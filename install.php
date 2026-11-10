<?php
/**
 * Mfuko POS web installer. Runs only while config/.env does not exist.
 * Creates the database tables, the owner account and business defaults.
 */
declare(strict_types=1);

define('MF_ROOT', __DIR__);
define('MF_VERSION', '2.0.0');
define('MF_CLI', PHP_SAPI === 'cli');
spl_autoload_register(static function (string $c): void {
    foreach (['core', 'services', 'print'] as $d) { $f = MF_ROOT . "/app/$d/$c.php"; if (is_file($f)) { require $f; return; } }
});
require MF_ROOT . '/app/core/helpers.php';
require MF_ROOT . '/app/Installer.php';

$envFile = MF_ROOT . '/config/.env';
if (is_file($envFile)) { header('Location: index.php'); exit; }
session_start();

$errors = [];
$checks = [
    'PHP 8.1+' => version_compare(PHP_VERSION, '8.1.0', '>='),
    'PDO MySQL' => extension_loaded('pdo_mysql'),
    'cURL (M-Pesa, SMS, AI)' => extension_loaded('curl'),
    'GD (image resizing)' => extension_loaded('gd'),
    'mbstring' => extension_loaded('mbstring'),
    'config/ writable' => is_writable(MF_ROOT . '/config'),
    'uploads/ writable' => is_writable(MF_ROOT . '/uploads'),
    'storage/ writable' => is_writable(MF_ROOT . '/storage'),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = array_map(static fn($v) => is_string($v) ? trim($v) : $v, $_POST);
    if (strlen($in['password'] ?? '') < 8) $errors[] = 'Owner password must be at least 8 characters.';
    if (!preg_match('/^[a-z0-9._-]{3,40}$/i', $in['username'] ?? '')) $errors[] = 'Choose a username of 3+ letters/numbers.';
    if (!in_array($in['timezone'] ?? '', timezone_identifiers_list(), true)) $errors[] = 'Choose a valid timezone.';
    if (!$errors) {
        try {
            date_default_timezone_set($in['timezone']);
            $dsn = "mysql:host={$in['db_host']};port=" . ($in['db_port'] ?: '3306') . ";charset=utf8mb4";
            $pdo = DB::connect($dsn, $in['db_user'], $in['db_pass']);
            $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', $in['db_name']) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $pdo->exec('USE `' . str_replace('`', '', $in['db_name']) . '`');
            if ($pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn()) throw new RuntimeException('That database already contains Mfuko tables. Use an empty database (or delete config/.env only if you are reinstalling on purpose).');
            Installer::schema($pdo);
            $vars = ['APP_ENV' => 'production', 'APP_DEBUG' => '0', 'APP_URL' => rtrim($in['app_url'], '/'), 'APP_TIMEZONE' => $in['timezone'],
                'DB_HOST' => $in['db_host'], 'DB_PORT' => $in['db_port'] ?: '3306', 'DB_NAME' => $in['db_name'], 'DB_USER' => $in['db_user'], 'DB_PASS' => $in['db_pass'], 'LICENSE_SUSPENDED' => '0'];
            Env::load('/nonexistent');
            foreach ($vars as $k => $v) putenv("$k=$v");
            DB::syncTimezone();
            Settings::load();
            Installer::seed([
                'business_name' => $in['business_name'], 'business_phone' => $in['business_phone'], 'business_town' => $in['business_town'],
                'timezone' => $in['timezone'], 'currency' => $in['currency'], 'currency_symbol' => $in['currency_symbol'], 'store_name' => $in['store_name'],
            ], ['first_name' => $in['first_name'], 'last_name' => $in['last_name'], 'username' => $in['username'], 'email' => $in['email'], 'password' => $in['password']]);
            if (!Env::write($envFile, $vars)) throw new RuntimeException('Could not write config/.env — check folder permissions.');
            @chmod($envFile, 0640);
            header('Location: index.php?p=login&installed=1');
            exit;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}
$v = static fn($k, $d = '') => e($_POST[$k] ?? $d);
$guessUrl = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
?><!doctype html>
<html lang="en" data-theme="light"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Install Mfuko POS</title><link rel="icon" href="assets/img/favicon.svg">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Sora:wght@500;600;700&display=swap" rel="stylesheet"><link rel="stylesheet" href="assets/css/mfuko.css"></head>
<body><div class="auth-page"><section class="auth-art"><div class="sun"></div><div class="brand" style="padding:0"><img src="assets/img/logo.svg" style="width:46px;height:46px" alt=""><div><b style="font-size:1.6rem">Mfuko</b><small>POS</small></div></div>
<div><h1>Let’s set up your <em>mfuko</em>.</h1><p style="color:rgba(255,255,255,.8);max-width:40ch">Two minutes: connect the database, name your business, create the owner account.</p></div><div class="small" style="color:rgba(255,255,255,.6)">Mfuko POS v<?= MF_VERSION ?></div></section>
<section class="auth-form" style="align-items:start;overflow:auto"><div class="auth-card" style="width:min(560px,100%)">
<h2>Install Mfuko POS</h2>
<div class="card card-pad mb small"><?php foreach ($checks as $k => $ok): ?><div class="row"><span class="grow"><?= e($k) ?></span><?= $ok ? '<b class="text-green">✓</b>' : '<b class="text-danger">✗</b>' ?></div><?php endforeach; ?></div>
<?php foreach ($errors as $er): ?><div class="alert alert-danger"><?= e($er) ?></div><?php endforeach; ?>
<form method="post" class="form-grid">
  <h3 class="full">Database</h3>
  <div class="field"><label>Host</label><input name="db_host" value="<?= $v('db_host', '127.0.0.1') ?>" required></div>
  <div class="field"><label>Port</label><input name="db_port" value="<?= $v('db_port', '3306') ?>"></div>
  <div class="field"><label>Database name</label><input name="db_name" value="<?= $v('db_name', 'mfuko') ?>" required></div>
  <div class="field"><label>User</label><input name="db_user" value="<?= $v('db_user', 'root') ?>" required></div>
  <div class="field full"><label>Password</label><input type="password" name="db_pass" value="<?= $v('db_pass') ?>"></div>
  <h3 class="full">Business</h3>
  <div class="field"><label>Business name</label><input name="business_name" value="<?= $v('business_name') ?>" required></div>
  <div class="field"><label>First store / branch</label><input name="store_name" value="<?= $v('store_name', 'Main Store') ?>" required></div>
  <div class="field"><label>Phone</label><input name="business_phone" value="<?= $v('business_phone') ?>"></div>
  <div class="field"><label>Town</label><input name="business_town" value="<?= $v('business_town', 'Nairobi') ?>"></div>
  <div class="field"><label>Timezone</label><select name="timezone"><?php foreach (timezone_identifiers_list() as $z): ?><option <?= ($_POST['timezone'] ?? 'Africa/Nairobi') === $z ? 'selected' : '' ?>><?= e($z) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label>Currency code / symbol</label><div class="input-group"><input name="currency" value="<?= $v('currency', 'KES') ?>"><input name="currency_symbol" value="<?= $v('currency_symbol', 'KSh') ?>"></div></div>
  <div class="field full"><label>Public URL of this system</label><input name="app_url" value="<?= $v('app_url', $guessUrl) ?>"></div>
  <h3 class="full">Owner account</h3>
  <div class="field"><label>First name</label><input name="first_name" value="<?= $v('first_name') ?>" required></div>
  <div class="field"><label>Last name</label><input name="last_name" value="<?= $v('last_name') ?>"></div>
  <div class="field"><label>Username</label><input name="username" value="<?= $v('username', 'admin') ?>" required></div>
  <div class="field"><label>Email</label><input type="email" name="email" value="<?= $v('email') ?>"></div>
  <div class="field full"><label>Password (8+ characters)</label><input type="password" name="password" minlength="8" required></div>
  <div class="full"><button class="btn btn-primary btn-lg btn-block">Install Mfuko POS</button></div>
</form></div></section></div></body></html>
