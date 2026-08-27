<?php
/**
 * Gated-mode screen: a blurred placeholder card (purely cosmetic — the
 * server sends no figures before the gate) with the contact form overlay.
 *
 * Expects `$data` = [ 'contact_step' => array|null, 'index' => int ]
 * and `$this` = WizardRenderer.
 *
 * @package Cybertech\Estimator
 * @var array<string, mixed>                          $data
 * @var \Cybertech\Estimator\Frontend\WizardRenderer $this
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ct_est_heading = $this->prefix() . '-gate-title';
?>
<section class="ct-est__screen ct-est__gate" data-ct-screen="gate" aria-labelledby="<?php echo esc_attr( $ct_est_heading ); ?>">
	<div class="ct-est__placeholder" aria-hidden="true">
		<div class="ct-est__card-result ct-est__card-result--blurred">
			<span class="ct-est__placeholder-label"></span>
			<span class="ct-est__placeholder-figure"></span>
			<span class="ct-est__placeholder-line ct-est__placeholder-line--short"></span>
			<span class="ct-est__placeholder-line"></span>
			<span class="ct-est__placeholder-line ct-est__placeholder-line--short"></span>
		</div>
	</div>

	<div class="ct-est__gate-form">
		<h2 class="ct-est__step-title" id="<?php echo esc_attr( $ct_est_heading ); ?>" tabindex="-1" data-ct-heading><?php esc_html_e( 'Enter your details to reveal your estimate', 'cybertech-estimator' ); ?></h2>
		<p class="ct-est__lead"><?php esc_html_e( 'Your indicative price range, timeline and team are ready. Tell us where to send them and we will unlock the full estimate right here.', 'cybertech-estimator' ); ?></p>
		<?php
		if ( ! empty( $data['contact_step'] ) ) {
			$ct_est_contact_step          = (array) $data['contact_step'];
			$ct_est_contact_step['title'] = __( 'Your details', 'cybertech-estimator' );
			$this->print_template(
				'step',
				[
					'step'   => $ct_est_contact_step,
					'index'  => (int) $data['index'],
					'screen' => false,
				]
			);
		}
		?>
	</div>
</section>
