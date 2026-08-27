<?php
/**
 * Minimal PSR-4 autoloader so the plugin ships without Composer.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Support;

/**
 * PSR-4 autoloader.
 */
final class Autoloader {

	private const PREFIX = 'Cybertech\\Estimator\\';

	/**
	 * Register the SPL autoloader for the plugin namespace.
	 *
	 * @param string $base_dir Absolute path to the src/ directory.
	 */
	public static function register( string $base_dir ): void {
		$base_dir = rtrim( $base_dir, '/\\' ) . '/';

		spl_autoload_register(
			static function ( string $class_name ) use ( $base_dir ): void {
				if ( 0 !== strncmp( $class_name, self::PREFIX, strlen( self::PREFIX ) ) ) {
					return;
				}
				$relative = substr( $class_name, strlen( self::PREFIX ) );
				$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';
				if ( is_file( $file ) ) {
					require $file;
				}
			}
		);
	}
}
