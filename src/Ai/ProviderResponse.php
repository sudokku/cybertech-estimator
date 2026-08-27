<?php
/**
 * Normalised provider response.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Ai;

/**
 * Provider response value object.
 */
final class ProviderResponse {

	/**
	 * Constructor.
	 *
	 * @param bool                 $ok                Transport + HTTP success.
	 * @param string               $content           Assistant message content (raw).
	 * @param string               $model             Model that answered.
	 * @param int                  $prompt_tokens     Prompt tokens (0 if unknown).
	 * @param int                  $completion_tokens Completion tokens (0 if unknown).
	 * @param float|null           $cost_usd          Cost reported by the provider, null if unknown.
	 * @param int                  $latency_ms        Round-trip time.
	 * @param string               $error             Error message when !ok.
	 * @param array<string, mixed> $raw               Decoded raw body (for the sandbox).
	 */
	public function __construct(
		public readonly bool $ok,
		public readonly string $content = '',
		public readonly string $model = '',
		public readonly int $prompt_tokens = 0,
		public readonly int $completion_tokens = 0,
		public readonly ?float $cost_usd = null,
		public readonly int $latency_ms = 0,
		public readonly string $error = '',
		public readonly array $raw = []
	) {}

	/**
	 * Failure constructor.
	 *
	 * @param string               $error      Message.
	 * @param int                  $latency_ms Latency.
	 * @param array<string, mixed> $raw Raw body if any.
	 */
	public static function failure( string $error, int $latency_ms = 0, array $raw = [] ): self {
		return new self( false, '', '', 0, 0, null, $latency_ms, $error, $raw );
	}

	/**
	 * Copy with a different raw payload (used to annotate the schema mode).
	 *
	 * @param array<string, mixed> $raw Raw payload.
	 */
	public function with_raw( array $raw ): self {
		return new self( $this->ok, $this->content, $this->model, $this->prompt_tokens, $this->completion_tokens, $this->cost_usd, $this->latency_ms, $this->error, $raw );
	}

	/**
	 * Serialise for the sandbox / lead meta.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return [
			'ok'                => $this->ok,
			'model'             => $this->model,
			'prompt_tokens'     => $this->prompt_tokens,
			'completion_tokens' => $this->completion_tokens,
			'cost_usd'          => $this->cost_usd,
			'latency_ms'        => $this->latency_ms,
			'error'             => $this->error,
			'schema_mode'       => (string) ( $this->raw['schema_mode'] ?? '' ),
		];
	}
}
