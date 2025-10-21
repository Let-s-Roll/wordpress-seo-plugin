<?php
/**
 * Renders the content for a single event page.
 */
function lr_render_single_event_content($event_id) {
    $data = lr_get_activity_data($event_id);

    if (is_wp_error($data) || empty($data->sessions[0])) {
        return '<p>Could not find details for this event.</p>';
    }

    $event = $data->sessions[0];
    $event->attachments = $data->attachments ?? []; // Ensure attachments are on the event object
    $organizer_profile = $data->userProfiles[0] ?? null;
    $spot_details = $data->spotsBoundToSessions[0] ?? null;

    $output = '<div class="lr-page-container">'; // Start of new wrapper
    $output .= '
    <style>
        @media (max-width: 768px) {
            .lr-page-container { padding-left: 15px; padding-right: 15px; }
        }
    </style>';

    if ($event) {
        $event_name = esc_attr($event->name);
        
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

        if ($spot_details) {
             $spot_url = home_url('/spots/' . $spot_details->_id . '/');
             $spot_name = $spot_details->name;
             $output .= '<p>This event takes place at: <a href="'.esc_url($spot_url).'">' . esc_html($spot_name) . '</a></p>';
        }

        // --- External URL ---
        if (!empty($event->event->url)) {
            $full_url = $event->event->url;
            $display_url = preg_replace('#^https?://(www\.)?#', '', $full_url);
            $output .= '<p><strong>More Info:</strong> <a href="' . esc_url($full_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($display_url) . '</a></p>';
        }

        // --- MODIFIED: Event Image from the aggregate data, no new API call ---
        if (!empty($event->attachments)) {
            $first_attachment = $event->attachments[0];
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

        // --- Description ---
        if (!empty($event->description)) {
            $output .= '<hr style="margin: 20px 0;">';
            $output .= '<h3>About this Event</h3>';
            $output .= '<p>' . nl2br(esc_html($event->description)) . '</p>';
        }

        // --- Organizer ---
        if ($organizer_profile && !empty($organizer_profile->skateName)) {
            $organizer_name = $organizer_profile->skateName;
            $organizer_url = home_url('/skaters/' . $organizer_profile->skateName . '/');
            $output .= '<hr style="margin: 20px 0;">';
            $output .= '<p><strong>Organizer:</strong> <a href="'.esc_url($organizer_url).'">' . esc_html($organizer_name) . '</a></p>';
        }
        
        $output .= '</div>'; // End of new wrapper
        return $output;

    } else {
        $output .= '<p>Could not find details for this event.</p>';
        $output .= '</div>'; // End of new wrapper
        return $output;
    }
}

