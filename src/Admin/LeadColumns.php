<?php
/**
 * Leads list table: columns, sorting, filters, search, inline status
 * change and the "Mark as contacted" bulk action.
 *
 * Everything the sales person needs to triage is on the row — estimate,
 * score, status, share link — so a lead rarely needs opening. Figures are
 * read from the denormalised `_ct_*` meta that LeadRepository writes for
 * exactly this purpose; nothing is ever recomputed here.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Admin;

use Cybertech\Estimator\Engine\RateCard;
use Cybertech\Estimator\Engine\RateCardRepository;
use Cybertech\Estimator\Lead\LeadPostType;
use Cybertech\Estimator\Lead\LeadRepository;
use Cybertech\Estimator\Lead\ShareToken;
use Cybertech\Estimator\Support\Money;

/**
 * List-table behaviour for the lead CPT.
 */
final class LeadColumns {

	public const HANDLE       = 'ct-est-leads';
	public const AJAX_STATUS  = 'ct_est_set_status';
	public const NONCE_STATUS = 'ct_est_set_status';
	public const BULK_CONTACT = 'ct_mark_contacted';
	// GET keys of the filter dropdowns.
	public const FILTER_SERVICE = 'ct_service_line';
	public const FILTER_STATUS  = 'ct_status';

	/**
	 * Current rate card, loaded once per request (labels + thresholds).
	 *
	 * @var RateCard|null
	 */
	private ?RateCard $card = null;

