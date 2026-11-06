<?php
/**
 * Module: Zorderz - Territories
 * Description: Who gets a new estimate stub and who does a won job, by rules instead of by hand. Each
 *   salesperson has a set of ZIP codes; zones say where each kind of work is offered and where a person is
 *   never sent; Team Roles says who can sell, install or repair each item; ordered rules, each readable as a
 *   sentence, pick the person. Every rule starts in shadow: the asking app keeps its own pick and the rules'
 *   choice is recorded next to it, so the two can be compared before a rule goes live, one rule at a time.
 *   Work outside every service area is marked for a referral. Other apps read territories through read
 *   verbs on the capability registry.
 * Version:     1.0.0
 * Author:      Zorderz
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: zorderz
 * Requires PHP: 8.0
 *
 * This is a bundled app module (loaded by zorderz-apps.php), not a standalone plugin. It registers with the
 * theme through the `zdz_register_apps` filter on after_setup_theme and declines cleanly when the theme is
 * absent.
 *
 * CORE-SERVICE BINDINGS
 *   - Who can      : Team Roles (zdz_person_can / zdz_people_who_can / zdz_abilities); without it a rule
 *                    that needs "can do" never matches. Never assume Can.
 *   - Items        : the catalog (ZDZ_Item_Engine) through Team Roles; kinds of project from the Jobs app.
 *   - Initials     : the platform's short code (ZDZ_Person_Color::META_INITIALS).
 *   - Alerts       : ZDZ_Alert_Router (type territories_assigned); a direct message when Messaging is here.
 *   - Verbs        : territories.* on zdz_register_capabilities, all reads.
 *
 * SHIPS EMPTY. No territory, zone, rule (other than "everything else", which names nobody), word or person.
 * An empty configuration assigns NOBODY: the decision is marked for a person to review.
 *
 * @package Zorderz\Territories
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ZTER_VERSION', '1.0.0' );
define( 'ZTER_DB_VERSION', '1.0.0' );
define( 'ZTER_APP_ID', 'territories' );
define( 'ZTER_FILE', __FILE__ );
define( 'ZTER_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZTER_URL', plugin_dir_url( __FILE__ ) );

require_once ZTER_DIR . 'includes/functions-zter-people.php';
require_once ZTER_DIR . 'includes/class-zter-db.php';
require_once ZTER_DIR . 'includes/class-zter-kinds.php';
require_once ZTER_DIR . 'includes/class-zter-rules.php';
require_once ZTER_DIR . 'includes/class-zter-zones.php';
require_once ZTER_DIR . 'includes/class-zter-verbs.php';
require_once ZTER_DIR . 'includes/class-zter-notify.php';
require_once ZTER_DIR . 'includes/class-zter-testdata.php';
require_once ZTER_DIR . 'includes/class-zter-rest.php';
require_once ZTER_DIR . 'includes/class-zter-asks.php';
require_once ZTER_DIR . 'includes/class-zter-pack.php';

/** territories.yml in an Identity Pack: zones, rules (always in shadow) and people's ZIP codes. */
add_filter( 'zdz_identity_pack_consumers', array( 'ZTER_Pack', 'register' ) );

/** Activation (called by the bundle activator through the manifest): the tables and the one "everything else" rule. */
function zter_activate() {
	ZTER_DB::create_tables();
	ZTER_Rules::seed();
	if ( false === get_option( 'zter_installed', false ) ) {
		add_option( 'zter_installed', ZTER_DB::now(), '', 'no' );
	}
	update_option( 'zter_db_version', ZTER_DB_VERSION, false );
}

/** Deactivation: nothing scheduled, nothing to tear down. */
function zter_deactivate() {}

/**
 * Salespeople, reviewers and anyone holding a territory hold the app, through the platform's ADD-only
 * per-person grant; an admin can still take it away from one person.
 */
add_filter(
	'zdz_extra_allowed_apps',
	function ( $apps, $user_id = 0 ) {
		$apps = is_array( $apps ) ? $apps : array();
		$uid  = (int) $user_id;
		if ( in_array( ZTER_APP_ID, $apps, true ) || ! zter_is_team_member( $uid ) ) {
			return $apps;
		}
		if ( zter_can_review( $uid ) || ZTER_DB::zips_of( $uid ) ) {
			$apps[] = ZTER_APP_ID;
		}
		return $apps;
	},
	10,
	2
);

add_action(
	'plugins_loaded',
	function () {
		if ( get_option( 'zter_db_version' ) !== ZTER_DB_VERSION ) {
			zter_activate(); // an app replaced by upload is not re-activated
		}
		ZTER_Verbs::init();
		ZTER_REST::init();
		ZTER_Notify::init();
	},
	20
);

