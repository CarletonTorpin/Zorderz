<?php
/**
 * Module: Zorderz - Route Planner
 * Description: Plans door-hanger routes and keeps drive-by neighborhood notes. "Plan 3 routes": an area (a ZIP, a
 *   neighborhood, or an address such as a past job's), a day, how many and how long; a neighborhood ranker connected
 *   to the platform ranks the area, Doorhangers' planWalks cuts it into walkable loops, and the planner scores each on
 *   the survey's confirmed tags, the ranker's fit, how long since anyone hung there, No-Solicit houses skipped, past
 *   customers nearby and its own Open-Meteo forecast for that day's walking window. Pick one, edit it block by block,
 *   or draw your own, then send it to Doorhangers by a press (Doorhangers checks every house on the server; who
 *   pressed is logged). The survey drive: say what you see ("every house on this street has a pool") and each
 *   note is pinned to the street segment it was said on, with suggested tags a person confirms; the screen is
 *   voice-only while the car moves; a recording of the drive can be read afterwards by a transcription service the
 *   platform connects, and every sentence lands on the street the car was on at that moment. Notes describe homes,
 *   never people. Access codes said or typed are sealed. Other apps read the confirmed tags through the read verb
 *   routeplanner.street_tags.
 * Version:     1.0.0
 * Author:      Zorderz
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: zorderz
 * Requires PHP: 8.0
 *
 * A separate app reached from inside Doorhangers (its Plan tab), reading Doorhangers and the ranker only through
 * registry verbs. Needs Doorhangers for streets, the map and the walking rule; without it the survey still records
 * notes (no map, no street until it is there).
 *
 * WHAT IS THE BUSINESS'S TO SET: the survey's vocabulary and the product line each tag points to (Core ships only
 * the two canvassing tags, "gated" and "no soliciting"; catalog.yml `survey_tags`), the re-hang window and the weather
 * limits (rules.yml `canvassing.*`; the defaults are starting values), which tags count against a route
 * (`zrp_plan_negative_tags`).
 *
 * @package Zorderz\RoutePlanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ZRP_VERSION', '1.0.0' );
define( 'ZRP_DB_VERSION', '1.0.0' );
define( 'ZRP_APP_ID', 'route-planner' );
define( 'ZRP_FILE', __FILE__ );
define( 'ZRP_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZRP_URL', plugin_dir_url( __FILE__ ) );

require_once ZRP_DIR . 'includes/zrp-names.php';
require_once ZRP_DIR . 'includes/class-zrp-vocab.php';
require_once ZRP_DIR . 'includes/class-zrp-db.php';
require_once ZRP_DIR . 'includes/class-zrp-snap.php';
require_once ZRP_DIR . 'includes/class-zrp-verb.php';
require_once ZRP_DIR . 'includes/class-zrp-rest.php';
require_once ZRP_DIR . 'includes/class-zrp-codes.php';
ZRP_Codes::boot();
require_once ZRP_DIR . 'includes/class-zrp-audio.php';
require_once ZRP_DIR . 'includes/class-zrp-weather.php';
require_once ZRP_DIR . 'includes/class-zrp-plan.php';

/* -------------------------------------------------------------------------
 * Settings (Zorderz -> Route Planner).
 * ---------------------------------------------------------------------- */

function zrp_default_settings() {
	return array(
		'readback'    => 0,    // the phone does not say back what it noted while driving unless turned on
		'rehang_days' => 90,   // a starting value: streets hung within this many days are left out of a plan
		'vocab_extra' => '',   // "slug | label | product slug | product label | phrase, phrase", one per line
		// Starting values for planning: no hanging when rain is likelier than this, or this much rain is forecast
		// across the day or the walking window, or the (steady) wind is over this. A business sets its own.
		'wx_rain_pct' => 25,
		'wx_rain_in'  => 0.5,
		'wx_wind_mph' => 10,   // "Steady wind over": the forecast's sustained wind; over it, the day is flagged
		'wx_gust_mph' => 20,   // "Gusts over": a warning and a lower score; over 1.5 x it, the day is flagged too; 0 = off
		'record'      => 1,    // the survey records audio (like a voice memo) for a transcription service to read after
		'everyone'    => 0,    // off: only people given the app hold it; on: sales and the admin tier hold it by role
	);
}

