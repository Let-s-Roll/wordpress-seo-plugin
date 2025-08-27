<?php
/**
 * Plugin Name:       Let's Roll SEO Pages
 * Description:       Dynamically generates pages for skate locations, skaters, and events.
 * Version:           0.7.0
 * Author:            Your Name
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       lets-roll-seo-pages
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// Include the template files which now act as content generators
require_once plugin_dir_path(__FILE__) . 'templates/template-country-page.php';
require_once plugin_dir_path(__FILE__) . 'templates/template-city-page.php';
require_once plugin_dir_path(__FILE__) . 'templates/template-detail-page.php';

/**
 * =================================================================================
 * Core API Functions
 * =================================================================================
 */
function lr_get_api_access_token() {
    $cached_token = get_transient('lr_api_access_token');
    if ($cached_token) return $cached_token;

    $auth_url = 'https://beta.web.lets-roll.app/api/auth/signin/email';
    $email    = defined('LETS_ROLL_API_EMAIL') ? LETS_ROLL_API_EMAIL : '';
    $password = defined('LETS_ROLL_API_PASS') ? LETS_ROLL_API_PASS : '';

    if (empty($email) || empty($password)) return new WP_Error('no_creds', 'API credentials are not configured.');
    
    $response = wp_remote_post($auth_url, ['headers' => ['Content-Type' => 'application/json'], 'body' => json_encode(['email' => $email, 'password' => $password])]);
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) return new WP_Error('auth_failed', 'Could not retrieve access token.');

    $body = json_decode(wp_remote_retrieve_body($response));
    $access_token = $body->tokens->access ?? null;
    
    if ($access_token) {
        set_transient('lr_api_access_token', $access_token, 55 * MINUTE_IN_SECONDS);
        return $access_token;
    }
    return new WP_Error('token_missing', 'Access token not found in API response.');
}

function lr_fetch_api_data($token, $endpoint, $params) {
    if (is_wp_error($token)) return $token;
    
    $api_base_url = 'https://beta.web.lets-roll.app/api/';
    $full_url = add_query_arg($params, $api_base_url . $endpoint);
    $response = wp_remote_get($full_url, ['headers' => ['Authorization' => $token]]);
    
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) return new WP_Error('fetch_failed', 'Failed to fetch data from the API.');
    return json_decode(wp_remote_retrieve_body($response));
}

/**
 * =================================================================================
 * Location Data & WordPress Integration
 * =================================================================================
 */
function lr_get_location_data() { 
    static $locations = null; 
    if($locations === null) {
        $locations_file = plugin_dir_path(__FILE__) . 'locations.php';
        $locations = file_exists($locations_file) ? include $locations_file : [];
    } 
    return $locations; 
}

function lr_get_country_details($country_slug) { 
    $locations = lr_get_location_data(); 
    return $locations[$country_slug] ?? null; 
}

function lr_get_city_details($country_slug, $city_slug) { 
    $country = lr_get_country_details($country_slug); 
    return $country['cities'][$city_slug] ?? null; 
}

function lr_custom_rewrite_rules() { 
    add_rewrite_tag('%lr_country%','([^/]+)');
    add_rewrite_tag('%lr_city%','([^/]+)');
    add_rewrite_tag('%lr_page_type%','([^/]+)');
    add_rewrite_rule('^([^/]+)/([^/]+)/([^/]+)/?$','index.php?lr_country=$matches[1]&lr_city=$matches[2]&lr_page_type=$matches[3]','top');
    add_rewrite_rule('^([^/]+)/([^/]+)/?$','index.php?lr_country=$matches[1]&lr_city=$matches[2]','top');
    add_rewrite_rule('^([^/]+)/?$','index.php?lr_country=$matches[1]','top'); 
}
add_action('init', 'lr_custom_rewrite_rules');

function lr_activate_plugin() { 
    lr_custom_rewrite_rules(); 
    flush_rewrite_rules(); 
}
register_activation_hook(__FILE__, 'lr_activate_plugin');


/**
 * =================================================================================
 * Dynamic Page Generation
 * =================================================================================
 */

/**
 * This is the main controller. It runs before the theme loads.
 */
function lr_page_controller() {
    // First, check if our main query variable is set. If not, it's not our page, so do nothing.
    if ( ! get_query_var('lr_country') ) {
        return;
    }

    // If it is our page, add the filters that will replace the title and content.
    add_filter('the_content', 'lr_generate_dynamic_content');
    add_filter('the_title', 'lr_generate_dynamic_title', 10, 2);

    // This is the crucial part: we create a fake post object.
    // This tricks the theme into thinking it's displaying a real page,
    // which makes it load all the correct styles and context.
    add_action('the_post', function($post) {
        $post->post_title = lr_generate_dynamic_title(''); // Set the title on the fake post
        // You could set other properties here if needed, e.g., $post->post_content
    });
}
add_action('template_redirect', 'lr_page_controller');


/**
 * Replaces the default page content with our dynamically generated content.
 */
function lr_generate_dynamic_content($content) {
    // Since this filter is now added conditionally, we just need to return our content.
    $country_slug = get_query_var('lr_country');
    $city_slug = get_query_var('lr_city');
    $page_type = get_query_var('lr_page_type');

    if ($page_type) {
        return lr_render_detail_page_content($country_slug, $city_slug, $page_type);
    } elseif ($city_slug) {
        return lr_render_city_page_content($country_slug, $city_slug);
    } else {
        return lr_render_country_page_content($country_slug);
    }
}

/**
 * Replaces the default page title with our dynamic title.
 */
function lr_generate_dynamic_title($title, $id = null) {
    // This function is now simpler as well.
    $country_slug = get_query_var('lr_country');
    $city_slug = get_query_var('lr_city');
    $page_type = get_query_var('lr_page_type');
    $new_title = '';

    if ($page_type) {
        $city_details = lr_get_city_details($country_slug, $city_slug);
        if ($city_details) $new_title = ucfirst($page_type) . ' in ' . $city_details['name'];
    } elseif ($city_slug) {
        $city_details = lr_get_city_details($country_slug, $city_slug);
        if ($city_details) $new_title = 'Roller Skating in ' . $city_details['name'];
    } else {
        $country_details = lr_get_country_details($country_slug);
        if ($country_details) $new_title = 'Roller Skating in ' . $country_details['name'];
    }

    return $new_title ? $new_title : $title;
}