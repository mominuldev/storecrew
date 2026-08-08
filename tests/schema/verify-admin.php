<?php
/**
 * Admin application host verification.
 *
 * Run with: wp eval-file wp-content/plugins/storecrew/tests/schema/verify-admin.php --user=1
 *
 * Covers the PHP that mounts the SPA and the contract the SPA depends on. The
 * React app itself is type-checked at build time; what is worth asserting here
 * is the seam — that the menu exists, that assets only load on our screen, that
 * the bootstrap payload carries a nonce, and that every endpoint the app calls
 * on first paint actually answers.
 *
 * No declare(strict_types=1): wp eval-file runs this through eval().
 *
 * @package StoreCrew
 */

use StoreCrew\Core\Activation\Activator;
use StoreCrew\Core\Admin\AdminPage;
use StoreCrew\Core\Capabilities\Capabilities;

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

// The first-activation probes rewrite the two options that decide whether a
// merchant is a first-timer. Restore on the way out however this file leaves —
// a fatal in between would otherwise leave a configured store looking freshly
// installed, and hijack the merchant's next admin page load.
$saved_activated = get_option( Activator::OPTION_ACTIVATED_AT, false );
$saved_redirect  = get_option( Activator::OPTION_SETUP_REDIRECT, false );

// This file dispatches /bootstrap, which runs the onboarding step recorder. On
// a store whose setup is finished that writes real step events stamped *now* —
// days after the merchant actually did the steps — and a ledger that stops the
// install ever being recognised as one whose times are unknown. Snapshot both;
// the events are removed by id so a merchant's own onboarding rows survive.
$saved_progress = get_option( StoreCrew\Core\SetupProgress::OPTION, false );
$events_before  = (int) $GLOBALS['wpdb']->get_var(
	'SELECT COALESCE(MAX(id), 0) FROM ' . StoreCrew\Database\Tables::name( StoreCrew\Database\Tables::USAGE_EVENTS )
);

$restore_progress = static function () use ( $saved_progress, $events_before ): void {
	static $done = false;

	if ( $done ) {
		return;
	}

	$done = true;

	if ( false === $saved_progress ) {
		delete_option( StoreCrew\Core\SetupProgress::OPTION );
	} else {
		update_option( StoreCrew\Core\SetupProgress::OPTION, $saved_progress, false );
	}

	$GLOBALS['wpdb']->query(
		$GLOBALS['wpdb']->prepare(
			'DELETE FROM ' . StoreCrew\Database\Tables::name( StoreCrew\Database\Tables::USAGE_EVENTS ) . ' WHERE id > %d AND metric LIKE %s',
			$events_before,
			$GLOBALS['wpdb']->esc_like( StoreCrew\Database\Repositories\UsageRepository::METRIC_SETUP_STEP . '.' ) . '%'
		)
	);
};

$restore_activation = static function () use ( $saved_activated, $saved_redirect ): void {
	static $done = false;

	if ( $done ) {
		return;
	}

	$done = true;

	foreach ( array( Activator::OPTION_ACTIVATED_AT => $saved_activated, Activator::OPTION_SETUP_REDIRECT => $saved_redirect ) as $option => $value ) {
		if ( false === $value ) {
			delete_option( $option );
		} else {
			update_option( $option, $value );
		}
	}
};

register_shutdown_function( $restore_activation );
register_shutdown_function( $restore_progress );

echo "\n== Built assets ==\n";
$js  = STORECREW_DIR . 'assets/admin/app.js';
$css = STORECREW_DIR . 'assets/admin/app.css';

$t( 'the application bundle is built', file_exists( $js ) );
$t( 'the stylesheet is built', file_exists( $css ) );

if ( file_exists( $js ) ) {
	$bytes = filesize( $js );
	// The PRD budgets 250 KB gzipped for the initial bundle. Raw size is the
	// cheap proxy; roughly a third survives gzip.
	$t( 'bundle is within a sane size', $bytes < 900 * 1024, round( $bytes / 1024 ) . ' KB raw' );

	$source = file_get_contents( $js );
	$t(
		'PROBE: no @wordpress package leaked into the bundle',
		! str_contains( $source, '@wordpress/' ),
		'a Gutenberg package was bundled'
	);
	$t(
		'PROBE: React is bundled, not borrowed from core',
		! str_contains( $source, 'window.wp.element' )
	);
}

