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
 * Searches for a contact in Brevo using the FIRSTNAME standard attribute.
 *
 * @param string $skateName The skateName to search for in the FIRSTNAME field.
 * @return object|null The contact object from Brevo if found, otherwise null.
 */
function lr_find_brevo_contact_by_firstname($skateName) {
    $options = get_option('lr_brevo_options');
    $brevo_api_key = $options['api_key'] ?? '';

    if (empty($brevo_api_key) || empty($skateName)) {
        return null;
    }

    $url = 'https://api.brevo.com/v3/contacts';
    $filter_value = 'equals(FIRSTNAME,"' . $skateName . '")';
    $full_url = add_query_arg('filter', $filter_value, $url);

    // Log the request for debugging
    error_log('Brevo API Request URL: ' . $full_url);

    $args = [
        'headers' => [
            'api-key' => $brevo_api_key,
            'Accept'  => 'application/json',
        ]
    ];

    $response = wp_remote_get($full_url, $args);
    $response_code = wp_remote_retrieve_response_code($response);
    $body_raw = wp_remote_retrieve_body($response);

    // Log the response for debugging
    error_log('Brevo API Response Code: ' . $response_code);
    error_log('Brevo API Response Body: ' . $body_raw);

    $body = json_decode($body_raw);

    if ($response_code !== 200 || !isset($body->contacts) || empty($body->contacts)) {
        return null; // Not found or error
    }

    if (count($body->contacts) > 1) {
        return null; // Found more than one, ambiguous
    }

    return $body->contacts[0];
}

/**
 * Adds a single contact to a specific list in Brevo.
 *
 * @param string $email The email of the contact to add.
 * @param int    $list_id The ID of the list to add the contact to.
 * @return bool|WP_Error True on success, WP_Error on failure.
 */
function lr_add_contact_to_brevo_list($email, $list_id) {
    $options = get_option('lr_brevo_options');
    $brevo_api_key = $options['api_key'] ?? '';
    if (empty($brevo_api_key)) {
        return new WP_Error('api_key_missing', 'Brevo API key is not set.');
    }

    $url = 'https://api.brevo.com/v3/contacts/lists/' . $list_id . '/contacts/add';
    $args = [
        'method'  => 'POST',
        'headers' => [
            'api-key'      => $brevo_api_key,
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ],
        'body'    => json_encode(['emails' => [$email]]),
    ];

    $response = wp_remote_post($url, $args);
    $response_code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response));

    if ($response_code !== 201) {
        $error_message = $body->message ?? 'Unknown error';
        return new WP_Error('add_to_list_failed', 'Failed to add contact to list. Reason: ' . $error_message);
    }

    return true;
}

/**
 * Finds a skater in Brevo and adds them to the appropriate city list.
 *
 * @param string $skateName The Let's Roll skateName.
 * @param string $city_name The city name to find the list for.
 */
function lr_add_skater_to_brevo_city_list($skateName, $city_name) {
    $contact = lr_find_brevo_contact_by_firstname($skateName);

    if (!$contact) {
        echo '<p style="color: #888;">No unique contact found in Brevo with skateName: ' . esc_html($skateName) . '. Skipping.</p>';
        return;
    }

    $city_list_ids = lr_get_city_list_ids();
    if (!isset($city_list_ids[$city_name])) {
        echo '<p style="color: red;">Error: No Brevo list ID found for city: ' . esc_html($city_name) . '. Skipping.</p>';
        return;
    }
    $list_id = $city_list_ids[$city_name];
    $contact_email = $contact->email;

    echo '<p>Found contact: ' . esc_html($contact_email) . '. Adding to list "' . esc_html($city_name) . '" (ID: ' . esc_html($list_id) . ')...</p>';

    $result = lr_add_contact_to_brevo_list($contact_email, $list_id);

    if (is_wp_error($result)) {
        echo '<p style="color: red;">Failed to add contact ' . esc_html($contact_email) . ' to list. Reason: ' . esc_html($result->get_error_message()) . '</p>';
    } else {
        echo '<p style="color: green;">Successfully added contact ' . esc_html($contact_email) . ' to list "' . esc_html($city_name) . '".</p>';
        lr_add_processed_skater($skateName, $city_name); // Add to log on success
    }
}

