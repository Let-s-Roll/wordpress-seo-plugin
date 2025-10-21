<?php
/**
 * Renders the content for a city overview page.
 */
function lr_render_city_page_content($country_slug, $city_slug) {
    if (lr_is_testing_mode_enabled()) {
        delete_transient('lr_city_page_html_v3_' . sanitize_key($city_slug));
    }
    // --- ADDED: Fragment Caching ---
    $transient_key = 'lr_city_page_html_v3_' . sanitize_key($city_slug); // v3 to invalidate old cache
    $cached_html = get_transient($transient_key);
    if ($cached_html) {
        return $cached_html;
    }

    $city_details = lr_get_city_details($country_slug, $city_slug);
    if (!$city_details) return '<p>City not found.</p>';

    $access_token = lr_get_api_access_token();
    $output = lr_get_breadcrumbs();

    // --- (CSS remains the same) ---
    $output .= '
    <style>
        .lr-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-top: 15px; }
        .lr-grid-item { border: 1px solid #eee; border-radius: 5px; overflow: hidden; text-align: center; height: 100%; display: flex; flex-direction: column; }
        .lr-grid-item a { text-decoration: none; color: inherit; display: flex; flex-direction: column; height: 100%; }
        .lr-grid-item img { width: 100%; height: 120px; object-fit: cover; background-color: #f0f0f0; }
        .lr-grid-item .lr-grid-item-content { padding: 10px; flex-grow: 1; display: flex; align-items: center; justify-content: center; }
        .lr-grid-item .lr-grid-item-content h4 { margin: 0; font-size: 1em; }
        .lr-grid-item-skater img { width: 120px; height: 120px; border-radius: 50%; margin: 10px auto 0; }
        @media (max-width: 768px) { .lr-grid { grid-template-columns: 1fr; } }
    </style>';

    // --- City Description ---
    if (!empty($city_details['description'])) { $output .= wpautop(esc_html($city_details['description'])); } 
    else { $output .= '<p>Explore everything the ' . esc_html($city_details['name']) . ' roller skating scene has to offer.</p>'; }
    $output .= '<hr style="margin: 20px 0;">';

    $render_grid_start = '<div class="lr-grid">';
    $render_grid_end = '</div>';
    $render_view_all = function($url, $text) {
        return '<p style="text-align: right; margin-top: 15px;"><a href="' . esc_url($url) . '">' . esc_html($text) . ' &raquo;</a></p>';
    };

    // =================================================================
    // Top Skate Spots
    // =================================================================
    $output .= '<h3>Top Skate Spots</h3>';
    $bounding_box = lr_calculate_bounding_box($city_details['latitude'], $city_details['longitude'], $city_details['radius_km']);
    $spots_list_params = ['ne' => $bounding_box['ne'], 'sw' => $bounding_box['sw'], 'limit' => 1000];
    $spots_list = lr_fetch_api_data($access_token, 'spots/v2/inBox', $spots_list_params);

    if (!is_wp_error($spots_list) && !empty($spots_list)) {
        usort($spots_list, function($a, $b) { return ($b->sessionsCount ?? 0) <=> ($a->sessionsCount ?? 0); });
        $top_spots = array_slice($spots_list, 0, 6);
        $output .= $render_grid_start;
        foreach ($top_spots as $spot) {
            $spot_details = lr_fetch_api_data($access_token, 'spots/' . $spot->_id, []);
            if ($spot_details && isset($spot_details->spotWithAddress)) {
                $spot_name = esc_attr($spot_details->spotWithAddress->name);
                $proxy_url = plugin_dir_url(__FILE__) . '../image-proxy.php';
                $image_url = 'https://placehold.co/400x240/e0e0e0/757575?text=Spot';
                if (!empty($spot_details->spotWithAddress->satelliteAttachment)) {
                    // OPTIMIZATION 1: Request a smaller image size from the proxy.
                    $image_url = add_query_arg(['type' => 'spot_satellite', 'id' => $spot_details->spotWithAddress->satelliteAttachment, 'width' => 400, 'quality' => 75], $proxy_url);
                }
                $spot_url = home_url('/spots/' . $spot->_id . '/');

                $output .= '<div class="lr-grid-item">';
                $output .= '<a href="' . esc_url($spot_url) . '">';
                // OPTIMIZATION 2: Add descriptive alt text.
                $output .= '<img src="' . esc_url($image_url) . '" alt="Satellite view of ' . $spot_name . '" width="200" height="120" />';
                $output .= '<div class="lr-grid-item-content">';
                $output .= '<h4>' . esc_html($spot_details->spotWithAddress->name) . '</h4>';
                $output .= '</div></a>';
                // --- ADDED: Display spot stats using the helper function ---
                $output .= lr_get_spot_stats_html($spot_details);
                $output .= '</div>';
            }
        }
        $output .= $render_grid_end;
        $output .= $render_view_all(home_url('/' . $country_slug . '/' . $city_slug . '/skatespots/'), 'View All Skate Spots');
    } else {
        $output .= '<p>No skate spots found for this location.</p>';
    }
    $output .= '<hr style="margin: 20px 0;">';
    
    // =================================================================
    // Local Skaters
    // =================================================================
    $output .= '<h3>Local Skaters</h3>';
    $skaters_params = ['lat' => $city_details['latitude'], 'lng' => $city_details['longitude'], 'minDistance' => 0, 'maxAgeInDays' => 90, 'limit' => 20];
    $skaters_data = lr_fetch_api_data($access_token, 'nearby-activities/v2/skaters', $skaters_params);

    if (!is_wp_error($skaters_data) && !empty($skaters_data->userProfiles)) {
        $top_skaters = array_slice($skaters_data->userProfiles, 0, 6);
        $output .= $render_grid_start;
        foreach ($top_skaters as $profile) {
            if (!empty($profile->skateName)) {
                $display_name = esc_attr($profile->skateName);
                // OPTIMIZATION 1: Request a smaller, optimized avatar directly.
                $avatar_url = 'https://beta.web.lets-roll.app/api/user/' . $profile->userId . '/avatar/content/processed?width=120&height=120&quality=75';
                $placeholder_url = 'https://placehold.co/120x120/e0e0e0/757575?text=Skater';
                $skater_url = home_url('/skaters/' . $profile->skateName . '/');
                $output .= '<div class="lr-grid-item lr-grid-item-skater">';
                $output .= '<a href="' . esc_url($skater_url) . '">';
                // OPTIMIZATION 2 & 3: Add descriptive alt text and lazy loading.
                $output .= '<img src="' . esc_url($avatar_url) . '" onerror="this.onerror=null;this.src=\'' . esc_url($placeholder_url) . '\';" alt="Avatar for ' . $display_name . '" loading="lazy" width="120" height="120" />';
                $output .= '<div class="lr-grid-item-content">';
                $output .= '<h4>' . esc_html($profile->skateName) . '</h4>';
                $output .= '</div></a></div>';
            }
        }
        $output .= $render_grid_end;
        $output .= $render_view_all(home_url('/' . $country_slug . '/' . $city_slug . '/skaters/'), 'View All Skaters');
    } else {
        $output .= '<p>No skaters found for this location.</p>';
    }
    $output .= '<hr style="margin: 20px 0;">';
    
    // =================================================================
    // Upcoming Events
    // =================================================================
    $output .= '<h3>Upcoming Events</h3>';
    $events_params = ['ne' => $bounding_box['ne'], 'sw' => $bounding_box['sw']];
    $events_data = lr_fetch_api_data($access_token, 'roll-session/event/inBox', $events_params);

    if (!is_wp_error($events_data) && !empty($events_data->rollEvents)) {
        $now = new DateTime();
        $upcoming_events = array_filter($events_data->rollEvents, function($event) use ($now) {
            return isset($event->event->endDate) && (new DateTime($event->event->endDate) > $now);
        });
        usort($upcoming_events, function($a, $b) { return strtotime($a->event->startDate) <=> strtotime($b->event->startDate); });
        $top_events = array_slice($upcoming_events, 0, 6);
        
        if (!empty($top_events)) {
            $output .= $render_grid_start;
            foreach ($top_events as $event) {
                $event_name = esc_attr($event->name);
                $attachments = lr_fetch_api_data($access_token, 'roll-session/' . $event->_id . '/attachments', []);
                $image_url = 'https://placehold.co/400x240/e0e0e0/757575?text=Event';
                if (!is_wp_error($attachments) && !empty($attachments)) {
                    $proxy_url = plugin_dir_url(__FILE__) . '../image-proxy.php';
                    // OPTIMIZATION 1: Request a smaller image size.
                    $image_url = add_query_arg(['type' => 'event_attachment', 'id' => $attachments[0]->_id, 'session_id' => $event->_id, 'width' => 400, 'quality' => 75], $proxy_url);
                }
                
                $event_url = home_url('/events/' . $event->_id . '/');

                $output .= '<div class="lr-grid-item">';
                $output .= '<a href="' . esc_url($event_url) . '">';
                // OPTIMIZATION 2 & 3: Add descriptive alt text and lazy loading.
                $output .= '<img src="' . esc_url($image_url) . '" alt="Image for ' . $event_name . '" loading="lazy" width="200" height="120" />';
                $output .= '<div class="lr-grid-item-content">';
                $output .= '<h4>' . esc_html($event->name) . '</h4>';
                $output .= '</div></a></div>';
            }
            $output .= $render_grid_end;
        } else {
             $output .= '<p>No upcoming events found for this location.</p>';
        }
        $output .= $render_view_all(home_url('/' . $country_slug . '/' . $city_slug . '/events/'), 'View All Events');
    } else {
        $output .= '<p>No events found for this location.</p>';
    }
    
    set_transient($transient_key, $output, 4 * HOUR_IN_SECONDS);

    return $output;
}

