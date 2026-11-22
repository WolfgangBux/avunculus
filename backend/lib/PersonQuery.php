<?php

declare(strict_types=1);

/**
 * Lese-Abfragen für Personenlisten, die auch bei sehr großen Bäumen (50.000+ Personen) schnell bleiben.
 *
 * „Schlanke“ Personen enthalten nur, was Listen, Suche und Stammbaum anzeigen:
 * id, sex, Hauptname, Anzahl Namen, Geburt und Tod (Datum, Ort).
 */
final class PersonQuery
{
    public function __construct(private readonly PDO $db, private readonly int $treeId) {}

    /**
     * Schlanke Daten dieser Personen des Baums (Reihenfolge wie in $ids). Absichtlich nur mit
     * IDs: große Bäume (50.000+ Personen) nie komplett laden, sondern über search().
     */
    public function slim(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) return [];
        $in     = ' AND p.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $params = $ids;

        // Erster Name und erstes Geburts-/Sterbeereignis je Person per Fensterfunktion statt Unterabfragen
        $stmt = $this->db->prepare("
            WITH fn AS (
                SELECT * FROM (
                    SELECT n.person_id, n.given_name, n.surname, n.surname_prefix, n.name_prefix, n.nickname,
                           ROW_NUMBER() OVER (PARTITION BY n.person_id ORDER BY n.sort_order, n.id) AS rn,
                           COUNT(*)     OVER (PARTITION BY n.person_id)                         AS cnt
                    FROM person_names n JOIN persons p ON p.id = n.person_id
                    WHERE p.tree_id = ? $in
                ) x WHERE rn = 1
            ),
            ev AS (
                SELECT * FROM (
                    SELECT e.person_id, e.event_type, e.event_date, e.place_id,
                           ROW_NUMBER() OVER (PARTITION BY e.person_id, e.event_type ORDER BY e.sort_order, e.id) AS rn
                    FROM person_events e JOIN persons p ON p.id = e.person_id
                    WHERE p.tree_id = ? $in AND e.event_type IN ('BIRT', 'DEAT')
                ) y WHERE rn = 1
            )
            SELECT p.id, p.sex, p.restriction,
                   fn.given_name, fn.surname, fn.surname_prefix, fn.name_prefix, fn.nickname,
                   COALESCE(fn.cnt, 0) AS name_count,
                   -- Nachname aus einem weiteren Namen, falls der Hauptname keinen hat (z. B. nur Ehename erfasst)
                   CASE WHEN COALESCE(fn.surname, '') = '' THEN (
                       SELECT n2.surname FROM person_names n2
                       WHERE n2.person_id = p.id AND COALESCE(n2.surname, '') <> ''
                       ORDER BY n2.sort_order, n2.id LIMIT 1
                   ) END AS alt_surname,
                   b.event_date AS birth_date, bp.full_name AS birth_place,
                   d.event_date AS death_date, dp.full_name AS death_place,
                   (SELECT pm.media_id FROM person_media pm WHERE pm.person_id = p.id AND pm.is_primary = 1 LIMIT 1) AS photo_id
            FROM persons p
            LEFT JOIN fn      ON fn.person_id = p.id
            LEFT JOIN ev b    ON b.person_id = p.id AND b.event_type = 'BIRT'
            LEFT JOIN ev d    ON d.person_id = p.id AND d.event_type = 'DEAT'
            LEFT JOIN places bp ON bp.id = b.place_id
            LEFT JOIN places dp ON dp.id = d.place_id
            WHERE p.tree_id = ? $in
        ");
        $stmt->execute([$this->treeId, ...$params, $this->treeId, ...$params, $this->treeId, ...$params]);
        $rows = array_map(fn($r) => Privacy::maskSlim([...$r, 'id' => (int) $r['id'], 'name_count' => (int) $r['name_count'],
                                     'photo_id' => $r['photo_id'] !== null ? (int) $r['photo_id'] : null]), $stmt->fetchAll());

        $byId = array_column($rows, null, 'id');
        return array_values(array_filter(array_map(fn($id) => $byId[$id] ?? null, $ids)));
    }

