<?php

if (!defined('ABSPATH')) exit; // Exit if accessed directly

/**
 * =================================================================================
 * Reusable Rendering Functions (Template Parts)
 * =================================================================================
 */

/**
 * Renders a standardized HTML card for a single skate spot.
 * Logic extracted from template-city-page.php to ensure pixel-perfect consistency.
 *
 * @param object $spot The full, rich spot object (from the /api/spots/{id} endpoint).
 * @return string The HTML for the spot card.
 */
function lr_render_spot_card($spot) {
    if (empty($spot->spotWithAddress)) {
        return '';
    }

    $spot_details = $spot->spotWithAddress;
    $spot_name = esc_attr($spot_details->name);
    $spot_url = home_url('/spots/' . $spot_details->_id);
    
    $image_url = 'https://placehold.co/400x240/e0e0e0/757575?text=Spot';
    if (!empty($spot_details->satelliteAttachment)) {
        $image_url = plugin_dir_url(__DIR__) . 'image-proxy.php?type=spot_satellite&id=' . $spot_details->satelliteAttachment . '&width=400&quality=75';
    }

    $output = '<div class="lr-grid-item">';
    $output .= '<a href="' . esc_url($spot_url) . '">';
    $output .= '<img src="' . esc_url($image_url) . '" alt="Satellite view of ' . $spot_name . '" loading="lazy" width="400" height="180" />';
    $output .= '<div class="lr-grid-item-content">';
    $output .= '<h4>' . esc_html($spot_details->name) . '</h4>';
    $output .= '</div></a>';
    $output .= lr_get_spot_stats_html($spot);
    $output .= '</div>';

    return $output;
}

/**
 * Renders a standardized HTML card for a single skater.
 * Logic extracted from template-city-page.php to ensure consistency.
 *
 * @param object $profile The skater's user profile object.
 * @return string The HTML for the skater card.
 */
function lr_render_skater_card($profile) {
    if (empty($profile->skateName) || empty($profile->userId)) {
        return '';
    }

    $display_name = esc_attr($profile->skateName);
    $avatar_url = 'https://beta.web.lets-roll.app/api/user/' . $profile->userId . '/avatar/content/processed?width=250&height=250&quality=75';
    $skater_url = home_url('/skaters/' . $profile->skateName . '/');

    $output = '<div class="lr-grid-item lr-grid-item-skater">';
    $output .= '<a href="' . esc_url($skater_url) . '">';
    // Pure CSS fallback: grey circle background will show if image fails to load
    $output .= '<div style="width: 120px; height: 120px; border-radius: 50%; margin: 10px auto 0; background-color: #f0f0f0; display: flex; align-items: center; justify-content: center;">';
    $output .= '<img src="' . esc_url($avatar_url) . '" alt="Avatar for ' . $display_name . '" loading="lazy" width="120" height="120" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%; background-color: transparent;" />';
    $output .= '</div>'; // Close the div with grey background
    $output .= '<div class="lr-grid-item-content">';
    $output .= '<h4>' . esc_html($profile->skateName) . '</h4>';
    $output .= '</div></a></div>';

    return $output;
}

/**
 * Renders a standardized HTML card for a single event.
 */
function lr_render_event_card($event) {
    if (empty($event->_id)) return '';

    $access_token = lr_get_api_access_token();
    if (is_wp_error($access_token)) return '';

    $event_name = esc_attr($event->name ?? 'Skate Event');
    $event_url = home_url('/events/' . $event->_id . '/');
    $image_url = 'https://placehold.co/400x240/e0e0e0/757575?text=Event';

    // Use the attachments that were already enriched during discovery.
    if (!empty($event->attachments[0]->_id)) {
        $image_url = plugin_dir_url(__DIR__) . 'image-proxy.php?type=event_attachment&id=' . $event->attachments[0]->_id . '&session_id=' . $event->_id . '&width=400&quality=75';
    }

    $output = '<div class="lr-grid-item">';
    $output .= '<a href="' . esc_url($event_url) . '">';
    $output .= '<img src="' . esc_url($image_url) . '" alt="Image for ' . $event_name . '" loading="lazy" width="400" height="180" />';
    $output .= '<div class="lr-grid-item-content">';
    $output .= '<h4>' . esc_html($event->name ?? 'Skate Event') . '</h4>';
    $output .= '</div></a></div>';

    return $output;
}

/**
 * Renders a standardized HTML card for a single session.
 */
