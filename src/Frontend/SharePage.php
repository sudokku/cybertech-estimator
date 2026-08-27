<?php
/**
 * Public, shareable estimate page: `/estimate/{token}`.
 *
 * Renders a standalone document (no theme, no wp_head/wp_footer) from the
 * lead's immutable snapshot. Never exposes the visitor's email or phone,
 * never indexes, never caches, and in `band` reveal mode never prints a
 * figure (D3 in docs/PLAN.md).
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Frontend;

use Cybertech\Estimator\Ai\FallbackNarrative;
use Cybertech\Estimator\Ai\NarrativeService;
use Cybertech\Estimator\Brand;
use Cybertech\Estimator\Engine\EstimateResult;
use Cybertech\Estimator\Engine\RateCardDefaults;
use Cybertech\Estimator\Lead\LeadRepository;
use Cybertech\Estimator\Lead\ShareToken;
use Cybertech\Estimator\Support\Money;
use Cybertech\Estimator\Support\Settings;

/**
 * Share page route + renderer.
 */
final class SharePage {

	public const QUERY_VAR = 'ct_est_share';

	/**
	 * Hook up the route. Rewrite rules are NOT flushed here — Activator does
	 * that once on activation.
	 */
	public function register(): void {
		add_action( 'init', [ $this, 'add_rewrite_rule' ] );
		add_filter( 'query_vars', [ $this, 'add_query_var' ] );
		// Priority 0: run before redirect_canonical(), which would otherwise
		// try to "guess" a permalink for what WP thinks is a 404.
		add_action( 'template_redirect', [ $this, 'maybe_render' ], 0 );
	}

