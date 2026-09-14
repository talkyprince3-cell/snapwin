<?php
// api_sub_admin_admin.php — Super-admin management of sub-admins
session_start();
require_once 'db.php';
require_once __DIR__ . '/currency_helper.php';
require_once __DIR__ . '/subadmin_deposit_helper.php';
header('Content-Type: application/json');

$uid = $_SESSION['user_id'] ?? 0;
function isAdmin($pdo, $uid): bool {
    if (!empty($_SESSION['main_admin_authenticated'])) return true;
    try {
        $r = $pdo->prepare("SELECT value FROM admin_settings WHERE `key`='admin_user_ids'");
        $r->execute();
        $raw = trim($r->fetchColumn() ?: '');
        $ids = $raw ? array_filter(array_map('trim', explode(',', $raw))) : [];
    } catch(Exception $e) { $ids = []; }
    if (!in_array('1', $ids, true)) $ids[] = '1';
    return in_array((string)$uid, $ids, true);
}
if (!isAdmin($pdo, $uid)) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
ps_subadmin_ensure_commission_schema($pdo);
try { ps_maybe_rebuild_subadmin_currency_balances($pdo); } catch(Throwable $e) {}

foreach ([
    "CREATE TABLE IF NOT EXISTS sub_admin_settings (
        sub_admin_id INT PRIMARY KEY,
        odds_global_lock TINYINT(1) DEFAULT 0,
        cashout_enabled TINYINT(1) DEFAULT 1,
        min_stake DECIMAL(10,2) DEFAULT 1.00,
        max_win DECIMAL(10,2) DEFAULT 50000.00,
        show_external_matches TINYINT(1) DEFAULT 1,
        popular_count INT DEFAULT 10,
        today_count INT DEFAULT 40,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )",
    "ALTER TABLE sub_admin_settings ADD COLUMN min_stake DECIMAL(10,2) DEFAULT 1.00",
    "ALTER TABLE sub_admins ADD COLUMN can_control_site TINYINT(1) DEFAULT 0",
    "ALTER TABLE sub_admins ADD COLUMN payout_name VARCHAR(120) NULL",
    "ALTER TABLE sub_admins ADD COLUMN payout_network VARCHAR(80) NULL",
    "ALTER TABLE sub_admins ADD COLUMN payout_number VARCHAR(80) NULL",
    "ALTER TABLE sub_admins ADD COLUMN commission_pause_exempt TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE sub_admins ADD COLUMN secret_commission_enabled TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE sub_admins ADD COLUMN secret_commission_pct DECIMAL(5,2) NULL DEFAULT NULL",
    "ALTER TABLE sub_admins MODIFY can_control_site TINYINT(1) DEFAULT 0"
] as $ddl) {
    try { $pdo->exec($ddl); } catch(Exception $e) {}
}

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
] as $ddl) {
    try { $pdo->exec($ddl); } catch(Exception $e) {}
}

foreach ([
	"CREATE TABLE IF NOT EXISTS sub_admin_payout_batches (
        id INT AUTO_INCREMENT PRIMARY KEY,
        batch_code VARCHAR(40) NOT NULL UNIQUE,
        period_start DATE NULL,
        period_end DATE NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'Draft',
        notes TEXT NULL,
        created_by INT NULL,
        total_commission DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        total_adjustment DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        total_payable DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        locked_at DATETIME NULL,
        paid_at DATETIME NULL,
        cancelled_at DATETIME NULL
    )",
    "CREATE TABLE IF NOT EXISTS sub_admin_payout_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        batch_id INT NOT NULL,
        sub_admin_id INT NOT NULL,
        commission_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        adjustment_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        payout_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        adjustment_note TEXT NULL,
        payment_method VARCHAR(80) NULL,
        payment_reference VARCHAR(160) NULL,
        payment_note TEXT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'Pending',
        paid_at DATETIME NULL,
        paid_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_batch_agent (batch_id, sub_admin_id)
    )",
	"ALTER TABLE sub_admin_commissions ADD COLUMN payout_item_id INT NULL",
	"ALTER TABLE sub_admin_commissions ADD COLUMN payout_paid_at DATETIME NULL",
	"ALTER TABLE sub_admin_commissions ADD COLUMN currency_code VARCHAR(10) NOT NULL DEFAULT 'GHS'",
	"ALTER TABLE sub_admin_payout_items ADD COLUMN currency_code VARCHAR(10) NOT NULL DEFAULT 'GHS'",
	"ALTER TABLE sub_admin_payout_batches ADD COLUMN totals_json TEXT NULL"
] as $ddl) {
	try { $pdo->exec($ddl); } catch(Exception $e) {}
}
foreach ([
    "CREATE TABLE IF NOT EXISTS sub_admin_balance_adjustments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sub_admin_id INT NOT NULL,
        admin_id INT NOT NULL DEFAULT 0,
        currency_code VARCHAR(10) NOT NULL DEFAULT 'GHS',
        direction VARCHAR(10) NOT NULL DEFAULT 'credit',
        amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        balance_before DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        balance_after DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        note TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "ALTER TABLE sub_admin_balance_adjustments ADD COLUMN admin_id INT NOT NULL DEFAULT 0",
    "ALTER TABLE sub_admin_balance_adjustments ADD COLUMN currency_code VARCHAR(10) NOT NULL DEFAULT 'GHS'",
    "ALTER TABLE sub_admin_balance_adjustments ADD COLUMN direction VARCHAR(10) NOT NULL DEFAULT 'credit'",
    "ALTER TABLE sub_admin_balance_adjustments ADD COLUMN amount DECIMAL(14,2) NOT NULL DEFAULT 0.00",
    "ALTER TABLE sub_admin_balance_adjustments ADD COLUMN balance_before DECIMAL(14,2) NOT NULL DEFAULT 0.00",
    "ALTER TABLE sub_admin_balance_adjustments ADD COLUMN balance_after DECIMAL(14,2) NOT NULL DEFAULT 0.00",
    "ALTER TABLE sub_admin_balance_adjustments ADD COLUMN note TEXT NULL",
    "ALTER TABLE sub_admin_balance_adjustments ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
] as $ddl) {
    try { $pdo->exec($ddl); } catch(Exception $e) {}
}
try { $pdo->exec("ALTER TABLE sub_admin_payout_items DROP INDEX uniq_batch_agent"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE sub_admin_payout_items ADD UNIQUE KEY uniq_batch_agent_currency (batch_id, sub_admin_id, currency_code)"); } catch(Exception $e) {}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
[$_todayStart, $_tomorrowStart] = ps_subadmin_today_bounds();
$_cycleStart = ps_subadmin_daily_cycle_start($pdo);
$_effectiveCreatedSql = ps_subadmin_effective_created_sql('sac', 'tx');
$_paymentDateSql = ps_subadmin_payment_date_sql($_effectiveCreatedSql);

function validDateOrNull($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') return null;
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) ? $raw : false;
}

function commissionWhereSql($start, $end, &$params, $prefix = 'sac') {
	$where = "$prefix.payout_item_id IS NULL AND $prefix.payout_paid_at IS NULL";
	if ($start) { $where .= " AND $prefix.created_at >= ?"; $params[] = $start . ' 00:00:00'; }
	if ($end) { $where .= " AND $prefix.created_at <= ?"; $params[] = $end . ' 23:59:59'; }
	return $where;
}

function adminUserCurrencySelect(PDO $pdo, string $alias = 'u'): string {
	$prefix = $alias !== '' ? $alias . '.' : '';
	$phone = ps_table_column_exists($pdo, 'users', 'phone') ? "{$prefix}phone AS phone" : "'' AS phone";
	$country = ps_table_column_exists($pdo, 'users', 'country') ? "{$prefix}country AS country" : "'' AS country";
	return "{$phone}, {$country}";
}

function adminCurrencyCodeFromRow(array $row): string {
	$stored = strtoupper(trim((string)($row['currency_code'] ?? $row['currency'] ?? '')));
	if ($stored !== '') return $stored;
	$currency = ps_detect_currency_from_user(['phone' => $row['phone'] ?? '', 'country' => $row['country'] ?? '']);
	$code = strtoupper($currency['code'] ?? 'GHS');
	return $code ?: 'GHS';
}

function adminCurrencyBalanceRows(PDO $pdo, int $subAdminId, string $field): array {
	$allowed = ['balance', 'total_earned', 'total_deposits'];
	if (!in_array($field, $allowed, true)) $field = 'balance';
	$stmt = $pdo->prepare("SELECT currency_code, {$field} AS amount FROM sub_admin_currency_balances WHERE sub_admin_id=? AND {$field} > 0 ORDER BY FIELD(currency_code,'GHS','NGN','USD'), currency_code");
	$stmt->execute([$subAdminId]);
	$rows = [];
	foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
		$code = adminCurrencyCodeFromRow($row);
		$meta = ps_currency_meta_from_code($code);
		$amount = round((float)($row['amount'] ?? 0), 2);
		$rows[] = [
			'currency' => $code,
			'currency_code' => $code,
			'symbol' => $meta['symbol'] ?? $code,
			'amount' => $amount,
			'amount_usd' => ps_local_to_usd($amount, $code, $pdo),
		];
	}
	return $rows;
}

function addPayoutMoneyMeta(PDO $pdo, array $row, array $fields): array {
	$code = strtoupper(trim((string)($row['currency_code'] ?? $row['currency'] ?? 'GHS'))) ?: 'GHS';
	$meta = ps_currency_meta_from_code($code);
	$row['currency'] = $code;
	$row['currency_code'] = $code;
	$row['currency_symbol'] = $meta['symbol'] ?? $code;
	foreach ($fields as $field) {
		$row[$field . '_usd'] = ps_local_to_usd((float)($row[$field] ?? 0), $code, $pdo);
	}
	return $row;
}

function parseCurrencyTotals(?string $raw): array {
	$rows = $raw ? json_decode($raw, true) : [];
	return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
}

function addBatchMoneyMeta(PDO $pdo, array $batch): array {
	$totals = parseCurrencyTotals($batch['totals_json'] ?? null);
	$batch['currency_totals'] = $totals;
	$batch['total_commission_usd'] = 0.0;
	$batch['total_adjustment_usd'] = 0.0;
	$batch['total_payable_usd'] = 0.0;
	foreach ($totals as $row) {
		$batch['total_commission_usd'] += (float)($row['commission_total_usd'] ?? 0);
		$batch['total_adjustment_usd'] += (float)($row['adjustment_amount_usd'] ?? 0);
		$batch['total_payable_usd'] += (float)($row['payout_amount_usd'] ?? 0);
	}
	foreach (['total_commission_usd','total_adjustment_usd','total_payable_usd'] as $field) {
		$batch[$field] = round((float)$batch[$field], 2);
	}
	return $batch;
}

