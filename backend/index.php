<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/lib/HttpError.php';
require_once __DIR__ . '/lib/AppInfo.php';
require_once __DIR__ . '/lib/Settings.php';
require_once __DIR__ . '/lib/Migrations.php';
require_once __DIR__ . '/lib/Updater.php';
require_once __DIR__ . '/lib/Router.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/LoginThrottle.php';
require_once __DIR__ . '/lib/Privacy.php';
require_once __DIR__ . '/lib/GedcomDate.php';
require_once __DIR__ . '/lib/GedcomTags.php';
require_once __DIR__ . '/lib/PersonQuery.php';
require_once __DIR__ . '/lib/PlaceIndex.php';
require_once __DIR__ . '/lib/MediaStore.php';
require_once __DIR__ . '/lib/MediaLinks.php';
require_once __DIR__ . '/lib/Relatives.php';
require_once __DIR__ . '/lib/RowSync.php';
require_once __DIR__ . '/lib/ChangeDiff.php';
require_once __DIR__ . '/lib/ChangeLog.php';
require_once __DIR__ . '/lib/Plausibility.php';
require_once __DIR__ . '/lib/Kinship.php';
require_once __DIR__ . '/api/BaseController.php';
require_once __DIR__ . '/api/AuthController.php';
require_once __DIR__ . '/api/UserController.php';
require_once __DIR__ . '/api/TreeController.php';
require_once __DIR__ . '/api/PersonController.php';
require_once __DIR__ . '/api/FamilyController.php';
require_once __DIR__ . '/api/SourceController.php';
require_once __DIR__ . '/api/RepositoryController.php';
require_once __DIR__ . '/api/PlaceController.php';
require_once __DIR__ . '/api/MediaController.php';
require_once __DIR__ . '/api/StatisticsController.php';
require_once __DIR__ . '/api/SurnameController.php';
require_once __DIR__ . '/api/ReportController.php';
require_once __DIR__ . '/api/ChangeController.php';
require_once __DIR__ . '/api/CheckController.php';
require_once __DIR__ . '/api/RelationshipController.php';
require_once __DIR__ . '/api/SystemController.php';

$method = $_SERVER['REQUEST_METHOD'];
// router.php hat den Pfad schon ohne Unterordner und ohne /api ermittelt
$path   = defined('AVUNCULUS_API_PATH') ? AVUNCULUS_API_PATH
        : preg_replace('#^/api#', '', (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

/**
 * Stammbaum der Anfrage aus dem Header X-Tree-Id (Bild-/Dateiabrufe per <img src> können keinen
 * Header setzen → ?tree=<id>). Nicht freigegebene Bäume gibt es für Besucher nicht.
 */
function requestTree(PDO $db, string $method): int
{
    $header = $_SERVER['HTTP_X_TREE_ID'] ?? ($method === 'GET' ? (string) ($_GET['tree'] ?? '') : '');
    $treeId = ctype_digit($header) ? (int) $header : 0;
    $stmt   = $db->prepare('SELECT is_public FROM trees WHERE id = ?');
    $stmt->execute([$treeId]);
    $tree   = $stmt->fetch();
    if (!$tree || (Auth::isGuest() && !(int) $tree['is_public'])) {
        throw new HttpError(400, 'Kein gültiger Stammbaum gewählt (Header X-Tree-Id)');
    }
    return $treeId;
}

try {
    // Während ein Update Dateien austauscht, kurz nichts beantworten
    if (Updater::inMaintenance()) {
        header('Retry-After: 10');
        throw new HttpError(503, 'Avunculus wird gerade aktualisiert – bitte in einem Moment neu laden.');
    }
    $db = Database::getConnection();
    Migrations::run($db);   // Datenbank auf den Stand des Codes bringen (meist nur eine Abfrage)
    Auth::init($db);

    // Schreibende Anfragen nur von der eigenen Seite
    if ($method !== 'GET' && !Auth::checkOrigin()) throw new HttpError(403, 'Anfrage von fremder Seite abgelehnt');

    $route = (new Router(require __DIR__ . '/routes.php'))->match($method, $path)
        ?? throw new HttpError(404, 'Endpunkt nicht gefunden');
    ['class' => $class, 'action' => $action, 'role' => $role, 'args' => $args] = $route;

    if (is_subclass_of($class, TreeScopedController::class)) {
        $treeId = requestTree($db, $method);
        Auth::require($role);
        if (Auth::isGuest()) Privacy::enable($db, $treeId);
        ChangeLog::run(ChangeLog::begin($db, $method, $path, $route, $treeId), fn() => (new $class($db, $treeId))->$action(...$args));
        // Jede Änderung kann Lebende/Verstorbene verschieben → Datenschutz neu berechnen lassen
        if ($method !== 'GET' && http_response_code() < 400) Privacy::markStale($db, $treeId);
    } else {
        Auth::require($role);
        ChangeLog::run(ChangeLog::begin($db, $method, $path, $route, null), fn() => (new $class($db))->$action(...$args));
    }
} catch (HttpError $e) {
    if (!headers_sent()) {
        http_response_code($e->status);
        header('Content-Type: application/json; charset=utf-8');   // falls ein Download schon einen anderen Typ gesetzt hat
    }
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    // Details (SQL, Pfade, Tabellennamen) nur ins Server-Log, nie an den Browser
    $ref = bin2hex(random_bytes(4));
    error_log(sprintf('[Avunculus %s] %s %s – %s: %s in %s:%d', $ref, $method, $_SERVER['REQUEST_URI'] ?? '',
        $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    $msg = $e instanceof PDOException ? 'Datenbankfehler' : 'Serverfehler';
    echo json_encode(['error' => "$msg (Referenz $ref, Details im Server-Log)"], JSON_UNESCAPED_UNICODE);
}
