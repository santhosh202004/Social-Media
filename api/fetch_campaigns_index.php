<?php
/**
 * Main Data Controller
 * Handles Facebook API requests and data formatting for the dashboard.
 */

// --- Configuration & Security ---
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_config.php';

// Fetch credentials from the database to avoid hardcoding sensitive data
$stmt = $pdo->query("SELECT access_token, ad_account_id FROM facebook_config WHERE is_active = 1 LIMIT 1");
$config = $stmt->fetch();

// Check if API is configured; if not, return error or redirect
if (!$config || empty($config['access_token']) || empty($config['ad_account_id'])) {
    if (isset($_GET['json'])) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'not_configured']);
        exit;
    }
    header("Location: ../dashboard.php?page=settings");
    exit;
}

$accessToken = $config['access_token'];
$adAccountId = $config['ad_account_id'];

// --- Facebook API Request ---
// Fetch ALL campaigns (Active + Paused) with insights
$effectiveStatus = json_encode(['ACTIVE', 'PAUSED']);

// Handle Date Range Filtering
$since = $_GET['since'] ?? '';
$until = $_GET['until'] ?? '';

$insightsField = "insights.date_preset(maximum)";
if (!empty($since) && !empty($until)) {
    $timeRange = json_encode(['since' => $since, 'until' => $until]);
    $insightsField = "insights.time_range({$timeRange})";
}

$url = "https://graph.facebook.com/v25.0/" . urlencode($adAccountId) . "/campaigns"
     . "?effective_status=" . urlencode($effectiveStatus)
     . "&fields=" . urlencode("id,name,status,objective,{$insightsField}{impressions,reach,actions,date_start,date_stop}")
     . "&access_token=" . urlencode($accessToken)
     . "&limit=25";

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_ENCODING       => "",
    CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// --- Debug Mode ---
// Access via: index.php?debug=1 to see the raw API request and response
if (isset($_GET['debug'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'debug_url'    => preg_replace('/access_token=[^&]+/', 'access_token=REDACTED', $url),
        'http_code'    => $httpCode,
        'api_response' => json_decode($response, true),
    ], JSON_PRETTY_PRINT);
    exit;
}


// Handle API failures gracefully
if ($response === false || $httpCode !== 200) {
    $apiError = json_decode($response, true);
    $errorMsg = isset($apiError['error']['message']) ? $apiError['error']['message'] : 'Unknown error';
    
        if (isset($_GET['json'])) {
        header('Content-Type: application/json');
        echo json_encode([
            'error'   => 'api_error',
            'message' => $errorMsg,
            'code'    => $httpCode,
        ]);
        exit;
    }
    die("Error fetching data from Facebook API: {$errorMsg}. Please check your credentials in <a href='../dashboard.php?page=settings'>Settings</a>.");
}

$data = json_decode($response, true);

// --- Output Handling ---
if (isset($_GET['json'])) {
    // Return formatted JSON for the dashboard.js AJAX calls
    header('Content-Type: application/json');
    $campaigns = [];
    if (isset($data['data'])) {
        foreach ($data['data'] as $c) {
            $campaign = [
                'id'        => $c['id'],
                'name'      => $c['name'],
                'status'    => $c['status'],
                'objective' => $c['objective'] ?? 'N/A',
            ];
            // Nest the first insights entry if available for easier frontend access
            if (isset($c['insights']['data'][0])) {
                $campaign['insights'] = $c['insights']['data'][0];
            }
            $campaigns[] = $campaign;
        }
    }
    echo json_encode($campaigns);
} else {
    // Default view: Redirect to the interactive dashboard
    header("Location: ../dashboard.php");
}
?>