/** Clamp stored settings over the defaults. Pure. */
function zrp_clean_settings( $s ) {
	$s = is_array( $s ) ? array_merge( zrp_default_settings(), $s ) : zrp_default_settings();
	$s['readback']    = empty( $s['readback'] ) ? 0 : 1;
	$s['rehang_days'] = max( 1, min( 3650, (int) $s['rehang_days'] ) );
	$s['vocab_extra'] = (string) $s['vocab_extra'];
	$s['wx_rain_pct'] = max( 0, min( 100, (int) $s['wx_rain_pct'] ) );
	$s['wx_rain_in']  = max( 0.0, min( 10.0, round( (float) $s['wx_rain_in'], 2 ) ) );
	$s['wx_wind_mph'] = max( 0, min( 100, (int) $s['wx_wind_mph'] ) );
	$s['wx_gust_mph'] = max( 0, min( 150, (int) $s['wx_gust_mph'] ) );
	$s['record']      = empty( $s['record'] ) ? 0 : 1;
	$s['everyone']    = empty( $s['everyone'] ) ? 0 : 1;
	return $s;
}

function zrp_get_settings() {
	return zrp_clean_settings( function_exists( 'get_option' ) ? get_option( 'zrp_settings', array() ) : array() );
}

/* -------------------------------------------------------------------------
 * Access: who holds the app is the theme's to say; never the shared device.
 * ---------------------------------------------------------------------- */

function zrp_default_roles() {
	return array( 'administrator', 'zdz_owner', 'zdz_admin', 'zdz_sales' );
}

function zrp_is_kiosk( $uid ) {
	$uid = (int) $uid;
	if ( $uid <= 0 ) {
		return false;
	}
	if ( class_exists( 'ZDZ_Hierarchy' ) && method_exists( 'ZDZ_Hierarchy', 'is_kiosk' ) && ZDZ_Hierarchy::is_kiosk( $uid ) ) {
		return true;
	}
	if ( class_exists( 'ZDZ_User_Roles' ) && method_exists( 'ZDZ_User_Roles', 'is_shared_device' ) && ZDZ_User_Roles::is_shared_device( $uid ) ) {
		return true;
	}
	$u = get_userdata( $uid );
	return $u ? in_array( 'zdz_general', (array) $u->roles, true ) : false;
}

/**
 * The role grant, when an administrator turns it on: sales and the admin tier hold the app through the platform's
 * ADD-only per-person grant (each person otherwise keeps the app list they were made with). It never reaches the
 * shared device, and it comes after Safe Mode and the per-person deny list, so it cannot undo a removal. Pure.
 */
function zrp_role_grant( $apps, $user_id, array $settings ) {
	$apps = is_array( $apps ) ? $apps : array();
	$uid  = (int) $user_id;
	if ( $uid <= 0 || empty( $settings['everyone'] ) || in_array( ZRP_APP_ID, $apps, true ) ) {
		return $apps;
	}
	$u = get_userdata( $uid );
	if ( $u && ! zrp_is_kiosk( $uid ) && array_intersect( (array) $u->roles, zrp_default_roles() ) ) {
		$apps[] = ZRP_APP_ID;
	}
	return $apps;
}

add_filter(
	'zdz_extra_allowed_apps',
	function ( $apps, $user_id = 0 ) {
		return zrp_role_grant( $apps, $user_id, zrp_get_settings() );
	},
	10,
	2
);

function zrp_user_can_use( $uid ) {
	$uid = (int) $uid;
	if ( $uid <= 0 || zrp_is_kiosk( $uid ) ) {
		return false;
	}
	if ( class_exists( 'ZDZ_Plugin_API' ) && method_exists( 'ZDZ_Plugin_API', 'user_can_access_app' ) ) {
		return (bool) ZDZ_Plugin_API::user_can_access_app( $uid, ZRP_APP_ID );
	}
	$u = get_userdata( $uid );
	return $u ? ( user_can( $uid, 'manage_options' ) || (bool) array_intersect( (array) $u->roles, zrp_default_roles() ) ) : false;
}

