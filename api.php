<?php
/**
 * JSON API: api.php?a=group.action  (POST JSON or multipart, GET for reads)
 * Handlers live in app/api/<group>.php and return
 *   ['action' => ['perm' => string|array|null, 'fn' => callable(array $in): mixed, 'public' => bool]]
 */
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

$a = (string)($_GET['a'] ?? '');
if (!preg_match('/^([a-z_]+)\.([a-z_]+)$/', $a, $m)) json_response(['ok' => false, 'error' => 'Unknown action'], 404);
[$_, $group, $action] = $m;

$file = MF_ROOT . "/app/api/$group.php";
if (!is_file($file)) json_response(['ok' => false, 'error' => 'Unknown action'], 404);
$defs = require $file;
$def = $defs[$action] ?? null;
if (!$def) json_response(['ok' => false, 'error' => 'Unknown action'], 404);

// Input: JSON body, form fields, or query string
$in = $_GET;
unset($in['a']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($ct, 'application/json')) {
        $body = json_decode((string)file_get_contents('php://input'), true);
        if (is_array($body)) $in = array_merge($in, $body);
    } else {
        $in = array_merge($in, $_POST);
    }
}

if (empty($def['public'])) {
    if (!Auth::check()) json_response(['ok' => false, 'error' => 'Session expired. Please sign in again.', 'auth' => false], 401);
    if ($_SERVER['REQUEST_METHOD'] !== 'GET' && !csrf_valid($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($in['_csrf'] ?? null))) {
        json_response(['ok' => false, 'error' => 'Security token expired. Refresh the page.'], 419);
    }
    $perm = $def['perm'] ?? null;
    $allowed = $perm === null || (is_array($perm) ? Auth::canAny($perm) : Auth::can($perm));
    if (!$allowed) json_response(['ok' => false, 'error' => 'You do not have permission for this action.'], 403);
    if (!empty($def['write']) && $_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['ok' => false, 'error' => 'POST required'], 405);
}

try {
    $data = ($def['fn'])($in);
    json_response(['ok' => true, 'data' => $data]);
} catch (ApiError $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()], $e->getCode() ?: 422);
} catch (Throwable $e) {
    error_log("[api $a] " . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_response(['ok' => false, 'error' => Env::bool('APP_DEBUG') ? $e->getMessage() : 'Something went wrong. Please try again.'], 500);
}
