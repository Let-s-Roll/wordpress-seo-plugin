<?php

if (!defined('ABSPATH')) exit; // Exit if accessed directly

/**
 * Renders the Brevo Sync admin page with the new AJAX report generator.
 */
function lr_render_brevo_sync_page() {
    // --- FORM HANDLERS (for non-AJAX actions) ---
    if (isset($_POST['lr_save_brevo_settings']) && check_admin_referer('lr_brevo_save_settings_action', 'lr_brevo_save_settings_nonce')) {
        $options = get_option('lr_brevo_options', []);
        $options['api_key'] = sanitize_text_field($_POST['brevo_api_key']);
        $options['list_folder_id'] = intval($_POST['brevo_list_folder_id']);
        $options['sender_id'] = intval($_POST['brevo_sender_id']);
        $options['template_id'] = intval($_POST['brevo_template_id']);
        $options['test_template_id'] = intval($_POST['brevo_test_template_id']);
        $options['resync_days'] = intval($_POST['brevo_resync_days']);
        update_option('lr_brevo_options', $options);
        echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
    }

    // Handle Manual Sync Control
    if (isset($_POST['lr_brevo_queue_sync']) && check_admin_referer('lr_brevo_manual_sync_action', 'lr_brevo_manual_sync_nonce')) {
        lr_populate_brevo_sync_queue();
        echo '<div class="notice notice-info is-dismissible"><p>Sync has been queued. The background worker will start processing shortly.</p></div>';
    }
    if (isset($_POST['lr_brevo_cancel_sync']) && check_admin_referer('lr_brevo_manual_sync_action', 'lr_brevo_manual_sync_nonce')) {
        delete_option('lr_brevo_sync_queue');
        delete_option('lr_brevo_sync_total_count');
        wp_clear_scheduled_hook('lr_brevo_sync_worker_event');
        if (!wp_next_scheduled('lr_brevo_sync_worker_event')) {
            wp_schedule_event(time(), 'five_minutes', 'lr_brevo_sync_worker_event');
        }
        echo '<div class="notice notice-warning is-dismissible"><p>Sync has been cancelled.</p></div>';
    }
    if (isset($_POST['lr_brevo_process_batch']) && check_admin_referer('lr_brevo_manual_sync_action', 'lr_brevo_manual_sync_nonce')) {
        echo '<div class="notice notice-info is-dismissible"><p>Manually processing one batch...</p></div>';
        lr_process_brevo_sync_queue();
    }
    // ... other non-AJAX handlers like log clearing can remain ...
    ?>
    <div class="wrap">
        <h1>Brevo Skater Location Sync</h1>
        <p>This tool enriches Brevo contacts with location data from the Let's Roll app.</p>

        <hr>
        
        <h2>Settings</h2>
        <form method="post" action="">
            <?php wp_nonce_field('lr_brevo_save_settings_action', 'lr_brevo_save_settings_nonce'); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><label for="brevo_api_key">Brevo API Key</label></th>
                    <td><input type="password" id="brevo_api_key" name="brevo_api_key" value="<?php echo esc_attr(get_option('lr_brevo_options')['api_key'] ?? ''); ?>" style="width: 300px;" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="brevo_list_folder_id">Brevo List Folder ID</label></th>
                    <td>
                        <input type="number" id="brevo_list_folder_id" name="brevo_list_folder_id" value="<?php echo esc_attr(get_option('lr_brevo_options')['list_folder_id'] ?? 31); ?>" style="width: 100px;" />
                        <p class="description">The ID of the folder in Brevo where city lists will be created (e.g., 31 for "Cities").</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="brevo_sender_id">Brevo Sender ID</label></th>
                    <td>
                        <input type="number" id="brevo_sender_id" name="brevo_sender_id" value="<?php echo esc_attr(get_option('lr_brevo_options')['sender_id'] ?? ''); ?>" style="width: 100px;" />
                        <p class="description">The numeric ID of the validated sender in your Brevo account to be used for campaigns.</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="brevo_template_id">Campaign Template ID</label></th>
                    <td>
                        <input type="number" id="brevo_template_id" name="brevo_template_id" value="<?php echo esc_attr(get_option('lr_brevo_options')['template_id'] ?? 62); ?>" style="width: 100px;" />
                        <p class="description">The numeric ID of the Brevo email template to use for campaigns.</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="brevo_test_template_id">Test Campaign Template ID</label></th>
                    <td>
                        <input type="number" id="brevo_test_template_id" name="brevo_test_template_id" value="<?php echo esc_attr(get_option('lr_brevo_options')['test_template_id'] ?? 0); ?>" style="width: 100px;" />
                        <p class="description">Optional: Use this template ID for testing purposes. If 0, the main Campaign Template ID is used.</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="brevo_resync_days">Re-sync Skaters After (days)</label></th>
                    <td>
                        <input type="number" id="brevo_resync_days" name="brevo_resync_days" value="<?php echo esc_attr(get_option('lr_brevo_options')['resync_days'] ?? 7); ?>" style="width: 100px;" min="1" />
                        <p class="description">Skaters will only be synced again if their last sync was more than this many days ago.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Save Settings', 'primary', 'lr_save_brevo_settings'); ?>
        </form>

        <hr>

        <h2>City List Management</h2>
        <div class="lr-status-box">
            <p>This tool will check for a corresponding contact list in Brevo for every city in the plugin. If a list doesn't exist, it will be created automatically. This is a necessary first step before skaters can be added to lists.</p>
            <div id="lr-city-list-mappings">
                <p><strong>Current Mappings:</strong> Not loaded.</p>
                <!-- Mappings will be loaded here via AJAX -->
            </div>
        </div>
        <div class="lr-controls-form">
            <button id="lr-sync-city-lists-btn" class="button button-secondary">Sync City Lists</button>
        </div>

        <hr>

        

                <h2>Sync Status & Control</h2>

                <div class="lr-status-box">

                    <?php 

                    $is_sync_running = get_option('lr_brevo_sync_queue', false) !== false;

                    if ($is_sync_running) : 

                        $sync_queue = get_option('lr_brevo_sync_queue', []);

                        $total_count = get_option('lr_brevo_sync_total_count', 0);

                        $remaining_count = count($sync_queue);

                        $processed_count = $total_count - $remaining_count;

                        $progress_percentage = $total_count > 0 ? round(($processed_count / $total_count) * 100) : 0;

                    ?>

                        <p><strong>Status:</strong> <span style="color: green;">Sync in progress...</span></p>

                        <p><strong>Progress:</strong> <?php echo esc_html($processed_count); ?> / <?php echo esc_html($total_count); ?> cities processed.</p>

                        <div class="lr-progress-bar"><div style="width: <?php echo $progress_percentage; ?>%;"></div></div>

                    <?php else : ?>

                        <p><strong>Status:</strong> <span style="color: #888;">Idle</span></p>

                        <p>The main sync is not currently running.</p>

                    <?php endif; ?>

                    <p><strong>Next Scheduled Sync:</strong> <?php $ts = wp_next_scheduled('lr_brevo_sync_main_event'); echo $ts ? get_date_from_gmt(date('Y-m-d H:i:s', $ts), 'F j, Y g:i a') : 'Not scheduled.'; ?></p>

                </div>

                <form method="post" action="" class="lr-controls-form">

                    <?php wp_nonce_field('lr_brevo_manual_sync_action', 'lr_brevo_manual_sync_nonce'); ?>

                    <?php if ($is_sync_running) : ?>
                        <?php submit_button('Manually Process Next Batch', 'secondary', 'lr_brevo_process_batch', ['style' => 'margin-right: 10px;']); ?>
                        <?php submit_button('Cancel Sync', 'delete', 'lr_brevo_cancel_sync'); ?>

                    <?php else : ?>

                        <?php submit_button('Queue Full Sync Now', 'primary', 'lr_brevo_queue_sync'); ?>

                    <?php endif; ?>

                </form>

        

                <hr>

        <h2>Dry Run Report</h2>
        <div class="lr-status-box">
            <p>Generate a CSV report to see what the sync will do without making any changes in Brevo. The process runs in your browser, so please keep this tab open until it completes.</p>
            <div id="lr-report-progress-bar-container" style="display: none;">
                <p><strong>Status:</strong> <span id="lr-report-status-text" style="color: orange;">Generating...</span></p>
                <div class="lr-progress-bar"><div id="lr-report-progress-bar"></div></div>
                <p><span id="lr-report-processed-count">0</span> / <span id="lr-report-total-count">0</span> cities processed.</p>
            </div>
        </div>
        <div class="lr-controls-form">
            <button id="lr-generate-report-btn" class="button button-primary">Generate Report</button>
            <form id="lr-download-report-form" method="post" action="" style="display: none; margin-left: 10px;">
                <?php wp_nonce_field('lr_brevo_download_report_action', 'lr_brevo_download_report_nonce'); ?>
                <?php submit_button('Download Report (CSV)', 'secondary', 'lr_brevo_download_report'); ?>
            </form>
        </div>

        <hr>

        <h2>Single Contact Lookup</h2>
        <div class="lr-status-box">
            <p>Use this tool to test the Brevo API by looking up a single contact by their Let's Roll skateName in the Brevo FIRSTNAME field.</p>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><label for="lr-skatename-lookup">Firstname (Skatename)</label></th>
                    <td><input type="text" id="lr-skatename-lookup" style="width: 300px;" /></td>
                </tr>
            </table>
        </div>
        <div class="lr-controls-form">
            <button id="lr-test-lookup-btn" class="button button-secondary">Test Lookup</button>
        </div>

        <hr>

        <h2>Activity Log</h2>
        <div id="lr-brevo-log-viewer">
            <textarea readonly id="lr-log-textarea" style="width: 100%; height: 250px; background-color: #f7f7f7; font-family: monospace; font-size: 12px;"><?php
                $log_entries = get_option('lr_brevo_log', []);
                if (!empty($log_entries)) {
                    echo esc_textarea(implode("\n", $log_entries));
                } else {
                    echo 'Log is empty.';
                }
            ?></textarea>
        </div>
    </div>

    <style>
        .lr-status-box { background-color: #fff; border: 1px solid #ccd0d4; padding: 1px 20px; margin-top: 15px; }
        .lr-progress-bar { background-color: #eee; border: 1px solid #ccc; height: 24px; width: 100%; }
        .lr-progress-bar div { background-color: #2271b1; height: 100%; width: 0%; }
        .lr-controls-form { margin-top: 15px; }
    </style>

    <script type="text/javascript">
        jQuery(document).ready(function($) {
            // --- City List Sync ---
            $('#lr-sync-city-lists-btn').on('click', function() {
                var btn = $(this);
                btn.prop('disabled', true).text('Syncing...');
                updateLog('--- Starting City List Sync ---');

                var data = {
                    'action': 'lr_brevo_sync_city_lists',
                    'nonce': '<?php echo wp_create_nonce('lr_brevo_sync_city_lists_nonce'); ?>'
                };

                $.post(ajaxurl, data, function(response) {
                    if (response.success) {
                        updateLog(response.data.log);
                        updateCityListMappings(response.data.mappings);
                    } else {
                        updateLog('Error: ' + response.data.message);
                    }
                    btn.prop('disabled', false).text('Sync City Lists');
                });
            });

            function updateCityListMappings(mappings) {
                var container = $('#lr-city-list-mappings');
                if (mappings) {
                    var html = '<p><strong>Current Mappings:</strong></p><ul style="max-height: 200px; overflow-y: auto; background: #f9f9f9; border: 1px solid #ddd; padding: 10px;">';
                    for (var city in mappings) {
                        html += '<li><strong>' + city + ':</strong> ' + mappings[city] + '</li>';
                    }
                    html += '</ul>';
                    container.html(html);
                } else {
                    container.html('<p><strong>Current Mappings:</strong> No mappings found.</p>');
                }
            }

            // --- Single Lookup ---
            $('#lr-test-lookup-btn').on('click', function() {
                var btn = $(this);
                var skatename = $('#lr-skatename-lookup').val();

                if (!skatename) {
                    alert('Please enter a skatename.');
                    return;
                }

                btn.prop('disabled', true).text('Looking up...');
                updateLog('--- Starting Single Lookup ---');
                updateLog('Looking up: ' + skatename);

                var data = {
                    'action': 'lr_brevo_test_contact_lookup',
                    'nonce': '<?php echo wp_create_nonce('lr_brevo_test_lookup_nonce'); ?>',
                    'skatename': skatename
                };

                $.post(ajaxurl, data, function(response) {
                    if (response.success) {
                        updateLog(response.data.log);
                        var contact = response.data.contact;
                        var formattedContact = JSON.stringify(contact, null, 2);
                        updateLog("--- Contact Found --- \n" + formattedContact);
                    } else {
                        updateLog(response.data.log);
                    }
                    btn.prop('disabled', false).text('Test Lookup');
                });
            });

            // --- Dry Run Report ---
            var reportQueue = [];
            var totalCities = 0;
            var processedCities = 0;

            $('#lr-generate-report-btn').on('click', function() {
                var btn = $(this);
                btn.prop('disabled', true).text('Starting...');
                
                $('#lr-report-progress-bar-container').show();
                $('#lr-download-report-form').hide();
                updateLog('--- Starting Dry Run Report ---');

                var data = {
                    'action': 'lr_brevo_start_report',
                    'nonce': '<?php echo wp_create_nonce('lr_brevo_report_nonce'); ?>'
                };

                $.post(ajaxurl, data, function(response) {
                    if (response.success) {
                        reportQueue = response.data.queue;
                        totalCities = response.data.total;
                        processedCities = 0;
                        updateLog(response.data.log);
                        $('#lr-report-total-count').text(totalCities);
                        btn.text('Generating...');
                        processNextBatch();
                    } else {
                        updateLog('Error: ' + response.data.message);
                        btn.prop('disabled', false).text('Generate Report');
                    }
                });
            });

            function processNextBatch() {
                if (reportQueue.length === 0) {
                    $('#lr-report-status-text').text('Complete!').css('color', 'green');
                    $('#lr-generate-report-btn').prop('disabled', false).text('Generate Report');
                    $('#lr-download-report-form').show();
                    return;
                }

                var data = {
                    'action': 'lr_brevo_process_report_batch',
                    'nonce': '<?php echo wp_create_nonce('lr_brevo_report_nonce'); ?>',
                    'queue': JSON.stringify(reportQueue)
                };

                $.post(ajaxurl, data, function(response) {
                    if (response.success) {
                        reportQueue = response.data.queue;
                        processedCities = totalCities - reportQueue.length;
                        
                        updateLog(response.data.log);
                        updateProgressBar();

                        if (response.data.status === 'processing') {
                            processNextBatch();
                        } else { // complete
                            $('#lr-report-status-text').text('Complete!').css('color', 'green');
                            $('#lr-generate-report-btn').prop('disabled', false).text('Generate Report');
                            $('#lr-download-report-form').show();
                        }
                    } else {
                        updateLog('An error occurred. Please check the server logs.');
                        $('#lr-generate-report-btn').prop('disabled', false).text('Generate Report');
                    }
                });
            }

            function updateProgressBar() {
                var percentage = (processedCities / totalCities) * 100;
                $('#lr-report-progress-bar').css('width', percentage + '%');
                $('#lr-report-processed-count').text(processedCities);
            }

            // --- Shared Functions ---
            function updateLog(logEntries) {
                var textarea = $('#lr-log-textarea');
                var currentLog = textarea.val();
                if (currentLog === 'Log is empty.') {
                    currentLog = '';
                }

                var newContent;
                if (Array.isArray(logEntries)) {
                    newContent = logEntries.join("\n");
                } else {
                    newContent = logEntries;
                }
                
                textarea.val(newContent + "\n" + currentLog);
            }
        });
    </script>
    <?php
}

        

        
