<?php
/**
 * Core privacy exporter: Tools → Export Personal Data lists every estimate
 * a visitor requested with the email address entered.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Privacy;

use Cybertech\Estimator\Lead\LeadPostType;
use Cybertech\Estimator\Lead\LeadRepository;
use Cybertech\Estimator\Lead\ShareToken;
use Cybertech\Estimator\Support\Money;

/**
 * Personal data exporter.
 */
final class DataExporter {

	public const GROUP_ID  = 'ct_estimate_leads';
	public const PAGE_SIZE = 50;

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', [ $this, 'register_exporter' ] );
	}

	/**
	 * Register with core.
	 *
	 * @param array<string, array<string, mixed>> $exporters Exporters.
	 * @return array<string, array<string, mixed>>
	 */
	public function register_exporter( array $exporters ): array {
		$exporters['cybertech-estimator'] = [
			'exporter_friendly_name' => __( 'Project estimates', 'cybertech-estimator' ),
			'callback'               => [ $this, 'export' ],
		];
		return $exporters;
	}

	/**
	 * Export one page of leads for an email.
	 *
	 * @param string $email_address Email.
	 * @param int    $page          Page (1-based).
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public function export( string $email_address, int $page = 1 ): array {
		$leads = self::leads_for_email( $email_address, $page );
		$data  = [];
		$repo  = new LeadRepository();
		foreach ( $leads as $lead_id ) {
			$result  = $repo->result( $lead_id );
			$contact = $repo->contact( $lead_id );
			$labels  = get_post_meta( $lead_id, LeadRepository::META_LABELS, true );
			$consent = get_post_meta( $lead_id, LeadRepository::META_CONSENT, true );
			$items   = [
				[
					'name'  => __( 'Requested on', 'cybertech-estimator' ),
					'value' => get_the_date( 'c', $lead_id ),
				],
				[
					'name'  => __( 'Name', 'cybertech-estimator' ),
					'value' => $contact['name'],
				],
				[
					'name'  => __( 'Email', 'cybertech-estimator' ),
					'value' => $contact['email'],
				],
				[
					'name'  => __( 'Company', 'cybertech-estimator' ),
					'value' => $contact['company'],
				],
				[
					'name'  => __( 'Phone', 'cybertech-estimator' ),
					'value' => $contact['phone'],
				],
			];
			if ( $result ) {
				$items[] = [
					'name'  => __( 'Estimate', 'cybertech-estimator' ),
					'value' => Money::range( $result->price_low, $result->price_high, $result->currency ),
				];
				$items[] = [
					'name'  => __( 'Duration', 'cybertech-estimator' ),
					'value' => $result->weeks . ' ' . __( 'weeks', 'cybertech-estimator' ),
				];
			}
			foreach ( is_array( $labels ) ? $labels : [] as $row ) {
				$items[] = [
					'name'  => $row['label'],
					'value' => $row['value'],
				];
			}
			if ( is_array( $consent ) ) {
				$items[] = [
					'name'  => __( 'Consent', 'cybertech-estimator' ),
					'value' => sprintf( '%s (v%s, %s)', $consent['text'] ?? '', $consent['version'] ?? '', isset( $consent['ts'] ) ? gmdate( 'c', (int) $consent['ts'] ) : '' ),
				];
			}
			$items[] = [
				'name'  => __( 'Share link', 'cybertech-estimator' ),
				'value' => ShareToken::url( $repo->token( $lead_id ) ),
			];
			$data[]  = [
				'group_id'    => self::GROUP_ID,
				'group_label' => __( 'Project estimates', 'cybertech-estimator' ),
				'item_id'     => 'ct-lead-' . $lead_id,
				'data'        => $items,
			];
		}//end foreach
		return [
			'data' => $data,
			'done' => count( $leads ) < self::PAGE_SIZE,
		];
	}

	/**
	 * Lead ids for an email, paged.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array<int, int>
	 */
	public static function leads_for_email( string $email, int $page ): array {
		$ids = get_posts(
			[
				'post_type'        => LeadPostType::POST_TYPE,
				'post_status'      => 'any',
				'numberposts'      => self::PAGE_SIZE,
				'offset'           => ( max( 1, $page ) - 1 ) * self::PAGE_SIZE,
				'fields'           => 'ids',
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
				'meta_key'         => LeadRepository::META_EMAIL, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => $email,                     // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			]
		);
		return array_map( 'intval', $ids );
	}
}
