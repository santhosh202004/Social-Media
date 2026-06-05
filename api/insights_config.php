<?php
/**
 * Shared configuration and helper functions for Insights APIs
 */
set_time_limit(60);
header('Content-Type: application/json');
require_once '../includes/auth.php';
require_once '../includes/db_config.php';
require_once '../includes/SimpleCache.php';

// Helper for single API calls using cURL
function fbApiGet($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_ENCODING       => "",
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4
    ]);
    $response = curl_exec($ch);
    $data = $response ? json_decode($response, true) : null;
    curl_close($ch);
    return $data;
}

// Helper for parallel API calls
function fbApiGetMulti(array $urls): array {
    if (empty($urls)) return [];
    
    $mh = curl_multi_init();
    $handles = [];
    
    foreach ($urls as $key => $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_ENCODING       => "",
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$key] = $ch;
    }
    
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh);
    } while ($running > 0);
    
    $results = [];
    foreach ($handles as $key => $ch) {
        $content = curl_multi_getcontent($ch);
        $results[$key] = $content ? json_decode($content, true) : null;
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    
    curl_multi_close($mh);
    return $results;
}

// Extract metric name helper
function extractMetricName($metric) {
    if (!empty($metric['name'])) return $metric['name'];
    if (!empty($metric['id'])) {
        if (preg_match('/insights\/([a-z_]+)\//i', $metric['id'], $m)) {
            return $m[1];
        }
    }
    return '';
}

// Get active configuration
$stmt = $pdo->query("SELECT f.id, f.access_token, f.page_access_token, f.page_id, i.ig_user_id 
                     FROM facebook_config f 
                     LEFT JOIN instagram_config i ON f.id = i.facebook_config_id 
                     WHERE f.is_active = 1 LIMIT 1");
$config = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$config || empty($config['access_token'])) {
    echo json_encode(['error' => 'not_configured', 'message' => 'API is not configured.']);
    exit;
}

$accessToken = $config['access_token'];
$pageId      = trim($config['page_id'] ?? '');
$igUserId    = trim($config['ig_user_id'] ?? '');
$configId    = $config['id'];

// Token Exchange
$pageAccessToken = !empty($config['page_access_token']) ? $config['page_access_token'] : $accessToken;

// Date handling
$since = $_GET['since'] ?? date('Y-m-d', strtotime('-6 months'));
$until = $_GET['until'] ?? date('Y-m-d');
$sinceTs = strtotime($since . ' 00:00:00');
$untilTs = strtotime($until . ' 23:59:59');

$diffDays = floor(($untilTs - $sinceTs) / (60 * 60 * 24));
$prevUntilTs = strtotime($since . ' 00:00:00') - 1;
$prevSinceTs = $prevUntilTs - ($diffDays * 60 * 60 * 24);
$prevSince = date('Y-m-d', $prevSinceTs);
$prevUntil = date('Y-m-d', $prevUntilTs);

// Return basic context needed by the APIs
$apiContext = [
    'configId'        => $configId,
    'pageId'          => $pageId,
    'igUserId'        => $igUserId,
    'accessToken'     => $accessToken,
    'pageAccessToken' => $pageAccessToken,
    'since'           => $since,
    'until'           => $until,
    'sinceTs'         => $sinceTs,
    'untilTs'         => $untilTs,
    'prevSince'       => $prevSince,
    'prevUntil'       => $prevUntil,
    'prevSinceTs'     => $prevSinceTs,
    'prevUntilTs'     => $prevUntilTs
];
?>
