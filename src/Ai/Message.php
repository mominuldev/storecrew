<?php
/**
 * A conversation turn as sent to a provider.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * Provider-neutral message.
 *
 * Deliberately does NOT carry a `system` role. Anthropic takes the system
 * prompt as a separate top-level field rather than a message, and OpenAI and
 * Gemini each disagree again about where it goes. Modelling it as a role would
 * force every provider to fish it back out of the array; ChatRequest carries it
 * separately instead.
 */
final readonly class Message {

	public const ROLE_USER      = 'user';
	public const ROLE_ASSISTANT = 'assistant';

	public function __construct(
		public string $role,
		public string $content,
	) {
		if ( ! in_array( $role, array( self::ROLE_USER, self::ROLE_ASSISTANT ), true ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'Unsupported message role "%s".', $role )
			);
		}
	}

	public static function user( string $content ): self {
		return new self( self::ROLE_USER, $content );
	}

	public static function assistant( string $content ): self {
		return new self( self::ROLE_ASSISTANT, $content );
	}

	/**
	 * @return array{role: string, content: string}
	 */
	public function to_array(): array {
		return array(
			'role'    => $this->role,
			'content' => $this->content,
		);
	}
}
