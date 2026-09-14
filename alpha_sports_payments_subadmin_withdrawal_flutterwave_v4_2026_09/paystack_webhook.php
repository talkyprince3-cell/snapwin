<?php
// paystack_webhook.php — Paystack server-to-server event handler
// ─────────────────────────────────────────────────────────────────
// Register this URL in your Paystack Dashboard:
//   Dashboard → Settings → API Keys & Webhooks → Webhook URL
//   URL: https://alpha-sports.online/paystack_webhook
//
// This fires EVEN IF the user closes their browser mid-payment.
// It is the reliable fallback when the browser callback fails.
// ─────────────────────────────────────────────────────────────────

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/payment_gateway_helper.php';
require_once __DIR__ . '/subadmin_deposit_helper.php';

// ── 1. Read raw POST body ─────────────────────────────────────────
$rawBody = file_get_contents('php://input');
if (empty($rawBody)) {
    http_response_code(400);
    exit('No payload');
}

// ── 2. Verify Paystack signature ──────────────────────────────────
//   Your Paystack SECRET key is used as the HMAC key.
//   Paystack sends X-Paystack-Signature header with each webhook.
$sk        = ps_paystack_secret_key($pdo);
$signature = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';
$expected  = hash_hmac('sha512', $rawBody, $sk);

if ($sk === '') {
    error_log('[paystack_webhook] Missing Paystack secret key');
    http_response_code(500);
    exit('Payment gateway not configured');
}

if (!hash_equals($expected, $signature)) {
    error_log('[paystack_webhook] Invalid signature — possible spoofed request');
    http_response_code(401);
    exit('Invalid signature');
}

// ── 3. Decode payload ─────────────────────────────────────────────
$event = json_decode($rawBody, true);
if (!$event || !isset($event['event'])) {
    http_response_code(400);
    exit('Bad payload');
}

// ── 4. Only handle successful charge events ───────────────────────
if ($event['event'] !== 'charge.success') {
    // Acknowledge other events with 200 but take no action
    http_response_code(200);
    exit('OK');
}

$data      = $event['data'] ?? [];
$reference = $data['reference'] ?? '';
$email     = $data['customer']['email'] ?? '';
$psAmount  = round(($data['amount'] ?? 0) / 100, 2); // kobo → GHS
$channel   = $data['channel'] ?? 'paystack';

// SITE FILTER: only process transactions from this platform (FUND_ / USDT_ prefix)
if (!empty($reference) && strpos($reference, 'FUND_') !== 0 && strpos($reference, 'USDT_') !== 0 && strpos($reference, 'WV_FUND_') !== 0 && strpos($reference, 'WV_USDT_') !== 0) {
    error_log("[alpha_webhook] Ignored foreign ref={$reference}");
    http_response_code(200);
    exit('OK - not our transaction');
}

if (!$reference || $psAmount <= 0) {
    error_log('[paystack_webhook] Missing reference or amount in payload');
    http_response_code(400);
    exit('Missing data');
}

$verificationNote = strpos((string)$reference, 'WV_') === 0 ? 'withdraw_verification' : null;

// ── 5. Detect transactions table schema ───────────────────────────
$refColName = null;
try {
    $cols       = array_column($pdo->query("SHOW COLUMNS FROM transactions")->fetchAll(), "Field");
    if (!in_array('dep_notes', $cols, true)) {
        try {
            $pdo->exec("ALTER TABLE transactions ADD COLUMN dep_notes TEXT NULL DEFAULT NULL");
            $cols[] = 'dep_notes';
        } catch (Throwable $e) {}
    }
    $refColName = in_array('reference', $cols)    ? 'reference'
                : (in_array('tx_reference', $cols) ? 'tx_reference' : null);
} catch (Exception $e) {
    error_log('[paystack_webhook] SHOW COLUMNS failed: ' . $e->getMessage());
}

// ── 6. Existing transaction check ─────────────────────────────────
$existingTx = null;
if ($refColName) {
    try {
        $dup = $pdo->prepare("SELECT id, user_id, status, amount FROM transactions WHERE `{$refColName}` = ? LIMIT 1");
        $dup->execute([$reference]);
        $existingTx = $dup->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($existingTx && $existingTx['status'] === 'Completed') {
            $commission = ps_award_subadmin_deposit_commission($pdo, (int)$existingTx['user_id'], (float)$existingTx['amount'], (int)$existingTx['id'], 'paystack_webhook_completed');
            error_log("[paystack_webhook] Ref {$reference} already credited — commission=" . json_encode($commission));
            http_response_code(200);
            exit('Already processed');
        }
    } catch (Exception $e) {
        error_log('[paystack_webhook] Duplicate check failed: ' . $e->getMessage());
    }
}

