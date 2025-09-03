<?php
/**
 * KC Metro Live - Updated Batch Processor Module
 * Linear batch processing with Supabase analytics integration - OpenAI Version
 */

defined('ABSPATH') || exit;

class KC_ML_Batch_Processor {
    
    private $supabase_client;
    private $current_session_id;
    
    public function __construct($supabase_client) {
        $this->supabase_client = $supabase_client;
    }
    
    /**
     * Run the complete agent process
     */
    public function run_full_agent($limit = 10) {
        $start_time = microtime(true);
        
        // Start research session in Supabase
        $this->current_session_id = $this->supabase_client->start_research_session('batch', $limit);
        
        try {
            // Step 1: Process events batch
            $result = $this->process_events_batch($limit);
            
            if (!$result['success']) {
                throw new Exception('Events batch failed: ' . $result['message']);
            }
            
            $end_time = microtime(true);
            $duration = round($end_time - $start_time, 2);
            
            // Complete session in Supabase
            if ($this->current_session_id) {
                $this->supabase_client->complete_research_session(
                    $this->current_session_id,
                    true,
                    'Batch completed successfully'
                );
            }
            
            // Track daily budget
            $this->track_daily_budget($result);
            
            return array(
                'success' => true,
                'message' => $result['message'] . ' (Duration: ' . $duration . 's)',
                'duration' => $duration,
                'session_id' => $this->current_session_id,
                'stats' => $result
            );
            
        } catch (Exception $e) {
            $end_time = microtime(true);
            $duration = round($end_time - $start_time, 2);
            
            // Mark session as failed
            if ($this->current_session_id) {
                $this->supabase_client->complete_research_session(
                    $this->current_session_id,
                    false,
                    $e->getMessage()
                );
            }
            
            return array(
                'success' => false,
                'message' => $e->getMessage(),
                'duration' => $duration,
                'session_id' => $this->current_session_id
            );
        }
    }
    
    /**
     * Process events batch - First stage
     */
    public function process_events_batch($limit = 10) {
        $start_time = microtime(true);
        $api_key = get_option('kc_ml_api_key', '');
        
        if (empty($api_key)) {
            return array('success' => false, 'message' => 'No OpenAI API key configured');
        }
        
        // Build comprehensive events research prompt
        $prompt = $this->build_events_prompt($limit);
        
        // Log operation start
        $operation_id = null;
        if ($this->current_session_id) {
            $operation_id = $this->supabase_client->log_research_operation(
                $this->current_session_id,
                'events_research',
                'batch',
                0,
                'Events Batch',
                0, 0, 0, 0, 0, 0, false
            );
        }
        
        // Make API call with extended timeout
        $response = $this->call_openai_api($prompt, $api_key, 300);
        
        $duration = microtime(true) - $start_time;
        
        if (!$response['success']) {
            if ($operation_id) {
                $this->update_operation($operation_id, $duration, 0, false, $response['message']);
            }
            return array('success' => false, 'message' => 'API call failed: ' . $response['message']);
        }
        
        // Calculate API cost
        $api_cost = $this->calculate_api_cost($response['usage'] ?? array());
        
        $events_data = $response['data']['events'] ?? array();
        
        if (empty($events_data)) {
            if ($operation_id) {
                $this->update_operation($operation_id, $duration, $api_cost, false, 'No events found');
            }
            return array('success' => false, 'message' => 'No events found in API response');
        }
        
        // Process each event
        $processed = 0;
        $skipped = 0;
        $temp_data = array(
            'venues' => array(),
            'performers' => array(),
            'notes' => array()
        );
        
        foreach ($events_data as $event_entry) {
            try {
                $event_data = $event_entry['event'] ?? array();
                $venue_data = $event_entry['venue'] ?? array();
                $performers_data = $event_entry['performers'] ?? array();
                $notes = $event_entry['notes'] ?? array();
                
                // Check if event already exists
                if ($this->event_exists($event_data)) {
                    $skipped++;
                    continue;
                }
                
                // Create event post
                $event_id = $this->create_event_post($event_data);
                
                if (!$event_id) {
                    continue;
                }
                
                // Store event metadata in Supabase
                if ($this->current_session_id) {
                    $this->supabase_client->store_event_meta($event_id, $event_data);
                }
                
                // Store temporary data for next batches
                $temp_data['venues'][] = array(
                    'data' => $venue_data,
                    'event_id' => $event_id
                );
                
                foreach ($performers_data as $performer) {
                    $temp_data['performers'][] = array(
                        'data' => $performer,
                        'event_id' => $event_id
                    );
                }
                
                // Store notes
                foreach ($notes as $note) {
                    $note['related_id'] = $event_id;
                    $temp_data['notes'][] = $note;
                }
                
                $processed++;
                
            } catch (Exception $e) {
                error_log('Failed to process individual event: ' . $e->getMessage());
                continue;
            }
        }
        
        // Store temp data for next batches
        update_option('kc_ml_temp_batch_data', $temp_data);
        
        // Log success in Supabase
        if ($operation_id) {
            $this->update_operation($operation_id, $duration, $api_cost, true, '');
        }
        
        $message = sprintf('Processed %d events, skipped %d duplicates', $processed, $skipped);
        
        // Trigger venues batch
        do_action('kc_events_batch_complete');
        
        return array(
            'success' => true,
            'message' => $message,
            'processed' => $processed,
            'skipped' => $skipped,
            'cost' => $api_cost
        );
    }
    
