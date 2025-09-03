<?php
/**
 * Uninstall KC Metro Live Plugin
 * Cleans up all plugin data when uninstalled
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Remove all plugin options
delete_option('kc_ml_api_key');
delete_option('kc_ml_api_key_valid');
delete_option('kc_ml_enabled');
delete_option('kc_ml_supabase_url');
delete_option('kc_ml_supabase_anon_key');
delete_option('kc_ml_supabase_service_key');
delete_option('kc_ml_supabase_valid');
delete_option('kc_ml_bunny_zone');
delete_option('kc_ml_bunny_key');
delete_option('kc_ml_daily_budget_limit');
delete_option('kc_ml_run_logs');
delete_option('kc_ml_error_logs');
delete_option('kc_ml_temp_batch_data');
delete_option('kc_ml_total_api_requests');
delete_option('kc_ml_total_tokens');
delete_option('kc_ml_total_api_cost');
delete_option('kc_ml_total_images');
delete_option('kc_ml_error_count_today');
delete_option('kc_ml_last_error_date');
delete_option('kc_ml_last_run_time');

// Remove daily spending options (last 30 days)
for ($i = 0; $i < 30; $i++) {
    $date = date('Y-m-d', strtotime("-{$i} days"));
    delete_option('kc_ml_daily_spent_' . $date);
    delete_option('kc_ml_budget_warning_sent_' . $date);
}

// Remove transients
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_kc_ml_%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_kc_ml_%'");

// Clear scheduled events
wp_clear_scheduled_hook('kc_ml_daily_run');
wp_clear_scheduled_hook('kc_ml_weekly_maintenance');

// Remove upload directory (optional - uncomment if you want to remove uploaded files)
$upload_dir = wp_upload_dir();
$kc_dir = $upload_dir['basedir'] . '/kc-metro-live';
if (file_exists($kc_dir)) {
    // Uncomment the next lines if you want to remove uploaded files on uninstall
    // $files = glob($kc_dir . '/*');
    // foreach($files as $file) {
    //     if(is_file($file)) unlink($file);
    // }
    // rmdir($kc_dir);
}

// Note: We don't delete the JetEngine CCTs (events, venues, performers, notes)
// or the sentiment_words taxonomy as these might contain user data
// Users should manually remove these if desired
?>