/** wp-admin shows what is stored; everything else is in the app. */
add_action(
	'admin_menu',
	function () {
		$parent = menu_page_url( 'zdz-core-settings', false ) ? 'zdz-core-settings' : 'options-general.php';
		add_submenu_page( $parent, __( 'Territories', 'zorderz' ), __( 'Territories', 'zorderz' ), 'manage_options', 'zter-settings', 'zter_admin_page' );
	},
	41
);

function zter_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$st    = ZTER_DB::stats();
	$r     = ZTER_Rules::get( 'stub' );
	$ir    = ZTER_Rules::get( 'install' );
	$on    = function ( $x ) { return ! empty( $x['live'] ); };
	$live  = count( array_filter( $r['rows'], $on ) );
	$ilive = count( array_filter( $ir['rows'], $on ) );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Territories', 'zorderz' ); ?></h1>
		<p><?php esc_html_e( 'Territories lives in the app (Sales group). The admin tier edits each salesperson\'s ZIP codes, the zones and the rules there, and compares what the rules would have chosen with what happened.', 'zorderz' ); ?></p>
		<p><strong><?php esc_html_e( 'Stored:', 'zorderz' ); ?></strong>
			<?php
			echo esc_html( sprintf(
				/* translators: 1: ZIP rows, 2: people, 3: stub rules version, 4: stub rules, 5: live stub rules, 6: install rules version, 7: install rules, 8: live install rules, 9: decisions */
				__( '%1$d ZIP assignments for %2$d people; stub rules version %3$d (%4$d rules, %5$d live); install rules version %6$d (%7$d rules, %8$d live); %9$d decisions recorded.', 'zorderz' ),
				(int) $st['zips'], (int) $st['people'], (int) $r['version'], count( $r['rows'] ), $live, (int) $ir['version'], count( $ir['rows'] ), $ilive, (int) $st['decisions']
			) );
			?>
		</p>
		<p><strong><?php esc_html_e( 'For other apps:', 'zorderz' ); ?></strong>
			<code>territories.of_person</code>, <code>territories.for_zip</code>, <code>territories.map</code>, <code>territories.route_for</code>,
			<code>territories.zones</code>, <code>territories.installer_for</code>, <code>territories.uncovered</code>,
			<code>territories.preview_route</code> <?php esc_html_e( '(read verbs on the capability registry).', 'zorderz' ); ?>
		</p>
	</div>
	<?php
}

/** The dashboard app (theme interface — after_setup_theme). */
add_action(
	'after_setup_theme',
	function () {
		if ( ! interface_exists( '\Zorderz\Widget_App_Interface' ) ) {
			return;
		}
		require_once ZTER_DIR . 'includes/class-zter-app.php';
		add_filter(
			'zdz_register_apps',
			function ( $apps ) {
				if ( class_exists( 'ZTER_App' ) ) {
					$apps[ ZTER_APP_ID ] = new ZTER_App();
				}
				return $apps;
			}
		);
	},
	20
);

/** Loads the app's script and style once, with what the page needs to know. */
function zter_enqueue_app( $user_id ) {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	$ver  = function ( $rel ) {
		$m = @filemtime( ZTER_DIR . $rel );
		return $m ? ZTER_VERSION . '.' . $m : ZTER_VERSION;
	};
	wp_enqueue_style( 'zter-app', ZTER_URL . 'assets/app.css', array(), $ver( 'assets/app.css' ) );
	$deps = ( wp_script_is( 'zdz-kit-js', 'registered' ) || wp_script_is( 'zdz-kit-js', 'enqueued' ) ) ? array( 'zdz-kit-js' ) : array();
	wp_enqueue_script( 'zter-app', ZTER_URL . 'assets/app.js', $deps, $ver( 'assets/app.js' ), true );
	wp_localize_script(
		'zter-app',
		'ZTER_CONFIG',
		array(
			'restPath' => ZTER_REST::ns() . ZTER_REST::BASE . '/',
			'version'  => ZTER_VERSION,
			'userId'   => (int) $user_id,
		)
	);
}

/**
 * Carry an install's earlier Territories data across to this app's names. The platform migration performs the
 * renames; a fresh install has none of these and every entry is a no-op.
 */
add_filter(
	'zdz_rename_map',
	function ( $map ) {
		$map['tables']  = array_merge(
			$map['tables'] ?? array(),
			array(
				'tster_zips'      => 'zter_zips',
				'tster_decisions' => 'zter_decisions',
				'tster_history'   => 'zter_history',
			)
		);
		$map['options'] = array_merge(
			$map['options'] ?? array(),
			array(
				'tster_rules'         => 'zter_rules',
				'tster_install_rules' => 'zter_install_rules',
				'tster_zones'         => 'zter_zones',
				'tster_words'         => 'zter_words',
				'tster_settings'      => 'zter_settings',
				'tster_rr'            => 'zter_rr',
				'tster_generation'    => 'zter_generation',
				'tster_asks'          => 'zter_asks',
			)
		);
		return $map;
	}
);
