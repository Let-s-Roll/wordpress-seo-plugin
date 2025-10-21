<?php
/**
 * Renders the content for a detail page (list of spots, events, skaters).
 */
function lr_render_top_spots_section($city_details, $access_token, $num_spots = 3) {
    $transient_key = 'lr_top_spots_html_' . sanitize_key($city_details['name']) . '_' . $num_spots;
    if (!lr_is_testing_mode_enabled()) {
        $cached_html = get_transient($transient_key);
        if ($cached_html) {
            return $cached_html;
        }
    }

    $bounding_box = lr_calculate_bounding_box($city_details['latitude'], $city_details['longitude'], $city_details['radius_km']);
    $spots_list = lr_fetch_api_data($access_token, 'spots/v2/inBox', ['ne' => $bounding_box['ne'], 'sw' => $bounding_box['sw'], 'limit' => 1000]);

    if (is_wp_error($spots_list) || empty($spots_list)) {
        return '';
    }

    usort($spots_list, function($a, $b) { return ($b->sessionsCount ?? 0) <=> ($a->sessionsCount ?? 0); });
    $top_spots = array_slice($spots_list, 0, $num_spots);

    $output = '<h3>Top ' . $num_spots . ' Most Active Spots</h3>';
    // --- MODIFIED: Use the standard 'lr-grid' class for consistency ---
    $output .= '<div class="lr-grid">';

    foreach ($top_spots as $spot) {
        $spot_details = lr_fetch_api_data($access_token, 'spots/' . $spot->_id, []);
        if ($spot_details && isset($spot_details->spotWithAddress)) {
            $spot_name = esc_attr($spot_details->spotWithAddress->name);
            $spot_url = home_url('/spots/' . $spot->_id . '/');
            $proxy_url = plugin_dir_url(__FILE__) . '../image-proxy.php';
            $image_url = 'https://placehold.co/400x240/e0e0e0/757575?text=Spot';
            if (!empty($spot_details->spotWithAddress->satelliteAttachment)) {
                $image_url = add_query_arg(['type' => 'spot_satellite', 'id' => $spot_details->spotWithAddress->satelliteAttachment, 'width' => 400, 'quality' => 75], $proxy_url);
            }

            // --- MODIFIED: Use the standard 'lr-grid-item' structure ---
            $output .= '<div class="lr-grid-item">';
            $output .= '<a href="' . esc_url($spot_url) . '">';
            $output .= '<img src="' . esc_url($image_url) . '" alt="Satellite view of ' . $spot_name . '" width="200" height="120" />';
            $output .= '<div class="lr-grid-item-content">';
            $output .= '<h4>' . esc_html($spot_details->spotWithAddress->name) . '</h4>';
            $output .= '</div></a>';
            $output .= lr_get_spot_stats_html($spot_details);
            $output .= '</div>';
        }
    }
    $output .= '</div><hr style="margin: 30px 0;">';

    if (!lr_is_testing_mode_enabled()) {
        set_transient($transient_key, $output, 4 * HOUR_IN_SECONDS);
    }

    return $output;
}

