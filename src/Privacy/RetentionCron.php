<?php
/**
 * Daily retention job: anonymise leads older than the configured window.
 * Also registers the suggested privacy-policy text.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Privacy;

use Cybertech\Estimator\Brand;
use Cybertech\Estimator\Lead\LeadPostType;
use Cybertech\Estimator\Lead\LeadRepository;
use Cybertech\Estimator\Support\Settings;

/**
 * Retention cron.
 */
final class RetentionCron {

	public const HOOK  = 'ct_est_retention_daily';
	public const BATCH = 200;

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( 'init', [ $this, 'ensure_scheduled' ] );
		add_action( self::HOOK, [ $this, 'run' ] );
		add_action( 'admin_init', [ $this, 'privacy_policy_content' ] );
	}

	/**
	 * Schedule once; cheap check on every init.
	 */
	public function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Anonymise leads past the retention window.
	 *
	 * @return int Number of leads anonymised.
	 */
	public function run(): int {
		$days = (int) Settings::get( 'privacy.retention_days' );
		if ( $days <= 0 ) {
			return 0;
		}
		$ids = get_posts(
			[
				'post_type'        => LeadPostType::POST_TYPE,
				'post_status'      => 'any',
				'numberposts'      => self::BATCH,
				'fields'           => 'ids',
				'suppress_filters' => true,
				'date_query'       => [
					[
						'before' => gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ),
						'column' => 'post_date_gmt',
					],
				],
				'meta_query'       => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[
						'key'     => LeadRepository::META_ANONYMISED,
						'compare' => 'NOT EXISTS',
					],
				],
			]
		);
		foreach ( $ids as $id ) {
			DataEraser::anonymise( (int) $id, 'retention' );
		}
		return count( $ids );
	}

	/**
	 * Suggested privacy-policy text (Settings → Privacy → Policy Guide).
	 */
	public function privacy_policy_content(): void {
		// admin_init also fires under WP-CLI, where core rejects the call.
		if ( ! is_admin() || ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$days    = (int) Settings::get( 'privacy.retention_days' );
		$company = Brand::get( 'company' );
		$content = '<p>' . esc_html(
			sprintf(
			/* translators: 1: company, 2: retention days */
				__( 'When you use the project estimator, %1$s stores the answers you give, your name, email address, company and phone number (if provided), the free-text notes you enter, the calculated estimate, the consent text you agreed to and the time of your request. This data is used to prepare and follow up on your estimate. Personal details are removed automatically after %2$d days; the anonymous estimate data is kept for statistics. If AI narration is enabled, the answers and notes (never your contact details) are sent to the configured AI provider to generate a plain-language summary. No third-party CAPTCHA is used. You can request an export or erasure of your data at any time.', 'cybertech-estimator' ),
				$company,
				$days
			)
		) . '</p>';
		wp_add_privacy_policy_content( 'Cybertech Project Estimator', wp_kses_post( $content ) );
	}
}
