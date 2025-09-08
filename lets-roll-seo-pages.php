<?php
/**
 * Plugin Name:       Let's Roll SEO Pages
 * Description:       Dynamically generates pages for skate locations, skaters, and events.
 * Version:           0.9.6
 * Author:            Your Name
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       lets-roll-seo-pages
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// Include admin and template files
if (is_admin()) { require_once plugin_dir_path(__FILE__) . 'admin/admin-page.php'; }
require_once plugin_dir_path(__FILE__) . 'templates/template-country-page.php';
require_once plugin_dir_path(__FILE__) . 'templates/template-city-page.php';
require_once plugin_dir_path(__FILE__) . 'templates/template-detail-page.php';
require_once plugin_dir_path(__FILE__) . 'templates/template-single-spot.php';
require_once plugin_dir_path(__FILE__) . 'templates/template-single-event.php';
require_once plugin_dir_path(__FILE__) . 'templates/template-single-skater.php';

/**
 * =================================================================================
 * Core API Functions
 * =================================================================================
 */
function lr_get_api_access_token() {
    $cached_token = get_transient('lr_api_access_token');
    if ($cached_token) return $cached_token;
    $options = get_option('lr_options');
    $email = $options['api_email'] ?? '';
    $password = $options['api_pass'] ?? '';
    $auth_url = 'https://beta.web.lets-roll.app/api/auth/signin/email';
    if (empty($email) || empty($password)) return new WP_Error('no_creds', 'API credentials are not configured in the plugin settings.');
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
    if ($locations === null) {
        $options = get_option('lr_options');
        $locations_json = $options['locations_json'] ?? '';
        $locations = json_decode($locations_json, true);
        $locations = is_array($locations) ? $locations : [];
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
    add_rewrite_tag('%lr_single_type%', '(spots|events|skaters)');
    add_rewrite_tag('%lr_item_id%', '([^/]+)');
    add_rewrite_tag('%lr_country%','([^/]+)');
    add_rewrite_tag('%lr_city%','([^/]+)');
    add_rewrite_tag('%lr_page_type%','([^/]+)');
    
    // Rules must be ordered from most specific to least specific.
    add_rewrite_rule('^(spots|events|skaters)/([^/]+)/?$', 'index.php?lr_single_type=$matches[1]&lr_item_id=$matches[2]', 'top');
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
 * Helper function to calculate a bounding box from a center point and radius.
 */
function lr_calculate_bounding_box($lat, $lon, $radius_km) {
    $earth_radius = 6371; // Earth's radius in kilometers
    $lat_rad = deg2rad($lat);

    // Calculate latitude bounds
    $lat_delta = $radius_km / $earth_radius;
    $min_lat = $lat - rad2deg($lat_delta);
    $max_lat = $lat + rad2deg($lat_delta);

    // Calculate longitude bounds
    $lon_delta = asin(sin($lat_delta) / cos($lat_rad));
    $min_lon = $lon - rad2deg($lon_delta);
    $max_lon = $lon + rad2deg($lon_delta);

    return [
        'sw' => $min_lat . ',' . $min_lon,
        'ne' => $max_lat . ',' . $max_lon,
    ];
}

/**
 * =================================================================================
 * Dynamic Page Generation
 * =================================================================================
 */
function lr_pre_get_posts_controller( $query ) {
    if ( ! is_admin() && $query->is_main_query() ) {
        if ( get_query_var('lr_country') || get_query_var('lr_single_type') ) {
            $query->set('is_page', true);
            $query->set('is_singular', true);
            $query->set('is_home', false);
            $query->set('is_archive', false);

            add_filter('the_content', 'lr_generate_dynamic_content');
            add_filter('the_title', 'lr_generate_dynamic_title', 20, 2);
        }
    }
}
add_action( 'pre_get_posts', 'lr_pre_get_posts_controller' );

function lr_generate_dynamic_content($content) {
    if ( !get_query_var('lr_country') && !get_query_var('lr_single_type') ) {
        return $content;
    }
    
    $single_type = get_query_var('lr_single_type');
    $item_id = get_query_var('lr_item_id');

    if ($single_type && $item_id) {
        switch ($single_type) {
            case 'spots':   return lr_render_single_spot_content($item_id);
            case 'events':  return lr_render_single_event_content($item_id);
            case 'skaters': return lr_render_single_skater_content($item_id);
        }
    }

    $country_slug = get_query_var('lr_country');
    $city_slug = get_query_var('lr_city');
    $page_type = get_query_var('lr_page_type');
    if ($page_type) return lr_render_detail_page_content($country_slug, $city_slug, $page_type);
    elseif ($city_slug) return lr_render_city_page_content($country_slug, $city_slug);
    else return lr_render_country_page_content($country_slug);
}

function lr_generate_dynamic_title($title, $id = null) {
    $single_type = get_query_var('lr_single_type');
    $item_id = get_query_var('lr_item_id');
    
    if ($single_type && $item_id) {
        $access_token = lr_get_api_access_token();
        $display_name = '';
        $prefix = 'Details';

        switch ($single_type) {
            case 'skaters':
                $item_data = lr_fetch_api_data($access_token, 'user/' . $item_id . '/profile', []);
                $display_name = $item_data->skateName ?? $item_data->firstName ?? '';
                $prefix = 'Rollerskater Profile';
                break;
            case 'spots':
                $item_data = lr_fetch_api_data($access_token, 'spots/' . $item_id, []);
                $display_name = $item_data->spotWithAddress->name ?? '';
                $prefix = 'Skate Spot';
                break;
            case 'events':
                $display_name = ''; // Placeholder for event name
                $prefix = 'Skate Event';
                break;
        }
        
        if (!empty($display_name)) {
            return $prefix . ': ' . $display_name;
        } else {
            return 'Details for ' . ucfirst($single_type);
        }
    }
    
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
    } elseif ($country_slug) {
        $country_details = lr_get_country_details($country_slug);
        if ($country_details) $new_title = 'Roller Skating in ' . $country_details['name'];
    }
    
    return $new_title ? $new_title : $title;
}

