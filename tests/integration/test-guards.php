<?php
/**
 * Failure-path probes. Each scenario runs in its own process because these are
 * load-time guards that define constants.
 *
 * Usage: php test-guards.php <scenario>
 */

require __DIR__ . '/wp-shim.php';

// tests/integration -> tests -> storecrew -> plugins
$plugins  = dirname( __DIR__, 3 );
$scenario = $argv[1] ?? '';

switch ( $scenario ) {

	case 'pro-uninstall':
		// Pro's uninstall removes exactly what Pro created — its licence
		// options — and nothing the free plugin owns (FR-DIST-06).
		update_option( 'storecrew_pro_licence_tier', 'pro' );
		update_option( 'storecrew_pro_licence_key', 'sc_pro_test' );
		update_option( 'storecrew_pro_licence_status', 'active' );
		update_option( 'storecrew_pro_snapshot', array( 'payload' => 'x', 'signature' => 'y' ) );
		wp_schedule_event( time(), 'weekly', 'storecrew_pro_licence_revalidate' );
		// Decoys: free-plugin state that must survive an add-on's removal.
		update_option( 'storecrew_model_policy', array( 'chat' => array( 'provider' => 'x' ) ) );
		update_option( 'storecrew_delete_data_on_uninstall', '0' );

		define( 'WP_UNINSTALL_PLUGIN', 'storecrew-pro/storecrew-pro.php' );
		require $plugins . '/storecrew-pro/uninstall.php';

		t( 'no fatal', true );
		t( 'licence tier removed', false === get_option( 'storecrew_pro_licence_tier' ) );
		t( 'licence key removed', false === get_option( 'storecrew_pro_licence_key' ) );
		t( 'licence status removed', false === get_option( 'storecrew_pro_licence_status' ) );
		t( 'snapshot removed', false === get_option( 'storecrew_pro_snapshot' ) );
		t( 'revalidation event removed', false === wp_next_scheduled( 'storecrew_pro_licence_revalidate' ) );
		t( 'PROBE: the free plugin\'s options survive', false !== get_option( 'storecrew_model_policy' ) );
		t( 'PROBE: the free plugin\'s uninstall opt-in is untouched', '0' === get_option( 'storecrew_delete_data_on_uninstall' ) );
		break;

	case 'pro-without-free':
		// Pro active, free absent entirely. Must notice, must not fatal.
		require $plugins . '/storecrew-pro/storecrew-pro.php';
		do_action( 'plugins_loaded' );
		$n = scr_collect_notices();
		t( 'no fatal', true );
		t( 'notice mentions the free plugin', str_contains( $n, 'requires the free StoreCrew AI plugin' ), $n );
		t( 'Pro registered nothing', ! has_action( 'storecrew_api_ready' ) );
		break;

	case 'free-without-woo':
		// WooCommerce not installed. Free must decline to boot, with a reason.
		require $plugins . '/storecrew/storecrew.php';
		do_action( 'plugins_loaded' );
		$n = scr_collect_notices();
		t( 'no fatal', true );
		t( 'notice names WooCommerce', str_contains( $n, 'WooCommerce' ), $n );
		t( 'notice says it is not active', str_contains( $n, 'not active' ), $n );
		t( 'kernel did not boot', ! class_exists( 'StoreCrew\Plugin', false ) );
		break;

	case 'free-with-old-woo':
		define( 'WC_VERSION', '8.4.0' );
		require $plugins . '/storecrew/storecrew.php';
		do_action( 'plugins_loaded' );
		$n = scr_collect_notices();
		t( 'no fatal', true );
		t( 'notice states required version', str_contains( $n, '9.0' ), $n );
		t( 'notice states installed version', str_contains( $n, '8.4.0' ), $n );
		break;

	case 'pro-api-too-new':
		// Free plugin has moved to API 2.x; this Pro build supports up to <2.0.
		define( 'STORECREW_API_VERSION', '2.0' );
		require $plugins . '/storecrew-pro/storecrew-pro.php';
		do_action( 'plugins_loaded' );
		$n = scr_collect_notices();
		t( 'no fatal', true );
		t( 'notice tells merchant to update Pro', str_contains( $n, 'update StoreCrew AI Pro' ), $n );
		t( 'Pro registered nothing', ! has_action( 'storecrew_api_ready' ) );
		break;

	case 'pro-api-too-old':
		// Free plugin predates the API this Pro build needs.
		define( 'STORECREW_API_VERSION', '0.9' );
		require $plugins . '/storecrew-pro/storecrew-pro.php';
		do_action( 'plugins_loaded' );
		$n = scr_collect_notices();
		t( 'no fatal', true );
		t( 'notice tells merchant to update the free plugin', str_contains( $n, 'update StoreCrew AI' ), $n );
		t( 'Pro registered nothing', ! has_action( 'storecrew_api_ready' ) );
		break;

	case 'pro-i18n':
		// Pro is never distributed through WordPress.org, so no language pack is
		// ever served to it — its catalog has to ship in the plugin, and the path
		// the loader names has to be real. It was not: `load_plugin_textdomain()`
		// pointed at a `/languages` directory that did not exist, and nothing
		// noticed, because a missing directory and a locale nobody has translated
		// yet both return false and both leave the merchant reading English.
		define( 'STORECREW_API_VERSION', '1.0' );
		require $plugins . '/storecrew-pro/storecrew-pro.php';
		do_action( 'plugins_loaded' );
		do_action( 'init' );

		$loaded = isset( $GLOBALS['scr_textdomains'] ) ? $GLOBALS['scr_textdomains'] : array();
		t( 'Pro loads a textdomain on init', array() !== $loaded );

		$rel = null;
		foreach ( $loaded as $call ) {
			if ( 'storecrew-pro' === $call['domain'] ) {
				$rel = $call['rel_path'];
			}
		}

		// json_encode, not wp_json_encode: the shim is a hook substitute, not a
		// WordPress, and reaching for a core helper here is how a probe ends up
		// testing the shim instead of the plugin.
		t( 'the domain is storecrew-pro', null !== $rel, json_encode( $loaded ) );

		$dir = $plugins . '/' . $rel;
		t( 'the directory it names exists', is_dir( $dir ), $dir );
		t( 'and holds the string catalog', file_exists( $dir . '/storecrew-pro.pot' ), $dir );
		break;

	default:
		fwrite( STDERR, "Unknown scenario: {$scenario}\n" );
		exit( 2 );
}

scr_summary();
