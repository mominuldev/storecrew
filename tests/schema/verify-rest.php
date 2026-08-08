<?php
/**
 * REST API verification.
 *
 * Run with:  wp eval-file wp-content/plugins/storecrew/tests/schema/verify-rest.php
 *
 * Dispatches through the real WP_REST_Server, so permission callbacks, argument
 * validation, and route matching are all exercised rather than bypassed by
 * calling controller methods directly.
 *
 * No declare(strict_types=1): wp eval-file runs this through eval().
 *
 * @package StoreCrew
 */

use StoreCrew\Api\Registry\ControllerRegistry;
use StoreCrew\Api\Rest\RestController;
use StoreCrew\Core\Capabilities\Capabilities;
use StoreCrew\Database\Repositories\ConversationRepository;
use StoreCrew\Database\Repositories\ToolCallRepository;
use StoreCrew\Database\Tables;
use StoreCrew\Security\SecretStore;

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

// The settings probes write the live model-policy and spend options. Snapshot
// and restore rather than delete — a configured store keeps its configuration.
$saved_policy = get_option( StoreCrew\Ai\ModelPolicy::OPTION );
$saved_cap    = get_option( StoreCrew\Ai\SpendGuard::OPTION_CAP_MICROS );
$saved_breach = get_option( StoreCrew\Ai\SpendGuard::OPTION_ON_BREACH );
$saved_sources = get_option( StoreCrew\Knowledge\SourceSelection::OPTION, false );

// The agent probes write real configuration rows. Snapshot whatever the
// merchant has so standing an agent down here cannot leave it down.
$agent_configs = $c->get( StoreCrew\Database\Repositories\AgentConfigRepository::class );
$saved_agents  = array();

foreach ( array( 'sales', 'support' ) as $probe_agent ) {
	$saved_agents[ $probe_agent ] = $agent_configs->get( $probe_agent );
}

// The "unconfigured store" probes (canEmbed false, degraded search) mean
// nothing on a site with a real provider key — they only ever passed here
// because another suite had wiped the merchant's keys. Construct the state
// the probes assume instead of inheriting it: snapshot the secrets, clear
// the provider keys, restore both at cleanup.
$saved_secrets = get_option( StoreCrew\Security\SecretStore::OPTION_SECRETS, false );
$suite_secrets = new StoreCrew\Security\SecretStore();

foreach ( array( 'anthropic', 'openai', 'gemini', 'openrouter', 'deepseek' ) as $provider_id ) {
	$suite_secrets->forget( 'provider.' . $provider_id . '.key' );
}

// Every /bootstrap dispatch below runs the onboarding step recorder, which
// writes usage events and a ledger option on a store whose setup is genuinely
// finished. Snapshot the ledger and note the high-water mark of the events
// table now, so cleanup can remove exactly the rows this suite caused and not
// one the merchant's own onboarding wrote. Registered for shutdown too: a fatal
// mid-suite would otherwise leave the ledger claiming steps that never
// happened, and the *next* run would snapshot the lie.
global $wpdb;

$saved_progress = get_option( StoreCrew\Core\SetupProgress::OPTION, false );
$events_before  = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id), 0) FROM ' . Tables::name( Tables::USAGE_EVENTS ) );

$restore_progress = static function () use ( $saved_progress, $events_before ) {
	global $wpdb;

	if ( false === $saved_progress ) {
		delete_option( StoreCrew\Core\SetupProgress::OPTION );
	} else {
		update_option( StoreCrew\Core\SetupProgress::OPTION, $saved_progress, false );
	}

	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM ' . Tables::name( Tables::USAGE_EVENTS ) . ' WHERE id > %d AND metric LIKE %s',
			$events_before,
			$wpdb->esc_like( StoreCrew\Database\Repositories\UsageRepository::METRIC_SETUP_STEP . '.' ) . '%'
		)
	);
};

register_shutdown_function( $restore_progress );

do_action( 'rest_api_init' );
$server = rest_get_server();

