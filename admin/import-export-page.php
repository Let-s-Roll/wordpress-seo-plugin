<?php

if (!defined('ABSPATH')) exit; // Exit if accessed directly

/**
 * Renders the HTML for the Import/Export admin page.
 */
function lr_render_import_export_page() {
    ?>
    <div class="wrap">
        <h1>Import / Export Let's Roll Data</h1>
        <p>Use the tools below to export and import discovered content and generated city update posts. This is useful for migrating data between development and production environments.</p>

        <!-- Export Section -->
        <div class="card">
            <h2>Export Data</h2>
            <p>Click a button below to generate and download a JSON file for a specific data type. This is useful for migrating data between environments.</p>
            
            <div style="display: flex; gap: 10px;">
                <!-- Discovered Content Export Form -->
                <form method="post" action="">
                    <?php wp_nonce_field('lr_export_data_nonce', 'lr_export_nonce'); ?>
                    <input type="hidden" name="lr_action" value="export_discovered_content">
                    <?php submit_button('Export Discovered Content', 'primary', 'lr_export_discovered_submit', false); ?>
                </form>

                <!-- City Updates Export Form -->
                <form method="post" action="">
                    <?php wp_nonce_field('lr_export_data_nonce', 'lr_export_nonce'); ?>
                    <input type="hidden" name="lr_action" value="export_city_updates">
                    <?php submit_button('Export City Update Posts', 'primary', 'lr_export_updates_submit', false); ?>
                </form>
            </div>
        </div>

        <!-- Import Section -->
        <div class="card" style="margin-top: 20px;">
            <h2>Import Data</h2>
            <p>Upload a JSON file that was previously exported from this plugin. Ensure you are uploading the correct file to the correct section.</p>

            <!-- Discovered Content Import Form -->
            <div style="margin-bottom: 20px; padding: 10px; border: 1px solid #ccd0d4;">
                <h4>Import Discovered Content</h4>
                <form method="post" action="" enctype="multipart/form-data">
                    <?php wp_nonce_field('lr_import_data_nonce', 'lr_import_nonce'); ?>
                    <input type="hidden" name="lr_action" value="import_data">
                    <p>
                        <label for="lr_import_discovered_file">Choose a <code>discovered-content</code> file to upload:</label>
                        <input type="file" id="lr_import_discovered_file" name="lr_import_file" accept=".json">
                    </p>
                    <?php submit_button('Import Discovered Content', 'secondary', 'lr_import_discovered_submit'); ?>
                </form>
            </div>

            <!-- City Updates Import Form -->
            <div style="padding: 10px; border: 1px solid #ccd0d4;">
                <h4>Import City Update Posts</h4>
                <form method="post" action="" enctype="multipart/form-data">
                    <?php wp_nonce_field('lr_import_data_nonce', 'lr_import_nonce'); ?>
                    <input type="hidden" name="lr_action" value="import_data">
                    <p>
                        <label for="lr_import_updates_file">Choose a <code>city-updates</code> file to upload:</label>
                        <input type="file" id="lr_import_updates_file" name="lr_import_file" accept=".json">
                    </p>
                    <?php submit_button('Import City Update Posts', 'secondary', 'lr_import_updates_submit'); ?>
                </form>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Handles the logic for both export and import actions.
 * This function is hooked to the 'admin_init' action.
 */
function lr_handle_import_export_actions() {
    if (!isset($_POST['lr_action'])) {
        return;
    }

    // Handle Export: Discovered Content
    if ($_POST['lr_action'] === 'export_discovered_content' && isset($_POST['lr_export_nonce']) && wp_verify_nonce($_POST['lr_export_nonce'], 'lr_export_data_nonce')) {
        lr_export_discovered_content_data();
        exit; // Important to prevent any further output
    }

    // Handle Export: City Updates
    if ($_POST['lr_action'] === 'export_city_updates' && isset($_POST['lr_export_nonce']) && wp_verify_nonce($_POST['lr_export_nonce'], 'lr_export_data_nonce')) {
        lr_export_city_updates_data();
        exit; // Important to prevent any further output
    }

    // Handle Import
    if ($_POST['lr_action'] === 'import_data' && isset($_POST['lr_import_nonce']) && wp_verify_nonce($_POST['lr_import_nonce'], 'lr_import_data_nonce')) {
        if (isset($_FILES['lr_import_file']) && $_FILES['lr_import_file']['error'] === UPLOAD_ERR_OK) {
            $file_path = $_FILES['lr_import_file']['tmp_name'];
            $file_content = file_get_contents($file_path);
            $data = json_decode($file_content, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                lr_import_data($data);
            } else {
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-error is-dismissible"><p>Error: Invalid JSON file.</p></div>';
                });
            }
        } else {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error is-dismissible"><p>Error: File upload failed.</p></div>';
            });
        }
    }
}
add_action('admin_init', 'lr_handle_import_export_actions');

