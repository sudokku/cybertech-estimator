<?php
/**
 * InputSanitizer tests: schema-driven rejection, clamping, stripping and
 * required-field enforcement per mode.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Tests\Unit;

use Cybertech\Estimator\Security\InputSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Input sanitizer tests.
 */
final class InputSanitizerTest extends TestCase {

	private const ERR_UNKNOWN  = 'Unknown field.';
	private const ERR_REQUIRED = 'This field is required.';
	private const ERR_SINGLE   = 'Please choose one of the listed options.';
	private const ERR_MULTI    = 'Please choose from the listed options.';
	private const ERR_NUMBER   = 'Please enter a number.';
	private const ERR_EMAIL    = 'Please enter a valid email address.';
	private const ERR_CONSENT  = 'Please confirm you agree to be contacted about this estimate.';

	/**
	 * A complete, valid web submission.
	 */
	private const FULL_WEB = [
		'service_line'     => 'web',
		'web_platform'     => 'wordpress',
		'web_ecommerce'    => 'none',
		'web_templates'    => 5,
		'web_multilingual' => 'no',
		'web_integrations' => 0,
		'web_migration'    => 'no',
		'urgency'          => 'normal',
		'budget'           => 'undisclosed',
		'maintenance'      => 'no',
		'hosting'          => 'client',
		'notes'            => 'Launch before spring.',
		'name'             => 'Ana Pop',
		'email'            => 'ana@example.test',
		'company'          => 'Acme',
		'phone'            => '+40 700 000 000',
		'consent'          => 'on',
	];

	/**
	 * Validate.
	 *
	 * @param array<string, mixed> $raw  Raw.
	 * @param string               $mode Mode.
	 * @return array{answers: array<string, mixed>, contact: array<string, mixed>, errors: array<string, string>}
	 */
	private static function validate( array $raw, string $mode = InputSanitizer::MODE_PREVIEW ): array {
		return ( new InputSanitizer() )->validate( $raw, $mode );
	}

	/* ---------- ids & options ---------- */

	public function test_unknown_ids_are_errors_and_never_pass_through(): void {
		$result = self::validate(
			[
				'service_line' => 'web',
				'price'        => 1,
				'__proto__'    => 'x',
			]
		);
		$this->assertSame( self::ERR_UNKNOWN, $result['errors']['price'] );
		$this->assertSame( self::ERR_UNKNOWN, $result['errors']['__proto__'] );
		$this->assertSame( [ 'service_line' => 'web' ], $result['answers'] );
		$this->assertSame( [], $result['contact'] );
	}

	#[DataProvider( 'bad_single_provider' )]
	public function test_single_with_unknown_option_is_rejected_not_coerced( mixed $value ): void {
		$result = self::validate(
			[
				'service_line' => 'web',
				'web_platform' => $value,
			]
		);
		$this->assertSame( self::ERR_SINGLE, $result['errors']['web_platform'] );
		$this->assertArrayNotHasKey( 'web_platform', $result['answers'], 'must not fall back to the default option' );
	}

