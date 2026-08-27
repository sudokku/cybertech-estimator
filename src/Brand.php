<?php
/**
 * The single white-label configuration point.
 *
 * Every brand-specific string, colour and asset the plugin renders comes
 * from here. To re-skin the plugin for another agency, edit this file (or
 * filter `ct_est_brand`) — nothing else references the brand by name.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator;

/**
 * White-label brand configuration.
 */
final class Brand {

	/**
	 * Raw brand values. Colours are the client's live-site tokens.
	 *
	 * @return array<string, string>
	 */
	private static function defaults(): array {
		return [
			'company'         => 'Cybertech',
			'legal_name'      => 'ALANTIS WEB STUDIO S.R.L.',
			'tagline'         => 'Navigating the digital ocean since 1999',
			'website'         => 'https://cybertech.ro',
			'contact_email'   => 'office@cybertech.ro',
			'contact_phone'   => '+40 723 168 188',
			'logo'            => CT_EST_URL . 'assets/img/logo-white.png',
			'logo_alt'        => 'Cybertech',
			'color_primary'   => '#1C67FA',
			'color_accent'    => '#4ECEEE',
			'color_ink'       => '#1F1F25',
			'color_dark'      => '#191919',
			'color_bg'        => '#FFFFFF',
			'font_heading'    => 'Montserrat',
			'font_body'       => 'Poppins',
			'consent_text'    => 'I agree that Cybertech may store the information above to prepare and follow up on this estimate. See the Privacy Policy.',
			'consent_version' => '2026-08-27',
		];
	}

	/**
	 * Fetch one brand value, filterable via `ct_est_brand`.
	 *
	 * @param string $key Brand key.
	 * @return string
	 */
	public static function get( string $key ): string {
		/**
		 * Filters the brand configuration map.
		 *
		 * @param array<string, string> $brand Brand values.
		 */
		$brand = apply_filters( 'ct_est_brand', self::defaults() );
		return (string) ( $brand[ $key ] ?? '' );
	}

	/**
	 * Whole brand map (for templates that need several values).
	 *
	 * @return array<string, string>
	 */
	public static function all(): array {
		return apply_filters( 'ct_est_brand', self::defaults() );
	}
}
