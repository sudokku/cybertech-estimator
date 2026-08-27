<?php
/**
 * RateCard tests: validation, defaults, factor filtering/sorting, role rates.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Tests\Unit;

use Cybertech\Estimator\Engine\RateCard;
use Cybertech\Estimator\Engine\RateCardDefaults;
use PHPUnit\Framework\TestCase;

/**
 * Rate card tests.
 */
final class RateCardTest extends TestCase {

	private const REQUIRED_KEYS = [ 'currency', 'blended_rate', 'service_lines', 'factors', 'urgency', 'contingency', 'range_spread', 'rounding', 'weekly_capacity', 'min_weeks', 'team_bands', 'reveal_bands', 'budget_bands', 'qualification' ];

	/* ---------- validate() ---------- */

	public function test_defaults_validate_clean(): void {
		$this->assertSame( [], RateCard::validate( RateCardDefaults::card() ) );
		$card = RateCard::defaults();
		$this->assertSame( 1, $card->version() );
		$this->assertSame( 'EUR', $card->currency() );
		$this->assertSame( RateCardDefaults::card(), $card->to_array() );
	}

	public function test_role_labels_cover_every_role_in_the_defaults(): void {
		$card  = RateCardDefaults::card();
		$roles = array_keys( $card['role_rates'] );
		$this->assertSame( $roles, array_keys( RateCardDefaults::role_labels() ) );
		foreach ( $card['team_bands'] as $line => $bands ) {
			foreach ( $bands as $i => $band ) {
				$this->assertSame( $roles, array_keys( $band['roles'] ), "team_bands.{$line}.{$i} must list every role" );
			}
		}
	}

	public function test_missing_keys_are_each_reported_and_nothing_else(): void {
		$errors = RateCard::validate( [] );
		$this->assertCount( count( self::REQUIRED_KEYS ), $errors );
		foreach ( self::REQUIRED_KEYS as $key ) {
			$this->assertContains( "missing key '{$key}'", $errors );
		}
	}

	public function test_single_missing_key_is_reported_before_value_checks(): void {
		$data = CardFactory::data( [ 'blended_rate' => -1 ] );
		unset( $data['team_bands'] );
		// Structural errors short-circuit: only the missing key is reported.
		$this->assertSame( [ "missing key 'team_bands'" ], RateCard::validate( $data ) );
	}

	public function test_blended_rate_must_be_positive(): void {
		$this->assertContains( 'blended_rate must be > 0', RateCard::validate( CardFactory::data( [ 'blended_rate' => 0 ] ) ) );
		$this->assertContains( 'blended_rate must be > 0', RateCard::validate( CardFactory::data( [ 'blended_rate' => -45 ] ) ) );
		$this->assertContains( 'blended_rate must be > 0', RateCard::validate( CardFactory::data( [ 'blended_rate' => 'abc' ] ) ) );
		$this->assertSame( [], RateCard::validate( CardFactory::data( [ 'blended_rate' => '45' ] ) ) );
	}

	public function test_weekly_capacity_must_be_positive(): void {
		$this->assertContains( 'weekly_capacity must be > 0', RateCard::validate( CardFactory::data( [ 'weekly_capacity' => 0 ] ) ) );
	}

	public function test_range_spread_must_be_below_one(): void {
		$this->assertContains( 'range_spread must be in [0, 1)', RateCard::validate( CardFactory::data( [ 'range_spread' => 1 ] ) ) );
		$this->assertContains( 'range_spread must be in [0, 1)', RateCard::validate( CardFactory::data( [ 'range_spread' => 1.5 ] ) ) );
		$this->assertContains( 'range_spread must be in [0, 1)', RateCard::validate( CardFactory::data( [ 'range_spread' => -0.1 ] ) ) );
		$this->assertSame( [], RateCard::validate( CardFactory::data( [ 'range_spread' => 0 ] ) ) );
		$this->assertSame( [], RateCard::validate( CardFactory::data( [ 'range_spread' => 0.99 ] ) ) );
	}

	public function test_contingency_must_not_be_negative(): void {
		$this->assertContains( 'contingency must be >= 0', RateCard::validate( CardFactory::data( [ 'contingency' => -0.1 ] ) ) );
		$this->assertContains( 'contingency must be >= 0', RateCard::validate( CardFactory::data( [ 'contingency' => 'ten' ] ) ) );
		$this->assertSame( [], RateCard::validate( CardFactory::data( [ 'contingency' => 0 ] ) ) );
	}

