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
 * =================================================================================
 * Logging Functions
 * =================================================================================
 */

/**
 * Gets the full path for the log file.
 * @return string
 */
function lr_get_discovery_log_file_path() {
    return plugin_dir_path(__DIR__) . 'content_discovery.log';
}

/**
 * Clears the log file.
 */
function lr_clear_discovery_log_file() {
    file_put_contents(lr_get_discovery_log_file_path(), '');
}

/**
 * Appends a message to the log file.
 * @param string $message The message to log.
 */
function lr_log_discovery_message($message) {
    $log_file = lr_get_discovery_log_file_path();
    $timestamp = current_time('mysql');
    file_put_contents($log_file, "[" . $timestamp . "] " . $message . "\n", FILE_APPEND);
}

/**
 * =================================================================================
 * Main Discovery Orchestration
 * =================================================================================
 */

/**
 * Main function to orchestrate the full content discovery process.
 */
function lr_run_content_discovery() {
    lr_clear_discovery_log_file();
    lr_log_discovery_message("Starting full content discovery run...");

    $locations = lr_get_location_data();
    if (empty($locations)) {
        lr_log_discovery_message("ERROR: Could not load location data. Aborting run.");
        return;
    }

    lr_log_discovery_message("Found " . count($locations) . " countries to process.");

    foreach ($locations as $country_data) {
        if (empty($country_data['cities'])) continue;

        foreach ($country_data['cities'] as $city_slug => $city_details) {
            lr_log_discovery_message("--- Processing city: $city_slug ---");
            lr_discover_new_content_for_city($city_slug, $city_details);
        }
    }
    lr_log_discovery_message("Full content discovery run finished.");
}

function lr_discover_new_content_for_city($city_slug, $city_details) {
    $rich_spots = lr_discover_new_spots_and_get_details($city_slug, $city_details);
    lr_discover_new_activities($city_slug, $city_details);
    lr_discover_new_reviews($city_slug, $rich_spots);
    lr_discover_new_skaters($city_slug, $city_details);
}

/**
 * =================================================================================
 * Individual Content Type Discovery Functions
 * =================================================================================
 */

function lr_discover_new_spots_and_get_details($city_slug, $city_details) {
    global $wpdb;
    lr_log_discovery_message("Starting spot discovery for $city_slug...");
    $access_token = lr_get_api_access_token();
    if (is_wp_error($access_token)) {
        lr_log_discovery_message("ERROR: Could not get API access token for spot discovery.");
        return [];
    }

    $spots = lr_get_spots_for_city($city_details);

    if (is_wp_error($spots) || empty($spots)) {
        lr_log_discovery_message("No spots found in API response for $city_slug.");
        return [];
    }

    $table_name = $wpdb->prefix . 'lr_discovered_content';
    $new_spots_found = 0;
    $rich_spots_details = []; // Array to hold the full details

    foreach ($spots as $spot_summary) {
        if (empty($spot_summary->_id)) continue;

        $spot_details = lr_fetch_api_data($access_token, 'spots/' . $spot_summary->_id, []);
        if (!$spot_details || is_wp_error($spot_details)) continue;
        
        $rich_spots_details[] = $spot_details; // Add to our array

        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE api_id = %s AND content_type = 'spot'", $spot_summary->_id));
        if (!$existing) {
            $wpdb->insert($table_name, ['content_type' => 'spot', 'api_id' => $spot_summary->_id, 'city_slug' => $city_slug, 'discovered_at' => current_time('mysql'), 'data_cache' => json_encode($spot_details)], ['%s', '%s', '%s', '%s', '%s']);
            $new_spots_found++;
        }
    }
    lr_log_discovery_message("Finished spot discovery for $city_slug. Found $new_spots_found new spots.");
    return $rich_spots_details; // Return the rich data
}

