<?php
// Shared user currency helpers.
// The site stores balances as plain numbers; these helpers only control labels shown to users.

if (!function_exists('ps_currency_map')) {
    function ps_currency_map(): array {
        return [
            '+1264' => ['code' => 'GHS', 'symbol' => 'GH₵', 'country' => 'Anguilla'],
            '+1268' => ['code' => 'GHS', 'symbol' => 'GH₵', 'country' => 'Antigua and Barbuda'],
            '+233'  => ['code' => 'GHS', 'symbol' => 'GH₵', 'country' => 'Ghana'],
            '+234'  => ['code' => 'NGN', 'symbol' => '₦',   'country' => 'Nigeria'],
            '+254'  => ['code' => 'KES', 'symbol' => 'KSh', 'country' => 'Kenya'],
            '+256'  => ['code' => 'UGX', 'symbol' => 'USh', 'country' => 'Uganda'],
            '+255'  => ['code' => 'TZS', 'symbol' => 'TSh', 'country' => 'Tanzania'],
            '+252'  => ['code' => 'SOS', 'symbol' => 'Sh',  'country' => 'Somalia'],
            '+251'  => ['code' => 'ETB', 'symbol' => 'Br',  'country' => 'Ethiopia'],
            '+250'  => ['code' => 'RWF', 'symbol' => 'FRw', 'country' => 'Rwanda'],
            '+249'  => ['code' => 'SDG', 'symbol' => 'ج.س.', 'country' => 'Sudan'],
            '+244'  => ['code' => 'AOA', 'symbol' => 'Kz',  'country' => 'Angola'],
            '+242'  => ['code' => 'XAF', 'symbol' => 'FCFA', 'country' => 'Congo'],
            '+241'  => ['code' => 'XAF', 'symbol' => 'FCFA', 'country' => 'Gabon'],
            '+240'  => ['code' => 'XAF', 'symbol' => 'FCFA', 'country' => 'Equatorial Guinea'],
            '+237'  => ['code' => 'XAF', 'symbol' => 'FCFA', 'country' => 'Cameroon'],
            '+236'  => ['code' => 'XAF', 'symbol' => 'FCFA', 'country' => 'Central African Republic'],
            '+235'  => ['code' => 'XAF', 'symbol' => 'FCFA', 'country' => 'Chad'],
            '+227'  => ['code' => 'XOF', 'symbol' => 'CFA', 'country' => 'Niger'],
            '+226'  => ['code' => 'XOF', 'symbol' => 'CFA', 'country' => 'Burkina Faso'],
            '+225'  => ['code' => 'XOF', 'symbol' => 'CFA', 'country' => 'Ivory Coast'],
            '+224'  => ['code' => 'GNF', 'symbol' => 'FG',  'country' => 'Guinea'],
            '+223'  => ['code' => 'XOF', 'symbol' => 'CFA', 'country' => 'Mali'],
            '+222'  => ['code' => 'MRU', 'symbol' => 'UM',  'country' => 'Mauritania'],
            '+221'  => ['code' => 'XOF', 'symbol' => 'CFA', 'country' => 'Senegal'],
            '+220'  => ['code' => 'GMD', 'symbol' => 'D',   'country' => 'Gambia'],
            '+216'  => ['code' => 'TND', 'symbol' => 'DT',  'country' => 'Tunisia'],
            '+213'  => ['code' => 'DZD', 'symbol' => 'DA',  'country' => 'Algeria'],
            '+212'  => ['code' => 'MAD', 'symbol' => 'MAD', 'country' => 'Morocco'],
            '+260'  => ['code' => 'ZMW', 'symbol' => 'ZK',  'country' => 'Zambia'],
            '+263'  => ['code' => 'ZWL', 'symbol' => 'Z$',  'country' => 'Zimbabwe'],
            '+264'  => ['code' => 'NAD', 'symbol' => 'N$',  'country' => 'Namibia'],
            '+265'  => ['code' => 'MWK', 'symbol' => 'MK',  'country' => 'Malawi'],
            '+266'  => ['code' => 'LSL', 'symbol' => 'L',   'country' => 'Lesotho'],
            '+267'  => ['code' => 'BWP', 'symbol' => 'P',   'country' => 'Botswana'],
            '+268'  => ['code' => 'SZL', 'symbol' => 'E',   'country' => 'Eswatini'],
            '+27'   => ['code' => 'ZAR', 'symbol' => 'R',   'country' => 'South Africa'],
            '+20'   => ['code' => 'EGP', 'symbol' => 'E£',  'country' => 'Egypt'],
            '+91'   => ['code' => 'INR', 'symbol' => '₹',   'country' => 'India'],
            '+92'   => ['code' => 'PKR', 'symbol' => '₨',   'country' => 'Pakistan'],
            '+880'  => ['code' => 'BDT', 'symbol' => '৳',   'country' => 'Bangladesh'],
            '+44'   => ['code' => 'GBP', 'symbol' => '£',   'country' => 'United Kingdom'],
            '+49'   => ['code' => 'EUR', 'symbol' => '€',   'country' => 'Germany'],
            '+33'   => ['code' => 'EUR', 'symbol' => '€',   'country' => 'France'],
            '+34'   => ['code' => 'EUR', 'symbol' => '€',   'country' => 'Spain'],
            '+39'   => ['code' => 'EUR', 'symbol' => '€',   'country' => 'Italy'],
            '+31'   => ['code' => 'EUR', 'symbol' => '€',   'country' => 'Netherlands'],
            '+1'    => ['code' => 'USD', 'symbol' => '$',   'country' => 'United States'],
            '+61'   => ['code' => 'AUD', 'symbol' => 'A$',  'country' => 'Australia'],
            '+64'   => ['code' => 'NZD', 'symbol' => 'NZ$', 'country' => 'New Zealand'],
            '+55'   => ['code' => 'BRL', 'symbol' => 'R$',  'country' => 'Brazil'],
            '+52'   => ['code' => 'MXN', 'symbol' => 'MX$', 'country' => 'Mexico'],
            '+971'  => ['code' => 'AED', 'symbol' => 'د.إ', 'country' => 'United Arab Emirates'],
            '+966'  => ['code' => 'SAR', 'symbol' => '﷼',   'country' => 'Saudi Arabia'],
            '+65'   => ['code' => 'SGD', 'symbol' => 'S$',  'country' => 'Singapore'],
            '+60'   => ['code' => 'MYR', 'symbol' => 'RM',  'country' => 'Malaysia'],
            '+62'   => ['code' => 'IDR', 'symbol' => 'Rp',  'country' => 'Indonesia'],
            '+63'   => ['code' => 'PHP', 'symbol' => '₱',   'country' => 'Philippines'],
            '+82'   => ['code' => 'KRW', 'symbol' => '₩',   'country' => 'South Korea'],
            '+81'   => ['code' => 'JPY', 'symbol' => '¥',   'country' => 'Japan'],
            '+86'   => ['code' => 'CNY', 'symbol' => '¥',   'country' => 'China'],
            '+7'    => ['code' => 'RUB', 'symbol' => '₽',   'country' => 'Russia'],
        ];
    }
}

