<?php
/**
 * OpenRouter driver (chat completions with strict JSON schema output).
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Ai;

use Cybertech\Estimator\Support\Logger;
use Cybertech\Estimator\Support\Settings;

/**
 * OpenRouter.
 */
final class OpenRouterProvider implements ProviderInterface {

	public const ENDPOINT     = 'https://openrouter.ai/api/v1/chat/completions';
	public const MODELS_URL   = 'https://openrouter.ai/api/v1/models';
	public const MODELS_CACHE = 'ct_est_or_models';
	public const MODELS_TTL   = DAY_IN_SECONDS;

	/**
	 * Id.
	 */
	public function id(): string {
		return 'openrouter';
	}

	/**
	 * Label.
	 */
	public function label(): string {
		return 'OpenRouter';
	}

	/**
	 * Key and model present.
	 */
	public function is_configured(): bool {
		return '' !== $this->api_key() && '' !== $this->model();
	}

	/**
	 * Structured completion.
	 *
	 * @param string               $system System prompt.
	 * @param string               $user   User prompt.
	 * @param array<string, mixed> $schema JSON schema.
	 * @param array<string, mixed> $opts   Overrides.
	 */
	public function complete( string $system, string $user, array $schema, array $opts = [] ): ProviderResponse {
		$model = (string) ( $opts['model'] ?? $this->model() );
		if ( Settings::get( 'ai.floor' ) && ! str_ends_with( $model, ':floor' ) ) {
			$model .= ':floor';
			// Route to the cheapest provider offering the model.
		}

		$request = $this->request_body( $model, $system, $user, $schema, $opts, true );
		$result  = $this->post( $request, $opts );

		// Free models share an upstream pool and answer 429 with a short Retry-After.
		// One bounded retry: this runs after the visitor already has their numbers.
		$retry_after = (int) ( $result->raw['error']['metadata']['retry_after_seconds'] ?? 0 );
		if ( ! $result->ok && $retry_after > 0 && $retry_after <= self::MAX_RETRY_WAIT ) {
			sleep( $retry_after );
			$result = $this->post( $request, $opts );
		}

		if ( ! $result->ok && false !== stripos( $result->error, 'reasoning is mandatory' ) ) {
			$opts['reasoning_mandatory'] = true;
			$request                     = $this->request_body( $model, $system, $user, $schema, $opts, true );
			$result                      = $this->post( $request, $opts );
		}

		// A model without structured-output support (most free ones) yields
		// "No endpoints found that can handle the requested parameters".
		// Downgrade once to prompt-only JSON: the validator is the real gate.
		if ( ! $result->ok && false !== stripos( $result->error, 'no endpoints found' ) ) {
			Logger::log(
				'ai',
				'schema_downgrade',
				[
					'model' => $model,
					'error' => $result->error,
				]
			);
			$request = $this->request_body( $model, $system, $user, $schema, $opts, false );
			$result  = $this->post( $request, $opts );
			if ( $result->ok ) {
				return $result->with_raw( [ 'schema_mode' => 'prompt_only' ] + $result->raw );
			}
			return $result;
		}
		return $result->ok ? $result->with_raw( [ 'schema_mode' => 'strict' ] + $result->raw ) : $result;
	}

	/**
	 * Build the chat-completions body.
	 *
	 * @param string               $model  Model slug (with :floor if enabled).
	 * @param string               $system System prompt.
	 * @param string               $user   User prompt.
	 * @param array<string, mixed> $schema JSON schema.
	 * @param array<string, mixed> $opts   Overrides.
	 * @param bool                 $strict Strict json_schema + require_parameters, or prompt-only JSON.
	 * @return array<string, mixed>
	 */
	private function request_body( string $model, string $system, string $user, array $schema, array $opts, bool $strict ): array {
		if ( ! $strict ) {
			// Put the schema in the prompt instead so the model still knows the shape.
			$user .= "\n\nRespond with a single JSON object only (no prose, no code fences) matching this JSON schema:\n" . wp_json_encode( $schema );
		}
		$body = [
			'model'       => $model,
			'messages'    => [
				[
					'role'    => 'system',
					'content' => $system,
				],
				[
					'role'    => 'user',
					'content' => $user,
				],
			],
			'max_tokens'  => (int) ( $opts['max_tokens'] ?? Settings::get( 'ai.max_tokens' ) ),
			'temperature' => (float) ( $opts['temperature'] ?? 0.4 ),
			'usage'       => [ 'include' => true ],
			// Returns cost in the usage object.
		];
		// Reasoning models spend the whole max_tokens budget thinking and return
		// empty content. We want prose, not chain-of-thought: switch it off where
		// the model advertises the parameter (sending it elsewhere would break
		// require_parameters routing). Endpoints where reasoning is mandatory get
		// it excluded from the output with a larger budget instead.
		if ( ! empty( $opts['reasoning_mandatory'] ) ) {
			$body['reasoning']  = [ 'exclude' => true ];
			$body['max_tokens'] = $body['max_tokens'] * 3;
		} elseif ( $this->supports( $model, 'reasoning' ) ) {
			$body['reasoning'] = [ 'enabled' => false ];
		}
		if ( $strict ) {
			$body['response_format'] = [
				'type'        => 'json_schema',
				'json_schema' => [
					'name'   => 'estimate_narrative',
					'strict' => true,
					'schema' => $schema,
				],
			];
			$body['provider']        = [ 'require_parameters' => true ];
			// Otherwise a route may silently ignore the schema.
		}
		$max_price = (float) Settings::get( 'ai.max_price' );
		if ( $max_price > 0 ) {
			$body['provider']              = $body['provider'] ?? [];
			$body['provider']['max_price'] = [
				'prompt'     => $max_price,
				'completion' => $max_price,
			];
		}
		return $body;
	}

