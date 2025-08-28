<?php
/**
 * Renders the content for a single event page.
 *
 * @param string $event_id The ID of the event to display.
 * @return string The HTML content for the page.
 */
function lr_render_single_event_content($event_id) {
    
    $output = '<h2>Hello from the Single Event Page!</h2>';
    $output .= '<p>This page will soon show the full details for the event with the following ID:</p>';
    $output .= '<strong>Event ID:</strong> ' . esc_html($event_id);

    return $output;
}
