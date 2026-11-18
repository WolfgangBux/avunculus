<?php

declare(strict_types=1);

$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Liegt Avunculus in einem Unterordner (example.com/ahnen/)? Apache ruft uns dann als /ahnen/router.php auf.
// Beim eingebauten Server (php -S … router.php) und im Hauptordner bleibt das leer.
$script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
$base   = str_ends_with($script, '/router.php') ? rtrim(str_replace('\\', '/', dirname($script)), '/') : '';
define('AVUNCULUS_BASE', $base);
if ($base !== '') {
    // ohne Schrägstrich am Ende würden die relativen Pfade der Oberfläche ins Leere zeigen
    if ($path === $base) {
        $query = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
        header('Location: ' . $base . '/' . ($query ? "?$query" : ''), true, 301);
        return;
    }
    if (str_starts_with($path, "$base/")) $path = substr($path, strlen($base));
}

// API-Anfragen an das Backend weiterleiten (Pfad ohne /api, z. B. /persons/12)
if (str_starts_with($path, '/api/')) {
    define('AVUNCULUS_API_PATH', substr($path, 4));
    require __DIR__ . '/backend/index.php';
    return;
}

// Vorhandene statische Dateien (JS, CSS, Bilder …) aus public/ ausliefern
$file = __DIR__ . '/public' . $path;
if ($path !== '/' && is_file($file)) {
    $ext  = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $mime = match($ext) {
        'html'        => 'text/html; charset=utf-8',
        'js', 'mjs'   => 'application/javascript; charset=utf-8',
        'css'         => 'text/css; charset=utf-8',
        'json'        => 'application/json; charset=utf-8',
        'txt'         => 'text/plain; charset=utf-8',
        'svg'         => 'image/svg+xml',
        'png'         => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'ico'         => 'image/x-icon',
        'woff'        => 'font/woff',
        'woff2'       => 'font/woff2',
        default       => 'application/octet-stream',
    };
    header('Content-Type: ' . $mime);
    readfile($file);
    return;
}

// SPA-Fallback: index.html für alle anderen Routen
$index = __DIR__ . '/public/index.html';
if (!is_file($index)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Frontend nicht gebaut.\nBitte zuerst ausführen:\n\n  cd frontend && npm install && npm run build\n";
    return;
}

header('Content-Type: text/html; charset=utf-8');
readfile($index);