if (!function_exists('ps_detect_currency_from_phone')) {
    function ps_detect_currency_from_phone(string $phone): array {
        $map = ps_currency_map();
        $p = preg_replace('/\s+/', '', trim($phone));
        if ($p !== '' && $p[0] !== '+') $p = '+' . $p;

        $prefixes = array_keys($map);
        usort($prefixes, fn($a, $b) => strlen($b) - strlen($a));
        foreach ($prefixes as $prefix) {
            if (strpos($p, $prefix) === 0) return $map[$prefix];
        }
        return ['code' => 'GHS', 'symbol' => 'GH₵', 'country' => 'Ghana'];
    }
}

if (!function_exists('ps_detect_currency_from_user')) {
    function ps_detect_currency_from_user(array $user): array {
        $detected = ps_detect_currency_from_phone((string)($user['phone'] ?? ''));
        if (!empty($user['country'])) {
            $country = strtolower(trim((string)$user['country']));
            if ($country === 'ng' || str_contains($country, 'nigeria')) return ['code' => 'NGN', 'symbol' => '₦', 'country' => 'Nigeria'];
            if ($country === 'gh' || str_contains($country, 'ghana')) return ['code' => 'GHS', 'symbol' => 'GH₵', 'country' => 'Ghana'];
            if ($country === 'ke' || str_contains($country, 'kenya')) return ['code' => 'KES', 'symbol' => 'KSh', 'country' => 'Kenya'];
            if ($country === 'za' || str_contains($country, 'south africa')) return ['code' => 'ZAR', 'symbol' => 'R', 'country' => 'South Africa'];
        }
        return $detected;
    }
}

