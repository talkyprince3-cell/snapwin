<?php
// Admin-configurable payment gateway settings.

if (!function_exists('ps_gateway_setting')) {
    function ps_gateway_setting(PDO $pdo, string $settingKey, string $envKey = '', string $default = ''): string {
        try {
            $stmt = $pdo->prepare("SELECT value FROM admin_settings WHERE `key`=? LIMIT 1");
            $stmt->execute([$settingKey]);
            $value = trim((string)($stmt->fetchColumn() ?: ''));
            if ($value !== '') return $value;
        } catch (Throwable $e) {}

        if ($envKey !== '' && function_exists('ps_config')) {
            return trim((string)ps_config($envKey, $default));
        }
        return $default;
    }
}

if (!function_exists('ps_direct_gateway_provider')) {
    function ps_direct_gateway_provider(PDO $pdo): string {
        $provider = ps_gateway_setting($pdo, 'direct_gateway_provider', '', 'paystack');
        if (function_exists('ps_normalize_payment_provider_key')) {
            $provider = ps_normalize_payment_provider_key($provider);
        } else {
            $provider = strtolower(trim($provider));
        }
        return in_array($provider, ['paystack', 'moolre', 'flutterwave'], true) ? $provider : 'paystack';
    }
}

if (!function_exists('ps_paystack_public_key')) {
    function ps_paystack_public_key(PDO $pdo): string {
        return ps_gateway_setting($pdo, 'direct_paystack_public_key', 'PAYSTACK_PUBLIC_KEY', '');
    }
}

if (!function_exists('ps_paystack_secret_key')) {
    function ps_paystack_secret_key(PDO $pdo): string {
        return ps_gateway_setting($pdo, 'direct_paystack_secret_key', 'PAYSTACK_SECRET_KEY', '');
    }
}

if (!function_exists('ps_moolre_setting')) {
    function ps_moolre_setting(PDO $pdo, string $name): string {
        $map = [
            'api_user' => ['direct_moolre_api_user', 'MOOLRE_API_USER'],
            'public_key' => ['direct_moolre_public_key', 'MOOLRE_PUBLIC_KEY'],
            'ghs_account' => ['direct_moolre_ghs_account', 'MOOLRE_GHS_ACCOUNT'],
            'ngn_account' => ['direct_moolre_ngn_account', 'MOOLRE_NGN_ACCOUNT'],
            'ghs_webhook_secret' => ['direct_moolre_ghs_webhook_secret', 'MOOLRE_GHS_WEBHOOK_SECRET'],
            'ngn_webhook_secret' => ['direct_moolre_ngn_webhook_secret', 'MOOLRE_NGN_WEBHOOK_SECRET'],
        ];
        if (!isset($map[$name])) return '';
        return ps_gateway_setting($pdo, $map[$name][0], $map[$name][1], '');
    }
}

if (!function_exists('ps_flutterwave_public_key')) {
    function ps_flutterwave_public_key(PDO $pdo): string {
        return ps_gateway_setting($pdo, 'direct_flutterwave_public_key', 'FLUTTERWAVE_PUBLIC_KEY', '');
    }
}

// Flutterwave v4 uses OAuth client credentials instead of the v3 public/secret
// key pair. Keep the settings separate so an admin can switch versions without
// overwriting a working v3 integration.
if (!function_exists('ps_flutterwave_version')) {
    function ps_flutterwave_version(PDO $pdo): string {
        return ps_gateway_setting($pdo, 'direct_flutterwave_version', '', 'v3') === 'v4' ? 'v4' : 'v3';
    }
}

if (!function_exists('ps_flutterwave_v4_client_id')) {
    function ps_flutterwave_v4_client_id(PDO $pdo): string {
        return ps_gateway_setting($pdo, 'direct_flutterwave_v4_client_id', 'FLUTTERWAVE_V4_CLIENT_ID', '');
    }
}

if (!function_exists('ps_flutterwave_v4_client_secret')) {
    function ps_flutterwave_v4_client_secret(PDO $pdo): string {
        return ps_gateway_setting($pdo, 'direct_flutterwave_v4_client_secret', 'FLUTTERWAVE_V4_CLIENT_SECRET', '');
    }
}

if (!function_exists('ps_flutterwave_v4_encryption_key')) {
    function ps_flutterwave_v4_encryption_key(PDO $pdo): string {
        return ps_gateway_setting($pdo, 'direct_flutterwave_v4_encryption_key', 'FLUTTERWAVE_V4_ENCRYPTION_KEY', '');
    }
}

if (!function_exists('ps_flutterwave_v4_webhook_secret')) {
    function ps_flutterwave_v4_webhook_secret(PDO $pdo): string {
        return ps_gateway_setting($pdo, 'direct_flutterwave_v4_webhook_secret', 'FLUTTERWAVE_V4_WEBHOOK_SECRET', '');
    }
}

if (!function_exists('ps_flutterwave_v4_environment')) {
    function ps_flutterwave_v4_environment(PDO $pdo): string {
        return ps_gateway_setting($pdo, 'direct_flutterwave_v4_environment', '', 'live') === 'sandbox' ? 'sandbox' : 'live';
    }
}

if (!function_exists('ps_flutterwave_v4_base_url')) {
    function ps_flutterwave_v4_base_url(PDO $pdo): string {
        return ps_flutterwave_v4_environment($pdo) === 'sandbox'
            ? 'https://developersandbox-api.flutterwave.com'
            : 'https://f4bexperience.flutterwave.com';
    }
}

if (!function_exists('ps_flutterwave_secret_key')) {
    function ps_flutterwave_secret_key(PDO $pdo): string {
        return ps_gateway_setting($pdo, 'direct_flutterwave_secret_key', 'FLUTTERWAVE_SECRET_KEY', '');
    }
}

if (!function_exists('ps_flutterwave_encryption_key')) {
    function ps_flutterwave_encryption_key(PDO $pdo): string {
        return ps_gateway_setting($pdo, 'direct_flutterwave_encryption_key', 'FLUTTERWAVE_ENCRYPTION_KEY', '');
    }
}

if (!function_exists('ps_flutterwave_webhook_secret')) {
    function ps_flutterwave_webhook_secret(PDO $pdo): string {
        return ps_gateway_setting($pdo, 'direct_flutterwave_webhook_secret', 'FLUTTERWAVE_WEBHOOK_SECRET', '');
    }
}

if (!function_exists('ps_techvault_shared_token')) {
    function ps_techvault_shared_token(PDO $pdo): string {
        $token = ps_gateway_setting($pdo, 'techvault_shared_token', 'TECHVAULT_SHARED_TOKEN', 'foundation');
        // This is the default 'secret handshake' token that will match most new
        // TechVault integrations until an admin explicitly replaces it.
        return $token !== '' ? $token : 'foundation';
    }
}
?>
