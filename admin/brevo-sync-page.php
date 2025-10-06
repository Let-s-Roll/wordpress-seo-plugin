<?php

if (!defined('ABSPATH')) exit; // Exit if accessed directly

/**
 * Renders the Brevo Sync admin page with all new features.
 */
function lr_render_brevo_sync_page() {
    $all_locations = lr_get_location_data();
    $selected_city_data = null;
    $skaters_list = [];

    // --- FORM HANDLERS ---

    // Handle saving the API Key
    if (isset($_POST['lr_save_brevo_key']) && check_admin_referer('lr_brevo_save_key_action', 'lr_brevo_save_key_nonce')) {
        $options = get_option('lr_brevo_options', []);
        $options['api_key'] = sanitize_text_field($_POST['brevo_api_key']);
        update_option('lr_brevo_options', $options);
        echo '<div class="notice notice-success is-dismissible"><p>API Key saved.</p></div>';
    }

    // Handle log management
    if (isset($_POST['lr_remove_from_log']) && check_admin_referer('lr_brevo_log_management_action', 'lr_brevo_log_management_nonce')) {
        lr_remove_processed_skater(sanitize_text_field($_POST['skater_to_remove']));
        echo '<div class="notice notice-success is-dismissible"><p>Skater removed from log. You can now sync them again.</p></div>';
    }
    if (isset($_POST['lr_clear_log']) && check_admin_referer('lr_brevo_log_management_action', 'lr_brevo_log_management_nonce')) {
        lr_clear_processed_skaters();
        echo '<div class="notice notice-success is-dismissible"><p>Processed skater log has been cleared.</p></div>';
    }

    // Find city data for single city sync
    if ((isset($_POST['lr_load_skaters']) || isset($_POST['lr_run_single_city_sync'])) && isset($_POST['lr_city_select'])) {
        $selected_city_slug = sanitize_text_field($_POST['lr_city_select']);
        foreach ($all_locations as $country_slug => $country_data) {
            if (isset($country_data['cities'][$selected_city_slug])) {
                $selected_city_data = $country_data['cities'][$selected_city_slug];
                $selected_city_data['country_slug'] = $country_slug;
                $selected_city_data['city_slug'] = $selected_city_slug;
                break;
            }
        }
    }

    // Handle loading skaters for a specific city
    if (isset($_POST['lr_load_skaters']) && check_admin_referer('lr_brevo_dry_run_action', 'lr_brevo_dry_run_nonce') && $selected_city_data) {
        $skaters_list = lr_fetch_skaters_for_city($selected_city_data);
    }

    // Handle enriching a single skater
    if (isset($_POST['lr_enrich_single_skater']) && check_admin_referer('lr_brevo_single_enrich_action', 'lr_brevo_single_enrich_nonce')) {
        // ... (enrichment logic)
    }
    
    ?>
    <div class="wrap">
        <h1>Brevo Skater Location Sync</h1>
        <p>This tool allows you to enrich your Brevo contacts with location data based on their activity in the Let's Roll app.</p>

        <div class="notice notice-warning" style="padding: 10px;">
            <h3 style="margin-top: 0;">IP Whitelisting</h3>
            <p><strong>Your Server's IP Address is:</strong> <code><?php echo esc_html(lr_get_server_ip()); ?></code></p>
        </div>

        <hr>
        
        <h2>Settings</h2>
        <form method="post" action="">
            <?php wp_nonce_field('lr_brevo_save_key_action', 'lr_brevo_save_key_nonce'); ?>
            <?php $options = get_option('lr_brevo_options'); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><label for="brevo_api_key">Brevo API Key</label></th>
                    <td>
                        <input type="password" id="brevo_api_key" name="brevo_api_key" value="<?php echo esc_attr($options['api_key'] ?? ''); ?>" style="width: 300px;" />
                    </td>
                </tr>
            </table>
            <?php submit_button('Save API Key', 'primary', 'lr_save_brevo_key'); ?>
        </form>

        <hr>

        <h2>1. Test & Dry Run</h2>
        <p><strong>Prerequisites:</strong> Make sure you have saved your Brevo API key above and created a text attribute in your Brevo account named <code>SKATENAME</code> (all uppercase).</p>
        
        <form method="post" action="">
            <?php wp_nonce_field('lr_brevo_dry_run_action', 'lr_brevo_dry_run_nonce'); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><label for="lr_city_select">Select a City</label></th>
                    <td>
                        <select id="lr_city_select" name="lr_city_select" style="width: 300px;">
                            <option value="">-- Choose a City --</option>
                            <?php
                            foreach ($all_locations as $country_data) {
                                if (empty($country_data['cities'])) continue;
                                foreach ($country_data['cities'] as $city_slug => $city_details) {
                                    $is_selected = ($selected_city_data && $selected_city_data['city_slug'] === $city_slug) ? 'selected' : '';
                                    echo '<option value="' . esc_attr($city_slug) . '" ' . $is_selected . '>' . esc_html($country_data['name']) . ' - ' . esc_html($city_details['name']) . '</option>';
                                }
                            }
                            ?>
                        </select>
                    </td>
                </tr>
            </table>
            <?php submit_button('Load Skaters for Dry Run', 'secondary', 'lr_load_skaters'); ?>
            <?php if ($selected_city_data) : ?>
                <?php submit_button('Sync All Skaters in ' . esc_html($selected_city_data['name']), 'primary', 'lr_run_single_city_sync'); ?>
            <?php endif; ?>
        </form>

        <?php if (isset($_POST['lr_run_single_city_sync']) && check_admin_referer('lr_brevo_dry_run_action', 'lr_brevo_dry_run_nonce') && $selected_city_data) : ?>
            <div class="notice notice-info" style="padding: 10px; margin-top: 15px;">
                <?php lr_run_single_city_sync($selected_city_data); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($skaters_list) && !is_wp_error($skaters_list)) : ?>
            <h3>Skaters found in <?php echo esc_html($selected_city_data['name']); ?></h3>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 40%;">Skate Name</th>
                        <th style="width: 20%;">Distance (km)</th>
                        <th style="width: 40%;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($skaters_list as $skater) : ?>
                        <tr>
                            <td><?php echo esc_html($skater->skateName ?? 'N/A'); ?></td>
                            <td><?php echo esc_html($skater->distance_km ?? 'N/A'); ?></td>
                            <td>
                                <?php if (!empty($skater->skateName)) : ?>
                                    <form method="post" action="">
                                        <?php wp_nonce_field('lr_brevo_single_enrich_action', 'lr_brevo_single_enrich_nonce'); ?>
                                        <input type="hidden" name="skater_skateName" value="<?php echo esc_attr($skater->skateName); ?>">
                                        <input type="hidden" name="city_name" value="<?php echo esc_attr($selected_city_data['name']); ?>">
                                        <button type="submit" name="lr_enrich_single_skater" class="button button-primary">Enrich in Brevo</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <hr style="margin-top: 40px;">

        <h2 style="color: #c00;">2. Run Full Sync</h2>
        <div id="lr-brevo-sync-status" style="background-color: #f7f7f7; border-left: 4px solid #777; padding: 10px 20px; margin-top: 15px; max-height: 400px; overflow-y: auto;">
            <?php
            if (isset($_POST['lr_brevo_sync_start']) && check_admin_referer('lr_brevo_sync_action', 'lr_brevo_sync_nonce')) {
                run_brevo_sync();
            } else {
                echo "<p>Ready to run the full sync.</p>";
            }
            ?>
        </div>
        <form method="post" action="" style="margin-top: 15px;">
            <?php wp_nonce_field('lr_brevo_sync_action', 'lr_brevo_sync_nonce'); ?>
            <?php submit_button('Run Full Sync For All Cities', 'delete', 'lr_brevo_sync_start'); ?>
        </form>

        <hr style="margin-top: 40px;">

        <h2>Processed Skaters Log</h2>
        <?php $processed_skaters = lr_get_processed_skaters(); ?>
        <p>Found <?php echo count($processed_skaters); ?> skaters in the log. You can remove them to have the system sync them again.</p>
        <form method="post" action="">
            <?php wp_nonce_field('lr_brevo_log_management_action', 'lr_brevo_log_management_nonce'); ?>
            <?php submit_button('Clear Entire Log', 'delete', 'lr_clear_log'); ?>
        </form>
        <table class="wp-list-table widefat fixed striped" style="margin-top: 15px;">
            <thead>
                <tr>
                    <th style="width: 40%;">Skate Name</th>
                    <th style="width: 40%;">Synced to City</th>
                    <th style="width: 20%;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($processed_skaters)) : ?>
                    <tr><td colspan="3">The log is empty.</td></tr>
                <?php else : ?>
                    <?php foreach ($processed_skaters as $skater_name => $city_name) : ?>
                        <tr>
                            <td><?php echo esc_html($skater_name); ?></td>
                            <td><?php echo esc_html($city_name); ?></td>
                            <td>
                                <form method="post" action="">
                                    <?php wp_nonce_field('lr_brevo_log_management_action', 'lr_brevo_log_management_nonce'); ?>
                                    <input type="hidden" name="skater_to_remove" value="<?php echo esc_attr($skater_name); ?>">
                                    <?php submit_button('Remove from Log', 'delete', 'lr_remove_from_log', false); ?>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}