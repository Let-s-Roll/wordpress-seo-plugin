<?php
/**
 * Let's Roll SEO - Image Proxy
 * This script securely fetches an image from a protected API endpoint that redirects to S3.
 * It's loaded directly by the browser from an <img> tag.
 */

// We need to load the WordPress environment to get access to our functions and settings.
// This path assumes the proxy is in the root of the plugin folder.
require_once( dirname( __FILE__ ) . '/../../../wp-load.php' );

// Make sure we have an attachment ID to fetch.
if ( empty( $_GET['attachment_id'] ) ) {
    header("HTTP/1.0 400 Bad Request");
    exit('Error: No attachment ID provided.');
}

$attachment_id = sanitize_text_field( $_GET['attachment_id'] );
$access_token = lr_get_api_access_token();

if ( is_wp_error( $access_token ) ) {
    header("HTTP/1.0 401 Unauthorized");
    exit('Error: Could not authenticate.');
}

// Construct the initial API URL for the image.
$initial_image_url = 'https://beta.web.lets-roll.app/api/spots/spot-satellite-attachment/' . $attachment_id . '/content?width=800&quality=80';

// --- Step 1: Make the initial request but DON'T follow the redirect ---
$initial_response = wp_remote_get($initial_image_url, [
    'headers' => ['Authorization' => $access_token],
    'redirection' => 0 
]);

// Check if the first request was a successful redirect.
if ( !is_wp_error($initial_response) && in_array(wp_remote_retrieve_response_code($initial_response), [301, 302, 307]) ) {
    
    // --- Step 2: Get the S3 URL from the 'location' header ---
    $s3_url = wp_remote_retrieve_header($initial_response, 'location');
    
    if ($s3_url) {
        // --- Step 3: Make a second, clean request to the S3 URL ---
        $image_response = wp_remote_get($s3_url);

        if (!is_wp_error($image_response) && wp_remote_retrieve_response_code($image_response) === 200) {
            
            // --- Step 4: Serve the image directly to the browser ---
            $image_data = wp_remote_retrieve_body($image_response);
            $image_mime_type = wp_remote_retrieve_header($image_response, 'content-type');
            
            // Set the correct content type header so the browser knows it's an image.
            header('Content-Type: ' . $image_mime_type);
            
            // Output the raw image data.
            echo $image_data;
            exit;
        }
    }
}

// If anything failed, return a 404.
header("HTTP/1.0 404 Not Found");
exit('Error: Image could not be loaded.');

