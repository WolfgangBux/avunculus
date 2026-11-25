<?php

declare(strict_types=1);

class FamilyController extends TreeScopedController
{
    private const VALID_ROLES  = ['HUSB', 'WIFE', 'CHIL'];
    public  const EVENT_TYPES  = ['MARR', 'MARB', 'MARC', 'MARL', 'ENGA', 'DIV', 'DIVF', 'ANUL', 'CENS', 'RESI', 'EVEN'];

    // GET /api/persons/{id}/families
    public function personFamilies(int $personId): void
    {
        if (!$this->inTree('persons', $personId)) {
            $this->json(['error' => 'Person nicht gefunden'], 404); return;
        }

        $stmt = $this->db->prepare('
            SELECT f.id, f.notes, fm.role AS person_role
            FROM families f
            JOIN family_members fm ON fm.family_id = f.id AND fm.person_id = ?
            ORDER BY f.id
        ');
        $stmt->execute([$personId]);
        $families = $stmt->fetchAll();

        foreach ($families as &$family) {
            $family['id']      = (int) $family['id'];
            $family['members'] = $this->fetchMembers((int) $family['id']);
            $family['events']  = $this->fetchEvents([(int) $family['id']])[(int) $family['id']] ?? [];
            if ($this->isPrivate($family['id'])) $family['notes'] = null;
        }

        $this->json(array_values($families));
    }

    // GET /api/families/search?q=&limit=&offset=  –  Familienliste mit Partnern, Kinderzahl, Heirat/Scheidung
    public function search(): void
    {
        $limit  = max(1, min(200, (int) ($_GET['limit'] ?? 50)));
        $offset = max(0, (int) ($_GET['offset'] ?? 0));

        $where  = '';
        $params = [];
        foreach (array_slice(preg_split('/\s+/', trim((string) ($_GET['q'] ?? '')), -1, PREG_SPLIT_NO_EMPTY), 0, 6) as $tok) {
            $where   .= " AND EXISTS (SELECT 1 FROM family_members fm JOIN person_names n ON n.person_id = fm.person_id
                                      JOIN persons sp ON sp.id = fm.person_id
                                      WHERE fm.family_id = f.id" . Privacy::personSql('sp') . "
                                        AND CONCAT_WS(' ', n.given_name, n.surname_prefix, n.surname) LIKE ?)";
            $params[] = '%' . addcslashes($tok, '%_\\') . '%';
        }

        $count = $this->db->prepare("SELECT COUNT(*) FROM families f WHERE f.tree_id = ? $where");
        $count->execute([$this->treeId, ...$params]);
        $total = (int) $count->fetchColumn();

        // Sortiert nach dem Namen des ersten Partners
        $stmt = $this->db->prepare("
            SELECT f.id FROM families f
            WHERE f.tree_id = ? $where
            ORDER BY (
                SELECT CONCAT_WS(' ', n.surname, n.given_name)
                FROM family_members fm JOIN person_names n ON n.person_id = fm.person_id
                WHERE fm.family_id = f.id AND fm.role IN ('HUSB', 'WIFE')
                ORDER BY FIELD(fm.role, 'HUSB', 'WIFE'), n.sort_order, n.id LIMIT 1
            ) IS NULL, (
                SELECT CONCAT_WS(' ', n.surname, n.given_name)
                FROM family_members fm JOIN person_names n ON n.person_id = fm.person_id
                WHERE fm.family_id = f.id AND fm.role IN ('HUSB', 'WIFE')
                ORDER BY FIELD(fm.role, 'HUSB', 'WIFE'), n.sort_order, n.id LIMIT 1
            ), f.id
            LIMIT $limit OFFSET $offset
        ");
        $stmt->execute([$this->treeId, ...$params]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        $this->json(['total' => $total, ...$this->familiesWithPersons($ids)]);
    }

    /**
     * GET /api/pedigree?root=<id>&generations=<n>
     * Nur der Ausschnitt, den die Stammbaum-Ansicht zeigt: Vorfahren der Hauptperson über n Generationen,
     * ihre Partnerschaften und Kinder. Antwort wie /persons + /families, aber nur mit diesen Daten.
     */
    public function pedigree(): void
    {
        $pq    = new PersonQuery($this->db, $this->treeId);
        $root  = (int) ($_GET['root'] ?? 0);
        $gens  = max(1, min(8, (int) ($_GET['generations'] ?? 3)));
        if (!$root || !$this->inTree('persons', $root)) $root = $this->startPersonId() ?? $pq->firstId();
        if (!$root) { $this->json(['root' => null, 'total' => 0, 'persons' => [], 'families' => []]); return; }

        $familyIds = [];
        $personIds = [$root => true];
        $frontier  = [$root];
        $depth     = 0;          // tatsächlich vorhandene Vorfahrengenerationen
        $rootParents = null;     // Elternfamilie der Hauptperson (für die Geschwister)

        // Vorfahren Generation für Generation (je Kind die erste Elternfamilie)
        for ($g = 0; $g < $gens && $frontier; $g++) {
            $in   = implode(',', array_fill(0, count($frontier), '?'));
            $stmt = $this->db->prepare("
                SELECT fm.person_id AS child, MIN(fm.family_id) AS family_id
                FROM family_members fm JOIN families f ON f.id = fm.family_id
                WHERE f.tree_id = ? AND fm.role = 'CHIL' AND fm.person_id IN ($in)
                GROUP BY fm.person_id
            ");
            $stmt->execute([$this->treeId, ...$frontier]);
            $fams = array_map('intval', array_column($stmt->fetchAll(), 'family_id'));
            if (!$fams) break;
            if ($g === 0) $rootParents = $fams[0];
            foreach ($fams as $f) $familyIds[$f] = true;

            $in   = implode(',', array_fill(0, count($fams), '?'));
            $stmt = $this->db->prepare("SELECT DISTINCT person_id FROM family_members WHERE role <> 'CHIL' AND family_id IN ($in)");
            $stmt->execute($fams);
            $frontier = [];
            foreach (array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)) as $pid) {
                if (!isset($personIds[$pid])) { $personIds[$pid] = true; $frontier[] = $pid; }
            }
            if ($frontier) $depth = $g + 1;
        }

        // Geschwister der Hauptperson
        if ($rootParents) {
            $stmt = $this->db->prepare("SELECT person_id FROM family_members WHERE role = 'CHIL' AND family_id = ?");
            $stmt->execute([$rootParents]);
            foreach (array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)) as $pid) $personIds[$pid] = true;
        }

