<?php
/**
 * Module: Zorderz - Doorhangers
 * Description: Door-hanger routes: plan an area on the map, walk it with GPS ticking off houses, log the walk with
 *   weather and neighborhood context, look an address up ("did they get a hanger?"), digitize old screenshots and
 *   paper maps (a stack of photos or a PDF of printed route sheets, each read and lined up with the street map),
 *   cross-check a person's CRM customers, export. Ships with NO business data: no areas, no walkers, no offer.
 * Version:     1.0.0
 * Author:      Zorderz
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: zorderz
 * Requires PHP: 8.0
 *
 * This is a bundled app module (loaded by zorderz-apps.php), not a standalone plugin. It registers with the theme
 * through the `zdz_register_apps` filter on after_setup_theme and declines cleanly when the theme is absent.
 *
 * WHAT IS THE BUSINESS'S TO SET (Core ships each one empty or neutral):
 *   - The area it works in: city, state/region and county default to the Business Profile address; the map opens on
 *     the `map_center` setting, else on the Business Profile address (rounded to about a kilometre), else on a world
 *     view. Pack: territories.yml `canvassing.default_area`, `canvassing.map_center`.
 *   - Where house addresses come from: a regional GIS address layer (`zdh_address_source`, see ZDH_Geo), else the open
 *     street map. Pack: territories.yml `canvassing.address_source`.
 *   - Hazard map layers for the walk log (`zdh_hazard_layers`). Pack: territories.yml `canvassing.hazard_layers`.
 *   - The service the hanger advertises and the hanger types. Pack: catalog.yml `canvassing.service`, `.hanger_types`.
 *   - The walking pace and re-visit numbers below are starting values. Pack: rules.yml `canvassing.*`.
 *
 * SAFETY FLOORS (a business may narrow them, never widen them):
 *   - A house with a "No Soliciting" sign is never on a route, never ticked, never in a list a route is made from.
 *   - Access codes in a route's notes are sealed in the theme's vault (ZDZ_Sealed); without the vault they are cut,
 *     never kept in plain text.
 *   - The shared device (kiosk) never holds the app.
 *
 * @package Zorderz\Doorhangers
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ZDH_VERSION', '1.0.0' );
define( 'ZDH_DB_VERSION', '1.0.0' );         // the schema's own number; bump when create_tables() changes
define( 'ZDH_APP_ID', 'doorhangers' );       // the app id the theme knows (tiles, access, groups, capabilities)
define( 'ZDH_MAPLIBRE_REL', '5.24.0' );      // assets/vendor/maplibre/maplibre-gl.js (+ leaflet-maplibre-gl.js 0.1.4)
define( 'ZDH_FILE', __FILE__ );
define( 'ZDH_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZDH_URL', plugin_dir_url( __FILE__ ) );

require_once ZDH_DIR . 'includes/class-zdh-db.php';
require_once ZDH_DIR . 'includes/class-zdh-geo.php';
require_once ZDH_DIR . 'includes/class-zdh-local.php';
require_once ZDH_DIR . 'includes/class-zdh-llm.php';
require_once ZDH_DIR . 'includes/class-zdh-enrich.php';
require_once ZDH_DIR . 'includes/class-zdh-nutshell.php';
require_once ZDH_DIR . 'includes/class-zdh-import.php';
require_once ZDH_DIR . 'includes/class-zdh-nosolicit.php';
require_once ZDH_DIR . 'includes/class-zdh-plan.php';
require_once ZDH_DIR . 'includes/class-zdh-bridge.php';
require_once ZDH_DIR . 'includes/class-zdh-area.php';
require_once ZDH_DIR . 'includes/class-zdh-rest.php';
require_once ZDH_DIR . 'includes/class-zdh-sealed.php';
ZDH_Sealed::boot();

/* -------------------------------------------------------------------------
 * Settings. No key lives here: the AI key and the CRM login are the platform's (Zorderz -> Settings). No role list:
 * the theme decides who holds the app. No page, no password, no layout switch.
 * ---------------------------------------------------------------------- */

function zdh_default_settings() {
	return array(
		'hanger_types'   => 'Standard',
		'service'        => '',     // the service the hanger advertises, stored on each drop (catalog.yml)
		'default_city'   => '',     // '' = the Business Profile's town
		'default_state'  => '',     // '' = the Business Profile's state or region
		'default_county' => '',     // '' = none; the reader's prompt then names only the state
		'map_center'     => '',     // "lat,lng" where the map opens; '' = the Business Profile address, else a world view
		'census_on'      => 0,      // US Census neighborhood figures for the walk log (a US-only public service)
		'census_api_key' => '',     // free, not a secret: the Census Data API needs one for the neighborhood numbers
		'osm_fallback'   => 1,      // ask the open street map when no regional address layer answers
		'revisit_days'   => 90,
		'auto_radius_m'  => 25,
		'walk_size'      => 60,
		'walk_rate'      => 40,
		'route_style'    => 'loop',
		'nutshell_user'  => '',     // the site default CRM user for the cross-check ("12-users"); a person's own wins
		'nutshell_user_name' => '',
		'paper_key'      => '',
		// Walking vs. driving. Driving = faster than pace_drive_mph for pace_drive_s; walking again = slower than
		// pace_walk_mph for pace_walk_s. Brisk walking is 3-4 mph and nobody hangs doors at a run, so these sit just
		// above walking speed; a business changes them after its own field test.
		'pace_drive_mph' => 6,
		'pace_drive_s'   => 15,
		'pace_walk_mph'  => 4.5,
		'pace_walk_s'    => 15,
		'pace_acc_m'     => 15,     // a fix looser than this says nothing about speed
		'pace_jump_m'    => 300,    // this far between two fixes (under 90 s apart) is impossible on foot
		// "Next block in 200 feet" spoken in pocket mode. Off until an administrator turns it on; even then each phone
		// turns it on for itself in pocket mode.
		'nav_voice'      => 0,
	);
}

/** The walking-vs-driving limits, clamped so walking's limit is always below driving's (else both could be true). */
function zdh_pace_limits() {
	$s = zdh_get_settings();
	return zdh_pace_limits_from( $s );
}

/** The clamp itself, pure: the same numbers the settings page and the phone use. */
function zdh_pace_limits_from( array $s ) {
	$d     = zdh_default_settings();
	$s     = array_merge( $d, $s );
	$drive = max( 3.0, min( 30.0, (float) $s['pace_drive_mph'] ) );
	$walk  = max( 1.0, min( $drive - 0.5, (float) $s['pace_walk_mph'] ) );
	return array(
		'driveMph' => round( $drive, 1 ),
		'driveS'   => max( 5, min( 120, (int) $s['pace_drive_s'] ) ),
		'walkMph'  => round( $walk, 1 ),
		'walkS'    => max( 5, min( 120, (int) $s['pace_walk_s'] ) ),
		'accM'     => max( 5, min( 50, (int) $s['pace_acc_m'] ) ),
		'jumpM'    => max( 100, min( 2000, (int) $s['pace_jump_m'] ) ),
	);
}

