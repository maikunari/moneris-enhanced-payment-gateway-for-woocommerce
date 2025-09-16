<?php
/**
 * Moneris Payment Gateway
 *
 * @package MonerisEnhancedGateway
 */

namespace Moneris_Enhanced_Gateway\Gateways;

use WC_Payment_Gateway;
use WC_Order;
use Moneris_Enhanced_Gateway\Api\Moneris_API;
use Moneris_Enhanced_Gateway\Utils\Moneris_Logger;

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Moneris Gateway class
 *
 * @since 1.0.0
 */
class Moneris_Gateway extends WC_Payment_Gateway {

    /**
     * API handler
     *
     * @var Moneris_API
     */
    private $api;

    /**
     * Logger
     *
     * @var Moneris_Logger
     */
    private $logger;

    /**
     * Constructor
     */
    public function __construct() {
        $this->id                 = 'moneris_enhanced_gateway';
        $this->icon               = apply_filters( 'woocommerce_moneris_icon', '' );
        $this->has_fields         = true;
        $this->method_title       = __( 'Moneris Enhanced Gateway', 'moneris-enhanced-gateway-for-woocommerce' );
        $this->method_description = __( 'Accept credit card payments through Moneris with enhanced security features including hosted tokenization.', 'moneris-enhanced-gateway-for-woocommerce' );
        $this->supports           = array(
            'products',
            'refunds',
            'tokenization',
            'subscriptions',
            'subscription_cancellation',
            'subscription_suspension',
            'subscription_reactivation',
            'subscription_amount_changes',
            'subscription_date_changes',
            'subscription_payment_method_change',
            'subscription_payment_method_change_customer',
            'subscription_payment_method_change_admin',
            'multiple_subscriptions',
        );

        // Load settings
        $this->init_form_fields();
        $this->init_settings();

        // Define user settings
        $this->title       = $this->get_option( 'title' );
        $this->description = $this->get_option( 'description' );
        $this->enabled     = $this->get_option( 'enabled' );
        $this->testmode    = 'yes' === $this->get_option( 'testmode' );
        $this->debug       = 'yes' === $this->get_option( 'debug' );

        // Initialize API and Logger
        $this->api = new Moneris_API();
        $this->logger = new Moneris_Logger();

        // Hooks
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
        add_action( 'woocommerce_api_' . $this->id, array( $this, 'webhook' ) );

        // Add test mode notice
        if ( $this->testmode ) {
            $this->description .= ' ' . __( '(TEST MODE ENABLED)', 'moneris-enhanced-gateway-for-woocommerce' );
            $this->description = trim( $this->description );
        }
    }

