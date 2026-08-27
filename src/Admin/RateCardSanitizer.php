<?php
/**
 * Turns an untrusted rate-card array (the editor form or an imported JSON
 * file) into a typed card array with only the keys the engine knows about.
 *
 * Type coercion lives here; *semantic* validation (sums, ranges, required
 * keys) stays in RateCard::validate() so the two never disagree. Unknown
 * top-level keys and unknown per-item fields are dropped; collection ids
 * (factor, role, service line…) are kept so an import can add rows the
 * form cannot.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Admin;

/**
 * Rate card input sanitiser.
 */
final class RateCardSanitizer {

	/**
	 * Sanitise a candidate card. `format` and `version` are intentionally
	 * absent from the output: the repository owns them.
	 *
	 * @param array<string, mixed> $in Untrusted input.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $in ): array {
		$lines = [];
		foreach ( (array) ( $in['service_lines'] ?? [] ) as $id => $line ) {
			$id = sanitize_key( (string) $id );
			if ( '' === $id || ! is_array( $line ) ) {
				continue;
			}
			$lines[ $id ] = [
				'label'      => self::text( $line['label'] ?? '' ),
				'base_hours' => self::num( $line['base_hours'] ?? null ),
				'min_hours'  => self::num( $line['min_hours'] ?? null ),
			];
		}

		return [
			'currency'        => self::currency( $in['currency'] ?? '' ),
			'blended_rate'    => self::num( $in['blended_rate'] ?? null ),
			'role_rates'      => self::num_map( $in['role_rates'] ?? [] ),
			'service_lines'   => $lines,
			'factors'         => self::factors( $in['factors'] ?? [], array_keys( $lines ) ),
			'urgency'         => self::num_map( $in['urgency'] ?? [] ),
			'contingency'     => self::num( $in['contingency'] ?? null ),
			'range_spread'    => self::num( $in['range_spread'] ?? null ),
			'rounding'        => [
				'threshold' => self::num( $in['rounding']['threshold'] ?? null ),
				'below'     => self::num( $in['rounding']['below'] ?? null ),
				'above'     => self::num( $in['rounding']['above'] ?? null ),
			],
			'weekly_capacity' => self::num( $in['weekly_capacity'] ?? null ),
			'min_weeks'       => (int) ( self::num( $in['min_weeks'] ?? null ) ?? 0 ),
			'team_bands'      => self::team_bands( $in['team_bands'] ?? [] ),
			'reveal_bands'    => self::reveal_bands( $in['reveal_bands'] ?? [] ),
			'budget_bands'    => self::budget_bands( $in['budget_bands'] ?? [] ),
			'qualification'   => self::qualification( $in['qualification'] ?? [] ),
		];
	}

	/**
	 * Factor table.
	 *
	 * @param mixed              $raw   Raw factors.
	 * @param array<int, string> $lines Known service line ids (applies_to is filtered to these).
	 * @return array<string, array<string, mixed>>
	 */
	private static function factors( mixed $raw, array $lines ): array {
		$out = [];
		foreach ( (array) $raw as $id => $f ) {
			$id = sanitize_key( (string) $id );
			if ( '' === $id || ! is_array( $f ) ) {
				continue;
			}
			$applies = array_map( static fn( $l ): string => sanitize_key( (string) $l ), (array) ( $f['applies_to'] ?? [] ) );
			$applies = array_values( array_unique( array_filter( $applies, static fn( string $l ): bool => in_array( $l, $lines, true ) ) ) );

			$out[ $id ] = [
				'label'      => self::text( $f['label'] ?? '' ),
				'applies_to' => $applies,
				// An unknown type is passed through (as a key) so the validator names it in its error.
				'type'       => sanitize_key( (string) ( $f['type'] ?? '' ) ),
				'value'      => self::num( $f['value'] ?? null ),
				'order'      => (int) ( self::num( $f['order'] ?? null ) ?? 0 ),
				'per_unit'   => self::flag( $f['per_unit'] ?? false ),
				'note'       => self::text( $f['note'] ?? '' ),
			];
		}
		return $out;
	}

	/**
	 * Team bands: line => list of {max_hours, roles{role => percent}}.
	 *
	 * @param mixed $raw Raw bands.
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private static function team_bands( mixed $raw ): array {
		$out = [];
		foreach ( (array) $raw as $line => $bands ) {
			$line = sanitize_key( (string) $line );
			if ( '' === $line || ! is_array( $bands ) ) {
				continue;
			}
			$list = [];
			foreach ( $bands as $band ) {
				if ( ! is_array( $band ) ) {
					continue;
				}
				$roles = [];
				foreach ( (array) ( $band['roles'] ?? [] ) as $role => $pct ) {
					$role = sanitize_key( (string) $role );
					if ( '' !== $role ) {
						$roles[ $role ] = self::num( $pct ) ?? 0;
					}
				}
				$list[] = [
					'max_hours' => self::num( $band['max_hours'] ?? null ),
					'roles'     => $roles,
				];
			}
			$out[ $line ] = $list;
		}
		return $out;
	}

	/**
	 * Reveal bands: ordered list of {id, label, max_price|null}.
	 *
	 * @param mixed $raw Raw bands.
	 * @return array<int, array<string, mixed>>
	 */
	private static function reveal_bands( mixed $raw ): array {
		$out = [];
		foreach ( (array) $raw as $band ) {
			if ( ! is_array( $band ) ) {
				continue;
			}
			$id = sanitize_key( (string) ( $band['id'] ?? '' ) );
			if ( '' === $id ) {
				continue;
			}
			$out[] = [
				'id'        => $id,
				'label'     => self::text( $band['label'] ?? '' ),
				'max_price' => self::num( $band['max_price'] ?? null ),
			];
		}
		return $out;
	}

