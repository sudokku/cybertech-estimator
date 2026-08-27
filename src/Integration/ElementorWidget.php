<?php
/**
 * Elementor widget wrapping the estimator shortcode. Loaded only by
 * ElementorIntegration when Elementor is present.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Integration;

use Cybertech\Estimator\Engine\Questionnaire;
use Cybertech\Estimator\Engine\RateCardRepository;
use Cybertech\Estimator\Frontend\Assets;
use Elementor\Controls_Manager;
use Elementor\Widget_Base;

/**
 * Project Estimator widget.
 */
final class ElementorWidget extends Widget_Base {

	/**
	 * Widget slug.
	 */
	public function get_name(): string {
		return 'ct_estimator';
	}

	/**
	 * Title.
	 */
	public function get_title(): string {
		return __( 'Project Estimator', 'cybertech-estimator' );
	}

	/**
	 * Icon.
	 */
	public function get_icon(): string {
		return 'eicon-calculator';
	}

	/**
	 * Categories.
	 *
	 * @return array<int, string>
	 */
	public function get_categories(): array {
		return [ ElementorIntegration::CATEGORY ];
	}

	/**
	 * Keywords for the widget search.
	 *
	 * @return array<int, string>
	 */
	public function get_keywords(): array {
		return [ 'estimate', 'quote', 'calculator', 'cybertech', 'lead' ];
	}

	/**
	 * Styles Elementor enqueues for this widget (editor preview + front end).
	 *
	 * @return array<int, string>
	 */
	public function get_style_depends(): array {
		Assets::register();
		return [ Assets::HANDLE_TOKENS, Assets::HANDLE_WIZARD ];
	}

	/**
	 * Scripts Elementor enqueues for this widget (editor preview + front end).
	 *
	 * @return array<int, string>
	 */
	public function get_script_depends(): array {
		Assets::register();
		return [ Assets::HANDLE_WIZARD ];
	}

	/**
	 * Controls: title, service pre-filter, reveal-mode override, accent colour.
	 */
	protected function register_controls(): void {
		$this->start_controls_section( 'content', [ 'label' => __( 'Estimator', 'cybertech-estimator' ) ] );

		$this->add_control(
			'title',
			[
				'label'       => __( 'Title', 'cybertech-estimator' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => __( 'Estimate my project', 'cybertech-estimator' ),
				'label_block' => true,
			]
		);

		$services = [ '' => __( 'Let the visitor choose', 'cybertech-estimator' ) ];
		$card     = ( new RateCardRepository() )->load();
		foreach ( Questionnaire::SERVICE_LINES as $line ) {
			$services[ $line ] = (string) $card->get( "service_lines.{$line}.label", $line );
		}
		$this->add_control(
			'service',
			[
				'label'       => __( 'Service line pre-filter', 'cybertech-estimator' ),
				'description' => __( 'Pre-selects the service line and skips the first step.', 'cybertech-estimator' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => $services,
				'default'     => '',
			]
		);

		$this->add_control(
			'mode',
			[
				'label'       => __( 'Reveal mode', 'cybertech-estimator' ),
				'description' => __( 'Overrides Estimator → Settings → General for this placement.', 'cybertech-estimator' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => [
					''      => __( 'Use the plugin setting', 'cybertech-estimator' ),
					'open'  => __( 'Open — figures shown immediately', 'cybertech-estimator' ),
					'band'  => __( 'Band — engagement size only', 'cybertech-estimator' ),
					'gated' => __( 'Gated — figures after contact details', 'cybertech-estimator' ),
				],
				'default'     => '',
			]
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'style',
			[
				'label' => __( 'Style', 'cybertech-estimator' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);
		$this->add_control(
			'accent',
			[
				'label'     => __( 'Accent colour', 'cybertech-estimator' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [ '{{WRAPPER}} .ct-est' => '--ct-color-primary: {{VALUE}};' ],
			]
		);
		$this->end_controls_section();
	}

	/**
	 * Front-end and editor output: the shortcode is the single renderer.
	 */
	protected function render(): void {
		$settings = $this->get_settings_for_display();
		$atts     = [];
		foreach ( [ 'title', 'service', 'mode' ] as $key ) {
			if ( ! empty( $settings[ $key ] ) ) {
				$atts[] = sprintf( '%s="%s"', $key, esc_attr( (string) $settings[ $key ] ) );
			}
		}
		echo do_shortcode( '[cybertech_estimator ' . implode( ' ', $atts ) . ']' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- shortcode output is escaped at source.
	}
}
