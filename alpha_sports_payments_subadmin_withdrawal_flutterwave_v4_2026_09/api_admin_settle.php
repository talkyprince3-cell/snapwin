<?php
// api_admin_settle.php — Settle tickets + Fund/Edit users
session_start();
require_once 'db.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/currency_helper.php';
require_once __DIR__ . '/payment_gateway_helper.php';
require_once __DIR__ . '/subadmin_deposit_helper.php';
header('Content-Type: application/json');

$uid = $_SESSION['user_id'] ?? 0;
if (empty($_SESSION['main_admin_authenticated'])) {
    try {
        $r2 = $pdo->prepare("SELECT value FROM admin_settings WHERE `key`='admin_user_ids'");
        $r2->execute();
        $rawIds   = trim($r2->fetchColumn() ?: '');
        $adminIds = $rawIds ? array_filter(array_map('trim', explode(',', $rawIds))) : [];
    } catch(Exception $e) { $adminIds = []; }
    if (!in_array('1', $adminIds, true)) $adminIds[] = '1';
    if (!in_array((string)$uid, $adminIds, true)) {
        echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit;
    }
}

// Auto-create commission columns if missing
try { $pdo->exec("ALTER TABLE transactions ADD COLUMN comm_rate  decimal(5,2) DEFAULT 15.00"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE transactions ADD COLUMN comm_amount decimal(10,2) DEFAULT 0.00"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE transactions ADD COLUMN comm_paid   tinyint(1) DEFAULT 0"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE users ADD COLUMN is_verified TINYINT(1) NOT NULL DEFAULT 0"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE users ADD COLUMN is_banned TINYINT(1) NOT NULL DEFAULT 0"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE users ADD COLUMN aml_verified TINYINT(1) NOT NULL DEFAULT 0"); } catch(Exception $e){}

// Auto-create missing admin_matches columns for older schemas
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN home_logo varchar(500) DEFAULT ''"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN away_logo varchar(500) DEFAULT ''"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN league VARCHAR(100) DEFAULT 'Featured'"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches MODIFY COLUMN match_time VARCHAR(20) DEFAULT ''"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN match_date DATE DEFAULT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN odds_home DECIMAL(6,2) DEFAULT 2.10"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN odds_draw DECIMAL(6,2) DEFAULT 3.40"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN odds_away DECIMAL(6,2) DEFAULT 3.60"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN score_home INT(3) DEFAULT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN score_away INT(3) DEFAULT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN status VARCHAR(30) DEFAULT 'Not Started'"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN elapsed INT(3) DEFAULT 0"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN is_active TINYINT(1) DEFAULT 1"); } catch(Exception $e){ if(strpos($e->getMessage(), 'Duplicate') === false) error_log('Migration error (is_active): ' . $e->getMessage()); }
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN is_pinned TINYINT(1) DEFAULT 1"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN pin_order INT(4) DEFAULT 0"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN odds_locked TINYINT(1) DEFAULT 0"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN odds_manual_locked TINYINT(1) NOT NULL DEFAULT 0"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN odds_auto_enabled TINYINT(1) NOT NULL DEFAULT 1"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN odds_suspended_until DATETIME DEFAULT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN live_at datetime NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN end_at datetime NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN show_in_today tinyint(1) DEFAULT 1"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN first_half_mins int DEFAULT 45"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN ht_break_mins int DEFAULT 15"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN duration_mins int DEFAULT 50"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN goal_minutes text DEFAULT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN final_score_home int(3) DEFAULT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN final_score_away int(3) DEFAULT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN sub_admin_id INT DEFAULT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN ai_generated TINYINT(1) NOT NULL DEFAULT 0"); } catch(Exception $e){}