    /**
     * Process venues batch - Second stage
     */
    public function process_venues_batch() {
        $start_time = microtime(true);
        $temp_data = get_option('kc_ml_temp_batch_data', array());
        $venues_data = $temp_data['venues'] ?? array();
        
        if (empty($venues_data)) {
            return array('success' => false, 'message' => 'No venue data found from events batch');
        }
        
        $created = 0;
        $updated = 0;
        
        foreach ($venues_data as $venue_info) {
            try {
                $venue_data = $venue_info['data'];
                $event_id = $venue_info['event_id'];
                
                // Create venue post using JetEngine CCT
                $venue_id = $this->create_venue_post($venue_data);
                
                if ($venue_id) {
                    $created++;
                    
                    // Store metadata in Supabase
                    if ($this->current_session_id) {
                        $this->supabase_client->store_venue_meta($venue_id, $venue_data);
                    }
                    
                    // Link event to venue
                    update_post_meta($event_id, 'venue_id', $venue_id);
                    update_post_meta($event_id, 'venue_name', $venue_data['name'] ?? '');
                }
                
            } catch (Exception $e) {
                error_log('Failed to process venue: ' . $e->getMessage());
                continue;
            }
        }
        
        $message = sprintf('Created %d venues, updated %d venues', $created, $updated);
        
        // Trigger performers batch
        do_action('kc_venues_batch_complete');
        
        return array(
            'success' => true,
            'message' => $message,
            'created' => $created,
            'updated' => $updated
        );
    }
    