    /**
     * Suche mit Seitenweise-Laden.
     * Jedes Wort muss in einem Namen der Person vorkommen; 3–4-stellige Zahlen suchen
     * im Geburts-, Tauf- oder Sterbedatum („Müller 1879“).
     * @return array{total: int, items: list<array>}
     */
    public function search(string $q, int $limit, int $offset, array $exclude = [], ?int $around = null): array
    {
        [$where, $params] = $this->filter($q, $exclude);

        $count = $this->db->prepare("SELECT COUNT(*) FROM persons p WHERE p.tree_id = ? $where");
        $count->execute([$this->treeId, ...$params]);
        $total = (int) $count->fetchColumn();

        // around: die Seite, auf der diese Person steht (mit ein paar Namen davor) – statt die Person oben anzuheften
        if ($around !== null && ($pos = $this->position($around, $where, $params)) !== null) {
            $offset = max(0, $pos - self::AROUND_BEFORE);
        }

        $stmt = $this->db->prepare("
            SELECT p.id FROM persons p " . self::FIRST_NAME_JOIN . "
            WHERE p.tree_id = ? $where
            ORDER BY " . self::ORDER . "
            LIMIT $limit OFFSET $offset
        ");
        $stmt->execute([$this->treeId, $this->treeId, ...$params]);

        return ['total' => $total, 'offset' => $offset, 'items' => $this->slim($stmt->fetchAll(PDO::FETCH_COLUMN))];
    }

    /** So viele Personen stehen bei „around“ noch über der gesuchten */
    private const AROUND_BEFORE = 10;

    /** Erster Name je Person (für die Sortierung) */
    private const FIRST_NAME_JOIN = "
        LEFT JOIN (
            SELECT person_id, given_name, surname FROM (
                SELECT n.person_id, n.given_name, n.surname,
                       ROW_NUMBER() OVER (PARTITION BY n.person_id ORDER BY n.sort_order, n.id) AS rn
                FROM person_names n JOIN persons p2 ON p2.id = n.person_id
                WHERE p2.tree_id = ?
            ) x WHERE rn = 1
        ) fn ON fn.person_id = p.id";

    /** Alphabetisch nach Nachname, Vorname; ohne Nachnamen ans Ende */
    private const ORDER = "COALESCE(fn.surname, '') = '', COALESCE(fn.surname, ''), COALESCE(fn.given_name, ''), p.id";

    /** Stelle (ab 0) dieser Person in der sortierten Liste; null, wenn sie nicht zur Suche passt */
    private function position(int $id, string $where, array $params): ?int
    {
        $stmt = $this->db->prepare("
            SELECT pos FROM (
                SELECT p.id, ROW_NUMBER() OVER (ORDER BY " . self::ORDER . ") - 1 AS pos
                FROM persons p " . self::FIRST_NAME_JOIN . "
                WHERE p.tree_id = ? $where
            ) ranked WHERE id = ?
        ");
        $stmt->execute([$this->treeId, $this->treeId, ...$params, $id]);
        $pos = $stmt->fetchColumn();
        return $pos === false ? null : (int) $pos;
    }

    /** Erste Person (alphabetisch) – Standard-Hauptperson des Stammbaums */
    public function firstId(): ?int
    {
        $r = $this->search('', 1, 0);
        return $r['items'][0]['id'] ?? null;
    }

    public function count(): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM persons WHERE tree_id = ?');
        $stmt->execute([$this->treeId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array{0: string, 1: array} SQL-Bedingungen (beginnen mit AND) und Parameter */
    private function filter(string $q, array $exclude): array
    {
        $where  = Privacy::personSql('p');   // Besucher finden private Personen nicht
        $params = [];
        foreach (array_slice(preg_split('/\s+/', trim($q), -1, PREG_SPLIT_NO_EMPTY), 0, 6) as $tok) {
            $like = '%' . addcslashes($tok, '%_\\') . '%';
            if (preg_match('/^\d{3,4}$/', $tok)) {
                $where .= " AND EXISTS (SELECT 1 FROM person_events e WHERE e.person_id = p.id
                                        AND e.event_type IN ('BIRT', 'BAPM', 'DEAT') AND e.event_date LIKE ?)";
            } else {
                $where .= " AND EXISTS (SELECT 1 FROM person_names n WHERE n.person_id = p.id
                                        AND CONCAT_WS(' ', n.name_prefix, n.given_name, n.nickname, n.surname_prefix, n.surname) LIKE ?)";
            }
            $params[] = $like;
        }
        $exclude = array_values(array_filter(array_map('intval', $exclude)));
        if ($exclude) {
            $where .= ' AND p.id NOT IN (' . implode(',', array_fill(0, count($exclude), '?')) . ')';
            array_push($params, ...$exclude);
        }
        return [$where, $params];
    }
}
