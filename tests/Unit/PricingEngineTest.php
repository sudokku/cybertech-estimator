<?php
/**
 * PricingEngine tests. Every expected number below is derived BY HAND from
 * docs/PLAN.md §3 (the accepted default rate card) and the brief's fixed
 * calculation order; the arithmetic is written out next to each assertion.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Tests\Unit;

use Cybertech\Estimator\Engine\EstimateResult;
use Cybertech\Estimator\Engine\PricingEngine;
use Cybertech\Estimator\Engine\RateCard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Pricing engine tests.
 */
final class PricingEngineTest extends TestCase {

	private const SENTINEL = '__no_such_path__';

	/**
	 * Steps whose breakdown rows must carry a card path. 'price' is pure
	 * arithmetic (hours × rate) and is allowed an empty source.
	 */
	private const CARD_DRIVEN_STEPS = [ 'base_hours', 'add_hours', 'multiplier', 'urgency', 'contingency', 'clamp', 'team', 'rate', 'add_price', 'range', 'weeks', 'band', 'qualification' ];

	/**
	 * Plain brochure site: WordPress, 5 templates, nothing else.
	 */
	private const WEB_BASIC = [
		'service_line'     => 'web',
		'web_platform'     => 'wordpress',
		'web_ecommerce'    => 'none',
		'web_templates'    => 5,
		'web_multilingual' => 'no',
		'web_integrations' => 0,
		'web_migration'    => 'no',
		'urgency'          => 'normal',
		'budget'           => '5k_15k',
		'maintenance'      => 'no',
		'hosting'          => 'client',
		'notes'            => '',
	];

	/**
	 * The worked example from the brief: web + Magento + multilingual + urgent.
	 */
	private const WORKED = [
		'service_line'     => 'web',
		'web_platform'     => 'wordpress',
		'web_ecommerce'    => 'magento',
		'web_templates'    => 5,
		'web_multilingual' => 'yes',
		'web_integrations' => 2,
		'web_migration'    => 'yes',
		'urgency'          => 'urgent',
		'budget'           => '40k_100k',
		'maintenance'      => 'yes',
		'hosting'          => 'cybertech',
		'notes'            => 'Replatform our B2B shop from Magento 1 to Magento 2 with three storefronts.',
	];

	private const MOBILE = [
		'service_line'     => 'mobile',
		'mobile_framework' => 'flutter',
		'mobile_platforms' => 'both',
		'mobile_offline'   => 'no',
		'mobile_auth'      => 'yes',
		'mobile_payments'  => 'no',
		'mobile_push'      => 'yes',
		'mobile_backend'   => 'existing',
		'urgency'          => 'flexible',
		'budget'           => '15k_40k',
		'maintenance'      => 'yes',
		'hosting'          => 'undecided',
		'notes'            => 'Need it for our sales team',
	];

	private const DESIGN = [
		'service_line'          => 'design',
		'design_deliverables'   => [ 'research', 'hifi', 'design_system' ],
		'design_screens'        => 12,
		'design_brand'          => 'yes',
		'design_testing_rounds' => 2,
		'urgency'               => 'asap',
		'budget'                => 'under_5k',
		'maintenance'           => 'no',
		'hosting'               => 'client',
		'notes'                 => 'Full redesign of the customer portal and the admin.',
	];

	private const AI = [
		'service_line' => 'ai',
		'ai_workflows' => 3,
		'ai_provider'  => 'open_weight',
		'ai_voice'     => 'yes',
		'ai_systems'   => 2,
		'ai_data'      => 'medium',
		'ai_hitl'      => 'yes',
		'urgency'      => 'normal',
		'budget'       => 'over_100k',
		'maintenance'  => 'yes',
		'hosting'      => 'cybertech',
		'notes'        => '',
	];

	/* ---------- helpers ---------- */

	/**
	 * Run the engine.
	 *
	 * @param array<string, mixed> $answers Answers.
	 * @param RateCard|null        $card    Card (defaults when null).
	 */
	private static function estimate( array $answers, ?RateCard $card = null ): EstimateResult {
		return ( new PricingEngine( $card ?? RateCard::defaults(), $answers ) )->estimate();
	}

	/**
	 * Breakdown rows of one step.
	 *
	 * @param EstimateResult $result Result.
	 * @param string         $step   Step id.
	 * @return array<int, array<string, mixed>>
	 */
	private static function rows( EstimateResult $result, string $step ): array {
		return array_values( array_filter( $result->breakdown, static fn( array $r ): bool => $r['step'] === $step ) );
	}

	/**
	 * Breakdown sources of one step, in order.
	 *
	 * @param EstimateResult $result Result.
	 * @param string         $step   Step id.
	 * @return array<int, string>
	 */
	private static function sources( EstimateResult $result, string $step ): array {
		return array_map( static fn( array $r ): string => (string) $r['source'], self::rows( $result, $step ) );
	}

	/* ---------- 1. the four service lines ---------- */

	public function test_web_basic_site(): void {
		// Hours: base 80 + templates 5 × 6 = 110. No multipliers. Urgency normal × 1.
		// Contingency × 1.1 = 121. Clamp to min 40 → 121.
		// Team: 121 h > 120 → web band 1 (≤ 400): pm 12 % · sse 30 % · be 20 % · devops 6 % · qa 15 % · fe_junior 12 % · design 5 %.
		// Rate: 0.12×40 + 0.30×55 + 0.20×55 + 0.06×50 + 0.15×35 + 0.12×28 + 0.05×40
		//     = 4.8 + 16.5 + 11 + 3 + 5.25 + 3.36 + 2 = 45.91.
		// Price: 121 × 45.91 = 5555.11.
		// Range: low 5555.11 × 0.8 = 4444.088 → nearest 250 (below 10k) → 4500;
		//        high 5555.11 × 1.2 = 6666.132 → nearest 250 → 6750.
		// Weeks: 121 / 30 = 4.03 → ceil 5 (≥ min 2).
		// Band: 5555.11 < 10 000 → small.
		// Score: budget 5k_15k max 15 000 ≥ high 6750 → covers_high 40; normal 12; 121 h → 80–300 → 15;
		//        notes empty 0; maintenance no 0; hosting client 0 → 67.
		$r = self::estimate( self::WEB_BASIC );

		$this->assertSame( 'web', $r->service_line );
		$this->assertSame( 'EUR', $r->currency );
		$this->assertSame( 1, $r->rate_card_version );
		$this->assertEqualsWithDelta( 121.0, $r->hours, 0.0001 );
		$this->assertSame( 1, $r->team['band_index'] );
		$this->assertEqualsWithDelta( 45.91, $r->effective_rate, 0.00001 );
		$this->assertEqualsWithDelta( 5555.11, $r->price, 0.001 );
		$this->assertSame( 4500.0, $r->price_low );
		$this->assertSame( 6750.0, $r->price_high );
		$this->assertSame( 5, $r->weeks );
		$this->assertSame( 'small', $r->band );
		$this->assertSame( 67, $r->qualification );
		$this->assertSame(
			[
				'budget'      => 40,
				'urgency'     => 12,
				'scope'       => 15,
				'notes'       => 0,
				'maintenance' => 0,
				'hosting'     => 0,
			],
			$r->qualification_parts
		);
		// Only the template factor fired: integrations = 0 units must not produce a row.
		$this->assertSame( [ 'factors.web_templates.value' ], self::sources( $r, 'add_hours' ) );
		$this->assertSame( [], self::rows( $r, 'multiplier' ) );
	}

