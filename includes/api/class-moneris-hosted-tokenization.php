<?php
/**
 * Moneris Hosted Tokenization API Handler
 *
 * @package MonerisEnhancedGateway
 * @since   1.0.0
 */

namespace Moneris_Enhanced_Gateway\API;

use Moneris_Enhanced_Gateway\Utils\Moneris_Logger;

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Moneris Hosted Tokenization Class
 *
 * Handles secure tokenization through Moneris Hosted Payment Page
 *
 * @since 1.0.0
 */
class Moneris_Hosted_Tokenization {

    /**
     * HPP URLs
     *
     * @var array
     */
    private $hpp_urls = array(
        'test'       => 'https://esqa.moneris.com/HPPtoken/index.php',
        'production' => 'https://www3.moneris.com/HPPtoken/index.php',
    );

    /**
     * Gateway instance
     *
     * @var \WC_Gateway_Moneris_Enhanced
     */
    private $gateway;

    /**
     * Logger instance
     *
     * @var Moneris_Logger
     */
    private $logger;

    /**
     * Session token key
     *
     * @var string
     */
    private $session_token_key = 'moneris_hpp_token';

    /**
     * Token expiry time in seconds (10 minutes)
     *
     * @var int
     */
    private $token_expiry = 600;

    /**
     * Constructor
     *
     * @param \WC_Gateway_Moneris_Enhanced $gateway Gateway instance.
     */
    public function __construct( $gateway = null ) {
        $this->gateway = $gateway;
        $this->logger  = new Moneris_Logger();
    }

    /**
     * Generate HPP request data
     *
     * @param \WC_Order $order Order object.
     * @return array Request data for HPP.
     */
    public function generate_hpp_request( $order ) {
        if ( ! $order instanceof \WC_Order ) {
            return new \WP_Error( 'invalid_order', __( 'Invalid order object', 'moneris-enhanced-gateway-for-woocommerce' ) );
        }

        // Get HPP credentials
        $hpp_id   = $this->get_hpp_id();
        $hpp_key  = $this->get_hpp_key();
        $store_id = $this->get_store_id();

        if ( empty( $hpp_id ) || empty( $hpp_key ) || empty( $store_id ) ) {
            $this->logger->log( sprintf(
                'Missing credentials - HPP ID: %s, HPP Key: %s, Store ID: %s',
                $hpp_id ? 'Present' : 'Empty',
                $hpp_key ? 'Present' : 'Empty',
                $store_id ? 'Present' : 'Empty'
            ), 'error' );
            return new \WP_Error( 'missing_credentials', __( 'HPP credentials not configured', 'moneris-enhanced-gateway-for-woocommerce' ) );
        }

        // Build request data
        $request_data = array(
            'hpp_id'           => $hpp_id,
            'ps_store_id'      => $store_id,
            'charge_total'     => $order->get_total(),
            'order_no'         => $order->get_order_number(),
            'cust_id'          => $order->get_customer_id(),
            'email'            => $order->get_billing_email(),
            'bill_first_name'  => $order->get_billing_first_name(),
            'bill_last_name'   => $order->get_billing_last_name(),
            'bill_company'     => $order->get_billing_company(),
            'bill_address_one' => $order->get_billing_address_1(),
            'bill_city'        => $order->get_billing_city(),
            'bill_state_prov'  => $order->get_billing_state(),
            'bill_postal_code' => $order->get_billing_postcode(),
            'bill_country'     => $order->get_billing_country(),
            'bill_phone'       => $order->get_billing_phone(),
            'ship_first_name'  => $order->get_shipping_first_name() ?: $order->get_billing_first_name(),
            'ship_last_name'   => $order->get_shipping_last_name() ?: $order->get_billing_last_name(),
            'ship_company'     => $order->get_shipping_company() ?: $order->get_billing_company(),
            'ship_address_one' => $order->get_shipping_address_1() ?: $order->get_billing_address_1(),
            'ship_city'        => $order->get_shipping_city() ?: $order->get_billing_city(),
            'ship_state_prov'  => $order->get_shipping_state() ?: $order->get_billing_state(),
            'ship_postal_code' => $order->get_shipping_postcode() ?: $order->get_billing_postcode(),
            'ship_country'     => $order->get_shipping_country() ?: $order->get_billing_country(),
            'language'         => $this->get_language(),
            'rvar_reference_3' => 'WC_Order_' . $order->get_id(),
            'txn_type'         => 'purchase',
            'dynamic_descriptor' => get_bloginfo( 'name' ),
        );

        // Add return URLs
        $request_data['hpp_success_url'] = $this->get_return_url( $order, 'success' );
        $request_data['hpp_error_url']   = $this->get_return_url( $order, 'error' );
        $request_data['hpp_cancel_url']  = $this->get_return_url( $order, 'cancel' );

        // Generate HMAC for security
        $request_data['ticket'] = $this->generate_hmac( $request_data );

        // Log request (without sensitive data)
        $this->logger->log( 'HPP request generated for order ' . $order->get_order_number(), 'info' );

        return $request_data;
    }

