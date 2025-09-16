<?php
/**
 * WP-CLI Commands for Moneris Gateway
 *
 * @package MonerisEnhancedGateway
 * @since 1.0.0
 */

namespace Moneris_Enhanced_Gateway\CLI;

use WP_CLI;
use WP_CLI_Command;
use Moneris_Enhanced_Gateway\Utils\Moneris_Credential_Manager;

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Manage Moneris Enhanced Gateway via WP-CLI
 *
 * @since 1.0.0
 */
class Moneris_CLI_Commands extends WP_CLI_Command {

    /**
     * Credential manager instance
     *
     * @var Moneris_Credential_Manager
     */
    private $credential_manager;

    /**
     * Constructor
     */
    public function __construct() {
        $this->credential_manager = new Moneris_Credential_Manager();
    }

    /**
     * Test Moneris API credentials
     *
     * ## OPTIONS
     *
     * [--test-mode]
     * : Use test mode credentials
     *
     * ## EXAMPLES
     *
     *     wp moneris credentials test
     *     wp moneris credentials test --test-mode
     *
     * @when after_wp_load
     */
    public function test( $args, $assoc_args ) {
        WP_CLI::log( 'Testing Moneris API credentials...' );

        $test_mode = isset( $assoc_args['test-mode'] );
        $this->credential_manager = new Moneris_Credential_Manager( $test_mode );

        $result = $this->credential_manager->test_connection();

        if ( is_wp_error( $result ) ) {
            WP_CLI::error( $result->get_error_message() );
        }

        WP_CLI::success( 'API connection test successful!' );

        // Get and display credential status
        $status = $this->credential_manager->get_status();

        WP_CLI::log( "\nCredential Status:" );
        WP_CLI::log( "├── Encryption Method: " . $status['encryption_method'] );
        WP_CLI::log( "├── Stored At: " . ( $status['stored_at'] ?? 'Never' ) );
        WP_CLI::log( "├── Last Rotated: " . ( $status['last_rotated'] ?? 'Never' ) );
        WP_CLI::log( "└── Rotation Due: " . ( $status['rotation_due'] ?? 'N/A' ) );

        if ( $status['needs_rotation'] ) {
            WP_CLI::warning( 'Credentials are due for rotation!' );
        }
    }

    /**
     * Rotate Moneris API credentials
     *
     * ## OPTIONS
     *
     * [--store-id=<store-id>]
     * : New Store ID
     *
     * [--api-token=<api-token>]
     * : New API Token
     *
     * [--hpp-id=<hpp-id>]
     * : New HPP Profile ID
     *
     * [--hpp-key=<hpp-key>]
     * : New HPP Validation Key
     *
     * [--test-mode]
     * : Use test mode
     *
     * ## EXAMPLES
     *
     *     wp moneris credentials rotate --store-id=store1 --api-token=yesguy
     *
     * @when after_wp_load
     */
    public function rotate( $args, $assoc_args ) {
        WP_CLI::log( 'Rotating Moneris credentials...' );

        // Get existing credentials first
        $existing = $this->credential_manager->get_credentials();

        if ( is_wp_error( $existing ) && 'no_credentials' !== $existing->get_error_code() ) {
            WP_CLI::error( 'Failed to retrieve existing credentials: ' . $existing->get_error_message() );
        }

        // Use provided values or prompt for them
        $store_id = $assoc_args['store-id'] ?? $this->prompt_for_credential( 'Store ID', $existing['store_id'] ?? '' );
        $api_token = $assoc_args['api-token'] ?? $this->prompt_for_credential( 'API Token', '', true );
        $hpp_id = $assoc_args['hpp-id'] ?? $this->prompt_for_credential( 'HPP Profile ID', $existing['hpp_id'] ?? '' );
        $hpp_key = $assoc_args['hpp-key'] ?? $this->prompt_for_credential( 'HPP Validation Key', '', true );

        // Store new credentials
        $result = $this->credential_manager->store_credentials( $store_id, $api_token, $hpp_id, $hpp_key );

        if ( is_wp_error( $result ) ) {
            WP_CLI::error( 'Failed to rotate credentials: ' . $result->get_error_message() );
        }

        WP_CLI::success( 'Credentials rotated successfully!' );

        // Test new credentials
        WP_CLI::log( 'Testing new credentials...' );
        $test_result = $this->credential_manager->test_connection();

        if ( is_wp_error( $test_result ) ) {
            WP_CLI::warning( 'New credentials stored but connection test failed: ' . $test_result->get_error_message() );
        } else {
            WP_CLI::success( 'New credentials validated successfully!' );
        }
    }

