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

    /** Arbeitsordner für Updates und Wartungsmodus (unter data/, nie direkt abrufbar) */
    public static function dataDir(): string
    {
        return self::root() . '/data';
    }
}
