<?php
/**
 * Slow API for fetching paginated content and per-media insights.
 */
require_once 'insights_config.php';

$cacheKey = 'insights_content_v4_' . $apiContext['configId'] . '_' . $apiContext['since'] . '_' . $apiContext['until'];

$useCache = !isset($_GET['nocache']) && !isset($_GET['_']);
if ($useCache) {
    $cachedData = SimpleCache::get($cacheKey);
    if ($cachedData) {
        echo json_encode($cachedData);
        exit;
    }
}

$allContent = [];
$fbApiError = null;
$igMediaData = [];
$seenIds = [];

// Base URLs
$fbNextUrl = !empty($apiContext['pageId']) ? "https://graph.facebook.com/v19.0/{$apiContext['pageId']}/published_posts?fields=id,message,created_time,permalink_url,full_picture,attachments{media_type,type},reactions.summary(total_count),comments.summary(total_count),shares,insights.metric(post_impressions_unique,post_clicks,post_reactions_like_total,post_video_views){values}&limit=50&access_token={$apiContext['pageAccessToken']}" : null;
$igNextUrl = !empty($apiContext['igUserId']) ? "https://graph.facebook.com/v19.0/{$apiContext['igUserId']}/media?fields=id,caption,media_type,media_url,permalink,timestamp,like_count,comments_count,media_product_type&limit=50&access_token={$apiContext['accessToken']}" : null;

$fbPageCount = 0;
$igPageCount = 0;
$maxPages = 4;

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
                if ($postDate >= $apiContext['since']) $allFbOlder = false;
                if ($postDate < $apiContext['since'] || $postDate > $apiContext['until']) continue;

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
    
    if (isset($results['ig'])) {
        $igRes = $results['ig'];
        if (!isset($igRes['data']) || empty($igRes['data'])) {
            $igNextUrl = null;
        } else {
            $allIgOlder = true;
            foreach ($igRes['data'] as $media) {
                $postDate = date('Y-m-d', strtotime($media['timestamp']));
                if ($postDate >= $apiContext['since']) $allIgOlder = false;
                if (!in_array($media['id'], $seenIds)) {
                    $igMediaData[] = $media;
                    $seenIds[] = $media['id'];
                }
            }
            $igNextUrl = $igRes['paging']['next'] ?? null;
        }
    }
}

if (!empty($igMediaData)) {
    $igItems = [];
    $igUrlsToFetch = [];
    
    foreach ($igMediaData as $media) {
        $postTs = strtotime($media['timestamp']);
        if ($postTs < $apiContext['sinceTs'] || $postTs > $apiContext['untilTs']) continue;

        $mediaProductType = $media['media_product_type'] ?? '';
        $mediaType = $media['media_type'] ?? '';

        if (strtoupper($mediaProductType) === 'STORY') {
            continue;
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
        
        $igUrlsToFetch[$media['id']] = "https://graph.facebook.com/v19.0/{$media['id']}/insights?metric={$metricsList}&access_token={$apiContext['accessToken']}";
    }
    
    $igInsightsResults = fbApiGetMulti($igUrlsToFetch);
    
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
                $item['watch_time'] = round($v / 1000);
            } elseif ($mName === 'ig_reels_avg_watch_time' && $item['watch_time'] === 0) {
                $item['watch_time'] = round(($v / 1000) * $item['views']);
            }
        }
        
        if ($item['type'] === 'post' || $item['type'] === 'carousel') {
            $item['interactions'] = $item['likes'] + $item['comments'] + $item['saved'];
        }
        if ($item['views'] === 0 && $item['reach'] > 0) {
            $item['views'] = $item['reach'];
        }
    }
    
    foreach ($igItems as $it) {
        $allContent[] = $it;
    }
}

// Group and aggregate content
usort($allContent, fn($a, $b) => strtotime($b['timestamp']) - strtotime($a['timestamp']));

$types = ['all', 'post', 'story', 'reel', 'carousel', 'live', 'facebook', 'instagram'];
$aggregated = [];

foreach ($types as $t) {
    $filtered = $allContent;
    if ($t !== 'all') {
        if ($t === 'facebook' || $t === 'instagram') {
            $filtered = array_filter($allContent, fn($c) => $c['platform'] === $t);
        } else {
            $filtered = array_filter($allContent, fn($c) => $c['type'] === $t);
        }
    }
    
    $totalViews = 0; $totalReach = 0; $totalLikes = 0; $totalComments = 0;
    $totalShares = 0; $totalSaves = 0; $totalWatchTime = 0; $totalInteractions = 0;

    foreach ($filtered as $c) {
        $totalViews       += $c['views'];
        $totalReach       += $c['reach'];
        $totalLikes       += $c['likes'];
        $totalComments    += $c['comments'];
        $totalShares      += $c['shares'];
        $totalSaves       += $c['saved'];
        $totalWatchTime   += $c['watch_time'];
        $totalInteractions+= $c['interactions'];
    }
    
    $count = count($filtered);
    $avgViews = $count > 0 ? $totalViews / $count : 0;
    $avgLikes = $count > 0 ? $totalLikes / $count : 0;
    $avgComments = $count > 0 ? $totalComments / $count : 0;
    $likeCommentRatio = $totalComments > 0 ? $totalLikes / $totalComments : $totalLikes;
    $saveRate = $totalViews > 0 ? ($totalSaves / $totalViews) * 100 : 0;
    $shareRate = $totalViews > 0 ? ($totalShares / $totalViews) * 100 : 0;
    
    $topContent = $filtered;
    usort($topContent, fn($a, $b) => $b['views'] - $a['views']);
    $topContent = array_slice($topContent, 0, 5);

    $postingHeatmap = [];
    foreach ($filtered as $c) {
        $dayName = date('D', strtotime($c['timestamp']));
        $hour = date('G', strtotime($c['timestamp']));
        $key = "{$dayName}-{$hour}";
        if (!isset($postingHeatmap[$key])) {
            $postingHeatmap[$key] = ['count' => 0, 'views' => 0];
        }
        $postingHeatmap[$key]['count']++;
        $postingHeatmap[$key]['views'] += $c['views'];
    }

    $aggregated[$t] = [
        'total_views'        => $totalViews,
        'total_reach'        => $totalReach,
        'total_likes'        => $totalLikes,
        'total_comments'     => $totalComments,
        'total_shares'       => $totalShares,
        'total_saves'        => $totalSaves,
        'total_watch_time'   => $totalWatchTime,
        'total_interactions' => $totalInteractions,
        'content_count'      => $count,
        'top_content'        => $topContent,
        'engagement_metrics' => [
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

$output = [
    'phase'       => 'content',
    'since'       => $apiContext['since'],
    'until'       => $apiContext['until'],
    'aggregated'  => $aggregated,
    'all_content' => $allContent
];

SimpleCache::set($cacheKey, $output, 900);
echo json_encode($output);
?>
