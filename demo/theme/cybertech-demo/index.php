<?php
/**
 * Minimal fallback template.
 *
 * @package Cybertech_Demo
 */

declare(strict_types=1);

get_header();
?>
<section class="page-hero band-wave">
	<div class="container">
		<h1 class="page-hero__title"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></h1>
	</div>
</section>
<div class="page-body">
	<div class="container entry-content">
		<?php
		if ( have_posts() ) :
			while ( have_posts() ) :
				the_post();
				?>
				<article <?php post_class(); ?>>
					<h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
					<?php the_excerpt(); ?>
				</article>
				<?php
			endwhile;
		else :
			?>
			<p><?php esc_html_e( 'Nothing here yet.', 'cybertech-demo' ); ?></p>
		<?php endif; ?>
	</div>
</div>
<?php
get_footer();
