<?php
/**
 * Chat capability.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Ai;

use StoreCrew\Ai\Exception\ProviderException;

defined( 'ABSPATH' ) || exit;

/**
 * A provider that can hold a conversation.
 */
interface ChatProviderInterface extends ProviderInterface {

	/**
	 * Complete a chat turn.
	 *
	 * @throws ProviderException On transport failure or an API error.
	 */
	public function chat( ChatRequest $request ): ChatResponse;

	/**
	 * Default model ids this provider suggests, newest first.
	 *
	 * @return list<string>
	 */
	public function default_models(): array;
}
