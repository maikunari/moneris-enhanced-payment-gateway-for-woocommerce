<?php
/**
 * Secure Credential Management System
 *
 * @package MonerisEnhancedGateway
 * @since 1.0.0
 */

namespace Moneris_Enhanced_Gateway\Utils;

use WP_Error;

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define Moneris API URLs from API reference
if ( ! defined( 'MONERIS_TEST_API_URL' ) ) {
    define( 'MONERIS_TEST_API_URL', 'https://esqa.moneris.com/gateway2/servlet/MpgRequest' );
}

if ( ! defined( 'MONERIS_PROD_API_URL' ) ) {
    define( 'MONERIS_PROD_API_URL', 'https://www3.moneris.com/gateway2/servlet/MpgRequest' );
}

/**
 * Credential Manager Class
 *
 * Handles secure storage, encryption, and validation of Moneris API credentials
 * Implements encryption methods from API reference with WordPress best practices
 *
 * @since 1.0.0
 */
class Moneris_Credential_Manager {

    /**
     * Option name for stored credentials
     *
     * @var string
     */
    private const CREDENTIALS_OPTION = 'moneris_encrypted_credentials';

    /**
     * Option name for credential metadata
     *
     * @var string
     */
    private const CREDENTIALS_META_OPTION = 'moneris_credentials_meta';

    /**
     * Option name for failed attempts tracking
     *
     * @var string
     */
    private const FAILED_ATTEMPTS_OPTION = 'moneris_failed_auth_attempts';

    /**
     * Maximum failed attempts before lockout
     *
     * @var int
     */
    private const MAX_FAILED_ATTEMPTS = 5;

    /**
     * Credential rotation period in days
     *
     * @var int
     */
    private const ROTATION_PERIOD_DAYS = 90;

    /**
     * Logger instance
     *
     * @var Moneris_Logger
     */
    private $logger;

    /**
     * Test mode flag
     *
     * @var bool
     */
    private $test_mode;

    /**
     * Constructor
     *
     * @param bool $test_mode Whether to use test mode.
     */
    public function __construct( $test_mode = false ) {
        $this->test_mode = $test_mode;
        $this->logger = new Moneris_Logger();
    }

    /**
     * Store credentials securely
     *
     * @param string $store_id  Store ID.
     * @param string $api_token API Token.
     * @param string $hpp_id    HPP Profile ID.
     * @param string $hpp_key   HPP Validation Key.
     * @return bool|WP_Error
     */
    public function store_credentials( $store_id, $api_token, $hpp_id, $hpp_key ) {
        try {
            // Validate inputs
            if ( empty( $store_id ) || empty( $api_token ) || empty( $hpp_id ) || empty( $hpp_key ) ) {
                return new WP_Error( 'missing_credentials', __( 'All credential fields are required', 'moneris-enhanced-gateway-for-woocommerce' ) );
            }

            // Prepare credentials array
            $credentials = array(
                'store_id'  => sanitize_text_field( $store_id ),
                'api_token' => $api_token, // Will be encrypted
                'hpp_id'    => sanitize_text_field( $hpp_id ),
                'hpp_key'   => $hpp_key,   // Will be encrypted
            );

            // Encrypt sensitive fields
            $credentials['api_token'] = $this->encrypt_data( $api_token );
            $credentials['hpp_key'] = $this->encrypt_data( $hpp_key );

            if ( false === $credentials['api_token'] || false === $credentials['hpp_key'] ) {
                return new WP_Error( 'encryption_failed', __( 'Failed to encrypt credentials', 'moneris-enhanced-gateway-for-woocommerce' ) );
            }

            // Store encrypted credentials
            $stored = update_option( self::CREDENTIALS_OPTION, $credentials, false );

            if ( ! $stored ) {
                return new WP_Error( 'storage_failed', __( 'Failed to store credentials', 'moneris-enhanced-gateway-for-woocommerce' ) );
            }

            // Update metadata
            $this->update_credentials_metadata();

            // Log the action (without sensitive data)
            $this->logger->log( 'Credentials stored successfully for store: ' . $this->mask_value( $store_id ), 'info' );

            // Clear any failed attempts on successful storage
            delete_option( self::FAILED_ATTEMPTS_OPTION );

            return true;

        } catch ( \Exception $e ) {
            $this->logger->log( 'Error storing credentials: ' . $e->getMessage(), 'error' );
            return new WP_Error( 'storage_exception', $e->getMessage() );
        }
    }

