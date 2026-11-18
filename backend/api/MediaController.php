<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/MediaStore.php';
require_once __DIR__ . '/../lib/MediaLinks.php';

/** Mediathek eines Stammbaums: Bilder, Fotos, Dokumente (GEDCOM OBJE) */
class MediaController extends TreeScopedController
{
    private const MAX_BYTES   = 200 * 1024 * 1024;   // je Datei
    private const CHUNK_LIMIT = 4 * 1024 * 1024;     // je Upload-Stück (unter post_max_size)

    /** Filter der Mediathek → SQL-Bedingung auf mime_type */
    private const TYPE_FILTERS = [
        'image'    => "m.mime_type LIKE 'image/%'",
        'document' => "(m.mime_type = 'application/pdf' OR m.mime_type LIKE 'text/%' OR m.mime_type LIKE 'application/%')",
        'av'       => "(m.mime_type LIKE 'audio/%' OR m.mime_type LIKE 'video/%')",
    ];

    /** Anzahl der Verknüpfungen eines Mediums (für Liste und „nicht verknüpft“) */
    private const LINK_COUNT = '(SELECT COUNT(*) FROM person_media x WHERE x.media_id = m.id)
                              + (SELECT COUNT(*) FROM family_media x WHERE x.media_id = m.id)
                              + (SELECT COUNT(*) FROM event_media x WHERE x.media_id = m.id)
                              + (SELECT COUNT(*) FROM family_event_media x WHERE x.media_id = m.id)
                              + (SELECT COUNT(*) FROM source_media x WHERE x.media_id = m.id)';

