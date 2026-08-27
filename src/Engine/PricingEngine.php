<?php
/**
 * The pricing engine. PURE: no WordPress calls, no globals, no side effects.
 * Construct with a RateCard and validated answers, call estimate().
 *
 * Calculation order (fixed; every step is logged to the Breakdown):
 *  1. base_hours of the service line
 *  2. add_hours factors (ascending order, then id)
 *  3. multiplier factors (ascending order, then id)
 *  4. urgency multiplier
 *  5. contingency
 *  6. clamp to min_hours
 *  7. team allocation (needed for the rate) → share-weighted hourly rate → price
 *  8. add_price factors
 *  9. range = price × (1 ∓ spread), rounded to the card's increments
 * 10. weeks = ceil(hours / weekly_capacity), floored at min_weeks
 * 11. reveal band
 * 12. qualification score (admin-only)
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Engine;

/**
 * Pure pricing engine.
 */
final class PricingEngine {

	/**
	 * Calculation log for the current run.
	 *
	 * @var Breakdown
	 */
	private Breakdown $breakdown;

	/**
	 * Constructor.
	 *
	 * @param RateCard             $card    Rate card.
	 * @param array<string, mixed> $answers Validated answers (question id => value).
	 */
	public function __construct(
		private readonly RateCard $card,
		private readonly array $answers
	) {
		$this->breakdown = new Breakdown();
	}

