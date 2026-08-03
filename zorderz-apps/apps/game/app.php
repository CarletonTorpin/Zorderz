<?php
/**
 * Plugin Name: Zorderz Game
 * Description: Casual block-breaker for the Zorderz dashboard. Optional "extras" tile — no external calls, no cron, no business data. High scores persist per user.
 * Version:     1.5.4
 * Author:      Zorderz
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: zorderz
 * Requires PHP: 8.0
 *
 * ── What this is ──────────────────────────────────────────────────────
 * A self-contained block-breaker rendered on an HTML canvas, shipped as an
 * OPTIONAL extras tile in the Zorderz apps bundle. It makes no external API
 * calls, schedules no cron, and stores no business data. High scores live in
 * one small table (one row per user — their personal best) and surface as a
 * leaderboard. The engine is entirely in assets/js/game.js.
 *
 * ── Core-clean port (from the internal block-breaker) ─────────────────
 * Generalized for the public release:
 *   - Dropped the company-logo first level. The first game now shows a neutral
 *     block wall; a site MAY supply its own welcome pattern via the
 *     `zg_first_pattern` filter (an array of row strings). No company letters
 *     are baked in.
 *   - Dropped the initial-letter easter egg. Level 2 shows the signed-in user's
 *     own first initial when it maps to a glyph, otherwise it falls through to a
 *     random pattern — there is no hardcoded fallback letter.
 *   - Dropped the analytics/chat embed hook (the "play while you wait" coupling
 *     to another app) and its results overlay. The game is a standalone tile.
 *   - Renamed off the old ts/tsg identifiers to the short `zg` prefix. REST is
 *     registered under the theme's single ZDZ_REST_NS constant (never typed
 *     twice). The score table and DB-version option carry deprecated-alias
 *     rename-map entries so an existing install upgrades in place.
 *
 * ── Architecture ──────────────────────────────────────────────────────
 * PREFIX:  zg_
 * CLASSES: ZG_App (widget), ZG_Scores (persistence)
 * TABLE:   wp_zg_game_scores (user_id, score, level, pattern, created_at)
 * REST:    ZDZ_REST_NS/game-scores  (GET leaderboard, GET /me, POST submit)
 * NONCE:   wp_rest (X-WP-Nonce)
 * THEME:   registers via the `zdz_register_apps` filter on after_setup_theme
 *          (Zorderz\Widget_App_Interface). The theme publishes the
 *          --zdz-game-max-h scale-to-fit budget for data-app-id="game"; the
 *          engine reads it. Deps missing → the app declines to register rather
 *          than failing.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ZG_VERSION', '1.5.4' );
define( 'ZG_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZG_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ZG_DB_VERSION', '1.0.0' );

require_once ZG_PLUGIN_DIR . 'includes/class-zg-scores.php';


/* ═══════════════════════════════════════════════════════════════════════════
 * ACTIVATION — DB SCHEMA (schema only; ships EMPTY, never seeded)
 * ═══════════════════════════════════════════════════════════════════════════
 * Exposed to the bundle via the manifest ( 'activate' => 'zg_activate' ), and
 * self-healed on plugins_loaded below so the table exists even when the module
 * is added to an already-active bundle. dbDelta is idempotent — safe to re-run.
 */
function zg_activate() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table   = $wpdb->prefix . 'zg_game_scores';
	$charset = $wpdb->get_charset_collate();

	dbDelta( "CREATE TABLE {$table} (
		id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		user_id     BIGINT UNSIGNED NOT NULL,
		score       INT UNSIGNED    NOT NULL DEFAULT 0,
		level       INT UNSIGNED    NOT NULL DEFAULT 1,
		pattern     VARCHAR(32)     NOT NULL DEFAULT 'wall',
		created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		KEY idx_user_score (user_id, score DESC),
		KEY idx_leaderboard (score DESC, created_at ASC)
	) {$charset};" );

	update_option( 'zg_db_version', ZG_DB_VERSION );
}

