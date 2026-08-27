<?php
/**
 * Currency formatting for the visitor-facing UI and admin.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Support;

/**
 * Money formatter.
 */
final class Money {

	/**
	 * Format a price: "€20,000" / "20.000 RON". Uses the site locale's
	 * thousands separator via number_format_i18n when available.
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency ISO code.
	 */
	public static function format( float $amount, string $currency ): string {
		$number = function_exists( 'number_format_i18n' ) ? number_format_i18n( $amount, 0 ) : number_format( $amount, 0, '.', ',' );
		$symbol = self::symbol( $currency );
		return null !== $symbol ? $symbol . $number : $number . ' ' . $currency;
	}

	/**
	 * Range "€20,000 – €30,000".
	 *
	 * @param float  $low      Low.
	 * @param float  $high     High.
	 * @param string $currency ISO code.
	 */
	public static function range( float $low, float $high, string $currency ): string {
		return self::format( $low, $currency ) . ' – ' . self::format( $high, $currency );
	}

	/**
	 * Prefix symbol for common currencies, null to use a suffix code.
	 *
	 * @param string $currency ISO code.
	 */
	public static function symbol( string $currency ): ?string {
		return [
			'EUR' => '€',
			'USD' => '$',
			'GBP' => '£',
		][ strtoupper( $currency ) ] ?? null;
	}
}