	/**
	 * Budget bands: id => {min|null, max|null}.
	 *
	 * @param mixed $raw Raw bands.
	 * @return array<string, array<string, mixed>>
	 */
	private static function budget_bands( mixed $raw ): array {
		$out = [];
		foreach ( (array) $raw as $id => $band ) {
			$id = sanitize_key( (string) $id );
			if ( '' === $id || ! is_array( $band ) ) {
				continue;
			}
			$out[ $id ] = [
				'min' => self::num( $band['min'] ?? null ),
				'max' => self::num( $band['max'] ?? null ),
			];
		}
		return $out;
	}

	/**
	 * Qualification weights. The shape is fixed by PricingEngine::qualify(),
	 * so keys are allow-listed rather than passed through.
	 *
	 * @param mixed $raw Raw weights.
	 * @return array<string, mixed>
	 */
	private static function qualification( mixed $raw ): array {
		$q     = is_array( $raw ) ? $raw : [];
		$scope = [];
		foreach ( (array) ( $q['scope'] ?? [] ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$scope[] = [
				'max_hours' => self::num( $row['max_hours'] ?? null ),
				'points'    => (int) ( self::num( $row['points'] ?? null ) ?? 0 ),
			];
		}
		return [
			'budget'      => self::int_map( $q['budget'] ?? [] ),
			'urgency'     => self::int_map( $q['urgency'] ?? [] ),
			'scope'       => $scope,
			'notes'       => [
				'min_chars' => (int) ( self::num( $q['notes']['min_chars'] ?? null ) ?? 0 ),
				'points'    => (int) ( self::num( $q['notes']['points'] ?? null ) ?? 0 ),
			],
			'maintenance' => [ 'points' => (int) ( self::num( $q['maintenance']['points'] ?? null ) ?? 0 ) ],
			'hosting'     => [ 'points' => (int) ( self::num( $q['hosting']['points'] ?? null ) ?? 0 ) ],
			'thresholds'  => [
				'green' => (int) ( self::num( $q['thresholds']['green'] ?? null ) ?? 0 ),
				'amber' => (int) ( self::num( $q['thresholds']['amber'] ?? null ) ?? 0 ),
			],
		];
	}

	/**
	 * key => number map (role rates, urgency multipliers).
	 *
	 * @param mixed $raw Raw map.
	 * @return array<string, int|float|null>
	 */
	private static function num_map( mixed $raw ): array {
		$out = [];
		foreach ( (array) $raw as $k => $v ) {
			$k = sanitize_key( (string) $k );
			if ( '' !== $k ) {
				$out[ $k ] = self::num( $v );
			}
		}
		return $out;
	}

	/**
	 * key => int map (qualification points).
	 *
	 * @param mixed $raw Raw map.
	 * @return array<string, int>
	 */
	private static function int_map( mixed $raw ): array {
		$out = [];
		foreach ( self::num_map( $raw ) as $k => $v ) {
			$out[ $k ] = (int) ( $v ?? 0 );
		}
		return $out;
	}

	/**
	 * Numeric coercion. Empty / non-numeric → null so the validator reports
	 * a missing value instead of silently pricing with 0. Integers stay
	 * integers so the exported JSON reads like the defaults.
	 *
	 * @param mixed $v Raw value.
	 */
	private static function num( mixed $v ): int|float|null {
		if ( is_int( $v ) || is_float( $v ) ) {
			return $v;
		}
		$s = is_string( $v ) ? trim( $v ) : '';
		if ( '' === $s || ! is_numeric( $s ) ) {
			return null;
		}
		return preg_match( '/[.eE]/', $s ) ? (float) $s : (int) $s;
	}

	/**
	 * Checkbox / JSON boolean.
	 *
	 * @param mixed $v Raw value.
	 */
	private static function flag( mixed $v ): bool {
		return (bool) filter_var( $v, FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Single-line text.
	 *
	 * @param mixed $v Raw value.
	 */
	private static function text( mixed $v ): string {
		return sanitize_text_field( (string) $v );
	}

	/**
	 * ISO 4217-ish code: letters only, upper-cased, 3 chars.
	 *
	 * @param mixed $v Raw value.
	 */
	private static function currency( mixed $v ): string {
		return strtoupper( substr( (string) preg_replace( '/[^A-Za-z]/', '', (string) $v ), 0, 3 ) );
	}
}
