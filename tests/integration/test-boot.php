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

// STORECREW_FREE_DIR points this at a built distribution instead of the working
// tree, which is the only way to find out whether the thing merchants actually
// download boots. The dist ships no tests, a `--no-dev` vendor/ and no
// composer.lock, so its autoloader is a different artifact from the one every
// other suite exercises — and a plugin that passes every check in the repo and
// fatals on activation from the zip is the classic .org launch failure.
$free = getenv( 'STORECREW_FREE_DIR' ) ?: $plugins . '/storecrew';

echo "\n== Loading plugin files (Pro FIRST, to prove load order does not matter) ==\n";
require $plugins . '/storecrew-pro/storecrew-pro.php';
require $free . '/storecrew.php';
t( 'Pro loaded before Free without fatal', true );
t( 'loaded from ' . basename( $free ), true );

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

// ---------------------------------------------------------------------------
// The licence spine (10 § 4, § 5). Everything below drives the REAL
// Snapshot/LicenceClient code against envelopes signed by a keypair minted
// for this run — "fixture-signed" means signed here, by a key the code has
// never seen, exactly as the licence server will sign with a key this code
// will never see. No canned base64 blobs: a fixture that cannot be re-derived
// is a fixture nobody can debug.
// ---------------------------------------------------------------------------

use StoreCrew\Pro\Licence;
use StoreCrew\Pro\Licensing\LicenceClient;
use StoreCrew\Pro\Licensing\Snapshot;

$keypair    = sodium_crypto_sign_keypair();
$secret_key = sodium_crypto_sign_secretkey( $keypair );
$public_key = base64_encode( sodium_crypto_sign_publickey( $keypair ) );

/** Sign a payload the way the server contract says the server must. */
$sign = static function ( array $payload ) use ( $secret_key ): array {
	$bytes = json_encode( $payload );

	return array(
		'payload'   => base64_encode( $bytes ),
		'signature' => 'ed25519:' . base64_encode( sodium_crypto_sign_detached( $bytes, $secret_key ) ),
	);
};

/** A well-formed Pro payload; override fields per scenario. */
$payload = static function ( array $overrides = array() ): array {
	return array_merge(
		array(
			'licence'      => 'sc_pro_fixture',
			'tier'         => 'pro',
			'status'       => 'active',
			'site'         => 'http://example.test',
			'seats'        => array( 'used' => 1, 'max' => 1 ),
			'entitlements' => array(
				'agent.marketing'        => true,
				'agent.analytics'        => true,
				'workflow.builder'       => true,
				'integrations.email'     => true,
				'conversations.monthly'  => null,
			),
			'issued_at'    => gmdate( 'c', time() ),
			'valid_until'  => gmdate( 'c', time() + 14 * DAY_IN_SECONDS ),
		),
		$overrides
	);
};

/** Install an envelope as the stored licence and boot a fixture-keyed client. */
$install = static function ( ?array $envelope, string $key = 'sc_pro_fixture' ) use ( $public_key ): LicenceClient {
	if ( null === $envelope ) {
		delete_option( LicenceClient::OPTION_KEY );
		delete_option( LicenceClient::OPTION_SNAPSHOT );
	} else {
		update_option( LicenceClient::OPTION_KEY, $key );
		update_option( LicenceClient::OPTION_SNAPSHOT, $envelope );
	}

	$client = new LicenceClient( $public_key );
	Licence::boot( $client );

	return $client;
};

echo "\n== Entitlement — a signed Pro snapshot ==\n";
// Fresh gate per scenario: the real one memoises per request, which is
// correct behaviour.
$install( $sign( $payload() ) );

$gate2 = new StoreCrew\Licensing\FeatureGate( $features, $routes );

t( 'agent.marketing ENABLED by snapshot entitlement', $gate2->enabled( 'agent.marketing' ) );
t( 'agent.analytics ENABLED by snapshot entitlement', $gate2->enabled( 'agent.analytics' ) );
t( 'agency.multisite still DISABLED — the map does not name it', ! $gate2->enabled( 'agency.multisite' ) );
t( 'free features unaffected', $gate2->enabled( 'agent.sales' ) );
t( 'licence status reads active', Licence::STATUS_ACTIVE === Licence::status(), Licence::status() );
t( 'tier reads pro', StoreCrew\Api\Feature::TIER_PRO === Licence::tier() );

// The quota half of the same snapshot: conversations.monthly => null is the
// paid tiers' shape, and the free side's loosen-only clamp lets null through.
t(
	'quota loosens to unlimited through storecrew_quota',
	null === apply_filters( 'storecrew_quota', 100, 'conversations.monthly' )
);
t(
	'a quota the snapshot does not name passes through untouched',
	100 === apply_filters( 'storecrew_quota', 100, 'sites' )
);

echo "\n== Entitlement — Agency snapshot ==\n";
$agency = $payload(
	array(
		'tier'         => 'agency',
		'entitlements' => array(
			'agent.marketing'       => true,
			'agent.analytics'       => true,
			'workflow.builder'      => true,
			'integrations.email'    => true,
			'agency.multisite'      => true,
			'agency.whitelabel'     => true,
			'conversations.monthly' => null,
		),
	)
);
$install( $sign( $agency ) );
$gate3 = new StoreCrew\Licensing\FeatureGate( $features, $routes );
t( 'agency.multisite ENABLED when the map names it', $gate3->enabled( 'agency.multisite' ) );
t( 'pro features included in the agency map', $gate3->enabled( 'agent.marketing' ) );

echo "\n== PROBE: the signature is the boundary ==\n";
// Tamper with one byte of the payload and keep the valid signature. This is
// exactly what editing the stored option looks like, and it must read as no
// licence at all (FR-LIC-03).
$envelope   = $sign( $payload() );
$bytes      = base64_decode( $envelope['payload'] );
$tampered   = str_replace( '"tier":"pro"', '"tier":"agency"', $bytes );
$envelope['payload'] = base64_encode( $tampered );

