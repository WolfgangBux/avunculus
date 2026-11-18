<?php

declare(strict_types=1);

/** Fachlicher Fehler bei Ortsänderungen (Namenskonflikt, Ort in Benutzung …) – wird als 409 gemeldet */
final class PlaceConflict extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $existingId = null)
    {
        parent::__construct($message);
    }
}

/**
 * Hierarchische Orte (Tabelle places) – eine gemeinsame Ortsbasis für alle Stammbäume.
 *
 * Ereignisse verweisen per place_id auf den kleinsten Ort. Jeder Ort kennt seinen Oberort;
 * full_name hält die ganze Ortsangabe vom Kleinsten zum Größten
 * („Ratzing, Waldkirchen, Freyung-Grafenau, Niederbayern, Bayern, Deutschland“) für Anzeige,
 * Suche und GEDCOM-Export und wird bei Umbenennen/Verschieben für alle Unterorte nachgeführt.
 *
 * Unter einem Oberort darf jeder Name nur einmal vorkommen (ohne Groß-/Kleinschreibung).
 */
final class PlaceIndex
{
    /** Ortsarten, die ein Gebiet bezeichnen – taugen nicht als Ersatzkoordinate für einen Unterort */
    public const AREA_TYPES = '/kreis$|^kreis|bezirk|land|staat|region|provinz|département|kanton|diözese|herzogtum|'
                            . 'föderation|kraj|okres|entität|kommunalverband/iu';

    /** Ist der Ort ein Gebiet (oder oberste Ebene) und damit kein brauchbarer Ersatz für einen Unterort? */
    public static function isArea(array $p): bool
    {
        return !$p['parent_id'] || (bool) preg_match(self::AREA_TYPES, (string) $p['place_type']);
    }

