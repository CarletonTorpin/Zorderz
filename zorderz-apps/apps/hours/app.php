<?php
/**
 * Module: Zorderz - Hours
 * Description: Hours worked, to the minute. Clock in and out (with where the phone was, when the business asks and the
 *   person allows it); add hours by typing them, saying them, or from a photo of a time sheet (read by the platform's Ai
 *   router as a proposal a person confirms; it never writes hours by itself); see today, this week, this month and the
 *   pay period; download them for the accountant. A clock forgotten at the end of the day gets one reminder and the
 *   person sets the time they finished -- the app never makes one up. Door-hanger walks start and stop the clock by
 *   themselves. Everyone sees their own hours, simply; the admin tier sees everyone's, against the standard hours, with
 *   how much of each stretch the app's own record backs up, and sets the standard hours and the business's rules.
 * Version:     1.0.0
 * Author:      Zorderz
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: zorderz
 * Requires PHP: 8.0
 *
 * This is a bundled app module (loaded by zorderz-apps.php), not a standalone plugin. It registers with the theme through
 * the `zdz_register_apps` filter on after_setup_theme and declines cleanly when the theme is absent.
 *
 * CORE-SERVICE BINDINGS
 *   - Tiers       : ZDZ_Capabilities::tier_of (the admin tier sees and changes everyone's hours).
 *   - Crews       : ZDZ_Hierarchy (a crew lead may read their crew's hours, when the business turns it on).
 *   - App access  : ZDZ_Plugin_API::user_can_access_app (+ the ADD-only zdz_extra_allowed_apps grant, when the
 *                   business turns on "everyone on staff has Hours").
 *   - Reading     : ZDZ_Core_LLM::ask, caller 'hours' (words: extract; a photo: read_document).
 *   - Privacy     : ZDZ_Private::for( 'hours' ) for stored notes; the router's own coder for what it sends.
 *   - Reminders   : ZDZ_Alert_Router (type zhr_still_clocked_in, category 'hours').
 *   - Schedule    : ZSCH_Appointments::query, when the Scheduler is installed (ZHR_Schedule).
 *   - Chat verb   : hours.lookup on zdz_register_capabilities (a read).
 *
 * PAY SAFETY (party -> attribution -> pay)
 *   An entry belongs to exactly the person it was recorded for. A reading's stretch is attributed by ZHR_Attribution:
 *   exact names only; a photo with no name, a name that is nobody's, or a name that is two people's is a REVIEW item,
 *   attributed to nobody until a person chooses -- never to the owner, the uploader or the closest name. Nobody sees
 *   another person's hours unless ZHR_Access lets them (deny by default); the shared device and demo mode are refused.
 *
 * Ships EMPTY: no people, no rates, no standard hours, no meal, overtime or pay-period rules, no reminder time. The
 * business sets them in the app (Settings, admin tier) or an Identity Pack writes them (rules.yml `hours`,
 * compensation.yml `hourly`; ZHR_Pack).
 *
 * @package Zorderz\Hours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ZHR_VERSION', '1.0.0' );
define( 'ZHR_DB_VERSION', '1.0.0' );
define( 'ZHR_APP_ID', 'hours' );
define( 'ZHR_FILE', __FILE__ );
define( 'ZHR_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZHR_URL', plugin_dir_url( __FILE__ ) );

require_once ZHR_DIR . 'includes/class-zhr-rules.php';
require_once ZHR_DIR . 'includes/class-zhr-time.php';
require_once ZHR_DIR . 'includes/class-zhr-attribution.php';
require_once ZHR_DIR . 'includes/class-zhr-access.php';
require_once ZHR_DIR . 'includes/class-zhr-schedule.php';
require_once ZHR_DIR . 'includes/class-zhr-db.php';
require_once ZHR_DIR . 'includes/class-zhr-private.php';
require_once ZHR_DIR . 'includes/class-zhr-clock.php';
require_once ZHR_DIR . 'includes/class-zhr-collect.php';
require_once ZHR_DIR . 'includes/class-zhr-parse.php';
require_once ZHR_DIR . 'includes/class-zhr-rest.php';
require_once ZHR_DIR . 'includes/class-zhr-bridge.php';
require_once ZHR_DIR . 'includes/class-zhr-forgot.php';
require_once ZHR_DIR . 'includes/class-zhr-pack.php';

/** A pack's rules.yml `hours` and compensation.yml `hourly` blocks write the settings (ZHR_Pack). */
add_filter( 'zdz_identity_pack_consumers', array( 'ZHR_Pack', 'register' ) );