function lr_discover_new_activities($city_slug, $city_details) {
    global $wpdb;
    lr_log_discovery_message("Starting activity (event/session) discovery for $city_slug...");
    $access_token = lr_get_api_access_token();
    if (is_wp_error($access_token)) {
        lr_log_discovery_message("ERROR: Could not get API access token for activity discovery.");
        return;
    }

    $table_name = $wpdb->prefix . 'lr_discovered_content';
    $all_events_by_id = [];

    // --- CORRECTED HYBRID EVENT DISCOVERY ---
    // This uses a two-step process to create a comprehensive list of events.
    // 1. Get all events geographically located within the city's bounding box. These are reliable.
    $bounding_box = lr_calculate_bounding_box($city_details['latitude'], $city_details['longitude'], $city_details['radius_km']);
    $params_inbox = ['ne' => $bounding_box['ne'], 'sw' => $bounding_box['sw'], 'limit' => 1000];
    $response_inbox = lr_fetch_api_data($access_token, 'roll-session/event/inBox', $params_inbox);

    if (!is_wp_error($response_inbox) && !empty($response_inbox->rollEvents)) {
        foreach ($response_inbox->rollEvents as $event) {
            $all_events_by_id[$event->_id] = $event;
        }
    }
    lr_log_discovery_message("Found " . count($all_events_by_id) . " events from inBox endpoint.");

    // 2. Supplement the list with "orphan" events from the local feed. These are events
    //    posted by users nearby that are not attached to a specific skate spot, and would
    //    otherwise be missed. This prevents incorrectly including events from other cities.
    $params_feed = ['lat' => $city_details['latitude'], 'lng' => $city_details['longitude'], 'limit' => 500];
    $feed_data = lr_fetch_api_data($access_token, 'local-feed', $params_feed);

    $initial_event_count = count($all_events_by_id);
    if (!is_wp_error($feed_data) && !empty($feed_data->sessions)) {
        foreach ($feed_data->sessions as $activity) {
            // A true "orphan" is an Event, has NO spotId, and is not already in our list.
            if (($activity->type ?? 'Roll') === 'Event' && empty($activity->spotId) && !isset($all_events_by_id[$activity->_id])) {
                $all_events_by_id[$activity->_id] = $activity;
            }
        }
    }
    lr_log_discovery_message("Found " . (count($all_events_by_id) - $initial_event_count) . " new orphan events from local-feed.");

    // Step 3: Process the combined list of all events for newness.
    $new_events_found = 0;
    foreach ($all_events_by_id as $event) {
        if (empty($event->_id)) continue;

        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE api_id = %s AND content_type = 'event'", $event->_id));
        if (!$existing) {
            // --- ADDED: Enrich event data before caching ---
            $data_to_cache = $event;
            $enriched_data = lr_fetch_api_data($access_token, 'roll-session/' . $event->_id . '/aggregates', []);
            if ($enriched_data && !is_wp_error($enriched_data) && !empty($enriched_data->sessions[0])) {
                // The aggregates endpoint returns a 'sessions' array, even for a single event.
                $data_to_cache = $enriched_data->sessions[0];
                // Manually add attachments to the main object for easier access in templates.
                $data_to_cache->attachments = $enriched_data->attachments ?? [];
            }

            $wpdb->insert($table_name, [
                'content_type'  => 'event',
                'api_id'        => $event->_id,
                'city_slug'     => $city_slug,
                'discovered_at' => current_time('mysql'),
                'data_cache'    => json_encode($data_to_cache)
            ], ['%s', '%s', '%s', '%s', '%s']);
            $new_events_found++;
        }
    }
    lr_log_discovery_message("Finished event discovery for $city_slug. Found $new_events_found new total events.");

    // --- PRESERVED SESSION DISCOVERY (from the same local-feed call) ---
    if (is_wp_error($feed_data) || empty($feed_data->sessions)) {
        lr_log_discovery_message("No sessions found in local-feed response for $city_slug.");
        return;
    }

    $new_sessions_found = 0;
    foreach ($feed_data->sessions as $activity) {
        if (empty($activity->_id) || ($activity->type ?? 'Roll') === 'Event') {
            continue; // Skip events, as they are now handled above.
        }

        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE api_id = %s AND content_type = 'session'", $activity->_id));
        if (!$existing) {
            $data_to_cache = $activity;
            $enriched_data = lr_fetch_api_data($access_token, 'roll-session/' . $activity->_id . '/aggregates', []);
            if ($enriched_data && !is_wp_error($enriched_data)) {
                $data_to_cache = $enriched_data;
            }
            
            $wpdb->insert($table_name, [
                'content_type'  => 'session',
                'api_id'        => $activity->_id,
                'city_slug'     => $city_slug,
                'discovered_at' => current_time('mysql'),
                'data_cache'    => json_encode($data_to_cache)
            ], ['%s', '%s', '%s', '%s', '%s']);
            
            $new_sessions_found++;
        }
    }
    lr_log_discovery_message("Finished session discovery for $city_slug. Found $new_sessions_found new sessions.");
}

/**
 * Discovers and records new reviews for a single city, enriching them with spot and user data.
 */
