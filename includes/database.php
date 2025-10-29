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
        PRIMARY KEY  (id),
        UNIQUE KEY api_id_type (api_id, content_type)
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
 * Updates the city updates table to include new columns if they don't exist.
 * This is a non-destructive way to update the table structure after the initial creation.
 */
function lr_update_city_updates_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'lr_city_updates';
    $column_name = 'post_summary';

    // Check if the column already exists
    $column = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
        DB_NAME, $table_name, $column_name
    ));

    // If the column does not exist, add it.
    if (empty($column)) {
        $wpdb->query("ALTER TABLE $table_name ADD $column_name text NOT NULL AFTER post_title");
    }
}
