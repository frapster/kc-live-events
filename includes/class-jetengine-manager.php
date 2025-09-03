<?php
/**
 * KC Metro Live - JetEngine Manager Module
 * Handles JetEngine CCT operations and relationships
 */

defined('ABSPATH') || exit;

class KC_ML_JetEngine_Manager {
    
    private $cct_manager;
    private $relations_manager;
    private $cct_types;
    private $relation_names;
    
    public function __construct() {
        $this->init_jetengine();
        
        // Define CCT types
        $this->cct_types = array(
            'venues' => 'venues',
            'performers' => 'performers', 
            'notes' => 'notes'
        );
        
        // Define relation names (these should match your JetEngine setup)
        $this->relation_names = array(
            'events_venues' => 'events-to-venues',
            'events_performers' => 'events-to-performers',
            'venues_notes' => 'venues-to-notes',
            'performers_notes' => 'performers-to-notes',
            'events_notes' => 'events-to-notes'
        );
    }
    
    /**
     * Initialize JetEngine components
     */
    private function init_jetengine() {
        if (!class_exists('Jet_Engine')) {
            return false;
        }
        
        // Get JetEngine CCT manager
        if (method_exists('Jet_Engine', 'cct') && Jet_Engine::instance()->cct) {
            $this->cct_manager = Jet_Engine::instance()->cct->data;
        }
        
        // Get relations manager
        if (method_exists('Jet_Engine', 'relations') && Jet_Engine::instance()->relations) {
            $this->relations_manager = Jet_Engine::instance()->relations;
        }
        
        return true;
    }
    
    /**
     * Check if JetEngine is properly configured
     */
    public function check_setup_status() {
        $status = array(
            'jetengine_active' => class_exists('Jet_Engine'),
            'cct_manager_available' => !empty($this->cct_manager),
            'relations_manager_available' => !empty($this->relations_manager),
            'relations_ready' => false,
            'queries_ready' => false,
            'cct_types' => array(),
            'relations' => array(),
            'queries' => array()
        );
        
        if (!$status['jetengine_active']) {
            return $status;
        }
        
        // Check CCT types
        foreach ($this->cct_types as $key => $slug) {
            $exists = $this->cct_exists($slug);
            $status['cct_types'][$key] = array(
                'slug' => $slug,
                'exists' => $exists
            );
        }
        
        // Check relations
        if ($this->relations_manager) {
            $all_relations = $this->relations_manager->get_relations();
            
            foreach ($this->relation_names as $key => $name) {
                $relation = $this->get_relation_by_name($name);
                $status['relations'][$key] = array(
                    'name' => $name,
                    'exists' => !empty($relation),
                    'relation' => $relation
                );
            }
            
            $status['relations_ready'] = count(array_filter($status['relations'], function($r) {
                return $r['exists'];
            })) >= 3; // At least 3 core relations
        }
        
        // Check queries (if applicable)
        $status['queries_ready'] = true; // We'll create these programmatically
        
        return $status;
    }
    
