<?php /*
Plugin Name: KC Metro Live
Description: AI Agent for live events in Kansas City
Version: 1.0
Author: Rob Floyd
*/ 

defined('ABSPATH')||exit; 

// Include ALL required files from includes folder
require_once plugin_dir_path(__FILE__) . 'includes/class-api-handler.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-batch-processor.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-budget-monitor.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-bunny-client.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-jetengine-manager.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-prompt-builder.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-supabase-client.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-test-manager.php';

// Enqueue admin styles and scripts
add_action('admin_enqueue_scripts', 'kc_ml_admin_scripts');
function kc_ml_admin_scripts($hook) {
    if (strpos($hook, 'kc-ml') !== false) {
        wp_enqueue_style('kc-ml-admin', plugin_dir_url(__FILE__) . 'includes/admin.css', array(), '1.0');
        wp_enqueue_script('kc-ml-admin', plugin_dir_url(__FILE__) . 'includes/admin.js', array('jquery'), '1.0', true);
        wp_localize_script('kc-ml-admin', 'kcml_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('kc_ml_nonce')
        ));
    }
}

function kc_ml_settings_page(){
    $s=get_option('kc_ml_api_key','');
    $v=get_option('kc_ml_api_key_valid',0);
    $e=get_option('kc_ml_enabled',0);
    
    // Supabase settings
    $supabase_url = get_option('kc_ml_supabase_url', '');
    $supabase_anon_key = get_option('kc_ml_supabase_anon_key', '');
    $supabase_service_key = get_option('kc_ml_supabase_service_key', '');
    $supabase_valid = get_option('kc_ml_supabase_valid', false);
    
    // Bunny.net settings
    $bunny_zone = get_option('kc_ml_bunny_zone', '');
    $bunny_key = get_option('kc_ml_bunny_key', '');
    $bunny_valid = get_option('kc_ml_bunny_valid', false);
    
    echo '<div class="wrap kc-ml-dashboard"><h1>KC Metro Live Settings</h1>';
    
    // Handle xAI API key save
    if(isset($_POST['save_kc_ml_api'])){
        $k=sanitize_text_field($_POST['kc_ml_api_key']);
        
        // Initialize API handler for testing
        $api_handler = new KC_ML_API_Handler();
        $test_result = $api_handler->test_api_key($k);
        
        if($test_result['success']){
            update_option('kc_ml_api_key',$k);
            update_option('kc_ml_api_key_valid',1);
            echo '<div class="notice notice-success"><p>API key valid and saved!</p></div>';
            $s=$k;$v=1;
        }else{
            echo '<div class="notice notice-error"><p>Error testing API key: '.esc_html($test_result['message']).'</p></div>';
        }
    }
    
    // Handle Supabase save
    if(isset($_POST['save_supabase'])){
        $new_url = sanitize_text_field($_POST['supabase_url']);
        $new_anon_key = sanitize_text_field($_POST['supabase_anon_key']);
        $new_service_key = sanitize_text_field($_POST['supabase_service_key']);
        
        $supabase_client = new KC_ML_Supabase_Client();
        $supabase_client->update_credentials($new_url, $new_anon_key, $new_service_key);
        $test_result = $supabase_client->test_connection();
        
        if($test_result){
            update_option('kc_ml_supabase_valid', true);
            echo '<div class="notice notice-success"><p>Supabase connection successful!</p></div>';
            $supabase_url=$new_url;$supabase_anon_key=$new_anon_key;$supabase_service_key=$new_service_key;$supabase_valid=true;
        }else{
            echo '<div class="notice notice-error"><p>Supabase connection failed!</p></div>';
        }
    }
    
    // Handle Bunny.net save
    if(isset($_POST['save_bunny'])){
        $new_zone = sanitize_text_field($_POST['bunny_zone']);
        $new_key = sanitize_text_field($_POST['bunny_key']);
        
        update_option('kc_ml_bunny_zone', $new_zone);
        update_option('kc_ml_bunny_key', $new_key);
        
        $bunny_client = new KC_ML_Bunny_Client();
        $test_result = $bunny_client->test_connection();
        
        if($test_result){
            update_option('kc_ml_bunny_valid', true);
            echo '<div class="notice notice-success"><p>Bunny.net connection successful!</p></div>';
            $bunny_zone=$new_zone;$bunny_key=$new_key;$bunny_valid=true;
        }else{
            echo '<div class="notice notice-error"><p>Bunny.net connection failed!</p></div>';
        }
    }
    
    // Mask keys for display
    $masked_key = $s ? substr($s,0,4).str_repeat('.',12).substr($s,-4) : '';
    $masked_anon = $supabase_anon_key ? substr($supabase_anon_key,0,8).str_repeat('.',20).substr($supabase_anon_key,-8) : '';
    $masked_service = $supabase_service_key ? substr($supabase_service_key,0,8).str_repeat('.',20).substr($supabase_service_key,-8) : '';
    $masked_bunny = $bunny_key ? substr($bunny_key,0,8).str_repeat('.',20).substr($bunny_key,-8) : '';
    
    ?>
    <div class="kc-ml-settings-tabs">
        <h2 class="nav-tab-wrapper">
            <a href="#api-settings" class="nav-tab nav-tab-active">API Settings</a>
            <a href="#database-settings" class="nav-tab">Database</a>
            <a href="#cdn-settings" class="nav-tab">CDN Storage</a>
            <a href="#system-status" class="nav-tab">System Status</a>
        </h2>
        
        <!-- xAI API Settings Tab -->
        <div id="api-settings" class="tab-content active">
            <div class="kc-ml-card">
                <h2>xAI API Configuration</h2>
                <form method="post">
                    <p><label>xAI API Key: <input type="text" name="kc_ml_api_key" value="<?php echo esc_attr($masked_key); ?>" style="width: 400px;"></label></p>
                    <p class="description">Get your API key from <a href="https://console.x.ai" target="_blank">https://console.x.ai</a></p>
                    <p><input type="submit" name="save_kc_ml_api" value="Save and Test API Key" class="button button-primary"></p>
                    <p>Status: <span class="status-indicator <?php echo $v ? 'valid' : 'invalid'; ?>"><?php echo $v ? '✅ Valid' : '❌ Not Valid'; ?></span></p>
                </form>
            </div>
        </div>
        
        <!-- Supabase Database Tab -->
        <div id="database-settings" class="tab-content">
            <div class="kc-ml-card">
                <h2>Supabase Database Configuration</h2>
                <form method="post">
                    <p><label>Project URL: <input type="url" name="supabase_url" value="<?php echo esc_attr($supabase_url); ?>" style="width: 400px;" placeholder="https://yourproject.supabase.co"></label></p>
                    <p><label>Anon/Public Key: <input type="text" name="supabase_anon_key" value="<?php echo esc_attr($masked_anon); ?>" style="width: 400px;" placeholder="eyJ..."></label></p>
                    <p><label>Service Role Key: <input type="text" name="supabase_service_key" value="<?php echo esc_attr($masked_service); ?>" style="width: 400px;" placeholder="eyJ..."></label></p>
                    <p class="description">Get these from your Supabase project Settings > API</p>
                    <p><input type="submit" name="save_supabase" value="Save and Test Supabase" class="button button-primary"></p>
                    <p>Status: <span class="status-indicator <?php echo $supabase_valid ? 'valid' : 'invalid'; ?>"><?php echo $supabase_valid ? '✅ Connected' : '❌ Not Connected'; ?></span></p>
                </form>
            </div>
        </div>
        
        <!-- Bunny.net CDN Tab -->
        <div id="cdn-settings" class="tab-content">
            <div class="kc-ml-card">
                <h2>Bunny.net CDN Configuration</h2>
                <form method="post">
                    <p><label>Storage Zone Name: <input type="text" name="bunny_zone" value="<?php echo esc_attr($bunny_zone); ?>" style="width: 300px;" placeholder="your-storage-zone"></label></p>
                    <p><label>Storage Password: <input type="text" name="bunny_key" value="<?php echo esc_attr($masked_bunny); ?>" style="width: 400px;" placeholder="storage-password"></label></p>
                    <p class="description">Get these from your Bunny.net Storage Zone settings</p>
                    <p><input type="submit" name="save_bunny" value="Save and Test Bunny.net" class="button button-primary"></p>
                    <p>Status: <span class="status-indicator <?php echo $bunny_valid ? 'valid' : 'invalid'; ?>"><?php echo $bunny_valid ? '✅ Connected' : '❌ Not Connected'; ?></span></p>
                </form>
            </div>
        </div>
        
        <!-- System Status Tab -->
        <div id="system-status" class="tab-content">
            <div class="kc-ml-card">
                <h2>System Status</h2>
                <?php
                // Check JetEngine status
                $jetengine_manager = new KC_ML_JetEngine_Manager();
                $jetengine_status = $jetengine_manager->check_setup_status();
                ?>
                <h3>JetEngine Status</h3>
                <p>JetEngine Active: <span class="status-indicator <?php echo $jetengine_status['jetengine_active'] ? 'valid' : 'invalid'; ?>"><?php echo $jetengine_status['jetengine_active'] ? '✅ Active' : '❌ Not Active'; ?></span></p>
                <p>CCT Types Ready: <span class="status-indicator <?php echo count(array_filter($jetengine_status['cct_types'], function($t) { return $t['exists']; })) >= 4 ? 'valid' : 'invalid'; ?>"><?php echo count(array_filter($jetengine_status['cct_types'], function($t) { return $t['exists']; })); ?>/4 Found</span></p>
                <p>Relations Ready: <span class="status-indicator <?php echo $jetengine_status['relations_ready'] ? 'valid' : 'invalid'; ?>"><?php echo $jetengine_status['relations_ready'] ? '✅ Ready' : '❌ Not Ready'; ?></span></p>
                
                <h3>API Connections</h3>
                <p>xAI API: <span class="status-indicator <?php echo $v ? 'valid' : 'invalid'; ?>"><?php echo $v ? '✅ Connected' : '❌ Not Connected'; ?></span></p>
                <p>Supabase: <span class="status-indicator <?php echo $supabase_valid ? 'valid' : 'invalid'; ?>"><?php echo $supabase_valid ? '✅ Connected' : '❌ Not Connected'; ?></span></p>
                <p>Bunny.net: <span class="status-indicator <?php echo $bunny_valid ? 'valid' : 'invalid'; ?>"><?php echo $bunny_valid ? '✅ Connected' : '❌ Not Connected'; ?></span></p>
            </div>
        </div>
    </div>
    
    <div class="kc-ml-setup-guide">
        <h2>Setup Instructions</h2>
        <ol>
            <li>Get xAI API key from <a href="https://console.x.ai" target="_blank">console.x.ai</a> and configure above</li>
            <li>Create Supabase project and configure database connection</li>
            <li>Set up Bunny.net storage zone for images (optional)</li>
            <li>Set up JetEngine CCTs: venues, performers, events, notes</li>
            <li>Create sentiment_words taxonomy in JetEngine</li>
            <li>Use the Control tab to test and run the agent</li>
        </ol>
    </div>
    
    </div>
    <?php
}

