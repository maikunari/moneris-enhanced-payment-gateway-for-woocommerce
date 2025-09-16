<?php
/**
 * WooCommerce Integration Handler
 *
 * @package MonerisEnhancedGateway
 * @since 1.0.0
 */

namespace Moneris_Enhanced_Gateway;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Automattic\WooCommerce\Admin\Features\OnboardingTasks\TaskLists;

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * WooCommerce Integration Class
 *
 * Handles all WooCommerce-specific integrations and compatibility checks
 *
 * @since 1.0.0
 */
class Moneris_WooCommerce_Integration {

    /**
     * Minimum WooCommerce version required
     *
     * @var string
     */
    const MIN_WC_VERSION = '7.1';

    /**
     * Instance of this class
     *
     * @var Moneris_WooCommerce_Integration|null
     */
    private static $instance = null;

    /**
     * Compatibility status
     *
     * @var array
     */
    private $compatibility_status = array();

    /**
     * WooCommerce active status
     *
     * @var bool
     */
    private $woocommerce_active = false;

    /**
     * Get instance
     *
     * @return Moneris_WooCommerce_Integration
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
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
     * Initialize integration
     */
    private function init() {
        // Check if WooCommerce is active
        $this->woocommerce_active = $this->is_woocommerce_active();

        if ( ! $this->woocommerce_active ) {
            add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
            return;
        }

        // Check version compatibility
        if ( ! $this->is_woocommerce_version_compatible() ) {
            add_action( 'admin_notices', array( $this, 'woocommerce_version_notice' ) );
            return;
        }

        // Initialize WooCommerce integration
        $this->init_woocommerce_hooks();
    }

    /**
     * Initialize WooCommerce hooks
     */
    private function init_woocommerce_hooks() {
        // Register payment gateway
        add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateway' ), 10 );

        // HPOS compatibility declaration
        add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );

        // Block support preparation
        add_action( 'woocommerce_blocks_loaded', array( $this, 'register_block_support' ) );

        // Order status change hooks for capture triggers
        add_action( 'woocommerce_order_status_changed', array( $this, 'handle_order_status_change' ), 10, 4 );

