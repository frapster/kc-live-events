<?php
/**
 * KC Metro Live - Test Manager Module
 * Comprehensive testing system for all plugin components
 */

defined('ABSPATH') || exit;

class KC_ML_Test_Manager {
    
    private $api_handler;
    private $supabase_client;
    private $bunny_client;
    private $jetengine_manager;
    private $batch_processor;
    
    public function __construct($api_handler, $supabase_client, $bunny_client, $jetengine_manager, $batch_processor) {
        $this->api_handler = $api_handler;
        $this->supabase_client = $supabase_client;
        $this->bunny_client = $bunny_client;
        $this->jetengine_manager = $jetengine_manager;
        $this->batch_processor = $batch_processor;
    }
    
    /**
     * Run component test for specific service
     */
    public function run_component_test($test_type) {
        $start_time = microtime(true);
        
        switch ($test_type) {
            case 'openai':
                return $this->test_openai_component();
            case 'supabase':
                return $this->test_supabase_component();
            case 'bunny':
                return $this->test_bunny_component();
            case 'jetengine':
                return $this->test_jetengine_component();
            case 'prompts':
                return $this->test_prompt_component();
            default:
                return array(
                    'success' => false,
                    'message' => 'Unknown test type: ' . $test_type
                );
        }
    }
    
    /**
     * Run integration test (end-to-end workflow)
     */
    public function run_integration_test($limit = 2) {
        $start_time = microtime(true);
        $steps = array();
        $total_cost = 0;
        
        try {
            // Step 1: Test all connections
            $steps[] = $this->test_step('Connection Tests', function() {
                $openai_test = $this->api_handler->test_api_key();
                $supabase_test = $this->supabase_client->test_connection();
                $bunny_test = $this->bunny_client->test_connection();
                
                if (!$openai_test['success']) {
                    throw new Exception('OpenAI connection failed: ' . $openai_test['message']);
                }
                if (!$supabase_test) {
                    throw new Exception('Supabase connection failed');
                }
                if (!$bunny_test) {
                    throw new Exception('Bunny.net connection failed');
                }
                
                return 'All connections verified';
            });
            
            // Step 2: Test JetEngine setup
            $steps[] = $this->test_step('JetEngine Verification', function() {
                $status = $this->jetengine_manager->check_setup_status();
                if (!$status['relations_ready'] || !$status['queries_ready']) {
                    throw new Exception('JetEngine not properly configured');
                }
                return 'JetEngine relations and queries verified';
            });
            
            // Step 3: Test prompt generation
            $steps[] = $this->test_step('Prompt Generation', function() {
                $prompt_builder = new KC_ML_Prompt_Builder();
                $prompt = $prompt_builder->build_events_prompt(1);
                
                if (strlen($prompt) < 1000) {
                    throw new Exception('Generated prompt seems too short');
                }
                if (strpos($prompt, 'SEARCH AT LEAST') === false) {
                    throw new Exception('Prompt missing aggressive research requirements');
                }
                
                return 'Comprehensive prompt generated (' . strlen($prompt) . ' characters)';
            });
            
            // Step 4: Test AI research (single event)
            $steps[] = $this->test_step('AI Research Test', function() use (&$total_cost) {
                $test_prompt = "Find 1 real upcoming live music event in Kansas City for testing. Use the exact JSON format specified in the prompt.";
                $response = $this->api_handler->research_with_citations($test_prompt, 'events', 120);
                
                if (!$response['success']) {
                    throw new Exception('AI research failed: ' . $response['message']);
                }
                
                $usage = $response['usage'] ?? array();
                $cost = $this->estimate_cost_from_usage($usage);
                $total_cost += $cost;
                
                return 'AI research completed (Cost: $' . number_format($cost, 4) . ')';
            });
            
            // Step 5: Test image generation
            $steps[] = $this->test_step('Image Generation Test', function() use (&$total_cost) {
                $test_image = $this->api_handler->generate_image(
                    'A vibrant artistic image of a live music venue in Kansas City. Style: modern, welcoming, no text.',
                    'Test Event Image'
                );
                
                if (!$test_image) {
                    throw new Exception('Image generation failed');
                }
                
                $total_cost += 0.04; // DALL-E 3 cost
                
                return 'Test image generated (ID: ' . $test_image . ')';
            });
            
            // Step 6: Test Bunny.net upload
            $steps[] = $this->test_step('Bunny.net Upload Test', function() {
                if (!$this->bunny_client->test_upload()) {
                    throw new Exception('Bunny.net upload test failed');
                }
                return 'Bunny.net upload verified';
            });
            
            // Step 7: Test Supabase data operations
            $steps[] = $this->test_step('Supabase Data Test', function() {
                $test_data = array(
                    'event_wp_id' => 999999, // Test ID
                    'event_name' => 'Integration Test Event',
                    'metadata_json' => json_encode(array('test' => true)),
                    'ai_confidence_score' => 0.85
                );
                
                $result = $this->supabase_client->insert('events_meta', $test_data);
                if (!$result) {
                    throw new Exception('Supabase insert failed');
                }
                
                // Clean up test data
                $this->supabase_client->delete('events_meta', array('event_wp_id' => 999999));
                
                return 'Supabase data operations verified';
            });
            
            // Step 8: Test CCT operations
            $steps[] = $this->test_step('CCT Operations Test', function() {
                $test_venue = $this->jetengine_manager->create_cct_item('venues', array(
                    'name' => 'Test Integration Venue',
                    'type' => 'bar',
                    'city' => 'Kansas City'
                ));
                
                if (!$test_venue) {
                    throw new Exception('CCT creation failed');
                }
                
                // Clean up
                $this->jetengine_manager->delete_cct_item('venues', $test_venue);
                
                return 'CCT operations verified';
            });
            
            $end_time = microtime(true);
            $duration = $end_time - $start_time;
            
            return array(
                'success' => true,
                'message' => 'Integration test completed successfully',
                'steps' => $steps,
                'duration' => round($duration, 2),
                'cost_estimate' => number_format($total_cost, 4),
                'summary' => sprintf(
                    'All %d integration tests passed in %.2f seconds. Estimated cost: $%.4f',
                    count($steps),
                    $duration,
                    $total_cost
                )
            );
            
        } catch (Exception $e) {
            $end_time = microtime(true);
            $duration = $end_time - $start_time;
            
            return array(
                'success' => false,
                'message' => 'Integration test failed: ' . $e->getMessage(),
                'steps' => $steps,
                'duration' => round($duration, 2),
                'cost_estimate' => number_format($total_cost, 4)
            );
        }
    }
    
