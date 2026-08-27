<?php
/**
 * The `ct_estimate_lead` custom post type.
 *
 * Leads are private posts: never queryable on the frontend, visible in the
 * admin to anyone who can edit posts (the agency's PM/sales roles).
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Lead;

/**
 * Lead custom post type.
 */
final class LeadPostType {

	public const POST_TYPE = 'ct_estimate_lead';

	/**
	 * Pipeline statuses stored in `_ct_status` meta. Order = pipeline order.
	 *
	 * @return array<string, string>
	 */
	public static function statuses(): array {
		return [
			'new'           => __( 'New', 'cybertech-estimator' ),
			'contacted'     => __( 'Contacted', 'cybertech-estimator' ),
			'qualified'     => __( 'Qualified', 'cybertech-estimator' ),
			'proposal_sent' => __( 'Proposal sent', 'cybertech-estimator' ),
			'won'           => __( 'Won', 'cybertech-estimator' ),
			'lost'          => __( 'Lost', 'cybertech-estimator' ),
		];
	}

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( 'init', [ $this, 'register_post_type' ] );
	}

	/**
	 * Register the CPT. Public so the activator can call it before flushing rules.
	 */
	public function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			[
				'labels'              => [
					'name'               => _x( 'Estimates', 'post type general name', 'cybertech-estimator' ),
					'singular_name'      => _x( 'Estimate', 'post type singular name', 'cybertech-estimator' ),
					'menu_name'          => __( 'Estimator', 'cybertech-estimator' ),
					'all_items'          => __( 'Leads', 'cybertech-estimator' ),
					'edit_item'          => __( 'Lead', 'cybertech-estimator' ),
					'view_item'          => __( 'View lead', 'cybertech-estimator' ),
					'search_items'       => __( 'Search leads', 'cybertech-estimator' ),
					'not_found'          => __( 'No leads yet. Seed demo data from Estimator → Settings → Diagnostics.', 'cybertech-estimator' ),
					'not_found_in_trash' => __( 'No leads in the bin.', 'cybertech-estimator' ),
				],
				'description'         => __( 'Project estimate requests captured by the estimator wizard.', 'cybertech-estimator' ),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'menu_icon'           => 'dashicons-calculator',
				'menu_position'       => 26,
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
				'capabilities'        => [
					'create_posts' => 'do_not_allow',
				// Leads are created by the wizard/seeder only.
				],
				'supports'            => [ 'title' ],
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'delete_with_user'    => false,
			]
		);
	}
}
