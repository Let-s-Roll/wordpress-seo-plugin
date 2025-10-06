<?php

if (!defined('ABSPATH')) exit; // Exit if accessed directly

/**
 * Gets the server's outbound IP address by calling an external service.
 *
 * @return string The server's IP address or an error message.
 */
function lr_get_server_ip() {
    $response = wp_remote_get('https://api.ipify.org');
    if (is_wp_error($response)) {
        return 'Error retrieving IP: ' . $response->get_error_message();
    }
    $ip = wp_remote_retrieve_body($response);
    if (filter_var($ip, FILTER_VALIDATE_IP)) {
        return $ip;
    }
    return 'Could not determine server IP.';
}

/**
 * Fetches a list of skaters for a specific city (without emails).
 * This is a fast, single API call per city.
 *
 * @param array $city_details The city data array from merged.json.
 * @return array|WP_Error An array of user profile objects or a WP_Error on failure.
 */
function lr_fetch_skaters_for_city($city_details) {
    $access_token = lr_get_api_access_token();
    if (is_wp_error($access_token)) {
        return $access_token;
    }

    $params = [
        'lat'           => $city_details['latitude'],
        'lng'           => $city_details['longitude'],
        'minDistance'   => 0,
        'maxAgeInDays'  => 365,
        'limit'         => 1000 // Increased limit
    ];
    
    $skaters_data = lr_fetch_api_data($access_token, 'nearby-activities/v2/skaters', $params);

    if (is_wp_error($skaters_data)) {
        return $skaters_data;
    }

    if (empty($skaters_data->activities) || empty($skaters_data->userProfiles)) {
        return [];
    }

    $radius_meters = ($city_details['radius_km'] ?? 50) * 1000;
    $user_distances = [];

    // Find the minimum distance for each user within the radius
    foreach ($skaters_data->activities as $activity) {
        if (isset($activity->distance) && $activity->distance <= $radius_meters && !empty($activity->userId)) {
            $userId = $activity->userId;
            if (!isset($user_distances[$userId]) || $activity->distance < $user_distances[$userId]) {
                $user_distances[$userId] = $activity->distance;
            }
        }
    }

    if (empty($user_distances)) {
        return [];
    }

    // Create the final list of profiles, augmented with the distance
    $filtered_profiles = [];
    foreach ($skaters_data->userProfiles as $profile) {
        if (!empty($profile->userId) && isset($user_distances[$profile->userId])) {
            // Add the distance to the profile object
            $profile->distance_km = round($user_distances[$profile->userId] / 1000, 2);
            $filtered_profiles[] = $profile;
        }
    }
    
    // Sort by distance, closest first
    usort($filtered_profiles, function($a, $b) {
        return $a->distance_km <=> $b->distance_km;
    });

    return $filtered_profiles;
}

/**
 * Enriches a single Brevo contact by using a Let's Roll skateName.
 * It finds the contact in Brevo by the SKATENAME attribute, then updates that contact.
 *
 * @param string $skateName The Let's Roll skateName.
 * @param string $city_name The city name to add to the contact.
 */
function lr_enrich_skater_in_brevo_by_skatename($skateName, $city_name) {
    if (empty($skateName)) {
        echo '<p style="color: red;"><strong>Error:</strong> Invalid skateName provided.</p>';
        return;
    }

    $options = get_option('lr_brevo_options');
    $brevo_api_key = $options['api_key'] ?? '';

    if (empty($brevo_api_key)) {
        echo '<p style="color: red;"><strong>Error:</strong> Brevo API Key is not set in settings.</p>';
        return;
    }

    // Step 1: Find the contact in Brevo by the SKATENAME custom attribute
    $search_url = add_query_arg(
        'filter',
        'equals(SKATENAME,"' . $skateName . '")',
        'https://api.brevo.com/v3/contacts'
    );

    $search_args = [
        'headers' => [
            'api-key' => $brevo_api_key,
            'Accept'  => 'application/json',
        ]
    ];

    $search_response = wp_remote_get($search_url, $search_args);
    $search_response_code = wp_remote_retrieve_response_code($search_response);
    $search_body = json_decode(wp_remote_retrieve_body($search_response));

    if ($search_response_code !== 200 || !isset($search_body->contacts)) {
        $error_message = $search_body->message ?? 'Unknown error during search';
        echo '<p style="color: red;">Error searching for contact with skateName ' . esc_html($skateName) . '. API responded with code ' . esc_html($search_response_code) . ' and message: ' . esc_html($error_message) . '</p>';
        return;
    }

    if (empty($search_body->contacts)) {
        echo '<p style="color: #888;">No contact found in Brevo with skateName: ' . esc_html($skateName) . '. Skipping.</p>';
        return;
    }

    if (count($search_body->contacts) > 1) {
        echo '<p style="color: orange;">Warning: Found multiple contacts with the same skateName: ' . esc_html($skateName) . '. Skipping to avoid ambiguity.</p>';
        return;
    }

    $contact_email = $search_body->contacts[0]->email;
    echo '<p>Found contact: ' . esc_html($contact_email) . '. Now updating with city...</p>';

    // Step 2: Update the contact in Brevo using the found email
    $update_url = 'https://api.brevo.com/v3/contacts/' . urlencode(strtolower($contact_email));
    $update_args = [
        'method'  => 'PUT',
        'headers' => [
            'api-key'      => $brevo_api_key,
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ],
        'body'    => json_encode([
            'attributes' => [ 'CITY' => $city_name ],
        ]),
        'timeout' => 15,
    ];

    $update_response = wp_remote_request($update_url, $update_args);
    $update_response_code = wp_remote_retrieve_response_code($update_response);

    if ($update_response_code === 204) {
        echo '<p style="color: green;">Successfully updated contact: ' . esc_html($contact_email) . ' with city: ' . esc_html($city_name) . '</p>';
        lr_add_processed_skater($skateName, $city_name); // Add to log on success
    } else {
        $update_body = json_decode(wp_remote_retrieve_body($update_response));
        $error_message = $update_body->message ?? 'Unknown error during update';
        echo '<p style="color: red;">Failed to update contact ' . esc_html($contact_email) . '. Brevo API responded with code ' . esc_html($update_response_code) . ' and message: ' . esc_html($error_message) . '</p>';
    }
}

