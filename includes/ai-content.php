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
 * Performs an intelligent quality check on a URL using an "AI-First" model.
 *
 * This function first checks for unambiguous technical failures (cURL errors, 4xx/5xx status codes).
 * If the URL is technically live, it then defers to an AI model (`lr_adjudicate_content_relevance`)
 * to make a final, context-aware judgment on the content's quality and relevance. A link is only
 * considered to have failed if the AI classifies it as 'Irrelevant'.
 *
 * @param string $url The URL to check.
 * @param int    $link_id The unique ID for this link verification attempt.
 * @param string $link_text The anchor text of the link.
 * @param string $original_url The original URL being checked.
 * @param string $city_name The city name for contextual relevance.
 * @return string|false The final, valid URL if it passes all checks, or `false` otherwise.
 */
function lr_intelligent_quality_check($url, $link_id, $link_text, $original_url, $city_name) {
    // --- NEW: URL Validation Guard ---
    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        lr_log_link_verification_csv([
            'link_id' => $link_id, 'link_text' => $link_text, 'original_url' => $original_url,
            'status' => 'FAILURE', 'notes' => 'Quality Check Failed: Invalid or malformed URL provided: ' . $url
        ]);
        return false;
    }

    $final_url = $url; // Start with the URL we were given.

    // 1. Check for Vertex AI search redirect URLs and resolve them.
    $host = parse_url($final_url, PHP_URL_HOST);
    if ($host && strpos($host, 'vertexaisearch.cloud.google.com') !== false) {
        $resolved_url = lr_resolve_redirect_url($final_url);

        if ($resolved_url !== $final_url) {
            // Log the successful resolution of the redirect for traceability.
            lr_log_link_verification_csv([
                'link_id' => $link_id, 'link_text' => $link_text, 'original_url' => $original_url,
                'resulting_url' => $resolved_url, 'status' => 'REDIRECT',
                'notes' => 'Vertex AI URL resolved to final destination.'
            ]);
            // The resolved URL is now the one we will check for quality.
            $final_url = $resolved_url;
        } else {
            // If the URL is still a vertex URL, it means the redirect failed to resolve.
            return false;
        }
    }

    // 2. Perform the remote request on the potentially updated URL.
    $response = wp_remote_get($final_url, [
        'timeout' => 20,
        'sslverify' => true,
        'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
    ]);

    // 3. Check for WordPress-level errors (e.g., SSL cert invalid, DNS issue)
    if (is_wp_error($response)) {
        $error_message = $response->get_error_message();
        lr_log_link_verification_csv([
            'link_id' => $link_id, 'link_text' => $link_text, 'original_url' => $original_url,
            'status' => 'FAILURE', 'notes' => 'Quality Check Failed: WP_Error - ' . $error_message
        ]);
        return false;
    }

    // 4. Check for non-200 HTTP status codes
    $status_code = wp_remote_retrieve_response_code($response);
    if ($status_code !== 200) {
        lr_log_link_verification_csv([
            'link_id' => $link_id, 'link_text' => $link_text, 'original_url' => $original_url,
            'status' => 'FAILURE', 'notes' => 'Quality Check Failed: HTTP Status Code ' . $status_code
        ]);
        return false;
    }

    // 5. Check the HTML <title> for common error messages.
    $body = wp_remote_retrieve_body($response);
    
    // NOTE: The keyword-based checks for title and body have been removed.
    // The AI adjudicator is now the single source of truth for content relevance and quality,
    // as it provides superior contextual understanding.

    // Step 6: FINAL CHECK - Use AI to adjudicate the content's relevance.
    // We now presume innocence. Only if the AI classifies as 'Irrelevant' do we fail.
    $ai_classification = lr_adjudicate_content_relevance($body, $link_text, $city_name);

    if ($ai_classification === 'Irrelevant') {
        lr_log_link_verification_csv([
            'link_id' => $link_id, 'link_text' => $link_text, 'original_url' => $original_url,
            'status' => 'FAILURE', 'notes' => "Quality Check Failed: AI classified content as '{$ai_classification}'."
        ]);
        return false;
    }

    // If all checks pass (including AI not classifying as irrelevant), return the URL.
    return $final_url;
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
function lr_evaluate_best_link_from_search($link_text, $search_results, $city_name, $context_sentence = '') {
    $options = get_option('lr_options');
    $api_key = $options['gemini_api_key'] ?? '';
    $model = $options['gemini_model'] ?? '';

    if (empty($api_key) || empty($model)) {
        return null; // Cannot evaluate without the API configured.
    }

    $simplified_results = [];
    foreach ($search_results as $result) {
        $simplified_results[] = [
            'url' => $result['link'],
            'title' => $result['title'],
            'snippet' => $result['snippet']
        ];
    }

    $prompt = "You are a link verification assistant specializing in local roller skating events. Your task is to find the best replacement link for a broken URL within a sentence, based on context.\n\n"
            . "**The original sentence is:**\n\"{$context_sentence}\"\n\n"
            . "**The broken link text was:** \"{$link_text}\"\n"
            . "**The target city is:** \"{$city_name}\"\n\n"
            . "Here are the potential replacement links from a search:\n"
            . "```json\n"
            . json_encode($simplified_results, JSON_PRETTY_PRINT) . "\n"
            . "```\n\n"
            . "**Instructions:**\n"
            . "1.  **Analyze the original sentence** to understand the purpose and context of the broken link.\n"
            . "2.  **Evaluate each search result** to find the best replacement that fits the context, is for roller skating, and is geographically accurate for the target city.\n"
            . "3.  **Prioritize Link Quality:** Official event pages, venue websites, or news articles are strongly preferred. Social media links are a last resort.\n"
            . "4.  **Strictly Enforce Relevance:** You MUST discard any result that is for the wrong activity (e.g., biking) or the wrong city.\n"
            . "5.  **Final Output:** If you find a clear and relevant match, return only its URL. If NONE of the results are a good fit for the context of the sentence, you MUST return the exact string 'NONE'.";

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

    if (empty($ai_response_text) || strcasecmp($ai_response_text, 'NONE') === 0) {
        return null;
    }

    if (filter_var($ai_response_text, FILTER_VALIDATE_URL)) {
        return $ai_response_text;
    }

    return null;
}

