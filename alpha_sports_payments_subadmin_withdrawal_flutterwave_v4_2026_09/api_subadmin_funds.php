<?php
session_start();
header('Content-Type: application/json');

require_once 'db.php';

if (!isset($_SESSION['sub_admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated as sub-admin']);
    exit;
}

$saId = (int)$_SESSION['sub_admin_id'];
$action = $_POST['action'] ?? '';
$amount = (float)($_POST['amount'] ?? 0);

if ($amount <= 0) {
    echo json_encode(['success' => false, 'error' => 'Amount must be greater than zero']);
    exit;
}

if (!in_array($action, ['add', 'remove'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
    exit;
}

try {
    // Find the personal betting account linked to this sub_admin
    $stmt = $pdo->prepare("SELECT id, balance FROM users WHERE is_agent=1 AND linked_agent_id=? LIMIT 1");
    $stmt->execute([$saId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'No personal betting account linked to this sub-admin.']);
        exit;
    }

    $userId = (int)$user['id'];
    
    // Use transaction for safety
    $pdo->beginTransaction();

    if ($action === 'add') {
        $upd = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
        $upd->execute([$amount, $userId]);
        $msg = 'Successfully added funds.';
    } else {
        // action === 'remove'
        // lock row for read
        $chk = $pdo->prepare("SELECT balance FROM users WHERE id = ? FOR UPDATE");
        $chk->execute([$userId]);
        $currentBalance = (float)($chk->fetchColumn() ?: 0);
        
        if ($currentBalance < $amount) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Insufficient funds in betting account.']);
            exit;
        }
        
        $upd = $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ?");
        $upd->execute([$amount, $userId]);
        $msg = 'Successfully removed funds.';
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => $msg]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'error' => 'Database error occurred.']);
}
