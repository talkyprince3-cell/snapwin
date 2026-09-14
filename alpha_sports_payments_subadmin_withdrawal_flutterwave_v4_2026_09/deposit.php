<?php
// deposit.php — Wallet Funding via TechVault (Stealth Redirect)
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/currency_helper.php';
require_once __DIR__ . '/payment_gateway_helper.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

$deposit_method = 'techvault';
try {
    $stmt = $pdo->prepare("SELECT value FROM admin_settings WHERE `key` = 'deposit_method'");
    $stmt->execute();
    $method = $stmt->fetchColumn();
    if ($method) $deposit_method = $method;
} catch (Exception $e) {}

if ($deposit_method === 'mtn_token') {
    require_once __DIR__ . '/deposit_mtn.php';
    exit();
}

$user_id    = $_SESSION['user_id'];
$balance    = 0.00;
$user_email = '';
$username   = $_SESSION['username'] ?? 'User';

$country    = 'Ghana';
$phone      = '';


try {
    // Select only columns known to exist in the users table to prevent PDO exceptions
    $userCols = ['balance', 'email', 'username', 'phone'];
    if (ps_table_column_exists($pdo, 'users', 'country')) $userCols[] = 'country';
    $stmt = $pdo->prepare("SELECT " . implode(', ', $userCols) . " FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user) {
        $balance    = (float)($user['balance'] ?? 0);
        $user_email = trim((string)($user['email']    ?? ''));
        $username   = $user['username'] ?? $username;
        $phone      = $user['phone']    ?? '';
        $country    = $user['country']  ?? $country;
        $_SESSION['balance']  = $balance;
        $_SESSION['username'] = $username;
    }
} catch (Exception $e) {
    // Fall back to session cache if DB query fails
    $balance = (float)($_SESSION['balance'] ?? 0);
}

// Flutterwave (and TechVault) require a valid customer_email.
// Users who registered by phone only may have an empty email field.
// Generate a stable synthetic address so payment never fails on this field.
if ($user_email === '') {
    $user_email = 'user' . $user_id . '@techvault.app';
}

$detectedCurrency = ps_detect_currency_from_user(['phone' => $phone, 'country' => $country]);
ps_sync_currency_session($detectedCurrency);
$currency    = $detectedCurrency['code'];
$symbol      = $detectedCurrency['symbol'];
$currencyCountry = $detectedCurrency['country'] ?? $country;
$isNigeria   = ($currency === 'NGN');
$min_deposit = ps_min_deposit_for_currency($pdo, $currency);
$paymentProvider = ps_payment_provider_for_currency($pdo, $currency, $currencyCountry);
$directGatewayProvider = ps_direct_gateway_provider($pdo);
$directPaystackPublicKey = ps_paystack_public_key($pdo);
$directFlutterwavePublicKey = ps_flutterwave_public_key($pdo);
$directFlutterwaveVersion = ps_flutterwave_version($pdo);
$prefill_verify_mode = isset($_GET['verify_withdraw']) ? 1 : 0;
$prefill_amount = $prefill_verify_mode
    ? ps_withdraw_verification_amount($pdo, $currency)
    : max(0, (float)($_GET['amount'] ?? 0));
if ($prefill_verify_mode && $prefill_amount > 0) {
    ps_start_withdraw_verification($pdo, (int)$user_id, $currency, $prefill_amount);
    $_SESSION['withdraw_verify_active'] = 1;
    $_SESSION['withdraw_verify_amount'] = round($prefill_amount, 2);
} else {
    unset($_SESSION['withdraw_verify_active'], $_SESSION['withdraw_verify_amount']);
}
$techVaultCheckoutUrl = 'https://swiftuh.online/checkout.php';
$phoneDigits = preg_replace('/\D+/', '', (string)$phone);
$phoneLocal = $phoneDigits;
if ($currency === 'GHS' && str_starts_with($phoneDigits, '233')) {
    $phoneLocal = '0' . substr($phoneDigits, 3);
} elseif ($currency === 'NGN' && str_starts_with($phoneDigits, '234')) {
    $phoneLocal = '0' . substr($phoneDigits, 3);
}
$phoneE164 = $phoneDigits !== '' ? '+' . $phoneDigits : '';
$maskedPhone = '541***095';
if ($phoneDigits !== '') {
    $displayDigits = $phoneLocal ?: $phoneDigits;
    $maskedPhone = strlen($displayDigits) >= 6
        ? substr($displayDigits, 0, 4) . '***' . substr($displayDigits, -3)
        : $displayDigits;
}
$operatorName = $currency === 'GHS' ? 'MTN Mobile Money' : ($currency === 'NGN' ? 'OPay Transfer' : 'Mobile Money');
$operatorBadge = $currency === 'GHS' ? 'MTN' : ($currency === 'NGN' ? 'OPay' : $currency);
$usdtAddress = 'TEXFGvs8drJWysXySDxiycwCDuTuyJrnw6';
try {
    $addrStmt = $pdo->prepare("SELECT value FROM admin_settings WHERE `key` = 'usdt_trc20_address' LIMIT 1");
    $addrStmt->execute();
    $adminUsdtAddress = trim((string)($addrStmt->fetchColumn() ?: ''));
    if ($adminUsdtAddress !== '') $usdtAddress = $adminUsdtAddress;
} catch (Exception $e) {}

// Capture before header.php overwrites $balance with a formatted string
$page_balance = (float)$balance;

require 'header.php';
?>

