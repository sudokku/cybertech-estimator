<?php
/**
 * FallbackNarrative tests. Key property: the fallback must satisfy the same
 * contract as the model (ResponseValidator) for every line and duration.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Tests\Unit;

use Cybertech\Estimator\Ai\FallbackNarrative;
use Cybertech\Estimator\Ai\PromptBuilder;
use Cybertech\Estimator\Ai\ResponseValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Fallback narrative tests.
 */
final class FallbackNarrativeTest extends TestCase {

	private const LABELS = [
		'web'    => 'Web solutions',
		'mobile' => 'Mobile application',
		'design' => 'UI/UX Design',
		'ai'     => 'AI Integration & Automation',
	];

	/**
	 * Phases per line in the fallback plan.
	 */
	private const PLAN_SIZE = [
		'web'    => 4,
		'mobile' => 4,
		'design' => 3,
		'ai'     => 3,
	];

	private const TEAM = [ 'Project manager', 'Senior software engineer', 'Senior backend developer', 'DevOps', 'QA', 'Junior frontend developer', 'Designer' ];

	/**
	 * Facts for a line.
	 *
	 * @param string               $line  Service line.
	 * @param int                  $weeks Weeks.
	 * @param array<string, mixed> $raw   Raw answers.
	 * @return array<string, mixed>
	 */
	private static function facts( string $line, int $weeks, array $raw = [] ): array {
		return [
			'service_line'  => $line,
			'service_label' => self::LABELS[ $line ] ?? 'Custom',
			'weeks'         => $weeks,
			'hours'         => $weeks * 30,
			'team'          => array_map(
				static fn( string $l ): array => [
					'label' => $l,
					'hours' => 10,
				],
				self::TEAM
			),
			'answers'       => [],
			'answers_raw'   => array_merge( [ 'service_line' => $line ], $raw ),
			'notes'         => '',
			'locale'        => 'en_US',
		];
	}

	#[DataProvider( 'line_weeks_provider' )]
	public function test_contract_holds_for_every_line_and_duration( string $line, int $weeks ): void {
		$narrative = FallbackNarrative::build( self::facts( $line, $weeks ) );
		$phases    = $narrative['phases'];

		// Weeks sum EXACTLY.
		$this->assertSame( $weeks, array_sum( array_column( $phases, 'weeks' ) ), "{$line}/{$weeks}: phase weeks must sum to the total" );
		foreach ( $phases as $i => $phase ) {
			$this->assertIsInt( $phase['weeks'], "{$line}/{$weeks}: phase {$i} weeks is an integer" );
			$this->assertGreaterThanOrEqual( 1, $phase['weeks'], "{$line}/{$weeks}: phase {$i} has at least one week" );
			$this->assertSame( [ 'name', 'weeks', 'description', 'roles' ], array_keys( $phase ) );
			$this->assertLessThanOrEqual( 4, count( $phase['roles'] ) );
			$this->assertSame( array_slice( self::TEAM, 0, 4 ), $phase['roles'] );
		}
		if ( $weeks >= self::PLAN_SIZE[ $line ] ) {
			$this->assertCount( self::PLAN_SIZE[ $line ], $phases, "{$line}/{$weeks}: every phase survives when there are enough weeks" );
		} else {
			$this->assertCount( $weeks, $phases, "{$line}/{$weeks}: one week per surviving phase when weeks are scarce" );
		}
		$this->assertLessThanOrEqual( PromptBuilder::PHASES_MAX, count( $phases ) );
		$this->assertLessThanOrEqual( PromptBuilder::ASSUMPTIONS_MAX, count( $narrative['assumptions'] ) );
		$this->assertLessThanOrEqual( PromptBuilder::RISKS_MAX, count( $narrative['risks'] ) );
		$this->assertNotEmpty( $narrative['assumptions'] );
		$this->assertNotEmpty( $narrative['risks'] );

		// Same contract as the AI output.
		$json   = (string) json_encode( $narrative );
		$result = ResponseValidator::validate( $json, $weeks );
		$this->assertTrue( $result['ok'], "{$line}/{$weeks}: " . implode( ', ', $result['errors'] ) );
		$this->assertSame( [], $result['warnings'], "{$line}/{$weeks}: fallback must be clean text" );
		$this->assertFalse( ResponseValidator::contains_money( $json ) );
		$this->assertStringContainsString( "{$weeks}-week", $narrative['headline'] );
		$this->assertStringContainsString( 1 === $weeks ? "about 1 week of work for a team of 7 roles" : "about {$weeks} weeks of work for a team of 7 roles", $narrative['summary'] );
		$this->assertStringContainsString( self::LABELS[ $line ], $narrative['summary'] );
	}

