<?php
// This script acts as a secure proxy to fetch and serve protected images from the API.

// Bootstrap WordPress
define('WP_USE_THEMES', false);
$wp_load_path = realpath(__DIR__ . '/../../../wp-load.php');
if ($wp_load_path) { require_once($wp_load_path); } 
else {
    $wp_load_path = realpath(__DIR__ . '/../../../../wp-load.php');
    if ($wp_load_path) { require_once($wp_load_path); }
    else { header("HTTP/1.1 500"); echo "Could not locate WordPress bootstrap file."; exit; }
}

// --- Get parameters from the URL ---
$type = $_GET['type'] ?? null;
$id = $_GET['id'] ?? null;
$session_id = $_GET['session_id'] ?? null;
// OPTIMIZATION: Get width and quality parameters
$width = $_GET['width'] ?? null;
$quality = $_GET['quality'] ?? null;

if (!$type || !$id) { header("HTTP/1.1 400"); echo "Missing parameters."; exit; }

$access_token = lr_get_api_access_token();
if (is_wp_error($access_token)) { header("HTTP/1.1 401"); echo "API Auth failed."; exit; }

// --- Build the correct API endpoint ---
$api_endpoint = '';
$api_base_url = 'https://beta.web.lets-roll.app/api/';
$api_params = [];

if ($width) $api_params['width'] = intval($width);
if ($quality) $api_params['quality'] = intval($quality);

switch ($type) {
    case 'spot_satellite':
        $api_endpoint = 'spots/spot-satellite-attachment/' . $id . '/content';
        break;
    
    case 'event_attachment':
        if (!$session_id) { header("HTTP/1.1 400"); echo "Missing session_id."; exit; }
        $api_endpoint = 'roll-session/' . $session_id . '/attachment/' . $id . '/content';
        break;
    
    default:
        header("HTTP/1.1 400"); echo "Invalid image type."; exit;
}

// OPTIMIZATION: Add resizing parameters to the final URL
$initial_image_url = add_query_arg($api_params, $api_base_url . $api_endpoint);

// --- (Rest of the script for fetching and serving the image remains the same) ---
$initial_response = wp_remote_get($initial_image_url, [
    'headers' => ['Authorization' => 'Bearer ' . $access_token],
    'redirection' => 0
]);
if (is_wp_error($initial_response) || !in_array(wp_remote_retrieve_response_code($initial_response), [301, 302, 307])) { header("HTTP/1.1 502"); echo "API redirect failed."; exit; }

$s3_url = wp_remote_retrieve_header($initial_response, 'location');
if (!$s3_url) { header("HTTP/1.1 502"); echo "S3 location not found."; exit; }

$image_response = wp_remote_get($s3_url);
if (!is_wp_error($image_response) && wp_remote_retrieve_response_code($image_response) === 200) {
    $image_data = wp_remote_retrieve_body($image_response);
    $image_mime_type = wp_remote_retrieve_header($image_response, 'content-type');
    
    $cache_duration_seconds = 2592000; // 30 days
    header('Content-Type: ' . $image_mime_type);
    header('Content-Length: ' . strlen($image_data));
    header('Cache-Control: public, max-age=' . $cache_duration_seconds);
    header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $cache_duration_seconds) . ' GMT');
    header('Pragma: cache');

    echo $image_data;
    exit;
} else {
    header("HTTP/1.1 502"); echo "Failed to fetch from S3."; exit;
}