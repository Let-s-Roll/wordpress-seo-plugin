<?php
/**
 * Plugin Name:       Let's Roll SEO Pages
 * Description:       Dynamically generates pages for skate locations, skaters, and events.
 * Version:           1.4.1
 * Author:            Your Name
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       lets-roll-seo-pages
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// --- Guard against double loading ---
if (defined('LR_SEO_PAGES_LOADED')) return;
define('LR_SEO_PAGES_LOADED', true);

define('LR_CACHE_VERSION', 'v3'); // Increment to invalidate all caches
$lr_debug_messages = []; // Global for cache debugging

// Include all necessary files
require_once plugin_dir_path(__FILE__) . 'admin/admin-page.php'; 
require_once plugin_dir_path(__FILE__) . 'admin/brevo-sync-page.php';
require_once plugin_dir_path(__FILE__) . 'admin/content-discovery-page.php';
require_once plugin_dir_path(__FILE__) . 'brevo-integration.php';
require_once plugin_dir_path(__FILE__) . 'templates/template-explore-page.php';
require_once plugin_dir_path(__FILE__) . 'templates/template-country-page.php';
require_once plugin_dir_path(__FILE__) . 'templates/template-city-page.php';
require_once plugin_dir_path(__FILE__) . 'templates/template-detail-page.php';
require_once plugin_dir_path(__FILE__) . 'templates/template-single-spot.php';
require_once plugin_dir_path(__FILE__) . 'templates/template-single-event.php';
require_once plugin_dir_path(__FILE__) . 'templates/template-single-skater.php';
require_once plugin_dir_path(__FILE__) . 'templates/template-single-activity.php';
require_once plugin_dir_path(__FILE__) . 'cta-banner.php';
require_once plugin_dir_path(__FILE__) . 'includes/database.php';
require_once plugin_dir_path(__FILE__) . 'includes/content-discovery.php';

// Hook for adding admin menus
add_action('admin_menu', 'lr_setup_admin_menu');

function lr_setup_admin_menu() {
    // Add the top-level menu page
    add_menu_page(
        'Let\'s Roll',               // Page title
        'Let\'s Roll',               // Menu title
        'manage_options',           // Capability
        'lets-roll-settings',       // Menu slug (for the parent)
        'lr_options_page_html',     // Correct function for the first submenu page
        plugin_dir_url(__FILE__) . 'icon.png', // Custom Icon
        30                          // Position
    );

    // Add the main settings page (this will be the first item, using the parent's slug)
    add_submenu_page(
        'lets-roll-settings',       // Parent slug
        'SEO Settings',             // Page title
        'SEO Settings',             // Menu title
        'manage_options',           // Capability
        'lets-roll-settings',       // Menu slug
        'lr_options_page_html'      // Correct function
    );

    // Add the Brevo Sync sub-menu page
    add_submenu_page(
        'lets-roll-settings',       // Parent slug
        'Brevo Sync',               // Page title
        'Brevo Sync',               // Menu title
        'manage_options',           // Capability
        'lr-brevo-sync',            // Menu slug
        'lr_render_brevo_sync_page' // Function
    );

    // Add the new Content Discovery sub-menu page
    add_submenu_page(
        'lets-roll-settings',       // Parent slug
        'Content Discovery',        // Page title
        'Content Discovery',        // Menu title
        'manage_options',           // Capability
        'lr-content-discovery',     // Menu slug
        'lr_render_content_discovery_page' // Function
    );
}

// Register settings for the Brevo page
function lr_brevo_settings_init() {
    register_setting('lr_brevo_options', 'lr_brevo_options');
}
add_action('admin_init', 'lr_brevo_settings_init');

// Activation, deactivation, and action hooks for the Brevo Sync cron jobs
register_activation_hook(__FILE__, 'lr_activate_brevo_sync_cron');
register_deactivation_hook(__FILE__, 'lr_deactivate_brevo_sync_cron');
add_action('lr_brevo_sync_main_event', 'lr_populate_brevo_sync_queue');
add_action('lr_brevo_sync_worker_event', 'lr_process_brevo_sync_queue');

// Add a custom cron schedule for every 10 minutes.
add_filter('cron_schedules', 'lr_add_ten_minute_cron_interval');
function lr_add_ten_minute_cron_interval($schedules) {
    $schedules['ten_minutes'] = array(
        'interval' => 600, // 10 * 60 seconds
        'display'  => esc_html__('Every Ten Minutes'),
    );
    return $schedules;
}

/**
 * =================================================================================
 * Core API & Location Functions
 * =================================================================================
 */