/* -------------------------------------------------------------------------
 * Settings: one set for everyone, from the option the app's Settings (or a pack) writes.
 * ---------------------------------------------------------------------- */

/**
 * The settings in force. A new install has none of its own: no standard hours, no rules, the site's own first day of
 * the week. Filter zhr_settings lets a pack's consumer supply them; the result is always cleaned.
 */
function zhr_get_settings() {
	$wp_ws = (int) get_option( 'start_of_week', 1 );            // WordPress: 0 = Sunday … 6 = Saturday
	$base  = ZHR_Time::default_settings( 0 === $wp_ws ? 7 : max( 1, min( 6, $wp_ws ) ) );
	$saved = get_option( 'zhr_settings', array() );
	$saved = apply_filters( 'zhr_settings', is_array( $saved ) ? $saved : array() );
	return ZHR_Time::clean_settings( is_array( $saved ) ? $saved : array(), $base );
}

/* -------------------------------------------------------------------------
 * People: who holds Hours, who is the admin tier, and the ladder of who may see whose hours.
 * ---------------------------------------------------------------------- */

/** The staff roles that make someone part of the team. Never the shared-device role. */
function zhr_staff_roles() {
	return array( 'administrator', 'zdz_owner', 'zdz_admin', 'zdz_sales', 'zdz_operator', 'zdz_mfg', 'zdz_tech' );
}

/** The shared device, or this request running in demo mode: never a person in Hours. */
function zhr_is_shared( $uid ) {
	$uid = (int) $uid;
	if ( $uid <= 0 ) {
		return false;
	}
	if ( class_exists( 'ZDZ_Kiosk_Demo' ) && method_exists( 'ZDZ_Kiosk_Demo', 'is_active' ) && ZDZ_Kiosk_Demo::is_active() && $uid === (int) get_current_user_id() ) {
		return true;
	}
	if ( class_exists( 'ZDZ_User_Roles' ) && method_exists( 'ZDZ_User_Roles', 'is_shared_device' ) && ZDZ_User_Roles::is_shared_device( $uid ) ) {
		return true;
	}
	if ( class_exists( 'ZDZ_Hierarchy' ) && method_exists( 'ZDZ_Hierarchy', 'is_kiosk' ) && ZDZ_Hierarchy::is_kiosk( $uid ) ) {
		return true;
	}
	$u = get_userdata( $uid );
	return $u ? in_array( 'zdz_general', (array) $u->roles, true ) : false;
}

/**
 * May this person open Hours? The platform's app access; never the shared device or demo mode, and never an account in
 * Privacy View (hours are pay data, and the people they belong to are named).
 */
function zhr_user_can_use( $uid ) {
	$uid = (int) $uid;
	if ( $uid <= 0 || zhr_is_shared( $uid ) ) {
		return false;
	}
	if ( class_exists( 'ZDZ_Privacy_View' ) && method_exists( 'ZDZ_Privacy_View', 'is_on' ) && ZDZ_Privacy_View::is_on( $uid ) ) {
		return false;
	}
	if ( class_exists( 'ZDZ_Plugin_API' ) && method_exists( 'ZDZ_Plugin_API', 'user_can_access_app' ) ) {
		return (bool) ZDZ_Plugin_API::user_can_access_app( $uid, ZHR_APP_ID );
	}
	return function_exists( 'user_can' ) && user_can( $uid, 'manage_options' );
}

/** The admin tier: sees and changes everyone's hours, sets the standard hours and the rules. */
function zhr_user_is_admin( $uid ) {
	$uid = (int) $uid;
	if ( $uid <= 0 || zhr_is_shared( $uid ) ) {
		return false;
	}
	if ( class_exists( 'ZDZ_Capabilities' ) && method_exists( 'ZDZ_Capabilities', 'tier_of' ) ) {
		return 'admin' === ZDZ_Capabilities::tier_of( $uid );
	}
	return function_exists( 'user_can' ) && user_can( $uid, 'manage_options' );
}

/** Is $viewer the crew lead of $target (the platform's crews)? */
function zhr_leads( $viewer, $target ) {
	return class_exists( 'ZDZ_Hierarchy' )
		&& method_exists( 'ZDZ_Hierarchy', 'is_crew_lead' ) && method_exists( 'ZDZ_Hierarchy', 'is_lead_of' )
		&& ZDZ_Hierarchy::is_crew_lead( (int) $viewer ) && ZDZ_Hierarchy::is_lead_of( (int) $viewer, (int) $target );
}