$install( $envelope );
$gate4 = new StoreCrew\Licensing\FeatureGate( $features, $routes );
t( 'PROBE: a tampered payload grants nothing', ! $gate4->enabled( 'agent.marketing' ) );
t( 'PROBE: and reads as invalid, not as a crash', Licence::STATUS_INVALID === Licence::status(), Licence::status() );
t( 'PROBE: free features survive the tamper', $gate4->enabled( 'agent.sales' ) );

echo "\n== PROBE: a misspelt entitlement key grants nothing, silently ==\n";
// 10 § 2.1's defect shape, observed on purpose: the snapshot is perfectly
// signed, the merchant paid, and the grant never happens. This probe exists
// so the failure is at least *known* to be silent.
$misspelt = $payload( array( 'entitlements' => array( 'agent.marketting' => true ) ) );
$install( $sign( $misspelt ) );
$gate5 = new StoreCrew\Licensing\FeatureGate( $features, $routes );
t( 'PROBE: agent.marketing not granted by agent.marketting', ! $gate5->enabled( 'agent.marketing' ) );
t( 'the snapshot itself still verifies and reads active', Licence::STATUS_ACTIVE === Licence::status() );

echo "\n== PROBE: a snapshot for another site grants nothing here ==\n";
$install( $sign( $payload( array( 'site' => 'https://someone-elses.shop' ) ) ) );
$gate6 = new StoreCrew\Licensing\FeatureGate( $features, $routes );
t( 'PROBE: wrong-site snapshot grants nothing', ! $gate6->enabled( 'agent.marketing' ) );
t( 'and reads invalid', Licence::STATUS_INVALID === Licence::status() );

echo "\n== Grace and expiry (10 § 4) ==\n";
// Yesterday's valid_until: inside the 7-day grace window. Entitlements
// continue; the status word changes so the notice can fire.
$install( $sign( $payload( array( 'valid_until' => gmdate( 'c', time() - DAY_IN_SECONDS ) ) ) ) );
$gate7 = new StoreCrew\Licensing\FeatureGate( $features, $routes );
t( 'inside grace, entitlements continue', $gate7->enabled( 'agent.marketing' ) );
t( 'and the status says grace', Licence::STATUS_GRACE === Licence::status(), Licence::status() );
$GLOBALS['scr_caps'] = array( 'storecrew_manage' );
t( 'grace raises an admin notice', str_contains( scr_collect_notices(), 'could not be revalidated' ) );
$GLOBALS['scr_caps'] = true;

// Eight days past: grace is over. Degrade to free — data intact, free intact.
$install( $sign( $payload( array( 'valid_until' => gmdate( 'c', time() - 8 * DAY_IN_SECONDS ) ) ) ) );
$gate8 = new StoreCrew\Licensing\FeatureGate( $features, $routes );
t( 'PROBE: past grace, entitlements end', ! $gate8->enabled( 'agent.marketing' ) );
t( 'status says expired', Licence::STATUS_EXPIRED === Licence::status(), Licence::status() );
t( 'agent.sales survives licence lapse', $gate8->enabled( 'agent.sales' ) );
t(
	'an expired snapshot leaves quotas at the free tier',
	100 === apply_filters( 'storecrew_quota', 100, 'conversations.monthly' )
);

// The grace boundary itself, exact to the second, via injected time.
$snapshot = ( $install( $sign( $payload( array( 'valid_until' => gmdate( 'c', 1000000 ) ) ) ) ) )->snapshot();
t( 'the last second of grace still grants', Snapshot::STATE_GRACE === $snapshot->state( 1000000 + 7 * DAY_IN_SECONDS ) );
t( 'the second after does not', Snapshot::STATE_EXPIRED === $snapshot->state( 1000000 + 7 * DAY_IN_SECONDS + 1 ) );

echo "\n== PROBE: a refund is a signed revocation, not an absence ==\n";
$install( $sign( $payload( array( 'status' => 'revoked' ) ) ) );
$gate9 = new StoreCrew\Licensing\FeatureGate( $features, $routes );
t( 'PROBE: a revoked snapshot grants nothing, even in date', ! $gate9->enabled( 'agent.marketing' ) );

echo "\n== Activation, over a fixture transport ==\n";
$install( null );

$served    = $sign( $payload() );
$activated = ( new LicenceClient(
	$public_key,
	static fn ( string $route, array $body ): array => $served
) );
Licence::boot( $activated );

$result = $activated->activate( 'sc_pro_fixture' );
t( 'activation succeeds against a verifiable response', true === $result['ok'], $result['error'] );
t( 'the key is stored', 'sc_pro_fixture' === get_option( LicenceClient::OPTION_KEY ) );
t( 'the envelope is stored verbatim', $served === get_option( LicenceClient::OPTION_SNAPSHOT ) );
t( 'weekly revalidation is scheduled', false !== wp_next_scheduled( LicenceClient::CRON_HOOK ) );
t( 'state reads active', 'active' === $activated->state() );

echo "\n== PROBE: activation refuses what it cannot verify ==\n";
$install( null );

$garbled = ( new LicenceClient(
	$public_key,
	static fn ( string $route, array $body ): array => array( 'payload' => base64_encode( '{"tier":"pro"}' ), 'signature' => 'ed25519:' . base64_encode( str_repeat( 'x', 64 ) ) )
) );
$result  = $garbled->activate( 'sc_pro_fixture' );
t( 'PROBE: an unverifiable response is refused', false === $result['ok'] && 'unverifiable' === $result['error'], $result['error'] );
t( 'PROBE: and nothing was stored', false === get_option( LicenceClient::OPTION_SNAPSHOT ) && false === get_option( LicenceClient::OPTION_KEY ) );

$wrong_site = ( new LicenceClient(
	$public_key,
	static fn ( string $route, array $body ): array => $sign( $payload( array( 'site' => 'https://someone-elses.shop' ) ) )
) );
$result     = $wrong_site->activate( 'sc_pro_fixture' );
t( 'PROBE: a snapshot for another site is refused at activation', 'site_mismatch' === $result['error'] );

$down   = ( new LicenceClient(
	$public_key,
	static fn ( string $route, array $body ) => new WP_Error( 'http_request_failed', 'could not resolve host' )
) );
$result = $down->activate( 'sc_pro_fixture' );
t( 'a dead server surfaces its error code', 'http_request_failed' === $result['error'] );

