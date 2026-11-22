<?php

declare(strict_types=1);

/**
 * Änderungsprotokoll (Tabelle change_log). index.php umschließt jede schreibende Anfrage mit
 * ChangeLog::run(): Nach erfolgreicher Antwort (< 400) entsteht ein Eintrag mit Benutzer, Baum,
 * Datensatz und Aktion (= Name der Controller-Aktion aus routes.php). Neue Endpunkte werden so
 * ohne weiteres Zutun protokolliert; der Datensatztyp ergibt sich aus dem ersten Pfadteil.
 *
 * Die Bezeichnung wird vor der Aktion gelesen (gelöschte Datensätze bleiben lesbar) und danach
 * aktualisiert, falls es den Datensatz noch gibt.
 */
final class ChangeLog
{
    /** erster Pfadteil → Datensatztyp */
    private const TYPES = [
        'persons' => 'person', 'families' => 'family', 'sources' => 'source', 'repositories' => 'repository',
        'places'  => 'place',  'media'    => 'media',  'trees'   => 'tree',
    ];
    /** nicht protokolliert: Baum löschen (das Protokoll des Baums verschwindet mit ihm) */
    private const SKIP = ['tree.destroy'];
    /** Aktionen, deren Anfragekörper Angaben fürs Protokoll enthält */
    private const BODY_ACTIONS = ['addMember', 'addEvent', 'updateEvent', 'merge', 'commitImport', 'relativesTree', 'region'];

    /** Speichern, bei dem festgehalten wird, welche Felder sich geändert haben (ChangeDiff) */
    private const DIFF_ACTIONS = ['person.update', 'family.update', 'family.updateEvent', 'source.update',
                                  'repository.update', 'place.update', 'media.update'];

    private ?string $before;
    private array   $details = [];
    /** [Schnappschuss-Typ, ID, Zustand vorher] */
    private ?array  $snapshot = null;

    private function __construct(
        private readonly PDO    $db,
        private readonly string $type,
        private readonly string $action,
        private readonly array  $args,
        private readonly ?int   $treeId,
        private readonly array  $body,
    ) {
        $this->before = $this->entityId() ? $this->label($this->type, $this->entityId()) : null;
        // Angaben, die nach der Aktion nicht mehr zu lesen sind
        if ($action === 'removeMember' || $action === 'updateMember') $this->detailPerson((int) ($args[1] ?? 0));
        if ($action === 'deleteEvent') $this->details['event_type'] = $this->value('SELECT event_type FROM family_events WHERE id = ?', $args[1] ?? 0);
        if (in_array("$type.$action", self::DIFF_ACTIONS, true)) {
            [$snapType, $snapId] = $action === 'updateEvent' ? ['fevent', (int) ($args[1] ?? 0)] : [$type, $this->entityId()];
            $this->snapshot = [$snapType, $snapId, (new ChangeDiff($db))->snapshot($snapType, $snapId)];
        }
    }

    /** Protokoll für diese Anfrage vorbereiten; null bei lesenden oder nicht protokollierten Anfragen */
    public static function begin(PDO $db, string $method, string $path, array $route, ?int $treeId): ?self
    {
        if ($method === 'GET') return null;
        $type = self::TYPES[explode('/', trim($path, '/'))[0]] ?? null;
        if (!$type || in_array("$type.{$route['action']}", self::SKIP, true)) return null;
        // Körper nur, wo er gebraucht wird (Uploads kommen stückweise als Rohdaten)
        $body = in_array($route['action'], self::BODY_ACTIONS, true)
            ? (array) json_decode((string) file_get_contents('php://input'), true) : [];
        return new self($db, $type, $route['action'], $route['args'], $treeId, $body);
    }

    /** Aktion ausführen; bei Erfolg protokollieren. Ein Fehler im Protokoll macht die Anfrage nicht ungültig. */
    public static function run(?self $log, callable $action): void
    {
        if (!$log) { $action(); return; }
        ob_start();
        try {
            $action();
        } finally {
            $out = (string) ob_get_contents();
            ob_end_flush();
        }
        if (http_response_code() >= 400) return;
        try {
            $log->finish($out);
        } catch (\Throwable $e) {
            error_log('[Avunculus] Änderungsprotokoll: ' . $e->getMessage());
        }
    }