function zdh_get_settings() {
	$saved = function_exists( 'get_option' ) ? get_option( 'zdh_settings', array() ) : array();
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	return array_merge( zdh_default_settings(), $saved );
}

function zdh_list_setting( $key ) {
	$s     = zdh_get_settings();
	$lines = preg_split( '/\r\n|\r|\n/', (string) ( $s[ $key ] ?? '' ) );
	$out   = array();
	foreach ( $lines as $l ) {
		$l = trim( $l );
		if ( $l !== '' ) {
			$out[] = $l;
		}
	}
	return $out;
}

/** A Business Profile value, or '' when the profile is not there. */
function zdh_profile( $path ) {
	if ( class_exists( 'ZDZ_Business_Profile' ) && method_exists( 'ZDZ_Business_Profile', 'get' ) ) {
		return trim( (string) ZDZ_Business_Profile::get( $path, '' ) );
	}
	return '';
}

/**
 * The area the app works in by default: the setting when one is set, else the Business Profile address. Never a
 * place of the platform's own choosing: with neither, each value is ''.
 */
function zdh_area_defaults() {
	$s = zdh_get_settings();
	$city   = trim( (string) $s['default_city'] );
	$state  = trim( (string) $s['default_state'] );
	$county = trim( (string) $s['default_county'] );
	return array(
		'city'   => '' !== $city ? $city : zdh_profile( 'contact.address.locality' ),
		'state'  => '' !== $state ? $state : zdh_profile( 'contact.address.region' ),
		'county' => $county,
		'zip'    => zdh_profile( 'contact.address.postal' ),
	);
}

/**
 * "lat,lng" -> [lat, lng] rounded to 4 places, or null when it is not a point on the globe.
 */
function zdh_parse_point( $raw ) {
	if ( ! is_string( $raw ) || ! preg_match( '/^\s*(-?\d{1,2}(?:\.\d+)?)\s*,\s*(-?\d{1,3}(?:\.\d+)?)\s*$/', $raw, $m ) ) {
		return null;
	}
	$lat = (float) $m[1];
	$lng = (float) $m[2];
	if ( abs( $lat ) > 90 || abs( $lng ) > 180 ) {
		return null;
	}
	return array( round( $lat, 4 ), round( $lng, 4 ) );
}

/**
 * Where the map opens: the map_center setting; else the Business Profile address, resolved by the platform's
 * geocoder and rounded to two decimals (about a kilometre: a map centre, never the address itself); else null, and
 * the map opens on a world view until the walker's own position or a route says where to look.
 */
function zdh_map_center() {
	$s  = zdh_get_settings();
	$pt = zdh_parse_point( (string) $s['map_center'] );
	if ( $pt ) {
		return $pt;
	}
	if ( class_exists( 'ZDZ_Geocoder' ) && method_exists( 'ZDZ_Geocoder', 'resolve_address' ) ) {
		$street = zdh_profile( 'contact.address.street' );
		$city   = zdh_profile( 'contact.address.locality' );
		if ( '' !== $street || '' !== $city ) {
			$r = ZDZ_Geocoder::resolve_address( array(
				'street'  => $street,
				'city'    => $city,
				'state'   => zdh_profile( 'contact.address.region' ),
				'zip'     => zdh_profile( 'contact.address.postal' ),
				'country' => zdh_profile( 'contact.address.country' ),
			) );
			if ( is_array( $r ) && isset( $r['lat'], $r['lng'] ) && is_numeric( $r['lat'] ) && is_numeric( $r['lng'] ) ) {
				return array( round( (float) $r['lat'], 2 ), round( (float) $r['lng'], 2 ) );
			}
		}
	}
	return null;
}

/** The business's timezone: the Business Profile's, else the site's. "Today" for a route is that day there. */
function zdh_timezone() {
	$tz = zdh_profile( 'locale.timezone' );
	if ( '' !== $tz ) {
		try {
			return new DateTimeZone( $tz );
		} catch ( Exception $e ) {
			// fall through to the site's own
		}
	}
	if ( function_exists( 'wp_timezone' ) ) {
		return wp_timezone();
	}
	return new DateTimeZone( 'UTC' );
}

/* -------------------------------------------------------------------------
 * Access. The theme's ladder decides who HOLDS the app; the shared device never does; the admin tier may delete,
 * import and look at diagnostics. Every helper degrades on an older theme to the role list in get_config().
 * ---------------------------------------------------------------------- */

