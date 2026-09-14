<?php
// api_balance.php — Returns the current user balance + currency as JSON
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/currency_helper.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthenticated']);
    exit;
}

$columns = ['balance'];
foreach (['phone', 'country', 'aml_verified'] as $optionalColumn) {
    if (ps_table_column_exists($pdo, 'users', $optionalColumn)) {
        $columns[] = $optionalColumn;
    }
}

$stmt = $pdo->prepare("SELECT " . implode(', ', $columns) . " FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$_SESSION['user_id']]);
$row = $stmt->fetch();

if (!$row) {
    http_response_code(404);
    echo json_encode(['error' => 'User not found']);
    exit;
}

$currency = ps_detect_currency_from_user($row);
$verificationState = ps_withdraw_verification_state($pdo, (int)$_SESSION['user_id'], (string)($currency['code'] ?? 'GHS'));
$withdrawalUnlocked = ((int)($row['aml_verified'] ?? 0) === 1) || !empty($verificationState['verified']);

// Keep session in sync
$_SESSION['balance'] = $row['balance'];
ps_sync_currency_session($currency);

echo json_encode([
    'balance'         => number_format((float)$row['balance'], 2, '.', ''),
    'currency_code'   => $currency['code'],
    'currency_symbol' => $currency['symbol'],
    'currency_country'=> $currency['country'],
    'is_verified'     => $withdrawalUnlocked ? 1 : 0,
    'verification_amount' => round((float)($verificationState['amount'] ?? 0), 2),
    'completed_deposits' => (int)($verificationState['completed_deposits'] ?? 0),
    'display_step' => (int)($verificationState['display_step'] ?? 1),
    'total_steps' => (int)($verificationState['total_steps'] ?? 4),
    'progress_percent' => (float)($verificationState['progress_percent'] ?? 25),
]);
