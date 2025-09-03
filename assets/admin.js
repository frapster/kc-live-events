/* KC Metro Live Admin JavaScript - assets/admin.js */
jQuery(document).ready(function($) {
    
    // Tab switching functionality
    $('.nav-tab').on('click', function(e) {
        e.preventDefault();
        
        // Remove active class from all tabs and content
        $('.nav-tab').removeClass('nav-tab-active');
        $('.tab-content').removeClass('active');
        
        // Add active class to clicked tab
        $(this).addClass('nav-tab-active');
        
        // Show corresponding content
        var targetTab = $(this).attr('href');
        $(targetTab).addClass('active');
    });
    
    // Form submission with loading states
    $('form').on('submit', function() {
        var $form = $(this);
        var $submitBtn = $form.find('input[type="submit"]');
        
        // Add loading state
        $submitBtn.prop('disabled', true);
        $submitBtn.val($submitBtn.val() + '...');
        
        // Add spinner if not already present
        if (!$submitBtn.siblings('.kc-ml-spinner').length) {
            $submitBtn.before('<span class="kc-ml-spinner"></span>');
        }
    });
    
    // AJAX test functions
    window.kcmlRunTest = function(testType) {
        var $button = $('#run-' + testType + '-test');
        var $results = $('#' + testType + '-results');
        
        // Show loading state
        $button.prop('disabled', true);
        $button.find('.kc-ml-spinner').remove();
        $button.prepend('<span class="kc-ml-spinner"></span>');
        
        // Clear previous results
        $results.empty();
        
        // Make AJAX request
        $.ajax({
            url: kcml_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'kc_ml_run_test',
                test_type: testType,
                nonce: kcml_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    $results.html('<div class="test-result success">' + response.data.message + '</div>');
                } else {
                    $results.html('<div class="test-result error">' + response.data.message + '</div>');
                }
            },
            error: function() {
                $results.html('<div class="test-result error">Test failed - please try again</div>');
            },
            complete: function() {
                // Remove loading state
                $button.prop('disabled', false);
                $button.find('.kc-ml-spinner').remove();
            }
        });
    };
    
    // Auto-refresh functionality for dashboard
    if ($('.kc-ml-dashboard').length) {
        setInterval(function() {
            // Refresh activity log every 30 seconds
            if ($('.kc-ml-activity-log').length) {
                $.ajax({
                    url: kcml_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'kc_ml_get_recent_activity',
                        nonce: kcml_ajax.nonce
                    },
                    success: function(response) {
                        if (response.success && response.data.html) {
                            $('.kc-ml-activity-log').html(response.data.html);
                        }
                    }
                });
            }
        }, 30000); // 30 seconds
    }
    
    // Budget monitoring alerts
    function checkBudgetStatus() {
        $.ajax({
            url: kcml_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'kc_ml_check_budget',
                nonce: kcml_ajax.nonce
            },
            success: function(response) {
                if (response.success && response.data.warning) {
                    showBudgetWarning(response.data);
                }
            }
        });
    }
    
    function showBudgetWarning(budgetData) {
        var warningHtml = '<div class="kc-ml-notification warning">';
        warningHtml += '<strong>Budget Warning:</strong> ';
        warningHtml += 'You have spent $' + budgetData.spent + ' of your $' + budgetData.limit + ' daily budget ';
        warningHtml += '(' + Math.round(budgetData.percentage) + '%).';
        warningHtml += '</div>';
        
        $('.wrap h1').after(warningHtml);
    }
    
    // Check budget status on page load for admin pages
    if ($('.kc-ml-dashboard, .kc-ml-control').length) {
        checkBudgetStatus();
    }
    
    // Confirmation dialogs for destructive actions
    $('input[value*="Delete"], input[value*="Reset"], input[value*="Clear"]').on('click', function(e) {
        var action = $(this).val();
        if (!confirm('Are you sure you want to ' + action.toLowerCase() + '? This action cannot be undone.')) {
            e.preventDefault();
        }
    });
    
    // Progress indicators for long-running operations
    $('input[value*="Full Test"], input[value*="Manual Run"]').on('click', function() {
        var $form = $(this).closest('form');
        
        // Show progress message
        $form.after('<div class="kc-ml-notification info">Operation started. This may take several minutes...</div>');
        
        // Add periodic progress checks (if implemented)
        var progressCheck = setInterval(function() {
            // This would check for progress updates from the server
            // Implementation depends on how you want to handle long operations
        }, 5000);
        
        // Clear interval when form submission completes
        $form.on('submit-complete', function() {
            clearInterval(progressCheck);
        });
    });
    
    // Dynamic form validation
    $('input[type="url"]').on('blur', function() {
        var url = $(this).val();
        if (url && !isValidUrl(url)) {
            $(this).addClass('error');
            $(this).after('<span class="error-message">Please enter a valid URL</span>');
        } else {
            $(this).removeClass('error');
            $(this).siblings('.error-message').remove();
        }
    });
    
    function isValidUrl(string) {
        try {
            new URL(string);
            return true;
        } catch (_) {
            return false;
        }
    }
    
    // Number input validation
    $('input[type="number"]').on('input', function() {
        var min = parseFloat($(this).attr('min'));
        var max = parseFloat($(this).attr('max'));
        var value = parseFloat($(this).val());
        
        if (!isNaN(min) && value < min) {
            $(this).val(min);
        }
        if (!isNaN(max) && value > max) {
            $(this).val(max);
        }
    });
    
    // Collapsible sections
    $('.kc-ml-collapsible-toggle').on('click', function() {
        var $target = $($(this).data('target'));
        $target.slideToggle();
        $(this).find('.dashicons').toggleClass('dashicons-arrow-down dashicons-arrow-up');
    });
    
    // Copy to clipboard functionality
    $('.kc-ml-copy-button').on('click', function() {
        var text = $(this).data('copy-text') || $(this).siblings('input, textarea').val();
        
        navigator.clipboard.writeText(text).then(function() {
            // Show success message
            var $button = $(this);
            var originalText = $button.text();
            $button.text('Copied!');
            setTimeout(function() {
                $button.text(originalText);
            }, 2000);
        });
    });
    
    // Keyboard shortcuts
    $(document).on('keydown', function(e) {
        // Ctrl/Cmd + R for refresh/reload data
        if ((e.ctrlKey || e.metaKey) && e.key === 'r') {
            if ($('.kc-ml-dashboard').length) {
                e.preventDefault();
                location.reload();
            }
        }
        
        // Ctrl/Cmd + T for test run
        if ((e.ctrlKey || e.metaKey) && e.key === 't') {
            if ($('.kc-ml-testing').length) {
                e.preventDefault();
                $('#run-integration-test').click();
            }
        }
    });
    
    // Initialize tooltips if available
    if (typeof $.fn.tooltip === 'function') {
        $('[data-tooltip]').tooltip({
            content: function() {
                return $(this).data('tooltip');
            }
        });
    }
    
    // Initialize on page load
    console.log('KC Metro Live admin scripts loaded');
});