    /**
     * Get decrypted credentials
     *
     * @return array|WP_Error
     */
    public function get_credentials() {
        try {
            // Check for lockout
            if ( $this->is_locked_out() ) {
                return new WP_Error( 'locked_out', __( 'Too many failed attempts. Please wait before trying again.', 'moneris-enhanced-gateway-for-woocommerce' ) );
            }

            $credentials = get_option( self::CREDENTIALS_OPTION );

            if ( empty( $credentials ) ) {
                return new WP_Error( 'no_credentials', __( 'No credentials found', 'moneris-enhanced-gateway-for-woocommerce' ) );
            }

            // Decrypt sensitive fields
            if ( isset( $credentials['api_token'] ) ) {
                $decrypted_token = $this->decrypt_data( $credentials['api_token'] );
                if ( false === $decrypted_token ) {
                    $this->record_failed_attempt();
                    return new WP_Error( 'decryption_failed', __( 'Failed to decrypt API token', 'moneris-enhanced-gateway-for-woocommerce' ) );
                }
                $credentials['api_token'] = $decrypted_token;
            }

            if ( isset( $credentials['hpp_key'] ) ) {
                $decrypted_key = $this->decrypt_data( $credentials['hpp_key'] );
                if ( false === $decrypted_key ) {
                    $this->record_failed_attempt();
                    return new WP_Error( 'decryption_failed', __( 'Failed to decrypt HPP key', 'moneris-enhanced-gateway-for-woocommerce' ) );
                }
                $credentials['hpp_key'] = $decrypted_key;
            }

            // Log access (audit trail)
            $this->logger->log( 'Credentials accessed', 'debug' );

            return $credentials;

        } catch ( \Exception $e ) {
            $this->logger->log( 'Error retrieving credentials: ' . $e->getMessage(), 'error' );
            return new WP_Error( 'retrieval_exception', $e->getMessage() );
        }
    }

    /**
     * Validate credentials with Moneris API
     *
     * @param array $credentials Optional credentials to validate.
     * @return bool|WP_Error
     */
    public function validate_credentials( $credentials = null ) {
        try {
            // Get credentials if not provided
            if ( null === $credentials ) {
                $credentials = $this->get_credentials();
                if ( is_wp_error( $credentials ) ) {
                    return $credentials;
                }
            }

            // Test connection with API
            $test_result = $this->test_connection(
                $credentials['store_id'],
                $credentials['api_token']
            );

            if ( is_wp_error( $test_result ) ) {
                return $test_result;
            }

            return true;

        } catch ( \Exception $e ) {
            $this->logger->log( 'Credential validation failed: ' . $e->getMessage(), 'error' );
            return new WP_Error( 'validation_exception', $e->getMessage() );
        }
    }

    /**
     * Clear all stored credentials
     *
     * @param bool $emergency Emergency clear without checks.
     * @return bool
     */
    public function clear_credentials( $emergency = false ) {
        try {
            if ( ! $emergency ) {
                // Verify user capability
                if ( ! current_user_can( 'manage_options' ) ) {
                    return false;
                }
            }

            // Delete credentials
            $deleted = delete_option( self::CREDENTIALS_OPTION );

            // Delete metadata
            delete_option( self::CREDENTIALS_META_OPTION );

            // Clear failed attempts
            delete_option( self::FAILED_ATTEMPTS_OPTION );

            // Clear any cached data
            wp_cache_delete( 'moneris_credentials', 'options' );

            // Log the action
            $this->logger->log(
                $emergency ? 'Emergency credential clear executed' : 'Credentials cleared',
                $emergency ? 'warning' : 'info'
            );

            return $deleted;

        } catch ( \Exception $e ) {
            $this->logger->log( 'Error clearing credentials: ' . $e->getMessage(), 'error' );
            return false;
        }
    }

