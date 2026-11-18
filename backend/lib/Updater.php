<?php

declare(strict_types=1);

/**
 * Neue Versionen finden und installieren (nur Admin, über SystemController).
 *
 * Prüfen: höchstens einmal am Tag das neueste Release auf GitHub abfragen (abschaltbar mit
 * UPDATE_CHECK=0 in backend/.env). Installieren:
 *   1. avunculus-<version>.zip und die .sha256-Datei des Releases nach data/updates/ laden, Prüfsumme vergleichen
 *   2. entpacken (nur Einträge unter avunculus-<version>/, keine „..“), Inhalt prüfen, backend/.env hinüberkopieren
 *   3. Wartungsmodus an; backend/ und public/ als Ganzes austauschen, die übrigen Dateien einzeln überschreiben.
 *      Die alte Fassung bleibt in data/updates/previous/; schlägt ein Schritt fehl, wird zurückgetauscht.
 *   4. Wartungsmodus aus. Die Datenbank-Migrationen laufen bei der nächsten Anfrage (neuer Code, Migrations::run).
 * data/ und backend/.env bleiben unberührt.
 */
final class Updater
{
    /** werden als ganze Ordner ausgetauscht (keine alten Dateien übrig) */
    private const SWAP_DIRS = ['backend', 'public'];
    private const CHECK_INTERVAL = 86400;

    public function __construct(private readonly PDO $db) {}

    // ── Prüfen ───────────────────────────────────────────────────────────────────

    /**
     * @return array{current: string, check: bool, latest?: string, newer?: bool, notes?: string, url?: string,
     *               published_at?: string, checked_at?: int, can_install?: bool, blocker?: ?string, error?: string}
     */
    public function status(bool $refresh = false): array
    {
        $out = ['current' => AppInfo::version(), 'check' => self::checkEnabled()];
        if (!$out['check']) return $out;

        $cached  = json_decode((string) Settings::get($this->db, 'update_check'), true);
        $release = is_array($cached) ? $cached : null;
        if ($refresh || !$release || time() - (int) ($release['checked_at'] ?? 0) > self::CHECK_INTERVAL) {
            try {
                $release = $this->fetchLatest();
                Settings::set($this->db, 'update_check', json_encode($release, JSON_UNESCAPED_UNICODE));
            } catch (\Throwable $e) {
                error_log('[Avunculus] Update-Prüfung fehlgeschlagen: ' . $e->getMessage());
                $out['error'] = 'Die Prüfung auf neue Versionen ist fehlgeschlagen.';
                if (!$release) return $out;   // ältere Antwort zeigen, falls vorhanden
            }
        }
        $blocker = self::blocker();
        return $out + [
            'latest'       => $release['version'],
            'newer'        => version_compare($release['version'], $out['current'], '>'),
            'notes'        => $release['notes'],
            'url'          => $release['url'],
            'published_at' => $release['published_at'],
            'checked_at'   => (int) $release['checked_at'],
            'can_install'  => $blocker === null && $release['zip'] !== null,
            'blocker'      => $blocker ?? ($release['zip'] === null ? 'asset' : null),
        ];
    }

    public static function checkEnabled(): bool
    {
        return !in_array(strtolower((string) ($_ENV['UPDATE_CHECK'] ?? '1')), ['0', 'false', 'no', 'off'], true);
    }