/**
 * Checks if Testing Mode is enabled in the plugin settings.
 * @return bool True if testing mode is on, false otherwise.
 */
function lr_is_testing_mode_enabled() {
    $options = get_option('lr_options');
    return isset($options['testing_mode']) && $options['testing_mode'] === '1';
}

function lr_get_api_access_token($force_refresh = false) {
    if ($force_refresh) {
        delete_transient('lr_api_access_token');
    }
    $cached_token = get_transient('lr_api_access_token');
    if ($cached_token) return $cached_token;

    $options = get_option('lr_options');
    $email = $options['api_email'] ?? '';
    $password = $options['api_pass'] ?? '';
    $auth_url = 'https://beta.web.lets-roll.app/api/auth/signin/email';

    if (empty($email) || empty($password)) {
        return new WP_Error('no_creds', 'API credentials are not configured.');
    }

    $response = wp_remote_post($auth_url, [
        'headers' => ['Content-Type' => 'application/json'],
        'body'    => json_encode(['email' => $email, 'password' => $password])
    ]);

    // --- IMPROVED ERROR HANDLING ---
    if (is_wp_error($response)) {
        return new WP_Error('auth_wp_error', 'Could not connect to authentication server. WP_Error: ' . $response->get_error_message());
    }

    $response_code = wp_remote_retrieve_response_code($response);
    if ($response_code !== 200) {
        return new WP_Error('auth_failed', 'Could not retrieve access token. The API responded with HTTP code: ' . $response_code);
    }
    // --- END IMPROVED ERROR HANDLING ---

    $body = json_decode(wp_remote_retrieve_body($response));
    $access_token = $body->tokens->access ?? null;

    if ($access_token) {
        // Remove "Bearer " prefix if it exists
        if (strpos($access_token, 'Bearer ') === 0) {
            $access_token = substr($access_token, 7);
        }
        set_transient('lr_api_access_token', $access_token, 55 * MINUTE_IN_SECONDS);
        return $access_token;
    }

    return new WP_Error('token_missing', 'Access token not found in API response.');
}

/**
 * Fetches a list of skaters for a specific city and filters them by the city's radius.
 * This is the new, reusable core function.
 *
 * @param array $city_details The city data array from merged.json.
 * @param int $max_age_days The maximum age in days for skater activity.
 * @return array|WP_Error An array of user profile objects or a WP_Error on failure.
 */
function lr_fetch_filtered_skaters_for_city($city_details, $max_age_days = 90) {
    $access_token = lr_get_api_access_token();
    if (is_wp_error($access_token)) {
        return $access_token;
    }

    $params = [
        'lat'           => $city_details['latitude'],
        'lng'           => $city_details['longitude'],
        'minDistance'   => 0,
        'maxAgeInDays'  => $max_age_days,
        'limit'         => 1000
    ];
    
    $skaters_data = lr_fetch_api_data($access_token, 'nearby-activities/v2/skaters', $params);

    if (is_wp_error($skaters_data) || empty($skaters_data->activities) || empty($skaters_data->userProfiles)) {
        return [];
    }

    $radius_meters = ($city_details['radius_km'] ?? 50) * 1000;
    $user_distances = [];

    // Step 1: Find the minimum distance for each user *within the radius*.
    foreach ($skaters_data->activities as $activity) {
        if (isset($activity->distance) && $activity->distance <= $radius_meters && !empty($activity->userId)) {
            $userId = $activity->userId;
            if (!isset($user_distances[$userId]) || $activity->distance < $user_distances[$userId]) {
                $user_distances[$userId] = $activity->distance;
            }
        }
    }

    if (empty($user_distances)) {
        return [];
    }

    // Step 2: Create the final list of profiles, augmented with their distance.
    $filtered_profiles = [];
    foreach ($skaters_data->userProfiles as $profile) {
        if (!empty($profile->userId) && isset($user_distances[$profile->userId])) {
            $profile->distance_km = round($user_distances[$profile->userId] / 1000, 2);
            $filtered_profiles[] = $profile;
        }
    }
    
    // Sort by distance, closest first.
    usort($filtered_profiles, function($a, $b) {
        return $a->distance_km <=> $b->distance_km;
    });

    return $filtered_profiles;
}

