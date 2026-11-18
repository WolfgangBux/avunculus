<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/GedcomImporter.php';
require_once __DIR__ . '/../lib/GedcomExporter.php';
require_once __DIR__ . '/../lib/MediaStore.php';

class TreeController extends BaseController
{
    private const MAX_IMPORT_BYTES = 200 * 1024 * 1024;   // 200 MB – GEDCOM-Datei (wird ganz eingelesen)
    private const MAX_ZIP_BYTES    = 4 * 1024 * 1024 * 1024;   // 4 GB – ZIP samt Medien (werden gestreamt)
    private const CHUNK_LIMIT      = 4 * 1024 * 1024;     // je Upload-Stück (unter post_max_size)

    // GET /api/trees  –  Besucher sehen nur öffentliche Bäume
    public function index(): void
    {
        $trees = $this->fetchTrees();
        if (Auth::isGuest()) $trees = array_values(array_filter($trees, fn($t) => $t['is_public']));
        $this->json($trees);
    }

    // POST /api/trees  –  body: { name }
    public function store(): void
    {
        $name = $this->validName($this->body()['name'] ?? '');
        if ($name === null) return;

        $this->db->prepare('INSERT INTO trees (name) VALUES (?)')->execute([$name]);
        $this->json($this->fetchTrees((int) $this->db->lastInsertId())[0], 201);
    }

    // PUT /api/trees/{id}  –  body: { name?, is_public? }
    public function update(int $id): void
    {
        $this->findOrFail($id);
        $data = $this->body();
        if (array_key_exists('name', $data)) {
            $name = $this->validName($data['name']);
            if ($name === null) return;
            $this->db->prepare('UPDATE trees SET name = ? WHERE id = ?')->execute([$name, $id]);
        }
        if (array_key_exists('is_public', $data)) {
            $public = (bool) $data['is_public'];
            // vor der Freigabe Datenschutz berechnen, damit der erste Besucher nicht warten muss
            if ($public) Privacy::recompute($this->db, $id);
            $this->db->prepare('UPDATE trees SET is_public = ? WHERE id = ?')->execute([(int) $public, $id]);
        }
        if (array_key_exists('start_person_id', $data)) {
            $pid = $data['start_person_id'] === null ? null : (int) $data['start_person_id'];
            if ($pid !== null && !$this->personInTree($pid, $id)) { $this->json(['error' => 'Person nicht gefunden'], 422); return; }
            $this->db->prepare('UPDATE trees SET start_person_id = ? WHERE id = ?')->execute([$pid, $id]);
        }
        $this->json($this->fetchTrees($id)[0]);
    }

    // DELETE /api/trees/{id}  –  löscht den Baum mit allen Personen, Familien und Quellen
    public function destroy(int $id): void
    {
        $this->findOrFail($id);
        $this->db->prepare('DELETE FROM trees WHERE id = ?')->execute([$id]);
        MediaStore::deleteTree($id);
        http_response_code(204);
    }

    // GET /api/trees/{id}/export[?media=1]  –  GEDCOM 5.5.1 (UTF-8) als Download, mit media=1 als ZIP samt Mediendateien
    public function export(int $id): void
    {
        $this->findOrFail($id);
        $tree = $this->fetchTrees($id)[0];
        set_time_limit(0);
        if (!empty($_GET['media'])) { $this->exportZip($id, $tree['name']); return; }

        $file = GedcomExporter::fileName($tree['name']);
        $ascii = preg_replace('/[^A-Za-z0-9 ._-]/', '_', $file);
        header('Content-Type: text/plain; charset=utf-8');
        header("Content-Disposition: attachment; filename=\"$ascii\"; filename*=UTF-8''" . rawurlencode($file));
        header('Cache-Control: no-store');

        // gepuffert in Blöcken ausgeben – schneller als jede Zeile einzeln
        $buffer = "\u{FEFF}";
        $out = function (string $line) use (&$buffer) {
            $buffer .= $line;
            if (strlen($buffer) > 65536) { echo $buffer; $buffer = ''; }
        };
        (new GedcomExporter($this->db, $id, $out))->run($tree['name']);
        echo $buffer;
    }

