<?php
/** Mfuko POS — front controller for every screen. */
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

$pages = require MF_ROOT . '/app/pages.php';
$p = preg_replace('/[^a-z\-]/', '', (string)($_GET['p'] ?? ''));

if ($p === 'logout') {
    Auth::logout();
    redirect(url('login'));
}

if (!Auth::check()) {
    if ($p !== 'login' && $p !== '') $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? '';
    require MF_ROOT . '/pages/login.php';
    exit;
}

$user = Auth::user();
if ($p === '' || $p === 'login') redirect(url($user['home_page'] ?: 'dashboard'));

if (!isset($pages[$p])) {
    http_response_code(404);
    $page = ['title' => 'Not found', 'icon' => 'alert', 'group' => null];
    $content = '<div class="empty">' . icon('alert') . '<h2>Page not found</h2><a class="btn" href="' . url('dashboard') . '">Go home</a></div>';
    require MF_ROOT . '/app/views/layout.php';
    exit;
}

$page = $pages[$p];
$perm = $page['perm'];
$allowed = $perm === null || (is_array($perm) ? Auth::canAny($perm) : Auth::can($perm));
if (!$allowed) {
    http_response_code(403);
    $content = '<div class="empty">' . icon('lock') . '<h2>No access</h2><p>Your role cannot open this page. Ask an administrator.</p><a class="btn" href="' . url($user['home_page']) . '">Go to my home</a></div>';
    require MF_ROOT . '/app/views/layout.php';
    exit;
}

Auth::touch($p);
$storeId = Auth::currentStore();
$scripts = [];       // pages push extra JS files here
$cdn = [];           // pages push CDN libs here (e.g. Chart.js)

ob_start();
if (!empty($page['resource'])) {
    $resourceKey = $page['resource'];
    require MF_ROOT . '/pages/resource.php';
} else {
    require MF_ROOT . "/pages/$p.php";
}
$content = ob_get_clean();

require MF_ROOT . '/app/views/layout.php';
