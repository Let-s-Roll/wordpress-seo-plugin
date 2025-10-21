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
        $output = '<div class="lr-page-container">'; // Start of new wrapper

        // --- ADDED: Style block for mobile padding ---
        $output .= '
        <style>
            .lr-sessions-list .lr-session-item {
                border: 1px solid #eee;
                padding: 15px;
                margin-bottom: 15px;
                border-radius: 5px;
                overflow: hidden;
            }
            .lr-sessions-list .lr-session-header {
                display: flex;
                align-items: center;
                margin-bottom: 10px;
            }
            .lr-sessions-list .lr-session-avatar {
                border-radius: 50%;
                margin-right: 15px;
            }
            @media (max-width: 768px) {
                .lr-page-container { padding-left: 15px; padding-right: 15px; }
            }
        </style>';

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
        
        // --- Events & Recent Activity ---
        $session_data = lr_get_spot_sessions($spot_id);
        if ($session_data && !empty($session_data->sessions)) {
            // --- Pre-populate user profiles from the session data ---
            if (!empty($session_data->userProfiles)) {
                foreach ($session_data->userProfiles as $profile) {
                    $user_profiles[$profile->userId] = $profile;
                }
            }

            // --- Sort sessions into events and rolls ---
            $upcoming_events = [];
            $past_events = [];
            $roll_sessions = [];
            $now = new DateTime();

            foreach ($session_data->sessions as $session) {
                if (isset($session->type) && $session->type === 'Event') {
                    if (isset($session->event) && isset($session->event->startDate)) {
                        try {
                            $event_start = new DateTime($session->event->startDate);
                            if ($event_start > $now) {
                                $upcoming_events[] = $session;
                            } else {
                                $past_events[] = $session;
                            }
                        } catch (Exception $e) {
                            $past_events[] = $session;
                        }
                    } else {
                        $past_events[] = $session;
                    }
                } else {
                    $roll_sessions[] = $session;
                }
            }

            // --- Fallback: Fetch any missing user profiles (should be rare) ---
            $user_ids_to_fetch = [];
            foreach ($session_data->sessions as $session) {
                if (!isset($user_profiles[$session->userId])) {
                    $user_ids_to_fetch[] = $session->userId;
                }
            }
            if (!empty($user_ids_to_fetch)) {
                foreach (array_unique($user_ids_to_fetch) as $user_id) {
                    $profile_data = lr_fetch_api_data($access_token, 'user/profile/' . $user_id, []);
                    if ($profile_data && !is_wp_error($profile_data)) {
                        $user_profiles[$user_id] = $profile_data;
                    }
                }
            }

            // --- Render Upcoming Events ---
            if (!empty($upcoming_events)) {
                $output .= '<hr style="margin: 20px 0;">';
                $output .= '<h4>Upcoming Events</h4>';
                $output .= '<div class="lr-sessions-list">';
                foreach ($upcoming_events as $event) {
                    $user = $user_profiles[$event->userId] ?? null;
                    if (!$user) continue;
                    $display_name = esc_html($user->skateName ?? $user->firstName ?? 'A skater');
                    $skater_url = home_url('/skaters/' . esc_attr($user->skateName ?? $user->userId) . '/');
                    $avatar_url = 'https://beta.web.lets-roll.app/api/user/' . esc_attr($user->userId) . '/avatar/content/processed?width=40&height=40&quality=75';
                    $activity_url = home_url('/activity/' . esc_attr($event->_id) . '/');

                    $output .= '<div class="lr-session-item">';
                                                            $output .= '<div class="lr-session-header">';
                                                            $output .= '<img src="' . esc_url($avatar_url) . '" alt="Avatar for ' . esc_attr($display_name) . '" class="lr-session-avatar" loading="lazy" width="40" height="40">';
                                                                                $output .= '<strong><a href="' . esc_url($skater_url) . '">' . $display_name . '</a></strong>&nbsp;is hosting an event:';
                                                                                $output .= '</div>';
                                                                                $output .= '<div class="lr-session-body">';
                                                                                $output .= '<p class="lr-session-title"><a href="' . esc_url($activity_url) . '">' . esc_html($event->name) . '</a></p>';
                                                                                $event_date = new DateTime($event->event->startDate);
                                                                                $output .= '<p class="lr-session-date">' . $event_date->format('F j, Y') . '</p>';
                                                                                $output .= '</div>';
                                                                                $output .= '</div>';
                                                                            }
                                                                            $output .= '</div>';            }

            // --- Render Past Events ---
            if (!empty($past_events)) {
                $output .= '<hr style="margin: 20px 0;">';
                $output .= '<h4>Past Events</h4>';
                $output .= '<div class="lr-sessions-list">';
                foreach ($past_events as $event) {
                     $user = $user_profiles[$event->userId] ?? null;
                    if (!$user) continue;
                    $display_name = esc_html($user->skateName ?? $user->firstName ?? 'A skater');
                    $skater_url = home_url('/skaters/' . esc_attr($user->skateName ?? $user->userId) . '/');
                    $avatar_url = 'https://beta.web.lets-roll.app/api/user/' . esc_attr($user->userId) . '/avatar/content/processed?width=40&height=40&quality=75';
                    $activity_url = home_url('/activity/' . esc_attr($event->_id) . '/');

                    $output .= '<div class="lr-session-item">';
                                                            $output .= '<div class="lr-session-header">';
                                                            $output .= '<img src="' . esc_url($avatar_url) . '" alt="Avatar for ' . esc_attr($display_name) . '" class="lr-session-avatar" loading="lazy" width="40" height="40">';
                                                                                $output .= '<strong><a href="' . esc_url($skater_url) . '">' . $display_name . '</a></strong>&nbsp;hosted an event:';
                                                                                $output .= '</div>';
                                                                                $output .= '<div class="lr-session-body">';
                                                                                $output .= '<p class="lr-session-title"><a href="' . esc_url($activity_url) . '">' . esc_html($event->name) . '</a></p>';
                                                                                $event_date = new DateTime($event->event->startDate);
                                                                                $output .= '<p class="lr-session-date">' . $event_date->format('F j, Y') . '</p>';
                                                                                $output .= '</div>';
                                                                                $output .= '</div>';
                                                                            }
                                                                            $output .= '</div>';            }

            // --- Render Recent Roll Sessions ---
            if (!empty($roll_sessions)) {
                $output .= '<hr style="margin: 20px 0;">';
                $output .= '<h4>Recent Roll Sessions</h4>';
                $output .= '<div class="lr-sessions-list">';
                foreach ($roll_sessions as $session) {
                    $user = $user_profiles[$session->userId] ?? null;
                    if (!$user) continue;
                    $display_name = esc_html($user->skateName ?? $user->firstName ?? 'A skater');
                    $skater_url = home_url('/skaters/' . esc_attr($user->skateName ?? $user->userId) . '/');
                    $avatar_url = 'https://beta.web.lets-roll.app/api/user/' . esc_attr($user->userId) . '/avatar/content/processed?width=40&height=40&quality=75';
                    $activity_url = home_url('/activity/' . esc_attr($session->_id) . '/');

                    $output .= '<div class="lr-session-item">';
                                                            $output .= '<div class="lr-session-header">';
                                                            $output .= '<img src="' . esc_url($avatar_url) . '" alt="Avatar for ' . esc_attr($display_name) . '" class="lr-session-avatar" loading="lazy" width="40" height="40">';
                                                                                $output .= '<strong><a href="' . esc_url($skater_url) . '">' . $display_name . '</a></strong>&nbsp;logged a session:';
                                                                                $output .= '</div>';
                                                                                $output .= '<div class="lr-session-body">';
                                                                                $output .= '<p class="lr-session-title"><a href="' . esc_url($activity_url) . '">' . esc_html($session->name) . '</a></p>';
                                                                                $session_date = new DateTime($session->createdAt);
                                                                                $output .= '<p class="lr-session-date">' . $session_date->format('F j, Y') . '</p>';
                                                                                if (!empty($session->description)) {
                                                                                    $output .= '<p class="lr-session-description">"' . esc_html($session->description) . '"</p>';
                                                                                }
                                                                                $output .= '</div>';
                                                                                $output .= '</div>';                }
                $output .= '</div>';
            }
        }
        $output .= '</div>'; // End of new wrapper
        if (!lr_is_testing_mode_enabled()) {
            set_transient($transient_key, $output, 4 * HOUR_IN_SECONDS);
        }
        return $output;
    } else {
        return '<p>Could not find details for this skate spot.</p>';
    }
}
