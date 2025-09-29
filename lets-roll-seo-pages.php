<?php
/**
 * Plugin Name:       Let's Roll SEO Pages
 * Description:       Dynamically generates pages for skate locations, skaters, and events.
 * Version:           1.2.8
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

function lr_get_api_access_token() {
    $cached_token = get_transient('lr_api_access_token');
    if ($cached_token) return $cached_token;
    $options = get_option('lr_options');
    $email = $options['api_email'] ?? '';
    $password = $options['api_pass'] ?? '';
    $auth_url = 'https://beta.web.lets-roll.app/api/auth/signin/email';
    if (empty($email) || empty($password)) return new WP_Error('no_creds', 'API credentials are not configured.');
    $response = wp_remote_post($auth_url, ['headers' => ['Content-Type' => 'application/json'], 'body' => json_encode(['email' => $email, 'password' => $password])]);
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) return new WP_Error('auth_failed', 'Could not retrieve access token.');
    $body = json_decode(wp_remote_retrieve_body($response));
    $access_token = $body->tokens->access ?? null;
    if ($access_token) {
        set_transient('lr_api_access_token', $access_token, 55 * MINUTE_IN_SECONDS);
        return $access_token;
    }
    return new WP_Error('token_missing', 'Access token not found.');
}

/**
 * Retrieves data for a single event using the /aggregates endpoint.
 *
 * @param string $event_id The ID of the event to fetch.
 * @return object|null The event object or null if not found.
 */
