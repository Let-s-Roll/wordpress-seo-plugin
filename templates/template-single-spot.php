<?php
/**
 * Renders the content for a single spot page.
 */
function lr_render_single_spot_content($spot_id) {
    // --- Caching ---
    $transient_key = 'lr_spot_page_html_v3_' . sanitize_key($spot_id);
    if (!lr_is_testing_mode_enabled()) {
        $cached_html = get_transient($transient_key);
        if ($cached_html) {
            return $cached_html;
        }
    }

    $access_token = lr_get_api_access_token();
    if (is_wp_error($access_token)) { return '<p><strong>Error:</strong> Could not authenticate with the API.</p>'; }

    $spot_data = lr_fetch_api_data($access_token, 'spots/' . $spot_id, []);
    if (is_wp_error($spot_data)) { return '<p><strong>Error:</strong> ' . esc_html($spot_data->get_error_message()) . '</p>'; } 
    
    if (isset($spot_data->spotWithAddress)) {
        $spot = $spot_data->spotWithAddress;
        $spot_name = esc_attr($spot->name);
        $output = '';

        // --- Satellite Image ---
        if (!empty($spot->satelliteAttachment)) {
            $proxy_url = plugin_dir_url( __FILE__ ) . '../image-proxy.php';
            // OPTIMIZATION: Request a large, high-quality image for the main content area.
            $image_url = add_query_arg(['type' => 'spot_satellite', 'id' => $spot->satelliteAttachment, 'width' => 800, 'quality' => 80], $proxy_url);
            $output .= '<div style="text-align: center; margin-bottom: 20px;">';
            $output .= '<img src="' . esc_url($image_url) . '" alt="Satellite view of ' . $spot_name . '" style="max-width: 100%; height: auto;" width="800" />';
            $output .= '</div>';
        }

        // --- (Address, Maps Link, Stats remain the same) ---
        if (!empty($spot->info->address)) { $output .= '<p><strong>Address:</strong> ' . esc_html($spot->info->address) . '</p>'; }
        if (!empty($spot->location->coordinates)) {
            $gmaps_url = 'https://www.google.com/maps/search/?api=1&query=' . $spot->location->coordinates[1] . ',' . $spot->location->coordinates[0];
            $output .= '<p><a href="' . esc_url($gmaps_url) . '" target="_blank" rel="noopener noreferrer">Open location in Google Maps</a></p>';
        }
        $output .= '<p><strong>Skaters Visited:</strong> ' . ($spot_data->totalSkaters ?? 0) . '</p>';
        $output .= '<p><strong>Total Sessions Logged:</strong> ' . ($spot_data->totalSessions ?? 0) . '</p>';

        // --- Ratings and Opinions ---
        $ratings_data = lr_fetch_api_data($access_token, 'spots/' . $spot_id . '/ratings-opinions', ['limit' => 20, 'skip' => 0]);
        if ($ratings_data && !is_wp_error($ratings_data) && !empty($ratings_data->ratingsAndOpinions)) {
            // ... (average rating calculation logic) ...
            $output .= '<hr style="margin: 20px 0;">';
            // ... (display average rating) ...
            $output .= '<h4>Reviews</h4>';

            $user_profiles = [];
            if (!empty($ratings_data->userProfiles)) {
                foreach ($ratings_data->userProfiles as $profile) { $user_profiles[$profile->userId] = $profile; }
            }

            foreach ($ratings_data->ratingsAndOpinions as $opinion) {
                $user = $user_profiles[$opinion->userId] ?? null;
                $output .= '<div style="border: 1px solid #eee; padding: 15px; margin-bottom: 15px; border-radius: 5px; overflow: hidden;">';
                if ($user && !empty($user->skateName)) {
                    $display_name = esc_attr($user->skateName);
                    // OPTIMIZATION: Request a small 50x50 avatar for reviews.
                    $avatar_url = 'https://beta.web.lets-roll.app/api/user/' . esc_attr($user->userId) . '/avatar/content/processed?width=50&height=50&quality=75';
                    $skater_url = home_url('/skaters/' . esc_attr($user->skateName) . '/');
                    // OPTIMIZATION: Add lazy loading for review images.
                    $output .= '<img src="' . esc_url($avatar_url) . '" alt="Avatar for ' . $display_name . '" style="float: left; border-radius: 50%; width: 50px; height: 50px; margin-right: 15px;" loading="lazy" width="50" height="50">';
                    $output .= '<h5 style="margin: 0 0 5px 0;"><a href="' . esc_url($skater_url) . '">' . esc_html($display_name) . '</a></h5>';
                }
                $review_stars = str_repeat('★', $opinion->rating) . str_repeat('☆', 5 - $opinion->rating);
                $output .= '<p style="margin: 0 0 10px 0; color: #666;">Rating: ' . $review_stars . '</p>';
                $output .= '<p style="margin: 0; font-style: italic;">"' . esc_html($opinion->comment) . '"</p>';
                $output .= '</div>';
            }
        }
        
        if (!lr_is_testing_mode_enabled()) {
            set_transient($transient_key, $output, 4 * HOUR_IN_SECONDS);
        }
        return $output;
    } else {
        return '<p>Could not find details for this skate spot.</p>';
    }
}