    /**
     * Initialize gateway settings form fields
     */
    public function init_form_fields() {
        $this->form_fields = array(
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
            ),
            'testmode' => array(
                'title'       => __( 'Test mode', 'moneris-enhanced-gateway-for-woocommerce' ),
                'label'       => __( 'Enable Test Mode', 'moneris-enhanced-gateway-for-woocommerce' ),
                'type'        => 'checkbox',
                'description' => __( 'Place the payment gateway in test mode using test API keys.', 'moneris-enhanced-gateway-for-woocommerce' ),
                'default'     => 'yes',
                'desc_tip'    => true,
            ),
            'test_store_id' => array(
                'title'       => __( 'Test Store ID', 'moneris-enhanced-gateway-for-woocommerce' ),
                'type'        => 'text',
                'description' => __( 'Enter your Moneris test store ID.', 'moneris-enhanced-gateway-for-woocommerce' ),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'test_api_token' => array(
                'title'       => __( 'Test API Token', 'moneris-enhanced-gateway-for-woocommerce' ),
                'type'        => 'password',
                'description' => __( 'Enter your Moneris test API token.', 'moneris-enhanced-gateway-for-woocommerce' ),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'store_id' => array(
                'title'       => __( 'Production Store ID', 'moneris-enhanced-gateway-for-woocommerce' ),
                'type'        => 'text',
                'description' => __( 'Enter your Moneris production store ID.', 'moneris-enhanced-gateway-for-woocommerce' ),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'api_token' => array(
                'title'       => __( 'Production API Token', 'moneris-enhanced-gateway-for-woocommerce' ),
                'type'        => 'password',
                'description' => __( 'Enter your Moneris production API token.', 'moneris-enhanced-gateway-for-woocommerce' ),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'hosted_tokenization' => array(
                'title'       => __( 'Hosted Tokenization', 'moneris-enhanced-gateway-for-woocommerce' ),
                'label'       => __( 'Enable Hosted Tokenization', 'moneris-enhanced-gateway-for-woocommerce' ),
                'type'        => 'checkbox',
                'description' => __( 'Use Moneris Hosted Tokenization for enhanced security.', 'moneris-enhanced-gateway-for-woocommerce' ),
                'default'     => 'yes',
                'desc_tip'    => true,
            ),
            'save_cards' => array(
                'title'       => __( 'Save Cards', 'moneris-enhanced-gateway-for-woocommerce' ),
                'label'       => __( 'Allow customers to save cards for future purchases', 'moneris-enhanced-gateway-for-woocommerce' ),
                'type'        => 'checkbox',
                'description' => __( 'If enabled, users will be able to pay with a saved card during checkout.', 'moneris-enhanced-gateway-for-woocommerce' ),
                'default'     => 'yes',
                'desc_tip'    => true,
            ),
            'capture' => array(
                'title'       => __( 'Capture', 'moneris-enhanced-gateway-for-woocommerce' ),
                'label'       => __( 'Capture charge immediately', 'moneris-enhanced-gateway-for-woocommerce' ),
                'type'        => 'checkbox',
                'description' => __( 'Whether or not to immediately capture the transaction. When unchecked, the transaction will be authorized but will need to be captured manually.', 'moneris-enhanced-gateway-for-woocommerce' ),
                'default'     => 'yes',
                'desc_tip'    => true,
            ),
            'debug' => array(
                'title'       => __( 'Debug Log', 'moneris-enhanced-gateway-for-woocommerce' ),
                'type'        => 'checkbox',
                'label'       => __( 'Enable logging', 'moneris-enhanced-gateway-for-woocommerce' ),
                'default'     => 'no',
                'description' => __( 'Log events such as API requests', 'moneris-enhanced-gateway-for-woocommerce' ),
            ),
        );
    }

    /**
     * Process the payment and return the result
     *
     * @param int $order_id Order ID.
     * @return array
     */
    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return array(
                'result'   => 'failure',
                'messages' => __( 'Invalid order.', 'moneris-enhanced-gateway-for-woocommerce' ),
            );
        }

        // Log payment attempt
        if ( $this->debug ) {
            $this->logger->log( 'Processing payment for order #' . $order_id );
        }

        // Process payment through API
        $payment_data = array(
            'order_id'     => $order->get_order_number(),
            'amount'       => $order->get_total(),
            'pan'          => sanitize_text_field( $_POST['moneris_card_number'] ?? '' ),
            'expdate'      => sanitize_text_field( $_POST['moneris_card_expiry'] ?? '' ),
            'crypt_type'   => '7',
            'dynamic_descriptor' => substr( get_bloginfo( 'name' ), 0, 20 ),
        );

        $response = $this->api->purchase( $payment_data );

        if ( $response['success'] ) {
            // Payment successful
            $order->payment_complete( $response['data']['TransID'] ?? '' );
            $order->add_order_note( __( 'Moneris payment completed successfully', 'moneris-enhanced-gateway-for-woocommerce' ) );

            // Return success
            return array(
                'result'   => 'success',
                'redirect' => $this->get_return_url( $order ),
            );
        } else {
            // Payment failed
            $error_message = $response['message'] ?? __( 'Payment failed. Please try again.', 'moneris-enhanced-gateway-for-woocommerce' );
            wc_add_notice( $error_message, 'error' );

            if ( $this->debug ) {
                $this->logger->log( 'Payment failed: ' . $error_message );
            }

            return array(
                'result' => 'failure',
            );
        }
    }

    /**
     * Process refund
     *
     * @param int    $order_id Order ID.
     * @param float  $amount Refund amount.
     * @param string $reason Refund reason.
     * @return bool|WP_Error
     */
    public function process_refund( $order_id, $amount = null, $reason = '' ) {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return new \WP_Error( 'invalid_order', __( 'Invalid order', 'moneris-enhanced-gateway-for-woocommerce' ) );
        }

        // Get transaction ID
        $transaction_id = $order->get_transaction_id();

        if ( empty( $transaction_id ) ) {
            return new \WP_Error( 'no_transaction', __( 'No transaction ID found', 'moneris-enhanced-gateway-for-woocommerce' ) );
        }

        // Process refund through API
        $refund_data = array(
            'order_id' => $order->get_order_number(),
            'amount'   => $amount,
            'txn_number' => $transaction_id,
        );

        $response = $this->api->refund( $refund_data );

        if ( $response['success'] ) {
            $order->add_order_note(
                sprintf(
                    __( 'Refunded %s - Reason: %s', 'moneris-enhanced-gateway-for-woocommerce' ),
                    wc_price( $amount ),
                    $reason ?: __( 'No reason provided', 'moneris-enhanced-gateway-for-woocommerce' )
                )
            );

            return true;
        }

        return new \WP_Error( 'refund_failed', $response['message'] ?? __( 'Refund failed', 'moneris-enhanced-gateway-for-woocommerce' ) );
    }