    private function finish(string $output): void
    {
        $res = json_decode($output, true);
        $res = is_array($res) ? $res : [];

        // Datensatz: aus dem Pfad, bei neu angelegten aus der Antwort
        $id = $this->entityId();
        if ($this->type === 'tree' && in_array($this->action, ['import', 'relativesTree'], true)) $id = (int) ($res['tree']['id'] ?? 0);
        if ($this->type === 'tree' && $this->action === 'commitImport') $id = (int) ($this->body['target'] ?? 0);
        if (!$id && $this->action !== 'destroyUnused' && $this->action !== 'applyWikidata') $id = (int) ($res['id'] ?? 0);

        // Medien-Upload: nur das letzte Stück legt etwas an; dieselbe Datei gab es schon
        if ($this->action === 'upload' && (!$id || !empty($res['duplicate']))) return;
        // GEDCOM: Upload-Stücke und die Analyse vor dem Zusammenführen ändern noch nichts
        if (in_array($this->action, ['import', 'commitImport'], true) && !isset($res['stats'])) return;

        $treeId = $this->type === 'tree' ? $id : $this->treeId;
        if (!$treeId) return;

        // Welche Felder haben sich geändert? Ohne Änderung kein Eintrag (nur „Speichern“ gedrückt).
        if ($this->snapshot) {
            [$snapType, $snapId, $old] = $this->snapshot;
            $changes = ChangeDiff::diff($old ?? [], (new ChangeDiff($this->db))->snapshot($snapType, $snapId) ?? []);
            if (!$changes) return;
            $this->details['changes'] = $changes;
        }

        match ($this->action) {
            'addMember'                       => $this->detailPerson((int) ($this->body['person_id'] ?? 0), $this->body['role'] ?? null),
            'region'                          => $this->detailRegion(),
            'addEvent', 'updateEvent'         => $this->details['event_type'] = $this->body['event_type'] ?? null,
            'merge'                           => $this->details += ['target_id' => (int) ($this->body['target_id'] ?? 0),
                                                                    'target'    => $this->label('place', (int) ($this->body['target_id'] ?? 0))],
            'applyWikidata', 'destroyUnused'  => $this->details += array_intersect_key($res, ['updated' => 1, 'deleted' => 1]),
            'import', 'commitImport'          => $this->details['stats'] = $res['stats'] ?? null,
            'relativesTree'                   => $this->details += ['stats' => $res['stats'] ?? null, 'from_tree' => $this->before,
                                                                    'person' => $this->label('person', (int) ($this->body['person_id'] ?? 0))],
            default                           => null,
        };
        // relativesTree: „before“ ist der Ausgangsbaum, die Bezeichnung die des neuen Baums
        $label = $id ? ($this->label($this->type, $id) ?? ($this->action === 'relativesTree' ? null : $this->before)) : null;
        $details = array_filter($this->details, fn($v) => $v !== null && $v !== '');

        $user = Auth::user();
        $this->db->prepare('
            INSERT INTO change_log (tree_id, user_id, user_name, entity_type, entity_id, entity_label, action, details)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ')->execute([
            $treeId, $user['id'] ?? null, mb_substr($user['display_name'] ?: ($user['username'] ?? '?'), 0, 120),
            $this->type, $id ?: null, $label !== null ? mb_substr($label, 0, 255) : null, $this->action,
            $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    private function entityId(): int
    {
        return (int) ($this->args[0] ?? 0);
    }

    /** Person auf einem Bild markiert (removed: Markierung entfernt) */
    private function detailRegion(): void
    {
        $this->detailPerson((int) ($this->body['person_id'] ?? 0));
        if (empty($this->body['crop'])) $this->details['removed'] = true;
    }

    private function detailPerson(int $personId, ?string $role = null): void
    {
        if (!$personId) return;
        $this->details += ['person_id' => $personId, 'person' => $this->label('person', $personId), 'role' => $role];
    }

    private function value(string $sql, mixed ...$params): ?string
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $v = $stmt->fetchColumn();
        return $v === false || $v === null || $v === '' ? null : (string) $v;
    }

    /** Lesbare Bezeichnung eines Datensatzes; null, wenn es ihn nicht (mehr) gibt */
    private function label(string $type, int $id): ?string
    {
        if ($id <= 0) return null;
        return match ($type) {
            'person'     => $this->personName($id),
            'family'     => $this->familyName($id),
            'source'     => $this->value('SELECT title FROM sources WHERE id = ?', $id),
            'repository' => $this->value('SELECT name FROM repositories WHERE id = ?', $id),
            'place'      => $this->value('SELECT full_name FROM places WHERE id = ?', $id),
            'media'      => $this->value("SELECT COALESCE(NULLIF(title, ''), original_name) FROM media WHERE id = ?", $id),
            'tree'       => $this->value('SELECT name FROM trees WHERE id = ?', $id),
            default      => null,
        };
    }

    private function personName(int $id): ?string
    {
        $name = $this->value("
            SELECT TRIM(CONCAT_WS(' ', NULLIF(given_name, ''), NULLIF(surname_prefix, ''), NULLIF(surname, '')))
            FROM person_names WHERE person_id = ? ORDER BY sort_order, id LIMIT 1", $id);
        return $name ?? ($this->value('SELECT id FROM persons WHERE id = ?', $id) ? "#$id" : null);
    }

    private function familyName(int $id): ?string
    {
        $stmt = $this->db->prepare("SELECT person_id FROM family_members WHERE family_id = ? AND role IN ('HUSB', 'WIFE') ORDER BY FIELD(role, 'HUSB', 'WIFE')");
        $stmt->execute([$id]);
        $names = array_filter(array_map(fn($pid) => $this->personName((int) $pid), $stmt->fetchAll(PDO::FETCH_COLUMN)));
        if ($names) return implode(' ⚭ ', $names);
        return $this->value('SELECT id FROM families WHERE id = ?', $id) ? "#$id" : null;
    }
}
