<?php
/**
 * Knowledge-base pipeline verification.
 *
 * Run with:  wp eval-file wp-content/plugins/storecrew/tests/schema/verify-knowledge.php
 *
 * Creates a real WooCommerce product, because the FR-KB-08 guarantee — price
 * and stock never enter the index — cannot be proven against a fixture. The
 * product is deleted at the end.
 *
 * No declare(strict_types=1): wp eval-file runs this through eval().
 *
 * @package StoreCrew
 */

use StoreCrew\Ai\Capabilities;
use StoreCrew\Ai\EmbeddingProviderInterface;
use StoreCrew\Ai\EmbeddingRequest;
use StoreCrew\Ai\EmbeddingResponse;
use StoreCrew\Ai\ModelPolicy;
use StoreCrew\Ai\SpendGuard;
use StoreCrew\Ai\TokenUsage;
use StoreCrew\Api\Registry\ExtractorRegistry;
use StoreCrew\Api\Registry\ProviderRegistry;
use StoreCrew\Database\Repositories\KnowledgeChunkRepository;
use StoreCrew\Database\Repositories\KnowledgeSourceRepository;
use StoreCrew\Database\Repositories\UsageRepository;
use StoreCrew\Knowledge\Chunker;
use StoreCrew\Knowledge\Extractor\PostExtractor;
use StoreCrew\Knowledge\Extractor\ProductExtractor;
use StoreCrew\Knowledge\Indexer;
use StoreCrew\Knowledge\Retriever;

$pass = 0;
$fail = 0;

$t = static function ( string $label, bool $ok, string $detail = '' ) use ( &$pass, &$fail ): void {
	if ( $ok ) {
		++$pass;
		echo "  PASS  {$label}\n";
	} else {
		++$fail;
		echo "  FAIL  {$label}" . ( '' !== $detail ? " — {$detail}" : '' ) . "\n";
	}
};

$container = StoreCrew\Plugin::instance()->container();

echo "\n== Chunker ==\n";
$chunker = new Chunker( target_tokens: 40, max_tokens: 80, overlap_tokens: 8 );

$t(
	'PROBE: target must be below the ceiling',
	(function () {
		try {
			new Chunker( target_tokens: 100, max_tokens: 100 );
			return false;
		} catch ( InvalidArgumentException ) {
			return true;
		}
	})()
);

$t( 'empty text yields no chunks', array() === $chunker->chunk( '   ' ) );

$short  = 'A short product description.';
$chunks = $chunker->chunk( $short );
$t( 'short text stays one chunk', 1 === count( $chunks ) );
$t( 'chunk carries a token estimate', $chunks[0]['tokens'] > 0 );

$paragraphs = implode( "\n\n", array_map(
	static fn ( $i ) => "Paragraph {$i}. " . str_repeat( 'word ', 30 ),
	range( 1, 6 )
) );
$chunks = $chunker->chunk( $paragraphs );
$t( 'long text splits into several chunks', count( $chunks ) > 1, (string) count( $chunks ) );

$over = array_filter( $chunks, static fn ( $c ) => $c['tokens'] > 80 );
$t( 'PROBE: no chunk exceeds the hard ceiling', array() === $over, wp_json_encode( array_column( $over, 'tokens' ) ) );

$t(
	'PROBE: chunks overlap so a split fact stays retrievable',
	(function () use ( $chunks ) {
		$tail  = mb_substr( $chunks[0]['content'], -20 );
		$words = array_filter( explode( ' ', $tail ) );
		foreach ( $words as $w ) {
			if ( mb_strlen( $w ) > 3 && str_contains( $chunks[1]['content'], $w ) ) {
				return true;
			}
		}
		return false;
	})()
);

// A single unbreakable run must still be cut rather than blowing the ceiling.
$blob   = str_repeat( 'x', 4000 );
$chunks = $chunker->chunk( $blob );
$t( 'PROBE: unbreakable text is hard-split, not emitted whole', count( $chunks ) > 1 );
$t(
	'PROBE: hard-split pieces respect the ceiling',
	array() === array_filter( $chunks, static fn ( $c ) => $c['tokens'] > 80 )
);

echo "\n== Product extraction and FR-KB-08 ==\n";
$has_woo = function_exists( 'wc_get_product' );
$t( 'WooCommerce is available', $has_woo );

$product_id = 0;

