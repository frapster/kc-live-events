<?php
/**
 * KC Metro Live - Budget Monitor Module
 * Handles cost tracking and budget management for API operations
 */

defined('ABSPATH') || exit;

class KC_ML_Budget_Monitor {
    
    private $supabase_client;
    private $daily_limit;
    private $operation_costs;
    
    public function __construct() {
        
        $this->daily_limit = get_option('kc_ml_daily_budget_limit', 10.00);
        
        // Define estimated costs for different operations
        $this->operation_costs = array(
            'api_call_basic' => 0.02,          // Basic API call (1K tokens)
            'api_call_research' => 0.15,       // Research call with search (5K tokens)
            'api_call_comprehensive' => 0.50,  // Comprehensive research (20K tokens)
            'image_generation' => 0.07,        // DALL-E 3 equivalent
            'live_search_source' => 0.025,     // Per source in Live Search
            'daily_batch_5_events' => 2.50,    // 5 event batch estimate
            'daily_batch_10_events' => 5.00,   // 10 event batch estimate
            'daily_batch_20_events' => 10.00,  // 20 event batch estimate
            'test_run_2_events' => 0.50,       // Small test run
            'monthly_update' => 1.00,          // Monthly refresh operation
        );
    }
    
    /**
     * Check if we can afford a specific operation
     */
    public function can_afford_operation($operation_type, $quantity = 1) {
        $estimated_cost = $this->estimate_operation_cost($operation_type, $quantity);
        $budget_status = $this->get_budget_status();
        
        $remaining_budget = $budget_status['remaining'];
        
        if ($estimated_cost > $remaining_budget) {
            error_log("KC ML Budget: Cannot afford operation '{$operation_type}' - Cost: $" . number_format($estimated_cost, 2) . ", Remaining: $" . number_format($remaining_budget, 2));
            return false;
        }
        
        return true;
    }
    
    /**
     * Estimate cost for an operation
     */
    public function estimate_operation_cost($operation_type, $quantity = 1) {
        $base_cost = $this->operation_costs[$operation_type] ?? 0.10; // Default fallback
        return $base_cost * $quantity;
    }
    
    /**
     * Get current budget status
     */
    public function get_budget_status() {
        $today = date('Y-m-d');
        $daily_limit = $this->daily_limit;
        
        // Get today's spending from Supabase if available
        $spent_today = 0;
        if ($this->supabase_client) {
            $today_record = $this->supabase_client->select('budget_tracking', array('date' => $today));
            if (!empty($today_record)) {
                $spent_today = floatval($today_record[0]['total_cost_usd']);
            }
        } else {
            // Fallback to WordPress options
            $spent_today = floatval(get_option('kc_ml_daily_spent_' . $today, 0));
        }
        
        $remaining = max(0, $daily_limit - $spent_today);
        $percentage_used = $daily_limit > 0 ? ($spent_today / $daily_limit) * 100 : 0;
        
        return array(
            'daily_limit' => $daily_limit,
            'spent_today' => $spent_today,
            'remaining' => $remaining,
            'percentage_used' => $percentage_used,
            'budget_exhausted' => $remaining <= 0,
            'budget_warning' => $percentage_used >= 80, // Warn at 80%
            'date' => $today
        );
    }
    
    /**
     * Record actual spending
     */
    public function record_spending($amount, $operation_type, $details = array()) {
        $today = date('Y-m-d');
        
        // Record in Supabase if available
        if ($this->supabase_client) {
            $this->record_spending_supabase($amount, $operation_type, $details);
        }
        
        // Also record in WordPress options as backup
        $current_spent = floatval(get_option('kc_ml_daily_spent_' . $today, 0));
        update_option('kc_ml_daily_spent_' . $today, $current_spent + $amount);
        
        // Record in running log
        $spending_log = get_option('kc_ml_spending_log', array());
        $spending_log[] = array(
            'timestamp' => time(),
            'date' => $today,
            'amount' => $amount,
            'operation_type' => $operation_type,
            'details' => $details
        );
        
        // Keep only last 100 entries
        if (count($spending_log) > 100) {
            $spending_log = array_slice($spending_log, -100);
        }
        
        update_option('kc_ml_spending_log', $spending_log);
        
        // Check if we've exceeded budget
        $budget_status = $this->get_budget_status();
        if ($budget_status['budget_exhausted']) {
            $this->handle_budget_exceeded($budget_status);
        } elseif ($budget_status['budget_warning']) {
            $this->handle_budget_warning($budget_status);
        }
        
        return true;
    }
    
