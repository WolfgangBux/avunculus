<?php

declare(strict_types=1);

/**
 * Verknüpfungen zwischen Medien und Personen, Familien, Ereignissen, Quellen und Quellenangaben.
 * Jede Art hat eine eigene Tabelle (mit Fremdschlüsseln, damit Löschen aufräumt).
 *
 * Ein Medium in API-Antworten: { id, title, mime_type, width, height, is_primary } – bei Personen dazu
 * crop: Ausschnitt der Person auf dem Bild { left, top, width, height, shape } (Anteile 0–1) oder null.
 * Beim Speichern genügt [{ id, is_primary? }, …]; der Ausschnitt einer Person bleibt dabei erhalten
 * (er wird über PUT media/{id}/region gesetzt), außer ein Eintrag bringt selbst crop mit (Import).
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
        // Quellenangaben (SOUR.OBJE): Scan des zitierten Eintrags
        'person_citation'       => ['person_citation_media',       'citation_id'],
        'family_citation'       => ['family_citation_media',       'citation_id'],
        'event_citation'        => ['event_citation_media',        'citation_id'],
        'family_event_citation' => ['family_event_citation_media', 'citation_id'],
    ];

    /** Tabelle der Quellenangaben → Art ihrer Medien-Verknüpfungen */
    public const CITATION_KINDS = [
        'person_citations'       => 'person_citation',
        'family_citations'       => 'family_citation',
        'event_citations'        => 'event_citation',
        'family_event_citations' => 'family_event_citation',
    ];

    public function __construct(private readonly PDO $db, private readonly int $treeId) {}

    /** Spalten des Ausschnitts in person_media */
    private const CROP_COLS = 'l.crop_left, l.crop_top, l.crop_width, l.crop_height, l.crop_shape';

    /** @return array<int, list<array>> Besitzer-ID → Medien in Reihenfolge */
    public function fetch(string $kind, array $ownerIds): array
    {
        [$table, $col] = self::KINDS[$kind];
        $ownerIds = array_values(array_unique(array_filter(array_map('intval', $ownerIds))));
        if (!$ownerIds) return [];
        $extra = $kind === 'person' ? 'l.is_primary, ' . self::CROP_COLS . ',' : '0 AS is_primary,';
        $in    = implode(',', array_fill(0, count($ownerIds), '?'));
        $stmt  = $this->db->prepare("
            SELECT l.$col AS owner_id, $extra m.id, m.title, m.original_name, m.mime_type, m.width, m.height
            FROM $table l JOIN media m ON m.id = l.media_id
            WHERE l.$col IN ($in)" . Privacy::mediaSql('m') . "
            ORDER BY l.$col, l.sort_order, l.id
        ");
        $stmt->execute($ownerIds);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['owner_id']][] = self::item($r) + ($kind === 'person' ? ['crop' => self::cropFromRow($r)] : []);
        }
        return $out;
    }

    /** Quellenangaben (Liste mit 'id') um ihre Medien ergänzen; $citTable ist ein Schlüssel von CITATION_KINDS */
    public function attachToCitations(string $citTable, array $citations): array
    {
        $media = $this->fetch(self::CITATION_KINDS[$citTable], array_column($citations, 'id'));
        return array_map(fn($c) => [...$c, 'media' => $media[(int) $c['id']] ?? []], $citations);
    }

    /** Medien einer gespeicherten Quellenangabe ersetzen – nur wenn die Anfrage sie mitschickt */
    public function saveForCitation(string $citTable, int $citationId, array $citation): void
    {
        if (array_key_exists('media', $citation)) $this->save(self::CITATION_KINDS[$citTable], $citationId, (array) $citation['media']);
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

    // ── Ausschnitt (Person auf dem Bild) ─────────────────────────────

    /** Ausschnitt aus einer Zeile mit crop_*-Spalten; null = ganzes Bild */
    public static function cropFromRow(array $r): ?array
    {
        if ($r['crop_width'] === null || $r['crop_height'] === null) return null;
        return [
            'left'   => round((float) $r['crop_left'], 4),
            'top'    => round((float) $r['crop_top'], 4),
            'width'  => round((float) $r['crop_width'], 4),
            'height' => round((float) $r['crop_height'], 4),
            'shape'  => $r['crop_shape'] === 'ellipse' ? 'ellipse' : 'rect',
        ];
    }

    /**
     * Ausschnitt aus einer Eingabe prüfen und auf das Bild begrenzen; null = keiner bzw. ungültig.
     * Zu kleine Ausschnitte (unter 1 % je Richtung) gelten als versehentlich.
     */
    public static function normalizeCrop(mixed $c): ?array
    {
        if (!is_array($c)) return null;
        foreach (['left', 'top', 'width', 'height'] as $k) if (!is_numeric($c[$k] ?? null)) return null;
        $l = max(0.0, min(1.0, (float) $c['left']));
        $t = max(0.0, min(1.0, (float) $c['top']));
        $w = min(1.0 - $l, (float) $c['width']);
        $h = min(1.0 - $t, (float) $c['height']);
        if ($w < 0.01 || $h < 0.01) return null;
        return ['left' => round($l, 6), 'top' => round($t, 6), 'width' => round($w, 6), 'height' => round($h, 6),
                'shape' => ($c['shape'] ?? '') === 'ellipse' ? 'ellipse' : 'rect'];
    }

    /** Parameterwerte für die crop_*-Spalten (alle null ohne Ausschnitt) */
    private static function cropValues(?array $c): array
    {
        return $c ? [$c['left'], $c['top'], $c['width'], $c['height'], $c['shape']] : [null, null, null, null, null];
    }

    /** Verknüpfungen eines Besitzers ersetzen; nur Medien aus diesem Baum, jedes einmal, höchstens ein Hauptbild */
    public function save(string $kind, int $ownerId, array $items): void
    {
        [$table, $col] = self::KINDS[$kind];
        // Ausschnitte bleiben beim Medium, das die Person behält (das Personenformular schickt sie nicht mit)
        if ($kind === 'person') {
            $stmt = $this->db->prepare('SELECT media_id, crop_left, crop_top, crop_width, crop_height, crop_shape FROM person_media WHERE person_id = ?');
            $stmt->execute([$ownerId]);
            $kept = [];
            foreach ($stmt as $r) if ($c = self::cropFromRow($r)) $kept[(int) $r['media_id']] = $c;
            $items = array_map(fn($m) => is_array($m) && !array_key_exists('crop', $m) && isset($kept[(int) ($m['id'] ?? 0)])
                ? [...$m, 'crop' => $kept[(int) $m['id']]] : $m, $items);
        }
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
            ? "INSERT INTO $table ($col, media_id, is_primary, sort_order, crop_left, crop_top, crop_width, crop_height, crop_shape)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
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
                $crop = is_array($m) ? self::normalizeCrop($m['crop'] ?? null) : null;
                $ins->execute([$ownerId, $id, (int) $primary, min($order++, 255), ...self::cropValues($crop)]);
            } else {
                $ins->execute([$ownerId, $id, min($order++, 255)]);
            }
        }
    }

    /** Ausschnitt einer Person auf einem Medium setzen (null = entfernen); false, wenn die Verknüpfung fehlt */
    public function setCrop(int $mediaId, int $personId, ?array $crop): bool
    {
        $stmt = $this->db->prepare('UPDATE person_media SET crop_left = ?, crop_top = ?, crop_width = ?, crop_height = ?, crop_shape = ?
                                    WHERE media_id = ? AND person_id = ?');
        $stmt->execute([...self::cropValues(self::normalizeCrop($crop)), $mediaId, $personId]);
        if ($stmt->rowCount() > 0) return true;
        $chk = $this->db->prepare('SELECT 1 FROM person_media WHERE media_id = ? AND person_id = ?');   // unverändert gespeichert?
        $chk->execute([$mediaId, $personId]);
        return (bool) $chk->fetchColumn();
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
