<?php
/**
 * Server-renders the whole questionnaire as one real `<form>`: a fieldset
 * per step, real labels, native inputs. JS then shows one screen at a time
 * (progressive enhancement); without JS the visitor sees a friendly
 * message and a contact CTA.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Frontend;

use Cybertech\Estimator\Brand;
use Cybertech\Estimator\Engine\Questionnaire;
use Cybertech\Estimator\Security\Honeypot;
use Cybertech\Estimator\Support\Settings;

/**
 * Wizard markup.
 */
final class WizardRenderer {

	/**
	 * Per-request counter so two wizards on one page get distinct ids.
	 *
	 * @var int
	 */
	private static int $instances = 0;

	/**
	 * Id prefix for this instance.
	 *
	 * @var string
	 */
	private string $prefix = 'ct-est';

	/**
	 * Resolved reveal mode.
	 *
	 * @var string
	 */
	private string $mode = 'gated';

	/**
	 * Service prefilter or ''.
	 *
	 * @var string
	 */
	private string $service = '';

	/**
	 * Render the wizard.
	 *
	 * @param array{mode: string, service: string, title: string} $args Render arguments.
	 * @return string
	 */
	public function render( array $args ): string {
		++self::$instances;
		$this->prefix  = 1 === self::$instances ? 'ct-est' : 'ct-est-' . self::$instances;
		$this->mode    = $args['mode'];
		$this->service = $args['service'];

		$steps         = Questionnaire::steps();
		$contact_step  = null;
		$pricing_steps = [];
		foreach ( $steps as $step ) {
			if ( 'contact' === $step['id'] ) {
				$contact_step = $step;
			} else {
				$pricing_steps[] = $step;
			}
		}

		$contact_url = (string) Settings::get( 'general.contact_page' );
		if ( '' === $contact_url ) {
			$contact_url = 'mailto:' . Brand::get( 'contact_email' );
		}

		ob_start();
		?>
		<div class="ct-est" id="<?php echo esc_attr( $this->prefix ); ?>" data-ct-estimator data-ct-mode="<?php echo esc_attr( $this->mode ); ?>" data-ct-service="<?php echo esc_attr( $this->service ); ?>">
			<script>document.currentScript.parentNode.classList.add('ct-est--js');</script>
			<?php if ( '' !== $args['title'] ) : ?>
				<h2 class="ct-est__title"><?php echo esc_html( $args['title'] ); ?></h2>
			<?php endif; ?>

			<noscript>
				<div class="ct-est__noscript" role="status">
					<p><?php esc_html_e( 'The interactive estimator needs JavaScript to calculate a price. You can still reach us directly and we will prepare an estimate for you.', 'cybertech-estimator' ); ?></p>
					<a class="ct-est__btn ct-est__btn--primary" href="<?php echo esc_url( $contact_url ); ?>"><?php esc_html_e( 'Contact us', 'cybertech-estimator' ); ?></a>
				</div>
				<style>.ct-est__nav,.ct-est__progress,.ct-est__live{display:none !important}</style>
			</noscript>

			<form class="ct-est__form" method="post" action="" novalidate data-ct-form>
				<div class="ct-est__progress" data-ct-progress>
					<p class="ct-est__progress-text" data-ct-progress-text aria-live="polite"></p>
					<div class="ct-est__progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" data-ct-progress-bar>
						<span class="ct-est__progress-fill" data-ct-progress-fill></span>
					</div>
				</div>

				<p class="ct-est__live" aria-live="polite" aria-atomic="true" data-ct-live></p>
				<p class="ct-est__form-error" role="alert" data-ct-form-error hidden></p>

				<?php
				foreach ( $pricing_steps as $index => $step ) {
					$this->print_template(
						'step',
						[
							'step'   => $step,
							'index'  => $index,
							'screen' => true,
						]
					);
				}

				if ( 'gated' === $this->mode ) {
					$this->print_template(
						'gate',
						[
							'contact_step' => $contact_step,
							'index'        => count( $pricing_steps ),
						]
					);
				} else {
					$this->print_template( 'result', [ 'variant' => 'result' ] );
					if ( null !== $contact_step ) {
						$this->print_template(
							'step',
							[
								'step'   => $contact_step,
								'index'  => count( $pricing_steps ),
								'screen' => true,
							]
						);
					}
				}//end if

				$this->print_template( 'result', [ 'variant' => 'final' ] );
				?>

				<div class="ct-est__hp" aria-hidden="true">
					<label for="<?php echo esc_attr( $this->prefix . '-website' ); ?>"><?php esc_html_e( 'Website', 'cybertech-estimator' ); ?></label>
					<input type="text" id="<?php echo esc_attr( $this->prefix . '-website' ); ?>" name="<?php echo esc_attr( Honeypot::FIELD_HONEY ); ?>" tabindex="-1" autocomplete="off" data-ct-honeypot>
				</div>
				<input type="hidden" name="<?php echo esc_attr( Honeypot::FIELD_TOKEN ); ?>" value="<?php echo esc_attr( Honeypot::issue_token() ); ?>" data-ct-token>

				<div class="ct-est__nav" data-ct-nav>
					<button type="button" class="ct-est__btn ct-est__btn--ghost" data-ct-back><?php esc_html_e( 'Back', 'cybertech-estimator' ); ?></button>
					<button type="button" class="ct-est__btn ct-est__btn--primary" data-ct-next><?php esc_html_e( 'Next', 'cybertech-estimator' ); ?></button>
					<button type="submit" class="ct-est__btn ct-est__btn--primary" data-ct-submit hidden>
						<?php 'gated' === $this->mode ? esc_html_e( 'Reveal my estimate', 'cybertech-estimator' ) : esc_html_e( 'Send me the full estimate', 'cybertech-estimator' ); ?>
					</button>
				</div>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render one question (called from the step template).
	 *
	 * @param array<string, mixed> $question Question definition.
	 * @return string
	 */
	public function render_question( array $question ): string {
		$id       = (string) $question['id'];
		$type     = (string) $question['type'];
		$dom_id   = $this->prefix . '-q-' . $id;
		$err_id   = $dom_id . '-error';
		$help_id  = $dom_id . '-help';
		$help     = (string) ( $question['help'] ?? '' );
		$required = ! empty( $question['required'] );
		$label    = (string) $question['label'];
		if ( Questionnaire::TYPE_CHECKBOX === $type && '' === $label ) {
			$label = Brand::get( 'consent_text' );
		}
		$described = [];
		if ( '' !== $help ) {
			$described[] = $help_id;
		}
		$described[] = $err_id;

		ob_start();
		?>
		<div class="ct-est__q ct-est__q--<?php echo esc_attr( $type ); ?>" data-ct-question="<?php echo esc_attr( $id ); ?>" data-ct-type="<?php echo esc_attr( $type ); ?>">
			<?php if ( Questionnaire::TYPE_SINGLE === $type || Questionnaire::TYPE_MULTI === $type ) : ?>
				<fieldset class="ct-est__group" aria-describedby="<?php echo esc_attr( implode( ' ', $described ) ); ?>"<?php echo $required ? ' aria-required="true"' : ''; ?>>
					<legend class="ct-est__label"><?php echo esc_html( $label ); ?><?php echo $required ? '' : ' <span class="ct-est__optional">' . esc_html__( '(optional)', 'cybertech-estimator' ) . '</span>'; ?></legend>
					<?php if ( '' !== $help ) : ?>
						<p class="ct-est__help" id="<?php echo esc_attr( $help_id ); ?>"><?php echo esc_html( $help ); ?></p>
					<?php endif; ?>
					<div class="ct-est__options">
						<?php
						$default = $question['default'] ?? null;
						if ( 'service_line' === $id && '' !== $this->service ) {
							$default = $this->service;
						}
						$i = 0;
						foreach ( (array) $question['options'] as $value => $option ) :
							++$i;
							$opt_id  = $dom_id . '-' . $i;
							$checked = Questionnaire::TYPE_MULTI === $type
								? in_array( $value, (array) $default, true )
								: (string) $value === (string) $default;
							?>
							<div class="ct-est__option">
								<input
									type="<?php echo Questionnaire::TYPE_MULTI === $type ? 'checkbox' : 'radio'; ?>"
									id="<?php echo esc_attr( $opt_id ); ?>"
									name="<?php echo esc_attr( $id ); ?>"
									value="<?php echo esc_attr( (string) $value ); ?>"
									<?php checked( $checked ); ?>
									<?php echo $required && Questionnaire::TYPE_SINGLE === $type ? 'required' : ''; ?>
								>
								<label class="ct-est__card" for="<?php echo esc_attr( $opt_id ); ?>">
									<span class="ct-est__card-label"><?php echo esc_html( (string) $option['label'] ); ?></span>
									<?php if ( '' !== (string) ( $option['help'] ?? '' ) ) : ?>
										<span class="ct-est__card-help"><?php echo esc_html( (string) $option['help'] ); ?></span>
									<?php endif; ?>
								</label>
							</div>
						<?php endforeach; ?>
					</div>
				</fieldset>

			<?php elseif ( Questionnaire::TYPE_NUMBER === $type ) : ?>
				<label class="ct-est__label" for="<?php echo esc_attr( $dom_id ); ?>"><?php echo esc_html( $label ); ?></label>
				<?php if ( '' !== $help ) : ?>
					<p class="ct-est__help" id="<?php echo esc_attr( $help_id ); ?>"><?php echo esc_html( $help ); ?></p>
				<?php endif; ?>
				<input
					type="number"
					class="ct-est__input ct-est__input--number"
					id="<?php echo esc_attr( $dom_id ); ?>"
					name="<?php echo esc_attr( $id ); ?>"
					inputmode="numeric"
					min="<?php echo esc_attr( (string) $question['min'] ); ?>"
					max="<?php echo esc_attr( (string) $question['max'] ); ?>"
					step="1"
					value="<?php echo esc_attr( (string) ( $question['default'] ?? $question['min'] ) ); ?>"
					aria-describedby="<?php echo esc_attr( implode( ' ', $described ) ); ?>"
					<?php echo $required ? 'required' : ''; ?>
				>

			<?php elseif ( Questionnaire::TYPE_CHECKBOX === $type ) : ?>
				<div class="ct-est__consent">
					<input
						type="checkbox"
						id="<?php echo esc_attr( $dom_id ); ?>"
						name="<?php echo esc_attr( $id ); ?>"
						value="1"
						aria-describedby="<?php echo esc_attr( $err_id ); ?>"
						<?php echo $required ? 'required' : ''; ?>
					>
					<label for="<?php echo esc_attr( $dom_id ); ?>"><?php echo esc_html( $label ); ?></label>
				</div>

			<?php elseif ( 'notes' === $id ) : ?>
				<label class="ct-est__label" for="<?php echo esc_attr( $dom_id ); ?>"><?php echo esc_html( $label ); ?><?php echo $required ? '' : ' <span class="ct-est__optional">' . esc_html__( '(optional)', 'cybertech-estimator' ) . '</span>'; ?></label>
				<?php if ( '' !== $help ) : ?>
					<p class="ct-est__help" id="<?php echo esc_attr( $help_id ); ?>"><?php echo esc_html( $help ); ?></p>
				<?php endif; ?>
				<textarea
					class="ct-est__input ct-est__textarea"
					id="<?php echo esc_attr( $dom_id ); ?>"
					name="<?php echo esc_attr( $id ); ?>"
					rows="5"
					maxlength="<?php echo esc_attr( (string) ( $question['max'] ?? Questionnaire::NOTES_MAX ) ); ?>"
					aria-describedby="<?php echo esc_attr( implode( ' ', array_merge( $described, [ $dom_id . '-count' ] ) ) ); ?>"
				></textarea>
				<p class="ct-est__count" id="<?php echo esc_attr( $dom_id . '-count' ); ?>" data-ct-count aria-live="off">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: characters used, 2: maximum characters */
							__( '%1$s / %2$s characters', 'cybertech-estimator' ),
							'0',
							number_format_i18n( (int) ( $question['max'] ?? Questionnaire::NOTES_MAX ) )
						)
					);
					?>
				</p>

