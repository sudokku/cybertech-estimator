<?php
/**
 * Default rate card. Every number that influences an estimate lives here
 * (or in the saved override) — the engine contains no literals.
 *
 * Values were proposed in docs/PLAN.md §3 and accepted 2026-08-27.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Engine;

/**
 * Default rate card values.
 */
final class RateCardDefaults {

	public const FORMAT = 1;
	// Structural format; bump when keys change and add a migration in RateCardRepository.

	/**
	 * Build the default card.
	 *
	 * @return array<string, mixed>
	 */
	public static function card(): array {
		return [
			'format'          => self::FORMAT,
			'version'         => 1,
			'currency'        => 'EUR',
			'blended_rate'    => 45,
			'role_rates'      => [
				'pm'        => 40,
				'sse'       => 55,
				'be'        => 55,
				'devops'    => 50,
				'qa'        => 35,
				'fe_junior' => 28,
				'design'    => 40,
			],
			'service_lines'   => [
				'web'    => [
					'label'      => __( 'Web solutions', 'cybertech-estimator' ),
					'base_hours' => 80,
					'min_hours'  => 40,
				],
				'mobile' => [
					'label'      => __( 'Mobile application', 'cybertech-estimator' ),
					'base_hours' => 160,
					'min_hours'  => 80,
				],
				'design' => [
					'label'      => __( 'UI/UX Design', 'cybertech-estimator' ),
					'base_hours' => 60,
					'min_hours'  => 24,
				],
				'ai'     => [
					'label'      => __( 'AI Integration & Automation', 'cybertech-estimator' ),
					'base_hours' => 60,
					'min_hours'  => 24,
				],
			],
			'factors'         => self::factors(),
			'urgency'         => [
				'flexible' => 0.95,
				'normal'   => 1.0,
				'urgent'   => 1.25,
				'asap'     => 1.5,
			],
			'contingency'     => 0.10,
			'range_spread'    => 0.20,
			'rounding'        => [
				'threshold' => 10000,
				'below'     => 250,
				'above'     => 500,
			],
			'weekly_capacity' => 30,
			'min_weeks'       => 2,
			'team_bands'      => self::team_bands(),
			'reveal_bands'    => [
				[
					'id'        => 'small',
					'label'     => __( 'Small engagement', 'cybertech-estimator' ),
					'max_price' => 10000,
				],
				[
					'id'        => 'mid',
					'label'     => __( 'Mid-size engagement', 'cybertech-estimator' ),
					'max_price' => 40000,
				],
				[
					'id'        => 'enterprise',
					'label'     => __( 'Enterprise engagement', 'cybertech-estimator' ),
					'max_price' => null,
				],
			],
			'budget_bands'    => [
				'under_5k'    => [
					'min' => 0,
					'max' => 5000,
				],
				'5k_15k'      => [
					'min' => 5000,
					'max' => 15000,
				],
				'15k_40k'     => [
					'min' => 15000,
					'max' => 40000,
				],
				'40k_100k'    => [
					'min' => 40000,
					'max' => 100000,
				],
				'over_100k'   => [
					'min' => 100000,
					'max' => null,
				],
				'undisclosed' => [
					'min' => null,
					'max' => null,
				],
			],
			'qualification'   => [
				'budget'      => [
					'covers_high'       => 40,
					'overlaps'          => 30,
					'below_within_half' => 15,
					'far_below'         => 0,
					'undisclosed'       => 20,
				],
				'urgency'     => [
					'flexible' => 8,
					'normal'   => 12,
					'urgent'   => 15,
					'asap'     => 10,
				],
				'scope'       => [
					[
						'max_hours' => 80,
						'points'    => 8,
					],
					[
						'max_hours' => 300,
						'points'    => 15,
					],
					[
						'max_hours' => 800,
						'points'    => 20,
					],
					[
						'max_hours' => null,
						'points'    => 15,
					],
				],
				'notes'       => [
					'min_chars' => 40,
					'points'    => 10,
				],
				'maintenance' => [ 'points' => 10 ],
				'hosting'     => [ 'points' => 5 ],
				'thresholds'  => [
					'green' => 70,
					'amber' => 40,
				],
			],
		];
	}

