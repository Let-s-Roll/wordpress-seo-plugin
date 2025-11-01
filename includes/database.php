<?php

if (!defined('ABSPATH')) exit; // Exit if accessed directly

/**
 * Creates the custom database table for storing discovered content.
 * This function is hooked to run on plugin activation.
 */
function lr_create_discovered_content_table() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    
    // 1. Create the main content ledger table
    $table_name_content = $wpdb->prefix . 'lr_discovered_content';
    $sql_content = "CREATE TABLE $table_name_content (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        content_type varchar(20) NOT NULL,
        api_id varchar(255) NOT NULL,
        city_slug varchar(255) NOT NULL,
        discovered_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        data_cache longtext NOT NULL,
        is_published tinyint(1) NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        UNIQUE KEY api_id_type (api_id, content_type),
        KEY is_published (is_published)
    ) $charset_collate;";

    // 2. Create the table to track seen skaters
    $table_name_skaters = $wpdb->prefix . 'lr_seen_skaters';
    $sql_skaters = "CREATE TABLE $table_name_skaters (
        user_api_id varchar(255) NOT NULL,
        city_slug varchar(255) NOT NULL,
        first_seen_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        PRIMARY KEY  (user_api_id, city_slug)
    ) $charset_collate;";

    // 3. Create the table to store the generated city update posts
    $table_name_updates = $wpdb->prefix . 'lr_city_updates';
    $sql_updates = "CREATE TABLE $table_name_updates (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        city_slug varchar(255) NOT NULL,
        post_slug varchar(255) NOT NULL,
        post_title text NOT NULL,
        post_summary text NOT NULL,
        featured_image_url text NULL,
        post_content longtext NOT NULL,
        publish_date datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY city_post_slug (city_slug, post_slug)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql_content);
    dbDelta($sql_skaters);
    dbDelta($sql_updates);
}

/**
 * Updates the city updates table to include new columns or modify existing ones.
 * This is a non-destructive way to update the table structure.
 */
function lr_update_city_updates_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'lr_city_updates';

    // --- Check and fix post_summary column ---
    $summary_column_info = $wpdb->get_row($wpdb->prepare(
        "SELECT COLUMN_NAME, IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
        DB_NAME, $table_name, 'post_summary'
    ));

    if (empty($summary_column_info)) {
        // Column doesn't exist, so add it.
        $wpdb->query("ALTER TABLE $table_name ADD post_summary text NOT NULL AFTER post_title");
    }

    // --- Check and fix featured_image_url column ---
    $image_column_info = $wpdb->get_row($wpdb->prepare(
        "SELECT COLUMN_NAME, IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
        DB_NAME, $table_name, 'featured_image_url'
    ));

    if (empty($image_column_info)) {
        // Column doesn't exist, so add it correctly allowing NULLs.
        $wpdb->query("ALTER TABLE $table_name ADD featured_image_url text NULL AFTER post_summary");
    } elseif ($image_column_info->IS_NULLABLE === 'NO') {
        // Column exists but is incorrectly set to NOT NULL, so modify it.
        $wpdb->query("ALTER TABLE $table_name MODIFY COLUMN featured_image_url text NULL");
    }
}

/**
 * Adds the is_published column to the discovered content table for existing installations.
 */
function lr_update_discovered_content_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'lr_discovered_content';
    $column_name = 'is_published';

    $column_info = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
        DB_NAME, $table_name, $column_name
    ));

    if (empty($column_info)) {
        $wpdb->query("ALTER TABLE $table_name ADD $column_name tinyint(1) NOT NULL DEFAULT 0");
        $wpdb->query("ALTER TABLE $table_name ADD INDEX `is_published` (`is_published`)");
    }
}