	public function test_rounding_values_must_be_positive(): void {
		$this->assertContains( 'rounding.below must be > 0', RateCard::validate( CardFactory::data( [ 'rounding.below' => 0 ] ) ) );
		$this->assertContains( 'rounding.above must be > 0', RateCard::validate( CardFactory::data( [ 'rounding.above' => -500 ] ) ) );
		$this->assertContains( 'rounding.threshold must be > 0', RateCard::validate( CardFactory::data( [ 'rounding.threshold' => 0 ] ) ) );
		$data = CardFactory::data();
		unset( $data['rounding']['below'] );
		$this->assertContains( 'rounding.below must be > 0', RateCard::validate( $data ) );
	}

	public function test_service_line_hours_must_be_non_negative(): void {
		$this->assertContains( 'service_lines.web needs non-negative base_hours and min_hours', RateCard::validate( CardFactory::data( [ 'service_lines.web.base_hours' => -1 ] ) ) );
		$this->assertContains( 'service_lines.ai needs non-negative base_hours and min_hours', RateCard::validate( CardFactory::data( [ 'service_lines.ai.min_hours' => -1 ] ) ) );
		$data = CardFactory::data();
		unset( $data['service_lines']['design']['min_hours'] );
		$this->assertContains( 'service_lines.design needs non-negative base_hours and min_hours', RateCard::validate( $data ) );
	}

	public function test_team_band_shares_must_sum_to_100(): void {
		// Drop web band 0's sse from 40 to 39 → 99.
		$errors = RateCard::validate( CardFactory::data( [ 'team_bands.web.0.roles.sse' => 39 ] ) );
		$this->assertContains( 'team_bands.web.0 role shares sum to 99, expected 100', $errors );
		$this->assertCount( 1, $errors );
		// 100.005 is within tolerance; 100.02 is not.
		$this->assertSame( [], RateCard::validate( CardFactory::data( [ 'team_bands.web.0.roles.sse' => 40.005 ] ) ) );
		$this->assertCount( 1, RateCard::validate( CardFactory::data( [ 'team_bands.web.0.roles.sse' => 40.02 ] ) ) );
	}

	public function test_every_service_line_needs_team_bands(): void {
		$data = CardFactory::data();
		unset( $data['team_bands']['mobile'] );
		$this->assertContains( 'team_bands.mobile missing', RateCard::validate( $data ) );
		$this->assertContains( 'team_bands.mobile missing', RateCard::validate( CardFactory::data( [ 'team_bands.mobile' => [] ] ) ) );
	}

	public function test_unknown_factor_type(): void {
		$errors = RateCard::validate( CardFactory::data( [ 'factors.web_migration.type' => 'discount' ] ) );
		$this->assertContains( 'factors.web_migration.type must be one of multiplier|add_hours|add_price', $errors );
		$data = CardFactory::data();
		unset( $data['factors']['web_migration']['type'] );
		$this->assertContains( 'factors.web_migration.type must be one of multiplier|add_hours|add_price', RateCard::validate( $data ) );
	}

	public function test_non_numeric_factor_value(): void {
		$this->assertContains( 'factors.web_migration.value must be numeric', RateCard::validate( CardFactory::data( [ 'factors.web_migration.value' => 'lots' ] ) ) );
		$data = CardFactory::data();
		unset( $data['factors']['web_migration']['value'] );
		$this->assertContains( 'factors.web_migration.value must be numeric', RateCard::validate( $data ) );
		// Numeric strings are accepted.
		$this->assertSame( [], RateCard::validate( CardFactory::data( [ 'factors.web_migration.value' => '24' ] ) ) );
		// add_hours may be zero or negative (a discount in hours).
		$this->assertSame( [], RateCard::validate( CardFactory::data( [ 'factors.web_migration.value' => -10 ] ) ) );
	}

	public function test_multiplier_must_be_positive(): void {
		$this->assertContains( 'factors.web_multilingual.value must be > 0 for a multiplier', RateCard::validate( CardFactory::data( [ 'factors.web_multilingual.value' => 0 ] ) ) );
		$this->assertContains( 'factors.web_multilingual.value must be > 0 for a multiplier', RateCard::validate( CardFactory::data( [ 'factors.web_multilingual.value' => -1.25 ] ) ) );
		$this->assertSame( [], RateCard::validate( CardFactory::data( [ 'factors.web_multilingual.value' => 0.5 ] ) ) );
	}