/** Dispatch a request and return [status, body]. */
$call = static function ( string $method, string $path, array $body = array() ) use ( $server ): array {
	$request = new WP_REST_Request( $method, $path );

	if ( array() !== $body ) {
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
	}

	$response = $server->dispatch( $request );

	return array( $response->get_status(), $response->get_data() );
};

$admin_id = 1;
$original = get_current_user_id();

echo "\n== Routes are registered ==\n";
$routes = array_keys( $server->get_routes() );
$ours   = array_filter( $routes, static fn ( $r ) => str_starts_with( $r, '/storecrew/v1' ) );
$t( 'namespace has routes', count( $ours ) > 10, (string) count( $ours ) );
$t( 'bootstrap route exists', in_array( '/storecrew/v1/bootstrap', $routes, true ) );
$t( 'health route exists', in_array( '/storecrew/v1/health', $routes, true ) );

echo "\n== Permissions deny by default ==\n";
wp_set_current_user( 0 );

foreach ( array( '/bootstrap', '/health', '/providers', '/settings', '/index', '/agents', '/conversations', '/approvals' ) as $path ) {
	[ $status ] = $call( 'GET', '/storecrew/v1' . $path );
	$t( "PROBE: anonymous GET {$path} is denied", 401 === $status || 403 === $status, (string) $status );
}

[ $status ] = $call( 'POST', '/storecrew/v1/index/start' );
$t( 'PROBE: anonymous POST /index/start is denied', 401 === $status || 403 === $status, (string) $status );

[ $status ] = $call( 'POST', '/storecrew/v1/providers/anthropic/key', array( 'key' => 'sk-evil' ) );
$t( 'PROBE: anonymous cannot write an API key', 401 === $status || 403 === $status, (string) $status );

$t(
	'PROBE: the denied key write did not land',
	null === ( new SecretStore() )->get( 'provider.anthropic.key' )
);

// A subscriber has an account but none of our capabilities.
$sub_id = wp_insert_user(
	array(
		'user_login' => 'scr_probe_sub_' . wp_rand( 1000, 9999 ),
		'user_pass'  => wp_generate_password(),
		'role'       => 'subscriber',
	)
);
wp_set_current_user( (int) $sub_id );

[ $status ] = $call( 'GET', '/storecrew/v1/health' );
$t( 'PROBE: a logged-in subscriber is still denied', 403 === $status, (string) $status );

echo "\n== Administrator access ==\n";
wp_set_current_user( $admin_id );

[ $status, $body ] = $call( 'GET', '/storecrew/v1/bootstrap' );
$t( 'bootstrap returns 200', 200 === $status, (string) $status );
$t( 'response is enveloped under data', isset( $body['data'] ) );
$t( 'carries the product version', STORECREW_VERSION === ( $body['data']['version'] ?? '' ) );
$t( 'carries the API contract version', STORECREW_API_VERSION === ( $body['data']['apiVersion'] ?? '' ) );
$t( 'carries the feature manifest', isset( $body['data']['features']['agent.sales'] ) );
$t( 'free features are enabled in the manifest', true === ( $body['data']['features']['agent.sales'] ?? null ) );
$t( 'pro features are locked in the manifest', false === ( $body['data']['features']['agent.marketing'] ?? null ) );
$t( 'carries onboarding state', isset( $body['data']['onboarding']['canEmbed'] ) );
$t(
	'PROBE: onboarding reports embedding unavailable with no key configured',
	false === ( $body['data']['onboarding']['canEmbed'] ?? null )
);

$onboarding = $body['data']['onboarding'] ?? array();
$step_ids   = array_column( $onboarding['steps'] ?? array(), 'id' );
$t(
	'onboarding carries the five steps in order',
	array( 'provider', 'sources', 'index', 'agents', 'widget' ) === $step_ids,
	wp_json_encode( $step_ids )
);
$t(
	'PROBE: with no provider key the blocking step is the provider step',
	'provider' === ( $onboarding['current'] ?? '' ) && false === ( $onboarding['complete'] ?? null ),
	wp_json_encode( array( $onboarding['current'] ?? null, $onboarding['complete'] ?? null ) )
);

echo "\n== Onboarding step events (02 § 7 drop-off) ==\n";