    /**
     * Generate HMAC signature
     *
     * @param array $data Request data.
     * @return string HMAC signature.
     */
    public function generate_hmac( $data ) {
        $hpp_key = $this->get_hpp_key();

        if ( empty( $hpp_key ) ) {
            $this->logger->log( 'Cannot generate HMAC: HPP key not configured', 'error' );
            return '';
        }

        // Build message for HMAC - fields must be in specific order per Moneris documentation
        $message_fields = array(
            'ps_store_id',  // Store ID is critical for authentication
            'hpp_id',
            'charge_total',
            'order_no',
            'txn_type',
            'cust_id',
            'email',
        );

        $message = '';
        $included_fields = array();
        foreach ( $message_fields as $field ) {
            if ( isset( $data[ $field ] ) && $data[ $field ] !== '' ) {
                $message .= $data[ $field ];
                $included_fields[] = $field . '=' . $data[ $field ];
            }
        }

        // Log HMAC calculation for debugging (without showing the key or final HMAC)
        $this->logger->log( sprintf(
            'HMAC calculation - Fields included: [%s], Message length: %d',
            implode( ', ', $included_fields ),
            strlen( $message )
        ), 'debug' );

        if ( empty( $message ) ) {
            $this->logger->log( 'HMAC calculation failed: No valid fields found for message construction', 'error' );
            return '';
        }

        // Generate HMAC-SHA256
        $hmac = hash_hmac( 'sha256', $message, $hpp_key );

        $this->logger->log( 'HMAC signature generated successfully', 'debug' );

        return $hmac;
    }

    /**
     * Validate token response from Moneris
     *
     * @param array $response Response data from Moneris.
     * @return bool|WP_Error True if valid, WP_Error on failure.
     */
    public function validate_token_response( $response ) {
        if ( empty( $response ) || ! is_array( $response ) ) {
            return new \WP_Error( 'invalid_response', __( 'Invalid response data', 'moneris-enhanced-gateway-for-woocommerce' ) );
        }

        // Required fields
        $required_fields = array( 'response_order_id', 'date_stamp', 'time_stamp', 'ticket' );
        foreach ( $required_fields as $field ) {
            if ( empty( $response[ $field ] ) ) {
                return new \WP_Error( 'missing_field', sprintf( __( 'Missing required field: %s', 'moneris-enhanced-gateway-for-woocommerce' ), $field ) );
            }
        }

        // Verify HMAC signature
        $hpp_key = $this->get_hpp_key();
        if ( empty( $hpp_key ) ) {
            return new \WP_Error( 'missing_key', __( 'HPP key not configured', 'moneris-enhanced-gateway-for-woocommerce' ) );
        }

        // Build message for HMAC verification
        $message = $response['response_order_id'] . $response['date_stamp'] . $response['time_stamp'];
        $expected_hmac = hash_hmac( 'sha256', $message, $hpp_key );

        if ( ! hash_equals( $expected_hmac, $response['ticket'] ) ) {
            $this->logger->log( 'HMAC validation failed for order ' . $response['response_order_id'], 'error' );
            return new \WP_Error( 'invalid_signature', __( 'Response signature validation failed', 'moneris-enhanced-gateway-for-woocommerce' ) );
        }

        // Check response code
        if ( isset( $response['response_code'] ) && '000' !== $response['response_code'] ) {
            $error_message = $this->get_response_message( $response['response_code'] );
            return new \WP_Error( 'transaction_failed', $error_message );
        }

        $this->logger->log( 'Token response validated successfully for order ' . $response['response_order_id'], 'info' );
        return true;
    }

