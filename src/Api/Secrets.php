<?php
/**
 * Credential storage, as much of it as an add-on should see.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Store and retrieve a secret without knowing how it is protected.
 *
 * Any add-on that talks to a third-party service needs somewhere safe to keep a
 * credential, and there is exactly one thing it must not do about that: invent
 * its own. `Security\SecretStore` already does envelope encryption properly —
 * this interface is how an add-on reaches it without the platform promising to
 * keep key management itself stable.
 *
 * **Deliberately four methods.** `SecretStore` also exposes `rewrap()`,
 * `rotate_data_key()`, and `master_key_source()`, which are operator concerns
 * belonging to the platform's own settings screen. Publishing the whole class
 * would commit the free plugin to those signatures forever so that an add-on
 * could store one API key — and `@api` is a promise about compatibility, not a
 * convenience. What an add-on needs is put, get, has, forget.
 *
 * Resolve it from the published container:
 *
 * ```php
 * $secrets = $api->container()->get( \StoreCrew\Api\Secrets::class );
 * $secrets->put( 'storecrew_pro.mailchimp', $key );
 * ```
 *
 * **Namespace your names.** Every add-on shares one store, so a name that is
 * merely descriptive will eventually collide with somebody else's. Prefix with
 * your plugin slug.
 *
 * @see docs/15-free-premium-split.md § 4
 *
 * @api
 */
interface Secrets {

	/**
	 * Store a secret under a name, replacing any existing value.
	 */
	public function put( string $name, string $plaintext ): bool;

	/**
	 * Retrieve a secret, or null if absent or undecryptable.
	 *
	 * Null rather than an exception on decryption failure: a rotated-away or
	 * corrupted secret should surface as "not configured", not as a fatal on
	 * every request that touches it.
	 */
	public function get( string $name ): ?string;

	/**
	 * Whether a usable secret is stored under this name.
	 */
	public function has( string $name ): bool;

	/**
	 * Remove a secret. Removing one that was never there is not an error.
	 */
	public function forget( string $name ): bool;
}
