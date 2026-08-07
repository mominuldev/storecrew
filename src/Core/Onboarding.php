<?php
/**
 * The guided setup path, as state.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Core;

use StoreCrew\Ai\ModelPolicy;
use StoreCrew\Api\Registry\ProviderRegistry;
use StoreCrew\Chat\ChatSettings;
use StoreCrew\Knowledge\Indexer;
use StoreCrew\Knowledge\SourceSelection;

defined( 'ABSPATH' ) || exit;

/**
 * Where a new install has got to, in five steps (FR-ADMIN-02).
 *
 * Every step's completion is **derived from the thing itself** — a provider
 * that resolves, a decision on record, vectors in the table, an agent on duty,
 * the widget switched on. Nothing here is a "step 3 done" flag a merchant could
 * be shown while the product cannot answer a question, which is the failure
 * this class exists to make impossible.
 *
 * The copy lives in the admin app. This side sends facts: which steps there
 * are, which are done, and which one is blocking. That keeps the console's
 * wording a console concern and stops the same sentence existing twice.
 *
 * One computation, two readers: the bootstrap payload (so every screen knows)
 * and the setup screen itself. A second implementation of "is setup finished"
 * would drift within a release.
 *
 * @see docs/01-prd.md FR-ADMIN-02
 * @see docs/14-milestone-plan.md § M1
 */
final class Onboarding {

	public const STEP_PROVIDER = 'provider';
	public const STEP_SOURCES  = 'sources';
	public const STEP_INDEX    = 'index';
	public const STEP_AGENTS   = 'agents';
	public const STEP_WIDGET   = 'widget';

	/**
	 * @param \Closure(): array<string, mixed> $available_agents Entitled and enabled agents.
	 */
	public function __construct(
		private readonly ProviderRegistry $providers,
		private readonly ModelPolicy $policy,
		private readonly SourceSelection $selection,
		private readonly Indexer $indexer,
		private readonly \Closure $available_agents,
	) {}

	/**
	 * The five steps, in order, with what is done.
	 *
	 * @return array{
	 *     steps: list<array{id: string, done: bool}>,
	 *     current: string,
	 *     complete: bool,
	 *     canEmbed: bool
	 * }
	 */
	public function state(): array {
		$done = array(
			self::STEP_PROVIDER => null !== $this->policy->resolve( ModelPolicy::TASK_CHAT ),
			self::STEP_SOURCES  => $this->selection->chosen() && array() !== $this->selection->enabled(),
			self::STEP_INDEX    => $this->index_ready(),
			self::STEP_AGENTS   => array() !== ( $this->available_agents )(),
			self::STEP_WIDGET   => true === ( ChatSettings::all()['enabled'] ?? false ),
		);

		$steps   = array();
		$current = '';

		foreach ( $done as $id => $is_done ) {
			$steps[] = array(
				'id'   => (string) $id,
				'done' => $is_done,
			);

			if ( ! $is_done && '' === $current ) {
				$current = (string) $id;
			}
		}

		return array(
			'steps'    => $steps,
			'current'  => $current,
			'complete' => '' === $current,
			// The trap worth naming on its own: chat works, nothing can be
			// indexed, and an Anthropic-only merchant would otherwise discover
			// it when the index run silently produces nothing.
			'canEmbed' => $this->providers->can_embed(),
		);
	}

	/**
	 * Is there a searchable index?
	 *
	 * One vector, not a finished run — and the distinction is the whole
	 * criterion. Embedding is a background job whose duration scales with the
	 * catalogue: a 5,000-product store cannot finish it inside the fifteen
	 * minutes 14 § M1 allows, however fast the merchant works. Gating the step
	 * on `pending === 0` made the flow report a merchant unfinished for an hour
	 * because of a queue they cannot hurry, and nagged them from the Overview
	 * the whole time.
	 *
	 * So the step tracks *the merchant's* part: a run was started and the crew
	 * can answer from something. The remainder is not hidden to buy this —
	 * `health.pending` is on the step card, the Knowledge screen, and the
	 * Overview tile, and the step says plainly that reading continues. A step
	 * that reads done while the number beside it says "62 of 5,000" is honest;
	 * one that silently dropped the number would not be.
	 */
	private function index_ready(): bool {
		return $this->indexer->health()['embedded'] > 0;
	}
}