function zrp_user_is_admin( $uid ) {
	$uid = (int) $uid;
	if ( $uid <= 0 || zrp_is_kiosk( $uid ) ) {
		return false;
	}
	if ( class_exists( 'ZDZ_Capabilities' ) && method_exists( 'ZDZ_Capabilities', 'tier_of' ) ) {
		return 'admin' === ZDZ_Capabilities::tier_of( $uid );
	}
	$u = get_userdata( $uid );
	return $u ? ( user_can( $uid, 'manage_options' ) || (bool) array_intersect( (array) $u->roles, array( 'administrator', 'zdz_owner', 'zdz_admin' ) ) ) : false;
}

/**
 * May this person see house numbers? Doorhangers gives them only to its own holders (doorhangers.area_houses), so the
 * planner never hands a "this house" number to a person Doorhangers would not.
 */
function zrp_may_see_numbers( $uid ) {
	return function_exists( 'zdh_user_can_use' ) && zdh_user_can_use( (int) $uid );
}

/** Words as another person may read them: no digits unless they may see house numbers. */
function zrp_said_for( $said, $uid ) {
	return zrp_may_see_numbers( $uid ) ? (string) $said : preg_replace( '/\b\d+\b/', '[number]', (string) $said );
}

/** The street key Doorhangers files streets by ("Example Drive" = "Example Dr"); a plain fallback without it. */
function zrp_street_key( $name ) {
	if ( class_exists( 'ZDH_DB' ) && method_exists( 'ZDH_DB', 'street_key' ) ) {
		return (string) ZDH_DB::street_key( (string) $name );
	}
	return strtolower( trim( preg_replace( '/\s+/', ' ', preg_replace( '/[^a-z0-9 ]/i', ' ', (string) $name ) ) ) );
}

/* -------------------------------------------------------------------------
 * Install, upgrade, the theme's seams.
 * ---------------------------------------------------------------------- */

function zrp_activate() {
	ZRP_DB::create_tables();
	update_option( 'zrp_db_version', ZRP_DB_VERSION );
}

function zrp_deactivate() {
	wp_clear_scheduled_hook( ZRP_Audio::HOOK );
}

/* Recordings waiting on the transcription service move every 5 minutes, each as its owner (ZRP_Audio::tick). */
add_filter( 'cron_schedules', array( 'ZRP_Audio', 'schedules' ) );
add_action( ZRP_Audio::HOOK, array( 'ZRP_Audio', 'tick' ) );
add_action( 'init', array( 'ZRP_Audio', 'ensure_cron' ) );

add_action( 'plugins_loaded', function () {
	if ( get_option( 'zrp_db_version' ) !== ZRP_DB_VERSION ) {
		zrp_activate(); // a bundle replaced by upload is not re-activated
	}
	ZRP_REST::init();
} );

add_filter( 'zdz_register_capabilities', function ( $caps ) {
	$caps = is_array( $caps ) ? $caps : array();
	$d    = ZRP_Verb::descriptor();
	$caps[ $d['verb'] ] = $d;
	return $caps;
}, 20 );

/**
 * An install that ran this app under its earlier option, table and app-id names is carried across by the platform's
 * rename migration. Declared here; the theme performs it. A fresh install has none of these.
 */
add_filter( 'zdz_rename_map', function ( $map ) {
	$map['tables'] = array_merge( $map['tables'] ?? array(), array(
		'tsrp_drives'    => 'zrp_drives',
		'tsrp_notes'     => 'zrp_notes',
		'tsrp_note_tags' => 'zrp_note_tags',
		'tsrp_codes'     => 'zrp_codes',
		'tsrp_audio'     => 'zrp_audio',
		'tsrp_sends'     => 'zrp_sends',
	) );
	$map['options'] = array_merge( $map['options'] ?? array(), array(
		'tsrp_settings'   => 'zrp_settings',
		'tsrp_db_version' => 'zrp_db_version',
	) );
	$map['cron'] = array_merge( $map['cron'] ?? array(), array(
		'tsrp_audio_tick' => 'zrp_audio_tick',
	) );
	$map['app_ids'] = array_merge( $map['app_ids'] ?? array(), array(
		'routeplanner' => ZRP_APP_ID,
	) );
	return $map;
} );