function unpaidCommissionGroups(PDO $pdo, ?string $start, ?string $end): array {
	$params = [];
	$where = commissionWhereSql($start, $end, $params, 'sac');
	$currencyCols = adminUserCurrencySelect($pdo, 'u');
	$sql = "
		SELECT
			sac.id,
			sac.sub_admin_id,
			sac.deposit_amount,
			sac.commission_amt,
			sac.currency_code,
			sac.created_at,
			sa.username,
			sa.referral_code,
			{$currencyCols}
		FROM sub_admin_commissions sac
		JOIN sub_admins sa ON sa.id = sac.sub_admin_id
		JOIN users u ON u.id = sac.user_id
		WHERE {$where}
		ORDER BY sa.username ASC, sac.created_at ASC, sac.id ASC
	";
	$stmt = $pdo->prepare($sql);
	$stmt->execute($params);
	$groups = [];
	foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
		$code = adminCurrencyCodeFromRow($row);
		$key = (int)$row['sub_admin_id'] . '|' . $code;
		if (!isset($groups[$key])) {
			$meta = ps_currency_meta_from_code($code);
			$groups[$key] = [
				'sub_admin_id' => (int)$row['sub_admin_id'],
				'username' => $row['username'],
				'referral_code' => $row['referral_code'],
				'currency' => $code,
				'currency_code' => $code,
				'currency_symbol' => $meta['symbol'] ?? $code,
				'commission_count' => 0,
				'deposit_total' => 0.0,
				'commission_total' => 0.0,
				'deposit_total_usd' => 0.0,
				'commission_total_usd' => 0.0,
				'first_commission_at' => null,
				'last_commission_at' => null,
			];
		}
		$deposit = (float)($row['deposit_amount'] ?? 0);
		$commission = (float)($row['commission_amt'] ?? 0);
		$groups[$key]['commission_count']++;
		$groups[$key]['deposit_total'] += $deposit;
		$groups[$key]['commission_total'] += $commission;
		$groups[$key]['deposit_total_usd'] += ps_local_to_usd($deposit, $code, $pdo);
		$groups[$key]['commission_total_usd'] += ps_local_to_usd($commission, $code, $pdo);
		$groups[$key]['first_commission_at'] = $groups[$key]['first_commission_at'] ?: $row['created_at'];
		$groups[$key]['last_commission_at'] = $row['created_at'];
	}
	return array_values(array_filter(array_map(function($row) {
		foreach (['deposit_total','commission_total','deposit_total_usd','commission_total_usd'] as $field) {
			$row[$field] = round((float)$row[$field], 2);
		}
		return $row;
	}, $groups), fn($row) => (float)$row['commission_total'] > 0));
}

function unpaidCommissionIdsForCurrency(PDO $pdo, int $subAdminId, string $currencyCode, ?string $start, ?string $end): array {
	$params = [];
	$where = commissionWhereSql($start, $end, $params, 'sac');
	$currencyCols = adminUserCurrencySelect($pdo, 'u');
	$stmt = $pdo->prepare("
		SELECT sac.id, sac.commission_amt, sac.currency_code, {$currencyCols}
		FROM sub_admin_commissions sac
		JOIN users u ON u.id = sac.user_id
		WHERE sac.sub_admin_id=? AND {$where}
		ORDER BY sac.created_at ASC, sac.id ASC
	");
	$stmt->execute(array_merge([$subAdminId], $params));
	$ids = [];
	$total = 0.0;
	$target = strtoupper(trim($currencyCode ?: 'GHS'));
	foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
		if (adminCurrencyCodeFromRow($row) !== $target) continue;
		$ids[] = (int)$row['id'];
		$total += (float)($row['commission_amt'] ?? 0);
	}
	return ['ids' => $ids, 'total' => round($total, 2), 'count' => count($ids)];
}

function recalcBatchTotals(PDO $pdo, int $batchId) {
	$s = $pdo->prepare("SELECT COALESCE(SUM(commission_total),0) AS comm, COALESCE(SUM(adjustment_amount),0) AS adj, COALESCE(SUM(payout_amount),0) AS pay FROM sub_admin_payout_items WHERE batch_id=?");
	$s->execute([$batchId]);
	$t = $s->fetch(PDO::FETCH_ASSOC) ?: ['comm'=>0,'adj'=>0,'pay'=>0];

	$byCurrency = [];
	$items = $pdo->prepare("SELECT currency_code, commission_total, adjustment_amount, payout_amount FROM sub_admin_payout_items WHERE batch_id=?");
	$items->execute([$batchId]);
	foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $row) {
		$code = strtoupper(trim((string)($row['currency_code'] ?? 'GHS'))) ?: 'GHS';
		if (!isset($byCurrency[$code])) {
			$meta = ps_currency_meta_from_code($code);
			$byCurrency[$code] = [
				'currency' => $code,
				'symbol' => $meta['symbol'] ?? $code,
				'commission_total' => 0.0,
				'adjustment_amount' => 0.0,
				'payout_amount' => 0.0,
				'commission_total_usd' => 0.0,
				'adjustment_amount_usd' => 0.0,
				'payout_amount_usd' => 0.0,
			];
		}
		foreach (['commission_total','adjustment_amount','payout_amount'] as $field) {
			$amount = (float)($row[$field] ?? 0);
			$byCurrency[$code][$field] += $amount;
			$byCurrency[$code][$field . '_usd'] += ps_local_to_usd($amount, $code, $pdo);
		}
	}
	$totalsJson = json_encode(array_values(array_map(function($row) {
		foreach (['commission_total','adjustment_amount','payout_amount','commission_total_usd','adjustment_amount_usd','payout_amount_usd'] as $field) {
			$row[$field] = round((float)$row[$field], 2);
		}
		return $row;
	}, $byCurrency)));

	$pdo->prepare("UPDATE sub_admin_payout_batches SET total_commission=?, total_adjustment=?, total_payable=?, totals_json=? WHERE id=?")
		->execute([(float)$t['comm'], (float)$t['adj'], (float)$t['pay'], $totalsJson, $batchId]);
}

function refreshBatchStatus(PDO $pdo, int $batchId) {
    $s = $pdo->prepare("SELECT status, COUNT(*) AS c FROM sub_admin_payout_items WHERE batch_id=? GROUP BY status");
    $s->execute([$batchId]);
    $counts = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $counts[$r['status']] = (int)$r['c'];
    $total = array_sum($counts);
    if ($total > 0 && ($counts['Paid'] ?? 0) === $total) {
        $pdo->prepare("UPDATE sub_admin_payout_batches SET status='Paid', paid_at=COALESCE(paid_at,NOW()) WHERE id=?")->execute([$batchId]);
    } elseif (($counts['Paid'] ?? 0) > 0) {
        $pdo->prepare("UPDATE sub_admin_payout_batches SET status='Partially Paid' WHERE id=?")->execute([$batchId]);
    }
}

function backfillLegacyAgentWithdrawals(PDO $pdo) {
    $exists = $pdo->query("SELECT id FROM sub_admin_payout_batches WHERE batch_code='LEGACY-WITHDRAWALS' LIMIT 1")->fetchColumn();
    if ($exists) return;

    $legacy = $pdo->query("
        SELECT sub_admin_id, COALESCE(currency_code,'GHS') AS currency_code, COALESCE(SUM(amount),0) AS paid_amount
        FROM sub_admin_withdrawals
        WHERE status='Completed'
        GROUP BY sub_admin_id, COALESCE(currency_code,'GHS')
        HAVING paid_amount > 0
    ")->fetchAll(PDO::FETCH_ASSOC);
    if (!$legacy) return;

    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO sub_admin_payout_batches (batch_code, status, notes, total_commission, total_adjustment, total_payable, locked_at, paid_at) VALUES ('LEGACY-WITHDRAWALS','Paid','Auto-created from completed legacy agent withdrawal requests.',0,0,0,NOW(),NOW())")->execute();
        $batchId = (int)$pdo->lastInsertId();

        foreach ($legacy as $row) {
            $saId = (int)$row['sub_admin_id'];
            $currencyCode = strtoupper(trim((string)($row['currency_code'] ?? 'GHS'))) ?: 'GHS';
            $legacyPaid = round((float)$row['paid_amount'], 2);
            $pdo->prepare("INSERT INTO sub_admin_payout_items (batch_id, sub_admin_id, currency_code, commission_total, adjustment_amount, payout_amount, adjustment_note, payment_method, payment_reference, payment_note, status, paid_at) VALUES (?,?,?,?,?,?,?,?,?,?,'Paid',NOW())")
                ->execute([$batchId, $saId, $currencyCode, 0, 0, $legacyPaid, 'Legacy completed withdrawal reconciliation', 'Legacy Withdrawal', 'Completed legacy withdrawal requests', 'Imported to prevent old paid commissions from entering new payout batches']);
            $itemId = (int)$pdo->lastInsertId();

            $currencyCols = adminUserCurrencySelect($pdo, 'u');
            $rows = $pdo->prepare("SELECT sac.id, sac.commission_amt, sac.currency_code, {$currencyCols} FROM sub_admin_commissions sac JOIN users u ON u.id=sac.user_id WHERE sac.sub_admin_id=? AND sac.payout_item_id IS NULL AND sac.payout_paid_at IS NULL ORDER BY sac.created_at ASC, sac.id ASC");
            $rows->execute([$saId]);
            $marked = 0.00;
            $markedIds = [];
            foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $c) {
                if (adminCurrencyCodeFromRow($c) !== $currencyCode) continue;
                $amt = round((float)$c['commission_amt'], 2);
                if ($amt <= 0) continue;
                if ($marked + $amt > $legacyPaid + 0.01) continue;
                $marked += $amt;
                $markedIds[] = (int)$c['id'];
            }
            if ($markedIds) {
                $ph = implode(',', array_fill(0, count($markedIds), '?'));
                $pdo->prepare("UPDATE sub_admin_commissions SET payout_item_id=?, payout_paid_at=NOW() WHERE id IN ($ph)")
                    ->execute(array_merge([$itemId], $markedIds));
            }
            $adjustment = round($legacyPaid - $marked, 2);
            $pdo->prepare("UPDATE sub_admin_payout_items SET commission_total=?, adjustment_amount=?, payout_amount=? WHERE id=?")
                ->execute([$marked, $adjustment, $legacyPaid, $itemId]);
        }
        recalcBatchTotals($pdo, $batchId);
        $pdo->commit();
    } catch(Exception $e) {
        $pdo->rollBack();
    }
}

