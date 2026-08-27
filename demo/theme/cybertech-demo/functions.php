<?php
/**
 * Cybertech Demo theme bootstrap.
 *
 * @package Cybertech_Demo
 */

declare(strict_types=1);

define( 'CYBERTECH_DEMO_VERSION', '0.1.0' );

require_once get_theme_file_path( 'inc/icons.php' );

require_once get_template_directory() . '/inc/provision.php';

/**
 * Google Fonts stylesheet (the theme owns fonts; the plugin inherits them).
 * CSS API v1 is used on purpose: a single `family` param survives
 * add_query_arg() on the plugin's share page.
 */
function cybertech_demo_fonts_url(): string {
	return 'https://fonts.googleapis.com/css?family=Montserrat:600,700,800|Poppins:400,500,600,700&display=swap';
}

/**
 * Theme supports, menus.
 */
function cybertech_demo_setup(): void {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'html5', [ 'search-form', 'gallery', 'caption', 'script', 'style', 'navigation-widgets' ] );
	add_theme_support(
		'custom-logo',
		[
			'height'      => 28,
			'width'       => 169,
			'flex-height' => true,
			'flex-width'  => true,
		]
	);
	register_nav_menus( [ 'primary' => __( 'Primary', 'cybertech-demo' ) ] );
}
add_action( 'after_setup_theme', 'cybertech_demo_setup' );

/**
 * Front-end assets.
 */
function cybertech_demo_assets(): void {
	wp_enqueue_style( 'cybertech-demo', get_theme_file_uri( 'assets/css/theme.css' ), [], CYBERTECH_DEMO_VERSION );
	wp_enqueue_script( 'cybertech-demo', get_theme_file_uri( 'assets/js/theme.js' ), [], CYBERTECH_DEMO_VERSION, [ 'in_footer' => true ] );
}
add_action( 'wp_enqueue_scripts', 'cybertech_demo_assets' );

/**
 * Fonts + favicon as plain <link>s, early in <head>.
 */
function cybertech_demo_head_links(): void {
	?>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link rel="stylesheet" href="<?php echo esc_url( cybertech_demo_fonts_url() ); ?>"><?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Google Fonts, plain link on purpose. ?>
	<?php
	if ( ! has_site_icon() ) {
		printf( '<link rel="icon" href="%s" sizes="192x192">' . "\n", esc_url( get_theme_file_uri( 'assets/img/favicon-192.png' ) ) );
	}
}
add_action( 'wp_head', 'cybertech_demo_head_links', 5 );

/**
 * Same fonts on the plugin's theme-less share page.
 *
 * @param array<int, array{href: string, media: string}> $styles Stylesheets.
 * @return array<int, array{href: string, media: string}>
 */
function cybertech_demo_share_styles( array $styles ): array {
	$styles[] = [
		'href'  => cybertech_demo_fonts_url(),
		'media' => 'all',
	];
	return $styles;
}
add_filter( 'ct_est_share_styles', 'cybertech_demo_share_styles' );

/**
 * Body classes.
 *
 * @param string[] $classes Classes.
 * @return string[]
 */
function cybertech_demo_body_class( array $classes ): array {
	$classes[] = 'ct-theme';
	$classes[] = 'has-transparent-header';
	if ( is_page_template( 'template-services.php' ) ) {
		$classes[] = 'is-services-page';
	}
	return $classes;
}
add_filter( 'body_class', 'cybertech_demo_body_class' );

/**
 * Service cards link to /estimate-project/?service=web|mobile|design|ai —
 * pass the query param through to the shortcode as a prefilter.
 *
 * @param array<string, mixed> $out Combined attributes.
 * @return array<string, mixed>
 */
function cybertech_demo_estimator_service( array $out ): array {
	if ( '' === (string) ( $out['service'] ?? '' ) && isset( $_GET['service'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public read-only prefilter.
		$service = sanitize_key( wp_unslash( (string) $_GET['service'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( in_array( $service, [ 'web', 'mobile', 'design', 'ai' ], true ) ) {
			$out['service'] = $service;
		}
	}
	return $out;
}
add_filter( 'shortcode_atts_cybertech_estimator', 'cybertech_demo_estimator_service' );

/**
 * Nav items: label → anchor on the home page.
 *
 * @return array<string, string>
 */
function cybertech_demo_nav_items(): array {
	return [
		'Home'     => home_url( '/#home-demo' ),
		'Services' => home_url( '/#our-services' ),
		'Clients'  => home_url( '/#our-clients' ),
		'About us' => home_url( '/#about-us' ),
		'Our team' => home_url( '/#team' ),
		'Contact'  => home_url( '/#contact' ),
	];
}

/**
 * URL of the estimator page (slug estimate-project), with a fallback.
 *
 * @param string $service Optional service prefilter.
 */
function cybertech_demo_estimate_url( string $service = '' ): string {
	$page = get_page_by_path( 'estimate-project' );
	$url  = $page instanceof WP_Post ? (string) get_permalink( $page ) : home_url( '/estimate-project/' );
	return '' === $service ? $url : add_query_arg( 'service', $service, $url );
}

/**
 * Fallback menu when no `primary` menu is assigned yet (pre-seed).
 */
function cybertech_demo_fallback_menu(): void {
	echo '<ul class="nav-menu">';
	foreach ( cybertech_demo_nav_items() as $label => $url ) {
		printf( '<li class="menu-item"><a href="%s">%s</a></li>', esc_url( $url ), esc_html( $label ) );
	}
	echo '</ul>';
}

/**
 * Mark the nav item that matches the current page (Home on the front page,
 * Services on the services template). Anchors are handled by JS scroll-spy.
 *
 * @param string[] $classes Item classes.
 * @param WP_Post  $item    Menu item.
 * @return string[]
 */
function cybertech_demo_nav_item_classes( array $classes, WP_Post $item ): array {
	$url  = (string) $item->url;
	$path = wp_parse_url( $url, PHP_URL_PATH ) ?? '/';
	$frag = (string) wp_parse_url( $url, PHP_URL_FRAGMENT );
	$home = wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ?? '/';

	if ( is_front_page() && untrailingslashit( (string) $path ) === untrailingslashit( (string) $home ) && 'home-demo' === $frag ) {
		$classes[] = 'is-active';
	}
	if ( is_page_template( 'template-services.php' ) && 'our-services' === $frag ) {
		$classes[] = 'is-active';
	}
	return $classes;
}
add_filter( 'nav_menu_css_class', 'cybertech_demo_nav_item_classes', 10, 2 );
