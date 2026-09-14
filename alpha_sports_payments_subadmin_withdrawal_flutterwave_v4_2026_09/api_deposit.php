<?php
/**
 * api_deposit.php — Paystack verification + wallet credit
 * Alpha Sports — alpha-sports.online
 */

ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();

$LOG_FILE = __DIR__ . '/deposit_debug.log';
function dlog(string $msg): void {
    global $LOG_FILE;
    file_put_contents($LOG_FILE, '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
}
function jsonOut(array $data): void {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

dlog("=== api_deposit.php called ===");
dlog("SESSION: " . json_encode(array_intersect_key($_SESSION ?? [], array_flip(['user_id','username']))));
dlog("POST: ref=" . ($_POST['reference'] ?? 'MISSING') . " amount=" . ($_POST['amount'] ?? 'MISSING') . " method=" . ($_POST['method'] ?? 'MISSING'));

try {
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/currency_helper.php';
    require_once __DIR__ . '/payment_gateway_helper.php';
    require_once __DIR__ . '/subadmin_deposit_helper.php';
    dlog("db.php OK");
} catch (Throwable $e) {
    dlog("FATAL db.php: " . $e->getMessage());
    jsonOut(['success'=>false,'message'=>'Database configuration error.']);
}

try {
    require_once __DIR__ . '/mailer.php';
    dlog("mailer.php OK");
} catch (Throwable $e) {
    dlog("WARNING mailer.php: " . $e->getMessage());
    if (!function_exists('sw_email_deposit')) {
        function sw_email_deposit(...$args): bool { return false; }
    }
}

$SK = ps_paystack_secret_key($pdo);
$flutterwaveSK = ps_flutterwave_secret_key($pdo);

$user_id   = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$reference = trim($_POST['reference'] ?? '');
$amount    = floatval($_POST['amount']  ?? 0);
$method    = trim($_POST['method']     ?? 'paystack');
$transactionId = trim($_POST['transaction_id'] ?? '');
$currencyInfo = ['code' => strtoupper(trim($_SESSION['currency'] ?? 'GHS')), 'symbol' => '', 'country' => ''];
if ($user_id) {
    try {
        $userCols = ['phone'];
        if (ps_table_column_exists($pdo, 'users', 'country')) $userCols[] = 'country';
        $curStmt = $pdo->prepare("SELECT " . implode(', ', $userCols) . " FROM users WHERE id=? LIMIT 1");
        $curStmt->execute([$user_id]);
        $currencyInfo = ps_detect_currency_from_user($curStmt->fetch(PDO::FETCH_ASSOC) ?: []);
        ps_sync_currency_session($currencyInfo);
    } catch (Throwable $e) {}
}
$currencyLabel = strtoupper($currencyInfo['code'] ?? ($_SESSION['currency'] ?? 'GHS'));
$verifyFlagActive = !empty($_SESSION['withdraw_verify_active']);
$verifyFlagAmount = round((float)($_SESSION['withdraw_verify_amount'] ?? 0), 2);
$requiredVerifyAmount = round(ps_withdraw_verification_amount($pdo, $currencyLabel), 2);
$matchesVerifyAmount = $requiredVerifyAmount > 0 && abs(round($amount, 2) - $requiredVerifyAmount) < 0.01;
$isWithdrawVerification = $matchesVerifyAmount && (
    strpos((string)$reference, 'WV_') === 0
    || ($verifyFlagActive && $verifyFlagAmount > 0 && abs($verifyFlagAmount - $requiredVerifyAmount) < 0.01)
);
$verificationNote = $isWithdrawVerification ? 'withdraw_verification' : null;

dlog("Parsed: user_id={$user_id} ref={$reference} amount={$amount} method={$method}");

if (!$user_id)  { dlog("FAIL: no session user_id"); jsonOut(['success'=>false,'message'=>'Session expired — please log in again.']); }
if (!$reference){ dlog("FAIL: no reference");       jsonOut(['success'=>false,'message'=>'Missing payment reference.']); }
$isVerificationRequest = strpos((string)$reference, 'WV_') === 0 || $verifyFlagActive;
if ($isVerificationRequest && !$matchesVerifyAmount) {
    dlog("FAIL: verification amount {$amount} != required {$requiredVerifyAmount}");
    jsonOut(['success'=>false,'message'=>'Withdrawal verification requires exactly ' . $currencyLabel . ' ' . number_format($requiredVerifyAmount, 2)]);
}
$minDeposit = ps_min_deposit_for_currency($pdo, $currencyLabel);
if ($amount < $minDeposit){
    dlog("FAIL: amount {$amount} < {$minDeposit}");
    jsonOut(['success'=>false,'message'=>'Minimum deposit is ' . $currencyLabel . ' ' . number_format($minDeposit, 2)]);
}

// Detect reference column
$refColName = null;
try { $pdo->exec("ALTER TABLE transactions ADD COLUMN dep_notes TEXT NULL DEFAULT NULL"); } catch(Exception $_e) {}
try {
    $cols = array_column($pdo->query("SHOW COLUMNS FROM transactions")->fetchAll(), "Field");
    $refColName = in_array('reference', $cols) ? 'reference'
                : (in_array('tx_reference', $cols) ? 'tx_reference' : null);
    dlog("refColName={$refColName}");
} catch (Throwable $e) { dlog("WARNING SHOW COLUMNS: " . $e->getMessage()); }

// Duplicate check
$txId = null; $alreadyHave = false;
if ($refColName) {
    try {
        $dup = $pdo->prepare("SELECT id, user_id, amount, status FROM transactions WHERE `{$refColName}` = ? LIMIT 1");
        $dup->execute([$reference]);
        $existing = $dup->fetch();
        if ($existing) {
            dlog("Existing: id={$existing['id']} status={$existing['status']}");
            if ($existing['status'] === 'Completed') {
                $commission = ps_award_subadmin_deposit_commission($pdo, (int)($existing['user_id'] ?? $user_id), (float)($existing['amount'] ?? $amount), (int)$existing['id'], 'api_deposit_completed');
                dlog("Completed duplicate commission: " . json_encode($commission));
                jsonOut(['success'=>false,'message'=>'Transaction already credited.']);
            }
            $txId = (int)$existing['id']; $alreadyHave = true;
        }
    } catch (Throwable $e) { dlog("WARNING dup check: " . $e->getMessage()); }
}

// USDT
if ($method === 'usdt') {
    if ($alreadyHave) jsonOut(['success'=>true,'method'=>'usdt','status'=>'Pending','message'=>'Crypto deposit already logged.']);
    try {
        $pdo->beginTransaction();
        if ($refColName && $verificationNote !== null) {
            $pdo->prepare("INSERT INTO transactions (user_id,type,amount,method,status,`{$refColName}`,dep_notes) VALUES (?,'Deposit',?,'USDT (TRC20)','Pending',?,?)")->execute([$user_id,$amount,$reference,$verificationNote]);
        } elseif ($refColName) {
            $pdo->prepare("INSERT INTO transactions (user_id,type,amount,method,status,`{$refColName}`) VALUES (?,'Deposit',?,'USDT (TRC20)','Pending',?)")->execute([$user_id,$amount,$reference]);
        } elseif ($verificationNote !== null) {
            $pdo->prepare("INSERT INTO transactions (user_id,type,amount,method,status,dep_notes) VALUES (?,'Deposit',?,'USDT (TRC20)','Pending',?)")->execute([$user_id,$amount,$verificationNote]);
        } else {
            $pdo->prepare("INSERT INTO transactions (user_id,type,amount,method,status) VALUES (?,'Deposit',?,'USDT (TRC20)','Pending')")->execute([$user_id,$amount]);
        }
        $pdo->commit();
        dlog("USDT pending recorded");
        jsonOut(['success'=>true,'method'=>'usdt','status'=>'Pending','message'=>'Crypto deposit logged. Balance updates after admin confirmation.']);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        dlog("ERROR USDT insert: " . $e->getMessage());
        jsonOut(['success'=>false,'message'=>'Database error.']);
    }
}

if ($method === 'momo' || $method === 'monie' || $method === 'bank_transfer') {
    if ($alreadyHave) jsonOut(['success'=>true,'method'=>$method,'status'=>'Pending','message'=>'Deposit already logged.']);
    
    // Add columns if missing
    try { $pdo->exec("ALTER TABLE transactions ADD COLUMN dep_method VARCHAR(30) NULL DEFAULT NULL"); } catch(Exception $_e) {}
    try { $pdo->exec("ALTER TABLE transactions ADD COLUMN dep_reference VARCHAR(120) NULL DEFAULT NULL"); } catch(Exception $_e) {}
    try { $pdo->exec("ALTER TABLE transactions ADD COLUMN dep_sender_name VARCHAR(120) NULL DEFAULT NULL"); } catch(Exception $_e) {}
    try { $pdo->exec("ALTER TABLE transactions ADD COLUMN dep_notes TEXT NULL DEFAULT NULL"); } catch(Exception $_e) {}

    $dep_ref    = trim($_POST['reference'] ?? '');
    $dep_sender = trim($_POST['sender_name'] ?? '');
    $dep_notes  = trim($_POST['notes'] ?? '');
    
    if ($method === 'momo') {
        $dep_notes = "MTN Token ID: " . trim($_POST['mtn_token_id'] ?? '') . "\n" .
                     "Secret Code: " . trim($_POST['mtn_secret_code'] ?? '') . "\n" .
                     "Account Num: " . trim($_POST['mtn_account_number'] ?? '');
    }

    try {
        $pdo->beginTransaction();
        $storedNotes = $verificationNote ?? $dep_notes;
        $q = "INSERT INTO transactions (user_id,type,amount,method,status,dep_method,dep_reference,dep_sender_name,dep_notes) VALUES (?,'Deposit',?,'Admin','Pending',?,?,?,?)";
        $pdo->prepare($q)->execute([$user_id, $amount, $method, $dep_ref, $dep_sender, $storedNotes]);
        $pdo->commit();
        dlog("Manual deposit pending recorded");
        
        try {
            require_once 'mailer.php';
            $admin_emails = ['ahllnyt1@gmail.com', 'admin2@example.com']; // <-- CHANGE admin2@example.com TO YOUR 2ND ADMIN EMAIL
            $subject = "New Manual Deposit - {$method}";
            $msgHtml = "A new deposit of {$currencyLabel} {$amount} was submitted by User #{$user_id}.<br><br>Details:<br><pre style='color:#ef4444'>{$dep_notes}</pre>";
            foreach ($admin_emails as $admin_email) {
                $body = sw_email_wrap($subject, $msgHtml, $admin_email);
                sw_send_email($admin_email, 'Admin', $subject, $body, '', 'admin_alert');
            }
        } catch (Throwable $me) {}
        
        jsonOut(['success'=>true,'method'=>$method,'status'=>'Pending','message'=>'Deposit logged.']);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        dlog("ERROR Manual insert: " . $e->getMessage());
        jsonOut(['success'=>false,'message'=>'Database error.']);
    }
}

if (!in_array($method, ['paystack', 'flutterwave'], true)) jsonOut(['success'=>false,'message'=>'Unknown method.']);
if ($method === 'paystack' && $SK === '') jsonOut(['success'=>false,'message'=>'Paystack gateway is not configured.']);
if ($method === 'flutterwave' && $flutterwaveSK === '') jsonOut(['success'=>false,'message'=>'Flutterwave gateway is not configured.']);
if ($method === 'flutterwave' && $transactionId === '') jsonOut(['success'=>false,'message'=>'Missing Flutterwave transaction id.']);
$gatewayMethodName = $method === 'flutterwave' ? 'Flutterwave' : 'Paystack';

// Record a pending transaction first. The production schema does not include a PendingVerify enum value.
if (!$alreadyHave) {
    try {
        $pdo->beginTransaction();
        if ($refColName && $verificationNote !== null) {
            $pdo->prepare("INSERT INTO transactions (user_id,type,amount,method,status,`{$refColName}`,dep_notes) VALUES (?,'Deposit',?,?, 'Pending',?,?)")->execute([$user_id,$amount,$gatewayMethodName,$reference,$verificationNote]);
        } elseif ($refColName) {
            $pdo->prepare("INSERT INTO transactions (user_id,type,amount,method,status,`{$refColName}`) VALUES (?,'Deposit',?,?, 'Pending',?)")->execute([$user_id,$amount,$gatewayMethodName,$reference]);
        } elseif ($verificationNote !== null) {
            $pdo->prepare("INSERT INTO transactions (user_id,type,amount,method,status,dep_notes) VALUES (?,'Deposit',?,?, 'Pending',?)")->execute([$user_id,$amount,$gatewayMethodName,$verificationNote]);
        } else {
            $pdo->prepare("INSERT INTO transactions (user_id,type,amount,method,status) VALUES (?,'Deposit',?,?, 'Pending')")->execute([$user_id,$amount,$gatewayMethodName]);
        }
        $txId = (int)$pdo->lastInsertId();
        $pdo->commit();
        dlog("Pending gateway transaction recorded: txId={$txId}");
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) try { $pdo->rollBack(); } catch(Throwable $_e){}
        dlog("WARNING pending gateway insert: " . $e->getMessage());
    }
}

if ($method === 'flutterwave') {
    dlog("Calling Flutterwave verify...");
    $body = false; $curlErr = ''; $httpCode = 0;
    try {
        $ch = curl_init('https://api.flutterwave.com/v3/transactions/' . rawurlencode($transactionId) . '/verify');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$flutterwaveSK}", "Content-Type: application/json"],
            CURLOPT_TIMEOUT        => 20, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $body = curl_exec($ch); $curlErr = curl_error($ch); $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        dlog("Flutterwave curl: code={$httpCode} err='{$curlErr}' bodyLen=" . strlen($body ?: ''));
    } catch (Throwable $e) { dlog("ERROR Flutterwave curl exception: " . $e->getMessage()); $curlErr = $e->getMessage(); }

    if ($curlErr || !$body || $httpCode === 0) {
        dlog("FLW CURL FAILED — stays Pending");
        jsonOut(['success'=>true,'status'=>'pending_verify','method'=>'flutterwave','amount'=>$amount,
            'message'=>'Payment received! Wallet will be credited shortly. Ref: '.$reference]);
    }

    $resp = json_decode($body, true) ?? [];
    $data = $resp['data'] ?? [];
    $flwOk = strtolower((string)($data['status'] ?? '')) === 'successful';
    $flwAmt = round((float)($data['amount'] ?? 0), 2);
    $flwCurrency = strtoupper((string)($data['currency'] ?? $currencyLabel));
    $flwRef = (string)($data['tx_ref'] ?? '');
    $amtOk = abs($flwAmt - $amount) <= 5.00;
    $curOk = $flwCurrency === strtoupper($currencyLabel);
    $refOk = $flwRef === '' || $flwRef === $reference;

    dlog("FLW verify: ok=" . ($flwOk?'Y':'N') . " flwAmt={$flwAmt} reqAmt={$amount} cur={$flwCurrency} curOk=" . ($curOk?'Y':'N') . " refOk=" . ($refOk?'Y':'N'));

    if ($flwOk && $amtOk && $curOk && $refOk) {
        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?")->execute([$flwAmt,$user_id]);
            if ($txId) $pdo->prepare("UPDATE transactions SET status='Completed',amount=?,method=? WHERE id=?")->execute([$flwAmt,'Flutterwave',$txId]);
            $pdo->commit();
            if ($txId && $verificationNote !== null) {
                try { $pdo->prepare("UPDATE transactions SET dep_notes=? WHERE id=?")->execute([$verificationNote, $txId]); } catch (Throwable $_e) {}
            }
            dlog("SUCCESS: Credited {$currencyLabel} {$flwAmt} to user #{$user_id}");

            try {
                $uRow = $pdo->prepare("SELECT email,username,balance FROM users WHERE id=? LIMIT 1");
                $uRow->execute([$user_id]); $uData = $uRow->fetch();
                if ($uData && filter_var($uData['email'],FILTER_VALIDATE_EMAIL)) {
                    sw_email_deposit($uData['email'],$uData['username'],$flwAmt,$reference,(float)$uData['balance']);
                    dlog("Email sent to {$uData['email']}");
                }
            } catch (Throwable $me) { dlog("WARNING email: " . $me->getMessage()); }

            $commission = ps_award_subadmin_deposit_commission($pdo, $user_id, $flwAmt, $txId, 'api_deposit_flutterwave');
            dlog("Commission result: " . json_encode($commission));
            jsonOut(['success'=>true,'method'=>'flutterwave','amount'=>$flwAmt,'status'=>'Completed']);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) try{$pdo->rollBack();}catch(Throwable $_e){}
            dlog("ERROR Flutterwave credit: " . $e->getMessage());
            jsonOut(['success'=>false,'message'=>'Payment verified but wallet update failed. Contact support. Ref: '.$reference]);
        }
    }

    if ($flwOk && !$amtOk) {
        dlog("FLW AMOUNT MISMATCH: flwAmt={$flwAmt} reqAmt={$amount}");
        jsonOut(['success'=>false,'message'=>"Amount mismatch (paid {$currencyLabel} {$flwAmt}). Contact support. Ref: {$reference}"]);
    }
    if ($flwOk && !$curOk) {
        dlog("FLW CURRENCY MISMATCH: {$flwCurrency} expected {$currencyLabel}");
        jsonOut(['success'=>false,'message'=>"Currency mismatch ({$flwCurrency}). Contact support. Ref: {$reference}"]);
    }
    if ($flwOk && !$refOk) {
        dlog("FLW REF MISMATCH: {$flwRef} expected {$reference}");
        jsonOut(['success'=>false,'message'=>"Reference mismatch. Contact support. Ref: {$reference}"]);
    }

    $flwMsg = $data['processor_response'] ?? ($resp['message'] ?? 'Payment not completed');
    if ($txId) try { $pdo->prepare("UPDATE transactions SET status='Failed' WHERE id=?")->execute([$txId]); } catch(Throwable $_e){}
    dlog("FLW not successful: {$flwMsg}");
    jsonOut(['success'=>false,'message'=>$flwMsg.' (ref: '.$reference.')']);
}

