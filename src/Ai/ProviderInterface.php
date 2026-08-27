<?php
/**
 * LLM provider contract. Add a provider by implementing this and hooking
 * `ct_est_ai_providers` — a direct OpenAI or Gemini driver is a drop-in.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Ai;

interface ProviderInterface {

	/**
	 * Stable id used in settings ('openrouter').
	 */
	public function id(): string;

	/**
	 * Human label for the settings dropdown.
	 */
	public function label(): string;

	/**
	 * Run a structured completion.
	 *
	 * @param string               $system System prompt.
	 * @param string               $user   User prompt.
	 * @param array<string, mixed> $schema JSON schema the reply must satisfy.
	 * @param array<string, mixed> $opts   model, max_tokens, timeout, temperature.
	 */
	public function complete( string $system, string $user, array $schema, array $opts = [] ): ProviderResponse;

	/**
	 * Models for the settings datalist: [{id, label, prompt_price, completion_price}].
	 *
	 * @param bool $refresh Bypass the cache.
	 * @return array<int, array<string, mixed>>
	 */
	public function list_models( bool $refresh = false ): array;

	/**
	 * Whether the provider has what it needs (key, model).
	 */
	public function is_configured(): bool;
}
