<?php
/**
 * WordPress AI Client provider.
 *
 * WordPress 7.0 ships a core AI Client, so a site owner configures one provider
 * and every plugin uses it. This provider routes HexChat through that client when
 * it is available; the direct providers stay for older sites and for anyone who
 * wants their own key and endpoint.
 *
 * @package HexChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class HexChat_Provider_WP
 */
class HexChat_Provider_WP extends HexChat_Provider {

	/**
	 * Whether the core AI Client exists on this site.
	 *
	 * @return bool
	 */
	public static function available() {
		return function_exists( 'wp_ai_client_prompt' );
	}

	/**
	 * Complete a chat turn through the core AI Client.
	 *
	 * @param string $system  System prompt.
	 * @param array  $history Prior turns.
	 * @param string $message Latest message.
	 * @return string|WP_Error
	 */
	public function complete( $system, array $history, $message ) {
		if ( ! self::available() ) {
			return new WP_Error(
				'hexchat_no_ai_client',
				__( 'The WordPress AI Client is not available here. It requires WordPress 7.0 or later — choose a provider and paste an API key instead.', 'hexchat' )
			);
		}

		$prompt = (string) $system . "\n\n";

		foreach ( $history as $turn ) {
			if ( empty( $turn['content'] ) ) {
				continue;
			}
			$who     = ( 'assistant' === $turn['role'] ) ? 'Assistant' : 'Visitor';
			$prompt .= $who . ': ' . (string) $turn['content'] . "\n";
		}

		$prompt .= 'Visitor: ' . (string) $message;

		$builder = wp_ai_client_prompt( $prompt );
		if ( is_wp_error( $builder ) ) {
			return $builder;
		}

		if ( is_object( $builder ) && method_exists( $builder, 'using_temperature' ) ) {
			$builder = $builder->using_temperature( (float) hexchat_opt( 'temperature' ) );
		}

		$text = $builder->generate_text();

		if ( is_wp_error( $text ) ) {
			return $text;
		}

		$text = trim( (string) $text );

		if ( '' === $text ) {
			return new WP_Error(
				'hexchat_empty_reply',
				__( 'The WordPress AI Client returned an empty answer.', 'hexchat' )
			);
		}

		return $text;
	}
}