    /**
     * Get iframe URL for HPP
     *
     * @param \WC_Order|null $order Optional order object.
     * @return string HPP iframe URL.
     */
    public function get_iframe_url( $order = null ) {
        // Get HPP ID
        $hpp_id = $this->get_hpp_id();

        // Comprehensive validation and error logging
        if ( empty( $hpp_id ) ) {
            $this->logger->log( 'Error: HPP ID is empty - cannot generate iframe URL', 'error' );
        }

        $store_id = $this->get_store_id();
        if ( empty( $store_id ) ) {
            $this->logger->log( 'Error: Store ID is empty - this will cause authentication failures', 'error' );
        }

        $hpp_key = $this->get_hpp_key();
        if ( empty( $hpp_key ) ) {
            $this->logger->log( 'Error: HPP Key is empty - HMAC validation will fail', 'error' );
        }

        // If no real HPP ID, show a test message
        if ( empty( $hpp_id ) || 'TEST_HPP_001' === $hpp_id ) {
            $this->logger->log( 'Displaying test configuration page due to missing/test HPP credentials', 'warning' );
            // Return a data URL with instructions
            $test_html = '
            <html>
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <style>
                    body {
                        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                        padding: 30px;
                        text-align: center;
                        background: #f5f5f5;
                        color: #333;
                    }
                    .container {
                        max-width: 500px;
                        margin: 0 auto;
                        background: white;
                        padding: 30px;
                        border-radius: 8px;
                        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                    }
                    h3 {
                        color: #0073aa;
                        margin-top: 0;
                    }
                    .test-mode {
                        background: #fff3cd;
                        color: #856404;
                        padding: 10px;
                        border-radius: 4px;
                        margin: 20px 0;
                        font-size: 14px;
                    }
                    ol {
                        text-align: left;
                        display: inline-block;
                        margin: 20px 0;
                    }
                    li {
                        margin: 10px 0;
                    }
                    .test-cards {
                        background: #e8f4f8;
                        padding: 15px;
                        border-radius: 4px;
                        margin-top: 20px;
                    }
                    .test-cards h4 {
                        margin-top: 0;
                        color: #0073aa;
                    }
                    .card-info {
                        text-align: left;
                        font-family: monospace;
                        font-size: 13px;
                        margin: 10px 0;
                    }
                </style>
            </head>
            <body>
                <div class="container">
                    <h3>🔒 Moneris Test Mode</h3>
                    <div class="test-mode">
                        <strong>HPP credentials not configured</strong>
                    </div>
                    <p>To enable Moneris payment processing:</p>
                    <ol>
                        <li>Get test credentials from Moneris</li>
                        <li>Go to WooCommerce → Settings → Payments → Moneris</li>
                        <li>Enter your Store ID, API Token, HPP ID, and HPP Key</li>
                        <li>Save settings and refresh this page</li>
                    </ol>
                    <div class="test-cards">
                        <h4>Test Card Numbers</h4>
                        <div class="card-info">
                            <strong>Visa:</strong> 4242 4242 4242 4242<br>
                            <strong>MasterCard:</strong> 5454 5454 5454 5454<br>
                            <strong>Expiry:</strong> Any future date<br>
                            <strong>CVV:</strong> Any 3 digits
                        </div>
                    </div>
                </div>
            </body>
            </html>';

            return 'data:text/html;base64,' . base64_encode( $test_html );
        }

        // Build real iframe URL
        $base_url = $this->is_test_mode() ? $this->hpp_urls['test'] : $this->hpp_urls['production'];

        // Get required credentials
        $store_id = $this->get_store_id();

        // Basic parameters - ps_store_id is critical for authentication
        $params = array(
            'hpp_id'      => $hpp_id,
            'ps_store_id' => $store_id,
            'hpp_preload' => '',
        );

        // Log credential status for debugging
        $this->logger->log( sprintf(
            'Building HPP iframe URL - HPP ID: %s, Store ID: %s, Mode: %s',
            $hpp_id ? 'Present' : 'Empty',
            $store_id ? 'Present' : 'Empty',
            $this->is_test_mode() ? 'Test' : 'Production'
        ), 'info' );

        // Add order data if provided
        if ( $order instanceof \WC_Order ) {
            $request_data = $this->generate_hpp_request( $order );
            if ( ! is_wp_error( $request_data ) ) {
                $params = array_merge( $params, $request_data );
                $this->logger->log( 'Successfully added order data to HPP parameters for order ' . $order->get_order_number(), 'debug' );
            } else {
                $this->logger->log( 'Failed to generate HPP request data for order ' . $order->get_order_number() . ': ' . $request_data->get_error_message(), 'error' );
            }
        }

        $final_url = add_query_arg( $params, $base_url );

        // Log final URL (without sensitive data)
        $this->logger->log( sprintf(
            'Generated HPP iframe URL: %s with %d parameters',
            $base_url,
            count( $params )
        ), 'info' );

        // Additional validation for critical authentication parameters
        if ( empty( $params['ps_store_id'] ) ) {
            $this->logger->log( 'CRITICAL: ps_store_id parameter is missing from iframe URL - this will cause error 945', 'error' );
        }
        if ( empty( $params['hpp_id'] ) ) {
            $this->logger->log( 'CRITICAL: hpp_id parameter is missing from iframe URL', 'error' );
        }

        return $final_url;
    } 

