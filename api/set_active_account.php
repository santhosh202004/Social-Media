<?php
// api/set_active_account.php
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
    $pdo->beginTransaction();
    
    // Set all to inactive
    $pdo->exec("UPDATE facebook_config SET is_active = 0");
    
    // Set the selected one to active
    $stmt = $pdo->prepare("UPDATE facebook_config SET is_active = 1 WHERE id = ?");
    $stmt->execute([$id]);
    
    $pdo->commit();
    
    // Clear all cached data so new config takes effect immediately
    require_once '../includes/SimpleCache.php';
    SimpleCache::clear();
    
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>
