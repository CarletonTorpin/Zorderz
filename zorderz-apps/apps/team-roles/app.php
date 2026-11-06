<?php
/**
 * Module: Zorderz - Team Roles
 * Description: What each person on the team can and can't do. A grid of people down the side and
 *   abilities across the top: each catalog item with Sell, Install and Repair, plus the site
 *   conditions, tasks and kinds of work the business adds. Each cell is Can, Learning, Can't or
 *   not marked, with an optional note and "since" date. Other apps ask one question, "can this
 *   person do this?", and get the answer with the reason. The admin tier edits; everyone sees
 *   their own row; a crew lead sees their crew. Every change is kept.
 * Version:     1.0.0
 * Author:      Zorderz
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: zorderz
 * Requires PHP: 8.0
 *
 * This is a bundled app module (loaded by zorderz-apps.php), not a standalone plugin. It
 * registers with the theme through the `zdz_register_apps` filter on after_setup_theme and
 * declines cleanly when the theme is absent.
 *
 * CORE-SERVICE BINDINGS
 *   - Items       : ZDZ_Item_Engine (the business's own catalog). Team Roles never ships an item.
 *   - Kinds of work: ZJOB_Project_Types (the Jobs app), configured by the business.
 *   - Tiers       : ZDZ_Capabilities::tier_of (the admin tier edits).
 *   - Crews       : ZDZ_Hierarchy::can_oversee (a crew lead reads their crew).
 *   - Colours     : ZDZ_Person_Color (initials and tone on each row).
 *   - Chat verb   : roles.who_can on `zdz_register_capabilities` (a read).
 *
 * DENY BY DEFAULT
 *   An unknown person, an unknown item or ability, an item asked without saying sell / install /
 *   repair, a retired ability, the shared device, and a cell nobody marked all answer
 *   can = false. The answer for a person is read from that person's own row only.
 *
 * Ships EMPTY: no abilities, no items, no marks. The business adds them on the page (or the
 * Identity Pack's org file names them); items come from the catalog.
 *
 * @package Zorderz\TeamRoles
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ZRO_VERSION', '1.0.0' );
define( 'ZRO_DB_VERSION', '1.0.0' );
define( 'ZRO_APP_ID', 'team-roles' );
define( 'ZRO_FILE', __FILE__ );
define( 'ZRO_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZRO_URL', plugin_dir_url( __FILE__ ) );

require_once ZRO_DIR . 'includes/class-zro-db.php';
require_once ZRO_DIR . 'includes/class-zro-abilities.php';
require_once ZRO_DIR . 'includes/class-zro-roles.php';
require_once ZRO_DIR . 'includes/class-zro-migrate.php';
require_once ZRO_DIR . 'includes/class-zro-rest.php';
require_once ZRO_DIR . 'includes/class-zro-bridge.php';
require_once ZRO_DIR . 'includes/functions-zro-people.php';
require_once ZRO_DIR . 'includes/class-zro-pack.php';

/** parties.yml in an Identity Pack: people's Team Roles marks. */
add_filter( 'zdz_identity_pack_consumers', array( 'ZRO_Pack', 'register' ) );

/**
 * Activation (called by the bundle activator through the manifest). Creates the two tables.
 * Nothing is seeded: the business adds its abilities on the page.
 */
function zro_activate() {
	ZRO_DB::create_tables();
	update_option( 'zro_db_version', ZRO_DB_VERSION, false );
}

/** Deactivation: nothing scheduled, nothing to tear down. */
function zro_deactivate() {}

/**
 * Everyone on staff holds Team Roles (each sees their own row), through the platform's ADD-only
 * per-person grant: it comes after Safe Mode and the per-person deny list, so an admin can still
 * take it away from one person in User Management.
 */
add_filter(
	'zdz_extra_allowed_apps',
	function ( $apps, $user_id = 0 ) {
		$apps = is_array( $apps ) ? $apps : array();
		if ( ! in_array( ZRO_APP_ID, $apps, true ) && zro_is_team_member( (int) $user_id ) ) {
			$apps[] = ZRO_APP_ID;
		}
		return $apps;
	},
	10,
	2
);

add_action(
	'plugins_loaded',
	function () {
		if ( get_option( 'zro_db_version' ) !== ZRO_DB_VERSION ) {
			zro_activate(); // an app replaced by upload is not re-activated
		}
		ZRO_Roles::init();
		ZRO_REST::init();
		ZRO_Bridge::init();
	},
	20
);

/** The grid lives in the app; wp-admin shows what is stored and how other apps ask. */
add_action(
	'admin_menu',
	function () {
		$parent = menu_page_url( 'zdz-core-settings', false ) ? 'zdz-core-settings' : 'options-general.php';
		add_submenu_page( $parent, __( 'Team Roles', 'zorderz' ), __( 'Team Roles', 'zorderz' ), 'manage_options', 'zro-settings', 'zro_admin_page' );
	},
	40
);

