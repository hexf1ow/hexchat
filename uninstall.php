<?php
/**
 * Uninstall cleanup.
 *
 * Runs only when the plugin is deleted, not deactivated. Removes options and the
 * transcript table so nothing is left behind on a site that has moved on.
 *
 * @package HexChat
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$hexchat_options = array(
	'hexchat_enabled',
	'hexchat_provider',
	'hexchat_base_url',
	'hexchat_model',
	'hexchat_temperature',
	'hexchat_max_tokens',
	'hexchat_max_message',
	'hexchat_rate_limit',
	'hexchat_greeting',
	'hexchat_title',
	'hexchat_system_prompt',
	'hexchat_handover_email',
	'hexchat_index_types',
	'hexchat_daily_cap',
	'hexchat_retention_days',
	'hexchat_brand_colour',
	'hexchat_position',
	'hexchat_api_key',
);

foreach ( $hexchat_options as $hexchat_option ) {
	delete_option( $hexchat_option );
}

$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'hexchat_messages' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
