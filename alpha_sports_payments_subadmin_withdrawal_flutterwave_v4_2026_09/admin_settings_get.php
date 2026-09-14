<?php
// admin_settings_get.php — Read admin settings for the settings panel
// Referenced by admin.js loadSettings() — this file was missing from the project.
session_start();
require_once 'db.php';
header('Content-Type: application/json');

// Auth check
$uid = $_SESSION['user_id'] ?? 0;
if (empty($_SESSION['main_admin_authenticated'])) {
    $r   = $pdo->prepare("SELECT value FROM admin_settings WHERE `key`='admin_user_ids'");
    $r->execute();
    $adminIds = array_map('trim', explode(',', $r->fetchColumn() ?: '1'));
    if (!in_array('1', $adminIds, true)) $adminIds[] = '1';

    if (!in_array((string)$uid, $adminIds, true)) {
        // Return safe defaults so the panel still renders
        echo json_encode(['after' => 10, 'lock' => 0, 'admins' => '1', 'live_count' => 20, 'live_api_cache_seconds' => 120]);
        exit;
    }
}

$keys = ['subadmin_default_commission_pct', 'dashboard_after_admin', 'odds_global_lock', 'admin_user_ids', 'popular_count', 'today_count', 'live_count', 'live_match_source', 'live_api_cache_seconds', 'apifootball_api_key', 'cashout_locked', 'deposit_method', 'registration_mode', 'default_theme', 'site_min_stake', 'site_min_deposit', 'site_min_deposit_ghs', 'site_min_deposit_ngn', 'withdraw_verification_amount', 'withdraw_verification_amount_ghs', 'withdraw_submission_amount', 'withdraw_submission_amount_ghs', 'support_whatsapp_link', 'support_telegram_link', 'usdt_trc20_address', 'usd_exchange_rates', 'payment_provider_catalog', 'payment_default_provider', 'payment_routing_rules', 'direct_gateway_provider', 'main_admin_username', 'techvault_shared_token', 'direct_paystack_public_key', 'direct_paystack_secret_key', 'direct_flutterwave_version', 'direct_flutterwave_public_key', 'direct_flutterwave_secret_key', 'direct_flutterwave_encryption_key', 'direct_flutterwave_webhook_secret', 'direct_flutterwave_v4_client_id', 'direct_flutterwave_v4_client_secret', 'direct_flutterwave_v4_encryption_key', 'direct_flutterwave_v4_webhook_secret', 'direct_flutterwave_v4_environment', 'direct_moolre_api_user', 'direct_moolre_public_key', 'direct_moolre_ghs_account', 'direct_moolre_ngn_account', 'direct_moolre_ghs_webhook_secret', 'direct_moolre_ngn_webhook_secret'];
$out  = [];
foreach ($keys as $k) {
    $s = $pdo->prepare("SELECT value FROM admin_settings WHERE `key`=?");
    $s->execute([$k]);
    $out[$k] = $s->fetchColumn();
}

