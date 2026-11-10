<?php
/** Public endpoint for Safaricom Daraja callbacks. Protected by a secret token. */
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json');
$token = Settings::get('mpesa_callback_token');
if ($token === '' || !hash_equals($token, (string)($_GET['t'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Forbidden']);
    exit;
}

$raw = (string)file_get_contents('php://input');
$payload = json_decode($raw, true) ?: [];
@file_put_contents(MF_ROOT . '/storage/logs/mpesa-' . date('Y-m') . '.log', date('c') . ' ' . ($_GET['k'] ?? '') . ' ' . $raw . "\n", FILE_APPEND);

try {
    switch ($_GET['k'] ?? '') {
        case 'stk':
            Mpesa::handleStkCallback($payload);
            break;
        case 'c2b_validate':
            // Accept everything; add business rules here (e.g. validate account numbers).
            break;
        case 'c2b_confirm':
            Mpesa::handleC2B($payload);
            break;
    }
} catch (Throwable $e) {
    error_log('mpesa callback: ' . $e->getMessage());
}
echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
