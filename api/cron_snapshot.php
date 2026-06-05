<?php
/**
 * Daily Snapshot Cron Script
 * Records a snapshot of current KPI metrics into metrics_history table.
 */

require_once '../includes/db_config.php';

function fbApiGet($url) {
    $context = stream_context_create([
        'http' => [
            'method'        => 'GET',
            'timeout'       => 30,
            'ignore_errors' => true,
        ],
    ]);
    $response = @file_get_contents($url, false, $context);
    if ($response === false) return null;
    return json_decode($response, true);
}

$today = date('Y-m-d');

// Fetch all active accounts
$stmt = $pdo->query("SELECT f.id as config_id, f.access_token, f.page_id, i.ig_user_id 
                     FROM facebook_config f 
                     LEFT JOIN instagram_config i ON f.id = i.facebook_config_id 
                     WHERE f.is_active = 1");
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($accounts as $acc) {
    $configId = $acc['config_id'];
    $accessToken = $acc['access_token'];
    $pageId = $acc['page_id'];
    $igUserId = $acc['ig_user_id'];
    
    // Check if we already snapped today
    $checkStmt = $pdo->prepare("SELECT id FROM metrics_history WHERE account_id = ? AND metric_date = ?");
    $checkStmt->execute([$configId, $today]);
    if ($checkStmt->rowCount() > 0) {
        continue; // Already recorded today
    }
    
    $fbFollowers = 0; $igFollowers = 0;
    $fbReach = 0; $igReach = 0;
    $fbViews = 0; $igViews = 0;
    
    $since = strtotime('-30 days');
    $until = strtotime('now');
    
    // 1. FB Metrics
    if (!empty($pageId)) {
        // Followers
        $fbUrl = "https://graph.facebook.com/v19.0/{$pageId}?fields=followers_count&access_token={$accessToken}";
        $fbData = fbApiGet($fbUrl);
        if (isset($fbData['followers_count'])) $fbFollowers = $fbData['followers_count'];
        
        // Insights
        $fbReachUrl = "https://graph.facebook.com/v19.0/{$pageId}/insights?metric=page_impressions_unique,page_views_total&period=day&since={$since}&until={$until}&access_token={$accessToken}";
        $fbReachData = fbApiGet($fbReachUrl);
        if (isset($fbReachData['data'])) {
            foreach ($fbReachData['data'] as $metric) {
                $total = 0;
                if(isset($metric['values'])) {
                    foreach($metric['values'] as $v) $total += $v['value'] ?? 0;
                }
                if ($metric['name'] === 'page_impressions_unique') $fbReach = $total;
                if ($metric['name'] === 'page_views_total') $fbViews = $total;
            }
        }
        
        $insertFb = $pdo->prepare("INSERT INTO metrics_history (account_id, metric_date, platform, followers, reach, profile_views) VALUES (?, ?, 'facebook', ?, ?, ?)");
        $insertFb->execute([$configId, $today, $fbFollowers, $fbReach, $fbViews]);
    }
    
    // 2. IG Metrics
    if (!empty($igUserId)) {
        // Followers
        $igUrl = "https://graph.facebook.com/v19.0/{$igUserId}?fields=followers_count&access_token={$accessToken}";
        $igData = fbApiGet($igUrl);
        if (isset($igData['followers_count'])) $igFollowers = $igData['followers_count'];
        
        // Insights
        $igReachUrl = "https://graph.facebook.com/v19.0/{$igUserId}/insights?metric=reach,profile_views&period=day&since={$since}&until={$until}&access_token={$accessToken}";
        $igReachData = fbApiGet($igReachUrl);
        if (isset($igReachData['data'])) {
             foreach ($igReachData['data'] as $metric) {
                $total = 0;
                if(isset($metric['values'])) {
                    foreach($metric['values'] as $v) $total += $v['value'] ?? 0;
                }
                if ($metric['name'] === 'reach') $igReach = $total;
                if ($metric['name'] === 'profile_views') $igViews = $total;
            }
        }
        
        $insertIg = $pdo->prepare("INSERT INTO metrics_history (account_id, metric_date, platform, followers, reach, profile_views) VALUES (?, ?, 'instagram', ?, ?, ?)");
        $insertIg->execute([$configId, $today, $igFollowers, $igReach, $igViews]);
    }
}

if (isset($_GET['manual'])) {
    echo "Daily snapshot complete.";
}
?>
