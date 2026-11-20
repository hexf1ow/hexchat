<?php
/**
 * Plugin Name:       HexChat
 * Plugin URI:        https://github.com/hexf1ow/hexchat
 * Description:       AI chat widget for WordPress. Free, open source, bring your own API key. Answers from your own site content and hands over to a human when it is unsure.
 * Version:           0.2.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Hex Flow
 * Author URI:        https://www.hexflow.com.au/
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       hexchat
 * Domain Path:       /languages
 *
 * @package HexChat
 */

defined( 'ABSPATH' ) || exit;

define( 'HEXCHAT_VERSION', '0.2.1' );
define( 'HEXCHAT_FILE', __FILE__ );
define( 'HEXCHAT_DIR', plugin_dir_path( __FILE__ ) );
define( 'HEXCHAT_URL', plugin_dir_url( __FILE__ ) );

require_once HEXCHAT_DIR . 'includes/class-hexchat-crypto.php';
require_once HEXCHAT_DIR . 'includes/class-hexchat-store.php';
require_once HEXCHAT_DIR . 'includes/class-hexchat-retrieval.php';
require_once HEXCHAT_DIR . 'includes/providers/class-hexchat-provider.php';
require_once HEXCHAT_DIR . 'includes/providers/class-hexchat-provider-openai.php';
require_once HEXCHAT_DIR . 'includes/providers/class-hexchat-provider-anthropic.php';
require_once HEXCHAT_DIR . 'includes/providers/class-hexchat-provider-gemini.php';
require_once HEXCHAT_DIR . 'includes/class-hexchat-settings.php';
require_once HEXCHAT_DIR . 'includes/class-hexchat-rest.php';

/**
 * Default option set. Kept in one place so activation, settings and REST agree.
 *
 * @return array
 */
function hexchat_defaults() {
	return array(
		'enabled'        => 0,
		'provider'       => 'openai',
		'base_url'       => 'https://api.openai.com/v1',
		'model'          => 'gpt-4o-mini',
		'temperature'    => 0.3,
		'max_tokens'     => 500,
		'max_message'    => 1500,
		'rate_limit'     => 20,
		'greeting'       => 'Hi there 👋 Ask me anything about this site — I answer from its own content.',
		'system_prompt'  => 'You are a helpful assistant for this website. Answer only from the CONTEXT provided. If the answer is not in the context, say you are not sure and offer to pass the question to a person. Never invent prices, phone numbers, addresses or legal claims. Keep answers under 90 words, plain English, no marketing padding.',
		'handover_email' => '',
		'no_answer_text' => 'I can only answer from what is written on this website, and I could not find anything here about that. I have not passed this to anyone yet — if you leave the question with our team they can help.',
		'index_types'    => array( 'page', 'post' ),
		'daily_cap'      => 200,
		'retention_days' => 30,
		'brand_colour'   => '',
		'position'       => 'right',
		'title'          => 'Chat with us',
	);
}

/**
 * Get one option with the default fallback.
 *
 * @param string $key Option key.
 * @return mixed
 */
function hexchat_opt( $key ) {
	$defaults = hexchat_defaults();
	$value    = get_option( 'hexchat_' . $key, isset( $defaults[ $key ] ) ? $defaults[ $key ] : '' );
	if ( '' === $value && isset( $defaults[ $key ] ) ) {
		return $defaults[ $key ];
	}
	return $value;
}

/**
 * Read a numeric option with a floor, falling back to the shipped default.
 *
 * WordPress writes an empty string for any registered option that is missing
 * from the submitted form, so a value can legitimately arrive as 0. Treating
 * that as "use the default" keeps a mis-saved screen from breaking the chat.
 *
 * @param string $key Option key without the hexchat_ prefix.
 * @param int    $min Lowest acceptable value.
 * @return int
 */
function hexchat_num( $key, $min = 1 ) {
	$value    = (int) hexchat_opt( $key );
	$defaults = hexchat_defaults();

	if ( $value < $min ) {
		$value = isset( $defaults[ $key ] ) ? (int) $defaults[ $key ] : $min;
	}

	return ( $value < $min ) ? $min : $value;
}

/**
 * Build the configured provider instance.
 *
 * @return HexChat_Provider|WP_Error
 */
function hexchat_provider() {
	$key      = HexChat_Crypto::get_api_key();
	$base_url = (string) hexchat_opt( 'base_url' );
	$model    = (string) hexchat_opt( 'model' );

	if ( '' === $key ) {
		return new WP_Error( 'hexchat_no_key', __( 'No API key saved yet.', 'hexchat' ) );
	}

	switch ( hexchat_opt( 'provider' ) ) {
		case 'anthropic':
			return new HexChat_Provider_Anthropic( $key, $model, $base_url );
		case 'gemini':
			return new HexChat_Provider_Gemini( $key, $model, $base_url );
		default:
			return new HexChat_Provider_OpenAI( $key, $model, $base_url );
	}
}

/**
 * Activation: transcript table + defaults.
 */
function hexchat_activate() {
	HexChat_Store::install();

	foreach ( hexchat_defaults() as $key => $value ) {
		if ( false === get_option( 'hexchat_' . $key, false ) ) {
			add_option( 'hexchat_' . $key, $value );
		}
	}
}
register_activation_hook( __FILE__, 'hexchat_activate' );

/**
 * Boot the plugin.
 */
function hexchat_boot() {
	load_plugin_textdomain( 'hexchat', false, dirname( plugin_basename( HEXCHAT_FILE ) ) . '/languages' );

	HexChat_Settings::init();
	HexChat_Rest::init();

	if ( hexchat_opt( 'enabled' ) ) {
		add_action( 'wp_enqueue_scripts', 'hexchat_enqueue_widget' );
		add_action( 'wp_footer', 'hexchat_render_mount' );
	}
}
add_action( 'plugins_loaded', 'hexchat_boot' );

/**
 * Front-end assets.
 */
function hexchat_enqueue_widget() {
	wp_enqueue_style( 'hexchat-widget', HEXCHAT_URL . 'assets/css/widget.css', array(), HEXCHAT_VERSION );
	wp_enqueue_script( 'hexchat-widget', HEXCHAT_URL . 'assets/js/widget.js', array(), HEXCHAT_VERSION, true );

	$brand = (string) hexchat_opt( 'brand_colour' );

	wp_localize_script(
		'hexchat-widget',
		'HexChatConfig',
		array(
			'endpoint'  => esc_url_raw( rest_url( 'hexchat/v1/chat' ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'cssUrl'    => HEXCHAT_URL . 'assets/css/widget.css?ver=' . HEXCHAT_VERSION,
			'title'     => (string) hexchat_opt( 'title' ),
			'greeting'  => (string) hexchat_opt( 'greeting' ),
			'position'  => (string) hexchat_opt( 'position' ),
			'brand'     => $brand ? sanitize_hex_color( $brand ) : '',
			'handover'  => (string) hexchat_opt( 'handover_email' ),
			'maxLength' => (int) hexchat_opt( 'max_message' ),
		)
	);
}

/**
 * Print the widget mount point.
 */
function hexchat_render_mount() {
	echo '<div id="hexchat-root" aria-live="polite"></div>';
}