	/**
	 * Values a single-select must refuse.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function bad_single_provider(): array {
		return [
			'unknown option' => [ 'shopify' ],
			'wrong case'     => [ 'WordPress' ],
			'array'          => [ [ 'wordpress' ] ],
			'int'            => [ 0 ],
			'null'           => [ null ],
			'empty string'   => [ '' ],
			'padded'         => [ ' wordpress' ],
		];
	}

	public function test_unknown_service_line_is_rejected_and_required(): void {
		$result = self::validate( [ 'service_line' => 'print' ] );
		$this->assertSame( [ 'service_line' => self::ERR_SINGLE ], $result['errors'] );
		$this->assertSame( [], $result['answers'] );
	}

	public function test_multi_select(): void {
		$ok = self::validate(
			[
				'service_line'        => 'design',
				'design_deliverables' => [ 'hifi', 'research', 'hifi' ],
			]
		);
		$this->assertSame( [], $ok['errors'] );
		$this->assertSame( [ 'hifi', 'research' ], $ok['answers']['design_deliverables'], 'deduplicated, order kept, reindexed' );

		$one_bad = self::validate(
			[
				'service_line'        => 'design',
				'design_deliverables' => [ 'hifi', 'video' ],
			]
		);
		$this->assertSame( self::ERR_MULTI, $one_bad['errors']['design_deliverables'] );
		$this->assertArrayNotHasKey( 'design_deliverables', $one_bad['answers'], 'one bad value rejects the whole answer' );

		$scalar = self::validate(
			[
				'service_line'        => 'design',
				'design_deliverables' => 'hifi',
			]
		);
		$this->assertSame( self::ERR_MULTI, $scalar['errors']['design_deliverables'] );

		$non_string = self::validate(
			[
				'service_line'        => 'design',
				'design_deliverables' => [ 0 ],
			]
		);
		$this->assertSame( self::ERR_MULTI, $non_string['errors']['design_deliverables'] );
	}

	public function test_empty_multi_on_submit_is_required(): void {
		$result = self::validate(
			[
				'service_line'        => 'design',
				'design_deliverables' => [],
			],
			InputSanitizer::MODE_SUBMIT
		);
		$this->assertSame( 'Please choose at least one option.', $result['errors']['design_deliverables'] );
	}

	/**
	 * Preview allows partial answers: an empty multi-select (nothing ticked
	 * yet) must not be an error in preview mode, only on submit.
	 */
	public function test_empty_multi_in_preview_is_not_an_error(): void {
		$result = self::validate(
			[
				'service_line'        => 'design',
				'design_deliverables' => [],
			]
		);
		$this->assertSame( [], $result['errors'] );
		$this->assertArrayNotHasKey( 'design_deliverables', $result['answers'] );
	}

	/* ---------- numbers ---------- */

	#[DataProvider( 'number_provider' )]
	public function test_numbers_are_clamped_and_rounded( mixed $value, int $expected ): void {
		// web_templates: min 1, max 40.
		$result = self::validate(
			[
				'service_line'  => 'web',
				'web_templates' => $value,
			]
		);
		$this->assertSame( [], $result['errors'] );
		$this->assertSame( $expected, $result['answers']['web_templates'] );
	}

	/**
	 * Raw → clamped integer for a 1–40 field.
	 *
	 * @return array<string, array{0: mixed, 1: int}>
	 */
	public static function number_provider(): array {
		return [
			'in range'        => [ 7, 7 ],
			'numeric string'  => [ '7', 7 ],
			'float rounds'    => [ 7.6, 8 ],
			'half rounds up'  => [ '2.5', 3 ],
			'above max'       => [ 100, 40 ],
			'at max'          => [ 40, 40 ],
			'below min'       => [ 0, 1 ],
			'negative'        => [ -5, 1 ],
			'at min'          => [ 1, 1 ],
			'exponent string' => [ '1e3', 40 ],
		];
	}

	/**
	 * Out-of-range input must clamp to the declared maximum. The int cast in
	 * `(int) round( (float) $value )` happens BEFORE the clamp, so a float
	 * beyond PHP_INT_MAX wraps and the answer collapses to the minimum (1),
	 * silently under-pricing a visitor who typed too many digits.
	 */
	#[DataProvider( 'huge_number_provider' )]
	public function test_huge_numbers_clamp_to_the_maximum( mixed $value ): void {
		$result = self::validate(
			[
				'service_line'  => 'web',
				'web_templates' => $value,
			]
		);
		$this->assertSame( [], $result['errors'] );
		$this->assertSame( 40, $result['answers']['web_templates'] );
	}

