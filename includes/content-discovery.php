<?php

if (!defined('ABSPATH')) exit; // Exit if accessed directly

// Register the daily cron job for content discovery
add_action('init', function() {
    if (!wp_next_scheduled('lr_content_discovery_cron')) {
        wp_schedule_event(time(), 'daily', 'lr_content_discovery_cron');
    }
});

// Hook the main discovery function to the cron action
add_action('lr_content_discovery_cron', 'lr_run_content_discovery');

/**
 * Main function to orchestrate the content discovery process.
 * This is now just a wrapper to start the queue.
 */
function lr_run_content_discovery() {
    // Prevent starting a new run if one is already queued.
    if (get_option('lr_discovery_queue') !== false) {
        return;
    }
    lr_queue_content_discovery();
}

/**
 * The background worker that processes one city from the queue.
 */
function lr_run_discovery_worker() {
    $queue = get_option('lr_discovery_queue', []);

    if (empty($queue)) {
        // Queue is empty, we are done.
        delete_option('lr_discovery_queue_total');
        return;
    }

    // Get the next city from the queue.
    $city = array_shift($queue);
    
    // Process this single city.
    lr_discover_new_content_for_city($city['slug'], $city['details']);

    // Save the updated queue.
    update_option('lr_discovery_queue', $queue);

    // If there are more cities, schedule the next run.
    if (!empty($queue)) {
        wp_schedule_single_event(time() + 5, 'lr_content_discovery_worker_event'); // 5-second delay
    } else {
        // Last city was processed, clean up.
        delete_option('lr_discovery_queue_total');
    }
}

/**
 * Discovers and records new content for a single city.
 *
 * @param string $city_slug The slug of the city.
 * @param array $city_details The details of the city.
 */
function lr_discover_new_content_for_city($city_slug, $city_details) {
    lr_discover_new_spots($city_slug, $city_details);
    lr_discover_new_events($city_slug, $city_details);
    lr_discover_new_reviews($city_slug, $city_details);
    lr_discover_new_sessions($city_slug, $city_details);
    lr_discover_new_skaters($city_slug, $city_details);
}



/**
 * Discovers and records new spots for a single city.
 */
function lr_discover_new_spots($city_slug, $city_details) {
    global $wpdb;
    $access_token = lr_get_api_access_token();
    if (is_wp_error($access_token)) {
        lr_discovery_log_message('ERROR: Could not get access token for spot discovery.');
        return;
    }

    $bounding_box = lr_calculate_bounding_box($city_details['latitude'], $city_details['longitude'], $city_details['radius_km']);
    $params = ['ne' => $bounding_box['ne'], 'sw' => $bounding_box['sw'], 'limit' => 1000];
    
    lr_discovery_log_message("DEBUG: Fetching spots for {$city_slug}...");
    $spots = lr_fetch_api_data($access_token, 'spots/v2/inBox', $params);

    if (is_wp_error($spots)) {
        lr_discovery_log_message("ERROR [Spots]: API call failed. " . $spots->get_error_message());
        return;
    }
    if (empty($spots)) {
        lr_discovery_log_message("DEBUG [Spots]: API returned no spots for {$city_slug}.");
        return;
    }

    lr_discovery_log_message("DEBUG [Spots]: Found " . count($spots) . " total spots. Filtering for new ones...");
    $table_name = $wpdb->prefix . 'lr_discovered_content';
    $one_day_ago = strtotime('-1 day');
    $new_found = 0;

    foreach ($spots as $spot) {
        if (!empty($spot->_id) && !empty($spot->createdAt) && strtotime($spot->createdAt) >= $one_day_ago) {
            $wpdb->insert($table_name, ['content_type' => 'spot', 'api_id' => $spot->_id, 'city_slug' => $city_slug, 'discovered_at' => current_time('mysql'), 'data_cache' => json_encode($spot)], ['%s', '%s', '%s', '%s', '%s']);
            $new_found++;
        }
    }
    lr_discovery_log_message("SUCCESS [Spots]: Recorded {$new_found} new spots for {$city_slug}.");
}

/**
 * Discovers and records new events for a single city.
 */
function lr_discover_new_events($city_slug, $city_details) {
    global $wpdb;
    $access_token = lr_get_api_access_token();
    if (is_wp_error($access_token)) {
        lr_discovery_log_message('ERROR: Could not get access token for event discovery.');
        return;
    }

    $bounding_box = lr_calculate_bounding_box($city_details['latitude'], $city_details['longitude'], $city_details['radius_km']);
    $params = ['ne' => $bounding_box['ne'], 'sw' => $bounding_box['sw'], 'limit' => 1000];

    lr_discovery_log_message("DEBUG: Fetching events for {$city_slug}...");
    $response = lr_fetch_api_data($access_token, 'roll-session/event/inBox', $params);

    if (is_wp_error($response)) {
        lr_discovery_log_message("ERROR [Events]: API call failed. " . $response->get_error_message());
        return;
    }
    if (empty($response->rollEvents)) {
        lr_discovery_log_message("DEBUG [Events]: API returned no events for {$city_slug}.");
        return;
    }

    lr_discovery_log_message("DEBUG [Events]: Found " . count($response->rollEvents) . " total events. Filtering for new ones...");
    $table_name = $wpdb->prefix . 'lr_discovered_content';
    $one_day_ago = strtotime('-1 day');
    $new_found = 0;

    foreach ($response->rollEvents as $event) {
        if (!empty($event->_id) && !empty($event->createdAt) && strtotime($event->createdAt) >= $one_day_ago) {
            $wpdb->insert($table_name, ['content_type' => 'event', 'api_id' => $event->_id, 'city_slug' => $city_slug, 'discovered_at' => current_time('mysql'), 'data_cache' => json_encode($event)], ['%s', '%s', '%s', '%s', '%s']);
            $new_found++;
        }
    }
    lr_discovery_log_message("SUCCESS [Events]: Recorded {$new_found} new events for {$city_slug}.");
}

