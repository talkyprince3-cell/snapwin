<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'db.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/currency_helper.php';
require_once __DIR__ . '/subadmin_deposit_helper.php';
require_once __DIR__ . '/admin_match_state_helper.php';
header('Content-Type: application/json');
[$_todayStart, $_tomorrowStart] = ps_subadmin_today_bounds();
$_effectiveCreatedSql = ps_subadmin_effective_created_sql('sac', 'tx');

if (!isset($_SESSION['sub_admin_id']) && !isset($_SESSION['user_id'])) {
    echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit;
}
$userId = (int)($_SESSION['user_id'] ?? 0);
if (isset($_SESSION['sub_admin_id'])) {
    $agentRow = $pdo->prepare("SELECT sa.* FROM sub_admins sa WHERE sa.id = ? AND sa.is_active = 1 LIMIT 1");
    $agentRow->execute([(int)$_SESSION['sub_admin_id']]);
} else {
    $agentRow = $pdo->prepare("SELECT sa.* FROM users u JOIN sub_admins sa ON sa.id = u.linked_agent_id WHERE u.id = ? AND u.is_agent = 1 AND sa.is_active = 1 LIMIT 1");
    $agentRow->execute([$userId]);
}
$sa = $agentRow->fetch();
if (!$sa) { echo json_encode(['success'=>false,'message'=>'Not an agent or account inactive']); exit; }
$saId = (int)$sa['id'];
$canControlSite = (int)($sa['can_control_site'] ?? 0) === 1;
ps_subadmin_ensure_commission_schema($pdo);
try { ps_maybe_rebuild_subadmin_currency_balances($pdo); } catch(Throwable $e) {}

// DB migrations
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN sub_admin_id INT NULL DEFAULT NULL"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN odds_locked TINYINT(1) DEFAULT 0"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN score_home VARCHAR(10) DEFAULT NULL"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN score_away VARCHAR(10) DEFAULT NULL"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN final_score_home int(3) DEFAULT NULL"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN final_score_away int(3) DEFAULT NULL"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN live_at DATETIME NULL"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN end_at DATETIME NULL"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE admin_matches ADD COLUMN show_in_today TINYINT(1) DEFAULT 1"); } catch(Exception $e) {}
ps_admin_match_ensure_live_odds_schema($pdo);
try { $pdo->exec("ALTER TABLE admin_codes ADD COLUMN sub_admin_id INT NULL DEFAULT NULL"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE admin_codes ADD COLUMN min_stake DECIMAL(10,2) DEFAULT 0.00"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE admin_codes ADD COLUMN reveal_at DATETIME NULL"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE admin_codes ADD COLUMN settled_as VARCHAR(10) NULL DEFAULT NULL"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE admin_code_selections ADD COLUMN hidden TINYINT(1) DEFAULT 0"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE admin_code_selections ADD COLUMN admin_match_id INT(11) DEFAULT NULL"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE admin_code_selections ADD COLUMN game_id VARCHAR(50) DEFAULT NULL"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE sub_admins ADD COLUMN can_control_site TINYINT(1) DEFAULT 0"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE sub_admins ADD COLUMN phone VARCHAR(50) NULL"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE sub_admins ADD COLUMN telegram_link VARCHAR(255) NULL"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE sub_admins ADD COLUMN payout_name VARCHAR(120) NULL"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE sub_admins ADD COLUMN payout_network VARCHAR(80) NULL"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE sub_admins ADD COLUMN payout_number VARCHAR(80) NULL"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE transactions ADD COLUMN updated_at DATETIME NULL"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE sub_admin_commissions ADD COLUMN payout_item_id INT NULL"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE sub_admin_commissions ADD COLUMN payout_paid_at DATETIME NULL"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE sub_admin_commissions ADD COLUMN currency_code VARCHAR(10) NOT NULL DEFAULT 'GHS'"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE transactions MODIFY COLUMN type ENUM('Deposit','Withdrawal','AgentSelfFund') NOT NULL"); } catch(Exception $e) {}
try { $pdo->exec("UPDATE transactions SET type='AgentSelfFund' WHERE type='Deposit' AND method='Agent Self Fund'"); } catch(Exception $e) {}
foreach ([
    "CREATE TABLE IF NOT EXISTS sub_admin_withdrawals (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sub_admin_id INT NOT NULL,
        amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        network VARCHAR(80) NULL,
        phone VARCHAR(80) NULL,
        notes TEXT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'Pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        processed_at DATETIME NULL,
        balance_deducted_at DATETIME NULL,
        payment_method VARCHAR(80) NULL,
        payment_reference VARCHAR(160) NULL,
        payment_note TEXT NULL
    )",
    "ALTER TABLE sub_admin_withdrawals ADD COLUMN notes TEXT NULL",
    "ALTER TABLE sub_admin_withdrawals ADD COLUMN balance_deducted_at DATETIME NULL",
    "ALTER TABLE sub_admin_withdrawals ADD COLUMN currency_code VARCHAR(10) NOT NULL DEFAULT 'GHS'",
    "ALTER TABLE sub_admin_withdrawals ADD COLUMN payment_method VARCHAR(80) NULL",
    "ALTER TABLE sub_admin_withdrawals ADD COLUMN payment_reference VARCHAR(160) NULL",
    "ALTER TABLE sub_admin_withdrawals ADD COLUMN payment_note TEXT NULL"
] as $ddl) { try { $pdo->exec($ddl); } catch(Exception $e) {} }

// Auto Score Spreading helper (PHP)
function computeAutoScorePHP(int $fh, int $fa, int $elapsed, int $duration = 90): array {
    $total = $fh + $fa;
    if ($total === 0 || $elapsed <= 0) return [0, 0];
    if ($elapsed >= $duration) return [$fh, $fa];
    $goalMins = [];
    for ($i = 1; $i <= $total; $i++) $goalMins[] = (int)round($duration / ($total + 1) * $i);
    $scored = count(array_filter($goalMins, fn($m) => $m <= $elapsed));
    if ($scored === 0) return [0, 0];
    if ($scored >= $total) return [$fh, $fa];
    $h = (int)round($scored * $fh / $total);
    return [$h, $scored - $h];
}

function saValidFtScore(?string $score): bool {
    $score = trim((string)$score);
    return $score !== '' && $score !== '?' && strcasecmp($score, 'N/A') !== 0
        && (bool)preg_match('/^\d+\s*[-:]\s*\d+$/', $score);
}

function saPairScore(array $row, string $homeKey, string $awayKey): ?string {
    $home = $row[$homeKey] ?? null;
    $away = $row[$awayKey] ?? null;
    if ($home === null || $home === '' || $away === null || $away === '') return null;
    return trim((string)$home) . ' - ' . trim((string)$away);
}

function saCleanTeam($value): string {
    $name = trim((string)$value);
    $bad = ['', '-', '—', '?', '???', 'home', 'away', 'home team', 'away team', 'team a', 'team b'];
    return in_array(strtolower($name), $bad, true) ? '' : $name;
}

function saApplyResultOdds(PDO $pdo, array $selections): array {
    $stmt = $pdo->prepare("SELECT final_score_home, final_score_away, odds_home, odds_draw, odds_away FROM admin_matches WHERE id=? LIMIT 1");
    foreach ($selections as &$selection) {
        $adminMatchId = isset($selection['admin_match_id']) ? (int)$selection['admin_match_id'] : 0;
        if (!$adminMatchId) continue;
        try {
            $stmt->execute([$adminMatchId]);
            $match = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$match || $match['final_score_home'] === null || $match['final_score_home'] === '' || $match['final_score_away'] === null || $match['final_score_away'] === '') {
                continue;
            }
            $home = (int)$match['final_score_home'];
            $away = (int)$match['final_score_away'];
            if ($home === $away) {
                $selection['pick'] = 'X';
                $selection['odds'] = (float)$match['odds_draw'];
            } elseif ($home > $away) {
                $selection['pick'] = '1';
                $selection['odds'] = (float)$match['odds_home'];
            } else {
                $selection['pick'] = '2';
                $selection['odds'] = (float)$match['odds_away'];
            }
        } catch (Throwable $e) {}
    }
    unset($selection);
    return $selections;
}

function saSyncTicketMatchScores(PDO $pdo, int $ticketId, string $outcome): void {
    $settleResult = $outcome === 'Won' ? 1 : ($outcome === 'Lost' ? 0 : null);
    $pdo->prepare("UPDATE ticket_matches SET match_status=?, settle_result=? WHERE ticket_id=?")
        ->execute([$outcome, $settleResult, $ticketId]);

    $rows = $pdo->prepare("
        SELECT tm.id AS match_id, tm.game_id, tm.home_team AS tm_home, tm.away_team AS tm_away,
               acs.score, acs.home_team AS acs_home, acs.away_team AS acs_away,
               am.home_team AS am_home, am.away_team AS am_away,
               am.score_home, am.score_away, am.final_score_home, am.final_score_away
        FROM ticket_matches tm
        JOIN tickets t ON t.id = tm.ticket_id
        JOIN admin_codes ac ON ac.code = t.booking_code OR ac.code = t.verification_code
        JOIN admin_code_selections acs
          ON acs.code_id = ac.id
         AND (
            (tm.game_id <> '' AND (acs.game_id = tm.game_id OR CONCAT('adm_', acs.admin_match_id) = tm.game_id OR CAST(acs.admin_match_id AS CHAR) = tm.game_id))
            OR (LOWER(TRIM(acs.home_team)) = LOWER(TRIM(tm.home_team)) AND LOWER(TRIM(acs.away_team)) = LOWER(TRIM(tm.away_team)))
         )
        LEFT JOIN admin_matches am ON am.id = acs.admin_match_id
        WHERE tm.ticket_id = ?
    ");
    $rows->execute([$ticketId]);
    $upd = $pdo->prepare("UPDATE ticket_matches SET ft_score=?, home_team=?, away_team=? WHERE id=?");
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $score = trim((string)($row['score'] ?? ''));
        if (!saValidFtScore($score)) {
            $score = saPairScore($row, 'final_score_home', 'final_score_away')
                ?: saPairScore($row, 'score_home', 'score_away')
                ?: '';
        }
        $home = saCleanTeam($row['tm_home'] ?? '') ?: (saCleanTeam($row['am_home'] ?? '') ?: saCleanTeam($row['acs_home'] ?? ''));
        $away = saCleanTeam($row['tm_away'] ?? '') ?: (saCleanTeam($row['am_away'] ?? '') ?: saCleanTeam($row['acs_away'] ?? ''));
        if (saValidFtScore($score)) {
            $upd->execute([str_replace(':', ' - ', $score), $home ?: 'Home Team', $away ?: 'Away Team', (int)$row['match_id']]);
        }
    }
}


