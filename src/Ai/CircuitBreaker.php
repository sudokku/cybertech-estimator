<?php
/**
 * Circuit breaker: 5 consecutive provider failures open the circuit for
 * 15 minutes, during which the fallback is used without calling out.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Ai;

use Cybertech\Estimator\Support\Logger;

/**
 * Circuit breaker.
 */
final class CircuitBreaker {

	public const TRANSIENT = 'ct_est_ai_breaker';
	public const THRESHOLD = 5;
	public const COOLDOWN  = 15 * MINUTE_IN_SECONDS;

	/**
	 * Current state.
	 *
	 * @return array{failures: int, open_until: int}
	 */
	public static function state(): array {
		$state = get_transient( self::TRANSIENT );
		return is_array( $state ) ? $state + [
			'failures'   => 0,
			'open_until' => 0,
		] : [
			'failures'   => 0,
			'open_until' => 0,
		];
	}

	/**
	 * Whether calls are currently blocked.
	 */
	public static function is_open(): bool {
		return self::state()['open_until'] > time();
	}

	/**
	 * Count a failure; trip when the threshold is reached.
	 *
	 * @param string $reason Why (logged).
	 */
	public static function record_failure( string $reason ): void {
		$state             = self::state();
		$state['failures'] = (int) $state['failures'] + 1;
		if ( $state['failures'] >= self::THRESHOLD ) {
			$state['open_until'] = time() + self::COOLDOWN;
			$state['failures']   = 0;
			Logger::log(
				'ai',
				'breaker_open',
				[
					'reason' => $reason,
					'until'  => $state['open_until'],
				]
			);
		}
		set_transient( self::TRANSIENT, $state, DAY_IN_SECONDS );
	}

	/**
	 * A success resets the failure streak.
	 */
	public static function record_success(): void {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * Manual reset from the admin.
	 */
	public static function reset(): void {
		delete_transient( self::TRANSIENT );
		Logger::log( 'ai', 'breaker_reset' );
	}
}
