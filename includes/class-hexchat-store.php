<?php
/**
 * Transcript storage.
 *
 * One table, one row per exchange. Deliberately plain: the site owner can read
 * it, export it, or drop it on uninstall. No personal data beyond what the
 * visitor chose to type.
 *
 * @package HexChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class HexChat_Store
 */
class HexChat_Store {

	/**
	 * Table name without prefix.
	 */
	const TABLE = 'hexchat_messages';

	/**
	 * Create or upgrade the table.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		$table   = $wpdb->prefix . self::TABLE;
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_key VARCHAR(64) NOT NULL,
			role VARCHAR(16) NOT NULL,
			message LONGTEXT NOT NULL,
			sources TEXT NULL,
			escalated TINYINT(1) NOT NULL DEFAULT 0,
			ip_hash CHAR(64) NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY session_key (session_key),
			KEY created_at (created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Store one message.
	 *
	 * @param array $row Row data.
	 * @return void
	 */
	public static function add( array $row ) {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		$role      = ( 'assistant' === $row['role'] ) ? 'assistant' : 'user';
		$escalated = empty( $row['escalated'] ) ? 0 : 1;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'session_key' => substr( (string) $row['session_key'], 0, 64 ),
				'role'        => $role,
				'message'     => (string) $row['message'],
				'sources'     => isset( $row['sources'] ) ? wp_json_encode( $row['sources'] ) : null,
				'escalated'   => $escalated,
				'ip_hash'     => isset( $row['ip_hash'] ) ? substr( (string) $row['ip_hash'], 0, 64 ) : null,
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		// Keep the conversation roll-up in step. Done here rather than in the REST
		// handler so every path that stores a message — including refusals and
		// provider errors — produces a readable conversation.
		if ( class_exists( 'HexChat_Conversations' ) ) {
			HexChat_Conversations::touch(
				substr( (string) $row['session_key'], 0, 64 ),
				$role,
				(string) $row['message'],
				isset( $row['ip_hash'] ) ? (string) $row['ip_hash'] : '',
				(bool) $escalated
			);
		}
	}

	/**
	 * Recent history for one session, oldest first.
	 *
	 * @param string $session_key Session key.
	 * @param int    $limit       Max rows.
	 * @return array
	 */
	public static function history( $session_key, $limit = 10 ) {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT role, message FROM {$table} WHERE session_key = %s ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$session_key,
				absint( $limit )
			),
			ARRAY_A
		);

		return array_reverse( is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Count messages from one IP hash in the last 24 hours — used for the hard daily cap.
	 *
	 * @param string $ip_hash Hashed IP.
	 * @return int
	 */
	public static function day_count( $ip_hash ) {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE ip_hash = %s AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$ip_hash
			)
		);
	}

	/**
	 * Delete rows older than the retention setting.
	 *
	 * @return int Rows removed.
	 */
	public static function prune() {
		global $wpdb;

		$days  = max( 1, (int) hexchat_opt( 'retention_days' ) );
		$table = $wpdb->prefix . self::TABLE;

		return (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$days
			)
		);
	}

	/**
	 * Top questions, trimmed for the admin panel.
	 *
	 * @param int $limit Max rows.
	 * @return array
	 */
	public static function top_questions( $limit = 10 ) {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT LEFT(message, 120) AS question, COUNT(*) AS hits FROM {$table} WHERE role = 'user' GROUP BY question ORDER BY hits DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $limit )
			),
			ARRAY_A
		);
	}

	/**
	 * Escalation count.
	 *
	 * @return int
	 */
	public static function escalated_count() {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE escalated = 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
