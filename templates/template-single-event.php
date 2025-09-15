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

    // --- (Data fetching logic remains the same) ---
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
        
        // --- (Date, Address, Spot Link, URL logic remains the same) ---

        // --- Event Image ---
        $attachments = lr_fetch_api_data($access_token, 'roll-session/' . $event_id . '/attachments', []);
        if (!is_wp_error($attachments) && !empty($attachments)) {
            $first_attachment = $attachments[0];
            $proxy_url = plugin_dir_url(__FILE__) . '../image-proxy.php';
            
            // OPTIMIZATION: Request a large, high-quality image suitable for main content.
            $image_url = add_query_arg([
                'type'       => 'event_attachment',
                'id'         => $first_attachment->_id,
                'session_id' => $event_id,
                'width'      => 800,
                'quality'    => 80
            ], $proxy_url);

            $output .= '<div style="text-align: center; margin-top: 20px;">';
            $output .= '<img src="' . esc_url($image_url) . '" alt="Image for ' . $event_name . '" style="max-width: 100%; height: auto;" width="800">';
            $output .= '</div>';
        }

        // --- (Description and Organizer logic remains the same) ---
        
        set_transient($transient_key, $output, 4 * HOUR_IN_SECONDS);
        return $output;

    } else {
        return '<p>Could not find details for this event.</p>';
    }
}