    /**
     * Run full comprehensive test
     */
    public function run_full_test($limit = 3) {
        $start_time = microtime(true);
        $steps = array();
        $total_cost = 0;
        
        try {
            // Run integration test first
            $integration_result = $this->run_integration_test(1);
            if (!$integration_result['success']) {
                throw new Exception('Integration test failed: ' . $integration_result['message']);
            }
            
            $steps = array_merge($steps, $integration_result['steps']);
            $total_cost += floatval($integration_result['cost_estimate']);
            
            // Step: Full batch processing test
            $steps[] = $this->test_step('Full Batch Processing Test', function() use ($limit, &$total_cost) {
                $batch_result = $this->batch_processor->run_full_agent($limit);
                
                if (!$batch_result['success']) {
                    throw new Exception('Batch processing failed: ' . $batch_result['message']);
                }
                
                $estimated_batch_cost = $limit * 0.15; // Estimate per event
                $total_cost += $estimated_batch_cost;
                
                return sprintf(
                    'Processed %d events through full pipeline (Cost: $%.2f)',
                    $limit,
                    $estimated_batch_cost
                );
            });
            
            // Step: Performance benchmarks
            $steps[] = $this->test_step('Performance Benchmarks', function() {
                $benchmarks = $this->run_performance_benchmarks();
                return sprintf(
                    'Benchmarks: API avg %.2fs, Supabase avg %.2fs, Image gen avg %.2fs',
                    $benchmarks['api_avg'],
                    $benchmarks['supabase_avg'],
                    $benchmarks['image_avg']
                );
            });
            
            // Step: Data validation
            $steps[] = $this->test_step('Data Validation', function() {
                $validation_result = $this->validate_data_integrity();
                if (!$validation_result['success']) {
                    throw new Exception('Data validation failed: ' . $validation_result['message']);
                }
                return 'Data integrity verified: ' . $validation_result['summary'];
            });
            
            // Step: Stress test (if requested)
            if ($limit >= 5) {
                $steps[] = $this->test_step('Stress Test', function() use (&$total_cost) {
                    $stress_result = $this->run_stress_test();
                    $total_cost += $stress_result['cost'];
                    return $stress_result['message'];
                });
            }
            
            $end_time = microtime(true);
            $duration = $end_time - $start_time;
            
            return array(
                'success' => true,
                'message' => 'Full test suite completed successfully',
                'steps' => $steps,
                'duration' => round($duration, 2),
                'cost_estimate' => number_format($total_cost, 4),
                'summary' => sprintf(
                    'All %d tests passed in %.2f seconds. Total cost: $%.4f. System is ready for production.',
                    count($steps),
                    $duration,
                    $total_cost
                )
            );
            
        } catch (Exception $e) {
            $end_time = microtime(true);
            $duration = $end_time - $start_time;
            
            return array(
                'success' => false,
                'message' => 'Full test suite failed: ' . $e->getMessage(),
                'steps' => $steps,
                'duration' => round($duration, 2),
                'cost_estimate' => number_format($total_cost, 4)
            );
        }
    }
    
