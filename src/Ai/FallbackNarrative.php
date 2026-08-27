<?php
/**
 * Deterministic narrative built in PHP from the same facts the model gets.
 * It is complete on its own: the AI is a garnish on a dish already finished.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Ai;

/**
 * Fallback narrative.
 */
final class FallbackNarrative {

	/**
	 * Build a narrative matching the AI schema.
	 *
	 * @param array<string, mixed> $facts See PromptBuilder::build() for the shape; also uses `answers_raw` and `service_line`.
	 * @return array<string, mixed>
	 */
	public static function build( array $facts ): array {
		$line  = (string) ( $facts['service_line'] ?? 'web' );
		$weeks = max( 1, (int) $facts['weeks'] );
		$label = (string) $facts['service_label'];
		$raw   = (array) ( $facts['answers_raw'] ?? [] );
		$roles = array_map( static fn( array $m ): string => (string) $m['label'], (array) ( $facts['team'] ?? [] ) );

		return [
			'headline'    => self::headline( $line, $weeks ),
			'summary'     => sprintf(
				/* translators: 1: service line label, 2: "N weeks", 3: "N roles" */
				__( 'Based on your answers, this %1$s project is scoped at about %2$s of work for a team of %3$s. The plan below is an indication built from your selections; we refine it together before any commitment.', 'cybertech-estimator' ),
				$label,
				/* translators: %d: weeks */
				sprintf( _n( '%d week', '%d weeks', $weeks, 'cybertech-estimator' ), $weeks ),
				/* translators: %d: number of roles */
				sprintf( _n( '%d role', '%d roles', max( 1, count( $roles ) ), 'cybertech-estimator' ), max( 1, count( $roles ) ) )
			),
			'phases'      => self::phases( $line, $weeks, $roles ),
			'assumptions' => self::assumptions( $line, $raw ),
			'risks'       => self::risks( $line, $raw ),
		];
	}

	/**
	 * Headline per service line.
	 *
	 * @param string $line  Service line.
	 * @param int    $weeks Weeks.
	 */
	private static function headline( string $line, int $weeks ): string {
		switch ( $line ) {
			case 'web':
				/* translators: %d: weeks */
				return sprintf( __( 'A %d-week course to a site your team can run itself', 'cybertech-estimator' ), $weeks );
			case 'mobile':
				/* translators: %d: weeks */
				return sprintf( __( 'A %d-week route from first screen to the app stores', 'cybertech-estimator' ), $weeks );
			case 'design':
				/* translators: %d: weeks */
				return sprintf( __( 'A %d-week design engagement, from research to handoff', 'cybertech-estimator' ), $weeks );
			case 'ai':
				/* translators: %d: weeks */
				return sprintf( __( 'A %d-week path to automations that work while you sleep', 'cybertech-estimator' ), $weeks );
		}
		/* translators: %d: weeks */
		return sprintf( __( 'A %d-week plan for your project', 'cybertech-estimator' ), $weeks );
	}

	/**
	 * Phase plan with weeks that sum exactly to the computed total.
	 *
	 * @param string             $line  Service line.
	 * @param int                $weeks Total weeks.
	 * @param array<int, string> $roles Role labels.
	 * @return array<int, array<string, mixed>>
	 */
	private static function phases( string $line, int $weeks, array $roles ): array {
		$plans = [
			'web'    => [
				[ __( 'Discovery & design', 'cybertech-estimator' ), 0.2, __( 'We align on goals, structure and visual direction, then sign off the page templates.', 'cybertech-estimator' ) ],
				[ __( 'Build', 'cybertech-estimator' ), 0.5, __( 'Templates, content model, integrations and admin experience are implemented and reviewed weekly.', 'cybertech-estimator' ) ],
				[ __( 'QA & launch', 'cybertech-estimator' ), 0.2, __( 'Cross-device testing, performance and accessibility checks, migration and go-live.', 'cybertech-estimator' ) ],
				[ __( 'Handover', 'cybertech-estimator' ), 0.1, __( 'Training, documentation and a stabilisation window after launch.', 'cybertech-estimator' ) ],
			],
			'mobile' => [
				[ __( 'Discovery & UX', 'cybertech-estimator' ), 0.2, __( 'User flows, screen inventory and technical spikes on the risky parts.', 'cybertech-estimator' ) ],
				[ __( 'Build', 'cybertech-estimator' ), 0.5, __( 'Cross-platform implementation with a testable build shipped every sprint.', 'cybertech-estimator' ) ],
				[ __( 'QA & store submission', 'cybertech-estimator' ), 0.2, __( 'Device matrix testing, store assets, review submission and fixes.', 'cybertech-estimator' ) ],
				[ __( 'Launch & stabilisation', 'cybertech-estimator' ), 0.1, __( 'Monitoring, crash triage and the first post-launch release.', 'cybertech-estimator' ) ],
			],
			'design' => [
				[ __( 'Research & structure', 'cybertech-estimator' ), 0.3, __( 'Stakeholder input, user needs and the information architecture.', 'cybertech-estimator' ) ],
				[ __( 'Design', 'cybertech-estimator' ), 0.5, __( 'Wireframes progress into hi-fi screens with the components documented as we go.', 'cybertech-estimator' ) ],
				[ __( 'Validation & handoff', 'cybertech-estimator' ), 0.2, __( 'Prototype reviews, testing rounds and a developer-ready handoff.', 'cybertech-estimator' ) ],
			],
			'ai'     => [
				[ __( 'Discovery & data', 'cybertech-estimator' ), 0.25, __( 'We map the processes, the systems involved and the data each workflow needs.', 'cybertech-estimator' ) ],
				[ __( 'Build workflows', 'cybertech-estimator' ), 0.5, __( 'Workflows are built and tested against real cases, with error paths and monitoring.', 'cybertech-estimator' ) ],
				[ __( 'Pilot & rollout', 'cybertech-estimator' ), 0.25, __( 'Supervised pilot, tuning, then rollout with runbooks for your team.', 'cybertech-estimator' ) ],
			],
		];
		$plan  = $plans[ $line ] ?? $plans['web'];

		// Largest-remainder rounding so the integer weeks sum exactly to $weeks
		// and every phase keeps at least one week when there are enough.
		$exact  = array_map( static fn( array $p ): float => $p[1] * $weeks, $plan );
		$floors = array_map( 'intval', $exact );
		$left   = $weeks - array_sum( $floors );
		$order  = array_keys( $exact );
		usort( $order, static fn( int $a, int $b ): int => ( $exact[ $b ] - $floors[ $b ] ) <=> ( $exact[ $a ] - $floors[ $a ] ) );
		foreach ( $order as $i ) {
			if ( $left <= 0 ) {
				break;
			}
			++$floors[ $i ];
			--$left;
		}
		if ( $weeks >= count( $plan ) ) {
			foreach ( $floors as $i => $w ) {
				if ( 0 === $w ) {
					$max            = array_search( max( $floors ), $floors, true );
					$floors[ $i ]   = 1;
					$floors[ $max ] = $floors[ $max ] - 1;
				}
			}
		}

		$out = [];
		foreach ( $plan as $i => [$name, , $description] ) {
			if ( 0 === $floors[ $i ] ) {
				continue;
			}
			$out[] = [
				'name'        => $name,
				'weeks'       => $floors[ $i ],
				'description' => $description,
				'roles'       => array_slice( $roles, 0, 4 ),
			];
		}
		return $out;
	}