/**
 * Self-heal: create/upgrade the table on load if it isn't at the current
 * version. Runs before after_setup_theme so the schema is ready for the first
 * score submission. Cheap: a single option read on the fast path.
 */
add_action(
	'plugins_loaded',
	function () {
		if ( get_option( 'zg_db_version' ) !== ZG_DB_VERSION ) {
			zg_activate();
		}
	},
	5
);

/**
 * Deprecated-alias rename map. The platform's Zdz_Rename_Migration renames the
 * old Total-Screen-era table and option to the `zg` names on activation so an
 * existing install upgrades cleanly. Plugins declare; the kernel migrates.
 */
add_filter(
	'zdz_rename_map',
	function ( $map ) {
		$map['tables']  = array_merge( $map['tables'] ?? array(), array( 'ts_game_scores' => 'zg_game_scores' ) );
		$map['options'] = array_merge( $map['options'] ?? array(), array( 'tsg_db_version' => 'zg_db_version' ) );
		return $map;
	}
);


/* ═══════════════════════════════════════════════════════════════════════════
 * REST API — ZDZ_REST_NS/game-scores
 * ═══════════════════════════════════════════════════════════════════════════
 * Every route lives under the theme's single ZDZ_REST_NS constant (= zorderz/v1)
 * so the namespace is never typed twice. If the theme (which defines the
 * constant) isn't present, the routes decline to register rather than fatal.
 */
add_action(
	'rest_api_init',
	function () {
		if ( ! defined( 'ZDZ_REST_NS' ) || ! class_exists( 'ZG_Scores' ) ) {
			return;
		}

		// /game-scores — GET leaderboard (top 10, one best per user) + POST a score.
		register_rest_route(
			ZDZ_REST_NS,
			'/game-scores',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => function ( \WP_REST_Request $req ) {
						return ZG_Scores::get_leaderboard( 10 );
					},
					'permission_callback' => function () {
						return current_user_can( 'read' );
					},
				),
				array(
					'methods'             => 'POST',
					'callback'            => 'zg_rest_submit_score',
					'permission_callback' => function () {
						return current_user_can( 'read' );
					},
				),
			)
		);

		// /game-scores/me — the current user's single best score.
		register_rest_route(
			ZDZ_REST_NS,
			'/game-scores/me',
			array(
				'methods'             => 'GET',
				'callback'            => function ( \WP_REST_Request $req ) {
					return ZG_Scores::get_user_scores( get_current_user_id() );
				},
				'permission_callback' => function () {
					return current_user_can( 'read' );
				},
			)
		);
	}
);

/**
 * POST /game-scores callback. Server-authoritative: the client proposes a
 * score; persistence only keeps it when it beats the stored personal best.
 */
function zg_rest_submit_score( \WP_REST_Request $req ) {
	$score   = absint( $req->get_param( 'score' ) );
	$level   = absint( $req->get_param( 'level' ) );
	$pattern = sanitize_text_field( (string) ( $req->get_param( 'pattern' ) ?: 'wall' ) );

	if ( $score < 1 ) {
		return new \WP_Error( 'invalid_score', 'Score must be positive.', array( 'status' => 400 ) );
	}

	$result = ZG_Scores::submit_score( get_current_user_id(), $score, $level, $pattern );

	return array(
		'success'  => true,
		'score_id' => $result['id'],
		'is_pb'    => $result['is_personal_best'],
		'rank'     => $result['rank'],
	);
}


/* ═══════════════════════════════════════════════════════════════════════════
 * THEME INTEGRATION — DASHBOARD APP TILE
 * ═══════════════════════════════════════════════════════════════════════════
 * The theme's interfaces aren't defined until after_setup_theme (WordPress loads
 * plugins before themes), so registration is deferred here. Tier-2 inline widget
 * only — the theme (2.x) always provides Widget_App_Interface.
 */
