<?php
/**
 * The outcome of a tool call.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Agent\Tool;

defined( 'ABSPATH' ) || exit;

/**
 * What happened, in a form the model can read.
 *
 * Errors are returned rather than thrown. A tool that fails must hand the model
 * something it can react to — "that order number was not found" lets the agent
 * ask for a correct one, whereas an exception ends the turn and leaves the
 * customer with nothing.
 */
final readonly class ToolResult {

	public const STATUS_OK       = 'ok';
	public const STATUS_ERROR    = 'error';
	public const STATUS_DENIED   = 'denied';
	public const STATUS_PENDING  = 'pending_approval';
	public const STATUS_DISABLED = 'disabled';

	/**
	 * @param array<string, mixed> $data Structured payload, for the inspector.
	 */
	public function __construct(
		public string $status,
		public string $message,
		public array $data = array(),
	) {}

	/**
	 * @param array<string, mixed> $data Payload.
	 */
	public static function ok( string $message, array $data = array() ): self {
		return new self( self::STATUS_OK, $message, $data );
	}

	public static function error( string $message ): self {
		return new self( self::STATUS_ERROR, $message );
	}

	public static function denied( string $message ): self {
		return new self( self::STATUS_DENIED, $message );
	}

	public static function pending( string $message ): self {
		return new self( self::STATUS_PENDING, $message );
	}

	public static function disabled( string $message ): self {
		return new self( self::STATUS_DISABLED, $message );
	}

	public function is_ok(): bool {
		return self::STATUS_OK === $this->status;
	}

	/**
	 * Whether the model should be told this was a failure.
	 */
	public function is_error(): bool {
		return in_array( $this->status, array( self::STATUS_ERROR, self::STATUS_DENIED ), true );
	}

	/**
	 * The text handed back to the model.
	 *
	 * Structured data is appended as JSON so the model can quote specifics —
	 * an answer that says "we have three in your size" needs the numbers, not
	 * a prose summary of them.
	 */
	public function for_model(): string {
		if ( array() === $this->data ) {
			return $this->message;
		}

		return $this->message . "\n\n" . (string) wp_json_encode( $this->data );
	}
}
