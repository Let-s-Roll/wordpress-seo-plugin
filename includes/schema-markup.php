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
 * Generates CollectionPage Schema for Lists/Cities
 */
function lr_generate_collection_page_schema($data, $page_details) {
    $schema = [
        '@type' => 'CollectionPage',
        'url' => home_url($_SERVER['REQUEST_URI']),
    ];

    if ($page_details['type'] === 'city') {
        $schema['name'] = 'Roller Skating in ' . ($data->name ?? 'City');
        $schema['description'] = 'Find the best skate spots, events, and skaters in ' . ($data->name ?? 'City') . '.';
    } elseif ($page_details['type'] === 'list') {
        $list_name = ($page_details['list_type'] === 'skatespots') ? 'Skate Spots' : ucfirst($page_details['list_type']);
        $schema['name'] = $list_name . ' in ' . ($data->name ?? 'City');
    }

    return $schema;
}
