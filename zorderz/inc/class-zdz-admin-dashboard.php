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

	/** Current audit-log schema version. Bump to trigger dbDelta. */
	const DB_VERSION = '1.0.0';

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

		// Login / logout tracking.
		add_action( 'wp_login', [ __CLASS__, 'track_login' ], 10, 2 );
		add_action( 'wp_logout', [ __CLASS__, 'track_logout' ] );

		// v2.17.1: Track front-end page loads server-side (SPA shell load).
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
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY action_type (action_type),
			KEY created_at (created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

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
		if ( 'zorderz_page_ts-user-management' !== $hook_suffix ) {
			return;
		}

		$version = wp_get_theme()->get( 'Version' );

		wp_enqueue_style(
			'zdz-admin-dashboard-css',
			get_template_directory_uri() . '/assets/css/admin-dashboard.css',
			[],
			$version
		);

		wp_enqueue_script(
			'zdz-admin-dashboard-js',
			get_template_directory_uri() . '/assets/js/admin-dashboard.js',
			[],
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

		// v2.17.1: POST /track — front-end activity tracking (any logged-in user)
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

		// v2.14.3.1: Build the app_permissions map that the admin-dashboard JS
		// expects. Converts the two flat arrays into { app_id: 'allow'|'deny' }.
		// Apps not in either list default to 'default' on the JS side.
		$perms = [];
		foreach ( $allowed_apps as $app_id ) {
			$perms[ $app_id ] = 'allow';
		}
		foreach ( $denied_apps as $app_id ) {
			$perms[ $app_id ] = 'deny';
		}

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

		// v2.14.3.1: Accept the permissions map format sent by admin-dashboard.js.
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
			// v2.17.1: Handle both compact keys (nm, cat, cc) and full keys (name, category, color)
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
	 * Insert a single row into the audit log table.
	 *
	 * @param int    $user_id     WordPress user ID.
	 * @param string $action_type Short action identifier (e.g. "login", "role_change").
	 * @param string $detail      Human-readable detail string.
	 * @param string $app_id      Optional related app ID.
	 * @param array  $meta        Optional associative array stored as JSON.
	 */
	public static function log_action( $user_id, $action_type, $detail = '', $app_id = '', $meta = [] ) {
		global $wpdb;

		$table = $wpdb->prefix . 'zdz_audit_log';

		// Auto-enrich meta with user initials.
		$initials = (string) get_user_meta( $user_id, 'zdz_user_initials', true );
		if ( '' !== $initials ) {
			$meta['user_initials'] = $initials;
		}

		// Determine client IP.
		$ip = '';
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			// May contain comma-separated list; take the first.
			if ( false !== strpos( $ip, ',' ) ) {
				$ip = trim( explode( ',', $ip )[0] );
			}
		} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		$wpdb->insert(
			$table,
			[
				'user_id'       => absint( $user_id ),
				'action_type'   => sanitize_text_field( $action_type ),
				'action_detail' => sanitize_textarea_field( $detail ),
				'app_id'        => '' !== $app_id ? sanitize_text_field( $app_id ) : null,
				'ip_address'    => sanitize_text_field( $ip ),
				'meta_json'     => ! empty( $meta ) ? wp_json_encode( $meta ) : null,
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s' ]
		);
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

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		self::log_action( $user_id, 'page_view', 'SPA shell loaded: ' . $uri );
	}

	/**
	 * POST /zorderz/v1/track — Accept batched activity events from the front-end.
	 *
	 * Expected JSON body: { "events": [ { "action_type", "detail", "app_id" }, ... ] }
	 * Throttled per action_type: heartbeat max 1/5min, others max 1/30sec.
	 *
	 * @since 2.17.1
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

		// Limit batch size to prevent abuse.
		$events = array_slice( $events, 0, 20 );

		$allowed_actions = [
			'app_open', 'view_switch', 'dashboard_load', 'heartbeat',
			'widget_interact', 'search', 'command_palette',
		];

		$logged = 0;
		foreach ( $events as $evt ) {
			if ( ! is_array( $evt ) ) {
				continue;
			}
			$action = sanitize_text_field( $evt['action_type'] ?? '' );
			if ( ! in_array( $action, $allowed_actions, true ) ) {
				continue;
			}

			// Per-action throttle to prevent duplicate entries.
			$throttle_key = 'zdz_tr_' . $user_id . '_' . $action;
			$ttl = ( $action === 'heartbeat' ) ? 4 * MINUTE_IN_SECONDS : 10;
			if ( get_transient( $throttle_key ) ) {
				continue;
			}
			set_transient( $throttle_key, 1, $ttl );

			$detail = sanitize_textarea_field( $evt['detail'] ?? '' );
			$app_id = sanitize_text_field( $evt['app_id'] ?? '' );

			self::log_action( $user_id, $action, $detail, $app_id );
			$logged++;
		}

		return rest_ensure_response( [ 'success' => true, 'logged' => $logged ] );
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