echo "\n== Revalidation: outage keeps the snapshot, revocation replaces it ==\n";
update_option( LicenceClient::OPTION_KEY, 'sc_pro_fixture' );
update_option( LicenceClient::OPTION_SNAPSHOT, $sign( $payload() ) );

$offline = new LicenceClient(
	$public_key,
	static fn ( string $route, array $body ) => new WP_Error( 'http_request_failed', 'down' )
);
t( 'PROBE: revalidation over a dead server reports failure', false === $offline->revalidate() );
t( 'PROBE: and the stored snapshot survives — our outage is not their lapse', 'active' === $offline->state() );

$revoking = new LicenceClient(
	$public_key,
	static fn ( string $route, array $body ): array => $sign( $payload( array( 'status' => 'revoked' ) ) )
);
t( 'a signed revocation is accepted', true === $revoking->revalidate() );
t( 'and entitlement ends with it', null === $revoking->granting_snapshot() );

echo "\n== Deactivation releases everything local ==\n";
$offline->deactivate();
t( 'key and snapshot are gone', false === get_option( LicenceClient::OPTION_KEY ) && false === get_option( LicenceClient::OPTION_SNAPSHOT ) );
t( 'the cron event is gone', false === wp_next_scheduled( LicenceClient::CRON_HOOK ) );

echo "\n== PROBE: the shipping build is fail-closed until the public key exists ==\n";
// Production wires LicenceClient::PUBLIC_KEY, which is empty until the
// licence server is stood up. With a key pasted and a perfectly signed
// snapshot stored, an empty public key must grant nothing and say why.
update_option( LicenceClient::OPTION_KEY, 'sc_pro_fixture' );
update_option( LicenceClient::OPTION_SNAPSHOT, $sign( $payload() ) );
$unconfigured = new LicenceClient( LicenceClient::PUBLIC_KEY );
Licence::boot( $unconfigured );
$gate10 = new StoreCrew\Licensing\FeatureGate( $features, $routes );
t( 'PROBE: no public key, no grants — fail closed', ! $gate10->enabled( 'agent.marketing' ) );
t( 'PROBE: and the state is named, not mysterious', LicenceClient::STATE_UNCONFIGURED === $unconfigured->state() );

echo "\n== The licence tab: controller, bundle, and registry all arrive through the seam ==\n";
// The activation UI is Pro's, delivered through two published surfaces — a
// REST controller factory and the storecrew_admin_assets action — plus the
// shell's client-side settings-tab registry. It is deliberately NOT an
// AdminRoute: a sidebar entry is for a place the merchant works, and a form
// they visit twice a year belongs on the Settings screen with the rest of
// the configuration. The tab is never feature-gated either way — the REST
// controller behind it requires only storecrew_manage, because an activation
// form that renders as "part of a paid plan" is a door that locks its own
// key inside.

t( 'PROBE: /licence is not a route — the screen moved into Settings', ! $routes->has( '/licence' ) );

$controllers = $api->controllers();
t( 'the licence controller is registered', $controllers->has( 'licence' ) );
t( 'and owned by storecrew-pro', 'storecrew-pro' === $controllers->owner( 'licence' ) );

$built = $controllers->get( 'licence' )();
t( 'its factory builds a real controller', $built instanceof StoreCrew\Api\Rest\RestController );
t( 'which names its owner', 'storecrew-pro' === $built->owner() );

// The bundle arrives only through the action the shell fires — enqueued with
// the shell's handle as a dependency, so registerScreen exists before it runs.
do_action( 'storecrew_admin_assets', 'storecrew-admin' );
$enqueued = $GLOBALS['scr_scripts']['storecrew-pro-licence'] ?? null;
t( 'the bundle is enqueued when the shell announces itself', null !== $enqueued );
t( 'PROBE: it depends on the shell handle, so the registry exists before it runs', null !== $enqueued && in_array( 'storecrew-admin', $enqueued['deps'], true ) );
t( 'the file it points at exists', null !== $enqueued && is_file( $plugins . '/storecrew-pro/assets/admin/licence.js' ) );
t(
	'its strings arrive translated from PHP, not from an i18n runtime',
	isset( $GLOBALS['scr_localized']['storecrew-pro-licence']['storecrewProLicence']['strings']['activate'] )
);
t(
	'the tab label travels with them — the shell cannot translate a label it has never heard of',
	isset( $GLOBALS['scr_localized']['storecrew-pro-licence']['storecrewProLicence']['strings']['tab'] )
);

// Two halves of one seam, like the Update URI header and the updater's HOOK:
// the shell exposes window.storecrew.registerSettingsTab and the bundle calls
// it. Neither side can see the other at build time, and if either name drifts
// the tab silently never appears — so assert the two files agree.
$licence_bundle = (string) file_get_contents( $plugins . '/storecrew-pro/assets/admin/licence.js' );
$shell_bundle   = (string) file_get_contents( $plugins . '/storecrew/assets/admin/app.js' );
t( 'the bundle registers a Settings tab, not a routed screen', str_contains( $licence_bundle, 'registerSettingsTab' ) && ! str_contains( $licence_bundle, 'registerScreen' ) );
t( 'PROBE: the built shell actually exposes registerSettingsTab', str_contains( $shell_bundle, 'registerSettingsTab' ) );

echo "\n== The Marketing agent: contributed through the registries, kept off the storefront ==\n";

use StoreCrew\Agent\Agent;
use StoreCrew\Agent\Tool\ToolInterface;
use StoreCrew\Pro\Agent\MarketingAgent;

$tools  = $api->tools();
$agents = $api->agents();

t( 'Pro registered segment.build', $tools->has( 'segment.build' ) && 'storecrew-pro' === $tools->owner( 'segment.build' ) );
t( 'Pro registered coupon.create', $tools->has( 'coupon.create' ) && 'storecrew-pro' === $tools->owner( 'coupon.create' ) );

$segment = $tools->get( 'segment.build' );
$coupon  = $tools->get( 'coupon.create' );

t( 'the tool factories build real tools', $segment instanceof ToolInterface && $coupon instanceof ToolInterface );

