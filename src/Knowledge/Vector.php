<?php
/**
 * Embedding storage format and similarity.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Knowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Packs embeddings for storage and scores them for retrieval.
 *
 * Vectors are stored as packed little-endian float32 in a LONGBLOB. A
 * 1536-dimension vector is 6,144 bytes packed against roughly 20 KB as a JSON
 * array — a 3x storage difference, and a far larger parsing difference given
 * this runs on every candidate of every query.
 *
 * Little-endian is pinned explicitly rather than using machine byte order, so a
 * database dumped on one architecture restores correctly on another.
 *
 * @see docs/04-database-schema.md § 5.2, § 6
 */
final class Vector {

	/**
	 * Pack a float vector into a storable blob.
	 *
	 * @param list<float> $vector Embedding.
	 */
	public static function encode( array $vector ): string {
		return pack( 'g*', ...array_map( 'floatval', $vector ) );
	}

	/**
	 * Unpack a stored blob back into floats.
	 *
	 * @return list<float>
	 */
	public static function decode( string $blob ): array {
		if ( '' === $blob ) {
			return array();
		}

		$unpacked = unpack( 'g*', $blob );

		return false === $unpacked ? array() : array_values( $unpacked );
	}

	/**
	 * Number of dimensions in a packed blob, without unpacking it.
	 */
	public static function dimensions( string $blob ): int {
		return intdiv( strlen( $blob ), 4 );
	}

	/**
	 * Cosine similarity between two vectors, in [-1, 1].
	 *
	 * Returns 0.0 for mismatched dimensions rather than throwing: a corpus can
	 * legitimately contain chunks embedded by a previous model mid-migration,
	 * and one stale row should not fail a customer's search.
	 *
	 * @param list<float> $a First vector.
	 * @param list<float> $b Second vector.
	 */
	public static function cosine( array $a, array $b ): float {
		$len = count( $a );

		if ( 0 === $len || $len !== count( $b ) ) {
			return 0.0;
		}

		$dot    = 0.0;
		$norm_a = 0.0;
		$norm_b = 0.0;

		for ( $i = 0; $i < $len; $i++ ) {
			$dot    += $a[ $i ] * $b[ $i ];
			$norm_a += $a[ $i ] * $a[ $i ];
			$norm_b += $b[ $i ] * $b[ $i ];
		}

		if ( 0.0 === $norm_a || 0.0 === $norm_b ) {
			return 0.0;
		}

		return $dot / ( sqrt( $norm_a ) * sqrt( $norm_b ) );
	}

	/**
	 * Cosine similarity where one side is already a packed blob.
	 *
	 * Saves decoding the query vector once per candidate.
	 *
	 * @param list<float> $query Query vector, already decoded.
	 */
	public static function cosine_against_blob( array $query, string $blob ): float {
		return self::cosine( $query, self::decode( $blob ) );
	}
}
