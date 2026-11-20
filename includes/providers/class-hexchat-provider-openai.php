<?php
/**
 * OpenAI-compatible provider.
 *
 * Works with OpenAI, OpenRouter, Groq, Together, DeepInfra, LM Studio, Ollama
 * (/v1), vLLM and anything else speaking /chat/completions. That is the reason
 * it is the default: one code path covers almost every "bring your own key"
 * choice a site owner might make.
 *
 * @package HexChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class HexChat_Provider_OpenAI
 */
class HexChat_Provider_OpenAI extends HexChat_Provider {

	/**
	 * Endpoint for chat completions.
	 *
	 * @return string
	 */
	protected function endpoint() {
		$base = $this->base_url ? $this->base_url : 'https://api.openai.com/v1';

		if ( false !== strpos( $base, '/chat/completions' ) ) {
			return $base;
		}

		return $base . '/chat/completions';
	}

	/**
	 * Complete a chat turn.
	 *
	 * @param string $system  System prompt.
	 * @param array  $history Prior turns.
	 * @param string $message Latest message.
	 * @return string|WP_Error
	 */
	public function complete( $system, array $history, $message ) {
		$messages = array(
			array(
				'role'    => 'system',
				'content' => $system,
			),
		);

		foreach ( $history as $turn ) {
			if ( empty( $turn['content'] ) ) {
				continue;
			}
			$messages[] = array(
				'role'    => ( 'assistant' === $turn['role'] ) ? 'assistant' : 'user',
				'content' => (string) $turn['content'],
			);
		}

		$messages[] = array(
			'role'    => 'user',
			'content' => $message,
		);

		$body = array(
			'model'       => $this->model,
			'messages'    => $messages,
			'temperature' => (float) hexchat_opt( 'temperature' ),
			'max_tokens'  => hexchat_num( 'max_tokens', 60 ),
		);

		$data = $this->post(
			$this->endpoint(),
			array(
				'Authorization' => 'Bearer ' . $this->api_key,
				'Content-Type'  => 'application/json',
			),
			$body
		);

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$text = isset( $data['choices'][0]['message']['content'] ) ? $data['choices'][0]['message']['content'] : '';

		if ( is_array( $text ) ) {
			// Some gateways return content parts instead of a string.
			$flat = '';
			foreach ( $text as $part ) {
				if ( isset( $part['text'] ) ) {
					$flat .= $part['text'];
				}
			}
			$text = $flat;
		}

		$text = trim( (string) $text );

		if ( '' === $text ) {
			return new WP_Error( 'hexchat_empty', __( 'The AI returned an empty answer.', 'hexchat' ), array( 'status' => 502 ) );
		}

		return $text;
	}
}
