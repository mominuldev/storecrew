<?php
/**
 * The admin application host page.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Core\Admin;

use StoreCrew\Core\Activation\Activator;
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

		// Priority 20: the migrator is on this hook at the default 10, and the
		// screen we are about to send them to reads tables it creates.
		add_action( 'admin_init', array( $this, 'maybe_redirect_to_setup' ), 20 );
	}

	/**
	 * Where the guided setup flow lives.
	 *
	 * The fragment is the SPA's own router (06 § 2.1). It never reaches the
	 * server, which is exactly why it survives a refresh with no rewrite rule.
	 */
	public static function setup_url(): string {
		return admin_url( 'admin.php?page=' . self::SLUG ) . '#/setup';
	}

	/**
	 * Send a freshly activated install into the setup flow, once.
	 *
	 * The flag is consumed before anything is decided, so a request that cannot
	 * redirect — wrong user, bulk activation, an `exit` that never happens —
	 * still spends it. A redirect that can retry is a redirect that can trap a
	 * merchant in a loop, and a plugin that hijacks navigation twice is worse
	 * than one that misses its moment once.
	 */
	public function maybe_redirect_to_setup(): void {
		if ( ! get_option( Activator::OPTION_SETUP_REDIRECT ) ) {
			return;
		}

		delete_option( Activator::OPTION_SETUP_REDIRECT );

		if ( ! $this->may_redirect() ) {
			return;
		}

		wp_safe_redirect( self::setup_url() );

		exit;
	}

	/**
	 * Whether this request is one a merchant would want redirected.
	 *
	 * Separated from the redirect so the guards can be probed — the redirect
	 * itself ends the request and cannot be.
	 */
	public function may_redirect(): bool {
		// Activating ten plugins at once ends on a screen reporting all ten.
		// Stealing that screen loses the other nine's notices, and WordPress
		// says so by setting this parameter.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['activate-multi'] ) ) {
			return false;
		}

		if ( ! is_admin() || wp_doing_ajax() || is_network_admin() ) {
			return false;
		}

		// Network activation lands every site's administrator here at once, and
		// none of them activated anything.
		if ( is_multisite()
			&& function_exists( 'is_plugin_active_for_network' )
			&& is_plugin_active_for_network( STORECREW_BASENAME ) ) {
			return false;
		}

		// Someone without the capability cannot use the destination, and would
		// arrive at a permission error instead of a welcome.
		return current_user_can( Capabilities::MANAGE );
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

		/**
		 * The admin application is on the page — add-ons enqueue their screen
		 * bundles now.
		 *
		 * Fired only after the shell's own script is enqueued, so a bundle
		 * that lists the handle as a dependency is guaranteed to execute
		 * after `window.storecrew.registerScreen` exists. This is the
		 * client-side half of the AdminRoute contract: the PHP registry
		 * declares that a screen exists; this action is where its
		 * implementation arrives.
		 *
		 * @param string $handle The shell's script handle, for dependency
		 *                       declarations.
		 */
		do_action( 'storecrew_admin_assets', self::HANDLE );

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
				// The setup flow's last step ends by sending the merchant to
				// look at their own storefront. Derived here rather than
				// guessed from the admin URL, which is wrong on every
				// subdirectory install and every site with WP in its own
				// folder.
				'siteUrl'  => esc_url_raw( home_url( '/' ) ),
				// The console greets the merchant by name; first name only,
				// the way a colleague would.
				'userName' => wp_get_current_user()->display_name,
			)
		);
	}

	/**
	 * Strip the admin chrome that fights a full-viewport application.
	 *
	 * Kept minimal and scoped to this page only. A plugin that restyles the
	 * whole admin is a plugin merchants uninstall. The admin menu is left
	 * standing deliberately — a takeover was tried and rejected; the console
	 * lives beside WordPress, not instead of it.
	 */
	public static function inline_reset(): string {
		// No negative margin on the root: the padding rules above already put
		// the app flush against the admin menu, and pulling it further left
		// slides it *under* the menu — invisible behind the menu's background,
		// but bleeding out as a stray light block below where the menu ends.
		return '
			#wpcontent, #wpbody-content { padding: 0 !important; }
			#wpbody-content { padding-bottom: 0 !important; }
			#wpfooter { display: none; }
			.wrap { margin: 0; }
		';
	}
}
