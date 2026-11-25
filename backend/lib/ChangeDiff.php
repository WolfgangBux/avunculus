<?php

declare(strict_types=1);

/**
 * Was hat sich an einem Datensatz geändert? ChangeLog zieht vor und nach dem Speichern einen
 * Schnappschuss und speichert den Unterschied im Protokolleintrag (details.changes).
 *
 * Ein Schnappschuss ist eine Liste von Einträgen  Schlüssel => ['l' => Bezeichnung, 'p' => [Teil => Wert]]:
 *   - Schlüssel: stabil über das Speichern hinweg (Zeilen-IDs, seit RowSync abgleicht statt neu anlegt)
 *   - Bezeichnung: Kürzel, das das Frontend übersetzt (sex, notes, name, event:BIRT, fevent:MARR, title …)
 *   - Teile: bei Ereignissen date, place … und je Quellenangabe citation:<ID>; bei einfachen Feldern ein Teil ''
 * Werte sind lesbarer Text (Ortsname statt place_id, Quellentitel statt source_id).
 *
 * Unterschied: [{l, s?, o?, n?}] – nur n = hinzugefügt, nur o = entfernt, beides = geändert (s = Teil).
 */
final class ChangeDiff
{
    private const MAX_ENTRIES = 40;
    private const MAX_VALUE   = 300;

    /** Ereignisspalten → Teil-Kürzel (Reihenfolge = Anzeige) */
    private const EVENT_PARTS = [
        'event_date' => 'date', 'event_time' => 'time', 'place' => 'place', 'event_value' => 'value',
        'event_descriptor' => 'descriptor', 'age' => 'age', 'husb_age' => 'husb_age', 'wife_age' => 'wife_age',
        'cause' => 'cause', 'agency' => 'agency', 'religion' => 'religion', 'notes' => 'notes',
    ];

    public function __construct(private readonly PDO $db) {}

