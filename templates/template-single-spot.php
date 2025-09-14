<?php
/**
 * Renders the content for a single spot page.
 *
 * @param string $spot_id The ID of the spot to display.
 * @return string The HTML content for the page.
 */
function lr_render_single_spot_content($spot_id) {
    $access_token = lr_get_api_access_token();
    if (is_wp_error($access_token)) {
        return '<p><strong>Error:</strong> Could not authenticate with the API.</p>';
    }

    $api_endpoint = 'spots/' . $spot_id;
    $spot_data = lr_fetch_api_data($access_token, $api_endpoint, []);

    if (is_wp_error($spot_data)) {
        return '<p><strong>Error:</strong> ' . esc_html($spot_data->get_error_message()) . '</p>';
    } 
    
    if (isset($spot_data->spotWithAddress)) {
        $spot = $spot_data->spotWithAddress;
        $output = '';

        // --- Satellite Image ---
        if (!empty($spot->satelliteAttachment)) {
            // Updated to use the new proxy format
            $proxy_url = plugin_dir_url( __FILE__ ) . '../image-proxy.php';
            $image_url = add_query_arg([
                'type' => 'spot_satellite',
                'id'   => $spot->satelliteAttachment
            ], $proxy_url);

            // Wrap the image in a div for center alignment.
            $output .= '<div style="text-align: center; margin-bottom: 20px;">';
            $output .= '<img src="' . esc_url($image_url) . '" alt="Satellite view of ' . esc_attr($spot->name) . '" style="max-width: 100%; height: auto;">';
            $output .= '</div>';
        }

        // --- Address ---
        if (!empty($spot->info->address)) {
            $output .= '<p><strong>Address:</strong> ' . esc_html($spot->info->address) . '</p>';
        }

        // --- Google Maps Link ---
        if ( !empty($spot->location->coordinates) ) {
            $lat = $spot->location->coordinates[1];
            $lng = $spot->location->coordinates[0];
            $gmaps_url = 'https://www.google.com/maps/search/?api=1&query=' . $lat . ',' . $lng;
            $output .= '<p><a href="' . esc_url($gmaps_url) . '" target="_blank" rel="noopener noreferrer">Open location in Google Maps</a></p>';
        }

        // --- Stats ---
        $total_skaters = $spot_data->totalSkaters ?? 0;
        $total_sessions = $spot_data->totalSessions ?? 0;
        $output .= '<p><strong>Skaters Visited:</strong> ' . esc_html($total_skaters) . '</p>';
        $output .= '<p><strong>Total Sessions Logged:</strong> ' . esc_html($total_sessions) . '</p>';

        // --- Fetch and Display Ratings and Opinions ---
        $ratings_endpoint = 'spots/' . $spot_id . '/ratings-opinions';
        $ratings_data = lr_fetch_api_data($access_token, $ratings_endpoint, ['limit' => 20, 'skip' => 0]);

        if ($ratings_data && !is_wp_error($ratings_data) && !empty($ratings_data->ratingsAndOpinions)) {
            
            // --- Calculate and Display Average Rating ---
            $total_rating = 0;
            $ratings_count = count($ratings_data->ratingsAndOpinions);
            foreach ($ratings_data->ratingsAndOpinions as $opinion) {
                $total_rating += $opinion->rating;
            }
            $average_rating = $ratings_count > 0 ? round($total_rating / $ratings_count, 1) : 0;
            $stars_html = str_repeat('★', round($average_rating)) . str_repeat('☆', 5 - round($average_rating));
            
            $output .= '<hr style="margin: 20px 0;">';
            $output .= '<h3>Overall Rating: ' . esc_html($average_rating) . ' / 5 (' . $stars_html . ')</h3>';

            // --- Display Individual Reviews ---
            $output .= '<h4>Reviews</h4>';

            $user_profiles = [];
            if (!empty($ratings_data->userProfiles)) {
                foreach ($ratings_data->userProfiles as $profile) {
                    $user_profiles[$profile->userId] = $profile;
                }
            }

            foreach ($ratings_data->ratingsAndOpinions as $opinion) {
                $user = $user_profiles[$opinion->userId] ?? null;
                $output .= '<div style="border: 1px solid #eee; padding: 15px; margin-bottom: 15px; border-radius: 5px; overflow: hidden;">';
                
                // MODIFIED: Use skateName for the URL and ensure it exists.
                if ($user && !empty($user->skateName)) {
                    $avatar_url = 'https://beta.web.lets-roll.app/api/user/' . esc_attr($user->userId) . '/avatar/content/processed?width=50&height=50&quality=70';
                    $skater_url = home_url('/skaters/' . esc_attr($user->skateName) . '/');
                    $display_name = $user->skateName;
                    
                    $output .= '<img src="' . esc_url($avatar_url) . '" alt="Avatar for ' . esc_attr($display_name) . '" style="float: left; border-radius: 50%; width: 50px; height: 50px; margin-right: 15px;">';
                    $output .= '<h5 style="margin: 0 0 5px 0;"><a href="' . esc_url($skater_url) . '">' . esc_html($display_name) . '</a></h5>';
                }
                
                $review_stars = str_repeat('★', $opinion->rating) . str_repeat('☆', 5 - $opinion->rating);
                $output .= '<p style="margin: 0 0 10px 0; color: #666;">Rating: ' . $review_stars . '</p>';
                $output .= '<p style="margin: 0; font-style: italic;">"' . esc_html($opinion->comment) . '"</p>';

                $output .= '</div>';
            }
        }

        return $output;

    } else {
        return '<p>Could not find details for this skate spot.</p>';
    }
}
