<?php
/**
 * Front-end asset registration for the estimator wizard.
 *
 * Assets are registered on `wp_enqueue_scripts` and only enqueued from the
 * shortcode render, so pages without a wizard load nothing. Config goes to
 * the page as one JSON object (`window.ctEstimator`) via
 * `wp_add_inline_script()` — not `wp_localize_script()`, which stringifies
 * every value (docs/PLAN.md D5).
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Frontend;

use Cybertech\Estimator\Brand;
use Cybertech\Estimator\Engine\Questionnaire;
use Cybertech\Estimator\Security\Honeypot;
use Cybertech\Estimator\Security\RateLimiter;
use Cybertech\Estimator\Support\Settings;

/**
 * Wizard assets + JS config.
 */
final class Assets {

	public const HANDLE_TOKENS = 'ct-est-tokens';
	public const HANDLE_WIZARD = 'ct-est-wizard';

	/**
	 * Whether the config has already been printed for this request.
	 *
	 * @var bool
	 */
	private static bool $configured = false;

	/**
	 * Register (do not enqueue) the wizard assets.
	 */
	public static function register(): void {
		if ( wp_script_is( self::HANDLE_WIZARD, 'registered' ) ) {
			return;
		}
		wp_register_style( self::HANDLE_TOKENS, CT_EST_URL . 'assets/css/tokens.css', [], CT_EST_VERSION );
		wp_register_style( self::HANDLE_WIZARD, CT_EST_URL . 'assets/css/wizard.css', [ self::HANDLE_TOKENS ], CT_EST_VERSION );
		wp_register_script(
			self::HANDLE_WIZARD,
			CT_EST_URL . 'assets/js/wizard.js',
			[],
			CT_EST_VERSION,
			[
				'in_footer' => true,
				'strategy'  => 'defer',
			]
		);
	}

	/**
	 * Enqueue the wizard assets and inject the config. Safe to call more
	 * than once per request (a page with two shortcodes gets one config).
	 *
	 * @param array<string, mixed> $config Config from `config()`.
	 */
	public static function enqueue( array $config ): void {
		self::register();
		wp_enqueue_style( self::HANDLE_WIZARD );
		wp_enqueue_script( self::HANDLE_WIZARD );
		if ( self::$configured ) {
			return;
		}
		self::$configured = true;
		wp_add_inline_script(
			self::HANDLE_WIZARD,
			'window.ctEstimator = ' . wp_json_encode( $config ) . ';',
			'before'
		);
	}

	/**
	 * Build the JS config.
	 *
	 * @param string $mode       Resolved reveal mode (open | band | gated).
	 * @param string $service    Service-line prefilter or ''.
	 * @param string $source_url URL of the page hosting the wizard.
	 * @return array<string, mixed>
	 */
	public static function config( string $mode, string $service, string $source_url ): array {
		$contact_url = (string) Settings::get( 'general.contact_page' );
		if ( '' === $contact_url ) {
			$contact_url = 'mailto:' . Brand::get( 'contact_email' );
		}

		return [
			'restUrl'          => esc_url_raw( rest_url( 'ct-est/v1/' ) ),
			'endpoints'        => [
				'preview'   => esc_url_raw( rest_url( 'ct-est/v1/preview' ) ),
				'submit'    => esc_url_raw( rest_url( 'ct-est/v1/submit' ) ),
				'token'     => esc_url_raw( rest_url( 'ct-est/v1/token' ) ),
				'narrative' => esc_url_raw( rest_url( 'ct-est/v1/narrative' ) ),
			],
			'nonce'            => wp_create_nonce( 'wp_rest' ),
			'loggedIn'         => is_user_logged_in(),
			'mode'             => $mode,
			'servicePrefilter' => $service,
			'honeypot'         => [
				'field' => Honeypot::FIELD_HONEY,
				'token' => Honeypot::FIELD_TOKEN,
			],
			'cookieName'       => RateLimiter::COOKIE,
			'sourceUrl'        => esc_url_raw( $source_url ),
			'previewDebounce'  => 350,
			'steps'            => self::steps_schema(),
			'brand'            => [
				'company'       => Brand::get( 'company' ),
				'contact_email' => Brand::get( 'contact_email' ),
				'contact_url'   => esc_url_raw( $contact_url ),
			],
			'consentText'      => Brand::get( 'consent_text' ),
			'strings'          => self::strings(),
		];
	}