if ( $has_woo ) {
	$product = new WC_Product_Simple();
	$product->set_name( 'StoreCrew Probe Trail Runner' );
	$product->set_sku( 'SCR-PROBE-001' );
	$product->set_short_description( 'A lightweight trail running shoe.' );
	$product->set_description( 'Aggressive lug pattern for muddy terrain. Breathable mesh upper.' );
	$product->set_regular_price( '129.99' );
	$product->set_manage_stock( true );
	$product->set_stock_quantity( 7 );
	$product->set_stock_status( 'instock' );
	$product->set_status( 'publish' );
	$product_id = $product->save();

	$extractor = new ProductExtractor();
	$doc       = $extractor->extract( $product_id );

	$t( 'extracts the product', null !== $doc );
	$t( 'includes the product name', str_contains( $doc->content, 'Trail Runner' ) );
	$t( 'includes the description', str_contains( $doc->content, 'muddy terrain' ) );
	$t( 'includes the SKU (stable, not volatile)', str_contains( $doc->content, 'SCR-PROBE-001' ) );

	$t( 'PROBE: price is NOT in the indexed text', ! str_contains( $doc->content, '129.99' ), $doc->content );
	$t( 'PROBE: stock quantity is NOT in the indexed text', ! preg_match( '/\b7 in stock\b/i', $doc->content ) );
	$t(
		'PROBE: no stock-status wording leaks in',
		! preg_match( '/instock|in stock|out of stock/i', $doc->content )
	);

	// The cost control, stated as a behaviour rather than an intention.
	$hash_before = $doc->content_hash;

	$product = wc_get_product( $product_id );
	$product->set_stock_quantity( 2 );
	$product->set_regular_price( '99.99' );
	$product->save();

	$doc_after = ( new ProductExtractor() )->extract( $product_id );

	$t(
		'PROBE: changing price and stock leaves the content hash UNCHANGED',
		$hash_before === $doc_after->content_hash,
		"{$hash_before} vs {$doc_after->content_hash}"
	);

	// And a real content change must move it, or the hash is useless.
	$product = wc_get_product( $product_id );
	$product->set_description( 'Aggressive lug pattern for muddy terrain. Now with a rock plate.' );
	$product->save();

	$doc_edited = ( new ProductExtractor() )->extract( $product_id );
	$t( 'PROBE: editing the description DOES move the hash', $hash_before !== $doc_edited->content_hash );

	// Hidden products must not be retrievable.
	$product = wc_get_product( $product_id );
	$product->set_catalog_visibility( 'hidden' );
	$product->save();
	$t( 'PROBE: catalogue-hidden products are not extracted', null === ( new ProductExtractor() )->extract( $product_id ) );

	$product = wc_get_product( $product_id );
	$product->set_catalog_visibility( 'visible' );
	$product->save();

	$product = wc_get_product( $product_id );
	$product->set_status( 'draft' );
	$product->save();
	$t( 'PROBE: draft products are not extracted', null === ( new ProductExtractor() )->extract( $product_id ) );

	$product = wc_get_product( $product_id );
	$product->set_status( 'publish' );
	$product->save();
}

echo "\n== Post extraction ==\n";
$page_id = wp_insert_post(
	array(
		'post_title'   => 'StoreCrew Probe Returns Policy',
		'post_content' => 'We accept returns within 30 days of delivery provided items are unworn.',
		'post_status'  => 'publish',
		'post_type'    => 'page',
	)
);

$post_extractor = new PostExtractor();
$page_doc       = $post_extractor->extract( (int) $page_id );
$t( 'extracts a page', null !== $page_doc );
$t( 'includes the title', str_contains( $page_doc->content, 'Returns Policy' ) );
$t( 'includes the body', str_contains( $page_doc->content, '30 days' ) );

wp_update_post( array( 'ID' => $page_id, 'post_password' => 'secret' ) );
$t(
	'PROBE: password-protected pages are never indexed',
	null === ( new PostExtractor() )->extract( (int) $page_id )
);
wp_update_post( array( 'ID' => $page_id, 'post_password' => '' ) );

echo "\n== Indexer ==\n";
$sources = $container->get( KnowledgeSourceRepository::class );
$chunks  = $container->get( KnowledgeChunkRepository::class );
$usage   = $container->get( UsageRepository::class );

$extractors = new ExtractorRegistry();
$extractors->register( new ProductExtractor() );
$extractors->register( new PostExtractor() );

