<?php
/**
 * Sandbox admin page — the engine's workbench.
 *
 * Renders the whole questionnaire from the declarative schema on the left
 * and, on the right, everything the engine produces for those answers: the
 * result as each reveal mode would show it to a visitor, the calculation
 * breakdown (every row linked to the rate-card field that drove it), the raw
 * result JSON, and the Phase 4 AI panel (empty shells for now).
 *
 * The page is server-rendered so the form is meaningful without JS; the JS
 * only wires live re-pricing through the admin-only sandbox REST endpoint.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Admin;

use Cybertech\Estimator\Brand;
use Cybertech\Estimator\Engine\Questionnaire;
use Cybertech\Estimator\Engine\RateCardDefaults;
use Cybertech\Estimator\Engine\RateCardRepository;
use Cybertech\Estimator\Lead\LeadPostType;
use Cybertech\Estimator\Rest\SandboxController;
use Cybertech\Estimator\Support\Money;

/**
 * Estimator → Sandbox submenu page.
 */
final class SandboxPage {

	public const SLUG           = 'ct-est-sandbox';
	public const RATE_CARD_SLUG = 'ct-est-rate-card';
	public const CAPABILITY     = 'manage_options';
	public const HANDLE         = 'ct-est-sandbox';

	/**
	 * Hook suffix returned by add_submenu_page(); assets are enqueued only there.
	 *
	 * @var string
	 */
	private string $hook = '';

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/**
	 * Submenu under the Leads CPT menu.
	 */
	public function add_menu(): void {
		$hook = add_submenu_page(
			self::parent_slug(),
			__( 'Estimator sandbox', 'cybertech-estimator' ),
			__( 'Sandbox', 'cybertech-estimator' ),
			self::CAPABILITY,
			self::SLUG,
			[ $this, 'render' ]
		);
		// add_submenu_page() returns false when the user lacks the capability.
		$this->hook = is_string( $hook ) ? $hook : '';
	}

	/**
	 * Parent menu slug (the Leads list).
	 */
	public static function parent_slug(): string {
		return 'edit.php?post_type=' . LeadPostType::POST_TYPE;
	}

	/**
	 * URL of this page.
	 */
	public static function url(): string {
		return admin_url( self::parent_slug() . '&page=' . self::SLUG );
	}

	/**
	 * URL of the sibling rate-card page; breakdown rows deep-link into it
	 * with `#rc-<dot.path → dashes>` anchors.
	 */
	public static function rate_card_url(): string {
		return admin_url( self::parent_slug() . '&page=' . self::RATE_CARD_SLUG );
	}

