<?php
/**
 * The load-bearing rules no off-the-shelf sniff can express (03 § 4, 15 § 7).
 *
 * Usage:
 *   php tools/check-invariants.php               # check src/
 *   php tools/check-invariants.php --self-test   # violate every rule once
 *
 * Each rule here exists because breaking it produces a silent wrong answer,
 * not a crash — which is also why each carries a self-test: per the working
 * agreement, a guard that has never been observed to fire is not a guard.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

$root = dirname( __DIR__ );

/**
 * Rule 1 — storecrew.noGlobalWpdb: only repositories touch $wpdb.
 *
 * The three carve-outs documented in 07 § principle 1, plus the migrations
 * directory (they run DDL by definition):
 */
const WPDB_ALLOWED = array(
	'src/Database/Repositories/',
	// The base class IS the repository layer — it is where the injected-or-
	// global $wpdb lives so that every subclass can take a test double.
	'src/Database/Repository.php',
	'src/Database/Migrations/',
	'src/Database/Tables.php',
	'src/Knowledge/Extractor/PagesPostTypeIds.php',
);

/**
 * Rule 2 — storecrew.noProReferenceInFree: the free plugin must not know
 * premium exists.
 */
const PRO_PATTERNS = array( 'StoreCrew\\Pro\\', 'storecrew-pro', 'storecrew_pro_' );

/**
 * Rule 3 — parse-safety: files that load before the version guard stay
 * PHP 5.6-parseable. Token-level check for the constructs that would white-
 * screen a 7.4 site instead of showing the requirements notice.
 */
const PARSE_SAFE_FILES = array( 'storecrew.php', 'uninstall.php', 'src/Core/Requirements.php' );

const FORBIDDEN_56 = array(
	'/\bfn\s*\(/'                        => 'arrow function',
	'/\?\?/'                             => 'null coalescing',
	'/\bmatch\s*\(/'                     => 'match expression',
	'/:\s*(?:void|int|string|bool|float|array|self|static|\\\\?[A-Z][a-zA-Z0-9_\\\\]*)\s*\{/' => 'return type',
	'/\b(?:public|protected|private)\s+(?:readonly\s+)?(?:\?|int\b|string\b|bool\b|float\b|array\b|[A-Z])/' => 'typed or promoted property',
	'/\benum\s+[A-Z]/'                   => 'enum',
	'/\.\.\.\$/'                         => 'variadic/spread',
);

/**
 * Scan one tree. Returns violation strings.
 *
 * @return list<string>
 */
function scr_check( string $root ): array {
	$violations = array();

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS )
	);

	foreach ( $iterator as $file ) {
		if ( 'php' !== $file->getExtension() ) {
			continue;
		}

		$path     = str_replace( '\\', '/', substr( (string) $file, strlen( $root ) + 1 ) );
		$contents = (string) file_get_contents( (string) $file );

		// Rule 1: $wpdb outside the allowed paths.
		$allowed = false;

		foreach ( WPDB_ALLOWED as $prefix ) {
			if ( str_starts_with( $path, $prefix ) ) {
				$allowed = true;
				break;
			}
		}

		if ( ! $allowed && ( str_contains( $contents, 'global $wpdb' ) || str_contains( $contents, "\$GLOBALS['wpdb']" ) ) ) {
			$violations[] = "noGlobalWpdb: {$path} touches \$wpdb outside the repository layer";
		}

		// Rule 2: any reference to premium.
		foreach ( PRO_PATTERNS as $pattern ) {
			if ( str_contains( $contents, $pattern ) ) {
				$violations[] = "noProReferenceInFree: {$path} references '{$pattern}'";
			}
		}
	}

	// Rule 3: the parse-safe files.
	foreach ( PARSE_SAFE_FILES as $relative ) {
		$full = $root . '/' . $relative;

		if ( ! is_file( $full ) ) {
			$violations[] = "parseSafe: {$relative} is missing";
			continue;
		}

		$contents = (string) file_get_contents( $full );

		// Strip comments and strings first — prose legitimately contains "??".
		$stripped = '';

		foreach ( token_get_all( $contents ) as $token ) {
			if ( is_array( $token ) ) {
				if ( in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE ), true ) ) {
					continue;
				}

				$stripped .= $token[1];
			} else {
				$stripped .= $token;
			}
		}

		foreach ( FORBIDDEN_56 as $regex => $construct ) {
			if ( 1 === preg_match( $regex, $stripped ) ) {
				$violations[] = "parseSafe: {$relative} uses a {$construct} — PHP 5.6 white-screens before the version guard can run";
			}
		}
	}

	return $violations;
}

// ---------------------------------------------------------------------------

if ( in_array( '--self-test', $argv, true ) ) {
	// Violate every rule once in a scratch tree and demand the checker fires.
	$scratch = sys_get_temp_dir() . '/scr-invariants-' . getmypid();
	mkdir( $scratch . '/src/Chat', 0777, true );
	mkdir( $scratch . '/src/Core', 0777, true );

	file_put_contents( $scratch . '/src/Chat/Evil.php', "<?php\nglobal \$wpdb;\n" );
	file_put_contents( $scratch . '/src/Chat/Nosy.php', "<?php\n\\StoreCrew\\Pro\\Thing::x();\n" );
	file_put_contents( $scratch . '/storecrew.php', "<?php\n\$x = \$y ?? 1;\n" );
	file_put_contents( $scratch . '/uninstall.php', "<?php\n" );
	file_put_contents( $scratch . '/src/Core/Requirements.php', "<?php\n" );

	$found = scr_check( $scratch );

	$expect = array( 'noGlobalWpdb', 'noProReferenceInFree', 'parseSafe' );
	$ok     = true;

	foreach ( $expect as $rule ) {
		$hit = false;

		foreach ( $found as $violation ) {
			if ( str_starts_with( $violation, $rule ) ) {
				$hit = true;
			}
		}

		printf( "  %s  self-test: %s fires\n", $hit ? 'PASS' : 'FAIL', $rule );
		$ok = $ok && $hit;
	}

	array_map( 'unlink', glob( $scratch . '/src/Chat/*' ) );
	array_map( 'unlink', glob( $scratch . '/src/Core/*' ) );
	array_map( 'unlink', glob( $scratch . '/*.php' ) );
	rmdir( $scratch . '/src/Chat' );
	rmdir( $scratch . '/src/Core' );
	rmdir( $scratch . '/src' );
	rmdir( $scratch );

	exit( $ok ? 0 : 1 );
}

$violations = scr_check( $root );

if ( array() === $violations ) {
	echo "invariants: all hold (noGlobalWpdb, noProReferenceInFree, parseSafe)\n";
	exit( 0 );
}

foreach ( $violations as $violation ) {
	echo "  VIOLATION  {$violation}\n";
}

exit( 1 );
