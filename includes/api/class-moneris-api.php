<?php
/**
 * Moneris API handler
 *
 * @package MonerisEnhancedGateway
 */

namespace Moneris_Enhanced_Gateway\Api;

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Moneris API class
 *
 * @since 1.0.0
 */
class Moneris_API {

    /**
     * API endpoints
     *
     * @var array
     */
    private $endpoints = array(
        'production' => 'https://www3.moneris.com',
        'test'       => 'https://esqa.moneris.com',
    );

    /**
     * Store ID
     *
     * @var string
     */
    private $store_id;

    /**
     * API Token
     *
     * @var string
     */
    private $api_token;

    /**
     * Test mode
     *
     * @var bool
     */
    private $test_mode;

    /**
     * Constructor
     */
    public function __construct() {
        $this->load_settings();
    }

    /**
     * Load settings
     */
    private function load_settings() {
        $settings = get_option( 'woocommerce_moneris_enhanced_gateway_settings', array() );
        
        $this->test_mode = isset( $settings['testmode'] ) && 'yes' === $settings['testmode'];
        $this->store_id = $this->test_mode ? ( $settings['test_store_id'] ?? '' ) : ( $settings['store_id'] ?? '' );
        $this->api_token = $this->test_mode ? ( $settings['test_api_token'] ?? '' ) : ( $settings['api_token'] ?? '' );
    }

    /**
     * Get API endpoint
     *
     * @return string
     */
    public function get_endpoint() {
        return $this->test_mode ? $this->endpoints['test'] : $this->endpoints['production'];
    }

    /**
     * Process purchase
     *
     * @param array $data Transaction data.
     * @return array
     */
    public function purchase( $data ) {
        return $this->process_transaction( 'purchase', $data );
    }

    /**
     * Process pre-authorization
     *
     * @param array $data Transaction data.
     * @return array
     */
    public function preauth( $data ) {
        return $this->process_transaction( 'preauth', $data );
    }

    /**
     * Process capture
     *
     * @param array $data Transaction data.
     * @return array
     */
    public function capture( $data ) {
        return $this->process_transaction( 'completion', $data );
    }

    /**
     * Process refund
     *
     * @param array $data Transaction data.
     * @return array
     */
    public function refund( $data ) {
        return $this->process_transaction( 'refund', $data );
    }

    /**
     * Process transaction
     *
     * @param string $type Transaction type.
     * @param array  $data Transaction data.
     * @return array
     */
    private function process_transaction( $type, $data ) {
        // Build request
        $request = $this->build_request( $type, $data );

        // Send request
        $response = $this->send_request( $request );

        // Parse response
        return $this->parse_response( $response );
    }

    /**
     * Build API request
     *
     * @param string $type Transaction type.
     * @param array  $data Transaction data.
     * @return array
     */
    private function build_request( $type, $data ) {
        $request = array(
            'type' => $type,
            'store_id' => $this->store_id,
            'api_token' => $this->api_token,
        );

        return array_merge( $request, $data );
    }

    /**
     * Send API request
     *
     * @param array $request Request data.
     * @return array|WP_Error
     */
    private function send_request( $request ) {
        $url = $this->get_endpoint() . '/gateway2/servlet/MpgRequest';

        $response = wp_remote_post( $url, array(
            'method'      => 'POST',
            'timeout'     => 45,
            'redirection' => 5,
            'httpversion' => '1.0',
            'blocking'    => true,
            'headers'     => array(
                'Content-Type' => 'application/xml',
            ),
            'body'        => $this->build_xml( $request ),
            'cookies'     => array(),
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return wp_remote_retrieve_body( $response );
    }

    /**
     * Build XML request
     *
     * @param array $data Request data.
     * @return string
     */
    private function build_xml( $data ) {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<request>';
        
        foreach ( $data as $key => $value ) {
            $xml .= "<{$key}>" . esc_html( $value ) . "</{$key}>";
        }
        
        $xml .= '</request>';
        
        return $xml;
    }

    /**
     * Parse API response
     *
     * @param string|WP_Error $response API response.
     * @return array
     */
    private function parse_response( $response ) {
        if ( is_wp_error( $response ) ) {
            return array(
                'success' => false,
                'message' => $response->get_error_message(),
            );
        }

        // Parse XML response
        $xml = simplexml_load_string( $response );
        
        if ( false === $xml ) {
            return array(
                'success' => false,
                'message' => __( 'Invalid response from payment gateway.', 'moneris-enhanced-gateway-for-woocommerce' ),
            );
        }

        return array(
            'success' => true,
            'data'    => json_decode( json_encode( $xml ), true ),
        );
    }

    /**
     * Validate credentials
     *
     * @return bool
     */
    public function validate_credentials() {
        return ! empty( $this->store_id ) && ! empty( $this->api_token );
    }
}