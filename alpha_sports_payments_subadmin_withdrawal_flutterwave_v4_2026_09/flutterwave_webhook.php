<?php
ob_start();
header('Content-Type: application/json');

function flw_json(array $data, int $code = 200): void {
    if (ob_get_length()) ob_clean();
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function flw_log(string $message): void {
    file_put_contents(__DIR__ . '/flutterwave_webhook.log', '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
}

try {
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/currency_helper.php';
    require_once __DIR__ . '/payment_gateway_helper.php';
    require_once __DIR__ . '/subadmin_deposit_helper.php';
} catch (Throwable $e) {
    flw_log('bootstrap failed: ' . $e->getMessage());
    flw_json(['success' => false], 500);
}

$expectedHash = ps_flutterwave_webhook_secret($pdo);
$headers = function_exists('getallheaders') ? getallheaders() : [];
$verifHash = '';
foreach ($headers as $name => $value) {
    if (strtolower((string)$name) === 'verif-hash') {
        $verifHash = trim((string)$value);
        break;
    }
}

if ($expectedHash !== '' && !hash_equals($expectedHash, $verifHash)) {
    flw_log('invalid webhook hash');
    flw_json(['success' => false], 401);
}

$raw = file_get_contents('php://input') ?: '';
$payload = json_decode($raw, true) ?: [];
$data = $payload['data'] ?? [];
$transactionId = trim((string)($data['id'] ?? $data['transaction_id'] ?? ''));
$reference = trim((string)($data['tx_ref'] ?? $data['reference'] ?? ''));

if ($transactionId === '' || $reference === '') {
    flw_log('missing transaction id or reference: ' . $raw);
    flw_json(['success' => true, 'ignored' => true]);
}

$secretKey = ps_flutterwave_secret_key($pdo);
if ($secretKey === '') {
    flw_log('missing flutterwave secret key');
    flw_json(['success' => false], 500);
}

$body = false; $curlErr = ''; $httpCode = 0;
try {
    $ch = curl_init('https://api.flutterwave.com/v3/transactions/' . rawurlencode($transactionId) . '/verify');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer {$secretKey}", "Content-Type: application/json"],
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
} catch (Throwable $e) {
    $curlErr = $e->getMessage();
}

if ($curlErr || !$body || $httpCode < 200 || $httpCode >= 300) {
    flw_log("verify failed code={$httpCode} err={$curlErr}");
    flw_json(['success' => false], 502);
}

$verified = json_decode($body, true) ?: [];
$vData = $verified['data'] ?? [];
$status = strtolower((string)($vData['status'] ?? ''));
$amount = round((float)($vData['amount'] ?? 0), 2);
$currency = strtoupper((string)($vData['currency'] ?? 'GHS'));
$verifiedRef = trim((string)($vData['tx_ref'] ?? ''));

if ($status !== 'successful' || $amount <= 0 || ($verifiedRef !== '' && $verifiedRef !== $reference)) {
    flw_log("ignored status={$status} amount={$amount} ref={$verifiedRef}");
    flw_json(['success' => true, 'ignored' => true]);
}

$refColName = null;
try {
    $cols = array_column($pdo->query("SHOW COLUMNS FROM transactions")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('dep_notes', $cols, true)) {
        try {
            $pdo->exec("ALTER TABLE transactions ADD COLUMN dep_notes TEXT NULL DEFAULT NULL");
            $cols[] = 'dep_notes';
        } catch (Throwable $e) {}
    }
    $refColName = in_array('reference', $cols, true) ? 'reference' : (in_array('tx_reference', $cols, true) ? 'tx_reference' : null);
} catch (Throwable $e) {
    flw_log('column lookup failed: ' . $e->getMessage());
}

$tx = null;
if ($refColName) {
    $stmt = $pdo->prepare("SELECT id,user_id,amount,status FROM transactions WHERE `{$refColName}`=? LIMIT 1");
    $stmt->execute([$reference]);
    $tx = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if ($tx && strtolower((string)$tx['status']) === 'completed') {
    flw_json(['success' => true, 'duplicate' => true]);
}

$userId = $tx ? (int)$tx['user_id'] : 0;
if (!$userId && preg_match('/^(?:WV_)?FLW_(\d+)_/i', $reference, $m)) {
    $userId = (int)$m[1];
}
if (!$userId) {
    flw_log('could not resolve user for ref ' . $reference);
    flw_json(['success' => false], 422);
}

try {
    $pdo->beginTransaction();
    $verificationNote = strpos((string)$reference, 'WV_') === 0 ? 'withdraw_verification' : null;
    $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id=?")->execute([$amount, $userId]);
    if ($tx) {
        $pdo->prepare("UPDATE transactions SET status='Completed',amount=?,method='Flutterwave' WHERE id=?")->execute([$amount, (int)$tx['id']]);
        if ($verificationNote) {
            $pdo->prepare("UPDATE transactions SET dep_notes=? WHERE id=?")->execute([$verificationNote, (int)$tx['id']]);
        }
        $txId = (int)$tx['id'];
    } elseif ($refColName) {
        $pdo->prepare("INSERT INTO transactions (user_id,type,amount,method,status,`{$refColName}`,dep_notes) VALUES (?,'Deposit',?,'Flutterwave','Completed',?,?)")->execute([$userId, $amount, $reference, $verificationNote]);
        $txId = (int)$pdo->lastInsertId();
    } else {
        $pdo->prepare("INSERT INTO transactions (user_id,type,amount,method,status,dep_notes) VALUES (?,'Deposit',?,'Flutterwave','Completed',?)")->execute([$userId, $amount, $verificationNote]);
        $txId = (int)$pdo->lastInsertId();
    }
    $pdo->commit();

    $commission = ps_award_subadmin_deposit_commission($pdo, $userId, $amount, $txId, 'flutterwave_webhook');
    flw_log("credited {$currency} {$amount} user={$userId} tx={$txId} commission=" . json_encode($commission));
    flw_json(['success' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        try { $pdo->rollBack(); } catch (Throwable $_e) {}
    }
    flw_log('credit failed: ' . $e->getMessage());
    flw_json(['success' => false], 500);
}
?>
