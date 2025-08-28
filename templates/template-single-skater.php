<?php
/**
 * Renders the content for a single skater page.
 *
 * @param string $skater_id The ID of the skater to display.
 * @return string The HTML content for the page.
 */
function lr_render_single_skater_content($skater_id) {
    
    $output = '<h2>Hello from the Single Skater Page!</h2>';
    $output .= '<p>This page will soon show the full details for the skater with the following ID:</p>';
    $output .= '<strong>Skater ID:</strong> ' . esc_html($skater_id);

    return $output;
}
