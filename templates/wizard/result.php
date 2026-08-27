<?php
/**
 * Result screens. Two variants share this template:
 *  - 'result' (open/band modes): the pre-contact preview — range or band, timeline, team.
 *  - 'final' (all modes): the unlocked estimate with share link and the narrative slot.
 *
 * All figures are filled by JS from REST responses; nothing is priced here.
 *
 * Expects `$data` = [ 'variant' => 'result'|'final' ] and `$this` = WizardRenderer.
 *
 * @package Cybertech\Estimator
 * @var array<string, mixed>                          $data
 * @var \Cybertech\Estimator\Frontend\WizardRenderer $this
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ct_est_variant      = 'final' === $data['variant'] ? 'final' : 'result';
$ct_est_mode         = $this->mode();
$ct_est_heading      = $this->prefix() . '-' . $ct_est_variant . '-title';
$ct_est_show_figures = 'final' === $ct_est_variant ? 'band' !== $ct_est_mode : 'open' === $ct_est_mode;
?>
<section class="ct-est__screen ct-est__result ct-est__result--<?php echo esc_attr( $ct_est_variant ); ?>" data-ct-screen="<?php echo esc_attr( $ct_est_variant ); ?>" aria-labelledby="<?php echo esc_attr( $ct_est_heading ); ?>">
	<h2 class="ct-est__step-title" id="<?php echo esc_attr( $ct_est_heading ); ?>" tabindex="-1" data-ct-heading>
		<?php 'final' === $ct_est_variant ? esc_html_e( 'Your estimate is ready', 'cybertech-estimator' ) : esc_html_e( 'Your estimate', 'cybertech-estimator' ); ?>
	</h2>
	<p class="ct-est__lead">
		<?php
		if ( 'final' === $ct_est_variant ) {
			esc_html_e( 'Thank you. Here is your full estimate — we have also sent it to your inbox.', 'cybertech-estimator' );
		} elseif ( 'band' === $ct_est_mode ) {
			esc_html_e( 'Based on your answers, this is the budget band and timeline we would expect.', 'cybertech-estimator' );
		} else {
			esc_html_e( 'Based on your answers, this is the indicative range and timeline we would expect.', 'cybertech-estimator' );
		}
		?>
	</p>

	<div class="ct-est__card-result" data-ct-result-card>
		<p class="ct-est__result-status" data-ct-result-status><?php esc_html_e( 'Calculating your estimate…', 'cybertech-estimator' ); ?></p>

		<div class="ct-est__result-body" data-ct-result-body hidden>
			<?php if ( $ct_est_show_figures ) : ?>
				<p class="ct-est__result-kicker"><?php esc_html_e( 'Indicative range', 'cybertech-estimator' ); ?></p>
				<p class="ct-est__figure" data-ct-range></p>
			<?php endif; ?>
			<?php if ( ! $ct_est_show_figures || 'final' === $ct_est_variant ) : ?>
				<p class="ct-est__result-kicker"><?php esc_html_e( 'Budget band', 'cybertech-estimator' ); ?></p>
				<p class="ct-est__band" data-ct-band></p>
			<?php endif; ?>

			<hr class="ct-est__horizon" aria-hidden="true">

			<dl class="ct-est__meta">
				<div class="ct-est__meta-item">
					<dt><?php esc_html_e( 'Timeline', 'cybertech-estimator' ); ?></dt>
					<dd data-ct-weeks></dd>
				</div>
				<?php if ( $ct_est_show_figures ) : ?>
					<div class="ct-est__meta-item">
						<dt><?php esc_html_e( 'Effort', 'cybertech-estimator' ); ?></dt>
						<dd data-ct-hours></dd>
					</div>
				<?php endif; ?>
			</dl>

			<div class="ct-est__team">
				<h3 class="ct-est__subtitle"><?php esc_html_e( 'Suggested team', 'cybertech-estimator' ); ?></h3>
				<ul class="ct-est__team-list" data-ct-team></ul>
			</div>
		</div>
	</div>

	<?php if ( 'final' === $ct_est_variant ) : ?>
		<div class="ct-est__narrative" data-ct-narrative aria-busy="true">
			<h3 class="ct-est__subtitle"><?php esc_html_e( 'How we got here', 'cybertech-estimator' ); ?></h3>
			<div class="ct-est__skeleton" aria-hidden="true">
				<span class="ct-est__skeleton-line"></span>
				<span class="ct-est__skeleton-line"></span>
				<span class="ct-est__skeleton-line ct-est__skeleton-line--short"></span>
			</div>
		</div>

		<div class="ct-est__share">
			<h3 class="ct-est__subtitle"><?php esc_html_e( 'Share this estimate', 'cybertech-estimator' ); ?></h3>
			<div class="ct-est__share-row">
				<label class="ct-est__sr-only" for="<?php echo esc_attr( $this->prefix() . '-share-url' ); ?>"><?php esc_html_e( 'Shareable link to this estimate', 'cybertech-estimator' ); ?></label>
				<input type="url" class="ct-est__input ct-est__share-input" id="<?php echo esc_attr( $this->prefix() . '-share-url' ); ?>" readonly data-ct-share-url>
				<button type="button" class="ct-est__btn ct-est__btn--ghost" data-ct-copy><?php esc_html_e( 'Copy link', 'cybertech-estimator' ); ?></button>
			</div>
			<p class="ct-est__help" data-ct-copy-status aria-live="polite"></p>
		</div>

		<div class="ct-est__next">
			<h3 class="ct-est__subtitle"><?php esc_html_e( 'What happens next', 'cybertech-estimator' ); ?></h3>
			<ol class="ct-est__next-list">
				<li><?php esc_html_e( 'We review your answers and the assumptions behind this estimate.', 'cybertech-estimator' ); ?></li>
				<li><?php esc_html_e( 'Within two working days we get in touch to clarify scope and priorities.', 'cybertech-estimator' ); ?></li>
				<li><?php esc_html_e( 'You receive a detailed proposal with a fixed price and delivery plan.', 'cybertech-estimator' ); ?></li>
			</ol>
		</div>
	<?php endif; ?>
</section>
