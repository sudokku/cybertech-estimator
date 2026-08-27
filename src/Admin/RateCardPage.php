<?php
/**
 * Estimator → Rate card: the editor for every coefficient the engine reads.
 *
 * Persistence always goes through RateCardRepository (save / import /
 * reset / rollback) so the version counter and the history ring stay the
 * single source of truth. The "effect on the sample project" figures are
 * computed by the same PricingEngine — server-side on first paint, and
 * via the sandbox REST endpoint with the *unsaved* card while editing —
 * so what the admin sees before saving is exactly what the wizard will
 * compute after.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Admin;

use Cybertech\Estimator\Engine\PricingEngine;
use Cybertech\Estimator\Engine\Questionnaire;
use Cybertech\Estimator\Engine\RateCard;
use Cybertech\Estimator\Engine\RateCardDefaults;
use Cybertech\Estimator\Engine\RateCardRepository;
use Cybertech\Estimator\Lead\LeadPostType;
use Cybertech\Estimator\Rest\SandboxController;
use Cybertech\Estimator\Support\Money;

/**
 * Rate card admin page.
 */
final class RateCardPage {

	public const SLUG       = 'ct-est-rate-card';
	public const CAPABILITY = 'manage_options';

	private const PARENT     = 'edit.php?post_type=' . LeadPostType::POST_TYPE;
	private const NONCE      = 'ct_est_rate_card';
	private const FLASH_KEY  = 'ct_est_rc_flash_';
	private const FORM_ID    = 'ct-rc-form';
	private const MAX_IMPORT = 1048576;
	// 1 MiB: the default card serialises to ~12 KB.

	/**
	 * Screen hook suffix returned by add_submenu_page().
	 *
	 * @var string
	 */
	private string $hook = '';

	/**
	 * Persistence.
	 *
	 * @var RateCardRepository
	 */
	private RateCardRepository $repo;

