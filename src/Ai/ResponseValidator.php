<?php
/**
 * Validates model output before anything is displayed. Any failure means
 * the fallback narrative is shown instead — the visitor never sees an error.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Ai;

/**
 * Response validator.
 */
final class ResponseValidator {

	public const WEEKS_TOLERANCE = 1;

	/**
	 * Validate raw model text against the narrative contract.
	 *
	 * @param string $raw   Raw content.
	 * @param int    $weeks Computed weeks the phases must sum to (±tolerance).
	 * @return array{ok: bool, data: array<string, mixed>, errors: array<int, string>, warnings: array<int, string>}
	 */
	public static function validate( string $raw, int $weeks ): array {
		$errors   = [];
		$warnings = [];

		$json = self::strip_fences( $raw );
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return self::fail( [ 'invalid_json' ] );
		}

		// Structure.
		foreach ( [
			'headline'    => 'string',
			'summary'     => 'string',
			'phases'      => 'array',
			'assumptions' => 'array',
			'risks'       => 'array',
		] as $key => $type ) {
			if ( ! array_key_exists( $key, $data ) ) {
				$errors[] = "missing:{$key}";
			} elseif ( ( 'string' === $type && ! is_string( $data[ $key ] ) ) || ( 'array' === $type && ! is_array( $data[ $key ] ) ) ) {
				$errors[] = "type:{$key}";
			}
		}
		if ( $errors ) {
			return self::fail( $errors );
		}

		// Strip HTML everywhere, then re-check lengths and content on the stripped text.
		$had_html = false;
		$clean    = [
			'headline'    => self::text( (string) $data['headline'], $had_html ),
			'summary'     => self::text( (string) $data['summary'], $had_html ),
			'phases'      => [],
			'assumptions' => [],
			'risks'       => [],
		];
		foreach ( $data['phases'] as $i => $phase ) {
			if ( ! is_array( $phase ) || ! isset( $phase['name'], $phase['weeks'], $phase['description'] ) || ! is_numeric( $phase['weeks'] ) ) {
				$errors[] = "phase:{$i}:shape";
				continue;
			}
			$roles = [];
			foreach ( (array) ( $phase['roles'] ?? [] ) as $role ) {
				if ( is_string( $role ) ) {
					$roles[] = self::text( $role, $had_html );
				}
			}
			$clean['phases'][] = [
				'name'        => self::text( (string) $phase['name'], $had_html ),
				'weeks'       => round( (float) $phase['weeks'], 1 ),
				'description' => self::text( (string) $phase['description'], $had_html ),
				'roles'       => array_slice( $roles, 0, 8 ),
			];
		}
		foreach ( [ 'assumptions', 'risks' ] as $list ) {
			foreach ( $data[ $list ] as $item ) {
				if ( is_string( $item ) && '' !== trim( $item ) ) {
					$clean[ $list ][] = self::text( $item, $had_html );
				}
			}
		}
		if ( $had_html ) {
			$warnings[] = 'html_stripped';
		}

		// Lengths and counts.
		if ( '' === $clean['headline'] || mb_strlen( $clean['headline'] ) > PromptBuilder::HEADLINE_MAX ) {
			$errors[] = 'headline_length';
		}
		if ( '' === $clean['summary'] || mb_strlen( $clean['summary'] ) > PromptBuilder::SUMMARY_MAX ) {
			$errors[] = 'summary_length';
		}
		if ( ! $clean['phases'] || count( $clean['phases'] ) > PromptBuilder::PHASES_MAX ) {
			$errors[] = 'phases_count';
		}
		foreach ( $clean['phases'] as $i => $phase ) {
			if ( '' === $phase['name'] || mb_strlen( $phase['name'] ) > PromptBuilder::PHASE_NAME_MAX ) {
				$errors[] = "phase:{$i}:name_length";
			}
			if ( mb_strlen( $phase['description'] ) > PromptBuilder::PHASE_DESC_MAX ) {
				$errors[] = "phase:{$i}:description_length";
			}
			if ( $phase['weeks'] < 0 ) {
				$errors[] = "phase:{$i}:negative_weeks";
			}
		}
		if ( count( $clean['assumptions'] ) > PromptBuilder::ASSUMPTIONS_MAX ) {
			$errors[] = 'assumptions_count';
		}
		if ( count( $clean['risks'] ) > PromptBuilder::RISKS_MAX ) {
			$errors[] = 'risks_count';
		}
		foreach ( array_merge( $clean['assumptions'], $clean['risks'] ) as $item ) {
			if ( mb_strlen( $item ) > PromptBuilder::LIST_ITEM_MAX ) {
				$errors[] = 'list_item_length';
				break;
			}
		}

