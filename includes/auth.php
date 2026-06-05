<?php
/**
 * Authentication Middleware
 * Checks if the user session is active. If not, redirects to index.php.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Close the session lock immediately so parallel AJAX requests (fetch_*) don't block each other.
session_write_close();

if (!isset($_SESSION['user_id'])) {
    // If it's an AJAX request (e.g. from internal dashboard fetch pages), return a JSON error
    if (
        (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
        (isset($_GET['ajax']) || isset($_GET['json']))
    ) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['error' => 'unauthorized', 'message' => 'Your session has expired. Please log in again.']);
        exit;
    }
    
    // Determine the redirect path dynamically depending on the current directory level
    $current_dir = basename(dirname($_SERVER['SCRIPT_FILENAME']));
    $redirect_path = 'index.php';
    
    if ($current_dir === 'api' || $current_dir === 'pages' || $current_dir === 'reports') {
        $redirect_path = '../index.php';
    }
    
    header("Location: " . $redirect_path);
    exit;
}
?>