try { $pdo->exec("CREATE TABLE IF NOT EXISTS sub_admin_settings (
    sub_admin_id INT PRIMARY KEY, odds_global_lock TINYINT(1) DEFAULT 0,
    cashout_enabled TINYINT(1) DEFAULT 1, min_stake DECIMAL(10,2) DEFAULT 1.00,
    max_win DECIMAL(10,2) DEFAULT 50000.00, show_external_matches TINYINT(1) DEFAULT 1,
    popular_count INT DEFAULT 10, today_count INT DEFAULT 40,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)"); } catch(Exception $e) {}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

$fullControlActions = [
    'get_matches','add_match','update_match','update_score','lock_odds','delete_match',
    'code_list','create_code','import_sporty','get_code_selections','toggle_code_hide',
    'toggle_sel_hide','update_code_selection','reveal_code','code_tickets','settle_code',
    'delete_code','get_tickets','settle_ticket','close_all_running','get_deposits',
    'approve_deposit','reject_deposit','get_user_withdrawals','approve_user_withdrawal',
    'reject_user_withdrawal','from_admin_matches','settle_code_users',
    'fund_user','set_balance','ban_user','unban_user','reset_user_password',
];
if (in_array($action, $fullControlActions, true) && !$canControlSite) {
    echo json_encode(['success'=>false,'message'=>'Full Control is not enabled for this agent account.']); exit;
}

function getSaUserIds(PDO $pdo, int $saId): array {
    // Include members linked by registration (sub_admin_id) OR admin-assigned (linked_agent_id)
    $s = $pdo->prepare("SELECT id FROM users WHERE sub_admin_id=? OR linked_agent_id=?");
    $s->execute([$saId, $saId]);
    return array_column($s->fetchAll(), 'id') ?: [0];
}
function ownCode(PDO $pdo, int $codeId, int $saId): bool {
    $r = $pdo->prepare("SELECT id FROM admin_codes WHERE id=? AND sub_admin_id=?");
    $r->execute([$codeId, $saId]);
    return (bool)$r->fetch();
}
function saRowCurrency(array $row): array {
    $stored = strtoupper(trim((string)($row['currency_code'] ?? $row['currency'] ?? '')));
    if ($stored !== '') return ps_currency_meta_from_code($stored);
    return ps_detect_currency_from_user(['phone' => $row['phone'] ?? '', 'country' => $row['country'] ?? '']);
}
function saAddMoney(PDO $pdo, array $row, string $amountKey): array {
    $currency = $row['currency'] ?? $row['currency_code'] ?? saRowCurrency($row)['code'];
    $meta = ps_money_payload((float)($row[$amountKey] ?? 0), $currency, $pdo);
    $row['currency'] = $meta['currency'];
    $row['currency_symbol'] = $meta['symbol'];
    $row[$amountKey . '_usd'] = $meta['usd'];
    return $row;
}
function saSumUsd(PDO $pdo, array $rows, string $amountKey): float {
    $total = 0.0;
    foreach ($rows as $row) {
        $currency = $row['currency'] ?? $row['currency_code'] ?? saRowCurrency($row)['code'];
        $total += ps_local_to_usd((float)($row[$amountKey] ?? 0), $currency, $pdo);
    }
    return round($total, 2);
}
function saCurrencyBreakdown(PDO $pdo, array $rows, string $amountKey): array {
    $out = [];
    foreach ($rows as $row) {
        $currency = $row['currency'] ?? $row['currency_code'] ?? saRowCurrency($row)['code'];
        $code = strtoupper(trim((string)$currency ?: 'GHS'));
        $meta = ps_currency_meta_from_code($code);
        if (!isset($out[$code])) {
            $out[$code] = ['currency'=>$code, 'symbol'=>$meta['symbol'] ?? $code, 'amount'=>0.0, 'amount_usd'=>0.0];
        }
        $amount = (float)($row[$amountKey] ?? 0);
        $out[$code]['amount'] += $amount;
        $out[$code]['amount_usd'] += ps_local_to_usd($amount, $code, $pdo);
    }
    return array_values(array_map(function($row) {
        $row['amount'] = round((float)$row['amount'], 2);
        $row['amount_usd'] = round((float)$row['amount_usd'], 2);
        return $row;
    }, $out));
}
function saCurrencyBalanceRows(PDO $pdo, int $subAdminId, string $field): array {
    $allowed = ['balance', 'total_earned', 'total_deposits'];
    if (!in_array($field, $allowed, true)) $field = 'balance';
    $stmt = $pdo->prepare("SELECT currency_code, {$field} AS amount FROM sub_admin_currency_balances WHERE sub_admin_id=? AND {$field} > 0 ORDER BY FIELD(currency_code,'GHS','NGN','USD'), currency_code");
    $stmt->execute([$subAdminId]);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $code = strtoupper(trim((string)($row['currency_code'] ?? 'GHS'))) ?: 'GHS';
        $meta = ps_currency_meta_from_code($code);
        $amount = round((float)($row['amount'] ?? 0), 2);
        $rows[] = ['currency'=>$code, 'currency_code'=>$code, 'symbol'=>$meta['symbol'] ?? $code, 'amount'=>$amount, 'amount_usd'=>ps_local_to_usd($amount, $code, $pdo)];
    }
    return $rows;
}
function saSubtractCurrencyBreakdowns(PDO $pdo, array $baseRows, array $minusRows): array {
    $out = [];
    foreach ($baseRows as $row) {
        $code = strtoupper(trim((string)($row['currency'] ?? $row['currency_code'] ?? 'GHS'))) ?: 'GHS';
        $out[$code] = (float)($out[$code] ?? 0) + (float)($row['amount'] ?? 0);
    }
    foreach ($minusRows as $row) {
        $code = strtoupper(trim((string)($row['currency'] ?? $row['currency_code'] ?? 'GHS'))) ?: 'GHS';
        $out[$code] = (float)($out[$code] ?? 0) - (float)($row['amount'] ?? 0);
    }
    $rows = [];
    foreach ($out as $code => $amount) {
        $amount = max(0, round((float)$amount, 2));
        if ($amount <= 0) continue;
        $meta = ps_currency_meta_from_code($code);
        $rows[] = [
            'currency' => $code,
            'symbol' => $meta['symbol'] ?? $code,
            'amount' => $amount,
            'amount_usd' => ps_local_to_usd($amount, $code, $pdo)
        ];
    }
    return $rows;
}
function saUserCurrencySelect(PDO $pdo, string $alias = 'u'): string {
    $prefix = $alias !== '' ? $alias . '.' : '';
    $phone = ps_table_column_exists($pdo, 'users', 'phone') ? "{$prefix}phone AS phone" : "'' AS phone";
    $country = ps_table_column_exists($pdo, 'users', 'country') ? "{$prefix}country AS country" : "'' AS country";
    return "{$phone}, {$country}";
}

function saUniqueBettingUsername(PDO $pdo, string $base): string {
    $base = strtolower(preg_replace('/[^a-z0-9_]+/i', '_', trim($base)));
    $base = trim($base, '_') ?: 'agent';
    $base = 'agent_' . substr($base, 0, 24);
    $username = $base;
    $i = 1;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username=?");
    while (true) {
        $stmt->execute([$username]);
        if ((int)$stmt->fetchColumn() === 0) return $username;
        $username = $base . '_' . $i++;
    }
}

function saLinkedBettingAccount(PDO $pdo, array $sa, bool $forUpdate = false): array {
    $saId = (int)($sa['id'] ?? 0);
    if ($saId <= 0) throw new Exception('Agent account not found');
    $lock = $forUpdate ? ' FOR UPDATE' : '';

    $stmt = $pdo->prepare("SELECT id, username, email, phone, country, balance FROM users WHERE linked_agent_id=? AND is_agent=1 LIMIT 1{$lock}");
    $stmt->execute([$saId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;

    $email = trim((string)($sa['email'] ?? ''));
    if ($email !== '') {
        $byEmail = $pdo->prepare("SELECT id, username, email, phone, country, balance FROM users WHERE email=? LIMIT 1{$lock}");
        $byEmail->execute([$email]);
        $existing = $byEmail->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $pdo->prepare("UPDATE users SET is_agent=1, linked_agent_id=?, sub_admin_id=NULL WHERE id=?")
                ->execute([$saId, (int)$existing['id']]);
            $existing['is_agent'] = 1;
            $existing['linked_agent_id'] = $saId;
            return $existing;
        }
    }

    $phone = trim((string)($sa['phone'] ?? ''));
    $email = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : ('agent' . $saId . '@alphasports.local');
    $emailCheck = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email=?");
    $emailBase = $email;
    $n = 1;
    while (true) {
        $emailCheck->execute([$email]);
        if ((int)$emailCheck->fetchColumn() === 0) break;
        $email = 'agent' . $saId . '_' . $n++ . '@alphasports.local';
    }

    $currency = ps_detect_currency_from_user(['phone' => $phone, 'country' => '']);
    $columns = ['username', 'email', 'password', 'balance', 'is_verified', 'profile_pic', 'is_agent', 'linked_agent_id'];
    $values = [
        saUniqueBettingUsername($pdo, (string)($sa['username'] ?? ('agent_' . $saId))),
        $email,
        (string)($sa['password'] ?? password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT)),
        0.00,
        1,
        'img/avatar1.png',
        1,
        $saId,
    ];
    if (ps_table_column_exists($pdo, 'users', 'phone')) {
        $columns[] = 'phone';
        $values[] = $phone;
    }
    if (ps_table_column_exists($pdo, 'users', 'country')) {
        $columns[] = 'country';
        $values[] = $currency['country'] ?? '';
    }
    if (ps_table_column_exists($pdo, 'users', 'first_name')) {
        $columns[] = 'first_name';
        $values[] = (string)($sa['username'] ?? 'Agent');
    }

    $placeholders = implode(',', array_fill(0, count($columns), '?'));
    $pdo->prepare("INSERT INTO users (" . implode(',', $columns) . ") VALUES ($placeholders)")
        ->execute($values);
    $id = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare("SELECT id, username, email, phone, country, balance FROM users WHERE id=? LIMIT 1{$lock}");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['id'=>$id, 'balance'=>0.0, 'email'=>$email];
}

