<?php
/**
 * Lead edit screen. The lead is an immutable snapshot of what was quoted,
 * so nearly everything here is read-only; the only editable controls are
 * the pipeline status, internal notes and the share-link switches.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Admin;

use Cybertech\Estimator\Engine\RateCardDefaults;
use Cybertech\Estimator\Engine\RateCardRepository;
use Cybertech\Estimator\Integration\WebhookDispatcher;
use Cybertech\Estimator\Lead\LeadPostType;
use Cybertech\Estimator\Lead\LeadRepository;
use Cybertech\Estimator\Lead\ShareToken;
use Cybertech\Estimator\Support\Money;
use Cybertech\Estimator\Support\Settings;

/**
 * Metaboxes + save handlers for the lead CPT.
 */
final class LeadMetaBoxes {

	public const META_NOTES     = '_ct_notes_internal';
	public const NONCE_META     = 'ct_est_lead_meta';
	public const FIELD_NONCE    = 'ct_est_lead_meta_nonce';
	public const ACTION_RESEND  = 'ct_est_resend_webhook';
	public const RATE_CARD_SLUG = 'ct-est-rate-card';

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes_' . LeadPostType::POST_TYPE, [ $this, 'add_boxes' ] );
		add_action( 'save_post_' . LeadPostType::POST_TYPE, [ $this, 'save' ] );
		add_filter( 'wp_insert_post_data', [ $this, 'keep_title' ], 10, 2 );
		add_action( 'admin_post_' . self::ACTION_RESEND, [ $this, 'handle_resend' ] );
		add_action( 'admin_notices', [ $this, 'notices' ] );
	}

	/**
	 * Register our boxes and drop the ones that make no sense for a snapshot.
	 */
	public function add_boxes(): void {
		$type = LeadPostType::POST_TYPE;
		remove_meta_box( 'slugdiv', $type, 'normal' );
		remove_meta_box( 'authordiv', $type, 'normal' );

		$boxes = [
			'estimate'  => [ __( 'Estimate', 'cybertech-estimator' ), 'normal', 'high' ],
			'answers'   => [ __( 'Answers', 'cybertech-estimator' ), 'normal', 'default' ],
			'breakdown' => [ __( 'Calculation breakdown', 'cybertech-estimator' ), 'normal', 'default' ],
			'narrative' => [ __( 'AI narrative', 'cybertech-estimator' ), 'normal', 'default' ],
			'webhook'   => [ __( 'Webhook log', 'cybertech-estimator' ), 'normal', 'low' ],
			'snapshot'  => [ __( 'Snapshot', 'cybertech-estimator' ), 'normal', 'low' ],
			'pipeline'  => [ __( 'Pipeline', 'cybertech-estimator' ), 'side', 'high' ],
			'contact'   => [ __( 'Contact & consent', 'cybertech-estimator' ), 'side', 'default' ],
			'share'     => [ __( 'Share link', 'cybertech-estimator' ), 'side', 'default' ],
		];
		foreach ( $boxes as $key => [ $title, $context, $priority ] ) {
			add_meta_box( 'ct-lead-' . $key, $title, [ $this, 'box_' . $key ], $type, $context, $priority );
		}
	}

	/**
	 * The title encodes company/service/range from the snapshot; a hand
	 * edit would make the list lie about what was quoted, so updates keep
	 * the stored title whatever the form posts.
	 *
	 * @param array<string, mixed> $data    Slashed post data about to be written.
	 * @param array<string, mixed> $postarr Raw post array.
	 * @return array<string, mixed>
	 */
	public function keep_title( array $data, array $postarr ): array {
		$id = (int) ( $postarr['ID'] ?? 0 );
		if ( $id > 0 && LeadPostType::POST_TYPE === ( $data['post_type'] ?? '' ) ) {
			$existing = get_post( $id );
			if ( $existing ) {
				$data['post_title'] = wp_slash( $existing->post_title );
			}
		}
		return $data;
	}

	/**
	 * Persist the editable controls on Update.
	 *
	 * @param int $post_id Lead id.
	 */
	public function save( int $post_id ): void {
		if ( ! isset( $_POST[ self::FIELD_NONCE ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::FIELD_NONCE ] ) ), self::NONCE_META ) ) {
			return;
		}
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$in = isset( $_POST['ct_est'] ) && is_array( $_POST['ct_est'] ) ? wp_unslash( $_POST['ct_est'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field sanitised below.

		$status = sanitize_key( (string) ( $in['status'] ?? '' ) );
		if ( isset( LeadPostType::statuses()[ $status ] ) ) {
			update_post_meta( $post_id, LeadRepository::META_STATUS, $status );
		}

		update_post_meta( $post_id, self::META_NOTES, sanitize_textarea_field( (string) ( $in['notes'] ?? '' ) ) );

		// The checkbox is absent when unticked, so the box's own presence flag says whether it was rendered.
		if ( ! empty( $in['share_rendered'] ) ) {
			update_post_meta( $post_id, ShareToken::META_ENABLED, empty( $in['share_enabled'] ) ? 0 : 1 );
			update_post_meta( $post_id, ShareToken::META_EXPIRES, self::expiry_from_date( (string) ( $in['share_expires'] ?? '' ) ) );
		}
	}

	/**
	 * Date input `Y-m-d` (site timezone, end of day) → timestamp; empty or malformed → 0 = never expires.
	 *
	 * @param string $date Date input value.
	 */
	private static function expiry_from_date( string $date ): int {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return 0;
		}
		$dt = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $date . ' 23:59:59', wp_timezone() );
		return $dt ? $dt->getTimestamp() : 0;
	}

	/**
	 * Admin-post handler: resend the webhook for one lead, synchronously, so the
	 * rep sees the outcome in the log on the next paint.
	 */
	public function handle_resend(): void {
		$id = isset( $_GET['lead'] ) ? absint( $_GET['lead'] ) : 0;
		if ( $id <= 0 || LeadPostType::POST_TYPE !== get_post_type( $id ) ) {
			wp_die( esc_html__( 'Unknown lead.', 'cybertech-estimator' ), '', [ 'response' => 404 ] );
		}
		check_admin_referer( self::ACTION_RESEND . '_' . $id );
		if ( ! current_user_can( 'edit_post', $id ) ) {
			wp_die( esc_html__( 'You are not allowed to edit this lead.', 'cybertech-estimator' ), '', [ 'response' => 403 ] );
		}

		$flag = 'nourl';
		if ( '' !== trim( (string) Settings::get( 'integrations.webhook_url' ) ) ) {
			$before = count( (array) get_post_meta( $id, WebhookDispatcher::META_LOG, true ) );
			( new WebhookDispatcher() )->dispatch( $id, 1 );
			$log  = (array) get_post_meta( $id, WebhookDispatcher::META_LOG, true );
			$last = end( $log );
			// dispatch() logs exactly one attempt; if nothing new appeared the payload could not be built.
			$flag = count( $log ) > $before && is_array( $last ) ? ( $last['ok'] ? 'sent' : 'failed' ) : 'nopayload';
		}
		wp_safe_redirect( add_query_arg( 'ct_webhook', $flag, get_edit_post_link( $id, 'raw' ) ) );
		exit;
	}

	/**
	 * Outcome notice after a resend.
	 */
	public function notices(): void {
		$screen = get_current_screen();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by our own redirect.
		if ( ! $screen || LeadPostType::POST_TYPE !== $screen->post_type || 'post' !== $screen->base || ! isset( $_GET['ct_webhook'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by our own redirect.
		$flag     = sanitize_key( wp_unslash( $_GET['ct_webhook'] ) );
		$messages = [
			'sent'      => [ 'success', __( 'Webhook delivered.', 'cybertech-estimator' ) ],
			'failed'    => [ 'error', __( 'Webhook attempt failed — see the log below. Retries are scheduled as usual.', 'cybertech-estimator' ) ],
			'nourl'     => [ 'warning', __( 'No webhook URL is configured in Estimator → Settings → Integrations.', 'cybertech-estimator' ) ],
			'nopayload' => [ 'error', __( 'This lead has no usable snapshot, so no payload could be built.', 'cybertech-estimator' ) ],
		];
		if ( ! isset( $messages[ $flag ] ) ) {
			return;
		}
		[ $tone, $text ] = $messages[ $flag ];
		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $tone ), esc_html( $text ) );
	}

	// ---- Boxes ----

	/**
	 * (a) Estimate: figures, score parts, team, rate-card badge.
	 *
	 * @param \WP_Post $post The lead.
	 */
	public function box_estimate( \WP_Post $post ): void {
		$result = ( new LeadRepository() )->result( $post->ID );
		if ( ! $result ) {
			$this->empty_note( __( 'No estimate snapshot on this lead.', 'cybertech-estimator' ) );
			return;
		}
		$current_card = ( new RateCardRepository() )->load();
		$thresholds   = (array) $current_card->get( 'qualification.thresholds', [] );
		$used_version = (int) get_post_meta( $post->ID, LeadRepository::META_RC_VER, true );
		$superseded   = $used_version !== $current_card->version();
		$service      = (string) $current_card->get( 'service_lines.' . $result->service_line . '.label', $result->service_line );
		$roles        = RateCardDefaults::role_labels();
		$parts_labels = [
			'budget'      => __( 'Budget fit', 'cybertech-estimator' ),
			'urgency'     => __( 'Urgency', 'cybertech-estimator' ),
			'scope'       => __( 'Scope size', 'cybertech-estimator' ),
			'notes'       => __( 'Description', 'cybertech-estimator' ),
			'maintenance' => __( 'Maintenance', 'cybertech-estimator' ),
			'hosting'     => __( 'Hosting', 'cybertech-estimator' ),
		];
		?>
		<div class="ct-lead-badges">
			<span class="ct-pill ct-pill--grey">
				<?php
				/* translators: %s: rate-card version number */
				echo esc_html( sprintf( __( 'Rate card v%s', 'cybertech-estimator' ), $used_version ) );
				?>
			</span>
			<?php if ( $superseded ) : ?>
				<a class="ct-pill ct-pill--amber" href="<?php echo esc_url( self::rate_card_url() ); ?>">
					<?php
					/* translators: %s: current rate-card version number */
					echo esc_html( sprintf( __( 'superseded — current is v%s', 'cybertech-estimator' ), $current_card->version() ) );
					?>
				</a>
			<?php endif; ?>
		</div>

		<dl class="ct-lead-figures">
			<div><dt><?php esc_html_e( 'Range', 'cybertech-estimator' ); ?></dt><dd class="ct-lead-figures__big"><?php echo esc_html( Money::range( $result->price_low, $result->price_high, $result->currency ) ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Service line', 'cybertech-estimator' ); ?></dt><dd><?php echo esc_html( $service ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Hours', 'cybertech-estimator' ); ?></dt><dd><?php echo esc_html( number_format_i18n( $result->hours, 1 ) ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Weeks', 'cybertech-estimator' ); ?></dt><dd><?php echo esc_html( number_format_i18n( $result->weeks ) ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Band', 'cybertech-estimator' ); ?></dt><dd><?php echo esc_html( $result->band_label ); ?> <span class="ct-lead-muted">(<?php echo esc_html( $result->band ); ?>)</span></dd></div>
			<div><dt><?php esc_html_e( 'Effective rate', 'cybertech-estimator' ); ?></dt><dd><?php echo esc_html( Money::format( $result->effective_rate, $result->currency ) ); ?>/h</dd></div>
			<div><dt><?php esc_html_e( 'Point price', 'cybertech-estimator' ); ?></dt><dd><?php echo esc_html( Money::format( $result->price, $result->currency ) ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Score', 'cybertech-estimator' ); ?></dt><dd><?php echo LeadColumns::score_pill( $result->qualification, $thresholds ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?></dd></div>
		</dl>

		<div class="ct-lead-cols">
			<div>
				<h4><?php esc_html_e( 'Score parts', 'cybertech-estimator' ); ?></h4>
				<table class="widefat striped ct-lead-table ct-lead-table--compact">
					<tbody>
					<?php foreach ( $result->qualification_parts as $key => $points ) : ?>
						<tr>
							<td><?php echo esc_html( $parts_labels[ $key ] ?? (string) $key ); ?></td>
							<td class="is-num"><?php echo esc_html( number_format_i18n( (int) $points ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					<tr class="ct-lead-table__total">
						<td><?php esc_html_e( 'Total', 'cybertech-estimator' ); ?></td>
						<td class="is-num"><?php echo esc_html( number_format_i18n( $result->qualification ) ); ?> / 100</td>
					</tr>
					</tbody>
				</table>
			</div>
			<div>
				<h4><?php esc_html_e( 'Team', 'cybertech-estimator' ); ?></h4>
				<table class="widefat striped ct-lead-table ct-lead-table--compact">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Role', 'cybertech-estimator' ); ?></th>
							<th class="is-num"><?php esc_html_e( 'Hours', 'cybertech-estimator' ); ?></th>
							<th class="is-num"><?php esc_html_e( 'Share', 'cybertech-estimator' ); ?></th>
							<th class="is-num"><?php esc_html_e( 'Rate', 'cybertech-estimator' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( (array) ( $result->team['roles'] ?? [] ) as $role => $r ) : ?>
						<tr>
							<td><?php echo esc_html( $roles[ $role ] ?? (string) $role ); ?></td>
							<td class="is-num"><?php echo esc_html( number_format_i18n( (float) ( $r['hours'] ?? 0 ), 1 ) ); ?></td>
							<td class="is-num"><?php echo esc_html( number_format_i18n( (float) ( $r['share'] ?? 0 ) * 100 ) ); ?>%</td>
							<td class="is-num"><?php echo esc_html( Money::format( (float) ( $r['rate'] ?? 0 ), $result->currency ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * (b) Contact & consent.
	 *
	 * @param \WP_Post $post The lead.
	 */
	public function box_contact( \WP_Post $post ): void {
		$id      = $post->ID;
		$contact = ( new LeadRepository() )->contact( $id );
		$consent = get_post_meta( $id, LeadRepository::META_CONSENT, true );
		$consent = is_array( $consent ) ? $consent : [];
		$yes     = __( 'Yes', 'cybertech-estimator' );
		$no      = __( 'No', 'cybertech-estimator' );
		$rows    = [
			__( 'Name', 'cybertech-estimator' )    => esc_html( $contact['name'] ),
			__( 'Email', 'cybertech-estimator' )   => '' !== $contact['email'] ? '<a href="' . esc_url( 'mailto:' . $contact['email'] ) . '">' . esc_html( $contact['email'] ) . '</a>' : '',
			__( 'Company', 'cybertech-estimator' ) => esc_html( $contact['company'] ),
			__( 'Phone', 'cybertech-estimator' )   => esc_html( $contact['phone'] ),
		];
		if ( get_post_meta( $id, LeadRepository::META_ANONYMISED, true ) ) {
			$rows[ __( 'Anonymised', 'cybertech-estimator' ) ] = esc_html( $yes );
		}
		$rows[ __( 'Consent', 'cybertech-estimator' ) ]         = esc_html( (string) ( $consent['text'] ?? '' ) );
		$rows[ __( 'Consent version', 'cybertech-estimator' ) ] = esc_html( (string) ( $consent['version'] ?? '' ) );
		$rows[ __( 'Consented at', 'cybertech-estimator' ) ]    = esc_html( self::date( (int) ( $consent['ts'] ?? 0 ) ) );
		$rows[ __( 'Locale', 'cybertech-estimator' ) ]          = esc_html( (string) get_post_meta( $id, LeadRepository::META_LOCALE, true ) );
		$rows[ __( 'Reveal mode', 'cybertech-estimator' ) ]     = esc_html( (string) get_post_meta( $id, LeadRepository::META_MODE, true ) );
		// Presence only: the hash itself is never useful to a human and stays out of the UI.
		$rows[ __( 'Hashed IP stored', 'cybertech-estimator' ) ] = esc_html( '' !== (string) get_post_meta( $id, LeadRepository::META_IP_HASH, true ) ? $yes : $no );

		$this->kv_table( $rows );
	}

	/**
	 * (c) Pipeline: status + internal notes (saved on Update).
	 *
	 * @param \WP_Post $post The lead.
	 */
	public function box_pipeline( \WP_Post $post ): void {
		wp_nonce_field( self::NONCE_META, self::FIELD_NONCE );
		$current = (string) get_post_meta( $post->ID, LeadRepository::META_STATUS, true );
		$notes   = (string) get_post_meta( $post->ID, self::META_NOTES, true );
		?>
		<p>
			<label for="ct_est_status"><strong><?php esc_html_e( 'Status', 'cybertech-estimator' ); ?></strong></label><br>
			<select name="ct_est[status]" id="ct_est_status" class="widefat">
				<?php foreach ( LeadPostType::statuses() as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $current, $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="ct_est_notes"><strong><?php esc_html_e( 'Internal notes', 'cybertech-estimator' ); ?></strong></label><br>
			<textarea name="ct_est[notes]" id="ct_est_notes" class="widefat" rows="6" placeholder="<?php esc_attr_e( 'Call notes, next steps… never shown to the visitor.', 'cybertech-estimator' ); ?>"><?php echo esc_textarea( $notes ); ?></textarea>
		</p>
		<?php
	}

	/**
	 * (d) Share link: URL, copy, enabled, expiry (saved on Update).
	 *
	 * @param \WP_Post $post The lead.
	 */
	public function box_share( \WP_Post $post ): void {
		$id               = $post->ID;
		$token            = (string) get_post_meta( $id, ShareToken::META_TOKEN, true );
		$state            = '' === $token ? 'missing' : ShareToken::state( $id );
		$url              = ShareToken::url( $token );
		$enabled          = (bool) get_post_meta( $id, ShareToken::META_ENABLED, true );
		$expires          = (int) get_post_meta( $id, ShareToken::META_EXPIRES, true );
		$labels           = [
			'ok'       => [ 'green', __( 'Active', 'cybertech-estimator' ) ],
			'disabled' => [ 'grey', __( 'Disabled', 'cybertech-estimator' ) ],
			'expired'  => [ 'amber', __( 'Expired', 'cybertech-estimator' ) ],
			'missing'  => [ 'red', __( 'No token', 'cybertech-estimator' ) ],
		];
		[ $tone, $label ] = $labels[ $state ] ?? $labels['missing'];
		?>
		<input type="hidden" name="ct_est[share_rendered]" value="1">
		<p class="ct-lead-share-state">
			<span class="ct-pill ct-pill--<?php echo esc_attr( $tone ); ?>"><?php echo esc_html( $label ); ?></span>
		</p>
		<?php if ( '' !== $token ) : ?>
			<p class="ct-lead-share-url">
				<input type="text" class="widefat code" readonly value="<?php echo esc_attr( $url ); ?>" onfocus="this.select()">
			</p>
			<p>
				<button type="button" class="button ct-copy" data-ct-copy="<?php echo esc_attr( $url ); ?>"><?php esc_html_e( 'Copy link', 'cybertech-estimator' ); ?></button>
				<?php if ( 'ok' === $state ) : ?>
					<a class="button" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open', 'cybertech-estimator' ); ?></a>
				<?php endif; ?>
			</p>
		<?php endif; ?>
		<p>
			<label>
				<input type="checkbox" name="ct_est[share_enabled]" value="1" <?php checked( $enabled ); ?>>
				<?php esc_html_e( 'Link enabled', 'cybertech-estimator' ); ?>
			</label>
		</p>
		<p>
			<label for="ct_est_share_expires"><?php esc_html_e( 'Expires on', 'cybertech-estimator' ); ?></label><br>
			<input type="date" name="ct_est[share_expires]" id="ct_est_share_expires" value="<?php echo esc_attr( $expires > 0 ? wp_date( 'Y-m-d', $expires ) : '' ); ?>">
			<span class="description"><?php esc_html_e( 'Empty = never expires.', 'cybertech-estimator' ); ?></span>
		</p>
		<?php
	}

	/**
	 * (e) Answers as resolved labels.
	 *
	 * @param \WP_Post $post The lead.
	 */
	public function box_answers( \WP_Post $post ): void {
		$labels = get_post_meta( $post->ID, LeadRepository::META_LABELS, true );
		if ( ! is_array( $labels ) || ! $labels ) {
			$this->empty_note( __( 'No answers stored.', 'cybertech-estimator' ) );
			return;
		}
		$rows = [];
		foreach ( $labels as $key => $row ) {
			$label = (string) ( $row['label'] ?? $key );
			$value = $row['value'] ?? '';
			$value = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
			// nl2br so a multi-line "notes" answer keeps its paragraphs.
			$rows[ $label ] = nl2br( esc_html( $value ) );
		}
		$this->kv_table( $rows );
	}

	/**
	 * (f) Calculation breakdown, each card-driven row deep-linking into the
	 * rate card with the same `#rc-<path>` anchors the Sandbox uses.
	 *
	 * @param \WP_Post $post The lead.
	 */
	public function box_breakdown( \WP_Post $post ): void {
		$result = ( new LeadRepository() )->result( $post->ID );
		if ( ! $result || ! $result->breakdown ) {
			$this->empty_note( __( 'No breakdown stored.', 'cybertech-estimator' ) );
			return;
		}
		$steps = self::step_labels();
		?>
		<p class="description"><?php esc_html_e( 'Every step the engine took when this lead was quoted. Values are from the snapshot, never recomputed. Source links open the current rate card — which may have changed since.', 'cybertech-estimator' ); ?></p>
		<div class="ct-lead-scroll">
		<table class="widefat striped ct-lead-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Step', 'cybertech-estimator' ); ?></th>
					<th><?php esc_html_e( 'Label', 'cybertech-estimator' ); ?></th>
					<th><?php esc_html_e( 'Input', 'cybertech-estimator' ); ?></th>
					<th><?php esc_html_e( 'Operation', 'cybertech-estimator' ); ?></th>
					<th class="is-num"><?php esc_html_e( 'Before', 'cybertech-estimator' ); ?></th>
					<th class="is-num"><?php esc_html_e( 'After', 'cybertech-estimator' ); ?></th>
					<th><?php esc_html_e( 'Source', 'cybertech-estimator' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $result->breakdown as $row ) : ?>
				<?php
				$step   = (string) ( $row['step'] ?? '' );
				$unit   = (string) ( $row['unit'] ?? '' );
				$source = (string) ( $row['source'] ?? '' );
				?>
				<tr>
					<td><?php echo esc_html( $steps[ $step ] ?? $step ); ?></td>
					<td><?php echo esc_html( (string) ( $row['label'] ?? '' ) ); ?></td>
					<td><?php echo esc_html( (string) ( $row['input'] ?? '' ) ); ?></td>
					<td><code><?php echo esc_html( (string) ( $row['operation'] ?? '' ) ); ?></code></td>
					<td class="is-num"><?php echo esc_html( self::num( $row['before'] ?? 0, $unit ) ); ?></td>
					<td class="is-num"><?php echo esc_html( self::num( $row['after'] ?? 0, $unit ) ); ?></td>
					<td>
						<?php if ( '' !== $source ) : ?>
							<a href="<?php echo esc_url( self::rate_card_url() . '#rc-' . str_replace( '.', '-', $source ) ); ?>" title="<?php esc_attr_e( 'Open this value in the rate card', 'cybertech-estimator' ); ?>"><code><?php echo esc_html( $source ); ?></code></a>
						<?php else : ?>
							<span class="ct-lead-muted">—</span>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
	}

	/**
	 * (g) AI narrative. The shape is Phase 4's; render the known keys and
	 * fall back to a JSON dump for anything else so nothing is hidden.
	 *
	 * @param \WP_Post $post The lead.
	 */
	public function box_narrative( \WP_Post $post ): void {
		$id        = $post->ID;
		$status    = (string) get_post_meta( $id, LeadRepository::META_AI_STATUS, true );
		$model     = (string) get_post_meta( $id, LeadRepository::META_AI_MODEL, true );
		$narrative = get_post_meta( $id, LeadRepository::META_NARRATIVE, true );
		?>
		<p class="ct-lead-badges">
			<?php echo LeadColumns::ai_badge( $status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
			<?php if ( '' !== $model ) : ?>
				<code><?php echo esc_html( $model ); ?></code>
			<?php endif; ?>
		</p>
		<?php
		if ( ! is_array( $narrative ) || ! $narrative ) {
			$this->empty_note( __( 'Not generated.', 'cybertech-estimator' ) );
			return;
		}
		$known = [
			'headline'    => __( 'Headline', 'cybertech-estimator' ),
			'summary'     => __( 'Summary', 'cybertech-estimator' ),
			'phases'      => __( 'Phases', 'cybertech-estimator' ),
			'assumptions' => __( 'Assumptions', 'cybertech-estimator' ),
			'risks'       => __( 'Risks', 'cybertech-estimator' ),
		];
		echo '<div class="ct-lead-narrative">';
		foreach ( $known as $key => $heading ) {
			if ( empty( $narrative[ $key ] ) ) {
				continue;
			}
			echo '<h4>' . esc_html( $heading ) . '</h4>';
			$this->narrative_value( $narrative[ $key ] );
		}
		$rest = array_diff_key( $narrative, $known );
		if ( $rest ) {
			echo '<details><summary>' . esc_html__( 'Other fields', 'cybertech-estimator' ) . '</summary><pre class="ct-lead-json">' . esc_html( (string) wp_json_encode( $rest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) . '</pre></details>';
		}
		echo '</div>';
	}

	/**
	 * A narrative value: string → paragraph; list → bullets (each item a
	 * string or a {title, text|description|...} object).
	 *
	 * @param mixed $value Narrative field.
	 */
	private function narrative_value( mixed $value ): void {
		if ( is_string( $value ) ) {
			echo '<p>' . wp_kses_post( $value ) . '</p>';
			return;
		}
		if ( ! is_array( $value ) ) {
			echo '<p>' . esc_html( (string) wp_json_encode( $value ) ) . '</p>';
			return;
		}
		echo '<ul>';
		foreach ( $value as $item ) {
			if ( is_array( $item ) ) {
				$title = (string) ( $item['title'] ?? $item['name'] ?? '' );
				$text  = (string) ( $item['text'] ?? $item['description'] ?? $item['summary'] ?? '' );
				if ( '' === $title && '' === $text ) {
					$text = (string) wp_json_encode( $item, JSON_UNESCAPED_UNICODE );
				}
				echo '<li>' . ( '' !== $title ? '<strong>' . wp_kses_post( $title ) . '</strong> ' : '' ) . wp_kses_post( $text ) . '</li>';
			} else {
				echo '<li>' . wp_kses_post( (string) $item ) . '</li>';
			}
		}
		echo '</ul>';
	}

	/**
	 * (h) Webhook attempts + resend.
	 *
	 * @param \WP_Post $post The lead.
	 */
	public function box_webhook( \WP_Post $post ): void {
		$id  = $post->ID;
		$log = get_post_meta( $id, WebhookDispatcher::META_LOG, true );
		$log = is_array( $log ) ? $log : [];
		$url = trim( (string) Settings::get( 'integrations.webhook_url' ) );

		if ( ! $log ) {
			$this->empty_note( __( 'No webhook attempts yet.', 'cybertech-estimator' ) );
		}
		if ( $log ) {
			?>
			<table class="widefat striped ct-lead-table ct-lead-table--compact">
				<thead>
					<tr>
						<th class="is-num">#</th>
						<th><?php esc_html_e( 'When', 'cybertech-estimator' ); ?></th>
						<th><?php esc_html_e( 'Result', 'cybertech-estimator' ); ?></th>
						<th class="is-num"><?php esc_html_e( 'HTTP', 'cybertech-estimator' ); ?></th>
						<th><?php esc_html_e( 'Error / body', 'cybertech-estimator' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $log as $row ) : ?>
					<?php
					$ok     = ! empty( $row['ok'] );
					$status = (int) ( $row['status'] ?? 0 );
					$detail = (string) ( '' !== (string) ( $row['error'] ?? '' ) ? $row['error'] : ( $row['body'] ?? '' ) );
					?>
					<tr>
						<td class="is-num"><?php echo esc_html( (string) (int) ( $row['attempt'] ?? 0 ) ); ?></td>
						<td><?php echo esc_html( self::date( (int) ( $row['ts'] ?? 0 ) ) ); ?></td>
						<td><span class="ct-pill ct-pill--<?php echo $ok ? 'green' : 'red'; ?>"><?php echo esc_html( $ok ? __( 'Delivered', 'cybertech-estimator' ) : __( 'Failed', 'cybertech-estimator' ) ); ?></span></td>
						<td class="is-num"><?php echo esc_html( $status > 0 ? (string) $status : '—' ); ?></td>
						<td><code class="ct-lead-code"><?php echo esc_html( mb_substr( $detail, 0, 200 ) ); ?></code></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php
		}//end if
		$resend = wp_nonce_url(
			add_query_arg(
				[
					'action' => self::ACTION_RESEND,
					'lead'   => $id,
				],
				admin_url( 'admin-post.php' )
			),
			self::ACTION_RESEND . '_' . $id
		);
		?>
		<p class="ct-lead-actions">
			<a class="button <?php echo '' === $url ? 'disabled' : ''; ?>" href="<?php echo esc_url( $resend ); ?>" <?php echo '' === $url ? 'aria-disabled="true" tabindex="-1"' : ''; ?>>
				<?php esc_html_e( 'Resend webhook', 'cybertech-estimator' ); ?>
			</a>
			<?php if ( '' === $url ) : ?>
				<span class="description"><?php esc_html_e( 'No webhook URL configured.', 'cybertech-estimator' ); ?></span>
			<?php else : ?>
				<span class="description"><?php echo esc_html( $url ); ?></span>
			<?php endif; ?>
		</p>
		<?php
	}

	/**
	 * (i) Raw snapshot, collapsed: the rate card and answers as quoted.
	 *
	 * @param \WP_Post $post The lead.
	 */
	public function box_snapshot( \WP_Post $post ): void {
		$sections = [
			__( 'Rate card snapshot', 'cybertech-estimator' ) => get_post_meta( $post->ID, LeadRepository::META_RATE_CARD, true ),
			__( 'Raw answers', 'cybertech-estimator' ) => get_post_meta( $post->ID, LeadRepository::META_ANSWERS, true ),
		];
		foreach ( $sections as $title => $data ) {
			?>
			<details class="ct-lead-details">
				<summary><?php echo esc_html( $title ); ?></summary>
				<pre class="ct-lead-json"><?php echo esc_html( (string) wp_json_encode( is_array( $data ) ? $data : [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?></pre>
			</details>
			<?php
		}
	}

	// ---- Helpers ----

	/**
	 * Two-column key/value table. Values arrive pre-escaped (some carry links).
	 *
	 * @param array<string, string> $rows label → escaped HTML.
	 */
	private function kv_table( array $rows ): void {
		echo '<table class="ct-lead-kv"><tbody>';
		foreach ( $rows as $label => $html ) {
			echo '<tr><th scope="row">' . esc_html( (string) $label ) . '</th><td>' . ( '' !== $html ? $html : '<span class="ct-lead-muted">—</span>' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- values escaped by the callers.
		}
		echo '</tbody></table>';
	}

	/**
	 * Muted placeholder line.
	 *
	 * @param string $text Text.
	 */
	private function empty_note( string $text ): void {
		echo '<p class="ct-lead-muted">' . esc_html( $text ) . '</p>';
	}

	/**
	 * Site-format date+time, or a dash for 0.
	 *
	 * @param int $ts Unix timestamp.
	 */
	private static function date( int $ts ): string {
		if ( $ts <= 0 ) {
			return '—';
		}
		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
	}

	/**
	 * Breakdown number with its unit ('h', 'pts', 'weeks', a currency code, '').
	 *
	 * @param mixed  $value Number.
	 * @param string $unit  Unit.
	 */
	private static function num( mixed $value, string $unit ): string {
		$value = (float) $value;
		if ( 3 === strlen( $unit ) && ctype_upper( $unit ) ) {
			return Money::format( $value, $unit );
		}
		$text = number_format_i18n( $value, floor( $value ) === $value ? 0 : 2 );
		return '' === $unit ? $text : $text . ' ' . $unit;
	}

	/**
	 * Human labels for breakdown step ids (same wording as the Sandbox).
	 *
	 * @return array<string, string>
	 */
	private static function step_labels(): array {
		return [
			'base_hours'    => __( '1 · Base hours', 'cybertech-estimator' ),
			'add_hours'     => __( '2 · Additive hours', 'cybertech-estimator' ),
			'multiplier'    => __( '3 · Multipliers', 'cybertech-estimator' ),
			'urgency'       => __( '4 · Urgency', 'cybertech-estimator' ),
			'contingency'   => __( '5 · Contingency', 'cybertech-estimator' ),
			'clamp'         => __( '6 · Minimum hours', 'cybertech-estimator' ),
			'team'          => __( '7 · Team allocation', 'cybertech-estimator' ),
			'rate'          => __( '7 · Effective rate', 'cybertech-estimator' ),
			'price'         => __( '7 · Price', 'cybertech-estimator' ),
			'add_price'     => __( '8 · Additive price', 'cybertech-estimator' ),
			'range'         => __( '9 · Range', 'cybertech-estimator' ),
			'weeks'         => __( '10 · Duration', 'cybertech-estimator' ),
			'band'          => __( '11 · Reveal band', 'cybertech-estimator' ),
			'qualification' => __( '12 · Qualification', 'cybertech-estimator' ),
		];
	}

	/**
	 * Rate card admin page URL.
	 */
	public static function rate_card_url(): string {
		return admin_url( 'edit.php?post_type=' . LeadPostType::POST_TYPE . '&page=' . self::RATE_CARD_SLUG );
	}
}
