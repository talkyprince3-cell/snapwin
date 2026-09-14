<?php
session_start();
require_once 'db.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/currency_helper.php';
header('Content-Type: application/json');

try { $pdo->exec("ALTER TABLE transactions ADD COLUMN comm_rate decimal(5,2) DEFAULT 15.00"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE transactions ADD COLUMN comm_amount decimal(10,2) DEFAULT 0.00"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE transactions ADD COLUMN comm_paid tinyint(1) DEFAULT 0"); } catch(Exception $e) {}

$user_id = $_SESSION['user_id'] ?? null;
// Support both JSON body (fetch+JSON.stringify) AND FormData (form submit)
$raw  = file_get_contents('php://input');
$data = !empty($raw) ? json_decode($raw, true) : null;
if (!is_array($data) || empty($data)) {
    $data = $_POST; // FormData fallback
}
$amount  = floatval($data['amount']  ?? 0);
$phone   = trim($data['phone'] ?? $data['number'] ?? '');  // accept both field names
$network = trim($data['network']     ?? 'Mobile Money');
$bankName = trim($data['bank_name'] ?? '');
$accountNumber = preg_replace('/\D+/', '', (string)($data['account_number'] ?? $phone));
$accountName = trim($data['account_name'] ?? '');

if (!$user_id) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
}
if ($amount <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid amount']); exit;
}

$is_admin = false;
try {
    $adminStmt = $pdo->prepare("SELECT value FROM admin_settings WHERE `key`='admin_user_ids'");
    $adminStmt->execute();
    $adminIds = array_filter(array_map('trim', explode(',', (string)($adminStmt->fetchColumn() ?: ''))));
    if (!in_array('1', $adminIds, true)) {
        $adminIds[] = '1';
    }
    $is_admin = in_array((string)$user_id, $adminIds, true);
} catch (Exception $e) {
    $is_admin = ((string)$user_id === '1');
}

try {
    $pdo->beginTransaction();

    // 1. Lock row and check balance + AML verification status
    $columns = ['balance'];
    if (ps_table_column_exists($pdo, 'users', 'phone')) {
        $columns[] = 'phone';
    }
    if (ps_table_column_exists($pdo, 'users', 'country')) {
        $columns[] = 'country';
    }
    if (ps_table_column_exists($pdo, 'users', 'aml_verified')) {
        $columns[] = 'aml_verified';
    }
    if (ps_table_column_exists($pdo, 'users', 'is_agent')) {
        $columns[] = 'is_agent';
    }
    if (ps_table_column_exists($pdo, 'users', 'linked_agent_id')) {
        $columns[] = 'linked_agent_id';
    }
    if (ps_table_column_exists($pdo, 'users', 'email')) {
        $columns[] = 'email';
    }
    if (ps_table_column_exists($pdo, 'users', 'username')) {
        $columns[] = 'username';
    }

    $stmt = $pdo->prepare("SELECT " . implode(', ', $columns) . " FROM users WHERE id = ? FOR UPDATE");
    $stmt->execute([$user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $current      = (float)($row['balance']      ?? 0);
    $aml_verified = (int)  ($row['aml_verified'] ?? 0);
    $is_agent_user = (int)($row['is_agent'] ?? 0) === 1 && (int)($row['linked_agent_id'] ?? 0) > 0;
    $currencyInfo = ps_detect_currency_from_user([
        'phone' => $row['phone'] ?? '',
        'country' => $row['country'] ?? '',
    ]);
    $isNigeria = (($currencyInfo['code'] ?? 'GHS') === 'NGN');
    $verificationState = ps_withdraw_verification_state($pdo, (int)$user_id, (string)($currencyInfo['code'] ?? 'GHS'));
    $withdrawalUnlocked = $aml_verified || !empty($verificationState['verified']);

    if ($isNigeria) {
        $network = 'Bank Transfer';
        $phone = $accountNumber;
        if ($bankName === '' || $accountName === '' || !preg_match('/^[0-9]{10}$/', $accountNumber)) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Enter bank name, 10-digit account number, and account name before withdrawing.']); exit;
        }
    } else {
        if ($network === '' || !preg_match('/^[0-9]{10}$/', $phone)) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Select a payment provider and enter a valid mobile number.']); exit;
        }
    }

    // Block users who have not been AML-verified by admin — completely separate from registration
    if (!$withdrawalUnlocked && !$is_admin && !$is_agent_user) {
        $pdo->rollBack();
        $nextStep = (int)($verificationState['display_step'] ?? 1);
        $stepTotal = (int)($verificationState['total_steps'] ?? 4);
        $verifyAmount = number_format((float)($verificationState['amount'] ?? 0), 2);
        echo json_encode(['success' => false, 'message' => "Complete your verification deposit progress ({$nextStep}/{$stepTotal}) with {$currencyInfo['code']} {$verifyAmount} deposits before withdrawing."]); exit;
    }

    if ($current < $amount) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Insufficient balance']); exit;
    }

    $rateStmt = $pdo->prepare("SELECT value FROM admin_settings WHERE `key`='withdrawal_comm_rate'");
    $rateStmt->execute();
    $storedRate = $rateStmt->fetchColumn();
    $storedRateFloat = (float)$storedRate;
    $withdrawalRate = ($storedRate === false || $storedRate === '' || abs($storedRateFloat - 50.00) < 0.0001)
        ? 15.00
        : max(0, $storedRateFloat);
    $withdrawalCharge = round(($current * $withdrawalRate) / 100, 2);

    // 2. Deduct balance immediately so user can't double-spend
    $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ?")
        ->execute([$amount, $user_id]);
    $newBalance = round($current - $amount, 2);
    $_SESSION['balance'] = $newBalance;

    $txStatus = $is_agent_user ? 'Completed' : 'Pending';

    // 3. Log transaction
    $ref = $isNigeria
        ? "Bank Transfer — {$bankName} — {$accountNumber} — {$accountName}"
        : "{$network} — {$phone}";
    $pdo->prepare("INSERT INTO transactions (user_id, type, amount, method, status, tx_reference, comm_rate, comm_amount) VALUES (?, 'Withdrawal', ?, ?, ?, ?, ?, ?)")
        ->execute([$user_id, $amount, $network, $txStatus, $ref, $withdrawalRate, $withdrawalCharge]);

    $pdo->commit();

    // ── Withdrawal email (non-fatal) ──────────────────────
    try {
        if (!empty($row['email'])) {
            if ($is_agent_user) {
                sw_email_withdrawal_completed((string)$row['email'], (string)($row['username'] ?? ''), $amount, $phone, $network);
            } else {
                sw_email_withdrawal_request((string)$row['email'], (string)($row['username'] ?? ''), $amount, $phone, $network);
            }
        }
    } catch (Exception $mailErr) { /* non-fatal */ }

    echo json_encode([
        'success' => true,
        'message' => $is_agent_user ? 'Withdrawal completed successfully.' : 'Withdrawal request submitted. Pending admin approval.',
        'instant_notification' => $is_agent_user,
        'status' => $txStatus,
        'submission_amount' => $is_agent_user ? 0 : ps_withdraw_submission_amount($pdo, (string)($currencyInfo['code'] ?? 'GHS')),
        'currency' => (string)($currencyInfo['code'] ?? 'GHS'),
        'new_balance' => $newBalance,
        'amount' => $amount,
        'network' => $network
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
