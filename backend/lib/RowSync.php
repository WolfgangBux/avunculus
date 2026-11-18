<?php

declare(strict_types=1);

/**
 * Abgleich der Kindzeilen eines Besitzers (z. B. Namen einer Person, Quellenangaben eines Ereignisses):
 * Zeilen mit bekannter ID werden aktualisiert – die ID bleibt, damit Verweise und eine spätere
 * Änderungshistorie stabil sind –, neue angelegt, nicht mehr enthaltene mit deleteRest() gelöscht.
 *
 * Eine ID aus der Anfrage wird nur übernommen, wenn die Zeile zu diesem Besitzer gehört und in
 * diesem Abgleich noch nicht verwendet wurde; sonst wird eine neue Zeile angelegt.
 *
 *   $sync = new RowSync($db, 'person_names', 'person_id', $personId);
 *   foreach ($names as $n) $sync->save($n['id'] ?? null, [...Spalten...]);
 *   $sync->deleteRest();
 *
 * Tabellen- und Spaltennamen kommen immer aus dem Code, nie aus der Anfrage.
 */
final class RowSync
{
    /** @var array<int,true> */
    private array $existing;
    /** @var array<int,true> */
    private array $kept = [];
    private ?PDOStatement $insert = null;
    private ?PDOStatement $update = null;

    public function __construct(
        private readonly PDO $db,
        private readonly string $table,
        private readonly string $ownerCol,
        private readonly int $ownerId,
    ) {
        $stmt = $db->prepare("SELECT id FROM $table WHERE $ownerCol = ?");
        $stmt->execute([$ownerId]);
        $this->existing = array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
    }

    /** Zeile speichern (Spalten ohne id und Besitzer); liefert ihre ID */
    public function save(mixed $id, array $row): int
    {
        $id = is_numeric($id) ? (int) $id : 0;
        if (isset($this->existing[$id]) && !isset($this->kept[$id])) {
            $this->update ??= $this->db->prepare("UPDATE $this->table SET " . self::assignments($row)
                                                . " WHERE id = :_id AND $this->ownerCol = :_owner");
            $this->update->execute([...$row, '_id' => $id, '_owner' => $this->ownerId]);
        } else {
            $this->insert ??= $this->db->prepare("INSERT INTO $this->table SET $this->ownerCol = :_owner, " . self::assignments($row));
            $this->insert->execute([...$row, '_owner' => $this->ownerId]);
            $id = (int) $this->db->lastInsertId();
        }
        $this->kept[$id] = true;
        return $id;
    }

    /** Alle Zeilen des Besitzers löschen, die in diesem Abgleich nicht gespeichert wurden */
    public function deleteRest(): void
    {
        $gone = array_keys(array_diff_key($this->existing, $this->kept));
        if (!$gone) return;
        $in = implode(',', array_fill(0, count($gone), '?'));
        $this->db->prepare("DELETE FROM $this->table WHERE $this->ownerCol = ? AND id IN ($in)")
            ->execute([$this->ownerId, ...$gone]);
    }

    private static function assignments(array $row): string
    {
        return implode(', ', array_map(fn($col) => "$col = :$col", array_keys($row)));
    }
}
