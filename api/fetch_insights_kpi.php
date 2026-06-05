<?php
/**
 * Fast API for fetching only top-level KPI metrics and chart data.
 */
require_once 'insights_config.php';

$cacheKey = 'insights_kpi_v4_' . $apiContext['configId'] . '_' . $apiContext['since'] . '_' . $apiContext['until'];

$useCache = !isset($_GET['nocache']) && !isset($_GET['_']);
if ($useCache) {
    $cachedData = SimpleCache::get($cacheKey);
    if ($cachedData) {
        echo json_encode($cachedData);
        exit;
    }
}

// Meta API limits insights to 93 days per query. We chunk into 90 days.
function getDateChunks($sinceTs, $untilTs, $maxDays = 90) {
    $chunks = [];
    $chunkSize = $maxDays * 24 * 60 * 60;
    $currentSince = $sinceTs;
    while ($currentSince <= $untilTs) {
        $currentUntil = min($currentSince + $chunkSize - 1, $untilTs);
        $chunks[] = ['since' => $currentSince, 'until' => $currentUntil];
        $currentSince = $currentUntil + 1;
    }
    return $chunks;
}

$chunks = getDateChunks($apiContext['sinceTs'], $apiContext['untilTs']);
$prevChunks = getDateChunks($apiContext['prevSinceTs'], $apiContext['prevUntilTs']);

$parallelUrls = [];

if (!empty($apiContext['pageId'])) {
    foreach ($chunks as $idx => $chunk) {
        $parallelUrls["page_metrics_{$idx}"] = "https://graph.facebook.com/v19.0/{$apiContext['pageId']}/insights"
                        . "?metric=page_impressions_unique,page_video_views,page_post_engagements,page_views_total"
                        . "&period=day"
                        . "&since={$chunk['since']}&until={$chunk['until']}"
                        . "&access_token={$apiContext['pageAccessToken']}";
    }

    foreach ($prevChunks as $idx => $chunk) {
        $parallelUrls["prev_page_metrics_{$idx}"] = "https://graph.facebook.com/v19.0/{$apiContext['pageId']}/insights"
                        . "?metric=page_impressions_unique,page_video_views,page_post_engagements,page_views_total"
                        . "&period=day"
                        . "&since={$chunk['since']}&until={$chunk['until']}"
                        . "&access_token={$apiContext['pageAccessToken']}";
    }
}

if (!empty($apiContext['igUserId'])) {
    foreach ($chunks as $idx => $chunk) {
        $parallelUrls["ig_nf_{$idx}"] = "https://graph.facebook.com/v19.0/{$apiContext['igUserId']}/insights?metric=reach&period=day&metric_type=total_value&breakdown=follow_type&since={$chunk['since']}&until={$chunk['until']}&access_token={$apiContext['accessToken']}";
    }
}

$parallelResults = fbApiGetMulti($parallelUrls);

$pageMetrics = [
    'page_views'         => 0,
    'three_second_views' => 0,
    'interactions'       => 0,
    'reach'              => 0
];
$dailyPageData = [];

if (!empty($apiContext['pageId'])) {
    foreach ($chunks as $idx => $chunk) {
        $pageMetricsResult = $parallelResults["page_metrics_{$idx}"] ?? null;

        if (isset($pageMetricsResult['data'])) {
            foreach ($pageMetricsResult['data'] as $metric) {
                $totalVal = 0;
                if (isset($metric['values'])) {
                    foreach ($metric['values'] as $val) {
                        $v = $val['value'] ?? 0;
                        if (!is_numeric($v)) continue;
                        $totalVal += $v;

                        if ($metric['name'] === 'page_views_total' || $metric['name'] === 'page_impressions_unique' || $metric['name'] === 'page_post_engagements') {
                            $dt = new DateTime($val['end_time']);
                            $dt->modify('-1 day');
                            $dayStr = $dt->format('Y-m-d');
                            if ($dayStr >= $apiContext['since'] && $dayStr <= $apiContext['until']) {
                                if (!isset($dailyPageData[$dayStr])) {
                                    $dailyPageData[$dayStr] = ['views' => 0, 'interactions' => 0];
                                }
                                if ($metric['name'] === 'page_impressions_unique') {
                                    $dailyPageData[$dayStr]['views'] += $v;
                                }
                                if ($metric['name'] === 'page_post_engagements') {
                                    $dailyPageData[$dayStr]['interactions'] += $v;
                                }
                                if ($metric['name'] === 'page_views_total') {
                                    if ($dailyPageData[$dayStr]['views'] === 0) {
                                        $dailyPageData[$dayStr]['views'] = $v;
                                    }
                                }
                            }
                        }
                    }
                }
                if ($metric['name'] === 'page_impressions_unique') $pageMetrics['reach'] += $totalVal;
                if ($metric['name'] === 'page_video_views') $pageMetrics['three_second_views'] += $totalVal;
                if ($metric['name'] === 'page_post_engagements') $pageMetrics['interactions'] += $totalVal;
                if ($metric['name'] === 'page_views_total') $pageMetrics['page_views'] += $totalVal;
            }
        }
    }
}

