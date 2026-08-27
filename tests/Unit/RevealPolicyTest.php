<?php
/**
 * RevealPolicy tests: what leaves the server per reveal mode.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Tests\Unit;

use Cybertech\Estimator\Engine\EstimateResult;
use Cybertech\Estimator\Frontend\RevealPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Reveal policy tests.
 */
final class RevealPolicyTest extends TestCase {

	/**
	 * A result with the worked-example figures and a deliberately awkward team.
	 *
	 * @param int $weeks Weeks.
	 */
	private static function sample( int $weeks = 18 ): EstimateResult {
		return new EstimateResult(
			'web',
			538.31,
			25144.46,
			20000.0,
			30000.0,
			'EUR',
			$weeks,
			[
				'band_index' => 2,
				'source'     => 'team_bands.web.2',
				'roles'      => [
					'pm'     => [
						'share' => 0.15,
						'hours' => 80.75,
						'rate'  => 40.0,
					],
					'sse'    => [
						'share' => 0.25,
						'hours' => 134.58,
						'rate'  => 55.0,
					],
					'design' => [
						'share' => 0.00091,
						'hours' => 0.49,
						'rate'  => 40.0,
					],
					'qa'     => [
						'share' => 0.00093,
						'hours' => 0.5,
						'rate'  => 35.0,
					],
					'ops'    => [
						'share' => 0.01,
						'hours' => 5.4,
						'rate'  => 45.0,
					],
				],
			],
			46.71,
			'mid',
			'Mid-size engagement',
			100,
			[ 'budget' => 40 ],
			[ [ 'step' => 'base_hours' ] ],
			[ 'service_line' => 'web' ],
			1
		);
	}

	private const FIGURES = [ 'currency', 'price_low', 'price_high', 'range_text', 'hours' ];

	public function test_gated_and_locked_carries_nothing_but_the_gate(): void {
		$payload = RevealPolicy::visitor_payload( self::sample(), 'gated', false );
		$this->assertSame(
			[
				'mode'     => 'gated',
				'unlocked' => false,
				'ready'    => true,
			],
			$payload
		);
		$this->assertSame( 0, preg_match( '/\d/', (string) json_encode( $payload ) ), 'not a single digit leaves the server' );
	}

	public function test_band_mode_has_timeline_and_band_but_no_figures(): void {
		$payload = RevealPolicy::visitor_payload( self::sample(), 'band', false );
		$this->assertSame( [ 'mode', 'unlocked', 'ready', 'weeks', 'weeks_text', 'team', 'band', 'band_label' ], array_keys( $payload ) );
		foreach ( self::FIGURES as $key ) {
			$this->assertArrayNotHasKey( $key, $payload );
		}
		$this->assertSame( 'band', $payload['mode'] );
		$this->assertFalse( $payload['unlocked'] );
		$this->assertSame( 18, $payload['weeks'] );
		$this->assertSame( '18 weeks', $payload['weeks_text'] );
		$this->assertSame( 'mid', $payload['band'] );
		$this->assertSame( 'Mid-size engagement', $payload['band_label'] );

		$json = (string) json_encode( $payload );
		foreach ( [ '20000', '30000', '25144', '538', '46.71', '€', 'EUR', 'price', '"hours":538' ] as $leak ) {
			$this->assertStringNotContainsString( $leak, $json, "band payload leaks {$leak}" );
		}
	}

	public function test_band_mode_stays_figure_free_even_when_unlocked(): void {
		$payload = RevealPolicy::visitor_payload( self::sample(), 'band', true );
		$this->assertTrue( $payload['unlocked'] );
		foreach ( self::FIGURES as $key ) {
			$this->assertArrayNotHasKey( $key, $payload );
		}
	}