	public function test_mobile_app(): void {
		// add_hours by (order, id): order 30 → mobile_auth 24, mobile_push 16; order 40 → mobile_backend_existing 16;
		// order 90 → ctx_hosting_undecided 8. Hours: 160 + 24 + 16 + 16 + 8 = 224.
		// Multipliers: order 10 flutter × 1.0 = 224; order 20 both × 1.3 = 291.2.
		// Urgency flexible × 0.95 = 276.64. Contingency × 1.1 = 304.304 → 304.30. Clamp min 80 → 304.3.
		// Team: 304.3 ≤ 400 → mobile band 1: pm 12 · sse 35 · be 20 · devops 6 · qa 17 · fe_junior 5 · design 5.
		// Rate: 4.8 + 19.25 + 11 + 3 + 5.95 + 1.4 + 2 = 47.4.
		// Price: 304.3 × 47.4 = 14 423.82.
		// Range: low 11 539.056 → ≥ 10k → nearest 500 → 11 500; high 17 308.584 → 17 500.
		// Weeks: 304.3 / 30 = 10.14 → 11. Band: 10k ≤ 14 423.82 < 40k → mid.
		// Score: 15k_40k max 40 000 ≥ 17 500 → 40; flexible 8; 304.3 h → 300–800 → 20; notes 26 chars < 40 → 0;
		//        maintenance yes 10; hosting undecided 0 → 78.
		$r = self::estimate( self::MOBILE );

		$this->assertEqualsWithDelta( 304.3, $r->hours, 0.0001 );
		$this->assertSame( 1, $r->team['band_index'] );
		$this->assertEqualsWithDelta( 47.4, $r->effective_rate, 0.00001 );
		$this->assertEqualsWithDelta( 14423.82, $r->price, 0.001 );
		$this->assertSame( 11500.0, $r->price_low );
		$this->assertSame( 17500.0, $r->price_high );
		$this->assertSame( 11, $r->weeks );
		$this->assertSame( 'mid', $r->band );
		$this->assertSame( 78, $r->qualification );
		$this->assertSame( 8, $r->qualification_parts['urgency'] );
		$this->assertSame( 20, $r->qualification_parts['scope'] );
		$this->assertSame( 0, $r->qualification_parts['notes'] );
		$this->assertSame(
			[ 'factors.mobile_auth.value', 'factors.mobile_push.value', 'factors.mobile_backend_existing.value', 'factors.ctx_hosting_undecided.value' ],
			self::sources( $r, 'add_hours' )
		);
		// A × 1.0 multiplier is still logged (the framework choice is deliberately visible).
		$this->assertSame( [ 'factors.mobile_framework_flutter.value', 'factors.mobile_platforms_both.value' ], self::sources( $r, 'multiplier' ) );
	}

	public function test_design_engagement(): void {
		// add_hours order 10 by id: design_system 40, hifi 24, research 24 → 60 + 88 = 148;
		// order 20 screens 12 × 3 = 36 → 184; order 30 brand 40 → 224; order 40 testing 2 × 12 = 24 → 248.
		// No multipliers. ASAP × 1.5 = 372. Contingency × 1.1 = 409.2. Clamp min 24 → 409.2.
		// Team: > 400 → design band 2: pm 10 · sse 15 · qa 5 · fe_junior 10 · design 60 (be, devops 0 → omitted).
		// Rate: 4 + 8.25 + 1.75 + 2.8 + 24 = 40.8.
		// Price: 409.2 × 40.8 = 16 695.36.
		// Range: low 13 356.288 → 13 500; high 20 034.432 → 20 000.
		// Weeks: 409.2 / 30 = 13.64 → 14. Band mid.
		// Score: under_5k max 5000 < high, < low 13 500, < low/2 6750 → far_below 0; asap 10; 409.2 h → 20;
		//        notes 51 chars → 10; maintenance no 0; hosting client 0 → 40.
		$r = self::estimate( self::DESIGN );

		$this->assertEqualsWithDelta( 409.2, $r->hours, 0.0001 );
		$this->assertSame( 2, $r->team['band_index'] );
		$this->assertArrayNotHasKey( 'be', $r->team['roles'] );
		$this->assertArrayNotHasKey( 'devops', $r->team['roles'] );
		$this->assertEqualsWithDelta( 40.8, $r->effective_rate, 0.00001 );
		$this->assertEqualsWithDelta( 16695.36, $r->price, 0.001 );
		$this->assertSame( 13500.0, $r->price_low );
		$this->assertSame( 20000.0, $r->price_high );
		$this->assertSame( 14, $r->weeks );
		$this->assertSame( 'mid', $r->band );
		$this->assertSame( 40, $r->qualification );
		$this->assertSame( 0, $r->qualification_parts['budget'] );
		$this->assertSame( 10, $r->qualification_parts['urgency'] );
		$this->assertSame( 10, $r->qualification_parts['notes'] );
		$this->assertSame(
			[
				'factors.design_deliverable_design_system.value',
				'factors.design_deliverable_hifi.value',
				'factors.design_deliverable_research.value',
				'factors.design_screens.value',
				'factors.design_brand.value',
				'factors.design_testing_rounds.value',
			],
			self::sources( $r, 'add_hours' )
		);
	}

	public function test_ai_automation(): void {
		// add_hours: order 10 workflows 3 × 16 = 48 → 108; order 20 open_weight 24 → 132; order 30 vapi 40 → 172;
		// order 40 systems 2 × 12 = 24 → 196; order 60 hitl 16 → 212; order 90 hosting cybertech 16 → 228.
		// Multiplier medium × 1.15 = 262.2. Normal × 1. Contingency × 1.1 = 288.42. Clamp min 24 → 288.42.
		// Team: ≤ 400 → ai band 1: pm 12 · sse 35 · be 30 · devops 8 · qa 10 · design 5 (fe_junior 0 → omitted).
		// Rate: 4.8 + 19.25 + 16.5 + 4 + 3.5 + 2 = 50.05.
		// Price: 288.42 × 50.05 = 14 435.421 → 14 435.42.
		// Range: low 11 548.336 → 11 500; high 17 322.504 → 17 500.
		// Weeks: 288.42 / 30 = 9.61 → 10. Band mid.
		// Score: over_100k has no max → covers_high 40; normal 12; 288.42 h → 80–300 → 15; notes 0;
		//        maintenance yes 10; hosting cybertech 5 → 82.
		$r = self::estimate( self::AI );

		$this->assertEqualsWithDelta( 288.42, $r->hours, 0.0001 );
		$this->assertSame( 1, $r->team['band_index'] );
		$this->assertArrayNotHasKey( 'fe_junior', $r->team['roles'] );
		$this->assertEqualsWithDelta( 50.05, $r->effective_rate, 0.00001 );
		$this->assertEqualsWithDelta( 14435.42, $r->price, 0.001 );
		$this->assertSame( 11500.0, $r->price_low );
		$this->assertSame( 17500.0, $r->price_high );
		$this->assertSame( 10, $r->weeks );
		$this->assertSame( 'mid', $r->band );
		$this->assertSame( 82, $r->qualification );
		$this->assertSame( 40, $r->qualification_parts['budget'] );
		$this->assertSame( 15, $r->qualification_parts['scope'] );
		$this->assertSame( 5, $r->qualification_parts['hosting'] );
		$this->assertSame( [ 'factors.ai_data_medium.value' ], self::sources( $r, 'multiplier' ) );
	}