	/**
	 * Hook registration.
	 */
	public function register(): void {
		$type = LeadPostType::POST_TYPE;
		add_filter( "manage_{$type}_posts_columns", [ $this, 'columns' ] );
		add_action( "manage_{$type}_posts_custom_column", [ $this, 'render_column' ], 10, 2 );
		add_filter( "manage_edit-{$type}_sortable_columns", [ $this, 'sortable_columns' ] );
		add_filter( "bulk_actions-edit-{$type}", [ $this, 'bulk_actions' ] );
		add_filter( "handle_bulk_actions-edit-{$type}", [ $this, 'handle_bulk_actions' ], 10, 3 );
		add_filter( 'post_row_actions', [ $this, 'row_actions' ], 10, 2 );
		add_action( 'restrict_manage_posts', [ $this, 'filters' ], 10, 2 );
		add_action( 'pre_get_posts', [ $this, 'apply_query_vars' ] );
		add_filter( 'posts_search', [ $this, 'search_contact_meta' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'admin_notices', [ $this, 'bulk_notice' ] );
		add_filter( 'removable_query_args', [ $this, 'removable_query_args' ] );
		add_action( 'wp_ajax_' . self::AJAX_STATUS, [ $this, 'ajax_set_status' ] );
	}

	/**
	 * Assets for both lead screens (list + edit). One bundle: the JS
	 * feature-detects by DOM so the edit screen shares the copy buttons.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( string $hook ): void {
		if ( ! in_array( $hook, [ 'edit.php', 'post.php' ], true ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || LeadPostType::POST_TYPE !== $screen->post_type ) {
			return;
		}
		wp_enqueue_style( self::HANDLE, CT_EST_URL . 'assets/css/leads.css', [ 'dashicons' ], CT_EST_VERSION );
		wp_enqueue_script(
			self::HANDLE,
			CT_EST_URL . 'assets/js/leads.js',
			[],
			CT_EST_VERSION,
			[
				'in_footer' => true,
				'strategy'  => 'defer',
			]
		);
		$config = [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'action'  => self::AJAX_STATUS,
			'nonce'   => wp_create_nonce( self::NONCE_STATUS ),
			'i18n'    => [
				'saving'     => __( 'Saving…', 'cybertech-estimator' ),
				'saved'      => __( 'Saved', 'cybertech-estimator' ),
				'error'      => __( 'Could not save', 'cybertech-estimator' ),
				'copied'     => __( 'Copied', 'cybertech-estimator' ),
				'copyFailed' => __( 'Copy failed', 'cybertech-estimator' ),
			],
		];
		// JSON, not wp_localize_script(): that helper stringifies every scalar.
		wp_add_inline_script( self::HANDLE, 'window.ctEstLeads = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	/**
	 * Column set. The checkbox stays for bulk actions; `date` is WP's own
	 * (already sortable) so it is kept rather than re-implemented.
	 *
	 * @param array<string, string> $columns Default columns.
	 * @return array<string, string>
	 */
	public function columns( array $columns ): array {
		return [
			'cb'          => $columns['cb'] ?? '<input type="checkbox" />',
			'title'       => __( 'Lead', 'cybertech-estimator' ),
			'ct_contact'  => __( 'Contact', 'cybertech-estimator' ),
			'ct_service'  => __( 'Service line', 'cybertech-estimator' ),
			'ct_estimate' => __( 'Estimate', 'cybertech-estimator' ),
			'ct_weeks'    => __( 'Weeks', 'cybertech-estimator' ),
			'ct_score'    => __( 'Score', 'cybertech-estimator' ),
			'ct_status'   => __( 'Status', 'cybertech-estimator' ),
			'ct_ai'       => __( 'AI', 'cybertech-estimator' ),
			'ct_share'    => __( 'Share', 'cybertech-estimator' ),
			'date'        => __( 'Date', 'cybertech-estimator' ),
		];
	}

	/**
	 * Sortable columns → orderby keys resolved in apply_query_vars().
	 *
	 * @param array<string, mixed> $columns Sortable columns.
	 * @return array<string, mixed>
	 */
	public function sortable_columns( array $columns ): array {
		$columns['ct_score']    = 'ct_score';
		$columns['ct_weeks']    = 'ct_weeks';
		$columns['ct_estimate'] = 'ct_price_low';
		$columns['date']        = 'date';
		return $columns;
	}

	/**
	 * Render one cell.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Lead id.
	 */
	public function render_column( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'ct_contact':
				$this->render_contact( $post_id );
				break;
			case 'ct_service':
				echo esc_html( $this->service_label( (string) get_post_meta( $post_id, LeadRepository::META_SERVICE, true ) ) );
				break;
			case 'ct_estimate':
				// Admins always see figures, whatever the visitor-facing reveal mode was.
				$result   = get_post_meta( $post_id, LeadRepository::META_RESULT, true );
				$currency = is_array( $result ) && ! empty( $result['currency'] ) ? (string) $result['currency'] : $this->card()->currency();
				echo esc_html(
					Money::range(
						(float) get_post_meta( $post_id, LeadRepository::META_LOW, true ),
						(float) get_post_meta( $post_id, LeadRepository::META_HIGH, true ),
						$currency
					)
				);
				break;
			case 'ct_weeks':
				echo esc_html( number_format_i18n( (int) get_post_meta( $post_id, LeadRepository::META_WEEKS, true ) ) );
				break;
			case 'ct_score':
				echo self::score_pill( (int) get_post_meta( $post_id, LeadRepository::META_SCORE, true ), $this->thresholds() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
				break;
			case 'ct_status':
				$this->render_status( $post_id );
				break;
			case 'ct_ai':
				echo self::ai_badge( (string) get_post_meta( $post_id, LeadRepository::META_AI_STATUS, true ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
				break;
			case 'ct_share':
				$this->render_share( $post_id );
				break;
		}//end switch
	}

	/**
	 * Name, mailto email, company — the three things a rep reaches for first.
	 *
	 * @param int $post_id Lead id.
	 */
	private function render_contact( int $post_id ): void {
		$contact = ( new LeadRepository() )->contact( $post_id );
		if ( get_post_meta( $post_id, LeadRepository::META_ANONYMISED, true ) ) {
			echo '<span class="ct-lead-muted">' . esc_html__( 'Anonymised', 'cybertech-estimator' ) . '</span>';
			return;
		}
		echo '<div class="ct-lead-contact">';
		if ( '' !== $contact['name'] ) {
			echo '<strong>' . esc_html( $contact['name'] ) . '</strong>';
		}
		if ( '' !== $contact['email'] ) {
			echo '<a href="' . esc_url( 'mailto:' . $contact['email'] ) . '">' . esc_html( $contact['email'] ) . '</a>';
		}
		if ( '' !== $contact['company'] ) {
			echo '<span class="ct-lead-muted">' . esc_html( $contact['company'] ) . '</span>';
		}
		echo '</div>';
	}

	/**
	 * Inline status select; leads.js saves it through admin-ajax and shows a tick.
	 *
	 * @param int $post_id Lead id.
	 */
	private function render_status( int $post_id ): void {
		$current = (string) get_post_meta( $post_id, LeadRepository::META_STATUS, true );
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			echo esc_html( LeadPostType::statuses()[ $current ] ?? $current );
			return;
		}
		?>
		<span class="ct-lead-status" data-id="<?php echo esc_attr( (string) $post_id ); ?>">
			<select class="ct-lead-status__select" aria-label="<?php esc_attr_e( 'Pipeline status', 'cybertech-estimator' ); ?>">
				<?php foreach ( LeadPostType::statuses() as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $current, $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<span class="ct-lead-status__state" aria-live="polite"></span>
		</span>
		<?php
	}

	/**
	 * Copy-link button + open icon; both disabled when the link would 404
	 * for the visitor (disabled or expired), with the reason shown instead.
	 *
	 * @param int $post_id Lead id.
	 */
	private function render_share( int $post_id ): void {
		$token = (string) get_post_meta( $post_id, ShareToken::META_TOKEN, true );
		$state = '' === $token ? 'missing' : ShareToken::state( $post_id );
		$url   = ShareToken::url( $token );
		$ok    = 'ok' === $state;
		$notes = [
			'disabled' => __( 'Disabled', 'cybertech-estimator' ),
			'expired'  => __( 'Expired', 'cybertech-estimator' ),
			'missing'  => __( 'No link', 'cybertech-estimator' ),
		];
		?>
		<span class="ct-lead-share ct-lead-share--<?php echo esc_attr( $state ); ?>">
			<button type="button" class="button button-small ct-copy" data-ct-copy="<?php echo esc_attr( $url ); ?>" <?php disabled( ! $ok ); ?>>
				<?php esc_html_e( 'Copy link', 'cybertech-estimator' ); ?>
			</button>
			<?php if ( $ok ) : ?>
				<a class="ct-lead-share__open" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener" title="<?php esc_attr_e( 'Open the public estimate page', 'cybertech-estimator' ); ?>">
					<span class="dashicons dashicons-external" aria-hidden="true"></span>
					<span class="screen-reader-text"><?php esc_html_e( 'Open', 'cybertech-estimator' ); ?></span>
				</a>
			<?php else : ?>
				<span class="ct-lead-share__note"><?php echo esc_html( $notes[ $state ] ?? $state ); ?></span>
			<?php endif; ?>
		</span>
		<?php
	}

	/**
	 * Colour-coded score pill. Static so the edit screen reuses it.
	 *
	 * @param int                $score      0–100.
	 * @param array<string, int> $thresholds ['green' => int, 'amber' => int].
	 * @return string Escaped HTML.
	 */
	public static function score_pill( int $score, array $thresholds ): string {
		$tone = 'red';
		if ( $score >= (int) ( $thresholds['green'] ?? 70 ) ) {
			$tone = 'green';
		} elseif ( $score >= (int) ( $thresholds['amber'] ?? 40 ) ) {
			$tone = 'amber';
		}
		return sprintf(
			'<span class="ct-pill ct-pill--%1$s" title="%3$s">%2$s</span>',
			esc_attr( $tone ),
			esc_html( (string) $score ),
			esc_attr__( 'Qualification score out of 100', 'cybertech-estimator' )
		);
	}

	/**
	 * AI status badge. Static so the edit screen reuses it.
	 *
	 * @param string $status pending | ai | fallback.
	 * @return string Escaped HTML.
	 */
	public static function ai_badge( string $status ): string {
		$map              = [
			'ai'       => [ 'green', __( 'Yes', 'cybertech-estimator' ) ],
			'fallback' => [ 'amber', __( 'Fallback', 'cybertech-estimator' ) ],
			'pending'  => [ 'grey', __( 'Pending', 'cybertech-estimator' ) ],
		];
		[ $tone, $label ] = $map[ $status ] ?? $map['pending'];
		return sprintf( '<span class="ct-pill ct-pill--%1$s">%2$s</span>', esc_attr( $tone ), esc_html( $label ) );
	}

	/**
	 * Quick Edit is removed: the CPT supports only a title that is derived
	 * from the snapshot, so there is nothing sensible to quick-edit.
	 *
	 * @param array<string, string> $actions Row actions.
	 * @param \WP_Post              $post    Post.
	 * @return array<string, string>
	 */
	public function row_actions( array $actions, \WP_Post $post ): array {
		if ( LeadPostType::POST_TYPE === $post->post_type ) {
			unset( $actions['inline hide-if-no-js'] );
		}
		return $actions;
	}

	/**
	 * Service line + status dropdowns above the table.
	 *
	 * @param string $post_type Current post type.
	 * @param string $which     'top' | 'bottom'.
	 */
	public function filters( string $post_type, string $which ): void {
		if ( LeadPostType::POST_TYPE !== $post_type || 'top' !== $which ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only GET filters on a listing screen.
		$service = isset( $_GET[ self::FILTER_SERVICE ] ) ? sanitize_key( wp_unslash( $_GET[ self::FILTER_SERVICE ] ) ) : '';
		$status  = isset( $_GET[ self::FILTER_STATUS ] ) ? sanitize_key( wp_unslash( $_GET[ self::FILTER_STATUS ] ) ) : '';
		// phpcs:enable
		$this->dropdown( self::FILTER_SERVICE, __( 'All service lines', 'cybertech-estimator' ), $this->service_lines(), $service );
		$this->dropdown( self::FILTER_STATUS, __( 'All statuses', 'cybertech-estimator' ), LeadPostType::statuses(), $status );
	}

	/**
	 * One filter `<select>`.
	 *
	 * @param string                $name    GET key.
	 * @param string                $all     "All …" label.
	 * @param array<string, string> $options value → label.
	 * @param string                $current Selected value.
	 */
	private function dropdown( string $name, string $all, array $options, string $current ): void {
		?>
		<label class="screen-reader-text" for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $all ); ?></label>
		<select name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $name ); ?>">
			<option value=""><?php echo esc_html( $all ); ?></option>
			<?php foreach ( $options as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Translate the list-table GET vars into the main query: filters become
	 * a meta_query, custom orderby keys become meta_value_num sorts.
	 *
	 * @param \WP_Query $query The query.
	 */
	public function apply_query_vars( \WP_Query $query ): void {
		if ( ! $this->is_lead_list_query( $query ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only GET filters on a listing screen.
		$service = isset( $_GET[ self::FILTER_SERVICE ] ) ? sanitize_key( wp_unslash( $_GET[ self::FILTER_SERVICE ] ) ) : '';
		$status  = isset( $_GET[ self::FILTER_STATUS ] ) ? sanitize_key( wp_unslash( $_GET[ self::FILTER_STATUS ] ) ) : '';
		// phpcs:enable

		$meta_query = (array) $query->get( 'meta_query', [] );
		if ( '' !== $service ) {
			$meta_query[] = [
				'key'   => LeadRepository::META_SERVICE,
				'value' => $service,
			];
		}
		if ( '' !== $status && isset( LeadPostType::statuses()[ $status ] ) ) {
			$meta_query[] = [
				'key'   => LeadRepository::META_STATUS,
				'value' => $status,
			];
		}
		if ( $meta_query ) {
			$query->set( 'meta_query', $meta_query ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- exact-match on small admin listing.
		}

		$sort_keys = [
			'ct_score'     => LeadRepository::META_SCORE,
			'ct_weeks'     => LeadRepository::META_WEEKS,
			'ct_price_low' => LeadRepository::META_LOW,
		];
		$orderby   = (string) $query->get( 'orderby' );
		if ( isset( $sort_keys[ $orderby ] ) ) {
			$query->set( 'meta_key', $sort_keys[ $orderby ] ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- sort key on an admin listing.
			$query->set( 'orderby', 'meta_value_num' );
		}
	}

	/**
	 * Replace WP's title/content search with title OR contact meta
	 * (name, email, company). Reps search by who wrote in, not by what the
	 * auto-generated title happens to contain.
	 *
	 * @param string    $search The SQL search clause (starts with " AND (").
	 * @param \WP_Query $query  The query.
	 */
	public function search_contact_meta( string $search, \WP_Query $query ): string {
		$term = trim( (string) $query->get( 's' ) );
		if ( '' === $term || ! $this->is_lead_list_query( $query ) ) {
			return $search;
		}
		global $wpdb;
		$like = '%' . $wpdb->esc_like( $term ) . '%';
		$keys = "'" . implode( "','", array_map( 'esc_sql', [ LeadRepository::META_NAME, LeadRepository::META_EMAIL, LeadRepository::META_COMPANY ] ) ) . "'";
		return $wpdb->prepare(
			" AND ( {$wpdb->posts}.post_title LIKE %s OR {$wpdb->posts}.ID IN ( SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ( {$keys} ) AND meta_value LIKE %s ) )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names + esc_sql()'d constant keys.
			$like,
			$like
		);
	}

	/**
	 * The admin list-table main query for our CPT (and nothing else).
	 *
	 * @param \WP_Query $query The query.
	 */
	private function is_lead_list_query( \WP_Query $query ): bool {
		return is_admin()
			&& $query->is_main_query()
			&& LeadPostType::POST_TYPE === $query->get( 'post_type' )
			&& ! wp_doing_ajax();
	}

	/**
	 * Bulk actions. "Mark as contacted" is the one transition done in bulk
	 * after a mailing; the rest happen one lead at a time. WP's bulk "Edit"
	 * goes: it only offers the title, which is derived from the snapshot.
	 *
	 * @param array<string, string> $actions Bulk actions.
	 * @return array<string, string>
	 */
	public function bulk_actions( array $actions ): array {
		unset( $actions['edit'] );
		$actions[ self::BULK_CONTACT ] = __( 'Mark as contacted', 'cybertech-estimator' );
		return $actions;
	}

	/**
	 * Apply the bulk action. WP has already verified the bulk nonce here.
	 *
	 * @param string     $redirect Redirect URL.
	 * @param string     $action   Action key.
	 * @param array<int> $ids      Selected ids.
	 */
	public function handle_bulk_actions( string $redirect, string $action, array $ids ): string {
		if ( self::BULK_CONTACT !== $action ) {
			return $redirect;
		}
		$done = 0;
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( LeadPostType::POST_TYPE !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}
			update_post_meta( $id, LeadRepository::META_STATUS, 'contacted' );
			++$done;
		}
		return add_query_arg( 'ct_contacted', $done, $redirect );
	}

	/**
	 * Result notice for the bulk action.
	 */
	public function bulk_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only counter set by our own redirect.
		if ( ! isset( $_GET['ct_contacted'] ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'edit-' . LeadPostType::POST_TYPE !== $screen->id ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only counter set by our own redirect.
		$count = (int) $_GET['ct_contacted'];
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: number of leads */
					_n( '%s lead marked as contacted.', '%s leads marked as contacted.', $count, 'cybertech-estimator' ),
					number_format_i18n( $count )
				)
			)
		);
	}

	/**
	 * Keep our one-shot flags out of the persisted URL.
	 *
	 * @param array<int, string> $args Removable args.
	 * @return array<int, string>
	 */
	public function removable_query_args( array $args ): array {
		$args[] = 'ct_contacted';
		$args[] = 'ct_webhook';
		return $args;
	}

	/**
	 * Ajax handler: change one lead's pipeline status from the list table.
	 */
	public function ajax_set_status(): void {
		check_ajax_referer( self::NONCE_STATUS, 'nonce' );
		$id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';

		if ( $id <= 0 || LeadPostType::POST_TYPE !== get_post_type( $id ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown lead.', 'cybertech-estimator' ) ], 404 );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			wp_send_json_error( [ 'message' => __( 'You are not allowed to edit this lead.', 'cybertech-estimator' ) ], 403 );
		}
		$statuses = LeadPostType::statuses();
		if ( ! isset( $statuses[ $status ] ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown status.', 'cybertech-estimator' ) ], 400 );
		}
		update_post_meta( $id, LeadRepository::META_STATUS, $status );
		wp_send_json_success(
			[
				'status' => $status,
				'label'  => $statuses[ $status ],
			]
		);
	}

	/**
	 * Current rate card (lazy, once per request).
	 */
	private function card(): RateCard {
		if ( null === $this->card ) {
			$this->card = ( new RateCardRepository() )->load();
		}
		return $this->card;
	}

	/**
	 * Score colour thresholds from the current card.
	 *
	 * @return array<string, int>
	 */
	private function thresholds(): array {
		$t = (array) $this->card()->get( 'qualification.thresholds', [] );
		return [
			'green' => (int) ( $t['green'] ?? 70 ),
			'amber' => (int) ( $t['amber'] ?? 40 ),
		];
	}

	/**
	 * Service line id → label from the current card.
	 *
	 * @return array<string, string>
	 */
	private function service_lines(): array {
		$out = [];
		foreach ( (array) $this->card()->get( 'service_lines', [] ) as $id => $line ) {
			$out[ (string) $id ] = (string) ( $line['label'] ?? $id );
		}
		return $out;
	}

	/**
	 * Label for a service line id; the id itself when the card no longer has it.
	 *
	 * @param string $id Service line id.
	 */
	private function service_label( string $id ): string {
		return $this->service_lines()[ $id ] ?? $id;
	}
}
