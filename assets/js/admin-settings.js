/**
 * Moneris Enhanced Gateway Admin Settings JavaScript
 *
 * @package MonerisEnhancedGateway
 */

(function($) {
    'use strict';

    /**
     * Admin Settings Handler
     */
    var MonerisAdminSettings = {
        /**
         * Initialize
         */
        init: function() {
            this.bindEvents();
            this.toggleCaptureFields();
            this.addTestConnectionButton();
        },

        /**
         * Bind events
         */
        bindEvents: function() {
            var self = this;

            // Transaction type change
            $(document).on('change', '#woocommerce_moneris_enhanced_transaction_type', function() {
                self.toggleCaptureFields();
            });

            // Auto capture change
            $(document).on('change', '#woocommerce_moneris_enhanced_auto_capture', function() {
                self.toggleCaptureStatusField();
            });

            // Test connection button
            $(document).on('click', '#moneris-test-connection', function(e) {
                e.preventDefault();
                self.testConnection();
            });

            // Test mode toggle
            $(document).on('change', '#woocommerce_moneris_enhanced_test_mode', function() {
                self.updateCredentialLabels();
            });
        },

        /**
         * Toggle capture fields based on transaction type
         */
        toggleCaptureFields: function() {
            var transactionType = $('#woocommerce_moneris_enhanced_transaction_type').val();

            if (transactionType === 'preauth') {
                $('.capture-setting').closest('tr').show();
                this.toggleCaptureStatusField();
            } else {
                $('.capture-setting').closest('tr').hide();
            }
        },

        /**
         * Toggle capture status field based on auto capture
         */
        toggleCaptureStatusField: function() {
            var autoCapture = $('#woocommerce_moneris_enhanced_auto_capture').is(':checked');

            if (autoCapture) {
                $('.capture-status-setting').closest('tr').show();
            } else {
                $('.capture-status-setting').closest('tr').hide();
            }
        },

        /**
         * Add test connection button
         */
        addTestConnectionButton: function() {
            var $saveButton = $('.woocommerce-save-button');

            if ($saveButton.length) {
                var testButton = '<button type="button" id="moneris-test-connection" class="button moneris-test-connection">' +
                                moneris_admin.i18n.test_btn + '</button>';

                $saveButton.after(testButton);

                // Add result container
                $saveButton.parent().append('<div id="moneris-test-result" class="moneris-test-result" style="display:none;"></div>');
            }
        },

        /**
         * Test API connection
         */
        testConnection: function() {
            var self = this;
            var $button = $('#moneris-test-connection');
            var $result = $('#moneris-test-result');
            var originalText = $button.text();

            // Get current form values
            var storeId = $('#woocommerce_moneris_enhanced_store_id').val();
            var apiToken = $('#woocommerce_moneris_enhanced_api_token').val();
            var testMode = $('#woocommerce_moneris_enhanced_test_mode').is(':checked');

            if (!storeId || !apiToken) {
                $result.removeClass('success error')
                       .addClass('error')
                       .html('<strong>Error:</strong> Please enter Store ID and API Token')
                       .show();
                return;
            }

            // Show loading state
            $button.prop('disabled', true)
                   .html('<span class="spinner is-active" style="float:none;"></span> ' + moneris_admin.i18n.testing);

            // Make AJAX request
            $.ajax({
                url: moneris_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'moneris_test_connection',
                    nonce: moneris_admin.nonce,
                    store_id: storeId,
                    api_token: apiToken,
                    test_mode: testMode ? 'yes' : 'no'
                },
                success: function(response) {
                    if (response.success) {
                        $result.removeClass('success error')
                               .addClass('success')
                               .html('<strong>' + moneris_admin.i18n.success + '</strong>')
                               .show();
                    } else {
                        $result.removeClass('success error')
                               .addClass('error')
                               .html('<strong>' + moneris_admin.i18n.error + ':</strong> ' + response.data)
                               .show();
                    }
                },
                error: function() {
                    $result.removeClass('success error')
                           .addClass('error')
                           .html('<strong>' + moneris_admin.i18n.error + ':</strong> Network error occurred')
                           .show();
                },
                complete: function() {
                    $button.prop('disabled', false)
                           .text(originalText);

                    // Hide result after 10 seconds
                    setTimeout(function() {
                        $result.fadeOut();
                    }, 10000);
                }
            });
        },

        /**
         * Update credential field labels based on test mode
         */
        updateCredentialLabels: function() {
            var isTestMode = $('#woocommerce_moneris_enhanced_test_mode').is(':checked');

            var $storeIdLabel = $('label[for="woocommerce_moneris_enhanced_store_id"]');
            var $apiTokenLabel = $('label[for="woocommerce_moneris_enhanced_api_token"]');

            if (isTestMode) {
                // Add test mode indicator to labels
                if ($storeIdLabel.find('.test-indicator').length === 0) {
                    $storeIdLabel.append(' <span class="test-indicator" style="color: #ff9800;">(Test Mode)</span>');
                    $apiTokenLabel.append(' <span class="test-indicator" style="color: #ff9800;">(Test Mode)</span>');
                }
            } else {
                // Remove test mode indicator
                $('.test-indicator').remove();
            }
        },

        /**
         * Validate form before submission
         */
        validateForm: function() {
            var errors = [];

            // Check required fields
            var requiredFields = [
                'store_id',
                'api_token',
                'hpp_id',
                'hpp_key'
            ];

            requiredFields.forEach(function(field) {
                var $field = $('#woocommerce_moneris_enhanced_' + field);
                if ($field.length && !$field.val()) {
                    errors.push($field.closest('tr').find('label').text() + ' is required');
                    $field.addClass('error');
                }
            });

            if (errors.length > 0) {
                alert('Please fix the following errors:\n\n' + errors.join('\n'));
                return false;
            }

            return true;
        },

        /**
         * Show/hide fields based on dependencies
         */
        handleFieldDependencies: function() {
            // Handle crypt type visibility based on transaction type
            var transactionType = $('#woocommerce_moneris_enhanced_transaction_type').val();

            if (transactionType === 'preauth') {
                $('#woocommerce_moneris_enhanced_crypt_type').closest('tr').show();
            } else {
                // For direct purchase, use default crypt type
                $('#woocommerce_moneris_enhanced_crypt_type').val('7');
            }
        },

        /**
         * Add helper tooltips
         */
        addTooltips: function() {
            var tooltips = {
                'store_id': 'Your unique Moneris store identifier',
                'api_token': 'Secret key for API authentication',
                'hpp_id': 'Profile ID for Hosted Payment Page',
                'hpp_key': 'Validation key for secure HPP transactions',
                'crypt_type': 'Security level indicator for e-commerce transactions'
            };

            $.each(tooltips, function(field, text) {
                var $field = $('#woocommerce_moneris_enhanced_' + field);
                if ($field.length) {
                    $field.after('<span class="woocommerce-help-tip" data-tip="' + text + '"></span>');
                }
            });

            // Initialize tooltips
            $('.woocommerce-help-tip').tipTip({
                'attribute': 'data-tip',
                'fadeIn': 50,
                'fadeOut': 50,
                'delay': 200
            });
        }
    };

    /**
     * Initialize when document is ready
     */
    $(document).ready(function() {
        // Only initialize on Moneris settings page
        if ($('#woocommerce_moneris_enhanced_enabled').length) {
            MonerisAdminSettings.init();
        }
    });

    /**
     * Add inline CSS for better styling
     */
    $(document).ready(function() {
        var css = '<style>' +
            '.moneris-test-connection { margin-left: 10px; }' +
            '.moneris-test-result { margin-top: 10px; padding: 10px; border-radius: 3px; }' +
            '.moneris-test-result.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }' +
            '.moneris-test-result.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }' +
            '.test-indicator { font-size: 0.9em; font-weight: normal; }' +
            '.woocommerce table.form-table input.error { border-color: #dc3232; }' +
            '</style>';

        $('head').append(css);
    });

})(jQuery);