        // Add order meta display in admin
        add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'display_order_meta' ) );

        // Checkout validation
        add_action( 'woocommerce_checkout_process', array( $this, 'validate_checkout' ) );

        // Add payment method to customer
        add_action( 'woocommerce_payment_token_added_to_order', array( $this, 'save_payment_token' ), 10, 4 );

        // Handle subscription renewals
        add_action( 'woocommerce_scheduled_subscription_payment_moneris_enhanced_gateway', array( $this, 'process_subscription_payment' ), 10, 2 );

        // Add gateway settings link
        add_filter( 'woocommerce_get_settings_checkout', array( $this, 'add_settings_section' ), 10, 2 );

        // Handle refund status
        add_action( 'woocommerce_order_refunded', array( $this, 'handle_refund' ), 10, 2 );

        // Cart and checkout blocks compatibility
        add_action( 'woocommerce_blocks_payment_method_type_registration', array( $this, 'register_block_payment_method' ) );

        // Add custom order statuses
        add_action( 'init', array( $this, 'register_order_statuses' ) );
        add_filter( 'wc_order_statuses', array( $this, 'add_order_statuses' ) );

        // Handle webhook endpoint
        add_action( 'woocommerce_api_moneris_webhook', array( $this, 'handle_webhook' ) );

        // Admin enqueue scripts for order page
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_order_scripts' ) );

        // Add capture button to order actions
        add_action( 'woocommerce_order_actions', array( $this, 'add_capture_order_action' ) );
        add_action( 'woocommerce_order_action_moneris_capture_payment', array( $this, 'process_capture_action' ) );

        // Handle order meta box
        add_action( 'add_meta_boxes', array( $this, 'add_order_meta_box' ), 10, 2 );

        // Payment method title filter
        add_filter( 'woocommerce_gateway_title', array( $this, 'filter_payment_method_title' ), 10, 2 );

        // Email hooks
        add_action( 'woocommerce_email_before_order_table', array( $this, 'add_payment_info_to_email' ), 10, 3 );
    }

    /**
     * Check if WooCommerce is active
     *
     * @return bool
     */
    public function is_woocommerce_active() {
        if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ), true ) ) {
            return true;
        }

        if ( is_multisite() ) {
            $plugins = get_site_option( 'active_sitewide_plugins' );
            if ( isset( $plugins['woocommerce/woocommerce.php'] ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if WooCommerce version is compatible
     *
     * @return bool
     */
    public function is_woocommerce_version_compatible() {
        if ( ! defined( 'WC_VERSION' ) ) {
            return false;
        }

        return version_compare( WC_VERSION, self::MIN_WC_VERSION, '>=' );
    }

    /**
     * Register payment gateway
     *
     * @param array $gateways Existing gateways.
     * @return array
     */
    public function register_gateway( $gateways ) {
        // Only register if all compatibility checks pass
        if ( $this->check_full_compatibility() ) {
            // Register the new enhanced gateway class
            $gateways[] = 'Moneris_Enhanced_Gateway\Gateways\WC_Gateway_Moneris_Enhanced';
        }

        return $gateways;
    }

    /**
     * Declare HPOS compatibility
     */
    public function declare_hpos_compatibility() {
        if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
            FeaturesUtil::declare_compatibility( 'custom_order_tables', MONERIS_PLUGIN_FILE, true );
            FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', MONERIS_PLUGIN_FILE, true );
        }
    }

    /**
     * Register block support
     */
    public function register_block_support() {
        if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
            require_once MONERIS_PLUGIN_DIR . 'includes/blocks/class-moneris-blocks-support.php';

            add_action(
                'woocommerce_blocks_payment_method_type_registration',
                function( $payment_method_registry ) {
                    $payment_method_registry->register( new Blocks\Moneris_Blocks_Support() );
                }
            );
        }
    }

    /**
     * Handle order status change
     *
     * @param int    $order_id    Order ID.
     * @param string $old_status  Old status.
     * @param string $new_status  New status.
     * @param object $order       Order object.
     */
    public function handle_order_status_change( $order_id, $old_status, $new_status, $order ) {
        // Check if this order was paid with Moneris
        if ( $order->get_payment_method() !== 'moneris_enhanced_gateway' ) {
            return;
        }

        // Handle capture on status change to processing or completed
        if ( in_array( $new_status, array( 'processing', 'completed' ), true ) &&
             $old_status === 'on-hold' ) {

            // Check if we need to capture payment
            $captured = $order->get_meta( '_moneris_payment_captured' );

            if ( ! $captured ) {
                $this->capture_payment( $order );
            }
        }

        // Log status change
        $order->add_order_note(
            sprintf(
                __( 'Moneris Gateway: Order status changed from %1$s to %2$s', 'moneris-enhanced-gateway-for-woocommerce' ),
                $old_status,
                $new_status
            )
        );
    }

    /**
     * Display order meta in admin
     *
     * @param WC_Order $order Order object.
     */
    public function display_order_meta( $order ) {
        if ( $order->get_payment_method() !== 'moneris_enhanced_gateway' ) {
            return;
        }

        $transaction_id = $order->get_meta( '_moneris_transaction_id' );
        $receipt_id = $order->get_meta( '_moneris_receipt_id' );
        $reference_num = $order->get_meta( '_moneris_reference_num' );
        $auth_code = $order->get_meta( '_moneris_auth_code' );
        $captured = $order->get_meta( '_moneris_payment_captured' );
        $card_type = $order->get_meta( '_moneris_card_type' );
        $last_four = $order->get_meta( '_moneris_card_last_four' );

        ?>
        <div class="moneris-order-meta">
            <h3><?php esc_html_e( 'Moneris Payment Details', 'moneris-enhanced-gateway-for-woocommerce' ); ?></h3>
            <table class="wp-list-table widefat fixed striped">
                <?php if ( $transaction_id ) : ?>
                    <tr>
                        <td><strong><?php esc_html_e( 'Transaction ID:', 'moneris-enhanced-gateway-for-woocommerce' ); ?></strong></td>
                        <td><?php echo esc_html( $transaction_id ); ?></td>
                    </tr>
                <?php endif; ?>

                <?php if ( $receipt_id ) : ?>
                    <tr>
                        <td><strong><?php esc_html_e( 'Receipt ID:', 'moneris-enhanced-gateway-for-woocommerce' ); ?></strong></td>
                        <td><?php echo esc_html( $receipt_id ); ?></td>
                    </tr>
                <?php endif; ?>

                <?php if ( $reference_num ) : ?>
                    <tr>
                        <td><strong><?php esc_html_e( 'Reference Number:', 'moneris-enhanced-gateway-for-woocommerce' ); ?></strong></td>
                        <td><?php echo esc_html( $reference_num ); ?></td>
                    </tr>
                <?php endif; ?>

                <?php if ( $auth_code ) : ?>
                    <tr>
                        <td><strong><?php esc_html_e( 'Authorization Code:', 'moneris-enhanced-gateway-for-woocommerce' ); ?></strong></td>
                        <td><?php echo esc_html( $auth_code ); ?></td>
                    </tr>
                <?php endif; ?>

                <?php if ( $card_type && $last_four ) : ?>
                    <tr>
                        <td><strong><?php esc_html_e( 'Card:', 'moneris-enhanced-gateway-for-woocommerce' ); ?></strong></td>
                        <td><?php echo esc_html( $card_type . ' ****' . $last_four ); ?></td>
                    </tr>
                <?php endif; ?>

                <tr>
                    <td><strong><?php esc_html_e( 'Payment Status:', 'moneris-enhanced-gateway-for-woocommerce' ); ?></strong></td>
                    <td>
                        <?php if ( $captured ) : ?>
                            <span class="moneris-status captured"><?php esc_html_e( 'Captured', 'moneris-enhanced-gateway-for-woocommerce' ); ?></span>
                        <?php else : ?>
                            <span class="moneris-status authorized"><?php esc_html_e( 'Authorized Only', 'moneris-enhanced-gateway-for-woocommerce' ); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
        </div>
        <?php
    }

    /**
     * Validate checkout
     */
    public function validate_checkout() {
        if ( ! isset( $_POST['payment_method'] ) || $_POST['payment_method'] !== 'moneris_enhanced_gateway' ) {
            return;
        }

        // Custom validation logic here
        $gateway = new Gateways\Moneris_Gateway();

        // Check if gateway is properly configured
        if ( ! $gateway->is_available() ) {
            wc_add_notice(
                __( 'Moneris payment gateway is not available. Please choose another payment method.', 'moneris-enhanced-gateway-for-woocommerce' ),
                'error'
            );
        }
    }

    /**
     * Check full compatibility
     *
     * @return bool|array
     */
    public function check_full_compatibility() {
        $this->compatibility_status = array(
            'compatible' => true,
            'errors' => array(),
            'warnings' => array(),
        );

        // Check WooCommerce is active
        if ( ! $this->is_woocommerce_active() ) {
            $this->compatibility_status['compatible'] = false;
            $this->compatibility_status['errors'][] = __( 'WooCommerce is not active.', 'moneris-enhanced-gateway-for-woocommerce' );
        }

        // Check WooCommerce version
        if ( $this->woocommerce_active && ! $this->is_woocommerce_version_compatible() ) {
            $this->compatibility_status['compatible'] = false;
            $this->compatibility_status['errors'][] = sprintf(
                __( 'WooCommerce %s or higher is required. You are running %s.', 'moneris-enhanced-gateway-for-woocommerce' ),
                self::MIN_WC_VERSION,
                WC_VERSION
            );
        }

        // Check PHP version
        if ( version_compare( PHP_VERSION, MONERIS_MIN_PHP_VERSION, '<' ) ) {
            $this->compatibility_status['compatible'] = false;
            $this->compatibility_status['errors'][] = sprintf(
                __( 'PHP %s or higher is required. You are running %s.', 'moneris-enhanced-gateway-for-woocommerce' ),
                MONERIS_MIN_PHP_VERSION,
                PHP_VERSION
            );
        }

        // Check for SSL in production
        if ( ! is_ssl() && get_option( 'woocommerce_moneris_enhanced_gateway_settings' )['testmode'] !== 'yes' ) {
            $this->compatibility_status['warnings'][] = __( 'SSL is recommended for production use.', 'moneris-enhanced-gateway-for-woocommerce' );
        }

        // Check for conflicting plugins
        $conflicting_plugins = $this->check_conflicting_plugins();
        if ( ! empty( $conflicting_plugins ) ) {
            $this->compatibility_status['warnings'][] = sprintf(
                __( 'The following plugins may conflict: %s', 'moneris-enhanced-gateway-for-woocommerce' ),
                implode( ', ', $conflicting_plugins )
            );
        }

        // Check HPOS compatibility
        if ( $this->woocommerce_active && class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
            $hpos_enabled = FeaturesUtil::feature_is_enabled( 'custom_order_tables' );
            if ( $hpos_enabled ) {
                $this->compatibility_status['warnings'][] = __( 'High-Performance Order Storage (HPOS) is enabled. Plugin is compatible.', 'moneris-enhanced-gateway-for-woocommerce' );
            }
        }

        return $this->compatibility_status['compatible'];
    }

    /**
     * Get compatibility report
     *
     * @return array
     */
    public function get_compatibility_report() {
        $this->check_full_compatibility();
        return $this->compatibility_status;
    }

    /**
     * Check for conflicting plugins
     *
     * @return array
     */
    private function check_conflicting_plugins() {
        $conflicting = array();

        // List of known conflicting plugins
        $conflict_list = array(
            'moneris-gateway/moneris-gateway.php' => 'Moneris Gateway',
            'woocommerce-moneris-payment-gateway/woocommerce-moneris-payment-gateway.php' => 'WooCommerce Moneris Payment Gateway',
        );

        $active_plugins = apply_filters( 'active_plugins', get_option( 'active_plugins' ) );

        foreach ( $conflict_list as $plugin => $name ) {
            if ( in_array( $plugin, $active_plugins, true ) ) {
                $conflicting[] = $name;
            }
        }

        return $conflicting;
    }

    /**
     * WooCommerce missing notice
     */
    public function woocommerce_missing_notice() {
        if ( ! current_user_can( 'activate_plugins' ) ) {
            return;
        }

        ?>
        <div class="notice notice-error">
            <p>
                <strong><?php esc_html_e( 'Moneris Enhanced Payment Gateway for WooCommerce', 'moneris-enhanced-gateway-for-woocommerce' ); ?></strong>
                <?php esc_html_e( 'requires WooCommerce to be installed and active.', 'moneris-enhanced-gateway-for-woocommerce' ); ?>

                <?php if ( file_exists( WP_PLUGIN_DIR . '/woocommerce/woocommerce.php' ) ) : ?>
                    <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'plugins.php?action=activate&plugin=woocommerce/woocommerce.php' ), 'activate-plugin_woocommerce/woocommerce.php' ) ); ?>" class="button button-primary">
                        <?php esc_html_e( 'Activate WooCommerce', 'moneris-enhanced-gateway-for-woocommerce' ); ?>
                    </a>
                <?php else : ?>
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
        if ( ! current_user_can( 'update_plugins' ) ) {
            return;
        }

        ?>
        <div class="notice notice-warning">
            <p>
                <strong><?php esc_html_e( 'Moneris Enhanced Payment Gateway for WooCommerce', 'moneris-enhanced-gateway-for-woocommerce' ); ?></strong>
                <?php
                printf(
                    esc_html__( 'requires WooCommerce %1$s or higher. You are using version %2$s.', 'moneris-enhanced-gateway-for-woocommerce' ),
                    esc_html( self::MIN_WC_VERSION ),
                    esc_html( WC_VERSION )
                );
                ?>
                <a href="<?php echo esc_url( admin_url( 'update-core.php' ) ); ?>" class="button button-primary">
                    <?php esc_html_e( 'Update WooCommerce', 'moneris-enhanced-gateway-for-woocommerce' ); ?>
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * Capture payment
     *
     * @param WC_Order $order Order object.
     * @return bool
     */
    private function capture_payment( $order ) {
        $gateway = new Gateways\Moneris_Gateway();
        $api = new Api\Moneris_API();

        $transaction_id = $order->get_meta( '_moneris_transaction_id' );

        if ( ! $transaction_id ) {
            return false;
        }

        $capture_data = array(
            'order_id' => $order->get_order_number(),
            'amount' => $order->get_total(),
            'txn_number' => $transaction_id,
        );

        $response = $api->capture( $capture_data );

        if ( $response['success'] ) {
            $order->update_meta_data( '_moneris_payment_captured', 'yes' );
            $order->save();

            $order->add_order_note( __( 'Payment captured successfully via Moneris.', 'moneris-enhanced-gateway-for-woocommerce' ) );

            return true;
        }

        $order->add_order_note(
            sprintf(
                __( 'Failed to capture payment: %s', 'moneris-enhanced-gateway-for-woocommerce' ),
                $response['message'] ?? __( 'Unknown error', 'moneris-enhanced-gateway-for-woocommerce' )
            )
        );

        return false;
    }

    /**
     * Register custom order statuses
     */
    public function register_order_statuses() {
        register_post_status( 'wc-moneris-authorized', array(
            'label'                     => __( 'Moneris Authorized', 'moneris-enhanced-gateway-for-woocommerce' ),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop( 'Moneris Authorized (%s)', 'Moneris Authorized (%s)', 'moneris-enhanced-gateway-for-woocommerce' ),
        ) );
    }

    /**
     * Add custom order statuses to list
     *
     * @param array $order_statuses Order statuses.
     * @return array
     */
    public function add_order_statuses( $order_statuses ) {
        $order_statuses['wc-moneris-authorized'] = __( 'Moneris Authorized', 'moneris-enhanced-gateway-for-woocommerce' );
        return $order_statuses;
    }

    /**
     * Handle webhook
     */
    public function handle_webhook() {
        // Implementation will depend on Moneris webhook structure
        $gateway = new Gateways\Moneris_Gateway();
        $gateway->webhook();
    }

    /**
     * Save payment token
     *
     * @param int              $order_id Order ID.
     * @param int              $customer_id Customer ID.
     * @param WC_Payment_Token $token Payment token.
     * @param array            $result Result array.
     */
    public function save_payment_token( $order_id, $customer_id, $token, $result ) {
        // Save additional Moneris-specific token data if needed
    }

    /**
     * Process subscription payment
     *
     * @param float    $amount_to_charge Amount to charge.
     * @param WC_Order $order Order object.
     */
    public function process_subscription_payment( $amount_to_charge, $order ) {
        $gateway = new Gateways\Moneris_Gateway();
        $gateway->process_subscription_payment( $order, $amount_to_charge );
    }

    /**
     * Add settings section
     *
     * @param array  $settings Settings array.
     * @param string $current_section Current section.
     * @return array
     */
    public function add_settings_section( $settings, $current_section ) {
        if ( 'moneris_enhanced_gateway' === $current_section ) {
            $settings = array(
                array(
                    'title' => __( 'Moneris Enhanced Gateway Settings', 'moneris-enhanced-gateway-for-woocommerce' ),
                    'type'  => 'title',
                    'desc'  => __( 'Configure your Moneris payment gateway settings below.', 'moneris-enhanced-gateway-for-woocommerce' ),
                    'id'    => 'moneris_enhanced_gateway_settings',
                ),
            );
        }
        return $settings;
    }

    /**
     * Handle refund
     *
     * @param int   $order_id Order ID.
     * @param int   $refund_id Refund ID.
     */
    public function handle_refund( $order_id, $refund_id ) {
        $order = wc_get_order( $order_id );

        if ( $order->get_payment_method() !== 'moneris_enhanced_gateway' ) {
            return;
        }

        // Log refund
        $refund = wc_get_order( $refund_id );
        $amount = $refund->get_amount();

        $order->add_order_note(
            sprintf(
                __( 'Refund of %s processed via Moneris Gateway.', 'moneris-enhanced-gateway-for-woocommerce' ),
                wc_price( $amount )
            )
        );
    }

    /**
     * Register block payment method
     *
     * @param object $payment_method_registry Payment method registry.
     */
    public function register_block_payment_method( $payment_method_registry ) {
        // Will be implemented when block support is added
    }

    /**
     * Enqueue scripts for order page
     *
     * @param string $hook Hook suffix.
     */
    public function enqueue_order_scripts( $hook ) {
        global $post;

        if ( 'shop_order' !== $post->post_type && 'woocommerce_page_wc-orders' !== $hook ) {
            return;
        }

        wp_enqueue_style(
            'moneris-order-admin',
            MONERIS_PLUGIN_URL . 'assets/css/moneris-order-admin.css',
            array(),
            MONERIS_VERSION
        );
    }

    /**
     * Add capture order action
     *
     * @param array $actions Order actions.
     * @return array
     */
    public function add_capture_order_action( $actions ) {
        global $theorder;

        if ( ! is_object( $theorder ) ) {
            $theorder = wc_get_order( get_the_ID() );
        }

        if ( $theorder->get_payment_method() === 'moneris_enhanced_gateway' ) {
            $captured = $theorder->get_meta( '_moneris_payment_captured' );

            if ( ! $captured ) {
                $actions['moneris_capture_payment'] = __( 'Capture Moneris Payment', 'moneris-enhanced-gateway-for-woocommerce' );
            }
        }

        return $actions;
    }

    /**
     * Process capture action
     *
     * @param WC_Order $order Order object.
     */
    public function process_capture_action( $order ) {
        if ( $this->capture_payment( $order ) ) {
            // Add admin notice for success
            add_action( 'admin_notices', function() {
                ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e( 'Payment captured successfully.', 'moneris-enhanced-gateway-for-woocommerce' ); ?></p>
                </div>
                <?php
            } );
        } else {
            // Add admin notice for failure
            add_action( 'admin_notices', function() {
                ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php esc_html_e( 'Failed to capture payment. Please check the order notes for details.', 'moneris-enhanced-gateway-for-woocommerce' ); ?></p>
                </div>
                <?php
            } );
        }
    }

    /**
     * Add order meta box
     *
     * @param string  $post_type Post type.
     * @param WP_Post $post Post object.
     */
    public function add_order_meta_box( $post_type, $post ) {
        if ( 'shop_order' === $post_type ) {
            $order = wc_get_order( $post->ID );

            if ( $order && $order->get_payment_method() === 'moneris_enhanced_gateway' ) {
                add_meta_box(
                    'moneris_payment_details',
                    __( 'Moneris Payment Details', 'moneris-enhanced-gateway-for-woocommerce' ),
                    array( $this, 'render_order_meta_box' ),
                    'shop_order',
                    'side',
                    'high'
                );
            }
        }
    }

    /**
     * Render order meta box
     *
     * @param WP_Post $post Post object.
     */
    public function render_order_meta_box( $post ) {
        $order = wc_get_order( $post->ID );
        $this->display_order_meta( $order );
    }

    /**
     * Filter payment method title
     *
     * @param string $title Gateway title.
     * @param string $id Gateway ID.
     * @return string
     */
    public function filter_payment_method_title( $title, $id ) {
        if ( 'moneris_enhanced_gateway' === $id ) {
            $settings = get_option( 'woocommerce_moneris_enhanced_gateway_settings', array() );

            if ( isset( $settings['title'] ) && ! empty( $settings['title'] ) ) {
                $title = $settings['title'];
            }
        }

        return $title;
    }

    /**
     * Add payment info to email
     *
     * @param WC_Order $order Order object.
     * @param bool     $sent_to_admin Sent to admin.
     * @param bool     $plain_text Plain text email.
     */
    public function add_payment_info_to_email( $order, $sent_to_admin, $plain_text ) {
        if ( $order->get_payment_method() !== 'moneris_enhanced_gateway' ) {
            return;
        }

        $transaction_id = $order->get_meta( '_moneris_transaction_id' );

        if ( $transaction_id && $sent_to_admin ) {
            if ( $plain_text ) {
                echo "\n" . __( 'Moneris Transaction ID:', 'moneris-enhanced-gateway-for-woocommerce' ) . ' ' . $transaction_id . "\n";
            } else {
                echo '<p><strong>' . __( 'Moneris Transaction ID:', 'moneris-enhanced-gateway-for-woocommerce' ) . '</strong> ' . esc_html( $transaction_id ) . '</p>';
            }
        }
    }

    /**
     * Handle WooCommerce deactivation
     */
    public function handle_woocommerce_deactivation() {
        // Deactivate our gateway if WooCommerce is deactivated
        deactivate_plugins( MONERIS_PLUGIN_BASENAME );

        add_action( 'admin_notices', function() {
            ?>
            <div class="notice notice-warning is-dismissible">
                <p>
                    <?php
                    esc_html_e(
                        'Moneris Enhanced Payment Gateway has been deactivated because WooCommerce was deactivated.',
                        'moneris-enhanced-gateway-for-woocommerce'
                    );
                    ?>
                </p>
            </div>
            <?php
        } );
    }
}