	/**
	 * Questionnaire schema shaped for the browser: ordered option lists,
	 * no rate-card factors, consent label resolved from the brand.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function steps_schema(): array {
		$out = [];
		foreach ( Questionnaire::steps() as $step ) {
			$questions = [];
			foreach ( $step['questions'] as $q ) {
				$item = [
					'id'       => (string) $q['id'],
					'type'     => (string) $q['type'],
					'label'    => Questionnaire::TYPE_CHECKBOX === $q['type'] && '' === (string) $q['label'] ? Brand::get( 'consent_text' ) : (string) $q['label'],
					'help'     => (string) ( $q['help'] ?? '' ),
					'required' => ! empty( $q['required'] ),
					'contact'  => ! empty( $q['contact'] ),
					'show_if'  => (object) ( $q['show_if'] ?? [] ),
				];
				if ( isset( $q['min'] ) ) {
					$item['min'] = (int) $q['min'];
				}
				if ( isset( $q['max'] ) ) {
					$item['max'] = (int) $q['max'];
				}
				if ( array_key_exists( 'default', $q ) ) {
					$item['default'] = $q['default'];
				}
				if ( ! empty( $q['options'] ) ) {
					$item['options'] = [];
					foreach ( $q['options'] as $value => $opt ) {
						$item['options'][] = [
							'value' => (string) $value,
							'label' => (string) $opt['label'],
							'help'  => (string) ( $opt['help'] ?? '' ),
						];
					}
				}
				$questions[] = $item;
			}//end foreach
			$out[] = [
				'id'        => (string) $step['id'],
				'title'     => (string) $step['title'],
				'questions' => $questions,
			];
		}//end foreach
		return $out;
	}

	/**
	 * Every string the wizard JS renders. Translated server-side so no JSON
	 * translation build is needed.
	 *
	 * @return array<string, string>
	 */
	public static function strings(): array {
		return [
			'next'             => __( 'Next', 'cybertech-estimator' ),
			'back'             => __( 'Back', 'cybertech-estimator' ),
			'continue'         => __( 'Continue', 'cybertech-estimator' ),
			'submit'           => __( 'Send me the full estimate', 'cybertech-estimator' ),
			'reveal'           => __( 'Reveal my estimate', 'cybertech-estimator' ),
			'submitting'       => __( 'Sending…', 'cybertech-estimator' ),
			'calculating'      => __( 'Calculating your estimate…', 'cybertech-estimator' ),
			'loading'          => __( 'Loading…', 'cybertech-estimator' ),
			'yourEstimate'     => __( 'Your estimate', 'cybertech-estimator' ),
			/* translators: %s: estimate summary, e.g. "€9,750 – €14,500 · 9 weeks" */
			'currentEstimate'  => __( 'Current estimate: %s', 'cybertech-estimator' ),
			'keepGoing'        => __( 'Keep going — your estimate is revealed at the end.', 'cybertech-estimator' ),
			'range'            => __( 'Indicative range', 'cybertech-estimator' ),
			'timeline'         => __( 'Timeline', 'cybertech-estimator' ),
			'weeks'            => __( 'weeks', 'cybertech-estimator' ),
			'band'             => __( 'Budget band', 'cybertech-estimator' ),
			'team'             => __( 'Suggested team', 'cybertech-estimator' ),
			/* translators: %d: hours */
			'hours'            => __( '%d h', 'cybertech-estimator' ),
			/* translators: 1: current step number, 2: total number of steps */
			'stepOf'           => __( 'Step %1$s of %2$s', 'cybertech-estimator' ),
			/* translators: 1: characters used, 2: maximum characters */
			'charCount'        => __( '%1$s / %2$s characters', 'cybertech-estimator' ),
			'copyLink'         => __( 'Copy link', 'cybertech-estimator' ),
			'copied'           => __( 'Copied!', 'cybertech-estimator' ),
			'copyFailed'       => __( 'Could not copy — select the link and copy it manually.', 'cybertech-estimator' ),
			'contactUs'        => __( 'Contact us directly', 'cybertech-estimator' ),
			'errorRequired'    => __( 'This field is required.', 'cybertech-estimator' ),
			'errorChoose'      => __( 'Please choose one of the options.', 'cybertech-estimator' ),
			'errorChooseAny'   => __( 'Please choose at least one option.', 'cybertech-estimator' ),
			'errorNumber'      => __( 'Please enter a whole number.', 'cybertech-estimator' ),
			/* translators: %s: minimum value */
			'errorMin'         => __( 'Please enter %s or more.', 'cybertech-estimator' ),
			/* translators: %s: maximum value */
			'errorMax'         => __( 'Please enter %s or less.', 'cybertech-estimator' ),
			/* translators: %s: maximum characters */
			'errorMaxLength'   => __( 'Please keep this under %s characters.', 'cybertech-estimator' ),
			'errorEmail'       => __( 'Please enter a valid email address.', 'cybertech-estimator' ),
			'errorConsent'     => __( 'Please confirm you agree to be contacted about this estimate.', 'cybertech-estimator' ),
			'errorFix'         => __( 'Please check the highlighted fields.', 'cybertech-estimator' ),
			'errorGeneric'     => __( 'Something went wrong on our side. Please try again in a moment.', 'cybertech-estimator' ),
			'errorNetwork'     => __( 'We could not reach the server. Check your connection and try again.', 'cybertech-estimator' ),
			'errorRateLimited' => __( 'You have reached the limit of estimates for now. Please try again in an hour or contact us directly.', 'cybertech-estimator' ),
			'previewPaused'    => __( 'Live preview paused for now — you can still finish and receive your estimate.', 'cybertech-estimator' ),
			'unlockedTitle'    => __( 'Your estimate is ready', 'cybertech-estimator' ),
			'shareLabel'       => __( 'Shareable link to this estimate', 'cybertech-estimator' ),
			'narrativePhases'  => __( 'Delivery phases', 'cybertech-estimator' ),
			'narrativeAssume'  => __( 'Assumptions', 'cybertech-estimator' ),
			'narrativeRisks'   => __( 'Risks to watch', 'cybertech-estimator' ),
			/* translators: %s: number of weeks (abbreviated badge) */
			'weeksShort'       => __( '%s wk', 'cybertech-estimator' ),
		];
	}
}
