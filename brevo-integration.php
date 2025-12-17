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
 * Fetches a list of skaters for a specific city for the Brevo sync.
 * This wrapper calls the core function with a long maxAgeInDays for maximum reach.
 *
 * @param array $city_details The city data array from merged.json.
 * @return array|WP_Error An array of user profile objects or a WP_Error on failure.
 */
function lr_fetch_skaters_for_city($city_details) {
    // Use a long time window to capture all potential contacts for marketing.
    return lr_fetch_filtered_skaters_for_city($city_details, 365);
}

/**
 * Searches for a contact in Brevo, first by FIRSTNAME and then falling back to SKATENAME.
 *
 * @param string $skateName The skateName to search for.
 * @return object|null The contact object from Brevo if found, otherwise null.
 */
function lr_find_brevo_contact($skateName) {
    $options = get_option('lr_brevo_options');
    $brevo_api_key = $options['api_key'] ?? '';

    if (empty($brevo_api_key) || empty($skateName)) {
        return null;
    }

    // --- First Attempt: Search by FIRSTNAME ---
    lr_brevo_log_message("Attempting lookup for '$skateName' using FIRSTNAME...");
    $contact = lr_brevo_api_get_contact_by_attribute('FIRSTNAME', $skateName);

    if ($contact) {
        lr_brevo_log_message("...SUCCESS found contact using FIRSTNAME.");
        return $contact;
    }
    lr_brevo_log_message("...No unique match found using FIRSTNAME.");

    // --- Fallback Attempt: Search by SKATENAME ---
    lr_brevo_log_message("Attempting fallback lookup for '$skateName' using SKATENAME...");
    $contact = lr_brevo_api_get_contact_by_attribute('SKATENAME', $skateName);
    
    if ($contact) {
        lr_brevo_log_message("...SUCCESS found contact using SKATENAME.");
        return $contact;
    }
    lr_brevo_log_message("...No unique match found using SKATENAME.");

    return null; // Return null if both attempts fail
}

/**
 * Helper function to query the Brevo API for a contact by a specific attribute.
 *
 * @param string $attribute_name The name of the attribute to filter by (e.g., 'FIRSTNAME', 'SKATENAME').
 * @param string $attribute_value The value to match.
 * @return object|null The contact object or null if not found/ambiguous.
 */
