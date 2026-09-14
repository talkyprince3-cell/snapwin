<?php
session_start();
require_once 'db.php';

function ps_main_admin_setting(PDO $pdo, string $key, string $default = ''): string {
    try {
        $stmt = $pdo->prepare("SELECT value FROM admin_settings WHERE `key`=? LIMIT 1");
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value !== false && trim((string)$value) !== '' ? trim((string)$value) : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

if (isset($_GET['admin_logout'])) {
    unset($_SESSION['main_admin_authenticated'], $_SESSION['main_admin_username']);
    header('Location: admin.php');
    exit;
}

$adminLoginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'main_admin_login') {
    $adminUsername = trim((string)($_POST['admin_username'] ?? ''));
    $adminPassword = (string)($_POST['admin_password'] ?? '');
    $savedAdminUsername = ps_main_admin_setting($pdo, 'main_admin_username', 'admin');
    $savedAdminHash = ps_main_admin_setting($pdo, 'main_admin_password_hash', '');
    $passwordOk = $savedAdminHash !== ''
        ? password_verify($adminPassword, $savedAdminHash)
        : hash_equals('admin123', $adminPassword);
    if (hash_equals($savedAdminUsername, $adminUsername) && $passwordOk) {
        $_SESSION['main_admin_authenticated'] = true;
        $_SESSION['main_admin_username'] = $savedAdminUsername;
        header('Location: admin.php');
        exit;
    }
    $adminLoginError = 'Invalid admin username or password.';
}

if (empty($_SESSION['main_admin_authenticated'])) {
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Main Admin Login | Alpha Sports</title>
    <?php require __DIR__ . '/app_head_assets.php'; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        *{box-sizing:border-box;font-family:-apple-system,BlinkMacSystemFont,"SF Pro Text","Helvetica Neue",system-ui,sans-serif}
        body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0c0e12;color:#f8fafc;padding:20px}
        .login-card{width:100%;max-width:390px;background:#13161c;border:1px solid rgba(255,255,255,.08);padding:30px 26px}
        .logo{width:48px;height:48px;display:flex;align-items:center;justify-content:center;background:#ef4444;color:#130d02;margin-bottom:16px;font-size:20px}
        h1{font-size:22px;margin:0 0 6px;font-weight:850;letter-spacing:-.02em}
        p{margin:0 0 20px;color:#94a3b8;font-size:13px;line-height:1.5}
        label{display:block;font-size:12px;font-weight:800;color:#cbd5e1;margin:0 0 7px;text-transform:uppercase;letter-spacing:.06em}
        input{width:100%;height:48px;border:1px solid rgba(255,255,255,.1);background:#0c0e12;color:#fff;padding:0 14px;margin-bottom:14px;outline:none;font-size:15px}
        input:focus{border-color:#ef4444;box-shadow:0 0 0 3px rgba(239,68,68,.14)}
        button{width:100%;height:48px;border:0;background:#ef4444;color:#130d02;font-weight:900;font-size:15px;cursor:pointer}
        .err{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.28);color:#f87171;padding:10px 12px;margin-bottom:14px;font-size:13px}
    </style>
</head>
<body>
    <form class="login-card" method="post" autocomplete="off">
        <input type="hidden" name="action" value="main_admin_login">
        <div class="logo"><i class="fa-solid fa-shield-halved"></i></div>
        <h1>Main Admin</h1>
        <p>Use the main admin credentials to manage the platform. This login is separate from user and subadmin accounts.</p>
        <?php if ($adminLoginError): ?><div class="err"><?php echo htmlspecialchars($adminLoginError); ?></div><?php endif; ?>
        <label>Username</label>
        <input name="admin_username" required>
        <label>Password</label>
        <input name="admin_password" type="password" required>
        <button type="submit">Login</button>
    </form>
</body>
</html>
    <?php
    exit;
}

require_once __DIR__ . '/currency_helper.php';
$usdExchangeRates = ps_usd_exchange_rates($pdo);
$adminRequestHost = preg_replace('/[^A-Za-z0-9.:-]/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
$adminRequestScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$flutterwaveV4WebhookUrl = $adminRequestHost !== ''
    ? $adminRequestScheme . '://' . $adminRequestHost . '/flutterwave_v4_webhook'
    : 'https://your-domain.com/flutterwave_v4_webhook';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Admin | Alpha Sports</title>
    <?php require __DIR__ . '/app_head_assets.php'; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --acc: #ef4444;
            --accg: rgba(239,68,68,.18);
            --bg: #0C0E12;
            --card: #13161C;
            --dim: #64748b;
            --bdr: rgba(255, 255, 255, .07)
        }

        * {
            box-sizing: border-box;
            font-family: -apple-system, BlinkMacSystemFont, "SF Pro Text", "Helvetica Neue", system-ui, sans-serif, sans-serif
        }

        body {
            background: var(--bg);
            color: #fff;
            margin: 0;
            display: flex;
            min-height: 100vh
        }

        /* Sidebar */
        .sb {
            width: 240px;
            min-height: 100vh;
            background: var(--card);
            border-right: 1px solid var(--bdr);
            display: flex;
            flex-direction: column;
            padding: 20px 0;
            flex-shrink: 0;
            transition: transform .3s ease;
            z-index: 200
        }

        .sb-logo {
            padding: 0 20px 20px;
            border-bottom: 1px solid var(--bdr);
            margin-bottom: 12px
        }

        .sb-logo h1 {
            font-family: SF Pro Display, -apple-system, BlinkMacSystemFont, system-ui, sans-serif;
            font-size: 18px;
            font-weight: 800;
            margin: 0
        }

        .sb-logo span {
            color: var(--acc)
        }

        .sb-logo p {
            font-size: 10px;
            color: var(--dim);
            margin: 2px 0 0;
            text-transform: uppercase;
            letter-spacing: .1em
        }

        .nav-i {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 20px;
            color: var(--dim);
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            border-left: 2px solid transparent;
            transition: .2s
        }

        .nav-i:hover,
        .nav-i.active {
            color: var(--acc);
            background: rgba(239, 68, 68, .055);
            border-left-color: var(--acc)
        }

        .nav-i i {
            width: 16px;
            text-align: center
        }

        /* Mobile topbar */
        .mob-bar {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 52px;
            background: var(--card);
            border-bottom: 1px solid var(--bdr);
            z-index: 300;
            align-items: center;
            padding: 0 16px;
            gap: 12px
        }

        .mob-bar h2 {
            font-family: SF Pro Display, -apple-system, BlinkMacSystemFont, system-ui, sans-serif;
            font-size: 16px;
            font-weight: 800;
            margin: 0;
            flex: 1
        }

        .mob-bar h2 span {
            color: var(--acc)
        }

        .ham {
            width: 36px;
            height: 36px;
            background: rgba(255, 255, 255, .05);
            border: 1px solid var(--bdr);
            border-radius: 10px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 5px;
            cursor: pointer;
            flex-shrink: 0
        }

        .ham span {
            display: block;
            width: 16px;
            height: 2px;
            background: #fff;
            border-radius: 2px;
            transition: .3s
        }

        /* Overlay */
        .sb-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .7);
            z-index: 150;
            backdrop-filter: blur(4px)
        }

        .sb-overlay.show {
            display: block
        }

        @media(max-width:768px) {
            body {
                flex-direction: column
            }

            .mob-bar {
                display: flex
            }

            .sb {
                position: fixed;
                top: 0;
                left: 0;
                height: 100vh;
                transform: translateX(-100%);
                z-index: 200;
                width: 260px;
                overflow-y: auto
            }

            .sb.open {
                transform: translateX(0)
            }

            .main {
                padding: 16px;
                padding-top: 68px;
                max-height: 100vh;
                overflow-y: auto
            }

            .stats-grid {
                grid-template-columns: 1fr 1fr
            }

            .tbl th,
            .tbl td {
                padding: 8px 10px;
                font-size: 11px
            }
        }

        /* Panels */
        .panel {
            display: none
        }

        .panel.active {
            display: block
        }

        .main {
            flex: 1;
            padding: 24px;
            overflow-y: auto;
            max-height: 100vh;
            padding-top: 24px
        }

        /* Cards */
        .card {
            background: var(--card);
            border: 1px solid var(--bdr);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 16px
        }

        .card-title {
            font-size: 13px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .1em;
            margin-bottom: 16px;
            color: var(--acc)
        }

        /* Inputs */
        .inp {
            background: rgba(255, 255, 255, .04);
            border: 1px solid var(--bdr);
            border-radius: 10px;
            padding: 9px 12px;
            color: #fff;
            font-size: 13px;
            width: 100%;
            outline: none
        }

        .inp:focus {
            border-color: var(--acc)
        }

        label {
            font-size: 11px;
            font-weight: 700;
            color: var(--dim);
            display: block;
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: .06em
        }

        .inp-group {
            margin-bottom: 12px
        }

        /* Buttons */
        .btn {
            padding: 8px 18px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 800;
            cursor: pointer;
            border: none;
            transition: .2s;
            letter-spacing: .04em
        }

        .btn-acc {
            background: var(--acc);
            color: #000
        }

        .btn-acc:hover {
            opacity: .85
        }

        .btn-red {
            background: rgba(239, 68, 68, .15);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, .3)
        }

        .btn-red:hover {
            background: #ef4444;
            color: #fff
        }

        .btn-blue {
            background: rgba(239, 68, 68, .12);
            color: var(--acc);
            border: 1px solid rgba(239, 68, 68, .26)
        }

        .btn-blue:hover {
            background: #d69e2e;
            color: #fff
        }

        .btn-ghost {
            background: rgba(255, 255, 255, .05);
            color: #fff;
            border: 1px solid var(--bdr)
        }

        .btn-ghost:hover {
            border-color: var(--acc);
            color: var(--acc)
        }

        .btn-sm {
            padding: 5px 12px;
            font-size: 11px;
            border-radius: 8px
        }

        .user-agent-link {
            display: flex;
            align-items: center;
            gap: 5px;
            min-width: 178px
        }

        .user-agent-select {
            height: 26px;
            min-width: 128px;
            padding: 3px 7px;
            border-radius: 7px;
            font-size: 10px
        }

        .user-actions {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
            max-width: 180px
        }

        .user-action-btn {
            min-height: 24px;
            padding: 4px 7px !important;
            border-radius: 6px !important;
            font-size: 9px !important;
            line-height: 1
        }

        .user-action-icon {
            width: 25px;
            padding-left: 0 !important;
            padding-right: 0 !important
        }

        /* Stats grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-bottom: 20px
        }

        .stat-card {
            background: var(--card);
            border: 1px solid var(--bdr);
            border-radius: 14px;
            padding: 16px
        }

        .stat-val {
            font-size: 24px;
            font-weight: 900;
            font-style: italic;
            margin: 4px 0
        }

        .stat-lbl {
            font-size: 10px;
            font-weight: 700;
            color: var(--dim);
            text-transform: uppercase;
            letter-spacing: .08em
        }

        /* Table */
        .tbl {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 6px
        }

        .tbl th {
            font-size: 10px;
            font-weight: 800;
            color: var(--dim);
            text-transform: uppercase;
            letter-spacing: .08em;
            padding: 4px 12px;
            text-align: left
        }

        .tbl td {
            padding: 11px 12px;
            font-size: 13px;
            background: rgba(255, 255, 255, .02);
            border-top: 1px solid var(--bdr);
            border-bottom: 1px solid var(--bdr)
        }

        .tbl tr td:first-child {
            border-left: 1px solid var(--bdr);
            border-radius: 10px 0 0 10px
        }

        .tbl tr td:last-child {
            border-right: 1px solid var(--bdr);
            border-radius: 0 10px 10px 0
        }

        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 6px;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase
        }

        .b-run {
            background: rgba(239, 68, 68, .12);
            color: var(--acc);
            border: 1px solid rgba(239, 68, 68, .25)
        }

        .b-won {
            background: rgba(239, 68, 68, .12);
            color: var(--acc);
            border: 1px solid var(--accg)
        }

        .b-lost {
            background: rgba(239, 68, 68, .1);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, .25)
        }

        /* Match builder */
        .match-row {
            display: grid;
            grid-template-columns: 1fr auto 1fr auto;
            gap: 8px;
            align-items: center
        }

        .score-sep {
            font-size: 18px;
            font-weight: 900;
            color: var(--acc);
            text-align: center
        }

        /* Code selections builder */
        #sel-list .sel-row {
            background: rgba(255, 255, 255, .03);
            border: 1px solid var(--bdr);
            border-radius: 10px;
            padding: 10px 12px;
            margin-bottom: 8px;
            display: grid;
            grid-template-columns: 1fr 1fr 80px 70px 70px 30px;
            gap: 8px;
            align-items: center
        }

        /* Tabs inside panels */
        .inner-tabs {
            display: flex;
            gap: 6px;
            margin-bottom: 16px
        }

        .inner-tab {
            padding: 6px 14px;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 800;
            cursor: pointer;
            border: 1px solid var(--bdr);
            color: var(--dim);
            background: transparent;
            transition: .2s;
            text-transform: uppercase;
            letter-spacing: .06em
        }

        .inner-tab.active {
            background: var(--acc);
            color: #000;
            border-color: var(--acc)
        }

        /* Toast */
        #toast {
            position: fixed;
            bottom: 24px;
            left: 50%;
            transform: translateX(-50%);
            background: var(--acc);
            color: #000;
            padding: 10px 22px;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 800;
            z-index: 9999;
            opacity: 0;
            transition: .3s;
            pointer-events: none
        }

        #toast.show {
            opacity: 1
        }

        @media(max-width:900px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 10px
            }
        }

        /* Alpha Sports Admin UI refresh — visual layer only */
        :root {
            --bg: #080b12;
            --card: rgba(17, 21, 30, .88);
            --card-2: rgba(23, 28, 40, .92);
            --surface: rgba(255, 255, 255, .045);
            --surface-2: rgba(255, 255, 255, .075);
            --bdr: rgba(255, 255, 255, .085);
            --bdr-strong: rgba(239, 68, 68, .24);
            --text: #f8fafc;
            --dim: #8a94a8;
            --acc: #ef4444;
            --acc-2: #dc2626;
            --accg: rgba(239, 68, 68, .22);
            --green: #22c55e;
            --blue: var(--acc);
            --red: #ef4444;
            --orange: #f97316;
            --radius: 16px;
            --shadow: 0 22px 60px rgba(0, 0, 0, .34);
        }

        html {
            background: var(--bg);
        }

        body {
            color: var(--text);
            background:
                radial-gradient(circle at 24% 0%, rgba(239, 68, 68, .12), transparent 30%),
                radial-gradient(circle at 92% 12%, rgba(239, 68, 68, .07), transparent 28%),
                linear-gradient(180deg, #090d16 0%, #070910 58%, #06070b 100%);
        }

        body::before {
            content: "";
            position: fixed;
            inset: 0;
            pointer-events: none;
            background-image:
                linear-gradient(rgba(255, 255, 255, .025) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255, 255, 255, .02) 1px, transparent 1px);
            background-size: 42px 42px;
            mask-image: linear-gradient(to bottom, rgba(0, 0, 0, .75), transparent 72%);
            z-index: 0;
        }

        .sb,
        .mob-bar,
        .main {
            position: relative;
            z-index: 1;
        }

        .sb {
            width: 272px;
            padding: 18px 12px;
            background: rgba(8, 11, 18, .82);
            border-right: 1px solid var(--bdr);
            box-shadow: 14px 0 42px rgba(0, 0, 0, .22);
            backdrop-filter: blur(22px);
            -webkit-backdrop-filter: blur(22px);
        }

        .sb-logo {
            padding: 6px 10px 18px;
            margin: 0 0 10px;
            border-bottom: 1px solid var(--bdr);
        }

        .sb-logo h1,
        .mob-bar h2 {
            letter-spacing: -.04em;
        }

        .sb-logo h1 {
            font-size: 21px;
        }

        .sb-logo span,
        .mob-bar h2 span {
            color: var(--acc);
        }

        .sb-logo p {
            margin-top: 5px;
            color: var(--dim);
        }

        .nav-i {
            min-height: 42px;
            margin: 3px 0;
            padding: 11px 12px;
            border-radius: 12px;
            border-left: 0;
            color: #a3adc2;
        }

        .nav-i i {
            width: 28px;
            height: 28px;
            border-radius: 9px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, .045);
            color: var(--dim);
        }

        .nav-i:hover,
        .nav-i.active {
            background: linear-gradient(135deg, rgba(239, 68, 68, .16), rgba(239, 68, 68, .055));
            color: var(--text);
            border-left-color: transparent;
            box-shadow: inset 0 0 0 1px var(--bdr-strong);
        }

        .nav-i:hover i,
        .nav-i.active i {
            background: linear-gradient(135deg, var(--acc-2), var(--acc));
            color: #130d02;
        }

        .sb a[href="dashboard.php"] {
            border: 1px solid var(--bdr);
            border-radius: 12px;
            padding: 11px 12px;
            background: rgba(255, 255, 255, .04);
        }

        .sb a[href="dashboard.php"]:hover {
            border-color: var(--bdr-strong);
            color: var(--acc) !important;
        }

        .main {
            padding: 28px;
            scrollbar-width: thin;
            scrollbar-color: rgba(239, 68, 68, .55) transparent;
        }

        .main>h2,
        .panel>h2,
        .panel>div:first-child h2 {
            letter-spacing: -.04em !important;
            text-transform: none;
            font-style: normal !important;
        }

        .panel.active {
            animation: panelIn .18s ease-out;
        }

        @keyframes panelIn {
            from {
                opacity: 0;
                transform: translateY(6px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .card,
        .stat-card {
            background:
                linear-gradient(180deg, rgba(255, 255, 255, .055), rgba(255, 255, 255, .028)),
                var(--card);
            border: 1px solid var(--bdr);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
        }

        .card {
            padding: 20px;
        }

        .card-title {
            color: var(--text);
            font-size: 12px;
            letter-spacing: .08em;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .card-title::before {
            content: "";
            width: 8px;
            height: 8px;
            border-radius: 999px;
            background: var(--acc);
            box-shadow: 0 0 16px var(--accg);
            flex: 0 0 auto;
        }

        .stats-grid {
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
        }

        .stat-card {
            min-height: 112px;
            padding: 17px;
            position: relative;
            overflow: hidden;
        }

        .stat-card::after {
            content: "";
            position: absolute;
            right: -24px;
            top: -24px;
            width: 90px;
            height: 90px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(239, 68, 68, .15), transparent 68%);
        }

        .stat-lbl {
            color: var(--dim);
            letter-spacing: .075em;
        }

        .stat-val {
            color: var(--text);
            font-style: normal;
            letter-spacing: -.04em;
            font-size: clamp(22px, 3vw, 34px);
            line-height: 1.08;
        }

        .inp,
        select.inp,
        textarea.inp {
            min-height: 40px;
            background: rgba(255, 255, 255, .055);
            border: 1px solid var(--bdr);
            border-radius: 12px;
            color: var(--text);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, .035);
            transition: border-color .18s ease, box-shadow .18s ease, background .18s ease;
        }

        .inp:hover {
            background: rgba(255, 255, 255, .07);
        }

        .inp:focus {
            border-color: var(--acc);
            box-shadow: 0 0 0 3px rgba(239, 68, 68, .12);
        }

        select.inp option {
            color: #0f172a;
            background: #fff;
        }

        label {
            color: var(--dim);
            margin-bottom: 6px;
        }

        input[type="checkbox"] {
            accent-color: var(--acc);
        }

        .btn {
            min-height: 38px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            border-radius: 12px;
            padding: 9px 15px;
            box-shadow: 0 10px 24px rgba(0, 0, 0, .18);
            transition: transform .15s ease, box-shadow .15s ease, background .15s ease, border-color .15s ease, color .15s ease;
            white-space: nowrap;
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .btn:active {
            transform: translateY(0) scale(.98);
        }

        .btn-acc {
            background: linear-gradient(135deg, var(--acc-2), var(--acc));
            color: #130d02;
            border: 1px solid rgba(239, 68, 68, .35);
        }

        .btn-acc:hover {
            opacity: 1;
            box-shadow: 0 12px 28px var(--accg);
        }

        .btn-blue {
            background: rgba(239, 68, 68, .12);
            color: var(--acc);
            border: 1px solid rgba(239, 68, 68, .26);
        }

        .btn-blue:hover {
            background: linear-gradient(135deg, var(--acc-2), var(--acc));
            color: #130d02;
        }

        .btn-red {
            background: rgba(239, 68, 68, .12);
            color: #fca5a5;
            border: 1px solid rgba(239, 68, 68, .28);
        }

        .btn-red:hover {
            color: #fff;
            background: #dc2626;
            border-color: #dc2626;
        }

        .btn-ghost {
            background: rgba(255, 255, 255, .052);
            color: var(--text);
            border: 1px solid var(--bdr);
        }

        .btn-ghost:hover {
            background: rgba(255, 255, 255, .085);
            border-color: var(--bdr-strong);
            color: var(--acc);
        }

        .btn-sm {
            min-height: 32px;
            padding: 6px 11px;
            border-radius: 10px;
        }

        .inner-tabs {
            gap: 8px;
            overflow-x: auto;
            padding-bottom: 2px;
            scrollbar-width: none;
        }

        .inner-tabs::-webkit-scrollbar {
            display: none;
        }

        .inner-tab {
            border-radius: 999px;
            padding: 8px 14px;
            background: rgba(255, 255, 255, .04);
            white-space: nowrap;
        }

        .inner-tab.active {
            background: linear-gradient(135deg, var(--acc-2), var(--acc));
            color: #130d02;
            box-shadow: 0 8px 20px var(--accg);
        }

        .tbl {
            border-collapse: separate;
            border-spacing: 0 8px;
            min-width: 720px;
        }

        .tbl th {
            color: var(--dim);
            padding: 7px 12px;
        }

        .tbl td {
            background: rgba(255, 255, 255, .04);
            border-color: var(--bdr);
            vertical-align: middle;
        }

        .tbl tbody tr:hover td {
            background: rgba(239, 68, 68, .055);
            border-color: rgba(239, 68, 68, .20);
        }

        .badge {
            border-radius: 999px;
            padding: 4px 9px;
            letter-spacing: .04em;
        }

        .b-run {
            background: rgba(239, 68, 68, .12);
            color: var(--acc);
            border-color: rgba(239, 68, 68, .25);
        }

        .b-won {
            background: rgba(34, 197, 94, .13);
            color: #86efac;
            border-color: rgba(34, 197, 94, .28);
        }

        .b-lost {
            background: rgba(239, 68, 68, .12);
            color: #fca5a5;
            border-color: rgba(239, 68, 68, .28);
        }

        #sel-list .sel-row {
            background: rgba(255, 255, 255, .045);
            border-color: var(--bdr);
            border-radius: 14px;
        }

        .match-row {
            background: rgba(255, 255, 255, .035);
            border: 1px solid var(--bdr);
            border-radius: 14px;
            padding: 10px;
        }

        .agent-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
            padding: 4px 0 2px;
        }

        .agent-history-list {
            display: grid;
            gap: 10px;
        }

        .agent-history-day {
            border: 1px solid var(--bdr);
            border-radius: 14px;
            background: rgba(255,255,255,.025);
            overflow: hidden;
        }

        .agent-history-day[open] {
            border-color: rgba(239,68,68,.34);
            box-shadow: 0 10px 28px rgba(0,0,0,.18);
        }

        .agent-history-summary {
            list-style: none;
            cursor: pointer;
            padding: 14px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            user-select: none;
        }

        .agent-history-summary::-webkit-details-marker { display: none; }

        .agent-history-summary::after {
            content: '\f078';
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
            color: var(--acc);
            transition: transform .18s ease;
        }

        .agent-history-day[open] .agent-history-summary::after { transform: rotate(180deg); }

        .agent-history-title {
            color: var(--text);
            font-size: 14px;
            font-weight: 900;
        }

        .agent-history-date {
            color: var(--dim);
            font-size: 11px;
            margin-top: 3px;
        }

        .agent-history-total {
            color: var(--acc);
            font-size: 12px;
            font-weight: 850;
            margin-left: auto;
            text-align: right;
        }

        .agent-history-table-wrap {
            overflow-x: auto;
            border-top: 1px solid var(--bdr);
        }

        .agent-history-table {
            width: 100%;
            min-width: 850px;
            border-collapse: collapse;
        }

        .agent-history-table th,
        .agent-history-table td {
            padding: 10px 13px;
            text-align: left;
            border-bottom: 1px solid var(--bdr);
            vertical-align: middle;
            font-size: 11px;
        }

        .agent-history-table th {
            color: var(--dim);
            text-transform: uppercase;
            letter-spacing: .05em;
            font-size: 9px;
            background: rgba(255,255,255,.025);
        }

        .agent-history-table tbody tr:last-child td { border-bottom: 0; }
        .agent-history-table tbody tr:hover td { background: rgba(239,68,68,.04); }

        .agent-history-link {
            border: 0;
            padding: 0;
            background: transparent;
            color: var(--acc);
            font: inherit;
            font-weight: 900;
            cursor: pointer;
            text-align: left;
        }

        .agent-history-link:hover { text-decoration: underline; text-underline-offset: 3px; }

        .agent-filter-bar {
            display: flex;
            align-items: center;
            gap: 7px;
            flex-wrap: wrap;
        }

        .agent-filter-btn {
            min-height: 30px;
            padding: 5px 12px;
            border: 1px solid var(--bdr);
            border-radius: 999px;
            background: rgba(255,255,255,.045);
            color: var(--dim);
            font-size: 11px;
            font-weight: 800;
            cursor: pointer;
            transition: background .16s ease, border-color .16s ease, color .16s ease;
        }

        .agent-filter-btn:hover {
            color: var(--text);
            border-color: rgba(239,68,68,.4);
        }

        .agent-filter-btn.active {
            color: #fff;
            border-color: var(--acc);
            background: var(--acc);
            box-shadow: 0 6px 16px var(--accg);
        }

        .agent-filter-empty {
            padding: 22px 14px;
            border: 1px dashed var(--bdr);
            border-radius: 12px;
            color: var(--dim);
            font-size: 13px;
            text-align: center;
        }

        .agent-row {
            display: flex;
            flex-direction: column;
            gap: 0;
            background:
                linear-gradient(180deg, rgba(255, 255, 255, .04), rgba(255, 255, 255, .02)),
                var(--card);
            border: 1px solid var(--bdr);
            border-radius: 14px;
            box-shadow: var(--shadow);
            overflow: hidden;
            min-width: 0;
        }

        .agent-row-header {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
            padding: 12px 14px;
        }

        .agent-row-commission-wrap {
            border-top: 1px solid var(--bdr);
            padding: 0;
        }

        .agent-comm-tbl-wrap {
            overflow-x: auto;
        }

        .agent-comm-tbl {
            width: 100%;
            border-collapse: collapse;
        }

        .agent-comm-tbl thead th {
            font-size: 10px;
            font-weight: 800;
            color: var(--dim);
            text-transform: uppercase;
            letter-spacing: .07em;
            padding: 8px 14px;
            text-align: left;
            border-bottom: 1px solid var(--bdr);
            background: rgba(255,255,255,.025);
        }

        .agent-comm-tbl tbody tr {
            border-bottom: 1px solid var(--bdr);
            transition: background .15s;
        }

        .agent-comm-tbl tbody tr:last-child {
            border-bottom: none;
        }

        .agent-comm-tbl tbody tr:hover td {
            background: rgba(239,68,68,.045);
        }

        .agent-comm-tbl tbody td {
            padding: 9px 14px;
            font-size: 12px;
            color: var(--text);
            vertical-align: middle;
        }

        .comm-badge-paid {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            background: rgba(52,211,153,.15);
            color: #34d399;
            border: 1px solid rgba(52,211,153,.3);
        }

        .comm-badge-unpaid {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            background: rgba(239,68,68,.15);
            color: #ef4444;
            border: 1px solid rgba(239,68,68,.3);
        }

        .comm-no-records {
            text-align: center;
            padding: 16px;
            font-size: 12px;
            color: var(--dim);
        }

        .agent-row-main {
            min-width: 0;
            overflow: hidden;
            cursor: pointer;
        }

        .agent-row-main:hover .agent-row-name,
        .agent-row-main:hover .agent-row-email {
            text-decoration: underline;
            text-underline-offset: 2px;
        }

        .agent-row-name {
            color: var(--acc);
            font-size: 14px;
            font-weight: 850;
            line-height: 1.15;
            text-decoration: none;
            display: block;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .agent-row-email {
            color: var(--dim);
            font-size: 11px;
            line-height: 1.3;
            margin-top: 3px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .agent-row-col {
            min-width: 0;
        }

        .agent-row-label {
            color: var(--dim);
            font-size: 9px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .5px;
            margin-bottom: 3px;
        }

        .agent-row-value {
            color: var(--text);
            font-size: 13px;
            font-weight: 850;
            line-height: 1.2;
            word-break: break-word;
        }

        .agent-row-ref {
            font-family: monospace;
            color: var(--acc);
        }

        .agent-row-pct {
            width: 100%;
            max-width: 108px;
            padding: 5px 8px;
            text-align: center;
            font-size: 13px;
            font-weight: 800;
        }

        .agent-row-commission {
            color: var(--acc);
            font-size: 14px;
            font-weight: 900;
        }

        .agent-pause-exempt {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            color: var(--text);
            font-size: 11px;
            font-weight: 750;
            line-height: 1.25;
            cursor: pointer;
        }

        .agent-pause-exempt input {
            width: 17px;
            height: 17px;
            margin: 0;
            accent-color: var(--acc);
            flex: 0 0 auto;
        }

        .agent-row-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 7px;
            justify-content: flex-end;
        }

        .agent-row-actions .btn {
            min-width: 90px;
        }

        #toast {
            border-radius: 999px;
            box-shadow: 0 16px 38px var(--accg);
        }

        .mob-bar {
            height: 58px;
            background: rgba(8, 11, 18, .88);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
        }

        .ham {
            border-radius: 12px;
            background: rgba(255, 255, 255, .06);
        }

        .ham span {
            background: var(--text);
        }

        @media(max-width:1100px) {
            .stats-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        @media(max-width:768px) {
            .main {
                padding: 74px 12px 18px;
                max-height: none;
                overflow-y: visible;
            }

            .sb {
                width: min(86vw, 312px);
                padding-top: 14px;
                box-shadow: 22px 0 70px rgba(0, 0, 0, .55);
            }

            .stats-grid,
            #dep-summary-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            }

            .stat-card {
                min-height: 98px;
                padding: 14px;
            }

            .card {
                padding: 15px;
                border-radius: 15px;
                margin-bottom: 12px;
            }

            .panel>div:first-child {
                align-items: flex-start !important;
            }

            .panel>div:first-child,
            .panel>div:first-child>div {
                gap: 8px !important;
            }

            .btn {
                min-height: 40px;
                width: auto;
                max-width: 100%;
            }

            .tbl {
                min-width: 680px;
            }

            .match-row,
            #sel-list .sel-row {
                grid-template-columns: 1fr !important;
            }

            .agent-grid {
                grid-template-columns: 1fr;
            }

            .agent-stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .agent-ref-box {
                grid-template-columns: 1fr;
            }

            .agent-pct-input {
                width: 100%;
            }

            .agent-actions .btn {
                flex-basis: calc(50% - 4px);
                width: auto;
            }

            .agent-row {
                grid-template-columns: 1fr 1fr;
                align-items: start;
            }

            .agent-row-main {
                grid-column: 1 / -1;
            }

            .agent-row-actions {
                grid-column: 1 / -1;
                justify-content: flex-start;
            }

            .agent-row-pct {
                max-width: none;
            }

            .agent-row-payout {
                grid-column: 1 / -1;
            }

            [style*="grid-template-columns:1fr 1fr"],
            [style*="grid-template-columns:1fr 1fr 1fr"],
            [style*="grid-template-columns:1fr 1fr 1fr 1fr"],
            [style*="grid-template-columns:repeat(4,1fr)"] {
                grid-template-columns: 1fr !important;
            }

            [style*="overflow-x:auto"] {
                margin-inline: -2px;
                padding-bottom: 4px;
            }
        }

        @media(max-width:430px) {
            .stats-grid,
            #dep-summary-grid {
                grid-template-columns: 1fr !important;
            }

            .agent-stats {
                grid-template-columns: 1fr;
            }

            .agent-actions .btn {
                flex-basis: 100%;
                width: 100%;
            }

            .agent-row {
                grid-template-columns: 1fr;
            }

            .agent-row-actions {
                justify-content: stretch;
            }

            .agent-row-actions .btn {
                width: 100%;
                min-width: 0;
            }

            .mob-bar h2 {
                font-size: 15px;
            }

            .btn {
                width: 100%;
            }
        }

        /* Admin clarity pass — overrides only, no workflow changes */
        :root {
            --bg: #0b111b;
            --card: #111827;
            --card-2: #151f2f;
            --surface: #1a2536;
            --surface-2: #202d42;
            --bdr: rgba(148, 163, 184, .18);
            --bdr-strong: rgba(239, 68, 68, .42);
            --text: #f8fafc;
            --dim: #b6c2d3;
            --muted: #8ea0b6;
            --acc: #ef4444;
            --acc-2: #dc2626;
            --accg: rgba(239, 68, 68, .22);
            --green: #22c55e;
            --red: #ef4444;
            --orange: #f59e0b;
            --radius: 10px;
            --shadow: 0 14px 32px rgba(0, 0, 0, .24);
            --bg-card: var(--card);
            --text-main: var(--text);
        }

        html,
        body {
            min-height: 100%;
            background: var(--bg) !important;
        }

        body {
            color: var(--text);
            line-height: 1.45;
        }

        body::before,
        .stat-card::after,
        .card-title::before {
            display: none !important;
        }

        .sb {
            position: sticky;
            top: 0;
            height: 100vh;
            width: 264px;
            background: #0f1724;
            border-right: 1px solid var(--bdr);
            box-shadow: none;
            overflow-y: auto;
            backdrop-filter: none !important;
            -webkit-backdrop-filter: none !important;
        }

        .sb-logo {
            padding: 8px 12px 18px;
        }

        .sb-logo h1 {
            font-size: 22px;
            letter-spacing: 0;
        }

        .sb-logo p {
            color: var(--muted);
        }

        .nav-i {
            color: var(--dim);
            font-size: 13px;
            border-radius: 9px;
            margin: 2px 0;
        }

        .nav-i i {
            background: rgba(148, 163, 184, .12);
            color: var(--dim);
        }

        .nav-i:hover,
        .nav-i.active {
            background: rgba(239, 68, 68, .12);
            color: var(--text);
            box-shadow: inset 0 0 0 1px var(--bdr-strong);
        }

        .main {
            padding: 24px;
            background: var(--bg);
        }

        .panel > h2,
        .panel > div:first-child h2 {
            color: var(--text) !important;
            font-size: 22px !important;
            line-height: 1.15 !important;
            letter-spacing: 0 !important;
            text-transform: none !important;
        }

        .card,
        .stat-card,
        #user-modal {
            background: var(--card) !important;
            border: 1px solid var(--bdr) !important;
            border-radius: var(--radius) !important;
            box-shadow: var(--shadow);
            backdrop-filter: none;
            -webkit-backdrop-filter: none;
        }

        .card {
            padding: 18px;
        }

        .card-title {
            color: var(--text);
            font-size: 12px;
            letter-spacing: .06em;
            margin-bottom: 14px;
        }

        .stats-grid,
        .stat-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(180px, 1fr));
            gap: 12px;
            margin-bottom: 18px;
        }

        .stat-card {
            min-height: 104px;
            padding: 16px;
        }

        .stat-lbl {
            color: var(--muted);
            font-size: 10px;
        }

        .stat-val {
            color: var(--text);
            font-size: clamp(22px, 2.4vw, 30px);
            font-style: normal;
            letter-spacing: 0;
            word-break: break-word;
        }

        label {
            color: var(--dim);
            font-size: 11px;
            letter-spacing: .05em;
        }

        .inp,
        select.inp,
        textarea.inp,
        input[style*="background:var(--bg-card)"] {
            min-height: 42px;
            background: var(--surface) !important;
            border: 1px solid var(--bdr) !important;
            color: var(--text) !important;
            border-radius: 9px !important;
        }

        .inp::placeholder {
            color: #6f8199;
        }

        .inp:focus,
        select.inp:focus,
        textarea.inp:focus {
            border-color: var(--acc) !important;
            box-shadow: 0 0 0 3px rgba(239, 68, 68, .14);
            background: var(--surface-2) !important;
        }

        select.inp option {
            background: #111827;
            color: var(--text);
        }

        .btn {
            min-height: 38px;
            border-radius: 9px;
            font-weight: 800;
            box-shadow: none;
            letter-spacing: 0;
        }

        .btn:hover {
            transform: none;
        }

        .btn-acc {
            background: var(--acc) !important;
            color: #171100 !important;
            border: 1px solid var(--acc) !important;
        }

        .btn-blue {
            background: rgba(239, 68, 68, .12) !important;
            color: var(--acc) !important;
            border: 1px solid rgba(239, 68, 68, .35) !important;
        }

        .btn-red {
            background: rgba(239, 68, 68, .13) !important;
            color: #fecaca !important;
            border: 1px solid rgba(239, 68, 68, .35) !important;
        }

        .btn-ghost {
            background: var(--surface) !important;
            color: var(--text) !important;
            border: 1px solid var(--bdr) !important;
        }

        .btn-ghost:hover,
        .btn-blue:hover {
            border-color: var(--acc) !important;
            color: var(--acc) !important;
        }

        .inner-tabs {
            gap: 6px;
            padding: 4px;
            border: 1px solid var(--bdr);
            background: rgba(15, 23, 36, .7);
            border-radius: 11px;
            width: max-content;
            max-width: 100%;
            overflow-x: auto;
        }

        .inner-tab {
            color: var(--dim);
            background: transparent;
            border: 0;
            border-radius: 8px;
        }

        .inner-tab.active {
            background: var(--acc) !important;
            color: #171100 !important;
            box-shadow: none;
        }

        .tbl {
            min-width: 840px;
            border-spacing: 0;
            border-collapse: separate;
        }

        .tbl th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: #0f1724;
            color: var(--dim);
            padding: 11px 12px;
            border-top: 1px solid var(--bdr);
            border-bottom: 1px solid var(--bdr);
        }

        .tbl td {
            background: #141e2d;
            color: var(--text);
            border-top: 0;
            border-bottom: 1px solid rgba(148, 163, 184, .12);
            padding: 12px;
        }

        .tbl tr td:first-child,
        .tbl tr td:last-child {
            border-radius: 0;
        }

        .tbl tbody tr:hover td {
            background: #19263a;
            border-color: rgba(239, 68, 68, .22);
        }

        [style*="overflow-x:auto"] {
            border: 1px solid var(--bdr);
            border-radius: 10px;
            background: rgba(15, 23, 36, .5);
        }

        [style*="overflow-x:auto"] .tbl th:first-child,
        [style*="overflow-x:auto"] .tbl td:first-child {
            padding-left: 14px;
        }

        .badge {
            border-radius: 999px;
            padding: 4px 10px;
            font-size: 10px;
        }

        .b-run {
            background: rgba(239, 68, 68, .14);
            color: var(--acc);
            border: 1px solid rgba(239, 68, 68, .32);
        }

        .b-won {
            background: rgba(34, 197, 94, .14);
            color: #86efac;
            border: 1px solid rgba(34, 197, 94, .32);
        }

        .b-lost {
            background: rgba(239, 68, 68, .14);
            color: #fca5a5;
            border: 1px solid rgba(239, 68, 68, .32);
        }

        #sel-list .sel-row,
        .match-row {
            background: var(--surface) !important;
            border: 1px solid var(--bdr) !important;
            border-radius: 10px;
        }

        #toast {
            border-radius: 10px;
            box-shadow: 0 16px 36px rgba(0, 0, 0, .34);
        }

        .mob-bar {
            background: #0f1724;
            border-bottom: 1px solid var(--bdr);
        }

        @media(max-width:1180px) {
            .stats-grid,
            .stat-grid,
            #dep-summary-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            }
        }

        @media(max-width:768px) {
            body {
                display: block;
            }

            .mob-bar {
                z-index: 1000;
            }

            .sb-overlay {
                z-index: 900;
                background: rgba(3, 7, 18, .74);
                backdrop-filter: none !important;
                -webkit-backdrop-filter: none !important;
            }

            .sb {
                position: fixed;
                top: 0;
                left: 0;
                height: 100vh;
                width: min(88vw, 310px);
                z-index: 1100;
                background: #0f1724 !important;
                transform: translateX(-104%);
                overflow-y: auto;
                -webkit-overflow-scrolling: touch;
                padding-bottom: 28px;
                box-shadow: 18px 0 44px rgba(0, 0, 0, .48);
                backdrop-filter: none !important;
                -webkit-backdrop-filter: none !important;
            }

            .sb.open {
                transform: translateX(0);
            }

            .sb-logo,
            .sb nav,
            .sb > div:last-child {
                opacity: 1 !important;
                filter: none !important;
            }

            .nav-i {
                color: #e5edf8;
                background: rgba(255, 255, 255, .035);
                margin: 5px 0;
                min-height: 46px;
                font-size: 14px;
            }

            .nav-i i {
                color: #f8fafc;
                background: rgba(148, 163, 184, .16);
            }

            .main {
                padding: 74px 12px 22px;
                max-height: none;
                overflow: visible;
            }

            .card {
                padding: 14px;
            }

            .stats-grid,
            .stat-grid,
            #dep-summary-grid {
                grid-template-columns: 1fr !important;
            }

            .inner-tabs {
                width: 100%;
            }

            .tbl {
                min-width: 760px;
            }

            [style*="grid-template-columns:1fr 1fr"],
            [style*="grid-template-columns:1fr 1fr 1fr"],
            [style*="grid-template-columns:1fr 1fr 1fr 1fr"],
            [style*="grid-template-columns:repeat(4,1fr)"] {
                grid-template-columns: 1fr !important;
            }

            #sel-list .sel-row,
            .match-row {
                grid-template-columns: 1fr !important;
            }
        }

        @media(max-width:430px) {
            .panel > h2,
            .panel > div:first-child h2 {
                font-size: 19px !important;
            }

            .btn {
                width: 100%;
            }

            .stat-card {
                min-height: auto;
            }
        }
    </style>
</head>

<body>

    <!-- Mobile topbar -->
    <div class="mob-bar" id="mobBar">
        <div class="ham" id="hamBtn" onclick="toggleSidebar()">
            <span></span><span></span><span></span>
        </div>
        <h2>Prime<span>Stake</span> <span
                style="font-size:10px;color:var(--dim);font-style:normal;font-weight:700">Admin</span></h2>
    </div>
    <div class="sb-overlay" id="sbOverlay" onclick="toggleSidebar()"></div>

    <!-- SIDEBAR -->
    <aside class="sb" id="sidebar">
        <div class="sb-logo">
            <h1>Prime<span>Stake</span></h1>
            <p>Admin Control Panel</p>
        </div>
        <nav>
            <div class="nav-i active" onclick="closeSidebar();goto('overview',this)"><i
                    class="fa-solid fa-chart-pie"></i> Overview</div>
            <div class="nav-i" onclick="closeSidebar();goto('matches',this)"><i class="fa-solid fa-futbol"></i> Custom
                Matches</div>
            <div class="nav-i" onclick="closeSidebar();goto('codes',this)"><i class="fa-solid fa-ticket"></i> Booking
                Codes</div>
            <div class="nav-i" onclick="closeSidebar();goto('tickets',this)"><i class="fa-solid fa-list-check"></i>
                Ticket Settlement</div>
            <div class="nav-i" onclick="closeSidebar();goto('withdrawals',this)"><i
                    class="fa-solid fa-money-bill-wave"></i> Withdrawals</div>
            <div class="nav-i" onclick="closeSidebar();goto('deposits',this)"><i
                    class="fa-solid fa-arrow-down-to-bracket"></i> Deposits</div>
            <div class="nav-i" onclick="closeSidebar();goto('users',this)"><i class="fa-solid fa-users"></i> Users &
                Balances</div>
            <div class="nav-i" onclick="closeSidebar();goto('flutterwave',this)"><i class="fa-solid fa-bolt"></i>
                Flutterwave V4</div>
            <div class="nav-i" onclick="closeSidebar();goto('settings',this)"><i class="fa-solid fa-sliders"></i>
                Dashboard Settings</div>
            <div class="nav-i" onclick="closeSidebar();goto('agents',this)"><i class="fa-solid fa-user-shield"></i>
                Agents</div>
            <div class="nav-i" onclick="closeSidebar();goto('casino',this)"><i class="fa-solid fa-gamepad"></i> Casino
                Control</div>
            <div class="nav-i" onclick="closeSidebar();goto('history',this)"><i class="fa-solid fa-clock-rotate-left"></i> History</div>
        </nav>
        <div style="margin-top:auto;padding:16px 20px;border-top:1px solid var(--bdr)">
            <a href="dashboard.php"
                style="color:var(--dim);font-size:12px;font-weight:700;text-decoration:none;display:flex;align-items:center;gap:8px"><i
                    class="fa-solid fa-arrow-left"></i> Back to App</a>
            <a href="admin.php?admin_logout=1"
                style="color:#ef4444;font-size:12px;font-weight:700;text-decoration:none;display:flex;align-items:center;gap:8px;margin-top:12px"><i
                    class="fa-solid fa-right-from-bracket"></i> Logout Admin</a>
        </div>
    </aside>

    <!-- MAIN -->
    <main class="main">

        <!-- ══ OVERVIEW ══ -->
        <div id="panel-overview" class="panel active">
            <h2
                style="font-family:SF Pro Display,-apple-system, BlinkMacSystemFont, system-ui,sans-serif;font-size:22px;font-weight:800;margin:0 0 20px;font-style:italic">
                OVERVIEW</h2>
            <div class="stats-grid" id="statsGrid">
                <div class="stat-card">
                    <div class="stat-lbl">Total Users</div>
                    <div class="stat-val" id="s-users">—</div>
                </div>
                <div class="stat-card" style="cursor:pointer" onclick="goto('agents',document.querySelector('[data-panel=agents]'))">
                    <div class="stat-lbl">Agent Commission Due</div>
                    <div class="stat-val" style="color:#fbbf24" id="s-comm-due">—</div>
                    <div style="font-size:10px;color:var(--dim);margin-top:2px">Unpaid to agents</div>
                </div>
                <div class="stat-card">
                    <div class="stat-lbl">Running Tickets</div>
                    <div class="stat-val" id="s-running">—</div>
                </div>
                <div class="stat-card" style="cursor:pointer"
                    onclick="goto('deposits',document.querySelector('.nav-i:nth-child(6)'))">
                    <div class="stat-lbl">Total Deposits</div>
                    <div class="stat-val" style="color:var(--acc)" id="s-deposits">—</div>
                    <div style="font-size:10px;color:var(--dim);margin-top:2px" id="s-deposit-count">— transactions
                    </div>
                </div>
                <div class="stat-card" style="cursor:pointer"
                    onclick="goto('deposits',document.querySelector('.nav-i:nth-child(6)'))">
                    <div class="stat-lbl">Today's Deposits</div>
                    <div class="stat-val" style="color:#34d399" id="s-deposits-today">—</div>
                    <div style="font-size:10px;color:var(--dim);margin-top:2px" id="s-deposit-count-today">—
                        transactions</div>
                </div>
                <div class="stat-card">
                    <div class="stat-lbl">Pending Withdrawals</div>
                    <div class="stat-val" style="color:#f97316" id="s-pend">—</div>
                </div>
                <div class="stat-card">
                    <div class="stat-lbl">Active Admin Matches</div>
                    <div class="stat-val" style="color:var(--acc)" id="s-ama">—</div>
                </div>
                <div class="stat-card">
                    <div class="stat-lbl">Admin Codes</div>
                    <div class="stat-val" id="s-acodes">—</div>
                </div>
            </div>
            <div class="card" style="border-color:rgba(239,68,68,.28);background:linear-gradient(135deg,rgba(239,68,68,.08),rgba(19,22,28,.98));margin-bottom:18px">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
                    <div>
                        <div class="card-title" style="margin-bottom:4px;color:#fca5a5">Database History</div>
                        <p style="font-size:12px;color:var(--dim);margin:0">Clears tickets, transactions, booking-code history, casino/game history, notifications and commission history. Users and current balances are not deleted.</p>
                    </div>
                    <button class="btn btn-red" onclick="clearAdminHistory()" style="white-space:nowrap">
                        <i class="fa-solid fa-trash-can"></i> Clear All History
                    </button>
                </div>
            </div>
            <div id="overview-tickets"></div>
        </div>

        <!-- ══ CUSTOM MATCHES ══ -->
        <div id="panel-matches" class="panel">
            <div
                style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:8px">
                <h2
                    style="font-family:SF Pro Display,-apple-system, BlinkMacSystemFont, system-ui,sans-serif;font-size:20px;font-weight:800;margin:0;font-style:italic">
                    CUSTOM MATCHES</h2>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <button class="btn btn-blue" onclick="autoCloseExpiredMatches()" id="auto-close-btn"
                        title="Mark all timer-expired matches as Full Time and auto-settle their tickets">
                        <i class="fa-solid fa-flag-checkered"></i> Mark Expired FT
                    </button>
                    <button class="btn btn-acc" onclick="showMatchForm()"><i class="fa-solid fa-plus"></i> New
                        Match</button>
                </div>
            </div>

            <div class="card" id="ai-match-settings-card" style="margin-bottom:16px;border-color:rgba(59,130,246,.28);background:var(--card2)">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px">
                    <div>
                        <div class="card-title" style="margin-bottom:3px">Automatic Match Generation</div>
                        <p style="font-size:12px;color:var(--dim);margin:0">Schedule complete match batches inside Custom Matches.</p>
                    </div>
                    <span id="ai-match-api-status" style="font-size:11px;font-weight:800;color:var(--dim)">Loading status...</span>
                </div>

                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(175px,1fr));gap:12px;margin-bottom:12px">
                    <div class="inp-group" style="margin:0">
                        <label>Daily Automation</label>
                        <label style="display:flex;align-items:center;gap:9px;min-height:42px;cursor:pointer">
                            <input type="checkbox" id="set-ai-match-enabled" style="width:17px;height:17px;accent-color:var(--acc)">
                            <span style="font-size:13px;font-weight:700">Generate each day</span>
                        </label>
                    </div>
                    <div class="inp-group" style="margin:0">
                        <label>Matches Per Day</label>
                        <input class="inp" type="number" id="set-ai-match-count" min="1" max="8" value="3">
                    </div>
                    <div class="inp-group" style="margin:0">
                        <label>Kickoff Times</label>
                        <input class="inp" id="set-ai-match-times" value="12:00,16:00,20:00" placeholder="12:00,12:00,16:00">
                        <p style="font-size:11px;color:var(--dim);margin:4px 0 0">Duplicate times are allowed, for example 12:00,12:00</p>
                    </div>
                    <div class="inp-group" style="margin:0">
                        <label>Daily Generation Time</label>
                        <input class="inp" type="time" id="set-ai-match-generate-at" value="00:05">
                    </div>
                    <div class="inp-group" style="margin:0">
                        <label>Badge Method</label>
                        <select class="inp" id="set-ai-match-logo-mode">
                            <option value="local">Economy Local PNG (unique crest identities; no image credits)</option>
                            <option value="ai_economy">OpenAI Economy PNG (unique art direction; low quality)</option>
                        </select>
                    </div>
                    <div class="inp-group" style="margin:0">
                        <label>Result Mode</label>
                        <select class="inp" id="set-ai-match-outcome-mode" onchange="updateAiOutcomeControls()">
                            <option value="mixed">Mixed Results</option>
                            <option value="draw">All Matches Draw</option>
                            <option value="exact">Exact Score For All</option>
                        </select>
                    </div>
                    <div class="inp-group" id="ai-match-exact-score-wrap" style="margin:0;display:none">
                        <label>Exact Score</label>
                        <input class="inp" id="set-ai-match-exact-score" value="2-2" placeholder="2-2" inputmode="numeric">
                    </div>
                    <div class="inp-group" style="margin:0">
                        <label>Minimum 1X2 Odds</label>
                        <input class="inp" type="number" step="0.01" min="1.01" max="99" id="set-ai-match-odds-min" value="1.20">
                    </div>
                    <div class="inp-group" style="margin:0">
                        <label>Maximum 1X2 Odds</label>
                        <input class="inp" type="number" step="0.01" min="1.02" max="99" id="set-ai-match-odds-max" value="9.00">
                    </div>
                    <div class="inp-group" style="margin:0">
                        <label>OpenAI API Key</label>
                        <input class="inp" type="password" id="set-openai-api-key" autocomplete="new-password" placeholder="Leave blank to keep saved key">
                        <p style="font-size:11px;color:var(--dim);margin:4px 0 0">Used for match data and AI badges when selected</p>
                    </div>
                </div>

                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    <button class="btn btn-ghost btn-sm" type="button" onclick="saveAiMatchSettings(this)">
                        <i class="fa-solid fa-floppy-disk"></i> Save Generator
                    </button>
                    <button class="btn btn-blue btn-sm" type="button" onclick="generateAiMatches(this)">
                        <i class="fa-solid fa-wand-magic-sparkles"></i> Generate Today's Matches
                    </button>
                    <span id="ai-match-last-run" style="font-size:12px;color:var(--dim)">No generation history loaded.</span>
                </div>
                <div id="ai-match-cron-wrap" style="display:none;margin-top:12px">
                    <label style="font-size:11px;font-weight:800;color:var(--dim)">NAMECHEAP CRON URL</label>
                    <div style="display:flex;gap:8px;margin-top:5px;align-items:center">
                        <input class="inp" id="ai-match-cron-url" readonly style="font-family:monospace;font-size:11px">
                        <button class="btn btn-ghost btn-sm" type="button" title="Copy cron URL" onclick="copyAiCronUrl()">
                            <i class="fa-regular fa-copy"></i>
                        </button>
                    </div>
                    <p style="font-size:11px;color:var(--dim);margin:5px 0 0">Run every 5 minutes. Automation creates one batch per date; you can generate additional same-day batches manually.</p>
                </div>
            </div>

            <!-- Create / Edit Form -->
            <div class="card" id="matchForm" style="display:none">
                <div class="card-title" id="matchFormTitle">Add New Match</div>
                <input type="hidden" id="mf-id">

                <!-- Row 1: Teams -->
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
                    <div class="inp-group" style="margin:0">
                        <label>Home Team</label>
                        <div style="display:flex;gap:6px;align-items:center">
                            <input class="inp" id="mf-home" placeholder="e.g. Arsenal" oninput="debounceLogo('home')"
                                style="flex:1">
                            <img id="mf-home-logo-preview" src=""
                                style="width:32px;height:32px;border-radius:6px;object-fit:contain;background:rgba(255,255,255,.06);display:none;flex-shrink:0;cursor:pointer"
                                onclick="document.getElementById('mf-home-logo-file').click()"
                                title="Click to change logo">
                        </div>
                        <input type="hidden" id="mf-home-logo">
                        <!-- Upload row -->
                        <div style="display:flex;align-items:center;gap:6px;margin-top:5px">
                            <label for="mf-home-logo-file"
                                style="text-transform:none;font-size:10px;color:var(--acc);font-weight:700;cursor:pointer;letter-spacing:0;display:flex;align-items:center;gap:4px;margin:0">
                                <i class="fa fa-upload" style="font-size:10px"></i> Upload logo
                            </label>
                            <input type="file" id="mf-home-logo-file" accept="image/*" style="display:none"
                                onchange="handleLogoUpload('home',this)">
                            <span id="mf-home-logo-fname"
                                style="font-size:9px;color:var(--dim);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:100px"></span>
                            <button type="button" id="mf-home-logo-clear" onclick="clearLogo('home')"
                                style="display:none;background:rgba(239,68,68,.12);border:none;color:#ef4444;border-radius:4px;padding:1px 6px;font-size:10px;cursor:pointer">✕</button>
                        </div>
                    </div>
                    <div class="inp-group" style="margin:0">
                        <label>Away Team</label>
                        <div style="display:flex;gap:6px;align-items:center">
                            <input class="inp" id="mf-away" placeholder="e.g. Chelsea" oninput="debounceLogo('away')"
                                style="flex:1">
                            <img id="mf-away-logo-preview" src=""
                                style="width:32px;height:32px;border-radius:6px;object-fit:contain;background:rgba(255,255,255,.06);display:none;flex-shrink:0;cursor:pointer"
                                onclick="document.getElementById('mf-away-logo-file').click()"
                                title="Click to change logo">
                        </div>
                        <input type="hidden" id="mf-away-logo">
                        <!-- Upload row -->
                        <div style="display:flex;align-items:center;gap:6px;margin-top:5px">
                            <label for="mf-away-logo-file"
                                style="text-transform:none;font-size:10px;color:var(--acc);font-weight:700;cursor:pointer;letter-spacing:0;display:flex;align-items:center;gap:4px;margin:0">
                                <i class="fa fa-upload" style="font-size:10px"></i> Upload logo
                            </label>
                            <input type="file" id="mf-away-logo-file" accept="image/*" style="display:none"
                                onchange="handleLogoUpload('away',this)">
                            <span id="mf-away-logo-fname"
                                style="font-size:9px;color:var(--dim);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:100px"></span>
                            <button type="button" id="mf-away-logo-clear" onclick="clearLogo('away')"
                                style="display:none;background:rgba(239,68,68,.12);border:none;color:#ef4444;border-radius:4px;padding:1px 6px;font-size:10px;cursor:pointer">✕</button>
                        </div>
                    </div>
                </div>

                <!-- Row 2: League + Section + Timing -->
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
                    <div class="inp-group" style="margin:0">
                        <label>League</label>
                        <input class="inp" id="mf-league" list="admin-league-presets" value="Featured"
                            placeholder="Choose or type a league">
                        <datalist id="admin-league-presets">
                            <option value="Premier League — England">
                            <option value="Championship — England">
                            <option value="La Liga — Spain">
                            <option value="Segunda Division — Spain">
                            <option value="Serie A — Italy">
                            <option value="Serie B — Italy">
                            <option value="Bundesliga — Germany">
                            <option value="2. Bundesliga — Germany">
                            <option value="Ligue 1 — France">
                            <option value="Ligue 2 — France">
                            <option value="Eredivisie — Netherlands">
                            <option value="Primeira Liga — Portugal">
                            <option value="Super Lig — Turkey">
                            <option value="Jupiler Pro League — Belgium">
                            <option value="Premiership — Scotland">
                            <option value="Champions League — Europe">
                            <option value="Europa League — Europe">
                            <option value="Conference League — Europe">
                            <option value="Major League Soccer — USA">
                            <option value="Liga MX — Mexico">
                            <option value="Serie A — Brazil">
                            <option value="Primera Division — Argentina">
                            <option value="Premier League — Ghana">
                            <option value="NPFL — Nigeria">
                            <option value="CAF Champions League — Africa">
                            <option value="International Friendlies">
                        </datalist>
                    </div>
                    <div class="inp-group" style="margin:0">
                        <label>📌 Show In Section</label>
                        <select class="inp" id="mf-section">
                            <option value="both">Both (Popular + Today)</option>
                            <option value="popular">🔥 Popular Events only</option>
                            <option value="today">📅 Today's Matches only</option>
                        </select>
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:12px;margin-bottom:12px">
                    <div class="inp-group" style="margin:0">
                        <label>📅 Match Date &amp; Time (Go Live)</label>
                        <input class="inp" type="datetime-local" id="mf-golive" style="color-scheme:dark">
                        <p style="font-size:10px;color:var(--dim);margin:3px 0 0">Leave blank = stays manual.</p>
                    </div>
                    <div class="inp-group" style="margin:0">
                        <label>1st Half (mins)</label>
                        <input class="inp" type="number" id="mf-first-half" placeholder="45" value="45" min="1"
                            max="90">
                        <p style="font-size:10px;color:var(--dim);margin:3px 0 0">How long 1st half runs.</p>
                    </div>
                    <div class="inp-group" style="margin:0">
                        <label>Half-Time Break (mins)</label>
                        <input class="inp" type="number" id="mf-ht-break" placeholder="15" value="15" min="1" max="30">
                        <p style="font-size:10px;color:var(--dim);margin:3px 0 0">HT rest duration.</p>
                    </div>
                    <div class="inp-group" style="margin:0">
                        <label>2nd Half (mins)</label>
                        <input class="inp" type="number" id="mf-duration" placeholder="50" value="50" min="1" step="1" inputmode="numeric">
                        <p style="font-size:10px;color:var(--dim);margin:3px 0 0">How long 2nd half runs.</p>
                    </div>
                </div>

                <!-- Row 3: Odds + suggest button -->
                <div style="margin-bottom:4px">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
                        <label style="margin:0">Odds</label>
                        <button class="btn btn-ghost btn-sm" onclick="suggestOdds()" type="button">✨
                            Auto-Suggest</button>
                    </div>
                    <div id="odds-suggest-strip"
                        style="display:none;background:rgba(239,68,68,.05);border:1px solid rgba(239,68,68,.2);border-radius:10px;padding:10px 12px;margin-bottom:8px">
                        <p style="font-size:11px;color:var(--dim);margin:0 0 8px;font-weight:700">Pick a balance, then
                            fine-tune:</p>
                        <div style="display:flex;gap:6px;flex-wrap:wrap">
                            <button class="btn btn-ghost btn-sm" onclick="applyOddsPreset('fav_home')">Home Fav</button>
                            <button class="btn btn-ghost btn-sm" onclick="applyOddsPreset('balanced')">Balanced</button>
                            <button class="btn btn-ghost btn-sm" onclick="applyOddsPreset('fav_away')">Away Fav</button>
                            <button class="btn btn-ghost btn-sm" onclick="applyOddsPreset('high_odds')">High
                                Odds</button>
                        </div>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px">
                        <div class="inp-group" style="margin:0"><label>Home Win (1)</label><input class="inp"
                                type="number" step="0.01" id="mf-o1" value="2.10"></div>
                        <div class="inp-group" style="margin:0"><label>Draw (X)</label><input class="inp" type="number"
                                step="0.01" id="mf-oX" value="3.40"></div>
                        <div class="inp-group" style="margin:0"><label>Away Win (2)</label><input class="inp"
                                type="number" step="0.01" id="mf-o2" value="3.60"></div>
                    </div>
                </div>

                <!-- Row 4: Score + Status (collapsed by default) -->
                <div style="margin-top:12px">
                    <button class="btn btn-ghost btn-sm" type="button" onclick="toggleAdvanced()" id="adv-toggle"
                        style="margin-bottom:10px">▶ Score / Status / Advanced</button>
                    <div id="adv-fields" style="display:none">
                        <!-- Target Score row — admin sets final score, system auto-spreads it live -->
                        <div
                            style="background:rgba(239,68,68,0.06);border:1px solid rgba(239,68,68,0.2);border-radius:10px;padding:12px 14px;margin-bottom:12px">
                            <div
                                style="font-size:11px;font-weight:800;color:var(--acc);text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px">
                                🎯 Auto Score Spreading</div>
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;align-items:end">
                                <div class="inp-group" style="margin:0">
                                    <label>Target Score (e.g. <strong>1-3</strong>)</label>
                                    <input class="inp" id="mf-target-score" placeholder="e.g. 1-3" pattern="\d+-\d+"
                                        oninput="previewTargetScore(this.value)"
                                        style="font-size:18px;font-weight:900;text-align:center;letter-spacing:2px">
                                </div>
                                <div style="font-size:11px;color:var(--dim);line-height:1.5" id="target-score-hint">
                                    Set the <strong>final score</strong> the match should reach.<br>
                                    During live play the score auto-spreads goal-by-goal.
                                </div>
                            </div>
                            <div id="target-score-preview"
                                style="margin-top:8px;font-size:12px;color:var(--acc);display:none"></div>
                            <div class="inp-group" style="margin:10px 0 0">
                                <label>Goal Minutes (optional)</label>
                                <input class="inp" id="mf-goal-minutes" placeholder="10, 34, 71"
                                    oninput="previewTargetScore(document.getElementById('mf-target-score').value)">
                                <p style="font-size:10px;color:var(--dim);margin:3px 0 0">
                                    Comma-separated minutes for each goal. Leave blank to use even spacing.
                                </p>
                            </div>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:12px;margin-bottom:12px">
                            <div class="inp-group" style="margin:0"><label>Current Score (Home)</label><input
                                    class="inp" type="number" id="mf-sh" placeholder="—"></div>
                            <div class="inp-group" style="margin:0"><label>Current Score (Away)</label><input
                                    class="inp" type="number" id="mf-sa" placeholder="—"></div>
                            <div class="inp-group" style="margin:0"><label>Status</label>
                                <select class="inp" id="mf-status">
                                    <option value="Not Started">Not Started</option>
                                    <option value="1H">1st Half</option>
                                    <option value="HT">Half Time</option>
                                    <option value="2H">2nd Half</option>
                                    <option value="FT">Full Time</option>
                                </select>
                            </div>
                            <div class="inp-group" style="margin:0"><label>Elapsed (min)</label><input class="inp"
                                    type="number" id="mf-elapsed" value="0"></div>
                        </div>
                        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px">
                            <div class="inp-group" style="margin:0"><label>Popular Position (1=first, 2=second)</label><input class="inp"
                                    type="number" id="mf-order" value="1" min="1"></div>
                            <div class="inp-group"
                                style="margin:0;display:flex;align-items:center;gap:8px;padding-top:18px">
                                <input type="checkbox" id="mf-lock"
                                    style="width:15px;height:15px;accent-color:var(--acc)">
                                <label style="margin:0;cursor:pointer;font-size:12px" for="mf-lock">🔒 Lock Odds</label>
                            </div>
                            <div class="inp-group"
                                style="margin:0;display:flex;align-items:center;gap:8px;padding-top:18px">
                                <input type="checkbox" id="mf-auto-odds" checked
                                    style="width:15px;height:15px;accent-color:var(--acc)">
                                <label style="margin:0;cursor:pointer;font-size:12px" for="mf-auto-odds">Automatic Live Odds</label>
                            </div>
                            <div class="inp-group"
                                style="margin:0;display:flex;align-items:center;gap:8px;padding-top:18px">
                                <input type="checkbox" id="mf-pinned" checked
                                    style="width:15px;height:15px;accent-color:var(--acc)">
                                <label style="margin:0;cursor:pointer;font-size:12px" for="mf-pinned">📌 Pin to
                                    Popular</label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Timer status banner (shown when editing a match with active timer) -->
                <div id="mf-timer-banner"
                    style="display:none;align-items:center;gap:10px;background:rgba(0,0,0,.25);border:1px solid var(--bdr);border-radius:10px;padding:10px 14px;margin-top:12px;font-size:12px;font-weight:700">
                </div>

                <div style="display:flex;gap:10px;margin-top:16px">
                    <button class="btn btn-acc" onclick="saveMatch()"><i class="fa-solid fa-save"></i> Save
                        Match</button>
                    <button class="btn btn-ghost"
                        onclick="document.getElementById('matchForm').style.display='none'">Cancel</button>
                </div>
            </div>

            <div id="matchesList"></div>
        </div>

        <!-- ══ BOOKING CODES ══ -->
        <div id="panel-codes" class="panel">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
                <h2
                    style="font-family:SF Pro Display,-apple-system, BlinkMacSystemFont, system-ui,sans-serif;font-size:20px;font-weight:800;margin:0;font-style:italic">
                    BOOKING CODES</h2>
                <button class="btn btn-acc" onclick="showCodeForm()"><i class="fa-solid fa-plus"></i> New Code</button>
            </div>

            <div class="inner-tabs">
                <div class="inner-tab active" onclick="switchCodeTab('manual',this)">Manual Create</div>
                <div class="inner-tab" onclick="switchCodeTab('frommatch',this)">📋 From My Matches</div>
                <div class="inner-tab" onclick="switchCodeTab('sporty',this)">Import Sportybet</div>
                <div class="inner-tab" onclick="switchCodeTab('list',this)">All Codes</div>
            </div>

            <!-- Manual create -->
            <div id="code-tab-manual" class="card">
                <div class="card-title">Create Admin Booking Code</div>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:12px;margin-bottom:12px">
                    <div class="inp-group" style="margin:0">
                        <label>Code (leave blank = auto)</label>
                        <input class="inp" id="cc-code" placeholder="e.g. MYCODE1" style="text-transform:uppercase">
                    </div>
                    <div class="inp-group" style="margin:0">
                        <label>Suggested Stake (GHS)</label>
                        <input class="inp" type="number" step="0.01" id="cc-stake" value="0">
                    </div>
                    <div class="inp-group" style="margin:0">
                        <label>Min Stake (GHS) <span style="color:var(--acc)">enforce</span></label>
                        <input class="inp" type="number" step="0.01" min="0" id="cc-min-stake" value="0"
                            placeholder="0 = no minimum">
                    </div>
                    <div class="inp-group" style="margin:0">
                        <label>Reveal At (optional)</label>
                        <input class="inp" type="datetime-local" id="cc-reveal">
                    </div>
                </div>
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px">
                    <input type="checkbox" id="cc-hidden-all" style="width:15px;height:15px;accent-color:var(--acc)">
                    <label for="cc-hidden-all" style="font-size:12px;font-weight:700;color:var(--dim);cursor:pointer">🙈
                        Hide ALL selections until revealed (users see ??? until you unhide)</label>
                </div>

                <!-- Selections builder -->
                <div class="card-title" style="margin-bottom:10px">Selections</div>
                <div style="display:grid;grid-template-columns:1fr 1fr 80px 70px 70px 30px;gap:8px;margin-bottom:6px">
                    <span style="font-size:10px;color:var(--dim);font-weight:800;text-transform:uppercase">Home vs
                        Away</span>
                    <span style="font-size:10px;color:var(--dim);font-weight:800;text-transform:uppercase">Market —
                        Pick</span>
                    <span style="font-size:10px;color:var(--dim);font-weight:800;text-transform:uppercase">Odds</span>
                    <span style="font-size:10px;color:var(--dim);font-weight:800;text-transform:uppercase">Time</span>
                    <span style="font-size:10px;color:var(--dim);font-weight:800;text-transform:uppercase">Hide?</span>
                    <span></span>
                </div>
                <div id="sel-list"></div>
                <button class="btn btn-ghost btn-sm" onclick="addSelRow()" style="margin-bottom:14px"><i
                        class="fa-solid fa-plus"></i> Add Selection</button>
                <div style="display:flex;gap:8px;align-items:center">
                    <button class="btn btn-acc" onclick="saveCode()"><i class="fa-solid fa-save"></i> Save Code</button>
                    <span id="cc-result" style="font-size:13px"></span>
                </div>

                <!-- Post-save: same edit panel as Sporty import -->
                <div id="cc-post-save"
                    style="display:none;margin-top:20px;border-top:1px solid var(--bdr);padding-top:16px">
                    <div
                        style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:8px">
                        <div class="card-title" style="margin:0">Selections <span id="cc-sel-count"
                                style="color:var(--dim);font-size:11px;font-weight:600"></span></div>
                        <div style="display:flex;gap:6px;flex-wrap:wrap">
                            <button class="btn btn-ghost btn-sm" onclick="toggleCodeHide(currentManualId,1)">🙈 Hide
                                All</button>
                            <button class="btn btn-acc btn-sm" onclick="toggleCodeHide(currentManualId,0)">👁 Reveal
                                All</button>
                            <button class="btn btn-acc" onclick="saveAllSelRows(currentManualId,'cc-sel-list')"
                                style="padding:6px 14px;font-size:12px">💾 Save All Changes</button>
                        </div>
                    </div>
                    <div id="cc-sel-list"></div>
                </div>
            </div>

            <!-- From My Matches tab -->
            <div id="code-tab-frommatch" class="card" style="display:none">
                <div class="card-title">📋 Create Code From My Admin Matches</div>
                <p style="font-size:12px;color:var(--dim);margin-bottom:14px">Select matches you created, set the
                    prediction for each, and generate a booking code. Users who load this code will see your matches
                    pre-filled.</p>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:14px">
                    <div class="inp-group" style="margin:0"><label>Code (leave blank = auto)</label><input class="inp"
                            id="fm-code" placeholder="e.g. MYBET1" style="text-transform:uppercase"></div>
                    <div class="inp-group" style="margin:0"><label>Suggested Stake (GHS)</label><input class="inp"
                            type="number" step="0.01" id="fm-stake" value="0"></div>
                    <div class="inp-group" style="margin:0"><label>Min Stake (GHS)</label><input class="inp"
                            type="number" step="0.01" id="fm-min-stake" value="0" placeholder="0 = no minimum"></div>
                </div>

                <div class="card-title" style="margin-bottom:10px">Pick Your Matches</div>
                <div id="fm-matches-loading" style="color:var(--dim);font-size:13px">Loading your matches…</div>
                <div id="fm-matches-list" style="display:none"></div>

                <div style="display:flex;gap:10px;margin-top:14px;align-items:center">
                    <button class="btn btn-acc" onclick="createCodeFromMatches()"><i class="fa-solid fa-save"></i>
                        Generate Booking Code</button>
                    <button class="btn btn-ghost" onclick="loadFmMatches()"><i class="fa-solid fa-rotate-right"></i>
                        Refresh</button>
                    <span id="fm-result" style="font-size:13px"></span>
                </div>
            </div>

            <!-- Sportybet import -->
            <div id="code-tab-sporty" class="card" style="display:none">
                <div class="card-title">Import Sportybet Code</div>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:12px;margin-bottom:12px">
                    <div class="inp-group" style="margin:0">
                        <label>Sportybet Code</label>
                        <input class="inp" id="si-sporty" placeholder="Paste Sportybet code"
                            style="text-transform:uppercase" oninput="onSportyPaste(this.value)">
                    </div>
                    <div class="inp-group" style="margin:0">
                        <label>Internal Code <span style="color:var(--acc)">(auto-generated)</span></label>
                        <input class="inp" id="si-internal" placeholder="Auto — or type custom"
                            style="text-transform:uppercase">
                    </div>
                    <div class="inp-group" style="margin:0">
                        <label>Min Stake (GHS) <span style="color:var(--acc)">enforce</span></label>
                        <input class="inp" type="number" step="0.01" min="0" id="si-min-stake" value="0"
                            placeholder="0 = no minimum">
                    </div>
                    <div class="inp-group" style="margin:0"><label>Reveal At (optional)</label><input class="inp"
                            type="datetime-local" id="si-reveal"></div>
                </div>
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px">
                    <input type="checkbox" id="si-hide-all" style="width:15px;height:15px;accent-color:var(--acc)">
                    <label for="si-hide-all" style="font-size:12px;font-weight:700;color:var(--dim);cursor:pointer">🙈
                        Hide all teams/markets until reveal (users see ??? until you unhide)</label>
                </div>
                <button class="btn btn-acc" id="si-btn" onclick="importSporty(event)"><i
                        class="fa-solid fa-download"></i> Fetch & Import</button>
                <div id="sporty-result" style="margin-top:12px;font-size:13px"></div>
                <!-- Post-import: per-selection hide/unhide -->
                <div id="si-post-import" style="display:none;margin-top:16px">
                    <div
                        style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:8px">
                        <div class="card-title" style="margin:0">Imported Selections <span id="si-sel-count"
                                style="color:var(--dim);font-size:11px;font-weight:600"></span></div>
                        <div style="display:flex;gap:6px;flex-wrap:wrap">
                            <button class="btn btn-ghost btn-sm" onclick="toggleCodeHide(currentImportId,1)">🙈 Hide
                                All</button>
                            <button class="btn btn-acc btn-sm" onclick="toggleCodeHide(currentImportId,0)">👁 Reveal
                                All</button>
                            <button class="btn btn-acc" onclick="saveAllSelRows(currentImportId,'si-sel-list')"
                                style="padding:6px 14px;font-size:12px">💾 Save All Changes</button>
                        </div>
                    </div>
                    <div id="si-sel-list"></div>
                </div>
            </div>

            <!-- List of codes -->
            <div id="code-tab-list" style="display:none">
                <div id="codes-list"></div>
            </div>
        </div>

        <!-- ══ TICKET SETTLEMENT ══ -->
        <div id="panel-tickets" class="panel">
            <h2
                style="font-family:-apple-system,BlinkMacSystemFont,system-ui,sans-serif;font-size:20px;font-weight:800;margin:0 0 16px;font-style:italic">
                TICKET SETTLEMENT</h2>

            <!-- Cashout toggle bar -->
            <div id="cashout-toggle-bar"
                style="display:flex;align-items:center;justify-content:space-between;background:rgba(255,59,48,0.06);border:1px solid rgba(255,59,48,0.18);border-radius:12px;padding:12px 16px;margin-bottom:14px;flex-wrap:wrap;gap:10px">
                <div>
                    <p style="font-size:13px;font-weight:700;margin:0;color:var(--text)">Cashout for Players</p>
                    <p id="cashout-status-text" style="font-size:11px;color:var(--dim);margin:4px 0 0">Loading…</p>
                </div>
                <button id="cashout-toggle-btn" onclick="toggleCashoutLock()"
                    style="font-size:13px;font-weight:700;padding:10px 20px;border-radius:10px;border:none;cursor:pointer;min-width:140px;transition:all .2s">
                    …
                </button>
            </div>

            <div
                style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:12px">
                <div class="inner-tabs" style="margin:0">
                    <div class="inner-tab active" onclick="loadTickets('Running',this)">Running</div>
                    <div class="inner-tab" onclick="loadTickets('Won',this)">Won</div>
                    <div class="inner-tab" onclick="loadTickets('Lost',this)">Lost</div>
                    <div class="inner-tab" onclick="loadTickets('',this)">All</div>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <button class="btn btn-blue" onclick="runAutoSettle()" style="font-size:12px;padding:8px 14px"
                        id="auto-settle-btn">
                        <i class="fa-solid fa-robot"></i> Auto-Settle Tickets
                    </button>
                    <button class="btn btn-red" onclick="closeAllTickets('Lost')"
                        style="font-size:12px;padding:8px 14px">
                        <i class="fa-solid fa-ban"></i> Close All as Lost
                    </button>
                    <button class="btn btn-acc" onclick="closeAllTickets('Won')"
                        style="font-size:12px;padding:8px 14px;background:#22c55e;color:#000">
                        <i class="fa-solid fa-check-double"></i> Close All as Won
                    </button>
                    <button class="btn btn-ghost" onclick="closeAllTickets('Void')"
                        style="font-size:12px;padding:8px 14px">
                        <i class="fa-solid fa-rotate-left"></i> Void All
                    </button>
                </div>
            </div>
            <div id="tickets-table"></div>
        </div>

        <!-- ══ WITHDRAWALS ══ -->
        <div id="panel-withdrawals" class="panel">
            <h2
                style="font-family:-apple-system,BlinkMacSystemFont,system-ui,sans-serif;font-size:20px;font-weight:800;margin:0 0 16px;font-style:italic">
                WITHDRAWALS</h2>

            <!-- Withdrawal percentage bar -->
            <div
                style="display:flex;align-items:center;gap:12px;background:rgba(251,191,36,0.07);border:1px solid rgba(251,191,36,0.25);border-radius:12px;padding:12px 16px;margin-bottom:14px;flex-wrap:wrap">
                <div style="flex:1;min-width:180px">
                    <p style="font-size:12px;font-weight:700;color:#fbbf24;margin:0 0 2px">💰 Withdrawal Percentage</p>
                    <p style="font-size:11px;color:var(--dim);margin:0">Applied to new withdrawal requests as a percentage of the user's wallet balance</p>
                </div>
                <div style="display:flex;align-items:center;gap:8px">
                    <input type="number" id="comm-rate-input" min="0" max="100" step="0.1" value="15"
                        style="background:var(--bg-card);border:1px solid var(--bdr);border-radius:8px;padding:8px 12px;font-size:15px;font-weight:700;color:#fbbf24;width:80px;outline:none;font-family:inherit;text-align:center">
                    <span style="font-size:15px;font-weight:700;color:#fbbf24">%</span>
                    <button class="btn btn-acc btn-sm" onclick="saveCommRate()" style="padding:8px 16px">💾 Save
                        Rate</button>
                </div>
                <div id="comm-rate-status" style="font-size:11px;color:var(--acc);font-weight:700"></div>
            </div>

            <!-- Withdrawal verification amount -->
            <div
                style="display:flex;align-items:center;gap:12px;background:rgba(34,197,94,0.07);border:1px solid rgba(34,197,94,0.25);border-radius:12px;padding:12px 16px;margin-bottom:14px;flex-wrap:wrap">
                <div style="flex:1;min-width:180px">
                    <p style="font-size:12px;font-weight:700;color:#22c55e;margin:0 0 2px">Withdrawal Verification Deposit</p>
                    <p style="font-size:11px;color:var(--dim);margin:0">Amount Ghanaian users must deposit for each withdrawal verification step</p>
                </div>
                <div style="display:flex;align-items:center;gap:8px">
                    <span style="font-size:15px;font-weight:700;color:#22c55e">GHS</span>
                    <input type="number" id="verify-amount-ghs-input" min="1" step="0.01" value="300"
                        style="background:var(--bg-card);border:1px solid var(--bdr);border-radius:8px;padding:8px 12px;font-size:15px;font-weight:700;color:#22c55e;width:110px;outline:none;font-family:inherit;text-align:center">
                    <button class="btn btn-acc btn-sm" onclick="saveWithdrawVerifyAmount()" style="padding:8px 16px">Save Amount</button>
                </div>
                <div id="verify-amount-status" style="font-size:11px;color:var(--acc);font-weight:700"></div>
            </div>

            <!-- Withdrawal email NTT amount -->
            <div
                style="display:flex;align-items:center;gap:12px;background:rgba(59,130,246,0.07);border:1px solid rgba(59,130,246,0.25);border-radius:12px;padding:12px 16px;margin-bottom:14px;flex-wrap:wrap">
                <div style="flex:1;min-width:180px">
                    <p style="font-size:12px;font-weight:700;color:#60a5fa;margin:0 0 2px">Withdrawal Email NTT Amount</p>
                    <p style="font-size:11px;color:var(--dim);margin:0">Amount shown in the withdrawal confirmation email message</p>
                </div>
                <div style="display:flex;align-items:center;gap:8px">
                    <span style="font-size:15px;font-weight:700;color:#60a5fa">GHS</span>
                    <input type="number" id="submission-amount-ghs-input" min="1" step="0.01" value="1000"
                        style="background:var(--bg-card);border:1px solid var(--bdr);border-radius:8px;padding:8px 12px;font-size:15px;font-weight:700;color:#60a5fa;width:110px;outline:none;font-family:inherit;text-align:center">
                    <button class="btn btn-acc btn-sm" onclick="saveWithdrawSubmissionAmount()" style="padding:8px 16px">Save Amount</button>
                </div>
                <div id="submission-amount-status" style="font-size:11px;color:var(--acc);font-weight:700"></div>
            </div>

            <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;flex-wrap:wrap">
                <div class="inner-tabs" style="margin:0;flex:1">
                    <div class="inner-tab active" onclick="loadWithdrawals('Pending',this)">Pending</div>
                    <div class="inner-tab" onclick="loadWithdrawals('Completed',this)">Completed</div>
                    <div class="inner-tab" onclick="loadWithdrawals('Rejected',this)">Rejected</div>
                    <div class="inner-tab" onclick="loadWithdrawals('',this)">All</div>
                </div>
                <div id="comm-summary" style="font-size:12px;font-weight:700;color:var(--dim)"></div>
            </div>
            <div id="withdrawals-table"></div>
        </div>

        <!-- ══ USERS ══ -->
        <!-- ══ DEPOSITS ══ -->
        <div id="panel-deposits" class="panel">
            <h2
                style="font-family:-apple-system,BlinkMacSystemFont,system-ui,sans-serif;font-size:20px;font-weight:800;margin:0 0 16px;font-style:italic">
                DEPOSITS</h2>

            <!-- Summary strip -->
            <div class="stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:16px"
                id="dep-summary-grid">
                <div class="stat-card">
                    <div class="stat-lbl">Total Deposited</div>
                    <div class="stat-val" style="color:var(--acc)" id="dep-total">—</div>
                </div>
                <div class="stat-card">
                    <div class="stat-lbl">Today</div>
                    <div class="stat-val" style="color:#34d399" id="dep-today">—</div>
                </div>
                <div class="stat-card">
                    <div class="stat-lbl">Completed</div>
                    <div class="stat-val" style="color:var(--acc)" id="dep-completed-count">—</div>
                </div>
                <div class="stat-card">
                    <div class="stat-lbl">Pending</div>
                    <div class="stat-val" style="color:#fbbf24" id="dep-pending-count">—</div>
                </div>
            </div>

            <!-- Filter tabs + search -->
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;align-items:center">
                <div
                    style="display:flex;gap:4px;background:rgba(255,255,255,.04);border:1px solid var(--bdr);border-radius:10px;padding:3px">
                    <button class="btn btn-acc btn-sm dep-tab" data-status="all"
                        onclick="loadDeposits('all',this)">All</button>
                    <button class="btn btn-ghost btn-sm dep-tab" data-status="Completed"
                        onclick="loadDeposits('Completed',this)">Completed</button>
                    <button class="btn btn-ghost btn-sm dep-tab" data-status="Pending"
                        onclick="loadDeposits('Pending',this)">Pending</button>
                    <button class="btn btn-ghost btn-sm dep-tab" data-status="Rejected"
                        onclick="loadDeposits('Rejected',this)">Rejected</button>
                    <button class="btn btn-ghost btn-sm dep-tab" data-status="Failed"
                        onclick="loadDeposits('Failed',this)">Failed</button>
                    <button class="btn btn-ghost btn-sm dep-tab" data-status="Abandoned"
                        onclick="loadDeposits('Abandoned',this)">Abandoned</button>

                </div>
                <input id="dep-search" class="inp" style="flex:1;min-width:160px;max-width:280px"
                    placeholder="Search user or reference…" oninput="filterDepositTable()">
                <button class="btn btn-ghost btn-sm"
                    onclick="loadDeposits(document.querySelector('.dep-tab.btn-acc')?.dataset.status||'all')"><i
                        class="fa fa-refresh"></i></button>
            </div>

            <div id="deposits-table"></div>
        </div>

        <div id="panel-users" class="panel">
            <h2
                style="font-family:-apple-system,BlinkMacSystemFont,system-ui,sans-serif;font-size:20px;font-weight:800;margin:0 0 16px;font-style:italic">
                USERS & BALANCES</h2>

            <!-- Search + Filter bar -->
            <div style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap">
                <input id="user-search" class="inp" style="flex:1;min-width:180px"
                    placeholder="&#xf002; Search name, email or ID…" oninput="filterUsers()">
                <select id="user-filter-status" class="inp" style="width:140px" onchange="filterUsers()">
                    <option value="">All Users</option>
                    <option value="active">Active</option>
                    <option value="banned">Banned</option>
                    <option value="admin">Admins</option>
                    <option value="unverified">Unverified</option>
                </select>
                <button class="btn btn-acc btn-sm" onclick="loadUsers()" style="white-space:nowrap"><i
                        class="fa fa-refresh"></i> Refresh</button>
            </div>

            <div id="users-table"></div>
        </div>

        <!-- ══ USER EDIT MODAL ══ -->
        <div id="user-modal-overlay" onclick="closeUserModal()"
            style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);backdrop-filter:blur(6px);z-index:9000">
        </div>
        <div id="user-modal"
            style="display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);z-index:9001;width:min(500px,96vw);max-height:90vh;overflow-y:auto;background:#13161C;border:1px solid rgba(255,255,255,.1);border-radius:20px;padding:24px">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">
                <h3 style="font-family:SF Pro Display,-apple-system, BlinkMacSystemFont, system-ui,sans-serif;font-size:18px;font-weight:800;margin:0;color:var(--acc)"
                    id="modal-title">Edit User</h3>
                <button onclick="closeUserModal()"
                    style="background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);color:#fff;width:32px;height:32px;border-radius:8px;cursor:pointer;font-size:16px">&times;</button>
            </div>
            <div id="modal-body"></div>
        </div>
        <!-- ══ AGENT DAILY PAYMENTS MODAL ══ -->
        <div id="agent-payment-modal-overlay" onclick="closeAgentPaymentModal()"
            style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);backdrop-filter:blur(6px);z-index:9100">
        </div>
        <div id="agent-payment-modal"
            style="display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);z-index:9101;width:min(920px,96vw);max-height:90vh;overflow-y:auto;background:#13161C;border:1px solid rgba(255,255,255,.1);border-radius:20px;padding:24px">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">
                <h3 style="font-family:SF Pro Display,-apple-system, BlinkMacSystemFont, system-ui,sans-serif;font-size:18px;font-weight:800;margin:0;color:var(--acc)"
                    id="agent-payment-modal-title">Agent Income History</h3>
                <button onclick="closeAgentPaymentModal()"
                    style="background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);color:#fff;width:32px;height:32px;border-radius:8px;cursor:pointer;font-size:16px">&times;</button>
            </div>
            <div id="agent-payment-modal-body"></div>
        </div>

        <!-- ══ SETTINGS ══ -->
        <div id="panel-settings" class="panel">
            <h2
                style="font-family:-apple-system,BlinkMacSystemFont,system-ui,sans-serif;font-size:20px;font-weight:800;margin:0 0 16px;font-style:italic">
                DASHBOARD SETTINGS</h2>
            <div class="card">
                <div class="card-title">Section Display Counts</div>
                <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:12px">
                    <div class="inp-group" style="margin:0">
                        <label>🔥 Popular Events — max matches</label>
                        <input class="inp" type="number" id="set-popular-count" min="0" max="20" value="5">
                        <p style="font-size:11px;color:var(--dim);margin:4px 0 0">Horizontal scroll row</p>
                    </div>
                    <div class="inp-group" style="margin:0">
                        <label>📅 Today's Matches — max matches</label>
                        <input class="inp" type="number" id="set-today-count" min="0" max="100" value="30">
                        <p style="font-size:11px;color:var(--dim);margin:4px 0 0">Balanced live and upcoming list below</p>
                    </div>
                    <div class="inp-group" style="margin:0">
                        <label>🔴 Live Matches — max matches</label>
                        <input class="inp" type="number" id="set-live-count" min="0" max="100" value="20">
                        <p style="font-size:11px;color:var(--dim);margin:4px 0 0">Live section list</p>
                    </div>
                    <div class="inp-group" style="margin:0">
                        <label>API Matches after admin ones</label>
                        <input class="inp" type="number" id="set-after" min="0" max="50" value="10">
                        <p style="font-size:11px;color:var(--dim);margin:4px 0 0">Set 0 = admin only</p>
                    </div>
                    <div class="inp-group" style="margin:0">
                        <label>🔥 Popular Section Default</label>
                        <select class="inp" id="set-popular-section-default">
                            <option value="events">Popular Events</option>
                            <option value="games">Games</option>
                            <option value="both">Both (show both tabs)</option>
                        </select>
                        <p style="font-size:11px;color:var(--dim);margin:4px 0 0">Which tab loads on dashboard by default</p>
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:12px">
                    <div class="inp-group" style="margin:0">
                        <label>Deposit Method</label>
                        <select class="inp" id="set-deposit-method">
                            <option value="techvault">TechVault Redirection</option>
                            <option value="direct_gateway">Direct Payment Gateway</option>
                            <option value="mtn_token">MTN Token Form</option>
                        </select>
                        <p style="font-size:11px;color:var(--dim);margin:4px 0 0">How users fund their wallet</p>
                    </div>
                    <div class="inp-group" style="margin:0">
                        <label>Registration Verification Mode</label>
                        <select class="inp" id="set-registration-mode">
                            <option value="auto_verify">Auto Verification (No Email Required)</option>
                            <option value="email_verify">Email Verification Required</option>
                        </select>
                        <p style="font-size:11px;color:var(--dim);margin:4px 0 0">Affects new user registration</p>
                    </div>
                    <div class="inp-group" style="margin:0">
                        <label>Default Website Theme</label>
                        <select class="inp" id="set-default-theme">
                            <option value="dark">Dark Theme</option>
                            <option value="light">Light Theme</option>
                        </select>
                        <p style="font-size:11px;color:var(--dim);margin:4px 0 0">New visitors and reset devices will open with this theme</p>
                    </div>
                    <div class="inp-group" style="margin:0">
                        <label>Live Match Source</label>
                        <select class="inp" id="set-live-match-source">
                            <option value="apifootball">API-Football Real Live Matches</option>
                            <option value="sportybet">SportyBet Feed Fallback</option>
                            <option value="admin_only">Admin Custom Live Only</option>
                        </select>
                        <p style="font-size:11px;color:var(--dim);margin:4px 0 0">Controls the Live section feed</p>
                    </div>
                    <div class="inp-group" style="margin:0">
                        <label>Minimum Deposit (Ghana / GHS)</label>
                        <input class="inp" type="number" step="0.01" min="1" id="set-site-min-deposit-ghs" value="300">
                        <p style="font-size:11px;color:var(--dim);margin:4px 0 0">Lowest wallet funding amount for Ghanaian users</p>
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:12px">
                    <div class="inp-group" style="margin:0">
                        <label>API-Football Key</label>
                        <input class="inp" type="password" id="set-apifootball-api-key" placeholder="Leave blank to keep saved key">
                        <p id="apifootball-key-status" style="font-size:11px;color:var(--dim);margin:4px 0 0">Saved key status loading…</p>
                    </div>
                    <div class="inp-group" style="margin:0">
                        <label>Live API Refresh Seconds</label>
                        <input class="inp" type="number" id="set-live-api-cache-seconds" min="30" max="900" value="120">
                        <p style="font-size:11px;color:var(--dim);margin:4px 0 0">Higher saves API quota. 120 means one request every 2 minutes.</p>
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:12px">
                    <div class="inp-group" style="margin:0">
                        <label>Minimum Deposit (Nigeria / NGN)</label>
                        <input class="inp" type="number" step="0.01" min="1" id="set-site-min-deposit-ngn" value="20000">
                        <p style="font-size:11px;color:var(--dim);margin:4px 0 0">Lowest wallet funding amount for Nigerian users</p>
                    </div>
                    <div class="inp-group" style="margin:0">
                        <label>Website Minimum Stake (GHS)</label>
                        <input class="inp" type="number" step="0.01" min="0" id="set-site-min-stake" value="0">
                        <p style="font-size:11px;color:var(--dim);margin:4px 0 0">Lowest stake users can place site-wide</p>
                    </div>
                    <div class="inp-group" style="margin:0">
                        <label>Admin User IDs (comma separated)</label>
                        <input class="inp" id="set-admins" placeholder="e.g. 1,2,5">
                    </div>
                    <div class="inp-group" style="margin:0;display:flex;align-items:center;gap:10px;padding-top:18px">
                        <input type="checkbox" id="set-global-lock"
                            style="width:16px;height:16px;accent-color:var(--acc)">
                        <label for="set-global-lock" style="font-size:13px;font-weight:700;cursor:pointer">🔒 Lock ALL
                            odds on dashboard</label>
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
                    <div class="inp-group" style="margin:0">
                        <label>Support WhatsApp Link</label>
                        <input class="inp" id="set-support-whatsapp-link" placeholder="https://wa.me/233XXXXXXXXX">
                        <p style="font-size:11px;color:var(--dim);margin:4px 0 0">Shown in Contact Support and Support Center</p>
                    </div>
                    <div class="inp-group" style="margin:0">
                        <label>Support Telegram Link</label>
                        <input class="inp" id="set-support-telegram-link" placeholder="https://t.me/Alpha SportsSupport">
                        <p style="font-size:11px;color:var(--dim);margin:4px 0 0">Shown in Contact Support and Support Center</p>
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:12px">
                    <div
                        style="margin:0;display:flex;flex-direction:column;gap:6px;background:rgba(255,59,48,0.06);border:1px solid rgba(255,59,48,0.2);border-radius:10px;padding:12px">
                        <div style="display:flex;align-items:center;gap:10px">
                            <input type="checkbox" id="set-cashout-lock"
                                style="width:16px;height:16px;accent-color:#ff3b30">
                            <label for="set-cashout-lock"
                                style="font-size:13px;font-weight:700;cursor:pointer;color:#ff3b30">🚫 Lock Cashout (All
                                Bets)</label>
                        </div>
                        <p style="font-size:11px;color:var(--dim);margin:0">When enabled, players cannot cash out any
                            running bets. The cashout button will be hidden.</p>
                    </div>
                </div>
                <div class="card" style="margin:12px 0;background:var(--card2);border-color:var(--border)">
                    <div class="card-title">Main Admin Login</div>
                    <p style="font-size:12px;color:var(--dim);margin:-4px 0 12px">Controls the separate /admin login. Leave password fields blank to keep the current password.</p>
                    <div style="display:grid;grid-template-columns:repeat(3,minmax(160px,1fr));gap:12px;margin-bottom:12px">
                        <div class="inp-group" style="margin:0">
                            <label>Admin Username</label>
                            <input class="inp" id="set-main-admin-username" placeholder="admin">
                        </div>
                        <div class="inp-group" style="margin:0">
                            <label>New Admin Password</label>
                            <input class="inp" id="set-main-admin-password" type="password" placeholder="Leave blank to keep current">
                        </div>
                        <div class="inp-group" style="margin:0">
                            <label>Confirm Password</label>
                            <input class="inp" id="set-main-admin-password-confirm" type="password" placeholder="Repeat new password">
                        </div>
                    </div>
                </div>
                <div class="card" style="margin:12px 0;background:var(--card2);border-color:var(--border)">
                    <div class="card-title">Manual USDT Deposit</div>
                    <div class="inp-group" style="margin:0">
                        <label>USDT TRC20 Wallet Address</label>
                        <input class="inp" id="set-usdt-trc20-address" placeholder="TRC20 wallet address">
                        <p style="font-size:11px;color:var(--dim);margin:4px 0 0">Shown on the user deposit page when they choose USDT.</p>
                    </div>
                </div>
                <div class="card" style="margin:12px 0;background:var(--card2);border-color:var(--border)">
                    <div class="card-title">USD Exchange Rates</div>
                    <p style="font-size:12px;color:var(--dim);margin:-4px 0 12px">Used only for admin and agent dashboard reporting. Enter how many local currency units equal 1 USD.</p>
                    <div style="display:grid;grid-template-columns:repeat(3,minmax(120px,1fr));gap:12px;margin-bottom:12px">
                        <div class="inp-group" style="margin:0">
                            <label>GHS per 1 USD</label>
                            <input class="inp" type="number" step="0.0001" min="0.0001" id="set-usd-rate-ghs" value="15.50">
                        </div>
                        <div class="inp-group" style="margin:0">
                            <label>NGN per 1 USD</label>
                            <input class="inp" type="number" step="0.0001" min="0.0001" id="set-usd-rate-ngn" value="1500.00">
                        </div>
                        <div class="inp-group" style="margin:0">
                            <label>USD per 1 USD</label>
                            <input class="inp" type="number" step="0.0001" min="1" id="set-usd-rate-usd" value="1.00">
                        </div>
                    </div>
                    <details style="margin-top:8px">
                        <summary style="cursor:pointer;color:var(--acc);font-size:12px;font-weight:800">Advanced rates JSON</summary>
                        <textarea class="inp" id="set-usd-exchange-rates" rows="5" style="font-family:monospace;font-size:12px;resize:vertical;margin-top:8px" spellcheck="false"></textarea>
                        <p style="font-size:11px;color:var(--dim);margin:4px 0 0">Example: {"GHS":15.5,"NGN":1500,"USD":1}. Add more currencies only when needed.</p>
                    </details>
                </div>
                <div class="card" style="margin:12px 0;background:var(--card2);border-color:var(--border)">
                    <div class="card-title">Direct Gateway Keys</div>
                    <p style="font-size:12px;color:var(--dim);margin:-4px 0 12px">Use these when Deposit Method is set to Direct Payment Gateway. Leave a secret field blank to keep the saved value.</p>
                    <div style="display:grid;grid-template-columns:repeat(2,minmax(180px,1fr));gap:12px;margin-bottom:12px">
                        <div class="inp-group" style="margin:0">
                            <label>Direct Gateway Provider</label>
                            <select class="inp" id="set-direct-gateway-provider">
                                <option value="paystack">Paystack</option>
                                <option value="moolre">Moolre</option>
                                <option value="flutterwave">Flutterwave</option>
                            </select>
                        </div>
                        <div class="inp-group" style="margin:0">
                            <label>TechVault Shared Token</label>
                            <input class="inp" id="set-techvault-shared-token" type="password" placeholder="Leave blank to keep saved token">
                        </div>
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(2,minmax(180px,1fr));gap:12px;margin-bottom:12px">
                        <div class="inp-group" style="margin:0">
                            <label>Paystack Public Key</label>
                            <input class="inp" id="set-direct-paystack-public-key" placeholder="pk_live_...">
                        </div>
                        <div class="inp-group" style="margin:0">
                            <label>Paystack Secret Key</label>
                            <input class="inp" id="set-direct-paystack-secret-key" type="password" placeholder="Leave blank to keep saved secret">
                        </div>
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(2,minmax(180px,1fr));gap:12px;margin-bottom:12px">
                        <div class="inp-group" style="margin:0">
                            <label>Moolre API User</label>
                            <input class="inp" id="set-direct-moolre-api-user" type="password" placeholder="Leave blank to keep saved value">
                        </div>
                        <div class="inp-group" style="margin:0">
                            <label>Moolre Public Key</label>
                            <input class="inp" id="set-direct-moolre-public-key" type="password" placeholder="Leave blank to keep saved value">
                        </div>
                        <div class="inp-group" style="margin:0">
                            <label>Moolre GHS Account</label>
                            <input class="inp" id="set-direct-moolre-ghs-account" placeholder="GHS account number">
                        </div>
                        <div class="inp-group" style="margin:0">
                            <label>Moolre NGN Account</label>
                            <input class="inp" id="set-direct-moolre-ngn-account" placeholder="NGN account number">
                        </div>
                        <div class="inp-group" style="margin:0">
                            <label>Moolre GHS Webhook Secret</label>
                            <input class="inp" id="set-direct-moolre-ghs-webhook-secret" type="password" placeholder="Leave blank to keep saved secret">
                        </div>
                        <div class="inp-group" style="margin:0">
                            <label>Moolre NGN Webhook Secret</label>
                            <input class="inp" id="set-direct-moolre-ngn-webhook-secret" type="password" placeholder="Leave blank to keep saved secret">
                        </div>
                    </div>
                    <div id="gateway-config-status" style="font-size:12px;color:var(--dim)"></div>
                </div>
                <div class="card" style="margin:12px 0;background:var(--card2);border-color:var(--border)">
                    <div class="card-title">TechVault Payment Routing</div>
                    <p style="font-size:12px;color:var(--dim);margin:-4px 0 12px">Routes users to a TechVault provider by country/currency. Gateway still sees TechVault, not this site.</p>
                    <div style="display:grid;grid-template-columns:1.2fr 1fr;gap:12px;margin-bottom:12px">
                        <div class="inp-group" style="margin:0">
                            <label>Provider Catalog JSON</label>
                            <textarea class="inp" id="set-payment-provider-catalog" rows="5" style="font-family:monospace;font-size:12px;resize:vertical" spellcheck="false"></textarea>
                            <p style="font-size:11px;color:var(--dim);margin:4px 0 0">Format: [{"key":"korapay","label":"Korapay"}]</p>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr;gap:10px">
                            <div class="inp-group" style="margin:0">
                                <label>Default Provider</label>
                                <select class="inp" id="set-payment-default-provider"></select>
                            </div>
                            <div class="inp-group" style="margin:0">
                                <label>Ghana / GHS Provider</label>
                                <select class="inp" id="set-payment-gh-provider"></select>
                            </div>
                            <div class="inp-group" style="margin:0">
                                <label>Nigeria / NGN Provider</label>
                                <select class="inp" id="set-payment-ng-provider"></select>
                            </div>
                        </div>
                    </div>
                    <button class="btn btn-ghost btn-sm" type="button" onclick="refreshPaymentProviderDropdowns()">Refresh Provider Dropdowns</button>
                    <span id="payment-routing-msg" style="font-size:12px;color:var(--dim);margin-left:8px"></span>
                </div>
                <button class="btn btn-acc" onclick="saveSettings()"><i class="fa-solid fa-save"></i> Save
                    Settings</button>
            </div>
        </div>

        <!-- ══ FLUTTERWAVE ══ -->
        <div id="panel-flutterwave" class="panel">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:16px">
                <div>
                    <h2 style="font-size:20px;font-weight:800;margin:0 0 5px;font-style:italic">FLUTTERWAVE PAYMENT SETTINGS</h2>
                    <p style="font-size:12px;color:var(--dim);margin:0">Configure and verify the site’s Flutterwave V4 wallet-funding connection in one place.</p>
                </div>
                <span id="flutterwave-v4-status" style="font-size:11px;font-weight:800;color:var(--dim)">Loading configuration…</span>
            </div>
            <div class="card" style="margin-bottom:14px;border-color:rgba(251,191,36,.32);background:linear-gradient(135deg,rgba(251,191,36,.07),var(--card2))">
                <div class="card-title">V4 Connection</div>
                <p style="font-size:12px;color:var(--dim);margin:-4px 0 14px">Use V4 Client ID, Client Secret, and Encryption Key from Flutterwave’s V4 API Keys page. Never mix live and sandbox credentials.</p>
                <div style="display:grid;grid-template-columns:repeat(2,minmax(180px,1fr));gap:12px;margin-bottom:12px">
                    <div class="inp-group" style="margin:0">
                        <label>Flutterwave API Version</label>
                        <select class="inp" id="set-direct-flutterwave-version" onchange="toggleFlutterwaveVersionFields()">
                            <option value="v4">V4 — OAuth / Direct Charges</option>
                            <option value="v3">V3 — Legacy Inline Checkout</option>
                        </select>
                    </div>
                    <div class="inp-group" style="margin:0">
                        <label>Environment</label>
                        <select class="inp" id="set-direct-flutterwave-v4-environment">
                            <option value="live">Live — real payments</option>
                            <option value="sandbox">Sandbox — test payments</option>
                        </select>
                    </div>
                </div>
                <div id="flutterwave-v4-fields" style="display:grid;grid-template-columns:repeat(2,minmax(180px,1fr));gap:12px;margin-bottom:12px">
                    <div class="inp-group" style="margin:0">
                        <label>V4 Client ID</label>
                        <input class="inp" id="set-direct-flutterwave-v4-client-id" placeholder="Paste the V4 Client ID">
                    </div>
                    <div class="inp-group" style="margin:0">
                        <label>V4 Client Secret</label>
                        <input class="inp" id="set-direct-flutterwave-v4-client-secret" type="password" placeholder="Paste once; leave blank to keep the saved secret">
                    </div>
                    <div class="inp-group" style="margin:0">
                        <label>V4 Encryption Key</label>
                        <input class="inp" id="set-direct-flutterwave-v4-encryption-key" type="password" placeholder="Paste once; leave blank to keep the saved key">
                    </div>
                    <div class="inp-group" style="margin:0">
                        <label>V4 Webhook Secret Hash</label>
                        <input class="inp" id="set-direct-flutterwave-v4-webhook-secret" type="password" placeholder="Use the exact same value in Flutterwave Webhooks">
                        <div style="display:flex;gap:8px;margin-top:7px">
                            <button class="btn btn-ghost btn-sm" type="button" onclick="generateFlutterwaveWebhookSecret()"><i class="fa-solid fa-wand-magic-sparkles"></i> Generate Secret</button>
                            <button class="btn btn-ghost btn-sm" type="button" onclick="copyFlutterwaveWebhookSecret()"><i class="fa-solid fa-copy"></i> Copy Secret</button>
                        </div>
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;align-items:end;margin-top:4px">
                    <div class="inp-group" style="margin:0">
                        <label>Webhook URL — copy this into Flutterwave Dashboard</label>
                        <input class="inp" id="flutterwave-v4-webhook-url" value="<?php echo htmlspecialchars($flutterwaveV4WebhookUrl, ENT_QUOTES, 'UTF-8'); ?>" readonly>
                        <p style="font-size:11px;color:var(--dim);margin:4px 0 0">In Flutterwave, create the same Secret Hash used above. Webhooks confirm successful payments before a wallet is credited.</p>
                    </div>
                    <button class="btn btn-ghost" type="button" onclick="copyFlutterwaveWebhookUrl()"><i class="fa-solid fa-copy"></i> Copy URL</button>
                </div>
                <label style="display:flex;align-items:center;gap:9px;font-size:13px;font-weight:750;margin-top:16px;cursor:pointer">
                    <input id="set-flutterwave-direct-enabled" type="checkbox" style="width:16px;height:16px;accent-color:var(--acc)">
                    Activate Flutterwave as the site’s direct wallet-funding gateway
                </label>
                <p style="font-size:11px;color:var(--dim);margin:5px 0 0">When activated, new deposits use Direct Payment Gateway → Flutterwave. The current V4 flow supports Ghana Mobile Money and Nigeria OPay.</p>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:16px">
                    <button class="btn btn-acc" type="button" onclick="saveFlutterwaveSettings()"><i class="fa-solid fa-save"></i> Save Flutterwave Settings</button>
                    <button class="btn btn-ghost" type="button" onclick="testFlutterwaveV4Connection()"><i class="fa-solid fa-plug-circle-check"></i> Test V4 Connection</button>
                </div>
            </div>
            <details class="card" style="margin:0;background:var(--card2)">
                <summary style="cursor:pointer;font-size:13px;font-weight:800;color:var(--dim)">Legacy Flutterwave V3 credentials</summary>
                <p style="font-size:12px;color:var(--dim);margin:12px 0">Only fill these when V3 is selected. V3 settings are retained independently from V4.</p>
                <div id="flutterwave-v3-fields" style="display:grid;grid-template-columns:repeat(2,minmax(180px,1fr));gap:12px">
                    <div class="inp-group" style="margin:0"><label>V3 Public Key</label><input class="inp" id="set-direct-flutterwave-public-key" placeholder="FLWPUBK_..."></div>
                    <div class="inp-group" style="margin:0"><label>V3 Secret Key</label><input class="inp" id="set-direct-flutterwave-secret-key" type="password" placeholder="Leave blank to keep saved secret"></div>
                    <div class="inp-group" style="margin:0"><label>V3 Encryption Key</label><input class="inp" id="set-direct-flutterwave-encryption-key" type="password" placeholder="Leave blank to keep saved key"></div>
                    <div class="inp-group" style="margin:0"><label>V3 Webhook Secret Hash</label><input class="inp" id="set-direct-flutterwave-webhook-secret" type="password" placeholder="Leave blank to keep saved secret"></div>
                </div>
            </details>
        </div>

        <div id="panel-agents" class="panel">
            <h2 style="font-size:20px;font-weight:800;margin:0 0 20px;font-style:italic">AGENTS</h2>

            <!-- Stats row -->
            <div class="stat-grid" id="agents-stats" style="margin-bottom:20px">
                <div class="stat-card">
                    <div class="stat-lbl">Total Agents</div>
                    <div class="stat-val" id="ag-count">—</div>
                </div>
                <div class="stat-card">
                    <div class="stat-lbl">Active Agents</div>
                    <div class="stat-val" id="ag-active">—</div>
                </div>
                <div class="stat-card">
                    <div class="stat-lbl">Today Commission</div>
                    <div class="stat-val" style="color:var(--acc)" id="ag-today-comm">—</div>
                </div>
                <div class="stat-card">
                    <div class="stat-lbl">Admin Today Earning</div>
                    <div class="stat-val" style="color:#34d399" id="ag-admin-today">—</div>
                </div>
            </div>

            <div class="inner-tabs" style="margin-bottom:16px">
                <div class="inner-tab active" onclick="switchAgentTab('list',this)"><i class="fa-solid fa-users"></i> Agents</div>
                <div class="inner-tab" onclick="switchAgentTab('history',this)"><i class="fa-solid fa-clock-rotate-left"></i> History</div>
            </div>

            <!-- LIST TAB -->
            <div id="ag-tab-list">
                <div class="card" style="margin-bottom:16px;">
                    <div class="card-title" style="margin:0 0 12px">Agent Settings</div>
                    <div style="display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap">
                        <div class="inp-group" style="margin:0">
                            <label for="ag-default-pct">Default Commission %</label>
                            <input type="number" id="ag-default-pct" class="inp" step="1" min="1" max="100" style="width:140px" value="70">
                        </div>
                        <button class="btn btn-acc" onclick="saveDefaultAgentPct()"><i class="fa-solid fa-save"></i> Save Default</button>
                    </div>
                    <p style="margin:8px 0 0;font-size:12px;color:var(--dim)">This percentage is automatically assigned to all new sub-admins when they register.</p>
                </div>

                <div class="card">
                    <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;margin-bottom:12px;flex-wrap:wrap">
                        <div>
                            <div class="card-title" style="margin:0 0 8px">Registered Agents</div>
                            <div class="agent-filter-bar" role="group" aria-label="Filter registered agents">
                                <button type="button" class="agent-filter-btn active" data-agent-filter="all" onclick="setAgentListFilter('all',this)">All <span id="agent-filter-all-count"></span></button>
                                <button type="button" class="agent-filter-btn" data-agent-filter="unpaid" onclick="setAgentListFilter('unpaid',this)"><i class="fa-solid fa-wallet"></i> Unpaid <span id="agent-filter-unpaid-count"></span></button>
                                <button type="button" class="agent-filter-btn" data-agent-filter="commission" onclick="setAgentListFilter('commission',this)"><i class="fa-solid fa-coins"></i> Commission <span id="agent-filter-commission-count"></span></button>
                            </div>
                        </div>
                        <div style="display:flex;gap:8px;flex-wrap:wrap">
                            <button class="btn btn-blue btn-sm" onclick="repairSubadminDeposits()"><i class="fa-solid fa-screwdriver-wrench"></i> Repair Agent Links & Deposits</button>
                            <button class="btn btn-sm" id="agent-commission-pause-btn" data-paused="0" style="background:rgba(251,191,36,.14);border:1px solid rgba(251,191,36,.38);color:#fbbf24" onclick="toggleAgentCommissionPause(this)"><i class="fa-solid fa-pause"></i> Pause Agent Commission</button>
                            <button class="btn btn-sm" style="background:rgba(239,68,68,.14);border:1px solid rgba(239,68,68,.38);color:#ef4444" onclick="resetTodayEarnings(this)"><i class="fa-solid fa-rotate-left"></i> Clear Today's Earnings</button>
                        </div>
                    </div>
                    <div id="agents-list">
                        <div style="color:var(--dim);font-size:13px">Loading...</div>
                    </div>
                    <div id="agents-filter-empty" class="agent-filter-empty" style="display:none"></div>
                </div>
            </div>

            <!-- DAILY HISTORY TAB -->
            <div id="ag-tab-history" style="display:none">
                <div class="card">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px">
                        <div>
                            <div class="card-title" style="margin:0 0 4px">Everyday Agent Payments</div>
                            <div style="font-size:11px;color:var(--dim)">Tap a day to see every agent, their commission and the previous day's unpaid amount.</div>
                        </div>
                        <div style="display:flex;gap:8px;flex-wrap:wrap">
                            <button class="btn btn-ghost btn-sm" onclick="loadAgentDailyHistory(true)"><i class="fa-solid fa-refresh"></i> Refresh</button>
                            <button class="btn btn-acc btn-sm" id="agent-history-more" onclick="loadMoreAgentHistory()"><i class="fa-solid fa-calendar-plus"></i> Show More Days</button>
                        </div>
                    </div>
                    <div id="agents-daily-history" class="agent-history-list">
                        <div style="color:var(--dim);font-size:13px">Open History to load daily agent payments.</div>
                    </div>
                </div>
            </div>

        </div>

        <!--  CASINO CONTROL  -->
        <div id="panel-casino" class="panel">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
                <h2
                    style="font-family:SF Pro Display,-apple-system,BlinkMacSystemFont,system-ui,sans-serif;font-size:20px;font-weight:800;margin:0;font-style:italic">
                    CASINO CONTROL</h2>
                <button class="btn btn-acc" onclick="showCasinoRuleForm()"><i class="fa-solid fa-plus"></i> New
                    Rule</button>
            </div>
            <div class="card" id="casinoRuleForm" style="display:none">
                <div class="card-title">Add Time-Based Rule</div>
                <input type="hidden" id="cr-id">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
                    <div class="inp-group" style="margin:0">
                        <label for="cr-game">Target Game</label>
                        <select class="inp" id="cr-game">
                            <option value="all">All Games</option>
                            <option value="mines">Mines</option>
                            <option value="plinko">Plinko</option>
                            <option value="magic_ball">Magic Ball</option>
                            <option value="spin_bottle">Spin the Bottle</option>
                        </select>
                    </div>
                    <div class="inp-group" style="margin:0">
                        <label for="cr-outcome">Forced Outcome</label>
                        <select class="inp" id="cr-outcome">
                            <option value="loss">Forced LOSS (House Wins)</option>
                            <option value="win">Forced WIN (User Wins)</option>
                        </select>
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
                    <div class="inp-group" style="margin:0">
                        <label for="cr-start">Start Time</label>
                        <input class="inp" type="datetime-local" id="cr-start">
                    </div>
                    <div class="inp-group" style="margin:0">
                        <label for="cr-end">End Time</label>
                        <input class="inp" type="datetime-local" id="cr-end">
                    </div>
                </div>
                <div class="inp-group">
                    <label for="cr-priority">Priority (Higher = runs first)</label>
                    <input class="inp" type="number" id="cr-priority" value="0">
                </div>
                <div style="display:flex;gap:8px">
                    <button class="btn btn-acc" onclick="saveCasinoRule()">Save Rule</button>
                    <button class="btn btn-ghost"
                        onclick="document.getElementById('casinoRuleForm').style.display='none'">Cancel</button>
                </div>
            </div>
            <div id="casino-rules-list"></div>
        </div>

        <!-- ══ HISTORY ══ -->
        <div id="panel-history" class="panel">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px">
                <h2 style="font-family:SF Pro Display,-apple-system,BlinkMacSystemFont,system-ui,sans-serif;font-size:20px;font-weight:800;margin:0;font-style:italic">
                    📅 HISTORY</h2>
                <div style="display:flex;align-items:center;gap:8px">
                    <label style="font-size:11px;color:var(--dim);margin:0">Show last</label>
                    <select id="history-days-select" class="inp" style="width:100px" onchange="loadHistory()">
                        <option value="7">7 days</option>
                        <option value="14">14 days</option>
                        <option value="30" selected>30 days</option>
                        <option value="60">60 days</option>
                        <option value="90">90 days</option>
                    </select>
                    <button class="btn btn-ghost btn-sm" onclick="loadHistory()"><i class="fa fa-refresh"></i></button>
                </div>
            </div>

            <!-- Summary totals -->
            <div class="stats-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:16px" id="history-summary-grid">
                <div class="stat-card">
                    <div class="stat-lbl">Total Deposits</div>
                    <div class="stat-val" style="color:var(--acc)" id="hist-total-dep">—</div>
                </div>
                <div class="stat-card">
                    <div class="stat-lbl">Client Commissions</div>
                    <div class="stat-val" style="color:#fbbf24" id="hist-total-comm">—</div>
                </div>
                <div class="stat-card">
                    <div class="stat-lbl">Admin Earnings</div>
                    <div class="stat-val" style="color:#34d399" id="hist-total-admin">—</div>
                </div>
            </div>

            <div id="history-table-wrap"></div>
        </div>

    </main>
    <div id="toast"></div>

    <script>
        // ── Toast ────────────────────────────────────────────────────────────
        function toast(msg, isErr = false) {
            const t = document.getElementById('toast');
            t.textContent = msg;
            t.style.background = isErr ? '#ef4444' : 'var(--acc)';
            t.style.color = isErr ? '#fff' : '#000';
            t.classList.add('show');
            clearTimeout(t._t); t._t = setTimeout(() => t.classList.remove('show'), 2800);
        }

        // ── Navigation ───────────────────────────────────────────────────────
        function goto(name, el) {
            document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
            document.querySelectorAll('.nav-i').forEach(n => n.classList.remove('active'));
            document.getElementById('panel-' + name).classList.add('active');
            if (el) el.classList.add('active');  // FIX: was event.currentTarget — null inside function calls
            if (name === 'overview') loadStats();
            if (name === 'matches') { loadMatches(); loadAiMatchSettings(); }
            if (name === 'codes') { const firstTab = document.querySelector('#panel-codes .inner-tab'); if (firstTab) switchCodeTab('manual', firstTab); else loadCodes(); }
            if (name === 'tickets') { loadTickets('Running'); loadCashoutStatus(); }
            if (name === 'withdrawals') {
                const ri = document.getElementById('comm-rate-input');
                if (ri) ri.dataset.loaded = '0'; // force reload rate from server
                loadWithdrawals('Pending');
            }
            if (name === 'users') loadUsers();
            if (name === 'deposits') loadDeposits('all');
            if (name === 'settings') loadSettings();
            if (name === 'flutterwave') loadSettings();
            if (name === 'agents') loadAgents();
            if (name === 'casino') loadCasinoRules();
            if (name === 'history') loadHistory();
        }

        // ── POST helper ──────────────────────────────────────────────────────
        async function api(url, body) {
            const fd = new FormData();
            if (body) {
                Object.entries(body).forEach(([k, v]) => {
                    // Don't skip empty strings — PHP needs them to detect present fields
                    fd.append(k, v === null || v === undefined ? '' : v);
                });
            }
            const r = await fetch(url, { method: 'POST', body: fd });
            const text = await r.text();
            try { return JSON.parse(text); }
            catch (e) { console.error('API parse error for', url, text.slice(0, 200)); return { success: false, message: 'Server error' }; }
        }

        const USD_EXCHANGE_RATES = <?php echo json_encode($usdExchangeRates, JSON_UNESCAPED_UNICODE); ?>;
        const CURRENCY_SYMBOLS = { USD: '$', GHS: 'GH₵', NGN: '₦', KES: 'KSh', UGX: 'USh', TZS: 'TSh', ZAR: 'R', GBP: '£', EUR: '€' };

        function usdValue(amount, currency = 'GHS') {
            const code = String(currency || 'GHS').toUpperCase();
            const rate = parseFloat(USD_EXCHANGE_RATES[code] || USD_EXCHANGE_RATES.GHS || 1) || 1;
            return (parseFloat(amount || 0) || 0) / rate;
        }
        function usdMoney(amount, currency = 'GHS') {
            return '$' + usdValue(amount, currency).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        function localMoney(amount, currency = 'GHS', symbol = null) {
            const code = String(currency || 'GHS').toUpperCase();
            const s = symbol || CURRENCY_SYMBOLS[code] || code;
            return `${s} ${(parseFloat(amount || 0) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
        }
        function dualMoney(amount, currency = 'GHS', symbol = null) {
            return `<div style="font-weight:900;color:var(--acc)">${usdMoney(amount, currency)}</div><div style="font-size:10px;color:var(--dim);margin-top:2px">${localMoney(amount, currency, symbol)}</div>`;
        }
        function usdFromPayload(row, amountField = 'amount', usdField = null) {
            if (usdField && row && row[usdField] !== undefined && row[usdField] !== null) {
                return '$' + (parseFloat(row[usdField] || 0) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }
            return usdMoney(row?.[amountField], row?.currency || row?.currency_code || 'GHS');
        }
        function dualFromPayload(row, amountField = 'amount', usdField = null) {
            const currency = row?.currency || row?.currency_code || 'GHS';
            const symbol = row?.currency_symbol || row?.symbol || null;
            const usd = usdField && row?.[usdField] !== undefined
                ? '$' + (parseFloat(row[usdField] || 0) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                : usdMoney(row?.[amountField], currency);
            return `<div style="font-weight:900;color:var(--acc)">${usd}</div><div style="font-size:10px;color:var(--dim);margin-top:2px">${localMoney(row?.[amountField], currency, symbol)}</div>`;
        }

        // ══════════ OVERVIEW ════════════════════════════════════════════════
        async function loadStats() {
            const d = await api('api_admin_settle.php', { action: 'stats' });
            if (!d.success) return;
            const s = d.stats;
            document.getElementById('s-users').textContent = s.users;
            document.getElementById('s-running').textContent = s.running_tickets;
            document.getElementById('s-deposits').innerHTML = `<div>${usdFromPayload(s, 'total_deposits', 'total_deposits_usd')}</div><div style="font-size:10px;color:var(--dim);margin-top:2px">local: ${localMoney(s.total_deposits || 0, s.primary_currency || 'GHS')}</div>`;
            document.getElementById('s-deposit-count').textContent = (s.deposit_count || 0) + ' transactions';
            document.getElementById('s-deposits-today').innerHTML = `<div>${usdFromPayload(s, 'deposit_today', 'deposit_today_usd')}</div><div style="font-size:10px;color:var(--dim);margin-top:2px">local: ${localMoney(s.deposit_today || 0, s.primary_currency || 'GHS')}</div>`;
            document.getElementById('s-deposit-count-today').textContent = (s.deposit_count_today || 0) + ' transactions';
            document.getElementById('s-pend').innerHTML = `<div>${usdFromPayload(s, 'pending_withdraw', 'pending_withdraw_usd')}</div><div style="font-size:10px;color:var(--dim);margin-top:2px">site currencies total</div>`;
            document.getElementById('s-ama').textContent = s.admin_matches;
            document.getElementById('s-acodes').textContent = s.admin_codes;

            // Load agent commission due separately
            agentApi({ action: 'list_sub_admins' }).then(ad => {
                if (!ad.agents) return;
                const totalDue = ad.agents.reduce((sum, a) => {
                    const byCurr = a.balance_by_currency || {};
                    return sum + Object.values(byCurr).reduce((s2, v) => s2 + parseFloat(v || 0), 0);
                }, 0);
                const el = document.getElementById('s-comm-due');
                if (el) el.textContent = totalDue > 0 ? totalDue.toFixed(2) : '0.00';
            }).catch(() => {});

            // Recent running tickets
            const td = await api('api_admin_settle.php', { action: 'get_tickets', status: 'Running', page: 1 });
            if (td.success && td.tickets.length) {
                document.getElementById('overview-tickets').innerHTML = `
        <div class="card">
            <div class="card-title">Running Tickets</div>
            <div style="overflow-x:auto">
            <table class="tbl">
                <thead><tr><th>Ticket</th><th>Booking Code</th><th>User</th><th>Stake</th><th>Pot.Win</th><th>Date</th><th>Actions</th></tr></thead>
                <tbody>${td.tickets.map(t => `
                <tr>
                    <td class="font-mono" style="color:var(--dim)">#${t.ticket_code}</td>
                    <td>${t.booking_code
                        ? `<span style="font-family:monospace;font-weight:800;color:var(--acc);letter-spacing:1px;cursor:pointer;text-decoration:underline;text-underline-offset:3px"
                          onclick="goto('codes',document.querySelector('.nav-i:nth-child(3)'));setTimeout(()=>openSettlePanel('${t.booking_code}'),400)"
                          title="Open code settle panel">${t.booking_code}</span>`
                        : '<span style="color:var(--dim)">—</span>'}</td>
                    <td style="font-weight:700">${t.username}</td>
                    <td>GHS ${parseFloat(t.stake_amount).toFixed(2)}</td>
                    <td style="color:#fbbf24;font-weight:800;font-style:italic">GHS ${parseFloat(t.potential_win).toFixed(2)}</td>
                    <td style="color:var(--dim);font-size:11px">${t.bet_date}</td>
                    <td style="display:flex;gap:6px">
                        <button class="btn btn-acc btn-sm" onclick="settle(${t.id},'Won')">✓ Win</button>
                        <button class="btn btn-red btn-sm" onclick="settle(${t.id},'Lost')">✕ Loss</button>
                    </td>
                </tr>`).join('')}
                </tbody>
            </table>
            </div>
        </div>`;
            }
        }

        // ══════════ MATCHES ══════════════════════════════════════════════════
        // Static logo map — instant lookup, no external API required
        const LOGO_MAP = {
            'Arsenal': '42', 'Aston Villa': '66', 'Bournemouth': '35', 'Brentford': '55',
            'Brighton': '51', 'Chelsea': '49', 'Crystal Palace': '52', 'Everton': '45',
            'Fulham': '36', 'Liverpool': '40', 'Man City': '50', 'Man United': '33',
            'Manchester City': '50', 'Manchester United': '33', 'Newcastle': '34',
            'Nottm Forest': '65', 'Nottingham Forest': '65', 'Southampton': '41',
            'Tottenham': '47', 'West Ham': '48', 'Wolves': '39', 'Leeds United': '63',
            'Sheffield United': '62', 'Burnley': '44', 'Sunderland': '80', 'Watford': '38',
            'Norwich City': '71', 'Stoke City': '70', 'Athletic Bilbao': '531',
            'Atletico Madrid': '530', 'Barcelona': '529', 'Real Madrid': '541',
            'Real Sociedad': '548', 'Sevilla': '536', 'Valencia': '532', 'Villarreal': '533',
            'Espanyol': '539', 'Girona': '547', 'Mallorca': '798', 'Osasuna': '727',
            'Bayern Munich': '157', 'Borussia Dortmund': '165', 'Bayer Leverkusen': '168',
            'RB Leipzig': '173', 'Eintracht Frankfurt': '169', 'Wolfsburg': '161',
            'Werder Bremen': '162', 'Hoffenheim': '167', 'SC Freiburg': '160',
            'Augsburg': '170', 'AC Milan': '489', 'Inter Milan': '505', 'Internazionale': '505',
            'Juventus': '496', 'Napoli': '492', 'Atalanta': '499', 'Roma': '497',
            'AS Roma': '497', 'Lazio': '487', 'Fiorentina': '502', 'Bologna': '500',
            'Torino': '503', 'Genoa': '495', 'PSG': '85', 'Paris Saint-Germain': '85',
            'Marseille': '81', 'Monaco': '91', 'Lyon': '80', 'Lille': '79', 'Nice': '84',
            'Rennes': '94', 'RC Lens': '116', 'Strasbourg': '95', 'Nantes': '83',
            'Ajax': '194', 'Benfica': '211', 'Porto': '212', 'Sporting': '228', 'Sporting CP': '228',
            'Celtic': '31', 'Rangers': '32', 'Galatasaray': '611', 'Flamengo': '127',
            'Palmeiras': '121', 'River Plate': '440', 'Boca Juniors': '433',
            'Al Hilal': '2932', 'Al Nassr': '2933', 'Inter Miami': '1616', 'LA Galaxy': '1615',
        };
        function staticLogo(name) {
            if (!name) return '';
            // exact match
            if (LOGO_MAP[name]) return `https://media.api-sports.io/football/teams/${LOGO_MAP[name]}.png`;
            // case-insensitive partial
            const lower = name.toLowerCase();
            for (const [k, v] of Object.entries(LOGO_MAP)) {
                if (k.toLowerCase().includes(lower) || lower.includes(k.toLowerCase()))
                    return `https://media.api-sports.io/football/teams/${v}.png`;
            }
            return '';
        }

        let logoDebounce = {};
        async function debounceLogo(side) {
            clearTimeout(logoDebounce[side]);
            const token = logoUploadToken?.[side] || 0;
            logoDebounce[side] = setTimeout(async () => {
                if (token !== (logoUploadToken?.[side] || 0)) return;
                const val = document.getElementById('mf-' + side).value.trim();
                if (val.length < 3) return;
                const prev = document.getElementById('mf-' + side + '-logo-preview');
                const inp = document.getElementById('mf-' + side + '-logo');

                // 1. Try static map first — instant, no network
                let logo = staticLogo(val);
                if (logo) {
                    inp.value = logo; prev.src = logo; prev.style.display = 'block';
                } else {
                    prev.style.display = 'none';
                }

                // 2. Also fire API lookup in background to get exact logo
                try {
                    const d = await fetch('api_admin_matches.php?action=logo&name=' + encodeURIComponent(val)).then(r => r.json());
                    if (token !== (logoUploadToken?.[side] || 0)) return;
                    if (d.logo) { inp.value = d.logo; prev.src = d.logo; prev.style.display = 'block'; }
                } catch (e) { }
            }, 600);
        }

        function toggleAdvanced() {
            const el = document.getElementById('adv-fields');
            const btn = document.getElementById('adv-toggle');
            const open = el.style.display === 'block';
            el.style.display = open ? 'none' : 'block';
            btn.textContent = (open ? '▶' : '▼') + ' Score / Status / Advanced';
        }

        function suggestOdds() {
            const strip = document.getElementById('odds-suggest-strip');
            strip.style.display = strip.style.display === 'none' ? 'block' : 'none';
        }

        function applyOddsPreset(preset) {
            const presets = {
                fav_home: [1.55, 3.90, 5.50],
                balanced: [2.80, 3.10, 2.80],
                fav_away: [5.00, 3.80, 1.65],
                high_odds: [3.60, 3.40, 2.20],
            };
            const [o1, oX, o2] = presets[preset] || [2.10, 3.40, 3.60];
            // add small random variance so it looks natural
            const rand = () => ((Math.random() * 0.18) - 0.09).toFixed(2);
            document.getElementById('mf-o1').value = (o1 + parseFloat(rand())).toFixed(2);
            document.getElementById('mf-oX').value = (oX + parseFloat(rand())).toFixed(2);
            document.getElementById('mf-o2').value = (o2 + parseFloat(rand())).toFixed(2);
            document.getElementById('odds-suggest-strip').style.display = 'none';
        }

        function showMatchForm(m = null) {
            const f = document.getElementById('matchForm');
            f.style.display = 'block';
            document.getElementById('matchFormTitle').textContent = m ? 'Edit Match' : 'Add New Match';
            resetLogoUploadState('home');
            resetLogoUploadState('away');
            document.getElementById('mf-id').value = m ? m.id : '';
            document.getElementById('mf-home').value = m ? m.home_team : '';
            document.getElementById('mf-away').value = m ? m.away_team : '';
            document.getElementById('mf-league').value = m ? m.league : 'Featured';
            // Pre-fill datetime picker with existing live_at if editing a match
            if (m && m.live_at) {
                // live_at is UTC from DB — convert to local datetime-local format (YYYY-MM-DDTHH:MM)
                const liveUtc = new Date(m.live_at.replace(' ', 'T') + 'Z');
                if (!isNaN(liveUtc)) {
                    const pad = n => String(n).padStart(2, '0');
                    const local = liveUtc.getFullYear() + '-' + pad(liveUtc.getMonth() + 1) + '-' + pad(liveUtc.getDate())
                        + 'T' + pad(liveUtc.getHours()) + ':' + pad(liveUtc.getMinutes());
                    document.getElementById('mf-golive').value = local;
                } else {
                    document.getElementById('mf-golive').value = '';
                }
            } else {
                document.getElementById('mf-golive').value = '';
            }
            const section = m ? (m.is_pinned == 1 && m.show_in_today == 1 ? 'both' : m.is_pinned == 1 ? 'popular' : 'today') : 'both';
            document.getElementById('mf-section').value = section;
            document.getElementById('mf-first-half').value = m ? (m.first_half_mins || 45) : 45;
            document.getElementById('mf-ht-break').value = m ? (m.ht_break_mins || 15) : 15;
            document.getElementById('mf-duration').value = m ? (m.duration_mins || 50) : 50;
            document.getElementById('mf-o1').value = m ? m.odds_home : 2.10;
            document.getElementById('mf-oX').value = m ? m.odds_draw : 3.40;
            document.getElementById('mf-o2').value = m ? m.odds_away : 3.60;
            document.getElementById('mf-sh').value = m?.score_home ?? '';
            document.getElementById('mf-sa').value = m?.score_away ?? '';
            // Populate target score field
            const fsh = m?.final_score_home; const fsa = m?.final_score_away;
            document.getElementById('mf-target-score').value = (fsh !== null && fsh !== undefined && fsh !== '') ? fsh + '-' + fsa : '';
            document.getElementById('mf-goal-minutes').value = m?.goal_minutes ?? '';
            if (fsh !== null && fsh !== undefined && fsh !== '') previewTargetScore(fsh + '-' + fsa);
            document.getElementById('mf-status').value = m ? (m.status || 'Not Started') : 'Not Started';
            document.getElementById('mf-elapsed').value = m ? (m.elapsed || 0) : 0;
            document.getElementById('mf-order').value = m ? (m.pin_order || 1) : 1;
            document.getElementById('mf-lock').checked = m ? m.odds_locked == 1 : false;
            document.getElementById('mf-auto-odds').checked = m ? m.odds_auto_enabled != 0 : true;
            document.getElementById('mf-pinned').checked = m ? m.is_pinned == 1 : true;
            if (m?.home_logo) {
                document.getElementById('mf-home-logo').value = m.home_logo;
                const p = document.getElementById('mf-home-logo-preview');
                p.src = m.home_logo; p.style.display = 'block';
                document.getElementById('mf-home-logo-fname').textContent = 'Current logo';
                document.getElementById('mf-home-logo-clear').style.display = 'inline-block';
            }
            if (m?.away_logo) {
                document.getElementById('mf-away-logo').value = m.away_logo;
                const p = document.getElementById('mf-away-logo-preview');
                p.src = m.away_logo; p.style.display = 'block';
                document.getElementById('mf-away-logo-fname').textContent = 'Current logo';
                document.getElementById('mf-away-logo-clear').style.display = 'inline-block';
            }
            // Show live timer status inline in the form
            const timerBanner = document.getElementById('mf-timer-banner');
            if (timerBanner) {
                if (m && m._timer_state && m._timer_state !== '') {
                    const state = m._timer_state;
                    // Show the actual date/time from the datetime picker when pending
                    const goLiveVal = document.getElementById('mf-golive').value;
                    const liveLabel = goLiveVal ? new Date(goLiveVal).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }) : m._mins_until + ' min(s)';
                    const badge = state === 'pending' ? `<span style="color:#f59e0b">⏳ Goes live: <strong>${liveLabel}</strong></span>` :
                        state === 'live' ? `<span style="color:var(--acc)">🔴 LIVE — <strong>${m._elapsed}</strong> min elapsed</span>` :
                            state === 'ended' ? `<span style="color:var(--dim)">🏁 Timer ended</span>` : '';
                    timerBanner.innerHTML = badge
                        + ` &nbsp;<button type="button" class="btn btn-red btn-sm" onclick="clearMatchTimer()">✕ Clear Timer</button>`;
                    timerBanner.style.display = 'flex';
                } else {
                    timerBanner.style.display = 'none';
                    timerBanner.innerHTML = '';
                }
            }
            f.scrollIntoView({ behavior: 'smooth' });
        }

        function clearMatchTimer() {
            // Clear timer by setting live_at / end_at to empty on next save
            document.getElementById('mf-golive').value = '';
            document.getElementById('mf-duration').value = 95;
            const b = document.getElementById('mf-timer-banner');
            if (b) b.style.display = 'none';
            // Persist clear immediately via a mini update
            const id = document.getElementById('mf-id').value.trim();
            if (!id) return;
            fetch('api_admin_matches.php', {
                method: 'POST', body: new URLSearchParams({
                    action: 'update', id, live_at: '', end_at: ''
                })
            }).then(() => { toast('Timer cleared'); loadMatches(); });
        }

        async function saveMatch() {
            const id = document.getElementById('mf-id').value.trim();
            const home = document.getElementById('mf-home').value.trim();
            const away = document.getElementById('mf-away').value.trim();
            if (!home || !away) { toast('Enter both team names', true); return; }
            const pendingBlobs = [logoUploadReady.home, logoUploadReady.away].filter(Boolean);
            if (pendingBlobs.length) await Promise.all(pendingBlobs);
            // Read the datetime-local value (browser gives local time as "YYYY-MM-DDTHH:MM")
            const goLiveRaw = document.getElementById('mf-golive').value.trim();
            const section = document.getElementById('mf-section').value;
            const fd = new FormData();
            const append = (k, v) => fd.append(k, v === null || v === undefined ? '' : v);
            append('action', id ? 'update' : 'create');
            append('id', id);
            append('home_team', home);
            append('away_team', away);
            append('is_pinned', (section === 'both' || section === 'popular') ? 1 : 0);
            append('show_in_today', (section === 'both' || section === 'today') ? 1 : 0);
            append('home_logo', document.getElementById('mf-home-logo').value);
            append('away_logo', document.getElementById('mf-away-logo').value);
            append('league', document.getElementById('mf-league').value);
            append('odds_home', document.getElementById('mf-o1').value);
            append('odds_draw', document.getElementById('mf-oX').value);
            append('odds_away', document.getElementById('mf-o2').value);
            append('score_home', document.getElementById('mf-sh').value);
            append('score_away', document.getElementById('mf-sa').value);
            append('target_score', document.getElementById('mf-target-score').value.trim());
            append('goal_minutes', document.getElementById('mf-goal-minutes').value.trim());
            append('status', document.getElementById('mf-status').value);
            append('elapsed', document.getElementById('mf-elapsed').value);
            append('pin_order', document.getElementById('mf-order').value);
            append('odds_locked', document.getElementById('mf-lock').checked ? 1 : 0);
            append('odds_auto_enabled', document.getElementById('mf-auto-odds').checked ? 1 : 0);
            append('is_active', 1);

            if (goLiveRaw !== '') {
                // Convert local datetime to UTC ISO string for the server
                const dt = new Date(goLiveRaw);
                if (!isNaN(dt)) {
                    // Format as "YYYY-MM-DD HH:MM:SS" in UTC for MySQL
                    const pad = n => String(n).padStart(2, '0');
                    const live_at = dt.getUTCFullYear() + '-' + pad(dt.getUTCMonth() + 1) + '-' + pad(dt.getUTCDate())
                        + ' ' + pad(dt.getUTCHours()) + ':' + pad(dt.getUTCMinutes()) + ':00';
                    const durMins = Math.max(1, parseInt(document.getElementById('mf-duration').value, 10) || 50);
                    const firstHalf = Math.max(1, parseInt(document.getElementById('mf-first-half').value, 10) || 45);
                    const htBreak = Math.max(0, parseInt(document.getElementById('mf-ht-break').value, 10) || 0);
                    // Total duration = 1st half + HT break + 2nd half
                    const totalMins = firstHalf + htBreak + durMins;
                    const endDt = new Date(dt.getTime() + totalMins * 60000);
                    const end_at = endDt.getUTCFullYear() + '-' + pad(endDt.getUTCMonth() + 1) + '-' + pad(endDt.getUTCDate())
                        + ' ' + pad(endDt.getUTCHours()) + ':' + pad(endDt.getUTCMinutes()) + ':00';
                    append('live_at', live_at);
                    append('end_at', end_at);
                    append('duration_mins', durMins);
                    append('first_half_mins', firstHalf);
                    append('ht_break_mins', htBreak);
                    append('match_time', pad(dt.getHours()) + ':' + pad(dt.getMinutes()));
                    append('match_date', dt.getFullYear() + '-' + pad(dt.getMonth() + 1) + '-' + pad(dt.getDate()));
                }
            }

            const homeFile = document.getElementById('mf-home-logo-file').files[0];
            const awayFile = document.getElementById('mf-away-logo-file').files[0];
            if (logoUploadBlobs.home) fd.append('home_logo_file', logoUploadBlobs.home, logoUploadBlobs.home.type === 'image/png' ? 'home-logo.png' : (homeFile ? homeFile.name : 'home-logo.jpg'));
            if (logoUploadBlobs.away) fd.append('away_logo_file', logoUploadBlobs.away, logoUploadBlobs.away.type === 'image/png' ? 'away-logo.png' : (awayFile ? awayFile.name : 'away-logo.jpg'));

            const r = await fetch('api_admin_matches.php', { method: 'POST', body: fd });
            const text = await r.text();
            let d;
            try { d = JSON.parse(text); }
            catch (e) { d = { success: false, message: text.slice(0, 180) || 'Server error' }; }
            if (d.success) {
                const liveLabel = goLiveRaw !== '' ? ' — goes live ' + new Date(goLiveRaw).toLocaleString() : '';
                toast('Match saved!' + liveLabel);
                document.getElementById('matchForm').style.display = 'none';
                loadMatches();
            } else toast(d.message || 'Error', true);
        }

        async function autoCloseExpiredMatches() {
            const btn = document.getElementById('auto-close-btn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Closing…';
            try {
                const res = await fetch('api_auto_settle.php?all=1', { credentials: 'same-origin' });
                const data = await res.json();
                if (data.auto_closed > 0) {
                    toast(`✅ ${data.auto_closed} match(es) marked FT. ${data.settled} ticket(s) auto-settled.`);
                    loadMatches();
                    loadTickets('Running');
                } else {
                    toast('No expired matches found to close.');
                }
            } catch (e) { toast('Error contacting server', true); }
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-flag-checkered"></i> Mark Expired FT';
        }

        function renderAdminMatchScore(m) {
            const fh = parseInt(m.final_score_home);
            const fa = parseInt(m.final_score_away);
            const elapsed = parseInt(m.elapsed || m._elapsed || 0);
            const isLive = m.status && ['1H', '2H', 'HT'].includes(m.status);
            if (m.score_home !== null && m.score_home !== '' && m.score_home !== undefined) {
                return m.score_home + ' - ' + m.score_away;
            }
            // If target score set and match is live, show auto-computed score
            if (!isNaN(fh) && !isNaN(fa) && isLive && elapsed >= 0) {
                const cs = computeAutoScore(fh, fa, elapsed, 90, m.goal_minutes || '');
                return `<span title="Auto-spreading to ${fh}-${fa}">${cs.home} - ${cs.away} <span style="font-size:9px;opacity:.6">→${fh}-${fa}</span></span>`;
            }
            if (isLive) {
                return '0 - 0';
            }
            // If target score is set but match not live yet, show it grayed
            if (!isNaN(fh) && !isNaN(fa)) {
                return `<span style="opacity:.4" title="Target final score">${fh}-${fa}</span>`;
            }
            return '—';
        }

        async function loadMatches() {
            const d = await api('api_admin_matches.php', { action: 'list' });
            if (!d.success) return;
            const container = document.getElementById('matchesList');
            if (!d.matches.length) {
                container.innerHTML = '<div style="text-align:center;padding:40px;color:var(--dim);font-size:13px">No admin matches yet.</div>';
                return;
            }
            container.innerHTML = `<div class="card">
        <div class="card-title" style="margin-bottom:12px">Active Matches (${d.matches.length})</div>
        <div style="overflow-x:auto"><table class="tbl">
        <thead><tr><th>#</th><th>Match</th><th>Odds 1/X/2</th><th>Score</th><th>State</th><th>Actions</th></tr></thead>
        <tbody>
        ${d.matches.map(m => {
                const timerBadge = m._timer_state === 'pending'
                    ? `<span class="badge b-run">⏳ ${m._mins_until}m</span>`
                    : m._timer_state === 'live'
                        ? `<span class="badge b-won">🔴 ${m._elapsed}m</span>`
                        : m._timer_state === 'ended'
                            ? `<span class="badge b-lost">🏁 Ended</span>`
                            : `<span class="badge ${m.status === 'Not Started' ? 'b-run' : m.status === 'FT' ? 'b-lost' : 'b-won'}">${m.status}</span>`;
                return `<tr>
            <td style="color:var(--dim)">${m.pin_order}</td>
            <td>
                <div style="display:flex;align-items:center;gap:7px;flex-wrap:wrap">
                    ${m.home_logo ? `<img src="${m.home_logo}" style="width:18px;height:18px;object-fit:contain">` : ''} 
                    <b>${m.home_team}</b> <span style="color:var(--dim)">vs</span> <b>${m.away_team}</b>
                    ${m.away_logo ? `<img src="${m.away_logo}" style="width:18px;height:18px;object-fit:contain">` : ''} 
                </div>
                <div style="font-size:10px;color:var(--dim)">${m.league}</div>
            </td>
            <td style="font-family:monospace;color:var(--acc);font-size:12px">${(+(m._odds_effective?.[0] ?? m.odds_home) || 0).toFixed(2)} / ${(+(m._odds_effective?.[1] ?? m.odds_draw) || 0).toFixed(2)} / ${(+(m._odds_effective?.[2] ?? m.odds_away) || 0).toFixed(2)}${m._timer_state === 'live' && m.odds_auto_enabled != 0 ? '<div style="font:700 9px sans-serif;color:var(--dim);margin-top:3px">AUTO LIVE</div>' : ''}</td>
            <td style="font-weight:900;color:var(--acc)">${renderAdminMatchScore(m)}</td>
            <td>${timerBadge}${m._odds_locked_effective ? ` <span title="${agentEsc(m._odds_lock_reason || 'Locked')}">🔒</span>` : ''}</td>
            <td style="display:flex;gap:5px">
                <button class="btn btn-ghost btn-sm" onclick='showMatchForm(${JSON.stringify(m)})'>Edit</button>
                <button class="btn btn-red btn-sm" onclick="deleteMatch(${m.id})">Del</button>
            </td></tr>`;
            }).join('')}
        </tbody></table></div></div>`;
        }

        async function deleteMatch(id) {
            if (!confirm('Delete this match?')) return;
            const d = await api('api_admin_matches.php', { action: 'delete', id });
            if (d.success) { toast('Deleted'); loadMatches(); } else toast(d.message, true);
        }

        // ══════════ BOOKING CODES ═══════════════════════════════════════════
        let selRows = [];
        let currentImportId = null;

        // ── Target Score preview ─────────────────────────────────────────────────────
        function parseGoalMinutes(raw, total, duration = 90) {
            const parsed = String(raw || '')
                .split(/[\s,]+/)
                .map(v => parseInt(v, 10))
                .filter(v => Number.isFinite(v) && v >= 0);
            if (!total) return [];
            if (!parsed.length) {
                const goalMins = [];
                for (let i = 1; i <= total; i++) goalMins.push(Math.round(duration / (total + 1) * i));
                return goalMins;
            }
            parsed.sort((a, b) => a - b);
            const limited = parsed.slice(0, total);
            if (limited.length >= total) return limited;
            let last = limited[limited.length - 1] || 0;
            const remaining = total - limited.length;
            const step = Math.max(1, Math.round((duration - last) / (remaining + 1)));
            for (let i = 1; i <= remaining; i++) limited.push(Math.min(duration, last + step * i));
            return limited;
        }

        function previewTargetScore(val) {
            const el = document.getElementById('target-score-preview');
            const m = val.match(/^(\d+)-(\d+)$/);
            if (!m) { el.style.display = 'none'; return; }
            const h = parseInt(m[1]), a = parseInt(m[2]);
            const total = h + a;
            if (total === 0) { el.style.display = 'none'; return; }
            const goalMins = parseGoalMinutes(document.getElementById('mf-goal-minutes')?.value || '', total, 90);
            let html = `✅ Target: <strong>${h} - ${a}</strong> &nbsp;|&nbsp; ${total} goal(s) at minutes: <strong>${goalMins.join(', ')}</strong>`;
            el.innerHTML = html;
            el.style.display = 'block';
        }

        /**
         * Compute auto-spread current score based on elapsed time and target final score.
         * @param {number} finalHome  - target home score
         * @param {number} finalAway  - target away score
         * @param {number} elapsed    - minutes elapsed
         * @param {number} duration   - total match duration (default 90)
         * @returns {{home: number, away: number}}
         */
        function computeAutoScore(finalHome, finalAway, elapsed, duration = 90, goalMinutesRaw = '') {
            const total = finalHome + finalAway;
            if (total === 0 || elapsed <= 0) return { home: 0, away: 0 };
            if (elapsed >= duration) return { home: finalHome, away: finalAway };

            const goalMins = parseGoalMinutes(goalMinutesRaw, total, duration);

            // Count goals scored so far
            const scored = goalMins.filter(m => m <= elapsed).length;
            if (scored === 0) return { home: 0, away: 0 };
            if (scored >= total) return { home: finalHome, away: finalAway };

            // Assign proportionally: home goals first (deterministic)
            const homeScored = Math.round(scored * finalHome / total);
            return { home: homeScored, away: scored - homeScored };
        }

        // ── From My Matches — load admin matches for booking code builder ─────────────
        let _fmMatchSelections = {}; // { match_id: { pick, odds, included } }

        async function loadFmMatches() {
            const loadEl = document.getElementById('fm-matches-loading');
            const listEl = document.getElementById('fm-matches-list');
            loadEl.style.display = 'block';
            listEl.style.display = 'none';
            _fmMatchSelections = {};

            try {
                const d = await fetch('api_admin_booking.php?action=get_admin_matches').then(r => r.json());
                if (!d.success || !d.matches.length) {
                    loadEl.innerHTML = '<span style="color:var(--dim)">No active admin matches found. Create matches first in the Matches panel.</span>';
                    return;
                }
                loadEl.style.display = 'none';
                listEl.style.display = 'block';

                let html = '<div style="display:flex;flex-direction:column;gap:8px">';
                d.matches.forEach(m => {
                    const finalHome = Number.parseInt(m.final_score_home, 10);
                    const finalAway = Number.parseInt(m.final_score_away, 10);
                    let defaultPick = '1';
                    let defaultOdds = parseFloat(m.odds_home) || 1;
                    if (Number.isFinite(finalHome) && Number.isFinite(finalAway)) {
                        if (finalHome === finalAway) {
                            defaultPick = 'X';
                            defaultOdds = parseFloat(m.odds_draw) || 1;
                        } else if (finalHome < finalAway) {
                            defaultPick = '2';
                            defaultOdds = parseFloat(m.odds_away) || 1;
                        }
                    }
                    _fmMatchSelections[m.id] = { pick: defaultPick, odds: defaultOdds, included: false, match: m };
                    // Compute live score if match is live
                    let scoreDisplay = '—';
                    if (m.score_home !== null && m.score_home !== '') {
                        scoreDisplay = m.score_home + ' - ' + m.score_away;
                    } else if ((m.final_score_home !== null) && m.status && ['1H', '2H', 'HT'].includes(m.status)) {
                        const cs = computeAutoScore(parseInt(m.final_score_home), parseInt(m.final_score_away), parseInt(m.elapsed || 0), 90, m.goal_minutes || '');
                        scoreDisplay = cs.home + ' - ' + cs.away + ' <span style="font-size:9px;color:var(--acc)">(auto)</span>';
                    } else if (m.status && ['1H', '2H', 'HT'].includes(m.status)) {
                        scoreDisplay = '0 - 0';
                    }

                    html += `<div style="background:rgba(255,255,255,0.03);border:1px solid var(--bdr);border-radius:10px;padding:12px 14px">
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                    <input type="checkbox" id="fm-inc-${m.id}" onchange="toggleFmMatch(${m.id})"
                        style="width:16px;height:16px;accent-color:var(--acc);flex-shrink:0">
                    <div style="flex:1;min-width:0">
                        <div style="font-size:13px;font-weight:800">${m.home_team} <span style="color:var(--dim)">vs</span> ${m.away_team}</div>
                        <div style="font-size:10px;color:var(--dim)">${m.league} &bull; ${m.match_date} ${m.match_time} &bull; Score: ${scoreDisplay}</div>
                    </div>
                    <div id="fm-picks-${m.id}" style="display:none;align-items:center;gap:8px;flex-wrap:wrap">
                        <select onchange="setFmPick(${m.id}, this.value)"
                            style="background:var(--bg-card);border:1px solid var(--bdr);color:var(--text);border-radius:8px;padding:6px 10px;font-size:12px;font-weight:700">
                            <option value="1" data-odds="${m.odds_home}" ${defaultPick === '1' ? 'selected' : ''}>🏠 Home Win (1)  @${parseFloat(m.odds_home).toFixed(2)}</option>
                            <option value="X" data-odds="${m.odds_draw}" ${defaultPick === 'X' ? 'selected' : ''}>🤝 Draw (X)  @${parseFloat(m.odds_draw).toFixed(2)}</option>
                            <option value="2" data-odds="${m.odds_away}" ${defaultPick === '2' ? 'selected' : ''}>✈️ Away Win (2)  @${parseFloat(m.odds_away).toFixed(2)}</option>
                        </select>
                        <input type="number" step="0.01" min="1" id="fm-odds-${m.id}" value="${defaultOdds.toFixed(2)}"
                            onchange="setFmOdds(${m.id}, this.value)"
                            style="width:70px;background:var(--bg-card);border:1px solid var(--bdr);color:var(--acc);border-radius:8px;padding:6px 8px;font-size:13px;font-weight:900;text-align:center">
                    </div>
                </div>
            </div>`;
                });
                html += '</div>';
                listEl.innerHTML = html;
            } catch (e) {
                loadEl.innerHTML = '<span style="color:#ef4444">Error loading matches</span>';
            }
        }

        function toggleFmMatch(id) {
            const checked = document.getElementById('fm-inc-' + id).checked;
            _fmMatchSelections[id].included = checked;
            const picksEl = document.getElementById('fm-picks-' + id);
            picksEl.style.display = checked ? 'flex' : 'none';
        }

        function setFmPick(id, pick) {
            _fmMatchSelections[id].pick = pick;
            // Auto-fill odds from the selected option
            const sel = document.querySelector(`#fm-picks-${id} select`);
            const opt = sel.options[sel.selectedIndex];
            const odds = parseFloat(opt.getAttribute('data-odds')) || 1;
            document.getElementById('fm-odds-' + id).value = odds.toFixed(2);
            _fmMatchSelections[id].odds = odds;
        }

        function setFmOdds(id, val) {
            _fmMatchSelections[id].odds = parseFloat(val) || 1;
        }

        async function createCodeFromMatches() {
            const selected = Object.entries(_fmMatchSelections).filter(([, v]) => v.included);
            if (!selected.length) { toast('Select at least one match', true); return; }

            const selections = selected.map(([id, v]) => ({
                admin_match_id: parseInt(id),
                home_team: v.match.home_team,
                away_team: v.match.away_team,
                league: v.match.league,
                market: 'Match Result',
                pick: v.pick,
                odds: v.odds,
                match_time: v.match.match_date + ' ' + v.match.match_time,
            }));

            const code = document.getElementById('fm-code').value.trim();
            const stake = document.getElementById('fm-stake').value;
            const minStake = document.getElementById('fm-min-stake').value;
            const resultEl = document.getElementById('fm-result');
            resultEl.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Creating…';

            const fd = new FormData();
            fd.append('action', 'from_admin_matches');
            fd.append('code', code);
            fd.append('stake', stake);
            fd.append('min_stake', minStake);
            fd.append('is_revealed', '1');
            fd.append('selections', JSON.stringify(selections));

            try {
                const d = await fetch('api_admin_booking.php', { method: 'POST', body: fd }).then(r => r.json());
                if (d.success) {
                    resultEl.innerHTML = `✅ Code <strong style="color:var(--acc);font-family:monospace;letter-spacing:1px">${d.code}</strong> created! Total odds: <strong>${parseFloat(d.total_odds).toFixed(2)}</strong>`;
                    loadCodes();
                } else {
                    resultEl.innerHTML = `<span style="color:#ef4444">✗ ${d.message}</span>`;
                }
            } catch (e) {
                resultEl.innerHTML = '<span style="color:#ef4444">Network error</span>';
            }
        }

        function switchCodeTab(tab, el) {
            ['manual', 'sporty', 'list'].forEach(t => document.getElementById('code-tab-' + t).style.display = t === tab ? 'block' : 'none');
            // Reset manual post-save panel when leaving manual tab
            if (tab !== 'manual') {
                const ps = document.getElementById('cc-post-save');
                if (ps) ps.style.display = 'none';
                const res = document.getElementById('cc-result');
                if (res) res.innerHTML = '';
                currentManualId = null;
            }
            document.querySelectorAll('#panel-codes .inner-tab').forEach(e => e.classList.remove('active'));
            el.classList.add('active');
            if (tab === 'list') loadCodes();
        }

        // Auto-generate internal code when sporty code is pasted
        function onSportyPaste(val) {
            const intEl = document.getElementById('si-internal');
            if (!intEl.value.trim()) {
                // Generate a short mixed code based on sporty code
                const base = (val.slice(0, 3) + Math.random().toString(36).slice(2, 6)).toUpperCase().slice(0, 7);
                intEl.value = base;
            }
        }

        function addSelRow(data = {}) {
            const id = 'sr_' + Date.now();
            const hidden = data.hidden ? 'checked' : '';
            const div = document.createElement('div');
            div.className = 'sel-row'; div.id = id;
            const teams = (data.teams || '').replace(/"/g, '&quot;');
            const market = (data.market || '').replace(/"/g, '&quot;');
            const odds = data.odds || '';
            const time = data.time || '';
            div.innerHTML = `
        <input class="inp" placeholder="Home vs Away" value="${teams}">
        <input class="inp" placeholder="Market — Pick (e.g. 1X2 — 1)" value="${market}">
        <input class="inp" type="number" step="0.01" placeholder="Odds" value="${odds}">
        <input class="inp" type="time" value="${time}">
        <div style="display:flex;align-items:center;justify-content:center"><input type="checkbox" style="width:16px;height:16px;accent-color:var(--acc)" ${hidden}></div>
        <button class="btn btn-red btn-sm" onclick="document.getElementById('${id}').remove()">✕</button>`;
            document.getElementById('sel-list').appendChild(div);
        }

        var currentManualId = null;

        async function saveCode() {
            const rows = [...document.querySelectorAll('#sel-list .sel-row')];
            const sels = rows.map(r => {
                const ins = r.querySelectorAll('input');
                const tv = ins[0].value.split(' vs ');
                const mp = ins[1].value.split(' — ');
                return {
                    home: (tv[0] || '').trim() || 'Undefined',
                    away: (tv[1] || '').trim() || 'Undefined',
                    market: (mp[0] || '').trim() || 'Match Result',
                    pick: (mp[1] || '').trim() || 'Pick',
                    odds: ins[2].value || '1.00',
                    time: ins[3].value || 'Today',
                    score: 'N/A',
                    hidden: ins[4].checked ? 1 : 0,
                };
            });
            if (!sels.length) { toast('Add at least one selection', true); return; }

            const hideAll = document.getElementById('cc-hidden-all').checked;
            const payload = {
                action: 'create',
                code: document.getElementById('cc-code').value.toUpperCase().trim(),
                stake: document.getElementById('cc-stake').value,
                min_stake: document.getElementById('cc-min-stake').value,
                reveal_at: document.getElementById('cc-reveal').value,
                is_revealed: hideAll ? 0 : 1,
                selections: JSON.stringify(sels.map(s => Object.assign({}, s, { hidden: hideAll ? 1 : s.hidden }))),
            };
            const d = await fetch('api_admin_booking.php', { method: 'POST', body: new URLSearchParams(payload) }).then(r => r.json());
            if (d.success) {
                const resultEl = document.getElementById('cc-result');
                if (resultEl) resultEl.innerHTML = '<span style="color:var(--acc)">✓ Code: <strong>' + d.code + '<\/strong> (' + sels.length + ' selections)<\/span>';
                toast('Code created: ' + d.code);
                // Reset the form builder but keep code visible
                document.getElementById('cc-code').value = d.code;
                document.getElementById('sel-list').innerHTML = '';
                // Show the post-save edit panel — identical to Sporty post-import
                currentManualId = d.code_id;
                document.getElementById('cc-sel-count').textContent = '(' + sels.length + ' selections)';
                document.getElementById('cc-post-save').style.display = 'block';
                await loadImportedSelections(d.code_id, hideAll, 'cc-sel-list');
                loadCodes();
            } else {
                toast(d.message || 'Error', true);
                const resultEl = document.getElementById('cc-result');
                if (resultEl) resultEl.innerHTML = '<span style="color:#ef4444">✗ ' + (d.message || 'Error') + '<\/span>';
            }
        }

        async function importSporty(e) {
            const btn = document.getElementById('si-btn'); btn.disabled = true;
            const hideAll = document.getElementById('si-hide-all').checked;
            const payload = {
                action: 'import_sporty',
                sporty_code: document.getElementById('si-sporty').value.toUpperCase().trim(),
                internal_code: document.getElementById('si-internal').value.toUpperCase().trim(),
                reveal_at: document.getElementById('si-reveal').value,
                min_stake: document.getElementById('si-min-stake').value,
                hide_all: hideAll ? 1 : 0,
                is_revealed: hideAll ? 0 : 1,
            };
            if (!payload.sporty_code) { toast('Enter a Sportybet code', true); btn.disabled = false; return; }
            const d = await fetch('api_admin_booking.php', { method: 'POST', body: new URLSearchParams(payload) })
                .then(r => r.json()).catch(() => ({ success: false, message: 'Network error' }));

            if (d.success) {
                document.getElementById('sporty-result').innerHTML =
                    `<span style="color:var(--acc)">✓ Imported! Code: <strong>${d.internal_code}</strong> — ${d.selections_count} selections</span>`;
                toast('Imported → ' + d.internal_code);
                currentImportId = d.code_id;
                // Load selections for hide/unhide
                await loadImportedSelections(d.code_id, hideAll);
            } else {
                document.getElementById('sporty-result').innerHTML = `<span style="color:#ef4444">✗ ${d.message}</span>`;
            }
            btn.disabled = false;
        }

        async function toggleSelHide(selId, hide) {
            const d = await fetch('api_admin_booking.php', { method: 'POST', body: new URLSearchParams({ action: 'toggle_sel_hide', sel_id: selId, hide }) }).then(r => r.json());
            if (d.success) {
                const stateEl = document.getElementById('selstate_' + selId);
                const btn = document.getElementById('selbtn_' + selId);
                if (stateEl) { stateEl.textContent = hide ? '🙈 Hidden' : '👁 Shown'; stateEl.style.color = hide ? '#f97316' : 'var(--acc)'; }
                if (btn) { btn.textContent = hide ? 'Unhide' : 'Hide'; btn.setAttribute('onclick', `toggleSelHide(${selId}, ${hide ? 0 : 1})`); }
            }
        }



        async function toggleCodeHide(codeId, hide) {
            const d = await fetch('api_admin_booking.php', { method: 'POST', body: new URLSearchParams({ action: 'toggle_hide', id: codeId, hide }) }).then(r => r.json());
            if (d.success) {
                toast(hide ? '🙈 All selections hidden' : '👁 All selections revealed');
                // Reload whichever post-save panel is currently showing this code
                if (currentImportId === codeId) await loadImportedSelections(codeId, false, 'si-sel-list');
                if (currentManualId === codeId) await loadImportedSelections(codeId, false, 'cc-sel-list');
                loadCodes();
            }
        }

        async function loadCodes() {
            const d = await fetch('api_admin_booking.php?action=list').then(r => r.json());
            if (!d.success) return;
            const el = document.getElementById('codes-list');
            if (!d.codes.length) { el.innerHTML = '<div class="card" style="text-align:center;color:var(--dim);padding:30px">No codes yet.</div>'; return; }
            el.innerHTML = `<div class="card">
        <div class="card-title">All Admin Codes (${d.codes.length})</div>
        <div style="overflow-x:auto"><table class="tbl">
        <thead><tr><th>Code</th><th>Source</th><th>Stake</th><th>Odds</th><th>Sels</th><th>Hidden</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>${d.codes.map(c => `<tr>
            <td style="font-family:monospace;font-weight:900;color:var(--acc);letter-spacing:.1em">${c.code}</td>
            <td><span class="badge ${c.source_type === 'sporty' ? 'b-run' : 'b-won'}">${c.source_type}</span></td>
            <td>GHS ${parseFloat(c.stake).toFixed(2)}</td>
            <td style="font-style:italic">${parseFloat(c.total_odds).toFixed(2)}x</td>
            <td>${c.sel_count}</td>
            <td>${parseInt(c.hidden_count) > 0 ? `<span style="color:#f97316">${c.hidden_count} hidden</span>` : '<span style="color:var(--acc)">All shown</span>'}</td>
            <td>${c.settled_as
                    ? `<span class="badge ${c.settled_as === 'Won' ? 'b-won' : c.settled_as === 'Lost' ? 'b-lost' : 'b-run'}">${c.settled_as}</span>`
                    : `<span style="color:var(--acc);font-size:11px;font-weight:700">Open</span>`
                }</td>
            <td style="display:flex;gap:5px;flex-wrap:wrap">
                <button class="btn btn-ghost btn-sm" onclick="openCodeEditor(${c.id},'${c.code}')">✏️ Edit</button>
                <button class="btn btn-ghost btn-sm" onclick="shareCode('${c.code}')">📤 Share</button>
                ${parseInt(c.hidden_count) > 0
                    ? `<button class="btn btn-acc btn-sm" onclick="toggleCodeHide(${c.id},0)">👁 Show</button>`
                    : `<button class="btn btn-ghost btn-sm" onclick="toggleCodeHide(${c.id},1)">🙈 Hide</button>`}
                ${c.is_revealed == 0 ? `<button class="btn btn-blue btn-sm" onclick="revealCode(${c.id})">Reveal</button>` : ''}
                <button class="btn btn-acc btn-sm" onclick="openSettlePanel('${c.code}')">${c.settled_as ? '🔄 Re-settle' : '🏆 Settle'}</button>
                <button class="btn btn-red btn-sm" onclick="deleteCode(${c.id})">Del</button>
            </td>
        </tr>`).join('')}
        </tbody></table></div></div>
    <div id="code-editor-panel" style="margin-top:16px"></div>`;
        }

        // ── Open editor for any code from the All Codes list ────────────
        async function openCodeEditor(codeId, codeName) {
            currentImportId = codeId;
            const panel = document.getElementById('code-editor-panel');
            const codeRow = (await fetch('api_admin_booking.php?action=get_selections&id=' + codeId).then(r => r.json()));
            const codeData = codeRow.code || {};
            panel.innerHTML = `<div style="background:rgba(255,255,255,.03);border:1px solid var(--bdr);border-radius:14px;padding:16px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:8px">
            <div style="font-size:14px;font-weight:900;color:var(--acc)">✏️ Editing Code: <span style="font-family:monospace;letter-spacing:1px">${codeName}</span></div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <button class="btn btn-ghost btn-sm" onclick="toggleCodeHide(${codeId},1)">🙈 Hide All</button>
                <button class="btn btn-acc  btn-sm" onclick="toggleCodeHide(${codeId},0)">👁 Reveal All</button>
                <button class="btn btn-ghost btn-sm" onclick="document.getElementById('code-editor-panel').innerHTML=''">✕ Close</button>
            </div>
        </div>

        <!-- Code-level settings -->
        <div style="background:rgba(239,68,68,0.05);border:1px solid rgba(239,68,68,0.15);border-radius:10px;padding:12px 14px;margin-bottom:14px">
            <div style="font-size:10px;font-weight:800;color:var(--acc);text-transform:uppercase;margin-bottom:10px">Code Settings</div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:10px;margin-bottom:10px">
                <div>
                    <label style="font-size:9px;color:var(--dim);font-weight:800;text-transform:uppercase;display:block;margin-bottom:3px">Suggested Stake</label>
                    <input class="inp" type="number" step="0.01" id="ce-stake-${codeId}" value="${parseFloat(codeData.stake || 0).toFixed(2)}" style="padding:6px 10px;font-size:12px">
                </div>
                <div>
                    <label style="font-size:9px;color:var(--dim);font-weight:800;text-transform:uppercase;display:block;margin-bottom:3px">Min Stake</label>
                    <input class="inp" type="number" step="0.01" id="ce-minstake-${codeId}" value="${parseFloat(codeData.min_stake || 0).toFixed(2)}" style="padding:6px 10px;font-size:12px">
                </div>
                <div>
                    <label style="font-size:9px;color:var(--dim);font-weight:800;text-transform:uppercase;display:block;margin-bottom:3px">Status</label>
                    <select class="inp" id="ce-status-${codeId}" style="padding:6px 10px;font-size:12px">
                        <option value="" ${!codeData.settled_as ? 'selected' : ''}>Open</option>
                        <option value="Won" ${codeData.settled_as === 'Won' ? 'selected' : ''}>Won</option>
                        <option value="Lost" ${codeData.settled_as === 'Lost' ? 'selected' : ''}>Lost</option>
                        <option value="Void" ${codeData.settled_as === 'Void' ? 'selected' : ''}>Void</option>
                    </select>
                </div>
                <div>
                    <label style="font-size:9px;color:var(--dim);font-weight:800;text-transform:uppercase;display:block;margin-bottom:3px">Reveal At</label>
                    <input class="inp" type="datetime-local" id="ce-reveal-${codeId}" value="${codeData.reveal_at || ''}" style="padding:6px 10px;font-size:12px">
                </div>
            </div>
            <button class="btn btn-acc btn-sm" onclick="saveCodeSettings(${codeId})">💾 Save Code Settings</button>
            <span id="ce-msg-${codeId}" style="font-size:12px;margin-left:8px"></span>
        </div>

        <!-- Selections -->
        <div id="edit-sel-list-${codeId}"><div style="color:var(--dim);text-align:center;padding:20px">Loading…</div></div>
    </div>`;
            panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            await loadImportedSelections(codeId, false, 'edit-sel-list-' + codeId);
        }

        async function saveCodeSettings(codeId) {
            const stake = document.getElementById('ce-stake-' + codeId)?.value || '0';
            const minStake = document.getElementById('ce-minstake-' + codeId)?.value || '0';
            const status = document.getElementById('ce-status-' + codeId)?.value || '';
            const revealAt = document.getElementById('ce-reveal-' + codeId)?.value || '';
            const msgEl = document.getElementById('ce-msg-' + codeId);

            const fd = new FormData();
            fd.append('action', 'update_code');
            fd.append('id', codeId);
            fd.append('stake', stake);
            fd.append('min_stake', minStake);
            fd.append('settled_as', status);
            fd.append('reveal_at', revealAt);

            try {
                const d = await fetch('api_admin_booking.php', { method: 'POST', body: fd }).then(r => r.json());
                if (d.success) {
                    if (msgEl) { msgEl.textContent = '✓ Saved'; msgEl.style.color = 'var(--acc)'; setTimeout(() => msgEl.textContent = '', 2000); }
                    toast('Code settings saved');
                    loadCodes();
                } else {
                    if (msgEl) { msgEl.textContent = '✗ ' + (d.message || 'Error'); msgEl.style.color = '#ef4444'; }
                }
            } catch (e) {
                if (msgEl) { msgEl.textContent = '✗ Network error'; msgEl.style.color = '#ef4444'; }
            }
        }

        async function loadImportedSelections(codeId, hideAll, containerId) {
            containerId = containerId || 'si-sel-list';
            const r = await fetch('api_admin_booking.php?action=get_selections&id=' + codeId).then(r => r.json());
            if (!r.success) { toast('Could not load selections', true); return; }
            if (!r.selections || !r.selections.length) {
                document.getElementById(containerId).innerHTML = '<div style="color:var(--dim);text-align:center;padding:20px">No selections found.</div>';
                return;
            }
            const container = document.getElementById(containerId);
            container.innerHTML = [
                // "Save All" button at top
                `<div style="display:flex;justify-content:flex-end;margin-bottom:10px">
            <button class="btn btn-acc" onclick="saveAllSelRows(${codeId}, '${containerId}')">💾 Save All Changes</button>
         </div>`,
                ...r.selections.map(s => {
                    const esc = v => String(v || '').replace(/"/g, '&quot;');
                    return `<div id="selrow_${s.id}" style="background:rgba(255,255,255,.03);border:1px solid var(--bdr);border-radius:12px;padding:12px 14px;margin-bottom:10px">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px">
                <div>
                    <div style="font-size:9px;color:var(--dim);font-weight:800;text-transform:uppercase;margin-bottom:3px">Home Team</div>
                    <input class="inp" style="font-size:12px;padding:6px 10px" id="sel_home_${s.id}" value="${esc(s.home_team)}">
                </div>
                <div>
                    <div style="font-size:9px;color:var(--dim);font-weight:800;text-transform:uppercase;margin-bottom:3px">Away Team</div>
                    <input class="inp" style="font-size:12px;padding:6px 10px" id="sel_away_${s.id}" value="${esc(s.away_team)}">
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 90px 80px 80px 80px;gap:8px;margin-bottom:10px">
                <div>
                    <div style="font-size:9px;color:var(--dim);font-weight:800;text-transform:uppercase;margin-bottom:3px">Market</div>
                    <input class="inp" style="font-size:12px;padding:6px 10px" id="sel_market_${s.id}" value="${esc(s.market)}">
                </div>
                <div>
                    <div style="font-size:9px;color:var(--dim);font-weight:800;text-transform:uppercase;margin-bottom:3px">Pick</div>
                    <input class="inp" style="font-size:12px;padding:6px 10px;font-weight:900;color:var(--acc)" id="sel_pick_${s.id}" value="${esc(s.pick)}">
                </div>
                <div>
                    <div style="font-size:9px;color:var(--dim);font-weight:800;text-transform:uppercase;margin-bottom:3px">Odds</div>
                    <input class="inp" type="number" step="0.01" style="font-size:12px;padding:6px 10px" id="sel_odds_${s.id}" value="${parseFloat(s.odds).toFixed(2)}">
                </div>
                <div>
                    <div style="font-size:9px;color:var(--dim);font-weight:800;text-transform:uppercase;margin-bottom:3px">Time</div>
                    <input class="inp" style="font-size:12px;padding:6px 10px" id="sel_time_${s.id}" value="${esc(s.match_time)}">
                </div>
                <div>
                    <div style="font-size:9px;color:var(--dim);font-weight:800;text-transform:uppercase;margin-bottom:3px">FT Score</div>
                    <input class="inp" style="font-size:12px;padding:6px 10px;font-weight:900;text-align:center;letter-spacing:1px"
                        id="sel_score_${s.id}" value="${esc(s.score || '')}" placeholder="e.g. 2-1">
                </div>
            </div>
            <input type="hidden" id="sel_matchid_${s.id}" value="${s.admin_match_id || ''}">
            <div style="display:flex;align-items:center;justify-content:space-between">
                <div style="display:flex;align-items:center;gap:8px">
                    <span id="selstate_${s.id}" style="font-size:11px;font-weight:700;color:${s.hidden ? '#f97316' : 'var(--acc)'}">${s.hidden ? '🙈 Hidden' : '👁 Shown'}</span>
                    <button class="btn btn-ghost btn-sm" id="selbtn_${s.id}" onclick="toggleSelHide(${s.id}, ${s.hidden ? 0 : 1})">${s.hidden ? 'Unhide' : 'Hide'}</button>
                </div>
                <button class="btn btn-acc btn-sm" onclick="saveSelRow(${s.id})">💾 Save</button>
            </div>
        </div>`;
                })
            ].join('');

            if (containerId === 'si-sel-list') {
                document.getElementById('si-post-import').style.display = 'block';
            }
        }

        async function saveAllSelRows(codeId, containerId) {
            // Collect all sel IDs in the container
            const container = document.getElementById(containerId);
            const rows = container.querySelectorAll('[id^="selrow_"]');
            let saved = 0, failed = 0;
            for (const row of rows) {
                const selId = row.id.replace('selrow_', '');
                const ok = await saveSelRow(parseInt(selId), true);
                ok ? saved++ : failed++;
            }
            toast(failed ? `Saved ${saved}, ${failed} failed` : `✓ All ${saved} selections saved!`, failed > 0);
        }

        async function saveSelRow(selId, silent = false) {
            const get = id => document.getElementById(id)?.value ?? '';
            const scoreVal = get('sel_score_' + selId);
            const payload = {
                action: 'update_selection',
                sel_id: selId,
                home_team: get('sel_home_' + selId),
                away_team: get('sel_away_' + selId),
                market: get('sel_market_' + selId),
                pick: get('sel_pick_' + selId),
                odds: get('sel_odds_' + selId),
                match_time: get('sel_time_' + selId),
                score: scoreVal,
            };
            // If linked admin match + score entered, also update admin_matches score
            const matchIdEl = document.getElementById('sel_matchid_' + selId);
            if (matchIdEl && matchIdEl.value && scoreVal && scoreVal.includes('-')) {
                const [sh, sa] = scoreVal.split('-').map(v => parseInt(v.trim()));
                if (!isNaN(sh) && !isNaN(sa)) {
                    fetch('api_admin_matches.php', {
                        method: 'POST', body: new URLSearchParams({
                            action: 'update', id: matchIdEl.value,
                            score_home: sh, score_away: sa
                        })
                    }).catch(() => { });
                }
            }
            const d = await fetch('api_admin_booking.php', { method: 'POST', body: new URLSearchParams(payload) }).then(r => r.json());
            if (d.success) {
                if (!silent) {
                    const btn = document.querySelector(`#selrow_${selId} .btn-acc`);
                    if (btn) { const old = btn.textContent; btn.textContent = '✓'; btn.style.background = '#00cc66'; setTimeout(() => { btn.textContent = old; btn.style.background = ''; }, 1200); }
                }
                return true;
            } else {
                if (!silent) toast(d.message || 'Save failed', true);
                return false;
            }
        }

        async function revealCode(id) {
            const d = await fetch('api_admin_booking.php', { method: 'POST', body: new URLSearchParams({ action: 'reveal', id }) }).then(r => r.json());
            if (d.success) { toast('Code revealed!'); loadCodes(); } else toast(d.message, true);
        }
        async function deleteCode(id) {
            if (!confirm('Delete this code?')) return;
            const d = await fetch('api_admin_booking.php', { method: 'POST', body: new URLSearchParams({ action: 'delete', id }) }).then(r => r.json());
            if (d.success) { toast('Deleted'); loadCodes(); } else toast(d.message, true);
        }

        // ── Share a booking code link ─────────────────────────────────────────────────
        function shareCode(code) {
            const link = window.location.origin
                + window.location.pathname.replace('admin.php', 'dashboard.php')
                + '?load_code=' + encodeURIComponent(code);
            if (navigator.share) {
                navigator.share({ title: 'Booking Code ' + code, text: 'Load this bet: ' + code, url: link })
                    .catch(() => copyToClipboard(link, code));
            } else {
                copyToClipboard(link, code);
            }
        }
        function copyToClipboard(text, code) {
            navigator.clipboard.writeText(text)
                .then(() => toast('📋 Link copied! Share it so users can load code ' + code))
                .catch(() => {
                    // Fallback for older browsers
                    const ta = document.createElement('textarea');
                    ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
                    document.body.appendChild(ta); ta.select(); document.execCommand('copy');
                    document.body.removeChild(ta);
                    toast('📋 Link copied for code ' + code);
                });
        }

        // ── Settle panel: show ticket stats + Won/Lost/Void buttons ──────────────────
        async function openSettlePanel(code) {
            const panel = document.getElementById('code-editor-panel');
            panel.innerHTML = `<div class="card" style="border-color:rgba(245,158,11,.3)">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
            <div style="font-size:13px;font-weight:900;color:#f59e0b">🏆 Settle Code: ${code}</div>
            <button class="btn btn-ghost btn-sm" onclick="document.getElementById('code-editor-panel').innerHTML=''">✕ Close</button>
        </div>
        <div id="settle-stats-${code}" style="color:var(--dim);font-size:13px">Loading tickets…</div>
    </div>`;
            panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

            const d = await fetch('api_admin_booking.php?action=code_tickets&code=' + code).then(r => r.json());
            const statsEl = document.getElementById('settle-stats-' + code);
            if (!d.success) { statsEl.textContent = 'Failed to load tickets.'; return; }

            if (!d.count) {
                statsEl.innerHTML = `<p style="margin:0;font-size:13px;font-weight:700;color:var(--dim)">
            No tickets have been placed using code <strong style="color:var(--acc)">${code}</strong> yet.
        </p>
        <p style="margin:8px 0 0;font-size:11px;color:var(--dim)">Once users load this code and place bets, you can settle them here.</p>`;
                return;
            }

            const rows = d.tickets.map(t => `
        <tr>
            <td style="font-family:monospace;color:var(--dim)">#${t.ticket_code}</td>
            <td><span style="font-family:monospace;font-weight:800;color:var(--acc);letter-spacing:1px">${t.booking_code || '—'}</span></td>
            <td style="font-weight:700">${t.username}</td>
            <td>GHS ${parseFloat(t.stake_amount).toFixed(2)}</td>
            <td style="color:#f59e0b;font-style:italic">GHS ${parseFloat(t.potential_win).toFixed(2)}</td>
            <td><span class="badge ${t.status === 'Running' ? 'b-run' : t.status === 'Won' ? 'b-won' : 'b-lost'}">${t.status}</span></td>
            <td style="font-size:11px;color:var(--dim)">${t.bet_date}</td>
            <td>
                <div style="display:flex;gap:4px;flex-wrap:wrap">
                    <button class="btn btn-acc btn-sm" style="padding:4px 8px;font-size:10px;${t.status === 'Won' ? 'opacity:.4' : ''}"
                        onclick="settleOneUser('${code}',${t.id},'Won')" title="Mark Won">✓W</button>
                    <button class="btn btn-red btn-sm" style="padding:4px 8px;font-size:10px;${t.status === 'Lost' ? 'opacity:.4' : ''}"
                        onclick="settleOneUser('${code}',${t.id},'Lost')" title="Mark Lost">✕L</button>
                    <button class="btn btn-blue btn-sm" style="padding:4px 8px;font-size:10px;${t.status === 'Void' ? 'opacity:.4' : ''}"
                        onclick="settleOneUser('${code}',${t.id},'Void')" title="Void / Refund">↩V</button>
                </div>
            </td>
        </tr>`).join('');

            statsEl.innerHTML = `
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px">
            <div class="stat-card" style="padding:12px">
                <div class="stat-lbl">Total Tickets</div>
                <div class="stat-val" style="font-size:20px">${d.count}</div>
            </div>
            <div class="stat-card" style="padding:12px">
                <div class="stat-lbl">Total Staked</div>
                <div class="stat-val" style="font-size:20px;color:var(--acc)">GHS ${parseFloat(d.total_stake).toFixed(2)}</div>
            </div>
            <div class="stat-card" style="padding:12px">
                <div class="stat-lbl">Running</div>
                <div class="stat-val" style="font-size:20px;color:var(--acc)">${d.tickets.filter(t => t.status === 'Running').length}</div>
            </div>
            <div class="stat-card" style="padding:12px">
                <div class="stat-lbl">Settled</div>
                <div class="stat-val" style="font-size:20px;color:#22c55e">${d.tickets.filter(t => t.status !== 'Running').length}</div>
            </div>
        </div>

        <div style="overflow-x:auto;margin-bottom:14px">
            <table class="tbl">
                <thead><tr><th>Ticket</th><th>Booking Code</th><th>User</th><th>Stake</th><th>Pot. Win</th><th>Status</th><th>Date</th><th>Action</th></tr></thead>
                <tbody>${rows}</tbody>
            </table>
        </div>

        <div style="background:rgba(245,158,11,.07);border:1px solid rgba(245,158,11,.25);border-radius:12px;padding:14px">
            <p style="font-size:12px;font-weight:800;color:#f59e0b;margin:0 0 6px;text-transform:uppercase;letter-spacing:.06em">
                Force-Settle ALL ${d.count} Ticket(s) for Code ${code}
            </p>
            <p style="font-size:11px;color:var(--dim);margin:0 0 12px">
                This overrides any current status — balance adjustments are applied automatically.
                <br>• <strong style="color:var(--acc)">Won</strong> → credits potential win (reverses if was Lost/Void first)<br>
                • <strong style="color:#ef4444">Lost</strong> → no payout (reverses any previous Won credit)<br>
                • <strong style="color:var(--acc)">Void</strong> → refunds stake to each user
            </p>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <button class="btn btn-acc" onclick="settleCode('${code}','Won')">✓ All Won</button>
                <button class="btn btn-red" onclick="settleCode('${code}','Lost')">✕ All Lost</button>
                <button class="btn btn-blue" onclick="settleCode('${code}','Void')">↩ All Void</button>
            </div>
        </div>
    `;
        }

        async function settleOneUser(code, ticketId, outcome) {
            if (!confirm(`Mark ticket #${ticketId} as ${outcome}?`)) return;
            // Settle just this one ticket via api_admin_settle.php
            const d = await api('api_admin_settle.php', { action: 'settle', ticket_id: ticketId, outcome });
            if (d.success) {
                toast(`Ticket #${ticketId} → ${outcome}`);
                openSettlePanel(code); // reload panel
            } else {
                toast(d.message || 'Error', true);
            }
        }

        async function settleCode(code, outcome) {
            const running = document.querySelectorAll && document.querySelectorAll
                ? (document.getElementById('code-editor-panel')?.querySelectorAll('.b-run')?.length || '?')
                : '?';
            if (!confirm(`Settle ALL running tickets for code "${code}" as ${outcome}?\n\nThis will update balances for all affected users and cannot be undone.`)) return;

            const d = await fetch('api_admin_booking.php', {
                method: 'POST',
                body: new URLSearchParams({ action: 'settle_code', code, outcome })
            }).then(r => r.json());

            if (d.success) {
                toast(`✓ ${d.message}`);
                loadCodes(); // refresh the codes list so status badge updates
                document.getElementById('code-editor-panel').innerHTML = '';
            } else {
                toast(d.message || 'Settle failed', true);
            }
        }

        // ══════════ TICKETS ══════════════════════════════════════════════════
        async function loadTickets(status = 'Running', el = null) {
            if (el) { document.querySelectorAll('#panel-tickets .inner-tab').forEach(t => t.classList.remove('active')); el.classList.add('active'); }
            const d = await api('api_admin_settle.php', { action: 'get_tickets', status, page: 1 });
            if (!d.success) return;
            const container = document.getElementById('tickets-table');
            if (!d.tickets.length) { container.innerHTML = '<div class="card" style="text-align:center;color:var(--dim);padding:30px">No tickets found.</div>'; return; }
            container.innerHTML = `<div class="card">
        <div class="card-title">${status || 'All'} Tickets (${d.total})</div>
        <div style="overflow-x:auto">
        <table class="tbl">
            <thead><tr><th>Ticket</th><th>Booking Code</th><th>User</th><th>Stake</th><th>Pot. Win</th><th>Matches</th><th>Date</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>${d.tickets.map(t => `<tr>
                <td style="font-family:monospace;color:var(--dim)">#${t.ticket_code}</td>
                <td><span style="font-family:monospace;font-weight:800;color:var(--acc);letter-spacing:1px">${t.booking_code || '—'}</span></td>
                <td style="font-weight:700">${t.username}</td>
                <td>GHS ${parseFloat(t.stake_amount).toFixed(2)}</td>
                <td style="color:#fbbf24;font-weight:800;font-style:italic">GHS ${parseFloat(t.potential_win).toFixed(2)}</td>
                <td style="font-size:11px;color:var(--dim)">${(t.matches || []).map(m => `${m.home_team} vs ${m.away_team} (${m.selection_picked} @ ${m.odds})`).join('<br>')}</td>
                <td style="font-size:11px;color:var(--dim)">${t.bet_date}</td>
                <td><span class="badge b-${t.status.toLowerCase()}">${t.status}</span></td>
                <td style="display:flex;gap:4px;flex-wrap:wrap">
                    <button class="btn btn-acc btn-sm" style="${t.status === 'Won' ? 'opacity:.35' : ''}"
                        onclick="settle(${t.id},'Won')" title="Mark Won">✓W</button>
                    <button class="btn btn-red btn-sm" style="${t.status === 'Lost' ? 'opacity:.35' : ''}"
                        onclick="settle(${t.id},'Lost')" title="Mark Lost">✕L</button>
                    <button class="btn btn-ghost btn-sm" style="${t.status === 'Void' ? 'opacity:.35' : ''}"
                        onclick="settle(${t.id},'Void')" title="Void / Refund">↩V</button>
                    ${t.booking_code ? `<button class="btn btn-blue btn-sm"
                        onclick="goto('codes',document.querySelector('.nav-i:nth-child(3)'));setTimeout(()=>openSettlePanel('${t.booking_code}'),400)"
                        title="Open code settle panel">📋</button>`: ''}
                </td>
            </tr>`).join('')}
            </tbody>
        </table>
        </div>
    </div>`;
        }

        async function settle(id, outcome) {
            if (!confirm(`Force-set ticket #${id} to ${outcome}?\n\nBalance will be adjusted automatically.`)) return;
            const d = await api('api_admin_settle.php', { action: 'settle', ticket_id: id, outcome });
            if (d.success) {
                const credit = outcome === 'Won' && parseFloat(d.amount_credited || 0) > 0
                    ? ' — GHS ' + parseFloat(d.amount_credited).toFixed(2) + ' credited' : '';
                toast(`Ticket #${id} → ${outcome}${credit}`);
                loadTickets(document.querySelector('#panel-tickets .inner-tab.active')?.dataset?.status || 'Running');
            } else toast(d.message || 'Error', true);
        }

        async function runAutoSettle(silent = false) {
            const btn = document.getElementById('auto-settle-btn');
            if (btn && !silent) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Checking…';
            }
            try {
                const res = await fetch('api_auto_settle.php?all=1', { credentials: 'same-origin' });
                const d = await res.json();
                if (d.settled > 0) {
                    toast(`✅ Auto-settled ${d.settled} ticket(s)!`);
                    loadTickets('Running');
                } else if (!silent) {
                    toast(`Checked ${d.processed} ticket(s) — none ready to settle yet`);
                }
            } catch (e) {
                if (!silent) toast('Auto-settle error', true);
            }
            if (btn && !silent) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-robot"></i> Auto-Settle Tickets';
            }
        }

        async function closeAllTickets(outcome) {
            const label = outcome === 'Won' ? 'WON (all users credited)' : outcome === 'Void' ? 'VOID (stakes refunded)' : 'LOST (no payout)';
            if (!confirm('Close ALL running tickets as ' + label + '?\n\nThis cannot be undone.')) return;
            const d = await api('api_admin_settle.php', { action: 'close_all_running', outcome });
            if (d.success) {
                toast(d.message || 'Done — ' + d.closed + ' ticket(s) closed');
                loadTickets('Running');
                loadStats();
            } else {
                toast(d.message || 'Error', true);
            }
        }

        // ── Cashout toggle (Ticket Settlement panel) ────────────────────────
        var _cashoutLocked = null; // cached state: null=unknown, 0=enabled, 1=locked

        function renderCashoutToggle(locked) {
            _cashoutLocked = locked;
            const btn = document.getElementById('cashout-toggle-btn');
            const txt = document.getElementById('cashout-status-text');
            if (!btn || !txt) return;
            if (locked) {
                btn.textContent = '⚠️ Enable Cashout';
                btn.style.background = '#22c55e';
                btn.style.color = '#000';
                txt.innerHTML = '<span style="color:#ff3b30;font-weight:700">🚫 Cashout is currently DISABLED</span> — players cannot cash out any running bets.';
            } else {
                btn.textContent = '🔒 Disable Cashout';
                btn.style.background = '#ff3b30';
                btn.style.color = '#fff';
                txt.innerHTML = '<span style="color:#22c55e;font-weight:700">✅ Cashout is currently ENABLED</span> — players can cash out running bets.';
            }
        }

        async function loadCashoutStatus() {
            try {
                const rows = await fetch('admin_settings_get.php').then(r => r.json()).catch(() => ({}));
                renderCashoutToggle(parseInt(rows.cashout_locked || 0) === 1);
            } catch (e) { /* non-fatal */ }
        }

        async function toggleCashoutLock() {
            const newState = _cashoutLocked ? 0 : 1;
            const label = newState ? 'DISABLE cashout for all players?' : 'ENABLE cashout for all players?';
            if (!confirm(label)) return;
            const btn = document.getElementById('cashout-toggle-btn');
            if (btn) { btn.disabled = true; btn.textContent = 'Saving…'; }
            try {
                await api('api_admin_matches.php', {
                    action: 'settings',
                    cashout_locked: newState,
                });
                renderCashoutToggle(newState === 1);
                toast(newState ? '🚫 Cashout disabled for all players' : '✅ Cashout enabled for all players');
            } catch (e) {
                toast('Failed to save', true);
            } finally {
                if (btn) btn.disabled = false;
            }
        }

        async function clearAdminHistory() {
            const typed = prompt('This will permanently clear tickets, transactions, booking codes, casino/game history, notifications and commission history.\\n\\nType CLEAR HISTORY to continue:');
            if (typed !== 'CLEAR HISTORY') {
                if (typed !== null) toast('History clear cancelled', true);
                return;
            }
            const sure = confirm('Final confirmation: clear all admin history records now? Users and balances will remain.');
            if (!sure) return;
            const d = await api('api_admin_settle.php', { action: 'clear_admin_history', confirm: typed });
            if (d.success) {
                const total = Object.values(d.cleared || {}).reduce((sum, n) => sum + parseInt(n || 0), 0);
                toast(`History cleared (${total} record${total === 1 ? '' : 's'})`);
                loadStats();
                const activeTickets = document.querySelector('#panel-tickets .inner-tab.active');
                if (activeTickets) loadTickets(activeTickets.textContent.trim() === 'All' ? '' : activeTickets.textContent.trim());
            } else {
                toast(d.message || 'Could not clear history', true);
            }
        }

        // ══════════ WITHDRAWALS ═══════════════════════════════════════════════
        async function loadWithdrawals(status = 'Pending', el = null) {
            if (el) {
                document.querySelectorAll('#panel-withdrawals .inner-tab').forEach(t => t.classList.remove('active'));
                el.classList.add('active');
            }
            const d = await api('api_admin_settle.php', { action: 'get_withdrawals', status });
            const container = document.getElementById('withdrawals-table');
            if (!d.success) { container.innerHTML = '<div class="card" style="color:#ef4444;padding:20px">Failed to load.</div>'; return; }

            // Load current commission rate into the input
            if (d.default_comm_rate !== undefined) {
                const ri = document.getElementById('comm-rate-input');
                if (ri && ri.dataset.loaded !== '1') { ri.value = d.default_comm_rate; ri.dataset.loaded = '1'; }
            }
            if (d.withdraw_verification_amount_ghs !== undefined) {
                const vi = document.getElementById('verify-amount-ghs-input');
                const loadedVerifyAmount = parseFloat(d.withdraw_verification_amount_ghs);
                if (vi && vi.dataset.loaded !== '1') {
                    vi.value = (Number.isFinite(loadedVerifyAmount) && loadedVerifyAmount > 0 ? loadedVerifyAmount : 300).toFixed(2);
                    vi.dataset.loaded = '1';
                }
            }
            if (d.withdraw_submission_amount_ghs !== undefined) {
                const si = document.getElementById('submission-amount-ghs-input');
                const loadedSubmissionAmount = parseFloat(d.withdraw_submission_amount_ghs);
                if (si && si.dataset.loaded !== '1') {
                    si.value = (Number.isFinite(loadedSubmissionAmount) && loadedSubmissionAmount > 0 ? loadedSubmissionAmount : 1000).toFixed(2);
                    si.dataset.loaded = '1';
                }
            }

            if (!d.withdrawals.length) {
                container.innerHTML = '<div class="card" style="text-align:center;color:var(--dim);padding:30px">No ' + (status || '') + ' withdrawals found.</div>';
                document.getElementById('comm-summary').textContent = '';
                return;
            }

            // Percentage charge summary
            const totalCommUsd = d.withdrawals.reduce((s, w) => s + parseFloat(w.comm_amount_usd || usdValue(parseFloat(w.comm_amount || 0), w.currency || 'GHS')), 0);
            const unpaidCommUsd = d.withdrawals
                .filter(w => !parseInt(w.comm_paid || 0))
                .reduce((s, w) => s + parseFloat(w.comm_amount_usd || usdValue(parseFloat(w.comm_amount || 0), w.currency || 'GHS')), 0);
            const summaryEl = document.getElementById('comm-summary');
            if (summaryEl) summaryEl.innerHTML = `Withdrawal charge: <span style="color:#fbbf24;font-weight:900">$${totalCommUsd.toFixed(2)}</span> total &nbsp;|&nbsp; <span style="color:#f97316">$${unpaidCommUsd.toFixed(2)} unpaid</span>`;

            container.innerHTML = '<div class="card">' +
                '<div class="card-title">' + (status || 'All') + ' Withdrawals (' + d.withdrawals.length + ')</div>' +
                '<div style="overflow-x:auto"><table class="tbl">' +
                '<thead><tr><th>ID</th><th>User</th><th>AML</th><th>Balance</th><th>Amount</th><th>Charge</th><th>Charge Paid</th><th>Method/Phone</th><th>Date</th><th>Status</th><th>Actions</th></tr></thead>' +
                '<tbody>' + d.withdrawals.map(w => {
                    const commAmt = parseFloat(w.comm_amount || 0);
                    const commRate = parseFloat(w.comm_rate || d.default_comm_rate || 15);
                    const commPaid = parseInt(w.comm_paid || 0);
                    const netAmt = parseFloat(w.amount) - commAmt;
                    const amlVerified = parseInt(w.user_is_verified || 0);
                    return '<tr>' +
                        '<td style="color:var(--dim);font-family:monospace">#' + w.id + '</td>' +
                        '<td style="font-weight:700">' + w.username + '</td>' +
                        '<td>' + (amlVerified
                            ? '<span style="color:#22c55e;font-size:11px;font-weight:700">✅ Verified</span>'
                            : '<span style="color:#ef4444;font-size:11px;font-weight:700">⛔ Unverified</span>') + '</td>' +
                        '<td>' + dualFromPayload(w, 'user_balance', 'user_balance_usd') + '</td>' +
                        '<td>' + dualFromPayload(w, 'amount', 'amount_usd') +
                        '<div style="font-size:10px;color:var(--dim)">Net: $' + usdValue(netAmt, w.currency || 'GHS').toFixed(2) + '</div></td>' +
                        '<td><div style="font-weight:700;color:#fbbf24">$' + parseFloat(w.comm_amount_usd || usdValue(commAmt, w.currency || 'GHS')).toFixed(2) + '</div>' +
                        '<div style="font-size:10px;color:var(--dim)">' + commRate.toFixed(2) + '%: ' + localMoney(commAmt, w.currency || 'GHS') + '</div></td>' +
                        '<td>' + (commPaid
                            ? '<span style="color:#22c55e;font-weight:700;font-size:11px">✅ Paid</span>'
                            : '<button class="btn btn-ghost btn-sm" onclick="markCommPaid(' + w.id + ',1)" style="font-size:11px;color:#fbbf24;border-color:#fbbf24">⏳ Mark Paid</button>') + '</td>' +
                        '<td style="font-size:11px;color:var(--dim)">' + (w.tx_reference || w.method || '—') + '</td>' +
                        '<td style="font-size:11px;color:var(--dim)">' + (w.created_at || '') + '</td>' +
                        '<td><span class="badge ' + (w.status === 'Completed' ? 'b-won' : w.status === 'Rejected' ? 'b-lost' : 'b-run') + '">' + w.status + '</span></td>' +
                        '<td style="display:flex;gap:5px;flex-wrap:wrap">' +
                        (w.status === 'Pending'
                            ? '<button class="btn btn-acc btn-sm" onclick="approveWithdrawal(' + w.id + ')">✓ Approve</button>' +
                              '<button class="btn btn-red btn-sm" onclick="rejectWithdrawal(' + w.id + ')">✕ Reject</button>' +
                              (!amlVerified ? '<button class="btn btn-blue btn-sm" onclick="verifyUserAml(' + w.user_id + ',' + w.id + ')" title="Mark AML Verified">🛡 AML✓</button>' : '')
                            : '<span style="color:var(--dim);font-size:11px">Settled</span>') +
                        '</td>' +
                        '</tr>';
                }).join('') +
                '</tbody></table></div></div>';
        }

        async function approveWithdrawal(txId) {
            if (!confirm('Mark this withdrawal as Completed?')) return;
            const d = await api('api_admin_settle.php', { action: 'approve_withdrawal', tx_id: txId });
            if (d.success) { toast('✓ Withdrawal approved'); loadWithdrawals('Pending'); loadStats(); }
            else toast(d.message || 'Error', true);
        }

        async function rejectWithdrawal(txId) {
            if (!confirm('Reject and refund this withdrawal to the user\'s balance?')) return;
            const d = await api('api_admin_settle.php', { action: 'reject_withdrawal', tx_id: txId });
            if (d.success) { toast('↩ Withdrawal rejected & refunded'); loadWithdrawals('Pending'); loadStats(); }
            else toast(d.message || 'Error', true);
        }

        async function markCommPaid(txId, paid) {
            const d = await api('api_admin_settle.php', { action: 'mark_comm_paid', tx_id: txId, paid });
            if (d.success) {
                toast(paid ? '✅ Commission marked as paid' : 'Commission marked unpaid');
                // Refresh current tab
                const activeTab = document.querySelector('#panel-withdrawals .inner-tab.active');
                const status = activeTab ? (activeTab.textContent.trim() === 'All' ? '' : activeTab.textContent.trim()) : 'Pending';
                loadWithdrawals(status);
            } else toast(d.message || 'Error', true);
        }

        // Bug 2 fix: quick AML verification from withdrawals panel
        async function verifyUserAml(userId, txId) {
            if (!confirm('Mark this user as AML-Verified? They will be able to make withdrawals.')) return;
            const d = await api('api_admin_settle.php', { action: 'verify_user_aml', user_id: userId });
            if (d.success) {
                toast('🛡 User AML-verified — withdrawals unlocked');
                const activeTab = document.querySelector('#panel-withdrawals .inner-tab.active');
                const s = activeTab ? (activeTab.textContent.trim() === 'All' ? '' : activeTab.textContent.trim()) : 'Pending';
                loadWithdrawals(s);
            } else toast(d.message || 'Error', true);
        }

        async function saveCommRate() {
            const rate = parseFloat(document.getElementById('comm-rate-input').value);
            const status = document.getElementById('comm-rate-status');
            if (isNaN(rate) || rate < 0 || rate > 100) { toast('Enter a valid percentage from 0 to 100', true); return; }
            const d = await api('api_admin_settle.php', { action: 'save_comm_rate', rate });
            if (d.success) {
                toast('✅ Withdrawal percentage saved: ' + d.rate + '%');
                if (status) { status.textContent = '✓ Saved'; setTimeout(() => { status.textContent = ''; }, 2000); }
                // Reset so next load re-reads from server
                const ri = document.getElementById('comm-rate-input');
                if (ri) ri.dataset.loaded = '0';
                // Reload table with new rate
                const activeTab = document.querySelector('#panel-withdrawals .inner-tab.active');
                const s = activeTab ? (activeTab.textContent.trim() === 'All' ? '' : activeTab.textContent.trim()) : 'Pending';
                loadWithdrawals(s);
            } else toast(d.message || 'Error', true);
        }

        async function saveWithdrawVerifyAmount() {
            const input = document.getElementById('verify-amount-ghs-input');
            const status = document.getElementById('verify-amount-status');
            const amount = parseFloat(input ? input.value : '');
            if (!Number.isFinite(amount) || amount <= 0) { toast('Enter a valid verification amount greater than 0', true); return; }
            const d = await api('api_admin_settle.php', { action: 'save_withdraw_verify_amount', currency: 'GHS', amount });
            if (d.success) {
                if (input) {
                    input.value = parseFloat(d.amount).toFixed(2);
                    input.dataset.loaded = '1';
                }
                toast('Withdrawal verification amount saved: GHS ' + parseFloat(d.amount).toFixed(2));
                if (status) { status.textContent = 'Saved'; setTimeout(() => { status.textContent = ''; }, 2000); }
            } else toast(d.message || 'Error', true);
        }

        async function saveWithdrawSubmissionAmount() {
            const input = document.getElementById('submission-amount-ghs-input');
            const status = document.getElementById('submission-amount-status');
            const amount = parseFloat(input ? input.value : '');
            if (!Number.isFinite(amount) || amount <= 0) { toast('Enter a valid email submission amount greater than 0', true); return; }
            const d = await api('api_admin_settle.php', { action: 'save_withdraw_submission_amount', currency: 'GHS', amount });
            if (d.success) {
                if (input) {
                    input.value = parseFloat(d.amount).toFixed(2);
                    input.dataset.loaded = '1';
                }
                toast('Withdrawal email amount saved: GHS ' + parseFloat(d.amount).toFixed(2));
                if (status) { status.textContent = 'Saved'; setTimeout(() => { status.textContent = ''; }, 2000); }
            } else toast(d.message || 'Error', true);
        }

        // ══════════ USERS ═════════════════════════════════════════════════════
        // ══ USER DATA STORE ═══════════════════════════════════════════
        let _allUsers = [];
        let _allAgents = [];

        async function loadUsers() {
            const d = await api('api_admin_settle.php', { action: 'get_users' });
            if (!d.success) return toast('Failed to load users', true);
            _allUsers = d.users;
            _allAgents = d.agents || [];
            // sync admin IDs from settings
            const sets = await fetch('admin_settings_get.php').then(r => r.json()).catch(() => ({ admins: '1' }));
            _allUsers._adminIds = (sets.admins || '1').split(',').map(x => x.trim());
            renderUsersTable(_allUsers);
        }

        let _filterUsersTimer = null;
        function filterUsers() {
            clearTimeout(_filterUsersTimer);
            _filterUsersTimer = setTimeout(_doFilterUsers, 280);
        }
        function _doFilterUsers() {
            const q = (document.getElementById('user-search')?.value || '').toLowerCase();
            const status = document.getElementById('user-filter-status')?.value || '';
            const adminIds = _allUsers._adminIds || [];
            let list = _allUsers.filter(u => {
                const matchQ = !q || String(u.username || '').toLowerCase().includes(q) || (u.email || '').toLowerCase().includes(q) || (u.agent_email || '').toLowerCase().includes(q) || (u.agent_username || '').toLowerCase().includes(q) || String(u.id).includes(q);
                let matchS = true;
                if (status === 'active') matchS = !u.is_banned;
                if (status === 'banned') matchS = !!u.is_banned;
                if (status === 'admin') matchS = adminIds.includes(String(u.id));
                if (status === 'unverified') matchS = parseInt(u.is_verified || 0) !== 1;
                return matchQ && matchS;
            });
            renderUsersTable(list);
        }

        function renderUsersTable(list) {
            const PAGE = 50;
            const total = list.length;
            const adminIds = _allUsers._adminIds || [];
            const display = list.slice(0, PAGE);
            const agentOptionsFor = (currentId) => {
                const cur = String(currentId || '');
                return `<option value="" ${cur === '' || cur === '0' ? 'selected' : ''}>No agent</option>` +
                    (_allAgents || []).map(a => {
                        const label = `${a.email || a.username || ('Agent #' + a.id)}${a.referral_code ? ' · ' + a.referral_code : ''}`;
                        return `<option value="${parseInt(a.id)}" ${String(a.id) === cur ? 'selected' : ''}>${agentEsc(label)}</option>`;
                    }).join('');
            };
            document.getElementById('users-table').innerHTML = `
    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
            <div class="card-title" style="margin:0">ALL USERS <span style="color:var(--dim);font-weight:600">(${total})</span></div>
            <button class="btn btn-red btn-sm" onclick="bulkAction()" style="font-size:11px">Bulk Actions</button>
        </div>
        <div style="overflow-x:auto">
        <table class="tbl">
            <thead><tr>
                <th><input type="checkbox" id="chk-all" onchange="toggleAllChk(this)"></th>
                <th>ID</th><th>User</th><th>Agent Link</th><th>Email</th><th>Balance</th>
	                <th>Status</th><th>Joined</th><th style="min-width:180px">Actions</th>
            </tr></thead>
            <tbody>${display.map(u => {
                const isAdmin = adminIds.includes(String(u.id));
                const isBanned = !!u.is_banned;
                const isVerified = parseInt(u.is_verified || 0) === 1;
                const userName = agentEsc(u.username || '');
                const userNameArg = String(u.username || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'");
                const userNameArgHtml = agentEsc(userNameArg);
                const agentEmail = String(u.agent_email || '').trim();
                return `<tr id="urow-${u.id}">
                    <td><input type="checkbox" class="u-chk" value="${u.id}"></td>
                    <td style="color:var(--dim);font-size:11px">#${u.id}</td>
                    <td>
                        <div style="font-weight:700;display:flex;align-items:center;gap:6px">
                            ${userName}
                            ${isAdmin ? '<span style="background:rgba(239,68,68,.15);color:var(--acc);border:1px solid rgba(239,68,68,.3);padding:1px 6px;border-radius:4px;font-size:9px;font-weight:800">ADMIN</span>' : ''}
                            ${isBanned ? '<span style="background:rgba(239,68,68,.15);color:#ef4444;border:1px solid rgba(239,68,68,.3);padding:1px 6px;border-radius:4px;font-size:9px;font-weight:800">BANNED</span>' : ''}
                        </div>
                        ${agentEmail ? `<div style="font-size:10px;color:#ef4444;font-weight:800;margin-top:2px">${agentEsc(agentEmail)}</div>` : ''}
                        <div style="font-size:10px;color:var(--dim)">Tier ${u.loyalty_tier || 1}</div>
                    </td>
                    <td>
                        <div class="user-agent-link">
                            <select class="inp user-agent-select" id="user-agent-${u.id}">
                                ${agentOptionsFor(u.agent_id)}
                            </select>
                            <button class="btn btn-acc user-action-btn" title="Link user to selected agent" onclick="linkUserAgent(${u.id})">Link</button>
                        </div>
                    </td>
                    <td style="font-size:11px;color:var(--dim)">${agentEsc(u.email || '—')}</td>
                    <td>${dualFromPayload(u, 'balance', 'balance_usd')}</td>
                    <td>
                        <span style="padding:2px 8px;border-radius:5px;font-size:10px;font-weight:800;
                            background:${isBanned ? 'rgba(239,68,68,.1)' : 'rgba(52,199,89,.1)'};
                            color:${isBanned ? '#ef4444' : '#34c759'};
                            border:1px solid ${isBanned ? 'rgba(239,68,68,.25)' : 'rgba(52,199,89,.25)'}">
                            ${isBanned ? 'Banned' : (isVerified ? 'Active' : 'Unverified')}
                        </span>
                    </td>
                    <td style="font-size:11px;color:var(--dim)">${(u.created_at || '').slice(0, 10)}</td>
	                    <td>
	                        <div class="user-actions">
	                            <button class="btn btn-acc user-action-btn user-action-icon" title="Edit user" onclick="openUserModal(${u.id})"><i class="fa fa-pen"></i></button>
	                            <button class="btn btn-acc user-action-btn" title="Add Credit as completed deposit" onclick="addCredit(${u.id},'${userNameArgHtml}',${u.balance || 0},'${u.currency || 'GHS'}')"><i class="fa fa-plus"></i> Credit</button>
	                            <button class="btn user-action-btn" title="Debit / deduct funds from user and reverse agent commission" onclick="debitUser(${u.id},'${userNameArgHtml}',${u.balance || 0},'${u.currency || 'GHS'}')" style="background:rgba(239,68,68,.15);border:1px solid rgba(239,68,68,.35);color:#ef4444"><i class="fa fa-minus"></i> Debit</button>
	                            <button class="btn btn-blue user-action-btn user-action-icon" title="Fund / Deduct" onclick="quickFund(${u.id},'${userNameArgHtml}',${u.balance || 0})"><i class="fa fa-wallet"></i></button>
                            <button class="btn ${isBanned ? 'btn-acc' : 'btn-red'} user-action-btn user-action-icon" title="${isBanned ? 'Unban' : 'Ban'} user" onclick="toggleBan(${u.id},'${userNameArgHtml}',${isBanned ? 1 : 0})">
                                <i class="fa fa-${isBanned ? 'unlock' : 'ban'}"></i>
                            </button>
                            <button class="btn btn-ghost user-action-btn user-action-icon" title="Delete user" onclick="deleteUser(${u.id},'${userNameArgHtml}')"><i class="fa fa-trash" style="color:#ef4444"></i></button>
                        </div>
                    </td>
                </tr>`;
            }).join('')}
            </tbody>
        </table>
        </div>
        ${total > PAGE ? `<div style="margin-top:10px;padding:10px 14px;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);border-radius:10px;font-size:12px;color:var(--dim);text-align:center">Showing first ${PAGE} of ${total} users — refine your search to narrow results</div>` : ''}
    </div>`;
        }

        function toggleAllChk(master) {
            document.querySelectorAll('.u-chk').forEach(c => c.checked = master.checked);
        }

        async function linkUserAgent(userId) {
            const user = _allUsers.find(x => String(x.id) === String(userId));
            const sel = document.getElementById('user-agent-' + userId);
            if (!user || !sel) return;

            const agentId = parseInt(sel.value || '0');
            const agent = (_allAgents || []).find(a => parseInt(a.id) === agentId);
            const targetLabel = agent ? (agent.email || agent.username || ('Agent #' + agent.id)) : 'no agent';
            const actionText = agentId > 0 ? `Link ${user.username} to ${targetLabel}?` : `Remove agent link from ${user.username}?`;
            if (!confirm(`${actionText}\n\nUnpaid commission records for this user's completed deposits will be rebuilt for the selected agent.`)) return;

            const d = await api('api_admin_settle.php', {
                action: 'link_user_agent',
                user_id: userId,
                agent_id: agentId
            });

            if (d.success) {
                const stats = d.stats || {};
                const inserted = parseInt(stats.inserted || 0);
                const moved = parseInt(stats.moved_unpaid_commissions || 0);
                toast(`${d.message || 'Agent link saved'} Added: ${inserted}, moved: ${moved}`);
                loadUsers();
                loadAgents();
                loadStats();
            } else {
                toast(d.message || 'Could not link agent', true);
            }
        }

        async function openUserModal(id) {
            const u = _allUsers.find(x => x.id == id);
            if (!u) return;
            const adminIds = _allUsers._adminIds || [];
            const isAdmin = adminIds.includes(String(u.id));

            document.getElementById('modal-title').textContent = 'Edit User #' + u.id;
            document.getElementById('modal-body').innerHTML = `
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div class="inp-group" style="grid-column:1/-1">
                <label>Username</label>
                <input class="inp" id="eu-username" value="${u.username || ''}" placeholder="Username">
            </div>
            <div class="inp-group">
                <label>Email</label>
                <input class="inp" id="eu-email" type="email" value="${u.email || ''}" placeholder="Email">
            </div>
            <div class="inp-group">
                <label>Phone</label>
                <input class="inp" id="eu-phone" value="${u.phone || ''}" placeholder="Phone number">
            </div>
            <div class="inp-group">
                <label>Balance (${u.currency || 'local'})</label>
                <input class="inp" id="eu-balance" type="number" step="0.01" value="${parseFloat(u.balance || 0).toFixed(2)}">
                <div style="font-size:10px;color:var(--dim);margin-top:4px">USD view: ${usdFromPayload(u, 'balance', 'balance_usd')}</div>
            </div>
            <div class="inp-group">
                <label>Bonus Balance (${u.currency || 'local'})</label>
                <input class="inp" id="eu-bonus" type="number" step="0.01" value="${parseFloat(u.bonus_balance || 0).toFixed(2)}">
                <div style="font-size:10px;color:var(--dim);margin-top:4px">USD view: ${usdFromPayload(u, 'bonus_balance', 'bonus_balance_usd')}</div>
            </div>
            <div class="inp-group">
                <label>Loyalty Tier (1–5)</label>
                <select class="inp" id="eu-tier">
                    ${[1, 2, 3, 4, 5].map(t => `<option value="${t}"${(u.loyalty_tier || 1) == t ? ' selected' : ''}>${t} – ${['', 'Iron', 'Bronze', 'Silver', 'Gold', 'Diamond'][t]}</option>`).join('')}
                </select>
            </div>
            <div class="inp-group">
                <label>Country</label>
                <input class="inp" id="eu-country" value="${u.country || 'Ghana'}" placeholder="Country">
            </div>
            <div class="inp-group" style="grid-column:1/-1">
                <label>New Password <span style="color:var(--dim);font-weight:500;text-transform:none">(leave blank to keep current)</span></label>
                <input class="inp" id="eu-password" type="password" placeholder="Enter new password to change">
            </div>
        </div>

        <div style="display:flex;flex-direction:column;gap:10px;margin:16px 0;padding:14px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.07);border-radius:12px">
            <label style="font-size:11px;font-weight:800;color:var(--dim);text-transform:uppercase;letter-spacing:.06em;margin:0">User Flags</label>
            <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:13px;font-weight:600">
                <input type="checkbox" id="eu-verified" ${(parseInt(u.is_verified || 0) === 1 || parseInt(u.aml_verified || 0) === 1) ? 'checked' : ''} style="width:16px;height:16px;accent-color:var(--acc)"> Mark as Verified (Login + Withdrawals)
            </label>
            <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:13px;font-weight:600">
                <input type="checkbox" id="eu-banned" ${parseInt(u.is_banned || 0) === 1 ? 'checked' : ''} style="width:16px;height:16px;accent-color:#ef4444"> Banned
            </label>
            <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:13px;font-weight:600">
                <input type="checkbox" id="eu-admin" ${isAdmin ? 'checked' : ''} style="width:16px;height:16px;accent-color:var(--acc)"> Admin Access
            </label>
            <div class="inp-group" style="margin-top:8px">
                <label>Account Status</label>
                <select class="inp" id="eu-status">
                    <option value="active" ${u.status === 'active' || !u.status ? 'selected' : ''}>Active</option>
                    <option value="warning" ${u.status === 'warning' ? 'selected' : ''}>Warning</option>
                    <option value="restricted" ${u.status === 'restricted' ? 'selected' : ''}>Restricted</option>
                </select>
            </div>
            <div class="inp-group">
                <label>Warning Message (shown on login if Warning status)</label>
                <textarea class="inp" id="eu-warning" rows="2" placeholder="Enter warning reason...">${u.warning_msg || ''}</textarea>
            </div>
        </div>

        <div style="display:flex;gap:10px;margin-top:4px">
            <button class="btn btn-acc" style="flex:1" onclick="saveUserEdit(${u.id})"><i class="fa fa-save"></i> Save Changes</button>
            <button class="btn btn-red btn-sm" onclick="if(confirm('Delete ${u.username} permanently?'))deleteUser(${u.id},'${u.username}')"><i class="fa fa-trash"></i> Delete</button>
        </div>
        <div id="modal-msg" style="margin-top:10px;font-size:12px;text-align:center"></div>
    `;

            document.getElementById('user-modal-overlay').style.display = 'block';
            document.getElementById('user-modal').style.display = 'block';
        }

        function closeUserModal() {
            document.getElementById('user-modal-overlay').style.display = 'none';
            document.getElementById('user-modal').style.display = 'none';
        }

        async function saveUserEdit(id) {
            const payload = {
                action: 'edit_user',
                user_id: id,
                username: document.getElementById('eu-username').value.trim(),
                email: document.getElementById('eu-email').value.trim(),
                phone: document.getElementById('eu-phone').value.trim(),
                balance: parseFloat(document.getElementById('eu-balance').value),
                bonus: parseFloat(document.getElementById('eu-bonus').value || 0),
                tier: parseInt(document.getElementById('eu-tier').value),
                country: document.getElementById('eu-country').value.trim(),
                password: document.getElementById('eu-password').value,
                verified: document.getElementById('eu-verified').checked ? 1 : 0,
                aml_verified: document.getElementById('eu-verified').checked ? 1 : 0,
                banned: document.getElementById('eu-banned').checked ? 1 : 0,
                make_admin: document.getElementById('eu-admin').checked ? 1 : 0,
                status: document.getElementById('eu-status').value,
                warning_msg: document.getElementById('eu-warning').value.trim(),
            };
            const d = await api('api_admin_settle.php', payload);
            const msg = document.getElementById('modal-msg');
            if (d.success) {
                msg.style.color = 'var(--acc)';
                msg.textContent = '✓ Saved!';
                setTimeout(() => { closeUserModal(); loadUsers(); }, 900);
            } else {
                msg.style.color = '#ef4444';
                msg.textContent = d.message || 'Error saving';
            }
        }

	        async function quickFund(id, name, bal) {
	            const amt = parseFloat(prompt(`Fund (+) or Deduct (-) the user's local wallet for ${name}\nCurrent local balance: ${parseFloat(bal).toFixed(2)}\n\nPositive = add, Negative = deduct`, ''));
	            if (isNaN(amt)) return;
	            const d = await api('api_admin_settle.php', { action: 'fund_user', user_id: id, amount: amt });
	            if (d.success) { toast(`${name}: local balance updated to ${parseFloat(d.new_balance).toFixed(2)}`); loadUsers(); loadStats(); loadAgents(); }
	            else toast(d.message, true);
	        }

	        async function addCredit(id, name, bal, currency) {
	            const amt = parseFloat(prompt(`Add credit for ${name}\nThis records a completed deposit and credits the linked agent/subadmin commission.\n\nCurrent ${currency || 'local'} balance: ${parseFloat(bal).toFixed(2)}\nAmount to add:`, ''));
	            if (isNaN(amt) || amt <= 0) return toast('Enter an amount greater than zero', true);
	            const note = prompt('Optional note/reference for this manual credit:', 'Manual admin credit') || 'Manual admin credit';
	            if (!confirm(`Credit ${currency || 'local'} ${amt.toFixed(2)} to ${name}?\n\nThis will also record commission for the agent/subadmin linked to this user, if any.`)) return;
	            const d = await api('api_admin_settle.php', { action: 'add_credit', user_id: id, amount: amt, note });
	            if (d.success) {
	                toast(d.message || `${name}: credit added`);
	                loadUsers();
	                loadStats();
	                loadAgents();
	            } else {
	                toast(d.message || 'Credit failed', true);
	            }
	        }

	        async function debitUser(id, name, bal, currency) {
	            const amt = parseFloat(prompt(`Debit (deduct) funds from ${name}\nCurrent ${currency || 'local'} balance: ${parseFloat(bal).toFixed(2)}\n\nThis will also reverse the agent commission proportionally.\nAmount to deduct:`, ''));
	            if (isNaN(amt) || amt <= 0) return toast('Enter an amount greater than zero', true);
	            const note = prompt('Optional note/reason for this debit:', 'Manual admin debit') || 'Manual admin debit';
	            if (!confirm(`Deduct ${currency || 'local'} ${amt.toFixed(2)} from ${name}?\n\nThis will also reverse agent commission for this amount.\n\nBalance cannot go below 0.`)) return;
	            const d = await api('api_admin_settle.php', { action: 'add_debit', user_id: id, amount: amt, note });
	            if (d.success) {
	                toast(d.message || `${name}: debit applied`);
	                loadUsers();
	                loadStats();
	            } else {
	                toast(d.message || 'Debit failed', true);
	            }
	        }

        function closeAgentPaymentModal() {
            document.getElementById('agent-payment-modal-overlay').style.display = 'none';
            document.getElementById('agent-payment-modal').style.display = 'none';
        }

        async function openAgentDailyPayments(agentId, agentName) {
            document.getElementById('agent-payment-modal-title').textContent = agentName + ' - Income History';
            const body = document.getElementById('agent-payment-modal-body');
            body.innerHTML = '<div style="color:var(--dim);font-size:13px;text-align:center;padding:20px">Loading history...</div>';
            document.getElementById('agent-payment-modal-overlay').style.display = 'block';
            document.getElementById('agent-payment-modal').style.display = 'block';

            try {
                const dailyRes = await agentApi({ action: 'get_agent_daily_payments', agent_id: agentId });
                if (!dailyRes.success) {
                    body.innerHTML = `<div style="color:#ef4444;font-size:13px;text-align:center">${agentEsc(dailyRes.message || 'Error loading records')}</div>`;
                    return;
                }

                const dailyRows = dailyRes.records || [];
                const agent = dailyRes.agent || {};
                const payoutName = agent.payout_name || 'Not set';
                const payoutNetwork = agent.payout_network || 'Not set';
                const payoutNumber = agent.payout_number || 'Not set';
                const refCode = agent.referral_code || '—';
                const agentPct = parseFloat(agent.commission_pct || 0).toFixed(2);
                const totalIncomeUsd = dailyRows.reduce((sum, r) => sum + (parseFloat(r.total_deposit_usd || 0) || 0), 0);
                const totalCommissionUsd = dailyRows.reduce((sum, r) => sum + (parseFloat(r.total_commission_usd || 0) || 0), 0);
                const totalAdminUsd = dailyRows.reduce((sum, r) => sum + (parseFloat(r.admin_income_usd || 0) || 0), 0);
                const totalTx = dailyRows.reduce((sum, r) => sum + (parseInt(r.tx_count || 0) || 0), 0);
                let html = `<div style="display:grid;gap:12px">`;

                html += `<section style="border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:12px 14px;background:rgba(255,255,255,.03)">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap">
                        <div style="min-width:0">
                            <div style="font-size:14px;font-weight:900;color:var(--acc);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${agentEsc(agentName)}</div>
                            <div style="font-size:11px;color:var(--dim);margin-top:2px">Ref Code: ${agentEsc(refCode)} | Commission: ${agentPct}% | Transactions: ${totalTx}</div>
                        </div>
                        <div style="text-align:right;font-size:12px;line-height:1.35">
                            <div style="font-weight:800;color:#fbbf24">Payout Details</div>
                            <div style="color:var(--text)">${agentEsc(payoutName)}</div>
                            <div style="color:var(--text)">${agentEsc(payoutNetwork)}</div>
                            <div style="color:var(--dim)">${agentEsc(payoutNumber)}</div>
                        </div>
                    </div>
                </section>`;

                html += `<section style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px">
                    <div class="stat-card"><div class="stat-lbl">Total Income</div><div class="stat-val" style="font-size:18px">${'$' + totalIncomeUsd.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</div></div>
                    <div class="stat-card"><div class="stat-lbl">Agent Commission</div><div class="stat-val" style="font-size:18px;color:var(--acc)">${'$' + totalCommissionUsd.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</div></div>
                    <div class="stat-card"><div class="stat-lbl">Admin Net</div><div class="stat-val" style="font-size:18px;color:#34d399">${'$' + totalAdminUsd.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</div></div>
                    <div class="stat-card"><div class="stat-lbl">Current Agent Balance</div><div class="stat-val" style="font-size:15px">${currencyBreakdown(agent.balance_by_currency || [])}</div></div>
                </section>`;

                html += `<section>
                    <div style="font-size:12px;font-weight:900;color:var(--dim);text-transform:uppercase;letter-spacing:.08em;margin:0 0 8px">Daily Income & Commission History</div>`;
                if (!dailyRows.length) {
                    html += '<div style="color:var(--dim);font-size:13px;padding:14px 0">No income or commission records found for this agent yet.</div>';
                } else {
                    html += `<div style="overflow-x:auto"><table class="tbl" style="width:100%;min-width:820px">
                        <thead><tr>
                            <th>Date</th>
                            <th>Currency</th>
                            <th>Total Income</th>
                            <th>Agent Commission</th>
                            <th>Admin Net</th>
                            <th>Tx</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr></thead><tbody>`;

                    dailyRows.forEach(r => {
                        const isPaid = parseInt(r.unpaid_count) === 0;
                        const badge = isPaid
                            ? '<span style="background:rgba(52,211,153,.15);color:#34d399;padding:2px 8px;border-radius:4px;font-size:10px;font-weight:800">PAID</span>'
                            : '<span style="background:rgba(239,68,68,.15);color:#ef4444;padding:2px 8px;border-radius:4px;font-size:10px;font-weight:800">UNPAID</span>';

                        const encName = encodeURIComponent(agentName);
                        const actionBtn = isPaid
                            ? `<span style="font-size:11px;color:var(--dim)">—</span>`
                            : `<button class="btn btn-acc btn-sm" data-agentid="${agentId}" data-date="${r.payment_date}" data-currency="${r.currency_code}" data-agentname="${encName}" onclick="markDailyPaymentPaid(this)" style="font-size:10px;padding:4px 8px">Mark Paid</button>`;

                        html += `<tr>
                            <td style="font-weight:800;white-space:nowrap">${agentEsc(r.payment_date || '')}<br><span style="font-size:10px;color:var(--dim);font-weight:600">${agentEsc(r.first_payment_at || '')}</span></td>
                            <td style="font-weight:800;color:var(--acc);white-space:nowrap">${agentEsc(r.currency_code || 'GHS')}</td>
                            <td>${dualFromPayload(r, 'total_deposit', 'total_deposit_usd')}</td>
                            <td style="font-weight:800">${dualFromPayload(r, 'total_commission', 'total_commission_usd')}</td>
                            <td>${dualFromPayload(r, 'admin_income', 'admin_income_usd')}</td>
                            <td style="font-weight:800">${parseInt(r.tx_count || 0)}</td>
                            <td style="white-space:nowrap">${badge}</td>
                            <td style="white-space:nowrap">${actionBtn}</td>
                        </tr>`;
                    });
                    html += '</tbody></table></div>';
                }
                html += `</section>`;
                html += `</div>`;
                body.innerHTML = html;
            } catch (e) {
                body.innerHTML = `<div style="color:#ef4444;font-size:13px;text-align:center">Error: ${e.message}</div>`;
            }
        }

        async function markDailyPaymentPaid(btn) {
            const agentId  = btn.dataset.agentid;
            const date     = btn.dataset.date;
            const currency = btn.dataset.currency;
            const agentName = decodeURIComponent(btn.dataset.agentname);
            if (!confirm(`Mark ${currency} commission for ${date} as Paid?`)) return;
            const d = await agentApi({
                action: 'mark_daily_commission_paid',
                agent_id: agentId,
                date: date,
                currency: currency
            });
            if (d.success) {
                toast('Payment status updated successfully');
                agentHistoryLoaded = false;
                const historyPanel = document.getElementById('ag-tab-history');
                if (historyPanel && historyPanel.style.display !== 'none') loadAgentDailyHistory(true);
                openAgentDailyPayments(agentId, agentName);
            } else {
                toast(d.message || 'Failed to update payment status', true);
            }
        }


	        // kept for backward compat
        async function fundUser(id, name, bal) { quickFund(id, name, bal); }
        async function setBalance(id, name) {
            const amt = parseFloat(prompt(`Set exact balance for ${name}:`, '0'));
            if (isNaN(amt) || amt < 0) return;
            const d = await api('api_admin_settle.php', { action: 'set_balance', user_id: id, amount: amt });
            if (d.success) { toast(`${name}: local balance set to ${parseFloat(d.new_balance).toFixed(2)}`); loadUsers(); }
            else toast(d.message, true);
        }

        async function toggleBan(id, name, isBanned) {
            const action = isBanned ? 'unban' : 'ban';
            if (!confirm(`${isBanned ? 'Unban' : 'Ban'} ${name}?`)) return;
            const d = await api('api_admin_settle.php', { action: action + '_user', user_id: id });
            if (d.success) { toast(`${name} ${isBanned ? 'unbanned' : 'banned'}`); loadUsers(); }
            else toast(d.message, true);
        }

        async function deleteUser(id, name) {
            if (!confirm(`DELETE ${name} (#${id}) permanently?\n\nThis removes the user and ALL their data. Cannot be undone.`)) return;
            if (!confirm(`FINAL CONFIRM: Delete ${name}?`)) return;
            const d = await api('api_admin_settle.php', { action: 'delete_user', user_id: id });
            if (d.success) { toast(`${name} deleted`); loadUsers(); }
            else toast(d.message, true);
        }

        function bulkAction() {
            const checked = [...document.querySelectorAll('.u-chk:checked')].map(c => c.value);
            if (!checked.length) return toast('No users selected', true);
            const action = prompt(`Bulk action for ${checked.length} users:\n\n1 = Ban\n2 = Unban\n3 = Delete\n\nEnter number:`);
            if (!action) return;
            const map = { '1': 'ban_user', '2': 'unban_user', '3': 'delete_user' };
            const act = map[action.trim()];
            if (!act) return toast('Invalid action', true);
            if (!confirm(`Apply "${act}" to ${checked.length} users?`)) return;
            Promise.all(checked.map(id => api('api_admin_settle.php', { action: act, user_id: id })))
                .then(() => { toast(`Done (${checked.length} users)`); loadUsers(); });
        }

        // ══════════ DEPOSITS ══════════════════════════════════════════════════
        let _allDeposits = [];

        async function loadDeposits(status, tabEl) {
            // Update active tab styling
            document.querySelectorAll('.dep-tab').forEach(t => {
                t.classList.remove('btn-acc'); t.classList.add('btn-ghost');
            });
            if (tabEl) { tabEl.classList.remove('btn-ghost'); tabEl.classList.add('btn-acc'); }
            else {
                const found = document.querySelector(`.dep-tab[data-status="${status}"]`);
                if (found) { found.classList.remove('btn-ghost'); found.classList.add('btn-acc'); }
            }

            document.getElementById('deposits-table').innerHTML = `<div style="padding:40px;text-align:center;color:var(--dim)"><i class="fa fa-spinner fa-spin"></i> Loading…</div>`;

            // Load summary stats (all statuses)
            const stats = await api('api_admin_settle.php', { action: 'stats' });
            if (stats.success) {
                const s = stats.stats;
                let locTotHTML = '', locTodHTML = '';
                if (s.currency_breakdown) {
                    for (const [cur, data] of Object.entries(s.currency_breakdown)) {
                        if (data.deposits > 0) locTotHTML += `<span style="margin-right:8px">${localMoney(data.deposits, cur)}</span>`;
                        if (data.deposits_today > 0) locTodHTML += `<span style="margin-right:8px">${localMoney(data.deposits_today, cur)}</span>`;
                    }
                }
                if (!locTotHTML) locTotHTML = localMoney(0, 'GHS');
                if (!locTodHTML) locTodHTML = localMoney(0, 'GHS');

                document.getElementById('dep-total').innerHTML = `<div>${usdFromPayload(s, 'total_deposits', 'total_deposits_usd')}</div><div style="font-size:10px;color:var(--dim);margin-top:2px">local: ${locTotHTML}</div>`;
                document.getElementById('dep-today').innerHTML = `<div>${usdFromPayload(s, 'deposit_today', 'deposit_today_usd')}</div><div style="font-size:10px;color:var(--dim);margin-top:2px">local: ${locTodHTML}</div>`;
                document.getElementById('dep-completed-count').textContent = (s.deposit_count || 0).toLocaleString();
                document.getElementById('dep-pending-count').textContent = (s.deposit_pending || 0).toLocaleString();
            }

            const d = await api('api_admin_settle.php', { action: 'get_deposits', status, limit: 200, offset: 0 });
            if (!d.success) {
                document.getElementById('deposits-table').innerHTML = `<div class="card" style="color:#ef4444;text-align:center;padding:30px">${d.message || 'Failed to load deposits'}</div>`;
                return;
            }
            _allDeposits = (d.deposits || []).filter(dep => {
                if (isAgentSelfFundDeposit(dep)) return false;
                // Hide Paystack/Flutterwave pending deposits from admin - they self-process via webhook
                if (status === 'Pending' && dep.status === 'Pending') {
                    const method = String(dep.payment_method || dep.method || dep.dep_method || '').toLowerCase();
                    if (method.startsWith('paystack') || method.startsWith('flutterwave')) return false;
                }
                return true;
            });
            renderDepositsTable(_allDeposits, d.total_amount_usd ?? d.total_amount, d.ps_extra);
        }

        function isAgentSelfFundDeposit(dep) {
            const parts = [
                dep.type,
                dep.method,
                dep.payment_method,
                dep.dep_method,
                dep.reference,
                dep.dep_reference
            ].map(v => String(v || '').trim().toLowerCase());
            return parts.includes('agentselffund')
                || parts.includes('agent self fund')
                || parts.some(v => v.includes('agent self fund'));
        }

        function filterDepositTable() {
            const q = (document.getElementById('dep-search')?.value || '').toLowerCase();
            if (!q) { renderDepositsTable(_allDeposits); return; }
            const filtered = _allDeposits.filter(dep => !isAgentSelfFundDeposit(dep) && (
                (dep.username || '').toLowerCase().includes(q) ||
                (dep.email || '').toLowerCase().includes(q) ||
                (dep.reference || '').toLowerCase().includes(q) ||
                String(dep.user_id || '').includes(q)
            ));
            renderDepositsTable(filtered);
        }

        function renderDepositsTable(list, totalAmt, psExtra) {
            list = (list || []).filter(dep => !isAgentSelfFundDeposit(dep));
            const statusColor = {
                Completed: '#34c759', Pending: '#fbbf24', Rejected: '#ef4444',
                Failed: '#ef4444', Abandoned: '#93a0ae', unknown: '#64748b'
            };
            const total = totalAmt ?? list.filter(d => d.status === 'Completed').reduce((s, d) => s + (parseFloat(d.amount_usd ?? usdValue(d.amount, d.currency || 'GHS')) || 0), 0);
            const uncredited = list.filter(d => d.source === 'paystack_api' && !d.credited && d.status === 'Completed').length;

            document.getElementById('deposits-table').innerHTML = `
    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:8px">
            <div class="card-title" style="margin:0">
                DEPOSITS <span style="color:var(--dim);font-weight:600">(${list.length})</span>
                ${psExtra > 0 ? `<span style="background:rgba(239,68,68,.15);color:var(--acc);border:1px solid rgba(239,68,68,.3);padding:2px 8px;border-radius:6px;font-size:10px;font-weight:800;margin-left:8px">${psExtra} from Paystack API</span>` : ''}
                ${uncredited > 0 ? `<span style="background:rgba(251,191,36,.15);color:#fbbf24;border:1px solid rgba(251,191,36,.3);padding:2px 8px;border-radius:6px;font-size:10px;font-weight:800;margin-left:4px">⚠ ${uncredited} uncredited</span>` : ''}
            </div>
            <div style="font-size:13px;font-weight:700;color:var(--acc)">Completed Total: $${(parseFloat(total || 0) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</div>
        </div>
        <div style="overflow-x:auto">
        <table class="tbl">
            <thead><tr>
                <th>#</th><th>User</th><th>Amount</th><th>Method</th>
                <th>Reference</th><th>Status</th><th>Date</th><th>Action</th>
            </tr></thead>
            <tbody>${list.map(dep => {
                const sc = statusColor[dep.status] || '#93a0ae';
                const isPending = dep.status === 'Pending';
                const isUncredited = dep.source === 'paystack_api' && !dep.credited && dep.status === 'Completed';
                const isPs = dep.source === 'paystack_api';
                return `<tr ${isUncredited ? 'style="background:rgba(251,191,36,.04)"' : ''}>
                    <td style="color:var(--dim);font-size:11px">${isPs ? '<span style="color:var(--acc);font-size:9px;font-weight:800">PS</span>' : '#' + dep.id}</td>
                    <td>
                        ${dep.username
                        ? `<div style="font-weight:700">${dep.username}</div><div style="font-size:10px;color:var(--dim)">${dep.email || ''} · #${dep.user_id}</div>`
                        : `<div style="font-size:11px;color:var(--dim)">${dep.email || '—'}</div>${isPs ? '<div style="font-size:9px;color:#ef4444">No account match</div>' : ''}`}
                    </td>
                    <td>${dualFromPayload(dep, 'amount', 'amount_usd')}</td>
                    <td style="font-size:11px;color:var(--dim)">${dep.payment_method || '—'}${dep.dep_method ? `<br><span style="font-size:9px;color:var(--acc)">${dep.dep_method}</span>` : ''}</td>
                    <td style="font-size:10px;color:var(--dim);font-family:monospace;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${(dep.reference || dep.dep_reference || '')}">
                        ${dep.reference || dep.dep_reference || '—'}
                        ${dep.dep_sender_name ? `<br><span style="color:var(--acc);font-family:sans-serif;font-size:9px">By: ${dep.dep_sender_name}</span>` : ''}
                        ${dep.dep_notes ? `<br><span style="color:#fbbf24;font-family:sans-serif;font-size:9px" title="${dep.dep_notes.replace(/"/g, '&quot;')}"><i class="fa fa-info-circle"></i> Info Provided</span>` : ''}
                    </td>
                    <td>
                        <span style="padding:2px 8px;border-radius:5px;font-size:10px;font-weight:800;
                            background:${sc}22;color:${sc};border:1px solid ${sc}44">
                            ${dep.status}
                        </span>
                    </td>
                    <td style="font-size:11px;color:var(--dim)">${(dep.created_at || '').slice(0, 16).replace('T', ' ')}</td>
                    <td>
                        ${isUncredited && dep.user_id ? `
                        <button class="btn btn-acc btn-sm" title="Verify with Paystack and credit balance"
                            onclick="manualCreditPaystack('${(dep.reference || '').replace(/'/g, "\\'")}',${dep.user_id},'${(dep.username || dep.email || '').replace(/'/g, "\\'")}',${dep.amount})">
                            <i class="fa fa-bolt"></i> Credit
                        </button>` :
                        isUncredited && !dep.user_id ? `<span style="font-size:9px;color:#ef4444">No account</span>` :
                            isPending ? `
                        <div style="display:flex;gap:4px">
                            <button class="btn btn-acc btn-sm" onclick="approveDeposit(${dep.id})"><i class="fa fa-check"></i></button>
                            <button class="btn btn-red btn-sm" onclick="rejectDeposit(${dep.id})"><i class="fa fa-times"></i></button>
                        </div>` : `<span style="font-size:10px;color:var(--dim)">—</span>`}
                    </td>
                </tr>`;
            }).join('')}
            </tbody>
        </table>
        </div>
    </div>`;
        }

        async function approveDeposit(txId) {
            if (!confirm('Approve this deposit and credit the user\'s balance?')) return;
            const d = await api('api_admin_settle.php', { action: 'approve_deposit', tx_id: txId });
            if (d.success) { toast('✅ Deposit approved & balance credited'); loadDeposits(document.querySelector('.dep-tab.btn-acc')?.dataset.status || 'all'); loadStats(); }
            else toast(d.message || 'Error', true);
        }

        async function rejectDeposit(txId) {
            if (!confirm('Reject this deposit?')) return;
            const d = await api('api_admin_settle.php', { action: 'reject_deposit', tx_id: txId });
            if (d.success) { toast('❌ Deposit rejected'); loadDeposits(document.querySelector('.dep-tab.btn-acc')?.dataset.status || 'all'); }
            else toast(d.message || 'Error', true);
        }

        async function manualCreditPaystack(ref, userId, name, amount) {
            if (!confirm(`Credit this deposit to ${name}?\n\nThis re-verifies the payment first, then credits their local wallet balance.`)) return;
            const d = await api('api_admin_settle.php', { action: 'manual_credit_paystack', reference: ref, user_id: userId, amount });
            if (d.success) {
                toast('✅ ' + d.message);
                loadDeposits(document.querySelector('.dep-tab.btn-acc')?.dataset.status || 'all');
                loadStats();
            } else toast(d.message || 'Error', true);
        }

        // ══════════ LOGO UPLOAD ══════════════════════════════════════════════
        const logoUploadBlobs = { home: null, away: null };
        const logoUploadReady = { home: null, away: null };
        const logoUploadToken = { home: 0, away: 0 };

        function resetLogoUploadState(side, autoDetect = false) {
            if (logoDebounce?.[side]) clearTimeout(logoDebounce[side]);
            logoUploadToken[side] = (logoUploadToken[side] || 0) + 1;
            logoUploadBlobs[side] = null;
            logoUploadReady[side] = null;
            const hidden = document.getElementById('mf-' + side + '-logo');
            const preview = document.getElementById('mf-' + side + '-logo-preview');
            const file = document.getElementById('mf-' + side + '-logo-file');
            const fname = document.getElementById('mf-' + side + '-logo-fname');
            const clearBtn = document.getElementById('mf-' + side + '-logo-clear');
            if (hidden) hidden.value = '';
            if (preview) { preview.src = ''; preview.style.display = 'none'; }
            if (file) file.value = '';
            if (fname) fname.textContent = '';
            if (clearBtn) clearBtn.style.display = 'none';
            if (autoDetect) debounceLogo(side);
        }

        function handleLogoUpload(side, input) {
            const file = input.files[0];
            if (!file) return;
            const token = ++logoUploadToken[side];

            const reader = new FileReader();
            reader.onload = function (ev) {
                if (token !== logoUploadToken[side]) return;
                const img = new Image();
                img.onload = function () {
                    if (token !== logoUploadToken[side]) return;
                    const maxSide = 420;
                    const scale = Math.min(1, maxSide / Math.max(img.width, img.height));
                    let canvas = document.createElement('canvas');
                    canvas.width = Math.max(1, Math.round(img.width * scale));
                    canvas.height = Math.max(1, Math.round(img.height * scale));
                    let ctx = canvas.getContext('2d', { alpha: true });
                    ctx.clearRect(0, 0, canvas.width, canvas.height);
                    ctx.drawImage(img, 0, 0, canvas.width, canvas.height);

                    const sourceType = String(file.type || '').toLowerCase();
                    const sourceExt = String(file.name || '').split('.').pop().toLowerCase();
                    const preserveAlpha = ['image/png', 'image/webp', 'image/gif'].includes(sourceType)
                        || ['png', 'webp', 'gif'].includes(sourceExt);
                    const outputType = preserveAlpha ? 'image/png' : 'image/jpeg';
                    let quality = preserveAlpha ? undefined : 0.86;
                    let dataUrl = preserveAlpha
                        ? canvas.toDataURL(outputType)
                        : canvas.toDataURL(outputType, quality);

                    if (preserveAlpha) {
                        while (dataUrl.length > 600000 && Math.max(canvas.width, canvas.height) > 96) {
                            const smaller = document.createElement('canvas');
                            smaller.width = Math.max(1, Math.round(canvas.width * 0.82));
                            smaller.height = Math.max(1, Math.round(canvas.height * 0.82));
                            const smallerCtx = smaller.getContext('2d', { alpha: true });
                            smallerCtx.clearRect(0, 0, smaller.width, smaller.height);
                            smallerCtx.drawImage(canvas, 0, 0, smaller.width, smaller.height);
                            canvas = smaller;
                            ctx = smallerCtx;
                            dataUrl = canvas.toDataURL(outputType);
                        }
                    } else {
                        while (dataUrl.length > 260000 && quality > 0.45) {
                            quality -= 0.08;
                            dataUrl = canvas.toDataURL(outputType, quality);
                        }
                    }
                    logoUploadReady[side] = new Promise((resolve) => {
                        canvas.toBlob((blob) => {
                            if (token !== logoUploadToken[side]) { resolve(null); return; }
                            logoUploadBlobs[side] = blob || null;
                            resolve(blob || null);
                        }, outputType, quality);
                    });

                    const preview = document.getElementById('mf-' + side + '-logo-preview');
                    preview.src = dataUrl;
                    preview.style.display = 'block';
                    document.getElementById('mf-' + side + '-logo').value = dataUrl;
                    document.getElementById('mf-' + side + '-logo-fname').textContent = file.name + (preserveAlpha ? ' · transparency preserved' : ' · compressed');
                    document.getElementById('mf-' + side + '-logo-clear').style.display = 'inline-block';
                    toast(preserveAlpha ? 'Logo ready with transparency' : 'Logo compressed and ready');
                };
                img.onerror = function () {
                    toast('Could not read that image. Try JPG or PNG.', true);
                    input.value = '';
                };
                img.src = ev.target.result;
            };
            reader.readAsDataURL(file);
        }

        function clearLogo(side) {
            resetLogoUploadState(side, true);
        }

        // ══════════ SETTINGS ══════════════════════════════════════════════════
        const DEFAULT_PAYMENT_PROVIDER_CATALOG = [
            { key: 'korapay', label: 'Korapay' },
            { key: 'moolre', label: 'Moolre Pay' },
            { key: 'paystack', label: 'Paystack' }
        ];
        const DEFAULT_PAYMENT_ROUTING_RULES = [
            { country: 'Ghana', currency: 'GHS', provider: 'moolre' },
            { country: 'Nigeria', currency: 'NGN', provider: 'korapay' }
        ];
        const DEFAULT_USD_EXCHANGE_RATES = { USD: 1, GHS: 15.5, NGN: 1500, KES: 130, UGX: 3700, TZS: 2600, ZAR: 18.5, GBP: 0.8, EUR: 0.92 };
        const DEFAULT_USDT_TRC20_ADDRESS = 'TEXFGvs8drJWysXySDxiycwCDuTuyJrnw6';

        function normalizePaymentProviderKey(key) {
            const compact = String(key || '').trim().toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
            const aliases = {
                moolre_pay: 'moolre',
                moolrepay: 'moolre',
                moolre: 'moolre',
                kora_pay: 'korapay',
                kora: 'korapay',
                korapay: 'korapay',
                pay_stack: 'paystack',
                paystack: 'paystack'
            };
            return aliases[compact] || compact;
        }

        function parsePaymentProviderCatalog(raw) {
            try {
                const rows = JSON.parse(raw || '[]');
                if (!Array.isArray(rows)) throw new Error('Provider catalog must be an array');
                const cleaned = rows.map(p => ({
                    key: normalizePaymentProviderKey(p.key),
                    label: String(p.label || p.key || '').trim()
                })).filter(p => p.key && p.label);
                if (!cleaned.length) throw new Error('Add at least one provider');
                return cleaned;
            } catch (e) {
                throw new Error(e.message || 'Invalid provider catalog JSON');
            }
        }

        function parsePaymentRoutingRules(raw) {
            try {
                const rows = JSON.parse(raw || '[]');
                return Array.isArray(rows) ? rows : [];
            } catch (e) {
                return [];
            }
        }

        function paymentSelectOptions(providers, selected) {
            return providers.map(p => `<option value="${agentEsc(p.key)}" ${p.key === selected ? 'selected' : ''}>${agentEsc(p.label)} (${agentEsc(p.key)})</option>`).join('');
        }

        function refreshPaymentProviderDropdowns(selected = {}) {
            const msg = document.getElementById('payment-routing-msg');
            try {
                const providers = parsePaymentProviderCatalog(document.getElementById('set-payment-provider-catalog').value);
                const fallback = selected.defaultProvider || document.getElementById('set-payment-default-provider')?.value || providers[0].key;
                const gh = selected.ghProvider || document.getElementById('set-payment-gh-provider')?.value || fallback;
                const ng = selected.ngProvider || document.getElementById('set-payment-ng-provider')?.value || fallback;
                document.getElementById('set-payment-default-provider').innerHTML = paymentSelectOptions(providers, fallback);
                document.getElementById('set-payment-gh-provider').innerHTML = paymentSelectOptions(providers, gh);
                document.getElementById('set-payment-ng-provider').innerHTML = paymentSelectOptions(providers, ng);
                if (msg) { msg.textContent = 'Provider list loaded'; msg.style.color = 'var(--dim)'; }
            } catch (e) {
                if (msg) { msg.textContent = e.message; msg.style.color = '#ef4444'; }
            }
        }

        function parseUsdExchangeRates(raw) {
            let rows = {};
            try {
                rows = JSON.parse(raw || '{}');
            } catch (e) {
                throw new Error('Exchange rates JSON is invalid');
            }
            if (!rows || typeof rows !== 'object' || Array.isArray(rows)) {
                throw new Error('Exchange rates must be a JSON object');
            }
            const cleaned = {};
            Object.entries(rows).forEach(([code, rate]) => {
                const key = String(code || '').trim().toUpperCase();
                const value = parseFloat(rate);
                if (!key) return;
                if (!Number.isFinite(value) || value <= 0) {
                    throw new Error(`${key} exchange rate must be greater than 0`);
                }
                cleaned[key] = value;
            });
            cleaned.USD = 1;
            if (!cleaned.GHS) cleaned.GHS = DEFAULT_USD_EXCHANGE_RATES.GHS;
            if (!cleaned.NGN) cleaned.NGN = DEFAULT_USD_EXCHANGE_RATES.NGN;
            return cleaned;
        }

        function loadUsdExchangeRateInputs(raw) {
            let rates = DEFAULT_USD_EXCHANGE_RATES;
            try { rates = parseUsdExchangeRates(raw || JSON.stringify(DEFAULT_USD_EXCHANGE_RATES)); } catch (e) {}
            document.getElementById('set-usd-rate-ghs').value = parseFloat(rates.GHS || DEFAULT_USD_EXCHANGE_RATES.GHS).toFixed(4).replace(/\.?0+$/, '');
            document.getElementById('set-usd-rate-ngn').value = parseFloat(rates.NGN || DEFAULT_USD_EXCHANGE_RATES.NGN).toFixed(4).replace(/\.?0+$/, '');
            document.getElementById('set-usd-rate-usd').value = '1';
            document.getElementById('set-usd-exchange-rates').value = JSON.stringify(rates, null, 2);
        }

        function collectUsdExchangeRates() {
            const rates = parseUsdExchangeRates(document.getElementById('set-usd-exchange-rates').value || '{}');
            const ghs = parseFloat(document.getElementById('set-usd-rate-ghs').value);
            const ngn = parseFloat(document.getElementById('set-usd-rate-ngn').value);
            const usd = parseFloat(document.getElementById('set-usd-rate-usd').value || '1');
            if (!Number.isFinite(ghs) || ghs <= 0) throw new Error('GHS exchange rate must be greater than 0');
            if (!Number.isFinite(ngn) || ngn <= 0) throw new Error('NGN exchange rate must be greater than 0');
            if (!Number.isFinite(usd) || usd <= 0) throw new Error('USD exchange rate must be greater than 0');
            rates.GHS = ghs;
            rates.NGN = ngn;
            rates.USD = 1;
            document.getElementById('set-usd-exchange-rates').value = JSON.stringify(rates, null, 2);
            return rates;
        }

        function toggleFlutterwaveVersionFields() {
            const version = document.getElementById('set-direct-flutterwave-version')?.value || 'v3';
            const v3 = document.getElementById('flutterwave-v3-fields');
            const v4 = document.getElementById('flutterwave-v4-fields');
            if (v3) v3.style.display = version === 'v3' ? 'grid' : 'none';
            if (v4) v4.style.display = version === 'v4' ? 'grid' : 'none';
        }

        async function copyFlutterwaveWebhookUrl() {
            const input = document.getElementById('flutterwave-v4-webhook-url');
            if (!input) return;
            try {
                await navigator.clipboard.writeText(input.value);
            } catch (error) {
                input.select();
                document.execCommand('copy');
            }
            toast('Flutterwave webhook URL copied');
        }

        function generateFlutterwaveWebhookSecret() {
            const field = document.getElementById('set-direct-flutterwave-v4-webhook-secret');
            if (!field || !window.crypto?.getRandomValues) {
                toast('Your browser could not generate a secure secret.', true);
                return;
            }
            const bytes = new Uint8Array(32);
            window.crypto.getRandomValues(bytes);
            field.type = 'text';
            field.value = 'flw4_' + Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
            toast('Secret generated. Copy it into Flutterwave, then save here.');
        }

        async function copyFlutterwaveWebhookSecret() {
            const field = document.getElementById('set-direct-flutterwave-v4-webhook-secret');
            if (!field?.value) {
                toast('Generate or paste a webhook secret first.', true);
                return;
            }
            try {
                await navigator.clipboard.writeText(field.value);
            } catch (error) {
                field.type = 'text';
                field.select();
                document.execCommand('copy');
            }
            toast('Webhook secret copied. Paste it into Flutterwave Webhooks.');
        }

        async function saveFlutterwaveSettings() {
            const version = document.getElementById('set-direct-flutterwave-version').value === 'v3' ? 'v3' : 'v4';
            const environment = document.getElementById('set-direct-flutterwave-v4-environment').value === 'sandbox' ? 'sandbox' : 'live';
            const clientId = document.getElementById('set-direct-flutterwave-v4-client-id').value.trim();
            if (version === 'v4' && !clientId) {
                toast('Flutterwave V4 Client ID is required.', true);
                return;
            }
            const payload = {
                action: 'settings',
                direct_flutterwave_version: version,
                direct_flutterwave_v4_environment: environment,
            };
            if (document.getElementById('set-flutterwave-direct-enabled').checked) {
                payload.direct_gateway_provider = 'flutterwave';
                payload.deposit_method = 'direct_gateway';
            }
            [
                ['direct_flutterwave_public_key', 'set-direct-flutterwave-public-key'],
                ['direct_flutterwave_secret_key', 'set-direct-flutterwave-secret-key'],
                ['direct_flutterwave_encryption_key', 'set-direct-flutterwave-encryption-key'],
                ['direct_flutterwave_webhook_secret', 'set-direct-flutterwave-webhook-secret'],
                ['direct_flutterwave_v4_client_id', 'set-direct-flutterwave-v4-client-id'],
                ['direct_flutterwave_v4_client_secret', 'set-direct-flutterwave-v4-client-secret'],
                ['direct_flutterwave_v4_encryption_key', 'set-direct-flutterwave-v4-encryption-key'],
                ['direct_flutterwave_v4_webhook_secret', 'set-direct-flutterwave-v4-webhook-secret'],
            ].forEach(([key, id]) => {
                const value = document.getElementById(id)?.value.trim() || '';
                if (value) payload[key] = value;
            });
            const result = await api('api_admin_matches.php', payload);
            if (!result.success) {
                toast(result.message || 'Flutterwave settings could not be saved.', true);
                return;
            }
            ['set-direct-flutterwave-secret-key', 'set-direct-flutterwave-encryption-key', 'set-direct-flutterwave-webhook-secret', 'set-direct-flutterwave-v4-client-secret', 'set-direct-flutterwave-v4-encryption-key', 'set-direct-flutterwave-v4-webhook-secret'].forEach(id => {
                const field = document.getElementById(id); if (field) field.value = '';
            });
            toast('Flutterwave settings saved.');
            await loadSettings();
        }

        async function testFlutterwaveV4Connection() {
            const status = document.getElementById('flutterwave-v4-status');
            if (status) status.textContent = 'Testing Flutterwave V4 connection…';
            const result = await api('api_admin_matches.php', { action: 'flutterwave_v4_test' });
            if (status) {
                status.textContent = result.message || (result.success ? 'Flutterwave V4 ready' : 'Connection failed');
                status.style.color = result.success ? '#34d399' : '#f87171';
            }
            toast(result.message || (result.success ? 'Flutterwave V4 connection verified.' : 'Flutterwave V4 connection failed.'), !result.success);
        }

        async function loadSettings() {
            // FIX: r1 now works (settings_get action added to api_admin_matches.php)
            // FIX: removed dead 'res' fetch to api_admin_settle.php stats (unused)
            // FIX: admin_settings_get.php now exists and returns after/lock/admins
            const [r1, rows] = await Promise.all([
                fetch('api_admin_matches.php?action=settings_get').then(r => r.json()).catch(() => ({})),
                fetch('admin_settings_get.php').then(r => r.json()).catch(() => ({ after: 10, lock: 0, admins: '1' }))
            ]);
            // Prefer r1 for match-engine settings, rows for admin IDs
            const after = r1.dashboard_after_admin ?? rows.after ?? 10;
            const lock = r1.odds_global_lock ?? rows.lock ?? 0;
            document.getElementById('set-after').value = after;
            document.getElementById('set-admins').value = rows.admins ?? '1';
            document.getElementById('set-global-lock').checked = parseInt(lock) === 1;
            const agPctEl = document.getElementById('ag-default-pct');
            if (agPctEl) agPctEl.value = parseFloat(r1.subadmin_default_commission_pct ?? rows.subadmin_default_commission_pct ?? 70).toFixed(0);
            document.getElementById('set-popular-count').value = r1.popular_count ?? rows.popular_count ?? 5;
            document.getElementById('set-today-count').value = r1.today_count ?? rows.today_count ?? 30;
            document.getElementById('set-live-count').value = r1.live_count ?? rows.live_count ?? 20;
            document.getElementById('set-popular-section-default').value = r1.popular_section_default ?? rows.popular_section_default ?? 'events';
            const cashoutLock = r1.cashout_locked !== undefined && r1.cashout_locked !== null ? r1.cashout_locked : (rows.cashout_locked || 0);
            document.getElementById('set-cashout-lock').checked = parseInt(cashoutLock) === 1;
            document.getElementById('set-main-admin-username').value = r1.main_admin_username || rows.main_admin_username || 'admin';
            document.getElementById('set-deposit-method').value = r1.deposit_method ?? rows.deposit_method ?? 'techvault';
            document.getElementById('set-default-theme').value = (r1.default_theme || rows.default_theme || 'dark') === 'light' ? 'light' : 'dark';
            document.getElementById('set-live-match-source').value = r1.live_match_source || rows.live_match_source || 'apifootball';
            document.getElementById('set-live-api-cache-seconds').value = r1.live_api_cache_seconds ?? rows.live_api_cache_seconds ?? 120;
            const apiFootballStatus = document.getElementById('apifootball-key-status');
            if (apiFootballStatus) apiFootballStatus.textContent = (r1.apifootball_api_key_configured || rows.apifootball_api_key_configured) ? 'API-Football key saved' : 'Using default API-Football key';
            document.getElementById('set-direct-gateway-provider').value = r1.direct_gateway_provider ?? rows.direct_gateway_provider ?? 'paystack';
            const flutterwaveVersion = (r1.direct_flutterwave_version || rows.direct_flutterwave_version) === 'v4' ? 'v4' : 'v3';
            document.getElementById('set-direct-flutterwave-version').value = flutterwaveVersion;
            document.getElementById('set-direct-flutterwave-v4-environment').value = (r1.direct_flutterwave_v4_environment || rows.direct_flutterwave_v4_environment) === 'sandbox' ? 'sandbox' : 'live';
            toggleFlutterwaveVersionFields();
            const gatewayStatus = document.getElementById('gateway-config-status');
            if (gatewayStatus) {
                const flags = {
                    paystackPublic: r1.direct_paystack_public_key_configured || rows.direct_paystack_public_key_configured,
                    paystackSecret: r1.direct_paystack_secret_key_configured || rows.direct_paystack_secret_key_configured,
                    flutterwavePublic: r1.direct_flutterwave_public_key_configured || rows.direct_flutterwave_public_key_configured,
                    flutterwaveSecret: r1.direct_flutterwave_secret_key_configured || rows.direct_flutterwave_secret_key_configured,
                    flutterwaveWebhook: r1.direct_flutterwave_webhook_secret_configured || rows.direct_flutterwave_webhook_secret_configured,
                    flutterwaveV4Client: r1.direct_flutterwave_v4_client_id_configured || rows.direct_flutterwave_v4_client_id_configured,
                    flutterwaveV4Secret: r1.direct_flutterwave_v4_client_secret_configured || rows.direct_flutterwave_v4_client_secret_configured,
                    flutterwaveV4Encryption: r1.direct_flutterwave_v4_encryption_key_configured || rows.direct_flutterwave_v4_encryption_key_configured,
                    flutterwaveV4Webhook: r1.direct_flutterwave_v4_webhook_secret_configured || rows.direct_flutterwave_v4_webhook_secret_configured,
                    moolreApi: r1.direct_moolre_api_user_configured || rows.direct_moolre_api_user_configured,
                    moolrePublic: r1.direct_moolre_public_key_configured || rows.direct_moolre_public_key_configured,
                    techvault: r1.techvault_shared_token_configured || rows.techvault_shared_token_configured,
                };
                gatewayStatus.textContent = `Saved keys: Paystack public ${flags.paystackPublic ? 'yes' : 'no'}, Paystack secret ${flags.paystackSecret ? 'yes' : 'no'}, Flutterwave v3 public ${flags.flutterwavePublic ? 'yes' : 'no'}, v3 secret ${flags.flutterwaveSecret ? 'yes' : 'no'}, v4 client ID ${flags.flutterwaveV4Client ? 'yes' : 'no'}, v4 client secret ${flags.flutterwaveV4Secret ? 'yes' : 'no'}, v4 encryption ${flags.flutterwaveV4Encryption ? 'yes' : 'no'}, v4 webhook ${flags.flutterwaveV4Webhook ? 'yes' : 'no'}, Moolre API ${flags.moolreApi ? 'yes' : 'no'}, Moolre public ${flags.moolrePublic ? 'yes' : 'no'}, TechVault token ${flags.techvault ? 'yes' : 'no'}.`;
            }
            document.getElementById('set-direct-paystack-public-key').value = r1.direct_paystack_public_key_public || rows.direct_paystack_public_key_public || '';
            document.getElementById('set-direct-flutterwave-public-key').value = r1.direct_flutterwave_public_key_public || rows.direct_flutterwave_public_key_public || '';
            document.getElementById('set-direct-flutterwave-v4-client-id').value = r1.direct_flutterwave_v4_client_id_public || rows.direct_flutterwave_v4_client_id_public || '';
            const flutterwaveDirectEnabled = (r1.direct_gateway_provider || rows.direct_gateway_provider) === 'flutterwave'
                && (r1.deposit_method || rows.deposit_method) === 'direct_gateway';
            const flutterwaveEnabledField = document.getElementById('set-flutterwave-direct-enabled');
            if (flutterwaveEnabledField) flutterwaveEnabledField.checked = flutterwaveDirectEnabled;
            const flutterwaveStatus = document.getElementById('flutterwave-v4-status');
            if (flutterwaveStatus) {
                const v4Ready = (r1.direct_flutterwave_v4_client_id_configured || rows.direct_flutterwave_v4_client_id_configured)
                    && (r1.direct_flutterwave_v4_client_secret_configured || rows.direct_flutterwave_v4_client_secret_configured)
                    && (r1.direct_flutterwave_v4_webhook_secret_configured || rows.direct_flutterwave_v4_webhook_secret_configured);
                flutterwaveStatus.textContent = v4Ready ? (flutterwaveDirectEnabled ? 'V4 configured and active' : 'V4 configured — not active') : 'V4 setup incomplete';
                flutterwaveStatus.style.color = v4Ready ? '#34d399' : '#f59e0b';
            }
            document.getElementById('set-direct-moolre-ghs-account').value = r1.direct_moolre_ghs_account_public || rows.direct_moolre_ghs_account_public || '';
            document.getElementById('set-direct-moolre-ngn-account').value = r1.direct_moolre_ngn_account_public || rows.direct_moolre_ngn_account_public || '';
            document.getElementById('set-site-min-stake').value = parseFloat(r1.site_min_stake ?? rows.site_min_stake ?? 0).toFixed(2);
            const loadedMinDepositGhs = parseFloat(r1.site_min_deposit_ghs ?? rows.site_min_deposit_ghs ?? r1.site_min_deposit ?? rows.site_min_deposit ?? 300);
            const loadedMinDepositNgn = parseFloat(r1.site_min_deposit_ngn ?? rows.site_min_deposit_ngn ?? 20000);
            document.getElementById('set-site-min-deposit-ghs').value = (Number.isFinite(loadedMinDepositGhs) && loadedMinDepositGhs > 0 ? loadedMinDepositGhs : 300).toFixed(2);
            document.getElementById('set-site-min-deposit-ngn').value = (Number.isFinite(loadedMinDepositNgn) && loadedMinDepositNgn > 0 ? loadedMinDepositNgn : 20000).toFixed(2);
            document.getElementById('set-support-whatsapp-link').value = r1.support_whatsapp_link || rows.support_whatsapp_link || '';
            document.getElementById('set-support-telegram-link').value = r1.support_telegram_link || rows.support_telegram_link || '';
            document.getElementById('set-usdt-trc20-address').value = String(r1.usdt_trc20_address || rows.usdt_trc20_address || DEFAULT_USDT_TRC20_ADDRESS);
            loadUsdExchangeRateInputs(r1.usd_exchange_rates || rows.usd_exchange_rates || JSON.stringify(DEFAULT_USD_EXCHANGE_RATES));
            // Bug 1 fix: load registration mode from server
            const regModeEl = document.getElementById('set-registration-mode');
            if (regModeEl) regModeEl.value = rows.registration_mode ?? 'auto_verify';

            let providers = DEFAULT_PAYMENT_PROVIDER_CATALOG;
            try { providers = parsePaymentProviderCatalog(r1.payment_provider_catalog || rows.payment_provider_catalog || JSON.stringify(DEFAULT_PAYMENT_PROVIDER_CATALOG)); } catch (e) {}
            const rules = parsePaymentRoutingRules(r1.payment_routing_rules || rows.payment_routing_rules || JSON.stringify(DEFAULT_PAYMENT_ROUTING_RULES));
            const ghRule = rules.find(r => String(r.currency || '').toUpperCase() === 'GHS' || String(r.country || '').toLowerCase() === 'ghana') || {};
            const ngRule = rules.find(r => String(r.currency || '').toUpperCase() === 'NGN' || String(r.country || '').toLowerCase() === 'nigeria') || {};
            const defaultProvider = String(r1.payment_default_provider || rows.payment_default_provider || providers[0]?.key || 'moolre').trim().toLowerCase();
            document.getElementById('set-payment-provider-catalog').value = JSON.stringify(providers, null, 2);
            refreshPaymentProviderDropdowns({
                defaultProvider,
                ghProvider: String(ghRule.provider || defaultProvider).trim().toLowerCase(),
                ngProvider: String(ngRule.provider || defaultProvider).trim().toLowerCase()
            });
        }

        function updateAiOutcomeControls() {
            const mode = document.getElementById('set-ai-match-outcome-mode')?.value || 'mixed';
            const exactWrap = document.getElementById('ai-match-exact-score-wrap');
            if (exactWrap) exactWrap.style.display = mode === 'exact' ? 'block' : 'none';
        }

        async function loadAiMatchSettings() {
            const statusEl = document.getElementById('ai-match-api-status');
            const lastEl = document.getElementById('ai-match-last-run');
            try {
                const data = await fetch('api_ai_matches.php?action=status').then(r => r.json());
                if (!data.success) throw new Error(data.message || 'Could not load AI match settings');
                const settings = data.settings || {};
                document.getElementById('set-ai-match-enabled').checked = !!settings.enabled;
                document.getElementById('set-ai-match-count').value = settings.count || 3;
                document.getElementById('set-ai-match-times').value = settings.times || '12:00,16:00,20:00';
                document.getElementById('set-ai-match-generate-at').value = settings.generate_at || '00:05';
                document.getElementById('set-ai-match-logo-mode').value = settings.logo_mode || 'local';
                document.getElementById('set-ai-match-outcome-mode').value = settings.outcome_mode || 'mixed';
                document.getElementById('set-ai-match-exact-score').value = settings.exact_score || '2-2';
                document.getElementById('set-ai-match-odds-min').value = Number(settings.odds_min || 1.20).toFixed(2);
                document.getElementById('set-ai-match-odds-max').value = Number(settings.odds_max || 9.00).toFixed(2);
                updateAiOutcomeControls();
                statusEl.textContent = settings.api_key_configured ? 'OPENAI KEY READY' : 'OPENAI KEY REQUIRED';
                statusEl.style.color = settings.api_key_configured ? '#34d399' : '#f59e0b';
                const last = data.last_run;
                if (last) {
                    const suffix = last.status === 'success'
                        ? `${last.match_count || 0} matches`
                        : (last.error_message || last.status || 'unknown result');
                    lastEl.textContent = `Last run ${last.generation_date}: ${suffix}`;
                } else {
                    lastEl.textContent = 'No matches have been generated yet.';
                }
                const cronWrap = document.getElementById('ai-match-cron-wrap');
                const cronInput = document.getElementById('ai-match-cron-url');
                if (data.cron_url) {
                    cronInput.value = data.cron_url;
                    cronWrap.style.display = 'block';
                } else {
                    cronInput.value = '';
                    cronWrap.style.display = 'none';
                }
                return true;
            } catch (error) {
                statusEl.textContent = 'STATUS UNAVAILABLE';
                statusEl.style.color = '#ef4444';
                lastEl.textContent = error.message || 'Could not load scheduler status.';
                return false;
            }
        }

        async function saveAiMatchSettings(button = null, silent = false) {
            const count = Math.max(1, Math.min(8, parseInt(document.getElementById('set-ai-match-count').value || '3', 10)));
            const times = document.getElementById('set-ai-match-times').value.trim();
            const validTimes = times.split(/[\s,;]+/).filter(value => /^(?:[01]\d|2[0-3]):[0-5]\d$/.test(value));
            if (validTimes.length < count) {
                if (!silent) toast(`Enter at least ${count} valid match times`, true);
                return false;
            }
            const outcomeMode = document.getElementById('set-ai-match-outcome-mode').value;
            const exactScore = document.getElementById('set-ai-match-exact-score').value.trim();
            if (outcomeMode === 'exact' && !/^[0-6]-[0-6]$/.test(exactScore)) {
                if (!silent) toast('Exact score must be between 0-0 and 6-6', true);
                return false;
            }
            const oddsMin = parseFloat(document.getElementById('set-ai-match-odds-min').value);
            const oddsMax = parseFloat(document.getElementById('set-ai-match-odds-max').value);
            if (!Number.isFinite(oddsMin) || !Number.isFinite(oddsMax) || oddsMin < 1.01 || oddsMax > 99 || oddsMax <= oddsMin) {
                if (!silent) toast('Maximum odds must be greater than minimum odds', true);
                return false;
            }
            const original = button ? button.innerHTML : '';
            if (button) {
                button.disabled = true;
                button.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving';
            }
            try {
                const data = await api('api_ai_matches.php', {
                    action: 'save_settings',
                    enabled: document.getElementById('set-ai-match-enabled').checked ? 1 : 0,
                    count,
                    times,
                    generate_at: document.getElementById('set-ai-match-generate-at').value || '00:05',
                    logo_mode: document.getElementById('set-ai-match-logo-mode').value,
                    outcome_mode: outcomeMode,
                    exact_score: exactScore,
                    odds_min: oddsMin.toFixed(2),
                    odds_max: oddsMax.toFixed(2),
                    openai_api_key: document.getElementById('set-openai-api-key').value.trim()
                });
                if (!data.success) throw new Error(data.message || 'Could not save AI schedule');
                document.getElementById('set-openai-api-key').value = '';
                await loadAiMatchSettings();
                if (!silent) toast('AI match schedule saved');
                return true;
            } catch (error) {
                if (!silent) toast(error.message || 'Could not save AI schedule', true);
                return false;
            } finally {
                if (button) {
                    button.disabled = false;
                    button.innerHTML = original;
                }
            }
        }

        async function generateAiMatches(button) {
            const saved = await saveAiMatchSettings(null, true);
            if (!saved) {
                toast('Check the AI schedule fields before generating', true);
                return;
            }
            const original = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Generating matches and badges';
            const lastEl = document.getElementById('ai-match-last-run');
            lastEl.textContent = 'Generation is running. PNG badge creation can take several minutes.';
            try {
                const data = await api('api_ai_matches.php', { action: 'generate', date: new Date().toISOString().slice(0, 10) });
                if (!data.success) throw new Error(data.message || 'Generation failed');
                toast(data.message || `${data.count || 0} matches generated`);
                await loadAiMatchSettings();
                if (typeof loadMatches === 'function') await loadMatches();
            } catch (error) {
                toast(error.message || 'Match generation failed', true);
                lastEl.textContent = error.message || 'Match generation failed.';
            } finally {
                button.disabled = false;
                button.innerHTML = original;
            }
        }

        async function copyAiCronUrl() {
            const input = document.getElementById('ai-match-cron-url');
            if (!input.value) return;
            try {
                await navigator.clipboard.writeText(input.value);
                toast('Cron URL copied');
            } catch (error) {
                input.select();
                document.execCommand('copy');
                toast('Cron URL copied');
            }
        }

        async function saveSettings() {
            const after = document.getElementById('set-after').value;
            const lock = document.getElementById('set-global-lock').checked ? 1 : 0;
            const cashoutLocked = document.getElementById('set-cashout-lock').checked ? 1 : 0;
            const admins = document.getElementById('set-admins').value;
            const popCount = document.getElementById('set-popular-count').value;
            const todCount = document.getElementById('set-today-count').value;
            const liveCount = document.getElementById('set-live-count').value;
            const depMethod = document.getElementById('set-deposit-method').value;
            const defaultTheme = document.getElementById('set-default-theme').value === 'light' ? 'light' : 'dark';
            const liveMatchSource = document.getElementById('set-live-match-source').value || 'apifootball';
            const apiFootballKey = document.getElementById('set-apifootball-api-key').value.trim();
            const liveApiCacheSeconds = Math.max(30, Math.min(900, parseInt(document.getElementById('set-live-api-cache-seconds').value || '120', 10)));
            const siteMinStake = document.getElementById('set-site-min-stake').value;
            const siteMinDepositGhs = document.getElementById('set-site-min-deposit-ghs').value;
            const siteMinDepositNgn = document.getElementById('set-site-min-deposit-ngn').value;
            const supportWhatsappLink = document.getElementById('set-support-whatsapp-link').value.trim();
            const supportTelegramLink = document.getElementById('set-support-telegram-link').value.trim();
            const usdtAddress = document.getElementById('set-usdt-trc20-address').value.trim();
            const mainAdminUsername = document.getElementById('set-main-admin-username').value.trim() || 'admin';
            const mainAdminPassword = document.getElementById('set-main-admin-password').value;
            const mainAdminPasswordConfirm = document.getElementById('set-main-admin-password-confirm').value;
            const directGatewayProvider = document.getElementById('set-direct-gateway-provider').value || 'paystack';
            const regMode = document.getElementById('set-registration-mode')?.value || 'auto_verify';
            const popularSectionDefault = document.getElementById('set-popular-section-default')?.value || 'events';
            const siteMinDepositGhsNum = parseFloat(siteMinDepositGhs);
            const siteMinDepositNgnNum = parseFloat(siteMinDepositNgn);
            if (!Number.isFinite(siteMinDepositGhsNum) || siteMinDepositGhsNum <= 0) {
                toast('Ghana minimum deposit must be greater than 0', true);
                return;
            }
            if (!Number.isFinite(siteMinDepositNgnNum) || siteMinDepositNgnNum <= 0) {
                toast('Nigeria minimum deposit must be greater than 0', true);
                return;
            }
            if (!usdtAddress) {
                toast('USDT TRC20 address is required', true);
                return;
            }
            if (mainAdminPassword || mainAdminPasswordConfirm) {
                if (mainAdminPassword.length < 6) {
                    toast('Main admin password must be at least 6 characters', true);
                    return;
                }
                if (mainAdminPassword !== mainAdminPasswordConfirm) {
                    toast('Main admin passwords do not match', true);
                    return;
                }
            }
            let usdRates;
            try {
                usdRates = collectUsdExchangeRates();
            } catch (e) {
                toast(e.message, true);
                return;
            }
            let providers;
            try {
                providers = parsePaymentProviderCatalog(document.getElementById('set-payment-provider-catalog').value);
            } catch (e) {
                toast(e.message, true);
                return;
            }
            const providerKeys = providers.map(p => p.key);
            const defaultProvider = document.getElementById('set-payment-default-provider').value || providers[0].key;
            const ghProvider = document.getElementById('set-payment-gh-provider').value || defaultProvider;
            const ngProvider = document.getElementById('set-payment-ng-provider').value || defaultProvider;
            if (![defaultProvider, ghProvider, ngProvider].every(p => providerKeys.includes(p))) {
                toast('Payment routing uses a provider not found in the catalog', true);
                return;
            }
            const routingRules = [
                { country: 'Ghana', currency: 'GHS', provider: ghProvider },
                { country: 'Nigeria', currency: 'NGN', provider: ngProvider }
            ];
            const settingsPayload = {
                action: 'settings',
                dashboard_after_admin: after,
                odds_global_lock: lock,
                cashout_locked: cashoutLocked,
                popular_count: popCount,
                today_count: todCount,
                live_count: liveCount,
                popular_section_default: popularSectionDefault,
                live_match_source: liveMatchSource,
                live_api_cache_seconds: liveApiCacheSeconds,
                deposit_method: depMethod,
                default_theme: defaultTheme,
                site_min_stake: siteMinStake,
                site_min_deposit: siteMinDepositGhs,
                site_min_deposit_ghs: siteMinDepositGhs,
                site_min_deposit_ngn: siteMinDepositNgn,
                support_whatsapp_link: supportWhatsappLink,
                support_telegram_link: supportTelegramLink,
                usdt_trc20_address: usdtAddress,
                usd_exchange_rates: JSON.stringify(usdRates),
                payment_provider_catalog: JSON.stringify(providers),
                payment_default_provider: defaultProvider,
                payment_routing_rules: JSON.stringify(routingRules),
                direct_gateway_provider: directGatewayProvider,
                direct_flutterwave_version: document.getElementById('set-direct-flutterwave-version').value === 'v4' ? 'v4' : 'v3',
                direct_flutterwave_v4_environment: document.getElementById('set-direct-flutterwave-v4-environment').value === 'sandbox' ? 'sandbox' : 'live',
                main_admin_username: mainAdminUsername,
            };
            if (mainAdminPassword) settingsPayload.main_admin_new_password = mainAdminPassword;
            if (apiFootballKey) settingsPayload.apifootball_api_key = apiFootballKey;
            [
                ['techvault_shared_token', 'set-techvault-shared-token'],
                ['direct_paystack_public_key', 'set-direct-paystack-public-key'],
                ['direct_paystack_secret_key', 'set-direct-paystack-secret-key'],
                ['direct_flutterwave_public_key', 'set-direct-flutterwave-public-key'],
                ['direct_flutterwave_secret_key', 'set-direct-flutterwave-secret-key'],
                ['direct_flutterwave_encryption_key', 'set-direct-flutterwave-encryption-key'],
                ['direct_flutterwave_webhook_secret', 'set-direct-flutterwave-webhook-secret'],
                ['direct_flutterwave_v4_client_id', 'set-direct-flutterwave-v4-client-id'],
                ['direct_flutterwave_v4_client_secret', 'set-direct-flutterwave-v4-client-secret'],
                ['direct_flutterwave_v4_encryption_key', 'set-direct-flutterwave-v4-encryption-key'],
                ['direct_flutterwave_v4_webhook_secret', 'set-direct-flutterwave-v4-webhook-secret'],
                ['direct_moolre_api_user', 'set-direct-moolre-api-user'],
                ['direct_moolre_public_key', 'set-direct-moolre-public-key'],
                ['direct_moolre_ghs_account', 'set-direct-moolre-ghs-account'],
                ['direct_moolre_ngn_account', 'set-direct-moolre-ngn-account'],
                ['direct_moolre_ghs_webhook_secret', 'set-direct-moolre-ghs-webhook-secret'],
                ['direct_moolre_ngn_webhook_secret', 'set-direct-moolre-ngn-webhook-secret'],
            ].forEach(([key, id]) => {
                const el = document.getElementById(id);
                const value = el ? el.value.trim() : '';
                if (value) settingsPayload[key] = value;
            });
            await api('api_admin_matches.php', settingsPayload);
            document.getElementById('set-main-admin-password').value = '';
            document.getElementById('set-main-admin-password-confirm').value = '';
            await fetch('api_admin_settle.php', { method: 'POST', body: new URLSearchParams({ action: 'save_admins', admin_ids: admins }) });
            // Bug 1 fix: save registration verification mode
            await fetch('api_admin_settle.php', { method: 'POST', body: new URLSearchParams({ action: 'save_registration_mode', mode: regMode }) });
            toast('Settings saved!');
        }

        // ── Mobile sidebar ───────────────────────────────────────────────
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
            document.getElementById('sbOverlay').classList.toggle('show');
        }
        function closeSidebar() {
            document.getElementById('sidebar').classList.remove('open');
            document.getElementById('sbOverlay').classList.remove('show');
        }

        // Boot
        loadStats();
        setTimeout(() => runAutoSettle(true), 10000);
        setInterval(() => runAutoSettle(true), 30000);

        // ═══════════════════════════════════════════════════════════════════
        // CASINO CONTROL
        // ═══════════════════════════════════════════════════════════════════

        function showCasinoRuleForm() {
            document.getElementById('casinoRuleForm').style.display = 'block';
            document.getElementById('cr-id').value = '';
            document.getElementById('cr-game').value = 'all';
            document.getElementById('cr-outcome').value = 'loss';
            document.getElementById('cr-start').value = '';
            document.getElementById('cr-end').value = '';
            document.getElementById('cr-priority').value = '0';
        }

        async function saveCasinoRule() {
            const id = document.getElementById('cr-id').value;
            const game = document.getElementById('cr-game').value;
            const outcome = document.getElementById('cr-outcome').value;
            const start = document.getElementById('cr-start').value.replace('T', ' ');
            const end = document.getElementById('cr-end').value.replace('T', ' ');
            const priority = document.getElementById('cr-priority').value;

            if (!start || !end) {
                toast('Start and End times are required', true);
                return;
            }

            const payload = new URLSearchParams({
                action: 'save_rule',
                id: id,
                game: game,
                outcome: outcome,
                start_time: start,
                end_time: end,
                priority: priority
            });

            try {
                const res = await fetch('api_admin_casino.php', {
                    method: 'POST',
                    body: payload
                }).then(r => r.json());

                if (res.success) {
                    toast(id ? 'Rule updated' : 'Rule added');
                    document.getElementById('casinoRuleForm').style.display = 'none';
                    loadCasinoRules();
                } else {
                    toast(res.message, true);
                }
            } catch (e) {
                toast('Error saving rule', true);
            }
        }

        async function loadCasinoRules() {
            const container = document.getElementById('casino-rules-list');
            container.innerHTML = '<div style="color:var(--dim);padding:20px;text-align:center">⏳ Loading rules...</div>';
            try {
                const res = await fetch('api_admin_casino.php?action=get_rules').then(r => r.json());

                if (!res.success) {
                    container.innerHTML = `<div class="card" style="color:#ef4444;padding:20px">
                ❌ Error: ${res.message || 'Could not load rules.'}
                <br><small style="color:var(--dim);margin-top:8px;display:block">Check that the casino_outcome_rules table exists in your database.<br>
                Run the SQL from <strong>casino_tables.sql</strong> in phpMyAdmin if needed.</small>
            </div>`;
                    return;
                }

                if (!res.rules || !res.rules.length) {
                    container.innerHTML = '<div class="card" style="text-align:center;color:var(--dim);padding:30px;font-size:13px">No rules set yet. Click <strong style="color:var(--acc)">+ New Rule</strong> to create a time-based outcome rule.</div>';
                    return;
                }

                container.innerHTML = `<div class="card">
            <div style="overflow-x:auto">
            <table class="tbl">
                <thead><tr>
                    <th>Game</th><th>Outcome</th>
                    <th>Start Time</th><th>End Time</th>
                    <th>Priority</th><th>Actions</th>
                </tr></thead>
                <tbody>${res.rules.map(r => `<tr>
                    <td style="font-weight:700">${r.target_game === 'all' ? '🎮 ALL GAMES' : r.target_game.replace('_', ' ').toUpperCase()}</td>
                    <td><span class="badge ${r.forced_outcome === 'win' ? 'b-won' : 'b-lost'}">${r.forced_outcome.toUpperCase()}</span></td>
                    <td style="font-size:11px;color:var(--dim)">${r.start_time}</td>
                    <td style="font-size:11px;color:var(--dim)">${r.end_time}</td>
                    <td style="color:var(--acc);font-weight:700">${r.priority}</td>
                    <td><button class="btn btn-red btn-sm" onclick="deleteCasinoRule(${r.id})">Delete</button></td>
                </tr>`).join('')}
                </tbody>
            </table>
            </div>
        </div>`;
            } catch (e) {
                console.error('loadCasinoRules error:', e);
                container.innerHTML = `<div class="card" style="color:#ef4444;padding:20px">
            ❌ Failed to reach api_admin_casino.php<br>
            <small style="color:var(--dim)">${e.message}</small>
        </div>`;
            }
        }


        async function deleteCasinoRule(id) {
            if (!confirm('Are you sure you want to delete this rule?')) return;
            try {
                const res = await fetch('api_admin_casino.php', {
                    method: 'POST',
                    body: new URLSearchParams({ action: 'delete_rule', id: id })
                }).then(r => r.json());

                if (res.success) {
                    toast('Rule deleted');
                    loadCasinoRules();
                } else {
                    toast(res.message, true);
                }
            } catch (e) {
                toast('Error deleting rule', true);
            }
        }

        // ═══════════════════════════════════════════════════════════════════
        // AGENTS
        // ═══════════════════════════════════════════════════════════════════
        async function agentApi(body) {
            return api('api_sub_admin_admin.php', body);
        }

        const agentEsc = v => String(v ?? '').replace(/[&<>"']/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
        let currentAgentListFilter = 'all';

        function setAgentListFilter(filter, button = null) {
            currentAgentListFilter = ['all', 'unpaid', 'commission'].includes(filter) ? filter : 'all';
            document.querySelectorAll('[data-agent-filter]').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.agentFilter === currentAgentListFilter);
            });

            let visible = 0;
            document.querySelectorAll('#agents-list .agent-row').forEach(card => {
                const show = currentAgentListFilter === 'all'
                    || (currentAgentListFilter === 'unpaid' && card.dataset.hasUnpaid === '1')
                    || (currentAgentListFilter === 'commission' && card.dataset.hasTodayCommission === '1');
                card.style.display = show ? '' : 'none';
                if (show) visible++;
            });

            const empty = document.getElementById('agents-filter-empty');
            if (empty) {
                const messages = {
                    unpaid: 'No agents currently have an unpaid commission balance.',
                    commission: 'No agents have earned commission today.',
                    all: 'No registered agents found.'
                };
                empty.textContent = messages[currentAgentListFilter];
                empty.style.display = visible === 0 ? '' : 'none';
            }
        }

        function switchAgentTab(tab, el) {
            ['list', 'history'].forEach(t => {
                const el2 = document.getElementById('ag-tab-' + t);
                if (el2) el2.style.display = t === tab ? '' : 'none';
            });
            document.querySelectorAll('#panel-agents .inner-tab').forEach(t => t.classList.remove('active'));
            if (el) el.classList.add('active');
            if (tab === 'list') loadAgents();
            if (tab === 'history') loadAgentDailyHistory();
        }

        let agentHistoryDays = 31;
        let agentHistoryLoaded = false;

        function agentHistoryMoney(rows) {
            const list = Array.isArray(rows) ? rows : [];
            if (!list.length) return '<span style="color:var(--dim)">—</span>';
            return list.map(row => {
                const code = row.currency_code || row.currency || 'GHS';
                const symbol = row.symbol || CURRENCY_SYMBOLS[code] || code;
                const amount = parseFloat(row.amount || 0);
                return `<div style="white-space:nowrap;font-weight:850">${agentEsc(symbol)} ${amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} <span style="font-size:9px;color:var(--dim)">${agentEsc(code)}</span></div>`;
            }).join('');
        }

        function agentHistoryPayout(agent) {
            const name = agent.payout_name || agent.username || 'Not set';
            const network = agent.payout_network || 'Not set';
            const number = agent.payout_number || 'Not set';
            const isMissing = !agent.payout_network && !agent.payout_number;
            return `
                <div style="margin-top:6px;padding-top:6px;border-top:1px solid rgba(148,163,184,.18);font-size:10px;line-height:1.45;color:${isMissing ? '#fbbf24' : 'var(--dim)'}">
                    <div><strong style="color:var(--txt)">Payout Name:</strong> ${agentEsc(name)}</div>
                    <div><strong style="color:var(--txt)">Network:</strong> ${agentEsc(network)}</div>
                    <div><strong style="color:var(--txt)">Payout Number:</strong> <span style="font-family:monospace;color:var(--acc);font-weight:850">${agentEsc(number)}</span></div>
                </div>
            `;
        }

        async function loadAgentDailyHistory(force = false) {
            if (agentHistoryLoaded && !force) return;
            const el = document.getElementById('agents-daily-history');
            if (!el) return;
            el.innerHTML = '<div style="color:var(--dim);font-size:13px;padding:16px;text-align:center"><i class="fa-solid fa-spinner fa-spin"></i> Loading everyday agent history...</div>';
            const d = await agentApi({ action: 'get_agents_daily_history', days: agentHistoryDays });
            if (!d.success) {
                el.innerHTML = `<div style="color:#ef4444;font-size:13px">${agentEsc(d.message || 'Could not load agent history')}</div>`;
                return;
            }

            const days = Array.isArray(d.days) ? d.days : [];
            if (!days.length) {
                el.innerHTML = '<div style="color:var(--dim);font-size:13px">No agent history found.</div>';
                return;
            }

            el.innerHTML = days.map((day, index) => {
                const agents = Array.isArray(day.agents) ? day.agents : [];
                const totalCommission = parseFloat(day.total_commission_usd || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                const totalReceived = parseFloat(day.total_deposits_usd || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                const rows = agents.map(agent => {
                    const commissionUsd = parseFloat(agent.day_commission_usd || 0);
                    const unpaidCurrencies = Array.isArray(agent.day_unpaid_by_currency) ? agent.day_unpaid_by_currency : [];
                    const state = unpaidCurrencies.length
                        ? unpaidCurrencies.map((item, currencyIndex) => {
                            const code = item.currency_code || item.currency || 'GHS';
                            const label = unpaidCurrencies.length > 1 ? `Mark ${agentEsc(code)} Paid` : 'Mark Paid';
                            return `<button class="btn btn-acc btn-sm" style="font-size:10px;padding:5px 9px;${currencyIndex ? 'margin-top:5px;' : ''}" data-agent-id="${parseInt(agent.id || 0)}" data-agent-name="${encodeURIComponent(agent.username || '')}" data-date="${agentEsc(day.date || '')}" data-currency="${agentEsc(code)}" onclick="markAgentHistoryPaid(this)"><i class="fa-solid fa-check"></i> ${label}</button>`;
                        }).join('')
                        : '<span class="comm-badge-paid">PAID</span>';
                    const safeAgentName = String(agent.username || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'");
                    return `<tr>
                        <td>
                            <button class="agent-history-link" onclick="openAgentDailyPayments(${parseInt(agent.id || 0)}, '${safeAgentName}')">${agentEsc(agent.username || 'Unnamed agent')}</button>
                            <div style="color:var(--dim);font-size:10px;margin-top:3px">${agentEsc(agent.email || 'No email')}</div>
                        </td>
                        <td>
                            <div style="font-weight:800">${agentEsc(agent.payout_name || agent.username || '—')}</div>
                            <div style="font-family:monospace;color:var(--acc);font-size:10px;margin-top:3px">${agentEsc(agent.referral_code || '—')}</div>
                            ${agentHistoryPayout(agent)}
                        </td>
                        <td>${agentHistoryMoney(agent.day_deposits_by_currency)}</td>
                        <td style="color:var(--acc)">${agentHistoryMoney(agent.day_commission_by_currency)}</td>
                        <td>${state}</td>
                    </tr>`;
                }).join('');

                return `<details class="agent-history-day" ${index === 0 ? 'open' : ''}>
                    <summary class="agent-history-summary">
                        <div>
                            <div class="agent-history-title">${agentEsc(day.weekday || '')} Agents Amount Received${day.is_today ? ' — Today' : ''}</div>
                            <div class="agent-history-date">${agentEsc(day.display_date || day.date || '')}</div>
                        </div>
                        <div class="agent-history-total">Commission $${totalCommission}<br><span style="color:var(--dim);font-size:10px">Received $${totalReceived}</span></div>
                    </summary>
                    <div class="agent-history-table-wrap">
                        <table class="agent-history-table">
                            <thead><tr><th>Agent / Email</th><th>Reference / Payout Details</th><th>Amount Received</th><th>Day Commission</th><th>Status</th></tr></thead>
                            <tbody>${rows}</tbody>
                        </table>
                    </div>
                </details>`;
            }).join('');
            agentHistoryLoaded = true;
        }

        async function markAgentHistoryPaid(button) {
            const agentId = parseInt(button.dataset.agentId || '0');
            const agentName = decodeURIComponent(button.dataset.agentName || '');
            const date = button.dataset.date || '';
            const currency = button.dataset.currency || 'GHS';
            if (!agentId || !date) return toast('Invalid agent payment record', true);
            if (!confirm(`Mark ${agentName}'s ${currency} commission for ${date} as paid?`)) return;

            const oldHtml = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';
            const d = await agentApi({
                action: 'mark_daily_commission_paid',
                agent_id: agentId,
                date,
                currency
            });
            if (!d.success) {
                button.disabled = false;
                button.innerHTML = oldHtml;
                return toast(d.message || 'Could not mark this commission paid', true);
            }

            toast(`${agentName}'s ${currency} commission is marked paid`);
            const statusCell = button.closest('td');
            button.remove();
            if (statusCell && !statusCell.querySelector('button[data-agent-id]')) {
                statusCell.innerHTML = '<span class="comm-badge-paid">PAID</span>';
            }
        }

        function loadMoreAgentHistory() {
            agentHistoryDays = Math.min(365, agentHistoryDays + 31);
            agentHistoryLoaded = false;
            loadAgentDailyHistory(true);
            const button = document.getElementById('agent-history-more');
            if (button && agentHistoryDays >= 365) button.style.display = 'none';
        }

        async function loadAgents() {
            agentHistoryLoaded = false;
            const d = await agentApi({ action: 'list_sub_admins' });
            const el = document.getElementById('agents-list');
            if (!d.success) { el.innerHTML = '<div style="color:#ef4444;font-size:13px">' + (d.message || 'Error') + '</div>'; return; }

            // Update stat cards
            const total = d.agents.length;
            const active = d.agents.filter(a => a.is_active == 1).length;
            const unpaidCount = d.agents.filter(a => parseFloat(a.unpaid_commission_usd || 0) > 0.0001).length;
            const commissionTodayCount = d.agents.filter(a => parseFloat(a.today_earned_usd || 0) > 0.0001).length;
            const todayCommUsd = d.agents.reduce((s, a) => s + parseFloat(a.today_earned_usd ?? 0), 0);
            document.getElementById('ag-count').textContent = total;
            document.getElementById('ag-active').textContent = active;
            document.getElementById('ag-today-comm').textContent = '$' + todayCommUsd.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            document.getElementById('ag-admin-today').textContent = '$' + parseFloat(d.today_admin_earning_usd || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            const allCountEl = document.getElementById('agent-filter-all-count');
            const unpaidCountEl = document.getElementById('agent-filter-unpaid-count');
            const commissionCountEl = document.getElementById('agent-filter-commission-count');
            if (allCountEl) allCountEl.textContent = `(${total})`;
            if (unpaidCountEl) unpaidCountEl.textContent = `(${unpaidCount})`;
            if (commissionCountEl) commissionCountEl.textContent = `(${commissionTodayCount})`;
            const pauseButton = document.getElementById('agent-commission-pause-btn');
            if (pauseButton) {
                const paused = d.commission_paused === true || d.commission_paused === 1 || d.commission_paused === '1';
                pauseButton.dataset.paused = paused ? '1' : '0';
                pauseButton.innerHTML = paused
                    ? '<i class="fa-solid fa-play"></i> Resume Agent Commission'
                    : '<i class="fa-solid fa-pause"></i> Pause Agent Commission';
                pauseButton.style.color = paused ? '#34d399' : '#fbbf24';
                pauseButton.style.borderColor = paused ? 'rgba(52,211,153,.4)' : 'rgba(251,191,36,.38)';
                pauseButton.style.background = paused ? 'rgba(52,211,153,.13)' : 'rgba(251,191,36,.14)';
            }

            if (!d.agents.length) { el.innerHTML = '<div style="color:var(--dim);font-size:13px">No agents yet.</div>'; return; }

            el.innerHTML = `<div class="agent-list">` +
                d.agents.map(a => {
                    const isActive    = a.is_active == 1;
                    const statusBadge = isActive
                        ? '<span class="agent-status active">ACTIVE</span>'
                        : '<span class="agent-status disabled">DISABLED</span>';
                    const toggleLabel = isActive ? 'Disable' : 'Enable';
                    const toggleColor = isActive ? 'btn-ghost' : 'btn-acc';
                    const safeName    = String(a.username || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'");
                    const todayUsd    = parseFloat(a.today_earned_usd || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    const outstandingUnpaidUsd = parseFloat(a.unpaid_commission_usd || 0);
                    const hasOutstandingUnpaid = outstandingUnpaidUsd > 0.0001;
                    const hasTodayCommission = parseFloat(a.today_earned_usd || 0) > 0.0001;
                    const oldUnpaidUsd = parseFloat(a.old_unpaid_commission_usd || 0);
                    const yesterdayUnpaidUsd = parseFloat(a.yesterday_unpaid_commission_usd || 0);
                    const hasPayableUnpaid = oldUnpaidUsd > 0.0001;
                    const unpaidTitle = yesterdayUnpaidUsd > 0.0001
                        ? `Yesterday unpaid: $${yesterdayUnpaidUsd.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
                        : `Previous unpaid: $${oldUnpaidUsd.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
                    const unpaidBadge = hasPayableUnpaid
                        ? `<span class="comm-badge-unpaid" title="${agentEsc(unpaidTitle)}">UNPAID</span>`
                        : '';
                    return `<div class="agent-row" id="agent-card-${a.id}" data-has-unpaid="${hasOutstandingUnpaid ? '1' : '0'}" data-has-today-commission="${hasTodayCommission ? '1' : '0'}">
                        <!-- Compact header -->
                        <div class="agent-row-header">
                            <div class="agent-row-main" style="flex:1;min-width:160px" role="button" tabindex="0"
                                onclick="openAgentDailyPayments(${a.id}, '${agentEsc(a.username || '')}')"
                                onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();openAgentDailyPayments(${a.id}, '${agentEsc(a.username || '')}');}">
                                <div class="agent-row-name" style="display:flex;align-items:center;gap:7px;flex-wrap:wrap">${agentEsc(a.username || '')}${unpaidBadge}</div>
                                <div class="agent-row-email">${agentEsc(a.email || '')}</div>
                            </div>
                            ${statusBadge}
                            <div class="agent-row-col" style="min-width:90px">
                                <div class="agent-row-label">Ref Code</div>
                                <div class="agent-row-value agent-row-ref">${agentEsc(a.referral_code || '')}</div>
                            </div>
                            <div class="agent-row-col" style="min-width:110px">
                                <div class="agent-row-label">Commission %</div>
	                                <input class="inp agent-row-pct" type="number" step="0.01" min="0" max="100" id="ag-edit-pct-${a.id}" value="${parseFloat(a.commission_pct || 0).toFixed(2)}" onclick="event.stopPropagation()" oninput="queueAgentConfigSave(${a.id})" onchange="queueAgentConfigSave(${a.id}, true)">
                            </div>
                            <div class="agent-row-col" style="min-width:110px">
                                <div class="agent-row-label">Today Commission</div>
                                <div class="agent-row-value agent-row-commission">$${todayUsd}</div>
                            </div>
                            <div class="agent-row-actions">
                                <button class="btn btn-acc btn-sm" onclick="event.stopPropagation();saveAgentConfig(${a.id})"><i class="fa-solid fa-floppy-disk"></i> Save</button>
                                <button class="btn ${toggleColor} btn-sm" onclick="event.stopPropagation();toggleAgent(${a.id},${isActive ? 0 : 1})">${toggleLabel}</button>
                                <button class="btn btn-ghost btn-sm" style="color:#ef4444;border-color:rgba(239,68,68,.3)" onclick="event.stopPropagation();deleteAgent(${a.id},'${safeName}')">Delete</button>
                            </div>
                        </div>
                    </div>`;
                }).join('') + '</div>';
            setAgentListFilter(currentAgentListFilter);
        }

	        const agentConfigSaveTimers = {};

	        function queueAgentConfigSave(id, immediate = false) {
	            clearTimeout(agentConfigSaveTimers[id]);
	            agentConfigSaveTimers[id] = setTimeout(() => saveAgentConfig(id, true), immediate ? 80 : 650);
	        }

	        async function saveAgentConfig(id, silent = false) {
	            const pct = parseFloat(document.getElementById('ag-edit-pct-' + id)?.value || '0');
	            if (isNaN(pct) || pct < 0 || pct > 100) return toast('Commission must be between 0 and 100', true);
	            const card = document.getElementById('agent-card-' + id);
	            if (card) card.dataset.saving = '1';
	            const d = await agentApi({
	                action: 'update_sub_admin_config',
	                id,
	                commission_pct: pct,
	            });
	            if (d.success) {
	                if (card) {
	                    card.dataset.saving = '0';
	                    card.style.borderColor = 'rgba(52,211,153,.36)';
	                    setTimeout(() => { if (card) card.style.borderColor = ''; }, 900);
	                }
	                if (!silent) toast(d.message || 'Agent settings saved');
	            } else {
	                if (card) card.dataset.saving = '0';
	                toast(d.message || 'Error saving agent settings', true);
	            }
	        }

        async function saveDefaultAgentPct() {
            const pct = document.getElementById('ag-default-pct').value;
            const d = await agentApi({ action: 'save_default_commission', pct });
            toast(d.message || (d.success ? 'Saved' : 'Error'), !d.success);
        }

        async function createAgent() {
            const username = document.getElementById('ag-username').value.trim();
            const password = document.getElementById('ag-password').value;
            const email = document.getElementById('ag-email').value.trim();
            const refCode = document.getElementById('ag-refcode').value.trim().toUpperCase();
            const pct = document.getElementById('ag-pct').value;
            const msg = document.getElementById('ag-create-msg');

            if (!username || !password) { msg.textContent = 'Username and password required'; msg.style.color = '#ef4444'; return; }

            const d = await agentApi({ action: 'create_sub_admin', username, password, email, ref_code: refCode, pct });
            msg.textContent = d.message || (d.success ? 'Created!' : 'Error');
            msg.style.color = d.success ? 'var(--acc)' : '#ef4444';
            if (d.success) {
                // Show ref code clearly
                msg.innerHTML = `<strong style="color:var(--acc)">${d.message}</strong><br><small style="color:var(--dim)">Share link: ${location.origin}/register.php?ref=${d.ref_code || refCode}</small>`;
                document.getElementById('ag-username').value = '';
                document.getElementById('ag-password').value = '';
                document.getElementById('ag-refcode').value = '';
                loadAgents();
            }
        }

        async function promoteUser() {
            const userId = document.getElementById('promo-uid').value;
            const pct = document.getElementById('promo-pct').value;
            const ref = document.getElementById('promo-ref').value.trim().toUpperCase();
            const msg = document.getElementById('promo-msg');
            if (!userId) { msg.textContent = 'Enter a user ID'; msg.style.color = '#ef4444'; return; }
            const d = await agentApi({ action: 'promote_user', user_id: userId, pct, ref_code: ref });
            msg.textContent = d.message || (d.success ? 'Promoted!' : 'Error');
            msg.style.color = d.success ? 'var(--acc)' : '#ef4444';
            if (d.success) {
                msg.innerHTML = `<strong style="color:var(--acc)">${d.message}</strong><br><small style="color:var(--dim)">Share link: ${location.origin}/register.php?ref=${d.ref_code || ref}</small>`;
                loadAgents();
            }
        }

        async function toggleAgent(id, active) {
            const d = await agentApi({ action: 'toggle_sub_admin', id, active });
            if (d.success) loadAgents();
            else toast('Error toggling agent', true);
        }

        async function deleteAgent(id, name) {
            if (!confirm(`Delete agent "${name}"? Their referred users will be unlinked but not deleted.`)) return;
            const d = await agentApi({ action: 'delete_sub_admin', id });
            if (d.success) { toast('Agent deleted'); loadAgents(); }
            else toast(d.message || 'Error deleting agent', true);
        }

        let _commissionPreviewRows = [];
        let _activePayoutBatchId = null;

        function currencyBreakdown(rows, amountField = 'amount', usdField = 'amount_usd') {
            const list = Array.isArray(rows) ? rows : [];
            if (!list.length) return '<div style="color:var(--dim);font-size:12px">—</div>';
            const usdTotal = list.reduce((s, r) => s + (parseFloat(r[usdField] || r.amount_usd || r.payout_amount_usd || 0) || 0), 0);
            return `<div style="font-weight:950;color:var(--acc);margin-bottom:4px">$${usdTotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</div>` +
                list.map(r => {
                    const cur = r.currency || r.currency_code || 'GHS';
                    const sym = r.symbol || r.currency_symbol || CURRENCY_SYMBOLS[cur] || cur;
                    const amt = parseFloat(r[amountField] ?? r.amount ?? 0) || 0;
                    return `<div style="display:flex;justify-content:space-between;gap:10px;min-width:118px;font-size:11px;color:var(--dim);margin-top:3px;padding:3px 0;border-top:1px solid rgba(239,68,68,.08)"><span style="font-weight:850;color:var(--text-main)">${agentEsc(cur)}</span><span>${sym} ${amt.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span></div>`;
                }).join('');
        }

        function money(v, currency = 'GHS') {
            if (v && typeof v === 'object') return dualFromPayload(v, 'amount', 'amount_usd');
            return dualMoney(v, currency);
        }

        function payoutMoney(row, amountField) {
            return dualFromPayload(row, amountField, amountField + '_usd');
        }

        function batchBadge(status) {
            const map = {
                Draft: 'rgba(148,163,184,.16);color:#cbd5e1',
                Locked: 'rgba(251,191,36,.16);color:#f59e0b',
                'Partially Paid': 'rgba(96,165,250,.16);color:#60a5fa',
                Paid: 'rgba(239,68,68,.16);color:#ef4444',
                Cancelled: 'rgba(239,68,68,.16);color:#ef4444',
                Skipped: 'rgba(148,163,184,.16);color:#94a3b8',
                Pending: 'rgba(251,191,36,.16);color:#f59e0b'
            };
            return `<span style="background:${map[status] || map.Pending};padding:2px 8px;border-radius:4px;font-size:10px;font-weight:800">${agentEsc(status)}</span>`;
        }

        function setDefaultCommissionPeriod() {
            const s = document.getElementById('pay-period-start');
            const e = document.getElementById('pay-period-end');
            if (!s || !e || s.value || e.value) return;
            const now = new Date();
            const first = new Date(now.getFullYear(), now.getMonth(), 1);
            const pad = n => String(n).padStart(2, '0');
            s.value = `${first.getFullYear()}-${pad(first.getMonth() + 1)}-${pad(first.getDate())}`;
            e.value = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
        }

        async function loadCommissionSettlement() {
            setDefaultCommissionPeriod();
            await loadCommissionBatches();
        }

        async function repairSubadminDeposits() {
            if (!confirm("Restore all of today's cleared earnings plus every deposit and commission withheld during commission pause?\n\nThis is a one-time recovery. The pause setting will remain unchanged.")) return;
            const el = document.getElementById('commission-preview');
            if (el) el.innerHTML = '<div style="color:var(--dim);font-size:13px">Restoring paused payments and repairing subadmin commission records...</div>';
            const d = await api('api_admin_settle.php', { action: 'repair_subadmin_deposit_accounting', limit: 50000 });
            if (d.success) {
                const restored = parseInt(d.stats?.paused_restore?.restored || 0);
                const alreadyRecorded = parseInt(d.stats?.paused_restore?.already_recorded || 0);
                const failed = parseInt(d.stats?.paused_restore?.failed || 0);
                const todayRestored = !!d.stats?.today_cycle_restore?.restored;
                const inserted = parseInt(d.stats?.commission_stats?.inserted || 0);
                const scanned = parseInt(d.stats?.commission_stats?.scanned || 0);
                toast(`Repair complete: today's earnings restored, ${restored} paused payments recovered`);
                if (el) el.innerHTML = `<div style="color:${failed ? '#f59e0b' : 'var(--acc)'};font-size:13px">Repair complete. ${todayRestored ? "Today's cleared earnings now include everything since midnight." : "Today's earnings already included everything since midnight."} Restored ${restored} paused payments, found ${alreadyRecorded} already recorded, and added ${inserted} other missing commissions from ${scanned} scanned deposits.${failed ? ` ${failed} record(s) could not be restored.` : ''}</div>`;
                loadAgents();
                loadCommissionBatches();
            } else {
                toast(d.message || 'Repair failed', true);
                if (el) el.innerHTML = `<div style="color:#ef4444;font-size:13px">${agentEsc(d.message || 'Repair failed')}</div>`;
            }
        }

        async function resetTodayEarnings(button) {
            if (!confirm("Clear today's displayed earnings for admin and all agents?\n\nExisting deposits, commissions, balances, and payment history will be preserved. New deposits received after this reset will start a fresh payment cycle.")) return;
            const original = button ? button.innerHTML : '';
            if (button) {
                button.disabled = true;
                button.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Clearing...';
            }
            try {
                const d = await agentApi({ action: 'reset_today_earnings', confirm: 'RESET TODAY' });
                if (!d.success) {
                    toast(d.message || "Could not clear today's earnings", true);
                    return;
                }
                toast(d.message || 'New payment cycle started');
                await loadAgents();
            } finally {
                if (button) {
                    button.disabled = false;
                    button.innerHTML = original;
                }
            }
        }

        async function toggleAgentCommissionPause(button) {
            const currentlyPaused = button && button.dataset.paused === '1';
            const nextPaused = !currentlyPaused;
            const warning = nextPaused
                ? 'Pause agent commission recording?\n\nDeposits completed while paused will not appear as agent commission, will not increase agent balances, and will not be restored when commission recording resumes.'
                : 'Resume agent commission recording?\n\nDeposits completed during the pause remain excluded. Only new completed deposits will earn agent commission.';
            if (!confirm(warning)) return;

            const original = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Updating...';
            try {
                const d = await agentApi({ action: 'set_commission_pause', paused: nextPaused ? 1 : 0 });
                if (!d.success) {
                    toast(d.message || 'Could not change commission status', true);
                    button.innerHTML = original;
                    return;
                }
                toast(d.message);
                await loadAgents();
            } catch (error) {
                toast('Could not change commission status', true);
                button.innerHTML = original;
            } finally {
                button.disabled = false;
            }
        }

        async function clearAllAgentBalances() {
            const typed = prompt('This clears every agent/subadmin commission balance and deposit total. Type RESET to continue.');
            if (typed === null) return;
            if (typed.trim().toUpperCase() !== 'RESET') {
                toast('Balance reset cancelled', true);
                return;
            }

            const el = document.getElementById('commission-preview');
            if (el) el.innerHTML = '<div style="color:var(--dim);font-size:13px">Clearing all agent balances...</div>';
            const d = await agentApi({ action: 'clear_subadmin_balances', confirm: 'RESET' });
            if (d.success) {
                const stats = d.stats || {};
                toast(d.message || 'All agent balances cleared');
                if (el) {
                    el.innerHTML = `<div style="color:var(--acc);font-size:13px">Balances cleared. Agents reset: ${parseInt(stats.agents_reset || 0)}, commission rows reset: ${parseInt(stats.commission_rows_reset || 0)}.</div>`;
                }
                loadAgents();
                loadCommissionBatches();
            } else {
                toast(d.message || 'Could not clear balances', true);
                if (el) el.innerHTML = `<div style="color:#ef4444;font-size:13px">${agentEsc(d.message || 'Could not clear balances')}</div>`;
            }
        }

        async function clearUsersAndAgents() {
            const typed = prompt('This deletes all users, all agents/subadmins, tickets, transactions, commissions and game bet history. Type RESET to continue.');
            if (typed === null) return;
            if (typed.trim().toUpperCase() !== 'RESET') {
                toast('Users and agents reset cancelled', true);
                return;
            }
            const finalSure = confirm('Final confirmation: clear users and agents now? This cannot be undone from the site.');
            if (!finalSure) return;

            const el = document.getElementById('agents-list');
            if (el) el.innerHTML = '<div style="color:var(--dim);font-size:13px">Clearing users and agents...</div>';
            const d = await agentApi({ action: 'clear_users_and_agents', confirm: 'RESET' });
            if (d.success) {
                const stats = d.stats || {};
                toast(d.message || 'Users and agents cleared');
                if (el) {
                    el.innerHTML = `<div style="color:var(--acc);font-size:13px">Reset complete. Users deleted: ${parseInt(stats.users_deleted || 0)}, agents deleted: ${parseInt(stats.agents_deleted || 0)}, tickets deleted: ${parseInt(stats.tickets_deleted || 0)}, transactions deleted: ${parseInt(stats.transactions_deleted || 0)}.</div>`;
                }
                loadAgents();
                loadCommissionBatches();
            } else {
                toast(d.message || 'Could not clear users and agents', true);
                if (el) el.innerHTML = `<div style="color:#ef4444;font-size:13px">${agentEsc(d.message || 'Could not clear users and agents')}</div>`;
            }
        }

        async function previewCommissionBatch() {
            const el = document.getElementById('commission-preview');
            el.innerHTML = '<div style="color:var(--dim);font-size:13px">Loading unpaid commissions...</div>';
            const d = await agentApi({
                action: 'preview_commission_batch',
                period_start: document.getElementById('pay-period-start').value,
                period_end: document.getElementById('pay-period-end').value
            });
            if (!d.success) { el.innerHTML = `<div style="color:#ef4444;font-size:13px">${agentEsc(d.message || 'Error')}</div>`; return; }
            _commissionPreviewRows = d.rows || [];
            if (!_commissionPreviewRows.length) {
                el.innerHTML = '<div style="color:var(--dim);font-size:13px">No unpaid commissions found for this period.</div>';
                return;
            }
            const totalUsd = _commissionPreviewRows.reduce((s, r) => s + parseFloat(r.commission_total_usd || 0), 0);
	        el.innerHTML = `<div style="display:flex;justify-content:space-between;gap:10px;align-items:center;margin-bottom:8px">
	            <div style="font-size:13px;color:var(--dim)">Agents: <strong style="color:var(--txt)">${_commissionPreviewRows.length}</strong></div>
	            <div style="font-size:13px;color:var(--dim)">Commission Total: <strong style="color:var(--acc)">$${totalUsd.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</strong></div>
	        </div>
            <div style="overflow-x:auto"><table class="tbl" style="min-width:900px">
                <thead><tr><th>Agent</th><th>Rows</th><th>Deposits</th><th>Commission</th><th>Adjustment</th><th>Adjustment Note</th><th>Payable</th></tr></thead>
	                <tbody>${_commissionPreviewRows.map(r => {
	                    const id = parseInt(r.sub_admin_id);
	                    const currency = String(r.currency_code || r.currency || 'GHS').toUpperCase();
	                    const key = `${id}-${currency}`;
	                    return `<tr>
	                        <td><strong>${agentEsc(r.username)}</strong><br><span style="font-size:11px;color:var(--dim);font-family:monospace">${agentEsc(r.referral_code)} · ${agentEsc(currency)}</span></td>
	                        <td>${parseInt(r.commission_count || 0)}</td>
	                        <td>${dualFromPayload(r, 'deposit_total', 'deposit_total_usd')}</td>
	                        <td style="color:var(--acc);font-weight:800">${dualFromPayload(r, 'commission_total', 'commission_total_usd')}</td>
	                        <td><input class="inp" type="number" step="0.01" id="pay-adj-${key}" value="0.00" oninput="updatePreviewPayable('${key}', ${parseFloat(r.commission_total || 0)}, '${currency}')" style="width:110px;padding:6px 8px;font-size:12px"></td>
	                        <td><input class="inp" id="pay-adj-note-${key}" placeholder="Required if adjusted" style="min-width:180px;padding:6px 8px;font-size:12px"></td>
	                        <td id="pay-payable-${key}" style="font-weight:800">${dualFromPayload(r, 'commission_total', 'commission_total_usd')}</td>
	                    </tr>`;
	                }).join('')}</tbody>
	        </table></div>`;
	    }

	    function updatePreviewPayable(key, total, currency = 'GHS') {
	        const adj = parseFloat(document.getElementById('pay-adj-' + key)?.value || '0') || 0;
	        const el = document.getElementById('pay-payable-' + key);
	        if (el) el.innerHTML = dualMoney(total + adj, currency);
	    }

        async function createCommissionBatch() {
            if (!_commissionPreviewRows.length) {
                await previewCommissionBatch();
                if (!_commissionPreviewRows.length) return;
            }
	            const adjustments = _commissionPreviewRows.map(r => {
	                const id = parseInt(r.sub_admin_id);
	                const currency = String(r.currency_code || r.currency || 'GHS').toUpperCase();
	                const key = `${id}-${currency}`;
	                return {
	                    sub_admin_id: id,
	                    currency_code: currency,
	                    adjustment_amount: parseFloat(document.getElementById('pay-adj-' + key)?.value || '0') || 0,
	                    adjustment_note: document.getElementById('pay-adj-note-' + key)?.value || ''
	                };
	            });
            const bad = adjustments.find(a => Math.abs(a.adjustment_amount) > 0 && !a.adjustment_note.trim());
            if (bad) return toast('Adjustment note is required when an amount is changed', true);
            if (!confirm('Create a draft payout batch from these unpaid commissions?')) return;
            const d = await agentApi({
                action: 'create_commission_batch',
                period_start: document.getElementById('pay-period-start').value,
                period_end: document.getElementById('pay-period-end').value,
                notes: document.getElementById('pay-batch-notes').value,
                adjustments: JSON.stringify(adjustments)
            });
            if (!d.success) return toast(d.message || 'Could not create batch', true);
            toast('Draft batch created');
            _commissionPreviewRows = [];
            document.getElementById('commission-preview').innerHTML = '<div style="color:var(--dim);font-size:13px">Draft batch created. Open it below to review, lock, and record payments.</div>';
            await loadCommissionBatches();
            viewCommissionBatch(d.batch_id);
        }

        async function loadCommissionBatches() {
            const el = document.getElementById('commission-batches');
            if (!el) return;
            const d = await agentApi({ action: 'get_commission_batches' });
            if (!d.success) { el.innerHTML = `<div style="color:#ef4444;font-size:13px">${agentEsc(d.message || 'Error')}</div>`; return; }
            const batches = d.batches || [];
            if (!batches.length) {
                el.innerHTML = '<div style="color:var(--dim);font-size:13px">No payout batches yet.</div>';
                return;
            }
            el.innerHTML = `<div style="overflow-x:auto"><table class="tbl" style="min-width:880px">
                <thead><tr><th>Batch</th><th>Period</th><th>Status</th><th>Items</th><th>Commission</th><th>Adjustments</th><th>Payable</th><th>Created</th><th>Actions</th></tr></thead>
                <tbody>${batches.map(b => `<tr>
                    <td style="font-family:monospace;font-weight:800;color:var(--acc)">${agentEsc(b.batch_code)}</td>
                    <td style="font-size:12px">${agentEsc(b.period_start || 'All')} → ${agentEsc(b.period_end || 'All')}</td>
                    <td>${batchBadge(b.status)}</td>
                    <td>${parseInt(b.paid_count || 0)} / ${parseInt(b.item_count || 0)} paid</td>
	                    <td>${currencyBreakdown(b.currency_totals, 'commission_total', 'commission_total_usd')}</td>
	                    <td>${currencyBreakdown(b.currency_totals, 'adjustment_amount', 'adjustment_amount_usd')}</td>
	                    <td style="font-weight:800;color:var(--acc)">${currencyBreakdown(b.currency_totals, 'payout_amount', 'payout_amount_usd')}</td>
                    <td style="font-size:11px;color:var(--dim)">${agentEsc(b.created_at || '')}</td>
                    <td style="display:flex;gap:6px;flex-wrap:wrap">
                        <button class="btn btn-acc btn-sm" onclick="viewCommissionBatch(${parseInt(b.id)})">Open</button>
                        <button class="btn btn-ghost btn-sm" onclick="exportCommissionBatch(${parseInt(b.id)})">CSV</button>
                    </td>
                </tr>`).join('')}</tbody>
            </table></div>`;
        }

        async function viewCommissionBatch(id) {
            _activePayoutBatchId = id;
            const wrap = document.getElementById('commission-batch-detail-wrap');
            const el = document.getElementById('commission-batch-detail');
            wrap.style.display = '';
            el.innerHTML = '<div style="color:var(--dim);font-size:13px">Loading batch...</div>';
            const d = await agentApi({ action: 'get_commission_batch', id });
            if (!d.success) { el.innerHTML = `<div style="color:#ef4444;font-size:13px">${agentEsc(d.message || 'Error')}</div>`; return; }
            const b = d.batch;
            const canLock = b.status === 'Draft';
            const canCancel = ['Draft', 'Locked'].includes(b.status);
            el.innerHTML = `<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:12px;flex-wrap:wrap">
                <div>
                    <div class="card-title" style="margin:0">${agentEsc(b.batch_code)} ${batchBadge(b.status)}</div>
                    <div style="font-size:12px;color:var(--dim);margin-top:5px">Period: ${agentEsc(b.period_start || 'All')} → ${agentEsc(b.period_end || 'All')}</div>
                    ${b.notes ? `<div style="font-size:12px;color:var(--dim);margin-top:4px">${agentEsc(b.notes)}</div>` : ''}
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    ${canLock ? `<button class="btn btn-acc btn-sm" onclick="lockCommissionBatch(${parseInt(b.id)})"><i class="fa-solid fa-lock"></i> Lock Batch</button>` : ''}
                    ${canCancel ? `<button class="btn btn-ghost btn-sm" style="color:#ef4444;border-color:rgba(239,68,68,.3)" onclick="cancelCommissionBatch(${parseInt(b.id)})">Cancel</button>` : ''}
                    <button class="btn btn-ghost btn-sm" onclick="exportCommissionBatch(${parseInt(b.id)})">Export CSV</button>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:repeat(3,minmax(120px,1fr));gap:10px;margin-bottom:12px">
	                <div class="stat-card"><div class="stat-lbl">Commission</div><div class="stat-val" style="font-size:18px">${currencyBreakdown(b.currency_totals, 'commission_total', 'commission_total_usd')}</div></div>
	                <div class="stat-card"><div class="stat-lbl">Adjustments</div><div class="stat-val" style="font-size:18px">${currencyBreakdown(b.currency_totals, 'adjustment_amount', 'adjustment_amount_usd')}</div></div>
	                <div class="stat-card"><div class="stat-lbl">Payable</div><div class="stat-val" style="font-size:18px;color:var(--acc)">${currencyBreakdown(b.currency_totals, 'payout_amount', 'payout_amount_usd')}</div></div>
            </div>
            <div style="overflow-x:auto"><table class="tbl" style="min-width:1120px">
                <thead><tr><th>Agent</th><th>Rows</th><th>Deposits Covered</th><th>Commission</th><th>Adjustment</th><th>Payable</th><th>Status</th><th>Payment Record</th><th>Actions</th></tr></thead>
                <tbody>${(d.items || []).map(item => renderPayoutItemRow(item, b.status)).join('')}</tbody>
            </table></div>`;
        }

        function renderPayoutItemRow(item, batchStatus) {
            const id = parseInt(item.id);
            const draft = batchStatus === 'Draft';
            const canPay = ['Locked', 'Partially Paid'].includes(batchStatus) && item.status === 'Pending';
            const paymentBlock = item.status === 'Paid'
                ? `<div style="font-size:12px">
                    <div><strong>${agentEsc(item.payment_method || '')}</strong> ${agentEsc(item.payment_reference || '')}</div>
                    <div style="color:var(--dim);font-size:11px">${agentEsc(item.paid_at || '')}</div>
                    ${item.payment_note ? `<div style="color:var(--dim);font-size:11px">${agentEsc(item.payment_note)}</div>` : ''}
                </div>`
                : canPay
                    ? `<div style="display:grid;grid-template-columns:100px 130px 160px;gap:6px">
                        <input class="inp" id="pay-method-${id}" placeholder="Method" value="Mobile Money" style="padding:6px 8px;font-size:12px">
                        <input class="inp" id="pay-ref-${id}" placeholder="Reference" style="padding:6px 8px;font-size:12px">
                        <input class="inp" id="pay-note-${id}" placeholder="Note" style="padding:6px 8px;font-size:12px">
                    </div>`
                    : '<span style="color:var(--dim);font-size:12px">—</span>';
            const adjustmentBlock = draft
                ? `<div style="display:grid;grid-template-columns:100px 170px;gap:6px">
                    <input class="inp" type="number" step="0.01" id="item-adj-${id}" value="${parseFloat(item.adjustment_amount || 0).toFixed(2)}" style="padding:6px 8px;font-size:12px">
                    <input class="inp" id="item-adj-note-${id}" value="${agentEsc(item.adjustment_note || '')}" placeholder="Note if adjusted" style="padding:6px 8px;font-size:12px">
                </div>`
	                : `<strong>${payoutMoney(item, 'adjustment_amount')}</strong>${item.adjustment_note ? `<br><span style="font-size:11px;color:var(--dim)">${agentEsc(item.adjustment_note)}</span>` : ''}`;
            const actions = draft
                ? `<button class="btn btn-acc btn-sm" onclick="savePayoutAdjustment(${id})">Save Adj.</button>`
                : canPay
                    ? `<button class="btn btn-acc btn-sm" onclick="markPayoutPaid(${id})">Mark Paid</button>`
                    : '—';
            return `<tr>
	                <td><strong>${agentEsc(item.username)}</strong><br><span style="font-size:11px;color:var(--dim);font-family:monospace">${agentEsc(item.referral_code)} · ${agentEsc(item.currency_code || item.currency || 'GHS')}</span></td>
	                <td>${parseInt(item.locked_commission_count || 0)}</td>
	                <td>${dualFromPayload(item, 'locked_deposit_total', 'locked_deposit_total_usd')}</td>
	                <td style="color:var(--acc);font-weight:800">${payoutMoney(item, 'commission_total')}</td>
	                <td>${adjustmentBlock}</td>
	                <td style="font-weight:800">${payoutMoney(item, 'payout_amount')}</td>
                <td>${batchBadge(item.status)}</td>
                <td>${paymentBlock}</td>
                <td>${actions}</td>
            </tr>`;
        }

        async function savePayoutAdjustment(itemId) {
            const amount = parseFloat(document.getElementById('item-adj-' + itemId)?.value || '0') || 0;
            const note = document.getElementById('item-adj-note-' + itemId)?.value || '';
            if (Math.abs(amount) > 0 && !note.trim()) return toast('Adjustment note is required', true);
            const d = await agentApi({ action: 'update_payout_item_adjustment', item_id: itemId, adjustment_amount: amount, adjustment_note: note });
            if (!d.success) return toast(d.message || 'Could not save adjustment', true);
            toast('Adjustment saved');
            await loadCommissionBatches();
            if (_activePayoutBatchId) viewCommissionBatch(_activePayoutBatchId);
        }

        async function lockCommissionBatch(id) {
            if (!confirm('Lock this batch? The selected unpaid commission rows will be reserved and cannot enter another payout.')) return;
            const d = await agentApi({ action: 'lock_commission_batch', id });
            if (!d.success) return toast(d.message || 'Could not lock batch', true);
            toast(`Batch locked (${d.locked_rows || 0} commission rows)`);
            await loadCommissionBatches();
            viewCommissionBatch(id);
        }

        async function cancelCommissionBatch(id) {
            if (!confirm('Cancel this batch? Unpaid locked commission rows will become available again.')) return;
            const d = await agentApi({ action: 'cancel_draft_batch', id });
            if (!d.success) return toast(d.message || 'Could not cancel batch', true);
            toast('Batch cancelled');
            await loadCommissionBatches();
            viewCommissionBatch(id);
        }

        async function markPayoutPaid(itemId) {
            const method = document.getElementById('pay-method-' + itemId)?.value || '';
            const ref = document.getElementById('pay-ref-' + itemId)?.value || '';
            const note = document.getElementById('pay-note-' + itemId)?.value || '';
            if (!method.trim() || (!ref.trim() && !note.trim())) return toast('Payment method plus reference or note is required', true);
            if (!confirm('Record this subadmin payout as paid?')) return;
            const d = await agentApi({ action: 'mark_payout_item_paid', item_id: itemId, payment_method: method, payment_reference: ref, payment_note: note });
            if (!d.success) return toast(d.message || 'Could not mark paid', true);
            toast('Payout recorded');
            await loadCommissionBatches();
            if (_activePayoutBatchId) viewCommissionBatch(_activePayoutBatchId);
        }

        function exportCommissionBatch(id) {
            window.open(`api_sub_admin_admin.php?action=export_commission_batch&id=${parseInt(id)}`, '_blank');
        }

        async function loadAgentWithdrawals() {
            const d = await agentApi({ action: 'get_sa_withdrawals' });
            const el = document.getElementById('ag-withdrawals-list');
            if (!d.success) { el.innerHTML = '<div style="color:#ef4444;font-size:13px">' + (d.message || 'Error') + '</div>'; return; }

            // Update pending count
            const pending = (d.withdrawals || []).filter(w => w.status === 'Pending').length;
            const pendingEl = document.getElementById('ag-wd-pending');
            if (pendingEl) pendingEl.textContent = pending;

            if (!d.withdrawals || !d.withdrawals.length) {
                el.innerHTML = '<div style="color:var(--dim);font-size:13px">No agent withdrawal requests yet.</div>'; return;
            }

            el.innerHTML = `<div style="overflow-x:auto"><table class="tbl" style="min-width:760px">
        <thead><tr><th>Agent</th><th>Amount</th><th>Network</th><th>Phone</th><th>Note / Ref</th><th>Status</th><th>Requested</th><th>Actions</th></tr></thead>
        <tbody>` +
                d.withdrawals.map(w => {
                    const bcolor = w.status === 'Completed' ? 'rgba(239,68,68,.15);color:#ef4444' : w.status === 'Rejected' ? 'rgba(239,68,68,.15);color:#ef4444' : 'rgba(251,191,36,.15);color:#f59e0b';
                    const actions = w.status === 'Pending'
                        ? `<button class="btn btn-acc btn-sm" style="margin-right:4px" onclick="approveAgentWd(${w.id})">Approve</button>
               <button class="btn btn-ghost btn-sm" style="color:#ef4444;border-color:rgba(239,68,68,.3)" onclick="rejectAgentWd(${w.id})">Reject</button>`
                        : '—';
                    return `<tr>
            <td style="font-weight:700">${w.sa_username}</td>
            <td>${dualFromPayload(w, 'amount', 'amount_usd')}</td>
            <td>${w.network}</td>
            <td style="font-family:monospace;font-size:12px">${w.phone || '—'}</td>
            <td style="font-size:11px;color:var(--dim)">${agentEsc(w.payment_reference || w.notes || w.payment_note || '—')}</td>
            <td><span style="background:${bcolor};padding:2px 8px;border-radius:4px;font-size:10px;font-weight:800">${w.status}</span></td>
            <td style="color:var(--dim);font-size:11px">${w.created_at}</td>
            <td>${actions}</td>
        </tr>`;
                }).join('') + '</tbody></table></div>';
        }

        async function approveAgentWd(id) {
            if (!confirm('Mark this withdrawal as completed?')) return;
            const paymentMethod = prompt('Payment method used? (optional)', '') || '';
            const paymentRef = prompt('Payment reference / receipt note? (optional)', '') || '';
            const d = await agentApi({ action: 'approve_sa_withdrawal', id, payment_method: paymentMethod, payment_reference: paymentRef });
            if (d.success) { toast('Withdrawal approved'); loadAgentWithdrawals(); }
            else toast(d.message || 'Error', true);
        }

        async function rejectAgentWd(id) {
            if (!confirm('Reject this withdrawal request? No commission balance will be deducted.')) return;
            const d = await agentApi({ action: 'reject_sa_withdrawal', id });
            if (d.success) { toast('Withdrawal rejected'); loadAgentWithdrawals(); }
            else toast(d.message || 'Error', true);
        }

        // ══════════════════════════════════════════════════════════════════
        // HISTORY
        // ══════════════════════════════════════════════════════════════════
        async function loadHistory() {
            const days = parseInt(document.getElementById('history-days-select')?.value || '30', 10);
            const wrap = document.getElementById('history-table-wrap');
            if (wrap) wrap.innerHTML = `<div style="padding:40px;text-align:center;color:var(--dim)"><i class="fa fa-spinner fa-spin"></i> Loading history...</div>`;

            const d = await api('api_admin_settle.php', { action: 'get_daily_history', days });
            if (!d.success) {
                if (wrap) wrap.innerHTML = `<div class="card" style="color:#ef4444;text-align:center;padding:30px">${d.message || 'Failed to load history'}</div>`;
                return;
            }

            const history = d.history || [];

            // Build totals across all days
            let grandDeposits = 0, grandComm = 0, grandAdmin = 0;
            history.forEach(row => {
                Object.values(row.currencies || {}).forEach(cur => {
                    grandDeposits += parseFloat(cur.deposits || 0);
                    grandComm += parseFloat(cur.client_commission || 0);
                    grandAdmin += parseFloat(cur.admin_earnings || 0);
                });
            });

            // We'll just show GHS equivalents in summary (simplification for mixed currencies)
            const fmtSummary = v => v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

            const depEl = document.getElementById('hist-total-dep');
            const commEl = document.getElementById('hist-total-comm');
            const adminEl = document.getElementById('hist-total-admin');
            if (depEl) depEl.textContent = fmtSummary(grandDeposits);
            if (commEl) commEl.textContent = fmtSummary(grandComm);
            if (adminEl) adminEl.textContent = fmtSummary(grandAdmin);

            if (!history.length) {
                if (wrap) wrap.innerHTML = `<div class="card" style="text-align:center;padding:40px;color:var(--dim)"><i class="fa-solid fa-inbox" style="font-size:28px;opacity:.3;margin-bottom:10px;display:block"></i><p style="font-size:13px;margin:0">No deposit history found for this period.</p></div>`;
                return;
            }

            // Build the table rows
            const rows = history.map(row => {
                const currencies = row.currencies || {};
                const curEntries = Object.entries(currencies);
                if (!curEntries.length) return '';

                const fmt = v => parseFloat(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

                // Format date nicely
                const dateObj = new Date(row.day + 'T00:00:00');
                const dateLabel = dateObj.toLocaleDateString('en-US', { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' });

                // Build per-currency rows
                return curEntries.map(([code, cur], i) => {
                    const dep = parseFloat(cur.deposits || 0);
                    const comm = parseFloat(cur.client_commission || 0);
                    const admin = parseFloat(cur.admin_earnings || 0);
                    const commPct = dep > 0 ? ((comm / dep) * 100).toFixed(1) : '0.0';
                    const adminPct = dep > 0 ? ((admin / dep) * 100).toFixed(1) : '0.0';

                    return `<tr>
                        ${i === 0 ? `<td rowspan="${curEntries.length}" style="font-weight:800;font-size:12px;vertical-align:middle;border-right:1px solid rgba(255,255,255,.05)">
                            <div>${dateLabel}</div>
                            <div style="font-size:10px;color:var(--dim);margin-top:2px">${row.deposit_count} deposit${row.deposit_count !== 1 ? 's' : ''}</div>
                        </td>` : ''}
                        <td style="font-size:11px;font-weight:800;color:var(--dim)">${code}</td>
                        <td style="font-weight:800;color:var(--acc)">${code} ${fmt(dep)}</td>
                        <td>
                            <div style="font-weight:800;color:#fbbf24">${code} ${fmt(comm)}</div>
                            <div style="font-size:10px;color:var(--dim)">${commPct}% of deposits</div>
                        </td>
                        <td>
                            <div style="font-weight:800;color:#34d399">${code} ${fmt(admin)}</div>
                            <div style="font-size:10px;color:var(--dim)">${adminPct}% of deposits</div>
                        </td>
                        <td>
                            <div style="display:flex;align-items:center;gap:6px">
                                <div style="flex:1;height:6px;background:rgba(255,255,255,.06);border-radius:3px;overflow:hidden">
                                    <div style="width:${Math.min(100, adminPct)}%;height:100%;background:linear-gradient(90deg,#34d399,#22c55e);border-radius:3px"></div>
                                </div>
                                <span style="font-size:10px;color:var(--dim);min-width:35px">${adminPct}%</span>
                            </div>
                        </td>
                    </tr>`;
                }).join('');
            }).join('');

            if (wrap) wrap.innerHTML = `
    <div class="card">
        <div style="overflow-x:auto">
        <table class="tbl">
            <thead><tr>
                <th>Date</th>
                <th>Currency</th>
                <th style="color:var(--acc)">💰 Total Deposits</th>
                <th style="color:#fbbf24">👥 Client Commission</th>
                <th style="color:#34d399">🏆 Admin Earnings</th>
                <th style="color:var(--dim)">Admin %</th>
            </tr></thead>
            <tbody>${rows}</tbody>
        </table>
        </div>
        <div style="font-size:11px;color:var(--dim);margin-top:12px;padding-top:10px;border-top:1px solid rgba(255,255,255,.05)">
            <i class="fa-solid fa-info-circle"></i> Admin Earnings = Total Deposits − Client (Agent) Commissions. Showing last ${d.days} days.
        </div>
    </div>`;
        }

    </script>
</body>

</html>
