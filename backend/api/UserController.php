<?php

declare(strict_types=1);

/** Benutzerverwaltung (nur Admin) */
class UserController extends BaseController
{
    // GET /api/users
    public function index(): void
    {
        $this->json(array_map([self::class, 'present'],
            $this->db->query('SELECT * FROM users ORDER BY username')->fetchAll()));
    }

    // POST /api/users  –  body: { username, display_name?, password, role }
    public function store(): void
    {
        $data   = $this->body();
        $errors = self::validate($data, true);
        if (!$errors && $this->usernameTaken(trim($data['username']))) $errors['username'] = 'Benutzername ist schon vergeben';
        if ($errors) { $this->json(['errors' => $errors], 422); return; }

        $this->db->prepare('INSERT INTO users (username, display_name, password_hash, role) VALUES (?, ?, ?, ?)')
            ->execute([trim($data['username']), self::displayName($data),
                       password_hash((string) $data['password'], PASSWORD_DEFAULT), self::role($data)]);
        $this->json($this->find((int) $this->db->lastInsertId()), 201);
    }

    // PUT /api/users/{id}  –  body: { username, display_name?, role, password? }  (ohne password bleibt es)
    public function update(int $id): void
    {
        $user   = $this->find($id);
        $data   = $this->body();
        $errors = self::validate($data, ($data['password'] ?? '') !== '');
        if (!$errors && $this->usernameTaken(trim($data['username']), $id)) $errors['username'] = 'Benutzername ist schon vergeben';
        $role = self::role($data);
        if ($user['role'] === 'admin' && $role !== 'admin' && $this->adminCount() <= 1) {
            $errors['role'] = 'Der letzte Administrator kann nicht herabgestuft werden';
        }
        if ($errors) { $this->json(['errors' => $errors], 422); return; }

        $this->db->prepare('UPDATE users SET username = ?, display_name = ?, role = ? WHERE id = ?')
            ->execute([trim($data['username']), self::displayName($data), $role, $id]);
        if (($data['password'] ?? '') !== '') {
            $this->db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash((string) $data['password'], PASSWORD_DEFAULT), $id]);
        }
        $this->json($this->find($id));
    }

    // DELETE /api/users/{id}
    public function destroy(int $id): void
    {
        $user = $this->find($id);
        if ($id === Auth::user()['id']) {
            $this->json(['error' => 'Das eigene Konto kann nicht gelöscht werden'], 422); return;
        }
        if ($user['role'] === 'admin' && $this->adminCount() <= 1) {
            $this->json(['error' => 'Der letzte Administrator kann nicht gelöscht werden'], 422); return;
        }
        $this->db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        http_response_code(204);
    }

    // -------------------------------------------------------------------------

    /** Prüft Benutzername (und Passwort, wenn $withPassword) */
    public static function validate(array $data, bool $withPassword): array
    {
        $errors   = [];
        $username = trim((string) ($data['username'] ?? ''));
        if (!preg_match('/^[\p{L}\p{N}._@-]{2,60}$/u', $username)) {
            $errors['username'] = '2–60 Zeichen: Buchstaben, Ziffern, . _ - @';
        }
        if ($withPassword && mb_strlen((string) ($data['password'] ?? '')) < AuthController::MIN_PASSWORD) {
            $errors['password'] = 'Mindestens ' . AuthController::MIN_PASSWORD . ' Zeichen';
        }
        return $errors;
    }

    public static function displayName(array $data): ?string
    {
        $v = trim((string) ($data['display_name'] ?? ''));
        return $v !== '' ? mb_substr($v, 0, 120) : null;
    }

    private static function role(array $data): string
    {
        return in_array($data['role'] ?? '', Auth::ROLES, true) ? $data['role'] : 'editor';
    }

    private static function present(array $u): array
    {
        return [
            'id'           => (int) $u['id'],
            'username'     => $u['username'],
            'display_name' => $u['display_name'],
            'role'         => $u['role'],
            'last_login'   => $u['last_login'],
            'created_at'   => $u['created_at'],
        ];
    }

    private function find(int $id): array
    {
        return self::present($this->rowOrFail('SELECT * FROM users WHERE id = ?', [$id], 'Benutzer nicht gefunden'));
    }

    private function usernameTaken(string $username, int $exceptId = 0): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM users WHERE username = ? AND id <> ?');
        $stmt->execute([$username, $exceptId]);
        return (bool) $stmt->fetch();
    }

    private function adminCount(): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
    }
}
