<?php
/**
 * Decides what the visitor may see, per reveal mode. This is the server-side
 * gate: in `gated` mode the preview payload contains no figures at all, so
 * "blurred" is cosmetic and devtools reveal nothing.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Frontend;

use Cybertech\Estimator\Engine\EstimateResult;
use Cybertech\Estimator\Engine\RateCardDefaults;
use Cybertech\Estimator\Support\Money;

/**
 * Visitor-facing payload shaping.
 */
final class RevealPolicy {

	/**
	 * Visitor payload.
	 *
	 * @param EstimateResult $result   Full result.
	 * @param string         $mode     open | band | gated.
	 * @param bool           $unlocked True once the visitor has submitted contact details.
	 * @return array<string, mixed>
	 */
	public static function visitor_payload( EstimateResult $result, string $mode, bool $unlocked ): array {
		$base = [
			'mode'     => $mode,
			'unlocked' => $unlocked,
			'ready'    => true,
		];

		if ( 'gated' === $mode && ! $unlocked ) {
			return $base;
			// Nothing numeric leaves the server before the gate.
		}

		$common = [
			'weeks'      => $result->weeks,
			'weeks_text' => sprintf(
				/* translators: %d: number of weeks */
				_n( '%d week', '%d weeks', $result->weeks, 'cybertech-estimator' ),
				$result->weeks
			),
			'team'       => self::team_labels( $result ),
			'band'       => $result->band,
			'band_label' => RateCardDefaults::localize_label( $result->band_label ),
		];

		if ( 'band' === $mode ) {
			return $base + $common;
			// Figures are never shown to the visitor in band mode.
		}

		return $base + $common + [
			'currency'   => $result->currency,
			'price_low'  => $result->price_low,
			'price_high' => $result->price_high,
			'range_text' => Money::range( $result->price_low, $result->price_high, $result->currency ),
			'hours'      => round( $result->hours ),
		];
	}

	/**
	 * Team as translated labels with rounded hours (roles under 1 h dropped).
	 *
	 * @param EstimateResult $result Result.
	 * @return array<int, array{role: string, label: string, hours: int, share: float}>
	 */
	public static function team_labels( EstimateResult $result ): array {
		$labels = RateCardDefaults::role_labels();
		$out    = [];
		foreach ( (array) ( $result->team['roles'] ?? [] ) as $role => $r ) {
			$hours = (int) round( (float) $r['hours'] );
			if ( $hours < 1 ) {
				continue;
			}
			$out[] = [
				'role'  => (string) $role,
				'label' => $labels[ $role ] ?? (string) $role,
				'hours' => $hours,
				'share' => round( (float) $r['share'], 4 ),
			];
		}
		return $out;
	}
}
