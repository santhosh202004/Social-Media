<?php
// api/get_accounts.php
require_once '../includes/auth.php';
require_once '../includes/db_config.php';
header('Content-Type: application/json');

try {
    $stmt = $pdo->query("SELECT id, account_name, ad_account_id, page_id, is_active, access_token FROM facebook_config ORDER BY updated_at DESC");
    $accounts = $stmt->fetchAll();
    
    echo json_encode(['success' => true, 'accounts' => $accounts]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>
