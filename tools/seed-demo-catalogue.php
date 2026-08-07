<?php
/**
 * Seeds a demo catalogue for retrieval testing.
 *
 * Run with:   wp eval-file wp-content/plugins/storecrew/tools/seed-demo-catalogue.php
 * Remove with: wp eval-file wp-content/plugins/storecrew/tools/seed-demo-catalogue.php --remove
 *
 * Every product carries a `DEMO-` SKU prefix so the whole set can be removed
 * without touching a real catalogue.
 *
 * The descriptions matter more than the count. Retrieval quality is only
 * measurable against text that has something to retrieve *on* — products whose
 * descriptions differ in ways a shopper would actually ask about (warmth,
 * waterproofing, use case, material) rather than lorem ipsum, which embeds to
 * noise and makes every measurement meaningless.
 *
 * @package StoreCrew
 */

if ( ! function_exists( 'wc_get_product' ) ) {
	echo "WooCommerce is not active.\n";

	return;
}

$remove = in_array( '--remove', (array) ( $args ?? array() ), true )
	|| in_array( '--remove', (array) ( $GLOBALS['argv'] ?? array() ), true );

/**
 * name, category, price, short, long
 */
$catalogue = array(
	// --- Outerwear -------------------------------------------------------
	array( 'Summit Down Parka', 'Outerwear', 289.00, 'A serious winter coat for genuine cold.', 'Filled with 800-fill responsibly sourced down and rated comfortable to minus twenty. A storm hood with a wired brim keeps driving snow off the face, and the two-way front zip lets you sit down without the hem riding up. Heavy for its warmth, and unapologetically so.' ),
	array( 'Drizzle Rain Shell', 'Outerwear', 129.00, 'A packable waterproof shell for wet commutes.', 'A two and a half layer waterproof breathable shell that folds into its own chest pocket. Fully taped seams and a stiffened hood peak keep rain out of your eyes. Not insulated, so wear a mid layer when it is genuinely cold.' ),
	array( 'Fell Softshell Jacket', 'Outerwear', 99.00, 'Wind resistant and stretchy for hill walking.', 'A close-fitting softshell that blocks wind and sheds light showers while stretching enough to scramble in. Brushed inner face for warmth against the skin. Best in dry cold; it will wet out in sustained rain.' ),
	array( 'Harbour Wool Peacoat', 'Outerwear', 219.00, 'A smart wool coat for town.', 'Melton wool in a classic double-breasted cut that sits well over a jacket. Warm without bulk and completely at home in an office. Wool resists light drizzle but this is not a rain coat.' ),
	array( 'Ember Insulated Gilet', 'Outerwear', 79.00, 'A body-warmer layer for cold mornings.', 'Synthetic insulation through the body with unlined arm openings, so you stay warm without overheating while moving. Layers under a shell in deep winter and works alone in autumn.' ),

	// --- Footwear --------------------------------------------------------
	array( 'Trailhead Runner', 'Footwear', 139.00, 'A grippy trail running shoe for soft ground.', 'A five millimetre lug pattern that bites into mud and wet grass, on a moderately cushioned midsole that stays lively over long distances. Quick-draining mesh upper. Too aggressive for road running, where the lugs feel unstable.' ),
	array( 'Pavement Road Shoe', 'Footwear', 119.00, 'A cushioned everyday road running shoe.', 'A soft, high-stack foam midsole aimed at easy miles and recovery runs rather than race pace. Smooth heel-to-toe transition and a roomy toe box. Flat outsole, so it slides on wet trails.' ),
	array( 'Bastion Hiking Boot', 'Footwear', 189.00, 'A waterproof boot with ankle support.', 'Full-grain leather over a waterproof membrane, with a supportive shank for carrying a heavy pack across rough ground. Ankle collar reduces roll on scree. Needs breaking in over a few walks.' ),
	array( 'Ferry Canvas Sneaker', 'Footwear', 59.00, 'A simple summer shoe.', 'Cotton canvas upper on a vulcanised rubber sole. Light, breathable and easy to wash. No waterproofing and minimal cushioning, so not for long distances.' ),
	array( 'Hearth Wool Slipper', 'Footwear', 45.00, 'A warm indoor slipper.', 'Boiled wool upper with a suede sole that grips wooden floors without marking them. Warm enough for a cold house in winter. Indoor use only.' ),

	// --- Tops ------------------------------------------------------------
	array( 'Merino Base Layer Crew', 'Tops', 69.00, 'A next-to-skin merino layer that resists odour.', 'A fine 170gsm merino knit that regulates temperature well and stays fresh across several days of wear, which makes it useful for travel and multi-day walks. Warm when damp, unlike cotton.' ),
	array( 'Everyday Cotton Tee', 'Tops', 24.00, 'A heavyweight cotton t-shirt.', 'A 220gsm organic cotton jersey with a slightly boxy cut that holds its shape after washing. Cotton is cool and comfortable but slow to dry and cold once damp.' ),
	array( 'Quarry Flannel Shirt', 'Tops', 79.00, 'A brushed cotton shirt for cool days.', 'Midweight brushed cotton flannel in a relaxed fit that layers over a t-shirt. Warm, soft, and hard-wearing. Works as a light jacket in autumn.' ),
	array( 'Loft Fleece Pullover', 'Tops', 65.00, 'A grid fleece mid layer.', 'A grid-backed fleece that traps warmth while moving moisture away, designed to sit under a shell. Dries quickly and packs small.' ),
	array( 'Long Sleeve Sun Hoody', 'Tops', 72.00, 'Lightweight sun protection for hot days.', 'An airy hooded top rated UPF 50 that keeps the sun off arms and neck without trapping heat. Popular for paddling, hiking at altitude, and fair skin in summer.' ),

	// --- Bottoms ---------------------------------------------------------
	array( 'Ridge Softshell Trouser', 'Bottoms', 109.00, 'Stretchy trousers for hill days.', 'Wind resistant, water repellent and stretchy, with articulated knees for steep ground. Warm enough for cold walking without a base layer underneath.' ),
	array( 'Camp Cotton Chino', 'Bottoms', 69.00, 'A comfortable everyday trouser.', 'Washed cotton twill with a touch of stretch, cut straight through the leg. Smart enough for the office, relaxed enough for the weekend.' ),
	array( 'Torrent Waterproof Overtrouser', 'Bottoms', 89.00, 'Waterproof trousers that go on over boots.', 'Full-length side zips let these go on without removing footwear, which matters when weather turns quickly. Fully taped seams. Packs to the size of a water bottle.' ),
	array( 'Studio Jogger', 'Bottoms', 55.00, 'A soft jogger for lounging and travel.', 'Brushed-back cotton loopback with a drawcord waist and deep pockets. Comfortable on long flights.' ),

	// --- Accessories -----------------------------------------------------
	array( 'Alpine Merino Beanie', 'Accessories', 29.00, 'A warm wool hat for winter.', 'A double-layer merino beanie that covers the ears properly and stays warm when damp. Fine enough to wear under a helmet or a hood without pressure points.' ),
	array( 'Summit Fleece Headband', 'Accessories', 18.00, 'Ear warmth without a full hat.', 'Covers the ears while letting heat escape from the top of the head, which suits running and fast walking in cold weather.' ),
	array( 'Grip Winter Glove', 'Accessories', 42.00, 'Insulated gloves with a usable grip.', 'Synthetic insulation with a silicone-printed palm so you can hold poles and rails securely. Touchscreen-compatible index finger. Water resistant rather than waterproof.' ),
	array( 'Nomad Wool Scarf', 'Accessories', 39.00, 'A soft lambswool scarf.', 'A generously long lambswool scarf that wraps twice without bulk. Warm and non-itchy against the neck.' ),
	array( 'Glare Polarised Sunglasses', 'Accessories', 89.00, 'Polarised lenses that cut water glare.', 'Polarised grey lenses reduce reflected glare off water, snow and wet roads, which lowers eye strain on bright days. Lightweight frame with rubberised temple tips.' ),
	array( 'Cinch Leather Belt', 'Accessories', 49.00, 'A full-grain leather belt.', 'Vegetable-tanned full-grain leather with a solid brass buckle that darkens attractively with use. Cut to length at home.' ),
	array( 'Daybreak Wool Sock', 'Accessories', 19.00, 'Cushioned merino walking socks.', 'Merino blend with a cushioned sole and a snug arch. Warm, breathable and odour resistant across long days on foot.' ),

	// --- Bags ------------------------------------------------------------
	array( 'Wayfarer 30L Backpack', 'Bags', 129.00, 'A day pack for hiking and commuting.', 'Thirty litres with a padded hip belt that carries weight on the hips rather than the shoulders. Separate laptop sleeve makes it work for the office as well as the hill.' ),
	array( 'Drybag Duffel 60L', 'Bags', 149.00, 'A fully waterproof roll-top duffel.', 'Welded seams and a roll-top closure keep contents dry in sustained rain or a wet boat. Removable shoulder straps let it carry as a pack.' ),
	array( 'Courier Canvas Satchel', 'Bags', 99.00, 'A waxed canvas shoulder bag.', 'Waxed cotton canvas that sheds light rain and ages well, with a padded laptop compartment and a quick-access front pocket.' ),
	array( 'Summit Packable Tote', 'Bags', 25.00, 'A tote that folds into a pocket.', 'Ripstop nylon that stuffs into its own inner pocket, for shopping or as a spare bag while travelling.' ),
);

