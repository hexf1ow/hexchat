<?php
/**
 * Admin screens: conversations, transcript, clients.
 *
 * Read-only by design. Step 2 adds the claim/reply workflow; this screen is
 * the place an owner opens a chat and sees what the visitor actually said.
 *
 * @package HexChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class HexChat_Admin
 */
class HexChat_Admin {

	/**
	 * Hook the menu.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
	}

	/**
	 * Top-level menu with three screens.
	 *
	 * @return void
	 */
	public static function menu() {
		$cap = 'manage_options';

		add_menu_page(
			__( 'HexChat', 'hexchat' ),
			__( 'HexChat', 'hexchat' ),
			$cap,
			'hexchat-conversations',
			array( __CLASS__, 'render_conversations' ),
			'dashicons-format-chat',
			58
		);

		add_submenu_page(
			'hexchat-conversations',
			__( 'Conversations', 'hexchat' ),
			__( 'Conversations', 'hexchat' ),
			$cap,
			'hexchat-conversations',
			array( __CLASS__, 'render_conversations' )
		);

		add_submenu_page(
			'hexchat-conversations',
			__( 'Clients', 'hexchat' ),
			__( 'Clients', 'hexchat' ),
			$cap,
			'hexchat-clients',
			array( __CLASS__, 'render_clients' )
		);

		add_submenu_page(
			'hexchat-conversations',
			__( 'Settings', 'hexchat' ),
			__( 'Settings', 'hexchat' ),
			$cap,
			'options-general.php?page=hexchat',
			''
		);
	}

	/**
	 * Conversation list, or one transcript when ?conversation= is set.
	 *
	 * @return void
	 */
	public static function render_conversations() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$id = isset( $_GET['conversation'] ) ? absint( $_GET['conversation'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $id ) {
			self::render_transcript( $id );
			return;
		}

		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$per_page = 20;
		$data     = HexChat_Conversations::listing(
			array(
				'status'   => $status,
				'search'   => $search,
				'page'     => $paged,
				'per_page' => $per_page,
			)
		);

		$counts   = HexChat_Conversations::counts();
		$statuses = HexChat_Conversations::statuses();
		$total    = (int) $data['total'];
		$pages    = (int) ceil( $total / $per_page );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'HexChat conversations', 'hexchat' ); ?></h1>

			<p>
				<?php
				printf(
					/* translators: %d: number of conversations. */
					esc_html( _n( '%d conversation', '%d conversations', $total, 'hexchat' ) ),
					(int) $total
				);
				?>
			</p>

			<ul class="subsubsub">
				<li>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=hexchat-conversations' ) ); ?>" class="<?php echo '' === $status ? 'current' : ''; ?>">
						<?php esc_html_e( 'All', 'hexchat' ); ?> <span class="count">(<?php echo (int) array_sum( $counts ); ?>)</span>
					</a>
				</li>
				<?php foreach ( $statuses as $key => $label ) : ?>
					<li>
						| <a href="<?php echo esc_url( admin_url( 'admin.php?page=hexchat-conversations&status=' . $key ) ); ?>" class="<?php echo $status === $key ? 'current' : ''; ?>">
							<?php echo esc_html( $label ); ?> <span class="count">(<?php echo (int) $counts[ $key ]; ?>)</span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>

