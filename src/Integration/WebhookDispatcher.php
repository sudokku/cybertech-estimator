<?php
/**
 * n8n webhook. Signed (HMAC-SHA256 over the raw body), dispatched via
 * WP-Cron so the visitor's request never waits on a remote system, with
 * 3 retries on exponential backoff. Every attempt is logged to the lead.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Integration;

use Cybertech\Estimator\Engine\RateCardDefaults;
use Cybertech\Estimator\Lead\LeadRepository;
use Cybertech\Estimator\Lead\ShareToken;
use Cybertech\Estimator\Support\Logger;
use Cybertech\Estimator\Support\Settings;

/**
 * Webhook dispatch.
 */
final class WebhookDispatcher {

	public const HOOK        = 'ct_est_webhook_dispatch';
	public const META_LOG    = '_ct_webhook_log';
	public const MAX_ATTEMPT = 4;
	// 1 initial + 3 retries.
	public const BACKOFF = [
		2 => 60,
		3 => 300,
		4 => 900,
	];
	// Seconds before attempt N.
	public const TIMEOUT = 10;

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( 'ct_est_lead_created', [ $this, 'schedule' ], 20, 1 );
		add_action( self::HOOK, [ $this, 'dispatch' ], 10, 2 );
	}

	/**
	 * Queue the first attempt if a URL is configured.
	 *
	 * @param int $lead_id Lead id.
	 */
	public function schedule( int $lead_id ): void {
		if ( '' === trim( (string) Settings::get( 'integrations.webhook_url' ) ) ) {
			return;
		}
		wp_schedule_single_event( time(), self::HOOK, [ $lead_id, 1 ] );
	}

	/**
	 * Cron handler: send attempt N, reschedule on failure.
	 *
	 * @param int $lead_id Lead id.
	 * @param int $attempt Attempt number (1-based).
	 */
	public function dispatch( int $lead_id, int $attempt = 1 ): void {
		$url = trim( (string) Settings::get( 'integrations.webhook_url' ) );
		if ( '' === $url ) {
			return;
		}
		$payload = $this->payload( $lead_id );
		if ( ! $payload ) {
			return;
		}
		$outcome = $this->send( $url, $payload );
		$this->log_attempt( $lead_id, $attempt, $outcome );

		if ( $outcome['ok'] ) {
			return;
		}
		$next = $attempt + 1;
		if ( $next <= self::MAX_ATTEMPT ) {
			wp_schedule_single_event( time() + self::BACKOFF[ $next ], self::HOOK, [ $lead_id, $next ] );
		} else {
			Logger::log( 'webhook', 'gave_up', [ 'lead' => $lead_id ] );
		}
	}

	/**
	 * Perform one signed POST. Public so the settings page "Send test
	 * payload" button can call it synchronously.
	 *
	 * @param string               $url     Webhook URL.
	 * @param array<string, mixed> $payload Body.
	 * @return array{ok: bool, status: int, body: string, error: string, request: array<string, mixed>}
	 */
	public function send( string $url, array $payload ): array {
		$timestamp            = (string) time();
		$payload['timestamp'] = (int) $timestamp;
		// Inside the signed body too, so replay checks are tamper-proof.
		$body    = (string) wp_json_encode( $payload );
		$secret  = (string) Settings::get( 'integrations.webhook_secret' );
		$headers = [
			'Content-Type'   => 'application/json',
			'User-Agent'     => 'Cybertech-Estimator/' . CT_EST_VERSION,
			'X-CT-Timestamp' => $timestamp,
			'X-CT-Event'     => 'estimate.created',
		];
		if ( '' !== $secret ) {
			$headers['X-CT-Signature'] = 'sha256=' . hash_hmac( 'sha256', $body, $secret );
		}

		$response = wp_remote_post(
			$url,
			[
				'timeout'     => self::TIMEOUT,
				'headers'     => $headers,
				'body'        => $body,
				'data_format' => 'body',
			]
		);

		$request = [
			'url'     => $url,
			'headers' => $headers,
			'body'    => $body,
		];
		if ( is_wp_error( $response ) ) {
			return [
				'ok'      => false,
				'status'  => 0,
				'body'    => '',
				'error'   => $response->get_error_message(),
				'request' => $request,
			];
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		return [
			'ok'      => $status >= 200 && $status < 300,
			'status'  => $status,
			'body'    => mb_substr( (string) wp_remote_retrieve_body( $response ), 0, 2000 ),
			'error'   => '',
			'request' => $request,
		];
	}

	/**
	 * The webhook body for a lead. Documented in the README.
	 *
	 * @param int $lead_id Lead id.
	 * @return array<string, mixed>|null
	 */
	public function payload( int $lead_id ): ?array {
		$repo   = new LeadRepository();
		$result = $repo->result( $lead_id );
		$card   = $repo->rate_card( $lead_id );
		if ( ! $result || ! $card ) {
			return null;
		}
		$labels = get_post_meta( $lead_id, LeadRepository::META_LABELS, true );
		$roles  = RateCardDefaults::role_labels();
		$team   = [];
		foreach ( (array) ( $result->team['roles'] ?? [] ) as $role => $r ) {
			$team[] = [
				'role'  => $role,
				'label' => $roles[ $role ] ?? $role,
				'hours' => round( (float) $r['hours'], 1 ),
			];
		}
		$post = get_post( $lead_id );

		return [
			'event'             => 'estimate.created',
			'lead_id'           => $lead_id,
			'created_at'        => $post ? mysql2date( 'c', $post->post_date_gmt, false ) : gmdate( 'c' ),
			'status'            => (string) get_post_meta( $lead_id, LeadRepository::META_STATUS, true ),
			'contact'           => $repo->contact( $lead_id ),
			'service'           => [
				'line'  => $result->service_line,
				'label' => (string) $card->get( 'service_lines.' . $result->service_line . '.label', $result->service_line ),
			],
			'estimate'          => [
				'currency'   => $result->currency,
				'price_low'  => $result->price_low,
				'price_high' => $result->price_high,
				'hours'      => round( $result->hours, 1 ),
				'weeks'      => $result->weeks,
				'band'       => $result->band,
				'band_label' => $result->band_label,
				'team'       => $team,
			],
			'qualification'     => [
				'score' => $result->qualification,
				'parts' => $result->qualification_parts,
			],
			'answers'           => $result->answers,
			'labels'            => is_array( $labels ) ? $labels : [],
			'notes'             => (string) ( $result->answers['notes'] ?? '' ),
			'reveal_mode'       => (string) get_post_meta( $lead_id, LeadRepository::META_MODE, true ),
			'share_url'         => ShareToken::url( $repo->token( $lead_id ) ),
			'admin_url'         => admin_url( 'post.php?post=' . $lead_id . '&action=edit' ),
			'rate_card_version' => $result->rate_card_version,
			'ai'                => [
				'status' => (string) get_post_meta( $lead_id, LeadRepository::META_AI_STATUS, true ),
				'model'  => (string) get_post_meta( $lead_id, LeadRepository::META_AI_MODEL, true ),
			],
		];
	}

	/**
	 * Sample payload for the settings page test button (no lead needed).
	 *
	 * @return array<string, mixed>
	 */
	public function sample_payload(): array {
		return [
			'event'             => 'estimate.created',
			'lead_id'           => 0,
			'test'              => true,
			'created_at'        => gmdate( 'c' ),
			'status'            => 'new',
			'contact'           => [
				'name'    => 'Test Lead',
				'email'   => 'test@example.com',
				'company' => 'Example SRL',
				'phone'   => '',
			],
			'service'           => [
				'line'  => 'web',
				'label' => 'Web solutions',
			],
			'estimate'          => [
				'currency'   => 'EUR',
				'price_low'  => 9750,
				'price_high' => 14500,
				'hours'      => 267.2,
				'weeks'      => 9,
				'band'       => 'mid',
				'band_label' => 'Mid-size engagement',
				'team'       => [
					[
						'role'  => 'pm',
						'label' => 'Project manager',
						'hours' => 32.1,
					],
				],
			],
			'qualification'     => [
				'score' => 72,
				'parts' => [],
			],
			'answers'           => [
				'service_line' => 'web',
				'web_platform' => 'wordpress',
			],
			'labels'            => [],
			'notes'             => '',
			'reveal_mode'       => 'gated',
			'share_url'         => home_url( '/estimate/TESTTOKENTESTTOKENTESTTOKENTEST0/' ),
			'admin_url'         => admin_url(),
			'rate_card_version' => 1,
			'ai'                => [
				'status' => 'fallback',
				'model'  => '',
			],
		];
	}

	/**
	 * Append an attempt to the lead's log.
	 *
	 * @param int                  $lead_id Lead id.
	 * @param int                  $attempt Attempt number.
	 * @param array<string, mixed> $outcome send() result.
	 */
	private function log_attempt( int $lead_id, int $attempt, array $outcome ): void {
		$log   = get_post_meta( $lead_id, self::META_LOG, true );
		$log   = is_array( $log ) ? $log : [];
		$log[] = [
			'attempt' => $attempt,
			'ts'      => time(),
			'ok'      => $outcome['ok'],
			'status'  => $outcome['status'],
			'error'   => $outcome['error'],
			'body'    => mb_substr( (string) $outcome['body'], 0, 300 ),
		];
		update_post_meta( $lead_id, self::META_LOG, $log );
		Logger::log(
			'webhook',
			$outcome['ok'] ? 'delivered' : 'failed',
			[
				'lead'    => $lead_id,
				'attempt' => $attempt,
				'status'  => $outcome['status'],
				'error'   => $outcome['error'],
			]
		);
	}
}