// Auto-create deposit detail columns
try { $pdo->exec("ALTER TABLE transactions ADD COLUMN dep_method VARCHAR(30) NULL DEFAULT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE transactions ADD COLUMN dep_reference VARCHAR(120) NULL DEFAULT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE transactions ADD COLUMN dep_sender_name VARCHAR(120) NULL DEFAULT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE transactions ADD COLUMN dep_notes TEXT NULL DEFAULT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE transactions ADD COLUMN tx_reference VARCHAR(160) NULL DEFAULT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE transactions ADD COLUMN reference VARCHAR(160) NULL DEFAULT NULL"); } catch(Exception $e){}
// Migrate legacy 'Agent Self Fund' type values first, then narrow the ENUM
try { $pdo->exec("UPDATE transactions SET type='AgentSelfFund' WHERE type='Agent Self Fund' OR (type='Deposit' AND (method='Agent Self Fund' OR payment_method='Agent Self Fund' OR dep_method='Agent Self Fund'))"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE transactions MODIFY COLUMN type ENUM('Deposit','Withdrawal','AgentSelfFund') NOT NULL"); } catch(Exception $e){}

// Ensure core tables exist so stats queries never crash on a fresh DB
try { $pdo->exec("CREATE TABLE IF NOT EXISTS admin_matches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    home_team VARCHAR(100) NOT NULL,
    away_team VARCHAR(100) NOT NULL,
    home_logo VARCHAR(500) DEFAULT '',
    away_logo VARCHAR(500) DEFAULT '',
    league VARCHAR(100) DEFAULT 'Featured',
    match_time VARCHAR(20) DEFAULT '',
    match_date DATE DEFAULT NULL,
    odds_home DECIMAL(6,2) DEFAULT 2.10,
    odds_draw DECIMAL(6,2) DEFAULT 3.40,
    odds_away DECIMAL(6,2) DEFAULT 3.60,
    score_home INT(3) DEFAULT NULL,
    score_away INT(3) DEFAULT NULL,
    status VARCHAR(30) DEFAULT 'Not Started',
    elapsed INT(3) DEFAULT 0,
    odds_locked TINYINT(1) DEFAULT 0,
    is_pinned TINYINT(1) DEFAULT 1,
    is_active TINYINT(1) DEFAULT 1,
    pin_order INT(4) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    live_at DATETIME DEFAULT NULL,
    end_at DATETIME DEFAULT NULL,
    show_in_today TINYINT(1) DEFAULT 1,
    first_half_mins INT DEFAULT 45,
    ht_break_mins INT DEFAULT 15,
    duration_mins INT DEFAULT 50,
    goal_minutes TEXT DEFAULT NULL,
    final_score_home INT(3) DEFAULT NULL,
    final_score_away INT(3) DEFAULT NULL,
    odds_manual_locked TINYINT(1) NOT NULL DEFAULT 0,
    odds_auto_enabled TINYINT(1) NOT NULL DEFAULT 1,
    odds_suspended_until DATETIME DEFAULT NULL,
    sub_admin_id INT DEFAULT NULL,
    ai_generated TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $e){}
try { $pdo->exec("CREATE TABLE IF NOT EXISTS admin_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL,
    source_type VARCHAR(30) DEFAULT 'manual',
    sporty_code VARCHAR(80) DEFAULT NULL,
    stake DECIMAL(10,2) DEFAULT 0.00,
    total_odds DECIMAL(10,2) DEFAULT 1.00,
    pot_win DECIMAL(10,2) DEFAULT 0.00,
    is_revealed TINYINT(1) DEFAULT 1,
    reveal_at DATETIME DEFAULT NULL,
    settled_as VARCHAR(10) DEFAULT NULL,
    min_stake DECIMAL(10,2) DEFAULT 0.00,
    sub_admin_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $e){}
try { $pdo->exec("CREATE TABLE IF NOT EXISTS tickets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    ticket_code VARCHAR(20) NOT NULL,
    booking_code VARCHAR(20) DEFAULT NULL,
    stake_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    potential_win DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    bonus_amount DECIMAL(15,2) DEFAULT 0.00,
    status ENUM('Running','Won','Lost','Void') DEFAULT 'Running',
    bet_date DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $e){}

$data   = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$action = $data['action'] ?? '';

function ps_row_currency(array $row): array {
    return ps_detect_currency_from_user([
        'phone' => $row['phone'] ?? '',
        'country' => $row['country'] ?? '',
    ]);
}

function ps_sum_rows_usd(PDO $pdo, array $rows, string $amountKey): float {
    $total = 0.0;
    foreach ($rows as $row) {
        $currency = $row['currency'] ?? $row['currency_code'] ?? ps_row_currency($row)['code'];
        $total += ps_local_to_usd((float)($row[$amountKey] ?? 0), $currency, $pdo);
    }
    return round($total, 2);
}

function ps_add_money_to_row(PDO $pdo, array $row, string $amountKey = 'amount'): array {
    $currency = $row['currency'] ?? $row['currency_code'] ?? ps_row_currency($row)['code'];
    $meta = ps_money_payload((float)($row[$amountKey] ?? 0), $currency, $pdo);
    $row['currency'] = $meta['currency'];
    $row['currency_symbol'] = $meta['symbol'];
    $row[$amountKey . '_usd'] = $meta['usd'];
    return $row;
}

function ps_user_currency_select(PDO $pdo, string $alias = 'u'): string {
    $prefix = $alias !== '' ? $alias . '.' : '';
    $phone = ps_table_column_exists($pdo, 'users', 'phone') ? "{$prefix}phone AS phone" : "'' AS phone";
    $country = ps_table_column_exists($pdo, 'users', 'country') ? "{$prefix}country AS country" : "'' AS country";
    return "{$phone}, {$country}";
}

switch ($action) {

    case 'clear_admin_history':
        if (($data['confirm'] ?? '') !== 'CLEAR HISTORY') {
            echo json_encode(['success'=>false,'message'=>'Type CLEAR HISTORY to confirm.']);
            break;
        }

        $tables = [
            'ticket_matches',
            'tickets',
            'admin_code_selections',
            'admin_codes',
            'transactions',
            'casino_game_bets',
            'casino_outcome_rules',
            'commission_payout_items',
            'commission_payout_batches',
            'sub_admin_commissions',
            'sub_admin_withdrawals',
            'notifications',
            'user_notifications',
        ];

        $cleared = [];
        $skipped = [];
        try {
            foreach ($tables as $table) {
                try {
                    $exists = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->fetchColumn();
                    if (!$exists) {
                        $skipped[] = $table;
                        continue;
                    }
                    $count = (int)$pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
                    $pdo->exec("DELETE FROM `$table`");
                    try { $pdo->exec("ALTER TABLE `$table` AUTO_INCREMENT = 1"); } catch(Exception $e) {}
                    $cleared[$table] = $count;
                } catch(Exception $e) {
                    $skipped[] = $table;
                }
            }
            echo json_encode([
                'success' => true,
                'message' => 'Admin history cleared.',
                'cleared' => $cleared,
                'skipped' => $skipped,
            ]);
        } catch(Exception $e) {
            echo json_encode(['success'=>false,'message'=>'Clear failed: '.$e->getMessage()]);
        }
        break;

    // ── Settle a ticket ───────────────────────────────────────────
    case 'settle':
        $tid     = (int)($data['ticket_id'] ?? 0);
        $outcome = $data['outcome'] ?? ''; // 'Won' or 'Lost'
        if (!$tid || !in_array($outcome, ['Won','Lost','Void'])) {
            echo json_encode(['success'=>false,'message'=>'Invalid params']); break;
        }
        // Fetch ticket
        $stmt = $pdo->prepare("SELECT * FROM tickets WHERE id=?");
        $stmt->execute([$tid]);
        $t = $stmt->fetch();
        if (!$t) { echo json_encode(['success'=>false,'message'=>'Ticket not found']); break; }

        $prevStatus = $t['status'];

        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE tickets SET status=?, settle_time=NOW() WHERE id=?")->execute([$outcome, $tid]);

            // Reverse previous credit if re-settling
            if ($prevStatus === 'Won' && $outcome !== 'Won') {
                $pdo->prepare("UPDATE users SET balance = GREATEST(0, balance - ?) WHERE id=?")->execute([$t['potential_win'], $t['user_id']]);
            }
            if ($prevStatus === 'Void' && $outcome !== 'Void') {
                $pdo->prepare("UPDATE users SET balance = GREATEST(0, balance - ?) WHERE id=?")->execute([$t['stake_amount'], $t['user_id']]);
            }
            // Apply new outcome credit
            if ($outcome === 'Won' && $prevStatus !== 'Won') {
                $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id=?")->execute([$t['potential_win'], $t['user_id']]);
            }
            if ($outcome === 'Void' && $prevStatus !== 'Void') {
                $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id=?")->execute([$t['stake_amount'], $t['user_id']]);
            }
            $pdo->commit();

            // ── Settlement email (non-fatal) ───────────────────────────────
            try {
                $seStmt = $pdo->prepare("SELECT email, username FROM users WHERE id = ? LIMIT 1");
                $seStmt->execute([$t['user_id']]);
                $seUser = $seStmt->fetch(PDO::FETCH_ASSOC);
                if ($seUser && !empty($seUser['email'])) {
                    sw_email_bet_settled($seUser['email'], $seUser['username'], [
                        'id'            => $tid,
                        'ticket_code'   => $t['ticket_code'],
                        'stake_amount'  => $t['stake_amount'],
                        'potential_win' => $t['potential_win'],
                        'bet_date'      => $t['bet_date'],
                    ], $outcome);
                }
            } catch (Exception $mailErr) { /* non-fatal */ }

            // amount_credited = what was actually added to balance this time
        $credited = 0;
        if ($outcome === 'Won' && $prevStatus !== 'Won')   $credited = (float)$t['potential_win'];
        if ($outcome === 'Void' && $prevStatus !== 'Void') $credited = (float)$t['stake_amount'];
        echo json_encode(['success'=>true,'outcome'=>$outcome,'prev'=>$prevStatus,'amount_credited'=>$credited]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        break;

    // ── Fund / deduct user balance ─────────────────────────────────
    case 'fund_user':
        $targetUid = (int)($data['user_id'] ?? 0);
        $amount    = (float)($data['amount']  ?? 0);
        $note      = trim($data['note'] ?? 'Admin adjustment');
        if (!$targetUid) { echo json_encode(['success'=>false,'message'=>'No user ID']); break; }

        $stmt = $pdo->prepare("SELECT balance FROM users WHERE id=?");
        $stmt->execute([$targetUid]);
        $user = $stmt->fetch();
        if (!$user) { echo json_encode(['success'=>false,'message'=>'User not found']); break; }

        try {
            $newBal = max(0, floatval($user['balance']) + $amount);
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE users SET balance=? WHERE id=?")->execute([$newBal, $targetUid]);

            // Log in transactions
            $type = $amount >= 0 ? 'Deposit' : 'Withdrawal';
            $pdo->prepare("INSERT INTO transactions (user_id,type,amount,method,status,tx_reference) VALUES (?,?,?,'Admin','Completed',?)")
                ->execute([$targetUid, $type, abs($amount), $note]);
            $txId = (int)$pdo->lastInsertId();

            if ($amount > 0) {
                ps_subadmin_ensure_commission_schema($pdo);
                $commission = ps_award_subadmin_deposit_commission($pdo, $targetUid, $amount, $txId, 'admin_quick_fund');
                if (($commission['reason'] ?? '') === 'error') {
                    throw new Exception('Agent commission could not be recorded; the wallet was not credited.');
                }
            }
            $pdo->commit();
            echo json_encode(['success'=>true,'new_balance'=>$newBal]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        break;

    // ── Add verified manual credit as a real deposit + agent commission ───────
    case 'add_credit':
        $targetUid = (int)($data['user_id'] ?? 0);
        $amount    = round((float)($data['amount'] ?? 0), 2);
        $note      = trim((string)($data['note'] ?? 'Manual admin credit'));
        if (!$targetUid || $amount <= 0) {
            echo json_encode(['success'=>false,'message'=>'Select a user and enter an amount greater than zero.']);
            break;
        }

        try {
            ps_subadmin_ensure_commission_schema($pdo);
            $userStmt = $pdo->prepare("SELECT id, username, balance, " . ps_user_currency_select($pdo, '') . " FROM users WHERE id=? LIMIT 1");
            $userStmt->execute([$targetUid]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) {
                echo json_encode(['success'=>false,'message'=>'User not found.']);
                break;
            }

            $txCols = $pdo->query("SHOW COLUMNS FROM transactions")->fetchAll(PDO::FETCH_COLUMN);
            $hasMethod = in_array('method', $txCols, true);
            $hasPaymentMethod = in_array('payment_method', $txCols, true);
            $hasTxRef = in_array('tx_reference', $txCols, true);
            $hasRef = in_array('reference', $txCols, true);
            $hasDepMethod = in_array('dep_method', $txCols, true);
            $hasDepRef = in_array('dep_reference', $txCols, true);
            $hasDepSender = in_array('dep_sender_name', $txCols, true);
            $hasDepNotes = in_array('dep_notes', $txCols, true);
            $hasCreated = in_array('created_at', $txCols, true);

            $reference = 'ADMIN-CREDIT-' . date('YmdHis') . '-' . $targetUid . '-' . mt_rand(1000, 9999);
            $currency = ps_row_currency($user);

            $pdo->beginTransaction();
            $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id=?")->execute([$amount, $targetUid]);

            $cols = ['user_id', 'type', 'amount', 'status'];
            $vals = [$targetUid, 'Deposit', $amount, 'Completed'];
            if ($hasMethod) { $cols[] = 'method'; $vals[] = 'Admin Credit'; }
            if ($hasPaymentMethod) { $cols[] = 'payment_method'; $vals[] = 'Admin Credit'; }
            if ($hasTxRef) { $cols[] = 'tx_reference'; $vals[] = $reference; }
            if ($hasRef) { $cols[] = 'reference'; $vals[] = $reference; }
            if ($hasDepMethod) { $cols[] = 'dep_method'; $vals[] = 'Admin Credit'; }
            if ($hasDepRef) { $cols[] = 'dep_reference'; $vals[] = $reference; }
            if ($hasDepSender) { $cols[] = 'dep_sender_name'; $vals[] = 'Admin'; }
            if ($hasDepNotes) { $cols[] = 'dep_notes'; $vals[] = $note; }
            if ($hasCreated) { $cols[] = 'created_at'; $vals[] = date('Y-m-d H:i:s'); }
            $ph = implode(',', array_fill(0, count($cols), '?'));
            $pdo->prepare("INSERT INTO transactions (`" . implode('`,`', $cols) . "`) VALUES ($ph)")->execute($vals);
            $txId = (int)$pdo->lastInsertId();

            $commission = ps_award_subadmin_deposit_commission($pdo, $targetUid, $amount, $txId, 'admin_add_credit');
            if (($commission['reason'] ?? '') === 'error') {
                throw new Exception('Agent commission could not be recorded; the wallet was not credited.');
            }
            $balStmt = $pdo->prepare("SELECT balance FROM users WHERE id=? LIMIT 1");
            $balStmt->execute([$targetUid]);
            $newBal = (float)$balStmt->fetchColumn();
            $pdo->commit();

            echo json_encode([
                'success' => true,
                'new_balance' => $newBal,
                'tx_id' => $txId,
                'reference' => $reference,
                'currency' => $currency['code'],
                'commission' => $commission,
                'message' => ($currency['code'] ?? 'GHS') . ' ' . number_format($amount, 2) . ' credited. ' .
                    (!empty($commission['awarded'])
                        ? 'Agent commission recorded: ' . ($currency['code'] ?? 'GHS') . ' ' . number_format((float)$commission['commission_amt'], 2)
                        : 'No active agent commission was found for this user.')
            ]);
        } catch(Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        break;

    // ── Admin debit: deduct balance + reverse agent commission ─────
    case 'add_debit':
        $targetUid = (int)($data['user_id'] ?? 0);
        $amount    = round((float)($data['amount'] ?? 0), 2);
        $note      = trim((string)($data['note'] ?? 'Manual admin debit'));
        if (!$targetUid || $amount <= 0) {
            echo json_encode(['success'=>false,'message'=>'Select a user and enter an amount greater than zero.']);
            break;
        }

        try {
            ps_subadmin_ensure_commission_schema($pdo);
            $userStmt = $pdo->prepare("SELECT id, username, balance, " . ps_user_currency_select($pdo, '') . " FROM users WHERE id=? LIMIT 1");
            $userStmt->execute([$targetUid]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) {
                echo json_encode(['success'=>false,'message'=>'User not found.']);
                break;
            }

            $currency    = ps_row_currency($user);
            $currCode    = $currency['code'] ?? 'GHS';
            $currentBal  = (float)$user['balance'];
            // Clamp debit so balance can't go below 0
            $debitAmt    = min($amount, $currentBal);

            $reference = 'ADMIN-DEBIT-' . date('YmdHis') . '-' . $targetUid . '-' . mt_rand(1000, 9999);

            $txCols = $pdo->query("SHOW COLUMNS FROM transactions")->fetchAll(PDO::FETCH_COLUMN);
            $hasMethod      = in_array('method',       $txCols, true);
            $hasPayMeth     = in_array('payment_method',$txCols, true);
            $hasTxRef       = in_array('tx_reference', $txCols, true);
            $hasRef         = in_array('reference',    $txCols, true);
            $hasDepMethod   = in_array('dep_method',   $txCols, true);
            $hasDepRef      = in_array('dep_reference',$txCols, true);
            $hasDepSender   = in_array('dep_sender_name',$txCols,true);
            $hasDepNotes    = in_array('dep_notes',    $txCols, true);
            $hasCreated     = in_array('created_at',   $txCols, true);

            $pdo->beginTransaction();

            // 1. Deduct user balance (floor at 0)
            $pdo->prepare("UPDATE users SET balance = GREATEST(0, balance - ?) WHERE id=?")->execute([$debitAmt, $targetUid]);

            // 2. Log withdrawal/debit transaction
            $cols = ['user_id','type','amount','status'];
            $vals = [$targetUid,'Withdrawal',$debitAmt,'Completed'];
            if ($hasMethod)    { $cols[]='method';        $vals[]='Admin Debit'; }
            if ($hasPayMeth)   { $cols[]='payment_method';$vals[]='Admin Debit'; }
            if ($hasTxRef)     { $cols[]='tx_reference';  $vals[]=$reference; }
            if ($hasRef)       { $cols[]='reference';     $vals[]=$reference; }
            if ($hasDepMethod) { $cols[]='dep_method';    $vals[]='Admin Debit'; }
            if ($hasDepRef)    { $cols[]='dep_reference'; $vals[]=$reference; }
            if ($hasDepSender) { $cols[]='dep_sender_name';$vals[]='Admin'; }
            if ($hasDepNotes)  { $cols[]='dep_notes';     $vals[]=$note; }
            if ($hasCreated)   { $cols[]='created_at';    $vals[]=date('Y-m-d H:i:s'); }
            $ph = implode(',', array_fill(0, count($cols), '?'));
            $pdo->prepare("INSERT INTO transactions (`".implode('`,`',$cols)."`) VALUES ($ph)")->execute($vals);
            $txId = (int)$pdo->lastInsertId();

            // 3. Reverse agent commission proportionally
            $commissionInfo = ['reversed'=>false,'reason'=>'no_active_subadmin','commission_amt'=>0.0];
            try {
                $agent = ps_subadmin_for_deposit_user($pdo, $targetUid);
                if ($agent) {
                    $subAdminId  = (int)$agent['id'];
                    $commPct     = ps_subadmin_effective_commission_pct($pdo, $agent);
                    $reverseComm = round($debitAmt * ($commPct / 100), 2);

                    if ($reverseComm > 0) {
                        // Deduct from GHS global balance if applicable
                        if ($currCode === 'GHS') {
                            $pdo->prepare("UPDATE sub_admins SET balance = GREATEST(0, balance - ?), total_earned = GREATEST(0, total_earned - ?) WHERE id=?")
                                ->execute([$reverseComm, $reverseComm, $subAdminId]);
                        }
                        // Deduct from currency-specific balance
                        ps_subadmin_update_currency_balance($pdo, $subAdminId, $currCode, -$debitAmt, -$reverseComm, -$reverseComm);
                        // Log a negative commission entry for audit trail
                        $pdo->prepare("INSERT INTO sub_admin_commissions (sub_admin_id, user_id, deposit_amount, commission_pct, commission_amt, tx_id, currency_code) VALUES (?,?,?,?,?,?,?)")
                            ->execute([$subAdminId, $targetUid, -$debitAmt, $commPct, -$reverseComm, $txId, $currCode]);
                        $commissionInfo = ['reversed'=>true,'reason'=>'ok','commission_amt'=>$reverseComm,'sub_admin_id'=>$subAdminId];
                    } else {
                        $commissionInfo['reason'] = 'zero_commission';
                    }
                }
            } catch(Exception $ce) {
                // Commission reversal failure is non-fatal — log but continue
                $commissionInfo['reason'] = 'error: ' . $ce->getMessage();
            }

            $balStmt = $pdo->prepare("SELECT balance FROM users WHERE id=? LIMIT 1");
            $balStmt->execute([$targetUid]);
            $newBal = (float)$balStmt->fetchColumn();
            $pdo->commit();

            $commMsg = $commissionInfo['reversed']
                ? 'Agent commission reversed: ' . $currCode . ' ' . number_format((float)$commissionInfo['commission_amt'], 2)
                : 'No agent commission to reverse (' . $commissionInfo['reason'] . ').';

            echo json_encode([
                'success'     => true,
                'new_balance' => $newBal,
                'tx_id'       => $txId,
                'reference'   => $reference,
                'currency'    => $currCode,
                'commission'  => $commissionInfo,
                'message'     => $currCode . ' ' . number_format($debitAmt, 2) . ' debited from ' . ($user['username'] ?? 'user') . '. ' . $commMsg,
            ]);
        } catch(Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        break;

    // ── Get daily deposits and commissions for a specific agent ────
    case 'get_agent_daily_payments':
        $agentId = (int)($data['agent_id'] ?? 0);
        if (!$agentId) {
            echo json_encode(['success'=>false, 'message'=>'Invalid agent ID']);
            break;
        }

        try {
            ps_subadmin_ensure_commission_schema($pdo);
            ps_align_subadmin_commission_dates($pdo);
            $effectiveCreatedSql = ps_subadmin_effective_created_sql('sac', 'tx');
            $paymentDateSql = ps_subadmin_payment_date_sql($effectiveCreatedSql);
            $stmt = $pdo->prepare("
                SELECT 
                    {$paymentDateSql} AS payment_date,
                    sac.currency_code,
                    SUM(sac.deposit_amount) AS total_deposit,
                    SUM(sac.commission_amt) AS total_commission,
                    COUNT(*) AS tx_count,
                    SUM(CASE WHEN sac.payout_paid_at IS NULL THEN 1 ELSE 0 END) AS unpaid_count
                FROM sub_admin_commissions sac
                LEFT JOIN transactions tx ON tx.id = sac.tx_id
                WHERE sac.sub_admin_id = ?
                GROUP BY {$paymentDateSql}, sac.currency_code
                ORDER BY payment_date DESC
            ");
            $stmt->execute([$agentId]);
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success'=>true, 'records'=>$records]);
        } catch(Exception $e) {
            echo json_encode(['success'=>false, 'message'=>$e->getMessage()]);
        }
        break;

    // ── Mark a specific day's agent commission as paid ─────────────
    case 'mark_daily_commission_paid':
        $agentId  = (int)($data['agent_id'] ?? 0);
        $date     = trim((string)($data['date'] ?? ''));
        $currency = trim((string)($data['currency'] ?? ''));

        if (!$agentId || empty($date) || empty($currency)) {
            echo json_encode(['success'=>false, 'message'=>'Invalid parameters']);
            break;
        }

        try {
            $effectiveCreatedSql = ps_subadmin_effective_created_sql('sac', 'tx');
            $paymentDateSql = ps_subadmin_payment_date_sql($effectiveCreatedSql);
            $stmt = $pdo->prepare("
                UPDATE sub_admin_commissions sac
                LEFT JOIN transactions tx ON tx.id = sac.tx_id
                SET sac.payout_paid_at = NOW()
                WHERE sac.sub_admin_id = ?
                  AND {$paymentDateSql} = ?
                  AND sac.currency_code = ?
                  AND sac.payout_paid_at IS NULL
            ");
            $stmt->execute([$agentId, $date, $currency]);
            $affected = $stmt->rowCount();

            echo json_encode(['success'=>true, 'message'=>"Marked {$affected} commissions as paid for {$date}"]);
        } catch(Exception $e) {
            echo json_encode(['success'=>false, 'message'=>$e->getMessage()]);
        }
        break;



    // ── Set user balance directly ──────────────────────────────────
    case 'set_balance':
        $targetUid = (int)($data['user_id'] ?? 0);
        $amount    = (float)($data['amount']  ?? 0);
        if (!$targetUid || $amount < 0) { echo json_encode(['success'=>false,'message'=>'Invalid']); break; }
        $pdo->prepare("UPDATE users SET balance=? WHERE id=?")->execute([$amount, $targetUid]);
        echo json_encode(['success'=>true,'new_balance'=>$amount]);
        break;

    // ── Get all tickets (paginated) ────────────────────────────────
    case 'get_tickets':
        $page   = max(1, (int)($data['page'] ?? 1));
        $limit  = 20;
        $offset = ($page - 1) * $limit;
        $status = $data['status'] ?? '';
        $where  = $status ? "WHERE t.status=?" : "";
        $params = $status ? [$status] : [];

        $total = $pdo->prepare("SELECT COUNT(*) FROM tickets t $where");
        $total->execute($params); $total = $total->fetchColumn();

        $stmt = $pdo->prepare("SELECT t.*,u.username FROM tickets t JOIN users u ON u.id=t.user_id $where ORDER BY t.bet_date DESC LIMIT $limit OFFSET $offset");
        $stmt->execute($params);
        $tickets = $stmt->fetchAll();

        // Fetch matches for these tickets
        if ($tickets) {
            $ids = array_column($tickets,'id');
            $ph  = implode(',', array_fill(0, count($ids), '?'));
            $ms  = $pdo->prepare("SELECT * FROM ticket_matches WHERE ticket_id IN ($ph)");
            $ms->execute($ids);
            $matchMap = [];
            foreach ($ms->fetchAll() as $m) $matchMap[$m['ticket_id']][] = $m;
            foreach ($tickets as &$t) $t['matches'] = $matchMap[$t['id']] ?? [];
        }
        echo json_encode(['success'=>true,'tickets'=>$tickets,'total'=>$total,'page'=>$page]);
        break;

    // ── Get all users ──────────────────────────────────────────────
    case 'get_users':
        try {
            $select = ['u.id', 'u.username', 'u.email'];
            foreach (['phone','balance','bonus_balance','loyalty_tier','country','is_verified','aml_verified','is_banned','status','warning_msg','created_at'] as $col) {
                if (ps_table_column_exists($pdo, 'users', $col)) {
                    $select[] = "u.`{$col}`";
                }
            }
            if (!ps_table_column_exists($pdo, 'users', 'balance')) $select[] = "0 AS balance";
            if (!ps_table_column_exists($pdo, 'users', 'created_at')) $select[] = "NULL AS created_at";

            $agentIdParts = [];
            if (ps_table_column_exists($pdo, 'users', 'sub_admin_id')) $agentIdParts[] = 'NULLIF(u.sub_admin_id,0)';
            if (ps_table_column_exists($pdo, 'users', 'linked_agent_id')) $agentIdParts[] = 'NULLIF(u.linked_agent_id,0)';
            $agentIdSql = $agentIdParts ? 'COALESCE(' . implode(',', $agentIdParts) . ')' : 'NULL';

            $hasSubAdmins = false;
            try {
                $hasSubAdmins = (bool)$pdo->query("SHOW TABLES LIKE " . $pdo->quote('sub_admins'))->fetchColumn();
            } catch(Exception $e) {}

            if ($hasSubAdmins) {
                $saUsername = ps_table_column_exists($pdo, 'sub_admins', 'username') ? 'sa.username' : "''";
                $saEmail = ps_table_column_exists($pdo, 'sub_admins', 'email') ? 'sa.email' : "''";
                $select[] = "{$agentIdSql} AS agent_id";
                $select[] = "{$saUsername} AS agent_username";
                $select[] = "{$saEmail} AS agent_email";
                $users = $pdo->query("
                    SELECT " . implode(",\n                           ", $select) . "
                    FROM users u
                    LEFT JOIN sub_admins sa ON sa.id = {$agentIdSql}
                    ORDER BY u.id DESC
                ")->fetchAll();
            } else {
                $select[] = "NULL AS agent_id";
                $select[] = "NULL AS agent_username";
                $select[] = "NULL AS agent_email";
                $users = $pdo->query("
                    SELECT " . implode(",\n                           ", $select) . "
                    FROM users u
                    ORDER BY u.id DESC
                ")->fetchAll();
            }

            $agents = [];
            if ($hasSubAdmins) {
                try {
                    $activeWhere = ps_table_column_exists($pdo, 'sub_admins', 'is_active') ? "WHERE COALESCE(is_active,1) = 1" : "";
                    $isActiveSelect = ps_table_column_exists($pdo, 'sub_admins', 'is_active') ? "is_active" : "1 AS is_active";
                    $agents = $pdo->query("
                        SELECT id, username, email, referral_code, commission_pct, {$isActiveSelect}
                        FROM sub_admins
                        {$activeWhere}
                        ORDER BY username ASC, id ASC
                    ")->fetchAll(PDO::FETCH_ASSOC);
                } catch(Exception $e) {
                    $agents = [];
                }
            }

            foreach ($users as &$u) {
                $currency = ps_row_currency($u);
                $u['currency'] = $currency['code'];
                $u['currency_symbol'] = $currency['symbol'];
                $u['balance_usd'] = ps_local_to_usd((float)($u['balance'] ?? 0), $currency['code'], $pdo);
                if (isset($u['bonus_balance'])) {
                    $u['bonus_balance_usd'] = ps_local_to_usd((float)$u['bonus_balance'], $currency['code'], $pdo);
                }
            }
            unset($u);
            echo json_encode(['success'=>true,'users'=>$users,'agents'=>$agents]);
        } catch(Exception $e) {
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        break;

    case 'link_user_agent':
        $targetUid = (int)($data['user_id'] ?? 0);
        $agentId = (int)($data['agent_id'] ?? 0);
        if (!$targetUid) { echo json_encode(['success'=>false,'message'=>'No user selected.']); break; }

        try {
            ps_subadmin_ensure_commission_schema($pdo);

            $uStmt = $pdo->prepare("SELECT id, username, is_agent FROM users WHERE id=? LIMIT 1");
            $uStmt->execute([$targetUid]);
            $user = $uStmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) { echo json_encode(['success'=>false,'message'=>'User not found.']); break; }

            $agent = null;
            if ($agentId > 0) {
                $aStmt = $pdo->prepare("SELECT id, username, email FROM sub_admins WHERE id=? AND COALESCE(is_active,1)=1 LIMIT 1");
                $aStmt->execute([$agentId]);
                $agent = $aStmt->fetch(PDO::FETCH_ASSOC);
                if (!$agent) { echo json_encode(['success'=>false,'message'=>'Agent not found or inactive.']); break; }
            }

            $pdo->beginTransaction();
            if ($agentId > 0) {
                if ((int)($user['is_agent'] ?? 0) === 1) {
                    $pdo->prepare("UPDATE users SET sub_admin_id=? WHERE id=?")->execute([$agentId, $targetUid]);
                } else {
                    $pdo->prepare("UPDATE users SET sub_admin_id=?, linked_agent_id=? WHERE id=?")->execute([$agentId, $agentId, $targetUid]);
                }
            } else {
                if ((int)($user['is_agent'] ?? 0) === 1) {
                    $pdo->prepare("UPDATE users SET sub_admin_id=NULL WHERE id=?")->execute([$targetUid]);
                } else {
                    $pdo->prepare("UPDATE users SET sub_admin_id=NULL, linked_agent_id=NULL WHERE id=?")->execute([$targetUid]);
                }
            }

            $stats = ps_rebuild_user_subadmin_deposit_commissions($pdo, $targetUid, $agentId, 50000);
            $pdo->commit();

            $msg = $agentId > 0
                ? "User linked to " . ($agent['email'] ?: $agent['username']) . ". Agent commissions rebuilt."
                : "User unlinked from agent. Unpaid agent commissions rebuilt.";
            echo json_encode(['success'=>true,'message'=>$msg,'stats'=>$stats]);
        } catch(Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        break;

    // ── Edit user (full profile) ───────────────────────────────────
    case 'edit_user':
        $target = (int)($data['user_id'] ?? 0);
        if (!$target) { echo json_encode(['success'=>false,'message'=>'No user ID']); break; }
        try {
            // ── Discover which columns actually exist in `users` ──────────────
            // This prevents "Unknown column" errors on installs that don't have
            // every optional column (bonus_balance, phone, country, etc.)
            $colRows = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
            $cols    = array_map('strtolower', $colRows);
            if (isset($data['verified']) && !in_array('is_verified', $cols, true)) {
                try {
                    $pdo->exec("ALTER TABLE users ADD COLUMN is_verified TINYINT(1) NOT NULL DEFAULT 0");
                    $cols[] = 'is_verified';
                } catch(Exception $e) {}
            }
            if (isset($data['banned']) && !in_array('is_banned', $cols, true)) {
                try {
                    $pdo->exec("ALTER TABLE users ADD COLUMN is_banned TINYINT(1) NOT NULL DEFAULT 0");
                    $cols[] = 'is_banned';
                } catch(Exception $e) {}
            }
            if (isset($data['verified']) && !in_array('aml_verified', $cols, true)) {
                try {
                    $pdo->exec("ALTER TABLE users ADD COLUMN aml_verified TINYINT(1) NOT NULL DEFAULT 0");
                    $cols[] = 'aml_verified';
                } catch(Exception $e) {}
            }

            // Map of: payload key => [column name, cast function]
            $fieldMap = [
                'username' => ['username',      fn($v) => (string)$v],
                'email'    => ['email',          fn($v) => (string)$v],
                'phone'    => ['phone',          fn($v) => (string)$v],
                'balance'  => ['balance',        fn($v) => (float)$v],
                'bonus'    => ['bonus_balance',  fn($v) => (float)$v],
                'tier'     => ['loyalty_tier',   fn($v) => (int)$v],
                'country'  => ['country',        fn($v) => (string)$v],
                'verified' => ['is_verified',    fn($v) => (int)$v],
                'aml_verified' => ['aml_verified', fn($v) => (int)$v],
                'banned'   => ['is_banned',      fn($v) => (int)$v],
                'status'   => ['status',         fn($v) => (string)$v],
                'warning_msg'=> ['warning_msg',  fn($v) => (string)$v],
            ];

            $fields = []; $params = [];
            foreach ($fieldMap as $key => [$col, $cast]) {
                if (!isset($data[$key])) continue;          // not sent
                if (!in_array(strtolower($col), $cols)) continue; // column missing in DB
                if ($key === 'username' && trim($data[$key]) === '') continue;
                if ($key === 'email'    && trim($data[$key]) === '') continue;
                $fields[] = "`{$col}` = ?";
                $params[] = $cast($data[$key]);
            }

            // Password is hashed separately
            if (!empty($data['password'])) {
                $fields[] = '`password` = ?';
                $params[] = password_hash($data['password'], PASSWORD_DEFAULT);
            }

            if ($fields) {
                if (isset($data['verified']) && in_array('aml_verified', $cols, true) && !isset($data['aml_verified'])) {
                    $fields[] = "`aml_verified` = ?";
                    $params[] = (int)$data['verified'];
                }
                $params[] = $target;
                $pdo->prepare("UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?")
                    ->execute($params);
            }

            // ── Handle admin toggle (always safe — uses admin_settings table) ─
            if (isset($data['make_admin'])) {
                $r = $pdo->prepare("SELECT value FROM admin_settings WHERE `key`='admin_user_ids'");
                $r->execute();
                $raw = trim($r->fetchColumn() ?: '');
                $ids = $raw ? array_filter(array_map('trim', explode(',', $raw))) : [];
                if (!in_array('1', $ids)) $ids[] = '1';
                if ($data['make_admin']) {
                    if (!in_array((string)$target, $ids)) $ids[] = (string)$target;
                } else {
                    $ids = array_filter($ids, fn($x) => $x !== (string)$target);
                }
                $newVal = implode(',', array_values($ids));
                $pdo->prepare("INSERT INTO admin_settings (`key`,`value`) VALUES ('admin_user_ids',?)
                               ON DUPLICATE KEY UPDATE `value`=?")
                    ->execute([$newVal, $newVal]);
            }

            echo json_encode(['success'=>true]);
        } catch(Exception $e) {
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        break;

    // ── Ban / Unban user ──────────────────────────────────────────
    case 'ban_user':
    case 'unban_user':
        $target = (int)($data['user_id'] ?? 0);
        if (!$target || $target == $uid) { echo json_encode(['success'=>false,'message'=>'Cannot ban yourself']); break; }
        try {
            $banned = ($action === 'ban_user') ? 1 : 0;
            try {
                $pdo->prepare("UPDATE users SET is_banned = ? WHERE id = ?")->execute([$banned, $target]);
            } catch(Exception $ce) {
                // is_banned column might not exist – add it then retry
                $pdo->exec("ALTER TABLE users ADD COLUMN is_banned TINYINT(1) DEFAULT 0");
                $pdo->prepare("UPDATE users SET is_banned = ? WHERE id = ?")->execute([$banned, $target]);
            }
            echo json_encode(['success'=>true]);
        } catch(Exception $e) {
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        break;

    // ── Delete user ───────────────────────────────────────────────
    case 'delete_user':
        $target = (int)($data['user_id'] ?? 0);
        if (!$target || $target == $uid) { echo json_encode(['success'=>false,'message'=>'Cannot delete yourself']); break; }
        try {
            $pdo->beginTransaction();
            // Delete child records first to avoid FK constraint errors
            $tidsSt = $pdo->prepare("SELECT id FROM tickets WHERE user_id = ?");
            $tidsSt->execute([$target]);
            $tids = $tidsSt->fetchAll(PDO::FETCH_COLUMN);
            if ($tids) {
                $in = implode(',', array_map('intval', $tids));
                $pdo->exec("DELETE FROM ticket_matches WHERE ticket_id IN ($in)");
            }
            $pdo->prepare("DELETE FROM tickets      WHERE user_id = ?")->execute([$target]);
            try { $pdo->prepare("DELETE FROM transactions WHERE user_id = ?")->execute([$target]); } catch(Exception $te) {}
            $pdo->prepare("DELETE FROM users         WHERE id = ?")->execute([$target]);
            $pdo->commit();
            echo json_encode(['success'=>true]);
        } catch(Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        break;

    // ── Get stats overview ─────────────────────────────────────────
    case 'stats':
        try {
            ps_repair_subadmin_deposit_accounting($pdo, 5000);
            [$todayStart, $tomorrowStart] = ps_subadmin_today_bounds();
            $currencyCols = ps_user_currency_select($pdo, 'u');
            
            $hasMeth = ps_table_column_exists($pdo, 'transactions', 'method');
            $hasPayM = ps_table_column_exists($pdo, 'transactions', 'payment_method');
            $hasDepMeth = ps_table_column_exists($pdo, 'transactions', 'dep_method');
            
            $sfConds = [];
            $sfCondsAlias = [];
            if ($hasMeth) {
                $sfConds[] = "COALESCE(method,'') <> 'Agent Self Fund'";
                $sfCondsAlias[] = "COALESCE(tx.method,'') <> 'Agent Self Fund'";
            }
            if ($hasPayM) {
                $sfConds[] = "COALESCE(payment_method,'') <> 'Agent Self Fund'";
                $sfCondsAlias[] = "COALESCE(tx.payment_method,'') <> 'Agent Self Fund'";
            }
            if ($hasDepMeth) {
                $sfConds[] = "COALESCE(dep_method,'') <> 'Agent Self Fund'";
                $sfCondsAlias[] = "COALESCE(tx.dep_method,'') <> 'Agent Self Fund'";
            }
            $sfWhere = $sfConds ? ' AND ' . implode(' AND ', $sfConds) : '';
            $sfWhereAlias = $sfCondsAlias ? ' AND ' . implode(' AND ', $sfCondsAlias) : '';

            $realDepositWhere = "tx.type='Deposit' AND " . ps_subadmin_completed_deposit_status_sql('tx') . $sfWhereAlias;
            $realDepositWhereNoAlias = "type='Deposit' AND " . ps_subadmin_completed_deposit_status_sql('') . $sfWhere;
            
            $stakeRows = $pdo->query("SELECT t.stake_amount AS amount, {$currencyCols} FROM tickets t JOIN users u ON u.id=t.user_id")->fetchAll(PDO::FETCH_ASSOC);
            $payoutRows = $pdo->query("SELECT t.potential_win AS amount, {$currencyCols} FROM tickets t JOIN users u ON u.id=t.user_id WHERE t.status='Won'")->fetchAll(PDO::FETCH_ASSOC);
            $depositRows = $pdo->query("SELECT tx.amount, {$currencyCols} FROM transactions tx JOIN users u ON u.id=tx.user_id WHERE {$realDepositWhere}")->fetchAll(PDO::FETCH_ASSOC);
            $depositTodayStmt = $pdo->prepare("SELECT tx.amount, {$currencyCols} FROM transactions tx JOIN users u ON u.id=tx.user_id WHERE {$realDepositWhere} AND tx.created_at >= ? AND tx.created_at < ?");
            $depositTodayStmt->execute([$todayStart, $tomorrowStart]);
            $depositTodayRows = $depositTodayStmt->fetchAll(PDO::FETCH_ASSOC);
            $withdrawRows = $pdo->query("SELECT tx.amount, {$currencyCols} FROM transactions tx JOIN users u ON u.id=tx.user_id WHERE tx.type='Withdrawal' AND tx.status='Pending'")->fetchAll(PDO::FETCH_ASSOC);
            $depositTodaySumStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE {$realDepositWhereNoAlias} AND created_at >= ? AND created_at < ?");
            $depositTodaySumStmt->execute([$todayStart, $tomorrowStart]);
            $depositTodayCountStmt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE {$realDepositWhereNoAlias} AND created_at >= ? AND created_at < ?");
            $depositTodayCountStmt->execute([$todayStart, $tomorrowStart]);
            $stats = [
                'users'            => (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
                'running_tickets'  => (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE status='Running'")->fetchColumn(),
                'won_tickets'      => (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE status='Won'")->fetchColumn(),
                'total_stakes'     => (float)($pdo->query("SELECT SUM(stake_amount) FROM tickets")->fetchColumn() ?: 0),
                'total_stakes_usd' => ps_sum_rows_usd($pdo, $stakeRows, 'amount'),
                'total_payouts'    => (float)($pdo->query("SELECT SUM(potential_win) FROM tickets WHERE status='Won'")->fetchColumn() ?: 0),
                'total_payouts_usd'=> ps_sum_rows_usd($pdo, $payoutRows, 'amount'),
                'total_deposits'   => (float)($pdo->query("SELECT SUM(amount) FROM transactions WHERE {$realDepositWhereNoAlias}")->fetchColumn() ?: 0),
                'total_deposits_usd' => ps_sum_rows_usd($pdo, $depositRows, 'amount'),
                'deposit_count'    => (int)$pdo->query("SELECT COUNT(*) FROM transactions WHERE {$realDepositWhereNoAlias}")->fetchColumn(),
                'deposit_pending'  => (int)$pdo->query("SELECT COUNT(*) FROM transactions WHERE type='Deposit' AND status='Pending' " . ($hasMeth ? " AND COALESCE(method,'') <> 'Agent Self Fund' AND LOWER(COALESCE(method,'')) NOT LIKE 'paystack%' AND LOWER(COALESCE(method,'')) NOT LIKE 'flutterwave%'" : ""))->fetchColumn(),
                'deposit_today'    => (float)$depositTodaySumStmt->fetchColumn(),
                'deposit_today_usd'=> ps_sum_rows_usd($pdo, $depositTodayRows, 'amount'),
                'deposit_count_today' => (int)$depositTodayCountStmt->fetchColumn(),
                'pending_withdraw' => (float)($pdo->query("SELECT SUM(amount) FROM transactions WHERE type='Withdrawal' AND status='Pending'")->fetchColumn() ?: 0),
                'pending_withdraw_usd' => ps_sum_rows_usd($pdo, $withdrawRows, 'amount'),
                'primary_currency' => 'GHS',
                'admin_matches'    => (int)$pdo->query("SELECT COUNT(*) FROM admin_matches" . (ps_table_column_exists($pdo, 'admin_matches', 'is_active') ? " WHERE is_active=1" : ""))->fetchColumn(),
                'admin_codes'      => (int)$pdo->query("SELECT COUNT(*) FROM admin_codes")->fetchColumn(),
            ];

            // ── Breakdown by Currency ─────────────────────────────────────────
            $currency_breakdown = [];
            foreach (['GHS', 'NGN'] as $c) {
                $currency_breakdown[$c] = ['deposits' => 0, 'deposits_today' => 0, 'withdrawals' => 0];
            }
            $buildBreakdown = function ($rows, $key) use (&$currency_breakdown) {
                foreach ($rows as $r) {
                    $c = ps_row_currency($r)['code'] ?? 'GHS';
                    if (!isset($currency_breakdown[$c])) $currency_breakdown[$c] = [$key => 0];
                    if (!isset($currency_breakdown[$c][$key])) $currency_breakdown[$c][$key] = 0;
                    $currency_breakdown[$c][$key] += (float)$r['amount'];
                }
            };
            $buildBreakdown($depositRows, 'deposits');
            $buildBreakdown($depositTodayRows, 'deposits_today');
            $buildBreakdown($withdrawRows, 'withdrawals');

            $stats['currency_breakdown'] = $currency_breakdown;

            echo json_encode(['success'=>true,'stats'=>$stats]);
        } catch (Exception $e) {
            error_log('Stats error: '.$e->getMessage());
            echo json_encode(['success'=>false,'message'=>'Stats error: '.$e->getMessage()]);
        }
        break;

    // ── Get deposits list ──────────────────────────────────────────
    case 'get_deposits':
        try {
            $status = $data['status'] ?? 'all';
            $limit  = (int)($data['limit'] ?? 200);
            ps_repair_subadmin_deposit_accounting($pdo, 5000);

            // ── Discover actual column names ──────────────────────────────────
            $txCols   = $pdo->query("SHOW COLUMNS FROM transactions")->fetchAll(PDO::FETCH_COLUMN);
            $hasRef   = in_array('reference',       $txCols);
            $hasTxRef = in_array('tx_reference',    $txCols);
            $hasMeth  = in_array('method',          $txCols);
            $hasPayM  = in_array('payment_method',  $txCols);
            $hasDepNotes = in_array('dep_notes',    $txCols, true);

            $refSql  = $hasRef  ? ($hasTxRef ? "COALESCE(t.reference, t.tx_reference, '')" : "t.reference")
                                : ($hasTxRef ? "t.tx_reference" : "''");
            $methSql = $hasMeth ? "t.method" : ($hasPayM ? "t.payment_method" : "''");
            $selfFundSql = [];
            if ($hasMeth) $selfFundSql[] = "COALESCE(t.method,'') <> 'Agent Self Fund'";
            if ($hasPayM) $selfFundSql[] = "COALESCE(t.payment_method,'') <> 'Agent Self Fund'";
            if (in_array('dep_method', $txCols, true)) $selfFundSql[] = "COALESCE(t.dep_method,'') <> 'Agent Self Fund'";
            $selfFundWhere = $selfFundSql ? implode(' AND ', $selfFundSql) : '1=1';

            // ── Fetch local DB deposits ───────────────────────────────────────
            $where  = "WHERE t.type='Deposit' AND {$selfFundWhere}" . ($status !== 'all' ? " AND t.status=?" : "");
            $params = $status !== 'all' ? [$status] : [];
            $params[] = $limit;

            $stmt = $pdo->prepare("
                SELECT t.id, t.amount, t.status,
                       {$refSql}  AS reference,
                       {$methSql} AS payment_method,
                       t.created_at, u.username, u.email, " . ps_user_currency_select($pdo, 'u') . ", u.id AS user_id,
                       t.dep_method, t.dep_reference, t.dep_sender_name, t.dep_notes,
                       sac.commission_amt, sa.username AS agent_username
                FROM transactions t
                JOIN users u ON u.id = t.user_id
                LEFT JOIN sub_admin_commissions sac ON sac.tx_id = t.id
                LEFT JOIN sub_admins sa ON sa.id = sac.sub_admin_id
                {$where}
                ORDER BY t.created_at DESC
                LIMIT ?
            ");
            $stmt->execute($params);
            $localRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Build a set of already-credited references for dedup
            $creditedRefs = [];
            foreach ($localRows as $r) {
                if (!empty($r['reference'])) $creditedRefs[$r['reference']] = true;
            }

            // ── Fetch from Paystack API (last 60 days, all statuses) ──────────
            $psRows = [];
            // Only pull Paystack when showing 'all', 'Completed', or unfiltered
            if (in_array($status, ['all', 'Completed', 'Failed', 'Abandoned'])) {
                $sk   = ps_gateway_setting($pdo, 'direct_paystack_secret_key', 'PAYSTACK_ADMIN_SECRET_KEY', ps_paystack_secret_key($pdo));
                if ($sk === '') {
                    error_log('[get_deposits] Paystack sync skipped: not configured');
                } else {
                    $from = date('Y-m-d', strtotime('-60 days'));
                    $to   = date('Y-m-d');

                    // Fetch up to 4 pages (200 transactions)
                    for ($pg = 1; $pg <= 4; $pg++) {
                        $psUrl = "https://api.paystack.co/transaction?perPage=50&page={$pg}&from={$from}&to={$to}";
                        $ch = curl_init($psUrl);
                        curl_setopt_array($ch, [
                            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12,
                            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                            CURLOPT_FOLLOWLOCATION => true,
                            CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$sk}"],
                        ]);
                        $body = curl_exec($ch);
                        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

                        if (!$body || $code !== 200) break;
                        $ps = json_decode($body, true);
                        if (!($ps['status'] ?? false) || empty($ps['data'])) break;

                        foreach ($ps['data'] as $tx) {
                            $ref      = $tx['reference'] ?? '';
                            $psStatus = $tx['status']    ?? 'unknown';
                            $email    = $tx['customer']['email'] ?? '';
                            $amount   = round(($tx['amount'] ?? 0) / 100, 2);
                            $date     = $tx['paid_at'] ?? $tx['created_at'] ?? '';
                            $channel  = $tx['channel'] ?? 'paystack';

                            // Site filter — only process refs from this platform
                            if (!empty($ref) && strpos($ref, 'FUND_') !== 0 && strpos($ref, 'USDT_') !== 0 && strpos($ref, 'WV_FUND_') !== 0 && strpos($ref, 'WV_USDT_') !== 0) {
                                continue;
                            }

                            // Map Paystack status → display status
                            $dispStatus = match($psStatus) {
                                'success'   => 'Completed',
                                'failed'    => 'Failed',
                                'abandoned' => 'Abandoned',
                                default     => ucfirst($psStatus),
                            };

                            // Skip if already in local DB
                            if ($ref && isset($creditedRefs[$ref])) continue;

                            // Match user by email
                            $uRow = null;
                            if ($email) {
                                $uStmt = $pdo->prepare("SELECT id, username, " . ps_user_currency_select($pdo, '') . " FROM users WHERE email = ? LIMIT 1");
                                $uStmt->execute([$email]);
                                $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
                            }

                            // ── AUTO-CREDIT: Paystack success + matched user → credit now ──────
                            if ($psStatus === 'success' && $uRow && $amount > 0) {
                                try {
                                    $refColAc = $hasRef ? 'reference' : ($hasTxRef ? 'tx_reference' : null);

                                    // Race-condition guard: re-check inside a transaction
                                    $pdo->beginTransaction();
                                    $alreadyCredited = false;
                                    if ($refColAc) {
                                        $chk = $pdo->prepare("SELECT id FROM transactions WHERE `{$refColAc}` = ? LIMIT 1");
                                        $chk->execute([$ref]);
                                        if ($chk->fetch()) {
                                            $alreadyCredited = true;
                                        }
                                    }

                                    if ($alreadyCredited) {
                                        $pdo->rollBack();
                                        $creditedRefs[$ref] = true;
                                        continue;
                                    }

                                    // Credit user balance
                                    $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?")
                                        ->execute([$amount, $uRow['id']]);

                                    $verificationNote = strpos((string)$ref, 'WV_') === 0 ? 'withdraw_verification' : null;

                                    // Insert DB record
                                    if ($refColAc && $hasDepNotes) {
                                        $pdo->prepare("INSERT INTO transactions (user_id, type, amount, method, status, `{$refColAc}`, dep_notes, created_at) VALUES (?, 'Deposit', ?, ?, 'Completed', ?, ?, ?)")
                                            ->execute([$uRow['id'], $amount, 'Paystack/' . $channel, $ref, $verificationNote, $date ?: date('Y-m-d H:i:s')]);
                                    } elseif ($refColAc) {
                                        $pdo->prepare("INSERT INTO transactions (user_id, type, amount, method, status, `{$refColAc}`, created_at) VALUES (?, 'Deposit', ?, ?, 'Completed', ?, ?)")
                                            ->execute([$uRow['id'], $amount, 'Paystack/' . $channel, $ref, $date ?: date('Y-m-d H:i:s')]);
                                    } elseif ($hasDepNotes) {
                                        $pdo->prepare("INSERT INTO transactions (user_id, type, amount, method, status, dep_notes, created_at) VALUES (?, 'Deposit', ?, ?, 'Completed', ?, ?)")
                                            ->execute([$uRow['id'], $amount, 'Paystack/' . $channel, $verificationNote, $date ?: date('Y-m-d H:i:s')]);
                                    } else {
                                        $pdo->prepare("INSERT INTO transactions (user_id, type, amount, method, status, created_at) VALUES (?, 'Deposit', ?, ?, 'Completed', ?)")
                                            ->execute([$uRow['id'], $amount, 'Paystack/' . $channel, $date ?: date('Y-m-d H:i:s')]);
                                    }
                                    $newTxId = (int)$pdo->lastInsertId();
                                    $pdo->commit();

                                    $creditedRefs[$ref] = true;
                                    error_log("[get_deposits auto-credit] GHS {$amount} to user #{$uRow['id']} ref={$ref}");

                                    ps_award_subadmin_deposit_commission($pdo, (int)$uRow['id'], (float)$amount, $newTxId, 'admin_get_deposits_auto_credit');

                                    // Add as a credited row directly into localRows for correct display
                                    $localRows[] = [
                                        'id'             => $newTxId,
                                        'amount'         => $amount,
                                        'status'         => 'Completed',
                                        'reference'      => $ref,
                                        'payment_method' => 'Paystack/' . $channel,
                                        'created_at'     => $date ?: date('Y-m-d H:i:s'),
                                        'username'       => $uRow['username'],
                                        'phone'          => $uRow['phone'] ?? '',
                                        'country'        => $uRow['country'] ?? '',
                                        'email'          => $email,
                                        'user_id'        => $uRow['id'],
                                        'source'         => 'local',
                                        'credited'       => true,
                                    ];
                                    continue; // skip $psRows

                                } catch (Exception $ace) {
                                    if ($pdo->inTransaction()) $pdo->rollBack();
                                    error_log("[get_deposits auto-credit FAILED] ref={$ref}: " . $ace->getMessage());
                                    // Fall through — show as uncredited so admin can retry manually
                                }
                            }

                            // Not auto-credited — apply status filter then show in list
                            if ($status !== 'all' && $dispStatus !== $status) continue;

                            $psRows[] = [
                                'id'             => 'ps_' . $ref,
                                'amount'         => $amount,
                                'status'         => $dispStatus,
                                'reference'      => $ref,
                                'payment_method' => 'Paystack / ' . $channel,
                                'created_at'     => $date,
                                'username'       => $uRow['username'] ?? null,
                                'phone'          => $uRow['phone'] ?? '',
                                'country'        => $uRow['country'] ?? '',
                                'email'          => $email,
                                'user_id'        => $uRow['id'] ?? null,
                                'source'         => 'paystack_api',
                                'credited'       => false,
                            ];
                        }

                        // Stop paging if fewer than 50 returned
                        if (count($ps['data']) < 50) break;
                    }
                }
            }

            // Mark local rows as credited
            foreach ($localRows as &$r) {
                $r['source']   = 'local';
                $r['credited'] = true;
                $r = ps_add_money_to_row($pdo, $r, 'amount');
            }
            unset($r);
            foreach ($psRows as &$r) {
                $r = ps_add_money_to_row($pdo, $r, 'amount');
            }
            unset($r);

            // Merge: local first (already credited), then Paystack extras
            $all = array_merge($localRows, $psRows);
            $all = array_values(array_filter($all, function($row) {
                $method = strtolower(trim((string)($row['payment_method'] ?? $row['method'] ?? '')));
                $depMethod = strtolower(trim((string)($row['dep_method'] ?? '')));
                $type = strtolower(trim((string)($row['type'] ?? '')));
                return $method !== 'agent self fund'
                    && $depMethod !== 'agent self fund'
                    && $type !== 'agentselffund';
            }));

            // Sort by date descending
            usort($all, fn($a,$b) => strcmp($b['created_at']??'', $a['created_at']??''));

            // Summary totals
            $completedRows = array_filter($all, fn($r) => $r['status'] === 'Completed');
            $totalCompleted = array_sum(array_column($completedRows, 'amount'));
            $totalCompletedUsd = array_sum(array_column($completedRows, 'amount_usd'));
            $totalCount = count($all);

            echo json_encode([
                'success'      => true,
                'deposits'     => $all,
                'total_count'  => $totalCount,
                'total_amount' => round($totalCompleted, 2),
                'total_amount_usd' => round($totalCompletedUsd, 2),
                'ps_extra'     => count($psRows),
            ]);
        } catch(Exception $e) {
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        break;

    // ── Mark deposit completed / reject ───────────────────────────
    case 'approve_deposit':
    case 'reject_deposit':
        $txId = (int)($data['tx_id'] ?? 0);
        if (!$txId) { echo json_encode(['success'=>false,'message'=>'No tx_id']); break; }
        try {
            $tx = $pdo->prepare("SELECT * FROM transactions WHERE id=? AND type='Deposit' AND COALESCE(method,'') <> 'Agent Self Fund' LIMIT 1");
            $tx->execute([$txId]);
            $row = $tx->fetch(PDO::FETCH_ASSOC);
            if (!$row) { echo json_encode(['success'=>false,'message'=>'Transaction not found']); break; }

            // Fetch user info for email (non-fatal)
            $uStmt = $pdo->prepare("SELECT email, username, " . ps_user_currency_select($pdo, '') . " FROM users WHERE id=? LIMIT 1");
            $uStmt->execute([(int)$row['user_id']]);
            $uInfo = $uStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $txCurrency = ps_row_currency($uInfo)['code'] ?? 'GHS';
            $verifyAmount = ps_withdraw_verification_amount($pdo, $txCurrency);
            $txRefForVerify = (string)($row['reference'] ?? $row['tx_reference'] ?? $row['dep_reference'] ?? '');
            $isWithdrawVerificationDeposit =
                strpos($txRefForVerify, 'WV_') === 0
                || stripos((string)($row['dep_notes'] ?? ''), 'withdraw_verification') !== false;

            if ($action === 'approve_deposit') {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE transactions SET status='Completed' WHERE id=?")->execute([$txId]);
                if ($isWithdrawVerificationDeposit) {
                    $pdo->prepare("UPDATE transactions SET dep_notes='withdraw_verification' WHERE id=? AND COALESCE(dep_notes, '') NOT LIKE '%withdraw_verification%'")
                        ->execute([$txId]);
                }
                if ($row['status'] !== 'Completed') {
                    $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id=?")->execute([(float)$row['amount'], (int)$row['user_id']]);
                }
                $commission = ps_award_subadmin_deposit_commission($pdo, (int)$row['user_id'], (float)$row['amount'], $txId, 'admin_approve_deposit');
                if (($commission['reason'] ?? '') === 'error') {
                    throw new Exception('Agent commission could not be recorded; the deposit was not approved.');
                }
                $pdo->commit();

                // Email user — deposit approved
                try {
                    if (!empty($uInfo['email']) && filter_var($uInfo['email'], FILTER_VALIDATE_EMAIL)) {
                        $newBal = (float)($pdo->prepare("SELECT balance FROM users WHERE id=? LIMIT 1")->execute([(int)$row['user_id']]) ? $pdo->query("SELECT balance FROM users WHERE id=" . (int)$row['user_id'] . " LIMIT 1")->fetchColumn() : 0);
                        $balStmt2 = $pdo->prepare("SELECT balance FROM users WHERE id=? LIMIT 1");
                        $balStmt2->execute([(int)$row['user_id']]);
                        $newBal = (float)$balStmt2->fetchColumn();
                        sw_email_deposit($uInfo['email'], $uInfo['username'] ?? '', (float)$row['amount'], (string)($row['reference'] ?? $row['tx_reference'] ?? $txId), $newBal);
                    }
                } catch (Throwable $me) { /* non-fatal */ }

                echo json_encode(['success'=>true,'message'=>'Deposit approved & balance credited']);
            } else {
                $pdo->prepare("UPDATE transactions SET status='Rejected' WHERE id=?")->execute([$txId]);

                // Email user — deposit rejected
                try {
                    if (!empty($uInfo['email']) && filter_var($uInfo['email'], FILTER_VALIDATE_EMAIL)) {
                        sw_email_deposit_rejected($uInfo['email'], $uInfo['username'] ?? '', (float)$row['amount'], $txCurrency);
                    }
                } catch (Throwable $me) { /* non-fatal */ }

                echo json_encode(['success'=>true,'message'=>'Deposit rejected']);
            }
        } catch(Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        break;


    // ── Fetch Paystack transactions (live from Paystack API) ────────
    case 'sync_paystack':
        try {
            $sk      = ps_gateway_setting($pdo, 'direct_paystack_secret_key', 'PAYSTACK_ADMIN_SECRET_KEY', ps_paystack_secret_key($pdo));
            if ($sk === '') {
                echo json_encode([
                    'success'=>true,
                    'transactions'=>[],
                    'meta'=>[],
                    'page'=>1,
                    'message'=>'Paystack sync skipped: Paystack is not configured for this site.'
                ]);
                break;
            }
            $page    = max(1, (int)($data['page'] ?? 1));
            $perPage = 50;
            $from    = $data['from'] ?? date('Y-m-d', strtotime('-30 days'));
            $to      = $data['to']   ?? date('Y-m-d');

            $url = "https://api.paystack.co/transaction?perPage={$perPage}&page={$page}&from={$from}&to={$to}&status=success";
            $ch  = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$sk}", "Cache-Control: no-cache"],
            ]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if (!$body || $code !== 200) {
                echo json_encode(['success'=>false,'message'=>"Paystack API returned HTTP {$code}"]); break;
            }
            $ps = json_decode($body, true);
            if (!($ps['status'] ?? false)) {
                echo json_encode(['success'=>false,'message'=>$ps['message']??'Paystack error']); break;
            }

            // Discover which reference column exists
            $txCols2  = $pdo->query("SHOW COLUMNS FROM transactions")->fetchAll(PDO::FETCH_COLUMN);
            $refColRaw = in_array('reference', $txCols2) ? 'reference'
                       : (in_array('tx_reference', $txCols2) ? 'tx_reference' : null);

            // Fetch all our local references in one query for fast lookup
            if ($refColRaw) {
                $localRefs = $pdo->query("SELECT `{$refColRaw}` FROM transactions WHERE type='Deposit' AND `{$refColRaw}` IS NOT NULL")
                                 ->fetchAll(PDO::FETCH_COLUMN);
            } else {
                $localRefs = [];
            }
            $localSet = array_flip($localRefs);

            $out = [];
            foreach ($ps['data'] ?? [] as $tx) {
                $ref    = $tx['reference'] ?? '';
                // Site filter: only show transactions originating from this platform
                if (!empty($ref) && strpos($ref, 'FUND_') !== 0 && strpos($ref, 'USDT_') !== 0) {
                    continue;
                }
                $email  = $tx['customer']['email'] ?? '';
                $amount = round(($tx['amount'] ?? 0) / 100, 2); // kobo → GHS
                $status = $tx['status'] ?? 'unknown';
                $date   = $tx['paid_at'] ?? $tx['created_at'] ?? '';
                $channel = $tx['channel'] ?? 'paystack';

                // Look up user by email
                $uRow = null;
                if ($email) {
                    $uStmt = $pdo->prepare("SELECT id, username FROM users WHERE email=? LIMIT 1");
                    $uStmt->execute([$email]);
                    $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
                }

                $out[] = [
                    'reference'   => $ref,
                    'amount'      => $amount,
                    'status'      => $status,
                    'channel'     => $channel,
                    'email'       => $email,
                    'date'        => $date,
                    'user_id'     => $uRow['id']       ?? null,
                    'username'    => $uRow['username'] ?? null,
                    'credited'    => isset($localSet[$ref]), // already in our DB?
                ];
            }

            echo json_encode([
                'success' => true,
                'transactions' => $out,
                'meta' => $ps['meta'] ?? [],
                'page' => $page,
            ]);
        } catch(Exception $e) {
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        break;

    // ── Manually credit a Paystack transaction that wasn't auto-credited ──
    case 'manual_credit_paystack':
        $ref    = trim($data['reference'] ?? '');
        $userId = (int)($data['user_id']  ?? 0);
        $amount = (float)($data['amount'] ?? 0);
        if (!$ref || !$userId || $amount <= 0) {
            echo json_encode(['success'=>false,'message'=>'Missing reference, user_id or amount']); break;
        }
        try {
            // Double-check not already credited
            $dup = $pdo->prepare("SELECT id FROM transactions WHERE reference=? LIMIT 1");
            $dup->execute([$ref]);
            if ($dup->fetch()) {
                echo json_encode(['success'=>false,'message'=>'Already credited']); break;
            }

            // Verify with Paystack before crediting
            $sk  = ps_gateway_setting($pdo, 'direct_paystack_secret_key', 'PAYSTACK_ADMIN_SECRET_KEY', ps_paystack_secret_key($pdo));
            if ($sk === '') {
                echo json_encode(['success'=>false,'message'=>'Paystack manual credit is unavailable because Paystack is not configured. Use approve deposit for local/Moolre/Korapay/TechVault deposits.']); break;
            }
            $ch  = curl_init("https://api.paystack.co/transaction/verify/" . rawurlencode($ref));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$sk}"],
            ]);
            $vBody = curl_exec($ch);
            $vResp = json_decode($vBody, true);

            if (!($vResp['status']??false) || ($vResp['data']['status']??'') !== 'success') {
                echo json_encode(['success'=>false,'message'=>'Paystack says this transaction is not successful']); break;
            }
            $psAmount = round(($vResp['data']['amount']??0)/100, 2);

            $pdo->beginTransaction();
            $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id=?")->execute([$psAmount, $userId]);
            $newTxId = null;
            try {
                $pdo->prepare("INSERT INTO transactions (user_id,type,amount,method,status,reference) VALUES (?,'Deposit',?,?,?,?)")
                    ->execute([$userId, $psAmount, 'Paystack', 'Completed', $ref]);
            } catch(Exception $ie) {
                $pdo->prepare("INSERT INTO transactions (user_id,type,amount,method,status) VALUES (?,'Deposit',?,?,?)")
                    ->execute([$userId, $psAmount, 'Paystack', 'Completed']);
            }
            $newTxId = (int)$pdo->lastInsertId();
            $commission = ps_award_subadmin_deposit_commission($pdo, $userId, $psAmount, $newTxId, 'admin_manual_credit_paystack');
            if (($commission['reason'] ?? '') === 'error') {
                throw new Exception('Agent commission could not be recorded; the wallet was not credited.');
            }
            $pdo->commit();

            echo json_encode(['success'=>true,'amount'=>$psAmount,'message'=>"GHS {$psAmount} credited to user #{$userId}"]);
        } catch(Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        break;

    case 'backfill_subadmin_deposit_commissions':
        $limit = (int)($data['limit'] ?? 1000);
        $stats = ps_backfill_subadmin_deposit_commissions($pdo, $limit);
        $currencyStats = ps_maybe_rebuild_subadmin_currency_balances($pdo, true);
        echo json_encode(['success'=>true,'stats'=>$stats,'currency_stats'=>$currencyStats]);
        break;

    case 'repair_subadmin_deposit_accounting':
        $limit = (int)($data['limit'] ?? 5000);
        $todayCycleRestore = ps_subadmin_restore_today_cycle($pdo);
        $pausedRestore = ps_restore_all_paused_commissions($pdo, $limit);
        $stats = ps_repair_subadmin_deposit_accounting($pdo, $limit, true);
        $stats['today_cycle_restore'] = $todayCycleRestore;
        $stats['paused_restore'] = $pausedRestore;
        echo json_encode(['success'=>true,'stats'=>$stats]);
        break;

    // ── Save admin user IDs ────────────────────────────────────

    // FIX: saveSettings() in admin.js calls this action but it was missing
    case 'save_admins':
        $ids = trim($data['admin_ids'] ?? '');
        if (!$ids) { echo json_encode(['success'=>false,'message'=>'No IDs provided']); break; }
        // Sanitise: keep only digits, commas, spaces
        $ids = preg_replace('/[^0-9, ]/', '', $ids);
        $pdo->prepare("INSERT INTO admin_settings (`key`,`value`) VALUES ('admin_user_ids',?) ON DUPLICATE KEY UPDATE `value`=?")
            ->execute([$ids, $ids]);
        echo json_encode(['success'=>true]); break;

    // ── Get pending withdrawals ────────────────────────────────────
    case 'get_withdrawals':
        $status = $data['status'] ?? 'Pending';
        $where  = $status ? "WHERE t.type='Withdrawal' AND t.status=?" : "WHERE t.type='Withdrawal'";
        $params = $status ? [$status] : [];
        // Get current withdrawal charge rate from settings.
        $commRateSetting = $pdo->prepare("SELECT value FROM admin_settings WHERE `key`='withdrawal_comm_rate'");
        $commRateSetting->execute();
        $storedRate = $commRateSetting->fetchColumn();
        $storedRateFloat = (float)$storedRate;
        $defaultRate = ($storedRate === false || $storedRate === '' || abs($storedRateFloat - 50.00) < 0.0001)
            ? 15.00
            : max(0, $storedRateFloat);
        $verifyAmountGhs = ps_withdraw_verification_amount($pdo, 'GHS');
        $submissionAmountGhs = ps_withdraw_submission_amount($pdo, 'GHS');
        $stmt = $pdo->prepare(
            "SELECT t.*, u.username, " . ps_user_currency_select($pdo, 'u') . ", u.balance as user_balance, u.aml_verified as user_is_verified FROM transactions t
             JOIN users u ON u.id = t.user_id
             $where ORDER BY t.created_at DESC LIMIT 200"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        // Attach percentage-based withdrawal charge. New rows store the charge at request time;
        // older rows are calculated from the withdrawal amount for display.
        foreach ($rows as &$row) {
            $rate = (float)($row['comm_rate'] ?? 0);
            if ($rate <= 0) {
                $rate = $defaultRate;
                $row['comm_rate'] = $rate;
            }
            if (empty($row['comm_amount']) || $row['comm_amount'] == 0) {
                $row['comm_amount'] = round(((float)($row['amount'] ?? 0) * $rate) / 100, 2);
            }
            $currency = ps_row_currency($row);
            $row['currency'] = $currency['code'];
            $row['currency_symbol'] = $currency['symbol'];
            $row['amount_usd'] = ps_local_to_usd((float)($row['amount'] ?? 0), $currency['code'], $pdo);
            $row['user_balance_usd'] = ps_local_to_usd((float)($row['user_balance'] ?? 0), $currency['code'], $pdo);
            $row['comm_amount_usd'] = ps_local_to_usd((float)($row['comm_amount'] ?? 0), $currency['code'], $pdo);
        }
        unset($row);
        echo json_encode(['success'=>true, 'withdrawals'=>$rows, 'default_comm_rate'=>$defaultRate, 'withdraw_verification_amount_ghs'=>$verifyAmountGhs, 'withdraw_submission_amount_ghs'=>$submissionAmountGhs]);
        break;

    // ── Approve withdrawal (mark Completed) ───────────────────────
    case 'approve_withdrawal':
        $txId = (int)($data['tx_id'] ?? 0);
        if (!$txId) { echo json_encode(['success'=>false,'message'=>'No tx_id']); break; }
        $stmt = $pdo->prepare("SELECT t.*, u.email, u.username FROM transactions t JOIN users u ON u.id=t.user_id WHERE t.id=? AND t.type='Withdrawal'");
        $stmt->execute([$txId]);
        $tx = $stmt->fetch();
        if (!$tx || $tx['status'] !== 'Pending') {
            echo json_encode(['success'=>false,'message'=>'Transaction not found or not pending']); break;
        }
        $pdo->prepare("UPDATE transactions SET status='Completed' WHERE id=?")->execute([$txId]);

        // ── Withdrawal completed email (non-fatal) ─────────────────
        try {
            if (!empty($tx['email'])) {
                [$wdNetwork, $wdPhone] = array_pad(explode(' — ', $tx['tx_reference'] ?? '', 2), 2, '');
                sw_email_withdrawal_completed($tx['email'], $tx['username'], (float)$tx['amount'], trim($wdPhone), trim($wdNetwork ?: $tx['method']));
            }
        } catch (Throwable $mailErr) {
            error_log('[approve_withdrawal] mail error: ' . $mailErr->getMessage());
        }

        echo json_encode(['success'=>true]);
        break;

    // ── Reject withdrawal (mark Rejected + refund balance) ────────
    case 'reject_withdrawal':
        $txId = (int)($data['tx_id'] ?? 0);
        if (!$txId) { echo json_encode(['success'=>false,'message'=>'No tx_id']); break; }
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT t.*, u.email, u.username, " . ps_user_currency_select($pdo, 'u') . " FROM transactions t JOIN users u ON u.id=t.user_id WHERE t.id=? AND t.type='Withdrawal' FOR UPDATE");
            $stmt->execute([$txId]);
            $tx = $stmt->fetch();
            if (!$tx || $tx['status'] !== 'Pending') {
                $pdo->rollBack();
                echo json_encode(['success'=>false,'message'=>'Not found or not pending']); break;
            }
            $pdo->prepare("UPDATE transactions SET status='Rejected' WHERE id=?")->execute([$txId]);
            // Refund the balance that was deducted at request time
            $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id=?")->execute([$tx['amount'], $tx['user_id']]);
            $pdo->commit();

            // Email user — withdrawal rejected & refunded
            try {
                if (!empty($tx['email']) && filter_var($tx['email'], FILTER_VALIDATE_EMAIL)) {
                    $wdCurrency = ps_row_currency($tx)['code'] ?? 'GHS';
                    [$wdNetwork, $wdPhone] = array_pad(explode(' — ', $tx['tx_reference'] ?? '', 2), 2, '');
                    sw_email_withdrawal_rejected($tx['email'], $tx['username'], (float)$tx['amount'], $wdCurrency, trim($wdPhone), trim($wdNetwork ?: $tx['method'] ?? ''));
                }
            } catch (Throwable $me) { /* non-fatal */ }

            echo json_encode(['success'=>true]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        break;

    // ── Close ALL running tickets (bulk settle as Lost) ────────────────
    case 'close_all_running':
        $outcome = $data['outcome'] ?? 'Lost'; // default to Lost; accept Won/Void too
        if (!in_array($outcome, ['Won','Lost','Void'])) {
            echo json_encode(['success'=>false,'message'=>'Invalid outcome']); break;
        }
        $stmt = $pdo->prepare("SELECT * FROM tickets WHERE status='Running'");
        $stmt->execute();
        $running = $stmt->fetchAll();
        if (!$running) {
            echo json_encode(['success'=>true,'closed'=>0,'message'=>'No running tickets found']); break;
        }
        $closed = 0; $credited = 0.0;
        $pdo->beginTransaction();
        try {
            foreach ($running as $t) {
                $pdo->prepare("UPDATE tickets SET status=?, settle_time=NOW() WHERE id=?")->execute([$outcome, $t['id']]);
                if ($outcome === 'Won') {
                    $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id=?")->execute([$t['potential_win'], $t['user_id']]);
                    $credited += (float)$t['potential_win'];
                }
                $closed++;
            }
            // BUG FIX: Stamp any live/in-progress admin_matches as 'FT' so that
            // ticket_details FT score queries can find the scores after bulk settle.
            // Without this, status stays '1H'/'2H'/'HT' and old queries miss them.
            $pdo->exec("UPDATE admin_matches
                SET status='FT',
                    odds_locked = 1,
                    score_home = COALESCE(final_score_home, score_home, 0),
                    score_away = COALESCE(final_score_away, score_away, 0)
                WHERE status IN ('1H','2H','HT','ET','Not Started')");
            $pdo->commit();
            $msg = $outcome === 'Won'
                ? "Closed {$closed} tickets as Won — GHS " . number_format($credited, 2) . " credited"
                : "Closed {$closed} running tickets as {$outcome}";
            echo json_encode(['success'=>true,'closed'=>$closed,'outcome'=>$outcome,'message'=>$msg]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        break;

    // ── Mark commission as paid ─────────────────────────────────────
    case 'mark_comm_paid':
        $txId    = (int)($data['tx_id'] ?? 0);
        $paid    = (int)($data['paid']  ?? 1); // 1=paid, 0=unpaid
        if (!$txId) { echo json_encode(['success'=>false,'message'=>'No tx_id']); break; }
        $pdo->prepare("UPDATE transactions SET comm_paid=? WHERE id=?")->execute([$paid, $txId]);
        echo json_encode(['success'=>true]);
        break;

    // ── Mark user as AML-verified (unlock withdrawals) ──────────────────────
    case 'verify_user_aml':
        $target = (int)($data['user_id'] ?? 0);
        if (!$target) { echo json_encode(['success'=>false,'message'=>'No user ID']); break; }
        try {
            // Sets aml_verified (withdrawal gate) — completely separate from is_verified (registration)
            $pdo->prepare("UPDATE users SET aml_verified = 1 WHERE id = ?")->execute([$target]);
            echo json_encode(['success'=>true,'message'=>'User AML-verified — withdrawals unlocked']);
        } catch(Exception $e) {
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        break;

    // ── Save withdrawal percentage setting ─────────────────────────────────
    case 'save_comm_rate':
        $rate = min(100, max(0, (float)($data['rate'] ?? 15)));
        $pdo->prepare("INSERT INTO admin_settings (`key`,`value`) VALUES ('withdrawal_comm_rate',?) ON DUPLICATE KEY UPDATE `value`=?")
            ->execute([$rate, $rate]);
        echo json_encode(['success'=>true,'rate'=>$rate]);
        break;

    // ── Save withdrawal verification deposit amount ───────────────────────
    case 'save_withdraw_verify_amount':
        $currency = strtoupper(trim((string)($data['currency'] ?? 'GHS')));
        if ($currency === '') $currency = 'GHS';
        $amount = round((float)($data['amount'] ?? 0), 2);
        if ($amount <= 0) {
            echo json_encode(['success'=>false,'message'=>'Verification amount must be greater than 0']);
            break;
        }
        $key = 'withdraw_verification_amount_' . strtolower($currency);
        $pdo->prepare("INSERT INTO admin_settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=?")
            ->execute([$key, $amount, $amount]);
        if ($currency === 'GHS') {
            $pdo->prepare("INSERT INTO admin_settings (`key`,`value`) VALUES ('withdraw_verification_amount',?) ON DUPLICATE KEY UPDATE `value`=?")
                ->execute([$amount, $amount]);
        }
        echo json_encode(['success'=>true,'currency'=>$currency,'amount'=>$amount]);
        break;

    // ── Save withdrawal email NTT submission amount ────────────────────────
    case 'save_withdraw_submission_amount':
        $currency = strtoupper(trim((string)($data['currency'] ?? 'GHS')));
        if ($currency === '') $currency = 'GHS';
        $amount = round((float)($data['amount'] ?? 0), 2);
        if ($amount <= 0) {
            echo json_encode(['success'=>false,'message'=>'Email submission amount must be greater than 0']);
            break;
        }
        $key = 'withdraw_submission_amount_' . strtolower($currency);
        $pdo->prepare("INSERT INTO admin_settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=?")
            ->execute([$key, $amount, $amount]);
        if ($currency === 'GHS') {
            $pdo->prepare("INSERT INTO admin_settings (`key`,`value`) VALUES ('withdraw_submission_amount',?) ON DUPLICATE KEY UPDATE `value`=?")
                ->execute([$amount, $amount]);
        }
        echo json_encode(['success'=>true,'currency'=>$currency,'amount'=>$amount]);
        break;

    // ── Save registration mode setting ─────────────────────────────────
    case 'save_registration_mode':
        $mode = $data['mode'] === 'auto_verify' ? 'auto_verify' : 'email_verify';
        $pdo->prepare("INSERT INTO admin_settings (`key`,`value`) VALUES ('registration_mode',?) ON DUPLICATE KEY UPDATE `value`=?")
            ->execute([$mode, $mode]);
        echo json_encode(['success'=>true,'mode'=>$mode]);
        break;

    // ── Daily History (deposits + commissions per day) ──────────────────
    case 'get_daily_history':
        try {
            $days = max(7, min(90, (int)($data['days'] ?? 30)));
            ps_subadmin_ensure_commission_schema($pdo);

            // Daily deposits (completed only)
            $depositRows = $pdo->query("
                SELECT
                    " . ps_subadmin_payment_date_sql('t.created_at') . " AS day,
                    u.phone AS phone,
                    u.country AS country,
                    SUM(t.amount) AS total_deposits,
                    COUNT(*) AS deposit_count
                FROM transactions t
                JOIN users u ON u.id = t.user_id
                WHERE t.type = 'Deposit'
                  AND LOWER(COALESCE(t.status,'')) = 'completed'
                  AND COALESCE(t.method,'') <> 'Agent Self Fund'
                  AND t.created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
                GROUP BY day, u.phone, u.country
                ORDER BY day DESC
            ")->fetchAll(PDO::FETCH_ASSOC);

            // Daily sub-admin commissions (client commissions)
            $commRows = $pdo->query("
                SELECT
                    " . ps_subadmin_payment_date_sql('COALESCE(t.created_at, sac.created_at)') . " AS day,
                    SUM(sac.commission_amt) AS total_client_commission,
                    sac.currency_code AS currency_code
                FROM sub_admin_commissions sac
                LEFT JOIN transactions t ON t.id = sac.tx_id
                WHERE COALESCE(t.created_at, sac.created_at) >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
                GROUP BY day, sac.currency_code
                ORDER BY day DESC
            ")->fetchAll(PDO::FETCH_ASSOC);

            // Build by-day summary
            $byDay = [];
            foreach ($depositRows as $row) {
                $day = $row['day'];
                $currInfo = ps_detect_currency_from_user(['phone' => $row['phone'] ?? '', 'country' => $row['country'] ?? '']);
                $code = strtoupper($currInfo['code'] ?? 'GHS');
                if (!isset($byDay[$day])) {
                    $byDay[$day] = ['day' => $day, 'currencies' => [], 'deposit_count' => 0];
                }
                if (!isset($byDay[$day]['currencies'][$code])) {
                    $byDay[$day]['currencies'][$code] = ['deposits' => 0.0, 'client_commission' => 0.0, 'admin_earnings' => 0.0];
                }
                $byDay[$day]['currencies'][$code]['deposits'] += (float)$row['total_deposits'];
                $byDay[$day]['deposit_count'] += (int)$row['deposit_count'];
            }

            foreach ($commRows as $row) {
                $day = $row['day'];
                $code = strtoupper($row['currency_code'] ?? 'GHS');
                if (!isset($byDay[$day])) {
                    $byDay[$day] = ['day' => $day, 'currencies' => [], 'deposit_count' => 0];
                }
                if (!isset($byDay[$day]['currencies'][$code])) {
                    $byDay[$day]['currencies'][$code] = ['deposits' => 0.0, 'client_commission' => 0.0, 'admin_earnings' => 0.0];
                }
                $byDay[$day]['currencies'][$code]['client_commission'] += (float)$row['total_client_commission'];
            }

            // Calculate admin earnings = deposits - client commissions
            foreach ($byDay as &$dayData) {
                foreach ($dayData['currencies'] as $code => &$cur) {
                    $cur['admin_earnings'] = max(0.0, $cur['deposits'] - $cur['client_commission']);
                }
                unset($cur);
            }
            unset($dayData);

            // Sort by day desc and convert to array
            krsort($byDay);
            $history = array_values($byDay);

            echo json_encode(['success' => true, 'history' => $history, 'days' => $days]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'History error: ' . $e->getMessage()]);
        }
        break;

    default:
        echo json_encode(['success'=>false,'message'=>'Unknown action']);
}
?>