    /**
     * Test connection with Moneris API
     *
     * @param string $store_id  Store ID.
     * @param string $api_token API Token.
     * @return true|WP_Error
     */
    public function test_connection( $store_id = null, $api_token = null ) {
        try {
            // Get credentials if not provided
            if ( null === $store_id || null === $api_token ) {
                $credentials = $this->get_credentials();
                if ( is_wp_error( $credentials ) ) {
                    return $credentials;
                }
                $store_id = $credentials['store_id'];
                $api_token = $credentials['api_token'];
            }

            // Build test XML request as per API reference
            $order_id = 'test_' . time();
            $xml_request = '<?xml version="1.0" encoding="UTF-8"?>
                <request>
                    <store_id>' . esc_html( $store_id ) . '</store_id>
                    <api_token>' . esc_html( $api_token ) . '</api_token>
                    <card_verification>
                        <order_id>' . esc_html( $order_id ) . '</order_id>
                        <pan>4242424242424242</pan>
                        <expdate>2512</expdate>
                        <crypt_type>7</crypt_type>
                    </card_verification>
                </request>';

            // Send request to API
            $response = $this->send_api_request( $xml_request );

            if ( is_wp_error( $response ) ) {
                return $response;
            }

            // Parse response
            $parsed = $this->parse_response( $response );

            if ( is_wp_error( $parsed ) ) {
                return $parsed;
            }

            // Check if connection is successful
            if ( isset( $parsed['receipt']['ResponseCode'] ) ) {
                $response_code = intval( $parsed['receipt']['ResponseCode'] );

                // Response codes < 50 indicate success per API reference
                if ( $response_code < 50 || $response_code === 889 ) { // 889 is CVD not verified but connection OK
                    $this->logger->log( 'API connection test successful', 'info' );
                    return true;
                }

                return new WP_Error(
                    'api_error',
                    sprintf(
                        __( 'API returned error code: %s', 'moneris-enhanced-gateway-for-woocommerce' ),
                        $response_code
                    )
                );
            }

            return new WP_Error( 'invalid_response', __( 'Invalid API response format', 'moneris-enhanced-gateway-for-woocommerce' ) );

        } catch ( \Exception $e ) {
            $this->logger->log( 'Connection test failed: ' . $e->getMessage(), 'error' );
            return new WP_Error( 'connection_exception', $e->getMessage() );
        }
    }

    /**
     * Send API request to Moneris
     *
     * @param string $xml_request XML request body.
     * @return string|WP_Error
     */
    protected function send_api_request( $xml_request ) {
        try {
            $api_url = $this->get_api_url();

            $this->logger->log( 'Sending API request to: ' . $api_url, 'debug' );

            $response = wp_remote_post( $api_url, array(
                'timeout'      => 30,
                'redirection'  => 5,
                'httpversion'  => '1.1',
                'blocking'     => true,
                'headers'      => array(
                    'Content-Type' => 'application/xml',
                    'Accept'       => 'application/xml',
                    'User-Agent'   => 'Moneris Enhanced Gateway for WooCommerce/' . MONERIS_VERSION,
                ),
                'body'         => $xml_request,
                'sslverify'    => true,
                'data_format'  => 'body',
            ) );

            if ( is_wp_error( $response ) ) {
                $this->logger->log( 'API request failed: ' . $response->get_error_message(), 'error' );
                return $response;
            }

            $response_code = wp_remote_retrieve_response_code( $response );
            $response_body = wp_remote_retrieve_body( $response );

            if ( 200 !== $response_code ) {
                return new WP_Error(
                    'http_error',
                    sprintf(
                        __( 'HTTP error: %d', 'moneris-enhanced-gateway-for-woocommerce' ),
                        $response_code
                    )
                );
            }

            if ( empty( $response_body ) ) {
                return new WP_Error( 'empty_response', __( 'Empty response from API', 'moneris-enhanced-gateway-for-woocommerce' ) );
            }

            return $response_body;

        } catch ( \Exception $e ) {
            $this->logger->log( 'API request exception: ' . $e->getMessage(), 'error' );
            return new WP_Error( 'request_exception', $e->getMessage() );
        }
    }

