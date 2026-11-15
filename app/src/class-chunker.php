<?php
/**
 * Text chunker.
 *
 * @package WP_AI_Smart_Search
 */

declare(strict_types=1);

namespace WP_AI_Smart_Search;

use InvalidArgumentException;

/**
 * Splits plain text into overlapping word-based chunks for embedding.
 *
 * Each chunk holds up to $size words and repeats the last $overlap words of
 * the previous chunk, so a sentence that crosses a chunk boundary still
 * appears whole in at least one chunk.
 *
 * Pure and stateless: no WordPress calls, so it can be tested on its own.
 *
 * @package WP_AI_Smart_Search
 */
final class Chunker {

	/**
	 * Default number of words per chunk.
	 *
	 * @var int
	 */
	public const DEFAULT_SIZE = 140;

	/**
	 * Default number of words repeated between consecutive chunks.
	 *
	 * @var int
	 */
	public const DEFAULT_OVERLAP = 20;

	/**
	 * Split text into overlapping chunks of words.
	 *
	 * Words are separated by any whitespace, including Unicode spaces such as
	 * non-breaking spaces. Chunks are joined back with single spaces.
	 *
	 * @param string $text    Plain text (HTML already stripped), UTF-8.
	 * @param int    $size    Maximum words per chunk. Must be at least 1.
	 * @param int    $overlap Words repeated from the previous chunk. Must be 0 or more and less than $size.
	 * @return string[] Chunks in order. Empty when the text has no words.
	 * @throws InvalidArgumentException If $size or $overlap is out of range, or the text is not valid UTF-8.
	 */
	public static function split(
		string $text,
		int $size = self::DEFAULT_SIZE,
		int $overlap = self::DEFAULT_OVERLAP
	): array {

		if ( $size < 1 ) {
			throw new InvalidArgumentException( 'Chunk size must be at least 1.' );
		}

		// An overlap >= size would make the step zero or negative and loop forever.
		if ( $overlap < 0 || $overlap >= $size ) {
			throw new InvalidArgumentException( 'Chunk overlap must be at least 0 and less than the chunk size.' );
		}

		$words = preg_split( '/[\s\p{Z}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );

		if ( $words === false ) {
			throw new InvalidArgumentException( 'Text is not valid UTF-8.' );
		}

		$count  = count( $words );
		$step   = $size - $overlap;
		$chunks = array();

		for ( $i = 0; $i < $count; $i += $step ) {
			$chunks[] = implode( ' ', array_slice( $words, $i, $size ) );

			// This chunk reached the last word; another would only repeat the overlap.
			if ( $i + $size >= $count ) {
				break;
			}
		}

		return $chunks;
	}
}
