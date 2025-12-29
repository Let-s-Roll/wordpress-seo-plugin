<?php
/**
 * SEO & Social Metadata Generator
 * 
 * Handles the dynamic generation of SEO tags (Title, Description, Canonical)
 * and Open Graph (OG) tags for Let's Roll SEO pages.
 */

// Step 1: Start the output buffer for direct injection (OG, Description, Canonical)
add_action('init', 'lr_start_html_buffer_for_seo');

// Step 2: Filter the standard WordPress title tag
add_filter('pre_get_document_title', 'lr_filter_document_title', 999);

function lr_start_html_buffer_for_seo() {
    if (!is_admin() && lr_get_page_details_from_uri()) {
        ob_start('lr_process_final_html_for_seo');
    }
}

function lr_process_final_html_for_seo($buffer) {
    $data = lr_get_current_page_api_data();
    if (!$data) {
        return $buffer;
    }
    
    // Generate our block of custom tags
    $custom_tags_html = lr_generate_seo_meta_tags_html($data);

    // Remove existing OG/Twitter tags
    $buffer = preg_replace('/<meta (property|name)="(og|twitter):[^"]+" content="[^"]*"
s*\/?>/i', '', $buffer);
    
    // Remove existing meta description to avoid duplicates
    $buffer = preg_replace('/<meta name="description" content="[^"]*"
s*\/?>/i', '', $buffer);
    
    // Remove existing canonical link
    $buffer = preg_replace('/<link rel="canonical" href="[^"]*"
s*\/?>/i', '', $buffer);

    // Inject our new tags
    $buffer = str_replace('<head>', "<head>\n" . $custom_tags_html, $buffer);

    return $buffer;
}

function lr_filter_document_title($title) {
    $data = lr_get_current_page_api_data();
    if ($data) {
        $generated_title = lr_get_seo_title($data);
        if ($generated_title) {
            return $generated_title;
        }
    }
    return $title;
}

function lr_generate_seo_meta_tags_html($data) {
    $tags_html = "<!-- Let's Roll SEO & Social Tags -->\n";
    
    // 1. Standard SEO Tags
    $description = lr_get_seo_description($data);
    $canonical_url = lr_get_canonical_url();
    
    if ($description) {
        $tags_html .= '<meta name="description" content="' . esc_attr($description) . '">' . "\n";
    }
    if ($canonical_url) {
        $tags_html .= '<link rel="canonical" href="' . esc_url($canonical_url) . '">' . "\n";
    }

    // 2. Open Graph Tags
    $og_tags = [];
    $og_tags['og:title']       = lr_get_seo_title($data); // Reuse SEO title
    $og_tags['og:description'] = $description;            // Reuse SEO description
    $og_tags['og:image']       = lr_get_og_image_url($data);
    $og_tags['og:type']        = lr_get_og_type($data);
    $og_tags['og:url']         = $canonical_url;
    $og_tags['twitter:card']   = 'summary_large_image';
    
    foreach ($og_tags as $property => $content) {
        if (!empty($content)) {
            $attribute = (strpos($property, 'twitter') === 0) ? 'name' : 'property';
            $tags_html .= '<meta ' . $attribute . '="' . esc_attr($property) . '" content="' . esc_attr($content) . '">' . "\n";
        }
    }
    
    $tags_html .= "<!-- End Let's Roll SEO Tags -->\n";

    return $tags_html;
}

// --- DATA HELPERS ---

function lr_get_canonical_url() {
    $protocol = is_ssl() ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    return untrailingslashit($protocol . $host . $path) . '/'; // Enforce trailing slash
}

function lr_get_seo_title($data) {
    $page_details = lr_get_page_details_from_uri();
    if (!$page_details) return '';
    
    switch ($page_details['type']) {
        case 'explore': 
            return 'Explore Skate Spots Worldwide | Let\'s Roll';
        case 'country':
            return 'Roller Skating in ' . ($data->name ?? 'Unknown Country') . ' | Let\'s Roll';
        case 'city':
            return 'Roller Skating in ' . ($data->name ?? 'Unknown City') . ' - Spots, Events & Skaters';
        case 'list':
            $list_name = ($page_details['list_type'] === 'skatespots') ? 'Skate Spots' : ucfirst($page_details['list_type']);
            return $list_name . ' in ' . ($data->name ?? 'Unknown City') . ' | Let\'s Roll';
        case 'update_list':
            return 'Skate Updates for ' . ($data->name ?? 'Unknown City') . ' | Let\'s Roll';
        case 'update_post':
            return ($data->post_title ?? 'City Update') . ' | Let\'s Roll';
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

function lr_get_seo_description($data) {
    $page_details = lr_get_page_details_from_uri();
    if (!$page_details) return '';
    
    switch ($page_details['type']) {
        case 'explore':
            return 'Discover the best roller skating spots, events, and a global community of skaters. Join Let\'s Roll and explore the world on eight wheels.';
        case 'country':
            return 'Find the best places to skate, upcoming events, and local skating communities across ' . ($data->name ?? 'the country') . '.';
        case 'city':
            if (!empty($data->description)) return wp_trim_words(esc_html($data->description), 25, '...');
            return 'Explore the roller skating scene in ' . ($data->name ?? 'this city') . '. Find top skate spots, join local events, and connect with skaters.';
        case 'list':
            $list_name = ($page_details['list_type'] === 'skatespots') ? 'skate spots' : $page_details['list_type'];
            return 'Check out the full list of ' . $list_name . ' in ' . ($data->name ?? 'this city') . ' and see what\'s happening in the local community.';
        case 'update_list':
            return 'Stay up to date with the latest skate spots, events, and community news in ' . ($data->name ?? 'this city') . '.';
        case 'update_post':
            return wp_trim_words(esc_html($data->post_summary ?? ''), 25, '...');
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
    
    $base_proxy_url = plugins_url('image-proxy.php', dirname(__FILE__));
    $default_image = plugins_url('icon.png', dirname(__FILE__));

    switch ($page_details['type']) {
        case 'explore':
        case 'country':
        case 'city':
        case 'list':
        case 'update_list':
            return $default_image;
        
        case 'update_post':
            if (!empty($data->featured_image_url)) {
                if (strpos($data->featured_image_url, 'http') === false) return home_url($data->featured_image_url);
                return $data->featured_image_url;
            }
            return $default_image;
            
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