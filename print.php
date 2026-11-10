<?php
/** Single print endpoint: print.php?doc=receipt&id=12&size=a4|thermal&auto=1 */
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

if (!Auth::check()) redirect('index.php?p=login');
$doc = preg_replace('/[^a-z]/', '', (string)($_GET['doc'] ?? ''));
$id = (string)($_GET['id'] ?? '');
if (!Documents::canPrint($doc)) { http_response_code(403); exit('You cannot print this document.'); }

$default = $doc === 'receipt' ? Settings::get('print_receipt_size') : Settings::get('print_default_size');
$size = in_array($_GET['size'] ?? '', ['a4', 'thermal'], true) ? $_GET['size'] : $default;

try {
    $d = Documents::build($doc, $id, $_GET);
} catch (ApiError $e) {
    http_response_code($e->getCode() ?: 404);
    exit(e($e->getMessage()));
}
Audit::log('print', $doc, $id, "Printed {$d['title']} {$d['number']} ($size)");
echo Printer::render($d, $size, ['auto' => !empty($_GET['auto']), 'frame' => !empty($_GET['frame'])]);
