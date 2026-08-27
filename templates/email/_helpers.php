<?php
/**
 * Shared email template helpers (loaded by the email templates).
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

if ( ! function_exists( 'ct_est_email_number' ) ) {
	/**
	 * Format a breakdown value with its unit.
	 *
	 * @param float  $value Value.
	 * @param string $unit  Unit.
	 */
	function ct_est_email_number( float $value, string $unit ): string {
		$n = rtrim( rtrim( number_format( $value, 2, '.', ',' ), '0' ), '.' );
		return 'h' === $unit ? $n . ' h' : $n . ( '' !== $unit ? ' ' . $unit : '' );
	}
}
