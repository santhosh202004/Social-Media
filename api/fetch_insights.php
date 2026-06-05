<?php
/**
 * Fetch Content Insights Controller
 * Retrieves content overview metrics broken down by type.
 */
set_time_limit(60); // Allow up to 60 seconds for API calls

header('Content-Type: application/json');
if (php_sapi_name() !== 'cli') {
    require_once '../includes/auth.php';
}
require_once '../includes/db_config.php';
require_once '../includes/SimpleCache.php';

// Helper for single API calls using cURL (faster/more reliable than file_get_contents)
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
$stmt = $pdo->query("SELECT f.id, f.access_token, f.page_access_token, f.page_id, i.ig_user_id 
                     FROM facebook_config f 
                     LEFT JOIN instagram_config i ON f.id = i.facebook_config_id 
                     WHERE f.is_active = 1 LIMIT 1");
$config = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$config || empty($config['access_token'])) {
    echo json_encode(['error' => 'not_configured', 'message' => 'API is not configured.']);
    exit;
}

// Check cache (skip if nocache param or _= cache-buster present)
$since = $_GET['since'] ?? date('Y-m-d', strtotime('-6 months'));
$until = $_GET['until'] ?? date('Y-m-d');
$cacheKey = 'insights_v4_' . $config['id'] . '_' . $since . '_' . $until;

$useCache = !isset($_GET['nocache']) && !isset($_GET['_']);
if ($useCache) {
    $cachedData = SimpleCache::get($cacheKey);
    if ($cachedData) {
        echo json_encode($cachedData);
        exit;
    }
}

$accessToken = $config['access_token'];
$pageId      = trim($config['page_id'] ?? '');
$igUserId    = trim($config['ig_user_id'] ?? '');
$configId    = $config['id'];
$discoveryLog = [];

// -------------------------------------------------------
// TOKEN EXCHANGE: Use stored Page Access Token
// -------------------------------------------------------
$pageAccessToken = !empty($config['page_access_token']) ? $config['page_access_token'] : $accessToken;
$discoveryLog['final_page_id'] = $pageId;
$discoveryLog['has_page_token'] = ($pageAccessToken !== $accessToken);

// AUTO-DISCOVERY: If ig_user_id is missing, find it from the page
if (empty($igUserId) && !empty($pageId)) {
    $igDiscoverUrl = "https://graph.facebook.com/v19.0/{$pageId}?fields=instagram_business_account&access_token={$pageAccessToken}";
    $igDiscoverData = fbApiGet($igDiscoverUrl);
    $discoveryLog['ig_discover_raw'] = $igDiscoverData;

    if (isset($igDiscoverData['instagram_business_account']['id'])) {
        $igUserId = $igDiscoverData['instagram_business_account']['id'];
        $discoveryLog['found_ig_user_id'] = $igUserId;

        $igCheck = $pdo->prepare("SELECT id FROM instagram_config WHERE facebook_config_id = ?");
        $igCheck->execute([$configId]);
        if ($igCheck->fetch()) {
            $upd = $pdo->prepare("UPDATE instagram_config SET ig_user_id = ? WHERE facebook_config_id = ?");
            $upd->execute([$igUserId, $configId]);
        } else {
            $upd = $pdo->prepare("INSERT INTO instagram_config (facebook_config_id, ig_user_id) VALUES (?, ?)");
            $upd->execute([$configId, $igUserId]);
        }
    }
}

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

$allContent = [];

// -------------------------------------------------------
// Helper: Extract metric name from API response.
// The FB API sometimes returns 'name' key, other times only 'id' key
// where the metric name is embedded like: "pageId_postId/insights/METRIC_NAME/lifetime"
// -------------------------------------------------------
function extractMetricName($metric) {
    if (!empty($metric['name'])) return $metric['name'];
    if (!empty($metric['id'])) {
        // Parse from "...../insights/post_impressions_unique/lifetime"
        if (preg_match('/insights\/([a-z_]+)\//i', $metric['id'], $m)) {
            return $m[1];
        }
    }
    return '';
}

