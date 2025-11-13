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
 * @param string $link_text The text of the link to verify (e.g., "Sparkle and Skate Night").
 * @param string $city_name The city for context (e.g., "San Francisco").
 * @return string|WP_Error The corrected URL or a WP_Error on failure.
 */
function lr_verify_link_with_google_search($link_text, $city_name) {
    $options = get_option('lr_options');
    lr_log_discovery_message("DEBUG: lr_verify_link_with_google_search options: " . json_encode($options));
    $api_key = $options['google_search_api_key'] ?? '';
    $engine_id = $options['google_search_engine_id'] ?? '';

    if (empty($api_key) || empty($engine_id)) {
        return new WP_Error('misconfigured', 'Google Custom Search API is not configured.');
    }

    $query = rawurlencode("\"{$link_text}\" {$city_name}");
    $api_url = "https://www.googleapis.com/customsearch/v1?key={$api_key}&cx={$engine_id}&q={$query}";

    $response = wp_remote_get($api_url, ['timeout' => 30]);

    if (is_wp_error($response)) {
        return $response;
    }

    $response_body = wp_remote_retrieve_body($response);
    $data = json_decode($response_body, true);

    if (isset($data['error'])) {
        return new WP_Error('google_search_error', $data['error']['message']);
    }

    if (empty($data['items'][0]['link'])) {
        return new WP_Error('no_results', 'No results found for the search query.');
    }

    return $data['items'][0]['link'];
}