        // Nachkommen mit Partnern: mindestens die Kinder, sonst so viele Generationen,
        // wie die Vorfahren nicht ausschöpfen
        $descendants = max(1, $gens - $depth);
        $frontier    = [$root];
        $seen        = [$root => true];
        for ($g = 0; $g < $descendants && $frontier; $g++) {
            $in   = implode(',', array_fill(0, count($frontier), '?'));
            $stmt = $this->db->prepare("
                SELECT DISTINCT fm.family_id FROM family_members fm JOIN families f ON f.id = fm.family_id
                WHERE f.tree_id = ? AND fm.role <> 'CHIL' AND fm.person_id IN ($in)
            ");
            $stmt->execute([$this->treeId, ...$frontier]);
            $unions = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            if (!$unions) break;
            foreach ($unions as $f) $familyIds[$f] = true;

            $in   = implode(',', array_fill(0, count($unions), '?'));
            $stmt = $this->db->prepare("SELECT person_id, role FROM family_members WHERE family_id IN ($in)");
            $stmt->execute($unions);
            $frontier = [];
            foreach ($stmt->fetchAll() as $m) {
                $pid = (int) $m['person_id'];
                $personIds[$pid] = true;
                if ($m['role'] === 'CHIL' && !isset($seen[$pid])) { $seen[$pid] = true; $frontier[] = $pid; }
            }
        }

        $ids = array_keys($familyIds);
        sort($ids);
        $data = $this->familiesWithPersons($ids, array_keys($personIds));
        $this->json(['root' => $root, 'total' => $pq->count(), ...$data]);
    }