// -------------------------------------------------------
// 1 & 2. Fetch Facebook Posts and Instagram Media in Parallel
// -------------------------------------------------------
$fbApiError = null;
$igMediaData = [];
$seenIds = [];

$fbNextUrl = !empty($pageId) ? "https://graph.facebook.com/v19.0/{$pageId}/published_posts?fields=id,message,created_time,permalink_url,full_picture,attachments{media_type,type},reactions.summary(total_count),comments.summary(total_count),shares,insights.metric(post_impressions_unique,post_clicks,post_reactions_like_total,post_video_views){values}&limit=50&access_token={$pageAccessToken}" : null;
$igNextUrl = !empty($igUserId) ? "https://graph.facebook.com/v19.0/{$igUserId}/media?fields=id,caption,media_type,media_url,permalink,timestamp,like_count,comments_count,media_product_type&limit=50&access_token={$accessToken}" : null;

$fbPageCount = 0;
$igPageCount = 0;
$maxPages = 4; // Cap pagination to prevent runaway loops

$allFbOlder = false;
$allIgOlder = false;

while (($fbNextUrl && $fbPageCount < $maxPages && !$allFbOlder) || ($igNextUrl && $igPageCount < $maxPages && !$allIgOlder)) {
    $batch = [];
    if ($fbNextUrl && $fbPageCount < $maxPages && !$allFbOlder) {
        $batch['fb'] = $fbNextUrl;
        $fbPageCount++;
    }
    if ($igNextUrl && $igPageCount < $maxPages && !$allIgOlder) {
        $batch['ig'] = $igNextUrl;
        $igPageCount++;
    }
    
    if (empty($batch)) break;
    $results = fbApiGetMulti($batch);
    
    // Process FB Results
    if (isset($results['fb'])) {
        $fbRes = $results['fb'];
        if (isset($fbRes['error'])) {
            $fbApiError = $fbRes['error'];
            $fbNextUrl = null;
        } elseif (!isset($fbRes['data']) || empty($fbRes['data'])) {
            $fbNextUrl = null;
        } else {
            $allFbOlder = true;
            foreach ($fbRes['data'] as $post) {
                $postDate = date('Y-m-d', strtotime($post['created_time']));
                if ($postDate >= $since) $allFbOlder = false;
                if ($postDate < $since || $postDate > $until) continue;

                $type = 'post';
                if (isset($post['attachments']['data'][0]['type'])) {
                    $attachType = $post['attachments']['data'][0]['type'];
                    if ($attachType === 'video_inline') $type = 'reel';
                    elseif ($attachType === 'live_status' || strpos($attachType, 'live') !== false) $type = 'live';
                    elseif ($attachType === 'album') $type = 'carousel';
                } elseif (isset($post['attachments']['data'][0]['media_type'])) {
                    $mType = $post['attachments']['data'][0]['media_type'];
                    if ($mType === 'video') $type = 'reel';
                    elseif ($mType === 'album') $type = 'carousel';
                }

                $views = 0; $reach = 0; $clicks = 0; $reactions = 0;
                if (isset($post['insights']['data'])) {
                    foreach ($post['insights']['data'] as $metric) {
                        $val = $metric['values'][0]['value'] ?? 0;
                        $mName = extractMetricName($metric);
                        if ($mName === 'post_impressions_unique') { $reach = $val; if ($views === 0) $views = $val; }
                        if ($mName === 'post_clicks') $clicks = $val;
                        if ($mName === 'post_reactions_like_total') $reactions = $val;
                        if ($mName === 'post_video_views' && $val > 0) $views = $val;
                    }
                }
                $likes = $post['reactions']['summary']['total_count'] ?? $reactions;
                $comments = $post['comments']['summary']['total_count'] ?? 0;
                $shares = $post['shares']['count'] ?? 0;
                $interactions = $clicks + $likes + $comments + $shares;

                $allContent[] = [
                    'platform' => 'facebook', 'id' => $post['id'], 'type' => $type,
                    'caption' => $post['message'] ?? '', 'url' => $post['permalink_url'] ?? '',
                    'media_url' => $post['full_picture'] ?? '', 'timestamp' => $post['created_time'],
                    'views' => $views, 'reach' => $reach, 'likes' => $likes,
                    'comments' => $comments, 'shares' => $shares, 'saved' => 0,
                    'interactions' => $interactions, 'watch_time' => 0, 'replies' => 0,
                    'exits' => 0, 'taps_forward' => 0, 'taps_back' => 0
                ];
            }
            $fbNextUrl = $fbRes['paging']['next'] ?? null;
        }
    }
    
    // Process IG Results
    if (isset($results['ig'])) {
        $igRes = $results['ig'];
        if (!isset($igRes['data']) || empty($igRes['data'])) {
            $igNextUrl = null;
        } else {
            $allIgOlder = true;
            foreach ($igRes['data'] as $media) {
                $postDate = date('Y-m-d', strtotime($media['timestamp']));
                if ($postDate >= $since) $allIgOlder = false;
                if (!in_array($media['id'], $seenIds)) {
                    $igMediaData[] = $media;
                    $seenIds[] = $media['id'];
                }
            }
            $igNextUrl = $igRes['paging']['next'] ?? null;
        }
    }
}
    
    // Stories fetching completely removed per user request

    if (!empty($igMediaData)) {
        $igItems = [];
        $igUrlsToFetch = [];
        $navUrlsToFetch = [];
        
        foreach ($igMediaData as $media) {
            $postTs = strtotime($media['timestamp']);
            if ($postTs < $sinceTs || $postTs > $untilTs) continue;

            $mediaProductType = $media['media_product_type'] ?? '';
            $mediaType = $media['media_type'] ?? '';

            if (strtoupper($mediaProductType) === 'STORY') {
                continue; // Skip stories entirely
            } elseif (strtoupper($mediaProductType) === 'REELS' || $mediaType === 'VIDEO') {
                $type = 'reel';
                $metricsList = 'reach,views,likes,comments,shares,saved,total_interactions,ig_reels_avg_watch_time,ig_reels_video_view_total_time';
            } elseif ($mediaType === 'CAROUSEL_ALBUM') {
                $type = 'carousel';
                $metricsList = 'reach,impressions,saved';
            } else {
                $type = 'post';
                $metricsList = 'reach,impressions,saved';
            }

            $likes = $media['like_count'] ?? 0;
            $comments = $media['comments_count'] ?? 0;
            $interactions = $likes + $comments;

            $igItems[$media['id']] = [
                'platform'      => 'instagram',
                'id'            => $media['id'],
                'type'          => $type,
                'caption'       => $media['caption'] ?? '',
                'url'           => $media['permalink'] ?? '',
                'media_url'     => $media['media_url'] ?? '',
                'timestamp'     => $media['timestamp'],
                'views'         => 0,
                'reach'         => 0,
                'likes'         => $likes,
                'comments'      => $comments,
                'shares'        => 0,
                'saved'         => 0,
                'interactions'  => $interactions,
                'watch_time'    => 0,
                'replies'       => 0,
                'exits'         => 0,
                'taps_forward'  => 0,
                'taps_back'     => 0
            ];
            
            // Queue URL for parallel fetching
            $igUrlsToFetch[$media['id']] = "https://graph.facebook.com/v19.0/{$media['id']}/insights?metric={$metricsList}&access_token={$accessToken}";
        }
        
        // Execute parallel requests for main insights
        $igInsightsResults = fbApiGetMulti($igUrlsToFetch);
        
        // Story navigation removed
        $igNavResults = [];
        
        // Process main insights
        foreach ($igInsightsResults as $mediaId => $result) {
            if (!isset($result['data']) || !isset($igItems[$mediaId])) continue;
            
            $item = &$igItems[$mediaId];
            
            foreach ($result['data'] as $metric) {
                $v = $metric['values'][0]['value'] ?? 0;
                $mName = $metric['name'];
                
                if ($mName === 'views' || $mName === 'impressions' || $mName === 'plays') $item['views'] = $v;
                if ($mName === 'reach') $item['reach'] = $v;
                if ($mName === 'likes' && $v > 0) $item['likes'] = $v;
                if ($mName === 'comments' && $v > 0) $item['comments'] = $v;
                if ($mName === 'shares') $item['shares'] = $v;
                if ($mName === 'saved') $item['saved'] = $v;
                if ($mName === 'replies') $item['replies'] = $v;
                
                if ($mName === 'total_interactions') {
                    if ($v > 0) $item['interactions'] = $v;
                }
                
                if ($mName === 'ig_reels_video_view_total_time') {
                    $item['watch_time'] = round($v / 1000); // ms to sec
                } elseif ($mName === 'ig_reels_avg_watch_time' && $item['watch_time'] === 0) {
                    $item['watch_time'] = round(($v / 1000) * $item['views']);
                }
            }
            
            // Re-calculate interactions if needed
            if ($item['type'] === 'post' || $item['type'] === 'carousel') {
                $item['interactions'] = $item['likes'] + $item['comments'] + $item['saved'];
            }
            if ($item['views'] === 0 && $item['reach'] > 0) {
                $item['views'] = $item['reach'];
            }
        }

        // Process navigation results
        foreach ($igNavResults as $mediaId => $result) {
            if (!isset($result['data']) || !isset($igItems[$mediaId])) continue;
            $item = &$igItems[$mediaId];
            foreach ($result['data'] as $navMetric) {
                if ($navMetric['name'] === 'navigation' && isset($navMetric['values'][0]['value']['story_navigation_action_type'])) {
                    $breakdowns = $navMetric['values'][0]['value']['story_navigation_action_type'];
                    foreach ($breakdowns as $b) {
                        $bval = $b['value'] ?? 0;
                        switch ($b['breakdown_value']) {
                            case 'taps_forward': $item['taps_forward'] = $bval; break;
                            case 'taps_back':    $item['taps_back']    = $bval; break;
                            case 'exits':        $item['exits']        = $bval; break;
                        }
                    }
                }
            }
        }
        
        foreach ($igItems as $it) {
            $allContent[] = $it;
        }
    }

