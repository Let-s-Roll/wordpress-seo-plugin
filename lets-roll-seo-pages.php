<?php
/**
 * Plugin Name:       Let's Roll SEO Pages
 * Description:       Dynamically generates pages for skate locations, skaters, and events.
 * Version:           1.1.0
 * Author:            Your Name
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       lets-roll-seo-pages
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// Include all necessary files
if (is_admin()) { require_once plugin_dir_path(__FILE__) . 'admin/admin-page.php'; }
require_once plugin_dir_path(__FILE__) . 'templates/template-country-page.php';
require_once plugin_dir_path(__FILE__) . 'templates/template-city-page.php';
require_once plugin_dir_path(__FILE__) . 'templates/template-detail-page.php';
require_once plugin_dir_path(__FILE__) . 'templates/template-single-spot.php';
require_once plugin_dir_path(__FILE__) . 'templates/template-single-event.php';
require_once plugin_dir_path(__FILE__) . 'templates/template-single-skater.php';
require_once plugin_dir_path(__FILE__) . 'templates/template-explore-page.php';
require_once plugin_dir_path(__FILE__) . 'cta-banner.php';

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
    add_rewrite_tag('%lr_is_explore_page%', '([0-9]+)');
    
    add_rewrite_rule('^explore/?$', 'index.php?lr_is_explore_page=1', 'top');
    add_rewrite_rule('^(spots|events|skaters)/([^/]+)/?$', 'index.php?lr_single_type=$matches[1]&lr_item_id=$matches[2]', 'top');
    
    add_rewrite_rule('^([^/]+)/([^/]+)/([^/]+)/page/([0-9]+)/?$', 'index.php?lr_country=$matches[1]&lr_city=$matches[2]&lr_page_type=$matches[3]&paged=$matches[4]', 'top');
    add_rewrite_rule('^([^/]+)/([^/]+)/([^/]+)/?$', 'index.php?lr_country=$matches[1]&lr_city=$matches[2]&lr_page_type=$matches[3]', 'top');
    add_rewrite_rule('^([^/]+)/([^/]+)/?$', 'index.php?lr_country=$matches[1]&lr_city=$matches[2]', 'top');
    add_rewrite_rule('^([^/]+)/?$', 'index.php?lr_country=$matches[1]', 'top');
}
add_action('init', 'lr_custom_rewrite_rules');

function lr_activate_plugin() { 
    lr_custom_rewrite_rules(); 
    flush_rewrite_rules(); 
}
register_activation_hook(__FILE__, 'lr_activate_plugin');

function lr_calculate_bounding_box($lat, $lon, $radius_km) {
    $earth_radius = 6371;
    $lat_rad = deg2rad($lat);
    $lat_delta = $radius_km / $earth_radius;
    $min_lat = $lat - rad2deg($lat_delta);
    $max_lat = $lat + rad2deg($lat_delta);
    $lon_delta = asin(sin($lat_delta) / cos($lat_rad));
    $min_lon = $lon - rad2deg($lon_delta);
    $max_lon = $lon + rad2deg($lon_delta);
    return ['sw' => $min_lat . ',' . $min_lon, 'ne' => $max_lat . ',' . $max_lon];
}

/**
 * =================================================================================
 * Dynamic Page Generation
 * =================================================================================
 */
add_filter('the_posts', 'lr_virtual_page_controller', 10, 2);

function lr_virtual_page_controller($posts, $query) {
    if ( is_admin() || !$query->is_main_query() || (!get_query_var('lr_country') && !get_query_var('lr_single_type') && !get_query_var('lr_is_explore_page')) ) {
        return $posts;
    }
    
    $post = new stdClass();
    $post->ID = 0;
    $post->post_author = 1;
    $post->post_date = current_time('mysql');
    $post->post_date_gmt = current_time('mysql', 1);
    $post->post_type = 'page';
    $post->post_status = 'publish';
    $post->comment_status = 'closed';
    $post->ping_status = 'closed';
    $post->post_name = '';
    $post->post_parent = 0;
    $post->menu_order = 0;
    $post->comment_count = 0;
    
    $post->post_title = lr_generate_dynamic_title('');
    $post->post_content = lr_generate_dynamic_content('');
    $post->post_name = sanitize_title($post->post_title);

    return [$post];
}

