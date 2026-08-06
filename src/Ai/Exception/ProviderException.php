<?php
/**
 * Provider failures.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Ai\Exception;

defined( 'ABSPATH' ) || exit;

/**
 * A provider call failed.
 *
 * Carries whether a retry could plausibly succeed, because the distinction is
 * not derivable from the message and every caller needs it. Providers classify
 * by status: 429 and 5xx are transient, 4xx generally is not — retrying a 401
 * just burns the merchant's rate limit to fail identically.
 */
class ProviderException extends \RuntimeException {

	public function __construct(
		string $message,
		private readonly string $provider = '',
		private readonly int $status = 0,
		private readonly bool $retryable = false,
		private readonly int $retry_after = 0,
		private readonly string $error_code = '',
	) {
		parent::__construct( $message );
	}

	public function provider(): string {
		return $this->provider;
	}

	public function status(): int {
		return $this->status;
	}

	public function is_retryable(): bool {
		return $this->retryable;
	}

	/**
	 * Seconds the provider asked us to wait, or 0 if it didn't say.
	 */
	public function retry_after(): int {
		return $this->retry_after;
	}

	public function error_code(): string {
		return $this->error_code;
	}

	/**
	 * Whether this looks like a credential problem worth surfacing in settings.
	 */
	public function is_auth_failure(): bool {
		return in_array( $this->status, array( 401, 403 ), true );
	}
}
