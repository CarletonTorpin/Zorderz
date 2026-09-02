<?php
/**
 * ZIB_Widget — the inline "Connected Email" card (P0 UI).
 *
 * Registers Inbox as a dashboard inline-widget app (bridge_type
 * 'inline_widget') and renders a small card the current user manages their OWN
 * mailbox connection from: Connect / Reconnect / Disconnect, the index-mode
 * picker (none · internal · external · all), and the "let admins search my
 * mailbox" opt-in. The card shell is server-rendered; assets/js/zib-connections.js
 * fills it from GET the connection route.
 *
 * KIOSK: the widget renders nothing for the read-only kiosk (INV-10) — mail is
 * personal, and Inbox has no read-only seat.
 *
 * @since 0.1.0 (P0 — Connect)
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIB_Widget {

	public static function register(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 20 );

		// MUST run inside after_setup_theme — the theme interface ZIB_App
		// implements isn't defined earlier (plugins_loaded fires before the
		// theme's functions.php is even loaded). Mirrors the house pattern.
		add_action( 'after_setup_theme', function () {
			add_filter( 'zdz_register_apps', array( __CLASS__, 'register_app' ) );
		} );
	}

	/** Only render for a logged-in, non-kiosk, feature-enabled user. */
	public static function should_render(): bool {
		return is_user_logged_in()
			&& zib_user_has_access()
			&& zib_user_can_write()
			&& class_exists( 'ZIB_Settings' )
			&& ZIB_Settings::feature_enabled();
	}

	public static function enqueue(): void {
		if ( is_admin() || ! self::should_render() ) {
			return;
		}
		$js  = ZIB_PLUGIN_DIR . 'assets/js/zib-connections.js';
		$css = ZIB_PLUGIN_DIR . 'assets/css/zib-connections.css';

		wp_enqueue_style(
			'zib-connections',
			ZIB_PLUGIN_URL . 'assets/css/zib-connections.css',
			array(),
			ZIB_VERSION . ( file_exists( $css ) ? '.' . filemtime( $css ) : '' )
		);
		wp_enqueue_script(
			'zib-connections',
			ZIB_PLUGIN_URL . 'assets/js/zib-connections.js',
			array(),
			ZIB_VERSION . ( file_exists( $js ) ? '.' . filemtime( $js ) : '' ),
			true
		);
		wp_localize_script( 'zib-connections', 'zibCfg', array(
			'restUrl'  => class_exists( 'ZIB_REST' ) ? ZIB_REST::base_url() : '',
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'startUrl' => class_exists( 'ZIB_OAuth' ) ? ZIB_OAuth::start_url() : '', // literal '&' — see start_url() docblock
			'modes'    => class_exists( 'ZIB_Connections' ) ? ZIB_Connections::MODES : array(),
		) );
	}

	/**
	 * The card shell — JS populates it from the REST connection state.
	 *
	 * Config (rest URL, nonce, start URL, mode options) is baked into
	 * data-attributes on the element, NOT relied upon from wp_localize_script —
	 * a JS optimizer (NitroPack) can strip or reorder an injected inline
	 * `var zibCfg`, but it cannot rewrite these attributes. The script reads
	 * from here first and falls back to window.zibCfg.
	 */
	public static function render_card_shell(): string {
		$labels = array(
			'none'     => 'Index nothing',
			'internal' => 'Employee ↔ employee',
			'external' => 'Customer ↔ employee',
			'all'      => 'Index all',
		);
		$opts = '';
		foreach ( $labels as $val => $label ) {
			$opts .= '<option value="' . esc_attr( $val ) . '">' . esc_html( $label ) . '</option>';
		}
		$rest_url = class_exists( 'ZIB_REST' ) ? ZIB_REST::base_url() : '';
		$start    = class_exists( 'ZIB_OAuth' ) ? ZIB_OAuth::start_url() : '';
		return '<div id="zib-card" class="zib-card"'
			. ' data-zib-rest="' . esc_attr( $rest_url ) . '"'
			. ' data-zib-nonce="' . esc_attr( wp_create_nonce( 'wp_rest' ) ) . '"'
			. ' data-zib-start="' . esc_attr( $start ) . '"'
			. ' data-zib-modeopts="' . esc_attr( $opts ) . '">'
			. '<div class="zib-loading">Loading your email connection…</div>'
			. '</div>';
	}

	/**
	 * Register the app tile (widget-only; the icon jumps to the card).
	 *
	 * This filter callback runs when the theme ENUMERATES apps — i.e. well
	 * after the theme has loaded — so the Widget_App_Interface is defined by
	 * now even though it was not when the plugin first loaded. The app-object
	 * class lives in its own file and is required lazily, because a file that
	 * `implements \Zorderz\Widget_App_Interface` fatals if required before
	 * the interface exists. A partial install (theme absent, or an old theme
	 * without this interface) declines cleanly — no tile, no error.
	 */
	public static function register_app( $apps ) {
		if ( ! interface_exists( '\Zorderz\Widget_App_Interface' ) ) {
			return $apps; // older/absent theme: no inline-widget tile (harmless)
		}
		if ( ! class_exists( 'ZIB_App' ) ) {
			require_once ZIB_PLUGIN_DIR . 'includes/class-zib-app.php';
		}
		if ( ! class_exists( 'ZIB_App' ) ) {
			return $apps;
		}
		$apps[ ZIB_APP_ID ] = new ZIB_App( array(
			'id'          => ZIB_APP_ID,
			'nm'          => 'Email',
			'icon'        => 'mail',
			'cat'         => 'Personal',
			'cc'          => '#7C3AED',
			'desc'        => 'Connect your Microsoft 365 mailbox so it can be indexed — privately, for you.',
			'roles'       => zib_roles(),
			'springboard' => true,
			'admin_url'   => home_url( '/' ),
		) );
		return $apps;
	}
}
