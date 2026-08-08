<?php
/**
 * Encrypted secret storage.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Security;

use StoreCrew\Api\Secrets;

defined( 'ABSPATH' ) || exit;

/**
 * Stores provider API keys encrypted at rest, with a rotatable key.
 *
 * Envelope encryption, for one specific reason: FR-AI-03 requires the
 * encryption key to be rotatable *without destroying what it protects*. Secrets
 * are encrypted with a data key; the data key is itself encrypted by a master
 * key. Rotating the master therefore re-wraps one small blob and leaves every
 * secret untouched — the naive design, where the master encrypts secrets
 * directly, turns rotation into "re-encrypt everything or lose it all", which
 * is how key rotation ends up never being done.
 *
 * The master key is resolved in descending order of safety, and the weakest
 * source is reported rather than hidden — a merchant on the fallback deserves
 * to know their keys are only as safe as their database.
 *
 * The four methods add-ons need are published separately as {@see Secrets};
 * the rotation and master-key surface below is the platform's own and is
 * deliberately not part of that contract.
 *
 * @see docs/01-prd.md FR-AI-03
 */
final class SecretStore implements Secrets {

	public const OPTION_DATA_KEY = 'storecrew_data_key';
	public const OPTION_SECRETS  = 'storecrew_secrets';
	public const OPTION_FALLBACK = 'storecrew_master_fallback';

	/**
	 * Ciphertext format version. Bumped if the envelope layout changes.
	 */
	private const FORMAT = 'scr1';

	public const SOURCE_CONSTANT = 'constant';
	public const SOURCE_SALTS    = 'salts';
	public const SOURCE_OPTION   = 'option';

	/**
	 * Memoised data key for the request.
	 */
	private ?string $data_key = null;

	/**
	 * Store a secret under a name.
	 */
	public function put( string $name, string $plaintext ): bool {
		$secrets = $this->all_ciphertexts();

		$secrets[ $name ] = $this->encrypt( $plaintext, $this->data_key() );

		return update_option( self::OPTION_SECRETS, $secrets, false );
	}

	/**
	 * Retrieve a secret, or null if absent or undecryptable.
	 *
	 * Returns null rather than throwing on a decryption failure: a rotated-away
	 * or corrupted secret should surface as "not configured" in the UI, not as
	 * a fatal on every request that touches it.
	 */
	public function get( string $name ): ?string {
		$secrets = $this->all_ciphertexts();

		if ( ! isset( $secrets[ $name ] ) ) {
			return null;
		}

		return $this->decrypt( (string) $secrets[ $name ], $this->data_key() );
	}

	public function has( string $name ): bool {
		return null !== $this->get( $name );
	}

	public function forget( string $name ): bool {
		$secrets = $this->all_ciphertexts();

		unset( $secrets[ $name ] );

		return update_option( self::OPTION_SECRETS, $secrets, false );
	}

	/**
	 * Names of stored secrets. Never the values.
	 *
	 * @return list<string>
	 */
	public function names(): array {
		return array_keys( $this->all_ciphertexts() );
	}

	/**
	 * A masked hint for display, e.g. "sk-…4f2a".
	 *
	 * The admin UI needs to show *that* a key is configured and let a merchant
	 * recognise which one, without ever shipping the key back to the browser.
	 */
	public function hint( string $name ): ?string {
		$secret = $this->get( $name );

		if ( null === $secret ) {
			return null;
		}

		$length = strlen( $secret );

		if ( $length <= 8 ) {
			return str_repeat( '•', $length );
		}

		return substr( $secret, 0, 3 ) . '…' . substr( $secret, -4 );
	}