add_action( 'after_setup_theme', function () {
	if ( ! interface_exists( '\Zorderz\Widget_App_Interface' ) ) {
		return;
	}

	class ZRP_App implements \Zorderz\Widget_App_Interface {

		public function get_config(): array {
			return array(
				'id'          => ZRP_APP_ID,
				'nm'          => __( 'Route Planner', 'zorderz' ),
				'icon'        => 'route',
				'cat'         => 'field',
				'cc'          => '#009E73',
				'desc'        => __( 'Plan door-hanger routes from the forecast and drive-by notes; drive a neighborhood and say what you see.', 'zorderz' ),
				'roles'       => zrp_default_roles(), // never zdz_general (the shared device)
				'bridge_type' => 'inline_widget',
				'admin_url'   => admin_url( 'admin.php?page=zrp-settings' ),
			);
		}

		public function render_mobile_view( int $user_id ): void {
			echo (string) $this->render_dashboard_widget( $user_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup built below from fixed strings
		}

		public function render_dashboard_widget( int $user_id ): ?string {
			if ( ! zrp_user_can_use( $user_id ) ) {
				return null;
			}
			zrp_enqueue_app( $user_id );
			return '<div class="zrp-app" id="zrp-app" data-zdz-ask="off">'
				. '<div class="zdz-loading" role="status"><span class="zdz-loading-spinner" aria-hidden="true"></span><span class="zdz-loading-label">' . esc_html__( 'Loading the Route Planner…', 'zorderz' ) . '</span></div>'
				. '<noscript>' . esc_html__( 'The Route Planner needs JavaScript.', 'zorderz' ) . '</noscript></div>';
		}
	}

	add_filter( 'zdz_register_apps', function ( $apps ) {
		$apps[] = new ZRP_App();
		return $apps;
	} );
} );

/**
 * The page. The map is Doorhangers' own Leaflet (the same file, so one copy loads when both apps are on the screen);
 * the walking-vs-driving rule is Doorhangers' pace.js with Doorhangers' own limits: one rule. Without Doorhangers the
 * survey still records notes (no map, no street until it is there; the driving rule's defaults).
 */
function zrp_enqueue_app( $user_id ) {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	$deps = array();
	$dh   = defined( 'ZDH_URL' );
	if ( $dh ) {
		wp_enqueue_style( 'zdh-leaflet', ZDH_URL . 'assets/vendor/leaflet/leaflet.css', array(), '1.9.4' );
		wp_enqueue_script( 'zdh-leaflet', ZDH_URL . 'assets/vendor/leaflet/leaflet.js', array(), '1.9.4', true );
		$deps[] = 'zdh-leaflet';
		if ( function_exists( 'zdh_register_shared_scripts' ) ) {
			zdh_register_shared_scripts();
			$deps[] = 'zdh-pace';
			$deps[] = 'zdh-walks'; // planWalks, Doorhangers' own (shared code, not shared data)
		}
	}
	if ( wp_script_is( 'zdz-kit-js', 'registered' ) || wp_script_is( 'zdz-kit-js', 'enqueued' ) ) {
		$deps[] = 'zdz-kit-js';
	}
	wp_enqueue_style( 'zrp-app', ZRP_URL . 'assets/app.css', array(), ZRP_VERSION );
	wp_enqueue_script( 'zrp-plan', ZRP_URL . 'assets/plan.js', array(), ZRP_VERSION, true ); // scoring and choosing routes (pure)
	$deps[] = 'zrp-plan';
	wp_enqueue_script( 'zrp-app', ZRP_URL . 'assets/app.js', $deps, ZRP_VERSION, true );
	$u = get_userdata( (int) $user_id );
	wp_localize_script( 'zrp-app', 'ZRP_CONFIG', array(
		'rest'          => esc_url_raw( rest_url( ZRP_REST::ns() . ZRP_REST::BASE . '/' ) ),
		'nonce'         => wp_create_nonce( 'wp_rest' ),
		'version'       => ZRP_VERSION,
		'userId'        => (int) $user_id,
		'userName'      => $u ? $u->display_name : '',
		'isAdmin'       => zrp_user_is_admin( $user_id ) ? '1' : '',
		'pace'          => function_exists( 'zdh_pace_limits' ) ? zdh_pace_limits() : array(),
		'map'           => $dh ? '1' : '',
		'mapCenter'     => array( 'point' => function_exists( 'zdh_map_center' ) ? zdh_map_center() : null ),
		'perHour'       => (int) round( ZRP_Plan::per_hour() ),
		'ajax'          => admin_url( 'admin-ajax.php' ), // WordPress's own rest-nonce refresh, for a tab left open overnight
		'leafletImages' => $dh ? ZDH_URL . 'assets/vendor/leaflet/images/' : '',
	) );
}

/* -------------------------------------------------------------------------
 * Zorderz -> Route Planner (wp-admin): the vocabulary and the planning limits.
 * ---------------------------------------------------------------------- */

add_action( 'admin_menu', function () {
	$parent = menu_page_url( 'zdz-core-settings', false ) ? 'zdz-core-settings' : 'options-general.php';
	add_submenu_page( $parent, __( 'Route Planner', 'zorderz' ), __( 'Route Planner', 'zorderz' ), 'manage_options', 'zrp-settings', 'zrp_admin_page' );
}, 41 );

function zrp_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( isset( $_POST['zrp_save'] ) && check_admin_referer( 'zrp_save' ) ) {
		$extra = isset( $_POST['vocab_extra'] ) ? sanitize_textarea_field( wp_unslash( $_POST['vocab_extra'] ) ) : '';
		update_option( 'zrp_settings', zrp_clean_settings( array(
			'readback'    => empty( $_POST['readback'] ) ? 0 : 1,
			'rehang_days' => isset( $_POST['rehang_days'] ) ? (int) $_POST['rehang_days'] : 90,
			'vocab_extra' => mb_substr( $extra, 0, 8000 ),
			'wx_rain_pct' => isset( $_POST['wx_rain_pct'] ) ? (int) $_POST['wx_rain_pct'] : 25,
			'wx_rain_in'  => isset( $_POST['wx_rain_in'] ) ? (float) $_POST['wx_rain_in'] : 0.5,
			'wx_wind_mph' => isset( $_POST['wx_wind_mph'] ) ? (int) $_POST['wx_wind_mph'] : 10,
			'wx_gust_mph' => isset( $_POST['wx_gust_mph'] ) ? (int) $_POST['wx_gust_mph'] : 20,
			'record'      => empty( $_POST['record'] ) ? 0 : 1,
			'everyone'    => empty( $_POST['everyone'] ) ? 0 : 1,
		) ) );
		echo '<div class="notice notice-success"><p>' . esc_html__( 'Saved.', 'zorderz' ) . '</p></div>';
	}
	$codes_note = zrp_codes_admin_post();
	$s = zrp_get_settings();
	$v = ZRP_Vocab::vocabulary();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Route Planner', 'zorderz' ); ?></h1>
		<p><?php esc_html_e( 'Plan door-hanger routes (the Plan tab): a neighborhood ranker connected to the platform ranks the area, Doorhangers\' walks are scored on the survey\'s confirmed tags, the ranker\'s fit, the re-hang window, No-Solicit houses, past customers nearby and the forecast below, and a route is sent to Doorhangers by a press.', 'zorderz' ); ?>
		<?php if ( ! ZRP_Plan::ranker_ready() ) : ?><strong><?php esc_html_e( 'No neighborhood ranker is connected: routes can be drawn on the map, not ranked.', 'zorderz' ); ?></strong><?php endif; ?></p>
		<p><?php esc_html_e( 'Drive-by notes: start a survey drive in the app, say what you see, and each note lands on its street. Tags count only once a person confirms them. Other apps read them through', 'zorderz' ); ?> <code>routeplanner.street_tags</code>.</p>
		<form method="post">
			<?php wp_nonce_field( 'zrp_save' ); ?>
			<p><label><input type="checkbox" name="everyone" value="1" <?php checked( $s['everyone'] ); ?>> <?php esc_html_e( 'Sales and the admin tier hold the Route Planner by role (off: only the people given it in User Management; it can still be taken from one person there).', 'zorderz' ); ?></label></p>
			<p><label><input type="checkbox" name="readback" value="1" <?php checked( $s['readback'] ); ?>> <?php esc_html_e( 'The phone says back what it noted while driving (off by default).', 'zorderz' ); ?></label></p>
			<p><label><input type="checkbox" name="record" value="1" <?php checked( $s['record'] ); ?>> <?php esc_html_e( 'Survey drives record audio, like a voice memo; a transcription service connected to the platform reads it and places each sentence on the drive afterwards. Off: the phone\'s own live dictation.', 'zorderz' ); ?></label>
			<?php if ( ! ZRP_Audio::reader_ready() ) : ?><br><em><?php esc_html_e( 'No transcription service is connected yet, so recordings wait until one is.', 'zorderz' ); ?></em><?php endif; ?></p>
			<p><?php esc_html_e( 'Starting values; set your own:', 'zorderz' ); ?></p>
			<p><?php esc_html_e( 'No hanging when: rain chance over', 'zorderz' ); ?> <input type="number" name="wx_rain_pct" min="0" max="100" value="<?php echo (int) $s['wx_rain_pct']; ?>" style="width:5em">%, <?php esc_html_e( 'or over', 'zorderz' ); ?> <input type="number" step="0.05" name="wx_rain_in" min="0" max="10" value="<?php echo esc_attr( $s['wx_rain_in'] ); ?>" style="width:5em"> <?php esc_html_e( 'inches of rain, in the walking window. Used when planning routes.', 'zorderz' ); ?></p>
			<p><label><?php esc_html_e( 'Steady wind over', 'zorderz' ); ?> <input type="number" name="wx_wind_mph" min="0" max="100" value="<?php echo (int) $s['wx_wind_mph']; ?>" style="width:5em"> mph</label>: <?php esc_html_e( 'not a hanging day (the forecast\'s sustained wind in the walking window).', 'zorderz' ); ?></p>
			<p><label><?php esc_html_e( 'Gusts over', 'zorderz' ); ?> <input type="number" name="wx_gust_mph" min="0" max="150" value="<?php echo (int) $s['wx_gust_mph']; ?>" style="width:5em"> mph</label>: <?php esc_html_e( 'still a hanging day, but each route there scores lower and says "Gusty: hangers may blow off doors".', 'zorderz' ); ?><?php if ( $s['wx_gust_mph'] > 0 ) : ?> <?php echo esc_html( sprintf( __( 'Gusts over %1$s times this (%2$s mph now) make it not a hanging day.', 'zorderz' ), rtrim( rtrim( number_format( ZRP_Weather::gust_flag(), 1 ), '0' ), '.' ), rtrim( rtrim( number_format( $s['wx_gust_mph'] * ZRP_Weather::gust_flag(), 1 ), '0' ), '.' ) ) ); ?><?php else : ?> <strong><?php esc_html_e( 'Off now', 'zorderz' ); ?></strong>: <?php esc_html_e( 'gusts are shown, not judged.', 'zorderz' ); ?><?php endif; ?> <?php esc_html_e( '0 turns the gust check off. Keep it above the steady wind limit: gusts are never below the steady wind, so a lower number makes nearly every day gusty.', 'zorderz' ); ?><?php if ( $s['wx_gust_mph'] > 0 && $s['wx_gust_mph'] <= $s['wx_wind_mph'] ) : ?> <strong><?php esc_html_e( 'It is not above it now.', 'zorderz' ); ?></strong><?php endif; ?></p>
			<p><label><?php esc_html_e( 'Re-hang window (days), for planning routes:', 'zorderz' ); ?> <input type="number" name="rehang_days" min="1" max="3650" value="<?php echo (int) $s['rehang_days']; ?>"></label></p>
			<h2><?php esc_html_e( 'What the survey listens for', 'zorderz' ); ?></h2>
			<table class="widefat striped" style="max-width:900px"><thead><tr><th><?php esc_html_e( 'Tag', 'zorderz' ); ?></th><th><?php esc_html_e( 'Product line', 'zorderz' ); ?></th><th><?php esc_html_e( 'Phrases', 'zorderz' ); ?></th></tr></thead><tbody>
			<?php foreach ( $v as $slug => $row ) : ?>
				<tr><td><strong><?php echo esc_html( $row['label'] ); ?></strong><br><code><?php echo esc_html( $slug ); ?></code></td><td><?php echo esc_html( $row['product_label'] ?: '—' ); ?></td><td><?php echo esc_html( implode( ', ', $row['phrases'] ) ); ?></td></tr>
			<?php endforeach; ?>
			</tbody></table>
			<p><label for="zrp-vocab"><?php esc_html_e( 'Add tags or phrases, one per line:', 'zorderz' ); ?> <code>slug | label | product slug | product label | phrase, phrase</code>. <?php esc_html_e( 'An existing slug gets the extra phrases.', 'zorderz' ); ?></label><br>
			<textarea id="zrp-vocab" name="vocab_extra" rows="6" cols="100" placeholder="corner_lots | Corner lots | example_product | Example product | corner lot, on the corner"><?php echo esc_textarea( $s['vocab_extra'] ); ?></textarea></p>
			<p><button class="button button-primary" name="zrp_save" value="1"><?php esc_html_e( 'Save', 'zorderz' ); ?></button></p>
		</form>
		<?php zrp_codes_admin_section( $codes_note ); ?>
	</div>
	<?php
}

