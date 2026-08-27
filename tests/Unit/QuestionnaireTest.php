<?php
/**
 * Questionnaire tests: schema integrity, factor linkage to the rate card,
 * visibility, factor selection and label resolution.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Tests\Unit;

use Cybertech\Estimator\Engine\Questionnaire;
use Cybertech\Estimator\Engine\RateCardDefaults;
use PHPUnit\Framework\TestCase;

/**
 * Questionnaire tests.
 */
final class QuestionnaireTest extends TestCase {

	/* ---------- schema integrity ---------- */

	public function test_question_ids_are_unique_across_steps(): void {
		$ids = [];
		foreach ( Questionnaire::steps() as $step ) {
			$this->assertNotEmpty( $step['id'] );
			foreach ( $step['questions'] as $question ) {
				$ids[] = $question['id'];
			}
		}
		$this->assertSame( $ids, array_unique( $ids ), 'duplicate question ids' );
		$this->assertSame( $ids, array_keys( Questionnaire::questions() ), 'questions() must preserve wizard order and lose nothing' );
	}

	public function test_every_question_has_the_required_shape(): void {
		$types = [ Questionnaire::TYPE_SINGLE, Questionnaire::TYPE_MULTI, Questionnaire::TYPE_NUMBER, Questionnaire::TYPE_TEXT, Questionnaire::TYPE_EMAIL, Questionnaire::TYPE_CHECKBOX ];
		foreach ( Questionnaire::questions() as $id => $q ) {
			$this->assertSame( $id, $q['id'] );
			$this->assertContains( $q['type'], $types, $id );
			$this->assertArrayHasKey( 'label', $q, $id );
			$this->assertArrayHasKey( 'required', $q, $id );
			if ( in_array( $q['type'], [ Questionnaire::TYPE_SINGLE, Questionnaire::TYPE_MULTI ], true ) ) {
				$this->assertNotEmpty( $q['options'], $id );
				foreach ( $q['options'] as $value => $option ) {
					$this->assertIsString( $value, "{$id}.{$value}" );
					$this->assertArrayHasKey( 'label', $option, "{$id}.{$value}" );
					$this->assertArrayHasKey( 'factors', $option, "{$id}.{$value}" );
				}
			}
			if ( Questionnaire::TYPE_NUMBER === $q['type'] ) {
				$this->assertLessThanOrEqual( $q['max'], $q['min'], $id );
				$this->assertGreaterThanOrEqual( $q['min'], $q['default'], $id );
				$this->assertLessThanOrEqual( $q['max'], $q['default'], $id );
			}
			if ( isset( $q['default'] ) && Questionnaire::TYPE_SINGLE === $q['type'] ) {
				$this->assertArrayHasKey( $q['default'], $q['options'], "{$id} default must be an option" );
			}
			if ( isset( $q['default'] ) && Questionnaire::TYPE_MULTI === $q['type'] ) {
				foreach ( $q['default'] as $d ) {
					$this->assertArrayHasKey( $d, $q['options'], "{$id} default must be an option" );
				}
			}
		}
	}

	public function test_service_line_options_match_the_constant_and_the_card(): void {
		$options = array_keys( Questionnaire::questions()['service_line']['options'] );
		$this->assertSame( Questionnaire::SERVICE_LINES, $options );
		$this->assertSame( Questionnaire::SERVICE_LINES, array_keys( RateCardDefaults::card()['service_lines'] ) );
	}

	public function test_every_referenced_factor_exists_and_applies_to_the_question_branch(): void {
		$factors = RateCardDefaults::card()['factors'];
		foreach ( self::factor_references() as [ $question_id, $factor_id, $branches ] ) {
			$this->assertArrayHasKey( $factor_id, $factors, "{$question_id} references unknown factor {$factor_id}" );
			foreach ( $branches as $branch ) {
				$this->assertContains( $branch, $factors[ $factor_id ]['applies_to'], "{$factor_id} (from {$question_id}) must apply to {$branch}" );
			}
		}
	}

	public function test_no_orphan_factors_in_the_default_card(): void {
		$referenced = array_unique( array_column( self::factor_references(), 1 ) );
		$defined    = array_keys( RateCardDefaults::card()['factors'] );
		sort( $referenced );
		sort( $defined );
		$this->assertSame( $defined, $referenced, 'factor ids defined in the card but reachable from no question (or vice-versa)' );
		$this->assertCount( 39, $defined );
	}

