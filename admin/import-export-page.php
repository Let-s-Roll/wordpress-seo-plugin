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
            <p>Click the button below to generate and download a JSON file containing all data from the <code>wp_lr_discovered_content</code> and <code>wp_lr_city_updates</code> tables.</p>
            <form method="post" action="">
                <?php wp_nonce_field('lr_export_data_nonce', 'lr_export_nonce'); ?>
                <input type="hidden" name="lr_action" value="export_data">
                <?php submit_button('Export All Data', 'primary', 'lr_export_submit'); ?>
            </form>
        </div>

        <!-- Import Section -->
        <div class="card" style="margin-top: 20px;">
            <h2>Import Data</h2>
            <p>Upload a JSON file that was previously exported from this plugin. The data will be imported into the database.</p>
            <form method="post" action="" enctype="multipart/form-data">
                <?php wp_nonce_field('lr_import_data_nonce', 'lr_import_nonce'); ?>
                <input type="hidden" name="lr_action" value="import_data">
                <p>
                    <label for="lr_import_file">Choose a file to upload:</label>
                    <input type="file" id="lr_import_file" name="lr_import_file" accept=".json">
                </p>
                <?php submit_button('Import Data', 'secondary', 'lr_import_submit'); ?>
            </form>
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

    // Handle Export
    if ($_POST['lr_action'] === 'export_data' && isset($_POST['lr_export_nonce']) && wp_verify_nonce($_POST['lr_export_nonce'], 'lr_export_data_nonce')) {
        lr_export_data();
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
 * Queries the database, packages the data, and serves it as a JSON file.
 */
function lr_export_data() {
    global $wpdb;

    $discovered_content_table = $wpdb->prefix . 'lr_discovered_content';
    $city_updates_table = $wpdb->prefix . 'lr_city_updates';

    $data_to_export = [
        'discovered_content' => $wpdb->get_results("SELECT * FROM $discovered_content_table", ARRAY_A),
        'city_updates'       => $wpdb->get_results("SELECT * FROM $city_updates_table", ARRAY_A),
    ];

    $filename = 'lets-roll-data-export-' . date('Y-m-d-H-i-s') . '.json';

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

    if (!empty($data['discovered_content'])) {
        foreach ($data['discovered_content'] as $row) {
            $wpdb->replace($discovered_content_table, $row);
            $discovered_content_count++;
        }
    }

    if (!empty($data['city_updates'])) {
        foreach ($data['city_updates'] as $row) {
            $wpdb->replace($city_updates_table, $row);
            $city_updates_count++;
        }
    }

    add_action('admin_notices', function() use ($discovered_content_count, $city_updates_count) {
        $message = "Import successful. Imported {$discovered_content_count} discovered content items and {$city_updates_count} city update posts.";
        echo '<div class="notice notice-success is-dismissible"><p>' . $message . '</p></div>';
    });
}
