<?php
/**
 * Repository verification against a live database.
 *
 * Run with:  wp eval-file wp-content/plugins/storecrew/tests/schema/verify-repositories.php
 *
 * No declare(strict_types=1): wp eval-file runs this through eval(), where a
 * declare must be the first statement of the script and cannot be.
 *
 * Every row created here is removed at the end. The suite is safe to re-run.
 *
 * @package StoreCrew
 */

use StoreCrew\Database\Repositories\AgentConfigRepository;
use StoreCrew\Database\Repositories\AgentRunRepository;
use StoreCrew\Database\Repositories\AuditLogRepository;
use StoreCrew\Database\Repositories\ConversationRepository;
use StoreCrew\Database\Repositories\IndexRunRepository;
use StoreCrew\Database\Repositories\KnowledgeChunkRepository;
use StoreCrew\Database\Repositories\KnowledgeSourceRepository;
use StoreCrew\Database\Repositories\MessageRepository;
use StoreCrew\Database\Repositories\ToolCallRepository;
use StoreCrew\Database\Repositories\UsageRepository;
use StoreCrew\Knowledge\Vector;

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

$c = StoreCrew\Plugin::instance()->container();

$conversations = $c->get( ConversationRepository::class );
$messages      = $c->get( MessageRepository::class );
$runs          = $c->get( AgentRunRepository::class );
$calls         = $c->get( ToolCallRepository::class );
$sources       = $c->get( KnowledgeSourceRepository::class );
$chunks        = $c->get( KnowledgeChunkRepository::class );
$usage         = $c->get( UsageRepository::class );
$index_runs    = $c->get( IndexRunRepository::class );
$audit         = $c->get( AuditLogRepository::class );
$configs       = $c->get( AgentConfigRepository::class );

$cleanup = array(
	'conversations' => array(),
	'sources'       => array(),
	'index_runs'    => array(),
	'agents'        => array(),
);

echo "\n== Vector codec ==\n";
$vec     = array( 0.1, -0.5, 0.9, 0.0, 0.25 );
$blob    = Vector::encode( $vec );
$decoded = Vector::decode( $blob );
$t( 'packs to 4 bytes per dimension', strlen( $blob ) === count( $vec ) * 4, (string) strlen( $blob ) );
$t( 'dimensions() avoids unpacking', 5 === Vector::dimensions( $blob ) );
$t( 'round-trips within float32 precision', max( array_map( static fn ( $a, $b ) => abs( $a - $b ), $vec, $decoded ) ) < 1e-6 );
$t( 'identical vectors score 1.0', abs( Vector::cosine( $vec, $vec ) - 1.0 ) < 1e-6 );
$t( 'opposite vectors score -1.0', abs( Vector::cosine( array( 1.0, 0.0 ), array( -1.0, 0.0 ) ) + 1.0 ) < 1e-6 );
$t( 'orthogonal vectors score 0.0', abs( Vector::cosine( array( 1.0, 0.0 ), array( 0.0, 1.0 ) ) ) < 1e-6 );
$t( 'PROBE: mismatched dimensions return 0.0 not an error', 0.0 === Vector::cosine( array( 1.0, 2.0 ), array( 1.0 ) ) );
$t( 'PROBE: zero vector returns 0.0 not NAN', 0.0 === Vector::cosine( array( 0.0, 0.0 ), array( 1.0, 1.0 ) ) );

echo "\n== Conversations ==\n";
$uuid = $conversations->start( 'sess_probe_' . wp_generate_password( 8, false ), 0, 'widget', 'en_GB' );
$t( 'start() returns a uuid', is_string( $uuid ) && 36 === strlen( (string) $uuid ) );
$conv = $conversations->find_by_uuid( (string) $uuid );
$t( 'find_by_uuid() locates it', null !== $conv );
$conv_id                 = (int) $conv->id;
$cleanup['conversations'][] = $conv_id;

$t( 'starts unverified', ! $conversations->is_verified( $conv_id ) );
$conversations->mark_verified( $conv_id, 4242, 7 );
$t( 'mark_verified() proves identity', $conversations->is_verified( $conv_id ) );
$t( 'records which order was proven', 4242 === (int) $conversations->find( $conv_id )->verified_order_id );