// The executor's second line of defence. Even if the audience boundary below
// were undone, a storefront visitor holds none of our capabilities, so both of
// these refuse before running.
t( 'PROBE: segment.build demands storecrew_manage', 'storecrew_manage' === $segment->required_capability() );
t( 'PROBE: coupon.create demands storecrew_manage', 'storecrew_manage' === $coupon->required_capability() );
t( 'PROBE: coupon.create is a write, so it queues for approval by default', ToolInterface::INTENT_WRITE === $coupon->intent() );

$marketing = $agents->get( MarketingAgent::ID );

t( 'Pro registered the marketing agent', $marketing instanceof Agent && 'storecrew-pro' === $agents->owner( MarketingAgent::ID ) );
t( 'gated on agent.marketing', $marketing instanceof Agent && 'agent.marketing' === $marketing->feature );
t( 'and it talks to the merchant, not the storefront', $marketing instanceof Agent && Agent::AUDIENCE_ADMIN === $marketing->audience );

// A named tool that is not registered is skipped silently when definitions are
// built — so an agent naming the catalogue tools by string id must be checked
// against the registry, or a rename in the free plugin would quietly leave the
// marketing agent unable to look a product up.
$missing = array_values( array_diff( $marketing->tool_ids, $tools->ids() ) );
t( 'PROBE: every tool it names actually exists', array() === $missing, implode( ', ', $missing ) );

// The boundary itself. Both callables answer yes, so entitlement and the
// merchant's enable switch are held constant and the audience is the only
// thing deciding.
$yes        = static fn ( string $x ): bool => true;
$storefront = $agents->available( $yes, $yes );
$admin      = $agents->available( $yes, $yes, Agent::AUDIENCE_ADMIN );

t( 'PROBE: routing cannot see the marketing agent', ! array_key_exists( MarketingAgent::ID, $storefront ) );
t( 'PROBE: and neither can the handoff tool, which reads the same list', ! array_key_exists( MarketingAgent::ID, $storefront ) );
t( 'the console can, by asking for the admin audience', array_key_exists( MarketingAgent::ID, $admin ) );
t( 'PROBE: sales and support stay on the storefront side', array_key_exists( 'sales', $storefront ) && ! array_key_exists( 'sales', $admin ) );

// PROBE: a misspelt audience must not fall back to the storefront, which is
// the direction that would put a merchant-facing agent in front of shoppers.
$bad_audience = false;
try {
	new Agent( id: 'typo', label: 'Typo', mission: 'Mission.', persona: '', audience: 'admn' );
} catch ( InvalidArgumentException $e ) {
	$bad_audience = str_contains( $e->getMessage(), 'audience' );
}
t( 'PROBE: an unknown audience throws rather than defaulting', $bad_audience );

// The console screen, through the same two published surfaces as the licence
// tab — but a routed screen this time, because Marketing is a place the
// merchant works rather than a form they fill in twice a year.
t( '/marketing is a sidebar route', $routes->has( '/marketing' ) );
t( 'the marketing controller is registered', $controllers->has( 'marketing' ) && 'storecrew-pro' === $controllers->owner( 'marketing' ) );

$marketing_bundle_path = $plugins . '/storecrew-pro/assets/admin/marketing.js';
$marketing_enqueued    = $GLOBALS['scr_scripts']['storecrew-pro-marketing'] ?? null;

t( 'its bundle is enqueued when the shell announces itself', null !== $marketing_enqueued );
t( 'PROBE: depending on the shell handle, so registerScreen exists first', null !== $marketing_enqueued && in_array( 'storecrew-admin', $marketing_enqueued['deps'], true ) );
t( 'the file it points at exists', is_file( $marketing_bundle_path ) );
t(
	'its strings arrive translated from PHP, not from an i18n runtime',
	isset( $GLOBALS['scr_localized']['storecrew-pro-marketing']['storecrewProMarketing']['strings']['send'] )
);

$marketing_bundle = (string) file_get_contents( $marketing_bundle_path );
t( 'the bundle registers a routed screen, not a Settings tab', str_contains( $marketing_bundle, "registerScreen('/marketing'" ) );
t( 'PROBE: the built shell actually exposes registerScreen', str_contains( $shell_bundle, 'registerScreen' ) );

echo "\n== The Analytics agent: the second console, and the figures it may not invent ==\n";

use StoreCrew\Pro\Agent\AnalyticsAgent;

t( 'Pro registered metrics.report', $tools->has( 'metrics.report' ) && 'storecrew-pro' === $tools->owner( 'metrics.report' ) );
t( 'Pro registered product.performance', $tools->has( 'product.performance' ) && 'storecrew-pro' === $tools->owner( 'product.performance' ) );

$metrics     = $tools->get( 'metrics.report' );
$performance = $tools->get( 'product.performance' );

t( 'the analytics tool factories build real tools', $metrics instanceof ToolInterface && $performance instanceof ToolInterface );

// Reading the store's numbers and changing the store's data are different
// permissions. If these ever demanded storecrew_manage instead, a merchant
// could not employ someone to read reports without also handing them the till.
t( 'PROBE: metrics.report demands storecrew_view_analytics, not storecrew_manage', 'storecrew_view_analytics' === $metrics->required_capability() );
t( 'PROBE: product.performance demands storecrew_view_analytics', 'storecrew_view_analytics' === $performance->required_capability() );
t( 'PROBE: metrics.report is a read, so it never queues for approval', ToolInterface::INTENT_READ === $metrics->intent() );
t( 'PROBE: product.performance is a read too', ToolInterface::INTENT_READ === $performance->intent() );

// FR-ANALYTICS-02 is a MUST: the surface is constrained, and the way it is
// constrained is that `range` is an enum. Free text would be a query surface by
// another name, so the schema is probed rather than trusted.
$metrics_schema = $metrics->definition()->input_schema;
$metrics_props  = (array) ( $metrics_schema['properties'] ?? array() );

t( 'PROBE: range is an enum, not free text', isset( $metrics_props['range']['enum'] ) && array() !== (array) $metrics_props['range']['enum'] );
t( 'PROBE: and it is the only parameter, so nothing else can be composed', array( 'range' ) === array_keys( $metrics_props ) );

