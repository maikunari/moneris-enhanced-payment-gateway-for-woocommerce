<?php
/**
 * Uninstall script
 *
 * This file is executed when the plugin is uninstalled.
 * It removes all plugin data from the database.
 *
 * @package MonerisEnhancedGateway
 * @since 1.0.0
 */

// Prevent direct file access
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Security check - ensure this is actually being called by WordPress
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Get user confirmation for data deletion
 * Note: This is handled by WordPress core during uninstall process
 */

// Load plugin constants if not already loaded
if ( ! defined( 'MONERIS_PLUGIN_DIR' ) ) {
    define( 'MONERIS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

/**
 * Main uninstall function
 */
function moneris_enhanced_gateway_uninstall() {
    global $wpdb;

    // Get plugin settings to check if we should remove data
    $settings = get_option( 'woocommerce_moneris_enhanced_gateway_settings' );
    $remove_data = isset( $settings['remove_data_on_uninstall'] ) ? $settings['remove_data_on_uninstall'] : 'no';

    // Additional safety check - only remove data if explicitly configured
    if ( 'yes' !== $remove_data ) {
        // Check for a separate uninstall option
        $force_remove = get_option( 'moneris_force_remove_all_data', false );

        if ( ! $force_remove ) {
            // Don't remove data unless explicitly configured
            return;
        }
    }

    // Remove plugin options
    $options_to_delete = array(
        'woocommerce_moneris_enhanced_gateway_settings',
        'moneris_enhanced_gateway_version',
        'moneris_enhanced_gateway_activated',
        'moneris_gateway_status',
        'moneris_api_test_result',
        'moneris_force_remove_all_data',
        'moneris_db_version',
        'moneris_install_date',
        'moneris_last_sync_time',
    );

    foreach ( $options_to_delete as $option ) {
        delete_option( $option );
    }

    // Remove all transients
    $transients_to_delete = array(
        'moneris_gateway_status',
        'moneris_api_test_result',
        'moneris_exchange_rates',
        'moneris_available_card_types',
        'moneris_gateway_availability',
    );

    foreach ( $transients_to_delete as $transient ) {
        delete_transient( $transient );
        delete_site_transient( $transient );
    }

    // Clear scheduled cron jobs
    $cron_hooks = array(
        'moneris_cleanup_logs',
        'moneris_check_pending_captures',
        'moneris_sync_transactions',
        'moneris_check_gateway_status',
        'moneris_clear_expired_tokens',
    );

    foreach ( $cron_hooks as $hook ) {
        $timestamp = wp_next_scheduled( $hook );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, $hook );
        }
        // Clear all scheduled occurrences
        wp_clear_scheduled_hook( $hook );
    }

    // Remove custom database tables
    $table_names = array(
        $wpdb->prefix . 'moneris_transactions',
        $wpdb->prefix . 'moneris_tokens',
        $wpdb->prefix . 'moneris_logs',
        $wpdb->prefix . 'moneris_api_requests',
    );

    foreach ( $table_names as $table_name ) {
        $wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );
    }

    // Remove custom order meta data
    $meta_keys_to_delete = array(
        '_moneris_transaction_id',
        '_moneris_receipt_id',
        '_moneris_reference_num',
        '_moneris_auth_code',
        '_moneris_payment_captured',
        '_moneris_card_type',
        '_moneris_card_last_four',
        '_moneris_customer_code',
        '_moneris_response_code',
        '_moneris_response_message',
        '_moneris_avs_result',
        '_moneris_cvd_result',
        '_moneris_token',
        '_moneris_refund_id',
        '_moneris_partial_refunds',
    );

    // Check if HPOS is enabled
    if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) &&
         \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {

        // HPOS is enabled - use WooCommerce CRUD methods
        $orders = wc_get_orders( array(
            'payment_method' => 'moneris_enhanced_gateway',
            'limit' => -1,
            'return' => 'ids',
        ) );

        foreach ( $orders as $order_id ) {
            $order = wc_get_order( $order_id );
            if ( $order ) {
                foreach ( $meta_keys_to_delete as $meta_key ) {
                    $order->delete_meta_data( $meta_key );
                }
                $order->save();
            }
        }
    } else {
        // Legacy post meta approach
        foreach ( $meta_keys_to_delete as $meta_key ) {
            $wpdb->delete(
                $wpdb->postmeta,
                array( 'meta_key' => $meta_key ),
                array( '%s' )
            );
        }
    }

    // Remove user meta for saved payment methods
    $user_meta_keys = array(
        '_moneris_customer_id',
        '_moneris_saved_cards',
        '_moneris_default_card',
    );

    foreach ( $user_meta_keys as $meta_key ) {
        $wpdb->delete(
            $wpdb->usermeta,
            array( 'meta_key' => $meta_key ),
            array( '%s' )
        );
    }

    // Remove WooCommerce payment tokens for Moneris
    if ( class_exists( 'WC_Payment_Tokens' ) ) {
        $tokens = WC_Payment_Tokens::get_tokens( array(
            'gateway_id' => 'moneris_enhanced_gateway',
        ) );

        foreach ( $tokens as $token ) {
            $token->delete();
        }
    }

    // Clean up uploaded files (if any)
    $upload_dir = wp_upload_dir();
    $moneris_upload_dir = $upload_dir['basedir'] . '/moneris-gateway';

    if ( is_dir( $moneris_upload_dir ) ) {
        moneris_delete_directory( $moneris_upload_dir );
    }

    // Clear any cached data
    if ( function_exists( 'wp_cache_flush' ) ) {
        wp_cache_flush();
    }

    // Remove capabilities (if any custom capabilities were added)
    $capabilities = array(
        'manage_moneris_settings',
        'view_moneris_reports',
        'process_moneris_refunds',
    );

    // Remove from all roles
    $roles = wp_roles()->get_names();
    foreach ( array_keys( $roles ) as $role_name ) {
        $role = get_role( $role_name );
        if ( $role ) {
            foreach ( $capabilities as $cap ) {
                $role->remove_cap( $cap );
            }
        }
    }

    // Log uninstall completion (if logging is still available)
    error_log( 'Moneris Enhanced Payment Gateway: Uninstall completed successfully.' );
}

/**
 * Recursively delete a directory
 *
 * @param string $dir Directory path.
 * @return bool
 */
function moneris_delete_directory( $dir ) {
    if ( ! is_dir( $dir ) ) {
        return false;
    }

    $files = array_diff( scandir( $dir ), array( '.', '..' ) );

    foreach ( $files as $file ) {
        $path = $dir . '/' . $file;

        if ( is_dir( $path ) ) {
            moneris_delete_directory( $path );
        } else {
            unlink( $path );
        }
    }

    return rmdir( $dir );
}

// Check for multisite
if ( is_multisite() ) {
    // For multisite, we need to iterate through all sites
    $sites = get_sites();

    foreach ( $sites as $site ) {
        switch_to_blog( $site->blog_id );
        moneris_enhanced_gateway_uninstall();
        restore_current_blog();
    }
} else {
    // Single site installation
    moneris_enhanced_gateway_uninstall();
}