	public function test_applies_to_must_be_a_non_empty_list(): void {
		$this->assertContains( 'factors.web_migration.applies_to must be a non-empty list', RateCard::validate( CardFactory::data( [ 'factors.web_migration.applies_to' => [] ] ) ) );
		$this->assertContains( 'factors.web_migration.applies_to must be a non-empty list', RateCard::validate( CardFactory::data( [ 'factors.web_migration.applies_to' => 'web' ] ) ) );
		$data = CardFactory::data();
		unset( $data['factors']['web_migration']['applies_to'] );
		$this->assertContains( 'factors.web_migration.applies_to must be a non-empty list', RateCard::validate( $data ) );
	}

	public function test_urgency_multipliers_must_be_positive(): void {
		$this->assertContains( 'urgency.asap must be > 0', RateCard::validate( CardFactory::data( [ 'urgency.asap' => 0 ] ) ) );
		$this->assertContains( 'urgency.flexible must be > 0', RateCard::validate( CardFactory::data( [ 'urgency.flexible' => -0.95 ] ) ) );
		$this->assertContains( 'urgency.normal must be > 0', RateCard::validate( CardFactory::data( [ 'urgency.normal' => 'fast' ] ) ) );
	}

	public function test_multiple_problems_are_all_reported(): void {
		$errors = RateCard::validate(
			CardFactory::data(
				[
					'blended_rate'                     => 0,
					'range_spread'                     => 1,
					'contingency'                      => -1,
					'factors.web_migration.type'       => 'nope',
					'factors.web_multilingual.value'   => 0,
					'urgency.asap'                     => 0,
					'team_bands.design.2.roles.design' => 0,
				]
			)
		);
		$this->assertCount( 7, $errors );
		$this->assertContains( 'team_bands.design.2 role shares sum to 40, expected 100', $errors );
	}

	/* ---------- constructor ---------- */

	public function test_constructor_throws_on_invalid_card(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Invalid rate card: blended_rate must be > 0; urgency.asap must be > 0' );
		new RateCard(
			CardFactory::data(
				[
					'blended_rate' => 0,
					'urgency.asap' => 0,
				]
			)
		);
	}

	public function test_constructor_throws_on_missing_keys(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( "missing key 'currency'" );
		new RateCard( [] );
	}

	/* ---------- accessors ---------- */

	public function test_get_walks_dot_paths_with_fallback(): void {
		$card = RateCard::defaults();
		$this->assertSame( 1.8, $card->get( 'factors.web_ecommerce_magento.value' ) );
		$this->assertSame( 10000, $card->get( 'rounding.threshold' ) );
		$this->assertSame( 40, $card->get( 'team_bands.web.0.roles.sse' ) );
		$this->assertNull( $card->get( 'reveal_bands.2.max_price' ) );
		$this->assertNull( $card->get( 'reveal_bands.2.max_price', 'fallback' ) );
		$this->assertSame( 'fallback', $card->get( 'factors.nope.value', 'fallback' ) );
		$this->assertSame( 'fallback', $card->get( 'currency.code', 'fallback' ) );
	}

	public function test_version_defaults_to_zero_when_absent(): void {
		$data = CardFactory::data();
		unset( $data['version'] );
		$this->assertSame( 0, ( new RateCard( $data ) )->version() );
		$this->assertSame( 7, CardFactory::card( [ 'version' => '7' ] )->version() );
	}

	public function test_has_service_line(): void {
		$card = RateCard::defaults();
		foreach ( [ 'web', 'mobile', 'design', 'ai' ] as $line ) {
			$this->assertTrue( $card->has_service_line( $line ) );
		}
		$this->assertFalse( $card->has_service_line( 'print' ) );
		$this->assertFalse( $card->has_service_line( '' ) );
	}

	/* ---------- factors_for() ---------- */

