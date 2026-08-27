<?php
/**
 * 404.
 *
 * @package Cybertech_Demo
 */

declare(strict_types=1);

get_header();
?>
<section class="page-hero band-wave">
	<div class="container">
		<h1 class="page-hero__title"><?php esc_html_e( 'Page not found', 'cybertech-demo' ); ?></h1>
	</div>
</section>
<div class="page-body">
	<div class="container entry-content">
		<p><?php esc_html_e( 'Lost at sea. The page you asked for does not exist.', 'cybertech-demo' ); ?></p>
		<p><a class="btn btn--dark" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Back to shore', 'cybertech-demo' ); ?></a></p>
	</div>
</div>
<?php
get_footer();