	/**
	 * Every line × weeks in [0, 1, 2, 3, 4, 5, 7, 10, 18, 52].
	 *
	 * @return array<string, array{0: string, 1: int}>
	 */
	public static function line_weeks_provider(): array {
		$cases = [];
		foreach ( array_keys( self::LABELS ) as $line ) {
			foreach ( [ 1, 2, 3, 4, 5, 7, 10, 18, 52 ] as $weeks ) {
				$cases[ "{$line} {$weeks}w" ] = [ $line, $weeks ];
			}
		}
		return $cases;
	}

	#[DataProvider( 'split_provider' )]
	public function test_hand_computed_week_splits( string $line, int $weeks, array $expected ): void {
		$phases = FallbackNarrative::build( self::facts( $line, $weeks ) )['phases'];
		$this->assertSame( $expected, array_combine( array_column( $phases, 'name' ), array_column( $phases, 'weeks' ) ) );
	}

	/**
	 * Largest-remainder splits, by hand.
	 *
	 * @return array<string, array{0: string, 1: int, 2: array<string, int>}>
	 */
	public static function split_provider(): array {
		return [
			// web 0.2/0.5/0.2/0.1 × 18 = 3.6/9/3.6/1.8 → floors 3/9/3/1 (16), remainders .6/0/.6/.8 → +1 to Handover, then Discovery.
			'web 18'   => [
				'web',
				18,
				[
					'Discovery & design' => 4,
					'Build'              => 9,
					'QA & launch'        => 3,
					'Handover'           => 2,
				],
			],
			// web × 5 = 1/2.5/1/.5 → floors 1/2/1/0, remainders 0/.5/0/.5 → +1 to Build (first) → 1/3/1/0 → zero fixed from the max → 1/2/1/1.
			'web 5'    => [
				'web',
				5,
				[
					'Discovery & design' => 1,
					'Build'              => 2,
					'QA & launch'        => 1,
					'Handover'           => 1,
				],
			],
			// web × 10 = 2/5/2/1 exactly.
			'web 10'   => [
				'web',
				10,
				[
					'Discovery & design' => 2,
					'Build'              => 5,
					'QA & launch'        => 2,
					'Handover'           => 1,
				],
			],
			// web × 1 → only the largest remainder (Build) gets the week; the rest are dropped.
			'web 1'    => [ 'web', 1, [ 'Build' => 1 ] ],
			// web × 2 → .4/1/.4/.2 → floors 0/1/0/0 → +1 to Discovery (first of the .4 tie).
			'web 2'    => [
				'web',
				2,
				[
					'Discovery & design' => 1,
					'Build'              => 1,
				],
			],
			// mobile × 7 = 1.4/3.5/1.4/.7 → floors 1/3/1/0 (5), remainders .4/.5/.4/.7 → +1 Launch, +1 Build.
			'mobile 7' => [
				'mobile',
				7,
				[
					'Discovery & UX'         => 1,
					'Build'                  => 4,
					'QA & store submission'  => 1,
					'Launch & stabilisation' => 1,
				],
			],
			// design 0.3/0.5/0.2 × 3 = .9/1.5/.6 → floors 0/1/0 → +1 Research, +1 Validation.
			'design 3' => [
				'design',
				3,
				[
					'Research & structure' => 1,
					'Design'               => 1,
					'Validation & handoff' => 1,
				],
			],
			// ai 0.25/0.5/0.25 × 2 = .5/1/.5 → floors 0/1/0 → +1 Discovery (first of the tie); Pilot dropped.
			'ai 2'     => [
				'ai',
				2,
				[
					'Discovery & data' => 1,
					'Build workflows'  => 1,
				],
			],
			// ai × 52 = 13/26/13 exactly.
			'ai 52'    => [
				'ai',
				52,
				[
					'Discovery & data' => 13,
					'Build workflows'  => 26,
					'Pilot & rollout'  => 13,
				],
			],
		];
	}

