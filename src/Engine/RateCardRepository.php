<?php
/**
 * Load/save/version the rate card in wp_options. The only place the card
 * touches WordPress; the engine only ever sees a RateCard value object.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Engine;

/**
 * Rate card persistence.
 */
final class RateCardRepository {

	public const OPTION         = 'ct_est_rate_card';
	public const OPTION_HISTORY = 'ct_est_rate_card_history';
	public const HISTORY_LIMIT  = 10;

	/**
	 * Current card. Falls back to defaults (and installs them) when the
	 * option is missing or corrupt, so the wizard never fails to price.
	 */
	public function load(): RateCard {
		$data = get_option( self::OPTION );
		if ( is_array( $data ) && ! RateCard::validate( $data ) ) {
			return new RateCard( $data );
		}
		$defaults = RateCardDefaults::card();
		update_option( self::OPTION, $defaults, false );
		return new RateCard( $defaults );
	}

	/**
	 * Raw current data (for the admin editor).
	 *
	 * @return array<string, mixed>
	 */
	public function raw(): array {
		return $this->load()->to_array();
	}

	/**
	 * Validate and persist a new card. Version auto-increments; the
	 * previous card goes to the history ring.
	 *
	 * @param array<string, mixed> $data Candidate card (version is overwritten).
	 * @return array<int, string> Validation errors; empty on success.
	 */
	public function save( array $data ): array {
		$errors = RateCard::validate( $data );
		if ( $errors ) {
			return $errors;
		}
		$current         = $this->load()->to_array();
		$data['format']  = RateCardDefaults::FORMAT;
		$data['version'] = (int) ( $current['version'] ?? 0 ) + 1;

		$history = get_option( self::OPTION_HISTORY, [] );
		$history = is_array( $history ) ? $history : [];
		array_unshift(
			$history,
			[
				'version'  => (int) ( $current['version'] ?? 0 ),
				'saved_at' => time(),
				'user_id'  => get_current_user_id(),
				'card'     => $current,
			]
		);
		update_option( self::OPTION_HISTORY, array_slice( $history, 0, self::HISTORY_LIMIT ), false );
		update_option( self::OPTION, $data, false );
		return [];
	}

	/**
	 * Saved history, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function history(): array {
		$history = get_option( self::OPTION_HISTORY, [] );
		return is_array( $history ) ? $history : [];
	}

	/**
	 * Roll back to a historic version (saved as a new version, so the
	 * audit trail stays linear).
	 *
	 * @param int $version Historic version number.
	 * @return array<int, string> Errors; empty on success.
	 */
	public function rollback( int $version ): array {
		foreach ( $this->history() as $entry ) {
			if ( (int) $entry['version'] === $version ) {
				return $this->save( (array) $entry['card'] );
			}
		}
		return [ sprintf( 'Version %d is not in the history.', $version ) ];
	}

	/**
	 * Reset to defaults (saved as a new version).
	 *
	 * @return array<int, string>
	 */
	public function reset(): array {
		return $this->save( RateCardDefaults::card() );
	}
}
