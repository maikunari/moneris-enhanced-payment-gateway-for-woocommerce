<?php
/**
 * Payment form template for Moneris Hosted Tokenization
 *
 * This template displays the secure iframe for credit card processing
 *
 * @package MonerisEnhancedGateway
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;
?>
<div id="moneris-payment-form" class="moneris-hosted-tokenization">
    <div class="moneris-iframe-container">
        <iframe
            id="monerisFrame"
            src="<?php echo esc_url( $iframe_url ); ?>"
            frameborder="0"
            width="100%"
            height="400"
            class="moneris-iframe"
            aria-label="<?php esc_attr_e( 'Secure credit card form', 'moneris-enhanced-gateway-for-woocommerce' ); ?>"
            sandbox="allow-forms allow-scripts allow-same-origin allow-top-navigation">
        </iframe>

        <div class="moneris-loading" style="display:none;">
            <span class="spinner is-active"></span>
            <span><?php esc_html_e( 'Processing secure payment...', 'moneris-enhanced-gateway-for-woocommerce' ); ?></span>
        </div>

        <div class="moneris-error-container" style="display:none;">
            <div class="woocommerce-error moneris-error"></div>
        </div>
    </div>

    <input type="hidden" id="moneris_token" name="moneris_token" value="" />
    <input type="hidden" id="moneris_token_response" name="moneris_token_response" value="" />
    <?php wp_nonce_field( 'moneris_payment', 'moneris_nonce' ); ?>
</div>

<script type="text/javascript">
    var moneris_checkout_params = {
        ajax_url: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
        test_mode: <?php echo $test_mode ? 'true' : 'false'; ?>,
        iframe_origin: '<?php echo esc_js( $iframe_origin ); ?>',
        debug: <?php echo $debug_mode ? 'true' : 'false'; ?>,
        nonce: '<?php echo esc_js( wp_create_nonce( 'moneris_payment' ) ); ?>',
        strings: {
            processing: '<?php echo esc_js( __( 'Processing payment...', 'moneris-enhanced-gateway-for-woocommerce' ) ); ?>',
            error_generic: '<?php echo esc_js( __( 'An error occurred processing your payment. Please try again.', 'moneris-enhanced-gateway-for-woocommerce' ) ); ?>',
            timeout: '<?php echo esc_js( __( 'Payment processing timed out. Please try again.', 'moneris-enhanced-gateway-for-woocommerce' ) ); ?>'
        }
    };

    // Listen for messages from iframe
    (function() {
        if (window.addEventListener) {
            window.addEventListener('message', handleMonerisMessage, false);
        } else if (window.attachEvent) {
            window.attachEvent('onmessage', handleMonerisMessage);
        }

        function handleMonerisMessage(event) {
            // Verify origin
            if (event.origin !== moneris_checkout_params.iframe_origin) {
                return;
            }

            // Debug logging
            if (moneris_checkout_params.debug) {
                console.log('Moneris message received:', event.data);
            }

            // Parse message
            var data = {};
            try {
                if (typeof event.data === 'string') {
                    data = JSON.parse(event.data);
                } else {
                    data = event.data;
                }
            } catch (e) {
                console.error('Failed to parse Moneris message:', e);
                return;
            }

            // Handle different message types
            if (data.type === 'token_success' && data.token) {
                // Token received successfully
                document.getElementById('moneris_token').value = data.token;
                if (data.response) {
                    document.getElementById('moneris_token_response').value = JSON.stringify(data.response);
                }

                // Show loading
                var loadingEl = document.querySelector('.moneris-loading');
                if (loadingEl) {
                    loadingEl.style.display = 'block';
                }

                // Submit the checkout form
                var checkoutForm = document.querySelector('form.checkout, form#order_review');
                if (checkoutForm) {
                    // Trigger WooCommerce checkout submission
                    jQuery(checkoutForm).submit();
                }
            } else if (data.type === 'token_error') {
                // Show error message
                var errorContainer = document.querySelector('.moneris-error');
                var errorWrapper = document.querySelector('.moneris-error-container');

                if (errorContainer && errorWrapper) {
                    errorContainer.textContent = data.message || moneris_checkout_params.strings.error_generic;
                    errorWrapper.style.display = 'block';
                }

                // Hide loading
                var loadingEl = document.querySelector('.moneris-loading');
                if (loadingEl) {
                    loadingEl.style.display = 'none';
                }
            } else if (data.type === 'iframe_loaded') {
                // Iframe loaded successfully
                if (moneris_checkout_params.debug) {
                    console.log('Moneris iframe loaded');
                }
            } else if (data.type === 'resize') {
                // Adjust iframe height if needed
                if (data.height) {
                    var iframe = document.getElementById('monerisFrame');
                    if (iframe) {
                        iframe.style.height = data.height + 'px';
                    }
                }
            }
        }
    })();
</script>

<style type="text/css">
    .moneris-hosted-tokenization {
        margin-top: 20px;
    }

    .moneris-iframe-container {
        position: relative;
        min-height: 400px;
        border: 1px solid #ddd;
        border-radius: 4px;
        background: #fff;
        padding: 10px;
    }

    .moneris-iframe {
        border: none;
        border-radius: 4px;
    }

    .moneris-loading {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        text-align: center;
        background: rgba(255, 255, 255, 0.95);
        padding: 20px;
        border-radius: 4px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        z-index: 10;
    }

    .moneris-loading .spinner {
        display: block;
        margin: 0 auto 10px;
    }

    .moneris-error-container {
        margin-top: 10px;
    }

    .moneris-error {
        margin-bottom: 0;
    }

    /* Responsive design */
    @media (max-width: 768px) {
        .moneris-iframe-container {
            padding: 5px;
        }

        .moneris-iframe {
            height: 450px !important;
        }
    }
</style>