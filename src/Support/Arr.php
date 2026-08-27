<?php
/**
 * Small array helpers used across the engine and admin.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Support;

/**
 * Array helpers.
 */
final class Arr {

	/**
	 * Dot-notation getter: Arr::get( $card, 'factors.web_migration.value' ).
	 *
	 * @param array<string, mixed> $data    Source.
	 * @param string               $path    Dot path.
	 * @param mixed                $fallback Fallback.
	 * @return mixed
	 */
	public static function get( array $data, string $path, mixed $fallback = null ): mixed {
		$node = $data;
		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! is_array( $node ) || ! array_key_exists( $segment, $node ) ) {
				return $fallback;
			}
			$node = $node[ $segment ];
		}
		return $node;
	}

	/**
	 * Dot-notation setter (returns a new array; input is not mutated).
	 *
	 * @param array<string, mixed> $data  Source.
	 * @param string               $path  Dot path.
	 * @param mixed                $value Value.
	 * @return array<string, mixed>
	 */
	public static function set( array $data, string $path, mixed $value ): array {
		$segments = explode( '.', $path );
		$ref      = &$data;
		foreach ( $segments as $segment ) {
			if ( ! isset( $ref[ $segment ] ) || ! is_array( $ref[ $segment ] ) ) {
				$ref[ $segment ] = [];
			}
			$ref = &$ref[ $segment ];
		}
		$ref = $value;
		return $data;
	}
}
