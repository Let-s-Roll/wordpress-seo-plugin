<?php
/**
 * SEO & Social Metadata Generator
 * 
 * Handles the dynamic generation of SEO tags (Title, Description, Canonical)
 * and Open Graph (OG) tags for Let's Roll SEO pages.
 */

if (!defined('ABSPATH')) exit;

// --- 1. DATA HELPERS ---

function lr_get_page_details_from_uri() {
    if (!isset($_SERVER['REQUEST_URI'])) return null;
    $request_uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $request_uri = untrailingslashit($request_uri);
    
    if ($request_uri === '/explore') return ['type' => 'explore'];
    if (preg_match('#^/activity/([^/]+)(?:/amp)?$#', $request_uri, $matches)) return ['type' => 'activity', 'id' => $matches[1]];
    if (preg_match('#^/(spots|events|skaters)/([^/]+)(?:/amp)?$#', $request_uri, $matches)) return ['type' => $matches[1], 'id' => $matches[2]];

    $locations = lr_get_location_data();
    if (empty($locations)) return null;

    $parts = array_values(array_filter(explode('/', $request_uri)));
    if (empty($parts)) return null;

    $country_slug = $parts[0];
    if (isset($locations[$country_slug])) {
        if (count($parts) === 1) return ['type' => 'country', 'country' => $country_slug];
        $city_slug = $parts[1];
        if (isset($locations[$country_slug]['cities'][$city_slug])) {
            if (count($parts) === 2) return ['type' => 'city', 'country' => $country_slug, 'city' => $city_slug];
            $page_type = $parts[2];
            if (in_array($page_type, ['skatespots', 'events', 'skaters'])) return ['type' => 'list', 'country' => $country_slug, 'city' => $city_slug, 'list_type' => $page_type];
            if ($page_type === 'updates') {
                return isset($parts[3]) 
                    ? ['type' => 'update_post', 'country' => $country_slug, 'city' => $city_slug, 'post_slug' => $parts[3]] 
                    : ['type' => 'update_list', 'country' => $country_slug, 'city' => $city_slug];
            }
        }
    }
    return null;
}

function lr_get_current_page_api_data() {
    static $data = null;
    if ($data !== null) return $data;
    
    $page_details = lr_get_page_details_from_uri();
    if (!$page_details) { $data = false; return null; }
    
    if ($page_details['type'] === 'explore') { $data = (object) ['type' => 'explore']; return $data; }

    if (in_array($page_details['type'], ['country', 'city', 'list', 'update_list'])) {
        if ($page_details['type'] === 'country') $data = (object) lr_get_country_details($page_details['country']);
        else $data = (object) lr_get_city_details($page_details['country'], $page_details['city']);
        
        if ($data) {
            $data->lr_page_type = $page_details['type'];
            if ($page_details['type'] === 'list') $data->lr_list_type = $page_details['list_type'];
        }
        return $data;
    }

    if ($page_details['type'] === 'update_post') {
        global $wpdb;
        $table_name = $wpdb->prefix . 'lr_city_updates';
        $update_post = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE city_slug = %s AND post_slug = %s", $page_details['city'], $page_details['post_slug']));
        if ($update_post) { $update_post->lr_page_type = 'update_post'; return $update_post; }
        return null;
    }

    $single_type = $page_details['type'];
    $item_id = $page_details['id'];
    $transient_key = 'lr_og_data_v6_' . $single_type . '_' . sanitize_key($item_id);

    if (!lr_is_testing_mode_enabled()) {
        $cached_data = get_transient($transient_key);
        if ($cached_data) { $data = $cached_data; return $data; }
    }

    $access_token = lr_get_api_access_token();
    if (is_wp_error($access_token)) { $data = false; return null; }

    $api_data = null;
    switch ($single_type) {
        case 'skaters': $api_data = lr_fetch_api_data($access_token, 'user/profile/' . $item_id, []); break;
        case 'spots': $api_data = lr_fetch_api_data($access_token, 'spots/' . $item_id, []); break;
        case 'events': $api_data = lr_get_single_event_data($item_id); break;
        case 'activity': $api_data = lr_get_activity_data($item_id); break;
    }

    if ($api_data && !is_wp_error($api_data)) {
        if (!lr_is_testing_mode_enabled()) set_transient($transient_key, $api_data, 10 * MINUTE_IN_SECONDS);
        $data = $api_data;
        return $data;
    }
    $data = false; return null;
}

