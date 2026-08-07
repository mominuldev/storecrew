<?php
/**
 * WordPress personal-data exporter and eraser (04 § 11, GDPR).
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Core\Privacy;

use StoreCrew\Database\Repositories\ConversationRepository;
use StoreCrew\Database\Repositories\MessageRepository;

defined( 'ABSPATH' ) || exit;

/**
 * What StoreCrew knows about a person, exportable and erasable.
 *
 * Registered with core's privacy tools so a merchant answers a data request
 * from the screen WordPress already gives them, rather than from phpMyAdmin.
 *
 * The identity model bounds what is reachable: conversations are attributable
 * to a person only through `customer_id` — a WordPress account. An anonymous
 * visitor's conversation carries a session-token digest and nothing else, so
 * it is not *identifiable* as theirs by email, and a privacy request cannot
 * reach it. That is a property, not a gap: honouring "erase what you hold on
 * jane@example.com" must not require first *linking* Jane to conversations
 * the system deliberately never linked to her.
 *
 * Erasure anonymises rather than deletes (the 04 § 11 specification):
 * `customer_id`, the proven order, and the session binding are stripped and
 * message content is blanked, while row counts survive — the shape of past
 * traffic is analytics, and aggregate usage counters contain no personal
 * data. Deleting them would corrupt billing history.
 */
final class PersonalData {

	private const GROUP_ID = 'storecrew-conversations';

	private const PAGE_SIZE = 25;

	public function __construct(
		private readonly ConversationRepository $conversations,
		private readonly MessageRepository $messages,
	) {}

	/**
	 * @param array<string, array<string, mixed>> $exporters Registered exporters.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function register_exporter( $exporters ): array {
		$exporters = is_array( $exporters ) ? $exporters : array();

		$exporters['storecrew'] = array(
			'exporter_friendly_name' => __( 'StoreCrew AI conversations', 'storecrew' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * @param array<string, array<string, mixed>> $erasers Registered erasers.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function register_eraser( $erasers ): array {
		$erasers = is_array( $erasers ) ? $erasers : array();

		$erasers['storecrew'] = array(
			'eraser_friendly_name' => __( 'StoreCrew AI conversations', 'storecrew' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * One page of a person's conversations, as core's export shape.
	 *
	 * @return array{data: list<array<string, mixed>>, done: bool}
	 */
	public function export( string $email, int $page = 1 ): array {
		$customer_id = $this->customer_id( $email );

		if ( 0 === $customer_id ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$offset = ( max( 1, $page ) - 1 ) * self::PAGE_SIZE;
		$rows   = $this->conversations->for_customer( $customer_id, self::PAGE_SIZE, $offset );

		$data = array();

		foreach ( $rows as $conversation ) {
			$transcript = array();

			foreach ( $this->messages->for_conversation( (int) $conversation->id ) as $message ) {
				// Only what the person and the store said to each other.
				// System rows are operator notes about the conversation, not
				// data held on the person.
				if ( ! in_array( (string) $message->role, array( MessageRepository::ROLE_USER, MessageRepository::ROLE_ASSISTANT ), true ) ) {
					continue;
				}

				$transcript[] = sprintf(
					'[%s] %s: %s',
					(string) $message->created_at,
					MessageRepository::ROLE_USER === (string) $message->role ? __( 'You', 'storecrew' ) : __( 'Store', 'storecrew' ),
					(string) $message->content
				);
			}

			$data[] = array(
				'group_id'    => self::GROUP_ID,
				'group_label' => __( 'Store chat conversations', 'storecrew' ),
				'item_id'     => 'storecrew-conversation-' . (string) $conversation->uuid,
				'data'        => array(
					array(
						'name'  => __( 'Started', 'storecrew' ),
						'value' => (string) $conversation->started_at,
					),
					array(
						'name'  => __( 'Status', 'storecrew' ),
						'value' => (string) $conversation->status,
					),
					array(
						'name'  => __( 'Order discussed', 'storecrew' ),
						'value' => (int) $conversation->verified_order_id > 0
							? '#' . (int) $conversation->verified_order_id
							: __( 'None', 'storecrew' ),
					),
					array(
						'name'  => __( 'Transcript', 'storecrew' ),
						'value' => implode( "\n", $transcript ),
					),
				),
			);
		}

		return array(
			'data' => $data,
			'done' => count( $rows ) < self::PAGE_SIZE,
		);
	}

	/**
	 * Erase a person's conversations, as core's eraser shape.
	 *
	 * Content is blanked page by page; the identity strip on the conversation
	 * rows happens once, on the final page, because `anonymise_customer`
	 * severs the very link (`customer_id`) the pager walks — stripping it
	 * first would orphan every later page's content un-blanked.
	 *
	 * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
	 */
	public function erase( string $email, int $page = 1 ): array {
		$customer_id = $this->customer_id( $email );

		if ( 0 === $customer_id ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$offset = ( max( 1, $page ) - 1 ) * self::PAGE_SIZE;
		$rows   = $this->conversations->for_customer( $customer_id, self::PAGE_SIZE, $offset );
		$ids    = array_map( static fn ( object $row ): int => (int) $row->id, $rows );

		$blanked = $this->messages->erase_content_for_conversations( $ids );
		$done    = count( $rows ) < self::PAGE_SIZE;

		if ( $done ) {
			$this->conversations->anonymise_customer( $customer_id );
		}

		return array(
			'items_removed'  => $blanked > 0 || $done,
			// Anonymised rows survive by design; core's wording for that is
			// "retained", with the reason given alongside.
			'items_retained' => true,
			'messages'       => array(
				__( 'Conversation records were anonymised: message content erased, and the account, verified order, and session references removed. Aggregate counters retain no personal data.', 'storecrew' ),
			),
			'done'           => $done,
		);
	}

	/**
	 * The WordPress account an email identifies, or 0.
	 */
	private function customer_id( string $email ): int {
		$user = get_user_by( 'email', trim( $email ) );

		return $user instanceof \WP_User ? (int) $user->ID : 0;
	}
}
