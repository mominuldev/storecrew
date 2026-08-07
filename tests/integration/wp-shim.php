<?php
/**
 * Minimal WordPress shim: just enough hook/option/i18n surface to boot the two
 * plugins outside WordPress and assert on their interaction.
 */

define( 'ABSPATH', '/tmp/fake-wp/' );

$GLOBALS['scr_hooks']   = array();
$GLOBALS['scr_options'] = array();
$GLOBALS['scr_notices'] = array();

function add_action( $tag, $cb, $prio = 10, $args = 1 ) {
	$GLOBALS['scr_hooks'][ $tag ][ $prio ][] = $cb;
	return true;
}

function add_filter( $tag, $cb, $prio = 10, $args = 1 ) {
	return add_action( $tag, $cb, $prio, $args );
}

function scr_run( $tag, array $args, $is_filter = false, $value = null ) {
	if ( ! isset( $GLOBALS['scr_hooks'][ $tag ] ) ) {
		return $value;
	}

	$done = array();

	// Re-read priorities each pass so a callback that registers a later
	// priority mid-run still fires, matching WordPress behaviour.
	while ( true ) {
		$prios = array_keys( $GLOBALS['scr_hooks'][ $tag ] );
		sort( $prios );

		$next = null;
		foreach ( $prios as $p ) {
			if ( ! in_array( $p, $done, true ) ) {
				$next = $p;
				break;
			}
		}

		if ( null === $next ) {
			break;
		}

		$done[] = $next;

		foreach ( $GLOBALS['scr_hooks'][ $tag ][ $next ] as $cb ) {
			if ( $is_filter ) {
				$value = $cb( $value, ...$args );
			} else {
				$cb( ...$args );
			}
		}
	}

	return $value;
}

function do_action( $tag, ...$args ) {
	scr_run( $tag, $args, false, null );
}

function apply_filters( $tag, $value, ...$args ) {
	return scr_run( $tag, $args, true, $value );
}

function has_action( $tag ) {
	return isset( $GLOBALS['scr_hooks'][ $tag ] );
}

function __( $s, $d = null ) { return $s; }
function esc_html( $s ) { return $s; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_attr( $s ) { return $s; }

function get_option( $k, $default = false ) {
	return array_key_exists( $k, $GLOBALS['scr_options'] ) ? $GLOBALS['scr_options'][ $k ] : $default;
}
function update_option( $k, $v ) { $GLOBALS['scr_options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['scr_options'][ $k ] ); return true; }
function add_option( $k, $v ) {
	if ( ! array_key_exists( $k, $GLOBALS['scr_options'] ) ) { $GLOBALS['scr_options'][ $k ] = $v; }
	return true;
}

/**
 * Capability state for the shim.
 *
 * true  = every capability granted
 * false = none granted (matches WP-CLI, which has no current user)
 * array = only the listed capabilities
 *
 * This started life as an unconditional `return true`, which quietly hid the
 * fact that FeatureGate::manifest() filters routes by capability — the suite
 * passed while real WP-CLI returned an empty route list. A stub that can only
 * say yes cannot test a gate.
 */
$GLOBALS['scr_caps'] = true;

function current_user_can( $cap ) {
	$caps = $GLOBALS['scr_caps'];

	if ( is_bool( $caps ) ) {
		return $caps;
	}

	return in_array( $cap, (array) $caps, true );
}
function get_bloginfo( $what ) { return '7.0.2'; }
// The harness models a front-end request: nothing admin-only should be needed
// to boot. AgentRunner reads this to decide whether a turn is merchant-driven.
function is_admin() { return false; }
function plugin_dir_path( $f ) { return rtrim( dirname( $f ), '/' ) . '/'; }
function plugin_dir_url( $f ) { return 'http://example.test/wp-content/plugins/' . basename( dirname( $f ) ) . '/'; }
function plugin_basename( $f ) { return basename( dirname( $f ) ) . '/' . basename( $f ); }
function load_plugin_textdomain( $d, $a = false, $p = '' ) { return true; }
function register_activation_hook( $f, $cb ) { $GLOBALS['scr_activation'][ $f ] = $cb; }
function register_deactivation_hook( $f, $cb ) { $GLOBALS['scr_deactivation'][ $f ] = $cb; }
function get_role( $r ) { return null; }
function wp_roles() { return (object) array( 'roles' => array() ); }
function wp_die( $m, $t = '', $a = array() ) { throw new RuntimeException( 'wp_die: ' . $m ); }
function as_unschedule_all_actions( $h, $a = array(), $g = '' ) { return 0; }

/** Collect admin notices instead of echoing them. */
function scr_collect_notices() {
	ob_start();
	do_action( 'admin_notices' );
	$out = trim( ob_get_clean() );
	return $out;
}

/** Tiny assertion helper. */
$GLOBALS['scr_pass'] = 0;
$GLOBALS['scr_fail'] = 0;

function t( $label, $condition, $detail = '' ) {
	if ( $condition ) {
		$GLOBALS['scr_pass']++;
		echo "  PASS  {$label}\n";
	} else {
		$GLOBALS['scr_fail']++;
		echo "  FAIL  {$label}" . ( '' !== $detail ? " — {$detail}" : '' ) . "\n";
	}
}

function scr_summary() {
	echo "\n" . str_repeat( '-', 60 ) . "\n";
	printf( "%d passed, %d failed\n", $GLOBALS['scr_pass'], $GLOBALS['scr_fail'] );
	exit( $GLOBALS['scr_fail'] > 0 ? 1 : 0 );
}