// -------------------------------------------------------
// 3. Aggregate metrics by type
// -------------------------------------------------------
$types = ['all', 'post', 'story', 'reel', 'carousel', 'live', 'facebook', 'instagram'];
$aggregated = [];

foreach ($types as $t) {
    if ($t === 'all') {
        $filtered = $allContent;
    } elseif (in_array($t, ['facebook', 'instagram'])) {
        $filtered = array_filter($allContent, fn($c) => $c['platform'] === $t);
    } else {
        $filtered = array_filter($allContent, fn($c) => $c['type'] === $t);
    }
    $filtered = array_values($filtered);

    $totalViews = array_sum(array_column($filtered, 'views'));
    $totalReach = array_sum(array_column($filtered, 'reach'));
    $totalInteractions = array_sum(array_column($filtered, 'interactions'));
    $totalWatchTime = array_sum(array_column($filtered, 'watch_time'));
    $totalLikes = array_sum(array_column($filtered, 'likes'));
    $totalComments = array_sum(array_column($filtered, 'comments'));
    $totalShares = array_sum(array_column($filtered, 'shares'));
    $totalSaves = array_sum(array_column($filtered, 'saved'));
    $totalReplies = array_sum(array_column($filtered, 'replies'));
    $totalExits = array_sum(array_column($filtered, 'exits'));
    $totalTapsForward = array_sum(array_column($filtered, 'taps_forward'));
    $totalTapsBack = array_sum(array_column($filtered, 'taps_back'));
    $count = count($filtered);

    // Build daily timeline
    $dailyViews = [];
    foreach ($filtered as $item) {
        $day = date('Y-m-d', strtotime($item['timestamp']));
        if (!isset($dailyViews[$day])) {
            $dailyViews[$day] = ['views' => 0, 'organic' => 0, 'ads' => 0];
        }
        $dailyViews[$day]['views'] += $item['views'];
        $dailyViews[$day]['organic'] += $item['views']; 
    }
    ksort($dailyViews);

    // Top content (sorted by views desc)
    usort($filtered, fn($a, $b) => $b['views'] - $a['views']);
    $topContent = array_slice($filtered, 0, 50);

    // Calculate engagement metrics
    $engagementRate = $totalReach > 0 ? ($totalInteractions / $totalReach) * 100 : 0;
    $avgViews = $count > 0 ? $totalViews / $count : 0;
    $avgLikes = $count > 0 ? $totalLikes / $count : 0;
    $avgComments = $count > 0 ? $totalComments / $count : 0;
    $saveRate = $totalReach > 0 ? ($totalSaves / $totalReach) * 100 : 0;
    $shareRate = $totalReach > 0 ? ($totalShares / $totalReach) * 100 : 0;
    $likeCommentRatio = $totalComments > 0 ? $totalLikes / $totalComments : $totalLikes;

    // Posting heatmap data
    $postingHeatmap = [];
    for ($d = 0; $d <= 6; $d++) {
        for ($h = 0; $h < 24; $h++) {
            $postingHeatmap[$d][$h] = ['count' => 0, 'views' => 0, 'avg_views' => 0];
        }
    }
    
    foreach ($filtered as $item) {
        $ts = strtotime($item['timestamp']);
        $dayOfWeek = date('w', $ts); // 0 (Sun) - 6 (Sat)
        $hourOfDay = date('G', $ts); // 0 - 23
        
        $postingHeatmap[$dayOfWeek][$hourOfDay]['count']++;
        $postingHeatmap[$dayOfWeek][$hourOfDay]['views'] += $item['views'];
    }
    
    for ($d = 0; $d <= 6; $d++) {
        for ($h = 0; $h < 24; $h++) {
            if ($postingHeatmap[$d][$h]['count'] > 0) {
                $postingHeatmap[$d][$h]['avg_views'] = $postingHeatmap[$d][$h]['views'] / $postingHeatmap[$d][$h]['count'];
            }
        }
    }

    $aggregated[$t] = [
        'total_views'        => $totalViews,
        'total_reach'        => $totalReach,
        'total_interactions' => $totalInteractions,
        'total_watch_time'   => $totalWatchTime,
        'total_likes'        => $totalLikes,
        'total_comments'     => $totalComments,
        'total_shares'       => $totalShares,
        'total_saves'        => $totalSaves,
        'total_replies'      => $totalReplies,
        'total_exits'        => $totalExits,
        'total_taps_forward' => $totalTapsForward,
        'total_taps_back'    => $totalTapsBack,
        'content_count'      => $count,
        'daily_timeline'     => $dailyViews,
        'top_content'        => $topContent,
        'engagement_metrics' => [
            'engagement_rate'       => round($engagementRate, 2),
            'avg_views_per_post'    => round($avgViews, 1),
            'avg_likes_per_post'    => round($avgLikes, 1),
            'avg_comments_per_post' => round($avgComments, 1),
            'save_rate'             => round($saveRate, 2),
            'share_rate'            => round($shareRate, 2),
            'like_comment_ratio'    => round($likeCommentRatio, 2)
        ],
        'posting_heatmap'    => $postingHeatmap
    ];
}

