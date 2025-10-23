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

    lr_discover_new_spots($city_slug, $city_details);

    lr_discover_new_events($city_slug, $city_details);

    lr_discover_new_sessions($city_slug, $city_details);

    lr_discover_new_reviews($city_slug, $city_details);

    lr_discover_new_skaters($city_slug, $city_details);

}



/**

 * =================================================================================

 * Individual Content Type Discovery Functions

 * =================================================================================

 */



/**

 * Discovers and records new spots for a single city.

 */

function lr_discover_new_spots($city_slug, $city_details) {

    global $wpdb;

    lr_log_discovery_message("Starting spot discovery for $city_slug...");

    

    $spots = lr_get_spots_for_city($city_details);



    if (is_wp_error($spots)) {

        lr_log_discovery_message("API ERROR for spots in $city_slug: " . $spots->get_error_message());

        return;

    }

    if (empty($spots)) {

        lr_log_discovery_message("No spots found in API response for $city_slug.");

        return;

    }



    $table_name = $wpdb->prefix . 'lr_discovered_content';

    $new_spots_found = 0;



    foreach ($spots as $spot) {

        if (empty($spot->_id)) continue;



        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE api_id = %s AND content_type = 'spot'", $spot->_id));

        if (!$existing) {

            $wpdb->insert($table_name, ['content_type' => 'spot', 'api_id' => $spot->_id, 'city_slug' => $city_slug, 'discovered_at' => current_time('mysql'), 'data_cache' => json_encode($spot)], ['%s', '%s', '%s', '%s', '%s']);

            $new_spots_found++;

        }

    }

    lr_log_discovery_message("Finished spot discovery for $city_slug. Found $new_spots_found new spots.");

}



/**

 * Discovers and records new events for a single city.

 */

function lr_discover_new_events($city_slug, $city_details) {

    global $wpdb;

    lr_log_discovery_message("Starting event discovery for $city_slug...");

    

    $events = lr_get_events_for_city($city_details);



    if (is_wp_error($events)) {

        lr_log_discovery_message("API ERROR for events in $city_slug: " . $events->get_error_message());

        return;

    }

    if (empty($events)) {

        lr_log_discovery_message("No events found for $city_slug.");

        return;

    }



    $table_name = $wpdb->prefix . 'lr_discovered_content';

    $new_events_found = 0;



    foreach ($events as $event) {

        if (empty($event->_id)) continue;



        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE api_id = %s AND content_type = 'event'", $event->_id));

        if (!$existing) {

            $wpdb->insert($table_name, ['content_type' => 'event', 'api_id' => $event->_id, 'city_slug' => $city_slug, 'discovered_at' => current_time('mysql'), 'data_cache' => json_encode($event)], ['%s', '%s', '%s', '%s', '%s']);

            $new_events_found++;

        }

    }

    lr_log_discovery_message("Finished event discovery for $city_slug. Found $new_events_found new events.");

}



/**

 * Discovers and records new sessions using the efficient local-feed endpoint.

 */

function lr_discover_new_sessions($city_slug, $city_details) {

    global $wpdb;

    lr_log_discovery_message("Starting session discovery for $city_slug...");

    $access_token = lr_get_api_access_token();

    if (is_wp_error($access_token)) {

        lr_log_discovery_message("ERROR: Could not get API access token for session discovery.");

        return;

    }



    $params = [

        'lat'   => $city_details['latitude'],

        'lng'   => $city_details['longitude'],

        'limit' => 500,

        'skip'  => 0

    ];

    

    $feed_data = lr_fetch_api_data($access_token, 'local-feed', $params);



    if (is_wp_error($feed_data) || empty($feed_data->sessions)) {

        lr_log_discovery_message("No activities found in local-feed response for $city_slug.");

        return;

    }



    $table_name = $wpdb->prefix . 'lr_discovered_content';

    $new_sessions_found = 0;



    foreach ($feed_data->sessions as $activity) {

        // Explicitly skip events, as they are handled by our new robust function.

        if (empty($activity->_id) || ($activity->type ?? 'Roll') === 'Event') {

            continue;

        }



        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE api_id = %s AND content_type = 'session'", $activity->_id));

        if (!$existing) {

            $wpdb->insert($table_name, ['content_type' => 'session', 'api_id' => $activity->_id, 'city_slug' => $city_slug, 'discovered_at' => current_time('mysql'), 'data_cache' => json_encode($activity)], ['%s', '%s', '%s', '%s', '%s']);

            $new_sessions_found++;

        }

    }

    lr_log_discovery_message("Finished session discovery for $city_slug. Found $new_sessions_found new sessions.");

}

/**
 * Discovers and records new reviews for a single city.
 */
function lr_discover_new_reviews($city_slug, $city_details) {
    global $wpdb;
    lr_log_discovery_message("Starting review discovery for $city_slug...");
    $access_token = lr_get_api_access_token();
    if (is_wp_error($access_token)) {
        lr_log_discovery_message("ERROR: Could not get API access token for review discovery.");
        return;
    }

    $spots = lr_get_spots_for_city($city_details);
    if (is_wp_error($spots) || empty($spots)) {
        lr_log_discovery_message("No spots found for $city_slug, cannot discover reviews.");
        return;
    }

    $table_name = $wpdb->prefix . 'lr_discovered_content';
    $new_reviews_found = 0;

    foreach ($spots as $spot) {
        if (empty($spot->_id)) continue;

        $review_data = lr_fetch_api_data($access_token, 'spots/' . $spot->_id . '/ratings-opinions', ['limit' => 100]);
        if (!is_wp_error($review_data) && !empty($review_data->ratingsAndOpinions)) {
            foreach ($review_data->ratingsAndOpinions as $review) {
                if (empty($review->_id)) continue;
                $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE api_id = %s AND content_type = 'review'", $review->_id));
                if (!$existing) {
                    $wpdb->insert($table_name, ['content_type' => 'review', 'api_id' => $review->_id, 'city_slug' => $city_slug, 'discovered_at' => current_time('mysql'), 'data_cache' => json_encode($review)], ['%s', '%s', '%s', '%s', '%s']);
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