    // GET /api/media/search?q=&type=image|document|av&unlinked=1&limit=&offset=
    public function search(): void
    {
        $limit  = max(1, min(200, (int) ($_GET['limit'] ?? 60)));
        $offset = max(0, (int) ($_GET['offset'] ?? 0));
        $where  = '';
        $params = [];
        foreach (array_slice(preg_split('/\s+/', trim((string) ($_GET['q'] ?? '')), -1, PREG_SPLIT_NO_EMPTY), 0, 6) as $tok) {
            $where   .= " AND CONCAT_WS(' ', m.title, m.original_name, m.notes, m.media_date) LIKE ?";
            $params[] = '%' . addcslashes($tok, '%_\\') . '%';
        }
        if ($filter = self::TYPE_FILTERS[$_GET['type'] ?? ''] ?? null) $where .= " AND $filter";
        if (!empty($_GET['unlinked'])) $where .= ' AND (' . self::LINK_COUNT . ') = 0';

        $where .= Privacy::mediaSql('m');   // Besucher: nichts von privaten Personen/Familien

        $count = $this->db->prepare("SELECT COUNT(*) FROM media m WHERE m.tree_id = ? $where");
        $count->execute([$this->treeId, ...$params]);

        $stmt = $this->db->prepare("
            SELECT m.id, m.title, m.original_name, m.mime_type, m.width, m.height, m.file_size, m.media_date,
                   " . self::LINK_COUNT . " AS link_count
            FROM media m WHERE m.tree_id = ? $where
            ORDER BY m.id DESC
            LIMIT $limit OFFSET $offset
        ");
        $stmt->execute([$this->treeId, ...$params]);
        $this->json([
            'total' => (int) $count->fetchColumn(),
            'items' => array_map(fn($r) => [
                ...MediaLinks::item($r),
                'original_name' => $r['original_name'],
                'file_size'     => (int) $r['file_size'],
                'media_date'    => $r['media_date'],
                'link_count'    => (int) $r['link_count'],
            ], $stmt->fetchAll()),
        ]);
    }

    // GET /api/media/{id}  –  mit allen Stellen, an denen das Medium verwendet wird
    public function show(int $id, int $status = 200): void
    {
        $m = $this->findOrFail($id);
        $this->json([...$this->present($m), 'links' => $this->links($id)], $status);
    }

    /**
     * POST /api/media/upload?upload=<token>&chunk=<n>[&final=1&name=<Dateiname>]
     * Datei in Stücken als Roh-Body (wie der GEDCOM-Import), damit PHP-Limits keine Rolle spielen.
     * Mit final=1 wird das Medium angelegt; ist dieselbe Datei schon in der Mediathek, kommt diese zurück.
     */
    public function upload(): void
    {
        $this->collectGarbage();
        $token = $_GET['upload'] ?? '';
        $chunk = (int) ($_GET['chunk'] ?? -1);
        if (!preg_match('/^[a-f0-9]{32}$/', $token) || $chunk < 0) {
            $this->json(['error' => 'Ungültige Upload-Parameter'], 400); return;
        }
        $file = sys_get_temp_dir() . "/avunculus-media-$token";
        $body = file_get_contents('php://input');
        if (strlen($body) > self::CHUNK_LIMIT) { $this->json(['error' => 'Upload-Stück zu groß'], 413); return; }
        if ($chunk > 0 && !is_file($file)) {
            $this->json(['error' => 'Upload unvollständig – bitte erneut versuchen'], 409); return;
        }
        file_put_contents($file, $body, $chunk === 0 ? 0 : FILE_APPEND);
        if (filesize($file) > self::MAX_BYTES) {
            @unlink($file);
            $this->json(['error' => 'Die Datei ist zu groß (max. 200 MB).'], 413); return;
        }
        if (empty($_GET['final'])) { $this->json(['received' => filesize($file)]); return; }

        $name = mb_substr(basename(str_replace('\\', '/', trim((string) ($_GET['name'] ?? '')))), 0, 255) ?: 'Datei';
        if (filesize($file) === 0) { @unlink($file); $this->json(['error' => 'Die Datei ist leer.'], 422); return; }

        // Dieselbe Datei gibt es schon → vorhandenes Medium verwenden
        $existing = $this->findByChecksum(sha1_file($file));
        if ($existing) {
            @unlink($file);
            $this->json([...$this->present($existing), 'duplicate' => true]);
            return;
        }
        $id = $this->create($file, $name, pathinfo($name, PATHINFO_FILENAME));
        $this->show($id, 201);
    }

    // PUT /api/media/{id}  –  body: { title, media_date, notes }
    public function update(int $id): void
    {
        $this->findOrFail($id);
        $data = $this->body();
        $str  = fn(string $k, int $max) => ($v = trim((string) ($data[$k] ?? ''))) !== '' ? mb_substr($v, 0, $max) : null;
        $date = $str('media_date', 100);
        if ($date !== null) {
            $date = GedcomDate::normalize($date);
            if (!GedcomDate::isValid($date)) {
                $this->json(['errors' => ['media_date' => 'Ungültiges GEDCOM-Datum']], 422); return;
            }
        }
        $this->db->prepare('UPDATE media SET title = ?, media_date = ?, notes = ? WHERE id = ?')
            ->execute([$str('title', 300), $date, $str('notes', 65000), $id]);
        $this->show($id);
    }

    // DELETE /api/media/{id}  –  Datei und alle Verknüpfungen
    public function destroy(int $id): void
    {
        $m = $this->findOrFail($id);
        $this->db->prepare('DELETE FROM media WHERE id = ?')->execute([$id]);
        MediaStore::delete($this->treeId, $m['file_name']);
        http_response_code(204);
    }

    // GET /api/media/{id}/file[?download=1]
    public function file(int $id): void
    {
        $m    = $this->findOrFail($id);
        $path = MediaStore::path($this->treeId, $m['file_name']);
        if (!is_file($path)) { $this->json(['error' => 'Die Datei fehlt im Medienordner.'], 404); return; }

        // Nur sicher darstellbare Typen im Browser öffnen, alles andere herunterladen
        $inline = empty($_GET['download']) && preg_match('#^(image/(jpeg|png|gif|webp|bmp)|application/pdf|audio/|video/|text/plain)#', $m['mime_type']);
        $name   = $this->downloadName($m);
        $ascii  = preg_replace('/[^A-Za-z0-9 ._-]/', '_', $name);
        $this->sendFile($path, $m['mime_type'], $m['checksum'], ($inline ? 'inline' : 'attachment')
            . "; filename=\"$ascii\"; filename*=UTF-8''" . rawurlencode($name));
    }

    // GET /api/media/{id}/thumb  –  verkleinertes JPEG; 404, wenn es keins gibt (Frontend zeigt dann ein Symbol)
    public function thumb(int $id): void
    {
        $m     = $this->findOrFail($id);
        $thumb = MediaStore::thumb($this->treeId, $m['file_name'], $m['mime_type']);
        if (!$thumb) { $this->json(['error' => 'Kein Vorschaubild'], 404); return; }
        $this->sendFile($thumb, 'image/jpeg', $m['checksum'] . '-t', 'inline');
    }

    // -------------------------------------------------------------------------

    /** Medium aus einer Datei anlegen (Upload); liefert die ID */
    private function create(string $file, string $originalName, ?string $title): int
    {
        $stored = MediaStore::store($this->treeId, $file, $originalName);
        try {
            $this->db->prepare('
                INSERT INTO media (tree_id, title, file_name, original_name, mime_type, file_size, width, height, checksum)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ')->execute([$this->treeId, $title ? mb_substr($title, 0, 300) : null, $stored['file_name'], $originalName,
                         $stored['mime_type'], $stored['file_size'], $stored['width'], $stored['height'], $stored['checksum']]);
        } catch (\Throwable $e) {
            MediaStore::discardWritten();
            throw $e;
        }
        return (int) $this->db->lastInsertId();
    }