	public function test_branch_questions_are_gated_on_service_line(): void {
		foreach ( Questionnaire::questions() as $id => $q ) {
			foreach ( Questionnaire::SERVICE_LINES as $line ) {
				if ( str_starts_with( $id, $line . '_' ) ) {
					$this->assertSame( [ 'service_line' => [ $line ] ], $q['show_if'], "{$id} must be gated on {$line}" );
				}
			}
		}
		foreach ( [ 'service_line', 'urgency', 'budget', 'maintenance', 'hosting', 'notes', 'name', 'email', 'consent' ] as $id ) {
			$this->assertArrayNotHasKey( 'show_if', Questionnaire::questions()[ $id ], "{$id} must always be visible" );
		}
	}

	public function test_contact_questions_are_flagged_and_nothing_else_is(): void {
		$contact = [];
		foreach ( Questionnaire::questions() as $id => $q ) {
			if ( Questionnaire::is_contact_question( $q ) ) {
				$contact[] = $id;
			}
		}
		$this->assertSame( [ 'name', 'email', 'company', 'phone', 'consent' ], $contact );
		$this->assertFalse( Questionnaire::is_contact_question( Questionnaire::questions()['notes'] ) );
		$this->assertFalse( Questionnaire::is_contact_question( [ 'contact' => false ] ) );
		$this->assertFalse( Questionnaire::is_contact_question( [] ) );
	}

	/* ---------- is_visible() ---------- */

	public function test_is_visible(): void {
		$q = Questionnaire::questions();
		$this->assertTrue( Questionnaire::is_visible( $q['service_line'], [] ) );
		$this->assertTrue( Questionnaire::is_visible( $q['urgency'], [] ) );
		$this->assertTrue( Questionnaire::is_visible( $q['web_platform'], [ 'service_line' => 'web' ] ) );
		$this->assertFalse( Questionnaire::is_visible( $q['web_platform'], [ 'service_line' => 'mobile' ] ) );
		$this->assertFalse( Questionnaire::is_visible( $q['web_platform'], [] ) );
		$this->assertFalse( Questionnaire::is_visible( $q['web_platform'], [ 'service_line' => null ] ) );
		$this->assertTrue( Questionnaire::is_visible( $q['ai_hitl'], [ 'service_line' => 'ai' ] ) );
	}

	public function test_is_visible_requires_every_show_if_dependency(): void {
		$question = [
			'id'      => 'x',
			'show_if' => [
				'service_line' => [ 'web', 'mobile' ],
				'web_platform' => 'custom',
			],
		];
		$this->assertTrue(
			Questionnaire::is_visible(
				$question,
				[
					'service_line' => 'mobile',
					'web_platform' => 'custom',
				]
			)
		);
		$this->assertFalse(
			Questionnaire::is_visible(
				$question,
				[
					'service_line' => 'web',
					'web_platform' => 'drupal',
				]
			)
		);
		$this->assertFalse( Questionnaire::is_visible( $question, [ 'web_platform' => 'custom' ] ) );
		// Strict comparison: an integer 1 does not satisfy a string '1'.
		$this->assertFalse( Questionnaire::is_visible( [ 'show_if' => [ 'n' => [ '1' ] ] ], [ 'n' => 1 ] ) );
		$this->assertTrue( Questionnaire::is_visible( [ 'show_if' => [] ], [] ) );
	}

	/* ---------- selected_factors() ---------- */

	public function test_selected_factors_for_a_web_answer_set(): void {
		$selected = Questionnaire::selected_factors(
			[
				'service_line'     => 'web',
				'web_platform'     => 'drupal',
				'web_ecommerce'    => 'magento',
				'web_templates'    => 7,
				'web_multilingual' => 'yes',
				'web_integrations' => '3',
				'web_migration'    => 'no',
				'hosting'          => 'cybertech',
				'urgency'          => 'urgent',
				'budget'           => '5k_15k',
			]
		);
		$this->assertSame(
			[
				'web_platform_drupal'   => 1.0,
				'web_ecommerce_magento' => 1.0,
				'web_templates'         => 7.0,
				'web_multilingual'      => 1.0,
				'web_integrations'      => 3.0,
				'ctx_hosting_cybertech' => 1.0,
			],
			$selected
		);
	}

	public function test_selected_factors_ignores_hidden_branches(): void {
		$selected = Questionnaire::selected_factors(
			[
				'service_line'        => 'design',
				'design_deliverables' => [ 'research', 'prototype' ],
				'design_screens'      => 0,
				'mobile_offline'      => 'yes',
				'mobile_platforms'    => 'both',
				'web_templates'       => 40,
				'ai_workflows'        => 9,
			]
		);
		$this->assertSame(
			[
				'design_deliverable_research'  => 1.0,
				'design_deliverable_prototype' => 1.0,
				'design_screens'               => 0.0,
			],
			$selected
		);
	}