	/* ---------- 2. worked example ---------- */

	public function test_worked_example_web_magento_multilingual_urgent(): void {
		// add_hours (order, id): templates 5 × 6 = 30 (30) → 110; integrations 2 × 12 = 24 (50) → 134;
		// migration 24 (60) → 158; hosting cybertech 16 (90) → 174.
		// Multipliers: magento × 1.8 (20) = 313.2; multilingual × 1.25 (40) = 391.5.
		// Urgent × 1.25 = 489.375. Contingency × 1.1 = 538.3125 → 538.31. Clamp min 40 → 538.31.
		// Team: > 400 → web band 2: pm 15 · sse 25 · be 25 · devops 8 · qa 15 · fe_junior 7 · design 5.
		// Rate: 6 + 13.75 + 13.75 + 4 + 5.25 + 1.96 + 2 = 46.71.
		// Price: 538.31 × 46.71 = 25 144.4601 → 25 144.46.
		// Range: low 20 115.568 → nearest 500 → 20 000; high 30 173.352 → 30 000.
		// Weeks: 538.31 / 30 = 17.94 → 18. Band: 10k ≤ price < 40k → mid.
		// Score: 40k_100k max 100 000 ≥ 30 000 → 40; urgent 15; 538 h → 300–800 → 20; notes 75 chars → 10;
		//        maintenance 10; hosting cybertech 5 → 100.
		$r = self::estimate( self::WORKED );

		$this->assertEqualsWithDelta( 538.31, $r->hours, 0.0001 );
		$this->assertSame( 2, $r->team['band_index'] );
		$this->assertSame( 'team_bands.web.2', $r->team['source'] );
		$this->assertEqualsWithDelta( 46.71, $r->effective_rate, 0.00001 );
		$this->assertEqualsWithDelta( 25144.46, $r->price, 0.001 );
		$this->assertSame( 20000.0, $r->price_low );
		$this->assertSame( 30000.0, $r->price_high );
		$this->assertSame( 18, $r->weeks );
		$this->assertSame( 'mid', $r->band );
		$this->assertSame( 'Mid-size engagement', $r->band_label );
		$this->assertSame( 100, $r->qualification );

		// The whole log, in the brief's order.
		$this->assertSame(
			[
				'base_hours',
				'add_hours',
				'add_hours',
				'add_hours',
				'add_hours',
				'multiplier',
				'multiplier',
				'urgency',
				'contingency',
				'clamp',
				'team',
				'rate',
				'price',
				'range',
				'range',
				'weeks',
				'band',
				'qualification',
				'qualification',
				'qualification',
				'qualification',
				'qualification',
				'qualification',
				'qualification',
			],
			array_column( $r->breakdown, 'step' )
		);
		$this->assertSame(
			[ 'factors.web_templates.value', 'factors.web_integrations.value', 'factors.web_migration.value', 'factors.ctx_hosting_cybertech.value' ],
			self::sources( $r, 'add_hours' )
		);
		$this->assertSame( [ 'factors.web_ecommerce_magento.value', 'factors.web_multilingual.value' ], self::sources( $r, 'multiplier' ) );

		// Intermediate values along the chain.
		$after = array_column( $r->breakdown, 'after' );
		$this->assertSame( 80.0, $after[0] );
		$this->assertSame( 110.0, $after[1] );
		$this->assertSame( 134.0, $after[2] );
		$this->assertSame( 158.0, $after[3] );
		$this->assertSame( 174.0, $after[4] );
		$this->assertEqualsWithDelta( 313.2, $after[5], 0.0001 );
		$this->assertEqualsWithDelta( 391.5, $after[6], 0.0001 );
		$this->assertEqualsWithDelta( 489.375, $after[7], 0.0001 );
		$this->assertEqualsWithDelta( 538.3125, $after[8], 0.0001 );
		$this->assertEqualsWithDelta( 538.3125, $after[9], 0.0001 );
		$this->assertSame( '× 1.25', self::rows( $r, 'urgency' )[0]['operation'] );
		$this->assertSame( 'urgency.urgent', self::rows( $r, 'urgency' )[0]['source'] );
		$this->assertSame( '× 1.1', self::rows( $r, 'contingency' )[0]['operation'] );
		$this->assertEqualsWithDelta( 17.9437, self::rows( $r, 'weeks' )[0]['before'], 0.0001 );
		$this->assertSame( 18.0, self::rows( $r, 'weeks' )[0]['after'] );
		$this->assertSame( 'reveal_bands.1.max_price', self::rows( $r, 'band' )[0]['source'] );
		$this->assertSame( [ 40.0, 15.0, 20.0, 10.0, 10.0, 5.0, 100.0 ], array_column( self::rows( $r, 'qualification' ), 'after' ) );
	}

	/* ---------- 3. ordering ---------- */

	public function test_add_hours_apply_before_multipliers_regardless_of_order_field(): void {
		// Card: base 100, contingency 0. add_hours 50 with order 100; multiplier × 2 with order 1.
		// Brief: all add_hours first, then multipliers → (100 + 50) × 2 = 300.
		// A naive global sort by `order` would give 100 × 2 + 50 = 250.
		$card = CardFactory::card(
			[
				'service_lines.web.base_hours' => 100,
				'contingency'                  => 0,
				'factors.web_migration'        => [
					'label'      => 'Late add',
					'applies_to' => [ 'web' ],
					'type'       => 'add_hours',
					'value'      => 50,
					'order'      => 100,
					'per_unit'   => false,
				],
				'factors.web_multilingual'     => [
					'label'      => 'Early multiplier',
					'applies_to' => [ 'web' ],
					'type'       => 'multiplier',
					'value'      => 2,
					'order'      => 1,
					'per_unit'   => false,
				],
			]
		);
		$r    = self::estimate(
			[
				'service_line'     => 'web',
				'web_migration'    => 'yes',
				'web_multilingual' => 'yes',
				'urgency'          => 'normal',
			],
			$card
		);
		$this->assertSame( 300.0, $r->hours );
		$steps = array_column( $r->breakdown, 'step' );
		$this->assertLessThan( array_search( 'multiplier', $steps, true ), array_search( 'add_hours', $steps, true ) );
	}

