<?php
/**
 * INT8 embedding quantizer.
 *
 * @package WP_AI_Smart_Search
 */

declare(strict_types=1);

namespace WP_AI_Smart_Search;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are logged, never printed as HTML.

use InvalidArgumentException;

/**
 * Converts float embedding vectors to and from compact INT8 blobs.
 *
 * Uses symmetric scalar quantization: every component is divided by one
 * per-vector scale factor (the largest absolute value divided by 127),
 * rounded, and stored as a signed byte. A 384-dimension vector becomes a
 * 384-byte blob, about 4x smaller than 32-bit floats.
 *
 * The scale must be stored alongside the blob (ai_chunks.embedding_scale).
 * Cosine similarity is unaffected by the scale, but dequantize() needs it to
 * reconstruct approximate float values.
 *
 * @package WP_AI_Smart_Search
 */
final class Quantizer {

	/**
	 * Largest magnitude of a quantized component.
	 *
	 * -128 is not used, so the range is symmetric around zero.
	 *
	 * @var int
	 */
	private const INT8_MAX = 127;

	/**
	 * Quantize a float embedding vector to signed 8-bit integers.
	 *
	 * @param array    $vector     Embedding vector. Every component must be a finite int or float.
	 * @param int|null $dimensions Expected number of components, or null to skip the check.
	 * @return array {
	 *     Quantized embedding.
	 *
	 *     @type string $blob  Binary string of packed signed 8-bit integers, one byte per dimension.
	 *     @type float  $scale Scale factor used to map the integers back to floats.
	 * }
	 * @throws InvalidArgumentException If the vector is empty, has the wrong number of
	 *                                  dimensions, contains a non-numeric, NaN or infinite
	 *                                  component, or is all zeros.
	 */
	public static function quantize( array $vector, ?int $dimensions = null ): array {

		self::validate( $vector, $dimensions );

		$max = max( array_map( 'abs', $vector ) );

		if ( $max <= 0.0 ) {
			throw new InvalidArgumentException( 'Invalid zero embedding.' );
		}

		$scale = $max / self::INT8_MAX;

		$quantized = array();

		foreach ( $vector as $value ) {
			// Can't exceed ±127 since $scale comes from the largest value; clamp guards float error.
			$quantized[] = max(
				-self::INT8_MAX,
				min( self::INT8_MAX, (int) round( $value / $scale ) )
			);
		}

		return array(
			'blob'  => pack( 'c*', ...$quantized ),
			'scale' => $scale,
		);
	}

	/**
	 * Turn a quantized embedding back into a float vector.
	 *
	 * The result is an approximation: precision lost to rounding during
	 * quantization cannot be recovered.
	 *
	 * @param string       $blob  Binary string of packed signed 8-bit integers, as returned by quantize().
	 * @param float|string $scale Scale factor returned by quantize(). Numeric strings are accepted
	 *                            because $wpdb returns column values as strings.
	 * @return float[] Reconstructed embedding vector, indexed from 0.
	 * @throws InvalidArgumentException If the scale is not a finite, positive number.
	 */
	public static function dequantize( string $blob, float|string $scale ): array {

		if ( is_string( $scale ) && ! is_numeric( $scale ) ) {
			throw new InvalidArgumentException( 'Embedding scale must be numeric.' );
		}

		$scale = (float) $scale;

		if ( ! is_finite( $scale ) || $scale <= 0.0 ) {
			throw new InvalidArgumentException( 'Embedding scale must be a finite, positive number.' );
		}

		$values = unpack( 'c*', $blob );

		if ( false === $values ) {
			return array();
		}

		return array_map(
			fn( int $value ): float => $value * $scale,
			array_values( $values )
		);
	}

	/**
	 * Format a scale for storage without losing precision.
	 *
	 * Insert the result with the '%s' format. Do not use '%f': $wpdb formats
	 * it with only 6 decimal places, which distorts small scales and turns
	 * very small ones into 0. Nine significant digits cover a MySQL FLOAT.
	 *
	 * @param float $scale Scale factor returned by quantize().
	 * @return string Scale as a numeric string, e.g. "0.000680316393".
	 */
	public static function format_scale( float $scale ): string {
		return sprintf( '%.9g', $scale );
	}

	/**
	 * Check that a vector can be quantized.
	 *
	 * @param array    $vector     Embedding vector.
	 * @param int|null $dimensions Expected number of components, or null to skip the check.
	 * @return void
	 * @throws InvalidArgumentException If the vector is invalid.
	 */
	private static function validate( array $vector, ?int $dimensions ): void {

		if ( array() === $vector ) {
			throw new InvalidArgumentException( 'Embedding vector is empty.' );
		}

		if ( null !== $dimensions && count( $vector ) !== $dimensions ) {
			throw new InvalidArgumentException(
				sprintf( 'Expected %d embedding dimensions, got %d.', $dimensions, count( $vector ) )
			);
		}

		foreach ( $vector as $index => $value ) {
			if ( ! is_int( $value ) && ! is_float( $value ) ) {
				throw new InvalidArgumentException(
					sprintf( 'Embedding component %s is not a number (%s).', $index, get_debug_type( $value ) )
				);
			}

			if ( ! is_finite( (float) $value ) ) {
				throw new InvalidArgumentException(
					sprintf( 'Embedding component %s is NaN or infinite.', $index )
				);
			}
		}
	}
}