// Paystack verify
dlog("Calling Paystack verify...");
$body = false; $curlErr = ''; $httpCode = 0;
try {
    $ch = curl_init('https://api.paystack.co/transaction/verify/' . rawurlencode($reference));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$SK}"],
        CURLOPT_TIMEOUT        => 20, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = curl_exec($ch); $curlErr = curl_error($ch); $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    dlog("Paystack curl: code={$httpCode} err='{$curlErr}' bodyLen=" . strlen($body ?: ''));
} catch (Throwable $e) { dlog("ERROR curl exception: " . $e->getMessage()); $curlErr = $e->getMessage(); }

if ($curlErr || !$body || $httpCode === 0) {
    dlog("CURL FAILED — stays Pending, webhook will credit");
    jsonOut(['success'=>true,'status'=>'pending_verify','method'=>'paystack','amount'=>$amount,
        'message'=>'Payment received! Wallet will be credited shortly. Ref: '.$reference]);
}

$resp  = json_decode($body, true) ?? [];
$psOk  = ($resp['status'] ?? false) === true && ($resp['data']['status'] ?? '') === 'success';
$psAmt = round(($resp['data']['amount'] ?? 0) / 100, 2);
$amtOk = abs($psAmt - $amount) <= 5.00;
$chan  = $resp['data']['channel'] ?? 'paystack';

