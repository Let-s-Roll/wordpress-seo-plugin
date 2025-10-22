<?php

if (!defined('ABSPATH')) exit; // Exit if accessed directly

/**
 * Renders the Content Discovery admin page and handles its form submissions.
 */
function lr_render_content_discovery_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'lr_discovered_content';

    // Handle form submissions
    if (isset($_POST['lr_cancel_discovery']) && check_admin_referer('lr_discovery_actions')) {
        delete_option('lr_discovery_queue');
        delete_option('lr_discovery_queue_total');
        wp_clear_scheduled_hook('lr_content_discovery_worker_event');
        // Re-schedule the recurring worker event so it doesn't stop completely
        if (!wp_next_scheduled('lr_content_discovery_cron')) {
            wp_schedule_event(time(), 'daily', 'lr_content_discovery_cron');
        }
        echo '<div class="notice notice-warning is-dismissible"><p>Content discovery has been cancelled.</p></div>';
    }

    if (isset($_POST['lr_clear_activity_log']) && check_admin_referer('lr_discovery_actions')) {
        lr_clear_discovery_log();
        echo '<div class="notice notice-success is-dismissible"><p>Activity log has been cleared.</p></div>';
    }

    if (isset($_POST['lr_clear_discovery_log']) && check_admin_referer('lr_discovery_actions')) {
        // Clear the discovered content table.
        $wpdb->query("TRUNCATE TABLE $table_name");
        echo '<div class="notice notice-success is-dismissible"><p>Discovered content log has been cleared.</p></div>';
    }

    // Fetch data for display
    $log_items = $wpdb->get_results("SELECT * FROM $table_name ORDER BY discovered_at DESC LIMIT 100");
    $queue = get_option('lr_discovery_queue', []);
    $total_count = get_option('lr_discovery_queue_total', 0);
    $is_running = !empty($queue);
    ?>
    <div class="wrap">
        <h1>Content Discovery</h1>
        <p>This page allows you to monitor and control the automated content discovery process.</p>

        <!-- Manual Trigger & Status -->
        <div class="notice notice-info" style="padding: 10px; margin-top: 15px;">
            <?php if ($is_running) : ?>
                <?php
                $remaining_count = count($queue);
                $processed_count = $total_count - $remaining_count;
                $progress_percentage = $total_count > 0 ? round(($processed_count / $total_count) * 100) : 0;
                ?>
                <p><strong>Status:</strong> <span style="color: green;">Discovery in progress...</span></p>
                <p><strong>Progress:</strong> <?php echo esc_html($processed_count); ?> / <?php echo esc_html($total_count); ?> cities processed.</p>
                <div style="background-color: #eee; border: 1px solid #ccc; height: 24px; width: 100%;"><div style="background-color: #2271b1; height: 100%; width: <?php echo $progress_percentage; ?>%;"></div></div>
                <p><em>This page will auto-refresh in 10 seconds.</em></p>
                <meta http-equiv="refresh" content="10">
            <?php else : ?>
                <p><strong>Status:</strong> <span style="color: #888;">Idle</span></p>
                <?php
                $next_run = wp_next_scheduled('lr_content_discovery_cron');
                if ($next_run) {
                    echo '<p><strong>Next Scheduled Run:</strong> ' . get_date_from_gmt(date('Y-m-d H:i:s', $next_run), 'F j, Y g:i a') . '</p>';
                }
                ?>
            <?php endif; ?>
            
            <form method="post" action="">
                <?php wp_nonce_field('lr_discovery_actions'); ?>
                <button type="button" id="lr-run-discovery-btn" class="button button-primary" <?php disabled($is_running); ?>>
                    <?php echo $is_running ? 'Running...' : 'Run Content Discovery Now'; ?>
                </button>
                <?php if ($is_running) : ?>
                    <?php submit_button('Cancel Discovery', 'delete', 'lr_cancel_discovery', false); ?>
                <?php endif; ?>
            </form>
        </div>

        <!-- JavaScript for AJAX Trigger -->
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('#lr-run-discovery-btn').on('click', function() {
                    var btn = $(this);
                    btn.text('Starting...').prop('disabled', true);

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'lr_run_discovery_now',
                            nonce: '<?php echo wp_create_nonce("lr_run_discovery_now"); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                // Reload the page to show the new progress bar
                                location.reload();
                            } else {
                                alert('An error occurred: ' + response.data);
                                btn.text('Run Content Discovery Now').prop('disabled', false);
                            }
                        },
                        error: function() {
                            alert('An AJAX error occurred.');
                            btn.text('Run Content Discovery Now').prop('disabled', false);
                        }
                    });
                });
            });
        </script>

        <hr style="margin-top: 20px;">

        <h2>Activity Log</h2>
        <div id="lr-discovery-log-viewer">
            <textarea readonly style="width: 100%; height: 200px; background-color: #f7f7f7; font-family: monospace; font-size: 12px;"><?php
                $log_entries = get_transient('lr_discovery_log');
                if (!empty($log_entries)) {
                    echo esc_textarea(implode("\n", $log_entries));
                } else {
                    echo 'Log is empty.';
                }
            ?></textarea>
            <form method="post" action="" style="margin-top: 10px;">
                <?php wp_nonce_field('lr_discovery_actions'); ?>
                <?php submit_button('Clear Activity Log', 'secondary', 'lr_clear_activity_log', false); ?>
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