    /**
     * Familien (Mitglieder + Ereignisse) und die schlanken Personen dazu.
     * @param int[]      $familyIds
     * @param int[]|null $personIds Personen, die geladen werden sollen (Standard: alle Mitglieder)
     */
    private function familiesWithPersons(array $familyIds, ?array $personIds = null): array
    {
        if (!$familyIds) {
            return ['families' => [], 'persons' => (new PersonQuery($this->db, $this->treeId))->slim($personIds ?? [])];
        }
        $in   = implode(',', array_fill(0, count($familyIds), '?'));
        $stmt = $this->db->prepare("
            SELECT f.id AS family_id, f.notes, fm.person_id, fm.role, fm.pedigree
            FROM families f LEFT JOIN family_members fm ON fm.family_id = f.id
            WHERE f.tree_id = ? AND f.id IN ($in)
            ORDER BY FIELD(fm.role, 'HUSB', 'WIFE', 'CHIL'), fm.id
        ");
        $stmt->execute([$this->treeId, ...$familyIds]);

        $families = array_fill_keys($familyIds, null);
        $members  = [];
        foreach ($stmt->fetchAll() as $row) {
            $fid = (int) $row['family_id'];
            $families[$fid] ??= ['id' => $fid, 'notes' => $row['notes'], 'members' => [], 'events' => []];
            if ($row['person_id'] === null) continue;
            $families[$fid]['members'][] = ['person_id' => (int) $row['person_id'], 'role' => $row['role'], 'pedigree' => $row['pedigree']];
            $members[(int) $row['person_id']] = true;
        }
        foreach ($this->fetchEvents($familyIds) as $fid => $events) {
            if (isset($families[$fid])) $families[$fid]['events'] = $events;
        }
        foreach ($families as &$family) if ($family && $this->isPrivate($family['id'])) $family['notes'] = null;
        unset($family);

        return [
            'families' => array_values(array_filter($families)),
            'persons'  => (new PersonQuery($this->db, $this->treeId))->slim($personIds ?? array_keys($members)),
        ];
    }

    // GET /api/families/{id}
    public function show(int $id): void
    {
        $family            = $this->findFamilyOrFail($id);
        $family['members'] = $this->fetchMembers($id);
        $family['events']    = $this->fetchEvents([$id])[$id] ?? [];
        $family['citations'] = $this->fetchCitations('family_citations', 'family_id', [$id])[$id] ?? [];
        $family['media']     = $this->media()->fetch('family', [$id])[$id] ?? [];
        if ($this->isPrivate($id)) {
            $family = [...$family, 'notes' => null, 'citations' => [], 'media' => [], 'private' => true];
        }
        $this->json($family);
    }

    private function media(): MediaLinks
    {
        return new MediaLinks($this->db, $this->treeId);
    }

    // PUT /api/families/{id}  –  body: { notes, citations?, media? }  (ersetzen Quellenangaben bzw. Medien der Familie)
    public function update(int $id): void
    {
        $this->findFamilyOrFail($id);
        $data  = $this->body();
        $notes = trim((string) ($data['notes'] ?? ''));
        $this->db->beginTransaction();
        try {
            $this->db->prepare('UPDATE families SET notes = ? WHERE id = ?')
                ->execute([$notes !== '' ? $notes : null, $id]);
            if (array_key_exists('citations', $data)) {
                $this->saveCitations('family_citations', 'family_id', $id, (array) $data['citations']);
            }
            if (array_key_exists('media', $data)) $this->media()->save('family', $id, (array) $data['media']);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        $this->show($id);
    }

    // POST /api/families/{id}/events  –  body: { event_type, event_date, place_id | event_place, citations? }
    public function addEvent(int $familyId): void
    {
        $this->findFamilyOrFail($familyId);
        $params = $this->eventParams($this->body());
        if ($params === null) return;

        $next = $this->db->prepare('SELECT COALESCE(MAX(sort_order) + 1, 0) FROM family_events WHERE family_id = ?');
        $next->execute([$familyId]);

        $data = $this->body();
        $this->db->beginTransaction();
        try {
            $cols = array_keys($params);
            $this->db->prepare('
                INSERT INTO family_events (family_id, ' . implode(', ', $cols) . ', sort_order)
                VALUES (?, ' . implode(', ', array_fill(0, count($cols), '?')) . ', ?)
            ')->execute([$familyId, ...array_values($params), min(255, (int) $next->fetchColumn())]);
            $eventId = (int) $this->db->lastInsertId();
            $this->saveCitations('family_event_citations', 'event_id', $eventId, $data['citations'] ?? []);
            $this->media()->save('family_event', $eventId, (array) ($data['media'] ?? []));
            $this->saveAssociations($eventId, (array) ($data['associations'] ?? []));
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $this->json($this->fetchEvents([$familyId])[$familyId] ?? [], 201);
    }

    // PUT /api/families/{id}/events/{eventId}  –  mit citations: Quellenangaben ersetzen, ohne: unverändert lassen
    public function updateEvent(int $familyId, int $eventId): void
    {
        $this->findFamilyOrFail($familyId);
        $this->findEventOrFail($familyId, $eventId);
        $params = $this->eventParams($this->body());
        if ($params === null) return;

        $data = $this->body();
        $this->db->beginTransaction();
        try {
            $this->db->prepare('
                UPDATE family_events SET ' . implode(', ', array_map(fn($c) => "$c = ?", array_keys($params))) . ' WHERE id = ?
            ')->execute([...array_values($params), $eventId]);
            if (array_key_exists('citations', $data)) {
                $this->saveCitations('family_event_citations', 'event_id', $eventId, (array) $data['citations']);
            }
            if (array_key_exists('media', $data)) $this->media()->save('family_event', $eventId, (array) $data['media']);
            if (array_key_exists('associations', $data)) $this->saveAssociations($eventId, (array) $data['associations']);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $this->json($this->fetchEvents([$familyId])[$familyId] ?? []);
    }

    // DELETE /api/families/{id}/events/{eventId}
    public function deleteEvent(int $familyId, int $eventId): void
    {
        $this->findFamilyOrFail($familyId);
        $this->findEventOrFail($familyId, $eventId);
        $this->db->prepare('DELETE FROM family_events WHERE id = ?')->execute([$eventId]);
        http_response_code(204);
    }

    // POST /api/families  –  body: { members: [{person_id, role}, …], notes? }
    public function store(): void
    {
        $data    = $this->body();
        $members = $data['members'] ?? [];

        if (empty($members)) {
            $this->json(['error' => 'Mindestens ein Mitglied erforderlich'], 422); return;
        }

        foreach ($members as $m) {
            if (empty($m['person_id']) || !in_array($m['role'] ?? '', self::VALID_ROLES, true)
                || !$this->inTree('persons', (int) $m['person_id'])) {
                $this->json(['error' => 'Ungültige Mitgliedsdaten'], 422); return;
            }
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('INSERT INTO families (tree_id, notes) VALUES (:tree_id, :notes)');
            $stmt->execute(['tree_id' => $this->treeId, 'notes' => ($data['notes'] ?? null) ?: null]);
            $familyId = (int) $this->db->lastInsertId();

            foreach ($members as $m) {
                $this->insertMember($familyId, (int) $m['person_id'], $m['role'], $m['pedigree'] ?? null);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $family            = $this->findFamilyOrFail($familyId);
        $family['members'] = $this->fetchMembers($familyId);
        $this->json($family, 201);
    }

    // POST /api/families/{id}/members  –  body: { person_id, role, pedigree? }
    public function addMember(int $familyId): void
    {
        $this->findFamilyOrFail($familyId);
        $data     = $this->body();
        $personId = (int) ($data['person_id'] ?? 0);
        $role     = $data['role'] ?? '';

        if ($personId <= 0 || !in_array($role, self::VALID_ROLES, true) || !$this->inTree('persons', $personId)) {
            $this->json(['error' => 'Ungültige Mitgliedsdaten'], 422); return;
        }

        $this->insertMember($familyId, $personId, $role, $data['pedigree'] ?? null);

        $family            = $this->findFamilyOrFail($familyId);
        $family['members'] = $this->fetchMembers($familyId);
        $this->json($family);
    }

    // PUT /api/families/{id}/members/{personId}  –  body: { pedigree }  (Abstammungsart eines Kindes)
    public function updateMember(int $familyId, int $personId): void
    {
        $this->findFamilyOrFail($familyId);
        $this->db->prepare("UPDATE family_members SET pedigree = ? WHERE family_id = ? AND person_id = ? AND role = 'CHIL'")
            ->execute([GedcomTags::pedigree($this->body()['pedigree'] ?? null), $familyId, $personId]);

        $family            = $this->findFamilyOrFail($familyId);
        $family['members'] = $this->fetchMembers($familyId);
        $this->json($family);
    }

    // DELETE /api/families/{id}/members/{personId}[?keep=1]
    // Ohne keep wird eine Familie mit weniger als zwei Mitgliedern automatisch gelöscht
    // (Beziehungsdialog); der Familien-Editor behält sie mit keep=1.
    public function removeMember(int $familyId, int $personId): void
    {
        $this->findFamilyOrFail($familyId);

        $this->db->prepare('DELETE FROM family_members WHERE family_id = ? AND person_id = ?')
            ->execute([$familyId, $personId]);

        if (!empty($_GET['keep'])) { http_response_code(204); return; }

        // Auto-delete family when it has fewer than 2 members
        $cnt = $this->db->prepare('SELECT COUNT(*) FROM family_members WHERE family_id = ?');
        $cnt->execute([$familyId]);
        if ((int) $cnt->fetchColumn() < 2) {
            $this->db->prepare('DELETE FROM families WHERE id = ?')->execute([$familyId]);
        }

        http_response_code(204);
    }

    // DELETE /api/families/{id}
    public function destroy(int $id): void
    {
        $this->findFamilyOrFail($id);
        $this->db->prepare('DELETE FROM families WHERE id = ?')->execute([$id]);
        http_response_code(204);
    }

    // -------------------------------------------------------------------------

    private function fetchMembers(int $familyId): array
    {
        $stmt = $this->db->prepare('
            SELECT fm.person_id, fm.role, fm.pedigree, p.sex,
                   n.given_name, n.surname, n.surname_prefix,
                   (SELECT event_date FROM person_events
                    WHERE person_id = fm.person_id AND event_type = \'BIRT\'
                    ORDER BY sort_order ASC, id ASC LIMIT 1) AS birth_date,
                   (SELECT event_date FROM person_events
                    WHERE person_id = fm.person_id AND event_type = \'DEAT\'
                    ORDER BY sort_order ASC, id ASC LIMIT 1) AS death_date
            FROM family_members fm
            JOIN persons p ON p.id = fm.person_id
            LEFT JOIN person_names n ON n.id = (
                SELECT id FROM person_names WHERE person_id = fm.person_id
                ORDER BY sort_order ASC, id ASC LIMIT 1
            )
            WHERE fm.family_id = ?
            ORDER BY FIELD(fm.role, \'HUSB\', \'WIFE\', \'CHIL\'), fm.person_id
        ');
        $stmt->execute([$familyId]);

        return array_map(fn(array $row) => Privacy::maskRef((function (array $row): array {
            $sfx  = trim(($row['surname_prefix'] ?? '') . ' ' . ($row['surname'] ?? ''));
            $name = array_filter([$sfx ?: null, $row['given_name'] ?: null]);
            return [
                'person_id'    => (int) $row['person_id'],
                'role'         => $row['role'],
                'pedigree'     => $row['pedigree'],
                'sex'          => $row['sex'],
                'display_name' => $name ? implode(', ', $name) : '–',
                'birth_date'   => $row['birth_date'],
                'death_date'   => $row['death_date'],
            ];
        })($row), 'person_id', ['birth_date', 'death_date']), $stmt->fetchAll());
    }

    private function insertMember(int $familyId, int $personId, string $role, ?string $pedigree = null): void
    {
        $this->db->prepare(
            'INSERT IGNORE INTO family_members (family_id, person_id, role, pedigree) VALUES (?, ?, ?, ?)'
        )->execute([$familyId, $personId, $role, $role === 'CHIL' ? GedcomTags::pedigree($pedigree) : null]);
    }

    private function findFamilyOrFail(int $id): array
    {
        $family = $this->rowOrFail('SELECT * FROM families WHERE id = ? AND tree_id = ?', [$id, $this->treeId], 'Familie nicht gefunden');
        $family['id'] = (int) $family['id'];
        return $family;
    }

    /** @return array<int, list<array>> Ereignisse je Familien-ID, inkl. Anzahl der Quellenangaben */
    private function fetchEvents(array $familyIds): array
    {
        $familyIds = array_values(array_filter(array_map('intval', $familyIds)));
        if (!$familyIds) return [];
        $in   = implode(',', array_fill(0, count($familyIds), '?'));
        $stmt = $this->db->prepare("
            SELECT e.id, e.family_id, e.event_type, e.event_date, e.place_id, pl.full_name AS event_place, e.event_value, e.event_descriptor,
                   e.husb_age, e.wife_age, e.cause, e.agency, e.religion, e.event_time, e.notes, e.sort_order
            FROM family_events e
            LEFT JOIN places pl ON pl.id = e.place_id
            WHERE e.family_id IN ($in)" . Privacy::familySql('e.family_id') . "
            ORDER BY e.family_id, e.sort_order, e.id
        ");
        $stmt->execute($familyIds);
        $rows      = $stmt->fetchAll();
        $citations = $this->fetchCitations('family_event_citations', 'event_id', array_map(fn($r) => (int) $r['id'], $rows));
        $media     = $this->media()->fetch('family_event', array_map(fn($r) => (int) $r['id'], $rows));
        $assos     = $this->fetchAssociations(array_map(fn($r) => (int) $r['id'], $rows));

        $out = [];
        foreach ($rows as $r) {
            $list = $citations[(int) $r['id']] ?? [];
            $out[(int) $r['family_id']][] = [
                'id'               => (int) $r['id'],
                'event_type'       => $r['event_type'],
                'event_date'       => $r['event_date'],
                'place_id'         => $r['place_id'] !== null ? (int) $r['place_id'] : null,
                'event_place'      => $r['event_place'],
                'event_value'      => $r['event_value'],
                'event_descriptor' => $r['event_descriptor'],
                'husb_age'         => $r['husb_age'],
                'wife_age'         => $r['wife_age'],
                'cause'            => $r['cause'],
                'agency'           => $r['agency'],
                'religion'         => $r['religion'],
                'event_time'       => $r['event_time'],
                'notes'            => $r['notes'],
                'citation_count'   => count($list),
                'citations'        => $list,
                'media'            => $media[(int) $r['id']] ?? [],
                'associations'     => $assos[(int) $r['id']] ?? [],
            ];
        }
        return array_map(fn($list) => GedcomDate::sortEvents($list), $out);
    }

    /** Beteiligte Personen je Familienereignis (Trauzeugen …) */
    private function fetchAssociations(array $eventIds): array
    {
        if (!$eventIds) return [];
        $in   = implode(',', array_fill(0, count($eventIds), '?'));
        $stmt = $this->db->prepare("
            SELECT a.id, a.event_id, a.person_id, a.role, a.role_phrase, p.sex,
                   (SELECT CONCAT_WS(', ', NULLIF(TRIM(CONCAT_WS(' ', n.surname_prefix, n.surname)), ''), NULLIF(n.given_name, ''))
                    FROM person_names n WHERE n.person_id = a.person_id ORDER BY n.sort_order, n.id LIMIT 1) AS display_name
            FROM family_event_associations a JOIN persons p ON p.id = a.person_id
            WHERE a.event_id IN ($in)
            ORDER BY a.event_id, a.sort_order, a.id
        ");
        $stmt->execute($eventIds);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['event_id']][] = Privacy::maskRef([
                'id'           => (int) $r['id'],
                'person_id'    => (int) $r['person_id'],
                'role'         => $r['role'],
                'role_phrase'  => $r['role_phrase'],
                'sex'          => $r['sex'],
                'display_name' => $r['display_name'] ?: '–',
            ]);
        }
        return $out;
    }

    /** Beteiligte eines Familienereignisses ersetzen; nur Personen aus diesem Baum */
    private function saveAssociations(int $eventId, array $list): void
    {
        $sync  = new RowSync($this->db, 'family_event_associations', 'event_id', $eventId);
        $valid = $this->idsInTree('persons', array_column($list, 'person_id'));
        $order = 0;
        $seen  = [];
        foreach ($list as $a) {
            $pid = (int) ($a['person_id'] ?? 0);
            if (!isset($valid[$pid]) || isset($seen[$pid])) continue;
            $seen[$pid] = true;
            $role = in_array($a['role'] ?? '', GedcomTags::ASSO_ROLES, true) ? $a['role'] : null;
            $sync->save($a['id'] ?? null, [
                'person_id'   => $pid,
                'role'        => $role,
                'role_phrase' => $role === 'OTHER' && ($v = trim((string) ($a['role_phrase'] ?? ''))) !== '' ? mb_substr($v, 0, 200) : null,
                'sort_order'  => min($order++, 255),
            ]);
        }
        $sync->deleteRest();
    }

    /** Quellenangaben je Besitzer-ID (Ereignis oder Familie), mit Titel der Quelle */
    private function fetchCitations(string $table, string $ownerCol, array $ownerIds): array
    {
        $ownerIds = array_values(array_filter(array_map('intval', $ownerIds)));
        if (!$ownerIds) return [];
        $in   = implode(',', array_fill(0, count($ownerIds), '?'));
        $stmt = $this->db->prepare("
            SELECT c.id, c.$ownerCol AS owner_id, c.source_id, c.page, c.transcription, c.url, c.quality,
                   s.title AS source_title, s.abbreviation AS source_abbreviation
            FROM $table c LEFT JOIN sources s ON s.id = c.source_id
            WHERE c.$ownerCol IN ($in)
            ORDER BY c.$ownerCol, c.sort_order, c.id
        ");
        $stmt->execute($ownerIds);
        $out = [];
        foreach ($stmt->fetchAll() as $c) {
            $out[(int) $c['owner_id']][] = [
                'id'                  => (int) $c['id'],
                'media'               => [],
                'source_id'           => $c['source_id'] !== null ? (int) $c['source_id'] : null,
                'page'                => $c['page'],
                'transcription'       => $c['transcription'],
                'url'                 => $c['url'],
                'quality'             => $c['quality'] !== null ? (int) $c['quality'] : null,
                'source_title'        => $c['source_title'],
                'source_abbreviation' => $c['source_abbreviation'],
            ];
        }
        // Medien der Quellenangaben (SOUR.OBJE) in einer Abfrage
        $media = $this->media()->fetch(MediaLinks::CITATION_KINDS[$table], array_column(array_merge([], ...array_values($out)), 'id'));
        foreach ($out as &$list) {
            foreach ($list as &$c) $c['media'] = $media[$c['id']] ?? [];
            unset($c);
        }
        unset($list);
        return $out;
    }

    /** Quellenangaben abgleichen (Familienereignis oder Familie, siehe RowSync); nur Quellen aus diesem Baum, leere werden übergangen */
    private function saveCitations(string $table, string $ownerCol, int $ownerId, array $citations): void
    {
        // $table/$ownerCol sind fest im Code vorgegeben (family_event_citations/event_id, family_citations/family_id)
        $sync  = new RowSync($this->db, $table, $ownerCol, $ownerId);
        $valid = $this->idsInTree('sources', array_map(fn($c) => $c['source_id'] ?? 0, $citations));
        $links = $this->media();
        $order = 0;
        foreach ($citations as $c) {
            $str      = fn(string $k, int $max) => ($v = trim((string) ($c[$k] ?? ''))) !== '' ? mb_substr($v, 0, $max) : null;
            $sourceId = isset($valid[(int) ($c['source_id'] ?? 0)]) ? (int) $c['source_id'] : null;
            $page     = $str('page', 250);
            $text     = $str('transcription', 65000);
            $url      = $str('url', 500);
            if (!$sourceId && !$page && !$text && !$url && empty($c['media'])) continue;
            $cid = $sync->save($c['id'] ?? null, [
                'source_id' => $sourceId, 'page' => $page, 'transcription' => $text, 'url' => $url,
                'quality'   => PersonController::quality($c['quality'] ?? null), 'sort_order' => min($order++, 255),
            ]);
            $links->saveForCitation($table, $cid, $c);
        }
        $sync->deleteRest();
    }

    /** Validiert Ereignisdaten; bei Fehlern wird 422 gesendet und null geliefert. */
    private function eventParams(array $data): ?array
    {
        $type  = $data['event_type'] ?? '';
        $date  = GedcomDate::normalize((string) ($data['event_date'] ?? ''));
        $place = trim((string) ($data['event_place'] ?? ''));

        $errors = [];
        if (!in_array($type, self::EVENT_TYPES, true))      $errors['event_type'] = 'Ungültiger Ereignistyp';
        if ($date !== '' && !GedcomDate::isValid($date))    $errors['event_date'] = 'Ungültiges GEDCOM-Datum';
        if ($errors) { $this->json(['errors' => $errors], 422); return null; }

        $str = fn(string $k, int $max) => ($v = trim((string) ($data[$k] ?? ''))) !== '' ? mb_substr($v, 0, $max) : null;
        return [
            'event_type'       => $type,
            'event_date'       => $date !== '' ? mb_substr($date, 0, 100) : null,
            'place_id'         => (new PlaceIndex($this->db))->fromInput($data['place_id'] ?? null, $place !== '' ? $place : null),
            'event_value'      => $type === 'EVEN' ? $str('event_value', 65000) : null,
            'event_descriptor' => $type === 'EVEN' ? $str('event_descriptor', 120) : null,
            'husb_age'         => $str('husb_age', 30),
            'wife_age'         => $str('wife_age', 30),
            'cause'            => $str('cause', 250),
            'agency'           => $str('agency', 250),
            'religion'         => $str('religion', 120),
            'event_time'       => $date !== '' ? $str('event_time', 20) : null,
            'notes'            => $str('notes', 65000),
        ];
    }

    private function findEventOrFail(int $familyId, int $eventId): void
    {
        $this->rowOrFail('SELECT 1 FROM family_events WHERE id = ? AND family_id = ?', [$eventId, $familyId], 'Ereignis nicht gefunden');
    }

    /** Familie mit privatem Partner (nur für Besucher; Notizen, Ereignisse, Medien bleiben verborgen) */
    private function isPrivate(int $familyId): bool
    {
        if (!Privacy::active()) return false;
        $stmt = $this->db->prepare("SELECT person_id FROM family_members WHERE family_id = ? AND role IN ('HUSB', 'WIFE')");
        $stmt->execute([$familyId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $pid) if (Privacy::hides((int) $pid)) return true;
        return false;
    }

    /** Festgelegte Startperson des Baums, sofern es sie (noch) gibt */
    private function startPersonId(): ?int
    {
        $stmt = $this->db->prepare('SELECT p.id FROM trees t JOIN persons p ON p.id = t.start_person_id AND p.tree_id = t.id WHERE t.id = ?');
        $stmt->execute([$this->treeId]);
        return ($id = $stmt->fetchColumn()) !== false ? (int) $id : null;
    }

}
