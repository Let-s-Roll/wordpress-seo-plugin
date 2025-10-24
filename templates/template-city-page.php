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
        .lr-grid-item a { text-decoration: none; color: inherit; display: flex; flex-direction: column; }
        .lr-grid-item img { width: 100%; height: 180px; object-fit: cover; background-color: #f0f0f0; }
        .lr-grid-item .lr-grid-item-content { padding: 10px 10px 0; display: flex; align-items: center; justify-content: center; }
        .lr-grid-item .lr-grid-item-content h4 { margin: 0; font-size: 1em; }
        .lr-grid-item-skater img { width: 120px; height: 120px; border-radius: 50%; margin: 10px auto 0; }
        @media (max-width: 768px) { 
            .lr-grid { grid-template-columns: 1fr; } 
        }
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
    $spots_list = lr_get_spots_for_city($city_details);

    if (!is_wp_error($spots_list) && !empty($spots_list)) {
        usort($spots_list, function($a, $b) { return ($b->sessionsCount ?? 0) <=> ($a->sessionsCount ?? 0); });
        $top_spots = array_slice($spots_list, 0, 6);
        $output .= $render_grid_start;
        foreach ($top_spots as $spot) {
            $spot_details = lr_fetch_api_data($access_token, 'spots/' . $spot->_id, []);
            if ($spot_details) {
                $output .= lr_render_spot_card($spot_details);
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
    $skaters_data = lr_fetch_filtered_skaters_for_city($city_details, 90);

    if (!is_wp_error($skaters_data) && !empty($skaters_data)) {
        $top_skaters = array_slice($skaters_data, 0, 6);
        $output .= $render_grid_start;
        foreach ($top_skaters as $profile) {
            $output .= lr_render_skater_card($profile);
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
    $events_data = lr_get_events_for_city($city_details);

    if (!is_wp_error($events_data) && !empty($events_data)) {
        $now = new DateTime();
        $upcoming_events = array_filter($events_data, function($event) use ($now) {
            return isset($event->event->endDate) && (new DateTime($event->event->endDate) > $now);
        });
        usort($upcoming_events, function($a, $b) { return strtotime($a->event->startDate) <=> strtotime($b->event->startDate); });
        $top_events = array_slice($upcoming_events, 0, 6);
        
        if (!empty($top_events)) {
            $output .= $render_grid_start;
            foreach ($top_events as $event) {
            $output .= lr_render_event_card($event);
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