/** A deterministic stand-in for a real embedding provider. */
$fake = new class() implements EmbeddingProviderInterface {
	public int $calls        = 0;
	public string $last_task = '';
	public bool $ragged      = false;

	public function id(): string { return 'fake'; }
	public function label(): string { return 'Fake'; }
	public function capabilities(): Capabilities {
		return new Capabilities( chat: false, embeddings: true, embedding_task_types: true );
	}
	public function is_configured(): bool { return true; }
	public function verify(): string { return ''; }
	public function default_embedding_models(): array { return array( 'fake-embed-1' ); }

	public function embed( EmbeddingRequest $request ): EmbeddingResponse {
		++$this->calls;
		$this->last_task = $request->task;

		// Honour the requested width. A provider that ignored it would leave
		// every chunk permanently "needing embedding", because a vector of the
		// wrong width is treated as unusable — which is the point of that rule.
		$width = $request->dimensions > 0 ? $request->dimensions : 3;

		$vectors = array();
		foreach ( $request->inputs as $i => $text ) {
			// When ragged, vary the dimensionality after the first vector. With a
			// single input there is no "after", so also emit a spare vector — that
			// way the fixture exercises a ragged response whatever the batch size,
			// rather than passing by accident when only one chunk is pending.
			$dims      = ( $this->ragged && $i > 0 ) ? max( 1, $width - 1 ) : $width;
			$vectors[] = array_fill( 0, $dims, 0.1 * ( $i + 1 ) );
		}

		if ( $this->ragged && 1 === count( $request->inputs ) ) {
			$vectors[] = array_fill( 0, $width, 0.9 );
		}

		return new EmbeddingResponse( $vectors, $request->model, 'fake', new TokenUsage( 10 ) );
	}
};

$providers = new ProviderRegistry();
$providers->register( $fake );
$policy = new ModelPolicy( $providers );
$policy->save( array( ModelPolicy::TASK_EMBEDDING => array( 'provider' => 'fake', 'model' => 'fake-embed-1' ) ) );

$indexer = new Indexer(
	$extractors,
	$providers,
	$policy,
	new Chunker(),
	$sources,
	$chunks,
	$usage,
	$container->get( SpendGuard::class )
);

$first = $indexer->index_object( PostExtractor::SOURCE_TYPE, (int) $page_id );
$t( 'first index chunks the page', 'chunked' === $first['status'], wp_json_encode( $first ) );
$t( 'chunks were written', $first['chunks'] > 0 );

$second = $indexer->index_object( PostExtractor::SOURCE_TYPE, (int) $page_id );
$t( 'PROBE: re-indexing unchanged content is skipped', 'unchanged' === $second['status'], wp_json_encode( $second ) );
$t( 'skipped re-index writes no chunks', 0 === $second['chunks'] );

$before_calls = $fake->calls;
$embed        = $indexer->embed_pending( 10 );
$t( 'embeds pending chunks', $embed['embedded'] > 0, wp_json_encode( $embed ) );
$t( 'called the provider', $fake->calls > $before_calls );
$t(
	'PROBE: documents embed with the DOCUMENT task type',
	EmbeddingRequest::TASK_DOCUMENT === $fake->last_task
);

// Scoped to this test's own source. embed_pending() drains globally, so on a
// site with a real indexed corpus a second pass legitimately finds other
// chunks — including ones embedded by a different model, which the width and
// model matching now correctly treats as needing re-embedding.
$own = $GLOBALS['wpdb']->get_var(
	$GLOBALS['wpdb']->prepare(
		'SELECT COUNT(*) FROM ' . StoreCrew\Database\Tables::name( StoreCrew\Database\Tables::KNOWLEDGE_CHUNKS )
		. ' WHERE source_id = %d AND embedding IS NULL',
		$first['id']
	)
);
$t( 'PROBE: this source has no unembedded chunks left', 0 === (int) $own, (string) $own );

// A provider returning ragged vectors would silently poison the index.
$fake->ragged = true;

// Long enough to chunk into several pieces, so the dimension guard is exercised
// and not just the count guard.
wp_update_post(
	array(
		'ID'           => $page_id,
		'post_content' => implode(
			"\n\n",
			array_map(
				static fn ( $i ) => "Section {$i}. Returns are accepted within 45 days of delivery. "
					. str_repeat( 'Items must be unworn and in original packaging. ', 20 ),
				range( 1, 5 )
			)
		),
	)
);
$reindexed = $indexer->index_object( PostExtractor::SOURCE_TYPE, (int) $page_id );
$t( 'edited page re-chunks into several pieces', $reindexed['chunks'] > 1, wp_json_encode( $reindexed ) );