if (!function_exists('ps_sync_currency_session')) {
    function ps_sync_currency_session(array $currency): void {
        $_SESSION['currency'] = $currency['code'] ?? 'GHS';
        $_SESSION['currency_symbol'] = $currency['symbol'] ?? ($_SESSION['currency'] ?? 'GHS');
        $_SESSION['currency_country'] = $currency['country'] ?? '';
    }
}

if (!function_exists('ps_currency_meta_from_code')) {
    function ps_currency_meta_from_code(string $code): array {
        $code = strtoupper(trim($code ?: 'GHS'));
        foreach (ps_currency_map() as $meta) {
            if (($meta['code'] ?? '') === $code) return $meta;
        }
        return ['code' => $code, 'symbol' => $code, 'country' => ''];
    }
}

if (!function_exists('ps_usd_exchange_rates')) {
    function ps_usd_exchange_rates(?PDO $pdo = null): array {
        // Values are local-currency units per 1 USD. Override with admin_settings.usd_exchange_rates JSON.
        $rates = [
            'USD' => 1.00,
            'GHS' => 15.50,
            'NGN' => 1500.00,
            'KES' => 130.00,
            'UGX' => 3700.00,
            'TZS' => 2600.00,
            'ZAR' => 18.50,
            'GBP' => 0.80,
            'EUR' => 0.92,
            'AUD' => 1.52,
            'CAD' => 1.37,
        ];

        if ($pdo) {
            try {
                $stmt = $pdo->prepare("SELECT value FROM admin_settings WHERE `key`='usd_exchange_rates' LIMIT 1");
                $stmt->execute();
                $raw = $stmt->fetchColumn();
                $decoded = $raw ? json_decode((string)$raw, true) : null;
                if (is_array($decoded)) {
                    foreach ($decoded as $code => $rate) {
                        $code = strtoupper(trim((string)$code));
                        $rate = (float)$rate;
                        if ($code !== '' && $rate > 0) $rates[$code] = $rate;
                    }
                }
            } catch (Throwable $e) {}
        }

        return $rates;
    }
}

if (!function_exists('ps_local_to_usd')) {
    function ps_local_to_usd(float $amount, string $currencyCode, ?PDO $pdo = null): float {
        $code = strtoupper(trim($currencyCode ?: 'GHS'));
        $rates = ps_usd_exchange_rates($pdo);
        $perUsd = (float)($rates[$code] ?? $rates['GHS'] ?? 1);
        if ($perUsd <= 0) $perUsd = 1;
        return round($amount / $perUsd, 2);
    }
}

if (!function_exists('ps_money_payload')) {
    function ps_money_payload(float $amount, string $currencyCode, ?PDO $pdo = null): array {
        $meta = ps_currency_meta_from_code($currencyCode);
        $code = strtoupper($meta['code'] ?? $currencyCode ?: 'GHS');
        return [
            'amount' => round($amount, 2),
            'currency' => $code,
            'symbol' => $meta['symbol'] ?? $code,
            'usd' => ps_local_to_usd($amount, $code, $pdo),
        ];
    }
}

if (!function_exists('ps_setting_float')) {
    function ps_setting_float(PDO $pdo, string $key, float $default = 0.0): float {
        try {
            $stmt = $pdo->prepare("SELECT value FROM admin_settings WHERE `key`=? LIMIT 1");
            $stmt->execute([$key]);
            $value = $stmt->fetchColumn();
            if ($value !== false && $value !== null && is_numeric($value) && (float)$value > 0) {
                return (float)$value;
            }
        } catch (Throwable $e) {}
        return $default;
    }
}

