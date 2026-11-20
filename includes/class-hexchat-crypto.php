<?php
/**
 * API key encryption at rest.
 *
 * The key is encrypted with libsodium before it touches the database, using a
 * secret derived from the site's AUTH_KEY. That means a database dump on its
 * own does not expose the key, and a key pasted by a site owner is never
 * returned to the browser in any form.
 *
 * @package HexChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class HexChat_Crypto
 */
class HexChat_Crypto {

	/**
	 * Option name holding the ciphertext.
	 */
	const OPTION = 'hexchat_api_key';

	/**
	 * Derive a 32 byte key from the site secret.
	 *
	 * @return string
	 */
	private static function secret() {
		$material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : 'hexchat-fallback' ) . ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '' ) . ( defined( 'NONCE_SALT' ) ? NONCE_SALT : '' );
		return hash( 'sha256', 'hexchat|' . $material, true );
	}

	/**
	 * Encrypt a plaintext key.
	 *
	 * @param string $plain Plaintext API key.
	 * @return string|false Base64 nonce+ciphertext, or false on failure.
	 */
	public static function encrypt( $plain ) {
		$plain = trim( (string) $plain );
		if ( '' === $plain ) {
			return false;
		}

		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			// Fallback for hosts without libsodium: reversible obfuscation only.
			return 'b64:' . base64_encode( $plain ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plain, $nonce, self::secret() );

		return 'sodium:' . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt a stored key.
	 *
	 * @return string Empty string when nothing is stored or decryption fails.
	 */
	public static function get_api_key() {
		$stored = (string) get_option( self::OPTION, '' );
		if ( '' === $stored ) {
			return '';
		}

		if ( 0 === strpos( $stored, 'b64:' ) ) {
			return (string) base64_decode( substr( $stored, 4 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		}

		if ( 0 === strpos( $stored, 'sodium:' ) && function_exists( 'sodium_crypto_secretbox_open' ) ) {
			$raw = base64_decode( substr( $stored, 7 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				return '';
			}
			$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $nonce, self::secret() );

			return false === $plain ? '' : (string) $plain;
		}

		return '';
	}

	/**
	 * Save a new key, or clear it when empty.
	 *
	 * @param string $plain Plaintext key.
	 * @return void
	 */
	public static function set_api_key( $plain ) {
		$plain = trim( (string) $plain );

		if ( '' === $plain ) {
			delete_option( self::OPTION );
			return;
		}

		$encrypted = self::encrypt( $plain );
		if ( $encrypted ) {
			update_option( self::OPTION, $encrypted, false );
		}
	}

	/**
	 * Whether a key is stored, without decrypting it for display.
	 *
	 * @return bool
	 */
	public static function has_api_key() {
		return '' !== (string) get_option( self::OPTION, '' );
	}

	/**
	 * Last four characters, for a "key saved" hint in the admin.
	 *
	 * @return string
	 */
	public static function key_hint() {
		$key = self::get_api_key();
		if ( strlen( $key ) < 8 ) {
			return '';
		}
		return '••••' . substr( $key, -4 );
	}
}
