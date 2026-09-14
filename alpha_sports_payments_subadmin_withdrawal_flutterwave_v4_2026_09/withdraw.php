<?php
// withdraw.php — Alpha Sports Premium Withdrawal System
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/currency_helper.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

$user_id     = $_SESSION['user_id'];
$balance      = 0.00;
$aml_verified = 0;
$user_phone   = '';
$user_name    = '';
$user_country = '';
$is_agent_user = 0;
$linked_agent_id = 0;

// Admin check
try {
    $_admStmt = $pdo->prepare("SELECT value FROM admin_settings WHERE `key`='admin_user_ids'");
    $_admStmt->execute();
    $rawIds = trim($_admStmt->fetchColumn() ?: '');
    $adminIds = $rawIds ? array_filter(array_map('trim', explode(',', $rawIds))) : [];
} catch (Exception $e) {
    $adminIds = [];
}
if (!in_array('1', $adminIds)) $adminIds[] = '1';
$is_admin = in_array((string)$user_id, $adminIds);

// Fetch user data
try {
    $columns = ['balance', 'username'];
    if (ps_table_column_exists($pdo, 'users', 'phone')) {
        $columns[] = 'phone';
    }
    if (ps_table_column_exists($pdo, 'users', 'country')) {
        $columns[] = 'country';
    }
    if (ps_table_column_exists($pdo, 'users', 'aml_verified')) {
        $columns[] = 'aml_verified';
    }
    if (ps_table_column_exists($pdo, 'users', 'is_agent')) {
        $columns[] = 'is_agent';
    }
    if (ps_table_column_exists($pdo, 'users', 'linked_agent_id')) {
        $columns[] = 'linked_agent_id';
    }

    $stmt = $pdo->prepare("SELECT " . implode(', ', $columns) . " FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user) {
        $balance      = (float)($user['balance']      ?? 0);
        $aml_verified = (int)  ($user['aml_verified'] ?? 0);
        $user_name    = (string)($user['username']    ?? '');
        $user_phone   = (string)($user['phone']       ?? '');
        $user_country = (string)($user['country']     ?? '');
        $is_agent_user = (int)($user['is_agent'] ?? 0);
        $linked_agent_id = (int)($user['linked_agent_id'] ?? 0);
        if ($is_agent_user === 1 && $linked_agent_id > 0) {
            $aml_verified = 1;
        }
        $_SESSION['balance'] = $balance;
    }
} catch (Exception $e) {
    $balance      = (float)($_SESSION['balance'] ?? 0);
    $aml_verified = 0;
}

// Withdrawal verification progress for normal users.
$page_balance = (float)$balance;
$detectedCurrency = ps_detect_currency_from_user(['phone' => $user_phone, 'country' => $user_country]);
ps_sync_currency_session($detectedCurrency);
$user_currency = $detectedCurrency['code'] ?? ($_SESSION['currency'] ?? 'GHS');
$is_nigerian_withdrawal = ($user_currency === 'NGN');
$saved_phone_source = trim($user_phone) !== '' ? $user_phone : $user_name;
$saved_phone_digits = preg_replace('/\D+/', '', $saved_phone_source);
$saved_phone_digits = preg_replace('/^(233|234)0/', '$1', $saved_phone_digits);
if (str_starts_with($saved_phone_digits, '233') && strlen($saved_phone_digits) === 12) {
    $saved_phone_digits = '0' . substr($saved_phone_digits, 3);
} elseif (str_starts_with($saved_phone_digits, '234') && strlen($saved_phone_digits) === 13) {
    $saved_phone_digits = '0' . substr($saved_phone_digits, 3);
} elseif (strlen($saved_phone_digits) === 9 && $user_currency === 'GHS') {
    $saved_phone_digits = '0' . $saved_phone_digits;
} elseif (strlen($saved_phone_digits) > 10) {
    $saved_phone_digits = substr($saved_phone_digits, -10);
}
$masked_saved_phone = '';
if (strlen($saved_phone_digits) === 10) {
    $masked_saved_phone = substr($saved_phone_digits, 0, 3) . '***' . substr($saved_phone_digits, -4);
}
$configured_verification_amount = ps_withdraw_verification_amount($pdo, $user_currency);
if ($aml_verified !== 1 && !($is_agent_user === 1 && $linked_agent_id > 0)) {
    ps_start_withdraw_verification(
        $pdo,
        (int)$user_id,
        $user_currency,
        $configured_verification_amount,
        date('Y-m-d H:i:s', time() - 7200)
    );
}
$verificationState = ps_withdraw_verification_state($pdo, (int)$user_id, $user_currency);
$verification_amount = (float)($verificationState['amount'] ?? $configured_verification_amount);
$verification_display_step = (int)($verificationState['display_step'] ?? 1);
$verification_total_steps = (int)($verificationState['total_steps'] ?? 4);
$verification_completed_deposits = (int)($verificationState['completed_deposits'] ?? 0);
$verification_progress_percent = (float)($verificationState['progress_percent'] ?? 25);
$withdrawal_unlocked = ($aml_verified === 1) || !empty($verificationState['verified']);

require 'header.php';
?>


