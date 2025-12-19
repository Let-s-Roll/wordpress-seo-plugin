<?php
/**
 * Renders the "Brevo Sender" admin page and handles its AJAX interactions.
 *
 * This file is responsible for creating the UI that allows an administrator
 * to manually select content (a city, a city update, a blog post) and
 * send it as an email campaign via Brevo. It includes the necessary HTML,
 * CSS, and JavaScript for the form, as well as the server-side PHP functions
 * that handle the AJAX requests for populating dropdowns and triggering the
 * campaign sending process.
 *
 * @package lets-roll-seo-pages
 * @since 1.17.0
 */

if (!defined('ABSPATH')) exit; // Exit if accessed directly

/**
 * AJAX handler to get recent city updates for the sender form.
 *
 * This function is triggered via AJAX when the user selects a city in the
 * "Brevo Sender" form. It queries the custom database table for `lr_city_updates`
 * and returns a JSON object containing the 10 most recent updates for that city,
 * which are then used to populate the "Select City Update" dropdown.
 *
 * @since 1.17.0
 */
function lr_get_city_updates_for_sender_ajax() {
    check_ajax_referer('lr_get_city_updates_nonce', 'nonce');

    $city_slug = isset($_POST['city_slug']) ? sanitize_text_field($_POST['city_slug']) : '';
    if (empty($city_slug)) {
        wp_send_json_error(['message' => 'City slug not provided.']);
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'lr_city_updates';
    $updates = $wpdb->get_results($wpdb->prepare(
        "SELECT id, post_title FROM $table_name WHERE city_slug = %s ORDER BY publish_date DESC LIMIT 10",
        $city_slug
    ));

    if (empty($updates)) {
        wp_send_json_error(['message' => 'No city updates found for the selected city.']);
        return;
    }

    wp_send_json_success($updates);
}
add_action('wp_ajax_lr_get_city_updates_for_sender', 'lr_get_city_updates_for_sender_ajax');

/**
 * AJAX handler to process the campaign sending request.
 *
 * This function is the endpoint for the main "Create Campaign" button. It receives
 * the selected content IDs and options from the form, sanitizes them, and
 * then calls the core `lr_create_and_send_brevo_campaign` function to handle
 * the actual API interaction with Brevo. It returns a JSON response indicating
 * the success or failure of the operation.
 *
 * @since 1.17.0
 */
function lr_send_brevo_campaign_ajax() {
    check_ajax_referer('lr_brevo_send_campaign_nonce', 'lr_brevo_sender_nonce');

    // --- Data Sanitization ---
    $city_slug = isset($_POST['city_slug']) ? sanitize_text_field($_POST['city_slug']) : '';
    $city_update_id = isset($_POST['city_update_id']) ? absint($_POST['city_update_id']) : 0;
    $blog_post_id = isset($_POST['blog_post_id']) ? absint($_POST['blog_post_id']) : 0;
    $send_now = isset($_POST['send_now']) && $_POST['send_now'] === '1';

    // --- Validation ---
    if (empty($city_slug) || empty($city_update_id) || empty($blog_post_id)) {
        wp_send_json_error(['message' => 'Missing required fields. Please select a value for all dropdowns.']);
        return;
    }

    // --- Call the main campaign function ---
    $result = lr_create_and_send_brevo_campaign($city_slug, $city_update_id, $blog_post_id, $send_now);

    if (is_wp_error($result)) {
        wp_send_json_error(['message' => $result->get_error_message()]);
    } else {
        wp_send_json_success($result);
    }
}
add_action('wp_ajax_lr_send_brevo_campaign', 'lr_send_brevo_campaign_ajax');


/**
 * AJAX handler to initialize the bulk campaign creation process.
 * This scans all cities and builds a queue of {city_slug, city_update_id} pairs.
 * 
 * @since 1.17.5
 */
function lr_init_bulk_campaigns() {
    check_ajax_referer('lr_brevo_bulk_nonce', 'nonce');

    global $wpdb;
    $updates_table = $wpdb->prefix . 'lr_city_updates';

    // Query to get the latest update ID for each city based on PUBLISH_DATE
    // We want the most recent post, but it MUST be "recent" (e.g., within the last 45 days)
    // to avoid sending very old content if a city hasn't had an update lately.
    $query = "
        SELECT t1.city_slug, t1.id as city_update_id, t1.post_title
        FROM $updates_table t1
        JOIN (
            SELECT city_slug, MAX(publish_date) as max_date
            FROM $updates_table
            WHERE publish_date >= DATE_SUB(NOW(), INTERVAL 45 DAY)
            GROUP BY city_slug
        ) t2 ON t1.city_slug = t2.city_slug AND t1.publish_date = t2.max_date
    ";
    
    $results = $wpdb->get_results($query, ARRAY_A);

    if (empty($results)) {
        wp_send_json_error(['message' => 'No city updates found in the database.']);
        return;
    }

    wp_send_json_success([
        'queue' => $results,
        'message' => 'Found ' . count($results) . ' cities with updates. Starting batch process...'
    ]);
}
add_action('wp_ajax_lr_init_bulk_campaigns', 'lr_init_bulk_campaigns');

/**
 * AJAX handler to process a single item from the bulk queue.
 * 
 * @since 1.17.5
 */
function lr_process_bulk_campaign_item() {
    check_ajax_referer('lr_brevo_bulk_nonce', 'nonce');

    $city_slug = isset($_POST['city_slug']) ? sanitize_text_field($_POST['city_slug']) : '';
    $city_update_id = isset($_POST['city_update_id']) ? absint($_POST['city_update_id']) : 0;
    $blog_post_id = isset($_POST['blog_post_id']) ? absint($_POST['blog_post_id']) : 0;
    $send_now = isset($_POST['send_now']) && $_POST['send_now'] === 'true'; // JS sends string 'true'

    if (empty($city_slug) || empty($city_update_id) || empty($blog_post_id)) {
        wp_send_json_error(['message' => 'Missing parameters for bulk item.']);
        return;
    }

    $result = lr_create_and_send_brevo_campaign($city_slug, $city_update_id, $blog_post_id, $send_now);

    if (is_wp_error($result)) {
        $error_data = $result->get_error_data();
        $response_data = ['message' => "Failed for {$city_slug}: " . $result->get_error_message()];
        
        // Pass rate limit info if available
        if ($result->get_error_code() === 'rate_limit' && isset($error_data['retry_after'])) {
            $response_data['retry_after'] = $error_data['retry_after'];
        }
        
        wp_send_json_error($response_data);
    } else {
        wp_send_json_success(['message' => "Success for {$city_slug}: " . $result['message']]);
    }
}
add_action('wp_ajax_lr_process_bulk_campaign_item', 'lr_process_bulk_campaign_item');


/**
 * Renders the main HTML content and JavaScript for the Brevo Sender admin page.
 *
 * @since 1.17.0
 */
function lr_render_brevo_sender_page() {
    // Get data for the form selectors
    $all_locations = lr_get_location_data();
    $all_cities = [];
    if (!empty($all_locations)) {
        foreach ($all_locations as $country_data) {
            if (isset($country_data['cities']) && is_array($country_data['cities'])) {
                foreach ($country_data['cities'] as $city_slug => $city_details) {
                    $all_cities[$city_slug] = $city_details['name'];
                }
            }
        }
        asort($all_cities); // Sort cities alphabetically
    }

    $recent_blog_posts = get_posts([
        'post_type'      => 'post',
        'posts_per_page' => 10,
        'post_status'    => 'publish',
    ]);
    ?>
    <div class="wrap">
        <h1>Brevo Sender</h1>
        <p>Manually create and send a Brevo email campaign by combining a City Update with a recent Blog Post.</p>

        <!-- SINGLE CAMPAIGN CREATOR -->
        <div class="postbox">
            <h2 class="hndle ui-sortable-handle" style="padding: 10px;"><span>Manual Campaign Creator</span></h2>
            <div class="inside">
                <div id="lr-sender-container" style="display: flex; gap: 20px;">
                    <!-- Form Section -->
                    <div id="lr-sender-form-wrap" style="flex: 1;">
                        <form id="lr-brevo-sender-form">
                            <?php wp_nonce_field('lr_brevo_send_campaign_nonce', 'lr_brevo_sender_nonce'); ?>

                            <!-- City Selector -->
                            <table class="form-table">
                                <tr valign="top">
                                    <th scope="row"><label for="lr-city-selector">Select City</label></th>
                                    <td>
                                        <select id="lr-city-selector" name="city_slug" style="width: 100%;">
                                            <option value="">-- Choose a City --</option>
                                            <?php foreach ($all_cities as $slug => $name) : ?>
                                                <option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($name); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <p class="description">Select the city to target. This will determine the recipient list in Brevo.</p>
                                    </td>
                                </tr>

                                <!-- City Update Selector (Populated by AJAX) -->
                                <tr valign="top">
                                    <th scope="row"><label for="lr-city-update-selector">Select City Update</label></th>
                                    <td>
                                        <select id="lr-city-update-selector" name="city_update_id" style="width: 100%;" disabled>
                                            <option value="">-- Select a city first --</option>
                                        </select>
                                        <p class="description">Choose the automated City Update post to feature in the campaign.</p>
                                    </td>
                                </tr>

                                <!-- Blog Post Selector -->
                                <tr valign="top">
                                    <th scope="row"><label for="lr-blog-post-selector">Select Blog Post</label></th>
                                    <td>
                                        <select id="lr-blog-post-selector" name="blog_post_id" style="width: 100%;">
                                            <option value="">-- Choose a Blog Post --</option>
                                            <?php foreach ($recent_blog_posts as $post) : ?>
                                                <option value="<?php echo esc_attr($post->ID); ?>"><?php echo esc_html($post->post_title); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <p class="description">Choose a recent blog post to include in the campaign.</p>
                                    </td>
                                </tr>

                                <!-- Send Immediately Checkbox -->
                                <tr valign="top">
                                    <th scope="row">Send Options</th>
                                    <td>
                                        <fieldset>
                                            <label for="lr-send-immediately">
                                                <input name="send_now" type="checkbox" id="lr-send-immediately" value="1" />
                                                <span>Send Immediately</span>
                                            </label>
                                            <p class="description">If checked, the campaign will be sent immediately. If unchecked, it will be saved as a draft in Brevo for you to review and send later.</p>
                                        </fieldset>
                                    </td>
                                </tr>
                            </table>

                            <?php submit_button('Create Campaign', 'primary', 'lr-send-campaign-btn', true, ['disabled' => 'disabled']); ?>
                        </form>
                    </div>

                    <!-- Log/Status Section -->
                    <div id="lr-sender-log-wrap" style="flex: 1; background: #f7f7f7; border: 1px solid #ccc; padding: 10px; height: 400px; overflow-y: auto; font-family: monospace; font-size: 12px;">
                        <p><strong>Log:</strong></p>
                        <div id="lr-sender-log">Please fill out the form and click "Create Campaign".</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- BULK CAMPAIGN CREATOR -->
        <div class="postbox" style="margin-top: 20px;">
            <h2 class="hndle ui-sortable-handle" style="padding: 10px;"><span>Bulk Campaign Creator (All Cities)</span></h2>
            <div class="inside">
                <p>This tool will automatically create a campaign for <strong>every city</strong> that has a published City Update. It will use the <strong>latest update</strong> for each city.</p>
                
                <form id="lr-brevo-bulk-form">
                    <table class="form-table">
                        <tr valign="top">
                            <th scope="row"><label for="lr-bulk-blog-post">Feature Blog Post</label></th>
                            <td>
                                <select id="lr-bulk-blog-post" name="bulk_blog_post_id" style="width: 100%;">
                                    <option value="">-- Choose a Blog Post --</option>
                                    <?php foreach ($recent_blog_posts as $post) : ?>
                                        <option value="<?php echo esc_attr($post->ID); ?>"><?php echo esc_html($post->post_title); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">This blog post will be featured in <strong>all</strong> generated campaigns.</p>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">Bulk Options</th>
                            <td>
                                <fieldset>
                                    <label for="lr-bulk-send-immediately">
                                        <input name="bulk_send_now" type="checkbox" id="lr-bulk-send-immediately" value="1" />
                                        <span>Send All Immediately (Warning: Use with caution!)</span>
                                    </label>
                                    <p class="description">Default is Draft mode. Check this only if you are sure you want to blast emails to all lists immediately.</p>
                                </fieldset>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button('Generate Campaigns for All Cities', 'secondary', 'lr-bulk-campaign-btn', true, ['disabled' => 'disabled']); ?>
                </form>

                <div id="lr-bulk-progress-wrap" style="display: none; margin-top: 15px;">
                    <div style="background: #f0f0f1; border: 1px solid #ccc; height: 20px; border-radius: 10px; overflow: hidden;">
                        <div id="lr-bulk-progress-bar" style="background: #2271b1; width: 0%; height: 100%; transition: width 0.3s;"></div>
                    </div>
                    <p id="lr-bulk-status-text">Ready to start.</p>
                </div>
            </div>
        </div>

    </div>

    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // --- MANUAL SENDER LOGIC ---
        const citySelector = $('#lr-city-selector');
        const cityUpdateSelector = $('#lr-city-update-selector');
        const sendButton = $('#lr-send-campaign-btn');
        const logDiv = $('#lr-sender-log');

        function updateButtonState() {
            const city = citySelector.val();
            const update = cityUpdateSelector.val();
            const post = $('#lr-blog-post-selector').val();
            if (city && update && post) {
                sendButton.prop('disabled', false);
            } else {
                sendButton.prop('disabled', true);
            }
        }

        function logMessage(message) {
            logDiv.prepend('<p style="margin: 0; padding-bottom: 5px; border-bottom: 1px solid #eee;">' + message + '</p>');
        }

        // 1. Handle City Selection Change (AJAX to get City Updates)
        citySelector.on('change', function() {
            const citySlug = $(this).val();
            cityUpdateSelector.prop('disabled', true).html('<option value="">-- Loading... --</option>');
            updateButtonState();

            if (!citySlug) {
                cityUpdateSelector.html('<option value="">-- Select a city first --</option>');
                return;
            }

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'lr_get_city_updates_for_sender',
                    city_slug: citySlug,
                    nonce: '<?php echo wp_create_nonce("lr_get_city_updates_nonce"); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        let options = '<option value="">-- Select a City Update --</option>';
                        response.data.forEach(function(update) {
                            options += '<option value="' + update.id + '">' + update.post_title + '</option>';
                        });
                        cityUpdateSelector.html(options).prop('disabled', false);
                    } else {
                        cityUpdateSelector.html('<option value="">-- No updates found --</option>');
                        logMessage('Error: ' + response.data.message);
                    }
                },
                error: function() {
                    cityUpdateSelector.html('<option value="">-- AJAX Error --</option>');
                    logMessage('Error: AJAX request failed.');
                }
            });
        });

        // 2. Handle Form Field Changes
        cityUpdateSelector.on('change', updateButtonState);
        $('#lr-blog-post-selector').on('change', updateButtonState);

        // 3. Handle Manual Form Submission
        $('#lr-brevo-sender-form').on('submit', function(e) {
            e.preventDefault();
            sendButton.prop('disabled', true);
            logDiv.html(''); // Clear log on new submission
            logMessage('Starting campaign creation process...');

            let sendNow = $('#lr-send-immediately').is(':checked');
            let formData = $(this).serialize() + '&send_now=' + sendNow;

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: formData + '&action=lr_send_brevo_campaign', // Add main action
                success: function(response) {
                    if (response.success) {
                        logMessage('<strong>SUCCESS:</strong> ' + response.data.message);
                    } else {
                        logMessage('<strong>ERROR:</strong> ' + response.data.message);
                    }
                    updateButtonState(); // Re-enable button if needed
                },
                error: function() {
                    logMessage('<strong>CRITICAL ERROR:</strong> The AJAX request failed completely.');
                    updateButtonState();
                }
            });
        });

        // --- BULK SENDER LOGIC ---
        const bulkButton = $('#lr-bulk-campaign-btn');
        const bulkBlogPost = $('#lr-bulk-blog-post');
        const bulkProgressBar = $('#lr-bulk-progress-bar');
        const bulkStatusText = $('#lr-bulk-status-text');
        const bulkProgressWrap = $('#lr-bulk-progress-wrap');
        
        let bulkQueue = [];
        let bulkTotal = 0;
        let bulkProcessed = 0;

        bulkBlogPost.on('change', function() {
            if ($(this).val()) {
                bulkButton.prop('disabled', false);
            } else {
                bulkButton.prop('disabled', true);
            }
        });

        $('#lr-brevo-bulk-form').on('submit', function(e) {
            e.preventDefault();
            if (!confirm('Are you sure you want to generate campaigns for ALL cities? This process may take a few minutes.')) {
                return;
            }

            bulkButton.prop('disabled', true);
            bulkProgressWrap.show();
            bulkProgressBar.css('width', '0%');
            bulkStatusText.text('Initializing bulk process...');
            logDiv.html(''); 
            logMessage('<strong>Starting Bulk Campaign Generation...</strong>');

            // Step 1: Init (Get Queue)
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'lr_init_bulk_campaigns',
                    nonce: '<?php echo wp_create_nonce("lr_brevo_bulk_nonce"); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        bulkQueue = response.data.queue;
                        bulkTotal = bulkQueue.length;
                        bulkProcessed = 0;
                        logMessage(response.data.message);
                        processNextBulkItem();
                    } else {
                        logMessage('<strong>Init Error:</strong> ' + response.data.message);
                        bulkStatusText.text('Error: ' + response.data.message);
                        bulkButton.prop('disabled', false);
                    }
                },
                error: function() {
                    logMessage('<strong>Critical Error:</strong> Init request failed.');
                    bulkButton.prop('disabled', false);
                }
            });
        });

        function processNextBulkItem() {
            if (bulkQueue.length === 0) {
                bulkStatusText.text('Bulk process complete! ' + bulkTotal + ' campaigns processed.');
                logMessage('<strong>Bulk process finished.</strong>');
                bulkButton.prop('disabled', false);
                return;
            }

            let item = bulkQueue.shift(); // Get next city
            let blogPostId = bulkBlogPost.val();
            let sendNow = $('#lr-bulk-send-immediately').is(':checked');

            bulkStatusText.text('Processing city: ' + item.city_slug + ' (' + (bulkProcessed + 1) + '/' + bulkTotal + ')...');

                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'lr_process_bulk_campaign_item',
                                nonce: '<?php echo wp_create_nonce("lr_brevo_bulk_nonce"); ?>',
                                city_slug: item.city_slug,
                                city_update_id: item.city_update_id,
                                blog_post_id: blogPostId,
                                send_now: sendNow
                            },
                            success: function(response) {
                                if (response.success) {
                                    logMessage('<span style="color:green;">[OK]</span> ' + item.city_slug + ': ' + response.data.message);
                                    bulkProcessed++;
                                    let percent = Math.round((bulkProcessed / bulkTotal) * 100);
                                    bulkProgressBar.css('width', percent + '%');
                                    
                                    setTimeout(function() { processNextBulkItem(); }, 1000); // Standard 1s delay
                                } else {
                                    // --- RATE LIMIT HANDLING ---
                                    if (response.data && response.data.retry_after) {
                                        let waitSeconds = parseInt(response.data.retry_after) + 2; // Add 2s buffer
                                        logMessage('<span style="color:orange;">[RATE LIMIT]</span> ' + item.city_slug + ': Hit limit. Pausing for ' + waitSeconds + ' seconds...');
                                        
                                        // Re-queue the item to try again
                                        bulkQueue.unshift(item);
                                        
                                        // Countdown Timer UI
                                        let remaining = waitSeconds;
                                        bulkStatusText.text('Rate limit hit. Resuming in ' + remaining + 's...');
                                        
                                        let countdown = setInterval(function() {
                                            remaining--;
                                            if (remaining <= 0) {
                                                clearInterval(countdown);
                                                bulkStatusText.text('Resuming...');
                                                processNextBulkItem(); // Resume loop
                                            } else {
                                                bulkStatusText.text('Rate limit hit. Resuming in ' + remaining + 's...');
                                            }
                                        }, 1000);
                                        
                                        return; // Stop here, the interval will resume
                                    }
            
                                    // Normal Failure
                                    logMessage('<span style="color:red;">[FAIL]</span> ' + item.city_slug + ': ' + response.data.message);
                                    bulkProcessed++;
                                    let percent = Math.round((bulkProcessed / bulkTotal) * 100);
                                    bulkProgressBar.css('width', percent + '%');
                                    
                                    setTimeout(function() { processNextBulkItem(); }, 1000);
                                }
                            },
                            error: function() {
                                logMessage('<span style="color:red;">[ERROR]</span> ' + item.city_slug + ': AJAX request failed.'); 
                                bulkProcessed++;
                                setTimeout(function() { processNextBulkItem(); }, 1000);
                            }
                        });
                    }
                });
                </script>    <?php
}