	/**
	 * Factor table. `order` is the deterministic application order within a
	 * type; `per_unit` multiplies `value` by the numeric answer.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function factors(): array {
		$f = static fn( string $label, array $applies, string $type, float $value, int $order, string $note, bool $per_unit = false ): array => [
			'label'      => $label,
			'applies_to' => $applies,
			'type'       => $type,
			'value'      => $value,
			'order'      => $order,
			'per_unit'   => $per_unit,
			'note'       => $note,
		];
		$h = 'add_hours';
		$m = 'multiplier';

		return [
			// Web.
			'web_platform_drupal'              => $f( __( 'Platform: Drupal', 'cybertech-estimator' ), [ 'web' ], $h, 40, 10, __( 'Heavier content model and module setup than WordPress.', 'cybertech-estimator' ) ),
			'web_platform_joomla'              => $f( __( 'Platform: Joomla', 'cybertech-estimator' ), [ 'web' ], $h, 24, 10, __( 'Template and extension configuration.', 'cybertech-estimator' ) ),
			'web_platform_django'              => $f( __( 'Platform: Django CMS', 'cybertech-estimator' ), [ 'web' ], $h, 60, 10, __( 'Custom models, admin and deployment.', 'cybertech-estimator' ) ),
			'web_platform_custom'              => $f( __( 'Platform: custom build', 'cybertech-estimator' ), [ 'web' ], $h, 120, 10, __( 'Own backend, auth, admin and infrastructure.', 'cybertech-estimator' ) ),
			'web_ecommerce_woocommerce'        => $f( __( 'E-commerce: WooCommerce', 'cybertech-estimator' ), [ 'web' ], $h, 40, 20, __( 'Catalogue, checkout, payments, shipping.', 'cybertech-estimator' ) ),
			'web_ecommerce_prestashop'         => $f( __( 'E-commerce: PrestaShop', 'cybertech-estimator' ), [ 'web' ], $h, 60, 20, __( 'Catalogue, checkout, payments, shipping, theme.', 'cybertech-estimator' ) ),
			'web_ecommerce_magento'            => $f( __( 'E-commerce: Magento', 'cybertech-estimator' ), [ 'web' ], $m, 1.8, 20, __( 'Magento multiplies everything: hosting, extensions, QA.', 'cybertech-estimator' ) ),
			'web_templates'                    => $f( __( 'Per unique page template', 'cybertech-estimator' ), [ 'web' ], $h, 6, 30, __( 'Design implementation + responsive QA per layout.', 'cybertech-estimator' ), true ),
			'web_multilingual'                 => $f( __( 'Multilingual', 'cybertech-estimator' ), [ 'web' ], $m, 1.25, 40, __( 'Translation plumbing, content ops and per-language QA.', 'cybertech-estimator' ) ),
			'web_integrations'                 => $f( __( 'Per third-party integration', 'cybertech-estimator' ), [ 'web' ], $h, 12, 50, __( 'API wiring, error handling, testing.', 'cybertech-estimator' ), true ),
			'web_migration'                    => $f( __( 'Content migration', 'cybertech-estimator' ), [ 'web' ], $h, 24, 60, __( 'Export/import, clean-up, redirects.', 'cybertech-estimator' ) ),
			// Mobile.
			'mobile_framework_flutter'         => $f( __( 'Framework: Flutter', 'cybertech-estimator' ), [ 'mobile' ], $m, 1.0, 10, __( 'Baseline. Framework choice barely moves cost — deliberately visible.', 'cybertech-estimator' ) ),
			'mobile_framework_react_native'    => $f( __( 'Framework: React Native', 'cybertech-estimator' ), [ 'mobile' ], $m, 1.0, 10, __( 'Baseline.', 'cybertech-estimator' ) ),
			'mobile_framework_ionic'           => $f( __( 'Framework: Ionic', 'cybertech-estimator' ), [ 'mobile' ], $m, 0.9, 10, __( 'Web-stack reuse shortens UI work.', 'cybertech-estimator' ) ),
			'mobile_platforms_both'            => $f( __( 'Both iOS and Android', 'cybertech-estimator' ), [ 'mobile' ], $m, 1.3, 20, __( 'Two store submissions, two QA matrices.', 'cybertech-estimator' ) ),
			'mobile_offline'                   => $f( __( 'Offline support', 'cybertech-estimator' ), [ 'mobile' ], $h, 40, 30, __( 'Local storage, sync, conflict handling.', 'cybertech-estimator' ) ),
			'mobile_auth'                      => $f( __( 'Authentication', 'cybertech-estimator' ), [ 'mobile' ], $h, 24, 30, __( 'Sign-up, login, recovery, sessions.', 'cybertech-estimator' ) ),
			'mobile_payments'                  => $f( __( 'In-app payments', 'cybertech-estimator' ), [ 'mobile' ], $h, 32, 30, __( 'PSP integration, store billing rules, PCI.', 'cybertech-estimator' ) ),
			'mobile_push'                      => $f( __( 'Push notifications', 'cybertech-estimator' ), [ 'mobile' ], $h, 16, 30, __( 'FCM/APNs setup, topics, deep links.', 'cybertech-estimator' ) ),
			'mobile_backend_existing'          => $f( __( 'Integrate existing backend', 'cybertech-estimator' ), [ 'mobile' ], $h, 16, 40, __( 'API discovery and client wiring.', 'cybertech-estimator' ) ),
			'mobile_backend_needed'            => $f( __( 'Build a backend', 'cybertech-estimator' ), [ 'mobile' ], $h, 80, 40, __( 'API, database, auth, admin.', 'cybertech-estimator' ) ),
			// UI/UX.
			'design_deliverable_research'      => $f( __( 'User research', 'cybertech-estimator' ), [ 'design' ], $h, 24, 10, __( 'Interviews, synthesis, personas.', 'cybertech-estimator' ) ),
			'design_deliverable_wireframes'    => $f( __( 'Wireframes', 'cybertech-estimator' ), [ 'design' ], $h, 16, 10, __( 'Low-fi flows and structure.', 'cybertech-estimator' ) ),
			'design_deliverable_hifi'          => $f( __( 'Hi-fi design', 'cybertech-estimator' ), [ 'design' ], $h, 24, 10, __( 'Visual design, states, handoff.', 'cybertech-estimator' ) ),
			'design_deliverable_prototype'     => $f( __( 'Interactive prototype', 'cybertech-estimator' ), [ 'design' ], $h, 16, 10, __( 'Clickable Figma prototype.', 'cybertech-estimator' ) ),
			'design_deliverable_design_system' => $f( __( 'Design system', 'cybertech-estimator' ), [ 'design' ], $h, 40, 10, __( 'Tokens, components, documentation.', 'cybertech-estimator' ) ),
			'design_screens'                   => $f( __( 'Per screen', 'cybertech-estimator' ), [ 'design' ], $h, 3, 20, __( 'Average across simple and complex screens.', 'cybertech-estimator' ), true ),
			'design_brand'                     => $f( __( 'Brand identity', 'cybertech-estimator' ), [ 'design' ], $h, 40, 30, __( 'Logo, palette, type, guidelines.', 'cybertech-estimator' ) ),
			'design_testing_rounds'            => $f( __( 'Per usability testing round', 'cybertech-estimator' ), [ 'design' ], $h, 12, 40, __( 'Recruiting, sessions, iteration.', 'cybertech-estimator' ), true ),
			// AI automation.
			'ai_workflows'                     => $f( __( 'Per n8n workflow', 'cybertech-estimator' ), [ 'ai' ], $h, 16, 10, __( 'Design, build, error paths, monitoring.', 'cybertech-estimator' ), true ),
			'ai_provider_open_weight'          => $f( __( 'Open-weight model', 'cybertech-estimator' ), [ 'ai' ], $h, 24, 20, __( 'Hosting, evaluation, prompt tuning.', 'cybertech-estimator' ) ),
			'ai_provider_undecided'            => $f( __( 'Provider undecided', 'cybertech-estimator' ), [ 'ai' ], $h, 8, 20, __( 'Discovery and comparison.', 'cybertech-estimator' ) ),
			'ai_voice_vapi'                    => $f( __( 'Voice agent (Vapi)', 'cybertech-estimator' ), [ 'ai' ], $h, 40, 30, __( 'Telephony, prompts, hand-off, testing.', 'cybertech-estimator' ) ),
			'ai_systems'                       => $f( __( 'Per system integrated', 'cybertech-estimator' ), [ 'ai' ], $h, 12, 40, __( 'Credentials, mapping, retries.', 'cybertech-estimator' ), true ),
			'ai_data_medium'                   => $f( __( 'Data volume: medium', 'cybertech-estimator' ), [ 'ai' ], $m, 1.15, 50, __( 'Batching, rate limits, cost controls.', 'cybertech-estimator' ) ),
			'ai_data_large'                    => $f( __( 'Data volume: large', 'cybertech-estimator' ), [ 'ai' ], $m, 1.35, 50, __( 'Queues, observability, cost controls.', 'cybertech-estimator' ) ),
			'ai_hitl'                          => $f( __( 'Human-in-the-loop review', 'cybertech-estimator' ), [ 'ai' ], $h, 16, 60, __( 'Review UI and audit trail.', 'cybertech-estimator' ) ),
			// Delivery context (all lines).
			'ctx_hosting_cybertech'            => $f( __( 'Hosting & DevOps by Cybertech', 'cybertech-estimator' ), [ 'web', 'mobile', 'design', 'ai' ], $h, 16, 90, __( 'Environment setup, pipeline, monitoring.', 'cybertech-estimator' ) ),
			'ctx_hosting_undecided'            => $f( __( 'Hosting undecided', 'cybertech-estimator' ), [ 'web', 'mobile', 'design', 'ai' ], $h, 8, 90, __( 'Infrastructure discovery.', 'cybertech-estimator' ) ),
		];
	}

	/**
	 * Role allocation per service line, by total-hours band. Shares are
	 * percentages and must sum to 100 within a band.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private static function team_bands(): array {
		$band = static fn( ?int $max, array $roles ): array => [
			'max_hours' => $max,
			'roles'     => $roles,
		];
		return [
			'web'    => [
				$band(
					120,
					[
						'pm'        => 10,
						'sse'       => 40,
						'be'        => 0,
						'devops'    => 5,
						'qa'        => 15,
						'fe_junior' => 20,
						'design'    => 10,
					]
				),
				$band(
					400,
					[
						'pm'        => 12,
						'sse'       => 30,
						'be'        => 20,
						'devops'    => 6,
						'qa'        => 15,
						'fe_junior' => 12,
						'design'    => 5,
					]
				),
				$band(
					null,
					[
						'pm'        => 15,
						'sse'       => 25,
						'be'        => 25,
						'devops'    => 8,
						'qa'        => 15,
						'fe_junior' => 7,
						'design'    => 5,
					]
				),
			],
			'mobile' => [
				$band(
					120,
					[
						'pm'        => 10,
						'sse'       => 45,
						'be'        => 15,
						'devops'    => 5,
						'qa'        => 15,
						'fe_junior' => 5,
						'design'    => 5,
					]
				),
				$band(
					400,
					[
						'pm'        => 12,
						'sse'       => 35,
						'be'        => 20,
						'devops'    => 6,
						'qa'        => 17,
						'fe_junior' => 5,
						'design'    => 5,
					]
				),
				$band(
					null,
					[
						'pm'        => 15,
						'sse'       => 30,
						'be'        => 20,
						'devops'    => 8,
						'qa'        => 17,
						'fe_junior' => 5,
						'design'    => 5,
					]
				),
			],
			'design' => [
				$band(
					120,
					[
						'pm'        => 10,
						'sse'       => 5,
						'be'        => 0,
						'devops'    => 0,
						'qa'        => 5,
						'fe_junior' => 10,
						'design'    => 70,
					]
				),
				$band(
					400,
					[
						'pm'        => 10,
						'sse'       => 10,
						'be'        => 0,
						'devops'    => 0,
						'qa'        => 5,
						'fe_junior' => 10,
						'design'    => 65,
					]
				),
				$band(
					null,
					[
						'pm'        => 10,
						'sse'       => 15,
						'be'        => 0,
						'devops'    => 0,
						'qa'        => 5,
						'fe_junior' => 10,
						'design'    => 60,
					]
				),
			],
			'ai'     => [
				$band(
					120,
					[
						'pm'        => 12,
						'sse'       => 40,
						'be'        => 25,
						'devops'    => 8,
						'qa'        => 10,
						'fe_junior' => 0,
						'design'    => 5,
					]
				),
				$band(
					400,
					[
						'pm'        => 12,
						'sse'       => 35,
						'be'        => 30,
						'devops'    => 8,
						'qa'        => 10,
						'fe_junior' => 0,
						'design'    => 5,
					]
				),
				$band(
					null,
					[
						'pm'        => 15,
						'sse'       => 30,
						'be'        => 30,
						'devops'    => 10,
						'qa'        => 10,
						'fe_junior' => 0,
						'design'    => 5,
					]
				),
			],
		];
	}

	/**
	 * Translated role labels (kept out of the card so they follow the site locale).
	 *
	 * @return array<string, string>
	 */
	public static function role_labels(): array {
		return [
			'pm'        => __( 'Project manager', 'cybertech-estimator' ),
			'sse'       => __( 'Senior software engineer', 'cybertech-estimator' ),
			'be'        => __( 'Senior backend developer', 'cybertech-estimator' ),
			'devops'    => __( 'DevOps', 'cybertech-estimator' ),
			'qa'        => __( 'QA', 'cybertech-estimator' ),
			'fe_junior' => __( 'Junior frontend developer', 'cybertech-estimator' ),
			'design'    => __( 'Designer', 'cybertech-estimator' ),
		];
	}
}