// ── 7. Find the user by email ─────────────────────────────────────
$userId = $existingTx ? (int)$existingTx['user_id'] : null;
if ($email) {
    try {
        $uStmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $uStmt->execute([$email]);
        $uRow   = $uStmt->fetch();
        $userId = $userId ?: ($uRow['id'] ?? null);
    } catch (Exception $e) {
        error_log('[paystack_webhook] User lookup failed: ' . $e->getMessage());
    }
}

if (!$userId) {
    // Can't match user — log it and tell Paystack we received it
    error_log("[paystack_webhook] No user found for email={$email} ref={$reference} amount={$psAmount}");
    // Return 200 so Paystack does not keep retrying
    http_response_code(200);
    exit('User not found — logged');
}

// ── 8. Credit balance + log transaction ───────────────────────────
try {
    $pdo->beginTransaction();

    if ($existingTx && $refColName) {
        $lockTx = $pdo->prepare("SELECT status FROM transactions WHERE id = ? FOR UPDATE");
        $lockTx->execute([(int)$existingTx['id']]);
        if (($lockTx->fetchColumn() ?: '') === 'Completed') {
            $pdo->commit();
            $commission = ps_award_subadmin_deposit_commission($pdo, (int)$userId, (float)($existingTx['amount'] ?? $psAmount), (int)$existingTx['id'], 'paystack_webhook_locked_completed');
            error_log('[paystack_webhook] Locked completed commission result: ' . json_encode($commission));
            http_response_code(200);
            exit('Already processed');
        }

        $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?")
            ->execute([$psAmount, $userId]);
        $pdo->prepare("UPDATE transactions SET user_id=?, amount=?, method=?, status='Completed' WHERE id=?")
            ->execute([$userId, $psAmount, 'Paystack/' . $channel, (int)$existingTx['id']]);
        if ($verificationNote) {
            $pdo->prepare("UPDATE transactions SET dep_notes=? WHERE id=?")
                ->execute([$verificationNote, (int)$existingTx['id']]);
        }
        $txId = (int)$existingTx['id'];
    } else {
        $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?")
            ->execute([$psAmount, $userId]);

        if ($refColName) {
            try {
                $pdo->prepare("INSERT INTO transactions (user_id, type, amount, method, status, `{$refColName}`, dep_notes, created_at)
                               VALUES (?, 'Deposit', ?, ?, 'Completed', ?, ?, NOW())")
                    ->execute([$userId, $psAmount, 'Paystack/' . $channel, $reference, $verificationNote]);
            } catch (Exception $ie) {
                $pdo->prepare("INSERT INTO transactions (user_id, type, amount, method, status, dep_notes, created_at)
                               VALUES (?, 'Deposit', ?, ?, 'Completed', ?, NOW())")
                    ->execute([$userId, $psAmount, 'Paystack/' . $channel, $verificationNote]);
            }
        } else {
            $pdo->prepare("INSERT INTO transactions (user_id, type, amount, method, status, dep_notes, created_at)
                           VALUES (?, 'Deposit', ?, ?, 'Completed', ?, NOW())")
                ->execute([$userId, $psAmount, 'Paystack/' . $channel, $verificationNote]);
        }
        $txId = (int)$pdo->lastInsertId();
    }

    $pdo->commit();

    error_log("[paystack_webhook] Credited GHS {$psAmount} to user #{$userId} ref={$reference}");

    // -- Send deposit confirmation email
    try {
        $uRow2 = $pdo->prepare("SELECT balance FROM users WHERE id = ? LIMIT 1");
        $uRow2->execute([$userId]);
        $newBal = (float)($uRow2->fetchColumn() ?: 0);
        if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $uName2 = $pdo->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
            $uName2->execute([$userId]);
            $uUsername = $uName2->fetchColumn() ?: '';
            sw_email_deposit($email, $uUsername, $psAmount, $reference, $newBal);
        }
    } catch (Throwable $me) {
        error_log("[paystack_webhook] Email send failed: " . $me->getMessage());
    }

    // ── Agent Commission ─────────────────────────────────────────
    $commission = ps_award_subadmin_deposit_commission($pdo, (int)$userId, (float)$psAmount, (int)($txId ?: 0), 'paystack_webhook');
    error_log('[paystack_webhook] Commission result: ' . json_encode($commission));

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[paystack_webhook] DB error ref=' . $reference . ': ' . $e->getMessage());
    // Return 500 so Paystack retries later
    http_response_code(500);
    exit('DB error');
}

// ── 9. Acknowledge receipt ────────────────────────────────────────
http_response_code(200);
exit('OK');
?>
