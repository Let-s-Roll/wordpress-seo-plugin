<?php

if (!defined('ABSPATH')) exit; // Exit if accessed directly

/**
 * =================================================================================
 * City Updates - Content Aggregation & Publication
 * =================================================================================
 */

// Register a new cron job that runs shortly after the discovery job.
add_action('init', function() {
    if (!wp_next_scheduled('lr_publication_cron')) {
        // Schedule to run daily, but offset from the discovery job.
        wp_schedule_event(time() + (HOUR_IN_SECONDS / 2), 'daily', 'lr_publication_cron');
    }
});

// Hook the main publication function to the cron action
add_action('lr_publication_cron', 'lr_run_content_publication');

/**
 * Main function to orchestrate the content publication process.
 * It finds discovered content and generates "City Update" posts for it.
 */
function lr_run_content_publication() {
    global $wpdb;
    $discovered_table = $wpdb->prefix . 'lr_discovered_content';
    $updates_table = $wpdb->prefix . 'lr_city_updates';

    // Find all cities that have new content discovered in the last 24 hours.
    $one_day_ago = date('Y-m-d H:i:s', strtotime('-1 day'));
    $cities_with_new_content = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT city_slug FROM $discovered_table WHERE discovered_at >= %s",
        $one_day_ago
    ));

    if (empty($cities_with_new_content)) {
        return; // No new content to publish
    }

    foreach ($cities_with_new_content as $city_slug) {
        // In a more robust system, we would queue these as separate jobs.
        // For now, we'll process them sequentially.
        lr_generate_city_update_post($city_slug);
    }
}

/**
 * Generates and saves a "City Update" post for a single city.
 *
 * @param string $city_slug The slug of the city.
 */
function lr_generate_city_update_post($city_slug) {
    global $wpdb;
    $discovered_table = $wpdb->prefix . 'lr_discovered_content';
    $updates_table = $wpdb->prefix . 'lr_city_updates';
    $one_day_ago = date('Y-m-d H:i:s', strtotime('-1 day'));

    // 1. Fetch all new content for this city.
    $new_content_items = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $discovered_table WHERE city_slug = %s AND discovered_at >= %s",
        $city_slug,
        $one_day_ago
    ));

    if (empty($new_content_items)) {
        return; // Should not happen based on the calling function, but a good safeguard.
    }

    // 2. Group content by type.
    $grouped_content = [];
    foreach ($new_content_items as $item) {
        $grouped_content[$item->content_type][] = json_decode($item->data_cache);
    }

    // 3. Generate a simple title and HTML content.
    $city_details = lr_get_city_details_by_slug($city_slug); // Assumes a helper function to get city name
    $city_name = $city_details['name'] ?? ucfirst($city_slug);
    $post_title = $city_name . ' Skate Update: ' . date('F j, Y');
    $post_slug = sanitize_title($post_title);
    $post_content = '<h1>' . esc_html($post_title) . '</h1>';
    $post_content .= '<p>Here are the latest updates from the ' . esc_html($city_name) . ' skate scene.</p>';

    if (!empty($grouped_content['spot'])) {
        $post_content .= '<h2>New Skate Spots</h2><ul>';
        foreach ($grouped_content['spot'] as $spot) {
            $post_content .= '<li><a href="' . home_url('/spots/' . $spot->_id) . '">' . esc_html($spot->name) . '</a></li>';
        }
        $post_content .= '</ul>';
    }
    if (!empty($grouped_content['event'])) {
        $post_content .= '<h2>New Events</h2><ul>';
        foreach ($grouped_content['event'] as $event) {
            $post_content .= '<li><a href="' . home_url('/events/' . $event->_id) . '">' . esc_html($event->name) . '</a></li>';
        }
        $post_content .= '</ul>';
    }
    if (!empty($grouped_content['review'])) {
        $post_content .= '<h2>New Spot Reviews</h2><ul>';
        foreach ($grouped_content['review'] as $review) {
            $post_content .= '<li>A new review for spot ' . esc_html($review->spotId) . ': "' . esc_html($review->comment) . '"</li>';
        }
        $post_content .= '</ul>';
    }
    if (!empty($grouped_content['session'])) {
        $post_content .= '<h2>New Sessions</h2><ul>';
        foreach ($grouped_content['session'] as $session) {
            $post_content .= '<li><a href="' . home_url('/activity/' . $session->sessions[0]->_id) . '">' . esc_html($session->sessions[0]->name) . '</a></li>';
        }
        $post_content .= '</ul>';
    }
    if (!empty($grouped_content['skater'])) {
        $post_content .= '<h2>Newly Seen Skaters</h2><ul>';
        foreach ($grouped_content['skater'] as $skater) {
            $post_content .= '<li><a href="' . home_url('/skaters/' . $skater->skateName) . '">' . esc_html($skater->skateName) . '</a></li>';
        }
        $post_content .= '</ul>';
    }

    // 4. Save the result into the wp_lr_city_updates table.
    $wpdb->insert(
        $updates_table,
        [
            'city_slug'    => $city_slug,
            'post_slug'    => $post_slug,
            'post_title'   => $post_title,
            'post_content' => $post_content,
            'publish_date' => current_time('mysql'),
        ],
        ['%s', '%s', '%s', '%s', '%s']
    );
}

/**
 * Helper function to get city details by slug from the main location data.
 */
function lr_get_city_details_by_slug($city_slug_to_find) {
    $all_locations = lr_get_location_data();
    foreach ($all_locations as $country_data) {
        if (isset($country_data['cities'][$city_slug_to_find])) {
            return $country_data['cities'][$city_slug_to_find];
        }
    }
    return null;
}
