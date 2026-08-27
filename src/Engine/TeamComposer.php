<?php
/**
 * Hours → role allocation, from the rate card's per-service-line bands.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Engine;

/**
 * Team allocation.
 */
final class TeamComposer {

	/**
	 * Constructor.
	 *
	 * @param RateCard $card Rate card.
	 */
	public function __construct( private readonly RateCard $card ) {}

	/**
	 * Pick the band for the hours and return per-role hours, shares and rates.
	 *
	 * @param string $service_line Service line id.
	 * @param float  $total_hours  Final hours.
	 * @return array{band_index: int, source: string, roles: array<string, array{share: float, hours: float, rate: float}>}
	 */
	public function compose( string $service_line, float $total_hours ): array {
		$bands = (array) $this->card->get( "team_bands.{$service_line}", [] );
		$index = count( $bands ) - 1;
		foreach ( $bands as $i => $band ) {
			$max = $band['max_hours'] ?? null;
			if ( null === $max || $total_hours <= (float) $max ) {
				$index = (int) $i;
				break;
			}
		}
		$roles = [];
		foreach ( (array) ( $bands[ $index ]['roles'] ?? [] ) as $role => $percent ) {
			$share = (float) $percent / 100;
			if ( $share <= 0 ) {
				continue;
			}
			$roles[ (string) $role ] = [
				'share' => $share,
				'hours' => round( $total_hours * $share, 2 ),
				'rate'  => $this->card->role_rate( (string) $role ),
			];
		}
		return [
			'band_index' => $index,
			'source'     => "team_bands.{$service_line}.{$index}",
			'roles'      => $roles,
		];
	}

	/**
	 * Share-weighted hourly rate. Falls back to the blended rate when no
	 * roles are allocated (e.g. a custom card with empty bands).
	 *
	 * @param array<string, array{share: float, hours: float, rate: float}> $roles Allocation.
	 */
	public function effective_rate( array $roles ): float {
		if ( ! $roles ) {
			return (float) $this->card->get( 'blended_rate' );
		}
		$rate = 0.0;
		foreach ( $roles as $role ) {
			$rate += $role['share'] * $role['rate'];
		}
		return round( $rate, 4 );
	}
}