function lr_brevo_api_get_contact_by_attribute($attribute_name, $attribute_value) {
    $options = get_option('lr_brevo_options');
    $brevo_api_key = $options['api_key'] ?? '';

    $url = 'https://api.brevo.com/v3/contacts';
    $filter_value = 'equals(' . $attribute_name . ',"' . $attribute_value . '")';
    $full_url = add_query_arg('filter', $filter_value, $url);

    $args = [
        'headers' => [
            'api-key' => $brevo_api_key,
            'Accept'  => 'application/json',
        ]
    ];

    $response = wp_remote_get($full_url, $args);
    $response_code = wp_remote_retrieve_response_code($response);
    $body_raw = wp_remote_retrieve_body($response);
    $body = json_decode($body_raw);

    if ($response_code !== 200 || !isset($body->contacts) || empty($body->contacts) || count($body->contacts) > 1) {
        return null;
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
    $contact = lr_find_brevo_contact($skateName);

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
        'log'      => get_option('lr_brevo_log', []),
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
    lr_brevo_log_message("--- Worker process started ---");
    $queue = get_option('lr_brevo_sync_queue', []);

    if (empty($queue)) {
        lr_brevo_log_message("Worker found an empty queue. Sync is likely complete. Cleaning up.");
        delete_option('lr_brevo_sync_total_count');
        return;
    }

    lr_brevo_log_message("Worker found " . count($queue) . " cities in the queue.");

    // Process a batch of 1 city to keep tasks short
    $batch_size = 1;
    $cities_to_process = array_slice($queue, 0, $batch_size);

    foreach ($cities_to_process as $city_details) {
        $city_name = $city_details['name'] ?? 'Unknown City';
        lr_brevo_log_message("Processing city: " . $city_name);
        
        // Use the existing single city sync function.
        lr_run_single_city_sync($city_details);
        
        lr_brevo_log_message("Finished processing city: " . $city_name);
    }

    // Remove the processed cities from the queue
    $remaining_queue = array_slice($queue, $batch_size);
    update_option('lr_brevo_sync_queue', $remaining_queue);
    lr_brevo_log_message(count($remaining_queue) . " cities remaining in the queue.");

    // If there are more cities, schedule the next worker run
    if (!empty($remaining_queue)) {
        if (!wp_next_scheduled('lr_brevo_sync_worker_event')) {
            wp_schedule_single_event(time() + 10, 'lr_brevo_sync_worker_event'); // 10-second delay
            lr_brevo_log_message("Scheduled next worker run in 10 seconds.");
        }
    } else {
        lr_brevo_log_message("--- Sync Complete: Last city processed. ---");
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
    // Schedule the worker to run every ten minutes as a fallback
    if (!wp_next_scheduled('lr_brevo_sync_worker_event')) {
        wp_schedule_event(time(), 'ten_minutes', 'lr_brevo_sync_worker_event');
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

        $contact = lr_find_brevo_contact($skater->skateName);
        
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
    $log = get_option('lr_brevo_log', []);
    if (!is_array($log)) {
        $log = [];
    }
    // Add the new message to the beginning of the array
    array_unshift($log, '[' . date('Y-m-d H:i:s') . '] ' . $message);
    // Keep the log at a reasonable size (e.g., 100 entries)
    if (count($log) > 100) {
        $log = array_slice($log, 0, 100);
    }
    update_option('lr_brevo_log', $log);
}

/**
 * Clears the Brevo sync log.
 */
function lr_clear_brevo_log() {
    delete_option('lr_brevo_log');
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
        'log'   => get_option('lr_brevo_log', [])
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
            'log'    => get_option('lr_brevo_log', [])
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
        'log'       => get_option('lr_brevo_log', [])
    ]);
}

/**
 * =================================================================================
 * AJAX Single Contact Lookup
 * =================================================================================
 */

// Hook the AJAX handler into WordPress
add_action('wp_ajax_lr_brevo_test_contact_lookup', 'lr_brevo_ajax_test_contact_lookup');

/**
 * AJAX handler for the single contact lookup tool.
 */
function lr_brevo_ajax_test_contact_lookup() {
    check_ajax_referer('lr_brevo_test_lookup_nonce', 'nonce');

    $skatename = isset($_POST['skatename']) ? sanitize_text_field($_POST['skatename']) : '';

    if (empty($skatename)) {
        wp_send_json_error(['message' => 'No skatename provided.']);
        return;
    }

    lr_clear_brevo_log();
    lr_brevo_log_message('--- Starting Single Contact Lookup Test ---');
    
    $contact_data = lr_find_brevo_contact($skatename);

    if ($contact_data) {
        lr_brevo_log_message('SUCCESS: Final contact found.');
        wp_send_json_success([
            'message' => 'SUCCESS: Contact found.',
            'contact' => $contact_data,
            'log'     => get_option('lr_brevo_log', [])
        ]);
    } else {
        lr_brevo_log_message('FAILURE: No unique contact found in Brevo with that skatename.');
        wp_send_json_error([
            'message' => 'FAILURE: Contact not found in Brevo.',
            'log'     => get_option('lr_brevo_log', [])
        ]);
    }
}

/**
 * =================================================================================
 * Brevo Campaign Sender Functions
 * =================================================================================
 */

/**
 * Creates and sends a Brevo email campaign.
 *
 * @param string $city_slug The slug of the city.
 * @param int    $city_update_id The ID of the city update post.
 * @param int    $blog_post_id The ID of the standard blog post.
 * @return array|WP_Error An array with a success message or a WP_Error on failure.
 */
function lr_create_and_send_brevo_campaign($city_slug, $city_update_id, $blog_post_id, $send_now = true) {
    global $wpdb;
    lr_brevo_log_message("Starting campaign creation for city: {$city_slug}. Send immediately: " . ($send_now ? 'Yes' : 'No'));

    // 1. --- Validation and Setup ---
    $options = get_option('lr_brevo_options');
    $brevo_api_key = $options['api_key'] ?? '';
    // Removed sender_id validation as we are now using name/email directly.

    if (empty($brevo_api_key)) {
        return new WP_Error('missing_api_key', 'Brevo API key is not configured.');
    }

    $city_list_ids = lr_get_city_list_ids();
    $city_details = lr_get_city_details_by_slug($city_slug);
    $city_name = $city_details['name'] ?? ucfirst($city_slug);

    if (!isset($city_list_ids[$city_name])) {
        return new WP_Error('missing_list', "No Brevo list found for the city: {$city_name}. Please sync lists first.");
    }
    $recipient_list_id = $city_list_ids[$city_name];

    // 2. --- Fetch Content ---
    $city_update = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}lr_city_updates WHERE id = %d", $city_update_id));
    $blog_post = get_post($blog_post_id);

    if (!$city_update || !$blog_post) {
        return new WP_Error('content_not_found', 'Could not retrieve the selected posts from the database.');
    }

    // 3. --- Construct Email Content ---
    $subject = "Skate News & Updates for {$city_name}!";
    $city_update_url = home_url("/" . ($city_details['country_slug'] ?? '') . "/{$city_slug}/updates/{$city_update->post_slug}/");
    $blog_post_url = get_permalink($blog_post);

    $html_content = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto;'>
            <h2>Latest Update for {$city_name}</h2>
            <h3><a href='{$city_update_url}'>{$city_update->post_title}</a></h3>
            <p>{$city_update->post_summary}</p>
            <p><a href='{$city_update_url}'>Read more...</a></p>
            <hr>
            <h2>From the Blog</h2>
            <h3><a href='{$blog_post_url}'>{$blog_post->post_title}</a></h3>
            <p>" . get_the_excerpt($blog_post) . "</p>
            <p><a href='{$blog_post_url}'>Read more...</a></p>
            <hr>
            <p style='font-size: 0.8em; color: #888; text-align: center;'>Sent by the Let's Roll Team</p>
        </div>
    ";

    // 4. --- Create Campaign via Brevo API ---
    lr_brevo_log_message("Creating campaign draft in Brevo...");
    $url = 'https://api.brevo.com/v3/emailCampaigns';
    $body = [
        'name'          => "Manual Campaign - {$city_name} - " . date('Y-m-d'),
        'subject'       => $subject,
        'htmlContent'   => $html_content,
        'sender'        => ['name' => 'Let\'s Roll Team', 'email' => 'hey@lets-roll.app'], // Use explicit name/email
        'recipients'    => ['listIds' => [$recipient_list_id]],
        'type'          => 'classic'
    ];

    $args = [
        'method'  => 'POST',
        'headers' => ['api-key' => $brevo_api_key, 'Content-Type' => 'application/json', 'Accept' => 'application/json'],
        'body'    => json_encode($body)
    ];

    $response = wp_remote_post($url, $args);
    $response_code = wp_remote_retrieve_response_code($response);
    $response_body_raw = wp_remote_retrieve_body($response);
    $response_body = json_decode($response_body_raw);

    // Always log the raw response body for debugging
    lr_brevo_log_message("Brevo API (Create Campaign) Raw Response: " . $response_body_raw);

    if ($response_code !== 201 || !isset($response_body->id)) {
        $error_message = $response_body->message ?? 'Unknown error';
        lr_brevo_log_message("ERROR: Failed to create campaign draft. API Message: " . $error_message . ". Full Response: " . $response_body_raw);
        return new WP_Error('campaign_creation_failed', 'Failed to create campaign draft in Brevo. API responded with HTTP code ' . $response_code . '. Message: ' . $error_message);
    }

    $campaign_id = $response_body->id;
    lr_brevo_log_message("Successfully created campaign draft with ID: {$campaign_id}.");

    // 5. --- Conditionally Send Campaign ---
    if ($send_now) {
        lr_brevo_log_message("Sending campaign ID: {$campaign_id}...");
        $send_url = "https://api.brevo.com/v3/emailCampaigns/{$campaign_id}/sendNow";
        $send_args = [
            'method'  => 'POST',
            'headers' => ['api-key' => $brevo_api_key, 'Accept' => 'application/json']
        ];

        $send_response = wp_remote_post($send_url, $send_args);
        $send_response_code = wp_remote_retrieve_response_code($send_response);
        $send_response_body_raw = wp_remote_retrieve_body($send_response);

        lr_brevo_log_message("Brevo API (Send Campaign) Raw Response: " . $send_response_body_raw);

        if ($send_response_code !== 204) {
            $send_error_message = json_decode($send_response_body_raw)->message ?? 'Unknown error';
            lr_brevo_log_message("ERROR: Failed to send campaign. API Message: " . $send_error_message . ". Full Response: " . $send_response_body_raw);
            return new WP_Error('campaign_send_failed', "Campaign was created as a draft (ID: {$campaign_id}), but failed to send. API responded with HTTP code " . $send_response_code . '. Message: ' . $send_error_message);
        }

        lr_brevo_log_message("SUCCESS: Campaign sent successfully.");
        return ['message' => "Campaign for {$city_name} sent successfully!"];
    } else {
        lr_brevo_log_message("SUCCESS: Campaign created as a draft.");
        return ['message' => "Campaign for {$city_name} was created as a draft (ID: {$campaign_id}) in Brevo. You can review and send it from your Brevo dashboard."];
    }
}
