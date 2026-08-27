<?php
/**
 * Transient-backed rate limiter keyed on the hashed IP and, when present,
 * the wizard session cookie. Raw IPs are never stored.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Security;

use Cybertech\Estimator\Support\Settings;

/**
 * Rate limiting.
 */
final class RateLimiter {

	public const COOKIE = 'ct_est_sid';
	public const WINDOW = HOUR_IN_SECONDS;

	/**
	 * Whether another `$action` is allowed for this visitor. Checks both the
	 * IP bucket and the session bucket; either being full blocks.
	 *
	 * @param string $action preview | submit.
	 */
	public function allow( string $action ): bool {
		$limit = (int) Settings::get( "security.{$action}_per_hour" );
		if ( $limit <= 0 ) {
			return true;
		}
		foreach ( $this->keys( $action ) as $key ) {
			if ( (int) get_transient( $key ) >= $limit ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Count one `$action`.
	 *
	 * @param string $action preview | submit.
	 */
	public function hit( string $action ): void {
		foreach ( $this->keys( $action ) as $key ) {
			$count = (int) get_transient( $key );
			set_transient( $key, $count + 1, self::WINDOW );
		}
	}

	/**
	 * Seconds until the window resets (approximate; for Retry-After).
	 */
	public function retry_after(): int {
		return self::WINDOW;
	}

	/**
	 * Hashed visitor IP (wp_hash → never reversible to the address).
	 */
	public static function ip_hash(): string {
		return wp_hash( self::client_ip() );
	}

	/**
	 * Best-effort client IP. REMOTE_ADDR only — proxy headers are spoofable
	 * and a misconfigured trust would let a bot bypass the limiter.
	 */
	public static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
	}

	/**
	 * Session id from the wizard cookie, when well-formed.
	 */
	public static function session_id(): string {
		$sid = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( (string) $_COOKIE[ self::COOKIE ] ) ) : '';
		return preg_match( '/^[A-Za-z0-9]{16,64}$/', $sid ) ? $sid : '';
	}

	/**
	 * Transient keys for the buckets that apply to this request.
	 *
	 * @param string $action Action.
	 * @return array<int, string>
	 */
	private function keys( string $action ): array {
		$keys = [ 'ct_est_rl_' . $action . '_ip_' . substr( self::ip_hash(), 0, 32 ) ];
		$sid  = self::session_id();
		if ( '' !== $sid ) {
			$keys[] = 'ct_est_rl_' . $action . '_sid_' . substr( wp_hash( $sid ), 0, 32 );
		}
		return $keys;
	}
}