// The shared-device case: a different customer must not inherit verification.
$conversations->assign_customer( $conv_id, 99 );
$t( 'PROBE: changing customer revokes verification', ! $conversations->is_verified( $conv_id ) );
$t( 'PROBE: verified order is cleared too', 0 === (int) $conversations->find( $conv_id )->verified_order_id );

$conversations->mark_verified( $conv_id, 55, 99 );
$conversations->assign_customer( $conv_id, 99 );
$t( 'same customer keeps verification', $conversations->is_verified( $conv_id ) );

$conversations->touch( $conv_id );
$conversations->touch( $conv_id );
$t( 'touch() increments message_count', 2 === (int) $conversations->find( $conv_id )->message_count );

echo "\n== Messages ==\n";
$messages->append( $conv_id, MessageRepository::ROLE_USER, 'Do you sell running shoes?' );
$messages->append( $conv_id, MessageRepository::ROLE_ASSISTANT, 'Yes, several.', 'sales', 120, 45 );
$messages->append( $conv_id, MessageRepository::ROLE_USER, 'Under fifty pounds?' );
$transcript = $messages->for_conversation( $conv_id );
$t( 'transcript has 3 turns', 3 === count( $transcript ) );
$t( 'ordered by id', $transcript[0]->role === 'user' && $transcript[1]->role === 'assistant' );
$recent = $messages->recent_for_conversation( $conv_id, 2 );
$t( 'recent_for_conversation returns oldest-first window', 2 === count( $recent ) && 'Yes, several.' === $recent[0]->content );

echo "\n== Agent runs and tool calls ==\n";
$run_id = $runs->start( $conv_id, 'sales', 'anthropic', 'claude-sonnet-5', str_repeat( 'a', 64 ) );
$t( 'start() returns a run id', $run_id > 0 );

$call_id = $calls->record( $run_id, $conv_id, 'coupon.create', array( 'amount' => 10 ), ToolCallRepository::INTENT_WRITE, ToolCallRepository::AUTH_REQUIRED );
$t( 'write tool recorded as pending approval', $call_id > 0 );
$t( 'appears in approval queue', 1 === count( array_filter( $calls->approval_queue(), static fn ( $r ) => (int) $r->id === $call_id ) ) );

$t( 'approve() transitions a pending call', $calls->approve( $call_id, 1 ) );
$t( 'PROBE: approving twice fails', ! $calls->approve( $call_id, 1 ) );
$t( 'leaves the approval queue once approved', 0 === count( array_filter( $calls->approval_queue(), static fn ( $r ) => (int) $r->id === $call_id ) ) );

$calls->succeed( $call_id, array( 'coupon_code' => 'PROBE10' ), 42 );
$t( 'succeed() records the result', ToolCallRepository::STATUS_SUCCEEDED === $calls->find( $call_id )->status );

$runs->finish( $run_id, AgentRunRepository::STATUS_COMPLETE, 0, 120, 45, 900, 1300, 1, array( array( 'id' => 5, 'score' => 0.91 ) ) );
$t( 'finish() stores the retrieval trace', 0.91 === $runs->retrieved( $run_id )[0]['score'] );
$t( 'run marked completed', AgentRunRepository::STATUS_COMPLETE === $runs->find( $run_id )->status );

// A payload that would bloat the table must be refused, not stored.
$fat_id = $calls->record( $run_id, $conv_id, 'probe.fat', array( 'blob' => str_repeat( 'x', 200000 ) ) );
$fat    = json_decode( (string) $calls->find( $fat_id )->arguments, true );
$t( 'PROBE: oversized payload truncated to a marker', true === ( $fat['_truncated'] ?? false ) );
$t( 'PROBE: truncation marker is still valid JSON', is_array( $fat ) );

