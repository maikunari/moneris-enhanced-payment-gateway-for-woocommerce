/**
 * Moneris Enhanced Gateway - Checkout Handler
 *
 * Handles iframe communication and checkout integration
 *
 * @package MonerisEnhancedGateway
 * @since   1.0.0
 */

(function($) {
    'use strict';

    /**
     * Moneris Checkout Handler Class
     */
    class MonerisCheckout {
        /**
         * Constructor
         */
        constructor() {
            this.tokenReceived = false;
            this.isProcessing = false;
            this.iframe = null;
            this.checkoutForm = null;
            this.retryCount = 0;
            this.maxRetries = 3;
            this.init();
        }

        /**
         * Initialize the handler
         */
        init() {
            // Get iframe element
            this.iframe = document.getElementById('monerisFrame');
            if (!this.iframe) {
                this.log('Iframe not found - standard checkout mode');
                return;
            }

            // Get checkout form
            this.checkoutForm = $('form.checkout, form#order_review');

            // Setup event listeners
            this.setupEventListeners();

            // Hook into WooCommerce checkout
            this.setupCheckoutIntegration();

            this.log('Moneris checkout handler initialized');
        }

        /**
         * Setup event listeners
         */
        setupEventListeners() {
            const self = this;

            // Listen for messages from iframe
            window.addEventListener('message', (event) => {
                this.handleIframeMessage(event);
            }, false);

            // Send billing data to iframe when checkout updates
            $(document.body).on('updated_checkout', () => {
                this.sendBillingDataToIframe();
            });

            // Handle payment method change
            $(document.body).on('change', 'input[name="payment_method"]', () => {
                this.handlePaymentMethodChange();
            });

            // Clear token when form errors occur
            $(document.body).on('checkout_error', () => {
                this.resetToken();
                this.hideLoading();
            });

            // Iframe load event
            if (this.iframe) {
                this.iframe.onload = function() {
                    self.log('Iframe loaded successfully');
                    self.sendBillingDataToIframe();
                };
            }
        }

        /**
         * Handle messages from iframe
         */
        handleIframeMessage(event) {
            // Validate origin
            const allowedOrigins = [
                'https://esqa.moneris.com',  // Test
                'https://www3.moneris.com'   // Production
            ];

            if (!allowedOrigins.includes(event.origin)) {
                console.warn('Invalid message origin:', event.origin);
                return;
            }

            // Parse response
            const response = this.parseResponse(event.data);

            this.log('Iframe message received', response);

            // Handle different response types
            if (response.responseCode === '001' || response.responseCode === '000') {
                // Success - token received
                this.handleTokenSuccess(response.dataKey || response.token);
            } else if (response.responseCode && response.responseCode !== '001') {
                // Error response code
                const errorMessage = response.errorMessage || this.getErrorMessage(response.responseCode);
                this.handleTokenError(errorMessage);
            } else if (response.errorMessage) {
                // Explicit error message
                this.handleTokenError(response.errorMessage);
            } else if (response.type === 'loaded') {
                // Iframe loaded confirmation
                this.log('Iframe confirmed loaded');
                this.sendBillingDataToIframe();
            } else if (response.type === 'resize') {
                // Resize request
                this.handleResize(response.height);
            }
        }

        /**
         * Parse response data
         */
        parseResponse(data) {
            // Handle JSON responses
            if (typeof data === 'object') {
                return data;
            }

            // Handle URL-encoded responses
            const params = {};
            if (typeof data === 'string') {
                if (data.startsWith('{')) {
                    // Try JSON parse
                    try {
                        return JSON.parse(data);
                    } catch (e) {
                        this.log('Failed to parse JSON response', e);
                    }
                }

                // Parse URL-encoded string
                const pairs = data.split('&');
                pairs.forEach(pair => {
                    const [key, value] = pair.split('=');
                    if (key) {
                        params[key] = decodeURIComponent(value || '');
                    }
                });
            }
            return params;
        }

        /**
         * Handle successful token receipt
         */
        handleTokenSuccess(token) {
            if (!token) {
                this.log('Token success but no token provided');
                this.handleTokenError('Payment token not received. Please try again.');
                return;
            }

            this.log('Token received successfully');
            this.tokenReceived = true;
            $('#moneris_token').val(token);
            $('.moneris-error-container').hide();

            // If checkout was waiting, submit now
            if (this.isProcessing) {
                this.submitCheckout();
            }
        }

        /**
         * Handle token error
         */
        handleTokenError(error) {
            this.log('Token error', error);
            this.showError(error || 'Payment authorization failed. Please try again.');
            this.hideLoading();
            this.resetToken();

            // Allow retry
            if (this.retryCount < this.maxRetries) {
                this.retryCount++;
                this.isProcessing = false;
            }
        }

        /**
         * Send billing data to iframe
         */
        sendBillingDataToIframe() {
            if (!this.iframe || !this.iframe.contentWindow) {
                this.log('Cannot send data - iframe not ready');
                return;
            }

            // Collect billing data
            const billingData = {
                bill_first_name: $('#billing_first_name').val(),
                bill_last_name: $('#billing_last_name').val(),
                bill_company_name: $('#billing_company').val(),
                bill_address_one: $('#billing_address_1').val(),
                bill_address_two: $('#billing_address_2').val(),
                bill_city: $('#billing_city').val(),
                bill_state_or_province: $('#billing_state').val(),
                bill_postal_code: $('#billing_postcode').val(),
                bill_country: $('#billing_country').val(),
                bill_phone: $('#billing_phone').val(),
                email: $('#billing_email').val()
            };

            // Add shipping if different
            if (!$('#ship-to-different-address-checkbox').is(':checked')) {
                billingData.ship_first_name = billingData.bill_first_name;
                billingData.ship_last_name = billingData.bill_last_name;
                billingData.ship_company_name = billingData.bill_company_name;
                billingData.ship_address_one = billingData.bill_address_one;
                billingData.ship_city = billingData.bill_city;
                billingData.ship_state_or_province = billingData.bill_state_or_province;
                billingData.ship_postal_code = billingData.bill_postal_code;
                billingData.ship_country = billingData.bill_country;
            } else {
                billingData.ship_first_name = $('#shipping_first_name').val();
                billingData.ship_last_name = $('#shipping_last_name').val();
                billingData.ship_company_name = $('#shipping_company').val();
                billingData.ship_address_one = $('#shipping_address_1').val();
                billingData.ship_city = $('#shipping_city').val();
                billingData.ship_state_or_province = $('#shipping_state').val();
                billingData.ship_postal_code = $('#shipping_postcode').val();
                billingData.ship_country = $('#shipping_country').val();
            }

            // Get order total
            const orderTotal = this.getOrderTotal();
            if (orderTotal) {
                billingData.charge_total = orderTotal;
            }

            // Send to iframe
            const message = Object.keys(billingData)
                .filter(key => billingData[key]) // Only send non-empty values
                .map(key => `${key}=${encodeURIComponent(billingData[key])}`)
                .join('&');

            const targetOrigin = moneris_checkout_params.iframe_origin || '*';

            this.log('Sending billing data to iframe', targetOrigin);
            this.iframe.contentWindow.postMessage(message, targetOrigin);
        }

        /**
         * Get order total from checkout
         */
        getOrderTotal() {
            // Try multiple selectors for order total
            let total = $('#order_review .order-total .amount').text();
            if (!total) {
                total = $('.order-total .woocommerce-Price-amount').text();
            }
            if (!total) {
                total = $('.cart-subtotal .woocommerce-Price-amount').text();
            }

            // Clean and format
            if (total) {
                // Remove currency symbols and formatting
                total = total.replace(/[^0-9.,]/g, '');
                // Replace comma with dot for decimal
                total = total.replace(',', '.');
                // Parse to float
                total = parseFloat(total);
                // Format to 2 decimal places
                return total.toFixed(2);
            }

            return '';
        }

        /**
         * Setup checkout integration
         */
        setupCheckoutIntegration() {
            const self = this;

            // Override checkout submission for Moneris
            $(document.body).on('checkout_place_order_moneris_enhanced', function() {
                // If not our gateway, continue
                if (!self.isOurPaymentMethod()) {
                    return true;
                }

                // If token already received, continue
                if (self.tokenReceived) {
                    self.log('Token already available, proceeding with checkout');
                    return true;
                }

                // Validate required fields
                if (!self.validateRequiredFields()) {
                    return false;
                }

                // Show loading
                self.showLoading();
                self.isProcessing = true;

                // Request token from iframe
                self.requestToken();

                // Prevent form submission for now
                return false;
            });
        }

        /**
         * Check if Moneris is selected payment method
         */
        isOurPaymentMethod() {
            return $('#payment_method_moneris_enhanced').is(':checked');
        }

        /**
         * Handle payment method change
         */
        handlePaymentMethodChange() {
            if (this.isOurPaymentMethod()) {
                // Show our iframe
                $('#moneris-payment-form').show();
                this.sendBillingDataToIframe();
            } else {
                // Hide our iframe
                $('#moneris-payment-form').hide();
                this.resetToken();
            }
        }

        /**
         * Validate required fields
         */
        validateRequiredFields() {
            const required = [
                '#billing_first_name',
                '#billing_last_name',
                '#billing_address_1',
                '#billing_city',
                '#billing_postcode',
                '#billing_email'
            ];

            for (let field of required) {
                if (!$(field).val()) {
                    this.showError('Please fill in all required billing fields.');
                    $(field).focus();
                    return false;
                }
            }

            return true;
        }

        /**
         * Request token from iframe
         */
        requestToken() {
            if (!this.iframe || !this.iframe.contentWindow) {
                this.handleTokenError('Payment iframe not loaded. Please refresh and try again.');
                return;
            }

            // Send request to iframe for token
            const message = 'requestToken=true';
            const targetOrigin = moneris_checkout_params.iframe_origin || '*';

            this.log('Requesting token from iframe');
            this.iframe.contentWindow.postMessage(message, targetOrigin);

            // Timeout after 30 seconds
            setTimeout(() => {
                if (!this.tokenReceived && this.isProcessing) {
                    this.handleTokenError('Payment request timed out. Please try again.');
                    this.isProcessing = false;
                }
            }, 30000);
        }

        /**
         * Submit checkout form
         */
        submitCheckout() {
            this.log('Submitting checkout with token');

            // Remove any previous handlers to prevent loops
            $(document.body).off('checkout_place_order_moneris_enhanced');

            // Re-trigger checkout
            if (this.checkoutForm.length) {
                this.checkoutForm.submit();
            } else {
                $('#place_order').trigger('click');
            }
        }

        /**
         * Reset token state
         */
        resetToken() {
            this.tokenReceived = false;
            this.isProcessing = false;
            $('#moneris_token').val('');
            $('#moneris_token_response').val('');
        }

        /**
         * Handle iframe resize
         */
        handleResize(height) {
            if (this.iframe && height) {
                this.iframe.style.height = height + 'px';
                this.log('Iframe resized to', height);
            }
        }

        /**
         * Show loading state
         */
        showLoading() {
            $('.moneris-loading').show();
            $('#place_order').prop('disabled', true).addClass('processing');
            this.checkoutForm.addClass('processing');
        }

        /**
         * Hide loading state
         */
        hideLoading() {
            $('.moneris-loading').hide();
            $('#place_order').prop('disabled', false).removeClass('processing');
            this.checkoutForm.removeClass('processing');
        }

        /**
         * Show error message
         */
        showError(message) {
            $('.moneris-error').text(message);
            $('.moneris-error-container').show();

            // Scroll to error
            const errorOffset = $('.moneris-error-container').offset();
            if (errorOffset) {
                $('html, body').animate({
                    scrollTop: errorOffset.top - 100
                }, 500);
            }

            // Also trigger WooCommerce error
            $(document.body).trigger('checkout_error', [message]);
        }

        /**
         * Get error message for response code
         */
        getErrorMessage(code) {
            const messages = {
                '050': 'Transaction declined. Please try another card.',
                '051': 'Insufficient funds.',
                '052': 'Card expired.',
                '053': 'Invalid card number.',
                '054': 'Invalid CVV.',
                '055': 'Card not supported.',
                '200': 'Communication error. Please try again.',
                '201': 'Invalid merchant configuration.',
                '475': 'Transaction cancelled.',
                '476': 'Transaction failed. Please try again.',
                '481': 'Transaction declined by bank.',
                '482': 'Transaction not permitted.',
                '900': 'Service temporarily unavailable.'
            };

            return messages[code] || `Transaction failed (Error ${code}). Please try again.`;
        }

        /**
         * Debug logging
         */
        log(message, data) {
            if (moneris_checkout_params && moneris_checkout_params.debug) {
                console.log('[Moneris]', message, data || '');
            }
        }
    }

    // Initialize on document ready
    $(document).ready(function() {
        // Only initialize if we have the iframe
        if ($('#monerisFrame').length > 0) {
            window.monerisCheckout = new MonerisCheckout();
        }
    });

    // Also initialize on checkout update
    $(document.body).on('updated_checkout', function() {
        if ($('#monerisFrame').length > 0 && !window.monerisCheckout) {
            window.monerisCheckout = new MonerisCheckout();
        }
    });

})(jQuery);