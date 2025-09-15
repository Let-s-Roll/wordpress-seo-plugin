<?php
// This script acts as a secure proxy to fetch and serve protected images from the API.

// Bootstrap WordPress to access its functions
define('WP_USE_THEMES', false);
// Traverse up to find wp-load.php
$wp_load_path = realpath(__DIR__ . '/../../../wp-load.php');
if ($wp_load_path) {
    require_once($wp_load_path);
} else {
    // Fallback for different directory structures
    $wp_load_path = realpath(__DIR__ . '/../../../../wp-load.php');
    if ($wp_load_path) {
        require_once($wp_load_path);
    } else {
        header("HTTP/1.1 500 Internal Server Error");
        echo "Could not locate WordPress bootstrap file.";
        exit;
    }
}


// --- Get parameters from the URL ---
$type = $_GET['type'] ?? null;
$id = $_GET['id'] ?? null;
$session_id = $_GET['session_id'] ?? null; // Needed for event attachments

if (!$type || !$id) {
    header("HTTP/1.1 400 Bad Request");
    echo "Missing required parameters.";
    exit;
}

// Get an access token
$access_token = lr_get_api_access_token();
if (is_wp_error($access_token)) {
    header("HTTP/1.1 401 Unauthorized");
    echo "API Authentication failed.";
    exit;
}

// --- Build the correct API endpoint based on the type ---
$api_endpoint = '';
$api_base_url = 'https://beta.web.lets-roll.app/api/';

switch ($type) {
    case 'spot_satellite':
        $api_endpoint = 'spots/spot-satellite-attachment/' . $id . '/content?width=800&quality=80';
        break;
    
    case 'event_attachment':
        if (!$session_id) {
            header("HTTP/1.1 400 Bad Request");
            echo "Missing session_id for event attachment.";
            exit;
        }
        $api_endpoint = 'roll-session/' . $session_id . '/attachment/' . $id . '/content';
        break;
    
    default:
        header("HTTP/1.1 400 Bad Request");
        echo "Invalid image type specified.";
        exit;
}

$initial_image_url = $api_base_url . $api_endpoint;

// --- Step 1: Make the initial request but DON'T follow the redirect ---
$initial_response = wp_remote_get($initial_image_url, [
    'headers' => ['Authorization' => $access_token],
    'redirection' => 0 
]);

if (is_wp_error($initial_response) || !in_array(wp_remote_retrieve_response_code($initial_response), [301, 302, 307])) {
    header("HTTP/1.1 502 Bad Gateway");
    echo "Initial API request failed to get redirect.";
    exit;
}

// --- Step 2: Get the S3 URL from the 'location' header ---
$s3_url = wp_remote_retrieve_header($initial_response, 'location');
if (!$s3_url) {
    header("HTTP/1.1 502 Bad Gateway");
    echo "Could not find S3 location header in API response.";
    exit;
}

// --- Step 3: Make a clean request to the S3 URL and serve the image ---
$image_response = wp_remote_get($s3_url);
if (!is_wp_error($image_response) && wp_remote_retrieve_response_code($image_response) === 200) {
    $image_data = wp_remote_retrieve_body($image_response);
    $image_mime_type = wp_remote_retrieve_header($image_response, 'content-type');
    
    // --- ADDED: Browser Caching Headers ---
    $cache_duration_seconds = 2592000; // 30 days
    header('Content-Type: ' . $image_mime_type);
    header('Content-Length: ' . strlen($image_data));
    header('Cache-Control: public, max-age=' . $cache_duration_seconds);
    header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $cache_duration_seconds) . ' GMT');
    header('Pragma: cache');

    echo $image_data;
    exit;
} else {
    header("HTTP/1.1 502 Bad Gateway");
    echo "Failed to fetch image from S3.";
    exit;
}