/**
 * May $viewer reach $target's hours for $action ('view' | 'change' | 'export')? The facts for ZHR_Access::decide(),
 * gathered here; the decision is the ladder's alone.
 */
function zhr_access( $viewer, $target, $action = 'view' ) {
	$viewer = (int) $viewer;
	$target = (int) $target;
	$s      = zhr_get_settings();
	return ZHR_Access::decide( array(
		'viewer'        => $viewer,
		'target'        => $target,
		'action'        => $action,
		'viewer_shared' => zhr_is_shared( $viewer ),
		'viewer_holds'  => zhr_user_can_use( $viewer ),
		'viewer_admin'  => zhr_user_is_admin( $viewer ),
		// a real person, never the shared device; someone who no longer holds Hours keeps their past hours for the office
		'target_ok'     => $target > 0 && $target !== $viewer ? ( (bool) get_userdata( $target ) && ! zhr_is_shared( $target ) ) : $target > 0,
		'leads_target'  => $target !== $viewer && ! empty( $s['crew_leads'] ) && zhr_leads( $viewer, $target ),
		'crew_leads'    => ! empty( $s['crew_leads'] ),
	) );
}

/** A person's initials from their display name (the platform's person colour has the chosen ones). */
function zhr_initials_of( $uid ) {
	if ( class_exists( 'ZDZ_Person_Color' ) && method_exists( 'ZDZ_Person_Color', 'for_user' ) ) {
		$p = ZDZ_Person_Color::for_user( (int) $uid );
		if ( is_array( $p ) && '' !== (string) ( $p['initials'] ?? '' ) ) {
			return (string) $p['initials'];
		}
	}
	$u = get_userdata( (int) $uid );
	if ( ! $u ) {
		return '?';
	}
	$parts = array_slice( preg_split( '/\s+/', trim( (string) $u->display_name ) ), 0, 2 );
	return strtoupper( implode( '', array_map( function ( $w ) { return function_exists( 'mb_substr' ) ? mb_substr( $w, 0, 1 ) : substr( $w, 0, 1 ); }, $parts ) ) );
}

/**
 * Everyone who holds Hours: id, display name, first and last name, initials (never an e-mail or a role). Once per
 * request. The names are what a reading's names are matched against, exactly (ZHR_Attribution).
 */
function zhr_people() {
	static $memo = null;
	if ( null !== $memo ) {
		return $memo;
	}
	$out = array();
	foreach ( get_users( array( 'fields' => array( 'ID', 'display_name' ), 'orderby' => 'display_name', 'number' => 500 ) ) as $u ) {
		$uid = (int) $u->ID;
		if ( ! zhr_user_can_use( $uid ) ) {
			continue;
		}
		$out[] = array(
			'id'       => $uid,
			'name'     => (string) $u->display_name,
			'first'    => (string) get_user_meta( $uid, 'first_name', true ),
			'last'     => (string) get_user_meta( $uid, 'last_name', true ),
			'initials' => zhr_initials_of( $uid ),
		);
	}
	$memo = $out;
	return $out;
}

/**
 * When the business turns on "everyone on staff has Hours", every staff role holds it, through the platform's ADD-only
 * per-person grant: it comes after Safe Mode and the per-person deny list, so an admin can still take Hours away from
 * one person in User Management. Never the shared device. Reads roles only (never zhr_user_can_use(), which asks the
 * platform, which asks this filter).
 */
add_filter(
	'zdz_extra_allowed_apps',
	function ( $apps, $user_id = 0 ) {
		$apps = is_array( $apps ) ? $apps : array();
		if ( in_array( ZHR_APP_ID, $apps, true ) ) {
			return $apps;
		}
		$s = zhr_get_settings();
		if ( empty( $s['everyone'] ) ) {
			return $apps;
		}
		$u  = get_userdata( (int) $user_id );
		$pv = class_exists( 'ZDZ_Privacy_View' ) && method_exists( 'ZDZ_Privacy_View', 'is_on' ) && ZDZ_Privacy_View::is_on( (int) $user_id );
		if ( $u && ! $pv && ! zhr_is_shared( (int) $user_id ) && array_intersect( (array) $u->roles, zhr_staff_roles() ) ) {
			$apps[] = ZHR_APP_ID;
		}
		return $apps;
	},
	10,
	2
);

/* -------------------------------------------------------------------------
 * Install, upgrade, the 15-minute collector.
 * ---------------------------------------------------------------------- */

