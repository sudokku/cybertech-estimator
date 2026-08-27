<?php
/**
 * Black hero with the 2D-canvas dot-wave.
 *
 * @package Cybertech_Demo
 *
 * @var array{id?: string, eyebrow: string, title: string, cta?: bool} $args
 */

declare(strict_types=1);

$hero_id      = (string) ( $args['id'] ?? 'home-demo' );
$hero_eyebrow = (string) ( $args['eyebrow'] ?? '' );
$hero_title   = (string) ( $args['title'] ?? '' );
$hero_cta     = (bool) ( $args['cta'] ?? false );
?>
<section class="hero" id="<?php echo esc_attr( $hero_id ); ?>">
	<canvas class="hero__wave" data-dot-wave aria-hidden="true"></canvas>
	<div class="hero__inner">
		<?php if ( '' !== $hero_eyebrow ) : ?>
			<span class="hero__eyebrow"><?php echo esc_html( $hero_eyebrow ); ?></span>
		<?php endif; ?>
		<h1 class="hero__title"><?php echo wp_kses( $hero_title, [ 'br' => [] ] ); ?></h1>
		<?php if ( $hero_cta ) : ?>
			<p class="hero__cta"><a class="btn btn--primary" href="<?php echo esc_url( cybertech_demo_estimate_url() ); ?>"><?php esc_html_e( 'Estimate my project', 'cybertech-demo' ); ?></a></p>
		<?php endif; ?>
	</div>
</section>
