<?php
/**
 * Admin Dashboard — User Management, Audit Log, and REST API
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Admin_Dashboard {

	private static $instance = null;

	/**
	 * Current audit-log schema version. Bump to trigger dbDelta. 1.2.0: the daily usage rollups (zdz_usage_daily), and
	 * three columns on the log -- device (phone / desktop_app / browser), session_id (one page load) and secs (how long
	 * an app stayed open, on app_close) -- with an index for the Usage view's window reads. Nothing is renamed or
	 * dropped: apps that read id, user_id, action_type, action_detail, app_id, meta_json and created_at still can.
	 */
	const DB_VERSION = '1.2.0';

	/**
	 * The usage events, pruned after raw_days() (each one first counted into zdz_usage_daily, one row per day, person,
	 * kind and app). Every other row -- logins, logouts, role changes, chat audit, money actions -- is the
	 * accountability record and is never pruned here. Filter (may only remove kinds): zdz_audit_usage_kinds.
	 */
	const USAGE_KINDS = array( 'app_open', 'view_switch', 'dashboard_load', 'heartbeat', 'widget_interact', 'search', 'command_palette', 'page_view', 'app_close', 'app_action' );

	/**
	 * The one heartbeat rhythm, in seconds. The page beats this often while it is in front (zdzData.track.beat); an
	 * hours app can read each beat as a few minutes of presence. The server keeps at most one beat per person in
	 * BEAT_GAP seconds, a little under the rhythm so a beat that arrives a few seconds early is not lost.
	 */
	const HEARTBEAT_S = 240;
	const BEAT_GAP    = 200;

	/** The device classes a usage row can carry. */
	const DEVICES = array( 'phone', 'desktop_app', 'browser' );

	/** The kinds the page itself may send to /track (page_view is the server's own). */
	const CLIENT_KINDS = array( 'app_open', 'app_close', 'app_action', 'view_switch', 'dashboard_load', 'heartbeat', 'widget_interact', 'search', 'command_palette' );

	/**
	 * What a search or a palette row may say. Never the typed words (they can hold a customer's name): the server keeps
	 * only which box was searched and which kind of result was opened.
	 */
	const SEARCH_DETAILS  = array( 'ask', 'apps' );
	const PALETTE_DETAILS = array( 'app', 'ask', 'action', 'answer', 'quick_answer' );

	/** Days a raw usage row is kept (filter zdz_audit_raw_days; never under RAW_DAYS_MIN). */
	const RAW_DAYS = 180;

	/** The fewest days a raw usage row is kept, whatever is configured: some readers look back 60 days. */
	const RAW_DAYS_MIN = 90;

	/** Days a raw usage row is kept on this site: RAW_DAYS or the filter's value, never under RAW_DAYS_MIN. */
	public static function raw_days() {
		$d = function_exists( 'apply_filters' ) ? (int) apply_filters( 'zdz_audit_raw_days', self::RAW_DAYS ) : self::RAW_DAYS;
		return max( self::RAW_DAYS_MIN, $d );
	}

	/** @var string Resolved table name (set in constructor). */
	private $table_name;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'zdz_audit_log';

		add_action( 'admin_menu', [ $this, 'add_admin_page' ] );
		add_action( 'admin_init', [ $this, 'maybe_create_table' ] );
		add_action( 'after_switch_theme', [ $this, 'create_table' ] );
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		// Raw usage events are pruned after raw_days(), keeping daily rollups. Constructed on 'init', so schedule now.
		add_action( 'zdz_audit_prune', [ __CLASS__, 'prune' ] );
		self::schedule_prune();

		// Login / logout tracking.
		add_action( 'wp_login', [ __CLASS__, 'track_login' ], 10, 2 );
		add_action( 'wp_logout', [ __CLASS__, 'track_logout' ] );

		// Track front-end page loads server-side (SPA shell load).
		add_action( 'template_redirect', [ __CLASS__, 'track_page_view' ] );
	}

	/* ──────────────────────────────────────────────
	 * Database table
	 * ────────────────────────────────────────────── */

	/**
	 * Create the audit-log table via dbDelta.
	 */
	public function create_table() {
		global $wpdb;

		$table   = $this->table_name;
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			action_type varchar(50) NOT NULL,
			action_detail text NOT NULL,
			app_id varchar(100) DEFAULT NULL,
			ip_address varchar(45) NOT NULL DEFAULT '',
			meta_json text DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			device varchar(12) NOT NULL DEFAULT '',
			session_id varchar(16) NOT NULL DEFAULT '',
			secs int(10) unsigned DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY action_type (action_type),
			KEY created_at (created_at),
			KEY kind_time (action_type,created_at)
		) {$charset};";

		// What pruning keeps of the usage events: a count per day, person, kind and app.
		$daily = $wpdb->prefix . 'zdz_usage_daily';
		$sql2  = "CREATE TABLE {$daily} (
			day date NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			action_type varchar(50) NOT NULL,
			app_id varchar(100) NOT NULL DEFAULT '',
			n int(10) unsigned NOT NULL DEFAULT 0,
			secs bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (day,user_id,action_type,app_id),
			KEY app_day (app_id,day)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		dbDelta( $sql2 );

		update_option( 'zdz_audit_log_version', self::DB_VERSION );
	}

	/**
	 * On admin_init, create/upgrade the table if the version option is stale.
	 */
	public function maybe_create_table() {
		if ( get_option( 'zdz_audit_log_version' ) !== self::DB_VERSION ) {
			$this->create_table();
		}
	}

	/* ──────────────────────────────────────────────
	 * Admin page
	 * ────────────────────────────────────────────── */

	public function add_admin_page() {
		add_submenu_page(
			'zdz-core-settings',
			__( 'User Management', 'zorderz' ),
			__( 'User Management', 'zorderz' ),
			'manage_options',
			'zdz-user-management',
			[ $this, 'render_page' ]
		);
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div id="zdz-admin-dashboard"></div>';
	}

	/* ──────────────────────────────────────────────
	 * Asset enqueuing
	 * ────────────────────────────────────────────── */

	/**
	 * Enqueue JS/CSS only on our admin page.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( 'zorderz_page_zdz-user-management' !== $hook_suffix ) {
			return;
		}

		$version = wp_get_theme()->get( 'Version' );
		if ( ! is_string( $version ) || '' === $version ) {
			$version = defined( 'ZDZ_THEME_VER_FLOOR' ) ? ZDZ_THEME_VER_FLOOR : '1'; // never an empty ?ver=
		}

		wp_enqueue_style(
			'zdz-admin-dashboard-css',
			get_template_directory_uri() . '/assets/css/admin-dashboard.css',
			[],
			$version
		);

		// The page draws its icons with Lucide (Edit, Activity, the stat tiles, the Usage tab).
		wp_enqueue_script( 'zdz-lucide', get_template_directory_uri() . '/assets/js/vendor/lucide.min.js', [], $version, true );

		wp_enqueue_script(
			'zdz-admin-dashboard-js',
			get_template_directory_uri() . '/assets/js/admin-dashboard.js',
			[ 'zdz-lucide', 'zdz-kit-js' ],   // the person badges are the kit's (functions.php loads it on this page)
			$version,
			true
		);

		$roles = [
			'administrator' => 'WP Administrator',
			'zdz_owner'      => 'Owner',
			'zdz_admin'      => 'Administrator',
			'zdz_sales'      => 'Salesperson',
			'zdz_operator'   => 'Operator',
			'zdz_mfg'        => 'Shop Foreman',
			'zdz_tech'       => 'Field Tech',
			'zdz_general'    => 'General (Shared Kiosk)',
			'subscriber'    => 'Subscriber',
		];

		$apps = $this->get_apps_list();

		wp_localize_script( 'zdz-admin-dashboard-js', 'zdzAdminData', [
			'restUrl' => esc_url_raw( rest_url( ZDZ_REST_NS . '/' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'roles'   => $roles,
			'apps'    => $apps,
		] );
	}

	/* ──────────────────────────────────────────────
	 * REST API routes
	 * ────────────────────────────────────────────── */

	public function register_routes() {

		$admin_check = function () {
			return current_user_can( 'manage_options' );
		};

		// GET /admin/users
		register_rest_route( 'zorderz/v1', '/admin/users', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'rest_list_users' ],
			'permission_callback' => $admin_check,
			'args'                => [
				'search' => [
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				],
				'role' => [
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		] );

		// GET /admin/users/(?P<id>\d+)
		register_rest_route( 'zorderz/v1', '/admin/users/(?P<id>\d+)', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'rest_get_user' ],
			'permission_callback' => $admin_check,
			'args'                => [
				'id' => [
					'type'              => 'integer',
					'required'          => true,
					'sanitize_callback' => 'absint',
				],
			],
		] );

		// POST /admin/users/(?P<id>\d+)
		register_rest_route( 'zorderz/v1', '/admin/users/(?P<id>\d+)', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'rest_update_user' ],
			'permission_callback' => $admin_check,
			'args'                => [
				'id' => [
					'type'              => 'integer',
					'required'          => true,
					'sanitize_callback' => 'absint',
				],
			],
		] );

		// POST /admin/users/(?P<id>\d+)/permissions
		register_rest_route( 'zorderz/v1', '/admin/users/(?P<id>\d+)/permissions', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'rest_update_permissions' ],
			'permission_callback' => $admin_check,
			'args'                => [
				'id' => [
					'type'              => 'integer',
					'required'          => true,
					'sanitize_callback' => 'absint',
				],
			],
		] );

		// GET /admin/audit-log
		register_rest_route( 'zorderz/v1', '/admin/audit-log', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'rest_audit_log' ],
			'permission_callback' => $admin_check,
			'args'                => [
				'user_id' => [
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				],
				'action_type' => [
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				],
				'search' => [
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				],
				'date_from' => [
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				],
				'date_to' => [
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				],
				'page' => [
					'type'              => 'integer',
					'default'           => 1,
					'sanitize_callback' => 'absint',
				],
				'per_page' => [
					'type'              => 'integer',
					'default'           => 50,
					'sanitize_callback' => 'absint',
				],
			],
		] );

		// GET /admin/audit-log/actions
		register_rest_route( 'zorderz/v1', '/admin/audit-log/actions', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'rest_audit_log_actions' ],
			'permission_callback' => $admin_check,
		] );

		// POST /track — front-end activity tracking (any logged-in user)
		register_rest_route( 'zorderz/v1', '/track', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'rest_track' ],
			'permission_callback' => function () {
				return is_user_logged_in();
			},
		] );

		// GET /admin/apps
		register_rest_route( 'zorderz/v1', '/admin/apps', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'rest_list_apps' ],
			'permission_callback' => $admin_check,
		] );
	}

	/* ──────────────────────────────────────────────
	 * REST callbacks
	 * ────────────────────────────────────────────── */

	/**
	 * Format a WP_User into the standard user-data array.
	 *
	 * @param WP_User $user WordPress user object.
	 * @return array
	 */
	private function format_user( WP_User $user ): array {
		$roles = (array) $user->roles;

		$allowed_apps = get_user_meta( $user->ID, 'zdz_allowed_apps', true );
		$denied_apps  = get_user_meta( $user->ID, 'zdz_denied_apps', true );
		$safe_mode    = get_user_meta( $user->ID, 'zdz_safe_mode', true );

		if ( ! is_array( $allowed_apps ) ) { $allowed_apps = []; }
		if ( ! is_array( $denied_apps ) )  { $denied_apps  = []; }

		// Build the app_permissions map that the admin-dashboard JS
		// expects. Converts the two flat arrays into { app_id: 'allow'|'deny' }.
		// Apps not in either list default to 'default' on the JS side.
		$perms = [];
		foreach ( $allowed_apps as $app_id ) {
			$perms[ $app_id ] = 'allow';
		}
		foreach ( $denied_apps as $app_id ) {
			$perms[ $app_id ] = 'deny';
		}

		$pc = class_exists( 'ZDZ_Person_Color' ) ? ZDZ_Person_Color::for_user( (int) $user->ID ) : [];
		$pc += [ 'color' => '', 'chosen' => false, 'ink' => '', 'edge' => '' ];

		return [
			'id'              => $user->ID,
			'email'           => $user->user_email,
			'display_name'    => $user->display_name,
			'role'            => ! empty( $roles ) ? $roles[0] : '',
			'initials'        => (string) get_user_meta( $user->ID, 'zdz_user_initials', true ),
			'notes'           => (string) get_user_meta( $user->ID, 'zdz_user_notes', true ),
			'allowed_apps'    => $allowed_apps,
			'denied_apps'     => $denied_apps,
			'app_permissions' => (object) $perms,
			'safe_mode'       => (bool) $safe_mode,
			'last_login'      => (string) get_user_meta( $user->ID, 'zdz_last_login', true ),
			// The person's badge: their tone (and whether an administrator chose it), its ink and outline, the tones
			// to choose from, and the shape their highest role gives (more sides, more privileges).
			'color'           => $pc['color'],
			'color_chosen'    => (bool) $pc['chosen'],
			'color_ink'       => $pc['ink'],
			'color_edge'      => $pc['edge'],
			'color_palette'   => class_exists( 'ZDZ_Person_Color' ) ? ZDZ_Person_Color::palette() : [],
			'color_tones'     => class_exists( 'ZDZ_Person_Color' ) ? ZDZ_Person_Color::tones_for_js() : [],
			'shape'           => class_exists( 'ZDZ_Person_Color' ) ? ZDZ_Person_Color::shape_for_roles( (array) $roles ) : '',
		];
	}

	/**
	 * GET /admin/users
	 */
	public function rest_list_users( WP_REST_Request $request ) {
		$args = [
			'orderby' => 'display_name',
			'order'   => 'ASC',
		];

		$search = $request->get_param( 'search' );
		if ( ! empty( $search ) ) {
			$args['search']         = '*' . $search . '*';
			$args['search_columns'] = [ 'user_login', 'user_email', 'display_name' ];
		}

		$role = $request->get_param( 'role' );
		if ( ! empty( $role ) ) {
			$args['role'] = $role;
		}

		$user_query = new WP_User_Query( $args );
		$users      = [];

		foreach ( $user_query->get_results() as $user ) {
			$users[] = $this->format_user( $user );
		}

		return rest_ensure_response( [ 'success' => true, 'data' => $users ] );
	}

	/**
	 * GET /admin/users/{id}
	 */
	public function rest_get_user( WP_REST_Request $request ) {
		$user_id = (int) $request->get_param( 'id' );
		$user    = get_userdata( $user_id );

		if ( ! $user ) {
			return new WP_Error( 'not_found', __( 'User not found.', 'zorderz' ), [ 'status' => 404 ] );
		}

		return rest_ensure_response( [ 'success' => true, 'data' => $this->format_user( $user ) ] );
	}

	/**
	 * POST /admin/users/{id}
	 */
	public function rest_update_user( WP_REST_Request $request ) {
		$user_id = (int) $request->get_param( 'id' );
		$user    = get_userdata( $user_id );

		if ( ! $user ) {
			return new WP_Error( 'not_found', __( 'User not found.', 'zorderz' ), [ 'status' => 404 ] );
		}

		$body = $request->get_json_params();

		// Role change.
		if ( isset( $body['role'] ) ) {
			$new_role = sanitize_text_field( $body['role'] );
			$old_role = ! empty( $user->roles ) ? $user->roles[0] : '';

			if ( $new_role !== $old_role ) {
				$user->set_role( $new_role );

				self::log_action(
					$user_id,
					'role_change',
					sprintf( 'Role changed from %s to %s', $old_role, $new_role ),
					'',
					[ 'old_role' => $old_role, 'new_role' => $new_role ]
				);
			}
		}

		// Display name.
		if ( isset( $body['display_name'] ) ) {
			wp_update_user( [
				'ID'           => $user_id,
				'display_name' => sanitize_text_field( $body['display_name'] ),
			] );
		}

		// Initials.
		if ( isset( $body['initials'] ) ) {
			update_user_meta( $user_id, 'zdz_user_initials', sanitize_text_field( $body['initials'] ) );
			if ( class_exists( 'ZDZ_Person_Color' ) ) {
				ZDZ_Person_Color::flush();
			}
		}

		// The person's badge tone: one of the tones, or '' for automatic (the next free one).
		if ( isset( $body['color'] ) && class_exists( 'ZDZ_Person_Color' ) ) {
			$c = strtoupper( sanitize_text_field( (string) $body['color'] ) );
			if ( '' === $c || ZDZ_Person_Color::is_palette( $c ) ) {
				ZDZ_Person_Color::set( (int) $user_id, $c );
			}
		}

		// Notes.
		if ( isset( $body['notes'] ) ) {
			update_user_meta( $user_id, 'zdz_user_notes', sanitize_textarea_field( $body['notes'] ) );
		}

		// Allowed apps.
		if ( isset( $body['allowed_apps'] ) && is_array( $body['allowed_apps'] ) ) {
			$allowed = array_map( 'sanitize_text_field', $body['allowed_apps'] );
			update_user_meta( $user_id, 'zdz_allowed_apps', $allowed );
		}

		// Denied apps.
		if ( isset( $body['denied_apps'] ) && is_array( $body['denied_apps'] ) ) {
			$denied = array_map( 'sanitize_text_field', $body['denied_apps'] );
			update_user_meta( $user_id, 'zdz_denied_apps', $denied );
		}

		// Safe mode.
		if ( isset( $body['safe_mode'] ) ) {
			update_user_meta( $user_id, 'zdz_safe_mode', (bool) $body['safe_mode'] ? '1' : '' );
		}

		// Return refreshed user data.
		$updated_user = get_userdata( $user_id );

		return rest_ensure_response( [ 'success' => true, 'data' => $this->format_user( $updated_user ) ] );
	}

	/**
	 * POST /admin/users/{id}/permissions
	 */
	public function rest_update_permissions( WP_REST_Request $request ) {
		$user_id = (int) $request->get_param( 'id' );
		$user    = get_userdata( $user_id );

		if ( ! $user ) {
			return new WP_Error( 'not_found', __( 'User not found.', 'zorderz' ), [ 'status' => 404 ] );
		}

		$body = $request->get_json_params();

		$changes = [];

		// Accept the permissions map format sent by admin-dashboard.js.
		// The JS sends { permissions: { 'app-id': 'allow'|'default'|'deny', ... } }.
		// Convert to the flat allowed_apps / denied_apps arrays used by the RBAC system.
		if ( isset( $body['permissions'] ) && is_array( $body['permissions'] ) ) {
			$allowed = [];
			$denied  = [];
			foreach ( $body['permissions'] as $app_id => $perm ) {
				$app_id = sanitize_text_field( $app_id );
				$perm   = sanitize_text_field( $perm );
				if ( 'allow' === $perm ) {
					$allowed[] = $app_id;
				} elseif ( 'deny' === $perm ) {
					$denied[] = $app_id;
				}
				// 'default' — not in either list, handled by RBAC defaults
			}
			update_user_meta( $user_id, 'zdz_allowed_apps', $allowed );
			update_user_meta( $user_id, 'zdz_denied_apps', $denied );
			$changes['allowed_apps'] = $allowed;
			$changes['denied_apps']  = $denied;
		}

		// Legacy: also accept flat arrays directly (from class-zdz-admin-ui.php form or API calls).
		if ( isset( $body['allowed_apps'] ) && is_array( $body['allowed_apps'] ) ) {
			$allowed = array_map( 'sanitize_text_field', $body['allowed_apps'] );
			update_user_meta( $user_id, 'zdz_allowed_apps', $allowed );
			$changes['allowed_apps'] = $allowed;
		}

		if ( isset( $body['denied_apps'] ) && is_array( $body['denied_apps'] ) ) {
			$denied = array_map( 'sanitize_text_field', $body['denied_apps'] );
			update_user_meta( $user_id, 'zdz_denied_apps', $denied );
			$changes['denied_apps'] = $denied;
		}

		if ( ! empty( $changes ) ) {
			self::log_action(
				$user_id,
				'permission_change',
				'User permissions updated',
				'',
				$changes
			);
		}

		$updated_user = get_userdata( $user_id );

		return rest_ensure_response( [ 'success' => true, 'data' => $this->format_user( $updated_user ) ] );
	}

	/**
	 * GET /admin/audit-log
	 */
	public function rest_audit_log( WP_REST_Request $request ) {
		global $wpdb;

		$table    = $this->table_name;
		$where    = [];
		$values   = [];
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 50 ) );
		$offset   = ( $page - 1 ) * $per_page;

		$user_id = $request->get_param( 'user_id' );
		if ( ! empty( $user_id ) ) {
			$where[]  = 'user_id = %d';
			$values[] = (int) $user_id;
		}

		$action_type = $request->get_param( 'action_type' );
		if ( ! empty( $action_type ) ) {
			$where[]  = 'action_type = %s';
			$values[] = $action_type;
		}

		$search = $request->get_param( 'search' );
		if ( ! empty( $search ) ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = 'action_detail LIKE %s';
			$values[] = $like;
		}

		$date_from = $request->get_param( 'date_from' );
		if ( ! empty( $date_from ) ) {
			$where[]  = 'created_at >= %s';
			$values[] = $date_from;
		}

		$date_to = $request->get_param( 'date_to' );
		if ( ! empty( $date_to ) ) {
			$where[]  = 'created_at <= %s';
			$values[] = $date_to;
		}

		$where_sql = '';
		if ( ! empty( $where ) ) {
			$where_sql = 'WHERE ' . implode( ' AND ', $where );
		}

		// Total count.
		if ( ! empty( $values ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where_sql}", $values ) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where_sql}" );
		}

		// Items.
		$limit_sql = $wpdb->prepare( 'ORDER BY created_at DESC LIMIT %d OFFSET %d', $per_page, $offset );

		if ( ! empty( $values ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} {$where_sql} {$limit_sql}", $values ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$items = $wpdb->get_results( "SELECT * FROM {$table} {$where_sql} {$limit_sql}", ARRAY_A );
		}

		if ( ! is_array( $items ) ) {
			$items = [];
		}

		$pages = (int) ceil( $total / $per_page );

		return rest_ensure_response( [
			'success' => true,
			'data'    => [
				'items' => $items,
				'total' => $total,
				'pages' => $pages,
			],
		] );
	}

	/**
	 * GET /admin/audit-log/actions
	 */
	public function rest_audit_log_actions( WP_REST_Request $request ) {
		global $wpdb;

		$table   = $this->table_name;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$actions = $wpdb->get_col( "SELECT DISTINCT action_type FROM {$table} ORDER BY action_type ASC" );

		if ( ! is_array( $actions ) ) {
			$actions = [];
		}

		return rest_ensure_response( [ 'success' => true, 'data' => $actions ] );
	}

	/**
	 * GET /admin/apps
	 */
	public function rest_list_apps( WP_REST_Request $request ) {
		return rest_ensure_response( [ 'success' => true, 'data' => $this->get_apps_list() ] );
	}

	/* ──────────────────────────────────────────────
	 * Helpers
	 * ────────────────────────────────────────────── */

	/**
	 * Build the flat apps list from ZDZ_Plugin_API.
	 *
	 * @return array
	 */
	private function get_apps_list(): array {
		if ( ! class_exists( 'ZDZ_Plugin_API' ) ) {
			return [];
		}

		$all  = ZDZ_Plugin_API::get_instance()->get_all_apps();
		$list = [];

		foreach ( $all as $app_id => $app ) {
			$config = $app->get_config();
			// Handle both compact keys (nm, cat, cc) and full keys (name, category, color)
			$list[] = [
				'id'       => $app_id,
				'name'     => $config['nm'] ?? $config['name'] ?? $app_id,
				'icon'     => $config['icon'] ?? '',
				'category' => $config['cat'] ?? $config['category'] ?? '',
				'color'    => $config['cc'] ?? $config['color'] ?? '',
			];
		}

		return $list;
	}

	/* ──────────────────────────────────────────────
	 * Static: Audit logging
	 * ────────────────────────────────────────────── */

	/**
	 * The address an accountability row records: the connection's (ZDZ_Client_IP::get()), never a header the client
	 * sent. '' when there is none.
	 */
	public static function client_ip(): string {
		if ( class_exists( 'ZDZ_Client_IP' ) ) {
			return ZDZ_Client_IP::get();
		}
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) $_SERVER['REMOTE_ADDR'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- validated by FILTER_VALIDATE_IP on the next line; anything else becomes ''
		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Insert a single row into the audit log table.
	 *
	 * A usage row (USAGE_KINDS) is written only when ZDZ_Consent::may_record() allows it for that person, and never keeps
	 * the IP address. Every other row (sign-ins, Demo Mode, chat audit, approvals, cost rules) is an accountability
	 * record: always written, with the connection's IP (client_ip()); a forwarded-for list the client sent goes into
	 * meta as "forwarded_for", never into the IP column. Returns true when a row was written.
	 *
	 * @param int    $user_id     WordPress user ID.
	 * @param string $action_type Short action identifier (e.g. "login", "role_change").
	 * @param string $detail      Human-readable detail string.
	 * @param string $app_id      Optional related app ID.
	 * @param array  $meta        Optional associative array stored as JSON.
	 * @param array  $usage       usage rows only: device, session_id, secs, age (seconds since it happened).
	 */
	public static function log_action( $user_id, $action_type, $detail = '', $app_id = '', $meta = [], $usage = [] ) {
		global $wpdb;

		$table    = $wpdb->prefix . 'zdz_audit_log';
		$is_usage = in_array( (string) $action_type, self::USAGE_KINDS, true );
		if ( $is_usage && ! self::may_record( $user_id ) ) {
			return false;
		}
		if ( ! is_array( $meta ) ) {
			$meta = [];
		}

		// Auto-enrich meta with user initials.
		$initials = (string) get_user_meta( $user_id, 'zdz_user_initials', true );
		if ( '' !== $initials ) {
			$meta['user_initials'] = $initials;
		}

		// The client address: accountability rows only (never on a usage row). It is the connection's address
		// (ZDZ_Client_IP: REMOTE_ADDR, or a trusted proxy's value on zdz_client_ip), which the person cannot type. A
		// forwarded-for list is the client's own claim: it is kept beside it, labelled, and never as the address.
		$ip = '';
		if ( ! $is_usage ) {
			$ip  = self::client_ip();
			$fwd = class_exists( 'ZDZ_Client_IP' ) ? ZDZ_Client_IP::forwarded_chain() : [];
			if ( $fwd && [ $ip ] !== $fwd ) {
				$meta['forwarded_for'] = implode( ', ', $fwd );
			}
		}

		$row = [
			'user_id'       => absint( $user_id ),
			'action_type'   => sanitize_text_field( $action_type ),
			'action_detail' => sanitize_textarea_field( $detail ),
			'app_id'        => '' !== (string) $app_id ? sanitize_text_field( $app_id ) : null,
			'ip_address'    => sanitize_text_field( $ip ),
			'meta_json'     => ! empty( $meta ) ? wp_json_encode( $meta ) : null,
		];
		$fmt = [ '%d', '%s', '%s', '%s', '%s', '%s' ];
		if ( ! $is_usage ) {
			return false !== $wpdb->insert( $table, $row, $fmt );
		}

		// A usage row: the device class (from the page, else read from the user agent), the page load, how long an app
		// stayed open, and when it happened if that was a while ago (a batch resent on the next visit).
		static $migrated = false;
		if ( ! $migrated && get_option( 'zdz_audit_log_version' ) !== self::DB_VERSION ) {
			$migrated = true;                        // once a request: the columns arrive before the first row that needs them
			self::get_instance()->create_table();    // (normally done on the first wp-admin page after the upload)
		}
		$u               = is_array( $usage ) ? $usage : [];
		$device          = isset( $u['device'] ) && in_array( $u['device'], self::DEVICES, true ) ? $u['device'] : self::device_from_agent();
		$row['device']   = $device;
		$fmt[]           = '%s';
		$sid             = isset( $u['session_id'] ) && preg_match( '/^[a-z0-9]{6,16}$/', (string) $u['session_id'] ) ? (string) $u['session_id'] : '';
		$row['session_id'] = $sid;
		$fmt[]           = '%s';
		if ( isset( $u['secs'] ) && is_numeric( $u['secs'] ) ) {
			$row['secs'] = max( 0, min( 8 * 3600, (int) $u['secs'] ) );
			$fmt[]       = '%d';
		}
		$age = isset( $u['age'] ) && is_numeric( $u['age'] ) ? (int) $u['age'] : 0;
		if ( $age > 60 ) {
			// created_at is the database's own clock; FROM_UNIXTIME is read in the same session zone that NOW() and
			// UNIX_TIMESTAMP() use, so every reader (the Usage view, apps reading rows_after()) sees the moment it happened.
			$cols = array_keys( $row );
			$sql  = "INSERT INTO {$table} (" . implode( ',', $cols ) . ',created_at) VALUES (' . implode( ',', $fmt ) . ',FROM_UNIXTIME(%d))';
			return false !== $wpdb->query( $wpdb->prepare( $sql, array_merge( array_values( $row ), [ time() - $age ] ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		return false !== $wpdb->insert( $table, $row, $fmt );
	}

	/** May a usage row be kept about this person? ZDZ_Consent decides (has() once its card is on). */
	public static function may_record( $user_id ) {
		if ( (int) $user_id <= 0 ) {
			return false;
		}
		if ( class_exists( 'ZDZ_Consent' ) && method_exists( 'ZDZ_Consent', 'may_record' ) ) {
			return (bool) ZDZ_Consent::may_record( 'usage_records', (int) $user_id );
		}
		return true;   // without the consent service: as before
	}

	/** Phone, or browser, read from the user agent when the page did not say (the server's own page_view). */
	public static function device_from_agent( $ua = null ) {
		$ua = null === $ua ? ( isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '' ) : (string) $ua; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- matched, never stored
		if ( '' === $ua ) {
			return '';
		}
		return preg_match( '/iPhone|iPad|iPod|Android|Mobile/i', $ua ) ? 'phone' : 'browser';
	}

	/**
	 * The actions each app records, from the sibling of zdz_register_capabilities:
	 *   add_filter( 'zdz_register_usage_actions', function ( $a ) {
	 *       $a['estimate-creator'] = array( 'estimate_sent' => 'Sent an estimate', 'estimate_approved' => 'Approved an estimate' );
	 *       return $a;
	 *   } );
	 * Keys are lower-case words with _ (at most 40 characters); labels are plain words for the Usage view. /track
	 * keeps an app_action only when its key is registered for that app.
	 *
	 * @return array<string, array<string,string>> app id => [ action key => label ]
	 */
	public static function usage_actions() {
		$raw = apply_filters( 'zdz_register_usage_actions', [] );
		$out = [];
		foreach ( (array) $raw as $app => $acts ) {
			if ( ! is_string( $app ) || ! preg_match( '/^[a-z0-9_\-]{1,64}$/', $app ) || ! is_array( $acts ) ) {
				continue;
			}
			foreach ( $acts as $k => $label ) {
				if ( is_string( $k ) && preg_match( '/^[a-z][a-z0-9_]{0,39}$/', $k ) ) {
					$out[ $app ][ $k ] = is_string( $label ) && '' !== trim( $label ) ? trim( wp_strip_all_tags( $label ) ) : $k;
				}
			}
		}
		return $out;
	}

	/* ──────────────────────────────────────────────
	 * A public reader, so other apps never read the table themselves
	 * ────────────────────────────────────────────── */

	/** The columns a reader gets: the log's own, plus device, session and time open. Never the IP. */
	const READ_COLS = 'id, user_id, action_type, action_detail, app_id, meta_json, created_at, UNIX_TIMESTAMP(created_at) AS ts, device, session_id, secs';

	/**
	 * Rows after id $after_id, of these kinds, oldest first: an id cursor, as an hours app's collector reads. A
	 * background or admin read with no person to stamp, so a PHP lookup, not a registry verb.
	 *
	 *   ZDZ_Admin_Dashboard::rows_after( $cursor, array( 'login', 'heartbeat', 'app_open' ), 5000 );
	 *
	 * @param int      $after_id  0 for the start of the log.
	 * @param string[] $kinds     action types; empty = every usage kind.
	 * @param int      $limit     1..10000.
	 * @param int      $since_ts  optional: only rows at or after this moment (a first pass that should not reach back far).
	 * @return array[] each: id, user_id, action_type, action_detail, app_id, meta_json, created_at, ts, device, session_id, secs
	 */
	public static function rows_after( $after_id, array $kinds = [], $limit = 1000, $since_ts = 0 ) {
		global $wpdb;
		$t     = $wpdb->prefix . 'zdz_audit_log';
		$kinds = self::clean_kinds( $kinds );
		$limit = max( 1, min( 10000, (int) $limit ) );
		$in    = implode( ',', array_fill( 0, count( $kinds ), '%s' ) );
		$args  = array_merge( [ (int) $after_id ], $kinds );
		$when  = '';
		if ( (int) $since_ts > 0 ) {
			$when   = ' AND created_at >= FROM_UNIXTIME(%d)';
			$args[] = (int) $since_ts;
		}
		$args[] = $limit;
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT " . self::READ_COLS . " FROM {$t} WHERE id > %d AND action_type IN ($in){$when} ORDER BY id ASC LIMIT %d", $args ), ARRAY_A ); // phpcs:ignore
		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Rows for one app, newest first: what a messaging app's CSV export reads. $args: kinds (default: every kind, the app's
	 * accountability rows included), since_ts, limit (1..10000, default 10000).
	 */
	public static function rows_for_app( $app_id, array $args = [] ) {
		global $wpdb;
		$t   = $wpdb->prefix . 'zdz_audit_log';
		$app = sanitize_text_field( (string) $app_id );
		if ( '' === $app ) {
			return [];
		}
		$where = 'app_id = %s';
		$vals  = [ $app ];
		if ( ! empty( $args['kinds'] ) ) {
			$k     = array_values( array_filter( array_map( 'sanitize_key', (array) $args['kinds'] ) ) );
			if ( $k ) {
				$where .= ' AND action_type IN (' . implode( ',', array_fill( 0, count( $k ), '%s' ) ) . ')';
				$vals   = array_merge( $vals, $k );
			}
		}
		if ( ! empty( $args['since_ts'] ) ) {
			$where .= ' AND created_at >= FROM_UNIXTIME(%d)';
			$vals[] = (int) $args['since_ts'];
		}
		$vals[] = max( 1, min( 10000, (int) ( $args['limit'] ?? 10000 ) ) );
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT " . self::READ_COLS . " FROM {$t} WHERE {$where} ORDER BY id DESC LIMIT %d", $vals ), ARRAY_A ); // phpcs:ignore
		return is_array( $rows ) ? $rows : [];
	}

	/** Kinds a reader may ask for: the usage kinds plus sign-in and sign-out; empty = every usage kind. */
	private static function clean_kinds( array $kinds ) {
		$ok  = array_merge( self::USAGE_KINDS, [ 'login', 'logout' ] );
		$out = [];
		foreach ( $kinds as $k ) {
			$k = sanitize_key( (string) $k );
			if ( in_array( $k, $ok, true ) ) {
				$out[ $k ] = $k;
			}
		}
		return $out ? array_values( $out ) : self::USAGE_KINDS;
	}

	/**
	 * Track a user login event.
	 *
	 * Hooked to `wp_login`.
	 *
	 * @param string  $user_login Username.
	 * @param WP_User $user       User object.
	 */
	public static function track_login( $user_login, $user ) {
		update_user_meta( $user->ID, 'zdz_last_login', current_time( 'mysql' ) );
		self::log_action( $user->ID, 'login', sprintf( 'User %s logged in', $user_login ) );
	}

	/**
	 * Track a user logout event.
	 *
	 * Hooked to `wp_logout`.
	 */
	public static function track_logout() {
		$user_id = get_current_user_id();
		if ( $user_id ) {
			self::log_action( $user_id, 'logout', 'User logged out' );
		}
	}

	/**
	 * Track a front-end page view (SPA shell load).
	 * Fires on template_redirect — only for logged-in users on the front page.
	 * Throttled: max 1 page_view per user per 10 minutes to avoid flooding.
	 *
	 * @since 2.17.1
	 */
	public static function track_page_view() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}
		// Throttle: 1 page_view per 10 minutes per user.
		$transient_key = 'zdz_pv_' . $user_id;
		if ( get_transient( $transient_key ) ) {
			return;
		}
		set_transient( $transient_key, 1, 10 * MINUTE_IN_SECONDS );

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- page_detail() keeps the path only
		self::log_action( $user_id, 'page_view', self::page_detail( $uri ) );
	}

	/** The path only, never a query string (a search box can put a name there). */
	public static function page_detail( $uri ) {
		$path = (string) strtok( (string) $uri, '?#' );
		$path = preg_replace( '/[^A-Za-z0-9_\-\/.]/', '', $path );
		return 'SPA shell loaded: ' . substr( '' === $path ? '/' : $path, 0, 120 );
	}

	/**
	 * POST /zorderz/v1/track — Accept batched activity events from the front-end.
	 *
	 * Body: { "bid": "<batch id>", "events": [ { action_type, detail, app_id, device, sid, secs, act, ref, age }, ... ] }
	 *
	 * Rules:
	 *   - kept only when ZDZ_Consent::may_record() allows it for this person (an answer of 200 with logged 0 otherwise);
	 *   - one event per person, kind AND app (and action) in 10 seconds, so two different apps opened in a row both
	 *     count; a heartbeat at most every BEAT_GAP seconds (the page beats every HEARTBEAT_S);
	 *   - a batch id seen in the last two days is answered "duplicate" and not written again (the page resends a batch
	 *     whose send failed, once, on the next visit; it may have arrived after all);
	 *   - age: seconds since the event happened, measured on the device (no clock skew); a resent event older than
	 *     two days is dropped; a resent event is not throttled (its batch id already guards it);
	 *   - search and command_palette keep only which box and which kind of result (SEARCH_DETAILS, PALETTE_DETAILS),
	 *     never the typed words; app_action keeps only a key registered on zdz_register_usage_actions for that app, and
	 *     ref only as digits (a project number), never free text.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function rest_track( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return new WP_REST_Response( [ 'success' => false ], 401 );
		}

		$body   = $request->get_json_params();
		$events = isset( $body['events'] ) && is_array( $body['events'] ) ? $body['events'] : [];

		if ( empty( $events ) ) {
			return rest_ensure_response( [ 'success' => true, 'logged' => 0 ] );
		}
		if ( ! self::may_record( $user_id ) ) {
			// Not an error: this person said no (or has not said yes since the card was switched on).
			return rest_ensure_response( [ 'success' => true, 'logged' => 0, 'recording' => false ] );
		}

		// A batch the page resends after a failed send is written once. Its id is remembered only once the batch has
		// been processed: a request that dies part-way is answered 5xx, and its resend must not be turned away as a
		// duplicate. Two requests carrying one id at the same moment are not locked against each other.
		$bid      = isset( $body['bid'] ) && is_string( $body['bid'] ) && preg_match( '/^[a-z0-9]{6,24}$/', $body['bid'] ) ? $body['bid'] : '';
		$seen_key = 'zdz_trb_' . $user_id;
		$seen     = [];
		if ( '' !== $bid ) {
			$seen = get_transient( $seen_key );
			$seen = is_array( $seen ) ? $seen : [];
			if ( in_array( $bid, $seen, true ) ) {
				return rest_ensure_response( [ 'success' => true, 'logged' => 0, 'duplicate' => true ] );
			}
		}
		$resend = ! empty( $body['re'] );   // a batch from an earlier visit: its id guards it, so no throttle
		$names  = null;

		// Limit batch size to prevent abuse.
		$events  = array_slice( $events, 0, 20 );
		$actions = null;

		$logged = 0;
		foreach ( $events as $evt ) {
			if ( ! is_array( $evt ) ) {
				continue;
			}
			$action = sanitize_key( (string) ( $evt['action_type'] ?? '' ) );
			if ( ! in_array( $action, self::CLIENT_KINDS, true ) ) {
				continue;
			}
			$app_id = (string) ( $evt['app_id'] ?? '' );
			$app_id = preg_match( '/^[a-z0-9_\-]{1,64}$/', $app_id ) ? $app_id : '';
			$age    = isset( $evt['age'] ) && is_numeric( $evt['age'] ) ? max( 0, (int) $evt['age'] ) : 0;
			if ( $age > 2 * DAY_IN_SECONDS ) {
				continue;
			}
			$detail = sanitize_textarea_field( (string) ( $evt['detail'] ?? '' ) );
			$meta   = [];
			$usage  = [
				'device'     => (string) ( $evt['device'] ?? '' ),
				'session_id' => (string) ( $evt['sid'] ?? '' ),
				'age'        => $age,
			];
			$sub = '';
			if ( 'search' === $action ) {
				$detail = in_array( $detail, self::SEARCH_DETAILS, true ) ? $detail : '';
				$sub    = $detail;
			} elseif ( 'command_palette' === $action ) {
				$detail = in_array( $detail, self::PALETTE_DETAILS, true ) ? $detail : '';
				$sub    = $detail;
			} elseif ( 'app_close' === $action ) {
				if ( '' === $app_id ) {
					continue;
				}
				$usage['secs'] = isset( $evt['secs'] ) && is_numeric( $evt['secs'] ) ? (int) $evt['secs'] : 0;
				$detail        = '';
			} elseif ( 'app_action' === $action ) {
				if ( null === $actions ) {
					$actions = self::usage_actions();
				}
				$act = (string) ( $evt['act'] ?? '' );
				if ( '' === $app_id || ! isset( $actions[ $app_id ][ $act ] ) ) {
					continue;
				}
				$detail      = $act;
				$meta['act'] = $act;
				$sub         = $act;
				$ref         = (string) ( $evt['ref'] ?? '' );
				if ( preg_match( '/^\d{1,12}$/', $ref ) ) {
					$meta['ref'] = $ref;
				}
			} elseif ( 'app_open' === $action ) {
				// The app's display name, but the registered one: never words the page made up.
				if ( null === $names ) {
					$names = class_exists( 'ZDZ_Usage' ) ? ZDZ_Usage::apps() : [];
				}
				if ( '' === $app_id ) {
					continue;
				}
				$detail = isset( $names[ $app_id ] ) ? mb_substr( wp_strip_all_tags( (string) $names[ $app_id ] ), 0, 120 ) : $app_id;
			} else {
				// view_switch, dashboard_load, heartbeat, widget_interact: a view or card id, never free text.
				$detail = preg_match( '/^[A-Za-z0-9_\- ]{0,40}$/', $detail ) ? $detail : '';
			}

			// Per person, kind and app (and action, box or result kind): a resent batch is guarded by its id instead.
			if ( ! $resend && $age <= 60 && 'app_close' !== $action ) {
				$throttle_key = 'zdz_tr_' . $user_id . '_' . $action . ( ( '' !== $app_id || '' !== $sub ) ? '_' . substr( md5( $app_id . '|' . $sub ), 0, 10 ) : '' );
				$ttl          = ( 'heartbeat' === $action ) ? self::BEAT_GAP : 10;
				if ( get_transient( $throttle_key ) ) {
					continue;
				}
				set_transient( $throttle_key, 1, $ttl );
			}

			if ( self::log_action( $user_id, $action, $detail, $app_id, $meta, $usage ) ) {
				$logged++;
			}
		}

		if ( '' !== $bid ) {
			$seen[] = $bid;
			set_transient( $seen_key, array_slice( $seen, -200 ), 2 * DAY_IN_SECONDS );   // two days of one person's batches
		}
		return rest_ensure_response( [ 'success' => true, 'logged' => $logged ] );
	}

	/* ──────────────────────────────────────────────
	 * Pruning the usage events
	 * ────────────────────────────────────────────── */

	/** Daily, about an hour after the first request that sees it unscheduled. */
	public static function schedule_prune() {
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( 'zdz_audit_prune' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'zdz_audit_prune' );
		}
	}

	/** The usage kinds that are pruned: USAGE_KINDS, or fewer if a filter takes some out. */
	public static function usage_kinds() {
		// A filter may only REMOVE kinds. Everything else in the table is somebody's accountability record (alerts, bug
		// status, crews, prices, chat audit), so nothing outside USAGE_KINDS is ever pruned.
		$kinds = apply_filters( 'zdz_audit_usage_kinds', self::USAGE_KINDS );
		$out   = array();
		foreach ( (array) $kinds as $k ) {
			$k = sanitize_key( (string) $k );
			if ( in_array( $k, self::USAGE_KINDS, true ) ) {
				$out[] = $k;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Count raw usage rows older than raw_days() into zdz_usage_daily, then delete them, in batches of 2,000; each batch
	 * is one transaction, so a batch is either counted AND deleted or neither (never counted twice, never lost). At
	 * most $max_rows a run; the daily schedule drains a backlog over a few days.
	 *
	 * @return array{deleted:int, rolled:int, cutoff:string, error:string}
	 */
	public static function prune( $now_ts = null, $max_rows = 20000 ) {
		global $wpdb;
		$t     = $wpdb->prefix . 'zdz_audit_log';
		$daily = $wpdb->prefix . 'zdz_usage_daily';
		$out   = array( 'deleted' => 0, 'rolled' => 0, 'cutoff' => '', 'error' => '' );

		if ( get_option( 'zdz_audit_log_version' ) !== self::DB_VERSION ) {
			self::get_instance()->create_table();
		}
		$days  = self::raw_days();
		// A hook passes '' (do_action) or nothing (cron); only a real timestamp is a clock (tests pass one).
		$now      = is_numeric( $now_ts ) && (int) $now_ts > 0 ? (int) $now_ts : time();
		$max_rows = is_numeric( $max_rows ) && (int) $max_rows > 0 ? (int) $max_rows : 20000;
		$kinds = self::usage_kinds();
		if ( ! $kinds ) {
			return $out;
		}
		$cutoff        = self::prune_cutoff( $now, $days );
		$out['cutoff'] = $cutoff;
		$in            = implode( ',', array_fill( 0, count( $kinds ), '%s' ) );

		while ( $out['deleted'] < (int) $max_rows ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$t} is $wpdb->prefix plus a fixed table name; $in is one %s placeholder per usage kind
				"SELECT id, user_id, action_type, app_id, created_at, secs FROM {$t} WHERE created_at < %s AND action_type IN ($in) ORDER BY id ASC LIMIT 2000",
				array_merge( array( $cutoff ), $kinds )
			), ARRAY_A );
			if ( ! $rows ) {
				break;
			}
			list( $groups, $secs, $ids ) = self::rollup( $rows );

			$wpdb->query( 'START TRANSACTION' );
			$ok = true;
			foreach ( $groups as $key => $n ) {
				list( $day, $uid, $kind, $app ) = explode( '|', $key, 4 );
				$upd = $wpdb->query( $wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$daily} is $wpdb->prefix plus a fixed table name
					"UPDATE {$daily} SET n = n + %d, secs = secs + %d WHERE day = %s AND user_id = %d AND action_type = %s AND app_id = %s",
					$n, (int) $secs[ $key ], $day, (int) $uid, $kind, $app
				) );
				if ( false === $upd ) {
					$ok = false;
					break;
				}
				if ( 0 === (int) $upd ) {
					$ins = $wpdb->insert( $daily, array( 'day' => $day, 'user_id' => (int) $uid, 'action_type' => $kind, 'app_id' => $app, 'n' => $n, 'secs' => (int) $secs[ $key ] ), array( '%s', '%d', '%s', '%s', '%d', '%d' ) );
					if ( false === $ins ) {
						$ok = false;
						break;
					}
				}
			}
			if ( $ok ) {
				$del = $wpdb->query( "DELETE FROM {$t} WHERE id IN (" . implode( ',', $ids ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL -- integers only
				$ok  = false !== $del && (int) $del === count( $ids );
			}
			if ( ! $ok ) {
				$wpdb->query( 'ROLLBACK' );
				$out['error'] = (string) ( $wpdb->last_error ? $wpdb->last_error : 'the batch did not apply' );
				error_log( 'Zorderz: audit prune stopped, nothing lost: ' . $out['error'] ); // phpcs:ignore
				break;
			}
			$wpdb->query( 'COMMIT' );
			$out['deleted'] += count( $ids );
			$out['rolled']  += count( $groups );
		}

		update_option( 'zdz_audit_prune_last', array( 'at' => gmdate( 'c', $now ) ) + $out, false );
		return $out;
	}

	/**
	 * The first moment a raw usage row is kept: midnight (the database's clock) $days days before $now. A row created
	 * before it is counted into the daily rollups and deleted. A day either way does not matter at 90 days or more.
	 */
	public static function prune_cutoff( $now, $days ) {
		return gmdate( 'Y-m-d 00:00:00', (int) $now - max( 90, (int) $days ) * DAY_IN_SECONDS );
	}

	/**
	 * One batch of raw rows as daily rollups: [ groups, secs, ids ]. groups and secs are keyed "Y-m-d|user|kind|app": how
	 * many rows, and the seconds open they carried (time open survives pruning as a daily total).
	 */
	public static function rollup( array $rows ) {
		$groups = array();
		$secs   = array();
		$ids    = array();
		foreach ( $rows as $r ) {
			$ids[] = (int) $r['id'];
			$key   = substr( (string) $r['created_at'], 0, 10 ) . '|' . (int) $r['user_id'] . '|' . (string) $r['action_type'] . '|' . (string) ( $r['app_id'] ?? '' );
			$groups[ $key ] = ( $groups[ $key ] ?? 0 ) + 1;
			$secs[ $key ]   = ( $secs[ $key ] ?? 0 ) + max( 0, (int) ( $r['secs'] ?? 0 ) );
		}
		return array( $groups, $secs, $ids );
	}

	/**
	 * Return a context array describing a user — handy for LLMs and apps.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array
	 */
	public static function get_user_context( $user_id ) {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return [];
		}

		$roles        = (array) $user->roles;
		$allowed_apps = get_user_meta( $user_id, 'zdz_allowed_apps', true );
		$denied_apps  = get_user_meta( $user_id, 'zdz_denied_apps', true );

		return [
			'user_id'      => $user->ID,
			'display_name' => $user->display_name,
			'initials'     => (string) get_user_meta( $user_id, 'zdz_user_initials', true ),
			'notes'        => (string) get_user_meta( $user_id, 'zdz_user_notes', true ),
			'role'         => ! empty( $roles ) ? $roles[0] : '',
			'allowed_apps' => is_array( $allowed_apps ) ? $allowed_apps : [],
			'denied_apps'  => is_array( $denied_apps ) ? $denied_apps : [],
			'safe_mode'    => (bool) get_user_meta( $user_id, 'zdz_safe_mode', true ),
		];
	}
}

// ── Interoperability shim: \Zorderz\ZDZ_Admin_Dashboard alias ─────────────
// Companion plugins (e.g. zdz-sales-analytics) write to the shared action log via
// \Zorderz\ZDZ_Admin_Dashboard::log_action(), matching the namespaced
// \Zorderz\ App interfaces in interface-zdz-app.php. This class lives in the
// GLOBAL namespace, so without an alias those calls — which the plugin guards with
// class_exists( '\Zorderz\ZDZ_Admin_Dashboard' ) — resolve to false, and every
// cross-plugin audit entry (analytics_session, memory edits, cache clears, etc.)
// is silently dropped. The alias makes the namespaced reference resolve to this
// class so that logging actually lands. Purely additive: the theme's own code
// keeps calling the global name, and any plugin already using the global name is
// unaffected. (ZDZ_Data_Permissions is intentionally left global — the plugin calls
// it unprefixed, so it already resolves.)
if ( ! class_exists( 'Zorderz\\ZDZ_Admin_Dashboard', false ) ) {
	class_alias( 'ZDZ_Admin_Dashboard', 'Zorderz\\ZDZ_Admin_Dashboard' );
}
