<?php
/**
 * ZAW_Admin -- Zorderz → Ai Workspace: the workspace itself (the same screen the dashboard tile opens) and its settings.
 *
 * The screen is a short thread (each message a turn in the same conversation, so a plan can be shown and answered with
 * "go"), each answer's cost, a Stop button while a turn runs, and a side panel with the hub runner's state and the
 * engine's month. Only a person who holds the workspace uses it; an owner-level account without it sees the switch.
 * The settings (per-answer cap, how many may hold it, effort, web search) are saved by an owner-level account only.
 *
 * @package Zorderz\AiWorkspace
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZAW_Admin {

	const PAGE   = 'zaw-workspace';
	const ACTION = 'zaw_save_settings';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 40 );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'save' ) );
	}

	public static function menu() {
		$parent = menu_page_url( 'zdz-core-settings', false ) ? 'zdz-core-settings' : 'options-general.php';
		add_submenu_page( $parent, __( 'Ai Workspace', 'zorderz' ), __( 'Ai Workspace', 'zorderz' ), 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
	}

	/** May this account change the settings? An owner-level account, never the shared device or Privacy View. */
	public static function may_configure( $uid ) {
		return '' === ZAW_Grant::refused( $uid ) && user_can( (int) $uid, 'manage_options' ) && ZAW_Grant::owner_level( $uid );
	}

	/** The page's and the tile's script config. */
	public static function config( $uid ) {
		$holders = array_keys( ZAW_Grant::all() );
		return array(
			'root'    => esc_url_raw( rest_url( ZDZ_REST_NS . ZAW_REST::BASE . '/' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'ready'   => ZAW_Turns::ready(),
			'missing' => ZAW_Turns::missing(),
			'granted' => ZAW_Grant::may_use( $uid ),
			'taken'   => ! ZAW_Grant::may_use( $uid ) && count( $holders ) >= ZAW_Grant::max(),
			'owner'   => ZAW_Grant::owner_level( $uid ) && user_can( (int) $uid, 'manage_options' ),
			'name'    => class_exists( 'ZDZ_Business_Profile' ) && method_exists( 'ZDZ_Business_Profile', 'name' ) ? (string) ZDZ_Business_Profile::name() : '',
			'cap'     => (float) ZAW_Settings::get( 'turn_usd' ),
			'version' => ZAW_VERSION,
		);
	}

	public static function enqueue( $uid ) {
		wp_enqueue_style( 'zaw-workspace', ZAW_URL . 'assets/workspace.css', array(), zaw_asset_ver( 'assets/workspace.css' ) );
		wp_enqueue_script( 'zaw-workspace', ZAW_URL . 'assets/workspace.js', array(), zaw_asset_ver( 'assets/workspace.js' ), true );
		wp_localize_script( 'zaw-workspace', 'ZAW_CONFIG', self::config( $uid ) );
	}

	/** The workspace's root element: the script draws everything in it. */
	public static function root() {
		return '<div class="zaw" id="zaw-app" aria-live="polite"><p class="zaw-mute">' . esc_html__( 'Loading…', 'zorderz' ) . '</p>'
			. '<noscript>' . esc_html__( 'The Ai Workspace needs JavaScript.', 'zorderz' ) . '</noscript></div>';
	}

	public static function render() {
		$uid = get_current_user_id();
		if ( ! current_user_can( 'manage_options' ) || '' !== ZAW_Grant::refused( $uid ) ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Not available on this account.', 'zorderz' ) . '</p></div>';
			return;
		}
		self::enqueue( $uid );
		$s = ZAW_Settings::all();
		echo '<div class="wrap zaw-wrap"><h1>' . esc_html__( 'Ai Workspace', 'zorderz' ) . '</h1>';
		echo '<p class="zaw-lead">' . esc_html__( 'Talks to the assistant beside the Charts hub. Customer details are coded before anything leaves this site and shown to you in plain words here. Each answer is held to its cap, and to what is left of the business\'s monthly Ai budget. Never on the shared device.', 'zorderz' ) . '</p>';
		if ( isset( $_GET['zaw_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a display flag only
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'zorderz' ) . '</p></div>';
		}
		echo self::root(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts
		if ( self::may_configure( $uid ) ) {
			?>
			<h2><?php esc_html_e( 'Settings', 'zorderz' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="zaw-turn-usd"><?php esc_html_e( 'Most one answer may cost (USD)', 'zorderz' ); ?></label></th>
						<td><input id="zaw-turn-usd" name="turn_usd" type="number" min="<?php echo esc_attr( ZAW_Settings::MIN_TURN_USD ); ?>" max="<?php echo esc_attr( ZAW_Settings::ceiling() ); ?>" step="0.05" value="<?php echo esc_attr( $s['turn_usd'] ); ?>"></td></tr>
					<tr><th scope="row"><label for="zaw-max"><?php esc_html_e( 'People who may hold it at once', 'zorderz' ); ?></label></th>
						<td><input id="zaw-max" name="max_grants" type="number" min="1" max="<?php echo esc_attr( ZAW_Settings::MAX_GRANTS ); ?>" value="<?php echo esc_attr( $s['max_grants'] ); ?>">
						<p class="description"><?php esc_html_e( 'Each owner-level account turns it on for itself.', 'zorderz' ); ?></p></td></tr>
					<tr><th scope="row"><label for="zaw-effort"><?php esc_html_e( 'Effort', 'zorderz' ); ?></label></th>
						<td><select id="zaw-effort" name="effort">
							<?php foreach ( ZAW_Settings::EFFORTS as $e ) : ?>
								<option value="<?php echo esc_attr( $e ); ?>" <?php selected( $s['effort'], $e ); ?>><?php echo esc_html( $e ); ?></option>
							<?php endforeach; ?>
						</select></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Web search', 'zorderz' ); ?></th>
						<td><label><input name="web" type="checkbox" value="1" <?php checked( $s['web'] ); ?>> <?php esc_html_e( 'Offer web search until a conversation holds business data (once any app has answered in it).', 'zorderz' ); ?></label></td></tr>
				</table>
				<?php submit_button( __( 'Save settings', 'zorderz' ) ); ?>
			</form>
			<?php
		}
		echo '</div>';
	}

	public static function save() {
		$uid = get_current_user_id();
		if ( ! self::may_configure( $uid ) ) {
			wp_die( esc_html__( 'Not available on this account.', 'zorderz' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );
		ZAW_Settings::save( array(
			'turn_usd'   => isset( $_POST['turn_usd'] ) ? (float) sanitize_text_field( wp_unslash( $_POST['turn_usd'] ) ) : ZAW_Settings::TURN_USD,
			'max_grants' => isset( $_POST['max_grants'] ) ? (int) sanitize_text_field( wp_unslash( $_POST['max_grants'] ) ) : 1,
			'effort'     => isset( $_POST['effort'] ) ? sanitize_key( wp_unslash( $_POST['effort'] ) ) : 'medium',
			'web'        => ! empty( $_POST['web'] ),
		) );
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'zaw_saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
