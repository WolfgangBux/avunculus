<?php

declare(strict_types=1);

/**
 * Datenbank automatisch auf den Stand des Codes bringen.
 *
 * Jede Änderung am Schema ist eine Datei backend/migrations/NNNN_name.php, die eine Funktion
 * function (PDO $db): void zurückgibt. Die Datenbank merkt sich in settings.schema_version die Nummer
 * der letzten ausgeführten Migration; index.php ruft run() bei jeder Anfrage auf (eine kleine Abfrage),
 * fehlende Migrationen laufen also beim ersten Aufruf nach einem Update.
 *
 * MariaDB führt CREATE/ALTER nicht in Transaktionen aus. Migrationen deshalb so schreiben, dass sie
 * nach einem Abbruch erneut laufen dürfen (CREATE TABLE IF NOT EXISTS, ADD COLUMN IF NOT EXISTS …).
 * Nach jeder Migration wird der Stand gespeichert.
 *
 * Eine neue Installation (database/schema.sql) bekommt gleich die höchste Nummer eingetragen –
 * schema.sql und Migrationen müssen also zusammenpassen (scripts/check-schema-version.php prüft das).
 */
final class Migrations
{
    private const LOCK = 'avunculus_migrate';

    /** @return array<int, string> Nummer → Datei, aufsteigend */
    public static function files(): array
    {
        $out = [];
        foreach (glob(__DIR__ . '/../migrations/[0-9][0-9][0-9][0-9]_*.php') ?: [] as $f) {
            $out[(int) substr(basename($f), 0, 4)] = $f;
        }
        ksort($out);
        return $out;
    }

    public static function latest(): int
    {
        $files = self::files();
        return $files ? (int) array_key_last($files) : 0;
    }

    /** Stand der Datenbank; legt die Tabelle settings an, falls sie fehlt (Installationen von vor 1.0) */
    public static function current(PDO $db): int
    {
        try {
            return (int) (Settings::get($db, 'schema_version') ?? 0);
        } catch (PDOException $e) {
            if ($e->getCode() !== '42S02') throw $e;   // 42S02 = Tabelle gibt es nicht
            $db->exec('CREATE TABLE IF NOT EXISTS settings (
                name  VARCHAR(64) NOT NULL PRIMARY KEY,
                value TEXT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            return 0;
        }
    }

    /** Fehlende Migrationen ausführen (nichts zu tun: eine Abfrage) */
    public static function run(PDO $db): void
    {
        $latest = self::latest();
        if (self::current($db) >= $latest) return;

        // Nur eine Anfrage migriert; die anderen warten kurz bzw. bekommen „bitte gleich nochmal“
        if ((int) $db->query("SELECT GET_LOCK('" . self::LOCK . "', 30)")->fetchColumn() !== 1) {
            throw new HttpError(503, 'Die Datenbank wird gerade aktualisiert – bitte in einem Moment neu laden.');
        }
        try {
            $current = self::current($db);   // inzwischen von einer anderen Anfrage erledigt?
            foreach (self::files() as $n => $file) {
                if ($n <= $current) continue;
                $migrate = require $file;
                $migrate($db);
                Settings::set($db, 'schema_version', (string) $n);
                error_log(sprintf('[Avunculus] Datenbank-Migration %04d ausgeführt (%s)', $n, basename($file)));
            }
        } catch (\Throwable $e) {
            error_log('[Avunculus] Datenbank-Migration fehlgeschlagen: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            throw new HttpError(500, 'Die Datenbank konnte nicht aktualisiert werden (Details im Server-Log).');
        } finally {
            $db->query("SELECT RELEASE_LOCK('" . self::LOCK . "')");
        }
    }
}
