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
 * Main function to orchestrate the ongoing content publication process.
 * This function is designed to run daily. It finds all unpublished content,
 * groups it into completed time buckets (weekly or monthly), and generates
 * a post for each bucket that is ready.
 */
function lr_run_content_publication() {
    global $wpdb;
    $discovered_table = $wpdb->prefix . 'lr_discovered_content';
    $updates_table = $wpdb->prefix . 'lr_city_updates';
    $options = get_option('lr_options');
    $frequency = $options['update_frequency'] ?? 'weekly';

    // 1. Fetch all unpublished recap content and all future events.
    $unpublished_recap = $wpdb->get_results("SELECT * FROM $discovered_table WHERE is_published = 0 AND content_type != 'event'");
    $future_events = $wpdb->get_results($wpdb->prepare("SELECT * FROM $discovered_table WHERE content_type = 'event' AND discovered_at >= %s", date('Y-m-d H:i:s', strtotime('-6 months'))));
    
    $all_content_items = array_merge($unpublished_recap, $future_events);

    if (empty($all_content_items)) {
        lr_log_discovery_message("No new content to publish.");
        return;
    }

    // 2. Group content into buckets using the same recap/preview logic as the seeder.
    $recap_buckets = [];
    $preview_buckets = [];

    foreach ($all_content_items as $item) {
        $data = json_decode($item->data_cache);
        $created_at = null;

        if ($item->content_type === 'event') {
            $created_at = $data->event->startDate ?? $data->createdAt ?? null;
            if ($created_at) {
                if ($frequency === 'monthly') {
                    $key = date('Y-m', strtotime($created_at . ' -1 month'));
                } else {
                    $key = date('o-W', strtotime($created_at . ' -1 week'));
                }
                $preview_buckets[$key][] = $item;
            }
        } else {
            switch ($item->content_type) {
                case 'skater': $created_at = $data->lastOnline ?? null; break;
                case 'spot': $created_at = $data->spotWithAddress->createdAt ?? null; break;
                case 'review': $created_at = $data->createdAt ?? null; break;
                case 'session': $created_at = $data->sessions[0]->createdAt ?? null; break;
            }
            if ($created_at) {
                $key = ($frequency === 'monthly') ? date('Y-m', strtotime($created_at)) : date('o-W', strtotime($created_at));
                $recap_buckets[$key][] = $item;
            }
        }
    }

    $buckets = $recap_buckets;
    foreach ($preview_buckets as $key => $items) {
        if (!isset($buckets[$key])) $buckets[$key] = [];
        $buckets[$key] = array_merge($buckets[$key], $items);
    }

    if (empty($buckets)) {
        lr_log_discovery_message("No completed buckets to publish.");
        return;
    }

    // 3. Identify which buckets are "complete" and need to be published.
    $current_monthly_key = date('Y-m');
    $current_weekly_key = date('o-W');

    foreach ($buckets as $key => $items) {
        // A bucket is "complete" if its key is from a past time period.
        $is_complete = ($frequency === 'monthly' && $key < $current_monthly_key) || ($frequency === 'weekly' && $key < $current_weekly_key);

        if ($is_complete) {
            // Since items can be from different cities, we need to group them by city within the bucket.
            $cities_in_bucket = [];
            foreach ($items as $item) {
                $cities_in_bucket[$item->city_slug][] = $item;
            }

            foreach ($cities_in_bucket as $city_slug => $city_items) {
                lr_generate_city_update_post($city_slug, $city_items, $key, $frequency);
            }
        }
    }
}

/**
 * Generates and saves a "City Update" post for a single city from a given set of items.
 *
 * @param string $city_slug The slug of the city.
 * @param array $items The content items to include in the post.
 * @param string $key The bucket key (e.g., '2025-10' or '2025-42').
 * @param string $frequency The publishing frequency ('weekly' or 'monthly').
 */
