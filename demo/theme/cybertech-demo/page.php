<?php
/**
 * Default page: short dark hero strip, white content area (1024px).
 * The estimator page renders the wizard here via [cybertech_estimator].
 *
 * @package Cybertech_Demo
 */

declare(strict_types=1);

get_header();

while ( have_posts() ) :
	the_post();
	?>
	<section class="page-hero band-wave">
		<div class="container">
			<h1 class="page-hero__title"><?php the_title(); ?></h1>
		</div>
	</section>
	<article <?php post_class( 'page-body' ); ?>>
		<div class="container entry-content">
			<?php the_content(); ?>
		</div>
	</article>
	<?php
endwhile;

get_footer();