	public function test_factors_for_filters_by_applies_to(): void {
		$card = RateCard::defaults();
		$web  = $card->factors_for( 'web' );
		foreach ( $web as $id => $factor ) {
			$this->assertContains( 'web', $factor['applies_to'], $id );
		}
		$this->assertArrayHasKey( 'web_ecommerce_magento', $web );
		$this->assertArrayHasKey( 'ctx_hosting_cybertech', $web );
		$this->assertArrayNotHasKey( 'mobile_offline', $web );
		$this->assertArrayNotHasKey( 'design_screens', $web );
		$this->assertArrayNotHasKey( 'ai_hitl', $web );
		// 11 web factors + 2 context factors.
		$this->assertCount( 13, $web );
		$this->assertCount( 12, $card->factors_for( 'mobile' ) );
		$this->assertCount( 10, $card->factors_for( 'design' ) );
		$this->assertCount( 10, $card->factors_for( 'ai' ) );
		$this->assertSame( [], $card->factors_for( 'print' ) );
	}

	public function test_factors_for_sorts_by_order_then_id(): void {
		$ids    = array_keys( RateCard::defaults()->factors_for( 'web' ) );
		$sorted = $ids;
		$card   = RateCardDefaults::card();
		usort(
			$sorted,
			static fn( string $a, string $b ): int => [ $card['factors'][ $a ]['order'], $a ] <=> [ $card['factors'][ $b ]['order'], $b ]
		);
		$this->assertSame( $sorted, $ids );
		// Concretely: order 10 platform factors alphabetically, then the order-20 e-commerce ones, …, ctx last.
		$this->assertSame(
			[
				'web_platform_custom',
				'web_platform_django',
				'web_platform_drupal',
				'web_platform_joomla',
				'web_ecommerce_magento',
				'web_ecommerce_prestashop',
				'web_ecommerce_woocommerce',
				'web_templates',
				'web_multilingual',
				'web_integrations',
				'web_migration',
				'ctx_hosting_cybertech',
				'ctx_hosting_undecided',
			],
			$ids
		);
	}

	public function test_factors_for_ignores_declaration_order(): void {
		$factor = static fn( int $order ): array => [
			'label'      => 'x',
			'applies_to' => [ 'web' ],
			'type'       => 'add_hours',
			'value'      => 1,
			'order'      => $order,
			'per_unit'   => false,
		];
		$card   = CardFactory::card(
			[
				'factors' => [
					'zeta'  => $factor( 5 ),
					'beta'  => $factor( 5 ),
					'alpha' => $factor( 5 ),
					'omega' => $factor( 1 ),
					'mob'   => array_merge( $factor( 0 ), [ 'applies_to' => [ 'mobile' ] ] ),
				],
			]
		);
		$this->assertSame( [ 'omega', 'alpha', 'beta', 'zeta' ], array_keys( $card->factors_for( 'web' ) ) );
		$this->assertSame( [ 'mob' ], array_keys( $card->factors_for( 'mobile' ) ) );
	}

	/* ---------- role_rate() ---------- */

	public function test_role_rate_reads_the_card(): void {
		$card = RateCard::defaults();
		$this->assertSame( 40.0, $card->role_rate( 'pm' ) );
		$this->assertSame( 55.0, $card->role_rate( 'sse' ) );
		$this->assertSame( 28.0, $card->role_rate( 'fe_junior' ) );
	}

	public function test_role_rate_falls_back_to_blended(): void {
		$card = CardFactory::card(
			[
				'blended_rate'   => 47,
				'role_rates.sse' => 0,
				'role_rates.qa'  => -5,
				'role_rates.be'  => 'n/a',
				'role_rates.pm'  => '41',
				'role_rates.ops' => 60,
			]
		);
		$this->assertSame( 47.0, $card->role_rate( 'sse' ), 'zero → blended' );
		$this->assertSame( 47.0, $card->role_rate( 'qa' ), 'negative → blended' );
		$this->assertSame( 47.0, $card->role_rate( 'be' ), 'non-numeric → blended' );
		$this->assertSame( 47.0, $card->role_rate( 'unknown_role' ), 'missing → blended' );
		$this->assertSame( 41.0, $card->role_rate( 'pm' ), 'numeric string is accepted' );
		$this->assertSame( 60.0, $card->role_rate( 'ops' ), 'any role id works' );

		$data = CardFactory::data();
		unset( $data['role_rates'] );
		$this->assertSame( 45.0, ( new RateCard( $data ) )->role_rate( 'sse' ), 'no role_rates key → blended' );
	}
}
