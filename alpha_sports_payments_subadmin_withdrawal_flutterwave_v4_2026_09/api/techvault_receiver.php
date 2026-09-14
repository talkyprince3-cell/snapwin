<?php
// Shared idempotent receiver used by both TechVault callback URLs.
header('Content-Type: application/json');
header('Cache-Control: no-store');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../currency_helper.php';
require_once __DIR__ . '/../payment_gateway_helper.php';
require_once __DIR__ . '/../subadmin_deposit_helper.php';

if (!function_exists('ps_techvault_callback_log')) {
    function ps_techvault_callback_log(string $event, array $context = []): void {
        $safe = [
            'time' => date('Y-m-d H:i:s'),
            'event' => $event,
            'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            'content_type' => (string)($_SERVER['CONTENT_TYPE'] ?? ''),
        ];
        foreach (['reference', 'ext_user', 'amount', 'currency', 'reason', 'tx_id'] as $key) {
            if (array_key_exists($key, $context)) $safe[$key] = $context[$key];
        }
        @file_put_contents(
            __DIR__ . '/../techvault_webhook.log',
            json_encode($safe, JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }
}

if (!function_exists('ps_techvault_out')) {
    function ps_techvault_out(int $code, array $payload, string $event = ''): void {
        if ($event !== '') ps_techvault_callback_log($event, $payload);
        http_response_code($code);
        echo json_encode($payload);
        exit;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    ps_techvault_out(405, ['error' => 'Method not allowed'], 'method_rejected');
}

$rawPayload = (string)file_get_contents('php://input');
$data = json_decode($rawPayload, true);
if (!is_array($data)) {
    // Some gateway retries use application/x-www-form-urlencoded.
    $data = is_array($_POST) ? $_POST : [];
}
if (!$data) {
    ps_techvault_out(400, ['error' => 'Invalid payload'], 'payload_rejected');
}

$providedToken = trim((string)($data['token'] ?? $_SERVER['HTTP_X_TECHVAULT_TOKEN'] ?? ''));
if ($providedToken === '') {
    $authorization = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if (stripos($authorization, 'Bearer ') === 0) $providedToken = trim(substr($authorization, 7));
}
$expectedToken = ps_techvault_shared_token($pdo);
if ($expectedToken === '' || $providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
    ps_techvault_out(403, ['error' => 'Unauthorized. Invalid token.'], 'auth_rejected');
}

$extUser = (int)($data['ext_user'] ?? $data['user_id'] ?? 0);
$amountPaid = round((float)($data['amount'] ?? 0), 2);
$gatewayReference = trim((string)($data['reference'] ?? ''));
$externalReference = trim((string)($data['external_reference'] ?? $data['merchant_reference'] ?? ''));
$reference = $gatewayReference !== '' ? $gatewayReference : $externalReference;
$payloadCurrency = strtoupper(trim((string)($data['currency'] ?? 'GHS')));
$logContext = [
    'reference' => $reference,
    'ext_user' => $extUser,
    'amount' => $amountPaid,
    'currency' => $payloadCurrency,
];

if ($extUser <= 0) ps_techvault_out(400, $logContext + ['error' => 'Invalid user', 'reason' => 'invalid_user'], 'callback_rejected');
if ($amountPaid <= 0) ps_techvault_out(400, $logContext + ['error' => 'Invalid amount', 'reason' => 'invalid_amount'], 'callback_rejected');
if ($reference === '' || strlen($reference) > 100) {
    ps_techvault_out(400, $logContext + ['error' => 'Invalid reference', 'reason' => 'invalid_reference'], 'callback_rejected');
}

$currencyLabel = $payloadCurrency !== '' ? $payloadCurrency : 'GHS';
try {
    $userCols = ['id', 'balance', 'email', 'username'];
    if (ps_table_column_exists($pdo, 'users', 'phone')) $userCols[] = 'phone';
    if (ps_table_column_exists($pdo, 'users', 'country')) $userCols[] = 'country';
    $userLookup = $pdo->prepare('SELECT ' . implode(', ', $userCols) . ' FROM users WHERE id=? LIMIT 1');
    $userLookup->execute([$extUser]);
    $callbackUser = $userLookup->fetch(PDO::FETCH_ASSOC);
    if (!$callbackUser) {
        ps_techvault_out(404, $logContext + ['error' => 'User not found', 'reason' => 'user_not_found'], 'callback_rejected');
    }
    $currencyInfo = ps_detect_currency_from_user($callbackUser);
    $currencyLabel = strtoupper((string)($currencyInfo['code'] ?? $currencyLabel));
    $logContext['currency'] = $currencyLabel;
} catch (Throwable $e) {
    ps_techvault_callback_log('user_lookup_failed', $logContext + ['reason' => $e->getMessage()]);
    ps_techvault_out(500, ['error' => 'Could not validate user'], 'callback_failed');
}

// Do not check the current minimum here. The money has already been taken by
// TechVault; the minimum is enforced when the checkout attempt is prepared.
$columns = [];
try {
    $columns = array_column($pdo->query('SHOW COLUMNS FROM transactions')->fetchAll(PDO::FETCH_ASSOC), 'Field');
} catch (Throwable $e) {
    ps_techvault_out(500, $logContext + ['error' => 'Transaction table unavailable', 'reason' => $e->getMessage()], 'callback_failed');
}
$refColName = in_array('reference', $columns, true) ? 'reference'
    : (in_array('tx_reference', $columns, true) ? 'tx_reference' : null);
if ($refColName === null) {
    ps_techvault_out(500, $logContext + ['error' => 'Transaction reference column unavailable'], 'callback_failed');
}
$hasDepNotes = in_array('dep_notes', $columns, true);
$hasUpdatedAt = in_array('updated_at', $columns, true);
$hasTxReference = in_array('tx_reference', $columns, true);
$hasDepReference = in_array('dep_reference', $columns, true);

$lockName = 'techvault_topup_' . sha1($reference);
$lockTaken = false;
$creditedNow = false;
$txId = null;
$newBalance = null;
$responseCode = 500;
$response = ['error' => 'Payment callback could not be completed'];

try {
    $lockStmt = $pdo->prepare('SELECT GET_LOCK(?, 10)');
    $lockStmt->execute([$lockName]);
    $lockTaken = (int)$lockStmt->fetchColumn() === 1;
    if (!$lockTaken) throw new RuntimeException('Reference is being processed. Retry shortly.');

    $pdo->beginTransaction();
    $userStmt = $pdo->prepare('SELECT id, balance FROM users WHERE id=? FOR UPDATE');
    $userStmt->execute([$extUser]);
    $lockedUser = $userStmt->fetch(PDO::FETCH_ASSOC);
    if (!$lockedUser) throw new RuntimeException('User disappeared during callback');

    $referenceChecks = ["`{$refColName}`=?"];
    $referenceValues = [$reference];
    if ($externalReference !== '' && $externalReference !== $reference) {
        $referenceChecks[] = "`{$refColName}`=?";
        $referenceValues[] = $externalReference;
    }
    if ($hasTxReference && $refColName !== 'tx_reference') {
        $referenceChecks[] = '`tx_reference`=?';
        $referenceValues[] = $reference;
    }
    if ($hasDepReference) {
        $referenceChecks[] = '`dep_reference`=?';
        $referenceValues[] = $reference;
    }
    $dup = $pdo->prepare(
        "SELECT id,user_id,amount,status FROM transactions WHERE type='Deposit' AND (" .
        implode(' OR ', $referenceChecks) . ') ORDER BY id DESC LIMIT 1 FOR UPDATE'
    );
    $dup->execute($referenceValues);
    $existingTx = $dup->fetch(PDO::FETCH_ASSOC);

    // TechVault can replace the client reference with its own TV-XXXXXXXX
    // reference. Link that callback to the latest matching prepared attempt.
    if (!$existingTx) {
        $pending = $pdo->prepare(
            "SELECT id,user_id,amount,status FROM transactions
             WHERE user_id=? AND type='Deposit' AND LOWER(TRIM(COALESCE(status,'')))='pending'
               AND method LIKE 'TechVault%' AND ABS(amount-?) < 0.01
               AND created_at >= DATE_SUB(NOW(), INTERVAL 6 HOUR)
             ORDER BY id DESC LIMIT 1 FOR UPDATE"
        );
        $pending->execute([$extUser, $amountPaid]);
        $existingTx = $pending->fetch(PDO::FETCH_ASSOC);
    }

    if ($existingTx && (int)$existingTx['user_id'] !== $extUser) {
        throw new RuntimeException('Reference belongs to a different user');
    }

    if ($existingTx && strtolower(trim((string)$existingTx['status'])) === 'completed') {
        $txId = (int)$existingTx['id'];
        $newBalance = (float)$lockedUser['balance'];
        $pdo->commit();
    } else {
        $pdo->prepare('UPDATE users SET balance=balance+? WHERE id=?')->execute([$amountPaid, $extUser]);
        $creditedNow = true;

        if ($existingTx) {
            $txId = (int)$existingTx['id'];
            $updatedSql = $hasUpdatedAt ? ', updated_at=NOW()' : '';
            $gatewayRefSql = '';
            $updateValues = [$amountPaid];
            if ($hasTxReference && $gatewayReference !== '') {
                $gatewayRefSql .= ', tx_reference=?';
                $updateValues[] = $gatewayReference;
            } elseif ($hasDepReference && $gatewayReference !== '') {
                $gatewayRefSql .= ', dep_reference=?';
                $updateValues[] = $gatewayReference;
            }
            $updateValues[] = $txId;
            $pdo->prepare("UPDATE transactions SET amount=?, method='TechVault', status='Completed'{$gatewayRefSql}{$updatedSql} WHERE id=?")
                ->execute($updateValues);
        } else {
            $storedReference = $externalReference !== '' ? $externalReference : $reference;
            if ($hasTxReference && $refColName !== 'tx_reference' && $gatewayReference !== '') {
                $pdo->prepare("INSERT INTO transactions (user_id,type,amount,method,status,`{$refColName}`,tx_reference,created_at) VALUES (?,'Deposit',?,'TechVault','Completed',?,?,NOW())")
                    ->execute([$extUser, $amountPaid, $storedReference, $gatewayReference]);
            } else {
                $pdo->prepare("INSERT INTO transactions (user_id,type,amount,method,status,`{$refColName}`,created_at) VALUES (?,'Deposit',?,'TechVault','Completed',?,NOW())")
                    ->execute([$extUser, $amountPaid, $storedReference]);
            }
            $txId = (int)$pdo->lastInsertId();
        }

        $isVerificationDeposit = str_starts_with($reference, 'WV_')
            || ($externalReference !== '' && str_starts_with($externalReference, 'WV_'));
        if ($hasDepNotes && $isVerificationDeposit) {
            $pdo->prepare("UPDATE transactions SET dep_notes='withdraw_verification' WHERE id=?")->execute([$txId]);
        }
        $balanceStmt = $pdo->prepare('SELECT balance FROM users WHERE id=? LIMIT 1');
        $balanceStmt->execute([$extUser]);
        $newBalance = (float)$balanceStmt->fetchColumn();
        $pdo->commit();
    }

    $commissionResult = ['awarded' => false, 'reason' => 'skipped'];
    try {
        $commissionResult = ps_award_subadmin_deposit_commission($pdo, $extUser, $amountPaid, $txId, 'techvault_topup');
    } catch (Throwable $e) {
        ps_techvault_callback_log('commission_failed', $logContext + ['tx_id' => $txId, 'reason' => $e->getMessage()]);
    }

    if ($creditedNow) {
        try {
            require_once __DIR__ . '/../mailer.php';
            if (filter_var((string)($callbackUser['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
                sw_email_deposit(
                    (string)$callbackUser['email'],
                    (string)($callbackUser['username'] ?? ''),
                    $amountPaid,
                    $reference,
                    (float)$newBalance
                );
            }
        } catch (Throwable $e) {
            ps_techvault_callback_log('email_failed', $logContext + ['tx_id' => $txId, 'reason' => $e->getMessage()]);
        }
    }

    $responseCode = 200;
    $response = [
        'status' => 'success',
        'credited' => $creditedNow,
        'tx_id' => $txId,
        'commission_awarded' => (bool)($commissionResult['awarded'] ?? false),
        'commission_reason' => (string)($commissionResult['reason'] ?? ''),
        'new_balance' => number_format((float)$newBalance, 2, '.', ''),
        'currency' => $currencyLabel,
        'message' => $creditedNow ? 'Wallet credited' : 'Reference already credited',
    ];
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        try { $pdo->rollBack(); } catch (Throwable $ignored) {}
    }
    $responseCode = str_contains($e->getMessage(), 'being processed') ? 409 : 500;
    $response = ['error' => $responseCode === 409 ? $e->getMessage() : 'Payment callback could not be completed'];
    ps_techvault_callback_log('callback_failed', $logContext + ['tx_id' => $txId, 'reason' => $e->getMessage()]);
} finally {
    if ($lockTaken) {
        try { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]); } catch (Throwable $ignored) {}
    }
}

ps_techvault_callback_log(
    $responseCode === 200 ? ($creditedNow ? 'wallet_credited' : 'duplicate_callback') : 'callback_failed',
    $logContext + ['tx_id' => $txId]
);
http_response_code($responseCode);
echo json_encode($response);
