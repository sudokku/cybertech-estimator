<?php
/**
 * Rate card value object. Pure: no WordPress calls. Loading/saving lives in
 * RateCardRepository so the engine and its tests never touch the database.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Engine;

use Cybertech\Estimator\Support\Arr;

/**
 * Immutable rate card.
 */
final class RateCard {

	public const FACTOR_TYPES = [ 'multiplier', 'add_hours', 'add_price' ];

	/**
	 * Raw card data.
	 *
	 * @var array<string, mixed>
	 */
	private array $data;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data Card data (see RateCardDefaults::card()).
	 * @throws \InvalidArgumentException When the card is structurally invalid.
	 */
	public function __construct( array $data ) {
		$errors = self::validate( $data );
		if ( $errors ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- pure class, message is developer-facing.
			throw new \InvalidArgumentException( 'Invalid rate card: ' . implode( '; ', $errors ) );
		}
		$this->data = $data;
	}

	/**
	 * The default card.
	 */
	public static function defaults(): self {
		return new self( RateCardDefaults::card() );
	}

	/**
	 * Structural validation. Returns a list of human-readable problems,
	 * empty when the card is usable. Kept static so the admin can validate
	 * an import before constructing.
	 *
	 * @param array<string, mixed> $data Candidate card.
	 * @return array<int, string>
	 */
	public static function validate( array $data ): array {
		$errors = [];
		foreach ( [ 'currency', 'blended_rate', 'service_lines', 'factors', 'urgency', 'contingency', 'range_spread', 'rounding', 'weekly_capacity', 'min_weeks', 'team_bands', 'reveal_bands', 'budget_bands', 'qualification' ] as $key ) {
			if ( ! array_key_exists( $key, $data ) ) {
				$errors[] = "missing key '{$key}'";
			}
		}
		if ( $errors ) {
			return $errors;
		}
		if ( ! is_numeric( $data['blended_rate'] ) || $data['blended_rate'] <= 0 ) {
			$errors[] = 'blended_rate must be > 0';
		}
		if ( ! is_numeric( $data['weekly_capacity'] ) || $data['weekly_capacity'] <= 0 ) {
			$errors[] = 'weekly_capacity must be > 0';
		}
		if ( ! is_numeric( $data['range_spread'] ) || $data['range_spread'] < 0 || $data['range_spread'] >= 1 ) {
			$errors[] = 'range_spread must be in [0, 1)';
		}
		if ( ! is_numeric( $data['contingency'] ) || $data['contingency'] < 0 ) {
			$errors[] = 'contingency must be >= 0';
		}
		foreach ( [ 'threshold', 'below', 'above' ] as $k ) {
			if ( ! isset( $data['rounding'][ $k ] ) || ! is_numeric( $data['rounding'][ $k ] ) || $data['rounding'][ $k ] <= 0 ) {
				$errors[] = "rounding.{$k} must be > 0";
			}
		}
		foreach ( (array) $data['service_lines'] as $id => $line ) {
			if ( ! isset( $line['base_hours'], $line['min_hours'] ) || $line['base_hours'] < 0 || $line['min_hours'] < 0 ) {
				$errors[] = "service_lines.{$id} needs non-negative base_hours and min_hours";
			}
			if ( empty( $data['team_bands'][ $id ] ) ) {
				$errors[] = "team_bands.{$id} missing";
				continue;
			}
			foreach ( (array) $data['team_bands'][ $id ] as $i => $band ) {
				$sum = array_sum( array_map( 'floatval', (array) ( $band['roles'] ?? [] ) ) );
				if ( abs( $sum - 100 ) > 0.01 ) {
					$errors[] = "team_bands.{$id}.{$i} role shares sum to {$sum}, expected 100";
				}
			}
		}
		foreach ( (array) $data['factors'] as $id => $factor ) {
			if ( ! in_array( $factor['type'] ?? '', self::FACTOR_TYPES, true ) ) {
				$errors[] = "factors.{$id}.type must be one of " . implode( '|', self::FACTOR_TYPES );
			}
			if ( ! isset( $factor['value'] ) || ! is_numeric( $factor['value'] ) ) {
				$errors[] = "factors.{$id}.value must be numeric";
			} elseif ( 'multiplier' === ( $factor['type'] ?? '' ) && $factor['value'] <= 0 ) {
				$errors[] = "factors.{$id}.value must be > 0 for a multiplier";
			}
			if ( empty( $factor['applies_to'] ) || ! is_array( $factor['applies_to'] ) ) {
				$errors[] = "factors.{$id}.applies_to must be a non-empty list";
			}
		}
		foreach ( (array) $data['urgency'] as $id => $mult ) {
			if ( ! is_numeric( $mult ) || $mult <= 0 ) {
				$errors[] = "urgency.{$id} must be > 0";
			}
		}
		return $errors;
	}

	/**
	 * Dot-path getter into the raw card.
	 *
	 * @param string $path     Dot path, e.g. 'factors.web_migration.value'.
	 * @param mixed  $fallback Fallback.
	 * @return mixed
	 */
	public function get( string $path, mixed $fallback = null ): mixed {
		return Arr::get( $this->data, $path, $fallback );
	}

	/**
	 * Raw data (for snapshots and export).
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return $this->data;
	}

	/**
	 * Card version (increments on every admin save).
	 */
	public function version(): int {
		return (int) ( $this->data['version'] ?? 0 );
	}

	/**
	 * Currency code.
	 */
	public function currency(): string {
		return (string) $this->data['currency'];
	}

	/**
	 * Whether a service line exists.
	 *
	 * @param string $line Service line id.
	 */
	public function has_service_line( string $line ): bool {
		return isset( $this->data['service_lines'][ $line ] );
	}

	/**
	 * Factors applicable to a service line, sorted by (order, id) so the
	 * application sequence is deterministic and reproducible from a snapshot.
	 *
	 * @param string $line Service line id.
	 * @return array<string, array<string, mixed>>
	 */
	public function factors_for( string $line ): array {
		$factors = array_filter(
			(array) $this->data['factors'],
			static fn( array $f ): bool => in_array( $line, (array) $f['applies_to'], true )
		);
		uksort(
			$factors,
			static fn( string $a, string $b ): int => [ (int) $factors[ $a ]['order'], $a ] <=> [ (int) $factors[ $b ]['order'], $b ]
		);
		return $factors;
	}

	/**
	 * Hourly rate for a role, falling back to the blended rate.
	 *
	 * @param string $role Role id.
	 */
	public function role_rate( string $role ): float {
		$rate = $this->data['role_rates'][ $role ] ?? null;
		return is_numeric( $rate ) && $rate > 0 ? (float) $rate : (float) $this->data['blended_rate'];
	}
}
