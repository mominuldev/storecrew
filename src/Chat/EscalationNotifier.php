<?php
/**
 * Tells a human that a conversation needs one.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Chat;

use StoreCrew\Agent\AgentTurn;
use StoreCrew\Database\Repositories\ConversationRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The push half of FR-SUPPORT-07 (the Inbox is the pull half, G4-D4).
 *
 * An inbox nobody is told to check is not an inbox. When a conversation
 * escalates, the merchant gets an email naming why and linking straight into
 * the inspector — not the customer's words, which may contain anything, and
 * not the transcript, which belongs behind the capability check the admin
 * screen enforces. The email is a doorbell, not a copy of the door.
 *
 * One email per escalation, not per failed turn: the caller passes whether
 * the status actually transitioned, and only the transition rings. A
 * conversation that fails five times while already escalated is one problem,
 * not five emails — a merchant whose phone buzzes per retry stops reading
 * exactly the messages this exists to make them read.
 */
final class EscalationNotifier {

	public function __construct(
		private readonly ConversationRepository $conversations,
	) {}

	/**
	 * Send the doorbell email for a newly escalated conversation.
	 */
	public function notify( int $conversation_id, AgentTurn $turn ): bool {
		$conversation = $this->conversations->find( $conversation_id );

		if ( null === $conversation ) {
			return false;
		}

		/**
		 * Filter who is told about an escalation.
		 *
		 * An empty string disables the email entirely — some merchants live in
		 * the console and want no mail.
		 *
		 * @param string $recipient Recipient address.
		 * @param object $conversation Conversation row.
		 */
		$recipient = (string) apply_filters(
			'storecrew_escalation_recipient',
			(string) get_option( 'admin_email' ),
			$conversation
		);

		if ( '' === $recipient || ! is_email( $recipient ) ) {
			return false;
		}

		$subject = sprintf(
			/* translators: %s: site name */
			__( '[%s] A customer conversation needs you', 'storecrew' ),
			wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES )
		);

		$reason = match ( $turn->outcome ) {
			AgentTurn::OUTCOME_REFUSED => __( 'The agent declined to handle the request.', 'storecrew' ),
			AgentTurn::OUTCOME_BUDGET  => __( 'The agent hit its limit before finishing.', 'storecrew' ),
			default                    => __( 'Something failed while answering.', 'storecrew' ),
		};

		$url = admin_url( 'admin.php?page=storecrew#/conversation/' . (string) $conversation->uuid );

		$lines = array(
			__( 'A customer conversation has been handed to you.', 'storecrew' ),
			'',
			sprintf( /* translators: %s: reason sentence */ __( 'Why: %s', 'storecrew' ), $reason ),
			sprintf(
				/* translators: %s: yes/no */
				__( 'Customer identified: %s', 'storecrew' ),
				'1' === (string) $conversation->identity_verified ? __( 'yes', 'storecrew' ) : __( 'no', 'storecrew' )
			),
			sprintf( /* translators: %d: number of messages */ __( 'Messages so far: %d', 'storecrew' ), (int) $conversation->message_count ),
			'',
			__( 'Open the conversation:', 'storecrew' ),
			$url,
			'',
			__( 'The customer can keep typing while they wait — replies you make from the store count.', 'storecrew' ),
		);

		return wp_mail( $recipient, $subject, implode( "\n", $lines ) );
	}
}