echo "\n== Menu registration ==\n";
$page = new AdminPage();
$page->register();

global $menu;
$menu                          = array();
$GLOBALS['_registered_pages']  = array();
do_action( 'admin_menu' );

$entry = null;

foreach ( (array) $menu as $item ) {
	if ( 'storecrew' === ( $item[2] ?? '' ) ) {
		$entry = $item;
	}
}

$t( 'a top-level menu is registered', null !== $entry );
$t( 'it requires our own capability', Capabilities::MANAGE === ( $entry[1] ?? '' ), (string) ( $entry[1] ?? '' ) );
$t(
	'PROBE: it does not use manage_options',
	'manage_options' !== ( $entry[1] ?? '' ),
	'a shop manager would be locked out'
);

echo "\n== The page is a mount point, nothing more ==\n";
ob_start();
$page->render();
$html = trim( (string) ob_get_clean() );

$t( 'renders exactly one mount point', '<div id="storecrew-root"></div>' === $html, $html );

echo "\n== Asset loading is scoped ==\n";
wp_scripts()->queue = array();
$page->enqueue( 'edit.php' );
$t( 'PROBE: nothing loads on an unrelated screen', ! in_array( 'storecrew-admin', wp_scripts()->queue, true ) );

$page->enqueue( 'plugins.php' );
$t( 'PROBE: nothing loads on the plugins screen either', ! in_array( 'storecrew-admin', wp_scripts()->queue, true ) );

$page->enqueue( 'toplevel_page_storecrew' );
$t( 'the app loads on our own screen', in_array( 'storecrew-admin', wp_scripts()->queue, true ) );

$data = (string) wp_scripts()->get_data( 'storecrew-admin', 'data' );
$t( 'a bootstrap payload is attached', str_contains( $data, 'storecrewBoot' ) );
$t( 'it carries the REST root', str_contains( $data, 'wp-json' ) || str_contains( $data, 'rest_route' ) );
$t(
	'PROBE: it carries a nonce — cookie auth alone will not authorise a write',
	(bool) preg_match( '/"nonce":"[a-zA-Z0-9]+"/', $data ),
	$data
);
$t(
	'PROBE: no secret is exposed to the browser',
	! str_contains( $data, 'sk-' ) && ! str_contains( strtolower( $data ), 'api_key' )
);

echo "\n== Every screen the app opens with answers ==\n";
do_action( 'rest_api_init' );
$server = rest_get_server();

$endpoints = array(
	'/storecrew/v1/bootstrap'     => 'Overview',
	'/storecrew/v1/health'        => 'Overview',
	'/storecrew/v1/approvals'     => 'Inbox badge',
	'/storecrew/v1/providers'     => 'Settings',
	'/storecrew/v1/settings'      => 'Settings',
	'/storecrew/v1/index'         => 'Knowledge',
	'/storecrew/v1/conversations' => 'Crew',
);

foreach ( $endpoints as $route => $screen ) {
	$response = $server->dispatch( new WP_REST_Request( 'GET', $route ) );
	$t( sprintf( '%s (%s)', $route, $screen ), 200 === $response->get_status(), (string) $response->get_status() );
}

echo "\n== First activation opens the setup flow (FR-ADMIN-02) ==\n";

$t(
	'the setup URL is the SPA route, not a settings page',
	str_ends_with( AdminPage::setup_url(), '#/setup' ),
	AdminPage::setup_url()
);

// A first-ever activation: neither option exists.
delete_option( Activator::OPTION_ACTIVATED_AT );
delete_option( Activator::OPTION_SETUP_REDIRECT );

Activator::activate();

$t( 'first activation records when it happened', (int) get_option( Activator::OPTION_ACTIVATED_AT ) > 0 );
$t( 'first activation asks for the setup redirect', '1' === (string) get_option( Activator::OPTION_SETUP_REDIRECT ) );