    /**
     * Parse XML response from Moneris API
     *
     * @param string $xml_response XML response string.
     * @return array|WP_Error
     */
    protected function parse_response( $xml_response ) {
        try {
            // Disable external entity loading for security (as per API reference)
            $previous_value = null;
            if ( function_exists( 'libxml_disable_entity_loader' ) ) {
                $previous_value = libxml_disable_entity_loader( true );
            }

            // Suppress XML errors
            $previous_errors = libxml_use_internal_errors( true );

            try {
                // Load XML string
                $xml = simplexml_load_string( $xml_response );

                if ( false === $xml ) {
                    $errors = libxml_get_errors();
                    $error_message = 'XML parsing failed';

                    if ( ! empty( $errors ) ) {
                        $error_message .= ': ' . $errors[0]->message;
                    }

                    libxml_clear_errors();
                    return new WP_Error( 'xml_parse_error', $error_message );
                }

                // Convert to array for easier handling
                $json = wp_json_encode( $xml );
                $array = json_decode( $json, true );

                return $array;

            } finally {
                // Restore previous settings
                libxml_use_internal_errors( $previous_errors );
                if ( null !== $previous_value && function_exists( 'libxml_disable_entity_loader' ) ) {
                    libxml_disable_entity_loader( $previous_value );
                }
            }

        } catch ( \Exception $e ) {
            $this->logger->log( 'Response parsing failed: ' . $e->getMessage(), 'error' );
            return new WP_Error( 'parse_exception', $e->getMessage() );
        }
    }

    /**
     * Encrypt data using WordPress best practices
     *
     * @param string $data Data to encrypt.
     * @return string|false
     */
    protected function encrypt_data( $data ) {
        if ( empty( $data ) ) {
            return $data;
        }

        // Check if OpenSSL is available (primary method)
        if ( $this->is_openssl_available() ) {
            return $this->encrypt_with_openssl( $data );
        }

        // Fallback to wp_hash method
        return $this->encrypt_with_wp_hash( $data );
    }

    /**
     * Decrypt data
     *
     * @param string $encrypted_data Encrypted data.
     * @return string|false
     */
    protected function decrypt_data( $encrypted_data ) {
        if ( empty( $encrypted_data ) ) {
            return $encrypted_data;
        }

        // Check encryption method used
        if ( strpos( $encrypted_data, 'openssl:' ) === 0 ) {
            return $this->decrypt_with_openssl( $encrypted_data );
        }

        // Fallback for wp_hash encrypted data
        return $this->decrypt_with_wp_hash( $encrypted_data );
    }

    /**
     * Encrypt with OpenSSL
     *
     * @param string $data Data to encrypt.
     * @return string|false
     */
    private function encrypt_with_openssl( $data ) {
        try {
            $key = substr( wp_salt( 'auth' ), 0, 32 );
            $iv = substr( wp_salt( 'secure_auth' ), 0, 16 );

            $encrypted = openssl_encrypt(
                $data,
                'AES-256-CBC',
                $key,
                0,
                $iv
            );

            if ( false === $encrypted ) {
                return false;
            }

            return 'openssl:' . base64_encode( $encrypted );

        } catch ( \Exception $e ) {
            $this->logger->log( 'OpenSSL encryption failed: ' . $e->getMessage(), 'error' );
            return false;
        }
    }

    /**
     * Decrypt with OpenSSL
     *
     * @param string $encrypted_data Encrypted data.
     * @return string|false
     */
    private function decrypt_with_openssl( $encrypted_data ) {
        try {
            // Remove prefix
            $encrypted_data = str_replace( 'openssl:', '', $encrypted_data );
            $encrypted_data = base64_decode( $encrypted_data );

            if ( false === $encrypted_data ) {
                return false;
            }

            $key = substr( wp_salt( 'auth' ), 0, 32 );
            $iv = substr( wp_salt( 'secure_auth' ), 0, 16 );

            $decrypted = openssl_decrypt(
                $encrypted_data,
                'AES-256-CBC',
                $key,
                0,
                $iv
            );

            return $decrypted;

        } catch ( \Exception $e ) {
            $this->logger->log( 'OpenSSL decryption failed: ' . $e->getMessage(), 'error' );
            return false;
        }
    }

    /**
     * Encrypt with wp_hash (fallback method)
     *
     * @param string $data Data to encrypt.
     * @return string
     */
    private function encrypt_with_wp_hash( $data ) {
        // This is a simple obfuscation, not true encryption
        // Store with a hash for verification
        $key = wp_salt( 'auth' );
        $encoded = base64_encode( $data );
        $hash = wp_hash( $data . $key );

        return 'wphash:' . $encoded . ':' . $hash;
    }

