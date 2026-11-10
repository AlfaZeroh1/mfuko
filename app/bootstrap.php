<?php
/**
 * Mfuko POS bootstrap — loaded by every entry point (index.php, api.php,
 * print.php, tools/*.php). Sets up env, timezone (PHP + MySQL), DB, session.
 */
declare(strict_types=1);

define('MF_ROOT', dirname(__DIR__));
define('MF_VERSION', '2.0.0');
define('MF_CLI', PHP_SAPI === 'cli');

spl_autoload_register(static function (string $class): void {
    foreach (['core', 'services', 'print'] as $dir) {
        $f = MF_ROOT . "/app/$dir/$class.php";
        if (is_file($f)) { require $f; return; }
    }
});

require MF_ROOT . '/app/core/helpers.php';

Env::load(MF_ROOT . '/config/.env');

if (!Env::get('DB_NAME')) {
    if (MF_CLI) { fwrite(STDERR, "config/.env missing. Run install.php first.\n"); exit(1); }
    header('Location: install.php');
    exit;
}

$debug = Env::bool('APP_DEBUG');
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', MF_ROOT . '/storage/logs/php-error.log');

// Licence switch kept from v1 (SUB_EXPIRED) so hosted clients can be paused.
if (Env::bool('LICENSE_SUSPENDED') && !MF_CLI) {
    http_response_code(402);
    require MF_ROOT . '/app/views/suspended.php';
    exit;
}

// --- Timezone: PHP first from .env, then refined from DB settings --------
date_default_timezone_set(Env::get('APP_TIMEZONE', 'Africa/Nairobi'));
Settings::load();
$tz = Settings::get('timezone', Env::get('APP_TIMEZONE', 'Africa/Nairobi'));
if (in_array($tz, timezone_identifiers_list(), true)) {
    date_default_timezone_set($tz);
}
DB::syncTimezone();

if (!MF_CLI) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('MFUKO');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'secure'   => $secure,
        'samesite' => 'Lax',
    ]);
    session_start();
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
}