    /** Schnappschuss oder null, wenn es für diesen Typ keinen gibt bzw. den Datensatz nicht (mehr) gibt */
    public function snapshot(string $type, int $id): ?array
    {
        if ($id <= 0) return null;
        return match ($type) {
            'person'     => $this->person($id),
            'family'     => $this->family($id),
            'fevent'     => $this->familyEvent($id),
            'source'     => $this->row('SELECT s.title, s.abbreviation, s.author, s.publication, r.name AS repository,
                                               s.call_number, s.media_type, s.source_text, s.notes
                                        FROM sources s LEFT JOIN repositories r ON r.id = s.repository_id WHERE s.id = ?', $id,
                                       fn() => $this->mediaItems('source_media', 'source_id', $id)),
            'repository' => $this->row('SELECT name, address, postal_code, city, country, phone, email, website, notes
                                        FROM repositories WHERE id = ?', $id),
            'place'      => $this->row('SELECT p.name, pp.full_name AS parent, p.place_type, p.latitude, p.longitude,
                                               p.gov_id, p.wikidata_id, p.notes
                                        FROM places p LEFT JOIN places pp ON pp.id = p.parent_id WHERE p.id = ?', $id,
                                       fn() => $this->placeNames($id)),
            'media'      => $this->row('SELECT title, media_date, notes FROM media WHERE id = ?', $id),
            default      => null,
        };
    }

    /** @return list<array{l: string, s?: string, o?: string, n?: string}> */
    public static function diff(array $before, array $after): array
    {
        $out = [];
        foreach (array_keys($before + $after) as $key) {
            $a = $before[$key] ?? null;
            $b = $after[$key] ?? null;
            if (!$a) { $out[] = ['l' => $b['l'], 'n' => self::summary($b)]; continue; }
            if (!$b) { $out[] = ['l' => $a['l'], 'o' => self::summary($a)]; continue; }
            foreach (array_keys($a['p'] + $b['p']) as $part) {
                $o = $a['p'][$part] ?? '';
                $n = $b['p'][$part] ?? '';
                if ($o === $n) continue;
                $out[] = array_filter(['l' => $b['l'], 's' => explode(':', (string) $part)[0], 'o' => self::cut($o), 'n' => self::cut($n)],
                                      fn($v) => $v !== '');
            }
        }
        if (count($out) > self::MAX_ENTRIES) {
            $more = count($out) - self::MAX_ENTRIES;
            $out  = [...array_slice($out, 0, self::MAX_ENTRIES), ['l' => 'more', 'n' => (string) $more]];
        }
        return $out;
    }

    private static function summary(array $item): string
    {
        return self::cut(implode(' · ', array_filter($item['p'], fn($v) => $v !== '')));
    }

    private static function cut(string $v): string
    {
        $v = trim(preg_replace('/\s+/u', ' ', $v));
        return mb_strlen($v) > self::MAX_VALUE ? mb_substr($v, 0, self::MAX_VALUE - 1) . '…' : $v;
    }

    private static function str(mixed $v): string
    {
        return $v === null ? '' : trim((string) $v);
    }

    // ── Einfache Datensätze: jede Spalte ein Eintrag ─────────────────────────────

    private function row(string $sql, int $id, ?callable $extra = null): ?array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) return null;
        $items = [];
        foreach ($row as $col => $v) $items[$col] = ['l' => $col, 'p' => ['' => self::str($v)]];
        // leere Felder weglassen, damit „gefüllt“ als hinzugefügt und „geleert“ als entfernt erscheint
        $items = array_filter($items, fn($it) => $it['p'][''] !== '');
        return $extra ? $items + $extra() : $items;
    }

    private function placeNames(int $placeId): array
    {
        // Andere Namen werden beim Speichern neu angelegt → nach Inhalt vergleichen
        $stmt = $this->db->prepare('SELECT name, language, date_period FROM place_names WHERE place_id = ? ORDER BY sort_order, id');
        $stmt->execute([$placeId]);
        $items = [];
        foreach ($stmt->fetchAll() as $n) {
            $text = $n['name'] . ($n['language'] ? " ({$n['language']})" : '') . ($n['date_period'] ? ", {$n['date_period']}" : '');
            $items['altname:' . $text] = ['l' => 'altname', 'p' => ['' => $text]];
        }
        return $items;
    }

    // ── Person ───────────────────────────────────────────────────────────────────

    private function person(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT sex, restriction, notes FROM persons WHERE id = ?');
        $stmt->execute([$id]);
        $p = $stmt->fetch();
        if (!$p) return null;

        $items = [];
        foreach (['sex', 'restriction', 'notes'] as $f) {
            if (self::str($p[$f]) !== '') $items[$f] = ['l' => $f, 'p' => ['' => self::str($p[$f])]];
        }

        $stmt = $this->db->prepare('SELECT * FROM person_names WHERE person_id = ? ORDER BY sort_order, id');
        $stmt->execute([$id]);
        foreach ($stmt->fetchAll() as $n) {
            $given = trim(implode(' ', array_filter([$n['name_prefix'], $n['given_name'], $n['nickname'] ? "„{$n['nickname']}“" : null])));
            $sur   = trim(implode(' ', array_filter([$n['surname_prefix'], $n['surname'], $n['name_suffix']])));
            $text  = trim("$given $sur") . ($n['name_type'] !== 'BIRTH' ? " [{$n['name_type']}]" : '');
            $items['name:' . $n['id']] = ['l' => 'name', 'p' => ['' => $text]];
        }

        $items += $this->events('person_events', 'person_id', $id, 'event',
            'event_citations', 'event_associations', 'event_media');

        foreach ($this->citations('person_citations', 'person_id', [$id])[$id] ?? [] as $cid => $text) {
            $items['citation:' . $cid] = ['l' => 'citation', 'p' => ['' => $text]];
        }

        $stmt = $this->db->prepare('SELECT a.id, a.associate_id, a.role, a.role_phrase, a.notes FROM person_associations a
                                    WHERE a.person_id = ? ORDER BY a.sort_order, a.id');
        $stmt->execute([$id]);
        foreach ($stmt->fetchAll() as $a) {
            $text = $this->personName((int) $a['associate_id']) . self::roleText($a['role'], $a['role_phrase'])
                  . ($a['notes'] ? ' · ' . $a['notes'] : '');
            $items['assoc:' . $a['id']] = ['l' => 'association', 'p' => ['' => $text]];
        }

        // Medien-Verknüpfungen werden ersetzt → nach Medium vergleichen
        $stmt = $this->db->prepare('SELECT m.id, COALESCE(NULLIF(m.title, \'\'), m.original_name) AS title, pm.is_primary
                                    FROM person_media pm JOIN media m ON m.id = pm.media_id WHERE pm.person_id = ? ORDER BY pm.sort_order, pm.id');
        $stmt->execute([$id]);
        foreach ($stmt->fetchAll() as $m) {
            $items['media:' . $m['id']] = ['l' => 'media', 'p' => ['' => $m['title'] . ($m['is_primary'] ? ' ★' : '')]];
        }
        return $items;
    }

    // ── Familie ──────────────────────────────────────────────────────────────────
    // Mitglieder und Ereignisse haben eigene Endpunkte; „Speichern“ der Familie umfasst Notiz, Quellen, Medien.

    private function family(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT notes FROM families WHERE id = ?');
        $stmt->execute([$id]);
        $f = $stmt->fetch();
        if (!$f) return null;
        $items = self::str($f['notes']) !== '' ? ['notes' => ['l' => 'notes', 'p' => ['' => self::str($f['notes'])]]] : [];
        foreach ($this->citations('family_citations', 'family_id', [$id])[$id] ?? [] as $cid => $text) {
            $items['citation:' . $cid] = ['l' => 'citation', 'p' => ['' => $text]];
        }
        return $items + $this->mediaItems('family_media', 'family_id', $id);
    }

    private function familyEvent(int $eventId): ?array
    {
        $familyId = $this->db->prepare('SELECT family_id FROM family_events WHERE id = ?');
        $familyId->execute([$eventId]);
        $fid = $familyId->fetchColumn();
        if ($fid === false) return null;
        $all = $this->events('family_events', 'family_id', (int) $fid, 'fevent',
            'family_event_citations', 'family_event_associations', 'family_event_media');
        return isset($all['event:' . $eventId]) ? ['event:' . $eventId => $all['event:' . $eventId]] : null;
    }

    // ── Gemeinsame Bausteine ─────────────────────────────────────────────────────

    /** Ereignisse eines Besitzers mit Quellen, Beteiligten und Medien als Teile */
    private function events(string $table, string $ownerCol, int $ownerId, string $prefix,
                            string $citTable, string $assoTable, string $mediaTable): array
    {
        $stmt = $this->db->prepare("SELECT e.*, pl.full_name AS place FROM $table e LEFT JOIN places pl ON pl.id = e.place_id
                                    WHERE e.$ownerCol = ? ORDER BY e.sort_order, e.id");
        $stmt->execute([$ownerId]);
        $events = $stmt->fetchAll();
        if (!$events) return [];
        $ids = array_map(fn($e) => (int) $e['id'], $events);
        $in  = implode(',', $ids);   // Zahlen aus der Datenbank

        $cits = $this->citations($citTable, 'event_id', $ids);

        $assos = [];
        foreach ($this->db->query("SELECT id, event_id, person_id, role, role_phrase FROM $assoTable WHERE event_id IN ($in) ORDER BY sort_order, id") as $a) {
            $assos[(int) $a['event_id']][(int) $a['id']] = $this->personName((int) $a['person_id']) . self::roleText($a['role'], $a['role_phrase']);
        }
        $media = [];
        foreach ($this->db->query("SELECT x.event_id, m.id, COALESCE(NULLIF(m.title, ''), m.original_name) AS title
                                   FROM $mediaTable x JOIN media m ON m.id = x.media_id WHERE x.event_id IN ($in) ORDER BY x.sort_order, x.id") as $m) {
            $media[(int) $m['event_id']][(int) $m['id']] = (string) $m['title'];
        }

        $items = [];
        foreach ($events as $e) {
            $eid   = (int) $e['id'];
            $parts = [];
            foreach (self::EVENT_PARTS as $col => $part) {
                if (array_key_exists($col, $e)) $parts[$part] = self::str($e[$col]);
            }
            // je Quellenangabe/Beteiligtem/Medium ein Teil – sonst ginge eine geänderte Seitenzahl im langen Text unter
            foreach ($cits[$eid] ?? []  as $k => $v) $parts["citation:$k"]    = $v;
            foreach ($assos[$eid] ?? [] as $k => $v) $parts["association:$k"] = $v;
            foreach ($media[$eid] ?? [] as $k => $v) $parts["media:$k"]       = $v;
            $items['event:' . $eid] = ['l' => "$prefix:{$e['event_type']}", 'p' => $parts];
        }
        return $items;
    }

    /** @return array<int, array<int, string>> Besitzer-ID → [Quellenangabe-ID → Text] */
    private function citations(string $table, string $ownerCol, array $ownerIds): array
    {
        if (!$ownerIds) return [];
        $in   = implode(',', array_map('intval', $ownerIds));
        $out  = [];
        $mediaTable = MediaLinks::KINDS[MediaLinks::CITATION_KINDS[$table]][0];
        foreach ($this->db->query("SELECT c.id, c.$ownerCol AS owner, c.page, c.transcription, c.url, c.quality,
                                          COALESCE(NULLIF(s.abbreviation, ''), s.title) AS source,
                                          (SELECT GROUP_CONCAT(CONCAT(COALESCE(NULLIF(m.title, ''), m.original_name),
                                                         IF(x.crop_width IS NULL, '', CONCAT(' ⬚ ', ROUND(x.crop_left, 3), ',', ROUND(x.crop_top, 3), ' ',
                                                                                              ROUND(x.crop_width, 3), '×', ROUND(x.crop_height, 3))))
                                                  ORDER BY x.sort_order, x.id SEPARATOR '; ')
                                           FROM $mediaTable x JOIN media m ON m.id = x.media_id WHERE x.citation_id = c.id) AS media
                                   FROM $table c LEFT JOIN sources s ON s.id = c.source_id
                                   WHERE c.$ownerCol IN ($in) ORDER BY c.sort_order, c.id") as $c) {
            $text = implode(', ', array_filter([
                $c['source'], $c['page'],
                $c['quality'] !== null ? str_repeat('★', (int) $c['quality']) . str_repeat('☆', 3 - (int) $c['quality']) : null,
                $c['url'], $c['transcription'] ? '„' . self::cut((string) $c['transcription']) . '“' : null,
                $c['media'] !== null ? '[' . $c['media'] . ']' : null,
            ], fn($v) => $v !== null && $v !== ''));
            $out[(int) $c['owner']][(int) $c['id']] = $text;
        }
        return $out;
    }

    private function mediaItems(string $table, string $ownerCol, int $ownerId): array
    {
        $stmt = $this->db->prepare("SELECT m.id, COALESCE(NULLIF(m.title, ''), m.original_name) AS title
                                    FROM $table x JOIN media m ON m.id = x.media_id WHERE x.$ownerCol = ? ORDER BY x.sort_order, x.id");
        $stmt->execute([$ownerId]);
        $items = [];
        foreach ($stmt->fetchAll() as $m) $items['media:' . $m['id']] = ['l' => 'media', 'p' => ['' => (string) $m['title']]];
        return $items;
    }

    private static function roleText(?string $role, ?string $phrase): string
    {
        $r = implode(', ', array_filter([$role, $phrase]));
        return $r !== '' ? " ($r)" : '';
    }

    private array $names = [];

    private function personName(int $id): string
    {
        if (!isset($this->names[$id])) {
            $stmt = $this->db->prepare("SELECT TRIM(CONCAT_WS(' ', NULLIF(given_name, ''), NULLIF(surname_prefix, ''), NULLIF(surname, '')))
                                        FROM person_names WHERE person_id = ? ORDER BY sort_order, id LIMIT 1");
            $stmt->execute([$id]);
            $this->names[$id] = (string) ($stmt->fetchColumn() ?: "#$id");
        }
        return $this->names[$id];
    }
}
