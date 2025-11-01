<?php

if (!defined('ABSPATH')) exit; // Exit if accessed directly

/**
 * Renders the Content Discovery admin page and handles its form submissions.
 */
function lr_render_content_discovery_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'lr_discovered_content';

    // Handle form submissions
    if (isset($_POST['lr_seed_historical_posts']) && check_admin_referer('lr_discovery_actions')) {
        $city_slug = sanitize_text_field($_POST['city_select']);
        if (!empty($city_slug)) {
            lr_run_historical_seeding_for_city($city_slug);
            echo '<div class="notice notice-success is-dismissible"><p>Finished seeding historical posts for ' . esc_html($city_slug) . '. See results in the log file and on the front end.</p></div>';
        } else {
            echo '<div class="notice notice-error is-dismissible"><p>Please select a city first.</p></div>';
        }
    }

    if (isset($_POST['lr_run_publication_now']) && check_admin_referer('lr_discovery_actions')) {
        lr_run_content_publication();
        echo '<div class="notice notice-success is-dismissible"><p>Content Publication process has been run. Check the city update pages for new posts.</p></div>';
    }

    if (isset($_POST['lr_run_publication_for_city']) && check_admin_referer('lr_discovery_actions')) {
        $city_slug = sanitize_text_field($_POST['city_select']);
        if (!empty($city_slug)) {
            lr_generate_city_update_post($city_slug);
            echo '<div class="notice notice-success is-dismissible"><p>Generated a new update post for ' . esc_html($city_slug) . '. You can now view it on the front end.</p></div>';
        } else {
            echo '<div class="notice notice-error is-dismissible"><p>Please select a city first.</p></div>';
        }
    }

    if (isset($_POST['lr_clear_update_posts']) && check_admin_referer('lr_discovery_actions')) {
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}lr_city_updates");
        echo '<div class="notice notice-success is-dismissible"><p>All generated city update posts have been cleared.</p></div>';
    }

    if (isset($_POST['lr_clear_all_data']) && check_admin_referer('lr_discovery_actions')) {
        // Clear the discovered content table
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}lr_discovered_content");
        // Clear the seen skaters table
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}lr_seen_skaters");
        // Clear the log file
        lr_clear_discovery_log_file();
        // Sledgehammer: Clear all transients to ensure no bad API responses are cached
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%'");
        echo '<div class="notice notice-success is-dismissible"><p>All discovery data (database logs, seen skaters, log file, and all site transients) has been cleared.</p></div>';
    }

    if (isset($_POST['lr_run_discovery_now']) && check_admin_referer('lr_discovery_actions')) {
        wp_schedule_single_event(time(), 'lr_content_discovery_cron');
        echo '<div class="notice notice-success is-dismissible"><p>Full Content Discovery has been started in the background. The log below will update as it runs.</p></div>';
    }

    if (isset($_POST['lr_run_discovery_for_city']) && check_admin_referer('lr_discovery_actions')) {
        $city_slug = sanitize_text_field($_POST['city_select']);
        if (!empty($city_slug)) {
            lr_clear_discovery_log_file();
            lr_log_discovery_message("Starting single-city discovery run for: $city_slug...");
            $all_locations = lr_get_location_data();
            $city_details = null;
            foreach ($all_locations as $country_data) {
                if (isset($country_data['cities'][$city_slug])) {
                    $city_details = $country_data['cities'][$city_slug];
                    break;
                }
            }
            if ($city_details) {
                lr_discover_new_content_for_city($city_slug, $city_details);
                lr_log_discovery_message("Single-city discovery run finished for: $city_slug.");
                echo '<div class="notice notice-success is-dismissible"><p>Finished discovery for ' . esc_html($city_slug) . '. See results in the log below.</p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>Could not find city details for the selected slug.</p></div>';
            }
        }
    }

    if (isset($_POST['lr_clear_discovery_log']) && check_admin_referer('lr_discovery_actions')) {
        $wpdb->query("TRUNCATE TABLE $table_name");
        echo '<div class="notice notice-success is-dismissible"><p>Discovered content database log has been cleared.</p></div>';
    }
    
    if (isset($_POST['lr_clear_log_file']) && check_admin_referer('lr_discovery_actions')) {
        lr_clear_discovery_log_file();
        echo '<div class="notice notice-success is-dismissible"><p>Log file has been cleared.</p></div>';
    }

    if (isset($_POST['lr_clear_seen_skaters_log']) && check_admin_referer('lr_discovery_actions')) {
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}lr_seen_skaters");
        echo '<div class="notice notice-success is-dismissible"><p>Seen skaters log has been cleared.</p></div>';
    }

    // --- Render Page ---
    $all_locations = lr_get_location_data();
    ?>
    <div class="wrap">
        <h1>Content Discovery</h1>
        <p>This page allows you to monitor and control the automated content discovery process.</p>

        <!-- Manual Triggers -->
        <div class="notice notice-info" style="padding: 10px; margin-top: 15px;">
            <h2>Run Discovery Manually</h2>
            <p><strong>Next Scheduled Full Run:</strong> <?php $ts = wp_next_scheduled('lr_content_discovery_cron'); echo $ts ? get_date_from_gmt(date('Y-m-d H:i:s', $ts), 'F j, Y g:i a') : 'Not scheduled.'; ?></p>
            
            <form method="post" action="" style="margin-bottom: 15px;">
                <?php wp_nonce_field('lr_discovery_actions'); ?>
                <?php submit_button('Run Full Discovery Now', 'primary', 'lr_run_discovery_now', false); ?>
                <?php submit_button('Generate City Update Posts', 'secondary', 'lr_run_publication_now', false); ?>
                <a href="<?php echo plugin_dir_url(__DIR__) . 'seed_all_cities.php'; ?>" 
                   class="button button-primary" 
                   target="_blank" 
                   onclick="return confirm('This will start the historical seeding process for ALL cities. It can take a very long time and should only be run once. Are you sure you want to continue?');">
                   Seed All Cities (Historical)
                </a>
                <p class="description">First, run discovery to find new content. Then, generate the posts for that content.</p>
            </form>
            <hr>
            <form method="post" action="">
                <?php wp_nonce_field('lr_discovery_actions'); ?>
                <select name="city_select" style="min-width: 200px;">
                    <option value="">-- Select a City to Test --</option>
                    <?php 
                     if (!empty($all_locations)) {
                         foreach ($all_locations as $country_data) {
                             if (empty($country_data['cities'])) continue;
                             foreach ($country_data['cities'] as $city_slug => $city_details) {
                                 echo '<option value="' . esc_attr($city_slug) . '">' . esc_html($city_details['name']) . ', ' . esc_html($country_data['name']) . '</option>';
                             }
                         }
                     }
                    ?>
                </select>
                <?php submit_button('Run Discovery for Selected City', 'secondary', 'lr_run_discovery_for_city', false); ?>
                <?php submit_button('Generate Post for Selected City', 'primary', 'lr_run_publication_for_city', false); ?>
                <?php submit_button('Seed Historical Posts', 'secondary', 'lr_seed_historical_posts', false); ?>
                 <p class="description">This will run the discovery for a single city and show the detailed output in the log file viewer below.</p>
            </form>
        </div>

        <!-- Log File Viewer -->
        <h2 style="margin-top: 40px;">Discovery Log File</h2>
        <div id="lr-log-viewer">
            <textarea readonly style="width: 100%; height: 300px; background-color: #f7f7f7; font-family: monospace; font-size: 12px;"><?php
                $log_file = lr_get_discovery_log_file_path();
                if (file_exists($log_file)) {
                    echo esc_textarea(file_get_contents($log_file));
                } else {
                    echo 'Log file not found.';
                }
            ?></textarea>
            <form method="post" action="">
                <?php wp_nonce_field('lr_discovery_actions'); ?>
                <?php submit_button('Clear Log File', 'delete', 'lr_clear_log_file', false); ?>
            </form>
        </div>

        <!-- The Discovered Content DB Log -->
        <h2 style="margin-top: 40px;">Discovered Content Database</h2>
        <p>This table shows all unique content items discovered over time. The log file above is cleared on each run; this database is permanent.</p>
        <?php
            // Pagination and Filtering logic
            $per_page = 50;
            $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
            $offset = ($current_page - 1) * $per_page;

            $where_clauses = [];
            if (!empty($_GET['filter_type'])) {
                $where_clauses[] = $wpdb->prepare("content_type = %s", sanitize_text_field($_GET['filter_type']));
            }
            if (!empty($_GET['filter_city'])) {
                $where_clauses[] = $wpdb->prepare("city_slug = %s", sanitize_text_field($_GET['filter_city']));
            }
            if (isset($_GET['filter_published']) && $_GET['filter_published'] !== '') {
                $where_clauses[] = $wpdb->prepare("is_published = %d", intval($_GET['filter_published']));
            }
            $where_sql = !empty($where_clauses) ? "WHERE " . implode(' AND ', $where_clauses) : '';

            $total_items = $wpdb->get_var("SELECT COUNT(id) FROM $table_name $where_sql");
            $total_pages = ceil($total_items / $per_page);

            $log_items = $wpdb->get_results("SELECT * FROM $table_name $where_sql ORDER BY discovered_at DESC LIMIT $per_page OFFSET $offset");
            
            $all_content_types = ['event', 'review', 'session', 'skater', 'spot'];
        ?>
        <!-- Filters -->
        <form method="get">
            <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page']); ?>">
            <select name="filter_type">
                <option value="">All Types</option>
                <?php foreach ($all_content_types as $type) : ?>
                    <option value="<?php echo esc_attr($type); ?>" <?php selected(($_GET['filter_type'] ?? ''), $type); ?>><?php echo esc_html(ucfirst($type)); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="filter_city">
                <option value="">All Cities</option>
                 <?php 
                 if (!empty($all_locations)) {
                     foreach ($all_locations as $country_data) {
                         if (empty($country_data['cities'])) continue;
                         foreach ($country_data['cities'] as $city_slug => $city_details) {
                             echo '<option value="' . esc_attr($city_slug) . '" ' . selected(($_GET['filter_city'] ?? ''), $city_slug, false) . '>' . esc_html($city_details['name']) . '</option>';
                         }
                     }
                 }
                 ?>
            </select>
            <select name="filter_published">
                <option value="">All Content</option>
                <option value="1" <?php selected(($_GET['filter_published'] ?? ''), '1'); ?>>Published</option>
                <option value="0" <?php selected(($_GET['filter_published'] ?? ''), '0'); ?>>Unpublished</option>
            </select>
            <?php submit_button('Filter', 'secondary', '', false); ?>
        </form>

        <!-- Log Table -->
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 20%;">Discovered At (UTC)</th>
                    <th style="width: 10%;">Type</th>
                    <th style="width: 15%;">City</th>
                    <th>API ID</th>
                    <th style="width: 10%;">Status</th>
                    <th style="width: 10%;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($log_items)) : ?>
                    <tr><td colspan="6">No content found for the selected filters.</td></tr>
                <?php else : ?>
                    <?php foreach ($log_items as $item) : ?>
                        <tr>
                            <td><?php echo esc_html($item->discovered_at); ?></td>
                            <td><?php echo esc_html($item->content_type); ?></td>
                            <td><?php echo esc_html($item->city_slug); ?></td>
                            <td><?php echo esc_html($item->api_id); ?></td>
                            <td><?php echo $item->is_published ? 'Published' : 'Unpublished'; ?></td>
                            <td><a href="#" class="view-data" data-content='<?php echo esc_attr($item->data_cache); ?>'>View Data</a></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Pagination -->
        <div class="tablenav">
            <div class="tablenav-pages">
                <span class="displaying-num"><?php echo $total_items; ?> items</span>
                <span class="pagination-links">
                    <?php
                    echo paginate_links([
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'prev_text' => __('&laquo;'),
                        'next_text' => __('&raquo;'),
                        'total' => $total_pages,
                        'current' => $current_page,
                    ]);
                    ?>
                </span>
            </div>
        </div>

        <div style="display: flex; gap: 10px; margin-top: 20px;">
            <!-- Clear All Data Button -->
            <form method="post" action="">
                 <?php wp_nonce_field('lr_discovery_actions'); ?>
                 <?php submit_button('Clear All Discovery Data', 'delete', 'lr_clear_all_data', false); ?>
            </form>
            <!-- Clear Generated Posts Button -->
            <form method="post" action="">
                 <?php wp_nonce_field('lr_discovery_actions'); ?>
                 <?php submit_button('Clear All Generated Posts', 'delete', 'lr_clear_update_posts', false); ?>
            </form>
        </div>
        <p class="description">"Clear All Discovery Data" wipes the raw discovered content (Layer 1). "Clear All Generated Posts" wipes the public-facing posts created from that data (Layer 2).</p>

    </div>

    <!-- Modal for displaying JSON data -->
    <div id="lr-data-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); z-index:1000;">
        <div style="background:white; width:60%; margin: 5% auto; padding:20px; border-radius:5px; max-height:80%; overflow-y:auto;">
            <h3>Cached API Data</h3>
            <pre id="lr-modal-content" style="background:#eee; padding:10px; border-radius:3px;"></pre>
            <button id="lr-close-modal" class="button">Close</button>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const modal = document.getElementById('lr-data-modal');
            const modalContent = document.getElementById('lr-modal-content');
            const closeModal = document.getElementById('lr-close-modal');

            document.querySelectorAll('.view-data').forEach(link => {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    const content = this.getAttribute('data-content');
                    try {
                        const parsed = JSON.parse(content);
                        modalContent.textContent = JSON.stringify(parsed, null, 2);
                    } catch (err) {
                        modalContent.textContent = 'Error parsing JSON data.';
                    }
                    modal.style.display = 'block';
                });
            });

            closeModal.addEventListener('click', function() {
                modal.style.display = 'none';
            });

            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    modal.style.display = 'none';
                }
            });
        });
    </script>
    <?php
}