if ( $remove ) {
	$removed = 0;

	foreach ( wc_get_products( array( 'limit' => -1, 'return' => 'ids', 'status' => 'any' ) ) as $id ) {
		$product = wc_get_product( $id );

		if ( $product && str_starts_with( (string) $product->get_sku(), 'DEMO-' ) ) {
			$product->delete( true );
			++$removed;
		}
	}

	printf( "Removed %d demo products.\n", $removed );

	return;
}

$created = 0;
$skipped = 0;

foreach ( $catalogue as $index => $row ) {
	[ $name, $category, $price, $short, $long ] = $row;

	$sku = 'DEMO-' . str_pad( (string) ( $index + 1 ), 3, '0', STR_PAD_LEFT );

	if ( wc_get_product_id_by_sku( $sku ) ) {
		++$skipped;

		continue;
	}

	$term = term_exists( $category, 'product_cat' );

	if ( ! $term ) {
		$term = wp_insert_term( $category, 'product_cat' );
	}

	$product = new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_sku( $sku );
	$product->set_short_description( $short );
	$product->set_description( $long );
	$product->set_regular_price( (string) $price );
	$product->set_manage_stock( true );
	// Varied stock so "is it in stock" is a real question rather than always yes.
	$product->set_stock_quantity( 0 === $index % 9 ? 0 : ( 3 + ( $index % 20 ) ) );
	$product->set_stock_status( 0 === $index % 9 ? 'outofstock' : 'instock' );
	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'visible' );

	if ( ! is_wp_error( $term ) && isset( $term['term_id'] ) ) {
		$product->set_category_ids( array( (int) $term['term_id'] ) );
	}

	$product->save();
	++$created;
}

printf( "Created %d demo products (%d already existed).\n", $created, $skipped );
printf( "Catalogue now has %d published products.\n", (int) wp_count_posts( 'product' )->publish );
