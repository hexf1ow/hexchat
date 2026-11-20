<?php
/**
 * REST endpoint the widget talks to.
 *
 * The browser never sees the API key: it posts a question here, WordPress calls
 * the model server-side and returns the answer. Every request is nonce-checked,
 * length-capped, rate-limited per IP and logged.
 *
 * @package HexChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class HexChat_Rest
 */
class HexChat_Rest {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Routes.
	 *
	 * @return void
	 */
	public static function routes() {
		register_rest_route(
			'hexchat/v1',
			'/chat',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'chat' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'message' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'session' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Hash the visitor IP — never store it raw.
	 *
	 * @return string
	 */
	private static function ip_hash() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return hash( 'sha256', 'hexchat|' . $ip . '|' . ( defined( 'NONCE_SALT' ) ? NONCE_SALT : '' ) );
	}

	/**
	 * Handle a message.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function chat( WP_REST_Request $request ) {
		if ( ! hexchat_opt( 'enabled' ) ) {
			return new WP_Error( 'hexchat_disabled', __( 'Chat is switched off.', 'hexchat' ), array( 'status' => 403 ) );
		}

		// CSRF: the widget sends the wp_rest nonce.
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'hexchat_nonce', __( 'Session expired — please reload the page.', 'hexchat' ), array( 'status' => 403 ) );
		}

		$message = trim( (string) $request->get_param( 'message' ) );
		$session = substr( preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) $request->get_param( 'session' ) ), 0, 64 );

		if ( '' === $message ) {
			return new WP_Error( 'hexchat_empty_message', __( 'Please type a question.', 'hexchat' ), array( 'status' => 400 ) );
		}

		$max_message = (int) hexchat_opt( 'max_message' );
		if ( $max_message > 0 && mb_strlen( $message ) > $max_message ) {
			$message = mb_substr( $message, 0, $max_message );
		}

		if ( '' === $session ) {
			$session = wp_generate_password( 24, false, false );
		}

		$ip_hash = self::ip_hash();

		// Hard daily cap per IP, independent of the per-minute limiter.
		$daily = (int) hexchat_opt( 'daily_cap' );
		if ( $daily > 0 && HexChat_Store::day_count( $ip_hash ) >= $daily ) {
			return new WP_Error( 'hexchat_day_cap', __( 'This chat has reached its daily limit. Please try again tomorrow or contact the site owner.', 'hexchat' ), array( 'status' => 429 ) );
		}

		$limit = (int) hexchat_opt( 'rate_limit' );
		if ( $limit > 0 ) {
			$bucket = 'hexchat_rl_' . substr( $ip_hash, 0, 24 );
			$hits   = (int) get_transient( $bucket );
			if ( $hits >= $limit ) {
				return new WP_Error( 'hexchat_rate', __( 'Too many messages — give it a minute and try again.', 'hexchat' ), array( 'status' => 429 ) );
			}
			set_transient( $bucket, $hits + 1, MINUTE_IN_SECONDS );
		}

		$provider = hexchat_provider();
		if ( is_wp_error( $provider ) ) {
			return new WP_Error( 'hexchat_config', __( 'This chat is not configured yet — the site owner needs to add an API key.', 'hexchat' ), array( 'status' => 503 ) );
		}

		// Retrieve from this site's own content.
		$passages = HexChat_Retrieval::search( $message, 4 );

		/*
		 * Nothing matched word-for-word. That is normal for broad questions
		 * ("what type of company are you", "hi what do you guys do") because the
		 * site never uses those exact words. Rather than dead-end the visitor,
		 * hand the model the site's own introduction and let it answer from that.
		 * The grounding check below still decides whether the reply survives.
		 */
		if ( empty( $passages ) ) {
			$passages = HexChat_Retrieval::overview( 3 );
		} else {
			/*
			 * Always keep the site's own introduction in the context. A question
			 * like "what type of company are you" matches loose words in blog
			 * posts, and without the homepage the answer goes vague.
			 */
			$have = wp_list_pluck( $passages, 'id' );
			foreach ( HexChat_Retrieval::overview( 1 ) as $intro ) {
				if ( ! in_array( $intro['id'], $have, true ) ) {
					$passages[] = $intro;
				}
			}
		}

		list( $context, $src ) = HexChat_Retrieval::context( $passages );

		$history = HexChat_Store::history( $session, 8 );

		/*
		 * THE WALL.
		 *
		 * If retrieval finds nothing on this site for the question, the model is
		 * never called. No API spend, no chance of the model answering from its
		 * own general knowledge, no clever phrasing getting past the prompt.
		 * The visitor gets a fixed refusal plus the handover route instead.
		 */
		if ( '' === $context ) {
			$refusal = (string) hexchat_opt( 'no_answer_text' );
			if ( '' === $refusal ) {
				$refusal = __( 'I can only answer from what is written on this website, and I could not find anything here about that. I have not passed this to anyone yet — if you leave the question with our team they can help.', 'hexchat' );
			}

			HexChat_Store::add(
				array(
					'session_key' => $session,
					'role'        => 'user',
					'message'     => $message,
					'ip_hash'     => $ip_hash,
				)
			);

			HexChat_Store::add(
				array(
					'session_key' => $session,
					'role'        => 'assistant',
					'message'     => $refusal,
					'escalated'   => 1,
					'ip_hash'     => $ip_hash,
				)
			);

			return new WP_REST_Response(
				array(
					'session'   => $session,
					'reply'     => $refusal,
					'sources'   => array(),
					'escalated' => true,
					'blocked'   => 'no_context',
				),
				200
			);
		}

		$system  = (string) hexchat_opt( 'system_prompt' );
		$system .= "\n\nSITE: " . home_url();
		$system .= "\n\nCONTEXT (answer from this, nothing else):\n" . $context;
		$system .= "\n\nIf the CONTEXT states a price, rate, fee or timeframe, you MUST quote it exactly as written and say which plan or package it belongs to. Never invent a figure, but never withhold one that is in the context either.";
		$system .= "\n\nIf the question is about something this site does not cover, do not answer it from general knowledge. Say in one sentence that you can only help with what is on this site, name what the business does in plain words, and offer to pass the question to the team.";

		$answer = $provider->complete( $system, $history, $message );

		if ( is_wp_error( $answer ) ) {
			HexChat_Store::add(
				array(
					'session_key' => $session,
					'role'        => 'user',
					'message'     => $message,
					'ip_hash'     => $ip_hash,
				)
			);

			return $answer;
		}

		/*
		 * Second half of the wall: the reply must actually be made of words the
		 * site uses. A model that ignores its instructions and answers from
		 * general knowledge fails this check, and the text is discarded.
		 */
		if ( ! HexChat_Retrieval::is_grounded( $answer, $passages, 0.55 ) ) {
			$refusal = (string) hexchat_opt( 'no_answer_text' );
			if ( '' === $refusal ) {
				$refusal = __( 'I can only answer from what is written on this website, and I could not find anything here about that. I have not passed this to anyone yet — if you leave the question with our team they can help.', 'hexchat' );
			}

			HexChat_Store::add(
				array(
					'session_key' => $session,
					'role'        => 'user',
					'message'     => $message,
					'ip_hash'     => $ip_hash,
				)
			);

			HexChat_Store::add(
				array(
					'session_key' => $session,
					'role'        => 'assistant',
					'message'     => $refusal,
					'escalated'   => 1,
					'ip_hash'     => $ip_hash,
				)
			);

			return new WP_REST_Response(
				array(
					'session'   => $session,
					'reply'     => $refusal,
					'sources'   => array(),
					'escalated' => true,
					'blocked'   => 'ungrounded',
				),
				200
			);
		}

		$escalated = ( false !== stripos( $answer, 'pass this to a person' ) || false !== stripos( $answer, 'not sure' ) );

		HexChat_Store::add(
			array(
				'session_key' => $session,
				'role'        => 'user',
				'message'     => $message,
				'ip_hash'     => $ip_hash,
			)
		);

		HexChat_Store::add(
			array(
				'session_key' => $session,
				'role'        => 'assistant',
				'message'     => $answer,
				'sources'     => $src,
				'escalated'   => $escalated,
				'ip_hash'     => $ip_hash,
			)
		);

		return new WP_REST_Response(
			array(
				'session'   => $session,
				'reply'     => $answer,
				'sources'   => $src,
				'escalated' => $escalated,
			),
			200
		);
	}
}
