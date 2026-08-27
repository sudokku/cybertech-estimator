<?php
/**
 * `[cybertech_estimator]` shortcode.
 *
 * Attributes:
 *  - service: web | mobile | design | ai — preselects the service line and
 *    skips the first step.
 *  - mode: open | band | gated — overrides the reveal-mode setting.
 *  - title: optional heading rendered above the wizard.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Frontend;

use Cybertech\Estimator\Engine\Questionnaire;
use Cybertech\Estimator\Support\Settings;

/**
 * Shortcode module.
 */
final class Shortcode {

	public const TAG = 'cybertech_estimator';

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_shortcode( self::TAG, [ $this, 'render' ] );
		add_action( 'wp_enqueue_scripts', [ Assets::class, 'register' ] );
	}

	/**
	 * Render the wizard.
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render( array|string $atts = [] ): string {
		$atts = shortcode_atts(
			[
				'service' => '',
				'mode'    => '',
				'title'   => '',
			],
			is_array( $atts ) ? $atts : [],
			self::TAG
		);

		$service = strtolower( trim( (string) $atts['service'] ) );
		if ( ! in_array( $service, Questionnaire::SERVICE_LINES, true ) ) {
			$service = '';
		}

		$mode = strtolower( trim( (string) $atts['mode'] ) );
		if ( ! in_array( $mode, [ 'open', 'band', 'gated' ], true ) ) {
			$mode = Settings::reveal_mode();
		}

		$source_url = self::current_url();

		Assets::enqueue( Assets::config( $mode, $service, $source_url ) );

		return ( new WizardRenderer() )->render(
			[
				'mode'    => $mode,
				'service' => $service,
				'title'   => wp_strip_all_tags( (string) $atts['title'] ),
			]
		);
	}

	/**
	 * Canonical URL of the page being rendered (stored on the lead as source).
	 */
	private static function current_url(): string {
		$permalink = get_permalink();
		if ( is_string( $permalink ) && '' !== $permalink ) {
			return $permalink;
		}
		$request = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '/';
		return home_url( $request );
	}
}
