<?php
/**
 * Renders the content for a detail page (skatespots, events, skaters).
 * This function is called by the main plugin file.
 *
 * @return string The HTML content for the page.
 */
function lr_render_detail_page_content($country_slug, $city_slug, $page_type) {
    $city_details = lr_get_city_details($country_slug, $city_slug);
    if (!$city_details) return '<p>Location not found.</p>';

    $access_token = lr_get_api_access_token();
    $output = ''; // Start with an empty string

    if ($page_type === 'skatespots') {
        $api_params = ['lat' => $city_details['latitude'], 'lng' => $city_details['longitude'], 'limit' => 10];
        $spots_list = lr_fetch_api_data($access_token, 'spots', $api_params);

        if (is_wp_error($spots_list)) {
            $output .= '<p><strong>Error:</strong> ' . esc_html($spots_list->get_error_message()) . '</p>';
        } elseif (is_array($spots_list) && !empty($spots_list)) {
            $output .= '<p>Here are the latest skate spots found in the area:</p><ul>';
            foreach ($spots_list as $item) {
                if (is_object($item) && isset($item->_id)) {
                    $spot_details = lr_fetch_api_data($access_token, 'spots/' . $item->_id, []);
                    $output .= '<li style="margin-bottom: 1em;">';
                    if (!is_wp_error($spot_details) && isset($spot_details->spotWithAddress)) {
                        $spot_info = $spot_details->spotWithAddress;
                        $spot_name = $spot_info->name ?? 'Unnamed Spot';
                        $spot_address = $spot_info->info->address ?? 'No address available';
                        $output .= '<strong>' . esc_html($spot_name) . '</strong><br>';
                        $output .= '<span>' . esc_html($spot_address) . '</span>';
                    } else {
                        $output .= '<strong>Spot ID:</strong> ' . esc_html($item->_id) . ' - <em style="color:red;">Could not load details.</em>';
                    }
                    $output .= '</li>';
                }
            }
            $output .= '</ul>';
        } else {
            $output .= '<p>No skate spots found for this location.</p>';
        }
    } else {
        $output .= '<p>API integration for ' . esc_html($page_type) . ' is coming soon.</p>';
    }

    return $output; // Return the generated HTML string
}