<?php
/**
 * Boots both plugins with WooCommerce present and asserts the handshake,
 * registration window, freeze, and entitlement gating all behave.
 */

require __DIR__ . '/wp-shim.php';

define( 'WC_VERSION', '9.5.0' );
define( 'WP_DEBUG', true );

// tests/integration -> tests -> storecrew -> plugins
$plugins = dirname( __DIR__, 3 );

echo "\n== Loading plugin files (Pro FIRST, to prove load order does not matter) ==\n";
require $plugins . '/storecrew-pro/storecrew-pro.php';
require $plugins . '/storecrew/storecrew.php';
t( 'Pro loaded before Free without fatal', true );

echo "\n== Firing plugins_loaded ==\n";
do_action( 'plugins_loaded' );

$notices = scr_collect_notices();
t( 'No admin notice raised', '' === $notices, $notices );

$plugin = StoreCrew\Plugin::instance();
$api    = $plugin->api();

echo "\n== Extension API ==\n";
t( 'API version is 1.0', '1.0' === $api->version(), $api->version() );
t( 'storecrew_api_ready fired', has_action( 'storecrew_api_ready' ) );

echo "\n== Registration ==\n";
$features = $api->features();
$routes   = $api->admin_routes();

t( 'Free registered 4 features', 4 === count( $features->owned_by( 'storecrew' ) ), (string) count( $features->owned_by( 'storecrew' ) ) );
t( 'Pro registered 6 features', 6 === count( $features->owned_by( 'storecrew-pro' ) ), (string) count( $features->owned_by( 'storecrew-pro' ) ) );
t( 'Pro registered 3 admin routes', 3 === count( $routes->owned_by( 'storecrew-pro' ) ) );
t( 'Ownership tracked', 'storecrew-pro' === $features->owner( 'agent.marketing' ) );
t( 'Free owns agent.sales', 'storecrew' === $features->owner( 'agent.sales' ) );

echo "\n== Freeze ==\n";
t( 'Feature registry frozen', $features->is_frozen() );
t( 'Route registry frozen', $routes->is_frozen() );

// PROBE: a guard that never fires is not a guard. Violate it deliberately.
$threw = false;
try {
	$features->register( new StoreCrew\Api\Feature( 'late.feature', 'Late' ), 'late-addon' );
} catch ( LogicException $e ) {
	$threw = true;
}
t( 'PROBE: late registration throws under WP_DEBUG', $threw );
t( 'PROBE: late feature was not registered', ! $features->has( 'late.feature' ) );

// PROBE: duplicate id must be rejected.
$dupe = false;
try {
	$features->add( 'agent.sales', new StoreCrew\Api\Feature( 'agent.sales', 'Hijack' ), 'evil-addon' );
} catch ( LogicException $e ) {
	$dupe = true;
}
t( 'PROBE: duplicate registration rejected', $dupe );

echo "\n== Entitlement — no licence ==\n";
$gate = $plugin->container()->get( StoreCrew\Licensing\FeatureGate::class );

t( 'agent.sales enabled (free tier)', $gate->enabled( 'agent.sales' ) );
t( 'agent.support enabled (free tier)', $gate->enabled( 'agent.support' ) );
t( 'agent.marketing DISABLED without licence', ! $gate->enabled( 'agent.marketing' ) );
t( 'workflow.builder DISABLED without licence', ! $gate->enabled( 'workflow.builder' ) );
t( 'agency.multisite DISABLED without licence', ! $gate->enabled( 'agency.multisite' ) );
t( 'unregistered slug denied', ! $gate->enabled( 'does.not.exist' ) );

echo "\n== Manifest ==\n";
$manifest = $gate->manifest();
t( 'Manifest exposes features map', isset( $manifest['features'] ) && count( $manifest['features'] ) === 10 );
t( 'Manifest marks pro route locked', (function () use ( $manifest ) {
	foreach ( $manifest['routes'] as $r ) {
		if ( '/marketing' === $r['path'] ) {
			return true === $r['locked'];
		}
	}
	return false;
})() );

