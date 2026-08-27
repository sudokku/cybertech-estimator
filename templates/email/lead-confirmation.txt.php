<?php
/**
 * Lead confirmation (plain text).
 *
 * @package Cybertech\Estimator
 * @var array<string, mixed> $data
 */

declare(strict_types=1);

$ct_est_result = $data['result'];
$ct_est_brand  = $data['brand'];

echo esc_html( sprintf( /* translators: %s: first name */ __( 'Thank you, %s.', 'cybertech-estimator' ), trim( explode( ' ', $data['contact']['name'] )[0] ) ) ), "\n\n";
echo esc_html( sprintf( /* translators: 1: company name, 2: service line */ __( 'Here is your preliminary estimate from %1$s for a %2$s project. It is an indication based on your answers, not a quote — we will follow up shortly to refine it together.', 'cybertech-estimator' ), $ct_est_brand['company'], $data['service_label'] ) ), "\n\n";
if ( 'band' !== $data['reveal_mode'] ) {
	echo esc_html( __( 'Estimated range', 'cybertech-estimator' ) . ': ' . $data['range'] ), "\n";
} else {
	echo esc_html( __( 'Engagement size', 'cybertech-estimator' ) . ': ' . $ct_est_result->band_label ), "\n";
}
echo esc_html( __( 'Estimated duration', 'cybertech-estimator' ) . ': ' . sprintf( /* translators: %d: weeks */ _n( '%d week', '%d weeks', $ct_est_result->weeks, 'cybertech-estimator' ), $ct_est_result->weeks ) ), "\n\n";
echo esc_html( __( 'View your estimate', 'cybertech-estimator' ) . ': ' . $data['share_url'] ), "\n\n";
echo esc_html( $ct_est_brand['company'] . ' · ' . $ct_est_brand['contact_email'] . ' · ' . $ct_est_brand['contact_phone'] ), "\n";
