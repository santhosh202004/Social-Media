<?php
// api/delete_account.php
require_once '../includes/auth.php';
require_once '../includes/db_config.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$id = $data['id'] ?? null;

if (!$id) {
    echo json_encode(['success' => false, 'error' => 'Account ID is required']);
    exit;
}

try {
    // Check if the account is active
    $stmt = $pdo->prepare("SELECT is_active FROM facebook_config WHERE id = ?");
    $stmt->execute([$id]);
    $account = $stmt->fetch();

    if (!$account) {
        echo json_encode(['success' => false, 'error' => 'Account not found']);
        exit;
    }

    if ($account['is_active']) {
        echo json_encode(['success' => false, 'error' => 'Cannot delete the currently active account. Please switch to another account first.']);
        exit;
    }

    $stmt = $pdo->prepare("DELETE FROM facebook_config WHERE id = ?");
    $stmt->execute([$id]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>