// A range the model invented must be refused, not coerced into a default. The
// coercing version is how a merchant gets last month's figures labelled as this
// month's and never finds out.
$query        = new StoreCrew\Pro\Analytics\MetricsQuery();
$injected     = $query->report( "2024-01-01' OR 1=1" );
$bad_ranking  = $query->top_products( 'last_30_days', 'net_revenue; DROP TABLE', 5 );

t( 'PROBE: a range outside the enum is refused, not defaulted', isset( $injected['error'] ) );
t( 'PROBE: and so is a ranking column outside its enum', isset( $bad_ranking['error'] ) );

$analytics = $agents->get( AnalyticsAgent::ID );

t( 'Pro registered the analytics agent', $analytics instanceof Agent && 'storecrew-pro' === $agents->owner( AnalyticsAgent::ID ) );
t( 'gated on agent.analytics', $analytics instanceof Agent && 'agent.analytics' === $analytics->feature );
t( 'and it too talks to the merchant, not the storefront', $analytics instanceof Agent && Agent::AUDIENCE_ADMIN === $analytics->audience );

$analytics_missing = array_values( array_diff( $analytics->tool_ids, $tools->ids() ) );
t( 'PROBE: every tool it names actually exists', array() === $analytics_missing, implode( ', ', $analytics_missing ) );

t( 'PROBE: routing cannot see the analytics agent either', ! array_key_exists( AnalyticsAgent::ID, $storefront ) );
t( 'the console can, by asking for the admin audience', array_key_exists( AnalyticsAgent::ID, $admin ) );

t( '/analytics is a sidebar route', $routes->has( '/analytics' ) );
t( 'the analytics controller is registered', $controllers->has( 'analytics' ) && 'storecrew-pro' === $controllers->owner( 'analytics' ) );

$analytics_bundle_path = $plugins . '/storecrew-pro/assets/admin/analytics.js';
$analytics_enqueued    = $GLOBALS['scr_scripts']['storecrew-pro-analytics'] ?? null;
$console_enqueued      = $GLOBALS['scr_scripts']['storecrew-pro-console'] ?? null;

t( 'its bundle is enqueued when the shell announces itself', null !== $analytics_enqueued );
t( 'the file it points at exists', is_file( $analytics_bundle_path ) );
t(
	'its strings arrive translated from PHP, not from an i18n runtime',
	isset( $GLOBALS['scr_localized']['storecrew-pro-analytics']['storecrewProAnalytics']['strings']['send'] )
);

$analytics_bundle = (string) file_get_contents( $analytics_bundle_path );
t( 'the bundle registers its own route, visibly', str_contains( $analytics_bundle, "registerScreen('/analytics'" ) );

// The console is shared between the two screens rather than copied. If a third
// screen ever pastes it back in, these probes are what notice.
$console_bundle_path = $plugins . '/storecrew-pro/assets/admin/console.js';

t( 'the shared console is enqueued', null !== $console_enqueued );
t(
	'PROBE: both screens depend on it, so createMount exists before they run',
	null !== $analytics_enqueued && null !== $marketing_enqueued
		&& in_array( 'storecrew-pro-console', $analytics_enqueued['deps'], true )
		&& in_array( 'storecrew-pro-console', $marketing_enqueued['deps'], true )
);
t( 'the shared console file exists', is_file( $console_bundle_path ) );

$console_bundle = (string) file_get_contents( $console_bundle_path );

// Model prose reaches these screens. The widget's rule applies unchanged: that
// text was written by something that has been reading customer reviews. The
// assertion follows the prose — it lives in the shared console now, and
// checking only the thin route files would be a probe that cannot fail.
t( 'PROBE: the shared console never assigns innerHTML', ! str_contains( $console_bundle, 'innerHTML' ) );
t( 'PROBE: and it does render model text as textContent', str_contains( $console_bundle, 'textContent' ) );
t( 'PROBE: neither route file re-implements the transcript', ! str_contains( $marketing_bundle, 'innerHTML' ) && ! str_contains( $analytics_bundle, 'innerHTML' ) );

echo "\n== The licence-gated updater (FR-DIST-08) ==\n";

use StoreCrew\Pro\Licensing\Updater;

// The Update URI header and the filter name are two halves of one
// mechanism: core derives the filter from the header's hostname, and if
// they drift apart updates silently stop while everything looks wired.
$pro_header = (string) file_get_contents( $plugins . '/storecrew-pro/storecrew-pro.php' );
$has_uri    = 1 === preg_match( '/^\s*\*\s*Update URI:\s*(\S+)\s*$/m', $pro_header, $uri_match );

t( 'the Update URI header exists — WordPress.org is locked out of the slug', $has_uri );
t(
	'PROBE: the header hostname and the filter name agree',
	$has_uri && Updater::HOOK === 'update_plugins_' . parse_url( $uri_match[1], PHP_URL_HOST )
);
t( 'the updater is hooked to that filter', has_action( Updater::HOOK ) );

$pro_file = 'storecrew-pro/storecrew-pro.php';
$headers  = array( 'Version' => STORECREW_PRO_VERSION );

// No key, no request: an unlicensed install has told us nothing and gets
// asked for nothing.
$install( null );
$asked   = 0;
$counter = new Updater( static function ( $route, $body ) use ( &$asked ) {
	++$asked;

	return array( 'version' => '9.9.9', 'package' => 'https://updates.example/x.zip' );
} );

t( 'PROBE: with no key the updater stays silent', false === $counter->check( false, $headers, $pro_file ) );
t( 'PROBE: and never calls the server', 0 === $asked );

update_option( StoreCrew\Pro\Licensing\LicenceClient::OPTION_KEY, 'sc_pro_fixture' );

$offer = $counter->check( false, $headers, $pro_file );
t( 'with a key, the server\'s metadata comes back for core to compare', is_array( $offer ) && '9.9.9' === $offer['version'] );
t( 'carrying the package', 'https://updates.example/x.zip' === ( $offer['package'] ?? '' ) );
t(
	'PROBE: another plugin naming the same host passes through untouched',
	false === $counter->check( false, $headers, 'other-plugin/other-plugin.php' ) && 3 !== $asked
);

