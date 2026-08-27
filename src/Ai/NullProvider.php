<?php
/**
 * No-op provider: never configured, so the service always uses the
 * fallback narrative. Selected explicitly or used when nothing else is.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Ai;

/**
 * Null provider.
 */
final class NullProvider implements ProviderInterface {

	/**
	 * Id.
	 */
	public function id(): string {
		return 'null';
	}

	/**
	 * Label.
	 */
	public function label(): string {
		return __( 'None (fallback text only)', 'cybertech-estimator' );
	}

	/**
	 * Always fails so the caller falls back.
	 *
	 * @param string               $system System prompt.
	 * @param string               $user   User prompt.
	 * @param array<string, mixed> $schema Schema.
	 * @param array<string, mixed> $opts   Options.
	 */
	public function complete( string $system, string $user, array $schema, array $opts = [] ): ProviderResponse {
		return ProviderResponse::failure( 'null_provider' );
	}

	/**
	 * No models.
	 *
	 * @param bool $refresh Ignored.
	 * @return array<int, array<string, mixed>>
	 */
	public function list_models( bool $refresh = false ): array {
		return [];
	}

	/**
	 * Never configured.
	 */
	public function is_configured(): bool {
		return false;
	}
}
