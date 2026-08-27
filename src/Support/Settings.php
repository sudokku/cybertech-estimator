<?php
/**
 * Plugin settings store: one option (`ct_est_settings`), grouped by tab,
 * with defaults merged on read so a missing key never yields null.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Support;

/**
 * Settings access.
 */
final class Settings {

	public const OPTION = 'ct_est_settings';

	/**
	 * Defaults. Business defaults accepted 2026-08-27 (docs/DECISIONS.md).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function defaults(): array {
		return [
			'general'       => [
				'reveal_mode'                          => 'gated',
				// open | band | gated.
										'contact_page' => '',
				// URL for the no-JS / expired-link fallback CTA; empty = mailto brand email.
										'share_days'   => 90,
		// Share-link validity.
			],
			'security'      => [
				'preview_per_hour'   => 10,
				'submit_per_hour'    => 3,
				'narrative_per_hour' => 6,
				'min_seconds'        => 3,
				'store_ip'           => false,
			// When on, the hashed IP is stored on the lead.
			],
			'ai'            => [
				'enabled'                        => false,
				'provider'                       => 'openrouter',
				'api_key'                        => '',
				'model'                          => '',
				'floor'                          => false,
				'max_price'                      => 0.0,
				// USD per 1M tokens ceiling; 0 = none.
									'max_tokens' => 700,
				'timeout'                        => 8,
				'monthly_budget_cents'           => 500,
				'cache_days'                     => 30,
			],
			'notifications' => [
				'sales_email'                           => '',
				// Empty = admin_email.
									'send_confirmation' => true,
			],
			'integrations'  => [
				'webhook_url'    => '',
				'webhook_secret' => '',
			],
			'privacy'       => [
				'retention_days'            => 365,
				'delete_leads_on_uninstall' => false,
			],
		];
	}

	/**
	 * Read `group.key`, falling back to the default.
	 *
	 * @param string $path Dot path, e.g. 'general.reveal_mode'.
	 * @return mixed
	 */
	public static function get( string $path ): mixed {
		$all = self::all();
		return Arr::get( $all, $path, Arr::get( self::defaults(), $path ) );
	}

	/**
	 * Full settings with defaults merged (two levels).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		$saved = get_option( self::OPTION, [] );
		$saved = is_array( $saved ) ? $saved : [];
		$out   = self::defaults();
		foreach ( $out as $group => $keys ) {
			foreach ( $keys as $key => $default ) {
				if ( isset( $saved[ $group ] ) && is_array( $saved[ $group ] ) && array_key_exists( $key, $saved[ $group ] ) ) {
					$out[ $group ][ $key ] = $saved[ $group ][ $key ];
				}
			}
		}
		return $out;
	}

	/**
	 * Persist one group (used by the Settings API sanitize callbacks and tests).
	 *
	 * @param string               $group  Group name.
	 * @param array<string, mixed> $values Values for that group.
	 */
	public static function update_group( string $group, array $values ): void {
		$saved           = get_option( self::OPTION, [] );
		$saved           = is_array( $saved ) ? $saved : [];
		$saved[ $group ] = array_merge( (array) ( $saved[ $group ] ?? [] ), $values );
		update_option( self::OPTION, $saved, false );
	}

	/**
	 * Reveal mode, guaranteed to be one of the three known values.
	 */
	public static function reveal_mode(): string {
		$mode = (string) self::get( 'general.reveal_mode' );
		return in_array( $mode, [ 'open', 'band', 'gated' ], true ) ? $mode : 'gated';
	}
}