function lr_discover_new_reviews($city_slug, $rich_spots) {
    global $wpdb;
    lr_log_discovery_message("Starting review discovery for $city_slug...");
    $access_token = lr_get_api_access_token();
    if (is_wp_error($access_token)) {
        lr_log_discovery_message("ERROR: Could not get API access token for review discovery.");
        return;
    }

    if (empty($rich_spots)) {
        lr_log_discovery_message("No spots provided for review discovery in $city_slug.");
        return;
    }

    $table_name = $wpdb->prefix . 'lr_discovered_content';
    $new_reviews_found = 0;

    foreach ($rich_spots as $spot_details) {
        if (empty($spot_details->spotWithAddress) || !isset($spot_details->spotWithAddress->rating->ratingsCount) || $spot_details->spotWithAddress->rating->ratingsCount === 0) {
            continue;
        }

        // The ratings-opinions endpoint conveniently returns both the reviews and the profiles
        // of the users who wrote them. This is much more efficient than fetching each profile individually.
        $response_data = lr_fetch_api_data($access_token, 'spots/' . $spot_details->spotWithAddress->_id . '/ratings-opinions', ['limit' => 100]);
        
        if (!is_wp_error($response_data) && !empty($response_data->ratingsAndOpinions)) {
            
            // Create a lookup map of user profiles keyed by userId for efficient access.
            $user_profiles_map = [];
            if (!empty($response_data->userProfiles)) {
                foreach ($response_data->userProfiles as $profile) {
                    $user_profiles_map[$profile->userId] = $profile;
                }
            }

            // Iterate through the reviews and enrich them with data from the spot and the user map.
            foreach ($response_data->ratingsAndOpinions as $review) {
                if (empty($review->_id)) continue;

                $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE api_id = %s AND content_type = 'review'", $review->_id));
                if (!$existing) {
                    $user_profile = $user_profiles_map[$review->userId] ?? null;

                    // This consolidated object is what gets saved to the database.
                    // It contains all necessary info to render a rich review card later.
                    $data_to_cache = [
                        'review_id' => $review->_id,
                        'spot_id'   => $spot_details->spotWithAddress->_id,
                        'spot_name' => $spot_details->spotWithAddress->name,
                        'rating'    => $review->rating,
                        'comment'   => $review->comment,
                        'user_id'   => $review->userId,
                        'skate_name'=> $user_profile->skateName ?? 'A Skater',
                        'createdAt' => $review->createdAt,
                    ];

                    $wpdb->insert($table_name, [
                        'content_type'  => 'review',
                        'api_id'        => $review->_id,
                        'city_slug'     => $city_slug,
                        'discovered_at' => current_time('mysql'),
                        'data_cache'    => json_encode($data_to_cache)
                    ], ['%s', '%s', '%s', '%s', '%s']);
                    $new_reviews_found++;
                }
            }
        }
    }
    lr_log_discovery_message("Finished review discovery for $city_slug. Found $new_reviews_found new reviews.");
}

/**
 * Discovers and records new skaters seen in a city for the first time.
 */
function lr_discover_new_skaters($city_slug, $city_details) {
    global $wpdb;
    lr_log_discovery_message("Starting skater discovery for $city_slug...");

    // Use the new, correct, consolidated function to get properly filtered skaters
    $skaters = lr_fetch_filtered_skaters_for_city($city_details);

    if (is_wp_error($skaters)) {
        lr_log_discovery_message("API ERROR for skaters in $city_slug: " . $skaters->get_error_message());
        return;
    }
    if (empty($skaters)) {
        lr_log_discovery_message("No user profiles found within the radius for $city_slug.");
        return;
    }

    $seen_skaters_table = $wpdb->prefix . 'lr_seen_skaters';
    $discovered_content_table = $wpdb->prefix . 'lr_discovered_content';
    $new_skaters_found = 0;

    foreach ($skaters as $profile) {
        if (empty($profile->userId)) continue;

        $seen = $wpdb->get_var($wpdb->prepare("SELECT user_api_id FROM $seen_skaters_table WHERE user_api_id = %s AND city_slug = %s", $profile->userId, $city_slug));

        if (!$seen) {
            $wpdb->insert($seen_skaters_table, ['user_api_id' => $profile->userId, 'city_slug' => $city_slug, 'first_seen_at' => current_time('mysql')], ['%s', '%s', '%s']);
            $wpdb->insert($discovered_content_table, ['content_type' => 'skater', 'api_id' => $profile->userId, 'city_slug' => $city_slug, 'discovered_at' => current_time('mysql'), 'data_cache' => json_encode($profile)], ['%s', '%s', '%s', '%s', '%s']);
            $new_skaters_found++;
        }
    }
    lr_log_discovery_message("Finished skater discovery for $city_slug. Found $new_skaters_found new skaters.");
}