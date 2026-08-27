<?php
/**
 * ResponseValidator tests: the contract every model response (and the
 * fallback) must satisfy before anything is shown to a visitor.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Tests\Unit;

use Cybertech\Estimator\Ai\ResponseValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Response validator tests.
 */
final class ResponseValidatorTest extends TestCase {

	private const WEEKS = 10;

	/**
	 * A valid payload whose phases sum to 10 weeks.
	 *
	 * @param array<string, mixed> $overrides Top-level overrides.
	 * @return array<string, mixed>
	 */
	private static function payload( array $overrides = [] ): array {
		return array_replace(
			[
				'headline'    => 'A clear plan for your new site',
				'summary'     => 'We build it in three phases. Reviews happen weekly.',
				'phases'      => [
					self::phase( 'Discovery', 2, [ 'Project manager', 'Designer' ] ),
					self::phase( 'Build', 6, [ 'Senior software engineer' ] ),
					self::phase( 'Launch', 2, [ 'QA' ] ),
				],
				'assumptions' => [ 'You provide content.' ],
				'risks'       => [ 'Scope may shift.' ],
			],
			$overrides
		);
	}

	/**
	 * One phase.
	 *
	 * @param string             $name  Name.
	 * @param mixed              $weeks Weeks.
	 * @param array<int, string> $roles Roles.
	 * @return array<string, mixed>
	 */
	private static function phase( string $name, mixed $weeks, array $roles = [] ): array {
		return [
			'name'        => $name,
			'weeks'       => $weeks,
			'description' => $name . ' work.',
			'roles'       => $roles,
		];
	}

	/**
	 * Validate an array payload.
	 *
	 * @param array<string, mixed> $payload Payload.
	 * @param int                  $weeks   Weeks.
	 * @return array{ok: bool, data: array<string, mixed>, errors: array<int, string>, warnings: array<int, string>}
	 */
	private static function validate( array $payload, int $weeks = self::WEEKS ): array {
		return ResponseValidator::validate( (string) json_encode( $payload ), $weeks );
	}

	/* ---------- fences & JSON ---------- */

	public function test_strip_fences(): void {
		$this->assertSame( '{"a":1}', ResponseValidator::strip_fences( "```json\n{\"a\":1}\n```" ) );
		$this->assertSame( '{"a":1}', ResponseValidator::strip_fences( "```\n{\"a\":1}\n```" ) );
		$this->assertSame( '{"a":1}', ResponseValidator::strip_fences( "  \n```json  {\"a\":1}  ```\n\n" ) );
		$this->assertSame( '{"a":1}', ResponseValidator::strip_fences( "  {\"a\":1}\n" ), 'no fences → just trimmed' );
		$this->assertSame( "```json\n{\"a\":1}", ResponseValidator::strip_fences( "```json\n{\"a\":1}" ), 'unclosed fence is left alone' );
	}

	public function test_fenced_json_validates(): void {
		$json = (string) json_encode( self::payload() );
		foreach ( [ "```json\n{$json}\n```", "```\n{$json}\n```", "\n\n{$json}\n" ] as $raw ) {
			$result = ResponseValidator::validate( $raw, self::WEEKS );
			$this->assertTrue( $result['ok'], $raw );
			$this->assertSame( [], $result['errors'] );
		}
	}

	#[DataProvider( 'invalid_json_provider' )]
	public function test_invalid_json( string $raw ): void {
		$result = ResponseValidator::validate( $raw, self::WEEKS );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( [ 'invalid_json' ], $result['errors'] );
		$this->assertSame( [], $result['data'] );
		$this->assertSame( [], $result['warnings'] );
	}

	/**
	 * Non-JSON and non-object JSON.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function invalid_json_provider(): array {
		return [
			'prose'          => [ 'Here is your estimate narrative.' ],
			'empty'          => [ '' ],
			'truncated'      => [ '{"headline": "x", "summary"' ],
			'string literal' => [ '"headline"' ],
			'number'         => [ '42' ],
			'null'           => [ 'null' ],
			'fenced prose'   => [ "```json\nnot json\n```" ],
		];
	}

	/* ---------- structure ---------- */

	public function test_all_keys_missing_are_reported_in_order(): void {
		$result = ResponseValidator::validate( '{}', self::WEEKS );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( [ 'missing:headline', 'missing:summary', 'missing:phases', 'missing:assumptions', 'missing:risks' ], $result['errors'] );
		$this->assertSame( [ 'missing:headline', 'missing:summary', 'missing:phases', 'missing:assumptions', 'missing:risks' ], ResponseValidator::validate( '[]', self::WEEKS )['errors'] );
	}

