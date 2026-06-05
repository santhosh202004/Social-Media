<?php
// api/fetch_fb_pages.php
require_once '../includes/auth.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$access_token = $data['access_token'] ?? '';

if (empty($access_token)) {
    echo json_encode(['success' => false, 'error' => 'Access token is required']);
    exit;
}

$url = "https://graph.facebook.com/v19.0/me/accounts?access_token=" . urlencode($access_token);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
// Use a reasonable timeout
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$response = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($response === false) {
    echo json_encode(['success' => false, 'error' => 'cURL Error: ' . $error]);
    exit;
}

$result = json_decode($response, true);

if (isset($result['error'])) {
    echo json_encode(['success' => false, 'error' => $result['error']['message'] ?? 'Unknown Graph API Error']);
    exit;
}

if (isset($result['data'])) {
    // We successfully got the pages
    $pages = [];
    foreach ($result['data'] as $page) {
        $pages[] = [
            'id' => $page['id'],
            'name' => $page['name']
        ];
    }
    echo json_encode(['success' => true, 'pages' => $pages]);
} else {
    echo json_encode(['success' => false, 'error' => 'No pages found or unexpected response format']);
}
?>
