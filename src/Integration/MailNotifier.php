<?php
/**
 * Email notifications: sales alert (full breakdown) and optional lead
 * confirmation (share link). HTML with a plain-text alternative.
 *
 * Sent synchronously on lead creation: an SMTP round-trip is short, and a
 * cron-dependent email is the first thing to go missing on a demo site.
 * The webhook, which may be slow or down, is the one that goes async.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Integration;

use Cybertech\Estimator\Brand;
use Cybertech\Estimator\Engine\EstimateResult;
use Cybertech\Estimator\Engine\RateCardDefaults;
use Cybertech\Estimator\Lead\LeadRepository;
use Cybertech\Estimator\Lead\ShareToken;
use Cybertech\Estimator\Support\Logger;
use Cybertech\Estimator\Support\Money;
use Cybertech\Estimator\Support\Settings;

/**
 * Mail notifications.
 */
final class MailNotifier {

	/**
	 * Plain-text body for the message currently being sent (phpmailer hook).
	 *
	 * @var string
	 */
	private string $alt_body = '';

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( 'ct_est_lead_created', [ $this, 'on_lead_created' ], 10, 1 );
	}

	/**
	 * Send both notifications for a new lead.
	 *
	 * @param int $lead_id Lead id.
	 */
	public function on_lead_created( int $lead_id ): void {
		$this->send_sales_notification( $lead_id );
		if ( Settings::get( 'notifications.send_confirmation' ) ) {
			$this->send_lead_confirmation( $lead_id );
		}
	}

	/**
	 * Sales alert to the agency.
	 *
	 * @param int $lead_id Lead id.
	 */
	public function send_sales_notification( int $lead_id ): bool {
		$data = $this->lead_data( $lead_id );
		if ( ! $data ) {
			return false;
		}
		$to = (string) Settings::get( 'notifications.sales_email' );
		if ( '' === $to || ! is_email( $to ) ) {
			$to = (string) get_option( 'admin_email' );
		}
		$subject = sprintf(
			/* translators: 1: service line label, 2: price range, 3: qualification score */
			__( 'New estimate: %1$s, %2$s (score %3$d)', 'cybertech-estimator' ),
			$data['service_label'],
			$data['range'],
			$data['result']->qualification
		);
		return $this->send( $to, $subject, 'sales-notification', $data, $lead_id );
	}

	/**
	 * Confirmation to the person who asked, with the share link.
	 *
	 * @param int $lead_id Lead id.
	 */
	public function send_lead_confirmation( int $lead_id ): bool {
		$data = $this->lead_data( $lead_id );
		if ( ! $data || '' === $data['contact']['email'] ) {
			return false;
		}
		$subject = sprintf(
			/* translators: %s: company name */
			__( 'Your project estimate from %s', 'cybertech-estimator' ),
			Brand::get( 'company' )
		);
		return $this->send( $data['contact']['email'], $subject, 'lead-confirmation', $data, $lead_id );
	}

	/**
	 * Render a template pair (HTML + .txt) and send.
	 *
	 * @param string               $to       Recipient.
	 * @param string               $subject  Subject.
	 * @param string               $template Template base name in templates/email/.
	 * @param array<string, mixed> $data     Template data.
	 * @param int                  $lead_id  Lead id (for logging).
	 */
	private function send( string $to, string $subject, string $template, array $data, int $lead_id ): bool {
		$html           = $this->render( $template . '.php', $data );
		$this->alt_body = $this->render( $template . '.txt.php', $data );

		$headers = [
			'Content-Type: text/html; charset=UTF-8',
			sprintf( 'From: %s <%s>', $this->from_name(), $this->from_email() ),
		];
		if ( 'sales-notification' === $template && '' !== $data['contact']['email'] ) {
			$headers[] = sprintf( 'Reply-To: %s <%s>', $data['contact']['name'], $data['contact']['email'] );
		}

		add_action( 'phpmailer_init', [ $this, 'attach_alt_body' ] );
		$sent = wp_mail( $to, $subject, $html, $headers );
		remove_action( 'phpmailer_init', [ $this, 'attach_alt_body' ] );

		Logger::log(
			'mail',
			$sent ? 'sent' : 'failed',
			[
				'template' => $template,
				'lead'     => $lead_id,
			]
		);
		return $sent;
	}

	/**
	 * Add the plain-text alternative to the outgoing message.
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $mailer PHPMailer instance.
	 */
	public function attach_alt_body( $mailer ): void {
		if ( '' !== $this->alt_body ) {
			$mailer->AltBody = $this->alt_body; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}
	}

	/**
	 * Everything the templates need, from the lead's snapshot.
	 *
	 * @param int $lead_id Lead id.
	 * @return array<string, mixed>|null
	 */
	public function lead_data( int $lead_id ): ?array {
		$repo   = new LeadRepository();
		$result = $repo->result( $lead_id );
		$card   = $repo->rate_card( $lead_id );
		if ( ! $result || ! $card ) {
			return null;
		}
		$labels = get_post_meta( $lead_id, LeadRepository::META_LABELS, true );
		return [
			'lead_id'       => $lead_id,
			'result'        => $result,
			'contact'       => $repo->contact( $lead_id ),
			'labels'        => is_array( $labels ) ? $labels : [],
			'service_label' => (string) $card->get( 'service_lines.' . $result->service_line . '.label', $result->service_line ),
			'range'         => Money::range( $result->price_low, $result->price_high, $result->currency ),
			'share_url'     => ShareToken::url( $repo->token( $lead_id ) ),
			'admin_url'     => admin_url( 'post.php?post=' . $lead_id . '&action=edit' ),
			'role_labels'   => RateCardDefaults::role_labels(),
			'reveal_mode'   => (string) get_post_meta( $lead_id, LeadRepository::META_MODE, true ),
			'brand'         => Brand::all(),
			'narrative'     => get_post_meta( $lead_id, LeadRepository::META_NARRATIVE, true ),
		];
	}

	/**
	 * Include a template with `$data` in scope; capture output.
	 *
	 * @param string               $file Template file name.
	 * @param array<string, mixed> $data Template data.
	 */
	private function render( string $file, array $data ): string {
		$path = CT_EST_DIR . 'templates/email/' . $file;
		if ( ! is_file( $path ) ) {
			return '';
		}
		ob_start();
		( static function () use ( $path, $data ): void {
			include $path;
		} )();
		return (string) ob_get_clean();
	}

	/**
	 * From name: the brand.
	 */
	private function from_name(): string {
		return Brand::get( 'company' );
	}

	/**
	 * From address: the site's own domain so SPF/DMARC stay happy; the
	 * brand contact address goes in Reply-To where relevant.
	 */
	private function from_email(): string {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = is_string( $host ) ? preg_replace( '/^www\./', '', $host ) : 'localhost';
		return 'estimator@' . $host;
	}
}
