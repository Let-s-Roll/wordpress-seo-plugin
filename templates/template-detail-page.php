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
        // --- Logic for Skate Spots (Unchanged) ---
        $api_params = ['lat' => $city_details['latitude'], 'lng' => $city_details['longitude'], 'limit' => 10];
        $spots_list = lr_fetch_api_data($access_token, 'spots', $api_params);

        if (is_wp_error($spots_list)) {
            $output .= '<p><strong>Error:</strong> ' . esc_html($spots_list->get_error_message()) . '</p>';
        } elseif (is_array($spots_list) && !empty($spots_list)) {
            $output .= '<p>Here are the latest skate spots found in the area:</p><ul>';
            foreach ($spots_list as $item) {
                if (is_object($item) && isset($item->_id)) {
                    $spot_details = lr_fetch_api_data($access_token, 'spots/' . $item->_id, []);
                    $output .= '<li style="margin-bottom: 1em;">';
                    if (!is_wp_error($spot_details) && isset($spot_details->spotWithAddress)) {
                        $spot_info = $spot_details->spotWithAddress;
                        $spot_name = $spot_info->name ?? 'Unnamed Spot';
                        $spot_address = $spot_info->info->address ?? 'No address available';
                        $spot_url = home_url('/spots/' . $item->_id . '/');
                        $output .= '<strong><a href="' . esc_url($spot_url) . '">' . esc_html($spot_name) . '</a></strong><br>';
                        $output .= '<span>' . esc_html($spot_address) . '</span>';
                    } else {
                        $output .= '<strong>Spot ID:</strong> ' . esc_html($item->_id) . ' - <em style="color:red;">Could not load details.</em>';
                    }
                    $output .= '</li>';
                }
            }
            $output .= '</ul>';
        } else {
            $output .= '<p>No skate spots found for this location.</p>';
        }

    } elseif ($page_type === 'events') {
        // --- NEW: Logic for Events ---
        
        // 1. Calculate the bounding box
        $bounding_box = lr_calculate_bounding_box(
            $city_details['latitude'],
            $city_details['longitude'],
            $city_details['radius_km']
        );

        // 2. Prepare the API parameters
        $api_params = [
            'ne'    => $bounding_box['ne'],
            'sw'    => $bounding_box['sw'],
            'limit' => 10,
        ];

        // 3. Fetch the events
        $events_list = lr_fetch_api_data($access_token, 'skate-events/in-box', $api_params);

        // 4. Display the results (or error)
        if (is_wp_error($events_list)) {
            $output .= '<p><strong>Error:</strong> ' . esc_html($events_list->get_error_message()) . '</p>';
        } elseif (is_array($events_list) && !empty($events_list)) {
            $output .= '<p>Here are the latest events happening in the area:</p><ul>';
            foreach ($events_list as $event) {
                // This assumes the event object has a 'name' and '_id'. Adjust if needed.
                if (is_object($event) && isset($event->name) && isset($event->_id)) {
                    $output .= '<li>' . esc_html($event->name) . ' (ID: ' . esc_html($event->_id) . ')</li>';
                }
            }
            $output .= '</ul>';
        } else {
            $output .= '<p>No events found for this location.</p>';
        }

    } else {
        // --- Fallback for Skaters, etc. ---
        $output .= '<p>API integration for ' . esc_html($page_type) . ' is coming soon.</p>';
    }

    return $output;
}