function kc_ml_control_page(){
    $e=get_option('kc_ml_enabled',0);
    $l=kc_ml_get_log_data();
    
    // Handle test runs
    if(isset($_POST['run_kc_ml'])){
        $n=intval($_POST['kc_ml_limit']);
        if($n<1)$n=1;
        $r=kc_ml_run_agent($n);
        echo '<div class="notice notice-success"><p>'.wp_kses_post($r).'</p></div>';
    }
    
    // Handle component tests
    if(isset($_POST['test_component'])){
        $component = sanitize_text_field($_POST['component_type']);
        $test_result = kc_ml_run_component_test($component);
        echo '<div class="notice '.($test_result['success'] ? 'notice-success' : 'notice-error').'"><p>'.wp_kses_post($test_result['message']).'</p></div>';
    }
    
    if(isset($_POST['save_kc_ml_toggle'])){
        $e=isset($_POST['kc_ml_enabled'])?1:0;
        update_option('kc_ml_enabled',$e);
        kc_ml_cron_setup();
        echo '<div class="notice notice-success"><p>Agent toggle saved!</p></div>';
    }
    
    echo '<div class="wrap kc-ml-dashboard"><h1>KC Metro Live Control</h1>';
    
    // Status cards
    echo '<div class="kc-ml-status-cards">';
    echo '<div class="kc-ml-card">';
    echo '<h3>Agent Status</h3>';
    echo '<div class="status '.($e ? 'enabled' : 'disabled').'">'.($e ? 'ENABLED' : 'DISABLED').'</div>';
    echo '</div>';
    
    echo '<div class="kc-ml-card">';
    echo '<h3>Last Run</h3>';
    echo '<div class="last-run">'.(empty($l['last_run']) ? 'Never' : date('M j, Y g:i A', $l['last_run'])).'</div>';
    echo '</div>';
    
    echo '<div class="kc-ml-card">';
    echo '<h3>Budget Status</h3>';
    $budget_monitor = new KC_ML_Budget_Monitor();
    $budget_status = $budget_monitor->get_budget_status();
    echo '<div class="budget">$'.number_format($budget_status['spent_today'], 2).' / $'.number_format($budget_status['daily_limit'], 2).'</div>';
    echo '</div>';
    echo '</div>';
    
    // Test Run Section
    echo '<div class="kc-ml-card">';
    echo '<h2>Test Run</h2>';
    echo '<form method="post"><p><label>Number of events: <input type="number" name="kc_ml_limit" value="2" min="1" max="10"></label></p>';
    echo '<p><input type="submit" name="run_kc_ml" value="Run Full Test" class="button button-primary"></p></form>';
    echo '</div>';
    
    // Component Testing Section
    echo '<div class="kc-ml-testing">';
    echo '<div class="kc-ml-card">';
    echo '<h2>Component Tests</h2>';
    echo '<form method="post">';
    echo '<p><select name="component_type">';
    echo '<option value="api">API Handler</option>';
    echo '<option value="supabase">Supabase Client</option>';
    echo '<option value="bunny">Bunny.net CDN</option>';
    echo '<option value="jetengine">JetEngine Manager</option>';
    echo '<option value="prompts">Prompt Builder</option>';
    echo '</select></p>';
    echo '<p><input type="submit" name="test_component" value="Run Component Test" class="button button-secondary"></p>';
    echo '</form>';
    echo '</div>';
    echo '</div>';
    
    // Agent Control Section
    echo '<div class="kc-ml-card">';
    echo '<h2>Agent Control</h2>';
    echo '<form method="post"><p><label>Enable Daily Agent: <input type="checkbox" name="kc_ml_enabled" '.checked(1,$e,false).'></label></p>';
    echo '<p><input type="submit" name="save_kc_ml_toggle" value="Save Toggle" class="button button-secondary"></p></form>';
    echo '</div>';
    
    // Statistics Section
    echo '<div class="kc-ml-card">';
    echo '<h2>Run Statistics</h2>';
    echo '<p>Last 24 hours: <strong>'.$l['24h'].' runs</strong></p>';
    echo '<p>Last 7 days: <strong>'.$l['7d'].' runs</strong></p>';
    echo '<p>Last 30 days: <strong>'.$l['30d'].' runs</strong></p>';
    echo '<p>Last 90 days: <strong>'.$l['90d'].' runs</strong></p>';
    echo '</div>';
    
    echo '</div>';
}