			<form method="get" style="margin:12px 0;">
				<input type="hidden" name="page" value="hexchat-conversations" />
				<?php if ( '' !== $status ) : ?>
					<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>" />
				<?php endif; ?>
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" style="min-width:320px;" placeholder="<?php esc_attr_e( 'Search summary, email or phone', 'hexchat' ); ?>" />
				<?php submit_button( __( 'Search', 'hexchat' ), 'secondary', '', false ); ?>
			</form>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th style="width:150px;"><?php esc_html_e( 'Last activity', 'hexchat' ); ?></th>
						<th><?php esc_html_e( 'Summary', 'hexchat' ); ?></th>
						<th style="width:180px;"><?php esc_html_e( 'Client', 'hexchat' ); ?></th>
						<th style="width:110px;"><?php esc_html_e( 'Status', 'hexchat' ); ?></th>
						<th style="width:80px;"><?php esc_html_e( 'Messages', 'hexchat' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $data['rows'] ) ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'No conversations yet. They appear as soon as a visitor uses the chat.', 'hexchat' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $data['rows'] as $row ) : ?>
						<?php
						$link = admin_url( 'admin.php?page=hexchat-conversations&conversation=' . (int) $row['id'] );
						$who  = trim( (string) $row['name'] );
						if ( '' === $who ) {
							$who = trim( (string) $row['email'] );
						}
						if ( '' === $who ) {
							$who = trim( (string) $row['phone'] );
						}
						?>
						<tr>
							<td><?php echo esc_html( mysql2date( 'j M Y H:i', $row['updated_at'] ) ); ?></td>
							<td>
								<strong><a href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( '' !== (string) $row['summary'] ? (string) $row['summary'] : __( '(no text)', 'hexchat' ) ); ?></a></strong>
							</td>
							<td><?php echo esc_html( '' !== $who ? $who : '—' ); ?></td>
							<td>
								<?php
								$key   = (string) $row['status'];
								$label = isset( $statuses[ $key ] ) ? $statuses[ $key ] : $key;
								echo esc_html( $label );
								?>
							</td>
							<td><?php echo (int) $row['message_count']; ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $pages > 1 ) : ?>
				<div class="tablenav"><div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => add_query_arg( 'paged', '%#%' ),
								'format'    => '',
								'prev_text' => '&laquo;',
								'next_text' => '&raquo;',
								'total'     => $pages,
								'current'   => $paged,
							)
						)
					);
					?>
				</div></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * One conversation: client card, summary, full transcript.
	 *
	 * @param int $id Conversation id.
	 * @return void
	 */
	private static function render_transcript( $id ) {
		$conv = HexChat_Conversations::get_by_id( $id );

		if ( ! $conv ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Conversation not found', 'hexchat' ) . '</h1></div>';
			return;
		}

		$statuses = HexChat_Conversations::statuses();
		$status   = (string) $conv['status'];
		$client   = ! empty( $conv['client_id'] ) ? HexChat_Conversations::get_client( (int) $conv['client_id'] ) : null;
		$rows     = HexChat_Conversations::transcript( $id );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Conversation', 'hexchat' ); ?></h1>
			<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=hexchat-conversations' ) ); ?>"><?php esc_html_e( 'Back to list', 'hexchat' ); ?></a>
			<hr class="wp-header-end" />

			<table class="widefat" style="max-width:900px;margin-bottom:20px;">
				<tbody>
					<tr>
						<th style="width:180px;"><?php esc_html_e( 'Started', 'hexchat' ); ?></th>
						<td><?php echo esc_html( mysql2date( 'j M Y H:i', $conv['created_at'] ) ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Last activity', 'hexchat' ); ?></th>
						<td><?php echo esc_html( mysql2date( 'j M Y H:i', $conv['updated_at'] ) ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Status', 'hexchat' ); ?></th>
						<td><?php echo esc_html( isset( $statuses[ $status ] ) ? $statuses[ $status ] : $status ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Messages', 'hexchat' ); ?></th>
						<td><?php echo (int) $conv['message_count']; ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Client', 'hexchat' ); ?></th>
						<td>
							<?php if ( $client ) : ?>
								<?php echo esc_html( (string) $client['name'] ); ?>
								<?php if ( ! empty( $client['email'] ) ) : ?>
									<br /><a href="mailto:<?php echo esc_attr( (string) $client['email'] ); ?>"><?php echo esc_html( (string) $client['email'] ); ?></a>
								<?php endif; ?>
								<?php if ( ! empty( $client['phone'] ) ) : ?>
									<br /><a href="tel:<?php echo esc_attr( (string) $client['phone'] ); ?>"><?php echo esc_html( (string) $client['phone'] ); ?></a>
								<?php endif; ?>
							<?php else : ?>
								<em><?php esc_html_e( 'No contact details left yet', 'hexchat' ); ?></em>
							<?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Transcript', 'hexchat' ); ?></h2>
			<div style="max-width:900px;">
				<?php if ( empty( $rows ) ) : ?>
					<p><em><?php esc_html_e( 'No messages recorded.', 'hexchat' ); ?></em></p>
				<?php else : ?>
					<?php foreach ( $rows as $row ) : ?>
						<?php $is_user = ( 'user' === $row['role'] ); ?>
						<div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:<?php echo $is_user ? '#eef4ff' : '#f6f7f7'; ?>;">
							<div style="font-size:11px;text-transform:uppercase;color:#666;margin-bottom:4px;">
								<?php echo $is_user ? esc_html__( 'Visitor', 'hexchat' ) : esc_html__( 'Assistant', 'hexchat' ); ?>
								· <?php echo esc_html( mysql2date( 'j M H:i', $row['created_at'] ) ); ?>
								<?php if ( ! empty( $row['escalated'] ) ) : ?>
									· <?php esc_html_e( 'needed a person', 'hexchat' ); ?>
								<?php endif; ?>
							</div>
							<div><?php echo nl2br( esc_html( (string) $row['message'] ) ); ?></div>
							<?php
							$sources = json_decode( (string) $row['sources'], true );
							if ( is_array( $sources ) && $sources ) :
								?>
								<div style="font-size:11px;color:#666;margin-top:6px;">
									<?php esc_html_e( 'From:', 'hexchat' ); ?>
									<?php
									$names = array();
									foreach ( $sources as $src ) {
										if ( is_array( $src ) && isset( $src['title'] ) ) {
											$names[] = (string) $src['title'];
										} elseif ( is_string( $src ) ) {
											$names[] = $src;
										}
									}
									echo esc_html( implode( ', ', $names ) );
									?>
								</div>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Client list.
	 *
	 * @return void
	 */
	public static function render_clients() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$clients = HexChat_Conversations::clients( 200 );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'HexChat clients', 'hexchat' ); ?></h1>
			<p><?php esc_html_e( 'Collected only when a visitor types an email address or phone number into the chat.', 'hexchat' ); ?></p>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'hexchat' ); ?></th>
						<th><?php esc_html_e( 'Email', 'hexchat' ); ?></th>
						<th><?php esc_html_e( 'Phone', 'hexchat' ); ?></th>
						<th style="width:150px;"><?php esc_html_e( 'First seen', 'hexchat' ); ?></th>
						<th style="width:150px;"><?php esc_html_e( 'Last seen', 'hexchat' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $clients ) ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'No clients captured yet.', 'hexchat' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $clients as $client ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $client['name'] ); ?></td>
							<td><?php echo esc_html( (string) $client['email'] ); ?></td>
							<td><?php echo esc_html( (string) $client['phone'] ); ?></td>
							<td><?php echo esc_html( mysql2date( 'j M Y H:i', $client['first_seen'] ) ); ?></td>
							<td><?php echo esc_html( mysql2date( 'j M Y H:i', $client['last_seen'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
