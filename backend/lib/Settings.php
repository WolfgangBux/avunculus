<?php

declare(strict_types=1);

/** Einstellungen der Installation (Tabelle settings: name → value), z. B. Stand der Datenbank */
final class Settings
{
    /** Einstellungen, die der Admin in der Oberfläche ändern darf (leer erlaubt) */
    public const EDITABLE = ['site_name', 'imprint_url', 'privacy_url'];
    /** davon Webadressen (werden als Link ausgegeben und darum streng geprüft) */
    public const URLS = ['imprint_url', 'privacy_url'];
    /** Höchstlänge des Seitennamens (Kopfzeile, Titel im Browser) */
    public const SITE_NAME_MAX = 80;

    /** Links auf Impressum und Datenschutzerklärung des Betreibers – nur die eingetragenen */
    public static function legalLinks(PDO $db): array
    {
        $links = [];
        foreach (self::URLS as $name) {
            if ($v = self::get($db, $name)) $links[$name] = $v;
        }
        return $links;
    }

    public static function get(PDO $db, string $name): ?string
    {
        $stmt = $db->prepare('SELECT value FROM settings WHERE name = ?');
        $stmt->execute([$name]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    public static function set(PDO $db, string $name, ?string $value): void
    {
        $db->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)')
           ->execute([$name, $value]);
    }
}