	/**
	 * Enqueue assets on this screen only. The config object is injected as
	 * JSON (not wp_localize_script, which stringifies every scalar).
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( string $hook ): void {
		if ( '' === $this->hook || $hook !== $this->hook ) {
			return;
		}
		wp_enqueue_style( self::HANDLE, CT_EST_URL . 'assets/css/sandbox.css', [], CT_EST_VERSION );
		wp_enqueue_script(
			self::HANDLE,
			CT_EST_URL . 'assets/js/sandbox.js',
			[],
			CT_EST_VERSION,
			[
				'in_footer' => true,
				'strategy'  => 'defer',
			]
		);
		wp_add_inline_script( self::HANDLE, 'window.ctEstSandbox = ' . wp_json_encode( $this->config() ) . ';', 'before' );
	}

	/**
	 * Page config handed to sandbox.js.
	 *
	 * @return array<string, mixed>
	 */
	private function config(): array {
		$card       = ( new RateCardRepository() )->load();
		$thresholds = (array) $card->get( 'qualification.thresholds', [] );

		$questions = [];
		foreach ( Questionnaire::questions() as $id => $question ) {
			// Only what the JS needs to read the form and toggle visibility.
			$questions[ $id ] = [
				'type'    => (string) $question['type'],
				'contact' => Questionnaire::is_contact_question( $question ),
				'show_if' => (object) ( $question['show_if'] ?? [] ),
			];
		}

		return [
			'endpoint'        => rest_url( SandboxController::NAMESPACE . '/sandbox/estimate' ),
			'nonce'           => wp_create_nonce( 'wp_rest' ),
			'questions'       => $questions,
			'presets'         => self::presets(),
			'roleLabels'      => RateCardDefaults::role_labels(),
			'stepLabels'      => self::step_labels(),
			'thresholds'      => [
				'green' => (int) ( $thresholds['green'] ?? 70 ),
				'amber' => (int) ( $thresholds['amber'] ?? 40 ),
			],
			'rateCardUrl'     => self::rate_card_url(),
			'rateCardVersion' => $card->version(),
			'currency'        => $card->currency(),
			'currencySymbol'  => Money::symbol( $card->currency() ),
			'locale'          => str_replace( '_', '-', get_locale() ),
			'i18n'            => [
				'estimating'  => __( 'Estimating…', 'cybertech-estimator' ),
				/* translators: %s: error message */
				'error'       => __( 'Estimate failed: %s', 'cybertech-estimator' ),
				/* translators: %s: number of weeks */
				'weeks'       => __( '%s weeks', 'cybertech-estimator' ),
				/* translators: %s: hours */
				'hours'       => __( '%s h', 'cybertech-estimator' ),
				/* translators: %s: points */
				'points'      => __( '%s pts', 'cybertech-estimator' ),
				/* translators: %s: score out of 100 */
				'score'       => __( '%s / 100', 'cybertech-estimator' ),
				/* translators: %s: rate-card version number */
				'rateCard'    => __( 'Rate card v%s', 'cybertech-estimator' ),
				'copied'      => __( 'Copied to clipboard.', 'cybertech-estimator' ),
				'copyFailed'  => __( 'Copy failed — select the text manually.', 'cybertech-estimator' ),
				'openInCard'  => __( 'Open this value in the rate card', 'cybertech-estimator' ),
				'notFromCard' => __( 'Derived value — not a rate-card field', 'cybertech-estimator' ),
			],
		];
	}

	/**
	 * Human labels for the breakdown step ids, in engine order.
	 *
	 * @return array<string, string>
	 */
	private static function step_labels(): array {
		return [
			'base_hours'    => __( '1 · Base hours', 'cybertech-estimator' ),
			'add_hours'     => __( '2 · Additive hours', 'cybertech-estimator' ),
			'multiplier'    => __( '3 · Multipliers', 'cybertech-estimator' ),
			'urgency'       => __( '4 · Urgency', 'cybertech-estimator' ),
			'contingency'   => __( '5 · Contingency', 'cybertech-estimator' ),
			'clamp'         => __( '6 · Minimum hours', 'cybertech-estimator' ),
			'team'          => __( '7 · Team allocation', 'cybertech-estimator' ),
			'rate'          => __( '7 · Effective rate', 'cybertech-estimator' ),
			'price'         => __( '7 · Price', 'cybertech-estimator' ),
			'add_price'     => __( '8 · Additive price', 'cybertech-estimator' ),
			'range'         => __( '9 · Range', 'cybertech-estimator' ),
			'weeks'         => __( '10 · Duration', 'cybertech-estimator' ),
			'band'          => __( '11 · Reveal band', 'cybertech-estimator' ),
			'qualification' => __( '12 · Qualification (admin-only)', 'cybertech-estimator' ),
		];
	}