/**
 * =================================================================================
 * Brevo City List Management
 * =================================================================================
 */

// Hook the AJAX handler into WordPress
add_action('wp_ajax_lr_brevo_sync_city_lists', 'lr_brevo_ajax_sync_city_lists');

/**
 * Retrieves all contact lists from Brevo.
 *
 * @return array|WP_Error An array of list objects or a WP_Error on failure.
 */
function lr_get_brevo_lists() {
    $options = get_option('lr_brevo_options');
    $brevo_api_key = $options['api_key'] ?? '';
    if (empty($brevo_api_key)) {
        return new WP_Error('api_key_missing', 'Brevo API key is not set.');
    }

    $all_lists = [];
    $offset = 0;
    $limit = 50; // A more conservative limit

    do {
        $url = add_query_arg([
            'limit'  => $limit,
            'offset' => $offset,
            'sort'   => 'desc',
        ], 'https://api.brevo.com/v3/contacts/lists');

        $args = [
            'headers' => [
                'api-key' => $brevo_api_key,
                'Accept'  => 'application/json',
            ]
        ];

        $response = wp_remote_get($url, $args);
        $response_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response));

        if ($response_code !== 200 || !isset($body->lists)) {
            return new WP_Error('api_error', 'Failed to fetch lists from Brevo. Code: ' . $response_code);
        }

        $all_lists = array_merge($all_lists, $body->lists);
        $offset += $limit;

    } while (count($body->lists) === $limit);

    return $all_lists;
}

/**
 * Creates a new contact list in Brevo.
 *
 * @param string $list_name The name of the list to create.
 * @param int    $folder_id The ID of the folder to create the list in.
 * @return int|WP_Error The ID of the newly created list or a WP_Error on failure.
 */
function lr_create_brevo_list($list_name, $folder_id) {
    $options = get_option('lr_brevo_options');
    $brevo_api_key = $options['api_key'] ?? '';
    if (empty($brevo_api_key)) {
        return new WP_Error('api_key_missing', 'Brevo API key is not set.');
    }

    $url = 'https://api.brevo.com/v3/contacts/lists';
    $args = [
        'method'  => 'POST',
        'headers' => [
            'api-key'      => $brevo_api_key,
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ],
        'body'    => json_encode([
            'name'     => $list_name,
            'folderId' => $folder_id,
        ]),
    ];

    $response = wp_remote_post($url, $args);
    $response_code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response));

    if ($response_code !== 201 || !isset($body->id)) {
        $error_message = $body->message ?? 'Unknown error';
        return new WP_Error('list_creation_failed', 'Failed to create list "' . $list_name . '". Reason: ' . $error_message);
    }

    return $body->id;
}

/**
 * Retrieves the saved city list ID mappings.
 * @return array An associative array of [cityName => listId].
 */
function lr_get_city_list_ids() {
    return get_option('lr_brevo_city_list_ids', []);
}

/**
 * AJAX handler to sync city lists with Brevo.
 */
