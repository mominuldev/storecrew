<?php
/**
 * Registry of knowledge extractors.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Api\Registry;

use StoreCrew\Knowledge\ExtractorInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Every source of indexable content the installation knows about.
 *
 * Populated via `storecrew_register_extractors`.
 *
 * @extends Registry<ExtractorInterface>
 */
final class ExtractorRegistry extends Registry {

	protected function name(): string {
		return 'extractor';
	}

	/**
	 * @throws \InvalidArgumentException When the item is not an extractor.
	 */
	protected function validate( mixed $item ): void {
		if ( ! $item instanceof ExtractorInterface ) {
			throw new \InvalidArgumentException(
				sprintf( 'Expected %s, got %s.', ExtractorInterface::class, get_debug_type( $item ) )
			);
		}
	}

	/**
	 * Register an extractor, keyed by its own source type.
	 *
	 * @return static
	 */
	public function register( ExtractorInterface $extractor, string $owner = 'storecrew' ): self {
		return $this->add( $extractor->source_type(), $extractor, $owner );
	}

	/**
	 * Extractors that can actually run here.
	 *
	 * @return array<string, ExtractorInterface>
	 */
	public function available(): array {
		return array_filter(
			$this->items,
			static fn ( ExtractorInterface $e ): bool => $e->is_available()
		);
	}

	/**
	 * Total indexable objects across every available extractor.
	 *
	 * Used for the pre-flight estimate: a merchant should see how much will be
	 * embedded — and what it will cost — before indexing starts, not after
	 * (R-COST-01).
	 *
	 * @return array<string, int>
	 */
	public function counts(): array {
		$counts = array();

		foreach ( $this->available() as $type => $extractor ) {
			$counts[ (string) $type ] = $extractor->count();
		}

		return $counts;
	}
}
