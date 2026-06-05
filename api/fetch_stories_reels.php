<?php
/**
 * Fetch Stories and Reels Insights API
 * Dedicated endpoint for granular stories and reels metrics.
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_config.php';
require_once __DIR__ . '/../includes/SimpleCache.php';

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

// Get active configuration
$stmt = $pdo->query("SELECT f.access_token, f.page_id, i.ig_user_id 
                     FROM facebook_config f 
                     LEFT JOIN instagram_config i ON f.id = i.facebook_config_id 
                     WHERE f.is_active = 1 LIMIT 1");
$config = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$config || empty($config['access_token']) || empty($config['ig_user_id'])) {
    echo json_encode(['error' => 'not_configured', 'message' => 'API is not configured or missing IG User ID.']);
    exit;
}

$accessToken = $config['access_token'];
$igUserId = $config['ig_user_id'];

$type = $_GET['type'] ?? 'reels'; // 'stories' or 'reels'
$since = $_GET['since'] ?? date('Y-m-d', strtotime('-30 days'));
$until = $_GET['until'] ?? date('Y-m-d');
$sinceTs = strtotime($since . ' 00:00:00');
$untilTs = strtotime($until . ' 23:59:59');

// Cache check
$cacheKey = 'stories_reels_' . $type . '_' . $since . '_' . $until;
if (!isset($_GET['nocache'])) {
    $cachedData = SimpleCache::get($cacheKey);
    if ($cachedData) {
        echo json_encode($cachedData);
        exit;
    }
}

$summary = [
    'total_views' => 0,
    'total_reach' => 0,
    'total_likes' => 0,
    'total_comments' => 0,
    'total_shares' => 0,
    'total_saves' => 0,
    'total_interactions' => 0,
    'total_replies' => 0,
    'total_exits' => 0,
    'total_taps_forward' => 0,
    'total_taps_back' => 0,
    'content_count' => 0
];

$items = [];

if ($type === 'reels') {
    // Fetch Reels
    $mediaUrl = "https://graph.facebook.com/v19.0/{$igUserId}/media?fields=id,caption,media_product_type,media_type,media_url,permalink,timestamp,like_count,comments_count&limit=50&access_token={$accessToken}";
    $mediaData = fbApiGet($mediaUrl);
    
    $urlsToFetch = [];
    if (isset($mediaData['data'])) {
        foreach ($mediaData['data'] as $media) {
            $postTs = strtotime($media['timestamp']);
            if ($postTs < $sinceTs || $postTs > $untilTs) continue;
            
            $productType = strtoupper($media['media_product_type'] ?? '');
            $mType = strtoupper($media['media_type'] ?? '');
            
            if ($productType === 'REELS' || $mType === 'VIDEO') {
                $items[$media['id']] = [
                    'id' => $media['id'],
                    'caption' => $media['caption'] ?? '',
                    'media_url' => $media['media_url'] ?? '',
                    'permalink' => $media['permalink'] ?? '',
                    'timestamp' => $media['timestamp'],
                    'base_likes' => $media['like_count'] ?? 0,
                    'base_comments' => $media['comments_count'] ?? 0,
                    'views' => 0,
                    'reach' => 0,
                    'likes' => 0,
                    'comments' => 0,
                    'shares' => 0,
                    'saved' => 0,
                    'total_interactions' => 0,
                    'avg_watch_time' => 0,
                    'total_watch_time' => 0,
                    'skip_rate' => 0,
                    'reposts' => 0,
                    'crossposted_views' => 0
                ];
                
                $metrics = 'views,reach,likes,comments,shares,saved,total_interactions,ig_reels_avg_watch_time,ig_reels_video_view_total_time,reels_skip_rate,reposts,crossposted_views';
                $urlsToFetch[$media['id']] = "https://graph.facebook.com/v19.0/{$media['id']}/insights?metric={$metrics}&access_token={$accessToken}";
            }
        }
    }
    
    $insightsData = fbApiGetMulti($urlsToFetch);
    
    foreach ($insightsData as $mediaId => $result) {
        if (!isset($result['data'])) continue;
        
        $item = &$items[$mediaId];
        foreach ($result['data'] as $metric) {
            $val = $metric['values'][0]['value'] ?? 0;
            switch ($metric['name']) {
                case 'views': $item['views'] = $val; break;
                case 'reach': $item['reach'] = $val; break;
                case 'likes': $item['likes'] = $val; break;
                case 'comments': $item['comments'] = $val; break;
                case 'shares': $item['shares'] = $val; break;
                case 'saved': $item['saved'] = $val; break;
                case 'total_interactions': $item['total_interactions'] = $val; break;
                case 'ig_reels_avg_watch_time': $item['avg_watch_time'] = round($val / 1000, 1); break;
                case 'ig_reels_video_view_total_time': $item['total_watch_time'] = round($val / 1000, 1); break;
                case 'reels_skip_rate': $item['skip_rate'] = $val; break;
                case 'reposts': $item['reposts'] = $val; break;
                case 'crossposted_views': $item['crossposted_views'] = $val; break;
            }
        }
        
        // Fallbacks if metric API fails to return likes/comments
        if ($item['likes'] === 0) $item['likes'] = $item['base_likes'];
        if ($item['comments'] === 0) $item['comments'] = $item['base_comments'];
        
        // Aggregation
        $summary['total_views'] += $item['views'];
        $summary['total_reach'] += $item['reach'];
        $summary['total_likes'] += $item['likes'];
        $summary['total_comments'] += $item['comments'];
        $summary['total_shares'] += $item['shares'];
        $summary['total_saves'] += $item['saved'];
        $summary['total_interactions'] += $item['total_interactions'];
        $summary['content_count']++;
    }

} else {
    // Fetch Stories
    $mediaUrl = "https://graph.facebook.com/v19.0/{$igUserId}/media?fields=id,caption,media_product_type,media_url,permalink,timestamp&limit=50&access_token={$accessToken}";
    $mediaData = fbApiGet($mediaUrl);
    
    $urlsToFetch = [];
    $navUrlsToFetch = [];
    if (isset($mediaData['data'])) {
        foreach ($mediaData['data'] as $media) {
            $postTs = strtotime($media['timestamp']);
            // Stories are ephemeral, but we filter anyway
            if ($postTs < $sinceTs || $postTs > $untilTs) continue;
            
            $productType = strtoupper($media['media_product_type'] ?? '');
            
            if ($productType === 'STORY') {
                $items[$media['id']] = [
                    'id' => $media['id'],
                    'caption' => $media['caption'] ?? '',
                    'media_url' => $media['media_url'] ?? '',
                    'permalink' => $media['permalink'] ?? '',
                    'timestamp' => $media['timestamp'],
                    'views' => 0,
                    'reach' => 0,
                    'replies' => 0,
                    'shares' => 0,
                    'exits' => 0,
                    'taps_forward' => 0,
                    'taps_back' => 0
                ];
                
                $metrics = 'views,reach,replies,shares';
                $urlsToFetch[$media['id']] = "https://graph.facebook.com/v19.0/{$media['id']}/insights?metric={$metrics}&access_token={$accessToken}";
                $navUrlsToFetch[$media['id']] = "https://graph.facebook.com/v19.0/{$media['id']}/insights?metric=navigation&breakdown=story_navigation_action_type&access_token={$accessToken}";
            }
        }
    }
    
    $insightsData = fbApiGetMulti($urlsToFetch);
    $navData = fbApiGetMulti($navUrlsToFetch);
    
    foreach ($insightsData as $mediaId => $result) {
        if (!isset($result['data'])) continue;
        
        $item = &$items[$mediaId];
        foreach ($result['data'] as $metric) {
            $val = $metric['values'][0]['value'] ?? 0;
            switch ($metric['name']) {
                case 'views': $item['views'] = $val; break;
                case 'reach': $item['reach'] = $val; break;
                case 'replies': $item['replies'] = $val; break;
                case 'shares': $item['shares'] = $val; break;
            }
        }
        
        // Process Navigation Breakdown
        if (isset($navData[$mediaId]['data'])) {
            foreach ($navData[$mediaId]['data'] as $navMetric) {
                if ($navMetric['name'] === 'navigation' && isset($navMetric['values'][0]['value']['story_navigation_action_type'])) {
                    $breakdowns = $navMetric['values'][0]['value']['story_navigation_action_type'];
                    // The API returns values in the breakdown array, typically:
                    // [{value: X, breakdown_value: 'taps_forward'}, {value: Y, breakdown_value: 'exits'}]
                    foreach ($breakdowns as $b) {
                        $bval = $b['value'] ?? 0;
                        switch ($b['breakdown_value']) {
                            case 'taps_forward': $item['taps_forward'] = $bval; break;
                            case 'taps_back': $item['taps_back'] = $bval; break;
                            case 'exits': $item['exits'] = $bval; break;
                        }
                    }
                }
            }
        }
        
        // Aggregation
        $summary['total_views'] += $item['views'];
        $summary['total_reach'] += $item['reach'];
        $summary['total_replies'] += $item['replies'];
        $summary['total_shares'] += $item['shares'];
        $summary['total_exits'] += $item['exits'];
        $summary['total_taps_forward'] += $item['taps_forward'];
        $summary['total_taps_back'] += $item['taps_back'];
        $summary['content_count']++;
    }
}

// Sort items by views descending
usort($items, function($a, $b) {
    return $b['views'] - $a['views'];
});

$output = [
    'type' => $type,
    'since' => $since,
    'until' => $until,
    'summary' => $summary,
    'items' => array_values($items)
];

// Cache for 15 minutes
SimpleCache::set($cacheKey, $output, 900);

echo json_encode($output);
?>