// -------------------------------------------------------
// 4. Fetch Page-level insights (Phase 3 & 4)
//    This is the PRIMARY data source for the "All" tab.
//    Uses only metrics confirmed to work on this page type.
// -------------------------------------------------------
// -------------------------------------------------------
// 4 & 5. Page-level insights + Previous Period + IG Reach Breakdown
//         ALL fetched in PARALLEL to eliminate sequential waits
// -------------------------------------------------------
$parallelUrls = [];

if (!empty($pageId)) {
    $parallelUrls['page_metrics'] = "https://graph.facebook.com/v19.0/{$pageId}/insights"
                    . "?metric=page_impressions_unique,page_video_views,page_post_engagements,page_views_total"
                    . "&period=day"
                    . "&since={$sinceTs}&until={$untilTs}"
                    . "&access_token={$pageAccessToken}";

    $parallelUrls['prev_page_metrics'] = "https://graph.facebook.com/v19.0/{$pageId}/insights"
                    . "?metric=page_impressions_unique,page_video_views,page_post_engagements,page_views_total"
                    . "&period=day"
                    . "&since={$prevSinceTs}&until={$prevUntilTs}"
                    . "&access_token={$pageAccessToken}";
}

if (!empty($igUserId)) {
    $parallelUrls['ig_nf'] = "https://graph.facebook.com/v19.0/{$igUserId}/insights?metric=reach&period=day&metric_type=total_value&breakdown=follow_type&since={$sinceTs}&until={$untilTs}&access_token={$accessToken}";
}

