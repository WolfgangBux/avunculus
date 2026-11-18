<?php

declare(strict_types=1);

/**
 * Orte: Hierarchie (Oberort → Unterorte), Koordinaten, GOV, Wikidata, andere Namen.
 * Die Ortsbasis ist für alle Stammbäume gemeinsam; Zählungen und Ereignislisten beziehen sich
 * auf den aktuellen Stammbaum. Ereignisse verweisen per place_id auf einen Ort (siehe PlaceIndex).
 */
class PlaceController extends TreeScopedController
{
    private const MAX_EVENTS = 300;

    // GET /api/places  –  alle Orte mit Anzahl der Ereignisse dieses Stammbaums
    public function index(): void
    {
        $index  = new PlaceIndex($this->db);
        $counts = $index->counts($this->treeId);
        $used   = $index->usedAnywhere();

        $stmt = $this->db->prepare('
            SELECT p.id, p.place_type, p.latitude IS NOT NULL AS has_coords, p.gov_id IS NOT NULL AS has_gov,
                   p.wikidata_id,
                   (SELECT GROUP_CONCAT(n.name ORDER BY n.sort_order SEPARATOR \' · \') FROM place_names n WHERE n.place_id = p.id) AS alt_names
            FROM places p
        ');
        $stmt->execute();
        $extra = array_column($stmt->fetchAll(), null, 'id');

        $out = [];
        foreach ($index->nodes() as $id => $n) {
            $x = $extra[$id] ?? [];
            $out[] = [
                'id'           => $id,
                'parent_id'    => $n['parent_id'],
                'name'         => $n['name'],
                'place_type'   => $x['place_type'] ?? null,
                'count'        => $counts['own'][$id] ?? 0,
                'total'        => $counts['total'][$id] ?? 0,
                'used'         => isset($used[$id]),   // in irgendeinem Stammbaum
                'has_coords'   => (bool) ($x['has_coords'] ?? false),
                'has_gov'      => (bool) ($x['has_gov'] ?? false),
                'has_wikidata' => ($x['wikidata_id'] ?? null) !== null,
                'wikidata_id'  => $x['wikidata_id'] ?? null,
                'alt_names'    => $x['alt_names'] ?? null,
            ];
        }
        $this->json($out);
    }

    // GET /api/places/search?q=…&limit=…  –  für die Ortsauswahl; jedes Wort muss in der Ortsangabe
    // oder einem anderen Namen vorkommen. Genaue Treffer und häufig benutzte Orte zuerst
    // (gezählt über alle Stammbäume).
    public function search(): void
    {
        $q     = trim((string) ($_GET['q'] ?? ''));
        $limit = max(1, min(50, (int) ($_GET['limit'] ?? 20)));
        $words = array_slice(array_filter(preg_split('/[\s,]+/u', $q)), 0, 6);

        $where  = ['1'];
        $params = [];
        foreach ($words as $w) {
            $like     = '%' . addcslashes($w, '%_\\') . '%';
            $where[]  = '(p.full_name LIKE ? OR EXISTS (SELECT 1 FROM place_names n WHERE n.place_id = p.id AND n.name LIKE ?))';
            $params[] = $like;
            $params[] = $like;
        }
        $first = $words ? addcslashes($words[0], '%_\\') . '%' : '%';
        $stmt  = $this->db->prepare('
            SELECT p.id, p.name, p.full_name, p.place_type,
                   (SELECT COUNT(*) FROM person_events e WHERE e.place_id = p.id)
                 + (SELECT COUNT(*) FROM family_events e WHERE e.place_id = p.id) AS uses
            FROM places p
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY p.full_name = ? DESC, p.name LIKE ? DESC, uses DESC, CHAR_LENGTH(p.full_name), p.full_name
            LIMIT ' . $limit);
        $stmt->execute([...$params, $q, $first]);
        $this->json(array_map(fn($r) => [...$r, 'id' => (int) $r['id'], 'uses' => (int) $r['uses']], $stmt->fetchAll()));
    }

    // GET /api/places/{id}  –  mit Pfad, Unterorten, anderen Namen und Ereignissen
    public function show(int $id, int $status = 200): void
    {
        $place = $this->findOrFail($id);
        $index = new PlaceIndex($this->db);

        $stmt = $this->db->prepare('SELECT id, name, language, date_period FROM place_names WHERE place_id = ? ORDER BY sort_order, id');
        $stmt->execute([$id]);
        $place['names'] = array_map(fn($n) => [...$n, 'id' => (int) $n['id']], $stmt->fetchAll());

        $stmt = $this->db->prepare('SELECT id, name, place_type FROM places WHERE parent_id = ? ORDER BY name');
        $stmt->execute([$id]);
        $place['children'] = array_map(fn($c) => [...$c, 'id' => (int) $c['id']], $stmt->fetchAll());

        $place['path'] = $index->path($id);
        $subtree       = $index->subtree($id);
        [$place['events'], $place['event_total']] = $this->events($subtree);
        $place['other_total'] = $this->countEvents($subtree) - $place['event_total'];   // in anderen Stammbäumen
        $this->json($place, $status);
    }

    // POST /api/places  –  body: { name, parent_id }
    public function store(): void
    {
        $data = $this->body();
        $this->guard(function () use ($data) {
            $id = (new PlaceIndex($this->db))->create(self::intOrNull($data['parent_id'] ?? null), (string) ($data['name'] ?? ''));
            $this->show($id, 201);
        });
    }

    // PUT /api/places/{id}  –  Name, Oberort, Angaben und andere Namen (die Liste ersetzt die bisherige)
    public function update(int $id): void
    {
        $current = $this->findOrFail($id);
        $data    = $this->body();
        $errors  = [];

        $str = function (string $field, int $max) use ($data): ?string {
            $v = trim((string) ($data[$field] ?? ''));
            return $v !== '' ? mb_substr($v, 0, $max) : null;
        };
        $coord = function (string $field, float $limit) use ($data, &$errors): ?string {
            $v = trim(str_replace(',', '.', (string) ($data[$field] ?? '')));
            if ($v === '') return null;
            if (!is_numeric($v) || abs((float) $v) > $limit) { $errors[$field] = 'invalid'; return null; }
            return number_format((float) $v, 6, '.', '');
        };

        $params = [
            'place_type'  => $str('place_type', 60),
            'latitude'    => $coord('latitude', 90),
            'longitude'   => $coord('longitude', 180),
            'gov_id'      => self::govId($str('gov_id', 300)),
            'wikidata_id' => self::wikidataId($str('wikidata_id', 300)),
            'notes'       => $str('notes', 65000),
        ];
        if (($params['latitude'] === null) !== ($params['longitude'] === null) && !$errors) {
            $errors[$params['latitude'] === null ? 'latitude' : 'longitude'] = 'missing';
        }
        if ($str('gov_id', 300) !== null && $params['gov_id'] === null)           $errors['gov_id'] = 'invalid';
        if ($str('wikidata_id', 300) !== null && $params['wikidata_id'] === null) $errors['wikidata_id'] = 'invalid';
        if ($errors) { $this->json(['errors' => $errors], 422); return; }

        $this->guard(function () use ($id, $current, $data, $params) {
            $this->db->beginTransaction();
            try {
                // Name und Oberort nur ändern, wenn mitgeschickt
                $name   = array_key_exists('name', $data) ? (string) $data['name'] : $current['name'];
                $parent = array_key_exists('parent_id', $data) ? self::intOrNull($data['parent_id']) : $current['parent_id'];
                (new PlaceIndex($this->db))->rename($id, $name, $parent);

                $this->db->prepare('UPDATE places SET ' . implode(', ', array_map(fn($c) => "$c = ?", array_keys($params))) . ' WHERE id = ?')
                    ->execute([...array_values($params), $id]);

                $this->db->prepare('DELETE FROM place_names WHERE place_id = ?')->execute([$id]);
                $ins   = $this->db->prepare('INSERT INTO place_names (place_id, name, language, date_period, sort_order) VALUES (?, ?, ?, ?, ?)');
                $order = 0;
                foreach ((array) ($data['names'] ?? []) as $n) {
                    $nm = trim((string) ($n['name'] ?? ''));
                    if ($nm === '') continue;
                    $lang   = trim((string) ($n['language'] ?? ''));
                    $period = trim((string) ($n['date_period'] ?? ''));
                    $ins->execute([$id, mb_substr($nm, 0, 200), $lang !== '' ? mb_substr($lang, 0, 40) : null,
                                   $period !== '' ? mb_substr($period, 0, 100) : null, min($order++, 255)]);
                }
                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }
            $this->show($id);
        });
    }

    // POST /api/places/{id}/merge  –  body: { target_id }; liefert den Zielort
    public function merge(int $id): void
    {
        $this->findOrFail($id);
        $target = (int) ($this->body()['target_id'] ?? 0);
        $this->findOrFail($target);
        $this->guard(function () use ($id, $target) {
            $this->db->beginTransaction();
            try {
                (new PlaceIndex($this->db))->merge($id, $target);
                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }
            $this->show($target);
        });
    }

    // POST /api/places/wikidata  –  Ergebnis des Wikidata-Abgleichs übernehmen
    // body: { items: [{ id, wikidata_id, latitude?, longitude?, gov_id?, place_type? }] }
    // Vorhandene Angaben bleiben unverändert; es werden nur leere Felder gefüllt.
    public function applyWikidata(): void
    {
        $items = (array) ($this->body()['items'] ?? []);
        $stmt  = $this->db->prepare('
            UPDATE places SET wikidata_id = COALESCE(wikidata_id, ?),
                   -- Breite/Länge gibt es nur gemeinsam; jede Spalte nur auf sich selbst prüfen,
                   -- weil MariaDB die Zuweisungen der Reihe nach mit den neuen Werten auswertet
                   latitude  = IF(latitude IS NULL, ?, latitude),
                   longitude = IF(longitude IS NULL, ?, longitude),
                   gov_id = COALESCE(gov_id, ?), place_type = COALESCE(place_type, ?)
            WHERE id = ?
        ');
        $updated = 0;
        $this->db->beginTransaction();
        foreach ($items as $it) {
            $qid = self::wikidataId((string) ($it['wikidata_id'] ?? ''));
            if (!$qid || (int) ($it['id'] ?? 0) <= 0) continue;
            $lat = is_numeric($it['latitude'] ?? null)  && abs((float) $it['latitude'])  <= 90  ? (float) $it['latitude']  : null;
            $lon = is_numeric($it['longitude'] ?? null) && abs((float) $it['longitude']) <= 180 ? (float) $it['longitude'] : null;
            if ($lat === null || $lon === null) $lat = $lon = null;
            $gov  = self::govId(($g = trim((string) ($it['gov_id'] ?? ''))) !== '' ? $g : null);
            $type = ($ty = trim((string) ($it['place_type'] ?? ''))) !== '' ? mb_substr($ty, 0, 60) : null;
            $stmt->execute([$qid, $lat, $lon, $gov, $type, (int) $it['id']]);
            $updated += $stmt->rowCount();
        }
        $this->db->commit();
        $this->json(['updated' => $updated]);
    }

    // DELETE /api/places/{id}  –  nur Orte ohne Unterorte, die in keinem Stammbaum benutzt werden
    public function destroy(int $id): void
    {
        $this->findOrFail($id);
        $this->guard(function () use ($id) {
            (new PlaceIndex($this->db))->delete($id);
            http_response_code(204);
        });
    }

    // POST /api/places/delete-unused  –  alle Orte löschen, die in keinem Stammbaum benutzt werden
    public function destroyUnused(): void
    {
        $this->db->beginTransaction();
        try {
            $n = (new PlaceIndex($this->db))->deleteUnused();
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        $this->json(['deleted' => $n]);
    }

    // -------------------------------------------------------------------------

    /** Namenskonflikte u. Ä. als 409 mit Meldung (und ggf. dem vorhandenen Ort) */
    private function guard(callable $fn): void
    {
        try {
            $fn();
        } catch (PlaceConflict $e) {
            $this->json(['error' => $e->getMessage(), 'existing_id' => $e->existingId], 409);
        }
    }

    private static function intOrNull(mixed $v): ?int
    {
        return $v === null || $v === '' || (int) $v <= 0 ? null : (int) $v;
    }

    /** GOV-Kennung aus Kennung oder Link (…/item/show/WALHE1JN68TR) */
    public static function govId(?string $v): ?string
    {
        if ($v === null) return null;
        if (preg_match('#gov\.genealogy\.net/.*?(?:item/show/|id=)([A-Za-z0-9_-]+)#i', $v, $m)) return $m[1];
        return preg_match('/^[A-Za-z0-9_-]{2,60}$/', $v) ? $v : null;
    }

    /** Wikidata-Kennung aus Q-Nummer oder Link (…/wiki/Q505961) */
    public static function wikidataId(?string $v): ?string
    {
        if ($v === null) return null;
        return preg_match('/(?:^|[\/=:])(Q\d{1,12})\b/i', $v, $m) ? strtoupper($m[1]) : null;
    }

    /** Ereignisse an diesen Orten – nur dieser Stammbaum oder ($treeId = null) alle */
    private function countEvents(array $placeIds, ?int $treeId = null): int
    {
        if (!$placeIds) return 0;
        $in = implode(',', array_map('intval', $placeIds));
        if ($treeId === null) {
            return (int) $this->db->query("
                SELECT (SELECT COUNT(*) FROM person_events WHERE place_id IN ($in))
                     + (SELECT COUNT(*) FROM family_events WHERE place_id IN ($in))
            ")->fetchColumn();
        }
        $stmt = $this->db->prepare("
            SELECT (SELECT COUNT(*) FROM person_events e JOIN persons o ON o.id = e.person_id WHERE e.place_id IN ($in) AND o.tree_id = ?)
                 + (SELECT COUNT(*) FROM family_events e JOIN families o ON o.id = e.family_id WHERE e.place_id IN ($in) AND o.tree_id = ?)
        ");
        $stmt->execute([$treeId, $treeId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Ereignisse dieses Stammbaums an diesen Orten (Ort und Unterorte), höchstens MAX_EVENTS
     * @param list<int> $placeIds
     * @return array{0: list<array>, 1: int}
     */
    private function events(array $placeIds): array
    {
        $total = $this->countEvents($placeIds, $this->treeId);
        if (!$total) return [[], 0];
        $in = implode(',', array_map('intval', $placeIds));

        $stmt = $this->db->prepare("
            SELECT x.*, pl.full_name AS event_place FROM (
                SELECT 'person' AS kind, e.id, e.person_id AS owner_id, e.event_type, e.event_date, e.place_id
                FROM person_events e JOIN persons o ON o.id = e.person_id WHERE e.place_id IN ($in) AND o.tree_id = ?" . Privacy::personSql('o') . "
                UNION ALL
                SELECT 'family', e.id, e.family_id, e.event_type, e.event_date, e.place_id
                FROM family_events e JOIN families o ON o.id = e.family_id WHERE e.place_id IN ($in) AND o.tree_id = ?" . Privacy::familySql('o.id') . "
            ) x JOIN places pl ON pl.id = x.place_id
            ORDER BY pl.full_name, x.owner_id LIMIT " . self::MAX_EVENTS);
        $stmt->execute([$this->treeId, $this->treeId]);
        $rows = $stmt->fetchAll();

        // Namen: Person bzw. Partner der Familie
        $famIds = array_values(array_unique(array_map(fn($r) => (int) $r['owner_id'], array_filter($rows, fn($r) => $r['kind'] === 'family'))));
        $partners = [];
        if ($famIds) {
            $q = $this->db->prepare('SELECT family_id, person_id FROM family_members WHERE role IN (\'HUSB\', \'WIFE\') AND family_id IN ('
                                  . implode(',', array_fill(0, count($famIds), '?')) . ') ORDER BY role');
            $q->execute($famIds);
            foreach ($q->fetchAll() as $m) $partners[(int) $m['family_id']][] = (int) $m['person_id'];
        }
        $personIds = array_map(fn($r) => (int) $r['owner_id'], array_filter($rows, fn($r) => $r['kind'] === 'person'));
        $slim = array_column((new PersonQuery($this->db, $this->treeId))->slim(array_merge($personIds, ...array_values($partners))), null, 'id');
        $pick = fn(int $pid) => isset($slim[$pid]) ? array_intersect_key($slim[$pid],
            array_flip(['id', 'sex', 'given_name', 'surname', 'surname_prefix', 'alt_surname'])) : null;

        $events = array_map(function ($r) use ($pick, $partners) {
            $owner = (int) $r['owner_id'];
            return [
                'kind'        => $r['kind'],
                'id'          => (int) $r['id'],
                'owner_id'    => $owner,
                'event_type'  => $r['event_type'],
                'event_date'  => $r['event_date'],
                'place_id'    => (int) $r['place_id'],
                'event_place' => $r['event_place'],
                'persons'     => array_values(array_filter($r['kind'] === 'person'
                    ? [$pick($owner)]
                    : array_map($pick, $partners[$owner] ?? []))),
            ];
        }, $rows);
        return [$events, $total];
    }

    private function findOrFail(int $id): array
    {
        $place = $this->rowOrFail('SELECT id, parent_id, name, full_name, place_type, latitude, longitude, gov_id, wikidata_id, notes
                                   FROM places WHERE id = ?', [$id], 'Ort nicht gefunden');
        return [...$place, 'id' => (int) $place['id'], 'parent_id' => $place['parent_id'] !== null ? (int) $place['parent_id'] : null];
    }
}