    // GET /api/trees/{id}/relatives?person=<id>  –  Vorschau: wie groß würde der Teilbaum?
    public function relativesPreview(int $id): void
    {
        $this->findOrFail($id);
        $personId = (int) ($_GET['person'] ?? 0);
        if (!$this->personInTree($personId, $id)) { $this->json(['error' => 'Person nicht gefunden'], 404); return; }
        $r = Relatives::collect($this->db, $id, $personId);
        $this->json([...$r, 'persons' => count($r['persons']), 'families' => count($r['families'])]);
    }

    /**
     * POST /api/trees/{id}/relatives  –  body: { person_id, name }
     * Neuer Stammbaum mit allen Blutsverwandten der Person und deren Partnern. Weg über GEDCOM:
     * Teilexport (nur benutzte Quellen, Archive, Medien) und Import in den neuen Baum – so gilt
     * genau dieselbe Abbildung wie bei Export/Import, einschließlich der Mediendateien.
     */
    public function relativesTree(int $id): void
    {
        $this->findOrFail($id);
        $data     = $this->body();
        $personId = (int) ($data['person_id'] ?? 0);
        if (!$this->personInTree($personId, $id)) { $this->json(['error' => 'Person nicht gefunden'], 404); return; }
        $name = $this->validName($data['name'] ?? '');
        if ($name === null) return;

        set_time_limit(0);
        $rel = Relatives::collect($this->db, $id, $personId);
        $ged = tempnam(sys_get_temp_dir(), 'avunculus-extract-');
        $zipFile = $ged . '.zip';
        try {
            // 1. Teilexport
            $fh  = fopen($ged, 'wb');
            $exp = (new GedcomExporter($this->db, $id, fn(string $line) => fwrite($fh, $line)))
                ->limitTo($rel['persons'], $rel['families']);
            $exp->run($name);
            fclose($fh);

            // 2. Mediendateien in ein ZIP (der Import sucht sie dort unter dem FILE-Pfad)
            $zipPath = null;
            if ($used = $exp->usedMedia()) {
                $zip = new ZipArchive();
                if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                    throw new RuntimeException('ZIP-Archiv kann nicht angelegt werden.');
                }
                $in   = implode(',', array_map('intval', $used));
                $stmt = $this->db->query("SELECT id, title, file_name, original_name FROM media WHERE id IN ($in)");
                foreach ($stmt->fetchAll() as $m) {
                    $path = MediaStore::path($id, $m['file_name']);
                    if (is_file($path)) $zip->addFile($path, GedcomExporter::mediaPath($m));
                }
                $zip->close();
                $zipPath = is_file($zipFile) ? $zipFile : null;
            }

            // 3. Import in einen neuen Baum
            $this->db->beginTransaction();
            try {
                $this->db->prepare('INSERT INTO trees (name) VALUES (?)')->execute([$name]);
                $newId  = (int) $this->db->lastInsertId();
                $importer = new GedcomImporter($this->db);
                $result   = $importer->import(file_get_contents($ged), $newId, [], false, $zipPath);
                // Ausgangsperson wird Startperson des neuen Baums
                $this->db->prepare('UPDATE trees SET start_person_id = ? WHERE id = ?')
                    ->execute([$importer->importedPersonId("@I$personId@"), $newId]);
                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollBack();
                MediaStore::discardWritten();
                throw $e;
            }
        } finally {
            @unlink($ged);
            @unlink($zipFile);
        }