	public function test_per_unit_factors_multiply_by_the_numeric_answer(): void {
		// 7 templates × 6 h = 42; 3 integrations × 12 h = 36. Hours: 80 + 42 + 36 = 158 × 1.1 = 173.8.
		$r    = self::estimate(
			array_merge(
				self::WEB_BASIC,
				[
					'web_templates'    => 7,
					'web_integrations' => 3,
				]
			)
		);
		$rows = self::rows( $r, 'add_hours' );
		$this->assertCount( 2, $rows );
		$this->assertSame( '7 × 6 h', $rows[0]['input'] );
		$this->assertSame( 42.0, $rows[0]['after'] - $rows[0]['before'] );
		$this->assertSame( '3 × 12 h', $rows[1]['input'] );
		$this->assertSame( 36.0, $rows[1]['after'] - $rows[1]['before'] );
		$this->assertEqualsWithDelta( 173.8, $r->hours, 0.0001 );
	}

	public function test_per_unit_factor_accepts_numeric_string_answers(): void {
		// '4' integrations → 4 × 12 = 48. Hours: 80 + 30 + 48 = 158 × 1.1 = 173.8.
		$r = self::estimate( array_merge( self::WEB_BASIC, [ 'web_integrations' => '4' ] ) );
		$this->assertEqualsWithDelta( 173.8, $r->hours, 0.0001 );
	}

	public function test_zero_unit_per_unit_factor_produces_no_breakdown_row(): void {
		$r = self::estimate( array_merge( self::WEB_BASIC, [ 'web_integrations' => 0 ] ) );
		$this->assertNotContains( 'factors.web_integrations.value', self::sources( $r, 'add_hours' ) );
		$this->assertContains( 'factors.web_templates.value', self::sources( $r, 'add_hours' ) );
	}

	public function test_same_order_factors_tiebreak_by_id_not_declaration_order(): void {
		// Defaults declare web_templates (order 30) before web_migration (order 60); force both to order 30.
		// 'web_migration' < 'web_templates' alphabetically → migration row must come first.
		$card = CardFactory::card( [ 'factors.web_migration.order' => 30 ] );
		$r    = self::estimate( array_merge( self::WEB_BASIC, [ 'web_migration' => 'yes' ] ), $card );
		$this->assertSame( [ 'factors.web_migration.value', 'factors.web_templates.value' ], self::sources( $r, 'add_hours' ) );
	}

	public function test_default_mobile_extras_share_order_and_apply_in_id_order(): void {
		// mobile_auth, mobile_payments, mobile_push all have order 30.
		$r = self::estimate(
			array_merge(
				self::MOBILE,
				[
					'mobile_payments' => 'yes',
					'mobile_backend'  => 'none',
					'hosting'         => 'client',
				]
			)
		);
		$this->assertSame( [ 'factors.mobile_auth.value', 'factors.mobile_payments.value', 'factors.mobile_push.value' ], self::sources( $r, 'add_hours' ) );
	}

	/* ---------- 4./5. urgency & contingency ---------- */

	public function test_unknown_urgency_falls_back_to_normal(): void {
		$normal  = self::estimate( self::WEB_BASIC );
		$unknown = self::estimate( array_merge( self::WEB_BASIC, [ 'urgency' => 'yesterday' ] ) );
		$missing = self::estimate( array_diff_key( self::WEB_BASIC, [ 'urgency' => 1 ] ) );

		$this->assertSame( $normal->hours, $unknown->hours );
		$this->assertSame( $normal->hours, $missing->hours );
		$row = self::rows( $unknown, 'urgency' )[0];
		$this->assertSame( 'normal', $row['input'] );
		$this->assertSame( '× 1', $row['operation'] );
		$this->assertSame( 'urgency.normal', $row['source'] );
	}

	#[DataProvider( 'urgency_provider' )]
	public function test_each_urgency_multiplier( string $urgency, float $mult ): void {
		// Base 80 + 5 templates = 110 → × urgency → × 1.1.
		$r = self::estimate( array_merge( self::WEB_BASIC, [ 'urgency' => $urgency ] ) );
		$this->assertEqualsWithDelta( round( 110 * $mult * 1.1, 2 ), $r->hours, 0.0001 );
		$this->assertSame( "urgency.{$urgency}", self::rows( $r, 'urgency' )[0]['source'] );
	}

	/**
	 * Urgency multipliers from PLAN §3.
	 *
	 * @return array<string, array{0: string, 1: float}>
	 */
	public static function urgency_provider(): array {
		return [
			'flexible' => [ 'flexible', 0.95 ],
			'normal'   => [ 'normal', 1.0 ],
			'urgent'   => [ 'urgent', 1.25 ],
			'asap'     => [ 'asap', 1.5 ],
		];
	}

	public function test_contingency_is_applied_after_urgency(): void {
		// Contingency 25 %: (80 + 1 × 6) × 1.0 × 1.25 = 107.5.
		$card = CardFactory::card( [ 'contingency' => 0.25 ] );
		$r    = self::estimate( array_merge( self::WEB_BASIC, [ 'web_templates' => 1 ] ), $card );
		$row  = self::rows( $r, 'contingency' )[0];
		$this->assertSame( 107.5, $r->hours );
		$this->assertSame( '25%', $row['input'] );
		$this->assertSame( '× 1.25', $row['operation'] );
		$this->assertSame( 86.0, $row['before'] );
		$this->assertSame( 107.5, $row['after'] );
		$this->assertSame( 'contingency', $row['source'] );
		$steps = array_column( $r->breakdown, 'step' );
		$this->assertLessThan( array_search( 'contingency', $steps, true ), array_search( 'urgency', $steps, true ) );
	}

	/* ---------- 6. min-hours clamp ---------- */

	public function test_min_hours_clamp_raises_hours(): void {
		// base 10 + 1 × 6 = 16 h, contingency 0 → clamp to min 40.
		$card = CardFactory::card(
			[
				'service_lines.web.base_hours' => 10,
				'service_lines.web.min_hours'  => 40,
				'contingency'                  => 0,
			]
		);
		$r    = self::estimate( array_merge( self::WEB_BASIC, [ 'web_templates' => 1 ] ), $card );
		$row  = self::rows( $r, 'clamp' )[0];
		$this->assertSame( 40.0, $r->hours );
		$this->assertSame( 16.0, $row['before'] );
		$this->assertSame( 40.0, $row['after'] );
		$this->assertSame( 'service_lines.web.min_hours', $row['source'] );
		// 40 / 30 = 1.33 → ceil 2 → floor 2.
		$this->assertSame( 2, $r->weeks );
		// Price is built on the clamped hours: web band 0 rate = 4 + 22 + 2.5 + 5.25 + 5.6 + 4 = 43.35 → 40 × 43.35 = 1734.
		$this->assertEqualsWithDelta( 1734.0, $r->price, 0.001 );
	}

	public function test_min_hours_clamp_is_a_no_op_above_the_minimum(): void {
		$row = self::rows( self::estimate( self::WORKED ), 'clamp' )[0];
		$this->assertSame( $row['before'], $row['after'] );
	}