add_action( 'after_setup_theme', function () {

	if ( ! interface_exists( '\\Zorderz\\Widget_App_Interface' ) ) {
		return; // No compatible theme — decline rather than fail.
	}

	$app = new class implements \Zorderz\Widget_App_Interface {

		public function get_config(): array {
			return array(
				'id'          => 'game',
				'nm'          => 'Game',
				'name'        => 'Game',
				'icon'        => 'gamepad-2',
				'cat'         => 'Tools',
				'cc'          => '#8B5CF6',
				'desc'        => 'Casual block-breaker game.',
				'description' => 'Casual block-breaker game.',
				// Declarative intent. Live access is granted per user via
				// `zdz_allowed_apps` meta and resolved in ZDZ_Plugin_API; this is
				// the real Zorderz role set (verified against ZDZ_User_Roles), not
				// the gate. An optional extra — offered broadly, including the
				// shared kiosk (zdz_general).
				'roles'       => array( 'administrator', 'zdz_owner', 'zdz_admin', 'zdz_sales', 'zdz_operator', 'zdz_mfg', 'zdz_tech', 'zdz_general' ),
				'bridge_type' => 'inline_widget',
				'admin_url'   => '', // frontend-only; no WP Admin page.
			);
		}

		public function render_mobile_view( int $user_id ): void {
			$this->render_inline( $user_id );
		}

		public function render_dashboard_widget( int $user_id ): ?string {
			ob_start();
			$this->render_inline( $user_id );
			return ob_get_clean();
		}

		private function render_inline( int $user_id ): void {
			wp_enqueue_style( 'zg-game-css', ZG_PLUGIN_URL . 'assets/css/game.css', array(), ZG_VERSION );
			wp_enqueue_script( 'zg-game-js', ZG_PLUGIN_URL . 'assets/js/game.js', array(), ZG_VERSION, true );

			$user     = get_userdata( $user_id );
			$rest_url = defined( 'ZDZ_REST_NS' ) ? esc_url_raw( rest_url( ZDZ_REST_NS . '/game-scores' ) ) : '';

			wp_localize_script(
				'zg-game-js',
				'zgGameData',
				array(
					'restUrl'   => $rest_url,
					'restNonce' => wp_create_nonce( 'wp_rest' ),
					'userId'    => $user_id,
					// Used only to draw the player's own initial on level 2. Empty
					// → no initial level (falls through to a random pattern).
					'userName'  => $user ? $user->display_name : '',
					'version'   => ZG_VERSION,
					// Optional neutral-default welcome pattern override. A site can
					// return an array of row strings (8 cols each) from the
					// `zg_first_pattern` filter; null → the built-in neutral wall.
					'firstPattern' => apply_filters( 'zg_first_pattern', null ),
				)
			);
			?>
			<div class="zg-wrap" id="zg-game-root" data-mode="standalone">
				<div class="zg-tabs">
					<button class="zg-tab zg-tab--active" data-tab="game">Game</button>
					<button class="zg-tab" data-tab="scores">High scores</button>
				</div>
				<div class="zg-panel zg-panel--game" id="zg-panel-game">
					<div class="zg-hud" id="zg-hud">
						<span id="zg-hud-score">0</span>
						<span id="zg-hud-level">Lvl 1</span>
					</div>
					<div class="zg-canvas-wrap" id="zg-canvas-wrap">
						<canvas id="zg-canvas"></canvas>
						<div class="zg-pause-banner" id="zg-pause-banner">Paused</div>
					</div>
				</div>
				<div class="zg-panel zg-panel--scores" id="zg-panel-scores" style="display:none">
					<table class="zg-scores-table">
						<thead>
							<tr><th>#</th><th>Player</th><th>Score</th><th>Date</th></tr>
						</thead>
						<tbody id="zg-scores-body"></tbody>
					</table>
				</div>
			</div>
			<?php
		}
	};

	add_filter( 'zdz_register_apps', function ( $apps ) use ( $app ) {
		$apps['game'] = $app;
		return $apps;
	} );
} );
