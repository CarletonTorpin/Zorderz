<?php
/**
 * ZIM_Admin
 *
 * WP-Admin subpage for messaging administration.
 *
 * CAPABILITIES:
 *   - manage_options OR zdz_admin role required for every surface here.
 *   - Per-action nonces (not a single page-wide nonce).
 *
 * SURFACES:
 *   Settings → Messaging
 *     └─ Channels list (slug, name, member count, type)
 *        └─ Channel detail: members + add/remove
 *     └─ Audit export (CSV)
 *
 * NOTE: the plugin's main UI lives in the inline dashboard widget, NOT here.
 * This admin page is for sysadmin-class operations the widget doesn't expose:
 *   - Bulk member management (drag-select is v1.1)
 *   - Audit log export
 *   - Viewing a channel without being a member (admin-only, read-only)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIM_Admin {

	const MENU_SLUG = 'zim-messaging';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_tsim_admin_create_channel', array( $this, 'handle_create_channel' ) );
		add_action( 'admin_post_tsim_admin_add_member',     array( $this, 'handle_add_member' ) );
		add_action( 'admin_post_tsim_admin_remove_member',  array( $this, 'handle_remove_member' ) );
		add_action( 'admin_post_tsim_admin_export_audit',   array( $this, 'handle_export_audit' ) );
		add_action( 'admin_post_tsim_admin_reset_email_cooldown', array( $this, 'handle_reset_email_cooldown' ) );
	}

	public function register_menu() {
		add_menu_page(
			__( 'Messaging', 'zdz-internal-messaging' ),
			__( 'Messaging', 'zdz-internal-messaging' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_channels_page' ),
			'dashicons-format-chat',
			58
		);
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Channels', 'zdz-internal-messaging' ),
			__( 'Channels', 'zdz-internal-messaging' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_channels_page' )
		);
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Audit log', 'zdz-internal-messaging' ),
			__( 'Audit log', 'zdz-internal-messaging' ),
			'manage_options',
			self::MENU_SLUG . '-audit',
			array( $this, 'render_audit_page' )
		);
	}

	// ─────────────────────────────────────────────────────────────
	// Channel list / detail
	// ─────────────────────────────────────────────────────────────

	public function render_channels_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'zdz-internal-messaging' ) );
		}

		$detail_id = isset( $_GET['conversation_id'] ) ? absint( $_GET['conversation_id'] ) : 0;
		if ( $detail_id > 0 ) {
			$this->render_channel_detail( $detail_id );
			return;
		}

		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT c.id, c.slug, c.name, c.is_private, c.is_announcements, c.created_at,
			        (SELECT COUNT(*) FROM {$wpdb->prefix}zim_members m WHERE m.conversation_id = c.id) AS member_count
			   FROM {$wpdb->prefix}zim_conversations c
			  WHERE c.kind = 'channel'
			  ORDER BY c.is_announcements DESC, c.name ASC",
			ARRAY_A
		);

		$notice = isset( $_GET['zim_notice'] ) ? sanitize_text_field( $_GET['zim_notice'] ) : '';

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Messaging — Channels', 'zdz-internal-messaging' ); ?></h1>

			<?php if ( $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<div class="card" style="max-width:none;margin:1em 0;padding:.5em 1em;">
				<h2 style="margin-top:.6em;"><?php esc_html_e( 'DM email testing', 'zdz-internal-messaging' ); ?></h2>
				<p style="max-width:60em;">
					<?php esc_html_e( 'DM alert emails are throttled to once every 30 minutes per conversation so a rapid back-and-forth doesn\'t flood the recipient\'s inbox. When you\'re deliberately sending repeated test DMs, that throttle holds the follow-up emails back. Click below to clear the throttle so the next DM emails the recipient right away.', 'zdz-internal-messaging' ); ?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="zim_admin_reset_email_cooldown">
					<?php wp_nonce_field( 'zim_admin_reset_email_cooldown', '_tsim_nonce' ); ?>
					<?php submit_button( __( 'Reset DM email cooldown', 'zdz-internal-messaging' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>

			<h2><?php esc_html_e( 'Create channel', 'zdz-internal-messaging' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:1.5em;">
				<input type="hidden" name="action" value="zim_admin_create_channel">
				<?php wp_nonce_field( 'zim_admin_create_channel', '_tsim_nonce' ); ?>
				<table class="form-table"><tbody>
				<tr>
					<th scope="row"><label for="zim-slug"><?php esc_html_e( 'Slug', 'zdz-internal-messaging' ); ?></label></th>
					<td><input name="slug" id="zim-slug" type="text" class="regular-text" placeholder="q4-planning" required></td>
				</tr>
				<tr>
					<th scope="row"><label for="zim-desc"><?php esc_html_e( 'Description', 'zdz-internal-messaging' ); ?></label></th>
					<td><input name="description" id="zim-desc" type="text" class="regular-text"></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Private', 'zdz-internal-messaging' ); ?></th>
					<td><label><input name="is_private" type="checkbox" value="1"> <?php esc_html_e( 'Invite-only', 'zdz-internal-messaging' ); ?></label></td>
				</tr>
				</tbody></table>
				<?php submit_button( __( 'Create channel', 'zdz-internal-messaging' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Existing channels', 'zdz-internal-messaging' ); ?></h2>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Slug', 'zdz-internal-messaging' ); ?></th>
					<th><?php esc_html_e( 'Name', 'zdz-internal-messaging' ); ?></th>
					<th><?php esc_html_e( 'Type', 'zdz-internal-messaging' ); ?></th>
					<th><?php esc_html_e( 'Members', 'zdz-internal-messaging' ); ?></th>
					<th><?php esc_html_e( 'Created', 'zdz-internal-messaging' ); ?></th>
					<th></th>
				</tr></thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="6"><em><?php esc_html_e( 'No channels yet.', 'zdz-internal-messaging' ); ?></em></td></tr>
				<?php else : foreach ( $rows as $r ) :
					$badges = array();
					if ( $r['is_announcements'] ) { $badges[] = 'announcements'; }
					if ( $r['is_private'] )       { $badges[] = 'private'; }
					$type = $badges ? implode( ', ', $badges ) : 'public';
					$detail_url = add_query_arg( array(
						'page' => self::MENU_SLUG,
						'conversation_id' => (int) $r['id'],
					), admin_url( 'admin.php' ) );
				?>
					<tr>
						<td><code>#<?php echo esc_html( $r['slug'] ); ?></code></td>
						<td><?php echo esc_html( $r['name'] ); ?></td>
						<td><?php echo esc_html( $type ); ?></td>
						<td><?php echo (int) $r['member_count']; ?></td>
						<td><?php echo esc_html( $r['created_at'] ); ?></td>
						<td><a class="button" href="<?php echo esc_url( $detail_url ); ?>"><?php esc_html_e( 'Manage', 'zdz-internal-messaging' ); ?></a></td>
					</tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function render_channel_detail( $conversation_id ) {
		global $wpdb;
		$conv = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, kind, slug, name, is_private, is_announcements
			   FROM {$wpdb->prefix}zim_conversations WHERE id = %d",
			$conversation_id
		) );
		if ( ! $conv || 'channel' !== $conv->kind ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Channel not found.', 'zdz-internal-messaging' ) . '</h1></div>';
			return;
		}

		$members = ZIM_Channels::list_members( $conversation_id );

		$back_url = add_query_arg( array( 'page' => self::MENU_SLUG ), admin_url( 'admin.php' ) );
		?>
		<div class="wrap">
			<h1>
				<?php echo esc_html( $conv->name ); ?>
				<a class="page-title-action" href="<?php echo esc_url( $back_url ); ?>">← <?php esc_html_e( 'Back', 'zdz-internal-messaging' ); ?></a>
			</h1>

			<h2><?php esc_html_e( 'Members', 'zdz-internal-messaging' ); ?> (<?php echo count( $members ); ?>)</h2>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'User', 'zdz-internal-messaging' ); ?></th>
					<th><?php esc_html_e( 'Login', 'zdz-internal-messaging' ); ?></th>
					<th><?php esc_html_e( 'Role', 'zdz-internal-messaging' ); ?></th>
					<th><?php esc_html_e( 'Joined', 'zdz-internal-messaging' ); ?></th>
					<th></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $members as $m ) : ?>
					<tr>
						<td><?php echo esc_html( $m['display_name'] ); ?></td>
						<td><code><?php echo esc_html( $m['user_login'] ); ?></code></td>
						<td><?php echo esc_html( $m['role'] ); ?></td>
						<td><?php echo esc_html( $m['joined_at'] ); ?></td>
						<td>
							<?php if ( empty( $conv->is_announcements ) ) :
								// Don't allow removing admins from #announcements via this UI.
							?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
								<input type="hidden" name="action" value="zim_admin_remove_member">
								<input type="hidden" name="conversation_id" value="<?php echo (int) $conversation_id; ?>">
								<input type="hidden" name="user_id" value="<?php echo (int) $m['user_id']; ?>">
								<?php wp_nonce_field( 'zim_admin_remove_member_' . $conversation_id . '_' . $m['user_id'], '_tsim_nonce' ); ?>
								<button type="submit" class="button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Remove this member?', 'zdz-internal-messaging' ) ); ?>');">
									<?php esc_html_e( 'Remove', 'zdz-internal-messaging' ); ?>
								</button>
							</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2 style="margin-top:2em;"><?php esc_html_e( 'Add member', 'zdz-internal-messaging' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="zim_admin_add_member">
				<input type="hidden" name="conversation_id" value="<?php echo (int) $conversation_id; ?>">
				<?php wp_nonce_field( 'zim_admin_add_member_' . $conversation_id, '_tsim_nonce' ); ?>
				<?php
				$candidates = get_users( array(
					'fields' => array( 'ID', 'user_login', 'display_name' ),
					'number' => 200,
				) );
				?>
				<select name="user_id" required style="min-width:22em;">
					<option value=""><?php esc_html_e( '— select a user —', 'zdz-internal-messaging' ); ?></option>
					<?php foreach ( $candidates as $u ) : ?>
						<option value="<?php echo (int) $u->ID; ?>"><?php echo esc_html( $u->display_name ); ?> (<?php echo esc_html( $u->user_login ); ?>)</option>
					<?php endforeach; ?>
				</select>
				<select name="role">
					<option value="member"><?php esc_html_e( 'Member', 'zdz-internal-messaging' ); ?></option>
					<option value="admin"><?php esc_html_e( 'Channel admin', 'zdz-internal-messaging' ); ?></option>
				</select>
				<?php submit_button( __( 'Add', 'zdz-internal-messaging' ), 'primary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	// ─────────────────────────────────────────────────────────────
	// Audit page
	// ─────────────────────────────────────────────────────────────

	public function render_audit_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'zdz-internal-messaging' ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Messaging — Audit log export', 'zdz-internal-messaging' ); ?></h1>
			<p><?php esc_html_e( 'Exports admin actions (channel creation, member changes, force-deletes) as CSV. Regular message traffic is not logged (intentional — too voluminous to be useful).', 'zdz-internal-messaging' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="zim_admin_export_audit">
				<?php wp_nonce_field( 'zim_admin_export_audit', '_tsim_nonce' ); ?>
				<?php submit_button( __( 'Download CSV', 'zdz-internal-messaging' ) ); ?>
			</form>
			<?php if ( ! class_exists( 'ZDZ_Admin_Dashboard' ) ) : ?>
				<p><em><?php esc_html_e( 'The theme audit log is not available in this TS theme version. Export will be empty.', 'zdz-internal-messaging' ); ?></em></p>
			<?php endif; ?>
		</div>
		<?php
	}

	// ─────────────────────────────────────────────────────────────
	// admin-post handlers
	// ─────────────────────────────────────────────────────────────

	public function handle_create_channel() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'zdz-internal-messaging' ) );
		}
		check_admin_referer( 'zim_admin_create_channel', '_tsim_nonce' );

		$slug = sanitize_title( wp_unslash( $_POST['slug'] ?? '' ) );
		if ( '' === $slug ) {
			wp_safe_redirect( $this->back_url( 'Slug is required.' ) );
			exit;
		}
		$id = ZIM_Channels::create( array(
			'slug'        => $slug,
			'name'        => '#' . $slug,
			'description' => sanitize_text_field( wp_unslash( $_POST['description'] ?? '' ) ),
			'is_private'  => ! empty( $_POST['is_private'] ) ? 1 : 0,
			'created_by'  => get_current_user_id(),
		) );
		if ( $id <= 0 ) {
			wp_safe_redirect( $this->back_url( 'Channel could not be created (duplicate slug?).' ) );
			exit;
		}
		wp_safe_redirect( $this->back_url( 'Channel created.' ) );
		exit;
	}

	public function handle_add_member() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'zdz-internal-messaging' ) );
		}
		$conv_id = isset( $_POST['conversation_id'] ) ? absint( $_POST['conversation_id'] ) : 0;
		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		$role    = ( ( $_POST['role'] ?? 'member' ) === 'admin' ) ? 'admin' : 'member';
		check_admin_referer( 'zim_admin_add_member_' . $conv_id, '_tsim_nonce' );

		if ( $conv_id <= 0 || $user_id <= 0 ) {
			wp_safe_redirect( $this->back_url( 'Missing conversation or user.', $conv_id ) );
			exit;
		}
		if ( ! zim_user_has_access( $user_id ) ) {
			wp_safe_redirect( $this->back_url( 'User lacks zdz_access_app capability.', $conv_id ) );
			exit;
		}
		ZIM_Channels::add_member( $conv_id, $user_id, $role );
		wp_safe_redirect( $this->back_url( 'Member added.', $conv_id ) );
		exit;
	}

	public function handle_remove_member() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'zdz-internal-messaging' ) );
		}
		$conv_id = isset( $_POST['conversation_id'] ) ? absint( $_POST['conversation_id'] ) : 0;
		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		check_admin_referer( 'zim_admin_remove_member_' . $conv_id . '_' . $user_id, '_tsim_nonce' );

		if ( $conv_id > 0 && $user_id > 0 ) {
			ZIM_Channels::remove_member( $conv_id, $user_id );
		}
		wp_safe_redirect( $this->back_url( 'Member removed.', $conv_id ) );
		exit;
	}

	public function handle_export_audit() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'zdz-internal-messaging' ) );
		}
		check_admin_referer( 'zim_admin_export_audit', '_tsim_nonce' );

		// Query wp_zdz_audit_log directly, filtered to our app_id. The theme
		// (as of 2.13.1) does not expose a public reader method — the class
		// writes via log_action() and reads via its own REST endpoint. For
		// a simple CSV we'd rather not go through REST, so we hit the table.
		$entries = array();
		global $wpdb;
		$table = $wpdb->prefix . 'zdz_audit_log';
		// Sanity-check the table exists before trying to read from it.
		if ( class_exists( 'ZDZ_Admin_Dashboard' ) && (int) $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		) !== 0 ) {
			// Row count check is cheap; skip the SELECT when empty.
			$entries = $wpdb->get_results( $wpdb->prepare(
				"SELECT created_at, user_id, action_type, action_detail, meta_json
				   FROM {$table}
				  WHERE app_id = %s
				  ORDER BY created_at DESC
				  LIMIT 10000",
				'zdz-internal-messaging'
			), ARRAY_A );
			if ( ! is_array( $entries ) ) {
				$entries = array();
			}
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=zim-audit-' . gmdate( 'Ymd-His' ) . '.csv' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'timestamp_utc', 'user_id', 'action', 'detail', 'meta_json' ) );
		foreach ( $entries as $e ) {
			fputcsv( $out, array(
				(string) ( $e['created_at']    ?? '' ),
				(int)    ( $e['user_id']       ?? 0  ),
				(string) ( $e['action_type']   ?? '' ),
				(string) ( $e['action_detail'] ?? '' ),
				(string) ( $e['meta_json']     ?? '' ),
			) );
		}
		fclose( $out );
		exit;
	}

	/**
	 * v1.1.3 — clear the per-(user, conversation) DM-email cooldown stamps so the
	 * next DM emails the recipient immediately. This is the "let me test the
	 * round-trip now" button: the 30-minute throttle is correct in production but
	 * gets in the way when you're deliberately sending repeated test DMs.
	 *
	 * Deletes every zim_last_email_convo_* user_meta row (all users). Admin-only,
	 * nonce-protected. Purely a reset — it sends nothing itself.
	 */
	public function handle_reset_email_cooldown() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'zdz-internal-messaging' ) );
		}
		check_admin_referer( 'zim_admin_reset_email_cooldown', '_tsim_nonce' );

		global $wpdb;
		// META_LAST_EMAIL_PREFIX = 'zim_last_email_convo_'
		$prefix  = ZIM_Notifications::META_LAST_EMAIL_PREFIX;
		$deleted = (int) $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
			$wpdb->esc_like( $prefix ) . '%'
		) );

		if ( function_exists( 'wp_cache_flush' ) ) {
			// user_meta is cached per-user; blow it away so the cleared stamps
			// don't linger in object cache for the rest of the request cycle.
			wp_cache_flush();
		}

		wp_safe_redirect( $this->back_url(
			sprintf(
				/* translators: %d = number of cooldown stamps cleared */
				__( 'DM email cooldown reset — cleared %d stamp(s). The next DM will email the recipient immediately.', 'zdz-internal-messaging' ),
				$deleted
			)
		) );
		exit;
	}

	private function back_url( $notice = '', $conversation_id = 0 ) {
		$args = array( 'page' => self::MENU_SLUG );
		if ( $conversation_id > 0 ) {
			$args['conversation_id'] = (int) $conversation_id;
		}
		if ( $notice ) {
			$args['zim_notice'] = $notice;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}
}
