<?php

declare(strict_types=1);

/** Anmelden, Abmelden, eigenes Passwort; Einrichtung des ersten Administrators */
class AuthController extends BaseController
{
    public const MIN_PASSWORD = 8;

    // GET /api/auth/me  –  { user: {…} | null, setup: true, wenn es noch keinen Benutzer gibt,
    //                      legal: { imprint_url?, privacy_url? } für die Fußzeile (auch für Besucher) }
    public function me(): void
    {
        $this->json(['user' => Auth::user(), 'setup' => $this->needsSetup(), 'legal' => (object) Settings::legalLinks($this->db)]
                    + (Auth::user() ? ['version' => AppInfo::version()] : []));
    }

    // POST /api/auth/login  –  body: { username, password }
    public function login(): void
    {
        $data     = $this->body();
        $username = trim((string) ($data['username'] ?? ''));

        // Zu viele Fehlversuche von dieser IP bzw. für dieses Konto → vorerst gesperrt
        $throttle = new LoginThrottle($this->db, $username, LoginThrottle::clientIp());
        if ($wait = $throttle->begin()) {
            header('Retry-After: ' . $wait);
            $this->json(['error' => 'Zu viele Fehlversuche – bitte später erneut versuchen',
                         'code' => 'too_many_attempts', 'retry_after' => $wait], 429);
            return;
        }

        $stmt = $this->db->prepare('SELECT * FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify((string) ($data['password'] ?? ''), $user['password_hash'])) {
            $throttle->failed();
            usleep(random_int(400_000, 800_000));   // Durchprobieren zusätzlich bremsen
            $this->json(['error' => 'Benutzername oder Passwort falsch'], 401);
            return;
        }
        $throttle->succeeded();
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $this->db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash((string) $data['password'], PASSWORD_DEFAULT), $user['id']]);
        }
        Auth::login($this->db, $user);
        $this->json(['user' => Auth::user(), 'setup' => false]);
    }

    // POST /api/auth/logout
    public function logout(): void
    {
        Auth::logout();
        $this->json(['user' => null, 'setup' => $this->needsSetup()]);
    }

    // POST /api/auth/setup  –  body: { username, display_name?, password }  –  nur solange es keinen Benutzer gibt
    public function setup(): void
    {
        if (!$this->needsSetup()) {
            $this->json(['error' => 'Die Einrichtung ist bereits abgeschlossen'], 409); return;
        }
        $data   = $this->body();
        $errors = UserController::validate($data, true);
        if ($errors) { $this->json(['errors' => $errors], 422); return; }

        $this->db->prepare('INSERT INTO users (username, display_name, password_hash, role) VALUES (?, ?, ?, ?)')
            ->execute([trim($data['username']), UserController::displayName($data),
                       password_hash((string) $data['password'], PASSWORD_DEFAULT), 'admin']);
        $id   = (int) $this->db->lastInsertId();
        $stmt = $this->db->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        Auth::login($this->db, $stmt->fetch());
        $this->json(['user' => Auth::user(), 'setup' => false], 201);
    }

    // PUT /api/auth/password  –  body: { current, password }  –  nur Angemeldete (siehe routes.php)
    public function changePassword(): void
    {
        $data = $this->body();
        $stmt = $this->db->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([Auth::user()['id']]);
        if (!password_verify((string) ($data['current'] ?? ''), (string) $stmt->fetchColumn())) {
            usleep(random_int(400_000, 800_000));
            $this->json(['errors' => ['current' => 'Das bisherige Passwort stimmt nicht']], 422); return;
        }
        if (mb_strlen((string) ($data['password'] ?? '')) < self::MIN_PASSWORD) {
            $this->json(['errors' => ['password' => 'Mindestens ' . self::MIN_PASSWORD . ' Zeichen']], 422); return;
        }
        $this->db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash((string) $data['password'], PASSWORD_DEFAULT), Auth::user()['id']]);
        http_response_code(204);
    }

    private function needsSetup(): bool
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0;
    }
}
