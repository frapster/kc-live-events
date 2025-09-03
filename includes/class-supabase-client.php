<?php
/**
 * KC Metro Live - Supabase Client
 * Handles all Supabase database operations
 */

defined('ABSPATH') || exit;

class KC_ML_Supabase_Client {
    
    private $project_url;
    private $anon_key;
    private $service_key;
    
    public function __construct() {
        $this->project_url = get_option('kc_ml_supabase_url', '');
        $this->anon_key = get_option('kc_ml_supabase_anon_key', '');
        $this->service_key = get_option('kc_ml_supabase_service_key', '');
    }
    
    /**
     * Test Supabase connection
     */
    public function test_connection() {
        if (empty($this->project_url) || empty($this->anon_key) || empty($this->service_key)) {
            return false;
        }
        
        // Test connection by trying to read from research_sessions table
        $response = wp_remote_get($this->project_url . '/rest/v1/research_sessions?select=id&limit=1', array(
            'timeout' => 30,
            'headers' => array(
                'apikey' => $this->anon_key,
                'Authorization' => 'Bearer ' . $this->service_key,
                'Content-Type' => 'application/json'
            )
        ));
        
        if (is_wp_error($response)) {
            error_log('Supabase connection error: ' . $response->get_error_message());
            return false;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        return $code === 200;
    }
    
    /**
     * Insert data into Supabase table
     */
    public function insert($table, $data) {
        if (!$this->is_configured()) {
            return false;
        }
        
        $response = wp_remote_post($this->project_url . '/rest/v1/' . $table, array(
            'timeout' => 30,
            'headers' => array(
                'apikey' => $this->anon_key,
                'Authorization' => 'Bearer ' . $this->service_key,
                'Content-Type' => 'application/json',
                'Prefer' => 'return=representation'
            ),
            'body' => json_encode($data)
        ));
        
        if (is_wp_error($response)) {
            error_log('Supabase insert error: ' . $response->get_error_message());
            return false;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 201) {
            error_log('Supabase insert failed: HTTP ' . $code . ' - ' . wp_remote_retrieve_body($response));
            return false;
        }
        
        $result = json_decode(wp_remote_retrieve_body($response), true);
        return $result;
    }
    
    /**
     * Update data in Supabase table
     */
    public function update($table, $conditions, $data) {
        if (!$this->is_configured()) {
            return false;
        }
        
        // Build query string for conditions
        $query_params = array();
        foreach ($conditions as $key => $value) {
            $query_params[] = $key . '=eq.' . urlencode($value);
        }
        $query_string = implode('&', $query_params);
        
        $response = wp_remote_request($this->project_url . '/rest/v1/' . $table . '?' . $query_string, array(
            'method' => 'PATCH',
            'timeout' => 30,
            'headers' => array(
                'apikey' => $this->anon_key,
                'Authorization' => 'Bearer ' . $this->service_key,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($data)
        ));
        
        if (is_wp_error($response)) {
            error_log('Supabase update error: ' . $response->get_error_message());
            return false;
        }
        
        return wp_remote_retrieve_response_code($response) === 204;
    }
    
    /**
     * Delete data from Supabase table
     */
    public function delete($table, $conditions) {
        if (!$this->is_configured()) {
            return false;
        }
        
        // Build query string for conditions
        $query_params = array();
        foreach ($conditions as $key => $value) {
            $query_params[] = $key . '=eq.' . urlencode($value);
        }
        $query_string = implode('&', $query_params);
        
        $response = wp_remote_request($this->project_url . '/rest/v1/' . $table . '?' . $query_string, array(
            'method' => 'DELETE',
            'timeout' => 30,
            'headers' => array(
                'apikey' => $this->anon_key,
                'Authorization' => 'Bearer ' . $this->service_key,
                'Content-Type' => 'application/json'
            )
        ));
        
        if (is_wp_error($response)) {
            error_log('Supabase delete error: ' . $response->get_error_message());
            return false;
        }
        
        return wp_remote_retrieve_response_code($response) === 204;
    }
    
    /**
     * Select data from Supabase table
     */
    public function select($table, $conditions = array(), $columns = '*', $limit = null) {
        if (!$this->is_configured()) {
            return false;
        }
        
        // Build query string
        $query_params = array('select=' . $columns);
        
        foreach ($conditions as $key => $value) {
            $query_params[] = $key . '=eq.' . urlencode($value);
        }
        
        if ($limit) {
            $query_params[] = 'limit=' . intval($limit);
        }
        
        $query_string = implode('&', $query_params);
        
        $response = wp_remote_get($this->project_url . '/rest/v1/' . $table . '?' . $query_string, array(
            'timeout' => 30,
            'headers' => array(
                'apikey' => $this->anon_key,
                'Authorization' => 'Bearer ' . $this->service_key,
                'Content-Type' => 'application/json'
            )
        ));
        
        if (is_wp_error($response)) {
            error_log('Supabase select error: ' . $response->get_error_message());
            return false;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            error_log('Supabase select failed: HTTP ' . $code);
            return false;
        }
        
        return json_decode(wp_remote_retrieve_body($response), true);
    }
    
    /**
     * Start a research session
     */
    public function start_research_session($session_type = 'manual', $batch_size = 1) {
        $session_data = array(
            'session_type' => $session_type,
            'batch_size' => $batch_size,
            'status' => 'running'
        );
        
        $result = $this->insert('research_sessions', $session_data);
        
        if ($result && !empty($result[0]['id'])) {
            return $result[0]['id'];
        }
        
        return false;
    }
    
    /**
     * Complete a research session
     */
    public function complete_research_session($session_id, $success = true, $message = '') {
        $update_data = array(
            'status' => $success ? 'completed' : 'failed',
            'completed_at' => date('c') // ISO 8601 format
        );
        
        return $this->update('research_sessions', array('id' => $session_id), $update_data);
    }
    
    /**
     * Log research operation
     */
    public function log_research_operation($session_id, $operation_type, $item_type, $item_id, $item_name, $duration = 0, $cost = 0, $tokens = 0, $confidence = 0, $sources_found = 0, $sources_used = 0, $success = false, $error = null) {
        $operation_data = array(
            'session_id' => $session_id,
            'operation_type' => $operation_type,
            'item_type' => $item_type,
            'item_id' => $item_id,
            'item_name' => $item_name,
            'duration_seconds' => $duration,
            'cost_usd' => $cost,
            'tokens_used' => $tokens,
            'ai_confidence' => $confidence,
            'sources_found' => $sources_found,
            'sources_used' => $sources_used,
            'success' => $success,
            'error_message' => $error
        );
        
        $result = $this->insert('research_operations', $operation_data);
        
        if ($result && !empty($result[0]['id'])) {
            return $result[0]['id'];
        }
        
        return false;
    }
    
    /**
     * Store event metadata
     */
    public function store_event_meta($event_id, $event_data, $confidence = 0.85) {
        $metadata = array(
            'event_wp_id' => $event_id,
            'event_name' => $event_data['event_name'] ?? '',
            'metadata_json' => $event_data,
            'ai_confidence_score' => $confidence
        );
        
        return $this->insert('events_meta', $metadata);
    }
    
    /**
     * Store venue metadata
     */
    public function store_venue_meta($venue_id, $venue_data, $sentiment_score = 0.5) {
        $metadata = array(
            'venue_cct_id' => $venue_id,
            'venue_name' => $venue_data['name'] ?? '',
            'metadata_json' => $venue_data,
            'sentiment_score' => $sentiment_score
        );
        
        return $this->insert('venues_meta', $metadata);
    }
    
    /**
     * Store performer metadata
     */
    public function store_performer_meta($performer_id, $performer_data, $sentiment_score = 0.5) {
        $metadata = array(
            'performer_cct_id' => $performer_id,
            'performer_name' => $performer_data['name'] ?? '',
            'metadata_json' => $performer_data,
            'sentiment_score' => $sentiment_score
        );
        
        return $this->insert('performers_meta', $metadata);
    }
    
    /**
     * Store note metadata
     */
    public function store_note_meta($note_id, $related_type, $related_id, $note_data) {
        $metadata = array(
            'note_cct_id' => $note_id,
            'related_type' => $related_type,
            'related_id' => $related_id,
            'metadata_json' => $note_data
        );
        
        return $this->insert('notes_meta', $metadata);
    }
    
    /**
     * Track daily budget
     */
    public function track_daily_budget($cost, $api_calls, $tokens, $images, $events, $venues, $performers) {
        $today = date('Y-m-d');
        
        // Try to update existing record
        $existing = $this->select('budget_tracking', array('date' => $today));
        
        if (!empty($existing)) {
            // Update existing record
            $current = $existing[0];
            $update_data = array(
                'total_cost_usd' => ($current['total_cost_usd'] ?? 0) + $cost,
                'api_calls' => ($current['api_calls'] ?? 0) + $api_calls,
                'tokens_used' => ($current['tokens_used'] ?? 0) + $tokens,
                'images_generated' => ($current['images_generated'] ?? 0) + $images,
                'events_processed' => ($current['events_processed'] ?? 0) + $events,
                'venues_processed' => ($current['venues_processed'] ?? 0) + $venues,
                'performers_processed' => ($current['performers_processed'] ?? 0) + $performers
            );
            
            return $this->update('budget_tracking', array('date' => $today), $update_data);
        } else {
            // Insert new record
            $insert_data = array(
                'date' => $today,
                'total_cost_usd' => $cost,
                'api_calls' => $api_calls,
                'tokens_used' => $tokens,
                'images_generated' => $images,
                'events_processed' => $events,
                'venues_processed' => $venues,
                'performers_processed' => $performers
            );
            
            return $this->insert('budget_tracking', $insert_data);
        }
    }
    
    /**
     * Get analytics dashboard data
     */
    public function get_analytics_dashboard() {
        $dashboard_data = array();
        
        // Get recent research performance
        $dashboard_data['research_performance'] = $this->select(
            'research_performance', 
            array(), 
            '*', 
            10
        );
        
        // Get source performance
        $dashboard_data['source_performance'] = $this->select(
            'source_performance', 
            array(), 
            '*', 
            20
        );
        
        // Get budget summary
        $dashboard_data['budget_summary'] = $this->select(
            'daily_budget_summary', 
            array(), 
            '*', 
            30
        );
        
        return $dashboard_data;
    }
    
    /**
     * Update Supabase credentials
     */
    public function update_credentials($url, $anon_key, $service_key) {
        $this->project_url = $url;
        $this->anon_key = $anon_key;
        $this->service_key = $service_key;
        
        update_option('kc_ml_supabase_url', $url);
        update_option('kc_ml_supabase_anon_key', $anon_key);
        update_option('kc_ml_supabase_service_key', $service_key);
    }
    
    /**
     * Check if Supabase is properly configured
     */
    public function is_configured() {
        return !empty($this->project_url) && !empty($this->anon_key) && !empty($this->service_key);
    }
    
    /**
     * Get masked credentials for display
     */
    public function get_masked_credentials() {
        return array(
            'url' => $this->project_url,
            'anon_key' => !empty($this->anon_key) ? substr($this->anon_key, 0, 8) . str_repeat('.', 20) . substr($this->anon_key, -8) : '',
            'service_key' => !empty($this->service_key) ? substr($this->service_key, 0, 8) . str_repeat('.', 20) . substr($this->service_key, -8) : ''
        );
    }
}

?>