	/**
	 * Re-wrap the data key under the current master key.
	 *
	 * This is what makes master rotation cheap and safe: it touches one blob,
	 * and every stored secret keeps working. Call it after changing
	 * STORECREW_ENCRYPTION_KEY or rotating the site's salts.
	 *
	 * @param string $previous_master The master key the data key is wrapped under now.
	 */
	public function rewrap( string $previous_master ): bool {
		$wrapped = get_option( self::OPTION_DATA_KEY );

		if ( ! is_string( $wrapped ) || '' === $wrapped ) {
			return false;
		}

		$data_key = $this->decrypt( $wrapped, $this->derive( $previous_master ) );

		if ( null === $data_key ) {
			return false;
		}

		$this->data_key = $data_key;

		return update_option(
			self::OPTION_DATA_KEY,
			$this->encrypt( $data_key, $this->master_key() ),
			false
		);
	}

	/**
	 * Generate a new data key and re-encrypt every secret under it.
	 *
	 * Unlike rewrap(), this does touch every secret — it is the response to a
	 * suspected data-key compromise, not routine rotation. Secrets are decrypted
	 * under the old key first and only written once all of them succeed, so a
	 * failure partway through leaves the store as it was.
	 *
	 * @return array{rotated: int, failed: list<string>}
	 */
	public function rotate_data_key(): array {
		$old_key = $this->data_key();
		$secrets = $this->all_ciphertexts();

		$plaintexts = array();
		$failed     = array();

		foreach ( $secrets as $name => $ciphertext ) {
			$plain = $this->decrypt( (string) $ciphertext, $old_key );

			if ( null === $plain ) {
				$failed[] = (string) $name;

				continue;
			}

			$plaintexts[ $name ] = $plain;
		}

		// Refuse a partial rotation. Rotating what we can and dropping the rest
		// would silently destroy secrets, which is the exact outcome FR-AI-03
		// exists to prevent.
		if ( array() !== $failed ) {
			return array(
				'rotated' => 0,
				'failed'  => $failed,
			);
		}

		$new_key = $this->random_key();

		$reencrypted = array();

		foreach ( $plaintexts as $name => $plain ) {
			$reencrypted[ $name ] = $this->encrypt( $plain, $new_key );
		}

		update_option( self::OPTION_DATA_KEY, $this->encrypt( $new_key, $this->master_key() ), false );
		update_option( self::OPTION_SECRETS, $reencrypted, false );

		$this->data_key = $new_key;

		return array(
			'rotated' => count( $reencrypted ),
			'failed'  => array(),
		);
	}

	/**
	 * Where the master key comes from, for the health report.
	 *
	 * @return array{source: string, secure: bool, advice: string}
	 */
	public function master_key_source(): array {
		if ( defined( 'STORECREW_ENCRYPTION_KEY' ) && '' !== (string) constant( 'STORECREW_ENCRYPTION_KEY' ) ) {
			return array(
				'source' => self::SOURCE_CONSTANT,
				'secure' => true,
				'advice' => '',
			);
		}

		if ( defined( 'AUTH_KEY' ) && '' !== (string) constant( 'AUTH_KEY' ) ) {
			return array(
				'source' => self::SOURCE_SALTS,
				'secure' => true,
				'advice' => __(
					'Keys are encrypted using this site\'s WordPress salts. Changing the salts requires re-wrapping the data key.',
					'storecrew'
				),
			);
		}

		return array(
			'source' => self::SOURCE_OPTION,
			'secure' => false,
			'advice' => __(
				'No encryption key or WordPress salts were found, so a key is stored in the database alongside the secrets it protects. Define STORECREW_ENCRYPTION_KEY in wp-config.php.',
				'storecrew'
			),
		);
	}

	/**
	 * Stored ciphertexts keyed by name.
	 *
	 * @return array<string, string>
	 */
	private function all_ciphertexts(): array {
		$secrets = get_option( self::OPTION_SECRETS, array() );

		return is_array( $secrets ) ? $secrets : array();
	}