function lr_get_single_event_data($event_id) {
    $event = false;
    $transient_key = LR_CACHE_VERSION . '_lr_event_data_v2_' . $event_id;

    if (!lr_is_testing_mode_enabled()) {
        $event = get_transient($transient_key);
        if ($event) return $event;
    }

    $access_token = lr_get_api_access_token();
    if (is_wp_error($access_token)) return null;

    $endpoint = 'roll-session/' . $event_id . '/aggregates';
    $aggregate_data = lr_fetch_api_data($access_token, $endpoint, []);

    if (!is_wp_error($aggregate_data) && isset($aggregate_data->sessions) && !empty($aggregate_data->sessions)) {
        $event = $aggregate_data->sessions[0];
        $event->attachments = $aggregate_data->attachments ?? [];
        
        if (!lr_is_testing_mode_enabled()) {
            set_transient($transient_key, $event, 4 * HOUR_IN_SECONDS);
        }
    }
    
    return $event;
}

function lr_get_spot_sessions($spot_id) {
    $data = false;
    $transient_key = LR_CACHE_VERSION . '_lr_spot_sessions_full_v2_' . $spot_id;

    if (!lr_is_testing_mode_enabled()) {
        $data = get_transient($transient_key);
        if ($data) return $data;
    }

    $access_token = lr_get_api_access_token();
    if (is_wp_error($access_token)) return null;

    $endpoint = 'spots/' . $spot_id . '/sessions/';
    $params = ['limit' => 50, 'skip' => 0];
    $api_data = lr_fetch_api_data($access_token, $endpoint, $params);

    if (!is_wp_error($api_data) && isset($api_data->sessions) && !empty($api_data->sessions)) {
        // Filter for public sessions before caching the whole object
        $api_data->sessions = array_values(array_filter($api_data->sessions, function($session) {
            return isset($session->visibilityLevel) && $session->visibilityLevel === 'Everyone';
        }));

        if (!empty($api_data->sessions)) {
            if (!lr_is_testing_mode_enabled()) {
                set_transient($transient_key, $api_data, 4 * HOUR_IN_SECONDS);
            }
            return $api_data;
        }
    }
    
    return null;
}

function lr_get_activity_data($activity_id) {
    static $activity_data = []; // In-request cache

    if (isset($activity_data[$activity_id])) {
        return $activity_data[$activity_id];
    }

    $access_token = lr_get_api_access_token();
    if (is_wp_error($access_token)) {
        return new WP_Error('api_auth_error', 'Could not authenticate with the API.');
    }

    $endpoint = 'roll-session/' . $activity_id . '/aggregates';
    $data = lr_fetch_api_data($access_token, $endpoint, []);

    if (is_wp_error($data) || empty($data->sessions[0])) {
        $activity_data[$activity_id] = new WP_Error('api_fetch_error', 'Could not retrieve activity data.');
        return $activity_data[$activity_id];
    }

    $activity_data[$activity_id] = $data;
    return $activity_data[$activity_id];
}


