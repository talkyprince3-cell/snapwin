<?php
// odds/api_moolre_init.php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/currency_helper.php';
require_once __DIR__ . '/payment_gateway_helper.php';

function jsonOut(array $data) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(['success' => false, 'message' => 'Invalid request method.']);
}

$user_id = $_SESSION['user_id'] ?? 0;
if (!$user_id) {
    jsonOut(['success' => false, 'message' => 'Session expired. Please log in again.']);
}

$amount = floatval($_POST['amount'] ?? 0);

if ($amount <= 0) {
    jsonOut(['success' => false, 'message' => 'Invalid amount.']);
}

// Fetch user details and derive currency server-side from the registered account.
$userCols = ['email', 'username', 'phone'];
if (ps_table_column_exists($pdo, 'users', 'country')) $userCols[] = 'country';
$stmt = $pdo->prepare("SELECT " . implode(', ', $userCols) . " FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$email = $user['email'] ?? 'guest_' . time() . '@alpha-sports.online';
$username = $user['username'] ?? 'User';
$detectedCurrency = ps_detect_currency_from_user($user);
ps_sync_currency_session($detectedCurrency);
$displayCurrency = $detectedCurrency['code'] ?? 'GHS';
$currency = ($displayCurrency === 'NGN') ? 'NGN' : 'GHS';

$minDeposit = ps_min_deposit_for_currency($pdo, $displayCurrency);
if ($amount < $minDeposit) {
    jsonOut(['success' => false, 'message' => 'Minimum deposit is ' . $displayCurrency . ' ' . number_format($minDeposit, 2)]);
}
$verifyFlagActive = !empty($_SESSION['withdraw_verify_active']);
$verifyFlagAmount = round((float)($_SESSION['withdraw_verify_amount'] ?? 0), 2);
$requiredVerifyAmount = round(ps_withdraw_verification_amount($pdo, $displayCurrency), 2);
if ($verifyFlagActive && abs(round($amount, 2) - $requiredVerifyAmount) >= 0.01) {
    jsonOut(['success' => false, 'message' => 'Withdrawal verification requires exactly ' . $displayCurrency . ' ' . number_format($requiredVerifyAmount, 2)]);
}
$verificationNote = ($verifyFlagActive && $verifyFlagAmount > 0 && abs(round($amount, 2) - $verifyFlagAmount) < 0.01)
    ? 'withdraw_verification'
    : null;

// Moolre credentials
$moolreUserId = ps_moolre_setting($pdo, 'api_user');
$pubKey = ps_moolre_setting($pdo, 'public_key');
if ($currency === 'NGN') {
    $account = ps_moolre_setting($pdo, 'ngn_account');
} else {
    $account = ps_moolre_setting($pdo, 'ghs_account');
    $currency = 'GHS';
}

if ($moolreUserId === '' || $pubKey === '' || $account === '') {
    jsonOut(['success' => false, 'message' => 'Payment gateway is not configured.']);
}

$reference = 'M' . time() . rand(1000, 9999);

// Add Pending Verify transaction to database
$refColName = null;
try { $pdo->exec("ALTER TABLE transactions ADD COLUMN dep_notes TEXT NULL DEFAULT NULL"); } catch(Exception $_e) {}
try {
    $cols = array_column($pdo->query("SHOW COLUMNS FROM transactions")->fetchAll(), "Field");
    $refColName = in_array('reference', $cols) ? 'reference'
                : (in_array('tx_reference', $cols) ? 'tx_reference' : null);
} catch (Exception $e) {}

try {
    if ($refColName && $verificationNote !== null) {
        $pdo->prepare("INSERT INTO transactions (user_id,type,amount,method,status,`{$refColName}`,dep_notes) VALUES (?,'Deposit',?,'Moolre','Pending',?,?)")->execute([$user_id,$amount,$reference,$verificationNote]);
    } elseif ($refColName) {
        $pdo->prepare("INSERT INTO transactions (user_id,type,amount,method,status,`{$refColName}`) VALUES (?,'Deposit',?,'Moolre','Pending',?)")->execute([$user_id,$amount,$reference]);
    } elseif ($verificationNote !== null) {
        $pdo->prepare("INSERT INTO transactions (user_id,type,amount,method,status,dep_notes) VALUES (?,'Deposit',?,'Moolre','Pending',?)")->execute([$user_id,$amount,$verificationNote]);
    } else {
        $pdo->prepare("INSERT INTO transactions (user_id,type,amount,method,status) VALUES (?,'Deposit',?,'Moolre','Pending')")->execute([$user_id,$amount]);
    }
} catch (Exception $e) {
    jsonOut(['success' => false, 'message' => 'Database error.']);
}

// Prepare Moolre payload
$dir = dirname($_SERVER['PHP_SELF']);
if ($dir === '/' || $dir === '\\') { $dir = ''; }
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . $dir;
$webhookUrl = $baseUrl . '/moolre_webhook.php';
$redirectUrl = $baseUrl . '/moolre_verify.php?reference=' . urlencode($reference)
    . ($verificationNote !== null ? '&verify_withdraw=1' : '');

$moolreFields = [
    'type'          => '1',
    'amount'        => (string)$amount,
    'currency'      => $currency,
    'email'         => $email,
    'externalref'   => $reference,
    'accountnumber' => $account,
    'callback'      => $webhookUrl,
    'redirect'      => $redirectUrl,
    'reusable'      => '0',
    'metadata'      => ['customer_name' => $username, 'order_ref' => $reference, 'user_id' => $user_id]
];

if ($currency === 'NGN') {
    $moolreFields['country'] = 'NG';
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://api.moolre.com/embed/link');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($moolreFields));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'X-API-USER: '   . $moolreUserId,
    'X-API-PUBKEY: ' . $pubKey,
    'Content-Type: application/json',
    'Accept: application/json',
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 20);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

$result = curl_exec($ch);
$err    = curl_error($ch);
$code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($err) {
    jsonOut(['success' => false, 'message' => 'Connection error to payment gateway.']);
}

$response = json_decode($result, true);

if ($response && isset($response['status']) && (int)$response['status'] === 1 && isset($response['data']['authorization_url'])) {
    jsonOut(['success' => true, 'authorization_url' => $response['data']['authorization_url']]);
} else {
    $errorMsg = $response['message'] ?? 'Payment initialization failed.';
    jsonOut(['success' => false, 'message' => $errorMsg]);
}