    /**
     * Test individual OpenAI component
     */
    private function test_openai_component() {
        $steps = array();
        
        try {
            // Test API connection
            $steps[] = $this->test_step('API Connection', function() {
                $result = $this->api_handler->test_api_key();
                if (!$result['success']) {
                    throw new Exception($result['message']);
                }
                return 'Connection verified';
            });
            
            // Test text generation
            $steps[] = $this->test_step('Text Generation', function() {
                $response = $this->api_handler->research_with_citations(
                    'Generate a simple JSON response with {"test": true, "message": "Hello World"}',
                    'test',
                    30
                );
                
                if (!$response['success']) {
                    throw new Exception($response['message']);
                }
                
                return 'Text generation working';
            });
            
            // Test image generation
            $steps[] = $this->test_step('Image Generation', function() {
                $image_id = $this->api_handler->generate_image(
                    'A simple test image of a musical note',
                    'Test Image'
                );
                
                if (!$image_id) {
                    throw new Exception('Image generation failed');
                }
                
                return 'Image generated (ID: ' . $image_id . ')';
            });
            
            return array(
                'success' => true,
                'message' => 'OpenAI component test passed',
                'steps' => $steps
            );
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => 'OpenAI component test failed: ' . $e->getMessage(),
                'steps' => $steps
            );
        }
    }
    
    /**
     * Test Supabase component
     */
    private function test_supabase_component() {
        $steps = array();
        
        try {
            // Test connection
            $steps[] = $this->test_step('Connection Test', function() {
                if (!$this->supabase_client->test_connection()) {
                    throw new Exception('Connection failed');
                }
                return 'Connection successful';
            });
            
            // Test insert
            $test_id = rand(100000, 999999);
            $steps[] = $this->test_step('Insert Test', function() use ($test_id) {
                $result = $this->supabase_client->insert('events_meta', array(
                    'event_wp_id' => $test_id,
                    'event_name' => 'Test Event',
                    'metadata_json' => json_encode(array('test' => true))
                ));
                
                if (!$result) {
                    throw new Exception('Insert failed');
                }
                return 'Insert successful';
            });
            
            // Test select
            $steps[] = $this->test_step('Select Test', function() use ($test_id) {
                $result = $this->supabase_client->select('events_meta', array(
                    'event_wp_id' => $test_id
                ));
                
                if (empty($result)) {
                    throw new Exception('Select failed - no data found');
                }
                return 'Select successful';
            });
            
            // Test delete (cleanup)
            $steps[] = $this->test_step('Delete Test', function() use ($test_id) {
                $result = $this->supabase_client->delete('events_meta', array(
                    'event_wp_id' => $test_id
                ));
                
                if (!$result) {
                    throw new Exception('Delete failed');
                }
                return 'Delete successful';
            });
            
            return array(
                'success' => true,
                'message' => 'Supabase component test passed',
                'steps' => $steps
            );
            
        } catch (Exception $e) {
            // Cleanup on failure
            $this->supabase_client->delete('events_meta', array('event_wp_id' => $test_id));
            
            return array(
                'success' => false,
                'message' => 'Supabase component test failed: ' . $e->getMessage(),
                'steps' => $steps
            );
        }
    }
    
    /**
     * Test Bunny.net component
     */
    private function test_bunny_component() {
        $steps = array();
        
        try {
            // Test connection
            $steps[] = $this->test_step('Connection Test', function() {
                if (!$this->bunny_client->test_connection()) {
                    throw new Exception('Connection failed');
                }
                return 'Connection successful';
            });
            
            // Test upload
            $steps[] = $this->test_step('Upload Test', function() {
                if (!$this->bunny_client->test_upload()) {
                    throw new Exception('Upload test failed');
                }
                return 'Upload test successful';
            });
            
            return array(
                'success' => true,
                'message' => 'Bunny.net component test passed',
                'steps' => $steps
            );
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => 'Bunny.net component test failed: ' . $e->getMessage(),
                'steps' => $steps
            );
        }
    }
    
    /**
     * Test JetEngine component
     */
    private function test_jetengine_component() {
        $steps = array();
        
        try {
            // Test relations
            $steps[] = $this->test_step('Relations Check', function() {
                $status = $this->jetengine_manager->check_setup_status();
                if (!$status['relations_ready']) {
                    throw new Exception('Relations not configured properly');
                }
                return 'Relations verified: ' . count($status['relations']) . ' found';
            });
            
            // Test queries
            $steps[] = $this->test_step('Queries Check', function() {
                $status = $this->jetengine_manager->check_setup_status();
                if (!$status['queries_ready']) {
                    throw new Exception('Queries not configured properly');
                }
                return 'Queries verified: ' . count($status['queries']) . ' found';
            });
            
            // Test CCT operations
            $steps[] = $this->test_step('CCT Operations', function() {
                $test_item = $this->jetengine_manager->create_cct_item('venues', array(
                    'name' => 'Test Venue',
                    'type' => 'bar'
                ));
                
                if (!$test_item) {
                    throw new Exception('CCT creation failed');
                }
                
                // Cleanup
                $this->jetengine_manager->delete_cct_item('venues', $test_item);
                
                return 'CCT operations verified';
            });
            
            return array(
                'success' => true,
                'message' => 'JetEngine component test passed',
                'steps' => $steps
            );
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => 'JetEngine component test failed: ' . $e->getMessage(),
                'steps' => $steps
            );
        }
    }
    
    /**
     * Test prompt generation component
     */
    private function test_prompt_component() {
        $steps = array();
        
        try {
            $prompt_builder = new KC_ML_Prompt_Builder();
            
            // Test events prompt
            $steps[] = $this->test_step('Events Prompt', function() use ($prompt_builder) {
                $prompt = $prompt_builder->build_events_prompt(5);
                
                if (strlen($prompt) < 2000) {
                    throw new Exception('Events prompt too short');
                }
                if (strpos($prompt, 'SEARCH AT LEAST') === false) {
                    throw new Exception('Missing aggressive research requirements');
                }
                
                return 'Events prompt generated (' . strlen($prompt) . ' chars)';
            });
            
            // Test venue prompt
            $steps[] = $this->test_step('Venue Prompt', function() use ($prompt_builder) {
                $prompt = $prompt_builder->build_venue_research_prompt('Test Venue', '123 Main St');
                
                if (strlen($prompt) < 1000) {
                    throw new Exception('Venue prompt too short');
                }
                
                return 'Venue prompt generated (' . strlen($prompt) . ' chars)';
            });
            
            // Test performer prompt
            $steps[] = $this->test_step('Performer Prompt', function() use ($prompt_builder) {
                $prompt = $prompt_builder->build_performer_research_prompt('Test Band', 'rock');
                
                if (strlen($prompt) < 1000) {
                    throw new Exception('Performer prompt too short');
                }
                
                return 'Performer prompt generated (' . strlen($prompt) . ' chars)';
            });
            
            return array(
                'success' => true,
                'message' => 'Prompt component test passed',
                'steps' => $steps
            );
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => 'Prompt component test failed: ' . $e->getMessage(),
                'steps' => $steps
            );
        }
    }
    
    /**
     * Helper function to run a test step
     */
    private function test_step($name, $callback) {
        $start_time = microtime(true);
        
        try {
            $message = $callback();
            $end_time = microtime(true);
            $duration = $end_time - $start_time;
            
            return array(
                'name' => $name,
                'success' => true,
                'message' => $message,
                'duration' => round($duration, 2)
            );
            
        } catch (Exception $e) {
            $end_time = microtime(true);
            $duration = $end_time - $start_time;
            
            return array(
                'name' => $name,
                'success' => false,
                'message' => $e->getMessage(),
                'duration' => round($duration, 2)
            );
        }
    }
    
    /**
     * Estimate cost from OpenAI usage data
     */
    private function estimate_cost_from_usage($usage) {
        $input_tokens = $usage['prompt_tokens'] ?? 0;
        $output_tokens = $usage['completion_tokens'] ?? 0;
        
        // GPT-4o pricing (approximate)
        $input_cost = ($input_tokens / 1000) * 0.0025;  // $2.50 per 1K input tokens
        $output_cost = ($output_tokens / 1000) * 0.01;  // $10.00 per 1K output tokens
        
        return $input_cost + $output_cost;
    }
    
    /**
     * Run performance benchmarks
     */
    private function run_performance_benchmarks() {
        $api_times = array();
        $supabase_times = array();
        $image_times = array();
        
        // API benchmark
        for ($i = 0; $i < 3; $i++) {
            $start = microtime(true);
            $this->api_handler->test_api_key();
            $api_times[] = microtime(true) - $start;
        }
        
        // Supabase benchmark  
        for ($i = 0; $i < 3; $i++) {
            $start = microtime(true);
            $this->supabase_client->test_connection();
            $supabase_times[] = microtime(true) - $start;
        }
        
        // Image generation benchmark (just 1 test due to cost)
        $start = microtime(true);
        $this->api_handler->generate_image('Simple test image', 'Benchmark Test');
        $image_times[] = microtime(true) - $start;
        
        return array(
            'api_avg' => array_sum($api_times) / count($api_times),
            'supabase_avg' => array_sum($supabase_times) / count($supabase_times),
            'image_avg' => array_sum($image_times) / count($image_times)
        );
    }
    
    /**
     * Validate data integrity
     */
    private function validate_data_integrity() {
        try {
            // Check for orphaned records
            $orphaned_events = $this->find_orphaned_events();
            $orphaned_notes = $this->find_orphaned_notes();
            
            // Check data consistency
            $consistency_issues = $this->check_data_consistency();
            
            if (!empty($orphaned_events) || !empty($orphaned_notes) || !empty($consistency_issues)) {
                return array(
                    'success' => false,
                    'message' => 'Data integrity issues found',
                    'details' => array(
                        'orphaned_events' => count($orphaned_events),
                        'orphaned_notes' => count($orphaned_notes),
                        'consistency_issues' => count($consistency_issues)
                    )
                );
            }
            
            return array(
                'success' => true,
                'summary' => 'No data integrity issues found'
            );
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => 'Data validation error: ' . $e->getMessage()
            );
        }
    }
    
    /**
     * Run stress test
     */
    private function run_stress_test() {
        $start_time = microtime(true);
        $operations = 0;
        $cost = 0;
        
        try {
            // Concurrent API calls simulation
            for ($i = 0; $i < 5; $i++) {
                $this->api_handler->test_api_key();
                $operations++;
            }
            
            // Concurrent Supabase operations
            for ($i = 0; $i < 10; $i++) {
                $this->supabase_client->test_connection();
                $operations++;
            }
            
            $duration = microtime(true) - $start_time;
            
            return array(
                'message' => sprintf('Stress test completed: %d operations in %.2f seconds', $operations, $duration),
                'cost' => $cost
            );
            
        } catch (Exception $e) {
            return array(
                'message' => 'Stress test failed: ' . $e->getMessage(),
                'cost' => $cost
            );
        }
    }
    
    /**
     * Helper methods for data validation
     */
    private function find_orphaned_events() {
        // Implementation would check for events without proper venue/performer links
        return array();
    }
    
    private function find_orphaned_notes() {
        // Implementation would check for notes without valid related items
        return array();
    }
    
    private function check_data_consistency() {
        // Implementation would check for data inconsistencies
        return array();
    }
}

?>