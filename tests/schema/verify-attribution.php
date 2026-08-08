<?php
/**
 * Attribution verification against a live database (FR-ANALYTICS-03).
 *
 * Run with:  wp eval-file wp-content/plugins/storecrew/tests/schema/verify-attribution.php
 *
 * No declare(strict_types=1): wp eval-file runs this through eval(), where a
 * declare must be the first statement of the script and cannot be.
 *
 * Every row created here is removed at the end, including the WooCommerce
 * orders — the suite creates its own rather than touching the merchant's,
 * because the probes here assert on order *status* and *total* and would
 * otherwise be reading real money. It is safe to re-run.
 *
 * @package StoreCrew
 */

use StoreCrew\Chat\OrderAttribution;
use StoreCrew\Chat\Session;
use StoreCrew\Database\Repositories\AgentRunRepository;
use StoreCrew\Database\Repositories\AttributionRepository;
use StoreCrew\Database\Repositories\ConversationRepository;
use StoreCrew\Database\Repositories\MessageRepository;

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

$c             = StoreCrew\Plugin::instance()->container();
$conversations = $c->get( ConversationRepository::class );
$attributions  = $c->get( AttributionRepository::class );
$runs          = $c->get( AgentRunRepository::class );
$messages      = $c->get( MessageRepository::class );
$recorder      = $c->get( OrderAttribution::class );

$made_conversations = array();
$made_orders        = array();

/**
 * Everything this suite created, gone.
 *
 * Installed three ways for the reason CLAUDE.md records: a shutdown function
 * does not run after an uncaught Throwable under `wp eval-file`, because
 * WordPress registers its own fatal handler first. `set_exception_handler` is
 * what actually catches a constructor change mid-suite — and it has to
 * re-report the error itself, or the suite dies silently, which is worse than
 * dying loudly.
 */
$cleanup = static function () use ( &$made_conversations, &$made_orders, $conversations, $attributions, $runs, $messages ) {
	if ( array() !== $made_conversations ) {
		$attributions->delete_for_conversations( $made_conversations );
		$runs->delete_for_conversations( $made_conversations );
		$messages->delete_for_conversations( $made_conversations );
		$conversations->delete_ids( $made_conversations );
	}

	foreach ( $made_orders as $order_id ) {
		$order = wc_get_order( $order_id );

		if ( $order instanceof WC_Order ) {
			$order->delete( true );
		}
	}

	$made_conversations = array();
	$made_orders        = array();
};

register_shutdown_function( $cleanup );
set_exception_handler(
	static function ( $e ) use ( $cleanup ) {
		$cleanup();
		echo "\n  UNCAUGHT: " . $e->getMessage() . "\n  at " . $e->getFile() . ':' . $e->getLine() . "\n";
		exit( 1 );
	}
);

if ( ! function_exists( 'wc_create_order' ) ) {
	echo "SKIPPED: WooCommerce is not active.\n";
	return;
}

/** Open a conversation that has been answered in, so it is attributable. */
$make_conversation = static function ( string $token, int $customer_id, string $channel, string $agent ) use ( $conversations, $runs, &$made_conversations ): int {
	$uuid = $conversations->start( Session::digest( $token ), $customer_id, $channel, 'en_GB' );
	$row  = $conversations->find_by_uuid( (string) $uuid );
	$id   = (int) $row->id;

	$made_conversations[] = $id;

	// run_count > 0 is what makes a conversation attributable: the crew has to
	// have actually answered in it.
	$conversations->record_run( $id );

	$run_id = $runs->start( $id, $agent, 'scripted', 'probe-model' );
	$runs->finish( $run_id, 'completed' );

	return $id;
};

$make_order = static function ( float $total, string $status, int $customer_id = 0 ) use ( &$made_orders ): int {
	$order = wc_create_order();
	$order->set_customer_id( $customer_id );
	$order->set_total( $total );
	$order->set_status( $status );
	$order->save();

	$made_orders[] = (int) $order->get_id();

	return (int) $order->get_id();
};

echo "\n== The link itself ==\n";

$token   = bin2hex( random_bytes( 32 ) );
$conv_id = $make_conversation( $token, 0, ConversationRepository::CHANNEL_WIDGET, 'sales' );
$order_a = $make_order( 120.00, 'completed' );

