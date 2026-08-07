<?php
/**
 * Pages and posts extractor.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Knowledge\Extractor;

use StoreCrew\Knowledge\ExtractedDocument;
use StoreCrew\Knowledge\ExtractorInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Indexes pages and posts — shipping policy, returns policy, FAQs, guides.
 *
 * This is what makes FR-SUPPORT-04 possible: the support agent answers policy
 * questions strictly from the merchant's own indexed documents rather than from
 * whatever the model believes a returns policy usually says.
 */
final class PostExtractor implements ExtractorInterface {

	public const SOURCE_TYPE = 'post';

	/**
	 * @param list<string> $post_types Post types to index.
	 */
	public function __construct(
		private readonly array $post_types = array( 'page', 'post' ),
	) {}

	public function source_type(): string {
		return self::SOURCE_TYPE;
	}

	public function label(): string {
		return __( 'Pages and posts', 'storecrew' );
	}

	public function is_available(): bool {
		return true;
	}

	public function count(): int {
		$query = new \WP_Query(
			array(
				'post_type'      => $this->post_types,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
			)
		);

		return (int) $query->found_posts;
	}

	public function ids( int $after_id = 0, int $limit = 50 ): array {
		$query = new \WP_Query(
			array(
				'post_type'      => $this->post_types,
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		$ids = array_values(
			array_filter(
				array_map( 'intval', $query->posts ),
				static fn ( int $id ): bool => $id > $after_id
			)
		);

		sort( $ids );

		return array_slice( $ids, 0, $limit );
	}

	public function extract( int $object_id ): ?ExtractedDocument {
		$post = get_post( $object_id );

		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return null;
		}

		if ( ! in_array( $post->post_type, $this->post_types, true ) ) {
			return null;
		}

		// A password-protected page is not public, so it must not become
		// retrievable through the chat widget.
		if ( '' !== $post->post_password ) {
			return null;
		}

		$content = trim(
			wp_strip_all_tags(
				strip_shortcodes( (string) $post->post_content )
			)
		);

		if ( '' === $content ) {
			return null;
		}

		return new ExtractedDocument(
			self::SOURCE_TYPE,
			$object_id,
			(string) $post->post_title,
			trim( $post->post_title . "\n\n" . $content ),
			(string) get_permalink( $post )
		);
	}
}
