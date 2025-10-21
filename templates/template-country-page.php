<?php
/**
 * Renders the content for a country overview page.
 * @return string The HTML content for the page.
 */
function lr_render_country_page_content($country_slug) {
    $country_details = lr_get_country_details($country_slug);
    if (!$country_details) return '<p>Country not found.</p>';

    $output = lr_get_breadcrumbs();
    $output .= '<p>Welcome to the main page for skating in ' . esc_html($country_details['name']) . '.</p>';
    $output .= '<h2>Cities in ' . esc_html($country_details['name']) . ':</h2>';
    $output .= '<ul>';
    foreach ($country_details['cities'] as $city_slug => $city) {
        $output .= '<li><a href="' . esc_url(home_url("/{$country_slug}/{$city_slug}/")) . '">' . esc_html($city['name']) . '</a></li>';
    }
    $output .= '</ul>';

    return $output;
}