    /**
     * Koordinaten einzelner Orte: eigene oder die der nächsten Siedlung darüber (höchstens 3 Ebenen,
     * nie die eines Gebiets). Ergebnis: id → [lat, lon, ungefähr?]; Orte ohne Koordinaten fehlen.
     */
    public function coordinates(array $ids): array
    {
        $rows = [];   // geladene Orte samt Oberorten
        $todo = array_values(array_unique(array_map('intval', $ids)));
        for ($level = 0; $todo && $level < 4; $level++) {
            $in   = implode(',', array_fill(0, count($todo), '?'));
            $stmt = $this->db->prepare("SELECT id, parent_id, place_type, latitude, longitude FROM places WHERE id IN ($in)");
            $stmt->execute($todo);
            $todo = [];
            foreach ($stmt->fetchAll() as $r) {
                $rows[(int) $r['id']] = $r;
                if ($r['latitude'] === null && $r['parent_id'] && !isset($rows[(int) $r['parent_id']])) $todo[] = (int) $r['parent_id'];
            }
            $todo = array_values(array_unique($todo));
        }
        $out = [];
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            for ($i = $id, $hops = 0; $i && isset($rows[$i]) && $hops < 4; $i = (int) $rows[$i]['parent_id'], $hops++) {
                if ($hops > 0 && self::isArea($rows[$i])) break;
                if ($rows[$i]['latitude'] !== null && $rows[$i]['longitude'] !== null) {
                    $out[$id] = [round((float) $rows[$i]['latitude'], 5), round((float) $rows[$i]['longitude'], 5), $i !== $id];
                    break;
                }
            }
        }
        return $out;
    }

    /** @var array<int, array{id:int, parent_id:?int, name:string}> */
    private array $nodes = [];
    /** @var array<string, int> Schlüssel (Oberort + Name) → id */
    private array $index = [];
    private bool $loaded = false;

    public function __construct(private readonly PDO $db) {}

    /** Ortsangabe in ihre Teile zerlegen, vom Größten zum Kleinsten; leere Teile entfallen */
    public static function split(?string $place): array
    {
        $parts = array_map('trim', explode(',', (string) $place));
        return array_values(array_reverse(array_filter($parts, fn($p) => $p !== '')));
    }

    private static function key(?int $parentId, string $name): string
    {
        return ($parentId ?? 0) . "\0" . mb_strtolower($name);
    }

    // ── Auflösen ──────────────────────────────────────────

    /**
     * Ort zu einer Ortsangabe als Text („Ratzing, Waldkirchen, …“); fehlende Teile werden mit $create angelegt.
     * Leere Angabe → null.
     */
    public function resolve(?string $place, bool $create = true): ?int
    {
        $this->load();
        $parent = null;
        foreach (self::split($place) as $name) {
            $name = mb_substr($name, 0, 200);
            $id   = $this->index[self::key($parent, $name)] ?? null;
            if ($id === null) {
                if (!$create) return null;
                $id = $this->insert($parent, $name);
            }
            $parent = $id;
        }
        return $parent;
    }

    /** place_id aus einer Eingabe: gültige Orts-ID, sonst die Ortsangabe als Text (wird angelegt) */
    public function fromInput(mixed $placeId, ?string $text): ?int
    {
        if ($placeId !== null && $placeId !== '' && (int) $placeId > 0) {
            $this->load();
            if (isset($this->nodes[(int) $placeId])) return (int) $placeId;
        }
        return $this->resolve($text);
    }

    // ── Lesen ─────────────────────────────────────────────

    /** @return array<int, array{id:int, parent_id:?int, name:string}> */
    public function nodes(): array
    {
        $this->load();
        return $this->nodes;
    }

    /**
     * Anzahl Ereignisse eines Stammbaums je Ort: own = genau hier, total = einschließlich aller Unterorte
     * @return array{own: array<int, int>, total: array<int, int>}
     */
    public function counts(int $treeId): array
    {
        $this->load();
        $own = [];
        foreach ([
            'SELECT e.place_id, COUNT(*) FROM person_events e JOIN persons o ON o.id = e.person_id
             WHERE o.tree_id = ? AND e.place_id IS NOT NULL GROUP BY e.place_id',
            'SELECT e.place_id, COUNT(*) FROM family_events e JOIN families o ON o.id = e.family_id
             WHERE o.tree_id = ? AND e.place_id IS NOT NULL GROUP BY e.place_id',
        ] as $sql) {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$treeId]);
            foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$id, $n]) $own[(int) $id] = ($own[(int) $id] ?? 0) + (int) $n;
        }
        $total = [];
        foreach ($own as $id => $n) {
            for ($p = $id; $p !== null && isset($this->nodes[$p]); $p = $this->nodes[$p]['parent_id']) $total[$p] = ($total[$p] ?? 0) + $n;
        }
        return ['own' => $own, 'total' => $total];
    }

    /**
     * Orte, die in irgendeinem Stammbaum benutzt werden – selbst oder über einen Unterort
     * @return array<int, true>
     */
    public function usedAnywhere(): array
    {
        $this->load();
        $used = [];
        $rows = $this->db->query('SELECT place_id FROM person_events WHERE place_id IS NOT NULL
                                  UNION SELECT place_id FROM family_events WHERE place_id IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $id) {
            for ($p = (int) $id; $p !== null && isset($this->nodes[$p]) && !isset($used[$p]); $p = $this->nodes[$p]['parent_id']) $used[$p] = true;
        }
        return $used;
    }

    /** Orte vom Größten bis zu diesem */
    public function path(int $id): array
    {
        $this->load();
        $path = [];
        for ($n = $id; $n !== null && isset($this->nodes[$n]); $n = $this->nodes[$n]['parent_id']) {
            array_unshift($path, ['id' => $n, 'name' => $this->nodes[$n]['name']]);
        }
        return $path;
    }

    /** Dieser Ort und alle Unterorte */
    public function subtree(int $id): array
    {
        $this->load();
        $kids = [];
        foreach ($this->nodes as $n) $kids[$n['parent_id'] ?? 0][] = $n['id'];
        $out = [];
        for ($stack = [$id]; $stack; ) {
            $n = array_pop($stack);
            $out[] = $n;
            foreach ($kids[$n] ?? [] as $k) $stack[] = $k;
        }
        return $out;
    }

    // ── Ändern ────────────────────────────────────────────

    public function create(?int $parentId, string $name): int
    {
        $name = $this->checkName($name);
        $this->checkParent($parentId);
        $this->checkFree($parentId, $name, null);
        return $this->insert($parentId, $name);
    }

    /** Umbenennen und/oder unter einen anderen Oberort hängen */
    public function rename(int $id, string $name, ?int $parentId): void
    {
        $this->load();
        $name = $this->checkName($name);
        $this->checkParent($parentId);
        if ($parentId !== null && in_array($parentId, $this->subtree($id), true)) {
            throw new PlaceConflict('Ein Ort kann nicht unter sich selbst oder einen seiner Unterorte gehängt werden.');
        }
        $this->checkFree($parentId, $name, $id);

        $old = $this->nodes[$id];
        if ($old['name'] === $name && $old['parent_id'] === $parentId) return;
        $this->db->prepare('UPDATE places SET name = ?, parent_id = ? WHERE id = ?')->execute([$name, $parentId, $id]);
        unset($this->index[self::key($old['parent_id'], $old['name'])]);
        $this->nodes[$id] = ['id' => $id, 'parent_id' => $parentId, 'name' => $name];
        $this->index[self::key($parentId, $name)] = $id;
        $this->refreshFullNames($id);
    }

    /**
     * $sourceId in $targetId aufgehen lassen: Ereignisse und Unterorte wandern mit (gleichnamige Unterorte
     * werden ebenfalls zusammengeführt), fehlende Angaben werden ergänzt, der alte Name wird anderer Name.
     */
    public function merge(int $sourceId, int $targetId): void
    {
        $this->load();
        if ($sourceId === $targetId || in_array($targetId, $this->subtree($sourceId), true)) {
            throw new PlaceConflict('Ein Ort kann nicht mit sich selbst oder einem seiner Unterorte zusammengeführt werden.');
        }
        $this->mergeInto($sourceId, $targetId);
        $this->refreshFullNames($targetId);
    }

    /** Nur Orte ohne Unterorte, die in keinem Stammbaum benutzt werden */
    public function delete(int $id): void
    {
        $this->load();
        foreach ($this->nodes as $n) {
            if ($n['parent_id'] === $id) throw new PlaceConflict('Der Ort hat noch Unterorte.');
        }
        foreach (['person_events', 'family_events'] as $table) {
            $stmt = $this->db->prepare("SELECT 1 FROM $table WHERE place_id = ? LIMIT 1");
            $stmt->execute([$id]);
            if ($stmt->fetch()) throw new PlaceConflict('Der Ort wird noch von Ereignissen verwendet (ggf. in einem anderen Stammbaum).');
        }
        $this->db->prepare('DELETE FROM places WHERE id = ?')->execute([$id]);
        unset($this->index[self::key($this->nodes[$id]['parent_id'], $this->nodes[$id]['name'])], $this->nodes[$id]);
    }

    /**
     * Alle Orte löschen, die in keinem Stammbaum benutzt werden (auch nicht über Unterorte).
     * Liefert die Anzahl gelöschter Orte.
     */
    public function deleteUnused(): int
    {
        $used = $this->usedAnywhere();
        $ids  = array_values(array_filter(array_keys($this->nodes), fn($id) => !isset($used[$id])));
        // Kinder vor Eltern löschen (tiefste zuerst), damit kein Fremdschlüssel stört
        $depth = function (int $id): int {
            $d = 0;
            for ($p = $this->nodes[$id]['parent_id']; $p !== null && isset($this->nodes[$p]); $p = $this->nodes[$p]['parent_id']) $d++;
            return $d;
        };
        $depths = [];
        foreach ($ids as $id) $depths[$id] = $depth($id);
        usort($ids, fn($a, $b) => $depths[$b] <=> $depths[$a]);

        foreach (array_chunk($ids, 500) as $chunk) {
            $this->db->prepare('DELETE FROM places WHERE id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')')
                ->execute($chunk);
        }
        foreach ($ids as $id) unset($this->index[self::key($this->nodes[$id]['parent_id'], $this->nodes[$id]['name'])], $this->nodes[$id]);
        return count($ids);
    }

    /** full_name dieses Orts und aller Unterorte neu bilden */
    public function refreshFullNames(int $id): void
    {
        $this->load();
        $upd = $this->db->prepare('UPDATE places SET full_name = ? WHERE id = ?');
        foreach ($this->subtree($id) as $n) $upd->execute([$this->fullName($n), $n]);
    }

    public function fullName(int $id): string
    {
        return implode(', ', array_reverse(array_column($this->path($id), 'name')));
    }

    // -------------------------------------------------------------------------

    private function mergeInto(int $src, int $dst): void
    {
        foreach (['person_events', 'family_events'] as $table) {
            $this->db->prepare("UPDATE $table SET place_id = ? WHERE place_id = ?")->execute([$dst, $src]);
        }

        // Angaben ergänzen; Namen übernehmen, der alte Name wird anderer Name des Ziels
        $this->db->prepare('
            UPDATE places d JOIN places s ON s.id = ?
            SET d.place_type = COALESCE(d.place_type, s.place_type), d.latitude = COALESCE(d.latitude, s.latitude),
                d.longitude = COALESCE(d.longitude, s.longitude), d.gov_id = COALESCE(d.gov_id, s.gov_id),
                d.wikidata_id = COALESCE(d.wikidata_id, s.wikidata_id),
                d.notes = CASE WHEN s.notes IS NULL OR s.notes = d.notes THEN d.notes WHEN d.notes IS NULL THEN s.notes
                               ELSE CONCAT(d.notes, \'\n\n\', s.notes) END
            WHERE d.id = ?
        ')->execute([$src, $dst]);
        $stmt = $this->db->prepare('SELECT LOWER(name) FROM place_names WHERE place_id = ?');
        $stmt->execute([$dst]);
        $known = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
        $known[mb_strtolower($this->nodes[$dst]['name'])] = true;
        $stmt = $this->db->prepare('SELECT name, language, date_period FROM place_names WHERE place_id = ? ORDER BY sort_order, id');
        $stmt->execute([$src]);
        $names = [['name' => $this->nodes[$src]['name'], 'language' => null, 'date_period' => null], ...$stmt->fetchAll()];
        $ins   = $this->db->prepare('INSERT INTO place_names (place_id, name, language, date_period, sort_order) VALUES (?, ?, ?, ?, ?)');
        $order = count($known);
        foreach ($names as $n) {
            if (isset($known[mb_strtolower($n['name'])])) continue;
            $known[mb_strtolower($n['name'])] = true;
            $ins->execute([$dst, $n['name'], $n['language'], $n['date_period'], min($order++, 255)]);
        }

        // Unterorte umhängen bzw. mit gleichnamigen zusammenführen
        foreach ($this->nodes as $child) {
            if ($child['parent_id'] !== $src) continue;
            $same = $this->index[self::key($dst, $child['name'])] ?? null;
            if ($same !== null) {
                $this->mergeInto($child['id'], $same);
            } else {
                $this->db->prepare('UPDATE places SET parent_id = ? WHERE id = ?')->execute([$dst, $child['id']]);
                unset($this->index[self::key($src, $child['name'])]);
                $this->nodes[$child['id']]['parent_id'] = $dst;
                $this->index[self::key($dst, $child['name'])] = $child['id'];
            }
        }

        $this->db->prepare('DELETE FROM places WHERE id = ?')->execute([$src]);
        unset($this->index[self::key($this->nodes[$src]['parent_id'], $this->nodes[$src]['name'])], $this->nodes[$src]);
    }

    private function insert(?int $parentId, string $name): int
    {
        $full = $parentId !== null ? $name . ', ' . $this->fullName($parentId) : $name;
        $this->db->prepare('INSERT INTO places (parent_id, name, full_name) VALUES (?, ?, ?)')
            ->execute([$parentId, $name, mb_substr($full, 0, 1000)]);
        $id = (int) $this->db->lastInsertId();
        $this->nodes[$id] = ['id' => $id, 'parent_id' => $parentId, 'name' => $name];
        $this->index[self::key($parentId, $name)] = $id;
        return $id;
    }

    private function checkName(string $name): string
    {
        $name = trim($name);
        if ($name === '')               throw new PlaceConflict('Bitte einen Namen angeben.');
        if (str_contains($name, ','))   throw new PlaceConflict('Der Name darf kein Komma enthalten – Kommas trennen die Ebenen.');
        return mb_substr($name, 0, 200);
    }

    private function checkParent(?int $parentId): void
    {
        $this->load();
        if ($parentId !== null && !isset($this->nodes[$parentId])) throw new PlaceConflict('Übergeordneter Ort nicht gefunden.');
    }

    private function checkFree(?int $parentId, string $name, ?int $self): void
    {
        $other = $this->index[self::key($parentId, $name)] ?? null;
        if ($other !== null && $other !== $self) {
            throw new PlaceConflict("„{$name}“ gibt es dort schon. Zum Vereinen „Zusammenführen“ verwenden.", $other);
        }
    }

    private function load(): void
    {
        if ($this->loaded) return;
        foreach ($this->db->query('SELECT id, parent_id, name FROM places ORDER BY id')->fetchAll() as $r) {
            $id = (int) $r['id'];
            $parent = $r['parent_id'] !== null ? (int) $r['parent_id'] : null;
            $this->nodes[$id] = ['id' => $id, 'parent_id' => $parent, 'name' => $r['name']];
            $this->index[self::key($parent, $r['name'])] = $id;
        }
        $this->loaded = true;
    }
}
