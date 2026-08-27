<?php
/**
 * Test helper: build rate cards from the defaults with dot-path overrides.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Tests\Unit;

use Cybertech\Estimator\Engine\RateCard;
use Cybertech\Estimator\Engine\RateCardDefaults;
use Cybertech\Estimator\Support\Arr;

/**
 * Card builder used by the engine tests.
 */
final class CardFactory {

	/**
	 * Default card data with dot-path overrides applied.
	 *
	 * @param array<string, mixed> $overrides Dot path => value.
	 * @return array<string, mixed>
	 */
	public static function data( array $overrides = [] ): array {
		$data = RateCardDefaults::card();
		foreach ( $overrides as $path => $value ) {
			$data = Arr::set( $data, $path, $value );
		}
		return $data;
	}

	/**
	 * Default card with dot-path overrides applied.
	 *
	 * @param array<string, mixed> $overrides Dot path => value.
	 */
	public static function card( array $overrides = [] ): RateCard {
		return new RateCard( self::data( $overrides ) );
	}

	/**
	 * A "flat" card that makes price = base_hours × rate with nothing else in
	 * the way: no contingency, no min-hours clamp, urgency normal = 1, and a
	 * single-role team (pm 100 %) at the given rate for the given line.
	 *
	 * Answers of just `[ 'service_line' => $line ]` select no factors, so
	 * hours = base_hours exactly.
	 *
	 * @param string               $line       Service line id.
	 * @param float                $base_hours Base hours.
	 * @param float                $rate       Hourly rate of the single role.
	 * @param array<string, mixed> $overrides  Additional dot-path overrides.
	 */
	public static function flat( string $line, float $base_hours, float $rate, array $overrides = [] ): RateCard {
		$base = [
			"service_lines.{$line}.base_hours" => $base_hours,
			"service_lines.{$line}.min_hours"  => 0,
			'contingency'                      => 0,
			'urgency.normal'                   => 1.0,
			'role_rates.pm'                    => $rate,
			"team_bands.{$line}"               => [
				[
					'max_hours' => null,
					'roles'     => [ 'pm' => 100 ],
				],
			],
		];
		return self::card( array_merge( $base, $overrides ) );
	}
}
