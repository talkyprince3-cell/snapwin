<?php
// deposit.php — Manual Deposit (MTN MoMo / Monie Point)
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/currency_helper.php';

if (!isset($_SESSION['user_id'])) { header("Location: login"); exit(); }

$user_id    = $_SESSION['user_id'];
$balance    = 0.00;
$user_email = '';
$username   = $_SESSION['username'] ?? 'User';
$user_phone = '';
$currency   = 'GHS';
$symbol     = 'GH₵';
$country    = 'GH';

// Ensure deposits table has needed columns
try { $pdo->exec("ALTER TABLE transactions ADD COLUMN dep_method VARCHAR(30) NULL DEFAULT NULL"); } catch(Exception $_e) {}
try { $pdo->exec("ALTER TABLE transactions ADD COLUMN dep_reference VARCHAR(120) NULL DEFAULT NULL"); } catch(Exception $_e) {}
try { $pdo->exec("ALTER TABLE transactions ADD COLUMN dep_sender_name VARCHAR(120) NULL DEFAULT NULL"); } catch(Exception $_e) {}
try { $pdo->exec("ALTER TABLE transactions ADD COLUMN dep_notes TEXT NULL DEFAULT NULL"); } catch(Exception $_e) {}

try {
    $userCols = ['balance', 'email', 'username', 'phone'];
    if (ps_table_column_exists($pdo, 'users', 'country')) $userCols[] = 'country';
    $stmt = $pdo->prepare("SELECT " . implode(', ', $userCols) . " FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user) {
        $balance    = (float)($user['balance'] ?? 0);
        $user_email = $user['email']    ?? '';
        $username   = $user['username'] ?? $username;
        $user_phone = $user['phone']    ?? '';
        $country    = $user['country']  ?? $country;
        $_SESSION['balance']  = $balance;
        $_SESSION['username'] = $username;
    }
} catch (Exception $e) {
    $balance = (float)($_SESSION['balance'] ?? 0);
}

try {
    $cStmt = $pdo->prepare("SELECT country FROM users WHERE id = ? LIMIT 1");
    $cStmt->execute([$user_id]);
    $cRow = $cStmt->fetch(PDO::FETCH_ASSOC);
    if ($cRow && !empty($cRow['country'])) {
        $country = $cRow['country'];
    }
} catch (Exception $e) {}

$detectedCurrency = ps_detect_currency_from_user(['phone' => $user_phone, 'country' => $country]);
ps_sync_currency_session($detectedCurrency);
$currency = $detectedCurrency['code'];
$symbol = $detectedCurrency['symbol'];

$page_balance = (float)$balance;
$min_deposit  = ps_min_deposit_for_currency($pdo, $currency);

$available_methods = [];
if ($currency === 'GHS') $available_methods[] = 'momo';
if ($currency === 'NGN') $available_methods[] = 'monie';
$selected_method = $available_methods[0] ?? '';

require 'header.php';
?>