	/**
	 * POST the body and normalise the response.
	 *
	 * @param array<string, mixed> $body Request body.
	 * @param array<string, mixed> $opts Overrides (timeout).
	 */
	private function post( array $body, array $opts ): ProviderResponse {
		$started  = microtime( true );
		$response = wp_remote_post(
			self::ENDPOINT,
			[
				'timeout' => (int) ( $opts['timeout'] ?? Settings::get( 'ai.timeout' ) ),
				'headers' => $this->headers(),
				'body'    => wp_json_encode( $body ),
			]
		);
		$latency  = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $response ) ) {
			return ProviderResponse::failure( $response->get_error_message(), $latency );
		}
		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$decoded = is_array( $decoded ) ? $decoded : [];
		if ( $status < 200 || $status >= 300 ) {
			$message = (string) ( $decoded['error']['message'] ?? ( 'HTTP ' . $status ) );
			// OpenRouter hides the upstream reason ("temporarily rate-limited…") in metadata.raw.
			$raw_reason = $decoded['error']['metadata']['raw'] ?? '';
			if ( is_string( $raw_reason ) && '' !== $raw_reason ) {
				$message .= ': ' . mb_substr( $raw_reason, 0, 200 );
			}
			return ProviderResponse::failure( $message, $latency, $decoded );
		}
		$content = $decoded['choices'][0]['message']['content'] ?? '';
		if ( ! is_string( $content ) || '' === $content ) {
			return ProviderResponse::failure( 'empty_content', $latency, $decoded );
		}
		$usage = (array) ( $decoded['usage'] ?? [] );
		return new ProviderResponse(
			true,
			$content,
			(string) ( $decoded['model'] ?? $body['model'] ),
			(int) ( $usage['prompt_tokens'] ?? 0 ),
			(int) ( $usage['completion_tokens'] ?? 0 ),
			isset( $usage['cost'] ) && is_numeric( $usage['cost'] ) ? (float) $usage['cost'] : null,
			$latency,
			'',
			$decoded
		);
	}

	/**
	 * Model list with per-token pricing (USD per token, as OpenRouter reports).
	 *
	 * @param bool $refresh Bypass cache.
	 * @return array<int, array<string, mixed>>
	 */
	public function list_models( bool $refresh = false ): array {
		if ( ! $refresh ) {
			$cached = get_transient( self::MODELS_CACHE );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}
		$response = wp_remote_get(
			self::MODELS_URL,
			[
				'timeout' => 15,
				'headers' => $this->headers( false ),
			]
		);
		if ( is_wp_error( $response ) ) {
			return [];
		}
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$models  = [];
		foreach ( (array) ( $decoded['data'] ?? [] ) as $m ) {
			if ( empty( $m['id'] ) ) {
				continue;
			}
			$models[] = [
				'id'                 => (string) $m['id'],
				'label'              => (string) ( $m['name'] ?? $m['id'] ),
				'prompt_price'       => (float) ( $m['pricing']['prompt'] ?? 0 ),
				'completion_price'   => (float) ( $m['pricing']['completion'] ?? 0 ),
				'context_length'     => (int) ( $m['context_length'] ?? 0 ),
				// Strict json_schema routing needs this; without it OpenRouter finds no endpoint.
				'structured_outputs' => in_array( 'structured_outputs', (array) ( $m['supported_parameters'] ?? [] ), true ),
				'reasoning'          => in_array( 'reasoning', (array) ( $m['supported_parameters'] ?? [] ), true ),
			];
		}
		usort( $models, static fn( array $a, array $b ): int => strcmp( $a['id'], $b['id'] ) );
		set_transient( self::MODELS_CACHE, $models, self::MODELS_TTL );
		return $models;
	}

	/**
	 * Whether the cached model list says a model supports a parameter.
	 *
	 * @param string $model Model id (":floor" suffix ignored).
	 * @param string $flag  'reasoning' | 'structured_outputs'.
	 */
	public function supports( string $model, string $flag ): bool {
		$model = preg_replace( '/:floor$/', '', $model ) ?? $model;
		foreach ( $this->list_models() as $m ) {
			if ( $m['id'] === $model ) {
				return ! empty( $m[ $flag ] );
			}
		}
		return false;
	}

	/**
	 * Listed price for a model (USD per token), for cost estimation when
	 * the response carries no usage.cost.
	 *
	 * @param string $model Model id (":floor" suffix ignored).
	 * @return array{prompt: float, completion: float}|null
	 */
	public function price_for( string $model ): ?array {
		$model = preg_replace( '/:floor$/', '', $model ) ?? $model;
		foreach ( $this->list_models() as $m ) {
			if ( $m['id'] === $model ) {
				return [
					'prompt'     => (float) $m['prompt_price'],
					'completion' => (float) $m['completion_price'],
				];
			}
		}
		return null;
	}

	/**
	 * Request headers. Referer/Title identify the app on OpenRouter's dashboard.
	 *
	 * @param bool $auth Include the bearer token.
	 * @return array<string, string>
	 */
	private function headers( bool $auth = true ): array {
		$headers = [
			'Content-Type' => 'application/json',
			'HTTP-Referer' => home_url( '/' ),
			'X-Title'      => 'Cybertech Project Estimator',
		];
		if ( $auth ) {
			$headers['Authorization'] = 'Bearer ' . $this->api_key();
		}
		return $headers;
	}

	/**
	 * API key from settings.
	 */
	private function api_key(): string {
		return trim( (string) Settings::get( 'ai.api_key' ) );
	}

	/**
	 * Model slug from settings — never hardcoded here.
	 */
	private function model(): string {
		return trim( (string) Settings::get( 'ai.model' ) );
	}
}