			<?php else : ?>
				<?php
				$input_type   = Questionnaire::TYPE_EMAIL === $type ? 'email' : ( 'phone' === $id ? 'tel' : 'text' );
				$autocomplete = [
					'name'    => 'name',
					'email'   => 'email',
					'company' => 'organization',
					'phone'   => 'tel',
				][ $id ] ?? 'off';
				?>
				<label class="ct-est__label" for="<?php echo esc_attr( $dom_id ); ?>"><?php echo esc_html( $label ); ?><?php echo $required ? '' : ' <span class="ct-est__optional">' . esc_html__( '(optional)', 'cybertech-estimator' ) . '</span>'; ?></label>
				<?php if ( '' !== $help ) : ?>
					<p class="ct-est__help" id="<?php echo esc_attr( $help_id ); ?>"><?php echo esc_html( $help ); ?></p>
				<?php endif; ?>
				<input
					type="<?php echo esc_attr( $input_type ); ?>"
					class="ct-est__input"
					id="<?php echo esc_attr( $dom_id ); ?>"
					name="<?php echo esc_attr( $id ); ?>"
					autocomplete="<?php echo esc_attr( $autocomplete ); ?>"
					maxlength="<?php echo esc_attr( (string) ( $question['max'] ?? 120 ) ); ?>"
					aria-describedby="<?php echo esc_attr( implode( ' ', $described ) ); ?>"
					<?php echo $required ? 'required' : ''; ?>
				>
			<?php endif; ?>

			<p class="ct-est__error" id="<?php echo esc_attr( $err_id ); ?>" data-ct-error hidden></p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Current reveal mode (for templates).
	 */
	public function mode(): string {
		return $this->mode;
	}

	/**
	 * Id prefix (for templates).
	 */
	public function prefix(): string {
		return $this->prefix;
	}

	/**
	 * Echo a template. Output is escaped inside the template files.
	 *
	 * @param string               $name Template name without extension.
	 * @param array<string, mixed> $data Template data.
	 */
	private function print_template( string $name, array $data ): void {
		echo $this->template( $name, $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Load a template from templates/wizard/ with `$data` in scope.
	 *
	 * @param string               $name Template name without extension.
	 * @param array<string, mixed> $data Template data.
	 * @return string
	 */
	private function template( string $name, array $data ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $data is read by the included template.
		$file = CT_EST_DIR . 'templates/wizard/' . $name . '.php';
		if ( ! is_readable( $file ) ) {
			return '';
		}
		ob_start();
		include $file;
		return (string) ob_get_clean();
	}
}
