<?php
/**
 * Orchestrates narration: cache → guards (kill switch, provider, breaker,
 * budget) → provider call → validation → fallback. Every path returns a
 * complete narrative; the visitor never sees a failure.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Ai;

use Cybertech\Estimator\Engine\EstimateResult;
use Cybertech\Estimator\Engine\RateCard;
use Cybertech\Estimator\Frontend\RevealPolicy;
use Cybertech\Estimator\Lead\LeadRepository;
use Cybertech\Estimator\Support\Logger;
use Cybertech\Estimator\Support\Settings;

/**
 * Narrative service.
 */
final class NarrativeService {

	public const CACHE_PREFIX = 'ct_est_nar_';
	public const STATS_OPTION = 'ct_est_ai_cache_stats';

	/**
	 * Facts for the prompt and the fallback, from a result + labels. No money.
	 *
	 * @param EstimateResult                                     $result Result.
	 * @param RateCard                                           $card   Card used (for the service label).
	 * @param array<string, array{label: string, value: string}> $labels Resolved answer labels.
	 * @param string                                             $locale Locale.
	 * @return array<string, mixed>
	 */
	public static function facts( EstimateResult $result, RateCard $card, array $labels, string $locale ): array {
		$answers = [];
		foreach ( $labels as $id => $row ) {
			if ( 'notes' === $id || 'budget' === $id ) {
				continue;
				// Notes go through the guard separately; the budget band is money-adjacent.
			}
			$answers[] = $row;
		}
		return [
			'service_line'  => $result->service_line,
			'service_label' => (string) $card->get( 'service_lines.' . $result->service_line . '.label', $result->service_line ),
			'weeks'         => $result->weeks,
			'hours'         => $result->hours,
			'team'          => RevealPolicy::team_labels( $result ),
			'answers'       => $answers,
			'answers_raw'   => $result->answers,
			'notes'         => (string) ( $result->answers['notes'] ?? '' ),
			'locale'        => $locale,
		];
	}

