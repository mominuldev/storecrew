<?php
/**
 * Environment requirement guard.
 *
 * @package StoreCrew
 */

namespace StoreCrew\Core;

/**
 * IMPORTANT — This file must stay parseable by PHP 5.6.
 *
 * It is loaded by storecrew.php *before* the PHP version has been verified, so
 * that a site on an unsupported PHP version gets an explanatory notice instead
 * of a parse-time fatal. Do not add scalar type hints, return types, typed
 * properties, constructor promotion, or any other PHP 7+ syntax here.
 *
 * Every other file under src/ targets PHP 8.3 and has no such restriction.
 */

defined( 'ABSPATH' ) || exit;

class Requirements {

	/**
	 * Minimum versions, keyed php|wp|wc.
	 *
	 * @var array
	 */
	private $minimums;

	/**
	 * Unmet requirements, populated by check().
	 *
	 * @var array
	 */
	private $failures = null;

	/**
	 * @param array $minimums Keys: php, wp, wc.
	 */
	public function __construct( $minimums ) {
		$this->minimums = $minimums;
	}

	/**
	 * Whether the environment meets every minimum.
	 *
	 * @return bool
	 */
	public function satisfied() {
		return count( $this->failures() ) === 0;
	}

	/**
	 * Unmet requirements as a list of associative arrays.
	 *
	 * Each entry: label, required, current (null when absent entirely).
	 *
	 * @return array
	 */
	public function failures() {
		if ( null !== $this->failures ) {
			return $this->failures;
		}

		$this->failures = array();

		if ( version_compare( PHP_VERSION, $this->minimums['php'], '<' ) ) {
			$this->failures[] = array(
				'label'    => 'PHP',
				'required' => $this->minimums['php'],
				'current'  => PHP_VERSION,
			);
		}

		$wp_version = get_bloginfo( 'version' );

		if ( version_compare( $wp_version, $this->minimums['wp'], '<' ) ) {
			$this->failures[] = array(
				'label'    => 'WordPress',
				'required' => $this->minimums['wp'],
				'current'  => $wp_version,
			);
		}

		// WC_VERSION is defined when woocommerce.php is included, which happens
		// during plugin loading — before plugins_loaded fires. Absent means
		// WooCommerce is not installed or not active.
		if ( ! defined( 'WC_VERSION' ) ) {
			$this->failures[] = array(
				'label'    => 'WooCommerce',
				'required' => $this->minimums['wc'],
				'current'  => null,
			);
		} elseif ( version_compare( WC_VERSION, $this->minimums['wc'], '<' ) ) {
			$this->failures[] = array(
				'label'    => 'WooCommerce',
				'required' => $this->minimums['wc'],
				'current'  => WC_VERSION,
			);
		}

		return $this->failures;
	}

	/**
	 * Plain-text summary, used for wp_die() during activation.
	 *
	 * @return string
	 */
	public function failure_summary() {
		$lines = array(
			__( 'StoreCrew AI cannot run in this environment:', 'storecrew' ),
		);

		foreach ( $this->failures() as $failure ) {
			if ( null === $failure['current'] ) {
				/* translators: 1: dependency name, 2: required version */
				$lines[] = sprintf(
					__( '%1$s %2$s or higher is required, but it is not active.', 'storecrew' ),
					$failure['label'],
					$failure['required']
				);
			} else {
				/* translators: 1: dependency name, 2: required version, 3: installed version */
				$lines[] = sprintf(
					__( '%1$s %2$s or higher is required. This site is running %3$s.', 'storecrew' ),
					$failure['label'],
					$failure['required'],
					$failure['current']
				);
			}
		}

		return implode( ' ', $lines );
	}

	/**
	 * Register an admin notice describing what is missing.
	 *
	 * @return void
	 */
	public function render_admin_notice() {
		$summary = $this->failure_summary();

		add_action(
			'admin_notices',
			function () use ( $summary ) {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}

				echo '<div class="notice notice-error"><p>';
				echo esc_html( $summary );
				echo '</p></div>';
			}
		);
	}
}