	/**
	 * Assumptions derived from the answers.
	 *
	 * @param string               $line Service line.
	 * @param array<string, mixed> $raw  Raw answers.
	 * @return array<int, string>
	 */
	private static function assumptions( string $line, array $raw ): array {
		$out = [ __( 'You provide content, brand assets and timely feedback at each review.', 'cybertech-estimator' ) ];
		if ( 'client' === ( $raw['hosting'] ?? '' ) ) {
			$out[] = __( 'Hosting and deployment infrastructure are provided by your team.', 'cybertech-estimator' );
		} elseif ( 'cybertech' === ( $raw['hosting'] ?? '' ) ) {
			$out[] = __( 'We set up hosting, deployment pipeline and monitoring as part of the project.', 'cybertech-estimator' );
		}
		if ( 'web' === $line && 'yes' === ( $raw['web_migration'] ?? '' ) ) {
			$out[] = __( 'Existing content is exportable in a structured form for migration.', 'cybertech-estimator' );
		}
		if ( 'mobile' === $line && 'existing' === ( $raw['mobile_backend'] ?? '' ) ) {
			$out[] = __( 'Your existing API is documented and reachable from a test environment.', 'cybertech-estimator' );
		}
		if ( 'ai' === $line ) {
			$out[] = __( 'API access to the systems to be integrated is available before the build starts.', 'cybertech-estimator' );
		}
		return array_slice( $out, 0, PromptBuilder::ASSUMPTIONS_MAX );
	}

	/**
	 * Risks derived from the answers.
	 *
	 * @param string               $line  Service line.
	 * @param array<string, mixed> $raw   Raw answers.
	 * @return array<int, string>
	 */
	private static function risks( string $line, array $raw ): array {
		$out     = [];
		$urgency = (string) ( $raw['urgency'] ?? 'normal' );
		if ( in_array( $urgency, [ 'urgent', 'asap' ], true ) ) {
			$out[] = __( 'A compressed timeline reduces room for scope changes; decisions need to be quick.', 'cybertech-estimator' );
		}
		if ( 'web' === $line && (int) ( $raw['web_integrations'] ?? 0 ) >= 2 ) {
			$out[] = __( 'Third-party integrations depend on external APIs and their documentation quality.', 'cybertech-estimator' );
		}
		if ( 'mobile' === $line ) {
			$out[] = __( 'App store review times are outside our control and can add days to launch.', 'cybertech-estimator' );
		}
		if ( 'ai' === $line ) {
			$out[] = __( 'Automation quality depends on the consistency of the source data.', 'cybertech-estimator' );
		}
		if ( 'design' === $line && 'yes' === ( $raw['design_brand'] ?? '' ) ) {
			$out[] = __( 'Brand identity work benefits from early stakeholder alignment to avoid late rework.', 'cybertech-estimator' );
		}
		if ( ! $out ) {
			$out[] = __( 'Scope details discovered during the first phase can shift the plan; we review it together at each milestone.', 'cybertech-estimator' );
		}
		return array_slice( $out, 0, PromptBuilder::RISKS_MAX );
	}
}
