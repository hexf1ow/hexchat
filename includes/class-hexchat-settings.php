<?php
/**
 * Admin settings screen.
 *
 * @package HexChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class HexChat_Settings
 */
class HexChat_Settings {

	/**
	 * Option group.
	 */
	const GROUP = 'hexchat_settings';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'update_option_hexchat_index_types', array( 'HexChat_Retrieval', 'flush' ) );
	}

	/**
	 * Menu entry.
	 *
	 * @return void
	 */
	public static function menu() {
		add_options_page(
			__( 'HexChat', 'hexchat' ),
			__( 'HexChat', 'hexchat' ),
			'manage_options',
			'hexchat',
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Admin styles — small, inline so there is no extra request.
	 *
	 * @param string $hook Current admin page.
	 * @return void
	 */
	public static function assets( $hook ) {
		if ( 'settings_page_hexchat' !== $hook ) {
			return;
		}

		$css = '.hexchat-wrap{max-width:860px}.hexchat-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px 22px;margin:18px 0;box-shadow:0 1px 2px rgba(0,0,0,.04)}.hexchat-card h2{margin-top:0;font-size:15px;text-transform:uppercase;letter-spacing:.04em;color:#50575e}.hexchat-row{display:flex;gap:18px;flex-wrap:wrap}.hexchat-col{flex:1 1 240px}.hexchat-wrap input[type=text],.hexchat-wrap input[type=password],.hexchat-wrap input[type=number],.hexchat-wrap select,.hexchat-wrap textarea{width:100%;max-width:520px}.hexchat-wrap textarea{min-height:110px}.hexchat-hint{color:#646970;font-size:12px;margin:4px 0 0}.hexchat-badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600}.hexchat-badge.on{background:#d1f0d8;color:#146c2e}.hexchat-badge.off{background:#fde2e1;color:#8a1f1a}';

		wp_register_style( 'hexchat-admin', false, array(), HEXCHAT_VERSION );
		wp_enqueue_style( 'hexchat-admin' );
		wp_add_inline_style( 'hexchat-admin', $css );
	}

	/**
	 * Register settings.
	 *
	 * @return void
	 */
	public static function register() {
		$fields = array(
			'enabled'        => 'int',
			'provider'       => 'text',
			'base_url'       => 'text',
			'model'          => 'text',
			'temperature'    => 'float',
			'max_tokens'     => 'int',
			'max_message'    => 'int',
			'rate_limit'     => 'int',
			'daily_cap'      => 'int',
			'greeting'       => 'text',
			'title'          => 'text',
			'system_prompt'  => 'text',
			'handover_email' => 'text',
			'index_types'    => 'array',
			'retention_days' => 'int',
			'brand_colour'   => 'text',
			'position'       => 'text',
		);

		foreach ( $fields as $name => $type ) {
			$callback = 'sanitize_text_field';
			if ( 'int' === $type ) {
				$callback = 'absint';
			} elseif ( 'float' === $type ) {
				$callback = static function ( $value ) {
					return max( 0, min( 2, (float) $value ) );
				};
			} elseif ( 'array' === $type ) {
				$callback = static function ( $value ) {
					return array_values( array_intersect( array_map( 'sanitize_key', (array) $value ), array( 'page', 'post' ) ) );
				};
			}

			register_setting(
				self::GROUP,
				'hexchat_' . $name,
				array(
					'type'              => ( 'array' === $type ) ? 'array' : ( in_array( $type, array( 'int', 'float' ), true ) ? 'number' : 'string' ),
					'sanitize_callback' => $callback,
					'default'           => hexchat_defaults()[ $name ],
				)
			);
		}

		// The API key is stored separately, encrypted, and never rendered back.
		if ( isset( $_POST['hexchat_api_key_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['hexchat_api_key_nonce'] ) ), 'hexchat_save_key' ) && current_user_can( 'manage_options' ) ) {
			$raw = isset( $_POST['hexchat_api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['hexchat_api_key'] ) ) ) : '';
			if ( '' !== $raw ) {
				HexChat_Crypto::set_api_key( $raw );
			}
			if ( ! empty( $_POST['hexchat_clear_key'] ) ) {
				HexChat_Crypto::set_api_key( '' );
			}
		}
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$enabled  = (int) hexchat_opt( 'enabled' );
		$has_key  = HexChat_Crypto::has_api_key();
		$provider = (string) hexchat_opt( 'provider' );
		$saved    = isset( $_GET['settings-updated'] ) ? true : false; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap hexchat-wrap">
			<h1><?php esc_html_e( 'HexChat', 'hexchat' ); ?>
				<span class="hexchat-badge <?php echo $enabled ? 'on' : 'off'; ?>">
					<?php echo $enabled ? esc_html__( 'Live', 'hexchat' ) : esc_html__( 'Off', 'hexchat' ); ?>
				</span>
			</h1>
			<p class="hexchat-hint">
				<?php esc_html_e( 'Free and open source. You bring your own API key — it is stored encrypted and never sent to the browser.', 'hexchat' ); ?>
			</p>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'hexchat' ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! $has_key ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'No API key yet — the chat will not answer until you add one below.', 'hexchat' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<div class="hexchat-card">
					<h2><?php esc_html_e( 'Model', 'hexchat' ); ?></h2>
					<div class="hexchat-row">
						<div class="hexchat-col">
							<label for="hexchat_provider"><strong><?php esc_html_e( 'Provider', 'hexchat' ); ?></strong></label>
							<select id="hexchat_provider" name="hexchat_provider">
								<option value="openai" <?php selected( $provider, 'openai' ); ?>><?php esc_html_e( 'OpenAI-compatible', 'hexchat' ); ?></option>
								<option value="anthropic" <?php selected( $provider, 'anthropic' ); ?>><?php esc_html_e( 'Anthropic', 'hexchat' ); ?></option>
								<option value="gemini" <?php selected( $provider, 'gemini' ); ?>><?php esc_html_e( 'Google Gemini', 'hexchat' ); ?></option>
							</select>
							<p class="hexchat-hint"><?php esc_html_e( 'OpenAI-compatible also covers OpenRouter, Groq, Ollama, LM Studio and vLLM.', 'hexchat' ); ?></p>
						</div>
						<div class="hexchat-col">
							<label for="hexchat_model"><strong><?php esc_html_e( 'Model', 'hexchat' ); ?></strong></label>
							<input type="text" id="hexchat_model" name="hexchat_model" value="<?php echo esc_attr( hexchat_opt( 'model' ) ); ?>" />
							<p class="hexchat-hint"><?php esc_html_e( 'For example gpt-4o-mini, claude-3-5-haiku-latest, gemini-2.0-flash.', 'hexchat' ); ?></p>
						</div>
					</div>
					<div class="hexchat-row">
						<div class="hexchat-col">
							<label for="hexchat_base_url"><strong><?php esc_html_e( 'API base URL', 'hexchat' ); ?></strong></label>
							<input type="text" id="hexchat_base_url" name="hexchat_base_url" value="<?php echo esc_attr( hexchat_opt( 'base_url' ) ); ?>" />
							<p class="hexchat-hint"><?php esc_html_e( 'OpenAI-compatible only. Leave the default for OpenAI itself.', 'hexchat' ); ?></p>
						</div>
						<div class="hexchat-col">
							<label for="hexchat_temperature"><strong><?php esc_html_e( 'Temperature', 'hexchat' ); ?></strong></label>
							<input type="number" step="0.1" min="0" max="2" id="hexchat_temperature" name="hexchat_temperature" value="<?php echo esc_attr( hexchat_opt( 'temperature' ) ); ?>" />
							<p class="hexchat-hint"><?php esc_html_e( '0.2–0.4 keeps answers factual. Higher is chattier and less reliable.', 'hexchat' ); ?></p>
						</div>
					</div>
				</div>

				<div class="hexchat-card">
					<h2><?php esc_html_e( 'Your API key', 'hexchat' ); ?></h2>
					<?php if ( $has_key ) : ?>
						<p><?php esc_html_e( 'A key is saved:', 'hexchat' ); ?> <code><?php echo esc_html( HexChat_Crypto::key_hint() ); ?></code></p>
					<?php endif; ?>
					<input type="password" name="hexchat_api_key" id="hexchat_api_key" value="" autocomplete="off" placeholder="<?php echo $has_key ? esc_attr__( 'Paste a new key to replace the saved one', 'hexchat' ) : esc_attr__( 'Paste your API key', 'hexchat' ); ?>" />
					<p class="hexchat-hint"><?php esc_html_e( 'Stored encrypted with this site’s own secret. It is never displayed again and never leaves the server except to call the provider.', 'hexchat' ); ?></p>
					<?php if ( $has_key ) : ?>
						<p><label><input type="checkbox" name="hexchat_clear_key" value="1" /> <?php esc_html_e( 'Remove the saved key', 'hexchat' ); ?></label></p>
					<?php endif; ?>
					<?php wp_nonce_field( 'hexchat_save_key', 'hexchat_api_key_nonce' ); ?>
				</div>

				<div class="hexchat-card">
					<h2><?php esc_html_e( 'Knowledge', 'hexchat' ); ?></h2>
					<div class="hexchat-row">
						<div class="hexchat-col">
							<label><strong><?php esc_html_e( 'Index content from', 'hexchat' ); ?></strong></label>
							<?php $types = (array) hexchat_opt( 'index_types' ); ?>
							<p><label><input type="checkbox" name="hexchat_index_types[]" value="page" <?php checked( in_array( 'page', $types, true ) ); ?> /> <?php esc_html_e( 'Pages', 'hexchat' ); ?></label></p>
							<p><label><input type="checkbox" name="hexchat_index_types[]" value="post" <?php checked( in_array( 'post', $types, true ) ); ?> /> <?php esc_html_e( 'Posts', 'hexchat' ); ?></label></p>
							<p class="hexchat-hint"><?php esc_html_e( 'The chat answers from these pages and nothing else. The index rebuilds itself when content changes.', 'hexchat' ); ?></p>
						</div>
						<div class="hexchat-col">
							<label for="hexchat_system_prompt"><strong><?php esc_html_e( 'Instructions to the AI', 'hexchat' ); ?></strong></label>
							<textarea id="hexchat_system_prompt" name="hexchat_system_prompt"><?php echo esc_textarea( hexchat_opt( 'system_prompt' ) ); ?></textarea>
							<p class="hexchat-hint"><?php esc_html_e( 'Tone, boundaries and what it must never claim. Site context is appended automatically.', 'hexchat' ); ?></p>
						</div>
					</div>
				</div>

				<div class="hexchat-card">
					<h2><?php esc_html_e( 'Widget', 'hexchat' ); ?></h2>
					<div class="hexchat-row">
						<div class="hexchat-col">
							<p><label><input type="checkbox" name="hexchat_enabled" value="1" <?php checked( $enabled, 1 ); ?> /> <strong><?php esc_html_e( 'Show the chat widget on the site', 'hexchat' ); ?></strong></label></p>
							<label for="hexchat_title"><strong><?php esc_html_e( 'Panel title', 'hexchat' ); ?></strong></label>
							<input type="text" id="hexchat_title" name="hexchat_title" value="<?php echo esc_attr( hexchat_opt( 'title' ) ); ?>" />
							<label for="hexchat_greeting"><strong><?php esc_html_e( 'Opening message', 'hexchat' ); ?></strong></label>
							<input type="text" id="hexchat_greeting" name="hexchat_greeting" value="<?php echo esc_attr( hexchat_opt( 'greeting' ) ); ?>" />
						</div>
						<div class="hexchat-col">
							<label for="hexchat_position"><strong><?php esc_html_e( 'Launcher position', 'hexchat' ); ?></strong></label>
							<select id="hexchat_position" name="hexchat_position">
								<option value="right" <?php selected( hexchat_opt( 'position' ), 'right' ); ?>><?php esc_html_e( 'Bottom right', 'hexchat' ); ?></option>
								<option value="left" <?php selected( hexchat_opt( 'position' ), 'left' ); ?>><?php esc_html_e( 'Bottom left', 'hexchat' ); ?></option>
							</select>
							<label for="hexchat_brand_colour"><strong><?php esc_html_e( 'Brand colour', 'hexchat' ); ?></strong></label>
							<input type="text" id="hexchat_brand_colour" name="hexchat_brand_colour" value="<?php echo esc_attr( hexchat_opt( 'brand_colour' ) ); ?>" placeholder="#1f3864" />
							<p class="hexchat-hint"><?php esc_html_e( 'Leave blank to inherit the theme’s colours.', 'hexchat' ); ?></p>
						</div>
					</div>
				</div>

				<div class="hexchat-card">
					<h2><?php esc_html_e( 'Limits and privacy', 'hexchat' ); ?></h2>
					<div class="hexchat-row">
						<div class="hexchat-col">
							<label for="hexchat_rate_limit"><strong><?php esc_html_e( 'Messages per minute, per visitor', 'hexchat' ); ?></strong></label>
							<input type="number" min="0" id="hexchat_rate_limit" name="hexchat_rate_limit" value="<?php echo esc_attr( hexchat_opt( 'rate_limit' ) ); ?>" />
							<label for="hexchat_daily_cap"><strong><?php esc_html_e( 'Messages per day, per visitor', 'hexchat' ); ?></strong></label>
							<input type="number" min="0" id="hexchat_daily_cap" name="hexchat_daily_cap" value="<?php echo esc_attr( hexchat_opt( 'daily_cap' ) ); ?>" />
							<p class="hexchat-hint"><?php esc_html_e( 'These caps protect your API bill. 0 disables the cap.', 'hexchat' ); ?></p>
						</div>
						<div class="hexchat-col">
							<label for="hexchat_max_message"><strong><?php esc_html_e( 'Longest message (characters)', 'hexchat' ); ?></strong></label>
							<input type="number" min="200" id="hexchat_max_message" name="hexchat_max_message" value="<?php echo esc_attr( hexchat_opt( 'max_message' ) ); ?>" />
							<label for="hexchat_retention_days"><strong><?php esc_html_e( 'Keep transcripts for (days)', 'hexchat' ); ?></strong></label>
							<input type="number" min="1" id="hexchat_retention_days" name="hexchat_retention_days" value="<?php echo esc_attr( hexchat_opt( 'retention_days' ) ); ?>" />
							<label for="hexchat_handover_email"><strong><?php esc_html_e( 'Handover email', 'hexchat' ); ?></strong></label>
							<input type="text" id="hexchat_handover_email" name="hexchat_handover_email" value="<?php echo esc_attr( hexchat_opt( 'handover_email' ) ); ?>" placeholder="info@example.com" />
						</div>
					</div>
				</div>

				<?php submit_button(); ?>
			</form>

			<?php self::render_stats(); ?>
		</div>
		<?php
	}

	/**
	 * Small read-only panel: what people are asking, how often it escalated.
	 *
	 * @return void
	 */
	private static function render_stats() {
		$top = HexChat_Store::top_questions( 8 );
		?>
		<div class="hexchat-card">
			<h2><?php esc_html_e( 'What visitors ask', 'hexchat' ); ?></h2>
			<p class="hexchat-hint"><?php esc_html_e( 'Use this to add the missing answers to your pages — the chat can only know what the site says.', 'hexchat' ); ?></p>
			<?php if ( empty( $top ) ) : ?>
				<p><?php esc_html_e( 'No conversations yet.', 'hexchat' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Question', 'hexchat' ); ?></th><th style="width:80px"><?php esc_html_e( 'Times', 'hexchat' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $top as $row ) : ?>
						<tr><td><?php echo esc_html( $row['question'] ); ?></td><td><?php echo esc_html( $row['hits'] ); ?></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<p class="hexchat-hint"><?php printf( esc_html__( 'Handed to a human: %d times.', 'hexchat' ), (int) HexChat_Store::escalated_count() ); ?></p>
		</div>
		<?php
	}
}
