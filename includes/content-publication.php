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

    lr_log_discovery_message("--- Starting Post Generation for $city_slug ---");

    $new_content_items = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $discovered_table WHERE city_slug = %s AND discovered_at >= %s",
        $city_slug, $one_day_ago
    ));

    if (empty($new_content_items)) {
        lr_log_discovery_message("No new content found. Aborting.");
        return;
    }

    $grouped_content = [];
    foreach ($new_content_items as $item) {
        $grouped_content[$item->content_type][] = json_decode($item->data_cache);
    }

    $city_details = lr_get_city_details_by_slug($city_slug);
    $city_name = $city_details['name'] ?? ucfirst($city_slug);

    // --- AI CONTENT "GLUE" ---
    $ai_snippets = lr_prepare_and_get_ai_content($city_name, $grouped_content);
    if (is_wp_error($ai_snippets)) {
        lr_log_discovery_message("AI Error: " . $ai_snippets->get_error_message() . ". Using fallback.");
        $post_title = $city_name . ' Skate Update: ' . date('F j, Y');
        $post_summary = 'A summary of the latest skate activity in ' . $city_name . '.';
        $ai_snippets = []; // Ensure snippets are empty for fallback
    } else {
        $post_title = $ai_snippets['post_title'];
        $post_summary = $ai_snippets['post_summary'];
    }
    // --- END AI ---

    $post_slug = sanitize_title($post_title);
    
    // --- TEMPLATE RENDERING ---
    $post_content = lr_generate_fallback_post_content($city_name, $grouped_content, $post_title, $ai_snippets);

    $wpdb->replace( $updates_table,
        [ 'city_slug' => $city_slug, 'post_slug' => $post_slug, 'post_title' => $post_title, 'post_summary' => $post_summary, 'post_content' => $post_content, 'publish_date' => current_time('mysql') ],
        ['%s', '%s', '%s', '%s', '%s', '%s']
    );
    lr_log_discovery_message("Successfully saved post for $city_slug.");
}

/**
 * Prepares the data and calls the AI content generation function.
 */
function lr_prepare_and_get_ai_content($city_name, $grouped_content) {
    // Prepare a clean data structure for the AI prompt
    $ai_data = ['city_name' => $city_name];
    if (!empty($grouped_content['spot'])) {
        $ai_data['spots'] = array_map(function($spot) {
            return ['name' => $spot->spotWithAddress->name, 'url' => home_url('/spots/' . $spot->spotWithAddress->_id)];
        }, $grouped_content['spot']);
    }
    if (!empty($grouped_content['event'])) {
        $ai_data['events'] = array_map(function($event) {
            return ['name' => $event->name, 'url' => home_url('/events/' . $event->_id)];
        }, $grouped_content['event']);
    }
    if (!empty($grouped_content['skater'])) {
        $ai_data['skaters'] = array_map(function($skater) {
            return ['name' => $skater->skateName, 'url' => home_url('/skaters/' . $skater->skateName)];
        }, $grouped_content['skater']);
    }
    if (!empty($grouped_content['review'])) {
        $ai_data['reviews'] = array_map(function($review) {
            return ['spot_name' => $review->spot_name, 'rating' => $review->rating, 'comment' => $review->comment, 'url' => home_url('/spots/' . $review->spot_id)];
        }, $grouped_content['review']);
    }

    return lr_get_ai_generated_content($ai_data);
}

/**
 * Generates the post content using the original template-based method as a fallback.
 */
function lr_generate_fallback_post_content($city_name, $grouped_content, $post_title, $ai_snippets = []) {
    $post_content = '<style>
        .lr-update-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 20px; margin-top: 15px; }
        .lr-grid-item, .lr-grid-item-skater, .lr-update-item { border: 1px solid #eee; border-radius: 5px; overflow: hidden; text-align: center; }
    </style>';
    
    // Use the AI-generated summary at the top of the post, and remove the duplicate H1 title.
    if (!empty($ai_snippets['top_summary'])) {
        $post_content .= '<p>' . esc_html($ai_snippets['top_summary']) . '</p>';
    } else {
        $post_content .= '<p>Here are the latest updates from the ' . esc_html($city_name) . ' skate scene.</p>';
    }

    if (!empty($grouped_content['spot'])) {
        $post_content .= '<h2>' . esc_html($ai_snippets['spots_intro'] ?? 'New Skate Spots') . '</h2><div class="lr-update-grid">';
        foreach ($grouped_content['spot'] as $spot) { $post_content .= lr_render_spot_card($spot); }
        $post_content .= '</div>';
    }
    if (!empty($grouped_content['event'])) {
        $post_content .= '<h2>' . esc_html($ai_snippets['events_intro'] ?? 'Upcoming Events') . '</h2><div class="lr-update-grid">';
        foreach ($grouped_content['event'] as $event) { $post_content .= lr_render_event_card($event); }
        $post_content .= '</div>';
    }
    if (!empty($grouped_content['skater'])) {
        $post_content .= '<h2>' . esc_html($ai_snippets['skaters_intro'] ?? 'New Skaters') . '</h2><div class="lr-update-grid">';
        foreach ($grouped_content['skater'] as $skater) { $post_content .= lr_render_skater_card($skater); }
        $post_content .= '</div>';
    }
    if (!empty($grouped_content['review'])) {
        $post_content .= '<h2>' . esc_html($ai_snippets['reviews_intro'] ?? 'New Reviews') . '</h2>';
        foreach ($grouped_content['review'] as $review) { $post_content .= lr_render_review_card($review); }
    }
    if (!empty($grouped_content['session'])) {
        $post_content .= '<h2>Latest Sessions</h2><ul>';
        foreach ($grouped_content['session'] as $session) { $post_content .= lr_render_session_list_item($session); }
        $post_content .= '</ul>';
    }
    return $post_content;
}