function lr_generate_city_update_post($city_slug, $items, $key, $frequency) {
    global $wpdb;
    $discovered_table = $wpdb->prefix . 'lr_discovered_content';
    $updates_table = $wpdb->prefix . 'lr_city_updates';

    lr_log_discovery_message("--- Starting Post Generation for $city_slug (Bucket: $key) ---");

    if (empty($items)) {
        lr_log_discovery_message("No items provided for post generation. Aborting.");
        return;
    }

    $grouped_content = [];
    foreach ($items as $item) {
        $grouped_content[$item->content_type][] = json_decode($item->data_cache);
    }

    $city_details = lr_get_city_details_by_slug($city_slug);
    $city_name = $city_details['name'] ?? ucfirst($city_slug);

    // Determine the publish date based on the bucket key
    if ($frequency === 'monthly') {
        $publish_date = new DateTime($key . '-01');
        $publish_date_str = $publish_date->format('Y-m-t 23:59:59');
    } else {
        $year = substr($key, 0, 4);
        $week = substr($key, 5, 2);
        $publish_date = new DateTime();
        $publish_date->setISODate($year, $week, 7);
        $publish_date_str = $publish_date->format('Y-m-d H:i:s');
    }

    $ai_snippets = lr_prepare_and_get_ai_content($city_name, $grouped_content, $publish_date_str);
    if (is_wp_error($ai_snippets)) {
        lr_log_discovery_message("AI Error for bucket $key: " . $ai_snippets->get_error_message() . ". Using fallback.");
        $title_date = ($frequency === 'monthly') ? $publish_date->format('F Y') : 'Week of ' . $publish_date->format('F j, Y');
        $post_title = $city_name . ' Skate Update: ' . $title_date;
        $post_summary = 'A summary of skate activity in ' . $city_name . ' for ' . $title_date . '.';
        $ai_snippets = [];
    } else {
        $post_title = $ai_snippets['post_title'];
        $post_summary = $ai_snippets['post_summary'];
    }

    $post_slug = sanitize_title($post_title) . '-' . $key;
    $featured_image_url = lr_select_featured_image($grouped_content);
    $post_content = lr_generate_fallback_post_content($city_name, $grouped_content, $post_title, $ai_snippets);

    $wpdb->replace(
        $updates_table,
        [
            'city_slug'    => $city_slug,
            'post_slug'    => $post_slug,
            'post_title'   => $post_title,
            'post_summary' => $post_summary,
            'featured_image_url' => $featured_image_url,
            'post_content' => $post_content,
            'publish_date' => $publish_date_str,
        ],
        ['%s', '%s', '%s', '%s', '%s', '%s', '%s']
    );

    // After saving the post, mark the recap content as published.
    $content_ids_to_mark = [];
    foreach ($items as $item) {
        if ($item->content_type !== 'event') {
            $content_ids_to_mark[] = $item->id;
        }
    }

    if (!empty($content_ids_to_mark)) {
        $ids_placeholder = implode(',', array_fill(0, count($content_ids_to_mark), '%d'));
        $wpdb->query($wpdb->prepare("UPDATE $discovered_table SET is_published = 1 WHERE id IN ($ids_placeholder)", $content_ids_to_mark));
    }

    lr_log_discovery_message("Successfully generated and saved new post for $city_slug (Bucket: $key).");
}

/**
 * Prepares a clean data structure from discovered content and calls the AI generation function.
 *
 * @param string $city_name The name of the city.
 * @param array $grouped_content The content grouped by type.
 * @param string $publication_date The date to be used for time context.
 * @return array|WP_Error An array of AI-generated snippets or a WP_Error on failure.
 */
function lr_prepare_and_get_ai_content($city_name, $grouped_content, $publication_date) {
    // Prepare a clean data structure for the AI prompt
    $ai_data = [
        'city_name' => $city_name,
        'publication_date' => $publication_date,
    ];
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
    if (!empty($grouped_content['session'])) {
        $ai_data['sessions'] = array_map(function($session) {
            return ['name' => $session->sessions[0]->name, 'url' => home_url('/activity/' . $session->sessions[0]->_id)];
        }, $grouped_content['session']);
    }

    return lr_get_ai_generated_content($ai_data);
}