    /**
     * Decrypt with wp_hash verification
     *
     * @param string $encrypted_data Encrypted data.
     * @return string|false
     */
    private function decrypt_with_wp_hash( $encrypted_data ) {
        if ( strpos( $encrypted_data, 'wphash:' ) !== 0 ) {
            return false;
        }

        $parts = explode( ':', $encrypted_data );
        if ( count( $parts ) !== 3 ) {
            return false;
        }

        $encoded = $parts[1];
        $hash = $parts[2];

        $decoded = base64_decode( $encoded );
        if ( false === $decoded ) {
            return false;
        }

        // Verify hash
        $key = wp_salt( 'auth' );
        $expected_hash = wp_hash( $decoded . $key );

        if ( ! hash_equals( $expected_hash, $hash ) ) {
            return false;
        }

        return $decoded;
    }

    /**
     * Check if OpenSSL is available
     *
     * @return bool
     */
    public function is_openssl_available() {
        return function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' );
    }

    /**
     * Check encryption availability
     *
     * @return array
     */
    public function check_encryption_availability() {
        return array(
            'openssl'  => $this->is_openssl_available(),
            'wp_hash'  => true, // Always available
            'preferred' => $this->is_openssl_available() ? 'openssl' : 'wp_hash',
        );
    }

    /**
     * Get API URL based on mode
     *
     * @return string
     */
    private function get_api_url() {
        return $this->test_mode ? MONERIS_TEST_API_URL : MONERIS_PROD_API_URL;
    }

    /**
     * Update credential metadata
     */
    private function update_credentials_metadata() {
        $metadata = array(
            'stored_at'     => current_time( 'mysql' ),
            'stored_by'     => get_current_user_id(),
            'last_rotated'  => current_time( 'mysql' ),
            'rotation_due'  => date( 'Y-m-d H:i:s', strtotime( '+' . self::ROTATION_PERIOD_DAYS . ' days' ) ),
            'encryption'    => $this->is_openssl_available() ? 'openssl' : 'wp_hash',
        );

        update_option( self::CREDENTIALS_META_OPTION, $metadata, false );
    }

    /**
     * Check if credentials need rotation
     *
     * @return bool
     */
    public function needs_rotation() {
        $metadata = get_option( self::CREDENTIALS_META_OPTION );

        if ( empty( $metadata['rotation_due'] ) ) {
            return false;
        }

        return strtotime( $metadata['rotation_due'] ) <= current_time( 'timestamp' );
    }

    /**
     * Record failed attempt
     */
    private function record_failed_attempt() {
        $attempts = get_option( self::FAILED_ATTEMPTS_OPTION, array() );

        $attempts[] = array(
            'time' => current_time( 'timestamp' ),
            'ip'   => $this->get_client_ip(),
        );

        // Keep only recent attempts (last hour)
        $one_hour_ago = current_time( 'timestamp' ) - HOUR_IN_SECONDS;
        $attempts = array_filter( $attempts, function( $attempt ) use ( $one_hour_ago ) {
            return $attempt['time'] > $one_hour_ago;
        } );

        update_option( self::FAILED_ATTEMPTS_OPTION, $attempts, false );

        $this->logger->log( 'Failed credential access attempt recorded', 'warning' );
    }

    /**
     * Check if locked out due to failed attempts
     *
     * @return bool
     */
    private function is_locked_out() {
        $attempts = get_option( self::FAILED_ATTEMPTS_OPTION, array() );

        // Filter to last hour
        $one_hour_ago = current_time( 'timestamp' ) - HOUR_IN_SECONDS;
        $recent_attempts = array_filter( $attempts, function( $attempt ) use ( $one_hour_ago ) {
            return $attempt['time'] > $one_hour_ago;
        } );

        return count( $recent_attempts ) >= self::MAX_FAILED_ATTEMPTS;
    }