$prevPageMetrics = [
    'page_views'         => 0,
    'three_second_views' => 0,
    'interactions'       => 0,
    'reach'              => 0
];

if (!empty($apiContext['pageId'])) {
    foreach ($prevChunks as $idx => $chunk) {
        $prevPageMetricsResult = $parallelResults["prev_page_metrics_{$idx}"] ?? null;

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
                        if ($dayStr >= $apiContext['prevSince'] && $dayStr <= $apiContext['prevUntil']) {
                            $totalVal += $v;
                        }
                    }
                }
                if ($metric['name'] === 'page_impressions_unique') $prevPageMetrics['reach'] += $totalVal;
                if ($metric['name'] === 'page_video_views') $prevPageMetrics['three_second_views'] += $totalVal;
                if ($metric['name'] === 'page_post_engagements') $prevPageMetrics['interactions'] += $totalVal;
                if ($metric['name'] === 'page_views_total') $prevPageMetrics['page_views'] += $totalVal;
            }
        }
    }
}

$prevAllViews = max($prevPageMetrics['reach'], $prevPageMetrics['page_views']);

$reachBreakdown = [
    'follower' => 0,
    'non_follower' => 0,
    'follower_pct' => 0,
    'non_follower_pct' => 0
];

foreach ($chunks as $idx => $chunk) {
    $igNfData = $parallelResults["ig_nf_{$idx}"] ?? null;
    if (isset($igNfData['data'][0]['total_value']['breakdowns'][0]['results'])) {
        foreach ($igNfData['data'][0]['total_value']['breakdowns'][0]['results'] as $res) {
            $dim = $res['dimension_values'][0] ?? '';
            if ($dim === 'FOLLOWER') $reachBreakdown['follower'] += $res['value'];
            if ($dim === 'NON_FOLLOWER') $reachBreakdown['non_follower'] += $res['value'];
        }
    }
}

$totalReachBrk = $reachBreakdown['follower'] + $reachBreakdown['non_follower'];
if ($totalReachBrk > 0) {
    $reachBreakdown['follower_pct'] = round(($reachBreakdown['follower'] / $totalReachBrk) * 100, 1);
    $reachBreakdown['non_follower_pct'] = round(($reachBreakdown['non_follower'] / $totalReachBrk) * 100, 1);
}

ksort($dailyPageData);

$output = [
    'phase'           => 'kpi',
    'since'           => $apiContext['since'],
    'until'           => $apiContext['until'],
    'page_metrics'    => $pageMetrics,
    'previous_period' => [
        'views'        => $prevAllViews,
        'reach'        => $prevPageMetrics['reach'],
        'video_views'  => $prevPageMetrics['three_second_views'],
        'interactions' => $prevPageMetrics['interactions'],
        'page_views'   => $prevPageMetrics['page_views']
    ],
    'daily_timeline'  => empty($dailyPageData) ? new stdClass() : $dailyPageData,
    'reach_breakdown' => $reachBreakdown
];

SimpleCache::set($cacheKey, $output, 900);
echo json_encode($output);
?>