	public function test_zero_weeks_is_lifted_to_one_and_still_validates(): void {
		$narrative = FallbackNarrative::build( self::facts( 'web', 0 ) );
		$this->assertSame( 1, array_sum( array_column( $narrative['phases'], 'weeks' ) ) );
		$this->assertStringContainsString( '1-week', $narrative['headline'] );
		$this->assertTrue( ResponseValidator::validate( (string) json_encode( $narrative ), 0 )['ok'] );
	}

	public function test_unknown_service_line_uses_the_generic_headline_and_web_plan(): void {
		$narrative = FallbackNarrative::build( self::facts( 'print', 10 ) );
		$this->assertSame( 'A 10-week plan for your project', $narrative['headline'] );
		$this->assertSame( [ 'Discovery & design', 'Build', 'QA & launch', 'Handover' ], array_column( $narrative['phases'], 'name' ) );
		$this->assertTrue( ResponseValidator::validate( (string) json_encode( $narrative ), 10 )['ok'] );
	}

	#[DataProvider( 'headline_provider' )]
	public function test_headline_per_line( string $line, string $headline ): void {
		$this->assertSame( $headline, FallbackNarrative::build( self::facts( $line, 12 ) )['headline'] );
	}

	/**
	 * Line → headline at 12 weeks.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function headline_provider(): array {
		return [
			'web'    => [ 'web', 'A 12-week course to a site your team can run itself' ],
			'mobile' => [ 'mobile', 'A 12-week route from first screen to the app stores' ],
			'design' => [ 'design', 'A 12-week design engagement, from research to handoff' ],
			'ai'     => [ 'ai', 'A 12-week path to automations that work while you sleep' ],
		];
	}

	public function test_missing_team_yields_empty_roles(): void {
		$facts = self::facts( 'web', 10 );
		unset( $facts['team'] );
		$narrative = FallbackNarrative::build( $facts );
		foreach ( $narrative['phases'] as $phase ) {
			$this->assertSame( [], $phase['roles'] );
		}
		$this->assertTrue( ResponseValidator::validate( (string) json_encode( $narrative ), 10 )['ok'] );
	}

	/* ---------- assumptions ---------- */

	public function test_assumptions_respond_to_hosting(): void {
		$base = 'You provide content, brand assets and timely feedback at each review.';
		$this->assertSame( [ $base ], FallbackNarrative::build( self::facts( 'web', 10, [ 'hosting' => 'undecided' ] ) )['assumptions'] );
		$this->assertSame( [ $base ], FallbackNarrative::build( self::facts( 'web', 10 ) )['assumptions'] );
		$this->assertSame(
			[ $base, 'Hosting and deployment infrastructure are provided by your team.' ],
			FallbackNarrative::build( self::facts( 'web', 10, [ 'hosting' => 'client' ] ) )['assumptions']
		);
		$this->assertSame(
			[ $base, 'We set up hosting, deployment pipeline and monitoring as part of the project.' ],
			FallbackNarrative::build( self::facts( 'web', 10, [ 'hosting' => 'cybertech' ] ) )['assumptions']
		);
	}