function lr_brevo_ajax_sync_city_lists() {
    check_ajax_referer('lr_brevo_sync_city_lists_nonce', 'nonce');
    lr_clear_brevo_log();
    lr_brevo_log_message('Starting City List synchronization with Brevo...');

    $options = get_option('lr_brevo_options');
    $folder_id = $options['list_folder_id'] ?? 31; // Default to 31 if not set
    if (empty($folder_id)) {
        wp_send_json_error(['message' => 'Brevo List Folder ID is not set.']);
        return;
    }

    // 1. Get all lists from Brevo
    $brevo_lists_raw = lr_get_brevo_lists();
    if (is_wp_error($brevo_lists_raw)) {
        wp_send_json_error(['message' => $brevo_lists_raw->get_error_message()]);
        return;
    }
    $brevo_lists = [];
    foreach ($brevo_lists_raw as $list) {
        if ($list->folderId === $folder_id) {
            $brevo_lists[$list->name] = $list->id;
        }
    }
    lr_brevo_log_message('Found ' . count($brevo_lists) . ' existing lists in folder ' . $folder_id . '.');

    // 2. Get all cities from our plugin
    $locations = lr_get_location_data();
    $plugin_cities = [];
    if (!empty($locations)) {
        foreach ($locations as $country_data) {
            if (empty($country_data['cities'])) continue;
            foreach ($country_data['cities'] as $city_details) {
                $plugin_cities[] = $city_details['name'];
            }
        }
    }
    lr_brevo_log_message('Found ' . count($plugin_cities) . ' cities in the plugin settings.');

    // 3. Compare and create missing lists
    $city_list_mappings = lr_get_city_list_ids();
    $created_count = 0;

    foreach ($plugin_cities as $city_name) {
        if (!isset($brevo_lists[$city_name])) {
            lr_brevo_log_message('List for "' . $city_name . '" not found in Brevo. Creating it...');
            $new_list_id = lr_create_brevo_list($city_name, $folder_id);
            if (is_wp_error($new_list_id)) {
                lr_brevo_log_message('ERROR creating list for ' . $city_name . ': ' . $new_list_id->get_error_message());
            } else {
                $city_list_mappings[$city_name] = $new_list_id;
                $created_count++;
                lr_brevo_log_message('SUCCESS: Created list for "' . $city_name . '" with ID: ' . $new_list_id);
            }
        } else {
            // If it exists in Brevo but not in our map, add it.
            if (!isset($city_list_mappings[$city_name])) {
                $city_list_mappings[$city_name] = $brevo_lists[$city_name];
            }
        }
    }

    update_option('lr_brevo_city_list_ids', $city_list_mappings);
    lr_brevo_log_message('Sync complete. Created ' . $created_count . ' new lists.');

    wp_send_json_success([
        'log'      => get_transient('lr_brevo_log'),
        'mappings' => $city_list_mappings,
    ]);
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
    // Store an array with city and timestamp
    $processed[$skateName] = [
        'city'      => $cityName,
        'timestamp' => time(), // Current Unix timestamp
    ];
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

    // Get the re-sync period from options, default to 7 days.
    $options = get_option('lr_brevo_options');
    $resync_days = isset($options['resync_days']) ? (int)$options['resync_days'] : 7;
    $resync_period_seconds = $resync_days * DAY_IN_SECONDS;

    if (is_wp_error($skaters_list)) {
        echo '<p style="color: orange;">Warning: Could not fetch skaters for ' . esc_html($city_name) . '. Skipping. Reason: ' . esc_html($skaters_list->get_error_message()) . '</p>';
        return 0;
    }

    if (empty($skaters_list)) {
        echo '<p>No active skaters found for ' . esc_html($city_name) . '. Skipping.</p>';
        return 0;
    }

    echo '<p>Found ' . count($skaters_list) . ' skaters. Now finding and updating in Brevo one by one (re-syncing skaters older than ' . esc_html($resync_days) . ' days)...</p>';

    foreach ($skaters_list as $skater) {
        if (!empty($skater->skateName)) {
            if (isset($processed_skaters[$skater->skateName])) {
                $last_sync_timestamp = $processed_skaters[$skater->skateName]['timestamp'] ?? 0;
                if ((time() - $last_sync_timestamp) < $resync_period_seconds) {
                    // Don't echo here to keep the log clean, maybe add a debug mode later
                    continue; // Skip recently synced skater
                }
            }
            lr_add_skater_to_brevo_city_list($skater->skateName, $city_name);
            $skaters_processed_in_this_run++;
            usleep(200000); // 200ms pause to avoid hitting API rate limits
        }
    }
    
    echo '<p>Finished processing ' . esc_html($city_name) . '. Processed ' . $skaters_processed_in_this_run . ' new/outdated skaters.</p>';
    return $skaters_processed_in_this_run;
}

/**
 * =================================================================================
 * WP-Cron Async Queue System
 * =================================================================================
 */

// Add a custom cron schedule for the worker.
add_filter('cron_schedules', 'lr_add_cron_schedules');
function lr_add_cron_schedules($schedules) {
    $schedules['five_minutes'] = [
        'interval' => 300, // 5 minutes in seconds
        'display'  => esc_html__('Every Five Minutes'),
    ];
    return $schedules;
}

