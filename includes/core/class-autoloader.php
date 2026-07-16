<?php
/**
 * Autoloader.
 *
 * @package WeSubscribeToPosts
 */

namespace WSTP\Core;

defined( 'ABSPATH' ) || exit;

/**
 * PSR-4-like autoloader for the WSTP namespace.
 */
final class Autoloader {
	/**
	 * Register autoloader.
	 */
	public static function register(): void {
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	/**
	 * Load matching class files.
	 *
	 * @param string $class_name FQCN.
	 */
	private static function autoload( string $class_name ): void {
		$prefix = 'WSTP\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative_class = substr( $class_name, strlen( $prefix ) );
		$relative_class = str_replace( '\\', '/', $relative_class );

		$parts = explode( '/', $relative_class );
		if ( empty( $parts ) ) {
			return;
		}

		$class_segment = strtolower( array_pop( $parts ) );
		$directory     = implode( '/', array_map( 'strtolower', $parts ) );
		$base_path     = WSTP_PATH . 'includes/' . ( $directory ? $directory . '/' : '' );

		$candidates = array(
			$base_path . 'class-' . str_replace( '_', '-', $class_segment ) . '.php',
			$base_path . 'class-' . $class_segment . '.php',
		);

		foreach ( $candidates as $path ) {
			if ( file_exists( $path ) ) {
				require_once $path;
				return;
			}
		}
	}
}