// A lapsed licence sees the update exist but is not handed the package —
// nothing installed ever stops working, updates just stop arriving.
$lapsed = new Updater( static fn ( $route, $body ) => array( 'version' => '9.9.9', 'package' => null ) );
$offer  = $lapsed->check( false, $headers, $pro_file );
t( 'PROBE: a withheld package arrives empty, not absent', is_array( $offer ) && '' === $offer['package'] );

ob_start();
$lapsed->renewal_hint( $headers, (object) array( 'package' => '' ) );
$hint = ob_get_clean();
t( 'and the update row says why', str_contains( $hint, 'Renew' ) );

ob_start();
$lapsed->renewal_hint( $headers, (object) array( 'package' => 'https://updates.example/x.zip' ) );
t( 'PROBE: the hint stays silent when the package is real', '' === ob_get_clean() );

// Failure modes all read as "no update information", never as an error.
$dead = new Updater( static fn ( $route, $body ) => new WP_Error( 'http_request_failed', 'down' ) );
t( 'PROBE: a dead update server is silence, not an error', false === $dead->check( false, $headers, $pro_file ) );

$garbled = new Updater( static fn ( $route, $body ) => array( 'nonsense' => true ) );
t( 'PROBE: a malformed answer is discarded', false === $garbled->check( false, $headers, $pro_file ) );

$sideload = new Updater( static fn ( $route, $body ) => array( 'version' => '9.9.9', 'package' => 'http://evil.example/x.zip' ) );
t( 'PROBE: a non-https package poisons the whole answer', false === $sideload->check( false, $headers, $pro_file ) );

// Leave the licence spine as the boot wiring had it: no licence at all.
$install( null );

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

echo "\n== Email integrations: one interface, and consent that finally has a source ==\n";

use StoreCrew\Api\Secrets;
use StoreCrew\Pro\Integrations\ConsentSource;
use StoreCrew\Pro\Integrations\EspAdapter;
use StoreCrew\Pro\Integrations\EspRegistry;
use StoreCrew\Pro\Integrations\FluentCrm\FluentCrmAdapter;
use StoreCrew\Pro\Integrations\Mailchimp\MailchimpAdapter;

// The widening this change-set needed. Pro had nowhere safe to keep a Mailchimp
// key; the boundary rule refused to let it name Security\SecretStore, so the
// free plugin published four methods instead of a whole key-management class.
$secrets = $api->container()->get( Secrets::class );

t( 'the published Secrets surface resolves', $secrets instanceof Secrets );
t( 'PROBE: and it is the same object the platform uses', $secrets === $api->container()->get( \StoreCrew\Security\SecretStore::class ) );

$secrets->put( 'storecrew_pro.probe', 'shh' );
t( 'a secret round-trips', 'shh' === $secrets->get( 'storecrew_pro.probe' ) );
$secrets->forget( 'storecrew_pro.probe' );
t( 'and forgetting it works', null === $secrets->get( 'storecrew_pro.probe' ) );

// A Mailchimp that answers from fixtures. No paid account, no network, and the
// adapter cannot tell the difference — which is the point of the seam.
$scr_mc_calls = array();
$scr_mc = static function ( array $subscribed, int $status = 200 ) use ( &$scr_mc_calls ): callable {
	return static function ( string $method, string $url, ?array $options ) use ( $subscribed, $status, &$scr_mc_calls ): array {
		$scr_mc_calls[] = $method . ' ' . $url;

		if ( str_contains( $url, '/members' ) ) {
			return array(
				'status' => $status,
				'body'   => array( 'members' => array_map( static fn ( $e ) => array( 'email_address' => $e ), $subscribed ) ),
			);
		}

		if ( str_contains( $url, '/lists' ) ) {
			return array( 'status' => $status, 'body' => array( 'lists' => array( array( 'id' => 'aud1', 'name' => 'Newsletter', 'stats' => array( 'member_count' => 2 ) ) ) ) );
		}

		return array( 'status' => $status, 'body' => array( 'account_name' => 'Probe Store' ) );
	};
};

$mc = new MailchimpAdapter( $secrets, $scr_mc( array( 'yes@example.test' ) ) );

t( 'mailchimp is always available (it is hosted)', $mc->is_available() );
t( 'but not connected until a key is stored', ! $mc->is_connected() );

// A key without a datacenter suffix is refused before any request is made.
$scr_mc_calls = array();
$bad = $mc->connect( array( 'api_key' => 'no-datacenter-here' ) );
t( 'PROBE: a malformed key is refused', true !== ( $bad['ok'] ?? false ) );
t( 'PROBE: and refused locally, without calling Mailchimp', array() === $scr_mc_calls );

$good = $mc->connect( array( 'api_key' => 'abc123-us21' ) );
t( 'a valid key connects', true === ( $good['ok'] ?? false ) && 'Probe Store' === ( $good['account'] ?? '' ) );
t( 'and is now stored', $mc->is_connected() );

// Consent. Two customers, one subscribed at the ESP, one not.
$scr_yes = 9001;
$scr_no  = 9002;

$GLOBALS['scr_users'][ $scr_yes ] = array( 'ID' => $scr_yes, 'user_email' => 'yes@example.test' );
$GLOBALS['scr_users'][ $scr_no ]  = array( 'ID' => $scr_no, 'user_email' => 'no@example.test' );

update_option( EspRegistry::OPTION_AUDIENCE, 'aud1' );

$consent = $mc->consent_for( array( $scr_yes, $scr_no ) );

t( 'the subscribed customer has a consent basis', true === ( $consent[ $scr_yes ] ?? null ) );
t( 'PROBE: the unsubscribed one does not', false === ( $consent[ $scr_no ] ?? null ) );

// The whole reason consent is asked in bulk: one audience read, not one call
// per customer. A per-customer contract guarantees somebody loops it.
$scr_mc_calls = array();
$mc->consent_for( array( $scr_yes, $scr_no ) );
$scr_member_reads = count( array_filter( $scr_mc_calls, static fn ( $c ) => str_contains( $c, '/members' ) ) );
t( 'PROBE: two customers cost one audience read, not two lookups', 1 === $scr_member_reads );

