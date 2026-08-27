<?php
/**
 * Plugin container: instantiates each module and lets it register hooks.
 *
 * Deliberately boring — no DI framework, no reflection. Each module has a
 * `register(): void` method; the list below is the whole wiring diagram.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator;

use Cybertech\Estimator\Integration\MailNotifier;
use Cybertech\Estimator\Integration\WebhookDispatcher;
use Cybertech\Estimator\Lead\LeadPostType;
use Cybertech\Estimator\Privacy\DataEraser;
use Cybertech\Estimator\Privacy\DataExporter;
use Cybertech\Estimator\Privacy\RetentionCron;
use Cybertech\Estimator\Admin\DemoSeeder;
use Cybertech\Estimator\Rest\AdminAiController;
use Cybertech\Estimator\Rest\NarrativeController;
use Cybertech\Estimator\Rest\PreviewController;
use Cybertech\Estimator\Rest\SandboxController;
use Cybertech\Estimator\Rest\SubmitController;
use Cybertech\Estimator\Rest\TokenController;

/**
 * Plugin container.
 */
final class Plugin {

	/**
	 * Singleton.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Registered module instances, keyed by class name.
	 *
	 * @var array<class-string, object>
	 */
	private array $modules = [];

	/**
	 * Private: use instance().
	 */
	private function __construct() {}

	/**
	 * Singleton accessor.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire modules. Called once from the main plugin file.
	 */
	public function boot(): void {
		add_action( 'init', [ $this, 'load_textdomain' ], 1 );

		foreach ( $this->module_classes() as $class ) {
			if ( ! class_exists( $class ) ) {
				continue;
				// Module not shipped yet (phased build) — skip silently.
			}
			$module = new $class();
			$module->register();
			$this->modules[ $class ] = $module;
		}
	}

	/**
	 * The wiring diagram. Order matters only where a module depends on another's hooks.
	 *
	 * @return array<int, class-string>
	 */
	private function module_classes(): array {
		return [
			LeadPostType::class,
			SandboxController::class,
			PreviewController::class,
			SubmitController::class,
			TokenController::class,
			NarrativeController::class,
			AdminAiController::class,
			MailNotifier::class,
			DataExporter::class,
			DataEraser::class,
			RetentionCron::class,
			DemoSeeder::class,
			WebhookDispatcher::class,
			'Cybertech\\Estimator\\Frontend\\Shortcode',
			// Admin pages (each registers its own submenu under the Estimator menu).
			'Cybertech\\Estimator\\Admin\\RateCardPage',
			'Cybertech\\Estimator\\Admin\\SandboxPage',
			'Cybertech\\Estimator\\Admin\\LeadColumns',
			'Cybertech\\Estimator\\Admin\\LeadMetaBoxes',
			'Cybertech\\Estimator\\Admin\\SettingsPage',
		];
	}

	/**
	 * Fetch a booted module (used by controllers that need a sibling).
	 *
	 * @param string $class_name Module class.
	 * @return object
	 * @throws \RuntimeException When the module was never booted.
	 */
	public function get( string $class_name ): object {
		if ( ! isset( $this->modules[ $class_name ] ) ) {
			throw new \RuntimeException( sprintf( 'Module %s is not registered.', esc_html( $class_name ) ) );
		}
		return $this->modules[ $class_name ];
	}

	/**
	 * Load translations.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'cybertech-estimator', false, dirname( CT_EST_BASENAME ) . '/languages' );
	}
}