/**
 * Discovers and records new reviews for a single city.
 */
function lr_discover_new_reviews($city_slug, $city_details) {
    global $wpdb;
    $access_token = lr_get_api_access_token();
    if (is_wp_error($access_token)) {
        lr_discovery_log_message('ERROR: Could not get access token for review discovery.');
        return;
    }

    $bounding_box = lr_calculate_bounding_box($city_details['latitude'], $city_details['longitude'], $city_details['radius_km']);
    $spot_params = ['ne' => $bounding_box['ne'], 'sw' => $bounding_box['sw'], 'limit' => 1000];
    
    $spots = lr_fetch_api_data($access_token, 'spots/v2/inBox', $spot_params);
    if (is_wp_error($spots) || empty($spots)) {
        return; // Already logged in spot discovery
    }

    $table_name = $wpdb->prefix . 'lr_discovered_content';
    $one_day_ago = strtotime('-1 day');
    $new_found = 0;

    foreach ($spots as $spot) {
        if (empty($spot->_id)) continue;

        $review_data = lr_fetch_api_data($access_token, 'spots/' . $spot->_id . '/ratings-opinions', ['limit' => 100]);
        if (is_wp_error($review_data) || empty($review_data->ratingsAndOpinions)) {
            continue;
        }

        foreach ($review_data->ratingsAndOpinions as $review) {
            if (!empty($review->_id) && !empty($review->createdAt) && strtotime($review->createdAt) >= $one_day_ago) {
                $wpdb->insert($table_name, ['content_type' => 'review', 'api_id' => $review->_id, 'city_slug' => $city_slug, 'discovered_at' => current_time('mysql'), 'data_cache' => json_encode($review)], ['%s', '%s', '%s', '%s', '%s']);
                $new_found++;
            }
        }
    }
    lr_discovery_log_message("SUCCESS [Reviews]: Recorded {$new_found} new reviews for {$city_slug}.");
}

/**
 * Discovers and records new sessions for a single city.
 */
function lr_discover_new_sessions($city_slug, $city_details) {
    global $wpdb;
    $access_token = lr_get_api_access_token();
    if (is_wp_error($access_token)) {
        lr_discovery_log_message('ERROR: Could not get access token for session discovery.');
        return;
    }

    $params = ['lat' => $city_details['latitude'], 'lng' => $city_details['longitude'], 'minDistance' => 0, 'maxAgeInDays' => 1, 'limit' => 1000];
    
    lr_discovery_log_message("DEBUG: Fetching activities for {$city_slug}...");
    $activity_data = lr_fetch_api_data($access_token, 'nearby-activities/v2/skaters', $params);

    if (is_wp_error($activity_data)) {
        lr_discovery_log_message("ERROR [Sessions]: API call failed. " . $activity_data->get_error_message());
        return;
    }
    if (empty($activity_data->activities)) {
        lr_discovery_log_message("DEBUG [Sessions]: API returned no activities for {$city_slug}.");
        return;
    }

    lr_discovery_log_message("DEBUG [Sessions]: Found " . count($activity_data->activities) . " total activities. Filtering for new sessions...");
    $table_name = $wpdb->prefix . 'lr_discovered_content';
    $one_day_ago = strtotime('-1 day');
    $new_found = 0;

    foreach ($activity_data->activities as $activity) {
        if (empty($activity->sessionId) || empty($activity->createdAt) || strtotime($activity->createdAt) < $one_day_ago) {
            continue;
        }

        $session_data = lr_fetch_api_data($access_token, 'roll-session/' . $activity->sessionId . '/aggregates', []);
        if (is_wp_error($session_data) || empty($session_data->sessions[0]) || ($session_data->sessions[0]->type ?? 'Roll') === 'Event') {
            continue;
        }

        $wpdb->insert($table_name, ['content_type' => 'session', 'api_id' => $session_data->sessions[0]->_id, 'city_slug' => $city_slug, 'discovered_at' => current_time('mysql'), 'data_cache' => json_encode($session_data)], ['%s', '%s', '%s', '%s', '%s']);
        $new_found++;
    }
    lr_discovery_log_message("SUCCESS [Sessions]: Recorded {$new_found} new sessions for {$city_slug}.");
}