/**
 * Runs the historical seeding process for a single city.
 */

/**
 * Runs the historical seeding process for a single city.
 */
function lr_run_historical_seeding_for_city($city_slug) {
    global $wpdb;
    $discovered_table = $wpdb->prefix . 'lr_discovered_content';
    $updates_table = $wpdb->prefix . 'lr_city_updates';

    lr_log_discovery_message("--- Starting Historical Seeding for $city_slug ---");

    $all_content_items = $wpdb->get_results($wpdb->prepare("SELECT * FROM $discovered_table WHERE city_slug = %s", $city_slug));

    if (empty($all_content_items)) {
        lr_log_discovery_message("No content found for $city_slug. Nothing to seed.");
        return;
    }

    // Group content into weekly buckets
    $weekly_buckets = [];
    foreach ($all_content_items as $item) {
        $data = json_decode($item->data_cache);
        $created_at = null;
        switch ($item->content_type) {
            case 'skater': $created_at = $data->lastOnline ?? null; break;
            case 'event': $created_at = $data->event->startDate ?? $data->createdAt ?? null; break;
            case 'spot': $created_at = $data->spotWithAddress->createdAt ?? null; break;
            case 'review': $created_at = $data->createdAt ?? null; break;
            case 'session': $created_at = $data->sessions[0]->createdAt ?? null; break;
        }
        if ($created_at) {
            $week_key = date('o-W', strtotime($created_at));
            $weekly_buckets[$week_key][] = $item;
        }
    }

    // Generate a post for each weekly bucket
    foreach ($weekly_buckets as $week_key => $items) {
        $year = substr($week_key, 0, 4);
        $week = substr($week_key, 5, 2);
        $publish_date = new DateTime();
        $publish_date->setISODate($year, $week, 7);
        $publish_date_str = $publish_date->format('Y-m-d H:i:s');

        $grouped_content = [];
        foreach ($items as $item) {
            $grouped_content[$item->content_type][] = json_decode($item->data_cache);
        }

        $city_details = lr_get_city_details_by_slug($city_slug);
        $city_name = $city_details['name'] ?? ucfirst($city_slug);

        // --- AI CONTENT "GLUE" ---
        $ai_snippets = lr_prepare_and_get_ai_content($city_name, $grouped_content);
        if (is_wp_error($ai_snippets)) {
            lr_log_discovery_message("AI Error for week $week_key: " . $ai_snippets->get_error_message() . ". Using fallback.");
            $post_title = $city_name . ' Skate Update: Week of ' . $publish_date->format('F j, Y');
            $post_summary = 'A summary of skate activity in ' . $city_name . ' for the week of ' . $publish_date->format('F j, Y') . '.';
            $ai_snippets = [];
        } else {
            $post_title = $ai_snippets['post_title'];
            $post_summary = $ai_snippets['post_summary'];
        }
        // --- END AI ---

        $post_slug = sanitize_title($post_title);
        $post_content = lr_generate_fallback_post_content($city_name, $grouped_content, $post_title, $ai_snippets); // Re-use the fallback renderer

        $wpdb->replace(
            $updates_table,
            [
                'city_slug'    => $city_slug,
                'post_slug'    => $post_slug,
                'post_title'   => $post_title,
                'post_summary' => $post_summary,
                'post_content' => $post_content,
                'publish_date' => $publish_date_str,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s']
        );
        lr_log_discovery_message("Generated and saved historical post for week: $week_key");
    }
    lr_log_discovery_message("--- Finished Historical Seeding for $city_slug ---");
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