    /**
     * Record spending in Supabase
     */
    private function record_spending_supabase($amount, $operation_type, $details) {
        if (!$this->supabase_client) {
            return false;
        }
        
        // Extract details for budget tracking
        $api_calls = $details['api_calls'] ?? 1;
        $tokens = $details['tokens'] ?? 0;
        $images = $details['images'] ?? 0;
        $events = $details['events'] ?? 0;
        $venues = $details['venues'] ?? 0;
        $performers = $details['performers'] ?? 0;
        
        return $this->supabase_client->track_daily_budget(
            $amount,
            $api_calls,
            $tokens,
            $images,
            $events,
            $venues,
            $performers
        );
    }
    
    /**
     * Handle budget exceeded
     */
    private function handle_budget_exceeded($budget_status) {
        // Log the event
        error_log('KC ML Budget: Daily budget exceeded! Spent: $' . number_format($budget_status['spent_today'], 2) . ', Limit: $' . number_format($budget_status['daily_limit'], 2));
        
        // Disable automatic agent if enabled
        if (get_option('kc_ml_enabled', 0)) {
            update_option('kc_ml_auto_disabled_budget', 1);
            update_option('kc_ml_enabled', 0);
            
            // Clear scheduled cron
            wp_clear_scheduled_hook('kc_ml_daily_run');
            
            error_log('KC ML Budget: Automatic agent disabled due to budget exceeded');
        }
        
        // Send notification if configured
        $this->send_budget_notification('exceeded', $budget_status);
    }
    
    /**
     * Handle budget warning
     */
    private function handle_budget_warning($budget_status) {
        // Only send warning once per day
        $warning_sent_today = get_option('kc_ml_budget_warning_sent_' . $budget_status['date'], 0);
        
        if (!$warning_sent_today) {
            error_log('KC ML Budget: Budget warning - ' . number_format($budget_status['percentage_used'], 1) . '% of daily budget used');
            
            update_option('kc_ml_budget_warning_sent_' . $budget_status['date'], 1);
            
            // Send notification if configured
            $this->send_budget_notification('warning', $budget_status);
        }
    }
    
    /**
     * Send budget notification
     */
    private function send_budget_notification($type, $budget_status) {
        $admin_email = get_option('admin_email');
        
        if (empty($admin_email)) {
            return false;
        }
        
        $site_name = get_bloginfo('name');
        
        if ($type === 'exceeded') {
            $subject = '[' . $site_name . '] KC Metro Live: Daily Budget Exceeded';
            $message = "The KC Metro Live plugin has exceeded its daily budget limit.\n\n";
            $message .= "Budget Limit: $" . number_format($budget_status['daily_limit'], 2) . "\n";
            $message .= "Amount Spent: $" . number_format($budget_status['spent_today'], 2) . "\n";
            $message .= "Date: " . $budget_status['date'] . "\n\n";
            $message .= "The automatic agent has been disabled to prevent further spending.\n";
            $message .= "You can re-enable it manually after reviewing the budget settings.\n\n";
            $message .= "Visit: " . admin_url('admin.php?page=kc-metro-live-settings') . "\n";
        } else {
            $subject = '[' . $site_name . '] KC Metro Live: Budget Warning';
            $message = "The KC Metro Live plugin has used " . number_format($budget_status['percentage_used'], 1) . "% of its daily budget.\n\n";
            $message .= "Budget Limit: $" . number_format($budget_status['daily_limit'], 2) . "\n";
            $message .= "Amount Spent: $" . number_format($budget_status['spent_today'], 2) . "\n";
            $message .= "Remaining: $" . number_format($budget_status['remaining'], 2) . "\n";
            $message .= "Date: " . $budget_status['date'] . "\n\n";
            $message .= "Monitor usage: " . admin_url('admin.php?page=kc-metro-live-analytics') . "\n";
        }
        
        wp_mail($admin_email, $subject, $message);
    }
    
    /**
     * Get spending history
     */
    public function get_spending_history($days = 30) {
        $history = array();
        
        if ($this->supabase_client) {
            // Get from Supabase
            $end_date = date('Y-m-d');
            $start_date = date('Y-m-d', strtotime("-{$days} days"));
            
            // This would use a more complex Supabase query
            $budget_records = $this->supabase_client->select('budget_tracking', array(), 
                'date,total_cost_usd,api_calls,tokens_used,images_generated,events_processed,venues_processed,performers_processed');
            
            foreach ($budget_records as $record) {
                if ($record['date'] >= $start_date && $record['date'] <= $end_date) {
                    $history[] = $record;
                }
            }
        } else {
            // Fallback to WordPress options
            for ($i = 0; $i < $days; $i++) {
                $date = date('Y-m-d', strtotime("-{$i} days"));
                $spent = floatval(get_option('kc_ml_daily_spent_' . $date, 0));
                
                if ($spent > 0) {
                    $history[] = array(
                        'date' => $date,
                        'total_cost_usd' => $spent,
                        'api_calls' => 0, // Not tracked in fallback
                        'tokens_used' => 0,
                        'images_generated' => 0,
                        'events_processed' => 0,
                        'venues_processed' => 0,
                        'performers_processed' => 0
                    );
                }
            }
        }
        
        // Sort by date descending
        usort($history, function($a, $b) {
            return strcmp($b['date'], $a['date']);
        });
        
        return $history;
    }
    
