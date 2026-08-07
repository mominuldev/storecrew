<?php
/**
 * Context carried across a conversation and between agents.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Agent;

defined( 'ABSPATH' ) || exit;

/**
 * What one agent knows, in a form another can inherit.
 *
 * FR-AGENT-03 requires a handoff to preserve context. This is a **structured
 * object rather than a concatenated transcript** deliberately: handing the next
 * agent a wall of prior conversation makes it re-derive facts that were already
 * established, and costs tokens doing it. Passing the resolved facts — which
 * order we are discussing, which products came up, what the last agent
 * concluded — is both cheaper and more reliable.
 *
 * Everything here is derived from verified session state or from tool results,
 * never from model prose.
 */
final class SharedContext {

	/**
	 * @var array<string, mixed>
	 */
	private array $facts = array();

	/**
	 * @var list<int>
	 */
	private array $product_ids = array();

	/**
	 * @var list<array<string, mixed>>
	 */
	private array $retrieved = array();

	private string $handoff_note = '';

	public function __construct(
		public readonly int $conversation_id,
		public readonly int $customer_id = 0,
	) {}

	/**
	 * Record a resolved fact.
	 */
	public function remember( string $key, mixed $value ): void {
		$this->facts[ $key ] = $value;
	}

	public function recall( string $key, mixed $default = null ): mixed {
		return $this->facts[ $key ] ?? $default;
	}

	/**
	 * Note a product the conversation has touched.
	 */
	public function saw_product( int $product_id ): void {
		if ( $product_id > 0 && ! in_array( $product_id, $this->product_ids, true ) ) {
			$this->product_ids[] = $product_id;
		}
	}

	/**
	 * @return list<int>
	 */
	public function product_ids(): array {
		return $this->product_ids;
	}

	/**
	 * Store what retrieval returned, for the run record.
	 *
	 * Ids and scores only — never chunk text, which would duplicate the corpus
	 * into every run row. Accumulates rather than replaces: one turn can
	 * retrieve more than once (a product search and then a policy lookup), and
	 * the run record should show everything that grounded the answer. A chunk
	 * retrieved twice keeps its best score.
	 *
	 * @param list<array<string, mixed>> $chunks Retrieved chunks.
	 */
	public function set_retrieved( array $chunks ): void {
		$best = array();

		foreach ( $this->retrieved as $entry ) {
			$best[ (int) $entry['id'] ] = (float) $entry['score'];
		}

		foreach ( $chunks as $c ) {
			if ( ! is_array( $c ) ) {
				continue;
			}

			$id    = (int) ( $c['id'] ?? 0 );
			$score = round( (float) ( $c['score'] ?? 0 ), 4 );

			if ( ! isset( $best[ $id ] ) || $score > $best[ $id ] ) {
				$best[ $id ] = $score;
			}
		}

		$this->retrieved = array();

		foreach ( $best as $id => $score ) {
			$this->retrieved[] = array(
				'id'    => $id,
				'score' => $score,
			);
		}
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function retrieved(): array {
		return $this->retrieved;
	}

	/**
	 * What the handing-off agent wants the next one to know.
	 */
	public function set_handoff_note( string $note ): void {
		$this->handoff_note = $note;
	}

	public function handoff_note(): string {
		return $this->handoff_note;
	}

	/**
	 * Render the context as prompt text.
	 *
	 * Empty when there is nothing resolved yet — an empty "Context:" heading
	 * teaches the model that the section is usually noise.
	 */
	public function to_prompt(): string {
		$lines = array();

		if ( '' !== $this->handoff_note ) {
			$lines[] = 'Handed over from another agent: ' . $this->handoff_note;
		}

		if ( array() !== $this->product_ids ) {
			// Ids, not names — the receiving agent reads details live through
			// its own tools rather than trusting a summary (FR-KB-08).
			$lines[] = 'Products already shown to the customer in this conversation (ids): '
				. implode( ', ', array_map( 'strval', $this->product_ids ) );
		}

        foreach ( $this->facts as $key => $value ) {
			$lines[] = sprintf( '%s: %s', $key, is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value ) );
		}

		return array() === $lines ? '' : implode( "\n", $lines );
	}
}