function kc_ml_get_log_data(){
    $l=get_option('kc_ml_run_logs',[]);
    $t=time();
    $c24=0;$c7=0;$c30=0;$c90=0;$last_run=0;
    
    foreach($l as $r){
        if($r['time'] > $last_run) $last_run = $r['time'];
        if($r['success']&&$t-$r['time']<=86400)$c24++;
        if($r['success']&&$t-$r['time']<=604800)$c7++;
        if($r['success']&&$t-$r['time']<=2592000)$c30++;
        if($r['success']&&$t-$r['time']<=7776000)$c90++;
    }
    return ['24h'=>$c24,'7d'=>$c7,'30d'=>$c30,'90d'=>$c90,'last_run'=>$last_run];
}

function kc_ml_run_agent($limit) {
    // Initialize all components
    $supabase_client = new KC_ML_Supabase_Client();
    $api_handler = new KC_ML_API_Handler();
    $bunny_client = new KC_ML_Bunny_Client();
    $jetengine_manager = new KC_ML_JetEngine_Manager();
    $batch_processor = new KC_ML_Batch_Processor($supabase_client);
    
    // Check budget before running
    $budget_monitor = new KC_ML_Budget_Monitor($supabase_client);
    if (!$budget_monitor->can_afford_operation('daily_batch_' . $limit . '_events')) {
        return 'Daily budget exceeded. Agent run cancelled.';
    }
    
    // Run the full agent
    $result = $batch_processor->run_full_agent($limit);
    
    // Log the run
    $logs = get_option('kc_ml_run_logs', []);
    $logs[] = ['time' => time(), 'success' => $result['success'], 'message' => $result['message']];
    if (count($logs) > 100) $logs = array_slice($logs, -100);
    update_option('kc_ml_run_logs', $logs);
    
    return $result['message'];
}

