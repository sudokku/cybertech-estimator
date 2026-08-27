<?php
/**
 * Plugin Name:       Cybertech Project Estimator
 * Plugin URI:        https://github.com/sudokku/cybertech-estimator
 * Description:       Interactive project estimator: a guided questionnaire that prices Web, Mobile, UI/UX and AI Automation projects from an editable rate card, captures qualified leads and produces a shareable estimate page.
 * Version:           0.1.1
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Radu Chirilov
 * Author URI:        https://github.com/sudokku
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cybertech-estimator
 * Domain Path:       /languages
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CT_EST_VERSION', '0.1.1' );
define( 'CT_EST_FILE', __FILE__ );
define( 'CT_EST_DIR', plugin_dir_path( __FILE__ ) );
define( 'CT_EST_URL', plugin_dir_url( __FILE__ ) );
define( 'CT_EST_BASENAME', plugin_basename( __FILE__ ) );
define( 'CT_EST_MIN_PHP', '8.1' );
define( 'CT_EST_MIN_WP', '6.4' );

/**
 * Environment guard. Runs before the autoloader so an old PHP never parses
 * PHP 8.1 syntax and fatals with an unhelpful message.
 *
 * @return bool
 */
function ct_est_environment_ok(): bool {
	global $wp_version;
	return version_compare( PHP_VERSION, CT_EST_MIN_PHP, '>=' )
		&& version_compare( (string) $wp_version, CT_EST_MIN_WP, '>=' );
}

if ( ! ct_est_environment_ok() ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: minimum PHP version, 2: minimum WordPress version */
						__( 'Cybertech Project Estimator requires PHP %1$s+ and WordPress %2$s+. The plugin is inactive.', 'cybertech-estimator' ),
						CT_EST_MIN_PHP,
						CT_EST_MIN_WP
					)
				)
			);
		}
	);
	return;
}

require_once CT_EST_DIR . 'src/Support/Autoloader.php';
\Cybertech\Estimator\Support\Autoloader::register( CT_EST_DIR . 'src' );

register_activation_hook( __FILE__, [ \Cybertech\Estimator\Activator::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \Cybertech\Estimator\Activator::class, 'deactivate' ] );

\Cybertech\Estimator\Plugin::instance()->boot();
