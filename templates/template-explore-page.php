<?php
/**
 * Renders the content for the main "Explore" page,
 * including the dynamic "Near You" section.
 */

// Helper function to render the grid of nearby items
function lr_render_nearby_grid($items, $type) {
    if (empty($items)) return '';

    $output = '';
    foreach (array_slice($items, 0, 4) as $item) {
        $name = '';
        $url = '';
        $image_url = 'https://placehold.co/400x240/e0e0e0/757575?text=' . ucfirst($type);
        $alt_text = 'Placeholder image';

        switch ($type) {
            case 'spots':
                // Fetch full details for the individual spot
                $access_token = lr_get_api_access_token();
                if (is_wp_error($access_token)) continue 2;

                $spot_details = lr_fetch_api_data($access_token, 'spots/' . $item->_id, []);
                if (!$spot_details || !isset($spot_details->spotWithAddress)) {
                    continue 2; // Skip this item if details can't be fetched
                }
                
                $name = $spot_details->spotWithAddress->name ?? 'Skate Spot';
                $url = home_url('/spots/' . $spot_details->spotWithAddress->_id . '/');
                $alt_text = 'Satellite view of ' . esc_attr($name);
                if (!empty($spot_details->spotWithAddress->satelliteAttachment)) {
                    $image_url = plugin_dir_url(__FILE__) . '../image-proxy.php?type=spot_satellite&id=' . $spot_details->spotWithAddress->satelliteAttachment . '&width=400&quality=75';
                }
                break;
            case 'events':
                $name = $item->name ?? 'Skate Event';
                $url = home_url('/events/' . $item->_id . '/');
                $alt_text = 'Image for ' . esc_attr($name);
                // --- FIX: Fetch attachments separately, like the city page does ---
                $access_token = lr_get_api_access_token();
                if (!is_wp_error($access_token)) {
                    $attachments = lr_fetch_api_data($access_token, 'roll-session/' . $item->_id . '/attachments', []);
                    if (!is_wp_error($attachments) && !empty($attachments)) {
                        $image_url = plugin_dir_url(__FILE__) . '../image-proxy.php?type=event_attachment&id=' . $attachments[0]->_id . '&session_id=' . $item->_id . '&width=400&quality=75';
                    }
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
                $output .= '<img src="' . esc_url($avatar_url) . '" onerror="this.onerror=null;this.src=\''. esc_url($placeholder_url) .'\'"';
                $output .= ' alt="' . $alt_text . '" loading="lazy" width="120" height="120" />';
                $output .= '<div class="lr-grid-item-content"><h4>' . esc_html($name) . '</h4></div></a></div>';
                continue 2; // Continue the parent foreach loop
        }

        $output .= '<div class="lr-grid-item">';
        $output .= '<a href="' . esc_url($url) . '">';
        $output .= '<img src="' . esc_url($image_url) . '" alt="' . esc_attr($alt_text) . '" loading="lazy" width="400" height="240" />';
        $output .= '<div class="lr-grid-item-content"><h4>' . esc_html($name) . '</h4></div></a></div>';
    }
    return $output;
}

// Function to fetch and render the "Near You" section content
function lr_get_and_render_nearby_content($access_token, $lat, $lon, $radius_km = 50, $country_slug = null, $city_slug = null) {
    if (is_wp_error($access_token)) {
        // This is a fallback, the main function should prevent this.
        return '<p>An invalid access token was provided.</p>';
    }

    $output = '';
    $bounding_box = lr_calculate_bounding_box($lat, $lon, $radius_km);

    // Helper for "View All" links
    $render_view_all = function($url, $text) {
        return '<p style="text-align: right; margin-top: 15px;"><a href="' . esc_url($url) . '">' . esc_html($text) . ' &raquo;</a></p>';
    };

    // Fetch Nearby Spots
    $spots_params = ['ne' => $bounding_box['ne'], 'sw' => $bounding_box['sw'], 'limit' => 1000];
    $spots_list = lr_fetch_api_data($access_token, 'spots/v2/inBox', $spots_params);
    if (!is_wp_error($spots_list) && !empty($spots_list)) {
        usort($spots_list, function($a, $b) { return ($b->sessionsCount ?? 0) <=> ($a->sessionsCount ?? 0); });
        
        $output .= '<h3>Nearby Skate Spots</h3>';
        $output .= '<div class="lr-grid">' . lr_render_nearby_grid($spots_list, 'spots') . '</div>';
        if ($country_slug && $city_slug) {
            $output .= $render_view_all(home_url('/' . $country_slug . '/' . $city_slug . '/skatespots/'), 'View All Skate Spots');
        }
    }

    // Fetch Nearby Events
    $events_params = ['ne' => $bounding_box['ne'], 'sw' => $bounding_box['sw'], 'limit' => 1000];
    $events_data = lr_fetch_api_data($access_token, 'roll-session/event/inBox', $events_params);
    if (!is_wp_error($events_data) && !empty($events_data->rollEvents)) {
        $now = new DateTime();
        $upcoming_events = array_filter($events_data->rollEvents, function($event) use ($now) {
            return isset($event->event->endDate) && (new DateTime($event->event->endDate) > $now);
        });
        usort($upcoming_events, function($a, $b) { return strtotime($a->event->startDate) <=> strtotime($b->event->startDate); });
        
        if(!empty($upcoming_events)) {
            $output .= '<hr style="margin: 20px 0;"><h3>Upcoming Events</h3>';
            $output .= '<div class="lr-grid">' . lr_render_nearby_grid($upcoming_events, 'events') . '</div>';
            if ($country_slug && $city_slug) {
                $output .= $render_view_all(home_url('/' . $country_slug . '/' . $city_slug . '/events/'), 'View All Events');
            }
        }
    }

    // Fetch Nearby Skaters
    $skaters_params = ['lat' => $lat, 'lng' => $lon, 'minDistance' => 0, 'maxAgeInDays' => 90, 'limit' => 20];
    $skaters_data = lr_fetch_api_data($access_token, 'nearby-activities/v2/skaters', $skaters_params);
    if (!is_wp_error($skaters_data) && !empty($skaters_data->userProfiles)) {
        $output .= '<hr style="margin: 20px 0;"><h3>Local Skaters</h3>';
        $output .= '<div class="lr-grid">' . lr_render_nearby_grid($skaters_data->userProfiles, 'skaters') . '</div>';
        if ($country_slug && $city_slug) {
            $output .= $render_view_all(home_url('/' . $country_slug . '/' . $city_slug . '/skaters/'), 'View All Skaters');
        }
    }

    if (empty($output)) {
        return '<p>No skate spots, events, or skaters found near your location. Try exploring our featured cities below!</p>';
    }

    return $output;
}


function lr_render_explore_page_content() {
    $output = lr_get_breadcrumbs();
    $matched_city_html = '';

    // --- Primary Authentication & Debugging ---
    $access_token = lr_get_api_access_token();

    // --- Bot Detection ---
    $is_bot = false;
    $bot_user_agents = ['Googlebot', 'Bingbot', 'Slurp', 'DuckDuckBot', 'Baiduspider', 'YandexBot'];
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    foreach ($bot_user_agents as $bot) {
        if (stripos($user_agent, $bot) !== false) {
            $is_bot = true;
            break;
        }
    }

    // --- City Matching Logic (only run if we have a valid token) ---
    if (!$is_bot && !is_wp_error($access_token)) {
        $ip_address = lr_get_user_ip_address();

        if (in_array($ip_address, ['127.0.0.1', '::1'])) {
            // For local dev, use Los Angeles data to ensure we have events for testing
            $locations = lr_get_location_data();
            $la_data = $locations['united-states']['cities']['los-angeles'] ?? null;
            if ($la_data) {
                $nearby_content = lr_get_and_render_nearby_content(
                    $access_token,
                    $la_data['latitude'],
                    $la_data['longitude'],
                    $la_data['radius_km'],
                    'united-states',
                    'los-angeles'
                );
                if (!empty($nearby_content)) {
                    $matched_city_html .= '<div class="lr-near-you-section">';
                    $matched_city_html .= '<h2>Rollerskating Near You</h2>';
                    $matched_city_html .= '<p style="font-size: 0.9em; color: #666;">(Testing with local data for Los Angeles)</p>';
                    $matched_city_html .= $nearby_content;
                    $matched_city_html .= '</div><hr style="margin: 30px 0;">';
                }
            }
        } else {
            // For live environment, use IP Geolocation
            $geo_data = null;
            $transient_key = LR_CACHE_VERSION . '_lr_geo_cache_' . md5($ip_address);

            if (!lr_is_testing_mode_enabled()) {
                $geo_data = get_transient($transient_key);
            }

            if (false === $geo_data) {
                $response = wp_remote_get('http://ip-api.com/json/' . $ip_address);
                if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                    $geo_data = json_decode(wp_remote_retrieve_body($response));
                    if ($geo_data && $geo_data->status === 'success') {
                        if (!lr_is_testing_mode_enabled()) {
                            set_transient($transient_key, $geo_data, 24 * HOUR_IN_SECONDS);
                        }
                    }
                }
            }

            if ($geo_data && $geo_data->status === 'success') {
                $user_lat = $geo_data->lat;
                $user_lon = $geo_data->lon;

                $all_locations = lr_get_location_data();
                $matched_city = null;

                foreach ($all_locations as $country_slug => $country_details) {
                    foreach ($country_details['cities'] as $city_slug => $city_details) {
                        $distance = lr_calculate_distance($user_lat, $user_lon, $city_details['latitude'], $city_details['longitude']);
                        if ($distance <= $city_details['radius_km']) {
                            $matched_city = $city_details;
                            $matched_city['country_slug'] = $country_slug;
                            $matched_city['city_slug'] = $city_slug;
                            break 2; // Break both loops
                        }
                    }
                }

                if ($matched_city) {
                    $nearby_content = lr_get_and_render_nearby_content(
                        $access_token,
                        $matched_city['latitude'],
                        $matched_city['longitude'],
                        $matched_city['radius_km'],
                        $matched_city['country_slug'],
                        $matched_city['city_slug']
                    );

                    if (!empty($nearby_content)) {
                        $matched_city_html .= '<div class="lr-near-you-section">';
                        $matched_city_html .= '<h2>Rollerskating Near You</h2>';
                        $matched_city_html .= '<p style="font-size: 0.9em; color: #666;">(Based on your location in ' . esc_html($matched_city['name']) . ')</p>';
                        $matched_city_html .= $nearby_content;
                        $matched_city_html .= '</div><hr style="margin: 30px 0;">';
                    }
                }
            }
        }
    }

    $output .= $matched_city_html;

    // --- Render Featured Cities & Country List (always visible) ---

    // Render Featured Cities Section
    $output .= '<div class="lr-featured-cities-section">';
    $output .= '<h2>Featured Cities</h2>';
    $output .= '<p>Discover some of the most vibrant rollerskating scenes around the globe.</p>';
    $output .= '<div class="lr-grid">';

    $featured_cities = [
        ['name' => 'Paris', 'url' => home_url('/france/paris/')],
        ['name' => 'New York City', 'url' => home_url('/united-states/new-york-city/')],
        ['name' => 'Tokyo', 'url' => home_url('/japan/tokyo/')],
        ['name' => 'Los Angeles', 'url' => home_url('/united-states/los-angeles/')]
    ];

    foreach ($featured_cities as $city) {
        $output .= '<div class="lr-grid-item">';
        $output .= '<a href="' . esc_url($city['url']) . '">';
        $output .= '<img src="https://placehold.co/400x240/e0e0e0/757575?text=' . urlencode($city['name']) . '" alt="' . esc_attr($city['name']) . '" />';
        $output .= '<div class="lr-grid-item-content"><h4>' . esc_html($city['name']) . '</h4></div>';
        $output .= '</a></div>';
    }

    $output .= '</div></div><hr style="margin: 30px 0;">';

    // Render the main country list
    $locations = lr_get_location_data();
    if (empty($locations)) {
        $output .= '<p>No locations have been configured yet.</p>';
        return $output;
    }

    $output .= '<h2>Or, Explore the Full Country List</h2>';
    $output .= '<p>Select a country below to explore local roller skating scenes, spots, and events.</p>';
    
    $output .= '<style>
        .lr-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-top: 15px; }
        .lr-grid-item { border: 1px solid #eee; border-radius: 5px; overflow: hidden; text-align: center; height: 100%; display: flex; flex-direction: column; }
        .lr-grid-item a { text-decoration: none; color: inherit; display: flex; flex-direction: column; height: 100%; }
        .lr-grid-item img { width: 100%; height: 120px; object-fit: cover; background-color: #f0f0f0; }
        .lr-grid-item .lr-grid-item-content { padding: 15px; flex-grow: 1; display: flex; align-items: center; justify-content: center; }
        .lr-grid-item .lr-grid-item-content h4 { margin: 0; font-size: 1.2em; }
        .lr-grid-item-skater img { width: 120px; height: 120px; border-radius: 50%; margin: 10px auto 0; }
        .lr-country-list { column-count: 4; column-gap: 20px; margin-top: 15px; }
        .lr-country-list a { display: block; margin-bottom: 10px; text-decoration: none; }
        @media (max-width: 1024px) { .lr-grid { grid-template-columns: repeat(2, 1fr); } .lr-country-list { column-count: 2; } }
        @media (max-width: 768px) { .lr-grid { grid-template-columns: 1fr; } }
    </style>';

    $output .= '<div class="lr-country-list">';

    uasort($locations, function($a, $b) {
        return strcmp($a['name'], $b['name']);
    });

    foreach ($locations as $country_slug => $country_details) {
        $country_name = $country_details['name'] ?? ucfirst(str_replace('-', ' ', $country_slug));
        $country_url = home_url('/' . $country_slug . '/');
        $output .= '<a href="' . esc_url($country_url) . '">' . esc_html($country_name) . '</a>';
    }

    $output .= '</div>';

    // --- ADMIN DEBUG: Show Cache Status ---
    global $lr_debug_messages;
    if (current_user_can('manage_options') && !empty($lr_debug_messages)) {
        $output .= '<div style="background-color: #e8f5e9; border: 1px solid #c8e6c9; padding: 15px; margin-top: 20px;">';
        $output .= '<strong>Admin Debug (Cache Status):</strong><br>';
        foreach ($lr_debug_messages as $message) {
            $output .= esc_html($message) . '<br>';
        }
        $output .= '</div>';
    }

    return $output;
}