	/**
	 * Realistic presets, one per service line. Answers use schema ids so a
	 * schema change surfaces here as a visible mismatch, not a silent skip.
	 *
	 * @return array<int, array{id: string, label: string, answers: array<string, mixed>}>
	 */
	private static function presets(): array {
		return [
			[
				'id'      => 'web',
				'label'   => __( 'Web — bilingual WooCommerce shop with migration', 'cybertech-estimator' ),
				'answers' => [
					'service_line'     => 'web',
					'web_platform'     => 'wordpress',
					'web_ecommerce'    => 'woocommerce',
					'web_templates'    => 8,
					'web_multilingual' => 'yes',
					'web_integrations' => 2,
					'web_migration'    => 'yes',
					'urgency'          => 'normal',
					'budget'           => '15k_40k',
					'maintenance'      => 'yes',
					'hosting'          => 'cybertech',
					'notes'            => 'We sell garden furniture in Romania and Hungary. Current site is a 2016 WordPress with a broken checkout; we need to keep the product URLs and connect our SmartBill invoicing.',
				],
			],
			[
				'id'      => 'mobile',
				'label'   => __( 'Mobile — Flutter delivery app, both stores, new backend', 'cybertech-estimator' ),
				'answers' => [
					'service_line'     => 'mobile',
					'mobile_framework' => 'flutter',
					'mobile_platforms' => 'both',
					'mobile_offline'   => 'yes',
					'mobile_auth'      => 'yes',
					'mobile_payments'  => 'yes',
					'mobile_push'      => 'yes',
					'mobile_backend'   => 'needed',
					'urgency'          => 'urgent',
					'budget'           => '40k_100k',
					'maintenance'      => 'yes',
					'hosting'          => 'cybertech',
					'notes'            => 'Courier app for our own fleet of 40 drivers: route list, proof of delivery with photo, cash-on-delivery reconciliation. Must work in basements with no signal.',
				],
			],
			[
				'id'      => 'design',
				'label'   => __( 'UI/UX — SaaS dashboard redesign, 25 screens', 'cybertech-estimator' ),
				'answers' => [
					'service_line'          => 'design',
					'design_deliverables'   => [ 'research', 'wireframes', 'hifi', 'prototype' ],
					'design_screens'        => 25,
					'design_brand'          => 'no',
					'design_testing_rounds' => 2,
					'urgency'               => 'flexible',
					'budget'                => '5k_15k',
					'maintenance'           => 'no',
					'hosting'               => 'client',
				],
			],
			[
				'id'      => 'ai',
				'label'   => __( 'AI — support automation with voice agent', 'cybertech-estimator' ),
				'answers' => [
					'service_line' => 'ai',
					'ai_workflows' => 4,
					'ai_provider'  => 'openai',
					'ai_voice'     => 'yes',
					'ai_systems'   => 3,
					'ai_data'      => 'medium',
					'ai_hitl'      => 'yes',
					'urgency'      => 'normal',
					'budget'       => '15k_40k',
					'maintenance'  => 'yes',
					'hosting'      => 'undecided',
					'notes'        => 'Triage inbound support e-mails and calls, draft replies from our Zendesk macros and Notion knowledge base, escalate refunds to a human.',
				],
			],
		];
	}

	/**
	 * Schema defaults as an answers map (drives the initial render).
	 *
	 * @return array<string, mixed>
	 */
	private static function defaults(): array {
		$out = [];
		foreach ( Questionnaire::questions() as $id => $question ) {
			if ( array_key_exists( 'default', $question ) ) {
				$out[ $id ] = $question['default'];
			}
		}
		// The schema deliberately leaves the service line unanswered (the
		// wizard forces a choice), but the engine cannot price without one,
		// so the sandbox opens on the first line instead of on an error.
		if ( ! isset( $out['service_line'] ) ) {
			$out['service_line'] = Questionnaire::SERVICE_LINES[0];
		}
		return $out;
	}

	/* ---------- rendering ---------- */

	/**
	 * Page markup.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to use the sandbox.', 'cybertech-estimator' ) );
		}
		$card     = ( new RateCardRepository() )->load();
		$defaults = self::defaults();
		?>
		<div class="wrap ct-sb">
			<header class="ct-sb__header">
				<h1 class="ct-sb__title"><?php esc_html_e( 'Sandbox', 'cybertech-estimator' ); ?></h1>
				<a class="ct-sb__badge" id="ct-sb-rate-card-version" href="<?php echo esc_url( self::rate_card_url() ); ?>">
					<?php
					/* translators: %s: rate-card version number */
					echo esc_html( sprintf( __( 'Rate card v%s', 'cybertech-estimator' ), $card->version() ) );
					?>
				</a>
				<p class="ct-sb__intro"><?php esc_html_e( 'Answer as a visitor would and watch the engine price it. Nothing here is stored — no lead is created and no e-mail is sent.', 'cybertech-estimator' ); ?></p>
			</header>

			<?php $this->render_stats(); ?>
			<p id="ct-sb-status" class="ct-sb__status" role="status"></p>