	/* ---------- 9. range rounding ---------- */

	public function test_range_rounds_to_250_below_threshold(): void {
		// Flat: 100 h × 50 = 5000. Low 4000 → 4000 (exact); high 6000 → 6000.
		// Use 4910 instead so rounding is visible: 98.2 h × 50 = 4910 → low 3928 → /250 = 15.71 → 16 → 4000;
		// high 5892 → /250 = 23.57 → 24 → 6000.
		$r = self::estimate( [ 'service_line' => 'web' ], CardFactory::flat( 'web', 98.2, 50 ) );
		$this->assertSame( 4910.0, $r->price );
		$this->assertSame( 4000.0, $r->price_low );
		$this->assertSame( 6000.0, $r->price_high );
		// And an asymmetric one: 121 h × 45.91 = 5555.11 → 4444.088 → 4500; 6666.132 → 6750 (see test_web_basic_site).
		$basic = self::estimate( self::WEB_BASIC );
		$this->assertSame( 4500.0, $basic->price_low );
		$this->assertSame( 6750.0, $basic->price_high );
	}

	public function test_range_rounds_to_500_at_and_above_threshold(): void {
		// 400 h × 50 = 20 000 → low 16 000 (exact), high 24 000. Use 401 h: 20 050 → low 16 040 → /500 = 32.08 → 16 000;
		// high 24 060 → /500 = 48.12 → 24 000. With 250-rounding these would be 16 000 and 24 000 too, so also
		// check 407 h: 20 350 → low 16 280 → /500 = 32.56 → 33 → 16 500 (250 would give 16 250);
		// high 24 420 → /500 = 48.84 → 49 → 24 500 (250 would give 24 500 as well).
		$r = self::estimate( [ 'service_line' => 'web' ], CardFactory::flat( 'web', 407, 50 ) );
		$this->assertSame( 20350.0, $r->price );
		$this->assertSame( 16500.0, $r->price_low );
		$this->assertSame( 24500.0, $r->price_high );
	}

	public function test_range_straddling_the_threshold_low_uses_250_high_uses_500(): void {
		// 250 h × 48.5 = 12 125. Low 9700 (< 10k) → /250 = 38.8 → 39 → 9750 (500-rounding would give 9500).
		// High 14 550 (≥ 10k) → /500 = 29.1 → 29 → 14 500 (250-rounding would give 14 500 too, fine).
		$r = self::estimate( [ 'service_line' => 'web' ], CardFactory::flat( 'web', 250, 48.5 ) );
		$this->assertSame( 12125.0, $r->price );
		$this->assertSame( 9750.0, $r->price_low );
		$this->assertSame( 14500.0, $r->price_high );
	}

	public function test_range_low_just_above_the_threshold_uses_500(): void {
		// 250 h × 51.5 = 12 875. Low 10 300 (≥ 10k) → /500 = 20.6 → 21 → 10 500 (250-rounding would give 10 250).
		// High 15 450 → /500 = 30.9 → 31 → 15 500.
		$r = self::estimate( [ 'service_line' => 'web' ], CardFactory::flat( 'web', 250, 51.5 ) );
		$this->assertSame( 12875.0, $r->price );
		$this->assertSame( 10500.0, $r->price_low );
		$this->assertSame( 15500.0, $r->price_high );
		$this->assertSame( [ 'range_spread', 'range_spread' ], self::sources( $r, 'range' ) );
	}

	/* ---------- 10. weeks ---------- */

	public function test_weeks_floor_at_min_weeks(): void {
		// 20 h / 30 = 0.67 → ceil 1 → floored at min_weeks 2.
		$r   = self::estimate( [ 'service_line' => 'web' ], CardFactory::flat( 'web', 20, 50 ) );
		$row = self::rows( $r, 'weeks' )[0];
		$this->assertSame( 2, $r->weeks );
		$this->assertEqualsWithDelta( 0.6667, $row['before'], 0.0001 );
		$this->assertSame( 2.0, $row['after'] );
		$this->assertSame( 'weeks', $row['unit'] );
		// Card-driven floor: min_weeks 3.
		$r3 = self::estimate( [ 'service_line' => 'web' ], CardFactory::flat( 'web', 20, 50, [ 'min_weeks' => 3 ] ) );
		$this->assertSame( 3, $r3->weeks );
	}

	public function test_weeks_ceil(): void {
		// 61 h / 30 = 2.03 → 3; 60 h / 30 = 2 exactly → 2; 90.1 h → 3.003 → 4.
		$this->assertSame( 3, self::estimate( [ 'service_line' => 'web' ], CardFactory::flat( 'web', 61, 50 ) )->weeks );
		$this->assertSame( 2, self::estimate( [ 'service_line' => 'web' ], CardFactory::flat( 'web', 60, 50 ) )->weeks );
		$this->assertSame( 4, self::estimate( [ 'service_line' => 'web' ], CardFactory::flat( 'web', 90.1, 50 ) )->weeks );
		// Capacity is card-driven: 60 h at 20 h/week → 3.
		$this->assertSame( 3, self::estimate( [ 'service_line' => 'web' ], CardFactory::flat( 'web', 60, 50, [ 'weekly_capacity' => 20 ] ) )->weeks );
	}

	/* ---------- 11. reveal bands ---------- */

	#[DataProvider( 'band_provider' )]
	public function test_reveal_band_thresholds( float $hours, string $band, string $source ): void {
		// Flat card at 50/h so price = hours × 50. Small < 10k · Mid 10k–40k · Enterprise ≥ 40k.
		$r = self::estimate( [ 'service_line' => 'web' ], CardFactory::flat( 'web', $hours, 50 ) );
		$this->assertSame( $hours * 50, $r->price );
		$this->assertSame( $band, $r->band );
		$this->assertSame( $source, self::rows( $r, 'band' )[0]['source'] );
	}

	/**
	 * Price → band cases.
	 *
	 * @return array<string, array{0: float, 1: string, 2: string}>
	 */
	public static function band_provider(): array {
		return [
			'5 000 → small'               => [ 100.0, 'small', 'reveal_bands.0.max_price' ],
			'9 950 → small'               => [ 199.0, 'small', 'reveal_bands.0.max_price' ],
			'10 000 exactly → mid'        => [ 200.0, 'mid', 'reveal_bands.1.max_price' ],
			'39 950 → mid'                => [ 799.0, 'mid', 'reveal_bands.1.max_price' ],
			'40 000 exactly → enterprise' => [ 800.0, 'enterprise', 'reveal_bands.2.max_price' ],
			'100 000 → enterprise'        => [ 2000.0, 'enterprise', 'reveal_bands.2.max_price' ],
		];
	}

