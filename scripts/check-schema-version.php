<?php

// CI check: database/schema.sql must set schema_version to the number of the newest migration,
// otherwise a new installation would run migrations it already has (or miss newer ones).
declare(strict_types=1);

$root    = dirname(__DIR__);
$files   = glob("$root/backend/migrations/[0-9][0-9][0-9][0-9]_*.php") ?: [];
$latest  = $files ? max(array_map(fn($f) => (int) substr(basename($f), 0, 4), $files)) : 0;
$schema  = (string) file_get_contents("$root/database/schema.sql");
$inSchema = preg_match("/INSERT INTO settings \\(name, value\\) VALUES \\('schema_version', '(\\d+)'\\)/", $schema, $m) ? (int) $m[1] : -1;

if ($inSchema !== $latest) {
    fwrite(STDERR, "database/schema.sql sets schema_version $inSchema, but the newest migration is $latest.\n");
    exit(1);
}
echo "schema_version $latest matches the newest migration\n";