    /**
     * Process performers batch - Third stage
     */
    public function process_performers_batch() {
        $start_time = microtime(true);
        $temp_data = get_option('kc_ml_temp_batch_data', array());
        $performers_data = $temp_data['performers'] ?? array();
        $notes_data = $temp_data['notes'] ?? array();
        
        if (empty($performers_data)) {
            return array('success' => false, 'message' => 'No performer data found from events batch');
        }
        
        $created = 0;
        $updated = 0;
        
        // Group performers by event
        $events_performers = array();
        foreach ($performers_data as $performer_info) {
            $event_id = $performer_info['event_id'];
            $events_performers[$event_id][] = $performer_info;
        }
        
        foreach ($events_performers as $event_id => $event_performers) {
            $performer_ids = array();
            
            foreach ($event_performers as $performer_info) {
                try {
                    $performer_data = $performer_info['data'];
                    
                    // Create performer post using JetEngine CCT
                    $performer_id = $this->create_performer_post($performer_data);
                    
                    if ($performer_id) {
                        $created++;
                        $performer_ids[] = $performer_id;
                        
                        // Store metadata in Supabase
                        if ($this->current_session_id) {
                            $this->supabase_client->store_performer_meta($performer_id, $performer_data);
                        }
                    }
                    
                } catch (Exception $e) {
                    error_log('Failed to process performer: ' . $e->getMessage());
                    continue;
                }
            }
            
            // Link event to performers
            if (!empty($performer_ids)) {
                update_post_meta($event_id, 'performer_ids', $performer_ids);
            }
        }
        
        // Process notes
        $this->process_batch_notes($notes_data);
        
        // Clean up temp data
        delete_option('kc_ml_temp_batch_data');
        
        $message = sprintf('Created %d performers, updated %d performers', $created, $updated);
        
        // Trigger completion
        do_action('kc_performers_batch_complete');
        
        return array(
            'success' => true,
            'message' => $message,
            'created' => $created,
            'updated' => $updated
        );
    }
    
    /**
     * Build events research prompt
     */
    private function build_events_prompt($limit = 10) {
        $current_date = date('Y-m-d');
        $end_date = date('Y-m-d', strtotime('+90 days'));
        
        return "You are KC Live Agent AI. Today is {$current_date}. 

CRITICAL INSTRUCTIONS:
- Find {$limit} REAL upcoming live music events in Kansas City, MO or KS area (within 50 miles of 39.0997° N, 94.5786° W)
- Events must be between {$current_date} and {$end_date}
- Focus on small venues: bars, restaurants, breweries, wineries, distilleries 
- NO big concert halls or Ticketmaster events
- Use web research to find current information from venue websites, performer websites, social media (Facebook, Instagram, Twitter)
- SECONDARY: kclive411.com, kansascitymusic.com
- Search comprehensively for accurate, current event information

For EACH event found, provide complete data in this EXACT JSON structure:

{
  \"events\": [
    {
      \"event\": {
        \"event_name\": \"Event Name\",
        \"event_type\": \"live music\",
        \"start_date\": \"2025-MM-DD\",
        \"end_date\": \"2025-MM-DD\",
        \"start_time\": \"HH:MM\",
        \"end_time\": \"HH:MM\",
        \"description\": \"Detailed description\",
        \"requires_tickets\": true/false,
        \"ticket_link\": \"URL or empty\",
        \"cost\": 0,
        \"age_restriction\": \"all ages/18+/21+\",
        \"theme\": \"theme if any\",
        \"event_link\": \"source URL\"
      },
      \"venue\": {
        \"name\": \"Venue Name\",
        \"type\": \"bar/restaurant/brewery/winery/distillery\",
        \"website_link\": \"URL\",
        \"facebook_page_link\": \"URL\",
        \"address\": \"Street Address\",
        \"city\": \"City\",
        \"state\": \"MO/KS\",
        \"zip\": \"Zip Code\",
        \"description\": \"Venue description\",
        \"phone_number\": \"Phone\",
        \"email\": \"Public email only\",
        \"capacity\": 100,
        \"parking_info\": \"Parking details\",
        \"accessibility\": true/false,
        \"outdoor_indoor\": \"indoor/outdoor/both\",
        \"pet_friendly\": true/false,
        \"rating_sentiment\": \"ugh/meh/good/great\",
        \"sentiment_words\": [\"positive\", \"words\", \"found\"],
        \"map_link\": \"Google Maps URL\"
      },
      \"performers\": [
        {
          \"name\": \"Performer Name\",
          \"description\": \"Who they are and what they play\",
          \"style_of_music\": [\"rock\", \"blues\"],
          \"rating_sentiment\": \"ugh/meh/good/great\",
          \"performer_type\": \"solo artist/duo/trio/band/group\",
          \"local_touring\": \"local/touring/both\",
          \"website_link\": \"URL\",
          \"social_media_links\": [\"URL1\", \"URL2\"],
          \"members\": \"Public member names only\",
          \"sentiment_words\": [\"words\", \"found\"],
          \"location\": \"Based in City, State\"
        }
      ],
      \"notes\": [
        {
          \"note_text\": \"Any conflicts or updates found\",
          \"source\": \"Source URL\",
          \"date\": \"{$current_date}\",
          \"related_to\": \"venue/performer/event\"
        }
      ]
    }
  ]
}