    /**
     * Clear all stored Moneris credentials
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Skip confirmation prompt
     *
     * [--emergency]
     * : Emergency clear without checks
     *
     * ## EXAMPLES
     *
     *     wp moneris credentials clear
     *     wp moneris credentials clear --yes
     *     wp moneris credentials clear --emergency --yes
     *
     * @when after_wp_load
     */
    public function clear( $args, $assoc_args ) {
        $emergency = isset( $assoc_args['emergency'] );

        if ( ! isset( $assoc_args['yes'] ) ) {
            WP_CLI::confirm(
                $emergency
                    ? 'Are you sure you want to perform an EMERGENCY clear of all credentials?'
                    : 'Are you sure you want to clear all stored credentials?'
            );
        }

        WP_CLI::log( 'Clearing credentials...' );

        $result = $this->credential_manager->clear_credentials( $emergency );

        if ( ! $result ) {
            WP_CLI::error( 'Failed to clear credentials' );
        }

        WP_CLI::success( 'All credentials cleared successfully!' );

        if ( $emergency ) {
            WP_CLI::warning( 'Emergency clear completed. Please reconfigure credentials in the WordPress admin.' );
        }
    }

    /**
     * Display credential status
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format (table, json, yaml)
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - yaml
     * ---
     *
     * ## EXAMPLES
     *
     *     wp moneris credentials status
     *     wp moneris credentials status --format=json
     *
     * @when after_wp_load
     */
    public function status( $args, $assoc_args ) {
        $status = $this->credential_manager->get_status();

        $format = $assoc_args['format'] ?? 'table';

        // Prepare data for display
        $display_data = array(
            array(
                'Property' => 'Has Credentials',
                'Value' => $status['has_credentials'] ? 'Yes' : 'No',
            ),
            array(
                'Property' => 'Encryption Method',
                'Value' => $status['encryption_method'],
            ),
            array(
                'Property' => 'Stored At',
                'Value' => $status['stored_at'] ?? 'Never',
            ),
            array(
                'Property' => 'Last Rotated',
                'Value' => $status['last_rotated'] ?? 'Never',
            ),
            array(
                'Property' => 'Rotation Due',
                'Value' => $status['rotation_due'] ?? 'N/A',
            ),
            array(
                'Property' => 'Needs Rotation',
                'Value' => $status['needs_rotation'] ? 'Yes' : 'No',
            ),
            array(
                'Property' => 'Is Locked Out',
                'Value' => $status['is_locked_out'] ? 'Yes' : 'No',
            ),
        );

        // Add encryption availability
        if ( isset( $status['encryption_available'] ) ) {
            $display_data[] = array(
                'Property' => 'OpenSSL Available',
                'Value' => $status['encryption_available']['openssl'] ? 'Yes' : 'No',
            );
            $display_data[] = array(
                'Property' => 'Preferred Encryption',
                'Value' => $status['encryption_available']['preferred'],
            );
        }

        WP_CLI\Utils\format_items( $format, $display_data, array( 'Property', 'Value' ) );

        // Show warnings if needed
        if ( $status['needs_rotation'] ) {
            WP_CLI::warning( 'Credentials are due for rotation!' );
        }

        if ( $status['is_locked_out'] ) {
            WP_CLI::error( 'Credentials are locked out due to too many failed attempts!' );
        }

        if ( ! $status['has_credentials'] ) {
            WP_CLI::warning( 'No credentials are currently stored.' );
        }
    }

