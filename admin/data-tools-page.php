<?php

if (!defined('ABSPATH')) exit; // Exit if accessed directly

/**
 * Handles the processing of the search and replace form submission.
 */
function lr_process_search_replace_action() {
    if (
        !isset($_POST['lr_search_replace_nonce_field']) ||
        !wp_verify_nonce($_POST['lr_search_replace_nonce_field'], 'lr_search_replace_nonce')
    ) {
        wp_die('Security check failed.');
    }

    if (!current_user_can('manage_options')) {
        wp_die('You do not have sufficient permissions to perform this action.');
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'lr_city_updates';
    
    $search_for = isset($_POST['lr_search_for']) ? sanitize_text_field(stripslashes($_POST['lr_search_for'])) : '';
    $replace_with = isset($_POST['lr_replace_with']) ? sanitize_text_field(stripslashes($_POST['lr_replace_with'])) : '';

    if (empty($search_for)) {
        wp_redirect(admin_url('admin.php?page=lr-data-tools&lr-notice=error-empty-search'));
        exit;
    }

    // Update post_content
    $rows_affected_content = $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$table_name} SET post_content = REPLACE(post_content, %s, %s)",
            $search_for,
            $replace_with
        )
    );

    // Update post_summary
    $rows_affected_summary = $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$table_name} SET post_summary = REPLACE(post_summary, %s, %s)",
            $search_for,
            $replace_with
        )
    );

    // Update featured_image_url
    $rows_affected_image = $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$table_name} SET featured_image_url = REPLACE(featured_image_url, %s, %s)",
            $search_for,
            $replace_with
        )
    );

    $total_affected = $rows_affected_content + $rows_affected_summary + $rows_affected_image;

    wp_redirect(admin_url('admin.php?page=lr-data-tools&lr-notice=success&rows=' . $total_affected));
    exit;
}
add_action('admin_post_lr_submit_search_replace', 'lr_process_search_replace_action');

/**
 * Renders the data tools page for search and replace functionality.
 */
function lr_render_data_tools_page() {
    ?>
    <div class="wrap">
        <h1>Let's Roll - Data Tools</h1>

        <?php
        if (isset($_GET['lr-notice'])) {
            if ($_GET['lr-notice'] === 'success') {
                $rows = isset($_GET['rows']) ? intval($_GET['rows']) : 0;
                echo '<div class="notice notice-success is-dismissible"><p>Search and replace completed. ' . esc_html($rows) . ' replacements were made.</p></div>';
            } elseif ($_GET['lr-notice'] === 'error-empty-search') {
                 echo '<div class="notice notice-error is-dismissible"><p>Error: The "Search for" field cannot be empty.</p></div>';
            }
        }
        ?>

        <p>Use these tools to perform maintenance tasks on the plugin's custom database tables.</p>

        <div class="card">
            <h2 class="title">Search and Replace URLs</h2>
            <p>This tool will search all city update posts for a specific string (like an old URL) and replace it with a new one.</p>
            <p><strong>Warning:</strong> This is a powerful tool that directly modifies the database. Please <a href="<?php echo esc_url(admin_url('admin.php?page=lr-import-export&action=export-city-updates')); ?>">create a backup</a> before proceeding.</p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="lr_submit_search_replace">
                <?php wp_nonce_field('lr_search_replace_nonce', 'lr_search_replace_nonce_field'); ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">
                            <label for="lr_search_for">Search for:</label>
                        </th>
                        <td>
                            <input type="text" id="lr_search_for" name="lr_search_for" class="regular-text" value="lets-roll-plugin-development-playground.local" required />
                            <p class="description">The old URL or string you want to replace.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">
                            <label for="lr_replace_with">Replace with:</label>
                        </th>
                        <td>
                            <input type="text" id="lr_replace_with" name="lr_replace_with" class="regular-text" value="lets-roll.app" required />
                            <p class="description">The new URL or string to use instead.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Run Search & Replace', 'primary', 'submit'); ?>
            </form>
        </div>
    </div>
    <?php
}