add_filter(
	'cron_schedules',
	function ( $s ) {
		if ( ! isset( $s['zhr_15min'] ) ) {
			$s['zhr_15min'] = array( 'interval' => 15 * MINUTE_IN_SECONDS, 'display' => __( 'Every 15 minutes (Hours)', 'zorderz' ) );
		}
		return $s;
	}
);

/** Activation (called by the bundle activator through the manifest): the tables and the collector. Nothing is seeded. */
function zhr_activate() {
	ZHR_DB::create_tables();
	update_option( 'zhr_db_version', ZHR_DB_VERSION, false );
	if ( ! wp_next_scheduled( 'zhr_collect' ) ) {
		wp_schedule_event( time() + 120, 'zhr_15min', 'zhr_collect' );
	}
}

function zhr_deactivate() {
	wp_clear_scheduled_hook( 'zhr_collect' );
}

add_action(
	'plugins_loaded',
	function () {
		if ( get_option( 'zhr_db_version' ) !== ZHR_DB_VERSION ) {
			ZHR_DB::create_tables(); // an app replaced by upload is not re-activated
			update_option( 'zhr_db_version', ZHR_DB_VERSION, false );
		}
		ZHR_REST::init();
		ZHR_Collect::init();
		ZHR_Bridge::init();
		ZHR_Forgot::init();
	},
	20
);

add_action(
	'init',
	function () {
		if ( ! wp_next_scheduled( 'zhr_collect' ) ) {
			wp_schedule_event( time() + 120, 'zhr_15min', 'zhr_collect' );
		}
	}
);

/* -------------------------------------------------------------------------
 * The dashboard app.
 * ---------------------------------------------------------------------- */

add_action(
	'after_setup_theme',
	function () {
		if ( ! interface_exists( '\Zorderz\Widget_App_Interface' ) ) {
			return; // degrade gracefully on a missing or older theme
		}
		require_once ZHR_DIR . 'includes/class-zhr-app.php';
		add_filter(
			'zdz_register_apps',
			function ( $apps ) {
				if ( class_exists( 'ZHR_App' ) ) {
					$apps[ ZHR_APP_ID ] = new ZHR_App();
				}
				return $apps;
			}
		);
	},
	20
);

/** Loads the app's script and style once, with what the page needs to know. */
function zhr_enqueue_app( $user_id ) {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	$s    = zhr_get_settings();
	$ver  = function ( $rel ) {
		$m = @filemtime( ZHR_DIR . $rel );
		return $m ? ZHR_VERSION . '.' . $m : ZHR_VERSION;
	};
	wp_enqueue_style( 'zhr-app', ZHR_URL . 'assets/app.css', array(), $ver( 'assets/app.css' ) );
	$deps = ( wp_script_is( 'zdz-kit-js', 'registered' ) || wp_script_is( 'zdz-kit-js', 'enqueued' ) ) ? array( 'zdz-kit-js' ) : array();
	wp_enqueue_script( 'zhr-app', ZHR_URL . 'assets/app.js', $deps, $ver( 'assets/app.js' ), true );
	$admin  = zhr_user_is_admin( $user_id );
	$people = array();
	foreach ( zhr_people() as $p ) {
		if ( $admin ) {
			$people[] = array( 'id' => $p['id'], 'name' => $p['name'], 'initials' => $p['initials'], 'admin' => zhr_user_is_admin( $p['id'] ) ); // no "See it as …" for another admin
		} elseif ( (int) $p['id'] !== (int) $user_id && zhr_access( $user_id, $p['id'], 'view' )['allow'] ) {
			$people[] = array( 'id' => $p['id'], 'name' => $p['name'], 'initials' => $p['initials'], 'admin' => false ); // a crew lead's crew
		}
	}
	$u = get_userdata( (int) $user_id );
	wp_localize_script(
		'zhr-app',
		'ZHR_CONFIG',
		array(
			'restPath'     => ZHR_REST::ns() . ZHR_REST::BASE . '/',
			'version'      => ZHR_VERSION,
			'userId'       => (int) $user_id,
			'userName'     => $u ? $u->display_name : '',
			'isAdmin'      => $admin ? '1' : '',
			'people'       => $people,
			'weekStart'    => (int) $s['week_start'],
			'askLocation'  => $s['ask_location'] ? '1' : '',
			'noteLocation' => $s['note_location'] ? '1' : '',
			'reader'       => ZHR_Parse::ready() ? '1' : '',
			'payPeriod'    => '' !== $s['pay_period']['kind'] ? '1' : '',
			'mealRules'    => ZHR_Rules::has_meals( $s ) ? '1' : '',
			'mealWords'    => ZHR_Rules::meal_words( $s['meals'] ),
			'premiumWords' => $s['meals']['premium_minutes'] > 0 ? ZHR_Rules::length_words( $s['meals']['premium_minutes'] ) : '',
			// a plain URL: wp_nonce_url() returns '&amp;' for HTML, which a link built in the browser would send as 'amp;_wpnonce'
			'exportUrl'    => add_query_arg( array( 'action' => 'zhr_export', '_wpnonce' => wp_create_nonce( 'zhr_export' ) ), admin_url( 'admin-post.php' ) ),
			'today'        => current_time( 'Y-m-d' ),
		)
	);
}