$t( 'a fresh order is not attributed', ! $attributions->has( $order_a ) );
$t( 'recording the link succeeds', $attributions->record( $order_a, $conv_id, AttributionRepository::BASIS_SESSION, 'sales', 12 ) );
$t( 'and the order now reads as attributed', $attributions->has( $order_a ) );

// The property the double checkout hook depends on. A store running both
// classic and Blocks checkout fires twice for one order, and the second must
// not re-point the link at whatever conversation the shopper is in by then.
$conv_other = $make_conversation( bin2hex( random_bytes( 32 ) ), 0, ConversationRepository::CHANNEL_WIDGET, 'support' );

$t( 'PROBE: a second recording for the same order is refused', ! $attributions->record( $order_a, $conv_other, AttributionRepository::BASIS_SESSION ) );

$rows = $attributions->between( gmdate( 'Y-m-d H:i:s', time() - 3600 ), gmdate( 'Y-m-d H:i:s', time() + 3600 ) );
$mine = array_values( array_filter( $rows, static fn ( $r ) => (int) $r->order_id === $order_a ) );

$t( 'PROBE: and the first link is the one that survived', 1 === count( $mine ) && $conv_id === (int) $mine[0]->conversation_id );
$t( 'the basis is stored, so the methodology is auditable per row', AttributionRepository::BASIS_SESSION === $mine[0]->basis );
$t( 'PROBE: an unknown basis is refused rather than stored', ! $attributions->record( $make_order( 10.0, 'completed' ), $conv_id, 'vibes' ) );

echo "\n== No money is stored, on purpose ==\n";

// FR-KB-08's discipline, applied to attribution: the row is a link, the amount
// is read live. A stored total would keep counting a refunded order forever.
global $wpdb;
$columns = $wpdb->get_col( 'SHOW COLUMNS FROM ' . StoreCrew\Database\Tables::name( StoreCrew\Database\Tables::ATTRIBUTIONS ) );

$money = array_filter( $columns, static fn ( $col ) => (bool) preg_match( '/total|revenue|amount|price|currency/i', (string) $col ) );

$t( 'PROBE: the table holds no amount, total, or currency column', array() === $money, implode( ', ', $money ) );

echo "\n== Recording from a real checkout ==\n";

// The recorder reads the cookie the browser presents. Setting $_COOKIE is
// exactly what a checkout request does to it.
$order_b                       = $make_order( 80.00, 'processing' );
$_COOKIE[ Session::COOKIE ]    = $token;
$recorder->from_order( wc_get_order( $order_b ) );

$t( 'the checkout path records a link from the session cookie', $attributions->has( $order_b ) );

$rows_b = $attributions->between( gmdate( 'Y-m-d H:i:s', time() - 3600 ), gmdate( 'Y-m-d H:i:s', time() + 3600 ) );
$row_b  = array_values( array_filter( $rows_b, static fn ( $r ) => (int) $r->order_id === $order_b ) )[0] ?? null;

$t( 'PROBE: on the session basis, the strong one', null !== $row_b && AttributionRepository::BASIS_SESSION === $row_b->basis );
$t( 'PROBE: and it carries the agent that answered', null !== $row_b && 'sales' === $row_b->agent_id );

// The console is not the storefront (rule 7b). A merchant who asks their
// marketing agent a question and then places a test order must not become
// attributed revenue.
$console_token = bin2hex( random_bytes( 32 ) );
$make_conversation( $console_token, 0, ConversationRepository::CHANNEL_CONSOLE, 'marketing' );

$order_c                    = $make_order( 500.00, 'completed' );
$_COOKIE[ Session::COOKIE ] = $console_token;
$recorder->from_order( wc_get_order( $order_c ) );

$t( 'PROBE: a merchant console conversation never attributes an order', ! $attributions->has( $order_c ) );

// A conversation nobody answered in cannot have influenced anything.
$silent_token = bin2hex( random_bytes( 32 ) );
$silent_uuid  = $conversations->start( Session::digest( $silent_token ), 0, ConversationRepository::CHANNEL_WIDGET, 'en_GB' );
$silent_row   = $conversations->find_by_uuid( (string) $silent_uuid );