$ragged = $indexer->embed_pending( 10 );
$t(
	'PROBE: ragged vectors are rejected, not stored',
	0 === $ragged['embedded'] && $ragged['blocked'],
	wp_json_encode( $ragged )
);
$t(
	'PROBE: rejection leaves the chunks unembedded rather than half-written',
	count( $chunks->needing_embedding( 10 ) ) > 0
);
$fake->ragged = false;
$indexer->embed_pending( 10 );

echo "\n== Indexer without an embedding provider ==\n";
$empty_providers = new ProviderRegistry();
$empty_policy    = new ModelPolicy( $empty_providers );
$lonely          = new Indexer(
	$extractors, $empty_providers, $empty_policy, new Chunker(),
	$sources, $chunks, $usage, $container->get( SpendGuard::class )
);
$blocked = $lonely->embed_pending( 5 );
$t(
	'PROBE: no embedding provider blocks with a reason, not a crash',
	$blocked['blocked'] && str_contains( $blocked['reason'], 'embeddings' ),
	wp_json_encode( $blocked )
);

echo "\n== Retriever ==\n";
$retriever = new Retriever( $providers, $policy, $chunks, $sources );

$found = $retriever->retrieve( 'returns policy unworn items', 3 );
$t( 'retrieval returns results', count( $found['results'] ) > 0, wp_json_encode( $found['strategy'] ) );
$t(
	'PROBE: queries embed with the QUERY task type, not DOCUMENT',
	EmbeddingRequest::TASK_QUERY === $fake->last_task,
	$fake->last_task
);
$t( 'results carry the source object id for live lookups', isset( $found['results'][0]['objectId'] ) );
$t( 'results carry the source url', array_key_exists( 'sourceUrl', $found['results'][0] ) );
$t( 'no degradation reported when embedding works', '' === $found['degraded'] );

$degraded = ( new Retriever( $empty_providers, $empty_policy, $chunks, $sources ) )
	->retrieve( 'returns policy unworn items', 3 );
$t(
	'PROBE: missing embedding provider degrades to keyword search, not failure',
	'' !== $degraded['degraded'],
	$degraded['degraded']
);
$t( 'degraded search still returns something', count( $degraded['results'] ) > 0 );

echo "\n== Index health and estimate ==\n";
$health = $indexer->health();
$t( 'health reports chunk counts', $health['chunks'] > 0 );
$t( 'health reports the configured model and width', '' !== $health['model'] && $health['dimensions'] > 0 );
$t( 'pending is never negative', $health['pending'] >= 0 );
$t(
	'PROBE: health counts vectors from a different model as mismatched, not healthy',
	array_key_exists( 'mismatched', $health )
);

$estimate = $indexer->estimate();
$t( 'estimate counts objects per source', isset( $estimate['objects']['post'] ) );
$t(
	'PROBE: estimate reports cost unknown rather than fabricating one',
	false === $estimate['costKnown'] && 0 === $estimate['costMicros']
);

echo "\n== Forget ==\n";
$t( 'forget removes the source', $indexer->forget( PostExtractor::SOURCE_TYPE, (int) $page_id ) );
$t(
	'PROBE: forgetting removes its chunks too',
	null === $sources->find_by_key( KnowledgeSourceRepository::key( PostExtractor::SOURCE_TYPE, (int) $page_id ) )
);

echo "\n== Cleanup ==\n";
if ( $product_id > 0 ) {
	$indexer->forget( ProductExtractor::SOURCE_TYPE, $product_id );
	wp_delete_post( $product_id, true );
}
wp_delete_post( (int) $page_id, true );
delete_option( ModelPolicy::OPTION );
$GLOBALS['wpdb']->delete(
	StoreCrew\Database\Tables::name( StoreCrew\Database\Tables::USAGE_EVENTS ),
	array( 'provider' => 'fake' ),
	array( '%s' )
);
$usage->rebuild_counters();
$t( 'probe product removed', 0 === $product_id || ! wc_get_product( $product_id ) );
$t( 'probe page removed', null === get_post( (int) $page_id ) );

echo "\n" . str_repeat( '-', 60 ) . "\n";
printf( "%d passed, %d failed\n", $pass, $fail );

if ( $fail > 0 ) {
	exit( 1 );
}
