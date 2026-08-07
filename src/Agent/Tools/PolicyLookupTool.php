<?php
/**
 * Store policy lookup.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Agent\Tools;

use StoreCrew\Agent\Tool\ToolContext;
use StoreCrew\Agent\Tool\ToolInterface;
use StoreCrew\Agent\Tool\ToolResult;
use StoreCrew\Ai\ToolDefinition;
use StoreCrew\Knowledge\Retriever;

defined( 'ABSPATH' ) || exit;

/**
 * Answers policy questions from the merchant's own documents.
 *
 * FR-SUPPORT-04 requires policy answers to be grounded strictly in indexed
 * content. That constraint exists because a model asked about a returns window
 * will confidently invent thirty days — a number that is right often enough to
 * be dangerous and wrong often enough to cost a chargeback.
 *
 * When nothing is indexed, this says so rather than returning an empty result
 * the model would fill in for itself.
 */
final class PolicyLookupTool implements ToolInterface {

	public const ID = 'policy.lookup';

	public function __construct(
		private readonly Retriever $retriever,
	) {}

	public function id(): string {
		return self::ID;
	}

	public function definition(): ToolDefinition {
		return new ToolDefinition(
			self::ID,
			'Look up what this store\'s own policies say — returns, refunds, exchanges, shipping, '
			. 'delivery times, warranties. Use this whenever a customer asks about a policy. '
			. 'Never answer a policy question from general knowledge: every store differs, and '
			. 'this returns what THIS store actually published.',
			array(
				'type'       => 'object',
				'properties' => array(
					'question' => array(
						'type'        => 'string',
						'description' => 'The policy question, in the customer\'s words.',
					),
				),
				'required'   => array( 'question' ),
			)
		);
	}

	public function intent(): string {
		return self::INTENT_READ;
	}

	public function required_capability(): string {
		return '';
	}

	public function requires_identity(): bool {
		return false;
	}

	public function execute( ToolContext $context, array $input ): ToolResult {
		$question = trim( (string) ( $input['question'] ?? '' ) );

		if ( '' === $question ) {
			return ToolResult::error( 'I need to know what policy to look up.' );
		}

		$found = $this->retriever->retrieve( $question, 4 );

		$passages = array();

		foreach ( $found['results'] as $chunk ) {
			if ( 'post' !== ( $chunk['sourceType'] ?? '' ) ) {
				continue;
			}

			$passages[] = array(
				'source' => (string) ( $chunk['sourceTitle'] ?? '' ),
				'url'    => (string) ( $chunk['sourceUrl'] ?? '' ),
				'text'   => (string) ( $chunk['content'] ?? '' ),
			);
		}

		if ( array() === $passages ) {
			// The honest answer. A model handed nothing will otherwise supply a
			// plausible policy of its own.
			return ToolResult::ok(
				'This store has not published anything covering that. Tell the customer you do not '
				. 'have that detail and offer to pass them to the store team — do not guess a policy.',
				array( 'passages' => array() )
			);
		}

		return ToolResult::ok(
			'Answer using only these passages. If they do not cover the question, say so rather than inferring.',
			array( 'passages' => $passages )
		);
	}
}
