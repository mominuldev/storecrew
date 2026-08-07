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
		// Decoys: free-plugin state that must survive an add-on's removal.
		update_option( 'storecrew_model_policy', array( 'chat' => array( 'provider' => 'x' ) ) );
		update_option( 'storecrew_delete_data_on_uninstall', '0' );

		define( 'WP_UNINSTALL_PLUGIN', 'storecrew-pro/storecrew-pro.php' );
		require $plugins . '/storecrew-pro/uninstall.php';

		t( 'no fatal', true );
		t( 'licence tier removed', false === get_option( 'storecrew_pro_licence_tier' ) );
		t( 'licence key removed', false === get_option( 'storecrew_pro_licence_key' ) );
		t( 'licence status removed', false === get_option( 'storecrew_pro_licence_status' ) );
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

	default:
		fwrite( STDERR, "Unknown scenario: {$scenario}\n" );
		exit( 2 );
}

scr_summary();
