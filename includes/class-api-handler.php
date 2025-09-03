<?php
/**
 * KC Metro Live - OpenAI API Handler Module
 * Handles all OpenAI API communication for chat completions and image generation
 */

defined('ABSPATH') || exit;

class KC_ML_API_Handler {
    
    private $api_key;
    private $base_url = 'https://api.openai.com/v1';
    private $image_url = 'https://api.openai.com/v1/images/generations';
    private $chat_url = 'https://api.openai.com/v1/chat/completions';
    private $chat_model = 'gpt-4o'; // OpenAI's latest model
    private $image_model = 'dall-e-3'; // OpenAI's image model
    
    public function __construct() {
        $this->api_key = get_option('kc_ml_api_key', '');
    }
    
    /**
     * Test API key validity
     */
    public function test_api_key($api_key = null) {
        $key = $api_key ?: $this->api_key;
        
        if (empty($key)) {
            return array(
                'success' => false,
                'message' => 'API key is empty'
            );
        }
        
        $response = wp_remote_post($this->chat_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $key,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array(
                'model' => $this->chat_model,
                'messages' => array(
                    array(
                        'role' => 'user',
                        'content' => 'Test connection. Respond with: {"test": "success"}'
                    )
                ),
                'max_tokens' => 50,
                'temperature' => 0
            )),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => 'Connection failed: ' . $response->get_error_message()
            );
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($status_code !== 200) {
            $error_data = json_decode($body, true);
            $error_message = $error_data['error']['message'] ?? 'HTTP ' . $status_code;
            
            return array(
                'success' => false,
                'message' => $error_message
            );
        }
        
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return array(
                'success' => false,
                'message' => 'Invalid JSON response'
            );
        }
        
        if (!isset($data['choices'][0]['message']['content'])) {
            return array(
                'success' => false,
                'message' => 'Unexpected response format'
            );
        }
        
        return array(
            'success' => true,
            'message' => 'API key is valid'
        );
    }
    
    /**
     * Research with OpenAI - handles web search through function calling
     */
    public function research_with_citations($prompt, $operation_type = 'events', $timeout = 180) {
        if (empty($this->api_key)) {
            return array(
                'success' => false,
                'message' => 'API key not configured'
            );
        }
        
        $request_body = array(
            'model' => $this->chat_model,
            'messages' => array(
                array(
                    'role' => 'system',
                    'content' => $this->build_system_prompt($operation_type)
                ),
                array(
                    'role' => 'user',
                    'content' => $prompt
                )
            ),
            'temperature' => 0.3,
            'max_tokens' => 4000,
            'response_format' => array(
                'type' => 'json_object'
            )
        );
        
        $start_time = microtime(true);
        
        $response = wp_remote_post($this->chat_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($request_body),
            'timeout' => $timeout
        ));
        
        $end_time = microtime(true);
        $duration = $end_time - $start_time;
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => 'API request failed: ' . $response->get_error_message(),
                'duration' => $duration
            );
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($status_code !== 200) {
            $error_data = json_decode($body, true);
            $error_message = $error_data['error']['message'] ?? 'HTTP ' . $status_code;
            
            return array(
                'success' => false,
                'message' => $error_message,
                'duration' => $duration
            );
        }
        
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return array(
                'success' => false,
                'message' => 'Invalid JSON response from API',
                'duration' => $duration
            );
        }
        
        if (!isset($data['choices'][0]['message']['content'])) {
            return array(
                'success' => false,
                'message' => 'Unexpected response format',
                'duration' => $duration
            );
        }
        
        // Parse the JSON content
        $content = $data['choices'][0]['message']['content'];
        $parsed_content = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return array(
                'success' => false,
                'message' => 'AI response is not valid JSON: ' . $content,
                'duration' => $duration
            );
        }
        
        return array(
            'success' => true,
            'data' => $parsed_content,
            'usage' => $data['usage'] ?? array(),
            'sources_used' => 0, // OpenAI doesn't provide this directly
            'duration' => $duration
        );
    }
    
    /**
     * Generate image using OpenAI DALL-E
     */
    public function generate_image($prompt, $alt_text = '', $size = '1024x1024') {
        if (empty($this->api_key)) {
            error_log('KC ML: Image generation failed - API key not configured');
            return false;
        }
        
        $request_body = array(
            'model' => $this->image_model,
            'prompt' => $prompt,
            'n' => 1,
            'size' => $size,
            'response_format' => 'url',
            'quality' => 'standard'
        );
        
        $response = wp_remote_post($this->image_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($request_body),
            'timeout' => 60
        ));
        
        if (is_wp_error($response)) {
            error_log('KC ML: Image generation API error: ' . $response->get_error_message());
            return false;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($status_code !== 200) {
            $error_data = json_decode($body, true);
            $error_message = $error_data['error']['message'] ?? 'HTTP ' . $status_code;
            error_log('KC ML: Image generation failed: ' . $error_message);
            return false;
        }
        
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('KC ML: Image generation - invalid JSON response');
            return false;
        }
        
        if (!isset($data['data'][0]['url'])) {
            error_log('KC ML: Image generation - no URL in response');
            return false;
        }
        
        $image_url = $data['data'][0]['url'];
        
        // Download and save to WordPress media library
        $image_id = $this->save_image_to_media_library($image_url, $alt_text);
        
        return $image_id;
    }
    
    /**
     * Save image URL to WordPress media library
     */
    private function save_image_to_media_library($image_url, $alt_text = '') {
        // Download the image
        $response = wp_remote_get($image_url, array(
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            error_log('KC ML: Failed to download generated image: ' . $response->get_error_message());
            return false;
        }
        
        $image_data = wp_remote_retrieve_body($response);
        
        if (empty($image_data)) {
            error_log('KC ML: Downloaded image is empty');
            return false;
        }
        
        // Generate filename
        $filename = 'kc-ml-generated-' . uniqid() . '.jpg';
        
        // Use WordPress upload functions
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        
        // Create a temporary file
        $temp_file = wp_tempnam($filename);
        file_put_contents($temp_file, $image_data);
        
        // Prepare file array for wp_handle_sideload
        $file_array = array(
            'name' => $filename,
            'tmp_name' => $temp_file
        );
        
        // Handle the upload
        $uploaded = wp_handle_sideload($file_array, array('test_form' => false));
        
        if (isset($uploaded['error'])) {
            error_log('KC ML: Failed to upload image: ' . $uploaded['error']);
            return false;
        }
        
        // Create attachment
        $attachment = array(
            'post_mime_type' => $uploaded['type'],
            'post_title' => $alt_text ?: 'KC Metro Live Generated Image',
            'post_content' => '',
            'post_status' => 'inherit'
        );
        
        $attachment_id = wp_insert_attachment($attachment, $uploaded['file']);
        
        if (is_wp_error($attachment_id)) {
            error_log('KC ML: Failed to create attachment: ' . $attachment_id->get_error_message());
            return false;
        }
        
        // Generate metadata
        $attachment_data = wp_generate_attachment_metadata($attachment_id, $uploaded['file']);
        wp_update_attachment_metadata($attachment_id, $attachment_data);
        
        // Set alt text
        if (!empty($alt_text)) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_text);
        }
        
        return $attachment_id;
    }
    
    /**
     * Build system prompt based on operation type
     */
    private function build_system_prompt($operation_type) {
        $base_prompt = "You are the KC Metro Live AI Agent. You help find live music events in Kansas City, MO and KS area (within 50 miles of 39.0997° N, 94.5786° W). Focus on small venues like bars, restaurants, breweries, wineries, distilleries. Avoid big concert halls or Ticketmaster events.

RESEARCH REQUIREMENTS:
- Research comprehensively using web search capabilities
- Search venue websites, social media, community calendars
- Use https://kclive411.com/ and https://kansascitymusic.com/ as secondary sources
- Priority: venue/performer websites with date stamps, then their social media
- Look for events up to 90 days ahead only
- Focus on live music, karaoke, open mic nights, jam sessions

DATA ACCURACY:
- Verify information from multiple sources when possible
- Note conflicts in addresses, times, or details
- Use sentiment analysis on reviews from Google, Yelp for ratings
- Count positive vs negative words in reviews
- Generate appropriate confidence scores for data quality

RESPONSE FORMAT:
Return valid JSON only. No markdown, no text outside JSON structure.";

        switch ($operation_type) {
            case 'events':
                return $base_prompt . "

Find upcoming events and return in this exact JSON format:
{
  \"events\": [
    {
      \"event\": {
        \"event_name\": \"string\",
        \"event_type\": \"live music|karaoke|open mic|jam session|concert|festival|other\",
        \"start_date\": \"YYYY-MM-DD\",
        \"end_date\": \"YYYY-MM-DD\",
        \"start_time\": \"HH:MM\",
        \"end_time\": \"HH:MM\",
        \"description\": \"string\",
        \"requires_tickets\": true|false,
        \"ticket_link\": \"url\",
        \"cost\": 0,
        \"age_restriction\": \"all ages|18+|21+\",
        \"event_link\": \"url\",
        \"theme\": \"string\",
        \"stage\": \"string\",
        \"recurrence\": \"one-time|daily|weekly|monthly|yearly\",
        \"spans_multiple_days\": true|false,
        \"weather_dependent\": true|false,
        \"crowd_size_expected\": \"string\"
      },
      \"venue\": {
        \"name\": \"string\",
        \"type\": \"bar|restaurant|brewery|winery|distillery|other\",
        \"address\": \"string\",
        \"city\": \"string\",
        \"state\": \"MO|KS\",
        \"zip\": \"string\",
        \"description\": \"string\",
        \"website_link\": \"url\",
        \"facebook_page_link\": \"url\",
        \"phone_number\": \"string\",
        \"email\": \"string\",
        \"capacity\": 0,
        \"parking_info\": \"string\",
        \"accessibility\": true|false,
        \"outdoor_indoor\": \"outdoor|indoor|both\",
        \"pet_friendly\": true|false,
        \"rating_sentiment\": \"ugh|meh|good|great\",
        \"sentiment_words\": [\"positive\", \"negative\", \"words\"],
        \"map_link\": \"url\",
        \"alternative_name\": \"string\"
      },
      \"performers\": [
        {
          \"name\": \"string\",
          \"description\": \"string\",
          \"style_of_music\": [\"rock\", \"jazz\", \"blues\"],
          \"performer_type\": \"solo artist|duo|trio|band|group|orchestra|other\",
          \"local_touring\": \"local|touring|both\",
          \"website_link\": \"url\",
          \"social_media_links\": [\"url1\", \"url2\"],
          \"members\": \"string\",
          \"location\": \"string\",
          \"rating_sentiment\": \"ugh|meh|good|great\",
          \"sentiment_words\": [\"positive\", \"negative\", \"words\"],
          \"additional_notes\": \"string\"
        }
      ],
      \"notes\": [
        {
          \"note_text\": \"string\",
          \"source\": \"string\",
          \"date\": \"YYYY-MM-DD\",
          \"related_to\": \"venue|performer|event\"
        }
      ],
      \"research_citations\": [
        {
          \"source_url\": \"string\",
          \"source_type\": \"official_website|social_media|review_site|directory\",
          \"information_found\": \"string\",
          \"reliability_score\": \"high|medium|low\"
        }
      ]
    }
  ],
  \"research_summary\": {
    \"total_sources_searched\": 0,
    \"sources_with_events\": 0,
    \"confidence_level\": \"high|medium|low\",
    \"search_challenges\": \"string\"
  }
}";

            case 'venue_research':
                return $base_prompt . "

