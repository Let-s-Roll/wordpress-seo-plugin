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

    if (empty($api_key)) {
        return new WP_Error('no_api_key', 'Gemini API key is not configured.');
    }
    if (empty($model)) {
        return new WP_Error('no_model_selected', 'An AI model has not been selected in the plugin settings.');
    }

    $prompt = lr_build_ai_prompt($content_data);
    $api_url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . $api_key;

    $response = wp_remote_post($api_url, [
        'headers' => ['Content-Type' => 'application/json'],
        'body'    => json_encode(['contents' => [['parts' => [['text' => $prompt]]]]]),
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
 * @return string The generated prompt.
 */
function lr_build_ai_prompt($content_data) {
    $city_name = $content_data['city_name'];

    $prompt = "You are an expert SEO content writer and a passionate, authentic roller skater for the Let's Roll community website. Your tone is enthusiastic and helpful.\n\n"
            . "Based on the following JSON data for {$city_name}, generate several text snippets for a webpage.\n\n"
            . "**Instructions:**\n"
            . "1.  **Generate a Title:** Create a catchy, SEO-friendly `post_title`. Incorporate the `publication_date` to give it a time-based context (e.g., \"September Update:\", \"What's New in Early October:\").\n"
            . "2.  **Generate an Introduction:** Write a `top_summary` (1-2 paragraphs) for the top of the page to introduce the updates.\n"
            . "3.  **Generate Section Content:** For each content type with data (spots, events, skaters, reviews), create an object with two keys:\n"
            . "    *   `heading`: A short, descriptive heading (2-4 words).\n"

            . "    *   `intro`: A slightly longer (1-2 sentence) introductory text for that section.\n"
            . "    *   If a section has no data, return null for its value.\n"
            . "4.  **Generate an Archive Summary:** Write a `post_summary` (1-2 sentences) for use on archive pages. This should also incorporate the `publication_date`.\n"
            . "5.  **Format the Output:** Return your response as a single, clean JSON object inside a ```json code block. The JSON object must have these exact keys: `post_title`, `top_summary`, `spots_section`, `events_section`, `skaters_section`, `reviews_section`, `sessions_section`, `post_summary`.\n\n"
            . "**JSON Data:**\n"
            . "```json\n"
            . json_encode($content_data, JSON_PRETTY_PRINT) . "\n"
            . "```";

    return $prompt;
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