// Wired, not merely built. The recorder writes its ledger on every bootstrap,
// so the option existing after a dispatch is what proves the controller calls
// it — the fourth instance of Gate 2's built-but-unconsumed pattern is what
// this assertion exists to prevent becoming a fifth.
$t(
	'a bootstrap dispatch runs the step recorder',
	false !== get_option( StoreCrew\Core\SetupProgress::OPTION, false )
);

$progress_events = Tables::name( Tables::USAGE_EVENTS );
$count_step      = static function ( string $step ) use ( $wpdb, $progress_events ): int {
	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$progress_events} WHERE metric = %s",
			StoreCrew\Core\SetupProgress::metric( $step )
		)
	);
};

// Drive the recorder directly from here on, with a state of the suite's own
// making. Dispatching /bootstrap again would record whichever steps this
// particular store happens to have finished, so the assertions would say
// something different on every machine — and on a finished store they would
// assert nothing at all.
delete_option( StoreCrew\Core\SetupProgress::OPTION );

$recorder = new StoreCrew\Core\SetupProgress(
	new StoreCrew\Database\Repositories\UsageRepository()
);

$partial = array(
	'steps'    => array(
		array( 'id' => 'provider', 'done' => true ),
		array( 'id' => 'sources', 'done' => true ),
		array( 'id' => 'index', 'done' => false ),
		array( 'id' => 'agents', 'done' => false ),
		array( 'id' => 'widget', 'done' => false ),
	),
	'complete' => false,
);

$provider_before = $count_step( 'provider' );
$index_before    = $count_step( 'index' );

$recorder->observe( $partial );

$t( 'a completed step is recorded', $count_step( 'provider' ) === $provider_before + 1 );
$t( 'an unfinished step is not', $count_step( 'index' ) === $index_before );

// Drop-off is a count of installs that reached a step. A second event from the
// same install would say two merchants got there.
$recorder->observe( $partial );
$recorder->observe( $partial );

$t(
	'PROBE: re-observing the same step records it once',
	$count_step( 'provider' ) === $provider_before + 1,
	(string) $count_step( 'provider' )
);

$partial['steps'][2]['done'] = true;
$recorder->observe( $partial );

$t( 'a step completed later is picked up', $count_step( 'index' ) === $index_before + 1 );

// The install that finished before the instrument existed. Stamping its steps
// now would report a five-second onboarding; unknown has to read as unknown.
delete_option( StoreCrew\Core\SetupProgress::OPTION );

$widget_before = $count_step( 'widget' );

$recorder->observe(
	array(
		'steps'    => array(
			array( 'id' => 'provider', 'done' => true ),
			array( 'id' => 'sources', 'done' => true ),
			array( 'id' => 'index', 'done' => true ),
			array( 'id' => 'agents', 'done' => true ),
			array( 'id' => 'widget', 'done' => true ),
		),
		'complete' => true,
	)
);

$t(
	'PROBE: an already-finished install is marked backfilled, not timed',
	array( StoreCrew\Core\SetupProgress::BACKFILLED ) === $recorder->ledger(),
	wp_json_encode( $recorder->ledger() )
);
$t( 'PROBE: and records no step events it cannot date', $count_step( 'widget' ) === $widget_before );
$onboarding_done = array_column( $onboarding['steps'] ?? array(), 'done', 'id' );
$t(
	'PROBE: the step named as current is the one reporting itself unfinished',
	false === ( $onboarding_done[ $onboarding['current'] ?? '' ] ?? null )
);

[ $status, $body ] = $call( 'GET', '/storecrew/v1/health' );
$t( 'health returns 200', 200 === $status );
$t( 'reports the environment', isset( $body['data']['environment']['php'] ) );
$t( 'reports WooCommerce version', null !== ( $body['data']['environment']['woocommerce'] ?? null ) );
$t( 'reports queue availability', true === ( $body['data']['queue']['available'] ?? null ) );
$t( 'reports index health', isset( $body['data']['index']['chunks'] ) );
$t( 'reports encryption key source', isset( $body['data']['encryption']['source'] ) );

