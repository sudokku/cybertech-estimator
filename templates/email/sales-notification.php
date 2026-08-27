<?php
/**
 * Sales notification (HTML). Variables: $data (see MailNotifier::lead_data()).
 *
 * @package Cybertech\Estimator
 * @var array<string, mixed> $data
 */

declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

$ct_est_result  = $data['result'];
$ct_est_brand   = $data['brand'];
$ct_est_primary = $ct_est_brand['color_primary'];
$ct_est_score   = (int) $ct_est_result->qualification;
$ct_est_colour  = $ct_est_score >= 70 ? '#1a7f37' : ( $ct_est_score >= 40 ? '#b7791f' : '#c0392b' );
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_locale() ); ?>">
<head><meta charset="utf-8"><title><?php echo esc_html( $data['service_label'] ); ?></title></head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Poppins,Helvetica,Arial,sans-serif;color:#1f1f25;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:24px 0;">
<tr><td align="center">
<table role="presentation" width="640" cellpadding="0" cellspacing="0" style="max-width:640px;background:#ffffff;border:1px solid #e9e9e9;">
	<tr><td style="background:#191919;padding:20px 28px;">
		<img src="<?php echo esc_url( $ct_est_brand['logo'] ); ?>" alt="<?php echo esc_attr( $ct_est_brand['logo_alt'] ); ?>" height="24" style="height:24px;display:block;">
	</td></tr>
	<tr><td style="padding:28px;">
		<p style="margin:0 0 6px;font-size:12px;letter-spacing:2px;text-transform:uppercase;color:<?php echo esc_attr( $ct_est_primary ); ?>;"><?php esc_html_e( 'New estimate request', 'cybertech-estimator' ); ?></p>
		<h1 style="margin:0 0 16px;font-family:Montserrat,Helvetica,Arial,sans-serif;font-size:22px;line-height:1.3;"><?php echo esc_html( $data['service_label'] ); ?> · <?php echo esc_html( $data['range'] ); ?></h1>

		<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 20px;">
			<tr>
				<td style="padding:0 24px 0 0;"><span style="font-size:12px;color:#717173;"><?php esc_html_e( 'Duration', 'cybertech-estimator' ); ?></span><br><strong><?php echo esc_html( sprintf( /* translators: %d: weeks */ _n( '%d week', '%d weeks', $ct_est_result->weeks, 'cybertech-estimator' ), $ct_est_result->weeks ) ); ?></strong></td>
				<td style="padding:0 24px 0 0;"><span style="font-size:12px;color:#717173;"><?php esc_html_e( 'Hours', 'cybertech-estimator' ); ?></span><br><strong><?php echo esc_html( (string) round( $ct_est_result->hours ) ); ?> h</strong></td>
				<td style="padding:0 24px 0 0;"><span style="font-size:12px;color:#717173;"><?php esc_html_e( 'Band', 'cybertech-estimator' ); ?></span><br><strong><?php echo esc_html( $data['band_label'] ); ?></strong></td>
				<td><span style="font-size:12px;color:#717173;"><?php esc_html_e( 'Qualification', 'cybertech-estimator' ); ?></span><br><strong style="color:<?php echo esc_attr( $ct_est_colour ); ?>;"><?php echo esc_html( (string) $ct_est_score ); ?>/100</strong></td>
			</tr>
		</table>

		<h2 style="font-size:14px;margin:24px 0 8px;letter-spacing:1px;text-transform:uppercase;"><?php esc_html_e( 'Contact', 'cybertech-estimator' ); ?></h2>
		<table role="presentation" cellpadding="4" cellspacing="0" style="font-size:14px;">
			<tr><td style="color:#717173;"><?php esc_html_e( 'Name', 'cybertech-estimator' ); ?></td><td><?php echo esc_html( $data['contact']['name'] ); ?></td></tr>
			<tr><td style="color:#717173;"><?php esc_html_e( 'Email', 'cybertech-estimator' ); ?></td><td><a href="mailto:<?php echo esc_attr( $data['contact']['email'] ); ?>"><?php echo esc_html( $data['contact']['email'] ); ?></a></td></tr>
			<?php
			if ( '' !== $data['contact']['company'] ) :
				?>
				<tr><td style="color:#717173;"><?php esc_html_e( 'Company', 'cybertech-estimator' ); ?></td><td><?php echo esc_html( $data['contact']['company'] ); ?></td></tr><?php endif; ?>
			<?php
			if ( '' !== $data['contact']['phone'] ) :
				?>
				<tr><td style="color:#717173;"><?php esc_html_e( 'Phone', 'cybertech-estimator' ); ?></td><td><?php echo esc_html( $data['contact']['phone'] ); ?></td></tr><?php endif; ?>
		</table>

		<h2 style="font-size:14px;margin:24px 0 8px;letter-spacing:1px;text-transform:uppercase;"><?php esc_html_e( 'Answers', 'cybertech-estimator' ); ?></h2>
		<table role="presentation" cellpadding="4" cellspacing="0" style="font-size:14px;width:100%;">
			<?php foreach ( $data['labels'] as $ct_est_row ) : ?>
			<tr><td style="color:#717173;width:45%;vertical-align:top;"><?php echo esc_html( $ct_est_row['label'] ); ?></td><td style="vertical-align:top;"><?php echo nl2br( esc_html( $ct_est_row['value'] ) ); ?></td></tr>
			<?php endforeach; ?>
		</table>

		<h2 style="font-size:14px;margin:24px 0 8px;letter-spacing:1px;text-transform:uppercase;"><?php esc_html_e( 'Calculation breakdown', 'cybertech-estimator' ); ?></h2>
		<table role="presentation" cellpadding="4" cellspacing="0" style="font-size:13px;width:100%;border-collapse:collapse;">
			<tr style="background:#f8f8f8;"><th align="left" style="border-bottom:1px solid #e9e9e9;"><?php esc_html_e( 'Step', 'cybertech-estimator' ); ?></th><th align="left" style="border-bottom:1px solid #e9e9e9;"><?php esc_html_e( 'Input', 'cybertech-estimator' ); ?></th><th align="left" style="border-bottom:1px solid #e9e9e9;"><?php esc_html_e( 'Operation', 'cybertech-estimator' ); ?></th><th align="right" style="border-bottom:1px solid #e9e9e9;"><?php esc_html_e( 'Before', 'cybertech-estimator' ); ?></th><th align="right" style="border-bottom:1px solid #e9e9e9;"><?php esc_html_e( 'After', 'cybertech-estimator' ); ?></th></tr>
			<?php foreach ( $ct_est_result->breakdown as $ct_est_row ) : ?>
			<tr><td style="border-bottom:1px solid #f0f0f0;"><?php echo esc_html( $ct_est_row['label'] ); ?></td><td style="border-bottom:1px solid #f0f0f0;color:#717173;"><?php echo esc_html( $ct_est_row['input'] ); ?></td><td style="border-bottom:1px solid #f0f0f0;"><?php echo esc_html( $ct_est_row['operation'] ); ?></td><td align="right" style="border-bottom:1px solid #f0f0f0;"><?php echo esc_html( ct_est_email_number( (float) $ct_est_row['before'], (string) $ct_est_row['unit'] ) ); ?></td><td align="right" style="border-bottom:1px solid #f0f0f0;"><?php echo esc_html( ct_est_email_number( (float) $ct_est_row['after'], (string) $ct_est_row['unit'] ) ); ?></td></tr>
			<?php endforeach; ?>
		</table>

		<p style="margin:28px 0 0;">
			<a href="<?php echo esc_url( $data['admin_url'] ); ?>" style="display:inline-block;padding:12px 22px;background:<?php echo esc_attr( $ct_est_primary ); ?>;color:#fff;text-decoration:none;letter-spacing:1px;font-weight:600;"><?php esc_html_e( 'Open lead in WordPress', 'cybertech-estimator' ); ?></a>
			&nbsp; <a href="<?php echo esc_url( $data['share_url'] ); ?>" style="color:<?php echo esc_attr( $ct_est_primary ); ?>;"><?php esc_html_e( 'Client share link', 'cybertech-estimator' ); ?></a>
		</p>
		<p style="margin:16px 0 0;font-size:12px;color:#717173;"><?php echo esc_html( sprintf( /* translators: %d: rate card version */ __( 'Priced with rate card v%d. The lead stores a full snapshot; later rate-card changes do not affect it.', 'cybertech-estimator' ), $ct_est_result->rate_card_version ) ); ?></p>
	</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