    /**
     * Payment form on checkout page
     */
    public function payment_fields() {
        if ( $this->description ) {
            echo wpautop( wp_kses_post( $this->description ) );
        }

        // Display saved cards if tokenization is enabled
        if ( $this->supports( 'tokenization' ) && is_checkout() ) {
            $this->tokenization_script();
            $this->saved_payment_methods();
        }

        // Display payment form
        ?>
        <fieldset id="wc-<?php echo esc_attr( $this->id ); ?>-cc-form" class="wc-credit-card-form wc-payment-form">
            <?php do_action( 'woocommerce_credit_card_form_start', $this->id ); ?>

            <div class="form-row form-row-wide">
                <label for="moneris-card-number"><?php esc_html_e( 'Card Number', 'moneris-enhanced-gateway-for-woocommerce' ); ?> <span class="required">*</span></label>
                <input id="moneris-card-number" class="input-text wc-credit-card-form-card-number" inputmode="numeric" autocomplete="cc-number" autocorrect="no" autocapitalize="no" spellcheck="no" type="tel" placeholder="•••• •••• •••• ••••" name="moneris_card_number" />
            </div>

            <div class="form-row form-row-first">
                <label for="moneris-card-expiry"><?php esc_html_e( 'Expiry (MM/YY)', 'moneris-enhanced-gateway-for-woocommerce' ); ?> <span class="required">*</span></label>
                <input id="moneris-card-expiry" class="input-text wc-credit-card-form-card-expiry" inputmode="numeric" autocomplete="cc-exp" autocorrect="no" autocapitalize="no" spellcheck="no" type="tel" placeholder="MM / YY" name="moneris_card_expiry" />
            </div>

            <div class="form-row form-row-last">
                <label for="moneris-card-cvc"><?php esc_html_e( 'Card Code', 'moneris-enhanced-gateway-for-woocommerce' ); ?> <span class="required">*</span></label>
                <input id="moneris-card-cvc" class="input-text wc-credit-card-form-card-cvc" inputmode="numeric" autocomplete="off" autocorrect="no" autocapitalize="no" spellcheck="no" type="tel" maxlength="4" placeholder="CVC" name="moneris_card_cvc" />
            </div>

            <?php if ( $this->get_option( 'save_cards' ) === 'yes' && ! is_add_payment_method_page() && is_user_logged_in() ) : ?>
                <div class="form-row form-row-wide">
                    <p class="form-row woocommerce-SavedPaymentMethods-saveNew">
                        <input id="wc-<?php echo esc_attr( $this->id ); ?>-new-payment-method" name="wc-<?php echo esc_attr( $this->id ); ?>-new-payment-method" type="checkbox" value="true" />
                        <label for="wc-<?php echo esc_attr( $this->id ); ?>-new-payment-method">
                            <?php esc_html_e( 'Save to account', 'moneris-enhanced-gateway-for-woocommerce' ); ?>
                        </label>
                    </p>
                </div>
            <?php endif; ?>

            <?php do_action( 'woocommerce_credit_card_form_end', $this->id ); ?>

            <div class="clear"></div>
        </fieldset>
        <?php
    }

    /**
     * Validate payment fields
     */
    public function validate_fields() {
        $card_number = isset( $_POST['moneris_card_number'] ) ? sanitize_text_field( wp_unslash( $_POST['moneris_card_number'] ) ) : '';
        $card_expiry = isset( $_POST['moneris_card_expiry'] ) ? sanitize_text_field( wp_unslash( $_POST['moneris_card_expiry'] ) ) : '';
        $card_cvc    = isset( $_POST['moneris_card_cvc'] ) ? sanitize_text_field( wp_unslash( $_POST['moneris_card_cvc'] ) ) : '';

        // Validate card number
        if ( empty( $card_number ) ) {
            wc_add_notice( __( 'Card number is required.', 'moneris-enhanced-gateway-for-woocommerce' ), 'error' );
            return false;
        }

        // Validate expiry
        if ( empty( $card_expiry ) ) {
            wc_add_notice( __( 'Card expiry is required.', 'moneris-enhanced-gateway-for-woocommerce' ), 'error' );
            return false;
        }

        // Validate CVC
        if ( empty( $card_cvc ) ) {
            wc_add_notice( __( 'Card security code is required.', 'moneris-enhanced-gateway-for-woocommerce' ), 'error' );
            return false;
        }

        return true;
    }

    /**
     * Webhook handler
     */
    public function webhook() {
        // Handle webhook from Moneris
        if ( $this->debug ) {
            $this->logger->log( 'Webhook received' );
        }

        // Process webhook data
        // Implementation will depend on Moneris webhook format

        wp_die( 'Moneris Webhook Processed', 'Webhook', array( 'response' => 200 ) );
    }
}