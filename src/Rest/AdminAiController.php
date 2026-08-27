<?php
/**
 * Admin-only AI endpoints: model list for the settings datalist and the
 * sandbox narration run (prompt, raw response, verdict, latency, cost).
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Rest;

use Cybertech\Estimator\Ai\BudgetGuard;
use Cybertech\Estimator\Ai\CircuitBreaker;
use Cybertech\Estimator\Ai\NarrativeService;
use Cybertech\Estimator\Ai\ProviderRegistry;
use Cybertech\Estimator\Engine\PricingEngine;
use Cybertech\Estimator\Engine\Questionnaire;
use Cybertech\Estimator\Engine\RateCardRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Admin AI endpoints.
 */
final class AdminAiController {

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
			'/admin/models',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'models' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'args'                => [
					'refresh' => [
						'type'     => 'boolean',
						'required' => false,
					],
				],
			]
		);
		register_rest_route(
			self::NAMESPACE,
			'/sandbox/narrative',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'sandbox_narrative' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'args'                => [
					'answers'        => [
						'required' => true,
						'type'     => 'object',
					],
					'force_fallback' => [
						'required' => false,
						'type'     => 'boolean',
					],
					'use_cache'      => [
						'required' => false,
						'type'     => 'boolean',
					],
				],
			]
		);
	}

	/**
	 * Capability check.
	 */
	public function can_manage(): bool {
		return current_user_can( self::CAPABILITY );
	}

	/**
	 * Model list with pricing (USD per 1M tokens for readability).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function models( WP_REST_Request $request ): WP_REST_Response {
		$provider = ProviderRegistry::current();
		$models   = [];
		foreach ( $provider->list_models( (bool) $request->get_param( 'refresh' ) ) as $m ) {
			$models[] = [
				'id'                 => $m['id'],
				'label'              => $m['label'],
				'prompt_price'       => round( (float) $m['prompt_price'] * 1e6, 4 ),
				'completion_price'   => round( (float) $m['completion_price'] * 1e6, 4 ),
				'free'               => str_ends_with( (string) $m['id'], ':free' ),
				'structured_outputs' => ! empty( $m['structured_outputs'] ),
			];
		}
		return new WP_REST_Response(
			[
				'provider' => $provider->id(),
				'models'   => $models,
				'breaker'  => CircuitBreaker::state(),
				'spend'    => [
					'cents'  => BudgetGuard::spent_cents(),
					'budget' => BudgetGuard::budget_cents(),
					'state'  => BudgetGuard::state(),
				],
			]
		);
	}

	/**
	 * Full diagnostic narration run for the sandbox.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function sandbox_narrative( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$answers = SandboxController::normalise_answers( (array) $request->get_param( 'answers' ) );
		$card    = ( new RateCardRepository() )->load();
		try {
			$result = ( new PricingEngine( $card, $answers ) )->estimate();
		} catch ( \InvalidArgumentException $e ) {
			return new WP_Error( 'ct_est_invalid_answers', $e->getMessage(), [ 'status' => 400 ] );
		}
		$facts                      = NarrativeService::facts( $result, $card, Questionnaire::resolve_labels( $answers ), get_locale() );
		$facts['rate_card_version'] = $card->version();
		$run                        = ( new NarrativeService() )->run( $facts, (bool) $request->get_param( 'force_fallback' ), (bool) ( $request->get_param( 'use_cache' ) ?? true ) );
		return new WP_REST_Response(
			$run + [
				'breaker'     => CircuitBreaker::state(),
				'spend_cents' => BudgetGuard::spent_cents(),
			]
		);
	}
}
