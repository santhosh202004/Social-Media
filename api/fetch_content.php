<?php
/**
 * Fetch Content Performance Controller
 * Retrieves and ranks individual posts, reels, and stories from Facebook and Instagram.
 * Optimized with field expansion and caching.
 */

header('Content-Type: application/json');
require_once '../includes/auth.php';
require_once '../includes/db_config.php';
require_once '../includes/SimpleCache.php';

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

// Date range params
$since = $_GET['since'] ?? date('Y-m-d', strtotime('-30 days'));
$until = $_GET['until'] ?? date('Y-m-d');

$stmt2 = $pdo->query("SELECT id FROM facebook_config WHERE is_active = 1 LIMIT 1");
$cfgId = $stmt2->fetchColumn() ?? 'default';

$cacheKey = 'content_performance_v4_' . $cfgId . '_' . $since . '_' . $until;
if (!isset($_GET['nocache'])) {
    $cachedData = SimpleCache::get($cacheKey);
    if ($cachedData) {
        echo json_encode($cachedData);
        exit;
    }
}

$stmt = $pdo->query("SELECT f.access_token, f.page_access_token, f.page_id, i.ig_user_id 
                     FROM facebook_config f 
                     LEFT JOIN instagram_config i ON f.id = i.facebook_config_id 
                     WHERE f.is_active = 1 LIMIT 1");
$config = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$config || empty($config['access_token'])) {
    echo json_encode(['error' => 'not_configured']);
    exit;
}

$accessToken = $config['access_token'];
$pageId = $config['page_id'];
$igUserId = $config['ig_user_id'];

$contentData = [];

$pageToken = !empty($config['page_access_token']) ? $config['page_access_token'] : $accessToken;

// --- 1. Fetch Instagram Content (Media) ---
if (!empty($igUserId)) {
    // Fetch basic fields without insights first to prevent media_type errors
    $igFields = "id,caption,media_type,media_product_type,media_url,permalink,timestamp,like_count,comments_count";
        $igMediaUrl = "https://graph.facebook.com/v19.0/{$igUserId}/media"
        . "?fields=" . urlencode($igFields)
        . "&limit=100"
        . "&since=" . strtotime($since) . "&until=" . strtotime($until)
        . "&access_token={$pageToken}";

    $nextUrl = $igMediaUrl;
    $igPageCount = 0;
    $igMaxPages = 3; // Cap pagination — UI only shows top 24, so 150 items is plenty
    while ($nextUrl && $igPageCount < $igMaxPages) {
        $igPageCount++;
        $igMediaResult = fbApiGet($nextUrl);
        if (!isset($igMediaResult['data'])) break;

        $urlsToFetch = [];
        $tempItems = [];

        foreach ($igMediaResult['data'] as $media) {
            // Filter by date range
            $postDate = date('Y-m-d', strtotime($media['timestamp']));
            if ($postDate > $until) continue;
            if ($postDate < $since) { $nextUrl = null; break; }

            $item = [
                'platform' => 'instagram',
                'id' => $media['id'],
                'type' => $media['media_type'],
                'caption' => $media['caption'] ?? '',
                'url' => $media['permalink'] ?? '',
                'media_url' => $media['media_url'] ?? '',
                'timestamp' => $media['timestamp'],
                'likes' => $media['like_count'] ?? 0,
                'comments' => $media['comments_count'] ?? 0,
                'views' => 0,
                'reach' => 0,
                'saves' => 0,
                'shares' => 0
            ];

            // Map type for UI - use media_product_type to distinguish Reels from Videos
            $productType = $media['media_product_type'] ?? '';
            if ($productType === 'REELS') $item['display_type'] = 'Reel';
            else if ($item['type'] === 'VIDEO') $item['display_type'] = 'Video';
            else if ($item['type'] === 'CAROUSEL_ALBUM') $item['display_type'] = 'Carousel';
            else $item['display_type'] = 'Post';

            $item['total_engagement'] = $item['likes'] + $item['comments'];
            $tempItems[$media['id']] = $item;

            // Prepare insights URL
            $validMetrics = ['reach', 'saved', 'shares'];
            if (in_array($productType, ['REELS', 'VIDEO']) || $item['type'] === 'VIDEO') {
                $validMetrics[] = 'views';
            }
            $metricStr = implode(',', $validMetrics);
            $urlsToFetch[$media['id']] = "https://graph.facebook.com/v19.0/{$media['id']}/insights?metric={$metricStr}&access_token={$pageToken}";
        }

        // Fetch all insights in parallel
        $insightsData = fbApiGetMulti($urlsToFetch);

        foreach ($tempItems as $mediaId => $item) {
            $igInsights = $insightsData[$mediaId] ?? null;
            if (isset($igInsights['data'])) {
                foreach ($igInsights['data'] as $metric) {
                    $val = $metric['values'][0]['value'] ?? 0;
                    if ($metric['name'] === 'reach') $item['reach'] = $val;
                    if ($metric['name'] === 'views') $item['views'] = $val;
                    if ($metric['name'] === 'saved') $item['saves'] = $val;
                    if ($metric['name'] === 'shares') $item['shares'] = $val;
                }
            }
            
            // Fallback for views on non-video
            if ($item['views'] === 0 && $item['reach'] > 0) $item['views'] = $item['reach'];
            
            $contentData[] = $item;
        }
        $nextUrl = $nextUrl ? ($igMediaResult['paging']['next'] ?? null) : null;
    }
}

