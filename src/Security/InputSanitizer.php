<?php
/**
 * Validates visitor input against the Questionnaire schema. Unknown ids and
 * out-of-range option values are REJECTED, not coerced; numbers are clamped
 * to their declared min/max; free text is stripped and capped.
 *
 * @package Cybertech\Estimator
 */

declare(strict_types=1);

namespace Cybertech\Estimator\Security;

use Cybertech\Estimator\Engine\Questionnaire;

/**
 * Schema-driven input validation.
 */
final class InputSanitizer {

	public const MODE_PREVIEW = 'preview';
	public const MODE_SUBMIT  = 'submit';

	/**
	 * Validate raw answers.
	 *
	 * @param array<string, mixed> $raw  Raw request answers.
	 * @param string               $mode preview (partial allowed) | submit (all visible required + contact).
	 * @return array{answers: array<string, mixed>, contact: array<string, mixed>, errors: array<string, string>}
	 */
	public function validate( array $raw, string $mode ): array {
		$answers = [];
		$contact = [];
		$errors  = [];
		$schema  = Questionnaire::questions();

		foreach ( array_keys( $raw ) as $id ) {
			if ( ! isset( $schema[ (string) $id ] ) ) {
				$errors[ (string) $id ] = __( 'Unknown field.', 'cybertech-estimator' );
			}
		}

		// First pass: coerce values so show_if can be evaluated on clean data.
		foreach ( $schema as $id => $question ) {
			if ( ! array_key_exists( $id, $raw ) ) {
				continue;
			}
			$clean = $this->clean_value( $question, $raw[ $id ], $errors, $mode );
			if ( null === $clean ) {
				continue;
			}
			if ( Questionnaire::is_contact_question( $question ) ) {
				$contact[ $id ] = $clean;
			} else {
				$answers[ $id ] = $clean;
			}
		}

		// Second pass: drop answers for hidden questions, enforce required on submit.
		foreach ( $schema as $id => $question ) {
			$visible = Questionnaire::is_visible( $question, $answers );
			if ( ! $visible ) {
				unset( $answers[ $id ], $contact[ $id ], $errors[ $id ] );
				continue;
			}
			$is_contact = Questionnaire::is_contact_question( $question );
			$present    = $is_contact ? array_key_exists( $id, $contact ) : array_key_exists( $id, $answers );
			$must       = ! empty( $question['required'] ) && ( self::MODE_SUBMIT === $mode || 'service_line' === $id );
			if ( $must && ! $present && ! isset( $errors[ $id ] ) ) {
				$errors[ $id ] = __( 'This field is required.', 'cybertech-estimator' );
			}
		}

		if ( self::MODE_SUBMIT === $mode && empty( $contact['consent'] ) ) {
			$errors['consent'] = __( 'Please confirm you agree to be contacted about this estimate.', 'cybertech-estimator' );
		}

		return [
			'answers' => $answers,
			'contact' => $contact,
			'errors'  => $errors,
		];
	}

	/**
	 * Coerce one value; records an error and returns null when unusable.
	 *
	 * @param array<string, mixed>  $question Schema entry.
	 * @param mixed                 $value    Raw value.
	 * @param array<string, string> $errors   Error sink (by reference).
	 * @param string                $mode     preview | submit.
	 * @return mixed
	 */
	private function clean_value( array $question, mixed $value, array &$errors, string $mode ): mixed {
		$id = (string) $question['id'];
		switch ( $question['type'] ) {
			case Questionnaire::TYPE_SINGLE:
				if ( is_string( $value ) && isset( $question['options'][ $value ] ) ) {
					return $value;
				}
				$errors[ $id ] = __( 'Please choose one of the listed options.', 'cybertech-estimator' );
				return null;

			case Questionnaire::TYPE_MULTI:
				if ( ! is_array( $value ) ) {
					$errors[ $id ] = __( 'Please choose from the listed options.', 'cybertech-estimator' );
					return null;
				}
				$vals = [];
				foreach ( $value as $v ) {
					if ( ! is_string( $v ) || ! isset( $question['options'][ $v ] ) ) {
						$errors[ $id ] = __( 'Please choose from the listed options.', 'cybertech-estimator' );
						return null;
					}
					$vals[] = $v;
				}
				$vals = array_values( array_unique( $vals ) );
				if ( ! $vals ) {
					// An empty multi-select is only an error once the visitor submits.
					if ( ! empty( $question['required'] ) && self::MODE_SUBMIT === $mode ) {
						$errors[ $id ] = __( 'Please choose at least one option.', 'cybertech-estimator' );
					}
					return null;
				}
				return $vals;

			case Questionnaire::TYPE_NUMBER:
				if ( ! is_numeric( $value ) ) {
					$errors[ $id ] = __( 'Please enter a number.', 'cybertech-estimator' );
					return null;
				}
				// Clamp as float first: casting a huge float to int wraps negative and would collapse to the minimum.
				return (int) max( (float) $question['min'], min( (float) $question['max'], round( (float) $value ) ) );

			case Questionnaire::TYPE_TEXT:
				if ( ! is_scalar( $value ) ) {
					$errors[ $id ] = __( 'Invalid text.', 'cybertech-estimator' );
					return null;
				}
				$text = wp_strip_all_tags( (string) $value );
				$text = preg_replace( '/[ \t]+/', ' ', $text ) ?? '';
				$text = preg_replace( '/\R{3,}/', "\n\n", $text ) ?? '';
				$text = trim( $text );
				if ( '' === $text ) {
					return null;
				}
				return mb_substr( $text, 0, (int) ( $question['max'] ?? Questionnaire::NOTES_MAX ) );

			case Questionnaire::TYPE_EMAIL:
				$email = is_scalar( $value ) ? sanitize_email( (string) $value ) : '';
				if ( '' === $email || ! is_email( $email ) ) {
					$errors[ $id ] = __( 'Please enter a valid email address.', 'cybertech-estimator' );
					return null;
				}
				return mb_substr( $email, 0, (int) ( $question['max'] ?? 254 ) );

			case Questionnaire::TYPE_CHECKBOX:
				return in_array( $value, [ true, 1, '1', 'on', 'true', 'yes' ], true );
		}//end switch
		return null;
	}
}