// Hook the main functions into WP-Cron actions.
add_action('lr_brevo_sync_main_event', 'lr_populate_brevo_sync_queue');
add_action('lr_brevo_sync_worker_event', 'lr_process_brevo_sync_queue');

/**
 * Populates the Brevo sync queue with all available cities.
 * This is the main task for the daily cron job.
 */
function lr_populate_brevo_sync_queue() {
    // Prevent re-queuing if a sync is already running
    if (get_option('lr_brevo_sync_queue') !== false) {
        return;
    }
    
    $locations = lr_get_location_data();
    $city_queue = [];

    if (!empty($locations)) {
        foreach ($locations as $country_data) {
            if (empty($country_data['cities'])) continue;
            foreach ($country_data['cities'] as $city_slug => $city_details) {
                // Store the full city details in the queue
                $city_queue[] = $city_details;
            }
        }
    }

    if (!empty($city_queue)) {
        update_option('lr_brevo_sync_queue', $city_queue);
        update_option('lr_brevo_sync_total_count', count($city_queue)); // Store total for progress display
        
        // Schedule the worker to start processing now
        if (!wp_next_scheduled('lr_brevo_sync_worker_event')) {
            wp_schedule_single_event(time(), 'lr_brevo_sync_worker_event');
        }
    }
}

/**
 * Processes a batch of cities from the sync queue.
 * This is the "worker" task.
 */
function lr_process_brevo_sync_queue() {
    $queue = get_option('lr_brevo_sync_queue', []);

    if (empty($queue)) {
        // Queue is empty, sync is complete.
        delete_option('lr_brevo_sync_total_count');
        return;
    }

    // Process a batch of 1 city to keep tasks short
    $batch_size = 1;
    $cities_to_process = array_slice($queue, 0, $batch_size);

    foreach ($cities_to_process as $city_details) {
        // Use the existing single city sync function.
        // Note: The echo statements in this function will not be visible,
        // they will only run in the background. We might want to add proper logging later.
        lr_run_single_city_sync($city_details);
    }

    // Remove the processed cities from the queue
    $remaining_queue = array_slice($queue, $batch_size);
    update_option('lr_brevo_sync_queue', $remaining_queue);

    // If there are more cities, schedule the next worker run
    if (!empty($remaining_queue)) {
        if (!wp_next_scheduled('lr_brevo_sync_worker_event')) {
            wp_schedule_single_event(time() + 10, 'lr_brevo_sync_worker_event'); // 10-second delay
        }
    } else {
        // Last batch was processed, clear the total count
        delete_option('lr_brevo_sync_total_count');
    }
}

/**
 * Schedules the cron events on plugin activation.
 */
function lr_activate_brevo_sync_cron() {
    // Schedule the main event to run daily
    if (!wp_next_scheduled('lr_brevo_sync_main_event')) {
        wp_schedule_event(time(), 'daily', 'lr_brevo_sync_main_event');
    }
    // Schedule the worker to run every 5 minutes
    if (!wp_next_scheduled('lr_brevo_sync_worker_event')) {
        wp_schedule_event(time(), 'five_minutes', 'lr_brevo_sync_worker_event');
    }
}

/**
 * Clears the cron events on plugin deactivation.
 */
function lr_deactivate_brevo_sync_cron() {
    wp_clear_scheduled_hook('lr_brevo_sync_main_event');
    wp_clear_scheduled_hook('lr_brevo_sync_worker_event');
    // Also clear any leftover queue data
    delete_option('lr_brevo_sync_queue');
    delete_option('lr_brevo_sync_total_count');
}

/**
 * =================================================================================
 * Dry Run & CSV Export Functions
 * =================================================================================
 */

// Hook the download trigger to admin_init
add_action('admin_init', 'lr_handle_dry_run_download');

/**
 * Checks for the dry run request and triggers the CSV download.
 */
