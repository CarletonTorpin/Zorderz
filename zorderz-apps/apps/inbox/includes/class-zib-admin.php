<?php
/**
 * ZIB_Admin — Settings → Zorderz Inbox (admin only).
 *
 * Where an admin pastes the Entra delegated-app credentials (tenant id, client
 * id, client secret) and flips the master feature flag. The secret is
 * write-only from here: the field shows only whether one is on file, never the
 * value. Also shows the exact redirect URI to register in Entra, and a
 * STATUS-ONLY roster (who has connected, their mode, whether they opted into
 * admin search) — never anyone's mail.
 *
 * @since 0.1.0 (P0 — Connect)
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIB_Admin {

	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_zib_save_settings', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_zib_toggle_read', array( __CLASS__, 'handle_toggle_read' ) );
	}

	public static function menu(): void {
		add_options_page( 'Zorderz Inbox', 'Zorderz Inbox', 'manage_options', 'zib-settings', array( __CLASS__, 'render' ) );
	}

	/**
	 * Admin flips a staff mailbox's read gate (the "admin controls the gate" control).
	 * manage_options only; nonce-checked; delegates to the Gatekeeper (which logs it), then
	 * returns to the settings page focused on that mailbox.
	 */
	public static function handle_toggle_read(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Denied.' );
		}
		check_admin_referer( 'zib_toggle_read' );
		$subject = absint( $_POST['owner_user_id'] ?? 0 );
		$enable  = isset( $_POST['enable'] ) && '1' === (string) wp_unslash( $_POST['enable'] );
		if ( $subject > 0 && class_exists( 'ZIB_Gatekeeper' ) ) {
			ZIB_Gatekeeper::admin_set_read( get_current_user_id(), $subject, $enable );
		}
		wp_safe_redirect( add_query_arg(
			array( 'page' => 'zib-settings', 'zib_read_uid' => $subject, 'gate' => $enable ? 'on' : 'off' ),
			admin_url( 'options-general.php' )
		) );
		exit;
	}

	/**
	 * The admin's read panel: when ?zib_read_uid=<staff user> is set, runs an alias-aware
	 * admin_search (optionally filtered by ?zib_read_q) through the Gatekeeper and prints the
	 * results (SUMMARIES only — every hit is logged). The Gatekeeper enforces the per-mailbox
	 * gate, so a mailbox whose gate is off shows a notice here, never its mail.
	 */
	public static function render_read_panel(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$uid = absint( $_GET['zib_read_uid'] ?? 0 );
		if ( $uid <= 0 ) {
			return;
		}
		$q   = isset( $_GET['zib_read_q'] ) ? sanitize_text_field( wp_unslash( $_GET['zib_read_q'] ) ) : '';
		$u   = get_userdata( $uid );
		$who = $u ? $u->display_name : ( 'user #' . $uid );
		$res = class_exists( 'ZIB_Gatekeeper' )
			? ZIB_Gatekeeper::admin_search( get_current_user_id(), $uid, $q, 40, 0 )
			: array( 'ok' => false, 'results' => array() );
		echo '<hr /><h3>Reading: ' . esc_html( $who ) . '</h3>';
		if ( empty( $res['ok'] ) ) {
			echo '<div class="notice notice-warning inline"><p>This mailbox is not open to admin read. Turn its gate <strong>On</strong> in the table above first.</p></div>';
			return;
		}
		echo '<form method="get" action="' . esc_url( admin_url( 'options-general.php' ) ) . '" style="margin:8px 0;">';
		echo '<input type="hidden" name="page" value="zib-settings" />';
		echo '<input type="hidden" name="zib_read_uid" value="' . esc_attr( $uid ) . '" />';
		echo '<input type="search" name="zib_read_q" value="' . esc_attr( $q ) . '" class="regular-text" placeholder="Search this mailbox (blank = most recent)" /> ';
		submit_button( 'Search', 'secondary', '', false );
		echo '</form>';
		$rows = (array) $res['results'];
		if ( empty( $rows ) ) {
			echo '<p><em>No messages' . ( '' !== $q ? ' match that search' : '' ) . '.</em></p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>Date</th><th>Dir</th><th>From</th><th>Subject</th><th>Preview</th></tr></thead><tbody>';
		foreach ( $rows as $m ) {
			echo '<tr>';
			echo '<td>' . esc_html( (string) ( $m['received_at'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $m['direction'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $m['from'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $m['subject'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $m['snippet'] ?? '' ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">Every row shown here is recorded in the access log (actor, mailbox, time). Summaries only.</p>';
	}

	public static function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Denied.' );
		}
		check_admin_referer( 'zib_save_settings' );

		ZIB_Settings::update_config( array(
			'tenant_id' => sanitize_text_field( wp_unslash( $_POST['tenant_id'] ?? '' ) ),
			'client_id' => sanitize_text_field( wp_unslash( $_POST['client_id'] ?? '' ) ),
		) );

		if ( isset( $_POST['internal_domains'] ) ) {
			ZIB_Settings::set_internal_domains( (string) wp_unslash( $_POST['internal_domains'] ) );
		}

		// Secret: only overwrite when a non-empty value is submitted (blank =
		// leave unchanged). A literal "-" clears it.
		$secret = (string) wp_unslash( $_POST['client_secret'] ?? '' );
		if ( '-' === trim( $secret ) ) {
			ZIB_Settings::set_secret( '' );
		} elseif ( '' !== trim( $secret ) ) {
			ZIB_Settings::set_secret( trim( $secret ) );
		}

		update_option( ZIB_Settings::OPT_FLAG, isset( $_POST['zib_enabled'] ) ? 'yes' : 'no' );
		update_option( ZIB_Settings::OPT_INGEST, isset( $_POST['zib_ingest'] ) ? 'yes' : 'no' );
		update_option( ZIB_Settings::OPT_ENRICH, isset( $_POST['zib_enrich'] ) ? 'yes' : 'no' );

		wp_safe_redirect( add_query_arg( array( 'page' => 'zib-settings', 'updated' => '1' ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$cfg     = ZIB_Settings::config();
		$has_sec = ZIB_Settings::has_secret();
		$on      = ZIB_Settings::feature_enabled();
		$flag    = ( 'yes' === get_option( ZIB_Settings::OPT_FLAG, 'no' ) );
		$ingest  = ( 'yes' === get_option( ZIB_Settings::OPT_INGEST, 'no' ) );
		$enrich  = ( 'yes' === get_option( ZIB_Settings::OPT_ENRICH, 'no' ) );
		$redir   = ZIB_OAuth::redirect_uri();
		$domains = implode( ', ', ZIB_Settings::internal_domains() );
		$roster  = ZIB_Connections::roster();
		?>
		<div class="wrap">
			<h1>Zorderz Inbox — Connected Email (per-user M365)</h1>
			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
			<?php endif; ?>

			<p><strong>Status:</strong>
				<?php echo $on ? '<span style="color:#0a0">● Live</span>' : '<span style="color:#a00">● Off (dark)</span>'; ?>
				<?php echo $has_sec ? ' · secret on file' : ' · <em>no secret yet</em>'; ?>
			</p>

			<h2>Entra (Azure) delegated app</h2>
			<p>Register a <strong>single-tenant</strong> app with delegated permission <code>Mail.Read</code> (plus <code>User.Read</code>, <code>offline_access</code>, <code>openid</code>, <code>profile</code>, <code>email</code>). Add this <strong>exact</strong> redirect URI (Web platform):</p>
			<p><code style="user-select:all;background:#f6f7f7;padding:6px 10px;display:inline-block;"><?php echo esc_html( $redir ); ?></code></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="zib_save_settings" />
				<?php wp_nonce_field( 'zib_save_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="zib_tenant">Tenant ID</label></th>
						<td><input name="tenant_id" id="zib_tenant" type="text" class="regular-text" value="<?php echo esc_attr( $cfg['tenant_id'] ); ?>" placeholder="00000000-0000-0000-0000-000000000000" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="zib_client">Client ID</label></th>
						<td><input name="client_id" id="zib_client" type="text" class="regular-text" value="<?php echo esc_attr( $cfg['client_id'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="zib_secret">Client Secret</label></th>
						<td>
							<input name="client_secret" id="zib_secret" type="password" class="regular-text" autocomplete="new-password" placeholder="<?php echo $has_sec ? '•••••••• (unchanged)' : 'paste the secret VALUE (not the Secret ID)'; ?>" />
							<p class="description">Leave blank to keep the current secret. Enter <code>-</code> to clear it. Paste the secret <strong>Value</strong>, never the Secret <strong>ID</strong> (AADSTS7000215 is the wrong-one error).</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="zib_domains">Internal domains</label></th>
						<td><input name="internal_domains" id="zib_domains" type="text" class="regular-text" value="<?php echo esc_attr( $domains ); ?>" placeholder="example.com" />
						<p class="description">Comma-separated. Mail where <strong>every</strong> participant is on one of these domains (or a registered staff account) counts as employee↔employee; anything else is customer↔employee. Used by the classifier in P1.</p></td>
					</tr>
					<tr>
						<th scope="row">Feature flag</th>
						<td><label><input type="checkbox" name="zib_enabled" value="1" <?php checked( $flag ); ?> /> Enable Inbox (users can connect their mailbox)</label>
						<p class="description">Dark by default. With this off, no route, card, or REST endpoint responds and no mailbox is ever touched.</p></td>
					</tr>
					<tr>
						<th scope="row">Ingestion (P1)</th>
						<td><label><input type="checkbox" name="zib_ingest" value="1" <?php checked( $ingest ); ?> /> Start indexing connected mailboxes</label>
						<p class="description"><strong>Dark by default — this is the switch that reads real mail.</strong> With it OFF, nothing is indexed even when mailboxes are connected. Turning it ON starts the 12-month backfill + live sync for every connected mailbox, per each user's chosen index mode. Leave it off until you're ready for real mail to be pulled in.</p></td>
					</tr>
					<tr>
						<th scope="row">Enrichment (P6)</th>
						<td><label><input type="checkbox" name="zib_enrich" value="1" <?php checked( $enrich ); ?> /> Derive index cards, extracts &amp; tags from indexed mail</label>
						<p class="description"><strong>Dark by default.</strong> With it ON, each in-scope message is enriched — a cleaned/searchable body, extracted amounts / order refs / product quantities, and message tags — so the assistant can compute mailbox answers ("how many rolls have I ordered"). Runs on the sync cron in bounded batches and re-enriches already-indexed mail. Reads only what's already indexed; the live chat model never re-reads a raw body. Safe to run with or without live Ingestion.</p></td>
					</tr>
				</table>
				<?php submit_button( 'Save settings' ); ?>
			</form>

			<h2>Connected mailboxes (status only)</h2>
			<table class="widefat striped">
				<thead><tr><th>User</th><th>Mailbox</th><th>Status</th><th>Index mode</th><th>Admin search</th><th>Backfill</th><th>Indexed</th><th>Connected</th></tr></thead>
				<tbody>
				<?php if ( empty( $roster ) ) : ?>
					<tr><td colspan="8"><em>No mailboxes connected yet.</em></td></tr>
				<?php else : foreach ( $roster as $r ) : ?>
					<tr>
						<td><?php echo esc_html( $r['user'] ); ?></td>
						<td><?php echo esc_html( $r['email_label'] ); ?></td>
						<td><?php echo esc_html( $r['status'] ); ?></td>
						<td><?php echo esc_html( $r['index_mode'] ); ?></td>
						<td><?php echo $r['admin_search_enabled'] ? 'enabled by user' : '—'; ?></td>
						<td><?php echo esc_html( $r['backfill_status'] ); ?></td>
						<td><?php echo (int) $r['indexed_count']; ?></td>
						<td><?php echo esc_html( $r['connected_at'] ); ?></td>
					</tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>
			<p class="description">This table is metadata only — no mail is shown above. An admin can open a mailbox for review below; a mailbox stays closed until an admin turns its gate on, and every read is logged.</p>

			<h2>Read a staff mailbox (admin)</h2>
			<p class="description"><strong>The admin controls the gate.</strong> Turn a mailbox <strong>On</strong> to allow admin review, <strong>Off</strong> to close it. Enabling <em>indexing</em> for a user never grants read access — this gate is separate, admin-only, and audited. Reading requires <code>manage_options</code> or an identity-plugin reader role; flipping the gate requires <code>manage_options</code>.</p>
			<table class="widefat striped">
				<thead><tr><th>User</th><th>Mailbox</th><th>Indexed</th><th>Admin gate</th><th>Action</th></tr></thead>
				<tbody>
				<?php if ( empty( $roster ) ) : ?>
					<tr><td colspan="5"><em>No mailboxes connected yet.</em></td></tr>
				<?php else : foreach ( $roster as $r ) : $ruid = (int) ( $r['user_id'] ?? 0 ); if ( $ruid <= 0 ) { continue; } $gate_on = ! empty( $r['admin_search_enabled'] ); ?>
					<tr>
						<td><?php echo esc_html( $r['user'] ); ?></td>
						<td><?php echo esc_html( $r['email_label'] ); ?></td>
						<td><?php echo (int) $r['indexed_count']; ?></td>
						<td><?php echo $gate_on ? '<span style="color:#0a0">● On</span>' : '<span style="color:#a00">● Off</span>'; ?></td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
								<input type="hidden" name="action" value="zib_toggle_read" />
								<input type="hidden" name="owner_user_id" value="<?php echo esc_attr( $ruid ); ?>" />
								<input type="hidden" name="enable" value="<?php echo $gate_on ? '0' : '1'; ?>" />
								<?php wp_nonce_field( 'zib_toggle_read' ); ?>
								<?php submit_button( $gate_on ? 'Turn off' : 'Turn on', 'secondary', '', false ); ?>
							</form>
							<?php if ( $gate_on ) : ?>
								<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'zib-settings', 'zib_read_uid' => $ruid ), admin_url( 'options-general.php' ) ) ); ?>">Search this mailbox</a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>
			<?php self::render_read_panel(); ?>
		</div>
		<?php
	}
}
