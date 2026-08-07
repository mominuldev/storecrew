<?php
/**
 * The outcome of one agent turn.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Agent;

use StoreCrew\Ai\TokenUsage;

defined( 'ABSPATH' ) || exit;

/**
 * What the agent produced, and how it ended.
 *
 * The outcome is explicit rather than inferred from an empty string, because
 * "answered with nothing", "refused", "ran out of budget", and "the provider
 * failed" all look identical to a caller reading only text — and each needs a
 * different response to the customer.
 */
final readonly class AgentTurn {

	public const OUTCOME_ANSWERED = 'answered';
	public const OUTCOME_REFUSED  = 'refused';
	public const OUTCOME_BUDGET   = 'budget_exceeded';
	public const OUTCOME_FAILED   = 'failed';

	private function __construct(
		public string $outcome,
		public string $agent_id,
		public string $text,
		public int $run_id = 0,
		public ?TokenUsage $usage = null,
		public int $cost_micros = 0,
		public bool $cost_known = true,
		public string $error_code = '',
		public string $error_message = '',
	) {}

	public static function answered(
		string $agent_id,
		int $run_id,
		string $text,
		TokenUsage $usage,
		int $cost,
		bool $cost_known
	): self {
		return new self( self::OUTCOME_ANSWERED, $agent_id, $text, $run_id, $usage, $cost, $cost_known );
	}

	public static function refused( string $agent_id, int $run_id ): self {
		return new self(
			self::OUTCOME_REFUSED,
			$agent_id,
			'I am not able to help with that. Let me pass you to the store team.',
			$run_id
		);
	}

	public static function budget_exceeded( string $agent_id, int $run_id, string $reason, string $partial ): self {
		return new self(
			self::OUTCOME_BUDGET,
			$agent_id,
			'' !== $partial
				? $partial
				: 'I was not able to finish working that out. Let me pass you to the store team.',
			$run_id,
			null,
			0,
			true,
			$reason
		);
	}

	public static function failed( string $agent_id, string $code, string $message, int $run_id = 0 ): self {
		return new self(
			self::OUTCOME_FAILED,
			$agent_id,
			'Something went wrong on our side. Please try again in a moment.',
			$run_id,
			null,
			0,
			true,
			$code,
			$message
		);
	}

	public function succeeded(): bool {
		return self::OUTCOME_ANSWERED === $this->outcome;
	}

	/**
	 * Whether a human should pick this up (FR-SUPPORT-07).
	 */
	public function needs_escalation(): bool {
		return in_array( $this->outcome, array( self::OUTCOME_REFUSED, self::OUTCOME_BUDGET, self::OUTCOME_FAILED ), true );
	}
}