function lr_handle_dry_run_download() {
    // This function now handles two separate POST requests: one to download, one to clear.
    if (isset($_POST['lr_brevo_download_report']) && check_admin_referer('lr_brevo_download_report_action', 'lr_brevo_download_report_nonce')) {
        
        $report_data = get_transient('lr_brevo_generated_report_data');
        
        if (empty($report_data)) {
            wp_die('Report data not found or has expired. Please generate it again.');
        }

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="brevo_dry_run_report_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        fputcsv($output, ['Skate Name', 'Proposed City', 'Brevo Email', 'Brevo ID', 'Sync Status', 'Reason']);
        
        foreach ($report_data as $row) {
            fputcsv($output, $row);
        }
        
        fclose($output);

        // Clean up after download
        delete_transient('lr_brevo_generated_report_data');
        delete_option('lr_brevo_report_status');
        
        exit;
    }
}




/**
 * Generates the report rows for a single city.
 *
 * @param array $city_details The details of the city to process.
 * @return array An array of data rows for the report.
 */
function lr_generate_dry_run_rows_for_city($city_details) {
    $report_rows = [];
    $processed_skaters = lr_get_processed_skaters();
    
    $options = get_option('lr_brevo_options');
    $resync_days = isset($options['resync_days']) ? (int)$options['resync_days'] : 7;
    $resync_period_seconds = $resync_days * DAY_IN_SECONDS;

    lr_brevo_log_message(" -> Fetching skaters for " . $city_details['name'] . "...");
    $skaters_list = lr_fetch_skaters_for_city($city_details);

    if (is_wp_error($skaters_list) || empty($skaters_list)) {
        lr_brevo_log_message(" -> No skaters found for " . $city_details['name'] . ".");
        return [];
    }

    $total_skaters = count($skaters_list);
    lr_brevo_log_message(" -> Found " . $total_skaters . " skaters. Now checking Brevo status for each...");

    $counter = 0;
    foreach ($skaters_list as $skater) {
        $counter++;
        if (empty($skater->skateName)) continue;

        $status = 'Will Sync';
        $reason = 'New or outdated skater.';
        
        if (isset($processed_skaters[$skater->skateName])) {
            $last_sync_timestamp = $processed_skaters[$skater->skateName]['timestamp'] ?? 0;
            if ((time() - $last_sync_timestamp) < $resync_period_seconds) {
                $status = 'Will Skip';
                $reason = 'Synced within the last ' . $resync_days . ' days.';
            }
        }

        $contact = lr_find_brevo_contact_by_firstname($skater->skateName);
        
        if (!$contact) {
            $status = 'Will Skip';
            $reason = 'No unique contact found in Brevo.';
        }

        $report_rows[] = [
            'skateName'     => $skater->skateName,
            'proposed_city' => $city_details['name'],
            'brevo_email'   => $contact->email ?? 'N/A',
            'brevo_id'      => $contact->id ?? 'N/A',
            'sync_status'   => $status,
            'reason'        => $reason,
        ];

        if ($counter % 10 === 0 && $counter < $total_skaters) {
            lr_brevo_log_message(" -> ...processed " . $counter . " of " . $total_skaters . " skaters in " . $city_details['name']);
        }
    }
    
    lr_brevo_log_message(" -> Finished checking all " . $total_skaters . " skaters for " . $city_details['name'] . ".");
    return $report_rows;
}

/**
 * =================================================================================
 * Simple Logging Functions
 * =================================================================================
 */

/**
 * Adds a message to the Brevo sync log.
 *
 * @param string $message The message to log.
 */
function lr_brevo_log_message($message) {
    $log = get_transient('lr_brevo_log');
    if (!is_array($log)) {
        $log = [];
    }
    // Add the new message to the beginning of the array
    array_unshift($log, '[' . date('Y-m-d H:i:s') . '] ' . $message);
    // Keep the log at a reasonable size (e.g., 100 entries)
    if (count($log) > 100) {
        $log = array_slice($log, 0, 100);
    }
    set_transient('lr_brevo_log', $log, HOUR_IN_SECONDS);
}

/**
 * Clears the Brevo sync log.
 */
function lr_clear_brevo_log() {
    delete_transient('lr_brevo_log');
}

/**
 * =================================================================================
 * AJAX Report Generation
 * =================================================================================
 */

// Hook the AJAX handlers into WordPress
add_action('wp_ajax_lr_brevo_start_report', 'lr_brevo_ajax_start_report');
add_action('wp_ajax_lr_brevo_process_report_batch', 'lr_brevo_ajax_process_report_batch');