function lr_fetch_api_data($access_token, $endpoint, $params) {
    global $lr_debug_messages;
    $transient_key = LR_CACHE_VERSION . '_lr_api_cache_' . md5($endpoint . serialize($params));

    // --- Caching Logic ---
    if (!lr_is_testing_mode_enabled()) {
        $cached_data = get_transient($transient_key);
        if (false !== $cached_data) {
            if (current_user_can('manage_options')) $lr_debug_messages[] = "CACHE HIT: " . $endpoint;
            return $cached_data;
        }
    }
    if (current_user_can('manage_options')) $lr_debug_messages[] = "CACHE MISS: " . $endpoint;

    // --- API Fetching Logic ---
    $base_url = 'https://beta.web.lets-roll.app/api/';
    $url = $base_url . $endpoint;
    if (!empty($params)) {
        $url = add_query_arg($params, $url);
    }

    $args = [
        'headers' => [
            'Authorization' => 'Bearer ' . $access_token,
            'Content-Type'  => 'application/json',
        ],
        'timeout' => 20,
    ];

    $response = wp_remote_get($url, $args);
    $response_code = wp_remote_retrieve_response_code($response);

    // --- Retry Logic for Expired Token ---
    if (!is_wp_error($response) && $response_code === 401) {
        $new_access_token = lr_get_api_access_token(true); // Force refresh
        if (is_wp_error($new_access_token)) {
            return new WP_Error('reauth_failed', 'The API token was invalid and an attempt to re-authenticate failed. Please check API credentials in settings.');
        }
        
        // Retry the request with the new token
        $args['headers']['Authorization'] = 'Bearer ' . $new_access_token;
        $response = wp_remote_get($url, $args);
        $response_code = wp_remote_retrieve_response_code($response);

        if ($response_code !== 200) {
             return new WP_Error('retry_failed', 'Re-authentication was successful, but the subsequent API call failed with code ' . $response_code . '. The API may be temporarily unavailable.');
        }
    }

    if (is_wp_error($response)) {
        return $response;
    }
    
    if ($response_code !== 200) {
        return new WP_Error('api_error', 'The API returned an unexpected response code: ' . $response_code);
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body);

    // --- Cache the successful result ---
    if (!empty($data)) {
        if (!lr_is_testing_mode_enabled()) {
            set_transient($transient_key, $data, 24 * HOUR_IN_SECONDS);
        }
    }

    return $data;
}

