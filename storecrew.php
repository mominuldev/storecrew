<?php
/**
 * Plugin Name:       StoreCrew AI
 * Plugin URI:        https://decentthemes.com/storecrew
 * Description:       The AI employee platform for WooCommerce. A crew of AI agents that sell, support, and act on your store.
 * Version:           0.1.0
 * Requires at least: 6.6
 * Requires PHP:      8.3
 * Requires Plugins:  woocommerce
 * Author:            Decent Themes
 * Author URI:        https://decentthemes.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       storecrew
 * Domain Path:       /languages
 *
 * @package StoreCrew
 */

/**
 * IMPORTANT — This file must stay parseable by PHP 5.6.
 *
 * It is the only file WordPress loads before the version guard runs. If it used
 * PHP 8 syntax, a site on PHP 7.4 would fatal at parse time and the merchant
 * would see a white screen instead of the notice telling them why. Everything
 * under src/ may use the full PHP 8.3 feature set; this file may not.
 *
 * No typed properties, no union types, no constructor promotion, no match,
 * no named arguments, no enums, no first-class callables, no arrow functions.
 */

defined( 'ABSPATH' ) || exit;

define( 'STORECREW_VERSION', '0.1.0' );

/**
 * Extension API contract version.
 *
 * Versioned independently of STORECREW_VERSION. Bumps only when the published
 * hook surface changes. Add-ons (including StoreCrew AI Pro) handshake against
 * this, not against the product version.
 *
 * @see docs/15-free-premium-split.md
 */
define( 'STORECREW_API_VERSION', '1.0' );

define( 'STORECREW_FILE', __FILE__ );
define( 'STORECREW_DIR', plugin_dir_path( __FILE__ ) );
define( 'STORECREW_URL', plugin_dir_url( __FILE__ ) );
define( 'STORECREW_BASENAME', plugin_basename( __FILE__ ) );

define( 'STORECREW_MIN_PHP', '8.3' );
define( 'STORECREW_MIN_WP', '6.6' );
define( 'STORECREW_MIN_WC', '9.0' );

/**
 * Declare WooCommerce feature compatibility.
 *
 * Must run on before_woocommerce_init — earlier than our own boot, and before
 * WooCommerce decides whether to show the "incompatible plugin" warning.
 *
 * FR-CORE-02 (HPOS), FR-CORE-03 (Cart & Checkout Blocks).
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( ! class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			return;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			STORECREW_FILE,
			true
		);
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'cart_checkout_blocks',
			STORECREW_FILE,
			true
		);
	}
);

/**
 * Boot the plugin.
 *
 * Priority 5: the kernel is built and modules registered before the extension
 * API opens at priority 10. Add-ons register on storecrew_api_ready; registries
 * freeze at priority 20.
 *
 * @see docs/15-free-premium-split.md § 3.1
 */
add_action(
	'plugins_loaded',
	function () {
		require_once STORECREW_DIR . 'src/Core/Requirements.php';

		$requirements = new StoreCrew\Core\Requirements(
			array(
				'php' => STORECREW_MIN_PHP,
				'wp'  => STORECREW_MIN_WP,
				'wc'  => STORECREW_MIN_WC,
			)
		);

		if ( ! $requirements->satisfied() ) {
			$requirements->render_admin_notice();

			return;
		}

		$autoload = STORECREW_DIR . 'vendor/autoload.php';

		if ( ! file_exists( $autoload ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>';
					echo esc_html__(
						'StoreCrew AI is missing its dependencies. Run "composer install" in the plugin directory.',
						'storecrew'
					);
					echo '</p></div>';
				}
			);

			return;
		}

		require_once $autoload;

		StoreCrew\Plugin::instance()->boot();
	},
	5
);

register_activation_hook(
	__FILE__,
	function () {
		require_once STORECREW_DIR . 'src/Core/Requirements.php';

		$requirements = new StoreCrew\Core\Requirements(
			array(
				'php' => STORECREW_MIN_PHP,
				'wp'  => STORECREW_MIN_WP,
				'wc'  => STORECREW_MIN_WC,
			)
		);

		if ( ! $requirements->satisfied() ) {
			// Refuse activation rather than activating into a broken state.
			wp_die(
				esc_html( $requirements->failure_summary() ),
				esc_html__( 'StoreCrew AI cannot be activated', 'storecrew' ),
				array( 'back_link' => true )
			);
		}

		require_once STORECREW_DIR . 'vendor/autoload.php';

		StoreCrew\Core\Activation\Activator::activate();
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		$autoload = STORECREW_DIR . 'vendor/autoload.php';

		if ( file_exists( $autoload ) ) {
			require_once $autoload;
			StoreCrew\Core\Activation\Deactivator::deactivate();
		}
	}
);