echo "\n== Providers ==\n";
[ $status, $body ] = $call( 'GET', '/storecrew/v1/providers' );
$t( 'lists all five providers', 5 === count( $body['data'] ), (string) count( $body['data'] ) );

$by_id = array_column( $body['data'], null, 'id' );
$t( 'reports Anthropic cannot embed', false === $by_id['anthropic']['capabilities']['embeddings'] );
$t( 'PROBE: reports Anthropic rejects sampling', false === $by_id['anthropic']['capabilities']['sampling'] );
$t( 'reports Gemini has embedding task types', true === $by_id['gemini']['capabilities']['embeddingTaskTypes'] );
$t( 'unconfigured provider has a null key hint', null === $by_id['anthropic']['keyHint'] );

[ $status, $body ] = $call( 'POST', '/storecrew/v1/providers/anthropic/key', array( 'key' => 'sk-ant-probe-secret-123456' ) );
$t( 'saves an API key', 200 === $status, (string) $status );
$t( 'reports it as configured', true === ( $body['data']['configured'] ?? null ) );
$t( 'returns a masked hint', 'sk-…3456' === ( $body['data']['keyHint'] ?? '' ), (string) ( $body['data']['keyHint'] ?? '' ) );

[ , $body ] = $call( 'GET', '/storecrew/v1/providers' );
$serialised = wp_json_encode( $body );
$t(
	'PROBE: the API never returns the key itself',
	! str_contains( $serialised, 'sk-ant-probe-secret-123456' )
);
$t( 'PROBE: not even a fragment beyond the hint', ! str_contains( $serialised, 'probe-secret' ) );

$audit_rows = $GLOBALS['wpdb']->get_col(
	"SELECT data FROM " . Tables::name( Tables::AUDIT_LOG ) . " WHERE action = 'provider.key_saved'"
);
$t(
	'PROBE: the audit log records the action but not the secret',
	array() !== $audit_rows && ! str_contains( implode( '', $audit_rows ), 'sk-ant-probe' )
);

[ $status ] = $call( 'POST', '/storecrew/v1/providers/nonsuch/key', array( 'key' => 'x' ) );
$t( 'unknown provider is a 404', 404 === $status, (string) $status );

[ $status ] = $call( 'POST', '/storecrew/v1/providers/anthropic/key', array() );
$t( 'PROBE: a missing key argument is rejected', 400 === $status, (string) $status );

[ $status ] = $call( 'POST', '/storecrew/v1/providers/anthropic/key', array( 'key' => '   ' ) );
$t( 'PROBE: a whitespace-only key is rejected', 400 === $status, (string) $status );

[ $status ] = $call( 'DELETE', '/storecrew/v1/providers/anthropic/key' );
$t( 'deletes the key', 200 === $status );
$t( 'key really gone', null === ( new SecretStore() )->get( 'provider.anthropic.key' ) );

echo "\n== Settings ==\n";
[ $status, $body ] = $call( 'GET', '/storecrew/v1/settings' );
$t( 'settings returns 200', 200 === $status );
$t( 'exposes the task list', in_array( 'embedding', $body['data']['tasks'] ?? array(), true ) );
$t( 'exposes the pricing verification date', isset( $body['data']['pricing']['ratesVerified'] ) );

[ $status, $body ] = $call(
	'POST',
	'/storecrew/v1/settings',
	array( 'modelPolicy' => array( 'embedding' => array( 'provider' => 'anthropic', 'model' => 'x' ) ) )
);
$t(
	'PROBE: assigning embedding to a chat-only provider is rejected',
	400 === $status,
	(string) $status
);

[ $status ] = $call(
	'POST',
	'/storecrew/v1/settings',
	array( 'modelPolicy' => array( 'chat' => array( 'provider' => 'nonsuch', 'model' => 'x' ) ) )
);
$t( 'unknown provider in a policy is rejected', 400 === $status );

[ $status ] = $call(
	'POST',
	'/storecrew/v1/settings',
	array( 'modelPolicy' => array( 'teleportation' => array( 'provider' => 'openai', 'model' => 'x' ) ) )
);
$t( 'PROBE: an unknown task is rejected, not silently stored', 400 === $status );