function lr_get_location_data() {
    static $locations = null;
    if ($locations === null) {
        $file_path = plugin_dir_path(__FILE__) . 'country_data/merged.json';
        if (file_exists($file_path)) {
            $locations_json = file_get_contents($file_path);
            $locations = json_decode($locations_json, true);
            $locations = is_array($locations) ? $locations : [];
        } else {
            $locations = [];
        }
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

/**
 * Fetches a raw list of all spots for a given city by its bounding box.
 *
 * @param array $city_details The city data array from merged.json.
 * @return array|WP_Error An array of spot objects or a WP_Error on failure.
 */
function lr_get_spots_for_city($city_details) {
    $access_token = lr_get_api_access_token();
    if (is_wp_error($access_token)) {
        return $access_token;
    }

    $bounding_box = lr_calculate_bounding_box($city_details['latitude'], $city_details['longitude'], $city_details['radius_km']);
    $params = ['ne' => $bounding_box['ne'], 'sw' => $bounding_box['sw'], 'limit' => 1000];
    
    return lr_fetch_api_data($access_token, 'spots/v2/inBox', $params);
}

/**
 * Fetches a raw list of all events for a given city by its bounding box.
 *
 * @param array $city_details The city data array from merged.json.
 * @return array|WP_Error An array of event objects or a WP_Error on failure.
 */
function lr_get_events_for_city($city_details) {
    $access_token = lr_get_api_access_token();
    if (is_wp_error($access_token)) {
        return $access_token;
    }

    $bounding_box = lr_calculate_bounding_box($city_details['latitude'], $city_details['longitude'], $city_details['radius_km']);
    $params = ['ne' => $bounding_box['ne'], 'sw' => $bounding_box['sw'], 'limit' => 1000];

    $response = lr_fetch_api_data($access_token, 'roll-session/event/inBox', $params);

    if (is_wp_error($response) || empty($response->rollEvents)) {
        return [];
    }

    return $response->rollEvents;
}

/**
 * Generates the HTML for a spot's statistics.
 *
 * @param object $spot_details The full spot details object from the API.
 * @return string The HTML for the stats.
 */
function lr_get_spot_stats_html($spot_details) {
    if (!$spot_details || !isset($spot_details->spotWithAddress)) {
        return '';
    }

    $spot_info = $spot_details->spotWithAddress;
    $total_skaters = $spot_details->totalSkaters ?? 0;
    $total_sessions = $spot_details->totalSessions ?? 0;
    $ratings_count = $spot_info->rating->ratingsCount ?? 0;
    $total_value = $spot_info->rating->totalValue ?? 0;
    $avg_rating = ($ratings_count > 0) ? round($total_value / $ratings_count) : 0;
    $stars_html = str_repeat('★', $avg_rating) . str_repeat('☆', 5 - $avg_rating);

    // MODIFIED: Reduced top margin from 10px to 0, and added bottom margin of 5px for balance.
    $output = '<div class="lr-spot-stats" style="font-size: 0.9em; color: #555; margin: 0 10px 5px 10px; text-align: center;">';
    $output .= '<span>' . $stars_html . '</span> &nbsp;&middot;&nbsp; ';
    $output .= '<span>' . esc_html($total_skaters) . '&nbsp;Skaters</span> &nbsp;&middot;&nbsp; ';
    $output .= '<span>' . esc_html($total_sessions) . '&nbsp;Sessions</span>';
    $output .= '</div>';

    return $output;
}

/**
 * Generates and displays breadcrumb navigation for the SEO pages.
 *
 * @return string HTML for the breadcrumbs.
 */
function lr_get_breadcrumbs() {
    $breadcrumbs = '<nav class="lr-breadcrumbs">';
    $breadcrumbs .= '<a href="' . home_url('/explore/') . '">Explore</a>';

    $country_slug = get_query_var('lr_country');
    $city_slug = get_query_var('lr_city');
    $page_type = get_query_var('lr_page_type');

    if ($country_slug) {
        $country_details = lr_get_country_details($country_slug);
        if ($country_details) {
            $breadcrumbs .= ' &gt; <a href="' . home_url('/' . $country_slug . '/') . '">' . esc_html($country_details['name']) . '</a>';
        }
    }

    if ($city_slug) {
        $city_details = lr_get_city_details($country_slug, $city_slug);
        if ($city_details) {
            $breadcrumbs .= ' &gt; <a href="' . home_url('/' . $country_slug . '/' . $city_slug . '/') . '">' . esc_html($city_details['name']) . '</a>';
        }
    }

    if ($page_type) {
        $breadcrumbs .= ' &gt; <span>' . esc_html(ucfirst($page_type)) . '</span>';
    }

    $breadcrumbs .= '</nav>';
    return $breadcrumbs;
}

/**
 * =================================================================================
 * WordPress Integration (Hooks & Rewrites)
 * =================================================================================
 */
function lr_custom_rewrite_rules() { 
    add_rewrite_tag('%lr_single_type%', '(spots|events|skaters)');
    add_rewrite_tag('%lr_item_id%', '([^/]+)');
    add_rewrite_tag('%lr_activity_id%', '([^/]+)'); // Add this line
    add_rewrite_tag('%lr_country%','([^/]+)');
    add_rewrite_tag('%lr_city%','([^/]+)');
    add_rewrite_tag('%lr_page_type%','([^/]+)');
    add_rewrite_tag('%lr_is_explore_page%', '([0-9]+)');
    
    add_rewrite_rule('^explore/?$', 'index.php?lr_is_explore_page=1', 'top');
    add_rewrite_rule('^activity/([^/]+)/?$', 'index.php?lr_activity_id=$matches[1]', 'top'); // Add this line
    add_rewrite_rule('^(spots|events|skaters)/([^/]+)/?$', 'index.php?lr_single_type=$matches[1]&lr_item_id=$matches[2]', 'top');
    
    $locations = lr_get_location_data();
    if (empty($locations)) return;
    $country_slugs = array_map('preg_quote', array_keys($locations));
    $country_regex = implode('|', $country_slugs);
    if ($country_regex) {
        $city_slugs = [];
        foreach ($locations as $country_data) {
            if (!empty($country_data['cities'])) { $city_slugs = array_merge($city_slugs, array_keys($country_data['cities'])); }
        }
        $city_slugs = array_unique(array_map('preg_quote', $city_slugs));
        $city_regex = implode('|', $city_slugs);
        if ($city_regex) {
            add_rewrite_rule("^($country_regex)/($city_regex)/([^/]+)/page/([0-9]+)/?$", 'index.php?lr_country=$matches[1]&lr_city=$matches[2]&lr_page_type=$matches[3]&paged=$matches[4]', 'top');
            add_rewrite_rule("^($country_regex)/($city_regex)/([^/]+)/?$", 'index.php?lr_country=$matches[1]&lr_city=$matches[2]&lr_page_type=$matches[3]', 'top');
            add_rewrite_rule("^($country_regex)/($city_regex)/?$", 'index.php?lr_country=$matches[1]&lr_city=$matches[2]', 'top');
        }
        add_rewrite_rule("^($country_regex)/?$", 'index.php?lr_country=$matches[1]', 'top');
    }

    // Check if the locations file has changed and flush rewrites if necessary.
    $locations_file = plugin_dir_path(__FILE__) . 'country_data/merged.json';
    if (file_exists($locations_file)) {
        $current_hash = md5_file($locations_file);
        $stored_hash = get_option('lr_locations_hash');
        if ($current_hash !== $stored_hash) {
            flush_rewrite_rules();
            update_option('lr_locations_hash', $current_hash);
        }
    }
}
add_action('init', 'lr_custom_rewrite_rules');

function lr_activate_plugin() { 
    lr_create_discovered_content_table();
    lr_custom_rewrite_rules(); 
}
register_activation_hook(__FILE__, 'lr_activate_plugin');

// Activation and deactivation hooks for the Brevo Sync cron jobs
register_activation_hook(__FILE__, 'lr_activate_brevo_sync_cron');
register_deactivation_hook(__FILE__, 'lr_deactivate_brevo_sync_cron');

/**
 * Get the real user IP address, checking for common proxy headers.
 * @return string The user's IP address.
 */
function lr_get_user_ip_address() {
    $ip_headers = [
        'HTTP_CF_CONNECTING_IP', // Cloudflare
        'HTTP_X_FORWARDED_FOR',  // Standard proxy
        'HTTP_CLIENT_IP',
        'HTTP_X_REAL_IP',
        'REMOTE_ADDR'            // Fallback
    ];

    foreach ($ip_headers as $header) {
        if (!empty($_SERVER[$header])) {
            // HTTP_X_FORWARDED_FOR can be a comma-separated list. The first IP is the client.
            $ip_list = explode(',', $_SERVER[$header]);
            return trim($ip_list[0]);
        }
    }
    return '0.0.0.0'; // Should not happen in a real scenario
}

function lr_calculate_bounding_box($lat, $lon, $radius_km) {
    $earth_radius = 6371; $lat_rad = deg2rad($lat); $lat_delta = $radius_km / $earth_radius;
    $min_lat = $lat - rad2deg($lat_delta); $max_lat = $lat + rad2deg($lat_delta);
    $lon_delta = asin(sin($lat_delta) / cos($lat_rad));
    $min_lon = $lon - rad2deg($lon_delta); $max_lon = $lon + rad2deg($lon_delta);
    return ['sw' => $min_lat . ',' . $min_lon, 'ne' => $max_lat . ',' . $max_lon];
}

/**
 * Calculates the distance between two points on Earth given their latitudes and longitudes.
 *
 * @param float $lat1 Latitude of the first point.
 * @param float $lon1 Longitude of the first point.
 * @param float $lat2 Latitude of the second point.
 * @param float $lon2 Longitude of the second point.
 * @return float The distance in kilometers.
 */
function lr_calculate_distance($lat1, $lon1, $lat2, $lon2) {
    $earth_radius = 6371; // in kilometers

    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);

    $a = sin($dLat / 2) * sin($dLat / 2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon / 2) * sin($dLon / 2);
    
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    
    return $earth_radius * $c;
}

/**
 * =================================================================================
 * Dynamic Page Generation
 * =================================================================================
 */
add_filter('the_posts', 'lr_virtual_page_controller', 10, 2);

function lr_virtual_page_controller($posts, $query) {
    if ( is_admin() || !$query->is_main_query() || (!get_query_var('lr_country') && !get_query_var('lr_single_type') && !get_query_var('lr_is_explore_page') && !get_query_var('lr_activity_id')) ) {
        return $posts;
    }
    $single_type = get_query_var('lr_single_type');
    if ($single_type === 'skaters') {
        $item_id = get_query_var('lr_item_id');
        if (preg_match('/^[a-f0-9]{24}$/', $item_id)) {
            $access_token = lr_get_api_access_token();
            $profile_data = lr_fetch_api_data($access_token, 'user/' . $item_id . '/profile', []);
            if (!is_wp_error($profile_data) && !empty($profile_data->skateName)) {
                $new_url = home_url('/skaters/' . $profile_data->skateName . '/');
                wp_redirect($new_url, 301); exit;
            }
        }
    }
    $post = new stdClass();
    $post->ID = 0; $post->post_author = 1; $post->post_date = current_time('mysql');
    $post->post_date_gmt = current_time('mysql', 1); $post->post_type = 'page';
    $post->post_status = 'publish'; $post->comment_status = 'closed';
    $post->ping_status = 'closed'; $post->post_name = ''; $post->post_parent = 0;
    $post->menu_order = 0; $post->comment_count = 0;
    $post->post_title = lr_generate_dynamic_title('');
    $post->post_content = lr_generate_dynamic_content('');
    $post->post_name = sanitize_title($post->post_title);
    return [$post];
}

function lr_generate_dynamic_content($content) {
    $activity_id = get_query_var('lr_activity_id');
    if ($activity_id) {
        $data = lr_get_activity_data($activity_id);
        if (is_wp_error($data)) {
            return '<p>Could not load activity.</p>'; // Or handle error more gracefully
        }

        if (isset($data->sessions[0]->type) && $data->sessions[0]->type === 'Event') {
            return lr_render_single_event_content($activity_id);
        } else {
            return lr_render_single_activity_content($activity_id);
        }
    }

    $single_type = get_query_var('lr_single_type');
    if (get_query_var('lr_is_explore_page')) return lr_render_explore_page_content();
    if ($single_type) {
        $item_id = get_query_var('lr_item_id');
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
    elseif ($country_slug) return lr_render_country_page_content($country_slug);
    return $content;
}

function lr_generate_dynamic_title($title) {
    $activity_id = get_query_var('lr_activity_id');
    if ($activity_id) {
        $access_token = lr_get_api_access_token();
        $endpoint = 'roll-session/' . $activity_id . '/aggregates';
        $data = lr_fetch_api_data($access_token, $endpoint, []);
        if (!is_wp_error($data) && !empty($data->sessions[0]->name)) {
            return 'Skate Session: ' . esc_html($data->sessions[0]->name);
        }
        return 'Skate Session Details';
    }

    $single_type = get_query_var('lr_single_type');
    if (get_query_var('lr_is_explore_page')) return 'Explore Skate Spots Worldwide';
    if ($single_type) {
        $item_id = get_query_var('lr_item_id'); $access_token = lr_get_api_access_token();
        $display_name = ''; $prefix = 'Details';
        switch ($single_type) {
            case 'skaters':
                $item_data = lr_fetch_api_data($access_token, 'user/profile/' . $item_id, []);
                $display_name = $item_data->skateName ?? $item_data->firstName ?? ''; $prefix = 'Rollerskater Profile';
                break;
            case 'spots':
                $item_data = lr_fetch_api_data($access_token, 'spots/' . $item_id, []);
                $display_name = $item_data->spotWithAddress->name ?? ''; $prefix = 'Skate Spot';
                break;
            case 'events':
                $event = lr_get_single_event_data($item_id);
                $display_name = $event->name ?? ''; $prefix = 'Skate Event';
                break;
        }
        if (!empty($display_name)) return $prefix . ': ' . $display_name;
        return 'Details for ' . ucfirst($single_type);
    }
    $country_slug = get_query_var('lr_country');
    $city_slug = get_query_var('lr_city');
    $page_type = get_query_var('lr_page_type');
    $new_title = 'Let\'s Roll';
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
    return $new_title;
}

/**
 * =================================================================================
 * App Install CTA Banner & Custom Styles
 * =================================================================================
 */