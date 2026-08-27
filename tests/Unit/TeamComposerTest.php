<?php
/**
 * TeamComposer tests: band boundaries, role omission, hours sums, rates.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Tests\Unit;

use Cybertech\Estimator\Engine\RateCard;
use Cybertech\Estimator\Engine\TeamComposer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Team composer tests.
 */
final class TeamComposerTest extends TestCase {

	#[DataProvider( 'boundary_provider' )]
	public function test_band_selection_at_boundaries( float $hours, int $band ): void {
		$team = ( new TeamComposer( RateCard::defaults() ) )->compose( 'web', $hours );
		$this->assertSame( $band, $team['band_index'] );
		$this->assertSame( "team_bands.web.{$band}", $team['source'] );
	}

	/**
	 * PLAN §3: bands by total hours ≤120 / ≤400 / >400.
	 *
	 * @return array<string, array{0: float, 1: int}>
	 */
	public static function boundary_provider(): array {
		return [
			'0 h → band 0'      => [ 0.0, 0 ],
			'119.99 h → band 0' => [ 119.99, 0 ],
			'120 h → band 0'    => [ 120.0, 0 ],
			'120.01 h → band 1' => [ 120.01, 1 ],
			'121 h → band 1'    => [ 121.0, 1 ],
			'400 h → band 1'    => [ 400.0, 1 ],
			'401 h → band 2'    => [ 401.0, 2 ],
			'5000 h → band 2'   => [ 5000.0, 2 ],
		];
	}

	public function test_bands_are_per_service_line(): void {
		$composer = new TeamComposer( RateCard::defaults() );
		// Same hours, different lines → different allocation (D7).
		$web    = $composer->compose( 'web', 100 );
		$design = $composer->compose( 'design', 100 );
		$this->assertSame( 'team_bands.web.0', $web['source'] );
		$this->assertSame( 'team_bands.design.0', $design['source'] );
		$this->assertSame( 0.4, $web['roles']['sse']['share'] );
		$this->assertSame( 0.7, $design['roles']['design']['share'] );
	}

	public function test_zero_share_roles_are_omitted(): void {
		$composer = new TeamComposer( RateCard::defaults() );
		$this->assertSame( [ 'pm', 'sse', 'devops', 'qa', 'fe_junior', 'design' ], array_keys( $composer->compose( 'web', 100 )['roles'] ) );
		$this->assertSame( [ 'pm', 'sse', 'be', 'devops', 'qa', 'fe_junior', 'design' ], array_keys( $composer->compose( 'web', 200 )['roles'] ) );
		$this->assertSame( [ 'pm', 'sse', 'qa', 'fe_junior', 'design' ], array_keys( $composer->compose( 'design', 500 )['roles'] ) );
		$this->assertSame( [ 'pm', 'sse', 'be', 'devops', 'qa', 'design' ], array_keys( $composer->compose( 'ai', 100 )['roles'] ) );
	}

	#[DataProvider( 'hours_provider' )]
	public function test_role_hours_sum_to_total( string $line, float $hours ): void {
		$team = ( new TeamComposer( RateCard::defaults() ) )->compose( $line, $hours );
		$sum  = 0.0;
		foreach ( $team['roles'] as $role => $r ) {
			$this->assertSame( round( $hours * $r['share'], 2 ), $r['hours'], $role );
			$this->assertGreaterThan( 0, $r['share'] );
			$sum += $r['hours'];
		}
		$this->assertEqualsWithDelta( $hours, $sum, 0.05 );
		$this->assertEqualsWithDelta( 1.0, array_sum( array_column( $team['roles'], 'share' ) ), 0.0001 );
	}

	/**
	 * Line/hours pairs, incl. the worked example (538.31) and awkward fractions.
	 *
	 * @return array<string, array{0: string, 1: float}>
	 */
	public static function hours_provider(): array {
		return [
			'web 538.31'    => [ 'web', 538.31 ],
			'web 121'       => [ 'web', 121.0 ],
			'web 40'        => [ 'web', 40.0 ],
			'mobile 304.3'  => [ 'mobile', 304.3 ],
			'mobile 999.99' => [ 'mobile', 999.99 ],
			'design 409.2'  => [ 'design', 409.2 ],
			'design 24'     => [ 'design', 24.0 ],
			'ai 288.42'     => [ 'ai', 288.42 ],
			'ai 1.01'       => [ 'ai', 1.01 ],
		];
	}

	public function test_worked_example_role_hours(): void {
		// 538.31 h in web band 2 (pm 15 · sse 25 · be 25 · devops 8 · qa 15 · fe_junior 7 · design 5):
		// pm 80.7465 → 80.75 · sse 134.5775 → 134.58 · be 134.58 · devops 43.0648 → 43.06 · qa 80.75 · fe 37.6817 → 37.68 · design 26.9155 → 26.92.
		$roles = ( new TeamComposer( RateCard::defaults() ) )->compose( 'web', 538.31 )['roles'];
		$this->assertSame( 80.75, $roles['pm']['hours'] );
		$this->assertSame( 134.58, $roles['sse']['hours'] );
		$this->assertSame( 134.58, $roles['be']['hours'] );
		$this->assertSame( 43.06, $roles['devops']['hours'] );
		$this->assertSame( 80.75, $roles['qa']['hours'] );
		$this->assertSame( 37.68, $roles['fe_junior']['hours'] );
		$this->assertSame( 26.92, $roles['design']['hours'] );
		$this->assertSame( 55.0, $roles['sse']['rate'] );
		$this->assertSame( 28.0, $roles['fe_junior']['rate'] );
	}

