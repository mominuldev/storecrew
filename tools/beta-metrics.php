<?php
/**
 * The private-beta leading indicators, read locally.
 *
 * Run with: wp eval-file wp-content/plugins/storecrew/tools/beta-metrics.php
 *
 * 14 § M2 asks for the beta fleet to be instrumented for the three leading
 * indicators in 02 § 7 — onboarding step drop-off, deflection rate, escalation
 * reasons. This prints all three from one install's own tables. Nothing is
 * transmitted: FR-DIST-11 gates telemetry, and collecting a fleet means asking
 * twenty merchants to run this and paste the output, which is the cost of
 * having no external analytics and is deliberate.
 *
 * Read-only. It writes nothing, so it is safe on a merchant's live store.
 *
 * @package StoreCrew
 */

use StoreCrew\Core\Onboarding;
use StoreCrew\Core\SetupProgress;
use StoreCrew\Database\Repositories\ConversationRepository;
use StoreCrew\Database\Repositories\UsageRepository;
use StoreCrew\Database\Tables;

global $wpdb;

$events        = Tables::name( Tables::USAGE_EVENTS );
$conversations = Tables::name( Tables::CONVERSATIONS );
$runs          = Tables::name( Tables::AGENT_RUNS );

$rule = static function ( string $title ): void {
	echo "\n" . $title . "\n" . str_repeat( '-', 60 ) . "\n";
};

/**
 * Seconds as the observer would write them down.
 */
$elapsed = static function ( int $seconds ): string {
	if ( $seconds < 60 ) {
		return $seconds . 's';
	}

	return sprintf( '%dm %02ds', intdiv( $seconds, 60 ), $seconds % 60 );
};

// ---------------------------------------------------------------------------
$rule( '1. Onboarding — step drop-off and elapsed time' );

$started = SetupProgress::started_at();
$ledger  = ( new SetupProgress( new UsageRepository() ) )->ledger();

if ( in_array( SetupProgress::BACKFILLED, $ledger, true ) ) {
	echo "This install finished setup before step events existed.\n";
	echo "No timings are reported because none were observed — an install that\n";
	echo "was already complete has step times nobody recorded, and inventing\n";
	echo "them would drag every fleet average toward a figure no merchant lived.\n";
	echo "Exclude this install from the onboarding sample.\n";
} elseif ( 0 === $started ) {
	echo "No activation timestamp. This install predates storecrew_activated_at.\n";
} else {
	$steps = array(
		Onboarding::STEP_PROVIDER => 'Connect a provider',
		Onboarding::STEP_SOURCES  => 'Choose what to read',
		Onboarding::STEP_INDEX    => 'Build the index',
		Onboarding::STEP_AGENTS   => 'Put the crew on duty',
		Onboarding::STEP_WIDGET   => 'Turn on the widget',
	);

	printf( "Activated: %s UTC\n\n", gmdate( 'Y-m-d H:i:s', $started ) );
	printf( "  %-22s %-12s %-12s %s\n", 'Step', 'Reached', 'Since start', 'Step took' );

	$previous = $started;
	$last     = null;
	$provider = null;

	foreach ( $steps as $id => $label ) {
		$at = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT recorded_at FROM {$events} WHERE metric = %s ORDER BY id ASC LIMIT 1",
				SetupProgress::metric( $id )
			)
		);

		if ( null === $at ) {
			// ASCII placeholders: printf pads to a byte count, and an em dash is
			// three bytes, so a prettier character silently shears the columns.
			printf( "  %-22s %-12s %-12s %s\n", $label, '-', '-', 'not reached' );
			continue;
		}

		$stamp = (int) strtotime( (string) $at . ' UTC' );
		$last  = $stamp;

		printf(
			"  %-22s %-12s %-12s %s\n",
			$label,
			gmdate( 'H:i:s', $stamp ),
			$elapsed( $stamp - $started ),
			$elapsed( $stamp - $previous )
		);

		if ( Onboarding::STEP_PROVIDER === $id ) {
			$provider = $stamp - $previous;
		}

		$previous = $stamp;
	}

	// The two numbers 14 § M1's protocol asks for, and why they differ: the
	// provider step contains a detour to someone else's website to make an
	// account and generate a key. The >= 15 min criterion applies to the second
	// number; the first is what the merchant actually lives through. A large gap
	// is a finding about the BYO-key cliff (02 § 5.3), not about this UI.
	if ( null !== $last ) {
		$total = $last - $started;

		echo "\n";
		printf( "  Total, as lived:            %s\n", $elapsed( $total ) );

		if ( null !== $provider ) {
			printf(
				"  Total, less provider signup: %s   (the >= 15 min criterion)\n",
				$elapsed( max( 0, $total - $provider ) )
			);
		}

		echo "\n";
		echo "  Elapsed times are wall-clock between observations, not attention.\n";
		echo "  A subject who left the tab open over lunch shows an enormous step;\n";
		echo "  that is why the protocol keeps an observer and counts hesitations.\n";
	}
}

// ---------------------------------------------------------------------------
$rule( '2. Deflection' );

$total_conversations = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$conversations}" );
$escalated           = (int) $wpdb->get_var(
	$wpdb->prepare( "SELECT COUNT(*) FROM {$conversations} WHERE status = %s", ConversationRepository::STATUS_ESCALATED )
);

if ( 0 === $total_conversations ) {
	echo "No conversations yet. Deflection is undefined, not 100%.\n";
} else {
	$deflected = $total_conversations - $escalated;

	printf( "  Conversations:  %d\n", $total_conversations );
	printf( "  Escalated:      %d\n", $escalated );
	printf(
		"  Deflection:     %.1f%%   (M2 exit: >= 40%%; launch target 55%% at 12 months)\n",
		( $deflected / $total_conversations ) * 100
	);

	echo "\n";
	echo "  Counts conversations, not turns, and counts a conversation that was\n";
	echo "  never escalated as deflected — including one the customer abandoned.\n";
	echo "  That flatters the figure and is the same definition the target was\n";
	echo "  set against, so it is comparable; it is not a satisfaction measure.\n";
}

// ---------------------------------------------------------------------------
$rule( '3. Escalation reasons' );

if ( 0 === $escalated ) {
	echo "Nothing escalated.\n";
} else {
	// The reason lives on the runs of escalated conversations: `status` is the
	// turn outcome and `error_code` carries the provider's own code where there
	// was one (Gate 3). The prose summary ChatService writes into a system
	// message is for the merchant reading the inbox; it is not aggregatable, so
	// it is deliberately not the source here.
	$reasons = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT r.status, r.error_code, COUNT(*) AS n
			 FROM {$runs} r
			 INNER JOIN {$conversations} c ON c.id = r.conversation_id
			 WHERE c.status = %s AND r.status <> %s
			 GROUP BY r.status, r.error_code
			 ORDER BY n DESC",
			ConversationRepository::STATUS_ESCALATED,
			'completed'
		)
	);

	if ( array() === $reasons ) {
		echo "  Escalated conversations carry no failed run — escalation was\n";
		echo "  requested rather than triggered by a failure.\n";
	} else {
		printf( "  %-24s %-28s %s\n", 'Run outcome', 'Provider code', 'Count' );

		foreach ( $reasons as $row ) {
			printf(
				"  %-24s %-28s %d\n",
				(string) $row->status,
				'' !== (string) $row->error_code ? (string) $row->error_code : '-',
				(int) $row->n
			);
		}
	}
}

echo "\n";
