<?php
/**
 * Handles the admin settings page for the Let's Roll SEO Plugin.
 */

/**
 * Handles the sitemap generation form submission.
 * This is hooked to 'admin_init' to ensure it runs after user permissions are loaded.
 */
function lr_handle_sitemap_generation() {
    if ( isset( $_POST['lr_action'] ) && $_POST['lr_action'] === 'generate_sitemap_csv' ) {
        // Security checks
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'You do not have sufficient permissions to perform this action.' );
        }
        check_admin_referer( 'lr_generate_sitemap' );

        $locations = lr_get_location_data();
        $urls = [];

        if (!empty($locations)) {
            $urls[] = home_url('/explore/'); // Add the main explore page

            foreach ($locations as $country_slug => $country_data) {
                $urls[] = home_url('/' . $country_slug . '/'); // Add Country URL

                if (!empty($country_data['cities'])) {
                    foreach ($country_data['cities'] as $city_slug => $city_data) {
                        $urls[] = home_url('/' . $country_slug . '/' . $city_slug . '/'); // Add City URL
                        // Add the three detail list pages
                        $urls[] = home_url('/' . $country_slug . '/' . $city_slug . '/skatespots/');
                        $urls[] = home_url('/' . $country_slug . '/' . $city_slug . '/events/');
                        $urls[] = home_url('/' . $country_slug . '/' . $city_slug . '/skaters/');
                    }
                }
            }
        }

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="aioseo-sitemap.csv"');
        $output = fopen('php://output', 'w');
        
        // Add the header row to match the AIOSEO sample format.
        fputcsv($output, ['Page URL', 'Priority', 'Frequency', 'Last Modified']);
        $today = date('m/d/Y');

        foreach ($urls as $url) {
            fputcsv($output, [$url, '0.7', 'weekly', $today]);
        }

        fclose($output);
        exit;
    }
}
add_action('admin_init', 'lr_handle_sitemap_generation');




/**
 * Registers the settings fields for the options page.
 */
function lr_settings_init() {
    register_setting('lr_options_group', 'lr_options');

    add_settings_section('lr_api_section', 'API Credentials', null, 'lr_options_group');
    add_settings_field('lr_api_email', 'API Email', 'lr_api_email_render', 'lr_options_group', 'lr_api_section');
    add_settings_field('lr_api_pass', 'API Password', 'lr_api_pass_render', 'lr_options_group', 'lr_api_section');
    add_settings_field('lr_gemini_api_key', 'Gemini API Key', 'lr_gemini_api_key_render', 'lr_options_group', 'lr_api_section');
    add_settings_field('lr_gemini_model', 'AI Model', 'lr_gemini_model_render', 'lr_options_group', 'lr_api_section');

    // New Section for Testing Mode
    add_settings_section('lr_testing_section', 'Development & Testing', null, 'lr_options_group');
    add_settings_field('lr_testing_mode', 'Testing Mode', 'lr_testing_mode_render', 'lr_options_group', 'lr_testing_section');
}
add_action('admin_init', 'lr_settings_init');


// --- Render Functions for Settings Fields ---

function lr_api_email_render() {
    $options = get_option('lr_options');
    echo "<input type='text' name='lr_options[api_email]' value='" . esc_attr($options['api_email'] ?? '') . "' style='width: 300px;'>";
}

function lr_api_pass_render() {
    $options = get_option('lr_options');
    echo "<input type='password' name='lr_options[api_pass]' value='" . esc_attr($options['api_pass'] ?? '') . "' style='width: 300px;'>";
}

function lr_gemini_api_key_render() {
    $options = get_option('lr_options');
    echo "<input type='password' name='lr_options[gemini_api_key]' value='" . esc_attr($options['gemini_api_key'] ?? '') . "' style='width: 300px;'>";
    echo '<p class="description">Enter your API key for the Gemini AI content generation.</p>';
}

function lr_gemini_model_render() {
    $options = get_option('lr_options');
    $api_key = $options['gemini_api_key'] ?? '';
    $selected_model = $options['gemini_model'] ?? '';

    if (empty($api_key)) {
        echo '<p style="color: red;">Please enter and save your Gemini API Key above to see the list of available models.</p>';
        return;
    }

    $models = lr_get_gemini_models($api_key);

    if (is_wp_error($models)) {
        echo '<p style="color: red;"><strong>Error:</strong> Could not fetch models. ' . esc_html($models->get_error_message()) . '</p>';
        return;
    }

    echo "<select name='lr_options[gemini_model]' style='width: 300px;'>";
    echo "<option value=''>-- Select a Model --</option>";
    foreach ($models as $model) {
        $selected = selected($selected_model, $model, false);
        echo "<option value='" . esc_attr($model) . "' " . $selected . ">" . esc_html($model) . "</option>";
    }
    echo "</select>";
    echo '<p class="description">Select the Gemini model to use for content generation.</p>';
}



// New Render Function for the Checkbox
function lr_testing_mode_render() {
    $options = get_option('lr_options');
    $checked = isset($options['testing_mode']) && $options['testing_mode'] === '1' ? 'checked' : '';
    echo "<input type='checkbox' name='lr_options[testing_mode]' value='1' " . $checked . ">";
    echo '<p class="description">When checked, the plugin will bypass all caching (transients). This is useful for testing but should be disabled on a live site.</p>';
}


/**
 * Renders the main HTML for the settings page.
 */
function lr_options_page_html() {
    if (!current_user_can('manage_options')) {
        return;
    }
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <p>Manage the settings for the Let's Roll dynamic page generation plugin.</p>
        
        <form action="options.php" method="post">
            <?php
            settings_fields('lr_options_group');
            do_settings_sections('lr_options_group');
            ?>

            <div style='padding: 10px; background-color: #f0f6fc; border-left: 4px solid #2196F3; margin-bottom: 20px;'>
                <p><strong>Notice:</strong> Location data is now managed automatically from the <code>country_data/merged.json</code> file within the plugin directory. Changes to this file will be reflected on the site directly.</p>
            </div>

            <?php
            submit_button('Save Settings');
            ?>
        </form>

        <hr>

        <h2>Sitemap Generation</h2>
        <p>Click the button below to generate a CSV file of all dynamic country, city, and detail list pages. You can then import this file into your SEO plugin's sitemap settings.</p>
        <form action="" method="post">
            <input type="hidden" name="lr_action" value="generate_sitemap_csv">
            <?php wp_nonce_field('lr_generate_sitemap'); ?>
            <?php submit_button('Generate Sitemap CSV'); ?>
        </form>
    </div>
    <?php
}