    /**
     * Get monthly spending summary
     */
    public function get_monthly_summary($month = null, $year = null) {
        if (!$month) $month = date('m');
        if (!$year) $year = date('Y');
        
        $start_date = sprintf('%04d-%02d-01', $year, $month);
        $end_date = date('Y-m-t', strtotime($start_date));
        
        $history = $this->get_spending_history(31);
        
        $monthly_total = 0;
        $daily_breakdown = array();
        
        foreach ($history as $record) {
            if ($record['date'] >= $start_date && $record['date'] <= $end_date) {
                $monthly_total += $record['total_cost_usd'];
                $daily_breakdown[$record['date']] = $record;
            }
        }
        
        return array(
            'month' => $month,
            'year' => $year,
            'total_cost' => $monthly_total,
            'daily_breakdown' => $daily_breakdown,
            'average_daily' => count($daily_breakdown) > 0 ? $monthly_total / count($daily_breakdown) : 0,
            'days_with_spending' => count($daily_breakdown),
            'projected_monthly' => $monthly_total * (31 / date('j'))
        );
    }
    
    /**
     * Set daily budget limit
     */
    public function set_daily_limit($amount) {
        $amount = floatval($amount);
        
        if ($amount < 0) {
            return false;
        }
        
        update_option('kc_ml_daily_budget_limit', $amount);
        $this->daily_limit = $amount;
        
        return true;
    }
    
    /**
     * Get cost optimization suggestions
     */
    public function get_optimization_suggestions() {
        $suggestions = array();
        $budget_status = $this->get_budget_status();
        $history = $this->get_spending_history(7);
        
        // Calculate average daily spending
        $total_spent = array_sum(array_column($history, 'total_cost_usd'));
        $avg_daily = count($history) > 0 ? $total_spent / count($history) : 0;
        
        // High spending suggestions
        if ($avg_daily > $this->daily_limit * 0.8) {
            $suggestions[] = array(
                'type' => 'warning',
                'title' => 'High Daily Spending',
                'message' => 'Average daily spending is ' . number_format(($avg_daily / $this->daily_limit) * 100, 1) . '% of budget limit.',
                'actions' => array(
                    'Reduce batch size for daily runs',
                    'Increase daily budget limit',
                    'Optimize research prompts for efficiency'
                )
            );
        }
        
        // Budget exceeded frequently
        $exceeded_days = 0;
        foreach ($history as $record) {
            if ($record['total_cost_usd'] > $this->daily_limit) {
                $exceeded_days++;
            }
        }
        
        if ($exceeded_days >= 3) {
            $suggestions[] = array(
                'type' => 'error',
                'title' => 'Frequent Budget Overruns',
                'message' => 'Budget exceeded on ' . $exceeded_days . ' of the last ' . count($history) . ' days.',
                'actions' => array(
                    'Increase daily budget limit',
                    'Review and optimize API usage',
                    'Consider running agent less frequently'
                )
            );
        }
        
        // Underutilized budget
        if ($avg_daily < $this->daily_limit * 0.3 && $avg_daily > 0) {
            $suggestions[] = array(
                'type' => 'info',
                'title' => 'Budget Underutilized',
                'message' => 'Only using ' . number_format(($avg_daily / $this->daily_limit) * 100, 1) . '% of daily budget.',
                'actions' => array(
                    'Increase batch size for more events',
                    'Run additional research operations',
                    'Reduce daily budget limit to optimize costs'
                )
            );
        }
        
        // No recent activity
        if (empty($history) || $total_spent == 0) {
            $suggestions[] = array(
                'type' => 'info',
                'title' => 'No Recent Activity',
                'message' => 'No API spending recorded in the last 7 days.',
                'actions' => array(
                    'Check if automatic agent is enabled',
                    'Verify API key configuration',
                    'Run a manual test to ensure system is working'
                )
            );
        }
        
        return $suggestions;
    }
    
    /**
     * Reset daily spending (for testing or corrections)
     */
    public function reset_daily_spending($date = null) {
        if (!$date) {
            $date = date('Y-m-d');
        }
        
        // Reset WordPress option
        delete_option('kc_ml_daily_spent_' . $date);
        delete_option('kc_ml_budget_warning_sent_' . $date);
        
        // Reset in Supabase if available
        if ($this->supabase_client) {
            $this->supabase_client->delete('budget_tracking', array('date' => $date));
        }
        
        return true;
    }
    