    /**
     * Get client IP address
     *
     * @return string
     */
    private function get_client_ip() {
        $ip_keys = array( 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' );

        foreach ( $ip_keys as $key ) {
            if ( array_key_exists( $key, $_SERVER ) === true ) {
                $ip = filter_var( $_SERVER[ $key ], FILTER_VALIDATE_IP );
                if ( false !== $ip ) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    /**
     * Mask value for logging
     *
     * @param string $value Value to mask.
     * @return string
     */
    private function mask_value( $value ) {
        if ( strlen( $value ) <= 4 ) {
            return str_repeat( '*', strlen( $value ) );
        }

        return str_repeat( '*', strlen( $value ) - 4 ) . substr( $value, -4 );
    }

    /**
     * Export credentials (for migration)
     *
     * @return array|WP_Error
     */
    public function export_credentials() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'permission_denied', __( 'Insufficient permissions', 'moneris-enhanced-gateway-for-woocommerce' ) );
        }

        $credentials = $this->get_credentials();
        if ( is_wp_error( $credentials ) ) {
            return $credentials;
        }

        // Create export package
        $export = array(
            'version'     => MONERIS_VERSION,
            'exported_at' => current_time( 'mysql' ),
            'credentials' => $credentials,
            'checksum'    => wp_hash( serialize( $credentials ) ),
        );

        $this->logger->log( 'Credentials exported', 'info' );

        return $export;
    }

    /**
     * Import credentials
     *
     * @param array $import_data Import data.
     * @return bool|WP_Error
     */
    public function import_credentials( $import_data ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'permission_denied', __( 'Insufficient permissions', 'moneris-enhanced-gateway-for-woocommerce' ) );
        }

        // Validate import structure
        if ( ! isset( $import_data['credentials'], $import_data['checksum'] ) ) {
            return new WP_Error( 'invalid_import', __( 'Invalid import data structure', 'moneris-enhanced-gateway-for-woocommerce' ) );
        }

        // Verify checksum
        $expected_checksum = wp_hash( serialize( $import_data['credentials'] ) );
        if ( ! hash_equals( $expected_checksum, $import_data['checksum'] ) ) {
            return new WP_Error( 'checksum_mismatch', __( 'Import data integrity check failed', 'moneris-enhanced-gateway-for-woocommerce' ) );
        }

        // Store credentials
        $result = $this->store_credentials(
            $import_data['credentials']['store_id'],
            $import_data['credentials']['api_token'],
            $import_data['credentials']['hpp_id'],
            $import_data['credentials']['hpp_key']
        );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $this->logger->log( 'Credentials imported successfully', 'info' );

        return true;
    }

    /**
     * Migrate plain text credentials to encrypted
     *
     * @return bool|WP_Error
     */
    public function migrate_plain_credentials() {
        // Check for old gateway settings
        $old_settings = get_option( 'woocommerce_moneris_enhanced_settings' );

        if ( empty( $old_settings ) ) {
            return new WP_Error( 'no_settings', __( 'No settings to migrate', 'moneris-enhanced-gateway-for-woocommerce' ) );
        }

        $store_id = $old_settings['store_id'] ?? '';
        $api_token = $old_settings['api_token'] ?? '';
        $hpp_id = $old_settings['hpp_id'] ?? '';
        $hpp_key = $old_settings['hpp_key'] ?? '';

        if ( empty( $store_id ) || empty( $api_token ) ) {
            return new WP_Error( 'incomplete_credentials', __( 'Incomplete credentials in old settings', 'moneris-enhanced-gateway-for-woocommerce' ) );
        }

        // Store with encryption
        $result = $this->store_credentials( $store_id, $api_token, $hpp_id, $hpp_key );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        // Clear old plain text credentials
        unset( $old_settings['api_token'] );
        unset( $old_settings['hpp_key'] );
        update_option( 'woocommerce_moneris_enhanced_settings', $old_settings );

        $this->logger->log( 'Plain text credentials migrated to encrypted storage', 'info' );

        return true;
    }

    /**
     * Get credential status
     *
     * @return array
     */
    public function get_status() {
        $metadata = get_option( self::CREDENTIALS_META_OPTION, array() );
        $has_credentials = ! empty( get_option( self::CREDENTIALS_OPTION ) );

        return array(
            'has_credentials'     => $has_credentials,
            'encryption_method'   => $metadata['encryption'] ?? 'unknown',
            'stored_at'          => $metadata['stored_at'] ?? null,
            'last_rotated'       => $metadata['last_rotated'] ?? null,
            'rotation_due'       => $metadata['rotation_due'] ?? null,
            'needs_rotation'     => $this->needs_rotation(),
            'is_locked_out'      => $this->is_locked_out(),
            'encryption_available' => $this->check_encryption_availability(),
        );
    }
}