/* -------------------------------------------------------------------------
 * The accountant's file: admin-post.php?action=zhr_export&kind=days|entries&user=<id>|all&period=…&from=&to=  (nonce).
 * Everyone may download their own; the admin tier anyone's or everyone's.
 * ---------------------------------------------------------------------- */

add_action( 'admin_post_zhr_export', 'zhr_export' );

function zhr_export() {
	$me = get_current_user_id();
	if ( ! $me || ! zhr_user_can_use( $me ) || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'zhr_export' ) ) {
		wp_die( esc_html__( 'You are signed out or not allowed to do that. Reload Hours and try again.', 'zorderz' ), esc_html__( 'Hours', 'zorderz' ), array( 'response' => 403 ) );
	}
	$out = zhr_export_csv(
		$me,
		isset( $_GET['user'] ) ? sanitize_text_field( wp_unslash( $_GET['user'] ) ) : 'me',
		isset( $_GET['kind'] ) && 'entries' === $_GET['kind'] ? 'entries' : 'days',
		isset( $_GET['period'] ) ? sanitize_key( wp_unslash( $_GET['period'] ) ) : 'custom',
		isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '',
		isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : ''
	);
	if ( is_wp_error( $out ) ) {
		wp_die( esc_html( $out->get_error_message() ), esc_html__( 'Hours', 'zorderz' ), array( 'response' => 403 ) );
	}
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . $out['filename'] . '"' );
	echo $out['csv']; // phpcs:ignore WordPress.Security.EscapeOutput -- every cell went through ZHR_Time::csv_line
	exit;
}

/**
 * The CSV for $me's request: ['filename', 'csv'] or WP_Error. $who: 'me', a user id, or 'all' (admin tier only).
 * $kind: 'days' (one row per person per day: hours on the clock, and with the business's rules the meal periods, any
 * meal premium owed, and overtime) or 'entries' (every stretch, with where and how backed). A file leaves the app: notes
 * show "a customer" / "[private]" where a private detail was, never the detail.
 */