function kc_ml_run_component_test($component) {
    try {
        $supabase_client = new KC_ML_Supabase_Client();
        $api_handler = new KC_ML_API_Handler();
        $bunny_client = new KC_ML_Bunny_Client();
        $jetengine_manager = new KC_ML_JetEngine_Manager();
        $batch_processor = new KC_ML_Batch_Processor($supabase_client);
        
        $test_manager = new KC_ML_Test_Manager(
            $api_handler,
            $supabase_client,
            $bunny_client,
            $jetengine_manager,
            $batch_processor
        );
        
        return $test_manager->run_component_test($component);
        
    } catch (Exception $e) {
        return array(
            'success' => false,
            'message' => 'Test failed: ' . $e->getMessage()
        );
    }
}

function kc_ml_cron_setup(){
    if(get_option('kc_ml_enabled',0)){
        if(!wp_next_scheduled('kc_ml_daily_run')){
            wp_schedule_event(time(),'daily','kc_ml_daily_run');
        }
    }else{
        wp_clear_scheduled_hook('kc_ml_daily_run');
    }
}

add_action('init','kc_ml_cron_setup');
add_action('kc_ml_daily_run','kc_ml_daily_run_function');

function kc_ml_daily_run_function(){
    if(get_option('kc_ml_enabled',0)){
        kc_ml_run_agent(10);
    }
}

