<?php
/**
 * POST /ct-est/v1/submit — full validation, bot checks, lead creation with
 * snapshot, and the unlocked visitor payload.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Rest;

use Cybertech\Estimator\Engine\PricingEngine;
use Cybertech\Estimator\Engine\RateCardRepository;
use Cybertech\Estimator\Frontend\RevealPolicy;
use Cybertech\Estimator\Lead\LeadRepository;
use Cybertech\Estimator\Lead\ShareToken;
use Cybertech\Estimator\Security\Honeypot;
use Cybertech\Estimator\Security\InputSanitizer;
use Cybertech\Estimator\Security\RateLimiter;
use Cybertech\Estimator\Support\Logger;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Submit endpoint.
 */
final class SubmitController {

	public const NAMESPACE = 'ct-est/v1';

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
			'/submit',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'submit' ],
				'permission_callback' => PublicAccess::for( 'submit' ),
				'args'                => [
					'answers'             => [
						'required' => true,
						'type'     => 'object',
					],
					'mode'                => [
						'required' => false,
						'type'     => 'string',
					],
					Honeypot::FIELD_HONEY => [
						'required' => false,
						'type'     => 'string',
					],
					Honeypot::FIELD_TOKEN => [
						'required' => true,
						'type'     => 'string',
					],
					'source_url'          => [
						'required' => false,
						'type'     => 'string',
					],
				],
			]
		);
	}

	/**
	 * Create the lead.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function submit( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$reason = Honeypot::reject_reason( (string) $request->get_param( Honeypot::FIELD_HONEY ), (string) $request->get_param( Honeypot::FIELD_TOKEN ) );
		if ( null !== $reason ) {
			Logger::log( 'security', 'submission_rejected', [ 'reason' => $reason ] );
			// Same friendly message for every bot signal: no oracle for the attacker.
			return new WP_Error( 'ct_est_rejected', __( 'We could not process this submission. Please try again or contact us directly.', 'cybertech-estimator' ), [ 'status' => 400 ] );
		}

		$validated = ( new InputSanitizer() )->validate( (array) $request->get_param( 'answers' ), InputSanitizer::MODE_SUBMIT );
		if ( $validated['errors'] ) {
			return new WP_Error(
				'ct_est_invalid',
				__( 'Please check the highlighted fields.', 'cybertech-estimator' ),
				[
					'status' => 400,
					'errors' => $validated['errors'],
				]
			);
		}

		( new RateLimiter() )->hit( 'submit' );

		$card   = ( new RateCardRepository() )->load();
		$result = ( new PricingEngine( $card, $validated['answers'] ) )->estimate();
		$mode   = PreviewController::resolve_mode( (string) $request->get_param( 'mode' ) );

		$lead_id = ( new LeadRepository() )->create(
			$validated['answers'],
			$validated['contact'],
			$result,
			$card,
			[
				'reveal_mode' => $mode,
				'locale'      => determine_locale(),
				'source_url'  => esc_url_raw( (string) $request->get_param( 'source_url' ) ),
			]
		);
		if ( ! $lead_id ) {
			Logger::log( 'lead', 'create_failed' );
			return new WP_Error( 'ct_est_failed', __( 'Something went wrong on our side. Please try again or contact us directly.', 'cybertech-estimator' ), [ 'status' => 500 ] );
		}

		$token = ( new LeadRepository() )->token( $lead_id );

		return new WP_REST_Response(
			RevealPolicy::visitor_payload( $result, $mode, true ) + [
				'share_url' => ShareToken::url( $token ),
				'token'     => $token,
			// Used by the narrative endpoint (Phase 4) to attach AI text to this lead.
			],
			201
		);
	}
}