	/**
	 * Run narration for a set of facts. Returns the narrative plus diagnostics.
	 *
	 * @param array<string, mixed> $facts          See facts().
	 * @param bool                 $force_fallback Skip the provider (sandbox toggle).
	 * @param bool                 $use_cache      Read/write the cache.
	 * @return array{narrative: array<string, mixed>, source: string, reason: string, prompt: array<string, mixed>, response: array<string, mixed>|null, raw: string, validation: array<string, mixed>|null, model: string, cache_key: string}
	 */
	public function run( array $facts, bool $force_fallback = false, bool $use_cache = true ): array {
		$fallback = FallbackNarrative::build( $facts );
		$prompt   = PromptBuilder::build( $facts );
		if ( $prompt['flagged'] ) {
			Logger::log( 'security', 'prompt_injection_markers_stripped', [ 'patterns' => $prompt['flagged'] ] );
		}
		$provider = ProviderRegistry::current();
		$model    = (string) Settings::get( 'ai.model' );
		$out      = [
			'narrative'  => $fallback,
			'source'     => 'fallback',
			'reason'     => '',
			'prompt'     => $prompt,
			'response'   => null,
			'raw'        => '',
			'validation' => null,
			'model'      => $model,
			'cache_key'  => '',
		];

		if ( $force_fallback ) {
			$out['reason'] = 'forced';
			return $out;
		}
		if ( ! Settings::get( 'ai.enabled' ) ) {
			$out['reason'] = 'disabled';
			return $out;
		}
		if ( ! $provider->is_configured() ) {
			$out['reason'] = 'not_configured';
			return $out;
		}
		if ( CircuitBreaker::is_open() ) {
			$out['reason'] = 'breaker_open';
			return $out;
		}
		if ( ! BudgetGuard::can_spend() ) {
			$out['reason'] = 'budget_exhausted';
			return $out;
		}

		$cache_key        = self::CACHE_PREFIX . sha1( wp_json_encode( $facts['answers_raw'] ) . '|' . ( $facts['rate_card_version'] ?? '' ) . '|' . $model . '|' . $facts['locale'] );
		$out['cache_key'] = $cache_key;
		if ( $use_cache ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) && isset( $cached['narrative'] ) ) {
				self::bump_stats( 'hits' );
				$out['narrative'] = $cached['narrative'];
				$out['source']    = 'cache';
				$out['model']     = (string) ( $cached['model'] ?? $model );
				$out['reason']    = 'cache_hit';
				return $out;
			}
			self::bump_stats( 'misses' );
		}

		$response        = $provider->complete( $prompt['system'], $prompt['user'], $prompt['schema'] );
		$out['response'] = $response->to_array();
		$out['raw']      = $response->content;
		if ( ! $response->ok ) {
			CircuitBreaker::record_failure( $response->error );
			Logger::log(
				'ai',
				'provider_error',
				[
					'error'      => $response->error,
					'latency_ms' => $response->latency_ms,
				]
			);
			$out['reason'] = 'provider_error';
			return $out;
		}

		$validation        = ResponseValidator::validate( $response->content, (int) $facts['weeks'] );
		$out['validation'] = $validation;
		$this->record_cost( $provider, $response );
		if ( ! $validation['ok'] ) {
			CircuitBreaker::record_failure( 'validation:' . implode( ',', $validation['errors'] ) );
			Logger::log(
				'ai',
				'validation_failed',
				[
					'errors' => $validation['errors'],
					'model'  => $response->model,
				]
			);
			$out['reason'] = 'validation_failed';
			return $out;
		}

		CircuitBreaker::record_success();
		$out['narrative'] = $validation['data'];
		$out['source']    = 'ai';
		$out['model']     = $response->model;
		$out['reason']    = 'ok';
		if ( $use_cache ) {
			set_transient(
				$cache_key,
				[
					'narrative' => $validation['data'],
					'model'     => $response->model,
				],
				max( 1, (int) Settings::get( 'ai.cache_days' ) ) * DAY_IN_SECONDS
			);
		}
		return $out;
	}

	/**
	 * Narrative for a lead: stored on first call, reused afterwards.
	 *
	 * @param int $lead_id Lead id.
	 * @return array<string, mixed>|null Narrative, or null when the lead has no snapshot.
	 */
	public function for_lead( int $lead_id ): ?array {
		$existing = get_post_meta( $lead_id, LeadRepository::META_NARRATIVE, true );
		$status   = (string) get_post_meta( $lead_id, LeadRepository::META_AI_STATUS, true );
		if ( is_array( $existing ) && $existing && 'pending' !== $status ) {
			return $existing;
		}
		$repo   = new LeadRepository();
		$result = $repo->result( $lead_id );
		$card   = $repo->rate_card( $lead_id );
		if ( ! $result || ! $card ) {
			return null;
		}
		$labels                     = get_post_meta( $lead_id, LeadRepository::META_LABELS, true );
		$facts                      = self::facts( $result, $card, is_array( $labels ) ? $labels : [], (string) get_post_meta( $lead_id, LeadRepository::META_LOCALE, true ) ?: get_locale() );
		$facts['rate_card_version'] = $card->version();
		$run                        = $this->run( $facts );

		update_post_meta( $lead_id, LeadRepository::META_NARRATIVE, $run['narrative'] );
		update_post_meta( $lead_id, LeadRepository::META_AI_STATUS, in_array( $run['source'], [ 'ai', 'cache' ], true ) ? 'ai' : 'fallback' );
		update_post_meta( $lead_id, LeadRepository::META_AI_MODEL, in_array( $run['source'], [ 'ai', 'cache' ], true ) ? $run['model'] : '' );
		update_post_meta(
			$lead_id,
			'_ct_ai_meta',
			[
				'source'   => $run['source'],
				'reason'   => $run['reason'],
				'response' => $run['response'],
				'ts'       => time(),
			]
		);
		return $run['narrative'];
	}

	/**
	 * Cost accounting: provider-reported cost, else tokens × listed price.
	 *
	 * @param ProviderInterface $provider Provider.
	 * @param ProviderResponse  $response Response.
	 */
	private function record_cost( ProviderInterface $provider, ProviderResponse $response ): void {
		$cost = $response->cost_usd;
		if ( null === $cost && $provider instanceof OpenRouterProvider ) {
			$price = $provider->price_for( $response->model );
			if ( $price ) {
				$cost = $response->prompt_tokens * $price['prompt'] + $response->completion_tokens * $price['completion'];
			}
		}
		if ( null !== $cost && $cost > 0 ) {
			BudgetGuard::record( $cost );
		}
	}

	/**
	 * Cache hit/miss counters for the settings page.
	 *
	 * @param string $key hits | misses.
	 */
	private static function bump_stats( string $key ): void {
		$stats         = get_option(
			self::STATS_OPTION,
			[
				'hits'   => 0,
				'misses' => 0,
			]
		);
		$stats         = is_array( $stats ) ? $stats : [
			'hits'   => 0,
			'misses' => 0,
		];
		$stats[ $key ] = (int) ( $stats[ $key ] ?? 0 ) + 1;
		update_option( self::STATS_OPTION, $stats, false );
	}
}