	/**
	 * Numbers beyond the integer range.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function huge_number_provider(): array {
		return [
			'PHP_INT_MAX' => [ PHP_INT_MAX ],
			'20 digits'   => [ '99999999999999999999' ],
			'1e30'        => [ '1e30' ],
			'INF-ish'     => [ 1.0e308 ],
		];
	}

	public function test_zero_minimum_fields_accept_zero(): void {
		$result = self::validate(
			[
				'service_line'     => 'web',
				'web_integrations' => 0,
			]
		);
		$this->assertSame( 0, $result['answers']['web_integrations'] );
		$rounds = self::validate(
			[
				'service_line'          => 'design',
				'design_testing_rounds' => 9,
			]
		);
		$this->assertSame( 5, $rounds['answers']['design_testing_rounds'] );
	}

	#[DataProvider( 'non_numeric_provider' )]
	public function test_non_numeric_numbers_are_errors( mixed $value ): void {
		$result = self::validate(
			[
				'service_line'  => 'web',
				'web_templates' => $value,
			]
		);
		$this->assertSame( self::ERR_NUMBER, $result['errors']['web_templates'] );
		$this->assertArrayNotHasKey( 'web_templates', $result['answers'] );
	}

	/**
	 * Values a number field must refuse.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function non_numeric_provider(): array {
		return [
			'word'    => [ 'five' ],
			'empty'   => [ '' ],
			'array'   => [ [ 5 ] ],
			'null'    => [ null ],
			'bool'    => [ true ],
			'5 pages' => [ '5 pages' ],
		];
	}

	/* ---------- text ---------- */

	public function test_text_is_stripped_normalised_and_capped(): void {
		$raw    = "  <script>alert(1)</script>Hello\t\t<b>world</b>,   we need\n\n\n\na   shop. <a href='x'>Link</a> ";
		$result = self::validate(
			[
				'service_line' => 'web',
				'notes'        => $raw,
			]
		);
		$this->assertSame( [], $result['errors'] );
		$this->assertSame( "Hello world, we need\n\na shop. Link", $result['answers']['notes'] );
	}

	public function test_text_cap_counts_characters_not_bytes(): void {
		$ascii = self::validate(
			[
				'service_line' => 'web',
				'notes'        => str_repeat( 'a', 1200 ),
			]
		);
		$this->assertSame( 1000, mb_strlen( $ascii['answers']['notes'] ) );
		$multi = self::validate(
			[
				'service_line' => 'web',
				'notes'        => str_repeat( 'ă', 1200 ),
			]
		);
		$this->assertSame( 1000, mb_strlen( $multi['answers']['notes'] ) );
		$this->assertSame( str_repeat( 'ă', 1000 ), $multi['answers']['notes'] );
		// Contact text fields have their own caps.
		$name = self::validate( [ 'name' => str_repeat( 'n', 200 ) ] );
		$this->assertSame( 120, mb_strlen( $name['contact']['name'] ) );
		$phone = self::validate( [ 'phone' => str_repeat( '1', 60 ) ] );
		$this->assertSame( 40, mb_strlen( $phone['contact']['phone'] ) );
	}

	public function test_empty_text_after_stripping_is_dropped_without_error(): void {
		foreach ( [ '', '   ', "\n\t", '<b></b>', '<script>x</script>' ] as $value ) {
			$result = self::validate(
				[
					'service_line' => 'web',
					'notes'        => $value,
				]
			);
			$this->assertSame( [], $result['errors'], (string) json_encode( $value ) );
			$this->assertArrayNotHasKey( 'notes', $result['answers'] );
		}
	}

	public function test_non_scalar_text_is_an_error(): void {
		$result = self::validate(
			[
				'service_line' => 'web',
				'notes'        => [ 'a' ],
			]
		);
		$this->assertSame( 'Invalid text.', $result['errors']['notes'] );
		$scalar = self::validate(
			[
				'service_line' => 'web',
				'notes'        => 42,
			]
		);
		$this->assertSame( '42', $scalar['answers']['notes'] );
	}

