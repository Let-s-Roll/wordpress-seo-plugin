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

    <script type="text/javascript">
    jQuery(document).ready(function($) {
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

        // 3. Handle Form Submission
        $('#lr-brevo-sender-form').on('submit', function(e) {
            e.preventDefault();
            sendButton.prop('disabled', true);
            logDiv.html(''); // Clear log on new submission
            logMessage('Starting campaign creation process...');

            // We need to include the checkbox value in the serialized data correctly.
            // A simple .serialize() won't include unchecked checkboxes.
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
    });
    </script>
    <?php
}