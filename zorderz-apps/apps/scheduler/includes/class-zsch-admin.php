<?php
/**
 * ZSCH_Admin — the Microsoft 365 connection settings screen.
 *
 * One admin page (Settings → TS Scheduler) where an owner/admin enters the
 * Azure AD app registration details that switch on two-way Outlook sync:
 *   • Directory (tenant) ID
 *   • Application (client) ID
 *   • Client secret  (write-only; never echoed back)
 *   • Default time zone
 *   • Sync on/off
 * Plus a "Test connection" button that acquires an app-only token and reports
 * success/failure so setup is verifiable without leaving WordPress.
 *
 * v1.6.0 adds the CONNECTED CALENDARS section: the Google + Microsoft
 * delegated (per-user OAuth) app credentials, the feature flag, the conflict
 * policy, per-provider credential tests, and a status-only roster of who has
 * connected what. Secrets are write-only here too — never echoed back.
 *
 * Only manage_options users reach this page. Secrets are stored isolated
 * (ZSCH_Settings) and never returned to the browser.
 *
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSCH_Admin {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'maybe_save' ) );
		add_action( 'wp_ajax_zsch_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_zsch_test_conncal', array( $this, 'ajax_test_conncal' ) );
	}

	public function menu() {
		add_options_page(
			'TS Scheduler',
			'TS Scheduler',
			'manage_options',
			'zsch-settings',
			array( $this, 'render' )
		);
	}

	public function maybe_save() {
		if ( ! empty( $_POST['zsch_settings_save'] ) ) {
			$this->save_graph_settings();
			return;
		}
		if ( ! empty( $_POST['zsch_conncal_save'] ) ) {
			$this->save_conncal_settings();
		}
	}

	private function save_graph_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( 'zsch_settings' );

		ZSCH_Settings::update_config( array(
			'tenant_id'    => sanitize_text_field( wp_unslash( $_POST['tenant_id'] ?? '' ) ),
			'client_id'    => sanitize_text_field( wp_unslash( $_POST['client_id'] ?? '' ) ),
			'default_tz'   => sanitize_text_field( wp_unslash( $_POST['default_tz'] ?? '' ) ),
			'sync_enabled' => ! empty( $_POST['sync_enabled'] ),
		) );

		// Only overwrite the secret if a new one was typed (blank = keep).
		$secret = (string) wp_unslash( $_POST['client_secret'] ?? '' );
		if ( '' !== trim( $secret ) ) {
			ZSCH_Settings::set_secret( trim( $secret ) );
			ZSCH_Settings::clear_cached_token(); // force a fresh token next call
		}

		add_settings_error( 'zsch', 'saved', 'Settings saved.', 'updated' );
	}

	/** v1.6.0 — Connected Calendars section save. */
	private function save_conncal_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( 'zsch_conncal' );

		ZSCH_Settings::update_conncal_config( array(
			'google_client_id' => sanitize_text_field( wp_unslash( $_POST['cc_google_client_id'] ?? '' ) ),
			'ms_client_id'     => sanitize_text_field( wp_unslash( $_POST['cc_ms_client_id'] ?? '' ) ),
			'conflict_policy'  => sanitize_text_field( wp_unslash( $_POST['cc_conflict_policy'] ?? 'warn' ) ),
		) );

		// Secrets: blank = keep existing (same posture as the Graph secret).
		$gsec = trim( (string) wp_unslash( $_POST['cc_google_secret'] ?? '' ) );
		if ( '' !== $gsec ) {
			ZSCH_Settings::set_google_secret( $gsec );
		}
		$msec = trim( (string) wp_unslash( $_POST['cc_ms_secret'] ?? '' ) );
		if ( '' !== $msec ) {
			ZSCH_Settings::set_ms_delegated_secret( $msec );
		}

		update_option( 'zsch_connected_cals', ! empty( $_POST['cc_enabled'] ) ? 'yes' : 'no' );

		add_settings_error( 'zsch', 'saved_conncal', 'Connected Calendars settings saved.', 'updated' );
	}

	public function render() {
		$cfg = ZSCH_Settings::get_config();
		$has_secret = ZSCH_Settings::has_secret();
		$active = ZSCH_Settings::sync_active();
		?>
		<div class="wrap">
			<h1>Scheduler — Mode A: Org-wide Microsoft 365 Sync (optional)</h1>
			<?php settings_errors( 'zsch' ); ?>

			<p style="max-width:740px">
				<strong>Most teams don't need this.</strong> The primary way to sync calendars is
				<em>Connected Calendars</em> (below), where each user connects their own Google or
				Microsoft account — no org-wide admin required.
			</p>
			<p style="max-width:740px">
				<strong>Mode A</strong> is an optional convenience for a team whose members are all in a
				single Microsoft 365 tenant: register <strong>one</strong> Azure AD app with the
				<code>Calendars.ReadWrite</code> <em>application</em> permission and admin consent, and the
				platform can two-way sync every mailbox without each user connecting. All values below ship
				empty. See the module README → "Mode A (org-wide Azure setup)" for the step-by-step.
			</p>

			<div style="margin:12px 0;padding:10px 14px;border-radius:8px;background:<?php echo $active ? '#ecfdf5' : '#fff7ed'; ?>;border:1px solid <?php echo $active ? '#a7f3d0' : '#fed7aa'; ?>;max-width:740px">
				<strong>Status:</strong>
				<?php echo $active
					? '✅ Sync is configured and ON.'
					: ( $has_secret ? '⚙️ Credentials present — switch "Enable sync" on to activate.' : '⛔ Not configured (running local-only).' ); ?>
			</div>

			<form method="post">
				<?php wp_nonce_field( 'zsch_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="tenant_id">Directory (tenant) ID</label></th>
						<td><input name="tenant_id" id="tenant_id" type="text" class="regular-text" value="<?php echo esc_attr( $cfg['tenant_id'] ); ?>" placeholder="00000000-0000-0000-0000-000000000000"></td>
					</tr>
					<tr>
						<th scope="row"><label for="client_id">Application (client) ID</label></th>
						<td><input name="client_id" id="client_id" type="text" class="regular-text" value="<?php echo esc_attr( $cfg['client_id'] ); ?>" placeholder="00000000-0000-0000-0000-000000000000"></td>
					</tr>
					<tr>
						<th scope="row"><label for="client_secret">Client secret</label></th>
						<td>
							<input name="client_secret" id="client_secret" type="password" class="regular-text" autocomplete="new-password" placeholder="<?php echo $has_secret ? '•••••••• (leave blank to keep)' : 'Paste the secret VALUE'; ?>">
							<p class="description">Stored server-side only. Leave blank to keep the existing secret.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="default_tz">Default time zone</label></th>
						<td>
							<input name="default_tz" id="default_tz" type="text" class="regular-text" value="<?php echo esc_attr( $cfg['default_tz'] ); ?>" placeholder="<?php echo esc_attr( ZSCH_Settings::default_tz() ); ?>">
							<p class="description">IANA time-zone name in <code>Region/City</code> form. Leave blank to inherit the site / business-profile time zone (shown as the placeholder). Used when a user's mailbox time zone is unknown.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Enable sync</th>
						<td>
							<label><input name="sync_enabled" type="checkbox" value="1" <?php checked( ! empty( $cfg['sync_enabled'] ) ); ?>> Two-way Outlook / Exchange sync</label>
							<p class="description">When off, the scheduler works entirely on its own tables (no calls to Microsoft).</p>
						</td>
					</tr>
				</table>

				<p>
					<button type="submit" name="zsch_settings_save" value="1" class="button button-primary">Save settings</button>
					<button type="button" id="zsch-test-btn" class="button" style="margin-left:8px">Test connection</button>
					<span id="zsch-test-result" style="margin-left:10px"></span>
				</p>
			</form>

			<?php $this->render_conncal_section(); ?>
		</div>

		<script>
		(function(){
			var nonce = <?php echo wp_json_encode( wp_create_nonce( 'zsch_test' ) ); ?>;
			function wire(btnId, outId, action, provider){
				var btn = document.getElementById(btnId);
				var out = document.getElementById(outId);
				if(!btn) return;
				btn.addEventListener('click', function(){
					out.textContent = 'Testing…';
					out.style.color = '';
					var data = new FormData();
					data.append('action', action);
					if (provider) { data.append('provider', provider); }
					data.append('_wpnonce', nonce);
					fetch(ajaxurl, { method:'POST', credentials:'same-origin', body:data })
						.then(function(r){ return r.json(); })
						.then(function(j){
							out.textContent = j.success ? ('✅ ' + (j.data && j.data.message || 'Connected.')) : ('⛔ ' + (j.data && j.data.message || 'Failed.'));
							out.style.color = j.success ? '#047857' : '#b91c1c';
						})
						.catch(function(){ out.textContent = '⛔ Request failed.'; out.style.color = '#b91c1c'; });
				});
			}
			wire('zsch-test-btn', 'zsch-test-result', 'zsch_test_connection', '');
			wire('zsch-test-google', 'zsch-test-google-result', 'zsch_test_conncal', 'google');
			wire('zsch-test-ms', 'zsch-test-ms-result', 'zsch_test_conncal', 'microsoft');
		})();
		</script>
		<?php
	}

	/** v1.6.0 — the Connected Calendars (per-user OAuth) admin section. */
	private function render_conncal_section() {
		$cc         = ZSCH_Settings::conncal_config();
		$flag_on    = get_option( 'zsch_connected_cals', 'no' ) === 'yes';
		$has_gsec   = ZSCH_Settings::has_google_secret();
		$has_msec   = ZSCH_Settings::has_ms_delegated_secret();
		$enabled    = class_exists( 'ZSCH_OAuth' ) && ZSCH_OAuth::feature_enabled();
		$google_uri = class_exists( 'ZSCH_OAuth' ) ? ZSCH_OAuth::redirect_uri( 'google' ) : '';
		$ms_uri     = class_exists( 'ZSCH_OAuth' ) ? ZSCH_OAuth::redirect_uri( 'microsoft' ) : '';
		$roster     = class_exists( 'ZSCH_Connections' ) ? ZSCH_Connections::roster() : array();
		?>
		<hr style="margin:28px 0">
		<h2>Connected Calendars (per-user, Google + Microsoft)</h2>
		<p style="max-width:740px">
			Lets each team member connect their <em>own</em> outside calendars (personal Google
			Calendar, another Microsoft 365 account) as <strong>conflict calendars</strong> — the
			scheduler will see their busy times and warn before double-booking. This is separate
			from the company-tenant sync above: two different app registrations, and the user
			signs in themselves. Configure both apps per the console setup guide.
		</p>

		<div style="margin:12px 0;padding:10px 14px;border-radius:8px;background:<?php echo $enabled ? '#ecfdf5' : '#fff7ed'; ?>;border:1px solid <?php echo $enabled ? '#a7f3d0' : '#fed7aa'; ?>;max-width:740px">
			<strong>Status:</strong>
			<?php
			if ( $enabled ) {
				echo '✅ Feature is ON — users can connect calendars from the Schedule widget.';
			} elseif ( $flag_on ) {
				echo '⚙️ Flag is on but no provider is fully configured (client ID + secret needed).';
			} else {
				echo '⛔ Off (default). Configure a provider below, then tick "Enable Connected Calendars".';
			}
			?>
		</div>

		<p style="max-width:740px">
			<strong>Redirect URIs to register</strong> (exact, one per console):<br>
			Google: <code><?php echo esc_html( $google_uri ); ?></code><br>
			Microsoft: <code><?php echo esc_html( $ms_uri ); ?></code>
		</p>

		<form method="post">
			<?php wp_nonce_field( 'zsch_conncal' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="cc_google_client_id">Google Client ID</label></th>
					<td><input name="cc_google_client_id" id="cc_google_client_id" type="text" class="regular-text" value="<?php echo esc_attr( $cc['google_client_id'] ); ?>" placeholder="….apps.googleusercontent.com"></td>
				</tr>
				<tr>
					<th scope="row"><label for="cc_google_secret">Google Client secret</label></th>
					<td>
						<input name="cc_google_secret" id="cc_google_secret" type="password" class="regular-text" autocomplete="new-password" placeholder="<?php echo $has_gsec ? '•••••••• (leave blank to keep)' : 'Paste the secret'; ?>">
						<button type="button" id="zsch-test-google" class="button" style="margin-left:8px">Test Google config</button>
						<span id="zsch-test-google-result" style="margin-left:10px"></span>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cc_ms_client_id">Microsoft Application (client) ID</label></th>
					<td>
						<input name="cc_ms_client_id" id="cc_ms_client_id" type="text" class="regular-text" value="<?php echo esc_attr( $cc['ms_client_id'] ); ?>" placeholder="00000000-0000-0000-0000-000000000000">
						<p class="description">The <em>multitenant delegated</em> app registration — NOT the sync app above.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cc_ms_secret">Microsoft Client secret</label></th>
					<td>
						<input name="cc_ms_secret" id="cc_ms_secret" type="password" class="regular-text" autocomplete="new-password" placeholder="<?php echo $has_msec ? '•••••••• (leave blank to keep)' : 'Paste the secret VALUE'; ?>">
						<button type="button" id="zsch-test-ms" class="button" style="margin-left:8px">Test Microsoft config</button>
						<span id="zsch-test-ms-result" style="margin-left:10px"></span>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cc_conflict_policy">Booking conflict policy</label></th>
					<td>
						<select name="cc_conflict_policy" id="cc_conflict_policy">
							<option value="warn" <?php selected( $cc['conflict_policy'], 'warn' ); ?>>Warn (allow "Book anyway")</option>
							<option value="block" <?php selected( $cc['conflict_policy'], 'block' ); ?>>Block double-booking</option>
						</select>
						<p class="description">Takes effect when the busy overlay ships (Phase 1). Default: warn.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Enable Connected Calendars</th>
					<td>
						<label><input name="cc_enabled" type="checkbox" value="1" <?php checked( $flag_on ); ?>> Show the Connected Calendars card in the Schedule widget</label>
						<p class="description">Off = every new surface no-ops and stays hidden; existing data is kept.</p>
					</td>
				</tr>
			</table>
			<p><button type="submit" name="zsch_conncal_save" value="1" class="button button-primary">Save Connected Calendars settings</button></p>
		</form>

		<h3>Who's connected (status only)</h3>
		<?php if ( empty( $roster ) ) : ?>
			<p style="color:#64748b">Nobody has connected an external calendar yet.</p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:740px">
				<thead><tr><th>User</th><th>Provider</th><th>Account</th><th>Feeds</th><th>Status</th></tr></thead>
				<tbody>
				<?php foreach ( $roster as $r ) : ?>
					<tr>
						<td><?php echo esc_html( $r['user'] ); ?></td>
						<td><?php echo esc_html( ucfirst( $r['provider'] ) ); ?></td>
						<td><?php echo esc_html( $r['email_label'] ); ?></td>
						<td><?php echo (int) $r['feeds']; ?></td>
						<td><?php echo 'ok' === $r['status'] ? '✅ Connected' : ( 'reauth_needed' === $r['status'] ? '⚠️ Reconnect needed' : esc_html( $r['status'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	/**
	 * AJAX — acquire an app-only token to verify the credentials work.
	 */
	public function ajax_test_connection() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Not allowed.' ) );
		}
		check_ajax_referer( 'zsch_test' );

		if ( ! class_exists( 'ZSCH_Graph' ) ) {
			wp_send_json_error( array( 'message' => 'Graph client not loaded.' ) );
		}
		// Temporarily bypass the "enabled" gate: we test the *credentials*, even
		// if sync isn't switched on yet. get_token() only needs tenant/client/secret.
		ZSCH_Settings::clear_cached_token();
		$token = ZSCH_Graph::get_token();
		if ( is_wp_error( $token ) ) {
			wp_send_json_error( array( 'message' => $token->get_error_message() ) );
		}
		wp_send_json_success( array( 'message' => 'Authenticated with Microsoft 365 successfully.' ) );
	}

	/**
	 * AJAX (v1.6.0) — verify a Connected Calendars provider's client id +
	 * secret WITHOUT a full OAuth dance: POST the token endpoint with a
	 * deliberately-bogus refresh token. `invalid_client` = credentials
	 * rejected; `invalid_grant` = credentials ACCEPTED (only the bogus token
	 * was refused) — which is exactly what we want to learn. No user data is
	 * touched.
	 */
	public function ajax_test_conncal() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Not allowed.' ) );
		}
		check_ajax_referer( 'zsch_test' );

		$provider = sanitize_key( wp_unslash( $_POST['provider'] ?? '' ) );
		if ( ! in_array( $provider, array( 'google', 'microsoft' ), true ) ) {
			wp_send_json_error( array( 'message' => 'Unknown provider.' ) );
		}

		$cfg = ZSCH_Settings::conncal_config();
		if ( 'google' === $provider ) {
			if ( '' === $cfg['google_client_id'] || ! ZSCH_Settings::has_google_secret() ) {
				wp_send_json_error( array( 'message' => 'Enter and save the Google client ID and secret first.' ) );
			}
			$res = ZSCH_Google::refresh_token( 'zsch-config-probe' );
		} else {
			if ( '' === $cfg['ms_client_id'] || ! ZSCH_Settings::has_ms_delegated_secret() ) {
				wp_send_json_error( array( 'message' => 'Enter and save the Microsoft client ID and secret first.' ) );
			}
			$res = ZSCH_Graph_Delegated::refresh_token( 'zsch-config-probe' );
		}

		if ( is_wp_error( $res ) ) {
			$code = $res->get_error_code();
			if ( 'invalid_grant' === $code ) {
				// The probe token was refused but the CLIENT was accepted.
				wp_send_json_success( array( 'message' => 'Client ID + secret accepted by ' . ( 'google' === $provider ? 'Google' : 'Microsoft' ) . '.' ) );
			}
			if ( 'invalid_client' === $code ) {
				wp_send_json_error( array( 'message' => 'Client ID or secret rejected — re-check both.' ) );
			}
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		// A bogus refresh token should never succeed; treat as config OK anyway.
		wp_send_json_success( array( 'message' => 'Configuration accepted.' ) );
	}
}
