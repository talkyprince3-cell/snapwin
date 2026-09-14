<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'db.php';

if (isset($_SESSION['sub_admin_id'])) {
    header("Location: sub_admin.php");
    exit;
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sub_admins (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        email VARCHAR(100) NULL,
        telegram_link VARCHAR(200) NULL,
        referral_code VARCHAR(20) NOT NULL UNIQUE,
        commission_pct DECIMAL(5,2) DEFAULT 70.00,
        balance DECIMAL(15,2) DEFAULT 0.00,
        total_earned DECIMAL(15,2) DEFAULT 0.00,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        can_control_site TINYINT(1) DEFAULT 0,
        phone VARCHAR(50) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("ALTER TABLE sub_admins MODIFY commission_pct DECIMAL(5,2) DEFAULT 70.00");
} catch (Throwable $e) {}

function ps_partner_code(PDO $pdo): string {
    do {
        $code = 'P' . strtoupper(substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 5));
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM sub_admins WHERE referral_code=?");
        $stmt->execute([$code]);
    } while ((int)$stmt->fetchColumn() > 0);
    return $code;
}

function ps_partner_set_session(array $sa): void {
    $_SESSION['sub_admin_id'] = (int)$sa['id'];
    $_SESSION['sub_admin_username'] = (string)$sa['username'];
    $_SESSION['sub_admin_ref'] = (string)$sa['referral_code'];
    unset($_SESSION['user_id']);
}

function ps_partner_password_matches(string $password, string $stored): bool {
    return password_verify($password, $stored) || hash_equals($stored, $password);
}

$mode = ($_GET['mode'] ?? '') === 'create' ? 'create' : 'login';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'login';

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $mode = 'create';

        if ($name === '' || $email === '' || strlen($password) < 6) {
            $error = 'Enter your name, email and a password with at least 6 characters.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid email address.';
        } else {
            $usernameBase = strtolower(preg_replace('/[^a-z0-9]+/i', '', $name));
            $usernameBase = $usernameBase !== '' ? substr($usernameBase, 0, 24) : strtok($email, '@');
            $username = $usernameBase;
            $n = 1;
            $check = $pdo->prepare("SELECT COUNT(*) FROM sub_admins WHERE username=? OR email=?");
            $check->execute([$username, $email]);
            while ((int)$check->fetchColumn() > 0) {
                $username = substr($usernameBase, 0, 20) . $n++;
                $check->execute([$username, $email]);
                if ($n > 99) break;
            }

            $emailCheck = $pdo->prepare("SELECT COUNT(*) FROM sub_admins WHERE email=?");
            $emailCheck->execute([$email]);
            if ((int)$emailCheck->fetchColumn() > 0) {
                $error = 'A partner account already exists with this email.';
            } else {
                $refCode = ps_partner_code($pdo);
                require_once 'subadmin_deposit_helper.php';
                $pct = ps_subadmin_default_commission_pct($pdo);
                $pdo->prepare("INSERT INTO sub_admins (username,password,email,referral_code,commission_pct,can_control_site,is_active) VALUES (?,?,?,?,?,0,1)")
                    ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $email, $refCode, $pct]);
                $id = (int)$pdo->lastInsertId();
                $_SESSION['sub_admin_id'] = $id;
                $_SESSION['sub_admin_username'] = $username;
                $_SESSION['sub_admin_ref'] = $refCode;
                unset($_SESSION['user_id']);
                header("Location: sub_admin.php");
                exit;
            }
        }
    } else {
        $login = trim($_POST['login'] ?? '');
        $password = $_POST['password'] ?? '';
        $mode = 'login';

        if ($login && $password) {
            $stmt = $pdo->prepare("SELECT * FROM sub_admins WHERE (username = ? OR email = ?) AND is_active = 1 LIMIT 1");
            $stmt->execute([$login, $login]);
            $sa = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($sa && ps_partner_password_matches($password, (string)$sa['password'])) {
                if (!password_get_info((string)$sa['password'])['algo']) {
                    $pdo->prepare("UPDATE sub_admins SET password=? WHERE id=?")->execute([password_hash($password, PASSWORD_DEFAULT), (int)$sa['id']]);
                }
                ps_partner_set_session($sa);
                header("Location: sub_admin.php");
                exit;
            }

            $userCols = ['id', 'username', 'email', 'password', 'is_agent', 'linked_agent_id'];
            $userStmt = $pdo->prepare("SELECT " . implode(',', $userCols) . " FROM users WHERE email=? OR username=? OR phone=? LIMIT 1");
            $userStmt->execute([$login, $login, $login]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);
            $linkedAgentId = (int)($user['linked_agent_id'] ?? 0);
            if ($user && $linkedAgentId > 0 && ps_partner_password_matches($password, (string)$user['password'])) {
                $saStmt = $pdo->prepare("SELECT * FROM sub_admins WHERE id=? AND is_active=1 LIMIT 1");
                $saStmt->execute([$linkedAgentId]);
                $linkedSa = $saStmt->fetch(PDO::FETCH_ASSOC);
                if ($linkedSa) {
                    if (!password_get_info((string)$user['password'])['algo']) {
                        $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([password_hash($password, PASSWORD_DEFAULT), (int)$user['id']]);
                    }
                    ps_partner_set_session($linkedSa);
                    header("Location: sub_admin.php");
                    exit;
                }
            }
            $error = 'Invalid login details.';
        } else {
            $error = 'Email/username and password are required.';
        }
    }
}

