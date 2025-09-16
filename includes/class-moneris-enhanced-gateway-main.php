<?php
/**
 * Main plugin class
 *
 * @package MonerisEnhancedGateway
 */

namespace Moneris_Enhanced_Gateway;

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Main plugin class - Singleton pattern
 *
 * @since 1.0.0
 */
final class Moneris_Enhanced_Gateway_Main {

    /**
     * Plugin instance
     *
     * @var Moneris_Enhanced_Gateway_Main|null
     */
    private static $instance = null;

    /**
     * Admin instance
     *
     * @var Admin\Moneris_Admin|null
     */
    public $admin = null;

    /**
     * Gateway instance
     *
     * @var Gateways\Moneris_Gateway|null
     */
    public $gateway = null;

    /**
     * API instance
     *
     * @var Api\Moneris_API|null
     */
    public $api = null;

    /**
     * Logger instance
     *
     * @var Utils\Moneris_Logger|null
     */
    public $logger = null;

    /**
     * WooCommerce Integration instance
     *
     * @var Moneris_WooCommerce_Integration|null
     */
    public $woocommerce_integration = null;

    /**
     * Plugin activation status
     *
     * @var bool
     */
    private $is_activated = false;

    /**
     * Get plugin instance
     *
     * @return Moneris_Enhanced_Gateway_Main
     */
    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->init();
    }

    /**
     * Initialize plugin
     */
    private function init() {
        // Check dependencies
        if ( ! $this->check_dependencies() ) {
            return;
        }

        // Initialize components
        $this->init_hooks();
        $this->init_components();

        // Set activation flag
        $this->is_activated = true;
    }

    /**
     * Check plugin dependencies
     *
     * @return bool
     */
    private function check_dependencies() {
        // Initialize WooCommerce integration
        $this->woocommerce_integration = Moneris_WooCommerce_Integration::get_instance();

        // Check if WooCommerce is active
        if ( ! $this->woocommerce_integration->is_woocommerce_active() ) {
            add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
            return false;
        }

        // Check WooCommerce version
        if ( ! $this->check_woocommerce_version() ) {
            add_action( 'admin_notices', array( $this, 'woocommerce_version_notice' ) );
            return false;
        }

        return true;
    }

    /**
     * Check WooCommerce version
     *
     * @return bool
     */
    public function check_woocommerce_version() {
        if ( ! defined( 'WC_VERSION' ) ) {
            return false;
        }

        // Check for minimum WooCommerce 7.1
        return version_compare( WC_VERSION, '7.1', '>=' );
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Load text domain for translations - moved to init for proper loading
        add_action( 'init', array( $this, 'load_textdomain' ), 1 );

        // Gateway registration is now handled by WooCommerce integration class
        // add_filter( 'woocommerce_payment_gateways', array( $this, 'add_gateway' ) );

        // Enqueue scripts and styles
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_scripts' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );

        // Add plugin action links
        add_filter( 'plugin_action_links_' . MONERIS_PLUGIN_BASENAME, array( $this, 'plugin_action_links' ) );

        // AJAX handlers
        add_action( 'wp_ajax_moneris_process_payment', array( $this, 'ajax_process_payment' ) );
        add_action( 'wp_ajax_nopriv_moneris_process_payment', array( $this, 'ajax_process_payment' ) );

        // Webhook handler
        add_action( 'woocommerce_api_moneris_webhook', array( $this, 'webhook_handler' ) );

        // Add custom order status for pending Moneris payments
        add_action( 'init', array( $this, 'register_custom_order_status' ) );
        add_filter( 'wc_order_statuses', array( $this, 'add_custom_order_status' ) );
    }

    /**
     * Initialize plugin components
     */
    private function init_components() {
        // Initialize logger
        if ( class_exists( 'Moneris_Enhanced_Gateway\Utils\Moneris_Logger' ) ) {
            $this->logger = new Utils\Moneris_Logger();
        }

        // Initialize API handler
        if ( class_exists( 'Moneris_Enhanced_Gateway\Api\Moneris_API' ) ) {
            $this->api = new Api\Moneris_API();
        }

        // Initialize admin if in admin area
        if ( is_admin() && class_exists( 'Moneris_Enhanced_Gateway\Admin\Moneris_Admin' ) ) {
            $this->admin = new Admin\Moneris_Admin();
        }

        // Initialize gateway
        add_action( 'plugins_loaded', array( $this, 'init_gateway' ), 11 );
    }

    /**
     * Initialize payment gateway
     */
    public function init_gateway() {
        if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
            return;
        }

        // Include gateway class
        if ( class_exists( 'Moneris_Enhanced_Gateway\Gateways\Moneris_Gateway' ) ) {
            $this->gateway = new Gateways\Moneris_Gateway();
        }
    }

    /**
     * Add gateway to WooCommerce
     * Note: This is now handled by the WooCommerce integration class
     *
     * @deprecated 1.0.0 Use WooCommerce integration class instead
     * @param array $gateways Payment gateways.
     * @return array
     */
    public function add_gateway( $gateways ) {
        // Handled by WooCommerce integration class
        return $gateways;
    }

    /**
     * Load plugin text domain
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            MONERIS_TEXT_DOMAIN,
            false,
            dirname( MONERIS_PLUGIN_BASENAME ) . '/languages/'
        );
    }

    /**
     * Enqueue frontend scripts and styles
     */
    public function enqueue_frontend_scripts() {
        if ( ! is_checkout() && ! is_order_received_page() ) {
            return;
        }

        // Enqueue styles
        wp_enqueue_style(
            'moneris-enhanced-gateway',
            MONERIS_PLUGIN_URL . 'assets/css/moneris-frontend.css',
            array(),
            MONERIS_VERSION
        );

        // Enqueue scripts
        wp_enqueue_script(
            'moneris-enhanced-gateway',
            MONERIS_PLUGIN_URL . 'assets/js/moneris-frontend.js',
            array( 'jquery', 'wc-checkout' ),
            MONERIS_VERSION,
            true
        );

        // Localize script
        wp_localize_script(
            'moneris-enhanced-gateway',
            'moneris_params',
            array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'moneris-payment-nonce' ),
                'i18n'     => array(
                    'processing' => __( 'Processing payment...', 'moneris-enhanced-gateway-for-woocommerce' ),
                    'error'      => __( 'An error occurred. Please try again.', 'moneris-enhanced-gateway-for-woocommerce' ),
                ),
            )
        );
    }

    /**
     * Enqueue admin scripts and styles
     *
     * @param string $hook Admin page hook.
     */
    public function enqueue_admin_scripts( $hook ) {
        // Only load on WooCommerce settings pages
        if ( 'woocommerce_page_wc-settings' !== $hook ) {
            return;
        }

        // Check if we're on the checkout/payment tab
        if ( ! isset( $_GET['tab'] ) || 'checkout' !== $_GET['tab'] ) {
            return;
        }

        // Enqueue admin styles
        wp_enqueue_style(
            'moneris-enhanced-gateway-admin',
            MONERIS_PLUGIN_URL . 'assets/css/moneris-admin.css',
            array(),
            MONERIS_VERSION
        );

        // Enqueue admin scripts
        wp_enqueue_script(
            'moneris-enhanced-gateway-admin',
            MONERIS_PLUGIN_URL . 'assets/js/moneris-admin.js',
            array( 'jquery' ),
            MONERIS_VERSION,
            true
        );

        // Localize script
        wp_localize_script(
            'moneris-enhanced-gateway-admin',
            'moneris_admin_params',
            array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'moneris-admin-nonce' ),
            )
        );
    }

    /**
     * Add plugin action links
     *
     * @param array $links Plugin action links.
     * @return array
     */
    public function plugin_action_links( $links ) {
        $plugin_links = array(
            '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=moneris_enhanced_gateway' ) . '">' . __( 'Settings', 'moneris-enhanced-gateway-for-woocommerce' ) . '</a>',
            '<a href="https://yourwebsite.com/documentation" target="_blank">' . __( 'Documentation', 'moneris-enhanced-gateway-for-woocommerce' ) . '</a>',
        );

        return array_merge( $plugin_links, $links );
    }

    /**
     * AJAX payment processing handler
     */
    public function ajax_process_payment() {
        // Verify nonce
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'moneris-payment-nonce' ) ) {
            wp_send_json_error( __( 'Security verification failed.', 'moneris-enhanced-gateway-for-woocommerce' ) );
        }

        // Process payment logic here
        // This will be implemented when integrating with Moneris API

        wp_send_json_success();
    }

    /**
     * Webhook handler for Moneris notifications
     */
    public function webhook_handler() {
        // Log webhook received
        if ( $this->logger ) {
            $this->logger->log( 'Webhook received from Moneris' );
        }

        // Process webhook
        // This will be implemented when integrating with Moneris API

        status_header( 200 );
        exit;
    }

    /**
     * Register custom order status
     */
    public function register_custom_order_status() {
        register_post_status( 'wc-moneris-pending', array(
            'label'                     => __( 'Moneris Pending', 'moneris-enhanced-gateway-for-woocommerce' ),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop( 'Moneris Pending (%s)', 'Moneris Pending (%s)', 'moneris-enhanced-gateway-for-woocommerce' ),
        ) );
    }

    /**
     * Add custom order status to WooCommerce
     *
     * @param array $order_statuses Order statuses.
     * @return array
     */
    public function add_custom_order_status( $order_statuses ) {
        $order_statuses['wc-moneris-pending'] = __( 'Moneris Pending', 'moneris-enhanced-gateway-for-woocommerce' );
        return $order_statuses;
    }

    /**
     * WooCommerce missing notice
     */
    public function woocommerce_missing_notice() {
        // Check if WooCommerce can be activated
        $can_activate = file_exists( WP_PLUGIN_DIR . '/woocommerce/woocommerce.php' );
        ?>
        <div class="notice notice-error">
            <p>
                <strong><?php esc_html_e( 'Moneris Enhanced Payment Gateway', 'moneris-enhanced-gateway-for-woocommerce' ); ?></strong>
                <?php esc_html_e( 'requires WooCommerce to be installed and active.', 'moneris-enhanced-gateway-for-woocommerce' ); ?>

                <?php if ( $can_activate && current_user_can( 'activate_plugins' ) ) : ?>
                    <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'plugins.php?action=activate&plugin=woocommerce/woocommerce.php' ), 'activate-plugin_woocommerce/woocommerce.php' ) ); ?>" class="button button-primary">
                        <?php esc_html_e( 'Activate WooCommerce', 'moneris-enhanced-gateway-for-woocommerce' ); ?>
                    </a>
                <?php elseif ( current_user_can( 'install_plugins' ) ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'plugin-install.php?s=woocommerce&tab=search&type=term' ) ); ?>" class="button button-primary">
                        <?php esc_html_e( 'Install WooCommerce', 'moneris-enhanced-gateway-for-woocommerce' ); ?>
                    </a>
                <?php endif; ?>
            </p>
        </div>
        <?php
    }

    /**
     * WooCommerce version notice
     */
    public function woocommerce_version_notice() {
        ?>
        <div class="notice notice-warning">
            <p>
                <strong><?php esc_html_e( 'Moneris Enhanced Payment Gateway', 'moneris-enhanced-gateway-for-woocommerce' ); ?></strong>
                <?php
                printf(
                    /* translators: %1$s: Required WooCommerce version, %2$s: Current WooCommerce version */
                    esc_html__( 'requires WooCommerce %1$s or later. You are running WooCommerce %2$s.', 'moneris-enhanced-gateway-for-woocommerce' ),
                    '7.1',
                    esc_html( WC_VERSION )
                );
                ?>

                <?php if ( current_user_can( 'update_plugins' ) ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'update-core.php' ) ); ?>" class="button button-primary">
                        <?php esc_html_e( 'Update WooCommerce', 'moneris-enhanced-gateway-for-woocommerce' ); ?>
                    </a>
                <?php endif; ?>
            </p>
        </div>
        <?php
    }

    /**
     * Plugin activation
     */
    public static function activate() {
        // Check WooCommerce dependency before activation
        if ( ! class_exists( 'WooCommerce' ) ) {
            wp_die(
                esc_html__( 'Moneris Enhanced Payment Gateway requires WooCommerce to be installed and activated.', 'moneris-enhanced-gateway-for-woocommerce' ),
                esc_html__( 'Plugin Activation Error', 'moneris-enhanced-gateway-for-woocommerce' ),
                array( 'back_link' => true )
            );
        }

        // Create database tables if needed
        self::create_tables();

        // Set default options
        self::set_default_options();

        // Clear permalinks
        flush_rewrite_rules();

        // Log activation
        if ( class_exists( 'Moneris_Enhanced_Gateway\Utils\Moneris_Logger' ) ) {
            $logger = new Utils\Moneris_Logger();
            $logger->log( 'Plugin activated' );
        }

        // Set activation flag
        update_option( 'moneris_enhanced_gateway_activated', true );
    }

    /**
     * Plugin deactivation
     */
    public static function deactivate() {
        // Clear scheduled hooks
        wp_clear_scheduled_hook( 'moneris_cleanup_logs' );
        wp_clear_scheduled_hook( 'moneris_check_pending_captures' );
        wp_clear_scheduled_hook( 'moneris_sync_transactions' );

        // Clear permalinks
        flush_rewrite_rules();

        // Clear transients
        delete_transient( 'moneris_gateway_status' );
        delete_transient( 'moneris_api_test_result' );

        // Log deactivation
        if ( class_exists( 'Moneris_Enhanced_Gateway\Utils\Moneris_Logger' ) ) {
            $logger = new Utils\Moneris_Logger();
            $logger->log( 'Plugin deactivated' );
        }

        // Remove activation flag
        delete_option( 'moneris_enhanced_gateway_activated' );
    }

    /**
     * Plugin uninstall
     */
    public static function uninstall() {
        // Remove plugin options
        delete_option( 'moneris_enhanced_gateway_settings' );
        delete_option( 'moneris_enhanced_gateway_version' );

        // Remove database tables if configured to do so
        if ( get_option( 'moneris_remove_data_on_uninstall', false ) ) {
            self::drop_tables();
        }
    }

    /**
     * Create database tables
     */
    private static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Transaction log table
        $table_name = $wpdb->prefix . 'moneris_transactions';

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            order_id bigint(20) NOT NULL,
            transaction_id varchar(255) NOT NULL,
            transaction_type varchar(50) NOT NULL,
            amount decimal(10,2) NOT NULL,
            currency varchar(3) NOT NULL,
            status varchar(50) NOT NULL,
            response_code varchar(10),
            response_message text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY transaction_id (transaction_id)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );

        // Update version option
        update_option( 'moneris_enhanced_gateway_version', MONERIS_VERSION );
    }

    /**
     * Drop database tables
     */
    private static function drop_tables() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'moneris_transactions';
        $wpdb->query( "DROP TABLE IF EXISTS $table_name" );
    }

    /**
     * Set default plugin options
     */
    private static function set_default_options() {
        $default_settings = array(
            'enabled' => 'no',
            'title' => __( 'Credit Card (Moneris)', 'moneris-enhanced-gateway-for-woocommerce' ),
            'description' => __( 'Pay securely with your credit card through Moneris.', 'moneris-enhanced-gateway-for-woocommerce' ),
            'testmode' => 'yes',
            'debug' => 'yes',
            'store_id' => '',
            'api_token' => '',
            'hosted_tokenization' => 'yes',
            'save_cards' => 'yes',
            'capture' => 'yes',
            'avs' => 'no',
            'cvd' => 'yes',
        );

        if ( false === get_option( 'moneris_enhanced_gateway_settings' ) ) {
            add_option( 'moneris_enhanced_gateway_settings', $default_settings );
        }
    }

    /**
     * Prevent cloning
     */
    private function __clone() {}

    /**
     * Prevent unserializing
     */
    public function __wakeup() {
        throw new \Exception( 'Cannot unserialize singleton' );
    }
}