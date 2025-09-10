<?php
/**
 * Renders the content for a city overview page.
 */
function lr_render_city_page_content($country_slug, $city_slug) {
    $city_details = lr_get_city_details($country_slug, $city_slug);
    if (!$city_details) return '<p>City not found.</p>';

    $output = ''; // Start with an empty string, the title is handled by the theme now.

    // --- ADDED: Display the rich text description if it exists ---
    if (!empty($city_details['description'])) {
        // Using wpautop to allow for paragraph breaks in the description
        $output .= wpautop(esc_html($city_details['description']));
    } else {
        // Fallback to the generic message if no description is provided
        $output .= '<p>Explore everything the ' . esc_html($city_details['name']) . ' roller skating scene has to offer.</p>';
    }

    $output .= '<h3>Discover:</h3>';
    $output .= '<ul>';

    $base_url = home_url('/' . $country_slug . '/' . $city_slug . '/');
    $output .= '<li><a href="' . esc_url($base_url . 'skatespots/') . '">Skate Spots in ' . esc_html($city_details['name']) . '</a></li>';
    $output .= '<li><a href="' . esc_url($base_url . 'events/') . '">Events in ' . esc_html($city_details['name']) . '</a></li>';
    $output .= '<li><a href="' . esc_url($base_url . 'skaters/') . '">Skaters in ' . esc_html($city_details['name']) . '</a></li>';
    
    $output .= '</ul>';

    return $output;
}


