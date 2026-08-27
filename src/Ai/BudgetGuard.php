<?php
/**
 * Monthly spend guard. Tracks cents per calendar month; warns the admin at
 * 80% and switches to the fallback (plus one email) at 100%.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Ai;

use Cybertech\Estimator\Support\Logger;
use Cybertech\Estimator\Support\Settings;

/**
 * Budget guard.
 */
final class BudgetGuard {

	public const OPTION_PREFIX = 'ct_est_ai_spend_';
	public const NOTIFIED_80   = 'ct_est_ai_budget80_';
	public const NOTIFIED_100  = 'ct_est_ai_budget100_';

	/**
	 * Option key for the current month.
	 */
	public static function month_key(): string {
		return gmdate( 'Y-m' );
	}

	/**
	 * Spend so far this month, in cents.
	 */
	public static function spent_cents(): int {
		return (int) get_option( self::OPTION_PREFIX . self::month_key(), 0 );
	}

	/**
	 * Configured monthly budget in cents (0 = unlimited).
	 */
	public static function budget_cents(): int {
		return max( 0, (int) Settings::get( 'ai.monthly_budget_cents' ) );
	}

	/**
	 * Whether another call is allowed.
	 */
	public static function can_spend(): bool {
		$budget = self::budget_cents();
		return 0 === $budget || self::spent_cents() < $budget;
	}

	/**
	 * Record a call's cost and fire the threshold notices.
	 *
	 * @param float $cost_usd Cost in USD (fractions of a cent are kept by rounding up to whole cents only at 1c granularity across calls).
	 */
	public static function record( float $cost_usd ): void {
		$key   = self::OPTION_PREFIX . self::month_key();
		$cents = (int) get_option( $key, 0 ) + (int) ceil( $cost_usd * 100 );
		update_option( $key, $cents, false );

		$budget = self::budget_cents();
		if ( 0 === $budget ) {
			return;
		}
		if ( $cents >= $budget && ! get_option( self::NOTIFIED_100 . self::month_key() ) ) {
			update_option( self::NOTIFIED_100 . self::month_key(), 1, false );
			Logger::log(
				'ai',
				'budget_exhausted',
				[
					'cents'  => $cents,
					'budget' => $budget,
				]
			);
			wp_mail(
				(string) get_option( 'admin_email' ),
				__( '[Estimator] AI narration budget reached — fallback text is now used', 'cybertech-estimator' ),
				sprintf(
					/* translators: 1: spent, 2: budget */
					__( 'The AI narration budget for this month is used up (%1$s of %2$s). Visitors still receive complete estimates with the built-in narrative. Raise the budget under Estimator → Settings → AI to resume.', 'cybertech-estimator' ),
					number_format( $cents / 100, 2 ) . ' USD',
					number_format( $budget / 100, 2 ) . ' USD'
				)
			);
		} elseif ( $cents >= (int) ( $budget * 0.8 ) && ! get_option( self::NOTIFIED_80 . self::month_key() ) ) {
			update_option( self::NOTIFIED_80 . self::month_key(), 1, false );
			Logger::log(
				'ai',
				'budget_80_percent',
				[
					'cents'  => $cents,
					'budget' => $budget,
				]
			);
		}//end if
	}

	/**
	 * Admin-notice state: 'ok' | 'warning' (≥80%) | 'exhausted'.
	 */
	public static function state(): string {
		$budget = self::budget_cents();
		if ( 0 === $budget ) {
			return 'ok';
		}
		$spent = self::spent_cents();
		if ( $spent >= $budget ) {
			return 'exhausted';
		}
		return $spent >= (int) ( $budget * 0.8 ) ? 'warning' : 'ok';
	}
}
