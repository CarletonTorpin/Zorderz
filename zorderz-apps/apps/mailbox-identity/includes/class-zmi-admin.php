<?php
/**
 * ZMI_Admin — the backend surface for the mailbox identity.
 *
 *   • On the WP user-profile screen (admin editing an account): "Exchange mailbox & aliases"
 *     — primary mailbox (UPN), aliases (CSV), and a "Pull from Microsoft" button. Seeds from
 *     the legacy stores on first view so nothing is lost. All writes go through
 *     ZMI_Store::save_identity() (validated + collision-guarded).
 *   • Settings → Zorderz Mailbox Identity: internal-domain fallback, the service-mailbox
 *     reader roles, the service-mailbox registry, feature flags, and a "seed all from
 *     legacy" action.
 *
 * Admin-only throughout (edit_users / manage_options). Nothing here reads mail.
 *
 * @package Zorderz\Mailbox_Identity
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZMI_Admin {

	const NOTICE_KEY = 'zmi_notice';

	public static function register(): void {
		add_action( 'show_user_profile', array( __CLASS__, 'render_profile' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_profile' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_profile' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_profile' ) );

		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_zmi_pull', array( __CLASS__, 'handle_pull' ) );
		add_action( 'admin_post_zmi_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_zmi_seed_all', array( __CLASS__, 'handle_seed_all' ) );
		add_action( 'admin_post_zmi_import', array( __CLASS__, 'handle_import' ) );

		add_action( 'admin_notices', array( __CLASS__, 'flash' ) );
	}

	// ── profile screen ──────────────────────────────────────────────

	public static function render_profile( $user ): void {
		if ( ! current_user_can( 'edit_users' ) || ! ( $user instanceof WP_User ) ) {
			return;
		}
		ZMI_Store::seed_from_legacy( (int) $user->ID ); // idempotent; never clobbers an existing identity
		$id      = ZMI_Store::identity( (int) $user->ID );
		$aliases = implode( ', ', $id['aliases'] );
		$pull_on = ZMI_Store::flag( 'autofill' ); // gated: only offer Pull when auto-fill is switched on
		?>
		<h2>Exchange mailbox &amp; aliases</h2>
		<p class="description">The addresses that <strong>are</strong> this person. Read by the Scheduler (calendar), the Inbox (mail attribution), and login, so mail/invites to any of these map to this one account. Comma-separate aliases.</p>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="zmi_upn">Primary mailbox (UPN)</label></th>
				<td>
					<input type="text" name="zmi_upn" id="zmi_upn" class="regular-text"
						value="<?php echo esc_attr( $id['upn'] ); ?>"
						placeholder="<?php echo esc_attr( $user->user_email ); ?>" />
					<p class="description">Defaults to the WP account email if left blank.</p>
				</td>
			</tr>
			<tr>
				<th><label for="zmi_aliases">Aliases</label></th>
				<td>
					<textarea name="zmi_aliases" id="zmi_aliases" class="large-text" rows="2"><?php echo esc_textarea( $aliases ); ?></textarea>
					<p class="description">Other addresses that land in this mailbox (proxy aliases, a personal alias, etc.). One account per address — a duplicate is rejected and names the current owner.</p>
				</td>
			</tr>
			<?php wp_nonce_field( 'zmi_save_profile_' . (int) $user->ID, 'zmi_profile_nonce' ); ?>
		</table>
		<?php if ( $pull_on ) : ?>
			<p>
				<a class="button"
					href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'zmi_pull', 'user_id' => (int) $user->ID ), admin_url( 'admin-post.php' ) ), 'zmi_pull_' . (int) $user->ID ) ); ?>">
					Pull aliases from Microsoft
				</a>
				<span class="description">Reads this mailbox's proxy addresses from Microsoft 365 and merges them in.</span>
			</p>
		<?php endif; ?>
		<?php
	}

	public static function save_profile( $user_id ): void {
		$user_id = (int) $user_id;
		if ( ! current_user_can( 'edit_users' ) ) {
			return;
		}
		if ( ! isset( $_POST['zmi_profile_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['zmi_profile_nonce'] ) ), 'zmi_save_profile_' . $user_id ) ) {
			return;
		}
		$upn     = isset( $_POST['zmi_upn'] ) ? sanitize_text_field( wp_unslash( $_POST['zmi_upn'] ) ) : '';
		$aliases = self::split_csv( isset( $_POST['zmi_aliases'] ) ? sanitize_textarea_field( wp_unslash( $_POST['zmi_aliases'] ) ) : '' );

		$res = ZMI_Store::save_identity( $user_id, $upn, $aliases );
		self::note( is_wp_error( $res ) ? $res->get_error_message() : 'Exchange identity saved.', is_wp_error( $res ) ? 'error' : 'success' );
	}

	public static function handle_pull(): void {
		if ( ! current_user_can( 'edit_users' ) ) {
			wp_die( 'Denied.' );
		}
		$user_id = (int) ( $_GET['user_id'] ?? 0 );
		check_admin_referer( 'zmi_pull_' . $user_id );

		$mailbox = ZDZ_Mailbox_Identity::mailbox_for_user( $user_id );
		$res     = ( '' !== $mailbox ) ? ZMI_Graph_Fill::fetch( $mailbox ) : new WP_Error( 'zmi_pull_nombx', 'No mailbox on file for this user.' );

		if ( is_wp_error( $res ) ) {
			self::note( $res->get_error_message(), 'error' );
		} else {
			$cur     = ZMI_Store::identity( $user_id );
			$merged  = array_merge( $cur['aliases'], $res['aliases'] );
			$save    = ZMI_Store::save_identity(
				$user_id,
				'' !== $res['upn'] ? $res['upn'] : $cur['upn'],
				$merged,
				array( 'oid' => $res['oid'] ?: $cur['oid'] )
			);
			self::note(
				is_wp_error( $save ) ? $save->get_error_message() : sprintf( 'Pulled %d alias(es) from Microsoft.', count( $res['aliases'] ) ),
				is_wp_error( $save ) ? 'error' : 'success'
			);
		}
		wp_safe_redirect( get_edit_user_link( $user_id ) ?: admin_url() );
		exit;
	}

	// ── settings page ───────────────────────────────────────────────

	public static function menu(): void {
		add_options_page( 'Zorderz Mailbox Identity', 'Zorderz Mailbox Identity', 'manage_options', 'zmi-settings', array( __CLASS__, 'render_settings' ) );
	}

	public static function render_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$domains  = implode( ', ', ZMI_Store::internal_domains_fallback() );
		$readers  = ZMI_Store::service_mailbox_reader_roles();
		$services = ZMI_Store::service_mailboxes();
		$autofill = ZMI_Store::flag( 'autofill' );
		$svc_text = '';
		foreach ( $services as $s ) {
			$svc_text .= $s['address'] . ' | ' . $s['index_mode'] . ' | ' . ( $s['automated'] ? 'automated' : 'human' ) . ' | ' . (int) $s['owner_service_user'] . "\n";
		}
		if ( null === get_option( ZMI_Store::OPT_SERVICE, null ) ) {
			$svc_text = ZMI_Store::default_service_text(); // pre-seed until first save (ships empty)
		}
		$bp_domain = ( class_exists( 'ZDZ_Business_Profile' ) && method_exists( 'ZDZ_Business_Profile', 'get' ) )
			? strtolower( trim( (string) ZDZ_Business_Profile::get( 'web.app_domain', '' ) ) )
			: '';
		?>
		<div class="wrap">
			<h1>Zorderz Mailbox Identity</h1>
			<p class="description">One per-account address book for Microsoft Exchange. This screen sets policy; per-person mailboxes &amp; aliases live on each user's profile.</p>

			<h2>Consumer wiring</h2>
			<p>Resolver available: <strong><?php echo class_exists( 'ZDZ_Mailbox_Identity' ) ? 'yes' : 'no'; ?></strong>.
			The Scheduler, the Inbox, and login each read this resolver behind a <code>class_exists()</code> guard, adopted independently — until a consumer reads it, this module only provides the API and this admin surface. Nothing here reads mail.</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="zmi_save_settings" />
				<?php wp_nonce_field( 'zmi_save_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="zmi_domains">Internal domains (fallback)</label></th>
						<td>
							<input type="text" name="zmi_domains" id="zmi_domains" class="regular-text" value="<?php echo esc_attr( $domains ); ?>" placeholder="example.com" />
							<p class="description">Comma-separated; used first. When left blank, falls back to the Business Profile's app domain<?php echo ( '' !== $bp_domain ) ? ' (currently <code>' . esc_html( $bp_domain ) . '</code>)' : ' when the theme provides one'; ?>, else no domain is treated as internal.</p>
						</td>
					</tr>
					<tr>
						<th>Service-mailbox readers</th>
						<td>
							<?php foreach ( get_editable_roles() as $slug => $role ) : ?>
								<label style="display:inline-block;min-width:220px;">
									<input type="checkbox" name="zmi_readers[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $readers, true ) ); ?> />
									<?php echo esc_html( $role['name'] ); ?>
								</label>
							<?php endforeach; ?>
							<p class="description">Roles permitted to read a shared service mailbox. Company mail, not owner-private — default administrators only.</p>
						</td>
					</tr>
					<tr>
						<th><label for="zmi_services">Service / shared mailboxes</label></th>
						<td>
							<textarea name="zmi_services" id="zmi_services" class="large-text code" rows="4" placeholder="help@example.com | all | automated | 0"><?php echo esc_textarea( trim( $svc_text ) ); ?></textarea>
							<p class="description">One per line: <code>address | index_mode(none|external|all) | automated|human | owner_service_user_id</code>. These are internal, never-a-person addresses. Ships empty; indexing them is a later, separately flagged step — here you only register them.</p>
						</td>
					</tr>
					<tr>
						<th>Auto-fill</th>
						<td><label><input type="checkbox" name="zmi_autofill" value="1" <?php checked( $autofill ); ?> /> Show "Pull aliases from Microsoft" on profiles</label>
						<p class="description">Needs the Scheduler's Microsoft app to have <code>User.Read.All</code> (application) with admin consent. Off = hand-entry only.</p></td>
					</tr>
				</table>
				<?php submit_button( 'Save settings' ); ?>
			</form>

			<hr />
			<h2>Import roster — Exchange mailboxes &amp; aliases</h2>
			<p class="description">One person per line: <code>Display Name | primary mailbox | alias(es)</code>. <strong>Preview</strong> matches each line to a WP user by email (then exact display name); <strong>Import</strong> writes identities for confident matches only — unmatched lines are reported, never guessed. Shared/service boxes (a support inbox, a sales inbox…) go in the registry above, not here.</p>
			<?php
			$pv_key = 'zmi_preview_' . get_current_user_id();
			$pv     = get_transient( $pv_key );
			if ( $pv ) {
				delete_transient( $pv_key );
				self::render_preview( (array) $pv );
			}
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="zmi_import" />
				<?php wp_nonce_field( 'zmi_import' ); ?>
				<textarea name="zmi_roster" class="large-text code" rows="14"><?php echo esc_textarea( (string) get_option( 'zmi_roster_text', ZMI_Import::default_roster() ) ); ?></textarea>
				<p>
					<button type="submit" name="mode" value="preview" class="button">Preview matches</button>
					<button type="submit" name="mode" value="apply" class="button button-primary">Import / apply matched</button>
				</p>
			</form>

			<hr />
			<h2>Seed from legacy</h2>
			<p>Backfill every user's identity from the existing <code>zsch_mailbox</code> + <code>zdz_login_aliases</code> metas. Idempotent — it never overwrites an identity that's already set.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="zmi_seed_all" />
				<?php wp_nonce_field( 'zmi_seed_all' ); ?>
				<?php submit_button( 'Seed all users from legacy', 'secondary' ); ?>
			</form>
		</div>
		<?php
	}

	public static function save_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Denied.' );
		}
		check_admin_referer( 'zmi_save_settings' );

		update_option( ZMI_Store::OPT_DOMAINS, sanitize_text_field( wp_unslash( $_POST['zmi_domains'] ?? '' ) ) );

		$readers = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['zmi_readers'] ?? array() ) );
		update_option( ZMI_Store::OPT_READER_ROLES, $readers ?: array( 'administrator' ) );

		ZMI_Store::set_service_mailboxes( self::parse_services( sanitize_textarea_field( wp_unslash( $_POST['zmi_services'] ?? '' ) ) ) );
		ZMI_Store::set_flag( 'autofill', ! empty( $_POST['zmi_autofill'] ) );

		self::note( 'Settings saved.', 'success' );
		wp_safe_redirect( add_query_arg( 'page', 'zmi-settings', admin_url( 'options-general.php' ) ) );
		exit;
	}

	public static function handle_seed_all(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Denied.' );
		}
		check_admin_referer( 'zmi_seed_all' );
		$n = 0;
		foreach ( get_users( array( 'fields' => array( 'ID' ) ) ) as $u ) {
			ZMI_Store::seed_from_legacy( (int) $u->ID );
			$n++;
		}
		self::note( sprintf( 'Seeded %d user(s) from legacy metadata.', $n ), 'success' );
		wp_safe_redirect( add_query_arg( 'page', 'zmi-settings', admin_url( 'options-general.php' ) ) );
		exit;
	}

	/** Roster importer: Preview (dry-run) or Apply (write matched identities). */
	public static function handle_import(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Denied.' );
		}
		check_admin_referer( 'zmi_import' );
		$text = sanitize_textarea_field( (string) wp_unslash( $_POST['zmi_roster'] ?? '' ) );
		update_option( 'zmi_roster_text', $text );
		$mode = sanitize_text_field( wp_unslash( $_POST['mode'] ?? 'preview' ) );

		if ( 'apply' === $mode ) {
			$r   = ZMI_Import::apply( $text );
			$msg = sprintf( 'Imported %d identity(ies); skipped %d unmatched.', (int) $r['applied'], (int) $r['skipped'] );
			if ( ! empty( $r['errors'] ) ) {
				$msg .= ' Issues: ' . implode( '; ', array_map( 'strval', $r['errors'] ) );
			}
			self::note( $msg, empty( $r['errors'] ) ? 'success' : 'error' );
		} else {
			set_transient( 'zmi_preview_' . get_current_user_id(), ZMI_Import::preview( $text ), 180 );
		}
		wp_safe_redirect( add_query_arg( 'page', 'zmi-settings', admin_url( 'options-general.php' ) ) );
		exit;
	}

	/** Render the dry-run preview table. */
	private static function render_preview( array $preview ): void {
		echo '<table class="widefat striped" style="max-width:1040px;margin:12px 0;"><thead><tr><th>Name</th><th>Primary (Exchange mailbox)</th><th>Alias(es)</th><th>Matched WP user</th><th>Matched by</th></tr></thead><tbody>';
		foreach ( $preview as $r ) {
			$ok   = (int) ( $r['uid'] ?? 0 ) > 0;
			$cell = $ok ? esc_html( (string) $r['user'] ) : '<span style="color:#a00;">— no match —</span>';
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( (string) ( $r['name'] ?? '' ) ),
				esc_html( (string) ( $r['primary'] ?? '' ) ),
				esc_html( implode( ', ', (array) ( $r['aliases'] ?? array() ) ) ),
				$cell, // already escaped / literal markup
				esc_html( (string) ( $r['by'] ?? '' ) )
			);
		}
		echo '</tbody></table><p class="description">Only rows with a matched WP user are written on Import.</p>';
	}

	// ── helpers ─────────────────────────────────────────────────────

	/** Parse the service-mailbox textarea (address | index_mode | automated|human | owner_id). */
	private static function parse_services( string $text ): array {
		$rows = array();
		foreach ( preg_split( '/\r?\n/', $text ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$parts = array_map( 'trim', explode( '|', $line ) );
			$rows[] = array(
				'address'            => $parts[0] ?? '',
				'index_mode'         => $parts[1] ?? 'all',
				'automated'          => isset( $parts[2] ) ? ( 'human' !== strtolower( $parts[2] ) ? 1 : 0 ) : 1,
				'owner_service_user' => isset( $parts[3] ) ? (int) $parts[3] : 0,
			);
		}
		return $rows;
	}

	private static function split_csv( string $csv ): array {
		return array_filter( array_map( 'trim', preg_split( '/[\s,]+/', $csv ) ) );
	}

	private static function note( string $msg, string $type ): void {
		set_transient( self::NOTICE_KEY . '_' . get_current_user_id(), array( 'msg' => $msg, 'type' => $type ), 60 );
	}

	public static function flash(): void {
		$key = self::NOTICE_KEY . '_' . get_current_user_id();
		$n   = get_transient( $key );
		if ( ! $n ) {
			return;
		}
		delete_transient( $key );
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( 'error' === $n['type'] ? 'error' : 'success' ),
			esc_html( (string) $n['msg'] )
		);
	}
}
