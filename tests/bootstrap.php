<?php
/**
 * PHPUnit bootstrap. The engine is WordPress-free; the handful of WP
 * functions used by validators/guards are stubbed here so tests run in
 * milliseconds without a WP test install.
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/src/Support/Autoloader.php';
\Cybertech\Estimator\Support\Autoloader::register( dirname( __DIR__ ) . '/src' );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'CT_EST_VERSION' ) ) {
	define( 'CT_EST_VERSION', 'test' );
}
if ( ! defined( 'CT_EST_URL' ) ) {
	define( 'CT_EST_URL', 'https://example.test/wp-content/plugins/cybertech-estimator/' );
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string { // phpcs:ignore
		return $text;
	}
}
if ( ! function_exists( '_x' ) ) {
	function _x( string $text, string $context, string $domain = 'default' ): string { // phpcs:ignore
		return $text;
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = 'default' ): string { // phpcs:ignore
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( string $text, bool $remove_breaks = false ): string {
		$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $text ) ?? '';
		$text = strip_tags( $text );
		if ( $remove_breaks ) {
			$text = preg_replace( '/[\r\n\t ]+/', ' ', $text ) ?? '';
		}
		return trim( $text );
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $data, int $flags = 0, int $depth = 512 ): string|false {
		return json_encode( $data, $flags, $depth ); // phpcs:ignore
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed { // phpcs:ignore
		return $value;
	}
}
if ( ! function_exists( 'wp_hash' ) ) {
	function wp_hash( string $data, string $scheme = 'auth' ): string { // phpcs:ignore
		return hash_hmac( 'md5', $data, 'test-salt' );
	}
}
