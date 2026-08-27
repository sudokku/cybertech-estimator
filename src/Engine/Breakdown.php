<?php
/**
 * Ordered log of every calculation step. This is what makes the pricing
 * model legible: the sandbox renders it as a table and links each row's
 * `source` to the rate-card field that produced it.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Engine;

/**
 * Calculation log.
 */
final class Breakdown {

	/**
	 * Rows in application order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $rows = [];

	/**
	 * Append a step.
	 *
	 * @param string $step      Step id (base_hours, add_hours, multiplier, urgency, contingency, clamp, team, rate, price, add_price, range, weeks, qualification).
	 * @param string $label     Human label.
	 * @param string $input     The answer/value that triggered the step.
	 * @param string $operation Operation as text, e.g. '× 1.8' or '+ 24 h'.
	 * @param float  $before    Value before.
	 * @param float  $after     Value after.
	 * @param string $source    Dot path into the rate card ('' when not card-driven).
	 * @param string $unit      'h' | currency code | 'weeks' | 'pts' | ''.
	 */
	public function add( string $step, string $label, string $input, string $operation, float $before, float $after, string $source = '', string $unit = 'h' ): void {
		$this->rows[] = [
			'step'      => $step,
			'label'     => $label,
			'input'     => $input,
			'operation' => $operation,
			'before'    => round( $before, 4 ),
			'after'     => round( $after, 4 ),
			'source'    => $source,
			'unit'      => $unit,
		];
	}

	/**
	 * All rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function rows(): array {
		return $this->rows;
	}

	/**
	 * Rows of a given step id.
	 *
	 * @param string $step Step id.
	 * @return array<int, array<string, mixed>>
	 */
	public function rows_for( string $step ): array {
		return array_values( array_filter( $this->rows, static fn( array $r ): bool => $r['step'] === $step ) );
	}
}