function lr_generate_dynamic_content($content) {
    $single_type = get_query_var('lr_single_type');
    
    if (get_query_var('lr_is_explore_page')) {
        return lr_render_explore_page_content();
    }

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
    $single_type = get_query_var('lr_single_type');

    if (get_query_var('lr_is_explore_page')) {
        return 'Explore Skate Spots Worldwide';
    }

    if ($single_type) {
        $item_id = get_query_var('lr_item_id');
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
                $event = get_transient('lr_event_data_' . $item_id);
                if (false === $event && isset($_GET['lat'], $_GET['lng'])) {
                    $bounding_box = lr_calculate_bounding_box($_GET['lat'], $_GET['lng'], 1);
                    $events_data = lr_fetch_api_data($access_token, 'roll-session/event/inBox', ['ne' => $bounding_box['ne'], 'sw' => $bounding_box['sw']]);
                     if ($events_data && !is_wp_error($events_data) && !empty($events_data->rollEvents)) {
                        foreach($events_data->rollEvents as $event_from_list) {
                            if ($event_from_list->_id === $item_id) {
                                $event = $event_from_list;
                                break;
                            }
                        }
                    }
                }
                $display_name = $event->name ?? '';
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
function lr_display_cta_banner() {
    $cta_text = '';
    $city_name = '';
    $country_slug = get_query_var('lr_country');
    $city_slug = get_query_var('lr_city');
    $page_type = get_query_var('lr_page_type');
    $single_type = get_query_var('lr_single_type');
    $item_id = get_query_var('lr_item_id');

    if ($country_slug && $city_slug) {
        $city_details = lr_get_city_details($country_slug, $city_slug);
        if ($city_details) $city_name = $city_details['name'];
    }

    if ($page_type === 'skatespots' && $city_name) {
        $cta_text = 'Find even more skate spots and see who\'s rolling in ' . $city_name . '. Install the Let\'s Roll app to explore the full map!';
    } elseif ($page_type === 'skaters' && $city_name) {
        $cta_text = 'You\'re seeing just a few of the active skaters in ' . $city_name . '. Join the community and find new friends to skate with on the Let\'s Roll app!';
    } elseif ($page_type === 'events' && $city_name) {
        $cta_text = 'Never miss a local skate event in ' . $city_name . ' again! Get notifications and connect with attendees by downloading the Let\'s Roll app.';
    } elseif ($single_type === 'spots') {
        $cta_text = 'This is just one of many spots waiting for you. Find your next favorite location with the Let\'s Roll app.';
    } elseif ($single_type === 'skaters') {
        $cta_text = 'Connect with this skater and many others from around the world. Share your profile and track your sessions with the Let\'s Roll app!';
    } elseif ($single_type === 'events') {
        $cta_text = 'Ready to roll? See who\'s going and coordinate with friends in the Let\'s Roll app. Install now!';
    } elseif ($city_name) {
         $cta_text = 'Get the full picture of the ' . $city_name . ' skate scene. Find spots, events, and skaters near you with the Let\'s Roll app!';
    }

    if ($cta_text) {
        lr_render_cta_banner($cta_text);
    }
}
add_action('wp_footer', 'lr_display_cta_banner');
add_action('amp_post_template_footer', 'lr_display_cta_banner');

function lr_add_mobile_spacing() {
    if (get_query_var('lr_country') || get_query_var('lr_single_type') || get_query_var('lr_is_explore_page')) {
        echo '<style>@media (max-width: 768px) { .entry-content, .post-content, .page-content { padding-left: 15px !important; padding-right: 15px !important; } }</style>';
    }
}
add_action('wp_head', 'lr_add_mobile_spacing');

