<?php
// odds/moolre_webhook.php
// Full rewrite — robust reference lookup + extensive logging
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/payment_gateway_helper.php';
require_once __DIR__ . '/subadmin_deposit_helper.php';

$LOG = __DIR__ . '/moolre_webhook_debug.log';
function wLog($msg) {
    global $LOG;
    file_put_contents($LOG, '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
}

// 1. Read raw POST body
$rawBody = file_get_contents('php://input');
wLog("=== NEW WEBHOOK HIT === raw body length: " . strlen($rawBody ?: ''));
wLog("RAW BODY: " . $rawBody);

if (empty($rawBody)) {
    wLog("REJECTED: empty body");
    http_response_code(400);
    exit('No payload');
}

// Log all headers for debugging
$headers = function_exists('getallheaders') ? getallheaders() : [];
wLog("HEADERS: " . json_encode($headers));

// 2. Signature check (log but don't reject — many Moolre sandbox setups skip signature)
$moolreGhsSecret = ps_moolre_setting($pdo, 'ghs_webhook_secret');
$moolreNgnSecret = ps_moolre_setting($pdo, 'ngn_webhook_secret');

$sigHeader = '';
foreach ($headers as $k => $v) {
    if (strtolower($k) === 'x-moolre-signature') { $sigHeader = $v; break; }
}
wLog("Signature header: '$sigHeader'");

if ($moolreGhsSecret === '' && $moolreNgnSecret === '') {
    wLog("REJECTED: missing Moolre webhook secrets");
    http_response_code(500);
    exit('Webhook not configured');
}

if (!$sigHeader) {
    wLog("REJECTED: missing signature header");
    http_response_code(401);
    exit('Missing signature');
}

if ($sigHeader) {
    $expGhs = hash_hmac('sha512', $rawBody, $moolreGhsSecret);
    $expNgn = hash_hmac('sha512', $rawBody, $moolreNgnSecret);
    if (!hash_equals($expGhs, $sigHeader) && !hash_equals($expNgn, $sigHeader)) {
        wLog("REJECTED: signature mismatch");
        http_response_code(401);
        exit('Invalid signature');
    } else {
        wLog("Signature OK");
    }
}

// 3. Decode Payload
$event = json_decode($rawBody, true);
if (!$event) {
    wLog("REJECTED: cannot JSON-decode body");
    http_response_code(400);
    exit('Bad JSON');
}
wLog("Decoded event: " . json_encode($event));

// Moolre uses 'event' or 'type' field
$eventName = $event['event'] ?? ($event['type'] ?? '');
wLog("Event name: '$eventName'");

$validEvents = ['payment.success', 'charge.success', 'payment.completed'];
if (!in_array($eventName, $validEvents)) {
    wLog("IGNORED: event '$eventName' is not a success event");
    http_response_code(200);
    exit('OK');
}

$data = $event['data'] ?? [];
wLog("Data object: " . json_encode($data));

// Moolre puts YOUR reference in 'externalref', their own ref in 'reference'
// Try both so we cover all cases
$reference = $data['externalref'] ?? ($data['external_reference'] ?? ($data['reference'] ?? ''));
$amount    = floatval($data['amount'] ?? 0);
$currency  = $data['currency'] ?? '';
wLog("Parsed — reference: '$reference' | amount: $amount | currency: '$currency'");

if (!$reference) {
    wLog("ERROR: no reference found in payload data keys: " . implode(', ', array_keys($data)));
    http_response_code(200); // 200 so Moolre stops retrying this bad payload
    exit('No reference');
}

if ($amount <= 0) {
    wLog("ERROR: amount is zero or negative");
    http_response_code(200);
    exit('Bad amount');
}

// 4. Find reference column name
$refColName = null;
try {
    $cols = array_column($pdo->query("SHOW COLUMNS FROM transactions")->fetchAll(), "Field");
    $refColName = in_array('reference', $cols) ? 'reference'
                : (in_array('tx_reference', $cols) ? 'tx_reference' : null);
    wLog("Reference column in DB: '$refColName'");
} catch (Exception $e) {
    wLog("ERROR: SHOW COLUMNS failed: " . $e->getMessage());
    http_response_code(500);
    exit('DB Error');
}

if (!$refColName) {
    wLog("ERROR: no reference column found in transactions table");
    http_response_code(500);
    exit('DB Error');
}

// 5. Lookup the pending transaction
try {
    $dup = $pdo->prepare("SELECT id, user_id, status, amount FROM transactions WHERE `{$refColName}` = ? LIMIT 1");
    $dup->execute([$reference]);
    $tx = $dup->fetch();
    wLog("DB lookup for ref='$reference': " . json_encode($tx));
} catch (Exception $e) {
    wLog("ERROR: DB lookup failed: " . $e->getMessage());
    http_response_code(500);
    exit('DB Error');
}

if (!$tx) {
    wLog("WARNING: Transaction not found for reference '$reference'. Will check if it's a Moolre internal ref...");
    // Moolre sometimes sends their internal ref as 'reference' and puts our ref in metadata
    $metaRef = $data['metadata']['order_ref'] ?? ($event['metadata']['order_ref'] ?? '');
    wLog("Trying metadata.order_ref: '$metaRef'");
    if ($metaRef) {
        try {
            $dup2 = $pdo->prepare("SELECT id, user_id, status, amount FROM transactions WHERE `{$refColName}` = ? LIMIT 1");
            $dup2->execute([$metaRef]);
            $tx = $dup2->fetch();
            wLog("DB lookup for metaRef='$metaRef': " . json_encode($tx));
            if ($tx) $reference = $metaRef;
        } catch (Exception $e) {
            wLog("ERROR: metadata ref lookup failed: " . $e->getMessage());
        }
    }
    if (!$tx) {
        wLog("FATAL: Transaction not found in DB — ref='$reference' metaRef='$metaRef'");
        http_response_code(200);
        exit('Not found');
    }
}

if ($tx['status'] === 'Completed') {
    $commission = ps_award_subadmin_deposit_commission($pdo, (int)$tx['user_id'], (float)$tx['amount'], (int)$tx['id'], 'moolre_webhook_completed');
    wLog("SKIPPED: Already credited (ref='$reference') commission=" . json_encode($commission));
    http_response_code(200);
    exit('Already done');
}

$userId = (int)$tx['user_id'];
$txId   = (int)$tx['id'];
// Use the amount from the webhook (what Moolre confirmed), fall back to what was stored
$creditAmount = ($amount > 0) ? $amount : (float)$tx['amount'];
wLog("Will credit user #$userId with amount=$creditAmount (txId=$txId)");

// 6. Fetch user info for email
$email = ''; $username = '';
try {
    $uStmt = $pdo->prepare("SELECT email, username FROM users WHERE id = ? LIMIT 1");
    $uStmt->execute([$userId]);
    if ($uRow = $uStmt->fetch()) {
        $email    = $uRow['email'];
        $username = $uRow['username'];
    }
} catch (Exception $e) { wLog("WARNING: user fetch failed: " . $e->getMessage()); }

// 7. Credit the user
try {
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?")->execute([$creditAmount, $userId]);
    $pdo->prepare("UPDATE transactions SET status = 'Completed', amount = ?, method = 'Moolre' WHERE id = ?")->execute([$creditAmount, $txId]);
    $pdo->commit();
    wLog("SUCCESS: Credited GHS/NGN $creditAmount to user #$userId ref=$reference");
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    wLog("ERROR: DB credit failed: " . $e->getMessage());
    http_response_code(500);
    exit('DB error');
}

// 8. Send email (optional)
try {
    require_once __DIR__ . '/mailer.php';
    $uRow2 = $pdo->prepare("SELECT balance FROM users WHERE id = ? LIMIT 1");
    $uRow2->execute([$userId]);
    $newBal = (float)($uRow2->fetchColumn() ?: 0);
    if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sw_email_deposit($email, $username, $creditAmount, $reference, $newBal);
        wLog("Email sent to $email");
    }
} catch (Throwable $me) {
    wLog("WARNING: Email failed: " . $me->getMessage());
}

// 9. Agent commission
$commission = ps_award_subadmin_deposit_commission($pdo, $userId, $creditAmount, $txId, 'moolre_webhook');
wLog("Commission result: " . json_encode($commission));

wLog("=== DONE ===");
http_response_code(200);
exit('OK');
