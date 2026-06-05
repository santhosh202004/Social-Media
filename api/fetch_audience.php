<?php
/**
 * Fetch Audience API Controller
 * Returns demographics (gender/age, cities, countries) + trends (daily follows)
 * for both Facebook and Instagram.
 */
header('Content-Type: application/json');
error_reporting(0);
ob_start();

try {
require_once '../includes/auth.php';
require_once '../includes/db_config.php';
require_once '../includes/SimpleCache.php';

function apiGet($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_ENCODING => "",
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4
    ]);
    $res = curl_exec($ch);
    $data = $res ? json_decode($res, true) : null;
    curl_close($ch);
    return $data;
}

function apiGetMulti(array $urls): array {
    if (empty($urls)) return [];
    
    $mh = curl_multi_init();
    $handles = [];
    
    foreach ($urls as $key => $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_ENCODING => "",
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4
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

function extractMetricName($metric) {
    if (!empty($metric['name'])) return $metric['name'];
    if (!empty($metric['id'])) {
        if (preg_match('/insights\/([a-z_]+)\//i', $metric['id'], $m)) return $m[1];
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
    echo json_encode(['error' => 'not_configured']);
    exit;
}

// Check cache
$since = $_GET['since'] ?? date('Y-m-d', strtotime('-28 days'));
$until = $_GET['until'] ?? date('Y-m-d');
$cacheKey = 'audience_metrics_' . $config['id'] . '_' . $since . '_' . $until;

if (!isset($_GET['nocache'])) {
    $cachedData = SimpleCache::get($cacheKey);
    if ($cachedData) {
        echo json_encode($cachedData);
        exit;
    }
}

$accessToken = $config['access_token'];
$pageId = trim($config['page_id'] ?? '');
$igUserId = trim($config['ig_user_id'] ?? '');

// Date range from query params
$since = $_GET['since'] ?? date('Y-m-d', strtotime('-28 days'));
$until = $_GET['until'] ?? date('Y-m-d');

$pageAccessToken = !empty($config['page_access_token']) ? $config['page_access_token'] : $accessToken;

// =====================================================
// Response structure
// =====================================================
$response = [
    'facebook' => [
        'followers'         => 0,
        'gender_age'        => [],
        'cities'            => [],
        'countries'         => [],
        'daily_follows'     => [],
    ],
    'instagram' => [
        'followers'           => 0,
        'gender_age'          => [],
        'age_breakdown'       => [],
        'gender_breakdown'    => [],
        'cities'              => [],
        'countries'           => [],
        'daily_followers'     => [],
        'content_consumption' => [
            'followers'     => [],  // { POST: 71.3, REEL: 17, STORY: 11.6 }
            'non_followers' => []
        ],
        'profile_activity' => [
            'profile_visits'  => 0,
            'website_clicks'  => 0
        ]
    ],
    'date_range' => ['since' => $since, 'until' => $until]
];

// =====================================================
// PREPARE PARALLEL REQUESTS
// =====================================================
$urlsToFetch = [];
$sinceTs = strtotime($since);
$untilTs = strtotime($until);
$reqFields = $_GET['fields'] ?? 'all';

if (!empty($pageId) && $reqFields === 'all') {
    $urlsToFetch['fb_page_fields'] = "https://graph.facebook.com/v19.0/{$pageId}?fields=followers_count,fan_count&access_token={$pageAccessToken}";
    $urlsToFetch['fb_ga'] = "https://graph.facebook.com/v19.0/{$pageId}/insights?metric=page_fans_gender_age&access_token={$pageAccessToken}";
    $urlsToFetch['fb_city'] = "https://graph.facebook.com/v19.0/{$pageId}/insights?metric=page_fans_city&access_token={$pageAccessToken}";
    $urlsToFetch['fb_country'] = "https://graph.facebook.com/v19.0/{$pageId}/insights?metric=page_fans_country&access_token={$pageAccessToken}";
    $urlsToFetch['fb_classic'] = "https://graph.facebook.com/v19.0/{$pageId}/insights?metric=page_fan_adds,page_fan_removes&period=day&since=" . urlencode($since) . "&until=" . urlencode($until) . "&access_token={$pageAccessToken}";
    $urlsToFetch['fb_npe'] = "https://graph.facebook.com/v19.0/{$pageId}/insights?metric=page_daily_follows,page_daily_unfollows&period=day&since=" . urlencode($since) . "&until=" . urlencode($until) . "&access_token={$pageAccessToken}";
}

if (!empty($igUserId)) {
    $igChunks = getIGDateChunks($sinceTs, $untilTs);
    
    if ($reqFields === 'all') {
        $urlsToFetch['ig_profile'] = "https://graph.facebook.com/v19.0/{$igUserId}?fields=followers_count,media_count,name,username&access_token={$accessToken}";
        $urlsToFetch['ig_age'] = "https://graph.facebook.com/v19.0/{$igUserId}/insights?metric=follower_demographics&period=lifetime&metric_type=total_value&breakdown=age&access_token={$accessToken}";
        $urlsToFetch['ig_gender'] = "https://graph.facebook.com/v19.0/{$igUserId}/insights?metric=follower_demographics&period=lifetime&metric_type=total_value&breakdown=gender&access_token={$accessToken}";
        $urlsToFetch['ig_city'] = "https://graph.facebook.com/v19.0/{$igUserId}/insights?metric=follower_demographics&period=lifetime&metric_type=total_value&breakdown=city&access_token={$accessToken}";
        $urlsToFetch['ig_country'] = "https://graph.facebook.com/v19.0/{$igUserId}/insights?metric=follower_demographics&period=lifetime&metric_type=total_value&breakdown=country&access_token={$accessToken}";
        
        $fcSince = strtotime($since) < strtotime('-30 days') ? date('Y-m-d', strtotime('-30 days')) : $since;
        $urlsToFetch['ig_fc'] = "https://graph.facebook.com/v19.0/{$igUserId}/insights?metric=follower_count&period=day&since=" . urlencode($fcSince) . "&until=" . urlencode($until) . "&access_token={$accessToken}";
        
        foreach ($igChunks as $idx => $chunk) {
            $urlsToFetch["ig_fu_$idx"] = "https://graph.facebook.com/v19.0/{$igUserId}/insights?metric=follows_and_unfollows&period=day&metric_type=total_value&since={$chunk['since']}&until={$chunk['until']}&access_token={$accessToken}";
            $urlsToFetch["ig_profile_act_$idx"] = "https://graph.facebook.com/v19.0/{$igUserId}/insights?metric=profile_views,website_clicks&period=day&metric_type=total_value&since={$chunk['since']}&until={$chunk['until']}&access_token={$accessToken}";
        }
    }
    
    foreach ($igChunks as $idx => $chunk) {
        $urlsToFetch["ig_content_cons_$idx"] = "https://graph.facebook.com/v19.0/{$igUserId}/insights?metric=reach&period=day&metric_type=total_value&breakdown=media_product_type,follow_type&since={$chunk['since']}&until={$chunk['until']}&access_token={$accessToken}";
    }
}

$multiData = apiGetMulti($urlsToFetch);

// =====================================================
// FACEBOOK DATA
// =====================================================
if (!empty($pageId)) {

    // -- FB Followers --
    $pageFields = $multiData['fb_page_fields'] ?? null;
    $response['facebook']['followers'] = $pageFields['followers_count'] ?? $pageFields['fan_count'] ?? 0;

    // -- FB Gender/Age --
    $fbGaData = $multiData['fb_ga'] ?? null;
    if (isset($fbGaData['data'][0])) {
        $response['facebook']['gender_age'] = $fbGaData['data'][0]['values'][0]['value'] ?? [];
    }

    // -- FB Cities --
    $fbCityData = $multiData['fb_city'] ?? null;
    if (isset($fbCityData['data'][0])) {
        $val = $fbCityData['data'][0]['values'][0]['value'] ?? [];
        arsort($val);
        $response['facebook']['cities'] = array_slice($val, 0, 10, true);
    }

    // -- FB Countries --
    $fbCountryData = $multiData['fb_country'] ?? null;
    if (isset($fbCountryData['data'][0])) {
        $val = $fbCountryData['data'][0]['values'][0]['value'] ?? [];
        arsort($val);
        $response['facebook']['countries'] = array_slice($val, 0, 10, true);
    }

    // -- FB Daily Follows/Unfollows --
    $fbClassic = $multiData['fb_classic'] ?? null;
    $fbNpe = $multiData['fb_npe'] ?? null;

    $followsArr = [];
    $unfollowsArr = [];

    // Parse Classic
    if (isset($fbClassic['data'])) {
        foreach ($fbClassic['data'] as $m) {
            $mName = $m['name'];
            foreach ($m['values'] as $v) {
                $date = substr($v['end_time'], 0, 10);
                if ($mName === 'page_fan_adds') $followsArr[$date] = max($followsArr[$date] ?? 0, $v['value']);
                if ($mName === 'page_fan_removes') $unfollowsArr[$date] = max($unfollowsArr[$date] ?? 0, $v['value']);
            }
        }
    }
    // Parse NPE (Fallback)
    if (isset($fbNpe['data'])) {
        foreach ($fbNpe['data'] as $m) {
            $mName = $m['name'];
            foreach ($m['values'] as $v) {
                $date = substr($v['end_time'], 0, 10);
                if ($mName === 'page_daily_follows') $followsArr[$date] = max($followsArr[$date] ?? 0, $v['value']);
                if ($mName === 'page_daily_unfollows') $unfollowsArr[$date] = max($unfollowsArr[$date] ?? 0, $v['value']);
            }
        }
    }
    // Merge into daily array
    $allDates = array_unique(array_merge(array_keys($followsArr), array_keys($unfollowsArr)));
    sort($allDates);
    $dailyFollows = [];
    foreach ($allDates as $d) {
        $dailyFollows[] = [
            'date'      => $d,
            'follows'   => $followsArr[$d] ?? 0,
            'unfollows' => $unfollowsArr[$d] ?? 0
        ];
    }
    $response['facebook']['daily_follows'] = $dailyFollows;
}

// =====================================================
// INSTAGRAM DATA
// =====================================================
if (!empty($igUserId)) {

    // -- IG Followers count --
    $igProfile = $multiData['ig_profile'] ?? null;
    $response['instagram']['followers'] = $igProfile['followers_count'] ?? 0;
    $response['instagram']['username']  = $igProfile['username'] ?? '';

    // -- IG Gender/Age --
    $igAgeData = $multiData['ig_age'] ?? null;
    if (isset($igAgeData['data'][0]['total_value']['breakdowns'][0]['results'])) {
        $ageResults = $igAgeData['data'][0]['total_value']['breakdowns'][0]['results'];
        $igAges = [];
        foreach ($ageResults as $r) {
            $ageGroup = $r['dimension_values'][0] ?? '';
            $igAges[$ageGroup] = $r['value'] ?? 0;
        }
        $response['instagram']['age_breakdown'] = $igAges;
    }
    
    $igGenderData = $multiData['ig_gender'] ?? null;
    if (isset($igGenderData['data'][0]['total_value']['breakdowns'][0]['results'])) {
        $genderResults = $igGenderData['data'][0]['total_value']['breakdowns'][0]['results'];
        $igGenders = [];
        foreach ($genderResults as $r) {
            $gender = $r['dimension_values'][0] ?? '';
            $igGenders[$gender] = $r['value'] ?? 0;
        }
        $response['instagram']['gender_breakdown'] = $igGenders;
    }

    // -- IG Cities --
    $igCityData = $multiData['ig_city'] ?? null;
    if (isset($igCityData['data'][0]['total_value']['breakdowns'][0]['results'])) {
        $cityResults = $igCityData['data'][0]['total_value']['breakdowns'][0]['results'];
        $igCities = [];
        foreach ($cityResults as $r) {
            $city = $r['dimension_values'][0] ?? '';
            $igCities[$city] = $r['value'] ?? 0;
        }
        arsort($igCities);
        $response['instagram']['cities'] = array_slice($igCities, 0, 10, true);
    }

    // -- IG Countries --
    $igCountryData = $multiData['ig_country'] ?? null;
    if (isset($igCountryData['data'][0]['total_value']['breakdowns'][0]['results'])) {
        $countryResults = $igCountryData['data'][0]['total_value']['breakdowns'][0]['results'];
        $igCountries = [];
        foreach ($countryResults as $r) {
            $country = $r['dimension_values'][0] ?? '';
            $igCountries[$country] = $r['value'] ?? 0;
        }
        arsort($igCountries);
        $response['instagram']['countries'] = array_slice($igCountries, 0, 10, true);
    }

    // -- IG Daily follower_count & follows_and_unfollows --
    $dailyIg = [];
    $tempData = [];

    $igFcData = $multiData['ig_fc'] ?? null;
    if (isset($igFcData['data'][0]['values'])) {
        foreach ($igFcData['data'][0]['values'] as $v) {
            $date = substr($v['end_time'], 0, 10);
            $tempData[$date]['net_new'] = $v['value'] ?? 0;
        }
    }

    // -- Daily Follows (Chunked) --
    $followsArrIg = [];
    $unfollowsArrIg = [];
    
    foreach ($igChunks as $idx => $chunk) {
        $igFu = $multiData["ig_fu_$idx"] ?? null;
        if (isset($igFu['data'])) {
            foreach ($igFu['data'] as $m) {
                $mName = extractMetricName($m);
                foreach ($m['values'] ?? [] as $v) {
                    $date = substr($v['end_time'], 0, 10);
                    if ($mName === 'follower_count' || $mName === 'follows') $followsArrIg[$date] = max($followsArrIg[$date] ?? 0, $v['value']);
                    if ($mName === 'unfollows') $unfollowsArrIg[$date] = max($unfollowsArrIg[$date] ?? 0, $v['value']);
                }
            }
        }
    }

    ksort($tempData);
    foreach ($tempData as $date => $vals) {
        $net_new = $vals['net_new'] ?? 0;
        $follows = $followsArrIg[$date] ?? ($net_new > 0 ? $net_new : 0);
        $unfollows = $unfollowsArrIg[$date] ?? ($net_new < 0 ? abs($net_new) : 0);
        $dailyIg[] = [
            'date'      => $date,
            'follows'   => $follows,
            'unfollows' => $unfollows,
            'value'     => $net_new
        ];
    }

    $response['instagram']['daily_follows'] = $dailyIg;
    // -- Profile Activity (Chunked) --
    $profileVisits = 0;
    $websiteClicks = 0;
    foreach ($igChunks as $idx => $chunk) {
        $igAct = $multiData["ig_profile_act_$idx"] ?? null;
        if (isset($igAct['data'])) {
            foreach ($igAct['data'] as $m) {
                $name = extractMetricName($m);
                $val = $m['total_value']['value'] ?? 0;
                if ($name === 'profile_views') $profileVisits += $val;
                if ($name === 'website_clicks') $websiteClicks += $val;
            }
        }
    }
    $response['instagram']['profile_activity'] = [
        'profile_visits' => $profileVisits,
        'website_clicks' => $websiteClicks
    ];

    // -- IG Content Consumption (Chunked) --
    $fTotals  = [];
    $nfTotals = [];
    $contentError = null;

    foreach ($igChunks as $idx => $chunk) {
        $igContentData = $multiData["ig_content_cons_$idx"] ?? null;
        if (!isset($igContentData['error']) && isset($igContentData['data'][0]['total_value']['breakdowns'][0]['results'])) {
            foreach ($igContentData['data'][0]['total_value']['breakdowns'][0]['results'] as $res) {
                $mediaType  = $res['dimension_values'][0] ?? '';
                $followType = $res['dimension_values'][1] ?? '';
                if ($followType === 'FOLLOWER')     $fTotals[$mediaType]  = ($fTotals[$mediaType]  ?? 0) + $res['value'];
                if ($followType === 'NON_FOLLOWER') $nfTotals[$mediaType] = ($nfTotals[$mediaType] ?? 0) + $res['value'];
            }
        } else {
            $contentError = $igContentData['error']['message'] ?? 'API error';
        }
    }

    if ($contentError && empty($fTotals) && empty($nfTotals)) {
        $response['instagram']['_content_error'] = $contentError;
    } else {
        $fSum  = array_sum($fTotals);
        $nfSum = array_sum($nfTotals);
        arsort($fTotals);
        arsort($nfTotals);
        foreach ($fTotals as $type => $val) {
            $response['instagram']['content_consumption']['followers'][$type] = [
                'count' => $val,
                'pct'   => $fSum > 0 ? round(($val / $fSum) * 100, 1) : 0
            ];
        }
        foreach ($nfTotals as $type => $val) {
            $response['instagram']['content_consumption']['non_followers'][$type] = [
                'count' => $val,
                'pct'   => $nfSum > 0 ? round(($val / $nfSum) * 100, 1) : 0
            ];
        }
    }
}

// Cache for 1 hour (audience data changes slowly), but only if we have successful data
$shouldCache = empty($response['instagram']['_content_error']);
if ($shouldCache) {
    SimpleCache::set($cacheKey, $response, 7200);
}

$output = json_encode($response);
ob_end_clean();
echo $output;

} catch (\Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['error' => 'server_error', 'message' => 'Internal Server Error: ' . $e->getMessage()]);
}
?>
