<?php
/**
 * Core privacy eraser and the shared anonymisation routine (also used by
 * the retention cron): strips personal fields, keeps the anonymous
 * estimate for analytics, disables the share link.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Privacy;

use Cybertech\Estimator\Lead\LeadRepository;
use Cybertech\Estimator\Lead\ShareToken;
use Cybertech\Estimator\Support\Logger;

/**
 * Personal data eraser.
 */
final class DataEraser {

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_erasers', [ $this, 'register_eraser' ] );
	}

	/**
	 * Register with core.
	 *
	 * @param array<string, array<string, mixed>> $erasers Erasers.
	 * @return array<string, array<string, mixed>>
	 */
	public function register_eraser( array $erasers ): array {
		$erasers['cybertech-estimator'] = [
			'eraser_friendly_name' => __( 'Project estimates', 'cybertech-estimator' ),
			'callback'             => [ $this, 'erase' ],
		];
		return $erasers;
	}

	/**
	 * Anonymise every lead for an email (paged like the exporter).
	 *
	 * @param string $email_address Email.
	 * @param int    $page          Page.
	 * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool}
	 */
	public function erase( string $email_address, int $page = 1 ): array {
		unset( $page );
		// Anonymising removes the email match, so every call restarts at page 1 until empty.
		$leads   = DataExporter::leads_for_email( $email_address, 1 );
		$removed = false;
		foreach ( $leads as $lead_id ) {
			self::anonymise( $lead_id, 'erasure_request' );
			$removed = true;
		}
		return [
			'items_removed'            => $removed,
			'items_retained'           => $removed,
			// The anonymous estimate stays; say so.
							'messages' => $removed ? [ __( 'Personal details were removed from the estimate requests; the anonymous estimate data (answers, hours, range) is retained for statistics.', 'cybertech-estimator' ) ] : [],
			'done'                     => count( $leads ) < DataExporter::PAGE_SIZE,
		];
	}

	/**
	 * Strip personal fields from a lead. Idempotent.
	 *
	 * @param int    $lead_id Lead id.
	 * @param string $reason  Logged reason.
	 */
	public static function anonymise( int $lead_id, string $reason ): void {
		if ( get_post_meta( $lead_id, LeadRepository::META_ANONYMISED, true ) ) {
			return;
		}
		foreach ( [ LeadRepository::META_NAME, LeadRepository::META_EMAIL, LeadRepository::META_COMPANY, LeadRepository::META_PHONE, LeadRepository::META_IP_HASH, '_ct_notes_internal' ] as $key ) {
			delete_post_meta( $lead_id, $key );
		}
		// The free text may contain personal data; the AI narrative may paraphrase it.
		$answers = get_post_meta( $lead_id, LeadRepository::META_ANSWERS, true );
		if ( is_array( $answers ) && isset( $answers['notes'] ) ) {
			$answers['notes'] = '';
			update_post_meta( $lead_id, LeadRepository::META_ANSWERS, $answers );
		}
		$result = get_post_meta( $lead_id, LeadRepository::META_RESULT, true );
		if ( is_array( $result ) && isset( $result['answers']['notes'] ) ) {
			$result['answers']['notes'] = '';
			update_post_meta( $lead_id, LeadRepository::META_RESULT, $result );
		}
		$labels = get_post_meta( $lead_id, LeadRepository::META_LABELS, true );
		if ( is_array( $labels ) ) {
			unset( $labels['notes'], $labels['name'], $labels['email'], $labels['company'], $labels['phone'] );
			update_post_meta( $lead_id, LeadRepository::META_LABELS, $labels );
		}
		delete_post_meta( $lead_id, LeadRepository::META_NARRATIVE );
		update_post_meta( $lead_id, ShareToken::META_ENABLED, 0 );
		update_post_meta( $lead_id, LeadRepository::META_ANONYMISED, time() );
		wp_update_post(
			[
				'ID'         => $lead_id,
				'post_title' => sprintf(
					/* translators: %d: lead id */
					__( 'Anonymised estimate #%d', 'cybertech-estimator' ),
					$lead_id
				),
			]
		);
		Logger::log(
			'privacy',
			'anonymised',
			[
				'lead'   => $lead_id,
				'reason' => $reason,
			]
		);
	}
}
