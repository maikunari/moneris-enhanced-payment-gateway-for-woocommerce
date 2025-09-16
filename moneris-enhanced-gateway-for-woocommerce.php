<?php
/**
 * Plugin Name: Moneris Enhanced Payment Gateway for WooCommerce
 * Plugin URI: https://yourwebsite.com/moneris-enhanced-gateway
 * Description: Secure Canadian payment processing with Moneris Hosted Tokenization
 * Version: 1.0.0
 * Author: [Your Name]
 * Author URI: https://yourwebsite.com
 * Text Domain: moneris-enhanced-gateway-for-woocommerce
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * WC requires at least: 7.0
 * WC tested up to: 8.5
 *
 * @package MonerisEnhancedGateway
 */

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// Define plugin constants
define( 'MONERIS_VERSION', '1.0.0' );
define( 'MONERIS_PLUGIN_FILE', __FILE__ );
define( 'MONERIS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MONERIS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MONERIS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'MONERIS_TEXT_DOMAIN', 'moneris-enhanced-gateway-for-woocommerce' );

// Minimum requirements
define( 'MONERIS_MIN_PHP_VERSION', '8.0' );
define( 'MONERIS_MIN_WP_VERSION', '6.0' );
define( 'MONERIS_MIN_WC_VERSION', '7.0' );

/**
 * Check if system requirements are met
 *
 * @return bool
 */
function moneris_check_requirements() {
    $errors = array();

    // Check PHP version
    if ( version_compare( PHP_VERSION, MONERIS_MIN_PHP_VERSION, '<' ) ) {
        $errors[] = sprintf(
            /* translators: %1$s: Required PHP version, %2$s: Current PHP version */
            __( 'Moneris Enhanced Gateway requires PHP %1$s or later. Your site is running PHP %2$s.', 'moneris-enhanced-gateway-for-woocommerce' ),
            MONERIS_MIN_PHP_VERSION,
            PHP_VERSION
        );
    }

    // Check WordPress version
    global $wp_version;
    if ( version_compare( $wp_version, MONERIS_MIN_WP_VERSION, '<' ) ) {
        $errors[] = sprintf(
            /* translators: %1$s: Required WordPress version, %2$s: Current WordPress version */
            __( 'Moneris Enhanced Gateway requires WordPress %1$s or later. Your site is running WordPress %2$s.', 'moneris-enhanced-gateway-for-woocommerce' ),
            MONERIS_MIN_WP_VERSION,
            $wp_version
        );
    }

    // Check if WooCommerce is installed and active
    if ( ! class_exists( 'WooCommerce' ) ) {
        $errors[] = __( 'Moneris Enhanced Gateway requires WooCommerce to be installed and active.', 'moneris-enhanced-gateway-for-woocommerce' );
    } elseif ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, MONERIS_MIN_WC_VERSION, '<' ) ) {
        $errors[] = sprintf(
            /* translators: %1$s: Required WooCommerce version, %2$s: Current WooCommerce version */
            __( 'Moneris Enhanced Gateway requires WooCommerce %1$s or later. Your site is running WooCommerce %2$s.', 'moneris-enhanced-gateway-for-woocommerce' ),
            MONERIS_MIN_WC_VERSION,
            WC_VERSION
        );
    }

    // Display errors if any
    if ( ! empty( $errors ) ) {
        add_action( 'admin_notices', function() use ( $errors ) {
            foreach ( $errors as $error ) {
                ?>
                <div class="notice notice-error">
                    <p><?php echo esc_html( $error ); ?></p>
                </div>
                <?php
            }
        } );
        return false;
    }

    return true;
}

// Include the autoloader
require_once MONERIS_PLUGIN_DIR . 'includes/class-moneris-autoloader.php';

// Initialize the autoloader
Moneris_Enhanced_Gateway\Moneris_Autoloader::init();

/**
 * Main plugin class instance
 *
 * @return Moneris_Enhanced_Gateway_Main
 */
function moneris_enhanced_gateway() {
    return Moneris_Enhanced_Gateway\Moneris_Enhanced_Gateway_Main::instance();
}

// Hook into plugins_loaded to initialize the plugin
add_action( 'plugins_loaded', function() {
    // Load text domain early for translation support
    load_plugin_textdomain(
        'moneris-enhanced-gateway-for-woocommerce',
        false,
        dirname( plugin_basename( __FILE__ ) ) . '/languages/'
    );

    if ( moneris_check_requirements() ) {
        moneris_enhanced_gateway();
    }
}, 10 );

// Register activation hook
register_activation_hook( __FILE__, array( 'Moneris_Enhanced_Gateway\Moneris_Enhanced_Gateway_Main', 'activate' ) );

// Register deactivation hook
register_deactivation_hook( __FILE__, array( 'Moneris_Enhanced_Gateway\Moneris_Enhanced_Gateway_Main', 'deactivate' ) );

// Uninstall is now handled by uninstall.php for better cleanup
// register_uninstall_hook( __FILE__, array( 'Moneris_Enhanced_Gateway\Moneris_Enhanced_Gateway_Main', 'uninstall' ) );

// Declare compatibility with WooCommerce HPOS (High Performance Order Storage)
// This is now handled by the WooCommerce integration class for better organization
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
    }
} );

// Handle WooCommerce deactivation
add_action( 'deactivated_plugin', function( $plugin ) {
    if ( $plugin === 'woocommerce/woocommerce.php' ) {
        // Check if our plugin is active
        if ( is_plugin_active( plugin_basename( __FILE__ ) ) ) {
            // Deactivate our plugin if WooCommerce is deactivated
            deactivate_plugins( plugin_basename( __FILE__ ) );

            // Add admin notice about the deactivation
            add_action( 'admin_notices', function() {
                ?>
                <div class="notice notice-warning is-dismissible">
                    <p>
                        <?php
                        esc_html_e(
                            'Moneris Enhanced Payment Gateway has been deactivated because WooCommerce is no longer active.',
                            'moneris-enhanced-gateway-for-woocommerce'
                        );
                        ?>
                    </p>
                </div>
                <?php
            } );
        }
    }
}, 10, 1 );