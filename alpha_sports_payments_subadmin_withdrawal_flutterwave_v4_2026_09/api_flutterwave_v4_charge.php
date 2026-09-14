<?php
// Starts a Flutterwave v4 wallet-funding charge. V4 credentials never leave the server.
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

function flw4_out(array $body, int $status = 200): void {
    if (ob_get_length()) ob_clean();
    http_response_code($status);
    echo json_encode($body);
    exit;
}

function flw4_gateway_error(array $response, string $fallback): string {
    $message = $response['error']['message']
        ?? $response['message']
        ?? $response['error_description']
        ?? $response['error']
        ?? $fallback;
    return is_string($message) && trim($message) !== '' ? trim($message) : $fallback;
}

function flw4_v4_post(string $url, string $accessToken, array $payload, string $traceId): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
            'Content-Type: application/json',
            'X-Trace-Id: ' . $traceId,
            'X-Idempotency-Key: ' . $traceId,
        ],
        CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'error' => $error, 'response' => json_decode((string)$body, true) ?: []];
}

function flw4_v4_success(array $result): bool {
    return $result['error'] === '' && $result['code'] >= 200 && $result['code'] < 300 && isset($result['response']['data']);
}

try {
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/currency_helper.php';
    require_once __DIR__ . '/payment_gateway_helper.php';
} catch (Throwable $e) {
    flw4_out(['success' => false, 'message' => 'Payment gateway is unavailable.'], 500);
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if (!$userId) flw4_out(['success' => false, 'message' => 'Session expired. Please log in again.'], 401);
if (ps_flutterwave_version($pdo) !== 'v4') flw4_out(['success' => false, 'message' => 'Flutterwave v4 is not enabled.'], 409);

$clientId = ps_flutterwave_v4_client_id($pdo);
$clientSecret = ps_flutterwave_v4_client_secret($pdo);
if ($clientId === '' || $clientSecret === '') {
    flw4_out(['success' => false, 'message' => 'Flutterwave v4 is not configured.'], 503);
}

$amount = round((float)($_POST['amount'] ?? 0), 2);
$operator = strtoupper(trim((string)($_POST['operator'] ?? '')));
$submittedPhone = trim((string)($_POST['phone'] ?? ''));

$userCols = ['email', 'username', 'phone'];
if (ps_table_column_exists($pdo, 'users', 'country')) $userCols[] = 'country';
$stmt = $pdo->prepare('SELECT ' . implode(',', $userCols) . ' FROM users WHERE id=? LIMIT 1');
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$currencyInfo = ps_detect_currency_from_user($user);
$currency = strtoupper((string)($currencyInfo['code'] ?? 'GHS'));
ps_sync_currency_session($currencyInfo);
$minDeposit = ps_min_deposit_for_currency($pdo, $currency);
if ($amount < $minDeposit) {
    flw4_out(['success' => false, 'message' => 'Minimum deposit is ' . $currency . ' ' . number_format($minDeposit, 2) . '.'], 422);
}

$phone = preg_replace('/\D+/', '', $submittedPhone !== '' ? $submittedPhone : (string)($user['phone'] ?? ''));
$countryCode = $currency === 'NGN' ? '234' : '233';
if (str_starts_with($phone, $countryCode)) $phone = substr($phone, strlen($countryCode));
if (str_starts_with($phone, '0')) $phone = substr($phone, 1);
if ($phone === '' || strlen($phone) < 8) flw4_out(['success' => false, 'message' => 'Enter a valid mobile number.'], 422);

// The v4 orchestrator accepts the mobile-money providers below. Card and bank
// account payments require additional secure fields, so they are intentionally
// not claimed as available by this server endpoint.
if ($currency === 'GHS') {
    $networkMap = ['MTN' => 'MTN', 'AIRTELTIGO' => 'AIRTELTIGO', 'TELECEL' => 'VODAFONE', 'VODAFONE' => 'VODAFONE'];
    if (!isset($networkMap[$operator])) $operator = 'MTN';
    $paymentMethod = [
        'type' => 'mobile_money',
        'mobile_money' => [
            'country_code' => $countryCode,
            'network' => $networkMap[$operator],
            'phone_number' => $phone,
        ],
    ];
} elseif ($currency === 'NGN' && $operator === 'OPAY') {
    $paymentMethod = ['type' => 'opay'];
} else {
    flw4_out(['success' => false, 'message' => 'Flutterwave v4 currently supports OPay for NGN deposits on this site.'], 422);
}

$email = trim((string)($user['email'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $email = 'user' . $userId . '@alpha-sports.local';
$name = trim((string)($user['username'] ?? 'Customer')) ?: 'Customer';
$reference = 'flw4-' . $userId . '-' . dechex(time()) . '-' . bin2hex(random_bytes(4));
$reference = substr($reference, 0, 42);
$isVerification = !empty($_SESSION['withdraw_verify_active']);
$expectedVerificationAmount = round((float)($_SESSION['withdraw_verify_amount'] ?? 0), 2);
if ($isVerification && abs($amount - $expectedVerificationAmount) >= 0.01) {
    flw4_out(['success' => false, 'message' => 'Withdrawal verification requires the exact requested amount.'], 422);
}

try { $pdo->exec('ALTER TABLE transactions ADD COLUMN dep_notes TEXT NULL DEFAULT NULL'); } catch (Throwable $e) {}
$columns = array_column($pdo->query('SHOW COLUMNS FROM transactions')->fetchAll(PDO::FETCH_ASSOC), 'Field');
$referenceColumn = in_array('reference', $columns, true) ? 'reference' : (in_array('tx_reference', $columns, true) ? 'tx_reference' : null);
if (!$referenceColumn) {
    flw4_out(['success' => false, 'message' => 'The transactions table needs a reference column before Flutterwave v4 can be used.'], 500);
}
try {
    if ($referenceColumn) {
        $notes = $isVerification ? 'withdraw_verification' : null;
        $pdo->prepare("INSERT INTO transactions (user_id,type,amount,method,status,`{$referenceColumn}`,dep_notes) VALUES (?,'Deposit',?,'Flutterwave V4','Pending',?,?)")
            ->execute([$userId, $amount, $reference, $notes]);
    }
} catch (Throwable $e) {
    flw4_out(['success' => false, 'message' => 'Could not prepare the deposit. Please try again.'], 500);
}

// Obtain a short-lived OAuth token required by all Flutterwave v4 API calls.
$tokenRequest = curl_init('https://idp.flutterwave.com/realms/flutterwave/protocol/openid-connect/token');
curl_setopt_array($tokenRequest, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query(['client_id' => $clientId, 'client_secret' => $clientSecret, 'grant_type' => 'client_credentials']),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
]);
$tokenBody = curl_exec($tokenRequest);
$tokenCode = curl_getinfo($tokenRequest, CURLINFO_HTTP_CODE);
$tokenError = curl_error($tokenRequest);
curl_close($tokenRequest);
$tokenResponse = json_decode((string)$tokenBody, true) ?: [];
$accessToken = (string)($tokenResponse['access_token'] ?? '');
if ($tokenError !== '' || $tokenCode < 200 || $tokenCode >= 300 || $accessToken === '') {
    flw4_out(['success' => false, 'message' => flw4_gateway_error($tokenResponse, 'Could not authenticate with Flutterwave. Check the v4 Client ID, Client Secret, and environment.')], 502);
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = preg_replace('/[^A-Za-z0-9.:-]/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
$redirectUrl = $host !== '' ? $scheme . '://' . $host . '/deposit.php?flw4_ref=' . rawurlencode($reference) : '';
$baseUrl = rtrim(ps_flutterwave_v4_base_url($pdo), '/');
$customer = [
    'email' => $email,
    'name' => ['first' => substr($name, 0, 60), 'last' => 'Customer'],
    'phone' => ['country_code' => $countryCode, 'number' => $phone],
    'meta' => ['user_id' => (string)$userId, 'purpose' => 'wallet_funding'],
];

// Flutterwave's documented V4 Mobile Money flow is customer → payment method
// → charge. It is used for GHS instead of relying on the optional orchestrator.
if ($currency === 'GHS') {
    $customerResult = flw4_v4_post($baseUrl . '/customers', $accessToken, $customer, 'flw4-customer-' . bin2hex(random_bytes(8)));
    if (!flw4_v4_success($customerResult)) {
        $response = $customerResult['response'];
        $gatewayFailure = $customerResult['error'] !== '' ? 'Connection to Flutterwave failed.' : flw4_gateway_error($response, 'Flutterwave could not create the customer.');
    } else {
        $methodResult = flw4_v4_post($baseUrl . '/payment-methods', $accessToken, $paymentMethod, 'flw4-method-' . bin2hex(random_bytes(8)));
        if (!flw4_v4_success($methodResult)) {
            $response = $methodResult['response'];
            $gatewayFailure = $methodResult['error'] !== '' ? 'Connection to Flutterwave failed.' : flw4_gateway_error($response, 'Flutterwave could not create the mobile-money payment method.');
        } else {
            $chargeRequest = [
                'amount' => $amount,
                'currency' => $currency,
                'reference' => $reference,
                'customer_id' => (string)$customerResult['response']['data']['id'],
                'payment_method_id' => (string)$methodResult['response']['data']['id'],
                'meta' => ['user_id' => (string)$userId, 'purpose' => 'wallet_funding'],
            ];
            if ($redirectUrl !== '') $chargeRequest['redirect_url'] = $redirectUrl;
            $chargeResult = flw4_v4_post($baseUrl . '/charges', $accessToken, $chargeRequest, 'flw4-charge-' . bin2hex(random_bytes(8)));
            $response = $chargeResult['response'];
            $gatewayFailure = !flw4_v4_success($chargeResult)
                ? ($chargeResult['error'] !== '' ? 'Connection to Flutterwave failed.' : flw4_gateway_error($response, 'Flutterwave could not create the mobile-money charge.'))
                : '';
        }
    }
} else {
    // OPay retains the orchestrator route because its V4 flow does not create a
    // reusable mobile-money payment method.
    $chargeRequest = ['amount' => $amount, 'currency' => $currency, 'reference' => $reference, 'payment_method' => $paymentMethod, 'customer' => $customer];
    if ($redirectUrl !== '') $chargeRequest['redirect_url'] = $redirectUrl;
    $chargeResult = flw4_v4_post($baseUrl . '/orchestration/direct-charges', $accessToken, $chargeRequest, 'flw4-charge-' . bin2hex(random_bytes(8)));
    $response = $chargeResult['response'];
    $gatewayFailure = !flw4_v4_success($chargeResult)
        ? ($chargeResult['error'] !== '' ? 'Connection to Flutterwave failed.' : flw4_gateway_error($response, 'Flutterwave could not create the OPay charge.'))
        : '';
}

if ($gatewayFailure !== '') {
    if ($referenceColumn) {
        try { $pdo->prepare("UPDATE transactions SET status='Failed' WHERE `{$referenceColumn}`=?")->execute([$reference]); } catch (Throwable $e) {}
    }
    flw4_out(['success' => false, 'message' => $gatewayFailure], 502);
}

$nextAction = $response['data']['next_action'] ?? [];
$redirect = (string)($nextAction['redirect_url']['url'] ?? '');
$instructions = $nextAction['payment_instructions'] ?? $nextAction['payment_instruction'] ?? null;
if (is_array($instructions)) $instructions = $instructions['note'] ?? $instructions['message'] ?? 'Approve the payment on your device.';
flw4_out([
    'success' => true,
    'reference' => $reference,
    'charge_id' => (string)($response['data']['id'] ?? ''),
    'status' => (string)($response['data']['status'] ?? 'pending'),
    'redirect_url' => $redirect,
    'instructions' => is_string($instructions) ? $instructions : '',
    'message' => 'Approve the payment on your device. Your wallet will be credited once Flutterwave confirms it.',
]);