	/* ---------- email ---------- */

	public function test_email_validation(): void {
		$this->assertSame( 'ana@example.test', self::validate( [ 'email' => 'ana@example.test' ] )['contact']['email'] );
		$this->assertSame( 'ana@example.test', self::validate( [ 'email' => '  ana@example.test  ' ] )['contact']['email'] );
		$this->assertSame( 'Ana.Pop+x@Example.test', self::validate( [ 'email' => 'Ana.Pop+x@Example.test' ] )['contact']['email'] );
		foreach ( [ 'not-an-email', 'ana@', '@example.test', 'ana@example', '', ' ', [ 'ana@example.test' ], null ] as $bad ) {
			$result = self::validate( [ 'email' => $bad ] );
			$this->assertSame( self::ERR_EMAIL, $result['errors']['email'] ?? '', (string) json_encode( $bad ) );
			$this->assertArrayNotHasKey( 'email', $result['contact'] );
		}
	}

	/* ---------- checkbox ---------- */

	#[DataProvider( 'checkbox_provider' )]
	public function test_checkbox_truthiness( mixed $value, bool $expected ): void {
		$result = self::validate(
			[
				'service_line' => 'web',
				'consent'      => $value,
			]
		);
		$this->assertSame( [], $result['errors'] );
		$this->assertSame( $expected, $result['contact']['consent'] );
	}

	/**
	 * Raw checkbox value → bool.
	 *
	 * @return array<string, array{0: mixed, 1: bool}>
	 */
	public static function checkbox_provider(): array {
		return [
			'true'   => [ true, true ],
			'1'      => [ 1, true ],
			'"1"'    => [ '1', true ],
			'on'     => [ 'on', true ],
			'"true"' => [ 'true', true ],
			'yes'    => [ 'yes', true ],
			'false'  => [ false, false ],
			'0'      => [ 0, false ],
			'"0"'    => [ '0', false ],
			'off'    => [ 'off', false ],
			'no'     => [ 'no', false ],
			'empty'  => [ '', false ],
			'null'   => [ null, false ],
			'YES'    => [ 'YES', false ],
			'array'  => [ [ 'on' ], false ],
			'"1.0"'  => [ '1.0', false ],
			'1.0'    => [ 1.0, false ],
		];
	}

	/* ---------- visibility ---------- */

	public function test_hidden_branch_answers_are_dropped_along_with_their_errors(): void {
		$result = self::validate(
			[
				'service_line'     => 'web',
				'web_platform'     => 'drupal',
				'mobile_offline'   => 'maybe',
				'mobile_platforms' => 'both',
				'mobile_auth'      => 'yes',
				'design_screens'   => 'lots',
				'ai_workflows'     => 5,
			]
		);
		$this->assertSame( [], $result['errors'], 'errors on hidden questions are irrelevant' );
		$this->assertSame(
			[
				'service_line' => 'web',
				'web_platform' => 'drupal',
			],
			$result['answers']
		);
	}

	public function test_branch_answers_are_dropped_when_service_line_is_missing_or_invalid(): void {
		$missing = self::validate( [ 'web_platform' => 'drupal' ] );
		$this->assertSame( [ 'service_line' => self::ERR_REQUIRED ], $missing['errors'] );
		$this->assertSame( [], $missing['answers'] );

		$invalid = self::validate(
			[
				'service_line' => 'nope',
				'web_platform' => 'drupal',
			]
		);
		$this->assertSame( [ 'service_line' => self::ERR_SINGLE ], $invalid['errors'] );
		$this->assertSame( [], $invalid['answers'] );
	}

	/* ---------- modes ---------- */

