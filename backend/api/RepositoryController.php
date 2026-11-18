<?php

declare(strict_types=1);

/** Archive / Aufbewahrungsorte (GEDCOM REPO) eines Stammbaums */
class RepositoryController extends TreeScopedController
{
    private const FIELDS = [
        'name' => 300, 'address' => 65000, 'postal_code' => 20, 'city' => 120, 'country' => 120,
        'phone' => 60, 'email' => 200, 'website' => 500, 'notes' => 65000,
    ];

    // GET /api/repositories  –  mit Anzahl der Quellen
    public function index(): void
    {
        $stmt = $this->db->prepare('
            SELECT r.*, (SELECT COUNT(*) FROM sources s WHERE s.repository_id = r.id) AS source_count
            FROM repositories r
            WHERE r.tree_id = ?
            ORDER BY r.name
        ');
        $stmt->execute([$this->treeId]);
        $this->json(array_map(fn($r) => [...$r, 'id' => (int) $r['id'], 'source_count' => (int) $r['source_count']], $stmt->fetchAll()));
    }

    // GET /api/repositories/{id}  –  mit den Quellen, die dort aufbewahrt werden
    public function show(int $id, int $status = 200): void
    {
        $repo = $this->findOrFail($id);
        $stmt = $this->db->prepare('
            SELECT id, title, abbreviation, author, call_number, media_type
            FROM sources WHERE repository_id = ? ORDER BY title
        ');
        $stmt->execute([$id]);
        $repo['sources'] = array_map(fn($s) => [...$s, 'id' => (int) $s['id']], $stmt->fetchAll());
        $this->json($repo, $status);
    }

    public function store(): void
    {
        $params = $this->params($this->body());
        if ($params === null) return;
        $cols = array_keys($params);
        $this->db->prepare('INSERT INTO repositories (tree_id, ' . implode(', ', $cols) . ') VALUES (?, '
                         . implode(', ', array_fill(0, count($cols), '?')) . ')')
            ->execute([$this->treeId, ...array_values($params)]);
        $this->show((int) $this->db->lastInsertId(), 201);
    }

    public function update(int $id): void
    {
        $this->findOrFail($id);
        $params = $this->params($this->body());
        if ($params === null) return;
        $this->db->prepare('UPDATE repositories SET ' . implode(', ', array_map(fn($c) => "$c = ?", array_keys($params)))
                         . ' WHERE id = ?')
            ->execute([...array_values($params), $id]);
        $this->show($id);
    }

    // DELETE /api/repositories/{id}  –  Quellen bleiben erhalten, verlieren nur den Verweis
    public function destroy(int $id): void
    {
        $this->findOrFail($id);
        $this->db->prepare('DELETE FROM repositories WHERE id = ?')->execute([$id]);
        http_response_code(204);
    }

    // -------------------------------------------------------------------------

    private function findOrFail(int $id): array
    {
        $repo = $this->rowOrFail('SELECT * FROM repositories WHERE id = ? AND tree_id = ?', [$id, $this->treeId], 'Archiv nicht gefunden');
        return [...$repo, 'id' => (int) $repo['id']];
    }

    /** Validiert die Eingaben; bei Fehlern 422 und null */
    private function params(array $data): ?array
    {
        $out = [];
        foreach (self::FIELDS as $field => $max) {
            $v = trim((string) ($data[$field] ?? ''));
            $out[$field] = $v !== '' ? mb_substr($v, 0, $max) : null;
        }
        if ($out['name'] === null) {
            $this->json(['errors' => ['name' => 'Name ist erforderlich']], 422);
            return null;
        }
        return $out;
    }
}