[ $status, $body ] = $call(
	'POST',
	'/storecrew/v1/settings',
	array(
		'modelPolicy' => array( 'embedding' => array( 'provider' => 'openai', 'model' => 'text-embedding-3-small' ) ),
		'spend'       => array( 'capMicros' => 5_000_000, 'behaviour' => 'stop' ),
	)
);
$t( 'a valid policy is accepted', 200 === $status, (string) $status );
$t( 'the spend cap is stored', 5_000_000 === ( $body['data']['spend']['capMicros'] ?? 0 ) );

[ $status ] = $call( 'POST', '/storecrew/v1/settings', array( 'spend' => array( 'behaviour' => 'explode' ) ) );
$t( 'an unknown spend behaviour is rejected', 400 === $status );

echo "\n== Index control ==\n";
[ $status, $body ] = $call( 'GET', '/storecrew/v1/index' );
$t( 'index status returns 200', 200 === $status );
$t( 'reports source counts', isset( $body['data']['sources'] ) );
$t( 'reports queue health', isset( $body['data']['queue']['available'] ) );

[ $status, $body ] = $call( 'GET', '/storecrew/v1/index/estimate' );
$t( 'estimate returns 200', 200 === $status );
$t(
	'PROBE: estimate reports cost unknown rather than a fabricated zero',
	false === ( $body['data']['costKnown'] ?? null )
);

[ $status, $body ] = $call( 'POST', '/storecrew/v1/index/start' );
$t( 'starting a run returns 202', 202 === $status, (string) $status );
$run_id = (int) ( $body['data']['runId'] ?? 0 );
$t( 'returns a run id', $run_id > 0 );

[ $status ] = $call( 'POST', '/storecrew/v1/index/start' );
$t( 'PROBE: a concurrent run is refused with 409', 409 === $status, (string) $status );

[ $status ] = $call( 'POST', '/storecrew/v1/index/cancel' );
$t( 'cancelling returns 200', 200 === $status );

[ $status ] = $call( 'POST', '/storecrew/v1/index/cancel' );
$t( 'cancelling nothing is a 409', 409 === $status );

echo "\n== Source selection (FR-ADMIN-02 step 2) ==\n";
[ $status, $body ] = $call( 'GET', '/storecrew/v1/index' );
$available = $body['data']['selection']['available'] ?? array();
$t( 'index status describes the available sources', count( $available ) > 0, wp_json_encode( array_column( $available, 'type' ) ) );
$t( 'each source carries a label and a count', isset( $available[0]['label'], $available[0]['count'] ) );

[ $status ] = $call( 'POST', '/storecrew/v1/index/sources', array( 'nope' => true ) );
$t( 'PROBE: a body without a sources array is rejected', 400 === $status, (string) $status );

// Every available type, deliberately: deselecting one purges what has already
// been read from it, and this suite runs against the merchant's own index.
// The purge itself is probed in verify-knowledge against a synthetic source
// type, where the only rows at risk are the ones the probe created.
$all_types = array_column( $available, 'type' );

[ $status, $body ] = $call( 'POST', '/storecrew/v1/index/sources', array( 'sources' => $all_types ) );
$t( 'saving a selection returns 200', 200 === $status, (string) $status );
$t( 'the selection is echoed back', $all_types === ( $body['data']['selected'] ?? null ), wp_json_encode( $body['data']['selected'] ?? null ) );
$t( 'PROBE: selecting everything removes nothing', array() === ( $body['data']['removed'] ?? null ) && 0 === ( $body['data']['purged']['chunks'] ?? -1 ) );

[ $status, $body ] = $call( 'POST', '/storecrew/v1/index/sources', array( 'sources' => array_merge( $all_types, array( 'invented' ) ) ) );
$t(
	'PROBE: a source type nothing can read is dropped rather than stored',
	$all_types === ( $body['data']['selected'] ?? null ),
	wp_json_encode( $body['data']['selected'] ?? null )
);

