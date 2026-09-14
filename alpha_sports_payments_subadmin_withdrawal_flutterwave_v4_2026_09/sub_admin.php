<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'db.php';
require_once __DIR__ . '/currency_helper.php';
require_once __DIR__ . '/subadmin_deposit_helper.php';

if (!isset($_SESSION['sub_admin_id']) && isset($_SESSION['user_id'])) {
    try {
        $legacy = $pdo->prepare("SELECT sa.* FROM users u JOIN sub_admins sa ON sa.id=u.linked_agent_id WHERE u.id=? AND u.is_agent=1 AND sa.is_active=1 LIMIT 1");
        $legacy->execute([(int)$_SESSION['user_id']]);
        $legacySa = $legacy->fetch(PDO::FETCH_ASSOC);
        if ($legacySa) {
            $_SESSION['sub_admin_id'] = (int)$legacySa['id'];
            $_SESSION['sub_admin_username'] = (string)$legacySa['username'];
            $_SESSION['sub_admin_ref'] = (string)$legacySa['referral_code'];
        }
    } catch (Throwable $e) {}
}

if (!isset($_SESSION['sub_admin_id'])) {
    header("Location: sub_admin_login.php");
    exit;
}

ps_subadmin_ensure_commission_schema($pdo);
foreach ([
    "ALTER TABLE sub_admins ADD COLUMN payout_name VARCHAR(120) NULL",
    "ALTER TABLE sub_admins ADD COLUMN payout_network VARCHAR(80) NULL",
    "ALTER TABLE sub_admins ADD COLUMN payout_number VARCHAR(80) NULL"
] as $payoutMigration) {
    try { $pdo->exec($payoutMigration); } catch (Throwable $e) {}
}

$saId = (int)$_SESSION['sub_admin_id'];
$stmt = $pdo->prepare("SELECT * FROM sub_admins WHERE id=? AND is_active=1 LIMIT 1");
$stmt->execute([$saId]);
$sa = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$sa) {
    header("Location: sub_admin_logout.php");
    exit;
}

$commissionPct = (float)($sa['commission_pct'] ?? 0);
if ($commissionPct <= 0) {
    $commissionPct = ps_subadmin_default_commission_pct($pdo);
    try {
        $pdo->prepare("UPDATE sub_admins SET commission_pct=? WHERE id=?")->execute([$commissionPct, $saId]);
    } catch (Throwable $e) {}
}
$payoutName = trim((string)($sa['payout_name'] ?? ''));
$payoutNetwork = trim((string)($sa['payout_network'] ?? ''));
$payoutNumber = trim((string)($sa['payout_number'] ?? ''));

function ps_partner_money_rows(PDO $pdo, int $saId, ?string $dateFilter = null): array {
    $effectiveCreated = ps_subadmin_effective_created_sql('sac', 'tx');
    $sql = "SELECT currency_code, COALESCE(SUM(commission_amt),0) AS amount, COUNT(*) AS deposits
            FROM sub_admin_commissions sac
            LEFT JOIN transactions tx ON tx.id = sac.tx_id
            WHERE sac.sub_admin_id=?";
    $params = [$saId];
    if ($dateFilter === 'today') {
        [$todayStart, $tomorrowStart] = ps_subadmin_today_bounds();
        $todayStart = ps_subadmin_daily_cycle_start($pdo);
        $sql .= " AND {$effectiveCreated} >= ? AND {$effectiveCreated} < ?";
        $params[] = $todayStart;
        $params[] = $tomorrowStart;
    }
    $sql .= " GROUP BY sac.currency_code ORDER BY FIELD(sac.currency_code,'GHS','NGN','USD'), sac.currency_code";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return [];
    return array_map(function($row) {
        $code = strtoupper(trim((string)($row['currency_code'] ?? 'GHS'))) ?: 'GHS';
        $meta = ps_currency_meta_from_code($code);
        return [
            'currency' => $code,
            'symbol' => $meta['symbol'] ?? $code,
            'amount' => round((float)($row['amount'] ?? 0), 2),
            'deposits' => (int)($row['deposits'] ?? 0),
        ];
    }, $rows);
}

function ps_partner_money_text(array $rows): string {
    if (!$rows) return '-';
    return implode(' / ', array_map(function($row) {
        return ($row['symbol'] ?? '') . ' ' . number_format((float)($row['amount'] ?? 0), 2);
    }, $rows));
}

$referralCode = strtoupper((string)$sa['referral_code']);
$siteUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
$shareLink = $siteUrl . '/register.php?ref=' . urlencode($referralCode);

$refCountStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE sub_admin_id=? OR linked_agent_id=?");
$refCountStmt->execute([$saId, $saId]);
$referralCount = (int)$refCountStmt->fetchColumn();

$todayRows = ps_partner_money_rows($pdo, $saId, 'today');
$totalRows = ps_partner_money_rows($pdo, $saId);
$todayCommissions = array_sum(array_map(fn($row) => (int)$row['deposits'], $todayRows));
[$historyTodayStart] = ps_subadmin_today_bounds();
$historyTodayDate = substr($historyTodayStart, 0, 10);
$historyCycleStart = ps_subadmin_daily_cycle_start($pdo);
$historyEffectiveCreated = ps_subadmin_effective_created_sql('sac', 'tx');
$historyPaymentDate = ps_subadmin_payment_date_sql($historyEffectiveCreated);

$historyStmt = $pdo->prepare("
    SELECT {$historyPaymentDate} AS commission_date, 
           sac.currency_code, 
           COALESCE(SUM(sac.commission_amt), 0) AS day_total, 
           COUNT(*) AS tx_count, 
           SUM(CASE WHEN sac.payout_paid_at IS NOT NULL THEN 1 ELSE 0 END) AS paid_count
    FROM sub_admin_commissions sac
    LEFT JOIN transactions tx ON tx.id = sac.tx_id
    WHERE sac.sub_admin_id=?
      AND NOT ({$historyPaymentDate} = ? AND {$historyEffectiveCreated} < ?)
    GROUP BY {$historyPaymentDate}, sac.currency_code
    HAVING day_total > 0
    ORDER BY commission_date DESC, sac.currency_code LIMIT 180
");
$historyStmt->execute([$saId, $historyTodayDate, $historyCycleStart]);
$commissionHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

$commissionByDate = [];
foreach ($commissionHistory as $row) {
    $date = (string)($row['commission_date'] ?? '');
    if (!isset($commissionByDate[$date])) $commissionByDate[$date] = [];
    $commissionByDate[$date][] = $row;
}

function ps_partner_rows_text(array $rows): string {
    if (!$rows) return '-';
    return implode(' / ', array_map(function($row) {
        return ($row['symbol'] ?? '') . ' ' . number_format((float)($row['amount'] ?? 0), 2) . ' ' . ($row['currency'] ?? '');
    }, array_values($rows)));
}

function ps_partner_user_display(array $user): string {
    $name = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
    if ($name !== '') return $name;
    $username = trim((string)($user['username'] ?? ''));
    if ($username !== '' && !preg_match('/^\+?\d{7,}$/', $username)) return $username;
    $email = trim((string)($user['email'] ?? ''));
    return $email !== '' ? strtok($email, '@') : 'User #' . (int)($user['id'] ?? 0);
}

// ── Fetch Admin Matches ──
$adminMatches = [];
try {
    $amStmt = $pdo->prepare("
        SELECT * FROM admin_matches 
        WHERE is_active=1 AND DATE(created_at) = CURDATE()
        ORDER BY pin_order ASC, created_at DESC 
        LIMIT 20
    ");
    $amStmt->execute();
    $adminMatches = $amStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Today's automatically generated AI booking codes ──
$aiBookingCodes = ['draw' => [], 'fixed' => []];
try {
    $pdo->exec("ALTER TABLE admin_codes ADD COLUMN ai_generation_run_id BIGINT UNSIGNED NULL");
} catch (Throwable $e) {}
try {
    $aiCodeStmt = $pdo->prepare("
        SELECT ac.id AS code_id, ac.code, ac.total_odds, ac.source_type, ac.ai_generation_run_id,
               ac.created_at AS code_created_at,
               acs.id AS selection_id, acs.home_team, acs.away_team, acs.league,
               acs.market, acs.pick, acs.odds, acs.match_time
        FROM admin_codes ac
        JOIN ai_match_generation_runs air ON air.id=ac.ai_generation_run_id
        LEFT JOIN admin_code_selections acs ON acs.code_id=ac.id
        WHERE air.generation_date=? AND air.status='success'
          AND ac.source_type IN ('ai_draw_morning','ai_draw_evening','ai_score_morning','ai_score_evening')
        ORDER BY air.id DESC,
                 FIELD(ac.source_type,'ai_draw_morning','ai_draw_evening','ai_score_morning','ai_score_evening'),
                 acs.id ASC
    ");
    $aiCodeStmt->execute([date('Y-m-d')]);
    $aiCodeIndexes = ['draw' => [], 'fixed' => []];
    foreach ($aiCodeStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sourceType = (string)($row['source_type'] ?? '');
        $tab = str_starts_with($sourceType, 'ai_score_') ? 'fixed' : 'draw';
        $session = str_ends_with($sourceType, '_evening') ? 'Evening Matches' : 'Morning Matches';
        $codeId = (int)$row['code_id'];
        if (!isset($aiCodeIndexes[$tab][$codeId])) {
            $aiBookingCodes[$tab][] = [
                'id' => $codeId,
                'code' => (string)$row['code'],
                'total_odds' => (float)$row['total_odds'],
                'source_type' => $sourceType,
                'session' => $session,
                'run_id' => (int)$row['ai_generation_run_id'],
                'created_at' => (string)$row['code_created_at'],
                'selections' => [],
            ];
            $aiCodeIndexes[$tab][$codeId] = count($aiBookingCodes[$tab]) - 1;
        }
        if (!empty($row['selection_id'])) {
            $index = $aiCodeIndexes[$tab][$codeId];
            $aiBookingCodes[$tab][$index]['selections'][] = [
                'home_team' => (string)$row['home_team'],
                'away_team' => (string)$row['away_team'],
                'league' => (string)$row['league'],
                'market' => (string)$row['market'],
                'pick' => (string)$row['pick'],
                'odds' => (float)$row['odds'],
                'match_time' => (string)$row['match_time'],
            ];
        }
    }
} catch (Throwable $e) {}

$displayName = htmlspecialchars($sa['username'] ?: 'Partner');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<title>Partner Dashboard | Alpha Sports</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
* { box-sizing: border-box; }
body { margin: 0; min-height: 100vh; background: #080808; color: #f8f7ff; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; -webkit-font-smoothing: antialiased; }
.top-bar { height: 70px; display: flex; align-items: center; justify-content: space-between; padding: 0 20px; background: #121212; border-bottom: 1px solid #1c1c1c; position: sticky; top: 0; z-index: 10; }
.brand { font-size: 22px; font-weight: 900; font-style: italic; letter-spacing: -1px; }
.brand span { color: #ef4444; }
.logout { width: 36px; height: 36px; border-radius: 10px; background: #1c1c1c; color: #888; display: flex; align-items: center; justify-content: center; text-decoration: none; font-size: 14px; transition: color 0.2s; }
.logout:hover { color: #fff; }
.container { max-width: 680px; margin: 0 auto; padding: 20px 16px; }

.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 16px; }
.card { background: #121212; border: 1px solid #1c1c1c; border-radius: 16px; padding: 20px; box-shadow: 0 8px 24px rgba(0,0,0,0.2); }
.card-title { color: #888; font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 14px; }

/* Referral / Share */
.ref-display { font-size: 32px; font-weight: 900; color: #ef4444; letter-spacing: 2px; }
.copy-btn { border: none; background: #ef4444; color: #000; padding: 8px 16px; border-radius: 8px; font-size: 13px; font-weight: 800; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
.copy-btn:active { transform: scale(0.96); }
.link-box { border: 1px solid #2a2a2a; background: #000; padding: 12px 14px; border-radius: 8px; color: #ccc; font-size: 13px; font-family: monospace; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; flex: 1; }
.link-row { display: flex; gap: 8px; margin-top: 12px; }

/* Stats */
.stat-val { font-size: 28px; font-weight: 900; margin-top: 8px; }
.stat-sub { font-size: 13px; color: #666; margin-top: 4px; }
.text-green { color: #10b981; }

/* Today's Booking Codes */
.code-card { background: #0a0a0a; border: 1px solid #1c1c1c; border-radius: 12px; padding: 14px; margin-bottom: 12px; display: flex; flex-direction: column; gap: 10px; }
.code-header { display: flex; justify-content: space-between; align-items: center; }
.the-code { font-size: 18px; font-weight: 900; color: #fff; background: #1c1c1c; padding: 4px 12px; border-radius: 6px; letter-spacing: 1px; }
.code-odds { font-size: 14px; font-weight: 800; color: #ef4444; }
.match-row { display: flex; justify-content: space-between; font-size: 12px; color: #aaa; padding: 4px 0; border-top: 1px solid #1c1c1c; }
.match-row:first-child { border-top: none; }
.match-teams { font-weight: 600; color: #ccc; }
.match-pick { color: #ef4444; font-weight: 700; }

.code-matches-details { margin-top: 8px; }
.code-matches-details summary { cursor: pointer; color: #888; font-size: 12px; font-weight: 700; user-select: none; outline: none; list-style: none; }
.code-matches-details summary::-webkit-details-marker { display: none; }
.code-matches-details summary::before { content: '\25B6'; font-size: 10px; margin-right: 6px; display: inline-block; transition: transform 0.2s; }
.code-matches-details[open] summary::before { transform: rotate(90deg); }
.code-matches-details summary:hover { color: #fff; }
.code-matches-content { margin-top: 10px; border-top: 1px solid #1c1c1c; padding-top: 10px; display: flex; flex-direction: column; gap: 12px; }
.ai-code-tabs { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; padding: 4px; margin-bottom: 14px; background: #080808; border: 1px solid #1c1c1c; border-radius: 12px; }
.ai-code-tab { border: 0; border-radius: 9px; padding: 10px 8px; background: transparent; color: #777; font-size: 12px; font-weight: 900; cursor: pointer; }
.ai-code-tab.active { background: #ef4444; color: #080808; box-shadow: 0 4px 14px rgba(239,68,68,.2); }
.ai-code-panel { display: none; }
.ai-code-panel.active { display: block; }
.ai-code-session { display: inline-flex; align-items: center; gap: 6px; color: #fca5a5; font-size: 11px; font-weight: 900; text-transform: uppercase; letter-spacing: .5px; }
.ai-code-copy { border: 0; border-radius: 8px; background: #ef4444; color: #080808; padding: 8px 10px; font-size: 11px; font-weight: 900; cursor: pointer; }
.ai-code-empty { padding: 22px 10px; color: #666; font-size: 13px; text-align: center; }

/* Collapsible Payouts */
.drawer-toggle { width: 100%; border: 1px solid #1c1c1c; background: #121212; color: #fff; padding: 14px; border-radius: 12px; font-size: 14px; font-weight: 700; display: flex; justify-content: space-between; align-items: center; cursor: pointer; margin-bottom: 16px; }
.drawer-toggle:hover { background: #181818; }
.drawer-content { display: none; margin-bottom: 16px; }
.drawer-content.open { display: block; }
.form-group { margin-bottom: 12px; }
.form-group label { display: block; font-size: 12px; color: #888; margin-bottom: 6px; font-weight: 600; }
.input-field { width: 100%; background: #000; border: 1px solid #2a2a2a; color: #fff; padding: 12px 14px; border-radius: 8px; font-size: 14px; outline: none; }
.input-field:focus { border-color: #ef4444; }
.btn-save { width: 100%; background: #ef4444; color: #000; border: none; padding: 14px; border-radius: 8px; font-size: 14px; font-weight: 800; cursor: pointer; margin-top: 10px; }

/* User List */
.user-list { border-top: 1px solid #1c1c1c; margin: 0 -20px -20px; }
.user-item { display: flex; justify-content: space-between; align-items: center; padding: 14px 20px; border-bottom: 1px solid #1c1c1c; }
.user-item:last-child { border-bottom: none; }
.user-info { display: flex; flex-direction: column; gap: 4px; }
.user-info strong { font-size: 14px; color: #fff; }
.user-info span { font-size: 12px; color: #888; }
.user-meta { text-align: right; }
.user-meta .date { font-size: 11px; color: #666; }
.user-meta .comm { font-size: 12px; color: #10b981; font-weight: 700; margin-top: 4px; }
.users-loading { padding: 30px 20px; text-align: center; color: #666; font-size: 13px; }

.toast { position: fixed; left: 50%; bottom: 20px; transform: translateX(-50%) translateY(20px); opacity: 0; pointer-events: none; background: #ef4444; color: #000; border-radius: 20px; padding: 10px 20px; font-size: 13px; font-weight: 800; transition: 0.3s; z-index: 100; box-shadow: 0 4px 12px rgba(239,68,68,0.3); }
.toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }
</style>
</head>
<body>

<header class="top-bar">
    <div class="brand">Alpha <span>Sports</span></div>
    <a class="logout" href="sub_admin_logout.php" aria-label="Logout"><i class="fa-solid fa-arrow-right-from-bracket"></i></a>
</header>

<div class="container">
    
    <div class="card" style="margin-bottom: 16px;">
        <div class="card-title">Share Your Code</div>
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <div class="ref-display"><?php echo htmlspecialchars($referralCode); ?></div>
            <button class="copy-btn" onclick="copyText('<?php echo htmlspecialchars($referralCode, ENT_QUOTES); ?>')"><i class="fa-solid fa-copy"></i> Copy</button>
        </div>
        <div style="font-size: 13px; color: #888; margin-top: 8px;">Earn <strong><?php echo rtrim(rtrim(number_format($commissionPct, 2), '0'), '.'); ?>%</strong> on every deposit from referred users.</div>
        <div class="link-row">
            <div class="link-box"><?php echo htmlspecialchars($shareLink); ?></div>
            <button class="copy-btn" style="padding: 12px;" onclick="copyText('<?php echo htmlspecialchars($shareLink, ENT_QUOTES); ?>')"><i class="fa-solid fa-share-nodes"></i></button>
        </div>
    </div>

    <div class="grid-2">
        <div class="card" style="padding: 16px;">
            <div class="card-title" style="margin-bottom: 0;">Referrals</div>
            <div class="stat-val"><?php echo number_format($referralCount); ?></div>
            <div class="stat-sub">Active network</div>
        </div>
        <div class="card" style="padding: 16px;">
            <div class="card-title" style="margin-bottom: 0;">Today's Comm</div>
            <div class="stat-val text-green"><?php echo htmlspecialchars(ps_partner_money_text($todayRows)); ?></div>
            <div class="stat-sub"><?php echo number_format($todayCommissions); ?> entries today</div>
        </div>
    </div>

    <button class="drawer-toggle" onclick="toggleDrawer('payoutDrawer')">
        <span><i class="fa-solid fa-building-columns"></i> Payout Details</span>
        <i class="fa-solid fa-chevron-down"></i>
    </button>

    <div class="drawer-content" id="payoutDrawer">
        <div class="card" style="margin-bottom: 16px;">
            <div class="card-title">Payout Details</div>
            <p style="font-size: 12px; color: #888; margin: 0 0 14px 0;">Set where admin should send your commissions.</p>
            <form id="payoutForm">
                <div class="form-group">
                    <label>Account Name</label>
                    <input class="input-field" name="name" value="<?php echo htmlspecialchars($payoutName); ?>" placeholder="John Doe" required maxlength="120" autocomplete="name">
                </div>
                <div class="form-group">
                    <label>Network / Bank</label>
                    <input class="input-field" name="network" value="<?php echo htmlspecialchars($payoutNetwork); ?>" placeholder="MTN" required maxlength="80">
                </div>
                <div class="form-group">
                    <label>Account Number</label>
                    <input class="input-field" name="number" value="<?php echo htmlspecialchars($payoutNumber); ?>" placeholder="054..." required maxlength="80" inputmode="numeric" autocomplete="tel">
                </div>
                <button class="btn-save" type="submit">Save Payout Info</button>
            </form>
        </div>
    </div>

    <button class="drawer-toggle" onclick="toggleDrawer('historyDrawer')">
        <span><i class="fa-solid fa-chart-line"></i> Earning History</span>
        <i class="fa-solid fa-chevron-down"></i>
    </button>

    <div class="drawer-content" id="historyDrawer">
        <div class="card" style="margin-bottom: 16px;">
            <div class="card-title">Total Earnings</div>
            <div style="font-size:24px; font-weight:900; color:#10b981; margin-bottom:16px;">
                <?php echo htmlspecialchars(ps_partner_rows_text($totalRows)); ?>
            </div>
            
            <div class="card-title" style="border-top:1px solid #1c1c1c; padding-top:16px;">History (Last 180 Days)</div>
            <?php if (!$commissionHistory): ?>
                <div style="font-size: 13px; color: #666; text-align: center; padding: 20px 0;">No earnings yet.</div>
            <?php else: ?>
                <div style="display:flex; flex-direction:column; gap:8px;">
                <?php foreach ($commissionHistory as $row): ?>
                    <?php 
                        $isPaid = (int)$row['tx_count'] > 0 && (int)$row['tx_count'] === (int)$row['paid_count']; 
                        $statusColor = $isPaid ? '#10b981' : '#f59e0b';
                        $statusText = $isPaid ? 'Paid' : 'Unpaid';
                    ?>
                    <div style="display:flex; justify-content:space-between; align-items:center; background:#000; padding:12px; border-radius:8px; border:1px solid #1c1c1c;">
                        <div style="display:flex; flex-direction:column; gap:4px;">
                            <span style="font-size:13px; font-weight:700; color:#eee;"><?php echo date('M d, Y', strtotime($row['commission_date'])); ?></span>
                            <span style="font-size:11px; font-weight:800; color:<?php echo $statusColor; ?>; text-transform:uppercase;"><?php echo $statusText; ?></span>
                        </div>
                        <div style="text-align:right;">
                            <div style="font-size:14px; font-weight:900; color:#10b981;">Commission: <?php echo htmlspecialchars($row['currency_code'] . ' ' . number_format((float)$row['day_total'], 2)); ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <button class="drawer-toggle" onclick="toggleDrawer('fundsDrawer')">
        <span><i class="fa-solid fa-wallet"></i> Manage Betting Funds</span>
        <i class="fa-solid fa-chevron-down"></i>
    </button>

    <div class="drawer-content" id="fundsDrawer">
        <div class="card" style="margin-bottom: 16px;">
            <div class="card-title">Manage Your Betting Account Balance</div>
            <p style="font-size: 12px; color: #888; margin: 0 0 14px 0;">Directly add or remove funds to your personal betting account.</p>
            <form id="fundsForm" onsubmit="handleFundsSubmit(event)">
                <div class="form-group">
                    <label>Amount</label>
                    <input type="number" step="0.01" min="1" class="input-field" id="fundAmount" required placeholder="0.00">
                </div>
                <div style="display:flex; gap:10px; margin-top:16px;">
                    <button type="submit" onclick="document.getElementById('fundAction').value='add'" style="flex:1; background:#10b981; color:#000; border:none; padding:14px; border-radius:8px; font-size:14px; font-weight:800; cursor:pointer;">Add Funds</button>
                    <button type="submit" onclick="document.getElementById('fundAction').value='remove'" style="flex:1; background:#ef4444; color:#000; border:none; padding:14px; border-radius:8px; font-size:14px; font-weight:800; cursor:pointer;">Remove Funds</button>
                </div>
                <input type="hidden" id="fundAction" value="">
            </form>
        </div>
    </div>

    <div class="card" style="margin-bottom: 16px;">
        <div class="card-title" style="margin-bottom:6px;">Today's AI Booking Codes</div>
        <p style="font-size:12px;color:#888;margin:0 0 14px;">Copy and share the two-match morning or evening code.</p>
        <div class="ai-code-tabs" role="tablist" aria-label="AI booking code types">
            <button class="ai-code-tab active" type="button" role="tab" aria-selected="true" onclick="switchAiCodeTab('draw',this)">
                <i class="fa-solid fa-handshake"></i> Draws
            </button>
            <button class="ai-code-tab" type="button" role="tab" aria-selected="false" onclick="switchAiCodeTab('fixed',this)">
                <i class="fa-solid fa-bullseye"></i> Fixed Matches
            </button>
        </div>

        <?php foreach (['draw' => 'Draws', 'fixed' => 'Fixed Matches'] as $tabKey => $tabLabel): ?>
            <div class="ai-code-panel <?php echo $tabKey === 'draw' ? 'active' : ''; ?>" id="aiCodePanel-<?php echo $tabKey; ?>" role="tabpanel">
                <?php if (empty($aiBookingCodes[$tabKey])): ?>
                    <div class="ai-code-empty">No <?php echo htmlspecialchars(strtolower($tabLabel)); ?> booking codes have been generated today.</div>
                <?php else: ?>
                    <?php foreach ($aiBookingCodes[$tabKey] as $code): ?>
                        <div class="code-card">
                            <div class="code-header">
                                <div>
                                    <div class="ai-code-session"><i class="fa-regular fa-clock"></i> <?php echo htmlspecialchars($code['session']); ?></div>
                                    <div style="font-size:10px;color:#555;margin-top:4px;">Batch #<?php echo (int)$code['run_id']; ?> · <?php echo htmlspecialchars(date('g:i A', strtotime($code['created_at']))); ?></div>
                                </div>
                                <div class="code-odds">Total <?php echo number_format((float)$code['total_odds'], 2); ?></div>
                            </div>
                            <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;">
                                <span class="the-code"><?php echo htmlspecialchars($code['code']); ?></span>
                                <button class="ai-code-copy" type="button" onclick="copyText('<?php echo htmlspecialchars($code['code'], ENT_QUOTES); ?>')"><i class="fa-solid fa-copy"></i> Copy Code</button>
                            </div>
                            <details class="code-matches-details">
                                <summary>View <?php echo count($code['selections']); ?> selections</summary>
                                <div class="code-matches-content">
                                    <?php foreach ($code['selections'] as $selection): ?>
                                        <div class="match-row">
                                            <div>
                                                <div class="match-teams"><?php echo htmlspecialchars($selection['home_team'] . ' vs ' . $selection['away_team']); ?></div>
                                                <div style="font-size:10px;color:#666;margin-top:3px;"><?php echo htmlspecialchars($selection['match_time']); ?> · <?php echo htmlspecialchars($selection['market']); ?></div>
                                            </div>
                                            <div style="text-align:right;">
                                                <div class="match-pick"><?php echo htmlspecialchars($selection['pick']); ?></div>
                                                <div style="font-size:10px;color:#777;margin-top:3px;">@<?php echo number_format((float)$selection['odds'], 2); ?></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card" style="margin-bottom: 16px;">
        <div class="card-title">Custom Matches</div>
        <p style="font-size: 12px; color: #888; margin: 0 0 14px 0;">Matches featured by the admin.</p>
        
        <?php if (!$adminMatches): ?>
            <div style="font-size: 13px; color: #666; text-align: center; padding: 20px 0;">No custom matches available.</div>
        <?php else: ?>
            <div style="display:flex; flex-direction:column; gap:8px;">
            <?php foreach ($adminMatches as $match): ?>
                <?php
                    $homeBadge = !empty($match['home_logo']) ? $match['home_logo'] : 'team_badge.php?n=' . rawurlencode($match['home_team']);
                    $awayBadge = !empty($match['away_logo']) ? $match['away_logo'] : 'team_badge.php?n=' . rawurlencode($match['away_team']);
                ?>
                <div style="background:#000; padding:10px; border-radius:8px; border:1px solid #1c1c1c; font-size:12px; color:#ccc;">
                    <div style="font-weight:700; color:#fff; margin-bottom:6px; display:flex; justify-content:space-between; align-items:center;">
                        <div style="display:flex; align-items:center; gap:6px;">
                            <img src="<?php echo htmlspecialchars($homeBadge); ?>" style="width:20px;height:20px;border-radius:50%;object-fit:cover;background:#fff;">
                            <span><?php echo htmlspecialchars($match['home_team']); ?></span>
                            <span style="color:#555;font-size:10px;">VS</span>
                            <span><?php echo htmlspecialchars($match['away_team']); ?></span>
                            <img src="<?php echo htmlspecialchars($awayBadge); ?>" style="width:20px;height:20px;border-radius:50%;object-fit:cover;background:#fff;">
                        </div>
                        <?php if ($match['final_score_home'] !== null && $match['final_score_away'] !== null): ?>
                            <span style="color:#ef4444; font-size:14px;"><?php echo (int)$match['final_score_home'] . ' - ' . (int)$match['final_score_away']; ?></span>
                        <?php endif; ?>
                    </div>
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <div style="display:flex; gap:10px; color:#aaa; font-size:11px;">
                            <span>1: <strong style="color:#10b981;"><?php echo number_format((float)$match['odds_home'], 2); ?></strong></span>
                            <span>X: <strong style="color:#10b981;"><?php echo number_format((float)$match['odds_draw'], 2); ?></strong></span>
                            <span>2: <strong style="color:#10b981;"><?php echo number_format((float)$match['odds_away'], 2); ?></strong></span>
                        </div>
                        <?php if (!empty($match['match_time'])): ?>
                            <span style="color:#888; font-size:10px;"><i class="fa-regular fa-clock"></i> <?php echo htmlspecialchars($match['match_time']); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <button class="drawer-toggle" type="button" onclick="toggleUsersDrawer()" aria-controls="usersDrawer" aria-expanded="false" id="usersToggle">
        <span><i class="fa-solid fa-users"></i> Referred Users <small style="color:#777;">(<?php echo number_format($referralCount); ?>)</small></span>
        <i class="fa-solid fa-chevron-down"></i>
    </button>

    <div class="drawer-content" id="usersDrawer">
        <div class="card">
            <div class="card-title" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                <span>Referred Users</span>
                <input type="text" id="refSearch" onkeyup="filterReferrals()" placeholder="Search user..." style="background:#000; border:1px solid #1c1c1c; color:#fff; padding:6px 10px; border-radius:6px; font-size:12px; outline:none; width:140px;">
            </div>
            <div class="user-list" id="refList">
                <div class="users-loading">Tap Referred Users to load the list.</div>
            </div>
        </div>
    </div>
</div>

<div class="toast" id="toast">Copied to clipboard!</div>

<script>
function copyText(text){
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(showToast);
    } else {
        var area=document.createElement('textarea');
        area.value=text; document.body.appendChild(area); area.select();
        try{document.execCommand('copy');}catch(e){}
        area.remove(); showToast();
    }
}
function showToast(msg){
    var t=document.getElementById('toast');
    if(msg) t.innerText = msg;
    t.classList.add('show');
    setTimeout(function(){t.classList.remove('show');}, 2000);
}

function switchAiCodeTab(tab, button) {
    document.querySelectorAll('.ai-code-tab').forEach(function(item) {
        var active = item === button;
        item.classList.toggle('active', active);
        item.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    document.querySelectorAll('.ai-code-panel').forEach(function(panel) {
        panel.classList.toggle('active', panel.id === 'aiCodePanel-' + tab);
    });
}

function toggleDrawer(id) {
    var el = document.getElementById(id);
    if(el.classList.contains('open')){
        el.classList.remove('open');
    } else {
        el.classList.add('open');
    }
}

var usersLoaded = false;
var usersLoading = false;
function escapeUserText(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function(ch) {
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch];
    });
}
function userDisplayName(user) {
    var full = ((user.first_name || '') + ' ' + (user.last_name || '')).trim();
    if (full) return full;
    var username = String(user.username || '').trim();
    if (username && !/^\+?\d{7,}$/.test(username)) return username;
    var email = String(user.email || '').trim();
    return email ? email.split('@')[0] : 'User #' + Number(user.id || 0);
}
function commissionText(rows) {
    if (!Array.isArray(rows) || !rows.length) return 'No commission';
    return rows.map(function(row) {
        var label = row.symbol || row.currency || '';
        var amount = Number(row.amount || 0).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});
        return label + ' ' + amount;
    }).join(' / ');
}
function renderReferralUsers(users) {
    var list = document.getElementById('refList');
    if (!Array.isArray(users) || !users.length) {
        list.innerHTML = '<div class="users-loading">No referred users yet.</div>';
        return;
    }
    list.innerHTML = users.map(function(user) {
        var joined = user.created_at ? new Date(String(user.created_at).replace(' ', 'T')).toLocaleDateString(undefined,{month:'short',day:'2-digit',year:'numeric'}) : '';
        var contact = user.email || user.phone || '';
        return '<div class="user-item">' +
            '<div class="user-info"><strong>' + escapeUserText(userDisplayName(user)) + '</strong><span>' + escapeUserText(contact) + '</span></div>' +
            '<div class="user-meta"><div class="date">' + escapeUserText(joined) + '</div><div class="comm">' + escapeUserText(commissionText(user.commissions)) + '</div></div>' +
        '</div>';
    }).join('');
}
async function loadReferralUsers() {
    if (usersLoaded || usersLoading) return;
    usersLoading = true;
    document.getElementById('refList').innerHTML = '<div class="users-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading users…</div>';
    try {
        var response = await fetch('api_sub_admin.php?action=get_users', {credentials:'same-origin',cache:'no-store'});
        var data = await response.json();
        if (!response.ok || !data.success) throw new Error(data.message || 'Could not load users.');
        renderReferralUsers(data.users || []);
        usersLoaded = true;
    } catch (error) {
        document.getElementById('refList').innerHTML = '<div class="users-loading">' + escapeUserText(error.message || 'Could not load users.') + '</div>';
    } finally {
        usersLoading = false;
    }
}
function toggleUsersDrawer() {
    var drawer = document.getElementById('usersDrawer');
    var button = document.getElementById('usersToggle');
    var opening = !drawer.classList.contains('open');
    drawer.classList.toggle('open', opening);
    button.setAttribute('aria-expanded', opening ? 'true' : 'false');
    if (opening) loadReferralUsers();
}

async function handleFundsSubmit(e) {
    e.preventDefault();
    const amount = document.getElementById('fundAmount').value;
    const action = document.getElementById('fundAction').value;
    
    if(!amount || !action) return;

    try {
        const res = await fetch('api_subadmin_funds.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({ action: action, amount: amount })
        });
        
        const data = await res.json();
        if (data.success) {
            showToast('Success: ' + data.message);
            document.getElementById('fundAmount').value = '';
        } else {
            showToast('Error: ' + data.error);
        }
    } catch(err) {
        showToast('An error occurred.');
    }
}

var payoutForm = document.getElementById('payoutForm');
if (payoutForm) {
    payoutForm.addEventListener('submit', function(e) {
        e.preventDefault();
        var btn = payoutForm.querySelector('button[type="submit"]');
        var oldText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = 'Saving...';
        var data = new FormData(payoutForm);
        data.append('action', 'save_payout_details');
        fetch('api_sub_admin.php', { method:'POST', body:data, credentials:'same-origin' })
            .then(function(r){
                if (!r.ok) throw new Error('Server returned ' + r.status);
                return r.json();
            })
            .then(function(res){
                if (!res.success) throw new Error(res.message || 'Payout details could not be saved.');
                showToast(res.message || 'Payout details saved');
            })
            .catch(function(err){ showToast(err.message || 'Network error. Try again.'); })
            .finally(function(){ btn.disabled = false; btn.innerHTML = oldText; });
    });
}

function filterReferrals() {
    var filter = document.getElementById("refSearch").value.toLowerCase();
    var list = document.getElementById("refList");
    var items = list.getElementsByClassName("user-item");
    
    for (var i = 0; i < items.length; i++) {
        var emailSpan = items[i].querySelector(".user-info span");
        if (emailSpan) {
            var txtValue = emailSpan.textContent || emailSpan.innerText;
            if (txtValue.toLowerCase().indexOf(filter) > -1) {
                items[i].style.display = "";
            } else {
                items[i].style.display = "none";
            }
        }
    }
}
</script>
</body>
</html>
