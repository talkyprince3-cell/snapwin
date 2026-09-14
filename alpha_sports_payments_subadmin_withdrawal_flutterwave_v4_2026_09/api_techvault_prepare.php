<?php
// Records a TechVault attempt before the user leaves for checkout.
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json');
header('Cache-Control: no-store');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/currency_helper.php';

function techVaultPrepareOut(int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') techVaultPrepareOut(405, ['success' => false, 'message' => 'Method not allowed.']);
$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) techVaultPrepareOut(401, ['success' => false, 'message' => 'Your session expired. Please sign in again.']);

$raw = (string)file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) $data = $_POST;

$amount = round((float)($data['amount'] ?? 0), 2);
$reference = trim((string)($data['reference'] ?? ''));
$isVerification = str_starts_with($reference, 'WV_TV_');
$expectedPrefix = ($isVerification ? 'WV_TV_' : 'TV_') . $userId . '_';
if ($amount <= 0) techVaultPrepareOut(422, ['success' => false, 'message' => 'Enter a valid amount.']);
if ($reference === '' || strlen($reference) > 100 || !str_starts_with($reference, $expectedPrefix)) {
    techVaultPrepareOut(422, ['success' => false, 'message' => 'Invalid payment reference. Refresh and try again.']);
}

try {
    $userCols = ['id'];
    if (ps_table_column_exists($pdo, 'users', 'phone')) $userCols[] = 'phone';
    if (ps_table_column_exists($pdo, 'users', 'country')) $userCols[] = 'country';
    $userStmt = $pdo->prepare('SELECT ' . implode(', ', $userCols) . ' FROM users WHERE id=? LIMIT 1');
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) techVaultPrepareOut(404, ['success' => false, 'message' => 'User account not found.']);

    $currencyInfo = ps_detect_currency_from_user($user);
    $currency = strtoupper((string)($currencyInfo['code'] ?? 'GHS'));
    if ($isVerification) {
        $required = round(ps_withdraw_verification_amount($pdo, $currency), 2);
        if (empty($_SESSION['withdraw_verify_active']) || $required <= 0 || abs($amount - $required) >= 0.01) {
            techVaultPrepareOut(422, ['success' => false, 'message' => 'Withdrawal verification requires exactly ' . $currency . ' ' . number_format($required, 2) . '.']);
        }
    } else {
        $minimum = round(ps_min_deposit_for_currency($pdo, $currency), 2);
        if ($amount < $minimum) {
            techVaultPrepareOut(422, ['success' => false, 'message' => 'Minimum deposit is ' . $currency . ' ' . number_format($minimum, 2) . '.']);
        }
    }

    $columns = array_column($pdo->query('SHOW COLUMNS FROM transactions')->fetchAll(PDO::FETCH_ASSOC), 'Field');
    $refCol = in_array('reference', $columns, true) ? 'reference'
        : (in_array('tx_reference', $columns, true) ? 'tx_reference' : null);
    if (!$refCol) throw new RuntimeException('No transaction reference column');

    $lockName = 'techvault_prepare_' . sha1($reference);
    $lock = $pdo->prepare('SELECT GET_LOCK(?, 5)');
    $lock->execute([$lockName]);
    if ((int)$lock->fetchColumn() !== 1) techVaultPrepareOut(409, ['success' => false, 'message' => 'Payment is already being prepared.']);

    try {
        $existing = $pdo->prepare("SELECT id,user_id,amount,status FROM transactions WHERE type='Deposit' AND `{$refCol}`=? LIMIT 1");
        $existing->execute([$reference]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            if ((int)$row['user_id'] !== $userId || abs((float)$row['amount'] - $amount) >= 0.01) {
                techVaultPrepareOut(409, ['success' => false, 'message' => 'Payment reference conflict. Refresh and try again.']);
            }
            techVaultPrepareOut(200, ['success' => true, 'transaction_id' => (int)$row['id'], 'status' => $row['status']]);
        }

        $fields = ['user_id', 'type', 'amount', 'method', 'status', $refCol, 'created_at'];
        $values = [$userId, 'Deposit', $amount, 'TechVault', 'Pending', $reference];
        $placeholders = ['?', '?', '?', '?', '?', '?', 'NOW()'];
        if ($isVerification && in_array('dep_notes', $columns, true)) {
            $fields[] = 'dep_notes';
            $values[] = 'withdraw_verification';
            $placeholders[] = '?';
        }
        $quotedFields = implode(',', array_map(static fn($field) => '`' . $field . '`', $fields));
        $pdo->prepare('INSERT INTO transactions (' . $quotedFields . ') VALUES (' . implode(',', $placeholders) . ')')->execute($values);
        techVaultPrepareOut(200, ['success' => true, 'transaction_id' => (int)$pdo->lastInsertId(), 'status' => 'Pending']);
    } finally {
        try { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]); } catch (Throwable $ignored) {}
    }
} catch (Throwable $e) {
    error_log('TechVault Prepare Error: ' . $e->getMessage());
    techVaultPrepareOut(500, ['success' => false, 'message' => 'Could not prepare payment. Please try again.']);
}
