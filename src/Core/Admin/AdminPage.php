<?php
/**
 * The admin application host page.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Core\Admin;

use StoreCrew\Core\Capabilities\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the admin menu and mounts the React application.
 *
 * The page renders a single empty div. Everything else is the SPA, which is a
 * standalone React 19 build with **no `@wordpress/*` packages of any kind** —
 * not the component library, not the data layer, not the element wrapper. That
 * is a product decision as much as a technical one: the admin should feel like
 * a SaaS product that happens to live inside WordPress, and inheriting
 * WordPress's component styling guarantees the opposite.
 *
 * React is bundled rather than borrowed from WordPress's registered copy.
 * Core ships whichever version its current release pins, so a plugin that
 * depends on it inherits every future core upgrade as an untested breaking
 * change.
 *
 * @see docs/01-prd.md FR-ADMIN-01
 */
final class AdminPage {

	public const SLUG = 'storecrew';

	private const HANDLE = 'storecrew-admin';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function add_menu(): void {
		$hook = add_menu_page(
			__( 'StoreCrew AI', 'storecrew' ),
			__( 'StoreCrew', 'storecrew' ),
			Capabilities::MANAGE,
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-groups',
			56
		);

		if ( ! $hook ) {
			return;
		}

		// The SPA owns the whole viewport, so WordPress's own admin notices —
		// which other plugins inject freely — would otherwise land inside our
		// layout and break it.
		add_action(
			'load-' . $hook,
			static function (): void {
				remove_all_actions( 'admin_notices' );
				remove_all_actions( 'all_admin_notices' );
			}
		);
	}

	public function render(): void {
		// A single mount point. Everything else is the application.
		echo '<div id="storecrew-root"></div>';
	}

	public function enqueue( string $hook ): void {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}

		$js  = STORECREW_DIR . 'assets/admin/app.js';
		$css = STORECREW_DIR . 'assets/admin/app.css';

		if ( ! file_exists( $js ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-error"><p>';
					echo esc_html__(
						'The StoreCrew admin application has not been built. Run "npm install && npm run build" in the plugin directory.',
						'storecrew'
					);
					echo '</p></div>';
				}
			);

			return;
		}

		wp_enqueue_script(
			self::HANDLE,
			STORECREW_URL . 'assets/admin/app.js',
			array(),
			(string) filemtime( $js ),
			true
		);

		if ( file_exists( $css ) ) {
			wp_enqueue_style(
				self::HANDLE,
				STORECREW_URL . 'assets/admin/app.css',
				array(),
				(string) filemtime( $css )
			);

			wp_add_inline_style( self::HANDLE, self::inline_reset() );
		}

		wp_localize_script(
			self::HANDLE,
			'storecrewBoot',
			array(
				'root'     => esc_url_raw( rest_url() ),
				// Cookie authentication alone is not enough for a REST write.
				// Without this nonce a third-party page could drive this API
				// using the merchant's own logged-in session.
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'version'  => STORECREW_VERSION,
				'adminUrl' => esc_url_raw( admin_url() ),
			)
		);
	}

	/**
	 * Strip the admin chrome that fights a full-viewport application.
	 *
	 * Kept minimal and scoped to this page only. A plugin that restyles the
	 * whole admin is a plugin merchants uninstall.
	 */
	public static function inline_reset(): string {
		return '
			#wpcontent, #wpbody-content { padding: 0 !important; }
			#wpbody-content { padding-bottom: 0 !important; }
			#wpfooter { display: none; }
			.wrap { margin: 0; }
			#storecrew-root { margin-left: -20px; }
			@media screen and (max-width: 782px) { #storecrew-root { margin-left: -10px; } }
		';
	}
}