switch ($action) {

case 'stats':
    ps_repair_subadmin_deposit_accounting($pdo, 1000);
    $uids = getSaUserIds($pdo, $saId);
    $ph   = implode(',', array_fill(0, count($uids), '?'));
    $uc = $pdo->prepare("SELECT COUNT(*) FROM users WHERE sub_admin_id=? OR linked_agent_id=?"); $uc->execute([$saId, $saId]);
    $currencyCols = saUserCurrencySelect($pdo, 'u');
    $tdRows = $pdo->prepare("SELECT t.amount, {$currencyCols} FROM transactions t JOIN users u ON u.id=t.user_id WHERE t.user_id IN ($ph) AND t.type='Deposit' AND t.status='Completed' AND COALESCE(t.method,'') <> 'Agent Self Fund'"); $tdRows->execute($uids);
    $depositRows = $tdRows->fetchAll(PDO::FETCH_ASSOC);
    $earnedRowsStmt = $pdo->prepare("SELECT sac.commission_amt, sac.currency_code, {$currencyCols} FROM sub_admin_commissions sac JOIN users u ON u.id=sac.user_id WHERE sac.sub_admin_id=?"); $earnedRowsStmt->execute([$saId]);
    $earnedRows = $earnedRowsStmt->fetchAll(PDO::FETCH_ASSOC);
    $todayRowsStmt = $pdo->prepare("SELECT sac.commission_amt, sac.currency_code, {$currencyCols} FROM sub_admin_commissions sac JOIN users u ON u.id=sac.user_id LEFT JOIN transactions tx ON tx.id=sac.tx_id WHERE sac.sub_admin_id=? AND {$_effectiveCreatedSql} >= ? AND {$_effectiveCreatedSql} < ?");
    $todayRowsStmt->execute([$saId, $_todayStart, $_tomorrowStart]);
    $todayRows = $todayRowsStmt->fetchAll(PDO::FETCH_ASSOC);
    $unpaidRowsStmt = $pdo->prepare("SELECT sac.commission_amt, sac.currency_code, {$currencyCols} FROM sub_admin_commissions sac JOIN users u ON u.id=sac.user_id WHERE sac.sub_admin_id=? AND sac.payout_paid_at IS NULL AND sac.payout_item_id IS NULL"); $unpaidRowsStmt->execute([$saId]);
    $unpaidRows = $unpaidRowsStmt->fetchAll(PDO::FETCH_ASSOC);
    $rt = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE user_id IN ($ph) AND status='Running'"); $rt->execute($uids);
    $rc = $pdo->prepare("SELECT sac.*, u.username, {$currencyCols} FROM sub_admin_commissions sac JOIN users u ON u.id=sac.user_id WHERE sac.sub_admin_id=? ORDER BY sac.created_at DESC LIMIT 10"); $rc->execute([$saId]);
    $recent = [];
    foreach ($rc->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row = saAddMoney($pdo, $row, 'deposit_amount');
        $row['commission_amt_usd'] = ps_local_to_usd((float)($row['commission_amt'] ?? 0), $row['currency'], $pdo);
        $recent[] = $row;
    }
    $depositByCurrency = saCurrencyBalanceRows($pdo, $saId, 'total_deposits') ?: saCurrencyBreakdown($pdo, $depositRows, 'amount');
    $earnedByCurrency = saCurrencyBalanceRows($pdo, $saId, 'total_earned') ?: saCurrencyBreakdown($pdo, $earnedRows, 'commission_amt');
    $todayByCurrency = saCurrencyBreakdown($pdo, $todayRows, 'commission_amt');
    $balanceByCurrency = saCurrencyBalanceRows($pdo, $saId, 'balance') ?: saCurrencyBreakdown($pdo, $unpaidRows, 'commission_amt');
    echo json_encode(['success'=>true,'sa_pct'=>$sa['commission_pct'],'stats'=>[
        'users'=>(int)$uc->fetchColumn(),
        'total_deposits'=>0,
        'total_deposits_usd'=>array_sum(array_map(fn($row)=>(float)($row['amount_usd'] ?? 0), $depositByCurrency)),
        'total_deposits_by_currency'=>$depositByCurrency,
        'total_earned'=>0,
        'total_earned_usd'=>array_sum(array_map(fn($row)=>(float)($row['amount_usd'] ?? 0), $earnedByCurrency)),
        'total_earned_by_currency'=>$earnedByCurrency,
        'today_commission'=>0,
        'today_commission_usd'=>array_sum(array_map(fn($row)=>(float)($row['amount_usd'] ?? 0), $todayByCurrency)),
        'today_commission_by_currency'=>$todayByCurrency,
        'balance'=>(float)$sa['balance'],
        'balance_usd'=>array_sum(array_map(fn($row)=>(float)($row['amount_usd'] ?? 0), $balanceByCurrency)),
        'balance_by_currency'=>$balanceByCurrency,
        'running_tickets'=>(int)$rt->fetchColumn()
    ],'recent'=>$recent]);
    break;

case 'get_settings':
    $r = $pdo->prepare("SELECT * FROM sub_admin_settings WHERE sub_admin_id=?"); $r->execute([$saId]);
    $cfg = $r->fetch() ?: [];
    // If no row exists, create one with show_external_matches=1
    if (!$cfg) {
        $pdo->prepare("INSERT IGNORE INTO sub_admin_settings (sub_admin_id,show_external_matches) VALUES (?,1)")->execute([$saId]);
        $cfg = ['sub_admin_id'=>$saId,'odds_global_lock'=>0,'cashout_enabled'=>1,'min_stake'=>1.00,'max_win'=>50000.00,'show_external_matches'=>1,'popular_count'=>10,'today_count'=>40];
    }
    // Ensure show_external_matches is 1 unless explicitly saved as 0
    if (!isset($cfg['show_external_matches']) || $cfg['show_external_matches'] === null) {
        $cfg['show_external_matches'] = 1;
    }
    echo json_encode(['success'=>true,'settings'=>$cfg]);
    break;

case 'save_settings':
    $fields=[]; $vals=[];
    foreach (['odds_global_lock','cashout_enabled','show_external_matches'] as $f) { if (isset($_POST[$f])) { $fields[]="$f=?"; $vals[]=(int)$_POST[$f]; } }
    foreach (['min_stake','max_win','popular_count','today_count'] as $f) { if (isset($_POST[$f])) { $fields[]="$f=?"; $vals[]=(float)$_POST[$f]; } }
    if (empty($fields)) { echo json_encode(['success'=>false,'message'=>'Nothing to save']); break; }
    $vals[]=$saId;
    $pdo->prepare("INSERT INTO sub_admin_settings (sub_admin_id) VALUES (?) ON DUPLICATE KEY UPDATE sub_admin_id=sub_admin_id")->execute([$saId]);
    $pdo->prepare("UPDATE sub_admin_settings SET ".implode(',', $fields)." WHERE sub_admin_id=?")->execute($vals);
    echo json_encode(['success'=>true,'message'=>'Settings saved!']);
    break;

case 'save_payout_details':
    $name = trim((string)($_POST['name'] ?? ''));
    $network = trim((string)($_POST['network'] ?? ''));
    $number = trim((string)($_POST['number'] ?? ''));
    if ($name === '' || $network === '' || $number === '') {
        echo json_encode(['success'=>false,'message'=>'Complete the account name, network or bank, and account number.']);
        break;
    }
    if (strlen($name) > 120 || strlen($network) > 80 || strlen($number) > 80) {
        echo json_encode(['success'=>false,'message'=>'One or more payout details are too long.']);
        break;
    }
    try {
        $pdo->prepare("UPDATE sub_admins SET payout_name=?, payout_network=?, payout_number=? WHERE id=?")
            ->execute([$name, $network, $number, $saId]);
        echo json_encode([
            'success'=>true,
            'message'=>'Payout details saved',
            'payout'=>['name'=>$name,'network'=>$network,'number'=>$number]
        ]);
    } catch(Throwable $e) {
        error_log('Sub-admin payout details save failed for #' . $saId . ': ' . $e->getMessage());
        echo json_encode(['success'=>false,'message'=>'Payout details could not be saved. Please try again.']);
    }
    break;

case 'get_my_betting_balance':
    try {
        $row = saLinkedBettingAccount($pdo, $sa, false);
        $meta = saRowCurrency($row);
        $fundRows = [];
        $withdrawRows = [];
        try {
            $fundStmt = $pdo->prepare("SELECT amount, status, created_at, method FROM transactions WHERE user_id=? AND type='AgentSelfFund' ORDER BY created_at DESC, id DESC LIMIT 12");
            $fundStmt->execute([(int)$row['id']]);
            $allRows = $fundStmt->fetchAll(PDO::FETCH_ASSOC);
            $fundRows = array_values(array_filter($allRows, fn($r)=>(float)($r['amount'] ?? 0) > 0));
            $withdrawRows = array_values(array_filter($allRows, fn($r)=>(float)($r['amount'] ?? 0) < 0));
        } catch(Exception $e) {}
        echo json_encode(['success'=>true,'user_id'=>(int)$row['id'],'balance'=>(float)$row['balance'],'currency'=>$meta['code'],'symbol'=>$meta['symbol'],'self_funds'=>$fundRows,'fake_withdrawals'=>$withdrawRows]);
    } catch(Exception $e) {
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    break;

case 'add_my_betting_funds':
case 'set_my_betting_balance':
    $amount = round((float)($_POST['amount'] ?? -1), 2);
    if ($amount <= 0) { echo json_encode(['success'=>false,'message'=>'Enter a valid amount to add']); break; }

    try {
        $pdo->beginTransaction();
        $row = saLinkedBettingAccount($pdo, $sa, true);
        $oldBalance = round((float)$row['balance'], 2);
        $newBalance = round($oldBalance + $amount, 2);
        $pdo->prepare("UPDATE users SET balance=? WHERE id=? AND is_agent=1 AND linked_agent_id=?")
            ->execute([$newBalance, (int)$row['id'], $saId]);

        try {
            $cols = array_column($pdo->query("SHOW COLUMNS FROM transactions")->fetchAll(PDO::FETCH_ASSOC), 'Field');
            $refCol = in_array('tx_reference', $cols, true) ? 'tx_reference' : (in_array('reference', $cols, true) ? 'reference' : null);
            $txCols = ['user_id','type','amount','method','status'];
            $txVals = [(int)$row['id'], 'AgentSelfFund', $amount, 'Agent Self Fund', 'Completed'];
            if ($refCol) {
                $txCols[] = "`{$refCol}`";
                $txVals[] = 'Agent self fund: ' . number_format($oldBalance, 2) . ' -> ' . number_format($newBalance, 2);
            }
            if (in_array('currency_code', $cols, true)) {
                $meta = saRowCurrency($row);
                $txCols[] = 'currency_code';
                $txVals[] = $meta['code'] ?? 'GHS';
            }
            if (in_array('created_at', $cols, true)) {
                $txCols[] = 'created_at';
                $txVals[] = date('Y-m-d H:i:s');
            }
            $ph = implode(',', array_fill(0, count($txVals), '?'));
            $pdo->prepare("INSERT INTO transactions (" . implode(',', $txCols) . ") VALUES ($ph)")
                ->execute($txVals);
        } catch(Exception $e) {}

        $pdo->commit();
        $_SESSION['balance'] = $newBalance;
        echo json_encode(['success'=>true,'message'=>'Funds added to betting account','balance'=>$newBalance,'added'=>$amount]);
    } catch(Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    break;

case 'remove_my_betting_funds':
    $amount = round((float)($_POST['amount'] ?? -1), 2);
    if ($amount <= 0) { echo json_encode(['success'=>false,'message'=>'Enter a valid amount to remove']); break; }

    try {
        $pdo->beginTransaction();
        $row = saLinkedBettingAccount($pdo, $sa, true);
        $oldBalance = round((float)$row['balance'], 2);
        if ($oldBalance + 0.0001 < $amount) {
            throw new Exception('Not enough betting balance to remove that amount');
        }
        $newBalance = round(max(0, $oldBalance - $amount), 2);
        $pdo->prepare("UPDATE users SET balance=? WHERE id=? AND is_agent=1 AND linked_agent_id=?")
            ->execute([$newBalance, (int)$row['id'], $saId]);

        try {
            $cols = array_column($pdo->query("SHOW COLUMNS FROM transactions")->fetchAll(PDO::FETCH_ASSOC), 'Field');
            $refCol = in_array('tx_reference', $cols, true) ? 'tx_reference' : (in_array('reference', $cols, true) ? 'reference' : null);
            $txCols = ['user_id','type','amount','method','status'];
            $txVals = [(int)$row['id'], 'AgentSelfFund', -1 * $amount, 'Agent Self Fund', 'Completed'];
            if ($refCol) {
                $txCols[] = "`{$refCol}`";
                $txVals[] = 'Agent self fund removal: ' . number_format($oldBalance, 2) . ' -> ' . number_format($newBalance, 2);
            }
            if (in_array('currency_code', $cols, true)) {
                $meta = saRowCurrency($row);
                $txCols[] = 'currency_code';
                $txVals[] = $meta['code'] ?? 'GHS';
            }
            if (in_array('created_at', $cols, true)) {
                $txCols[] = 'created_at';
                $txVals[] = date('Y-m-d H:i:s');
            }
            $ph = implode(',', array_fill(0, count($txVals), '?'));
            $pdo->prepare("INSERT INTO transactions (" . implode(',', $txCols) . ") VALUES ($ph)")
                ->execute($txVals);
        } catch(Exception $e) {}

        $pdo->commit();
        $_SESSION['balance'] = $newBalance;
        echo json_encode(['success'=>true,'message'=>'Funds removed from betting account','balance'=>$newBalance,'removed'=>$amount]);
    } catch(Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    break;

case 'fake_withdraw_my_betting_balance':
    $amount = round((float)($_POST['amount'] ?? -1), 2);
    $network = trim((string)($_POST['network'] ?? 'MTN Mobile Money'));
    $phone = trim((string)($_POST['phone'] ?? ''));
    if ($amount <= 0) { echo json_encode(['success'=>false,'message'=>'Enter a valid withdrawal amount']); break; }
    if ($phone === '') { echo json_encode(['success'=>false,'message'=>'Payment phone/account is required']); break; }

    try {
        $pdo->beginTransaction();
        $row = saLinkedBettingAccount($pdo, $sa, true);
        $meta = saRowCurrency($row);
        $oldBalance = round((float)$row['balance'], 2);
        if ($oldBalance + 0.0001 < $amount) {
            throw new Exception('Insufficient Alpha Sports balance for this withdrawal.');
        }
        $newBalance = round(max(0, $oldBalance - $amount), 2);
        $pdo->prepare("UPDATE users SET balance=? WHERE id=? AND is_agent=1 AND linked_agent_id=?")
            ->execute([$newBalance, (int)$row['id'], $saId]);

        try {
            $cols = array_column($pdo->query("SHOW COLUMNS FROM transactions")->fetchAll(PDO::FETCH_ASSOC), 'Field');
            $refCol = in_array('tx_reference', $cols, true) ? 'tx_reference' : (in_array('reference', $cols, true) ? 'reference' : null);
            $txCols = ['user_id','type','amount','method','status'];
            $txVals = [(int)$row['id'], 'AgentSelfFund', -1 * $amount, 'Agent Fake Withdrawal', 'Completed'];
            if ($refCol) {
                $txCols[] = "`{$refCol}`";
                $txVals[] = trim($network . ' ' . $phone . ' | fake withdraw: ' . number_format($oldBalance, 2) . ' -> ' . number_format($newBalance, 2));
            }
            if (in_array('currency_code', $cols, true)) {
                $txCols[] = 'currency_code';
                $txVals[] = $meta['code'] ?? 'GHS';
            }
            if (in_array('provider', $cols, true)) {
                $txCols[] = 'provider';
                $txVals[] = $network;
            }
            if (in_array('account_number', $cols, true)) {
                $txCols[] = 'account_number';
                $txVals[] = $phone;
            }
            if (in_array('created_at', $cols, true)) {
                $txCols[] = 'created_at';
                $txVals[] = date('Y-m-d H:i:s');
            }
            $ph = implode(',', array_fill(0, count($txVals), '?'));
            $pdo->prepare("INSERT INTO transactions (" . implode(',', $txCols) . ") VALUES ($ph)")
                ->execute($txVals);
        } catch(Exception $e) {}

        $pdo->commit();
        $_SESSION['balance'] = $newBalance;
        try {
            if (!empty($row['email'])) {
                sw_email_withdrawal_completed(
                    (string)$row['email'],
                    (string)($row['username'] ?? ''),
                    $amount,
                    $phone,
                    $network
                );
            }
        } catch (Exception $mailErr) {}
        echo json_encode([
            'success'=>true,
            'message'=>'Fake withdrawal completed.',
            'balance'=>$newBalance,
            'currency'=>$meta['code'] ?? 'GHS',
            'symbol'=>$meta['symbol'] ?? ($meta['code'] ?? 'GHS'),
            'amount'=>$amount
        ]);
    } catch(Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    break;

case 'get_matches':
    $r = $pdo->prepare("SELECT * FROM admin_matches WHERE is_active=1 AND sub_admin_id=? ORDER BY pin_order ASC, created_at DESC LIMIT 100");
    $r->execute([$saId]);
    // Apply auto-spread current score for live matches
    $matches = array_map(function($am) {
        $fsh     = $am['final_score_home'];
        $fsa     = $am['final_score_away'];
        $elapsed = (int)($am['elapsed'] ?? 0);
        $isLive  = in_array($am['status'] ?? '', ['1H','HT','2H']);
        if ($isLive && $fsh !== null && $fsh !== '' && $elapsed > 0) {
            [$am['score_home'], $am['score_away']] = computeAutoScorePHP((int)$fsh, (int)$fsa, $elapsed);
        }
        return $am;
    }, $r->fetchAll());
    echo json_encode(['success'=>true,'matches'=>$matches]);
    break;

case 'add_match':
    $sec = $_POST['section'] ?? 'both';
    // Parse target score shorthand "1-3"
    $finalSH = null; $finalSA = null;
    $targetScore = trim($_POST['target_score'] ?? '');
    if ($targetScore && preg_match('/^(\d+)-(\d+)$/', $targetScore, $tsm)) {
        $finalSH = (int)$tsm[1]; $finalSA = (int)$tsm[2];
    } elseif (isset($_POST['final_score_home']) && $_POST['final_score_home'] !== '') {
        $finalSH = (int)$_POST['final_score_home']; $finalSA = (int)($_POST['final_score_away'] ?? 0);
    }
    try {
        $manualLocked = !empty($_POST['odds_locked']) ? 1 : 0;
        $pdo->prepare("INSERT INTO admin_matches (home_team,away_team,home_logo,away_logo,league,match_time,match_date,odds_home,odds_draw,odds_away,is_pinned,show_in_today,live_at,end_at,odds_locked,odds_manual_locked,sub_admin_id,is_active,final_score_home,final_score_away) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?,?)")
            ->execute([trim($_POST['home_team']??''),trim($_POST['away_team']??''),trim($_POST['home_logo']??''),trim($_POST['away_logo']??''),trim($_POST['league']??'Premier League'),trim($_POST['match_time']??''),$_POST['match_date']??date('Y-m-d'),floatval($_POST['odds_home']??2.1),floatval($_POST['odds_draw']??3.4),floatval($_POST['odds_away']??3.6),in_array($sec,['both','popular'])?1:0,in_array($sec,['both','today'])?1:0,!empty($_POST['live_at'])?$_POST['live_at']:null,!empty($_POST['end_at'])?$_POST['end_at']:null,$manualLocked,$manualLocked,$saId,$finalSH,$finalSA]);
        echo json_encode(['success'=>true,'message'=>'Match added!']);
    } catch(Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    break;

case 'update_match':
    $mid = (int)($_POST['id']??0);
    $chk = $pdo->prepare("SELECT id FROM admin_matches WHERE id=? AND sub_admin_id=?"); $chk->execute([$mid,$saId]);
    if (!$chk->fetch()) { echo json_encode(['success'=>false,'message'=>'Match not found']); break; }
    $fields=[]; $vals=[]; $allowed=['home_team','away_team','home_logo','away_logo','league','match_time','match_date','odds_home','odds_draw','odds_away','score_home','score_away','status','live_at','end_at'];
    foreach ($allowed as $f) { if (array_key_exists($f,$_POST)) { $fields[]="`$f`=?"; $vals[]=in_array($f,['odds_home','odds_draw','odds_away'])?(float)$_POST[$f]:trim($_POST[$f]); } }
    if (isset($_POST['odds_locked'])) {
        $fields[]="odds_locked=?"; $vals[]=(int)$_POST['odds_locked'];
        $fields[]="odds_manual_locked=?"; $vals[]=(int)$_POST['odds_locked'];
    }
    if (empty($fields)) { echo json_encode(['success'=>false,'message'=>'Nothing to update']); break; }
    $vals[]=$mid;
    $pdo->prepare("UPDATE admin_matches SET ".implode(',',$fields)." WHERE id=?")->execute($vals);
    echo json_encode(['success'=>true,'message'=>'Match updated!']);
    break;

case 'update_score':
    $mid=$_POST['id']??0; $chk=$pdo->prepare("SELECT id FROM admin_matches WHERE id=? AND sub_admin_id=?"); $chk->execute([$mid,$saId]);
    if (!$chk->fetch()) { echo json_encode(['success'=>false,'message'=>'Match not found']); break; }
    $pdo->prepare("UPDATE admin_matches SET score_home=?,score_away=?,status=?,odds_suspended_until=DATE_ADD(NOW(), INTERVAL 12 SECOND) WHERE id=?")->execute([trim($_POST['score_home']??''),trim($_POST['score_away']??''),trim($_POST['status']??'1H'),$mid]);
    echo json_encode(['success'=>true,'message'=>'Score updated!']);
    break;

case 'lock_odds':
    $mid=$_POST['id']??0; $locked=(int)($_POST['locked']??0);
    $chk=$pdo->prepare("SELECT id FROM admin_matches WHERE id=? AND sub_admin_id=?"); $chk->execute([$mid,$saId]);
    if (!$chk->fetch()) { echo json_encode(['success'=>false,'message'=>'Match not found']); break; }
    $pdo->prepare("UPDATE admin_matches SET odds_locked=?, odds_manual_locked=? WHERE id=?")->execute([$locked,$locked,$mid]);
    echo json_encode(['success'=>true,'message'=>$locked?'Odds locked':'Odds unlocked']);
    break;

case 'delete_match':
    $mid=(int)($_POST['id']??0); $chk=$pdo->prepare("SELECT id FROM admin_matches WHERE id=? AND sub_admin_id=?"); $chk->execute([$mid,$saId]);
    if (!$chk->fetch()) { echo json_encode(['success'=>false,'message'=>'Match not found']); break; }
    $pdo->prepare("UPDATE admin_matches SET is_active=0 WHERE id=?")->execute([$mid]);
    echo json_encode(['success'=>true]);
    break;

// BOOKING CODES
case 'code_list':
    $r=$pdo->prepare("SELECT ac.*, COUNT(acs.id) AS sel_count, SUM(CASE WHEN acs.hidden=1 THEN 1 ELSE 0 END) AS hidden_count FROM admin_codes ac LEFT JOIN admin_code_selections acs ON acs.code_id=ac.id WHERE ac.sub_admin_id=? GROUP BY ac.id ORDER BY ac.created_at DESC LIMIT 100");
    $r->execute([$saId]); echo json_encode(['success'=>true,'codes'=>$r->fetchAll()]); break;

case 'create_code':
    $code=strtoupper(trim($_POST['code']??''))?:strtoupper(substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'),0,7));
    $stake=(float)($_POST['stake']??0); $minStake=(float)($_POST['min_stake']??0);
    $revealAt=trim($_POST['reveal_at']??'')?:null; $isRev=isset($_POST['is_revealed'])?(int)$_POST['is_revealed']:1;
    $sels=json_decode($_POST['selections']??'[]',true)?:[];
    if (empty($sels)) { echo json_encode(['success'=>false,'message'=>'Add at least one selection']); break; }
    $dup=$pdo->prepare("SELECT COUNT(*) FROM admin_codes WHERE code=?"); $dup->execute([$code]);
    if ($dup->fetchColumn()) { echo json_encode(['success'=>false,'message'=>'Code already exists']); break; }
    $totalOdds=array_reduce($sels,fn($c,$s)=>$c*max(1.0,(float)($s['odds']??1)),1.0);
    $potWin=round($stake*$totalOdds,2);
    try {
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO admin_codes (code,stake,min_stake,total_odds,pot_win,is_revealed,reveal_at,sub_admin_id,source_type) VALUES (?,?,?,?,?,?,?,?,'manual')")->execute([$code,$stake,$minStake,round($totalOdds,2),$potWin,$isRev,$revealAt,$saId]);
        $codeId=$pdo->lastInsertId();
        $ins=$pdo->prepare("INSERT INTO admin_code_selections (code_id,home_team,away_team,league,market,pick,odds,match_time,score,hidden) VALUES (?,?,?,?,?,?,?,?,?,?)");
        foreach ($sels as $s) {
            $tv=explode(' vs ',$s['teams']??($s['home']??'').' vs '.($s['away']??''),2);
            $mp=explode(' — ',$s['market_pick']??($s['market']??'Match Result').' — '.($s['pick']??'Pick'),2);
            $ins->execute([$codeId,trim($tv[0]??'Home'),trim($tv[1]??'Away'),$s['league']??'Soccer',trim($mp[0]??'Match Result'),trim($mp[1]??'Pick'),(float)($s['odds']??1),$s['time']??$s['match_time']??'Today',$s['score']??'N/A',(int)($s['hidden']??0)]);
        }
        $pdo->commit();
        echo json_encode(['success'=>true,'code'=>$code,'code_id'=>$codeId]);
    } catch(Exception $e) { $pdo->rollBack(); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    break;

case 'import_sporty':
    $sportyCode=strtoupper(trim($_POST['sporty_code']??'')); $internalCode=strtoupper(trim($_POST['internal_code']??''))?:strtoupper(substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'),0,7));
    $isRev=(int)($_POST['is_revealed']??1); $revealAt=trim($_POST['reveal_at']??'')?:null; $hideAll=(int)($_POST['hide_all']??0); $minStake=(float)($_POST['min_stake']??0);
    if (!$sportyCode) { echo json_encode(['success'=>false,'message'=>'Sporty code required']); break; }
    $dup=$pdo->prepare("SELECT COUNT(*) FROM admin_codes WHERE code=?"); $dup->execute([$internalCode]);
    if ($dup->fetchColumn()) $internalCode=strtoupper(substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'),0,7));
    $url="https://www.sportybet.com/api/gh/orders/share/".rawurlencode($sportyCode);
    $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>8,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_HTTPHEADER=>['User-Agent: Mozilla/5.0','Origin: https://www.sportybet.com','Referer: https://www.sportybet.com/gh/']]);
    $resp=curl_exec($ch); curl_close($ch);
    if (!$resp) { echo json_encode(['success'=>false,'message'=>'Could not reach Sportybet']); break; }
    $d=json_decode($resp,true); if (!$d||empty($d['data'])) { echo json_encode(['success'=>false,'message'=>'Invalid code']); break; }
    $data=$d['data']; $items=$data['outcomes']??$data['selections']??[]; $stake=(float)($data['stake']??$data['stakeAmount']??0);
    $totalOdds=1; foreach ($items as $it) $totalOdds*=max(1,(float)($it['odds']??$it['odd']??1));
    try {
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO admin_codes (code,source_type,sporty_code,stake,min_stake,total_odds,pot_win,is_revealed,reveal_at,sub_admin_id) VALUES (?,?,?,?,?,?,?,?,?,?)")->execute([$internalCode,'sporty',$sportyCode,$stake,$minStake,round($totalOdds,2),round($stake*$totalOdds,2),$isRev,$revealAt,$saId]);
        $codeId=$pdo->lastInsertId();
        $ins=$pdo->prepare("INSERT INTO admin_code_selections (code_id,home_team,away_team,league,market,pick,odds,match_time,score,hidden) VALUES (?,?,?,?,?,?,?,?,?,?)");
        foreach ($items as $it) {
            $home=$it['homeTeamName']??$it['home_team']??'Home'; $away=$it['awayTeamName']??$it['away_team']??'Away';
            $pick=$it['desc']??$it['outcomeDesc']??$it['outcomeName']??'Pick'; $odds=(float)($it['odds']??$it['odd']??1);
            $market=$it['marketDesc']??$it['marketName']??'Match Result';
            $t=$it['startTime']??$it['estimateStartTime']??'Today'; if ($t!=='Today'&&is_numeric($t)) $t=date('d/m H:i',(int)$t/1000);
            $ins->execute([$codeId,$home,$away,'Soccer',$market,$pick,$odds,$t,'N/A',$hideAll]);
        }
        $pdo->commit();
        echo json_encode(['success'=>true,'internal_code'=>$internalCode,'selections_count'=>count($items),'code_id'=>$codeId]);
    } catch(Exception $e) { $pdo->rollBack(); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    break;

case 'get_code_selections':
    $id=(int)($_GET['id']??$_POST['id']??0);
    if (!ownCode($pdo,$id,$saId)) { echo json_encode(['success'=>false,'message'=>'Not found']); break; }
    $r=$pdo->prepare("SELECT * FROM admin_code_selections WHERE code_id=? ORDER BY id ASC"); $r->execute([$id]);
    $cr=$pdo->prepare("SELECT * FROM admin_codes WHERE id=?"); $cr->execute([$id]);
    echo json_encode(['success'=>true,'selections'=>$r->fetchAll(),'code'=>$cr->fetch()]); break;

case 'toggle_code_hide':
    $id=(int)($_POST['id']??0); $hide=(int)($_POST['hide']??1);
    if (!ownCode($pdo,$id,$saId)) { echo json_encode(['success'=>false,'message'=>'Not found']); break; }
    $pdo->prepare("UPDATE admin_code_selections SET hidden=? WHERE code_id=?")->execute([$hide,$id]);
    if (!$hide) $pdo->prepare("UPDATE admin_codes SET is_revealed=1 WHERE id=?")->execute([$id]);
    echo json_encode(['success'=>true]); break;

case 'toggle_sel_hide':
    $selId=(int)($_POST['sel_id']??0); $hide=(int)($_POST['hide']??1);
    $r=$pdo->prepare("SELECT acs.id FROM admin_code_selections acs JOIN admin_codes ac ON ac.id=acs.code_id WHERE acs.id=? AND ac.sub_admin_id=?"); $r->execute([$selId,$saId]);
    if (!$r->fetch()) { echo json_encode(['success'=>false,'message'=>'Not found']); break; }
    $pdo->prepare("UPDATE admin_code_selections SET hidden=? WHERE id=?")->execute([$hide,$selId]);
    echo json_encode(['success'=>true]); break;

case 'update_code_selection':
    $selId=(int)($_POST['sel_id']??0);
    $r=$pdo->prepare("SELECT acs.id FROM admin_code_selections acs JOIN admin_codes ac ON ac.id=acs.code_id WHERE acs.id=? AND ac.sub_admin_id=?"); $r->execute([$selId,$saId]);
    if (!$r->fetch()) { echo json_encode(['success'=>false,'message'=>'Not found']); break; }
    $map=['home_team','away_team','league','market','pick','odds','match_time','score','hidden']; $fields=[]; $vals=[];
    foreach ($map as $f) { if (array_key_exists($f,$_POST)) { $fields[]="`$f`=?"; $vals[]=$f==='odds'?(float)$_POST[$f]:$_POST[$f]; } }
    if (empty($fields)) { echo json_encode(['success'=>false,'message'=>'Nothing to update']); break; }
    $vals[]=$selId; $pdo->prepare("UPDATE admin_code_selections SET ".implode(',',$fields)." WHERE id=?")->execute($vals);
    echo json_encode(['success'=>true]); break;

case 'reveal_code':
    $id=(int)($_POST['id']??0); if (!ownCode($pdo,$id,$saId)) { echo json_encode(['success'=>false,'message'=>'Not found']); break; }
    $pdo->prepare("UPDATE admin_codes SET is_revealed=1 WHERE id=?")->execute([$id]);
    $pdo->prepare("UPDATE admin_code_selections SET hidden=0 WHERE code_id=?")->execute([$id]);
    echo json_encode(['success'=>true]); break;

case 'code_tickets':
    $codeVal=strtoupper(trim($_GET['code']??$_POST['code']??''));
    if (!$codeVal) { echo json_encode(['success'=>false,'message'=>'No code']); break; }
    // Full-Control agents manage their own codes globally, like main-admin codes.
    $r=$pdo->prepare("SELECT id FROM admin_codes WHERE code=? AND sub_admin_id=?"); $r->execute([$codeVal,$saId]);
    if (!$r->fetch()) { echo json_encode(['success'=>false,'message'=>'Code not found']); break; }
    $stmt=$pdo->prepare("SELECT t.id,t.ticket_code,t.booking_code,t.stake_amount,t.potential_win,t.status,t.bet_date,t.verification_code,u.username,u.id AS user_id FROM tickets t JOIN users u ON u.id=t.user_id WHERE (t.verification_code=? OR t.booking_code=?) GROUP BY t.id ORDER BY t.bet_date DESC");
    $stmt->execute([$codeVal,$codeVal]);
    $tickets=$stmt->fetchAll();
    $acRow=$pdo->prepare("SELECT settled_as FROM admin_codes WHERE code=?"); $acRow->execute([$codeVal]);
    echo json_encode(['success'=>true,'tickets'=>$tickets,'count'=>count($tickets),'total_stake'=>array_sum(array_column($tickets,'stake_amount')),'settled_as'=>($acRow->fetch()?:[])['settled_as']??null]); break;

case 'settle_code':
    $codeVal=strtoupper(trim($_POST['code']??'')); $outcome=trim($_POST['outcome']??'');
    if (!in_array($outcome,['Won','Lost','Void'])) { echo json_encode(['success'=>false,'message'=>'Invalid outcome']); break; }
    $r=$pdo->prepare("SELECT id FROM admin_codes WHERE code=? AND sub_admin_id=?"); $r->execute([$codeVal,$saId]);
    if (!$r->fetch()) { echo json_encode(['success'=>false,'message'=>'Code not found']); break; }
    $stmt=$pdo->prepare("SELECT t.id,t.user_id,t.stake_amount,t.potential_win,t.status AS prev_status FROM tickets t WHERE (t.verification_code=? OR t.booking_code=?) GROUP BY t.id");
    $stmt->execute([$codeVal,$codeVal]);
    $tickets=$stmt->fetchAll();
    if (empty($tickets)) { echo json_encode(['success'=>false,'message'=>'No tickets found for this code']); break; }
    $pdo->beginTransaction();
    try {
        $settled=0;
        foreach ($tickets as $t) {
            $prev=$t['prev_status'];
            // Reverse previous credit
            if ($prev==='Won' && $outcome!=='Won')
                $pdo->prepare("UPDATE users SET balance=GREATEST(0,balance-?) WHERE id=?")->execute([$t['potential_win'],$t['user_id']]);
            if ($prev==='Void' && $outcome!=='Void')
                $pdo->prepare("UPDATE users SET balance=GREATEST(0,balance-?) WHERE id=?")->execute([$t['stake_amount'],$t['user_id']]);
            saSyncTicketMatchScores($pdo, (int)$t['id'], $outcome);
            $pdo->prepare("UPDATE tickets SET status=?,settle_time=NOW() WHERE id=?")->execute([$outcome,$t['id']]);
            if ($outcome==='Won' && $prev!=='Won')
                $pdo->prepare("UPDATE users SET balance=balance+? WHERE id=?")->execute([$t['potential_win'],$t['user_id']]);
            elseif ($outcome==='Void' && $prev!=='Void')
                $pdo->prepare("UPDATE users SET balance=balance+? WHERE id=?")->execute([$t['stake_amount'],$t['user_id']]);
            $settled++;
        }
        $pdo->prepare("UPDATE admin_codes SET settled_as=? WHERE code=?")->execute([$outcome,$codeVal]);
        $pdo->commit(); echo json_encode(['success'=>true,'settled'=>$settled,'outcome'=>$outcome,'message'=>"{$settled} ticket(s) set to {$outcome}"]);
    } catch(Exception $e) { $pdo->rollBack(); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    break;

case 'delete_code':
    $id=(int)($_POST['id']??0); if (!ownCode($pdo,$id,$saId)) { echo json_encode(['success'=>false,'message'=>'Not yours']); break; }
    $pdo->prepare("DELETE FROM admin_codes WHERE id=?")->execute([$id]); echo json_encode(['success'=>true]); break;

// TICKETS
case 'get_tickets':
    $status=$_POST['status']??'Running';
    $stmt=$pdo->prepare("SELECT t.*,u.username FROM tickets t JOIN users u ON u.id=t.user_id WHERE t.status=? ORDER BY t.bet_date DESC LIMIT 200");
    $stmt->execute([$status]); echo json_encode(['success'=>true,'tickets'=>$stmt->fetchAll()]); break;

case 'settle_ticket':
    $tid=(int)($_POST['id']??0); $outcome=$_POST['outcome']??'';
    if (!in_array($outcome,['Won','Lost','Void'])) { echo json_encode(['success'=>false,'message'=>'Invalid outcome']); break; }
    // Full-Control agents settle tickets site-wide like main admin.
    $chk=$pdo->prepare("SELECT * FROM tickets WHERE id=?"); $chk->execute([$tid]); $t=$chk->fetch();
    if (!$t) { echo json_encode(['success'=>false,'message'=>'Ticket not found']); break; }
    $prev = $t['status'];
    $pdo->beginTransaction();
    try {
        // Reverse previous credit if changing from Won/Void
        if ($prev==='Won' && $outcome!=='Won')
            $pdo->prepare("UPDATE users SET balance=GREATEST(0,balance-?) WHERE id=?")->execute([$t['potential_win'],$t['user_id']]);
        if ($prev==='Void' && $outcome!=='Void')
            $pdo->prepare("UPDATE users SET balance=GREATEST(0,balance-?) WHERE id=?")->execute([$t['stake_amount'],$t['user_id']]);
        saSyncTicketMatchScores($pdo, (int)$tid, $outcome);
        $pdo->prepare("UPDATE tickets SET status=?,settle_time=NOW() WHERE id=?")->execute([$outcome,$tid]);
        if ($outcome==='Won' && $prev!=='Won')
            $pdo->prepare("UPDATE users SET balance=balance+? WHERE id=?")->execute([$t['potential_win'],$t['user_id']]);
        elseif ($outcome==='Void' && $prev!=='Void')
            $pdo->prepare("UPDATE users SET balance=balance+? WHERE id=?")->execute([$t['stake_amount'],$t['user_id']]);
        $pdo->commit();
        echo json_encode(['success'=>true,'outcome'=>$outcome,'prev'=>$prev]);
    } catch(Exception $e) { $pdo->rollBack(); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    break;

// ── Close ALL running tickets site-wide for Full-Control agents ─────────
case 'close_all_running':
    $outcome = $_POST['outcome'] ?? 'Lost';
    if (!in_array($outcome, ['Won','Lost','Void'])) { echo json_encode(['success'=>false,'message'=>'Invalid outcome']); break; }
    $stmt = $pdo->prepare("SELECT * FROM tickets WHERE status='Running'");
    $stmt->execute();
    $running = $stmt->fetchAll();
    if (!$running) { echo json_encode(['success'=>true,'closed'=>0,'message'=>'No running tickets found']); break; }
    $closed = 0; $credited = 0.0;
    $pdo->beginTransaction();
    try {
        foreach ($running as $t) {
            saSyncTicketMatchScores($pdo, (int)$t['id'], $outcome);
            $pdo->prepare("UPDATE tickets SET status=?,settle_time=NOW() WHERE id=?")->execute([$outcome, $t['id']]);
            if ($outcome === 'Won') {
                $pdo->prepare("UPDATE users SET balance=balance+? WHERE id=?")->execute([$t['potential_win'], $t['user_id']]);
                $credited += (float)$t['potential_win'];
            } elseif ($outcome === 'Void') {
                $pdo->prepare("UPDATE users SET balance=balance+? WHERE id=?")->execute([$t['stake_amount'], $t['user_id']]);
            }
            $closed++;
        }
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


case 'get_users':
    // Loaded only after the agent opens Referred Users. This view returns
    // commission totals only; deposit totals are deliberately excluded.
    $userColumns = ['u.id','u.username','u.email','u.created_at'];
    $userColumns[] = ps_table_column_exists($pdo,'users','first_name') ? 'u.first_name' : "'' AS first_name";
    $userColumns[] = ps_table_column_exists($pdo,'users','last_name') ? 'u.last_name' : "'' AS last_name";
    $userColumns[] = ps_table_column_exists($pdo,'users','phone') ? 'u.phone' : "'' AS phone";
    $stmt=$pdo->prepare("SELECT " . implode(',', $userColumns) . " FROM users u WHERE u.sub_admin_id=? OR u.linked_agent_id=? ORDER BY u.created_at DESC");
    $stmt->execute([$saId, $saId]);
    $users=$stmt->fetchAll(PDO::FETCH_ASSOC);
    $commissionStmt=$pdo->prepare("SELECT user_id,currency_code,COALESCE(SUM(commission_amt),0) AS amount FROM sub_admin_commissions WHERE sub_admin_id=? GROUP BY user_id,currency_code");
    $commissionStmt->execute([$saId]);
    $commissionByUser=[];
    foreach ($commissionStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $code=strtoupper(trim((string)($row['currency_code'] ?? 'GHS'))) ?: 'GHS';
        $meta=ps_currency_meta_from_code($code);
        $commissionByUser[(int)$row['user_id']][]=[
            'currency'=>$code,
            'symbol'=>(string)($meta['symbol'] ?? $code),
            'amount'=>round((float)$row['amount'],2),
        ];
    }
    foreach ($users as &$user) $user['commissions']=$commissionByUser[(int)$user['id']] ?? [];
    unset($user);
    echo json_encode(['success'=>true,'users'=>$users]); break;

case 'get_user_detail':
    $uid=(int)($_POST['user_id']??0);
    // Check ownership: user must have sub_admin_id matching this agent OR be linked via linked_agent_id
    $own=$pdo->prepare("SELECT id FROM users WHERE id=? AND (sub_admin_id=? OR linked_agent_id=?)");
    $own->execute([$uid,$saId,$saId]);
    if (!$own->fetch()) {
        // Fallback: check if in getSaUserIds list (covers legacy data)
        $uids=getSaUserIds($pdo,$saId);
        if (!in_array($uid,$uids)) { echo json_encode(['success'=>false,'message'=>'User not in your network']); break; }
    }
    try {
        $cols = ['id', 'username', 'email', 'balance', 'created_at'];
        $cols[] = ps_table_column_exists($pdo, 'users', 'phone') ? 'phone' : "'' AS phone";
        $cols[] = ps_table_column_exists($pdo, 'users', 'is_verified') ? 'is_verified' : '0 AS is_verified';
        $cols[] = ps_table_column_exists($pdo, 'users', 'bonus_balance') ? 'COALESCE(bonus_balance,0) AS bonus_balance' : '0 AS bonus_balance';
        $cols[] = ps_table_column_exists($pdo, 'users', 'is_banned') ? 'COALESCE(is_banned,0) AS is_banned' : '0 AS is_banned';
        $s=$pdo->prepare("SELECT " . implode(',', $cols) . " FROM users WHERE id=?");
        $s->execute([$uid]); $user=$s->fetch();
    } catch(Exception $e) {
        $s=$pdo->prepare("SELECT id,username,email,balance,created_at FROM users WHERE id=?");
        $s->execute([$uid]); $user=$s->fetch();
        if($user){$user['phone']='';$user['is_verified']=0;$user['bonus_balance']=0;$user['is_banned']=0;}
    }
    if (!$user) { echo json_encode(['success'=>false,'message'=>'Not found']); break; }
    $b=$pdo->prepare("SELECT COUNT(*) AS total,COALESCE(SUM(stake_amount),0) AS staked,COALESCE(SUM(CASE WHEN status='Won' THEN potential_win ELSE 0 END),0) AS won FROM tickets WHERE user_id=?"); $b->execute([$uid]); $user['bet_stats']=$b->fetch();
    echo json_encode(['success'=>true,'user'=>$user]); break;

case 'get_referral_commissions':
    $uid = (int)($_POST['user_id'] ?? 0);
    $own = $pdo->prepare("SELECT id FROM users WHERE id=? AND (sub_admin_id=? OR linked_agent_id=?) LIMIT 1");
    $own->execute([$uid, $saId, $saId]);
    if (!$own->fetchColumn()) {
        echo json_encode(['success'=>false,'message'=>'User not in your network']);
        break;
    }

    $sql = "SELECT sac.id, sac.commission_amt, sac.currency_code, sac.payout_paid_at,
                   " . ps_subadmin_effective_created_sql('sac', 'tx') . " AS earned_at,
                   " . saUserCurrencySelect($pdo, 'u') . "
            FROM sub_admin_commissions sac
            JOIN users u ON u.id=sac.user_id
            LEFT JOIN transactions tx ON tx.id=sac.tx_id
            WHERE sac.user_id=? AND sac.sub_admin_id=?
            ORDER BY earned_at DESC, sac.id DESC
            LIMIT 100";
    $commissionsStmt = $pdo->prepare($sql);
    $commissionsStmt->execute([$uid, $saId]);
    $commissions = [];
    foreach ($commissionsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row = saAddMoney($pdo, $row, 'commission_amt');
        $timestamp = !empty($row['earned_at']) ? strtotime((string)$row['earned_at']) : false;
        $commissions[] = [
            'id' => (int)$row['id'],
            'commission' => round((float)$row['commission_amt'], 2),
            'currency' => (string)($row['currency'] ?? 'GHS'),
            'currency_symbol' => (string)($row['currency_symbol'] ?? ''),
            'status' => !empty($row['payout_paid_at']) ? 'Paid' : 'Unpaid',
            'date' => $timestamp ? date('d M Y', $timestamp) : '',
            'time' => $timestamp ? date('h:i A', $timestamp) : '',
        ];
    }
    echo json_encode(['success'=>true,'commissions'=>$commissions,'count'=>count($commissions)]);
    break;

case 'fund_user':
    $uid=(int)($_POST['user_id']??0); $amount=floatval($_POST['amount']??0); $uids=getSaUserIds($pdo,$saId);
    if (!in_array($uid,$uids)) { echo json_encode(['success'=>false,'message'=>'Not in your network']); break; }
    $pdo->prepare("UPDATE users SET balance=balance+? WHERE id=?")->execute([$amount,$uid]);
    $nb=$pdo->prepare("SELECT balance FROM users WHERE id=?"); $nb->execute([$uid]);
    echo json_encode(['success'=>true,'message'=>'Balance updated','new_balance'=>(float)$nb->fetchColumn()]); break;

case 'set_balance':
    $uid=(int)($_POST['user_id']??0); $amount=floatval($_POST['amount']??0); $uids=getSaUserIds($pdo,$saId);
    if (!in_array($uid,$uids)) { echo json_encode(['success'=>false,'message'=>'Not in your network']); break; }
    $pdo->prepare("UPDATE users SET balance=? WHERE id=?")->execute([$amount,$uid]);
    echo json_encode(['success'=>true,'message'=>'Balance set to GHS '.number_format($amount,2),'new_balance'=>$amount]); break;

case 'ban_user': case 'unban_user':
    $uid=(int)($_POST['user_id']??0); $uids=getSaUserIds($pdo,$saId);
    if (!in_array($uid,$uids)) { echo json_encode(['success'=>false,'message'=>'Not in your network']); break; }
    $banned=($action==='ban_user')?1:0;
    try { $pdo->prepare("UPDATE users SET is_banned=? WHERE id=?")->execute([$banned,$uid]); }
    catch(Exception $e) { $pdo->exec("ALTER TABLE users ADD COLUMN is_banned TINYINT(1) DEFAULT 0"); $pdo->prepare("UPDATE users SET is_banned=? WHERE id=?")->execute([$banned,$uid]); }
    echo json_encode(['success'=>true,'message'=>$banned?'User banned':'User unbanned']); break;

case 'reset_user_password':
    $uid=(int)($_POST['user_id']??0); $newPass=trim($_POST['new_password']??''); $uids=getSaUserIds($pdo,$saId);
    if (!in_array($uid,$uids)) { echo json_encode(['success'=>false,'message'=>'Not in your network']); break; }
    if (strlen($newPass)<6) { echo json_encode(['success'=>false,'message'=>'Min 6 characters']); break; }
    $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([password_hash($newPass,PASSWORD_DEFAULT),$uid]);
    echo json_encode(['success'=>true,'message'=>'Password reset successfully']); break;

// DEPOSITS
case 'get_deposits':
    ps_repair_subadmin_deposit_accounting($pdo, 1000);
    $uids=getSaUserIds($pdo,$saId); $ph=implode(',',array_fill(0,count($uids),'?')); $status=$_POST['status']??'all';
    $ss=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN t.status='Completed' THEN t.amount ELSE 0 END),0) AS total,COALESCE(SUM(CASE WHEN t.status='Completed' AND t.created_at >= ? AND t.created_at < ? THEN t.amount ELSE 0 END),0) AS today,SUM(CASE WHEN t.status='Completed' THEN 1 ELSE 0 END) AS completed_count,SUM(CASE WHEN t.status='Pending' THEN 1 ELSE 0 END) AS pending_count FROM transactions t LEFT JOIN sub_admin_commission_exclusions sce ON sce.tx_id=t.id WHERE t.user_id IN ($ph) AND t.type='Deposit' AND COALESCE(t.method,'') <> 'Agent Self Fund' AND sce.tx_id IS NULL");
    $ss->execute(array_merge([$_todayStart, $_tomorrowStart], $uids)); $summary=$ss->fetch(PDO::FETCH_ASSOC);
    $sql="SELECT t.*,u.username," . saUserCurrencySelect($pdo, 'u') . ", sac.commission_amt FROM transactions t JOIN users u ON u.id=t.user_id LEFT JOIN sub_admin_commissions sac ON sac.tx_id = t.id LEFT JOIN sub_admin_commission_exclusions sce ON sce.tx_id=t.id WHERE t.user_id IN ($ph) AND t.type='Deposit' AND COALESCE(t.method,'') <> 'Agent Self Fund' AND sce.tx_id IS NULL"; $params=$uids;
    if ($status!=='all') { $sql.=" AND t.status=?"; $params[]=$status; }
    $sql.=" ORDER BY t.created_at DESC LIMIT 200"; $s=$pdo->prepare($sql); $s->execute($params);
    $depRows=[];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $depRows[] = saAddMoney($pdo, $row, 'amount');
    }
    $summary['total_usd'] = saSumUsd($pdo, array_filter($depRows, fn($r) => ($r['status'] ?? '') === 'Completed'), 'amount');
    $summary['today_usd'] = saSumUsd($pdo, array_filter($depRows, fn($r) => ($r['status'] ?? '') === 'Completed' && (string)($r['created_at'] ?? '') >= $_todayStart && (string)($r['created_at'] ?? '') < $_tomorrowStart), 'amount');
    echo json_encode(['success'=>true,'deposits'=>$depRows,'summary'=>$summary]); break;

case 'approve_deposit':
    $txId=(int)($_POST['tx_id']??0); $uids=getSaUserIds($pdo,$saId); $ph=implode(',',array_fill(0,count($uids),'?'));
    $chk=$pdo->prepare("SELECT * FROM transactions WHERE id=? AND user_id IN ($ph) AND type='Deposit' AND COALESCE(method,'') <> 'Agent Self Fund' AND status='Pending'"); $chk->execute(array_merge([$txId],$uids)); $tx=$chk->fetch();
    if (!$tx) { echo json_encode(['success'=>false,'message'=>'Not found or already processed']); break; }
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE transactions SET status='Completed',updated_at=NOW() WHERE id=?")->execute([$txId]);
        $pdo->prepare("UPDATE users SET balance=balance+? WHERE id=?")->execute([(float)$tx['amount'],(int)$tx['user_id']]);
        $pdo->commit();
        ps_award_subadmin_deposit_commission($pdo, (int)$tx['user_id'], (float)$tx['amount'], $txId, 'subadmin_approve_deposit');
        // Email user — deposit approved
        try {
            $uStmt = $pdo->prepare("SELECT email, username FROM users WHERE id=? LIMIT 1");
            $uStmt->execute([(int)$tx['user_id']]);
            $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
            if ($uRow && filter_var($uRow['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
                $balStmt = $pdo->prepare("SELECT balance FROM users WHERE id=? LIMIT 1");
                $balStmt->execute([(int)$tx['user_id']]);
                $newBal = (float)$balStmt->fetchColumn();
                sw_email_deposit($uRow['email'], $uRow['username'] ?? '', (float)$tx['amount'], (string)($tx['reference'] ?? $tx['tx_reference'] ?? $txId), $newBal);
            }
        } catch (Throwable $me) { /* non-fatal */ }
        echo json_encode(['success'=>true,'message'=>'Approved & credited']);
    } catch(Exception $e) { $pdo->rollBack(); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    break;

case 'reject_deposit':
    $txId=(int)($_POST['tx_id']??0); $uids=getSaUserIds($pdo,$saId); $ph=implode(',',array_fill(0,count($uids),'?'));
    $chk=$pdo->prepare("SELECT t.*, u.email, u.username, " . saUserCurrencySelect($pdo, 'u') . " FROM transactions t JOIN users u ON u.id=t.user_id WHERE t.id=? AND t.user_id IN ($ph) AND t.type='Deposit' AND COALESCE(t.method,'') <> 'Agent Self Fund' AND t.status='Pending'"); $chk->execute(array_merge([$txId],$uids)); $txRow=$chk->fetch();
    if (!$txRow) { echo json_encode(['success'=>false,'message'=>'Not found']); break; }
    $pdo->prepare("UPDATE transactions SET status='Rejected',updated_at=NOW() WHERE id=?")->execute([$txId]);
    // Email user — deposit rejected
    try {
        if (!empty($txRow['email']) && filter_var($txRow['email'], FILTER_VALIDATE_EMAIL)) {
            $rejCur = saRowCurrency($txRow)['code'] ?? 'GHS';
            sw_email_deposit_rejected($txRow['email'], $txRow['username'] ?? '', (float)$txRow['amount'], $rejCur);
        }
    } catch (Throwable $me) { /* non-fatal */ }
    echo json_encode(['success'=>true,'message'=>'Rejected']);
    break;

// WITHDRAWALS
case 'get_user_withdrawals':
    $uids=getSaUserIds($pdo,$saId); $ph=implode(',',array_fill(0,count($uids),'?')); $status=$_POST['status']??'all';
    $userPhone = ps_table_column_exists($pdo, 'users', 'phone') ? 'u.phone AS user_phone' : "'' AS user_phone";
    $sql="SELECT t.*,u.username,{$userPhone} FROM transactions t JOIN users u ON u.id=t.user_id WHERE t.user_id IN ($ph) AND t.type='Withdrawal'"; $params=$uids;
    if ($status!=='all') { $sql.=" AND t.status=?"; $params[]=$status; }
    $sql.=" ORDER BY t.created_at DESC LIMIT 200"; $s=$pdo->prepare($sql); $s->execute($params);
    echo json_encode(['success'=>true,'withdrawals'=>$s->fetchAll()]); break;

case 'approve_user_withdrawal':
    $txId=(int)($_POST['tx_id']??0); $uids=getSaUserIds($pdo,$saId); $ph=implode(',',array_fill(0,count($uids),'?'));
    $chk=$pdo->prepare("SELECT t.*, u.email, u.username, " . saUserCurrencySelect($pdo,'u') . " FROM transactions t JOIN users u ON u.id=t.user_id WHERE t.id=? AND t.user_id IN ($ph) AND t.type='Withdrawal' AND t.status='Pending'"); $chk->execute(array_merge([$txId],$uids)); $wdRow=$chk->fetch();
    if (!$wdRow) { echo json_encode(['success'=>false,'message'=>'Not found or already processed']); break; }
    $pdo->prepare("UPDATE transactions SET status='Completed',updated_at=NOW() WHERE id=?")->execute([$txId]);
    // Email user — withdrawal approved
    try {
        if (!empty($wdRow['email']) && filter_var($wdRow['email'], FILTER_VALIDATE_EMAIL)) {
            [$wdNet, $wdPh] = array_pad(explode(' — ', $wdRow['tx_reference'] ?? '', 2), 2, '');
            sw_email_withdrawal_completed($wdRow['email'], $wdRow['username'] ?? '', (float)$wdRow['amount'], trim($wdPh), trim($wdNet ?: $wdRow['method'] ?? ''));
        }
    } catch (Throwable $me) { /* non-fatal */ }
    echo json_encode(['success'=>true,'message'=>'Marked completed']);
    break;

case 'reject_user_withdrawal':
    $txId=(int)($_POST['tx_id']??0); $uids=getSaUserIds($pdo,$saId); $ph=implode(',',array_fill(0,count($uids),'?'));
    $chk=$pdo->prepare("SELECT t.*, u.email, u.username, " . saUserCurrencySelect($pdo,'u') . " FROM transactions t JOIN users u ON u.id=t.user_id WHERE t.id=? AND t.user_id IN ($ph) AND t.type='Withdrawal' AND t.status='Pending'"); $chk->execute(array_merge([$txId],$uids)); $tx=$chk->fetch();
    if (!$tx) { echo json_encode(['success'=>false,'message'=>'Not found']); break; }
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE transactions SET status='Rejected',updated_at=NOW() WHERE id=?")->execute([$txId]);
        $pdo->prepare("UPDATE users SET balance=balance+? WHERE id=?")->execute([(float)$tx['amount'],(int)$tx['user_id']]);
        $pdo->commit();
        // Email user — withdrawal rejected & refunded
        try {
            if (!empty($tx['email']) && filter_var($tx['email'], FILTER_VALIDATE_EMAIL)) {
                $wdCur = saRowCurrency($tx)['code'] ?? 'GHS';
                [$wdNet, $wdPh] = array_pad(explode(' — ', $tx['tx_reference'] ?? '', 2), 2, '');
                sw_email_withdrawal_rejected($tx['email'], $tx['username'] ?? '', (float)$tx['amount'], $wdCur, trim($wdPh), trim($wdNet ?: $tx['method'] ?? ''));
            }
        } catch (Throwable $me) { /* non-fatal */ }
        echo json_encode(['success'=>true,'message'=>'Rejected & refunded']);
    } catch(Exception $e) { $pdo->rollBack(); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    break;

// FORCE BACKFILL — agent can trigger commission sync for historical deposits
case 'force_backfill':
    try {
        $limit = max(100, min(10000, (int)($_POST['limit'] ?? 5000)));
        $stats = ps_backfill_subadmin_deposit_commissions($pdo, $limit);
        try { ps_maybe_rebuild_subadmin_currency_balances($pdo); } catch(Throwable $e2) {}
        echo json_encode(['success'=>true,'message'=>'Commission sync complete','stats'=>$stats]);
    } catch(Throwable $e) {
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    break;

// COMMISSIONS
case 'get_commissions':
    ps_repair_subadmin_deposit_accounting($pdo, 1000);
    $comms=$pdo->prepare("SELECT sac.id,sac.user_id,sac.commission_amt,sac.currency_code,sac.payout_paid_at,sac.created_at,u.username," . saUserCurrencySelect($pdo, 'u') . " FROM sub_admin_commissions sac JOIN users u ON u.id=sac.user_id WHERE sac.sub_admin_id=? ORDER BY sac.created_at DESC LIMIT 100"); $comms->execute([$saId]);
    $wds=$pdo->prepare("SELECT * FROM sub_admin_withdrawals WHERE sub_admin_id=? ORDER BY created_at DESC LIMIT 50"); $wds->execute([$saId]);
    $pendingWd=$pdo->prepare("SELECT amount, currency_code FROM sub_admin_withdrawals WHERE sub_admin_id=? AND status='Pending'");
    $pendingWd->execute([$saId]);
    $pendingRows=$pendingWd->fetchAll(PDO::FETCH_ASSOC);
    $pendingTotal=array_sum(array_map(fn($row)=>(float)($row['amount'] ?? 0), $pendingRows));
    $pendingByCurrency=saCurrencyBreakdown($pdo, $pendingRows, 'amount');
    try {
        $payouts=$pdo->prepare("SELECT i.*, b.batch_code, b.period_start, b.period_end FROM sub_admin_payout_items i JOIN sub_admin_payout_batches b ON b.id=i.batch_id WHERE i.sub_admin_id=? ORDER BY i.created_at DESC LIMIT 50");
        $payouts->execute([$saId]);
        $payoutRows=$payouts->fetchAll();
    } catch(Exception $e) { $payoutRows=[]; }
    $commissionRows=[];
    foreach ($comms->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row = saAddMoney($pdo, $row, 'commission_amt');
        $row['commission_amt_usd'] = ps_local_to_usd((float)($row['commission_amt'] ?? 0), $row['currency'], $pdo);
        $commissionRows[] = $row;
    }
    foreach ($payoutRows as &$p) {
        $code = strtoupper(trim((string)($p['currency_code'] ?? 'GHS'))) ?: 'GHS';
        $meta = ps_currency_meta_from_code($code);
        $p['currency'] = $code;
        $p['currency_symbol'] = $meta['symbol'] ?? $code;
        $p['commission_total_usd'] = ps_local_to_usd((float)($p['commission_total'] ?? 0), $code, $pdo);
        $p['adjustment_amount_usd'] = ps_local_to_usd((float)($p['adjustment_amount'] ?? 0), $code, $pdo);
        $p['payout_amount_usd'] = ps_local_to_usd((float)($p['payout_amount'] ?? 0), $code, $pdo);
    }
    unset($p);
    $withdrawalRows=$wds->fetchAll(PDO::FETCH_ASSOC);
    foreach ($withdrawalRows as &$w) {
        $code = strtoupper(trim((string)($w['currency_code'] ?? 'GHS'))) ?: 'GHS';
        $meta = ps_currency_meta_from_code($code);
        $w['currency'] = $code;
        $w['currency_symbol'] = $meta['symbol'] ?? $code;
        $w['amount_usd'] = ps_local_to_usd((float)($w['amount'] ?? 0), $code, $pdo);
    }
    unset($w);
    $unpaidStmt=$pdo->prepare("SELECT sac.commission_amt, sac.currency_code, " . saUserCurrencySelect($pdo, 'u') . " FROM sub_admin_commissions sac JOIN users u ON u.id=sac.user_id WHERE sac.sub_admin_id=? AND sac.payout_paid_at IS NULL AND sac.payout_item_id IS NULL");
    $unpaidStmt->execute([$saId]);
    $unpaidRows=$unpaidStmt->fetchAll(PDO::FETCH_ASSOC);
    $balanceByCurrency=saCurrencyBalanceRows($pdo, $saId, 'balance') ?: saCurrencyBreakdown($pdo, $unpaidRows, 'commission_amt');
    $depositByCurrency=saCurrencyBalanceRows($pdo, $saId, 'total_deposits');
    $earnedByCurrency=saCurrencyBalanceRows($pdo, $saId, 'total_earned');
    $availableByCurrency=$balanceByCurrency;
    $balance=(float)$sa['balance'];
    echo json_encode([
        'success'=>true,
        'balance'=>$balance,
        'balance_usd'=>array_sum(array_map(fn($row)=>(float)($row['amount_usd'] ?? 0), $balanceByCurrency)),
        'balance_by_currency'=>$balanceByCurrency,
        'total_deposits_by_currency'=>$depositByCurrency,
        'total_earned_by_currency'=>$earnedByCurrency,
        'pending_withdrawals'=>$pendingTotal,
        'pending_withdrawals_usd'=>array_sum(array_map(fn($row)=>(float)($row['amount_usd'] ?? 0), $pendingByCurrency)),
        'pending_withdrawals_by_currency'=>$pendingByCurrency,
        'available_balance'=>$balance,
        'available_balance_usd'=>array_sum(array_map(fn($row)=>(float)($row['amount_usd'] ?? 0), $availableByCurrency)),
        'available_balance_by_currency'=>$availableByCurrency,
        'commissions'=>$commissionRows,
        'withdrawals'=>$withdrawalRows,
        'payouts'=>$payoutRows
    ]); break;

case 'withdraw':
    $amount=round((float)($_POST['amount']??0),2);
    $currencyCode=strtoupper(trim((string)($_POST['currency_code'] ?? $_POST['currency'] ?? 'GHS'))) ?: 'GHS';
    $network=trim($_POST['network']??'');
    $phone=trim($_POST['phone']??'');
    $notes=trim($_POST['notes']??'');
    if ($amount<=0) { echo json_encode(['success'=>false,'message'=>'Enter a valid withdrawal amount']); break; }
    if ($network==='') { echo json_encode(['success'=>false,'message'=>'Payment method/network is required']); break; }
    if ($phone==='') { echo json_encode(['success'=>false,'message'=>'Payment phone/account is required']); break; }
    try {
        $pdo->beginTransaction();
        $lock=$pdo->prepare("SELECT balance FROM sub_admins WHERE id=? FOR UPDATE");
        $lock->execute([$saId]);
        $currentBalance=(float)($lock->fetchColumn() ?: 0);
        $currencyLock=$pdo->prepare("SELECT balance FROM sub_admin_currency_balances WHERE sub_admin_id=? AND currency_code=? FOR UPDATE");
        $currencyLock->execute([$saId, $currencyCode]);
        $currencyBalance=$currencyLock->fetchColumn();
        $unpaidStmt=$pdo->prepare("SELECT sac.commission_amt, sac.currency_code, " . saUserCurrencySelect($pdo, 'u') . " FROM sub_admin_commissions sac JOIN users u ON u.id=sac.user_id WHERE sac.sub_admin_id=? AND sac.payout_paid_at IS NULL AND sac.payout_item_id IS NULL FOR UPDATE");
        $unpaidStmt->execute([$saId]);
        $available=(float)($currencyBalance !== false ? $currencyBalance : 0);
        if ($currencyBalance === false) {
            foreach (saCurrencyBreakdown($pdo, $unpaidStmt->fetchAll(PDO::FETCH_ASSOC), 'commission_amt') as $row) {
                if (strtoupper((string)$row['currency']) === $currencyCode) {
                    $available = (float)$row['amount'];
                    break;
                }
            }
        }
        if ($amount>$available+0.001) throw new Exception('Amount exceeds available ' . $currencyCode . ' commission balance.');
        if ($currencyBalance === false) {
            $pdo->prepare("INSERT INTO sub_admin_currency_balances (sub_admin_id,currency_code,balance,total_earned,total_deposits) VALUES (?,?,?,0,0)")
                ->execute([$saId,$currencyCode,$available]);
        }
        $pdo->prepare("UPDATE sub_admins SET balance=GREATEST(balance-?,0) WHERE id=?")
            ->execute([$amount,$saId]);
        ps_subadmin_update_currency_balance($pdo, $saId, $currencyCode, 0, 0, -1 * $amount);
        $pdo->prepare("INSERT INTO sub_admin_withdrawals (sub_admin_id,amount,currency_code,network,phone,notes,status,balance_deducted_at) VALUES (?,?,?,?,?,?,'Pending',NOW())")
            ->execute([$saId,$amount,$currencyCode,$network,$phone,$notes]);
        $pdo->commit();
        echo json_encode(['success'=>true,'message'=>'Withdrawal request submitted. The amount has been held from your agent balance pending admin approval.']);
    } catch(Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    break;

case 'save_telegram':
    $link=trim($_POST['telegram_link']??'');
    if ($link&&!filter_var($link,FILTER_VALIDATE_URL)) { echo json_encode(['success'=>false,'message'=>'Invalid URL']); break; }
    $pdo->prepare("UPDATE sub_admins SET telegram_link=? WHERE id=?")->execute([$link,$saId]);
    echo json_encode(['success'=>true,'message'=>'Telegram link saved!']); break;

case 'change_password':
    $pw=$_POST['password']??''; if (strlen($pw)<6) { echo json_encode(['success'=>false,'message'=>'Min 6 characters']); break; }
    $pdo->prepare("UPDATE sub_admins SET password=? WHERE id=?")->execute([password_hash($pw,PASSWORD_DEFAULT),$saId]);
    echo json_encode(['success'=>true,'message'=>'Password updated!']); break;

case 'get_my_profile':
    $r = $pdo->prepare("SELECT sa.id, sa.username, sa.email, sa.phone, sa.telegram_link, sa.referral_code, sa.commission_pct, sa.balance, sa.total_earned FROM sub_admins sa WHERE sa.id=?");
    $r->execute([$saId]);
    $profile = $r->fetch();
    echo json_encode(['success'=>true,'profile'=>$profile]);
    break;

case 'update_my_profile':
    $fields=[]; $vals=[];
    if (isset($_POST['email']) && trim($_POST['email'])) {
        if (!filter_var(trim($_POST['email']), FILTER_VALIDATE_EMAIL)) { echo json_encode(['success'=>false,'message'=>'Invalid email']); break; }
        $fields[]="email=?"; $vals[]=trim($_POST['email']);
    }
    if (isset($_POST['phone']) && trim($_POST['phone'])) { $fields[]="phone=?"; $vals[]=trim($_POST['phone']); }
    if (empty($fields)) { echo json_encode(['success'=>false,'message'=>'Nothing to update']); break; }
    $vals[]=$saId;
    $pdo->prepare("UPDATE sub_admins SET ".implode(',',$fields)." WHERE id=?")->execute($vals);
    echo json_encode(['success'=>true,'message'=>'Profile updated!']);
    break;

case 'from_admin_matches':
    $code=strtoupper(trim($_POST['code']??''))?:strtoupper(substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'),0,7));
    $stake=(float)($_POST['stake']??0); $minStake=(float)($_POST['min_stake']??0);
    $isRev=(int)($_POST['is_revealed']??1);
    $sels=json_decode($_POST['selections']??'[]',true)?:[];
    if (empty($sels)) { echo json_encode(['success'=>false,'message'=>'No selections']); break; }
    $sels = saApplyResultOdds($pdo, $sels);
    $dup=$pdo->prepare("SELECT COUNT(*) FROM admin_codes WHERE code=?"); $dup->execute([$code]);
    if ($dup->fetchColumn()) $code=strtoupper(substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'),0,7));
    $totalOdds=array_reduce($sels,fn($c,$s)=>$c*max(1.0,(float)($s['odds']??1)),1.0);
    try {
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO admin_codes (code,stake,min_stake,total_odds,pot_win,is_revealed,sub_admin_id,source_type) VALUES (?,?,?,?,?,?,?,'manual')")
            ->execute([$code,$stake,$minStake,round($totalOdds,2),round($stake*$totalOdds,2),$isRev,$saId]);
        $codeId=$pdo->lastInsertId();
        $ins=$pdo->prepare("INSERT INTO admin_code_selections (code_id,home_team,away_team,league,market,pick,odds,match_time,score,hidden,admin_match_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        foreach ($sels as $s) {
            $ins->execute([$codeId,
                $s['home_team']??'Home', $s['away_team']??'Away',
                $s['league']??'Soccer', $s['market']??'Match Result',
                $s['pick']??'Pick', (float)($s['odds']??1),
                $s['match_time']??'Today', 'N/A', 0,
                isset($s['admin_match_id'])?(int)$s['admin_match_id']:null
            ]);
        }
        $pdo->commit();
        echo json_encode(['success'=>true,'code'=>$code,'id'=>$codeId,'total_odds'=>round($totalOdds,2)]);
    } catch(Exception $e) { $pdo->rollBack(); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    break;

// ── Settle tickets by booking code (sub_admin version) ───────────────────────
case 'settle_code_users':
    $codeVal=strtoupper(trim($_POST['code']??''));
    $outcome=$_POST['outcome']??'';
    if (!$codeVal || !in_array($outcome,['Won','Lost','Void'])) {
        echo json_encode(['success'=>false,'message'=>'Invalid params']); break;
    }
    // Verify this code belongs to this Full-Control sub_admin, then settle all tickets site-wide.
    $codeCheck=$pdo->prepare("SELECT id FROM admin_codes WHERE code=? AND sub_admin_id=?");
    $codeCheck->execute([$codeVal,$saId]);
    if (!$codeCheck->fetch()) { echo json_encode(['success'=>false,'message'=>'Code not found']); break; }

    $stmt=$pdo->prepare("SELECT t.id,t.user_id,t.stake_amount,t.potential_win,t.status AS prev_status FROM tickets t WHERE (t.verification_code=? OR t.booking_code=?) GROUP BY t.id");
    $stmt->execute([$codeVal,$codeVal]);
    $tickets=$stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($tickets)) { echo json_encode(['success'=>false,'message'=>'No tickets found for this code']); break; }
    $pdo->beginTransaction();
    try {
        $settled=0;
        foreach ($tickets as $t) {
            $prev=$t['prev_status'];
            // Reverse previous credit if changing outcome
            if ($prev==='Won' && $outcome!=='Won')
                $pdo->prepare("UPDATE users SET balance=GREATEST(0,balance-?) WHERE id=?")->execute([$t['potential_win'],$t['user_id']]);
            if ($prev==='Void' && $outcome!=='Void')
                $pdo->prepare("UPDATE users SET balance=GREATEST(0,balance-?) WHERE id=?")->execute([$t['stake_amount'],$t['user_id']]);
            // Apply new outcome
            saSyncTicketMatchScores($pdo, (int)$t['id'], $outcome);
            $pdo->prepare("UPDATE tickets SET status=?,settle_time=NOW() WHERE id=?")->execute([$outcome,$t['id']]);
            if ($outcome==='Won' && $prev!=='Won')
                $pdo->prepare("UPDATE users SET balance=balance+? WHERE id=?")->execute([$t['potential_win'],$t['user_id']]);
            elseif ($outcome==='Void' && $prev!=='Void')
                $pdo->prepare("UPDATE users SET balance=balance+? WHERE id=?")->execute([$t['stake_amount'],$t['user_id']]);
            $settled++;
        }
        $pdo->prepare("UPDATE admin_codes SET settled_as=? WHERE code=?")->execute([$outcome,$codeVal]);
        $pdo->commit();
        echo json_encode(['success'=>true,'settled'=>$settled,'outcome'=>$outcome,'message'=>"{$settled} ticket(s) set to {$outcome}"]);
    } catch(Exception $e) { $pdo->rollBack(); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    break;

default: echo json_encode(['success'=>false,'message'=>'Unknown action']);
}
?>
