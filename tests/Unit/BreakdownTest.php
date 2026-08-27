<?php
/**
 * Breakdown tests.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Tests\Unit;

use Cybertech\Estimator\Engine\Breakdown;
use PHPUnit\Framework\TestCase;

/**
 * Calculation-log tests.
 */
final class BreakdownTest extends TestCase {

	public function test_starts_empty(): void {
		$b = new Breakdown();
		$this->assertSame( [], $b->rows() );
		$this->assertSame( [], $b->rows_for( 'base_hours' ) );
	}

	public function test_add_appends_rows_in_order_with_all_fields(): void {
		$b = new Breakdown();
		$b->add( 'base_hours', 'Web solutions', 'web', '= 80 h', 0.0, 80.0, 'service_lines.web.base_hours' );
		$b->add( 'multiplier', 'E-commerce: Magento', 'magento', '× 1.8', 120.0, 216.0, 'factors.web_ecommerce_magento.value' );
		$b->add( 'price', 'Hours × rate', '216 h × 45', '= 9720', 216.0, 9720.0, '', 'EUR' );

		$rows = $b->rows();
		$this->assertCount( 3, $rows );
		$this->assertSame(
			[
				'step'      => 'multiplier',
				'label'     => 'E-commerce: Magento',
				'input'     => 'magento',
				'operation' => '× 1.8',
				'before'    => 120.0,
				'after'     => 216.0,
				'source'    => 'factors.web_ecommerce_magento.value',
				'unit'      => 'h',
			],
			$rows[1]
		);
		// Defaults: source '' and unit 'h'.
		$this->assertSame( 'service_lines.web.base_hours', $rows[0]['source'] );
		$this->assertSame( 'h', $rows[0]['unit'] );
		$this->assertSame( '', $rows[2]['source'] );
		$this->assertSame( 'EUR', $rows[2]['unit'] );
		$this->assertSame( [ 'base_hours', 'multiplier', 'price' ], array_column( $rows, 'step' ) );
	}

	public function test_rows_for_filters_by_step_and_reindexes(): void {
		$b = new Breakdown();
		$b->add( 'base_hours', 'Base', '', '', 0.0, 80.0 );
		$b->add( 'add_hours', 'A', '', '+ 1', 80.0, 81.0 );
		$b->add( 'multiplier', 'M', '', '× 2', 81.0, 162.0 );
		$b->add( 'add_hours', 'B', '', '+ 2', 162.0, 164.0 );

		$adds = $b->rows_for( 'add_hours' );
		$this->assertSame( [ 0, 1 ], array_keys( $adds ), 'rows_for must reindex from 0' );
		$this->assertSame( [ 'A', 'B' ], array_column( $adds, 'label' ) );
		$this->assertSame( [], $b->rows_for( 'urgency' ) );
		$this->assertCount( 1, $b->rows_for( 'multiplier' ) );
		$this->assertCount( 4, $b->rows() );
	}

	public function test_before_and_after_are_rounded_to_4_decimals(): void {
		$b = new Breakdown();
		$b->add( 'x', 'x', '', '', 1.23456789, 9.87654321 );
		$b->add( 'x', 'x', '', '', 17.94366666, 0.00005 );
		$b->add( 'x', 'x', '', '', 538.3125, 538.31 );

		$rows = $b->rows();
		$this->assertSame( 1.2346, $rows[0]['before'] );
		$this->assertSame( 9.8765, $rows[0]['after'] );
		$this->assertSame( 17.9437, $rows[1]['before'] );
		$this->assertSame( 0.0001, $rows[1]['after'] );
		$this->assertSame( 538.3125, $rows[2]['before'], '4 decimals survive untouched' );
		$this->assertSame( 538.31, $rows[2]['after'] );
	}

	public function test_rows_are_a_copy_not_a_reference(): void {
		$b = new Breakdown();
		$b->add( 'x', 'x', '', '', 0.0, 1.0 );
		$rows             = $b->rows();
		$rows[0]['after'] = 999.0;
		$this->assertSame( 1.0, $b->rows()[0]['after'] );
	}
}
