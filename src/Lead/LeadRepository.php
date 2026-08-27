<?php
/**
 * Creates and reads leads. On creation the lead stores an immutable
 * snapshot: raw answers, resolved labels, the FULL rate card at that
 * moment, the breakdown and the result — so a lead renders exactly what
 * was quoted no matter how the rate card changes later.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Lead;

use Cybertech\Estimator\Brand;
use Cybertech\Estimator\Engine\EstimateResult;
use Cybertech\Estimator\Engine\Questionnaire;
use Cybertech\Estimator\Engine\RateCard;
use Cybertech\Estimator\Security\RateLimiter;
use Cybertech\Estimator\Support\Money;
use Cybertech\Estimator\Support\Settings;

/**
 * Lead persistence.
 */
final class LeadRepository {

	// Snapshot + result meta.
	public const META_ANSWERS   = '_ct_answers';
	public const META_LABELS    = '_ct_labels';
	public const META_RESULT    = '_ct_result';
	public const META_RATE_CARD = '_ct_rate_card';
	public const META_RC_VER    = '_ct_rate_card_version';
	// Contact.
	public const META_NAME    = '_ct_name';
	public const META_EMAIL   = '_ct_email';
	public const META_COMPANY = '_ct_company';
	public const META_PHONE   = '_ct_phone';
	public const META_CONSENT = '_ct_consent';
	public const META_IP_HASH = '_ct_ip_hash';
	// Denormalised columns for the list table.
	public const META_SERVICE = '_ct_service_line';
	public const META_LOW     = '_ct_price_low';
	public const META_HIGH    = '_ct_price_high';
	public const META_WEEKS   = '_ct_weeks';
	public const META_SCORE   = '_ct_score';
	public const META_STATUS  = '_ct_status';
	public const META_MODE    = '_ct_reveal_mode';
	public const META_LOCALE  = '_ct_locale';
	// AI (filled in Phase 4).
	public const META_NARRATIVE = '_ct_narrative';
	public const META_AI_STATUS = '_ct_ai_status';
	// pending | ai | fallback.
	public const META_AI_MODEL = '_ct_ai_model';
	// Anonymisation flag.
	public const META_ANONYMISED = '_ct_anonymised';

	/**
	 * Create a lead with its snapshot.
	 *
	 * @param array<string, mixed> $answers Validated pricing answers.
	 * @param array<string, mixed> $contact Validated contact fields.
	 * @param EstimateResult       $result  Engine output.
	 * @param RateCard             $card    The card used (snapshotted whole).
	 * @param array<string, mixed> $extra   Optional: reveal_mode, locale, source_url, created_at (for seeding).
	 * @return int Post id (0 on failure).
	 */
	public function create( array $answers, array $contact, EstimateResult $result, RateCard $card, array $extra = [] ): int {
		$service_label = (string) $card->get( 'service_lines.' . $result->service_line . '.label', $result->service_line );
		$who           = (string) ( $contact['company'] ?? '' );
		if ( '' === $who ) {
			$who = (string) ( $contact['name'] ?? __( 'Anonymous', 'cybertech-estimator' ) );
		}
		$title = sprintf( '%s — %s — %s', $who, $service_label, Money::range( $result->price_low, $result->price_high, $result->currency ) );

		$postarr = [
			'post_type'                  => LeadPostType::POST_TYPE,
			'post_status'                => 'publish',
			// Not public: the CPT is not publicly queryable. 'publish' keeps it out of drafts/trash flows.
							'post_title' => $title,
		];
		if ( ! empty( $extra['created_at'] ) ) {
			$postarr['post_date']     = gmdate( 'Y-m-d H:i:s', (int) $extra['created_at'] + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) );
			$postarr['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', (int) $extra['created_at'] );
		}

		$id = wp_insert_post( $postarr, true );
		if ( is_wp_error( $id ) || ! $id ) {
			return 0;
		}
		$id = (int) $id;

		$meta = [
			self::META_ANSWERS       => $answers,
			self::META_LABELS        => Questionnaire::resolve_labels( $answers ),
			self::META_RESULT        => $result->to_array(),
			self::META_RATE_CARD     => $card->to_array(),
			self::META_RC_VER        => $card->version(),
			self::META_NAME          => (string) ( $contact['name'] ?? '' ),
			self::META_EMAIL         => (string) ( $contact['email'] ?? '' ),
			self::META_COMPANY       => (string) ( $contact['company'] ?? '' ),
			self::META_PHONE         => (string) ( $contact['phone'] ?? '' ),
			self::META_CONSENT       => [
				'text'    => Brand::get( 'consent_text' ),
				'version' => Brand::get( 'consent_version' ),
				'ts'      => time(),
			],
			self::META_SERVICE       => $result->service_line,
			self::META_LOW           => $result->price_low,
			self::META_HIGH          => $result->price_high,
			self::META_WEEKS         => $result->weeks,
			self::META_SCORE         => $result->qualification,
			self::META_STATUS        => 'new',
			self::META_MODE          => (string) ( $extra['reveal_mode'] ?? Settings::reveal_mode() ),
			self::META_LOCALE        => (string) ( $extra['locale'] ?? get_locale() ),
			self::META_AI_STATUS     => 'pending',
			ShareToken::META_TOKEN   => ShareToken::generate(),
			ShareToken::META_EXPIRES => ShareToken::default_expiry(),
			ShareToken::META_ENABLED => 1,
		];
		if ( Settings::get( 'security.store_ip' ) ) {
			$meta[ self::META_IP_HASH ] = RateLimiter::ip_hash();
		}
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}

		/**
		 * Fires after a lead and its snapshot are stored.
		 *
		 * @param int                  $id      Lead post id.
		 * @param EstimateResult       $result  Engine result.
		 * @param array<string, mixed> $contact Contact fields.
		 */
		do_action( 'ct_est_lead_created', $id, $result, $contact );

		return $id;
	}

	/**
	 * Stored result for a lead (from the snapshot, never recomputed).
	 *
	 * @param int $lead_id Lead id.
	 */
	public function result( int $lead_id ): ?EstimateResult {
		$data = get_post_meta( $lead_id, self::META_RESULT, true );
		return is_array( $data ) && $data ? EstimateResult::from_array( $data ) : null;
	}

	/**
	 * Snapshotted rate card for a lead.
	 *
	 * @param int $lead_id Lead id.
	 */
	public function rate_card( int $lead_id ): ?RateCard {
		$data = get_post_meta( $lead_id, self::META_RATE_CARD, true );
		return is_array( $data ) && ! RateCard::validate( $data ) ? new RateCard( $data ) : null;
	}

	/**
	 * Share token of a lead.
	 *
	 * @param int $lead_id Lead id.
	 */
	public function token( int $lead_id ): string {
		return (string) get_post_meta( $lead_id, ShareToken::META_TOKEN, true );
	}

	/**
	 * Contact fields of a lead.
	 *
	 * @param int $lead_id Lead id.
	 * @return array{name: string, email: string, company: string, phone: string}
	 */
	public function contact( int $lead_id ): array {
		return [
			'name'    => (string) get_post_meta( $lead_id, self::META_NAME, true ),
			'email'   => (string) get_post_meta( $lead_id, self::META_EMAIL, true ),
			'company' => (string) get_post_meta( $lead_id, self::META_COMPANY, true ),
			'phone'   => (string) get_post_meta( $lead_id, self::META_PHONE, true ),
		];
	}
}