    /**
     * Save token for session
     *
     * @param string $token Payment token.
     * @return bool Success status.
     */
    public function save_token_for_session( $token ) {
        if ( empty( $token ) ) {
            return false;
        }

        // Get WC session
        if ( ! WC()->session ) {
            return false;
        }

        // Save token with expiry time
        $token_data = array(
            'token'  => sanitize_text_field( $token ),
            'expiry' => time() + $this->token_expiry,
        );

        WC()->session->set( $this->session_token_key, $token_data );
        $this->logger->log( 'Token saved to session', 'debug' );

        return true;
    }

    /**
     * Get session token
     *
     * @return string|null Token or null if not found/expired.
     */
    public function get_session_token() {
        if ( ! WC()->session ) {
            return null;
        }

        $token_data = WC()->session->get( $this->session_token_key );

        if ( empty( $token_data ) || ! is_array( $token_data ) ) {
            return null;
        }

        // Check expiry
        if ( isset( $token_data['expiry'] ) && time() > $token_data['expiry'] ) {
            $this->clear_session_token();
            $this->logger->log( 'Token expired and cleared', 'debug' );
            return null;
        }

        return isset( $token_data['token'] ) ? $token_data['token'] : null;
    }

    /**
     * Clear session token
     *
     * @return void
     */
    public function clear_session_token() {
        if ( WC()->session ) {
            WC()->session->set( $this->session_token_key, null );
            $this->logger->log( 'Token cleared from session', 'debug' );
        }
    }

    /**
     * Get HPP ID
     *
     * @return string
     */
    private function get_hpp_id() {
        if ( $this->gateway ) {
            $hpp_id = $this->gateway->get_hpp_id();
            $this->logger->log( 'Retrieved HPP ID: ' . ($hpp_id ? 'Present' : 'Empty'), 'debug' );
            return $hpp_id;
        }

        // Fallback to placeholder
        $fallback = $this->is_test_mode() ? 'TEST_HPP_001' : '';
        $this->logger->log( 'Using fallback HPP ID: ' . $fallback, 'debug' );
        return $fallback;
    }

    /**
     * Get HPP Key
     *
     * @return string
     */
    private function get_hpp_key() {
        if ( $this->gateway ) {
            $hpp_key = $this->gateway->get_hpp_key();
            $this->logger->log( 'Retrieved HPP Key: ' . ($hpp_key ? 'Present' : 'Empty'), 'debug' );
            return $hpp_key;
        }

        // Fallback to placeholder
        $fallback = $this->is_test_mode() ? 'hp_key_placeholder_123' : '';
        $this->logger->log( 'Using fallback HPP Key: ' . $fallback, 'debug' );
        return $fallback;
    }

