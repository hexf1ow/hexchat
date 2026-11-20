<?php
/**
 * Google Gemini provider.
 *
 * @package HexChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class HexChat_Provider_Gemini
 */
class HexChat_Provider_Gemini extends HexChat_Provider {

	/**
	 * Complete a chat turn.
	 *
	 * @param string $system  System prompt.
	 * @param array  $history Prior turns.
	 * @param string $message Latest message.
	 * @return string|WP_Error
	 */
	public function complete( $system, array $history, $message ) {
		$model = $this->model ? $this->model : 'gemini-2.0-flash';

		$contents = array();

		foreach ( $history as $turn ) {
			if ( empty( $turn['content'] ) ) {
				continue;
			}
			$contents[] = array(
				'role'  => ( 'assistant' === $turn['role'] ) ? 'model' : 'user',
				'parts' => array( array( 'text' => (string) $turn['content'] ) ),
			);
		}

		$contents[] = array(
			'role'  => 'user',
			'parts' => array( array( 'text' => $message ) ),
		);

		$url = sprintf(
			'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
			rawurlencode( $model ),
			rawurlencode( $this->api_key )
		);

		$data = $this->post(
			$url,
			array( 'Content-Type' => 'application/json' ),
			array(
				'systemInstruction' => array(
					'parts' => array( array( 'text' => $system ) ),
				),
				'contents'          => $contents,
				'generationConfig'  => array(
					'temperature'     => (float) hexchat_opt( 'temperature' ),
					'maxOutputTokens' => (int) hexchat_opt( 'max_tokens' ),
				),
			)
		);

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$text = '';
		if ( isset( $data['candidates'][0]['content']['parts'] ) && is_array( $data['candidates'][0]['content']['parts'] ) ) {
			foreach ( $data['candidates'][0]['content']['parts'] as $part ) {
				if ( isset( $part['text'] ) ) {
					$text .= $part['text'];
				}
			}
		}

		$text = trim( $text );
		if ( '' === $text ) {
			return new WP_Error( 'hexchat_empty', __( 'The AI returned an empty answer.', 'hexchat' ), array( 'status' => 502 ) );
		}

		return $text;
	}
}
