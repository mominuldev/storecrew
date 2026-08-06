<?php
/**
 * Role capabilities.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * StoreCrew's own capabilities, mapped to WordPress roles at activation.
 *
 * Custom capabilities rather than reusing `manage_woocommerce` so a merchant can
 * grant a staff member access to conversations without also granting them the
 * whole store. This matters for FR-AGENT-04: tool authorisation derives from the
 * session's capabilities, so the capability set is a security boundary, not a
 * convenience.
 *
 * FR-CORE-05.
 */
final class Capabilities {

	public const MANAGE         = 'storecrew_manage';
	public const VIEW_ANALYTICS = 'storecrew_view_analytics';
	public const MANAGE_AGENTS  = 'storecrew_manage_agents';
	public const CONVERSE       = 'storecrew_converse';

	/**
	 * Every capability this plugin defines.
	 *
	 * @return list<string>
	 */
	public static function all(): array {
		return array(
			self::MANAGE,
			self::VIEW_ANALYTICS,
			self::MANAGE_AGENTS,
			self::CONVERSE,
		);
	}

	/**
	 * Which capabilities each role receives.
	 *
	 * @return array<string, list<string>>
	 */
	public static function role_map(): array {
		return array(
			'administrator' => self::all(),
			'shop_manager'  => array(
				self::MANAGE,
				self::VIEW_ANALYTICS,
				self::MANAGE_AGENTS,
				self::CONVERSE,
			),
		);
	}

	/**
	 * Grant capabilities to roles. Idempotent.
	 */
	public static function install(): void {
		foreach ( self::role_map() as $role_name => $capabilities ) {
			$role = get_role( $role_name );

			if ( null === $role ) {
				continue;
			}

			foreach ( $capabilities as $capability ) {
				$role->add_cap( $capability );
			}
		}
	}

	/**
	 * Revoke every StoreCrew capability from every role.
	 *
	 * Called on uninstall, not on deactivation — a merchant deactivating
	 * temporarily should not have to rebuild their permissions.
	 */
	public static function uninstall(): void {
		$roles = wp_roles();

		foreach ( array_keys( $roles->roles ) as $role_name ) {
			$role = get_role( $role_name );

			if ( null === $role ) {
				continue;
			}

			foreach ( self::all() as $capability ) {
				$role->remove_cap( $capability );
			}
		}
	}
}
