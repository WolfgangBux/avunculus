<?php

declare(strict_types=1);

/**
 * Verknüpfungen zwischen Medien und Personen, Familien, Ereignissen und Quellen.
 * Jede Art hat eine eigene Tabelle (mit Fremdschlüsseln, damit Löschen aufräumt).
 *
 * Ein Medium in API-Antworten: { id, title, mime_type, width, height, is_primary }
 * Beim Speichern genügt [{ id, is_primary? }, …].
 */
final class MediaLinks
{
    /** Art → [Tabelle, Besitzer-Spalte] (fest im Code, nie aus Eingaben) */
    public const KINDS = [
        'person'       => ['person_media',       'person_id'],
        'family'       => ['family_media',       'family_id'],
        'event'        => ['event_media',        'event_id'],
        'family_event' => ['family_event_media', 'event_id'],
        'source'       => ['source_media',       'source_id'],
    ];

    public function __construct(private readonly PDO $db, private readonly int $treeId) {}

    /** @return array<int, list<array>> Besitzer-ID → Medien in Reihenfolge */
    public function fetch(string $kind, array $ownerIds): array
    {
        [$table, $col] = self::KINDS[$kind];
        $ownerIds = array_values(array_unique(array_filter(array_map('intval', $ownerIds))));
        if (!$ownerIds) return [];
        $primary = $kind === 'person' ? 'l.is_primary' : '0';
        $in   = implode(',', array_fill(0, count($ownerIds), '?'));
        $stmt = $this->db->prepare("
            SELECT l.$col AS owner_id, $primary AS is_primary, m.id, m.title, m.original_name, m.mime_type, m.width, m.height
            FROM $table l JOIN media m ON m.id = l.media_id
            WHERE l.$col IN ($in)" . Privacy::mediaSql('m') . "
            ORDER BY l.$col, l.sort_order, l.id
        ");
        $stmt->execute($ownerIds);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['owner_id']][] = self::item($r);
        }
        return $out;
    }

    public static function item(array $r): array
    {
        return [
            'id'         => (int) $r['id'],
            'title'      => $r['title'] ?: ($r['original_name'] ?? null),
            'mime_type'  => $r['mime_type'],
            'width'      => $r['width'] !== null ? (int) $r['width'] : null,
            'height'     => $r['height'] !== null ? (int) $r['height'] : null,
            'is_primary' => (bool) ($r['is_primary'] ?? false),
        ];
    }

    /** Verknüpfungen eines Besitzers ersetzen; nur Medien aus diesem Baum, jedes einmal, höchstens ein Hauptbild */
    public function save(string $kind, int $ownerId, array $items): void
    {
        [$table, $col] = self::KINDS[$kind];
        $this->db->prepare("DELETE FROM $table WHERE $col = ?")->execute([$ownerId]);
        $this->insert($kind, $ownerId, $items);
    }

    /** Verknüpfungen anhängen (ohne vorhandene zu löschen) */
    public function insert(string $kind, int $ownerId, array $items, int $order = 0): void
    {
        [$table, $col] = self::KINDS[$kind];
        $valid = $this->validIds(array_map(fn($m) => is_array($m) ? ($m['id'] ?? 0) : $m, $items));
        if (!$valid) return;
        $isPerson = $kind === 'person';
        $ins = $this->db->prepare($isPerson
            ? "INSERT INTO $table ($col, media_id, is_primary, sort_order) VALUES (?, ?, ?, ?)"
            : "INSERT INTO $table ($col, media_id, sort_order) VALUES (?, ?, ?)");
        $seen = [];
        $hasPrimary = false;
        foreach ($items as $m) {
            $id = (int) (is_array($m) ? ($m['id'] ?? 0) : $m);
            if (!isset($valid[$id]) || isset($seen[$id])) continue;
            $seen[$id] = true;
            if ($isPerson) {
                $primary = !$hasPrimary && is_array($m) && !empty($m['is_primary']);
                $hasPrimary = $hasPrimary || $primary;
                $ins->execute([$ownerId, $id, (int) $primary, min($order++, 255)]);
            } else {
                $ins->execute([$ownerId, $id, min($order++, 255)]);
            }
        }
    }

    /**
     * Verknüpfungen ergänzen, ohne vorhandene zu verdoppeln (Import in bestehenden Baum).
     * Hat die Person schon ein Hauptbild, bleibt es dabei.
     */
    public function append(string $kind, int $ownerId, array $items): void
    {
        [$table, $col] = self::KINDS[$kind];
        $stmt = $this->db->prepare("SELECT media_id, sort_order" . ($kind === 'person' ? ', is_primary' : ', 0 AS is_primary')
                                 . " FROM $table WHERE $col = ?");
        $stmt->execute([$ownerId]);
        $rows  = $stmt->fetchAll();
        $have  = array_fill_keys(array_map('intval', array_column($rows, 'media_id')), true);
        $items = array_values(array_filter($items, fn($m) => !isset($have[(int) $m['id']])));
        if (!$items) return;
        if (array_filter(array_column($rows, 'is_primary'))) {
            $items = array_map(fn($m) => [...$m, 'is_primary' => false], $items);
        }
        $this->insert($kind, $ownerId, $items, $rows ? max(array_map('intval', array_column($rows, 'sort_order'))) + 1 : 0);
    }

    /** @return array<int,true> */
    public function validIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return [];
        $in   = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("SELECT id FROM media WHERE tree_id = ? AND id IN ($in)");
        $stmt->execute([$this->treeId, ...$ids]);
        return array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
    }
}