	/**
	 * `/estimate/{32-char token}/` → index.php?ct_est_share={token}.
	 */
	public function add_rewrite_rule(): void {
		add_rewrite_rule( '^estimate/([A-Za-z0-9]{32})/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
	}

	/**
	 * Register the query var.
	 *
	 * @param array<int, string> $vars Public query vars.
	 * @return array<int, string>
	 */
	public function add_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Render and exit when the share var is present.
	 */
	public function maybe_render(): void {
		$token = (string) get_query_var( self::QUERY_VAR, '' );
		if ( '' === $token ) {
			return;
		}

		$lead_id = ShareToken::find_lead( $token );
		$state   = ShareToken::state( $lead_id );

		$this->send_headers( 'missing' === $state ? 404 : 200 );

		if ( 'ok' === $state ) {
			$data = $this->build_data( $lead_id, $token );
			if ( null === $data ) {
				// Snapshot missing or corrupt: treat as unavailable, not as an error page.
				$state = 'disabled';
			} else {
				$this->render_document( 'estimate', $data );
				exit;
			}
		}

		$this->render_document( 'unavailable', $this->unavailable_data( $state ) );
		exit;
	}

	/**
	 * Security / privacy headers. Sent before any output.
	 *
	 * @param int $status HTTP status.
	 */
	private function send_headers( int $status ): void {
		status_header( $status );
		nocache_headers();
		header( 'Cache-Control: private, no-store' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset', 'UTF-8' ) );
	}

	/**
	 * Template data for a live share link.
	 *
	 * @param int    $lead_id Lead id.
	 * @param string $token   Token (for the share URL).
	 * @return array<string, mixed>|null Null when the snapshot cannot be read.
	 */
	private function build_data( int $lead_id, string $token ): ?array {
		$repo   = new LeadRepository();
		$result = $repo->result( $lead_id );
		if ( ! $result ) {
			return null;
		}
		$card = $repo->rate_card( $lead_id );

		$labels = get_post_meta( $lead_id, LeadRepository::META_LABELS, true );
		$labels = is_array( $labels ) ? $labels : [];

		$mode = (string) get_post_meta( $lead_id, LeadRepository::META_MODE, true );
		if ( ! in_array( $mode, [ 'open', 'band', 'gated' ], true ) ) {
			$mode = Settings::reveal_mode();
		}
		$show_figures = 'band' !== $mode;

		$service_label = '';
		if ( $card ) {
			$service_label = (string) $card->get( 'service_lines.' . $result->service_line . '.label', '' );
		}
		if ( '' === $service_label ) {
			$service_label = (string) ( $labels['service_line']['value'] ?? $result->service_line );
		}

		$contact = $repo->contact( $lead_id );
		$first   = trim( (string) strtok( trim( $contact['name'] ), " \t\n" ) );
		$company = trim( $contact['company'] );

		$team = RevealPolicy::team_labels( $result );

		$created = (int) get_post_time( 'U', true, $lead_id );
		$expires = (int) get_post_meta( $lead_id, ShareToken::META_EXPIRES, true );
		$format  = (string) get_option( 'date_format', 'j F Y' );

		return [
			'lead_id'           => $lead_id,
			'state'             => 'ok',
			'mode'              => $mode,
			'show_figures'      => $show_figures,
			'range_text'        => $show_figures ? Money::range( $result->price_low, $result->price_high, $result->currency ) : '',
			'band_label'        => $result->band_label,
			'weeks'             => $result->weeks,
			'weeks_text'        => sprintf(
				/* translators: %d: number of weeks */
				_n( '%d week', '%d weeks', $result->weeks, 'cybertech-estimator' ),
				$result->weeks
			),
			'hours'             => (int) round( $result->hours ),
			'team'              => $team,
			'team_size'         => count( $team ),
			'role_labels'       => RateCardDefaults::role_labels(),
			'service_label'     => $service_label,
			'labels'            => $this->public_labels( $labels, $show_figures ),
			'narrative'         => $this->narrative( $lead_id, $result, $labels, $card ),
			'ai_status'         => (string) get_post_meta( $lead_id, LeadRepository::META_AI_STATUS, true ),
			'contact_name'      => $first,
			'company'           => $company,
			'prepared_for'      => $this->prepared_for( $first, $company ),
			'brand'             => Brand::all(),
			'created'           => $created > 0 ? wp_date( $format, $created ) : '',
			'expires'           => $expires > 0 ? wp_date( $format, $expires ) : '',
			'contact_url'       => (string) Settings::get( 'general.contact_page' ),
			'share_url'         => ShareToken::url( $token ),
			'rate_card_version' => $result->rate_card_version,
		];
	}

	/**
	 * Template data for the expired / disabled / missing page.
	 *
	 * @param string $state expired | disabled | missing.
	 * @return array<string, mixed>
	 */
	private function unavailable_data( string $state ): array {
		return [
			'state'       => $state,
			'brand'       => Brand::all(),
			'contact_url' => (string) Settings::get( 'general.contact_page' ),
			'share_url'   => '',
		];
	}

	/**
	 * Answer labels safe for the public page. Free text is dropped (visitors
	 * paste phone numbers and emails there); in band mode the budget answer is
	 * dropped too so no currency figure appears anywhere in the document.
	 *
	 * @param array<string, array{label: string, value: string}> $labels       Resolved labels.
	 * @param bool                                               $show_figures False in band mode.
	 * @return array<string, array{label: string, value: string}>
	 */
	private function public_labels( array $labels, bool $show_figures ): array {
		$out = [];
		foreach ( $labels as $id => $row ) {
			if ( 'notes' === $id || ( ! $show_figures && 'budget' === $id ) ) {
				continue;
			}
			if ( ! is_array( $row ) || '' === trim( (string) ( $row['value'] ?? '' ) ) ) {
				continue;
			}
			$out[ (string) $id ] = [
				'label' => (string) ( $row['label'] ?? $id ),
				'value' => (string) $row['value'],
			];
		}
		return $out;
	}

	/**
	 * Stored narrative, or a fallback built on the fly (never persisted and
	 * never calling the AI provider — a public page must not spend budget).
	 *
	 * @param int                                       $lead_id Lead id.
	 * @param EstimateResult                            $result  Result.
	 * @param array<string, array<string, string>>      $labels  Resolved labels.
	 * @param \Cybertech\Estimator\Engine\RateCard|null $card Snapshotted card.
	 * @return array{headline: string, summary: string, phases: array<int, array<string, mixed>>, assumptions: array<int, string>, risks: array<int, string>}
	 */
	private function narrative( int $lead_id, EstimateResult $result, array $labels, $card ): array {
		$stored = get_post_meta( $lead_id, LeadRepository::META_NARRATIVE, true );
		if ( ( ! is_array( $stored ) || empty( $stored['phases'] ) ) && $card ) {
			$locale = (string) get_post_meta( $lead_id, LeadRepository::META_LOCALE, true );
			$stored = FallbackNarrative::build( NarrativeService::facts( $result, $card, $labels, '' !== $locale ? $locale : get_locale() ) );
		}
		$stored = is_array( $stored ) ? $stored : [];

		$phases = [];
		foreach ( (array) ( $stored['phases'] ?? [] ) as $phase ) {
			if ( ! is_array( $phase ) ) {
				continue;
			}
			$roles = [];
			foreach ( (array) ( $phase['roles'] ?? [] ) as $role ) {
				$role = trim( (string) $role );
				if ( '' !== $role ) {
					$roles[] = $role;
				}
			}
			$phases[] = [
				'name'        => (string) ( $phase['name'] ?? '' ),
				'weeks'       => max( 0, (int) ( $phase['weeks'] ?? 0 ) ),
				'description' => (string) ( $phase['description'] ?? '' ),
				'roles'       => $roles,
			];
		}

		$list = static function ( $items ): array {
			$out = [];
			foreach ( (array) $items as $item ) {
				$item = trim( (string) $item );
				if ( '' !== $item ) {
					$out[] = $item;
				}
			}
			return $out;
		};

		return [
			'headline'    => (string) ( $stored['headline'] ?? '' ),
			'summary'     => (string) ( $stored['summary'] ?? '' ),
			'phases'      => $phases,
			'assumptions' => $list( $stored['assumptions'] ?? [] ),
			'risks'       => $list( $stored['risks'] ?? [] ),
		];
	}

	/**
	 * "Prepared for" line: first name and/or company, never contact details.
	 *
	 * @param string $first   First name.
	 * @param string $company Company.
	 */
	private function prepared_for( string $first, string $company ): string {
		if ( '' !== $first && '' !== $company ) {
			/* translators: 1: first name, 2: company name */
			return sprintf( __( '%1$s at %2$s', 'cybertech-estimator' ), $first, $company );
		}
		return '' !== $company ? $company : $first;
	}

	/**
	 * Stylesheets for the standalone document. Filterable so a theme can add
	 * its own font stylesheet (the plugin loads no web fonts — GDPR).
	 *
	 * @return array<int, array{href: string, media: string}>
	 */
	private function stylesheets(): array {
		$styles = [
			[
				'href'  => CT_EST_URL . 'assets/css/tokens.css',
				'media' => 'all',
			],
			[
				'href'  => CT_EST_URL . 'assets/css/share.css',
				'media' => 'all',
			],
			[
				'href'  => CT_EST_URL . 'assets/css/share-print.css',
				'media' => 'print',
			],
		];

		/**
		 * Filters the stylesheets printed on the share page.
		 *
		 * @param array<int, array{href: string, media: string}> $styles Stylesheets.
		 */
		$styles = apply_filters( 'ct_est_share_styles', $styles );
		return is_array( $styles ) ? $styles : [];
	}

	/**
	 * Print the whole HTML document around a body template.
	 *
	 * @param string               $template estimate | unavailable.
	 * @param array<string, mixed> $data     Template data.
	 */
	private function render_document( string $template, array $data ): void {
		$brand = (array) $data['brand'];
		if ( 'estimate' === $template ) {
			$title = sprintf(
				/* translators: 1: brand company name, 2: service line label */
				__( 'Project estimate — %2$s · %1$s', 'cybertech-estimator' ),
				(string) $brand['company'],
				(string) $data['service_label']
			);
		} else {
			$title = sprintf(
				/* translators: %s: brand company name */
				__( 'Estimate unavailable · %s', 'cybertech-estimator' ),
				(string) $brand['company']
			);
		}
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php echo esc_attr( get_option( 'blog_charset', 'UTF-8' ) ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<meta name="referrer" content="no-referrer">
	<meta name="color-scheme" content="light">
	<meta name="theme-color" content="<?php echo esc_attr( (string) $brand['color_dark'] ); ?>">
	<title><?php echo esc_html( $title ); ?></title>
		<?php foreach ( $this->stylesheets() as $style ) : ?>
	<link rel="stylesheet" href="<?php echo esc_url( add_query_arg( 'ver', CT_EST_VERSION, (string) $style['href'] ) ); ?>" media="<?php echo esc_attr( (string) ( $style['media'] ?? 'all' ) ); ?>"><?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Standalone document without wp_head(); nothing can be enqueued. ?>
		<?php endforeach; ?>
</head>
<body class="ct-share ct-share--<?php echo esc_attr( $template ); ?>">
		<?php $this->include_template( $template, $data ); ?>
</body>
</html>
		<?php
	}

	/**
	 * Include templates/share/{name}.php with `$data` in scope.
	 *
	 * @param string               $name Template name.
	 * @param array<string, mixed> $data Template data.
	 */
	private function include_template( string $name, array $data ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $data is read by the included template.
		$file = CT_EST_DIR . 'templates/share/' . $name . '.php';
		if ( is_readable( $file ) ) {
			include $file;
		}
	}
}