	public function test_single_missing_key(): void {
		$payload = self::payload();
		unset( $payload['risks'] );
		$this->assertSame( [ 'missing:risks' ], self::validate( $payload )['errors'] );
	}

	#[DataProvider( 'wrong_type_provider' )]
	public function test_wrong_types( string $key, mixed $value ): void {
		$result = self::validate( self::payload( [ $key => $value ] ) );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( [ "type:{$key}" ], $result['errors'] );
	}

	/**
	 * Key → wrongly typed value.
	 *
	 * @return array<string, array{0: string, 1: mixed}>
	 */
	public static function wrong_type_provider(): array {
		return [
			'headline int'       => [ 'headline', 12 ],
			'headline null'      => [ 'headline', null ],
			'summary array'      => [ 'summary', [ 'a' ] ],
			'phases string'      => [ 'phases', 'Discovery, Build' ],
			'assumptions string' => [ 'assumptions', 'none' ],
			'risks bool'         => [ 'risks', false ],
		];
	}

	public function test_structural_errors_short_circuit_content_checks(): void {
		// Headline too long AND phases the wrong type: only the type error is reported.
		$result = self::validate(
			self::payload(
				[
					'headline' => str_repeat( 'x', 200 ),
					'phases'   => 'nope',
				]
			)
		);
		$this->assertSame( [ 'type:phases' ], $result['errors'] );
	}

	/* ---------- lengths & counts ---------- */

	public function test_headline_length(): void {
		$this->assertTrue( self::validate( self::payload( [ 'headline' => str_repeat( 'h', 90 ) ] ) )['ok'], '90 chars is the maximum' );
		$this->assertTrue( self::validate( self::payload( [ 'headline' => str_repeat( 'ă', 90 ) ] ) )['ok'], 'multibyte counts characters, not bytes' );
		$this->assertSame( [ 'headline_length' ], self::validate( self::payload( [ 'headline' => str_repeat( 'h', 91 ) ] ) )['errors'] );
		$this->assertSame( [ 'headline_length' ], self::validate( self::payload( [ 'headline' => '' ] ) )['errors'] );
		$this->assertSame( [ 'headline_length' ], self::validate( self::payload( [ 'headline' => '   ' ] ) )['errors'], 'whitespace-only is empty' );
	}

	public function test_summary_length(): void {
		$this->assertTrue( self::validate( self::payload( [ 'summary' => str_repeat( 's', 600 ) ] ) )['ok'] );
		$this->assertSame( [ 'summary_length' ], self::validate( self::payload( [ 'summary' => str_repeat( 's', 601 ) ] ) )['errors'] );
		$this->assertSame( [ 'summary_length' ], self::validate( self::payload( [ 'summary' => '' ] ) )['errors'] );
	}

	public function test_phases_count(): void {
		$empty = self::validate( self::payload( [ 'phases' => [] ] ) );
		$this->assertFalse( $empty['ok'] );
		$this->assertContains( 'phases_count', $empty['errors'] );

		$six   = array_fill( 0, 6, self::phase( 'P', 1 ) );
		$seven = array_fill( 0, 7, self::phase( 'P', 1 ) );
		$this->assertTrue( self::validate( self::payload( [ 'phases' => $six ] ), 6 )['ok'] );
		$this->assertSame( [ 'phases_count' ], self::validate( self::payload( [ 'phases' => $seven ] ), 7 )['errors'] );
	}

	#[DataProvider( 'phase_shape_provider' )]
	public function test_phase_shape_errors( mixed $bad_phase ): void {
		// Bad phase at index 1; the other two sum to 4 so weeks_mismatch fires too — assert the shape error specifically.
		$result = self::validate( self::payload( [ 'phases' => [ self::phase( 'A', 2 ), $bad_phase, self::phase( 'C', 2 ) ] ] ) );
		$this->assertFalse( $result['ok'] );
		$this->assertContains( 'phase:1:shape', $result['errors'] );
	}