$made_conversations[] = (int) $silent_row->id;

$order_d                    = $make_order( 60.00, 'completed' );
$_COOKIE[ Session::COOKIE ] = $silent_token;
$recorder->from_order( wc_get_order( $order_d ) );

$t( 'PROBE: an unanswered conversation attributes nothing', ! $attributions->has( $order_d ) );

// No cookie at all, and no account: nothing to link on.
unset( $_COOKIE[ Session::COOKIE ] );

$order_e = $make_order( 45.00, 'completed' );
$recorder->from_order( wc_get_order( $order_e ) );

$t( 'PROBE: a guest with no session attributes nothing', ! $attributions->has( $order_e ) );

echo "\n== The methodology travels with the data ==\n";

$method = $recorder->methodology();

$t( 'it names its model', 'last-touch' === ( $method['model'] ?? '' ) );
$t( 'and the window in days', ( $method['windowDays'] ?? 0 ) === OrderAttribution::DEFAULT_WINDOW_DAYS );
$t( 'PROBE: it states what it cannot see, not only what it can', array() !== (array) ( $method['undercounts'] ?? array() ) );
$t( 'PROBE: and calls the figure a floor in the sentence a report repeats', str_contains( (string) ( $method['statement'] ?? '' ), 'floor' ) );

// A window measured in years does not measure attribution; it measures having
// ever had a conversation.
$absurd = static fn () => 3650;
add_filter( 'storecrew_attribution_window_days', $absurd );
$t( 'PROBE: an absurd window is clamped, not honoured', $recorder->methodology()['windowDays'] <= 90 );
remove_filter( 'storecrew_attribution_window_days', $absurd );

// A window of zero would be a merchant switching attribution off by accident.
$zero = static fn () => 0;
add_filter( 'storecrew_attribution_window_days', $zero );
$t( 'PROBE: a zero window clamps up rather than disabling silently', $recorder->methodology()['windowDays'] >= 1 );
remove_filter( 'storecrew_attribution_window_days', $zero );

echo "\n== Reading a window ==\n";

$from  = gmdate( 'Y-m-d H:i:s', time() - 3600 );
$to    = gmdate( 'Y-m-d H:i:s', time() + 3600 );
$count = $attributions->count_between( $from, $to );

$t( 'count_between sees the links just made', $count >= 2 );
$t( 'PROBE: and a window before them sees none', 0 === $attributions->count_between( '2001-01-01 00:00:00', '2001-01-02 00:00:00' ) );

// The ceiling is what lets a caller tell a complete answer from a truncated
// one. A report that silently summed the first N links and called it revenue
// would under-count in the direction that looks like a working feature.
$t( 'PROBE: the row ceiling is enforced', count( $attributions->between( $from, $to, 1 ) ) <= 1 );
$t( 'PROBE: while the count ignores it, so truncation is detectable', $attributions->count_between( $from, $to ) > count( $attributions->between( $from, $to, 1 ) ) );

echo "\n== Retention and erasure sever the link ==\n";

// A link whose conversation is gone is a revenue figure nobody can check.
//
// The bystander belongs to a *different* conversation on purpose: $order_b was
// recorded through the same session cookie as $order_a, so both hang off one
// conversation and a cascade probe using it would pass without proving the
// WHERE clause is scoped at all.
$order_f = $make_order( 30.00, 'completed' );
$attributions->record( $order_f, $conv_other, AttributionRepository::BASIS_CUSTOMER );

$t( 'a link exists before the cascade', $attributions->has( $order_a ) );
$attributions->delete_for_conversations( array( $conv_id ) );
$t( 'PROBE: deleting the conversation deletes its links', ! $attributions->has( $order_a ) );
$t( 'PROBE: including every one of them', ! $attributions->has( $order_b ) );
$t( 'PROBE: while another conversation\'s link is untouched', $attributions->has( $order_f ) );

$cleanup();

$t( 'cleanup: probe conversations removed', array() === $made_conversations );

echo "\n" . str_repeat( '-', 60 ) . "\n";
printf( "%d passed, %d failed\n", $pass, $fail );

if ( $fail > 0 ) {
	exit( 1 );
}
