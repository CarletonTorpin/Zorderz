<?php
/**
 * Zorderz Stock — settings page.
 *
 * Stock-specific settings ONLY. Shared credentials (Poe key, FreshBooks) and the platform AI
 * model live in the theme's Core Settings — this page does not re-collect them (no AES cascade,
 * no per-plugin credential fields). The catalog is managed in Zorderz → Item Engine; the "Apply
 * sample catalog" action here is a thin, confirmed pass-through to the Item Engine's own sample
 * mechanism — the catalog is never auto-seeded.
 *
 * @package Zorderz\Stock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSTOCK_Admin {

	const GROUP = 'zstock_settings';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_zstock_apply_sample', array( __CLASS__, 'handle_apply_sample' ) );
		add_action( 'admin_post_zstock_save_suppliers', array( __CLASS__, 'handle_save_suppliers' ) );
		// Reschedule the sweep when the sync options change.
		add_action( 'update_option_zstock_auto_sync', 'zstock_reschedule_sync' );
		add_action( 'update_option_zstock_sync_interval', 'zstock_reschedule_sync' );
	}

	public static function register_menu() {
		add_menu_page(
			__( 'Stock', 'zorderz' ),
			__( 'Stock', 'zorderz' ),
			'manage_options',
			'zstock-settings',
			array( __CLASS__, 'render_settings' ),
			'dashicons-archive',
			59
		);
	}

	public static function register_settings() {
		register_setting( self::GROUP, 'zstock_assistant', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( self::GROUP, 'zstock_auto_sync', array( 'sanitize_callback' => array( __CLASS__, 'sanitize_bool' ) ) );
		register_setting( self::GROUP, 'zstock_sync_interval', array( 'sanitize_callback' => array( __CLASS__, 'sanitize_interval' ) ) );
		register_setting( self::GROUP, 'zstock_low_stock_email', array( 'sanitize_callback' => 'sanitize_email' ) );
		register_setting( self::GROUP, 'zstock_default_supplier_name', array( 'sanitize_callback' => 'sanitize_text_field' ) );
	}

	public static function sanitize_bool( $v ) {
		return $v ? '1' : '';
	}

	public static function sanitize_interval( $v ) {
		return in_array( $v, array( 'hourly', 'twicedaily', 'daily' ), true ) ? $v : 'daily';
	}

	public static function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$poe_ok = class_exists( 'ZDZ_Core_Settings' ) && '' !== ZDZ_Core_Settings::get_poe_api_key();
		$fb_ok  = class_exists( 'ZDZ_Core_FreshBooks' ) && ( new ZDZ_Core_FreshBooks() )->is_configured();
		$empty  = class_exists( 'ZSTOCK_Catalog' ) ? ZSTOCK_Catalog::is_empty() : true;

		settings_errors( 'zstock' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Stock — Settings', 'zorderz' ); ?></h1>
			<p class="description">v<?php echo esc_html( ZSTOCK_VERSION ); ?> · <?php esc_html_e( 'optional / beta', 'zorderz' ); ?></p>

			<div style="background:#fff;padding:14px 18px;border:1px solid #ccd0d4;margin:16px 0;max-width:640px;">
				<h3 style="margin-top:0;"><?php esc_html_e( 'Status', 'zorderz' ); ?></h3>
				<p>
					<?php esc_html_e( 'AI (Poe):', 'zorderz' ); ?>
					<?php echo $poe_ok ? '<span style="color:green;">&#10003; ' . esc_html__( 'configured', 'zorderz' ) . '</span>' : '<span style="color:#b32d2e;">&#10007; ' . esc_html__( 'not configured', 'zorderz' ) . '</span>'; ?>
					&nbsp;|&nbsp;
					<?php esc_html_e( 'Billing (FreshBooks):', 'zorderz' ); ?>
					<?php echo $fb_ok ? '<span style="color:green;">&#10003; ' . esc_html__( 'connected', 'zorderz' ) . '</span>' : '<span style="color:#b32d2e;">&#10007; ' . esc_html__( 'not connected', 'zorderz' ) . '</span>'; ?>
				</p>
				<p class="description">
					<?php esc_html_e( 'Credentials are shared platform settings — set them in Zorderz → Settings, not here.', 'zorderz' ); ?>
				</p>
			</div>

			<div style="background:<?php echo $empty ? '#fff8e5' : '#f0fdf4'; ?>;padding:14px 18px;border:1px solid <?php echo $empty ? '#f0d48a' : '#bbf7d0'; ?>;margin:16px 0;max-width:640px;">
				<h3 style="margin-top:0;"><?php esc_html_e( 'Catalog', 'zorderz' ); ?></h3>
				<?php if ( $empty ) : ?>
					<p><?php esc_html_e( 'The catalog is empty. Stock tracks whatever items you define in the Item Engine, including each item’s Bill of Materials (its “consumes”). Define items in Zorderz → Item Engine, or load a fictional demo catalog to try the app.', 'zorderz' ); ?></p>
				<?php else : ?>
					<p><?php esc_html_e( 'Stock is reading the Item Engine catalog. Manage items and their Bill of Materials in Zorderz → Item Engine.', 'zorderz' ); ?></p>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px;"
					onsubmit="return confirm('<?php echo esc_js( __( 'Load the fictional sample catalog into the Item Engine? This adds clearly-marked demo items only.', 'zorderz' ) ); ?>');">
					<input type="hidden" name="action" value="zstock_apply_sample" />
					<?php wp_nonce_field( 'zstock_apply_sample' ); ?>
					<button type="submit" class="button"><?php esc_html_e( 'Apply sample catalog (demo)', 'zorderz' ); ?></button>
				</form>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="zstock_assistant"><?php esc_html_e( 'Inventory bot name', 'zorderz' ); ?></label></th>
						<td>
							<input type="text" id="zstock_assistant" name="zstock_assistant" class="regular-text"
								value="<?php echo esc_attr( get_option( 'zstock_assistant', '' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Optional Poe bot to answer inventory questions. Leave blank to use the platform’s default AI model with the built-in prompt template.', 'zorderz' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Auto-deduct from billed jobs', 'zorderz' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="zstock_auto_sync" value="1" <?php checked( get_option( 'zstock_auto_sync', '' ), '1' ); ?> />
								<?php esc_html_e( 'Automatically deduct materials from stock when jobs are billed (via each item’s Bill of Materials).', 'zorderz' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="zstock_sync_interval"><?php esc_html_e( 'Deduction interval', 'zorderz' ); ?></label></th>
						<td>
							<?php $interval = get_option( 'zstock_sync_interval', 'daily' ); ?>
							<select id="zstock_sync_interval" name="zstock_sync_interval">
								<option value="hourly" <?php selected( $interval, 'hourly' ); ?>><?php esc_html_e( 'Hourly', 'zorderz' ); ?></option>
								<option value="twicedaily" <?php selected( $interval, 'twicedaily' ); ?>><?php esc_html_e( 'Twice daily', 'zorderz' ); ?></option>
								<option value="daily" <?php selected( $interval, 'daily' ); ?>><?php esc_html_e( 'Daily', 'zorderz' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="zstock_low_stock_email"><?php esc_html_e( 'Low-stock alert email', 'zorderz' ); ?></label></th>
						<td>
							<input type="email" id="zstock_low_stock_email" name="zstock_low_stock_email" class="regular-text"
								value="<?php echo esc_attr( get_option( 'zstock_low_stock_email', '' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Where to send low-stock alerts. Leave blank to disable.', 'zorderz' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="zstock_default_supplier_name"><?php esc_html_e( 'Default supplier name', 'zorderz' ); ?></label></th>
						<td>
							<input type="text" id="zstock_default_supplier_name" name="zstock_default_supplier_name" class="regular-text"
								value="<?php echo esc_attr( get_option( 'zstock_default_supplier_name', '' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Used when a parsed invoice has no supplier name.', 'zorderz' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
			<?php self::render_suppliers(); ?>
		</div>
		<?php
	}

	/**
	 * The supplier list: one supplier per line, "Name | orders@supplier.example" or "Name | @supplier.example" (a whole
	 * domain and its subdomains). Mail from these senders, in a person's own connected mailbox, can add orders under
	 * Incoming. The business's own domains, whole free-mail domains and public suffixes are refused.
	 */
	private static function render_suppliers() {
		$lines = array();
		foreach ( ZSTOCK_Suppliers::all() as $r ) {
			$lines[] = $r['name'] . ' | ' . $r['match'];
		}
		?>
		<h2 id="zstock-suppliers"><?php esc_html_e( 'Suppliers', 'zorderz' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Order mail from these senders adds orders under Incoming (it never changes stock: a person taps Received). One per line: Name | an address, or Name | @a-whole-domain. Your own domains and whole free-mail domains are refused.', 'zorderz' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="zstock_save_suppliers" />
			<?php wp_nonce_field( 'zstock_save_suppliers' ); ?>
			<textarea name="zstock_suppliers_text" rows="8" class="large-text code" placeholder="Example Supply Co | orders@supplier.example"><?php echo esc_textarea( implode( "\n", $lines ) ); ?></textarea>
			<?php submit_button( __( 'Save suppliers', 'zorderz' ) ); ?>
		</form>
		<?php
	}

	/** Parse "Name | match" lines into rows. PURE. */
	public static function parse_supplier_lines( string $text ): array {
		$rows = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
			$parts = array_map( 'trim', explode( '|', (string) $line, 2 ) );
			if ( 2 === count( $parts ) && '' !== $parts[0] && '' !== $parts[1] ) {
				$rows[] = array( 'name' => $parts[0], 'match' => $parts[1] );
			}
		}
		return $rows;
	}

	/** Save the supplier list (admin only), auditing each address added or removed. */
	public static function handle_save_suppliers() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'zorderz' ) );
		}
		check_admin_referer( 'zstock_save_suppliers' );
		$rows                            = self::parse_supplier_lines( sanitize_textarea_field( wp_unslash( $_POST['zstock_suppliers_text'] ?? '' ) ) );
		list( $saved, $added, $removed ) = ZSTOCK_Suppliers::save( $rows );
		foreach ( $added as $m ) {
			self::audit( 'supplier_added', $m );
		}
		foreach ( $removed as $m ) {
			self::audit( 'supplier_removed', $m );
		}
		$refused = count( $rows ) - count( $saved );
		$msg     = $refused > 0
			/* translators: 1: suppliers saved, 2: lines refused */
			? sprintf( __( 'Saved %1$d suppliers. %2$d line(s) were refused: your own domain, a whole free-mail domain, a duplicate, or not an address.', 'zorderz' ), count( $saved ), $refused )
			/* translators: %d: suppliers saved */
			: sprintf( __( 'Saved %d suppliers.', 'zorderz' ), count( $saved ) );
		add_settings_error( 'zstock', 'zstock_suppliers', $msg, $refused > 0 ? 'warning' : 'success' );
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( admin_url( 'admin.php?page=zstock-settings&settings-updated=1#zstock-suppliers' ) );
		exit;
	}

	private static function audit( $action, $detail ) {
		if ( class_exists( 'ZDZ_Admin_Dashboard' ) && method_exists( 'ZDZ_Admin_Dashboard', 'log_action' ) ) {
			ZDZ_Admin_Dashboard::log_action( get_current_user_id(), $action, $detail, ZSTOCK_APP_ID );
		} else {
			zstock_log( $action . ': ' . $detail );
		}
	}

	/** Apply the Item Engine's fictional sample catalog (confirmed, never auto). */
	public static function handle_apply_sample() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'zorderz' ) );
		}
		check_admin_referer( 'zstock_apply_sample' );

		$msg  = __( 'The Item Engine is unavailable, so no sample catalog was applied.', 'zorderz' );
		$type = 'error';
		if ( is_callable( array( 'ZDZ_Item_Engine', 'apply_sample_pack' ) ) ) {
			$res = ZDZ_Item_Engine::apply_sample_pack( true );
			if ( is_wp_error( $res ) ) {
				$msg = $res->get_error_message();
			} else {
				$msg  = sprintf(
					/* translators: 1: item count, 2: scheme count */
					__( 'Sample catalog applied: %1$d items, %2$d pricing schemes. These are clearly-marked demo entries — edit or remove them in the Item Engine.', 'zorderz' ),
					(int) ( $res['items'] ?? 0 ),
					(int) ( $res['schemes'] ?? 0 )
				);
				$type = 'success';
			}
		}
		add_settings_error( 'zstock', 'zstock_sample', $msg, $type );
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( admin_url( 'admin.php?page=zstock-settings&settings-updated=1' ) );
		exit;
	}
}
