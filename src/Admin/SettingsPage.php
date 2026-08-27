<?php
/**
 * Estimator → Settings: one tabbed screen over the `ct_est_settings` option.
 *
 * Persistence goes through the WordPress Settings API (options.php) with a
 * single sanitize callback. Because every tab posts only its own group(s),
 * the callback merges field-by-field into the *current* option instead of
 * replacing it — saving the AI tab can never wipe the webhook secret. The
 * side actions (test email, test webhook, breaker reset, clear log) are
 * admin-post handlers that redirect back with a per-user flash transient,
 * mirroring RateCardPage so the two screens feel like one plugin.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Admin;

use Cybertech\Estimator\Ai\BudgetGuard;
use Cybertech\Estimator\Ai\CircuitBreaker;
use Cybertech\Estimator\Engine\RateCardRepository;
use Cybertech\Estimator\Integration\WebhookDispatcher;
use Cybertech\Estimator\Lead\LeadPostType;
use Cybertech\Estimator\Support\Logger;
use Cybertech\Estimator\Support\Money;
use Cybertech\Estimator\Support\Settings;

/**
 * Settings admin page.
 */
final class SettingsPage {

	public const SLUG         = 'ct-est-settings';
	public const CAPABILITY   = 'manage_options';
	public const OPTION_GROUP = 'ct_est_settings_group';

	/**
	 * What a stored secret is rendered as. Submitting it back means "keep".
	 */
	public const MASK = '••••••••';

	/**
	 * Transient the AI layer (Phase 4) trips when the provider keeps failing.
	 */
	public const BREAKER_TRANSIENT = 'ct_est_ai_breaker';

	private const PARENT        = 'edit.php?post_type=' . LeadPostType::POST_TYPE;
	private const NONCE         = 'ct_est_settings';
	private const FLASH_KEY     = 'ct_est_settings_flash_';
	private const WEBHOOK_KEY   = 'ct_est_webhook_test_';
	private const REST_MODELS   = 'ct-est/v1/admin/models';
	private const LOG_ROWS      = 50;
	private const ADMIN_ACTIONS = [ 'test_email', 'webhook_test', 'ai_breaker_reset', 'log_clear' ];

	/**
	 * Screen hook suffix returned by add_submenu_page().
	 *
	 * @var string
	 */
	private string $hook = '';

