<?php
/**
 * Autoloader class for the plugin
 *
 * @package MonerisEnhancedGateway
 */

namespace Moneris_Enhanced_Gateway;

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PSR-4 Autoloader implementation
 *
 * @since 1.0.0
 */
class Moneris_Autoloader {

    /**
     * Namespace prefix
     *
     * @var string
     */
    private static $prefix = 'Moneris_Enhanced_Gateway\\';

    /**
     * Base directory for namespace
     *
     * @var string
     */
    private static $base_dir;

    /**
     * Initialize autoloader
     */
    public static function init() {
        self::$base_dir = MONERIS_PLUGIN_DIR . 'includes/';
        spl_autoload_register( array( __CLASS__, 'autoload' ) );
    }

    /**
     * Autoload class
     *
     * @param string $class The fully-qualified class name.
     */
    public static function autoload( $class ) {
        // Check if class uses our namespace
        $len = strlen( self::$prefix );
        if ( strncmp( self::$prefix, $class, $len ) !== 0 ) {
            return;
        }

        // Get relative class name
        $relative_class = substr( $class, $len );

        // Convert namespace to path
        $path = str_replace( '\\', '/', $relative_class );

        // Handle subdirectory structure
        $parts = explode( '/', $path );
        $class_name = array_pop( $parts );

        // Convert class name to file name
        $file_name = 'class-' . str_replace( '_', '-', strtolower( $class_name ) ) . '.php';

        // Build full file path
        if ( ! empty( $parts ) ) {
            $subdirectory = strtolower( implode( '/', $parts ) );
            $file = self::$base_dir . $subdirectory . '/' . $file_name;
        } else {
            $file = self::$base_dir . $file_name;
        }

        // Include file if it exists
        if ( file_exists( $file ) ) {
            require_once $file;
        }
    }

    /**
     * Register a new namespace
     *
     * @param string $namespace Namespace to register.
     * @param string $path Path to namespace directory.
     */
    public static function register_namespace( $namespace, $path ) {
        spl_autoload_register( function( $class ) use ( $namespace, $path ) {
            $len = strlen( $namespace );
            if ( strncmp( $namespace, $class, $len ) !== 0 ) {
                return;
            }

            $relative_class = substr( $class, $len );
            $file = $path . str_replace( '\\', '/', $relative_class ) . '.php';

            if ( file_exists( $file ) ) {
                require_once $file;
            }
        } );
    }
}