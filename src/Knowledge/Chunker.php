<?php
/**
 * Splits documents into embeddable chunks.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Knowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Chunks text on structural boundaries, with overlap.
 *
 * **The packing target and the embedding ceiling are separate numbers, and that
 * is deliberate.** Token counts here are estimated from character length — no
 * tokenizer ships with the plugin, and every provider tokenizes differently, so
 * the estimate can be wrong by a third or more on non-English text, code, or
 * long unbroken identifiers. If the packing target were also the hard limit, an
 * underestimate would produce a chunk the embedding API rejects, and a single
 * over-long product description would fail its whole batch. Packing to a lower
 * target leaves headroom for the estimate to be wrong; the ceiling is enforced
 * separately as a last-resort hard split.
 *
 * Splitting prefers paragraph boundaries, then sentence boundaries, and only
 * cuts mid-sentence when a single sentence exceeds the ceiling on its own.
 * A chunk that ends mid-clause retrieves badly — the embedding represents half
 * a thought.
 *
 * @see docs/01-prd.md FR-KB-02
 */
final class Chunker {

	/**
	 * Rough characters-per-token. An estimate, not a measurement — see the
	 * class docblock for why that is load-bearing.
	 */
	private const CHARS_PER_TOKEN = 4;

	/**
	 * @param int $target_tokens  What we aim to pack into a chunk.
	 * @param int $max_tokens     Hard ceiling; never exceeded.
	 * @param int $overlap_tokens Tokens repeated between adjacent chunks.
	 */
	public function __construct(
		private readonly int $target_tokens = 400,
		private readonly int $max_tokens = 700,
		private readonly int $overlap_tokens = 60,
	) {
		if ( $target_tokens >= $max_tokens ) {
			throw new \InvalidArgumentException(
				'The packing target must be below the embedding ceiling, so an underestimated token count still fits.'
			);
		}

		if ( $overlap_tokens >= $target_tokens ) {
			throw new \InvalidArgumentException( 'Overlap must be smaller than the packing target.' );
		}
	}

	/**
	 * Estimated token count for a string.
	 */
	public static function estimate_tokens( string $text ): int {
		return (int) ceil( mb_strlen( $text ) / self::CHARS_PER_TOKEN );
	}

	/**
	 * Split a document into chunks.
	 *
	 * @return list<array{content: string, tokens: int}>
	 */
	public function chunk( string $text ): array {
		$text = trim( preg_replace( '/[ \t]+/u', ' ', $text ) ?? $text );

		if ( '' === $text ) {
			return array();
		}

		$units = $this->split_into_units( $text );

		$chunks  = array();
		$current = '';

		foreach ( $units as $unit ) {
			$candidate = '' === $current ? $unit : $current . "\n\n" . $unit;

			if ( self::estimate_tokens( $candidate ) <= $this->target_tokens ) {
				$current = $candidate;

				continue;
			}

			if ( '' !== $current ) {
				$chunks[] = $current;
				$current  = $this->overlap_tail( $current );
				$current  = '' === $current ? $unit : $current . "\n\n" . $unit;

				// The overlap plus this unit may itself be over target; if so,
				// flush again rather than letting overlap silently inflate every
				// chunk past the ceiling.
				if ( self::estimate_tokens( $current ) > $this->max_tokens ) {
					foreach ( $this->hard_split( $current ) as $piece ) {
						$chunks[] = $piece;
					}

					$current = '';
				}

				continue;
			}

			// A single unit larger than the target on its own.
			foreach ( $this->hard_split( $unit ) as $piece ) {
				$chunks[] = $piece;
			}
		}

		if ( '' !== trim( $current ) ) {
			$chunks[] = $current;
		}

		$out = array();

		foreach ( $chunks as $chunk ) {
			$chunk = trim( $chunk );

			if ( '' === $chunk ) {
				continue;
			}

			$out[] = array(
				'content' => $chunk,
				'tokens'  => self::estimate_tokens( $chunk ),
			);
		}

		return $out;
	}

	/**
	 * Break text into the smallest units we are willing to keep together.
	 *
	 * Paragraphs first. A paragraph over the ceiling is broken into sentences,
	 * because keeping it whole would force a mid-word cut later.
	 *
	 * @return list<string>
	 */
	private function split_into_units( string $text ): array {
		$paragraphs = preg_split( '/\n\s*\n/u', $text ) ?: array( $text );

		$units = array();

		foreach ( $paragraphs as $paragraph ) {
			$paragraph = trim( $paragraph );

			if ( '' === $paragraph ) {
				continue;
			}

			if ( self::estimate_tokens( $paragraph ) <= $this->target_tokens ) {
				$units[] = $paragraph;

				continue;
			}

			foreach ( $this->split_sentences( $paragraph ) as $sentence ) {
				$units[] = $sentence;
			}
		}

		return $units;
	}

	/**
	 * Split on sentence-ending punctuation followed by whitespace.
	 *
	 * @return list<string>
	 */
	private function split_sentences( string $paragraph ): array {
		$parts = preg_split( '/(?<=[.!?])\s+/u', $paragraph ) ?: array( $paragraph );

		return array_values(
			array_filter(
				array_map( 'trim', $parts ),
				static fn ( string $s ): bool => '' !== $s
			)
		);
	}

	/**
	 * Last-resort split for text with no usable boundary.
	 *
	 * Cuts on the ceiling rather than the target, because this path only runs
	 * when nothing better exists and fewer cuts is the lesser harm.
	 *
	 * @return list<string>
	 */
	private function hard_split( string $text ): array {
		$max_chars = $this->max_tokens * self::CHARS_PER_TOKEN;

		$pieces = array();
		$length = mb_strlen( $text );

		for ( $offset = 0; $offset < $length; $offset += $max_chars ) {
			$piece = trim( mb_substr( $text, $offset, $max_chars ) );

			if ( '' !== $piece ) {
				$pieces[] = $piece;
			}
		}

		return $pieces;
	}

	/**
	 * The trailing slice of a chunk, repeated into the next one.
	 *
	 * Overlap exists so a fact split across a boundary is retrievable from
	 * either side. Without it, the sentence that answers a question can land
	 * half in one chunk and half in the next, and neither scores.
	 */
	private function overlap_tail( string $chunk ): string {
		if ( $this->overlap_tokens < 1 ) {
			return '';
		}

		$chars = $this->overlap_tokens * self::CHARS_PER_TOKEN;

		if ( mb_strlen( $chunk ) <= $chars ) {
			return $chunk;
		}

		$tail = mb_substr( $chunk, -$chars );

		// Start the overlap at a word boundary; a fragment beginning mid-word
		// adds noise to the embedding rather than context.
		$space = mb_strpos( $tail, ' ' );

		return false === $space ? $tail : trim( mb_substr( $tail, $space + 1 ) );
	}
}
