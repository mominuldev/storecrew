<?php
/**
 * Merchant-facing widget configuration.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Chat;

defined( 'ABSPATH' ) || exit;

/**
 * What the widget looks like and where it appears.
 *
 * One option row rather than a row per field. These values are read together on
 * every storefront request that boots the widget, and eleven `get_option` calls
 * where one would do is eleven chances to miss the autoload cache.
 *
 * Defaults are chosen so a merchant who configures nothing still gets a working,
 * on-brand-enough widget — the only thing that must be set before the widget
 * appears is a provider key, and that is checked at boot rather than here.
 */
final class ChatSettings {

	public const OPTION = 'storecrew_chat';

	public const POSITION_RIGHT = 'right';
	public const POSITION_LEFT  = 'left';

	/**
	 * Stored settings merged over the defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$settings = array_merge( self::defaults(), $stored );

		/**
		 * Filter the storefront chat settings.
		 *
		 * @param array<string, mixed> $settings Merged settings.
		 */
		return (array) apply_filters( 'storecrew_chat_settings', $settings );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'enabled'      => false,
			'autoPlace'    => true,
			'position'     => self::POSITION_RIGHT,
			'accent'       => '#111827',
			'title'        => __( 'Ask the store', 'storecrew' ),
			'launcher'     => __( 'Chat', 'storecrew' ),
			'greeting'     => __( 'Hello — ask me anything about our products, your order, or our policies.', 'storecrew' ),
			'placeholder'  => __( 'Type your message', 'storecrew' ),
			'offlineNotice' => __( 'Chat is unavailable right now. Please get in touch by email.', 'storecrew' ),
		);
	}

	/**
	 * Persist a submitted subset.
	 *
	 * Every field is sanitised against its own type rather than run through one
	 * generic pass: `accent` lands in a CSS custom property and `position` in a
	 * class name, so an unchecked string in either is a stylesheet injection on
	 * every page of the storefront.
	 *
	 * @param array<string, mixed> $submitted Raw input.
	 *
	 * @return array<string, mixed> The settings as stored.
	 */
	public static function save( array $submitted ): array {
		$clean = self::all();

		if ( array_key_exists( 'enabled', $submitted ) ) {
			$clean['enabled'] = (bool) $submitted['enabled'];
		}

		if ( array_key_exists( 'autoPlace', $submitted ) ) {
			$clean['autoPlace'] = (bool) $submitted['autoPlace'];
		}

		if ( isset( $submitted['position'] ) ) {
			$position          = (string) $submitted['position'];
			$clean['position'] = self::POSITION_LEFT === $position ? self::POSITION_LEFT : self::POSITION_RIGHT;
		}

		if ( isset( $submitted['accent'] ) ) {
			$accent = sanitize_hex_color( (string) $submitted['accent'] );

			// An unparseable colour keeps the previous one. Falling back to a
			// default would silently discard a merchant's brand colour because
			// they typed a stray character.
			if ( null !== $accent && '' !== $accent ) {
				$clean['accent'] = $accent;
			}
		}

		foreach ( array( 'title', 'launcher', 'placeholder' ) as $field ) {
			if ( isset( $submitted[ $field ] ) ) {
				$clean[ $field ] = self::trim_to( sanitize_text_field( (string) $submitted[ $field ] ), 80 );
			}
		}

		foreach ( array( 'greeting', 'offlineNotice' ) as $field ) {
			if ( isset( $submitted[ $field ] ) ) {
				$clean[ $field ] = self::trim_to( sanitize_textarea_field( (string) $submitted[ $field ] ), 500 );
			}
		}

		update_option( self::OPTION, $clean, true );

		return $clean;
	}

	private static function trim_to( string $value, int $length ): string {
		$value = trim( $value );

		return mb_substr( $value, 0, $length );
	}
}
