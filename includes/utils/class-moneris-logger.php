<?php
/**
 * Logger utility
 *
 * @package MonerisEnhancedGateway
 */

namespace Moneris_Enhanced_Gateway\Utils;

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Logger class
 *
 * @since 1.0.0
 */
class Moneris_Logger {

    /**
     * Logger instance
     *
     * @var \WC_Logger
     */
    private $logger;

    /**
     * Logger context
     *
     * @var array
     */
    private $context;

    /**
     * Debug mode
     *
     * @var bool
     */
    private $debug;

    /**
     * Constructor
     */
    public function __construct() {
        $settings = get_option( 'woocommerce_moneris_enhanced_gateway_settings', array() );
        $this->debug = isset( $settings['debug'] ) && 'yes' === $settings['debug'];
        
        if ( $this->debug && function_exists( 'wc_get_logger' ) ) {
            $this->logger = wc_get_logger();
            $this->context = array( 'source' => 'moneris-enhanced-gateway' );
        }
    }

    /**
     * Log a message
     *
     * @param string $message Message to log.
     * @param string $level   Log level (emergency|alert|critical|error|warning|notice|info|debug).
     */
    public function log( $message, $level = 'info' ) {
        if ( ! $this->debug || ! $this->logger ) {
            return;
        }

        // Add timestamp
        $message = '[' . current_time( 'mysql' ) . '] ' . $message;

        // Log based on level
        switch ( $level ) {
            case 'emergency':
                $this->logger->emergency( $message, $this->context );
                break;
            case 'alert':
                $this->logger->alert( $message, $this->context );
                break;
            case 'critical':
                $this->logger->critical( $message, $this->context );
                break;
            case 'error':
                $this->logger->error( $message, $this->context );
                break;
            case 'warning':
                $this->logger->warning( $message, $this->context );
                break;
            case 'notice':
                $this->logger->notice( $message, $this->context );
                break;
            case 'debug':
                $this->logger->debug( $message, $this->context );
                break;
            case 'info':
            default:
                $this->logger->info( $message, $this->context );
                break;
        }
    }

    /**
     * Log an error
     *
     * @param string $message Error message.
     */
    public function error( $message ) {
        $this->log( $message, 'error' );
    }

    /**
     * Log a warning
     *
     * @param string $message Warning message.
     */
    public function warning( $message ) {
        $this->log( $message, 'warning' );
    }

    /**
     * Log info
     *
     * @param string $message Info message.
     */
    public function info( $message ) {
        $this->log( $message, 'info' );
    }

    /**
     * Log debug
     *
     * @param string $message Debug message.
     */
    public function debug( $message ) {
        $this->log( $message, 'debug' );
    }

    /**
     * Log API request
     *
     * @param string $endpoint API endpoint.
     * @param array  $request  Request data.
     * @param array  $response Response data.
     */
    public function log_api_request( $endpoint, $request, $response ) {
        if ( ! $this->debug ) {
            return;
        }

        $message = "API Request to {$endpoint}\n";
        $message .= 'Request: ' . wp_json_encode( $this->sanitize_for_log( $request ) ) . "\n";
        $message .= 'Response: ' . wp_json_encode( $response );

        $this->log( $message, 'debug' );
    }

    /**
     * Sanitize sensitive data for logging
     *
     * @param array $data Data to sanitize.
     * @return array
     */
    private function sanitize_for_log( $data ) {
        $sensitive_keys = array(
            'api_token',
            'pan',
            'card_number',
            'cvd',
            'cvc',
            'expdate',
            'expiry',
        );

        foreach ( $sensitive_keys as $key ) {
            if ( isset( $data[ $key ] ) ) {
                $data[ $key ] = str_repeat( '*', 4 ) . substr( $data[ $key ], -4 );
            }
        }

        return $data;
    }

    /**
     * Clear logs older than specified days
     *
     * @param int $days Number of days.
     */
    public function clear_old_logs( $days = 30 ) {
        if ( ! $this->logger || ! method_exists( $this->logger, 'clear' ) ) {
            return;
        }

        // This would need custom implementation as WC_Logger doesn't have built-in cleanup
        // Could be implemented with custom database table or file management
    }
}