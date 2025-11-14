<?php

if (!defined('ABSPATH')) exit; // Exit if accessed directly

/**
 * Generates content for a city update post using an AI model.
 *
 * @param array $content_data The structured data of new content (spots, events, etc.).
 * @return array|WP_Error An array containing 'post_title', 'post_content', and 'post_summary', or a WP_Error on failure.
 */
function lr_get_ai_generated_content($content_data) {
    $options = get_option('lr_options');
    $api_key = $options['gemini_api_key'] ?? '';
    $model = $options['gemini_model'] ?? '';
    $prompt = lr_build_ai_prompt($content_data);
    $api_url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . $api_key;

    $body = [
        'contents' => [['parts' => [['text' => $prompt]]]],
    ];

    $response = wp_remote_post($api_url, [
        'headers' => ['Content-Type' => 'application/json'],
        'body'    => json_encode($body),
        'timeout' => 60,
    ]);

    if (is_wp_error($response)) {
        return $response;
    }

    $response_body = wp_remote_retrieve_body($response);
    $data = json_decode($response_body, true);

    $ai_response_text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    if (empty($ai_response_text)) {
        return new WP_Error('ai_error', 'Failed to get a valid response from the AI. Raw response: ' . $response_body);
    }

    // Clean the response to extract the JSON part
    preg_match('/```json(.*)```/s', $ai_response_text, $matches);
    $json_string = $matches[1] ?? '';
    $content_json = json_decode(trim($json_string), true);

    if (json_last_error() !== JSON_ERROR_NONE || empty($content_json['post_title']) || empty($content_json['top_summary'])) {
        return new WP_Error('ai_json_error', 'AI response was not valid JSON or was missing required fields. Raw text: ' . $ai_response_text);
    }

    return $content_json;
}

/**
 * Builds the dynamic prompt for the AI based on the content data.
 *
 * @param array $content_data The structured data of new content.
 * @param bool $enable_web_search Whether to include web search instructions.
 * @return string The generated prompt.
 */
function lr_build_ai_prompt($content_data) {
    $city_name = $content_data['city_name'];

    $prompt = "You are an expert SEO content writer and a passionate, authentic roller skater for the Let's Roll community website. Your tone is enthusiastic, helpful, and authoritative.\n\n"
            . "You will be given two sets of data for {$city_name}: `internal_data` (our app's discoveries) and potentially `external_data` (pre-researched web findings).\n\n"
            . "**Primary Goal:** Your main goal is to write a cohesive, engaging summary of the city's skate scene for the given period, prioritizing the `internal_data`.\n\n"
            . "**Instructions:**\n"
            . "1.  **Analyze All Data:** First, analyze the `internal_data` to find the most exciting local discoveries. Then, analyze the `external_data` (if provided) for any major, relevant news or events.\n"
            . "2.  **Act as an Editor (Safety Valve):** This is your most important task. You **must** evaluate the quality of the `external_data`. If it is low-quality, irrelevant, or does not add significant value, you **must ignore it completely** and write the summary based only on the `internal_data`.\n"
            . "3.  **Generate a Title:** Create a catchy, SEO-friendly `post_title` that is forward-looking (use the upcoming month). Your title should incorporate the most exciting piece of information you found in *either* data source.\n"
            . "4.  **Generate an Introduction (`top_summary`):** Write a `top_summary` (1-2 paragraphs). Your task is to intelligently synthesize a single narrative from both data sources. Prioritize our `internal_data`, but seamlessly weave in the most important findings from the `external_data`. When you use external information, you **must** embed the source link directly in the text using Markdown format (e.g., [Event Name](https://example.com)).\n"
            . "5.  **Generate Section Content:** For each content type in `internal_data` (spots, events, skaters, etc.), create an object with a `heading` and an `intro`. The `intro` must reference specific content from the data to be authentic.\n"
            . "6.  **Generate an Archive Summary (`post_summary`):** Write a short (1-2 sentence) summary for archive pages.\n"
            . "7.  **Format the Output:** Return your response as a single, clean JSON object inside a ```json code block. The JSON object must have these exact keys: `post_title`, `top_summary`, `spots_section`, `events_section`, `skaters_section`, `reviews_section`, `sessions_section`, `post_summary`.\n\n"
            . "**JSON Data:**\n"
            . "```json\n"
            . json_encode($content_data, JSON_PRETTY_PRINT) . "\n"
            . "```";

    return $prompt;
}