	/**
	 * One-shot message from the last admin-post redirect (null = none, false = not loaded yet).
	 *
	 * @var array<string, mixed>|null|false
	 */
	private array|null|false $flash = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->repo = new RateCardRepository();
	}

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'admin_notices', [ $this, 'notices' ] );
		foreach ( [ 'save', 'import', 'reset', 'rollback', 'export' ] as $action ) {
			add_action( 'admin_post_ct_est_rate_card_' . $action, [ $this, 'handle_' . $action ] );
		}
	}

	/**
	 * Submenu under the Estimator (lead CPT) menu.
	 */
	public function add_menu(): void {
		$hook       = add_submenu_page(
			self::PARENT,
			__( 'Rate card', 'cybertech-estimator' ),
			__( 'Rate card', 'cybertech-estimator' ),
			self::CAPABILITY,
			self::SLUG,
			[ $this, 'render' ]
		);
		$this->hook = is_string( $hook ) ? $hook : '';
	}

	/**
	 * Assets, only on this screen.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( '' === $this->hook || $hook_suffix !== $this->hook ) {
			return;
		}
		wp_enqueue_style( 'ct-est-admin', CT_EST_URL . 'assets/css/admin.css', [], self::asset_version( 'assets/css/admin.css' ) );
		wp_enqueue_script( 'ct-est-rate-card', CT_EST_URL . 'assets/js/rate-card.js', [], self::asset_version( 'assets/js/rate-card.js' ), [ 'in_footer' => true ] );
		// A JSON config object, not wp_localize_script(): that helper stringifies every value and would turn numbers into strings.
		wp_add_inline_script( 'ct-est-rate-card', 'window.ctEstRateCard = ' . wp_json_encode( $this->config() ) . ';', 'before' );
	}

	/**
	 * Validation errors / success message after a redirect.
	 */
	public function notices(): void {
		$screen = get_current_screen();
		if ( ! $screen || $screen->id !== $this->hook ) {
			return;
		}
		$flash = $this->flash();
		if ( ! $flash ) {
			return;
		}
		$class = 'error' === $flash['type'] ? 'notice-error' : 'notice-success';
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( (string) $flash['message'] ) . '</p>';
		if ( ! empty( $flash['errors'] ) ) {
			echo '<ul class="ct-rc-errors">';
			foreach ( (array) $flash['errors'] as $error ) {
				echo '<li>' . esc_html( (string) $error ) . '</li>';
			}
			echo '</ul>';
		}
		echo '</div>';
	}

	/* ---------- admin-post handlers ---------- */

	/**
	 * Save the edited card.
	 */
	public function handle_save(): void {
		$this->authorize( 'save' );
		/* translators: %d: new version number */
		$this->persist( RateCardSanitizer::sanitize( $this->posted_card() ), __( 'Rate card saved as v%d.', 'cybertech-estimator' ) );
	}

	/**
	 * Import a JSON file (validated, then saved as a new version).
	 */
	public function handle_import(): void {
		$this->authorize( 'import' );
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing -- nonce checked in authorize(); $_FILES paths are server-generated and the content is parsed as JSON then deep-sanitised.
		$tmp  = isset( $_FILES['rate_card_file']['tmp_name'] ) ? (string) $_FILES['rate_card_file']['tmp_name'] : '';
		$size = isset( $_FILES['rate_card_file']['size'] ) ? (int) $_FILES['rate_card_file']['size'] : 0;
		// phpcs:enable
		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			$this->fail( __( 'No file was uploaded.', 'cybertech-estimator' ) );
		}
		if ( $size > self::MAX_IMPORT ) {
			$this->fail( __( 'The file is too large to be a rate card.', 'cybertech-estimator' ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading PHP's own upload temp file.
		$data = json_decode( (string) file_get_contents( $tmp ), true );
		if ( ! is_array( $data ) ) {
			$this->fail( __( 'The file is not a JSON object.', 'cybertech-estimator' ), [ json_last_error_msg() ] );
		}
		/* translators: %d: new version number */
		$this->persist( RateCardSanitizer::sanitize( $data ), __( 'Rate card imported and saved as v%d.', 'cybertech-estimator' ) );
	}

	/**
	 * Reset to the shipped defaults (as a new version).
	 */
	public function handle_reset(): void {
		$this->authorize( 'reset' );
		$errors = $this->repo->reset();
		/* translators: %d: new version number */
		$this->finish( $errors, __( 'Rate card reset to defaults, saved as v%d.', 'cybertech-estimator' ) );
	}

	/**
	 * Roll back to a historic version (as a new version).
	 */
	public function handle_rollback(): void {
		$this->authorize( 'rollback' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorize().
		$version = isset( $_POST['version'] ) ? (int) $_POST['version'] : 0;
		$errors  = $this->repo->rollback( $version );
		/* translators: 1: rolled-back version number, 2: new version number */
		$this->finish( $errors, __( 'Rolled back to v%1$d, saved as v%2$d.', 'cybertech-estimator' ), [ $version ] );
	}

	/**
	 * Download the current card as JSON.
	 */
	public function handle_export(): void {
		$this->authorize( 'export' );
		$card = $this->repo->raw();
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="rate-card-v' . (int) ( $card['version'] ?? 0 ) . '.json"' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON file download, not HTML.
		echo wp_json_encode( $card, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		exit;
	}

	/* ---------- handler plumbing ---------- */

	/**
	 * Capability + nonce gate for every handler.
	 *
	 * @param string $action Action suffix (save|import|reset|rollback|export).
	 */
	private function authorize( string $action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to edit the rate card.', 'cybertech-estimator' ), 403 );
		}
		check_admin_referer( self::NONCE . '_' . $action );
	}

	/**
	 * The `rate_card[...]` tree from the form. Deep sanitisation happens in
	 * RateCardSanitizer; this only unslashes.
	 *
	 * @return array<string, mixed>
	 */
	private function posted_card(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked in authorize(); sanitised field-by-field by RateCardSanitizer.
		$raw = isset( $_POST['rate_card'] ) && is_array( $_POST['rate_card'] ) ? wp_unslash( $_POST['rate_card'] ) : [];
		return is_array( $raw ) ? $raw : [];
	}

	/**
	 * Validate + save; keep the draft so the form re-renders with the
	 * rejected values instead of the last good card.
	 *
	 * @param array<string, mixed> $card    Sanitised card.
	 * @param string               $success Success message with a %d placeholder for the new version.
	 */
	private function persist( array $card, string $success ): void {
		$errors = $this->repo->save( $card );
		if ( $errors ) {
			$this->fail( __( 'The rate card was not saved. Fix the problems below and try again.', 'cybertech-estimator' ), $errors, $card );
		}
		$this->finish( [], $success );
	}

	/**
	 * Redirect back with a success message (or the repository's errors).
	 *
	 * @param array<int, string> $errors  Errors from the repository.
	 * @param string             $success Success message; its last %d placeholder is the new version.
	 * @param array<int, mixed>  $args    Earlier placeholders.
	 */
	private function finish( array $errors, string $success, array $args = [] ): void {
		if ( $errors ) {
			$this->fail( __( 'The operation failed.', 'cybertech-estimator' ), $errors );
		}
		$args[] = $this->repo->load()->version();
		$this->redirect(
			[
				'type'    => 'success',
				'message' => vsprintf( $success, $args ),
			]
		);
	}

	/**
	 * Redirect back with an error notice (never returns).
	 *
	 * @param string                    $message Headline.
	 * @param array<int, string>        $errors  Detail lines.
	 * @param array<string, mixed>|null $draft   Unsaved card to re-render.
	 */
	private function fail( string $message, array $errors = [], ?array $draft = null ): void {
		$this->redirect(
			[
				'type'    => 'error',
				'message' => $message,
				'errors'  => $errors,
				'draft'   => $draft,
			]
		);
	}

	/**
	 * Stash the flash for this user and go back to the page (never returns).
	 *
	 * @param array<string, mixed> $flash Flash payload.
	 */
	private function redirect( array $flash ): void {
		set_transient( self::FLASH_KEY . get_current_user_id(), $flash, 2 * MINUTE_IN_SECONDS );
		wp_safe_redirect( self::page_url() );
		exit;
	}

	/**
	 * Read (once) and clear the flash.
	 *
	 * @return array<string, mixed>|null
	 */
	private function flash(): ?array {
		if ( false === $this->flash ) {
			$key         = self::FLASH_KEY . get_current_user_id();
			$flash       = get_transient( $key );
			$this->flash = is_array( $flash ) ? $flash : null;
			delete_transient( $key );
		}
		return $this->flash;
	}

	/**
	 * URL of this page.
	 */
	private static function page_url(): string {
		return admin_url( self::PARENT . '&page=' . self::SLUG );
	}

	/**
	 * Cache-busting asset version: plugin version + mtime, so edits show up
	 * without bumping CT_EST_VERSION during development.
	 *
	 * @param string $rel Path relative to the plugin root.
	 */
	private static function asset_version( string $rel ): string {
		$mtime = file_exists( CT_EST_DIR . $rel ) ? (string) filemtime( CT_EST_DIR . $rel ) : '0';
		return CT_EST_VERSION . '.' . $mtime;
	}

	/* ---------- sample project + effects ---------- */

	/**
	 * Fixed sample answers per service line. The web one is the headline
	 * "sample project"; the others exist so mobile/design/AI factor rows
	 * have a realistic baseline to be applied to.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function samples(): array {
		return [
			'web'    => [
				'service_line'     => 'web',
				'web_platform'     => 'wordpress',
				'web_ecommerce'    => 'woocommerce',
				'web_templates'    => 8,
				'web_multilingual' => 'yes',
				'web_integrations' => 2,
				'web_migration'    => 'no',
				'urgency'          => 'normal',
				'budget'           => 'undisclosed',
				'maintenance'      => 'no',
				'hosting'          => 'client',
			],
			'mobile' => [
				'service_line'     => 'mobile',
				'mobile_framework' => 'flutter',
				'mobile_platforms' => 'both',
				'mobile_offline'   => 'no',
				'mobile_auth'      => 'yes',
				'mobile_payments'  => 'no',
				'mobile_push'      => 'yes',
				'mobile_backend'   => 'existing',
				'urgency'          => 'normal',
				'budget'           => 'undisclosed',
				'maintenance'      => 'no',
				'hosting'          => 'client',
			],
			'design' => [
				'service_line'          => 'design',
				'design_deliverables'   => [ 'wireframes', 'hifi' ],
				'design_screens'        => 10,
				'design_brand'          => 'no',
				'design_testing_rounds' => 0,
				'urgency'               => 'normal',
				'budget'                => 'undisclosed',
				'maintenance'           => 'no',
				'hosting'               => 'client',
			],
			'ai'     => [
				'service_line' => 'ai',
				'ai_workflows' => 2,
				'ai_provider'  => 'openai',
				'ai_voice'     => 'no',
				'ai_systems'   => 1,
				'ai_data'      => 'small',
				'ai_hitl'      => 'no',
				'urgency'      => 'normal',
				'budget'       => 'undisclosed',
				'maintenance'  => 'no',
				'hosting'      => 'client',
			],
		];
	}

	/**
	 * Which answer switches a factor on, derived from the questionnaire so
	 * the two can never drift apart: factor id => {type, question, value}.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function factor_triggers(): array {
		$out = [];
		foreach ( Questionnaire::questions() as $qid => $q ) {
			if ( Questionnaire::TYPE_NUMBER === $q['type'] ) {
				foreach ( (array) ( $q['factors'] ?? [] ) as $factor ) {
					$out[ $factor ] = [
						'type'     => 'number',
						'question' => $qid,
					];
				}
				continue;
			}
			foreach ( (array) ( $q['options'] ?? [] ) as $value => $option ) {
				foreach ( (array) ( $option['factors'] ?? [] ) as $factor ) {
					$out[ $factor ] = [
						'type'     => $q['type'],
						'question' => $qid,
						'value'    => $value,
					];
				}
			}
		}
		return $out;
	}

	/**
	 * Per factor row: the branch sample with that factor's trigger applied.
	 * Number-driven factors that the sample leaves at 0 get 1 unit so the
	 * row shows the per-unit effect instead of "= sample".
	 *
	 * @param array<string, mixed> $card Card data (may be an unsaved draft).
	 * @return array<string, array{line: string, answers: array<string, mixed>}>
	 */
	private static function effect_rows( array $card ): array {
		$samples  = self::samples();
		$triggers = self::factor_triggers();
		$rows     = [];
		foreach ( (array) ( $card['factors'] ?? [] ) as $id => $factor ) {
			$applies = array_values( (array) ( $factor['applies_to'] ?? [] ) );
			$line    = isset( $applies[0], $samples[ $applies[0] ] ) ? (string) $applies[0] : 'web';
			$answers = $samples[ $line ];
			$t       = $triggers[ $id ] ?? null;
			if ( $t ) {
				$qid = (string) $t['question'];
				if ( 'number' === $t['type'] ) {
					$current         = (int) ( $answers[ $qid ] ?? 0 );
					$answers[ $qid ] = $current > 0 ? $current : 1;
				} elseif ( Questionnaire::TYPE_MULTI === $t['type'] ) {
					$values = (array) ( $answers[ $qid ] ?? [] );
					if ( ! in_array( $t['value'], $values, true ) ) {
						$values[] = $t['value'];
					}
					$answers[ $qid ] = $values;
				} else {
					$answers[ $qid ] = $t['value'];
				}
			}
			$rows[ (string) $id ] = [
				'line'    => $line,
				'answers' => $answers,
			];
		}//end foreach
		return $rows;
	}

	/**
	 * Run the engine for one answer set. Null when the card or the answers
	 * cannot be priced (the page still renders; the cell shows a dash).
	 *
	 * @param RateCard|null        $card    Card, or null when the draft is invalid.
	 * @param array<string, mixed> $answers Sample answers.
	 * @return array<string, mixed>|null
	 */
	private static function estimate( ?RateCard $card, array $answers ): ?array {
		if ( ! $card ) {
			return null;
		}
		try {
			$r = ( new PricingEngine( $card, SandboxController::normalise_answers( $answers ) ) )->estimate();
		} catch ( \InvalidArgumentException ) {
			return null;
		}
		return [
			'hours'    => $r->hours,
			'low'      => $r->price_low,
			'high'     => $r->price_high,
			'weeks'    => $r->weeks,
			'currency' => $r->currency,
		];
	}

	/**
	 * JS config: REST access, the samples, per-row answers, the saved card
	 * and the history (for client-side diffs), plus translated strings.
	 *
	 * @return array<string, mixed>
	 */
	private function config(): array {
		$saved   = $this->repo->raw();
		$history = [];
		foreach ( $this->repo->history() as $entry ) {
			$history[] = [
				'version' => (int) ( $entry['version'] ?? 0 ),
				'card'    => (array) ( $entry['card'] ?? [] ),
			];
		}
		return [
			'restUrl' => rest_url( SandboxController::NAMESPACE . '/sandbox/estimate' ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'locale'  => str_replace( '_', '-', get_locale() ),
			'formId'  => self::FORM_ID,
			'samples' => self::samples(),
			'rows'    => self::effect_rows( $saved ),
			'current' => $saved,
			'history' => $history,
			'i18n'    => [
				'busy'      => __( 'Recalculating…', 'cybertech-estimator' ),
				'ok'        => __( 'Effects reflect your unsaved changes.', 'cybertech-estimator' ),
				'error'     => __( 'Cannot price the card as edited:', 'cybertech-estimator' ),
				'network'   => __( 'The estimate request failed. Check your connection and try again.', 'cybertech-estimator' ),
				'sample'    => __( '= sample', 'cybertech-estimator' ),
				'identical' => __( 'Identical to the current card.', 'cybertech-estimator' ),
				/* translators: %d: number of changed values */
				'changed'   => __( '%d changed value(s) vs the current card:', 'cybertech-estimator' ),
				'hours'     => _x( 'h', 'hours abbreviation', 'cybertech-estimator' ),
				'weeks'     => _x( 'wk', 'weeks abbreviation', 'cybertech-estimator' ),
			],
		];
	}

	/* ---------- rendering ---------- */

	/**
	 * The page.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to edit the rate card.', 'cybertech-estimator' ), 403 );
		}
		$saved = $this->repo->raw();
		$flash = $this->flash();
		$card  = is_array( $flash['draft'] ?? null ) ? $flash['draft'] : $saved;
		$valid = ! RateCard::validate( $card ) ? new RateCard( $card ) : null;
		$lines = (array) ( $card['service_lines'] ?? [] );

		$baselines = [];
		foreach ( self::samples() as $line => $answers ) {
			$baselines[ $line ] = self::estimate( $valid, $answers );
		}
		?>
		<div class="wrap ct-rc">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Rate card', 'cybertech-estimator' ); ?></h1>
			<span class="ct-rc-version" title="<?php esc_attr_e( 'Current saved version', 'cybertech-estimator' ); ?>">v<?php echo (int) ( $saved['version'] ?? 0 ); ?></span>
			<?php if ( $card !== $saved ) : ?>
				<span class="ct-rc-badge ct-rc-badge--draft"><?php esc_html_e( 'Showing your unsaved draft', 'cybertech-estimator' ); ?></span>
			<?php endif; ?>
			<hr class="wp-header-end">

			<div class="ct-rc-layout">
				<main class="ct-rc-main">
					<form id="<?php echo esc_attr( self::FORM_ID ); ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ct-rc-form" novalidate>
						<input type="hidden" name="action" value="ct_est_rate_card_save">
						<?php wp_nonce_field( self::NONCE . '_save' ); ?>

						<?php $this->render_stats( $lines, $baselines ); ?>
						<?php $this->render_general( $card ); ?>
						<?php $this->render_roles( $card ); ?>
						<?php $this->render_service_lines( $lines ); ?>
						<?php $this->render_factors( $card, $lines, $valid, $baselines ); ?>
						<?php $this->render_urgency( $card ); ?>
						<?php $this->render_team_bands( $card, $lines ); ?>
						<?php $this->render_reveal_bands( $card ); ?>
						<?php $this->render_budget_bands( $card ); ?>
						<?php $this->render_qualification( $card ); ?>

						<p class="submit">
							<button type="submit" class="button button-primary button-large"><?php esc_html_e( 'Save rate card', 'cybertech-estimator' ); ?></button>
						</p>
					</form>
				</main>

				<aside class="ct-rc-side">
					<?php $this->render_actions( $saved ); ?>
					<?php $this->render_nav(); ?>
					<?php $this->render_history(); ?>
				</aside>
			</div>
		</div>
		<?php
	}

	/**
	 * Header stat block: the sample project per service line.
	 *
	 * @param array<string, mixed>                     $lines     Service lines.
	 * @param array<string, array<string, mixed>|null> $baselines Estimates per line.
	 */
	private function render_stats( array $lines, array $baselines ): void {
		?>
		<section class="ct-rc-stats" aria-label="<?php esc_attr_e( 'Sample projects', 'cybertech-estimator' ); ?>">
			<?php foreach ( self::samples() as $line => $answers ) : ?>
				<?php $e = $baselines[ $line ] ?? null; ?>
				<div class="ct-rc-stat<?php echo 'web' === $line ? ' ct-rc-stat--primary' : ''; ?>" data-stat="<?php echo esc_attr( $line ); ?>">
					<h3 class="ct-rc-stat__title">
						<?php echo esc_html( (string) ( $lines[ $line ]['label'] ?? $line ) ); ?>
						<small><?php echo 'web' === $line ? esc_html__( 'sample project', 'cybertech-estimator' ) : esc_html__( 'branch sample', 'cybertech-estimator' ); ?></small>
					</h3>
					<p class="ct-rc-stat__range" data-stat-range><?php echo $e ? esc_html( Money::range( (float) $e['low'], (float) $e['high'], (string) $e['currency'] ) ) : '—'; ?></p>
					<p class="ct-rc-stat__meta">
						<span data-stat-hours><?php echo $e ? esc_html( self::hours( (float) $e['hours'] ) ) : '—'; ?></span>
						<span data-stat-weeks><?php echo $e ? esc_html( self::weeks( (int) $e['weeks'] ) ) : '—'; ?></span>
					</p>
					<p class="ct-rc-stat__answers"><?php echo esc_html( self::describe_sample( $answers ) ); ?></p>
				</div>
			<?php endforeach; ?>
			<p class="ct-rc-live" role="status" aria-live="polite" data-live-status></p>
		</section>
		<?php
	}

	/**
	 * General coefficients.
	 *
	 * @param array<string, mixed> $card Card.
	 */
	private function render_general( array $card ): void {
		$this->section_open( 'general', __( 'General', 'cybertech-estimator' ) );
		?>
		<table class="form-table" role="presentation">
			<tbody>
				<?php $this->row_open( 'currency', __( 'Currency', 'cybertech-estimator' ) ); ?>
					<?php
					$this->text(
						'currency',
						(string) ( $card['currency'] ?? '' ),
						[
							'maxlength' => 3,
							'size'      => 4,
							'class'     => 'ct-rc-input--code',
						]
					);
					?>
					<p class="description"><?php esc_html_e( 'ISO code (EUR, RON, USD). Prices are formatted with the symbol when one is known.', 'cybertech-estimator' ); ?></p>
				<?php $this->row_close(); ?>

				<?php $this->row_open( 'blended_rate', __( 'Blended hourly rate', 'cybertech-estimator' ) ); ?>
					<?php $this->number( 'blended_rate', $card['blended_rate'] ?? null, [ 'min' => 0 ] ); ?>
					<p class="description"><?php esc_html_e( 'Fallback when a role has no rate of its own.', 'cybertech-estimator' ); ?></p>
				<?php $this->row_close(); ?>

				<?php $this->row_open( 'contingency', __( 'Contingency', 'cybertech-estimator' ) ); ?>
					<?php
					$this->number(
						'contingency',
						$card['contingency'] ?? null,
						[
							'min'  => 0,
							'step' => 0.01,
						]
					);
					?>
					<p class="description"><?php esc_html_e( 'Fraction added to hours after urgency (0.10 = +10%).', 'cybertech-estimator' ); ?></p>
				<?php $this->row_close(); ?>

				<?php $this->row_open( 'range_spread', __( 'Range spread', 'cybertech-estimator' ) ); ?>
					<?php
					$this->number(
						'range_spread',
						$card['range_spread'] ?? null,
						[
							'min'  => 0,
							'max'  => 0.99,
							'step' => 0.01,
						]
					);
					?>
					<p class="description"><?php esc_html_e( 'Low/high = price × (1 ∓ spread). Must be below 1.', 'cybertech-estimator' ); ?></p>
				<?php $this->row_close(); ?>

				<?php $this->row_open( 'rounding.threshold', __( 'Rounding', 'cybertech-estimator' ) ); ?>
					<span class="ct-rc-inline">
						<label for="<?php echo esc_attr( self::field_id( 'rounding.threshold' ) ); ?>"><?php esc_html_e( 'Threshold', 'cybertech-estimator' ); ?></label>
						<?php $this->number( 'rounding.threshold', $card['rounding']['threshold'] ?? null, [ 'min' => 0 ] ); ?>
						<label for="<?php echo esc_attr( self::field_id( 'rounding.below' ) ); ?>"><?php esc_html_e( 'Increment below', 'cybertech-estimator' ); ?></label>
						<?php $this->number( 'rounding.below', $card['rounding']['below'] ?? null, [ 'min' => 0 ] ); ?>
						<label for="<?php echo esc_attr( self::field_id( 'rounding.above' ) ); ?>"><?php esc_html_e( 'Increment from threshold', 'cybertech-estimator' ); ?></label>
						<?php $this->number( 'rounding.above', $card['rounding']['above'] ?? null, [ 'min' => 0 ] ); ?>
					</span>
					<p class="description"><?php esc_html_e( 'Range ends are rounded to the nearest increment.', 'cybertech-estimator' ); ?></p>
				<?php $this->row_close(); ?>

				<?php $this->row_open( 'weekly_capacity', __( 'Weekly capacity (hours)', 'cybertech-estimator' ) ); ?>
					<?php $this->number( 'weekly_capacity', $card['weekly_capacity'] ?? null, [ 'min' => 0 ] ); ?>
					<p class="description"><?php esc_html_e( 'Team hours delivered per week; drives the duration.', 'cybertech-estimator' ); ?></p>
				<?php $this->row_close(); ?>

				<?php $this->row_open( 'min_weeks', __( 'Minimum weeks', 'cybertech-estimator' ) ); ?>
					<?php
					$this->number(
						'min_weeks',
						$card['min_weeks'] ?? null,
						[
							'min'  => 0,
							'step' => 1,
						]
					);
					?>
				<?php $this->row_close(); ?>
			</tbody>
		</table>
		<?php
		$this->section_close();
	}

	/**
	 * Role rates.
	 *
	 * @param array<string, mixed> $card Card.
	 */
	private function render_roles( array $card ): void {
		$labels   = RateCardDefaults::role_labels();
		$currency = (string) ( $card['currency'] ?? '' );
		$this->section_open( 'roles', __( 'Role rates', 'cybertech-estimator' ), __( 'Hourly rate per role. The effective project rate is the share-weighted mix from the team band.', 'cybertech-estimator' ) );
		?>
		<table class="widefat striped ct-rc-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Role', 'cybertech-estimator' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Id', 'cybertech-estimator' ); ?></th>
					<th scope="col">
						<?php
						/* translators: %s: currency code */
						echo esc_html( sprintf( __( 'Rate (%s/h)', 'cybertech-estimator' ), $currency ) );
						?>
					</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( (array) ( $card['role_rates'] ?? [] ) as $role => $rate ) : ?>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( self::field_id( "role_rates.{$role}" ) ); ?>"><?php echo esc_html( (string) ( $labels[ $role ] ?? $role ) ); ?></label></th>
						<td><code><?php echo esc_html( (string) $role ); ?></code></td>
						<td><?php $this->number( "role_rates.{$role}", $rate, [ 'min' => 0 ] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$this->section_close();
	}

	/**
	 * Service lines.
	 *
	 * @param array<string, mixed> $lines Service lines.
	 */
	private function render_service_lines( array $lines ): void {
		$this->section_open( 'service-lines', __( 'Service lines', 'cybertech-estimator' ), __( 'Base hours start every estimate in the line; min hours is the floor after contingency.', 'cybertech-estimator' ) );
		?>
		<table class="widefat striped ct-rc-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Id', 'cybertech-estimator' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Label', 'cybertech-estimator' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Base hours', 'cybertech-estimator' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Min hours', 'cybertech-estimator' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $lines as $id => $line ) : ?>
					<tr>
						<th scope="row"><code><?php echo esc_html( (string) $id ); ?></code></th>
						<td><?php $this->text( "service_lines.{$id}.label", (string) ( $line['label'] ?? '' ), [ 'class' => 'regular-text' ] ); ?></td>
						<td><?php $this->number( "service_lines.{$id}.base_hours", $line['base_hours'] ?? null, [ 'min' => 0 ] ); ?></td>
						<td><?php $this->number( "service_lines.{$id}.min_hours", $line['min_hours'] ?? null, [ 'min' => 0 ] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$this->section_close();
	}

	/**
	 * Factors, grouped by their first `applies_to` line, each with the live
	 * effect column.
	 *
	 * @param array<string, mixed>                     $card      Card.
	 * @param array<string, mixed>                     $lines     Service lines.
	 * @param RateCard|null                            $valid     Card object when the draft validates.
	 * @param array<string, array<string, mixed>|null> $baselines Sample estimates per line.
	 */
	private function render_factors( array $card, array $lines, ?RateCard $valid, array $baselines ): void {
		$rows   = self::effect_rows( $card );
		$groups = [];
		foreach ( (array) ( $card['factors'] ?? [] ) as $id => $factor ) {
			$groups[ $rows[ $id ]['line'] ][ $id ] = $factor;
		}
		$types = [
			'add_hours'  => __( 'Add hours', 'cybertech-estimator' ),
			'multiplier' => __( 'Multiplier', 'cybertech-estimator' ),
			'add_price'  => __( 'Add price', 'cybertech-estimator' ),
		];

		$this->section_open( 'factors', __( 'Factors', 'cybertech-estimator' ), __( 'Applied in order: add-hours factors first, then multipliers, then urgency and contingency; add-price factors after the hourly price. Lower order runs first. Per-unit factors multiply the value by the numeric answer.', 'cybertech-estimator' ) );
		?>
		<table class="widefat ct-rc-table ct-rc-factors">
			<thead>
				<tr>
					<th scope="col" class="ct-rc-col-factor"><?php esc_html_e( 'Factor', 'cybertech-estimator' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Type', 'cybertech-estimator' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Value', 'cybertech-estimator' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Per unit', 'cybertech-estimator' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Order', 'cybertech-estimator' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Applies to', 'cybertech-estimator' ); ?></th>
					<th scope="col" class="ct-rc-col-effect"><?php esc_html_e( 'Effect on the sample project', 'cybertech-estimator' ); ?></th>
				</tr>
			</thead>
			<?php foreach ( $groups as $line => $factors ) : ?>
				<tbody class="ct-rc-group">
					<tr class="ct-rc-group__head">
						<th colspan="7" scope="rowgroup"><?php echo esc_html( (string) ( $lines[ $line ]['label'] ?? $line ) ); ?></th>
					</tr>
					<?php foreach ( $factors as $id => $factor ) : ?>
						<?php $base = "factors.{$id}"; ?>
						<tr>
							<td class="ct-rc-col-factor">
								<?php
								$this->text(
									"{$base}.label",
									(string) ( $factor['label'] ?? '' ),
									[
										'class'      => 'ct-rc-input--label',
										'aria-label' => __( 'Label', 'cybertech-estimator' ),
									]
								);
								?>
								<code class="ct-rc-id"><?php echo esc_html( (string) $id ); ?></code>
								<?php
								$this->text(
									"{$base}.note",
									(string) ( $factor['note'] ?? '' ),
									[
										'class'       => 'ct-rc-input--note',
										'placeholder' => __( 'Note (why this costs what it costs)', 'cybertech-estimator' ),
										'aria-label'  => __( 'Note', 'cybertech-estimator' ),
									]
								);
								?>
							</td>
							<td><?php $this->select( "{$base}.type", (string) ( $factor['type'] ?? '' ), $types ); ?></td>
							<td>
								<?php
								$this->number(
									"{$base}.value",
									$factor['value'] ?? null,
									[
										'step' => 'any',
										'min'  => 0,
									]
								);
								?>
							</td>
							<td class="ct-rc-center"><?php $this->checkbox( "{$base}.per_unit", ! empty( $factor['per_unit'] ), __( 'Per unit', 'cybertech-estimator' ) ); ?></td>
							<td>
								<?php
								$this->number(
									"{$base}.order",
									$factor['order'] ?? null,
									[
										'step'  => 1,
										'class' => 'ct-rc-input--short',
									]
								);
								?>
							</td>
							<td class="ct-rc-applies">
								<?php foreach ( array_keys( $lines ) as $line_id ) : ?>
									<?php $this->checkbox( "{$base}.applies_to.{$line_id}", in_array( $line_id, (array) ( $factor['applies_to'] ?? [] ), true ), (string) $line_id, "{$base}.applies_to", (string) $line_id ); ?>
								<?php endforeach; ?>
							</td>
							<?php $this->effect_cell( (string) $id, self::estimate( $valid, $rows[ $id ]['answers'] ), $baselines[ $rows[ $id ]['line'] ] ?? null ); ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			<?php endforeach; ?>
		</table>
		<?php
		$this->section_close();
	}

	/**
	 * Urgency multipliers.
	 *
	 * @param array<string, mixed> $card Card.
	 */
	private function render_urgency( array $card ): void {
		$labels = [];
		foreach ( (array) ( Questionnaire::questions()['urgency']['options'] ?? [] ) as $id => $option ) {
			$labels[ $id ] = (string) $option['label'];
		}
		$this->section_open( 'urgency', __( 'Urgency', 'cybertech-estimator' ), __( 'Multiplies the hours after all factors.', 'cybertech-estimator' ) );
		?>
		<table class="widefat striped ct-rc-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Timeline', 'cybertech-estimator' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Id', 'cybertech-estimator' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Multiplier', 'cybertech-estimator' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( (array) ( $card['urgency'] ?? [] ) as $id => $mult ) : ?>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( self::field_id( "urgency.{$id}" ) ); ?>"><?php echo esc_html( $labels[ $id ] ?? (string) $id ); ?></label></th>
						<td><code><?php echo esc_html( (string) $id ); ?></code></td>
						<td>
							<?php
							$this->number(
								"urgency.{$id}",
								$mult,
								[
									'step' => 0.01,
									'min'  => 0,
								]
							);
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$this->section_close();
	}

	/**
	 * Team bands per service line.
	 *
	 * @param array<string, mixed> $card  Card.
	 * @param array<string, mixed> $lines Service lines.
	 */
	private function render_team_bands( array $card, array $lines ): void {
		$labels = RateCardDefaults::role_labels();
		$roles  = array_keys( (array) ( $card['role_rates'] ?? [] ) );
		$this->section_open( 'team-bands', __( 'Team bands', 'cybertech-estimator' ), __( 'Role shares (%) by total hours. The first band whose max hours covers the estimate is used; leave max empty for the open-ended last band. Shares must sum to 100.', 'cybertech-estimator' ) );
		foreach ( (array) ( $card['team_bands'] ?? [] ) as $line => $bands ) :
			?>
			<h3 class="ct-rc-subhead"><?php echo esc_html( (string) ( $lines[ $line ]['label'] ?? $line ) ); ?></h3>
			<table class="widefat striped ct-rc-table ct-rc-bands">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Band', 'cybertech-estimator' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Max hours', 'cybertech-estimator' ); ?></th>
						<?php foreach ( $roles as $role ) : ?>
							<th scope="col"><abbr title="<?php echo esc_attr( (string) ( $labels[ $role ] ?? $role ) ); ?>"><?php echo esc_html( (string) $role ); ?></abbr></th>
						<?php endforeach; ?>
						<th scope="col"><?php esc_html_e( 'Sum', 'cybertech-estimator' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( (array) $bands as $i => $band ) : ?>
						<?php
						$base = "team_bands.{$line}.{$i}";
						$sum  = array_sum( array_map( 'floatval', (array) ( $band['roles'] ?? [] ) ) );
						?>
						<tr>
							<th scope="row"><?php echo esc_html( (string) ( (int) $i + 1 ) ); ?></th>
							<td>
								<?php
								$this->number(
									"{$base}.max_hours",
									$band['max_hours'] ?? null,
									[
										'min'         => 0,
										'placeholder' => '∞',
									]
								);
								?>
							</td>
							<?php foreach ( $roles as $role ) : ?>
								<td>
									<?php
									$this->number(
										"{$base}.roles.{$role}",
										$band['roles'][ $role ] ?? 0,
										[
											'min'        => 0,
											'max'        => 100,
											'step'       => 'any',
											'class'      => 'ct-rc-input--short',
											'data-band'  => $base,
											'aria-label' => (string) ( $labels[ $role ] ?? $role ),
										]
									);
									?>
								</td>
							<?php endforeach; ?>
							<td class="ct-rc-sum <?php echo abs( $sum - 100 ) < 0.01 ? 'is-ok' : 'is-bad'; ?>" data-sum-for="<?php echo esc_attr( $base ); ?>">
								<?php echo esc_html( self::plain( $sum ) ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php
		endforeach;
		$this->section_close();
	}

	/**
	 * Reveal bands.
	 *
	 * @param array<string, mixed> $card Card.
	 */
	private function render_reveal_bands( array $card ): void {
		$this->section_open( 'reveal-bands', __( 'Reveal bands', 'cybertech-estimator' ), __( 'In "band" reveal mode the visitor sees the first band whose max price is above the point price. Leave the last max empty.', 'cybertech-estimator' ) );
		?>
		<table class="widefat striped ct-rc-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Id', 'cybertech-estimator' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Label', 'cybertech-estimator' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Max price', 'cybertech-estimator' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( (array) ( $card['reveal_bands'] ?? [] ) as $i => $band ) : ?>
					<?php $base = "reveal_bands.{$i}"; ?>
					<tr>
						<th scope="row">
							<code><?php echo esc_html( (string) ( $band['id'] ?? '' ) ); ?></code>
							<input type="hidden" name="<?php echo esc_attr( self::field_name( "{$base}.id" ) ); ?>" value="<?php echo esc_attr( (string) ( $band['id'] ?? '' ) ); ?>">
						</th>
						<td><?php $this->text( "{$base}.label", (string) ( $band['label'] ?? '' ), [ 'class' => 'regular-text' ] ); ?></td>
						<td>
							<?php
							$this->number(
								"{$base}.max_price",
								$band['max_price'] ?? null,
								[
									'min'         => 0,
									'placeholder' => '∞',
								]
							);
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$this->section_close();
	}

	/**
	 * Budget bands (qualification only).
	 *
	 * @param array<string, mixed> $card Card.
	 */
	private function render_budget_bands( array $card ): void {
		$labels = [];
		foreach ( (array) ( Questionnaire::questions()['budget']['options'] ?? [] ) as $id => $option ) {
			$labels[ $id ] = (string) $option['label'];
		}
		$this->section_open( 'budget-bands', __( 'Budget bands', 'cybertech-estimator' ), __( 'What each budget answer means in money, for the qualification score. Empty = unbounded / undisclosed.', 'cybertech-estimator' ) );
		?>
		<table class="widefat striped ct-rc-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Answer', 'cybertech-estimator' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Id', 'cybertech-estimator' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Min', 'cybertech-estimator' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Max', 'cybertech-estimator' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( (array) ( $card['budget_bands'] ?? [] ) as $id => $band ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $labels[ $id ] ?? (string) $id ); ?></th>
						<td><code><?php echo esc_html( (string) $id ); ?></code></td>
						<td><?php $this->number( "budget_bands.{$id}.min", $band['min'] ?? null, [ 'min' => 0 ] ); ?></td>
						<td><?php $this->number( "budget_bands.{$id}.max", $band['max'] ?? null, [ 'min' => 0 ] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$this->section_close();
	}

	/**
	 * Qualification weights.
	 *
	 * @param array<string, mixed> $card Card.
	 */
	private function render_qualification( array $card ): void {
		$q       = (array) ( $card['qualification'] ?? [] );
		$budget  = [
			'covers_high'       => __( 'Budget covers the high end', 'cybertech-estimator' ),
			'overlaps'          => __( 'Budget overlaps the range', 'cybertech-estimator' ),
			'below_within_half' => __( 'Below the low end by less than half', 'cybertech-estimator' ),
			'far_below'         => __( 'Far below', 'cybertech-estimator' ),
			'undisclosed'       => __( 'Undisclosed', 'cybertech-estimator' ),
		];
		$urgency = [];
		foreach ( (array) ( Questionnaire::questions()['urgency']['options'] ?? [] ) as $id => $option ) {
			$urgency[ $id ] = (string) $option['label'];
		}
		$this->section_open( 'qualification', __( 'Qualification weights', 'cybertech-estimator' ), __( 'Points per signal; the lead score is their sum, capped at 100. Admin-only, never shown to visitors.', 'cybertech-estimator' ) );
		?>
		<div class="ct-rc-grid">
			<table class="widefat striped ct-rc-table">
				<thead><tr><th scope="col" colspan="2"><?php esc_html_e( 'Budget fit', 'cybertech-estimator' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( (array) ( $q['budget'] ?? [] ) as $key => $points ) : ?>
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( self::field_id( "qualification.budget.{$key}" ) ); ?>"><?php echo esc_html( $budget[ $key ] ?? (string) $key ); ?></label></th>
							<td><?php $this->number( "qualification.budget.{$key}", $points, [ 'step' => 1 ] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<table class="widefat striped ct-rc-table">
				<thead><tr><th scope="col" colspan="2"><?php esc_html_e( 'Urgency signal', 'cybertech-estimator' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( (array) ( $q['urgency'] ?? [] ) as $key => $points ) : ?>
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( self::field_id( "qualification.urgency.{$key}" ) ); ?>"><?php echo esc_html( $urgency[ $key ] ?? (string) $key ); ?></label></th>
							<td><?php $this->number( "qualification.urgency.{$key}", $points, [ 'step' => 1 ] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<table class="widefat striped ct-rc-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Scope: up to hours', 'cybertech-estimator' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Points', 'cybertech-estimator' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( (array) ( $q['scope'] ?? [] ) as $i => $row ) : ?>
						<tr>
							<td>
								<?php
								$this->number(
									"qualification.scope.{$i}.max_hours",
									$row['max_hours'] ?? null,
									[
										'min'         => 0,
										'placeholder' => '∞',
									]
								);
								?>
							</td>
							<td><?php $this->number( "qualification.scope.{$i}.points", $row['points'] ?? null, [ 'step' => 1 ] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<table class="widefat striped ct-rc-table">
				<thead><tr><th scope="col" colspan="2"><?php esc_html_e( 'Other signals', 'cybertech-estimator' ); ?></th></tr></thead>
				<tbody>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( self::field_id( 'qualification.notes.min_chars' ) ); ?>"><?php esc_html_e( 'Description: minimum characters', 'cybertech-estimator' ); ?></label></th>
						<td>
							<?php
							$this->number(
								'qualification.notes.min_chars',
								$q['notes']['min_chars'] ?? null,
								[
									'step' => 1,
									'min'  => 0,
								]
							);
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( self::field_id( 'qualification.notes.points' ) ); ?>"><?php esc_html_e( 'Description: points', 'cybertech-estimator' ); ?></label></th>
						<td><?php $this->number( 'qualification.notes.points', $q['notes']['points'] ?? null, [ 'step' => 1 ] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( self::field_id( 'qualification.maintenance.points' ) ); ?>"><?php esc_html_e( 'Maintenance interest: points', 'cybertech-estimator' ); ?></label></th>
						<td><?php $this->number( 'qualification.maintenance.points', $q['maintenance']['points'] ?? null, [ 'step' => 1 ] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( self::field_id( 'qualification.hosting.points' ) ); ?>"><?php esc_html_e( 'Hosting with us: points', 'cybertech-estimator' ); ?></label></th>
						<td><?php $this->number( 'qualification.hosting.points', $q['hosting']['points'] ?? null, [ 'step' => 1 ] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( self::field_id( 'qualification.thresholds.green' ) ); ?>"><?php esc_html_e( 'Green from', 'cybertech-estimator' ); ?></label></th>
						<td>
							<?php
							$this->number(
								'qualification.thresholds.green',
								$q['thresholds']['green'] ?? null,
								[
									'step' => 1,
									'min'  => 0,
									'max'  => 100,
								]
							);
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( self::field_id( 'qualification.thresholds.amber' ) ); ?>"><?php esc_html_e( 'Amber from', 'cybertech-estimator' ); ?></label></th>
						<td>
							<?php
							$this->number(
								'qualification.thresholds.amber',
								$q['thresholds']['amber'] ?? null,
								[
									'step' => 1,
									'min'  => 0,
									'max'  => 100,
								]
							);
							?>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
		$this->section_close();
	}

	/**
	 * Sidebar: save / export / import / reset.
	 *
	 * @param array<string, mixed> $saved The saved card.
	 */
	private function render_actions( array $saved ): void {
		$export_url = wp_nonce_url( admin_url( 'admin-post.php?action=ct_est_rate_card_export' ), self::NONCE . '_export' );
		?>
		<div class="ct-rc-card">
			<h2><?php esc_html_e( 'Actions', 'cybertech-estimator' ); ?></h2>
			<p>
				<button type="submit" form="<?php echo esc_attr( self::FORM_ID ); ?>" class="button button-primary button-hero ct-rc-save"><?php esc_html_e( 'Save rate card', 'cybertech-estimator' ); ?></button>
			</p>
			<p class="description"><?php esc_html_e( 'Saving creates a new version; older leads keep the card they were priced with.', 'cybertech-estimator' ); ?></p>

			<p>
				<a class="button" href="<?php echo esc_url( $export_url ); ?>">
					<?php
					/* translators: %d: version number */
					echo esc_html( sprintf( __( 'Export JSON (v%d)', 'cybertech-estimator' ), (int) ( $saved['version'] ?? 0 ) ) );
					?>
				</a>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="ct-rc-import">
				<input type="hidden" name="action" value="ct_est_rate_card_import">
				<?php wp_nonce_field( self::NONCE . '_import' ); ?>
				<label for="ct-rc-import-file" class="ct-rc-label"><?php esc_html_e( 'Import JSON', 'cybertech-estimator' ); ?></label>
				<input type="file" id="ct-rc-import-file" name="rate_card_file" accept=".json,application/json" required>
				<button type="submit" class="button"><?php esc_html_e( 'Import and save', 'cybertech-estimator' ); ?></button>
				<p class="description"><?php esc_html_e( 'The file is validated before it replaces the current card.', 'cybertech-estimator' ); ?></p>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ct-rc-reset" data-confirm="<?php esc_attr_e( 'Reset every coefficient to the shipped defaults? The current card stays in the history.', 'cybertech-estimator' ); ?>">
				<input type="hidden" name="action" value="ct_est_rate_card_reset">
				<?php wp_nonce_field( self::NONCE . '_reset' ); ?>
				<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Reset to defaults', 'cybertech-estimator' ); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * Sidebar: jump links.
	 */
	private function render_nav(): void {
		$items = [
			'general'       => __( 'General', 'cybertech-estimator' ),
			'roles'         => __( 'Role rates', 'cybertech-estimator' ),
			'service-lines' => __( 'Service lines', 'cybertech-estimator' ),
			'factors'       => __( 'Factors', 'cybertech-estimator' ),
			'urgency'       => __( 'Urgency', 'cybertech-estimator' ),
			'team-bands'    => __( 'Team bands', 'cybertech-estimator' ),
			'reveal-bands'  => __( 'Reveal bands', 'cybertech-estimator' ),
			'budget-bands'  => __( 'Budget bands', 'cybertech-estimator' ),
			'qualification' => __( 'Qualification', 'cybertech-estimator' ),
		];
		?>
		<nav class="ct-rc-card ct-rc-nav" aria-label="<?php esc_attr_e( 'Sections', 'cybertech-estimator' ); ?>">
			<h2><?php esc_html_e( 'Sections', 'cybertech-estimator' ); ?></h2>
			<ul>
				<?php foreach ( $items as $anchor => $label ) : ?>
					<li><a href="#ct-rc-<?php echo esc_attr( $anchor ); ?>"><?php echo esc_html( $label ); ?></a></li>
				<?php endforeach; ?>
			</ul>
		</nav>
		<?php
	}

	/**
	 * Sidebar: version history with diff + rollback.
	 */
	private function render_history(): void {
		$history = $this->repo->history();
		?>
		<div class="ct-rc-card ct-rc-history">
			<h2><?php esc_html_e( 'Version history', 'cybertech-estimator' ); ?></h2>
			<?php if ( ! $history ) : ?>
				<p class="description"><?php esc_html_e( 'No earlier versions yet. The first save will record the current card here.', 'cybertech-estimator' ); ?></p>
			<?php else : ?>
				<ol class="ct-rc-history__list">
					<?php foreach ( $history as $entry ) : ?>
						<?php
						$version = (int) ( $entry['version'] ?? 0 );
						$user    = get_userdata( (int) ( $entry['user_id'] ?? 0 ) );
						$when    = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) ( $entry['saved_at'] ?? 0 ) );
						/* translators: %d: version number */
						$confirm = sprintf( __( 'Roll back to v%d? It will be saved as a new version.', 'cybertech-estimator' ), $version );
						?>
						<li class="ct-rc-history__item">
							<div class="ct-rc-history__meta">
								<strong>v<?php echo esc_html( (string) $version ); ?></strong>
								<span><?php echo esc_html( (string) $when ); ?></span>
								<span><?php echo esc_html( $user ? $user->display_name : __( 'unknown user', 'cybertech-estimator' ) ); ?></span>
							</div>
							<div class="ct-rc-history__actions">
								<button type="button" class="button button-small" data-diff="<?php echo esc_attr( (string) $version ); ?>" aria-expanded="false" aria-controls="ct-rc-diff-<?php echo esc_attr( (string) $version ); ?>"><?php esc_html_e( 'Diff vs current', 'cybertech-estimator' ); ?></button>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ct-rc-rollback" data-confirm="<?php echo esc_attr( $confirm ); ?>">
									<input type="hidden" name="action" value="ct_est_rate_card_rollback">
									<input type="hidden" name="version" value="<?php echo esc_attr( (string) $version ); ?>">
									<?php wp_nonce_field( self::NONCE . '_rollback' ); ?>
									<button type="submit" class="button button-small"><?php esc_html_e( 'Roll back', 'cybertech-estimator' ); ?></button>
								</form>
							</div>
							<div class="ct-rc-diff" id="ct-rc-diff-<?php echo esc_attr( (string) $version ); ?>" hidden></div>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ---------- markup helpers (all echo, all escaped) ---------- */

	/**
	 * Section wrapper open.
	 *
	 * @param string $anchor Anchor id suffix.
	 * @param string $title  Heading.
	 * @param string $intro  Optional description.
	 */
	private function section_open( string $anchor, string $title, string $intro = '' ): void {
		echo '<section class="ct-rc-section" id="ct-rc-' . esc_attr( $anchor ) . '">';
		echo '<h2>' . esc_html( $title ) . '</h2>';
		if ( '' !== $intro ) {
			echo '<p class="description">' . esc_html( $intro ) . '</p>';
		}
	}

	/**
	 * Section wrapper close.
	 */
	private function section_close(): void {
		echo '</section>';
	}

	/**
	 * `.form-table` row open; the label targets the field id.
	 *
	 * @param string $path  Dot path of the first field in the row.
	 * @param string $label Label.
	 */
	private function row_open( string $path, string $label ): void {
		echo '<tr><th scope="row"><label for="' . esc_attr( self::field_id( $path ) ) . '">' . esc_html( $label ) . '</label></th><td>';
	}

	/**
	 * `.form-table` row close.
	 */
	private function row_close(): void {
		echo '</td></tr>';
	}

	/**
	 * Numeric input. `step="any"` unless given, so decimals never trip HTML validation.
	 *
	 * @param string               $path  Dot path.
	 * @param mixed                $value Current value (null = empty).
	 * @param array<string, mixed> $attrs Extra attributes.
	 */
	private function number( string $path, mixed $value, array $attrs = [] ): void {
		$attrs         += [
			'step'  => 'any',
			'class' => '',
		];
		$attrs['class'] = trim( 'ct-rc-input ' . $attrs['class'] );
		echo '<input type="number" id="' . esc_attr( self::field_id( $path ) ) . '" name="' . esc_attr( self::field_name( $path ) ) . '" value="' . esc_attr( is_numeric( $value ) ? self::plain( (float) $value ) : '' ) . '"';
		$this->attrs( $attrs );
		echo '>';
	}

	/**
	 * Text input.
	 *
	 * @param string               $path  Dot path.
	 * @param string               $value Current value.
	 * @param array<string, mixed> $attrs Extra attributes.
	 */
	private function text( string $path, string $value, array $attrs = [] ): void {
		$attrs         += [ 'class' => '' ];
		$attrs['class'] = trim( 'ct-rc-input ' . $attrs['class'] );
		echo '<input type="text" id="' . esc_attr( self::field_id( $path ) ) . '" name="' . esc_attr( self::field_name( $path ) ) . '" value="' . esc_attr( $value ) . '"';
		$this->attrs( $attrs );
		echo '>';
	}

	/**
	 * Select.
	 *
	 * @param string                $path    Dot path.
	 * @param string                $value   Current value.
	 * @param array<string, string> $options value => label.
	 */
	private function select( string $path, string $value, array $options ): void {
		echo '<select id="' . esc_attr( self::field_id( $path ) ) . '" name="' . esc_attr( self::field_name( $path ) ) . '" class="ct-rc-input">';
		if ( '' !== $value && ! isset( $options[ $value ] ) ) {
			// Keep an unknown value visible so the validator's error points at something the admin can see.
			echo '<option value="' . esc_attr( $value ) . '" selected>' . esc_html( $value ) . '</option>';
		}
		foreach ( $options as $option => $label ) {
			echo '<option value="' . esc_attr( $option ) . '"' . selected( $value, $option, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	/**
	 * Checkbox with a visually compact label.
	 *
	 * @param string      $path      Dot path used for the id.
	 * @param bool        $checked   Checked state.
	 * @param string      $label     Label text.
	 * @param string|null $list_path When set, the field posts as `<list_path>[]` (multi-value).
	 * @param string      $value     Submitted value.
	 */
	private function checkbox( string $path, bool $checked, string $label, ?string $list_path = null, string $value = '1' ): void {
		$name = null !== $list_path ? self::field_name( $list_path ) . '[]' : self::field_name( $path );
		echo '<label class="ct-rc-check"><input type="checkbox" id="' . esc_attr( self::field_id( $path ) ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . checked( $checked, true, false ) . '> <span>' . esc_html( $label ) . '</span></label>';
	}

	/**
	 * Attribute list.
	 *
	 * @param array<string, mixed> $attrs name => value.
	 */
	private function attrs( array $attrs ): void {
		foreach ( $attrs as $name => $value ) {
			if ( '' === $value || null === $value ) {
				continue;
			}
			echo ' ' . esc_attr( (string) $name ) . '="' . esc_attr( (string) $value ) . '"';
		}
	}

	/**
	 * Effect cell for a factor row.
	 *
	 * @param string                    $id       Factor id.
	 * @param array<string, mixed>|null $effect   Estimate with the factor applied.
	 * @param array<string, mixed>|null $baseline The branch sample estimate.
	 */
	private function effect_cell( string $id, ?array $effect, ?array $baseline ): void {
		echo '<td class="ct-rc-effect" data-effect="' . esc_attr( $id ) . '">';
		if ( ! $effect ) {
			echo '<span class="ct-rc-effect__range">—</span>';
		} else {
			$delta = $baseline ? (float) $effect['hours'] - (float) $baseline['hours'] : 0.0;
			echo '<span class="ct-rc-effect__range">' . esc_html( Money::range( (float) $effect['low'], (float) $effect['high'], (string) $effect['currency'] ) ) . '</span>';
			echo '<span class="ct-rc-effect__meta">' . esc_html( self::hours( (float) $effect['hours'] ) . ' · ' . self::weeks( (int) $effect['weeks'] ) ) . ' ';
			if ( abs( $delta ) < 0.005 ) {
				echo '<em class="ct-rc-delta ct-rc-delta--zero">' . esc_html__( '= sample', 'cybertech-estimator' ) . '</em>';
			} else {
				echo '<em class="ct-rc-delta ' . ( $delta > 0 ? 'ct-rc-delta--up' : 'ct-rc-delta--down' ) . '">' . esc_html( ( $delta > 0 ? '+' : '−' ) . self::hours( abs( $delta ) ) ) . '</em>';
			}
			echo '</span>';
		}
		echo '</td>';
	}

	/**
	 * `id` attribute for a dot path: `factors.web_templates.value` → `rc-factors-web_templates-value`.
	 *
	 * @param string $path Dot path.
	 */
	public static function field_id( string $path ): string {
		return 'rc-' . str_replace( '.', '-', $path );
	}

	/**
	 * `name` attribute for a dot path: `factors.web_templates.value` → `rate_card[factors][web_templates][value]`.
	 *
	 * @param string $path Dot path.
	 */
	public static function field_name( string $path ): string {
		return 'rate_card[' . implode( '][', explode( '.', $path ) ) . ']';
	}

	/**
	 * Locale-neutral number for input values (no thousands separators).
	 *
	 * @param float $n Number.
	 */
	private static function plain( float $n ): string {
		return rtrim( rtrim( number_format( $n, 4, '.', '' ), '0' ), '.' );
	}

	/**
	 * "186 h".
	 *
	 * @param float $hours Hours.
	 */
	private static function hours( float $hours ): string {
		/* translators: %s: number of hours */
		return sprintf( __( '%s h', 'cybertech-estimator' ), number_format_i18n( $hours, abs( $hours - round( $hours ) ) < 0.05 ? 0 : 1 ) );
	}

	/**
	 * "7 wk".
	 *
	 * @param int $weeks Weeks.
	 */
	private static function weeks( int $weeks ): string {
		/* translators: %d: number of weeks */
		return sprintf( __( '%d wk', 'cybertech-estimator' ), $weeks );
	}

	/**
	 * One-line description of a sample: "WordPress, WooCommerce, 8 templates, …".
	 *
	 * @param array<string, mixed> $answers Sample answers.
	 */
	private static function describe_sample( array $answers ): string {
		$parts = [];
		foreach ( Questionnaire::resolve_labels( SandboxController::normalise_answers( $answers ) ) as $id => $row ) {
			if ( in_array( $id, [ 'service_line', 'budget', 'maintenance', 'hosting' ], true ) ) {
				continue;
			}
			$question = Questionnaire::questions()[ $id ] ?? [];
			if ( Questionnaire::TYPE_NUMBER === ( $question['type'] ?? '' ) ) {
				$parts[] = $row['value'] . ' ' . lcfirst( $row['label'] );
			} elseif ( 'no' === ( $answers[ $id ] ?? '' ) ) {
				continue;
			} elseif ( 'yes' === ( $answers[ $id ] ?? '' ) ) {
				$parts[] = rtrim( $row['label'], '?' );
			} else {
				$parts[] = $row['value'];
			}
		}
		return implode( ' · ', $parts );
	}
}
