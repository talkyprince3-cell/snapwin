<?php
// odds/moolre_verify.php
// Verify Moolre payment and credit user — also serves as fallback if webhook was slow
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/payment_gateway_helper.php';
require_once __DIR__ . '/subadmin_deposit_helper.php';

$LOG = __DIR__ . '/moolre_webhook_debug.log';
function vLog($msg) {
    global $LOG;
    file_put_contents($LOG, '[' . date('Y-m-d H:i:s') . '] [VERIFY] ' . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
}

$reference = trim($_GET['reference'] ?? '');
vLog("=== VERIFY PAGE LOADED ref='$reference'");

if (!$reference) {
    vLog("No reference — redirecting to deposit");
    header("Location: deposit");
    exit;
}

// ── 1. Check if webhook already credited ──────────────────────────────────
$refColName = null;
$cols = [];
try {
    $cols = array_column($pdo->query("SHOW COLUMNS FROM transactions")->fetchAll(), "Field");
    $refColName = in_array('reference', $cols) ? 'reference'
                : (in_array('tx_reference', $cols) ? 'tx_reference' : null);
} catch (Exception $e) { vLog("SHOW COLUMNS error: " . $e->getMessage()); }

$status   = 'Pending';
$txId     = null;
$userId   = $_SESSION['user_id'] ?? 0;
$txAmount = 0;
$isWithdrawalVerification = (int)($_GET['verify_withdraw'] ?? 0) === 1;

if ($refColName) {
    try {
        $hasDepNotes = in_array('dep_notes', $cols, true);
        $noteSql = $hasDepNotes ? ', dep_notes' : '';
        $stmt = $pdo->prepare("SELECT id, user_id, status, amount{$noteSql} FROM transactions WHERE `{$refColName}` = ? LIMIT 1");
        $stmt->execute([$reference]);
        $row = $stmt->fetch();
        vLog("DB row: " . json_encode($row));
        if ($row) {
            $status   = $row['status'];
            $txId     = (int)$row['id'];
            $userId   = (int)$row['user_id'];
            $txAmount = (float)$row['amount'];
            $isWithdrawalVerification = $isWithdrawalVerification
                || stripos((string)($row['dep_notes'] ?? ''), 'withdraw_verification') !== false;
        }
    } catch (Exception $e) { vLog("DB lookup error: " . $e->getMessage()); }
}

// If already completed by webhook, just redirect
if ($status === 'Completed') {
    if ($userId && $txId && $txAmount > 0) {
        $commission = ps_award_subadmin_deposit_commission($pdo, (int)$userId, (float)$txAmount, (int)$txId, 'moolre_verify_completed');
        vLog("Already completed commission result: " . json_encode($commission));
    }
    vLog("Already completed — redirecting after payment");
    header('Location: ' . ($isWithdrawalVerification ? 'withdraw.php?verification_return=1' : 'transactions'));
    exit;
}

// ── 2. Webhook hasn't fired yet — call Moolre verify API ourselves ─────────
vLog("Not yet completed, calling Moolre verify API...");

$moolreUserId = ps_moolre_setting($pdo, 'api_user');
$pubKeyGhs = ps_moolre_setting($pdo, 'public_key');
$pubKeyNgn = ps_moolre_setting($pdo, 'public_key');

$verifyResult   = null;
$verifySuccess  = false;
$verifiedAmount = 0;

// Try GHS wallet first, then NGN
foreach (['GHS' => $pubKeyGhs, 'NGN' => $pubKeyNgn] as $curr => $pubKey) {
    if ($moolreUserId === '' || $pubKey === '') {
        vLog("Skipping Moolre verify [$curr]: missing gateway config");
        continue;
    }
    $ch = curl_init('https://api.moolre.com/embed/verify/' . urlencode($reference));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'X-API-USER: '   . $moolreUserId,
        'X-API-PUBKEY: ' . $pubKey,
        'Content-Type: application/json',
        'Accept: application/json',
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $result   = curl_exec($ch);
    $err      = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    vLog("Moolre verify [$curr] HTTP $httpCode err='$err' body=$result");

    if (!$err && $result) {
        $json = json_decode($result, true);
        if ($json && isset($json['status']) && (int)$json['status'] === 1) {
            $txData = $json['data'] ?? $json['transaction'] ?? [];
            $txStatus = strtolower($txData['status'] ?? '');
            vLog("Moolre status from API: '$txStatus'");
            if (in_array($txStatus, ['success', 'completed', 'paid', '1', 'successful'])) {
                $verifySuccess  = true;
                $verifiedAmount = floatval($txData['amount'] ?? $txAmount);
                $verifyResult   = $json;
                break;
            }
        }
    }
}

if ($verifySuccess && $userId && $txId) {
    vLog("Moolre confirmed payment — crediting user #$userId amount=$verifiedAmount");
    try {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?")->execute([$verifiedAmount, $userId]);
        $pdo->prepare("UPDATE transactions SET status='Completed', amount=?, method='Moolre' WHERE id=?")->execute([$verifiedAmount, $txId]);
        $pdo->commit();
        vLog("SUCCESS: Credited $verifiedAmount to user #$userId");
        $commission = ps_award_subadmin_deposit_commission($pdo, (int)$userId, (float)$verifiedAmount, (int)$txId, 'moolre_verify');
        vLog("Commission result: " . json_encode($commission));
        // Send email
        try {
            require_once __DIR__ . '/mailer.php';
            $uRow = $pdo->prepare("SELECT email, username, balance FROM users WHERE id = ? LIMIT 1");
            $uRow->execute([$userId]);
            if ($uData = $uRow->fetch()) {
                if ($uData['email'] && filter_var($uData['email'], FILTER_VALIDATE_EMAIL)) {
                    sw_email_deposit($uData['email'], $uData['username'], $verifiedAmount, $reference, (float)$uData['balance']);
                }
            }
        } catch (Throwable $me) { vLog("Email error: " . $me->getMessage()); }
        // Redirect immediately
        header('Location: ' . ($isWithdrawalVerification ? 'withdraw.php?verification_return=1' : 'transactions?deposited=1'));
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        vLog("ERROR crediting: " . $e->getMessage());
    }
} else {
    vLog("Moolre verify did not return success — verifySuccess=" . ($verifySuccess ? 'true' : 'false'));
}

// ── 3. Show waiting page with auto-refresh ────────────────────────────────
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifying Payment...</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Display', sans-serif;
            background: #0a0810; color: #fff;
            display: flex; align-items: center; justify-content: center;
            min-height: 100vh; padding: 20px;
        }
        .card {
            background: #0f0c14; border: 1px solid rgba(255,255,255,0.07);
            padding: 40px 32px; border-radius: 24px;
            max-width: 380px; width: 100%;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0,0,0,0.5);
        }
        .spinner {
            width: 56px; height: 56px;
            border: 3.5px solid rgba(23,201,100,0.15);
            border-top: 3.5px solid #17C964;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin: 0 auto 24px;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        h2 { font-size: 20px; font-weight: 800; color: #FFB800; margin-bottom: 12px; }
        p  { font-size: 14px; color: #7a6e8a; line-height: 1.6; margin-bottom: 28px; }
        .ref-box {
            background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08);
            border-radius: 12px; padding: 10px 14px;
            font-size: 11px; color: #7a6e8a; margin-bottom: 28px;
            word-break: break-all;
        }
        .btn {
            display: inline-block; background: #17C964; color: #fff;
            text-decoration: none; padding: 14px 28px;
            border-radius: 14px; font-weight: 700; font-size: 15px;
            transition: all 0.2s; margin-bottom: 12px; width: 100%;
        }
        .btn:hover { transform: translateY(-1px); box-shadow: 0 8px 24px rgba(23,201,100,0.3); }
        .btn-sec {
            display: inline-block; color: #7a6e8a;
            text-decoration: none; font-size: 13px; font-weight: 600;
        }
        .countdown { font-size: 12px; color: #7a6e8a; margin-top: 16px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="spinner"></div>
        <h2>⏳ Confirming Payment</h2>
        <p>Your payment has been received by Moolre. We're waiting for final confirmation before crediting your wallet.</p>
        <div class="ref-box">Ref: <?php echo htmlspecialchars($reference); ?></div>
        <a href="<?php echo $isWithdrawalVerification ? 'withdraw.php?verification_return=1' : 'transactions'; ?>" class="btn"><?php echo $isWithdrawalVerification ? 'Check Verification Progress' : 'View Transactions'; ?></a><br>
        <span class="btn-sec">Page refreshes automatically...</span>
        <p class="countdown" id="cd">Rechecking in <span id="secs">10</span>s</p>
    </div>
    <script>
        // Auto-refresh every 10 seconds to recheck if webhook has fired
        let s = 10;
        const interval = setInterval(() => {
            s--;
            document.getElementById('secs').textContent = s;
            if (s <= 0) {
                clearInterval(interval);
                window.location.reload();
            }
        }, 1000);
    </script>
</body>
</html>