	public function test_last_reveal_band_is_the_fallback_even_with_a_max_price(): void {
		// Bands: small (≤ 100), big (≤ 200). Price 5000 exceeds both → last band wins.
		$card = CardFactory::flat(
			'web',
			100,
			50,
			[
				'reveal_bands' => [
					[
						'id'        => 'small',
						'label'     => 'Small',
						'max_price' => 100,
					],
					[
						'id'        => 'big',
						'label'     => 'Big',
						'max_price' => 200,
					],
				],
			]
		);
		$r    = self::estimate( [ 'service_line' => 'web' ], $card );
		$this->assertSame( 'big', $r->band );
		$this->assertSame( 'Big', $r->band_label );
		$this->assertSame( 'reveal_bands.1.max_price', self::rows( $r, 'band' )[0]['source'] );
	}

	public function test_no_reveal_bands_yields_unbanded(): void {
		$r = self::estimate( [ 'service_line' => 'web' ], CardFactory::flat( 'web', 100, 50, [ 'reveal_bands' => [] ] ) );
		$this->assertSame( 'unbanded', $r->band );
		$this->assertSame( '', $r->band_label );
	}

	/* ---------- 8. add_price ---------- */

	public function test_add_price_factors_apply_after_hours_x_rate_and_before_the_range(): void {
		// Flat: 100 h × 50 = 5000. add_price migration 500 (order 5) then integrations 3 × 150 = 450 (order 10) → 5950.
		// Range from 5950: low 4760 → /250 = 19.04 → 4750; high 7140 → /250 = 28.56 → 29 → 7250.
		$card = CardFactory::flat(
			'web',
			100,
			50,
			[
				'factors.web_migration'    => [
					'label'      => 'Licence',
					'applies_to' => [ 'web' ],
					'type'       => 'add_price',
					'value'      => 500,
					'order'      => 5,
					'per_unit'   => false,
				],
				'factors.web_integrations' => [
					'label'      => 'Per connector licence',
					'applies_to' => [ 'web' ],
					'type'       => 'add_price',
					'value'      => 150,
					'order'      => 10,
					'per_unit'   => true,
				],
			]
		);
		$r    = self::estimate(
			[
				'service_line'     => 'web',
				'web_migration'    => 'yes',
				'web_integrations' => 3,
			],
			$card
		);
		$this->assertSame( 100.0, $r->hours );
		$this->assertSame( 5950.0, $r->price );
		$this->assertSame( 4750.0, $r->price_low );
		$this->assertSame( 7250.0, $r->price_high );
		$this->assertSame( 'small', $r->band );

		$rows = self::rows( $r, 'add_price' );
		$this->assertCount( 2, $rows );
		$this->assertSame( 'factors.web_migration.value', $rows[0]['source'] );
		$this->assertSame( 5000.0, $rows[0]['before'] );
		$this->assertSame( 5500.0, $rows[0]['after'] );
		$this->assertSame( 'EUR', $rows[0]['unit'] );
		$this->assertSame( 'factors.web_integrations.value', $rows[1]['source'] );
		$this->assertSame( '450', $rows[1]['input'] );
		$this->assertSame( 5950.0, $rows[1]['after'] );

		$steps = array_column( $r->breakdown, 'step' );
		$this->assertLessThan( array_search( 'add_price', $steps, true ), array_search( 'price', $steps, true ) );
		$this->assertLessThan( array_search( 'range', $steps, true ), array_search( 'add_price', $steps, true ) );

		// Zero units → no row, price unchanged by that factor.
		$r0 = self::estimate(
			[
				'service_line'     => 'web',
				'web_migration'    => 'yes',
				'web_integrations' => 0,
			],
			$card
		);
		$this->assertSame( 5500.0, $r0->price );
		$this->assertCount( 1, self::rows( $r0, 'add_price' ) );
	}

	/* ---------- 7. role rates ---------- */

	public function test_missing_role_rates_fall_back_to_blended_rate(): void {
		// (80 + 6) × 1.1 = 94.6 h → web band 0: pm 10 % at 40, every other role missing → blended 45.
		// Rate: 0.10 × 40 + 0.90 × 45 = 4 + 40.5 = 44.5.
		$card = CardFactory::card( [ 'role_rates' => [ 'pm' => 40 ] ] );
		$r    = self::estimate( array_merge( self::WEB_BASIC, [ 'web_templates' => 1 ] ), $card );
		$this->assertEqualsWithDelta( 94.6, $r->hours, 0.0001 );
		$this->assertSame( 0, $r->team['band_index'] );
		$this->assertEqualsWithDelta( 44.5, $r->effective_rate, 0.00001 );
		$this->assertSame( 45.0, $r->team['roles']['sse']['rate'] );
		$this->assertSame( 40.0, $r->team['roles']['pm']['rate'] );
	}

	public function test_zero_role_rate_falls_back_to_blended_rate(): void {
		// Band 0 with sse at 0 → 45: 4 + 0.40 × 45 + 2.5 + 5.25 + 5.6 + 4 = 39.35.
		$card = CardFactory::card( [ 'role_rates.sse' => 0 ] );
		$r    = self::estimate( array_merge( self::WEB_BASIC, [ 'web_templates' => 1 ] ), $card );
		$this->assertEqualsWithDelta( 39.35, $r->effective_rate, 0.00001 );
	}

	public function test_no_role_rates_key_at_all_prices_at_blended_rate(): void {
		$data = CardFactory::data();
		unset( $data['role_rates'] );
		$r = self::estimate( array_merge( self::WEB_BASIC, [ 'web_templates' => 1 ] ), new RateCard( $data ) );
		$this->assertSame( 45.0, $r->effective_rate );
		// 94.6 × 45 = 4257.
		$this->assertEqualsWithDelta( 4257.0, $r->price, 0.001 );
	}

	/* ---------- inputs ---------- */

	public function test_hidden_branch_answers_do_not_price(): void {
		$clean = self::estimate( self::WORKED );
		$dirty = self::estimate(
			array_merge(
				self::WORKED,
				[
					'mobile_offline'      => 'yes',
					'mobile_backend'      => 'needed',
					'mobile_platforms'    => 'both',
					'ai_workflows'        => 5,
					'design_brand'        => 'yes',
					'design_deliverables' => [ 'research' ],
				]
			)
		);
		$this->assertSame( $clean->hours, $dirty->hours );
		$this->assertSame( $clean->price, $dirty->price );
		foreach ( $dirty->breakdown as $row ) {
			$this->assertDoesNotMatchRegularExpression( '/^factors\.(mobile|ai|design)_/', (string) $row['source'] );
		}
	}

