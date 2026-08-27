<?php
/**
 * Bot defence without a third-party CAPTCHA (deliberate: no visitor data
 * leaves the site for a bot check). Two signals:
 *  - a honeypot text field hidden by CSS position, which humans never fill;
 *  - a signed render timestamp; submissions faster than the configured
 *    minimum are rejected. Signing stops bots from back-dating the field.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Security;

use Cybertech\Estimator\Support\Settings;

/**
 * Honeypot + time-on-form.
 */
final class Honeypot {

	public const FIELD_HONEY = 'ct_est_website';
	// Looks like a real field to a form-filler.
	public const FIELD_TOKEN = 'ct_est_t0';
	public const MAX_AGE     = DAY_IN_SECONDS;

	/**
	 * Token embedded at render time: "<ts>.<hmac>".
	 */
	public static function issue_token(): string {
		$ts = (string) time();
		return $ts . '.' . self::sign( $ts );
	}

	/**
	 * Verify a submission. Returns null when human-looking, otherwise a
	 * short machine reason (logged, never shown verbatim).
	 *
	 * @param string $honey Honeypot field value.
	 * @param string $token Timestamp token.
	 */
	public static function reject_reason( string $honey, string $token ): ?string {
		if ( '' !== trim( $honey ) ) {
			return 'honeypot_filled';
		}
		$parts = explode( '.', $token, 2 );
		if ( 2 !== count( $parts ) || ! ctype_digit( $parts[0] ) ) {
			return 'token_missing';
		}
		[ $ts, $sig ] = $parts;
		if ( ! hash_equals( self::sign( $ts ), $sig ) ) {
			return 'token_forged';
		}
		$age = time() - (int) $ts;
		if ( $age < (int) Settings::get( 'security.min_seconds' ) ) {
			return 'too_fast';
		}
		if ( $age > self::MAX_AGE ) {
			return 'token_expired';
		}
		return null;
	}

	/**
	 * HMAC over the timestamp with the site's auth salt.
	 *
	 * @param string $ts Unix timestamp.
	 */
	private static function sign( string $ts ): string {
		return substr( hash_hmac( 'sha256', 'ct_est_t0|' . $ts, wp_salt( 'auth' ) ), 0, 32 );
	}
}
