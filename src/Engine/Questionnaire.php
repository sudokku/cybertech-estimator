<?php
/**
 * Declarative questionnaire schema.
 *
 * Steps and questions are data, not markup: the wizard renderer, the input
 * sanitizer, the sandbox and the pricing engine all read this one structure.
 * Option values are stable identifiers and double as the link into the rate
 * card via each option's `factors` list.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Engine;

/**
 * Questionnaire schema.
 */
final class Questionnaire {

	public const TYPE_SINGLE   = 'single';
	public const TYPE_MULTI    = 'multi';
	public const TYPE_NUMBER   = 'number';
	public const TYPE_TEXT     = 'text';
	public const TYPE_EMAIL    = 'email';
	public const TYPE_CHECKBOX = 'checkbox';

	public const SERVICE_LINES = [ 'web', 'mobile', 'design', 'ai' ];
	public const NOTES_MAX     = 1000;

	/**
	 * Steps in wizard order. Contact fields live in the last step but are
	 * never used for pricing — see `is_contact_question()`.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function steps(): array {
		return [
			[
				'id'        => 'service',
				'title'     => __( 'What are we building?', 'cybertech-estimator' ),
				'questions' => [
					self::single(
						'service_line',
						__( 'Service line', 'cybertech-estimator' ),
						[
							'web'    => [ __( 'Web solutions', 'cybertech-estimator' ), __( 'Websites, portals, e-commerce, custom web apps', 'cybertech-estimator' ) ],
							'mobile' => [ __( 'Mobile application', 'cybertech-estimator' ), __( 'iOS / Android apps with Flutter, Ionic or React Native', 'cybertech-estimator' ) ],
							'design' => [ __( 'UI/UX Design', 'cybertech-estimator' ), __( 'Research, wireframes, hi-fi design, prototypes, design systems', 'cybertech-estimator' ) ],
							'ai'     => [ __( 'AI Integration & Automation', 'cybertech-estimator' ), __( 'n8n workflows, LLM integrations, voice agents', 'cybertech-estimator' ) ],
						],
						[ 'required' => true ]
					),
				],
			],
			[
				'id'        => 'scope',
				'title'     => __( 'Scope', 'cybertech-estimator' ),
				'questions' => array_merge( self::web_questions(), self::mobile_questions(), self::design_questions(), self::ai_questions() ),
			],
			[
				'id'        => 'context',
				'title'     => __( 'Delivery context', 'cybertech-estimator' ),
				'questions' => [
					self::single(
						'urgency',
						__( 'Timeline', 'cybertech-estimator' ),
						[
							'flexible' => [ __( 'Flexible', 'cybertech-estimator' ), __( 'No fixed deadline', 'cybertech-estimator' ) ],
							'normal'   => [ __( 'Normal', 'cybertech-estimator' ), __( 'A realistic schedule', 'cybertech-estimator' ) ],
							'urgent'   => [ __( 'Urgent', 'cybertech-estimator' ), __( 'We need to move fast', 'cybertech-estimator' ) ],
							'asap'     => [ __( 'ASAP', 'cybertech-estimator' ), __( 'Yesterday would be nice', 'cybertech-estimator' ) ],
						],
						[
							'required' => true,
							'default'  => 'normal',
							'help'     => __( 'Urgency changes the team size and the price; it does not change the scope.', 'cybertech-estimator' ),
						]
					),
					self::single(
						'budget',
						__( 'Budget range', 'cybertech-estimator' ),
						[
							'under_5k'    => [ __( 'Under €5,000', 'cybertech-estimator' ), '' ],
							'5k_15k'      => [ __( '€5,000 – €15,000', 'cybertech-estimator' ), '' ],
							'15k_40k'     => [ __( '€15,000 – €40,000', 'cybertech-estimator' ), '' ],
							'40k_100k'    => [ __( '€40,000 – €100,000', 'cybertech-estimator' ), '' ],
							'over_100k'   => [ __( 'Over €100,000', 'cybertech-estimator' ), '' ],
							'undisclosed' => [ __( 'Prefer not to say', 'cybertech-estimator' ), '' ],
						],
						[
							'required' => false,
							'default'  => 'undisclosed',
							'help'     => __( 'Optional. Helps us propose the right approach; it does not change the estimate.', 'cybertech-estimator' ),
						]
					),
					self::yes_no( 'maintenance', __( 'Do you want ongoing maintenance and support after launch?', 'cybertech-estimator' ), [] ),
					self::single(
						'hosting',
						__( 'Who owns hosting and DevOps?', 'cybertech-estimator' ),
						[
							'client'    => [ __( 'We do', 'cybertech-estimator' ), __( 'You have infrastructure and people', 'cybertech-estimator' ) ],
							'cybertech' => [ __( 'Cybertech should', 'cybertech-estimator' ), __( 'Set-up, deployment pipeline and monitoring', 'cybertech-estimator' ) ],
							'undecided' => [ __( 'Not decided yet', 'cybertech-estimator' ), '' ],
						],
						[
							'required' => true,
							'default'  => 'undecided',
						],
						[
							'cybertech' => [ 'ctx_hosting_cybertech' ],
							'undecided' => [ 'ctx_hosting_undecided' ],
						]
					),
				],
			],
			[
				'id'        => 'notes',
				'title'     => __( 'Anything else?', 'cybertech-estimator' ),
				'questions' => [
					[
						'id'       => 'notes',
						'type'     => self::TYPE_TEXT,
						'label'    => __( 'Anything else we should know?', 'cybertech-estimator' ),
						'help'     => __( 'Optional. Goals, constraints, existing systems, examples you like.', 'cybertech-estimator' ),
						'required' => false,
						'max'      => self::NOTES_MAX,
					],
				],
			],
			[
				'id'        => 'contact',
				'title'     => __( 'Where should we send the estimate?', 'cybertech-estimator' ),
				'questions' => [
					[
						'id'       => 'name',
						'type'     => self::TYPE_TEXT,
						'label'    => __( 'Your name', 'cybertech-estimator' ),
						'required' => true,
						'max'      => 120,
						'contact'  => true,
					],
					[
						'id'       => 'email',
						'type'     => self::TYPE_EMAIL,
						'label'    => __( 'Work email', 'cybertech-estimator' ),
						'required' => true,
						'max'      => 254,
						'contact'  => true,
					],
					[
						'id'       => 'company',
						'type'     => self::TYPE_TEXT,
						'label'    => __( 'Company', 'cybertech-estimator' ),
						'required' => false,
						'max'      => 120,
						'contact'  => true,
					],
					[
						'id'       => 'phone',
						'type'     => self::TYPE_TEXT,
						'label'    => __( 'Phone', 'cybertech-estimator' ),
						'required' => false,
						'max'      => 40,
						'contact'  => true,
					],
					[
						'id'      => 'consent',
						'type'    => self::TYPE_CHECKBOX,
						'label'   => '',
						// Rendered from Brand::get( 'consent_text' ) so the wording is white-labelled.
													'required' => true,
						'contact' => true,
					],
				],
			],
		];
	}

	/**
	 * Flat map of question id => question definition.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function questions(): array {
		$map = [];
		foreach ( self::steps() as $step ) {
			foreach ( $step['questions'] as $question ) {
				$map[ $question['id'] ] = $question;
			}
		}
		return $map;
	}

	/**
	 * Whether a question is visible given the answers so far.
	 *
	 * @param array<string, mixed> $question Question definition.
	 * @param array<string, mixed> $answers  Answers so far.
	 */
	public static function is_visible( array $question, array $answers ): bool {
		if ( empty( $question['show_if'] ) ) {
			return true;
		}
		foreach ( $question['show_if'] as $dependency => $allowed ) {
			$current = $answers[ $dependency ] ?? null;
			if ( ! in_array( $current, (array) $allowed, true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Resolve the rate-card factors selected by a set of answers.
	 *
	 * Returns factor id => units (1 for options, the numeric answer for
	 * number questions). Hidden questions are ignored even if an answer is
	 * present, so a visitor who switches service line does not drag along
	 * factors from the previous branch.
	 *
	 * @param array<string, mixed> $answers Validated answers.
	 * @return array<string, float>
	 */
	public static function selected_factors( array $answers ): array {
		$selected = [];
		foreach ( self::questions() as $id => $question ) {
			if ( ! self::is_visible( $question, $answers ) || ! array_key_exists( $id, $answers ) ) {
				continue;
			}
			$value = $answers[ $id ];
			switch ( $question['type'] ) {
				case self::TYPE_NUMBER:
					foreach ( (array) ( $question['factors'] ?? [] ) as $factor ) {
						$selected[ $factor ] = (float) $value;
					}
					break;
				case self::TYPE_SINGLE:
				case self::TYPE_MULTI:
					foreach ( (array) $value as $option_value ) {
						$option = $question['options'][ $option_value ] ?? null;
						if ( ! $option ) {
							continue;
						}
						foreach ( (array) ( $option['factors'] ?? [] ) as $factor ) {
							$selected[ $factor ] = 1.0;
						}
					}
					break;
			}
		}//end foreach
		return $selected;
	}

	/**
	 * Human-readable labels for a set of answers (used by the AI prompt,
	 * emails and the lead snapshot).
	 *
	 * @param array<string, mixed> $answers Validated answers.
	 * @return array<string, array{label: string, value: string}>
	 */
	public static function resolve_labels( array $answers ): array {
		$out = [];
		foreach ( self::questions() as $id => $question ) {
			if ( ! self::is_visible( $question, $answers ) || ! array_key_exists( $id, $answers ) ) {
				continue;
			}
			$value = $answers[ $id ];
			switch ( $question['type'] ) {
				case self::TYPE_SINGLE:
					$text = (string) ( $question['options'][ $value ]['label'] ?? $value );
					break;
				case self::TYPE_MULTI:
					$labels = [];
					foreach ( (array) $value as $v ) {
						$labels[] = (string) ( $question['options'][ $v ]['label'] ?? $v );
					}
					$text = implode( ', ', $labels );
					break;
				case self::TYPE_CHECKBOX:
					$text = $value ? __( 'Yes', 'cybertech-estimator' ) : __( 'No', 'cybertech-estimator' );
					break;
				default:
					$text = (string) $value;
			}
			$out[ $id ] = [
				'label' => (string) $question['label'],
				'value' => $text,
			];
		}//end foreach
		return $out;
	}

	/**
	 * Contact/consent questions are stored on the lead, never fed to pricing.
	 *
	 * @param array<string, mixed> $question Question definition.
	 */
	public static function is_contact_question( array $question ): bool {
		return ! empty( $question['contact'] );
	}

	/* ---------- branch definitions ---------- */

	/**
	 * Web branch.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function web_questions(): array {
		$web = [ 'service_line' => [ 'web' ] ];
		return [
			self::single(
				'web_platform',
				__( 'Platform', 'cybertech-estimator' ),
				[
					'wordpress' => [ 'WordPress', '' ],
					'drupal'    => [ 'Drupal', '' ],
					'joomla'    => [ 'Joomla', '' ],
					'django'    => [ 'Django CMS', '' ],
					'custom'    => [ __( 'Custom build', 'cybertech-estimator' ), __( 'Next.js / Vue / Angular with a custom backend', 'cybertech-estimator' ) ],
				],
				[
					'required' => true,
					'default'  => 'wordpress',
					'show_if'  => $web,
				],
				[
					'drupal' => [ 'web_platform_drupal' ],
					'joomla' => [ 'web_platform_joomla' ],
					'django' => [ 'web_platform_django' ],
					'custom' => [ 'web_platform_custom' ],
				]
			),
			self::single(
				'web_ecommerce',
				__( 'E-commerce', 'cybertech-estimator' ),
				[
					'none'        => [ __( 'No online sales', 'cybertech-estimator' ), '' ],
					'woocommerce' => [ 'WooCommerce', '' ],
					'prestashop'  => [ 'PrestaShop', '' ],
					'magento'     => [ 'Magento', '' ],
				],
				[
					'required' => true,
					'default'  => 'none',
					'show_if'  => $web,
				],
				[
					'woocommerce' => [ 'web_ecommerce_woocommerce' ],
					'prestashop'  => [ 'web_ecommerce_prestashop' ],
					'magento'     => [ 'web_ecommerce_magento' ],
				]
			),
			self::number(
				'web_templates',
				__( 'Unique page templates', 'cybertech-estimator' ),
				1,
				40,
				5,
				[ 'web_templates' ],
				[
					'show_if' => $web,
					'help'    => __( 'Home, service page, article, contact… count the distinct layouts, not the pages.', 'cybertech-estimator' ),
				]
			),
			self::yes_no( 'web_multilingual', __( 'Multilingual?', 'cybertech-estimator' ), [ 'web_multilingual' ], [ 'show_if' => $web ] ),
			self::number(
				'web_integrations',
				__( 'Third-party integrations', 'cybertech-estimator' ),
				0,
				10,
				0,
				[ 'web_integrations' ],
				[
					'show_if' => $web,
					'help'    => __( 'CRM, ERP, payment, newsletter, booking…', 'cybertech-estimator' ),
				]
			),
			self::yes_no( 'web_migration', __( 'Migrate content from an existing site?', 'cybertech-estimator' ), [ 'web_migration' ], [ 'show_if' => $web ] ),
		];
	}

	/**
	 * Mobile branch.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function mobile_questions(): array {
		$mobile = [ 'service_line' => [ 'mobile' ] ];
		return [
			self::single(
				'mobile_framework',
				__( 'Framework', 'cybertech-estimator' ),
				[
					'flutter'      => [ 'Flutter', '' ],
					'react_native' => [ 'React Native', '' ],
					'ionic'        => [ 'Ionic', '' ],
				],
				[
					'required' => true,
					'default'  => 'flutter',
					'show_if'  => $mobile,
				],
				[
					'flutter'      => [ 'mobile_framework_flutter' ],
					'react_native' => [ 'mobile_framework_react_native' ],
					'ionic'        => [ 'mobile_framework_ionic' ],
				]
			),
			self::single(
				'mobile_platforms',
				__( 'Platforms', 'cybertech-estimator' ),
				[
					'ios'     => [ 'iOS', '' ],
					'android' => [ 'Android', '' ],
					'both'    => [ __( 'Both', 'cybertech-estimator' ), '' ],
				],
				[
					'required' => true,
					'default'  => 'both',
					'show_if'  => $mobile,
				],
				[ 'both' => [ 'mobile_platforms_both' ] ]
			),
			self::yes_no( 'mobile_offline', __( 'Offline support?', 'cybertech-estimator' ), [ 'mobile_offline' ], [ 'show_if' => $mobile ] ),
			self::yes_no( 'mobile_auth', __( 'User accounts / authentication?', 'cybertech-estimator' ), [ 'mobile_auth' ], [ 'show_if' => $mobile ] ),
			self::yes_no( 'mobile_payments', __( 'In-app payments?', 'cybertech-estimator' ), [ 'mobile_payments' ], [ 'show_if' => $mobile ] ),
			self::yes_no( 'mobile_push', __( 'Push notifications?', 'cybertech-estimator' ), [ 'mobile_push' ], [ 'show_if' => $mobile ] ),
			self::single(
				'mobile_backend',
				__( 'Backend', 'cybertech-estimator' ),
				[
					'existing' => [ __( 'We have an API', 'cybertech-estimator' ), __( 'Integrate with an existing backend', 'cybertech-estimator' ) ],
					'needed'   => [ __( 'Build one for us', 'cybertech-estimator' ), '' ],
					'none'     => [ __( 'No backend needed', 'cybertech-estimator' ), '' ],
				],
				[
					'required' => true,
					'default'  => 'existing',
					'show_if'  => $mobile,
				],
				[
					'existing' => [ 'mobile_backend_existing' ],
					'needed'   => [ 'mobile_backend_needed' ],
				]
			),
		];
	}

	/**
	 * UI/UX branch.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function design_questions(): array {
		$design = [ 'service_line' => [ 'design' ] ];
		return [
			[
				'id'       => 'design_deliverables',
				'type'     => self::TYPE_MULTI,
				'label'    => __( 'Deliverables', 'cybertech-estimator' ),
				'required' => true,
				'default'  => [ 'wireframes', 'hifi' ],
				'show_if'  => $design,
				'options'  => [
					'research'      => [
						'label'   => __( 'User research', 'cybertech-estimator' ),
						'help'    => '',
						'factors' => [ 'design_deliverable_research' ],
					],
					'wireframes'    => [
						'label'   => __( 'Wireframes', 'cybertech-estimator' ),
						'help'    => '',
						'factors' => [ 'design_deliverable_wireframes' ],
					],
					'hifi'          => [
						'label'   => __( 'Hi-fi design', 'cybertech-estimator' ),
						'help'    => '',
						'factors' => [ 'design_deliverable_hifi' ],
					],
					'prototype'     => [
						'label'   => __( 'Interactive prototype', 'cybertech-estimator' ),
						'help'    => '',
						'factors' => [ 'design_deliverable_prototype' ],
					],
					'design_system' => [
						'label'   => __( 'Design system', 'cybertech-estimator' ),
						'help'    => '',
						'factors' => [ 'design_deliverable_design_system' ],
					],
				],
			],
			self::number( 'design_screens', __( 'Number of screens', 'cybertech-estimator' ), 1, 100, 10, [ 'design_screens' ], [ 'show_if' => $design ] ),
			self::yes_no( 'design_brand', __( 'Brand identity needed?', 'cybertech-estimator' ), [ 'design_brand' ], [ 'show_if' => $design ] ),
			self::number( 'design_testing_rounds', __( 'Usability testing rounds', 'cybertech-estimator' ), 0, 5, 0, [ 'design_testing_rounds' ], [ 'show_if' => $design ] ),
		];
	}

	/**
	 * AI automation branch.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function ai_questions(): array {
		$ai = [ 'service_line' => [ 'ai' ] ];
		return [
			self::number( 'ai_workflows', __( 'n8n workflows', 'cybertech-estimator' ), 1, 20, 2, [ 'ai_workflows' ], [ 'show_if' => $ai ] ),
			self::single(
				'ai_provider',
				__( 'LLM provider preference', 'cybertech-estimator' ),
				[
					'openai'      => [ 'OpenAI', '' ],
					'gemini'      => [ 'Gemini', '' ],
					'open_weight' => [ __( 'Open-weight model', 'cybertech-estimator' ), __( 'Self-hosted, e.g. Llama / Mistral', 'cybertech-estimator' ) ],
					'undecided'   => [ __( 'Undecided', 'cybertech-estimator' ), '' ],
				],
				[
					'required' => true,
					'default'  => 'undecided',
					'show_if'  => $ai,
				],
				[
					'open_weight' => [ 'ai_provider_open_weight' ],
					'undecided'   => [ 'ai_provider_undecided' ],
				]
			),
			self::yes_no( 'ai_voice', __( 'Voice agent (Vapi)?', 'cybertech-estimator' ), [ 'ai_voice_vapi' ], [ 'show_if' => $ai ] ),
			self::number(
				'ai_systems',
				__( 'Systems to integrate', 'cybertech-estimator' ),
				0,
				10,
				1,
				[ 'ai_systems' ],
				[
					'show_if' => $ai,
					'help'    => __( 'CRM, helpdesk, ERP, e-mail, Slack…', 'cybertech-estimator' ),
				]
			),
			self::single(
				'ai_data',
				__( 'Data volume', 'cybertech-estimator' ),
				[
					'small'  => [ __( 'Small', 'cybertech-estimator' ), __( 'Hundreds of items a month', 'cybertech-estimator' ) ],
					'medium' => [ __( 'Medium', 'cybertech-estimator' ), __( 'Thousands a month', 'cybertech-estimator' ) ],
					'large'  => [ __( 'Large', 'cybertech-estimator' ), __( 'Tens of thousands or more', 'cybertech-estimator' ) ],
				],
				[
					'required' => true,
					'default'  => 'small',
					'show_if'  => $ai,
				],
				[
					'medium' => [ 'ai_data_medium' ],
					'large'  => [ 'ai_data_large' ],
				]
			),
			self::yes_no( 'ai_hitl', __( 'Human-in-the-loop review?', 'cybertech-estimator' ), [ 'ai_hitl' ], [ 'show_if' => $ai ] ),
		];
	}

	/* ---------- builders ---------- */

	/**
	 * Single-select question.
	 *
	 * @param string                                     $id      Question id.
	 * @param string                                     $label   Label.
	 * @param array<string, array{0: string, 1: string}> $options value => [label, help].
	 * @param array<string, mixed>                       $extra   required/default/show_if/help.
	 * @param array<string, array<int, string>>          $factors value => factor ids.
	 * @return array<string, mixed>
	 */
	private static function single( string $id, string $label, array $options, array $extra = [], array $factors = [] ): array {
		$opts = [];
		foreach ( $options as $value => [$opt_label, $opt_help] ) {
			$opts[ $value ] = [
				'label'   => $opt_label,
				'help'    => $opt_help,
				'factors' => $factors[ $value ] ?? [],
			];
		}
		return array_merge(
			[
				'id'       => $id,
				'type'     => self::TYPE_SINGLE,
				'label'    => $label,
				'help'     => '',
				'required' => true,
				'options'  => $opts,
			],
			$extra
		);
	}

	/**
	 * Yes/no question rendered as two options; "yes" carries the factors.
	 *
	 * @param string               $id      Question id.
	 * @param string               $label   Label.
	 * @param array<int, string>   $factors Factor ids applied on "yes".
	 * @param array<string, mixed> $extra Extra keys.
	 * @return array<string, mixed>
	 */
	private static function yes_no( string $id, string $label, array $factors, array $extra = [] ): array {
		return self::single(
			$id,
			$label,
			[
				'no'  => [ __( 'No', 'cybertech-estimator' ), '' ],
				'yes' => [ __( 'Yes', 'cybertech-estimator' ), '' ],
			],
			array_merge(
				[
					'required' => true,
					'default'  => 'no',
				],
				$extra
			),
			[ 'yes' => $factors ]
		);
	}

	/**
	 * Number question; factors are applied per unit.
	 *
	 * @param string               $id      Question id.
	 * @param string               $label   Label.
	 * @param int                  $min     Minimum.
	 * @param int                  $max     Maximum.
	 * @param int                  $initial Default value.
	 * @param array<int, string>   $factors Factor ids.
	 * @param array<string, mixed> $extra   Extra keys.
	 * @return array<string, mixed>
	 */
	private static function number( string $id, string $label, int $min, int $max, int $initial, array $factors, array $extra = [] ): array {
		return array_merge(
			[
				'id'       => $id,
				'type'     => self::TYPE_NUMBER,
				'label'    => $label,
				'help'     => '',
				'required' => true,
				'min'      => $min,
				'max'      => $max,
				'default'  => $initial,
				'factors'  => $factors,
			],
			$extra
		);
	}
}
