<?php
/**
 * Lead confirmation (HTML). Figures are only included when the reveal mode
 * showed them to the visitor (never in band mode).
 *
 * @package Cybertech\Estimator
 * @var array<string, mixed> $data
 */

declare(strict_types=1);

$ct_est_result  = $data['result'];
$ct_est_brand   = $data['brand'];
$ct_est_primary = $ct_est_brand['color_primary'];
$ct_est_figures = 'band' !== $data['reveal_mode'];
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_locale() ); ?>">
<head><meta charset="utf-8"><title><?php echo esc_html( $ct_est_brand['company'] ); ?></title></head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Poppins,Helvetica,Arial,sans-serif;color:#1f1f25;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:24px 0;">
<tr><td align="center">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border:1px solid #e9e9e9;">
	<tr><td style="background:#191919;padding:20px 28px;">
		<img src="<?php echo esc_url( $ct_est_brand['logo'] ); ?>" alt="<?php echo esc_attr( $ct_est_brand['logo_alt'] ); ?>" height="24" style="height:24px;display:block;">
	</td></tr>
	<tr><td style="padding:28px;">
		<h1 style="margin:0 0 12px;font-family:Montserrat,Helvetica,Arial,sans-serif;font-size:22px;line-height:1.3;"><?php echo esc_html( sprintf( /* translators: %s: first name */ __( 'Thank you, %s.', 'cybertech-estimator' ), trim( explode( ' ', $data['contact']['name'] )[0] ) ) ); ?></h1>
		<p style="margin:0 0 20px;line-height:1.6;"><?php echo esc_html( sprintf( /* translators: 1: company name, 2: service line */ __( 'Here is your preliminary estimate from %1$s for a %2$s project. It is an indication based on your answers, not a quote — we will follow up shortly to refine it together.', 'cybertech-estimator' ), $ct_est_brand['company'], $data['service_label'] ) ); ?></p>

		<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8f8f8;border-left:4px solid <?php echo esc_attr( $ct_est_primary ); ?>;margin:0 0 20px;">
			<tr><td style="padding:18px 20px;">
				<?php if ( $ct_est_figures ) : ?>
					<div style="font-size:12px;letter-spacing:2px;text-transform:uppercase;color:#717173;"><?php esc_html_e( 'Estimated range', 'cybertech-estimator' ); ?></div>
					<div style="font-family:Montserrat,Helvetica,Arial,sans-serif;font-size:26px;font-weight:800;color:<?php echo esc_attr( $ct_est_primary ); ?>;margin:2px 0 10px;"><?php echo esc_html( $data['range'] ); ?></div>
				<?php else : ?>
					<div style="font-size:12px;letter-spacing:2px;text-transform:uppercase;color:#717173;"><?php esc_html_e( 'Engagement size', 'cybertech-estimator' ); ?></div>
					<div style="font-family:Montserrat,Helvetica,Arial,sans-serif;font-size:22px;font-weight:800;color:<?php echo esc_attr( $ct_est_primary ); ?>;margin:2px 0 10px;"><?php echo esc_html( $data['band_label'] ); ?></div>
				<?php endif; ?>
				<div><?php echo esc_html( __( 'Estimated duration', 'cybertech-estimator' ) . ': ' . sprintf( /* translators: %d: weeks */ _n( '%d week', '%d weeks', $ct_est_result->weeks, 'cybertech-estimator' ), $ct_est_result->weeks ) ); ?></div>
			</td></tr>
		</table>

		<p style="margin:0 0 8px;line-height:1.6;"><?php esc_html_e( 'Your full estimate — timeline, team and assumptions — is available at this private link. Feel free to forward it to colleagues:', 'cybertech-estimator' ); ?></p>
		<p style="margin:0 0 24px;"><a href="<?php echo esc_url( $data['share_url'] ); ?>" style="display:inline-block;padding:12px 22px;background:<?php echo esc_attr( $ct_est_primary ); ?>;color:#fff;text-decoration:none;letter-spacing:1px;font-weight:600;"><?php esc_html_e( 'View your estimate', 'cybertech-estimator' ); ?></a></p>

		<p style="margin:0;font-size:13px;color:#717173;line-height:1.6;"><?php echo esc_html( $ct_est_brand['company'] ); ?> · <a href="mailto:<?php echo esc_attr( $ct_est_brand['contact_email'] ); ?>" style="color:<?php echo esc_attr( $ct_est_primary ); ?>;"><?php echo esc_html( $ct_est_brand['contact_email'] ); ?></a> · <?php echo esc_html( $ct_est_brand['contact_phone'] ); ?></p>
	</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
