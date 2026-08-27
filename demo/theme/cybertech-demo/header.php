<?php
/**
 * Header: transparent 128px bar over the hero, fixed 68px #1F1F25 bar on scroll.
 *
 * @package Cybertech_Demo
 */

declare(strict_types=1);
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="skip-link screen-reader-text" href="#content"><?php esc_html_e( 'Skip to content', 'cybertech-demo' ); ?></a>

<header class="site-header" id="site-header" data-sticky-header>
	<div class="site-header__inner">
		<a class="site-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home" aria-label="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
			<img src="<?php echo esc_url( get_theme_file_uri( 'assets/img/logo-baw.png' ) ); ?>" alt="" width="169" height="28" decoding="async">
		</a>

		<button class="nav-toggle" type="button" aria-controls="site-nav" aria-expanded="false" data-nav-open>
			<span class="screen-reader-text"><?php esc_html_e( 'Open menu', 'cybertech-demo' ); ?></span>
			<?php cybertech_demo_icon( 'menu' ); ?>
		</button>

		<nav class="site-nav" id="site-nav" aria-label="<?php esc_attr_e( 'Primary', 'cybertech-demo' ); ?>" data-site-nav>
			<button class="nav-close" type="button" data-nav-close>
				<span class="screen-reader-text"><?php esc_html_e( 'Close menu', 'cybertech-demo' ); ?></span>
				<?php cybertech_demo_icon( 'close' ); ?>
			</button>
			<?php
			wp_nav_menu(
				[
					'theme_location' => 'primary',
					'container'      => false,
					'menu_class'     => 'nav-menu',
					'depth'          => 1,
					'fallback_cb'    => 'cybertech_demo_fallback_menu',
				]
			);
			?>
			<a class="btn btn--primary site-nav__cta" href="<?php echo esc_url( cybertech_demo_estimate_url() ); ?>"><?php esc_html_e( 'Estimate my project', 'cybertech-demo' ); ?></a>
		</nav>
	</div>
	<div class="nav-backdrop" data-nav-close hidden></div>
</header>

<main id="content" class="site-main">
