<?php
/**
 * Anthropic provider.
 *
 * @package HexChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class HexChat_Provider_Anthropic
 */
class HexChat_Provider_Anthropic extends HexChat_Provider {

	/**
	 * Complete a chat turn.
	 *
	 * @param string $system  System prompt.
	 * @param array  $history Prior turns.
	 * @param string $message Latest message.
	 * @return string|WP_Error
	 */
	public function complete( $system, array $history, $message ) {
		$messages = array();

		foreach ( $history as $turn ) {
			if ( empty( $turn['content'] ) ) {
				continue;
			}
			$messages[] = array(
				'role'    => ( 'assistant' === $turn['role'] ) ? 'assistant' : 'user',
				'content' => (string) $turn['content'],
			);
		}

		// Anthropic requires the first message to be from the user and messages to alternate.
		if ( empty( $messages ) || 'user' !== $messages[0]['role'] ) {
			array_unshift(
				$messages,
				array(
					'role'    => 'user',
					'content' => 'Hello.',
				)
			);
		}

		$messages[] = array(
			'role'    => 'user',
			'content' => $message,
		);

		$model = $this->model ? $this->model : 'claude-3-5-haiku-latest';

		$data = $this->post(
			'https://api.anthropic.com/v1/messages',
			array(
				'x-api-key'         => $this->api_key,
				'anthropic-version' => '2023-06-01',
				'Content-Type'      => 'application/json',
			),
			array(
				'model'       => $model,
				'system'      => $system,
				'messages'    => $messages,
				'max_tokens'  => (int) hexchat_opt( 'max_tokens' ),
				'temperature' => (float) hexchat_opt( 'temperature' ),
			)
		);

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$text = '';
		if ( isset( $data['content'] ) && is_array( $data['content'] ) ) {
			foreach ( $data['content'] as $part ) {
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
