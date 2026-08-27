<?php
/**
 * Charcoal footer: legal line, social circles, RO legal links.
 *
 * @package Cybertech_Demo
 */

declare(strict_types=1);
?>
</main>

<footer class="site-footer" id="site-footer">
	<div class="site-footer__inner">
		<div class="site-footer__legal">
			<p>&copy; 1999&ndash;<?php echo esc_html( gmdate( 'Y' ) ); ?>. All rights reserved by ALANTIS WEB STUDIO S.R.L.</p>
			<p>Str. Nucetului, Nr. 7, Mansarda, Sector 4, Bucure&#537;ti</p>
			<p>RO39659270 &middot; J40/10548/2018</p>
		</div>
		<ul class="social-links" aria-label="<?php esc_attr_e( 'Social', 'cybertech-demo' ); ?>">
			<li><a href="https://www.facebook.com/cybertech.dev" target="_blank" rel="noopener noreferrer" aria-label="Facebook"><?php cybertech_demo_icon( 'facebook' ); ?></a></li>
			<li><a href="https://www.linkedin.com/company/27119514" target="_blank" rel="noopener noreferrer" aria-label="LinkedIn"><?php cybertech_demo_icon( 'linkedin' ); ?></a></li>
			<li><a href="https://www.instagram.com/cybertech.ro" target="_blank" rel="noopener noreferrer" aria-label="Instagram"><?php cybertech_demo_icon( 'instagram' ); ?></a></li>
		</ul>
		<p class="site-footer__links">
			<a href="#">Termeni &#537;i condi&#539;ii</a> &middot;
			<a href="#">Politica de confiden&#539;ialitate</a> &middot;
			<a href="#">Politica de cookies</a>
		</p>
	</div>
</footer>

<a class="back-to-top" href="#site-header" data-back-to-top aria-label="<?php esc_attr_e( 'Back to top', 'cybertech-demo' ); ?>" hidden><?php cybertech_demo_icon( 'arrow-up' ); ?></a>

<?php wp_footer(); ?>
</body>
</html>