// --- 2. Fetch Facebook Page Content (Posts) ---
if (!empty($pageId)) {
    $fbFields = "id,message,created_time,permalink_url,attachments{media_type},reactions.summary(total_count),comments.summary(total_count),shares";
    $fbPostsUrl = "https://graph.facebook.com/v19.0/{$pageId}/published_posts"
        . "?fields=" . urlencode($fbFields)
        . "&limit=100"
        . "&since=" . strtotime($since) . "&until=" . strtotime($until)
        . "&access_token={$pageToken}";

    $nextUrl = $fbPostsUrl;
    $fbPageCount = 0;
    $fbMaxPages = 3; // Cap pagination — UI only shows top 24, so 150 items is plenty
    while ($nextUrl && $fbPageCount < $fbMaxPages) {
        $fbPageCount++;
        $fbPostsResult = fbApiGet($nextUrl);
        if (empty($fbPostsResult['data'])) break;

        foreach ($fbPostsResult['data'] as $post) {
            // Filter by date range
            $postDate = date('Y-m-d', strtotime($post['created_time']));
            if ($postDate > $until) continue;
            if ($postDate < $since) { $nextUrl = null; break; }

            $type = 'Post';
            $mediaType = 'IMAGE';
            if (isset($post['attachments']['data'][0]['media_type'])) {
                $attachType = $post['attachments']['data'][0]['media_type'];
                if ($attachType === 'video') { $type = 'Video'; $mediaType = 'VIDEO'; }
                else if ($attachType === 'album') { $type = 'Carousel'; $mediaType = 'CAROUSEL_ALBUM'; }
            }

            $item = [
                'platform' => 'facebook',
                'id' => $post['id'],
                'type' => $mediaType,
                'display_type' => $type,
                'caption' => $post['message'] ?? '',
                'url' => $post['permalink_url'] ?? '',
                'media_url' => $post['full_picture'] ?? '',
                'timestamp' => $post['created_time'],
                'likes' => $post['reactions']['summary']['total_count'] ?? 0,
                'comments' => $post['comments']['summary']['total_count'] ?? 0,
                'shares' => $post['shares']['count'] ?? 0,
                'saves' => 0, // Not typically supported for FB posts natively via Graph API this easily
                'views' => 0,
                'reach' => 0,
                'total_engagement' => 0
            ];

            // Removed inline insights parsing for Facebook as it was deprecated
            // and engagement is calculated below from reactions + comments + shares
            
            // Recalculate total_engagement if Facebook's 'post_engaged_users' is 0
            if ($item['total_engagement'] == 0) {
                $item['total_engagement'] = $item['likes'] + $item['comments'] + $item['shares'];
            }
            
            $contentData[] = $item;
        }
        $nextUrl = $nextUrl ? ($fbPostsResult['paging']['next'] ?? null) : null;
    }
}

// Sort by views descending
usort($contentData, function ($a, $b) {
    return $b['views'] - $a['views'];
});

// Cache result for 30 minutes
SimpleCache::set($cacheKey, $contentData, 1800);

echo json_encode($contentData);
?>