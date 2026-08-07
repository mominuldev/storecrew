<?php
/**
 * Keyset pagination over post-type ids.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Knowledge\Extractor;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches the next page of post ids *after* a cursor.
 *
 * This exists because the obvious implementation is wrong in a way that hides
 * itself. Asking WP_Query for the first N ids and then filtering to those above
 * the cursor returns the same first N every time: once the cursor passes that
 * page, every subsequent batch filters down to nothing, the walker concludes
 * the extractor is exhausted, and the index silently stops a few dozen objects
 * in. No error, no warning — just a catalogue that is mostly missing.
 *
 * The cursor has to reach the database. WP_Query has no "ID greater than"
 * argument, so the condition is added through `posts_where` and removed again
 * immediately, scoped by a marker so a concurrent query cannot pick it up.
 */
trait PagesPostTypeIds {

	/**
	 * Ids strictly greater than $after_id, ascending.
	 *
	 * @param list<string> $post_types Post types to walk.
	 *
	 * @return list<int>
	 */
	private function paged_post_ids( array $post_types, int $after_id, int $limit ): array {
		global $wpdb;

		$after_id = max( 0, $after_id );
		$limit    = max( 1, $limit );

		$where = static function ( string $sql, \WP_Query $query ) use ( $wpdb, $after_id ): string {
			if ( true !== $query->get( 'storecrew_keyset' ) ) {
				return $sql;
			}

			return $sql . $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $after_id );
		};

		add_filter( 'posts_where', $where, 10, 2 );

		try {
			$query = new \WP_Query(
				array(
					'post_type'              => $post_types,
					'post_status'            => 'publish',
					'posts_per_page'         => $limit,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'ignore_sticky_posts'    => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'storecrew_keyset'       => true,
				)
			);
		} finally {
			remove_filter( 'posts_where', $where, 10 );
		}

		return array_values( array_map( 'intval', $query->posts ) );
	}

	/**
	 * Total published objects of these post types.
	 *
	 * @param list<string> $post_types Post types to count.
	 */
	private function count_post_type( array $post_types ): int {
		$total = 0;

		foreach ( $post_types as $type ) {
			$counts = wp_count_posts( $type );

			if ( is_object( $counts ) && isset( $counts->publish ) ) {
				$total += (int) $counts->publish;
			}
		}

		return $total;
	}
}