$isCreate = $mode === 'create';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<title><?php echo $isCreate ? 'Become a Partner' : 'Partner Login'; ?> | Alpha Sports</title>
<?php require __DIR__ . '/app_head_assets.php'; ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    body {
        margin: 0;
        background-color: #000000 !important;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        color: #fff;
    }
    
    .register-wrapper {
        display: flex;
        justify-content: center;
        align-items: flex-start;
        min-height: 100vh;
        padding-top: 40px;
        padding-bottom: 40px;
        padding-left: 16px;
        padding-right: 16px;
    }
    
    .ms-login-card {
        background: #1c1c1c;
        border-radius: 12px;
        width: 100%;
        max-width: 400px;
        padding: 40px 24px 30px;
        position: relative;
    }
    
    .ms-logo-container {
        position: absolute;
        top: -30px;
        left: 0;
        right: 0;
        display: flex;
        justify-content: center;
        align-items: center;
        flex-direction: column;
    }
    
    .alpha-logo-mark {
        width: 48px; height: 48px;
        display: flex; align-items: center; justify-content: center;
        border-radius: 14px;
        background: linear-gradient(145deg, #f87171 0%, #ef4444 52%, #b91c1c 100%);
        color: #080808;
        box-shadow: 0 0 0 1px rgba(239,68,68,.34), 0 12px 22px rgba(239,68,68,.18);
        font-size: 26px;
        font-weight: 1000;
        font-style: italic;
        transform: skewX(-8deg);
        position: relative;
    }
    .alpha-logo-mark::before {
        content: ''; position: absolute; inset: 9px 11px auto auto;
        width: 22px; height: 6px; border-radius: 999px;
        background: rgba(255,255,255,.50); transform: rotate(-28deg);
    }
    .alpha-logo-mark::after {
        content: ''; position: absolute; left: 9px; bottom: 10px;
        width: 30px; height: 5px; border-radius: 999px;
        background: #080808; opacity: .22; transform: rotate(-18deg);
    }
    .alpha-logo-mark span { position: relative; z-index: 2; transform: skewX(8deg); }
    
    .alpha-logo-copy {
        margin-top: 4px;
        display: flex; align-items: flex-end; gap: 4px;
        text-transform: uppercase; transform: skewX(-8deg);
    }
    .alpha-logo-copy strong {
        color: #ef4444; font-size: 22px; font-weight: 1000;
        letter-spacing: -1px; font-style: italic;
    }

    .ms-login-title {
        color: #ffffff;
        font-size: 18px;
        font-weight: 500;
        text-align: center;
        margin-top: 24px;
        margin-bottom: 8px;
    }
    .ms-login-subtitle {
        color: #888;
        font-size: 13px;
        text-align: center;
        margin-bottom: 24px;
        padding: 0 10px;
    }
    
    .ms-fieldset {
        border: 1px solid #4a4a4a;
        border-radius: 24px;
        padding: 0 16px;
        margin: 0 0 16px 0;
        display: flex;
        align-items: center;
        height: 52px;
        transition: border-color 0.2s;
    }
    .ms-fieldset:focus-within {
        border-color: #ef4444;
    }
    
    .ms-legend {
        color: #888888;
        font-size: 12px;
        padding: 0 6px;
        margin-left: 8px;
        width: auto;
        line-height: 1;
        transition: color 0.2s;
    }
    .ms-fieldset:focus-within .ms-legend {
        color: #ef4444;
    }
    
    .ms-input {
        background: transparent;
        border: none;
        outline: none;
        color: #ffffff;
        width: 100%;
        height: 100%;
        font-size: 15px;
        padding: 0 4px;
    }
    .ms-input::placeholder { color: #555555; }
    
    .ms-btn {
        background: #ef4444;
        color: #000;
        width: 100%;
        height: 52px;
        border: none;
        border-radius: 26px;
        font-size: 16px;
        font-weight: 800;
        cursor: pointer;
        margin-top: 10px;
        transition: transform 0.1s, filter 0.2s;
    }
    .ms-btn:hover { filter: brightness(1.1); }
    .ms-btn:active { transform: scale(0.98); }
    
    .ms-switch {
        text-align: center;
        margin-top: 24px;
        font-size: 14px;
        color: #888;
    }
    .ms-switch a {
        color: #ef4444;
        text-decoration: none;
        font-weight: 600;
    }
    
    .err-msg {
        background: rgba(239, 68, 68, 0.15);
        color: #ef4444;
        padding: 12px;
        border-radius: 12px;
        font-size: 13px;
        margin-bottom: 20px;
        text-align: center;
        border: 1px solid rgba(239, 68, 68, 0.3);
    }

    .back-btn {
        position: absolute;
        top: 20px;
        left: 20px;
        color: #888;
        text-decoration: none;
        font-size: 14px;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .back-btn:hover { color: #fff; }
</style>
</head>
<body>

<a href="dashboard.php" class="back-btn"><i class="fa-solid fa-arrow-left"></i> Back</a>

<div class="register-wrapper">
    <div class="ms-login-card">
        <div class="ms-logo-container">
            <div class="alpha-logo-mark"><span>&alpha;</span></div>
            <div class="alpha-logo-copy">
                <span>Alpha</span><strong>Sports</strong>
            </div>
        </div>
        
        <div class="ms-login-title">
            <?php echo $isCreate ? 'Become a Partner' : 'Partner Login'; ?>
        </div>
        <div class="ms-login-subtitle">
            <?php echo $isCreate ? 'Earn 70% commission on every deposit from users who sign up with your referral code.' : 'Sign in to view your referrals and commission account.'; ?>
        </div>

        <?php if ($error): ?>
        <div class="err-msg"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="action" value="<?php echo $isCreate ? 'create' : 'login'; ?>">
            
            <?php if ($isCreate): ?>
                <fieldset class="ms-fieldset">
                    <legend class="ms-legend">Full Name</legend>
                    <input type="text" name="name" class="ms-input" placeholder="Enter your full name" required>
                </fieldset>
                
                <fieldset class="ms-fieldset">
                    <legend class="ms-legend">Email Address</legend>
                    <input type="email" name="email" class="ms-input" placeholder="Enter your email" required>
                </fieldset>
                
                <fieldset class="ms-fieldset">
                    <legend class="ms-legend">Password</legend>
                    <input type="password" name="password" class="ms-input" placeholder="Choose a secure password" required minlength="6">
                </fieldset>
            <?php else: ?>
                <fieldset class="ms-fieldset">
                    <legend class="ms-legend">Email or Username</legend>
                    <input type="text" name="login" class="ms-input" placeholder="Enter email or username" required>
                </fieldset>
                
                <fieldset class="ms-fieldset">
                    <legend class="ms-legend">Password</legend>
                    <input type="password" name="password" class="ms-input" placeholder="Enter password" required>
                </fieldset>
            <?php endif; ?>
            
            <button type="submit" class="ms-btn">
                <?php echo $isCreate ? 'Create Partner Account' : 'Log In'; ?>
            </button>
        </form>

        <div class="ms-switch">
            <?php if ($isCreate): ?>
                Already a partner? <a href="sub_admin_login.php?mode=login">Log In</a>
            <?php else: ?>
                Want to earn commissions? <a href="sub_admin_login.php?mode=create">Sign Up</a>
            <?php endif; ?>
        </div>
    </div>
</div>

</body>
</html>
