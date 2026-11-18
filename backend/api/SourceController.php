<?php

declare(strict_types=1);

class SourceController extends TreeScopedController
{
    private const MAX_EVENTS = 300;

    /** Archiv-ID nur übernehmen, wenn das Archiv zu diesem Baum gehört */
    private function repositoryInTree(int $id): ?int
    {
        return $this->inTree('repositories', $id) ? $id : null;
    }

    // GET /api/sources/search?q=&limit=&offset=  –  Quellenliste und Quellenauswahl; jedes Wort muss in Titel,
    // Kurztitel, Autor, Archiv oder Signatur vorkommen. Treffer am Anfang von Kurztitel/Titel zuerst.
    public function search(): void
    {
        $limit  = max(1, min(200, (int) ($_GET['limit'] ?? 50)));
        $offset = max(0, (int) ($_GET['offset'] ?? 0));
        $words  = array_slice(preg_split('/\s+/', trim((string) ($_GET['q'] ?? '')), -1, PREG_SPLIT_NO_EMPTY), 0, 6);

        $where  = '';
        $params = [];
        foreach ($words as $w) {
            $where   .= " AND CONCAT_WS(' ', s.title, s.abbreviation, s.author, r.name, s.call_number) LIKE ?";
            $params[] = '%' . addcslashes($w, '%_\\') . '%';
        }
        $from = 'FROM sources s LEFT JOIN repositories r ON r.id = s.repository_id WHERE s.tree_id = ?' . $where;

        $count = $this->db->prepare("SELECT COUNT(*) $from");
        $count->execute([$this->treeId, ...$params]);

        $first = $words ? addcslashes($words[0], '%_\\') . '%' : '%';
        $stmt  = $this->db->prepare("
            SELECT s.id, s.title, s.abbreviation, s.author, s.call_number, r.name AS repository_name
            $from
            ORDER BY (s.abbreviation LIKE ? OR s.title LIKE ?) DESC, s.title, s.id
            LIMIT $limit OFFSET $offset
        ");
        $stmt->execute([$this->treeId, ...$params, $first, $first]);
        $this->json([
            'total' => (int) $count->fetchColumn(),
            'items' => array_map(fn($r) => [...$r, 'id' => (int) $r['id']], $stmt->fetchAll()),
        ]);
    }

    public function show(int $id): void
    {
        $this->json($this->full($id));
    }

    /** Quelle mit den verknüpften Medien (z. B. Scans) */
    private function full(int $id): array
    {
        $source = $this->findOrFail($id);
        $source['media'] = (new MediaLinks($this->db, $this->treeId))->fetch('source', [$id])[$id] ?? [];
        [$source['events'], $source['event_total']] = $this->events($id);
        return $source;
    }

    /**
     * Ereignisse dieses Stammbaums, die diese Quelle zitieren (höchstens MAX_EVENTS), chronologisch
     * @return array{0: list<array>, 1: int}
     */
    private function events(int $sourceId): array
    {
        $person = "FROM event_citations c JOIN person_events e ON e.id = c.event_id JOIN persons o ON o.id = e.person_id
                   WHERE c.source_id = ? AND o.tree_id = ?" . Privacy::personSql('o');
        $family = "FROM family_event_citations c JOIN family_events e ON e.id = c.event_id JOIN families o ON o.id = e.family_id
                   WHERE c.source_id = ? AND o.tree_id = ?" . Privacy::familySql('o.id');
        $args   = [$sourceId, $this->treeId, $sourceId, $this->treeId];

        $stmt = $this->db->prepare("SELECT (SELECT COUNT(*) $person) + (SELECT COUNT(*) $family)");
        $stmt->execute($args);
        $total = (int) $stmt->fetchColumn();
        if (!$total) return [[], 0];

        $stmt = $this->db->prepare("
            SELECT x.*, pl.full_name AS event_place FROM (
                SELECT 'person' AS kind, c.id AS citation_id, e.id, e.person_id AS owner_id, e.event_type, e.event_date, e.place_id, c.page $person
                UNION ALL
                SELECT 'family', c.id, e.id, e.family_id, e.event_type, e.event_date, e.place_id, c.page $family
            ) x LEFT JOIN places pl ON pl.id = x.place_id
            ORDER BY x.owner_id");
        $stmt->execute($args);
        // erst alle chronologisch ordnen (GEDCOM-Datum ist in SQL nicht sortierbar), dann kürzen
        // undatierte ans Ende
        $rows = $stmt->fetchAll();
        $keys = array_map(fn($r) => GedcomDate::sortKey($r['event_date']) ?? PHP_INT_MAX, $rows);
        array_multisort($keys, SORT_ASC, SORT_NUMERIC, array_keys($rows), SORT_ASC, $rows);
        $rows = array_slice($rows, 0, self::MAX_EVENTS);

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
                'id'          => (int) $r['citation_id'],
                'event_id'    => (int) $r['id'],
                'owner_id'    => $owner,
                'event_type'  => $r['event_type'],
                'event_date'  => $r['event_date'],
                'event_place' => $r['event_place'],
                'page'        => $r['page'],
                'persons'     => array_values(array_filter($r['kind'] === 'person'
                    ? [$pick($owner)]
                    : array_map($pick, $partners[$owner] ?? []))),
            ];
        }, $rows);
        return [$events, $total];
    }

    private function saveMedia(int $id, array $data): void
    {
        if (array_key_exists('media', $data)) (new MediaLinks($this->db, $this->treeId))->save('source', $id, (array) $data['media']);
    }

    public function store(): void
    {
        $data   = $this->body();
        $errors = $this->validate($data);
        if ($errors) { $this->json(['errors' => $errors], 422); return; }

        $stmt = $this->db->prepare('
            INSERT INTO sources (tree_id, title, abbreviation, author, publication, repository_id, call_number, media_type, source_text, notes)
            VALUES (:tree_id, :title, :abbreviation, :author, :publication, :repository_id, :call_number, :media_type, :source_text, :notes)
        ');
        $stmt->execute([...$this->params($data), 'tree_id' => $this->treeId]);
        $id = (int) $this->db->lastInsertId();
        $this->saveMedia($id, $data);
        $this->json($this->full($id), 201);
    }

    public function update(int $id): void
    {
        $this->findOrFail($id);
        $data   = $this->body();
        $errors = $this->validate($data);
        if ($errors) { $this->json(['errors' => $errors], 422); return; }

        $stmt = $this->db->prepare('
            UPDATE sources SET
                title        = :title,
                abbreviation = :abbreviation,
                author       = :author,
                publication  = :publication,
                repository_id = :repository_id,
                call_number  = :call_number,
                media_type   = :media_type,
                source_text  = :source_text,
                notes        = :notes
            WHERE id = :id
        ');
        $stmt->execute([...$this->params($data), 'id' => $id]);
        $this->saveMedia($id, $data);
        $this->json($this->full($id));
    }

    public function destroy(int $id): void
    {
        $this->findOrFail($id);
        $this->db->prepare('DELETE FROM sources WHERE id = ?')->execute([$id]);
        http_response_code(204);
    }

    // -------------------------------------------------------------------------

    private function findOrFail(int $id): array
    {
        return $this->rowOrFail('
            SELECT s.*, r.name AS repository_name
            FROM sources s LEFT JOIN repositories r ON r.id = s.repository_id
            WHERE s.id = ? AND s.tree_id = ?
        ', [$id, $this->treeId], 'Quelle nicht gefunden');
    }

    private function params(array $data): array
    {
        $str = fn(string $key): ?string =>
            ($v = trim($data[$key] ?? '')) !== '' ? $v : null;
        return [
            'title'        => trim($data['title'] ?? ''),
            'abbreviation' => $str('abbreviation'),
            'author'       => $str('author'),
            'publication'  => $str('publication'),
            'repository_id' => $this->repositoryInTree((int) ($data['repository_id'] ?? 0)),
            'call_number'   => ($v = $str('call_number')) !== null ? mb_substr($v, 0, 250) : null,
            'media_type'    => in_array($data['media_type'] ?? '', GedcomTags::MEDIA_TYPES, true) ? $data['media_type'] : null,
            'source_text'  => $str('source_text'),
            'notes'        => $str('notes'),
        ];
    }

    private function validate(array $data): array
    {
        $errors = [];
        if (empty(trim($data['title'] ?? ''))) {
            $errors['title'] = 'Titel ist erforderlich';
        }
        return $errors;
    }
}
