<?php
/**
 * Abuse protection for the public chat endpoints.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Chat;

use StoreCrew\Database\Repositories\AuditLogRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Fixed-window counters, per session and per IP (FR-CHAT-06).
 *
 * Both windows exist because either alone is trivially defeated. A per-session
 * limit is beaten by discarding the cookie and asking for a new session; a
 * per-IP limit is beaten by anyone behind a shared address — a school, an
 * office, a mobile carrier's NAT — being throttled for a stranger's behaviour.
 * The session window is the tight one and the IP window is the loose backstop,
 * so a legitimate visitor never reaches the IP limit and an automated one hits
 * it immediately.
 *
 * Counters live in transients rather than a table. Rate limiting is the one
 * place where losing state is *safe* — a flushed cache resets a counter to
 * zero, which costs the store one extra turn, whereas a row per message costs
 * a write on every request the limiter is supposed to be making cheap.
 *
 * The IP is never stored. It is hashed with the same salted digest the audit
 * log uses, so a cache dump does not become a list of visitor addresses.
 */
final class RateLimiter {

	public const SCOPE_SESSION = 'session';
	public const SCOPE_IP      = 'ip';

	private const PREFIX = 'storecrew_rl_';

	/**
	 * @param int $session_limit  Messages allowed per session per window.
	 * @param int $ip_limit       Messages allowed per address per window.
	 * @param int $window_seconds Window length.
	 */
	public function __construct(
		private readonly int $session_limit = 20,
		private readonly int $ip_limit = 60,
		private readonly int $window_seconds = 300,
	) {}

	/**
	 * Build from filtered settings.
	 */
	public static function configured(): self {
		/**
		 * Filter chat rate limits.
		 *
		 * @param array{session: int, ip: int, window: int} $limits Limits.
		 */
		$limits = (array) apply_filters(
			'storecrew_chat_rate_limits',
			array(
				'session' => 20,
				'ip'      => 60,
				'window'  => 300,
			)
		);

		return new self(
			max( 1, (int) ( $limits['session'] ?? 20 ) ),
			max( 1, (int) ( $limits['ip'] ?? 60 ) ),
			max( 10, (int) ( $limits['window'] ?? 300 ) )
		);
	}

	/**
	 * Consume one unit against both windows.
	 *
	 * Returns null when allowed, or the number of seconds to wait when not.
	 *
	 * Both counters are incremented even when the first one refuses. A client
	 * that keeps hammering after a 429 is exactly the client the IP window is
	 * for, and letting the refusal reset its budget would reward it.
	 */
	public function consume( string $session_token, string $ip ): ?int {
		$retry = null;

		$session_wait = $this->hit( self::SCOPE_SESSION, $session_token, $this->session_limit );
		$ip_wait      = $this->hit( self::SCOPE_IP, '' !== $ip ? AuditLogRepository::hash_ip( $ip ) : '', $this->ip_limit );

		foreach ( array( $session_wait, $ip_wait ) as $wait ) {
			if ( null !== $wait ) {
				$retry = null === $retry ? $wait : max( $retry, $wait );
			}
		}

		return $retry;
	}

	/**
	 * How much of the session's allowance is left, without consuming any.
	 */
	public function remaining( string $session_token ): int {
		$state = $this->state( self::SCOPE_SESSION, $session_token );

		return max( 0, $this->session_limit - $state['count'] );
	}

	/**
	 * Clear a session's counters. Test support, and merchant "unblock" actions.
	 */
	public function forget( string $session_token ): void {
		delete_transient( self::PREFIX . self::SCOPE_SESSION . '_' . $this->key( $session_token ) );
	}

	/**
	 * Increment one window. Returns seconds to wait, or null when under limit.
	 */
	private function hit( string $scope, string $identifier, int $limit ): ?int {
		if ( '' === $identifier ) {
			// Nothing to count against. Refusing here would lock out every
			// visitor behind a proxy that strips REMOTE_ADDR; the other window
			// still applies.
			return null;
		}

		$key   = self::PREFIX . $scope . '_' . $this->key( $identifier );
		$state = $this->state( $scope, $identifier );

		++$state['count'];

		$remaining_window = max( 1, $state['expires'] - time() );

		set_transient( $key, $state, $remaining_window );

		return $state['count'] > $limit ? $remaining_window : null;
	}

	/**
	 * Current window state for an identifier.
	 *
	 * @return array{count: int, expires: int}
	 */
	private function state( string $scope, string $identifier ): array {
		$stored = get_transient( self::PREFIX . $scope . '_' . $this->key( $identifier ) );

		if ( ! is_array( $stored ) || ! isset( $stored['expires'] ) || $stored['expires'] <= time() ) {
			return array(
				'count'   => 0,
				'expires' => time() + $this->window_seconds,
			);
		}

		return array(
			'count'   => (int) ( $stored['count'] ?? 0 ),
			'expires' => (int) $stored['expires'],
		);
	}

	/**
	 * Transient names cap at 172 characters; a digest also keeps the raw token
	 * out of the options table and out of any object-cache dump.
	 */
	private function key( string $identifier ): string {
		return substr( hash( 'sha256', $identifier ), 0, 32 );
	}
}