	/**
	 * Malformed phases.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function phase_shape_provider(): array {
		return [
			'string'              => [ 'Build (6 weeks)' ],
			'number'              => [ 6 ],
			'missing name'        => [
				[
					'weeks'       => 6,
					'description' => 'x',
				],
			],
			'missing weeks'       => [
				[
					'name'        => 'Build',
					'description' => 'x',
				],
			],
			'missing description' => [
				[
					'name'  => 'Build',
					'weeks' => 6,
				],
			],
			'non-numeric weeks'   => [ self::phase( 'Build', 'six' ) ],
			'null weeks'          => [ self::phase( 'Build', null ) ],
		];
	}

	public function test_phase_name_and_description_length(): void {
		$long_name = self::phase( str_repeat( 'n', 61 ), 2 );
		$this->assertSame( [ 'phase:0:name_length' ], self::validate( self::payload( [ 'phases' => [ $long_name, self::phase( 'B', 6 ), self::phase( 'C', 2 ) ] ] ) )['errors'] );
		$ok_name = self::phase( str_repeat( 'n', 60 ), 2 );
		$this->assertTrue( self::validate( self::payload( [ 'phases' => [ $ok_name, self::phase( 'B', 6 ), self::phase( 'C', 2 ) ] ] ) )['ok'] );
		$empty_name = self::phase( '', 2 );
		$this->assertSame( [ 'phase:0:name_length' ], self::validate( self::payload( [ 'phases' => [ $empty_name, self::phase( 'B', 6 ), self::phase( 'C', 2 ) ] ] ) )['errors'] );

		$long_desc                = self::phase( 'B', 6 );
		$long_desc['description'] = str_repeat( 'd', 301 );
		$this->assertSame( [ 'phase:1:description_length' ], self::validate( self::payload( [ 'phases' => [ self::phase( 'A', 2 ), $long_desc, self::phase( 'C', 2 ) ] ] ) )['errors'] );
		$long_desc['description'] = str_repeat( 'd', 300 );
		$this->assertTrue( self::validate( self::payload( [ 'phases' => [ self::phase( 'A', 2 ), $long_desc, self::phase( 'C', 2 ) ] ] ) )['ok'] );
		// An empty description is tolerated (only the maximum is enforced).
		$long_desc['description'] = '';
		$this->assertTrue( self::validate( self::payload( [ 'phases' => [ self::phase( 'A', 2 ), $long_desc, self::phase( 'C', 2 ) ] ] ) )['ok'] );
	}

	public function test_negative_weeks(): void {
		// -1 + 6 + 5 = 10, so only the sign error fires.
		$result = self::validate( self::payload( [ 'phases' => [ self::phase( 'A', -1 ), self::phase( 'B', 6 ), self::phase( 'C', 5 ) ] ] ) );
		$this->assertSame( [ 'phase:0:negative_weeks' ], $result['errors'] );
		// Zero is allowed.
		$this->assertTrue( self::validate( self::payload( [ 'phases' => [ self::phase( 'A', 0 ), self::phase( 'B', 6 ), self::phase( 'C', 4 ) ] ] ) )['ok'] );
	}

	#[DataProvider( 'weeks_sum_provider' )]
	public function test_weeks_sum_tolerance( float $last, bool $ok, ?string $error ): void {
		// 2 + 6 + $last vs 10 weeks; tolerance ±1 inclusive.
		$result = self::validate( self::payload( [ 'phases' => [ self::phase( 'A', 2 ), self::phase( 'B', 6 ), self::phase( 'C', $last ) ] ] ) );
		$this->assertSame( $ok, $result['ok'] );
		$this->assertSame( null === $error ? [] : [ $error ], $result['errors'] );
	}

	/**
	 * Last-phase weeks → sum → verdict.
	 *
	 * @return array<string, array{0: float, 1: bool, 2: ?string}>
	 */
	public static function weeks_sum_provider(): array {
		return [
			'sum 10 exact'   => [ 2.0, true, null ],
			'sum 9 (−1)'     => [ 1.0, true, null ],
			'sum 11 (+1)'    => [ 3.0, true, null ],
			'sum 9.5'        => [ 1.5, true, null ],
			'sum 8.9 (−1.1)' => [ 0.9, false, 'weeks_mismatch:8.9!=10' ],
			'sum 8 (−2)'     => [ 0.0, false, 'weeks_mismatch:8!=10' ],
			'sum 12 (+2)'    => [ 4.0, false, 'weeks_mismatch:12!=10' ],
			'sum 20'         => [ 12.0, false, 'weeks_mismatch:20!=10' ],
		];
	}

	public function test_weeks_are_rounded_to_one_decimal_and_numeric_strings_accepted(): void {
		$result = self::validate( self::payload( [ 'phases' => [ self::phase( 'A', '2' ), self::phase( 'B', 5.96 ), self::phase( 'C', 2.04 ) ] ] ) );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( [ 2.0, 6.0, 2.0 ], array_column( $result['data']['phases'], 'weeks' ) );
	}