$parallelResults = fbApiGetMulti($parallelUrls);

// --- Process Page Metrics ---
if (!empty($pageId)) {
    $pageMetricsResult = $parallelResults['page_metrics'] ?? null;

    $pageMetrics = [
        'page_views'         => 0,
        'three_second_views' => 0,
        'interactions'       => 0,
        'reach'              => 0
    ];
    $dailyPageData = [];

    if (isset($pageMetricsResult['data'])) {
        foreach ($pageMetricsResult['data'] as $metric) {
            $totalVal = 0;
            if (isset($metric['values'])) {
                foreach ($metric['values'] as $val) {
                    $v = $val['value'] ?? 0;
                    if (!is_numeric($v)) continue;
                    $totalVal += $v;

                    if ($metric['name'] === 'page_views_total' || $metric['name'] === 'page_impressions_unique') {
                        $dt = new DateTime($val['end_time']);
                        $dt->modify('-1 day');
                        $dayStr = $dt->format('Y-m-d');
                        if ($dayStr >= $since && $dayStr <= $until) {
                            if (!isset($dailyPageData[$dayStr])) {
                                $dailyPageData[$dayStr] = ['views' => 0, 'organic' => 0, 'ads' => 0];
                            }
                            if ($metric['name'] === 'page_impressions_unique') {
                                $dailyPageData[$dayStr]['views'] = $v;
                                $dailyPageData[$dayStr]['organic'] = $v;
                            }
                            if ($metric['name'] === 'page_views_total') {
                                if ($dailyPageData[$dayStr]['views'] === 0) {
                                    $dailyPageData[$dayStr]['views'] = $v;
                                    $dailyPageData[$dayStr]['organic'] = $v;
                                }
                            }
                        }
                    }
                }
            }
            if ($metric['name'] === 'page_impressions_unique') $pageMetrics['reach'] = $totalVal;
            if ($metric['name'] === 'page_video_views') $pageMetrics['three_second_views'] = $totalVal;
            if ($metric['name'] === 'page_post_engagements') $pageMetrics['interactions'] = $totalVal;
            if ($metric['name'] === 'page_views_total') $pageMetrics['page_views'] = $totalVal;
        }
    }

    $aggregated['page_metrics'] = $pageMetrics;

    $allViews = max($pageMetrics['reach'], $pageMetrics['page_views']);
    if ($allViews > 0) {
        $aggregated['all']['total_views'] = $allViews;
    }
    if ($pageMetrics['interactions'] > 0) {
        $aggregated['all']['total_interactions'] = $pageMetrics['interactions'];
    }
    
    if ($pageMetrics['reach'] > 0) {
        $aggregated['all']['engagement_metrics']['engagement_rate'] = round(($pageMetrics['interactions'] / $pageMetrics['reach']) * 100, 2);
    } elseif ($allViews > 0) {
        $aggregated['all']['engagement_metrics']['engagement_rate'] = round(($pageMetrics['interactions'] / $allViews) * 100, 2);
    }

    ksort($dailyPageData);
    if (!empty($dailyPageData)) {
        $aggregated['all']['daily_timeline'] = $dailyPageData;
    }

    // --- Process Previous Period Metrics (already fetched in parallel) ---
    $prevPageMetricsResult = $parallelResults['prev_page_metrics'] ?? null;
    
    $prevPageMetrics = [
        'page_views'         => 0,
        'three_second_views' => 0,
        'interactions'       => 0,
        'reach'              => 0
    ];

    if (isset($prevPageMetricsResult['data'])) {
        foreach ($prevPageMetricsResult['data'] as $metric) {
            $totalVal = 0;
            if (isset($metric['values'])) {
                foreach ($metric['values'] as $val) {
                    $v = $val['value'] ?? 0;
                    if (!is_numeric($v)) continue;
                    $dt = new DateTime($val['end_time']);
                    $dt->modify('-1 day');
                    $dayStr = $dt->format('Y-m-d');
                    if ($dayStr >= $prevSince && $dayStr <= $prevUntil) {
                        $totalVal += $v;
                    }
                }
            }
            if ($metric['name'] === 'page_impressions_unique') $prevPageMetrics['reach'] = $totalVal;
            if ($metric['name'] === 'page_video_views') $prevPageMetrics['three_second_views'] = $totalVal;
            if ($metric['name'] === 'page_post_engagements') $prevPageMetrics['interactions'] = $totalVal;
            if ($metric['name'] === 'page_views_total') $prevPageMetrics['page_views'] = $totalVal;
        }
    }
    
    $prevAllViews = max($prevPageMetrics['reach'], $prevPageMetrics['page_views']);
    
    $aggregated['previous_period'] = [
        'views' => $prevAllViews,
        'reach' => $prevPageMetrics['reach'],
        'video_views' => $prevPageMetrics['three_second_views'],
        'interactions' => $prevPageMetrics['interactions'],
        'page_views' => $prevPageMetrics['page_views']
    ];
}