	public function test_unknown_service_line_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( "Unknown service line 'print'." );
		self::estimate( [ 'service_line' => 'print' ] );
	}

	public function test_missing_service_line_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		self::estimate( [ 'web_templates' => 5 ] );
	}

	public function test_contact_fields_never_reach_the_result_answers(): void {
		$r = self::estimate(
			array_merge(
				self::WORKED,
				[
					'name'          => 'Ana',
					'email'         => 'ana@example.test',
					'company'       => 'Acme',
					'phone'         => '+40 700 000 000',
					'consent'       => true,
					'unrelated_key' => 'x',
				]
			)
		);
		foreach ( [ 'name', 'email', 'company', 'phone', 'consent', 'unrelated_key' ] as $key ) {
			$this->assertArrayNotHasKey( $key, $r->answers );
		}
		$this->assertSame( 'web', $r->answers['service_line'] );
		$this->assertSame( 5, $r->answers['web_templates'] );
		$this->assertSame( self::WORKED['notes'], $r->answers['notes'] );
		$this->assertSame( array_keys( self::WORKED ), array_keys( $r->answers ) );
	}

	#[DataProvider( 'answer_set_provider' )]
	public function test_breakdown_sources_resolve_on_the_card( array $answers ): void {
		$card = RateCard::defaults();
		$r    = self::estimate( $answers, $card );
		$this->assertNotEmpty( $r->breakdown );
		foreach ( $r->breakdown as $i => $row ) {
			foreach ( [ 'step', 'label', 'input', 'operation', 'before', 'after', 'source', 'unit' ] as $key ) {
				$this->assertArrayHasKey( $key, $row, "row {$i}" );
			}
			if ( in_array( $row['step'], self::CARD_DRIVEN_STEPS, true ) ) {
				$this->assertNotSame( '', $row['source'], "row {$i} ({$row['step']}: {$row['label']}) has no source" );
			}
			if ( '' !== $row['source'] ) {
				$this->assertNotSame( self::SENTINEL, $card->get( (string) $row['source'], self::SENTINEL ), "row {$i} source '{$row['source']}' does not resolve" );
			}
		}
	}

	/**
	 * One answer set per service line plus the worked example.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function answer_set_provider(): array {
		return [
			'web'    => [ self::WEB_BASIC ],
			'worked' => [ self::WORKED ],
			'mobile' => [ self::MOBILE ],
			'design' => [ self::DESIGN ],
			'ai'     => [ self::AI ],
		];
	}

	public function test_breakdown_accessor_matches_result_breakdown(): void {
		$engine = new PricingEngine( RateCard::defaults(), self::WORKED );
		$result = $engine->estimate();
		$this->assertSame( $result->breakdown, $engine->breakdown()->rows() );
	}

	public function test_result_round_trips_through_arrays(): void {
		$r     = self::estimate( self::WORKED );
		$array = $r->to_array();
		$back  = EstimateResult::from_array( $array );
		$this->assertSame( $array, $back->to_array() );
		$this->assertSame( $r->hours, $back->hours );
		$this->assertSame( $r->team, $back->team );
		$this->assertSame( $r->breakdown, $back->breakdown );
		$this->assertSame( $r->qualification_parts, $back->qualification_parts );
		// And survives JSON (as stored on the lead). Loose comparison on purpose: JSON turns
		// integer-valued floats inside `team`/`breakdown` (e.g. before 0.0) into ints, and
		// from_array() only re-types the scalar fields.
		$json = json_decode( (string) json_encode( $array ), true );
		$this->assertEquals( $array, EstimateResult::from_array( $json )->to_array() );
		$this->assertSame( $r->hours, EstimateResult::from_array( $json )->hours );
	}

	/* ---------- 12. qualification ---------- */

	#[DataProvider( 'budget_provider' )]
	public function test_budget_fit_branches( string $budget, string $key, int $points ): void {
		// Flat: 500 h × 50 = 25 000 → range 20 000 – 30 000 (both exact multiples of 500).
		// covers_high: max ≥ 30 000 · overlaps: 20 000 ≤ max < 30 000 · within half: 10 000 ≤ max < 20 000 · far: max < 10 000.
		$r   = self::estimate(
			[
				'service_line' => 'web',
				'budget'       => $budget,
			],
			CardFactory::flat( 'web', 500, 50 )
		);
		$row = self::rows( $r, 'qualification' )[0];
		$this->assertSame( 20000.0, $r->price_low );
		$this->assertSame( 30000.0, $r->price_high );
		$this->assertSame( $points, $r->qualification_parts['budget'] );
		$this->assertSame( "{$budget} → {$key}", $row['input'] );
		$this->assertSame( "qualification.budget.{$key}", $row['source'] );
	}

	/**
	 * Budget band → branch for a 20 000 – 30 000 range.
	 *
	 * @return array<string, array{0: string, 1: string, 2: int}>
	 */
	public static function budget_provider(): array {
		return [
			'40k_100k covers'          => [ '40k_100k', 'covers_high', 40 ],
			'over_100k (no max)'       => [ 'over_100k', 'covers_high', 40 ],
			'15k_40k covers'           => [ '15k_40k', 'covers_high', 40 ],
			'5k_15k within half'       => [ '5k_15k', 'below_within_half', 15 ],
			'under_5k far below'       => [ 'under_5k', 'far_below', 0 ],
			'undisclosed'              => [ 'undisclosed', 'undisclosed', 20 ],
			'unknown id → undisclosed' => [ 'lots', 'undisclosed', 20 ],
		];
	}

	public function test_budget_overlaps_the_range(): void {
		// Flat: 250 h × 50 = 12 500 → range 10 000 – 15 000. 5k_15k max 15 000 < high? no: 15 000 ≥ 15 000 → covers.
		// Use 260 h: 13 000 → low 10 400 → /500 = 20.8 → 10 500; high 15 600 → 31.2 → 15 500.
		// 5k_15k max 15 000: < 15 500, ≥ 10 500 → overlaps 30.
		$r = self::estimate(
			[
				'service_line' => 'web',
				'budget'       => '5k_15k',
			],
			CardFactory::flat( 'web', 260, 50 )
		);
		$this->assertSame( 10500.0, $r->price_low );
		$this->assertSame( 15500.0, $r->price_high );
		$this->assertSame( 30, $r->qualification_parts['budget'] );
		$this->assertSame( '5k_15k → overlaps', self::rows( $r, 'qualification' )[0]['input'] );
	}

	public function test_budget_missing_counts_as_undisclosed(): void {
		$r = self::estimate( [ 'service_line' => 'web' ], CardFactory::flat( 'web', 500, 50 ) );
		$this->assertSame( 20, $r->qualification_parts['budget'] );
	}

	/**
	 * PLAN §3: "below low by <50% → 15", otherwise far below → 0. A budget max
	 * sitting exactly 50 % below the low end is NOT "<50 %" below, so it must
	 * score far_below (0). The engine uses `>=` and awards 15.
	 */
	public function test_budget_exactly_half_of_range_low_is_far_below(): void {
		// Flat: 250 h × 50 = 12 500 → low 10 000. under_5k max 5000 = exactly 50 % of low.
		$r = self::estimate(
			[
				'service_line' => 'web',
				'budget'       => 'under_5k',
			],
			CardFactory::flat( 'web', 250, 50 )
		);
		$this->assertSame( 10000.0, $r->price_low );
		$this->assertSame( 0, $r->qualification_parts['budget'] );
	}

	#[DataProvider( 'urgency_points_provider' )]
	public function test_urgency_points( string $urgency, int $points ): void {
		$r = self::estimate( array_merge( self::WEB_BASIC, [ 'urgency' => $urgency ] ) );
		$this->assertSame( $points, $r->qualification_parts['urgency'] );
		$this->assertSame( "qualification.urgency.{$urgency}", self::rows( $r, 'qualification' )[1]['source'] );
	}

	/**
	 * Urgency points from PLAN §3.
	 *
	 * @return array<string, array{0: string, 1: int}>
	 */
	public static function urgency_points_provider(): array {
		return [
			'urgent'   => [ 'urgent', 15 ],
			'normal'   => [ 'normal', 12 ],
			'asap'     => [ 'asap', 10 ],
			'flexible' => [ 'flexible', 8 ],
		];
	}

	#[DataProvider( 'scope_provider' )]
	public function test_scope_points_by_hours( float $hours, int $points, string $source ): void {
		// Flat card, contingency 0 → hours = base_hours exactly. PLAN: <80 → 8 · 80–300 → 15 · 300–800 → 20 · >800 → 15.
		$r = self::estimate( [ 'service_line' => 'web' ], CardFactory::flat( 'web', $hours, 50 ) );
		$this->assertSame( $hours, $r->hours );
		$this->assertSame( $points, $r->qualification_parts['scope'] );
		$this->assertSame( $source, self::rows( $r, 'qualification' )[2]['source'] );
	}

	/**
	 * Hours → scope points (non-boundary values).
	 *
	 * @return array<string, array{0: float, 1: int, 2: string}>
	 */
	public static function scope_provider(): array {
		return [
			'1 h'   => [ 1.0, 8, 'qualification.scope.0.points' ],
			'79 h'  => [ 79.0, 8, 'qualification.scope.0.points' ],
			'81 h'  => [ 81.0, 15, 'qualification.scope.1.points' ],
			'299 h' => [ 299.0, 15, 'qualification.scope.1.points' ],
			'301 h' => [ 301.0, 20, 'qualification.scope.2.points' ],
			'799 h' => [ 799.0, 20, 'qualification.scope.2.points' ],
			'801 h' => [ 801.0, 15, 'qualification.scope.3.points' ],
		];
	}

	/**
	 * PLAN §3: "scope (20): <80h 8 · 80–300 15". Exactly 80 h is not "<80h",
	 * so it belongs to the 80–300 bucket and scores 15. The engine's
	 * `hours <= max_hours` puts 80 h in the first bucket and scores 8.
	 */
	public function test_scope_exactly_80_hours_scores_15(): void {
		$r = self::estimate( [ 'service_line' => 'web' ], CardFactory::flat( 'web', 80, 50 ) );
		$this->assertSame( 80.0, $r->hours );
		$this->assertSame( 15, $r->qualification_parts['scope'] );
	}

	public function test_scope_with_no_matching_band_scores_zero(): void {
		$card = CardFactory::flat(
			'web',
			80,
			50,
			[
				'qualification.scope' => [
					[
						'max_hours' => 10,
						'points'    => 8,
					],
				],
			]
		);
		$r    = self::estimate( [ 'service_line' => 'web' ], $card );
		$this->assertSame( 0, $r->qualification_parts['scope'] );
	}

	#[DataProvider( 'notes_provider' )]
	public function test_notes_points_need_min_chars( string $notes, int $points ): void {
		$r = self::estimate( array_merge( self::WEB_BASIC, [ 'notes' => $notes ] ) );
		$this->assertSame( $points, $r->qualification_parts['notes'] );
	}

	/**
	 * Notes → points (min_chars 40).
	 *
	 * @return array<string, array{0: string, 1: int}>
	 */
	public static function notes_provider(): array {
		return [
			'empty'                       => [ '', 0 ],
			'39 chars'                    => [ str_repeat( 'a', 39 ), 0 ],
			'40 chars'                    => [ str_repeat( 'a', 40 ), 10 ],
			'41 chars'                    => [ str_repeat( 'a', 41 ), 10 ],
			'40 multibyte chars'          => [ str_repeat( 'ă', 40 ), 10 ],
			'38 chars padded with spaces' => [ '  ' . str_repeat( 'a', 38 ) . '     ', 0 ],
			'whitespace only'             => [ str_repeat( ' ', 50 ), 0 ],
		];
	}

	public function test_notes_missing_scores_zero(): void {
		$r = self::estimate( array_diff_key( self::WEB_BASIC, [ 'notes' => 1 ] ) );
		$this->assertSame( 0, $r->qualification_parts['notes'] );
	}

	public function test_maintenance_and_hosting_points(): void {
		$yes = self::estimate(
			array_merge(
				self::WEB_BASIC,
				[
					'maintenance' => 'yes',
					'hosting'     => 'cybertech',
				]
			)
		);
		$this->assertSame( 10, $yes->qualification_parts['maintenance'] );
		$this->assertSame( 5, $yes->qualification_parts['hosting'] );
		// Hosting by Cybertech also adds 16 h: (80 + 30 + 16) × 1.1 = 138.6.
		$this->assertEqualsWithDelta( 138.6, $yes->hours, 0.0001 );

		$no = self::estimate(
			array_merge(
				self::WEB_BASIC,
				[
					'maintenance' => 'no',
					'hosting'     => 'client',
				]
			)
		);
		$this->assertSame( 0, $no->qualification_parts['maintenance'] );
		$this->assertSame( 0, $no->qualification_parts['hosting'] );

		$undecided = self::estimate( array_merge( self::WEB_BASIC, [ 'hosting' => 'undecided' ] ) );
		$this->assertSame( 0, $undecided->qualification_parts['hosting'] );
		// Undecided hosting still adds 8 h: (80 + 30 + 8) × 1.1 = 129.8.
		$this->assertEqualsWithDelta( 129.8, $undecided->hours, 0.0001 );

		$missing = self::estimate(
			array_diff_key(
				self::WEB_BASIC,
				[
					'maintenance' => 1,
					'hosting'     => 1,
				]
			)
		);
		$this->assertSame( 0, $missing->qualification_parts['maintenance'] );
		$this->assertSame( 0, $missing->qualification_parts['hosting'] );
	}

	public function test_qualification_is_clamped_to_100(): void {
		// Inflate the weights: covers_high 90 + urgent 50 + … > 100 → score 100, parts keep their raw values.
		$card = CardFactory::card(
			[
				'qualification.budget.covers_high' => 90,
				'qualification.urgency.urgent'     => 50,
			]
		);
		$r    = self::estimate( self::WORKED, $card );
		$this->assertSame( 90, $r->qualification_parts['budget'] );
		$this->assertSame( 50, $r->qualification_parts['urgency'] );
		$this->assertGreaterThan( 100, array_sum( $r->qualification_parts ) );
		$this->assertSame( 100, $r->qualification );
		$this->assertSame( 100.0, self::rows( $r, 'qualification' )[6]['after'] );
	}

	public function test_qualification_parts_sum_to_the_score_when_under_100(): void {
		foreach ( [ self::WEB_BASIC, self::MOBILE, self::DESIGN, self::AI ] as $answers ) {
			$r = self::estimate( $answers );
			$this->assertSame( array_sum( $r->qualification_parts ), $r->qualification );
			$this->assertSame( 'pts', self::rows( $r, 'qualification' )[0]['unit'] );
		}
	}
}
