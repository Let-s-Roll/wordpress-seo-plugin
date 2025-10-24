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

    // 1. Fetch all new content for this city.
    $new_content_items = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $discovered_table WHERE city_slug = %s AND discovered_at >= %s",
        $city_slug,
        $one_day_ago
    ));

    if (empty($new_content_items)) {
        lr_log_discovery_message("No new content found for $city_slug. Aborting post generation.");
        return;
    }
    lr_log_discovery_message("Found " . count($new_content_items) . " new items to process for $city_slug.");

    // 2. Group content by type.
    $grouped_content = [];
    foreach ($new_content_items as $item) {
        $grouped_content[$item->content_type][] = json_decode($item->data_cache);
    }
    lr_log_discovery_message("Grouped content types found: " . implode(', ', array_keys($grouped_content)));


    // 3. Generate rich HTML content.
    $city_details = lr_get_city_details_by_slug($city_slug);
    $city_name = $city_details['name'] ?? ucfirst($city_slug);
    $post_title = $city_name . ' Skate Update: ' . date('F j, Y');
    $post_slug = sanitize_title($post_title);
    
    $post_content = '<style>
        .lr-update-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 20px; margin-top: 15px; }
        .lr-update-item { border: 1px solid #eee; border-radius: 5px; overflow: hidden; text-align: center; }
        .lr-update-item a { text-decoration: none; color: inherit; }
        .lr-update-item img { width: 100%; height: 180px; object-fit: cover; background-color: #f0f0f0; }
        .lr-update-item h4 { margin: 10px; font-size: 1.1em; }
        .lr-update-item p { font-size: 0.9em; color: #555; margin: 0 10px 10px; }
        .lr-update-list-item { margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid #eee; }
    </style>';
    $post_content .= '<h1>' . esc_html($post_title) . '</h1>';
    $post_content .= '<p>Here are the latest updates from the ' . esc_html($city_name) . ' skate scene.</p>';

    if (!empty($grouped_content['skater'])) {
        $post_content .= '<h2>New Skaters in ' . esc_html($city_name) . ' - Say Hello!</h2><div class="lr-update-grid">';
        foreach ($grouped_content['skater'] as $skater) {
            $post_content .= lr_render_skater_card($skater);
        }
        $post_content .= '</div>';
    }

    if (!empty($grouped_content['spot'])) {
        $post_content .= '<h2>Check out the New Skate Spots in ' . esc_html($city_name) . '</h2><div class="lr-update-grid">';
        foreach ($grouped_content['spot'] as $spot) {
            $post_content .= lr_render_spot_card($spot);
        }
        $post_content .= '</div>';
    }

if (!empty($grouped_content['event'])) {
        $post_content .= '<h2>Events Coming Up</h2><div class="lr-update-grid">';
        foreach ($grouped_content['event'] as $event) {
            $post_content .= lr_render_event_card($event);
        }
        $post_content .= '</div>';
    }
    
    if (!empty($grouped_content['session'])) {
        $post_content .= '<h2>Latest Sessions in ' . esc_html($city_name) . '</h2><ul>';
        foreach ($grouped_content['session'] as $session_data) {
            $post_content .= lr_render_session_list_item($session_data);
        }
        $post_content .= '</ul>';
    }

    if (!empty($grouped_content['review'])) {
        $post_content .= '<h2>New Spot Reviews</h2><ul>';
        foreach ($grouped_content['review'] as $review) {
            $post_content .= lr_render_review_list_item($review);
        }
        $post_content .= '</ul>';
    }

    // 4. Save the result into the wp_lr_city_updates table.
    $wpdb->replace(
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
    lr_log_discovery_message("Successfully generated and saved new post for $city_slug.");
}

/**
 * Runs the historical seeding process for a single city.
 */
function lr_run_historical_seeding_for_city($city_slug) {
    global $wpdb;
    $discovered_table = $wpdb->prefix . 'lr_discovered_content';
    $updates_table = $wpdb->prefix . 'lr_city_updates';

    lr_log_discovery_message("--- Starting Historical Seeding for $city_slug ---");

    // 1. Fetch ALL discovered content for this city.
    $all_content_items = $wpdb->get_results($wpdb->prepare("SELECT * FROM $discovered_table WHERE city_slug = %s", $city_slug));

    if (empty($all_content_items)) {
        lr_log_discovery_message("No content found in the database for $city_slug. Nothing to seed.");
        return;
    }
    lr_log_discovery_message("Found " . count($all_content_items) . " total items to process for seeding.");

    // 2. Group content into weekly buckets based on its original date.
    $weekly_buckets = [];
    foreach ($all_content_items as $item) {
        $data = json_decode($item->data_cache);
        lr_log_discovery_message("--- Seeding Item --- \n" . print_r($data, true)); // Full data log
        $created_at = null;

        switch ($item->content_type) {
            case 'skater':
                $created_at = $data->lastOnline ?? null;
                break;
            case 'event':
                $created_at = $data->event->startDate ?? $data->createdAt ?? null;
                break;
            case 'spot':
                $created_at = $data->spotWithAddress->createdAt ?? null;
                break;
            case 'review':
                $created_at = $data->createdAt ?? null;
                break;
            case 'session':
                $created_at = $data->sessions[0]->createdAt ?? null;
                break;
        }

        if (!$created_at) {
            lr_log_discovery_message("Skipping item due to missing date.");
            continue;
        }
        
        $timestamp = strtotime($created_at);
        $week_key = date('o-W', $timestamp); // ISO-8601 year and week number
        $weekly_buckets[$week_key][] = $item;
    }
    lr_log_discovery_message("Grouped items into " . count($weekly_buckets) . " weekly buckets.");

    // 3. Generate a post for each weekly bucket.
    foreach ($weekly_buckets as $week_key => $items) {
        $year = substr($week_key, 0, 4);
        $week = substr($week_key, 5, 2);
        $publish_date = new DateTime();
        $publish_date->setISODate($year, $week, 7); // Set to the Sunday of that week
        $publish_date_str = $publish_date->format('Y-m-d H:i:s');

        $grouped_content = [];
        foreach ($items as $item) {
            $grouped_content[$item->content_type][] = json_decode($item->data_cache);
        }

        $city_details = lr_get_city_details_by_slug($city_slug);
        $city_name = $city_details['name'] ?? ucfirst($city_slug);
        $post_title = $city_name . ' Skate Update: Week of ' . $publish_date->format('F j, Y');
        $post_slug = sanitize_title($post_title);
        
        $post_content = '<style>
            .lr-update-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 20px; margin-top: 15px; }
            .lr-update-item { border: 1px solid #eee; border-radius: 5px; overflow: hidden; text-align: center; }
            .lr-update-item a { text-decoration: none; color: inherit; }
            .lr-update-item img { width: 100%; height: 180px; object-fit: cover; background-color: #f0f0f0; }
            .lr-update-item h4 { margin: 10px; font-size: 1.1em; }
            .lr-update-item p { font-size: 0.9em; color: #555; margin: 0 10px 10px; }
            .lr-update-list-item { margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid #eee; }
        </style>';
        $post_content .= '<h1>' . esc_html($post_title) . '</h1>';
        $post_content .= '<p>Here is a summary of the discoveries from the ' . esc_html($city_name) . ' skate scene for the week ending ' . $publish_date->format('F j, Y') . '.</p>';

        if (!empty($grouped_content['skater'])) {
            $post_content .= '<h2>New Skaters in ' . esc_html($city_name) . ' - Say Hello!</h2><div class="lr-update-grid">';
            foreach ($grouped_content['skater'] as $skater) {
                if (empty($skater->skateName)) continue;
                $avatar_url = 'https://beta.web.lets-roll.app/api/user/' . $skater->userId . '/avatar/content/processed?width=250&height=250&quality=75';
                $post_content .= '<div class="lr-update-item"><a href="' . home_url('/skaters/' . $skater->skateName) . '">';
                $post_content .= '<img src="' . esc_url($avatar_url) . '" alt="' . esc_attr($skater->skateName) . '">';
                $post_content .= '<h4>' . esc_html($skater->skateName) . '</h4>';
                $post_content .= '</a></div>';
            }
            $post_content .= '</div>';
        }

        if (!empty($grouped_content['spot'])) {
            $post_content .= '<h2>Check out the New Skate Spots in ' . esc_html($city_name) . '</h2><div class="lr-update-grid">';
            foreach ($grouped_content['spot'] as $spot) {
                if (empty($spot->spotWithAddress)) continue;
                $spot_details = $spot->spotWithAddress;
                $image_url = 'https://placehold.co/400x240/e0e0e0/757575?text=Spot';
                if (!empty($spot_details->satelliteAttachment)) {
                    $image_url = plugin_dir_url(__DIR__) . 'image-proxy.php?type=spot_satellite&id=' . $spot_details->satelliteAttachment . '&width=400&quality=75';
                }
                $post_content .= '<div class="lr-update-item"><a href="' . home_url('/spots/' . $spot_details->_id) . '">';
                $post_content .= '<img src="' . esc_url($image_url) . '" alt="' . esc_attr($spot_details->name) . '">';
                $post_content .= '<h4>' . esc_html($spot_details->name) . '</h4>';
                $post_content .= '<p>' . esc_html($spot_details->info->address ?? '') . '</p>';
                $post_content .= '</a>' . lr_get_spot_stats_html($spot) . '</div>';
            }
            $post_content .= '</div>';
        }

        if (!empty($grouped_content['event'])) {
            $post_content .= '<h2>Events Coming Up</h2><ul>';
            foreach ($grouped_content['event'] as $event) {
                if (empty($event->_id)) continue;
                $event_name = $event->name ?? $event->event->name ?? 'Event ' . $event->_id;
                $start_date = !empty($event->event->startDate) ? date('F j, Y', strtotime($event->event->startDate)) : 'Date TBD';
                $post_content .= '<li class="lr-update-list-item"><strong><a href="' . home_url('/events/' . $event->_id) . '">' . esc_html($event_name) . '</a></strong><br><small>' . $start_date . '</small></li>';
            }
            $post_content .= '</ul>';
        }
        
        if (!empty($grouped_content['session'])) {
            $post_content .= '<h2>Latest Sessions in ' . esc_html($city_name) . '</h2><ul>';
            foreach ($grouped_content['session'] as $session_data) {
                // The data is already enriched from the discovery phase, just render it.
                $post_content .= lr_render_session_list_item($session_data);
            }
            $post_content .= '</ul>';
        }

        if (!empty($grouped_content['review'])) {
            $post_content .= '<h2>New Spot Reviews</h2><ul>';
            foreach ($grouped_content['review'] as $review) {
                if (empty($review->spotId) || !isset($review->comment)) continue;
                $post_content .= '<li class="lr-update-list-item">A new review for spot <a href="' . home_url('/spots/' . $review->spotId) . '"><strong>' . esc_html($review->spotId) . '</strong></a>: <em>"' . esc_html($review->comment) . '"</em></li>';
            }
            $post_content .= '</ul>';
        }

        $wpdb->replace(
            $updates_table,
            [
                'city_slug'    => $city_slug,
                'post_slug'    => $post_slug,
                'post_title'   => $post_title,
                'post_content' => $post_content,
                'publish_date' => $publish_date_str,
            ],
            ['%s', '%s', '%s', '%s', '%s']
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
