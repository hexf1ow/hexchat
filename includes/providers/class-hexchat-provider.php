<?php
/**
 * Provider contract. One method, so every provider is swappable and testable.
 *
 * @package HexChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class HexChat_Provider
 */
abstract class HexChat_Provider {

	/**
	 * Plaintext API key.
	 *
	 * @var string
	 */
	protected $api_key;

	/**
	 * Model name.
	 *
	 * @var string
	 */
	protected $model;

	/**
	 * Base URL for OpenAI-compatible providers.
	 *
	 * @var string
	 */
	protected $base_url;

	/**
	 * Constructor.
	 *
	 * @param string $api_key  Key.
	 * @param string $model    Model.
	 * @param string $base_url Base endpoint.
	 */
	public function __construct( $api_key, $model, $base_url = '' ) {
		$this->api_key  = (string) $api_key;
		$this->model    = (string) $model;
		$this->base_url = rtrim( (string) $base_url, '/' );
	}

	/**
	 * Send a chat completion.
	 *
	 * @param string $system  System prompt.
	 * @param array  $history Prior turns: array( array( 'role' => 'user|assistant', 'content' => '...' ) ).
	 * @param string $message Latest visitor message.
	 * @return string|WP_Error
	 */
	abstract public function complete( $system, array $history, $message );

	/**
	 * Shared HTTP POST with JSON.
	 *
	 * @param string $url     Endpoint.
	 * @param array  $headers Headers.
	 * @param array  $body    Body.
	 * @return array|WP_Error Decoded body.
	 */
	protected function post( $url, array $headers, array $body ) {
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 45,
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			$detail = '';

			if ( is_array( $data ) ) {
				if ( isset( $data['error']['message'] ) ) {
					$detail = (string) $data['error']['message'];
				} elseif ( isset( $data['error'] ) && is_string( $data['error'] ) ) {
					$detail = $data['error'];
				} elseif ( isset( $data['message'] ) ) {
					$detail = (string) $data['message'];
				}
			}

			/* translators: 1: HTTP status, 2: provider message */
			$message = sprintf( __( 'Model request failed (%1$d). %2$s', 'hexchat' ), $code, $detail );

			return new WP_Error( 'hexchat_http_' . $code, $message, array( 'status' => 502 ) );
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'hexchat_bad_json', __( 'The AI provider returned an unreadable response.', 'hexchat' ), array( 'status' => 502 ) );
		}

		return $data;
	}
}
