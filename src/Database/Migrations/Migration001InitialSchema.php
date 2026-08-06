<?php
/**
 * Initial schema.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Database\Migrations;

use StoreCrew\Database\MigrationInterface;
use StoreCrew\Database\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Creates every table the free plugin owns.
 *
 * dbDelta is unforgiving and fails *silently* when its formatting rules are
 * broken, so the statements below follow them exactly: one field per line, two
 * spaces after PRIMARY KEY, KEY rather than INDEX, every key named, and
 * lowercase type names so repeat runs do not emit pointless ALTERs.
 *
 * Two column names deviate from docs/04-database-schema.md on purpose:
 * `cursor` is a reserved word in MySQL, and `authorization` is reserved in the
 * SQL standard. Both are renamed rather than escaped, because a column that
 * needs backticks forever is a trap for every query written later.
 *
 * @see docs/04-database-schema.md
 */
final class Migration001InitialSchema implements MigrationInterface {

	public function version(): int {
		return 1;
	}

	public function description(): string {
		return 'Initial schema';
	}

	public function up(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate = $wpdb->get_charset_collate();

		foreach ( $this->statements( $collate ) as $table => $sql ) {
			dbDelta( $sql );

			if ( ! Tables::exists( $table ) ) {
				throw new \RuntimeException(
					sprintf( 'Table %s was not created.', Tables::name( $table ) )
				);
			}
		}
	}

	/**
	 * CREATE TABLE statements keyed by unqualified table name.
	 *
	 * @return array<string, string>
	 */
	private function statements( string $collate ): array {
		$statements = array();

		$statements[ Tables::CONVERSATIONS ] = '
			CREATE TABLE ' . Tables::name( Tables::CONVERSATIONS ) . " (
				id bigint(20) unsigned NOT NULL auto_increment,
				uuid char(36) NOT NULL,
				session_token char(64) NOT NULL default '',
				customer_id bigint(20) unsigned NOT NULL default 0,
				status varchar(20) NOT NULL default 'open',
				channel varchar(32) NOT NULL default 'widget',
				locale varchar(10) NOT NULL default '',
				identity_verified tinyint(1) unsigned NOT NULL default 0,
				verified_order_id bigint(20) unsigned NOT NULL default 0,
				verified_at datetime default NULL,
				message_count int(10) unsigned NOT NULL default 0,
				run_count int(10) unsigned NOT NULL default 0,
				escalated_at datetime default NULL,
				started_at datetime NOT NULL,
				last_activity_at datetime NOT NULL,
				closed_at datetime default NULL,
				meta longtext default NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uuid (uuid),
				KEY session_token (session_token),
				KEY customer_id (customer_id),
				KEY status_activity (status, last_activity_at),
				KEY started_at (started_at)
			) {$collate};";

