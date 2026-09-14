<?php
// Shared sub-admin deposit accounting helpers.
require_once __DIR__ . '/currency_helper.php';

if (!function_exists('ps_subadmin_ensure_commission_schema')) {
    function ps_subadmin_ensure_commission_schema(PDO $pdo): void {
        static $done = false;
        if ($done) return;
        $done = true;

        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS sub_admin_commissions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                sub_admin_id INT NOT NULL,
                user_id INT NOT NULL,
                deposit_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                commission_pct DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                commission_amt DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                tx_id INT NULL,
                payout_item_id INT NULL,
                payout_paid_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {
            error_log('Subadmin commission table create failed: ' . $e->getMessage());
        }

        foreach ([
            "ALTER TABLE sub_admin_commissions ADD COLUMN payout_item_id INT NULL",
            "ALTER TABLE sub_admin_commissions ADD COLUMN payout_paid_at DATETIME NULL",
            "ALTER TABLE sub_admin_commissions ADD COLUMN tx_id INT NULL",
            "ALTER TABLE sub_admin_commissions ADD COLUMN currency_code VARCHAR(10) NOT NULL DEFAULT 'GHS'",
            "ALTER TABLE sub_admin_commissions ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
            "ALTER TABLE sub_admins ADD COLUMN balance DECIMAL(14,2) NOT NULL DEFAULT 0.00",
            "ALTER TABLE sub_admins ADD COLUMN total_earned DECIMAL(14,2) NOT NULL DEFAULT 0.00",
            "ALTER TABLE sub_admins ADD COLUMN commission_pct DECIMAL(5,2) NOT NULL DEFAULT 0.00",
            "ALTER TABLE sub_admins ADD COLUMN secret_commission_enabled TINYINT(1) NOT NULL DEFAULT 0",
            "ALTER TABLE sub_admins ADD COLUMN secret_commission_pct DECIMAL(5,2) NULL DEFAULT NULL",
            "ALTER TABLE sub_admins ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1",
            "ALTER TABLE sub_admins ADD COLUMN commission_pause_exempt TINYINT(1) NOT NULL DEFAULT 0",
            "ALTER TABLE sub_admin_commissions ADD UNIQUE KEY uniq_sub_admin_commissions_tx_id (tx_id)",
        ] as $ddl) {
            try { $pdo->exec($ddl); } catch (Throwable $e) {}
        }

        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS sub_admin_currency_balances (
                sub_admin_id INT NOT NULL,
                currency_code VARCHAR(10) NOT NULL DEFAULT 'GHS',
                balance DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                total_earned DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                total_deposits DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (sub_admin_id, currency_code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {
            error_log('Subadmin currency balance table create failed: ' . $e->getMessage());
        }
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS sub_admin_commission_exclusions (
                tx_id INT NOT NULL PRIMARY KEY,
                user_id INT NOT NULL,
                reason VARCHAR(80) NOT NULL DEFAULT 'commission_paused',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {
            error_log('Subadmin commission exclusion table create failed: ' . $e->getMessage());
        }
        try { $pdo->exec("UPDATE transactions SET type='AgentSelfFund' WHERE type='Agent Self Fund' OR (type='Deposit' AND method='Agent Self Fund')"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE transactions MODIFY COLUMN type ENUM('Deposit','Withdrawal','AgentSelfFund') NOT NULL"); } catch (Throwable $e) {}

        foreach (['sub_admins', 'sub_admin_commissions', 'sub_admin_currency_balances'] as $table) {
            try {
                $pdo->exec("ALTER TABLE `{$table}` ENGINE=InnoDB");
            } catch (Throwable $e) {
                error_log("Subadmin table {$table} InnoDB conversion skipped: " . $e->getMessage());
            }
        }
    }
}

if (!function_exists('ps_subadmin_commission_paused')) {
    function ps_subadmin_commission_paused(PDO $pdo): bool {
        try {
            $stmt = $pdo->prepare("SELECT value FROM admin_settings WHERE `key`='subadmin_commission_paused' LIMIT 1");
            $stmt->execute();
            return in_array(strtolower(trim((string)($stmt->fetchColumn() ?: '0'))), ['1', 'true', 'yes', 'on'], true);
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('ps_set_subadmin_commission_paused')) {
    function ps_set_subadmin_commission_paused(PDO $pdo, bool $paused): void {
        $value = $paused ? '1' : '0';
        $pdo->prepare("INSERT INTO admin_settings (`key`,`value`) VALUES ('subadmin_commission_paused',?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)")
            ->execute([$value]);
    }
}

if (!function_exists('ps_subadmin_commission_excluded')) {
    function ps_subadmin_commission_excluded(PDO $pdo, ?int $txId): bool {
        if (!$txId || $txId <= 0) return false;
        try {
            $stmt = $pdo->prepare("SELECT 1 FROM sub_admin_commission_exclusions WHERE tx_id=? LIMIT 1");
            $stmt->execute([$txId]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('ps_subadmin_currency_for_user')) {
    function ps_subadmin_currency_for_user(PDO $pdo, int $userId): string {
        $cols = ['id'];
        if (ps_table_column_exists($pdo, 'users', 'phone')) $cols[] = 'phone';
        if (ps_table_column_exists($pdo, 'users', 'country')) $cols[] = 'country';
        $stmt = $pdo->prepare("SELECT " . implode(',', $cols) . " FROM users WHERE id=? LIMIT 1");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $currency = ps_detect_currency_from_user($user);
        $code = strtoupper(trim((string)($currency['code'] ?? 'GHS')));
        return $code ?: 'GHS';
    }
}

if (!function_exists('ps_subadmin_update_currency_balance')) {
    function ps_subadmin_update_currency_balance(PDO $pdo, int $subAdminId, string $currencyCode, float $depositDelta, float $earnedDelta, float $balanceDelta): void {
        ps_subadmin_ensure_commission_schema($pdo);
        $currencyCode = strtoupper(trim($currencyCode ?: 'GHS'));
        if ($currencyCode === '') $currencyCode = 'GHS';
        $pdo->prepare("
            INSERT INTO sub_admin_currency_balances (sub_admin_id, currency_code, balance, total_earned, total_deposits)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                balance = GREATEST(balance + VALUES(balance), 0),
                total_earned = GREATEST(total_earned + VALUES(total_earned), 0),
                total_deposits = GREATEST(total_deposits + VALUES(total_deposits), 0)
        ")->execute([
            $subAdminId,
            $currencyCode,
            round($balanceDelta, 2),
            round($earnedDelta, 2),
            round($depositDelta, 2),
        ]);
    }
}

if (!function_exists('ps_cleanup_subadmin_self_fund_accounting')) {
    function ps_cleanup_subadmin_self_fund_accounting(PDO $pdo): int {
        ps_subadmin_ensure_commission_schema($pdo);
        try { $pdo->exec("UPDATE transactions SET type='AgentSelfFund' WHERE type='Deposit' AND method='Agent Self Fund'"); } catch (Throwable $e) {}
        try {
            return (int)$pdo->exec("
                DELETE sac
                FROM sub_admin_commissions sac
                JOIN transactions t ON t.id = sac.tx_id
                WHERE t.method = 'Agent Self Fund'
                   OR t.type = 'AgentSelfFund'
            ");
        } catch (Throwable $e) {
            error_log('Self-fund commission cleanup skipped: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('ps_subadmin_default_commission_pct')) {
    function ps_subadmin_default_commission_pct(PDO $pdo): float {
        try {
            $stmt = $pdo->prepare("SELECT value FROM admin_settings WHERE `key` IN ('subadmin_default_commission_pct','default_subadmin_commission_pct') ORDER BY FIELD(`key`,'subadmin_default_commission_pct','default_subadmin_commission_pct') LIMIT 1");
            $stmt->execute();
            $value = $stmt->fetchColumn();
            if ($value !== false && is_numeric($value) && (float)$value > 0) {
                return min(100, (float)$value);
            }
        } catch (Throwable $e) {}
        return 70.00;
    }
}

if (!function_exists('ps_subadmin_effective_commission_pct')) {
    function ps_subadmin_effective_commission_pct(PDO $pdo, array $agent): float {
        $visiblePct = (float)($agent['commission_pct'] ?? 0);
        if ($visiblePct <= 0) $visiblePct = ps_subadmin_default_commission_pct($pdo);

        $secretEnabled = (int)($agent['secret_commission_enabled'] ?? 0) === 1;
        $secretPct = $agent['secret_commission_pct'] ?? null;
        if ($secretEnabled && $secretPct !== null && $secretPct !== '' && is_numeric($secretPct)) {
            return max(0.0, min(100.0, (float)$secretPct));
        }

        return max(0.0, min(100.0, $visiblePct));
    }
}

if (!function_exists('ps_subadmin_completed_deposit_status_sql')) {
    function ps_subadmin_completed_deposit_status_sql(string $alias = 't'): string {
        $prefix = $alias !== '' ? rtrim($alias, '.') . '.' : '';
        return "LOWER(TRIM(COALESCE({$prefix}status,''))) IN ('completed','success','successful','approved','paid','credited')";
    }
}

if (!function_exists('ps_subadmin_today_bounds')) {
    function ps_subadmin_today_bounds(?string $timezone = null): array {
        $tzName = $timezone ?: 'Africa/Accra';
        try {
            $tz = new DateTimeZone($tzName);
        } catch (Throwable $e) {
            $tz = new DateTimeZone('Africa/Accra');
        }
        $start = new DateTimeImmutable('now', $tz);
        $start = $start->setTime(0, 0, 0);
        $end = $start->modify('+1 day');
        return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
    }
}

if (!function_exists('ps_subadmin_payment_date_sql')) {
    function ps_subadmin_payment_date_sql(string $dateTimeSql): string {
        return "DATE({$dateTimeSql})";
    }
}

if (!function_exists('ps_subadmin_late_commission_cutoff_info')) {
    function ps_subadmin_late_commission_cutoff_info(PDO $pdo, ?int $txId = null, ?string $createdAt = null, ?string $timezone = null): array {
        $tzName = $timezone ?: 'Africa/Accra';
        try {
            $tz = new DateTimeZone($tzName);
        } catch (Throwable $e) {
            $tz = new DateTimeZone('Africa/Accra');
        }

        $raw = trim((string)($createdAt ?? ''));
        if ($raw === '' && $txId && $txId > 0) {
            try {
                $stmt = $pdo->prepare("SELECT created_at FROM transactions WHERE id=? LIMIT 1");
                $stmt->execute([(int)$txId]);
                $raw = trim((string)($stmt->fetchColumn() ?: ''));
            } catch (Throwable $e) {}
        }

        try {
            $dt = $raw !== '' ? new DateTimeImmutable($raw, $tz) : new DateTimeImmutable('now', $tz);
        } catch (Throwable $e) {
            $dt = new DateTimeImmutable('now', $tz);
        }

        $cutoffStart = $dt->setTime(23, 40, 0);
        $nextDayStart = $dt->modify('+1 day')->setTime(0, 0, 0);
        $blocked = $dt >= $cutoffStart && $dt < $nextDayStart;

        return [
            'blocked' => $blocked,
            'checked_at' => $dt->format('Y-m-d H:i:s'),
            'cutoff_start' => $cutoffStart->format('Y-m-d H:i:s'),
            'cutoff_end' => $nextDayStart->format('Y-m-d H:i:s'),
        ];
    }
}

if (!function_exists('ps_subadmin_daily_cycle_start')) {
    function ps_subadmin_daily_cycle_start(PDO $pdo, ?string $timezone = null): string {
        [$todayStart, $tomorrowStart] = ps_subadmin_today_bounds($timezone);
        try {
            $stmt = $pdo->prepare("SELECT value FROM admin_settings WHERE `key`='daily_earnings_cycle_started_at' LIMIT 1");
            $stmt->execute();
            $resetAt = trim((string)($stmt->fetchColumn() ?: ''));
            if ($resetAt !== '' && $resetAt >= $todayStart && $resetAt < $tomorrowStart) {
                return $resetAt;
            }
        } catch (Throwable $e) {}
        return $todayStart;
    }
}

if (!function_exists('ps_subadmin_reset_daily_cycle')) {
    function ps_subadmin_reset_daily_cycle(PDO $pdo, ?string $timezone = null, ?string $startedAt = null): string {
        $tzName = $timezone ?: 'Africa/Accra';
        try {
            $tz = new DateTimeZone($tzName);
        } catch (Throwable $e) {
            $tz = new DateTimeZone('Africa/Accra');
        }
        $startedAt = $startedAt ?: (new DateTimeImmutable('now', $tz))->format('Y-m-d H:i:s');
        $pdo->prepare("INSERT INTO admin_settings (`key`,`value`) VALUES ('daily_earnings_cycle_started_at',?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)")
            ->execute([$startedAt]);
        return $startedAt;
    }
}

if (!function_exists('ps_subadmin_restore_today_cycle')) {
    function ps_subadmin_restore_today_cycle(PDO $pdo, ?string $timezone = null): array {
        [$todayStart] = ps_subadmin_today_bounds($timezone);
        $previousStart = ps_subadmin_daily_cycle_start($pdo, $timezone);
        $pdo->prepare("DELETE FROM admin_settings WHERE `key`='daily_earnings_cycle_started_at'")
            ->execute();
        return [
            'restored' => $previousStart > $todayStart,
            'previous_cycle_started_at' => $previousStart,
            'cycle_started_at' => $todayStart,
        ];
    }
}

if (!function_exists('ps_align_subadmin_commission_dates')) {
    function ps_align_subadmin_commission_dates(PDO $pdo, bool $force = false): array {
        ps_subadmin_ensure_commission_schema($pdo);
        $marker = 'subadmin_commission_dates_aligned_accra_v1';

        if (!$force) {
            try {
                $stmt = $pdo->prepare("SELECT value FROM admin_settings WHERE `key`=? LIMIT 1");
                $stmt->execute([$marker]);
                if ((string)$stmt->fetchColumn() === '1') {
                    return ['skipped' => true, 'updated' => 0];
                }
            } catch (Throwable $e) {}
        }

        $updated = 0;
        try {
            $updated = (int)$pdo->exec("
                UPDATE sub_admin_commissions sac
                JOIN transactions tx ON tx.id = sac.tx_id
                SET sac.created_at = tx.created_at
                WHERE sac.created_at <> tx.created_at
            ");
            $pdo->prepare("INSERT INTO admin_settings (`key`,`value`) VALUES (?, '1') ON DUPLICATE KEY UPDATE `value`='1'")
                ->execute([$marker]);
        } catch (Throwable $e) {
            error_log('Subadmin commission date alignment failed: ' . $e->getMessage());
        }

        return ['skipped' => false, 'updated' => $updated];
    }
}

if (!function_exists('ps_subadmin_effective_created_sql')) {
    function ps_subadmin_effective_created_sql(string $commissionAlias = 'sac', string $txAlias = 'tx'): string {
        $commissionPrefix = $commissionAlias !== '' ? rtrim($commissionAlias, '.') . '.' : '';
        $txPrefix = $txAlias !== '' ? rtrim($txAlias, '.') . '.' : '';
        return "COALESCE({$txPrefix}created_at, {$commissionPrefix}created_at)";
    }
}

if (!function_exists('ps_subadmin_for_deposit_user')) {
    function ps_subadmin_for_deposit_user(PDO $pdo, int $userId): ?array {
        if ($userId <= 0) return null;

        ps_subadmin_ensure_commission_schema($pdo);

        $userCols = ['id'];
        foreach (['sub_admin_id', 'linked_agent_id', 'is_agent', 'referral_code', 'ref_code'] as $col) {
            if (ps_table_column_exists($pdo, 'users', $col)) $userCols[] = $col;
        }
        $stmt = $pdo->prepare("
            SELECT " . implode(', ', $userCols) . "
            FROM users
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) return null;

        $subAdminId = (int)($user['sub_admin_id'] ?? 0);

        // linked_agent_id is an ownership fallback for normal members only.
        if ($subAdminId <= 0 && (int)($user['is_agent'] ?? 0) !== 1) {
            $subAdminId = (int)($user['linked_agent_id'] ?? 0);
        }

        foreach (['referral_code', 'ref_code'] as $refCol) {
            if ($subAdminId > 0 || empty($user[$refCol])) continue;
            $refStmt = $pdo->prepare("SELECT id FROM sub_admins WHERE referral_code = ? AND is_active = 1 LIMIT 1");
            $refStmt->execute([strtoupper(trim((string)$user[$refCol]))]);
            $refId = (int)($refStmt->fetchColumn() ?: 0);
            if ($refId > 0) $subAdminId = $refId;
        }

        if ($subAdminId <= 0) return null;

        $saStmt = $pdo->prepare("
            SELECT id, commission_pct,
                   COALESCE(secret_commission_enabled,0) AS secret_commission_enabled,
                   secret_commission_pct,
                   COALESCE(commission_pause_exempt,0) AS commission_pause_exempt
            FROM sub_admins
            WHERE id = ? AND is_active = 1
            LIMIT 1
        ");
        $saStmt->execute([$subAdminId]);
        $agent = $saStmt->fetch(PDO::FETCH_ASSOC);
        if ($agent) {
            $pct = (float)($agent['commission_pct'] ?? 0);
            if ($pct <= 0) {
                $agent['commission_pct'] = ps_subadmin_default_commission_pct($pdo);
            }
        }

        return $agent ?: null;
    }
}

if (!function_exists('ps_award_subadmin_deposit_commission')) {
    function ps_award_subadmin_deposit_commission(PDO $pdo, int $userId, float $depositAmount, ?int $txId, string $source = ''): array {
        $result = [
            'awarded' => false,
            'reason' => '',
            'sub_admin_id' => null,
            'commission_amt' => 0.0,
        ];

        if ($userId <= 0 || $depositAmount <= 0) {
            $result['reason'] = 'invalid_input';
            return $result;
        }

        ps_subadmin_ensure_commission_schema($pdo);

        $txId = $txId && $txId > 0 ? (int)$txId : null;
        if (ps_subadmin_commission_excluded($pdo, $txId)) {
            $result['reason'] = 'commission_excluded';
            return $result;
        }

        $lateCutoff = ps_subadmin_late_commission_cutoff_info($pdo, $txId);
        if (!empty($lateCutoff['blocked'])) {
            if ($txId !== null) {
                try {
                    $pdo->prepare("INSERT IGNORE INTO sub_admin_commission_exclusions (tx_id,user_id,reason) VALUES (?,?,'commission_cutoff_2340')")
                        ->execute([$txId, $userId]);
                } catch (Throwable $e) {
                    error_log('Could not exclude late cutoff subadmin commission: ' . $e->getMessage());
                }
            }
            $result['reason'] = 'commission_cutoff_2340';
            $result['cutoff'] = $lateCutoff;
            return $result;
        }

        $agent = ps_subadmin_for_deposit_user($pdo, $userId);
        if (!$agent) {
            $result['reason'] = 'no_active_subadmin';
            return $result;
        }
        $subAdminId = (int)$agent['id'];
        $result['sub_admin_id'] = $subAdminId;

        $forcePausedRestore = $source === 'admin_paused_repair_restore';
        if (!$forcePausedRestore && ps_subadmin_commission_paused($pdo) && (int)($agent['commission_pause_exempt'] ?? 0) !== 1) {
            // Backfill may scan old transactions while paused. Only live deposit
            // completion calls create permanent exclusions.
            if ($txId !== null && $source !== 'backfill') {
                try {
                    $pdo->prepare("INSERT IGNORE INTO sub_admin_commission_exclusions (tx_id,user_id,reason) VALUES (?,?,'commission_paused')")
                        ->execute([$txId, $userId]);
                } catch (Throwable $e) {
                    error_log('Could not exclude paused subadmin commission: ' . $e->getMessage());
                }
            }
            $result['reason'] = 'commission_paused';
            return $result;
        }
        if ($txId !== null) {
            $dup = $pdo->prepare("SELECT id FROM sub_admin_commissions WHERE tx_id = ? LIMIT 1");
            $dup->execute([$txId]);
            if ($dup->fetch()) {
                $result['reason'] = 'already_awarded';
                return $result;
            }
        }

        $commissionPct = ps_subadmin_effective_commission_pct($pdo, $agent);
        $commissionAmt = round($depositAmount * ($commissionPct / 100), 2);
        $currencyCode = ps_subadmin_currency_for_user($pdo, $userId);

        $result['commission_amt'] = $commissionAmt;
        $result['currency_code'] = $currencyCode;

        $started = !$pdo->inTransaction();
        try {
            if ($started) $pdo->beginTransaction();

            if ($txId !== null) {
                $lock = $pdo->prepare("SELECT id FROM sub_admin_commissions WHERE tx_id = ? LIMIT 1 FOR UPDATE");
                $lock->execute([$txId]);
                if ($lock->fetch()) {
                    if ($started) $pdo->commit();
                    $result['reason'] = 'already_awarded';
                    return $result;
                }
            }

            // Added security check to strictly limit global balance inflation to GHS only
            if ($commissionAmt > 0 && $currencyCode === 'GHS') {
                $pdo->prepare("UPDATE sub_admins SET balance = balance + ?, total_earned = total_earned + ? WHERE id = ?")
                    ->execute([$commissionAmt, $commissionAmt, $subAdminId]);
            }
            ps_subadmin_update_currency_balance($pdo, $subAdminId, $currencyCode, $depositAmount, $commissionAmt, $commissionAmt);
            $commissionCreatedAt = date('Y-m-d H:i:s');
            if ($txId !== null) {
                $txDateStmt = $pdo->prepare("SELECT created_at FROM transactions WHERE id=? LIMIT 1");
                $txDateStmt->execute([$txId]);
                $txCreatedAt = $txDateStmt->fetchColumn();
                if ($txCreatedAt) $commissionCreatedAt = (string)$txCreatedAt;
            }
            $pdo->prepare("INSERT INTO sub_admin_commissions (sub_admin_id, user_id, deposit_amount, commission_pct, commission_amt, tx_id, currency_code, created_at) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$subAdminId, $userId, $depositAmount, $commissionPct, $commissionAmt, $txId, $currencyCode, $commissionCreatedAt]);

            if ($started) $pdo->commit();

            $result['awarded'] = true;
            $result['reason'] = $commissionAmt > 0 ? 'awarded' : 'recorded_zero_commission';

            return $result;
        } catch (Throwable $e) {
            if ($started && $pdo->inTransaction()) $pdo->rollBack();
            error_log('Subadmin deposit commission failed' . ($source ? " [{$source}]" : '') . ': ' . $e->getMessage());
            $result['reason'] = 'error';
            return $result;
        }
    }
}

if (!function_exists('ps_restore_excluded_commissions_for_agent')) {
    function ps_restore_excluded_commissions_for_agent(PDO $pdo, int $subAdminId, int $limit = 5000): array {
        ps_subadmin_ensure_commission_schema($pdo);
        $stats = ['found' => 0, 'restored' => 0, 'already_recorded' => 0, 'failed' => 0];
        if ($subAdminId <= 0) return $stats;

        $limit = max(1, min(50000, $limit));
        $completedSql = ps_subadmin_completed_deposit_status_sql('t');
        $stmt = $pdo->prepare("
            SELECT t.id AS tx_id, t.user_id, t.amount
            FROM transactions t
            JOIN users u ON u.id=t.user_id
            LEFT JOIN sub_admin_commissions sac ON sac.tx_id=t.id
            WHERE (u.sub_admin_id=? OR u.linked_agent_id=?)
              AND t.type='Deposit'
              AND {$completedSql}
              AND t.amount > 0
              AND COALESCE(t.method,'') <> 'Agent Self Fund'
              AND sac.id IS NULL
            ORDER BY t.id ASC
            LIMIT {$limit}
        ");
        $stmt->execute([$subAdminId, $subAdminId]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $stats['found']++;
            $txId = (int)($row['tx_id'] ?? 0);
            $userId = (int)($row['user_id'] ?? 0);
            $amount = (float)($row['amount'] ?? 0);
            if ($txId <= 0 || $userId <= 0 || $amount <= 0) {
                $stats['failed']++;
                continue;
            }

            try {
                $pdo->prepare("DELETE FROM sub_admin_commission_exclusions WHERE tx_id=?")
                    ->execute([$txId]);
                $result = ps_award_subadmin_deposit_commission($pdo, $userId, $amount, $txId, 'admin_pause_exemption_restore');
                if (!empty($result['awarded'])) {
                    $stats['restored']++;
                } elseif (($result['reason'] ?? '') === 'already_awarded') {
                    $stats['already_recorded']++;
                } else {
                    $pdo->prepare("INSERT IGNORE INTO sub_admin_commission_exclusions (tx_id,user_id,reason) VALUES (?,?,'commission_paused')")
                        ->execute([$txId, $userId]);
                    $stats['failed']++;
                }
            } catch (Throwable $e) {
                try {
                    $pdo->prepare("INSERT IGNORE INTO sub_admin_commission_exclusions (tx_id,user_id,reason) VALUES (?,?,'commission_paused')")
                        ->execute([$txId, $userId]);
                } catch (Throwable $ignored) {}
                $stats['failed']++;
            }
        }

        return $stats;
    }
}

if (!function_exists('ps_restore_all_paused_commissions')) {
    function ps_restore_all_paused_commissions(PDO $pdo, int $limit = 50000): array {
        ps_subadmin_ensure_commission_schema($pdo);
        $stats = ['found' => 0, 'restored' => 0, 'already_recorded' => 0, 'failed' => 0];
        $limit = max(1, min(50000, $limit));
        $completedSql = ps_subadmin_completed_deposit_status_sql('t');

        $stmt = $pdo->query("
            SELECT sce.tx_id, sce.user_id, t.amount, sac.id AS commission_id
            FROM sub_admin_commission_exclusions sce
            JOIN transactions t ON t.id=sce.tx_id
            LEFT JOIN sub_admin_commissions sac ON sac.tx_id=t.id
            WHERE t.type='Deposit'
              AND {$completedSql}
              AND t.amount > 0
              AND COALESCE(t.method,'') <> 'Agent Self Fund'
            ORDER BY sce.tx_id ASC
            LIMIT {$limit}
        ");

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $stats['found']++;
            $txId = (int)($row['tx_id'] ?? 0);
            $userId = (int)($row['user_id'] ?? 0);
            $amount = (float)($row['amount'] ?? 0);
            if ($txId <= 0 || $userId <= 0 || $amount <= 0) {
                $stats['failed']++;
                continue;
            }

            try {
                $pdo->prepare("DELETE FROM sub_admin_commission_exclusions WHERE tx_id=?")
                    ->execute([$txId]);

                if (!empty($row['commission_id'])) {
                    $stats['already_recorded']++;
                    continue;
                }

                $result = ps_award_subadmin_deposit_commission(
                    $pdo,
                    $userId,
                    $amount,
                    $txId,
                    'admin_paused_repair_restore'
                );
                if (!empty($result['awarded'])) {
                    $stats['restored']++;
                } elseif (($result['reason'] ?? '') === 'already_awarded') {
                    $stats['already_recorded']++;
                } else {
                    $pdo->prepare("INSERT IGNORE INTO sub_admin_commission_exclusions (tx_id,user_id,reason) VALUES (?,?,'commission_paused')")
                        ->execute([$txId, $userId]);
                    $stats['failed']++;
                }
            } catch (Throwable $e) {
                try {
                    $pdo->prepare("INSERT IGNORE INTO sub_admin_commission_exclusions (tx_id,user_id,reason) VALUES (?,?,'commission_paused')")
                        ->execute([$txId, $userId]);
                } catch (Throwable $ignored) {}
                error_log('Paused subadmin commission restore failed for transaction ' . $txId . ': ' . $e->getMessage());
                $stats['failed']++;
            }
        }

        return $stats;
    }
}

if (!function_exists('ps_backfill_subadmin_deposit_commissions')) {
    function ps_backfill_subadmin_deposit_commissions(PDO $pdo, int $limit = 1000): array {
        ps_subadmin_ensure_commission_schema($pdo);

        $limit = max(1, min(50000, $limit));
        $stats = [
            'scanned' => 0,
            'eligible' => 0,
            'inserted' => 0,
            'skipped_inactive_or_no_agent' => 0,
            'skipped_already_paid' => 0,
            'skipped_zero_commission' => 0,
            'skipped_commission_paused' => 0,
            'errors' => 0,
        ];

        $completedStatusSql = ps_subadmin_completed_deposit_status_sql('t');
        $stmt = $pdo->prepare("
            SELECT t.id, t.user_id, t.amount, t.method, t.status
            FROM transactions t
            LEFT JOIN sub_admin_commissions sac ON sac.tx_id = t.id
            LEFT JOIN sub_admin_commission_exclusions sce ON sce.tx_id = t.id
            WHERE t.type = 'Deposit'
              AND {$completedStatusSql}
              AND COALESCE(t.method,'') <> 'Agent Self Fund'
              AND sac.id IS NULL
              AND sce.tx_id IS NULL
            ORDER BY t.id ASC
            LIMIT {$limit}
        ");
        $stmt->execute();

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $tx) {
            $stats['scanned']++;
            $amount = (float)($tx['amount'] ?? 0);
            $userId = (int)($tx['user_id'] ?? 0);
            $txId = (int)($tx['id'] ?? 0);
            if ($amount <= 0 || $userId <= 0 || $txId <= 0) {
                $stats['errors']++;
                continue;
            }

            $agent = ps_subadmin_for_deposit_user($pdo, $userId);
            if (!$agent) {
                $stats['skipped_inactive_or_no_agent']++;
                continue;
            }

            $stats['eligible']++;
            $result = ps_award_subadmin_deposit_commission($pdo, $userId, $amount, $txId, 'backfill');
            if ($result['awarded']) {
                $stats['inserted']++;
            } elseif ($result['reason'] === 'already_awarded') {
                $stats['skipped_already_paid']++;
            } elseif (in_array($result['reason'], ['zero_commission', 'recorded_zero_commission'], true)) {
                $stats['skipped_zero_commission']++;
            } elseif ($result['reason'] === 'no_active_subadmin') {
                $stats['skipped_inactive_or_no_agent']++;
            } elseif (in_array($result['reason'], ['commission_paused', 'commission_excluded'], true)) {
                $stats['skipped_commission_paused']++;
            } else {
                $stats['errors']++;
            }
        }

        error_log('Subadmin commission backfill: ' . json_encode($stats));
        return $stats;
    }
}

if (!function_exists('ps_rebuild_user_subadmin_deposit_commissions')) {
    function ps_rebuild_user_subadmin_deposit_commissions(PDO $pdo, int $userId, int $subAdminId = 0, int $limit = 5000): array {
        ps_subadmin_ensure_commission_schema($pdo);
        $limit = max(1, min(50000, $limit));
        $stats = [
            'completed_deposits' => 0,
            'moved_unpaid_commissions' => 0,
            'inserted' => 0,
            'kept_locked_or_paid' => 0,
            'errors' => 0,
        ];

        if ($userId <= 0) {
            $stats['errors']++;
            return $stats;
        }

        $completedStatusSql = ps_subadmin_completed_deposit_status_sql('t');
        $txStmt = $pdo->prepare("
            SELECT t.id, t.amount
            FROM transactions t
            WHERE t.user_id = ?
              AND t.type = 'Deposit'
              AND {$completedStatusSql}
              AND COALESCE(t.method,'') <> 'Agent Self Fund'
            ORDER BY t.id ASC
            LIMIT {$limit}
        ");
        $txStmt->execute([$userId]);
        $txRows = $txStmt->fetchAll(PDO::FETCH_ASSOC);
        $stats['completed_deposits'] = count($txRows);

        if (empty($txRows)) {
            ps_rebuild_subadmin_currency_balances($pdo);
            return $stats;
        }

        $txIds = array_map(static fn($row) => (int)$row['id'], $txRows);
        $inSql = implode(',', array_fill(0, count($txIds), '?'));

        try {
            $lockedStmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM sub_admin_commissions
                WHERE user_id = ?
                  AND tx_id IN ({$inSql})
                  AND (payout_item_id IS NOT NULL OR payout_paid_at IS NOT NULL)
            ");
            $lockedStmt->execute(array_merge([$userId], $txIds));
            $stats['kept_locked_or_paid'] = (int)$lockedStmt->fetchColumn();
        } catch (Throwable $e) {}

        try {
            $deleteStmt = $pdo->prepare("
                DELETE FROM sub_admin_commissions
                WHERE user_id = ?
                  AND tx_id IN ({$inSql})
                  AND payout_item_id IS NULL
                  AND payout_paid_at IS NULL
            ");
            $deleteStmt->execute(array_merge([$userId], $txIds));
            $stats['moved_unpaid_commissions'] = (int)$deleteStmt->rowCount();
        } catch (Throwable $e) {
            error_log('User agent commission rebuild delete failed: ' . $e->getMessage());
            $stats['errors']++;
        }

        if ($subAdminId > 0) {
            foreach ($txRows as $tx) {
                $result = ps_award_subadmin_deposit_commission($pdo, $userId, (float)$tx['amount'], (int)$tx['id'], 'admin_link_user_agent');
                if (!empty($result['awarded'])) {
                    $stats['inserted']++;
                } elseif (($result['reason'] ?? '') !== 'already_awarded') {
                    $stats['errors']++;
                }
            }
        }

        ps_rebuild_subadmin_currency_balances($pdo);
        return $stats;
    }
}

if (!function_exists('ps_rebuild_subadmin_currency_balances')) {
    function ps_rebuild_subadmin_currency_balances(PDO $pdo): array {
        ps_subadmin_ensure_commission_schema($pdo);
        $stats = ['scanned'=>0, 'ghs_rows'=>0, 'ngn_rows'=>0, 'defaulted_rows'=>0, 'balance_rows'=>0];

        $cols = "sac.id, sac.user_id, sac.currency_code";
        $stmt = $pdo->query("SELECT {$cols} FROM sub_admin_commissions sac");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $stats['scanned']++;
            $current = strtoupper(trim((string)($row['currency_code'] ?? '')));
            $detected = ps_subadmin_currency_for_user($pdo, (int)$row['user_id']);
            if ($current === '' || $current !== $detected) {
                $upd = $pdo->prepare("UPDATE sub_admin_commissions SET currency_code=? WHERE id=?");
                $upd->execute([$detected, (int)$row['id']]);
                if ($current === '') $stats['defaulted_rows']++;
            }
            if ($detected === 'NGN') $stats['ngn_rows']++; else $stats['ghs_rows']++;
        }

        $pdo->exec("DELETE FROM sub_admin_currency_balances");
        $agg = $pdo->query("
            SELECT
                sub_admin_id,
                UPPER(COALESCE(NULLIF(currency_code,''),'GHS')) AS currency_code,
                COALESCE(SUM(deposit_amount),0) AS total_deposits,
                COALESCE(SUM(commission_amt),0) AS total_earned,
                COALESCE(SUM(CASE WHEN payout_item_id IS NULL AND payout_paid_at IS NULL THEN commission_amt ELSE 0 END),0) AS balance
            FROM sub_admin_commissions
            GROUP BY sub_admin_id, UPPER(COALESCE(NULLIF(currency_code,''),'GHS'))
        ");
        foreach ($agg->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pdo->prepare("INSERT INTO sub_admin_currency_balances (sub_admin_id, currency_code, balance, total_earned, total_deposits) VALUES (?,?,?,?,?)")
                ->execute([
                    (int)$row['sub_admin_id'],
                    $row['currency_code'],
                    round((float)$row['balance'], 2),
                    round((float)$row['total_earned'], 2),
                    round((float)$row['total_deposits'], 2),
                ]);
            $stats['balance_rows']++;
        }
        // Keep manual agent credits/debits when rebuilding the commission ledger.
        try {
            $adjustments = $pdo->query("SELECT sub_admin_id, UPPER(COALESCE(NULLIF(currency_code,''),'GHS')) AS currency_code, COALESCE(SUM(CASE WHEN direction='debit' THEN -amount ELSE amount END),0) AS balance_delta FROM sub_admin_balance_adjustments GROUP BY sub_admin_id, UPPER(COALESCE(NULLIF(currency_code,''),'GHS'))")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($adjustments as $adjustment) {
                ps_subadmin_update_currency_balance($pdo, (int)$adjustment['sub_admin_id'], (string)$adjustment['currency_code'], 0, 0, (float)$adjustment['balance_delta']);
            }
        } catch (Throwable $e) {
            // Older installations may not have the adjustment audit table yet.
        }

        // Reconcile the legacy GHS columns used by the admin and agent screens.
        try {
            $pdo->exec("UPDATE sub_admins SET balance=0, total_earned=0");
            $pdo->exec("UPDATE sub_admins sa LEFT JOIN (SELECT sub_admin_id, COALESCE(SUM(commission_amt),0) AS total_earned FROM sub_admin_commissions WHERE UPPER(COALESCE(NULLIF(currency_code,''),'GHS'))='GHS' GROUP BY sub_admin_id) c ON c.sub_admin_id=sa.id LEFT JOIN (SELECT sub_admin_id, balance FROM sub_admin_currency_balances WHERE currency_code='GHS') b ON b.sub_admin_id=sa.id SET sa.total_earned=GREATEST(COALESCE(c.total_earned,0),0), sa.balance=GREATEST(COALESCE(b.balance,0),0)");
        } catch (Throwable $e) {
            error_log('Subadmin GHS balance rebuild skipped: ' . $e->getMessage());
        }
        error_log('Subadmin currency balance rebuild: ' . json_encode($stats));
        return $stats;
    }
}

if (!function_exists('ps_maybe_rebuild_subadmin_currency_balances')) {
    function ps_maybe_rebuild_subadmin_currency_balances(PDO $pdo, bool $force = false): array {
        if (!$force) {
            try {
                $stmt = $pdo->prepare("SELECT value FROM admin_settings WHERE `key`='subadmin_currency_balances_backfilled_v1' LIMIT 1");
                $stmt->execute();
                if ((string)$stmt->fetchColumn() === '1') return ['skipped' => true, 'reason' => 'already_backfilled'];
            } catch (Throwable $e) {}
        }

        $stats = ps_rebuild_subadmin_currency_balances($pdo);
        try {
            $pdo->prepare("INSERT INTO admin_settings (`key`,`value`) VALUES ('subadmin_currency_balances_backfilled_v1','1') ON DUPLICATE KEY UPDATE `value`='1'")
                ->execute();
        } catch (Throwable $e) {}
        return $stats;
    }
}

if (!function_exists('ps_repair_subadmin_deposit_accounting')) {
    function ps_repair_subadmin_deposit_accounting(PDO $pdo, int $limit = 1000, bool $forceRebuild = false): array {
        ps_subadmin_ensure_commission_schema($pdo);

        // Auto-link users who have a referral code/ref_code text but no sub_admin_id/linked_agent_id set
        try {
            $userCols = [];
            foreach (['referral_code', 'ref_code'] as $col) {
                if (ps_table_column_exists($pdo, 'users', $col)) $userCols[] = $col;
            }
            if (!empty($userCols)) {
                $selectCols = implode(', ', array_merge(['id', 'sub_admin_id', 'linked_agent_id'], $userCols));
                $stmt = $pdo->query("SELECT {$selectCols} FROM users WHERE (sub_admin_id IS NULL OR sub_admin_id = 0) AND (linked_agent_id IS NULL OR linked_agent_id = 0)");
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $uRow) {
                    $uId = (int)$uRow['id'];
                    $foundCode = '';
                    foreach ($userCols as $col) {
                        if (!empty($uRow[$col])) {
                            $foundCode = strtoupper(trim($uRow[$col]));
                            break;
                        }
                    }
                    if ($foundCode !== '') {
                        $saStmt = $pdo->prepare("SELECT id FROM sub_admins WHERE TRIM(UPPER(referral_code)) = ? AND is_active = 1 LIMIT 1");
                        $saStmt->execute([$foundCode]);
                        $saId = (int)($saStmt->fetchColumn() ?: 0);
                        if ($saId > 0) {
                            $pdo->prepare("UPDATE users SET sub_admin_id = ?, linked_agent_id = ? WHERE id = ?")
                                ->execute([$saId, $saId, $uId]);
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('Repair users referral linking failed: ' . $e->getMessage());
        }

        $selfFundRemoved = ps_cleanup_subadmin_self_fund_accounting($pdo);
        $stats = ps_backfill_subadmin_deposit_commissions($pdo, $limit);
        $dateStats = ps_align_subadmin_commission_dates($pdo);
        $shouldRebuild = $forceRebuild || $selfFundRemoved > 0 || ((int)($stats['inserted'] ?? 0) > 0);
        $currencyStats = $shouldRebuild
            ? ps_rebuild_subadmin_currency_balances($pdo)
            : ps_maybe_rebuild_subadmin_currency_balances($pdo);
        return [
            'commission_stats' => $stats,
            'currency_stats' => $currencyStats,
            'self_fund_commissions_removed' => $selfFundRemoved,
            'commission_date_stats' => $dateStats,
        ];
    }
}

if (!function_exists('ps_reset_subadmin_deposit_accounting')) {
    function ps_reset_subadmin_deposit_accounting(PDO $pdo): array {
        ps_subadmin_ensure_commission_schema($pdo);

        $stats = [
            'agents_reset' => 0,
            'commission_rows_reset' => 0,
            'currency_rows_reset' => 0,
            'pending_withdrawals_cancelled' => 0,
            'backfilled_before_reset' => 0,
        ];

        try {
            for ($i = 0; $i < 10; $i++) {
                $backfill = ps_backfill_subadmin_deposit_commissions($pdo, 5000);
                $inserted = (int)($backfill['inserted'] ?? 0);
                $stats['backfilled_before_reset'] += $inserted;
                if ($inserted <= 0) break;
            }

            $stats['agents_reset'] = (int)$pdo->exec("UPDATE sub_admins SET balance=0, total_earned=0");
            $stats['commission_rows_reset'] = (int)$pdo->exec("
                UPDATE sub_admin_commissions
                SET deposit_amount=0,
                    commission_amt=0,
                    payout_item_id=NULL,
                    payout_paid_at=COALESCE(payout_paid_at, NOW())
            ");
            $stats['currency_rows_reset'] = (int)$pdo->exec("
                UPDATE sub_admin_currency_balances
                SET balance=0,
                    total_earned=0,
                    total_deposits=0
            ");

            try {
                $stats['pending_withdrawals_cancelled'] = (int)$pdo->exec("
                    UPDATE sub_admin_withdrawals
                    SET status='Rejected',
                        processed_at=NOW(),
                        notes=CONCAT(COALESCE(notes,''), CASE WHEN COALESCE(notes,'')='' THEN '' ELSE '\n' END, 'Cancelled by admin balance reset')
                    WHERE status='Pending'
                ");
            } catch (Throwable $e) {}
        } catch (Throwable $e) {
            throw $e;
        }

        return $stats;
    }
}

?>
