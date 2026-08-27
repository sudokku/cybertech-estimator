<?php
/**
 * POST /ct-est/v1/preview — prices partial answers, creates no lead.
 * The response is shaped by RevealPolicy: in gated mode it carries no figures.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Rest;

use Cybertech\Estimator\Engine\PricingEngine;
use Cybertech\Estimator\Engine\RateCardRepository;
use Cybertech\Estimator\Frontend\RevealPolicy;
use Cybertech\Estimator\Security\InputSanitizer;
use Cybertech\Estimator\Security\RateLimiter;
use Cybertech\Estimator\Support\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Preview endpoint.
 */
final class PreviewController {

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
			'/preview',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'preview' ],
				'permission_callback' => PublicAccess::for( 'preview' ),
				'args'                => [
					'answers' => [
						'required' => true,
						'type'     => 'object',
					],
					'mode'    => [
						'required' => false,
						'type'     => 'string',
					],
					// Shortcode/widget override.
				],
			]
		);
	}

	/**
	 * Price the answers so far.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function preview( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		( new RateLimiter() )->hit( 'preview' );

		$validated = ( new InputSanitizer() )->validate( (array) $request->get_param( 'answers' ), InputSanitizer::MODE_PREVIEW );
		if ( empty( $validated['answers']['service_line'] ) ) {
			return new WP_Error(
				'ct_est_invalid',
				__( 'Please choose a service line first.', 'cybertech-estimator' ),
				[
					'status' => 400,
					'errors' => $validated['errors'],
				]
			);
		}

		$card   = ( new RateCardRepository() )->load();
		$result = ( new PricingEngine( $card, $validated['answers'] ) )->estimate();
		$mode   = self::resolve_mode( (string) $request->get_param( 'mode' ) );

		return new WP_REST_Response(
			RevealPolicy::visitor_payload( $result, $mode, false ) + [ 'errors' => (object) $validated['errors'] ]
		);
	}

	/**
	 * Mode override from the shortcode/widget, otherwise the setting.
	 * Only the three known values are accepted.
	 *
	 * @param string $requested Requested mode.
	 */
	public static function resolve_mode( string $requested ): string {
		return in_array( $requested, [ 'open', 'band', 'gated' ], true ) ? $requested : Settings::reveal_mode();
	}
}