        $this->json(['tree' => $this->fetchTrees($newId)[0], 'stats' => $result['stats'], 'warnings' => $result['warnings']], 201);
    }

    private function personInTree(int $personId, int $treeId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM persons WHERE id = ? AND tree_id = ?');
        $stmt->execute([$personId, $treeId]);
        return (bool) $stmt->fetchColumn();
    }

    /** GEDZIP-ähnliches Archiv: gedcom.ged und die Mediendateien unter media/ (wie FILE in der GEDCOM-Datei) */
    private function exportZip(int $id, string $treeName): void
    {
        $ged = tempnam(sys_get_temp_dir(), 'avunculus-export-');
        $zipFile = $ged . '.zip';
        try {
            $fh = fopen($ged, 'wb');
            fwrite($fh, "\u{FEFF}");
            (new GedcomExporter($this->db, $id, fn(string $line) => fwrite($fh, $line)))->run($treeName);
            fclose($fh);

            $zip = new ZipArchive();
            if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('ZIP-Archiv kann nicht angelegt werden.');
            }
            $zip->addFile($ged, 'gedcom.ged');
            $stmt = $this->db->prepare('SELECT id, title, file_name, original_name, mime_type FROM media WHERE tree_id = ? ORDER BY id');
            $stmt->execute([$id]);
            foreach ($stmt->fetchAll() as $m) {
                $path = MediaStore::path($id, $m['file_name']);
                if (!is_file($path)) continue;
                $entry = GedcomExporter::mediaPath($m);
                $zip->addFile($path, $entry);
                // Bilder und PDFs sind schon komprimiert
                if (preg_match('#^(image/(jpeg|png|gif|webp)|application/pdf|video/|audio/mpeg)#', $m['mime_type'])) {
                    $zip->setCompressionName($entry, ZipArchive::CM_STORE);
                }
            }
            $zip->close();

            $file  = GedcomExporter::fileName($treeName, 'zip');
            $ascii = preg_replace('/[^A-Za-z0-9 ._-]/', '_', $file);
            header('Content-Type: application/zip');
            header('Content-Length: ' . filesize($zipFile));
            header("Content-Disposition: attachment; filename=\"$ascii\"; filename*=UTF-8''" . rawurlencode($file));
            header('Cache-Control: no-store');
            readfile($zipFile);
        } finally {
            @unlink($ged);
            @unlink($zipFile);
        }
    }

    /**
     * POST /api/trees/import?upload=<token>&chunk=<n>[&final=1&(name=<Baumname> | target=<Baum-ID>)]
     *
     * Die Datei kommt in Stücken als Roh-Body, damit PHP-Limits (upload_max_filesize,
     * post_max_size) keine Rolle spielen. Mit final=1:
     *   name=…   → neuer Baum, sofort importieren
     *   target=… → Dublettenprüfung gegen den Baum; die Datei bleibt liegen, bis
     *              /api/trees/import/commit die Entscheidungen bringt
     */
    public function import(): void
    {
        $this->collectGarbage();

        $token = $_GET['upload'] ?? '';
        $chunk = (int) ($_GET['chunk'] ?? -1);
        if (!preg_match('/^[a-f0-9]{32}$/', $token) || $chunk < 0) {
            $this->json(['error' => 'Ungültige Upload-Parameter'], 400); return;
        }

        $file = $this->uploadPath($token);
        $body = file_get_contents('php://input');
        if (strlen($body) > self::CHUNK_LIMIT) {
            $this->json(['error' => 'Upload-Stück zu groß'], 413); return;
        }
        if ($chunk > 0 && !is_file($file)) {
            $this->json(['error' => 'Upload unvollständig – bitte erneut versuchen'], 409); return;
        }
        file_put_contents($file, $body, $chunk === 0 ? 0 : FILE_APPEND);

        clearstatcache(true, $file);
        $isZip = $this->zipPath($file) !== null;
        if (filesize($file) > ($isZip ? self::MAX_ZIP_BYTES : self::MAX_IMPORT_BYTES)) {
            @unlink($file);
            $this->json(['error' => $isZip ? 'Das ZIP-Archiv ist zu groß (max. 4 GB).' : 'Die Datei ist zu groß (max. 200 MB).'], 413);
            return;
        }

        if (empty($_GET['final'])) {
            $this->json(['received' => filesize($file)]); return;
        }
        set_time_limit(0);   // viele Mediendateien zu übernehmen kann dauern

        // Import in bestehenden Baum: erst Dubletten suchen
        if (isset($_GET['target'])) {
            $targetId = (int) $_GET['target'];
            $this->findOrFail($targetId);
            try {
                $analysis = (new GedcomImporter($this->db))->analyze($this->readGedcom($file), $targetId);
            } catch (InvalidArgumentException $e) {
                @unlink($file);
                $this->json(['error' => $e->getMessage()], 422); return;
            }
            $this->json(['upload' => $token, 'tree' => $this->fetchTrees($targetId)[0], ...$analysis]);
            return;
        }

        try {
            $raw = $this->readGedcom($file);
        } catch (InvalidArgumentException $e) {
            @unlink($file);
            $this->json(['error' => $e->getMessage()], 422); return;
        }

        $name = trim((string) ($_GET['name'] ?? '')) ?: 'Importierter Stammbaum';
        $name = mb_substr($name, 0, 200);

        $this->db->beginTransaction();
        try {
            $this->db->prepare('INSERT INTO trees (name) VALUES (?)')->execute([$name]);
            $treeId = (int) $this->db->lastInsertId();
            $result = (new GedcomImporter($this->db))->import($raw, $treeId, [], false, $this->zipPath($file));
            $this->db->commit();
        } catch (InvalidArgumentException $e) {
            $this->db->rollBack();
            MediaStore::discardWritten();
            $this->json(['error' => $e->getMessage()], 422); return;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            MediaStore::discardWritten();
            throw $e;
        } finally {
            @unlink($file);
        }

        $this->json(['tree' => $this->fetchTrees($treeId)[0], ...$result], 201);
    }

    /**
     * POST /api/trees/import/commit  –  body: { upload, target, decisions: { xref: personId | null } }
     * Importiert eine zuvor analysierte Datei in den bestehenden Baum.
     */
    public function commitImport(): void
    {
        $data     = $this->body();
        $token    = (string) ($data['upload'] ?? '');
        $targetId = (int) ($data['target'] ?? 0);
        $file     = $this->uploadPath($token);

        if (!preg_match('/^[a-f0-9]{32}$/', $token) || !is_file($file)) {
            $this->json(['error' => 'Die hochgeladene Datei ist nicht mehr vorhanden – bitte den Import neu starten.'], 410); return;
        }
        $this->findOrFail($targetId);

        // Nur Zuordnungen zu Personen dieses Baums zulassen
        $decisions = array_filter((array) ($data['decisions'] ?? []), fn($v) => is_numeric($v) && (int) $v > 0);
        $mapping   = [];
        if ($decisions) {
            $ids  = array_values(array_unique(array_map('intval', $decisions)));
            $in   = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->db->prepare("SELECT id FROM persons WHERE tree_id = ? AND id IN ($in)");
            $stmt->execute([$targetId, ...$ids]);
            $valid = array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
            foreach ($decisions as $xref => $id) {
                if (isset($valid[(int) $id]) && preg_match('/^@[^@]+@$/', (string) $xref)) $mapping[$xref] = (int) $id;
            }
        }

        try {
            $raw = $this->readGedcom($file);
        } catch (InvalidArgumentException $e) {
            $this->json(['error' => $e->getMessage()], 422); return;
        }
        set_time_limit(0);
        $this->db->beginTransaction();
        try {
            $result = (new GedcomImporter($this->db))->import($raw, $targetId, $mapping, true, $this->zipPath($file));
            $this->db->commit();
            Privacy::markStale($this->db, $targetId);
        } catch (InvalidArgumentException $e) {
            $this->db->rollBack();
            MediaStore::discardWritten();
            $this->json(['error' => $e->getMessage()], 422); return;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            MediaStore::discardWritten();
            throw $e;
        }
        @unlink($file);

        $this->json(['tree' => $this->fetchTrees($targetId)[0], ...$result]);
    }

    /** Pfad der hochgeladenen Datei, wenn sie ein ZIP-Archiv ist (Quelle für Mediendateien), sonst null */
    private function zipPath(string $file): ?string
    {
        $fh    = fopen($file, 'rb');
        $magic = fread($fh, 4);
        fclose($fh);
        return $magic === "PK\x03\x04" ? $file : null;
    }

    /**
     * Inhalt der hochgeladenen GEDCOM-Datei. ZIP-Archive (.ged.zip, GEDZIP/.gdz) werden erkannt
     * und die enthaltene .ged-Datei gelesen; die Medien darin übernimmt der Import (OBJE).
     */
    private function readGedcom(string $file): string
    {
        $fh    = fopen($file, 'rb');
        $magic = fread($fh, 4);
        fclose($fh);
        if ($magic !== "PK\x03\x04") return file_get_contents($file);

        $zip = new ZipArchive();
        if ($zip->open($file) !== true) {
            throw new InvalidArgumentException('Das ZIP-Archiv konnte nicht geöffnet werden.');
        }
        try {
            // .ged-Dateien im Archiv (ohne macOS-Metadaten); GEDZIP nennt sie „gedcom.ged“
            $candidates = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = $stat['name'];
                if (str_ends_with($name, '/') || str_starts_with($name, '__MACOSX/') || str_contains($name, '/._')) continue;
                if (preg_match('/\.(ged|gedcom)$/i', $name)) $candidates[] = $stat;
            }
            if (!$candidates) {
                throw new InvalidArgumentException('Im ZIP-Archiv wurde keine GEDCOM-Datei (.ged) gefunden.');
            }
            usort($candidates, fn($a, $b) => (strcasecmp(basename($a['name']), 'gedcom.ged') !== 0) <=> (strcasecmp(basename($b['name']), 'gedcom.ged') !== 0)
                                             ?: $b['size'] <=> $a['size']);
            $entry = $candidates[0];
            if ($entry['size'] > self::MAX_IMPORT_BYTES) {
                throw new InvalidArgumentException('Die GEDCOM-Datei im ZIP-Archiv ist zu groß (max. 200 MB).');
            }
            $raw = $zip->getFromIndex($entry['index']);
            if ($raw === false) {
                throw new InvalidArgumentException('Die GEDCOM-Datei im ZIP-Archiv konnte nicht gelesen werden.');
            }
            return $raw;
        } finally {
            $zip->close();
        }
    }

    private function uploadPath(string $token): string
    {
        return sys_get_temp_dir() . "/avunculus-import-$token.ged";
    }

    /** Liegengebliebene Uploads (abgebrochene Importe) nach 24 Stunden entfernen */
    private function collectGarbage(): void
    {
        foreach (glob(sys_get_temp_dir() . '/avunculus-import-*.ged') ?: [] as $f) {
            if (filemtime($f) < time() - 86400) @unlink($f);
        }
    }

    // -------------------------------------------------------------------------

    private function fetchTrees(?int $id = null): array
    {
        $stmt = $this->db->prepare('
            SELECT t.id, t.name, t.description, t.is_public, t.created_at, t.updated_at,
                   (SELECT sp.id FROM persons sp WHERE sp.id = t.start_person_id AND sp.tree_id = t.id) AS start_person_id,
                   (SELECT COUNT(*) FROM persons  p WHERE p.tree_id = t.id) AS person_count,
                   (SELECT COUNT(*) FROM families f WHERE f.tree_id = t.id) AS family_count,
                   (SELECT COUNT(*) FROM sources  s WHERE s.tree_id = t.id) AS source_count,
                   (SELECT COUNT(*) FROM media    m WHERE m.tree_id = t.id) AS media_count
            FROM trees t
            WHERE (? IS NULL OR t.id = ?)
            ORDER BY t.name
        ');
        $stmt->execute([$id, $id]);
        return array_map(fn($t) => [
            ...$t,
            'id'           => (int) $t['id'],
            'is_public'    => (bool) $t['is_public'],
            'start_person_id' => $t['start_person_id'] !== null ? (int) $t['start_person_id'] : null,
            'person_count' => (int) $t['person_count'],
            'family_count' => (int) $t['family_count'],
            'source_count' => (int) $t['source_count'],
            'media_count'  => (int) $t['media_count'],
        ], $stmt->fetchAll());
    }

    private function findOrFail(int $id): void
    {
        if (!$this->fetchTrees($id)) throw new HttpError(404, 'Stammbaum nicht gefunden');
    }

    private function validName(mixed $name): ?string
    {
        $name = trim((string) $name);
        if ($name === '') {
            $this->json(['errors' => ['name' => 'Name ist erforderlich']], 422);
            return null;
        }
        return mb_substr($name, 0, 200);
    }
}