	/**
	 * The data key, unwrapping or creating it as needed.
	 */
	private function data_key(): string {
		if ( null !== $this->data_key ) {
			return $this->data_key;
		}

		$wrapped = get_option( self::OPTION_DATA_KEY );

		if ( is_string( $wrapped ) && '' !== $wrapped ) {
			$key = $this->decrypt( $wrapped, $this->master_key() );

			if ( null !== $key ) {
				return $this->data_key = $key;
			}
		}

		$key = $this->random_key();

		update_option( self::OPTION_DATA_KEY, $this->encrypt( $key, $this->master_key() ), false );

		return $this->data_key = $key;
	}

	/**
	 * The master key, derived from the best available source.
	 */
	private function master_key(): string {
		$source = $this->master_key_source()['source'];

		if ( self::SOURCE_CONSTANT === $source ) {
			return $this->derive( (string) constant( 'STORECREW_ENCRYPTION_KEY' ) );
		}

		if ( self::SOURCE_SALTS === $source ) {
			$material = (string) constant( 'AUTH_KEY' );

			if ( defined( 'SECURE_AUTH_KEY' ) ) {
				$material .= (string) constant( 'SECURE_AUTH_KEY' );
			}

			return $this->derive( $material );
		}

		$stored = get_option( self::OPTION_FALLBACK );

		if ( ! is_string( $stored ) || '' === $stored ) {
			$stored = base64_encode( $this->random_key() );
			update_option( self::OPTION_FALLBACK, $stored, false );
		}

		return $this->derive( $stored );
	}

	/**
	 * Stretch arbitrary key material to a 32-byte key.
	 */
	private function derive( string $material ): string {
		return hash( 'sha256', 'storecrew|v1|' . $material, true );
	}

	private function random_key(): string {
		return random_bytes( 32 );
	}

	/**
	 * Encrypt with AEAD, producing a self-describing envelope.
	 *
	 * Sodium's XChaCha20-Poly1305 where available, AES-256-GCM otherwise. Both
	 * are authenticated: a tampered ciphertext fails to decrypt rather than
	 * yielding attacker-controlled plaintext. The cipher is recorded in the
	 * envelope so a site that gains or loses sodium can still read what it
	 * already wrote.
	 */
	private function encrypt( string $plaintext, string $key ): string {
		if ( function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' ) ) {
			$nonce  = random_bytes( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES );
			$cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt( $plaintext, '', $nonce, $key );

			return implode(
				':',
				array( self::FORMAT, 'xchacha', base64_encode( $nonce ), base64_encode( $cipher ) )
			);
		}

		$nonce = random_bytes( 12 );
		$tag   = '';

		$cipher = openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag );

		if ( false === $cipher ) {
			throw new \RuntimeException( 'StoreCrew: unable to encrypt secret.' );
		}

		return implode(
			':',
			array( self::FORMAT, 'aesgcm', base64_encode( $nonce ), base64_encode( $cipher . $tag ) )
		);
	}

	/**
	 * Decrypt an envelope, or null if it cannot be read.
	 */
	private function decrypt( string $envelope, string $key ): ?string {
		$parts = explode( ':', $envelope );

		if ( 4 !== count( $parts ) || self::FORMAT !== $parts[0] ) {
			return null;
		}

		[ , $cipher_name, $nonce_b64, $payload_b64 ] = $parts;

		$nonce   = base64_decode( $nonce_b64, true );
		$payload = base64_decode( $payload_b64, true );

		if ( false === $nonce || false === $payload ) {
			return null;
		}

		if ( 'xchacha' === $cipher_name ) {
			if ( ! function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_decrypt' ) ) {
				return null;
			}

			try {
				$plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt( $payload, '', $nonce, $key );
			} catch ( \Throwable ) {
				return null;
			}

			return false === $plain ? null : $plain;
		}

		if ( 'aesgcm' === $cipher_name ) {
			if ( strlen( $payload ) <= 16 ) {
				return null;
			}

			$tag    = substr( $payload, -16 );
			$cipher = substr( $payload, 0, -16 );

			$plain = openssl_decrypt( $cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag );

			return false === $plain ? null : $plain;
		}

		return null;
	}
}
