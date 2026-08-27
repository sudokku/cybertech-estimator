<?php
/**
 * Elementor integration: registers the "Cybertech" category and the
 * Project Estimator widget, only when Elementor is loaded.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Integration;

/**
 * Elementor hooks.
 */
final class ElementorIntegration {

	public const CATEGORY = 'cybertech';

	/**
	 * Hook registration. Guarded so sites without Elementor never load the
	 * widget class (which extends an Elementor base class).
	 */
	public function register(): void {
		add_action(
			'plugins_loaded',
			static function (): void {
				if ( ! did_action( 'elementor/loaded' ) ) {
					return;
				}
				add_action( 'elementor/elements/categories_registered', [ self::class, 'register_category' ] );
				add_action( 'elementor/widgets/register', [ self::class, 'register_widget' ] );
				add_action( 'elementor/editor/after_enqueue_styles', [ self::class, 'editor_styles' ] );
			},
			20
		);
	}

	/**
	 * Custom category so the widget is easy to find.
	 *
	 * @param \Elementor\Elements_Manager $manager Elements manager.
	 */
	public static function register_category( $manager ): void {
		$manager->add_category(
			self::CATEGORY,
			[
				'title' => __( 'Cybertech', 'cybertech-estimator' ),
				'icon'  => 'eicon-calculator',
			]
		);
	}

	/**
	 * Register the widget.
	 *
	 * @param \Elementor\Widgets_Manager $manager Widgets manager.
	 */
	public static function register_widget( $manager ): void {
		require_once CT_EST_DIR . 'src/Integration/ElementorWidget.php';
		$manager->register( new ElementorWidget() );
	}

	/**
	 * Load the wizard styles inside the editor so the preview matches the front end.
	 */
	public static function editor_styles(): void {
		wp_enqueue_style( 'ct-est-tokens', CT_EST_URL . 'assets/css/tokens.css', [], CT_EST_VERSION );
	}
}