echo "\n== Manifest respects capabilities ==\n";
// Regression: the shim used to grant every capability unconditionally, which
// hid the fact that manifest() filters routes by capability. Real WP-CLI has no
// current user and returned an empty route list while this suite stayed green.
$GLOBALS['scr_caps'] = false;
$gate_anon = new StoreCrew\Licensing\FeatureGate( $features, $routes );
t( 'no capabilities => no routes exposed', 0 === count( $gate_anon->manifest()['routes'] ) );

$GLOBALS['scr_caps'] = array( 'storecrew_manage' );
$gate_partial = new StoreCrew\Licensing\FeatureGate( $features, $routes );
$partial_paths = array_column( $gate_partial->manifest()['routes'], 'path' );
t( '/marketing visible with storecrew_manage', in_array( '/marketing', $partial_paths, true ) );
t( '/analytics hidden without storecrew_view_analytics', ! in_array( '/analytics', $partial_paths, true ) );

$GLOBALS['scr_caps'] = true;

echo "\n== Entitlement — Pro licence active ==\n";
// Fresh gate: the real one memoises per request, which is correct behaviour.
update_option( StoreCrew\Pro\Licence::OPTION_STATUS, StoreCrew\Pro\Licence::STATUS_ACTIVE );
update_option( StoreCrew\Pro\Licence::OPTION_TIER, StoreCrew\Api\Feature::TIER_PRO );

$gate2 = new StoreCrew\Licensing\FeatureGate( $features, $routes );

t( 'agent.marketing ENABLED with pro licence', $gate2->enabled( 'agent.marketing' ) );
t( 'agent.analytics ENABLED with pro licence', $gate2->enabled( 'agent.analytics' ) );
t( 'agency.multisite still DISABLED on pro tier', ! $gate2->enabled( 'agency.multisite' ) );
t( 'free features unaffected', $gate2->enabled( 'agent.sales' ) );

echo "\n== Entitlement — Agency licence ==\n";
update_option( StoreCrew\Pro\Licence::OPTION_TIER, StoreCrew\Api\Feature::TIER_AGENCY );
$gate3 = new StoreCrew\Licensing\FeatureGate( $features, $routes );
t( 'agency.multisite ENABLED on agency tier', $gate3->enabled( 'agency.multisite' ) );
t( 'pro features included in agency tier', $gate3->enabled( 'agent.marketing' ) );

echo "\n== Licence lapse must not revoke free features ==\n";
update_option( StoreCrew\Pro\Licence::OPTION_STATUS, StoreCrew\Pro\Licence::STATUS_INVALID );
$gate4 = new StoreCrew\Licensing\FeatureGate( $features, $routes );
t( 'agent.sales survives licence lapse', $gate4->enabled( 'agent.sales' ) );
t( 'agent.marketing revoked on lapse', ! $gate4->enabled( 'agent.marketing' ) );

echo "\n== Container ==\n";
$c = $plugin->container();
t( 'Container returns singletons', $c->get( StoreCrew\Api\Registry\FeatureRegistry::class ) === $c->get( StoreCrew\Api\Registry\FeatureRegistry::class ) );

$circular = false;
$c->set( 'a', fn ( $c ) => $c->get( 'b' ) );
$c->set( 'b', fn ( $c ) => $c->get( 'a' ) );
try {
	$c->get( 'a' );
} catch ( StoreCrew\Core\Container\ContainerException $e ) {
	$circular = str_contains( $e->getMessage(), 'circular' );
}
t( 'PROBE: circular dependency detected', $circular );

$notfound = false;
try {
	$c->get( 'nope' );
} catch ( StoreCrew\Core\Container\NotFoundException $e ) {
	$notfound = true;
}
t( 'PROBE: unknown service throws NotFound', $notfound );

scr_summary();