/**
 * Generates the post content using a template-based method. Acts as a fallback when AI fails.
 *
 * @param string $city_name The name of the city.
 * @param array $grouped_content The content grouped by type.
 * @param string $post_title The title of the post.
 * @param array $ai_snippets (Optional) The AI-generated snippets to inject.
 * @return string The full HTML content of the post.
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
        $post_content .= '<h2>' . esc_html($ai_snippets['spots_section']['heading'] ?? 'New Skate Spots') . '</h2>';
        if (!empty($ai_snippets['spots_section']['intro'])) {
            $post_content .= '<p>' . esc_html($ai_snippets['spots_section']['intro']) . '</p>';
        }
        $post_content .= '<div class="lr-update-grid">';
        foreach ($grouped_content['spot'] as $spot) { $post_content .= lr_render_spot_card($spot); }
        $post_content .= '</div>';
    }
    if (!empty($grouped_content['event'])) {
        $post_content .= '<h2>' . esc_html($ai_snippets['events_section']['heading'] ?? 'Upcoming Events') . '</h2>';
        if (!empty($ai_snippets['events_section']['intro'])) {
            $post_content .= '<p>' . esc_html($ai_snippets['events_section']['intro']) . '</p>';
        }
        $post_content .= '<div class="lr-update-grid">';
        foreach ($grouped_content['event'] as $event) { $post_content .= lr_render_event_card($event); }
        $post_content .= '</div>';
    }
    if (!empty($grouped_content['skater'])) {
        $post_content .= '<h2>' . esc_html($ai_snippets['skaters_section']['heading'] ?? 'New Skaters') . '</h2>';
        if (!empty($ai_snippets['skaters_section']['intro'])) {
            $post_content .= '<p>' . esc_html($ai_snippets['skaters_section']['intro']) . '</p>';
        }
        $post_content .= '<div class="lr-update-grid">';
        foreach ($grouped_content['skater'] as $skater) { $post_content .= lr_render_skater_card($skater); }
        $post_content .= '</div>';
    }
    if (!empty($grouped_content['review'])) {
        $post_content .= '<h2>' . esc_html($ai_snippets['reviews_section']['heading'] ?? 'New Reviews') . '</h2>';
        if (!empty($ai_snippets['reviews_section']['intro'])) {
            $post_content .= '<p>' . esc_html($ai_snippets['reviews_section']['intro']) . '</p>';
        }
        foreach ($grouped_content['review'] as $review) { $post_content .= lr_render_review_card($review); }
    }
    if (!empty($grouped_content['session'])) {
        $post_content .= '<h2>' . esc_html($ai_snippets['sessions_section']['heading'] ?? 'Latest Sessions') . '</h2>';
        if (!empty($ai_snippets['sessions_section']['intro'])) {
            $post_content .= '<p>' . esc_html($ai_snippets['sessions_section']['intro']) . '</p>';
        }
        foreach ($grouped_content['session'] as $session) { $post_content .= lr_render_session_card($session); }
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
    $options = get_option('lr_options');
    $frequency = $options['update_frequency'] ?? 'weekly';

    lr_log_discovery_message("--- Starting Historical Seeding for $city_slug (Frequency: $frequency) ---");

    // 1. Fetch ALL content for the city. The 6-month filter will be applied in PHP.
    $all_content_items = $wpdb->get_results($wpdb->prepare("SELECT * FROM $discovered_table WHERE city_slug = %s", $city_slug));

    if (empty($all_content_items)) {
        lr_log_discovery_message("No content found for $city_slug. Nothing to seed.");
        return;
    }

    // 2. Group content into buckets using a two-step process for recaps and previews.
    // This is the core logic for ensuring posts are timely and relevant.
    // Step A: All "recap" content (spots, reviews, skaters, sessions) is placed into a bucket for the month it was created (e.g., a spot from September goes into the "2025-09" bucket).
    // Step B: All "preview" content (events) is placed into a bucket for the *previous* month (e.g., an event happening in October goes into the "2025-09" bucket).
    // This ensures that the post generated at the end of September serves as a preview for October's events, while recapping September's discoveries.
    $recap_buckets = [];
    $preview_buckets = []; // For events
    $six_months_ago_ts = strtotime('-6 months');

    foreach ($all_content_items as $item) {
        $data = json_decode($item->data_cache);
        $created_at = null;

        if ($item->content_type === 'event') {
            $created_at = $data->event->startDate ?? $data->createdAt ?? null;
            if ($created_at && strtotime($created_at) >= $six_months_ago_ts) {
                if ($frequency === 'monthly') {
                    $key = date('Y-m', strtotime($created_at . ' -1 month'));
                } else { // weekly
                    $key = date('o-W', strtotime($created_at . ' -1 week'));
                }
                $preview_buckets[$key][] = $item;
            }
        } else {
            // Standard handling for all other content types (recap)
            switch ($item->content_type) {
                case 'skater': $created_at = $data->lastOnline ?? null; break;
                case 'spot': $created_at = $data->spotWithAddress->createdAt ?? null; break;
                case 'review': $created_at = $data->createdAt ?? null; break;
                case 'session': $created_at = $data->sessions[0]->createdAt ?? null; break;
            }
            
            if ($created_at && strtotime($created_at) >= $six_months_ago_ts) {
                $key = ($frequency === 'monthly') ? date('Y-m', strtotime($created_at)) : date('o-W', strtotime($created_at));
                $recap_buckets[$key][] = $item;
            }
        }
    }

    // Merge the recap and preview buckets to create the final list.
    $buckets = $recap_buckets;
    foreach ($preview_buckets as $key => $items) {
        if (!isset($buckets[$key])) {
            $buckets[$key] = [];
        }
        $buckets[$key] = array_merge($buckets[$key], $items);
    }

    if (empty($buckets)) {
        lr_log_discovery_message("No content found in the last 6 months for $city_slug. Nothing to seed.");
        return;
    }

    // 3. Generate a post for each bucket.
    foreach ($buckets as $key => $items) {
        if ($frequency === 'monthly') {
            $publish_date = new DateTime($key . '-01');
            $publish_date_str = $publish_date->format('Y-m-t 23:59:59'); // End of the month
        } else {
            $year = substr($key, 0, 4);
            $week = substr($key, 5, 2);
            $publish_date = new DateTime();
            $publish_date->setISODate($year, $week, 7); // End of the week
            $publish_date_str = $publish_date->format('Y-m-d H:i:s');
        }

        $grouped_content = [];
        foreach ($items as $item) {
            $grouped_content[$item->content_type][] = json_decode($item->data_cache);
        }

        $city_details = lr_get_city_details_by_slug($city_slug);
        $city_name = $city_details['name'] ?? ucfirst($city_slug);

        $ai_snippets = lr_prepare_and_get_ai_content($city_name, $grouped_content, $publish_date_str);
        if (is_wp_error($ai_snippets)) {
            lr_log_discovery_message("AI Error for bucket $key: " . $ai_snippets->get_error_message() . ". Using fallback.");
            $title_date = ($frequency === 'monthly') ? $publish_date->format('F Y') : 'Week of ' . $publish_date->format('F j, Y');
            $post_title = $city_name . ' Skate Update: ' . $title_date;
            $post_summary = 'A summary of skate activity in ' . $city_name . ' for ' . $title_date . '.';
            $ai_snippets = [];
        } else {
            $post_title = $ai_snippets['post_title'];
            $post_summary = $ai_snippets['post_summary'];
        }

        $post_slug = sanitize_title($post_title) . '-' . $key;
        $featured_image_url = lr_select_featured_image($grouped_content);
        $post_content = lr_generate_fallback_post_content($city_name, $grouped_content, $post_title, $ai_snippets);

        $wpdb->replace(
            $updates_table,
            [
                'city_slug'    => $city_slug,
                'post_slug'    => $post_slug,
                'post_title'   => $post_title,
                'post_summary' => $post_summary,
                'featured_image_url' => $featured_image_url,
                'post_content' => $post_content,
                'publish_date' => $publish_date_str,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        // After saving the post, mark the recap content as published.
        $content_ids_to_mark = [];
        foreach ($items as $item) {
            if ($item->content_type !== 'event') {
                $content_ids_to_mark[] = $item->id;
            }
        }

        if (!empty($content_ids_to_mark)) {
            $ids_placeholder = implode(',', array_fill(0, count($content_ids_to_mark), '%d'));
            $wpdb->query($wpdb->prepare("UPDATE $discovered_table SET is_published = 1 WHERE id IN ($ids_placeholder)", $content_ids_to_mark));
        }

        lr_log_discovery_message("Generated and saved historical post for bucket: $key");
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

/**
 * Selects a featured image URL from the available content.
 *
 * @param array $grouped_content The content grouped by type.
 * @return string|null The URL of the best available image, or null if none.
 */
