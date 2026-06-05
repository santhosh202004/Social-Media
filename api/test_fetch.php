<?php
$ch = curl_init("http://localhost/add_campign_test/api/fetch_insights.php?since=2026-05-01&until=2026-05-22&nocache=1&debug=1");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 60);
$res = curl_exec($ch);
if (curl_errno($ch)) {
    echo "cURL Error: " . curl_error($ch) . "\n";
} else {
    echo "Response Code: " . curl_getinfo($ch, CURLINFO_HTTP_CODE) . "\n";
    echo "Body: " . substr($res, 0, 1000) . "...\n";
    $json = json_decode($res, true);
    if ($json) {
        echo "Valid JSON. Content count: " . ($json['debug']['content_count'] ?? 'N/A') . "\n";
        echo "FB Posts processed: " . ($json['debug']['fb_posts_processed_count'] ?? 'N/A') . "\n";
        echo "IG Media processed: " . ($json['debug']['ig_media_processed_count'] ?? 'N/A') . "\n";
        echo "FB Error: " . json_encode($json['debug']['fb_api_error'] ?? null) . "\n";
    } else {
        echo "INVALID JSON!\n";
    }
}
curl_close($ch);
?>
