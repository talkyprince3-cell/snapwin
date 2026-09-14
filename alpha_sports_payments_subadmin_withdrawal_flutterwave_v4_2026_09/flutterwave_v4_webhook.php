<?php
// Flutterwave v4 webhook. Configure this URL in the v4 Flutterwave dashboard.
ob_start();
header('Content-Type: application/json');

function flw4_webhook_out(array $body, int $status = 200): void {
    if (ob_get_length()) ob_clean();
    http_response_code($status);
    echo json_encode($body);
    exit;
}
function flw4_webhook_log(string $message): void {
    file_put_contents(__DIR__ . '/flutterwave_v4_webhook.log', '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
}
function flw4_get_token(string $clientId, string $clientSecret): string {
    $ch = curl_init('https://idp.flutterwave.com/realms/flutterwave/protocol/openid-connect/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['client_id' => $clientId, 'client_secret' => $clientSecret, 'grant_type' => 'client_credentials']),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $error = curl_error($ch);
    curl_close($ch);
    $token = (string)((json_decode((string)$body, true)['access_token'] ?? ''));
    if ($error !== '' || $code < 200 || $code >= 300 || $token === '') throw new RuntimeException('OAuth token request failed');
    return $token;
}

try {
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/currency_helper.php';
    require_once __DIR__ . '/payment_gateway_helper.php';
    require_once __DIR__ . '/subadmin_deposit_helper.php';
    require_once __DIR__ . '/mailer.php';
} catch (Throwable $e) {
    flw4_webhook_log('bootstrap failed: ' . $e->getMessage());
    flw4_webhook_out(['success' => false], 500);
}

$raw = file_get_contents('php://input') ?: '';
// Permit a harmless health check from Flutterwave or an administrator. Any
// webhook carrying a payload still has to pass the signature verification below.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || trim($raw) === '') {
    flw4_webhook_out(['success' => true, 'status' => 'ready']);
}
$signature = trim((string)($_SERVER['HTTP_FLUTTERWAVE_SIGNATURE'] ?? ''));
if ($signature === '' && function_exists('getallheaders')) {
    foreach (getallheaders() as $name => $value) if (strtolower((string)$name) === 'flutterwave-signature') $signature = trim((string)$value);
}
$webhookSecret = ps_flutterwave_v4_webhook_secret($pdo);
if ($webhookSecret === '' || $signature === '') {
    flw4_webhook_log('missing webhook secret or signature');
    flw4_webhook_out(['success' => false], 401);
}
$expected = base64_encode(hash_hmac('sha256', $raw, $webhookSecret, true));
if (!hash_equals($expected, $signature)) {
    flw4_webhook_log('invalid signature');
    flw4_webhook_out(['success' => false], 401);
}

$payload = json_decode($raw, true) ?: [];
$event = (string)($payload['type'] ?? '');
$eventData = is_array($payload['data'] ?? null) ? $payload['data'] : [];
if ($event !== 'charge.completed' || strtolower((string)($eventData['status'] ?? '')) !== 'succeeded') {
    flw4_webhook_out(['success' => true, 'ignored' => true]);
}
$chargeId = trim((string)($eventData['id'] ?? ''));
$reference = trim((string)($eventData['reference'] ?? ''));
if ($chargeId === '' || $reference === '') {
    flw4_webhook_log('event missing charge ID or reference');
    flw4_webhook_out(['success' => true, 'ignored' => true]);
}

try {
    $token = flw4_get_token(ps_flutterwave_v4_client_id($pdo), ps_flutterwave_v4_client_secret($pdo));
    $ch = curl_init(rtrim(ps_flutterwave_v4_base_url($pdo), '/') . '/charges/' . rawurlencode($chargeId));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $verifyBody = curl_exec($ch); $verifyCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); $verifyError = curl_error($ch);
    curl_close($ch);
    $verified = json_decode((string)$verifyBody, true) ?: [];
    if ($verifyError !== '' || $verifyCode < 200 || $verifyCode >= 300 || !isset($verified['data'])) throw new RuntimeException('charge verification failed');
} catch (Throwable $e) {
    flw4_webhook_log($e->getMessage());
    flw4_webhook_out(['success' => false], 502);
}

$charge = $verified['data'];
if (strtolower((string)($charge['status'] ?? '')) !== 'succeeded' || trim((string)($charge['reference'] ?? '')) !== $reference) {
    flw4_webhook_log('charge failed validation for ' . $reference);
    flw4_webhook_out(['success' => true, 'ignored' => true]);
}
$amount = round((float)($charge['amount'] ?? 0), 2);
$currency = strtoupper((string)($charge['currency'] ?? ''));
if ($amount <= 0 || $currency === '') flw4_webhook_out(['success' => true, 'ignored' => true]);

$columns = array_column($pdo->query('SHOW COLUMNS FROM transactions')->fetchAll(PDO::FETCH_ASSOC), 'Field');
$referenceColumn = in_array('reference', $columns, true) ? 'reference' : (in_array('tx_reference', $columns, true) ? 'tx_reference' : null);
if (!$referenceColumn) {
    flw4_webhook_log('transactions table has no reference column');
    flw4_webhook_out(['success' => false], 500);
}

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT id,user_id,amount,status FROM transactions WHERE `{$referenceColumn}`=? AND method='Flutterwave V4' LIMIT 1 FOR UPDATE");
    $stmt->execute([$reference]);
    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$transaction) throw new RuntimeException('unknown reference ' . $reference);
    if ((string)$transaction['status'] === 'Completed') {
        $pdo->commit();
        flw4_webhook_out(['success' => true, 'duplicate' => true]);
    }
    if (abs(round((float)$transaction['amount'], 2) - $amount) >= 0.01) throw new RuntimeException('amount mismatch for ' . $reference);
    $userColumns = ['id', 'email', 'username', 'balance', 'phone'];
    if (ps_table_column_exists($pdo, 'users', 'country')) $userColumns[] = 'country';
    $userStmt = $pdo->prepare('SELECT ' . implode(',', $userColumns) . ' FROM users WHERE id=? LIMIT 1 FOR UPDATE');
    $userStmt->execute([(int)$transaction['user_id']]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) throw new RuntimeException('user not found for ' . $reference);
    $expectedCurrency = strtoupper((string)(ps_detect_currency_from_user($user)['code'] ?? ''));
    if ($expectedCurrency === '' || $currency !== $expectedCurrency) throw new RuntimeException('currency mismatch for ' . $reference);
    $pdo->prepare('UPDATE users SET balance=balance+? WHERE id=?')->execute([$amount, (int)$user['id']]);
    $pdo->prepare("UPDATE transactions SET status='Completed',amount=? WHERE id=?")->execute([$amount, (int)$transaction['id']]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flw4_webhook_log($e->getMessage());
    flw4_webhook_out(['success' => false], 422);
}

try {
    if (filter_var((string)$user['email'], FILTER_VALIDATE_EMAIL)) {
        sw_email_deposit($user['email'], $user['username'], $amount, $reference, (float)$user['balance'] + $amount);
    }
    ps_award_subadmin_deposit_commission($pdo, (int)$user['id'], $amount, (int)$transaction['id'], 'flutterwave_v4_webhook');
} catch (Throwable $e) {
    flw4_webhook_log('post-credit notification error: ' . $e->getMessage());
}
flw4_webhook_out(['success' => true]);