    /**
     * Generate budget report
     */
    public function generate_budget_report($period = 'month') {
        $report = array(
            'period' => $period,
            'generated_at' => date('Y-m-d H:i:s'),
            'budget_limit' => $this->daily_limit
        );
        
        switch ($period) {
            case 'week':
                $history = $this->get_spending_history(7);
                $report['title'] = 'Weekly Budget Report';
                break;
                
            case 'month':
                $history = $this->get_spending_history(30);
                $report['title'] = 'Monthly Budget Report';
                break;
                
            case 'quarter':
                $history = $this->get_spending_history(90);
                $report['title'] = 'Quarterly Budget Report';
                break;
                
            default:
                $history = $this->get_spending_history(30);
                $report['title'] = 'Budget Report';
        }
        
        // Calculate totals
        $total_spent = array_sum(array_column($history, 'total_cost_usd'));
        $total_api_calls = array_sum(array_column($history, 'api_calls'));
        $total_tokens = array_sum(array_column($history, 'tokens_used'));
        $total_images = array_sum(array_column($history, 'images_generated'));
        $total_events = array_sum(array_column($history, 'events_processed'));
        
        $report['summary'] = array(
            'total_spent' => $total_spent,
            'total_api_calls' => $total_api_calls,
            'total_tokens' => $total_tokens,
            'total_images' => $total_images,
            'total_events' => $total_events,
            'average_daily' => count($history) > 0 ? $total_spent / count($history) : 0,
            'days_with_activity' => count(array_filter($history, function($h) { return $h['total_cost_usd'] > 0; })),
            'cost_per_event' => $total_events > 0 ? $total_spent / $total_events : 0
        );
        
        $report['daily_breakdown'] = $history;
        $report['optimization_suggestions'] = $this->get_optimization_suggestions();
        
        return $report;
    }
    
    /**
     * Export budget report
     */
    public function export_budget_report($period = 'month', $format = 'json') {
        $report = $this->generate_budget_report($period);
        
        switch ($format) {
            case 'csv':
                return $this->export_report_csv($report);
            case 'html':
                return $this->export_report_html($report);
            case 'json':
            default:
                return json_encode($report, JSON_PRETTY_PRINT);
        }
    }
    
    /**
     * Export report as CSV
     */
    private function export_report_csv($report) {
        $csv = "KC Metro Live Budget Report - " . $report['title'] . "\n";
        $csv .= "Generated: " . $report['generated_at'] . "\n\n";
        
        $csv .= "Summary\n";
        $csv .= "Total Spent,$" . number_format($report['summary']['total_spent'], 2) . "\n";
        $csv .= "API Calls," . $report['summary']['total_api_calls'] . "\n";
        $csv .= "Events Processed," . $report['summary']['total_events'] . "\n";
        $csv .= "Average Daily,$" . number_format($report['summary']['average_daily'], 2) . "\n\n";
        
        $csv .= "Daily Breakdown\n";
        $csv .= "Date,Cost,API Calls,Events,Venues,Performers\n";
        
        foreach ($report['daily_breakdown'] as $day) {
            $csv .= $day['date'] . ",";
            $csv .= number_format($day['total_cost_usd'], 2) . ",";
            $csv .= $day['api_calls'] . ",";
            $csv .= $day['events_processed'] . ",";
            $csv .= $day['venues_processed'] . ",";
            $csv .= $day['performers_processed'] . "\n";
        }
        
        return $csv;
    }
    
    /**
     * Export report as HTML
     */
    private function export_report_html($report) {
        $html = '<h1>' . esc_html($report['title']) . '</h1>';
        $html .= '<p>Generated: ' . esc_html($report['generated_at']) . '</p>';
        
        $html .= '<h2>Summary</h2>';
        $html .= '<ul>';
        $html .= '<li>Total Spent: $' . number_format($report['summary']['total_spent'], 2) . '</li>';
        $html .= '<li>API Calls: ' . number_format($report['summary']['total_api_calls']) . '</li>';
        $html .= '<li>Events Processed: ' . number_format($report['summary']['total_events']) . '</li>';
        $html .= '<li>Average Daily: $' . number_format($report['summary']['average_daily'], 2) . '</li>';
        $html .= '</ul>';
        
        $html .= '<h2>Daily Breakdown</h2>';
        $html .= '<table border="1" style="border-collapse: collapse;">';
        $html .= '<tr><th>Date</th><th>Cost</th><th>API Calls</th><th>Events</th></tr>';
        
        foreach ($report['daily_breakdown'] as $day) {
            $html .= '<tr>';
            $html .= '<td>' . esc_html($day['date']) . '</td>';
            $html .= '<td>$' . number_format($day['total_cost_usd'], 2) . '</td>';
            $html .= '<td>' . number_format($day['api_calls']) . '</td>';
            $html .= '<td>' . number_format($day['events_processed']) . '</td>';
            $html .= '</tr>';
        }
        
        $html .= '</table>';
        
        return $html;
    }
}
?>