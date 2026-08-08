<?php
/**
 * The storefront visitor's chat session credential.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Chat;

defined( 'ABSPATH' ) || exit;

/**
 * Issues and reads the token that binds a visitor to their conversation.
 *
 * A conversation is addressed publicly by uuid, and a uuid is guessable to
 * nobody but visible to everybody who has one — it travels in URLs, in support
 * emails, in screenshots. **The uuid is an address, not a credential.** Reading
 * or continuing a conversation requires this token, which the server issued and
 * never puts in a URL.
 *
 * Only a digest is stored. The conversations table holds `sha256(token)`, so a
 * dump of that table hands an attacker nothing they can present back.
 *
 * The token travels in an HttpOnly cookie, and is *also* returned once at
 * session creation so the widget can fall back to a header. That fallback is
 * deliberate and its cost is understood: it puts the token where a
 * cross-site-scripting flaw in the merchant's theme could read it. The
 * alternative is worse — a great many WooCommerce hosts run a page cache that
 * strips `Set-Cookie`, and a widget that silently loses every conversation on
 * those hosts is a widget nobody can debug. The blast radius of the token is
 * one visitor's own chat thread, which they can already read.
 */
final class Session {

	public const COOKIE = 'storecrew_chat_session';

	public const HEADER = 'X-StoreCrew-Session';

	/**
	 * A fresh token. 32 bytes of entropy, rendered as 64 hex characters so the
	 * digest and the raw form are the same width as the column.
	 */
	public static function issue(): string {
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * The stored form of a token.
	 */
	public static function digest( string $token ): string {
		return '' === $token ? '' : hash( 'sha256', $token );
	}

	/**
	 * The token in this request's cookie, if any.
	 *
	 * Separate from {@see self::from_request()} because attribution reads it
	 * from an ordinary checkout request, where there is no `WP_REST_Request` to
	 * ask — and where the header fallback deliberately does not apply, since
	 * nothing but our own widget ever sets that header.
	 */
	public static function from_cookie(): string {
		$cookie = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( (string) $_COOKIE[ self::COOKIE ] ) ) : '';

		return self::looks_valid( $cookie ) ? $cookie : '';
	}

	/**
	 * The token this request presents, if any.
	 *
	 * The cookie wins over the header. A visitor with a working cookie should
	 * not be overridable by a header an injected script could set.
	 */
	public static function from_request( \WP_REST_Request $request ): string {
		$cookie = self::from_cookie();

		if ( '' !== $cookie ) {
			return $cookie;
		}

		$header = (string) $request->get_header( self::HEADER );

		return self::looks_valid( $header ) ? $header : '';
	}

	/**
	 * Send the cookie.
	 *
	 * `SameSite=Lax` rather than `Strict`: a customer arriving from an order
	 * confirmation email must still be recognised, and the token authorises
	 * nothing beyond their own conversation.
	 */
	public static function send_cookie( string $token ): void {
		if ( headers_sent() || ! self::looks_valid( $token ) ) {
			return;
		}

		setcookie(
			self::COOKIE,
			$token,
			array(
				'expires'  => time() + ( 30 * DAY_IN_SECONDS ),
				'path'     => defined( 'COOKIEPATH' ) && '' !== COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Shape check only — this says nothing about whether the token is known.
	 */
	public static function looks_valid( string $token ): bool {
		return 64 === strlen( $token ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $token );
	}

	/**
	 * The requesting address, for rate limiting. Never stored raw.
	 */
	public static function client_ip(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';

		/**
		 * Filter the address chat rate limiting counts against.
		 *
		 * Left as `REMOTE_ADDR` by default and deliberately not read from
		 * `X-Forwarded-For`: that header is caller-supplied, so trusting it
		 * unconditionally turns the per-IP limit into a per-*claimed*-IP limit,
		 * which any script can reset by changing one string. Sites behind a
		 * known proxy should filter this and validate the hop themselves.
		 *
		 * @param string $ip The address.
		 */
		return (string) apply_filters( 'storecrew_chat_client_ip', $remote );
	}
}
