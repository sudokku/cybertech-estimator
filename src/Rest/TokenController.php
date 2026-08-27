<?php
/**
 * GET /ct-est/v1/token — issues a fresh time-on-form token. Exists because
 * the wizard page may be served from a page cache for longer than the
 * token's lifetime; the wizard fetches one when it starts.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Rest;

use Cybertech\Estimator\Security\Honeypot;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Token endpoint.
 */
final class TokenController {

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
			'/token',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'token' ],
				'permission_callback' => PublicAccess::for( 'preview' ),
			]
		);
	}

	/**
	 * Issue a token. Not cacheable: the timestamp is the point.
	 */
	public function token(): WP_REST_Response {
		$response = new WP_REST_Response( [ 'token' => Honeypot::issue_token() ] );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
