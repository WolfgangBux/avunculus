<?php

declare(strict_types=1);

class Database
{
    private static ?PDO $connection = null;

    /** Einstellungen, die aus der Umgebung kommen dürfen (Vorrang vor backend/.env) */
    private const ENV_KEYS = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'MEDIA_DIR', 'UPDATE_CHECK', 'USAGE_STATS', 'UPDATE_API', 'STATS_API'];

    public static function getConnection(): PDO
    {
        if (self::$connection === null) {
            // Echte Umgebungsvariablen zuerst: PHP füllt $_ENV nur, wenn variables_order ein „E“ enthält
            // (in der üblichen php.ini nicht) – getenv() findet sie immer, unter Apache auch SetEnv.
            foreach (self::ENV_KEYS as $key) {
                $value = getenv($key);
                if ($value !== false) $_ENV[$key] ??= $value;
            }
            // backend/.env ergänzt nur, was nicht schon gesetzt ist
            $envFile = __DIR__ . '/../.env';
            if (file_exists($envFile)) {
                foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                    if (!str_starts_with(trim($line), '#') && str_contains($line, '=')) {
                        [$key, $value] = explode('=', $line, 2);
                        $_ENV[trim($key)] ??= trim($value);
                    }
                }
            }

            $host = $_ENV['DB_HOST'] ?? 'localhost';
            $port = $_ENV['DB_PORT'] ?? '3306';
            $name = $_ENV['DB_NAME'] ?? 'avunculus';
            $user = $_ENV['DB_USER'] ?? 'root';
            $pass = $_ENV['DB_PASS'] ?? '';

            self::$connection = new PDO(
                "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4",
                $user,
                $pass,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        }

        return self::$connection;
    }
}
