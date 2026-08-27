<?php
/**
 * Dark contact / CTA band.
 *
 * @package Cybertech_Demo
 */

declare(strict_types=1);
?>
<section class="contact-band band-wave" id="contact">
	<div class="contact-band__inner">
		<div class="contact-band__col">
			<h2 class="contact-band__title">Dive in With Us</h2>
			<p class="contact-band__sub">Build Your Vision with Cybertech</p>
			<a class="btn btn--white" href="<?php echo esc_url( cybertech_demo_estimate_url() ); ?>"><?php esc_html_e( 'Estimate my project', 'cybertech-demo' ); ?></a>
		</div>
		<div class="contact-band__col contact-band__spacer" aria-hidden="true"></div>
		<div class="contact-band__col contact-band__contact">
			<p class="contact-band__label">Contact us</p>
			<p class="contact-band__line"><a href="tel:+40723168188">+40723168188</a></p>
			<p class="contact-band__line"><a href="mailto:office@cybertech.ro">office@cybertech.ro</a></p>
		</div>
	</div>
</section>
