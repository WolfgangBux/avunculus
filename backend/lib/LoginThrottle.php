<?php

declare(strict_types=1);

/**
 * Bremse gegen Passwort-Durchprobieren: Fehlversuche werden in login_failures gezählt
 * (gleitendes Fenster von WINDOW Sekunden).
 *
 *   * je IP-Adresse höchstens MAX_PER_IP Fehlversuche, dann Sperre
 *   * je Benutzername höchstens MAX_PER_USER Fehlversuche (von allen IPs zusammen) – bewusst
 *     höher, damit niemand ein Konto mit ein paar falschen Passwörtern gezielt aussperren kann
 *
 * Jeder Versuch wird vor der Passwortprüfung eingetragen und bei Erfolg wieder entfernt.
 * So zählen auch gleichzeitig abgeschickte Versuche sofort mit. Gesperrte Versuche zählen
 * nicht, damit sich die Sperre durch weiteres Probieren nicht immer weiter verlängert.
 */
final class LoginThrottle
{
    public const WINDOW       = 900;   // 15 Minuten
    public const MAX_PER_IP   = 10;
    public const MAX_PER_USER = 50;

    private ?int $attemptId = null;

    public function __construct(private readonly PDO $db, private readonly string $username, private readonly string $ip) {}

    /**
     * Versuch eintragen. Liefert die Wartezeit in Sekunden, falls gesperrt (der Versuch wird
     * dann nicht gezählt), sonst null.
     */
    public function begin(): ?int
    {
        $this->db->prepare('INSERT INTO login_failures (username, ip) VALUES (?, ?)')
            ->execute([mb_substr($this->username, 0, 60), $this->ip]);
        $this->attemptId = (int) $this->db->lastInsertId();

        $wait = max($this->waitFor('ip', $this->ip, self::MAX_PER_IP) ?? 0,
                    $this->waitFor('username', mb_substr($this->username, 0, 60), self::MAX_PER_USER) ?? 0);
        if ($wait > 0) {
            $this->forget();
            return $wait;
        }
        return null;
    }

    /** Anmeldung geglückt: dieser Versuch und frühere Fehlversuche von hier für dieses Konto zählen nicht mehr */
    public function succeeded(): void
    {
        $this->db->prepare('DELETE FROM login_failures WHERE username = ? AND ip = ?')
            ->execute([mb_substr($this->username, 0, 60), $this->ip]);
        $this->attemptId = null;
    }

    /** Fehlversuch: Eintrag bleibt stehen; alte Einträge bei der Gelegenheit aufräumen */
    public function failed(): void
    {
        $this->attemptId = null;
        $this->db->exec('DELETE FROM login_failures WHERE created_at < NOW() - INTERVAL 1 DAY');
    }

    /**
     * Gesperrt, wenn es im Fenster mehr als $max Einträge gibt (der eigene, eben eingetragene
     * Versuch zählt mit). Die Sperre endet, wenn der ($max+1)-jüngste Eintrag aus dem Fenster fällt.
     */
    private function waitFor(string $column, string $value, int $max): ?int
    {
        $stmt = $this->db->prepare("
            SELECT ? - TIMESTAMPDIFF(SECOND, created_at, NOW())
            FROM login_failures
            WHERE $column = ? AND created_at > NOW() - INTERVAL ? SECOND
            ORDER BY created_at DESC, id DESC
            LIMIT 1 OFFSET $max");
        $stmt->execute([self::WINDOW, $value, self::WINDOW]);
        $wait = $stmt->fetchColumn();
        return $wait === false ? null : max(1, (int) $wait);
    }

    private function forget(): void
    {
        if ($this->attemptId === null) return;
        $this->db->prepare('DELETE FROM login_failures WHERE id = ?')->execute([$this->attemptId]);
        $this->attemptId = null;
    }

    /**
     * IP-Adresse des Aufrufers. Hinter einem Proxy im eigenen Netz (Caddy im Docker-Netz,
     * Vite-Dev-Proxy auf localhost) zählt der letzte Eintrag in X-Forwarded-For – den hat der
     * Proxy selbst angehängt. Von außen gesetzte Header werden so nicht übernommen.
     */
    public static function clientIp(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $isPublic = filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        if (!$isPublic && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $hops = array_map('trim', explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']));
            $last = end($hops);
            if (filter_var($last, FILTER_VALIDATE_IP)) return $last;
        }
        return $remote !== '' ? $remote : 'unknown';
    }
}