/**
 * =================================================================================
 * Processed Skater Log Functions
 * =================================================================================
 */

/**
 * Retrieves the list of processed skaters from the database.
 * @return array An associative array of [skateName => cityName].
 */
function lr_get_processed_skaters() {
    return get_option('lr_brevo_processed_skaters', []);
}

/**
 * Adds a skateName to the processed list.
 * @param string $skateName The skateName to add.
 * @param string $cityName The city they were synced to.
 */
function lr_add_processed_skater($skateName, $cityName) {
    $processed = lr_get_processed_skaters();
    // Use skateName as key to prevent duplicates and store city
    $processed[$skateName] = $cityName;
    update_option('lr_brevo_processed_skaters', $processed);
}

/**
 * Removes a skateName from the processed list.
 * @param string $skateName The skateName to remove.
 */
function lr_remove_processed_skater($skateName) {
    $processed = lr_get_processed_skaters();
    if (isset($processed[$skateName])) {
        unset($processed[$skateName]);
        update_option('lr_brevo_processed_skaters', $processed);
    }
}

/**
 * Clears the entire processed skater list.
 */
function lr_clear_processed_skaters() {
    delete_option('lr_brevo_processed_skaters');
}


/**
 * Main function to execute the full Brevo sync process.
 */
function run_brevo_sync() {
    $locations = lr_get_location_data();
    if (empty($locations)) {
        echo '<p style="color: red;"><strong>Error:</strong> Could not load city data.</p>';
        return;
    }
    
    echo '<p>Starting full sync. This may be slow depending on the number of skaters and Brevo API limits.</p>';
    set_time_limit(1800); // 30 minutes

    $total_skaters_processed = 0;

    foreach ($locations as $country_data) {
        if (empty($country_data['cities'])) continue;

        foreach ($country_data['cities'] as $city_slug => $city_details) {
            // Use the single city sync function for each city
            $total_skaters_processed += lr_run_single_city_sync($city_details);
        }
    }

    echo '<hr><p><strong>Full Sync Complete!</strong></p>';
    echo '<p><strong>Total skaters processed in this run: ' . $total_skaters_processed . '</strong></p>';
}

/**
 * Runs the sync process for only one specific city.
 *
 * @param array $city_details The details of the city to process.
 * @return int The number of skaters processed in this city.
 */
function lr_run_single_city_sync($city_details) {
    $city_name = $city_details['name'];
    echo "<hr><p><strong>Processing City: " . esc_html($city_name) . "</strong></p>";

    $skaters_list = lr_fetch_skaters_for_city($city_details);
    $processed_skaters = lr_get_processed_skaters();
    $skaters_processed_in_this_run = 0;

    if (is_wp_error($skaters_list)) {
        echo '<p style="color: orange;">Warning: Could not fetch skaters for ' . esc_html($city_name) . '. Skipping. Reason: ' . esc_html($skaters_list->get_error_message()) . '</p>';
        return 0;
    }

    if (empty($skaters_list)) {
        echo '<p>No active skaters found for ' . esc_html($city_name) . '. Skipping.</p>';
        return 0;
    }

    echo '<p>Found ' . count($skaters_list) . ' skaters. Now finding and updating in Brevo one by one (skipping already processed skaters)...</p>';

    foreach ($skaters_list as $skater) {
        if (!empty($skater->skateName)) {
            if (array_key_exists($skater->skateName, $processed_skaters)) {
                echo '<p style="color: #888;">Skipping already processed skater: ' . esc_html($skater->skateName) . '</p>';
                continue;
            }
            lr_enrich_skater_in_brevo_by_skatename($skater->skateName, $city_name);
            $skaters_processed_in_this_run++;
            usleep(200000); // 200ms pause
        }
    }
    
    echo '<p>Finished processing ' . esc_html($city_name) . '. Processed ' . $skaters_processed_in_this_run . ' new skaters.</p>';
    return $skaters_processed_in_this_run;
}
