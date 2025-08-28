<?php
/**
 * Renders the content for a single spot page.
 *
 * @param string $spot_id The ID of the spot to display.
 * @return string The HTML content for the page.
 */
function lr_render_single_spot_content($spot_id) {
    // This is a placeholder for now.
    // In the next step, we will make an API call here to get the spot's details.
    
    $output = '<h2>Hello from the Single Spot Page!</h2>';
    $output .= '<p>This page will soon show the full details for the spot with the following ID:</p>';
    $output .= '<strong>Spot ID:</strong> ' . esc_html($spot_id);

    return $output;
}
