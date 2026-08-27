<?php
/**
 * Sales notification (plain text).
 *
 * @package Cybertech\Estimator
 * @var array<string, mixed> $data
 */

declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';
$ct_est_result = $data['result'];

echo esc_html( __( 'New estimate request', 'cybertech-estimator' ) ), "\n";
echo esc_html( $data['service_label'] . ' · ' . $data['range'] ), "\n\n";
echo esc_html( __( 'Duration', 'cybertech-estimator' ) . ': ' . sprintf( /* translators: %d: weeks */ _n( '%d week', '%d weeks', $ct_est_result->weeks, 'cybertech-estimator' ), $ct_est_result->weeks ) ), "\n";
echo esc_html( __( 'Hours', 'cybertech-estimator' ) . ': ' . round( $ct_est_result->hours ) . ' h' ), "\n";
echo esc_html( __( 'Band', 'cybertech-estimator' ) . ': ' . $data['band_label'] ), "\n";
echo esc_html( __( 'Qualification', 'cybertech-estimator' ) . ': ' . $ct_est_result->qualification . '/100' ), "\n\n";
echo esc_html( __( 'Contact', 'cybertech-estimator' ) ), "\n";
foreach ( $data['contact'] as $ct_est_k => $ct_est_v ) {
	if ( '' !== $ct_est_v ) {
		echo esc_html( ucfirst( $ct_est_k ) . ': ' . $ct_est_v ), "\n";
	}
}
echo "\n", esc_html( __( 'Answers', 'cybertech-estimator' ) ), "\n";
foreach ( $data['labels'] as $ct_est_row ) {
	echo esc_html( $ct_est_row['label'] . ': ' . $ct_est_row['value'] ), "\n";
}
echo "\n", esc_html( __( 'Calculation breakdown', 'cybertech-estimator' ) ), "\n";
foreach ( $ct_est_result->breakdown as $ct_est_row ) {
	echo esc_html( sprintf( '%-32s %-24s %-16s %12s -> %12s', $ct_est_row['label'], $ct_est_row['input'], $ct_est_row['operation'], ct_est_email_number( (float) $ct_est_row['before'], (string) $ct_est_row['unit'] ), ct_est_email_number( (float) $ct_est_row['after'], (string) $ct_est_row['unit'] ) ) ), "\n";
}
echo "\n", esc_html( __( 'Open lead in WordPress', 'cybertech-estimator' ) . ': ' . $data['admin_url'] ), "\n";
echo esc_html( __( 'Client share link', 'cybertech-estimator' ) . ': ' . $data['share_url'] ), "\n";