	#[DataProvider( 'figures_mode_provider' )]
	public function test_open_and_unlocked_gated_carry_the_figures( string $mode, bool $unlocked ): void {
		$payload = RevealPolicy::visitor_payload( self::sample(), $mode, $unlocked );
		$this->assertSame(
			[ 'mode', 'unlocked', 'ready', 'weeks', 'weeks_text', 'team', 'band', 'band_label', 'currency', 'price_low', 'price_high', 'range_text', 'hours' ],
			array_keys( $payload )
		);
		$this->assertSame( $mode, $payload['mode'] );
		$this->assertSame( $unlocked, $payload['unlocked'] );
		$this->assertTrue( $payload['ready'] );
		$this->assertSame( 'EUR', $payload['currency'] );
		$this->assertSame( 20000.0, $payload['price_low'] );
		$this->assertSame( 30000.0, $payload['price_high'] );
		$this->assertSame( '€20,000 – €30,000', $payload['range_text'] );
		$this->assertSame( 538.0, $payload['hours'], 'hours are rounded for the visitor' );
		$this->assertSame( 18, $payload['weeks'] );
		$this->assertSame( 'mid', $payload['band'] );
		// The point price, the rate and the qualification never leave the server in any mode.
		$json = (string) json_encode( $payload );
		foreach ( [ '25144', '46.71', 'qualification', 'breakdown', 'rate' ] as $leak ) {
			$this->assertStringNotContainsString( $leak, $json );
		}
	}

	/**
	 * Modes that reveal figures.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function figures_mode_provider(): array {
		return [
			'open locked'    => [ 'open', false ],
			'open unlocked'  => [ 'open', true ],
			'gated unlocked' => [ 'gated', true ],
		];
	}

	public function test_gated_unlocked_equals_open_apart_from_the_gate_fields(): void {
		$open  = RevealPolicy::visitor_payload( self::sample(), 'open', true );
		$gated = RevealPolicy::visitor_payload( self::sample(), 'gated', true );
		unset( $open['mode'], $gated['mode'] );
		$this->assertSame( $open, $gated );
	}

	public function test_weeks_text_singular_and_plural(): void {
		$this->assertSame( '1 week', RevealPolicy::visitor_payload( self::sample( 1 ), 'open', true )['weeks_text'] );
		$this->assertSame( '2 weeks', RevealPolicy::visitor_payload( self::sample( 2 ), 'open', true )['weeks_text'] );
	}

	/* ---------- team_labels() ---------- */

	public function test_team_labels_drop_sub_hour_roles_and_round(): void {
		$team = RevealPolicy::team_labels( self::sample() );
		$this->assertSame(
			[
				[
					'role'  => 'pm',
					'label' => 'Project manager',
					'hours' => 81,
					'share' => 0.15,
				],
				[
					'role'  => 'sse',
					'label' => 'Senior software engineer',
					'hours' => 135,
					'share' => 0.25,
				],
				[
					'role'  => 'qa',
					'label' => 'QA',
					'hours' => 1,
					'share' => 0.0009,
				],
				[
					'role'  => 'ops',
					'label' => 'ops',
					'hours' => 5,
					'share' => 0.01,
				],
			],
			$team
		);
		$this->assertSame( [ 'pm', 'sse', 'qa', 'ops' ], array_column( $team, 'role' ) );
		$this->assertSame( $team, RevealPolicy::visitor_payload( self::sample(), 'band', false )['team'] );
	}

	public function test_team_labels_with_no_roles(): void {
		$empty = EstimateResult::from_array( array_merge( self::sample()->to_array(), [ 'team' => [] ] ) );
		$this->assertSame( [], RevealPolicy::team_labels( $empty ) );
		$this->assertSame( [], RevealPolicy::visitor_payload( $empty, 'open', true )['team'] );
	}

	public function test_team_payload_carries_no_rates(): void {
		$json = (string) json_encode( RevealPolicy::team_labels( self::sample() ) );
		$this->assertStringNotContainsString( 'rate', $json );
		$this->assertStringNotContainsString( '55', $json );
	}
}
