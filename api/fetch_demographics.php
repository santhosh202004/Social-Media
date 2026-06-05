<?php
/**
 * Fetch Demographics Controller
 * Retrieves audience demographic data (gender, age, location) from Facebook and Instagram APIs.
 */

header('Content-Type: application/json');
require_once '../includes/auth.php';
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

// Get active configuration
$stmt = $pdo->query("SELECT f.access_token, f.page_id, i.ig_user_id 
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

$demographics = [
    'facebook' => [
        'gender_age' => [],
        'city' => [],
        'country' => []
    ],
    'instagram' => [
        'gender_age' => [],
        'city' => [],
        'country' => []
    ]
];

// --- 1. Facebook Demographics ---
// metric=page_fans_gender_age,page_fans_city,page_fans_country
if (!empty($pageId)) {
    // Exchange for Page Access Token (required for page insights)
    $pageTokenReq = fbApiGet("https://graph.facebook.com/v19.0/{$pageId}?fields=access_token&access_token={$accessToken}");
    $pageToken = $pageTokenReq['access_token'] ?? $accessToken;

    $fbDemoUrl = "https://graph.facebook.com/v19.0/{$pageId}/insights"
               . "?metric=page_fans_gender_age,page_fans_city,page_fans_country"
               . "&access_token={$pageToken}";
    $fbDemoData = fbApiGet($fbDemoUrl);
    
    if (isset($fbDemoData['data'])) {
        foreach ($fbDemoData['data'] as $metric) {
            // Demographics data is usually in the first value
            $val = $metric['values'][0]['value'] ?? [];
            if ($metric['name'] === 'page_fans_gender_age') {
                $demographics['facebook']['gender_age'] = $val;
            } else if ($metric['name'] === 'page_fans_city') {
                // Sort by value desc and take top 10
                arsort($val);
                $demographics['facebook']['city'] = array_slice($val, 0, 10, true);
            } else if ($metric['name'] === 'page_fans_country') {
                 arsort($val);
                 $demographics['facebook']['country'] = array_slice($val, 0, 10, true);
            }
        }
    }
}

// --- 2. Instagram Demographics ---
// Modern v19.0+ API: follower_demographics with metric_type=total_value and breakdown param
if (!empty($igUserId)) {
    // Gender+Age: make two separate calls as combined breakdown isn't supported for demographics
    // Age breakdown
    $igAgeUrl = "https://graph.facebook.com/v19.0/{$igUserId}/insights"
              . "?metric=follower_demographics&period=lifetime&metric_type=total_value&breakdown=age"
              . "&access_token={$accessToken}";
    $igAgeData = fbApiGet($igAgeUrl);

    if (isset($igAgeData['data'][0]['total_value']['breakdowns'][0]['results'])) {
        foreach ($igAgeData['data'][0]['total_value']['breakdowns'][0]['results'] as $r) {
            $ageGroup = $r['dimension_values'][0] ?? '';
            $demographics['instagram']['age_breakdown'][$ageGroup] = $r['value'] ?? 0;
        }
    }

    // Gender breakdown
    $igGenderUrl = "https://graph.facebook.com/v19.0/{$igUserId}/insights"
                 . "?metric=follower_demographics&period=lifetime&metric_type=total_value&breakdown=gender"
                 . "&access_token={$accessToken}";
    $igGenderData = fbApiGet($igGenderUrl);

    if (isset($igGenderData['data'][0]['total_value']['breakdowns'][0]['results'])) {
        foreach ($igGenderData['data'][0]['total_value']['breakdowns'][0]['results'] as $r) {
            $g = $r['dimension_values'][0] ?? '';
            $demographics['instagram']['gender_breakdown'][$g] = $r['value'] ?? 0;
        }
    }

    // Build gender_age for backward compatibility with UI
    // Map to format: { "M.25-34": 100, "F.18-24": 50, ... }
    // We approximate using separate age + gender totals
    $demographics['instagram']['gender_age'] = $demographics['instagram']['age_breakdown'] ?? [];

    // City breakdown
    $igCityUrl = "https://graph.facebook.com/v19.0/{$igUserId}/insights"
               . "?metric=follower_demographics&period=lifetime&metric_type=total_value&breakdown=city"
               . "&access_token={$accessToken}";
    $igCityData = fbApiGet($igCityUrl);

    if (isset($igCityData['data'][0]['total_value']['breakdowns'][0]['results'])) {
        $cities = [];
        foreach ($igCityData['data'][0]['total_value']['breakdowns'][0]['results'] as $r) {
            $city = $r['dimension_values'][0] ?? '';
            $cities[$city] = $r['value'] ?? 0;
        }
        arsort($cities);
        $demographics['instagram']['city'] = array_slice($cities, 0, 10, true);
    }

    // Country breakdown
    $igCountryUrl = "https://graph.facebook.com/v19.0/{$igUserId}/insights"
                  . "?metric=follower_demographics&period=lifetime&metric_type=total_value&breakdown=country"
                  . "&access_token={$accessToken}";
    $igCountryData = fbApiGet($igCountryUrl);

    if (isset($igCountryData['data'][0]['total_value']['breakdowns'][0]['results'])) {
        $countries = [];
        foreach ($igCountryData['data'][0]['total_value']['breakdowns'][0]['results'] as $r) {
            $country = $r['dimension_values'][0] ?? '';
            $countries[$country] = $r['value'] ?? 0;
        }
        arsort($countries);
        $demographics['instagram']['country'] = array_slice($countries, 0, 10, true);
    }
}

if (isset($_GET['debug'])) {
    $demographics['debug'] = [
        'fb_raw' => $fbDemoData ?? null,
        'ig_raw' => $igDemoData ?? null
    ];
}

echo json_encode($demographics);
?>