/**
 * Zorderz -> Route Planner, Gate codes: the press that moves the codes this app kept in its own box (while the vault was
 * not set up) into the vault, and the one that seals codes still written in drive notes. Each is counted first, then
 * runs only when an administrator presses (nonce, manage_options), for up to 20 seconds a press.
 *
 * @return array{what:string,count:?array,result:string} what the last press asked and found
 */
function zrp_codes_admin_post() {
	$note = array( 'what' => '', 'count' => null, 'result' => '' );
	if ( ! current_user_can( 'manage_options' ) || empty( $_POST['zrp_codes'] ) ) {
		return $note;
	}
	check_admin_referer( 'zrp_codes' );
	$what = sanitize_key( wp_unslash( $_POST['zrp_codes'] ) );
	if ( 'count_notes' === $what ) {
		$note['what']  = 'notes';
		$note['count'] = ZRP_Codes::count_notes();
		return $note;
	}
	if ( ! in_array( $what, array( 'move', 'notes' ), true ) || ! ZRP_Codes::vault_on() ) {
		return $note;
	}
	$out            = ZRP_Codes::admin_run( $what, microtime( true ) + 20 );
	$note['what']   = $what;
	$note['result'] = ZRP_Codes::result_words( $out, $what );
	return $note;
}

/** The Gate codes section of Zorderz -> Route Planner (counts, then the presses). */
function zrp_codes_admin_section( array $note ) {
	$vault = ZRP_Codes::vault_on();
	$own   = ZRP_Codes::count_unmoved();
	?>
	<h2 id="zrp-codes"><?php esc_html_e( 'Gate codes', 'zorderz' ); ?></h2>
	<?php if ( '' !== $note['result'] ) : ?>
		<div class="notice notice-info"><p><?php echo esc_html( $note['result'] ); ?></p></div>
	<?php endif; ?>
	<?php if ( ! $vault ) : ?>
		<p><?php esc_html_e( 'The gate code vault is not set up on this site (Zorderz -> Gate code key), so codes are kept sealed in this app\'s own box. Once it is, they can be moved into it here.', 'zorderz' ); ?></p>
	<?php endif; ?>
	<form method="post" action="#zrp-codes">
		<?php wp_nonce_field( 'zrp_codes' ); ?>
		<p>
			<?php
			echo esc_html( sprintf(
				/* translators: %d: codes kept in the app's own box */
				_n( '%d code is kept in this app\'s own box, not in the vault.', '%d codes are kept in this app\'s own box, not in the vault.', (int) $own['rows'], 'zorderz' ),
				(int) $own['rows']
			) );
			?>
			<?php if ( $vault && (int) $own['rows'] > 0 ) : ?>
				<button class="button" name="zrp_codes" value="move"><?php esc_html_e( 'Move them into the vault', 'zorderz' ); ?></button>
			<?php endif; ?>
		</p>
		<p>
			<?php esc_html_e( 'Drive notes may still hold a code this app\'s reader missed when the note was taken.', 'zorderz' ); ?>
			<button class="button" name="zrp_codes" value="count_notes"><?php esc_html_e( 'Look for them', 'zorderz' ); ?></button>
			<?php if ( 'notes' === $note['what'] && is_array( $note['count'] ) ) : ?>
				<br><?php
				echo esc_html( sprintf(
					/* translators: 1: codes found, 2: notes they are in */
					__( 'Found %1$d code(s) in %2$d note(s).', 'zorderz' ),
					(int) $note['count']['codes'],
					(int) $note['count']['rows']
				) );
				?>
				<?php if ( $vault && (int) $note['count']['codes'] > 0 ) : ?>
					<button class="button" name="zrp_codes" value="notes"><?php esc_html_e( 'Seal them under their street', 'zorderz' ); ?></button>
				<?php endif; ?>
			<?php endif; ?>
		</p>
	</form>
	<?php
}