function lr_render_detail_page_content($country_slug, $city_slug, $page_type) {
    $city_details = lr_get_city_details($country_slug, $city_slug);
    if (!$city_details) return '<p>Location not found.</p>';

    $access_token = lr_get_api_access_token();
    $output = lr_get_breadcrumbs();

    // --- MOVED: Add Grid Styles at the beginning for universal application ---
    $output .= '
    <style>
        .lr-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-top: 15px; }
        .lr-grid-item { border: 1px solid #eee; border-radius: 5px; overflow: hidden; text-align: center; height: 100%; display: flex; flex-direction: column; }
        .lr-grid-item a { text-decoration: none; color: inherit; display: flex; flex-direction: column; }
        .lr-grid-item img { width: 100%; height: 180px; object-fit: cover; background-color: #f0f0f0; }
        .lr-grid-item .lr-grid-item-content { padding: 10px 10px 0; display: flex; align-items: center; justify-content: center; }
        .lr-grid-item .lr-grid-item-content h4 { margin: 0; font-size: 1em; }
        @media (max-width: 768px) { .lr-grid { grid-template-columns: 1fr; } }
    </style>';

    if ($page_type === 'skatespots') {
        // --- ADDED: Display Top Spots Section ---
        $output .= lr_render_top_spots_section($city_details, $access_token, 3);

        // --- REVISED Logic for Skate Spots with Custom Query Parameter Pagination ---
        $current_page   = isset($_GET['lr_page']) ? max(1, intval($_GET['lr_page'])) : 1;
        $spots_per_page = 10;
        
        $transient_key = 'lr_spots_list_v3_' . sanitize_key($city_slug);
        
        if (lr_is_testing_mode_enabled()) {
            delete_transient($transient_key);
        }
        $all_spot_ids = get_transient($transient_key);

        if (false === $all_spot_ids) {
            $bounding_box = lr_calculate_bounding_box(
                $city_details['latitude'],
                $city_details['longitude'],
                $city_details['radius_km']
            );
            $api_params = ['ne' => $bounding_box['ne'], 'sw' => $bounding_box['sw'], 'limit' => 1000];
            $spots_list = lr_fetch_api_data($access_token, 'spots/v2/inBox', $api_params);
            
            $all_spot_ids = [];
            if (is_array($spots_list) && !empty($spots_list)) {
                usort($spots_list, function($a, $b) {
                    return ($b->sessionsCount ?? 0) <=> ($a->sessionsCount ?? 0);
                });
                foreach ($spots_list as $spot) {
                    if (is_object($spot) && isset($spot->_id)) {
                        $all_spot_ids[] = $spot->_id;
                    }
                }
            }
            set_transient($transient_key, $all_spot_ids, 4 * HOUR_IN_SECONDS);
        }

        if (!empty($all_spot_ids)) {
            $total_spots = count($all_spot_ids);
            $total_pages = ceil($total_spots / $spots_per_page);
            $offset      = ($current_page - 1) * $spots_per_page;
            
            $ids_for_this_page = array_slice($all_spot_ids, $offset, $spots_per_page);

            $output .= '<p>Showing skate spots ' . ($offset + 1) . ' to ' . ($offset + count($ids_for_this_page)) . ' of ' . $total_spots . ' found in the area:</p><ul>';

            foreach ($ids_for_this_page as $spot_id) {
                $spot_details = lr_fetch_api_data($access_token, 'spots/' . $spot_id, []);
                $output .= '<li style="margin-bottom: 1em;">';
                if (!is_wp_error($spot_details) && isset($spot_details->spotWithAddress)) {
                    $spot_info = $spot_details->spotWithAddress;
                    $spot_name = $spot_info->name ?? 'Unnamed Spot';
                    $spot_address = $spot_info->info->address ?? 'No address available';
                    $spot_url = home_url('/spots/' . $spot_id . '/');
                    $output .= '<strong><a href="' . esc_url($spot_url) . '">' . esc_html($spot_name) . '</a></strong><br>';
                    $output .= '<span>' . esc_html($spot_address) . '</span>';
                    
                    // --- UNIFIED STATS: Use the helper function for consistency ---
                    $output .= lr_get_spot_stats_html($spot_details);

                } else {
                    $output .= '<strong>Spot ID:</strong> ' . esc_html($spot_id) . ' - <em style="color:red;">Could not load details.</em>';
                }
                $output .= '</li>';
            }
            $output .= '</ul>';

            // --- Pagination Links ---
            $output .= '<div class="lr-pagination" style="margin-top: 20px;">';
            if ($current_page > 1) {
                $prev_url = add_query_arg('lr_page', $current_page - 1);
                $output .= '<a href="' . esc_url($prev_url) . '">&laquo; Previous Page</a>';
            }
            if ($current_page > 1 && $current_page < $total_pages) {
                $output .= ' &nbsp; | &nbsp; ';
            }
            if ($current_page < $total_pages) {
                $next_url = add_query_arg('lr_page', $current_page + 1);
                $output .= '<a href="' . esc_url($next_url) . '">Next Page &raquo;</a>';
            }
            $output .= '</div>';

        } else {
            $output .= '<p>No skate spots found for this location.</p>';
        }

    } elseif ($page_type === 'events') {
        // --- REVISED Logic for Events ---
        $radius_km = $city_details['radius_km'];

        $bounding_box = lr_calculate_bounding_box(
            $city_details['latitude'],
            $city_details['longitude'],
            $radius_km
        );

        $api_params = [
            'ne'    => $bounding_box['ne'],
            'sw'    => $bounding_box['sw'],
        ];

        $events_data = lr_fetch_api_data($access_token, 'roll-session/event/inBox', $api_params);

        if (is_wp_error($events_data)) {
            $output .= '<p><strong>Error:</strong> ' . esc_html($events_data->get_error_message()) . '</p>';
        } elseif (isset($events_data->rollEvents) && !empty($events_data->rollEvents)) {
            
            $all_events = $events_data->rollEvents;
            $upcoming_events = [];
            $past_events = [];
            $now = new DateTime();

            foreach ($all_events as $event) {
                // Cache the full event object for the single event page to use.
                set_transient('lr_event_data_' . $event->_id, $event, 4 * HOUR_IN_SECONDS);

                if (isset($event->event->endDate)) {
                    $end_date = new DateTime($event->event->endDate);
                    if ($end_date > $now) {
                        $upcoming_events[] = $event;
                    } else {
                        $past_events[] = $event;
                    }
                }
            }

            usort($upcoming_events, function($a, $b) {
                return strtotime($a->event->startDate ?? 0) <=> strtotime($b->event->startDate ?? 0);
            });
            
            usort($past_events, function($a, $b) {
                return strtotime($b->event->startDate ?? 0) <=> strtotime($a->event->startDate ?? 0);
            });

            $render_event = function($event) {
                $event_url = home_url('/events/' . $event->_id . '/');
                $event_name = $event->name;
                $date_str = 'Date TBD';

                if (!empty($event->event->startDate) && !empty($event->event->endDate)) {
                    try {
                        $start = new DateTime($event->event->startDate);
                        $end = new DateTime($event->event->endDate);
                        if ($start->format('Y-m-d') === $end->format('Y-m-d')) {
                             $date_str = $start->format('F j, Y') . ' from ' . $start->format('g:i A') . ' to ' . $end->format('g:i A');
                        } else {
                             $date_str = $start->format('F j, Y, g:i A') . ' to ' . $end->format('F j, Y, g:i A');
                        }
                    } catch (Exception $e) {}
                }
                
                $description_excerpt = wp_trim_words($event->description ?? '', 30, '...');

                $item_html = '<li style="margin-bottom: 1.5em;">';
                $item_html .= '<strong><a href="' . esc_url($event_url) . '">' . esc_html($event_name) . '</a></strong><br>';
                $item_html .= '<small style="color: #555;">' . esc_html($date_str) . '</small><br>';
                $item_html .= '<p style="margin-top: 5px;">' . esc_html($description_excerpt) . '</p>';
                $item_html .= '</li>';
                return $item_html;
            };

            if (!empty($upcoming_events)) {
                $output .= '<h3>Upcoming Events</h3><ul>';
                foreach ($upcoming_events as $event) { $output .= $render_event($event); }
                $output .= '</ul>';
            }

            if (!empty($past_events)) {
                $output .= '<hr style="margin: 20px 0;"><h3>Past Events</h3><ul>';
                foreach ($past_events as $event) { $output .= $render_event($event); }
                $output .= '</ul>';
            }

        } else {
            $output .= '<p>No events found for this location.</p>';
        }
    } elseif ($page_type === 'skaters') {
        // --- REVISED Logic for Skaters with Pagination ---
        $min_distance = isset($_GET['lr_page_dist']) ? floatval($_GET['lr_page_dist']) : 0;

        $api_params = [
            'lat'          => $city_details['latitude'],
            'lng'          => $city_details['longitude'],
            'minDistance'  => $min_distance,
            'maxAgeInDays' => 90,
            'limit'        => 20,
        ];

        $activity_data = lr_fetch_api_data($access_token, 'nearby-activities/v2/skaters', $api_params);

        if (is_wp_error($activity_data)) {
            $output .= '<p><strong>Error:</strong> ' . esc_html($activity_data->get_error_message()) . '</p>';
        } elseif (isset($activity_data->userProfiles) && !empty($activity_data->userProfiles)) {
            
            $output .= '<p>Here are some of the skaters recently active in the area:</p><ul>';
            
            foreach ($activity_data->userProfiles as $profile) {
                // MODIFIED: Use skateName for the URL and ensure it exists.
                if (is_object($profile) && isset($profile->userId) && !empty($profile->skateName)) {
                    $display_name = $profile->skateName;
                    $skater_url = home_url('/skaters/' . $profile->skateName . '/');
                    $output .= '<li><a href="'. esc_url($skater_url) .'">' . esc_html($display_name) . '</a></li>';
                }
            }
            
            $output .= '</ul>';

            $next_min_distance = $activity_data->mostDistantActivity ?? 0;
            $city_radius_meters = $city_details['radius_km'] * 1000;

            if ($next_min_distance > $min_distance && $next_min_distance < $city_radius_meters) {
                $next_page_url = add_query_arg('lr_page_dist', $next_min_distance);
                $output .= '<div class="lr-pagination" style="margin-top: 20px;">';
                $output .= '<a href="' . esc_url($next_page_url) . '">Next Page &raquo;</a>';
                $output .= '</div>';
            }

        } else {
            if ($min_distance > 0) {
                $output .= '<p>No more skaters found in this area. <a href="' . esc_url(remove_query_arg('lr_page_dist')) . '">Back to Start</a></p>';
            } else {
                $output .= '<p>No recent skater activity found in this area.</p>';
            }
        }
    } else {
        $output .= '<p>API integration for ' . esc_html($page_type) . ' is coming soon.</p>';
    }

    return $output;
}

