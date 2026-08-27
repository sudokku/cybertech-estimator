<?php
/**
 * Named permission callback for the public endpoints. Never a bare `true`:
 * it runs the rate limiter so the intent is legible to a reviewer.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Rest;

use Cybertech\Estimator\Security\RateLimiter;
use Cybertech\Estimator\Support\Logger;
use WP_Error;

/**
 * Public endpoint gate.
 */
final class PublicAccess {

	/**
	 * Build a permission callback for `$action` (preview | submit).
	 *
	 * @param string $action Rate-limit bucket.
	 * @return callable(): (bool|WP_Error)
	 */
	public static function for( string $action ): callable {
		return static function () use ( $action ): bool|WP_Error {
			$limiter = new RateLimiter();
			if ( $limiter->allow( $action ) ) {
				return true;
			}
			Logger::log(
				'security',
				'rate_limited',
				[
					'action' => $action,
					'ip'     => substr( RateLimiter::ip_hash(), 0, 12 ),
				]
			);
			return new WP_Error(
				'ct_est_rate_limited',
				__( 'You have reached the limit of estimates for now. Please try again in an hour or contact us directly.', 'cybertech-estimator' ),
				[
					'status'      => 429,
					'retry_after' => $limiter->retry_after(),
				]
			);
		};
	}
}