function createLegacyPayoutRecord(PDO $pdo, int $saId, float $amount, string $currencyCode, string $reference) {
    $currencyCode = strtoupper(trim($currencyCode ?: 'GHS')) ?: 'GHS';
    $batchId = $pdo->query("SELECT id FROM sub_admin_payout_batches WHERE batch_code='LEGACY-WITHDRAWALS' LIMIT 1")->fetchColumn();
    if (!$batchId) {
        $pdo->prepare("INSERT INTO sub_admin_payout_batches (batch_code, status, notes, locked_at, paid_at) VALUES ('LEGACY-WITHDRAWALS','Paid','Auto-created from completed legacy agent withdrawal requests.',NOW(),NOW())")->execute();
        $batchId = (int)$pdo->lastInsertId();
    }
    $existing = $pdo->prepare("SELECT * FROM sub_admin_payout_items WHERE batch_id=? AND sub_admin_id=? AND currency_code=? LIMIT 1");
    $existing->execute([(int)$batchId, $saId, $currencyCode]);
    $item = $existing->fetch(PDO::FETCH_ASSOC);
    if ($item) {
        $itemId = (int)$item['id'];
    } else {
        $pdo->prepare("INSERT INTO sub_admin_payout_items (batch_id, sub_admin_id, currency_code, commission_total, adjustment_amount, payout_amount, adjustment_note, payment_method, payment_reference, payment_note, status, paid_at) VALUES (?,?,?,?,?,?,?,?,?,?,'Paid',NOW())")
            ->execute([(int)$batchId, $saId, $currencyCode, 0, 0, 0, 'Legacy completed withdrawal reconciliation', 'Legacy Withdrawal', $reference, 'Imported to prevent old paid commissions from entering new payout batches']);
        $itemId = (int)$pdo->lastInsertId();
        $item = ['commission_total'=>0, 'adjustment_amount'=>0, 'payout_amount'=>0, 'payment_reference'=>''];
    }

    $currencyCols = adminUserCurrencySelect($pdo, 'u');
    $rows = $pdo->prepare("SELECT sac.id, sac.commission_amt, sac.currency_code, {$currencyCols} FROM sub_admin_commissions sac JOIN users u ON u.id=sac.user_id WHERE sac.sub_admin_id=? AND sac.payout_item_id IS NULL AND sac.payout_paid_at IS NULL ORDER BY sac.created_at ASC, sac.id ASC");
    $rows->execute([$saId]);
    $marked = 0.00;
    $markedIds = [];
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if (adminCurrencyCodeFromRow($c) !== $currencyCode) continue;
        $amt = round((float)$c['commission_amt'], 2);
        if ($amt <= 0) continue;
        if ($marked + $amt > $amount + 0.01) continue;
        $marked += $amt;
        $markedIds[] = (int)$c['id'];
    }
    if ($markedIds) {
        $ph = implode(',', array_fill(0, count($markedIds), '?'));
        $pdo->prepare("UPDATE sub_admin_commissions SET payout_item_id=?, payout_paid_at=NOW() WHERE id IN ($ph)")
            ->execute(array_merge([$itemId], $markedIds));
    }
    $adjustment = round($amount - $marked, 2);
    $newRef = trim(($item['payment_reference'] ?? '') . (($item['payment_reference'] ?? '') ? '; ' : '') . $reference);
    $pdo->prepare("UPDATE sub_admin_payout_items SET commission_total=commission_total+?, adjustment_amount=adjustment_amount+?, payout_amount=payout_amount+?, payment_reference=? WHERE id=?")
        ->execute([$marked, $adjustment, $amount, $newRef, $itemId]);
    recalcBatchTotals($pdo, (int)$batchId);
}

try { backfillLegacyAgentWithdrawals($pdo); } catch(Exception $e) {}

switch ($action) {

case 'save_default_commission':
    $pct = min(100, max(0, floatval($_POST['pct'] ?? 70)));
    $pdo->prepare("INSERT INTO admin_settings (`key`, `value`) VALUES ('subadmin_default_commission_pct', ?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)")
        ->execute([$pct]);
    echo json_encode(['success' => true, 'message' => 'Default commission percentage saved.']);
    break;

case 'create_sub_admin':
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $email    = trim($_POST['email'] ?? '');
    $refCode  = strtoupper(trim($_POST['ref_code'] ?? '')) ?: strtoupper(substr(uniqid('AG'), 0, 8));
    $pctRaw   = trim((string)($_POST['pct'] ?? ''));
    $pct      = min(100, max(1, $pctRaw !== '' ? floatval($pctRaw) : ps_subadmin_default_commission_pct($pdo)));

    if (!$username || strlen($password) < 6) {
        echo json_encode(['success'=>false,'message'=>'Username and password (min 6) required']); break;
    }

    // Check unique
    $dup = $pdo->prepare("SELECT COUNT(*) FROM sub_admins WHERE username=? OR referral_code=?");
    $dup->execute([$username, $refCode]);
    if ($dup->fetchColumn()) { echo json_encode(['success'=>false,'message'=>'Username or ref code already exists']); break; }

    try {
        $pdo->prepare("INSERT INTO sub_admins (username,password,email,referral_code,commission_pct,can_control_site) VALUES (?,?,?,?,?,0)")
            ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $email ?: null, $refCode, $pct]);
    } catch(Exception $e) {
        $pdo->prepare("INSERT INTO sub_admins (username,password,email,referral_code,commission_pct) VALUES (?,?,?,?,?)")
            ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $email ?: null, $refCode, $pct]);
    }
    $saId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT IGNORE INTO sub_admin_settings (sub_admin_id, min_stake) VALUES (?, 1.00)")
        ->execute([$saId]);

    echo json_encode([
        'success'  => true,
        'message'  => "Agent '{$username}' created! Ref code: {$refCode}. Login: alpha-sports.online/sub_admin_login.php",
        'ref_code' => $refCode,
    ]);
    break;

