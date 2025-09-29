<?php
/**
 * Renders the content for the main "Explore" page,
 * including the dynamic "Near You" section.
 */

// Helper function to render the grid of nearby items
function lr_render_nearby_grid($items, $type) {
    if (empty($items)) return '';

    $output = '<div class="lr-grid">';
    foreach (array_slice($items, 0, 3) as $item) {
        $name = '';
        $url = '';
        $image_url = 'https://placehold.co/400x240/e0e0e0/757575?text=' . ucfirst($type);
        $alt_text = 'Placeholder image';

        switch ($type) {
            case 'spots':
                $name = $item->spotWithAddress->name ?? 'Skate Spot';
                $url = home_url('/spots/' . $item->spotWithAddress->_id . '/');
                $alt_text = 'Satellite view of ' . esc_attr($name);
                if (!empty($item->spotWithAddress->satelliteAttachment)) {
                    $image_url = plugin_dir_url(__FILE__) . '../image-proxy.php?type=spot_satellite&id=' . $item->spotWithAddress->satelliteAttachment . '&width=400&quality=75';
                }
                break;
            case 'events':
                $name = $item->name ?? 'Skate Event';
                $url = home_url('/events/' . $item->_id . '/');
                $alt_text = 'Image for ' . esc_attr($name);
                if (!empty($item->attachments)) {
                     $image_url = plugin_dir_url(__FILE__) . '../image-proxy.php?type=event_attachment&id=' . $item->attachments[0]->_id . '&session_id=' . $item->_id . '&width=400&quality=75';
                }
                break;
            case 'skaters':
                $name = $item->skateName ?? 'Local Skater';
                $url = home_url('/skaters/' . $item->skateName . '/');
                $alt_text = 'Avatar for ' . esc_attr($name);
                $avatar_url = 'https://beta.web.lets-roll.app/api/user/' . $item->userId . '/avatar/content/processed?width=120&height=120&quality=75';
                $placeholder_url = 'https://placehold.co/120x120/e0e0e0/757575?text=Skater';

                $output .= '<div class="lr-grid-item lr-grid-item-skater">';
                $output .= '<a href="' . esc_url($url) . '">';
                $output .= '<img src="' . esc_url($avatar_url) . '" onerror="this.onerror=null;this.src=\''. esc_url($placeholder_url) .'\''; // Corrected escaping for onerror attribute
                $output .= ' alt="' . $alt_text . '" loading="lazy" width="120" height="120" />';
                $output .= '<div class="lr-grid-item-content"><h4>' . esc_html($name) . '</h4></div></a></div>';
                continue 2; // Continue the parent foreach loop
        }

        $output .= '<div class="lr-grid-item">';
        $output .= '<a href="' . esc_url($url) . '">';
        $output .= '<img src="' . esc_url($image_url) . '" alt="' . $alt_text . '" loading="lazy" width="200" height="120" />';
        $output .= '<div class="lr-grid-item-content"><h4>' . esc_html($name) . '</h4></div></a></div>';
    }
    $output .= '</div>';
    return $output;
}

// Function to fetch and render the "Near You" section content
function lr_get_and_render_nearby_content($lat, $lon) {
    $access_token = lr_get_api_access_token();
    if (is_wp_error($access_token)) return '<p>Could not authenticate with API.</p>';

    $output = '';
    $radius_km = 50; // Default radius
    $bounding_box = lr_calculate_bounding_box($lat, $lon, $radius_km);

    // Fetch Nearby Spots
    $spots_params = ['ne' => $bounding_box['ne'], 'sw' => $bounding_box['sw'], 'limit' => 3];
    $spots_list = lr_fetch_api_data($access_token, 'spots/v2/inBox', $spots_params);
    if (!is_wp_error($spots_list) && !empty($spots_list)) {
        $output .= '<h3>Nearby Skate Spots</h3>';
        $output .= lr_render_nearby_grid($spots_list, 'spots');
    }

    // Fetch Nearby Events
    $events_params = ['ne' => $bounding_box['ne'], 'sw' => $bounding_box['sw']];
    $events_data = lr_fetch_api_data($access_token, 'roll-session/event/inBox', $events_params);
    if (!is_wp_error($events_data) && !empty($events_data->rollEvents)) {
        $now = new DateTime();
        $upcoming_events = array_filter($events_data->rollEvents, function($event) use ($now) {
            return isset($event->event->endDate) && (new DateTime($event->event->endDate) > $now);
        });
        usort($upcoming_events, function($a, $b) { return strtotime($a->event->startDate) <=> strtotime($b->event->startDate); });
        
        if(!empty($upcoming_events)) {
            $output .= '<hr style="margin: 20px 0;"><h3>Upcoming Events</h3>';
            $output .= lr_render_nearby_grid($upcoming_events, 'events');
        }
    }

    // Fetch Nearby Skaters
    $skaters_params = ['lat' => $lat, 'lng' => $lon, 'minDistance' => 0, 'maxAgeInDays' => 90, 'limit' => 3];
    $skaters_data = lr_fetch_api_data($access_token, 'nearby-activities/v2/skaters', $skaters_params);
    if (!is_wp_error($skaters_data) && !empty($skaters_data->userProfiles)) {
        $output .= '<hr style="margin: 20px 0;"><h3>Local Skaters</h3>';
        $output .= lr_render_nearby_grid($skaters_data->userProfiles, 'skaters');
    }

    if (empty($output)) {
        return '<p>No skate spots, events, or skaters found near your location. Try exploring our featured cities below!</p>';
    }

    return $output;
}


