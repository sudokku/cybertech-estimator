<?php
/**
 * Polite page for an expired, disabled or unknown share link. Deliberately
 * says nothing about whether a lead exists behind the token.
 *
 * @package Cybertech\Estimator
 * @var array<string, mixed> $data { state: expired|disabled|missing, brand, contact_url }
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ct_est_brand       = (array) $data['brand'];
$ct_est_state       = (string) $data['state'];
$ct_est_contact_url = (string) $data['contact_url'];
$ct_est_email       = (string) $ct_est_brand['contact_email'];
$ct_est_phone       = (string) $ct_est_brand['contact_phone'];
$ct_est_tel         = preg_replace( '/[^0-9+]/', '', $ct_est_phone );

switch ( $ct_est_state ) {
	case 'expired':
		$ct_est_title = __( 'This estimate link has expired', 'cybertech-estimator' );
		$ct_est_text  = __( 'Estimate links are valid for a limited time so that the figures you see are always current. Get in touch and we will send you a refreshed estimate.', 'cybertech-estimator' );
		break;
	case 'disabled':
		$ct_est_title = __( 'This estimate is no longer available', 'cybertech-estimator' );
		$ct_est_text  = __( 'The link you followed has been switched off. If you still need the estimate, contact us and we will prepare a new one.', 'cybertech-estimator' );
		break;
	default:
		$ct_est_title = __( 'We could not find that estimate', 'cybertech-estimator' );
		$ct_est_text  = __( 'Check the link you were sent, or ask us to send it again. We are happy to prepare a fresh estimate as well.', 'cybertech-estimator' );
}
?>
<a class="ct-share__skip" href="#ct-share-main"><?php esc_html_e( 'Skip to content', 'cybertech-estimator' ); ?></a>

<header class="ct-share__hero ct-share__hero--short">
	<div class="ct-share__container">
		<p class="ct-share__logo">
			<?php if ( '' !== (string) $ct_est_brand['logo'] ) : ?>
				<img src="<?php echo esc_url( (string) $ct_est_brand['logo'] ); ?>" alt="<?php echo esc_attr( (string) $ct_est_brand['logo_alt'] ); ?>" width="236" height="39" decoding="async">
			<?php else : ?>
				<span class="ct-share__logo-text"><?php echo esc_html( (string) $ct_est_brand['company'] ); ?></span>
			<?php endif; ?>
		</p>
		<p class="ct-share__eyebrow"><?php esc_html_e( 'Project estimate', 'cybertech-estimator' ); ?></p>
		<h1 class="ct-share__headline"><?php echo esc_html( $ct_est_title ); ?></h1>
	</div>
</header>

<main class="ct-share__main" id="ct-share-main">
	<div class="ct-share__container">
		<section class="ct-share__summary ct-share__summary--notice" aria-labelledby="ct-share-notice-title">
			<h2 class="ct-share__h3" id="ct-share-notice-title"><?php esc_html_e( 'What now?', 'cybertech-estimator' ); ?></h2>
			<p class="ct-share__lede"><?php echo esc_html( $ct_est_text ); ?></p>
			<hr class="ct-share__horizon" aria-hidden="true">
			<div class="ct-share__cta-actions ct-share__cta-actions--dark">
				<?php if ( '' !== $ct_est_contact_url ) : ?>
					<a class="ct-share__btn ct-share__btn--primary" href="<?php echo esc_url( $ct_est_contact_url ); ?>"><?php esc_html_e( 'Get in touch', 'cybertech-estimator' ); ?></a>
				<?php endif; ?>
				<?php if ( '' !== $ct_est_email ) : ?>
					<a class="ct-share__btn" href="<?php echo esc_url( 'mailto:' . $ct_est_email ); ?>"><?php echo esc_html( $ct_est_email ); ?></a>
				<?php endif; ?>
				<?php if ( '' !== $ct_est_phone ) : ?>
					<a class="ct-share__btn" href="<?php echo esc_url( 'tel:' . $ct_est_tel ); ?>"><?php echo esc_html( $ct_est_phone ); ?></a>
				<?php endif; ?>
			</div>
		</section>
	</div>
</main>

<footer class="ct-share__footer">
	<div class="ct-share__container">
		<p class="ct-share__legal">
			<?php echo esc_html( (string) $ct_est_brand['legal_name'] ); ?>
			<?php if ( '' !== (string) $ct_est_brand['tagline'] ) : ?>
				<span class="ct-share__sep" aria-hidden="true">·</span>
				<span><?php echo esc_html( (string) $ct_est_brand['tagline'] ); ?></span>
			<?php endif; ?>
		</p>
	</div>
</footer>