			<div class="ct-sb__grid">
				<section class="ct-sb__left" aria-labelledby="ct-sb-form-title">
					<div class="ct-sb__toolbar">
						<h2 id="ct-sb-form-title" class="ct-sb__h2"><?php esc_html_e( 'Questionnaire', 'cybertech-estimator' ); ?></h2>
						<label class="ct-sb__preset" for="ct-sb-preset">
							<span><?php esc_html_e( 'Preset', 'cybertech-estimator' ); ?></span>
							<select id="ct-sb-preset">
								<option value=""><?php esc_html_e( '— choose —', 'cybertech-estimator' ); ?></option>
								<?php foreach ( self::presets() as $preset ) : ?>
									<option value="<?php echo esc_attr( $preset['id'] ); ?>"><?php echo esc_html( $preset['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<button type="button" class="button" id="ct-sb-reset"><?php esc_html_e( 'Reset to defaults', 'cybertech-estimator' ); ?></button>
					</div>
					<form id="ct-sb-form" class="ct-sb-form" novalidate>
						<?php foreach ( Questionnaire::steps() as $step ) : ?>
							<?php $this->render_step( $step, $defaults ); ?>
						<?php endforeach; ?>
					</form>
				</section>

				<section class="ct-sb__right" aria-label="<?php esc_attr_e( 'Engine output', 'cybertech-estimator' ); ?>">
					<?php $this->render_tabs(); ?>
				</section>
			</div>
		</div>
		<?php
	}

	/**
	 * Header stat strip — announced politely so screen-reader users hear
	 * the new numbers after each re-price without being interrupted.
	 */
	private function render_stats(): void {
		$stats = [
			'hours' => __( 'Hours', 'cybertech-estimator' ),
			'range' => __( 'Range', 'cybertech-estimator' ),
			'weeks' => __( 'Duration', 'cybertech-estimator' ),
			'band'  => __( 'Band', 'cybertech-estimator' ),
			'score' => __( 'Qualification', 'cybertech-estimator' ),
		];
		?>
		<div class="ct-sb__stats" id="ct-sb-stats" aria-live="polite" aria-atomic="true">
			<?php foreach ( $stats as $key => $label ) : ?>
				<div class="ct-sb-stat ct-sb-stat--<?php echo esc_attr( $key ); ?>">
					<span class="ct-sb-stat__label"><?php echo esc_html( $label ); ?></span>
					<span class="ct-sb-stat__value" id="ct-sb-stat-<?php echo esc_attr( $key ); ?>">—</span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * One wizard step as a fieldset. The contact step is disabled as a
	 * whole: its fields are never priced, but showing them keeps the
	 * sandbox an honest mirror of what the visitor goes through.
	 *
	 * @param array<string, mixed> $step     Step definition.
	 * @param array<string, mixed> $defaults Default answers.
	 */
	private function render_step( array $step, array $defaults ): void {
		$is_contact = 'contact' === $step['id'];
		?>
		<fieldset class="ct-sb-step<?php echo $is_contact ? ' ct-sb-step--muted' : ''; ?>" id="ct-sb-step-<?php echo esc_attr( (string) $step['id'] ); ?>" data-step="<?php echo esc_attr( (string) $step['id'] ); ?>"<?php echo $is_contact ? ' disabled' : ''; ?>>
			<legend class="ct-sb-step__legend"><?php echo esc_html( (string) $step['title'] ); ?></legend>
			<?php if ( $is_contact ) : ?>
				<p class="ct-sb-step__note"><?php esc_html_e( 'Not used for pricing — shown so the sandbox mirrors the full wizard.', 'cybertech-estimator' ); ?></p>
			<?php endif; ?>
			<?php foreach ( $step['questions'] as $question ) : ?>
				<?php $this->render_question( $question, $defaults ); ?>
			<?php endforeach; ?>
		</fieldset>
		<?php
	}

	/**
	 * Dispatch a question to its control renderer.
	 *
	 * @param array<string, mixed> $question Question definition.
	 * @param array<string, mixed> $defaults Default answers.
	 */
	private function render_question( array $question, array $defaults ): void {
		switch ( $question['type'] ) {
			case Questionnaire::TYPE_SINGLE:
				$this->render_choice( $question, $defaults, false );
				break;
			case Questionnaire::TYPE_MULTI:
				$this->render_choice( $question, $defaults, true );
				break;
			case Questionnaire::TYPE_NUMBER:
				$this->render_number( $question, $defaults );
				break;
			case Questionnaire::TYPE_CHECKBOX:
				$this->render_checkbox( $question );
				break;
			default:
				$this->render_text( $question );
		}
	}

	/**
	 * Attributes shared by every question wrapper: the id JS keys on, the
	 * `show_if` rule, and the initial `hidden` state computed from defaults
	 * so the first paint is right before JS runs.
	 *
	 * @param array<string, mixed> $question Question definition.
	 * @param array<string, mixed> $defaults Default answers.
	 */
	private function wrapper_attrs( array $question, array $defaults ): string {
		$attrs = ' data-question="' . esc_attr( (string) $question['id'] ) . '"';
		if ( ! empty( $question['show_if'] ) ) {
			$attrs .= ' data-show-if="' . esc_attr( (string) wp_json_encode( $question['show_if'] ) ) . '"';
		}
		if ( ! Questionnaire::is_visible( $question, $defaults ) ) {
			$attrs .= ' hidden';
		}
		return $attrs;
	}

	/**
	 * Radio (single) or checkbox (multi) group.
	 *
	 * @param array<string, mixed> $question Question definition.
	 * @param array<string, mixed> $defaults Default answers.
	 * @param bool                 $multi    Checkboxes instead of radios.
	 */
	private function render_choice( array $question, array $defaults, bool $multi ): void {
		$id       = (string) $question['id'];
		$selected = (array) ( $defaults[ $id ] ?? [] );
		$name     = $multi ? $id . '[]' : $id;
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wrapper_attrs() escapes each attribute.
		echo '<fieldset class="ct-sb-q ct-sb-q--choice"' . $this->wrapper_attrs( $question, $defaults ) . '>';
		echo '<legend class="ct-sb-q__label">' . esc_html( (string) $question['label'] ) . '</legend>';
		if ( ! empty( $question['help'] ) ) {
			echo '<p class="ct-sb-q__help">' . esc_html( (string) $question['help'] ) . '</p>';
		}
		echo '<div class="ct-sb-options">';
		foreach ( (array) $question['options'] as $value => $option ) {
			$input_id = 'ct-sb-' . $id . '-' . (string) $value;
			printf(
				'<label class="ct-sb-option" for="%1$s"><input type="%2$s" id="%1$s" name="%3$s" value="%4$s"%5$s><span class="ct-sb-option__text"><span class="ct-sb-option__label">%6$s</span>%7$s</span></label>',
				esc_attr( $input_id ),
				$multi ? 'checkbox' : 'radio',
				esc_attr( $name ),
				esc_attr( (string) $value ),
				checked( in_array( (string) $value, array_map( 'strval', $selected ), true ), true, false ),
				esc_html( (string) $option['label'] ),
				'' !== (string) ( $option['help'] ?? '' ) ? '<span class="ct-sb-option__help">' . esc_html( (string) $option['help'] ) . '</span>' : ''
			);
		}
		echo '</div></fieldset>';
	}

	/**
	 * Number input with the schema's min/max/default.
	 *
	 * @param array<string, mixed> $question Question definition.
	 * @param array<string, mixed> $defaults Default answers.
	 */
	private function render_number( array $question, array $defaults ): void {
		$id       = (string) $question['id'];
		$input_id = 'ct-sb-' . $id;
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wrapper_attrs() escapes each attribute.
		echo '<div class="ct-sb-q ct-sb-q--number"' . $this->wrapper_attrs( $question, $defaults ) . '>';
		echo '<label class="ct-sb-q__label" for="' . esc_attr( $input_id ) . '">' . esc_html( (string) $question['label'] ) . '</label>';
		if ( ! empty( $question['help'] ) ) {
			echo '<p class="ct-sb-q__help">' . esc_html( (string) $question['help'] ) . '</p>';
		}
		printf(
			'<input type="number" id="%1$s" name="%2$s" min="%3$s" max="%4$s" step="1" value="%5$s" inputmode="numeric"><span class="ct-sb-q__range">%6$s</span>',
			esc_attr( $input_id ),
			esc_attr( $id ),
			esc_attr( (string) $question['min'] ),
			esc_attr( (string) $question['max'] ),
			esc_attr( (string) ( $defaults[ $id ] ?? $question['min'] ) ),
			/* translators: 1: minimum, 2: maximum */
			esc_html( sprintf( __( '%1$s – %2$s', 'cybertech-estimator' ), $question['min'], $question['max'] ) )
		);
		echo '</div>';
	}

	/**
	 * Free text (textarea for the notes question, single-line otherwise).
	 * Text questions never carry `show_if` or defaults, so no wrapper rule.
	 *
	 * @param array<string, mixed> $question Question definition.
	 */
	private function render_text( array $question ): void {
		$id       = (string) $question['id'];
		$input_id = 'ct-sb-' . $id;
		$max      = (int) ( $question['max'] ?? Questionnaire::NOTES_MAX );
		echo '<div class="ct-sb-q ct-sb-q--text" data-question="' . esc_attr( $id ) . '">';
		echo '<label class="ct-sb-q__label" for="' . esc_attr( $input_id ) . '">' . esc_html( (string) $question['label'] ) . '</label>';
		if ( ! empty( $question['help'] ) ) {
			echo '<p class="ct-sb-q__help">' . esc_html( (string) $question['help'] ) . '</p>';
		}
		if ( 'notes' === $id ) {
			printf(
				'<textarea id="%1$s" name="%2$s" rows="4" maxlength="%3$d"></textarea><span class="ct-sb-q__range"><span id="ct-sb-notes-count">0</span> / %3$d</span>',
				esc_attr( $input_id ),
				esc_attr( $id ),
				absint( $max )
			);
		} else {
			printf(
				'<input type="%1$s" id="%2$s" name="%3$s" maxlength="%4$d" autocomplete="off">',
				Questionnaire::TYPE_EMAIL === $question['type'] ? 'email' : 'text',
				esc_attr( $input_id ),
				esc_attr( $id ),
				absint( $max )
			);
		}
		echo '</div>';
	}

	/**
	 * Consent checkbox; the wording comes from the brand map so it is
	 * white-labelled exactly like the visitor sees it.
	 *
	 * @param array<string, mixed> $question Question definition.
	 */
	private function render_checkbox( array $question ): void {
		$id       = (string) $question['id'];
		$input_id = 'ct-sb-' . $id;
		$label    = '' !== (string) $question['label'] ? (string) $question['label'] : Brand::get( 'consent_text' );
		printf(
			'<div class="ct-sb-q ct-sb-q--checkbox" data-question="%1$s"><label class="ct-sb-option" for="%2$s"><input type="checkbox" id="%2$s" name="%1$s" value="1"><span class="ct-sb-option__text">%3$s</span></label></div>',
			esc_attr( $id ),
			esc_attr( $input_id ),
			esc_html( $label )
		);
	}

	/**
	 * Right column: tablist + four panels.
	 */
	private function render_tabs(): void {
		$tabs = [
			'visitor'   => __( 'Visitor view', 'cybertech-estimator' ),
			'breakdown' => __( 'Breakdown', 'cybertech-estimator' ),
			'json'      => __( 'Result JSON', 'cybertech-estimator' ),
			'ai'        => __( 'AI', 'cybertech-estimator' ),
		];
		?>
		<div class="ct-sb-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Result views', 'cybertech-estimator' ); ?>">
			<?php $first = true; ?>
			<?php foreach ( $tabs as $key => $label ) : ?>
				<button type="button" class="ct-sb-tab" role="tab" id="ct-sb-tab-<?php echo esc_attr( $key ); ?>" aria-controls="ct-sb-panel-<?php echo esc_attr( $key ); ?>" aria-selected="<?php echo $first ? 'true' : 'false'; ?>" tabindex="<?php echo $first ? '0' : '-1'; ?>"><?php echo esc_html( $label ); ?></button>
				<?php $first = false; ?>
			<?php endforeach; ?>
		</div>

		<div class="ct-sb-panel" role="tabpanel" id="ct-sb-panel-visitor" aria-labelledby="ct-sb-tab-visitor" tabindex="0">
			<?php $this->render_visitor_panel(); ?>
		</div>
		<div class="ct-sb-panel" role="tabpanel" id="ct-sb-panel-breakdown" aria-labelledby="ct-sb-tab-breakdown" tabindex="0" hidden>
			<?php $this->render_breakdown_panel(); ?>
		</div>
		<div class="ct-sb-panel" role="tabpanel" id="ct-sb-panel-json" aria-labelledby="ct-sb-tab-json" tabindex="0" hidden>
			<div class="ct-sb-panel__bar">
				<p class="ct-sb-panel__lead"><?php esc_html_e( 'The exact object the engine returns — what gets snapshotted on a lead.', 'cybertech-estimator' ); ?></p>
				<button type="button" class="button" id="ct-sb-copy-json"><?php esc_html_e( 'Copy JSON', 'cybertech-estimator' ); ?></button>
			</div>
			<pre class="ct-sb-json" id="ct-sb-json" tabindex="0"></pre>
		</div>
		<div class="ct-sb-panel" role="tabpanel" id="ct-sb-panel-ai" aria-labelledby="ct-sb-tab-ai" tabindex="0" hidden>
			<?php $this->render_ai_panel(); ?>
		</div>
		<?php
	}

	/**
	 * Three visitor cards, one per reveal mode. The shells are static; JS
	 * fills the numbers. The gated card is deliberately fed no figures —
	 * mirroring the wire, where gated `/preview` carries nothing numeric.
	 */
	private function render_visitor_panel(): void {
		$modes = [
			'open'  => [ __( 'Open', 'cybertech-estimator' ), __( 'Range, duration and team shown right away.', 'cybertech-estimator' ) ],
			'band'  => [ __( 'Band', 'cybertech-estimator' ), __( 'Engagement size and duration; no figures anywhere.', 'cybertech-estimator' ) ],
			'gated' => [ __( 'Gated', 'cybertech-estimator' ), __( 'Blurred card until the visitor leaves an e-mail.', 'cybertech-estimator' ) ],
		];
		?>
		<div class="ct-sb-visitors">
			<?php foreach ( $modes as $mode => [ $title, $desc ] ) : ?>
				<article class="ct-sb-visitor" data-mode="<?php echo esc_attr( $mode ); ?>" aria-labelledby="ct-sb-visitor-<?php echo esc_attr( $mode ); ?>-title">
					<h3 class="ct-sb-visitor__mode" id="ct-sb-visitor-<?php echo esc_attr( $mode ); ?>-title"><?php echo esc_html( $title ); ?> <span class="ct-sb-visitor__desc"><?php echo esc_html( $desc ); ?></span></h3>
					<div class="ct-sb-card">
						<div class="ct-sb-card__stripe" aria-hidden="true"></div>
						<?php if ( 'gated' === $mode ) : ?>
							<div class="ct-sb-card__body ct-sb-card__body--blurred" aria-hidden="true">
								<p class="ct-sb-card__eyebrow"><?php esc_html_e( 'Your estimate', 'cybertech-estimator' ); ?></p>
								<p class="ct-sb-card__figure">€••,••• – €••,•••</p>
								<p class="ct-sb-card__meta">•• <?php esc_html_e( 'weeks', 'cybertech-estimator' ); ?></p>
								<ul class="ct-sb-team"><li>••••••••</li><li>••••••</li><li>••••••••••</li></ul>
							</div>
							<div class="ct-sb-gate">
								<p class="ct-sb-gate__title"><?php esc_html_e( 'Your estimate is ready', 'cybertech-estimator' ); ?></p>
								<p class="ct-sb-gate__text"><?php esc_html_e( 'Enter your email to reveal', 'cybertech-estimator' ); ?></p>
								<div class="ct-sb-gate__form">
									<label class="screen-reader-text" for="ct-sb-gate-email"><?php esc_html_e( 'Work email (preview only)', 'cybertech-estimator' ); ?></label>
									<input type="email" id="ct-sb-gate-email" placeholder="you@company.com" disabled>
									<button type="button" class="ct-sb-btn" disabled><?php esc_html_e( 'Reveal', 'cybertech-estimator' ); ?></button>
								</div>
							</div>
						<?php elseif ( 'band' === $mode ) : ?>
							<div class="ct-sb-card__body">
								<p class="ct-sb-card__eyebrow"><?php esc_html_e( 'Your project looks like a', 'cybertech-estimator' ); ?></p>
								<p class="ct-sb-card__figure" id="ct-sb-v-band-label">—</p>
								<p class="ct-sb-card__meta" id="ct-sb-v-band-weeks">—</p>
								<p class="ct-sb-card__note"><?php esc_html_e( 'We’ll send a detailed proposal with figures by e-mail.', 'cybertech-estimator' ); ?></p>
							</div>
						<?php else : ?>
							<div class="ct-sb-card__body">
								<p class="ct-sb-card__eyebrow"><?php esc_html_e( 'Your estimate', 'cybertech-estimator' ); ?></p>
								<p class="ct-sb-card__figure" id="ct-sb-v-open-range">—</p>
								<p class="ct-sb-card__meta" id="ct-sb-v-open-weeks">—</p>
								<p class="ct-sb-card__sub"><?php esc_html_e( 'Suggested team', 'cybertech-estimator' ); ?></p>
								<ul class="ct-sb-team" id="ct-sb-v-open-team"></ul>
							</div>
						<?php endif; ?>
					</div>
				</article>
			<?php endforeach; ?>
		</div>
		<section class="ct-sb-answers" aria-labelledby="ct-sb-answers-title">
			<h3 class="ct-sb__h3" id="ct-sb-answers-title"><?php esc_html_e( 'What the visitor told us', 'cybertech-estimator' ); ?></h3>
			<dl class="ct-sb-answers__list" id="ct-sb-v-answers"></dl>
		</section>
		<?php
	}

	/**
	 * Breakdown table shell; rows are rendered by JS from `result.breakdown`.
	 */
	private function render_breakdown_panel(): void {
		?>
		<p class="ct-sb-panel__lead"><?php esc_html_e( 'Every step the engine took, in order. Rows driven by a rate-card value link to that field.', 'cybertech-estimator' ); ?></p>
		<div class="ct-sb-table-wrap">
			<table class="ct-sb-table" id="ct-sb-breakdown">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Label', 'cybertech-estimator' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Input', 'cybertech-estimator' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Operation', 'cybertech-estimator' ); ?></th>
						<th scope="col" class="is-num"><?php esc_html_e( 'Before', 'cybertech-estimator' ); ?></th>
						<th scope="col" class="is-num"><?php esc_html_e( 'After', 'cybertech-estimator' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Source', 'cybertech-estimator' ); ?></th>
					</tr>
				</thead>
				<tbody id="ct-sb-breakdown-body"></tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Phase 4 shells. Ids are the contract: NarrativeService's sandbox hook
	 * fills these in place, so keep them stable.
	 */
	private function render_ai_panel(): void {
		$sections = [
			'ct-sb-ai-prompt'   => __( 'Prompt that would be sent', 'cybertech-estimator' ),
			'ct-sb-ai-response' => __( 'Raw response', 'cybertech-estimator' ),
			'ct-sb-ai-meta'     => __( 'Validation verdict · latency · tokens · cost', 'cybertech-estimator' ),
		];
		?>
		<div class="ct-sb-panel__bar">
			<p class="ct-sb-panel__lead"><?php esc_html_e( 'Narrative generation lands in Phase 4. The panels below are wired but empty.', 'cybertech-estimator' ); ?></p>
			<label class="ct-sb-toggle" for="ct-sb-force-fallback">
				<input type="checkbox" id="ct-sb-force-fallback" disabled>
				<span><?php esc_html_e( 'Force fallback narrative', 'cybertech-estimator' ); ?></span>
			</label>
		</div>
		<?php foreach ( $sections as $id => $title ) : ?>
			<section class="ct-sb-ai" aria-labelledby="<?php echo esc_attr( $id ); ?>-title">
				<h3 class="ct-sb__h3" id="<?php echo esc_attr( $id ); ?>-title"><?php echo esc_html( $title ); ?></h3>
				<div class="ct-sb-ai__body" id="<?php echo esc_attr( $id ); ?>" data-empty="true">
					<p class="ct-sb-ai__placeholder"><?php esc_html_e( 'Available once the AI layer lands (Phase 4).', 'cybertech-estimator' ); ?></p>
				</div>
			</section>
		<?php endforeach; ?>
		<?php
	}
}
