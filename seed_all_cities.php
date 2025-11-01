<?php
// Ensure this script doesn't time out.
set_time_limit(0);

// Ensure we're in a WordPress environment.
if (!defined('ABSPATH')) {
    require_once(dirname(__FILE__) . '/../../../wp-load.php');
}

// Security check: Only admins can run this.
if (!current_user_can('manage_options')) {
    wp_die('You do not have sufficient permissions to access this page.');
}

// Basic styling for progress output.
echo '<!DOCTYPE html><html><head><title>Seeding All Cities</title>';
echo '<style>body { font-family: monospace; background: #f1f1f1; color: #333; line-height: 1.6; padding: 20px; } .log { margin-bottom: 5px; } .success { color: green; } .error { color: red; } .done { font-weight: bold; margin-top: 20px; }</style>';
echo '</head><body><h1>Seeding Historical Posts for All Cities</h1>';
echo '<p>This may take a very long time. Please do not close this window until you see the "All Done!" message.</p>';

// Function to flush output to the browser.
function flush_buffers() {
    echo str_pad('', 4096) . "\n";
    ob_flush();
    flush();
}

// Get all cities.
$all_locations = lr_get_location_data();
if (empty($all_locations)) {
    echo '<p class="error">Could not load location data. Aborting.</p>';
    exit;
}

$all_cities = [];
foreach ($all_locations as $country_data) {
    if (!empty($country_data['cities'])) {
        foreach ($country_data['cities'] as $city_slug => $city_details) {
            $all_cities[$city_slug] = $city_details['name'];
        }
    }
}

$total_cities = count($all_cities);
$current_city_num = 0;

// Loop through each city and run the seeder.
foreach ($all_cities as $city_slug => $city_name) {
    $current_city_num++;
    echo '<div class="log">(' . $current_city_num . '/' . $total_cities . ') Processing ' . esc_html($city_name) . ' (' . esc_html($city_slug) . ')... ';
    flush_buffers();

    // Run the seeder function for the city.
    lr_run_historical_seeding_for_city($city_slug);

    echo '<span class="success">Done.</span></div>';
    flush_buffers();
}

echo '<p class="done">All Done! All cities have been seeded.</p>';
echo '</body></html>';