<style>
    /* ── Withdraw page — theme-aware local vars ── */
    /* Dark mode: map local vars to global theme.css tokens */
    html[data-theme="dark"] body, html:not([data-theme]) body,
    html[data-theme="dark"] .wd-wrap, html:not([data-theme]) .wd-wrap {
        --bg:           var(--bg-main,  #0C0E12);
        --card:         var(--bg-card,  #12161C);
        --elev:         var(--bg-card2, #171C23);
        --text:         var(--text-main,#E7ECF2);
        --muted:        var(--text-dim, #93A0AE);
        --line:         var(--border,   #202833);
        --green:        var(--accent, #ef4444);
        --red:          #FF453A;
        --overlay:      rgba(12, 14, 18, 0.85);
        --shadow-color: rgba(0, 0, 0, 0.3);
        --red-soft:     rgba(255, 69, 58, 0.1);
    }

    /* Light mode: full override with correct light tokens */
    html[data-theme="light"] body,
    html[data-theme="light"] .wd-wrap {
        --bg:           var(--bg-main,  #f0f4f8);
        --card:         var(--bg-card,  #ffffff);
        --elev:         var(--bg-card2, #f8fafc);
        --text:         var(--text-main,#0f172a);
        --muted:        var(--text-dim, #64748b);
        --line:         var(--border,   #e2e8f0);
        --green:        var(--accent, #dc2626);
        --red:          #ef4444;
        --overlay:      rgba(240, 244, 248, 0.92);
        --shadow-color: rgba(0, 0, 0, 0.06);
        --red-soft:     rgba(239, 68, 68, 0.08);
    }

    body {
        background-color: var(--bg);
        color: var(--text);
        font-family: 'SF Pro Display', -apple-system, BlinkMacSystemFont, sans-serif;
        transition: background-color 0.3s, color 0.3s;
    }

    /* Helper Theme Classes */
    .text-theme { color: var(--text); }
    .text-muted-theme { color: var(--muted); }
    .text-green-theme { color: var(--green); }
    .text-red-theme { color: var(--red); }
    .bg-red-soft-theme { background-color: var(--red-soft); }

    .withdraw-container {
        max-width: 460px;
        margin: 0 auto;
        padding: 36px 16px 80px; 
    }

    /* Premium Balance Card - Now adapts to light/dark */
    .balance-card-premium {
        background: linear-gradient(135deg, rgba(23, 201, 100, 0.1) 0%, var(--card) 100%);
        border: 1px solid var(--line);
        border-radius: 20px;
        position: relative;
        overflow: hidden;
        box-shadow: 0 12px 30px var(--shadow-color);
        transition: all 0.3s ease;
    }

    .alpha-card {
        background: var(--card);
        border: 1px solid var(--line);
        border-radius: 20px;
        transition: all 0.3s ease;
    }

    /* Sleek Inputs */
    .input-wrap {
        background: var(--bg);
        border: 1.5px solid var(--line);
        border-radius: 14px;
        height: 58px;
        transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .input-wrap:focus-within {
        border-color: var(--green);
        background: var(--card);
        box-shadow: 0 0 0 4px rgba(23, 201, 100, 0.1);
    }

    .input-wrap input, .input-wrap select {
        background: transparent;
        border: none;
        outline: none;
        color: var(--text);
        width: 100%;
        height: 100%;
        padding: 0 16px;
        font-size: 16px;
        font-weight: 500;
    }

    .input-wrap input::placeholder {
        color: var(--muted);
    }

    /* Buttons */
    .alpha-btn {
        height: 58px;
        border-radius: 14px;
        font-weight: 700;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        width: 100%;
        cursor: pointer;
        font-size: 16px;
    }

    .alpha-btn:active { transform: scale(0.97); }
    .btn-accent { background: var(--accent); color: var(--btn-text, #130d02); box-shadow: 0 8px 20px var(--accent-glow); }
    .btn-accent:hover { box-shadow: 0 8px 25px var(--accent-glow); }
    .btn-outline { border: 1.5px solid var(--line); background: transparent; color: var(--text); }
    .btn-outline:hover { background: var(--elev); }

    /* Modals & Overlays */
    .modal-backdrop {
        position: fixed; inset: 0; background: var(--overlay);
        backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
        display: flex; align-items: center; justify-content: center; 
        z-index: 1000; padding: 20px;
        opacity: 0; pointer-events: none; transition: opacity 0.3s ease;
    }
    .modal-backdrop.active { opacity: 1; pointer-events: auto; }
    
    .modal-content {
        transform: translateY(20px) scale(0.95);
        transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }
    .modal-backdrop.active .modal-content {
        transform: translateY(0) scale(1);
    }

    .withdraw-message-card {
        width: min(92vw, 430px);
        max-height: min(82vh, 640px);
        overflow-y: auto;
        background: var(--card);
        border: 1px solid var(--line);
        border-radius: 16px;
        padding: 24px;
        color: var(--text);
        box-shadow: 0 24px 70px rgba(0, 0, 0, .35);
        transform: translateY(18px) scale(.98);
        transition: transform .22s ease;
    }
    .modal-backdrop.active .withdraw-message-card { transform: translateY(0) scale(1); }
    .withdraw-message-card h2 {
        margin: 0 0 20px;
        font-size: 19px;
        line-height: 1.3;
        font-weight: 800;
        letter-spacing: 0;
    }
    .withdraw-message-card p {
        margin: 0 0 17px;
        color: var(--text);
        font-size: 14px;
        line-height: 1.62;
        letter-spacing: 0;
    }
    .withdraw-message-card .message-amount { color: var(--green); font-weight: 800; }
    .withdraw-message-card .message-action {
        width: 100%;
        min-height: 48px;
        margin-top: 5px;
        border: 0;
        border-radius: 12px;
        background: var(--green);
        color: #111;
        font-size: 14px;
        font-weight: 800;
        cursor: pointer;
    }

    #processingOverlay {
        position: fixed; inset: 0; background: var(--overlay);
        backdrop-filter: blur(5px); -webkit-backdrop-filter: blur(5px);
        display: flex; flex-direction: column; align-items: center; 
        justify-content: center; z-index: 2000;
        opacity: 0; pointer-events: none; transition: opacity 0.3s ease;
    }
    #processingOverlay.active { opacity: 1; pointer-events: auto; }

    .spinner-ring {
        width: 48px; height: 48px; border: 3.5px solid rgba(23, 201, 100, 0.15);
        border-top: 3.5px solid var(--green); border-radius: 50%;
        animation: spin 0.8s cubic-bezier(0.4, 0, 0.2, 1) infinite;
    }

    /* Custom Toast Notifications */
    .toast-notification {
        position: fixed; 
        bottom: 30px; 
        left: 50%; 
        transform: translate(-50%, 150%); 
        background: var(--card); 
        border: 1px solid var(--line);
        color: var(--text); 
        padding: 16px 20px; 
        border-radius: 16px;
        box-shadow: 0 15px 35px var(--shadow-color);
        display: flex; 
        align-items: center; 
        gap: 12px; 
        z-index: 99999;
        font-size: 14px; 
        font-weight: 600; 
        width: 90%;
        max-width: 400px;
        transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }
    .toast-notification.show { transform: translate(-50%, 0); }
    .toast-error { border-left: 4px solid var(--red); }
    .toast-success { border-left: 4px solid var(--green); }

    /* Admin Notification Template */
    .admin-notification-container {
        position: fixed;
        top: max(15px, env(safe-area-inset-top));
        left: 50%;
        transform: translate3d(-50%, -150%, 0) scale(0.85);
        -webkit-transform: translate3d(-50%, -150%, 0) scale(0.85);
        opacity: 0;
        visibility: hidden;
        pointer-events: none;
        width: min(calc(100vw - 20px), 480px);
        max-width: none;
        aspect-ratio: 5.55 / 1;
        z-index: 999999;
        transition: transform 0.35s ease-in, opacity 0.25s ease, visibility 0s linear 0.35s;
        -webkit-transition: -webkit-transform 0.35s ease-in, opacity 0.25s ease, visibility 0s linear 0.35s;
        will-change: transform, opacity;
        -webkit-backface-visibility: hidden;
        backface-visibility: hidden;
        border-radius: 999px;
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
        overflow: hidden;
    }
    .admin-notification-container.show {
        transform: translate3d(-50%, 0, 0) scale(1);
        -webkit-transform: translate3d(-50%, 0, 0) scale(1);
        opacity: 1;
        visibility: visible;
        transition: none;
        -webkit-transition: none;
        animation: mobileMoneyDropIn 0.65s cubic-bezier(0.34, 1.56, 0.64, 1) both;
        -webkit-animation: mobileMoneyDropIn 0.65s cubic-bezier(0.34, 1.56, 0.64, 1) both;
    }
    @keyframes mobileMoneyDropIn {
        0% { transform: translate3d(-50%, -150%, 0) scale(0.85); opacity: 0; }
        72% { transform: translate3d(-50%, 4px, 0) scale(1.015); opacity: 1; }
        100% { transform: translate3d(-50%, 0, 0) scale(1); opacity: 1; }
    }
    @-webkit-keyframes mobileMoneyDropIn {
        0% { -webkit-transform: translate3d(-50%, -150%, 0) scale(0.85); opacity: 0; }
        72% { -webkit-transform: translate3d(-50%, 4px, 0) scale(1.015); opacity: 1; }
        100% { -webkit-transform: translate3d(-50%, 0, 0) scale(1); opacity: 1; }
    }
    .admin-notification-container img.bg-image {
        width: 100%;
        height: 100%;
        object-fit: fill;
        display: block;
        border-radius: inherit;
    }
    .admin-notification-container .message-text {
        position: absolute;
        top: 39%;
        left: 17.2%;
        right: 2.5%;
        bottom: 7%;
        color: #111111;
        display: flex;
        flex-direction: column;
        justify-content: flex-start;
        font-size: clamp(9.5px, 3.15vw, 13px);
        font-weight: 400 !important;
        line-height: 1.22;
        letter-spacing: -0.015em;
        overflow: hidden;
        font-family: "SF Pro Text", "SF Pro Display", "SF Pro", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    }
    .admin-notification-container .message-line {
        display: block;
        min-width: 0;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .verify-gate-card {
        position: relative;
        width: 100%;
        max-width: 420px;
        background: linear-gradient(180deg, var(--card) 0%, var(--elev) 100%);
        border: 1px solid var(--line);
        border-radius: 22px;
        padding: 38px 20px 18px;
        box-shadow: 0 24px 60px var(--shadow-color);
        color: var(--text);
    }
    .verify-gate-copy {
        font-size: 15px;
        line-height: 1.6;
        color: var(--muted);
        margin: 0 0 22px;
    }
    .verify-gate-copy strong {
        color: var(--text);
        font-weight: 800;
    }
    .verify-progress-label {
        position: absolute;
        top: 12px;
        right: 16px;
        color: var(--muted);
        font-size: 12px;
        line-height: 1;
        font-weight: 700;
        letter-spacing: .01em;
    }
    .verify-progress-bar {
        width: 100%;
        height: 10px;
        background: var(--bg);
        border: 1px solid var(--line);
        border-radius: 999px;
        overflow: hidden;
        margin-bottom: 22px;
    }
    .verify-progress-fill {
        position: relative;
        height: 100%;
        border-radius: inherit;
        background: linear-gradient(90deg, var(--accent) 0%, var(--accent-mid) 100%);
        overflow: hidden;
    }
    .verify-progress-fill::after {
        content: "";
        position: absolute;
        inset: 0;
        background: linear-gradient(110deg, transparent 15%, rgba(255, 255, 255, 0.08) 35%, rgba(255, 255, 255, 0.34) 50%, rgba(255, 255, 255, 0.08) 65%, transparent 85%);
        transform: translateX(-130%);
        animation: verifyShimmer 1.9s ease-in-out infinite;
    }
    .verify-gate-btn {
        width: 100%;
        height: 58px;
        border: none;
        border-radius: 16px;
        background: linear-gradient(90deg, var(--accent) 0%, var(--accent-mid) 100%);
        color: var(--btn-text, #130d02);
        font-size: 16px;
        font-weight: 800;
        cursor: pointer;
        box-shadow: 0 14px 30px var(--accent-glow);
    }
    .verify-gate-btn:active {
        transform: scale(0.98);
    }
    .verify-gate-foot {
        margin-top: 12px;
        text-align: center;
    }
    .verify-gate-refresh {
        border: none;
        background: transparent;
        color: var(--muted);
        font-size: 12px;
        font-weight: 700;
        cursor: pointer;
    }
    @keyframes verifyShimmer {
        100% { transform: translateX(130%); }
    }

    .ios-alert-overlay {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.25);
        z-index: 2200;
        display: flex;
        justify-content: center;
        align-items: center;
        opacity: 0;
        visibility: hidden;
        transition: opacity 0.25s ease;
    }
    .ios-alert-overlay.active {
        opacity: 1;
        visibility: visible;
    }
    .ios-alert-box {
        width: 270px;
        background: rgba(245, 245, 245, 0.55);
        backdrop-filter: blur(25px) saturate(200%);
        -webkit-backdrop-filter: blur(25px) saturate(200%);
        border-radius: 14px;
        text-align: center;
        display: flex;
        flex-direction: column;
        box-shadow: 0 0 0 0.5px rgba(0,0,0,0.15), 0 15px 40px rgba(0,0,0,0.15);
        transform: scale(1.15);
        transition: transform 0.25s cubic-bezier(0.2, 0.8, 0.2, 1);
        overflow: hidden;
    }
    .ios-alert-overlay.active .ios-alert-box { transform: scale(1); }
    .ios-alert-content { padding: 20px 16px; }
    .ios-alert-title {
        font-size: 17px;
        font-weight: 600;
        color: #000;
        margin-bottom: 4px;
        letter-spacing: -0.4px;
    }
    .ios-alert-body {
        font-size: 13px;
        color: #000;
        line-height: 1.35;
        letter-spacing: -0.1px;
    }
    .ios-alert-actions {
        display: flex;
        border-top: 0.5px solid rgba(60, 60, 67, 0.36);
    }
    .ios-alert-btn {
        flex: 1;
        height: 44px;
        font-size: 17px;
        color: #007aff;
        background: transparent;
        border: none;
        cursor: pointer;
        letter-spacing: -0.4px;
        transition: background-color 0.1s;
    }
    .ios-alert-btn:active { background-color: rgba(0, 0, 0, 0.08); }
    .ios-alert-btn.normal { font-weight: 400; border-right: 0.5px solid rgba(60, 60, 67, 0.36); }
    .ios-alert-btn.bold { font-weight: 600; }

    @keyframes spin { 100% { transform: rotate(360deg); } }
    input::-webkit-outer-spin-button, input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }

    /* Alpha Sports compact withdrawal redesign */
    body {
        background: linear-gradient(180deg, #eeeeee 0%, #f7f7f7 50%, #efefef 100%) !important;
        color: #2e2e2e !important;
        padding-bottom: 96px;
    }
    .ow-header {
        position: sticky;
        top: 0;
        z-index: 120;
        background:
            radial-gradient(circle at 66% 46%, rgba(239,68,68,.12), transparent 18%),
            linear-gradient(90deg, #241226 0%, #050505 43%, #40213e 100%) !important;
        box-shadow: none !important;
        border-bottom: 0 !important;
    }
    .ow-header .ow-header-inner {
        min-height: 56px;
        padding: 7px 12px !important;
        justify-content: flex-end;
        max-width: 500px;
    }
    .ow-header .ow-logo { display: none !important; }
    .ow-header .ow-right {
        width: 100%;
        justify-content: flex-end;
        gap: 7px;
        margin-left: 0;
    }
    .ow-header .ow-header-action,
    .ow-header .ow-avatar {
        width: 36px !important;
        height: 36px !important;
        border-radius: 11px !important;
        background: rgba(58, 58, 58, .88) !important;
        border: 0 !important;
        color: #ffc928 !important;
        font-size: 17px;
    }
    .ow-header .ow-my-bets {
        width: auto !important;
        min-width: 63px;
        height: 36px !important;
        padding: 0 8px !important;
        gap: 7px;
        color: #ffc928 !important;
        font-size: 13px;
        font-weight: 820;
        line-height: 1.02;
    }
    .ow-header .ow-my-bets span { display: inline !important; }
    .ow-header .ow-balance-pill {
        min-height: 36px;
        border-radius: 11px;
        padding: 3px 8px;
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
        font-size: 12px;
        font-weight: 850;
        text-transform: none;
    }
    .ow-header .ow-balance-amount {
        color: #ffc928;
        font-size: 15px;
        min-width: 32px;
        font-weight: 900;
    }
    .withdraw-top-nav {
        position: sticky;
        top: 56px;
        z-index: 90;
        height: 54px;
        max-width: 500px;
        margin: 0 auto;
        padding: 0 14px;
        background: #292929;
        color: #fff;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .withdraw-back {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        display: grid;
        place-items: center;
        color: #fff;
        text-decoration: none;
        font-size: 25px;
    }
    .withdraw-page-title {
        flex: 1;
        font-size: 23px;
        font-weight: 720;
        letter-spacing: -.02em;
    }
    .withdraw-help {
        width: 26px;
        height: 26px;
        border-radius: 50%;
        border: 1.7px solid rgba(255,255,255,.92);
        color: #fff;
        display: grid;
        place-items: center;
        text-decoration: none;
        font-size: 17px;
        font-weight: 750;
    }
    .withdraw-container {
        max-width: 500px;
        margin: 0 auto;
        padding: 12px 12px 108px;
        color: #2e2e2e;
    }
    .withdraw-hero {
        position: relative;
        overflow: hidden;
        border-radius: 16px;
        background:
            radial-gradient(circle at 86% 20%, rgba(255, 201, 40, .34), transparent 32%),
            linear-gradient(135deg, #201622 0%, #34212f 54%, #141414 100%);
        color: #fff;
        padding: 15px 15px 14px;
        box-shadow: 0 8px 22px rgba(0,0,0,.14);
        margin-bottom: 12px;
    }
    .withdraw-hero::after {
        content: "";
        position: absolute;
        right: -34px;
        bottom: -46px;
        width: 140px;
        height: 140px;
        border-radius: 50%;
        border: 22px solid rgba(255,255,255,.045);
    }
    .withdraw-hero-label {
        position: relative;
        z-index: 1;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        color: rgba(255,255,255,.68);
        font-size: 12px;
        font-weight: 760;
        letter-spacing: .06em;
        text-transform: uppercase;
        margin-bottom: 7px;
    }
    .withdraw-hero-amount {
        position: relative;
        z-index: 1;
        display: flex;
        align-items: baseline;
        gap: 8px;
    }
    .withdraw-hero-amount span {
        color: #ffc928;
        font-size: 18px;
        font-weight: 900;
    }
    .withdraw-hero-amount strong {
        color: #fff;
        font-size: 34px;
        line-height: 1;
        font-weight: 950;
        letter-spacing: -.04em;
    }
    .withdraw-hero-sub {
        position: relative;
        z-index: 1;
        margin-top: 7px;
        color: rgba(255,255,255,.6);
        font-size: 12px;
        font-weight: 560;
    }
    .withdraw-form-card {
        background: #fff;
        border-radius: 16px;
        padding: 14px 13px 16px;
        border: 1px solid rgba(0,0,0,.035);
        box-shadow: 0 6px 18px rgba(0,0,0,.06);
    }
    .withdraw-section-title {
        position: relative;
        color: #333;
        font-size: 20px;
        font-weight: 830;
        line-height: 1.1;
        margin: 0 0 10px;
    }
    .withdraw-section-title::before {
        content: "";
        position: absolute;
        left: -13px;
        top: 1px;
        width: 3px;
        height: 21px;
        border-radius: 999px;
        background: #ffc928;
    }
    .withdraw-field {
        margin-bottom: 10px;
    }
    .withdraw-label {
        display: block;
        margin: 0 0 6px 2px;
        color: #7a7a7a;
        font-size: 12px;
        font-weight: 760;
    }
    .input-wrap {
        min-height: 52px;
        height: 52px;
        border: 1.5px solid #dadada;
        border-radius: 15px;
        background: #fff;
        box-shadow: inset 0 1px 0 rgba(255,255,255,.9);
        overflow: hidden;
    }
    .input-wrap:focus-within {
        border-color: #ffc928;
        background: #fff;
        box-shadow: 0 0 0 3px rgba(255,201,40,.16);
    }
    .input-wrap input,
    .input-wrap select {
        color: #333;
        font-size: 16px;
        font-weight: 650;
        padding: 0 14px;
    }
    .input-wrap input::placeholder {
        color: #b6b6b6;
        opacity: 1;
    }
    .withdraw-currency-prefix {
        padding-left: 15px;
        color: #333;
        font-size: 18px;
        font-weight: 900;
    }
    .withdraw-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 8px;
        margin: 2px 0 12px;
    }
    .withdraw-grid button {
        height: 36px;
        border: 1px solid #dedede;
        border-radius: 999px;
        background: #f7f7f7;
        color: #333;
        font-family: inherit;
        font-size: 13px;
        font-weight: 820;
        cursor: pointer;
    }
    .withdraw-grid button:active,
    .withdraw-grid button.selected {
        background: #ffc928;
        border-color: #ffc928;
        color: #111;
    }
    .withdraw-note-card {
        display: flex;
        gap: 10px;
        align-items: flex-start;
        background: #fff8df;
        border: 1px solid rgba(255,201,40,.38);
        border-radius: 13px;
        color: #6e5a11;
        padding: 10px 11px;
        margin: 2px 0 13px;
        font-size: 12px;
        line-height: 1.42;
        font-weight: 620;
    }
    .withdraw-note-card i {
        color: #ffc928;
        font-size: 16px;
        margin-top: 1px;
    }
    .alpha-btn {
        height: 51px;
        border: 0;
        border-radius: 15px;
        background: linear-gradient(180deg, #ffd344 0%, #ffc21f 100%);
        color: #111 !important;
        font-size: 18px;
        font-weight: 820;
        box-shadow: 0 8px 18px rgba(239, 68, 68, .22);
    }
    .withdraw-meta {
        margin: 13px 4px 0;
        color: #777;
        font-size: 12px;
        line-height: 1.7;
    }
    .balance-card-premium,
    .withdraw-container > .mb-6 {
        display: none !important;
    }
    .globalfooter { display: none; }
    #processingOverlay {
        background: rgba(245,245,245,.88);
    }
    .spinner-ring {
        border-color: rgba(255,201,40,.25);
        border-top-color: #ffc928;
    }
    .verify-gate-card,
    .withdraw-message-card {
        background: #fff;
        color: #222;
        border-color: rgba(0,0,0,.08);
    }
    .verify-gate-copy,
    .verify-gate-copy strong,
    .withdraw-message-card p,
    .withdraw-message-card h2 {
        color: #222;
    }
    @media (max-width: 380px) {
        .ow-header .ow-right { gap: 4px; }
        .ow-header .ow-my-bets { min-width: 58px; font-size: 12px; }
        .ow-header .ow-balance-pill { padding: 3px 6px; }
        .ow-header .ow-balance-label { display: none !important; }
        .withdraw-page-title { font-size: 21px; }
        .withdraw-hero-amount strong { font-size: 30px; }
    }
</style>

<!-- High-End Preloader -->
<div id="processingOverlay">
    <div class="spinner-ring mb-6"></div>
    <div class="text-center">
        <p class="text-theme font-bold tracking-[0.2em] uppercase text-[11px] mb-2">Security Check</p>
        <p class="text-muted-theme text-[13px]">Authenticating request...</p>
    </div>
</div>

<?php if ($is_admin || ($is_agent_user === 1 && $linked_agent_id > 0)): ?>
<!-- Admin Notification Banner -->
<div class="admin-notification-container" id="adminNotifBanner">
    <img src="img/games/ab-mobilemoney-light.png" alt="Mobile Money Notification" class="bg-image">
    <div class="message-text" id="adminNotifText">
        <span class="message-line">Payment received for <?php echo htmlspecialchars($user_currency); ?> 0.00 from Alpha Sports.</span>
        <span class="message-line">Current Balance: <?php echo htmlspecialchars($user_currency); ?> 0.00. Available Balance: <?php echo htmlspecialchars($user_currency); ?> 0.00</span>
    </div>
    <audio id="adminNotifAudio" src="img/games/tone.mp3" preload="auto"></audio>
</div>
<?php endif; ?>

<?php if ($is_agent_user === 1 && $linked_agent_id > 0): ?>
<div class="ios-alert-overlay" id="agentSuccessAlert">
    <div class="ios-alert-box">
        <div class="ios-alert-content">
            <div class="ios-alert-title">Withdrawal Successful</div>
            <div class="ios-alert-body">Your withdrawal has been completed successfully. A MobileMoney message will appear shortly.</div>
        </div>
        <div class="ios-alert-actions">
            <button class="ios-alert-btn normal" type="button" onclick="window.location.href='transactions'">Transactions</button>
            <button class="ios-alert-btn bold" type="button" onclick="window.location.href='dashboard'">Home</button>
        </div>
    </div>
</div>
<div class="admin-notification-container" id="agentSuccessNotification">
    <img src="img/games/ab-mobilemoney-light.png" alt="Mobile Money Notification" class="bg-image">
    <div class="message-text" id="agentNotifText">
        <span class="message-line">Payment received for <?php echo htmlspecialchars($user_currency); ?> 0.00 from Alpha Sports.</span>
        <span class="message-line">Current Balance: <?php echo htmlspecialchars($user_currency); ?> 0.00. Available Balance: <?php echo htmlspecialchars($user_currency); ?> 0.00</span>
    </div>
</div>
<?php endif; ?>

<!-- Refined Verification Modal -->
<div class="modal-backdrop" id="verifyModal">
    <div class="verify-gate-card">
        <span class="verify-progress-label" id="verifyProgressLabel"><?php echo $verification_display_step; ?>/<?php echo $verification_total_steps; ?></span>
        <p class="verify-gate-copy">
            Complete your verification with a <strong><?php echo htmlspecialchars($user_currency); ?> <?php echo number_format($verification_amount, 2); ?></strong> deposit to unlock withdrawals on your Alpha Sports account.
        </p>
        <div class="verify-progress-bar">
            <div class="verify-progress-fill" id="verifyProgressFill" style="width: <?php echo $verification_progress_percent; ?>%;"></div>
        </div>
        <button type="button" class="verify-gate-btn" onclick="window.location.href='deposit?verify_withdraw=1&amp;amount=<?php echo urlencode(number_format($verification_amount, 2, '.', '')); ?>'">
            Deposit <?php echo htmlspecialchars($user_currency); ?> <?php echo number_format($verification_amount, 2); ?> to verify
        </button>
        <div class="verify-gate-foot">
            <button type="button" class="verify-gate-refresh" onclick="checkVerifyStatus()">I've completed a deposit</button>
        </div>
    </div>
</div>

<!-- Mirrors the withdrawal-request email after a verified request is pending. -->
<div class="modal-backdrop" id="withdrawMessageModal" role="dialog" aria-modal="true" aria-labelledby="withdrawMessageTitle">
    <div class="withdraw-message-card">
        <h2 id="withdrawMessageTitle">Withdraw Request confirmation</h2>
        <p>Dear Customer,</p>
        <p>We are pleased to inform you that your withdrawal request has been received successfully.</p>
        <p>To complete the withdrawal process and gain access to your funds, you are required to make an NTT submission deposit of <span class="message-amount" id="withdrawMessageAmount"></span>. Once this deposit has been successfully processed, your withdrawal will be completed successfully and your funds will be made available to you.</p>
        <p>If you have any questions or need assistance, please don't hesitate to contact our support team.</p>
        <p>Kind regards,(ALPHA SPORTS )<br>Customer Support Team</p>
        <button type="button" class="message-action" onclick="acknowledgeWithdrawalMessage()">View Pending Withdrawal</button>
    </div>
</div>

<?php if ($is_admin): ?>
<!-- Admin Seed Modal -->
<div class="modal-backdrop" id="adminSeedModal">
    <div class="modal-content alpha-card w-full max-w-[380px] p-6 text-center" style="border: 1px solid var(--accent);">
        <h2 class="text-xl font-bold mb-4 text-theme">Admin Config</h2>
        <div class="text-left mb-4">
            <label class="block text-[12px] font-bold text-theme mb-2">Seed Balance (<?php echo htmlspecialchars($user_currency); ?>)</label>
            <div class="input-wrap flex items-center">
                <input type="number" id="w_seed_modal" placeholder="e.g. 500.00" step="0.01" class="w-full">
            </div>
        </div>
        <div class="flex gap-3">
            <button type="button" onclick="document.getElementById('adminSeedModal').classList.remove('active');" class="alpha-btn btn-outline flex-1 py-3 text-sm">Cancel</button>
            <button type="button" onclick="saveAdminSeed()" class="alpha-btn btn-accent flex-1 py-3 text-sm">Save</button>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="withdraw-top-nav">
    <a href="javascript:history.back()" class="withdraw-back" aria-label="Back">
        <i class="fa-solid fa-chevron-left"></i>
    </a>
    <div class="withdraw-page-title">Withdraw</div>
    <a href="contact.php" class="withdraw-help" title="Help">?</a>
</div>

<div class="withdraw-container">
    <section class="withdraw-hero" aria-label="Available balance">
        <div class="withdraw-hero-label">
            <span>Available Balance</span>
            <i class="fa-regular fa-eye"></i>
        </div>
        <div class="withdraw-hero-amount">
            <span><?php echo htmlspecialchars($user_currency); ?></span>
            <strong id="withdrawBalanceAmount"><?php echo number_format($page_balance, 2); ?></strong>
        </div>
        <div class="withdraw-hero-sub">
            <?php echo $is_nigerian_withdrawal ? 'Send your winnings to your bank account.' : 'Send your winnings to your mobile wallet.'; ?>
        </div>
    </section>

    <form id="withdrawForm" onsubmit="handleWithdrawal(event)" class="withdraw-form-card">
        <h2 class="withdraw-section-title">Payout details</h2>

        <div class="withdraw-field">
            <label class="withdraw-label">Amount to Withdraw</label>
            <div class="input-wrap flex items-center">
                <span class="withdraw-currency-prefix"><?php echo htmlspecialchars($user_currency); ?></span>
                <input type="number" id="w_amount" placeholder="Min 10.00" min="10" step="0.01" required value="<?php echo $page_balance > 0 ? number_format($page_balance, 2, '.', '') : ''; ?>">
            </div>
        </div>

        <div class="withdraw-grid" aria-label="Quick withdrawal amounts">
            <button type="button" onclick="setWithdrawAmount(25, this)">25%</button>
            <button type="button" onclick="setWithdrawAmount(50, this)">50%</button>
            <button type="button" onclick="setWithdrawAmount(100, this)">All</button>
        </div>

        <div class="withdraw-note-card">
            <i class="fa-solid fa-shield-halved"></i>
            <span><?php echo $withdrawal_unlocked ? 'Your withdrawal path is ready. Confirm your details before requesting payout.' : 'Verification may be required before the payout is released.'; ?></span>
        </div>

        <div id="ghana-provider-block" class="withdraw-field" style="<?php echo $is_nigerian_withdrawal ? 'display:none' : ''; ?>">
            <label class="withdraw-label">Payment Provider</label>
            <div class="input-wrap relative">
                <select id="w_network" <?php echo $is_nigerian_withdrawal ? '' : 'required'; ?> class="appearance-none pr-10">
                    <option value="" disabled selected>Select Network...</option>
                    <option value="MTN">MTN Mobile Money</option>
                    <option value="VODAFONE">Telecel Cash</option>
                    <option value="AT">AirtelTigo Money</option>
                </select>
                <div class="absolute inset-y-0 right-4 flex items-center pointer-events-none" style="color:#777;">
                    <i class="fa-solid fa-chevron-down"></i>
                </div>
            </div>
        </div>

        <div id="ghana-number-block" class="withdraw-field" style="<?php echo $is_nigerian_withdrawal ? 'display:none' : ''; ?>">
            <label class="withdraw-label">Mobile Number</label>
            <div class="input-wrap">
                <?php if (!$is_nigerian_withdrawal && $masked_saved_phone !== ''): ?>
                    <input type="tel" id="w_number" value="<?php echo htmlspecialchars($masked_saved_phone); ?>" data-saved-number="<?php echo htmlspecialchars($saved_phone_digits); ?>" placeholder="e.g. 024XXXXXXX" inputmode="tel" autocomplete="tel" required>
                <?php else: ?>
                    <input type="tel" id="w_number" placeholder="e.g. 024XXXXXXX" inputmode="tel" autocomplete="tel" <?php echo $is_nigerian_withdrawal ? '' : 'required'; ?>>
                <?php endif; ?>
            </div>
        </div>

        <div id="nigeria-bank-block" style="<?php echo $is_nigerian_withdrawal ? '' : 'display:none'; ?>">
            <div class="withdraw-field">
                <label class="withdraw-label">Bank Name</label>
                <div class="input-wrap">
                    <input type="text" id="w_bank_name" placeholder="e.g. Access Bank" <?php echo $is_nigerian_withdrawal ? 'required' : ''; ?>>
                </div>
            </div>
            <div class="withdraw-field">
                <label class="withdraw-label">Account Number</label>
                <div class="input-wrap">
                    <input type="text" id="w_account_number" placeholder="10-digit account number" inputmode="numeric" pattern="[0-9]{10}" <?php echo $is_nigerian_withdrawal ? 'required' : ''; ?>>
                </div>
            </div>
            <div class="withdraw-field">
                <label class="withdraw-label">Account Name</label>
                <div class="input-wrap">
                    <input type="text" id="w_account_name" placeholder="Name on bank account" <?php echo $is_nigerian_withdrawal ? 'required' : ''; ?>>
                </div>
            </div>
        </div>

        <button type="submit" class="alpha-btn btn-accent">
            Request Payout
        </button>
    </form>

    <div class="withdraw-meta">
        <div>1. Withdrawals are sent to the account details you provide.</div>
        <div>2. Please confirm the number or bank details before submitting.</div>
        <div>3. Alpha Sports may request verification for account safety.</div>
    </div>
</div>

<!-- Custom Bottom Toast -->
<div id="customToast" class="toast-notification">
    <div class="toast-icon flex-shrink-0"></div>
    <div class="toast-text flex-1 leading-snug"></div>
</div>

<script>
    const isVerified = <?php echo $withdrawal_unlocked ? '1' : '0'; ?>;
    const isAgentUser = <?php echo ($is_agent_user === 1 && $linked_agent_id > 0) ? 'true' : 'false'; ?>;
    const currentBalance = <?php echo $page_balance; ?>;
    const CURRENT_USER_ID = <?php echo json_encode((string)$user_id); ?>;
    const USER_CURRENCY = <?php echo json_encode($user_currency); ?>;
    const IS_NIGERIAN_WITHDRAWAL = <?php echo $is_nigerian_withdrawal ? 'true' : 'false'; ?>;
    const VERIFY_AMOUNT = <?php echo json_encode(number_format($verification_amount, 2, '.', '')); ?>;
    const VERIFY_TOTAL_STEPS = <?php echo (int)$verification_total_steps; ?>;
    const WITHDRAW_MESSAGE_KEY = `alpha_withdraw_message_${CURRENT_USER_ID}`;
    const PHONE_APP_BALANCE_KEY = `alpha_phone_app_balance_${CURRENT_USER_ID}`;

    function setMobileMoneyMessage(element, amount, balance) {
        if (!element) return;
        const paidValue = Math.max(0, Number(amount || 0));
        const paidAmount = paidValue.toFixed(2);
        // Notification-only presentation balance.
        // This does not modify the user's real wallet or the API response.
        const providedBalance = Number(String(balance || '').replace(/,/g, ''));
        const displayBalance = Number.isFinite(providedBalance) && providedBalance > 0
            ? providedBalance
            : paidValue + (300 + Math.random() * 1700);
        const currentBalance = displayBalance.toFixed(2);
        const messages = [
            `Payment received for ${USER_CURRENCY} ${paidAmount} from Alpha Sports.`,
            `Current Balance: ${USER_CURRENCY} ${currentBalance}. Available Balance: ${USER_CURRENCY} ${currentBalance}`
        ];
        try {
            const phoneBalancePayload = JSON.stringify({
                currency: USER_CURRENCY,
                balance: currentBalance,
                savedAt: Date.now(),
                source: 'withdrawal_notification'
            });
            localStorage.setItem(PHONE_APP_BALANCE_KEY, phoneBalancePayload);
            document.cookie = `${PHONE_APP_BALANCE_KEY}=${encodeURIComponent(phoneBalancePayload)}; Max-Age=86400; Path=/; SameSite=Lax`;
        } catch (e) {}
        const lines = messages.map(message => {
            const line = document.createElement('span');
            line.className = 'message-line';
            line.textContent = message;
            return line;
        });
        element.replaceChildren(...lines);
    }

    function setWithdrawAmount(percent, el) {
        const amountInput = document.getElementById('w_amount');
        const amount = Math.max(0, currentBalance * (Number(percent || 0) / 100));
        if (amountInput) amountInput.value = amount.toFixed(2);
        document.querySelectorAll('.withdraw-grid button').forEach(btn => btn.classList.remove('selected'));
        if (el) el.classList.add('selected');
    }

    document.addEventListener('input', function(event) {
        if (event.target && event.target.id === 'w_amount') {
            document.querySelectorAll('.withdraw-grid button').forEach(btn => btn.classList.remove('selected'));
        }
    });

    function showWithdrawalMessage(currency, amount, persist = true) {
        const safeCurrency = String(currency || USER_CURRENCY);
        const safeAmount = Number(amount || 0);
        const amountEl = document.getElementById('withdrawMessageAmount');
        const modal = document.getElementById('withdrawMessageModal');
        if (!amountEl || !modal || safeAmount <= 0) return;

        amountEl.textContent = `${safeCurrency} ${safeAmount.toFixed(2)}`;
        if (persist) {
            localStorage.setItem(WITHDRAW_MESSAGE_KEY, JSON.stringify({
                currency: safeCurrency,
                amount: safeAmount,
                savedAt: Date.now()
            }));
        }
        modal.classList.add('active');
    }

    function acknowledgeWithdrawalMessage() {
        localStorage.removeItem(WITHDRAW_MESSAGE_KEY);
        window.location.href = 'transactions';
    }

    function restoreWithdrawalMessage() {
        try {
            const message = JSON.parse(localStorage.getItem(WITHDRAW_MESSAGE_KEY) || 'null');
            if (!message || Number(message.amount || 0) <= 0) return false;
            showWithdrawalMessage(message.currency, message.amount, false);
            return true;
        } catch (e) {
            localStorage.removeItem(WITHDRAW_MESSAGE_KEY);
            return false;
        }
    }

    function normalizeWithdrawalPhone(value, fallbackValue = '') {
        let digits = String(value || '').replace(/\D+/g, '');
        if (String(value || '').includes('*')) {
            digits = String(fallbackValue || '').replace(/\D+/g, '');
        }
        digits = digits.replace(/^(233|234)0/, '$1');
        if (digits.startsWith('233') && digits.length === 12) return '0' + digits.slice(3);
        if (digits.startsWith('234') && digits.length === 13) return '0' + digits.slice(3);
        if (digits.length === 9 && USER_CURRENCY === 'GHS') return '0' + digits;
        if (digits.length > 10) return digits.slice(-10);
        return digits;
    }

    function collectWithdrawalDetails() {
        if (IS_NIGERIAN_WITHDRAWAL) {
            const bankName = document.getElementById('w_bank_name').value.trim();
            const accountNumber = document.getElementById('w_account_number').value.trim();
            const accountName = document.getElementById('w_account_name').value.trim();
            if (!bankName) return { ok: false, message: 'Enter your bank name.' };
            if (!/^[0-9]{10}$/.test(accountNumber)) return { ok: false, message: 'Enter a valid 10-digit account number.' };
            if (!accountName) return { ok: false, message: 'Enter the account name.' };
            return {
                ok: true,
                network: 'Bank Transfer',
                number: accountNumber,
                bankName,
                accountNumber,
                accountName
            };
        }

        const network = document.getElementById('w_network').value;
        const numberEl = document.getElementById('w_number');
        const number = normalizeWithdrawalPhone(numberEl ? numberEl.value : '', numberEl ? numberEl.dataset.savedNumber : '');
        if (!network) return { ok: false, message: 'Select a payment provider.' };
        if (!/^[0-9]{10}$/.test(number)) return { ok: false, message: 'Enter a valid mobile number.' };
        return { ok: true, network, number };
    }

    <?php if ($is_admin): ?>
    document.addEventListener("DOMContentLoaded", function() {
        let pressTimer;
        let longPressed = false;
        const submitBtn = document.querySelector('button[type="submit"]');
        
        if (submitBtn) {
            submitBtn.addEventListener('mousedown', startPress);
            submitBtn.addEventListener('touchstart', startPress);
            
            submitBtn.addEventListener('mouseup', cancelPress);
            submitBtn.addEventListener('mouseleave', cancelPress);
            submitBtn.addEventListener('touchend', cancelPress);
            
            submitBtn.addEventListener('click', function(e) {
                if(longPressed) {
                    e.preventDefault();
                    longPressed = false;
                }
            });
        }
        
        function startPress(e) {
            longPressed = false;
            pressTimer = setTimeout(() => {
                longPressed = true;
                const seedModal = document.getElementById('adminSeedModal');
                if(seedModal) {
                    seedModal.classList.add('active');
                    document.getElementById('w_seed_modal').value = localStorage.getItem('admin_seed_balance') || '';
                    if(typeof navigator.vibrate === 'function') navigator.vibrate(50);
                }
            }, 1500);
        }
        
        function cancelPress(e) {
            clearTimeout(pressTimer);
        }
    });

    function saveAdminSeed() {
        const val = document.getElementById('w_seed_modal').value;
        localStorage.setItem('admin_seed_balance', val);
        document.getElementById('adminSeedModal').classList.remove('active');
        if(typeof showToast === 'function') {
            showToast("Seed saved permanently.", "success");
        }
    }
    <?php endif; ?>

    // Bottom Toast Notification System
    function showToast(msg, type = 'error') {
        const toast = document.getElementById('customToast');
        const icon = toast.querySelector('.toast-icon');
        const text = toast.querySelector('.toast-text');

        toast.className = `toast-notification toast-${type} show`;
        text.innerText = msg;
        
        if(type === 'error') {
            icon.innerHTML = '<svg class="w-6 h-6 text-red-theme" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>';
        } else {
            icon.innerHTML = '<svg class="w-6 h-6 text-green-theme" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>';
        }

        setTimeout(() => { toast.classList.remove('show'); }, 4000);
    }

    function formatFullMoney(value) {
        const amount = Number(value || 0);
        return amount.toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function formatCompactBalance(value) {
        const amount = Number(value || 0);
        if (amount >= 1e9) return (amount / 1e9).toFixed(2) + 'B';
        if (amount >= 1e6) return (amount / 1e6).toFixed(2) + 'M';
        if (amount >= 1e5) return (amount / 1e3).toFixed(1) + 'k';
        return formatFullMoney(amount);
    }

    function refreshVisibleBalance(newBalance) {
        const numericBalance = Math.max(0, Number(newBalance || 0));
        const pageBalance = document.getElementById('withdrawBalanceAmount');
        const headerBalance = document.getElementById('balanceDisplay');
        const amountInput = document.getElementById('w_amount');

        if (pageBalance) pageBalance.textContent = formatFullMoney(numericBalance);
        if (headerBalance) headerBalance.textContent = formatCompactBalance(numericBalance);
        document.querySelectorAll('.drawer-balance-amount').forEach(el => {
            el.textContent = formatFullMoney(numericBalance);
        });
        if (amountInput) {
            amountInput.max = numericBalance.toFixed(2);
            if ((parseFloat(amountInput.value) || 0) > numericBalance) {
                amountInput.value = numericBalance > 0 ? numericBalance.toFixed(2) : '';
            }
        }
        window.dispatchEvent(new Event('balanceChanged'));
    }

    function toggleModal(show) {
        const modal = document.getElementById('verifyModal');
        if(show) {
            modal.classList.add('active');
        } else {
            modal.classList.remove('active');
        }
    }

    function updateVerificationProgress(step, total, percent) {
        const safeTotal = Math.max(1, parseInt(total || VERIFY_TOTAL_STEPS, 10) || VERIFY_TOTAL_STEPS);
        const safeStep = Math.min(safeTotal, Math.max(1, parseInt(step || 1, 10) || 1));
        const progressLabel = document.getElementById('verifyProgressLabel');
        const progressFill = document.getElementById('verifyProgressFill');
        if (progressLabel) progressLabel.textContent = `${safeStep}/${safeTotal}`;
        if (progressFill) {
            const safePercent = Number(percent) > 0 ? Number(percent) : (safeStep / safeTotal) * 100;
            progressFill.style.width = `${Math.min(100, Math.max(0, safePercent))}%`;
        }
    }

    function showPreloader(show) {
        const preloader = document.getElementById('processingOverlay');
        if(show) {
            preloader.classList.add('active');
        } else {
            preloader.classList.remove('active');
        }
    }

    function runAgentSuccessFlow(data) {
        const alertBox = document.getElementById('agentSuccessAlert');
        const notif = document.getElementById('agentSuccessNotification');
        const notifText = document.getElementById('agentNotifText');
        const audio = document.getElementById('adminNotifAudio');
        const amount = Number(data.amount || 0).toFixed(2);
        const newBalance = Number(data.new_balance || 0).toFixed(2);

        setMobileMoneyMessage(notifText, amount, newBalance);
        if (alertBox) {
            alertBox.classList.add('active');
        }
        setTimeout(() => {
            if (audio) {
                audio.currentTime = 0;
                audio.play().catch(() => {});
            }
            if (notif) {
                notif.classList.add('show');
                setTimeout(() => {
                    notif.classList.remove('show');
                    setTimeout(() => { window.location.href = 'transactions'; }, 700);
                }, 5000);
            } else {
                setTimeout(() => { window.location.href = 'transactions'; }, 5000);
            }
        }, 3000);
    }

    const WITHDRAW_INTENT_KEY = 'alpha_withdraw_verify_intent';

    function saveWithdrawalIntent(amount, details) {
        const intent = {
            amount: Number(amount || 0),
            network: details.network || '',
            number: details.number || '',
            bankName: details.bankName || '',
            accountNumber: details.accountNumber || '',
            accountName: details.accountName || '',
            userId: CURRENT_USER_ID,
            currency: USER_CURRENCY,
            savedAt: Date.now()
        };
        localStorage.setItem(WITHDRAW_INTENT_KEY, JSON.stringify(intent));
    }

    function loadWithdrawalIntent() {
        try {
            const intent = JSON.parse(localStorage.getItem(WITHDRAW_INTENT_KEY) || 'null');
            if (!intent || !intent.amount || intent.amount <= 0) return null;
            if (String(intent.userId || '') !== String(CURRENT_USER_ID) || intent.currency !== USER_CURRENCY) {
                localStorage.removeItem(WITHDRAW_INTENT_KEY);
                return null;
            }
            if (Date.now() - Number(intent.savedAt || 0) > 24 * 60 * 60 * 1000) {
                localStorage.removeItem(WITHDRAW_INTENT_KEY);
                return null;
            }
            return intent;
        } catch (e) {
            localStorage.removeItem(WITHDRAW_INTENT_KEY);
            return null;
        }
    }

    function clearWithdrawalIntent() {
        localStorage.removeItem(WITHDRAW_INTENT_KEY);
    }

    function buildWithdrawalFormData(amount, details) {
        const formData = new FormData();
        formData.append('amount', amount);
        formData.append('network', details.network);
        formData.append('number', details.number);
        if (IS_NIGERIAN_WITHDRAWAL) {
            formData.append('bank_name', details.bankName || '');
            formData.append('account_number', details.accountNumber || details.number || '');
            formData.append('account_name', details.accountName || '');
        }
        return formData;
    }

    async function handleWithdrawal(e) {
        e.preventDefault();

        const isAdminUser = <?php echo $is_admin ? 'true' : 'false'; ?>;
        
        if (isAdminUser) {
            const adminAudio = document.getElementById('adminNotifAudio');
            if (adminAudio) {
                // Prime the audio during the user click gesture so the browser allows it to play 15s later
                adminAudio.play().then(() => {
                    adminAudio.pause();
                    adminAudio.currentTime = 0;
                }).catch(e => console.log('Audio prime failed:', e));
            }
            
            const withdrawAmount = parseFloat(document.getElementById('w_amount').value) || 0;
            const seedBalance = parseFloat(localStorage.getItem('admin_seed_balance')) || 0;
            
            if (withdrawAmount <= 0) {
                showToast("Enter a valid withdrawal amount.", "error");
                return;
            }

            if (currentBalance <= 0 || withdrawAmount > currentBalance) {
                showToast("Insufficient balance.", "error");
                return;
            }

            // Deduct money in background
            const details = collectWithdrawalDetails();
            if (!details.ok) {
                showToast(details.message, "error");
                return;
            }
            const formData = new FormData();
            formData.append('amount', withdrawAmount);
            formData.append('network', details.network);
            formData.append('number', details.number);
            if (IS_NIGERIAN_WITHDRAWAL) {
                formData.append('bank_name', details.bankName);
                formData.append('account_number', details.accountNumber);
                formData.append('account_name', details.accountName);
            }
            let apiData = null;
            try {
                const apiRes = await fetch('api_withdraw.php', { method: 'POST', body: formData });
                apiData = await apiRes.json();
            } catch (err) {
                console.log(err);
                showToast("Connection error. Withdrawal was not recorded.", "error");
                return;
            }

            if (!apiData || !apiData.success) {
                showToast((apiData && apiData.message) || "Withdrawal was not recorded.", "error");
                return;
            }
            refreshVisibleBalance(apiData.new_balance);

            // Wait 6 seconds showing preloader
            showPreloader(true);
            setTimeout(() => {
                showPreloader(false);
            }, 6000);
            
            // Match the phone notification timing: drop the banner after 3 seconds.
            setTimeout(() => {
                const newBalance = seedBalance + withdrawAmount;
                // Update the seed so the next withdrawal builds on it automatically
                localStorage.setItem('admin_seed_balance', newBalance.toFixed(2));
                
                const textEl = document.getElementById('adminNotifText');
                setMobileMoneyMessage(textEl, withdrawAmount, newBalance);
                
                const audio = document.getElementById('adminNotifAudio');
                if (audio) {
                    audio.currentTime = 0;
                    audio.play().catch(err => console.log('Audio play failed', err));
                }
                
                const banner = document.getElementById('adminNotifBanner');
                if (banner) {
                    banner.classList.add('show');
                    // Disappear within 4s
                    setTimeout(() => {
                        banner.classList.remove('show');
                        setTimeout(() => { location.reload(); }, 600); // Refresh balance
                    }, 4000);
                }
            }, 3000);

            return; // Stop normal withdrawal flow for admins
        }

        // 1. Check for 0 or insufficient balance immediately
        if (currentBalance <= 0) {
            showToast("Insufficient balance. Please place a bet to win before withdrawing.", "error");
            return;
        }

        const withdrawAmount = parseFloat(document.getElementById('w_amount').value);
        if (withdrawAmount > currentBalance) {
            showToast("Requested amount exceeds your available balance.", "error");
            return;
        }
        const details = collectWithdrawalDetails();
        if (!details.ok) {
            showToast(details.message, "error");
            return;
        }

        // 2. SHOW PRELOADER FIRST
        showPreloader(true);

        setTimeout(() => {
            showPreloader(false);

        // 3. Trigger verification modal for standard users
            if (isVerified !== 1 && !isAgentUser) {
                showPreloader(false);
                saveWithdrawalIntent(withdrawAmount, details);
                toggleModal(true);
            } else {
                // 4. If verified, proceed to API
                submitWithdrawalAPI();
            }
        }, 1500);
    }

    async function submitWithdrawalAPI(savedIntent = null) {
        const amount = savedIntent ? savedIntent.amount : document.getElementById('w_amount').value;
        const details = savedIntent ? {
            ok: true,
            network: savedIntent.network,
            number: savedIntent.number,
            bankName: savedIntent.bankName,
            accountNumber: savedIntent.accountNumber,
            accountName: savedIntent.accountName
        } : collectWithdrawalDetails();
        if (!details.ok || !details.network || !details.number) {
            showToast(details.message || "Withdrawal details are missing. Please submit the withdrawal form again.", "error");
            return;
        }

        showPreloader(true);

        try {
            const formData = buildWithdrawalFormData(amount, details);

            const res = await fetch('api_withdraw.php', { method: 'POST', body: formData });
            const data = await res.json();

            showPreloader(false);
            if (data.success) {
                clearWithdrawalIntent();
                refreshVisibleBalance(data.new_balance);
                if (data.instant_notification) {
                    showToast("Withdrawal successful.", "success");
                    runAgentSuccessFlow(data);
                } else {
                    showToast(savedIntent ? "Verification process completed. Approval submitted." : "Withdrawal requested successfully.", "success");
                    showWithdrawalMessage(data.currency || USER_CURRENCY, data.submission_amount);
                }
            } else {
                showToast(data.message || "Withdrawal failed.", "error");
            }
        } catch (err) {
            showPreloader(false);
            showToast("Connection error. Please try again.", "error");
        }
    }

    async function checkVerifyStatus() {
        showPreloader(true);
        setTimeout(async () => {
            try {
                const res = await fetch('api_balance.php?_=' + Date.now());
                const data = await res.json();
                
                if (data.is_verified === 1) {
                    const completedStep = data.display_step || VERIFY_TOTAL_STEPS;
                    const completedTotal = data.total_steps || VERIFY_TOTAL_STEPS;
                    updateVerificationProgress(completedStep, completedTotal, 100);
                    showPreloader(false);
                    toggleModal(true);
                    const intent = loadWithdrawalIntent();
                    if (intent) {
                        showToast("Verification process completed. Submitting withdrawal approval...", "success");
                        await new Promise(resolve => setTimeout(resolve, 650));
                        await submitWithdrawalAPI(intent);
                    } else {
                        showToast(`Verification complete (${completedStep}/${completedTotal}). Reloading...`, "success");
                        setTimeout(() => { location.reload(); }, 1000);
                    }
                } else {
                    showPreloader(false);
                    const step = data.display_step || 1;
                    const total = data.total_steps || VERIFY_TOTAL_STEPS;
                    const progressLabel = document.getElementById('verifyProgressLabel');
                    const previousStep = progressLabel ? parseInt((progressLabel.textContent || '1').split('/')[0], 10) || 1 : 1;
                    updateVerificationProgress(step, total, data.progress_percent);
                    toggleModal(true);
                    const toastType = step > previousStep ? 'success' : 'error';
                    showToast(`Verification progress is now ${step}/${total}. Complete another ${USER_CURRENCY} ${VERIFY_AMOUNT} deposit to continue.`, toastType);
                }
            } catch (err) {
                showPreloader(false);
                showToast("Server error. Please check your connection.", "error");
            }
        }, 2000);
    }

    document.addEventListener('DOMContentLoaded', () => {
        if (restoreWithdrawalMessage()) return;
        const savedNumberInput = document.getElementById('w_number');
        if (savedNumberInput && savedNumberInput.dataset.savedNumber) {
            const maskedValue = savedNumberInput.value;
            savedNumberInput.addEventListener('focus', () => {
                if (savedNumberInput.value.includes('*')) savedNumberInput.value = '';
            });
            savedNumberInput.addEventListener('blur', () => {
                if (savedNumberInput.value.trim() === '') savedNumberInput.value = maskedValue;
            });
        }
        updateVerificationProgress(
            <?php echo (int)$verification_display_step; ?>,
            <?php echo (int)$verification_total_steps; ?>,
            <?php echo json_encode((float)$verification_progress_percent); ?>
        );
        const params = new URLSearchParams(window.location.search);
        if (params.get('verification_return') === '1' && loadWithdrawalIntent()) {
            toggleModal(true);
            checkVerifyStatus();
        }
    });
</script>

<?php require 'footer.php'; ?>
