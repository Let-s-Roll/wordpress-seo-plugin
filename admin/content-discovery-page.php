<?php

if (!defined('ABSPATH')) exit; // Exit if accessed directly

/**
 * Renders the Content Discovery admin page and handles its form submissions.
 */
function lr_render_content_discovery_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'lr_discovered_content';

    // Handle form submissions
    if (isset($_POST['lr_run_discovery_now']) && check_admin_referer('lr_discovery_actions')) {
        // Manually trigger the content discovery cron job.
        // It will run in the background.
        wp_schedule_single_event(time(), 'lr_content_discovery_cron');
        echo '<div class="notice notice-success is-dismissible"><p>Content Discovery has been started in the background. Refresh this page in a minute to see the results.</p></div>';
    }

    if (isset($_POST['lr_clear_discovery_log']) && check_admin_referer('lr_discovery_actions')) {
        // Clear the discovered content table.
        $wpdb->query("TRUNCATE TABLE $table_name");
        echo '<div class="notice notice-success is-dismissible"><p>Discovered content log has been cleared.</p></div>';
    }

    // Fetch the data for the log table
    $log_items = $wpdb->get_results("SELECT * FROM $table_name ORDER BY discovered_at DESC LIMIT 100");
    ?>
    <div class="wrap">
        <h1>Content Discovery</h1>
        <p>This page allows you to monitor and control the automated content discovery process.</p>

        <!-- Manual Trigger & Cron Status -->
        <div class="notice notice-info" style="padding: 10px; margin-top: 15px;">
            <?php
            $next_run = wp_next_scheduled('lr_content_discovery_cron');
            if ($next_run) {
                echo '<p><strong>Next Scheduled Run:</strong> ' . get_date_from_gmt(date('Y-m-d H:i:s', $next_run), 'F j, Y g:i a') . '</p>';
            } else {
                echo '<p><strong>Next Scheduled Run:</strong> Not scheduled. Please re-activate the plugin if this persists.</p>';
            }
            ?>
            <form method="post" action="">
                <?php wp_nonce_field('lr_discovery_actions'); ?>
                <?php submit_button('Run Content Discovery Now', 'primary', 'lr_run_discovery_now', false); ?>
            </form>
        </div>

        <!-- The Discovered Content Log -->
        <h2 style="margin-top: 40px;">Discovered Content Log</h2>
        <p>Showing the last 100 items discovered by the system.</p>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 15%;">Discovered At</th>
                    <th style="width: 10%;">Type</th>
                    <th style="width: 15%;">City</th>
                    <th>API ID</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($log_items)) : ?>
                    <tr><td colspan="4">The log is empty. Run the discovery process to populate it.</td></tr>
                <?php else : ?>
                    <?php foreach ($log_items as $item) : ?>
                        <tr>
                            <td><?php echo esc_html($item->discovered_at); ?></td>
                            <td><?php echo esc_html($item->content_type); ?></td>
                            <td><?php echo esc_html($item->city_slug); ?></td>
                            <td><?php echo esc_html($item->api_id); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Clear Log Button -->
        <form method="post" action="" style="margin-top: 20px;">
             <?php wp_nonce_field('lr_discovery_actions'); ?>
             <?php submit_button('Clear Discovered Content Log', 'delete', 'lr_clear_discovery_log', false); ?>
        </form>

    </div>
    <?php
}