		// Weeks must agree with the engine.
		$sum = 0.0;
		foreach ( $clean['phases'] as $phase ) {
			$sum += (float) $phase['weeks'];
		}
		if ( abs( $sum - $weeks ) > self::WEEKS_TOLERANCE ) {
			$errors[] = 'weeks_mismatch:' . $sum . '!=' . $weeks;
		}

		// Money is forbidden anywhere.
		if ( self::contains_money( self::all_text( $clean ) ) ) {
			$errors[] = 'money_detected';
		}

		if ( $errors ) {
			return self::fail( $errors, $warnings );
		}
		return [
			'ok'       => true,
			'data'     => $clean,
			'errors'   => [],
			'warnings' => $warnings,
		];
	}

	/**
	 * Remove markdown code fences that models add even in strict mode.
	 *
	 * @param string $raw Raw text.
	 */
	public static function strip_fences( string $raw ): string {
		$raw = trim( $raw );
		if ( preg_match( '/^```(?:json)?\s*(.*?)\s*```$/s', $raw, $m ) ) {
			return $m[1];
		}
		return $raw;
	}

	/**
	 * Currency symbols, currency words, or money-shaped numbers.
	 *
	 * @param string $text Text.
	 */
	public static function contains_money( string $text ): bool {
		if ( preg_match( '/[€$£]/u', $text ) ) {
			return true;
		}
		if ( preg_match( '/\b(RON|EUR|USD|GBP|lei)\b/iu', $text ) ) {
			return true;
		}
		// 12,500 / 12.500 / 1,200,000 — thousands-separated numbers are prices, never weeks.
		if ( preg_match( '/\b\d{1,3}(?:[.,]\d{3})+\b/', $text ) ) {
			return true;
		}
		// "12k" style figures.
		if ( preg_match( '/\b\d+(?:[.,]\d+)?\s?k\b/i', $text ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Strip tags and normalise whitespace; flags when tags were present.
	 *
	 * @param string $text     Text.
	 * @param bool   $had_html Set to true when tags were found.
	 */
	private static function text( string $text, bool &$had_html ): string {
		if ( preg_match( '/<[a-zA-Z\/!][^>]*>/', $text ) ) {
			$had_html = true;
			// Detect tags before stripping: wp_strip_all_tags also trims, which is not "HTML".
		}
		$stripped = wp_strip_all_tags( $text );
		return trim( (string) preg_replace( '/\s+/u', ' ', $stripped ) );
	}

	/**
	 * Concatenate every string in the narrative for scanning.
	 *
	 * @param array<string, mixed> $clean Cleaned narrative.
	 */
	private static function all_text( array $clean ): string {
		$parts = [ $clean['headline'], $clean['summary'] ];
		foreach ( $clean['phases'] as $phase ) {
			$parts[] = $phase['name'];
			$parts[] = $phase['description'];
			$parts   = array_merge( $parts, $phase['roles'] );
		}
		return implode( "\n", array_merge( $parts, $clean['assumptions'], $clean['risks'] ) );
	}

	/**
	 * Failure shape.
	 *
	 * @param array<int, string> $errors   Errors.
	 * @param array<int, string> $warnings Warnings.
	 * @return array{ok: bool, data: array<string, mixed>, errors: array<int, string>, warnings: array<int, string>}
	 */
	private static function fail( array $errors, array $warnings = [] ): array {
		return [
			'ok'       => false,
			'data'     => [],
			'errors'   => $errors,
			'warnings' => $warnings,
		];
	}
}
