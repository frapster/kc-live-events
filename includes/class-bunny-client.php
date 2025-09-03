<?php
/**
 * KC Metro Live - Bunny.net CDN Client Module
 * Handles image uploads and CDN management via Bunny.net
 */

defined('ABSPATH') || exit;

class KC_ML_Bunny_Client {
    
    private $storage_zone;
    private $api_key;
    private $base_url;
    private $cdn_url;
    
    public function __construct() {
        $this->storage_zone = get_option('kc_ml_bunny_zone', '');
        $this->api_key = get_option('kc_ml_bunny_key', '');
        $this->base_url = 'https://storage.bunnycdn.com/' . $this->storage_zone;
        $this->cdn_url = 'https://' . $this->storage_zone . '.b-cdn.net';
    }
    
    /**
     * Test Bunny.net connection
     */
    public function test_connection() {
        if (empty($this->storage_zone) || empty($this->api_key)) {
            return false;
        }
        
        // Test by listing root directory
        $response = wp_remote_get($this->base_url . '/', array(
            'headers' => array(
                'AccessKey' => $this->api_key,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 15
        ));
        
        if (is_wp_error($response)) {
            error_log('KC ML Bunny: Connection test failed - ' . $response->get_error_message());
            return false;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        return $status_code === 200;
    }
    
    /**
     * Test upload functionality
     */
    public function test_upload() {
        if (!$this->test_connection()) {
            return false;
        }
        
        // Create a simple test file
        $test_content = 'KC Metro Live test file - ' . date('Y-m-d H:i:s');
        $test_filename = 'test-' . uniqid() . '.txt';
        
        $upload_result = $this->upload_file_content($test_content, 'tests/' . $test_filename, 'text/plain');
        
        if ($upload_result) {
            // Clean up test file
            $this->delete_file('tests/' . $test_filename);
            return true;
        }
        
        return false;
    }
    
    /**
     * Upload image for an event
     */
    public function upload_event_image($source_url_or_path, $event_id, $event_name) {
        $filename = $this->generate_filename('event', $event_id, $event_name);
        $remote_path = 'events/' . $filename;
        
        return $this->upload_image($source_url_or_path, $remote_path);
    }
    
    /**
     * Upload image for a venue
     */
    public function upload_venue_image($source_url_or_path, $venue_id, $venue_name) {
        $filename = $this->generate_filename('venue', $venue_id, $venue_name);
        $remote_path = 'venues/' . $filename;
        
        return $this->upload_image($source_url_or_path, $remote_path);
    }
    
    /**
     * Upload image for a performer
     */
    public function upload_performer_image($source_url_or_path, $performer_id, $performer_name) {
        $filename = $this->generate_filename('performer', $performer_id, $performer_name);
        $remote_path = 'performers/' . $filename;
        
        return $this->upload_image($source_url_or_path, $remote_path);
    }
    
    /**
     * Generic image upload method
     */
    private function upload_image($source_url_or_path, $remote_path) {
        if (empty($this->storage_zone) || empty($this->api_key)) {
            error_log('KC ML Bunny: Upload failed - credentials not configured');
            return false;
        }
        
        // Get image data
        if (filter_var($source_url_or_path, FILTER_VALIDATE_URL)) {
            // It's a URL - download the image
            $image_data = $this->download_image($source_url_or_path);
        } else {
            // It's a local file path
            if (!file_exists($source_url_or_path)) {
                error_log('KC ML Bunny: Local file does not exist - ' . $source_url_or_path);
                return false;
            }
            $image_data = file_get_contents($source_url_or_path);
        }
        
        if (empty($image_data)) {
            error_log('KC ML Bunny: No image data to upload');
            return false;
        }
        
        // Upload to Bunny.net
        $upload_result = $this->upload_file_content($image_data, $remote_path, 'image/jpeg');
        
        if ($upload_result) {
            $cdn_url = $this->cdn_url . '/' . $remote_path;
            
            // Store image metadata in Supabase if available
            $this->store_image_metadata($remote_path, $cdn_url, strlen($image_data));
            
            return $cdn_url;
        }
        
        return false;
    }
    
    /**
     * Download image from URL
     */
    private function download_image($url) {
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'headers' => array(
                'User-Agent' => 'KC Metro Live/1.0'
            )
        ));
        
        if (is_wp_error($response)) {
            error_log('KC ML Bunny: Failed to download image - ' . $response->get_error_message());
            return false;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            error_log('KC ML Bunny: Image download failed - HTTP ' . $status_code);
            return false;
        }
        
        $content_type = wp_remote_retrieve_header($response, 'content-type');
        if (!$this->is_valid_image_type($content_type)) {
            error_log('KC ML Bunny: Invalid image type - ' . $content_type);
            return false;
        }
        
        return wp_remote_retrieve_body($response);
    }
    
