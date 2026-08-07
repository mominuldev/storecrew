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

foreach ( array( '/bootstrap', '/health', '/providers', '/settings', '/index', '/conversations', '/approvals' ) as $path ) {
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
$t( 'seven controllers registered', 7 === count( $registry->all() ), (string) count( $registry->all() ) );
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
	"DELETE FROM " . Tables::name( Tables::AUDIT_LOG ) . " WHERE action LIKE 'provider.key_%' OR action = 'settings.updated'"
);
$GLOBALS['wpdb']->query( "DELETE FROM " . Tables::name( Tables::INDEX_RUNS ) . " WHERE type = 'full'" );
delete_option( StoreCrew\Ai\ModelPolicy::OPTION );
delete_option( StoreCrew\Ai\SpendGuard::OPTION_CAP_MICROS );
delete_option( StoreCrew\Ai\SpendGuard::OPTION_ON_BREACH );
$c->get( StoreCrew\Core\Queue\Scheduler::class )->cancel();

$t( 'probe conversation removed', null === $conversations->find_by_uuid( (string) $uuid ) );
$t( 'no provider key left behind', null === ( new SecretStore() )->get( 'provider.anthropic.key' ) );

echo "\n" . str_repeat( '-', 60 ) . "\n";
printf( "%d passed, %d failed\n", $pass, $fail );

if ( $fail > 0 ) {
	exit( 1 );
}
