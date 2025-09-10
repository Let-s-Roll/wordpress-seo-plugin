<?php
/**
 * Renders the content for the main "Explore" page.
 */
function lr_render_explore_page_content() {
    $locations = lr_get_location_data();
    if (empty($locations)) return '<p>No locations have been configured yet.</p>';

    $output = '<p>Select a country below to explore local roller skating scenes, spots, and events.</p>';
    $output .= '<ul style="list-style-type: none; padding-left: 0;">';

    // Sort countries alphabetically by name
    uasort($locations, function($a, $b) {
        return strcmp($a['name'], $b['name']);
    });

    foreach ($locations as $country_slug => $country_details) {
        $country_name = $country_details['name'] ?? ucfirst(str_replace('-', ' ', $country_slug));
        $country_url = home_url('/' . $country_slug . '/');
        
        $output .= '<li style="margin-bottom: 10px; font-size: 1.2em;">';
        $output .= '<a href="' . esc_url($country_url) . '">' . esc_html($country_name) . '</a>';
        $output .= '</li>';
    }

    $output .= '</ul>';

    return $output;
}

