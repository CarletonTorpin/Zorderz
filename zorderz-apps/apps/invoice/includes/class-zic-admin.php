<?php
/**
 * WP-Admin screens for the invoicing module: dashboard, new invoice, settings,
 * webhook events.
 *
 * Two generalized settings live here, both clearly disclosed:
 *   - Platform fee (%)  — default 0 / off. Applied as a Stripe Connect
 *     application fee only when a connected account is set. Replaces the old
 *     baked 0.5% constant.
 *   - Thank-you / return URL — blank shows the built-in on-site thank-you page.
 *     Replaces the old hardcoded production URL.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIC_Admin {

	const CAP  = 'manage_options';
	const SLUG = 'zic_dashboard';

	public static function register_menus() {
		add_menu_page( 'Invoice Creator', 'Invoices', self::CAP, self::SLUG, array( __CLASS__, 'render_dashboard' ), 'dashicons-money-alt', 56 );
		add_submenu_page( self::SLUG, 'Dashboard', 'Dashboard', self::CAP, self::SLUG, array( __CLASS__, 'render_dashboard' ) );
		add_submenu_page( self::SLUG, 'New Invoice', 'New Invoice', self::CAP, 'zic_new_invoice', array( __CLASS__, 'render_new_invoice' ) );
		add_submenu_page( self::SLUG, 'Settings', 'Settings', self::CAP, 'zic_settings', array( __CLASS__, 'render_settings' ) );
		add_submenu_page( self::SLUG, 'Webhook Events', 'Webhook Events', self::CAP, 'zic_webhook_events', array( __CLASS__, 'render_events' ) );
	}

	public static function register_settings() {
		$opts = array(
			'zic_stripe_secret',
			'zic_stripe_publishable',
			'zic_stripe_webhook_secret',
			'zic_stripe_connected_account',
			'zic_freshbooks_token',
			'zic_freshbooks_account_id',
			'zic_fb_client_id',
			'zic_fb_client_secret',
			'zic_fb_redirect_uri',
			'zic_notify_email',
		);
		foreach ( $opts as $o ) {
			register_setting( 'zic_settings_group', $o );
		}

		// Generalized, disclosed options with explicit sanitizers.
		register_setting(
			'zic_settings_group',
			'zic_platform_fee_percent',
			array(
				'type'              => 'string',
				'default'           => '0',
				'sanitize_callback' => array( __CLASS__, 'sanitize_fee_percent' ),
			)
		);
		register_setting(
			'zic_settings_group',
			'zic_return_url',
			array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => array( __CLASS__, 'sanitize_return_url' ),
			)
		);
	}

	/** Clamp the platform fee to a plain 0–100 percentage string. */
	public static function sanitize_fee_percent( $value ) {
		$n = (float) $value;
		if ( $n < 0 ) {
			$n = 0.0;
		}
		if ( $n > 100 ) {
			$n = 100.0;
		}
		// Store a tidy string (up to 3 decimals), e.g. "0", "0.5", "2.75".
		return rtrim( rtrim( number_format( $n, 3, '.', '' ), '0' ), '.' );
	}

	/** A blank return URL is valid (means "on-site thank-you"); else esc_url_raw. */
	public static function sanitize_return_url( $value ) {
		$value = trim( (string) $value );
		return '' === $value ? '' : esc_url_raw( $value );
	}

	public static function render_new_invoice() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$flash   = isset( $_GET['zic_created'] ) ? sanitize_text_field( wp_unslash( $_GET['zic_created'] ) ) : '';
		$pay_url = isset( $_GET['pay_url'] ) ? esc_url_raw( urldecode( wp_unslash( $_GET['pay_url'] ) ) ) : '';
		?>
		<div class="wrap">
			<h1>New Invoice</h1>
			<?php if ( '1' === $flash && $pay_url ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><strong>Invoice created.</strong> Pay URL:
					<a href="<?php echo esc_url( $pay_url ); ?>" target="_blank"><?php echo esc_html( $pay_url ); ?></a></p>
				</div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="zic_create_invoice" />
				<?php wp_nonce_field( 'zic_create_invoice' ); ?>
				<table class="form-table">
					<tr><th><label>Client name</label></th><td><input type="text" name="client_name" required size="40" /></td></tr>
					<tr><th><label>Client email</label></th><td><input type="email" name="client_email" required size="40" /></td></tr>
					<tr><th><label>Description</label></th><td><textarea name="description" rows="4" cols="60" required></textarea></td></tr>
					<tr><th><label>Amount (USD)</label></th><td><input type="number" step="0.01" min="0.50" name="amount_usd" required /></td></tr>
					<tr><th><label>Currency</label></th><td><input type="text" name="currency" value="usd" size="6" /></td></tr>
					<tr><th><label>FreshBooks Invoice ID (optional)</label></th><td><input type="text" name="freshbooks_invoice_id" size="20" /></td></tr>
				</table>
				<?php submit_button( 'Create Invoice' ); ?>
			</form>
		</div>
		<?php
	}

	public static function handle_create_invoice() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'nope' );
		}
		check_admin_referer( 'zic_create_invoice' );
		$amount_usd = (float) ( $_POST['amount_usd'] ?? 0 );
		$data       = array(
			'client_name'           => sanitize_text_field( $_POST['client_name'] ?? '' ),
			'client_email'          => sanitize_email( $_POST['client_email'] ?? '' ),
			'description'           => wp_kses_post( $_POST['description'] ?? '' ),
			'amount_cents'          => (int) round( $amount_usd * 100 ),
			'currency'              => strtolower( sanitize_text_field( $_POST['currency'] ?? 'usd' ) ),
			'freshbooks_invoice_id' => sanitize_text_field( $_POST['freshbooks_invoice_id'] ?? '' ),
		);
		if ( $data['amount_cents'] < 50 ) {
			wp_die( 'amount must be >= $0.50' );
		}
		if ( ! class_exists( 'ZIC_Payment_Engine' ) ) {
			wp_die( 'engine missing' );
		}
		$res = ZIC_Payment_Engine::create_and_send( $data );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'zic_new_invoice',
					'zic_created' => '1',
					'pay_url'     => rawurlencode( $res['pay_url'] ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function render_dashboard() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		global $wpdb;
		$t_inv   = $wpdb->prefix . 'zic_invoices';
		$rows    = $wpdb->get_results( "SELECT * FROM {$t_inv} ORDER BY id DESC LIMIT 100", ARRAY_A );
		$net_mtd = class_exists( 'ZIC_DB' ) ? ZIC_DB::platform_fee_total_since( strtotime( gmdate( 'Y-m-01' ) ) ) : 0;
		$fee_on  = zic_platform_fee_rate() > 0;
		?>
		<div class="wrap">
			<h1>Invoice Creator — Dashboard
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=zic_new_invoice' ) ); ?>" class="page-title-action">Add New</a>
			</h1>
			<div style="display:flex;gap:16px;flex-wrap:wrap;margin:12px 0 20px">
				<?php if ( $fee_on ) : ?>
				<div style="background:#fff;border:1px solid #ddd;padding:14px 20px;border-radius:6px;">
					<div style="font-size:12px;color:#666">Retained Platform Fees (MTD)</div>
					<div style="font-size:22px;font-weight:600">$<?php echo esc_html( number_format( $net_mtd / 100, 2 ) ); ?></div>
				</div>
				<?php endif; ?>
				<div style="background:#fff;border:1px solid #ddd;padding:14px 20px;border-radius:6px;">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="zic_export_csv" />
						<?php wp_nonce_field( 'zic_export_csv' ); ?>
						<button type="submit" class="button">Export All Invoices (CSV)</button>
					</form>
				</div>
			</div>

			<?php if ( empty( $rows ) ) : ?>
				<div style="background:#fff;border:1px dashed #bbb;padding:24px;text-align:center;border-radius:8px;">
					<p style="margin:0 0 12px">No invoices yet.</p>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=zic_new_invoice' ) ); ?>" class="button button-primary">Create Your First Invoice</a>
				</div>
			<?php else : ?>
			<table class="widefat striped">
				<thead><tr><th>ID</th><th>Client</th><th>Amount</th><th>Refunded</th><th>Status</th><th>Pay URL</th><th>Refund</th></tr></thead>
				<tbody>
				<?php
				foreach ( (array) $rows as $r ) :
					$pay_url = home_url( '/pay/' . rawurlencode( $r['token'] ) );
					$max     = max( 0, ( (int) $r['amount_cents'] ) - ( (int) $r['refunded_cents'] ) );
					?>
				<tr>
					<td><?php echo (int) $r['id']; ?></td>
					<td><?php echo esc_html( $r['client_name'] ); ?><br><small><?php echo esc_html( $r['client_email'] ); ?></small></td>
					<td>$<?php echo esc_html( number_format( $r['amount_cents'] / 100, 2 ) ); ?></td>
					<td>$<?php echo esc_html( number_format( ( (int) $r['refunded_cents'] ) / 100, 2 ) ); ?></td>
					<td><?php echo esc_html( $r['status'] ); ?></td>
					<td><a href="<?php echo esc_url( $pay_url ); ?>" target="_blank">open</a></td>
					<td>
					<?php if ( 'paid' === $r['status'] && $max > 0 ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:4px;align-items:center">
							<input type="hidden" name="action" value="zic_refund" />
							<input type="hidden" name="invoice_id" value="<?php echo (int) $r['id']; ?>" />
							<?php wp_nonce_field( 'zic_refund' ); ?>
							<input type="number" step="0.01" min="0.01" max="<?php echo esc_attr( number_format( $max / 100, 2, '.', '' ) ); ?>" name="amount_usd" placeholder="<?php echo esc_attr( number_format( $max / 100, 2, '.', '' ) ); ?>" style="width:90px" />
							<button class="button button-small" type="submit">Refund</button>
						</form>
					<?php else : echo '—'; endif; ?>
					</td>
				</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function render_settings() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$fb_status        = class_exists( 'ZIC_FreshBooks_OAuth' ) ? ZIC_FreshBooks_OAuth::token_status_label() : '—';
		$default_redirect = class_exists( 'ZIC_FreshBooks_OAuth' ) ? ZIC_FreshBooks_OAuth::default_redirect_uri() : '';
		$flash            = isset( $_GET['zic_fb'] ) ? sanitize_text_field( wp_unslash( $_GET['zic_fb'] ) ) : '';
		?>
		<div class="wrap">
			<h1>Invoice Creator — Settings</h1>
			<?php if ( 'connected' === $flash ) : ?>
				<div class="notice notice-success is-dismissible"><p>FreshBooks connected successfully.</p></div>
			<?php elseif ( 'state_mismatch' === $flash ) : ?>
				<div class="notice notice-error is-dismissible"><p>OAuth state mismatch — try again.</p></div>
			<?php elseif ( 'exchange_failed' === $flash ) : ?>
				<div class="notice notice-error is-dismissible"><p>OAuth token exchange failed — check error log.</p></div>
			<?php elseif ( 'not_configured' === $flash ) : ?>
				<div class="notice notice-warning is-dismissible"><p>Set Client ID + Client Secret below before connecting.</p></div>
			<?php endif; ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'zic_settings_group' ); ?>

				<h2>Payments (Stripe)</h2>
				<table class="form-table">
					<tr><th>Publishable Key</th><td><input type="text" name="zic_stripe_publishable" value="<?php echo esc_attr( get_option( 'zic_stripe_publishable' ) ); ?>" size="60" placeholder="pk_live_..." /></td></tr>
					<tr><th>Secret Key</th><td><input type="password" name="zic_stripe_secret" value="<?php echo esc_attr( get_option( 'zic_stripe_secret' ) ); ?>" size="60" /></td></tr>
					<tr><th>Webhook Secret</th><td><input type="password" name="zic_stripe_webhook_secret" value="<?php echo esc_attr( get_option( 'zic_stripe_webhook_secret' ) ); ?>" size="60" /></td></tr>
					<tr>
						<th>Connected Account ID (optional)</th>
						<td>
							<input type="text" name="zic_stripe_connected_account" value="<?php echo esc_attr( get_option( 'zic_stripe_connected_account' ) ); ?>" size="40" placeholder="acct_..." />
							<p class="description">For Stripe Connect. Leave blank to charge to this site's own Stripe account (no platform fee applies).</p>
						</td>
					</tr>
				</table>

				<h2>Platform fee</h2>
				<table class="form-table">
					<tr>
						<th><label for="zic_platform_fee_percent">Platform fee (%)</label></th>
						<td>
							<input type="number" step="0.001" min="0" max="100" id="zic_platform_fee_percent" name="zic_platform_fee_percent" value="<?php echo esc_attr( get_option( 'zic_platform_fee_percent', '0' ) ); ?>" style="width:120px" />
							<p class="description">
								Optional application fee retained by this site on each payment, taken via Stripe Connect.
								<strong>Default 0 (off).</strong> A fee applies only when a Connected Account ID is set above; with no connected account it is ignored.
								Example: <code>0.5</code> means 0.5%.
							</p>
						</td>
					</tr>
				</table>

				<h2>Payment page</h2>
				<table class="form-table">
					<tr>
						<th><label for="zic_return_url">Thank-you / return URL</label></th>
						<td>
							<input type="url" id="zic_return_url" name="zic_return_url" value="<?php echo esc_attr( get_option( 'zic_return_url', '' ) ); ?>" size="80" placeholder="https://" />
							<p class="description">Where customers go after a successful payment. Leave blank to show the built-in on-site thank-you page. Must be a full URL.</p>
						</td>
					</tr>
				</table>

				<h2>FreshBooks (OAuth 2.0)</h2>
				<p><strong>Token status:</strong> <?php echo esc_html( $fb_status ); ?></p>
				<table class="form-table">
					<tr><th>Client ID</th><td><input type="text" name="zic_fb_client_id" value="<?php echo esc_attr( get_option( 'zic_fb_client_id' ) ); ?>" size="60" /></td></tr>
					<tr><th>Client Secret</th><td><input type="password" name="zic_fb_client_secret" value="<?php echo esc_attr( get_option( 'zic_fb_client_secret' ) ); ?>" size="60" /></td></tr>
					<tr><th>Redirect URI</th><td><input type="text" name="zic_fb_redirect_uri" value="<?php echo esc_attr( get_option( 'zic_fb_redirect_uri', $default_redirect ) ); ?>" size="80" /><p class="description">Default: <code><?php echo esc_html( $default_redirect ); ?></code></p></td></tr>
					<tr><th>Account ID</th><td><input type="text" name="zic_freshbooks_account_id" value="<?php echo esc_attr( get_option( 'zic_freshbooks_account_id' ) ); ?>" size="40" /></td></tr>
					<tr><th>Legacy Static Token (fallback)</th><td><input type="password" name="zic_freshbooks_token" value="<?php echo esc_attr( get_option( 'zic_freshbooks_token' ) ); ?>" size="60" /></td></tr>
				</table>

				<h2>Notifications</h2>
				<table class="form-table">
					<tr><th>Merchant Email</th><td><input type="email" name="zic_notify_email" value="<?php echo esc_attr( get_option( 'zic_notify_email' ) ); ?>" size="40" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" /></td></tr>
				</table>

				<?php submit_button( 'Save Settings' ); ?>
			</form>

			<h2>Connect FreshBooks</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="zic_fb_oauth_start" />
				<?php wp_nonce_field( 'zic_fb_oauth_start' ); ?>
				<p><button type="submit" class="button button-primary" <?php echo class_exists( 'ZIC_FreshBooks_OAuth' ) && ZIC_FreshBooks_OAuth::is_configured() ? '' : 'disabled'; ?>>Connect FreshBooks (OAuth)</button></p>
			</form>
		</div>
		<?php
	}

	public static function render_events() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$events = class_exists( 'ZIC_DB' ) ? ZIC_DB::recent_webhook_events( 100 ) : array();
		echo '<div class="wrap"><h1>Stripe Webhook Events (last 100)</h1>';
		if ( empty( $events ) ) {
			echo '<p>No webhook events received yet.</p></div>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>Received</th><th>Type</th><th>Event ID</th></tr></thead><tbody>';
		foreach ( (array) $events as $e ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td><code>%s</code></td></tr>',
				esc_html( $e['created_at'] ?? '' ),
				esc_html( $e['type'] ?? '' ),
				esc_html( $e['stripe_event_id'] ?? '' )
			);
		}
		echo '</tbody></table></div>';
	}
}