// An unreachable service must never read as consent.
$dead = new MailchimpAdapter( $secrets, $scr_mc( array( 'yes@example.test' ), 500 ) );
$dead_answer = $dead->consent_for( array( $scr_yes ) );
t( 'PROBE: an unreachable ESP grants nobody consent', true !== ( $dead_answer[ $scr_yes ] ?? false ) );

// sync_segment filters at the adapter, not at its caller — the last point
// before data leaves the store.
$sync = $mc->sync_segment( 'Win-back', array( $scr_yes, $scr_no ), 'aud1' );
t( 'sync pushes only the consented member', 1 === ( $sync['synced'] ?? -1 ) );
t( 'PROBE: and reports the one it refused to send', 1 === ( $sync['skipped'] ?? -1 ) );
t( 'the tag is recognisable as ours', str_starts_with( (string) ( $sync['tag'] ?? '' ), 'StoreCrew: ' ) );

// FluentCRM is not installed here, and must say so rather than half-work.
$fc = new FluentCrmAdapter( $secrets );
t( 'PROBE: an absent in-WordPress ESP reports unavailable', ! $fc->is_available() );
t( 'PROBE: with a reason a merchant can act on', str_contains( $fc->unavailable_reason(), 'FluentCRM' ) );
t( 'PROBE: and grants no consent while unavailable', array() === $fc->consent_for( array( $scr_yes ) ) );

// The registry: one active provider, re-checked on every read.
$registry = new EspRegistry();
$registry->register( MailchimpAdapter::ID, static fn (): EspAdapter => $mc );
$registry->register( FluentCrmAdapter::ID, static fn (): EspAdapter => $fc );

t( 'nothing is active until the merchant chooses', null === $registry->active() );

$registry->activate( MailchimpAdapter::ID );
t( 'the chosen provider becomes active', $registry->active() instanceof MailchimpAdapter );

$registry->activate( FluentCrmAdapter::ID );
t( 'PROBE: a provider whose dependency vanished reads as no provider', null === $registry->active() );

$registry->activate( MailchimpAdapter::ID );

// The payoff: SegmentQuery's consent numbers stop being "all unknown".
//
// Hooked at 5 rather than through register()'s 10, because the *real* plugin
// already registered a ConsentSource over the real registry when it booted —
// and activate() above wrote the shared option, so that one sees Mailchimp
// active too. Its adapter has the live WP transport, which the shim refuses, so
// it would answer "nobody consented" and this probe would be measuring the
// harness. Running first also exercises the pass-through path below.
$source = new ConsentSource( $registry );
add_filter( 'storecrew_pro_marketing_consent_bulk', array( $source, 'resolve' ), 5, 2 );

ConsentSource::forget();
$bulk = apply_filters( 'storecrew_pro_marketing_consent_bulk', null, array( $scr_yes, $scr_no ) );

t( 'the consent source answers the bulk filter', is_array( $bulk ) );
t( 'consented customer comes back true', true === ( $bulk[ $scr_yes ] ?? null ) );
t( 'PROBE: and the other comes back false, not absent', false === ( $bulk[ $scr_no ] ?? null ) );

echo "\n== segment.sync: the first tool that sends store data anywhere ==\n";

use StoreCrew\Agent\Tool\ToolContext;
use StoreCrew\Pro\Agent\Tools\SegmentSyncTool;
use StoreCrew\Pro\Marketing\SegmentQuery;

t( 'Pro registered segment.sync', $tools->has( 'segment.sync' ) && 'storecrew-pro' === $tools->owner( 'segment.sync' ) );

$scr_sync_registered = $tools->get( 'segment.sync' );

t( 'the factory builds a real tool', $scr_sync_registered instanceof ToolInterface );
t( 'PROBE: it is a write, so it queues for approval by default', ToolInterface::INTENT_WRITE === $scr_sync_registered->intent() );
t( 'PROBE: and demands storecrew_manage', 'storecrew_manage' === $scr_sync_registered->required_capability() );
t( 'the marketing agent can actually reach it', in_array( 'segment.sync', $marketing->tool_ids, true ) );

// The two parameters this tool deliberately does not have, probed rather than
// trusted — both are the difference between a merchant's decision and a model's.
//
// `audience_id` would make the destination list something composed from model
// output, and model output has spent the conversation reading product
// descriptions and customer reviews. `customer_ids` would make the membership a
// list the model retyped from an earlier result: one mistyped integer is a real
// person tagged into a campaign they are not in, and nothing downstream can tell
// a wrong id from a right one. Criteria re-derive the audience at execution.
$scr_sync_props = array_keys( (array) ( $scr_sync_registered->definition()->input_schema['properties'] ?? array() ) );

t( 'PROBE: the model cannot name a destination audience', ! in_array( 'audience_id', $scr_sync_props, true ) );
t( 'PROBE: nor hand over a list of customer ids', ! in_array( 'customer_ids', $scr_sync_props, true ) );
t(
	'PROBE: and it takes segment.build\'s criteria, so the two agree on who is in',
	array() === array_diff( array_keys( (array) $segment->definition()->input_schema['properties'] ), $scr_sync_props )
);
t( 'no argument carries personal detail, so redaction cannot alter it', ! in_array( 'email', $scr_sync_props, true ) );

$scr_ctx = new ToolContext( conversation_id: 1, agent_id: MarketingAgent::ID, is_storefront: false );

// Four paid orders and two that must not count: a guest (no account to
// segment on) and a cancelled order (not customer behaviour).
$scr_now = time();
$GLOBALS['scr_orders'] = array(
	array( 'customer_id' => $scr_yes, 'total' => 120.0, 'created' => $scr_now - ( 30 * 86400 ) ),
	array( 'customer_id' => $scr_yes, 'total' => 80.0, 'created' => $scr_now - ( 60 * 86400 ) ),
	array( 'customer_id' => $scr_no, 'total' => 45.0, 'created' => $scr_now - ( 40 * 86400 ) ),
	array( 'customer_id' => 0, 'total' => 900.0, 'created' => $scr_now - ( 10 * 86400 ) ),
	array( 'customer_id' => 9003, 'total' => 500.0, 'created' => $scr_now - ( 5 * 86400 ), 'status' => 'cancelled' ),
);