    /**
     * Export credentials for migration
     *
     * ## OPTIONS
     *
     * [--output=<file>]
     * : Output file path
     *
     * ## EXAMPLES
     *
     *     wp moneris credentials export
     *     wp moneris credentials export --output=/path/to/export.json
     *
     * @when after_wp_load
     */
    public function export( $args, $assoc_args ) {
        WP_CLI::log( 'Exporting credentials...' );

        $export = $this->credential_manager->export_credentials();

        if ( is_wp_error( $export ) ) {
            WP_CLI::error( 'Export failed: ' . $export->get_error_message() );
        }

        // Determine output
        if ( isset( $assoc_args['output'] ) ) {
            $output_file = $assoc_args['output'];
            $json = wp_json_encode( $export, JSON_PRETTY_PRINT );

            if ( false === file_put_contents( $output_file, $json ) ) {
                WP_CLI::error( 'Failed to write to file: ' . $output_file );
            }

            WP_CLI::success( 'Credentials exported to: ' . $output_file );
        } else {
            // Output to console
            WP_CLI::log( wp_json_encode( $export, JSON_PRETTY_PRINT ) );
        }
    }

    /**
     * Import credentials from export
     *
     * ## OPTIONS
     *
     * <file>
     * : Import file path
     *
     * [--yes]
     * : Skip confirmation prompt
     *
     * ## EXAMPLES
     *
     *     wp moneris credentials import /path/to/export.json
     *     wp moneris credentials import export.json --yes
     *
     * @when after_wp_load
     */
    public function import( $args, $assoc_args ) {
        $import_file = $args[0];

        if ( ! file_exists( $import_file ) ) {
            WP_CLI::error( 'Import file not found: ' . $import_file );
        }

        $json = file_get_contents( $import_file );
        if ( false === $json ) {
            WP_CLI::error( 'Failed to read import file' );
        }

        $import_data = json_decode( $json, true );
        if ( null === $import_data ) {
            WP_CLI::error( 'Invalid JSON in import file' );
        }

        // Show import details
        WP_CLI::log( 'Import Details:' );
        WP_CLI::log( '├── Version: ' . ( $import_data['version'] ?? 'Unknown' ) );
        WP_CLI::log( '├── Exported At: ' . ( $import_data['exported_at'] ?? 'Unknown' ) );
        WP_CLI::log( '└── Store ID: ' . ( $import_data['credentials']['store_id'] ?? 'Unknown' ) );

        if ( ! isset( $assoc_args['yes'] ) ) {
            WP_CLI::confirm( 'Do you want to import these credentials?' );
        }

        WP_CLI::log( 'Importing credentials...' );

        $result = $this->credential_manager->import_credentials( $import_data );

        if ( is_wp_error( $result ) ) {
            WP_CLI::error( 'Import failed: ' . $result->get_error_message() );
        }

        WP_CLI::success( 'Credentials imported successfully!' );

        // Test imported credentials
        WP_CLI::log( 'Testing imported credentials...' );
        $test_result = $this->credential_manager->test_connection();

        if ( is_wp_error( $test_result ) ) {
            WP_CLI::warning( 'Credentials imported but connection test failed: ' . $test_result->get_error_message() );
        } else {
            WP_CLI::success( 'Imported credentials validated successfully!' );
        }
    }

