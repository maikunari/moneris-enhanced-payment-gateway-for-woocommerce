/**
 * Moneris Enhanced Gateway Admin JavaScript
 *
 * @package MonerisEnhancedGateway
 */

(function($) {
    'use strict';

    /**
     * Moneris Admin Handler
     */
    var MonerisAdmin = {
        /**
         * Initialize
         */
        init: function() {
            this.bindEvents();
            this.toggleFields();
        },

        /**
         * Bind events
         */
        bindEvents: function() {
            var self = this;

            // Test mode toggle
            $('#woocommerce_moneris_enhanced_gateway_testmode').on('change', function() {
                self.toggleFields();
            });

            // Test credentials button
            $(document).on('click', '#moneris-test-credentials', function(e) {
                e.preventDefault();
                self.testCredentials();
            });

            // Clear logs button
            $(document).on('click', '#moneris-clear-logs', function(e) {
                e.preventDefault();
                self.clearLogs();
            });

            // Copy webhook URL
            $(document).on('click', '#moneris-copy-webhook', function(e) {
                e.preventDefault();
                self.copyWebhookUrl();
            });
        },

        /**
         * Toggle fields based on test mode
         */
        toggleFields: function() {
            var isTestMode = $('#woocommerce_moneris_enhanced_gateway_testmode').is(':checked');
            
            if (isTestMode) {
                // Show test fields, hide production
                $('.moneris-test-field').closest('tr').show();
                $('.moneris-production-field').closest('tr').hide();
            } else {
                // Show production fields, hide test
                $('.moneris-test-field').closest('tr').hide();
                $('.moneris-production-field').closest('tr').show();
            }
        },

        /**
         * Test API credentials
         */
        testCredentials: function() {
            var self = this;
            var button = $('#moneris-test-credentials');
            var originalText = button.text();
            
            // Get credentials based on mode
            var isTestMode = $('#woocommerce_moneris_enhanced_gateway_testmode').is(':checked');
            var storeId, apiToken;
            
            if (isTestMode) {
                storeId = $('#woocommerce_moneris_enhanced_gateway_test_store_id').val();
                apiToken = $('#woocommerce_moneris_enhanced_gateway_test_api_token').val();
            } else {
                storeId = $('#woocommerce_moneris_enhanced_gateway_store_id').val();
                apiToken = $('#woocommerce_moneris_enhanced_gateway_api_token').val();
            }

            if (!storeId || !apiToken) {
                this.showNotice('Please enter both Store ID and API Token', 'error');
                return;
            }

            // Disable button and show loading
            button.prop('disabled', true).html('<span class="moneris-spinner"></span> Testing...');

            $.ajax({
                type: 'POST',
                url: moneris_admin_params.ajax_url,
                data: {
                    action: 'moneris_test_credentials',
                    nonce: moneris_admin_params.nonce,
                    store_id: storeId,
                    api_token: apiToken,
                    test_mode: isTestMode
                },
                success: function(response) {
                    if (response.success) {
                        self.showNotice('Credentials validated successfully!', 'success');
                    } else {
                        self.showNotice(response.data.message || 'Invalid credentials', 'error');
                    }
                },
                error: function() {
                    self.showNotice('An error occurred while testing credentials', 'error');
                },
                complete: function() {
                    button.prop('disabled', false).text(originalText);
                }
            });
        },

        /**
         * Clear transaction logs
         */
        clearLogs: function() {
            if (!confirm('Are you sure you want to clear all transaction logs?')) {
                return;
            }

            var self = this;
            var button = $('#moneris-clear-logs');
            var originalText = button.text();

            button.prop('disabled', true).text('Clearing...');

            $.ajax({
                type: 'POST',
                url: moneris_admin_params.ajax_url,
                data: {
                    action: 'moneris_clear_logs',
                    nonce: moneris_admin_params.nonce
                },
                success: function(response) {
                    if (response.success) {
                        self.showNotice('Logs cleared successfully', 'success');
                        // Reload logs table if present
                        if ($('#moneris-logs-table').length) {
                            location.reload();
                        }
                    } else {
                        self.showNotice('Failed to clear logs', 'error');
                    }
                },
                error: function() {
                    self.showNotice('An error occurred', 'error');
                },
                complete: function() {
                    button.prop('disabled', false).text(originalText);
                }
            });
        },

        /**
         * Copy webhook URL to clipboard
         */
        copyWebhookUrl: function() {
            var webhookUrl = $('#moneris-webhook-url').val();
            
            if (!webhookUrl) {
                return;
            }

            // Create temporary input
            var $temp = $('<input>');
            $('body').append($temp);
            $temp.val(webhookUrl).select();
            
            try {
                document.execCommand('copy');
                this.showNotice('Webhook URL copied to clipboard', 'success');
            } catch (err) {
                this.showNotice('Failed to copy URL', 'error');
            }
            
            $temp.remove();
        },

        /**
         * Show admin notice
         */
        showNotice: function(message, type) {
            var noticeClass = type === 'success' ? 'notice-success' : 'notice-error';
            var $notice = $('<div class="notice ' + noticeClass + ' is-dismissible"><p>' + message + '</p></div>');
            
            // Remove existing notices
            $('.moneris-admin-notice').remove();
            
            // Add new notice
            $notice.addClass('moneris-admin-notice').insertAfter('.wp-header-end');
            
            // Auto dismiss after 5 seconds
            setTimeout(function() {
                $notice.fadeOut(function() {
                    $(this).remove();
                });
            }, 5000);
        },

        /**
         * Initialize tooltips
         */
        initTooltips: function() {
            $('.moneris-tooltip').tipTip({
                'attribute': 'data-tip',
                'fadeIn': 50,
                'fadeOut': 50,
                'delay': 200
            });
        },

        /**
         * Validate settings before save
         */
        validateSettings: function() {
            var isValid = true;
            var isTestMode = $('#woocommerce_moneris_enhanced_gateway_testmode').is(':checked');
            
            // Check required fields based on mode
            if (isTestMode) {
                if (!$('#woocommerce_moneris_enhanced_gateway_test_store_id').val()) {
                    this.showFieldError('#woocommerce_moneris_enhanced_gateway_test_store_id', 'Test Store ID is required');
                    isValid = false;
                }
                if (!$('#woocommerce_moneris_enhanced_gateway_test_api_token').val()) {
                    this.showFieldError('#woocommerce_moneris_enhanced_gateway_test_api_token', 'Test API Token is required');
                    isValid = false;
                }
            } else {
                if (!$('#woocommerce_moneris_enhanced_gateway_store_id').val()) {
                    this.showFieldError('#woocommerce_moneris_enhanced_gateway_store_id', 'Store ID is required');
                    isValid = false;
                }
                if (!$('#woocommerce_moneris_enhanced_gateway_api_token').val()) {
                    this.showFieldError('#woocommerce_moneris_enhanced_gateway_api_token', 'API Token is required');
                    isValid = false;
                }
            }
            
            return isValid;
        },

        /**
         * Show field error
         */
        showFieldError: function(field, message) {
            $(field).addClass('error');
            $(field).after('<span class="moneris-field-error">' + message + '</span>');
        },

        /**
         * Clear field errors
         */
        clearFieldErrors: function() {
            $('.moneris-field-error').remove();
            $('.error').removeClass('error');
        }
    };

    /**
     * Initialize on document ready
     */
    $(document).ready(function() {
        // Only initialize on Moneris settings page
        if ($('#woocommerce_moneris_enhanced_gateway_enabled').length) {
            MonerisAdmin.init();
        }
    });

})(jQuery);