add_action('admin_menu','kc_ml_add_admin_menu');

function kc_ml_add_admin_menu(){
    add_menu_page('KC Metro Live Settings','KC Metro Live','manage_options','kc-ml-settings','kc_ml_settings_page','dashicons-admin-tools',100);
    add_submenu_page('kc-ml-settings','Settings','Settings','manage_options','kc-ml-settings','kc_ml_settings_page');
    add_submenu_page('kc-ml-settings','Control','Control','manage_options','kc-ml-control','kc_ml_control_page');
}

// Custom hooks for linear batch processing
add_action('kc_events_batch_complete', function() {
    $supabase_client = new KC_ML_Supabase_Client();
    $batch_processor = new KC_ML_Batch_Processor($supabase_client);
    $batch_processor->process_venues_batch();
});

add_action('kc_venues_batch_complete', function() {
    $supabase_client = new KC_ML_Supabase_Client();
    $batch_processor = new KC_ML_Batch_Processor($supabase_client);
    $batch_processor->process_performers_batch();
});

add_action('kc_performers_batch_complete', function() {
    error_log('KC Metro Live: Full batch processing completed');
});

// AJAX handlers for admin interface
add_action('wp_ajax_kc_ml_run_test', 'kc_ml_ajax_run_test');
function kc_ml_ajax_run_test() {
    check_ajax_referer('kc_ml_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    
    $test_type = sanitize_text_field($_POST['test_type']);
    $result = kc_ml_run_component_test($test_type);
    
    wp_send_json($result);
}

add_action('wp_ajax_kc_ml_check_budget', 'kc_ml_ajax_check_budget');
function kc_ml_ajax_check_budget() {
    check_ajax_referer('kc_ml_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    
    $budget_monitor = new KC_ML_Budget_Monitor();
    $budget_status = $budget_monitor->get_budget_status();
    
    wp_send_json(array(
        'success' => true,
        'data' => array(
            'warning' => $budget_status['budget_warning'],
            'spent' => number_format($budget_status['spent_today'], 2),
            'limit' => number_format($budget_status['daily_limit'], 2),
            'percentage' => round($budget_status['percentage_used'])
        )
    ));
}