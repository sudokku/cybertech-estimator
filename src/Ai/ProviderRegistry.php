<?php
/**
 * Provider lookup, filterable via `ct_est_ai_providers`.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Ai;

use Cybertech\Estimator\Support\Settings;

/**
 * Registry.
 */
final class ProviderRegistry {

	/**
	 * All providers keyed by id.
	 *
	 * @return array<string, ProviderInterface>
	 */
	public static function all(): array {
		$providers = [
			'openrouter' => new OpenRouterProvider(),
			'null'       => new NullProvider(),
		];
		/**
		 * Filters the available AI providers.
		 *
		 * @param array<string, ProviderInterface> $providers Providers keyed by id.
		 */
		$filtered = apply_filters( 'ct_est_ai_providers', $providers );
		return array_filter( (array) $filtered, static fn( $p ): bool => $p instanceof ProviderInterface );
	}

	/**
	 * Choices for the settings dropdown.
	 *
	 * @return array<string, string>
	 */
	public static function choices(): array {
		$out = [];
		foreach ( self::all() as $id => $provider ) {
			$out[ $id ] = $provider->label();
		}
		return $out;
	}

	/**
	 * The configured provider (NullProvider when unknown).
	 */
	public static function current(): ProviderInterface {
		$id = (string) Settings::get( 'ai.provider' );
		return self::all()[ $id ] ?? new NullProvider();
	}
}