function zdh_is_kiosk( $uid ) {
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

/** May this person use Doorhangers at all? */
function zdh_user_can_use( $uid ) {
	$uid = (int) $uid;
	if ( $uid <= 0 || zdh_is_kiosk( $uid ) ) {
		return false;
	}
	if ( class_exists( 'ZDZ_Plugin_API' ) && method_exists( 'ZDZ_Plugin_API', 'user_can_access_app' ) ) {
		return (bool) ZDZ_Plugin_API::user_can_access_app( $uid, ZDH_APP_ID );
	}
	$u = get_userdata( $uid );
	if ( ! $u ) {
		return false;
	}
	if ( user_can( $uid, 'manage_options' ) ) {
		return true;
	}
	return (bool) array_intersect( (array) $u->roles, zdh_default_roles() );
}

/** The admin tier: delete a drop, import a backup, read diagnostics, cross-check for someone else. */
function zdh_user_is_admin( $uid ) {
	$uid = (int) $uid;
	if ( $uid <= 0 || zdh_is_kiosk( $uid ) ) {
		return false;
	}
	if ( class_exists( 'ZDZ_Capabilities' ) && method_exists( 'ZDZ_Capabilities', 'tier_of' ) ) {
		return 'admin' === ZDZ_Capabilities::tier_of( $uid );
	}
	if ( user_can( $uid, 'manage_options' ) ) {
		return true;
	}
	$u = get_userdata( $uid );
	return $u ? (bool) array_intersect( (array) $u->roles, array( 'administrator', 'zdz_owner', 'zdz_admin' ) ) : false;
}

/** The roles the app is offered to by default (the theme's role default; an admin can grant or deny per person). */
function zdh_default_roles() {
	return array( 'administrator', 'zdz_owner', 'zdz_admin', 'zdz_sales', 'zdz_operator', 'zdz_tech' );
}

/**
 * The people who hold the app, for "who delivered": id, display name and initials. Never an e-mail or a role.
 * The badge itself is the kit's (ZDZ.ui.personChip(id)), so the browser needs only the id.
 */
function zdh_people() {
	static $memo = null; // once per request: the list is read on every render and up to three times per save
	if ( null !== $memo ) {
		return $memo;
	}
	$out   = array();
	$users = get_users( array( 'fields' => array( 'ID', 'display_name' ), 'orderby' => 'display_name', 'number' => 200 ) );
	foreach ( $users as $u ) {
		$uid = (int) $u->ID;
		if ( ! zdh_user_can_use( $uid ) ) {
			continue;
		}
		$initials = '';
		if ( class_exists( 'ZDZ_Person_Color' ) && method_exists( 'ZDZ_Person_Color', 'for_user' ) ) {
			$p        = ZDZ_Person_Color::for_user( $uid );
			$initials = is_array( $p ) ? (string) ( $p['initials'] ?? '' ) : '';
		}
		if ( '' === $initials ) {
			$initials = strtoupper( implode( '', array_map( function ( $w ) { return mb_substr( $w, 0, 1 ); }, array_slice( preg_split( '/\s+/', trim( (string) $u->display_name ) ), 0, 2 ) ) ) );
		}
		$out[] = array( 'id' => $uid, 'name' => (string) $u->display_name, 'initials' => $initials );
	}
	$memo = $out;
	return $out;
}

/* -------------------------------------------------------------------------
 * Activation + upgrade: the tables follow ZDH_DB_VERSION.
 * ---------------------------------------------------------------------- */

function zdh_activate() {
	ZDH_DB::create_tables();
	update_option( 'zdh_db_version', ZDH_DB_VERSION );
}

function zdh_maybe_upgrade() {
	if ( get_option( 'zdh_db_version' ) !== ZDH_DB_VERSION ) {
		ZDH_DB::create_tables();
		update_option( 'zdh_db_version', ZDH_DB_VERSION );
	}
}
add_action( 'plugins_loaded', 'zdh_maybe_upgrade' );

/** The vision job: a WP-Cron single event, never a public route. */
add_action( 'zdh_run_vision_job', array( 'ZDH_REST', 'run_job_cron' ) );

/* -------------------------------------------------------------------------
 * The theme's seams: the app itself.
 * ---------------------------------------------------------------------- */

add_action( 'after_setup_theme', function () {
	if ( ! interface_exists( '\Zorderz\Widget_App_Interface' ) ) {
		return; // not the Zorderz theme: the app keeps its data and REST, but has no surface
	}

	class ZDH_App implements \Zorderz\Widget_App_Interface {

		public function get_config(): array {
			return array(
				'id'          => ZDH_APP_ID,
				'nm'          => __( 'Doorhangers', 'zorderz' ),
				'icon'        => 'map-pin',
				'cat'         => 'field',
				'cc'          => '#009E73',          // kept as cc_plugin; the theme's group colour is what draws
				'desc'        => __( 'Plan, walk and log door-hanger routes; look an address up; digitize old maps.', 'zorderz' ),
				'roles'       => zdh_default_roles(), // never zdz_general (the shared device)
				'bridge_type' => 'inline_widget',
				'admin_url'   => admin_url( 'admin.php?page=zdh-settings' ),
			);
		}

		/** The ?zdz_mobile=1 frame: the same card (the kit loads there too). */
		public function render_mobile_view( int $user_id ): void {
			echo (string) $this->render_dashboard_widget( $user_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup built below from fixed strings
		}

		public function render_dashboard_widget( int $user_id ): ?string {
			if ( ! zdh_user_can_use( $user_id ) ) {
				return null;
			}
			zdh_enqueue_app( $user_id );
			return '<div class="zdh-app" id="zdh-app" data-zdz-ask="off">'
				. '<div class="zdh-boot" id="zdh-boot" aria-live="polite">'
				. '<div class="zdz-loading" role="status"><span class="zdz-loading-spinner" aria-hidden="true"></span><span class="zdz-loading-label">' . esc_html__( 'Loading Doorhangers…', 'zorderz' ) . '</span></div>'
				. '</div>'
				. '<noscript>' . esc_html__( 'Doorhangers needs JavaScript.', 'zorderz' ) . '</noscript></div>';
		}
	}

	add_filter( 'zdz_register_apps', function ( $apps ) {
		$apps[] = new ZDH_App();
		return $apps;
	} );
} );

/**
 * The scripts another app may depend on -- pure code, no data. `zdh-walks` is assets/walks.js, planWalks and its
 * helpers as window.ZDH_WALKS; `zdh-pace` is assets/pace.js, the walking-vs-driving rule as window.ZDH_PACE. The
 * Route Planner enqueues them as dependencies; the houses and roads it cuts come from the read verb
 * doorhangers.area_houses, never from Doorhangers' tables or REST routes.
 */
function zdh_register_shared_scripts() {
	foreach ( array( 'zdh-walks' => 'assets/walks.js', 'zdh-pace' => 'assets/pace.js' ) as $h => $f ) {
		if ( function_exists( 'wp_script_is' ) && wp_script_is( $h, 'registered' ) ) {
			continue; // each handle on its own: one registered never hides the other
		}
		wp_register_script( $h, ZDH_URL . $f, array(), ZDH_VERSION, true );
	}
}
add_action( 'init', 'zdh_register_shared_scripts' );

/**
 * The PDF reader (pdf.js, library and worker) for scanned route maps: the one copy the apps bundle ships, kept by the
 * Estimates app (its vendor/pdfjs README names the version; the Knowledge app's viewer reads the same copy). Both files
 * come from that copy, so the worker always matches the library. Null when the copy is not on disk (the app then says
 * the PDF reader is not available); never a CDN. The file's time is the cache-busting version.
 *
 * @return array{lib:string,worker:string}|null
 */
function zdh_pdfjs_urls() {
	$dir = dirname( ZDH_DIR ) . '/estimate/assets/js/vendor/pdfjs/';
	if ( ! is_readable( $dir . 'pdf.min.js' ) || ! is_readable( $dir . 'pdf.worker.min.js' ) ) {
		return null;
	}
	$base = trailingslashit( dirname( ZDH_URL ) ) . 'estimate/assets/js/vendor/pdfjs/';
	return array(
		'lib'    => $base . 'pdf.min.js?ver=' . (int) @filemtime( $dir . 'pdf.min.js' ),
		'worker' => $base . 'pdf.worker.min.js?ver=' . (int) @filemtime( $dir . 'pdf.worker.min.js' ),
	);
}

/**
 * Assets and ZDH_CONFIG. The kit (zdz-kit-js) is a dependency when the theme registered it; app.js guards with
 * `if (window.ZDZ && ZDZ.ui)` for anything else. Top-level scalars reach JS as strings: '' is the only false.
 */
function zdh_enqueue_app( $user_id ) {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	$s    = zdh_get_settings();
	$area = zdh_area_defaults();
	$v    = ZDH_URL . 'assets/vendor/';
	wp_enqueue_style( 'zdh-leaflet', $v . 'leaflet/leaflet.css', array(), '1.9.4' );
	wp_enqueue_style( 'zdh-maplibre', $v . 'maplibre/maplibre-gl.css', array(), ZDH_MAPLIBRE_REL );
	wp_enqueue_style( 'zdh-app', ZDH_URL . 'assets/app.css', array( 'zdh-leaflet', 'zdh-maplibre' ), ZDH_VERSION );

	wp_enqueue_script( 'zdh-leaflet', $v . 'leaflet/leaflet.js', array(), '1.9.4', true );
	wp_enqueue_script( 'zdh-maplibre', $v . 'maplibre/maplibre-gl.js', array(), ZDH_MAPLIBRE_REL, true );
	wp_enqueue_script( 'zdh-maplibre-leaflet', $v . 'maplibre/leaflet-maplibre-gl.js', array( 'zdh-leaflet', 'zdh-maplibre' ), '0.1.4', true );
	$deps = array( 'zdh-leaflet', 'zdh-maplibre-leaflet' );
	if ( wp_script_is( 'zdz-kit-js', 'registered' ) || wp_script_is( 'zdz-kit-js', 'enqueued' ) ) {
		$deps[] = 'zdz-kit-js';
	}
	wp_enqueue_script( 'zdh-nav', ZDH_URL . 'assets/nav.js', array(), ZDH_VERSION, true ); // the walk view's next-step maths
	$deps[] = 'zdh-nav';
	wp_enqueue_script( 'zdh-paper', ZDH_URL . 'assets/paper.js', array(), ZDH_VERSION, true ); // old routes from paper
	$deps[] = 'zdh-paper';
	zdh_register_shared_scripts();
	wp_enqueue_script( 'zdh-walks' ); // planWalks, shared with the Route Planner
	$deps[] = 'zdh-walks';
	wp_enqueue_script( 'zdh-sealed-show', ZDH_URL . 'assets/zdz-sealed-show.js', array(), ZDH_VERSION, true ); // Show code (shared; the first copy loaded wins)
	$deps[] = 'zdh-sealed-show';
	wp_enqueue_script( 'zdh-app', ZDH_URL . 'assets/app.js', $deps, ZDH_VERSION, true );

	$user = get_userdata( (int) $user_id );
	wp_localize_script( 'zdh-app', 'ZDH_CONFIG', array(
		'restUrl'       => esc_url_raw( rest_url( ZDH_REST::ns() . ZDH_REST::BASE . '/' ) ),
		'version'       => ZDH_VERSION,
		'nonce'         => wp_create_nonce( 'wp_rest' ),
		'isAdmin'       => zdh_user_is_admin( $user_id ) ? '1' : '',
		'userId'        => (int) $user_id,
		'userName'      => $user ? $user->display_name : '',
		'hangerTypes'   => zdh_list_setting( 'hanger_types' ),
		'people'        => zdh_people(),
		'service'       => (string) $s['service'],
		'defaultCity'   => $area['city'],
		'defaultState'  => $area['state'],
		'county'        => $area['county'],
		'zipHint'       => preg_replace( '/[^0-9]/', '', (string) $area['zip'] ), // the Business Profile's postal code as the box's hint
		'mapCenter'     => array( 'point' => zdh_map_center() ), // nested so null stays null
		'areaSource'    => ZDH_Geo::source_label(),
		'llmReady'      => ZDH_LLM::ready() ? '1' : '',
		'model'         => ZDH_LLM::model(),
		'leafletImages' => $v . 'leaflet/images/',
		'revisitDays'   => (int) $s['revisit_days'],
		'today'         => current_time( 'Y-m-d' ),
		'now'           => current_time( 'Y-m-d H:i' ),
		'autoRadius'    => (int) $s['auto_radius_m'],
		'walkSize'      => (int) $s['walk_size'],
		'walkRate'      => (int) $s['walk_rate'],
		'routeStyle'    => $s['route_style'],
		'pace'          => zdh_pace_limits(),          // nested, so the numbers stay numbers (top-level scalars become strings)
		'navVoice'      => ! empty( $s['nav_voice'] ) ? '1' : '',
		'paperKey'      => $s['paper_key'] !== '' ? '1' : '',
		'pdfjs'         => zdh_pdfjs_urls(), // null when the reader is not on disk: the app then says so
		'heicUrl'       => $v . 'heic/heic2any.min.js?ver=0.0.4', // phone photos in HEIC outside Safari; loaded only when one is picked
		'crm'           => array( 'ready' => ZDH_Nutshell::configured() ? '1' : '', 'hasKey' => ZDH_Nutshell::has_key() ? '1' : '', 'owner' => ZDH_Nutshell::owner_label(), 'own' => ZDH_Nutshell::owner() ),
	) );
}

/* -------------------------------------------------------------------------
 * Zorderz -> Doorhangers (wp-admin): the app's own numbers, the import, the connection check, the phone reports.
 * No key box: the AI key and the CRM login are on Zorderz -> Settings.
 * ---------------------------------------------------------------------- */

add_action( 'admin_menu', function () {
	$parent = menu_page_url( 'zdz-core-settings', false ) ? 'zdz-core-settings' : 'options-general.php';
	add_submenu_page( $parent, __( 'Doorhangers', 'zorderz' ), __( 'Doorhangers', 'zorderz' ), 'manage_options', 'zdh-settings', 'zdh_settings_page' );
}, 40 );

add_action( 'admin_init', function () {
	register_setting( 'zdh', 'zdh_settings', array(
		'type'              => 'array',
		'sanitize_callback' => 'zdh_sanitize_settings',
	) );
	register_setting( 'zdh', ZDH_Geo::SOURCE_OPTION, array(
		'type'              => 'array',
		'sanitize_callback' => array( 'ZDH_Geo', 'sanitize_source_input' ),
	) );
} );

/** A number box's value, or null when it was cleared or isn't a number (then the stored value stays). */
function zdh_num_in( $in, $k ) {
	if ( ! isset( $in[ $k ] ) ) {
		return null;
	}
	$v = trim( (string) $in[ $k ] );
	return ( '' !== $v && is_numeric( $v ) ) ? (float) $v : null;
}

/** The settings form -> what is stored. Pure apart from the stored values it starts from. */
function zdh_sanitize_settings( $in ) {
	$cur = zdh_get_settings();
	$out = $cur;
	$in  = is_array( $in ) ? $in : array();
	foreach ( array( 'service', 'default_city', 'default_state', 'default_county', 'census_api_key' ) as $k ) {
		if ( isset( $in[ $k ] ) ) {
			$out[ $k ] = sanitize_text_field( $in[ $k ] );
		}
	}
	if ( isset( $in['map_center'] ) ) {
		$pt                = zdh_parse_point( (string) $in['map_center'] );
		$out['map_center'] = $pt ? $pt[0] . ',' . $pt[1] : '';
	}
	// a cleared or non-numeric number box keeps the stored value
	foreach ( array( 'revisit_days' => array( 0, 730 ), 'auto_radius_m' => array( 8, 80 ), 'walk_size' => array( 5, 500 ), 'walk_rate' => array( 5, 300 ) ) as $k => $r ) {
		$v = zdh_num_in( $in, $k );
		if ( null !== $v ) { $out[ $k ] = max( $r[0], min( $r[1], (int) $v ) ); }
	}
	if ( isset( $in['route_style'] ) ) { $out['route_style'] = 'street' === $in['route_style'] ? 'street' : 'loop'; }
	$v = zdh_num_in( $in, 'pace_drive_mph' );
	if ( null !== $v ) { $out['pace_drive_mph'] = round( max( 3.0, min( 30.0, $v ) ), 1 ); }
	$v = zdh_num_in( $in, 'pace_walk_mph' );
	if ( null !== $v ) { $out['pace_walk_mph'] = round( max( 1.0, min( 29.5, $v ) ), 1 ); }
	if ( $out['pace_walk_mph'] >= $out['pace_drive_mph'] ) { $out['pace_walk_mph'] = max( 1.0, $out['pace_drive_mph'] - 0.5 ); }
	foreach ( array( 'pace_drive_s' => array( 5, 120 ), 'pace_walk_s' => array( 5, 120 ), 'pace_acc_m' => array( 5, 50 ), 'pace_jump_m' => array( 100, 2000 ) ) as $k => $r ) {
		$v = zdh_num_in( $in, $k );
		if ( null !== $v ) { $out[ $k ] = max( $r[0], min( $r[1], (int) $v ) ); }
	}
	// an unticked box sends nothing: the form's hidden marker says the boxes were on the page
	foreach ( array( 'nav_voice', 'census_on', 'osm_fallback' ) as $box ) {
		if ( isset( $in['zdh_form'] ) || isset( $in[ $box ] ) ) {
			$out[ $box ] = empty( $in[ $box ] ) ? 0 : 1;
		}
	}
	if ( isset( $in['paper_key'] ) ) { $out['paper_key'] = substr( sanitize_textarea_field( $in['paper_key'] ), 0, 2000 ); }
	if ( isset( $in['hanger_types'] ) ) { $out['hanger_types'] = sanitize_textarea_field( $in['hanger_types'] ); }
	$ns_before = $cur['nutshell_user'];
	if ( isset( $in['nutshell_user'] ) ) {
		$out['nutshell_user'] = preg_match( '/^\d+-users$/', (string) $in['nutshell_user'] ) ? (string) $in['nutshell_user'] : '';
	}
	if ( isset( $in['nutshell_user_name'] ) ) {
		$out['nutshell_user_name'] = sanitize_text_field( $in['nutshell_user_name'] );
	}
	if ( $out['nutshell_user'] === '' ) {
		$out['nutshell_user_name'] = '';
	}
	if ( $ns_before !== $out['nutshell_user'] && class_exists( 'ZDH_Nutshell' ) ) {
		ZDH_Nutshell::forget();
	}
	if ( trim( (string) $out['hanger_types'] ) === '' ) {
		$out['hanger_types'] = 'Standard';
	}
	return $out;
}

function zdh_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$s          = zdh_get_settings();
	$area       = zdh_area_defaults();
	$stats      = ZDH_DB::stats();
	$rest_nonce = wp_create_nonce( 'wp_rest' );
	$cred_url   = admin_url( 'admin.php?page=zdz-core-settings' );
	if ( class_exists( 'ZDZ_Core_Credentials' ) && method_exists( 'ZDZ_Core_Credentials', 'page_url' ) ) {
		$cred_url = (string) ZDZ_Core_Credentials::page_url();
	}
	$src_json = wp_json_encode( ZDH_Geo::source(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Doorhangers', 'zorderz' ); ?></h1>
		<p><?php esc_html_e( 'The app is on the home screen of everyone who holds it. Its keys live on', 'zorderz' ); ?> <a href="<?php echo esc_url( $cred_url ); ?>"><?php esc_html_e( 'the platform\'s credentials page', 'zorderz' ); ?></a>:
			<?php esc_html_e( 'AI', 'zorderz' ); ?> <strong><?php echo ZDH_LLM::ready() ? esc_html__( 'set', 'zorderz' ) : esc_html__( 'not set', 'zorderz' ); ?></strong><?php if ( '' !== ZDH_LLM::model() ) : ?> (<?php esc_html_e( 'model', 'zorderz' ); ?> <code><?php echo esc_html( ZDH_LLM::model() ); ?></code>)<?php endif; ?>,
			<?php esc_html_e( 'CRM', 'zorderz' ); ?> <strong><?php echo ZDH_Nutshell::has_key() ? esc_html__( 'set', 'zorderz' ) : esc_html__( 'not set', 'zorderz' ); ?></strong>.</p>
		<p><strong><?php esc_html_e( 'Database:', 'zorderz' ); ?></strong> <?php echo (int) $stats['drops']; ?> <?php esc_html_e( 'drops', 'zorderz' ); ?>, <?php echo (int) $stats['addresses']; ?> <?php esc_html_e( 'addresses', 'zorderz' ); ?>, <?php echo (int) $stats['streets']; ?> <?php esc_html_e( 'street records', 'zorderz' ); ?>.</p>
		<form method="post" action="options.php">
			<?php settings_fields( 'zdh' ); ?>
			<input type="hidden" name="zdh_settings[zdh_form]" value="1">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="zdh_types"><?php esc_html_e( 'Door hanger types', 'zorderz' ); ?></label></th>
					<td><textarea id="zdh_types" name="zdh_settings[hanger_types]" rows="3" class="regular-text"><?php echo esc_textarea( $s['hanger_types'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'One per line. The first line is the default.', 'zorderz' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="zdh_service"><?php esc_html_e( 'Service advertised', 'zorderz' ); ?></label></th>
					<td><input type="text" id="zdh_service" name="zdh_settings[service]" value="<?php echo esc_attr( $s['service'] ); ?>" class="regular-text">
					<p class="description"><?php esc_html_e( 'Saved on each drop, so the log says what the hanger offered.', 'zorderz' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Default area', 'zorderz' ); ?></th>
					<td>
						<input type="text" name="zdh_settings[default_city]" value="<?php echo esc_attr( $s['default_city'] ); ?>" placeholder="<?php echo esc_attr( '' !== $area['city'] ? $area['city'] : __( 'City', 'zorderz' ) ); ?>" style="width:160px">
						<input type="text" name="zdh_settings[default_state]" value="<?php echo esc_attr( $s['default_state'] ); ?>" placeholder="<?php echo esc_attr( '' !== $area['state'] ? $area['state'] : __( 'State', 'zorderz' ) ); ?>" style="width:80px">
						<input type="text" name="zdh_settings[default_county]" value="<?php echo esc_attr( $s['default_county'] ); ?>" placeholder="<?php esc_attr_e( 'County (optional)', 'zorderz' ); ?>" style="width:180px">
						<p class="description"><?php esc_html_e( 'Empty boxes use the Business Profile address.', 'zorderz' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="zdh_center"><?php esc_html_e( 'Map opens at', 'zorderz' ); ?></label></th>
					<td><input type="text" id="zdh_center" name="zdh_settings[map_center]" value="<?php echo esc_attr( $s['map_center'] ); ?>" placeholder="<?php esc_attr_e( 'latitude, longitude', 'zorderz' ); ?>" class="regular-text">
					<p class="description"><?php esc_html_e( 'Empty: near the Business Profile address when the platform can place it, else a world view until a route or the phone\'s position says where to look.', 'zorderz' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="zdh_revisit"><?php esc_html_e( 'Revisit reminder (days)', 'zorderz' ); ?></label></th>
					<td><input type="number" id="zdh_revisit" name="zdh_settings[revisit_days]" value="<?php echo esc_attr( (int) $s['revisit_days'] ); ?>" min="0" max="730" style="width:90px"></td>
				</tr>
				<tr>
					<th scope="row"><label for="zdh_radius"><?php esc_html_e( 'Auto-mark radius (meters)', 'zorderz' ); ?></label></th>
					<td><input type="number" id="zdh_radius" name="zdh_settings[auto_radius_m]" value="<?php echo esc_attr( (int) $s['auto_radius_m'] ); ?>" min="8" max="80" style="width:90px">
					<p class="description"><?php esc_html_e( 'A house is ticked when the phone comes within this distance of it (25 m covers both sides of a residential street).', 'zorderz' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="zdh_walk_size"><?php esc_html_e( 'Walks', 'zorderz' ); ?></label></th>
					<td>
						<label for="zdh_walk_size"><?php esc_html_e( 'Houses per walk', 'zorderz' ); ?></label> <input type="number" id="zdh_walk_size" name="zdh_settings[walk_size]" value="<?php echo esc_attr( (int) $s['walk_size'] ); ?>" min="5" max="500" style="width:90px">
						&nbsp; <label for="zdh_walk_rate"><?php esc_html_e( 'Houses per hour', 'zorderz' ); ?></label> <input type="number" id="zdh_walk_rate" name="zdh_settings[walk_rate]" value="<?php echo esc_attr( (int) $s['walk_rate'] ); ?>" min="5" max="300" style="width:90px">
						<label style="display:block;margin-top:8px"><input type="radio" name="zdh_settings[route_style]" value="loop" <?php checked( 'loop', $s['route_style'] ); ?>> <strong><?php esc_html_e( 'Loop the block', 'zorderz' ); ?></strong> — <?php esc_html_e( 'one side of the street at a time, round the block and back.', 'zorderz' ); ?></label>
						<label style="display:block;margin-top:4px"><input type="radio" name="zdh_settings[route_style]" value="street" <?php checked( 'street', $s['route_style'] ); ?>> <strong><?php esc_html_e( 'Street by street', 'zorderz' ); ?></strong> — <?php esc_html_e( 'both sides of a street, then the nearest unfinished street.', 'zorderz' ); ?></label>
						<p class="description"><?php esc_html_e( 'Starting values: set your own team\'s pace.', 'zorderz' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Walking vs. driving', 'zorderz' ); ?></th>
					<td>
						<label><?php esc_html_e( 'Driving: faster than', 'zorderz' ); ?> <input type="number" step="0.1" min="3" max="30" name="zdh_settings[pace_drive_mph]" value="<?php echo esc_attr( $s['pace_drive_mph'] ); ?>" style="width:70px"> <?php esc_html_e( 'mph for', 'zorderz' ); ?> <input type="number" min="5" max="120" name="zdh_settings[pace_drive_s]" value="<?php echo esc_attr( (int) $s['pace_drive_s'] ); ?>" style="width:60px"> s</label><br>
						<label><?php esc_html_e( 'Walking again: slower than', 'zorderz' ); ?> <input type="number" step="0.1" min="1" max="29.5" name="zdh_settings[pace_walk_mph]" value="<?php echo esc_attr( $s['pace_walk_mph'] ); ?>" style="width:70px"> <?php esc_html_e( 'mph for', 'zorderz' ); ?> <input type="number" min="5" max="120" name="zdh_settings[pace_walk_s]" value="<?php echo esc_attr( (int) $s['pace_walk_s'] ); ?>" style="width:60px"> s</label><br>
						<label><?php esc_html_e( 'Ignore GPS fixes looser than ±', 'zorderz' ); ?> <input type="number" min="5" max="50" name="zdh_settings[pace_acc_m]" value="<?php echo esc_attr( (int) $s['pace_acc_m'] ); ?>" style="width:60px"> m</label> &nbsp;
						<label><?php esc_html_e( 'A jump of', 'zorderz' ); ?> <input type="number" min="100" max="2000" name="zdh_settings[pace_jump_m]" value="<?php echo esc_attr( (int) $s['pace_jump_m'] ); ?>" style="width:75px"> <?php esc_html_e( 'm between fixes is driving', 'zorderz' ); ?></label>
						<p class="description"><?php esc_html_e( 'While the app thinks someone is driving, no house is ticked and the stretch is drawn dashed. The defaults sit just above walking speed; change them after a field test. The walker can always overrule it with I\'m on foot or I\'m driving.', 'zorderz' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Spoken turns', 'zorderz' ); ?></th>
					<td><label><input type="checkbox" name="zdh_settings[nav_voice]" value="1" <?php checked( ! empty( $s['nav_voice'] ) ); ?>> <?php esc_html_e( 'Offer spoken turns in pocket mode ("Next block in 200 feet")', 'zorderz' ); ?></label>
					<p class="description"><?php esc_html_e( 'Off by default. When on, each walker still turns it on for their own phone, in pocket mode only.', 'zorderz' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="zdh_paper_key"><?php esc_html_e( 'Paper map key', 'zorderz' ); ?></label></th>
					<td><textarea id="zdh_paper_key" name="zdh_settings[paper_key]" rows="5" class="large-text" placeholder="<?php echo esc_attr( __( 'Yellow highlight = hung door hangers on that street', 'zorderz' ) . "\n" . __( 'X = skipped (no soliciting)', 'zorderz' ) ); ?>"><?php echo esc_textarea( $s['paper_key'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'For digitizing old printed route maps: what each highlighter colour and mark means, one per line. The reader follows this key.', 'zorderz' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'House addresses', 'zorderz' ); ?></th>
					<td>
						<p class="description" style="margin:0 0 6px"><?php echo esc_html( sprintf( __( 'Now: %s.', 'zorderz' ), ZDH_Geo::source_label() ) ); ?> <?php esc_html_e( 'A regional GIS address layer (an ArcGIS feature service of address points, road centerlines and postal areas) is set here as JSON, or by your Identity Pack.', 'zorderz' ); ?></p>
						<textarea name="<?php echo esc_attr( ZDH_Geo::SOURCE_OPTION ); ?>[json]" rows="8" class="large-text code"><?php echo esc_textarea( (string) $src_json ); ?></textarea>
						<label style="display:block;margin-top:6px"><input type="checkbox" name="zdh_settings[osm_fallback]" value="1" <?php checked( ! empty( $s['osm_fallback'] ) ); ?>> <?php esc_html_e( 'Ask the open street map when the regional layer has no houses there (sends street names and an area to the public map service)', 'zorderz' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="zdh_census"><?php esc_html_e( 'Neighborhood figures', 'zorderz' ); ?></label></th>
					<td><label><input type="checkbox" name="zdh_settings[census_on]" value="1" <?php checked( ! empty( $s['census_on'] ) ); ?>> <?php esc_html_e( 'Look up US Census neighborhood figures for each walk (United States only)', 'zorderz' ); ?></label><br>
					<input type="text" id="zdh_census" name="zdh_settings[census_api_key]" value="<?php echo esc_attr( $s['census_api_key'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Census API key', 'zorderz' ); ?>">
					<p class="description"><?php esc_html_e( 'A free key from the Census Data API for median income, home value and year built. Not a secret; everything else works without it.', 'zorderz' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'CRM cross-check, site default', 'zorderz' ); ?></th>
					<td>
						<p class="description" style="margin:0 0 8px"><?php esc_html_e( 'Each person\'s cross-check reads their own CRM records (the CRM user their profile is matched to). This default is used for a person with no match. The app only ever reads, and stores no names.', 'zorderz' ); ?></p>
						<select id="zdh_ns_user" name="zdh_settings[nutshell_user]" data-saved="<?php echo esc_attr( $s['nutshell_user'] ); ?>" style="min-width:260px">
							<option value="">— <?php esc_html_e( 'none', 'zorderz' ); ?> —</option>
							<?php if ( $s['nutshell_user'] !== '' ) : ?><option value="<?php echo esc_attr( $s['nutshell_user'] ); ?>" selected><?php echo esc_html( $s['nutshell_user_name'] ?: $s['nutshell_user'] ); ?></option><?php endif; ?>
						</select>
						<input type="hidden" id="zdh_ns_user_name" name="zdh_settings[nutshell_user_name]" value="<?php echo esc_attr( $s['nutshell_user_name'] ); ?>">
						<span class="description" id="zdh_ns_user_note"><?php echo ZDH_Nutshell::has_key() ? esc_html__( 'Loading the CRM user list…', 'zorderz' ) : esc_html__( 'Set the CRM login on Zorderz → Settings first.', 'zorderz' ); ?></span>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<h2><?php esc_html_e( 'Import a backup', 'zorderz' ); ?></h2>
		<p><?php esc_html_e( 'A Doorhangers full backup (.json, from Export → Full backup). Drops, houses and street records come in with their dates and deliverer names; a drop already imported is skipped, so the same file can be loaded twice safely.', 'zorderz' ); ?></p>
		<p><input type="file" id="zdh-import-file" accept="application/json,.json"> <label><input type="checkbox" id="zdh-import-replace"> <?php esc_html_e( 'replace everything here first', 'zorderz' ); ?></label> <button type="button" class="button button-primary" id="zdh-import-btn"><?php esc_html_e( 'Import', 'zorderz' ); ?></button></p>
		<pre id="zdh-import-out" style="background:#fff;border:1px solid #ccd0d4;padding:10px;max-width:700px;white-space:pre-wrap"></pre>

		<h2><?php esc_html_e( 'Connection check', 'zorderz' ); ?></h2>
		<p><?php esc_html_e( 'Runs from this server: the AI reader, the address source, the weather service, the neighborhood figures, the hazard layers, the CRM.', 'zorderz' ); ?></p>
		<p><button type="button" class="button" id="zdh-diag-btn"><?php esc_html_e( 'Run connection check', 'zorderz' ); ?></button></p>
		<pre id="zdh-diag-out" style="background:#fff;border:1px solid #ccd0d4;padding:10px;max-width:700px;white-space:pre-wrap"></pre>

		<h2><?php esc_html_e( 'Phone diagnostics reports', 'zorderz' ); ?></h2>
		<p><?php esc_html_e( 'Reports sent from the app (Export → Diagnostics → Send to the site). Newest first; at most 12 are kept.', 'zorderz' ); ?></p>
		<?php
		$reports = get_option( ZDH_REST::DIAG_OPTION, array() );
		if ( ! is_array( $reports ) || ! $reports ) :
			?><p><em><?php esc_html_e( 'None yet.', 'zorderz' ); ?></em></p><?php
		else :
			foreach ( $reports as $i => $r ) :
				?>
				<details style="margin:0 0 8px;max-width:900px"<?php echo 0 === $i ? ' open' : ''; ?>>
					<summary style="cursor:pointer"><strong><?php echo esc_html( $r['at'] ); ?></strong> — <?php echo esc_html( trim( ( $r['by'] ?? '' ) . ' ' . ( $r['label'] ?? '' ) ) ); ?> <span style="color:#666"><?php echo esc_html( substr( (string) ( $r['ua'] ?? '' ), 0, 80 ) ); ?></span></summary>
					<textarea readonly rows="18" style="width:100%;font:12px/1.4 Menlo,Consolas,monospace;white-space:pre"><?php echo esc_textarea( $r['text'] ); ?></textarea>
				</details>
				<?php
			endforeach;
			?>
			<p><button type="button" class="button" id="zdh-diag-clear"><?php esc_html_e( 'Delete all reports', 'zorderz' ); ?></button> <span id="zdh-diag-clear-note"></span></p>
			<?php
		endif;
		// This page asks through the theme's kit (ZDZ.ui.rest), as the app does. wp-admin loads the kit only inside the
		// app (?zdz_mobile=1), so the page loads it itself otherwise.
		if ( ! wp_script_is( 'zdz-kit-js', 'enqueued' ) && function_exists( 'get_template_directory' ) && file_exists( get_template_directory() . '/assets/js/zdz-kit.js' ) ) {
			$kit_m = @filemtime( get_template_directory() . '/assets/js/zdz-kit.js' );
			wp_enqueue_script( 'zdz-kit-js', get_template_directory_uri() . '/assets/js/zdz-kit.js', array(), ( defined( 'ZDZ_THEME_VER_FLOOR' ) ? ZDZ_THEME_VER_FLOOR : '' ) . ( $kit_m ? '.' . $kit_m : '' ), true );
			wp_add_inline_script( 'zdz-kit-js', 'window.zdzData=Object.assign(' . wp_json_encode( array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ), 'apiUrl' => esc_url_raw( rest_url( ZDH_REST::ns() . '/' ) ), 'nonce' => $rest_nonce ) ) . ',window.zdzData||{});', 'before' );
		}
		$t = array(
			'noKit'      => __( 'The theme\'s kit is not loaded on this page. Reload it, or check that the Zorderz theme is active.', 'zorderz' ),
			'checking'   => __( 'Checking…', 'zorderz' ),
			'chooseFile' => __( 'Choose the backup file first.', 'zorderz' ),
			'armReplace' => __( 'Press again: this removes every drop here first', 'zorderz' ),
			'importing'  => __( 'Importing…', 'zorderz' ),
			'armDelete'  => __( 'Press again to delete them all', 'zorderz' ),
			'deleteAll'  => __( 'Delete all reports', 'zorderz' ),
			'noUsers'    => __( 'Could not load the user list.', 'zorderz' ),
			'noUsersKey' => __( 'Could not load the user list (check the login on Zorderz → Settings).', 'zorderz' ),
			'usersIn'    => __( 'users in the CRM.', 'zorderz' ),
			'none'       => __( 'none', 'zorderz' ),
			'disabled'   => __( 'disabled', 'zorderz' ),
		);
		?>
		<script>
		(function(){
			var nonce = <?php echo wp_json_encode( $rest_nonce ); ?>, base = <?php echo wp_json_encode( esc_url_raw( rest_url( ZDH_REST::ns() . ZDH_REST::BASE . '/' ) ) ); ?>, T = <?php echo wp_json_encode( $t ); ?>;
			// Every request goes through the kit, found at the call (it loads in the footer, after this script). One
			// request per action (retry 0); a JSON answer comes back as it came, whatever its status. An answer that is
			// not JSON (a proxy's HTML page, a timeout, no signal) is the kit's sentence. No fallback request.
			var LONG = { diagnostics: 180000, 'import': 600000 };
			function call(path, opts){
				opts = opts || {};
				var ui = window.ZDZ && window.ZDZ.ui;
				if (!ui || typeof ui.rest !== 'function') { var ne = new Error(T.noKit); ne.noKit = true; return Promise.reject(ne); }
				return ui.rest(base + path, { method: opts.method || 'GET', body: opts.body, headers: { 'X-WP-Nonce': nonce }, raw: true, retry: 0, timeout: LONG[path] || 30000 }).catch(function(e){
					var j = null;
					if (e && e.status && typeof e.body === 'string' && e.body !== '') { try { j = JSON.parse(e.body); } catch (x) { j = null; } }
					if (j !== null && typeof j === 'object') { return j; }
					throw e;
				});
			}
			function said(e){ return (e && e.message) ? e.message : String(e); }
			function start(){
			var b = document.getElementById('zdh-diag-btn'), o = document.getElementById('zdh-diag-out');
			b.addEventListener('click', function(){ o.textContent = T.checking; call('diagnostics', {method:'POST'}).then(function(j){ o.textContent = JSON.stringify(j, null, 2); }).catch(function(e){ o.textContent = 'Error: ' + said(e); }); });
			var ib = document.getElementById('zdh-import-btn'), io = document.getElementById('zdh-import-out'), f = document.getElementById('zdh-import-file');
			ib.addEventListener('click', function(){
				if (!f.files || !f.files[0]) { io.textContent = T.chooseFile; return; }
				if (document.getElementById('zdh-import-replace').checked && ib.dataset.armed !== '1') { ib.dataset.armed = '1'; ib.textContent = T.armReplace; io.textContent = ''; return; }
				var fd = new FormData(); fd.append('file', f.files[0]); if (document.getElementById('zdh-import-replace').checked) { fd.append('replace', '1'); }
				io.textContent = T.importing; ib.disabled = true;
				call('import', {method:'POST', body: fd}).then(function(j){ ib.disabled = false; io.textContent = j && j.drops !== undefined ? ('Imported ' + j.drops + ' drops, ' + j.addresses + ' houses, ' + j.streets + ' street records' + (j.skipped ? ' · skipped ' + j.skipped + ' (already here or unreadable)' : '') + (j.replaced ? ' · everything that was here was removed first' : '') + '.') : JSON.stringify(j, null, 2); }).catch(function(e){ ib.disabled = false; io.textContent = 'Error: ' + said(e); });
			});
			var dc = document.getElementById('zdh-diag-clear');
			if (dc) { dc.addEventListener('click', function(){ var n = document.getElementById('zdh-diag-clear-note'); if (dc.dataset.armed !== '1') { dc.dataset.armed = '1'; dc.textContent = T.armDelete; n.textContent = ''; return; } call('diag-report', {method:'DELETE'}).then(function(j){ if (j && j.code && j.message) { dc.dataset.armed = ''; dc.textContent = T.deleteAll; n.textContent = String(j.message); return; } location.reload(); }).catch(function(e){ dc.dataset.armed = ''; dc.textContent = T.deleteAll; n.textContent = said(e); }); }); }
			var sel = document.getElementById('zdh_ns_user'), nm = document.getElementById('zdh_ns_user_name'), note = document.getElementById('zdh_ns_user_note');
			if (!sel) { return; }
			sel.addEventListener('change', function(){ nm.value = sel.value ? sel.options[sel.selectedIndex].textContent.replace(/\s*—\s*\S+$/, '').replace(/\s*\([^)]*\)\s*$/, '') : ''; });
			<?php if ( ZDH_Nutshell::has_key() ) : ?>
			call('crm/users').then(function(j){
				if (!j || !j.users) { note.textContent = (j && j.message) ? j.message : T.noUsers; return; }
				var saved = sel.getAttribute('data-saved') || '';
				sel.innerHTML = '<option value="">— ' + T.none + ' —</option>' + j.users.map(function(u){ var label = u.name + (u.initials ? ' (' + u.initials + ')' : '') + (u.enabled ? '' : ' — ' + T.disabled); return '<option value="' + String(u.id).replace(/[^0-9a-z-]/gi, '') + '"' + (u.id === saved ? ' selected' : '') + '>' + label.replace(/&/g,'&amp;').replace(/</g,'&lt;') + '</option>'; }).join('');
				note.textContent = j.users.length + ' ' + T.usersIn;
			}).catch(function(e){ note.textContent = e && e.noKit ? said(e) : T.noUsersKey; });
			<?php endif; ?>
			}
			// The kit is in the footer: start once the page has read it (it already has, if this runs late).
			if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
		})();
		</script>
	</div>
	<?php
}

/* -------------------------------------------------------------------------
 * Media library housekeeping: label the map screenshots.
 * ---------------------------------------------------------------------- */

add_filter( 'manage_media_columns', function ( $cols ) {
	$cols['zdh_drop'] = __( 'Doorhangers', 'zorderz' );
	return $cols;
} );
add_action( 'manage_media_custom_column', function ( $col, $id ) {
	if ( 'zdh_drop' === $col ) {
		$drop = get_post_meta( $id, '_zdh_drop_id', true );
		echo $drop ? esc_html( sprintf( __( 'Drop #%d', 'zorderz' ), (int) $drop ) ) : ( get_post_meta( $id, '_zdh', true ) ? esc_html__( 'Unsaved analysis', 'zorderz' ) : '' );
	}
}, 10, 2 );