// --- 2. SEO GENERATORS ---

function lr_get_canonical_url() {
    $protocol = is_ssl() ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    return untrailingslashit($protocol . $host . $path) . '/';
}

function lr_get_seo_title($data) {
    $page_details = lr_get_page_details_from_uri();
    if (!$page_details) return '';
    
    switch ($page_details['type']) {
        case 'explore': return 'Explore Skate Spots Worldwide | Let\'s Roll';
        case 'country': return 'Roller Skating in ' . ($data->name ?? 'Unknown Country') . ' | Let\'s Roll';
        case 'city': return 'Roller Skating in ' . ($data->name ?? 'Unknown City') . ' - Spots, Events & Skaters';
        case 'list': return (($page_details['list_type'] === 'skatespots') ? 'Skate Spots' : ucfirst($page_details['list_type'])) . ' in ' . ($data->name ?? 'Unknown City') . ' | Let\'s Roll';
        case 'update_list': return 'Skate Updates for ' . ($data->name ?? 'Unknown City') . ' | Let\'s Roll';
        case 'update_post': return ($data->post_title ?? 'City Update') . ' | Let\'s Roll';
        case 'skaters': return 'Rollerskater Profile: ' . ($data->skateName ?? $data->firstName);
        case 'spots': return 'Skate Spot: ' . ($data->spotWithAddress->name ?? 'Details');
        case 'events': return 'Skate Event: ' . ($data->name ?? 'Details');
        case 'activity': return 'Skate Session by ' . ($data->userProfiles[0]->skateName ?? 'a Skater') . ': ' . ($data->sessions[0]->name ?? 'Session');
    }
    return '';
}

function lr_get_seo_description($data) {
    $page_details = lr_get_page_details_from_uri();
    if (!$page_details) return '';
    
    switch ($page_details['type']) {
        case 'explore': return 'Discover the best roller skating spots, events, and a global community of skaters.';
        case 'country': return 'Find the best places to skate in ' . ($data->name ?? 'the country') . '.';
        case 'city': return 'Explore the roller skating scene in ' . ($data->name ?? 'this city') . '.';
        case 'list': return 'Check out the full list of items in ' . ($data->name ?? 'this city') . '.';
        case 'update_list': return 'Stay up to date with skating news in ' . ($data->name ?? 'this city') . '.';
        case 'update_post': return wp_trim_words(esc_html($data->post_summary ?? ''), 25, '...');
        case 'skaters': if (!empty($data->publicBio)) return wp_trim_words(esc_html($data->publicBio), 25, '...'); return 'Check out this profile.';
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
            return 'Rated skate spot. Check details.';
        case 'events':
            if ($data) {
                $parts = [];
                if (!empty($data->event->startDate)) { 
                    try { 
                        $parts[] = 'When: ' . (new DateTime($data->event->startDate))->format('F j, Y, g:i A') . '.'; 
                    } catch (Exception $e) {} 
                } 
                if (!empty($data->event->address)) {
                    $address_obj = json_decode($data->event->address);
                    if (isset($address_obj->formatted_address)) $parts[] = 'Where: ' . $address_obj->formatted_address . '.';
                }
                if (!empty($data->description)) {
                    $parts[] = wp_trim_words(esc_html($data->description), 20, '...');
                }
                return implode(' ', $parts);
            }
            return 'Upcoming event. Check details.';
        case 'activity': return 'Check out this skate session.';
    }
    return '';
}