function lr_render_explore_page_content() {
    $output = '';
    $ip_located_content = '';

    // Attempt IP Geolocation first
    $ip_address = $_SERVER['REMOTE_ADDR'];
    // Use a placeholder for local development
    if (in_array($ip_address, ['127.0.0.1', '::1'])) {
        $ip_address = '8.8.8.8'; // Google's DNS for testing
    }

    $response = wp_remote_get('http://ip-api.com/json/' . $ip_address);
    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
        $geo_data = json_decode(wp_remote_retrieve_body($response));
        if ($geo_data && $geo_data->status === 'success') {
            $ip_located_content = lr_get_and_render_nearby_content($geo_data->lat, $geo_data->lon);
        }
    }

    // Render "Near You" section (either with content or as a placeholder)
    $output .= '<div class="lr-near-you-section">';
    $output .= '<h2>Rollerskating Near You</h2>';
    $output .= '<div id="lr-nearby-content-container">';
    if (!empty($ip_located_content)) {
        $output .= $ip_located_content;
    } else {
        // Placeholder for AJAX
        $output .= '<p class="lr-loading-message">Attempting to detect your location...</p>';
    }
    $output .= '</div></div><hr style="margin: 30px 0;">';


    // Render the main country grid
    $locations = lr_get_location_data();
    if (empty($locations)) {
        $output .= '<p>No locations have been configured yet.</p>';
        return $output;
    }

    $output .= '<h2>Explore By Country</h2>';
    $output .= '<p>Select a country below to explore local roller skating scenes, spots, and events.</p>';
    
    // Grid styles
    $output .= '<style>
        .lr-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 20px; margin-top: 15px; }
        .lr-grid-item { border: 1px solid #eee; border-radius: 5px; overflow: hidden; text-align: center; height: 100%; display: flex; flex-direction: column; }
        .lr-grid-item a { text-decoration: none; color: inherit; display: flex; flex-direction: column; height: 100%; }
        .lr-grid-item img { width: 100%; height: 120px; object-fit: cover; background-color: #f0f0f0; }
        .lr-grid-item .lr-grid-item-content { padding: 15px; flex-grow: 1; display: flex; align-items: center; justify-content: center; }
        .lr-grid-item .lr-grid-item-content h4 { margin: 0; font-size: 1.2em; }
        .lr-grid-item-skater img { width: 120px; height: 120px; border-radius: 50%; margin: 10px auto 0; }
        @media (max-width: 768px) { .lr-grid { grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); } }
    </style>';

    $output .= '<div class="lr-grid">';

    uasort($locations, function($a, $b) {
        return strcmp($a['name'], $b['name']);
    });

    foreach ($locations as $country_slug => $country_details) {
        $country_name = $country_details['name'] ?? ucfirst(str_replace('-', ' ', $country_slug));
        $country_url = home_url('/' . $country_slug . '/');
        
        $output .= '<div class="lr-grid-item">';
        $output .= '<a href="' . esc_url($country_url) . '">';
        // For now, we use a placeholder. A future enhancement could be to fetch a representative image.
        $output .= '<img src="https://placehold.co/400x240/e0e0e0/757575?text=' . urlencode($country_name) . '" alt="' . esc_attr($country_name) . '" />';
        $output .= '<div class="lr-grid-item-content"><h4>' . esc_html($country_name) . '</h4></div>';
        $output .= '</a></div>';
    }

    $output .= '</div>';

    return $output;
}