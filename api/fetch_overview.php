<?php
/**
 * Fetch Overview Metrics Controller
 * Retrieves high-level KPI metrics (followers, reach, views) for the dashboard overview.
 * Optimized with field expansion and caching.
 */

header('Content-Type: application/json');
error_reporting(0);
ob_start();

try {
require_once '../includes/auth.php';
require_once '../includes/db_config.php';
require_once '../includes/SimpleCache.php';

// Helper function for API requests
function fbApiGet($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
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
            CURLOPT_TIMEOUT        => 30,
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

// Helper for 30-day IG Insights chunks
function getIGDateChunks($sinceTs, $untilTs) {
    $chunks = [];
    $thirtyDays = 30 * 24 * 60 * 60;
    $curr = $sinceTs;
    while ($curr <= $untilTs) {
        $end = min($curr + $thirtyDays - 1, $untilTs);
        $chunks[] = ['since' => $curr, 'until' => $end];
        $curr = $end + 1;
    }
    return empty($chunks) ? [['since' => $sinceTs, 'until' => $untilTs]] : $chunks;
}

// Check cache
$since = $_GET['since'] ?? date('Y-m-d', strtotime('-30 days'));
$until = $_GET['until'] ?? date('Y-m-d');
$cacheKey = 'overview_metrics_' . $since . '_' . $until;

if (!isset($_GET['nocache'])) {
    $cachedData = SimpleCache::get($cacheKey);
    if ($cachedData) {
        echo json_encode($cachedData);
        exit;
    }
}

// Get active configuration
$stmt = $pdo->query("SELECT f.access_token, f.page_access_token, f.page_id, i.ig_user_id 
                     FROM facebook_config f 
                     LEFT JOIN instagram_config i ON f.id = i.facebook_config_id 
                     WHERE f.is_active = 1 LIMIT 1");
$config = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$config || empty($config['access_token'])) {
    echo json_encode(['error' => 'not_configured', 'message' => 'API is not configured.']);
    exit;
}

$accessToken = $config['access_token'];
$pageId = $config['page_id'];
$igUserId = $config['ig_user_id'];

$metrics = [
    'facebook' => ['followers' => 0, 'new_followers' => 0, 'reach' => 0, 'views' => 0, 'interactions' => 0],
    'instagram' => ['followers' => 0, 'new_followers' => 0, 'reach' => 0, 'views' => 0, 'follower_views' => 0, 'non_follower_views' => 0, 'interactions' => 0, 'profile_visits' => 0, 'website_clicks' => 0],
    'total' => ['followers' => 0, 'new_followers' => 0, 'reach' => 0, 'views' => 0, 'interactions' => 0, 'posts_count' => 0, 'reels_count' => 0],
    'content_inventory' => ['fb_posts' => 0, 'fb_videos' => 0, 'ig_posts' => 0, 'ig_reels' => 0]
];

$dateParams = "&since=" . strtotime($since) . "&until=" . strtotime($until);

$periodDates = [];
$curr = strtotime($since);
$endTs = strtotime($until);
while ($curr <= $endTs) {
    $dStr = date('Y-m-d', $curr);
    $periodDates[$dStr] = ['reach' => 0, 'views' => 0];
    $curr = strtotime("+1 day", $curr);
}

$pageToken = !empty($config['page_access_token']) ? $config['page_access_token'] : $accessToken;

// --- Prepare Batched Parallel API Calls ---
$urlsToFetch = [];
if (!empty($pageId)) {
    $fbMetrics = "page_impressions_unique,page_posts_impressions,page_daily_follows,page_daily_unfollows";
    $urlsToFetch['fb_metrics'] = "https://graph.facebook.com/v19.0/{$pageId}?fields=followers_count,insights.metric({$fbMetrics}).period(day){$dateParams}&access_token={$pageToken}";
    $urlsToFetch['fb_posts'] = "https://graph.facebook.com/v19.0/{$pageId}/published_posts?fields=created_time,attachments{media_type}&limit=100{$dateParams}&access_token={$pageToken}";
}

if (!empty($igUserId)) {
    $urlsToFetch['ig_profile'] = "https://graph.facebook.com/v19.0/{$igUserId}?fields=followers_count&access_token={$accessToken}";
    $urlsToFetch['ig_media'] = "https://graph.facebook.com/v19.0/{$igUserId}/media?fields=media_product_type,timestamp&limit=100{$dateParams}&access_token={$accessToken}";
    
    $igChunks = getIGDateChunks(strtotime($since), strtotime($until));
    foreach ($igChunks as $idx => $chunk) {
        $dParamsChunk = "&since=" . $chunk['since'] . "&until=" . $chunk['until'];
        $urlsToFetch["ig_reach_$idx"] = "https://graph.facebook.com/v19.0/{$igUserId}/insights?metric=reach,views&period=day&metric_type=total_value{$dParamsChunk}&access_token={$accessToken}";
        $urlsToFetch["ig_profile_data_$idx"] = "https://graph.facebook.com/v19.0/{$igUserId}/insights?metric=profile_views,website_clicks&period=day&metric_type=total_value{$dParamsChunk}&access_token={$accessToken}";
        $urlsToFetch["ig_fc_$idx"] = "https://graph.facebook.com/v19.0/{$igUserId}/insights?metric=follower_count&period=day{$dParamsChunk}&access_token={$accessToken}";
        $urlsToFetch["ig_nf_$idx"] = "https://graph.facebook.com/v19.0/{$igUserId}/insights?metric=reach&period=day&metric_type=total_value&breakdown=follow_type{$dParamsChunk}&access_token={$accessToken}";
    }
}

$multiData = fbApiGetMulti($urlsToFetch);

// --- 1. Facebook Page Metrics (Batched) ---
if (!empty($pageId)) {
    $fbData = $multiData['fb_metrics'] ?? null;

    if (isset($fbData['followers_count'])) {
        $metrics['facebook']['followers'] = $fbData['followers_count'];
    }

    if (isset($fbData['insights']['data'])) {
        $fbFollows = 0;
        $fbUnfollows = 0;
        foreach ($fbData['insights']['data'] as $m) {
            $totalVal = 0;
            $mName = $m['name'];
            foreach ($m['values'] as $v) {
                $totalVal += $v['value'] ?? 0;
                
                // Add to daily trend
                $dStr = substr($v['end_time'], 0, 10);
                if (isset($periodDates[$dStr])) {
                    if ($mName === 'page_impressions_unique') {
                        $periodDates[$dStr]['reach'] += $v['value'] ?? 0;
                    }
                    if ($mName === 'page_posts_impressions') {
                        $periodDates[$dStr]['views'] += $v['value'] ?? 0;
                    }
                }
            }
            if ($mName === 'page_impressions_unique')
                $metrics['facebook']['reach'] = $totalVal;
            if ($mName === 'page_posts_impressions')
                $metrics['facebook']['views'] = $totalVal;
            if ($mName === 'page_daily_follows')
                $fbFollows = $totalVal;
            if ($mName === 'page_daily_unfollows')
                $fbUnfollows = $totalVal;
        }
        $metrics['facebook']['new_followers'] = $fbFollows - $fbUnfollows;
    }

    // Content Inventory (Limit 100)
    $fbPosts = $multiData['fb_posts'] ?? null;
    foreach ($fbPosts['data'] ?? [] as $post) {
        $pDate = date('Y-m-d', strtotime($post['created_time']));
        if ($pDate >= $since && $pDate <= $until) {
            $isVid = false;
            foreach ($post['attachments']['data'] ?? [] as $att)
                if ($att['media_type'] === 'video')
                    $isVid = true;
            if ($isVid)
                $metrics['content_inventory']['fb_videos']++;
            else
                $metrics['content_inventory']['fb_posts']++;
        }
    }
}

// --- 2. Instagram Metrics ---
if (!empty($igUserId)) {
    // 2a. Basic Fields (Robust)
    $igProfile = $multiData['ig_profile'] ?? null;
    if (isset($igProfile['followers_count'])) {
        $metrics['instagram']['followers'] = $igProfile['followers_count'];
    }

    // 2b-1. Reach & Views — period=day with metric_type=total_value
    $igChunks = getIGDateChunks(strtotime($since), strtotime($until));
    
    // Aggregate ig_reach
    $reachTotal = 0;
    $viewsTotal = 0;
    $reachError = null;
    foreach ($igChunks as $idx => $chunk) {
        $igReachData = $multiData["ig_reach_$idx"] ?? null;
        if (isset($igReachData['data'])) {
            foreach ($igReachData['data'] as $m) {
                if ($m['name'] === 'reach') $reachTotal += $m['total_value']['value'] ?? 0;
                if ($m['name'] === 'views') $viewsTotal += $m['total_value']['value'] ?? 0;
                // Note: we're ignoring daily values aggregation for simplicity as they aren't strictly required
            }
        } else {
            $reachError = $igReachData['error']['message'] ?? 'unknown';
        }
    }
    if ($reachError) {
        $metrics['instagram']['_reach_error'] = $reachError;
    } else {
        $metrics['instagram']['reach'] = $reachTotal;
        $metrics['instagram']['views'] = $viewsTotal;
    }

    // 2b-2. Profile Views & Website Clicks — metric_type=total_value required
    $profileVisitsTotal = 0;
    $websiteClicksTotal = 0;
    $profileError = null;
    foreach ($igChunks as $idx => $chunk) {
        $igProfileData = $multiData["ig_profile_data_$idx"] ?? null;
        if (isset($igProfileData['data'])) {
            foreach ($igProfileData['data'] as $m) {
                if ($m['name'] === 'profile_views') $profileVisitsTotal += $m['total_value']['value'] ?? 0;
                if ($m['name'] === 'website_clicks') $websiteClicksTotal += $m['total_value']['value'] ?? 0;
            }
        } else {
            $profileError = $igProfileData['error']['message'] ?? 'unknown';
        }
    }
    if ($profileError) {
        $metrics['instagram']['_profile_error'] = $profileError;
    } else {
        $metrics['instagram']['profile_visits'] = $profileVisitsTotal;
        $metrics['instagram']['website_clicks'] = $websiteClicksTotal;
    }

    // 2b-3. follower_count — incompatible with metric_type=total_value, use simple period=day
    $totalFc = 0;
    foreach ($igChunks as $idx => $chunk) {
        $igFcData = $multiData["ig_fc_$idx"] ?? null;
        if (isset($igFcData['data'][0]['values'])) {
            foreach ($igFcData['data'][0]['values'] as $v) {
                $totalFc += $v['value'] ?? 0;
            }
        }
    }
    $metrics['instagram']['new_followers'] = $totalFc;

    // Content Inventory
    $igMedia = $multiData['ig_media'] ?? null;
    foreach ($igMedia['data'] ?? [] as $m) {
        $mDate = date('Y-m-d', strtotime($m['timestamp']));
        if ($mDate >= $since && $mDate <= $until) {
            if (($m['media_product_type'] ?? '') === 'REELS')
                $metrics['content_inventory']['ig_reels']++;
            else
                $metrics['content_inventory']['ig_posts']++;
        }
    }
    
    // Compute IG non-follower reach % using separate breakdown call
    $igNfReach = 0;
    $igFReach = 0;
    $nfError = null;
    
    foreach ($igChunks as $idx => $chunk) {
        $igNfData = $multiData["ig_nf_$idx"] ?? null;
        if (isset($igNfData['data'][0]['total_value']['breakdowns'][0]['results'])) {
            foreach ($igNfData['data'][0]['total_value']['breakdowns'][0]['results'] as $res) {
                $dim = $res['dimension_values'][0] ?? '';
                if ($dim === 'FOLLOWER') $igFReach += $res['value'];
                if ($dim === 'NON_FOLLOWER') $igNfReach += $res['value'];
            }
        } else {
            $nfError = $igNfData['error']['message'] ?? 'API error';
        }
    }
    
    if ($nfError && $igFReach === 0 && $igNfReach === 0) {
        $metrics['instagram']['_breakdown_error'] = $nfError;
    } else {
        $igTotalReachCalc = $igFReach + $igNfReach;
        $metrics['instagram']['non_follower_views_pct'] = $igTotalReachCalc > 0 ? round(($igNfReach / $igTotalReachCalc) * 100, 1) : 0;
    }
}

// --- 3. Totals ---
$metrics['total']['followers'] = $metrics['facebook']['followers'] + $metrics['instagram']['followers'];
$metrics['total']['new_followers'] = $metrics['facebook']['new_followers'] + $metrics['instagram']['new_followers'];
$metrics['total']['reach'] = $metrics['facebook']['reach'] + $metrics['instagram']['reach'];
$metrics['total']['views'] = $metrics['facebook']['views'] + $metrics['instagram']['views'];
$metrics['total']['interactions'] = $metrics['facebook']['interactions'] + $metrics['instagram']['interactions'];
$metrics['total']['posts_count'] = $metrics['content_inventory']['fb_posts'] + $metrics['content_inventory']['ig_posts'] + $metrics['content_inventory']['fb_videos'] + $metrics['content_inventory']['ig_reels'];
$metrics['total']['reels_count'] = $metrics['content_inventory']['ig_reels'];

// Build daily trend array
$trendData = [];
foreach ($periodDates as $d => $vals) {
    $trendData[] = [
        'date' => date('j M', strtotime($d)),
        'reach' => $vals['reach'],
        'views' => $vals['views']
    ];
}

$metrics['trend'] = $trendData;

$metrics['overview'] = [
    'total_views' => $metrics['total']['views'],
    'accounts_reached' => $metrics['total']['reach'],
    'non_follower_views_pct' => $metrics['instagram']['non_follower_views_pct'] ?? 0,
    'profile_visits' => $metrics['instagram']['profile_visits'],
    'website_clicks' => $metrics['instagram']['website_clicks']
];

// Cache for 30 minutes, but only if we have successful data
$shouldCache = empty($metrics['instagram']['_breakdown_error']) && empty($metrics['instagram']['_reach_error']);
if ($shouldCache) {
    SimpleCache::set($cacheKey, $metrics, 1800);
}

$output = json_encode($metrics);
ob_end_clean();
echo $output;

} catch (\Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['error' => 'server_error', 'message' => 'Internal Server Error: ' . $e->getMessage()]);
}