	public function test_preview_only_requires_service_line(): void {
		$this->assertSame( [ 'service_line' => self::ERR_REQUIRED ], self::validate( [] )['errors'] );
		$this->assertSame( [], self::validate( [ 'service_line' => 'web' ] )['errors'] );
		$this->assertSame( [], self::validate( [ 'service_line' => 'mobile' ] )['errors'] );
		// Contact fields are not required in preview.
		$this->assertSame( [], self::validate( [ 'service_line' => 'ai' ] )['contact'] );
	}

	public function test_submit_requires_every_visible_required_question_and_consent(): void {
		$result   = self::validate( [ 'service_line' => 'web' ], InputSanitizer::MODE_SUBMIT );
		$expected = [ 'web_platform', 'web_ecommerce', 'web_templates', 'web_multilingual', 'web_integrations', 'web_migration', 'urgency', 'maintenance', 'hosting', 'name', 'email', 'consent' ];
		$this->assertSame( $expected, array_keys( $result['errors'] ), 'schema order; optional budget/notes/company/phone absent; hidden branches absent' );
		foreach ( $expected as $id ) {
			$this->assertSame( 'consent' === $id ? self::ERR_CONSENT : self::ERR_REQUIRED, $result['errors'][ $id ], $id );
		}
	}

	public function test_submit_with_everything_valid(): void {
		$result = self::validate( self::FULL_WEB, InputSanitizer::MODE_SUBMIT );
		$this->assertSame( [], $result['errors'] );
		$this->assertSame(
			[
				'service_line'     => 'web',
				'web_platform'     => 'wordpress',
				'web_ecommerce'    => 'none',
				'web_templates'    => 5,
				'web_multilingual' => 'no',
				'web_integrations' => 0,
				'web_migration'    => 'no',
				'urgency'          => 'normal',
				'budget'           => 'undisclosed',
				'maintenance'      => 'no',
				'hosting'          => 'client',
				'notes'            => 'Launch before spring.',
			],
			$result['answers']
		);
		$this->assertSame(
			[
				'name'    => 'Ana Pop',
				'email'   => 'ana@example.test',
				'company' => 'Acme',
				'phone'   => '+40 700 000 000',
				'consent' => true,
			],
			$result['contact']
		);
	}

	public function test_submit_without_consent(): void {
		foreach ( [ 'off', '0', false, '' ] as $value ) {
			$result = self::validate( array_merge( self::FULL_WEB, [ 'consent' => $value ] ), InputSanitizer::MODE_SUBMIT );
			$this->assertSame( [ 'consent' => self::ERR_CONSENT ], $result['errors'], (string) json_encode( $value ) );
			$this->assertFalse( $result['contact']['consent'] );
		}
		$missing = self::validate( array_diff_key( self::FULL_WEB, [ 'consent' => 1 ] ), InputSanitizer::MODE_SUBMIT );
		$this->assertSame( [ 'consent' => self::ERR_CONSENT ], $missing['errors'] );
	}

	public function test_submit_keeps_a_value_error_over_the_required_error(): void {
		$result = self::validate( array_merge( self::FULL_WEB, [ 'web_templates' => 'many' ] ), InputSanitizer::MODE_SUBMIT );
		$this->assertSame( [ 'web_templates' => self::ERR_NUMBER ], $result['errors'] );
	}

	public function test_contact_fields_never_land_in_answers(): void {
		$result = self::validate( self::FULL_WEB );
		foreach ( [ 'name', 'email', 'company', 'phone', 'consent' ] as $id ) {
			$this->assertArrayNotHasKey( $id, $result['answers'] );
			$this->assertArrayHasKey( $id, $result['contact'] );
		}
		foreach ( array_keys( $result['answers'] ) as $id ) {
			$this->assertArrayNotHasKey( $id, $result['contact'] );
		}
		$this->assertSame( 'Ana Pop', $result['contact']['name'] );
	}

	public function test_contact_text_is_stripped_too(): void {
		$result = self::validate( [ 'name' => '<img src=x onerror=alert(1)>Ana' ] );
		$this->assertSame( 'Ana', $result['contact']['name'] );
	}
}
