<?php
/**
 * Share tokens for the public estimate page.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Lead;

use Cybertech\Estimator\Support\Settings;

/**
 * Token helpers.
 */
final class ShareToken {

	public const META_TOKEN   = '_ct_share_token';
	public const META_EXPIRES = '_ct_share_expires';
	public const META_ENABLED = '_ct_share_enabled';
	public const LENGTH       = 32;

	/**
	 * New 32-char alphanumeric token.
	 */
	public static function generate(): string {
		return wp_generate_password( self::LENGTH, false, false );
	}

	/**
	 * Well-formed token?
	 *
	 * @param string $token Candidate.
	 */
	public static function is_valid_format( string $token ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9]{32}$/', $token );
	}

	/**
	 * Public share URL for a token.
	 *
	 * @param string $token Token.
	 */
	public static function url( string $token ): string {
		return home_url( '/estimate/' . rawurlencode( $token ) . '/' );
	}

	/**
	 * Default expiry timestamp for a new lead.
	 */
	public static function default_expiry(): int {
		return time() + max( 1, (int) Settings::get( 'general.share_days' ) ) * DAY_IN_SECONDS;
	}

	/**
	 * Lead id for a token, or 0. Meta lookup on an exact key is indexed by
	 * meta_key in wp_postmeta, so this stays cheap.
	 *
	 * @param string $token Token.
	 */
	public static function find_lead( string $token ): int {
		if ( ! self::is_valid_format( $token ) ) {
			return 0;
		}
		$ids = get_posts(
			[
				'post_type'        => LeadPostType::POST_TYPE,
				'post_status'      => 'any',
				'numberposts'      => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_key'         => self::META_TOKEN, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => $token,           // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			]
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Share state for a lead: 'ok' | 'disabled' | 'expired' | 'missing'.
	 *
	 * @param int $lead_id Lead id.
	 */
	public static function state( int $lead_id ): string {
		if ( $lead_id <= 0 || LeadPostType::POST_TYPE !== get_post_type( $lead_id ) ) {
			return 'missing';
		}
		if ( ! get_post_meta( $lead_id, self::META_ENABLED, true ) ) {
			return 'disabled';
		}
		$expires = (int) get_post_meta( $lead_id, self::META_EXPIRES, true );
		if ( $expires > 0 && $expires < time() ) {
			return 'expired';
		}
		return 'ok';
	}
}