    /** Warum hier nicht automatisch installiert werden kann – oder null */
    public static function blocker(): ?string
    {
        $root = AppInfo::root();
        if (is_dir("$root/.git")) return 'git';                       // Entwicklungsumgebung: per git aktualisieren
        if (!class_exists(ZipArchive::class)) return 'zip';
        if (!function_exists('curl_init') && !filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL)) return 'http';
        $need = [$root, "$root/backend", "$root/public", "$root/database", AppInfo::dataDir()];
        foreach ($need as $dir) {
            if (is_dir($dir) && !is_writable($dir)) return 'readonly';
        }
        return null;
    }

    /** @return array{version: string, notes: string, url: string, published_at: string, zip: ?string, sha256: ?string, checked_at: int} */
    private function fetchLatest(): array
    {
        $api = $_ENV['UPDATE_API'] ?? ('https://api.github.com/repos/' . AppInfo::REPO . '/releases/latest');   // UPDATE_API nur für Tests
        $r   = json_decode(self::http($api), true);
        if (!is_array($r) || empty($r['tag_name'])) throw new RuntimeException('Unerwartete Antwort von GitHub');
        $version = ltrim((string) $r['tag_name'], 'v');
        if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) throw new RuntimeException("Unbekanntes Versionsformat: {$r['tag_name']}");
        $assets = array_column((array) ($r['assets'] ?? []), 'browser_download_url', 'name');
        return [
            'version'      => $version,
            'notes'        => mb_substr((string) ($r['body'] ?? ''), 0, 20000),
            'url'          => (string) ($r['html_url'] ?? ''),
            'published_at' => (string) ($r['published_at'] ?? ''),
            'zip'          => $assets["avunculus-$version.zip"] ?? null,
            'sha256'       => $assets["avunculus-$version.zip.sha256"] ?? null,
            'checked_at'   => time(),
        ];
    }

    // ── Installieren ─────────────────────────────────────────────────────────────

    /** @return array{installed: string, previous: string} */
    public function install(string $version): array
    {
        if ($b = self::blocker()) throw new HttpError(409, "Automatische Installation hier nicht möglich ($b)");
        $release = $this->fetchLatest();
        if ($release['version'] !== $version) throw new HttpError(409, "Die neueste Version ist {$release['version']}, nicht $version.");
        if (!version_compare($version, AppInfo::version(), '>')) throw new HttpError(409, 'Diese Version ist bereits installiert.');
        if (!$release['zip'] || !$release['sha256']) throw new HttpError(409, 'Das Release enthält kein Installationspaket.');

        set_time_limit(300);
        $root    = AppInfo::root();
        $work    = AppInfo::dataDir() . '/updates';
        $zipFile = "$work/avunculus-$version.zip";
        $stage   = "$work/stage-$version";
        if (!is_dir($work) && !mkdir($work, 0775, true) && !is_dir($work)) throw new RuntimeException("$work kann nicht angelegt werden");
        self::removeTree($stage);

        // 1. Herunterladen und prüfen
        file_put_contents($zipFile, self::http($release['zip']));
        $expected = strtolower(substr(trim(self::http($release['sha256'])), 0, 64));
        if (!preg_match('/^[0-9a-f]{64}$/', $expected) || !hash_equals($expected, hash_file('sha256', $zipFile))) {
            @unlink($zipFile);
            throw new HttpError(502, 'Das heruntergeladene Paket ist beschädigt (Prüfsumme stimmt nicht).');
        }

        // 2. Entpacken und prüfen
        $src = self::extract($zipFile, $stage, "avunculus-$version");
        foreach (['VERSION', 'router.php', 'backend/index.php', 'public/index.html'] as $f) {
            if (!is_file("$src/$f")) throw new HttpError(502, "Das Paket ist unvollständig ($f fehlt).");
        }
        if (trim((string) file_get_contents("$src/VERSION")) !== $version) throw new HttpError(502, 'Das Paket hat eine andere Versionsnummer.');
        if (is_file("$root/backend/.env")) copy("$root/backend/.env", "$src/backend/.env");

        // 3. Austauschen
        $previous = AppInfo::version();
        $backup   = "$work/previous";
        self::removeTree($backup);
        mkdir($backup, 0775, true);
        $undo = [];   // Liste von Rückgängig-Schritten, neueste zuerst
        $flag = AppInfo::dataDir() . '/maintenance.flag';
        file_put_contents($flag, $version);
        try {
            foreach (self::SWAP_DIRS as $d) {
                if (is_dir("$root/$d")) {
                    self::move("$root/$d", "$backup/$d");
                    array_unshift($undo, fn() => self::move("$backup/$d", "$root/$d"));
                }
                self::move("$src/$d", "$root/$d");
                array_unshift($undo, fn() => self::move("$root/$d", "$src/$d"));
            }
            foreach (self::files($src) as $rel) {
                if (in_array(explode('/', $rel)[0], self::SWAP_DIRS, true)) continue;
                $target = "$root/$rel";
                if (is_file($target)) {
                    @mkdir(dirname("$backup/$rel"), 0775, true);
                    if (!copy($target, "$backup/$rel")) throw new RuntimeException("Sicherung von $rel fehlgeschlagen");
                    array_unshift($undo, fn() => copy("$backup/$rel", $target));
                } else {
                    array_unshift($undo, fn() => @unlink($target));
                }
                @mkdir(dirname($target), 0775, true);
                if (!copy("$src/$rel", $target)) throw new RuntimeException("$rel kann nicht geschrieben werden");
            }
        } catch (\Throwable $e) {
            foreach ($undo as $step) {
                try { $step(); } catch (\Throwable) { /* weiter zurückrollen */ }
            }
            @unlink($flag);
            error_log('[Avunculus] Update auf ' . $version . ' fehlgeschlagen, zurückgerollt: ' . $e->getMessage());
            throw new HttpError(500, 'Die Installation ist fehlgeschlagen; die bisherige Version wurde wiederhergestellt (Details im Server-Log).');
        }

        // 4. Aufräumen
        if (function_exists('opcache_reset')) opcache_reset();
        @unlink($flag);
        self::removeTree($stage);
        @unlink($zipFile);
        error_log("[Avunculus] Update von $previous auf $version installiert");
        return ['installed' => $version, 'previous' => $previous];
    }

    /** Wartungsmodus während des Austauschs (älter als 10 Minuten = liegengeblieben, ignorieren) */
    public static function inMaintenance(): bool
    {
        $flag = AppInfo::dataDir() . '/maintenance.flag';
        return is_file($flag) && filemtime($flag) > time() - 600;
    }

    // ── Hilfsfunktionen ──────────────────────────────────────────────────────────

    /** Entpackt nur Einträge unter $prefix/ und weist Pfade mit „..“ ab; liefert den Ordner $stage/$prefix */
    private static function extract(string $zipFile, string $stage, string $prefix): string
    {
        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) throw new HttpError(502, 'Das Paket lässt sich nicht öffnen.');
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (!str_starts_with($name, "$prefix/") || str_contains($name, '\\') || str_contains($name, "\0")
                    || in_array('..', explode('/', $name), true)) {
                    throw new HttpError(502, 'Das Paket enthält unerwartete Pfade.');
                }
            }
            if (!$zip->extractTo($stage)) throw new RuntimeException('Entpacken fehlgeschlagen');
        } finally {
            $zip->close();
        }
        return "$stage/$prefix";
    }

    /** @return list<string> alle Dateien unter $dir, relativ */
    private static function files(string $dir): array
    {
        $out = [];
        $it  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) if ($f->isFile()) $out[] = substr($f->getPathname(), strlen($dir) + 1);
        sort($out);
        return $out;
    }

    private static function move(string $from, string $to): void
    {
        if (!rename($from, $to)) throw new RuntimeException("$from kann nicht nach $to verschoben werden");
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) return;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                                            RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        @rmdir($dir);
    }

    /** GET über curl oder (falls nicht vorhanden) file_get_contents; folgt Weiterleitungen (GitHub-Downloads) */
    private static function http(string $url): string
    {
        if (!preg_match('#^https?://#', $url)) throw new RuntimeException('Ungültige Adresse');
        $agent = 'Avunculus/' . AppInfo::version();
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 120, CURLOPT_FAILONERROR => true,
                CURLOPT_USERAGENT => $agent, CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json, */*'],
            ]);
            $body = curl_exec($ch);
            if ($body === false) throw new RuntimeException('Abruf fehlgeschlagen: ' . curl_error($ch));
            return (string) $body;
        }
        $ctx  = stream_context_create(['http' => ['timeout' => 120, 'user_agent' => $agent, 'follow_location' => 1,
                                                  'header' => "Accept: application/vnd.github+json, */*\r\n"]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) throw new RuntimeException("Abruf fehlgeschlagen: $url");
        return $body;
    }
}