function lr_render_session_card($session_data) {
    if (empty($session_data->sessions[0]->_id) || empty($session_data->userProfiles[0])) return '';
    
    $session = $session_data->sessions[0];
    $user = $session_data->userProfiles[0];
    
    $skater_name = esc_html($user->skateName ?? 'A Skater');
    $avatar_url = 'https://beta.web.lets-roll.app/api/user/' . $user->userId . '/avatar/content/processed?width=40&height=40&quality=75';
    $skater_url = home_url('/skaters/' . ($user->skateName ?? $user->userId) . '/');
    $activity_url = home_url('/activity/' . $session->_id . '/');

    $output = '<div class="lr-session-card" style="border: 1px solid #eee; border-radius: 5px; padding: 15px; margin-bottom: 15px;">';
    $output .= '<div style="display: flex; align-items: center; margin-bottom: 10px;">';
    $output .= '<img src="' . esc_url($avatar_url) . '" alt="' . esc_attr($skater_name) . '" style="width: 40px; height: 40px; border-radius: 50%; margin-right: 10px;">';
    $output .= '<strong><a href="' . esc_url($skater_url) . '">' . $skater_name . '</a></strong>&nbsp;logged a session:';
    $output .= '</div>';
    $output .= '<div style="padding-left: 50px;">';
    $output .= '<strong><a href="' . esc_url($activity_url) . '">' . esc_html($session->name) . '</a></strong>';
    if (!empty($session->description)) {
        $output .= '<p style="font-style: italic; color: #555; margin-top: 5px;">"' . esc_html(wp_trim_words($session->description, 20, '...')) . '"</p>';
    }
    $output .= '</div></div>';

    return $output;
}

/**
 * Renders a standardized HTML list item for a single review.
 */
function lr_render_review_list_item($review) {
    if (empty($review->spotId) || !isset($review->comment)) return '';
    
    // Note: We don't have the spot name here without another API call.
    // For consistency, we will just link to the spot ID.
    return '<li class="lr-update-list-item">A new review for spot <a href="' . home_url('/spots/' . $review->spotId) . '"><strong>' . esc_html($review->spotId) . '</strong></a>: <em>"' . esc_html($review->comment) . '"</em></li>';
}

/**
 * Renders a standardized HTML list item for a session on a single spot page.
 * This is different from the main session list item because the data structure is simpler.
 */
function lr_render_spot_session_list_item($session, $user_profiles) {
    $user = $user_profiles[$session->userId] ?? null;
    if (!$user) return '';

    $display_name = esc_html($user->skateName ?? $user->firstName ?? 'A skater');
    $skater_url = home_url('/skaters/' . esc_attr($user->skateName ?? $user->userId) . '/');
    $avatar_url = 'https://beta.web.lets-roll.app/api/user/' . esc_attr($user->userId) . '/avatar/content/processed?width=40&height=40&quality=75';
    $activity_url = home_url('/activity/' . esc_attr($session->_id) . '/');

    $output = '<div class="lr-session-item">';
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
    $output .= '</div>';
    
    return $output;
}

/**
 * Renders a rich HTML card for a single review, suitable for update pages.
 *
 * @param object $review The enriched review object, containing spot_name, skate_name, user_id, etc.
 * @return string The HTML for the review card.
 */
function lr_render_review_card($review) {
    if (empty($review->spot_id) || empty($review->user_id)) {
        return '';
    }

    $spot_url = home_url('/spots/' . $review->spot_id . '/');
    $skater_url = home_url('/skaters/' . $review->skate_name . '/');
    $avatar_url = 'https://beta.web.lets-roll.app/api/user/' . $review->user_id . '/avatar/content/processed?width=50&height=50&quality=75';
    
    $stars_html = str_repeat('★', $review->rating) . str_repeat('☆', 5 - $review->rating);

    $output = '<div class="lr-review-card" style="border: 1px solid #eee; border-radius: 5px; padding: 15px; margin-bottom: 20px;">';
    $output .= '<h4 style="margin-top: 0; margin-bottom: 15px; font-size: 1.2em;"><a href="' . esc_url($spot_url) . '">' . esc_html($review->spot_name) . '</a></h4>';
    
    $output .= '<div class="lr-review-box" style="background-color: #f9f9f9; padding: 15px; border-radius: 4px;">';
    $output .= '<div class="lr-review-header" style="display: flex; align-items: center; margin-bottom: 10px;">';
    $output .= '<img src="' . esc_url($avatar_url) . '" alt="' . esc_attr($review->skate_name) . '" style="width: 50px; height: 50px; border-radius: 50%; margin-right: 15px;">';
    $output .= '<div class="lr-review-meta">';
    $output .= '<strong style="font-size: 1.1em;"><a href="' . esc_url($skater_url) . '">' . esc_html($review->skate_name) . '</a></strong>';
    $output .= '<div class="lr-review-stars" style="color: #ffb400;">' . $stars_html . '</div>';
    $output .= '</div></div>'; // .lr-review-meta, .lr-review-header
    
    if (!empty($review->comment)) {
        $output .= '<div class="lr-review-body" style="font-style: italic; color: #333;">';
        $output .= '<p style="margin: 0;">"' . esc_html($review->comment) . '"</p>';
        $output .= '</div>'; // .lr-review-body
    }
    
    $output .= '</div></div>'; // .lr-review-box, .lr-review-card

    return $output;
}

