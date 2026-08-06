<?php
/**
 * A route contributed to the admin SPA.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Describes one screen in the admin single-page application.
 *
 * The SPA itself lives entirely in the free plugin (FR-DIST-12). Add-ons do not
 * ship a second React application; they declare routes here and register the
 * matching components into the shell's client-side registry from their own
 * enqueued bundle.
 *
 * A route with a `feature` slug renders only when that feature is entitled.
 * That gating is presentation only — the REST controller behind the screen
 * re-checks entitlement independently.
 *
 * @see docs/15-free-premium-split.md § 5
 */
final readonly class AdminRoute {

	/**
	 * @param string      $path      SPA path, e.g. "/agents/marketing". Must start with "/".
	 * @param string      $label     Menu label.
	 * @param string|null $feature   Feature slug gating this route, or null when always available.
	 * @param string      $capability WordPress capability required to see it.
	 * @param string      $icon      Icon identifier understood by the SPA shell.
	 * @param int         $order     Sort order within the menu; lower is higher.
	 * @param bool        $in_menu   Whether it appears in the sidebar, or is reachable by link only.
	 */
	public function __construct(
		public string $path,
		public string $label,
		public ?string $feature = null,
		public string $capability = 'storecrew_manage',
		public string $icon = 'circle',
		public int $order = 50,
		public bool $in_menu = true,
	) {
		if ( ! str_starts_with( $path, '/' ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'Admin route path must start with "/", got "%s".', $path )
			);
		}

		if ( '' === trim( $label ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'Admin route "%s" needs a label.', $path )
			);
		}
	}

	/**
	 * Serialisable shape for the SPA bootstrap payload.
	 *
	 * @return array{
	 *     path: string,
	 *     label: string,
	 *     feature: string|null,
	 *     icon: string,
	 *     order: int,
	 *     inMenu: bool
	 * }
	 */
	public function to_array(): array {
		return array(
			'path'    => $this->path,
			'label'   => $this->label,
			'feature' => $this->feature,
			'icon'    => $this->icon,
			'order'   => $this->order,
			'inMenu'  => $this->in_menu,
		);
	}
}
