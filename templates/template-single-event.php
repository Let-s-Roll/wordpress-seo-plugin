<?php
/**
 * Renders the content for a single event page.
 */
function lr_render_single_event_content($event_id) {
    $access_token = lr_get_api_access_token();
    if (is_wp_error($access_token)) {
        return '<p><strong>Error:</strong> Could not authenticate with the API.</p>';
    }

    // --- Step 1: Try to get the event data from the cache first ---
    $event = get_transient('lr_event_data_' . $event_id);

    if (false === $event) {
        // --- Step 2: If cache is a miss, use the coordinates from the URL to fetch it ---
        $lat = $_GET['lat'] ?? null;
        $lng = $_GET['lng'] ?? null;

        if ($lat && $lng) {
            // Make a very precise API call with a 1km box around the event's coordinates.
            $bounding_box = lr_calculate_bounding_box($lat, $lng, 1);
            $api_params = [
                'ne' => $bounding_box['ne'],
                'sw' => $bounding_box['sw']
            ];
            $events_data = lr_fetch_api_data($access_token, 'roll-session/event/inBox', $api_params);

            if ($events_data && !is_wp_error($events_data) && !empty($events_data->rollEvents)) {
                // Find our specific event in the response and cache it.
                foreach($events_data->rollEvents as $event_from_list) {
                    if ($event_from_list->_id === $event_id) {
                        $event = $event_from_list;
                        set_transient('lr_event_data_' . $event_id, $event, 4 * HOUR_IN_SECONDS);
                        break;
                    }
                }
            }
        }
    }

    if ($event) {
        $output = '';
        
        // --- Event Name (REMOVED, as it is now the main page title) ---

        // --- Date and Time ---
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
            } catch (Exception $e) { /* Invalid date format */ }
        }
        $output .= '<p><strong>When:</strong> ' . esc_html($date_str) . '</p>';
        
        // --- Address and Spot Link ---
        $address = 'Location TBD';
        if(!empty($event->event->address)) {
            $address_obj = json_decode($event->event->address);
            if(isset($address_obj->formatted_address)) {
                $address = $address_obj->formatted_address;
            }
        }
        $output .= '<p><strong>Where:</strong> ' . esc_html($address) . '</p>';

        if (!empty($event->spotId)) {
             $spot_details = lr_fetch_api_data($access_token, 'spots/' . $event->spotId, []);
             if($spot_details && !is_wp_error($spot_details) && isset($spot_details->spotWithAddress->name)) {
                $spot_url = home_url('/spots/' . $event->spotId . '/');
                $spot_name = $spot_details->spotWithAddress->name;
                $output .= '<p>This event takes place at: <a href="'.esc_url($spot_url).'">' . esc_html($spot_name) . '</a></p>';
             }
        }
        
        // --- MOVED & REFORMATTED: External URL ---
        if (!empty($event->event->url)) {
            $full_url = $event->event->url;
            $display_url = $full_url;
            // If the URL is long, truncate it for display purposes.
            if (strlen($display_url) > 60) {
                $display_url = substr($display_url, 0, 57) . '...';
            }
            $output .= '<p><strong>Read more:</strong> <a href="' . esc_url($full_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($display_url) . '</a></p>';
        }
        
        // --- NEW: Fetch and display the event image ---
        $attachments = lr_fetch_api_data($access_token, 'roll-session/' . $event_id . '/attachments', []);
        if (!is_wp_error($attachments) && !empty($attachments)) {
            $first_attachment = $attachments[0];
            $proxy_url = plugin_dir_url(__FILE__) . '../image-proxy.php';
            $image_url = add_query_arg([
                'type'       => 'event_attachment',
                'id'         => $first_attachment->_id,
                'session_id' => $event_id
            ], $proxy_url);

            $output .= '<div style="text-align: center; margin-top: 20px;">';
            $output .= '<img src="' . esc_url($image_url) . '" alt="Image for ' . esc_attr($event->name) . '" style="max-width: 100%; height: auto;">';
            $output .= '</div>';
        }


        // --- Description ---
        if (!empty($event->description)) {
            $output .= '<hr style="margin: 20px 0;">';
            $output .= '<h3>About this Event</h3>';
            $output .= '<p>' . nl2br(esc_html($event->description)) . '</p>';
        }

        // --- Organizer and External Link ---
        $output .= '<hr style="margin: 20px 0;">';
        if (!empty($event->userId)) {
            $organizer_profile = lr_fetch_api_data($access_token, 'user/' . $event->userId . '/profile', []);
            if ($organizer_profile && !is_wp_error($organizer_profile)) {
                $organizer_name = $organizer_profile->skateName ?? $organizer_profile->firstName ?? 'the organizer';
                $organizer_url = home_url('/skaters/' . $event->userId . '/');
                $output .= '<p><strong>Organizer:</strong> <a href="'.esc_url($organizer_url).'">' . esc_html($organizer_name) . '</a></p>';
            }
        }
        // External link has been moved higher up.

        return $output;

    } else {
        return '<p>Could not find details for this event.</p>';
    }
}