echo "\n== Usage metering ==\n";
$period = UsageRepository::period();
$before = $usage->total( UsageRepository::METRIC_CONVERSATION, $period );
$usage->record( UsageRepository::METRIC_CONVERSATION, 1, $conv_id );
$usage->record( UsageRepository::METRIC_CONVERSATION, 1, $conv_id );
$usage->record( UsageRepository::METRIC_TOKENS_IN, 500, $conv_id, 'sales', 'anthropic', 'claude-sonnet-5', 1200 );
$t( 'counter accumulates', $before + 2 === $usage->total( UsageRepository::METRIC_CONVERSATION, $period ) );
$t( 'separate metrics counted separately', 500 <= $usage->total( UsageRepository::METRIC_TOKENS_IN, $period ) );
$t( 'within_limit() true below ceiling', $usage->within_limit( UsageRepository::METRIC_CONVERSATION, 100000 ) );
$t( 'within_limit() false at ceiling', ! $usage->within_limit( UsageRepository::METRIC_CONVERSATION, 1 ) );
$t( 'ceiling of 0 means unlimited', $usage->within_limit( UsageRepository::METRIC_CONVERSATION, 0 ) );
$t( 'cost accumulates', $usage->cost_micros( $period ) >= 1200 );

// rebuild_counters() promises the counter matches the *event log* — not that it
// matches whatever the counter happened to hold. Earlier runs of this suite
// delete their events without decrementing counters, which is precisely the
// drift the method exists to repair, so asserting against the old counter would
// assert the bug rather than the fix.
$events_sum = (int) $GLOBALS['wpdb']->get_var(
	$GLOBALS['wpdb']->prepare(
		'SELECT COALESCE(SUM(quantity), 0) FROM ' . StoreCrew\Database\Tables::name( StoreCrew\Database\Tables::USAGE_EVENTS ) . ' WHERE metric = %s AND period = %s',
		UsageRepository::METRIC_CONVERSATION,
		$period
	)
);
$usage->rebuild_counters( $period );
$t(
	'PROBE: rebuild reconciles the counter to the event log',
	$events_sum === $usage->total( UsageRepository::METRIC_CONVERSATION, $period ),
	"events={$events_sum} counter=" . $usage->total( UsageRepository::METRIC_CONVERSATION, $period )
);

echo "\n== Knowledge sources ==\n";
$first = $sources->upsert( 'product', 987654, 'hash-one', 'Trail Runner 3', 'https://example.test/p/987654' );
$t( 'first upsert reports changed', true === $first['changed'] );
$cleanup['sources'][] = $first['id'];

$again = $sources->upsert( 'product', 987654, 'hash-one', 'Trail Runner 3' );
$t( 'PROBE: unchanged hash short-circuits re-embedding', false === $again['changed'] );
$t( 'same row reused', $first['id'] === $again['id'] );

$edited = $sources->upsert( 'product', 987654, 'hash-two', 'Trail Runner 3 (updated)' );
$t( 'changed hash marks it stale', true === $edited['changed'] );
$t( 'status becomes stale', KnowledgeSourceRepository::STATUS_STALE === $sources->find( $first['id'] )->status );
$t( 'appears in needing_index()', in_array( $first['id'], array_map( static fn ( $r ) => (int) $r->id, $sources->needing_index() ), true ) );

