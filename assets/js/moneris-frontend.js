/**
 * Moneris Enhanced Gateway Frontend JavaScript
 *
 * @package MonerisEnhancedGateway
 */

(function($) {
    'use strict';

    /**
     * Moneris payment handler
     */
    var MonerisPayment = {
        /**
         * Initialize
         */
        init: function() {
            this.form = $('form.checkout, form#order_review');
            this.gateway_id = 'moneris_enhanced_gateway';
            
            this.bindEvents();
            this.formatCardInputs();
        },

        /**
         * Bind events
         */
        bindEvents: function() {
            var self = this;

            // Payment method change
            this.form.on('change', 'input[name="payment_method"]', function() {
                self.togglePaymentFields();
            });

            // Saved card selection
            $(document).on('change', 'input[name="wc-moneris_enhanced_gateway-payment-token"]', function() {
                self.toggleNewCardForm();
            });

            // Card number formatting
            $(document).on('input', '#moneris-card-number', function() {
                self.formatCardNumber(this);
            });

            // Expiry date formatting
            $(document).on('input', '#moneris-card-expiry', function() {
                self.formatExpiry(this);
            });

            // CVC formatting
            $(document).on('input', '#moneris-card-cvc', function() {
                self.formatCVC(this);
            });

            // Form submission
            this.form.on('checkout_place_order_' + this.gateway_id, function() {
                return self.validateForm();
            });
        },

        /**
         * Toggle payment fields visibility
         */
        togglePaymentFields: function() {
            var selected_gateway = $('input[name="payment_method"]:checked').val();
            
            if (selected_gateway === this.gateway_id) {
                $('#wc-' + this.gateway_id + '-cc-form').slideDown(200);
            }
        },

        /**
         * Toggle new card form based on saved card selection
         */
        toggleNewCardForm: function() {
            var token = $('input[name="wc-moneris_enhanced_gateway-payment-token"]:checked').val();
            
            if (token === 'new') {
                $('#wc-moneris_enhanced_gateway-cc-form').slideDown(200);
            } else {
                $('#wc-moneris_enhanced_gateway-cc-form').slideUp(200);
            }
        },

        /**
         * Format card number input
         */
        formatCardNumber: function(element) {
            var value = element.value.replace(/\s+/g, '');
            var formattedValue = value.match(/.{1,4}/g);
            
            if (formattedValue) {
                element.value = formattedValue.join(' ');
            }
            
            // Detect card type
            this.detectCardType(value);
        },

        /**
         * Format expiry date
         */
        formatExpiry: function(element) {
            var value = element.value.replace(/\D/g, '');
            
            if (value.length >= 2) {
                value = value.substring(0, 2) + ' / ' + value.substring(2, 4);
            }
            
            element.value = value;
        },

        /**
         * Format CVC
         */
        formatCVC: function(element) {
            element.value = element.value.replace(/\D/g, '');
        },

        /**
         * Detect card type from number
         */
        detectCardType: function(number) {
            var cardTypes = {
                visa: /^4/,
                mastercard: /^5[1-5]/,
                amex: /^3[47]/,
                discover: /^6(?:011|5)/,
            };

            var cardType = 'unknown';
            
            for (var type in cardTypes) {
                if (cardTypes[type].test(number)) {
                    cardType = type;
                    break;
                }
            }

            // Update UI to show detected card type
            this.updateCardTypeDisplay(cardType);
        },

        /**
         * Update card type display
         */
        updateCardTypeDisplay: function(type) {
            $('.moneris-card-type').removeClass('visa mastercard amex discover').addClass(type);
        },

        /**
         * Format all card inputs
         */
        formatCardInputs: function() {
            // Add placeholders and formatting to inputs
            $('#moneris-card-number').attr('placeholder', '•••• •••• •••• ••••');
            $('#moneris-card-expiry').attr('placeholder', 'MM / YY');
            $('#moneris-card-cvc').attr('placeholder', 'CVC');
        },

        /**
         * Validate form before submission
         */
        validateForm: function() {
            var selected_gateway = $('input[name="payment_method"]:checked').val();
            
            if (selected_gateway !== this.gateway_id) {
                return true;
            }

            var token = $('input[name="wc-moneris_enhanced_gateway-payment-token"]:checked').val();
            
            // If using saved card, no validation needed
            if (token && token !== 'new') {
                return true;
            }

            // Clear previous errors
            this.clearErrors();

            var hasError = false;

            // Validate card number
            var cardNumber = $('#moneris-card-number').val().replace(/\s+/g, '');
            if (!cardNumber || cardNumber.length < 13) {
                this.showError('#moneris-card-number', moneris_params.i18n.invalid_card);
                hasError = true;
            }

            // Validate expiry
            var expiry = $('#moneris-card-expiry').val().replace(/\D/g, '');
            if (!expiry || expiry.length !== 4) {
                this.showError('#moneris-card-expiry', moneris_params.i18n.invalid_expiry);
                hasError = true;
            } else {
                var month = parseInt(expiry.substring(0, 2));
                var year = parseInt('20' + expiry.substring(2, 4));
                var now = new Date();
                var currentYear = now.getFullYear();
                var currentMonth = now.getMonth() + 1;
                
                if (month < 1 || month > 12) {
                    this.showError('#moneris-card-expiry', moneris_params.i18n.invalid_expiry);
                    hasError = true;
                } else if (year < currentYear || (year === currentYear && month < currentMonth)) {
                    this.showError('#moneris-card-expiry', moneris_params.i18n.expired_card);
                    hasError = true;
                }
            }

            // Validate CVC
            var cvc = $('#moneris-card-cvc').val();
            if (!cvc || cvc.length < 3) {
                this.showError('#moneris-card-cvc', moneris_params.i18n.invalid_cvc);
                hasError = true;
            }

            return !hasError;
        },

        /**
         * Show error message
         */
        showError: function(field, message) {
            $(field).addClass('woocommerce-invalid');
            $(field).parent().append('<span class="moneris-error show">' + message + '</span>');
        },

        /**
         * Clear all error messages
         */
        clearErrors: function() {
            $('.moneris-error').remove();
            $('.woocommerce-invalid').removeClass('woocommerce-invalid');
        },

        /**
         * Process payment via AJAX
         */
        processPayment: function(data) {
            var self = this;

            $.ajax({
                type: 'POST',
                url: moneris_params.ajax_url,
                data: {
                    action: 'moneris_process_payment',
                    nonce: moneris_params.nonce,
                    payment_data: data
                },
                beforeSend: function() {
                    self.form.addClass('processing').block({
                        message: null,
                        overlayCSS: {
                            background: '#fff',
                            opacity: 0.6
                        }
                    });
                },
                success: function(response) {
                    if (response.success) {
                        window.location = response.data.redirect;
                    } else {
                        self.showNotice(response.data.message, 'error');
                    }
                },
                error: function() {
                    self.showNotice(moneris_params.i18n.error, 'error');
                },
                complete: function() {
                    self.form.removeClass('processing').unblock();
                }
            });
        },

        /**
         * Show notice
         */
        showNotice: function(message, type) {
            $('.woocommerce-notices-wrapper').first().html(
                '<div class="woocommerce-' + type + '" role="alert">' + message + '</div>'
            );
            
            $('html, body').animate({
                scrollTop: $('.woocommerce-notices-wrapper').offset().top - 100
            }, 500);
        }
    };

    /**
     * Initialize on document ready
     */
    $(document).ready(function() {
        MonerisPayment.init();
    });

    /**
     * Re-initialize on updated checkout
     */
    $(document.body).on('updated_checkout', function() {
        MonerisPayment.init();
    });

})(jQuery);