/**
 * Step 1 of 2: The "Researcher" AI.
 * This function asks the AI to use its search tool to find external data
 * and return it as a structured JSON object.
 *
 * @param string $city_name The name of the city.
 * @param string $publication_date The date for context.
 * @return array|WP_Error The structured external data or an error.
 */
function lr_get_external_data_from_ai($city_name, $publication_date) {
    $options = get_option('lr_options');
    $api_key = $options['gemini_api_key'] ?? '';
    $model = $options['gemini_model'] ?? '';

    if (empty($api_key) || empty($model)) {
        return new WP_Error('misconfigured', 'Gemini API is not configured.');
    }

    $month = date('F', strtotime($publication_date));
    $year = date('Y', strtotime($publication_date));

    $prompt = "You are a research assistant. Your sole task is to find information about roller skating and inline skating for a specific city and month.\n\n"
            . "Use your Google Search tool to find news and events related to roller skating or inline skating in {$city_name} for {$month} {$year}.\n\n"
            . "Return your findings as a single, clean JSON object inside a ```json code block. The JSON object should have two keys: `news` and `events`. Each key should be an array of objects, where each object contains `title`, `link`, and a brief `summary`.\n\n"
            . "If you find no relevant information for a key, return an empty array for it. If you find nothing at all, return empty arrays for both.";

    $api_url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . $api_key;
    $body = [
        'contents' => [['parts' => [['text' => $prompt]]]],
        'tools' => [['google_search' => new stdClass()]] // Corrected tool name
    ];

    $response = wp_remote_post($api_url, [
        'headers' => ['Content-Type' => 'application/json'],
        'body'    => json_encode($body),
        'timeout' => 60,
    ]);

    if (is_wp_error($response)) {
        return $response;
    }

    $response_body = wp_remote_retrieve_body($response);
    $data = json_decode($response_body, true);
    $ai_response_text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

    if (empty($ai_response_text)) {
        return new WP_Error('ai_error', 'Researcher AI returned an empty response.');
    }

    preg_match('/```json(.*)```/s', $ai_response_text, $matches);
    $json_string = $matches[1] ?? '';
    $structured_data = json_decode(trim($json_string), true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        return new WP_Error('ai_json_error', 'Researcher AI response was not valid JSON. Raw text: ' . $ai_response_text);
    }

    return $structured_data;
}



/**
 * Fetches the list of available Gemini models that support content generation.
 *
 * @param string $api_key The Gemini API key.
 * @return array|WP_Error A list of model names or a WP_Error on failure.
 */
function lr_get_gemini_models($api_key) {
    if (empty($api_key)) {
        return new WP_Error('no_api_key', 'API key is not provided.');
    }

    $api_url = 'https://generativelanguage.googleapis.com/v1beta/models?key=' . $api_key;
    $response = wp_remote_get($api_url, ['timeout' => 20]);

    if (is_wp_error($response)) {
        return $response;
    }

    $response_body = wp_remote_retrieve_body($response);
    $data = json_decode($response_body, true);

    if (isset($data['error'])) {
        return new WP_Error('api_error', $data['error']['message']);
    }

    if (empty($data['models'])) {
        return new WP_Error('no_models', 'No models found in the API response.');
    }

    $supported_models = [];
    foreach ($data['models'] as $model) {
        if (in_array('generateContent', $model['supportedGenerationMethods'])) {
            // Extract the model name after "models/"
            $model_name = str_replace('models/', '', $model['name']);
            $supported_models[] = $model_name;
        }
    }

    return $supported_models;
}