    private function findByChecksum(string $checksum): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM media WHERE tree_id = ? AND checksum = ? LIMIT 1');
        $stmt->execute([$this->treeId, $checksum]);
        return $stmt->fetch() ?: null;
    }

    private function findOrFail(int $id): array
    {
        return $this->rowOrFail('SELECT * FROM media m WHERE m.id = ? AND m.tree_id = ?' . Privacy::mediaSql('m'),
                                [$id, $this->treeId], 'Medium nicht gefunden');
    }

    private function present(array $m): array
    {
        return [
            ...MediaLinks::item($m),
            'title'         => $m['title'],
            'original_name' => $m['original_name'],
            'file_size'     => (int) $m['file_size'],
            'media_date'    => $m['media_date'],
            'notes'         => $m['notes'],
            'created_at'    => $m['created_at'],
            'has_thumb'     => MediaStore::canThumb($m['mime_type']),
        ];
    }

    /** Dateiname für den Download: Titel bzw. ursprünglicher Name mit passender Endung */
    private function downloadName(array $m): string
    {
        $ext  = pathinfo($m['file_name'], PATHINFO_EXTENSION);
        $base = $m['original_name'] ?: ($m['title'] ?: 'medium-' . $m['id']);
        return strtolower(pathinfo($base, PATHINFO_EXTENSION)) === strtolower($ext) ? $base : "$base.$ext";
    }

    /** Wo wird das Medium verwendet? Personen, Ereignisse, Familien, Familienereignisse, Quellen */
    private function links(int $mediaId): array
    {
        $personName = fn(string $pid) => "(SELECT CONCAT_WS(', ', NULLIF(TRIM(CONCAT_WS(' ', n.surname_prefix, n.surname)), ''), n.given_name)
                                           FROM person_names n WHERE n.person_id = $pid ORDER BY n.sort_order, n.id LIMIT 1)";
        $partners   = fn(string $fid) => "(SELECT GROUP_CONCAT(CONCAT_WS(' ', n.given_name, n.surname_prefix, n.surname)
                                                  ORDER BY FIELD(fm.role, 'HUSB', 'WIFE') SEPARATOR ' & ')
                                           FROM family_members fm JOIN person_names n ON n.id = (
                                               SELECT id FROM person_names WHERE person_id = fm.person_id ORDER BY sort_order, id LIMIT 1)
                                           WHERE fm.family_id = $fid AND fm.role IN ('HUSB', 'WIFE'))";
        $queries = [
            'person'       => "SELECT 'person' AS kind, l.person_id AS target_id, l.is_primary, NULL AS event_type, NULL AS event_date,
                                      p.sex, {$personName('l.person_id')} AS label
                               FROM person_media l JOIN persons p ON p.id = l.person_id WHERE l.media_id = ?",
            'event'        => "SELECT 'event', e.person_id, 0, e.event_type, e.event_date, p.sex, {$personName('e.person_id')}
                               FROM event_media l JOIN person_events e ON e.id = l.event_id JOIN persons p ON p.id = e.person_id
                               WHERE l.media_id = ?",
            'family'       => "SELECT 'family', l.family_id, 0, NULL, NULL, NULL, {$partners('l.family_id')}
                               FROM family_media l WHERE l.media_id = ?",
            'family_event' => "SELECT 'family_event', e.family_id, 0, e.event_type, e.event_date, NULL, {$partners('e.family_id')}
                               FROM family_event_media l JOIN family_events e ON e.id = l.event_id WHERE l.media_id = ?",
            'source'       => "SELECT 'source', s.id, 0, NULL, NULL, NULL, s.title
                               FROM source_media l JOIN sources s ON s.id = l.source_id WHERE l.media_id = ?",
        ];
        $stmt = $this->db->prepare(implode("\nUNION ALL\n", $queries));
        $stmt->execute(array_fill(0, count($queries), $mediaId));
        return array_map(fn($r) => [
            'kind'       => $r['kind'],
            'target_id'  => (int) $r['target_id'],
            'label'      => $r['label'] ?: '–',
            'sex'        => $r['sex'],
            'event_type' => $r['event_type'],
            'event_date' => $r['event_date'],
            'is_primary' => (bool) $r['is_primary'],
        ], $stmt->fetchAll());
    }

    private function sendFile(string $path, string $mime, string $etag, string $disposition): void
    {
        $etag = '"' . $etag . '"';
        header_remove('Content-Type');
        header('Cache-Control: private, max-age=86400');
        header("ETag: $etag");
        header('X-Content-Type-Options: nosniff');
        // Skripte in hochgeladenen Dateien (z. B. SVG/HTML) nie im Kontext der App ausführen;
        // der PDF-Betrachter des Browsers funktioniert mit „sandbox“ nicht, er ist selbst abgeschottet
        if ($mime !== 'application/pdf') header("Content-Security-Policy: sandbox; default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'");
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); return; }
        header("Content-Type: $mime");
        header('Content-Length: ' . filesize($path));
        header("Content-Disposition: $disposition");
        readfile($path);
    }

    /** Liegengebliebene Upload-Stücke nach 24 Stunden entfernen */
    private function collectGarbage(): void
    {
        foreach (glob(sys_get_temp_dir() . '/avunculus-media-*') ?: [] as $f) {
            if (filemtime($f) < time() - 86400) @unlink($f);
        }
    }
}