SENTIMENT ANALYSIS: For venues and performers, analyze reviews from Google, Yelp, social media. Count positive words (amazing, fun, great, love, awesome, excellent, fantastic, wonderful, best) vs negative words (bad, dirty, boring, terrible, awful, poor, disappointing, horrible, worst, rude). 

RATING SCALE: ugh (<2 stars), meh (2-3 stars), good (4 stars), great (5 stars). Adjust based on positive/negative word ratio.

CONFLICTS: If you find different information from multiple sources, add to notes array.

RESPOND WITH VALID JSON ONLY. No explanation text outside the JSON.";
    }
    
    /**
     * Call OpenAI API
     */
    private function call_openai_api($prompt, $api_key, $timeout = 120) {
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'timeout' => $timeout,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array(
                'model' => 'gpt-4o', // OpenAI's latest model
                'messages' => array(
                    array('role' => 'user', 'content' => $prompt)
                ),
                'max_tokens' => 4000,
                'temperature' => 0.1,
                'response_format' => array('type' => 'json_object')
            ))
        ));
        
        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $body = wp_remote_retrieve_body($response);
            $error_data = json_decode($body, true);
            $error_message = $error_data['error']['message'] ?? 'HTTP ' . $code;
            return array('success' => false, 'message' => $error_message);
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (empty($data['choices'][0]['message']['content'])) {
            return array('success' => false, 'message' => 'No content in API response');
        }
        
        $content = $data['choices'][0]['message']['content'];
        
        // Try to parse JSON response
        $json_data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return array('success' => false, 'message' => 'Invalid JSON response from API: ' . $content);
        }
        
        return array(
            'success' => true,
            'data' => $json_data,
            'usage' => $data['usage'] ?? array()
        );
    }
    
    /**
     * Check if event already exists
     */
    private function event_exists($event_data) {
        if (empty($event_data['event_name']) || empty($event_data['start_date'])) {
            return false;
        }
        
        $query = new WP_Query(array(
            'post_type' => 'events', // Updated to match your JetEngine CCT
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key' => 'event_name',
                    'value' => $event_data['event_name'],
                    'compare' => '='
                ),
                array(
                    'key' => 'start_date',
                    'value' => $event_data['start_date'],
                    'compare' => '='
                )
            ),
            'posts_per_page' => 1
        ));
        
        return $query->have_posts();
    }
    
    /**
     * Create event post
     */
    private function create_event_post($event_data) {
        $post_data = array(
            'post_title' => $event_data['event_name'] ?? 'Untitled Event',
            'post_content' => $event_data['description'] ?? '',
            'post_status' => 'publish',
            'post_type' => 'events' // Updated to match your JetEngine CCT
        );
        
        $post_id = wp_insert_post($post_data);
        
        if (!$post_id || is_wp_error($post_id)) {
            return false;
        }
        
        // Add all custom fields as meta
        foreach ($event_data as $key => $value) {
            if (!empty($value) && $key !== 'event_name' && $key !== 'description') {
                update_post_meta($post_id, $key, $value);
            }
        }
        
        // Set verification status
        update_post_meta($post_id, 'verified', array('unverified'));
        
        return $post_id;
    }
    
    /**
     * Create venue post using JetEngine CCT
     */
    private function create_venue_post($venue_data) {
        $post_data = array(
            'post_title' => $venue_data['name'] ?? 'Untitled Venue',
            'post_content' => $venue_data['description'] ?? '',
            'post_status' => 'publish',
            'post_type' => 'venues' // JetEngine CCT
        );
        
        $post_id = wp_insert_post($post_data);
        
        if (!$post_id || is_wp_error($post_id)) {
            return false;
        }
        
        // Add all custom fields as meta
        foreach ($venue_data as $key => $value) {
            if (!empty($value) && $key !== 'name' && $key !== 'description') {
                update_post_meta($post_id, $key, $value);
            }
        }
        
        return $post_id;
    }
    
    /**
     * Create performer post using JetEngine CCT
     */
    private function create_performer_post($performer_data) {
        $post_data = array(
            'post_title' => $performer_data['name'] ?? 'Untitled Performer',
            'post_content' => $performer_data['description'] ?? '',
            'post_status' => 'publish',
            'post_type' => 'performers' // JetEngine CCT
        );
        
        $post_id = wp_insert_post($post_data);
        
        if (!$post_id || is_wp_error($post_id)) {
            return false;
        }
        
        // Add all custom fields as meta
        foreach ($performer_data as $key => $value) {
            if (!empty($value) && $key !== 'name' && $key !== 'description') {
                update_post_meta($post_id, $key, $value);
            }
        }
        
        return $post_id;
    }
    
    /**
     * Process batch notes
     */
    private function process_batch_notes($notes_data) {
        foreach ($notes_data as $note) {
            try {
                // Create note post using JetEngine CCT
                $note_post = array(
                    'post_title' => 'Note: ' . substr($note['note_text'], 0, 50),
                    'post_content' => $note['note_text'],
                    'post_status' => 'publish',
                    'post_type' => 'notes' // JetEngine CCT
                );
                
                $note_id = wp_insert_post($note_post);
                
                if ($note_id) {
                    update_post_meta($note_id, 'source', $note['source']);
                    update_post_meta($note_id, 'date', $note['date']);
                    update_post_meta($note_id, 'related_to', $note['related_to']);
                    update_post_meta($note_id, 'related_id', $note['related_id']);
                    
                    // Store in Supabase
                    if ($this->current_session_id) {
                        $this->supabase_client->store_note_meta(
                            $note_id,
                            $note['related_to'],
                            $note['related_id'],
                            $note
                        );
                    }
                }
                
            } catch (Exception $e) {
                error_log('Failed to process note: ' . $e->getMessage());
                continue;
            }
        }
    }
    
    /**
     * Update research operation in Supabase
     */
    private function update_operation($operation_id, $duration, $cost, $success, $error = '') {
        if (!$operation_id) return;
        
        $this->supabase_client->update('research_operations',
            array('id' => $operation_id),
            array(
                'duration_seconds' => round($duration, 2),
                'cost_usd' => $cost,
                'success' => $success,
                'error_message' => $error
            )
        );
    }
    
    /**
     * Calculate API cost from usage data (OpenAI pricing)
     */
    private function calculate_api_cost($usage) {
        $input_tokens = $usage['prompt_tokens'] ?? 0;
        $output_tokens = $usage['completion_tokens'] ?? 0;
        
        // GPT-4o pricing
        $input_cost = ($input_tokens / 1000) * 0.0025;  // $2.50 per 1K input tokens
        $output_cost = ($output_tokens / 1000) * 0.01;  // $10.00 per 1K output tokens
        
        return $input_cost + $output_cost;
    }
    
    /**
     * Track daily budget in Supabase
     */
    private function track_daily_budget($batch_stats) {
        $this->supabase_client->track_daily_budget(
            $batch_stats['cost'] ?? 0,
            1, // API calls (this batch)
            0, // tokens (calculated elsewhere)
            0, // images generated
            $batch_stats['processed'] ?? 0, // events
            $batch_stats['created'] ?? 0, // venues (from venues batch)
            0 // performers (from performers batch)
        );
    }
}

?>