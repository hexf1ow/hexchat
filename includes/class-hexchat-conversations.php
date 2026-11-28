<?php
/**
 * Conversations and client records.
 *
 * The transcript table stores messages one by one. This class rolls them up
 * into one row per conversation — the thing an owner actually reads — and
 * picks out an email address or phone number when a visitor leaves one, so a
 * chat becomes a client record instead of a dead log line.
 *
 * Everything here is plain SQL against the site's own database: no external
 * service, no synchronisation, no data leaving the WordPress install.
 *
 * @package HexChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class HexChat_Conversations
 */
class HexChat_Conversations {

	/**
	 * Conversations table without prefix.
	 */
	const TABLE = 'hexchat_conversations';

	/**
	 * Clients table without prefix.
	 */
	const TABLE_CLIENTS = 'hexchat_clients';

	/**
	 * Conversation states, in the order they normally move.
	 *
	 * ai          — the assistant is still handling it.
	 * needs_human — the assistant could not answer, or the visitor asked for a person.
	 * claimed     — a member of staff has taken it.
	 * answered    — a member of staff has replied.
	 *
	 * @return array
	 */
	public static function statuses() {
		return array(
			'ai'          => __( 'AI handling', 'hexchat' ),
			'needs_human' => __( 'Needs human', 'hexchat' ),
			'claimed'     => __( 'Claimed', 'hexchat' ),
			'answered'    => __( 'Answered', 'hexchat' ),
		);
	}

	/**
	 * Create or upgrade both tables.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset  = $wpdb->get_charset_collate();
		$conv     = $wpdb->prefix . self::TABLE;
		$clients  = $wpdb->prefix . self::TABLE_CLIENTS;

		$sql_conv = "CREATE TABLE {$conv} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_key VARCHAR(64) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'ai',
			summary TEXT NULL,
			client_id BIGINT UNSIGNED NULL,
			message_count INT NOT NULL DEFAULT 0,
			page_url VARCHAR(255) NULL,
			ip_hash CHAR(64) NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY session_key (session_key),
			KEY status (status),
			KEY updated_at (updated_at)
		) {$charset};";

		$sql_clients = "CREATE TABLE {$clients} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			email VARCHAR(190) NULL,
			phone VARCHAR(40) NULL,
			name VARCHAR(120) NULL,
			notes TEXT NULL,
			first_seen DATETIME NOT NULL,
			last_seen DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY email (email),
			KEY phone (phone)
		) {$charset};";

		dbDelta( $sql_conv );
		dbDelta( $sql_clients );

		update_option( 'hexchat_db_version', HEXCHAT_VERSION );
	}

	/**
	 * Create the tables when an updated plugin was never reactivated.
	 *
	 * The activation hook does not run on an update, so a site that installs
	 * 0.5.0 over 0.4.2 would have no conversations table until someone
	 * deactivated and reactivated. This checks the stored version once per
	 * request and installs when it is behind.
	 *
	 * @return void
	 */
	public static function maybe_install() {
		if ( get_option( 'hexchat_db_version' ) === HEXCHAT_VERSION ) {
			return;
		}

		self::install();
	}