	public function test_assumptions_and_risks_counts(): void {
		$this->assertTrue( self::validate( self::payload( [ 'assumptions' => array_fill( 0, 4, 'a' ) ] ) )['ok'] );
		$this->assertSame( [ 'assumptions_count' ], self::validate( self::payload( [ 'assumptions' => array_fill( 0, 5, 'a' ) ] ) )['errors'] );
		$this->assertTrue( self::validate( self::payload( [ 'risks' => array_fill( 0, 3, 'r' ) ] ) )['ok'] );
		$this->assertSame( [ 'risks_count' ], self::validate( self::payload( [ 'risks' => array_fill( 0, 4, 'r' ) ] ) )['errors'] );
		// Empty lists are fine.
		$this->assertTrue(
			self::validate(
				self::payload(
					[
						'assumptions' => [],
						'risks'       => [],
					]
				)
			)['ok']
		);
	}

	public function test_blank_and_non_string_list_items_are_dropped_before_counting(): void {
		$result = self::validate( self::payload( [ 'assumptions' => [ 'a', '', '   ', 'b', null, 3, [ 'x' ] ] ] ) );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( [ 'a', 'b' ], $result['data']['assumptions'] );
	}

	public function test_list_item_length(): void {
		$this->assertTrue( self::validate( self::payload( [ 'risks' => [ str_repeat( 'r', 200 ) ] ] ) )['ok'] );
		$this->assertSame( [ 'list_item_length' ], self::validate( self::payload( [ 'risks' => [ str_repeat( 'r', 201 ) ] ] ) )['errors'] );
		// Reported once even when several items are too long, across both lists.
		$result = self::validate(
			self::payload(
				[
					'assumptions' => [ str_repeat( 'a', 201 ), str_repeat( 'a', 300 ) ],
					'risks'       => [ str_repeat( 'r', 201 ) ],
				]
			)
		);
		$this->assertSame( [ 'list_item_length' ], $result['errors'] );
	}

	/* ---------- HTML & whitespace ---------- */

	public function test_html_is_stripped_with_a_warning_but_still_ok(): void {
		$result = self::validate(
			self::payload(
				[
					'headline' => '<b>Bold</b> plan',
					'summary'  => '<script>alert(1)</script>Safe summary <a href="x">link</a>.',
				]
			)
		);
		$this->assertTrue( $result['ok'] );
		$this->assertSame( [], $result['errors'] );
		$this->assertSame( [ 'html_stripped' ], $result['warnings'] );
		$this->assertSame( 'Bold plan', $result['data']['headline'] );
		$this->assertSame( 'Safe summary link.', $result['data']['summary'] );
	}

	public function test_html_in_phases_roles_and_lists_is_stripped(): void {
		$phase                = self::phase( '<em>Build</em>', 6, [ '<i>QA</i>' ] );
		$phase['description'] = 'Ship <br> it';
		$result               = self::validate(
			self::payload(
				[
					'phases'      => [ self::phase( 'A', 2 ), $phase, self::phase( 'C', 2 ) ],
					'assumptions' => [ '<p>Content ready</p>' ],
					'risks'       => [ 'Scope<script>x</script>' ],
				]
			)
		);
		$this->assertTrue( $result['ok'] );
		$this->assertSame( [ 'html_stripped' ], $result['warnings'] );
		$this->assertSame( 'Build', $result['data']['phases'][1]['name'] );
		$this->assertSame( 'Ship it', $result['data']['phases'][1]['description'] );
		$this->assertSame( [ 'QA' ], $result['data']['phases'][1]['roles'] );
		$this->assertSame( [ 'Content ready' ], $result['data']['assumptions'] );
		$this->assertSame( [ 'Scope' ], $result['data']['risks'] );
	}

	public function test_html_stripping_cannot_hide_money(): void {
		// Tags split the currency word; after stripping it is "EUR" again → rejected.
		$result = self::validate( self::payload( [ 'summary' => 'About 12<b>,</b>500 EU<i>R</i>' ] ) );
		$this->assertFalse( $result['ok'] );
		$this->assertContains( 'money_detected', $result['errors'] );
	}