function lr_get_og_image_url($data) {
    $page_details = lr_get_page_details_from_uri();
    if (!$page_details) return '';
    
    $base_proxy_url = plugins_url('image-proxy.php', dirname(__FILE__));
    $default_image = plugins_url('icon.png', dirname(__FILE__));

    switch ($page_details['type']) {
        case 'skaters': return 'https://beta.web.lets-roll.app/api/user/' . $data->userId . '/avatar/content/processed?width=1200&height=630&quality=85';
        case 'spots': return (!empty($data->spotWithAddress->satelliteAttachment)) ? $base_proxy_url . '?type=spot_satellite&id=' . $data->spotWithAddress->satelliteAttachment . '&width=1200&quality=85' : $default_image;
        case 'events': return (!empty($data->attachments)) ? $base_proxy_url . '?type=event_attachment&id=' . $data->attachments[0]->_id . '&session_id=' . $data->_id . '&width=1200&quality=85' : $default_image;
        case 'activity':
            if (!empty($data->attachments)) {
                foreach ($data->attachments as $attachment) {
                    if (!$attachment->isStaticMap) return $base_proxy_url . '?type=event_attachment&id=' . $attachment->_id . '&session_id=' . $data->sessions[0]->_id . '&width=1200&quality=85';
                }
            }
            return $default_image;
        case 'update_post':
            if (!empty($data->featured_image_url)) return (strpos($data->featured_image_url, 'http') === false) ? home_url($data->featured_image_url) : $data->featured_image_url;
            return $default_image;
        default: return $default_image;
    }
}

function lr_get_og_type($data) {
    $page_details = lr_get_page_details_from_uri();
    if ($page_details && $page_details['type'] === 'skaters') return 'profile';
    return 'article';
}

function lr_generate_seo_meta_tags_html($data) {
    $tags_html = "<!-- Let's Roll SEO & Social Tags -->\n";
    $description = lr_get_seo_description($data);
    $canonical_url = lr_get_canonical_url();
    if ($description) $tags_html .= '<meta name="description" content="' . esc_attr($description) . '">' . "\n";
    if ($canonical_url) $tags_html .= '<link rel="canonical" href="' . esc_url($canonical_url) . '">' . "\n";

    $og_tags = ['og:title' => lr_get_seo_title($data), 'og:description' => $description, 'og:image' => lr_get_og_image_url($data), 'og:type' => lr_get_og_type($data), 'og:url' => $canonical_url, 'twitter:card' => 'summary_large_image'];
    foreach ($og_tags as $property => $content) {
        if (!empty($content)) {
            $attribute = (strpos($property, 'twitter') === 0) ? 'name' : 'property';
            $tags_html .= '<meta ' . $attribute . '="' . esc_attr($property) . '" content="' . esc_attr($content) . '">' . "\n";
        }
    }
    $tags_html .= "<!-- End Let's Roll SEO Tags -->\n";
    return $tags_html;
}

function lr_process_final_html_for_seo($buffer) {
    $data = lr_get_current_page_api_data();
    if (!$data) return $buffer;
    
    $custom_tags_html = lr_generate_seo_meta_tags_html($data);
    
    // Simplest possible replacement to avoid regex issues for now
    $buffer = str_replace('<head>', "<head>\n" . $custom_tags_html, $buffer);
    return $buffer;
}

// --- 3. HOOKS ---

function lr_start_html_buffer_for_seo() {
    if (!is_admin() && lr_get_page_details_from_uri()) {
        ob_start('lr_process_final_html_for_seo');
    }
}

function lr_filter_document_title($title) {
    $data = lr_get_current_page_api_data();
    if ($data) {
        $generated_title = lr_get_seo_title($data);
        if ($generated_title) return $generated_title;
    }
    return $title;
}

add_action('init', 'lr_start_html_buffer_for_seo');
add_filter('pre_get_document_title', 'lr_filter_document_title', 999);