// Activation used to write two options nothing read (Gate 2). Migrations 003
// and 004 remove the rows; these are the half that stops them coming back —
// reintroducing either write would leave those migrations' own probes passing
// while the option reappeared on every activation.
$t(
	'PROBE: activation writes no upgrade flag',
	false === get_option( 'storecrew_needs_upgrade', false ),
	'the migrator gates on the version comparison, not on a flag'
);
$t(
	'PROBE: activation writes no version option',
	false === get_option( 'storecrew_version', false ),
	'STORECREW_VERSION is the running version and cannot go stale; the option could'
);

// Re-activating is not a first activation. Someone toggling the plugin to
// clear a cache has already been through setup.
delete_option( Activator::OPTION_SETUP_REDIRECT );

Activator::activate();

$t(
	'PROBE: re-activation does not throw a configured merchant back to step one',
	false === get_option( Activator::OPTION_SETUP_REDIRECT )
);

// The guard, exercised: WP-CLI is not an admin request, so nothing redirects
// here however the flag is set — which is also why this file can call the
// consumer at all without ending its own run.
update_option( Activator::OPTION_SETUP_REDIRECT, '1' );

$t( 'PROBE: a non-admin request never redirects', ! $page->may_redirect() );

$page->maybe_redirect_to_setup();

$t(
	'PROBE: the flag is spent even when the redirect cannot happen',
	false === get_option( Activator::OPTION_SETUP_REDIRECT ),
	'a redirect that can retry is a redirect that can loop'
);

$restore_activation();

$t(
	'cleanup restored the real activation state',
	$saved_activated === get_option( Activator::OPTION_ACTIVATED_AT, false )
		&& false === get_option( Activator::OPTION_SETUP_REDIRECT, false )
);

echo "\n== The bootstrap contract the app is typed against ==\n";
$boot = $server->dispatch( new WP_REST_Request( 'GET', '/storecrew/v1/bootstrap' ) )->get_data()['data'];

foreach ( array( 'version', 'apiVersion', 'features', 'catalog', 'routes', 'onboarding', 'user' ) as $key ) {
	$t( "bootstrap carries {$key}", array_key_exists( $key, $boot ) );
}

$t( 'onboarding reports whether anything can be indexed', array_key_exists( 'canEmbed', $boot['onboarding'] ) );

// The fifteen-minute exit criterion (14 § M1) is about the merchant's time,
// and embedding is a queue whose length is the catalogue's, not theirs. The
// index step therefore tracks what they can control.
$onboarding_steps = array_column( $boot['onboarding']['steps'], 'done', 'id' );
$live_health      = StoreCrew\Plugin::instance()->container()->get( StoreCrew\Knowledge\Indexer::class )->health();

$t(
	'PROBE: the index step tracks "the crew can answer", not "the queue is empty"',
	( $live_health['embedded'] > 0 ) === ( true === $onboarding_steps['index'] ),
	wp_json_encode( array( 'embedded' => $live_health['embedded'], 'pending' => $live_health['pending'], 'step' => $onboarding_steps['index'] ) )
);
$t( 'routes carry a locked flag for gating', ! $boot['routes'] || array_key_exists( 'locked', $boot['routes'][0] ) );

echo "\n== Cleanup ==\n";
// Explicitly, not only on shutdown: a suite's shutdown function does not run
// after a fatal under `wp eval-file` — WordPress's own fatal handler goes
// first. Measured 2026-08-08; it was believed otherwise.
$restore_progress();

$t(
	'the bootstrap dispatches left no step events behind',
	0 === (int) $GLOBALS['wpdb']->get_var(
		$GLOBALS['wpdb']->prepare(
			'SELECT COUNT(*) FROM ' . StoreCrew\Database\Tables::name( StoreCrew\Database\Tables::USAGE_EVENTS ) . ' WHERE id > %d AND metric LIKE %s',
			$events_before,
			$GLOBALS['wpdb']->esc_like( StoreCrew\Database\Repositories\UsageRepository::METRIC_SETUP_STEP . '.' ) . '%'
		)
	)
);

echo "\n" . str_repeat( '-', 60 ) . "\n";
printf( "%d passed, %d failed\n", $pass, $fail );

if ( $fail > 0 ) {
	exit( 1 );
}