/**
 * Verifies a link using the Google Custom Search API.
 *
 * This function can perform two types of searches:
 * - 'broad': Uses a template to search for link text combined with city and date context.
 * - 'refresh': Takes a full URL as the query to find its new, updated location.
 *
 * @param string $query_input    The text or URL to search for.
 * @param string $city_name      The city for context (used in 'broad' search).
 * @param string $publication_date The date for context (used in 'broad' search).
 * @param string $original_url   The original URL from the AI for logging purposes.
 * @param string $search_type    The type of search to perform ('broad' or 'refresh').
 * @return string|WP_Error The corrected URL or a WP_Error on failure.
 */
function lr_verify_link_with_google_search($query_input, $city_name, $publication_date, $original_url, $search_type = 'broad', $link_id = null, $link_text = '') {
    $options = get_option('lr_options');
    $api_key = $options['google_search_api_key'] ?? '';
    $engine_id = $options['google_search_engine_id'] ?? '';
    $human_readable_query = '';
    $num_results = ($search_type === 'broad') ? 5 : 1; // Get 5 results for broad search, 1 for refresh

    if ($search_type === 'refresh') {
        $human_readable_query = $query_input;
    } else {
        $timestamp = strtotime($publication_date);
        $month = date('F', $timestamp);
        $year = date('Y', $timestamp);

        $query_template = $options['google_search_query_template'] ?? '"{link_text}" {city_name}';
        $human_readable_query = str_replace(
            ['{link_text}', '{city_name}', '{month}', '{year}'],
            [$query_input, $city_name, $month, $year],
            $query_template
        );
    }

    $encoded_query = rawurlencode($human_readable_query);
    $api_url = "https://www.googleapis.com/customsearch/v1?key={$api_key}&cx={$engine_id}&q={$encoded_query}&num={$num_results}";

    if (empty($api_key) || empty($engine_id)) {
        lr_log_link_verification_csv([
            'link_id' => $link_id, 'link_text' => $link_text, 'original_url' => $original_url, 
            'query' => $api_url, 'status' => 'FAILURE',
            'notes' => "Google Custom Search API is not configured. Search Type: {$search_type}."
        ]);
        return new WP_Error('misconfigured', 'Google Custom Search API is not configured.');
    }

    $response = wp_remote_get($api_url, ['timeout' => 30]);

    if (is_wp_error($response)) {
        lr_log_link_verification_csv([
            'link_id' => $link_id, 'link_text' => $link_text, 'original_url' => $original_url, 
            'query' => $api_url, 'status' => 'FAILURE',
            'notes' => "WP_Error on API call: " . $response->get_error_message() . " Search Type: {$search_type}."
        ]);
        return $response;
    }

    $response_body = wp_remote_retrieve_body($response);
    $data = json_decode($response_body, true);

    if (isset($data['error'])) {
        lr_log_link_verification_csv([
            'link_id' => $link_id, 'link_text' => $link_text, 'original_url' => $original_url, 
            'query' => $api_url, 'status' => 'FAILURE',
            'notes' => "Google API Error: " . $data['error']['message'] . " Search Type: {$search_type}."
        ]);
        return new WP_Error('google_search_error', $data['error']['message']);
    }

    if (empty($data['items'])) {
        lr_log_link_verification_csv([
            'link_id' => $link_id, 'link_text' => $link_text, 'original_url' => $original_url, 
            'query' => $api_url, 'status' => 'FAILURE',
            'notes' => "No results found for search query. Search Type: {$search_type}."
        ]);
        return new WP_Error('no_results', 'No results found for the search query: ' . $human_readable_query);
    }

    // Log that the search itself was successful and returned results for evaluation.
    lr_log_link_verification_csv([
        'link_id' => $link_id, 'link_text' => $link_text, 'original_url' => $original_url, 
        'query' => $api_url, 'status' => 'SEARCH_SUCCESS', 
        'notes' => "{$search_type} search returned " . count($data['items']) . " results for evaluation."
    ]);

    // Return an array of results, each with the link, title, and snippet.
    $results = [];
    foreach ($data['items'] as $item) {
        $results[] = [
            'link'    => $item['link'] ?? '',
            'title'   => $item['title'] ?? '',
            'snippet' => $item['snippet'] ?? ''
        ];
    }

    return $results;
}

