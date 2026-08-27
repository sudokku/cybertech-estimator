<?php
/**
 * Thin logger: writes to the PHP error log when WP_DEBUG is on and keeps a
 * short ring buffer in an option for the Diagnostics tab.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Support;

/**
 * Ring-buffer logger.
 */
final class Logger {

	public const OPTION    = 'ct_est_log';
	private const MAX_ROWS = 200;

	/**
	 * Record an event.
	 *
	 * @param string               $channel Short channel name (ai, webhook, security...).
	 * @param string               $message Human readable message.
	 * @param array<string, mixed> $context Extra data, must be JSON-serialisable.
	 */
	public static function log( string $channel, string $message, array $context = [] ): void {
		$row = [
			'ts'      => time(),
			'channel' => $channel,
			'message' => $message,
			'context' => $context,
		];

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional debug output.
			error_log( sprintf( '[ct_est:%s] %s %s', $channel, $message, $context ? wp_json_encode( $context ) : '' ) );
		}

		$rows   = get_option( self::OPTION, [] );
		$rows   = is_array( $rows ) ? $rows : [];
		$rows[] = $row;
		if ( count( $rows ) > self::MAX_ROWS ) {
			$rows = array_slice( $rows, -self::MAX_ROWS );
		}
		update_option( self::OPTION, $rows, false );
	}

	/**
	 * Most recent rows, newest first.
	 *
	 * @param int $limit Max rows.
	 * @return array<int, array<string, mixed>>
	 */
	public static function recent( int $limit = 50 ): array {
		$rows = get_option( self::OPTION, [] );
		$rows = is_array( $rows ) ? $rows : [];
		return array_slice( array_reverse( $rows ), 0, $limit );
	}

	/**
	 * Clear the buffer.
	 */
	public static function clear(): void {
		delete_option( self::OPTION );
	}
}