	/**
	 * One-shot message from the last admin-post redirect (null = none, false = not loaded yet).
	 *
	 * @var array<string, mixed>|null|false
	 */
	private array|null|false $flash = false;

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_init', [ $this, 'register_setting' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'admin_notices', [ $this, 'notices' ] );
		foreach ( self::ADMIN_ACTIONS as $action ) {
			add_action( 'admin_post_ct_est_' . $action, [ $this, 'handle_' . $action ] );
		}
	}

	/**
	 * Submenu under the Estimator (lead CPT) menu.
	 */
	public function add_menu(): void {
		$hook       = add_submenu_page(
			self::PARENT,
			__( 'Settings', 'cybertech-estimator' ),
			__( 'Settings', 'cybertech-estimator' ),
			self::CAPABILITY,
			self::SLUG,
			[ $this, 'render' ]
		);
		$this->hook = is_string( $hook ) ? $hook : '';
	}

	/**
	 * Settings API registration: one option, one sanitize callback.
	 */
	public function register_setting(): void {
		register_setting(
			self::OPTION_GROUP,
			Settings::OPTION,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize' ],
				'default'           => [],
			]
		);
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
		wp_enqueue_style( 'ct-est-settings', CT_EST_URL . 'assets/css/settings.css', [], self::asset_version( 'assets/css/settings.css' ) );
		wp_enqueue_script( 'ct-est-settings', CT_EST_URL . 'assets/js/settings.js', [], self::asset_version( 'assets/js/settings.js' ), [ 'in_footer' => true ] );
		// JSON, not wp_localize_script(): that helper stringifies every value.
		wp_add_inline_script( 'ct-est-settings', 'window.ctEstSettings = ' . wp_json_encode( $this->config() ) . ';', 'before' );
	}

	/**
	 * Page config handed to settings.js.
	 *
	 * @return array<string, mixed>
	 */
	private function config(): array {
		return [
			'modelsEndpoint' => rest_url( self::REST_MODELS ),
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'i18n'           => [
				'loading'       => __( 'Fetching models…', 'cybertech-estimator' ),
				'notYet'        => __( 'Available once the AI layer lands (Phase 4).', 'cybertech-estimator' ),
				/* translators: %s: error detail */
				'failed'        => __( 'Could not fetch the model list: %s', 'cybertech-estimator' ),
				'empty'         => __( 'The provider returned no models.', 'cybertech-estimator' ),
				/* translators: %d: number of models */
				'loaded'        => __( '%d models loaded. Start typing in the model field to pick one.', 'cybertech-estimator' ),
				/* translators: %s: model slug */
				'suggested'     => __( 'Suggested a free model: %s. Free slugs are rate-limited — use a paid one in production.', 'cybertech-estimator' ),
				/* translators: 1: prompt price, 2: completion price, both USD per 1M tokens */
				'price'         => __( '$%1$s in / $%2$s out per 1M', 'cybertech-estimator' ),
				'free'          => __( 'free', 'cybertech-estimator' ),
				'breakerOpen'   => __( 'Open — calls paused', 'cybertech-estimator' ),
				'breakerClosed' => __( 'Closed — healthy', 'cybertech-estimator' ),
				/* translators: %d: consecutive failures */
				'breakerFails'  => __( '%d consecutive failure(s) recorded.', 'cybertech-estimator' ),
				/* translators: 1: spent USD, 2: budget USD, 3: percent */
				'spendOf'       => __( '$%1$s of $%2$s (%3$d%%)', 'cybertech-estimator' ),
				/* translators: %s: spent USD */
				'spendNoBudget' => __( '$%s (no budget)', 'cybertech-estimator' ),
			],
		];
	}

	/**
	 * Flash after an admin-post redirect (Settings API notices are printed by render()).
	 */
	public function notices(): void {
		$screen = get_current_screen();
		if ( ! $screen || $screen->id !== $this->hook ) {
			return;
		}
		$this->demo_notice();
		$flash = $this->flash();
		if ( ! $flash ) {
			return;
		}
		$class = 'error' === $flash['type'] ? 'notice-error' : 'notice-success';
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( (string) $flash['message'] ) . '</p></div>';
	}

	/**
	 * The demo seeder (DemoSeeder) redirects here with ?ct_est_demo=seeded|unseeded&ct_est_count=N.
	 */
	private function demo_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only flag set by our own redirect; nothing is changed here.
		$outcome = isset( $_GET['ct_est_demo'] ) ? sanitize_key( (string) wp_unslash( $_GET['ct_est_demo'] ) ) : '';
		$count   = isset( $_GET['ct_est_count'] ) ? (int) $_GET['ct_est_count'] : 0;
		// phpcs:enable
		if ( 'seeded' === $outcome ) {
			/* translators: %d: number of leads */
			$message = sprintf( _n( '%d demo lead created.', '%d demo leads created.', $count, 'cybertech-estimator' ), $count );
		} elseif ( 'unseeded' === $outcome ) {
			/* translators: %d: number of leads */
			$message = sprintf( _n( '%d demo lead removed.', '%d demo leads removed.', $count, 'cybertech-estimator' ), $count );
		} else {
			return;
		}
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	/* ---------- sanitisation ---------- */

	/**
	 * Settings API sanitize callback.
	 *
	 * Runs on every update_option() of the option while admin_init has
	 * fired — from options.php *and* from Settings::update_group(). Both
	 * paths are safe: only the groups/keys present in `$input` are touched,
	 * everything else is copied from the stored option, and every value is
	 * cast to the type of its default so the store never holds a string "1"
	 * where code expects a bool.
	 *
	 * @param mixed $input Posted (already unslashed) option value.
	 * @return array<string, array<string, mixed>>
	 */
	public function sanitize( mixed $input ): array {
		$current = get_option( Settings::OPTION, [] );
		$current = is_array( $current ) ? $current : [];
		if ( ! is_array( $input ) ) {
			return $current;
		}
		$defaults = Settings::defaults();

		foreach ( $input as $group => $fields ) {
			if ( ! isset( $defaults[ $group ] ) || ! is_array( $fields ) ) {
				continue;
				// Unknown group: dropped, never stored.
			}
			$stored = is_array( $current[ $group ] ?? null ) ? $current[ $group ] : [];
			foreach ( $defaults[ $group ] as $key => $default ) {
				if ( ! array_key_exists( $key, $fields ) ) {
					continue;
					// Not posted (other tab / split group): keep what is stored.
				}
				$old            = array_key_exists( $key, $stored ) ? $stored[ $key ] : $default;
				$stored[ $key ] = $this->sanitize_field( $group, $key, $fields[ $key ], $old, $fields );
			}
			$current[ $group ] = $stored;
		}

		return $current;
	}

	/**
	 * One field. Invalid values fall back to the previous value and register
	 * a settings error so the admin sees why the field did not change.
	 *
	 * @param string               $group  Group name.
	 * @param string               $key    Field name.
	 * @param mixed                $value  Posted value.
	 * @param mixed                $old    Currently stored value (or default).
	 * @param array<string, mixed> $fields Whole posted group (for companion inputs like *_clear).
	 * @return mixed
	 */
	private function sanitize_field( string $group, string $key, mixed $value, mixed $old, array $fields ): mixed {
		$path = $group . '.' . $key;
		switch ( $path ) {
			case 'general.reveal_mode':
				$value = is_string( $value ) ? $value : '';
				return in_array( $value, [ 'open', 'band', 'gated' ], true ) ? $value : (string) $old;

			case 'general.contact_page':
				$url = esc_url_raw( trim( (string) $value ), [ 'http', 'https' ] );
				if ( '' !== trim( (string) $value ) && '' === $url ) {
					$this->error( 'contact_page', __( 'Contact page must be an http(s) URL. The previous value was kept.', 'cybertech-estimator' ) );
					return (string) $old;
				}
				return $url;

			case 'general.share_days':
				return self::clamp_int( $value, 1, 3650, (int) $old );

			case 'security.preview_per_hour':
			case 'security.submit_per_hour':
				return self::clamp_int( $value, 1, 1000, (int) $old );

			case 'security.min_seconds':
				return self::clamp_int( $value, 0, 120, (int) $old );

			case 'ai.provider':
				$value = is_string( $value ) ? $value : '';
				return array_key_exists( $value, self::provider_choices() ) ? $value : (string) $old;

			case 'ai.api_key':
			case 'integrations.webhook_secret':
				return self::secret( $value, (string) $old, ! empty( $fields[ $key . '_clear' ] ) );

			case 'ai.model':
				// Provider slugs look like "vendor/name:variant"; anything else is noise.
				$model = (string) preg_replace( '/[^A-Za-z0-9._:\/-]/', '', (string) $value );
				return substr( $model, 0, 200 );

			case 'ai.max_price':
				$price = is_numeric( $value ) ? (float) $value : (float) $old;
				return round( max( 0.0, min( 1000.0, $price ) ), 4 );

			case 'ai.max_tokens':
				return self::clamp_int( $value, 50, 8000, (int) $old );

			case 'ai.timeout':
				return self::clamp_int( $value, 1, 60, (int) $old );

			case 'ai.monthly_budget_cents':
				return self::clamp_int( $value, 0, 10000000, (int) $old );

			case 'ai.cache_days':
				return self::clamp_int( $value, 0, 365, (int) $old );

			case 'notifications.sales_email':
				$raw = trim( (string) $value );
				if ( '' === $raw ) {
					return '';
				}
				$email = sanitize_email( $raw );
				if ( ! is_email( $email ) ) {
					$this->error( 'sales_email', __( 'Sales e-mail is not a valid address. The previous value was kept.', 'cybertech-estimator' ) );
					return (string) $old;
				}
				return $email;

			case 'integrations.webhook_url':
				$raw = trim( (string) $value );
				if ( '' === $raw ) {
					return '';
				}
				$url = esc_url_raw( $raw, [ 'http', 'https' ] );
				if ( '' === $url || ! self::webhook_url_allowed( $url ) ) {
					$this->error( 'webhook_url', __( 'Webhook URL must use https:// (plain http:// is only allowed for 127.0.0.1 / localhost). The previous value was kept.', 'cybertech-estimator' ) );
					return (string) $old;
				}
				return $url;

			case 'privacy.retention_days':
				return self::clamp_int( $value, 7, 3650, (int) $old );
		}//end switch

		// Everything left (enabled, floor, store_ip, send_confirmation, delete_leads_on_uninstall) is a checkbox.
		if ( is_bool( Settings::defaults()[ $group ][ $key ] ) ) {
			return self::to_bool( $value );
		}
		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : $old;
	}

	/**
	 * Secret round-trip: the mask means "unchanged", the companion checkbox means "forget it".
	 *
	 * @param mixed  $value Posted value.
	 * @param string $old   Stored secret.
	 * @param bool   $clear Companion "clear" checkbox.
	 */
	private static function secret( mixed $value, string $old, bool $clear ): string {
		if ( $clear ) {
			return '';
		}
		$value = trim( (string) $value );
		if ( '' === $value || self::MASK === $value ) {
			return $old;
		}
		// No sanitize_text_field(): API keys may legitimately contain characters it strips.
		return substr( (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $value ), 0, 500 );
	}

	/**
	 * Only TLS in the wild; plain http solely for a local receiver.
	 *
	 * @param string $url Already-escaped URL.
	 */
	private static function webhook_url_allowed( string $url ): bool {
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		$host   = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( 'https' === $scheme ) {
			return '' !== $host;
		}
		return 'http' === $scheme && in_array( $host, [ '127.0.0.1', 'localhost', '[::1]' ], true );
	}

	/**
	 * Integer within [min, max]; non-numeric input keeps the old value.
	 *
	 * @param mixed $value    Posted value.
	 * @param int   $min      Lower bound.
	 * @param int   $max      Upper bound.
	 * @param int   $fallback Previous value.
	 */
	private static function clamp_int( mixed $value, int $min, int $max, int $fallback ): int {
		if ( ! is_numeric( $value ) ) {
			return max( $min, min( $max, $fallback ) );
		}
		return max( $min, min( $max, (int) $value ) );
	}

	/**
	 * Checkbox semantics: "1"/"on"/true are on, everything else off.
	 *
	 * @param mixed $value Posted value.
	 */
	private static function to_bool( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		return in_array( strtolower( trim( (string) $value ) ), [ '1', 'on', 'true', 'yes' ], true );
	}

	/**
	 * Register a validation error (shown by settings_errors() after the redirect).
	 *
	 * @param string $code    Field code.
	 * @param string $message Message.
	 */
	private function error( string $code, string $message ): void {
		add_settings_error( Settings::OPTION, $code, $message, 'error' );
	}

	/**
	 * AI provider options; filterable so the AI layer can add providers without touching this page.
	 *
	 * @return array<string, string>
	 */
	private static function provider_choices(): array {
		/**
		 * Filters the AI provider choices shown on the settings page.
		 *
		 * @param array<string, string> $choices slug => label.
		 */
		$choices = apply_filters(
			'ct_est_ai_provider_choices',
			[
				'openrouter' => __( 'OpenRouter', 'cybertech-estimator' ),
				'null'       => __( 'None (fallback text only)', 'cybertech-estimator' ),
			]
		);
		return is_array( $choices ) ? array_map( 'strval', $choices ) : [];
	}

	/* ---------- admin-post handlers ---------- */

	/**
	 * Send a plain test message to the sales address.
	 */
	public function handle_test_email(): void {
		$this->authorize( 'test_email' );
		$to = self::sales_email();
		if ( ! is_email( $to ) ) {
			$this->fail( __( 'No valid sales e-mail is configured.', 'cybertech-estimator' ), 'notifications' );
		}
		$subject = sprintf(
			/* translators: %s: site name */
			__( '[%s] Estimator test e-mail', 'cybertech-estimator' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);
		$body = sprintf(
			/* translators: 1: site URL, 2: date/time */
			__( "This is a test message from the Cybertech Project Estimator plugin on %1\$s.\n\nSent: %2\$s\n\nIf you can read this, lead notifications will reach this inbox.", 'cybertech-estimator' ),
			home_url( '/' ),
			wp_date( 'Y-m-d H:i:s T' )
		);
		$sent = wp_mail( $to, $subject, $body );
		Logger::log( 'mail', $sent ? 'test_sent' : 'test_failed', [ 'to' => $to ] );
		if ( ! $sent ) {
			$this->fail( __( 'wp_mail() reported a failure. Check your SMTP configuration.', 'cybertech-estimator' ), 'notifications' );
		}
		/* translators: %s: e-mail address */
		$this->succeed( sprintf( __( 'Test e-mail sent to %s.', 'cybertech-estimator' ), $to ), 'notifications' );
	}

	/**
	 * Fire the sample payload at the configured webhook, synchronously, and
	 * stash the full exchange for the Integrations tab to display.
	 */
	public function handle_webhook_test(): void {
		$this->authorize( 'webhook_test' );
		$url = trim( (string) Settings::get( 'integrations.webhook_url' ) );
		if ( '' === $url ) {
			$this->fail( __( 'Save a webhook URL first.', 'cybertech-estimator' ), 'integrations' );
		}
		$dispatcher = new WebhookDispatcher();
		$outcome    = $dispatcher->send( $url, $dispatcher->sample_payload() );
		Logger::log(
			'webhook',
			$outcome['ok'] ? 'test_delivered' : 'test_failed',
			[
				'status' => $outcome['status'],
				'error'  => $outcome['error'],
			]
		);
		set_transient( self::WEBHOOK_KEY . get_current_user_id(), $outcome, 5 * MINUTE_IN_SECONDS );
		if ( $outcome['ok'] ) {
			/* translators: %d: HTTP status code */
			$this->succeed( sprintf( __( 'Webhook accepted the test payload (HTTP %d).', 'cybertech-estimator' ), $outcome['status'] ), 'integrations' );
		}
		$this->fail(
			'' !== $outcome['error']
				/* translators: %s: transport error message */
				? sprintf( __( 'Webhook request failed: %s', 'cybertech-estimator' ), $outcome['error'] )
				/* translators: %d: HTTP status code */
				: sprintf( __( 'Webhook rejected the test payload (HTTP %d).', 'cybertech-estimator' ), $outcome['status'] ),
			'integrations'
		);
	}

	/**
	 * Close the AI circuit breaker.
	 */
	public function handle_ai_breaker_reset(): void {
		$this->authorize( 'ai_breaker_reset' );
		if ( class_exists( CircuitBreaker::class ) ) {
			CircuitBreaker::reset();
		} else {
			delete_transient( self::BREAKER_TRANSIENT );
			Logger::log( 'ai', 'breaker_reset', [ 'user' => get_current_user_id() ] );
		}
		$this->succeed( __( 'Circuit breaker reset. AI calls will be attempted again.', 'cybertech-estimator' ), 'ai' );
	}

	/**
	 * Empty the diagnostics log ring.
	 */
	public function handle_log_clear(): void {
		$this->authorize( 'log_clear' );
		Logger::clear();
		$this->succeed( __( 'Log cleared.', 'cybertech-estimator' ), 'diagnostics' );
	}

	/* ---------- handler plumbing ---------- */

	/**
	 * Capability + nonce gate for every handler.
	 *
	 * @param string $action Action suffix.
	 */
	private function authorize( string $action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'cybertech-estimator' ), 403 );
		}
		check_admin_referer( self::NONCE . '_' . $action );
	}

	/**
	 * Redirect back with a success notice (never returns).
	 *
	 * @param string $message Message.
	 * @param string $tab     Tab to land on.
	 */
	private function succeed( string $message, string $tab = 'general' ): void {
		$this->redirect(
			[
				'type'    => 'success',
				'message' => $message,
			],
			$tab
		);
	}

	/**
	 * Redirect back with an error notice (never returns).
	 *
	 * @param string $message Message.
	 * @param string $tab     Tab to land on.
	 */
	private function fail( string $message, string $tab = 'general' ): void {
		$this->redirect(
			[
				'type'    => 'error',
				'message' => $message,
			],
			$tab
		);
	}

	/**
	 * Stash the flash for this user and go back to the page (never returns).
	 *
	 * @param array<string, mixed> $flash Flash payload.
	 * @param string               $tab   Tab to land on.
	 */
	private function redirect( array $flash, string $tab ): void {
		set_transient( self::FLASH_KEY . get_current_user_id(), $flash, 2 * MINUTE_IN_SECONDS );
		wp_safe_redirect( self::tab_url( $tab ) );
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
	 * URL of one tab.
	 *
	 * @param string $tab Tab id.
	 */
	private static function tab_url( string $tab ): string {
		return add_query_arg( 'tab', $tab, admin_url( self::PARENT . '&page=' . self::SLUG ) );
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

	/**
	 * Effective sales address (setting or the site admin).
	 */
	private static function sales_email(): string {
		$email = trim( (string) Settings::get( 'notifications.sales_email' ) );
		return '' !== $email ? $email : (string) get_option( 'admin_email' );
	}

	/* ---------- rendering ---------- */

	/**
	 * Tab registry: id => label.
	 *
	 * @return array<string, string>
	 */
	private static function tabs(): array {
		return [
			'general'       => __( 'General', 'cybertech-estimator' ),
			'pricing'       => __( 'Pricing', 'cybertech-estimator' ),
			'ai'            => __( 'AI', 'cybertech-estimator' ),
			'notifications' => __( 'Notifications', 'cybertech-estimator' ),
			'integrations'  => __( 'Integrations', 'cybertech-estimator' ),
			'privacy'       => __( 'Privacy', 'cybertech-estimator' ),
			'diagnostics'   => __( 'Diagnostics', 'cybertech-estimator' ),
		];
	}

	/**
	 * Current tab from the query string, defaulting to General.
	 */
	private static function current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation state.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( (string) wp_unslash( $_GET['tab'] ) ) : 'general';
		return array_key_exists( $tab, self::tabs() ) ? $tab : 'general';
	}

	/**
	 * Page body.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'cybertech-estimator' ), 403 );
		}
		$tab      = self::current_tab();
		$settings = Settings::all();
		?>
		<div class="wrap ct-st">
			<h1><?php esc_html_e( 'Estimator settings', 'cybertech-estimator' ); ?></h1>
			<?php
			// Unfiltered on purpose: core files "Settings saved." under 'general', our validation errors under the option name.
			settings_errors();
			?>
			<nav class="nav-tab-wrapper ct-st__tabs" aria-label="<?php esc_attr_e( 'Settings sections', 'cybertech-estimator' ); ?>">
				<?php foreach ( self::tabs() as $id => $label ) : ?>
					<a href="<?php echo esc_url( self::tab_url( $id ) ); ?>" class="nav-tab<?php echo $id === $tab ? ' nav-tab-active' : ''; ?>"<?php echo $id === $tab ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<div class="ct-st__panel" id="ct-st-panel-<?php echo esc_attr( $tab ); ?>">
				<?php
				switch ( $tab ) {
					case 'pricing':
						$this->render_pricing();
						break;
					case 'ai':
						$this->render_ai( $settings['ai'] );
						break;
					case 'notifications':
						$this->render_notifications( $settings['notifications'] );
						break;
					case 'integrations':
						$this->render_integrations( $settings['integrations'] );
						break;
					case 'privacy':
						$this->render_privacy( $settings['privacy'], $settings['security'] );
						break;
					case 'diagnostics':
						$this->render_diagnostics();
						break;
					default:
						$this->render_general( $settings['general'], $settings['security'] );
				}//end switch
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Opening tag + Settings API hidden fields for a tab form.
	 *
	 * @param string $tab Tab id (used as form id suffix).
	 */
	private function form_open( string $tab ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '" id="ct-st-form-' . esc_attr( $tab ) . '" class="ct-st-form">';
		settings_fields( self::OPTION_GROUP );
	}

	/**
	 * Input name for a field.
	 *
	 * @param string $group Group.
	 * @param string $key   Key.
	 */
	private static function name( string $group, string $key ): string {
		return Settings::OPTION . '[' . $group . '][' . $key . ']';
	}

	/**
	 * Input id for a field.
	 *
	 * @param string $group Group.
	 * @param string $key   Key.
	 */
	private static function id( string $group, string $key ): string {
		return 'ct-st-' . $group . '-' . str_replace( '_', '-', $key );
	}

	/**
	 * A checkbox row. The hidden "0" makes an unticked box explicit so the
	 * sanitizer can tell "unticked" from "not on this tab".
	 *
	 * @param string $group   Group.
	 * @param string $key     Key.
	 * @param bool   $checked Current value.
	 * @param string $label   Label next to the box.
	 * @param string $help    Description under it.
	 */
	private static function checkbox( string $group, string $key, bool $checked, string $label, string $help = '' ): void {
		$name = self::name( $group, $key );
		$id   = self::id( $group, $key );
		echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0">';
		echo '<label for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1"' . checked( $checked, true, false ) . '> ' . esc_html( $label ) . '</label>';
		if ( '' !== $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
	}

	/**
	 * A number input.
	 *
	 * @param string    $group Group.
	 * @param string    $key   Key.
	 * @param int|float $value Current value.
	 * @param int|float $min   Min.
	 * @param int|float $max   Max.
	 * @param string    $step  Step attribute.
	 */
	private static function number( string $group, string $key, int|float $value, int|float $min, int|float $max, string $step = '1' ): void {
		printf(
			'<input type="number" class="small-text" id="%1$s" name="%2$s" value="%3$s" min="%4$s" max="%5$s" step="%6$s">',
			esc_attr( self::id( $group, $key ) ),
			esc_attr( self::name( $group, $key ) ),
			esc_attr( (string) $value ),
			esc_attr( (string) $min ),
			esc_attr( (string) $max ),
			esc_attr( $step )
		);
	}

	/**
	 * A masked secret input with its "clear" companion.
	 *
	 * @param string $group Group.
	 * @param string $key   Key.
	 * @param string $value Stored secret.
	 * @param string $help  Description.
	 */
	private static function secret_input( string $group, string $key, string $value, string $help ): void {
		$id  = self::id( $group, $key );
		$set = '' !== $value;
		printf(
			'<input type="password" class="regular-text code" id="%1$s" name="%2$s" value="%3$s" autocomplete="new-password" spellcheck="false"%4$s>',
			esc_attr( $id ),
			esc_attr( self::name( $group, $key ) ),
			esc_attr( $set ? self::MASK : '' ),
			$set ? '' : ' placeholder="' . esc_attr__( 'Not set', 'cybertech-estimator' ) . '"'
		);
		if ( $set ) {
			echo ' <span class="ct-st-badge ct-st-badge--ok">' . esc_html__( 'Set', 'cybertech-estimator' ) . '</span>';
			echo '<p><label for="' . esc_attr( $id . '-clear' ) . '"><input type="checkbox" id="' . esc_attr( $id . '-clear' ) . '" name="' . esc_attr( self::name( $group, $key . '_clear' ) ) . '" value="1"> ' . esc_html__( 'Clear the stored value on save', 'cybertech-estimator' ) . '</label></p>';
		}
		echo '<p class="description">' . esc_html( $help ) . '</p>';
	}

	/**
	 * A self-contained admin-post form rendering as one button.
	 *
	 * @param string $action   Action suffix (after ct_est_).
	 * @param string $label    Button text.
	 * @param string $classes  Extra button classes.
	 * @param bool   $disabled Render disabled.
	 * @param string $id       Optional wrapper id.
	 * @param string $nonce    Nonce action; defaults to this page's scheme. Handlers owned by other modules (the demo seeder) verify their own names.
	 */
	private static function action_button( string $action, string $label, string $classes = 'button-secondary', bool $disabled = false, string $id = '', string $nonce = '' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ct-st-action"' . ( '' !== $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . '>';
		echo '<input type="hidden" name="action" value="' . esc_attr( 'ct_est_' . $action ) . '">';
		wp_nonce_field( '' !== $nonce ? $nonce : self::NONCE . '_' . $action );
		echo '<button type="submit" class="button ' . esc_attr( $classes ) . '"' . disabled( $disabled, true, false ) . '>' . esc_html( $label ) . '</button>';
		echo '</form>';
	}

	/* ---------- tabs ---------- */

	/**
	 * General tab: reveal mode, share links, contact fallback, rate limits.
	 *
	 * @param array<string, mixed> $general  General group.
	 * @param array<string, mixed> $security Security group (rate limits live here).
	 */
	private function render_general( array $general, array $security ): void {
		$modes = [
			'open'  => [
				__( 'Open', 'cybertech-estimator' ),
				__( 'The full price range and breakdown are shown as soon as the questionnaire is complete. Contact details are optional.', 'cybertech-estimator' ),
			],
			'band'  => [
				__( 'Band', 'cybertech-estimator' ),
				__( 'Only the price band (e.g. "mid-size engagement") is shown; exact figures unlock after contact details are submitted.', 'cybertech-estimator' ),
			],
			'gated' => [
				__( 'Gated', 'cybertech-estimator' ),
				__( 'Figures never leave the server before contact details are submitted — the preview endpoint returns no numbers at all.', 'cybertech-estimator' ),
			],
		];
		$this->form_open( 'general' );
		?>
		<h2><?php esc_html_e( 'Estimate reveal', 'cybertech-estimator' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Reveal mode', 'cybertech-estimator' ); ?></th>
				<td>
					<fieldset>
						<legend class="screen-reader-text"><?php esc_html_e( 'Reveal mode', 'cybertech-estimator' ); ?></legend>
						<?php foreach ( $modes as $value => [ $label, $help ] ) : ?>
							<?php $id = self::id( 'general', 'reveal_mode_' . $value ); ?>
							<label class="ct-st-radio" for="<?php echo esc_attr( $id ); ?>">
								<input type="radio" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( self::name( 'general', 'reveal_mode' ) ); ?>" value="<?php echo esc_attr( $value ); ?>"<?php checked( (string) $general['reveal_mode'], $value ); ?>>
								<span class="ct-st-radio__text"><strong><?php echo esc_html( $label ); ?></strong> <span class="description"><?php echo esc_html( $help ); ?></span></span>
							</label>
						<?php endforeach; ?>
					</fieldset>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="<?php echo esc_attr( self::id( 'general', 'share_days' ) ); ?>"><?php esc_html_e( 'Share link validity', 'cybertech-estimator' ); ?></label></th>
				<td>
					<?php self::number( 'general', 'share_days', (int) $general['share_days'], 1, 3650 ); ?> <?php esc_html_e( 'days', 'cybertech-estimator' ); ?>
					<p class="description"><?php esc_html_e( 'How long the estimate page linked from the confirmation e-mail stays reachable. After that, visitors see the contact fallback below.', 'cybertech-estimator' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="<?php echo esc_attr( self::id( 'general', 'contact_page' ) ); ?>"><?php esc_html_e( 'Contact page URL', 'cybertech-estimator' ); ?></label></th>
				<td>
					<input type="url" class="regular-text code" id="<?php echo esc_attr( self::id( 'general', 'contact_page' ) ); ?>" name="<?php echo esc_attr( self::name( 'general', 'contact_page' ) ); ?>" value="<?php echo esc_attr( (string) $general['contact_page'] ); ?>" placeholder="https://">
					<p class="description"><?php esc_html_e( 'Where the no-JavaScript fallback and expired share links send people. Leave empty to use a mailto: link to the brand contact e-mail.', 'cybertech-estimator' ); ?></p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Abuse protection', 'cybertech-estimator' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Per-IP limits on the public endpoints. Defaults are generous for humans and tight for scripts.', 'cybertech-estimator' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="<?php echo esc_attr( self::id( 'security', 'preview_per_hour' ) ); ?>"><?php esc_html_e( 'Previews per hour', 'cybertech-estimator' ); ?></label></th>
				<td><?php self::number( 'security', 'preview_per_hour', (int) $security['preview_per_hour'], 1, 1000 ); ?></td>
			</tr>
			<tr>
				<th scope="row"><label for="<?php echo esc_attr( self::id( 'security', 'submit_per_hour' ) ); ?>"><?php esc_html_e( 'Submissions per hour', 'cybertech-estimator' ); ?></label></th>
				<td><?php self::number( 'security', 'submit_per_hour', (int) $security['submit_per_hour'], 1, 1000 ); ?></td>
			</tr>
			<tr>
				<th scope="row"><label for="<?php echo esc_attr( self::id( 'security', 'min_seconds' ) ); ?>"><?php esc_html_e( 'Minimum fill time', 'cybertech-estimator' ); ?></label></th>
				<td>
					<?php self::number( 'security', 'min_seconds', (int) $security['min_seconds'], 0, 120 ); ?> <?php esc_html_e( 'seconds', 'cybertech-estimator' ); ?>
					<p class="description"><?php esc_html_e( 'Submissions faster than this are rejected as bots (alongside the honeypot field).', 'cybertech-estimator' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
		submit_button();
		echo '</form>';
	}

	/**
	 * Pricing tab: pointer to the rate card editor + read-only summary.
	 */
	private function render_pricing(): void {
		$card = ( new RateCardRepository() )->load();
		$rows = [
			__( 'Current version', 'cybertech-estimator' ) => 'v' . $card->version(),
			__( 'Currency', 'cybertech-estimator' )        => $card->currency(),
			__( 'Blended rate', 'cybertech-estimator' )    => Money::format( (float) $card->get( 'blended_rate', 0 ), $card->currency() ) . ' / h',
		];
		?>
		<div class="ct-st-card">
			<h2><?php esc_html_e( 'Rate card', 'cybertech-estimator' ); ?></h2>
			<p><?php esc_html_e( 'Every number the engine uses — hourly rates, base hours per service line, factor multipliers, urgency, spread and the qualification weights — lives on the Rate card page. It is versioned: each save keeps a history you can roll back to, and every lead records the version it was priced with.', 'cybertech-estimator' ); ?></p>
			<table class="widefat striped ct-st-summary">
				<tbody>
					<?php foreach ( $rows as $label => $value ) : ?>
						<tr><th scope="row"><?php echo esc_html( $label ); ?></th><td><?php echo esc_html( $value ); ?></td></tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( self::PARENT . '&page=' . RateCardPage::SLUG ) ); ?>"><?php esc_html_e( 'Open the rate card', 'cybertech-estimator' ); ?></a>
				<a class="button" href="<?php echo esc_url( admin_url( self::PARENT . '&page=' . SandboxPage::SLUG ) ); ?>"><?php esc_html_e( 'Try it in the sandbox', 'cybertech-estimator' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * AI tab: provider config, model picker, budget guard and the status strip.
	 *
	 * @param array<string, mixed> $ai AI group.
	 */
	private function render_ai( array $ai ): void {
		$this->render_ai_status( $ai );
		$this->form_open( 'ai' );
		?>
		<h2><?php esc_html_e( 'Narration', 'cybertech-estimator' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'AI narration', 'cybertech-estimator' ); ?></th>
				<td>
					<?php self::checkbox( 'ai', 'enabled', (bool) $ai['enabled'], __( 'Enable AI narration', 'cybertech-estimator' ), __( 'Kill switch. When off — or when no key/model is set — every estimate uses the built-in fallback text and nothing is sent to a provider.', 'cybertech-estimator' ) ); ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="<?php echo esc_attr( self::id( 'ai', 'provider' ) ); ?>"><?php esc_html_e( 'Provider', 'cybertech-estimator' ); ?></label></th>
				<td>
					<select id="<?php echo esc_attr( self::id( 'ai', 'provider' ) ); ?>" name="<?php echo esc_attr( self::name( 'ai', 'provider' ) ); ?>">
						<?php foreach ( self::provider_choices() as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>"<?php selected( (string) $ai['provider'], $slug ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="<?php echo esc_attr( self::id( 'ai', 'api_key' ) ); ?>"><?php esc_html_e( 'API key', 'cybertech-estimator' ); ?></label></th>
				<td><?php self::secret_input( 'ai', 'api_key', (string) $ai['api_key'], __( 'Stored in the database, never printed. Leave the masked value untouched to keep the current key.', 'cybertech-estimator' ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><label for="<?php echo esc_attr( self::id( 'ai', 'model' ) ); ?>"><?php esc_html_e( 'Model', 'cybertech-estimator' ); ?></label></th>
				<td>
					<input type="text" class="regular-text code" id="<?php echo esc_attr( self::id( 'ai', 'model' ) ); ?>" name="<?php echo esc_attr( self::name( 'ai', 'model' ) ); ?>" value="<?php echo esc_attr( (string) $ai['model'] ); ?>" list="ct-est-models" placeholder="vendor/model-name" autocomplete="off" spellcheck="false">
					<datalist id="ct-est-models"></datalist>
					<button type="button" class="button" id="ct-est-models-refresh"><?php esc_html_e( 'Refresh model list', 'cybertech-estimator' ); ?></button>
					<p class="description" id="ct-est-models-status" role="status" aria-live="polite"><?php esc_html_e( 'No model is preset on purpose: refresh the list and pick one. Prices shown are USD per 1M tokens.', 'cybertech-estimator' ); ?></p>
					<p><?php self::checkbox( 'ai', 'floor', (bool) $ai['floor'], __( ':floor — route to the cheapest provider offering this model', 'cybertech-estimator' ) ); ?></p>
					<div class="ct-st-note">
						<strong><?php esc_html_e( 'About free models', 'cybertech-estimator' ); ?></strong>
						<p><?php esc_html_e( 'Slugs ending in :free cost nothing but are rate-limited by the provider (roughly 20 requests/minute and 200/day) and can be withdrawn without notice. They are fine for a demo; production should use a paid slug — the budget guard below keeps the bill predictable.', 'cybertech-estimator' ); ?></p>
					</div>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Limits and budget', 'cybertech-estimator' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="<?php echo esc_attr( self::id( 'ai', 'max_price' ) ); ?>"><?php esc_html_e( 'Max price', 'cybertech-estimator' ); ?></label></th>
				<td>
					$<?php self::number( 'ai', 'max_price', (float) $ai['max_price'], 0, 1000, '0.01' ); ?> <?php esc_html_e( 'per 1M tokens', 'cybertech-estimator' ); ?>
					<p class="description"><?php esc_html_e( 'Ceiling passed to the provider router; 0 = no ceiling.', 'cybertech-estimator' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="<?php echo esc_attr( self::id( 'ai', 'max_tokens' ) ); ?>"><?php esc_html_e( 'Max output tokens', 'cybertech-estimator' ); ?></label></th>
				<td>
					<?php self::number( 'ai', 'max_tokens', (int) $ai['max_tokens'], 50, 8000 ); ?>
					<p class="description"><?php esc_html_e( 'The narration is a few paragraphs; 700 is plenty and caps the cost of a runaway completion.', 'cybertech-estimator' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="<?php echo esc_attr( self::id( 'ai', 'timeout' ) ); ?>"><?php esc_html_e( 'Timeout', 'cybertech-estimator' ); ?></label></th>
				<td>
					<?php self::number( 'ai', 'timeout', (int) $ai['timeout'], 1, 60 ); ?> <?php esc_html_e( 'seconds', 'cybertech-estimator' ); ?>
					<p class="description"><?php esc_html_e( 'The visitor is never kept waiting longer than this; on timeout the fallback text is shown.', 'cybertech-estimator' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="<?php echo esc_attr( self::id( 'ai', 'monthly_budget_cents' ) ); ?>"><?php esc_html_e( 'Monthly budget', 'cybertech-estimator' ); ?></label></th>
				<td>
					<?php self::number( 'ai', 'monthly_budget_cents', (int) $ai['monthly_budget_cents'], 0, 10000000 ); ?> <?php esc_html_e( 'US cents', 'cybertech-estimator' ); ?>
					<p class="description"><?php esc_html_e( 'Once this month\'s recorded spend reaches the budget, narration switches to fallback text until the 1st. 500 = $5.00; 0 = unlimited.', 'cybertech-estimator' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="<?php echo esc_attr( self::id( 'ai', 'cache_days' ) ); ?>"><?php esc_html_e( 'Cache narrations for', 'cybertech-estimator' ); ?></label></th>
				<td>
					<?php self::number( 'ai', 'cache_days', (int) $ai['cache_days'], 0, 365 ); ?> <?php esc_html_e( 'days', 'cybertech-estimator' ); ?>
					<p class="description"><?php esc_html_e( 'Identical answer sets reuse the previous narration instead of paying for a new one. 0 disables the cache.', 'cybertech-estimator' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
		submit_button();
		echo '</form>';
	}

	/**
	 * Status strip: breaker, spend, cache. The element ids are the contract
	 * Phase 4 targets; the values shown here come straight from the stores
	 * that layer will write to.
	 *
	 * @param array<string, mixed> $ai AI group.
	 */
	private function render_ai_status( array $ai ): void {
		// Same stores the AI layer writes to; the class_exists() guards keep the page usable if that layer is ever removed.
		if ( class_exists( CircuitBreaker::class ) ) {
			$breaker = CircuitBreaker::state();
		} else {
			$breaker = get_transient( self::BREAKER_TRANSIENT );
			$breaker = is_array( $breaker ) ? $breaker : [];
		}
		$until    = (int) ( $breaker['open_until'] ?? 0 );
		$failures = (int) ( $breaker['failures'] ?? 0 );
		$open     = $until > time();

		if ( class_exists( BudgetGuard::class ) ) {
			$month  = BudgetGuard::month_key();
			$spent  = BudgetGuard::spent_cents();
			$budget = BudgetGuard::budget_cents();
		} else {
			$month  = gmdate( 'Y-m' );
			$spent  = (int) get_option( 'ct_est_ai_spend_' . $month, 0 );
			$budget = (int) $ai['monthly_budget_cents'];
		}
		$pct = $budget > 0 ? min( 100, (int) round( $spent / $budget * 100 ) ) : 0;

		$stats  = get_option( 'ct_est_ai_cache_stats', [] );
		$stats  = is_array( $stats ) ? $stats : [];
		$hits   = (int) ( $stats['hits'] ?? 0 );
		$misses = (int) ( $stats['misses'] ?? 0 );
		$total  = $hits + $misses;
		?>
		<div class="ct-st-status" aria-label="<?php esc_attr_e( 'AI status', 'cybertech-estimator' ); ?>">
			<div class="ct-st-stat<?php echo $open ? ' ct-st-stat--bad' : ' ct-st-stat--ok'; ?>" id="ct-est-ai-breaker" data-state="<?php echo $open ? 'open' : 'closed'; ?>">
				<span class="ct-st-stat__label"><?php esc_html_e( 'Circuit breaker', 'cybertech-estimator' ); ?></span>
				<span class="ct-st-stat__value" id="ct-est-ai-breaker-value">
					<?php
					if ( $open ) {
						esc_html_e( 'Open — calls paused', 'cybertech-estimator' );
					} else {
						esc_html_e( 'Closed — healthy', 'cybertech-estimator' );
					}
					?>
				</span>
				<span class="ct-st-stat__meta" id="ct-est-ai-breaker-meta">
					<?php
					if ( $open ) {
						/* translators: %s: date/time */
						echo esc_html( sprintf( __( 'Retries after %s.', 'cybertech-estimator' ), wp_date( 'Y-m-d H:i', $until ) ) );
					} elseif ( $failures > 0 ) {
						/* translators: %d: consecutive failures */
						echo esc_html( sprintf( __( '%d consecutive failure(s) recorded.', 'cybertech-estimator' ), $failures ) );
					} else {
						esc_html_e( 'Opens automatically after repeated provider failures.', 'cybertech-estimator' );
					}
					?>
				</span>
				<?php if ( $open || $failures > 0 ) : ?>
					<?php self::action_button( 'ai_breaker_reset', __( 'Reset', 'cybertech-estimator' ), 'button-small' ); ?>
				<?php endif; ?>
			</div>
			<div class="ct-st-stat<?php echo $budget > 0 && $pct >= 100 ? ' ct-st-stat--bad' : ''; ?>" id="ct-est-ai-spend" data-cents="<?php echo esc_attr( (string) $spent ); ?>" data-budget="<?php echo esc_attr( (string) $budget ); ?>" data-state="<?php echo esc_attr( $budget > 0 && $spent >= $budget ? 'exhausted' : 'ok' ); ?>">
				<span class="ct-st-stat__label">
					<?php
					/* translators: %s: month, e.g. 2026-08 */
					echo esc_html( sprintf( __( 'Spend %s', 'cybertech-estimator' ), $month ) );
					?>
				</span>
				<span class="ct-st-stat__value" id="ct-est-ai-spend-value">
					<?php
					if ( $budget > 0 ) {
						/* translators: 1: spent USD, 2: budget USD, 3: percent */
						echo esc_html( sprintf( __( '$%1$s of $%2$s (%3$d%%)', 'cybertech-estimator' ), number_format_i18n( $spent / 100, 2 ), number_format_i18n( $budget / 100, 2 ), $pct ) );
					} else {
						/* translators: %s: spent USD */
						echo esc_html( sprintf( __( '$%s (no budget)', 'cybertech-estimator' ), number_format_i18n( $spent / 100, 2 ) ) );
					}
					?>
				</span>
				<?php if ( $budget > 0 ) : ?>
					<progress class="ct-st-stat__bar" id="ct-est-ai-spend-bar" max="100" value="<?php echo esc_attr( (string) $pct ); ?>"></progress>
				<?php endif; ?>
			</div>
			<div class="ct-st-stat" id="ct-est-ai-cache" data-hits="<?php echo esc_attr( (string) $hits ); ?>" data-misses="<?php echo esc_attr( (string) $misses ); ?>">
				<span class="ct-st-stat__label"><?php esc_html_e( 'Narration cache', 'cybertech-estimator' ); ?></span>
				<span class="ct-st-stat__value">
					<?php
					/* translators: 1: hits, 2: misses */
					echo esc_html( sprintf( __( '%1$d hits / %2$d misses', 'cybertech-estimator' ), $hits, $misses ) );
					?>
				</span>
				<span class="ct-st-stat__meta">
					<?php
					if ( $total > 0 ) {
						/* translators: %d: percent */
						echo esc_html( sprintf( __( '%d%% served from cache', 'cybertech-estimator' ), (int) round( $hits / $total * 100 ) ) );
					} else {
						esc_html_e( 'No narrations requested yet.', 'cybertech-estimator' );
					}
					?>
				</span>
			</div>
		</div>
		<?php
	}

	/**
	 * Notifications tab.
	 *
	 * @param array<string, mixed> $n Notifications group.
	 */
	private function render_notifications( array $n ): void {
		$this->form_open( 'notifications' );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="<?php echo esc_attr( self::id( 'notifications', 'sales_email' ) ); ?>"><?php esc_html_e( 'Sales e-mail', 'cybertech-estimator' ); ?></label></th>
				<td>
					<input type="email" class="regular-text" id="<?php echo esc_attr( self::id( 'notifications', 'sales_email' ) ); ?>" name="<?php echo esc_attr( self::name( 'notifications', 'sales_email' ) ); ?>" value="<?php echo esc_attr( (string) $n['sales_email'] ); ?>" placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>">
					<p class="description"><?php esc_html_e( 'Receives the full breakdown for every new lead. Empty = the site admin e-mail.', 'cybertech-estimator' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Lead confirmation', 'cybertech-estimator' ); ?></th>
				<td><?php self::checkbox( 'notifications', 'send_confirmation', (bool) $n['send_confirmation'], __( 'E-mail the visitor their estimate link', 'cybertech-estimator' ), __( 'The share link is the only way back to a gated estimate, so keep this on unless you follow up manually.', 'cybertech-estimator' ) ); ?></td>
			</tr>
		</table>
		<?php
		submit_button();
		echo '</form>';
		?>
		<div class="ct-st-card">
			<h2><?php esc_html_e( 'Test delivery', 'cybertech-estimator' ); ?></h2>
			<p>
				<?php
				/* translators: %s: e-mail address */
				echo esc_html( sprintf( __( 'Sends a short plain-text message to %s through wp_mail(), the same path the lead alerts use. Save the address first if you just changed it.', 'cybertech-estimator' ), self::sales_email() ) );
				?>
			</p>
			<?php self::action_button( 'test_email', __( 'Send test e-mail', 'cybertech-estimator' ) ); ?>
		</div>
		<?php
	}

	/**
	 * Integrations tab: webhook config, test button + exchange dump, n8n snippet.
	 *
	 * @param array<string, mixed> $i Integrations group.
	 */
	private function render_integrations( array $i ): void {
		$this->form_open( 'integrations' );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="<?php echo esc_attr( self::id( 'integrations', 'webhook_url' ) ); ?>"><?php esc_html_e( 'Webhook URL', 'cybertech-estimator' ); ?></label></th>
				<td>
					<input type="url" class="regular-text code" id="<?php echo esc_attr( self::id( 'integrations', 'webhook_url' ) ); ?>" name="<?php echo esc_attr( self::name( 'integrations', 'webhook_url' ) ); ?>" value="<?php echo esc_attr( (string) $i['webhook_url'] ); ?>" placeholder="https://n8n.example.com/webhook/estimator">
					<p class="description"><?php esc_html_e( 'Every new lead is POSTed here as JSON (asynchronously, 3 retries with backoff). https:// only — plain http:// is accepted just for 127.0.0.1 / localhost. Empty disables the webhook.', 'cybertech-estimator' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="<?php echo esc_attr( self::id( 'integrations', 'webhook_secret' ) ); ?>"><?php esc_html_e( 'Shared secret', 'cybertech-estimator' ); ?></label></th>
				<td><?php self::secret_input( 'integrations', 'webhook_secret', (string) $i['webhook_secret'], __( 'Used to sign each request (X-CT-Signature: sha256=HMAC-SHA256 of the raw body). Empty = unsigned.', 'cybertech-estimator' ) ); ?></td>
			</tr>
		</table>
		<?php
		submit_button();
		echo '</form>';
		?>
		<div class="ct-st-card">
			<h2><?php esc_html_e( 'Test the webhook', 'cybertech-estimator' ); ?></h2>
			<p><?php esc_html_e( 'Sends a sample estimate.created payload (lead_id 0, "test": true) to the saved URL right now and shows the full exchange below.', 'cybertech-estimator' ); ?></p>
			<?php self::action_button( 'webhook_test', __( 'Send test payload', 'cybertech-estimator' ), 'button-secondary', '' === trim( (string) $i['webhook_url'] ) ); ?>
			<?php $this->render_webhook_result(); ?>
		</div>
		<div class="ct-st-card">
			<details class="ct-st-details">
				<summary><?php esc_html_e( 'Verify the signature in n8n', 'cybertech-estimator' ); ?></summary>
				<p><?php esc_html_e( 'Put this in a Code (Function) node placed right after the Webhook node. Set the Webhook node to "Raw Body" so the exact bytes we signed are available, and keep the secret in an n8n credential or environment variable.', 'cybertech-estimator' ); ?></p>
				<pre class="ct-st-pre"><code><?php echo esc_html( self::n8n_snippet() ); ?></code></pre>
			</details>
		</div>
		<?php
	}

	/**
	 * The stashed exchange from the last "Send test payload" click (read once).
	 */
	private function render_webhook_result(): void {
		$key    = self::WEBHOOK_KEY . get_current_user_id();
		$result = get_transient( $key );
		if ( ! is_array( $result ) ) {
			return;
		}
		delete_transient( $key );
		$request = is_array( $result['request'] ?? null ) ? $result['request'] : [];
		$headers = is_array( $request['headers'] ?? null ) ? $request['headers'] : [];
		$body    = json_decode( (string) ( $request['body'] ?? '' ), true );
		$pretty  = is_array( $body ) ? (string) wp_json_encode( $body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : (string) ( $request['body'] ?? '' );
		$lines   = [];
		foreach ( $headers as $name => $value ) {
			$lines[] = $name . ': ' . $value;
		}
		$ok = ! empty( $result['ok'] );
		?>
		<div class="ct-st-exchange" id="ct-est-webhook-result">
			<h3>
				<?php esc_html_e( 'Request', 'cybertech-estimator' ); ?>
				<code><?php echo esc_html( 'POST ' . (string) ( $request['url'] ?? '' ) ); ?></code>
			</h3>
			<pre class="ct-st-pre"><?php echo esc_html( implode( "\n", $lines ) ); ?></pre>
			<pre class="ct-st-pre"><?php echo esc_html( $pretty ); ?></pre>
			<h3>
				<?php esc_html_e( 'Response', 'cybertech-estimator' ); ?>
				<span class="ct-st-badge <?php echo $ok ? 'ct-st-badge--ok' : 'ct-st-badge--bad'; ?>">
					<?php
					if ( (int) $result['status'] > 0 ) {
						echo esc_html( 'HTTP ' . (int) $result['status'] );
					} else {
						esc_html_e( 'No response', 'cybertech-estimator' );
					}
					?>
				</span>
			</h3>
			<?php if ( '' !== (string) $result['error'] ) : ?>
				<pre class="ct-st-pre ct-st-pre--error"><?php echo esc_html( (string) $result['error'] ); ?></pre>
			<?php else : ?>
				<pre class="ct-st-pre"><?php echo '' !== (string) $result['body'] ? esc_html( (string) $result['body'] ) : esc_html__( '(empty body)', 'cybertech-estimator' ); ?></pre>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The n8n Code-node snippet. Kept as a plain string so it is copy-pasteable verbatim.
	 */
	private static function n8n_snippet(): string {
		return <<<'JS'
// n8n Code node (JavaScript, "Run Once for All Items"), directly after the Webhook node.
// Webhook node → Options → "Raw Body" must be ON so `rawBody` holds the exact bytes we signed.
const crypto = require('crypto');

const SECRET = $env.CT_EST_WEBHOOK_SECRET;   // the shared secret from Estimator → Settings → Integrations
const MAX_AGE_SECONDS = 5 * 60;

const req = $input.first().json;
const headers = req.headers || {};
const rawBody = Buffer.isBuffer(req.rawBody) ? req.rawBody : Buffer.from(req.rawBody || '', 'utf8');

// 1) Freshness: reject anything older than 5 minutes (the timestamp is also inside the signed body).
const ts = parseInt(headers['x-ct-timestamp'], 10);
if (!Number.isFinite(ts) || Math.abs(Date.now() / 1000 - ts) > MAX_AGE_SECONDS) {
  throw new Error('Webhook rejected: stale or missing X-CT-Timestamp');
}

// 2) Signature: sha256= + HMAC-SHA256(rawBody, secret), compared in constant time.
const expected = 'sha256=' + crypto.createHmac('sha256', SECRET).update(rawBody).digest('hex');
const given = String(headers['x-ct-signature'] || '');
const a = Buffer.from(expected, 'utf8');
const b = Buffer.from(given, 'utf8');
if (a.length !== b.length || !crypto.timingSafeEqual(a, b)) {
  throw new Error('Webhook rejected: bad X-CT-Signature');
}

// Verified — pass the parsed payload on.
return [{ json: JSON.parse(rawBody.toString('utf8')) }];
JS;
	}

	/**
	 * Privacy tab. store_ip lives in the security group but belongs here
	 * conceptually; the sanitizer merges per field so the split is harmless.
	 *
	 * @param array<string, mixed> $privacy  Privacy group.
	 * @param array<string, mixed> $security Security group.
	 */
	private function render_privacy( array $privacy, array $security ): void {
		$this->form_open( 'privacy' );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="<?php echo esc_attr( self::id( 'privacy', 'retention_days' ) ); ?>"><?php esc_html_e( 'Keep leads for', 'cybertech-estimator' ); ?></label></th>
				<td>
					<?php self::number( 'privacy', 'retention_days', (int) $privacy['retention_days'], 7, 3650 ); ?> <?php esc_html_e( 'days', 'cybertech-estimator' ); ?>
					<p class="description"><?php esc_html_e( 'A daily job deletes leads older than this, contact details included. Won/lost status does not exempt a lead — export what you need to your CRM via the webhook.', 'cybertech-estimator' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Visitor IP', 'cybertech-estimator' ); ?></th>
				<td><?php self::checkbox( 'security', 'store_ip', (bool) $security['store_ip'], __( 'Store a hashed IP address on each lead', 'cybertech-estimator' ), __( 'Only a salted SHA-256 hash is kept (the same one the rate limiter uses), so abuse from one address can be correlated without storing the address itself. Off by default.', 'cybertech-estimator' ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Uninstall', 'cybertech-estimator' ); ?></th>
				<td><?php self::checkbox( 'privacy', 'delete_leads_on_uninstall', (bool) $privacy['delete_leads_on_uninstall'], __( 'Delete all leads and settings when the plugin is uninstalled', 'cybertech-estimator' ), __( 'Deactivating never deletes anything; this only applies to "Delete" from the Plugins screen.', 'cybertech-estimator' ) ); ?></td>
			</tr>
		</table>
		<?php
		submit_button();
		echo '</form>';
		?>
		<div class="ct-st-note">
			<strong><?php esc_html_e( 'Data subject requests', 'cybertech-estimator' ); ?></strong>
			<p>
				<?php
				printf(
					/* translators: 1: link to Export Personal Data, 2: link to Erase Personal Data */
					esc_html__( 'Leads are registered with the WordPress privacy tools: use %1$s and %2$s under Tools to answer access or erasure requests by e-mail address.', 'cybertech-estimator' ),
					'<a href="' . esc_url( admin_url( 'export-personal-data.php' ) ) . '">' . esc_html__( 'Export Personal Data', 'cybertech-estimator' ) . '</a>',
					'<a href="' . esc_url( admin_url( 'erase-personal-data.php' ) ) . '">' . esc_html__( 'Erase Personal Data', 'cybertech-estimator' ) . '</a>'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Diagnostics tab: environment, log ring, demo data placeholders.
	 */
	private function render_diagnostics(): void {
		$card       = ( new RateCardRepository() )->load();
		$retention  = wp_next_scheduled( 'ct_est_retention_daily' );
		$pending    = self::pending_cron_events( WebhookDispatcher::HOOK );
		$cron_off   = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$seed_ready = has_action( 'admin_post_ct_est_demo_seed' ) && has_action( 'admin_post_ct_est_demo_remove' );
		$rows       = [
			__( 'Plugin version', 'cybertech-estimator' ) => CT_EST_VERSION,
			__( 'WordPress', 'cybertech-estimator' )      => get_bloginfo( 'version' ),
			__( 'PHP', 'cybertech-estimator' )            => PHP_VERSION,
			__( 'Rate card version', 'cybertech-estimator' ) => 'v' . $card->version() . ' (' . $card->currency() . ')',
			__( 'WP-Cron', 'cybertech-estimator' )        => $cron_off ? __( 'DISABLE_WP_CRON is set — make sure a system cron hits wp-cron.php', 'cybertech-estimator' ) : __( 'Enabled (runs on page loads)', 'cybertech-estimator' ),
			__( 'Retention job', 'cybertech-estimator' )  => $retention
				/* translators: %s: date/time */
				? sprintf( __( 'Next run %s', 'cybertech-estimator' ), wp_date( 'Y-m-d H:i:s', (int) $retention ) )
				: __( 'Not scheduled', 'cybertech-estimator' ),
			__( 'Webhook queue', 'cybertech-estimator' )  => sprintf(
				/* translators: %d: number of events */
				_n( '%d pending delivery', '%d pending deliveries', $pending, 'cybertech-estimator' ),
				$pending
			),
			__( 'AI narration', 'cybertech-estimator' )   => Settings::get( 'ai.enabled' ) ? __( 'Enabled', 'cybertech-estimator' ) : __( 'Disabled (fallback text)', 'cybertech-estimator' ),
		];
		?>
		<h2><?php esc_html_e( 'Environment', 'cybertech-estimator' ); ?></h2>
		<table class="widefat striped ct-st-summary">
			<tbody>
				<?php foreach ( $rows as $label => $value ) : ?>
					<tr><th scope="row"><?php echo esc_html( $label ); ?></th><td><?php echo esc_html( (string) $value ); ?></td></tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<div class="ct-st-toolbar">
			<h2><?php esc_html_e( 'Recent log', 'cybertech-estimator' ); ?></h2>
			<?php self::action_button( 'log_clear', __( 'Clear log', 'cybertech-estimator' ) ); ?>
		</div>
		<?php $this->render_log(); ?>

		<h2><?php esc_html_e( 'Demo data', 'cybertech-estimator' ); ?></h2>
		<p class="description">
			<?php
			if ( $seed_ready ) {
				esc_html_e( 'Seed a realistic set of leads to try the list, filters and share pages; remove them again with one click.', 'cybertech-estimator' );
			} else {
				esc_html_e( 'The demo seeder ships in Phase 5; these buttons will activate then.', 'cybertech-estimator' );
			}
			?>
		</p>
		<div class="ct-st-actions">
			<?php self::action_button( 'demo_seed', __( 'Seed demo leads', 'cybertech-estimator' ), 'button-secondary', ! $seed_ready, 'ct-est-demo-seed', 'ct_est_demo_seed' ); ?>
			<?php self::action_button( 'demo_remove', __( 'Remove demo leads', 'cybertech-estimator' ), 'button-secondary', ! $seed_ready, 'ct-est-demo-remove', 'ct_est_demo_remove' ); ?>
		</div>
		<?php
	}

	/**
	 * Last N log rows as a table.
	 */
	private function render_log(): void {
		$rows = Logger::recent( self::LOG_ROWS );
		if ( ! $rows ) {
			echo '<p class="description">' . esc_html__( 'Nothing logged yet.', 'cybertech-estimator' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped ct-st-log" id="ct-est-log">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Time', 'cybertech-estimator' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Channel', 'cybertech-estimator' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Message', 'cybertech-estimator' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Context', 'cybertech-estimator' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<?php $context = (array) ( $row['context'] ?? [] ); ?>
					<tr>
						<td class="ct-st-log__time"><?php echo esc_html( wp_date( 'Y-m-d H:i:s', (int) ( $row['ts'] ?? 0 ) ) ); ?></td>
						<td><span class="ct-st-chip ct-st-chip--<?php echo esc_attr( sanitize_html_class( (string) ( $row['channel'] ?? '' ) ) ); ?>"><?php echo esc_html( (string) ( $row['channel'] ?? '' ) ); ?></span></td>
						<td><?php echo esc_html( (string) ( $row['message'] ?? '' ) ); ?></td>
						<td><code class="ct-st-log__context"><?php echo $context ? esc_html( (string) wp_json_encode( $context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) : '—'; ?></code></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Number of queued single events for a hook.
	 *
	 * @param string $hook Cron hook.
	 */
	private static function pending_cron_events( string $hook ): int {
		$count = 0;
		$crons = _get_cron_array();
		foreach ( is_array( $crons ) ? $crons : [] as $events ) {
			if ( isset( $events[ $hook ] ) && is_array( $events[ $hook ] ) ) {
				$count += count( $events[ $hook ] );
			}
		}
		return $count;
	}
}
