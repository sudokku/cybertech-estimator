<?php
/**
 * Self-provisioning: on theme activation (or on demand) create the demo
 * pages, the static front page, the primary menu and the site identity.
 * Idempotent, so it is safe on every host — Playground, a throwaway host,
 * a Local site. Demo leads are seeded by the plugin's own seeder.
 *
 * @package Cybertech_Demo
 */

declare( strict_types=1 );

/**
 * Create or reuse a page by slug.
 *
 * @param string $slug    Page slug.
 * @param string $title   Title.
 * @param string $content Content.
 * @return int Page id.
 */
function cybertech_demo_page( string $slug, string $title, string $content ): int {
	$existing = get_page_by_path( $slug, OBJECT, 'page' );
	if ( $existing instanceof WP_Post ) {
		if ( 'publish' !== $existing->post_status ) {
			wp_update_post( [ 'ID' => $existing->ID, 'post_status' => 'publish' ] );
		}
		if ( '' !== $content && false === strpos( (string) $existing->post_content, $content ) ) {
			wp_update_post( [ 'ID' => $existing->ID, 'post_content' => $content ] );
		}
		return (int) $existing->ID;
	}
	$id = wp_insert_post(
		[
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_content' => $content,
		]
	);
	return is_wp_error( $id ) ? 0 : (int) $id;
}

/**
 * Provision the demo site. Returns a short log for CLI/Playground output.
 *
 * @return array<int, string>
 */
function cybertech_demo_provision(): array {
	$log = [];

	update_option( 'blogname', 'Cybertech' );
	update_option( 'blogdescription', 'Navigating the digital ocean since 1999' );

	$home     = cybertech_demo_page( 'home', 'Home', '' );
	$services = cybertech_demo_page( 'services', 'Services', '' );
	$estimate = cybertech_demo_page( 'estimate-project', 'Estimate my project', '[cybertech_estimator]' );
	if ( $services ) {
		update_post_meta( $services, '_wp_page_template', 'template-services.php' );
	}
	if ( $home ) {
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $home );
	}
	$log[] = sprintf( 'pages: home #%d, services #%d, estimate #%d', $home, $services, $estimate );

	// Menu: rebuild the anchor links so re-runs converge.
	$menu = wp_get_nav_menu_object( 'primary' );
	$menu_id = $menu instanceof WP_Term ? (int) $menu->term_id : (int) wp_create_nav_menu( 'Primary' );
	if ( $menu_id && ! is_wp_error( $menu_id ) ) {
		foreach ( wp_get_nav_menu_items( $menu_id ) ?: [] as $item ) {
			wp_delete_post( (int) $item->ID, true );
		}
		$base  = home_url( '/' );
		$items = [
			'Home'     => '#home-demo',
			'Services' => '#our-services',
			'Clients'  => '#our-clients',
			'About us' => '#about-us',
			'Our team' => '#team',
			'Contact'  => '#contact',
		];
		$pos   = 1;
		foreach ( $items as $title => $anchor ) {
			wp_update_nav_menu_item(
				$menu_id,
				0,
				[
					'menu-item-title'    => $title,
					'menu-item-url'      => $base . $anchor,
					'menu-item-type'     => 'custom',
					'menu-item-status'   => 'publish',
					'menu-item-position' => $pos++,
				]
			);
		}
		$locations            = (array) get_theme_mod( 'nav_menu_locations', [] );
		$locations['primary'] = $menu_id;
		set_theme_mod( 'nav_menu_locations', $locations );
		$log[] = 'menu: primary rebuilt (' . count( $items ) . ' items)';
	}

	if ( '/%postname%/' !== get_option( 'permalink_structure' ) ) {
		update_option( 'permalink_structure', '/%postname%/' );
		$log[] = 'permalinks: /%postname%/';
	}
	// The plugin registers the /estimate/{token} rule on activation; flush so it
	// exists on hosts where the theme was activated afterwards.
	flush_rewrite_rules();

	update_option( 'cybertech_demo_provisioned', time() );
	return $log;
}

/**
 * Provision once on activation; later runs are explicit (CLI / Playground).
 */
function cybertech_demo_on_activation(): void {
	if ( ! get_option( 'cybertech_demo_provisioned' ) ) {
		cybertech_demo_provision();
	}
}
add_action( 'after_switch_theme', 'cybertech_demo_on_activation' );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command(
		'cybertech-demo provision',
		static function (): void {
			foreach ( cybertech_demo_provision() as $line ) {
				WP_CLI::log( $line );
			}
			WP_CLI::success( 'Demo site provisioned.' );
		}
	);
}