function lr_get_single_event_data($event_id) {
    $event = false;
    $transient_key = 'lr_event_data_v2_' . $event_id;

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


function lr_fetch_api_data($token, $endpoint, $params) {
    if (is_wp_error($token)) return $token;
    $api_base_url = 'https://beta.web.lets-roll.app/api/';
    $full_url = add_query_arg($params, $api_base_url . $endpoint);
    $response = wp_remote_get($full_url, ['headers' => ['Authorization' => $token]]);
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) return new WP_Error('fetch_failed', 'Failed to fetch API data.');
    return json_decode(wp_remote_retrieve_body($response));
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
 * =================================================================================
 * WordPress Integration (Hooks & Rewrites)
 * =================================================================================
 */
function lr_custom_rewrite_rules() { 
    add_rewrite_tag('%lr_single_type%', '(spots|events|skaters)');
    add_rewrite_tag('%lr_item_id%', '([^/]+)');
    add_rewrite_tag('%lr_country%','([^/]+)');
    add_rewrite_tag('%lr_city%','([^/]+)');
    add_rewrite_tag('%lr_page_type%','([^/]+)');
    add_rewrite_tag('%lr_is_explore_page%', '([0-9]+)');
    
    add_rewrite_rule('^explore/?$', 'index.php?lr_is_explore_page=1', 'top');
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
}
add_action('init', 'lr_custom_rewrite_rules');

function lr_activate_plugin() { 
    lr_custom_rewrite_rules(); 
    flush_rewrite_rules(); 
}
register_activation_hook(__FILE__, 'lr_activate_plugin');

function lr_calculate_bounding_box($lat, $lon, $radius_km) {
    $earth_radius = 6371; $lat_rad = deg2rad($lat); $lat_delta = $radius_km / $earth_radius;
    $min_lat = $lat - rad2deg($lat_delta); $max_lat = $lat + rad2deg($lat_delta);
    $lon_delta = asin(sin($lat_delta) / cos($lat_rad));
    $min_lon = $lon - rad2deg($lon_delta); $max_lon = $lon + rad2deg($lon_delta);
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
 * Dynamic Open Graph & Meta Tag Generation (Definitive Strategy)
 * =================================================================================
 */

// Step 1: Start the output buffer at the very beginning of the page load.
add_action('init', 'lr_start_html_buffer_for_og_tags');
function lr_start_html_buffer_for_og_tags() {
    // Only buffer our specific single pages to minimize performance impact.
    if (!is_admin() && lr_get_page_details_from_uri()) {
        ob_start('lr_process_final_html_for_og_tags');
    }
}

// Step 2: Process the final HTML buffer at the very end of the page load.
function lr_process_final_html_for_og_tags($buffer) {
    $data = lr_get_current_page_api_data();
    // If we don't have data for this page, return the buffer unmodified.
    if (!$data) {
        return $buffer;
    }
    
    // Generate our block of correct meta tags.
    $custom_tags_html = lr_generate_custom_meta_tags_html($data);

    // Surgically remove any existing OG/Twitter tags added by other plugins.
    $buffer = preg_replace('/<meta (property|name)="(og|twitter):[^"]+" content="[^"]*"\s*\/?>/i', '', $buffer);

    // Inject our new tags right after the <head> tag.
    $buffer = str_replace('<head>', '<head>' . "\n" . $custom_tags_html, $buffer);

    return $buffer;
}

// Helper function to generate the HTML for our custom tags.
function lr_generate_custom_meta_tags_html($data) {
    $page_details = lr_get_page_details_from_uri();
    if (!$page_details) return '';
    
    // Use our existing logic to get the correct values for each tag
    $tags = [];
    $tags['og:title']       = lr_get_og_title($data);
    $tags['og:description'] = lr_get_og_description($data);
    $tags['og:image']       = lr_get_og_image_url($data);
    $tags['og:type']        = lr_get_og_type($data);
    $tags['og:url']         = home_url($_SERVER['REQUEST_URI']);
    $tags['twitter:card']   = 'summary_large_image';
    
    $html = '<!-- Let\'s Roll Dynamic OG Tags -->'."\n";
    foreach ($tags as $property => $content) {
        if (!empty($content)) {
            // Use name attribute for twitter card
            $attribute = (strpos($property, 'twitter') === 0) ? 'name' : 'property';
            $html .= '<meta ' . $attribute . '="' . esc_attr($property) . '" content="' . esc_attr($content) . '">' . "\n";
        }
    }
    $html .= '<!-- End Let\'s Roll OG Tags -->'."\n";

    return $html;
}

// --- HELPER FUNCTIONS TO GET OG DATA (Refactored from old AIOSEO filters) ---
function lr_get_page_details_from_uri() {
    if (!isset($_SERVER['REQUEST_URI'])) return null;
    $request_uri = $_SERVER['REQUEST_URI'];
    if (preg_match('#^/(spots|events|skaters)/([^/]+)(?:/|/amp/)?$#', $request_uri, $matches)) {
        return ['type' => $matches[1], 'id' => $matches[2]];
    }
    return null;
}

function lr_get_current_page_api_data() {
    static $data = null;
    if ($data !== null) return $data;
    $page_details = lr_get_page_details_from_uri();
    if (!$page_details) { $data = false; return null; }
    
    $single_type = $page_details['type'];
    $item_id = $page_details['id'];
    $transient_key = 'lr_og_data_v4_' . $single_type . '_' . sanitize_key($item_id);

    if (!lr_is_testing_mode_enabled()) {
        $cached_data = get_transient($transient_key);
        if ($cached_data) { $data = $cached_data; return $data; }
    }

    $access_token = lr_get_api_access_token();
    $api_data = null;
    switch ($single_type) {
        case 'skaters': $api_data = lr_fetch_api_data($access_token, 'user/profile/' . $item_id, []); break;
        case 'spots': $api_data = lr_fetch_api_data($access_token, 'spots/' . $item_id, []); break;
        case 'events': 
            $api_data = lr_get_single_event_data($item_id);
            break;
    }
    if ($api_data && !is_wp_error($api_data)) {
        if (!lr_is_testing_mode_enabled()) {
            set_transient($transient_key, $api_data, 10 * MINUTE_IN_SECONDS);
        }
        $data = $api_data;
        return $data;
    }
    $data = false; return null;
}

function lr_get_og_title($data) {
    $page_details = lr_get_page_details_from_uri();
    if (!$page_details) return '';
    switch ($page_details['type']) {
        case 'skaters': return 'Rollerskater Profile: ' . ($data->skateName ?? $data->firstName);
        case 'spots': return 'Skate Spot: ' . ($data->spotWithAddress->name ?? 'Details');
        case 'events': return 'Skate Event: ' . ($data->name ?? 'Details');
    }
    return '';
}

function lr_get_og_description($data) {
    $page_details = lr_get_page_details_from_uri();
    if (!$page_details) return '';
    switch ($page_details['type']) {
        case 'skaters':
            if (!empty($data->publicBio)) return wp_trim_words(esc_html($data->publicBio), 25, '...');
            return 'Check out the profile for ' . esc_html($data->skateName ?? '') . ' on Let\'s Roll and connect with skaters from around the world.';
        case 'spots':
            $spot = $data->spotWithAddress ?? null;
            if ($spot) {
                $parts = [];
                if (!empty($spot->info->address)) $parts[] = $spot->info->address;
                $ratings_count = $spot->rating->ratingsCount ?? 0;
                if ($ratings_count > 0) {
                    $avg_rating = round($spot->rating->totalValue / $ratings_count, 1);
                    $parts[] = 'Rated ' . $avg_rating . ' out of 5 stars by the community.';
                }
                return implode(' ', $parts);
            }
            break;
        case 'events':
            if ($data) {
                $parts = [];
                if (!empty($data->event->startDate)) { try { $parts[] = 'When: ' . (new DateTime($data->event->startDate))->format('F j, Y, g:i A') . '.'; } catch (Exception $e) {} }
                if(!empty($data->event->address)) {
                    $address_obj = json_decode($data->event->address);
                    if(isset($address_obj->formatted_address)) $parts[] = 'Where: ' . $address_obj->formatted_address . '.';
                }
                if (!empty($data->description)) $parts[] = wp_trim_words(esc_html($data->description), 20, '...');
                return implode(' ', $parts);
            }
            break;
    }
    return '';
}

function lr_get_og_image_url($data) {
    $page_details = lr_get_page_details_from_uri();
    if (!$page_details) return '';
    switch ($page_details['type']) {
        case 'skaters': return 'https://beta.web.lets-roll.app/api/user/' . $data->userId . '/avatar/content/processed?width=1200&height=630&quality=85';
        case 'spots':
            if (!empty($data->spotWithAddress->satelliteAttachment)) {
                return plugin_dir_url(__FILE__) . 'image-proxy.php?type=spot_satellite&id=' . $data->spotWithAddress->satelliteAttachment . '&width=1200&quality=85';
            }
            break;
        case 'events':
            // MODIFIED: Use the attachment data we now have on the event object.
            if (!empty($data->attachments)) {
                return plugin_dir_url(__FILE__) . 'image-proxy.php?type=event_attachment&id=' . $data->attachments[0]->_id . '&session_id=' . $data->_id . '&width=1200&quality=85';
            }
            break;
    }
    return '';
}

function lr_get_og_type($data) {
    $page_details = lr_get_page_details_from_uri();
    if ($page_details && $page_details['type'] === 'skaters') return 'profile';
    return 'article';
}

/**
 * =================================================================================
 * App Install CTA Banner & Custom Styles
 * =================================================================================
 */
function lr_display_cta_banner() {
    $cta_text = ''; $city_name = '';
    $country_slug = get_query_var('lr_country'); $city_slug = get_query_var('lr_city');
    $page_type = get_query_var('lr_page_type'); $single_type = get_query_var('lr_single_type');
    if ($country_slug && $city_slug) {
        $city_details = lr_get_city_details($country_slug, $city_slug);
        if ($city_details) $city_name = $city_details['name'];
    }
    if ($page_type === 'skatespots' && $city_name) { $cta_text = 'Find even more skate spots and see who\'s rolling in ' . $city_name . '. Install the Let\'s Roll app to explore the full map!'; } 
    elseif ($page_type === 'skaters' && $city_name) { $cta_text = 'You\'re seeing just a few of the active skaters in ' . $city_name . '. Join the community and find new friends to skate with on the Let\'s Roll app!'; }
    elseif ($page_type === 'events' && $city_name) { $cta_text = 'Never miss a local skate event in ' . $city_name . ' again! Get notifications and connect with attendees by downloading the Let\'s Roll app.'; } 
    elseif ($single_type === 'spots') { $cta_text = 'This is just one of many spots waiting for you. Find your next favorite location with the Let\'s Roll app.'; }
    elseif ($single_type === 'skaters') { $cta_text = 'Connect with this skater and many others from around the world. Share your profile and track your sessions with the Let\'s Roll app!'; }
    elseif ($single_type === 'events') { $cta_text = 'Ready to roll? See who\'s going and coordinate with friends in the Let\'s Roll app. Install now!'; }
    elseif ($city_name) { $cta_text = 'Get the full picture of the ' . $city_name . ' skate scene. Find spots, events, and skaters near you with the Let\'s Roll app!'; }
    if ($cta_text) { lr_render_cta_banner($cta_text); }
}
add_action('wp_footer', 'lr_display_cta_banner');
add_action('amp_post_template_footer', 'lr_display_cta_banner');

function lr_add_mobile_spacing() {
    if (get_query_var('lr_country') || get_query_var('lr_single_type') || get_query_var('lr_is_explore_page')) {
        echo '<style>@media (max-width: 768px) { .entry-content, .post-content, .page-content { padding-left: 15px !important; padding-right: 15px !important; } }</style>';
    }
}
add_action('wp_head', 'lr_add_mobile_spacing');

