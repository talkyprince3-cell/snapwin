<?php
session_start();
require_once 'db.php';
if (!isset($_SESSION['user_id'])) { header("Location: login"); exit; }
$uid = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT * FROM transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 100");
$stmt->execute([$uid]);
$txns = $stmt->fetchAll();
include 'header.php';
$user_currency = $_SESSION['currency'] ?? 'GHS';
?>
<style>
    .tx-wrap { padding: 14px; padding-bottom: 100px; max-width: 600px; margin: 0 auto; }
    .tx-card {
        background: var(--bg-card); border: 1px solid var(--border);
        border-radius: 14px; padding: 14px 16px; margin-bottom: 8px;
        display: flex; justify-content: space-between; align-items: center;
    }
    .tx-type  { font-size: 13px; font-weight: 800; margin: 0; }
    .tx-meta  { font-size: 10px; color: var(--text-dim); margin: 4px 0 0; }
    .tx-ref   { font-size: 10px; color: var(--text-dim); margin: 2px 0 0; }
    .tx-amt   { font-size: 16px; font-weight: 900; font-style: italic; }
    .tx-pos   { color: var(--accent); }
    .tx-neg   { color: #ef4444; }
    .tx-badge {
        display: inline-block; font-size: 9px; font-weight: 800;
        text-transform: uppercase; padding: 2px 8px; border-radius: 5px; margin-top: 4px;
    }
    .badge-completed { background: rgba(239,68,68,.1); color: var(--accent); border: 1px solid rgba(239,68,68,.2); }
    .badge-pending   { background: rgba(239,68,68,.1); color: #60a5fa;       border: 1px solid rgba(239,68,68,.2); }
    .badge-rejected  { background: rgba(239,68,68,.1);  color: #ef4444;       border: 1px solid rgba(239,68,68,.2); }
</style>

<div class="tx-wrap">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:18px">
        <button onclick="history.back()"
                style="width:34px;height:34px;border-radius:10px;background:var(--glass);border:1px solid var(--border);color:var(--text-main);font-size:12px;display:flex;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0">
            <i class="fa-solid fa-chevron-left"></i>
        </button>
        <h1 style="font-family:SF Pro Display,-apple-system, BlinkMacSystemFont, system-ui,sans-serif;font-size:20px;font-weight:900;font-style:italic;margin:0;letter-spacing:-.5px">TRANSACTIONS</h1>
    </div>

    <?php
    // Deposits only ever show once they are approved/completed. A user should never
    // see a "Pending" deposit line — whether it came from an auto gateway (Paystack/
    // Flutterwave/TechVault) or a manual method (USDT, MoMo, Admin, bank_transfer).
    // Pending/Rejected/Failed withdrawals and other transaction types are unaffected
    // and still display normally.
    $filteredTxns = array_filter($txns, function($tx) {
        if (strcasecmp((string)($tx['type'] ?? ''), 'Deposit') !== 0) return true;    // Keep non-deposits as-is
        return strcasecmp((string)($tx['status'] ?? ''), 'Pending') !== 0;            // Hide ALL pending deposits
    });

    if (!$filteredTxns): ?>
        <div style="text-align:center;padding:60px 20px;color:var(--text-dim)">
            <i class="fa-solid fa-receipt" style="font-size:36px;opacity:.3;display:block;margin-bottom:12px"></i>
            <p style="font-size:13px;font-weight:700;margin:0">No transactions yet.</p>
        </div>
    <?php else: foreach ($filteredTxns as $tx):
        $displayType = $tx['type'];
        if (strcasecmp((string)$tx['type'], 'AgentSelfFund') === 0) {
            $displayType = ((float)$tx['amount'] < 0) ? 'Withdrawal' : 'Deposit';
        }
        $isPos  = in_array($displayType, ['Deposit','Win','Admin']) && $tx['amount'] > 0;
        $status = $tx['status'] ?? 'Completed';
        $badgeCls = match(strtolower($status)) {
            'completed' => 'badge-completed',
            'pending'   => 'badge-pending',
            default     => 'badge-rejected',
        };
        $dateStr = !empty($tx['created_at']) ? date('d M Y · H:i', strtotime($tx['created_at'])) : '—';
    ?>
        <div class="tx-card">
            <div>
                <p class="tx-type"><?php echo htmlspecialchars($displayType); ?></p>
                <p class="tx-meta"><?php echo $dateStr; ?></p>
                <?php if (!empty($tx['tx_reference'])): ?>
                    <p class="tx-ref"><?php echo htmlspecialchars($tx['tx_reference']); ?></p>
                <?php endif; ?>
                <span class="tx-badge <?php echo $badgeCls; ?>"><?php echo htmlspecialchars($status); ?></span>
            </div>
            <span class="tx-amt <?php echo $isPos ? 'tx-pos' : 'tx-neg'; ?>">
                <?php echo $isPos ? '+' : '−'; ?><?php echo htmlspecialchars($user_currency); ?> <?php echo number_format(abs($tx['amount']), 2); ?>
            </span>
        </div>
    <?php endforeach; endif; ?>
</div>

<?php include 'bottom_nav.php'; ?>
