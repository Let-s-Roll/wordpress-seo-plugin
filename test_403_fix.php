<?php
// Load WordPress environment
if (file_exists(__DIR__ . '/../../../../wp-load.php')) {
    require_once __DIR__ . '/../../../../wp-load.php';
} else {
    echo "Error: Could not load WordPress environment.\n";
    exit(1);
}

// Include necessary plugin files
require_once __DIR__ . '/includes/ai-content.php';
require_once __DIR__ . '/includes/content-discovery.php';

// The URL that was failing with a 403 error
$test_url = 'https://dothebay.com/events/2025/5/20/tuesday-night-roller-disco-tickets';
$link_id = 999; // Test ID
$link_text = 'Tuesday Night Roller Disco';
$city_name = 'San Francisco';

echo "--- Testing User-Agent Fix for 403 Error ---\\n";
echo "URL: " . $test_url . "\\n\\n";

// Clear the log file to isolate this test run's output
$log_file = __DIR__ . '/link_verification.csv';
if (file_exists($log_file)) {
    unlink($log_file);
}

// Call the function we want to test
$result = lr_intelligent_quality_check($test_url, $link_id, $link_text, $test_url, $city_name);

if ($result) {
    echo "SUCCESS: The function returned a valid URL.\n";
    echo "Final URL: " . $result . "\\n\\n";
} else {
    echo "FAILURE: The function returned false.\n\\n";
}

echo "--- Log Output ---\\n";
if (file_exists($log_file)) {
    echo file_get_contents($log_file);
} else {
    echo "Log file was not created.\n";
}
echo "\\n--- End of Test ---\\n";