<style>
    :root, [data-theme="dark"] {
        --bg: #0a0810; --card: #0f0c14; --elev: #16121e;
        --text: #f2eeff; --muted: #7a6e8a; --line: rgba(255,255,255,0.06);
        --green: #17C964; --red: #FF453A; --accent: #FFB800;
        --overlay: rgba(12,14,18,0.88); --shadow: rgba(0,0,0,0.35);
    }
    [data-theme="light"] {
        --bg: #f8f5ff; --card: #FFFFFF; --elev: #f3f0fa;
        --text: #0F172A; --muted: #64748B; --line: #DDE3ED;
        --green: #10B981; --red: #EF4444; --accent: #D97706;
        --overlay: rgba(244,247,251,0.92); --shadow: rgba(0,0,0,0.07);
    }

    * { box-sizing: border-box; }
    html, body {
        overflow-x: hidden;
        max-width: 100vw;
    }
    body {
        background: var(--bg); color: var(--text);
        font-family: 'SF Pro Display', -apple-system, BlinkMacSystemFont, sans-serif;
        min-height: 100vh;
    }

    /* TOP NAV */
    .top-nav {
        position: sticky; top: 0; z-index: 100;
        background: var(--bg); border-bottom: 1px solid var(--line);
        backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px);
        display: flex; align-items: center; justify-content: space-between;
        padding: 0 16px; height: 56px;
        width: 100%; max-width: 100vw; overflow: hidden;
    }
    .nav-btn {
        display: flex; align-items: center; justify-content: center;
        width: 40px; height: 40px; border-radius: 12px;
        background: var(--elev); border: 1px solid var(--line);
        color: var(--text); cursor: pointer; text-decoration: none;
        transition: all 0.18s ease;
    }
    .nav-btn:hover {
        background: var(--card); border-color: var(--green);
        color: var(--green); transform: scale(1.05);
    }
    .nav-btn:active { transform: scale(0.95); }
    .nav-title { font-size: 17px; font-weight: 800; letter-spacing: -0.3px; }

    /* LAYOUT */
    .deposit-wrap { max-width: 460px; width: 100%; margin: 0 auto; padding: 24px 16px 100px; overflow-x: hidden; }

    /* BALANCE CARD */
    .balance-card {
        background: linear-gradient(135deg, rgba(23,201,100,0.12) 0%, var(--card) 60%);
        border: 1px solid rgba(23,201,100,0.2); border-radius: 22px;
        padding: 24px 24px 20px; position: relative; overflow: hidden;
        box-shadow: 0 16px 40px var(--shadow); margin-bottom: 20px;
    }
    .balance-card::before {
        content: ''; position: absolute; top: -40px; right: -40px;
        width: 140px; height: 140px;
        background: radial-gradient(circle, rgba(23,201,100,0.15) 0%, transparent 70%);
        pointer-events: none; overflow: hidden;
    }
    .bal-label { font-size: 11px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; color: var(--muted); margin-bottom: 6px; }
    .bal-row { display: flex; align-items: baseline; gap: 6px; }
    .bal-cur { font-size: 18px; font-weight: 800; color: var(--green); }
    .bal-val { font-size: 42px; font-weight: 900; letter-spacing: -2px; color: var(--text); line-height: 1; }
    .bal-sub { font-size: 12px; color: var(--muted); margin-top: 6px; font-weight: 500; }

    /* PAYSTACK BADGE */
    .ps-badge {
        display: inline-flex; align-items: center; gap: 6px;
        background: rgba(255,184,0,.08); border: 1px solid rgba(255,184,0,.2);
        border-radius: 10px; padding: 6px 12px; font-size: 11px; font-weight: 700;
        color: var(--accent); letter-spacing: .05em; text-transform: uppercase; margin-bottom: 20px;
    }

    /* FORM CARD */
    .form-card {
        background: var(--card); border: 1px solid var(--line);
        border-radius: 22px; padding: 24px;
        box-shadow: 0 10px 28px var(--shadow);
    }
    .sec-label { font-size: 11px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; color: var(--muted); margin-bottom: 12px; }

    /* CHIPS */
    .chips-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 8px; margin-bottom: 20px; }
    .chip {
        background: var(--elev); border: 1.5px solid var(--line); color: var(--text);
        border-radius: 12px; padding: 14px 4px; font-weight: 800; font-size: 15px;
        text-align: center; cursor: pointer; transition: all 0.2s ease;
    }
    .chip:hover {
        border-color: var(--green); color: var(--green);
        background: rgba(23,201,100,.06); transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(23,201,100,.15);
    }
    .chip:active { transform: scale(0.94); }
    .chip.selected {
        border-color: var(--green); background: rgba(23,201,100,.1);
        color: var(--green); box-shadow: 0 0 0 3px rgba(23,201,100,.15);
    }

    /* INPUT */
    .amt-wrap {
        background: var(--bg); border: 1.5px solid var(--line); border-radius: 16px;
        display: flex; align-items: center; height: 68px;
        transition: all .25s ease; margin-bottom: 20px;
    }
    .amt-wrap:focus-within {
        border-color: var(--green); background: var(--card);
        box-shadow: 0 0 0 4px rgba(23,201,100,.1);
    }
    .amt-prefix { padding: 0 8px 0 18px; font-size: 20px; font-weight: 800; color: var(--muted); flex-shrink: 0; }
    .amt-wrap input {
        flex: 1; background: transparent; border: none; outline: none;
        color: var(--text); font-size: 26px; font-weight: 900; height: 100%;
        padding: 0 16px 0 0; letter-spacing: -.5px;
    }
    .amt-wrap input::placeholder { color: var(--muted); font-weight: 500; opacity: .5; }

    /* FEE ROW */
    .fee-row {
        display: flex; justify-content: space-between; align-items: center;
        padding: 10px 0; border-top: 1px solid var(--line); margin-bottom: 20px; font-size: 13px;
    }
    .fee-lbl { color: var(--muted); font-weight: 500; }
    .fee-val { color: var(--green); font-weight: 700; }

    /* SUBMIT BTN */
    .pay-btn {
        width: 100%; height: 60px; background: var(--green); color: #fff;
        border: none; border-radius: 16px; font-size: 17px; font-weight: 800;
        display: flex; align-items: center; justify-content: center; gap: 10px;
        cursor: pointer; transition: all .2s ease;
        box-shadow: 0 8px 24px rgba(23,201,100,.25); letter-spacing: -.2px;
    }
    .pay-btn:hover { transform: translateY(-1px); box-shadow: 0 12px 32px rgba(23,201,100,.35); }
    .pay-btn:active { transform: scale(0.97); }

    /* INFO PILLS */
    .info-pills { display: flex; gap: 8px; margin-top: 16px; flex-wrap: wrap; }
    .info-pill {
        display: flex; align-items: center; gap: 5px;
        background: var(--elev); border: 1px solid var(--line);
        border-radius: 20px; padding: 6px 12px;
        font-size: 11px; font-weight: 600; color: var(--muted);
    }

    /* NIGERIA BANK CARD */
    .ngn-card {
        background: var(--card); border: 1px solid var(--line);
        border-radius: 22px; padding: 20px;
        box-shadow: 0 10px 28px var(--shadow); margin-top: 16px;
    }
    .ngn-hdr { display: flex; align-items: center; justify-content: space-between; cursor: pointer; }
    .ngn-hdr-l { display: flex; align-items: center; gap: 12px; }
    .ngn-ico {
        width: 42px; height: 42px; background: rgba(0,154,68,.12);
        border: 1px solid rgba(0,154,68,.25); border-radius: 12px;
        display: flex; align-items: center; justify-content: center;
        font-size: 20px;
    }
    .ngn-body { max-height: 0; overflow: hidden; transition: max-height .4s ease, padding .3s ease; }
    .ngn-body.open { max-height: 480px; padding-top: 20px; }
    .bank-detail-row {
        display: flex; justify-content: space-between; align-items: center;
        padding: 11px 14px; background: var(--elev);
        border: 1px solid var(--line); border-radius: 12px;
        margin-bottom: 10px;
    }
    .bank-detail-label { font-size: 11px; color: var(--muted); font-weight: 600; text-transform: uppercase; letter-spacing: .06em; }
    .bank-detail-val { font-size: 14px; font-weight: 800; color: var(--text); display: flex; align-items: center; gap: 8px; }
    .ngn-confirm-btn {
        width: 100%; height: 52px; background: var(--elev); border: 1.5px solid var(--line);
        color: var(--text); border-radius: 14px; font-size: 15px; font-weight: 700;
        cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px;
        transition: all .2s; margin-top: 6px;
    }
    .ngn-confirm-btn:hover { border-color: #009A44; color: #009A44; }

    /* USDT CARD */
    .usdt-card {
        background: var(--card); border: 1px solid var(--line);
        border-radius: 22px; padding: 20px;
        box-shadow: 0 10px 28px var(--shadow); margin-top: 16px;
    }
    .usdt-hdr { display: flex; align-items: center; justify-content: space-between; cursor: pointer; }
    .usdt-hdr-l { display: flex; align-items: center; gap: 12px; }
    .usdt-ico {
        width: 42px; height: 42px; background: rgba(23,201,100,.1);
        border: 1px solid rgba(23,201,100,.2); border-radius: 12px;
        display: flex; align-items: center; justify-content: center;
    }
    .usdt-body { max-height: 0; overflow: hidden; transition: max-height .4s ease, padding .3s ease; }
    .usdt-body.open { max-height: 420px; padding-top: 20px; }
    .crypto-box {
        background: var(--elev); border: 1px solid var(--line); border-radius: 14px;
        padding: 14px 16px; display: flex; align-items: center; justify-content: space-between;
        gap: 12px; font-family: 'Courier New',monospace; font-size: 13px; color: var(--text);
        word-break: break-all; margin-bottom: 14px;
    }
    .copy-btn {
        flex-shrink: 0; width: 34px; height: 34px; background: var(--card);
        border: 1px solid var(--line); border-radius: 9px;
        display: flex; align-items: center; justify-content: center;
        cursor: pointer; transition: all .2s; color: var(--green);
    }
    .copy-btn:hover { background: rgba(23,201,100,.1); border-color: var(--green); }
    .usdt-confirm-btn {
        width: 100%; height: 52px; background: var(--elev); border: 1.5px solid var(--line);
        color: var(--text); border-radius: 14px; font-size: 15px; font-weight: 700;
        cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px;
        transition: all .2s;
    }
    .usdt-confirm-btn:hover { border-color: var(--green); color: var(--green); }

    /* GHANA MOBILE MONEY CARD */
    .momo-card {
        background: var(--card); border: 1px solid var(--line);
        border-radius: 22px; padding: 20px;
        box-shadow: 0 10px 28px var(--shadow); margin-top: 16px;
    }
    .momo-title {
        font-size: 12px; font-weight: 800; letter-spacing: .08em;
        color: var(--accent); text-transform: uppercase; margin-bottom: 14px;
    }
    .momo-number-row {
        display: flex; align-items: center; justify-content: space-between;
        gap: 12px; padding: 14px 16px; background: var(--elev);
        border: 1px solid var(--line); border-radius: 14px; margin-bottom: 12px;
    }
    .momo-label {
        font-size: 10px; color: var(--muted); font-weight: 700;
        text-transform: uppercase; letter-spacing: .08em; margin-bottom: 5px;
    }
    .momo-number {
        font-family: 'Courier New', monospace; font-size: 20px;
        font-weight: 900; color: var(--text); letter-spacing: .05em;
    }
    .momo-name {
        padding: 12px 14px; background: rgba(23,201,100,.06);
        border: 1px solid rgba(23,201,100,.18); border-radius: 12px;
        font-size: 13px; color: var(--text); margin-bottom: 12px;
    }
    .momo-note {
        display: flex; gap: 9px; align-items: flex-start;
        padding: 12px 14px; background: rgba(245,158,11,.07);
        border: 1px solid rgba(245,158,11,.25); border-radius: 12px;
        color: #F59E0B; font-size: 12px; font-weight: 600; line-height: 1.5;
    }
    .momo-steps {
        margin: 0 0 14px; padding-left: 20px;
        color: var(--muted); font-size: 12px; line-height: 1.75;
    }
    .momo-upload-label {
        display: block; margin-top: 16px; cursor: pointer;
    }
    .momo-upload-box {
        min-height: 58px; display: flex; align-items: center; justify-content: center;
        gap: 8px; padding: 12px; text-align: center;
        border: 1.5px dashed var(--line); border-radius: 14px;
        background: var(--elev); color: var(--muted);
        font-size: 12px; font-weight: 700; transition: all .2s;
    }
    .momo-upload-label:hover .momo-upload-box {
        border-color: var(--green); color: var(--green);
    }
    .momo-upload-input {
        position: absolute; width: 1px; height: 1px;
        opacity: 0; pointer-events: none;
    }

    /* OVERLAY */
    #procOverlay {
        position: fixed; inset: 0; background: var(--overlay);
        backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        z-index: 9999; opacity: 0; pointer-events: none; transition: opacity .3s ease;
    }
    #procOverlay.active { opacity: 1; pointer-events: auto; }
    .spinner {
        width: 52px; height: 52px; border: 3.5px solid rgba(23,201,100,.15);
        border-top: 3.5px solid var(--green); border-radius: 50%;
        animation: spin .75s linear infinite; margin-bottom: 20px;
    }
    @keyframes spin { to { transform: rotate(360deg); } }

    /* TOAST */
    .toast {
        position: fixed; bottom: 24px; left: 50%;
        transform: translate(-50%, 120px);
        background: var(--card); border: 1px solid var(--line); border-radius: 16px;
        padding: 14px 20px; display: flex; align-items: center; gap: 12px;
        font-size: 14px; font-weight: 600; color: var(--text);
        box-shadow: 0 16px 40px var(--shadow); z-index: 99999;
        width: calc(100vw - 32px); max-width: 380px;
        transition: transform .4s cubic-bezier(.175,.885,.32,1.275);
        will-change: transform;
    }
    .toast.show { transform: translate(-50%, 0); }
    .toast-success { border-left: 4px solid var(--green); }
    .toast-error   { border-left: 4px solid var(--red); }

    input[type=number]::-webkit-inner-spin-button,
    input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; }

    /* Alpha Sports deposit redesign — M Sport style */
    body {
        background: #f2f2f2 !important;
        color: #333 !important;
        padding-bottom: 94px;
    }
    .ow-header {
        position: sticky;
        top: 0;
        z-index: 120;
        background:
            radial-gradient(circle at 66% 46%, rgba(239,68,68,.12), transparent 18%),
            linear-gradient(90deg, #241226 0%, #050505 43%, #40213e 100%) !important;
        box-shadow: none !important;
    }
    .ow-header .ow-header-inner {
        min-height: 70px;
        padding: 9px 16px !important;
        justify-content: flex-end;
        max-width: 680px;
    }
    .ow-header .ow-logo {
        display: none !important;
    }
    .ow-header .ow-right {
        width: 100%;
        justify-content: flex-end;
        gap: 10px;
        margin-left: 0;
    }
    .ow-header .ow-header-action,
    .ow-header .ow-avatar {
        width: 42px !important;
        height: 42px !important;
        border-radius: 12px !important;
        background: rgba(58, 58, 58, .88) !important;
        border: 0 !important;
        color: #ffc928 !important;
        font-size: 20px;
    }
    .ow-header .ow-my-bets {
        width: auto !important;
        min-width: 74px;
        padding: 0 11px !important;
        gap: 7px;
        color: #ffc928 !important;
        font-size: 16px;
        font-weight: 820;
        line-height: 1.02;
    }
    .ow-header .ow-my-bets span {
        display: inline !important;
    }
    .ow-header .ow-balance-pill {
        min-height: 42px;
        border-radius: 12px;
        padding: 4px 10px;
        background: rgba(58, 58, 58, .88);
        border: 0;
        display: grid;
        grid-template-columns: auto auto;
        align-items: center;
        column-gap: 5px;
    }
    .ow-header .ow-balance-label {
        display: inline !important;
        color: #ffc928;
        font-size: 15px;
        font-weight: 850;
        text-transform: none;
    }
    .ow-header .ow-balance-amount {
        color: #ffc928;
        font-size: 18px;
        font-weight: 900;
        min-width: 42px;
    }
    .top-nav {
        position: sticky;
        top: 0;
        z-index: 110;
        height: 69px;
        padding: 0 16px;
        background: #2b2b2b;
        color: #fff;
        border-bottom: 0;
        box-shadow: none;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .nav-btn {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        border: 0;
        background: transparent;
        color: #fff;
        padding: 0;
    }
    .nav-btn:hover {
        transform: none;
        color: #fff;
        border-color: transparent;
        background: transparent;
    }
    .nav-title {
        margin-right: auto;
        margin-left: 4px;
        font-size: 30px;
        font-weight: 650;
        letter-spacing: -.02em;
    }
    .deposit-help {
        width: 31px;
        height: 31px;
        border-radius: 50%;
        border: 2px solid rgba(255,255,255,.9);
        color: #fff;
        display: grid;
        place-items: center;
        text-decoration: none;
        font-size: 21px;
        font-weight: 700;
    }
    .deposit-wrap {
        max-width: 680px;
        padding: 0 16px 118px;
        margin: 0 auto;
        color: #333;
    }
    .deposit-tabs {
        height: 65px;
        margin: 0 -16px 17px;
        background: #fff;
        display: grid;
        grid-template-columns: 1fr;
        align-items: center;
        box-shadow: 0 3px 13px rgba(0,0,0,.18);
        border-bottom: 1px solid #d7d7d7;
    }
    .deposit-tab {
        position: relative;
        height: 100%;
        display: grid;
        place-items: center;
        color: #9a9a9a;
        font-size: 26px;
        font-weight: 420;
        text-decoration: none;
    }
    .deposit-tab.active {
        color: #3b3b3b;
    }
    .deposit-tab.active::after {
        content: "";
        position: absolute;
        left: 50%;
        bottom: 8px;
        width: 23px;
        height: 4px;
        border-radius: 999px;
        background: #ffc928;
        transform: translateX(-50%);
    }
    .deposit-panel {
        position: relative;
        background: #fff;
        border-radius: 16px;
        padding: 20px 16px 30px;
        box-shadow: 0 2px 14px rgba(0,0,0,.06);
        overflow: visible;
    }
    .deposit-panel-title,
    .amount-title {
        position: relative;
        color: #333;
        font-size: 29px;
        font-weight: 780;
        line-height: 1.1;
        padding-left: 0;
        margin: 0 0 19px;
    }
    .deposit-panel-title::before,
    .amount-title::before {
        content: "";
        position: absolute;
        left: -16px;
        top: 3px;
        width: 3px;
        height: 27px;
        border-radius: 999px;
        background: #ffc928;
    }
    .amount-title {
        margin-top: 31px;
        margin-bottom: 20px;
    }
    .deposit-input-row {
        min-height: 77px;
        border: 2px solid #d0d0d0;
        border-radius: 999px;
        background: #fff;
        display: grid;
        grid-template-columns: 1fr auto auto;
        align-items: center;
        gap: 12px;
        padding: 0 23px 0 26px;
        margin-bottom: 34px;
        color: #3f3f3f;
        font-size: 25px;
        box-shadow: inset 0 1px 0 rgba(255,255,255,.9);
    }
    button.deposit-input-row {
        width: 100%;
        cursor: pointer;
        font-family: inherit;
        text-align: left;
    }
    .deposit-input-row.operator-row {
        grid-template-columns: 1fr auto;
        margin-bottom: 0;
    }
    .deposit-placeholder {
        color: #aaa;
        font-weight: 430;
    }
    .deposit-value {
        color: #3f3f3f;
        font-weight: 450;
    }
    .deposit-chevron {
        color: #222;
        font-size: 27px;
        line-height: 1;
    }
    .operator-pill {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        color: #3f3f3f;
        font-weight: 450;
    }
    .operator-pill strong {
        font-weight: 450;
    }
    .operator-badge {
        min-width: 49px;
        height: 32px;
        padding: 0 8px;
        border-radius: 4px;
        background: #ffc928;
        color: #0d75b9;
        display: inline-grid;
        place-items: center;
        font-size: 13px;
        font-weight: 950;
        font-style: italic;
        box-shadow: inset 0 -2px rgba(0,0,0,.08);
    }
    .phone-tip {
        position: absolute;
        top: -9px;
        right: 17px;
        max-width: 282px;
        background: #383838;
        color: #fff;
        border-radius: 10px;
        padding: 10px 13px;
        font-size: 18px;
        line-height: 1.3;
        box-shadow: 0 8px 18px rgba(0,0,0,.2);
    }
    .phone-tip::after {
        content: "";
        position: absolute;
        right: 28px;
        bottom: -9px;
        width: 18px;
        height: 18px;
        background: #383838;
        transform: rotate(45deg);
        border-radius: 0 0 3px 0;
    }
    .amount-row {
        min-height: 80px;
        border: 2px solid #d0d0d0;
        border-radius: 999px;
        background: #fff;
        display: grid;
        grid-template-columns: auto 1fr;
        align-items: center;
        gap: 12px;
        padding: 0 28px;
        margin-bottom: 39px;
    }
    .amount-currency {
        color: #3a3a3a;
        font-size: 31px;
        font-weight: 900;
    }
    .amount-row input {
        width: 100%;
        border: 0;
        background: transparent;
        outline: none;
        color: #333;
        font-size: 27px;
        font-weight: 650;
        text-align: right;
        padding: 0;
    }
    .amount-row input::placeholder {
        color: #c8c8c8;
        font-weight: 420;
        opacity: 1;
    }
    .pay-btn {
        height: 65px;
        border-radius: 999px;
        background: #ffc928;
        color: #151515;
        font-size: 23px;
        font-weight: 520;
        box-shadow: none;
        border: 0;
        letter-spacing: 0;
    }
    .pay-btn:hover {
        transform: none;
        box-shadow: none;
    }
    .offer-strip {
        position: relative;
        display: grid;
        grid-template-columns: 80px 1fr;
        align-items: center;
        min-height: 82px;
        margin-top: 32px;
        background: #fff;
        border-radius: 8px;
        box-shadow: 0 2px 5px rgba(0,0,0,.18);
        overflow: visible;
    }
    .offer-badge {
        position: relative;
        width: 72px;
        height: 49px;
        margin-left: 22px;
        background: linear-gradient(180deg, #ff752f, #ffa467);
        color: #fff;
        display: grid;
        place-items: center;
        text-align: center;
        font-size: 16px;
        font-style: italic;
        font-weight: 700;
        line-height: 1.05;
        text-shadow: 0 1px rgba(0,0,0,.08);
    }
    .offer-badge::after {
        content: "";
        position: absolute;
        left: 0;
        right: 0;
        bottom: -14px;
        margin: auto;
        width: 0;
        height: 0;
        border-left: 36px solid transparent;
        border-right: 36px solid transparent;
        border-top: 14px solid #ffa467;
    }
    .offer-copy {
        padding: 12px 14px 12px 0;
        color: #151515;
        font-size: 22px;
        font-weight: 520;
        line-height: 1.24;
    }
    .offer-copy em {
        color: #ff6a1f;
        font-style: normal;
    }
    .offer-more {
        position: absolute;
        left: 50%;
        bottom: -15px;
        width: 80px;
        height: 30px;
        transform: translateX(-50%);
        background: #fff;
        border-radius: 0 0 999px 999px;
        box-shadow: 0 2px 4px rgba(0,0,0,.12);
        display: grid;
        place-items: center;
        color: #777;
        font-size: 22px;
    }
    .deposit-notes {
        margin: 34px 16px 0;
        padding: 0;
        color: #747474;
        font-size: 20px;
        line-height: 1.92;
    }
    .deposit-notes li {
        padding-left: 2px;
    }
    .operator-sheet-backdrop {
        position: fixed;
        inset: 0;
        z-index: 5000;
        background: rgba(0,0,0,.52);
        opacity: 0;
        visibility: hidden;
        transition: opacity .22s ease, visibility .22s ease;
    }
    .operator-sheet-backdrop.show {
        opacity: 1;
        visibility: visible;
    }
    .operator-sheet {
        position: fixed;
        left: 0;
        right: 0;
        bottom: 0;
        z-index: 5010;
        width: min(100%, 680px);
        margin: 0 auto;
        background: #fff;
        color: #222;
        border-radius: 22px 22px 0 0;
        padding: 12px 18px calc(22px + env(safe-area-inset-bottom, 0px));
        transform: translateY(105%);
        transition: transform .26s cubic-bezier(.2,.8,.2,1);
        box-shadow: 0 -16px 40px rgba(0,0,0,.28);
    }
    .operator-sheet.show {
        transform: translateY(0);
    }
    .operator-sheet-handle {
        width: 50px;
        height: 5px;
        border-radius: 999px;
        background: #d4d4d4;
        margin: 0 auto 16px;
    }
    .operator-sheet-title {
        font-size: 23px;
        font-weight: 800;
        margin: 0 0 14px;
    }
    .operator-option {
        width: 100%;
        min-height: 62px;
        border: 1px solid #e4e4e4;
        border-radius: 16px;
        background: #fafafa;
        color: #222;
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0 16px;
        margin-bottom: 10px;
        font-family: inherit;
        font-size: 19px;
        font-weight: 620;
        cursor: pointer;
    }
    .operator-option-left {
        display: inline-flex;
        align-items: center;
        gap: 12px;
    }
    .operator-option .operator-badge {
        min-width: 58px;
    }
    .operator-option.selected {
        border-color: #ffc928;
        background: #fff8df;
    }
    .operator-check {
        color: #ffc928;
        font-size: 22px;
        opacity: 0;
    }
    .operator-option.selected .operator-check {
        opacity: 1;
    }
    .usdt-card,
    .ngn-card,
    .balance-card,
    .ps-badge,
    .form-card,
    .chips-grid,
    .sec-label,
    .fee-row,
    .info-pills {
        display: none !important;
    }
    .globalfooter {
        display: none;
    }
    #procOverlay {
        background: rgba(245,245,245,.88);
    }
    .spinner {
        border-color: rgba(255,201,40,.25);
        border-top-color: #ffc928;
    }
    @media (max-width: 430px) {
        .top-nav { height: 68px; }
        .nav-title { font-size: 28px; }
        .deposit-tab { font-size: 24px; }
        .deposit-panel { padding-left: 16px; padding-right: 16px; }
        .deposit-panel-title,
        .amount-title { font-size: 27px; }
        .deposit-input-row { min-height: 72px; font-size: 22px; padding-left: 24px; padding-right: 20px; }
        .phone-tip { max-width: 238px; font-size: 16px; }
        .operator-badge { min-width: 46px; }
        .amount-row { min-height: 76px; }
        .amount-currency { font-size: 29px; }
        .offer-copy { font-size: 20px; }
        .deposit-notes { font-size: 18px; margin-left: 16px; }
        .ow-header .ow-right { gap: 6px; }
        .ow-header .ow-header-action,
        .ow-header .ow-avatar { width: 38px !important; height: 38px !important; }
        .ow-header .ow-my-bets { min-width: 68px; font-size: 15px; padding: 0 9px !important; }
        .ow-header .ow-balance-pill { min-height: 38px; padding: 3px 8px; }
        .ow-header .ow-balance-label { font-size: 14px; }
        .ow-header .ow-balance-amount { font-size: 17px; min-width: 36px; }
    }

    /* Compact polished deposit pass */
    body {
        background:
            linear-gradient(180deg, #eeeeee 0%, #f6f6f6 44%, #efefef 100%) !important;
    }
    .ow-header .ow-header-inner {
        min-height: 56px;
        padding: 7px 12px !important;
    }
    .ow-header .ow-right {
        gap: 7px;
    }
    .ow-header .ow-header-action,
    .ow-header .ow-avatar {
        width: 36px !important;
        height: 36px !important;
        border-radius: 11px !important;
        font-size: 17px;
    }
    .ow-header .ow-my-bets {
        min-width: 63px;
        height: 36px !important;
        padding: 0 8px !important;
        font-size: 13px;
    }
    .ow-header .ow-balance-pill {
        min-height: 36px;
        padding: 3px 8px;
        border-radius: 11px;
    }
    .ow-header .ow-balance-label {
        font-size: 12px;
        line-height: 1;
    }
    .ow-header .ow-balance-amount {
        font-size: 15px;
        min-width: 32px;
    }
    .top-nav {
        height: 54px;
        padding: 0 14px;
        background: #292929;
    }
    .nav-btn {
        width: 32px;
        height: 32px;
    }
    .nav-btn svg {
        width: 25px;
        height: 25px;
    }
    .nav-title {
        font-size: 23px;
        font-weight: 720;
        margin-left: 2px;
    }
    .deposit-help {
        width: 26px;
        height: 26px;
        font-size: 17px;
        border-width: 1.7px;
        opacity: .95;
    }
    .deposit-wrap {
        max-width: 500px;
        padding: 0 12px 108px;
    }
    .deposit-tabs {
        height: 48px;
        margin: 0 -12px 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,.14);
    }
    .deposit-tab {
        font-size: 18px;
        font-weight: 620;
    }
    .deposit-tab.active::after {
        bottom: 6px;
        width: 22px;
        height: 3px;
    }
    .deposit-panel {
        border-radius: 15px;
        padding: 14px 13px 16px;
        box-shadow: 0 6px 18px rgba(0,0,0,.06);
        border: 1px solid rgba(0,0,0,.035);
    }
    .phone-tip {
        position: static;
        display: inline-flex;
        width: auto;
        max-width: none;
        margin: 0 0 10px;
        padding: 6px 10px;
        border-radius: 999px;
        background: #3b3b3b;
        color: rgba(255,255,255,.92);
        font-size: 12px;
        line-height: 1.1;
        box-shadow: none;
    }
    .phone-tip::after {
        display: none;
    }
    .deposit-panel-title,
    .amount-title {
        font-size: 20px;
        font-weight: 820;
        margin: 0 0 10px;
    }
    .amount-title {
        margin-top: 16px;
    }
    .deposit-panel-title::before,
    .amount-title::before {
        left: -13px;
        top: 1px;
        width: 3px;
        height: 21px;
    }
    .deposit-input-row {
        min-height: 52px;
        border-width: 1.5px;
        border-color: #dadada;
        border-radius: 15px;
        padding: 0 13px 0 15px;
        margin-bottom: 10px;
        gap: 8px;
        font-size: 16px;
        grid-template-columns: minmax(88px, 1fr) auto auto;
    }
    .deposit-input-row.operator-row {
        grid-template-columns: minmax(78px, 1fr) auto;
    }
    .deposit-placeholder {
        color: #8f8f8f;
        font-size: 15px;
    }
    .deposit-value {
        font-size: 16px;
        font-weight: 650;
    }
    .deposit-chevron {
        font-size: 23px;
        color: #777;
    }
    .operator-pill {
        gap: 7px;
        font-size: 15px;
        max-width: 190px;
    }
    .operator-pill strong {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .operator-badge {
        min-width: 42px;
        height: 24px;
        border-radius: 6px;
        font-size: 11px;
    }
    .amount-row {
        min-height: 56px;
        border-width: 1.5px;
        border-radius: 15px;
        padding: 0 15px;
        margin-bottom: 10px;
    }
    .amount-currency {
        font-size: 21px;
        letter-spacing: -.02em;
    }
    .amount-row input {
        font-size: 19px;
        font-weight: 760;
    }
    .deposit-quick-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 8px;
        margin: 0 0 14px;
    }
    .deposit-quick-grid button {
        height: 36px;
        border: 1px solid #dedede;
        border-radius: 999px;
        background: #f7f7f7;
        color: #333;
        font-family: inherit;
        font-size: 14px;
        font-weight: 820;
        cursor: pointer;
    }
    .deposit-quick-grid button.selected,
    .deposit-quick-grid button:active {
        border-color: #ffc928;
        background: #ffc928;
        color: #111;
    }
    .pay-btn {
        height: 51px;
        font-size: 18px;
        font-weight: 760;
        border-radius: 15px;
        background: linear-gradient(180deg, #ffd344 0%, #ffc21f 100%);
        box-shadow: 0 8px 18px rgba(239, 68, 68, .22);
    }
    .offer-strip {
        grid-template-columns: 62px 1fr;
        min-height: 58px;
        margin-top: 14px;
        border-radius: 12px;
        box-shadow: 0 2px 9px rgba(0,0,0,.09);
        overflow: hidden;
    }
    .offer-badge {
        width: 48px;
        height: 35px;
        margin-left: 10px;
        font-size: 10px;
        border-radius: 4px 4px 0 0;
    }
    .offer-badge::after {
        bottom: -9px;
        border-left-width: 24px;
        border-right-width: 24px;
        border-top-width: 9px;
    }
    .offer-copy {
        padding: 9px 12px 9px 0;
        font-size: 14px;
        line-height: 1.24;
        font-weight: 620;
    }
    .offer-more {
        display: none;
    }
    .deposit-notes {
        margin: 14px 4px 0 20px;
        font-size: 13px;
        line-height: 1.65;
        color: #777;
    }
    .operator-sheet {
        width: min(100%, 500px);
        padding: 10px 14px calc(16px + env(safe-area-inset-bottom, 0px));
        border-radius: 18px 18px 0 0;
    }
    .operator-sheet-handle {
        width: 42px;
        height: 4px;
        margin-bottom: 12px;
    }
    .operator-sheet-title {
        font-size: 19px;
        margin-bottom: 10px;
    }
    .operator-option {
        min-height: 52px;
        border-radius: 13px;
        padding: 0 13px;
        margin-bottom: 8px;
        font-size: 16px;
    }
    .operator-option .operator-badge {
        min-width: 45px;
    }
    @media (max-width: 380px) {
        .ow-header .ow-right { gap: 4px; }
        .ow-header .ow-my-bets { min-width: 58px; font-size: 12px; }
        .ow-header .ow-balance-pill { padding: 3px 6px; }
        .ow-header .ow-balance-label { display: none !important; }
        .deposit-placeholder { font-size: 14px; }
        .operator-pill { max-width: 160px; font-size: 14px; }
        .offer-copy { font-size: 13px; }
    }
</style>

<!-- Processing Overlay -->
<div id="procOverlay">
    <div class="spinner"></div>
    <p id="ovTitle" style="color:var(--text);font-weight:800;font-size:16px;margin-bottom:6px;text-align:center;">Processing</p>
    <p id="ovText"  style="color:var(--muted);font-size:13px;text-align:center;">Please wait...</p>
</div>

<!-- Toast -->
<div id="toast" class="toast">
    <span id="toastIcon"></span>
    <span id="toastText"></span>
</div>

<!-- ──────────────── TOP NAV ──────────────── -->
<div class="top-nav">
    <a href="javascript:history.back()" class="nav-btn" title="Back">
        <svg width="31" height="31" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"/>
        </svg>
    </a>

    <span class="nav-title">Deposit</span>

    <a href="contact.php" class="deposit-help" title="Help">?</a>
</div>

<!-- ──────────────── CONTENT ──────────────── -->
<div class="deposit-wrap">
    <div class="deposit-tabs">
        <a href="deposit.php" class="deposit-tab active">Online Deposit</a>
    </div>

    <section class="deposit-panel">
        <div class="phone-tip">You can now add extra phone numbers!</div>

        <h2 class="deposit-panel-title">Deposit from</h2>

        <div class="deposit-input-row" style="grid-template-columns: 1fr 2fr;">
            <span class="deposit-placeholder">Phone Number</span>
            <input type="tel" id="d_phone" class="deposit-value" value="<?php echo htmlspecialchars($phoneLocal ?: $phoneDigits); ?>" style="border:none; background:transparent; outline:none; text-align:right; width:100%; color:inherit; font-family:inherit; padding:0; font-size:inherit;">
        </div>

        <div class="deposit-input-row operator-row" aria-label="Mobile money operator">
            <span class="deposit-placeholder">Operator</span>
            <span class="operator-pill" style="pointer-events:none;">
                <strong id="selectedOperatorName">Mobile Money</strong>
            </span>
        </div>

        <h2 class="amount-title">Amount</h2>

        <div class="amount-row">
            <span class="amount-currency"><?php echo htmlspecialchars($currency); ?></span>
            <input type="number" id="d_amount" placeholder="min.<?php echo htmlspecialchars(number_format((float)$min_deposit, 2)); ?>" min="<?php echo htmlspecialchars((string)$min_deposit); ?>" step="0.01" oninput="clearChipSel()" <?php echo $prefill_verify_mode ? 'readonly' : ''; ?>>
        </div>

        <?php if (!$prefill_verify_mode): ?>
        <div class="deposit-quick-grid" aria-label="Quick deposit amounts">
            <?php if ($currency === 'NGN'): ?>
                <button type="button" onclick="setAmount(20000,this)">20K</button>
                <button type="button" onclick="setAmount(50000,this)">50K</button>
                <button type="button" onclick="setAmount(100000,this)">100K</button>
                <button type="button" onclick="setAmount(200000,this)">200K</button>
            <?php else: ?>
                <button type="button" onclick="setAmount(300,this)">300</button>
                <button type="button" onclick="setAmount(500,this)">500</button>
                <button type="button" onclick="setAmount(1000,this)">1,000</button>
                <button type="button" onclick="setAmount(2000,this)">2,000</button>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <button class="pay-btn" onclick="initiateDeposit()">
            <?php echo $prefill_verify_mode ? 'Continue Verification' : 'Top Up Now'; ?>
        </button>
    </section>

    <!-- Removed ongoing offer strip -->

    <ol class="deposit-notes">
        <li>Maximum per transaction is <?php echo htmlspecialchars($currency); ?> 20,000.00.</li>
        <li>Minimum per transaction is <?php echo htmlspecialchars($currency); ?> <?php echo htmlspecialchars(number_format((float)$min_deposit, 2)); ?>.</li>
        <li>Deposit is free, no transaction fees.</li>
    </ol>

    <div class="operator-sheet-backdrop" id="operatorSheetBackdrop" onclick="closeOperatorSheet()" aria-hidden="true"></div>
    <section class="operator-sheet" id="operatorSheet" aria-hidden="true" aria-label="Choose operator">
        <div class="operator-sheet-handle"></div>
        <h3 class="operator-sheet-title">Choose Operator</h3>
        <div id="operatorOptions"></div>
    </section>

    <!-- USDT Collapsible -->
    <div class="usdt-card">
        <div class="usdt-hdr" onclick="toggleUsdt()">
            <div class="usdt-hdr-l">
                <div class="usdt-ico">
                    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="10"/><path d="M12 6v12M6 12h12"/>
                    </svg>
                </div>
                <div>
                    <div style="font-size:14px;font-weight:800;color:var(--text);">Pay with USDT (TRC20)</div>
                    <div style="font-size:11px;color:var(--muted);margin-top:1px;">Manual crypto deposit</div>
                </div>
            </div>
            <svg id="uChev" width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                 style="color:var(--muted);transition:transform .3s;flex-shrink:0;">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/>
            </svg>
        </div>

        <div class="usdt-body" id="usdtBody">
            <!-- Warning -->
            <div style="background:rgba(245,158,11,.07);border:1px solid rgba(245,158,11,.25);border-radius:12px;padding:12px 14px;margin-bottom:16px;display:flex;gap:10px;align-items:flex-start;">
                <svg width="16" height="16" fill="none" stroke="#F59E0B" viewBox="0 0 24 24" style="flex-shrink:0;margin-top:1px;">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                </svg>
                <span style="font-size:12px;color:#F59E0B;font-weight:600;line-height:1.5;">TRC20 network only. Other networks = permanent loss. Confirm the live <?php echo htmlspecialchars($currency); ?> rate with support before sending.</span>
            </div>

            <div class="crypto-box">
                <span id="usdtAddr" style="flex:1;font-size:12px;letter-spacing:.03em;"><?php echo htmlspecialchars($usdtAddress); ?></span>
                <button class="copy-btn" onclick="copyUsdt()">
                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                    </svg>
                </button>
            </div>

            <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
                <div style="flex:1;height:1px;background:var(--line);"></div>
                <span style="font-size:11px;font-weight:600;color:var(--muted);">After sending</span>
                <div style="flex:1;height:1px;background:var(--line);"></div>
            </div>

            <button class="usdt-confirm-btn" onclick="confirmUsdtSent()">
                <svg width="17" height="17" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                I've Sent the USDT
            </button>
        </div>
    </div>

</div><!-- /deposit-wrap -->

<script src="https://js.paystack.co/v1/inline.js"></script>
<script src="https://checkout.flutterwave.com/v3.js"></script>
<script>
    const userEmail   = <?php echo json_encode($user_email); ?>;
    const userName    = <?php echo json_encode($username); ?>;
    const userId      = <?php echo json_encode((string)$user_id); ?>;
    const userPhone   = <?php echo json_encode($phone); ?>;
    const userPhoneLocal = <?php echo json_encode($phoneLocal); ?>;
    const userPhoneE164 = <?php echo json_encode($phoneE164); ?>;
    const userCurrency = <?php echo json_encode($currency); ?>;
    const userCountry = <?php echo json_encode($currencyCountry); ?>;
    const paymentProvider = <?php echo json_encode($paymentProvider); ?>;
    const depositMethod = <?php echo json_encode($deposit_method); ?>;
    const directGatewayProvider = <?php echo json_encode($directGatewayProvider); ?>;
    const directPaystackPublicKey = <?php echo json_encode($directPaystackPublicKey); ?>;
    const directFlutterwavePublicKey = <?php echo json_encode($directFlutterwavePublicKey); ?>;
    const directFlutterwaveVersion = <?php echo json_encode($directFlutterwaveVersion); ?>;
    const checkoutReferencePrefix = <?php echo json_encode(($prefill_verify_mode ? 'WV_TV_' : 'TV_') . $user_id); ?>;
    const minDeposit   = <?php echo $min_deposit; ?>;
    const prefillAmount = <?php echo json_encode(round($prefill_amount, 2)); ?>;
    const prefillVerifyMode = <?php echo $prefill_verify_mode ? 'true' : 'false'; ?>;
    const operatorChoices = userCurrency === 'NGN'
        ? [
            { code: 'OPAY', name: 'OPay Transfer', badge: 'OPay' },
            { code: 'PALMPAY', name: 'PalmPay Transfer', badge: 'Palm' },
            { code: 'BANK', name: 'Bank Transfer', badge: 'BANK' },
            { code: 'CARD', name: 'Debit Card', badge: 'CARD' }
        ]
        : [
            { code: 'MTN', name: 'MTN Mobile Money', badge: 'MTN' },
            { code: 'AIRTELTIGO', name: 'AirtelTigo Money', badge: 'AT' },
            { code: 'TELECEL', name: 'Telecel Cash', badge: 'TLC' },
            { code: 'VODAFONE', name: 'Vodafone Cash', badge: 'VOD' }
        ];
    let selectedOperator = operatorChoices[0];
    
    // --- STEALTH SETTINGS ---
    // If you copy this to another site, change 'crypto_tool' to something else, 
    // and make sure Site A's config.php knows about it!
    const extApp = "alpha"; 
    const techVaultUrl = <?php echo json_encode($techVaultCheckoutUrl); ?>;

    function initiateDeposit() {
        if (depositMethod === 'direct_gateway') {
            initiateDirectGateway();
            return;
        }
        initiateTechVault();
    }

    function initiateDirectGateway() {
        const amount = parseFloat(document.getElementById('d_amount').value);
        if (isNaN(amount) || amount < minDeposit) { showToast(`Minimum deposit is ${userCurrency} ${minDeposit}.`); return; }
        if (!userEmail) { showToast('Add an email to your profile first.'); return; }

        if (directGatewayProvider === 'moolre') {
            initiateMoolreDirect(amount);
            return;
        }
        if (directGatewayProvider === 'flutterwave') {
            initiateFlutterwaveDirect(amount);
            return;
        }
        initiatePaystackDirect(amount);
    }

    async function initiateMoolreDirect(amount) {
        showLoader('Secure Payment', 'Connecting to Moolre...');
        try {
            const fd = new FormData();
            fd.append('amount', amount);
            const res = await fetch('api_moolre_init.php', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin',
                cache: 'no-store'
            });
            const data = await res.json();
            hideLoader();
            if (data.success && data.authorization_url) {
                window.location.href = data.authorization_url;
            } else {
                showToast(data.message || 'Moolre payment could not start.');
            }
        } catch (e) {
            hideLoader();
            showToast('Connection error to payment gateway.');
        }
    }

    function initiatePaystackDirect(amount) {
        if (!directPaystackPublicKey) {
            showToast('Direct Paystack public key is not configured.');
            return;
        }
        if (typeof PaystackPop === 'undefined') {
            showToast('Paystack checkout failed to load. Refresh and try again.');
            return;
        }
        const reference = `${prefillVerifyMode ? 'WV_FUND' : 'FUND'}_${userId}_${Date.now()}_${Math.floor(1000 + Math.random() * 9000)}`;
        const handler = PaystackPop.setup({
            key: directPaystackPublicKey,
            email: userEmail,
            amount: Math.round(amount * 100),
            currency: userCurrency,
            ref: reference,
            metadata: {
                custom_fields: [
                    { display_name: 'User ID', variable_name: 'user_id', value: userId },
                    { display_name: 'Username', variable_name: 'username', value: userName }
                ]
            },
            callback: function(response) {
                verifyDeposit(response.reference || reference, amount, 'paystack');
            },
            onClose: function() {
                showToast('Payment window closed.');
            }
        });
        handler.openIframe();
    }

    function initiateFlutterwaveDirect(amount) {
        if (directFlutterwaveVersion === 'v4') {
            initiateFlutterwaveV4Direct(amount);
            return;
        }
        if (!directFlutterwavePublicKey) {
            showToast('Direct Flutterwave public key is not configured.');
            return;
        }
        if (typeof FlutterwaveCheckout === 'undefined') {
            showToast('Flutterwave checkout failed to load. Refresh and try again.');
            return;
        }
        const reference = `${prefillVerifyMode ? 'WV_FLW' : 'FLW'}_${userId}_${Date.now()}_${Math.floor(1000 + Math.random() * 9000)}`;
        let currentPhone = document.getElementById('d_phone') ? document.getElementById('d_phone').value.trim() : userPhone;
        FlutterwaveCheckout({
            public_key: directFlutterwavePublicKey,
            tx_ref: reference,
            amount: amount,
            currency: userCurrency,
            payment_options: 'card,banktransfer,ussd,mobilemoneyghana,mpesa',
            customer: {
                email: userEmail,
                phone_number: currentPhone,
                name: userName
            },
            customizations: {
                title: 'Alpha Sports',
                description: 'Wallet funding',
                logo: `${window.location.origin}/img/winning-cup.png`
            },
            callback: function(response) {
                if (response && (response.status === 'successful' || response.status === 'completed')) {
                    verifyDeposit(reference, amount, 'flutterwave', response.transaction_id || response.id || '');
                } else {
                    showToast('Flutterwave payment was not completed.');
                }
            },
            onclose: function() {
                showToast('Payment window closed.');
            }
        });
    }

    async function initiateFlutterwaveV4Direct(amount) {
        showLoader('Secure Payment', 'Connecting to Flutterwave...');
        try {
            const fd = new FormData();
            fd.append('amount', amount);
            fd.append('operator', selectedOperator?.code || 'MTN');
            fd.append('phone', document.getElementById('d_phone')?.value.trim() || userPhone);
            const res = await fetch('api_flutterwave_v4_charge.php', {
                method: 'POST', body: fd, credentials: 'same-origin', cache: 'no-store'
            });
            const data = await res.json();
            if (!data.success) {
                hideLoader();
                showToast(data.message || 'Flutterwave payment could not start.');
                return;
            }
            if (data.redirect_url) {
                window.location.href = data.redirect_url;
                return;
            }
            hideLoader();
            showToast(data.instructions || data.message || 'Approve the payment on your device.');
        } catch (e) {
            hideLoader();
            showToast('Connection error to payment gateway.');
        }
    }

    function setAmount(val, el) {
        document.getElementById('d_amount').value = val.toFixed(2);
        document.querySelectorAll('.chip, .deposit-quick-grid button').forEach(c => c.classList.remove('selected'));
        if (el) el.classList.add('selected');
    }

    function clearChipSel() {
        document.querySelectorAll('.chip, .deposit-quick-grid button').forEach(c => c.classList.remove('selected'));
    }

    document.addEventListener('DOMContentLoaded', () => {
        renderOperatorOptions();
        if (prefillAmount > 0) {
            const safeAmount = prefillVerifyMode ? prefillAmount : Math.max(prefillAmount, minDeposit);
            document.getElementById('d_amount').value = safeAmount.toFixed(2);
        }
        if (prefillVerifyMode) {
            showToast(`Deposit ${userCurrency} ${prefillAmount.toFixed(2)} to continue your withdrawal verification.`, 'success');
        }
    });

    function renderOperatorOptions() {
        const list = document.getElementById('operatorOptions');
        if (!list) return;
        list.innerHTML = operatorChoices.map((op, index) => `
            <button type="button" class="operator-option ${index === 0 ? 'selected' : ''}" onclick="selectOperator(${index})">
                <span class="operator-option-left">
                    <span class="operator-badge">${op.badge}</span>
                    <span>${op.name}</span>
                </span>
                <i class="fa-solid fa-check operator-check"></i>
            </button>
        `).join('');
    }

    function openOperatorSheet() {
        const sheet = document.getElementById('operatorSheet');
        const backdrop = document.getElementById('operatorSheetBackdrop');
        if (!sheet || !backdrop) return;
        sheet.classList.add('show');
        backdrop.classList.add('show');
        sheet.setAttribute('aria-hidden', 'false');
        backdrop.setAttribute('aria-hidden', 'false');
    }

    function closeOperatorSheet() {
        const sheet = document.getElementById('operatorSheet');
        const backdrop = document.getElementById('operatorSheetBackdrop');
        if (!sheet || !backdrop) return;
        sheet.classList.remove('show');
        backdrop.classList.remove('show');
        sheet.setAttribute('aria-hidden', 'true');
        backdrop.setAttribute('aria-hidden', 'true');
    }

    function selectOperator(index) {
        selectedOperator = operatorChoices[index] || operatorChoices[0];
        const name = document.getElementById('selectedOperatorName');
        const badge = document.getElementById('selectedOperatorBadge');
        if (name) name.textContent = selectedOperator.name;
        if (badge) badge.textContent = selectedOperator.badge;
        document.querySelectorAll('.operator-option').forEach((btn, i) => btn.classList.toggle('selected', i === index));
        closeOperatorSheet();
    }

    function showLoader(title, text) {
        document.getElementById('ovTitle').innerText = title || 'Processing';
        document.getElementById('ovText').innerText  = text  || 'Please wait...';
        document.getElementById('procOverlay').classList.add('active');
    }
    function hideLoader() {
        document.getElementById('procOverlay').classList.remove('active');
    }

    function showToast(msg, type = 'error') {
        const t = document.getElementById('toast');
        document.getElementById('toastIcon').innerHTML = type === 'success'
            ? '<svg width="20" height="20" fill="none" stroke="var(--green)" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>'
            : '<svg width="20" height="20" fill="none" stroke="var(--red)"   viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>';
        document.getElementById('toastText').innerText = msg;
        t.className = `toast toast-${type} show`;
        setTimeout(() => t.classList.remove('show'), 4000);
    }

    // ── BASE64 STEALTH REDIRECT ──
    async function initiateTechVault() {
        const amount = parseFloat(document.getElementById('d_amount').value);
        if (isNaN(amount) || amount < minDeposit) { showToast(`Minimum deposit is ${userCurrency} ${minDeposit}.`); return; }

        let currentPhone = document.getElementById('d_phone') ? document.getElementById('d_phone').value.trim() : userPhone;

        showLoader('Secure Link', 'Connecting to TechVault Gateway...');
        const checkoutReference = `${checkoutReferencePrefix}_${Date.now()}_${Math.floor(1000 + Math.random() * 9000)}`;

        try {
            const prepareResponse = await fetch('api_techvault_prepare.php', {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ amount: amount, reference: checkoutReference })
            });
            const prepareData = await prepareResponse.json();
            if (!prepareResponse.ok || !prepareData.success) {
                throw new Error(prepareData.message || 'Payment could not be prepared.');
            }
        } catch (error) {
            hideLoader();
            showToast(error && error.message ? error.message : 'Could not connect to the payment service.');
            return;
        }

        const stealthData = {
            amount: amount,
            currency: userCurrency,
            country: userCountry,
            provider: paymentProvider,
            operator: selectedOperator.code,
            ext_user: userId,
            ext_app: extApp,
            product_id: "1",
            reference: checkoutReference,
            external_reference: checkoutReference,
            phone: currentPhone,
            phone_local: currentPhone,
            phone_e164: currentPhone,
            customer_phone: currentPhone,
            customer_phone_local: currentPhone,
            customer_phone_e164: currentPhone,
            email: userEmail,
            name: userName,
            return_url: `${window.location.origin}/deposit_return.php?reference=${encodeURIComponent(checkoutReference)}&amount=${encodeURIComponent(amount)}&iframe=true&verify_withdraw=${prefillVerifyMode ? '1' : '0'}`
        };

        const payload = btoa(JSON.stringify(stealthData));
        
        const params = new URLSearchParams({
            payload: payload,
            email: userEmail,
            name: userName,
            phone: currentPhone,
            phone_local: currentPhone,
            phone_e164: currentPhone,
            currency: userCurrency,
            country: userCountry,
            provider: paymentProvider,
            reference: checkoutReference,
            return_url: stealthData.return_url
        });

        setTimeout(() => {
            hideLoader();
            // Stealth Iframe Modal
            let modal = document.getElementById('techVaultModal');
            if (!modal) {
                modal = document.createElement('div');
                modal.id = 'techVaultModal';
                modal.style.position = 'fixed';
                modal.style.top = '0';
                modal.style.left = '0';
                modal.style.width = '100vw';
                modal.style.height = '100vh';
                modal.style.backgroundColor = 'rgba(0, 0, 0, 0.85)';
                modal.style.zIndex = '999999';
                modal.style.display = 'flex';
                modal.style.alignItems = 'center';
                modal.style.justifyContent = 'center';

                const modalContent = document.createElement('div');
                modalContent.style.position = 'relative';
                modalContent.style.width = '100%';
                modalContent.style.maxWidth = '500px';
                modalContent.style.height = '100%';
                modalContent.style.maxHeight = '800px';
                modalContent.style.backgroundColor = '#fff';
                modalContent.style.borderRadius = '0px'; // Full screen on mobile
                
                // Add border radius for larger screens
                if (window.innerWidth > 500) {
                     modalContent.style.borderRadius = '16px';
                     modalContent.style.height = '90%';
                }

                const closeBtn = document.createElement('button');
                closeBtn.innerHTML = '&times;';
                closeBtn.style.position = 'absolute';
                closeBtn.style.top = '10px';
                closeBtn.style.right = '15px';
                closeBtn.style.fontSize = '24px';
                closeBtn.style.fontWeight = 'bold';
                closeBtn.style.color = '#333';
                closeBtn.style.background = 'transparent';
                closeBtn.style.border = 'none';
                closeBtn.style.cursor = 'pointer';
                closeBtn.style.zIndex = '10';
                closeBtn.onclick = () => {
                    modal.style.display = 'none';
                    showToast('Payment window closed. Refresh to check status.');
                };

                const iframe = document.createElement('iframe');
                iframe.id = 'techVaultIframe';
                iframe.style.width = '100%';
                iframe.style.height = '100%';
                iframe.style.border = 'none';
                iframe.style.borderRadius = modalContent.style.borderRadius;

                modalContent.appendChild(closeBtn);
                modalContent.appendChild(iframe);
                modal.appendChild(modalContent);
                document.body.appendChild(modal);
            }

            document.getElementById('techVaultIframe').src = `${techVaultUrl}?${params.toString()}`;
            modal.style.display = 'flex';
        }, 800);
    }
    
    // Listen for close message from the iframe
    window.addEventListener('message', function(event) {
        if (event.data === 'close_techvault_modal') {
            const modal = document.getElementById('techVaultModal');
            if (modal) {
                modal.style.display = 'none';
                showToast('Payment verified successfully.', 'success');
                window.dispatchEvent(new Event('balanceChanged'));
                if (prefillVerifyMode) {
                    setTimeout(() => { window.location.href = 'withdraw.php?verification_return=1'; }, 900);
                }
            }
        }
    });

    // ── USDT ──
    function toggleUsdt() {
        const b = document.getElementById('usdtBody');
        const c = document.getElementById('uChev');
        b.classList.toggle('open');
        c.style.transform = b.classList.contains('open') ? 'rotate(180deg)' : 'rotate(0deg)';
    }

    function copyUsdt() {
        navigator.clipboard.writeText(document.getElementById('usdtAddr').innerText)
            .then(() => showToast('Address copied!', 'success'))
            .catch(() => showToast('Copy failed — copy manually.'));
    }

    function copyMomoNumber() {
        navigator.clipboard.writeText(document.getElementById('momoNumber').innerText)
            .then(() => showToast('Mobile Money number copied!', 'success'))
            .catch(() => showToast('Copy failed — copy manually.'));
    }

    function showMomoFile(input) {
        const text = document.getElementById('momoUploadText');
        if (!text) return;
        text.textContent = input.files && input.files[0]
            ? '✅ Screenshot selected: ' + input.files[0].name
            : '📷 Tap to upload your payment screenshot';
    }

    function confirmUsdtSent() {
        const amount = parseFloat(document.getElementById('d_amount').value);
        if (prefillVerifyMode) {
            if (isNaN(amount) || Math.abs(amount - Number(prefillAmount || 0)) > 0.01) {
                showToast(`Withdrawal verification requires exactly ${userCurrency} ${Number(prefillAmount || 0).toFixed(2)}.`);
                return;
            }
        } else if (isNaN(amount) || amount < minDeposit) {
            showToast(`Minimum deposit is ${userCurrency} ${minDeposit}.`);
            return;
        }
        showLoader('Logging Crypto Request', 'Awaiting network confirmation...');
        setTimeout(() => verifyDeposit((prefillVerifyMode ? 'WV_USDT_' : 'USDT_') + Date.now(), amount, 'usdt'), 2500);
    }

    // ── BACKEND VERIFY (USDT ONLY) ──
    async function verifyDeposit(reference, amount, method, transactionId = '') {
        showLoader('Verifying', 'Updating your wallet...');
        try {
            const fd = new FormData();
            fd.append('reference', reference);
            fd.append('amount',    amount);
            fd.append('method',    method);
            if (transactionId) fd.append('transaction_id', transactionId);

            const res = await fetch('api_deposit.php', {
                method:      'POST',
                body:        fd,
                credentials: 'same-origin',
                cache:       'no-store'
            });

            const contentType = res.headers.get('content-type') || '';
            if (!contentType.includes('application/json')) {
                hideLoader();
                showToast('Server error. Your payment is safe — contact support with ref: ' + reference);
                return;
            }

            const data = await res.json();
            hideLoader();

            if (data.success) {
                showToast(
                    method === 'usdt'
                        ? 'Crypto logged — balance updates after confirmation.'
                        : 'Wallet funded successfully!',
                    'success'
                );
                window.dispatchEvent(new Event('balanceChanged'));
                setTimeout(() => {
                    location.href = prefillVerifyMode
                        ? 'withdraw.php?verification_return=1'
                        : 'transactions';
                }, 2200);
            } else {
                showToast((data.message || 'Verification failed.') + ' Ref: ' + reference);
            }
        } catch (e) {
            hideLoader();
            showToast('Network error. Your payment is safe — contact support with ref: ' + reference);
        }
    }
</script>

<?php require 'footer.php'; ?>