/**
 * Uses an AI model to adjudicate the quality and relevance of a URL's content.
 *
 * This function analyzes the page's title, H1, and body content to classify its relevance
 * in relation to the original link text and city. It is the "common sense" check in the
 * verification process.
 *
 * @param string $content The HTML content of the page to analyze.
 * @param string $link_text The original anchor text of the link for context.
 * @param string $city_name The target city for geographical context.
 * @return string The AI's classification ('High Relevance', 'Low Relevance', 'Irrelevant'), or 'High Relevance' on failsafe.
 */
function lr_adjudicate_content_relevance($content, $link_text, $city_name) {
    $options = get_option('lr_options');
    $api_key = $options['gemini_api_key'] ?? '';
    $model = $options['gemini_model'] ?? '';

    if (empty($api_key) || empty($model)) {
        return 'High Relevance'; // Failsafe: If AI is not configured, presume innocence.
    }

    // Extract page title
    $page_title = '';
    if (preg_match('/<title>(.*?)<\/title>/i', $content, $matches)) {
        $page_title = trim($matches[1]);
    }

    // Extract first H1 heading
    $h1_content = '';
    if (preg_match('/<h1[^>]*>(.*?)<\/h1>/i', $content, $matches)) {
        $h1_content = trim($matches[1]);
    }

    // To save tokens and improve performance, we'll only send the most relevant part of the HTML.
    // This strips scripts, styles, and gets the core text content.
    $stripped_content = wp_strip_all_tags($content);
    $content_for_ai = substr($stripped_content, 0, 8000); // Limit to a reasonable length

    $prompt = "You are a link relevance analyst. Your task is to determine if the content of a webpage is a direct match for the given link text.\n\n"
            . "**Link Text:** \"{$link_text}\"\n"
            . "**Target City:** \"{$city_name}\"\n\n"
            . "**Page Content Analysis:**\n"
            . "*   **Title:** \"{$page_title}\"\n"
            . "*   **Main Heading (H1):** \"{$h1_content}\"\n"
            . "*   **Body Snippet:** \"" . substr($content_for_ai, 0, 500) . "...\"\n\n"
            . "**Instructions:** Based on the Title, Main Heading, and Body Snippet, classify the page's relevance to the Link Text. Consider the overall context of the website and the city. Choose one of the following three classifications:\n\n"
            . "1.  **High Relevance:** The title/heading is a direct or very close match for the link text. The page is clearly about this specific topic.\n"
            . "2.  **Low Relevance:** The page is on a related topic but is not a specific match. (e.g., the link text is for a specific event, but the page is a generic calendar or a homepage for a venue, or a general blog post). It still provides some value.\n"
            . "3.  **Irrelevant:** The title/heading has no connection to the link text, or the page is a parked domain, an error page, or a generic directory with no real information.\n\n"
            . "**Return only the classification: `High Relevance`, `Low Relevance`, or `Irrelevant`.**";

    $api_url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . $api_key;
    $body = ['contents' => [['parts' => [['text' => $prompt]]]]];

    $response = wp_remote_post($api_url, [
        'headers' => ['Content-Type' => 'application/json'],
        'body'    => json_encode($body),
        'timeout' => 45,
    ]);

    if (is_wp_error($response)) {
        return 'High Relevance'; // Failsafe: If the API call fails, presume innocence.
    }

    $response_body = wp_remote_retrieve_body($response);
    $data = json_decode($response_body, true);
    $ai_response_text = trim($data['candidates'][0]['content']['parts'][0]['text'] ?? 'ERROR');

    // Log the AI's decision for auditing purposes.
    lr_log_link_verification_csv([
        'link_text' => $link_text,
        'original_url' => 'N/A (Adjudication)',
        'status' => 'AI_ADJUDICATION',
        'notes' => "AI classified relevance for '{$link_text}' in {$city_name}: {$ai_response_text}"
    ]);

    // Return the AI's classification.
    // If the AI returns something unexpected, default to 'High Relevance' (presume innocence).
    if (in_array($ai_response_text, ['High Relevance', 'Low Relevance', 'Irrelevant'])) {
        return $ai_response_text;
    }

    return 'High Relevance';
}