/**
 * Discovers and records new skaters seen in a city for the first time.
 */
function lr_discover_new_skaters($city_slug, $city_details) {
    global $wpdb;
    $access_token = lr_get_api_access_token();
    if (is_wp_error($access_token)) {
        lr_discovery_log_message('ERROR: Could not get access token for skater discovery.');
        return;
    }

    $params = ['lat' => $city_details['latitude'], 'lng' => $city_details['longitude'], 'minDistance' => 0, 'maxAgeInDays' => 30, 'limit' => 1000];
    
    lr_discovery_log_message("DEBUG: Fetching skaters for {$city_slug}...");
    $skaters_data = lr_fetch_api_data($access_token, 'nearby-activities/v2/skaters', $params);

    if (is_wp_error($skaters_data)) {
        lr_discovery_log_message("ERROR [Skaters]: API call failed. " . $skaters_data->get_error_message());
        return;
    }
    if (empty($skaters_data->userProfiles)) {
        lr_discovery_log_message("DEBUG [Skaters]: API returned no skaters for {$city_slug}.");
        return;
    }

    lr_discovery_log_message("DEBUG [Skaters]: Found " . count($skaters_data->userProfiles) . " total skaters. Filtering for newly seen...");
    $seen_skaters_table = $wpdb->prefix . 'lr_seen_skaters';
    $discovered_content_table = $wpdb->prefix . 'lr_discovered_content';
    $new_found = 0;

    foreach ($skaters_data->userProfiles as $profile) {
        if (empty($profile->userId)) continue;

        $seen = $wpdb->get_var($wpdb->prepare("SELECT user_api_id FROM $seen_skaters_table WHERE user_api_id = %s AND city_slug = %s", $profile->userId, $city_slug));

        if (!$seen) {
            $wpdb->insert($seen_skaters_table, ['user_api_id' => $profile->userId, 'city_slug' => $city_slug, 'first_seen_at' => current_time('mysql')], ['%s', '%s', '%s']);
            $wpdb->insert($discovered_content_table, ['content_type' => 'skater', 'api_id' => $profile->userId, 'city_slug' => $city_slug, 'discovered_at' => current_time('mysql'), 'data_cache' => json_encode($profile)], ['%s', '%s', '%s', '%s', '%s']);
            $new_found++;
        }
    }
    lr_discovery_log_message("SUCCESS [Skaters]: Recorded {$new_found} newly seen skaters for {$city_slug}.");
}

/**
 * =================================================================================
 * AJAX Handler for Manual Trigger
 * =================================================================================
 */

// Hook the AJAX action for both logged-in users.
add_action('wp_ajax_lr_run_discovery_now', 'lr_handle_ajax_run_discovery');
// Hook the new background worker to its own action.
add_action('lr_content_discovery_worker_event', 'lr_run_discovery_worker');

/**
 * Handles the AJAX request to manually trigger the content discovery.
 */
function lr_handle_ajax_run_discovery() {
    // Security check
    if (!current_user_can('manage_options') || !check_ajax_referer('lr_run_discovery_now', 'nonce', false)) {
        wp_send_json_error('Permission denied.');
        return;
    }

    // Queue the discovery process to run in the background.
    lr_queue_content_discovery();

    // Send an immediate success response to the browser.
    wp_send_json_success('Content discovery process has been queued and will start shortly.');
}

/**
 * Creates a queue of cities and schedules the background worker to start processing.
 */
function lr_queue_content_discovery() {
    // Clear any old queue.
    delete_option('lr_discovery_queue');
    lr_discovery_log_message('DEBUG: Clearing old discovery queue.');

    $locations = lr_get_location_data();
    $city_queue = [];
    if (!empty($locations)) {
        foreach ($locations as $country_data) {
            if (empty($country_data['cities'])) continue;
            foreach ($country_data['cities'] as $city_slug => $city_details) {
                $city_queue[] = ['slug' => $city_slug, 'details' => $city_details];
            }
        }
    }

    if (!empty($city_queue)) {
        update_option('lr_discovery_queue', $city_queue);
        update_option('lr_discovery_queue_total', count($city_queue)); // For progress tracking
        lr_discovery_log_message('DEBUG: Created new discovery queue with ' . count($city_queue) . ' cities.');
        
        // Schedule the worker to start processing now.
        wp_schedule_single_event(time(), 'lr_content_discovery_worker_event');
    }
}

/**
 * =================================================================================
 * Discovery Process Logging
 * =================================================================================
 */

/**
 * Adds a message to the discovery process log.
 *
 * @param string $message The message to log.
 */
function lr_discovery_log_message($message) {
    $log = get_transient('lr_discovery_log');
    if (!is_array($log)) {
        $log = [];
    }
    array_unshift($log, '[' . date('Y-m-d H:i:s') . '] ' . $message);
    if (count($log) > 100) {
        $log = array_slice($log, 0, 100);
    }
    set_transient('lr_discovery_log', $log, HOUR_IN_SECONDS);
}

/**
 * Clears the discovery process log.
 */
function lr_clear_discovery_log() {
    delete_transient('lr_discovery_log');
}
