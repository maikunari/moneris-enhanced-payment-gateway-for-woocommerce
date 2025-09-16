<?php
/**
 * WooCommerce Blocks Support
 *
 * @package MonerisEnhancedGateway
 * @since 1.0.0
 */

namespace Moneris_Enhanced_Gateway\Blocks;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Moneris Blocks Support Class
 *
 * Handles WooCommerce Blocks checkout integration for the Moneris Enhanced Gateway
 *
 * @since 1.0.0
 */
class Moneris_Blocks_Support extends AbstractPaymentMethodType {

    /**
     * Payment method name/slug
     *
     * @var string
     */
    protected $name = 'moneris_enhanced';

    /**
     * Settings from the gateway
     *
     * @var array
     */
    protected $settings = array();

    /**
     * Constructor
     */
    public function __construct() {
        // Only proceed if we have the required parent class
        if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
            return;
        }

        // Load gateway settings
        $this->settings = get_option( 'woocommerce_moneris_enhanced_settings', array() );
    }

    /**
     * Initialize the payment method
     */
    public function initialize() {
        // Load settings from the payment gateway
        $this->settings = get_option( 'woocommerce_moneris_enhanced_settings', array() );
    }

    /**
     * Returns if the payment method is active
     *
     * @return boolean
     */
    public function is_active() {
        $gateway = WC()->payment_gateways->payment_gateways()[ $this->name ] ?? null;
        return $gateway && 'yes' === $gateway->enabled;
    }

    /**
     * Returns an array of script handles for the payment method
     *
     * @return array
     */
    public function get_payment_method_script_handles() {
        // Register the block script if not already registered
        if ( ! wp_script_is( 'moneris-enhanced-blocks', 'registered' ) ) {
            $asset_path = MONERIS_PLUGIN_DIR . 'assets/js/blocks.js';
            $asset_url = MONERIS_PLUGIN_URL . 'assets/js/blocks.js';

            // Only register if the file exists
            if ( file_exists( $asset_path ) ) {
                wp_register_script(
                    'moneris-enhanced-blocks',
                    $asset_url,
                    array(
                        'wc-blocks-checkout',
                        'wc-settings',
                        'wp-element',
                        'wp-i18n',
                        'wp-components',
                        'wp-blocks',
                    ),
                    MONERIS_VERSION,
                    true
                );

                // Add inline script with settings
                wp_add_inline_script(
                    'moneris-enhanced-blocks',
                    'const moneris_enhanced_params = ' . wp_json_encode( $this->get_payment_method_data() ),
                    'before'
                );
            }
        }

        return array( 'moneris-enhanced-blocks' );
    }

    /**
     * Returns an array of data for the payment method
     *
     * @return array
     */
    public function get_payment_method_data() {
        $gateway = WC()->payment_gateways->payment_gateways()[ $this->name ] ?? null;

        if ( ! $gateway ) {
            return array(
                'title'       => __( 'Credit Card (Moneris)', 'moneris-enhanced-gateway-for-woocommerce' ),
                'description' => __( 'Pay securely with your credit card through Moneris.', 'moneris-enhanced-gateway-for-woocommerce' ),
                'supports'    => array(),
            );
        }

        return array(
            'title'            => $gateway->get_title(),
            'description'      => $gateway->get_description(),
            'supports'         => $gateway->supports,
            'show_saved_cards' => $gateway->supports( 'tokenization' ) && is_user_logged_in(),
            'test_mode'        => $gateway->is_test_mode(),
            'icons'            => $this->get_payment_method_icons(),
            'hpp_enabled'      => isset( $this->settings['hosted_tokenization'] ) && 'yes' === $this->settings['hosted_tokenization'],
            'ajax_url'         => admin_url( 'admin-ajax.php' ),
            'nonce'            => wp_create_nonce( 'moneris-payment-nonce' ),
            'i18n'             => array(
                'card_number'      => __( 'Card Number', 'moneris-enhanced-gateway-for-woocommerce' ),
                'expiry_date'      => __( 'Expiry Date', 'moneris-enhanced-gateway-for-woocommerce' ),
                'cvc'              => __( 'CVC', 'moneris-enhanced-gateway-for-woocommerce' ),
                'save_card'        => __( 'Save payment method to my account', 'moneris-enhanced-gateway-for-woocommerce' ),
                'processing'       => __( 'Processing...', 'moneris-enhanced-gateway-for-woocommerce' ),
                'card_number_invalid' => __( 'Card number is invalid', 'moneris-enhanced-gateway-for-woocommerce' ),
                'expiry_invalid'   => __( 'Expiry date is invalid', 'moneris-enhanced-gateway-for-woocommerce' ),
                'cvc_invalid'      => __( 'Security code is invalid', 'moneris-enhanced-gateway-for-woocommerce' ),
                'generic_error'    => __( 'An error occurred. Please try again.', 'moneris-enhanced-gateway-for-woocommerce' ),
            ),
        );
    }

    /**
     * Get payment method icons
     *
     * @return array
     */
    private function get_payment_method_icons() {
        $icons = array();
        $icon_types = array( 'visa', 'mastercard', 'amex' );

        foreach ( $icon_types as $type ) {
            $icon_url = MONERIS_PLUGIN_URL . 'assets/images/cards/' . $type . '.svg';
            $icons[ $type ] = array(
                'src' => $icon_url,
                'alt' => ucfirst( $type ),
            );
        }

        return $icons;
    }

    /**
     * Check if blocks are supported
     *
     * @return bool
     */
    public static function is_supported() {
        return class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' );
    }

    /**
     * Register the payment method type with blocks
     *
     * @param object $payment_method_registry Payment method registry.
     */
    public static function register( $payment_method_registry ) {
        // Only register if blocks are supported
        if ( ! self::is_supported() ) {
            return;
        }

        // Only register if the gateway is available
        $gateways = WC()->payment_gateways->payment_gateways();
        if ( ! isset( $gateways['moneris_enhanced'] ) ) {
            return;
        }

        $payment_method_registry->register( new self() );
    }
}