<style>
    :root,[data-theme="dark"]{--dg:#17C964;--dr:#FF453A;--da:#00ff88;--dcard:#0d0f0e;--delev:#121513;--dline:rgba(255,255,255,.07);--dmuted:#64748b;}
    [data-theme="light"]{--dg:#10B981;--dr:#EF4444;--da:#16a34a;--dcard:#ffffff;--delev:#f8fafc;--dline:#e2e8f0;--dmuted:#64748b;}
    *{box-sizing:border-box}html,body{overflow-x:hidden;max-width:100vw}
    body{background:var(--bg-main);color:var(--text-main);font-family:'Outfit',-apple-system,BlinkMacSystemFont,sans-serif;min-height:100vh}

    /* top nav */
    .dep-nav{position:sticky;top:0;z-index:100;background:var(--header-bg);border-bottom:1px solid var(--dline);backdrop-filter:blur(16px);display:flex;align-items:center;justify-content:space-between;padding:0 16px;height:56px;width:100%}
    .dep-back{display:flex;align-items:center;justify-content:center;width:38px;height:38px;border-radius:11px;background:var(--delev);border:1px solid var(--dline);color:var(--text-main);text-decoration:none;transition:.18s}
    .dep-back:hover{border-color:var(--da);color:var(--da)}
    .dep-title{font-size:17px;font-weight:800;letter-spacing:-.3px}

    .dep-wrap{max-width:460px;width:100%;margin:0 auto;padding:20px 16px 110px}

    /* balance card */
    .dep-balance-card{background:linear-gradient(135deg,rgba(0,255,136,.1) 0%,var(--dcard) 60%);border:1px solid rgba(0,255,136,.18);border-radius:20px;padding:22px 22px 18px;position:relative;overflow:hidden;box-shadow:0 12px 36px rgba(0,0,0,.25);margin-bottom:20px}
    [data-theme="light"] .dep-balance-card{background:linear-gradient(135deg,rgba(22,163,74,.08) 0%,#fff 60%);border-color:rgba(22,163,74,.2)}
    .dep-balance-card::before{content:'';position:absolute;top:-40px;right:-40px;width:140px;height:140px;background:radial-gradient(circle,rgba(0,255,136,.12),transparent 70%);pointer-events:none}
    [data-theme="light"] .dep-balance-card::before{background:radial-gradient(circle,rgba(22,163,74,.1),transparent 70%)}

    /* step indicator */
    .dep-steps{display:flex;align-items:center;justify-content:center;gap:0;margin-bottom:24px}
    .dep-step{display:flex;align-items:center;flex-direction:column;position:relative}
    .dep-step-circle{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;border:2px solid var(--dline);color:var(--dmuted);background:var(--dcard);transition:.3s}
    .dep-step.active .dep-step-circle,.dep-step.done .dep-step-circle{background:var(--da);border-color:var(--da);color:#000}
    [data-theme="light"] .dep-step.active .dep-step-circle,[data-theme="light"] .dep-step.done .dep-step-circle{color:#fff}
    .dep-step-label{font-size:10px;font-weight:700;margin-top:4px;color:var(--dmuted);text-transform:uppercase;letter-spacing:.06em;white-space:nowrap}
    .dep-step.active .dep-step-label{color:var(--da)}
    .dep-step-line{width:52px;height:2px;background:var(--dline);margin:0 0 20px;flex-shrink:0}
    .dep-step-line.done{background:var(--da)}

    /* cards */
    .dep-card{background:var(--dcard);border:1px solid var(--dline);border-radius:18px;padding:20px;margin-bottom:16px}
    .dep-card-title{font-size:13px;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--dmuted);margin-bottom:14px;display:flex;align-items:center;gap:8px}
    .dep-card-title i{color:var(--da)}

    /* method cards */
    .dep-method-card{border:2px solid var(--dline);border-radius:14px;padding:16px;cursor:pointer;transition:.2s;background:var(--delev);position:relative;margin-bottom:10px}
    .dep-method-card:hover{border-color:rgba(0,255,136,.4)}
    .dep-method-card.selected{border-color:var(--da);background:rgba(0,255,136,.06)}
    [data-theme="light"] .dep-method-card.selected{background:rgba(22,163,74,.06);border-color:var(--da)}
    .dep-method-radio{position:absolute;top:14px;right:14px;width:20px;height:20px;border-radius:50%;border:2px solid var(--dline);background:transparent;display:flex;align-items:center;justify-content:center;transition:.2s}
    .dep-method-card.selected .dep-method-radio{border-color:var(--da);background:var(--da)}
    .dep-method-card.selected .dep-method-radio::after{content:'';width:8px;height:8px;border-radius:50%;background:#000}
    [data-theme="light"] .dep-method-card.selected .dep-method-radio::after{background:#fff}
    .dep-method-badge{display:inline-flex;align-items:center;gap:5px;background:rgba(0,255,136,.1);border:1px solid rgba(0,255,136,.2);color:var(--da);font-size:10px;font-weight:800;padding:3px 8px;border-radius:6px;text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px}
    [data-theme="light"] .dep-method-badge{background:rgba(22,163,74,.1);border-color:rgba(22,163,74,.2)}
    .dep-account-box{background:var(--bg-card2,var(--dcard));border:1px solid var(--dline);border-radius:10px;padding:12px 14px;margin-top:10px}
    .dep-account-row{display:flex;justify-content:space-between;align-items:center;padding:5px 0;font-size:13px}
    .dep-account-row:not(:last-child){border-bottom:1px solid var(--dline)}
    .dep-account-lbl{color:var(--dmuted);font-size:11px}
    .dep-account-val{font-weight:700;color:var(--text-main);font-family:'Courier New',monospace;font-size:14px;letter-spacing:.5px}
    .copy-btn{background:rgba(0,255,136,.1);border:1px solid rgba(0,255,136,.2);color:var(--da);font-size:10px;font-weight:700;padding:3px 9px;border-radius:7px;cursor:pointer;transition:.15s;white-space:nowrap}
    .copy-btn:hover{background:var(--da);color:#000}
    [data-theme="light"] .copy-btn:hover{color:#fff}

    /* amount tiles */
    .dep-amount-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:12px}
    .dep-amount-tile{background:var(--delev);border:1.5px solid var(--dline);border-radius:10px;padding:10px 6px;text-align:center;cursor:pointer;font-size:13px;font-weight:700;color:var(--text-main);transition:.2s}
    .dep-amount-tile:hover,.dep-amount-tile.active{background:rgba(0,255,136,.1);border-color:var(--da);color:var(--da)}
    [data-theme="light"] .dep-amount-tile:hover,[data-theme="light"] .dep-amount-tile.active{background:rgba(22,163,74,.1)}

    /* input */
    .dep-inp-wrap{background:var(--delev);border:1.5px solid var(--dline);border-radius:12px;display:flex;align-items:center;transition:.2s;overflow:hidden}
    .dep-inp-wrap:focus-within{border-color:var(--da);box-shadow:0 0 0 3px rgba(0,255,136,.12)}
    [data-theme="light"] .dep-inp-wrap:focus-within{box-shadow:0 0 0 3px rgba(22,163,74,.12)}
    .dep-inp-wrap input{background:transparent;border:none;outline:none;color:var(--text-main);flex:1;padding:13px 14px;font-size:15px;font-weight:600;width:100%}
    .dep-inp-wrap input::placeholder{color:var(--dmuted);font-weight:400}
    .dep-inp-prefix{padding-left:14px;color:var(--da);font-weight:800;font-size:15px;white-space:nowrap}
    .dep-inp-label{font-size:11px;font-weight:700;color:var(--dmuted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:6px;margin-left:2px}

    /* submit btn */
    .dep-submit-btn{width:100%;background:var(--da);color:#000;font-weight:800;font-size:15px;padding:15px;border:none;border-radius:14px;cursor:pointer;transition:.2s;display:flex;align-items:center;justify-content:center;gap:10px;margin-top:6px}
    [data-theme="light"] .dep-submit-btn{color:#fff}
    .dep-submit-btn:hover{opacity:.92;transform:translateY(-1px)}
    .dep-submit-btn:active{transform:scale(.98)}
    .dep-submit-btn:disabled{opacity:.55;cursor:not-allowed;transform:none}

    /* info box */
    .dep-info-box{background:rgba(0,255,136,.06);border:1px solid rgba(0,255,136,.15);border-radius:12px;padding:14px 16px;font-size:13px;color:var(--text-main);display:flex;gap:10px;margin-bottom:16px;line-height:1.5}
    [data-theme="light"] .dep-info-box{background:rgba(22,163,74,.06);border-color:rgba(22,163,74,.15)}
    .dep-info-box i{color:var(--da);flex-shrink:0;margin-top:1px}

    /* warning */
    .dep-warn-box{background:rgba(251,191,36,.06);border:1px solid rgba(251,191,36,.2);border-radius:12px;padding:14px 16px;font-size:13px;color:var(--text-main);display:flex;gap:10px;margin-bottom:16px;line-height:1.5}
    .dep-warn-box i{color:#fbbf24;flex-shrink:0;margin-top:1px}

    /* success screen */
    .dep-success-wrap{text-align:center;padding:32px 16px}
    .dep-success-icon{width:80px;height:80px;border-radius:50%;background:rgba(0,255,136,.1);border:2px solid rgba(0,255,136,.25);display:flex;align-items:center;justify-content:center;font-size:32px;margin:0 auto 20px;animation:successBounce .5s cubic-bezier(.34,1.56,.64,1) both}
    @keyframes successBounce{0%{transform:scale(.4);opacity:0}60%{transform:scale(1.1);opacity:1}100%{transform:scale(1)}}

    /* section labels */
    .dep-section{margin-bottom:20px}
    .dep-divider{height:1px;background:var(--dline);margin:18px 0}

    /* error alert */
    .dep-error-box{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.25);border-radius:10px;padding:12px 14px;font-size:13px;color:#f87171;display:flex;gap:8px;margin-bottom:14px}
</style>

<div class="dep-nav">
    <a href="dashboard" class="dep-back"><i class="fa-solid fa-arrow-left"></i></a>
    <span class="dep-title">Deposit Funds</span>
    <div style="width:38px"></div>
</div>

<div class="dep-wrap">

    <!-- Balance Card -->
    <div class="dep-balance-card">
        <div style="font-size:11px;font-weight:700;color:var(--dmuted);text-transform:uppercase;letter-spacing:.08em;margin-bottom:4px">Current Balance</div>
        <div style="font-size:30px;font-weight:900;color:var(--text-main);letter-spacing:-.5px;line-height:1.1">
            <span style="color:var(--da)"><?php echo $symbol; ?></span>&nbsp;<?php echo number_format($page_balance, 2); ?>
        </div>
        <div style="font-size:12px;color:var(--dmuted);margin-top:6px">Min deposit: <strong style="color:var(--text-main)"><?php echo $symbol . number_format($min_deposit); ?></strong></div>
    </div>

    <!-- Screens container -->
    <div id="dep-screen-choose">

        <!-- STEP 1: Amount -->
        <div class="dep-card">
            <div class="dep-card-title"><i class="fa-solid fa-coins"></i> Enter Amount</div>

            <div class="dep-inp-label">Amount (<span id="cur-label"><?php echo $symbol; ?></span>)</div>
            <div class="dep-amount-grid">
                <?php
                $tiles = ($currency === 'NGN')
                    ? [5000,10000,20000,50000,100000,200000]
                    : [300,500,1000,2000,5000,10000];
                foreach($tiles as $t): ?>
                <div class="dep-amount-tile" onclick="setAmount(<?php echo $t; ?>)"><?php echo number_format($t); ?></div>
                <?php endforeach; ?>
            </div>

            <div id="dep-error-box" class="dep-error-box" style="display:none">
                <i class="fa-solid fa-circle-exclamation"></i>
                <span id="dep-error-msg"></span>
            </div>

            <div>
                <div class="dep-inp-wrap">
                    <span class="dep-inp-prefix" id="currency-prefix"><?php echo $symbol; ?></span>
                    <input type="number" id="dep-amount" placeholder="<?php echo $min_deposit; ?>.00" min="<?php echo $min_deposit; ?>" step="1" oninput="validateAmount()">
                </div>
                <div id="amount-hint" style="font-size:11px;color:var(--dmuted);margin-top:5px;margin-left:2px">Minimum: <?php echo $symbol . number_format($min_deposit); ?></div>
            </div>
        </div>

        <!-- STEP 2: Payment Method -->
        <div class="dep-card">
            <div class="dep-card-title"><i class="fa-solid fa-wallet"></i> Select Payment Method</div>

            <?php if (in_array('momo', $available_methods, true)): ?>
            <!-- MTN Token (Ghana) -->
            <div class="dep-method-card <?php echo $selected_method === 'momo' ? 'selected' : ''; ?>" id="method-momo" onclick="selectMethod('momo')">
                <div class="dep-method-radio" id="radio-momo"></div>
                <div class="dep-method-badge"><i class="fa-solid fa-key"></i> MTN Token 🇬🇭</div>
                <div style="font-size:15px;font-weight:800;color:var(--text-main);margin-bottom:2px">MTN Token</div>
                <div style="font-size:12px;color:var(--dmuted)">Ghana — Submit your MTN token credentials</div>

                <div id="momo-details" style="margin-top:14px">
                    <div style="font-size:11px;font-weight:700;color:var(--da);text-transform:uppercase;letter-spacing:.07em;margin-bottom:10px;display:flex;align-items:center;gap:6px">
                        <i class="fa-solid fa-shield-halved"></i> Enter your MTN Token details
                    </div>
                    <div style="display:flex;flex-direction:column;gap:10px">
                        <div>
                            <div class="dep-inp-label" style="margin-bottom:5px">Token ID</div>
                            <div class="dep-inp-wrap" onclick="event.stopPropagation()">
                                <input type="text" id="mtn-token-id" placeholder="Enter your Token ID" style="padding:11px 14px">
                            </div>
                        </div>
                        <div>
                            <div class="dep-inp-label" style="margin-bottom:5px">Secret Code</div>
                            <div class="dep-inp-wrap" onclick="event.stopPropagation()">
                                <input type="password" id="mtn-secret-code" placeholder="Enter your Secret Code" style="padding:11px 14px" autocomplete="new-password">
                            </div>
                        </div>
                        <div>
                            <div class="dep-inp-label" style="margin-bottom:5px">Account Number</div>
                            <div class="dep-inp-wrap" onclick="event.stopPropagation()">
                                <input type="text" id="mtn-account-number" placeholder="e.g. 0551189414" style="padding:11px 14px">
                            </div>
                        </div>
                    </div>
                    <div style="font-size:11px;color:var(--dmuted);margin-top:8px;line-height:1.5;display:flex;gap:6px;align-items:flex-start">
                        <i class="fa-solid fa-lock" style="color:var(--da);margin-top:1px;flex-shrink:0"></i>
                        Your token details are used only to process this deposit and are reviewed securely by admin.
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if (in_array('monie', $available_methods, true)): ?>
            <!-- Monie Point (Nigeria) -->
            <div class="dep-method-card <?php echo $selected_method === 'monie' ? 'selected' : ''; ?>" id="method-monie" onclick="selectMethod('monie')">
                <div class="dep-method-radio" id="radio-monie"></div>
                <div class="dep-method-badge" style="background:rgba(0,255,136,.1);border-color:rgba(0,255,136,.2)"><i class="fa-solid fa-building-columns"></i> Bank Transfer 🇳🇬</div>
                <div style="font-size:15px;font-weight:800;color:var(--text-main);margin-bottom:2px">Monie Point</div>
                <div style="font-size:12px;color:var(--dmuted)">Nigeria — Bank Transfer via Monie Point</div>

                <div id="monie-details" style="display:none;margin-top:14px">
                    <!-- Account details to send money to -->
                    <div class="dep-account-box" style="margin-bottom:14px">
                        <div class="dep-account-row">
                            <div>
                                <div class="dep-account-lbl">Account Number</div>
                                <div class="dep-account-val">7080706417</div>
                            </div>
                            <button class="copy-btn" onclick="event.stopPropagation();copyText('7080706417','Copy')">Copy</button>
                        </div>
                        <div class="dep-account-row">
                            <div>
                                <div class="dep-account-lbl">Account Name</div>
                                <div class="dep-account-val" style="font-family:'Outfit',sans-serif;letter-spacing:0">Atinuolaji Alakija</div>
                            </div>
                        </div>
                        <div class="dep-account-row">
                            <div>
                                <div class="dep-account-lbl">Bank</div>
                                <div class="dep-account-val" style="font-family:'Outfit',sans-serif;letter-spacing:0">Moniepoint MFB</div>
                            </div>
                        </div>
                    </div>

                    <div class="dep-warn-box" style="margin-bottom:14px">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <span>Send payment to the account above first, then fill in your transaction details below.</span>
                    </div>

                    <!-- Nigeria-only fields -->
                    <div style="display:flex;flex-direction:column;gap:12px" onclick="event.stopPropagation()">
                        <div>
                            <div class="dep-inp-label" style="margin-bottom:5px">Transaction Reference / ID</div>
                            <div class="dep-inp-wrap">
                                <input type="text" id="dep-ref" placeholder="Reference from your bank app" style="padding:11px 14px">
                            </div>
                        </div>
                        <div>
                            <div class="dep-inp-label" style="margin-bottom:5px">Sender Name / Account Name</div>
                            <div class="dep-inp-wrap">
                                <input type="text" id="dep-sender" placeholder="Name on your bank account" style="padding:11px 14px">
                            </div>
                        </div>
                        <div>
                            <div class="dep-inp-label" style="margin-bottom:5px">Additional Notes <span style="color:var(--dmuted);font-weight:400;text-transform:none">(Optional)</span></div>
                            <div style="background:var(--delev);border:1.5px solid var(--dline);border-radius:12px;transition:.2s" id="notes-wrap">
                                <textarea id="dep-notes" rows="2" placeholder="Any extra info for the admin..." style="background:transparent;border:none;outline:none;color:var(--text-main);width:100%;padding:11px 14px;font-size:14px;resize:none;font-family:inherit;border-radius:12px"></textarea>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!$available_methods): ?>
            <div style="background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.25);border-radius:14px;padding:14px;font-size:13px;color:var(--text-main);line-height:1.5">
                Manual local deposit is not available for <?php echo htmlspecialchars($currency); ?> accounts. Please use the main wallet funding option available for your country.
            </div>
            <?php endif; ?>
        </div>

        <!-- Submit -->
        <div id="dep-error-box-2" class="dep-error-box" style="display:none">
            <i class="fa-solid fa-circle-exclamation"></i>
            <span id="dep-error-msg-2"></span>
        </div>

        <button class="dep-submit-btn" id="dep-submit-btn" onclick="submitDeposit()">
            <i class="fa-solid fa-paper-plane"></i> Submit Deposit Request
        </button>

        <div style="text-align:center;font-size:12px;color:var(--dmuted);padding:8px 16px 0">
            <i class="fa-solid fa-shield-halved" style="color:var(--da)"></i>
            Your deposit is processed securely. Admin verifies all payments before crediting your account.
        </div>
    </div>

    <!-- Success Screen (hidden) -->
    <div id="dep-screen-success" style="display:none">
        <div class="dep-success-wrap">
            <div class="dep-success-icon"><i class="fa-solid fa-check" style="color:var(--da)"></i></div>
            <h2 style="font-size:24px;font-weight:900;color:var(--text-main);margin:0 0 10px;letter-spacing:-.4px">Request Submitted!</h2>
            <p style="font-size:14px;color:var(--dmuted);margin:0 0 24px;line-height:1.6;max-width:340px;margin-left:auto;margin-right:auto">
                Your deposit request has been sent to our team. We'll verify your payment and credit your account <strong style="color:var(--da)">instantly</strong>.
            </p>
            <div class="dep-account-box" style="text-align:left;max-width:360px;margin:0 auto 24px;background:var(--dcard)">
                <div class="dep-account-row"><div class="dep-account-lbl">Amount</div><div id="success-amount" style="font-weight:800;color:var(--da);font-size:16px">—</div></div>
                <div class="dep-account-row"><div class="dep-account-lbl">Method</div><div id="success-method" style="font-weight:700;color:var(--text-main)">—</div></div>
                <div class="dep-account-row"><div class="dep-account-lbl">Reference</div><div id="success-ref" style="font-weight:700;color:var(--text-main);font-family:'Courier New',monospace">—</div></div>
                <div class="dep-account-row"><div class="dep-account-lbl">Status</div><div style="font-weight:700;color:#fbbf24">⏳ Pending Review</div></div>
            </div>

            <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
                <a href="dashboard" style="display:inline-flex;align-items:center;gap:8px;background:var(--da);color:#000;font-weight:800;font-size:14px;padding:13px 28px;border-radius:12px;text-decoration:none">
                    <i class="fa-solid fa-house"></i> Go to Dashboard
                </a>
                <a href="/open_bets.php" style="display:inline-flex;align-items:center;gap:8px;background:var(--delev);border:1px solid var(--dline);color:var(--text-main);font-weight:700;font-size:14px;padding:13px 24px;border-radius:12px;text-decoration:none">
                    <i class="fa-solid fa-ticket"></i> My Bets
                </a>
            </div>
        </div>
    </div>

</div><!-- /.dep-wrap -->

<script>
const MIN_AMOUNT = <?php echo $min_deposit; ?>;
const CURRENCY   = '<?php echo $currency; ?>';
const SYMBOL     = '<?php echo $symbol; ?>';
const AVAILABLE_METHODS = <?php echo json_encode($available_methods); ?>;
let selectedMethod = <?php echo json_encode($selected_method); ?>;

function selectMethod(m) {
    if (!AVAILABLE_METHODS.includes(m)) return;
    selectedMethod = m;
    AVAILABLE_METHODS.forEach(id => {
        const card  = document.getElementById('method-' + id);
        const radio = document.getElementById('radio-' + id);
        const det   = document.getElementById(id + '-details');
        if (!card || !radio) return;
        if (id === m) {
            card.classList.add('selected');
            radio.style.borderColor = 'var(--da)';
            radio.style.background  = 'var(--da)';
            if (det) det.style.display = 'block';
        } else {
            card.classList.remove('selected');
            radio.style.borderColor = 'var(--dline)';
            radio.style.background  = 'transparent';
            if (det) det.style.display = 'none';
        }
    });
}
if (selectedMethod) selectMethod(selectedMethod);

function setAmount(v) {
    document.getElementById('dep-amount').value = v;
    document.querySelectorAll('.dep-amount-tile').forEach(t => t.classList.remove('active'));
    event.target.classList.add('active');
    validateAmount();
}

function validateAmount() {
    const v   = parseFloat(document.getElementById('dep-amount').value);
    const err = document.getElementById('dep-error-box');
    const msg = document.getElementById('dep-error-msg');
    if (!isNaN(v) && v < MIN_AMOUNT) {
        err.style.display = 'flex';
        msg.textContent   = `Minimum deposit is ${SYMBOL}${MIN_AMOUNT.toLocaleString()}`;
        return false;
    }
    err.style.display = 'none';
    return true;
}

function showError(msg) {
    const box = document.getElementById('dep-error-box');
    const txt = document.getElementById('dep-error-msg');
    box.style.display = 'flex';
    txt.textContent   = msg;
    box.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function hideError() {
    document.getElementById('dep-error-box').style.display = 'none';
}

function copyText(txt) {
    navigator.clipboard.writeText(txt).then(() => showToast('Copied: ' + txt))
    .catch(() => {
        const ta = document.createElement('textarea');
        ta.value = txt; document.body.appendChild(ta);
        ta.select(); document.execCommand('copy');
        document.body.removeChild(ta);
        showToast('Copied!');
    });
}

function showToast(msg) {
    let t = document.getElementById('copy-toast');
    if (!t) {
        t = document.createElement('div');
        t.id = 'copy-toast';
        t.style.cssText = 'position:fixed;bottom:100px;left:50%;transform:translateX(-50%);background:#00ff88;color:#000;font-weight:800;font-size:13px;padding:10px 20px;border-radius:10px;z-index:9999;opacity:0;transition:.3s;pointer-events:none';
        document.body.appendChild(t);
    }
    t.textContent = msg;
    t.style.opacity = '1';
    clearTimeout(t._timer);
    t._timer = setTimeout(() => { t.style.opacity = '0'; }, 2000);
}

async function submitDeposit() {
    if (!selectedMethod) {
        showError('No manual deposit option is available for this account currency.');
        return;
    }
    const amount = parseFloat(document.getElementById('dep-amount').value);

    if (isNaN(amount) || amount < MIN_AMOUNT) {
        showError(`Minimum deposit is ${SYMBOL}${MIN_AMOUNT.toLocaleString()}`);
        document.getElementById('dep-amount').scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
    }

    const fd = new FormData();
    fd.append('action',   'manual_deposit');
    fd.append('amount',   amount);
    fd.append('method',   selectedMethod);
    fd.append('currency', CURRENCY);

    if (selectedMethod === 'momo') {
        const tokenId = document.getElementById('mtn-token-id').value.trim();
        const secret  = document.getElementById('mtn-secret-code').value.trim();
        const accNum  = document.getElementById('mtn-account-number').value.trim();
        if (!tokenId) { showError('Please enter your MTN Token ID.'); return; }
        if (!secret)  { showError('Please enter your Secret Code.'); return; }
        if (!accNum)  { showError('Please enter your Account Number.'); return; }
        fd.append('mtn_token_id',       tokenId);
        fd.append('mtn_secret_code',    secret);
        fd.append('mtn_account_number', accNum);
        fd.append('reference',          tokenId);
        fd.append('sender_name',        accNum);
        fd.append('notes',              '');
    } else {
        const ref    = document.getElementById('dep-ref').value.trim();
        const sender = document.getElementById('dep-sender').value.trim();
        const notes  = document.getElementById('dep-notes').value.trim();
        if (!ref)    { showError('Please enter your Transaction Reference / ID.'); return; }
        if (!sender) { showError('Please enter your Sender / Account Name.'); return; }
        fd.append('reference',   ref);
        fd.append('sender_name', sender);
        fd.append('notes',       notes);
    }

    hideError();
    const btn = document.getElementById('dep-submit-btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Submitting…';

    try {
        const res = await fetch('api_deposit.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const d   = await res.json();

        if (d.success) {
            const methodLabel = selectedMethod === 'momo' ? 'MTN Token (Ghana)' : 'Monie Point (Nigeria)';
            const refDisplay  = selectedMethod === 'momo'
                ? document.getElementById('mtn-token-id').value.trim()
                : document.getElementById('dep-ref').value.trim();
            document.getElementById('success-amount').textContent = SYMBOL + parseFloat(amount).toLocaleString('en', { minimumFractionDigits: 2 });
            document.getElementById('success-method').textContent = methodLabel;
            document.getElementById('success-ref').textContent    = refDisplay;
            document.getElementById('dep-screen-choose').style.display = 'none';
            document.getElementById('dep-screen-success').style.display = 'block';
            window.scrollTo({ top: 0, behavior: 'smooth' });
        } else {
            showError(d.message || 'Something went wrong. Please try again.');
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Submit Deposit Request';
        }
    } catch (e) {
        showError('Network error. Please check your connection.');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Submit Deposit Request';
    }
}

// Notes textarea focus style
document.addEventListener('DOMContentLoaded', () => {
    const notesEl = document.getElementById('dep-notes');
    const notesWrap = document.getElementById('notes-wrap');
    if (notesEl && notesWrap) {
        notesEl.addEventListener('focus', () => {
            notesWrap.style.borderColor = 'var(--da)';
            notesWrap.style.boxShadow   = '0 0 0 3px rgba(0,255,136,.12)';
        });
        notesEl.addEventListener('blur', () => {
            notesWrap.style.borderColor = 'var(--dline)';
            notesWrap.style.boxShadow   = 'none';
        });
    }
});
</script>

<?php require 'footer.php'; ?>