[ $status, $body ] = $call( 'GET', '/storecrew/v1/index' );
$t( 'the choice is now on record', true === ( $body['data']['selection']['chosen'] ?? null ) );
$t(
	'the estimate is scoped to the selection',
	array_keys( $call( 'GET', '/storecrew/v1/index/estimate' )[1]['data']['objects'] ?? array() ) === $all_types
);

echo "\n== Agents (FR-ADMIN-02 step 4) ==\n";
[ $status, $body ] = $call( 'GET', '/storecrew/v1/agents' );
$t( 'agents returns 200', 200 === $status, (string) $status );
$roster = $body['data'] ?? array();
$t( 'the roster carries the shipped agents', array( 'sales', 'support' ) === array_column( $roster, 'id' ), wp_json_encode( array_column( $roster, 'id' ) ) );
$t( 'an agent reports its feature and its tools', isset( $roster[0]['feature'], $roster[0]['toolIds'] ) );
$t(
	'PROBE: an agent with no configuration row reads as on, matching the orchestrator',
	true === ( $roster[0]['enabled'] ?? null ) && false === ( $roster[0]['configured'] ?? null )
);

[ $status ] = $call( 'POST', '/storecrew/v1/agents/support', array( 'nothing' => 1 ) );
$t( 'PROBE: a body without an enabled flag is rejected', 400 === $status, (string) $status );

[ $status ] = $call( 'POST', '/storecrew/v1/agents/does-not-exist', array( 'enabled' => true ) );
$t( 'PROBE: an unknown agent is a 404, not a stored row', 404 === $status, (string) $status );

[ $status, $body ] = $call( 'POST', '/storecrew/v1/agents/support', array( 'enabled' => false ) );
$t( 'standing an agent down returns 200', 200 === $status, (string) $status );
$t( 'the response reports it as off', false === ( $body['data']['enabled'] ?? null ) );

$t(
	'PROBE: the orchestrator stops routing to a stood-down agent',
	! array_key_exists( 'support', $c->get( StoreCrew\Agent\Orchestrator::class )->available_agents() )
);

$call( 'POST', '/storecrew/v1/agents/sales', array( 'enabled' => false ) );
[ $status, $body ] = $call( 'GET', '/storecrew/v1/bootstrap' );
$steps_done = array_column( $body['data']['onboarding']['steps'] ?? array(), 'done', 'id' );
$t(
	'PROBE: with nobody on duty the agents step reports itself unfinished',
	false === ( $steps_done['agents'] ?? null ),
	wp_json_encode( $steps_done )
);

$call( 'POST', '/storecrew/v1/agents/sales', array( 'enabled' => true ) );
$call( 'POST', '/storecrew/v1/agents/support', array( 'enabled' => true ) );

[ $status, $body ] = $call( 'GET', '/storecrew/v1/bootstrap' );
$steps_done = array_column( $body['data']['onboarding']['steps'] ?? array(), 'done', 'id' );
$t(
	'putting the crew back on duty closes the agents step again',
	true === ( $steps_done['agents'] ?? null ),
	wp_json_encode( $steps_done )
);
$t(
	'putting it back on duty restores the routing candidate',
	array_key_exists( 'support', $c->get( StoreCrew\Agent\Orchestrator::class )->available_agents() )
);

echo "\n== Knowledge search ==\n";
[ $status, $body ] = $call( 'POST', '/storecrew/v1/knowledge/search', array( 'query' => 'returns policy' ) );
$t( 'search returns 200', 200 === $status, (string) $status );
$t( 'reports the retrieval strategy', isset( $body['data']['strategy'] ) );
$t(
	'PROBE: search reports degradation when no embedding provider is live',
	'' !== ( $body['data']['degraded'] ?? '' ),
	(string) ( $body['data']['degraded'] ?? '' )
);

[ $status ] = $call( 'POST', '/storecrew/v1/knowledge/search', array( 'query' => '  ' ) );
$t( 'an empty query is rejected', 400 === $status );

echo "\n== Conversations and approvals ==\n";
$conversations = $c->get( ConversationRepository::class );
$calls_repo    = $c->get( ToolCallRepository::class );

