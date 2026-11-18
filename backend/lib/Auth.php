<?php

declare(strict_types=1);

/**
 * Anmeldung über PHP-Sessions.
 *
 * Rollen:   admin  – alles, auch Benutzer und Stammbäume verwalten (anlegen, importieren, löschen, freigeben)
 *           editor – Daten in allen Stammbäumen bearbeiten, exportieren
 *           (keine Anmeldung) – Besucher: nur freigegebene Bäume, nur lesend, Lebende anonymisiert
 *
 * Die Session wird nur gelesen und sofort wieder freigegeben (read_and_close), damit lange
 * Anfragen (Import, Export) parallele Anfragen nicht blockieren.
 */
final class Auth
{
    public const ROLES = ['admin', 'editor'];
    private const RANK = ['guest' => 0, 'editor' => 1, 'admin' => 2];
    private const SESSION_NAME = 'avunculus_sid';

    private static ?array $user = null;

    /** Session lesen und den Benutzer aus der Datenbank laden (Rolle kann sich geändert haben) */
    public static function init(PDO $db): void
    {
        self::configure();
        if (!isset($_COOKIE[self::SESSION_NAME])) return;   // Besucher bekommen keine Session
        session_start(['read_and_close' => true]);
        $uid = (int) ($_SESSION['uid'] ?? 0);
        if ($uid <= 0) return;
        $stmt = $db->prepare('SELECT id, username, display_name, role FROM users WHERE id = ?');
        $stmt->execute([$uid]);
        self::$user = $stmt->fetch() ?: null;
        if (self::$user) self::$user['id'] = (int) self::$user['id'];
    }

    public static function user(): ?array
    {
        return self::$user;
    }

    public static function role(): string
    {
        return self::$user['role'] ?? 'guest';
    }

    public static function isGuest(): bool
    {
        return self::$user === null;
    }

    /** Mindestens diese Rolle? (guest < editor < admin) */
    public static function can(string $role): bool
    {
        return self::RANK[self::role()] >= self::RANK[$role];
    }

    /** Bricht mit 401 (nicht angemeldet) bzw. 403 (Rolle reicht nicht) ab */
    public static function require(string $role): void
    {
        if (self::can($role)) return;
        throw self::isGuest() ? new HttpError(401, 'Bitte anmelden') : new HttpError(403, 'Keine Berechtigung');
    }

    public static function login(PDO $db, array $user): void
    {
        self::configure();
        session_start();
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $user['id'];
        session_write_close();
        $db->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([(int) $user['id']]);
        self::$user = ['id' => (int) $user['id'], 'username' => $user['username'],
                       'display_name' => $user['display_name'], 'role' => $user['role']];
    }

    public static function logout(): void
    {
        self::configure();
        if (isset($_COOKIE[self::SESSION_NAME])) {
            session_start();
            $_SESSION = [];
            session_destroy();
        }
        setcookie(self::SESSION_NAME, '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
        self::$user = null;
    }

    /**
     * Schreibende Anfragen nur von der eigenen Seite (zusätzlich zu SameSite=Lax):
     * Browser schicken bei POST/PUT/DELETE einen Origin-Header mit.
     */
    public static function checkOrigin(): bool
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin === '' || $origin === 'null') return $origin === '';
        $host = parse_url($origin, PHP_URL_HOST) . (($p = parse_url($origin, PHP_URL_PORT)) ? ":$p" : '');
        return strcasecmp($host, (string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? '')) === 0;
    }

    private static function configure(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        session_name(self::SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => (defined('AVUNCULUS_BASE') ? AVUNCULUS_BASE : '') . '/',   // nur für diese Installation
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_maxlifetime', (string) (14 * 86400));
    }
}