/**
 * Performs an intelligent liveness check on a URL.
 *
 * This function goes beyond a simple status code check. It verifies:
 * 1. The SSL certificate is valid (no WP_Error on request).
 * 2. The HTTP status code is 200 OK.
 * 3. The URL is not a Vertex AI search redirect.
 * 4. The HTML <title> of the page does not contain common error messages.
 *
 * @param string $url The URL to check.
 * @return bool True if the link is live and appears valid, false otherwise.
 */
/**
 * Performs an intelligent liveness check on a URL.
 *
 * This function goes beyond a simple status code check. It verifies:
 * 1. If the URL is a `vertexaisearch.com` link, it attempts to resolve the redirect first.
 * 2. The SSL certificate is valid (with a lenient check for server CA bundle issues).
 * 3. The HTTP status code is 200 OK.
 * 4. The HTML <title> of the page does not contain common error messages.
 *
 * @param string $url The URL to check.
 * @param int    $link_id The unique ID for this link verification attempt.
 * @param string $link_text The anchor text of the link.
 * @param string $original_url The original URL being checked.
 * @return bool True if the link is live and appears valid, false otherwise.
 */
function lr_intelligent_liveness_check($url, $link_id, $link_text, $original_url) {
    // 1. Check for Vertex AI search redirect URLs and resolve them.
    // These are generated by the AI's search tool and need to be followed to their final destination.
    $host = parse_url($url, PHP_URL_HOST);
    if ($host && strpos($host, 'vertexaisearch.cloud.google.com') !== false) {
        $resolved_url = lr_resolve_redirect_url($url);

        if ($resolved_url !== $url) {
            // Log the successful resolution of the redirect for traceability.
            lr_log_link_verification_csv([
                'link_id' => $link_id,
                'link_text' => $link_text,
                'original_url' => $original_url,
                'resulting_url' => $resolved_url,
                'status' => 'REDIRECT',
                'notes' => 'Vertex AI URL resolved to final destination.'
            ]);
            // Continue the liveness check with the new, resolved URL.
            $url = $resolved_url;
        } else {
            // If the URL is still a vertex URL, it means the redirect failed to resolve.
            return false;
        }
    }

    // 2. Perform the remote request
    $response = wp_remote_get($url, [
        'timeout' => 20,
        'sslverify' => true // This is the default, but explicit for clarity
    ]);

    // 3. Check for WordPress-level errors (e.g., SSL cert invalid, DNS issue)
    if (is_wp_error($response)) {
        $error_message = $response->get_error_message();
        error_log('Liveness check WP_Error for ' . $url . ': ' . $error_message);

        // Lenient check: If the error is SSL-related, we proceed anyway.
        // This is a pragmatic workaround for servers with outdated CA certificate bundles
        // that might fail to verify a certificate that modern browsers trust.
        // We still perform other checks (like status code and title) to ensure the page is valid.
        if (stripos($error_message, 'SSL') === false && stripos($error_message, 'certificate') === false) {
            return false; // It's a non-SSL error (e.g., DNS), so we fail it.
        }
    }

    // 4. Check for non-200 HTTP status codes
    $status_code = wp_remote_retrieve_response_code($response);
    if ($status_code !== 200) {
        return false;
    }

    // 5. Check the HTML <title> for common error messages ("soft 404s").
    $body = wp_remote_retrieve_body($response);
    $title = '';
    if (preg_match('/<title>(.*?)<\/title>/i', $body, $matches)) {
        $title = strtolower($matches[1]);
    }

    $error_strings = [
        'privacy error',
        'connection not private',
        'not found',
        '404',
        'page not found',
        'server error',
        'err_cert_common_name_invalid'
    ];

    foreach ($error_strings as $error_string) {
        if (strpos($title, $error_string) !== false) {
            return false;
        }
    }

    // If all checks pass, the link is considered live and valid
    return true;
}