/**
 * AJAX handler to initialize the report and send back the city queue.
 */
function lr_brevo_ajax_start_report() {
    check_ajax_referer('lr_brevo_report_nonce', 'nonce');

    // Clear any old data and set the status
    delete_transient('lr_brevo_generated_report_data');
    lr_clear_brevo_log();
    lr_brevo_log_message('Starting new AJAX dry run report generation...');

    $locations = lr_get_location_data();
    $city_queue = [];
    if (!empty($locations)) {
        foreach ($locations as $country_data) {
            if (empty($country_data['cities'])) continue;
            foreach ($country_data['cities'] as $city_slug => $city_details) {
                $city_queue[] = $city_details;
            }
        }
    }

    if (empty($city_queue)) {
        wp_send_json_error(['message' => 'No cities found to process.']);
        return;
    }
    
    lr_brevo_log_message('Created a queue of ' . count($city_queue) . ' cities to process.');
    
    // Initialize the report data transient
    set_transient('lr_brevo_generated_report_data', [], HOUR_IN_SECONDS);

    wp_send_json_success([
        'queue' => $city_queue,
        'total' => count($city_queue),
        'log'   => get_transient('lr_brevo_log')
    ]);
}

/**
 * AJAX handler to process one city from the queue.
 */
function lr_brevo_ajax_process_report_batch() {
    check_ajax_referer('lr_brevo_report_nonce', 'nonce');

    $queue = isset($_POST['queue']) ? json_decode(stripslashes($_POST['queue']), true) : [];
    if (empty($queue)) {
        lr_brevo_log_message('Finished processing all cities. Report is now complete.');
        wp_send_json_success([
            'status' => 'complete',
            'log'    => get_transient('lr_brevo_log')
        ]);
        return;
    }

    $city_to_process = array_shift($queue);
    lr_brevo_log_message('Processing city: ' . $city_to_process['name']);

    $new_rows = lr_generate_dry_run_rows_for_city($city_to_process);

    $existing_data = get_transient('lr_brevo_generated_report_data');
    if (!is_array($existing_data)) {
        $existing_data = [];
    }
    $updated_data = array_merge($existing_data, $new_rows);
    set_transient('lr_brevo_generated_report_data', $updated_data, HOUR_IN_SECONDS);

    wp_send_json_success([
        'status'    => 'processing',
        'queue'     => $queue,
        'log'       => get_transient('lr_brevo_log')
    ]);
}

/**
 * =================================================================================
 * AJAX Single Firstname Lookup
 * =================================================================================
 */

// Hook the AJAX handler into WordPress
add_action('wp_ajax_lr_brevo_test_firstname_lookup', 'lr_brevo_ajax_test_firstname_lookup');

/**
 * AJAX handler for the single contact lookup tool using FIRSTNAME.
 */
function lr_brevo_ajax_test_firstname_lookup() {
    check_ajax_referer('lr_brevo_test_lookup_nonce', 'nonce');

    $skatename = isset($_POST['skatename']) ? sanitize_text_field($_POST['skatename']) : '';

    if (empty($skatename)) {
        wp_send_json_error(['message' => 'No skatename provided.']);
        return;
    }

    lr_clear_brevo_log();
    lr_brevo_log_message('--- Starting Single Firstname Lookup Test ---');
    lr_brevo_log_message('1. Looking up contact in Brevo with FIRSTNAME: ' . $skatename);

    $contact_data = lr_find_brevo_contact_by_firstname($skatename);

    if ($contact_data) {
        lr_brevo_log_message('SUCCESS: Contact found in Brevo.');
        wp_send_json_success([
            'message' => 'SUCCESS: Contact found.',
            'contact' => $contact_data,
            'log'     => get_transient('lr_brevo_log')
        ]);
    } else {
        lr_brevo_log_message('FAILURE: No unique contact found in Brevo with that firstname.');
        wp_send_json_error([
            'message' => 'FAILURE: Contact not found in Brevo.',
            'log'     => get_transient('lr_brevo_log')
        ]);
    }
}