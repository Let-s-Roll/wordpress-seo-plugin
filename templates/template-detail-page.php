<?php
/**
 * Renders the content for a detail page (list of spots, events, skaters).
 */
function lr_render_detail_page_content($country_slug, $city_slug, $page_type) {
    $city_details = lr_get_city_details($country_slug, $city_slug);
    if (!$city_details) return '<p>Location not found.</p>';

    $access_token = lr_get_api_access_token();
    $output = '';

    if ($page_type === 'skatespots') {
        // --- REVISED Logic for Skate Spots with Custom Query Parameter Pagination ---
        $current_page   = isset($_GET['lr_page']) ? max(1, intval($_GET['lr_page'])) : 1;
        $spots_per_page = 10;
        
        // MODIFIED: Changed the transient key to _v3_ to invalidate the old, unsorted cache.
        $transient_key = 'lr_spots_list_v3_' . sanitize_key($city_slug);
        $all_spot_ids = get_transient($transient_key);

        if (false === $all_spot_ids) {
            // --- CORRECTED API CALL ---
            // Use the bounding box to correctly limit the search area.
            $bounding_box = lr_calculate_bounding_box(
                $city_details['latitude'],
                $city_details['longitude'],
                $city_details['radius_km']
            );

            $api_params = [
                'ne'    => $bounding_box['ne'],
                'sw'    => $bounding_box['sw'],
                'limit' => 1000,
            ];
            // Use the correct 'inBox' endpoint.
            $spots_list = lr_fetch_api_data($access_token, 'spots/v2/inBox', $api_params);
            
            $all_spot_ids = [];
            if (is_array($spots_list) && !empty($spots_list)) {
                
                // --- ADDED: Sort the spots list by sessionsCount before caching ---
                usort($spots_list, function($a, $b) {
                    $count_a = $a->sessionsCount ?? 0;
                    $count_b = $b->sessionsCount ?? 0;
                    // Use the spaceship operator for comparison. This sorts descending (highest first).
                    return $count_b <=> $count_a;
                });

                // Now that the list is sorted, extract the IDs in the new order.
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
        $radius_km = 200; // DEBUG: Temporarily force a large radius for testing.

        $bounding_box = lr_calculate_bounding_box(
            $city_details['latitude'],
            $city_details['longitude'],
            $radius_km
        );

        $api_params = [
            'ne'    => $bounding_box['ne'],
            'sw'    => $bounding_box['sw'],
            'limit' => 10,
        ];

        $events_list = lr_fetch_api_data($access_token, 'skate-events/in-box', $api_params);

        if (is_wp_error($events_list)) {
            $output .= '<p><strong>Error:</strong> ' . esc_html($events_list->get_error_message()) . '</p>';
        } elseif (is_array($events_list) && !empty($events_list)) {
            $output .= '<p>Here are the latest events happening in the area:</p><ul>';
            foreach ($events_list as $event) {
                if (is_object($event) && isset($event->name) && isset($event->_id)) {
                    $output .= '<li>' . esc_html($event->name) . ' (ID: ' . esc_html($event->_id) . ')</li>';
                }
            }
            $output .= '</ul>';
        } else {
            $output .= '<p>No events found for this location.</p>';
        }
    } elseif ($page_type === 'skaters') {
        // --- REVISED Logic for Skaters with Pagination ---
        
        // Get the minimum distance from the URL query string, defaulting to 0 for the first page.
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
                if (is_object($profile) && isset($profile->userId)) {
                    $user_id = $profile->userId;
                    $display_name = !empty($profile->skateName) ? $profile->skateName : (!empty($profile->firstName) ? $profile->firstName : 'Skater ' . $user_id);
                    $skater_url = home_url('/skaters/' . $user_id . '/');
                    $output .= '<li><a href="'. esc_url($skater_url) .'">' . esc_html($display_name) . '</a></li>';
                }
            }
            
            $output .= '</ul>';

            // --- Pagination Link ---
            // The API response includes the distance of the most distant activity found.
            // We can use this as the 'minDistance' for the next page.
            $next_min_distance = $activity_data->mostDistantActivity ?? 0;

            // Only show the next page link if the next skater is further away,
            // but still within the defined radius for the city.
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
        // --- Fallback for unknown types ---
        $output .= '<p>API integration for ' . esc_html($page_type) . ' is coming soon.</p>';
    }

    return $output;
}








