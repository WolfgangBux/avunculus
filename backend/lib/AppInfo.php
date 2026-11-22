<?php

declare(strict_types=1);

/** Version und Ablageorte der Installation */
final class AppInfo
{
    /** GitHub-Repository, aus dem Updates kommen */
    public const REPO = 'WolfgangBux/avunculus';

    /** Hauptordner der Installation (enthält router.php, backend/, public/, data/) */
    public static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** Version aus der Datei VERSION, z. B. „1.0.0“ */
    public static function version(): string
    {
        static $v = null;
        return $v ??= trim((string) @file_get_contents(self::root() . '/VERSION')) ?: '0.0.0';
    }

    /**
     * Öffentliche Adresse dieser Installation aus der laufenden Anfrage, z. B. „https://example.com/ahnen/“.
     * null ohne Webanfrage und für Adressen, die nur lokal gelten (localhost, IP-Adressen, .local, .lan, fritz.box …).
     */
    public static function siteUrl(): ?string
    {
        $host = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? ''))[0]));
        if (!preg_match('/^[a-z0-9.-]+(:\d{1,5})?$/', $host)) return null;
        $name = (string) preg_replace('/:\d+$/', '', $host);
        if (filter_var($name, FILTER_VALIDATE_IP) || !str_contains($name, '.')
            || preg_match('/(^|\.)(local|lan|home|home\.arpa|internal|intranet|corp|private|localhost|test|example|invalid|fritz\.box)$/', $name)) return null;
        $https = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
              || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        $base  = defined('AVUNCULUS_BASE') ? (string) AVUNCULUS_BASE : '';
        return ($https ? 'https' : 'http') . '://' . $host . $base . '/';
    }

    /** Arbeitsordner für Updates und Wartungsmodus (unter data/, nie direkt abrufbar) */
    public static function dataDir(): string
    {
        return self::root() . '/data';
    }
}