    /**
     * Get Store ID
     *
     * @return string
     */
    private function get_store_id() {
        if ( $this->gateway ) {
            $store_id = $this->gateway->get_store_id();
            $this->logger->log( 'Retrieved Store ID: ' . ($store_id ? 'Present' : 'Empty'), 'debug' );
            return $store_id;
        }

        // Fallback to placeholder
        $fallback = $this->is_test_mode() ? 'store1' : '';
        $this->logger->log( 'Using fallback Store ID: ' . $fallback, 'debug' );
        return $fallback;
    }

    /**
     * Check if in test mode
     *
     * @return bool
     */
    private function is_test_mode() {
        if ( $this->gateway ) {
            return $this->gateway->is_test_mode();
        }

        // Default to test mode if no gateway
        return true;
    }

    /**
     * Get return URL for HPP
     *
     * @param \WC_Order $order Order object.
     * @param string    $type  URL type (success|error|cancel).
     * @return string
     */
    private function get_return_url( $order, $type = 'success' ) {
        switch ( $type ) {
            case 'success':
                return $order->get_checkout_order_received_url();

            case 'error':
                return wc_get_checkout_url() . '?order_id=' . $order->get_id() . '&payment_error=1';

            case 'cancel':
                return $order->get_cancel_order_url();

            default:
                return home_url();
        }
    }

    /**
     * Get language for HPP
     *
     * @return string
     */
    private function get_language() {
        $locale = get_locale();

        // Map WordPress locale to Moneris language codes
        if ( strpos( $locale, 'fr' ) === 0 ) {
            return 'fr-ca';
        }

        return 'en-ca';
    }

    /**
     * Get response message for code
     *
     * @param string $response_code Moneris response code.
     * @return string User-friendly message.
     */
    private function get_response_message( $response_code ) {
        $messages = array(
            '001' => __( 'Transaction declined by bank', 'moneris-enhanced-gateway-for-woocommerce' ),
            '002' => __( 'Invalid amount', 'moneris-enhanced-gateway-for-woocommerce' ),
            '003' => __( 'Invalid card number', 'moneris-enhanced-gateway-for-woocommerce' ),
            '004' => __( 'Expired card', 'moneris-enhanced-gateway-for-woocommerce' ),
            '005' => __( 'Invalid expiry date', 'moneris-enhanced-gateway-for-woocommerce' ),
            '006' => __( 'Transaction not permitted', 'moneris-enhanced-gateway-for-woocommerce' ),
            '007' => __( 'Card reported lost or stolen', 'moneris-enhanced-gateway-for-woocommerce' ),
            '008' => __( 'Insufficient funds', 'moneris-enhanced-gateway-for-woocommerce' ),
            '009' => __( 'Invalid CVV', 'moneris-enhanced-gateway-for-woocommerce' ),
            '010' => __( 'Transaction timeout', 'moneris-enhanced-gateway-for-woocommerce' ),
            '050' => __( 'Velocity check failed', 'moneris-enhanced-gateway-for-woocommerce' ),
            '051' => __( 'Address verification failed', 'moneris-enhanced-gateway-for-woocommerce' ),
            '475' => __( 'Transaction cancelled', 'moneris-enhanced-gateway-for-woocommerce' ),
            '476' => __( 'Transaction failed - please try again', 'moneris-enhanced-gateway-for-woocommerce' ),
            '481' => __( 'Transaction declined - refer to card issuer', 'moneris-enhanced-gateway-for-woocommerce' ),
            '482' => __( 'Transaction declined - do not honour', 'moneris-enhanced-gateway-for-woocommerce' ),
            '485' => __( 'Card not supported', 'moneris-enhanced-gateway-for-woocommerce' ),
            '486' => __( 'Transaction limit exceeded', 'moneris-enhanced-gateway-for-woocommerce' ),
            '487' => __( 'Invalid merchant configuration', 'moneris-enhanced-gateway-for-woocommerce' ),
            '489' => __( 'Invalid currency', 'moneris-enhanced-gateway-for-woocommerce' ),
            '490' => __( 'Invalid transaction', 'moneris-enhanced-gateway-for-woocommerce' ),
            '945' => __( 'Invalid credentials - please check Store ID and HPP configuration', 'moneris-enhanced-gateway-for-woocommerce' ),
        );

        $code = (string) $response_code;
        if ( isset( $messages[ $code ] ) ) {
            return $messages[ $code ];
        }

        // Default message for unknown codes
        return sprintf( __( 'Transaction failed (Error code: %s)', 'moneris-enhanced-gateway-for-woocommerce' ), $code );
    }

