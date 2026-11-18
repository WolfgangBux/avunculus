<?php

declare(strict_types=1);

/** Gemeinsame Grundlage aller Controller: JSON ein/aus, Datensatz holen oder 404 */
abstract class BaseController
{
    public function __construct(protected readonly PDO $db) {}

    protected function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    /** Anfragekörper als JSON-Objekt (leer, wenn keiner oder ungültig) */
    protected function body(): array
    {
        return (array) json_decode(file_get_contents('php://input'), true);
    }

    /** Erste Zeile der Abfrage – oder Abbruch mit 404 und dieser Meldung */
    protected function rowOrFail(string $sql, array $params, string $notFound): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch() ?: throw new HttpError(404, $notFound);
    }
}

/**
 * Controller für die Daten eines Stammbaums (Header X-Tree-Id). Jede Abfrage muss auf
 * $this->treeId eingeschränkt werden; IDs aus Anfragen vor dem Speichern mit idsInTree() prüfen.
 */
abstract class TreeScopedController extends BaseController
{
    /** Tabellen mit Spalte tree_id, deren IDs aus Anfragen geprüft werden */
    private const TREE_TABLES = ['persons', 'families', 'sources', 'repositories', 'media'];

    public function __construct(PDO $db, protected readonly int $treeId)
    {
        parent::__construct($db);
    }

    /**
     * Welche dieser IDs gehören zu diesem Stammbaum?
     * @return array<int,true>
     */
    protected function idsInTree(string $table, array $ids): array
    {
        if (!in_array($table, self::TREE_TABLES, true)) throw new LogicException("idsInTree: Tabelle $table nicht erlaubt");
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return [];
        $in   = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("SELECT id FROM $table WHERE tree_id = ? AND id IN ($in)");
        $stmt->execute([$this->treeId, ...$ids]);
        return array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
    }

    /** Gehört dieser Datensatz zu diesem Stammbaum? */
    protected function inTree(string $table, int $id): bool
    {
        return $id > 0 && isset($this->idsInTree($table, [$id])[$id]);
    }
}
