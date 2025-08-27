<?php
/**
 * Location Data for Let's Roll SEO Pages Plugin
 *
 * This file contains the array of all supported countries and cities.
 * The keys (e.g., 'united-states', 'new-york') should be lowercase and URL-friendly.
 */

// Prevent direct file access for security
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

return [
    'united-states' => [
        'name' => 'United States',
        'cities' => [
            'new-york' => ['name' => 'New York', 'latitude' => 40.7128, 'longitude' => -74.0060, 'radius_km' => 30],
            'los-angeles' => ['name' => 'Los Angeles', 'latitude' => 34.0522, 'longitude' => -118.2437, 'radius_km' => 40],
            'chicago' => ['name' => 'Chicago', 'latitude' => 41.8781, 'longitude' => -87.6298, 'radius_km' => 25],
            'houston' => ['name' => 'Houston', 'latitude' => 29.7604, 'longitude' => -95.3698, 'radius_km' => 35],
            'phoenix' => ['name' => 'Phoenix', 'latitude' => 33.4484, 'longitude' => -112.0740, 'radius_km' => 30],
            'philadelphia' => ['name' => 'Philadelphia', 'latitude' => 39.9526, 'longitude' => -75.1652, 'radius_km' => 20],
            'san-antonio' => ['name' => 'San Antonio', 'latitude' => 29.4241, 'longitude' => -98.4936, 'radius_km' => 25],
            'san-diego' => ['name' => 'San Diego', 'latitude' => 32.7157, 'longitude' => -117.1611, 'radius_km' => 25],
            'dallas' => ['name' => 'Dallas', 'latitude' => 32.7767, 'longitude' => -96.7970, 'radius_km' => 30],
            'san-francisco' => ['name' => 'San Francisco', 'latitude' => 37.7749, 'longitude' => -122.4194, 'radius_km' => 20],
        ],
    ],
    'united-kingdom' => [
        'name' => 'United Kingdom',
        'cities' => [
            'london' => ['name' => 'London', 'latitude' => 51.5074, 'longitude' => -0.1278, 'radius_km' => 30],
            'manchester' => ['name' => 'Manchester', 'latitude' => 53.4808, 'longitude' => -2.2426, 'radius_km' => 20],
            'birmingham' => ['name' => 'Birmingham', 'latitude' => 52.4862, 'longitude' => -1.8904, 'radius_km' => 20],
            'glasgow' => ['name' => 'Glasgow', 'latitude' => 55.8642, 'longitude' => -4.2518, 'radius_km' => 18],
        ],
    ],
    'germany' => [
        'name' => 'Germany',
        'cities' => [
            'berlin' => ['name' => 'Berlin', 'latitude' => 52.5200, 'longitude' => 13.4050, 'radius_km' => 25],
            'hamburg' => ['name' => 'Hamburg', 'latitude' => 53.5511, 'longitude' => 9.9937, 'radius_km' => 22],
            'munich' => ['name' => 'Munich', 'latitude' => 48.1351, 'longitude' => 11.5820, 'radius_km' => 20],
            'cologne' => ['name' => 'Cologne', 'latitude' => 50.9375, 'longitude' => 6.9603, 'radius_km' => 18],
        ],
    ],
    'france' => [
        'name' => 'France',
        'cities' => [
            'paris' => ['name' => 'Paris', 'latitude' => 48.8566, 'longitude' => 2.3522, 'radius_km' => 25],
            'marseille' => ['name' => 'Marseille', 'latitude' => 43.2965, 'longitude' => 5.3698, 'radius_km' => 20],
            'lyon' => ['name' => 'Lyon', 'latitude' => 45.7640, 'longitude' => 4.8357, 'radius_km' => 18],
        ],
    ],
    'spain' => [
        'name' => 'Spain',
        'cities' => [
            'madrid' => ['name' => 'Madrid', 'latitude' => 40.4168, 'longitude' => -3.7038, 'radius_km' => 25],
            'barcelona' => ['name' => 'Barcelona', 'latitude' => 41.3851, 'longitude' => 2.1734, 'radius_km' => 20],
        ],
    ],
    'italy' => [
        'name' => 'Italy',
        'cities' => [
            'rome' => ['name' => 'Rome', 'latitude' => 41.9028, 'longitude' => 12.4964, 'radius_km' => 25],
            'milan' => ['name' => 'Milan', 'latitude' => 45.4642, 'longitude' => 9.1900, 'radius_km' => 20],
        ],
    ],
    'denmark' => [
        'name' => 'Denmark',
        'cities' => [
            'copenhagen' => ['name' => 'Copenhagen', 'latitude' => 55.6761, 'longitude' => 12.5683, 'radius_km' => 20],
        ],
    ],
    'australia' => [
        'name' => 'Australia',
        'cities' => [
            'sydney' => ['name' => 'Sydney', 'latitude' => -33.8688, 'longitude' => 151.2093, 'radius_km' => 30],
        ],
    ],
];