$uuid = $conversations->start( 'sess_rest_probe', 0, 'widget' );
$conv = $conversations->find_by_uuid( (string) $uuid );

[ $status, $body ] = $call( 'GET', '/storecrew/v1/conversations' );
$t( 'lists conversations', 200 === $status );
$t( 'the probe conversation is listed', in_array( $uuid, array_column( $body['data'], 'uuid' ), true ) );
$t(
	'PROBE: internal auto-increment ids are never exposed',
	! array_key_exists( 'id', $body['data'][0] ?? array() )
);

[ $status, $body ] = $call( 'GET', '/storecrew/v1/conversations/' . $uuid );
$t( 'fetches one conversation by uuid', 200 === $status );
$t( 'includes turns and runs', isset( $body['data']['turns'], $body['data']['runs'] ) );

[ $status ] = $call( 'GET', '/storecrew/v1/conversations/00000000-0000-0000-0000-000000000000' );
$t( 'an unknown uuid is a 404', 404 === $status, (string) $status );

[ $status ] = $call( 'GET', '/storecrew/v1/conversations/not-a-uuid' );
$t( 'PROBE: a malformed uuid does not match the route at all', 404 === $status );

$call_id = $calls_repo->record(
	0,
	(int) $conv->id,
	'coupon.create',
	array( 'amount' => 10 ),
	ToolCallRepository::INTENT_WRITE,
	ToolCallRepository::AUTH_REQUIRED
);

[ $status, $body ] = $call( 'GET', '/storecrew/v1/approvals' );
$t( 'lists pending approvals', 200 === $status );
$t( 'the pending write is listed', in_array( $call_id, array_column( $body['data'], 'id' ), true ) );

[ $status ] = $call( 'POST', '/storecrew/v1/approvals/' . $call_id, array( 'decision' => 'sideways' ) );
$t( 'PROBE: an invalid decision is rejected', 400 === $status, (string) $status );

[ $status ] = $call( 'POST', '/storecrew/v1/approvals/' . $call_id, array( 'decision' => 'approve' ) );
$t( 'approving returns 200', 200 === $status );

[ $status ] = $call( 'POST', '/storecrew/v1/approvals/' . $call_id, array( 'decision' => 'approve' ) );
$t( 'PROBE: approving twice is a 409, not a silent success', 409 === $status, (string) $status );

echo "\n== Registry ==\n";
$registry = $c->get( ControllerRegistry::class );
$t( 'nine controllers registered', 9 === count( $registry->all() ), (string) count( $registry->all() ) );
$t( 'the registry is frozen', $registry->is_frozen() );
$t( 'ownership is tracked', 'storecrew' === $registry->owner( 'health' ) );
$t(
	'PROBE: controllers are stored as factories, not instances',
	is_callable( $registry->get( 'health' ) ) && ! ( $registry->get( 'health' ) instanceof RestController )
);

// A factory returning the wrong thing must cost only its own routes.
$broken_reported = false;
add_action( 'storecrew_rest_controller_failed', static function () use ( &$broken_reported ): void { $broken_reported = true; } );

$isolated = new ControllerRegistry();
$isolated->register( 'broken', static fn () => 'not a controller' );
$isolated->register( 'fine', static fn (): RestController => new class( $c->get( StoreCrew\Licensing\FeatureGate::class ) ) extends RestController {
	public bool $ran = false;
	public function register_routes(): void { $this->ran = true; }
} );
$isolated->register_routes();
$t( 'PROBE: a broken controller is reported, not fatal', $broken_reported );

// A frozen registry throws under WP_DEBUG and logs-and-ignores in production,
// so the throw is environment-dependent. The invariant that holds in both — and
// the one that actually matters — is that the late item is never registered.
$threw    = false;
$rejected = false;

add_action( 'storecrew_registry_rejected', static function () use ( &$rejected ): void { $rejected = true; } );

try {
	$registry->register(
		'late',
		static fn (): RestController => new class( StoreCrew\Plugin::instance()->container()->get( StoreCrew\Licensing\FeatureGate::class ) ) extends RestController {
			public function register_routes(): void {}
		}
	);
} catch ( LogicException ) {
	$threw = true;
}