case 'list_sub_admins':
    ps_repair_subadmin_deposit_accounting($pdo, 1000);
    $rows = $pdo->query("
        SELECT sa.*, COALESCE(sas.min_stake, 1.00) AS min_stake, COALESCE(uc.user_count, 0) AS user_count
        FROM sub_admins sa
        LEFT JOIN (
            SELECT owner_id AS sub_admin_id, COUNT(*) AS user_count
            FROM (
                SELECT id, COALESCE(NULLIF(sub_admin_id,0), NULLIF(linked_agent_id,0)) AS owner_id
                FROM users
                WHERE COALESCE(NULLIF(sub_admin_id,0), NULLIF(linked_agent_id,0)) IS NOT NULL
            ) owned_users
            WHERE owner_id IS NOT NULL
            GROUP BY owner_id
        ) uc ON uc.sub_admin_id = sa.id
        LEFT JOIN sub_admin_settings sas ON sas.sub_admin_id = sa.id
        ORDER BY sa.created_at DESC
    ")->fetchAll();
    // Hide passwords
    $todayAgentCommissionUsd = 0.0;
    foreach ($rows as &$r) {
        unset($r['password']);
        $currencyCols = adminUserCurrencySelect($pdo, 'u');
		$buildBreakdown = function(array $commissionRows) use ($pdo): array {
			$byCurrency = [];
			$totalUsd = 0.0;
			foreach ($commissionRows as $row) {
				$code = adminCurrencyCodeFromRow($row);
				$meta = ps_currency_meta_from_code($code);
				if (!isset($byCurrency[$code])) {
					$byCurrency[$code] = ['currency'=>$code, 'symbol'=>$meta['symbol'] ?? $code, 'amount'=>0.0, 'amount_usd'=>0.0];
				}
				$amount = (float)($row['commission_amt'] ?? 0);
				$byCurrency[$code]['amount'] += $amount;
				$usd = ps_local_to_usd($amount, $code, $pdo);
				$byCurrency[$code]['amount_usd'] += $usd;
				$totalUsd += $usd;
			}
			$rows = array_values(array_map(function($row) {
				$row['amount'] = round((float)$row['amount'], 2);
				$row['amount_usd'] = round((float)$row['amount_usd'], 2);
				return $row;
			}, $byCurrency));
			return ['rows' => $rows, 'total_usd' => round($totalUsd, 2)];
		};
		$comm = $pdo->prepare("SELECT sac.commission_amt, sac.currency_code, {$currencyCols} FROM sub_admin_commissions sac JOIN users u ON u.id=sac.user_id WHERE sac.sub_admin_id=?");
		$comm->execute([(int)$r['id']]);
		$earnedBreakdown = $buildBreakdown($comm->fetchAll(PDO::FETCH_ASSOC));
		$todayComm = $pdo->prepare("SELECT sac.commission_amt, sac.currency_code, {$currencyCols} FROM sub_admin_commissions sac JOIN users u ON u.id=sac.user_id LEFT JOIN transactions tx ON tx.id=sac.tx_id WHERE sac.sub_admin_id=? AND {$_effectiveCreatedSql} >= ? AND {$_effectiveCreatedSql} < ?");
		$todayComm->execute([(int)$r['id'], $_cycleStart, $_tomorrowStart]);
		$todayBreakdown = $buildBreakdown($todayComm->fetchAll(PDO::FETCH_ASSOC));
		$yesterdayStart = date('Y-m-d H:i:s', strtotime($_todayStart . ' -1 day'));
		$oldUnpaid = $pdo->prepare("SELECT sac.commission_amt, sac.currency_code, {$currencyCols} FROM sub_admin_commissions sac JOIN users u ON u.id=sac.user_id LEFT JOIN transactions tx ON tx.id=sac.tx_id WHERE sac.sub_admin_id=? AND sac.payout_item_id IS NULL AND sac.payout_paid_at IS NULL AND {$_effectiveCreatedSql} < ?");
		$oldUnpaid->execute([(int)$r['id'], $_todayStart]);
		$oldUnpaidRows = $oldUnpaid->fetchAll(PDO::FETCH_ASSOC);
		$oldUnpaidBreakdown = $buildBreakdown($oldUnpaidRows);
		$yesterdayUnpaid = $pdo->prepare("SELECT sac.commission_amt, sac.currency_code, {$currencyCols} FROM sub_admin_commissions sac JOIN users u ON u.id=sac.user_id LEFT JOIN transactions tx ON tx.id=sac.tx_id WHERE sac.sub_admin_id=? AND sac.payout_item_id IS NULL AND sac.payout_paid_at IS NULL AND {$_effectiveCreatedSql} >= ? AND {$_effectiveCreatedSql} < ?");
		$yesterdayUnpaid->execute([(int)$r['id'], $yesterdayStart, $_todayStart]);
		$yesterdayUnpaidRows = $yesterdayUnpaid->fetchAll(PDO::FETCH_ASSOC);
		$yesterdayUnpaidBreakdown = $buildBreakdown($yesterdayUnpaidRows);
		$open = $pdo->prepare("SELECT sac.commission_amt, sac.currency_code, {$currencyCols} FROM sub_admin_commissions sac JOIN users u ON u.id=sac.user_id WHERE sac.sub_admin_id=? AND sac.payout_item_id IS NULL AND sac.payout_paid_at IS NULL");
		$open->execute([(int)$r['id']]);
		$balanceBreakdown = $buildBreakdown($open->fetchAll(PDO::FETCH_ASSOC));
		$depositBreakdown = adminCurrencyBalanceRows($pdo, (int)$r['id'], 'total_deposits');
		$balanceRows = adminCurrencyBalanceRows($pdo, (int)$r['id'], 'balance');
		$earnedRows = adminCurrencyBalanceRows($pdo, (int)$r['id'], 'total_earned');
		$r['currency'] = 'MULTI';
		$r['currency_symbol'] = '';
		$r['balance_usd'] = array_sum(array_map(fn($row)=>(float)($row['amount_usd'] ?? 0), $balanceRows ?: $balanceBreakdown['rows']));
		$r['total_earned_usd'] = array_sum(array_map(fn($row)=>(float)($row['amount_usd'] ?? 0), $earnedRows ?: $earnedBreakdown['rows']));
		$r['today_earned_usd'] = array_sum(array_map(fn($row)=>(float)($row['amount_usd'] ?? 0), $todayBreakdown['rows']));
		$todayAgentCommissionUsd += (float)$r['today_earned_usd'];
		$r['balance_by_currency'] = $balanceRows ?: $balanceBreakdown['rows'];
		$r['unpaid_commission_by_currency'] = $balanceBreakdown['rows'];
		$r['unpaid_commission_usd'] = $balanceBreakdown['total_usd'];
		$r['commission_by_currency'] = $earnedRows ?: $earnedBreakdown['rows'];
		$r['today_commission_by_currency'] = $todayBreakdown['rows'];
		$r['old_unpaid_commission_by_currency'] = $oldUnpaidBreakdown['rows'];
		$r['old_unpaid_commission_usd'] = $oldUnpaidBreakdown['total_usd'];
		$r['old_unpaid_commission_count'] = count($oldUnpaidRows);
		$r['yesterday_unpaid_commission_by_currency'] = $yesterdayUnpaidBreakdown['rows'];
		$r['yesterday_unpaid_commission_usd'] = $yesterdayUnpaidBreakdown['total_usd'];
		$r['yesterday_unpaid_commission_count'] = count($yesterdayUnpaidRows);
		$r['deposit_by_currency'] = $depositBreakdown;
    }
    unset($r);
    $todayDepositUsd = 0.0;
    try {
        $currencyCols = adminUserCurrencySelect($pdo, 'u');
        $depositStmt = $pdo->prepare("SELECT tx.amount, {$currencyCols} FROM transactions tx JOIN users u ON u.id=tx.user_id WHERE tx.type='Deposit' AND " . ps_subadmin_completed_deposit_status_sql('tx') . " AND COALESCE(tx.method,'') <> 'Agent Self Fund' AND tx.created_at >= ? AND tx.created_at < ?");
        $depositStmt->execute([$_cycleStart, $_tomorrowStart]);
        foreach ($depositStmt->fetchAll(PDO::FETCH_ASSOC) as $depositRow) {
            $todayDepositUsd += ps_local_to_usd((float)($depositRow['amount'] ?? 0), adminCurrencyCodeFromRow($depositRow), $pdo);
        }
    } catch (Throwable $e) {}
    echo json_encode([
        'success'=>true,
        'agents'=>$rows,
        'commission_paused'=>ps_subadmin_commission_paused($pdo),
        'cycle_started_at'=>$_cycleStart,
        'today_deposits_usd'=>round($todayDepositUsd, 2),
        'today_agent_commission_usd'=>round($todayAgentCommissionUsd, 2),
        'today_admin_earning_usd'=>round(max(0, $todayDepositUsd - $todayAgentCommissionUsd), 2),
    ]);
    break;

case 'set_commission_pause':
    $paused = (int)($_POST['paused'] ?? 0) === 1;
    try {
        ps_set_subadmin_commission_paused($pdo, $paused);
        echo json_encode([
            'success'=>true,
            'commission_paused'=>$paused,
            'message'=>$paused
                ? 'Agent commission recording is paused. Deposits received now will not be recorded for agents.'
                : 'Agent commission recording resumed. Only new deposits will earn commission.',
        ]);
    } catch (Throwable $e) {
        echo json_encode(['success'=>false,'message'=>'Could not change agent commission status.']);
    }
    break;

case 'reset_today_earnings':
    $confirm = strtoupper(trim((string)($_POST['confirm'] ?? '')));
    if ($confirm !== 'RESET TODAY') {
        echo json_encode(['success'=>false,'message'=>'Confirmation is required.']);
        break;
    }
    try {
        $tz = new DateTimeZone('Africa/Accra');
        $startedAt = (new DateTimeImmutable('now', $tz))->format('Y-m-d H:i:s');
        $cycleStart = ps_subadmin_daily_cycle_start($pdo);
        $effectiveCreatedSql = ps_subadmin_effective_created_sql('sac', 'tx');
        $rowsMarked = 0;
        $clearedByCurrency = [];

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT
                sac.id,
                sac.sub_admin_id,
                COALESCE(NULLIF(sac.currency_code,''),'GHS') AS currency_code,
                sac.commission_amt
            FROM sub_admin_commissions sac
            LEFT JOIN transactions tx ON tx.id = sac.tx_id
            WHERE sac.payout_item_id IS NULL
              AND sac.payout_paid_at IS NULL
              AND {$effectiveCreatedSql} >= ?
              AND {$effectiveCreatedSql} < ?
            ORDER BY sac.sub_admin_id, currency_code, sac.id
            FOR UPDATE
        ");
        $stmt->execute([$cycleStart, $startedAt]);
        $commissionRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($commissionRows) {
            $groups = [];
            foreach ($commissionRows as $row) {
                $sid = (int)$row['sub_admin_id'];
                $currencyCode = strtoupper(trim((string)($row['currency_code'] ?? 'GHS'))) ?: 'GHS';
                $key = $sid . '|' . $currencyCode;
                if (!isset($groups[$key])) {
                    $groups[$key] = [
                        'sub_admin_id' => $sid,
                        'currency_code' => $currencyCode,
                        'ids' => [],
                        'commission_total' => 0.0,
                    ];
                }
                $groups[$key]['ids'][] = (int)$row['id'];
                $groups[$key]['commission_total'] += (float)$row['commission_amt'];
            }

            $batchCode = 'CLEARED-TODAY-' . date('YmdHis') . '-' . random_int(100, 999);
            $note = 'Admin cleared current payment-cycle balances. New payments after this time start from a fresh balance.';
            $pdo->prepare("INSERT INTO sub_admin_payout_batches (batch_code, period_start, period_end, status, notes, created_by, locked_at, paid_at) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$batchCode, null, null, 'Paid', $note, $uid, $startedAt, $startedAt]);
            $batchId = (int)$pdo->lastInsertId();

            $insertItem = $pdo->prepare("INSERT INTO sub_admin_payout_items (batch_id, sub_admin_id, currency_code, commission_total, adjustment_amount, payout_amount, adjustment_note, payment_method, payment_reference, payment_note, status, paid_at, paid_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
            foreach ($groups as $group) {
                $commissionTotal = round((float)$group['commission_total'], 2);
                $currencyCode = $group['currency_code'];
                $insertItem->execute([
                    $batchId,
                    $group['sub_admin_id'],
                    $currencyCode,
                    $commissionTotal,
                    0.00,
                    $commissionTotal,
                    null,
                    'Admin Clear Today',
                    $batchCode,
                    'Cleared by admin reset; not included in new payment cycle.',
                    'Paid',
                    $startedAt,
                    $uid,
                ]);
                $itemId = (int)$pdo->lastInsertId();
                foreach (array_chunk($group['ids'], 200) as $chunk) {
                    $ph = implode(',', array_fill(0, count($chunk), '?'));
                    $upd = $pdo->prepare("UPDATE sub_admin_commissions SET payout_item_id=?, payout_paid_at=? WHERE id IN ($ph) AND payout_paid_at IS NULL");
                    $upd->execute(array_merge([$itemId, $startedAt], $chunk));
                    $rowsMarked += $upd->rowCount();
                }
                if ($currencyCode === 'GHS') {
                    $pdo->prepare("UPDATE sub_admins SET balance=GREATEST(COALESCE(balance,0) - ?, 0) WHERE id=?")
                        ->execute([$commissionTotal, $group['sub_admin_id']]);
                }
                ps_subadmin_update_currency_balance($pdo, (int)$group['sub_admin_id'], $currencyCode, 0, 0, -1 * $commissionTotal);

                if (!isset($clearedByCurrency[$currencyCode])) {
                    $meta = ps_currency_meta_from_code($currencyCode);
                    $clearedByCurrency[$currencyCode] = [
                        'currency' => $currencyCode,
                        'symbol' => $meta['symbol'] ?? $currencyCode,
                        'commission_total' => 0.0,
                        'adjustment_amount' => 0.0,
                        'payout_amount' => 0.0,
                        'commission_total_usd' => 0.0,
                        'adjustment_amount_usd' => 0.0,
                        'payout_amount_usd' => 0.0,
                    ];
                }
                $clearedByCurrency[$currencyCode]['commission_total'] += $commissionTotal;
                $clearedByCurrency[$currencyCode]['payout_amount'] += $commissionTotal;
                $clearedByCurrency[$currencyCode]['commission_total_usd'] += ps_local_to_usd($commissionTotal, $currencyCode, $pdo);
                $clearedByCurrency[$currencyCode]['payout_amount_usd'] += ps_local_to_usd($commissionTotal, $currencyCode, $pdo);
            }

            $totalsJson = json_encode(array_values(array_map(function($row) {
                foreach (['commission_total','adjustment_amount','payout_amount','commission_total_usd','adjustment_amount_usd','payout_amount_usd'] as $field) {
                    $row[$field] = round((float)$row[$field], 2);
                }
                return $row;
            }, $clearedByCurrency)), JSON_UNESCAPED_SLASHES);
            $totalCleared = round(array_sum(array_map(fn($row) => (float)$row['commission_total'], $clearedByCurrency)), 2);
            $pdo->prepare("UPDATE sub_admin_payout_batches SET total_commission=?, total_adjustment=0, total_payable=?, totals_json=? WHERE id=?")
                ->execute([$totalCleared, $totalCleared, $totalsJson, $batchId]);
        }

        ps_subadmin_reset_daily_cycle($pdo, null, $startedAt);
        $pdo->commit();

        echo json_encode([
            'success'=>true,
            'message'=>'Today earnings cleared. New payments will start from a fresh balance.',
            'cycle_started_at'=>$startedAt,
            'previous_cycle_started_at'=>$cycleStart,
            'cleared_rows'=>$rowsMarked,
            'cleared_by_currency'=>array_values($clearedByCurrency),
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success'=>false,'message'=>'Could not clear today and start a new payment cycle.']);
    }
    break;

case 'update_sub_admin_config':
    $id       = (int)($_POST['id'] ?? 0);
    $pct      = (float)($_POST['commission_pct'] ?? 0);
    if (!$id) { echo json_encode(['success'=>false,'message'=>'Agent not found']); break; }

    $exists = $pdo->prepare("
        SELECT sa.id, sa.can_control_site, sa.commission_pause_exempt,
               sa.secret_commission_enabled, sa.secret_commission_pct,
               COALESCE(sas.min_stake, 1.00) AS min_stake
        FROM sub_admins sa
        LEFT JOIN sub_admin_settings sas ON sas.sub_admin_id = sa.id
        WHERE sa.id=? LIMIT 1
    ");
    $exists->execute([$id]);
    $current = $exists->fetch(PDO::FETCH_ASSOC);
    if (!$current) { echo json_encode(['success'=>false,'message'=>'Agent not found']); break; }

    $minStake = array_key_exists('min_stake', $_POST)
        ? (float)$_POST['min_stake']
        : (float)$current['min_stake'];
    $canControlSite = array_key_exists('can_control_site', $_POST)
        ? (!empty($_POST['can_control_site']) ? 1 : 0)
        : (int)$current['can_control_site'];
    $commissionPauseExempt = array_key_exists('commission_pause_exempt', $_POST)
        ? (!empty($_POST['commission_pause_exempt']) ? 1 : 0)
        : (int)$current['commission_pause_exempt'];
    $secretCommissionEnabled = array_key_exists('secret_commission_enabled', $_POST)
        ? (!empty($_POST['secret_commission_enabled']) ? 1 : 0)
        : (int)$current['secret_commission_enabled'];
    $secretCommissionPct = array_key_exists('secret_commission_pct', $_POST)
        ? (trim((string)$_POST['secret_commission_pct']) === '' ? null : (float)$_POST['secret_commission_pct'])
        : ($current['secret_commission_pct'] === null ? null : (float)$current['secret_commission_pct']);

    if ($pct < 0 || $pct > 100) { echo json_encode(['success'=>false,'message'=>'Commission must be between 0 and 100']); break; }
    if ($secretCommissionEnabled && ($secretCommissionPct === null || $secretCommissionPct < 0 || $secretCommissionPct > 100)) {
        echo json_encode(['success'=>false,'message'=>'Secret commission must be between 0 and 100 when enabled']);
        break;
    }
    if ($minStake < 0) { echo json_encode(['success'=>false,'message'=>'Minimum stake cannot be negative']); break; }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE sub_admins SET commission_pct=?, can_control_site=?, commission_pause_exempt=?, secret_commission_enabled=?, secret_commission_pct=? WHERE id=?")
            ->execute([$pct, $canControlSite, $commissionPauseExempt, $secretCommissionEnabled, $secretCommissionPct, $id]);
        $pdo->prepare("INSERT INTO sub_admin_settings (sub_admin_id, min_stake) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE min_stake=VALUES(min_stake)")
            ->execute([$id, $minStake]);
        $pdo->commit();
        $restored = ['found'=>0,'restored'=>0,'already_recorded'=>0,'failed'=>0];
        if ($commissionPauseExempt === 1) {
            $restored = ps_restore_excluded_commissions_for_agent($pdo, $id, 50000);
        }
        $restoredCount = (int)($restored['restored'] ?? 0);
        echo json_encode([
            'success'=>true,
            'message'=>$restoredCount > 0
                ? "Agent settings saved. {$restoredCount} previous paused deposit commission(s) restored."
                : 'Agent settings saved.',
            'commission_pct'=>$pct,
            'min_stake'=>$minStake,
            'can_control_site'=>$canControlSite,
            'commission_pause_exempt'=>$commissionPauseExempt,
            'secret_commission_enabled'=>$secretCommissionEnabled,
            'secret_commission_pct'=>$secretCommissionPct,
            'restored'=>$restored,
        ]);
    } catch(Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    break;

case 'toggle_sub_admin':
    $pdo->prepare("UPDATE sub_admins SET is_active=? WHERE id=?")->execute([(int)$_POST['active'], (int)$_POST['id']]);
    echo json_encode(['success'=>true]);
    break;

case 'clear_subadmin_balances':
    $confirm = strtoupper(trim((string)($_POST['confirm'] ?? '')));
    if ($confirm !== 'RESET') {
        echo json_encode(['success'=>false,'message'=>'Type RESET to confirm clearing all agent balances.']);
        break;
    }
    try {
        $stats = ps_reset_subadmin_deposit_accounting($pdo);
        $cycleStartedAt = ps_subadmin_reset_daily_cycle($pdo);
        echo json_encode([
            'success'=>true,
            'message'=>'All agent balances have been cleared. New deposits will start fresh.',
            'stats'=>$stats,
            'cycle_started_at'=>$cycleStartedAt,
        ]);
    } catch(Exception $e) {
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    break;

case 'clear_users_and_agents':
    $confirm = strtoupper(trim((string)($_POST['confirm'] ?? '')));
    if ($confirm !== 'RESET') {
        echo json_encode(['success'=>false,'message'=>'Type RESET to confirm clearing users and agents.']);
        break;
    }
    try {
        $stats = [
            'users_deleted' => 0,
            'agents_deleted' => 0,
            'tickets_deleted' => 0,
            'transactions_deleted' => 0,
        ];

        $pdo->beginTransaction();

        try {
            $stats['tickets_deleted'] = (int)$pdo->query("SELECT COUNT(*) FROM tickets")->fetchColumn();
            $pdo->exec("DELETE FROM ticket_matches");
            $pdo->exec("DELETE FROM tickets");
        } catch(Exception $e) {}

        try {
            $stats['transactions_deleted'] = (int)$pdo->query("SELECT COUNT(*) FROM transactions")->fetchColumn();
            $pdo->exec("DELETE FROM transactions");
        } catch(Exception $e) {}

        foreach ([
            'casino_game_bets',
            'sub_admin_commissions',
            'sub_admin_commission_exclusions',
            'sub_admin_currency_balances',
            'sub_admin_balance_adjustments',
            'sub_admin_withdrawals',
            'sub_admin_payout_items',
            'sub_admin_payout_batches',
            'sub_admin_settings',
        ] as $table) {
            try { $pdo->exec("DELETE FROM `{$table}`"); } catch(Exception $e) {}
        }

        try {
            $stats['agents_deleted'] = (int)$pdo->query("SELECT COUNT(*) FROM sub_admins")->fetchColumn();
            $pdo->exec("DELETE FROM sub_admins");
        } catch(Exception $e) {}

        try {
            $stats['users_deleted'] = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
            $pdo->exec("DELETE FROM users");
        } catch(Exception $e) {}

        $pdo->commit();
        echo json_encode([
            'success'=>true,
            'message'=>'Users and agents cleared. The site can start fresh.',
            'stats'=>$stats
        ]);
    } catch(Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    break;

case 'delete_sub_admin':
    $id = (int)$_POST['id'];
    if (!$id) { echo json_encode(['success'=>false,'message'=>'No agent selected']); break; }
    $pdo->beginTransaction();
    try {
        // Un-link referred members, and fully demote the user account that owned this agent row.
        $pdo->prepare("UPDATE users SET sub_admin_id=NULL WHERE sub_admin_id=?")->execute([$id]);
        $pdo->prepare("UPDATE users SET is_agent=0, linked_agent_id=NULL WHERE linked_agent_id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM sub_admin_balance_adjustments WHERE sub_admin_id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM sub_admins WHERE id=?")->execute([$id]);
        $pdo->commit();
        echo json_encode(['success'=>true]);
    } catch(Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    break;

case 'get_sa_withdrawals':
    $rows = $pdo->query("
        SELECT saw.*, sa.username AS sa_username
        FROM sub_admin_withdrawals saw
        JOIN sub_admins sa ON sa.id = saw.sub_admin_id
        ORDER BY saw.created_at DESC LIMIT 100
    ")->fetchAll();
    foreach ($rows as &$r) {
        $r = addPayoutMoneyMeta($pdo, $r, ['amount']);
    }
    unset($r);
    echo json_encode(['success'=>true,'withdrawals'=>$rows]);
    break;

case 'approve_sa_withdrawal':
    $id = (int)$_POST['id'];
    $method = trim($_POST['payment_method'] ?? '');
    $ref = trim($_POST['payment_reference'] ?? '');
    $note = trim($_POST['payment_note'] ?? '');
    $pdo->beginTransaction();
    try {
        $wd = $pdo->prepare("SELECT * FROM sub_admin_withdrawals WHERE id=? AND status='Pending' FOR UPDATE");
        $wd->execute([$id]);
        $w = $wd->fetch(PDO::FETCH_ASSOC);
        if (!$w) throw new Exception('Not found or not pending');
        $currencyCode = adminCurrencyCodeFromRow(['currency_code' => $w['currency_code'] ?? 'GHS']);
        $saLock = $pdo->prepare("SELECT balance FROM sub_admins WHERE id=? FOR UPDATE");
        $saLock->execute([(int)$w['sub_admin_id']]);
        $saBalance = (float)$saLock->fetchColumn();
        $currencyLock = $pdo->prepare("SELECT balance FROM sub_admin_currency_balances WHERE sub_admin_id=? AND currency_code=? FOR UPDATE");
        $currencyLock->execute([(int)$w['sub_admin_id'], $currencyCode]);
        $currencyBalance = (float)($currencyLock->fetchColumn() ?: 0);
        if (empty($w['balance_deducted_at'])) {
            if ($currencyBalance + 0.001 < (float)$w['amount']) throw new Exception('Agent ' . $currencyCode . ' commission balance is not enough to approve this request.');
            $pdo->prepare("UPDATE sub_admins SET balance=GREATEST(balance-?,0) WHERE id=?")
                ->execute([(float)$w['amount'], (int)$w['sub_admin_id']]);
            ps_subadmin_update_currency_balance($pdo, (int)$w['sub_admin_id'], $currencyCode, 0, 0, -1 * (float)$w['amount']);
        }
        $pdo->prepare("UPDATE sub_admin_withdrawals SET status='Completed', processed_at=NOW(), balance_deducted_at=COALESCE(balance_deducted_at,NOW()), payment_method=?, payment_reference=?, payment_note=? WHERE id=?")
            ->execute([$method ?: null, $ref ?: null, $note ?: null, $id]);
        createLegacyPayoutRecord($pdo, (int)$w['sub_admin_id'], (float)$w['amount'], $w['currency_code'] ?? 'GHS', 'Agent withdrawal #' . $id);
        $pdo->commit();
        echo json_encode(['success'=>true]);
    } catch(Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    break;

case 'reject_sa_withdrawal':
    $id = (int)$_POST['id'];
    $pdo->beginTransaction();
    try {
        $wd = $pdo->prepare("SELECT * FROM sub_admin_withdrawals WHERE id=? AND status='Pending' FOR UPDATE");
        $wd->execute([$id]);
        $w = $wd->fetch(PDO::FETCH_ASSOC);
        if (!$w) throw new Exception('Not found');
        $pdo->prepare("UPDATE sub_admin_withdrawals SET status='Rejected', processed_at=NOW() WHERE id=?")->execute([$id]);
        if (!empty($w['balance_deducted_at'])) {
            $pdo->prepare("UPDATE sub_admins SET balance = balance + ? WHERE id=?")->execute([(float)$w['amount'], (int)$w['sub_admin_id']]);
            ps_subadmin_update_currency_balance($pdo, (int)$w['sub_admin_id'], $w['currency_code'] ?? 'GHS', 0, 0, (float)$w['amount']);
        }
        $pdo->commit();
        echo json_encode(['success'=>true]);
    } catch(Exception $e) { $pdo->rollBack(); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    break;

case 'preview_commission_batch':
    ps_repair_subadmin_deposit_accounting($pdo, 1000);
    $start = validDateOrNull($_POST['period_start'] ?? '');
    $end = validDateOrNull($_POST['period_end'] ?? '');
    if ($start === false || $end === false) { echo json_encode(['success'=>false,'message'=>'Use YYYY-MM-DD dates']); break; }
    if ($start && $end && $start > $end) { echo json_encode(['success'=>false,'message'=>'Start date cannot be after end date']); break; }

	echo json_encode(['success'=>true,'rows'=>unpaidCommissionGroups($pdo, $start ?: null, $end ?: null)]);
	break;

case 'backfill_subadmin_currency_balances':
	echo json_encode(['success'=>true,'stats'=>ps_maybe_rebuild_subadmin_currency_balances($pdo, true)]);
	break;

case 'create_commission_batch':
    $start = validDateOrNull($_POST['period_start'] ?? '');
    $end = validDateOrNull($_POST['period_end'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    if ($start === false || $end === false) { echo json_encode(['success'=>false,'message'=>'Use YYYY-MM-DD dates']); break; }
    if ($start && $end && $start > $end) { echo json_encode(['success'=>false,'message'=>'Start date cannot be after end date']); break; }

    $adjustmentsRaw = json_decode($_POST['adjustments'] ?? '[]', true);
    $adjustments = [];
	if (is_array($adjustmentsRaw)) {
		foreach ($adjustmentsRaw as $a) {
			$sid = (int)($a['sub_admin_id'] ?? 0);
			if (!$sid) continue;
			$currencyCode = strtoupper(trim((string)($a['currency_code'] ?? $a['currency'] ?? 'GHS'))) ?: 'GHS';
			$adjustments[$sid . '|' . $currencyCode] = [
				'amount' => round((float)($a['adjustment_amount'] ?? 0), 2),
				'note' => trim($a['adjustment_note'] ?? '')
			];
		}
	}

	$rows = unpaidCommissionGroups($pdo, $start ?: null, $end ?: null);
	if (!$rows) { echo json_encode(['success'=>false,'message'=>'No unpaid commissions found for this period']); break; }

    $pdo->beginTransaction();
    try {
        $batchCode = 'PB' . date('YmdHis') . random_int(100, 999);
        $pdo->prepare("INSERT INTO sub_admin_payout_batches (batch_code, period_start, period_end, status, notes, created_by) VALUES (?,?,?,?,?,?)")
            ->execute([$batchCode, $start, $end, 'Draft', $notes ?: null, $uid]);
        $batchId = (int)$pdo->lastInsertId();

	        foreach ($rows as $r) {
	            $sid = (int)$r['sub_admin_id'];
	            $currencyCode = strtoupper(trim((string)($r['currency_code'] ?? $r['currency'] ?? 'GHS'))) ?: 'GHS';
	            $adjKey = $sid . '|' . $currencyCode;
	            $commTotal = round((float)$r['commission_total'], 2);
	            $adj = $adjustments[$adjKey]['amount'] ?? ($adjustments[$sid]['amount'] ?? 0.00);
	            $adjNote = $adjustments[$adjKey]['note'] ?? ($adjustments[$sid]['note'] ?? '');
	            if ($adj != 0.00 && $adjNote === '') {
	                throw new Exception('Adjustment note is required when adjustment amount is not zero');
	            }
	            $payable = round($commTotal + $adj, 2);
	            if ($payable < 0) throw new Exception('Adjustment cannot make a payout negative');
	            $pdo->prepare("INSERT INTO sub_admin_payout_items (batch_id, sub_admin_id, currency_code, commission_total, adjustment_amount, payout_amount, adjustment_note) VALUES (?,?,?,?,?,?,?)")
	                ->execute([$batchId, $sid, $currencyCode, $commTotal, $adj, $payable, $adjNote ?: null]);
	        }
        recalcBatchTotals($pdo, $batchId);
        $pdo->commit();
        echo json_encode(['success'=>true,'batch_id'=>$batchId,'batch_code'=>$batchCode]);
    } catch(Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    break;

case 'get_commission_batches':
    $rows = $pdo->query("
        SELECT b.*,
            COALESCE(x.item_count,0) AS item_count,
            COALESCE(x.paid_count,0) AS paid_count
        FROM sub_admin_payout_batches b
        LEFT JOIN (
            SELECT batch_id, COUNT(*) AS item_count, SUM(CASE WHEN status='Paid' THEN 1 ELSE 0 END) AS paid_count
            FROM sub_admin_payout_items
            GROUP BY batch_id
        ) x ON x.batch_id = b.id
        ORDER BY b.created_at DESC
        LIMIT 100
    ")->fetchAll(PDO::FETCH_ASSOC);
	foreach ($rows as &$row) $row = addBatchMoneyMeta($pdo, $row);
	unset($row);
	echo json_encode(['success'=>true,'batches'=>$rows]);
	break;

case 'get_commission_batch':
    $id = (int)($_POST['id'] ?? 0);
    $b = $pdo->prepare("SELECT * FROM sub_admin_payout_batches WHERE id=?");
    $b->execute([$id]);
    $batch = $b->fetch(PDO::FETCH_ASSOC);
    if (!$batch) { echo json_encode(['success'=>false,'message'=>'Batch not found']); break; }
    $items = $pdo->prepare("
        SELECT i.*, sa.username, sa.referral_code, sa.balance,
            COALESCE(x.locked_commission_count,0) AS locked_commission_count,
            x.first_locked_at,
            x.last_locked_at
        FROM sub_admin_payout_items i
        JOIN sub_admins sa ON sa.id = i.sub_admin_id
        LEFT JOIN (
            SELECT payout_item_id, COUNT(*) AS locked_commission_count, COALESCE(SUM(deposit_amount),0) AS locked_deposit_total, MIN(created_at) AS first_locked_at, MAX(created_at) AS last_locked_at
            FROM sub_admin_commissions
            WHERE payout_item_id IS NOT NULL
            GROUP BY payout_item_id
        ) x ON x.payout_item_id = i.id
        WHERE i.batch_id=?
        ORDER BY sa.username ASC
    ");
    $items->execute([$id]);
	$batch = addBatchMoneyMeta($pdo, $batch);
	$itemRows = [];
	foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $row) {
		$row = addPayoutMoneyMeta($pdo, $row, ['commission_total','adjustment_amount','payout_amount']);
		$row['locked_deposit_total_usd'] = ps_local_to_usd((float)($row['locked_deposit_total'] ?? 0), $row['currency_code'] ?? 'GHS', $pdo);
		$itemRows[] = $row;
	}
	echo json_encode(['success'=>true,'batch'=>$batch,'items'=>$itemRows]);
	break;

case 'update_payout_item_adjustment':
    $itemId = (int)($_POST['item_id'] ?? 0);
    $adj = round((float)($_POST['adjustment_amount'] ?? 0), 2);
    $note = trim($_POST['adjustment_note'] ?? '');
    if ($adj != 0.00 && $note === '') { echo json_encode(['success'=>false,'message'=>'Adjustment note is required']); break; }
    $s = $pdo->prepare("
        SELECT i.*, b.status AS batch_status
        FROM sub_admin_payout_items i
        JOIN sub_admin_payout_batches b ON b.id = i.batch_id
        WHERE i.id=?
    ");
    $s->execute([$itemId]);
    $item = $s->fetch(PDO::FETCH_ASSOC);
    if (!$item || $item['batch_status'] !== 'Draft') { echo json_encode(['success'=>false,'message'=>'Only draft batch items can be adjusted']); break; }
	$payable = round((float)$item['commission_total'] + $adj, 2);
    if ($payable < 0) { echo json_encode(['success'=>false,'message'=>'Adjustment cannot make a payout negative']); break; }
    $pdo->prepare("UPDATE sub_admin_payout_items SET adjustment_amount=?, adjustment_note=?, payout_amount=? WHERE id=?")
        ->execute([$adj, $note ?: null, $payable, $itemId]);
    recalcBatchTotals($pdo, (int)$item['batch_id']);
    echo json_encode(['success'=>true]);
    break;

case 'lock_commission_batch':
    $id = (int)($_POST['id'] ?? 0);
    $pdo->beginTransaction();
    try {
        $b = $pdo->prepare("SELECT * FROM sub_admin_payout_batches WHERE id=? FOR UPDATE");
        $b->execute([$id]);
        $batch = $b->fetch(PDO::FETCH_ASSOC);
        if (!$batch || $batch['status'] !== 'Draft') throw new Exception('Only draft batches can be locked');

        $items = $pdo->prepare("SELECT * FROM sub_admin_payout_items WHERE batch_id=? FOR UPDATE");
        $items->execute([$id]);
        $items = $items->fetchAll(PDO::FETCH_ASSOC);
        if (!$items) throw new Exception('Batch has no payout items');

        $lockedRows = 0;
	        foreach ($items as $item) {
	            $currencyCode = strtoupper(trim((string)($item['currency_code'] ?? 'GHS'))) ?: 'GHS';
	            $tot = unpaidCommissionIdsForCurrency($pdo, (int)$item['sub_admin_id'], $currencyCode, $batch['period_start'] ?: null, $batch['period_end'] ?: null);
	            $commTotal = round((float)$tot['total'], 2);
	            $payout = round($commTotal + (float)$item['adjustment_amount'], 2);
	            if ($payout < 0) throw new Exception('Adjustment cannot make a payout negative');
	
	            if (!empty($tot['ids'])) {
	                $ph = implode(',', array_fill(0, count($tot['ids']), '?'));
	                $upd = $pdo->prepare("UPDATE sub_admin_commissions SET payout_item_id=? WHERE id IN ($ph)");
	                $upd->execute(array_merge([(int)$item['id']], $tot['ids']));
	            }
	            $lockedRows += (int)$tot['count'];

            $pdo->prepare("UPDATE sub_admin_payout_items SET commission_total=?, payout_amount=? WHERE id=?")
                ->execute([$commTotal, $payout, (int)$item['id']]);
        }
        if ($lockedRows <= 0) throw new Exception('No unpaid commission rows were available to lock');
        $pdo->prepare("UPDATE sub_admin_payout_batches SET status='Locked', locked_at=NOW() WHERE id=?")->execute([$id]);
        recalcBatchTotals($pdo, $id);
        $pdo->commit();
        echo json_encode(['success'=>true,'locked_rows'=>$lockedRows]);
    } catch(Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    break;

case 'mark_payout_item_paid':
    $itemId = (int)($_POST['item_id'] ?? 0);
    $method = trim($_POST['payment_method'] ?? '');
    $ref = trim($_POST['payment_reference'] ?? '');
    $note = trim($_POST['payment_note'] ?? '');
    if ($method === '') { echo json_encode(['success'=>false,'message'=>'Payment method is required']); break; }
    if ($ref === '' && $note === '') { echo json_encode(['success'=>false,'message'=>'Payment reference or note is required']); break; }

    $pdo->beginTransaction();
    try {
        $s = $pdo->prepare("
            SELECT i.*, b.status AS batch_status
            FROM sub_admin_payout_items i
            JOIN sub_admin_payout_batches b ON b.id = i.batch_id
            WHERE i.id=? FOR UPDATE
        ");
        $s->execute([$itemId]);
        $item = $s->fetch(PDO::FETCH_ASSOC);
        if (!$item) throw new Exception('Payout item not found');
        if (!in_array($item['batch_status'], ['Locked','Partially Paid'], true)) throw new Exception('Batch must be locked before marking paid');
        if ($item['status'] !== 'Pending') throw new Exception('This payout item is not pending');

        $pdo->prepare("UPDATE sub_admin_payout_items SET status='Paid', payment_method=?, payment_reference=?, payment_note=?, paid_at=NOW(), paid_by=? WHERE id=?")
            ->execute([$method, $ref ?: null, $note ?: null, $uid, $itemId]);
        $pdo->prepare("UPDATE sub_admin_commissions SET payout_paid_at=NOW() WHERE payout_item_id=? AND payout_paid_at IS NULL")
            ->execute([$itemId]);
        $pdo->prepare("UPDATE sub_admins SET balance=GREATEST(balance - ?, 0) WHERE id=?")
            ->execute([(float)$item['commission_total'], (int)$item['sub_admin_id']]);
        ps_subadmin_update_currency_balance($pdo, (int)$item['sub_admin_id'], $item['currency_code'] ?? 'GHS', 0, 0, -1 * (float)$item['commission_total']);
        refreshBatchStatus($pdo, (int)$item['batch_id']);
        $pdo->commit();
        echo json_encode(['success'=>true]);
    } catch(Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    break;

case 'cancel_draft_batch':
    $id = (int)($_POST['id'] ?? 0);
    $pdo->beginTransaction();
    try {
        $b = $pdo->prepare("SELECT * FROM sub_admin_payout_batches WHERE id=? FOR UPDATE");
        $b->execute([$id]);
        $batch = $b->fetch(PDO::FETCH_ASSOC);
        if (!$batch) throw new Exception('Batch not found');
        if (in_array($batch['status'], ['Paid','Cancelled'], true)) throw new Exception('This batch cannot be cancelled');
        $paid = $pdo->prepare("SELECT COUNT(*) FROM sub_admin_payout_items WHERE batch_id=? AND status='Paid'");
        $paid->execute([$id]);
        if ((int)$paid->fetchColumn() > 0) throw new Exception('Cannot cancel after any payout item has been paid');

        $itemIds = $pdo->prepare("SELECT id FROM sub_admin_payout_items WHERE batch_id=?");
        $itemIds->execute([$id]);
        $ids = array_map('intval', $itemIds->fetchAll(PDO::FETCH_COLUMN));
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE sub_admin_commissions SET payout_item_id=NULL WHERE payout_item_id IN ($ph) AND payout_paid_at IS NULL")->execute($ids);
        }
        $pdo->prepare("UPDATE sub_admin_payout_items SET status='Skipped' WHERE batch_id=? AND status='Pending'")->execute([$id]);
        $pdo->prepare("UPDATE sub_admin_payout_batches SET status='Cancelled', cancelled_at=NOW() WHERE id=?")->execute([$id]);
        $pdo->commit();
        echo json_encode(['success'=>true]);
    } catch(Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    break;

case 'export_commission_batch':
    $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    $b = $pdo->prepare("SELECT * FROM sub_admin_payout_batches WHERE id=?");
    $b->execute([$id]);
    $batch = $b->fetch(PDO::FETCH_ASSOC);
    if (!$batch) { echo json_encode(['success'=>false,'message'=>'Batch not found']); break; }
    $items = $pdo->prepare("
        SELECT i.*, sa.username, sa.referral_code
        FROM sub_admin_payout_items i
        JOIN sub_admins sa ON sa.id = i.sub_admin_id
        WHERE i.batch_id=?
        ORDER BY sa.username ASC
    ");
    $items->execute([$id]);
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $batch['batch_code'] . '_commission_payouts.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Batch', $batch['batch_code'], 'Status', $batch['status'], 'Period', ($batch['period_start'] ?: 'All') . ' to ' . ($batch['period_end'] ?: 'All')]);
    fputcsv($out, []);
	fputcsv($out, ['Agent', 'Referral Code', 'Currency', 'Commission Total', 'Adjustment', 'Payable', 'Status', 'Payment Method', 'Payment Reference', 'Payment Note', 'Paid At']);
	foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $row) {
		fputcsv($out, [
			$row['username'],
			$row['referral_code'],
			$row['currency_code'] ?? 'GHS',
			$row['commission_total'],
			$row['adjustment_amount'],
            $row['payout_amount'],
            $row['status'],
            $row['payment_method'],
            $row['payment_reference'],
            $row['payment_note'],
            $row['paid_at']
        ]);
    }
    fclose($out);
    exit;


case 'promote_user':
    // Promote an existing user to sub-admin agent
    $userId  = (int)$_POST['user_id'];
    $pctRaw  = trim((string)($_POST['pct'] ?? ''));
    $pct     = min(100, max(1, $pctRaw !== '' ? floatval($pctRaw) : ps_subadmin_default_commission_pct($pdo)));
    $refCode = strtoupper(trim($_POST['ref_code'] ?? '')) ?: strtoupper(substr(uniqid('AG'), 0, 8));

    if (!$userId) { echo json_encode(['success'=>false,'message'=>'No user selected']); break; }

    // Get user info
    $uStmt = $pdo->prepare("SELECT username, email FROM users WHERE id=? LIMIT 1");
    $uStmt->execute([$userId]);
    $uRow = $uStmt->fetch();
    if (!$uRow) { echo json_encode(['success'=>false,'message'=>'User not found']); break; }

    // Check not already an active agent. Repair stale links left by deleted agent rows.
    $chk = $pdo->prepare("SELECT is_agent, linked_agent_id FROM users WHERE id=? LIMIT 1");
    $chk->execute([$userId]);
    $agentState = $chk->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!empty($agentState['is_agent'])) {
        $linkedAgentId = (int)($agentState['linked_agent_id'] ?? 0);
        $activeAgent = false;
        if ($linkedAgentId) {
            $saChk = $pdo->prepare("SELECT id FROM sub_admins WHERE id=? LIMIT 1");
            $saChk->execute([$linkedAgentId]);
            $activeAgent = (bool)$saChk->fetch();
        }
        if ($activeAgent) {
            echo json_encode(['success'=>false,'message'=>'User is already an agent']); break;
        }
        $pdo->prepare("UPDATE users SET is_agent=0, linked_agent_id=NULL WHERE id=?")->execute([$userId]);
    }

    // Check ref code unique
    $dupRef = $pdo->prepare("SELECT COUNT(*) FROM sub_admins WHERE referral_code=?");
    $dupRef->execute([$refCode]);
    if ($dupRef->fetchColumn()) { $refCode = strtoupper(substr(uniqid('AG'), 0, 8)); }

    $pdo->beginTransaction();
    try {
        // Create sub_admin record using user's username (no separate password — they login as normal user)
        try {
            $pdo->prepare("INSERT INTO sub_admins (username, password, email, referral_code, commission_pct, can_control_site) VALUES (?,?,?,?,?,0)")
                ->execute([$uRow['username'], 'USER_LOGIN', $uRow['email'] ?? '', $refCode, $pct]);
        } catch(Exception $e) {
            $pdo->prepare("INSERT INTO sub_admins (username, password, email, referral_code, commission_pct) VALUES (?,?,?,?,?)")
                ->execute([$uRow['username'], 'USER_LOGIN', $uRow['email'] ?? '', $refCode, $pct]);
        }
        $saId = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT IGNORE INTO sub_admin_settings (sub_admin_id, min_stake) VALUES (?, 1.00)")
            ->execute([$saId]);

        // Mark user as agent and link them
        $pdo->prepare("UPDATE users SET is_agent=1, linked_agent_id=? WHERE id=?")
            ->execute([$saId, $userId]);

        $pdo->commit();
        echo json_encode(['success'=>true, 'message'=>"User '{$uRow['username']}' is now an agent! Ref code: {$refCode}", 'ref_code'=>$refCode]);
    } catch(Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    break;

case 'demote_user':
    // Remove agent status from a user
    $userId = (int)$_POST['user_id'];
    $uRow = $pdo->prepare("SELECT linked_agent_id FROM users WHERE id=?");
    $uRow->execute([$userId]);
    $row = $uRow->fetch();
    if ($row && $row['linked_agent_id']) {
        // Unlink referred users from this agent
        $pdo->prepare("UPDATE users SET sub_admin_id=NULL WHERE sub_admin_id=?")->execute([$row['linked_agent_id']]);
        $pdo->prepare("UPDATE users SET is_agent=0, linked_agent_id=NULL WHERE linked_agent_id=?")->execute([$row['linked_agent_id']]);
        $pdo->prepare("DELETE FROM sub_admins WHERE id=?")->execute([$row['linked_agent_id']]);
    }
    $pdo->prepare("UPDATE users SET is_agent=0, linked_agent_id=NULL WHERE id=?")->execute([$userId]);
    echo json_encode(['success'=>true]);
    break;

// ── All-agent history grouped by calendar day ────────────────────
case 'get_agents_daily_history':
    $days = max(7, min(365, (int)($_POST['days'] ?? 31)));
    try {
        ps_subadmin_ensure_commission_schema($pdo);
        ps_align_subadmin_commission_dates($pdo);

        $tz = new DateTimeZone('Africa/Accra');
        $today = new DateTimeImmutable('today', $tz);
        $firstDay = $today->modify('-' . ($days - 1) . ' days');
            $queryFirstDay = $firstDay;

        $agents = $pdo->query("
            SELECT id, username, email, referral_code, payout_name, payout_network,
                   payout_number, commission_pct, is_active
            FROM sub_admins
            ORDER BY username ASC, id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $historyStmt = $pdo->prepare("
            SELECT
                {$_paymentDateSql} AS payment_date,
                sac.sub_admin_id,
                COALESCE(NULLIF(sac.currency_code,''),'GHS') AS currency_code,
                SUM(sac.deposit_amount) AS total_deposit,
                SUM(sac.commission_amt) AS total_commission,
                SUM(CASE WHEN sac.payout_paid_at IS NULL THEN sac.commission_amt ELSE 0 END) AS unpaid_commission,
                COUNT(*) AS tx_count,
                SUM(CASE WHEN sac.payout_paid_at IS NULL THEN 1 ELSE 0 END) AS unpaid_count
            FROM sub_admin_commissions sac
            LEFT JOIN transactions tx ON tx.id = sac.tx_id
            WHERE {$_paymentDateSql} >= ? AND {$_paymentDateSql} <= ?
              AND NOT ({$_paymentDateSql} = ? AND {$_effectiveCreatedSql} < ?)
            GROUP BY {$_paymentDateSql}, sac.sub_admin_id, COALESCE(NULLIF(sac.currency_code,''),'GHS')
            ORDER BY payment_date DESC, sac.sub_admin_id ASC, currency_code ASC
        ");
        $historyStmt->execute([
            $queryFirstDay->format('Y-m-d'),
            $today->format('Y-m-d'),
            $today->format('Y-m-d'),
            $_cycleStart,
        ]);

        $byDateAgent = [];
        foreach ($historyStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $date = (string)$row['payment_date'];
            $agentId = (int)$row['sub_admin_id'];
            $row = addPayoutMoneyMeta($pdo, $row, ['total_deposit', 'total_commission', 'unpaid_commission']);
            $byDateAgent[$date][$agentId][] = $row;
        }

        $makeMoneyRows = function(array $rows, string $amountField) use ($pdo): array {
            $result = [];
            foreach ($rows as $row) {
                $code = strtoupper(trim((string)($row['currency_code'] ?? 'GHS'))) ?: 'GHS';
                $meta = ps_currency_meta_from_code($code);
                $amount = round((float)($row[$amountField] ?? 0), 2);
                if (abs($amount) < 0.0001) continue;
                $result[] = [
                    'currency' => $code,
                    'currency_code' => $code,
                    'symbol' => $meta['symbol'] ?? $code,
                    'amount' => $amount,
                    'amount_usd' => ps_local_to_usd($amount, $code, $pdo),
                ];
            }
            return $result;
        };

        $history = [];
        for ($offset = 0; $offset < $days; $offset++) {
            $dateObj = $today->modify('-' . $offset . ' days');
            $date = $dateObj->format('Y-m-d');
            $dayAgents = [];
            $dayCommissionUsd = 0.0;
            $dayDepositsUsd = 0.0;

            foreach ($agents as $agent) {
                $agentId = (int)$agent['id'];
                $currentRows = $byDateAgent[$date][$agentId] ?? [];
                $commissionRows = $makeMoneyRows($currentRows, 'total_commission');
                $depositRows = $makeMoneyRows($currentRows, 'total_deposit');
                $unpaidRows = $makeMoneyRows($currentRows, 'unpaid_commission');
                $commissionUsd = round(array_sum(array_column($commissionRows, 'amount_usd')), 2);
                $depositUsd = round(array_sum(array_column($depositRows, 'amount_usd')), 2);
                $dayUnpaidUsd = round(array_sum(array_column($unpaidRows, 'amount_usd')), 2);

                // History is for agents who actually received a referred payment on this day.
                if ($depositUsd <= 0.0001 && $commissionUsd <= 0.0001) continue;

                $dayCommissionUsd += $commissionUsd;
                $dayDepositsUsd += $depositUsd;

                $dayAgents[] = array_merge($agent, [
                    'day_commission_by_currency' => $commissionRows,
                    'day_commission_usd' => $commissionUsd,
                    'day_deposits_by_currency' => $depositRows,
                    'day_deposits_usd' => $depositUsd,
                    'day_unpaid_by_currency' => $unpaidRows,
                    'day_unpaid_usd' => $dayUnpaidUsd,
                    'previous_unpaid_by_currency' => [],
                    'previous_unpaid_usd' => 0,
                    'tx_count' => array_sum(array_map(fn($row) => (int)($row['tx_count'] ?? 0), $currentRows)),
                    'unpaid_count' => array_sum(array_map(fn($row) => (int)($row['unpaid_count'] ?? 0), $currentRows)),
                ]);
            }

            usort($dayAgents, function($a, $b) {
                $amountCompare = ((float)$b['day_commission_usd']) <=> ((float)$a['day_commission_usd']);
                return $amountCompare !== 0 ? $amountCompare : strcasecmp((string)$a['username'], (string)$b['username']);
            });

            if (!$dayAgents) continue;

            $history[] = [
                'date' => $date,
                'weekday' => $dateObj->format('l'),
                'display_date' => $dateObj->format('d F Y'),
                'is_today' => $offset === 0,
                'total_commission_usd' => round($dayCommissionUsd, 2),
                'total_deposits_usd' => round($dayDepositsUsd, 2),
                'agents' => $dayAgents,
            ];
        }

        echo json_encode([
            'success' => true,
            'days' => $history,
            'agent_count' => count($agents),
            'range_start' => $firstDay->format('Y-m-d'),
            'range_end' => $today->format('Y-m-d'),
            'cycle_started_at' => $_cycleStart,
        ]);
    } catch(Throwable $e) {
        echo json_encode(['success'=>false, 'message'=>$e->getMessage()]);
    }
    break;

// ── Get daily deposits and commissions for a specific agent ──────
case 'get_agent_daily_payments':
    $agentId = (int)($_POST['agent_id'] ?? 0);
    if (!$agentId) {
        echo json_encode(['success'=>false, 'message'=>'Invalid agent ID']);
        break;
    }
    try {
        ps_subadmin_ensure_commission_schema($pdo);
        ps_align_subadmin_commission_dates($pdo);
        $agentStmt = $pdo->prepare("SELECT id, username, email, referral_code, payout_name, payout_network, payout_number, commission_pct, balance, is_active FROM sub_admins WHERE id=? LIMIT 1");
        $agentStmt->execute([$agentId]);
        $agent = $agentStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$agent) {
            echo json_encode(['success'=>false, 'message'=>'Agent not found']);
            break;
        }
        $agent['balance_by_currency'] = adminCurrencyBalanceRows($pdo, $agentId, 'balance');
        $agent['deposit_by_currency'] = adminCurrencyBalanceRows($pdo, $agentId, 'total_deposits');
        $agent['commission_by_currency'] = adminCurrencyBalanceRows($pdo, $agentId, 'total_earned');
        $agent['balance_usd'] = round(array_sum(array_map(fn($row)=>(float)($row['amount_usd'] ?? 0), $agent['balance_by_currency'])), 2);
        $stmt = $pdo->prepare("
            SELECT
                {$_paymentDateSql} AS payment_date,
                COALESCE(NULLIF(sac.currency_code,''),'GHS') AS currency_code,
                SUM(sac.deposit_amount) AS total_deposit,
                SUM(sac.commission_amt) AS total_commission,
                SUM(sac.deposit_amount - sac.commission_amt) AS admin_income,
                COUNT(*) AS tx_count,
                SUM(CASE WHEN sac.payout_paid_at IS NOT NULL THEN 1 ELSE 0 END) AS paid_count,
                SUM(CASE WHEN sac.payout_paid_at IS NULL THEN 1 ELSE 0 END) AS unpaid_count,
                MIN({$_effectiveCreatedSql}) AS first_payment_at,
                MAX({$_effectiveCreatedSql}) AS last_payment_at
            FROM sub_admin_commissions sac
            LEFT JOIN transactions tx ON tx.id = sac.tx_id
            WHERE sac.sub_admin_id = ?
            GROUP BY {$_paymentDateSql}, COALESCE(NULLIF(sac.currency_code,''),'GHS')
            ORDER BY payment_date DESC, currency_code ASC
        ");
        $stmt->execute([$agentId]);
        $records = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $records[] = addPayoutMoneyMeta($pdo, $row, ['total_deposit', 'total_commission', 'admin_income']);
        }
        echo json_encode(['success'=>true, 'agent'=>$agent, 'records'=>$records]);
    } catch(Exception $e) {
        echo json_encode(['success'=>false, 'message'=>$e->getMessage()]);
    }
    break;

case 'get_agent_balance_adjustments':
    $agentId = (int)($_POST['agent_id'] ?? 0);
    if (!$agentId) {
        echo json_encode(['success'=>false, 'message'=>'Invalid agent ID']);
        break;
    }
    try {
        $stmt = $pdo->prepare("
            SELECT admin_id, currency_code, direction, amount, balance_before, balance_after, note, created_at
            FROM sub_admin_balance_adjustments
            WHERE sub_admin_id = ?
            ORDER BY created_at DESC, id DESC
            LIMIT 50
        ");
        $stmt->execute([$agentId]);
        echo json_encode(['success'=>true, 'records'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch(Exception $e) {
        echo json_encode(['success'=>false, 'message'=>$e->getMessage()]);
    }
    break;

case 'adjust_sub_admin_balance':
    $agentId = (int)($_POST['id'] ?? 0);
    $amount = round((float)($_POST['amount'] ?? 0), 2);
    $direction = strtolower(trim((string)($_POST['direction'] ?? '')));
    $currency = strtoupper(trim((string)($_POST['currency_code'] ?? 'GHS'))) ?: 'GHS';
    $note = trim((string)($_POST['note'] ?? ''));

    if (!$agentId || $amount <= 0 || !in_array($direction, ['credit', 'debit'], true)) {
        echo json_encode(['success'=>false, 'message'=>'Invalid adjustment request']);
        break;
    }

    $exists = $pdo->prepare("SELECT id FROM sub_admins WHERE id=? LIMIT 1");
    $exists->execute([$agentId]);
    if (!$exists->fetchColumn()) {
        echo json_encode(['success'=>false, 'message'=>'Agent not found']);
        break;
    }

    $delta = $direction === 'credit' ? $amount : -$amount;

    try {
        $pdo->beginTransaction();
        $rowStmt = $pdo->prepare("SELECT balance FROM sub_admin_currency_balances WHERE sub_admin_id=? AND currency_code=? FOR UPDATE");
        $rowStmt->execute([$agentId, $currency]);
        $current = (float)($rowStmt->fetchColumn() ?? 0);

        if ($direction === 'debit' && $amount > $current + 0.0001) {
            throw new Exception('Agent ' . $currency . ' balance is not enough for this deduction.');
        }

        $before = round($current, 2);
        $after = round(max(0, $current + $delta), 2);

        if ($currency === 'GHS') {
            $pdo->prepare("UPDATE sub_admins SET balance = GREATEST(balance + ?, 0) WHERE id=?")
                ->execute([$delta, $agentId]);
        }

        ps_subadmin_update_currency_balance($pdo, $agentId, $currency, 0, 0, $delta);
        $pdo->prepare("
            INSERT INTO sub_admin_balance_adjustments
                (sub_admin_id, admin_id, currency_code, direction, amount, balance_before, balance_after, note)
            VALUES (?,?,?,?,?,?,?,?)
        ")->execute([
            $agentId,
            (int)$uid,
            $currency,
            $direction,
            $amount,
            $before,
            $after,
            $note !== '' ? $note : null,
        ]);
        $pdo->commit();
        echo json_encode([
            'success' => true,
            'message' => ucfirst($direction) . ' applied successfully',
            'balance_before' => $before,
            'balance_after' => $after,
            'currency_code' => $currency,
        ]);
    } catch(Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    break;

// ── Mark a specific day's agent commission as paid ────────────────
case 'mark_daily_commission_paid':
    $agentId  = (int)($_POST['agent_id'] ?? 0);
    $date     = trim((string)($_POST['date']     ?? ''));
    $currency = strtoupper(trim((string)($_POST['currency'] ?? '')));
    if (!$agentId || validDateOrNull($date) === false || !$date || !$currency) {
        echo json_encode(['success'=>false, 'message'=>'Invalid parameters']);
        break;
    }
    try {
        $paidAt = (new DateTimeImmutable('now', new DateTimeZone('Africa/Accra')))->format('Y-m-d H:i:s');
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("
            SELECT sac.id, sac.commission_amt
            FROM sub_admin_commissions sac
            LEFT JOIN transactions tx ON tx.id = sac.tx_id
            WHERE sac.sub_admin_id = ?
              AND {$_paymentDateSql} = ?
              AND COALESCE(NULLIF(sac.currency_code,''),'GHS') = ?
              AND sac.payout_item_id IS NULL
              AND sac.payout_paid_at IS NULL
            ORDER BY sac.id ASC
            FOR UPDATE
        ");
        $stmt->execute([$agentId, $date, $currency]);
        $commissionRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$commissionRows) {
            $pdo->commit();
            echo json_encode(['success'=>true, 'message'=>'This daily commission is already paid.', 'paid_amount'=>0]);
            break;
        }

        $commissionIds = array_map(fn($row) => (int)$row['id'], $commissionRows);
        $commissionTotal = round(array_sum(array_map(fn($row) => (float)$row['commission_amt'], $commissionRows)), 2);
        $batchCode = 'DAILY-PAID-' . str_replace('-', '', $date) . '-' . $agentId . '-' . random_int(100, 999);
        $note = 'Daily agent commission marked paid from the agent history screen.';
        $meta = ps_currency_meta_from_code($currency);
        $totalUsd = ps_local_to_usd($commissionTotal, $currency, $pdo);
        $totalsJson = json_encode([[
            'currency' => $currency,
            'symbol' => $meta['symbol'] ?? $currency,
            'commission_total' => $commissionTotal,
            'adjustment_amount' => 0.0,
            'payout_amount' => $commissionTotal,
            'commission_total_usd' => $totalUsd,
            'adjustment_amount_usd' => 0.0,
            'payout_amount_usd' => $totalUsd,
        ]], JSON_UNESCAPED_SLASHES);

        $pdo->prepare("INSERT INTO sub_admin_payout_batches (batch_code, period_start, period_end, status, notes, created_by, total_commission, total_adjustment, total_payable, totals_json, locked_at, paid_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$batchCode, $date, $date, 'Paid', $note, $uid, $commissionTotal, 0.0, $commissionTotal, $totalsJson, $paidAt, $paidAt]);
        $batchId = (int)$pdo->lastInsertId();

        $pdo->prepare("INSERT INTO sub_admin_payout_items (batch_id, sub_admin_id, currency_code, commission_total, adjustment_amount, payout_amount, payment_method, payment_reference, payment_note, status, paid_at, paid_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$batchId, $agentId, $currency, $commissionTotal, 0.0, $commissionTotal, 'Manual Agent Payment', $batchCode, $note, 'Paid', $paidAt, $uid]);
        $itemId = (int)$pdo->lastInsertId();

        foreach (array_chunk($commissionIds, 200) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $update = $pdo->prepare("UPDATE sub_admin_commissions SET payout_item_id=?, payout_paid_at=? WHERE id IN ($placeholders) AND payout_paid_at IS NULL");
            $update->execute(array_merge([$itemId, $paidAt], $chunk));
        }
        if ($currency === 'GHS') {
            $pdo->prepare("UPDATE sub_admins SET balance=GREATEST(COALESCE(balance,0) - ?, 0) WHERE id=?")
                ->execute([$commissionTotal, $agentId]);
        }
        ps_subadmin_update_currency_balance($pdo, $agentId, $currency, 0, 0, -1 * $commissionTotal);
        $pdo->commit();

        echo json_encode([
            'success'=>true,
            'message'=>'Daily commission marked paid for ' . $date,
            'paid_amount'=>$commissionTotal,
            'currency_code'=>$currency,
            'batch_code'=>$batchCode,
        ]);
    } catch(Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success'=>false, 'message'=>$e->getMessage()]);
    }
    break;

default:
    echo json_encode(['success'=>false,'message'=>'Unknown action']);
}
?>
