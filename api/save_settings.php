<?php
// api/save_settings.php
require_once '../includes/auth.php';
require_once '../includes/db_config.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);

$id = $data['id'] ?? '';
$account_name = $data['account_name'] ?? '';
$access_token = $data['access_token'] ?? '';
$ad_account_id = $data['ad_account_id'] ?? '';
$ad_account_id = 'act_' . preg_replace('/[^0-9]/', '', $ad_account_id);
$page_id = $data['page_id'] ?? '';

if (empty($account_name) || empty($access_token) || $ad_account_id === 'act_') {
    echo json_encode(['success' => false, 'error' => 'Account Name, Access Token, and Ad Account ID are required']);
    exit;
}

// Auto-fetch page_id if not provided
if (empty($page_id)) {
    $url = "https://graph.facebook.com/v19.0/me/accounts?access_token=" . urlencode($access_token);
    $context = stream_context_create([
        'http' => ['method' => 'GET', 'timeout' => 15, 'ignore_errors' => true]
    ]);
    $response = @file_get_contents($url, false, $context);
    
    if ($response !== false) {
        $fbData = json_decode($response, true);
        if (isset($fbData['data']) && count($fbData['data']) > 0) {
            $page_id = $fbData['data'][0]['id'];
        } else {
            echo json_encode(['success' => false, 'error' => 'No Facebook Pages found for this Access Token. Please ensure the token has pages_read_engagement permission.']);
            exit;
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to connect to Facebook API. Check your Access Token.']);
        exit;
    }
}

// Fetch Page Access Token before saving
$pageAccessToken = $access_token;
if (!empty($page_id)) {
    $pageTokenUrl = "https://graph.facebook.com/v19.0/me/accounts?fields=id,access_token&access_token=" . urlencode($access_token);
    $context = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 15, 'ignore_errors' => true]]);
    $ptResponse = @file_get_contents($pageTokenUrl, false, $context);
    $ptData = $ptResponse ? json_decode($ptResponse, true) : null;
    if (isset($ptData['data'])) {
        foreach ($ptData['data'] as $acc) {
            if ($acc['id'] === $page_id) { $pageAccessToken = $acc['access_token']; break; }
        }
    }
}

try {
    // Check if we need to insert or update. Let's just insert and make it active, making others inactive.
    $pdo->beginTransaction();
    
    // Set all other accounts to inactive
    $pdo->exec("UPDATE facebook_config SET is_active = 0");

    // Insert new configuration or update existing one
    if (!empty($id)) {
        // Explicit update by ID
        $updateStmt = $pdo->prepare("UPDATE facebook_config SET account_name = ?, access_token = ?, page_access_token = ?, page_id = ?, ad_account_id = ?, is_active = 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $updateStmt->execute([$account_name, $access_token, $pageAccessToken, $page_id, $ad_account_id, $id]);
    } else {
        // Check if ad_account_id already exists to prevent duplicates if ID is not provided
        $stmt = $pdo->prepare("SELECT id FROM facebook_config WHERE ad_account_id = ?");
        $stmt->execute([$ad_account_id]);
        $existing = $stmt->fetch();

        if ($existing) {
            $updateStmt = $pdo->prepare("UPDATE facebook_config SET account_name = ?, access_token = ?, page_access_token = ?, page_id = ?, is_active = 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $updateStmt->execute([$account_name, $access_token, $pageAccessToken, $page_id, $existing['id']]);
        } else {
            $insertStmt = $pdo->prepare("INSERT INTO facebook_config (account_name, access_token, page_access_token, ad_account_id, page_id, is_active) VALUES (?, ?, ?, ?, ?, 1)");
            $insertStmt->execute([$account_name, $access_token, $pageAccessToken, $ad_account_id, $page_id]);
        }
    }

    $pdo->commit();
    
    // Auto-discover and save Instagram Business Account ID
    $configId = !empty($id) ? $id : $pdo->lastInsertId();
    if (empty($configId)) {
        // Find the active config ID
        $findStmt = $pdo->query("SELECT id FROM facebook_config WHERE is_active = 1 LIMIT 1");
        $configId = $findStmt->fetchColumn();
    }
    
    if ($configId && !empty($page_id)) {
        // We already have pageAccessToken from above, just proceed with IG discovery

        $igDiscUrl = "https://graph.facebook.com/v19.0/{$page_id}?fields=instagram_business_account&access_token=" . urlencode($pageAccessToken);
        $igResponse = @file_get_contents($igDiscUrl, false, $context);
        $igData = $igResponse ? json_decode($igResponse, true) : null;
        
        if (isset($igData['instagram_business_account']['id'])) {
            $igUserId = $igData['instagram_business_account']['id'];
            $igCheck = $pdo->prepare("SELECT id FROM instagram_config WHERE facebook_config_id = ?");
            $igCheck->execute([$configId]);
            if ($igCheck->fetch()) {
                $igUpd = $pdo->prepare("UPDATE instagram_config SET ig_user_id = ? WHERE facebook_config_id = ?");
                $igUpd->execute([$igUserId, $configId]);
            } else {
                $igIns = $pdo->prepare("INSERT INTO instagram_config (facebook_config_id, ig_user_id) VALUES (?, ?)");
                $igIns->execute([$configId, $igUserId]);
            }
        }
    }
    
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
