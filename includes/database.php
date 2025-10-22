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

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql_content);
    dbDelta($sql_skaters);
}