function zhr_export_csv( $me, $who, $kind, $period, $from, $to ) {
	$admin = zhr_user_is_admin( $me );
	$s     = zhr_get_settings();
	list( $from, $to ) = ZHR_Time::period( $period, current_time( 'Y-m-d' ), (int) $s['week_start'], $from, $to, $s['pay_period'] );
	if ( 'all' === $who ) {
		if ( ! $admin ) {
			return new WP_Error( 'forbidden', __( 'You can only download your own hours.', 'zorderz' ) );
		}
		$uids = wp_list_pluck( zhr_people(), 'id' );
	} else {
		$uid = ( 'me' === $who || '' === $who ) ? (int) $me : (int) $who;
		if ( $uid <= 0 || ! get_userdata( $uid ) ) { // 0 would read everyone's entries as one person's
			return new WP_Error( 'no_person', __( 'That person was not found.', 'zorderz' ) );
		}
		if ( ! zhr_access( $me, $uid, 'export' )['allow'] ) {
			return new WP_Error( 'forbidden', __( 'You can only download your own hours.', 'zorderz' ) );
		}
		$uids = array( $uid );
	}
	$names = array();
	foreach ( $uids as $u ) {
		$ud          = get_userdata( (int) $u );
		$names[ $u ] = $ud ? $ud->display_name : __( 'Person', 'zorderz' ) . ' ' . (int) $u;
	}
	$label = 'all' === $who ? 'everyone' : sanitize_file_name( strtolower( str_replace( ' ', '-', $names[ $uids[0] ] ?? 'me' ) ) );
	$csv   = "\xEF\xBB\xBF"; // spreadsheets read UTF-8 with the mark
	$now   = time();
	$meals = ZHR_Rules::has_meals( $s );
	if ( 'entries' === $kind ) {
		// a person's own file leaves out what their own screen does not show them: how much the app backs each stretch
		// and where the phone was. The admin tier's (the accountant's) file has everything.
		$drop = $admin ? array() : array( 9, 10, 11 );
		$line = function ( array $cells ) use ( $drop ) {
			foreach ( $drop as $i ) {
				unset( $cells[ $i ] );
			}
			return ZHR_Time::csv_line( array_values( $cells ) );
		};
		$csv .= $line( ZHR_Time::csv_header() );
		foreach ( $uids as $u ) {
			$events = array();
			foreach ( ZHR_DB::activity( (int) $u, $from . ' 00:00:00', ZHR_Time::add_days( $to, 1 ) . ' 23:59:59', 50000 ) as $a ) {
				if ( 'clock' === $a['kind'] ) {
					continue;
				}
				$t = ZHR_Time::ts( substr( (string) $a['at'], 0, 19 ) );
				if ( null !== $t ) {
					$events[] = array( 'ts' => $t, 'kind' => $a['kind'], 'until' => ! empty( $a['until'] ) ? (int) ZHR_Time::ts( substr( (string) $a['until'], 0, 19 ) ) : 0 );
				}
			}
			foreach ( ZHR_DB::entries( (int) $u, $from, $to ) as $e ) {
				if ( $e['work_date'] < $from || $e['work_date'] > $to ) {
					continue;
				}
				$end = empty( $e['end_at'] ) ? $now : (int) ZHR_Time::ts( $e['end_at'] );
				if ( empty( $e['end_at'] ) ) {
					// only a clock really running counts on; past 16 hours or with the office it counts nothing
					$live         = 'live' === ZHR_Time::open_state( $e, $now );
					$e['minutes'] = $live ? (int) floor( max( 0, $now - (int) ZHR_Time::ts( $e['start_at'] ) ) / 60 ) : 0;
					$end          = $live ? $now : (int) ZHR_Time::ts( $e['start_at'] ) + 60;
				}
				$sts       = (int) ZHR_Time::ts( $e['start_at'] );
				$pct       = $admin ? ZHR_Time::evidence( $sts, $end, ZHR_Time::events_near( $events, $sts, $end ) )['pct'] : '';
				$e['note'] = ZHR_Private::decode( $e['note'], false );
				$fs        = ZHR_Forgot::finish_set( $e );
				if ( $fs ) {
					$e['finish_set_by']     = $fs['by'];
					$e['finish_set_office'] = $fs['office'];
				}
				$csv .= $line( ZHR_Time::csv_row( $e, $names[ $u ], $pct ) );
			}
		}
	} else {
		$ot_on = $admin && ZHR_Rules::has_overtime( $s );
		$head  = array( __( 'Person', 'zorderz' ), __( 'Date', 'zorderz' ), __( 'Weekday', 'zorderz' ), __( 'Hours worked', 'zorderz' ), __( 'Minutes worked', 'zorderz' ) );
		if ( $admin ) {
			$head[] = __( 'Standard minutes', 'zorderz' );
		}
		$head = array_merge( $head, array( __( 'Day off', 'zorderz' ), __( 'Stretches', 'zorderz' ) ) );
		if ( $meals ) {
			$head = array_merge( $head, array( __( 'Meal period 1', 'zorderz' ), __( 'Meal period 2', 'zorderz' ), __( 'Meal premium (hours owed)', 'zorderz' ), __( 'Meal question unanswered', 'zorderz' ) ) );
		}
		if ( $ot_on ) {
			$head[] = __( 'Overtime minutes', 'zorderz' );
		}
		$head[] = __( 'Shifts with no finish yet (not counted)', 'zorderz' );
		$csv   .= ZHR_Time::csv_line( $head );
		foreach ( $uids as $u ) {
			$by = array();
			foreach ( ZHR_DB::entries( (int) $u, $from, $to ) as $e ) {
				if ( $e['work_date'] >= $from && $e['work_date'] <= $to ) {
					$by[ $e['work_date'] ][] = $e;
				}
			}
			$answers = ZHR_DB::meal_answers( (int) $u, $from, $to )[ (int) $u ] ?? array();
			$w       = ZHR_REST::waivers_of( (int) $u );
			$ot      = $ot_on ? ZHR_REST::overtime_for( (int) $u, $from, $to, $s ) : null;
			$tot     = array();
			foreach ( ZHR_Time::dates( $from, $to ) as $d ) {
				$t     = ZHR_Time::day_totals( $s, $d, $by[ $d ] ?? array(), $now, array( 'waiver1' => $w['first_periods'], 'waiver2' => $w['second_periods'], 'answers' => $answers[ $d ] ?? array() ) );
				$tot[] = $t;
				$row   = array( $names[ $u ], $d, ( new DateTimeImmutable( $d . ' 12:00:00' ) )->format( 'D' ), ZHR_Time::dec( $t['worked'] ), $t['worked'] );
				if ( $admin ) {
					$row[] = $t['standard'];
				}
				$row = array_merge( $row, array( $t['off_day'] ? __( 'yes', 'zorderz' ) : '', $t['entries'] ) );
				if ( $meals ) {
					$mw = array( '', '' );
					foreach ( $t['meals']['meals'] as $m ) {
						if ( 'not_required' !== $m['status'] && $m['no'] <= 2 ) {
							$mw[ $m['no'] - 1 ] = ZHR_Time::meal_words( $m ) . ( $m['violation'] ? ' -- ' . ( 'voluntary' === $m['answer'] ? __( 'the person\'s own choice', 'zorderz' ) : ( 'not_provided' === $m['answer'] ? __( 'not provided', 'zorderz' ) : __( 'not answered', 'zorderz' ) ) ) : '' );
						}
					}
					$row = array_merge( $row, array( $mw[0], $mw[1], $t['meals']['premium'] ? ZHR_Time::dec( $t['meals']['premium_minutes'] ) : '', $t['meals']['needs_answer'] ? __( 'yes', 'zorderz' ) : '' ) );
				}
				if ( $ot_on ) {
					$row[] = (string) (int) ( $ot['days'][ $d ]['overtime'] ?? 0 );
				}
				$row[] = $t['unfinished'] ? (string) $t['unfinished'] : '';
				$csv  .= ZHR_Time::csv_line( $row );
			}
			$r   = ZHR_Time::range_totals( $tot );
			$row = array( $names[ $u ], __( 'TOTAL', 'zorderz' ), $from . ' – ' . $to, ZHR_Time::dec( $r['worked'] ), $r['worked'] );
			if ( $admin ) {
				$row[] = $r['standard'];
			}
			$row = array_merge( $row, array( '', '' ) );
			if ( $meals ) {
				$row = array_merge( $row, array( '', '', $r['meal_premium_minutes'] ? ZHR_Time::dec( $r['meal_premium_minutes'] ) : '', (string) $r['meal_open'] ) );
			}
			if ( $ot_on ) {
				$row[] = (string) (int) ( $ot['overtime'] ?? 0 );
			}
			$row[] = $r['unfinished'] ? (string) $r['unfinished'] : '';
			$csv  .= ZHR_Time::csv_line( $row );
		}
	}
	return array( 'filename' => 'hours-' . $kind . '-' . $from . '-to-' . $to . '-' . $label . '.csv', 'csv' => $csv );
}

