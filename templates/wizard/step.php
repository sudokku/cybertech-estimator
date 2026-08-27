<?php
/**
 * One questionnaire step: a fieldset with a legend and its questions.
 *
 * Expects `$data` = [ 'step' => array, 'index' => int, 'screen' => bool ]
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

$ct_est_step    = (array) $data['step'];
$ct_est_step_id = (string) $ct_est_step['id'];
$ct_est_heading = $this->prefix() . '-step-' . $ct_est_step_id . '-title';
?>
<fieldset
	class="ct-est__step<?php echo ! empty( $data['screen'] ) ? ' ct-est__screen' : ''; ?>"
	data-ct-step="<?php echo esc_attr( $ct_est_step_id ); ?>"
	data-ct-index="<?php echo esc_attr( (string) $data['index'] ); ?>"
	<?php echo ! empty( $data['screen'] ) ? 'data-ct-screen="step"' : ''; ?>
	aria-labelledby="<?php echo esc_attr( $ct_est_heading ); ?>"
>
	<legend class="ct-est__legend">
		<h2 class="ct-est__step-title" id="<?php echo esc_attr( $ct_est_heading ); ?>" tabindex="-1" data-ct-heading><?php echo esc_html( (string) $ct_est_step['title'] ); ?></h2>
	</legend>
	<?php
	foreach ( (array) $ct_est_step['questions'] as $ct_est_question ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside render_question().
		echo $this->render_question( (array) $ct_est_question );
	}
	?>
</fieldset>