function zro_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$st = ZRO_DB::stats();
	$ab = ZRO_Abilities::all( true );
	$on = count( array_filter( $ab, function ( $a ) { return empty( $a['retired'] ); } ) );
	$mv = ZRO_Migrate::done();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Team Roles', 'zorderz' ); ?></h1>
		<p><?php esc_html_e( 'The grid is in the app. Open Team Roles, tap a box, then tap Can, Learning or Can\'t: it saves at once, with Undo in the message. Abilities are added, renamed and retired there too; items come from the catalog.', 'zorderz' ); ?></p>
		<p><a class="button button-primary" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Open the app', 'zorderz' ); ?></a></p>
		<p><strong><?php esc_html_e( 'Items:', 'zorderz' ); ?></strong>
			<?php
			echo esc_html( sprintf(
				/* translators: %d: number of catalog items */
				__( '%d catalog items, each with Sell, Install and Repair.', 'zorderz' ),
				count( ZRO_Abilities::items() )
			) );
			echo ' ';
			if ( $mv ) {
				echo esc_html( sprintf(
					/* translators: 1: date, 2: number of cells */
					__( 'Older work-kind marks were moved into items on %1$s (%2$d cells).', 'zorderz' ),
					substr( (string) $mv['at'], 0, 10 ),
					(int) $mv['cells']
				) );
			} elseif ( ZRO_Migrate::needed() ) {
				esc_html_e( 'Older work-kind marks are waiting to be moved into items: open Team Roles as an admin.', 'zorderz' );
			}
			?>
		</p>
		<p><strong><?php esc_html_e( 'Stored:', 'zorderz' ); ?></strong>
			<?php
			echo esc_html( sprintf(
				/* translators: 1: abilities in use, 2: retired, 3: answers, 4: people, 5: history rows */
				__( '%1$d abilities in use (%2$d retired), %3$d answers for %4$d people, %5$d changes in the history.', 'zorderz' ),
				$on,
				count( $ab ) - $on,
				(int) $st['answers'],
				(int) $st['people'],
				(int) $st['history']
			) );
			?>
		</p>
		<p><strong><?php esc_html_e( 'For other apps:', 'zorderz' ); ?></strong>
			<code>zdz_person_can( $user_id, 'item-id__install' )</code>,
			<code>apply_filters( 'zdz_people_who_can', null, $key, array( 'learning' => true ) )</code>,
			<code>apply_filters( 'zdz_abilities', null )</code>,
			<code>apply_filters( 'zdz_team_roles_in_use', null )</code>;
			<?php esc_html_e( 'chat verb', 'zorderz' ); ?> <code>roles.who_can</code>.
		</p>
	</div>
	<?php
}

/** The dashboard app (theme interface — after_setup_theme). */
add_action(
	'after_setup_theme',
	function () {
		if ( ! interface_exists( '\Zorderz\Widget_App_Interface' ) ) {
			return; // degrade gracefully on a missing or older theme
		}
		require_once ZRO_DIR . 'includes/class-zro-app.php';
		add_filter(
			'zdz_register_apps',
			function ( $apps ) {
				if ( class_exists( 'ZRO_App' ) ) {
					$apps[ ZRO_APP_ID ] = new ZRO_App();
				}
				return $apps;
			}
		);
	},
	20
);

/** Loads the app's script and style once, with what the page needs to know. */
function zro_enqueue_app( $user_id ) {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	$ver  = function ( $rel ) {
		$m = @filemtime( ZRO_DIR . $rel );
		return $m ? ZRO_VERSION . '.' . $m : ZRO_VERSION;
	};
	wp_enqueue_style( 'zro-app', ZRO_URL . 'assets/app.css', array(), $ver( 'assets/app.css' ) );
	$deps = ( wp_script_is( 'zdz-kit-js', 'registered' ) || wp_script_is( 'zdz-kit-js', 'enqueued' ) ) ? array( 'zdz-kit-js' ) : array();
	wp_enqueue_script( 'zro-app', ZRO_URL . 'assets/app.js', $deps, $ver( 'assets/app.js' ), true );
	wp_localize_script(
		'zro-app',
		'ZRO_CONFIG',
		array(
			'restPath' => ZRO_REST::ns() . ZRO_REST::BASE . '/',
			'version'  => ZRO_VERSION,
			'userId'   => (int) $user_id,
			'isAdmin'  => zro_user_is_admin( $user_id ) ? '1' : '',
			'today'    => current_time( 'Y-m-d' ),
		)
	);
}

/**
 * Carry an install's earlier Team Roles data across to this app's names. The platform migration
 * performs the renames; a fresh install has none of these and every entry is a no-op.
 */
add_filter(
	'zdz_rename_map',
	function ( $map ) {
		$map['tables']  = array_merge(
			$map['tables'] ?? array(),
			array(
				'tsro_answers' => 'zro_answers',
				'tsro_history' => 'zro_history',
			)
		);
		$map['options'] = array_merge(
			$map['options'] ?? array(),
			array(
				'tsro_abilities'  => 'zro_abilities',
				'tsro_migration'  => 'zro_migration',
				'tsro_db_version' => 'zro_db_version',
			)
		);
		$map['app_ids'] = array_merge( $map['app_ids'] ?? array(), array( 'roles' => ZRO_APP_ID ) );
		return $map;
	}
);