function lr_select_featured_image($grouped_content) {
    // Priority: Event > Spot > Reviewer Avatar > Skater Avatar
    if (!empty($grouped_content['event'])) {
        foreach ($grouped_content['event'] as $event) {
            if (!empty($event->attachments[0]->_id)) {
                return plugin_dir_url(__DIR__) . 'image-proxy.php?type=event_attachment&id=' . $event->attachments[0]->_id . '&session_id=' . $event->_id . '&width=400&quality=75';
            }
        }
    }
    if (!empty($grouped_content['spot'])) {
        foreach ($grouped_content['spot'] as $spot) {
            if (!empty($spot->spotWithAddress->satelliteAttachment)) {
                return plugin_dir_url(__DIR__) . 'image-proxy.php?type=spot_satellite&id=' . $spot->spotWithAddress->satelliteAttachment . '&width=400&quality=75';
            }
        }
    }
    if (!empty($grouped_content['review'])) {
        foreach ($grouped_content['review'] as $review) {
            if (!empty($review->user_id)) {
                return 'https://beta.web.lets-roll.app/api/user/' . $review->user_id . '/avatar/content/processed?width=250&height=250&quality=75';
            }
        }
    }
        return null;
    }
    
    /**
     * Processes a batch of cities for historical content seeding.
     * This function is designed to be called by a cron job and will reschedule itself
     * until all cities have been processed.
     */
    function lr_run_historical_seeding_batch() {
        define('LR_SEEDING_BATCH_SIZE', 5);
    
        // Get all cities flattened into a single array
        $all_locations = lr_get_location_data();
        $all_cities = [];
        if (!empty($all_locations)) {
            foreach ($all_locations as $country_data) {
                if (!empty($country_data['cities'])) {
                    foreach ($country_data['cities'] as $city_slug => $city_details) {
                        $all_cities[$city_slug] = $city_details['name'];
                    }
                }
            }
        }
    
        if (empty($all_cities)) {
            lr_log_discovery_message("ERROR: Could not load location data for historical seeding batch. Aborting.");
            return;
        }
    
        $total_cities = count($all_cities);
        $processed_count = get_option('lr_seeding_batch_progress', 0);
    
        if ($processed_count >= $total_cities) {
            lr_log_discovery_message("--- All cities have been seeded. Historical seeding complete. ---");
            delete_option('lr_seeding_batch_progress');
            return;
        }
    
        $cities_to_process = array_slice($all_cities, $processed_count, LR_SEEDING_BATCH_SIZE, true);
    
        lr_log_discovery_message("--- Seeding Batch Start: Processing cities " . ($processed_count + 1) . " to " . ($processed_count + count($cities_to_process)) . " of " . $total_cities . " ---");
    
        foreach ($cities_to_process as $city_slug => $city_name) {
            lr_log_discovery_message("--- Starting Historical Seeding for $city_slug ---");
            lr_run_historical_seeding_for_city($city_slug);
            lr_log_discovery_message("--- Finished Historical Seeding for $city_slug ---");
            $processed_count++;
        }
    
        update_option('lr_seeding_batch_progress', $processed_count);
    
        // Schedule the next batch
        wp_schedule_single_event(time() + 60, 'lr_historical_seeding_batch_cron');
        lr_log_discovery_message("--- Seeding Batch End. Next batch scheduled in 1 minute. ---");
    }
    
    
