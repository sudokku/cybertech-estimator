<?php
/**
 * Immutable result of one PricingEngine run.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Engine;

/**
 * Estimate value object.
 */
final class EstimateResult {

	/**
	 * Constructor.
	 *
	 * @param string                           $service_line      Service line id.
	 * @param float                            $hours             Final hours (after clamp).
	 * @param float                            $price             Point price before range.
	 * @param float                            $price_low         Rounded low end.
	 * @param float                            $price_high        Rounded high end.
	 * @param string                           $currency          Currency code.
	 * @param int                              $weeks             Duration in weeks.
	 * @param array<string, mixed>             $team              TeamComposer output.
	 * @param float                            $effective_rate    Share-weighted rate.
	 * @param string                           $band              Reveal band id.
	 * @param string                           $band_label        Reveal band label.
	 * @param int                              $qualification     0–100.
	 * @param array<string, int>               $qualification_parts Component scores.
	 * @param array<int, array<string, mixed>> $breakdown     Breakdown rows.
	 * @param array<string, mixed>             $answers           Pricing answers used.
	 * @param int                              $rate_card_version Card version used.
	 */
	public function __construct(
		public readonly string $service_line,
		public readonly float $hours,
		public readonly float $price,
		public readonly float $price_low,
		public readonly float $price_high,
		public readonly string $currency,
		public readonly int $weeks,
		public readonly array $team,
		public readonly float $effective_rate,
		public readonly string $band,
		public readonly string $band_label,
		public readonly int $qualification,
		public readonly array $qualification_parts,
		public readonly array $breakdown,
		public readonly array $answers,
		public readonly int $rate_card_version
	) {}

	/**
	 * Full serialisation (admin, snapshot, sandbox).
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return [
			'service_line'        => $this->service_line,
			'hours'               => $this->hours,
			'price'               => $this->price,
			'price_low'           => $this->price_low,
			'price_high'          => $this->price_high,
			'currency'            => $this->currency,
			'weeks'               => $this->weeks,
			'team'                => $this->team,
			'effective_rate'      => $this->effective_rate,
			'band'                => $this->band,
			'band_label'          => $this->band_label,
			'qualification'       => $this->qualification,
			'qualification_parts' => $this->qualification_parts,
			'breakdown'           => $this->breakdown,
			'answers'             => $this->answers,
			'rate_card_version'   => $this->rate_card_version,
		];
	}

	/**
	 * Rebuild from a stored array (lead snapshots).
	 *
	 * @param array<string, mixed> $data Serialised result.
	 */
	public static function from_array( array $data ): self {
		return new self(
			(string) $data['service_line'],
			(float) $data['hours'],
			(float) $data['price'],
			(float) $data['price_low'],
			(float) $data['price_high'],
			(string) $data['currency'],
			(int) $data['weeks'],
			(array) $data['team'],
			(float) $data['effective_rate'],
			(string) $data['band'],
			(string) $data['band_label'],
			(int) $data['qualification'],
			(array) $data['qualification_parts'],
			(array) $data['breakdown'],
			(array) $data['answers'],
			(int) $data['rate_card_version']
		);
	}
}
