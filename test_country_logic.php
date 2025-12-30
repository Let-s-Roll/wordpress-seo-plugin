<?php
// Mock WordPress environment variables
$_SERVER['REQUEST_URI'] = '/united-states/';

// Mock plugin functions
function lr_get_location_data() {
    return [
        'united-states' => [
            'name' => 'United States',
            'cities' => [
                'new-york-city' => ['name' => 'New York City']
            ]
        ]
    ];
}

function untrailingslashit($string) {
    return rtrim($string, '/\\');
}

// Copy the logic from includes/seo-metadata.php
function lr_get_page_details_from_uri() {
    if (!isset($_SERVER['REQUEST_URI'])) return null;
    $request_uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $request_uri = untrailingslashit($request_uri);
    
    // Skip static/regex checks for this test... 

    $locations = lr_get_location_data();
    if (empty($locations)) return null;

    $parts = array_values(array_filter(explode('/', $request_uri)));
    if (empty($parts)) return null;

    $country_slug = $parts[0];
    if (isset($locations[$country_slug])) {
        if (count($parts) === 1) return ['type' => 'country', 'country' => $country_slug];
        // ... skip deeper logic
    }
    return null;
}

// Run Test
$details = lr_get_page_details_from_uri();
echo "Detected Type: " . ($details['type'] ?? 'NULL') . "\n";
echo "Country: " . ($details['country'] ?? 'NULL') . "\n";

