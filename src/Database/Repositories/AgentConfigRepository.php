<?php
/**
 * Agent configuration persistence.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Database\Repositories;

use StoreCrew\Database\Repository;
use StoreCrew\Database\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Merchant-editable agent settings.
 *
 * Keyed by agent_id rather than an auto-increment, because there is exactly one
 * configuration per agent and a surrogate key would only invite duplicates.
 *
 * @see docs/04-database-schema.md § 8.3
 */
final class AgentConfigRepository extends Repository {

	public const MODE_AUTO     = 'auto';
	public const MODE_REQUIRED = 'required';
	public const MODE_DISABLED = 'disabled';

	protected function table(): string {
		return Tables::AGENT_CONFIGS;
	}

	/**
	 * Fetch configuration for an agent.
	 *
	 * @return array{
	 *     agent_id: string,
	 *     enabled: bool,
	 *     persona: string,
	 *     guardrails: array<string, mixed>,
	 *     model_policy: array<string, mixed>,
	 *     tool_modes: array<string, string>,
	 *     version: int
	 * }|null
	 */
	public function get( string $agent_id ): ?array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $this->db->get_row(
			$this->db->prepare( 'SELECT * FROM ' . $this->table_name() . ' WHERE agent_id = %s', $agent_id )
		);

		if ( ! $row ) {
			return null;
		}

		return array(
			'agent_id'     => (string) $row->agent_id,
			'enabled'      => '1' === (string) $row->enabled,
			'persona'      => (string) ( $row->persona ?? '' ),
			'guardrails'   => $this->decode_json( $row->guardrails ) ?? array(),
			'model_policy' => $this->decode_json( $row->model_policy ) ?? array(),
			'tool_modes'   => $this->decode_json( $row->tool_modes ) ?? array(),
			'version'      => (int) $row->version,
		);
	}

	/**
	 * Create or update configuration, bumping the version.
	 *
	 * The version is what `agent_runs.prompt_hash` is reconciled against, so a
	 * merchant can tell whether an answer predates a persona change.
	 *
	 * @param array<string, mixed>  $guardrails   Guardrail settings.
	 * @param array<string, mixed>  $model_policy Per-task model choices.
	 * @param array<string, string> $tool_modes   Tool id => auto|required|disabled.
	 */
	public function save(
		string $agent_id,
		bool $enabled,
		string $persona,
		array $guardrails = array(),
		array $model_policy = array(),
		array $tool_modes = array()
	): bool {
		$existing = $this->get( $agent_id );
		$now      = $this->now();

		$data = array(
			'enabled'      => $enabled ? 1 : 0,
			'persona'      => $persona,
			'guardrails'   => $this->encode_json( $guardrails ),
			'model_policy' => $this->encode_json( $model_policy ),
			'tool_modes'   => $this->encode_json( $tool_modes ),
			'version'      => null === $existing ? 1 : $existing['version'] + 1,
			'updated_at'   => $now,
		);

		$formats = array( '%d', '%s', '%s', '%s', '%s', '%d', '%s' );

		if ( null === $existing ) {
			$data['agent_id'] = $agent_id;
			$formats[]        = '%s';

			return $this->insert_row( $data, $formats ) > 0
				|| null !== $this->get( $agent_id );
		}

		return false !== $this->db->update(
			$this->table_name(),
			$data,
			array( 'agent_id' => $agent_id ),
			$formats,
			array( '%s' )
		);
	}

	/**
	 * Autonomy mode for one tool on one agent.
	 *
	 * Defaults to `required` for anything not explicitly configured. An
	 * unconfigured write tool must not act unattended — FR-AGENT-05 makes
	 * autonomy something granted per tool, never assumed.
	 */
	public function tool_mode( string $agent_id, string $tool_id ): string {
		$config = $this->get( $agent_id );

		if ( null === $config ) {
			return self::MODE_REQUIRED;
		}

		$mode = $config['tool_modes'][ $tool_id ] ?? self::MODE_REQUIRED;

		return in_array( $mode, array( self::MODE_AUTO, self::MODE_REQUIRED, self::MODE_DISABLED ), true )
			? $mode
			: self::MODE_REQUIRED;
	}

	/**
	 * Every stored configuration.
	 *
	 * @return list<object>
	 */
	public function all(): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $this->db->get_results( 'SELECT * FROM ' . $this->table_name() . ' ORDER BY agent_id ASC' );
	}

	public function delete_for_agent( string $agent_id ): bool {
		return false !== $this->db->delete(
			$this->table_name(),
			array( 'agent_id' => $agent_id ),
			array( '%s' )
		);
	}
}
