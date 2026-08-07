<?php
/**
 * Which sources the merchant chose to index.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Knowledge;

use StoreCrew\Api\Registry\ExtractorRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * The second step of onboarding, made durable (FR-ADMIN-02).
 *
 * An extractor being *available* is a fact about the installation — WooCommerce
 * is active, so products can be read. Whether the merchant *wants* them read is
 * a different question, and one they have to be able to answer: a store whose
 * blog is a decade of unrelated posts pays to embed all of it and then gets it
 * back in customer answers.
 *
 * Absent configuration means everything available, so an install that never
 * visits the setup flow behaves exactly as it did before this class existed.
 * "Chosen" is therefore distinct from "everything is selected" — the former is
 * a recorded decision, and that is what the onboarding step completes on.
 *
 * @see docs/01-prd.md FR-ADMIN-02
 */
final class SourceSelection {

	public const OPTION = 'storecrew_sources';

	public function __construct(
		private readonly ExtractorRegistry $extractors,
	) {}

	/**
	 * Has the merchant recorded a decision at all?
	 */
	public function chosen(): bool {
		return is_array( get_option( self::OPTION, null ) );
	}

	/**
	 * Source types that will be indexed.
	 *
	 * Always intersected with what is actually available: a stored selection
	 * naming `product` on a site where WooCommerce has since been deactivated
	 * must not put the walker into a type it cannot read.
	 *
	 * @return list<string>
	 */
	public function enabled(): array {
		$available = array_map( 'strval', array_keys( $this->extractors->available() ) );

		$stored = get_option( self::OPTION, null );

		if ( ! is_array( $stored ) ) {
			return $available;
		}

		$stored = array_map( 'strval', $stored );

		return array_values( array_intersect( $available, $stored ) );
	}

	public function is_enabled( string $source_type ): bool {
		return in_array( $source_type, $this->enabled(), true );
	}

	/**
	 * Record a decision.
	 *
	 * Returns the types that were dropped, because the caller owes the merchant
	 * more than a stored option: content already in the index stays retrievable
	 * until something removes it, and an agent quoting a page the merchant just
	 * excluded is exactly the silent wrong answer this plugin's rules exist to
	 * prevent.
	 *
	 * @param list<string> $types Source types to index.
	 *
	 * @return array{selected: list<string>, removed: list<string>}
	 */
	public function save( array $types ): array {
		$available = array_map( 'strval', array_keys( $this->extractors->available() ) );

		$before = $this->enabled();

		$selected = array_values(
			array_intersect( $available, array_unique( array_map( 'sanitize_key', $types ) ) )
		);

		update_option( self::OPTION, $selected, true );

		return array(
			'selected' => $selected,
			'removed'  => array_values( array_diff( $before, $selected ) ),
		);
	}

	/**
	 * Every available source with its label, count, and whether it is selected.
	 *
	 * @return list<array{type: string, label: string, count: int, enabled: bool}>
	 */
	public function describe(): array {
		$enabled = $this->enabled();

		$rows = array();

		foreach ( $this->extractors->available() as $type => $extractor ) {
			$rows[] = array(
				'type'    => (string) $type,
				'label'   => $extractor->label(),
				'count'   => $extractor->count(),
				'enabled' => in_array( (string) $type, $enabled, true ),
			);
		}

		return $rows;
	}

	/**
	 * Indexable object counts, selected sources only.
	 *
	 * The estimate a merchant sees before starting a run has to match the run
	 * they are about to start, or the cost figure is fiction.
	 *
	 * @return array<string, int>
	 */
	public function counts(): array {
		$counts = array();

		foreach ( $this->enabled() as $type ) {
			$extractor = $this->extractors->get( $type );

			if ( $extractor instanceof ExtractorInterface ) {
				$counts[ $type ] = $extractor->count();
			}
		}

		return $counts;
	}
}
