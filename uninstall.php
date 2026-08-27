<?php
/**
 * Uninstall: remove options, transients and cron events. Leads are removed
 * only when the "delete leads on uninstall" setting was switched on, because
 * a sales pipeline is not something a plugin should silently destroy.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$ct_est_settings = get_option( 'ct_est_settings', [] );
$ct_est_purge    = is_array( $ct_est_settings ) && ! empty( $ct_est_settings['privacy']['delete_leads_on_uninstall'] );

if ( $ct_est_purge ) {
	$ct_est_ids = get_posts(
		[
			'post_type'     => 'ct_estimate_lead',
			'post_status'   => 'any',
			'numberposts'   => -1,
			'fields'        => 'ids',
			'no_found_rows' => true,
		]
	);
	foreach ( $ct_est_ids as $ct_est_id ) {
		wp_delete_post( (int) $ct_est_id, true );
	}
}

foreach ( [ 'ct_est_retention_daily', 'ct_est_webhook_dispatch' ] as $ct_est_hook ) {
	wp_clear_scheduled_hook( $ct_est_hook );
}

// Options and transients all share the ct_est_ prefix by convention.
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- uninstall cleanup by prefix has no API equivalent.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'ct_est_' ) . '%' ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_ct_est_' ) . '%' ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_timeout_ct_est_' ) . '%' ) );
// phpcs:enable
