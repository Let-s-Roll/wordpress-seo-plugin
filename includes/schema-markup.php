<?php
/**
 * JSON-LD Schema Markup Generator
 * 
 * Handles the dynamic generation of structured data (JSON-LD) for Let's Roll SEO pages.
 * This improves Google's understanding of the content, enabling Rich Snippets.
 */

if (!defined('ABSPATH')) exit;

// Hook into wp_head to output the JSON-LD script
add_action('wp_head', 'lr_output_json_ld_schema', 5); // Priority 5 to run before other SEO tags

function lr_output_json_ld_schema() {
    // Only run on our virtual pages
    if (!function_exists('lr_get_page_details_from_uri')) return; 
    
    $page_details = lr_get_page_details_from_uri();
    if (!$page_details) return;

    $data = lr_get_current_page_api_data();
    if (!$data) return;

    $schema_graph = [];

    // 1. Global Breadcrumbs (All Pages)
    $breadcrumbs = lr_generate_breadcrumb_schema($page_details, $data);
    if ($breadcrumbs) {
        $schema_graph[] = $breadcrumbs;
    }

    // 2. Entity Specific Schema
    $entity_schema = null;
    switch ($page_details['type']) {
        case 'spots':
            $entity_schema = lr_generate_spot_schema($data);
            break;
        case 'events':
            $entity_schema = lr_generate_event_schema($data);
            break;
        case 'skaters':
            $entity_schema = lr_generate_person_schema($data);
            break;
        case 'city':
        case 'list':
            $entity_schema = lr_generate_collection_page_schema($data, $page_details);
            break;
    }

    if ($entity_schema) {
        $schema_graph[] = $entity_schema;
    }

    // Output the JSON-LD block
    if (!empty($schema_graph)) {
        echo "\n<!-- Let's Roll JSON-LD Schema -->\n";
        echo '<script type="application/ld+json">\n';
        echo json_encode(['@context' => 'https://schema.org', '@graph' => $schema_graph], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        echo "\n</script>\n";
        echo "<!-- End Let's Roll Schema -->\n\n";
    }
}

/**
 * Generates BreadcrumbList Schema
 */
function lr_generate_breadcrumb_schema($page_details, $data) {
    $items = [];
    $position = 1;

    // Home
    $items[] = [
        '@type' => 'ListItem',
        'position' => $position++,
        'name' => 'Home',
        'item' => home_url('/')
    ];

    // Explore
    $items[] = [
        '@type' => 'ListItem',
        'position' => $position++,
        'name' => 'Explore',
        'item' => home_url('/explore/')
    ];

    // Country
    if (isset($page_details['country'])) {
        $country_data = lr_get_country_details($page_details['country']);
        $country_name = $country_data['name'] ?? ucfirst($page_details['country']);
        $items[] = [
            '@type' => 'ListItem',
            'position' => $position++,
            'name' => $country_name,
            'item' => home_url('/' . $page_details['country'] . '/')
        ];
    }

    // City
    if (isset($page_details['city'])) {
        $city_data = lr_get_city_details($page_details['country'], $page_details['city']);
        $city_name = $city_data['name'] ?? ucfirst($page_details['city']);
        $items[] = [
            '@type' => 'ListItem',
            'position' => $position++,
            'name' => $city_name,
            'item' => home_url('/' . $page_details['country'] . '/' . $page_details['city'] . '/')
        ];
    }

    // Final Item (The current page)
    // We strictly do NOT link the last item in breadcrumb schema
    $current_name = '';
    switch ($page_details['type']) {
        case 'spots': $current_name = $data->spotWithAddress->name ?? 'Spot'; break;
        case 'events': $current_name = $data->name ?? 'Event'; break;
        case 'skaters': $current_name = $data->skateName ?? 'Skater'; break;
        case 'list': $current_name = ucfirst($page_details['list_type']); break;
    }

    if ($current_name && $page_details['type'] !== 'city') {
        $items[] = [
            '@type' => 'ListItem',
            'position' => $position++,
            'name' => $current_name
        ];
    }

    return [
        '@type' => 'BreadcrumbList',
        'itemListElement' => $items
    ];
}

/**
 * Generates SportsActivityLocation Schema for Spots
 */
function lr_generate_spot_schema($data) {
    if (empty($data->spotWithAddress)) return null;
    $spot = $data->spotWithAddress;

    $schema = [
        '@type' => 'SportsActivityLocation', // Specific type for skate spots
        'name' => $spot->name,
        'url' => home_url($_SERVER['REQUEST_URI']),
        'description' => 'A popular roller skating spot in ' . ($spot->address->city ?? 'the area') . '.',
    ];

    // Image
    if (!empty($spot->satelliteAttachment)) {
        $schema['image'] = plugins_url('image-proxy.php?type=spot_satellite&id=' . $spot->satelliteAttachment . '&width=800', dirname(__FILE__));
    }

    // Address
    if (!empty($spot->info->address)) {
        $schema['address'] = [
            '@type' => 'PostalAddress',
            'streetAddress' => $spot->info->address
        ];
    }

    // Geo
    if (!empty($spot->location->coordinates)) {
        $schema['geo'] = [
            '@type' => 'GeoCoordinates',
            'latitude' => $spot->location->coordinates[1],
            'longitude' => $spot->location->coordinates[0]
        ];
    }

    // Aggregate Rating
    if (!empty($spot->rating->ratingsCount) && $spot->rating->ratingsCount > 0) {
        $avg_rating = round($spot->rating->totalValue / $spot->rating->ratingsCount, 1);
        $schema['aggregateRating'] = [
            '@type' => 'AggregateRating',
            'ratingValue' => $avg_rating,
            'reviewCount' => $spot->rating->ratingsCount,
            'bestRating' => '5',
            'worstRating' => '1'
        ];
    }

    return $schema;
}

/**
 * Generates Event Schema
 */
function lr_generate_event_schema($data) {
    if (empty($data->name)) return null;

    $schema = [
        '@type' => 'Event',
        'name' => $data->name,
        'url' => home_url($_SERVER['REQUEST_URI']),
        'description' => wp_strip_all_tags($data->description ?? ''),
        'eventStatus' => 'https://schema.org/EventScheduled',
        'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode'
    ];

    // Dates
    if (!empty($data->event->startDate)) {
        $schema['startDate'] = $data->event->startDate;
    }
    if (!empty($data->event->endDate)) {
        $schema['endDate'] = $data->event->endDate;
    }

    // Image
    if (!empty($data->attachments)) {
        $schema['image'] = plugins_url('image-proxy.php?type=event_attachment&id=' . $data->attachments[0]->_id . '&session_id=' . $data->_id . '&width=800', dirname(__FILE__));
    }

    // Location (Using Place)
    // Ideally we would map this to a known Spot if available, but for now we use the raw address
    if (!empty($data->event->address)) {
        $address_obj = json_decode($data->event->address);
        if ($address_obj && isset($address_obj->formatted_address)) {
            $schema['location'] = [
                '@type' => 'Place',
                'name' => $address_obj->formatted_address, // Fallback name
                'address' => [
                    '@type' => 'PostalAddress',
                    'streetAddress' => $address_obj->formatted_address
                ]
            ];
        }
    }

    // Organizer (Person)
    if (!empty($data->userProfiles[0])) {
        $organizer = $data->userProfiles[0];
        $schema['organizer'] = [
            '@type' => 'Person',
            'name' => $organizer->skateName ?? $organizer->firstName,
            'url' => home_url('/skaters/' . ($organizer->skateName ?? $organizer->userId) . '/')
        ];
    }

    return $schema;
}

/**
 * Generates Person Schema for Skaters
 */
function lr_generate_person_schema($data) {
    if (empty($data->userId)) return null;

    $schema = [
        '@type' => 'Person',
        'name' => $data->skateName ?? $data->firstName,
        'url' => home_url($_SERVER['REQUEST_URI']),
        'image' => 'https://beta.web.lets-roll.app/api/user/' . $data->userId . '/avatar/content/processed?width=500',
    ];

    if (!empty($data->publicBio)) {
        $schema['description'] = wp_strip_all_tags($data->publicBio);
    }

    if (!empty($data->instagramUsername)) {
        $schema['sameAs'][] = 'https://instagram.com/' . $data->instagramUsername;
    }

    return $schema;
}

/**
 * Generates CollectionPage Schema for Lists/Cities with embedded ItemList
 */
function lr_generate_collection_page_schema($data, $page_details) {
    $schema = [
        '@type' => 'CollectionPage',
        'url' => home_url($_SERVER['REQUEST_URI']),
    ];

    $list_items = [];
    $city_name = $data->name ?? 'City';

    if ($page_details['type'] === 'city') {
        $schema['name'] = 'Roller Skating in ' . $city_name;
        $schema['description'] = 'Find the best skate spots, events, and skaters in ' . $city_name . '.';
        
        // For the main city page, we feature the Top Spots
        $spots = lr_get_spots_for_city((array)$data);
        if (!is_wp_error($spots) && !empty($spots)) {
            usort($spots, function($a, $b) { return ($b->sessionsCount ?? 0) <=> ($a->sessionsCount ?? 0); });
            $top_spots = array_slice($spots, 0, 6);
            $position = 1;
            foreach ($top_spots as $spot) {
                // Fetch spot details for the name if possible, or fallback to API structure
                // Optimization: To avoid N+1 API calls here just for schema, we might skip the full details 
                // if we don't have them cached. However, the spot list object often lacks the name.
                // Let's rely on the cache.
                $access_token = lr_get_api_access_token();
                $spot_detail = lr_fetch_api_data($access_token, 'spots/' . $spot->_id, []);
                
                if (!is_wp_error($spot_detail) && isset($spot_detail->spotWithAddress)) {
                    $list_items[] = [
                        '@type' => 'ListItem',
                        'position' => $position++,
                        'item' => [
                            '@type' => 'SportsActivityLocation',
                            'name' => $spot_detail->spotWithAddress->name,
                            'url' => home_url('/spots/' . $spot->_id . '/')
                        ]
                    ];
                }
            }
        }

    } elseif ($page_details['type'] === 'list') {
        $list_type = $page_details['list_type'];
        $list_name_display = ($list_type === 'skatespots') ? 'Skate Spots' : ucfirst($list_type);
        $schema['name'] = $list_name_display . ' in ' . $city_name;
        
        if ($list_type === 'skatespots') {
            $spots = lr_get_spots_for_city((array)$data);
            if (!is_wp_error($spots) && !empty($spots)) {
                usort($spots, function($a, $b) { return ($b->sessionsCount ?? 0) <=> ($a->sessionsCount ?? 0); });
                $top_spots = array_slice($spots, 0, 10); // Top 10 for the list page
                $position = 1;
                foreach ($top_spots as $spot) {
                    $access_token = lr_get_api_access_token();
                    $spot_detail = lr_fetch_api_data($access_token, 'spots/' . $spot->_id, []);
                    if (!is_wp_error($spot_detail) && isset($spot_detail->spotWithAddress)) {
                        $list_items[] = [
                            '@type' => 'ListItem',
                            'position' => $position++,
                            'item' => [
                                '@type' => 'SportsActivityLocation',
                                'name' => $spot_detail->spotWithAddress->name,
                                'url' => home_url('/spots/' . $spot->_id . '/')
                            ]
                        ];
                    }
                }
            }
        } elseif ($list_type === 'events') {
            $events = lr_get_events_for_city((array)$data);
            if (!is_wp_error($events) && !empty($events)) {
                $now = new DateTime();
                $upcoming = array_filter($events, function($event) use ($now) {
                    return isset($event->event->endDate) && (new DateTime($event->event->endDate) > $now);
                });
                usort($upcoming, function($a, $b) { return strtotime($a->event->startDate) <=> strtotime($b->event->startDate); });
                $top_events = array_slice($upcoming, 0, 10);
                $position = 1;
                foreach ($top_events as $event) {
                    $list_items[] = [
                        '@type' => 'ListItem',
                        'position' => $position++,
                        'item' => [
                            '@type' => 'Event',
                            'name' => $event->name,
                            'url' => home_url('/events/' . $event->_id . '/')
                        ]
                    ];
                }
            }
        }
    }

    if (!empty($list_items)) {
        $schema['mainEntity'] = [
            '@type' => 'ItemList',
            'itemListElement' => $list_items
        ];
    }

    return $schema;
}
