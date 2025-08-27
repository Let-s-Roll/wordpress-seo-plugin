<?php
/**
 * Renders the content for a city overview page.
 * @return string The HTML content for the page.
 */
function lr_render_city_page_content($country_slug, $city_slug) {
    $city_details = lr_get_city_details($country_slug, $city_slug);
    if (!$city_details) return '<p>City not found.</p>';

    $output = '<p>Explore everything the ' . esc_html($city_details['name']) . ' roller skating scene has to offer.</p>';
    $output .= '<h2>Explore ' . esc_html($city_details['name']) . ':</h2>';
    $output .= '<ul>';
    $output .= '<li><a href="' . esc_url(home_url("/{$country_slug}/{$city_slug}/skatespots/")) . '">Skate Spots</a></li>';
    $output .= '<li><a href="' . esc_url(home_url("/{$country_slug}/{$city_slug}/events/")) . '">Events</a></li>';
    $output .= '<li><a href="' . esc_url(home_url("/{$country_slug}/{$city_slug}/skaters/")) . '">Local Skaters</a></li>';
    $output .= '</ul>';

    return $output;
}