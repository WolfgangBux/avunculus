<?php

declare(strict_types=1);

/** Einstellungen der Installation (Tabelle settings: name → value), z. B. Stand der Datenbank */
final class Settings
{
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