    /**
     * Handle timeout scenario
     *
     * @param \WC_Order $order Order object.
     * @return void
     */
    public function handle_timeout( $order ) {
        if ( ! $order instanceof \WC_Order ) {
            return;
        }

        // Clear any session tokens
        $this->clear_session_token();

        // Add order note
        $order->add_order_note( __( 'Payment timeout - customer did not complete payment within allowed time', 'moneris-enhanced-gateway-for-woocommerce' ) );

        // Update order status
        $order->update_status( 'failed', __( 'Payment timeout', 'moneris-enhanced-gateway-for-woocommerce' ) );

        $this->logger->log( 'Payment timeout handled for order ' . $order->get_order_number(), 'info' );
    }

    /**
     * Process HPP response
     *
     * @param array $response_data Response data from HPP.
     * @return array Processing result.
     */
    public function process_hpp_response( $response_data ) {
        // Validate response
        $validation = $this->validate_token_response( $response_data );
        if ( is_wp_error( $validation ) ) {
            return array(
                'success' => false,
                'error'   => $validation->get_error_message(),
            );
        }

        // Extract order ID
        $order_id = isset( $response_data['response_order_id'] ) ? absint( $response_data['response_order_id'] ) : 0;
        if ( ! $order_id ) {
            return array(
                'success' => false,
                'error'   => __( 'Invalid order ID in response', 'moneris-enhanced-gateway-for-woocommerce' ),
            );
        }

        // Get order
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return array(
                'success' => false,
                'error'   => __( 'Order not found', 'moneris-enhanced-gateway-for-woocommerce' ),
            );
        }

        // Process based on response
        if ( isset( $response_data['response_code'] ) && '000' === $response_data['response_code'] ) {
            // Success
            $order->payment_complete( $response_data['bank_transaction_id'] ?? '' );
            $order->add_order_note(
                sprintf(
                    __( 'Payment completed via Moneris HPP. Transaction ID: %s', 'moneris-enhanced-gateway-for-woocommerce' ),
                    $response_data['bank_transaction_id'] ?? 'N/A'
                )
            );

            // Clear session token
            $this->clear_session_token();

            return array(
                'success'      => true,
                'redirect_url' => $order->get_checkout_order_received_url(),
            );
        } else {
            // Failed
            $response_code = $response_data['response_code'] ?? '999';
            $error_message = $this->get_response_message( $response_code );

            // Special handling for credential errors (response code 945)
            if ( '945' === $response_code ) {
                $this->logger->log( sprintf(
                    'Error 945 (Invalid Credentials) detected for order %s. This usually indicates missing or incorrect Store ID, HPP ID, or HPP Key configuration.',
                    $order->get_order_number()
                ), 'error' );

                // Add detailed note for admin debugging
                $order->add_order_note( sprintf(
                    __( 'Payment failed with error 945 (Invalid Credentials). Please verify Store ID, HPP ID, and HPP Key are correctly configured for the %s environment.', 'moneris-enhanced-gateway-for-woocommerce' ),
                    $this->is_test_mode() ? 'test' : 'production'
                ) );
            } else {
                $order->add_order_note( sprintf( __( 'Payment failed: %s', 'moneris-enhanced-gateway-for-woocommerce' ), $error_message ) );
            }

            return array(
                'success' => false,
                'error'   => $error_message,
            );
        }
    }
}