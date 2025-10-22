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
 * It iterates through all cities and triggers the discovery for each one.
 */
function lr_run_content_discovery() {
    $locations = lr_get_location_data();
    if (empty($locations)) {
        // We should probably log this error
        return;
    }

    foreach ($locations as $country_data) {
        if (empty($country_data['cities'])) continue;

        foreach ($country_data['cities'] as $city_slug => $city_details) {
            // In a more robust system, we would queue these as separate background jobs.
            // For now, we'll process them sequentially in this single cron task.
            lr_discover_new_content_for_city($city_slug, $city_details);
        }
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
 * Discovers and records new sessions for a single city using the efficient activity endpoint.
 *
 * @param string $city_slug The slug of the city.
 * @param array $city_details The details of the city.
 */
function lr_discover_new_sessions($city_slug, $city_details) {
    global $wpdb;
    $access_token = lr_get_api_access_token();
    if (is_wp_error($access_token)) {
        return;
    }

    $params = [
        'lat'           => $city_details['latitude'],
        'lng'           => $city_details['longitude'],
        'minDistance'   => 0,
        'maxAgeInDays'  => 1, // Only fetch activities from the last 24 hours
        'limit'         => 1000
    ];
    
    $activity_data = lr_fetch_api_data($access_token, 'nearby-activities/v2/skaters', $params);

    if (is_wp_error($activity_data) || empty($activity_data->activities)) {
        return;
    }

    $table_name = $wpdb->prefix . 'lr_discovered_content';
    $one_day_ago = strtotime('-1 day');

    foreach ($activity_data->activities as $activity) {
        // Ensure the activity has a session ID and is new.
        if (empty($activity->sessionId) || empty($activity->createdAt) || strtotime($activity->createdAt) < $one_day_ago) {
            continue;
        }

        // Fetch the full, rich session data to cache it.
        $session_data = lr_fetch_api_data($access_token, 'roll-session/' . $activity->sessionId . '/aggregates', []);

        // Ensure the session data is valid and is NOT an event (events are handled separately).
        if (is_wp_error($session_data) || empty($session_data->sessions[0]) || ($session_data->sessions[0]->type ?? 'Roll') === 'Event') {
            continue;
        }

        $wpdb->insert(
            $table_name,
            [
                'content_type'  => 'session',
                'api_id'        => $session_data->sessions[0]->_id,
                'city_slug'     => $city_slug,
                'discovered_at' => current_time('mysql'),
                'data_cache'    => json_encode($session_data), // Cache the full aggregate data
            ],
            ['%s', '%s', '%s', '%s', '%s']
        );
    }
}

/**
 * Discovers and records new reviews for a single city.
 *
 * @param string $city_slug The slug of the city.
 * @param array $city_details The details of the city.
 */
function lr_discover_new_reviews($city_slug, $city_details) {
    global $wpdb;
    $access_token = lr_get_api_access_token();
    if (is_wp_error($access_token)) {
        return;
    }

    // Step 1: Fetch all spots for the city to check for reviews.
    $bounding_box = lr_calculate_bounding_box($city_details['latitude'], $city_details['longitude'], $city_details['radius_km']);
    $spot_params = ['ne' => $bounding_box['ne'], 'sw' => $bounding_box['sw'], 'limit' => 1000];
    $spots = lr_fetch_api_data($access_token, 'spots/v2/inBox', $spot_params);

    if (is_wp_error($spots) || empty($spots)) {
        return;
    }

    $table_name = $wpdb->prefix . 'lr_discovered_content';
    $one_day_ago = strtotime('-1 day');

    // Step 2: Iterate through each spot to fetch its reviews.
    foreach ($spots as $spot) {
        if (empty($spot->_id)) {
            continue;
        }

        $review_params = ['limit' => 100, 'skip' => 0];
        $review_data = lr_fetch_api_data($access_token, 'spots/' . $spot->_id . '/ratings-opinions', $review_params);

        if (is_wp_error($review_data) || empty($review_data->ratingsAndOpinions)) {
            continue;
        }

        foreach ($review_data->ratingsAndOpinions as $review) {
            if (!empty($review->_id) && !empty($review->createdAt) && strtotime($review->createdAt) >= $one_day_ago) {
                $wpdb->insert(
                    $table_name,
                    [
                        'content_type'  => 'review',
                        'api_id'        => $review->_id,
                        'city_slug'     => $city_slug,
                        'discovered_at' => current_time('mysql'),
                        'data_cache'    => json_encode($review),
                    ],
                    ['%s', '%s', '%s', '%s', '%s']
                );
            }
        }
    }
}

/**
 * Discovers and records new events for a single city.
 *
 * @param string $city_slug The slug of the city.
 * @param array $city_details The details of the city.
 */
function lr_discover_new_events($city_slug, $city_details) {
    global $wpdb;
    $access_token = lr_get_api_access_token();
    if (is_wp_error($access_token)) {
        return;
    }

    $bounding_box = lr_calculate_bounding_box($city_details['latitude'], $city_details['longitude'], $city_details['radius_km']);

    $params = [
        'ne'    => $bounding_box['ne'],
        'sw'    => $bounding_box['sw'],
        'limit' => 1000,
    ];

    $response = lr_fetch_api_data($access_token, 'roll-session/event/inBox', $params);

    if (is_wp_error($response) || empty($response->rollEvents)) {
        return;
    }

    $table_name = $wpdb->prefix . 'lr_discovered_content';
    $one_day_ago = strtotime('-1 day');

    foreach ($response->rollEvents as $event) {
        if (empty($event->_id) || empty($event->createdAt)) {
            continue;
        }

        $created_at_time = strtotime($event->createdAt);

        if ($created_at_time >= $one_day_ago) {
            $wpdb->insert(
                $table_name,
                [
                    'content_type'  => 'event',
                    'api_id'        => $event->_id,
                    'city_slug'     => $city_slug,
                    'discovered_at' => current_time('mysql'),
                    'data_cache'    => json_encode($event),
                ],
                [
                    '%s', '%s', '%s', '%s', '%s',
                ]
            );
        }
    }
}

/**
 * Discovers and records new spots for a single city.
 *
 * @param string $city_slug The slug of the city.
 * @param array $city_details The details of the city.
 */
function lr_discover_new_spots($city_slug, $city_details) {
    global $wpdb;
    $access_token = lr_get_api_access_token();
    if (is_wp_error($access_token)) {
        return; // Should log this error
    }

    // Calculate bounding box from city data
    $bounding_box = lr_calculate_bounding_box($city_details['latitude'], $city_details['longitude'], $city_details['radius_km']);

    $params = [
        'ne'    => $bounding_box['ne'],
        'sw'    => $bounding_box['sw'],
        'limit' => 1000,
    ];

    $spots = lr_fetch_api_data($access_token, 'spots/v2/inBox', $params);

    if (is_wp_error($spots) || empty($spots)) {
        return;
    }

    $table_name = $wpdb->prefix . 'lr_discovered_content';
    $one_day_ago = strtotime('-1 day');

    foreach ($spots as $spot) {
        if (empty($spot->_id) || empty($spot->createdAt)) {
            continue;
        }

        $created_at_time = strtotime($spot->createdAt);

        if ($created_at_time >= $one_day_ago) {
            // This is a new spot, let's record it.
            $wpdb->insert(
                $table_name,
                [
                    'content_type'  => 'spot',
                    'api_id'        => $spot->_id,
                    'city_slug'     => $city_slug,
                    'discovered_at' => current_time('mysql'),
                    'data_cache'    => json_encode($spot),
                ],
                [
                    '%s', // content_type
                    '%s', // api_id
                    '%s', // city_slug
                    '%s', // discovered_at
                    '%s', // data_cache
                ]
            );
        }
    }
}