	#[DataProvider( 'rate_provider' )]
	public function test_effective_rate_is_share_weighted( string $line, float $hours, float $expected ): void {
		$composer = new TeamComposer( RateCard::defaults() );
		$this->assertEqualsWithDelta( $expected, $composer->effective_rate( $composer->compose( $line, $hours )['roles'] ), 0.00001 );
	}

	/**
	 * Hand-computed Σ share × role rate (pm 40 · sse 55 · be 55 · devops 50 · qa 35 · fe_junior 28 · design 40).
	 *
	 * @return array<string, array{0: string, 1: float, 2: float}>
	 */
	public static function rate_provider(): array {
		return [
			// 0.10×40 + 0.40×55 + 0.05×50 + 0.15×35 + 0.20×28 + 0.10×40 = 4 + 22 + 2.5 + 5.25 + 5.6 + 4.
			'web band 0'    => [ 'web', 100.0, 43.35 ],
			// 4.8 + 16.5 + 11 + 3 + 5.25 + 3.36 + 2.
			'web band 1'    => [ 'web', 121.0, 45.91 ],
			// 6 + 13.75 + 13.75 + 4 + 5.25 + 1.96 + 2.
			'web band 2'    => [ 'web', 538.31, 46.71 ],
			// 4 + 24.75 + 8.25 + 2.5 + 5.25 + 1.4 + 2.
			'mobile band 0' => [ 'mobile', 80.0, 48.15 ],
			// 4.8 + 19.25 + 11 + 3 + 5.95 + 1.4 + 2.
			'mobile band 1' => [ 'mobile', 304.3, 47.4 ],
			// 6 + 16.5 + 11 + 4 + 5.95 + 1.4 + 2.
			'mobile band 2' => [ 'mobile', 401.0, 46.85 ],
			// 4 + 2.75 + 1.75 + 2.8 + 28.
			'design band 0' => [ 'design', 24.0, 39.3 ],
			// 4 + 8.25 + 1.75 + 2.8 + 24.
			'design band 2' => [ 'design', 409.2, 40.8 ],
			// 4.8 + 22 + 13.75 + 4 + 3.5 + 2.
			'ai band 0'     => [ 'ai', 24.0, 50.05 ],
			// 4.8 + 19.25 + 16.5 + 4 + 3.5 + 2.
			'ai band 1'     => [ 'ai', 288.42, 50.05 ],
			// 6 + 16.5 + 16.5 + 5 + 3.5 + 2.
			'ai band 2'     => [ 'ai', 801.0, 49.5 ],
		];
	}

	public function test_effective_rate_is_rounded_to_4_decimals(): void {
		$composer = new TeamComposer( RateCard::defaults() );
		$rate     = $composer->effective_rate(
			[
				'a' => [
					'share' => 1 / 3,
					'hours' => 1,
					'rate'  => 100,
				],
				'b' => [
					'share' => 2 / 3,
					'hours' => 2,
					'rate'  => 10,
				],
			]
		);
		// 33.333… + 6.666… = 40 exactly; use an uneven one too: 1/3 × 100 = 33.3333.
		$this->assertSame( 40.0, $rate );
		$this->assertSame(
			33.3333,
			$composer->effective_rate(
				[
					'a' => [
						'share' => 1 / 3,
						'hours' => 1,
						'rate'  => 100,
					],
				]
			)
		);
	}

	public function test_empty_roles_fall_back_to_blended_rate(): void {
		$this->assertSame( 45.0, ( new TeamComposer( RateCard::defaults() ) )->effective_rate( [] ) );
		$this->assertSame( 52.0, ( new TeamComposer( CardFactory::card( [ 'blended_rate' => 52 ] ) ) )->effective_rate( [] ) );
	}

	public function test_roles_without_a_rate_use_the_blended_rate(): void {
		// A custom band with a role id the role_rates table does not know.
		$card = CardFactory::card(
			[
				'team_bands.web' => [
					[
						'max_hours' => null,
						'roles'     => [
							'ops' => 60,
							'pm'  => 40,
						],
					],
				],
			]
		);
		$team = ( new TeamComposer( $card ) )->compose( 'web', 100 );
		$this->assertSame( 45.0, $team['roles']['ops']['rate'] );
		$this->assertSame( 40.0, $team['roles']['pm']['rate'] );
		// 0.6 × 45 + 0.4 × 40 = 27 + 16 = 43.
		$this->assertSame( 43.0, ( new TeamComposer( $card ) )->effective_rate( $team['roles'] ) );
	}

	public function test_single_band_with_a_max_still_catches_overflow(): void {
		// One band capped at 10 h; 500 h falls past it → last band is used anyway.
		$card = CardFactory::card(
			[
				'team_bands.web' => [
					[
						'max_hours' => 10,
						'roles'     => [ 'pm' => 100 ],
					],
				],
			]
		);
		$team = ( new TeamComposer( $card ) )->compose( 'web', 500 );
		$this->assertSame( 0, $team['band_index'] );
		$this->assertSame( 500.0, $team['roles']['pm']['hours'] );
	}
}
