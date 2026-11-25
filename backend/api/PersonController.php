<?php

declare(strict_types=1);

class PersonController extends TreeScopedController
{
    private const VALID_SEX        = ['M', 'F', 'X', 'U'];
    private const VALID_NAME_TYPES = ['BIRTH', 'MARRIED', 'AKA', 'IMMIGRANT', 'FORMAL', 'PROFESSIONAL', 'RELIGIOUS'];
    private const VALID_ROLES      = GedcomTags::ASSO_ROLES;

    // GET /api/persons/search?q=&limit=&offset=&exclude=1,2&around=<Person>
    public function search(): void
    {
        $limit   = max(1, min(200, (int) ($_GET['limit'] ?? 50)));
        $offset  = max(0, (int) ($_GET['offset'] ?? 0));
        $exclude = array_filter(explode(',', (string) ($_GET['exclude'] ?? '')), 'is_numeric');
        $around  = (int) ($_GET['around'] ?? 0) ?: null;   // Seite, auf der diese Person steht (Antwort: offset)
        $surname = isset($_GET['surname']) ? trim((string) $_GET['surname']) : null;   // '' = ohne Nachnamen
        $this->json((new PersonQuery($this->db, $this->treeId))->search((string) ($_GET['q'] ?? ''), $limit, $offset, $exclude, $around, $surname));
    }

    public function show(int $id): void
    {
        $this->json(Privacy::hides($id) ? Privacy::maskFull($this->findPersonOrFail($id)) : $this->full($id));
    }

    /** Person mit Namen, Ereignissen, Quellenangaben, Medien und Rückverweisen */
    private function full(int $id): array
    {
        $person             = $this->findPersonOrFail($id);
        $person['names']    = $this->fetchNames($id);
        $person['events']   = $this->fetchEvents($id);
        $person['citations'] = $this->fetchCitations($id);
        $person['associations']  = $this->fetchAssociations($id);
        $person['associated_in'] = $this->fetchAssociatedIn($id);
        $person['media']    = $this->media()->fetch('person', [$id])[$id] ?? [];
        return $person;
    }

    private function media(): MediaLinks
    {
        return new MediaLinks($this->db, $this->treeId);
    }

    public function store(): void
    {
        $data   = $this->body();
        $errors = $this->validate($data);
        if ($errors) { $this->json(['errors' => $errors], 422); return; }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('INSERT INTO persons (tree_id, sex, restriction, notes) VALUES (:tree_id, :sex, :restriction, :notes)');
            $stmt->execute([...$this->personParams($data), 'tree_id' => $this->treeId]);
            $id = (int) $this->db->lastInsertId();
            $this->saveNames($id, $data['names'] ?? []);
            $this->saveEvents($id, $data['events'] ?? []);
            if (array_key_exists('citations', $data)) $this->saveCitations($id, (array) $data['citations']);
            if (array_key_exists('media', $data))     $this->media()->save('person', $id, (array) $data['media']);
            if (array_key_exists('associations', $data)) $this->saveAssociations($id, (array) $data['associations']);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $this->json($this->full($id), 201);
    }

    public function update(int $id): void
    {
        $this->findPersonOrFail($id);
        $data   = $this->body();
        $errors = $this->validate($data);
        if ($errors) { $this->json(['errors' => $errors], 422); return; }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('UPDATE persons SET sex = :sex, restriction = :restriction, notes = :notes WHERE id = :id');
            $stmt->execute([...$this->personParams($data), 'id' => $id]);
            $this->saveNames($id, $data['names'] ?? []);
            $this->saveEvents($id, $data['events'] ?? []);
            if (array_key_exists('citations', $data)) $this->saveCitations($id, (array) $data['citations']);
            if (array_key_exists('media', $data))     $this->media()->save('person', $id, (array) $data['media']);
            if (array_key_exists('associations', $data)) $this->saveAssociations($id, (array) $data['associations']);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $this->json($this->full($id));
    }