/**
 * Retrieves the single latest city update post for a given city slug.
 *
 * @param string $city_slug The slug of the city.
 * @return object|null The latest city update post object, or null if not found.
 */
function lr_get_latest_city_update($city_slug) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'lr_city_updates';

    $query = $wpdb->prepare(
        "SELECT * FROM $table_name WHERE city_slug = %s ORDER BY publish_date DESC LIMIT 1",
        $city_slug
    );

    return $wpdb->get_row($query);
}

/**
 * Renders a banner featuring the latest city update post on a city page.
 *
 * @param string $country_slug The slug of the country.
 * @param string $city_slug The slug of the city for which to display the latest update.
 * @return string The HTML for the banner, or an empty string if no update is found.
 */
function lr_render_latest_city_update_banner($country_slug, $city_slug) {
    $latest_update = lr_get_latest_city_update($city_slug);

    if (empty($latest_update)) {
        return '';
    }

    // Corrected URLs to include country slug
    $post_url = home_url('/' . esc_attr($country_slug) . '/' . esc_attr($city_slug) . '/updates/' . esc_attr($latest_update->post_slug) . '/');
    $all_updates_url = home_url('/' . esc_attr($country_slug) . '/' . esc_attr($city_slug) . '/updates/');

    $output = '';

    // Add featured image if available
    if (!empty($latest_update->featured_image_url)) {
        $output .= '<div style="margin-top: 30px; margin-bottom: 20px; text-align: center;">';
        $output .= '<a href="' . esc_url($post_url) . '">';
        $output .= '<img src="' . esc_url($latest_update->featured_image_url) . '" alt="' . esc_attr($latest_update->post_title) . '" style="max-width: 100%; height: auto; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" loading="lazy" />';
        $output .= '</a>';
        $output .= '</div>';
    }

    $output .= '<h3 style="margin-top: 20px;">Latest Update: <a href="' . esc_url($post_url) . '">' . esc_html($latest_update->post_title) . '</a></h3>';
    $output .= '<p>' . esc_html($latest_update->post_summary) . '</p>';
    $output .= '<p style="text-align: right; margin-top: 15px;">';
    $output .= '<a href="' . esc_url($post_url) . '">Read More &raquo;</a> | ';
    $output .= '<a href="' . esc_url($all_updates_url) . '">See all updates &raquo;</a>';
    $output .= '</p>';

    return $output;
}

/**
 * Generates and returns the HTML for breadcrumbs based on WordPress query variables.
 * This function is the central source of truth for all breadcrumbs in the plugin.
 *
 * @return string The breadcrumb HTML.
 */
function lr_get_breadcrumbs() {
    global $wpdb;
    $output = '<div class="lr-breadcrumbs"><a href="' . home_url('/explore/') . '">Explore</a>';

    $country_slug = get_query_var('lr_country');
    $city_slug = get_query_var('lr_city');
    $page_type = get_query_var('lr_page_type');
    $update_feed = get_query_var('lr_update_feed');
    $update_post_slug = get_query_var('lr_update_post');

    if ($country_slug) {
        $country_details = lr_get_country_details($country_slug);
        if ($country_details) {
            $output .= ' &raquo; <a href="' . home_url('/' . $country_slug . '/') . '">' . esc_html($country_details['name']) . '</a>';
        }
    }

    if ($city_slug) {
        $city_details = lr_get_city_details($country_slug, $city_slug);
        if ($city_details) {
            $output .= ' &raquo; <a href="' . home_url('/' . $country_slug . '/' . $city_slug . '/') . '">' . esc_html($city_details['name']) . '</a>';
        }
    }

    if ($page_type) {
        $output .= ' &raquo; <span>' . esc_html(ucfirst(str_replace('-', ' ', $page_type))) . '</span>';
    }

    if ($update_feed) {
        $output .= ' &raquo; <span>Updates</span>';
    }

    if ($update_post_slug) {
        $table_name = $wpdb->prefix . 'lr_city_updates';
        $post_title = $wpdb->get_var($wpdb->prepare("SELECT post_title FROM $table_name WHERE post_slug = %s", $update_post_slug));
        if ($post_title) {
            $output .= ' &raquo; <a href="' . home_url('/' . $country_slug . '/' . $city_slug . '/updates/') . '">Updates</a>';
            $output .= ' &raquo; <span>' . esc_html($post_title) . '</span>';
        }
    }

    $output .= '</div>';
    return $output;
}

/**
 * Converts Markdown-style links [text](url) to HTML <a> tags.
 *
 * @param string $text The text possibly containing Markdown links.
 * @return string The text with HTML links.
 */
function lr_convert_markdown_links_to_html($text) {
    return preg_replace_callback('/\\[([^\\]]+)\\]\\(([^)]+)\\)/',
        function ($matches) {
            $link_text = $matches[1];
            $url = esc_url($matches[2]); // Sanitize URL for safety
            return '<a href="' . $url . '">' . esc_html($link_text) . '</a>';
        },
        $text
    );
}