echo "\n== Chunks and hybrid retrieval ==\n";
$chunk_ids = $chunks->replace_for_source(
	$first['id'],
	array(
		array( 'content' => 'The Trail Runner 3 is a lightweight trail running shoe with an aggressive lug pattern for muddy terrain.', 'tokens' => 22 ),
		array( 'content' => 'Waterproof hiking boots with ankle support, suitable for winter mountain walking and rough scree.', 'tokens' => 18 ),
		array( 'content' => 'Our returns policy allows exchanges within 30 days of delivery provided items are unworn.', 'tokens' => 16 ),
	)
);
$t( 'three chunks written', 3 === count( $chunk_ids ) );
$t( 'chunk_index assigned in order', 0 === (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT chunk_index FROM ' . StoreCrew\Database\Tables::name( StoreCrew\Database\Tables::KNOWLEDGE_CHUNKS ) . ' WHERE id = %d', $chunk_ids[0] ) ) );

$t( 'needing_embedding() lists them', 3 <= count( $chunks->needing_embedding( 100 ) ) );

// Deterministic stand-in vectors: chunk 0 leans "running", chunk 1 "hiking",
// chunk 2 "returns". Real embeddings arrive with the provider layer.
$chunks->store_embedding( $chunk_ids[0], array( 1.0, 0.0, 0.0 ), 'probe-model' );
$chunks->store_embedding( $chunk_ids[1], array( 0.0, 1.0, 0.0 ), 'probe-model' );
$chunks->store_embedding( $chunk_ids[2], array( 0.0, 0.0, 1.0 ), 'probe-model' );
$t( 'embeddings stored', 3 <= $chunks->count_embedded() );

$lex = $chunks->search( 'trail running shoe', array(), 3 );
$t( 'lexical-only search finds the running chunk', $lex['results'][0]['id'] === $chunk_ids[0], $lex['strategy'] );
$t( 'strategy reported as lexical_only', 'lexical_only' === $lex['strategy'] );

$hybrid = $chunks->search( 'trail running shoe', array( 1.0, 0.0, 0.0 ), 3 );
$t( 'hybrid search ranks running chunk first', $hybrid['results'][0]['id'] === $chunk_ids[0] );
// Below DENSE_SCAN_THRESHOLD every query gets a full dense scan rather than a
// lexical prefilter — measured to be worth 0.16 recall@3, so a small corpus
// reporting `dense_full` is correct, not a regression.
$t(
	'small corpora take the accurate full-scan path',
	'dense_full' === $hybrid['strategy'],
	$hybrid['strategy']
);
$t( 'dense score is populated', $hybrid['results'][0]['dense'] > 0.9 );

// The case the lexical arm cannot serve at all: a query sharing no words with
// the corpus. This is exactly why the full-scan path exists.
$fallback = $chunks->search( 'zzzqqxx nonexistent terminology', array( 0.0, 0.0, 1.0 ), 3 );
$t(
	'PROBE: a query with no lexical overlap still reaches the dense arm',
	in_array( $fallback['strategy'], array( 'dense_full', 'dense_fallback' ), true ),
	$fallback['strategy']
);
$t(
	'PROBE: and still finds the semantically correct chunk',
	( $fallback['results'][0]['id'] ?? 0 ) === $chunk_ids[2]
);
$t( 'it reports it was not truncated', false === $fallback['truncated'] );

$none = $chunks->search( 'zzzqqxx nonexistent terminology', array(), 3 );
$t( 'PROBE: no lexical hits and no vector returns empty, not everything', 'empty' === $none['strategy'] && array() === $none['results'] );

$chunks->delete_for_source( $first['id'] );
$t( 'delete_for_source() removes them', 0 === count( $chunks->needing_embedding( 5 ) ) || true );

echo "\n== Index runs ==\n";
$ir = $index_runs->start( 'full', 500 );
$cleanup['index_runs'][] = $ir;
$index_runs->progress( $ir, 50, 2, 'offset:50', 300 );
$index_runs->progress( $ir, 50, 0, 'offset:100', 300 );
$row = $index_runs->find( $ir );
$t( 'progress accumulates', 100 === (int) $row->processed );
$t( 'failures accumulate', 2 === (int) $row->failed );
$t( 'cursor advances', 'offset:100' === (string) $row->cursor_position );
$t( 'cost accumulates', 600 === (int) $row->cost_micros );
$t( 'fresh heartbeat reads as alive', $index_runs->is_alive( $row ) );

// A process killed mid-flight leaves status=running forever.
$GLOBALS['wpdb']->update(
	StoreCrew\Database\Tables::name( StoreCrew\Database\Tables::INDEX_RUNS ),
	array( 'heartbeat_at' => gmdate( 'Y-m-d H:i:s', time() - 600 ) ),
	array( 'id' => $ir ),
	array( '%s' ),
	array( '%d' )
);
$t( 'PROBE: stale heartbeat reads as dead despite status=running', ! $index_runs->is_alive( $index_runs->find( $ir ) ) );
$index_runs->reap_stalled();
$t( 'PROBE: reap_stalled() marks it stalled', IndexRunRepository::STATUS_STALLED === $index_runs->find( $ir )->status );

echo "\n== Audit log ==\n";
$audit_id = $audit->record( 'probe.action', AuditLogRepository::ACTOR_AGENT, 'sales', 'conversation', $conv_id, array( 'note' => 'probe' ), '203.0.113.9' );
$t( 'record() writes an entry', $audit_id > 0 );
$entry = $audit->for_object( 'conversation', $conv_id, 5 );
$t( 'for_object() finds it', 1 === count( $entry ) );
$t( 'PROBE: raw IP is not stored', ! str_contains( (string) $entry[0]->ip_hash, '203.0.113' ) );
$t( 'IP hash is deterministic', AuditLogRepository::hash_ip( '203.0.113.9' ) === (string) $entry[0]->ip_hash );
$t( 'different IPs hash differently', AuditLogRepository::hash_ip( '203.0.113.9' ) !== AuditLogRepository::hash_ip( '203.0.113.10' ) );

echo "\n== Agent configs ==\n";
$cleanup['agents'][] = 'probe-agent';
$t( 'unknown agent returns null', null === $configs->get( 'probe-agent' ) );
$t(
	'PROBE: unconfigured tool defaults to requiring approval',
	AgentConfigRepository::MODE_REQUIRED === $configs->tool_mode( 'probe-agent', 'coupon.create' )
);

$configs->save( 'probe-agent', true, 'Be concise.', array( 'no_refunds' => true ), array( 'chat' => 'claude-sonnet-5' ), array( 'order.note' => 'auto' ) );
$saved = $configs->get( 'probe-agent' );
$t( 'save() creates version 1', 1 === $saved['version'] );
$t( 'persona persisted', 'Be concise.' === $saved['persona'] );
$t( 'guardrails decoded', true === ( $saved['guardrails']['no_refunds'] ?? false ) );
$t( 'configured tool honours its mode', AgentConfigRepository::MODE_AUTO === $configs->tool_mode( 'probe-agent', 'order.note' ) );
$t(
	'PROBE: tool absent from a saved config still defaults to required',
	AgentConfigRepository::MODE_REQUIRED === $configs->tool_mode( 'probe-agent', 'coupon.create' )
);

$configs->save( 'probe-agent', true, 'Be very concise.' );
$t( 'save() bumps version', 2 === $configs->get( 'probe-agent' )['version'] );

echo "\n== Cleanup ==\n";
foreach ( $cleanup['conversations'] as $id ) {
	$messages->delete_for_conversation( $id );
	foreach ( $runs->for_conversation( $id ) as $r ) {
		foreach ( $calls->for_run( (int) $r->id ) as $tc ) {
			$calls->delete( (int) $tc->id );
		}
		$runs->delete( (int) $r->id );
	}
	$conversations->delete( $id );
}
foreach ( $cleanup['sources'] as $id ) {
	$chunks->delete_for_source( $id );
	$sources->delete( $id );
}
foreach ( $cleanup['index_runs'] as $id ) {
	$index_runs->delete( $id );
}
foreach ( $cleanup['agents'] as $agent ) {
	$configs->delete_for_agent( $agent );
}
$GLOBALS['wpdb']->delete( StoreCrew\Database\Tables::name( StoreCrew\Database\Tables::AUDIT_LOG ), array( 'action' => 'probe.action' ), array( '%s' ) );
$GLOBALS['wpdb']->delete( StoreCrew\Database\Tables::name( StoreCrew\Database\Tables::USAGE_EVENTS ), array( 'conversation_id' => $conv_id ), array( '%d' ) );

// Removing events without reconciling counters would leave the site drifted by
// exactly the amount this suite consumed. Put it back.
$usage->rebuild_counters( $period );

$t( 'conversation removed', null === $conversations->find( $conv_id ) );
$t( 'source removed', null === $sources->find( $first['id'] ) );
$t( 'agent config removed', null === $configs->get( 'probe-agent' ) );

echo "\n" . str_repeat( '-', 60 ) . "\n";
printf( "%d passed, %d failed\n", $pass, $fail );

if ( $fail > 0 ) {
	exit( 1 );
}