/**
 * Exports just the discovered content.
 */
function lr_export_discovered_content_data() {
    global $wpdb;
    $discovered_content_table = $wpdb->prefix . 'lr_discovered_content';
    $data_to_export = [
        'discovered_content' => $wpdb->get_results("SELECT * FROM $discovered_content_table", ARRAY_A),
    ];
    $filename = 'lets-roll-discovered-content-export-' . date('Y-m-d-H-i-s') . '.json';
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename=' . $filename);
    echo json_encode($data_to_export, JSON_PRETTY_PRINT);
    exit;
}

/**
 * Exports just the city update posts.
 */
function lr_export_city_updates_data() {
    global $wpdb;
    $city_updates_table = $wpdb->prefix . 'lr_city_updates';
    $data_to_export = [
        'city_updates' => $wpdb->get_results("SELECT * FROM $city_updates_table", ARRAY_A),
    ];
    $filename = 'lets-roll-city-updates-export-' . date('Y-m-d-H-i-s') . '.json';
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename=' . $filename);
    echo json_encode($data_to_export, JSON_PRETTY_PRINT);
    exit;
}

/**
 * Imports data from an array into the database tables.
 *
 * @param array $data The data to import.
 */
function lr_import_data($data) {
    global $wpdb;

    $discovered_content_table = $wpdb->prefix . 'lr_discovered_content';
    $city_updates_table = $wpdb->prefix . 'lr_city_updates';

    $discovered_content_count = 0;
    $city_updates_count = 0;
    $chunk_size = 500; // Process 500 records at a time

    if (!empty($data['discovered_content'])) {
        $chunks = array_chunk($data['discovered_content'], $chunk_size);
        foreach ($chunks as $chunk) {
            $placeholders = [];
            $values = [];
            $query = "REPLACE INTO $discovered_content_table (`content_id`, `city_id`, `type`, `source_id`, `title`, `image_url`, `url`, `created_at`, `status`, `meta`) VALUES ";

            foreach ($chunk as $row) {
                array_push($values, $row['content_id'], $row['city_id'], $row['type'], $row['source_id'], $row['title'], $row['image_url'], $row['url'], $row['created_at'], $row['status'], $row['meta']);
                $placeholders[] = "(%s, %d, %s, %s, %s, %s, %s, %s, %s, %s)";
            }

            $query .= implode(', ', $placeholders);
            $wpdb->query($wpdb->prepare($query, $values));
            $discovered_content_count += count($chunk);
        }
    }

    if (!empty($data['city_updates'])) {
        $chunks = array_chunk($data['city_updates'], $chunk_size);
        foreach ($chunks as $chunk) {
            $placeholders = [];
            $values = [];
            $query = "REPLACE INTO $city_updates_table (`post_id`, `city_id`, `post_title`, `post_content`, `post_slug`, `featured_image_url`, `time_bucket`, `published_at`) VALUES ";

            foreach ($chunk as $row) {
                // Ensure post_id is treated as an integer
                $post_id = !empty($row['post_id']) ? intval($row['post_id']) : 0;

                array_push($values, $post_id, $row['city_id'], $row['post_title'], $row['post_content'], $row['post_slug'], $row['featured_image_url'], $row['time_bucket'], $row['published_at']);
                
                // If post_id is 0, it will be auto-incremented by the DB. Use NULL placeholder.
                $placeholders[] = $post_id === 0 ? "(NULL, %d, %s, %s, %s, %s, %s, %s)" : "(%d, %d, %s, %s, %s, %s, %s, %s)";
            }
             
            $query .= implode(', ', $placeholders);
            $wpdb->query($wpdb->prepare($query, $values));
            $city_updates_count += count($chunk);
        }
    }

    add_action('admin_notices', function() use ($discovered_content_count, $city_updates_count) {
        $message = "Import successful. Imported {$discovered_content_count} discovered content items and {$city_updates_count} city update posts.";
        echo '<div class="notice notice-success is-dismissible"><p>' . $message . '</p></div>';
    });
}
