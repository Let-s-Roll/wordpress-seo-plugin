<?php
/**
 * This file contains the functions to create and manage the plugin's admin settings page.
 */

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Adds a new menu item under the main "Settings" menu in the WordPress admin.
 */
function lr_add_settings_page() {
    add_options_page(
        'Let\'s Roll SEO Pages Settings', // Page title
        'Let\'s Roll SEO',                // Menu title
        'manage_options',                  // Capability required to see this option
        'lets-roll-seo-pages',             // Menu slug
        'lr_render_settings_page'          // Function that renders the page content
    );
}
add_action('admin_menu', 'lr_add_settings_page');

/**
 * Registers the settings that our page will use.
 * This tells WordPress to handle the saving and security of our options.
 */
function lr_register_settings() {
    // Register a group of settings
    register_setting(
        'lr_settings_group', // A name for the group of settings
        'lr_options'         // The name of the option that will be saved in the database
    );
}
add_action('admin_init', 'lr_register_settings');

/**
 * Renders the HTML for the settings page.
 */
function lr_render_settings_page() {
    // Get our saved options from the database
    $options = get_option('lr_options');
    $api_email = $options['api_email'] ?? '';
    $api_pass = $options['api_pass'] ?? '';
    $locations_json = $options['locations_json'] ?? '';
    ?>
    <div class="wrap">
        <h1>Let's Roll SEO Pages Settings</h1>
        <form method="post" action="options.php">
            <?php
            // WordPress functions to handle security and field registration
            settings_fields('lr_settings_group');
            do_settings_sections('lr_settings_group');
            ?>

            <h2>API Credentials</h2>
            <p>Enter the credentials used to authenticate with the Let's Roll API.</p>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><label for="lr_api_email">API Email</label></th>
                    <td><input type="text" id="lr_api_email" name="lr_options[api_email]" value="<?php echo esc_attr($api_email); ?>" size="40" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="lr_api_pass">API Password</label></th>
                    <td><input type="password" id="lr_api_pass" name="lr_options[api_pass]" value="<?php echo esc_attr($api_pass); ?>" size="40" /></td>
                </tr>
            </table>

            <hr>

            <h2>Location Data</h2>
            <p>Enter the location data as a JSON object. You can validate the format using an online JSON validator.</p>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><label for="lr_locations_json">Locations JSON</label></th>
                    <td>
                        <textarea id="lr_locations_json" name="lr_options[locations_json]" rows="20" cols="80" class="large-text code"><?php echo esc_textarea($locations_json); ?></textarea>
                        <p class="description">Paste the full JSON structure for countries and cities here.</p>
                    </td>
                </tr>
            </table>
            
            <?php submit_button(); ?>

        </form>
    </div>
    <?php
}