		$statements[ Tables::MESSAGES ] = '
			CREATE TABLE ' . Tables::name( Tables::MESSAGES ) . " (
				id bigint(20) unsigned NOT NULL auto_increment,
				conversation_id bigint(20) unsigned NOT NULL,
				role varchar(16) NOT NULL,
				agent_id varchar(64) NOT NULL default '',
				content longtext NOT NULL,
				content_format varchar(16) NOT NULL default 'markdown',
				tokens_in int(10) unsigned NOT NULL default 0,
				tokens_out int(10) unsigned NOT NULL default 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY conversation_seq (conversation_id, id),
				KEY created_at (created_at)
			) {$collate};";

		$statements[ Tables::AGENT_RUNS ] = '
			CREATE TABLE ' . Tables::name( Tables::AGENT_RUNS ) . " (
				id bigint(20) unsigned NOT NULL auto_increment,
				conversation_id bigint(20) unsigned NOT NULL,
				message_id bigint(20) unsigned NOT NULL default 0,
				agent_id varchar(64) NOT NULL,
				provider varchar(32) NOT NULL default '',
				model varchar(64) NOT NULL default '',
				prompt_hash char(64) NOT NULL default '',
				status varchar(24) NOT NULL default 'running',
				tool_call_count smallint(5) unsigned NOT NULL default 0,
				tokens_in int(10) unsigned NOT NULL default 0,
				tokens_out int(10) unsigned NOT NULL default 0,
				cost_micros bigint(20) unsigned NOT NULL default 0,
				latency_ms int(10) unsigned NOT NULL default 0,
				retrieved longtext default NULL,
				error_code varchar(64) NOT NULL default '',
				error_message text default NULL,
				started_at datetime NOT NULL,
				finished_at datetime default NULL,
				PRIMARY KEY  (id),
				KEY conversation_id (conversation_id),
				KEY agent_started (agent_id, started_at),
				KEY status (status)
			) {$collate};";

		$statements[ Tables::TOOL_CALLS ] = '
			CREATE TABLE ' . Tables::name( Tables::TOOL_CALLS ) . " (
				id bigint(20) unsigned NOT NULL auto_increment,
				agent_run_id bigint(20) unsigned NOT NULL,
				conversation_id bigint(20) unsigned NOT NULL,
				tool_id varchar(64) NOT NULL,
				intent varchar(8) NOT NULL default 'read',
				auth_mode varchar(16) NOT NULL default 'auto',
				arguments longtext default NULL,
				result longtext default NULL,
				status varchar(16) NOT NULL default 'pending',
				approved_by bigint(20) unsigned NOT NULL default 0,
				approved_at datetime default NULL,
				duration_ms int(10) unsigned NOT NULL default 0,
				error_message text default NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY agent_run_id (agent_run_id),
				KEY conversation_id (conversation_id),
				KEY approval_queue (auth_mode, status, created_at),
				KEY tool_created (tool_id, created_at)
			) {$collate};";

		$statements[ Tables::KNOWLEDGE_SOURCES ] = '
			CREATE TABLE ' . Tables::name( Tables::KNOWLEDGE_SOURCES ) . " (
				id bigint(20) unsigned NOT NULL auto_increment,
				source_key char(64) NOT NULL,
				source_type varchar(32) NOT NULL,
				object_id bigint(20) unsigned NOT NULL default 0,
				external_ref varchar(191) NOT NULL default '',
				title text default NULL,
				url text default NULL,
				content_hash char(64) NOT NULL default '',
				status varchar(16) NOT NULL default 'pending',
				chunk_count int(10) unsigned NOT NULL default 0,
				error_message text default NULL,
				indexed_at datetime default NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY source_key (source_key),
				KEY source_type_object (source_type, object_id),
				KEY status (status),
				KEY content_hash (content_hash)
			) {$collate};";

		$statements[ Tables::KNOWLEDGE_CHUNKS ] = '
			CREATE TABLE ' . Tables::name( Tables::KNOWLEDGE_CHUNKS ) . " (
				id bigint(20) unsigned NOT NULL auto_increment,
				source_id bigint(20) unsigned NOT NULL,
				chunk_index int(10) unsigned NOT NULL default 0,
				content longtext NOT NULL,
				content_tokens int(10) unsigned NOT NULL default 0,
				embedding longblob default NULL,
				embedding_model varchar(64) NOT NULL default '',
				embedding_dims smallint(5) unsigned NOT NULL default 0,
				embedded_at datetime default NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY source_chunk (source_id, chunk_index),
				KEY embedding_model (embedding_model),
				FULLTEXT KEY content_ft (content)
			) {$collate};";

		$statements[ Tables::USAGE_EVENTS ] = '
			CREATE TABLE ' . Tables::name( Tables::USAGE_EVENTS ) . " (
				id bigint(20) unsigned NOT NULL auto_increment,
				metric varchar(32) NOT NULL,
				quantity bigint(20) unsigned NOT NULL default 1,
				period char(7) NOT NULL,
				conversation_id bigint(20) unsigned NOT NULL default 0,
				agent_id varchar(64) NOT NULL default '',
				provider varchar(32) NOT NULL default '',
				model varchar(64) NOT NULL default '',
				cost_micros bigint(20) unsigned NOT NULL default 0,
				recorded_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY metric_period (metric, period),
				KEY period (period),
				KEY conversation_id (conversation_id),
				KEY recorded_at (recorded_at)
			) {$collate};";

		$statements[ Tables::USAGE_COUNTERS ] = '
			CREATE TABLE ' . Tables::name( Tables::USAGE_COUNTERS ) . " (
				metric varchar(32) NOT NULL,
				period char(7) NOT NULL,
				total bigint(20) unsigned NOT NULL default 0,
				cost_micros bigint(20) unsigned NOT NULL default 0,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (metric, period)
			) {$collate};";

		$statements[ Tables::INDEX_RUNS ] = '
			CREATE TABLE ' . Tables::name( Tables::INDEX_RUNS ) . " (
				id bigint(20) unsigned NOT NULL auto_increment,
				type varchar(32) NOT NULL default 'full',
				status varchar(16) NOT NULL default 'queued',
				total int(10) unsigned NOT NULL default 0,
				processed int(10) unsigned NOT NULL default 0,
				failed int(10) unsigned NOT NULL default 0,
				cursor_position varchar(191) NOT NULL default '',
				cost_micros bigint(20) unsigned NOT NULL default 0,
				last_error text default NULL,
				heartbeat_at datetime default NULL,
				started_at datetime NOT NULL,
				finished_at datetime default NULL,
				PRIMARY KEY  (id),
				KEY status_started (status, started_at)
			) {$collate};";

		$statements[ Tables::AUDIT_LOG ] = '
			CREATE TABLE ' . Tables::name( Tables::AUDIT_LOG ) . " (
				id bigint(20) unsigned NOT NULL auto_increment,
				actor_type varchar(16) NOT NULL default 'system',
				actor_id varchar(64) NOT NULL default '',
				action varchar(64) NOT NULL,
				object_type varchar(32) NOT NULL default '',
				object_id bigint(20) unsigned NOT NULL default 0,
				ip_hash char(64) NOT NULL default '',
				data longtext default NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY action_created (action, created_at),
				KEY actor (actor_type, actor_id),
				KEY object (object_type, object_id)
			) {$collate};";

		$statements[ Tables::AGENT_CONFIGS ] = '
			CREATE TABLE ' . Tables::name( Tables::AGENT_CONFIGS ) . " (
				agent_id varchar(64) NOT NULL,
				enabled tinyint(1) unsigned NOT NULL default 1,
				persona longtext default NULL,
				guardrails longtext default NULL,
				model_policy longtext default NULL,
				tool_modes longtext default NULL,
				version int(10) unsigned NOT NULL default 1,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (agent_id)
			) {$collate};";

		return $statements;
	}
}
