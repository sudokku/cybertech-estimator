<?php
/**
 * POST /ct-est/v1/narrative — narrative for a submitted lead, addressed by
 * its share token. Called by the wizard after the numeric result rendered;
 * this is the only public path that can reach the paid API, and it exists
 * only for leads that gave consent.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Rest;

use Cybertech\Estimator\Ai\NarrativeService;
use Cybertech\Estimator\Lead\LeadRepository;
use Cybertech\Estimator\Lead\ShareToken;
use Cybertech\Estimator\Security\RateLimiter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Narrative endpoint.
 */
final class NarrativeController {

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
			'/narrative',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'narrative' ],
				'permission_callback' => PublicAccess::for( 'narrative' ),
				'args'                => [
					'token' => [
						'required' => true,
						'type'     => 'string',
					],
				],
			]
		);
	}

	/**
	 * Return (and store) the narrative for the lead behind the token.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function narrative( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		( new RateLimiter() )->hit( 'narrative' );
		$token   = (string) $request->get_param( 'token' );
		$lead_id = ShareToken::find_lead( $token );
		if ( ! $lead_id ) {
			return new WP_Error( 'ct_est_not_found', __( 'This estimate could not be found.', 'cybertech-estimator' ), [ 'status' => 404 ] );
		}
		$narrative = ( new NarrativeService() )->for_lead( $lead_id );
		if ( null === $narrative ) {
			return new WP_Error( 'ct_est_not_found', __( 'This estimate could not be found.', 'cybertech-estimator' ), [ 'status' => 404 ] );
		}
		return new WP_REST_Response(
			[
				'narrative' => $narrative,
				'source'    => (string) get_post_meta( $lead_id, LeadRepository::META_AI_STATUS, true ),
			]
		);
	}
}
