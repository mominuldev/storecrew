<?php
/**
 * The storefront widget host.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Chat;

defined( 'ABSPATH' ) || exit;

/**
 * Puts the chat script on the storefront, and nothing else.
 *
 * The PHP side is deliberately almost empty. It prints **no markup, no
 * settings, and no conversation state** into the page, for one reason:
 * WooCommerce storefronts are aggressively page-cached, and anything printed
 * into the document is served to the next thousand visitors as well. The script
 * fetches its own configuration from `/chat/boot`, which is never cached.
 *
 * What is printed is a single `<script src>` with `async`, carrying only the
 * REST root — a value that does not vary by visitor. That is what makes
 * FR-CHAT-01 true rather than merely intended: nothing about the widget blocks
 * or delays the page, and nothing about the page personalises for it.
 *
 * FR-CHAT-03 — widget failure must never break the storefront — is why the
 * enqueue is guarded on the built file existing, why the script is a module-free
 * IIFE, and why the boot request failing simply leaves no widget rather than an
 * error.
 *
 * @see docs/01-prd.md FR-CHAT-01, FR-CHAT-03, FR-CHAT-07
 */
final class Widget {

	public const HANDLE = 'storecrew-chat';

	public const SHORTCODE = 'storecrew_chat';

	public const BLOCK = 'storecrew/chat';

	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'init', array( $this, 'register_shortcode' ) );
		add_action( 'init', array( $this, 'register_block' ) );
	}

	/**
	 * Enqueue the widget on the storefront.
	 *
	 * Registered even when auto-placement is off, because the shortcode and the
	 * block both need the script and neither can enqueue it late enough to be
	 * printed if it was never registered.
	 */
	public function enqueue(): void {
		if ( is_admin() || ! $this->asset_exists() ) {
			return;
		}

		$settings = ChatSettings::all();

		if ( ! $settings['enabled'] ) {
			return;
		}

		/**
		 * Filter whether the widget loads on this request.
		 *
		 * The obvious use is suppressing chat on checkout. Nothing here does that
		 * by default — the store's own support agent is arguably most useful at
		 * the moment a customer hesitates — but the decision belongs to the
		 * merchant, not to this plugin.
		 *
		 * @param bool $load Whether to enqueue.
		 */
		if ( ! (bool) apply_filters( 'storecrew_chat_should_load', true ) ) {
			return;
		}

		$file = STORECREW_DIR . 'assets/widget/widget.js';

		wp_enqueue_script(
			self::HANDLE,
			STORECREW_URL . 'assets/widget/widget.js',
			array(),
			(string) filemtime( $file ),
			array(
				'in_footer' => true,
				// `async`, not `defer`: the widget has no ordering relationship
				// with any other script on the page, and nothing on the page
				// waits for it. FR-CHAT-01 asks for exactly this.
				'strategy'  => 'async',
			)
		);

		wp_add_inline_script(
			self::HANDLE,
			'window.storecrewChat=' . wp_json_encode(
				array(
					'root' => esc_url_raw( rest_url( 'storecrew/v1' ) ),
					// Auto-placement is decided here rather than in the script so
					// a merchant who only wants the block does not get a floating
					// launcher as well.
					'auto' => (bool) $settings['autoPlace'],
				),
				JSON_UNESCAPED_SLASHES
			) . ';',
			'before'
		);
	}

	/**
	 * `[storecrew_chat]` — an inline chat panel wherever the merchant puts it.
	 */
	public function register_shortcode(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
	}

	/**
	 * The same panel as a block.
	 *
	 * Registered from PHP with a hand-written editor script rather than a build
	 * step. The script uses the `wp.blocks` globals the editor already loads; it
	 * does not add a single `@wordpress/*` package to this plugin's dependency
	 * tree, which is the constraint FR-ADMIN-01 exists to protect.
	 */
	public function register_block(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		$args = array(
			'api_version'     => 3,
			'title'           => __( 'StoreCrew chat', 'storecrew' ),
			'category'        => 'widgets',
			'icon'            => 'format-chat',
			'description'     => __( 'An inline chat panel answered by your StoreCrew agents.', 'storecrew' ),
			'render_callback' => array( $this, 'render' ),
			'supports'        => array( 'html' => false ),
		);

		// The editor script is an admin concern. Registering it on the storefront
		// would put two filesystem stats on every product page for a file no
		// visitor will ever request.
		$editor = is_admin() ? STORECREW_DIR . 'assets/blocks/chat-block.js' : '';

		if ( '' !== $editor && file_exists( $editor ) ) {
			wp_register_script(
				self::HANDLE . '-block',
				STORECREW_URL . 'assets/blocks/chat-block.js',
				array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-i18n' ),
				(string) filemtime( $editor ),
				true
			);

			$args['editor_script'] = self::HANDLE . '-block';
		}

		register_block_type( self::BLOCK, $args );
	}

	/**
	 * The mount point for an inline panel.
	 *
	 * Prints the container only. The script finds it and takes over — so a page
	 * saved with this block still renders cleanly on a store where chat was
	 * later switched off, rather than leaving a dead frame behind.
	 */
	public function render(): string {
		if ( ! ChatSettings::all()['enabled'] || ! $this->asset_exists() ) {
			return '';
		}

		// Enqueued here as well: a block or shortcode inside post content is
		// rendered after `wp_enqueue_scripts` has already run on some themes.
		if ( ! wp_script_is( self::HANDLE, 'enqueued' ) ) {
			$this->enqueue();
		}

		return '<div class="scr-chat-inline" data-storecrew-chat="inline"></div>';
	}

	private function asset_exists(): bool {
		return file_exists( STORECREW_DIR . 'assets/widget/widget.js' );
	}
}
