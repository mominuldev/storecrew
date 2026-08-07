<?php
/**
 * Routes a turn to the right agent.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Agent;

use StoreCrew\Ai\ChatProviderInterface;
use StoreCrew\Ai\ChatRequest;
use StoreCrew\Ai\Exception\ProviderException;
use StoreCrew\Ai\Message;
use StoreCrew\Ai\ModelPolicy;
use StoreCrew\Ai\SpendGuard;
use StoreCrew\Api\Registry\AgentRegistry;
use StoreCrew\Api\Registry\ProviderRegistry;
use StoreCrew\Database\Repositories\AgentConfigRepository;
use StoreCrew\Licensing\FeatureGate;

defined( 'ABSPATH' ) || exit;

/**
 * Decides who answers, and hands over when someone else should.
 *
 * FR-AGENT-02: exactly one agent owns a turn. Routing uses a small model on the
 * `routing` task rather than the chat model, because classification is cheap,
 * high-volume, and does not improve with capability — paying flagship rates to
 * pick between two labels is the clearest waste in the whole pipeline.
 *
 * Routing failure is never fatal. If the classifier errors, is unconfigured, or
 * returns something unrecognised, the turn falls through to the default agent.
 * A customer asking a question must get an answer from *somebody*.
 *
 * @see docs/01-prd.md FR-AGENT-02, FR-AGENT-03
 */
final class Orchestrator {

	public function __construct(
		private readonly AgentRegistry $agents,
		private readonly AgentRunner $runner,
		private readonly ProviderRegistry $providers,
		private readonly ModelPolicy $policy,
		private readonly FeatureGate $features,
		private readonly AgentConfigRepository $configs,
		private readonly SpendGuard $spend,
	) {}

	/**
	 * Handle one customer turn.
	 *
	 * @param list<Message> $history Prior turns, oldest first.
	 */
	public function handle( string $message, array $history, SharedContext $context, ?callable $on_delta = null ): AgentTurn {
		$available = $this->available_agents();

		if ( array() === $available ) {
			return AgentTurn::failed( '', 'no_agents', 'No agents are enabled.' );
		}

		$agent = $this->route( $message, $available );

		$history[] = Message::user( $message );

		$turn = $this->runner->run( $agent, $history, $context, null, $on_delta );

		/**
		 * Fires after an agent finishes a turn.
		 *
		 * @param AgentTurn     $turn    The outcome.
		 * @param Agent         $agent   Who handled it.
		 * @param SharedContext $context Conversation context.
		 */
		do_action( 'storecrew_agent_turn_completed', $turn, $agent, $context );

		return $turn;
	}

	/**
	 * Hand a conversation to another agent, carrying context (FR-AGENT-03).
	 *
	 * @param list<Message> $history Prior turns.
	 */
	public function handoff(
		string $to_agent_id,
		string $note,
		array $history,
		SharedContext $context
	): AgentTurn {
		$agent = $this->agents->get( $to_agent_id );

		if ( ! $agent instanceof Agent ) {
			return AgentTurn::failed( $to_agent_id, 'unknown_agent', 'That agent does not exist.' );
		}

		// The note is the whole point of a structured handoff: the receiving
		// agent inherits the conclusion rather than re-reading the transcript
		// and re-deriving it.
		$context->set_handoff_note( $note );

		do_action( 'storecrew_handoff', $to_agent_id, $note, $context );

		$turn = $this->runner->run( $agent, $history, $context );

		// The receiving agent's turn is a turn like any other — an observer
		// counting turns must not silently miss the handed-off ones.
		do_action( 'storecrew_agent_turn_completed', $turn, $agent, $context );

		return $turn;
	}

	/**
	 * Agents that are entitled and enabled.
	 *
	 * @return array<string, Agent>
	 */
	public function available_agents(): array {
		return $this->agents->available(
			fn ( string $feature ): bool => $this->features->enabled( $feature ),
			function ( string $agent_id ): bool {
				$config = $this->configs->get( $agent_id );

				// Absent configuration means shipped defaults, which are on.
				return null === $config || $config['enabled'];
			}
		);
	}

	/**
	 * Choose the owning agent for a message.
	 *
	 * @param array<string, Agent> $available Candidates.
	 */
	private function route( string $message, array $available ): Agent {
		$default = $this->default_agent( $available );

		if ( count( $available ) < 2 ) {
			// Nothing to decide.
			return $default;
		}

		// The classifier is a provider call too. Past the spend cap the runner
		// is about to refuse the turn anyway, so paying flag-fall for routing
		// on the way there would leak spend on every capped turn (FR-AI-06).
		// Under the warn behaviour allows_call() passes and routing proceeds.
		if ( ! $this->spend->allows_call() ) {
			return $default;
		}

		$resolved = $this->policy->resolve( ModelPolicy::TASK_ROUTING );

		if ( null === $resolved ) {
			return $default;
		}

		$provider = $this->providers->get( $resolved['provider'] );

		if ( ! $provider instanceof ChatProviderInterface ) {
			return $default;
		}

		$catalogue = array();

		foreach ( $available as $agent ) {
			$catalogue[] = sprintf( '- %s: %s', $agent->id, $agent->label . ' — ' . $agent->mission );
		}

		try {
			$response = $provider->chat(
				new ChatRequest(
					model: $resolved['model'],
					messages: array( Message::user( $message ) ),
					system: "Choose which specialist should answer this customer message.\n\n"
						. implode( "\n", $catalogue )
						. "\n\nReply with the identifier only, nothing else.",
					// Small: the answer is one token's worth of information.
					max_tokens: 16,
				)
			);
		} catch ( ProviderException ) {
			// A classifier outage must not cost the customer an answer.
			return $default;
		}

		$choice = trim( strtolower( $response->text ) );

		foreach ( $available as $id => $agent ) {
			if ( str_contains( $choice, strtolower( (string) $id ) ) ) {
				return $agent;
			}
		}

		return $default;
	}

	/**
	 * @param array<string, Agent> $available Candidates.
	 */
	private function default_agent( array $available ): Agent {
		/**
		 * Filter which agent handles a turn when routing cannot decide.
		 *
		 * @param string               $agent_id  Default agent id.
		 * @param array<string, Agent> $available Candidates.
		 */
		$preferred = (string) apply_filters( 'storecrew_default_agent', 'support', $available );

		return $available[ $preferred ] ?? reset( $available );
	}
}