/* -------------------------------------------------------------------------
 * Zorderz → Hours (wp-admin): what is stored and when it last collected. Everything else is in the app.
 * ---------------------------------------------------------------------- */

add_action(
	'admin_menu',
	function () {
		$parent = menu_page_url( 'zdz-core-settings', false ) ? 'zdz-core-settings' : 'options-general.php';
		add_submenu_page( $parent, __( 'Hours', 'zorderz' ), __( 'Hours', 'zorderz' ), 'manage_options', 'zhr-settings', 'zhr_admin_page' );
	},
	40
);

function zhr_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( isset( $_POST['zhr_collect_now'] ) && check_admin_referer( 'zhr_collect_now' ) ) {
		$n = ZHR_Collect::run();
		/* translators: %d: number of rows */
		echo '<div class="notice notice-success"><p>' . esc_html( sprintf( __( 'Collected %d new activity rows.', 'zorderz' ), (int) $n ) ) . '</p></div>';
	}
	$st    = ZHR_DB::stats();
	$last  = get_option( 'zhr_last_collect', null );
	$s     = zhr_get_settings();
	$names = array( 1 => __( 'Mon', 'zorderz' ), __( 'Tue', 'zorderz' ), __( 'Wed', 'zorderz' ), __( 'Thu', 'zorderz' ), __( 'Fri', 'zorderz' ), __( 'Sat', 'zorderz' ), __( 'Sun', 'zorderz' ) );
	$bits  = array();
	foreach ( $s['days'] as $n => $row ) {
		$bits[] = $names[ $n ] . ' ' . ( $row['on'] ? $row['start'] . '–' . $row['end'] : __( 'off', 'zorderz' ) );
	}
	$meal = ZHR_Rules::meal_words( $s['meals'] );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Hours', 'zorderz' ); ?></h1>
		<p><?php esc_html_e( 'Hours is on the home screen for everyone who holds it (User Management). Its standard hours and rules are set inside the app (Settings, for the admin tier); signed meal waivers on a person\'s page in it.', 'zorderz' ); ?></p>
		<p><a class="button button-primary" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Open the app', 'zorderz' ); ?></a></p>
		<p><strong><?php esc_html_e( 'Stored:', 'zorderz' ); ?></strong>
			<?php
			echo esc_html( sprintf(
				/* translators: 1: entries, 2: clocks running, 3: activity rows */
				__( '%1$d entries (%2$d clocks running now), %3$d activity rows.', 'zorderz' ),
				(int) $st['entries'],
				(int) $st['running'],
				(int) $st['activity']
			) );
			if ( is_array( $last ) ) {
				echo ' ' . esc_html( sprintf(
					/* translators: 1: when, 2: rows added */
					__( 'Last collected %1$s (%2$d new).', 'zorderz' ),
					(string) $last['at'],
					(int) $last['added']
				) );
			}
			?>
		</p>
		<p><strong><?php esc_html_e( 'Standard hours:', 'zorderz' ); ?></strong> <?php echo esc_html( ZHR_Time::has_standard( $s ) ? implode( ' · ', $bits ) : __( 'none set', 'zorderz' ) ); ?></p>
		<p><strong><?php esc_html_e( 'Meal breaks:', 'zorderz' ); ?></strong> <?php echo esc_html( $meal ? implode( ' ', $meal ) . ' ' . __( 'Recorded by clocking out for them; nothing is deducted.', 'zorderz' ) : __( 'no rules set: no meal break is required or asked about.', 'zorderz' ) ); ?></p>
		<p><strong><?php esc_html_e( 'Reminder for a clock still running:', 'zorderz' ); ?></strong>
			<?php echo esc_html( '' === (string) $s['remind_at'] ? __( 'off', 'zorderz' ) : $s['remind_at'] . ' ' . __( '(one notice a shift; the person sets the time they finished, never the app)', 'zorderz' ) ); ?></p>
		<p><strong><?php esc_html_e( 'Reading words and photos:', 'zorderz' ); ?></strong> <?php echo esc_html( ZHR_Parse::ready_label() ); ?>.
			<?php echo esc_html( ZHR_Parse::heic_readable() ? __( 'Phone photos in HEIC are turned into JPEG on this site before they are read.', 'zorderz' ) : __( 'This site cannot turn HEIC photos into JPEG; the app sends JPEG from the phone, and a HEIC file is refused in words.', 'zorderz' ) ); ?></p>
		<form method="post"><?php wp_nonce_field( 'zhr_collect_now' ); ?><p><button class="button" name="zhr_collect_now" value="1"><?php esc_html_e( 'Collect app activity now', 'zorderz' ); ?></button></p></form>
	</div>
	<?php
}

