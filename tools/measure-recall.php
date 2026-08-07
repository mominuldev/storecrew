<?php
/**
 * FR-KB-09 recall measurement.
 *
 * Run with: wp eval-file wp-content/plugins/storecrew/tools/measure-recall.php
 *
 * Measures recall@k against a fixture set of questions with known-correct
 * answers, and sweeps the dense/lexical fusion weight so the setting is chosen
 * from data rather than taste.
 *
 * The fixtures are deliberately phrased the way a shopper would ask — "warm hat
 * for winter", not "beanie". A fixture set that reuses the product's own words
 * measures keyword matching and flatters the system; the whole reason for
 * embeddings is the case where the shopper's words and the catalogue's words
 * differ.
 *
 * Requires the demo catalogue (tools/seed-demo-catalogue.php) and a configured
 * embedding provider.
 *
 * @package StoreCrew
 */

use StoreCrew\Ai\EmbeddingRequest;
use StoreCrew\Api\Registry\ProviderRegistry;
use StoreCrew\Database\Repositories\KnowledgeChunkRepository;
use StoreCrew\Database\Repositories\KnowledgeSourceRepository;
use StoreCrew\Knowledge\Indexer;

$container = StoreCrew\Plugin::instance()->container();
$chunks    = $container->get( KnowledgeChunkRepository::class );
$sources   = $container->get( KnowledgeSourceRepository::class );
$policy    = $container->get( StoreCrew\Ai\ModelPolicy::class );

$resolved = $policy->resolve( 'embedding' );

if ( null === $resolved ) {
	echo "No embedding provider configured.\n";

	return;
}

$provider = $container->get( ProviderRegistry::class )->get( $resolved['provider'] );

/** question => substring of the title that should appear in the top k */
$fixtures = array(
	// Product discovery, phrased as a shopper would.
	'warm hat for winter'                       => 'Beanie',
	'something to keep my ears warm running'    => 'Headband',
	'gloves I can use my phone with'            => 'Glove',
	'a waterproof jacket for heavy rain'        => 'Rain Shell',
	'shoes with grip for muddy trails'          => 'Trailhead',
	'boots for carrying a heavy pack'           => 'Bastion',
	'trousers I can pull on over my boots'      => 'Overtrouser',
	'a coat smart enough for the office'        => 'Peacoat',
	'top that does not smell after a few days'  => 'Merino',
	'sun protection for hiking in summer'       => 'Sun Hoody',
	'a bag that keeps things dry on a boat'     => 'Drybag',
	'backpack for commuting with a laptop'      => 'Wayfarer',
	'sunglasses for glare off water'            => 'Polarised',
	'socks for walking all day'                 => 'Sock',
	'a warm layer that is not bulky'            => 'Gilet',
	'slippers for a cold house'                 => 'Slipper',
	'a belt that will last'                     => 'Belt',
	'something light for hot weather'           => 'Cotton Tee',

	// Policy questions.
	'what is your returns policy'               => 'Returns',
	'how long does delivery take'               => 'Delivery',
	'do you offer gift wrapping'                => 'Gift',
	'how should I wash this'                    => 'Product care',
	'what size should I order'                  => 'Sizing',
);

echo "Embedding " . count( $fixtures ) . " fixture queries...\n";

$vectors = array();

foreach ( array_keys( $fixtures ) as $question ) {
	$vectors[ $question ] = $provider->embed(
		new EmbeddingRequest( $resolved['model'], array( $question ), EmbeddingRequest::TASK_QUERY, 60, Indexer::dimensions() )
	)->vectors[0];
}

/**
 * Score one configuration.
 *
 * @return array{recall: float, misses: list<string>}
 */
$evaluate = static function ( float $dense_weight, int $k ) use ( $fixtures, $vectors, $chunks, $sources ): array {
	$hits   = 0;
	$misses = array();

	foreach ( $fixtures as $question => $expected ) {
		$found = $chunks->search( $question, $vectors[ $question ], $k, $dense_weight );

		$hit = false;

		foreach ( $found['results'] as $row ) {
			$source = $sources->find( (int) $row['source_id'] );

			if ( null !== $source && false !== stripos( (string) $source->title, $expected ) ) {
				$hit = true;
				break;
			}
		}

		if ( $hit ) {
			++$hits;
		} else {
			$misses[] = $question;
		}
	}

	return array(
		'recall' => $hits / count( $fixtures ),
		'misses' => $misses,
	);
};

echo "\nDense-weight sweep (recall@3 / recall@5):\n";
echo str_repeat( '-', 52 ) . "\n";

$best        = 0.0;
$best_weight = 0.8;

foreach ( array( 0.0, 0.5, 0.7, 0.8, 0.9, 0.95, 1.0 ) as $weight ) {
	$at3 = $evaluate( $weight, 3 );
	$at5 = $evaluate( $weight, 5 );

	printf(
		"  dense=%-5.2f  recall@3 = %.2f   recall@5 = %.2f%s\n",
		$weight,
		$at3['recall'],
		$at5['recall'],
		$at3['recall'] >= 0.88 ? '  <- clears the 0.88 bar' : ''
	);

	if ( $at3['recall'] > $best ) {
		$best        = $at3['recall'];
		$best_weight = $weight;
	}
}

echo str_repeat( '-', 52 ) . "\n";
printf( "best: dense=%.2f at recall@3 = %.2f\n", $best_weight, $best );

$detail = $evaluate( $best_weight, 3 );

if ( array() !== $detail['misses'] ) {
	echo "\nStill missing at the best setting:\n";

	foreach ( $detail['misses'] as $miss ) {
		printf( "  - %s (want: %s)\n", $miss, $fixtures[ $miss ] );
	}
}

printf(
	"\ncorpus: %d chunks embedded, %d dimensions, threshold %d\n",
	$chunks->count_embedded(),
	Indexer::dimensions(),
	KnowledgeChunkRepository::DENSE_SCAN_THRESHOLD
);

// ---------------------------------------------------------------------------
// Exact-identifier fixtures (14 § M1's SKU tool).
//
// These are deliberately NOT in the semantic fixture set above: the original
// measurement showed identifier queries failing at every fusion weight, and
// the conclusion was structural — an embedding of "DEMO-003" carries nothing
// for cosine similarity to find. Identifiers are answered by product.lookup's
// exact resolution, and this section asserts that path instead: the harness
// scores each surface on the queries that surface exists for.
// ---------------------------------------------------------------------------

echo "\nExact identifiers (product.lookup path):\n";

$lookup    = new StoreCrew\Agent\Tools\ProductLookupTool();
$id_pass   = 0;
$id_total  = 0;
$identifiers = array( 'DEMO-001', 'DEMO-003', 'DEMO-012' );

foreach ( $identifiers as $sku ) {
	++$id_total;

	$result = $lookup->execute(
		new StoreCrew\Agent\Tool\ToolContext( 0 ),
		array( 'sku' => $sku )
	);

	$hit = $result->is_ok() && ( $result->data['sku'] ?? '' ) === $sku;

	if ( $hit ) {
		++$id_pass;
	}

	printf( "  %s %s%s\n", $hit ? 'HIT ' : 'MISS', $sku, $hit ? ' -> ' . $result->data['name'] : '' );
}

printf( "identifier resolution: %d/%d via exact lookup (semantic path not consulted)\n", $id_pass, $id_total );
