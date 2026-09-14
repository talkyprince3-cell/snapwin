<?php
// api_techvault_status.php — Poll latest TechVault deposit for the logged-in user.
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/currency_helper.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthenticated']);
    exit;
}

$uid = (int)$_SESSION['user_id'];
$reference = trim($_GET['reference'] ?? $_POST['reference'] ?? '');

try {
    $cols = array_column($pdo->query("SHOW COLUMNS FROM transactions")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    $refCol = in_array('reference', $cols, true) ? 'reference'
        : (in_array('tx_reference', $cols, true) ? 'tx_reference' : null);
    if (!in_array('dep_notes', $cols, true)) {
        try {
            $pdo->exec("ALTER TABLE transactions ADD COLUMN dep_notes TEXT NULL DEFAULT NULL");
            $cols[] = 'dep_notes';
        } catch (Throwable $e) {}
    }

    $userColumns = ['balance'];
    if (ps_table_column_exists($pdo, 'users', 'phone')) $userColumns[] = 'phone';
    if (ps_table_column_exists($pdo, 'users', 'country')) $userColumns[] = 'country';
    $balanceStmt = $pdo->prepare("SELECT " . implode(', ', $userColumns) . " FROM users WHERE id=? LIMIT 1");
    $balanceStmt->execute([$uid]);
    $userRow = $balanceStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $balance = (float)($userRow['balance'] ?? 0);
    $currencyInfo = ps_detect_currency_from_user($userRow);
    $currencyCode = (string)($currencyInfo['code'] ?? 'GHS');

    $refSelect = $refCol ? "`{$refCol}` AS reference" : "NULL AS reference";
    $tx = null;
    if ($reference !== '' && $refCol) {
        $stmt = $pdo->prepare("
            SELECT id, amount, status, method, created_at, {$refSelect}
            FROM transactions
            WHERE user_id=? AND type='Deposit' AND method LIKE 'TechVault%' AND `{$refCol}`=?
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute([$uid, $reference]);
        $tx = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$tx) {
        $stmt = $pdo->prepare("
            SELECT id, amount, status, method, created_at, {$refSelect}
            FROM transactions
            WHERE user_id=? AND type='Deposit' AND method LIKE 'TechVault%' AND created_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute([$uid]);
    }
    if (!$tx) $tx = $stmt->fetch(PDO::FETCH_ASSOC);

    $isVerificationTx = $tx
        && strpos((string)($tx['reference'] ?? ''), 'WV_') === 0;

    if ($tx && strtolower((string)($tx['status'] ?? '')) === 'completed' && $isVerificationTx && in_array('dep_notes', $cols, true)) {
        $mark = $pdo->prepare("UPDATE transactions SET dep_notes='withdraw_verification' WHERE id=? AND COALESCE(dep_notes, '') = ''");
        $mark->execute([(int)$tx['id']]);
    }

    $_SESSION['balance'] = $balance;

    echo json_encode([
        'success' => true,
        'found' => (bool)$tx,
        'completed' => $tx && strtolower(trim((string)($tx['status'] ?? ''))) === 'completed',
        'balance' => number_format($balance, 2, '.', ''),
        'transaction' => $tx ?: null,
    ]);
} catch (Throwable $e) {
    error_log('TechVault Status Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Status check failed']);
}
?>