/**
 * Carry an install's earlier Hours data across to this app's names. The platform migration performs the renames; a
 * fresh install has none of these and every entry is a no-op.
 */
add_filter(
	'zdz_rename_map',
	function ( $map ) {
		$map['tables']    = array_merge(
			$map['tables'] ?? array(),
			array(
				'tshr_entries'  => 'zhr_entries',
				'tshr_meals'    => 'zhr_meals',
				'tshr_activity' => 'zhr_activity',
			)
		);
		$map['options']   = array_merge(
			$map['options'] ?? array(),
			array(
				'tshr_settings'       => 'zhr_settings',
				'tshr_db_version'     => 'zhr_db_version',
				'tshr_last_collect'   => 'zhr_last_collect',
				'tshr_cursor_audit'   => 'zhr_cursor_audit',
				'tshr_cursor_media'   => 'zhr_cursor_media',
				'tshr_cursor_jobs'    => 'zhr_cursor_jobs',
			)
		);
		$map['user_meta'] = array_merge(
			$map['user_meta'] ?? array(),
			array(
				'tshr_meal_waiver1' => 'zhr_meal_waiver1',
				'tshr_meal_waiver2' => 'zhr_meal_waiver2',
				'tshr_on_meal'      => 'zhr_on_meal',
				'tshr_open_notice'  => 'zhr_open_notice',
			)
		);
		$map['cron']      = array_merge( $map['cron'] ?? array(), array( 'tshr_collect' => 'zhr_collect' ) );
		return $map;
	}
);
