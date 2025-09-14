<?php
/**
 * Renders the content for a single skater's profile page.
 */
function lr_render_single_skater_content() {
    // The query variable now contains the username.
    $username = get_query_var('lr_item_id');
    if (!$username) {
        return '<p>No skater username provided.</p>';
    }

    $access_token = lr_get_api_access_token();
    $output = '';

    // MODIFIED: Use the new endpoint with the username.
    $api_endpoint = 'user/profile/' . $username;
    $profile_data = lr_fetch_api_data($access_token, $api_endpoint, []);

    if (is_wp_error($profile_data)) {
        $output .= '<h2>Skater Profile</h2>';
        $output .= '<p><strong>Error:</strong> ' . esc_html($profile_data->get_error_message()) . '</p>';
    } elseif (is_object($profile_data) && isset($profile_data->userId)) {
        
        // Check if the profile is private or blacklisted
        if (isset($profile_data->isPrivate) && $profile_data->isPrivate === true) {
            return '<h2>Private Profile</h2><p>This skater\'s profile is private.</p>';
        }
        if (isset($profile_data->isBlacklisted) && $profile_data->isBlacklisted === true) {
            return '<h2>Profile Unavailable</h2><p>This profile is not available.</p>';
        }

        // Use skateName if available, otherwise fall back to firstName
        $display_name = !empty($profile_data->skateName) ? $profile_data->skateName : (!empty($profile_data->firstName) ? $profile_data->firstName : 'A Let\'s Roll Skater');
        
        // MODIFIED: Construct the Avatar URL using the userId from the API response.
        $user_id_for_avatar = $profile_data->userId;
        $avatar_url = 'https://beta.web.lets-roll.app/api/user/' . $user_id_for_avatar . '/avatar/content/processed?width=150&height=150&quality=80';
        $placeholder_url = 'https://placehold.co/150x150/e0e0e0/757575?text=Skater';

        // --- Centered Header with Image and Skatename ---
        $output .= '<div style="text-align: center; margin-bottom: 20px;">';
        // Add the onerror attribute to fall back to a placeholder if the avatar fails to load.
        $output .= '<img src="' . esc_url($avatar_url) . '" 
                         onerror="this.onerror=null;this.src=\'' . esc_url($placeholder_url) . '\';" 
                         alt="Avatar for ' . esc_attr($display_name) . '" 
                         style="border-radius: 50%; width: 150px; height: 150px; margin-bottom: 10px; background-color: #f0f0f0;">';
        $output .= '<h2>' . esc_html($display_name) . '</h2>';
        $output .= '</div>';

        // --- Add a sub-heading with first name and gender ---
        $sub_heading_parts = [];
        if (!empty($profile_data->firstName)) {
            $sub_heading_parts[] = esc_html($profile_data->firstName);
        }
        if (!empty($profile_data->gender) && $profile_data->gender !== 'unknown') {
            $sub_heading_parts[] = '(' . esc_html($profile_data->gender) . ')';
        }
        if (!empty($sub_heading_parts)) {
            $output .= '<h4>' . implode(' ', $sub_heading_parts) . '</h4>';
        }

        // Display the public bio if it exists
        if (!empty($profile_data->publicBio)) {
            // Use nl2br to convert newlines in the bio to <br> tags for proper display
            $output .= '<p>' . nl2br(esc_html($profile_data->publicBio)) . '</p>';
        } else {
            $output .= '<p>This skater has not written a bio yet.</p>';
        }

        // --- Add social media links ---
        $social_links = '';
        if (!empty($profile_data->instagramUsername)) {
            $social_links .= '<a href="https://www.instagram.com/' . esc_attr($profile_data->instagramUsername) . '" target="_blank" rel="noopener noreferrer">Instagram</a>';
        }
        if (!empty($profile_data->tiktokUsername)) {
            $social_links .= ($social_links ? ' &middot; ' : '') . '<a href="https://www.tiktok.com/@' . esc_attr($profile_data->tiktokUsername) . '" target="_blank" rel="noopener noreferrer">TikTok</a>';
        }
        if ($social_links) {
            $output .= '<p><strong>Follow:</strong> ' . $social_links . '</p>';
        }

        // --- Display Badges (REMOVED) ---
        
        // --- Display Last Online Status ---
        if (!empty($profile_data->lastOnline)) {
            try {
                $date = new DateTime($profile_data->lastOnline);
                $output .= '<p><small><em>Last active: ' . esc_html($date->format('F j, Y')) . '</em></small></p>';
            } catch (Exception $e) {
                // Do nothing if the date format is invalid
            }
        }
        
    } else {
        $output .= '<h2>Skater Profile</h2>';
        $output .= '<p>Could not retrieve profile information for this skater.</p>';
    }

    return $output;
}