/**
 * Follows redirects for a given URL and returns the final destination.
 *
 * Uses `wp_remote_head` to efficiently check for `Location` headers without
 * downloading the entire page body at each step.
 *
 * @param string $url The URL to resolve.
 * @return string The final URL after following redirects, or the original URL if no redirects are found or an error occurs.
 */
function lr_resolve_redirect_url($url) {
    $redirect_limit = 10; // Prevent infinite loops
    $current_url = $url;

    for ($i = 0; $i < $redirect_limit; $i++) {
        $response = wp_remote_head($current_url, ['timeout' => 15]);

        if (is_wp_error($response)) {
            // If there's an error (e.g., DNS failure), we can't follow, so return the last known URL.
            return $current_url;
        }

        $status_code = wp_remote_retrieve_response_code($response);

        // Check if the status code is a redirect (3xx).
        if ($status_code >= 300 && $status_code < 400) {
            $location = wp_remote_retrieve_header($response, 'location');
            if (!empty($location)) {
                $current_url = $location;
            } else {
                // A redirect status was given but no Location header; stop here.
                break;
            }
        } else {
            // Not a redirect, so we've reached the final destination.
            break;
        }
    }

    return $current_url;
}

/**
 * Uses an AI model to evaluate a list of search results and pick the most relevant one.
 *
 * This function acts as a "common sense" filter for the "Broad Search" results,
 * preventing contextually irrelevant links (like news articles about the stock market)
 * from being chosen.
 *
 * @param string $link_text The original anchor text of the link, used for context.
 * @param array  $search_results An array of search result objects from the Google Search API.
 * @return string|null The URL of the most relevant search result, or null if none are deemed relevant by the AI.
 */
function lr_evaluate_best_link_from_search($link_text, $search_results) {
    $options = get_option('lr_options');
    $api_key = $options['gemini_api_key'] ?? '';
    $model = $options['gemini_model'] ?? '';

    if (empty($api_key) || empty($model)) {
        return null; // Cannot evaluate without the API configured.
    }

    // Prepare a simplified list of results for a clean and concise AI prompt.
    $simplified_results = [];
    foreach ($search_results as $result) {
        $simplified_results[] = [
            'url' => $result['link'],
            'title' => $result['title'],
            'snippet' => $result['snippet']
        ];
    }

    $prompt = "You are a link verification assistant. Your task is to find the most contextually relevant link for a given piece of text from a list of Google Search results.\n\n"
            . "The original link text is: \"{$link_text}\"\n\n"
            . "Here are the search results:\n"
            . "```json\n"
            . json_encode($simplified_results, JSON_PRETTY_PRINT) . "\n"
            . "```\n\n"
            . "Instructions:\n"
            . "1. Analyze the title and snippet of each search result.\n"
            . "2. Compare them to the original link text to determine which is the best match.\n"
            . "3. If you find a result that is a clear and relevant match, return only its URL.\n"
            . "4. If NONE of the results seem relevant to the original link text, you MUST return the exact string 'NONE'.";

    $api_url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . $api_key;
    $body = ['contents' => [['parts' => [['text' => $prompt]]]]];

    $response = wp_remote_post($api_url, [
        'headers' => ['Content-Type' => 'application/json'],
        'body'    => json_encode($body),
        'timeout' => 45,
    ]);

    if (is_wp_error($response)) {
        return null;
    }

    $response_body = wp_remote_retrieve_body($response);
    $data = json_decode($response_body, true);
    $ai_response_text = trim($data['candidates'][0]['content']['parts'][0]['text'] ?? '');

    // If the AI returns nothing or explicitly states 'NONE', no relevant link was found.
    if (empty($ai_response_text) || strcasecmp($ai_response_text, 'NONE') === 0) {
        return null;
    }

    // The AI should return a clean URL. We'll validate it as a final safety check.
    if (filter_var($ai_response_text, FILTER_VALIDATE_URL)) {
        return $ai_response_text;
    }

    return null; // The response was not a valid URL.
}