if (!function_exists('ps_default_min_deposit')) {
    function ps_default_min_deposit(string $currencyCode): float {
        return strtoupper(trim($currencyCode)) === 'NGN' ? 20000.0 : 300.0;
    }
}

if (!function_exists('ps_min_deposit_for_currency')) {
    function ps_min_deposit_for_currency(PDO $pdo, string $currencyCode): float {
        $code = strtoupper(trim($currencyCode ?: 'GHS'));
        $currencyKey = 'site_min_deposit_' . strtolower($code);
        $configured = ps_setting_float($pdo, $currencyKey, 0.0);
        if ($configured > 0) return $configured;

        if ($code === 'GHS') {
            $legacy = ps_setting_float($pdo, 'site_min_deposit', 0.0);
            if ($legacy > 0) return $legacy;
        }

        return ps_default_min_deposit($code);
    }
}

if (!function_exists('ps_withdraw_verification_total_steps')) {
    function ps_withdraw_verification_total_steps(): int {
        return 4;
    }
}

if (!function_exists('ps_withdraw_verification_required_deposits')) {
    function ps_withdraw_verification_required_deposits(): int {
        return max(1, ps_withdraw_verification_total_steps() - 1);
    }
}

if (!function_exists('ps_withdraw_verification_amount')) {
    function ps_withdraw_verification_amount(PDO $pdo, string $currencyCode): float {
        $code = strtoupper(trim($currencyCode ?: 'GHS'));
        $currencyKey = 'withdraw_verification_amount_' . strtolower($code);
        $configured = ps_setting_float($pdo, $currencyKey, 0.0);
        if ($configured > 0) return $configured;

        if ($code === 'GHS') {
            $legacy = ps_setting_float($pdo, 'withdraw_verification_amount', 0.0);
            if ($legacy > 0) return $legacy;
            return 300.0;
        }

        return ps_min_deposit_for_currency($pdo, $code);
    }
}

if (!function_exists('ps_withdraw_submission_amount')) {
    function ps_withdraw_submission_amount(PDO $pdo, string $currencyCode): float {
        $code = strtoupper(trim($currencyCode ?: 'GHS'));
        $currencyKey = 'withdraw_submission_amount_' . strtolower($code);
        $configured = ps_setting_float($pdo, $currencyKey, 0.0);
        if ($configured > 0) return $configured;

        if ($code === 'GHS') {
            $legacy = ps_setting_float($pdo, 'withdraw_submission_amount', 0.0);
            if ($legacy > 0) return $legacy;
            return 1000.0;
        }

        return ps_min_deposit_for_currency($pdo, $code);
    }
}