	public function test_assumptions_respond_to_migration_backend_and_ai(): void {
		$migration = 'Existing content is exportable in a structured form for migration.';
		$this->assertContains( $migration, FallbackNarrative::build( self::facts( 'web', 10, [ 'web_migration' => 'yes' ] ) )['assumptions'] );
		$this->assertNotContains( $migration, FallbackNarrative::build( self::facts( 'web', 10, [ 'web_migration' => 'no' ] ) )['assumptions'] );
		$this->assertNotContains( $migration, FallbackNarrative::build( self::facts( 'mobile', 10, [ 'web_migration' => 'yes' ] ) )['assumptions'], 'a web answer must not leak into a mobile narrative' );

		$api = 'Your existing API is documented and reachable from a test environment.';
		$this->assertContains( $api, FallbackNarrative::build( self::facts( 'mobile', 10, [ 'mobile_backend' => 'existing' ] ) )['assumptions'] );
		$this->assertNotContains( $api, FallbackNarrative::build( self::facts( 'mobile', 10, [ 'mobile_backend' => 'needed' ] ) )['assumptions'] );

		$access = 'API access to the systems to be integrated is available before the build starts.';
		$this->assertContains( $access, FallbackNarrative::build( self::facts( 'ai', 10 ) )['assumptions'] );
		$this->assertNotContains( $access, FallbackNarrative::build( self::facts( 'web', 10 ) )['assumptions'] );

		// Fullest case stays within the cap.
		$full = FallbackNarrative::build(
			self::facts(
				'web',
				10,
				[
					'hosting'       => 'cybertech',
					'web_migration' => 'yes',
				]
			)
		)['assumptions'];
		$this->assertCount( 3, $full );
	}

	/* ---------- risks ---------- */

	public function test_risks_default_when_nothing_specific_applies(): void {
		$default = 'Scope details discovered during the first phase can shift the plan; we review it together at each milestone.';
		$this->assertSame( [ $default ], FallbackNarrative::build( self::facts( 'web', 10 ) )['risks'] );
		$this->assertSame( [ $default ], FallbackNarrative::build( self::facts( 'design', 10, [ 'design_brand' => 'no' ] ) )['risks'] );
		$this->assertSame( [ $default ], FallbackNarrative::build( self::facts( 'web', 10, [ 'urgency' => 'flexible' ] ) )['risks'] );
	}

	public function test_risks_respond_to_urgency_integrations_line_and_brand(): void {
		$compressed = 'A compressed timeline reduces room for scope changes; decisions need to be quick.';
		$apis       = 'Third-party integrations depend on external APIs and their documentation quality.';
		$stores     = 'App store review times are outside our control and can add days to launch.';
		$data       = 'Automation quality depends on the consistency of the source data.';
		$brand      = 'Brand identity work benefits from early stakeholder alignment to avoid late rework.';

		$this->assertSame( [ $compressed ], FallbackNarrative::build( self::facts( 'web', 10, [ 'urgency' => 'urgent' ] ) )['risks'] );
		$this->assertSame( [ $compressed ], FallbackNarrative::build( self::facts( 'web', 10, [ 'urgency' => 'asap' ] ) )['risks'] );
		$this->assertSame( [ $apis ], FallbackNarrative::build( self::facts( 'web', 10, [ 'web_integrations' => 2 ] ) )['risks'] );
		$this->assertSame( [ $apis ], FallbackNarrative::build( self::facts( 'web', 10, [ 'web_integrations' => '3' ] ) )['risks'] );
		$this->assertNotContains( $apis, FallbackNarrative::build( self::facts( 'web', 10, [ 'web_integrations' => 1 ] ) )['risks'] );
		$this->assertSame( [ $stores ], FallbackNarrative::build( self::facts( 'mobile', 10 ) )['risks'] );
		$this->assertSame( [ $data ], FallbackNarrative::build( self::facts( 'ai', 10 ) )['risks'] );
		$this->assertSame( [ $brand ], FallbackNarrative::build( self::facts( 'design', 10, [ 'design_brand' => 'yes' ] ) )['risks'] );
		// Stacked: urgency first, then the line-specific one; the default is not appended.
		$this->assertSame( [ $compressed, $stores ], FallbackNarrative::build( self::facts( 'mobile', 10, [ 'urgency' => 'asap' ] ) )['risks'] );
		$this->assertSame(
			[ $compressed, $apis ],
			FallbackNarrative::build(
				self::facts(
					'web',
					10,
					[
						'urgency'          => 'urgent',
						'web_integrations' => 5,
					]
				)
			)['risks']
		);
	}
}
