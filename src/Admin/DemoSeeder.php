<?php
/**
 * Demo data: 10 realistic leads spread over the past six weeks across all
 * four service lines, with varied scores and pipeline statuses. Empty
 * admin tables kill demos.
 *
 * Available as an admin button (Settings → Diagnostics) and as
 * `wp ct-estimator seed` / `wp ct-estimator unseed`.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Admin;

use Cybertech\Estimator\Ai\FallbackNarrative;
use Cybertech\Estimator\Ai\NarrativeService;
use Cybertech\Estimator\Engine\PricingEngine;
use Cybertech\Estimator\Engine\Questionnaire;
use Cybertech\Estimator\Engine\RateCardRepository;
use Cybertech\Estimator\Lead\LeadPostType;
use Cybertech\Estimator\Lead\LeadRepository;
use Cybertech\Estimator\Security\InputSanitizer;

/**
 * Demo seeder.
 */
final class DemoSeeder {

	public const META_DEMO = '_ct_demo';

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( 'admin_post_ct_est_demo_seed', [ $this, 'handle_seed' ] );
		add_action( 'admin_post_ct_est_demo_remove', [ $this, 'handle_remove' ] );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'ct-estimator seed', [ $this, 'cli_seed' ] );
			\WP_CLI::add_command( 'ct-estimator unseed', [ $this, 'cli_unseed' ] );
		}
	}

	/**
	 * Admin button: seed.
	 */
	public function handle_seed(): void {
		$this->guard( 'ct_est_demo_seed' );
		$count = $this->seed();
		$this->back( 'seeded', $count );
	}

	/**
	 * Admin button: remove.
	 */
	public function handle_remove(): void {
		$this->guard( 'ct_est_demo_remove' );
		$count = $this->remove();
		$this->back( 'unseeded', $count );
	}

	/**
	 * WP-CLI: seed.
	 */
	public function cli_seed(): void {
		\WP_CLI::success( sprintf( '%d demo leads created.', $this->seed() ) );
	}

	/**
	 * WP-CLI: unseed.
	 */
	public function cli_unseed(): void {
		\WP_CLI::success( sprintf( '%d demo leads removed.', $this->remove() ) );
	}

	/**
	 * Create the demo leads. Removes previous demo data first so the button
	 * is idempotent. Emails/webhooks are suppressed during seeding.
	 *
	 * @return int Leads created.
	 */
	public function seed(): int {
		$this->remove();
		remove_all_actions( 'ct_est_lead_created' );
		// No sales emails / webhooks for fake leads.

		$card      = ( new RateCardRepository() )->load();
		$sanitizer = new InputSanitizer();
		$repo      = new LeadRepository();
		$count     = 0;

		foreach ( self::scenarios() as $i => $scenario ) {
			$validated = $sanitizer->validate( $scenario['answers'] + $scenario['contact'], InputSanitizer::MODE_SUBMIT );
			if ( $validated['errors'] ) {
				continue;
			}
			$result  = ( new PricingEngine( $card, $validated['answers'] ) )->estimate();
			$lead_id = $repo->create(
				$validated['answers'],
				$validated['contact'],
				$result,
				$card,
				[
					'created_at'  => time() - (int) ( $scenario['days_ago'] * DAY_IN_SECONDS ) - ( $i * 3600 ),
					'reveal_mode' => $scenario['mode'],
					'locale'      => get_locale(),
				]
			);
			if ( ! $lead_id ) {
				continue;
			}
			$facts = NarrativeService::facts( $result, $card, Questionnaire::resolve_labels( $validated['answers'] ), get_locale() );
			update_post_meta( $lead_id, LeadRepository::META_NARRATIVE, FallbackNarrative::build( $facts ) );
			update_post_meta( $lead_id, LeadRepository::META_AI_STATUS, 'fallback' );
			update_post_meta( $lead_id, LeadRepository::META_STATUS, $scenario['status'] );
			update_post_meta( $lead_id, self::META_DEMO, 1 );
			++$count;
		}//end foreach
		return $count;
	}

	/**
	 * Delete demo leads.
	 *
	 * @return int Leads removed.
	 */
	public function remove(): int {
		$ids = get_posts(
			[
				'post_type'        => LeadPostType::POST_TYPE,
				'post_status'      => 'any',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'suppress_filters' => true,
				'meta_key'         => self::META_DEMO, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => '1',             // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			]
		);
		foreach ( $ids as $id ) {
			wp_delete_post( (int) $id, true );
		}
		return count( $ids );
	}

	/**
	 * Ten scenarios: realistic Romanian businesses, all four lines, varied
	 * urgency/budget so the qualification scores spread across the bands.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function scenarios(): array {
		$c = static fn( string $name, string $email, string $company, string $phone = '' ): array => [
			'name'    => $name,
			'email'   => $email,
			'company' => $company,
			'phone'   => $phone,
			'consent' => true,
		];
		return [
			[
				'days_ago' => 41,
				'status'   => 'won',
				'mode'     => 'gated',
				'contact'  => $c( 'Andreea Marin', 'andreea.marin@vinaria-dealu.ro', 'Vinăria Dealu Mare', '+40 721 000 101' ),
				'answers'  => [
					'service_line'     => 'web',
					'web_platform'     => 'wordpress',
					'web_ecommerce'    => 'woocommerce',
					'web_templates'    => 9,
					'web_multilingual' => 'yes',
					'web_integrations' => 2,
					'web_migration'    => 'yes',
					'urgency'          => 'normal',
					'budget'           => '15k_40k',
					'maintenance'      => 'yes',
					'hosting'          => 'cybertech',
					'notes'            => 'We sell wine online to Romania and Germany. Current site is from 2016 and does not work on phones. We need the shop connected to our SmartBill invoicing.',
				],
			],
			[
				'days_ago' => 38,
				'status'   => 'lost',
				'mode'     => 'gated',
				'contact'  => $c( 'Bogdan Petrescu', 'bogdan@fitchain.ro', 'FitChain Gyms' ),
				'answers'  => [
					'service_line'     => 'mobile',
					'mobile_framework' => 'flutter',
					'mobile_platforms' => 'both',
					'mobile_offline'   => 'no',
					'mobile_auth'      => 'yes',
					'mobile_payments'  => 'yes',
					'mobile_push'      => 'yes',
					'mobile_backend'   => 'needed',
					'urgency'          => 'asap',
					'budget'           => 'under_5k',
					'maintenance'      => 'no',
					'hosting'          => 'undecided',
					'notes'            => 'Members should book classes and pay in the app.',
				],
			],
			[
				'days_ago' => 33,
				'status'   => 'proposal_sent',
				'mode'     => 'open',
				'contact'  => $c( 'Ioana Dumitrescu', 'ioana.d@dentalpro.ro', 'DentalPro Clinics', '+40 722 000 303' ),
				'answers'  => [
					'service_line'          => 'design',
					'design_deliverables'   => [ 'research', 'wireframes', 'hifi', 'prototype' ],
					'design_screens'        => 24,
					'design_brand'          => 'no',
					'design_testing_rounds' => 2,
					'urgency'               => 'normal',
					'budget'                => '5k_15k',
					'maintenance'           => 'no',
					'hosting'               => 'client',
					'notes'                 => 'Patient portal redesign for three clinics. We have a Figma file from 2021 to start from.',
				],
			],
			[
				'days_ago' => 29,
				'status'   => 'qualified',
				'mode'     => 'gated',
				'contact'  => $c( 'Radu Enache', 'radu.enache@logistica-express.ro', 'Logistica Express SRL', '+40 723 000 404' ),
				'answers'  => [
					'service_line' => 'ai',
					'ai_workflows' => 6,
					'ai_provider'  => 'openai',
					'ai_voice'     => 'yes',
					'ai_systems'   => 3,
					'ai_data'      => 'large',
					'ai_hitl'      => 'yes',
					'urgency'      => 'urgent',
					'budget'       => '40k_100k',
					'maintenance'  => 'yes',
					'hosting'      => 'cybertech',
					'notes'        => 'Dispatch team answers 400 calls a day about delivery status. We want a voice agent that checks our TMS and only escalates exceptions to a human.',
				],
			],
			[
				'days_ago' => 24,
				'status'   => 'contacted',
				'mode'     => 'gated',
				'contact'  => $c( 'Cristina Popa', 'cristina@atelierpopa.ro', 'Atelier Popa' ),
				'answers'  => [
					'service_line'     => 'web',
					'web_platform'     => 'wordpress',
					'web_ecommerce'    => 'none',
					'web_templates'    => 4,
					'web_multilingual' => 'no',
					'web_integrations' => 0,
					'web_migration'    => 'no',
					'urgency'          => 'flexible',
					'budget'           => 'under_5k',
					'maintenance'      => 'no',
					'hosting'          => 'undecided',
					'notes'            => '',
				],
			],
			[
				'days_ago' => 19,
				'status'   => 'qualified',
				'mode'     => 'band',
				'contact'  => $c( 'Mihai Stan', 'mihai.stan@agroterra.ro', 'AgroTerra Cooperativa', '+40 724 000 606' ),
				'answers'  => [
					'service_line'     => 'web',
					'web_platform'     => 'custom',
					'web_ecommerce'    => 'none',
					'web_templates'    => 12,
					'web_multilingual' => 'yes',
					'web_integrations' => 4,
					'web_migration'    => 'no',
					'urgency'          => 'normal',
					'budget'           => 'over_100k',
					'maintenance'      => 'yes',
					'hosting'          => 'cybertech',
					'notes'            => 'B2B portal for 300 member farms: orders, invoices, subsidy documents. Integrations with SAP Business One and ANAF e-Factura.',
				],
			],
			[
				'days_ago' => 14,
				'status'   => 'new',
				'mode'     => 'gated',
				'contact'  => $c( 'Elena Vasile', 'elena.vasile@brightkids.ro', 'BrightKids Academy' ),
				'answers'  => [
					'service_line'     => 'mobile',
					'mobile_framework' => 'react_native',
					'mobile_platforms' => 'both',
					'mobile_offline'   => 'yes',
					'mobile_auth'      => 'yes',
					'mobile_payments'  => 'no',
					'mobile_push'      => 'yes',
					'mobile_backend'   => 'existing',
					'urgency'          => 'normal',
					'budget'           => '15k_40k',
					'maintenance'      => 'yes',
					'hosting'          => 'client',
					'notes'            => 'Parents app for our 4 kindergartens: daily reports, photos, absence notices. Our backend is a Laravel API.',
				],
			],
			[
				'days_ago' => 9,
				'status'   => 'contacted',
				'mode'     => 'gated',
				'contact'  => $c( 'Alexandru Ilie', 'alex@nordicbuild.ro', 'Nordic Build Construct', '+40 725 000 808' ),
				'answers'  => [
					'service_line' => 'ai',
					'ai_workflows' => 2,
					'ai_provider'  => 'undecided',
					'ai_voice'     => 'no',
					'ai_systems'   => 2,
					'ai_data'      => 'small',
					'ai_hitl'      => 'no',
					'urgency'      => 'flexible',
					'budget'       => '5k_15k',
					'maintenance'  => 'no',
					'hosting'      => 'client',
					'notes'        => 'Automate supplier invoice intake from email into our accounting software.',
				],
			],
			[
				'days_ago' => 4,
				'status'   => 'new',
				'mode'     => 'gated',
				'contact'  => $c( 'Simona Tudor', 'simona.tudor@hotelcarpati.ro', 'Hotel Carpați Sinaia', '+40 726 000 909' ),
				'answers'  => [
					'service_line'          => 'design',
					'design_deliverables'   => [ 'wireframes', 'hifi', 'design_system' ],
					'design_screens'        => 40,
					'design_brand'          => 'yes',
					'design_testing_rounds' => 1,
					'urgency'               => 'urgent',
					'budget'                => '15k_40k',
					'maintenance'           => 'no',
					'hosting'               => 'undecided',
					'notes'                 => 'Rebrand plus booking flow redesign before the winter season.',
				],
			],
			[
				'days_ago' => 1,
				'status'   => 'new',
				'mode'     => 'gated',
				'contact'  => $c( 'George Nistor', 'george.nistor@pharmaline.ro', 'PharmaLine Distribution', '+40 727 001 010' ),
				'answers'  => [
					'service_line'     => 'web',
					'web_platform'     => 'wordpress',
					'web_ecommerce'    => 'magento',
					'web_templates'    => 15,
					'web_multilingual' => 'no',
					'web_integrations' => 3,
					'web_migration'    => 'yes',
					'urgency'          => 'normal',
					'budget'           => '40k_100k',
					'maintenance'      => 'yes',
					'hosting'          => 'cybertech',
					'notes'            => 'Migrating a 12,000-SKU B2B pharmacy catalogue from PrestaShop 1.6. Prices per customer group; ERP is WinMentor.',
				],
			],
		];
	}

	/**
	 * Nonce + capability for the admin buttons.
	 *
	 * @param string $action Nonce action.
	 */
	private function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'cybertech-estimator' ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Redirect back to the Diagnostics tab with a notice code.
	 *
	 * @param string $notice Notice code.
	 * @param int    $count  Count.
	 */
	private function back( string $notice, int $count ): void {
		$url = add_query_arg(
			[
				'post_type'    => LeadPostType::POST_TYPE,
				'page'         => 'ct-est-settings',
				'tab'          => 'diagnostics',
				'ct_est_demo'  => $notice,
				'ct_est_count' => $count,
			],
			admin_url( 'edit.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}
}
