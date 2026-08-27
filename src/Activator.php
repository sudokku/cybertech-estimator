<?php
/**
 * Activation / deactivation lifecycle.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator;

use Cybertech\Estimator\Frontend\SharePage;
use Cybertech\Estimator\Lead\LeadPostType;

/**
 * Activation lifecycle.
 */
final class Activator {

	public const OPTION_VERSION = 'ct_est_version';

	/**
	 * Activation: register the CPT so its rewrite rules exist, then flush once.
	 * Rate-card defaults are installed lazily by RateCard::load() so a fresh
	 * install and an upgrade take the same path.
	 */
	public static function activate(): void {
		( new LeadPostType() )->register_post_type();
		( new SharePage() )->add_rewrite_rule();
		flush_rewrite_rules();
		update_option( self::OPTION_VERSION, CT_EST_VERSION, false );
	}

	/**
	 * Deactivation: unschedule our cron events and flush rules so the
	 * `/estimate/{token}` route disappears cleanly.
	 */
	public static function deactivate(): void {
		foreach ( [ 'ct_est_retention_daily', 'ct_est_webhook_dispatch' ] as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
		flush_rewrite_rules();
	}
}
