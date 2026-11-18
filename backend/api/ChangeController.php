<?php

declare(strict_types=1);

/** Änderungsprotokoll eines Stammbaums lesen (nur Bearbeiter; geschrieben wird es von ChangeLog) */
class ChangeController extends TreeScopedController
{
    /** Datensatztyp → Tabelle, um zu prüfen, ob es den Datensatz noch gibt */
    private const TABLES = [
        'person' => 'persons', 'family' => 'families', 'source' => 'sources', 'repository' => 'repositories',
        'place'  => 'places',  'media'  => 'media',    'tree'   => 'trees',
    ];

    // GET /api/changes?type=&id=&user=&q=&limit=&offset=  –  neueste zuerst
    // Bei offset=0 kommen die Benutzer mit, die in diesem Baum etwas geändert haben (für den Filter).
    public function index(): void
    {
        $limit  = max(1, min(200, (int) ($_GET['limit'] ?? 50)));
        $offset = max(0, (int) ($_GET['offset'] ?? 0));

        $where  = 'c.tree_id = ?';
        $params = [$this->treeId];
        if (isset(self::TABLES[$type = (string) ($_GET['type'] ?? '')])) {
            $where   .= ' AND c.entity_type = ?';
            $params[] = $type;
            if (($id = (int) ($_GET['id'] ?? 0)) > 0) { $where .= ' AND c.entity_id = ?'; $params[] = $id; }
        }
        if (($user = (int) ($_GET['user'] ?? 0)) > 0) { $where .= ' AND c.user_id = ?'; $params[] = $user; }
        foreach (array_slice(preg_split('/\s+/', trim((string) ($_GET['q'] ?? '')), -1, PREG_SPLIT_NO_EMPTY), 0, 6) as $w) {
            $where   .= ' AND c.entity_label LIKE ?';
            $params[] = '%' . addcslashes($w, '%_\\') . '%';
        }

        $count = $this->db->prepare("SELECT COUNT(*) FROM change_log c WHERE $where");
        $count->execute($params);

        $stmt = $this->db->prepare("
            SELECT c.id, c.created_at, c.user_id, c.user_name, c.entity_type, c.entity_id, c.entity_label, c.action, c.details
            FROM change_log c WHERE $where
            ORDER BY c.id DESC
            LIMIT $limit OFFSET $offset
        ");
        $stmt->execute($params);
        $items = array_map(fn($r) => [
            ...$r,
            'id'        => (int) $r['id'],
            'user_id'   => $r['user_id'] !== null ? (int) $r['user_id'] : null,
            'entity_id' => $r['entity_id'] !== null ? (int) $r['entity_id'] : null,
            'details'   => $r['details'] !== null ? json_decode($r['details'], true) : null,
        ], $stmt->fetchAll());
        $this->markExisting($items);

        $out = ['total' => (int) $count->fetchColumn(), 'items' => $items];
        if ($offset === 0) {
            $u = $this->db->prepare('
                SELECT c.user_id AS id, COALESCE(MAX(NULLIF(u.display_name, \'\')), MAX(u.username), MAX(c.user_name)) AS name
                FROM change_log c LEFT JOIN users u ON u.id = c.user_id
                WHERE c.tree_id = ? AND c.user_id IS NOT NULL
                GROUP BY c.user_id ORDER BY name');
            $u->execute([$this->treeId]);
            $out['users'] = array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['name']], $u->fetchAll());
        }
        $this->json($out);
    }

    /** exists = gibt es den Datensatz noch (im Protokoll stehen auch gelöschte) */
    private function markExisting(array &$items): void
    {
        $byType = [];
        foreach ($items as $it) if ($it['entity_id']) $byType[$it['entity_type']][] = $it['entity_id'];
        $found = [];
        foreach ($byType as $type => $ids) {
            $table = self::TABLES[$type] ?? null;
            if (!$table) continue;
            $ids  = array_values(array_unique($ids));
            $in   = implode(',', array_fill(0, count($ids), '?'));
            // Orte gehören allen Bäumen; alles andere nur, wenn es in diesem Baum liegt
            $tree = match ($type) { 'place' => '', 'tree' => ' AND id = ' . $this->treeId, default => ' AND tree_id = ' . $this->treeId };
            $stmt = $this->db->prepare("SELECT id FROM $table WHERE id IN ($in)$tree");
            $stmt->execute($ids);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) $found[$type][(int) $id] = true;
        }
        foreach ($items as &$it) $it['exists'] = $it['entity_id'] && isset($found[$it['entity_type']][$it['entity_id']]);
    }
}