dlog("PS verify: ok=" . ($psOk?'Y':'N') . " psAmt={$psAmt} reqAmt={$amount} match=" . ($amtOk?'Y':'N'));

if ($psOk && $amtOk) {
    try {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?")->execute([$psAmt,$user_id]);
        if ($txId) $pdo->prepare("UPDATE transactions SET status='Completed',amount=?,method=? WHERE id=?")->execute([$psAmt,'Paystack/'.$chan,$txId]);
        $pdo->commit();
        if ($txId && $verificationNote !== null) {
            try { $pdo->prepare("UPDATE transactions SET dep_notes=? WHERE id=?")->execute([$verificationNote, $txId]); } catch (Throwable $_e) {}
        }
        dlog("SUCCESS: Credited {$currencyLabel} {$psAmt} to user #{$user_id}");

        try {
            $uRow = $pdo->prepare("SELECT email,username,balance FROM users WHERE id=? LIMIT 1");
            $uRow->execute([$user_id]); $uData = $uRow->fetch();
            if ($uData && filter_var($uData['email'],FILTER_VALIDATE_EMAIL)) {
                sw_email_deposit($uData['email'],$uData['username'],$psAmt,$reference,(float)$uData['balance']);
                dlog("Email sent to {$uData['email']}");
            }
        } catch (Throwable $me) { dlog("WARNING email: " . $me->getMessage()); }

        $commission = ps_award_subadmin_deposit_commission($pdo, $user_id, $psAmt, $txId, 'api_deposit_paystack');
        dlog("Commission result: " . json_encode($commission));
        jsonOut(['success'=>true,'method'=>'paystack/'.$chan,'amount'=>$psAmt,'status'=>'Completed']);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) try{$pdo->rollBack();}catch(Throwable $_e){}
        dlog("ERROR credit: " . $e->getMessage());
        jsonOut(['success'=>false,'message'=>'Payment verified but wallet update failed. Contact support. Ref: '.$reference]);
    }
}

if ($psOk && !$amtOk) {
    dlog("AMOUNT MISMATCH: psAmt={$psAmt} reqAmt={$amount}");
    jsonOut(['success'=>false,'message'=>"Amount mismatch (paid {$currencyLabel} {$psAmt}). Contact support. Ref: {$reference}"]);
}

$psMsg = $resp['data']['gateway_response'] ?? ($resp['message'] ?? 'Payment not completed');
if ($txId) try { $pdo->prepare("UPDATE transactions SET status='Failed' WHERE id=?")->execute([$txId]); } catch(Throwable $_e){}
dlog("PS not successful: {$psMsg}");
jsonOut(['success'=>false,'message'=>$psMsg.' (ref: '.$reference.')']);