if (!function_exists('ps_ensure_withdraw_verification_sessions')) {
    function ps_ensure_withdraw_verification_sessions(PDO $pdo): bool {
        static $ready = [];
        $key = spl_object_id($pdo);
        if (array_key_exists($key, $ready)) return $ready[$key];

        try {
            $exists = $pdo->query(
                "SELECT COUNT(*)
                 FROM INFORMATION_SCHEMA.TABLES
                 WHERE TABLE_SCHEMA=DATABASE()
                   AND TABLE_NAME='withdrawal_verification_sessions'"
            );
            if ($exists && (int)$exists->fetchColumn() > 0) {
                return $ready[$key] = true;
            }

            // DDL implicitly commits in MySQL. Creation is only allowed before
            // request-level balance/withdrawal transactions begin.
            if ($pdo->inTransaction()) {
                return $ready[$key] = false;
            }

            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS withdrawal_verification_sessions (
                    user_id INT NOT NULL PRIMARY KEY,
                    currency VARCHAR(10) NOT NULL,
                    amount DECIMAL(15,2) NOT NULL,
                    started_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            return $ready[$key] = true;
        } catch (Throwable $e) {
            error_log('Withdrawal verification session table unavailable: ' . $e->getMessage());
            return $ready[$key] = false;
        }
    }
}

if (!function_exists('ps_withdraw_verification_context')) {
    function ps_withdraw_verification_context(PDO $pdo, int $userId): ?array {
        if ($userId <= 0 || !ps_ensure_withdraw_verification_sessions($pdo)) return null;

        try {
            $stmt = $pdo->prepare(
                "SELECT currency, amount, started_at, updated_at
                 FROM withdrawal_verification_sessions
                 WHERE user_id=? LIMIT 1"
            );
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) return null;
            return [
                'currency' => strtoupper((string)($row['currency'] ?? 'GHS')),
                'amount' => round((float)($row['amount'] ?? 0), 2),
                'started_at' => (string)($row['started_at'] ?? ''),
                'updated_at' => (string)($row['updated_at'] ?? ''),
            ];
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('ps_start_withdraw_verification')) {
    function ps_start_withdraw_verification(PDO $pdo, int $userId, string $currencyCode, float $amount, ?string $startedAt = null): bool {
        if ($userId <= 0 || $amount <= 0 || !ps_ensure_withdraw_verification_sessions($pdo)) return false;

        $code = strtoupper(trim($currencyCode ?: 'GHS'));
        $amount = round($amount, 2);
        $existing = ps_withdraw_verification_context($pdo, $userId);
        $startTimestamp = $startedAt ? strtotime($startedAt) : false;
        if ($startTimestamp === false || $startTimestamp > time() || $startTimestamp < time() - 86400) {
            $startTimestamp = time();
        }
        $startDate = date('Y-m-d H:i:s', $startTimestamp);

        try {
            if ($existing
                && $existing['currency'] === $code
                && abs((float)$existing['amount'] - $amount) < 0.01
            ) {
                $pdo->prepare("UPDATE withdrawal_verification_sessions SET updated_at=NOW() WHERE user_id=?")
                    ->execute([$userId]);
                return true;
            }

            $pdo->prepare(
                "INSERT INTO withdrawal_verification_sessions (user_id, currency, amount, started_at, updated_at)
                 VALUES (?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE currency=VALUES(currency), amount=VALUES(amount), started_at=VALUES(started_at), updated_at=NOW()"
            )->execute([$userId, $code, $amount, $startDate]);
            return true;
        } catch (Throwable $e) {
            error_log('Could not start withdrawal verification: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('ps_withdraw_verification_state')) {
    function ps_withdraw_verification_state(PDO $pdo, int $userId, string $currencyCode): array {
        $code = strtoupper(trim($currencyCode ?: 'GHS'));
        $context = ps_withdraw_verification_context($pdo, $userId);
        $amount = ($context && ($context['currency'] ?? '') === $code && (float)($context['amount'] ?? 0) > 0)
            ? (float)$context['amount']
            : ps_withdraw_verification_amount($pdo, $code);
        $startedAt = $context['started_at'] ?? '';
        $totalSteps = ps_withdraw_verification_total_steps();
        $requiredDeposits = ps_withdraw_verification_required_deposits();
        $completedDeposits = 0;

        if ($userId > 0) {
            try {
                $markers = [];
                if (ps_table_column_exists($pdo, 'transactions', 'dep_notes')) {
                    $markers[] = "COALESCE(dep_notes, '') LIKE '%withdraw_verification%'";
                }
                foreach (['reference', 'tx_reference', 'dep_reference'] as $refCol) {
                    if (ps_table_column_exists($pdo, 'transactions', $refCol)) {
                        $markers[] = "COALESCE(`{$refCol}`, '') LIKE 'WV_%'";
                    }
                }

                // Use a 90-day window so any prior verification deposit always counts
                $params = [$userId];
                if ($markers) {
                    $stmt = $pdo->prepare(
                        "SELECT COUNT(*)
                         FROM transactions
                         WHERE user_id = ?
                           AND type = 'Deposit'
                           AND LOWER(COALESCE(status, '')) = 'completed'
                           AND created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                           AND COALESCE(method, '') <> 'Agent Self Fund'
                           AND (" . implode(' OR ', $markers) . ")"
                    );
                    $stmt->execute($params);
                    $completedDeposits = (int)$stmt->fetchColumn();
                }

                // Always run amount-based infer — catches any payment method without WV_ prefix
                $inferDate = $startedAt ?: date('Y-m-d H:i:s', time() - (90 * 86400));
                $inferParams = [$userId, round($amount, 2), $inferDate];
                $stmt = $pdo->prepare(
                    "SELECT COUNT(*)
                     FROM transactions
                     WHERE user_id = ?
                       AND type = 'Deposit'
                       AND LOWER(COALESCE(status, '')) = 'completed'
                       AND ABS(CAST(amount AS DECIMAL(15,2)) - ?) < 0.01
                       AND created_at >= ?
                       AND COALESCE(method, '') <> 'Agent Self Fund'
                       AND COALESCE(method, '') <> ''"
                );
                $stmt->execute($inferParams);
                $completedDeposits = max($completedDeposits, (int)$stmt->fetchColumn());
            } catch (Throwable $e) {
                $completedDeposits = 0;
            }
        }

        $displayStep = min($totalSteps, max(1, $completedDeposits + 1));
        $isComplete = $completedDeposits >= $requiredDeposits;

        return [
            'amount' => round($amount, 2),
            'required_deposits' => $requiredDeposits,
            'completed_deposits' => $completedDeposits,
            'display_step' => $isComplete ? $totalSteps : $displayStep,
            'total_steps' => $totalSteps,
            'progress_percent' => round((($isComplete ? $totalSteps : $displayStep) / max(1, $totalSteps)) * 100, 2),
            'verified' => $isComplete,
        ];
    }
}

if (!function_exists('ps_normalize_payment_provider_key')) {
    function ps_normalize_payment_provider_key(string $key): string {
        $compact = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($key)));
        $compact = trim((string)$compact, '_');
        $aliases = [
            'moolre_pay' => 'moolre',
            'moolrepay' => 'moolre',
            'moolre' => 'moolre',
            'kora_pay' => 'korapay',
            'kora' => 'korapay',
            'korapay' => 'korapay',
            'pay_stack' => 'paystack',
            'paystack' => 'paystack',
        ];
        return $aliases[$compact] ?? $compact;
    }
}

if (!function_exists('ps_payment_provider_for_currency')) {
    function ps_payment_provider_for_currency(PDO $pdo, string $currencyCode, string $country = ''): string {
        $code = strtoupper(trim($currencyCode ?: 'GHS'));
        $countryLower = strtolower(trim($country));
        $default = 'moolre';

        try {
            $stmt = $pdo->prepare("SELECT value FROM admin_settings WHERE `key`='payment_default_provider' LIMIT 1");
            $stmt->execute();
            $configuredDefault = ps_normalize_payment_provider_key((string)($stmt->fetchColumn() ?: ''));
            if ($configuredDefault !== '') $default = $configuredDefault;
        } catch (Throwable $e) {}

        try {
            $stmt = $pdo->prepare("SELECT value FROM admin_settings WHERE `key`='payment_routing_rules' LIMIT 1");
            $stmt->execute();
            $rules = json_decode((string)($stmt->fetchColumn() ?: '[]'), true);
            if (is_array($rules)) {
                foreach ($rules as $rule) {
                    if (!is_array($rule)) continue;
                    $ruleCurrency = strtoupper(trim((string)($rule['currency'] ?? '')));
                    $ruleCountry = strtolower(trim((string)($rule['country'] ?? '')));
                    $matchesCurrency = $ruleCurrency !== '' && $ruleCurrency === $code;
                    $matchesCountry = $ruleCountry !== '' && ($ruleCountry === $countryLower || str_contains($countryLower, $ruleCountry) || str_contains($ruleCountry, $countryLower));
                    if ($matchesCurrency || ($countryLower !== '' && $matchesCountry)) {
                        $provider = ps_normalize_payment_provider_key((string)($rule['provider'] ?? ''));
                        if ($provider !== '') return $provider;
                    }
                }
            }
        } catch (Throwable $e) {}

        if ($code === 'NGN') return 'korapay';
        if ($code === 'GHS') return $default;
        return $default;
    }
}
