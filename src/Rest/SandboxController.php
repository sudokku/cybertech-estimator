<?php
/**
 * Admin-only REST endpoint used by the Sandbox and Rate-card pages to
 * price a set of answers — optionally against an unsaved card, so the
 * rate-card editor can show the live effect of a change before saving.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Rest;

use Cybertech\Estimator\Engine\PricingEngine;
use Cybertech\Estimator\Engine\Questionnaire;
use Cybertech\Estimator\Engine\RateCard;
use Cybertech\Estimator\Engine\RateCardRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * POST /ct-est/v1/sandbox/estimate
 */
final class SandboxController {

	public const NAMESPACE  = 'ct-est/v1';
	public const CAPABILITY = 'manage_options';

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/sandbox/estimate',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'estimate' ],
				'permission_callback' => [ $this, 'can_use_sandbox' ],
				'args'                => [
					'answers'   => [
						'required' => true,
						'type'     => 'object',
					],
					'rate_card' => [
						'required' => false,
						'type'     => 'object',
					],
				],
			]
		);
	}

	/**
	 * Admin capability check — never a bare `true`.
	 */
	public function can_use_sandbox(): bool {
		return current_user_can( self::CAPABILITY );
	}

	/**
	 * Price the given answers. Unknown answers are dropped; numbers are
	 * clamped to the schema (a lighter version of the public sanitizer,
	 * which lands in Phase 2 and will replace this).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function estimate( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$answers = self::normalise_answers( (array) $request->get_param( 'answers' ) );

		$card_data = $request->get_param( 'rate_card' );
		if ( is_array( $card_data ) && $card_data ) {
			$errors = RateCard::validate( $card_data );
			if ( $errors ) {
				return new WP_Error( 'ct_est_invalid_rate_card', implode( '; ', $errors ), [ 'status' => 400 ] );
			}
			$card = new RateCard( $card_data );
		} else {
			$card = ( new RateCardRepository() )->load();
		}

		try {
			$result = ( new PricingEngine( $card, $answers ) )->estimate();
		} catch ( \InvalidArgumentException $e ) {
			return new WP_Error( 'ct_est_invalid_answers', $e->getMessage(), [ 'status' => 400 ] );
		}

		return new WP_REST_Response(
			[
				'result' => $result->to_array(),
				'labels' => Questionnaire::resolve_labels( $answers ),
			]
		);
	}

	/**
	 * Keep only known question ids with schema-valid values.
	 *
	 * @param array<string, mixed> $raw Raw answers.
	 * @return array<string, mixed>
	 */
	public static function normalise_answers( array $raw ): array {
		$out = [];
		foreach ( Questionnaire::questions() as $id => $question ) {
			if ( ! array_key_exists( $id, $raw ) ) {
				continue;
			}
			$value = $raw[ $id ];
			switch ( $question['type'] ) {
				case Questionnaire::TYPE_SINGLE:
					if ( is_string( $value ) && isset( $question['options'][ $value ] ) ) {
						$out[ $id ] = $value;
					}
					break;
				case Questionnaire::TYPE_MULTI:
					$vals = array_values( array_filter( (array) $value, static fn( $v ) => is_string( $v ) && isset( $question['options'][ $v ] ) ) );
					if ( $vals ) {
						$out[ $id ] = $vals;
					}
					break;
				case Questionnaire::TYPE_NUMBER:
					if ( is_numeric( $value ) ) {
						$out[ $id ] = max( (int) $question['min'], min( (int) $question['max'], (int) $value ) );
					}
					break;
				case Questionnaire::TYPE_TEXT:
				case Questionnaire::TYPE_EMAIL:
					$text = trim( wp_strip_all_tags( (string) $value ) );
					if ( '' !== $text ) {
						$out[ $id ] = mb_substr( $text, 0, (int) ( $question['max'] ?? 1000 ) );
					}
					break;
				case Questionnaire::TYPE_CHECKBOX:
					$out[ $id ] = (bool) $value;
					break;
			}//end switch
		}//end foreach
		return $out;
	}
}
