<?php
/**
 * Renders the content for a single event page.
 */
function lr_render_single_event_content($event_id) {
    // --- Caching ---
    $transient_key = 'lr_event_page_html_v3_' . sanitize_key($event_id);
    $cached_html = get_transient($transient_key);
    if ($cached_html) {
        return $cached_html;
    }

    $access_token = lr_get_api_access_token();
    if (is_wp_error($access_token)) { return '<p><strong>Error:</strong> Could not authenticate with the API.</p>'; }

    // --- Data fetching logic (try cache, then fallback to API) ---
    $event = get_transient('lr_event_data_' . $event_id);
    if (false === $event) {
        $lat = $_GET['lat'] ?? null;
        $lng = $_GET['lng'] ?? null;
        if ($lat && $lng) {
            $bounding_box = lr_calculate_bounding_box($lat, $lng, 1);
            $events_data = lr_fetch_api_data($access_token, 'roll-session/event/inBox', ['ne' => $bounding_box['ne'], 'sw' => $bounding_box['sw']]);
            if ($events_data && !is_wp_error($events_data) && !empty($events_data->rollEvents)) {
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
        $event_name = esc_attr($event->name);
        
        // --- RESTORED: Date and Time ---
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

        // --- RESTORED: Address and Spot Link ---
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

        // --- RESTORED: External URL ---
        if (!empty($event->event->url)) {
            $full_url = $event->event->url;
            $display_url = preg_replace('#^https?://(www\.)?#', '', $full_url);
            $output .= '<p><strong>More Info:</strong> <a href="' . esc_url($full_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($display_url) . '</a></p>';
        }

        // --- Event Image (Preserving current optimized version) ---
        $attachments = lr_fetch_api_data($access_token, 'roll-session/' . $event_id . '/attachments', []);
        if (!is_wp_error($attachments) && !empty($attachments)) {
            $first_attachment = $attachments[0];
            $proxy_url = plugin_dir_url(__FILE__) . '../image-proxy.php';
            
            $image_url = add_query_arg([
                'type'       => 'event_attachment',
                'id'         => $first_attachment->_id,
                'session_id' => $event_id,
                'width'      => 800,
                'quality'    => 80
            ], $proxy_url);

            $output .= '<div style="text-align: center; margin-top: 20px; margin-bottom: 20px;">';
            $output .= '<img src="' . esc_url($image_url) . '" alt="Image for ' . $event_name . '" style="max-width: 100%; height: auto; border-radius: 5px;" width="800">';
            $output .= '</div>';
        }

        // --- RESTORED: Description ---
        if (!empty($event->description)) {
            $output .= '<hr style="margin: 20px 0;">';
            $output .= '<h3>About this Event</h3>';
            $output .= '<p>' . nl2br(esc_html($event->description)) . '</p>';
        }

        // --- RESTORED: Organizer ---
        if (!empty($event->userId)) {
            $organizer_profile = lr_fetch_api_data($access_token, 'user/' . $event->userId . '/profile', []);
            if ($organizer_profile && !is_wp_error($organizer_profile) && !empty($organizer_profile->skateName)) {
                $organizer_name = $organizer_profile->skateName;
                $organizer_url = home_url('/skaters/' . $organizer_profile->skateName . '/');
                $output .= '<hr style="margin: 20px 0;">';
                $output .= '<p><strong>Organizer:</strong> <a href="'.esc_url($organizer_url).'">' . esc_html($organizer_name) . '</a></p>';
            }
        }
        
        set_transient($transient_key, $output, 4 * HOUR_IN_SECONDS);
        return $output;

    } else {
        return '<p>Could not find details for this event.</p>';
    }
}