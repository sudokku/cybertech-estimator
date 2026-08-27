<?php
/**
 * Elementor integration: registers the "Cybertech" category and the
 * Project Estimator widget, only when Elementor is loaded.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Integration;

use Cybertech\Estimator\Frontend\Assets;
use Cybertech\Estimator\Support\Settings;

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
				add_action( 'elementor/preview/enqueue_scripts', [ self::class, 'preview_assets' ] );
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
	 * The editor preview iframe renders widgets over AJAX, so the shortcode's
	 * own `Assets::enqueue()` never runs in that request. Enqueue the wizard
	 * assets + JS config here; per-placement mode/service come from the
	 * wizard's data attributes, so one shared config is enough.
	 */
	public static function preview_assets(): void {
		$permalink = get_permalink();
		Assets::enqueue(
			Assets::config(
				Settings::reveal_mode(),
				'',
				is_string( $permalink ) && '' !== $permalink ? $permalink : home_url( '/' )
			)
		);
	}
}