	public function test_selected_factors_ignores_unknown_options_and_baseline_options(): void {
		$selected = Questionnaire::selected_factors(
			[
				'service_line'     => 'web',
				'web_platform'     => 'wordpress',
				'web_ecommerce'    => 'shopify',
				'web_multilingual' => 'maybe',
				'hosting'          => 'client',
			]
		);
		$this->assertSame( [], $selected );
	}

	public function test_selected_factors_multi_accepts_a_scalar_and_number_accepts_a_float(): void {
		$selected = Questionnaire::selected_factors(
			[
				'service_line'        => 'design',
				'design_deliverables' => 'hifi',
				'design_screens'      => 2.5,
			]
		);
		$this->assertSame(
			[
				'design_deliverable_hifi' => 1.0,
				'design_screens'          => 2.5,
			],
			$selected
		);
	}

	public function test_selected_factors_without_a_service_line_yields_only_context_factors(): void {
		$this->assertSame(
			[ 'ctx_hosting_undecided' => 1.0 ],
			Questionnaire::selected_factors(
				[
					'hosting'       => 'undecided',
					'web_templates' => 5,
				]
			)
		);
	}

	/* ---------- resolve_labels() ---------- */

	public function test_resolve_labels_for_each_type(): void {
		$labels = Questionnaire::resolve_labels(
			[
				'service_line'        => 'design',
				'design_deliverables' => [ 'research', 'hifi' ],
				'design_screens'      => 12,
				'design_brand'        => 'yes',
				'urgency'             => 'asap',
				'notes'               => 'Some <b>notes</b>',
				'name'                => 'Ana',
				'consent'             => true,
			]
		);
		$this->assertSame(
			[
				'label' => 'Service line',
				'value' => 'UI/UX Design',
			],
			$labels['service_line']
		);
		$this->assertSame( 'User research, Hi-fi design', $labels['design_deliverables']['value'] );
		$this->assertSame( 'Deliverables', $labels['design_deliverables']['label'] );
		$this->assertSame( '12', $labels['design_screens']['value'] );
		$this->assertSame( 'Yes', $labels['design_brand']['value'] );
		$this->assertSame( 'ASAP', $labels['urgency']['value'] );
		$this->assertSame( 'Some <b>notes</b>', $labels['notes']['value'], 'resolve_labels does not sanitize; that is the input layer' );
		$this->assertSame( 'Ana', $labels['name']['value'] );
		$this->assertSame( 'Yes', $labels['consent']['value'] );
		$this->assertSame( 'No', Questionnaire::resolve_labels( [ 'consent' => false ] )['consent']['value'] );
		$this->assertSame( 'No', Questionnaire::resolve_labels( [ 'consent' => 0 ] )['consent']['value'] );
	}

	public function test_resolve_labels_skips_hidden_and_unanswered_questions(): void {
		$labels = Questionnaire::resolve_labels(
			[
				'service_line'   => 'web',
				'web_platform'   => 'drupal',
				'mobile_offline' => 'yes',
			]
		);
		$this->assertSame( [ 'service_line', 'web_platform' ], array_keys( $labels ) );
		$this->assertSame( 'Drupal', $labels['web_platform']['value'] );
	}

	public function test_resolve_labels_falls_back_to_the_raw_value_for_unknown_options(): void {
		$labels = Questionnaire::resolve_labels(
			[
				'service_line'        => 'design',
				'design_deliverables' => [ 'hifi', 'video' ],
				'urgency'             => 'whenever',
			]
		);
		$this->assertSame( 'Hi-fi design, video', $labels['design_deliverables']['value'] );
		$this->assertSame( 'whenever', $labels['urgency']['value'] );
	}

	/* ---------- helpers ---------- */

	/**
	 * Every (question id, factor id, branches) triple declared by the schema.
	 * Branches = the show_if service lines, or all lines for ungated questions.
	 *
	 * @return array<int, array{0: string, 1: string, 2: array<int, string>}>
	 */
	private static function factor_references(): array {
		$refs = [];
		foreach ( Questionnaire::questions() as $id => $q ) {
			$branches = (array) ( $q['show_if']['service_line'] ?? Questionnaire::SERVICE_LINES );
			foreach ( (array) ( $q['factors'] ?? [] ) as $factor ) {
				$refs[] = [ $id, $factor, $branches ];
			}
			foreach ( (array) ( $q['options'] ?? [] ) as $option ) {
				foreach ( (array) ( $option['factors'] ?? [] ) as $factor ) {
					$refs[] = [ $id, $factor, $branches ];
				}
			}
		}
		return $refs;
	}
}