	/**
	 * Run the calculation.
	 *
	 * @throws \InvalidArgumentException When the service line is unknown to the card.
	 */
	public function estimate(): EstimateResult {
		$line = (string) ( $this->answers['service_line'] ?? '' );
		if ( ! $this->card->has_service_line( $line ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- pure class, message is developer-facing.
			throw new \InvalidArgumentException( "Unknown service line '{$line}'." );
		}
		$b        = $this->breakdown;
		$selected = Questionnaire::selected_factors( $this->answers );
		$factors  = $this->card->factors_for( $line );

		// 1. Base hours.
		$hours = (float) $this->card->get( "service_lines.{$line}.base_hours" );
		$b->add( 'base_hours', (string) $this->card->get( "service_lines.{$line}.label" ), $line, '= ' . $this->fmt( $hours ) . ' h', 0.0, $hours, "service_lines.{$line}.base_hours" );

		// 2. Additive hours.
		foreach ( $factors as $id => $factor ) {
			if ( 'add_hours' !== $factor['type'] || ! isset( $selected[ $id ] ) ) {
				continue;
			}
			$units = $selected[ $id ];
			$delta = (float) $factor['value'] * ( ! empty( $factor['per_unit'] ) ? $units : 1.0 );
			if ( 0.0 === $delta ) {
				continue;
			}
			$input = ! empty( $factor['per_unit'] ) ? $this->fmt( $units ) . ' × ' . $this->fmt( (float) $factor['value'] ) . ' h' : $this->fmt( (float) $factor['value'] ) . ' h';
			$b->add( 'add_hours', (string) $factor['label'], $input, '+ ' . $this->fmt( $delta ) . ' h', $hours, $hours + $delta, "factors.{$id}.value" );
			$hours += $delta;
		}

		// 3. Multipliers.
		foreach ( $factors as $id => $factor ) {
			if ( 'multiplier' !== $factor['type'] || ! isset( $selected[ $id ] ) ) {
				continue;
			}
			$mult = (float) $factor['value'];
			$b->add( 'multiplier', (string) $factor['label'], (string) $id, '× ' . $this->fmt( $mult ), $hours, $hours * $mult, "factors.{$id}.value" );
			$hours *= $mult;
		}

		// 4. Urgency.
		$urgency = (string) ( $this->answers['urgency'] ?? 'normal' );
		$umult   = $this->card->get( "urgency.{$urgency}" );
		if ( ! is_numeric( $umult ) ) {
			$urgency = 'normal';
			$umult   = $this->card->get( 'urgency.normal', 1.0 );
		}
		$umult = (float) $umult;
		$b->add( 'urgency', 'Urgency', $urgency, '× ' . $this->fmt( $umult ), $hours, $hours * $umult, "urgency.{$urgency}" );
		$hours *= $umult;

		// 5. Contingency.
		$contingency = (float) $this->card->get( 'contingency' );
		$b->add( 'contingency', 'Contingency', $this->fmt( $contingency * 100 ) . '%', '× ' . $this->fmt( 1 + $contingency ), $hours, $hours * ( 1 + $contingency ), 'contingency' );
		$hours *= ( 1 + $contingency );

		// 6. Clamp.
		$min_hours = (float) $this->card->get( "service_lines.{$line}.min_hours" );
		$clamped   = max( $hours, $min_hours );
		$b->add( 'clamp', 'Minimum hours', $this->fmt( $min_hours ) . ' h', 'max(hours, min)', $hours, $clamped, "service_lines.{$line}.min_hours" );
		$hours = round( $clamped, 2 );

		// 7. Team → rate → price.
		$composer = new TeamComposer( $this->card );
		$team     = $composer->compose( $line, $hours );
		$rate     = $composer->effective_rate( $team['roles'] );
		$shares   = [];
		foreach ( $team['roles'] as $role => $r ) {
			$shares[] = $role . ' ' . $this->fmt( $r['share'] * 100 ) . '%';
		}
		$b->add( 'team', 'Team allocation', implode( ', ', $shares ), 'band ' . ( $team['band_index'] + 1 ), $hours, $hours, $team['source'] );
		$b->add( 'rate', 'Effective hourly rate', 'Σ share × role rate', '= ' . $this->fmt( $rate ), 0.0, $rate, 'role_rates', $this->card->currency() . '/h' );
		$price = round( $hours * $rate, 2 );
		$b->add( 'price', 'Hours × rate', $this->fmt( $hours ) . ' h × ' . $this->fmt( $rate ), '= ' . $this->fmt( $price ), $hours, $price, '', $this->card->currency() );

		// 8. Additive price.
		foreach ( $factors as $id => $factor ) {
			if ( 'add_price' !== $factor['type'] || ! isset( $selected[ $id ] ) ) {
				continue;
			}
			$units = $selected[ $id ];
			$delta = (float) $factor['value'] * ( ! empty( $factor['per_unit'] ) ? $units : 1.0 );
			if ( 0.0 === $delta ) {
				continue;
			}
			$b->add( 'add_price', (string) $factor['label'], $this->fmt( $delta ), '+ ' . $this->fmt( $delta ), $price, $price + $delta, "factors.{$id}.value", $this->card->currency() );
			$price += $delta;
		}

		// 9. Range.
		$spread = (float) $this->card->get( 'range_spread' );
		$low    = $this->round_price( $price * ( 1 - $spread ) );
		$high   = $this->round_price( $price * ( 1 + $spread ) );
		$b->add( 'range', 'Range low', '−' . $this->fmt( $spread * 100 ) . '%, rounded', '× ' . $this->fmt( 1 - $spread ), $price, $low, 'range_spread', $this->card->currency() );
		$b->add( 'range', 'Range high', '+' . $this->fmt( $spread * 100 ) . '%, rounded', '× ' . $this->fmt( 1 + $spread ), $price, $high, 'range_spread', $this->card->currency() );

		// 10. Weeks.
		$capacity  = (float) $this->card->get( 'weekly_capacity' );
		$min_weeks = (int) $this->card->get( 'min_weeks' );
		$raw_weeks = $hours / $capacity;
		$weeks     = max( $min_weeks, (int) ceil( $raw_weeks ) );
		$b->add( 'weeks', 'Duration', $this->fmt( $hours ) . ' h ÷ ' . $this->fmt( $capacity ) . ' h/week', 'ceil, min ' . $min_weeks, $raw_weeks, (float) $weeks, 'weekly_capacity', 'weeks' );

		// 11. Reveal band.
		[ $band, $band_label, $band_source ] = $this->band_for( $price );
		$b->add( 'band', 'Engagement band', $this->fmt( $price ), $band_label, $price, $price, $band_source, $this->card->currency() );

		// 12. Qualification.
		[ $score, $parts ] = $this->qualify( $hours, $low, $high );

		return new EstimateResult(
			$line,
			$hours,
			$price,
			$low,
			$high,
			$this->card->currency(),
			$weeks,
			$team,
			$rate,
			$band,
			$band_label,
			$score,
			$parts,
			$b->rows(),
			$this->pricing_answers(),
			$this->card->version()
		);
	}

	/**
	 * The breakdown of the last run (also embedded in the result).
	 */
	public function breakdown(): Breakdown {
		return $this->breakdown;
	}

	/**
	 * Round to the card's increment: `below` under the threshold, `above` from it.
	 *
	 * @param float $value Price.
	 */
	private function round_price( float $value ): float {
		$threshold = (float) $this->card->get( 'rounding.threshold' );
		$inc       = (float) ( $value < $threshold ? $this->card->get( 'rounding.below' ) : $this->card->get( 'rounding.above' ) );
		return round( $value / $inc ) * $inc;
	}

	/**
	 * First reveal band whose max_price is null or >= price.
	 *
	 * @param float $price Point price.
	 * @return array{0: string, 1: string, 2: string}
	 */
	private function band_for( float $price ): array {
		$bands = (array) $this->card->get( 'reveal_bands', [] );
		$last  = count( $bands ) - 1;
		foreach ( $bands as $i => $band ) {
			$max = $band['max_price'] ?? null;
			if ( null === $max || $price < (float) $max || $i === $last ) {
				return [ (string) $band['id'], (string) $band['label'], "reveal_bands.{$i}.max_price" ];
			}
		}
		return [ 'unbanded', '', '' ];
	}

	/**
	 * Qualification score 0–100 from the card's `qualification` weights.
	 *
	 * @param float $hours Final hours.
	 * @param float $low   Range low.
	 * @param float $high  Range high.
	 * @return array{0: int, 1: array<string, int>}
	 */
	private function qualify( float $hours, float $low, float $high ): array {
		$q     = (array) $this->card->get( 'qualification', [] );
		$parts = [];
		$b     = $this->breakdown;

		// Budget vs range.
		$budget_id = (string) ( $this->answers['budget'] ?? 'undisclosed' );
		$band      = (array) $this->card->get(
			"budget_bands.{$budget_id}",
			[
				'min' => null,
				'max' => null,
			]
		);
		$bmin      = $band['min'] ?? null;
		$bmax      = $band['max'] ?? null;
		if ( null === $bmin && null === $bmax ) {
			$key = 'undisclosed';
		} elseif ( null === $bmax || (float) $bmax >= $high ) {
			$key = 'covers_high';
		} elseif ( (float) $bmax >= $low ) {
			$key = 'overlaps';
		} elseif ( (float) $bmax >= $low / 2 ) {
			$key = 'below_within_half';
		} else {
			$key = 'far_below';
		}
		$parts['budget'] = (int) ( $q['budget'][ $key ] ?? 0 );
		$b->add( 'qualification', 'Budget fit', $budget_id . ' → ' . $key, '+ ' . $parts['budget'], 0.0, (float) $parts['budget'], "qualification.budget.{$key}", 'pts' );

		// Urgency.
		$urgency          = (string) ( $this->answers['urgency'] ?? 'normal' );
		$parts['urgency'] = (int) ( $q['urgency'][ $urgency ] ?? 0 );
		$b->add( 'qualification', 'Urgency signal', $urgency, '+ ' . $parts['urgency'], 0.0, (float) $parts['urgency'], "qualification.urgency.{$urgency}", 'pts' );

		// Scope by hours.
		$parts['scope'] = 0;
		$scope_src      = '';
		foreach ( (array) ( $q['scope'] ?? [] ) as $i => $row ) {
			$max = $row['max_hours'] ?? null;
			if ( null === $max || $hours <= (float) $max ) {
				$parts['scope'] = (int) $row['points'];
				$scope_src      = "qualification.scope.{$i}.points";
				break;
			}
		}
		$b->add( 'qualification', 'Scope size', $this->fmt( $hours ) . ' h', '+ ' . $parts['scope'], 0.0, (float) $parts['scope'], $scope_src, 'pts' );

		// Free text.
		$notes          = trim( (string) ( $this->answers['notes'] ?? '' ) );
		$min_chars      = (int) ( $q['notes']['min_chars'] ?? 0 );
		$parts['notes'] = mb_strlen( $notes ) >= $min_chars && '' !== $notes ? (int) ( $q['notes']['points'] ?? 0 ) : 0;
		$b->add( 'qualification', 'Left a description', mb_strlen( $notes ) . ' chars', '+ ' . $parts['notes'], 0.0, (float) $parts['notes'], 'qualification.notes.points', 'pts' );

		// Maintenance interest.
		$parts['maintenance'] = 'yes' === ( $this->answers['maintenance'] ?? 'no' ) ? (int) ( $q['maintenance']['points'] ?? 0 ) : 0;
		$b->add( 'qualification', 'Maintenance interest', (string) ( $this->answers['maintenance'] ?? 'no' ), '+ ' . $parts['maintenance'], 0.0, (float) $parts['maintenance'], 'qualification.maintenance.points', 'pts' );

		// Hosting by the agency.
		$parts['hosting'] = 'cybertech' === ( $this->answers['hosting'] ?? '' ) ? (int) ( $q['hosting']['points'] ?? 0 ) : 0;
		$b->add( 'qualification', 'Hosting with us', (string) ( $this->answers['hosting'] ?? '' ), '+ ' . $parts['hosting'], 0.0, (float) $parts['hosting'], 'qualification.hosting.points', 'pts' );

		$score = max( 0, min( 100, array_sum( $parts ) ) );
		$b->add( 'qualification', 'Qualification score', 'Σ parts', '= ' . $score, 0.0, (float) $score, 'qualification', 'pts' );

		return [ $score, $parts ];
	}

	/**
	 * Answers that influenced pricing (contact fields excluded).
	 *
	 * @return array<string, mixed>
	 */
	private function pricing_answers(): array {
		$out = [];
		foreach ( Questionnaire::questions() as $id => $question ) {
			if ( Questionnaire::is_contact_question( $question ) || ! array_key_exists( $id, $this->answers ) ) {
				continue;
			}
			$out[ $id ] = $this->answers[ $id ];
		}
		return $out;
	}

	/**
	 * Compact number formatting for breakdown text (locale-neutral).
	 *
	 * @param float $n Number.
	 */
	private function fmt( float $n ): string {
		return rtrim( rtrim( number_format( $n, 2, '.', '' ), '0' ), '.' );
	}
}
