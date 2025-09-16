<?php
/**
 * Moneris Enhanced Payment Gateway Class
 *
 * @package MonerisEnhancedGateway
 * @since 1.0.0
 */

namespace Moneris_Enhanced_Gateway\Gateways;

use WC_Payment_Gateway;
use WC_Order;
use WP_Error;
use Moneris_Enhanced_Gateway\Utils\Moneris_Credential_Manager;

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Moneris Enhanced Gateway Class
 *
 * Extends WC_Payment_Gateway to provide Moneris payment processing
 * with Hosted Tokenization for PCI compliance
 *
 * @since 1.0.0
 */
class WC_Gateway_Moneris_Enhanced extends WC_Payment_Gateway {

    /**
     * API URLs
     *
     * @var array
     */
    private $api_urls = array(
        'test' => array(
            'api' => 'https://esqa.moneris.com',
            'hpp' => 'https://esqa.moneris.com/HPPtoken/index.php',
        ),
        'production' => array(
            'api' => 'https://www3.moneris.com',
            'hpp' => 'https://www3.moneris.com/HPPtoken/index.php',
        ),
    );

    /**
     * Credential Manager instance
     *
     * @var Moneris_Credential_Manager
     */
    private $credential_manager;

    /**
     * Constructor
     */
    public function __construct() {
        // Gateway configuration
        $this->id                 = 'moneris_enhanced';
        $this->icon               = '';
        $this->has_fields         = true;
        $this->method_title       = __( 'Moneris Enhanced Gateway', 'moneris-enhanced-gateway-for-woocommerce' );
        $this->method_description = __( 'Secure Canadian payment processing via Moneris with Hosted Tokenization for PCI compliance', 'moneris-enhanced-gateway-for-woocommerce' );

        // Gateway supports
        $this->supports = array(
            'products',
            'refunds',
            'tokenization',
            'subscriptions',
            'subscription_cancellation',
            'subscription_suspension',
            'subscription_reactivation',
            'subscription_amount_changes',
            'subscription_date_changes',
        );

        // Load settings
        $this->init_form_fields();
        $this->init_settings();

        // Define user-facing options
        $this->title       = $this->get_option( 'title' );
        $this->description = $this->get_option( 'description' );
        $this->enabled     = $this->get_option( 'enabled' );

        // Initialize credential manager after settings are loaded
        $this->credential_manager = new Moneris_Credential_Manager( $this->is_test_mode() );

        // Set icon after settings are loaded
        $this->icon = $this->get_gateway_icon();

        // Hooks
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
        add_action( 'woocommerce_api_wc_gateway_moneris_enhanced', array( $this, 'handle_webhook' ) );

        // Admin scripts
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );

        // AJAX handlers
        add_action( 'wp_ajax_moneris_test_connection', array( $this, 'ajax_test_connection' ) );
    }

    /**
     * Initialize gateway settings form fields
     */
    public function init_form_fields() {
        $this->form_fields = array(
            // Basic Settings
            'enabled' => array(
                'title'   => __( 'Enable/Disable', 'moneris-enhanced-gateway-for-woocommerce' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable Moneris Enhanced Gateway', 'moneris-enhanced-gateway-for-woocommerce' ),
                'default' => 'no',
            ),
            'title' => array(
                'title'       => __( 'Title', 'moneris-enhanced-gateway-for-woocommerce' ),
                'type'        => 'text',
                'description' => __( 'This controls the title which the user sees during checkout.', 'moneris-enhanced-gateway-for-woocommerce' ),
                'default'     => __( 'Credit Card (Moneris)', 'moneris-enhanced-gateway-for-woocommerce' ),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __( 'Description', 'moneris-enhanced-gateway-for-woocommerce' ),
                'type'        => 'textarea',
                'description' => __( 'This controls the description which the user sees during checkout.', 'moneris-enhanced-gateway-for-woocommerce' ),
                'default'     => __( 'Pay securely with your credit card through Moneris.', 'moneris-enhanced-gateway-for-woocommerce' ),
                'desc_tip'    => true,
            ),
            'test_mode' => array(
                'title'       => __( 'Test Mode', 'moneris-enhanced-gateway-for-woocommerce' ),
                'type'        => 'checkbox',
                'label'       => __( 'Enable Test/Sandbox Mode', 'moneris-enhanced-gateway-for-woocommerce' ),
                'default'     => 'yes',
                'description' => __( 'Place the payment gateway in test mode using test API keys.', 'moneris-enhanced-gateway-for-woocommerce' ),
            ),

            // API Credentials Section
            'api_credentials_title' => array(
                'title'       => __( 'API Credentials', 'moneris-enhanced-gateway-for-woocommerce' ),
                'type'        => 'title',
                'description' => __( 'Enter your Moneris API credentials. You can find these in your Moneris merchant account.', 'moneris-enhanced-gateway-for-woocommerce' ),
            ),
            'store_id' => array(
                'title'             => __( 'Store ID', 'moneris-enhanced-gateway-for-woocommerce' ),
                'type'              => 'text',
                'description'       => __( 'Enter your Moneris Store ID.', 'moneris-enhanced-gateway-for-woocommerce' ),
                'desc_tip'          => true,
                'custom_attributes' => array( 'required' => 'required' ),
            ),
            'api_token' => array(
                'title'             => __( 'API Token', 'moneris-enhanced-gateway-for-woocommerce' ),
                'type'              => 'password',
                'description'       => __( 'Enter your Moneris API Token.', 'moneris-enhanced-gateway-for-woocommerce' ),
                'desc_tip'          => true,
                'custom_attributes' => array( 'required' => 'required' ),
            ),
            'hpp_id' => array(
                'title'             => __( 'HPP Profile ID', 'moneris-enhanced-gateway-for-woocommerce' ),
                'type'              => 'text',
                'description'       => __( 'Enter your Hosted Tokenization Profile ID.', 'moneris-enhanced-gateway-for-woocommerce' ),
                'desc_tip'          => true,
                'custom_attributes' => array( 'required' => 'required' ),
            ),
            'hpp_key' => array(
                'title'             => __( 'HPP Validation Key', 'moneris-enhanced-gateway-for-woocommerce' ),
                'type'              => 'password',
                'description'       => __( 'Enter your HPP Validation Key.', 'moneris-enhanced-gateway-for-woocommerce' ),
                'desc_tip'          => true,
                'custom_attributes' => array( 'required' => 'required' ),
            ),

            // Transaction Settings Section
            'transaction_settings_title' => array(
                'title' => __( 'Transaction Settings', 'moneris-enhanced-gateway-for-woocommerce' ),
                'type'  => 'title',
            ),
            'transaction_type' => array(
                'title'       => __( 'Transaction Type', 'moneris-enhanced-gateway-for-woocommerce' ),
                'type'        => 'select',
                'description' => __( 'Choose how transactions should be processed.', 'moneris-enhanced-gateway-for-woocommerce' ),
                'default'     => 'purchase',
                'desc_tip'    => true,
                'options'     => array(
                    'purchase' => __( 'Direct Purchase (Capture immediately)', 'moneris-enhanced-gateway-for-woocommerce' ),
                    'preauth'  => __( 'Pre-Authorization (Capture later)', 'moneris-enhanced-gateway-for-woocommerce' ),
                ),
            ),
            'auto_capture' => array(
                'title'       => __( 'Auto-Capture', 'moneris-enhanced-gateway-for-woocommerce' ),
                'type'        => 'checkbox',
                'label'       => __( 'Automatically capture payment on order status change', 'moneris-enhanced-gateway-for-woocommerce' ),
                'default'     => 'no',
                'description' => __( 'When enabled, pre-authorized payments will be captured automatically when order status changes.', 'moneris-enhanced-gateway-for-woocommerce' ),
                'class'       => 'capture-setting',
            ),
            'capture_on_status' => array(
                'title'       => __( 'Capture on Status', 'moneris-enhanced-gateway-for-woocommerce' ),
                'type'        => 'multiselect',
                'description' => __( 'Select order statuses that should trigger automatic capture.', 'moneris-enhanced-gateway-for-woocommerce' ),
                'desc_tip'    => true,
                'default'     => array( 'processing', 'completed' ),
                'options'     => array(
                    'processing' => __( 'Processing', 'moneris-enhanced-gateway-for-woocommerce' ),
                    'completed'  => __( 'Completed', 'moneris-enhanced-gateway-for-woocommerce' ),
                    'shipped'    => __( 'Shipped', 'moneris-enhanced-gateway-for-woocommerce' ),
                ),
                'class'       => 'capture-setting capture-status-setting wc-enhanced-select',
            ),

            // Advanced Settings Section
            'advanced_settings_title' => array(
                'title' => __( 'Advanced Settings', 'moneris-enhanced-gateway-for-woocommerce' ),
                'type'  => 'title',
            ),
            'enable_logging' => array(
                'title'       => __( 'Enable Logging', 'moneris-enhanced-gateway-for-woocommerce' ),
                'type'        => 'checkbox',
                'label'       => __( 'Enable debug logging', 'moneris-enhanced-gateway-for-woocommerce' ),
                'default'     => 'no',
                'description' => sprintf(
                    /* translators: %s: log path */
                    __( 'Log Moneris events, such as API requests. You can check the log in %s', 'moneris-enhanced-gateway-for-woocommerce' ),
                    '<code>' . \WC_Log_Handler_File::get_log_file_path( 'moneris-enhanced' ) . '</code>'
                ),
            ),
            'enable_tokenization' => array(
                'title'       => __( 'Enable Tokenization', 'moneris-enhanced-gateway-for-woocommerce' ),
                'type'        => 'checkbox',
                'label'       => __( 'Allow customers to save payment cards', 'moneris-enhanced-gateway-for-woocommerce' ),
                'default'     => 'yes',
                'description' => __( 'If enabled, users will be able to save their card details during checkout for faster future payments.', 'moneris-enhanced-gateway-for-woocommerce' ),
            ),
            'crypt_type' => array(
                'title'       => __( 'E-Commerce Indicator', 'moneris-enhanced-gateway-for-woocommerce' ),
                'type'        => 'select',
                'description' => __( 'E-commerce indicator for transaction processing.', 'moneris-enhanced-gateway-for-woocommerce' ),
                'default'     => '7',
                'desc_tip'    => true,
                'options'     => array(
                    '1' => __( '1 - Mail Order / Telephone Order - Single', 'moneris-enhanced-gateway-for-woocommerce' ),
                    '2' => __( '2 - Mail Order / Telephone Order - Recurring', 'moneris-enhanced-gateway-for-woocommerce' ),
                    '3' => __( '3 - Mail Order / Telephone Order - Installment', 'moneris-enhanced-gateway-for-woocommerce' ),
                    '4' => __( '4 - Mail Order / Telephone Order - Unknown', 'moneris-enhanced-gateway-for-woocommerce' ),
                    '5' => __( '5 - Authenticated e-commerce', 'moneris-enhanced-gateway-for-woocommerce' ),
                    '6' => __( '6 - Non-Authenticated e-commerce', 'moneris-enhanced-gateway-for-woocommerce' ),
                    '7' => __( '7 - SSL enabled merchant', 'moneris-enhanced-gateway-for-woocommerce' ),
                ),
            ),
        );
    }

    /**
     * Process and save gateway options
     *
     * @return bool
     */
    public function process_admin_options() {
        // Validate required fields
        $post_data = $this->get_post_data();

        $required_fields = array( 'store_id', 'api_token', 'hpp_id', 'hpp_key' );
        $errors = array();

        foreach ( $required_fields as $field ) {
            $field_key = $this->get_field_key( $field );
            if ( empty( $post_data[ $field_key ] ) ) {
                $errors[] = sprintf(
                    /* translators: %s: field name */
                    __( '%s is required.', 'moneris-enhanced-gateway-for-woocommerce' ),
                    $this->form_fields[ $field ]['title']
                );
            }
        }

        if ( ! empty( $errors ) ) {
            foreach ( $errors as $error ) {
                \WC_Admin_Settings::add_error( $error );
            }
            return false;
        }

        // Store credentials using credential manager
        $store_id = isset( $post_data[ $this->get_field_key( 'store_id' ) ] ) ? sanitize_text_field( $post_data[ $this->get_field_key( 'store_id' ) ] ) : '';
        $api_token = isset( $post_data[ $this->get_field_key( 'api_token' ) ] ) ? $post_data[ $this->get_field_key( 'api_token' ) ] : '';
        $hpp_id = isset( $post_data[ $this->get_field_key( 'hpp_id' ) ] ) ? sanitize_text_field( $post_data[ $this->get_field_key( 'hpp_id' ) ] ) : '';
        $hpp_key = isset( $post_data[ $this->get_field_key( 'hpp_key' ) ] ) ? $post_data[ $this->get_field_key( 'hpp_key' ) ] : '';

        if ( ! empty( $store_id ) && ! empty( $api_token ) && ! empty( $hpp_id ) && ! empty( $hpp_key ) ) {
            $credential_result = $this->credential_manager->store_credentials( $store_id, $api_token, $hpp_id, $hpp_key );

            if ( is_wp_error( $credential_result ) ) {
                \WC_Admin_Settings::add_error(
                    sprintf(
                        /* translators: %s: error message */
                        __( 'Failed to store credentials: %s', 'moneris-enhanced-gateway-for-woocommerce' ),
                        $credential_result->get_error_message()
                    )
                );
                return false;
            }
        }

        // Save settings
        $saved = parent::process_admin_options();

        // Test API connection using credential manager
        if ( $saved ) {
            $test_result = $this->credential_manager->test_connection();

            if ( is_wp_error( $test_result ) ) {
                \WC_Admin_Settings::add_error(
                    sprintf(
                        /* translators: %s: error message */
                        __( 'API Connection Test Failed: %s', 'moneris-enhanced-gateway-for-woocommerce' ),
                        $test_result->get_error_message()
                    )
                );
            } else {
                \WC_Admin_Settings::add_message(
                    __( 'API Connection Test Successful!', 'moneris-enhanced-gateway-for-woocommerce' )
                );

                // Check if credentials need rotation
                if ( $this->credential_manager->needs_rotation() ) {
                    \WC_Admin_Settings::add_message(
                        __( 'Note: Your credentials are due for rotation. Consider updating them soon for security.', 'moneris-enhanced-gateway-for-woocommerce' )
                    );
                }
            }

            // Clear cache/transients
            delete_transient( 'moneris_gateway_status' );
            delete_transient( 'moneris_api_test_result' );
        }

        return $saved;
    }

    /**
     * Check if gateway is available
     *
     * @return bool
     */
    public function is_available() {
        // Check if gateway is enabled
        if ( 'yes' !== $this->enabled ) {
            $this->log( 'Gateway is disabled', 'info' );
            return false;
        }

        // Check required credentials
        $required_credentials = array( 'store_id', 'api_token', 'hpp_id', 'hpp_key' );
        foreach ( $required_credentials as $credential ) {
            if ( empty( $this->get_option( $credential ) ) ) {
                $this->log( 'Missing required credential: ' . $credential, 'error' );
                return false;
            }
        }

        // Check currency - Moneris only supports CAD
        if ( 'CAD' !== get_woocommerce_currency() ) {
            $this->log( 'Currency not supported. Moneris only supports CAD.', 'error' );
            return false;
        }

        // Check SSL in production mode
        if ( ! $this->is_test_mode() && ! is_ssl() ) {
            $this->log( 'SSL is required for production mode', 'error' );
            return false;
        }

        return true;
    }

    /**
     * Get API URL based on mode
     *
     * @return string
     */
    public function get_api_url() {
        $mode = $this->is_test_mode() ? 'test' : 'production';
        return $this->api_urls[ $mode ]['api'];
    }

    /**
     * Get Hosted Payment Page URL
     *
     * @return string
     */
    public function get_hpp_url() {
        $mode = $this->is_test_mode() ? 'test' : 'production';
        return $this->api_urls[ $mode ]['hpp'];
    }

    /**
     * Check if gateway is in test mode
     *
     * @return bool
     */
    public function is_test_mode() {
        return 'yes' === $this->get_option( 'test_mode', 'no' );
    }

    /**
     * Get Store ID
     *
     * @return string
     */
    public function get_store_id() {
        $credentials = $this->credential_manager->get_credentials();
        if ( is_wp_error( $credentials ) ) {
            return '';
        }
        return $credentials['store_id'] ?? '';
    }

    /**
     * Get API Token (decrypted)
     *
     * @return string
     */
    public function get_api_token() {
        $credentials = $this->credential_manager->get_credentials();
        if ( is_wp_error( $credentials ) ) {
            return '';
        }
        return $credentials['api_token'] ?? '';
    }

    /**
     * Get HPP Profile ID
     *
     * @return string
     */
    public function get_hpp_id() {
        $credentials = $this->credential_manager->get_credentials();
        if ( is_wp_error( $credentials ) ) {
            return '';
        }
        return $credentials['hpp_id'] ?? '';
    }

    /**
     * Get HPP Key (decrypted)
     *
     * @return string
     */
    public function get_hpp_key() {
        $credentials = $this->credential_manager->get_credentials();
        if ( is_wp_error( $credentials ) ) {
            return '';
        }
        return $credentials['hpp_key'] ?? '';
    }

    /**
     * Mask credential for display
     *
     * @param string $value Credential value.
     * @return string
     */
    public function mask_credential( $value ) {
        if ( empty( $value ) ) {
            return '';
        }

        $length = strlen( $value );
        if ( $length <= 4 ) {
            return str_repeat( '*', $length );
        }

        return str_repeat( '*', $length - 4 ) . substr( $value, -4 );
    }

    /**
     * Log message if logging is enabled
     *
     * @param string $message Log message.
     * @param string $level Log level (info, error, warning).
     */
    public function log( $message, $level = 'info' ) {
        if ( 'yes' !== $this->get_option( 'enable_logging' ) ) {
            return;
        }

        if ( empty( $this->logger ) ) {
            $this->logger = wc_get_logger();
        }

        $this->logger->log(
            $level,
            $message,
            array( 'source' => 'moneris-enhanced' )
        );
    }

    /**
     * Get gateway icon HTML
     *
     * @return string
     */
    public function get_icon() {
        $icon_html = '';

        // Card logos
        $accepted_cards = array(
            'visa'       => __( 'Visa', 'moneris-enhanced-gateway-for-woocommerce' ),
            'mastercard' => __( 'Mastercard', 'moneris-enhanced-gateway-for-woocommerce' ),
            'amex'       => __( 'American Express', 'moneris-enhanced-gateway-for-woocommerce' ),
        );

        $icon_html .= '<div class="moneris-payment-icons">';

        foreach ( $accepted_cards as $card => $label ) {
            $icon_url = MONERIS_PLUGIN_URL . 'assets/images/cards/' . $card . '.svg';
            $icon_html .= '<img src="' . esc_url( $icon_url ) . '" alt="' . esc_attr( $label ) . '" class="moneris-card-icon moneris-card-' . esc_attr( $card ) . '" />';
        }

        // Optional Moneris logo
        $moneris_logo = MONERIS_PLUGIN_URL . 'assets/images/moneris-logo.svg';
        if ( file_exists( MONERIS_PLUGIN_DIR . 'assets/images/moneris-logo.svg' ) ) {
            $icon_html .= '<img src="' . esc_url( $moneris_logo ) . '" alt="' . esc_attr__( 'Powered by Moneris', 'moneris-enhanced-gateway-for-woocommerce' ) . '" class="moneris-logo-icon" />';
        }

        $icon_html .= '</div>';

        return apply_filters( 'woocommerce_gateway_icon', $icon_html, $this->id );
    }

    /**
     * Get gateway icon for display
     *
     * @return string
     */
    private function get_gateway_icon() {
        return $this->get_icon();
    }

    /**
     * Admin scripts
     */
    public function admin_scripts() {
        if ( ! $this->is_settings_page() ) {
            return;
        }

        wp_enqueue_script(
            'moneris-admin-settings',
            MONERIS_PLUGIN_URL . 'assets/js/admin-settings.js',
            array( 'jquery' ),
            MONERIS_VERSION,
            true
        );

        wp_localize_script(
            'moneris-admin-settings',
            'moneris_admin',
            array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'moneris_admin_nonce' ),
                'i18n'     => array(
                    'testing'    => __( 'Testing connection...', 'moneris-enhanced-gateway-for-woocommerce' ),
                    'test_btn'   => __( 'Test Connection', 'moneris-enhanced-gateway-for-woocommerce' ),
                    'success'    => __( 'Connection successful!', 'moneris-enhanced-gateway-for-woocommerce' ),
                    'error'      => __( 'Connection failed', 'moneris-enhanced-gateway-for-woocommerce' ),
                ),
            )
        );

        // Add inline styles for admin
        wp_add_inline_style( 'woocommerce_admin_styles', '
            .moneris-test-connection {
                margin-left: 10px;
            }
            .moneris-test-result {
                margin-top: 10px;
                padding: 10px;
                border-radius: 3px;
            }
            .moneris-test-result.success {
                background: #d4edda;
                color: #155724;
                border: 1px solid #c3e6cb;
            }
            .moneris-test-result.error {
                background: #f8d7da;
                color: #721c24;
                border: 1px solid #f5c6cb;
            }
            .capture-setting {
                display: none;
            }
            .capture-status-setting {
                display: none;
            }
        ' );
    }

    /**
     * Check if current page is gateway settings page
     *
     * @return bool
     */
    private function is_settings_page() {
        return isset( $_GET['page'] ) && 'wc-settings' === $_GET['page'] &&
               isset( $_GET['tab'] ) && 'checkout' === $_GET['tab'] &&
               isset( $_GET['section'] ) && $this->id === $_GET['section'];
    }

    /**
     * Test API connection via AJAX
     */
    public function ajax_test_connection() {
        check_ajax_referer( 'moneris_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( -1 );
        }

        $result = $this->test_api_connection();

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( __( 'Connection successful!', 'moneris-enhanced-gateway-for-woocommerce' ) );
    }

    /**
     * Test API connection
     *
     * @return true|WP_Error
     */
    private function test_api_connection() {
        // Use credential manager for testing
        return $this->credential_manager->test_connection();
    }

    /**
     * Get credential manager instance
     *
     * @return Moneris_Credential_Manager
     */
    public function get_credential_manager() {
        return $this->credential_manager;
    }

    /**
     * Process the payment
     *
     * @param int $order_id Order ID.
     * @return array
     */
    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return array(
                'result'   => 'fail',
                'redirect' => '',
            );
        }

        // Implementation will be added in next phase
        return array(
            'result'   => 'success',
            'redirect' => $this->get_return_url( $order ),
        );
    }

    /**
     * Output payment fields
     */
    public function payment_fields() {
        if ( $this->description ) {
            echo wpautop( wptexturize( $this->description ) );
        }

        // HPP iframe will be implemented here
        echo '<div id="moneris-hpp-container"></div>';
    }

    /**
     * Validate payment fields
     *
     * @return bool
     */
    public function validate_fields() {
        return true;
    }

    /**
     * Handle webhook/callback
     */
    public function handle_webhook() {
        // Implementation for webhook handling
        $this->log( 'Webhook received', 'info' );
    }

    /**
     * Process refund
     *
     * @param int    $order_id Order ID.
     * @param float  $amount Amount to refund.
     * @param string $reason Refund reason.
     * @return bool|WP_Error
     */
    public function process_refund( $order_id, $amount = null, $reason = '' ) {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return new WP_Error( 'invalid_order', __( 'Invalid order', 'moneris-enhanced-gateway-for-woocommerce' ) );
        }

        // Refund implementation will be added
        return true;
    }

    /**
     * Receipt page
     *
     * @param int $order_id Order ID.
     */
    public function receipt_page( $order_id ) {
        echo '<p>' . esc_html__( 'Thank you for your order.', 'moneris-enhanced-gateway-for-woocommerce' ) . '</p>';
    }
}