	/**
	 * Rolled-up row for a session, or null.
	 *
	 * @param string $session_key Session key.
	 * @return array|null
	 */
	public static function get_by_session( $session_key ) {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE session_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				substr( (string) $session_key, 0, 64 )
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Called for every stored message: keeps the conversation row in step.
	 *
	 * @param string $session_key Session key.
	 * @param string $role        user|assistant.
	 * @param string $message     Message body.
	 * @param string $ip_hash     Hashed IP, never the raw address.
	 * @param bool   $escalated   Whether this exchange needed a human.
	 * @return void
	 */
	public static function touch( $session_key, $role, $message, $ip_hash = '', $escalated = false ) {
		global $wpdb;

		$session_key = substr( (string) $session_key, 0, 64 );

		if ( '' === $session_key ) {
			return;
		}

		$table = $wpdb->prefix . self::TABLE;
		$now   = current_time( 'mysql', true );

		$existing = self::get_by_session( $session_key );

		if ( ! $existing ) {
			$summary = ( 'user' === $role ) ? self::trim_summary( $message ) : '';

			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'session_key'   => $session_key,
					'status'        => $escalated ? 'needs_human' : 'ai',
					'summary'       => $summary,
					'message_count' => 1,
					'ip_hash'       => '' === $ip_hash ? null : substr( (string) $ip_hash, 0, 64 ),
					'created_at'    => $now,
					'updated_at'    => $now,
				),
				array( '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
			);

			$conversation_id = (int) $wpdb->insert_id;
			$summary         = (string) $summary;
		} else {
			$conversation_id = (int) $existing['id'];
			$summary         = (string) $existing['summary'];

			// The first human question is the best one-line label until a proper
			// summary is written in step 2. Never overwrite one that exists.
			if ( '' === $summary && 'user' === $role ) {
				$summary = self::trim_summary( $message );
			}

			$status = (string) $existing['status'];
			if ( $escalated && 'ai' === $status ) {
				$status = 'needs_human';
			}

			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"UPDATE {$table} SET message_count = message_count + 1, summary = %s, status = %s, updated_at = %s WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$summary,
					$status,
					$now,
					$conversation_id
				)
			);
		}

		if ( 'user' !== $role ) {
			return;
		}

		$client_id       = self::capture_client( $message, $conversation_id );
		$had_client      = is_array( $existing ) && ! empty( $existing['client_id'] );

		if ( $client_id && ! $had_client ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array( 'client_id' => $client_id ),
				array( 'id' => $conversation_id ),
				array( '%d' ),
				array( '%d' )
			);
		}
	}

	/**
	 * One-line label for a conversation.
	 *
	 * @param string $message Message body.
	 * @return string
	 */
	private static function trim_summary( $message ) {
		$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $message ) ) );

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, 0, 180 );
		}

		return substr( $text, 0, 180 );
	}

	/**
	 * Find an email address or phone number in a message and store the client.
	 *
	 * Deliberately conservative: only what the visitor typed, no guessing, and
	 * nothing at all when they typed neither.
	 *
	 * @param string $message         Message body.
	 * @param int    $conversation_id Conversation row.
	 * @return int Client id, or 0.
	 */
	public static function capture_client( $message, $conversation_id = 0 ) {
		global $wpdb;

		$message = (string) $message;
		$email   = '';
		$phone   = '';

		if ( preg_match( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $message, $m ) ) {
			$email = sanitize_email( $m[0] );
		}

		/*
		 * Australian numbers in the shapes people actually type:
		 *   0412 345 678   0412345678    +61 412 345 678
		 *   02 8958 6754   0289586754    (02) 8958 6754
		 *
		 * Brackets and dots are stripped from a copy first, so "(02) 8958 6754"
		 * matches the same pattern as "02 8958 6754".
		 */
		$probe = preg_replace( '/[^0-9+\s\-]/', ' ', $message );

		if ( preg_match( '/(?:\+?61[\s\-]*|0)(?:(?:2|3|7|8)[\s\-]*\d{4}[\s\-]*\d{4}|4\d{2}[\s\-]*\d{3}[\s\-]*\d{3})/', (string) $probe, $m ) ) {
			$phone = preg_replace( '/[^0-9+]/', '', $m[0] );
		}

		if ( '' === $email && '' === $phone ) {
			return 0;
		}

		$table = $wpdb->prefix . self::TABLE_CLIENTS;
		$now   = current_time( 'mysql', true );

		$existing = null;

		if ( '' !== $email ) {
			$existing = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE email = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$email
				),
				ARRAY_A
			);
		}

		if ( ! $existing && '' !== $phone ) {
			$existing = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE phone = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$phone
				),
				ARRAY_A
			);
		}

		if ( is_array( $existing ) ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'email'     => '' !== $email ? $email : $existing['email'],
					'phone'     => '' !== $phone ? $phone : $existing['phone'],
					'last_seen' => $now,
				),
				array( 'id' => (int) $existing['id'] ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);

			return (int) $existing['id'];
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'email'      => '' !== $email ? $email : null,
				'phone'      => '' !== $phone ? $phone : null,
				'first_seen' => $now,
				'last_seen'  => $now,
			),
			array( '%s', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Paged conversation list for the admin screen.
	 *
	 * @param array $args status / search / page / per_page.
	 * @return array
	 */
	public static function listing( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'status'   => '',
			'search'   => '',
			'page'     => 1,
			'per_page' => 20,
		);
		$args     = wp_parse_args( $args, $defaults );

		$conv    = $wpdb->prefix . self::TABLE;
		$clients = $wpdb->prefix . self::TABLE_CLIENTS;

		$where  = 'WHERE 1=1';
		$params = array();

		if ( '' !== $args['status'] && isset( self::statuses()[ $args['status'] ] ) ) {
			$where   .= ' AND c.status = %s';
			$params[] = $args['status'];
		}

		if ( '' !== $args['search'] ) {
			$where   .= ' AND (c.summary LIKE %s OR cl.email LIKE %s OR cl.phone LIKE %s)';
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$per_page = max( 1, min( 100, (int) $args['per_page'] ) );
		$offset   = ( max( 1, (int) $args['page'] ) - 1 ) * $per_page;

		$sql = "SELECT c.*, cl.email, cl.phone, cl.name
			FROM {$conv} c
			LEFT JOIN {$clients} cl ON cl.id = c.client_id
			{$where}
			ORDER BY c.updated_at DESC
			LIMIT %d OFFSET %d";

		$query_params = array_merge( $params, array( $per_page, $offset ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $query_params ), ARRAY_A );

		$count_sql = "SELECT COUNT(*) FROM {$conv} c LEFT JOIN {$clients} cl ON cl.id = c.client_id {$where}";
		$total     = $params
			? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			: (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Every message in one conversation, oldest first.
	 *
	 * @param int $conversation_id Conversation row.
	 * @return array
	 */
	public static function transcript( $conversation_id ) {
		global $wpdb;

		$conv = self::get_by_id( $conversation_id );

		if ( ! $conv ) {
			return array();
		}

		$table = $wpdb->prefix . HexChat_Store::TABLE;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT role, message, sources, escalated, created_at FROM {$table} WHERE session_key = %s ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$conv['session_key']
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * One conversation by id.
	 *
	 * @param int $id Row id.
	 * @return array|null
	 */
	public static function get_by_id( $id ) {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $id )
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * One client by id.
	 *
	 * @param int $id Row id.
	 * @return array|null
	 */
	public static function get_client( $id ) {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_CLIENTS;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $id )
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Client list for the admin screen.
	 *
	 * @param int $limit Max rows.
	 * @return array
	 */
	public static function clients( $limit = 100 ) {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_CLIENTS;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY last_seen DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $limit )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Counts per status, for the admin header.
	 *
	 * @return array
	 */
	public static function counts() {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$out = array();
		foreach ( self::statuses() as $key => $label ) {
			$out[ $key ] = 0;
		}
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['status'] ] = (int) $row['n'];
		}

		return $out;
	}

	/**
	 * Remove conversations and clients older than the retention setting.
	 *
	 * @return void
	 */
	public static function prune() {
		global $wpdb;

		$days = max( 1, (int) hexchat_opt( 'retention_days' ) );

		foreach ( array( self::TABLE, self::TABLE_CLIENTS ) as $name ) {
			$table = $wpdb->prefix . $name;
			$stamp = ( self::TABLE_CLIENTS === $name ) ? 'last_seen' : 'updated_at';

			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE {$stamp} < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$days
				)
			);
		}
	}
}
