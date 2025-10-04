<?php
/**
 * Let's Roll Image Proxy
 *
 * This script acts as a secure and authenticated proxy for fetching images from the API.
 * It is essential because the API endpoints for images require authentication, and we
 * cannot expose the API access token in the public-facing HTML.
 *
 * --- How It Works ---
 * 1.  Load WordPress: It first loads the WordPress environment to get access to
 *     WordPress functions (`wp_remote_get`) and our plugin's functions.
 * 2.  Load Plugin: It then loads the main plugin file to access `lr_get_api_access_token()`.
 * 3.  Authenticate: It retrieves the API access token.
 * 4.  API to S3 Redirect: It makes an authenticated request to the Let's Roll API.
 *     The API doesn't return the image directly. Instead, it returns a temporary,
 *     redirect URL to the actual image file stored on a service like Amazon S3.
 * 5.  Fetch from S3: The script then makes a second, unauthenticated request to this
 *     temporary S3 URL to get the raw image data.
 * 6.  Serve Image: Finally, it serves the raw image data to the browser with the
 *     correct content-type header and a long cache duration.
 *
 * This two-step process ensures that our API remains secure while still allowing
 * us to serve images efficiently.
 */

// --- Robust WordPress Environment Loader ---
$wp_load_path = '';
$dir = __DIR__;
for ($i = 0; $i < 10; $i++) { // Limit to 10 levels up
    if (file_exists($dir . '/wp-load.php')) {
        $wp_load_path = $dir . '/wp-load.php';
        break;
    }
    $dir = dirname($dir);
}

if (empty($wp_load_path)) {
    header("HTTP/1.1 500 Internal Server Error");
    exit('Could not find wp-load.php');
}
require_once($wp_load_path);

// Load the main plugin file to get access to its functions
require_once(__DIR__ . '/lets-roll-seo-pages.php');

// --- Self-Contained Image Proxy ---

// 1. Get Access Token
$access_token = lr_get_api_access_token();
if (is_wp_error($access_token)) {
    header("HTTP/1.1 401 Unauthorized");
    exit('Could not authenticate for image proxy.');
}

// 2. Get and sanitize parameters
$type = $_GET['type'] ?? '';
$id = $_GET['id'] ?? '';
$session_id = $_GET['session_id'] ?? '';
$width = isset($_GET['width']) ? intval($_GET['width']) : 800;
$quality = isset($_GET['quality']) ? intval($_GET['quality']) : 85;

if (empty($type) || empty($id)) {
    header("HTTP/1.1 400 Bad Request");
    exit('Missing required parameters.');
}

// 3. Construct the correct API URL based on type
$base_url = 'https://beta.web.lets-roll.app/api/';
$api_url = '';

switch ($type) {
    case 'spot_satellite':
        $api_url = $base_url . 'spots/attachment/' . $id . '/content/processed?width=' . $width . '&height=' . round($width * 0.6) . '&quality=' . $quality;
        break;
    case 'event_attachment':
        if (empty($session_id)) {
            header("HTTP/1.1 400 Bad Request");
            exit('Missing session_id for event attachment.');
        }
        $api_url = $base_url . 'roll-session/' . $session_id . '/attachments/' . $id . '/content/processed?width=' . $width . '&height=' . round($width * 0.6) . '&quality=' . $quality;
        break;
    default:
        header("HTTP/1.1 400 Bad Request");
        exit('Invalid type specified.');
}

// 4. Fetch the image from the API with Authentication
$response = wp_remote_get($api_url, [
    'headers' => [
        'Authorization' => 'Bearer ' . $access_token,
    ],
    'timeout' => 20
]);

if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
    header("HTTP/1.1 502 Bad Gateway");
    exit('Failed to fetch image from API.');
}

// 5. Serve the image with the correct headers
$image_data = wp_remote_retrieve_body($response);
$content_type = wp_remote_retrieve_header($response, 'content-type');

header('Content-Type: ' . $content_type);
header('Content-Length: ' . strlen($image_data));
header('Cache-Control: public, max-age=86400'); // Cache for 1 day

echo $image_data;
exit;