    /**
     * Check if CCT exists
     */
    private function cct_exists($slug) {
        if (!$this->cct_manager) {
            return false;
        }
        
        $content_types = $this->cct_manager->get_content_types();
        
        foreach ($content_types as $type) {
            if ($type['slug'] === $slug) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Get relation by name
     */
    private function get_relation_by_name($name) {
        if (!$this->relations_manager) {
            return null;
        }
        
        $relations = $this->relations_manager->get_relations();
        
        foreach ($relations as $relation) {
            if ($relation['name'] === $name || $relation['slug'] === $name) {
                return $relation;
            }
        }
        
        return null;
    }
    
    /**
     * Create CCT item
     */
    public function create_cct_item($cct_type, $data) {
        if (!isset($this->cct_types[$cct_type])) {
            error_log('KC ML JetEngine: Unknown CCT type - ' . $cct_type);
            return false;
        }
        
        $slug = $this->cct_types[$cct_type];
        
        if (!$this->cct_manager) {
            error_log('KC ML JetEngine: CCT manager not available');
            return false;
        }
        
        // Prepare data for insertion
        $insert_data = $this->prepare_cct_data($data, $cct_type);
        
        try {
            $item_id = $this->cct_manager->insert_item($slug, $insert_data);
            
            if ($item_id) {
                error_log('KC ML JetEngine: Created ' . $cct_type . ' item with ID ' . $item_id);
                return $item_id;
            } else {
                error_log('KC ML JetEngine: Failed to create ' . $cct_type . ' item');
                return false;
            }
        } catch (Exception $e) {
            error_log('KC ML JetEngine: Exception creating ' . $cct_type . ' - ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update CCT item
     */
    public function update_cct_item($cct_type, $item_id, $data) {
        if (!isset($this->cct_types[$cct_type])) {
            error_log('KC ML JetEngine: Unknown CCT type - ' . $cct_type);
            return false;
        }
        
        $slug = $this->cct_types[$cct_type];
        
        if (!$this->cct_manager) {
            error_log('KC ML JetEngine: CCT manager not available');
            return false;
        }
        
        // Prepare data for update
        $update_data = $this->prepare_cct_data($data, $cct_type);
        
        try {
            $result = $this->cct_manager->update_item($slug, $item_id, $update_data);
            
            if ($result) {
                error_log('KC ML JetEngine: Updated ' . $cct_type . ' item ' . $item_id);
                return true;
            } else {
                error_log('KC ML JetEngine: Failed to update ' . $cct_type . ' item ' . $item_id);
                return false;
            }
        } catch (Exception $e) {
            error_log('KC ML JetEngine: Exception updating ' . $cct_type . ' - ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get CCT item
     */
    public function get_cct_item($cct_type, $item_id) {
        if (!isset($this->cct_types[$cct_type])) {
            return null;
        }
        
        $slug = $this->cct_types[$cct_type];
        
        if (!$this->cct_manager) {
            return null;
        }
        
        try {
            return $this->cct_manager->get_item($slug, $item_id);
        } catch (Exception $e) {
            error_log('KC ML JetEngine: Exception getting ' . $cct_type . ' item - ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Find CCT item by field value
     */
    public function find_cct_item($cct_type, $field, $value) {
        if (!isset($this->cct_types[$cct_type])) {
            return null;
        }
        
        $slug = $this->cct_types[$cct_type];
        
        if (!$this->cct_manager) {
            return null;
        }
        
        try {
            $items = $this->cct_manager->get_filtered_items($slug, array(
                $field => $value
            ));
            
            return !empty($items) ? $items[0] : null;
        } catch (Exception $e) {
            error_log('KC ML JetEngine: Exception finding ' . $cct_type . ' item - ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Delete CCT item
     */
    public function delete_cct_item($cct_type, $item_id) {
        if (!isset($this->cct_types[$cct_type])) {
            return false;
        }
        
        $slug = $this->cct_types[$cct_type];
        
        if (!$this->cct_manager) {
            return false;
        }
        
        try {
            $result = $this->cct_manager->delete_item($slug, $item_id);
            
            if ($result) {
                error_log('KC ML JetEngine: Deleted ' . $cct_type . ' item ' . $item_id);
                return true;
            } else {
                error_log('KC ML JetEngine: Failed to delete ' . $cct_type . ' item ' . $item_id);
                return false;
            }
        } catch (Exception $e) {
            error_log('KC ML JetEngine: Exception deleting ' . $cct_type . ' - ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Set relation between items
     */
    public function set_relation($relation_key, $parent_id, $child_id) {
        if (!isset($this->relation_names[$relation_key])) {
            error_log('KC ML JetEngine: Unknown relation - ' . $relation_key);
            return false;
        }
        
        $relation_name = $this->relation_names[$relation_key];
        $relation = $this->get_relation_by_name($relation_name);
        
        if (!$relation) {
            error_log('KC ML JetEngine: Relation not found - ' . $relation_name);
            return false;
        }
        
        if (!$this->relations_manager) {
            error_log('KC ML JetEngine: Relations manager not available');
            return false;
        }
        
        try {
            // Get relation instance
            $relation_instance = $this->relations_manager->get_active_relations($relation['id']);
            
            if (!$relation_instance) {
                error_log('KC ML JetEngine: Could not get relation instance for ' . $relation_name);
                return false;
            }
            
            // Set the relation
            $result = $relation_instance->update($parent_id, $child_id);
            
            if ($result) {
                error_log('KC ML JetEngine: Set relation ' . $relation_key . ' between ' . $parent_id . ' and ' . $child_id);
                return true;
            } else {
                error_log('KC ML JetEngine: Failed to set relation ' . $relation_key);
                return false;
            }
        } catch (Exception $e) {
            error_log('KC ML JetEngine: Exception setting relation - ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get related items
     */
    public function get_related_items($relation_key, $parent_id) {
        if (!isset($this->relation_names[$relation_key])) {
            return array();
        }
        
        $relation_name = $this->relation_names[$relation_key];
        $relation = $this->get_relation_by_name($relation_name);
        
        if (!$relation || !$this->relations_manager) {
            return array();
        }
        
        try {
            $relation_instance = $this->relations_manager->get_active_relations($relation['id']);
            
            if (!$relation_instance) {
                return array();
            }
            
            return $relation_instance->get_related_posts($parent_id);
        } catch (Exception $e) {
            error_log('KC ML JetEngine: Exception getting related items - ' . $e->getMessage());
            return array();
        }
    }
    
    /**
     * Remove relation between items
     */
    public function remove_relation($relation_key, $parent_id, $child_id = null) {
        if (!isset($this->relation_names[$relation_key])) {
            return false;
        }
        
        $relation_name = $this->relation_names[$relation_key];
        $relation = $this->get_relation_by_name($relation_name);
        
        if (!$relation || !$this->relations_manager) {
            return false;
        }
        
        try {
            $relation_instance = $this->relations_manager->get_active_relations($relation['id']);
            
            if (!$relation_instance) {
                return false;
            }
            
            if ($child_id) {
                // Remove specific relation
                $result = $relation_instance->delete($parent_id, $child_id);
            } else {
                // Remove all relations for parent
                $result = $relation_instance->delete_all_relations($parent_id);
            }
            
            return $result;
        } catch (Exception $e) {
            error_log('KC ML JetEngine: Exception removing relation - ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Prepare CCT data for insertion/update
     */
    private function prepare_cct_data($data, $cct_type) {
        $prepared = array();
        
        // Common preparation for all types
        foreach ($data as $key => $value) {
            // Handle arrays (like sentiment_words, style_of_music)
            if (is_array($value)) {
                $prepared[$key] = json_encode($value);
            } 
            // Handle boolean values
            elseif (is_bool($value)) {
                $prepared[$key] = $value ? 1 : 0;
            }
            // Handle empty values
            elseif (empty($value) && $value !== 0) {
                $prepared[$key] = '';
            }
            // Default handling
            else {
                $prepared[$key] = $value;
            }
        }
        
        // Type-specific preparation
        switch ($cct_type) {
            case 'venues':
                $prepared = $this->prepare_venue_data($prepared);
                break;
                
            case 'performers':
                $prepared = $this->prepare_performer_data($prepared);
                break;
                
            case 'notes':
                $prepared = $this->prepare_note_data($prepared);
                break;
        }
        
        return $prepared;
    }
    
    /**
     * Prepare venue-specific data
     */
    private function prepare_venue_data($data) {
        // Ensure required fields have defaults
        $defaults = array(
            'name' => '',
            'type' => 'bar',
            'address' => '',
            'city' => '',
            'state' => 'MO',
            'zip' => '',
            'description' => '',
            'rating_sentiment' => 'meh',
            'outdoor_indoor' => 'indoor',
            'pet_friendly' => 0,
            'accessibility' => 0,
            'verified' => json_encode(array('unverified'))
        );
        
        return array_merge($defaults, $data);
    }
    
    /**
     * Prepare performer-specific data
     */
    private function prepare_performer_data($data) {
        // Ensure required fields have defaults
        $defaults = array(
            'name' => '',
            'description' => '',
            'performer_type' => 'band',
            'local_touring' => 'local',
            'rating_sentiment' => 'meh',
            'location' => 'Kansas City, MO',
            'verified' => json_encode(array('unverified'))
        );
        
        return array_merge($defaults, $data);
    }
    
    /**
     * Prepare note-specific data
     */
    private function prepare_note_data($data) {
        // Ensure required fields have defaults
        $defaults = array(
            'note_text' => '',
            'source' => 'Unknown',
            'date' => date('Y-m-d'),
            'related_to' => 'unknown'
        );
        
        return array_merge($defaults, $data);
    }
    
    /**
     * Get CCT items with pagination
     */
    public function get_cct_items($cct_type, $args = array()) {
        if (!isset($this->cct_types[$cct_type])) {
            return array();
        }
        
        $slug = $this->cct_types[$cct_type];
        
        if (!$this->cct_manager) {
            return array();
        }
        
        $defaults = array(
            'limit' => 10,
            'offset' => 0,
            'orderby' => '_ID',
            'order' => 'DESC'
        );
        
        $args = array_merge($defaults, $args);
        
        try {
            return $this->cct_manager->get_items($slug, $args);
        } catch (Exception $e) {
            error_log('KC ML JetEngine: Exception getting ' . $cct_type . ' items - ' . $e->getMessage());
            return array();
        }
    }
    
    /**
     * Search CCT items
     */
    public function search_cct_items($cct_type, $search_term, $fields = array()) {
        if (!isset($this->cct_types[$cct_type])) {
            return array();
        }
        
        $slug = $this->cct_types[$cct_type];
        
        if (!$this->cct_manager) {
            return array();
        }
        
        // Default search fields
        if (empty($fields)) {
            switch ($cct_type) {
                case 'venues':
                    $fields = array('name', 'description', 'address', 'city');
                    break;
                case 'performers':
                    $fields = array('name', 'description', 'location');
                    break;
                case 'notes':
                    $fields = array('note_text');
                    break;
            }
        }
        
        $search_conditions = array();
        foreach ($fields as $field) {
            $search_conditions[] = array(
                'field' => $field,
                'operator' => 'LIKE',
                'value' => '%' . $search_term . '%'
            );
        }
        
        try {
            return $this->cct_manager->get_filtered_items($slug, $search_conditions, 'OR');
        } catch (Exception $e) {
            error_log('KC ML JetEngine: Exception searching ' . $cct_type . ' items - ' . $e->getMessage());
            return array();
        }
    }
    
    /**
     * Count CCT items
     */
    public function count_cct_items($cct_type, $conditions = array()) {
        if (!isset($this->cct_types[$cct_type])) {
            return 0;
        }
        
        $slug = $this->cct_types[$cct_type];
        
        if (!$this->cct_manager) {
            return 0;
        }
        
        try {
            return $this->cct_manager->get_items_count($slug, $conditions);
        } catch (Exception $e) {
            error_log('KC ML JetEngine: Exception counting ' . $cct_type . ' items - ' . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Bulk update CCT items
     */
    public function bulk_update_cct_items($cct_type, $items) {
        $success_count = 0;
        $error_count = 0;
        
        foreach ($items as $item) {
            $item_id = $item['id'] ?? null;
            $data = $item['data'] ?? array();
            
            if (!$item_id || empty($data)) {
                $error_count++;
                continue;
            }
            
            if ($this->update_cct_item($cct_type, $item_id, $data)) {
                $success_count++;
            } else {
                $error_count++;
            }
        }
        
        return array(
            'success' => $success_count,
            'errors' => $error_count,
            'total' => count($items)
        );
    }
    
    /**
     * Get CCT statistics
     */
    public function get_cct_statistics() {
        $stats = array();
        
        foreach ($this->cct_types as $type => $slug) {
            $total = $this->count_cct_items($type);
            $verified = $this->count_cct_items($type, array(
                array(
                    'field' => 'verified',
                    'operator' => 'LIKE',
                    'value' => '%verified%'
                )
            ));
            
            $stats[$type] = array(
                'total' => $total,
                'verified' => $verified,
                'unverified' => $total - $verified
            );
        }
        
        return $stats;
    }
    
    /**
     * Export CCT data
     */
    public function export_cct_data($cct_type, $format = 'json') {
        $items = $this->get_cct_items($cct_type, array('limit' => -1)); // Get all items
        
        switch ($format) {
            case 'csv':
                return $this->export_to_csv($items);
            case 'xml':
                return $this->export_to_xml($items);
            case 'json':
            default:
                return json_encode($items, JSON_PRETTY_PRINT);
        }
    }
    
    /**
     * Export to CSV format
     */
    private function export_to_csv($items) {
        if (empty($items)) {
            return '';
        }
        
        $csv = '';
        $headers = array_keys($items[0]);
        $csv .= implode(',', $headers) . "\n";
        
        foreach ($items as $item) {
            $row = array();
            foreach ($headers as $header) {
                $value = $item[$header] ?? '';
                // Escape quotes and wrap in quotes if contains comma
                if (strpos($value, ',') !== false || strpos($value, '"') !== false) {
                    $value = '"' . str_replace('"', '""', $value) . '"';
                }
                $row[] = $value;
            }
            $csv .= implode(',', $row) . "\n";
        }
        
        return $csv;
    }
    
    /**
     * Export to XML format
     */
    private function export_to_xml($items) {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<items>' . "\n";
        
        foreach ($items as $item) {
            $xml .= '  <item>' . "\n";
            foreach ($item as $key => $value) {
                $xml .= '    <' . $key . '>' . htmlspecialchars($value) . '</' . $key . '>' . "\n";
            }
            $xml .= '  </item>' . "\n";
        }
        
        $xml .= '</items>';
        
        return $xml;
    }
}
?>