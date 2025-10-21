<?php
/**
 * Renders the content for a single activity page.
 *
 * @param string $activity_id The ID of the activity to render.
 * @return string The HTML content for the activity page.
 */
function lr_render_single_activity_content($activity_id) {
    // --- Caching ---
    $transient_key = 'lr_activity_page_html_v1_' . sanitize_key($activity_id);
    if (!lr_is_testing_mode_enabled()) {
        $cached_html = get_transient($transient_key);
        if ($cached_html) {
            return $cached_html;
        }
    }

    $data = lr_get_activity_data($activity_id);

    if (is_wp_error($data) || empty($data->sessions[0])) {
        return '<p><strong>Error:</strong> Could not retrieve activity data.</p>';
    }

    $session = $data->sessions[0];
    $user_profile = $data->userProfiles[0] ?? null;
    $spot = $data->spotsBoundToSessions[0] ?? null;

    $output = '<div class="lr-page-container">'; // Start of new wrapper

    // --- ADDED: Style block for mobile padding and slideshow aspect ratio ---
    $output .= '
    <style>
        .lr-activity-attachments amp-carousel {
            aspect-ratio: 3 / 4;
            max-height: 70vh;
        }
        .lr-activity-attachments amp-img img {
            object-fit: contain;
        }
        .lr-activity-meta {
            margin: 20px 0;
            padding: 15px;
            background-color: #f9f9f9;
            border-radius: 5px;
            display: flex;
            justify-content: space-around;
            flex-wrap: wrap;
            gap: 10px;
            text-align: center;
        }
        @media (max-width: 768px) {
            .lr-page-container { padding-left: 15px; padding-right: 15px; }
            .lr-activity-meta {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>';

    // --- Skater Header ---
    if ($user_profile) {
        $display_name = esc_html($user_profile->skateName ?? $user_profile->firstName ?? 'A skater');
        $skater_url = home_url('/skaters/' . esc_attr($user_profile->skateName ?? $user_profile->userId) . '/');
        $avatar_url = 'https://beta.web.lets-roll.app/api/user/' . esc_attr($user_profile->userId) . '/avatar/content/processed?width=60&height=60&quality=80';
        
        $output .= '<div class="lr-activity-skater-header">';
        $output .= '<a href="' . esc_url($skater_url) . '"><img src="' . esc_url($avatar_url) . '" alt="Avatar for ' . esc_attr($display_name) . '" class="lr-activity-avatar" loading="lazy" width="60" height="60"></a>';
        $output .= '<h2><a href="' . esc_url($skater_url) . '">' . $display_name . '</a></h2>';
        $output .= '</div>';
    }

    // --- Session Title & Description ---
    $output .= '<h3>' . esc_html($session->name) . '</h3>';
    if (!empty($session->description)) {
        $output .= '<p class="lr-activity-description">"' . nl2br(esc_html($session->description)) . '"</p>';
    }

    // --- Attachments ---
    if (!empty($data->attachments)) {
        $output .= '<div class="lr-activity-attachments">';
        $output .= '<amp-carousel width="3" height="4" layout="responsive" type="slides" controls>';
        foreach ($data->attachments as $attachment) {
            if (!$attachment->isStaticMap) {
                $proxy_url = plugin_dir_url( __FILE__ ) . '../image-proxy.php';
                $image_url = add_query_arg([
                    'type' => 'event_attachment', // Same type as events
                    'id' => $attachment->_id,
                    'session_id' => $session->_id,
                    'width' => 800,
                    'quality' => 85
                ], $proxy_url);
                $output .= '<amp-img src="' . esc_url($image_url) . '" width="3" height="4" layout="responsive" alt="' . esc_attr($session->name) . '"></amp-img>';
            }
        }
        $output .= '</amp-carousel>';
        $output .= '</div>';
    }
    
    // --- Session Meta ---
    $output .= '<div class="lr-activity-meta">';
    if (isset($session->meta->distance) && $session->meta->distance > 0) {
        $output .= '<span><strong>Distance:</strong> ' . round($session->meta->distance, 2) . ' km</span>';
    }
    if (isset($session->meta->durationSecs) && $session->meta->durationSecs > 0) {
        $duration = gmdate("H:i:s", $session->meta->durationSecs);
        $output .= '<span><strong>Duration:</strong> ' . $duration . '</span>';
    }
    $output .= '<span><strong>Posted:</strong> ' . date('F j, Y', strtotime($session->createdAt)) . '</span>';
    $output .= '</div>';


    // --- Spot Link ---
    if ($spot) {
        $spot_url = home_url('/spots/' . esc_attr($spot->_id) . '/');
        $output .= '<p class="lr-activity-spot-link"><strong>At:</strong> <a href="' . esc_url($spot_url) . '">' . esc_html($spot->name) . '</a></p>';
    }

    $output .= '</div>'; // close lr-page-container

    if (!lr_is_testing_mode_enabled()) {
        set_transient($transient_key, $output, 4 * HOUR_IN_SECONDS);
    }

    return $output;
}