// --- Process Follower vs Non-follower Reach Breakdown (already fetched in parallel) ---
$reachBreakdown = [
    'follower' => 0,
    'non_follower' => 0,
    'follower_pct' => 0,
    'non_follower_pct' => 0
];

$igNfData = $parallelResults['ig_nf'] ?? null;
if (isset($igNfData['data'][0]['total_value']['breakdowns'][0]['results'])) {
    foreach ($igNfData['data'][0]['total_value']['breakdowns'][0]['results'] as $res) {
        $dim = $res['dimension_values'][0] ?? '';
        if ($dim === 'FOLLOWER') $reachBreakdown['follower'] += $res['value'];
        if ($dim === 'NON_FOLLOWER') $reachBreakdown['non_follower'] += $res['value'];
    }
}
$totalReachBrk = $reachBreakdown['follower'] + $reachBreakdown['non_follower'];
if ($totalReachBrk > 0) {
    $reachBreakdown['follower_pct'] = round(($reachBreakdown['follower'] / $totalReachBrk) * 100, 1);
    $reachBreakdown['non_follower_pct'] = round(($reachBreakdown['non_follower'] / $totalReachBrk) * 100, 1);
}
$aggregated['reach_breakdown'] = $reachBreakdown;

// -------------------------------------------------------
// Output
// -------------------------------------------------------
$output = [
    'since'   => $since,
    'until'   => $until,
    'data'    => $aggregated,
    'all_content' => $allContent
];

if (isset($_GET['debug'])) {
    $output['debug'] = [
        'page_id'           => $pageId,
        'ig_user_id'        => $igUserId,
        'since'             => $since,
        'until'             => $until,
        'since_ts'          => $sinceTs,
        'until_ts'          => $untilTs,
        'content_count'     => count($allContent),
        'ig_items_fetched'  => count($igUrlsToFetch),
        'discovery_log'     => $discoveryLog,
        'fb_api_error'      => $fbApiError,
        'fb_posts_processed_count'=> count(array_filter($allContent, fn($c) => $c['platform'] === 'facebook')),
        'ig_media_processed_count'=> count(array_filter($allContent, fn($c) => $c['platform'] === 'instagram')),
    ];
}

// Cache for 15 minutes
SimpleCache::set($cacheKey, $output, 900);

echo json_encode($output);
?>