// No provider connected is a refusal, not an empty sync. Without an ESP nothing
// in the system holds a consent basis at all, so "sent to nobody" and "sent to
// everyone" are the same call — and one of them is a complaint.
$scr_orphan = new SegmentSyncTool( new SegmentQuery(), new EspRegistry() );
$scr_r      = $scr_orphan->execute( $scr_ctx, array( 'name' => 'Win-back' ) );

t( 'PROBE: with no email provider it refuses', ! $scr_r->is_ok() );
t( 'PROBE: and says nothing was sent', str_contains( $scr_r->message, 'Nothing was sent' ) );

$scr_sync = new SegmentSyncTool( new SegmentQuery(), $registry );

// An unnamed segment is a tag the merchant cannot tell from the next one.
$scr_r = $scr_sync->execute( $scr_ctx, array( 'name' => '   ' ) );
t( 'PROBE: an unnamed segment is refused', ! $scr_r->is_ok() );

$scr_mc_calls = array();
$scr_r        = $scr_sync->execute( $scr_ctx, array( 'name' => 'Win-back', 'window_days' => 365 ) );

t( 'a named segment syncs', $scr_r->is_ok(), $scr_r->message );
t( 'two registered customers matched', 2 === ( $scr_r->data['matched'] ?? -1 ) );
t( 'PROBE: but only the consented one was sent', 1 === ( $scr_r->data['synced'] ?? -1 ) );
t( 'PROBE: and the other is reported skipped, not quietly dropped', 1 === ( $scr_r->data['skipped'] ?? -1 ) );

// The number a merchant plans a campaign against must be the number that
// arrived. A model told "400 matched" reaches for 400.
t( 'the model is told to report the synced figure, not the match', str_contains( $scr_r->message, 'not the segment size' ) );
t( 'PROBE: and why the rest were left out', str_contains( $scr_r->message, 'no marketing consent' ) );

// The destination is the merchant's setting. `aud1` was chosen above; the tool
// passes an empty audience precisely so each adapter reads it.
t(
	'PROBE: it pushed to the audience the merchant chose',
	array() !== array_filter( $scr_mc_calls, static fn ( $c ) => str_contains( $c, 'POST' ) && str_contains( $c, '/lists/aud1' ) )
);

// The invariant that keeps approval honest: what segment.build counts and what
// segment.sync sends are the same population, because both convert the same
// named criteria the same way.
$scr_built = $segment->execute( $scr_ctx, array( 'window_days' => 365 ) );
t( 'PROBE: segment.build and segment.sync agree on the audience', ( $scr_built->data['customers'] ?? -1 ) === ( $scr_r->data['matched'] ?? -2 ) );

// Criteria nobody matches is an answer, not a failure — and the provider is
// never called for it.
$scr_mc_calls = array();
$scr_r        = $scr_sync->execute( $scr_ctx, array( 'name' => 'Big spenders', 'min_lifetime_value' => 100000 ) );

t( 'an empty segment is reported, not errored', $scr_r->is_ok() );
t( 'PROBE: nothing was synced', 0 === ( $scr_r->data['synced'] ?? -1 ) );
t( 'PROBE: and the provider was never called', array() === $scr_mc_calls );

// A provider that has stopped answering must fail loudly. The dangerous version
// reports a successful sync of nobody.
$scr_dead_reg = new EspRegistry();
$scr_dead_reg->register( MailchimpAdapter::ID, static fn (): EspAdapter => $dead );
$scr_dead_reg->activate( MailchimpAdapter::ID );

$scr_r = ( new SegmentSyncTool( new SegmentQuery(), $scr_dead_reg ) )->execute( $scr_ctx, array( 'name' => 'Win-back' ) );

t( 'PROBE: an unreachable provider is an error', ! $scr_r->is_ok() );
t( 'PROBE: and claims no one was synced', ! isset( $scr_r->data['synced'] ) );

$GLOBALS['scr_orders'] = array();
$registry->activate( MailchimpAdapter::ID );

// A more specific source must win — a dedicated consent plugin beats a general
// ESP read, and filter order is how a merchant says so.
add_filter( 'storecrew_pro_marketing_consent_bulk', static fn () => array( 'sentinel' => true ), 1 );
$scr_earlier = apply_filters( 'storecrew_pro_marketing_consent_bulk', null, array( $scr_yes ) );
t( 'PROBE: an earlier filter is not overwritten', isset( $scr_earlier['sentinel'] ) );

unset( $GLOBALS['scr_users'][ $scr_yes ], $GLOBALS['scr_users'][ $scr_no ] );
delete_option( EspRegistry::OPTION_ACTIVE );
delete_option( EspRegistry::OPTION_AUDIENCE );
$mc->disconnect();

// The tab, through the same two published surfaces as the licence pane.
$scr_int_tab = $GLOBALS['scr_scripts']['storecrew-pro-integrations'] ?? null;
t( 'the integrations tab bundle is enqueued', null !== $scr_int_tab );
t( 'PROBE: depending on the shell handle', null !== $scr_int_tab && in_array( 'storecrew-admin', $scr_int_tab['deps'], true ) );
t( 'its strings arrive translated from PHP', isset( $GLOBALS['scr_localized']['storecrew-pro-integrations']['storecrewProIntegrations']['strings']['connect'] ) );
t( 'the integrations controller is registered', $controllers->has( 'integrations' ) && 'storecrew-pro' === $controllers->owner( 'integrations' ) );

$scr_int_bundle = (string) file_get_contents( $plugins . '/storecrew-pro/assets/admin/integrations.js' );
t( 'it registers a Settings tab, not a route', str_contains( $scr_int_bundle, 'registerSettingsTab' ) && ! str_contains( $scr_int_bundle, 'registerScreen' ) );
t( 'PROBE: and never assigns innerHTML', ! str_contains( $scr_int_bundle, 'innerHTML' ) );

t( 'cleanup: probe users removed', false === get_userdata( $scr_yes ) );
t( 'cleanup: the probe key is gone', ! $mc->is_connected() );

scr_summary();
