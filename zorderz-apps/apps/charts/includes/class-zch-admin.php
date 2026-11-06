<?php
/**
 * Screens, the WordPress dashboard widget, settings and the health monitor.
 *
 * @package Zorderz\Charts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZCH_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_head', array( __CLASS__, 'app_frame_head' ) );
		add_filter( 'admin_body_class', array( __CLASS__, 'app_frame_body_class' ) );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'dashboard_widget' ) );
		add_shortcode( 'zch_charts', array( __CLASS__, 'shortcode_app' ) );
		add_shortcode( 'zch_charts_pinned', array( __CLASS__, 'shortcode_pinned' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( ZCH_CRON_HOOK, array( __CLASS__, 'health_check' ) );
		add_action( 'admin_post_zch_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_zch_hub', array( __CLASS__, 'save_hub' ) );
		add_action( 'admin_post_zch_keygen', array( __CLASS__, 'keygen' ) );
		if ( ! wp_next_scheduled( ZCH_CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, 'zch_5min', ZCH_CRON_HOOK );
		}
	}

	/**
	 * Opened from the app (?zdz_mobile=1): hide the WordPress chrome in <head>, before anything
	 * is painted, so the page never flashes the admin menu and then jumps.
	 */
	private static function in_app_frame() {
		return isset( $_GET['page'], $_GET['zdz_mobile'] ) && ZCH_SLUG === $_GET['page'] // phpcs:ignore WordPress.Security.NonceVerification -- display only.
			&& '1' === $_GET['zdz_mobile'];                                                  // phpcs:ignore WordPress.Security.NonceVerification -- display only.
	}

	public static function app_frame_head() {
		if ( ! self::in_app_frame() ) {
			return;
		}
		echo '<style>#wpadminbar,#adminmenuwrap,#adminmenuback,#wpfooter,.update-nag,.notice{display:none!important}'
			. 'html.wp-toolbar{padding-top:0!important}#wpcontent,#wpfooter{margin-left:0!important}'
			. '#wpcontent{padding-left:0!important}#wpbody-content{padding-bottom:0!important}'
			. 'body.zch-frame .wrap{margin:0!important;padding:12px!important}</style>';
	}

	public static function app_frame_body_class( $classes ) {
		return self::in_app_frame() ? $classes . ' zch-frame' : $classes;
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( ZCH_CRON_HOOK );
		ZCH_Counts::deactivate();
	}

	public static function schedules( $s ) {
		$s['zch_5min'] = array( 'interval' => 300, 'display' => __( 'Every 5 minutes', 'zorderz' ) );
		return $s;
	}

	/* ── Screens ────────────────────────────────────────────────────────── */

	public static function menu() {
		if ( self::allowed() ) {          // hidden unless ZCH_Access allows.
			add_menu_page( __( 'Charts', 'zorderz' ), __( 'Charts', 'zorderz' ), 'read', ZCH_SLUG, array( __CLASS__, 'page_app' ), 'dashicons-chart-bar', 3 );
			add_submenu_page( ZCH_SLUG, __( 'Charts settings', 'zorderz' ), __( 'Settings', 'zorderz' ), 'manage_options', ZCH_SLUG . '-settings', array( __CLASS__, 'page_settings' ) );
		} elseif ( current_user_can( 'manage_options' ) ) {
			// An administrator whose own Charts access is off can still connect the hub.
			add_options_page( __( 'Charts settings', 'zorderz' ), __( 'Charts', 'zorderz' ), 'manage_options', ZCH_SLUG . '-settings', array( __CLASS__, 'page_settings' ) );
		}
	}

	public static function enqueue( $mode ) {
		wp_enqueue_script( 'zch-chartjs', ZCH_URL . 'assets/vendor/chart.umd.min.js', array(), '4.5.1', true );
		wp_enqueue_script( 'zch-charts', ZCH_URL . 'assets/charts.js', array( 'zch-chartjs' ), zch_asset_ver( 'assets/charts.js' ), true );
		wp_enqueue_style( 'zch-charts', ZCH_URL . 'assets/charts.css', array(), zch_asset_ver( 'assets/charts.css' ) );
		wp_localize_script(
			'zch-charts',
			'zchCharts',
			array(
				'rest'        => esc_url_raw( rest_url( ZDZ_REST_NS . ZCH_REST_BASE ) ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'mode'        => $mode,
				'version'     => ZCH_VERSION,
				'fullUrl'     => admin_url( 'admin.php?page=' . ZCH_SLUG . '&zdz_mobile=1' ),
				'appId'       => ZCH_APP_ID,
				// "Ask chat about this chart" hands the chart's totals to the chat; the same switch as
				// the assistant receiving Charts totals.
				'chatShare'   => (bool) ZCH_Store::settings()['bot_totals'],
				// The map loads Leaflet only when one is drawn (not on every dashboard view).
				'map'         => self::map_config(),
				// Whether this person may ask Territories (its verbs decide what they see).
				'territories' => ZCH_Territories::available(),
				// No hub here: the page says so and offers what still works.
				'local'       => 'connected' !== ZCH_Config::state(),
				'localText'   => 'connected' !== ZCH_Config::state() ? ZCH_Config::local_message() : '',
				'company'     => class_exists( 'ZDZ_Business_Profile' ) ? (string) ZDZ_Business_Profile::name() : '',
			)
		);
	}

	/**
	 * Where the map's script, tiles and ZIP outlines come from. Filterable, so a site can point at
	 * another tile provider: array( 'tiles' => url template, 'attribution' => html ).
	 */
	public static function map_config() {
		$tiles  = apply_filters( 'zch_map_tiles', array( 'tiles' => ZCH_MAP_TILES, 'attribution' => ZCH_MAP_ATTRIBUTION ) );
		$url    = ZCH_MAP_TILES;
		$credit = ZCH_MAP_ATTRIBUTION;
		// Not esc_url_raw(): it strips the {z}/{x}/{y} braces the template needs. A provider's
		// credit is used only with that provider's tiles (never one provider's tiles under another's name).
		if ( is_array( $tiles ) && ! empty( $tiles['tiles'] ) && is_string( $tiles['tiles'] )
			&& preg_match( '#^https://[A-Za-z0-9.-]+(:\d+)?/[A-Za-z0-9{}._~/?=&@%-]*$#', $tiles['tiles'] ) ) {
			$url    = $tiles['tiles'];
			$credit = isset( $tiles['attribution'] ) && is_string( $tiles['attribution'] )
				? wp_kses( $tiles['attribution'], array( 'a' => array( 'href' => array() ) ) ) : '';
		}
		// The ZIP-area outlines: the business's own GeoJSON (its hub can export it from the region
		// pack). None by default: a map then draws places only, and says ZIP areas are not set up.
		$shapes = (string) apply_filters( 'zch_map_shapes', (string) ZCH_Store::settings()['map_shapes'] );
		$shapes = preg_match( '#^https://#i', $shapes ) ? esc_url_raw( $shapes ) : '';
		$ver    = '1.9.4';
		return array(
			'js'          => ZCH_URL . 'assets/vendor/leaflet/leaflet.js?ver=' . $ver,
			'css'         => ZCH_URL . 'assets/vendor/leaflet/leaflet.css?ver=' . $ver,
			'tiles'       => $url,
			'attribution' => $credit,
			'shapes'      => $shapes,
		);
	}

	private static function allowed() {
		return ZCH_Rest::allowed();
	}

	public static function page_app() {
		if ( ! self::allowed() ) {
			wp_die( esc_html__( 'Your account does not have access to Charts.', 'zorderz' ) );
		}
		self::enqueue( 'app' );
		echo '<div class="wrap"><div id="zch-charts-app" class="zch"></div></div>';
	}

	public static function shortcode_app() {
		if ( ! self::allowed() ) {
			return '';
		}
		self::enqueue( 'app' );
		return '<div id="zch-charts-app" class="zch"></div>';
	}

	public static function shortcode_pinned() {
		if ( ! self::allowed() ) {
			return '';
		}
		self::enqueue( 'pinned' );
		return '<div id="zch-charts-pinned" class="zch"></div>';
	}

	public static function dashboard_widget() {
		if ( ! self::allowed() ) {
			return;
		}
		wp_add_dashboard_widget(
			'zch_charts_pinned',
			__( 'Pinned charts', 'zorderz' ),
			function () {
				self::enqueue( 'pinned' );
				echo '<div id="zch-charts-pinned" class="zch"></div>';
			}
		);
	}

	/* ── Settings ───────────────────────────────────────────────────────── */

	/** One sentence on the hub connection, for the settings page. */
	public static function hub_state_text() {
		$state = ZCH_Config::state();
		$hosts = implode( ', ', ZCH_Config::live_hosts() );
		if ( 'not_configured' === $state ) {
			return __( 'Not connected: Charts runs in local mode. Fill in the hub below, or define ZCH_HUB_URL, ZCH_HUB_PUBLIC, ZCH_SITE_SECRET and ZCH_LIVE_HOSTS in wp-config.php.', 'zorderz' );
		}
		if ( 'not_live' === $state ) {
			/* translators: 1: this site's host, 2: the live hosts */
			return sprintf( __( 'Not used here: this site answers on %1$s, and the hub keys are used only on %2$s.', 'zorderz' ), ZCH_Config::this_host(), '' !== $hosts ? $hosts : __( '(no live site named yet)', 'zorderz' ) );
		}
		if ( ZCH_Config::from_constants() ) {
			return __( 'Connected (keys from wp-config.php).', 'zorderz' );
		}
		return ZCH_Config::managed() ? __( 'Connected (keys from Zorderz → Credentials, sealed at rest).', 'zorderz' ) : __( 'Connected (keys from this page, sealed at rest).', 'zorderz' );
	}

	/** This site's public key (what the hub's configuration lists), or '' when there is no site secret. */
	public static function site_public_key() {
		$secret = ZCH_Config::site_secret();
		if ( '' === $secret ) {
			return '';
		}
		try {
			$sk = ZCH_Envelope::b64d( $secret );
			return SODIUM_CRYPTO_BOX_SECRETKEYBYTES === strlen( $sk ) ? ZCH_Envelope::b64e( sodium_crypto_box_publickey_from_secretkey( $sk ) ) : '';
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	public static function page_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s     = ZCH_Store::settings();
		$h     = ZCH_Config::stored();
		$const = ZCH_Config::from_constants();
		$pub   = self::site_public_key();
		?>
		<div class="wrap"><h1><?php esc_html_e( 'Charts settings', 'zorderz' ); ?></h1>
		<h2><?php esc_html_e( 'Charts hub', 'zorderz' ); ?></h2>
		<p><strong><?php echo esc_html( self::hub_state_text() ); ?></strong></p>
		<p class="description"><?php esc_html_e( 'Every Charts figure comes from a Charts hub: the reference hub that ships with Zorderz, or any service that speaks the same sealed contract. This site talks to it only from the server; no key or hub address ever reaches a browser.', 'zorderz' ); ?></p>
		<?php if ( '' !== $pub ) : ?>
			<p><?php esc_html_e( "This site's public key (list it in the hub's configuration as the website's key):", 'zorderz' ); ?><br><code><?php echo esc_html( $pub ); ?></code></p>
		<?php endif; ?>
		<?php if ( ! $const ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'zch_hub' ); ?>
			<input type="hidden" name="action" value="zch_hub">
			<table class="form-table">
			<?php if ( ZCH_Config::managed() ) : ?>
			<tr><th><?php esc_html_e( 'Hub address and keys', 'zorderz' ); ?></th><td>
				<p><?php esc_html_e( 'Managed on Zorderz → Credentials (the Charts hub entry). Change them there.', 'zorderz' ); ?>
				<?php if ( class_exists( 'ZDZ_Core_Credentials' ) ) : ?> <a href="<?php echo esc_url( ZDZ_Core_Credentials::page_url() ); ?>"><?php esc_html_e( 'Open Credentials', 'zorderz' ); ?></a><?php endif; ?></p></td></tr>
			<?php else : ?>
			<tr><th><label for="zch-url"><?php esc_html_e( 'Hub address', 'zorderz' ); ?></label></th><td>
				<input type="url" id="zch-url" class="regular-text" name="url" value="<?php echo esc_attr( (string) ( $h['url'] ?? '' ) ); ?>" placeholder="https://hub.example.com/v1/rpc">
				<p class="description"><?php esc_html_e( "The hub's RPC address, reached from this server only.", 'zorderz' ); ?></p></td></tr>
			<tr><th><label for="zch-pub"><?php esc_html_e( "Hub's public key", 'zorderz' ); ?></label></th><td>
				<input type="text" id="zch-pub" class="large-text code" name="hub_public" value="<?php echo esc_attr( (string) ( $h['hub_public'] ?? '' ) ); ?>">
				<p class="description"><?php esc_html_e( 'Two keys, comma-separated, newest first, while the hub changes keys.', 'zorderz' ); ?></p></td></tr>
			<tr><th><label for="zch-secret"><?php esc_html_e( "This site's secret key", 'zorderz' ); ?></label></th><td>
				<input type="password" id="zch-secret" class="regular-text code" name="site_secret" value="" autocomplete="off" placeholder="<?php echo '' !== (string) ( $h['site_secret'] ?? '' ) ? esc_attr__( 'Set (hidden). Leave blank to keep it.', 'zorderz' ) : esc_attr__( 'Not set', 'zorderz' ); ?>">
				<p class="description"><?php esc_html_e( 'Kept sealed at rest and never shown again. Or make a new key pair here with the button below.', 'zorderz' ); ?></p></td></tr>
			<tr><th><label for="zch-headers"><?php esc_html_e( 'Extra request headers', 'zorderz' ); ?></label></th><td>
				<input type="password" id="zch-headers" class="regular-text code" name="headers" value="" autocomplete="off" placeholder="<?php echo '' !== (string) ( $h['headers'] ?? '' ) ? esc_attr__( 'Set (hidden). Leave blank to keep them.', 'zorderz' ) : '{"Header-Name": "value"}'; ?>">
				<label><input type="checkbox" name="forget_headers" value="1"> <?php esc_html_e( 'Remove them', 'zorderz' ); ?></label>
				<p class="description"><?php esc_html_e( "Optional: a JSON object of headers a tunnel or access proxy in front of the hub needs. Sealed at rest.", 'zorderz' ); ?></p></td></tr>
			<?php endif; ?>
			<tr><th><label for="zch-hosts"><?php esc_html_e( 'Live site', 'zorderz' ); ?></label></th><td>
				<input type="text" id="zch-hosts" class="regular-text" name="live_hosts" value="<?php echo esc_attr( (string) ( $h['live_hosts'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( ZCH_Config::this_host() ); ?>">
				<p class="description"><?php esc_html_e( 'The host name(s) of the real site, comma-separated. The keys are used nowhere else, so a staging or development copy never reaches the hub. Blank on first save = this site.', 'zorderz' ); ?></p></td></tr>
			<tr><th><label for="zch-label"><?php esc_html_e( 'What the hub runs on', 'zorderz' ); ?></label></th><td>
				<input type="text" id="zch-label" class="regular-text" name="label" value="<?php echo esc_attr( (string) ( $h['label'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'the hub computer', 'zorderz' ); ?>">
				<p class="description"><?php esc_html_e( 'Used in "may be asleep or offline" messages.', 'zorderz' ); ?></p></td></tr>
			</table>
			<?php submit_button( __( 'Save hub connection', 'zorderz' ) ); ?>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Make a new key pair for this site? The hub must then list the new public key before Charts answers again.', 'zorderz' ) ); ?>');">
			<?php wp_nonce_field( 'zch_keygen' ); ?>
			<input type="hidden" name="action" value="zch_keygen">
			<?php submit_button( __( 'Make a new key pair for this site', 'zorderz' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Charts', 'zorderz' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'zch_settings' ); ?>
			<input type="hidden" name="action" value="zch_settings">
			<table class="form-table">
			<tr><th><?php esc_html_e( 'Offline snapshots', 'zorderz' ); ?></th><td><label><input type="checkbox" name="snapshots" value="1" <?php checked( $s['snapshots'] ); ?>>
				<?php esc_html_e( "Keep each saved chart's last aggregate rows (dated) on this site, to show when the hub is offline.", 'zorderz' ); ?></label>
				<p class="description"><?php esc_html_e( 'Aggregates only: no customers, no line items. Off shows nothing while the hub is down.', 'zorderz' ); ?></p></td></tr>
			<tr><th><?php esc_html_e( 'Assistant', 'zorderz' ); ?></th><td><label><input type="checkbox" name="bot_totals" value="1" <?php checked( $s['bot_totals'] ); ?>>
				<?php esc_html_e( 'Let the chat assistant receive Charts totals.', 'zorderz' ); ?></label>
				<p class="description"><?php esc_html_e( "Off: the assistant can only offer to open the chart here. On: it receives a chart's aggregate rows (never line items or customers), limited to what the person asking may see, and the Charts widget shows an \"Ask chat about this chart\" button. Named business accounts are never named. The assistant's models may run in the cloud; chart numbers are never used for web search.", 'zorderz' ); ?></p></td></tr>
			<tr><th><label for="zch-alert"><?php esc_html_e( 'Alert email', 'zorderz' ); ?></label></th><td><input type="email" id="zch-alert" name="alert_email" value="<?php echo esc_attr( $s['alert_email'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
				<p class="description"><?php esc_html_e( 'Emailed when the hub has been down more than 30 minutes during business hours.', 'zorderz' ); ?></p></td></tr>
			<tr><th><label for="zch-cp"><?php esc_html_e( 'Measured counts', 'zorderz' ); ?></label></th><td>
				<input type="text" id="zch-cp" name="counts_product" value="<?php echo esc_attr( $s['counts_product'] ); ?>" placeholder="<?php esc_attr_e( 'A product name on the hub', 'zorderz' ); ?>">
				<input type="text" name="counts_match" value="<?php echo esc_attr( $s['counts_match'] ); ?>" placeholder="<?php esc_attr_e( 'Word in an estimate (optional)', 'zorderz' ); ?>">
				<p class="description"><?php esc_html_e( "Send the hub each job's measured piece count (from Prep) for this product. Blank turns it off.", 'zorderz' ); ?></p></td></tr>
			<tr><th><label for="zch-shapes"><?php esc_html_e( 'ZIP-area outlines', 'zorderz' ); ?></label></th><td>
				<input type="url" id="zch-shapes" class="regular-text" name="map_shapes" value="<?php echo esc_attr( $s['map_shapes'] ); ?>" placeholder="https://…/zip-areas.json">
				<p class="description"><?php esc_html_e( "A GeoJSON file of the ZIP areas your maps may fill (upload it to the media library and paste its address). The reference hub writes one from its region pack: python -m zorderz_hub region shapes <file>.", 'zorderz' ); ?></p></td></tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<h2><?php esc_html_e( 'Hourly data for the hub', 'zorderz' ); ?></h2>
		<p class="description"><?php esc_html_e( "Every hour this site sends the hub: new and changed invoices with their lines (when the billing ledger is on this site; the reference hub uses them once its feed is switched to live after its feed check says READY), each invoice's lead source, each linked job's lead source, and the measured counts Prep can read from a job's measurement note.", 'zorderz' ); ?></p>
		<?php echo ZCH_Feed::status_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>
		<?php echo ZCH_Counts::status_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'zch_counts_now' ); ?>
			<input type="hidden" name="action" value="zch_counts_now">
			<?php submit_button( __( 'Run a batch now', 'zorderz' ), 'secondary', 'submit', false ); ?>
		</form></div>
		<?php
	}

	private static function back( $arg ) {
		wp_safe_redirect( admin_url( ( self::allowed() ? 'admin.php?page=' : 'options-general.php?page=' ) . ZCH_SLUG . '-settings&' . $arg . '=1' ) );
		exit;
	}

	public static function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'zorderz' ) );
		}
		check_admin_referer( 'zch_settings' );
		$s                   = ZCH_Store::settings();
		$s['snapshots']      = ! empty( $_POST['snapshots'] );
		$s['bot_totals']     = ! empty( $_POST['bot_totals'] );
		$s['alert_email']    = sanitize_email( wp_unslash( $_POST['alert_email'] ?? '' ) );
		$s['counts_product'] = mb_substr( sanitize_text_field( wp_unslash( $_POST['counts_product'] ?? '' ) ), 0, 80 );
		$s['counts_match']   = mb_substr( sanitize_text_field( wp_unslash( $_POST['counts_match'] ?? '' ) ), 0, 40 );
		$shapes              = esc_url_raw( wp_unslash( $_POST['map_shapes'] ?? '' ), array( 'https' ) );
		$s['map_shapes']     = is_string( $shapes ) ? $shapes : '';
		update_option( ZCH_OPTION, $s, false );
		if ( ! $s['snapshots'] ) {
			global $wpdb;
			$wpdb->query( 'TRUNCATE TABLE ' . ZCH_Store::table( ZCH_TABLE_SNAPSHOT ) ); // phpcs:ignore WordPress.DB.PreparedSQL -- fixed table name.
		}
		self::back( 'updated' );
	}

	public static function save_hub() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'zorderz' ) );
		}
		check_admin_referer( 'zch_hub' );
		ZCH_Config::save(
			array(
				'url'            => esc_url_raw( wp_unslash( $_POST['url'] ?? '' ), array( 'https', 'http' ) ),
				'hub_public'     => sanitize_text_field( wp_unslash( $_POST['hub_public'] ?? '' ) ),
				'site_secret'    => sanitize_text_field( wp_unslash( $_POST['site_secret'] ?? '' ) ),
				'headers'        => trim( (string) wp_unslash( $_POST['headers'] ?? '' ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- JSON, validated by ZCH_Config::save and sealed.
				'forget_headers' => ! empty( $_POST['forget_headers'] ),
				'live_hosts'     => sanitize_text_field( wp_unslash( $_POST['live_hosts'] ?? '' ) ),
				'label'          => sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) ),
			)
		);
		delete_option( 'zch_last_fail' );
		ZCH_Store::audit( 'hub_config', true, null, null, null, null, 'admin' );
		self::back( 'updated' );
	}

	/** A new key pair for this site: the secret is kept sealed here; the public key is shown for the hub. */
	public static function keygen() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'zorderz' ) );
		}
		check_admin_referer( 'zch_keygen' );
		if ( ZCH_Config::from_constants() ) {
			self::back( 'constants' );
		}
		list( $secret ) = ZCH_Envelope::keygen();
		if ( ZCH_Config::managed() ) {
			// Managed on Zorderz → Credentials: the new key goes there, for every copy, backed up first.
			$vals                = ZDZ_Core_Credentials::current_values( ZCH_Config::CRED_SERVICE );
			$vals['site_secret'] = $secret;
			if ( '' !== ZDZ_Core_Credentials::apply( ZCH_Config::CRED_SERVICE, $vals, (int) get_current_user_id() ) ) {
				self::back( 'keys_failed' );
			}
			ZCH_Store::audit( 'keygen', true, null, null, null, null, 'admin' );
			self::back( 'keys' );
		}
		$h                = ZCH_Config::stored();
		$h['site_secret'] = ZCH_Config::seal( $secret );
		update_option( ZCH_Config::OPTION, $h, false );
		ZCH_Store::audit( 'keygen', true, null, null, null, null, 'admin' );
		self::back( 'keys' );
	}

	/* ── The monitor ────────────────────────────────────────────────────── */

	/** Is it business hours (the settings' hours and days) right now? */
	private static function business_now() {
		$s    = ZCH_Store::settings();
		$hour = (int) wp_date( 'G' );
		$day  = (int) wp_date( 'N' );
		return $hour >= $s['business_hours'][0] && $hour < $s['business_hours'][1] && in_array( $day, $s['business_days'], true );
	}

	/**
	 * The hub's nightly self-check puts one plain sentence in /health when something needs a
	 * person (an answer about a finished period moved, past years no longer match, the invoice
	 * feed or the data is old, the model is not loaded). One email per sentence, at most once a
	 * day, in business hours. The sentence carries codes' wording only: no figures, no names.
	 */
	public static function selfcheck_alert( $data ) {
		$text = isset( $data['attention'] ) && is_string( $data['attention'] ) ? trim( $data['attention'] ) : '';
		if ( '' === $text ) {
			delete_option( 'zch_selfcheck_alerted' );
			return false;
		}
		$text = mb_substr( wp_strip_all_tags( $text ), 0, 240 );
		$key  = md5( preg_replace( '/\s*\(self-check [^)]*\)$/', '', $text ) ) . '|' . wp_date( 'Y-m-d' );
		if ( get_option( 'zch_selfcheck_alerted' ) === $key ) {
			return false;
		}
		if ( ! self::business_now() ) {
			return false;
		}
		$s  = ZCH_Store::settings();
		$to = $s['alert_email'] ? $s['alert_email'] : get_option( 'admin_email' );
		/* translators: %s: what the business calls the hub machine */
		wp_mail( $to, __( 'Charts self-check: needs a look', 'zorderz' ), $text . "\n\n" . sprintf( __( "Charts still answers questions. The hub's self-check log on %s has the details.", 'zorderz' ), ZCH_Config::hub_label() ) );
		update_option( 'zch_selfcheck_alerted', $key, false );
		return true;
	}

	public static function health_check() {
		$client = ZCH_Hub_Client::from_config();
		if ( ! $client ) {
			return;
		}
		$reply = $client->call( 'health', array(), 'wp_cron', wp_date( 'Y-m-d' ) );
		if ( ! empty( $reply['ok'] ) ) {
			delete_option( 'zch_down_since' );
			delete_option( 'zch_alerted' );
			update_option( 'zch_last_ok', time(), false );
			self::selfcheck_alert( isset( $reply['data'] ) && is_array( $reply['data'] ) ? $reply['data'] : array() );
			return;
		}
		$since = (int) get_option( 'zch_down_since' );
		if ( ! $since ) {
			update_option( 'zch_down_since', time(), false );
			return;
		}
		if ( self::business_now() && time() - $since > 1800 && ! get_option( 'zch_alerted' ) ) {
			$s  = ZCH_Store::settings();
			$to = $s['alert_email'] ? $s['alert_email'] : get_option( 'admin_email' );
			wp_mail(
				$to,
				__( 'Charts hub unavailable', 'zorderz' ),
				/* translators: 1: since when, 2: what the business calls the hub machine */
				sprintf( __( "The Charts hub has not answered since %1\$s.\n\nCheck that %2\$s is awake and online, and that the hub service and anything in front of it (a tunnel or proxy) are running.", 'zorderz' ), wp_date( 'D j M, g:i a', $since ), ZCH_Config::hub_label() )
			);
			update_option( 'zch_alerted', 1, false );
		}
	}
}