	public function test_whitespace_is_normalised(): void {
		$result = self::validate(
			self::payload(
				[
					'headline'    => "A\t\tplan   with\n\nspaces",
					'assumptions' => [ " padded \n" ],
				]
			)
		);
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'A plan with spaces', $result['data']['headline'] );
		$this->assertSame( [ 'padded' ], $result['data']['assumptions'] );
	}

	/**
	 * Whitespace is not HTML. Padding and repeated spaces are normalised
	 * silently; the `html_stripped` warning must only fire when tags were
	 * actually removed, otherwise every model reply with a trailing newline
	 * is logged as a stripping incident.
	 */
	public function test_whitespace_only_differences_do_not_warn_html_stripped(): void {
		$result = self::validate( self::payload( [ 'headline' => "  A plan with  padding \n" ] ) );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'A plan with padding', $result['data']['headline'] );
		$this->assertSame( [], $result['warnings'] );
	}

	public function test_no_warning_when_there_is_nothing_to_strip(): void {
		$result = self::validate( self::payload() );
		$this->assertSame( [], $result['warnings'] );
	}

	/* ---------- money ---------- */

	#[DataProvider( 'money_provider' )]
	public function test_contains_money_detects_every_pattern( string $text ): void {
		$this->assertTrue( ResponseValidator::contains_money( $text ), $text );
	}

	/**
	 * Strings that must be treated as money.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function money_provider(): array {
		return [
			'euro sign'       => [ 'around €5000' ],
			'dollar sign'     => [ '$ 500 extra' ],
			'pound sign'      => [ '£1' ],
			'EUR'             => [ 'about 5000 EUR' ],
			'eur lowercase'   => [ '5000 eur' ],
			'RON'             => [ '25.000 RON' ],
			'USD'             => [ 'in USD' ],
			'GBP'             => [ 'GBP pricing' ],
			'lei'             => [ '1000 lei' ],
			'Lei capitalised' => [ 'Lei' ],
			'12,500'          => [ 'roughly 12,500 in total' ],
			'12.500'          => [ 'roughly 12.500 in total' ],
			'1,200,000'       => [ '1,200,000' ],
			'2.000.000'       => [ 'budget 2.000.000' ],
			'12k'             => [ 'about 12k' ],
			'12K'             => [ '12K' ],
			'12 k'            => [ '12 k' ],
			'3.5k'            => [ 'costs 3.5k' ],
			'3,5k'            => [ '3,5k' ],
			'0.5k'            => [ '0.5k' ],
		];
	}

	#[DataProvider( 'innocent_provider' )]
	public function test_contains_money_ignores_innocent_numbers( string $text ): void {
		$this->assertFalse( ResponseValidator::contains_money( $text ), $text );
	}

	/**
	 * Strings that must pass.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function innocent_provider(): array {
		return [
			'weeks'          => [ '3 weeks' ],
			'year'           => [ 'since 2024' ],
			'version'        => [ 'version 2.0' ],
			'screens'        => [ '10 screens' ],
			'hours'          => [ 'about 538 team-hours over 18 weeks' ],
			'hyphen week'    => [ 'A 12-week plan' ],
			'decimal'        => [ '1.5 sprints' ],
			'two-digit sep'  => [ 'chapter 1,5' ],
			'kilometres'     => [ '5km away' ],
			'kilograms'      => [ '1.5 kg' ],
			'kubernetes'     => [ 'deploy to Kubernetes' ],
			'currency words' => [ 'euro zone customers, leisure market' ],
			'empty'          => [ '' ],
		];
	}

	#[DataProvider( 'money_location_provider' )]
	public function test_money_anywhere_in_the_payload_is_rejected( array $overrides ): void {
		$result = self::validate( self::payload( $overrides ) );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( [ 'money_detected' ], $result['errors'] );
	}

	/**
	 * Money placed in each text field.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function money_location_provider(): array {
		return [
			'headline'    => [ [ 'headline' => 'A €25,000 plan' ] ],
			'summary'     => [ [ 'summary' => 'Expect about 25k in total.' ] ],
			'phase name'  => [ [ 'phases' => [ self::phase( 'Build (12,500)', 2 ), self::phase( 'B', 6 ), self::phase( 'C', 2 ) ] ] ],
			'phase desc'  => [
				[
					'phases' => [
						self::phase( 'A', 2 ),
						array_merge( self::phase( 'B', 6 ), [ 'description' => 'Costs 500 EUR.' ] ),
						self::phase( 'C', 2 ),
					],
				],
			],
			'phase role'  => [ [ 'phases' => [ self::phase( 'A', 2, [ '$ Designer' ] ), self::phase( 'B', 6 ), self::phase( 'C', 2 ) ] ] ],
			'assumptions' => [ [ 'assumptions' => [ 'Budget is 40k.' ] ] ],
			'risks'       => [ [ 'risks' => [ 'Prices in lei may change.' ] ] ],
		];
	}

	/* ---------- roles ---------- */

	public function test_roles_capped_at_8_and_non_strings_dropped(): void {
		$ten    = array_map( static fn( int $i ): string => "Role {$i}", range( 1, 10 ) );
		$result = self::validate( self::payload( [ 'phases' => [ self::phase( 'A', 2, $ten ), self::phase( 'B', 6, [ 1, 'QA', null, [ 'x' ], 'PM' ] ), self::phase( 'C', 2 ) ] ] ) );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( array_slice( $ten, 0, 8 ), $result['data']['phases'][0]['roles'] );
		$this->assertSame( [ 'QA', 'PM' ], $result['data']['phases'][1]['roles'] );
		$this->assertSame( [], $result['data']['phases'][2]['roles'] );
	}

	public function test_missing_roles_key_yields_empty_roles(): void {
		$phase = self::phase( 'A', 2 );
		unset( $phase['roles'] );
		$result = self::validate( self::payload( [ 'phases' => [ $phase, self::phase( 'B', 6 ), self::phase( 'C', 2 ) ] ] ) );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( [], $result['data']['phases'][0]['roles'] );
	}

	/* ---------- round-trip ---------- */

	public function test_valid_payload_round_trips_to_the_exact_cleaned_shape(): void {
		$raw    = "```json\n" . json_encode(
			[
				'headline'    => 'A clear   plan',
				'summary'     => "Two sentences.\nOn two lines.",
				'price'       => 'extra keys are dropped',
				'phases'      => [
					[
						'name'        => 'Discovery',
						'weeks'       => '2',
						'description' => 'Goals.',
						'roles'       => [ 'Project manager' ],
						'cost'        => 'dropped too',
					],
					[
						'name'        => 'Build',
						'weeks'       => 6.04,
						'description' => 'Code.',
					],
					[
						'name'        => 'Launch',
						'weeks'       => 2,
						'description' => 'Go-live.',
						'roles'       => [],
					],
				],
				'assumptions' => [ 'Content ready.', '' ],
				'risks'       => [ 'Scope.' ],
			]
		) . "\n```";
		$result = ResponseValidator::validate( $raw, self::WEEKS );
		$this->assertSame(
			[
				'ok'       => true,
				'data'     => [
					'headline'    => 'A clear plan',
					'summary'     => 'Two sentences. On two lines.',
					'phases'      => [
						[
							'name'        => 'Discovery',
							'weeks'       => 2.0,
							'description' => 'Goals.',
							'roles'       => [ 'Project manager' ],
						],
						[
							'name'        => 'Build',
							'weeks'       => 6.0,
							'description' => 'Code.',
							'roles'       => [],
						],
						[
							'name'        => 'Launch',
							'weeks'       => 2.0,
							'description' => 'Go-live.',
							'roles'       => [],
						],
					],
					'assumptions' => [ 'Content ready.' ],
					'risks'       => [ 'Scope.' ],
				],
				'errors'   => [],
				'warnings' => [],
			],
			$result
		);
	}

	public function test_failure_shape_has_empty_data_and_keeps_warnings(): void {
		$result = self::validate(
			self::payload(
				[
					'headline' => '<b>' . str_repeat( 'h', 91 ) . '</b>',
				]
			)
		);
		$this->assertSame( false, $result['ok'] );
		$this->assertSame( [], $result['data'] );
		$this->assertSame( [ 'headline_length' ], $result['errors'] );
		$this->assertSame( [ 'html_stripped' ], $result['warnings'] );
	}

	public function test_multiple_content_errors_are_all_reported(): void {
		$result = self::validate(
			self::payload(
				[
					'headline'    => '',
					'summary'     => str_repeat( 's', 601 ),
					'phases'      => [ self::phase( 'A', -2 ) ],
					'assumptions' => array_fill( 0, 5, 'a' ),
					'risks'       => [ str_repeat( 'r', 201 ), 'b', 'c', 'd' ],
				]
			)
		);
		$this->assertSame(
			[ 'headline_length', 'summary_length', 'phase:0:negative_weeks', 'assumptions_count', 'risks_count', 'list_item_length', 'weeks_mismatch:-2!=10' ],
			$result['errors']
		);
	}
}