    /**
     * Upload file content to Bunny.net
     */
    private function upload_file_content($content, $remote_path, $content_type = 'application/octet-stream') {
        $url = $this->base_url . '/' . $remote_path;
        
        $response = wp_remote_request($url, array(
            'method' => 'PUT',
            'headers' => array(
                'AccessKey' => $this->api_key,
                'Content-Type' => $content_type,
                'Content-Length' => strlen($content)
            ),
            'body' => $content,
            'timeout' => 60
        ));
        
        if (is_wp_error($response)) {
            error_log('KC ML Bunny: Upload failed - ' . $response->get_error_message());
            return false;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        
        if ($status_code !== 201) {
            $body = wp_remote_retrieve_body($response);
            error_log('KC ML Bunny: Upload failed - HTTP ' . $status_code . ' - ' . $body);
            return false;
        }
        
        return true;
    }
    
    /**
     * Delete file from Bunny.net
     */
    public function delete_file($remote_path) {
        if (empty($this->storage_zone) || empty($this->api_key)) {
            return false;
        }
        
        $url = $this->base_url . '/' . $remote_path;
        
        $response = wp_remote_request($url, array(
            'method' => 'DELETE',
            'headers' => array(
                'AccessKey' => $this->api_key
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            error_log('KC ML Bunny: Delete failed - ' . $response->get_error_message());
            return false;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        return $status_code === 200;
    }
    
    /**
     * List files in a directory
     */
    public function list_files($directory = '') {
        if (empty($this->storage_zone) || empty($this->api_key)) {
            return array();
        }
        
        $url = $this->base_url . '/' . $directory;
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'AccessKey' => $this->api_key,
                'Accept' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            error_log('KC ML Bunny: List failed - ' . $response->get_error_message());
            return array();
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        
        if ($status_code !== 200) {
            return array();
        }
        
        $body = wp_remote_retrieve_body($response);
        $files = json_decode($body, true);
        
        return is_array($files) ? $files : array();
    }
    
    /**
     * Get file info
     */
    public function get_file_info($remote_path) {
        $directory = dirname($remote_path);
        $filename = basename($remote_path);
        
        $files = $this->list_files($directory);
        
        foreach ($files as $file) {
            if ($file['ObjectName'] === $filename) {
                return $file;
            }
        }
        
        return null;
    }
    
    /**
     * Generate filename for uploads
     */
    private function generate_filename($type, $id, $name) {
        // Sanitize name for filename
        $clean_name = sanitize_file_name(strtolower($name));
        $clean_name = preg_replace('/[^a-z0-9\-]/', '-', $clean_name);
        $clean_name = preg_replace('/-+/', '-', $clean_name);
        $clean_name = trim($clean_name, '-');
        
        if (empty($clean_name)) {
            $clean_name = 'unnamed';
        }
        
        // Create filename: type-id-name-timestamp.jpg
        $timestamp = date('Ymd-His');
        return "{$type}-{$id}-{$clean_name}-{$timestamp}.jpg";
    }
    
    /**
     * Validate image content type
     */
    private function is_valid_image_type($content_type) {
        $valid_types = array(
            'image/jpeg',
            'image/jpg',
            'image/png',
            'image/gif',
            'image/webp'
        );
        
        return in_array(strtolower($content_type), $valid_types);
    }
    
    /**
     * Store image metadata (requires Supabase)
     */
    private function store_image_metadata($remote_path, $cdn_url, $file_size) {
        // Only store if Supabase is available
        if (class_exists('KC_ML_Supabase_Client')) {
            $supabase = new KC_ML_Supabase_Client();
            
            // Extract info from path
            $path_parts = explode('/', $remote_path);
            $item_type = $path_parts[0] ?? 'unknown'; // events, venues, performers
            $filename = $path_parts[1] ?? '';
            
            // Extract ID from filename (format: type-id-name-timestamp.jpg)
            $filename_parts = explode('-', $filename);
            $item_id = $filename_parts[1] ?? 0;
            
            $metadata = array(
                'item_type' => rtrim($item_type, 's'), // Remove 's' from plural
                'item_id' => intval($item_id),
                'bunny_url' => $cdn_url,
                'bunny_filename' => $filename,
                'generation_prompt' => '', // Would be filled by calling function
                'generation_cost_usd' => 0.07, // Approximate DALL-E cost
                'file_size_bytes' => $file_size,
                'width' => null, // Could be determined by image analysis
                'height' => null,
                'created_at' => date('c')
            );
            
            $supabase->insert('image_assets', $metadata);
        }
    }
    
    /**
     * Clean up old images
     */
    public function cleanup_old_images($days_old = 90) {
        $directories = array('events', 'venues', 'performers');
        $cutoff_date = date('Ymd', strtotime("-{$days_old} days"));
        $deleted_count = 0;
        
        foreach ($directories as $directory) {
            $files = $this->list_files($directory);
            
            foreach ($files as $file) {
                $filename = $file['ObjectName'];
                
                // Extract date from filename (format: type-id-name-YYYYMMDD-HHMMSS.jpg)
                if (preg_match('/(\d{8})-\d{6}\.jpg$/', $filename, $matches)) {
                    $file_date = $matches[1];
                    
                    if ($file_date < $cutoff_date) {
                        $remote_path = $directory . '/' . $filename;
                        if ($this->delete_file($remote_path)) {
                            $deleted_count++;
                        }
                    }
                }
            }
        }
        
        return $deleted_count;
    }
    
    /**
     * Get storage statistics
     */
    public function get_storage_stats() {
        $directories = array('events', 'venues', 'performers', 'tests');
        $stats = array(
            'total_files' => 0,
            'total_size' => 0,
            'by_type' => array()
        );
        
        foreach ($directories as $directory) {
            $files = $this->list_files($directory);
            $dir_count = 0;
            $dir_size = 0;
            
            foreach ($files as $file) {
                $dir_count++;
                $dir_size += $file['Length'] ?? 0;
            }
            
            $stats['by_type'][$directory] = array(
                'files' => $dir_count,
                'size' => $dir_size
            );
            
            $stats['total_files'] += $dir_count;
            $stats['total_size'] += $dir_size;
        }
        
        return $stats;
    }
    
    /**
     * Get CDN URL for a file
     */
    public function get_cdn_url($remote_path) {
        return $this->cdn_url . '/' . $remote_path;
    }
    
    /**
     * Purge CDN cache for a file or directory
     */
    public function purge_cache($url_or_path) {
        // This would require the Bunny.net Pull Zone API
        // For now, just log the request
        error_log('KC ML Bunny: Cache purge requested for ' . $url_or_path);
        return true;
    }
    
    /**
     * Generate optimized image URL with transformations
     */
    public function get_optimized_url($remote_path, $width = null, $height = null, $quality = 80) {
        $base_url = $this->get_cdn_url($remote_path);
        
        // Bunny.net Optimizer parameters
        $params = array();
        
        if ($width) {
            $params[] = 'width=' . intval($width);
        }
        
        if ($height) {
            $params[] = 'height=' . intval($height);
        }
        
        if ($quality && $quality !== 80) {
            $params[] = 'quality=' . intval($quality);
        }
        
        if (!empty($params)) {
            $base_url .= '?' . implode('&', $params);
        }
        
        return $base_url;
    }
    
    /**
     * Batch upload multiple files
     */
    public function batch_upload($files) {
        $results = array();
        
        foreach ($files as $file) {
            $source = $file['source'];
            $remote_path = $file['remote_path'];
            $type = $file['type'] ?? 'image/jpeg';
            
            $result = $this->upload_file_content($source, $remote_path, $type);
            $results[] = array(
                'remote_path' => $remote_path,
                'success' => $result,
                'cdn_url' => $result ? $this->get_cdn_url($remote_path) : null
            );
        }
        
        return $results;
    }
}
?>