    public function destroy(int $id): void
    {
        $this->findPersonOrFail($id);
        $this->db->prepare('DELETE FROM persons WHERE id = ?')->execute([$id]);
        http_response_code(204);
    }

    // -------------------------------------------------------------------------

    private function findPersonOrFail(int $id): array
    {
        return $this->rowOrFail('SELECT * FROM persons WHERE id = ? AND tree_id = ?', [$id, $this->treeId], 'Person nicht gefunden');
    }

    private function fetchNames(int $id): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM person_names WHERE person_id = ? ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([$id]);
        return $stmt->fetchAll();
    }

    /** Namen abgleichen (Reihenfolge = Rangfolge, der erste ist der Hauptname) */
    private function saveNames(int $personId, array $names): void
    {
        $sync = new RowSync($this->db, 'person_names', 'person_id', $personId);
        foreach (array_values($names) as $i => $name) {
            $str = fn(string $k): ?string => ($v = trim($name[$k] ?? '')) !== '' ? $v : null;
            $sync->save($name['id'] ?? null, [
                'name_type'      => in_array($name['name_type'] ?? '', self::VALID_NAME_TYPES, true) ? $name['name_type'] : 'BIRTH',
                'name_prefix'    => $str('name_prefix'),
                'given_name'     => $str('given_name'),
                'nickname'       => $str('nickname'),
                'surname_prefix' => $str('surname_prefix'),
                'surname'        => $str('surname'),
                'name_suffix'    => $str('name_suffix'),
                'sort_order'     => $i,
            ]);
        }
        $sync->deleteRest();
    }

    /** Name „Nachname, Vorname“ einer Person (SQL-Ausdruck, erster Name) */
    private static function nameSql(string $personIdExpr): string
    {
        return "(SELECT CONCAT_WS(', ', NULLIF(TRIM(CONCAT_WS(' ', n.surname_prefix, n.surname)), ''), NULLIF(n.given_name, ''))
                 FROM person_names n WHERE n.person_id = $personIdExpr ORDER BY n.sort_order, n.id LIMIT 1)";
    }

    /** Verknüpfte Personen direkt an der Person (INDI.ASSO), z. B. Taufpate, Weggefährte */
    private function fetchAssociations(int $personId): array
    {
        $stmt = $this->db->prepare('
            SELECT a.id, a.associate_id, a.role, a.role_phrase, a.notes, p.sex, ' . self::nameSql('a.associate_id') . ' AS display_name
            FROM person_associations a JOIN persons p ON p.id = a.associate_id
            WHERE a.person_id = ?
            ORDER BY a.sort_order, a.id
        ');
        $stmt->execute([$personId]);
        return array_map(fn(array $r) => Privacy::maskRef([
            'id'           => (int) $r['id'],
            'person_id'    => (int) $r['associate_id'],
            'role'         => $r['role'],
            'role_phrase'  => $r['role_phrase'],
            'notes'        => $r['notes'],
            'sex'          => $r['sex'],
            'display_name' => $r['display_name'] ?: '–',
        ]), $stmt->fetchAll());
    }

    /** Verknüpfte Personen der Person ersetzen; nur Personen aus diesem Baum, nicht sie selbst */
    private function saveAssociations(int $personId, array $list): void
    {
        $sync  = new RowSync($this->db, 'person_associations', 'person_id', $personId);
        $valid = $this->idsInTree('persons', array_column($list, 'person_id'));
        $order = 0;
        foreach ($list as $a) {
            $pid = (int) ($a['person_id'] ?? 0);
            if (!isset($valid[$pid]) || $pid === $personId) continue;
            $role = in_array($a['role'] ?? '', self::VALID_ROLES, true) ? $a['role'] : null;
            $sync->save($a['id'] ?? null, [
                'associate_id' => $pid,
                'role'         => $role,
                'role_phrase'  => $role === 'OTHER' && ($v = trim((string) ($a['role_phrase'] ?? ''))) !== '' ? mb_substr($v, 0, 200) : null,
                'notes'        => ($v = trim((string) ($a['notes'] ?? ''))) !== '' ? $v : null,
                'sort_order'   => min($order++, 255),
            ]);
        }
        $sync->deleteRest();
    }

    /**
     * Rückrichtung der Verknüpfungen: wo diese Person bei anderen eingetragen ist –
     * an deren Ereignissen (Taufpate bei der Taufe von X), direkt an der Person (INDI.ASSO)
     * oder an Familienereignissen (Trauzeuge bei der Heirat von X & Y).
     * scope: event | person | family; bei family zeigt person_id nicht auf eine Person, sondern family_id.
     */
    private function fetchAssociatedIn(int $personId): array
    {
        $stmt = $this->db->prepare("
            SELECT 'event' AS scope, a.role, a.role_phrase, e.event_type, e.event_date, e.person_id AS target_id, p.sex,
                   " . self::nameSql('e.person_id') . " AS display_name
            FROM event_associations a
            JOIN person_events e ON e.id = a.event_id
            JOIN persons p       ON p.id = e.person_id
            WHERE a.person_id = ? AND p.tree_id = ?
            UNION ALL
            SELECT 'person', a.role, a.role_phrase, NULL, NULL, a.person_id, p.sex, " . self::nameSql('a.person_id') . "
            FROM person_associations a JOIN persons p ON p.id = a.person_id
            WHERE a.associate_id = ? AND p.tree_id = ?
            UNION ALL
            SELECT 'family', a.role, a.role_phrase, e.event_type, e.event_date, e.family_id, NULL,
                   (SELECT GROUP_CONCAT(CONCAT_WS(' ', n.given_name, n.surname_prefix, n.surname)
                                        ORDER BY FIELD(fm.role, 'HUSB', 'WIFE') SEPARATOR ' & ')
                    FROM family_members fm JOIN person_names n ON n.id = (
                        SELECT id FROM person_names WHERE person_id = fm.person_id ORDER BY sort_order, id LIMIT 1)
                    WHERE fm.family_id = e.family_id AND fm.role IN ('HUSB', 'WIFE'))
            FROM family_event_associations a
            JOIN family_events e ON e.id = a.event_id
            JOIN families f      ON f.id = e.family_id
            WHERE a.person_id = ? AND f.tree_id = ?" . Privacy::familySql('e.family_id') . "
            ORDER BY event_date IS NULL, event_date
        ");
        $stmt->execute([$personId, $this->treeId, $personId, $this->treeId, $personId, $this->treeId]);
        return array_map(function (array $r): array {
            $row = [
                'scope'        => $r['scope'],
                'person_id'    => $r['scope'] === 'family' ? null : (int) $r['target_id'],
                'family_id'    => $r['scope'] === 'family' ? (int) $r['target_id'] : null,
                'display_name' => $r['display_name'] ?: '–',
                'sex'          => $r['sex'],
                'event_type'   => $r['event_type'],
                'event_date'   => $r['event_date'],
                'role'         => $r['role'],
                'role_phrase'  => $r['role_phrase'],
            ];
            return $r['scope'] === 'family' ? $row : Privacy::maskRef($row, 'person_id', ['event_type', 'event_date']);
        }, $stmt->fetchAll());
    }

    /** Verlässlichkeit einer Quellenangabe (GEDCOM QUAY 0–3), sonst null */
    public static function quality(mixed $v): ?int
    {
        return is_numeric($v) && (int) $v >= 0 && (int) $v <= 3 ? (int) $v : null;
    }

    /** Quellenangaben direkt an der Person (INDI.SOUR), mit Titel der Quelle */
    private function fetchCitations(int $personId): array
    {
        $stmt = $this->db->prepare('
            SELECT c.id, c.source_id, c.page, c.transcription, c.url, c.quality,
                   s.title AS source_title, s.abbreviation AS source_abbreviation
            FROM person_citations c LEFT JOIN sources s ON s.id = c.source_id
            WHERE c.person_id = ?
            ORDER BY c.sort_order, c.id
        ');
        $stmt->execute([$personId]);
        return $this->media()->attachToCitations('person_citations', array_map(fn($c) => [
            ...$c,
            'id'        => (int) $c['id'],
            'source_id' => $c['source_id'] !== null ? (int) $c['source_id'] : null,
            'quality'   => $c['quality'] !== null ? (int) $c['quality'] : null,
        ], $stmt->fetchAll()));
    }

    /** Quellenangaben der Person ersetzen; nur Quellen aus diesem Baum, leere werden übergangen */
    private function saveCitations(int $personId, array $citations): void
    {
        $sync  = new RowSync($this->db, 'person_citations', 'person_id', $personId);
        $valid = $this->idsInTree('sources', array_column($citations, 'source_id'));
        $links = $this->media();
        $order = 0;
        foreach ($citations as $c) {
            if (!is_array($c)) continue;
            $str      = fn(string $k, int $max) => ($v = trim((string) ($c[$k] ?? ''))) !== '' ? mb_substr($v, 0, $max) : null;
            $sourceId = isset($valid[(int) ($c['source_id'] ?? 0)]) ? (int) $c['source_id'] : null;
            $page     = $str('page', 250);
            $text     = $str('transcription', 65000);
            $url      = $str('url', 500);
            if (!$sourceId && !$page && !$text && !$url && empty($c['media'])) continue;
            $cid = $sync->save($c['id'] ?? null, [
                'source_id' => $sourceId, 'page' => $page, 'transcription' => $text, 'url' => $url,
                'quality'   => self::quality($c['quality'] ?? null), 'sort_order' => min($order++, 255),
            ]);
            $links->saveForCitation('person_citations', $cid, $c);
        }
        $sync->deleteRest();
    }

    private function fetchEvents(int $personId): array
    {
        $stmt = $this->db->prepare('
            SELECT e.id AS eid, e.event_type, e.event_date, e.place_id, pl.full_name AS event_place, e.sort_order AS esort,
                   e.event_value, e.event_descriptor, e.age, e.cause, e.agency, e.religion, e.event_time, e.notes AS event_notes,
                   c.id AS cid, c.source_id, c.page, c.transcription, c.url, c.quality, c.sort_order AS csort,
                   s.title AS source_title, s.abbreviation AS source_abbreviation
            FROM person_events e
            LEFT JOIN places pl ON pl.id = e.place_id
            LEFT JOIN event_citations c ON c.event_id = e.id
            LEFT JOIN sources s ON s.id = c.source_id
            WHERE e.person_id = ?
            ORDER BY e.sort_order ASC, e.id ASC, c.sort_order ASC, c.id ASC
        ');
        $stmt->execute([$personId]);

        $events = [];
        $index  = [];
        foreach ($stmt->fetchAll() as $row) {
            $eid = (int) $row['eid'];
            if (!isset($index[$eid])) {
                $index[$eid] = count($events);
                $events[] = [
                    'id'           => $eid,
                    'event_type'   => $row['event_type'],
                    'event_date'   => $row['event_date'],
                    'place_id'     => $row['place_id'] !== null ? (int) $row['place_id'] : null,
                    'event_place'  => $row['event_place'],
                    'event_value'      => $row['event_value'],
                    'event_descriptor' => $row['event_descriptor'],
                    'age'              => $row['age'],
                    'cause'            => $row['cause'],
                    'agency'           => $row['agency'],
                    'religion'         => $row['religion'],
                    'event_time'       => $row['event_time'],
                    'notes'            => $row['event_notes'],
                    'sort_order'   => (int) $row['esort'],
                    'citations'    => [],
                    'associations' => [],
                    'media'        => [],
                ];
            }
            if ($row['cid'] !== null) {
                $events[$index[$eid]]['citations'][] = [
                    'id'                  => (int) $row['cid'],
                    'source_id'           => $row['source_id'],
                    'page'                => $row['page'],
                    'transcription'       => $row['transcription'],
                    'url'                 => $row['url'],
                    'quality'             => $row['quality'] !== null ? (int) $row['quality'] : null,
                    'sort_order'          => (int) $row['csort'],
                    'source_title'        => $row['source_title'],
                    'source_abbreviation' => $row['source_abbreviation'],
                ];
            }
        }

        // Associations fetched separately to avoid cross-product with citations
        $aStmt = $this->db->prepare('
            SELECT a.id AS aid, a.event_id, a.person_id AS assoc_id,
                   a.role, a.role_phrase, a.sort_order, ap.sex,
                   n.given_name, n.surname, n.surname_prefix
            FROM event_associations a
            JOIN person_events e ON e.id = a.event_id
            JOIN persons ap ON ap.id = a.person_id
            LEFT JOIN person_names n ON n.id = (
                SELECT id FROM person_names WHERE person_id = a.person_id
                ORDER BY sort_order ASC, id ASC LIMIT 1
            )
            WHERE e.person_id = ?
            ORDER BY a.event_id ASC, a.sort_order ASC, a.id ASC
        ');
        $aStmt->execute([$personId]);
        foreach ($aStmt->fetchAll() as $row) {
            $eid = (int) $row['event_id'];
            if (!isset($index[$eid])) continue;
            $surname = trim(($row['surname_prefix'] ?? '') . ' ' . ($row['surname'] ?? ''));
            $parts   = array_filter([$surname ?: null, $row['given_name'] ?: null]);
            $events[$index[$eid]]['associations'][] = Privacy::maskRef([
                'id'           => (int) $row['aid'],
                'person_id'    => (int) $row['assoc_id'],
                'role'         => $row['role'],
                'role_phrase'  => $row['role_phrase'],
                'sex'          => $row['sex'],
                'display_name' => $parts ? implode(', ', $parts) : '–',
            ]);
        }

        foreach ($this->media()->fetch('event', array_keys($index)) as $eid => $list) {
            $events[$index[$eid]]['media'] = $list;
        }
        // Medien der Quellenangaben in einer Abfrage für alle Ereignisse
        $citMedia = $this->media()->fetch('event_citation', array_merge([], ...array_map(fn($e) => array_column($e['citations'], 'id'), $events)));
        foreach ($events as &$e) {
            foreach ($e['citations'] as &$c) $c['media'] = $citMedia[$c['id']] ?? [];
            unset($c);
        }
        unset($e);
        return GedcomDate::sortEvents($events);
    }

    /**
     * Ereignisse abgleichen (siehe RowSync): bekannte IDs bleiben, damit Verweise darauf stabil sind.
     * Quellenangaben und Beteiligte je Ereignis ebenso; Medien-Verknüpfungen werden ersetzt
     * (sie haben außer Medium und Reihenfolge nichts Eigenes). Gelöschte Ereignisse nehmen ihre
     * Quellenangaben, Beteiligten und Medien-Verknüpfungen per Fremdschlüssel mit.
     */
    private function saveEvents(int $personId, array $events): void
    {
        // Nur Quellen und Personen aus dem eigenen Baum dürfen verknüpft werden
        $validSources = $this->idsInTree('sources', array_merge([], ...array_map(
            fn($e) => array_column($e['citations'] ?? [], 'source_id'), $events)));
        $validPersons = $this->idsInTree('persons', array_merge([], ...array_map(
            fn($e) => array_column($e['associations'] ?? [], 'person_id'), $events)));

        $sync       = new RowSync($this->db, 'person_events', 'person_id', $personId);
        $places     = new PlaceIndex($this->db);
        $mediaLinks = $this->media();
        foreach (array_values($events) as $i => $event) {
            $str = fn(string $k): ?string => ($v = trim($event[$k] ?? '')) !== '' ? $v : null;
            $eventId = $sync->save($event['id'] ?? null, [
                'event_type'       => in_array($event['event_type'] ?? '', GedcomTags::personTypes(), true) ? $event['event_type'] : 'EVEN',
                'event_date'       => $str('event_date'),
                'place_id'         => $places->fromInput($event['place_id'] ?? null, $str('event_place')),
                'event_value'      => $str('event_value'),
                'event_descriptor' => self::cut($str('event_descriptor'), 120),
                'age'              => self::cut($str('age'), 30),
                'cause'            => self::cut($str('cause'), 250),
                'agency'           => self::cut($str('agency'), 250),
                'religion'         => self::cut($str('religion'), 120),
                'event_time'       => $str('event_date') !== null ? self::cut($str('event_time'), 20) : null,
                'notes'            => $str('notes'),
                'sort_order'       => min($i, 255),
            ]);

            $mediaLinks->save('event', $eventId, (array) ($event['media'] ?? []));

            $citations = new RowSync($this->db, 'event_citations', 'event_id', $eventId);
            foreach (array_values($event['citations'] ?? []) as $j => $c) {
                $cStr     = fn(string $k): ?string => ($v = trim($c[$k] ?? '')) !== '' ? $v : null;
                $sourceId = !empty($c['source_id']) && isset($validSources[(int) $c['source_id']]) ? (int) $c['source_id'] : null;
                if (!$sourceId && !$cStr('page') && !$cStr('transcription') && !$cStr('url') && empty($c['media'])) continue;
                $cid = $citations->save($c['id'] ?? null, [
                    'source_id'     => $sourceId,
                    'page'          => $cStr('page'),
                    'transcription' => $cStr('transcription'),
                    'url'           => $cStr('url'),
                    'quality'       => self::quality($c['quality'] ?? null),
                    'sort_order'    => min($j, 255),
                ]);
                $mediaLinks->saveForCitation('event_citations', $cid, $c);
            }
            $citations->deleteRest();

            $assos = new RowSync($this->db, 'event_associations', 'event_id', $eventId);
            foreach (array_values($event['associations'] ?? []) as $j => $a) {
                $pid  = !empty($a['person_id']) ? (int) $a['person_id'] : 0;
                if ($pid <= 0 || !isset($validPersons[$pid])) continue;
                $role = in_array($a['role'] ?? '', self::VALID_ROLES, true) ? $a['role'] : null;
                $assos->save($a['id'] ?? null, [
                    'person_id'   => $pid,
                    'role'        => $role,
                    'role_phrase' => ($role === 'OTHER' && ($v = trim($a['role_phrase'] ?? '')) !== '') ? $v : null,
                    'sort_order'  => min($j, 255),
                ]);
            }
            $assos->deleteRest();
        }
        $sync->deleteRest();
    }

    private function personParams(array $data): array
    {
        $str = fn(string $key): ?string =>
            ($v = trim($data[$key] ?? '')) !== '' ? $v : null;
        return [
            'sex'         => in_array($data['sex'] ?? '', self::VALID_SEX, true) ? $data['sex'] : 'U',
            'restriction' => GedcomTags::restriction($data['restriction'] ?? null),
            'notes'       => $str('notes'),
        ];
    }

    private static function cut(?string $s, int $max): ?string
    {
        return $s === null ? null : mb_substr($s, 0, $max);
    }

    private function validate(array $data): array
    {
        $errors = [];

        if (!in_array($data['sex'] ?? '', self::VALID_SEX, true)) {
            $errors['sex'] = 'Ungültiges Geschlecht (erwartet: M, F, X oder U)';
        }

        $names = $data['names'] ?? [];
        if (empty($names)) {
            $errors['names'] = 'Mindestens ein Name ist erforderlich';
        } else {
            $primary = $names[0];
            if (empty(trim($primary['given_name'] ?? '')) && empty(trim($primary['surname'] ?? ''))) {
                $errors['names_0'] = 'Hauptname: Vorname oder Nachname ist erforderlich';
            }
        }

        $birthYear = null;
        $deathYear = null;
        foreach (($data['events'] ?? []) as $i => $event) {
            $val = trim($event['event_date'] ?? '');
            if ($val !== '' && !GedcomDate::isValid($val)) {
                $errors["event_{$i}_date"] = 'Ereignis ' . ($i + 1) . ': Ungültiges GEDCOM-Datum';
            }
            $type = $event['event_type'] ?? '';
            if ($type === 'BIRT' && $val !== '') $birthYear = $birthYear ?? GedcomDate::extractYear($val);
            if ($type === 'DEAT' && $val !== '') $deathYear = $deathYear ?? GedcomDate::extractYear($val);
        }

        if ($birthYear && $deathYear && $birthYear > $deathYear) {
            $errors['event_years'] = 'Sterbejahr muss nach dem Geburtsjahr liegen';
        }

        return $errors;
    }

    /**
     * GET /api/persons/{id}/places  –  Orte der Person für die Karte: eigene Ereignisse und Ereignisse
     * der Familien, in denen sie Partner ist (Heirat …). Je Ort die Ereignisse; Orte ohne Koordinaten getrennt.
     */
    public function places(int $id): void
    {
        $this->findPersonOrFail($id);
        if (Privacy::hides($id)) { $this->json(['places' => [], 'without_coords' => []]); return; }

        $stmt = $this->db->prepare("
            SELECT 'person' AS scope, e.event_type, e.event_date, e.event_descriptor, e.place_id, NULL AS family_id, e.sort_order, e.id
            FROM person_events e
            WHERE e.person_id = ? AND e.place_id IS NOT NULL
            UNION ALL
            SELECT 'family', e.event_type, e.event_date, e.event_descriptor, e.place_id, e.family_id, e.sort_order, e.id
            FROM family_events e
            JOIN family_members fm ON fm.family_id = e.family_id AND fm.person_id = ? AND fm.role <> 'CHIL'
            WHERE e.place_id IS NOT NULL" . Privacy::familySql('e.family_id') . "
            ORDER BY sort_order, id
        ");
        $stmt->execute([$id, $id]);
        $events = array_map(fn($e) => $e + ['generation' => 0, 'person_id' => null], $stmt->fetchAll());

        // Auf Wunsch Vorfahren: Generation 1 = Eltern, 2 = Großeltern – je ihre eigenen Ereignisse
        // und die Ereignisse der Elternfamilie (Heirat der Eltern gehört zu Generation 1)
        $generations = max(0, min(2, (int) ($_GET['ancestors'] ?? 0)));
        $children    = [$id];
        $ancestorIds = [];
        for ($g = 1; $g <= $generations && $children; $g++) {
            $in = implode(',', array_fill(0, count($children), '?'));
            $q  = $this->db->prepare("
                SELECT DISTINCT c.family_id FROM family_members c
                WHERE c.role = 'CHIL' AND c.person_id IN ($in)" . Privacy::familySql('c.family_id'));
            $q->execute($children);
            $families = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
            if (!$families) break;
            $fin = implode(',', array_fill(0, count($families), '?'));

            $q = $this->db->prepare("SELECT DISTINCT person_id FROM family_members WHERE family_id IN ($fin) AND role <> 'CHIL'");
            $q->execute($families);
            $children = array_values(array_filter(array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN)),
                                                  fn($p) => !Privacy::hides($p) && !isset($ancestorIds[$p])));
            foreach ($children as $p) $ancestorIds[$p] = $g;

            $q = $this->db->prepare("
                SELECT 'family' AS scope, e.event_type, e.event_date, e.event_descriptor, e.place_id, e.family_id, e.sort_order, e.id,
                       $g AS generation, NULL AS person_id
                FROM family_events e WHERE e.family_id IN ($fin) AND e.place_id IS NOT NULL
                ORDER BY e.sort_order, e.id");
            $q->execute($families);
            array_push($events, ...$q->fetchAll());

            if ($children) {
                $pin = implode(',', array_fill(0, count($children), '?'));
                $q = $this->db->prepare("
                    SELECT 'person' AS scope, e.event_type, e.event_date, e.event_descriptor, e.place_id, NULL AS family_id, e.sort_order, e.id,
                           $g AS generation, e.person_id
                    FROM person_events e WHERE e.person_id IN ($pin) AND e.place_id IS NOT NULL
                    ORDER BY e.person_id, e.sort_order, e.id");
                $q->execute($children);
                array_push($events, ...$q->fetchAll());
            }
        }

        // Namen der Vorfahren
        $ancestorNames = [];
        if ($ancestorIds) {
            $ids = array_keys($ancestorIds);
            $in  = implode(',', array_fill(0, count($ids), '?'));
            $q   = $this->db->prepare("SELECT p.id AS person_id, " . self::nameSql('p.id') . " AS display_name FROM persons p WHERE p.id IN ($in)");
            $q->execute($ids);
            foreach ($q->fetchAll() as $r) $ancestorNames[(int) $r['person_id']] = Privacy::maskRef($r)['display_name'];
        }

        // Partner je Familie (für „Heirat mit …“; bei Vorfahren-Familien beide Partner)
        $partners = [];
        $familyIds = array_values(array_unique(array_filter(array_map(fn($e) => (int) $e['family_id'], $events))));
        if ($familyIds) {
            $in = implode(',', array_fill(0, count($familyIds), '?'));
            $q  = $this->db->prepare("
                SELECT fm.family_id, fm.person_id, " . self::nameSql('fm.person_id') . " AS display_name
                FROM family_members fm
                WHERE fm.family_id IN ($in) AND fm.role <> 'CHIL' AND fm.person_id <> ?
            ");
            $q->execute([...$familyIds, $id]);
            foreach ($q->fetchAll() as $r) {
                $r = Privacy::maskRef($r);
                $partners[(int) $r['family_id']][] = $r['display_name'];
            }
        }

        $placeIds = array_values(array_unique(array_map(fn($e) => (int) $e['place_id'], $events)));
        $coords   = (new PlaceIndex($this->db))->coordinates($placeIds);
        $names    = [];
        if ($placeIds) {
            $in = implode(',', array_fill(0, count($placeIds), '?'));
            $q  = $this->db->prepare("SELECT id, name, full_name FROM places WHERE id IN ($in)");
            $q->execute($placeIds);
            foreach ($q->fetchAll() as $r) $names[(int) $r['id']] = $r;
        }

        $places = []; $without = [];
        foreach ($events as $e) {
            $pid = (int) $e['place_id'];
            $ev  = [
                'scope'      => $e['scope'],
                'event_type' => $e['event_type'],
                'event_date' => $e['event_date'],
                'event_descriptor' => $e['event_descriptor'],
                'family_id'  => $e['family_id'] !== null ? (int) $e['family_id'] : null,
                'partner'    => $e['family_id'] !== null ? implode((int) $e['generation'] ? ' & ' : ', ', $partners[(int) $e['family_id']] ?? []) : null,
                'generation' => (int) $e['generation'],
                'person'     => $e['person_id'] !== null ? ($ancestorNames[(int) $e['person_id']] ?? '') : null,
                'person_id'  => $e['person_id'] !== null ? (int) $e['person_id'] : null,
            ];
            if (isset($coords[$pid])) {
                [$lat, $lon, $approx] = $coords[$pid];
                $places[$pid] ??= ['id' => $pid, 'name' => $names[$pid]['name'] ?? '', 'full_name' => $names[$pid]['full_name'] ?? '',
                                   'lat' => $lat, 'lon' => $lon, 'approx' => $approx, 'events' => []];
                $places[$pid]['events'][] = $ev;
            } else {
                $without[$pid] ??= ['id' => $pid, 'full_name' => $names[$pid]['full_name'] ?? '', 'events' => []];
                $without[$pid]['events'][] = $ev;
            }
        }
        $this->json(['places' => array_values($places), 'without_coords' => array_values($without)]);
    }

    // -------------------------------------------------------------------------
}
