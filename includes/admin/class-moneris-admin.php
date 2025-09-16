<?php
/**
 * Admin functionality
 *
 * @package MonerisEnhancedGateway
 */

namespace Moneris_Enhanced_Gateway\Admin;

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin class
 *
 * @since 1.0.0
 */
class Moneris_Admin {

    /**
     * Constructor
     */
    public function __construct() {
        $this->init();
    }

    /**
     * Initialize admin functionality
     */
    private function init() {
        // Add admin menu
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ), 99 );

        // Add settings link to plugins page
        add_filter( 'plugin_action_links_' . MONERIS_PLUGIN_BASENAME, array( $this, 'add_settings_link' ) );

        // Admin notices
        add_action( 'admin_notices', array( $this, 'admin_notices' ) );
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __( 'Moneris Gateway', 'moneris-enhanced-gateway-for-woocommerce' ),
            __( 'Moneris Gateway', 'moneris-enhanced-gateway-for-woocommerce' ),
            'manage_woocommerce',
            'moneris-gateway',
            array( $this, 'admin_page' )
        );
    }

    /**
     * Admin page content
     */
    public function admin_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            <p><?php esc_html_e( 'Configure your Moneris payment gateway settings in WooCommerce settings.', 'moneris-enhanced-gateway-for-woocommerce' ); ?></p>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=moneris_enhanced_gateway' ) ); ?>" class="button button-primary">
                <?php esc_html_e( 'Go to Settings', 'moneris-enhanced-gateway-for-woocommerce' ); ?>
            </a>
        </div>
        <?php
    }

    /**
     * Add settings link to plugins page
     *
     * @param array $links Existing links.
     * @return array
     */
    public function add_settings_link( $links ) {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            admin_url( 'admin.php?page=wc-settings&tab=checkout&section=moneris_enhanced_gateway' ),
            __( 'Settings', 'moneris-enhanced-gateway-for-woocommerce' )
        );
        array_unshift( $links, $settings_link );
        return $links;
    }

    /**
     * Display admin notices
     */
    public function admin_notices() {
        // Check for SSL in production
        if ( ! is_ssl() && 'yes' !== get_option( 'woocommerce_moneris_enhanced_gateway_settings' )['testmode'] ) {
            ?>
            <div class="notice notice-warning">
                <p>
                    <strong><?php esc_html_e( 'Moneris Gateway:', 'moneris-enhanced-gateway-for-woocommerce' ); ?></strong>
                    <?php esc_html_e( 'SSL is required for production mode. Please enable SSL on your site.', 'moneris-enhanced-gateway-for-woocommerce' ); ?>
                </p>
            </div>
            <?php
        }
    }
}