$t( 'PROBE: a late controller is never registered', ! $registry->has( 'late' ) );
$t(
	'PROBE: the rejection is surfaced, not silent',
	$threw || $rejected,
	'WP_DEBUG=' . ( defined( 'WP_DEBUG' ) && WP_DEBUG ? 'on' : 'off' )
);

echo "\n== Cleanup ==\n";
wp_set_current_user( $original );

$calls_repo->delete( $call_id );
$conversations->delete( (int) $conv->id );
wp_delete_user( (int) $sub_id );

$GLOBALS['wpdb']->query(
	// Includes the agent enable/disable audit rows the toggle probes write —
	// without them, ~4 `agent.*` rows survived every run.
	"DELETE FROM " . Tables::name( Tables::AUDIT_LOG ) . " WHERE action LIKE 'provider.key_%' OR action = 'settings.updated' OR action IN ( 'agent.enabled', 'agent.disabled' )"
);
// Only the run this suite started — deleting every `type = 'full'` row would
// erase the merchant's real full-index history alongside the probe's.
if ( $run_id > 0 ) {
	$GLOBALS['wpdb']->query( $GLOBALS['wpdb']->prepare( 'DELETE FROM ' . Tables::name( Tables::INDEX_RUNS ) . ' WHERE id = %d', $run_id ) );
}
$restore = static function ( string $option, $value ): void {
	if ( false === $value ) {
		delete_option( $option );
	} else {
		update_option( $option, $value, false );
	}
};
$restore( StoreCrew\Ai\ModelPolicy::OPTION, $saved_policy );
$restore( StoreCrew\Ai\SpendGuard::OPTION_CAP_MICROS, $saved_cap );
$restore( StoreCrew\Ai\SpendGuard::OPTION_ON_BREACH, $saved_breach );

// Called here as well as registered for shutdown. Measured, not assumed: under
// `wp eval-file` a shutdown function registered by the suite does *not* run
// after a fatal — WordPress's own fatal handler is registered first and ends
// the request — though it does run on the `exit(1)` a failing suite takes.
// Plain PHP runs it in both cases, which is where the assumption came from.
$restore_progress();

$t(
	'step-event probes left no rows behind',
	0 === (int) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT COUNT(*) FROM ' . Tables::name( Tables::USAGE_EVENTS ) . ' WHERE id > %d AND metric LIKE %s',
			$events_before,
			$wpdb->esc_like( StoreCrew\Database\Repositories\UsageRepository::METRIC_SETUP_STEP . '.' ) . '%'
		)
	)
);

if ( false === $saved_sources ) {
	delete_option( StoreCrew\Knowledge\SourceSelection::OPTION );
} else {
	update_option( StoreCrew\Knowledge\SourceSelection::OPTION, $saved_sources, true );
}

foreach ( $saved_agents as $probe_agent => $before ) {
	if ( null === $before ) {
		$agent_configs->delete_for_agent( $probe_agent );
	} else {
		$agent_configs->set_enabled( $probe_agent, $before['enabled'] );
	}
}

$t(
	'PROBE: no agent this suite touched is left stood down',
	array_key_exists( 'support', $c->get( StoreCrew\Agent\Orchestrator::class )->available_agents() )
);
$c->get( StoreCrew\Core\Queue\Scheduler::class )->cancel();

$t( 'probe conversation removed', null === $conversations->find_by_uuid( (string) $uuid ) );
$t( 'no provider key left behind', null === ( new SecretStore() )->get( 'provider.anthropic.key' ) );

// Asserted first, restored second: the assertion proves this suite's own key
// probes cleaned up; the restore hands the merchant back their real keys.
$restore( StoreCrew\Security\SecretStore::OPTION_SECRETS, $saved_secrets );

echo "\n" . str_repeat( '-', 60 ) . "\n";
printf( "%d passed, %d failed\n", $pass, $fail );

if ( $fail > 0 ) {
	exit( 1 );
}