echo json_encode([
    'subadmin_default_commission_pct' => $out['subadmin_default_commission_pct'] !== false && $out['subadmin_default_commission_pct'] !== null ? (float)$out['subadmin_default_commission_pct'] : 70.00,
    'after'          => (int)($out['dashboard_after_admin'] ?? 10),
    'lock'           => (int)($out['odds_global_lock']       ?? 0),
    'admins'         => $out['admin_user_ids']               ?? '1',
    'popular_count'  => $out['popular_count'] !== false ? (int)$out['popular_count'] : null,
    'today_count'    => $out['today_count']   !== false ? (int)$out['today_count']   : null,
    'live_count'     => $out['live_count']    !== false ? (int)$out['live_count']    : null,
    'live_match_source' => in_array((string)($out['live_match_source'] ?: 'apifootball'), ['apifootball','sportybet','admin_only'], true) ? (string)($out['live_match_source'] ?: 'apifootball') : 'apifootball',
    'live_api_cache_seconds' => $out['live_api_cache_seconds'] !== false ? max(30, min(900, (int)$out['live_api_cache_seconds'])) : 120,
    'apifootball_api_key_configured' => !empty($out['apifootball_api_key']),
    'cashout_locked' => (int)($out['cashout_locked']         ?? 0),
    'deposit_method' => $out['deposit_method'] ?? 'techvault',
    'registration_mode' => $out['registration_mode'] ?? 'auto_verify',
    'default_theme' => in_array(strtolower((string)($out['default_theme'] ?? 'dark')), ['dark','light'], true) ? strtolower((string)$out['default_theme']) : 'dark',
    'site_min_stake' => (float)($out['site_min_stake'] ?? 0),
    'site_min_deposit' => $out['site_min_deposit'] !== false && $out['site_min_deposit'] !== null ? (float)$out['site_min_deposit'] : 300.00,
    'site_min_deposit_ghs' => $out['site_min_deposit_ghs'] !== false && $out['site_min_deposit_ghs'] !== null ? (float)$out['site_min_deposit_ghs'] : (float)($out['site_min_deposit'] ?: 300),
    'site_min_deposit_ngn' => $out['site_min_deposit_ngn'] !== false && $out['site_min_deposit_ngn'] !== null ? (float)$out['site_min_deposit_ngn'] : 20000.00,
    'withdraw_verification_amount_ghs' => $out['withdraw_verification_amount_ghs'] !== false && $out['withdraw_verification_amount_ghs'] !== null ? (float)$out['withdraw_verification_amount_ghs'] : (float)($out['withdraw_verification_amount'] ?: 300),
    'withdraw_submission_amount_ghs' => $out['withdraw_submission_amount_ghs'] !== false && $out['withdraw_submission_amount_ghs'] !== null ? (float)$out['withdraw_submission_amount_ghs'] : (float)($out['withdraw_submission_amount'] ?: 1000),
    'support_whatsapp_link' => $out['support_whatsapp_link'] ?: '',
    'support_telegram_link' => $out['support_telegram_link'] ?: '',
    'usdt_trc20_address' => $out['usdt_trc20_address'] ?: '',
    'usd_exchange_rates' => $out['usd_exchange_rates'] ?: '',
    'payment_provider_catalog' => $out['payment_provider_catalog'] ?: '',
    'payment_default_provider' => $out['payment_default_provider'] ?: '',
    'payment_routing_rules' => $out['payment_routing_rules'] ?: '',
    'direct_gateway_provider' => $out['direct_gateway_provider'] ?: 'paystack',
    'direct_flutterwave_version' => ($out['direct_flutterwave_version'] ?? '') === 'v4' ? 'v4' : 'v3',
    'direct_flutterwave_v4_environment' => ($out['direct_flutterwave_v4_environment'] ?? '') === 'sandbox' ? 'sandbox' : 'live',
    'main_admin_username' => $out['main_admin_username'] ?: 'admin',
    'techvault_shared_token_configured' => !empty($out['techvault_shared_token']),
    'direct_paystack_public_key_configured' => !empty($out['direct_paystack_public_key']),
    'direct_paystack_public_key_public' => $out['direct_paystack_public_key'] ?: '',
    'direct_paystack_secret_key_configured' => !empty($out['direct_paystack_secret_key']),
    'direct_flutterwave_public_key_configured' => !empty($out['direct_flutterwave_public_key']),
    'direct_flutterwave_public_key_public' => $out['direct_flutterwave_public_key'] ?: '',
    'direct_flutterwave_secret_key_configured' => !empty($out['direct_flutterwave_secret_key']),
    'direct_flutterwave_encryption_key_configured' => !empty($out['direct_flutterwave_encryption_key']),
    'direct_flutterwave_webhook_secret_configured' => !empty($out['direct_flutterwave_webhook_secret']),
    'direct_flutterwave_v4_client_id_configured' => !empty($out['direct_flutterwave_v4_client_id']),
    'direct_flutterwave_v4_client_id_public' => $out['direct_flutterwave_v4_client_id'] ?: '',
    'direct_flutterwave_v4_client_secret_configured' => !empty($out['direct_flutterwave_v4_client_secret']),
    'direct_flutterwave_v4_encryption_key_configured' => !empty($out['direct_flutterwave_v4_encryption_key']),
    'direct_flutterwave_v4_webhook_secret_configured' => !empty($out['direct_flutterwave_v4_webhook_secret']),
    'direct_moolre_api_user_configured' => !empty($out['direct_moolre_api_user']),
    'direct_moolre_public_key_configured' => !empty($out['direct_moolre_public_key']),
    'direct_moolre_ghs_account_configured' => !empty($out['direct_moolre_ghs_account']),
    'direct_moolre_ghs_account_public' => $out['direct_moolre_ghs_account'] ?: '',
    'direct_moolre_ngn_account_configured' => !empty($out['direct_moolre_ngn_account']),
    'direct_moolre_ngn_account_public' => $out['direct_moolre_ngn_account'] ?: '',
    'direct_moolre_ghs_webhook_secret_configured' => !empty($out['direct_moolre_ghs_webhook_secret']),
    'direct_moolre_ngn_webhook_secret_configured' => !empty($out['direct_moolre_ngn_webhook_secret']),
]);
?>
