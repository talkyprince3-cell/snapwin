<?php
// deposit_return.php — user-facing return page after TechVault checkout.
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit;
}

$expectedAmount = isset($_GET['amount']) ? (float)$_GET['amount'] : 0.00;
$reference = trim($_GET['reference'] ?? $_GET['ref'] ?? $_GET['trxref'] ?? $_GET['transaction_id'] ?? '');
$isWithdrawalVerification = isset($_GET['verify_withdraw'])
    ? (int)$_GET['verify_withdraw'] === 1
    : str_starts_with($reference, 'WV_');

require 'header.php';
$user_currency = $_SESSION['currency'] ?? 'GHS';
?>

<style>
    .tv-return-wrap {
        max-width: 520px;
        margin: 0 auto;
        padding: 36px 16px 110px;
    }
    .tv-return-card {
        background: var(--bg-card);
        border: 1px solid var(--border);
        border-radius: 18px;
        padding: 24px;
        box-shadow: 0 16px 45px rgba(0,0,0,.18);
        text-align: center;
    }
    .tv-icon {
        width: 58px;
        height: 58px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 16px;
        background: rgba(239,68,68,.12);
        color: var(--accent);
        border: 1px solid rgba(239,68,68,.24);
        font-size: 22px;
    }
    .tv-title {
        font-size: 22px;
        font-weight: 900;
        margin: 0 0 8px;
        letter-spacing: -.4px;
    }
    .tv-sub {
        color: var(--text-dim);
        font-size: 13px;
        line-height: 1.55;
        margin: 0 auto 18px;
        max-width: 390px;
    }
    .tv-balance {
        background: var(--glass);
        border: 1px solid var(--border);
        border-radius: 14px;
        padding: 14px;
        margin: 16px 0;
    }
    .tv-balance-label {
        color: var(--text-dim);
        font-size: 10px;
        text-transform: uppercase;
        letter-spacing: .08em;
        font-weight: 800;
        margin-bottom: 4px;
    }
    .tv-balance-value {
        color: var(--accent);
        font-size: 24px;
        font-weight: 900;
    }
    .tv-actions {
        display: flex;
        gap: 10px;
        justify-content: center;
        flex-wrap: wrap;
        margin-top: 18px;
    }
    .tv-btn {
        border: 1px solid var(--border);
        background: var(--glass);
        color: var(--text-main);
        text-decoration: none;
        border-radius: 12px;
        padding: 11px 14px;
        font-size: 13px;
        font-weight: 800;
        cursor: pointer;
    }
    .tv-btn.primary {
        background: var(--accent);
        color: #111;
        border-color: var(--accent);
    }
</style>

<div class="tv-return-wrap">
    <div class="tv-return-card">
        <div class="tv-icon" id="tvIcon"><i class="fa-solid fa-spinner fa-spin"></i></div>
        <h1 class="tv-title" id="tvTitle">Checking your payment</h1>
        <p class="tv-sub" id="tvText">Please wait while we confirm the TechVault callback and refresh your wallet balance.</p>

        <div class="tv-balance">
            <div class="tv-balance-label">Current Balance</div>
            <div class="tv-balance-value"><?php echo htmlspecialchars($user_currency); ?> <span id="tvBalance">...</span></div>
        </div>

        <?php if ($expectedAmount > 0): ?>
            <p style="font-size:12px;color:var(--text-dim);margin:0">Expected deposit: <?php echo htmlspecialchars($user_currency); ?> <?php echo number_format($expectedAmount, 2); ?></p>
        <?php endif; ?>

        <div class="tv-actions">
            <a class="tv-btn primary" href="transactions">View Transactions</a>
            <a class="tv-btn" href="dashboard">Back to Dashboard</a>
            <button class="tv-btn" type="button" onclick="checkTechVaultStatus(true)">Refresh</button>
        </div>
    </div>
</div>

<script>
    const tvReference = <?php echo json_encode($reference); ?>;
    const tvWithdrawalVerification = <?php echo $isWithdrawalVerification ? 'true' : 'false'; ?>;
    let tvAttempts = 0;
    const tvMaxAttempts = 20;

    function setTvState(kind, title, text) {
        const icon = document.getElementById('tvIcon');
        icon.innerHTML = kind === 'success'
            ? '<i class="fa-solid fa-check"></i>'
            : kind === 'error'
                ? '<i class="fa-solid fa-circle-exclamation"></i>'
                : '<i class="fa-solid fa-spinner fa-spin"></i>';
        icon.style.color = kind === 'error' ? '#ef4444' : 'var(--accent)';
        document.getElementById('tvTitle').textContent = title;
        document.getElementById('tvText').textContent = text;
    }

    async function checkTechVaultStatus(manual = false) {
        tvAttempts++;
        try {
            const qs = new URLSearchParams();
            if (tvReference) qs.set('reference', tvReference);
            qs.set('_', Date.now());
            const res = await fetch('api_techvault_status.php?' + qs.toString(), {
                credentials: 'same-origin',
                cache: 'no-store'
            });
            const data = await res.json();
            if (!data.success) throw new Error(data.message || 'Status check failed');

            document.getElementById('tvBalance').textContent = parseFloat(data.balance || 0).toFixed(2);
            if (data.completed) {
                setTvState('success', 'Wallet credited', 'Your payment has been confirmed and your balance has been updated.');
                window.dispatchEvent(new Event('balanceChanged'));
                
                // If loaded in iframe modal, tell parent to close
                const urlParams = new URLSearchParams(window.location.search);
                if (urlParams.get('iframe') === 'true') {
                    if (window.parent && window.parent !== window) {
                         setTimeout(() => {
                             window.parent.postMessage('close_techvault_modal', '*');
                         }, 2000); // give user 2 seconds to see the success message
                    }
                } else if (tvWithdrawalVerification) {
                    setTimeout(() => { window.location.href = 'withdraw.php?verification_return=1'; }, 1500);
                }
                return;
            }
            if (data.found) {
                setTvState('pending', 'Payment received', 'We found your deposit and are waiting for final completion.');
            } else {
                setTvState('pending', 'Still confirming', 'TechVault may need a few seconds to send the final callback.');
            }
        } catch (e) {
            setTvState('error', 'Could not check automatically', 'Your payment may still be processing. Open transactions or refresh in a moment.');
            return;
        }

        if (!manual && tvAttempts < tvMaxAttempts) {
            setTimeout(() => checkTechVaultStatus(false), 3000);
        }
    }

    checkTechVaultStatus(false);
</script>

<?php require 'footer.php'; ?>
