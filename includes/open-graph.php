<?php
/**
 * Open Graph & Meta Tag Generation
 * 
 * Handles the dynamic generation of Open Graph (OG) tags for Let's Roll SEO pages.
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

    // Surgically remove any existing OG/Twitter tags added by other plugins to prevent duplicates.
    $buffer = preg_replace('/<meta (property|name)="(og|twitter):[^"]+" content="[^"]*"\s*\/?>/i', '', $buffer);

    // Inject our new tags right after the <head> tag.
    $buffer = str_replace('<head>', "<head>\n" . $custom_tags_html, $buffer);

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
    
    $html = "<!-- Let's Roll Dynamic OG Tags -->\n";
    foreach ($tags as $property => $content) {
        if (!empty($content)) {
            // Use name attribute for twitter card
            $attribute = (strpos($property, 'twitter') === 0) ? 'name' : 'property';
            $html .= '<meta ' . $attribute . '="' . esc_attr($property) . '" content="' . esc_attr($content) . '">' . "\n";
        }
    }
    $html .= "<!-- End Let's Roll OG Tags -->\n";

    return $html;
}

// --- DATA RETRIEVAL FUNCTIONS ---

function lr_get_page_details_from_uri() {
    if (!isset($_SERVER['REQUEST_URI'])) return null;
    $request_uri = $_SERVER['REQUEST_URI'];
    
    // Check for activity pages
    if (preg_match('#^/activity/([^/]+)(?:/|/amp/)?$#', $request_uri, $matches)) {
        return ['type' => 'activity', 'id' => $matches[1]];
    }
    
    // Check for standard entity pages
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
    $transient_key = 'lr_og_data_v5_' . $single_type . '_' . sanitize_key($item_id);

    if (!lr_is_testing_mode_enabled()) {
        $cached_data = get_transient($transient_key);
        if ($cached_data) { $data = $cached_data; return $data; }
    }

    $access_token = lr_get_api_access_token();
    if (is_wp_error($access_token)) {
        $data = false; return null;
    }

    $api_data = null;
    switch ($single_type) {
        case 'skaters': 
            $api_data = lr_fetch_api_data($access_token, 'user/profile/' . $item_id, []); 
            break; 
        case 'spots': 
            $api_data = lr_fetch_api_data($access_token, 'spots/' . $item_id, []); 
            break; 
        case 'events': 
            $api_data = lr_get_single_event_data($item_id);
            break;
        case 'activity':
            $api_data = lr_get_activity_data($item_id);
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

// --- CONTENT GENERATION HELPERS ---

function lr_get_og_title($data) {
    $page_details = lr_get_page_details_from_uri();
    if (!$page_details) return '';
    switch ($page_details['type']) {
        case 'skaters': return 'Rollerskater Profile: ' . ($data->skateName ?? $data->firstName);
        case 'spots': return 'Skate Spot: ' . ($data->spotWithAddress->name ?? 'Details');
        case 'events': return 'Skate Event: ' . ($data->name ?? 'Details');
        case 'activity':
            $session_name = $data->sessions[0]->name ?? 'A Skate Session';
            $skater_name = $data->userProfiles[0]->skateName ?? 'a Skater';
            return 'Skate Session by ' . $skater_name . ': ' . $session_name;
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
        case 'activity':
            if (!empty($data->sessions[0]->description)) {
                return wp_trim_words(esc_html($data->sessions[0]->description), 25, '...');
            }
            return 'Check out this skate session on Let\'s Roll!';
    }
    return '';
}

function lr_get_og_image_url($data) {
    $page_details = lr_get_page_details_from_uri();
    if (!$page_details) return '';
    
    // Ensure plugin_dir_url is relative to this file, then move up one directory
    $base_proxy_url = plugins_url('image-proxy.php', dirname(__FILE__)); 

    switch ($page_details['type']) {
        case 'skaters': 
            return 'https://beta.web.lets-roll.app/api/user/' . $data->userId . '/avatar/content/processed?width=1200&height=630&quality=85';
        
        case 'spots':
            if (!empty($data->spotWithAddress->satelliteAttachment)) {
                return $base_proxy_url . '?type=spot_satellite&id=' . $data->spotWithAddress->satelliteAttachment . '&width=1200&quality=85';   
            }
            break;
            
        case 'events':
            if (!empty($data->attachments)) {
                return $base_proxy_url . '?type=event_attachment&id=' . $data->attachments[0]->_id . '&session_id=' . $data->_id . '&width=1200&quality=85';
            }
            break;
            
        case 'activity':
            if (!empty($data->attachments)) {
                // Find the first non-static map image to use as the OG image
                foreach ($data->attachments as $attachment) {
                    if (!$attachment->isStaticMap) {
                        return $base_proxy_url . '?type=event_attachment&id=' . $attachment->_id . '&session_id=' . $data->sessions[0]->_id . '&width=1200&quality=85';
                    }
                }
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