    /**
     * Migrate plain text credentials to encrypted storage
     *
     * ## EXAMPLES
     *
     *     wp moneris credentials migrate
     *
     * @when after_wp_load
     */
    public function migrate( $args, $assoc_args ) {
        WP_CLI::log( 'Checking for plain text credentials to migrate...' );

        $result = $this->credential_manager->migrate_plain_credentials();

        if ( is_wp_error( $result ) ) {
            if ( 'no_settings' === $result->get_error_code() ) {
                WP_CLI::log( 'No plain text credentials found to migrate.' );
                return;
            }

            WP_CLI::error( 'Migration failed: ' . $result->get_error_message() );
        }

        WP_CLI::success( 'Plain text credentials migrated to encrypted storage!' );

        // Test migrated credentials
        WP_CLI::log( 'Testing migrated credentials...' );
        $test_result = $this->credential_manager->test_connection();

        if ( is_wp_error( $test_result ) ) {
            WP_CLI::warning( 'Credentials migrated but connection test failed: ' . $test_result->get_error_message() );
        } else {
            WP_CLI::success( 'Migrated credentials validated successfully!' );
        }
    }

    /**
     * Check encryption availability
     *
     * ## EXAMPLES
     *
     *     wp moneris encryption status
     *
     * @when after_wp_load
     */
    public function encryption( $args, $assoc_args ) {
        $availability = $this->credential_manager->check_encryption_availability();

        WP_CLI::log( 'Encryption Status:' );
        WP_CLI::log( '├── OpenSSL: ' . ( $availability['openssl'] ? WP_CLI::colorize( '%gAvailable%n' ) : WP_CLI::colorize( '%rNot Available%n' ) ) );
        WP_CLI::log( '├── WP Hash: ' . ( $availability['wp_hash'] ? WP_CLI::colorize( '%gAvailable%n' ) : 'Not Available' ) );
        WP_CLI::log( '└── Preferred Method: ' . WP_CLI::colorize( '%c' . $availability['preferred'] . '%n' ) );

        if ( ! $availability['openssl'] ) {
            WP_CLI::warning( 'OpenSSL is not available. Using fallback encryption method.' );
            WP_CLI::log( 'For better security, consider enabling the OpenSSL PHP extension.' );
        }
    }

    /**
     * Prompt for credential input
     *
     * @param string $label   Field label.
     * @param string $default Default value.
     * @param bool   $hidden  Hide input.
     * @return string
     */
    private function prompt_for_credential( $label, $default = '', $hidden = false ) {
        $prompt = $label;
        if ( ! empty( $default ) && ! $hidden ) {
            $prompt .= ' [' . $default . ']';
        }
        $prompt .= ': ';

        if ( $hidden ) {
            // For sensitive data, use hidden input
            WP_CLI::out( $prompt );
            system( 'stty -echo' );
            $value = trim( fgets( STDIN ) );
            system( 'stty echo' );
            WP_CLI::line( '' ); // New line after hidden input
        } else {
            $value = \cli\prompt( $prompt, $default );
        }

        return $value ?: $default;
    }
}

/**
 * Register WP-CLI commands
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    WP_CLI::add_command( 'moneris credentials', array( 'Moneris_Enhanced_Gateway\CLI\Moneris_CLI_Commands', 'test' ) );
    WP_CLI::add_command( 'moneris credentials rotate', array( 'Moneris_Enhanced_Gateway\CLI\Moneris_CLI_Commands', 'rotate' ) );
    WP_CLI::add_command( 'moneris credentials clear', array( 'Moneris_Enhanced_Gateway\CLI\Moneris_CLI_Commands', 'clear' ) );
    WP_CLI::add_command( 'moneris credentials status', array( 'Moneris_Enhanced_Gateway\CLI\Moneris_CLI_Commands', 'status' ) );
    WP_CLI::add_command( 'moneris credentials export', array( 'Moneris_Enhanced_Gateway\CLI\Moneris_CLI_Commands', 'export' ) );
    WP_CLI::add_command( 'moneris credentials import', array( 'Moneris_Enhanced_Gateway\CLI\Moneris_CLI_Commands', 'import' ) );
    WP_CLI::add_command( 'moneris credentials migrate', array( 'Moneris_Enhanced_Gateway\CLI\Moneris_CLI_Commands', 'migrate' ) );
    WP_CLI::add_command( 'moneris encryption', array( 'Moneris_Enhanced_Gateway\CLI\Moneris_CLI_Commands', 'encryption' ) );
}