Research specific venue details and return in this JSON format:
{
  \"venue_data\": {
    \"name\": \"string\",
    \"type\": \"bar|restaurant|brewery|winery|distillery|other\",
    \"address\": \"string\",
    \"city\": \"string\",
    \"state\": \"MO|KS\",
    \"zip\": \"string\",
    \"description\": \"string\",
    \"website_link\": \"url\",
    \"facebook_page_link\": \"url\",
    \"phone_number\": \"string\",
    \"email\": \"string\",
    \"capacity\": 0,
    \"parking_info\": \"string\",
    \"accessibility\": true|false,
    \"outdoor_indoor\": \"outdoor|indoor|both\",
    \"pet_friendly\": true|false,
    \"rating_sentiment\": \"ugh|meh|good|great\",
    \"sentiment_words\": [\"positive\", \"negative\", \"words\"],
    \"map_link\": \"url\",
    \"alternative_name\": \"string\"
  },
  \"citations\": [
    {
      \"source_url\": \"string\",
      \"source_type\": \"official_website|social_media|review_site|directory\",
      \"information_found\": \"string\",
      \"reliability_score\": \"high|medium|low\"
    }
  ]
}";

            case 'performer_research':
                return $base_prompt . "

Research specific performer details and return in this JSON format:
{
  \"performer_data\": {
    \"name\": \"string\",
    \"description\": \"string\",
    \"style_of_music\": [\"rock\", \"jazz\", \"blues\"],
    \"performer_type\": \"solo artist|duo|trio|band|group|orchestra|other\",
    \"local_touring\": \"local|touring|both\",
    \"website_link\": \"url\",
    \"social_media_links\": [\"url1\", \"url2\"],
    \"members\": \"string\",
    \"location\": \"string\",
    \"rating_sentiment\": \"ugh|meh|good|great\",
    \"sentiment_words\": [\"positive\", \"negative\", \"words\"],
    \"additional_notes\": \"string\"
  },
  \"citations\": [
    {
      \"source_url\": \"string\",
      \"source_type\": \"official_website|social_media|review_site|directory\",
      \"information_found\": \"string\",
      \"reliability_score\": \"high|medium|low\"
    }
  ]
}";

            default:
                return $base_prompt;
        }
    }
    
    /**
     * Calculate API costs for OpenAI
     */
    public function calculate_cost($usage, $sources_used = 0) {
        $input_tokens = $usage['prompt_tokens'] ?? 0;
        $output_tokens = $usage['completion_tokens'] ?? 0;
        
        // GPT-4o pricing (as of 2024)
        $input_cost = ($input_tokens / 1000) * 0.0025;  // $2.50 per 1K input tokens
        $output_cost = ($output_tokens / 1000) * 0.01;  // $10.00 per 1K output tokens
        
        return $input_cost + $output_cost;
    }
    
    /**
     * Get usage statistics
     */
    public function get_usage_stats() {
        return array(
            'total_requests' => get_option('kc_ml_total_api_requests', 0),
            'total_tokens' => get_option('kc_ml_total_tokens', 0),
            'total_cost' => get_option('kc_ml_total_api_cost', 0),
            'images_generated' => get_option('kc_ml_total_images', 0)
        );
    }
    
    /**
     * Update usage statistics
     */
    public function update_usage_stats($tokens, $cost, $images = 0) {
        $total_requests = get_option('kc_ml_total_api_requests', 0);
        $total_tokens = get_option('kc_ml_total_tokens', 0);
        $total_cost = get_option('kc_ml_total_api_cost', 0);
        $total_images = get_option('kc_ml_total_images', 0);
        
        update_option('kc_ml_total_api_requests', $total_requests + 1);
        update_option('kc_ml_total_tokens', $total_tokens + $tokens);
        update_option('kc_ml_total_api_cost', $total_cost + $cost);
        update_option('kc_ml_total_images', $total_images + $images);
    }
}
?>