<?php

declare(strict_types=1);

/** Einstellungen der Installation (Tabelle settings: name → value), z. B. Stand der Datenbank */
final class Settings
{
    /** Einstellungen, die der Admin in der Oberfläche ändern darf (Name → leer erlaubt) */
    public const EDITABLE = ['imprint_url', 'privacy_url'];

    /** Links auf Impressum und Datenschutzerklärung des Betreibers – nur die eingetragenen */
    public static function legalLinks(PDO $db): array
    {
        $links = [];
        foreach (['imprint_url', 'privacy_url'] as $name) {
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
