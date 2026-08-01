<?php
/**
 * Theme-level alert routing — cross-plugin notification delivery.
 *
 * Controls HOW and WHETHER alerts reach team members via configurable
 * channels. Plugins call ZDZ_Alert_Router::send() and the router
 * dispatches to the enabled channels for that alert type.
 *
 * Channel matrix is resolved by merging:
 *   1. Alert type defaults (set by the plugin registering the type)
 *   2. Admin channel overrides (zdz_alert_channel_config option)
 *   3. Per-user preferences (zdz_alert_prefs user meta) — future
 *
 * Delivery channels:
 *   - in_app   : Writes to wp_zdz_notifications (badge + SPA inbox)
 *   - email    : Sends via wp_mail() with branded template
 *   - disabled : Logged but not delivered
 *
 * All plugins consume this via:
 *   ZDZ_Alert_Router::send( 'survey_note_forwarded', $recipient_id, $title, $message, $source )
 *
 * @package Zorderz
 * @since   2.19.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class ZDZ_Alert_Router {

	private static $instance = null;

	/** @var array Registered alert types: key => config. */
	private $types = [];

	/** @var array Request-level cache for resolved channel configs. */
	private static $config_cache = null;

	// ═══════════════════════════════════════════════════════════════════
	//  Channel Definitions
	// ═══════════════════════════════════════════════════════════════════

	/** All supported delivery channels. */
	const CHANNELS = [ 'in_app', 'email' ];

	/**
	 * Role-based defaults: which channels are available per role.
	 * Mirrors the ZDZ_Data_Permissions pattern.
	 *
	 * 'allow' = can receive via this channel | 'deny' = blocked
	 * When per-user prefs are 'default' or missing, this resolves it.
	 */
	const ROLE_CHANNEL_DEFAULTS = [
		'zdz_owner' => [
			'in_app' => 'allow',
			'email'  => 'allow',
		],
		'zdz_admin' => [
			'in_app' => 'allow',
			'email'  => 'allow',
		],
		'zdz_sales' => [
			'in_app' => 'allow',
			'email'  => 'deny',
		],
		'zdz_operator' => [
			'in_app' => 'allow',
			'email'  => 'deny',
		],
		'zdz_mfg' => [
			'in_app' => 'deny',
			'email'  => 'deny',
		],
		'zdz_tech' => [
			'in_app' => 'deny',
			'email'  => 'deny',
		],
	];

	// ═══════════════════════════════════════════════════════════════════
	//  Singleton
	// ═══════════════════════════════════════════════════════════════════

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );

		// Collect alert types from plugins after they've loaded
		add_action( 'init', [ $this, 'collect_types' ], 25 );
	}

	// ═══════════════════════════════════════════════════════════════════
	//  Alert Type Registration
	// ═══════════════════════════════════════════════════════════════════

	/**
	 * Register an alert type. Called by plugins during 'zdz_register_alert_types' action.
	 *
	 * @param string $key    Unique type key (e.g., 'survey_note_forwarded').
	 * @param array  $config {
	 *     @type string   $label            Human-readable label for admin UI.
	 *     @type string   $description      What triggers this alert.
	 *     @type string[] $default_channels Channels enabled by default: 'in_app', 'email'.
	 *     @type string   $source_plugin    Plugin identifier (e.g., 'zdz-satisfaction-surveys').
	 *     @type string   $category         Grouping for admin UI: 'surveys', 'sales', 'system'.
	 * }
	 */
	public function register_type( string $key, array $config ): void {
		$this->types[ $key ] = wp_parse_args( $config, [
			'label'            => $key,
			'description'      => '',
			'default_channels' => [ 'in_app' ],
			'source_plugin'    => '',
			'category'         => 'general',
		] );
	}

	/**
	 * Collect alert types from all plugins.
	 * Fires on 'init' at priority 25 (after plugins have loaded).
	 */
	public function collect_types(): void {
		// Let plugins register their types
		do_action( 'zdz_register_alert_types', $this );

		// Built-in types (Satisfaction Surveys v2.9.0)
		if ( ! isset( $this->types['survey_note_forwarded'] ) ) {
			$this->register_type( 'survey_note_forwarded', [
				'label'            => 'Survey Note Forwarded',
				'description'      => 'A Nutshell note was forwarded from the Surveys widget.',
				'default_channels' => [ 'in_app' ],
				'source_plugin'    => 'zdz-satisfaction-surveys',
				'category'         => 'surveys',
			] );
		}
		if ( ! isset( $this->types['survey_task_assigned'] ) ) {
			$this->register_type( 'survey_task_assigned', [
				'label'            => 'Survey Task Assigned',
				'description'      => 'A survey-related task was assigned to you.',
				'default_channels' => [ 'in_app' ],
				'source_plugin'    => 'zdz-satisfaction-surveys',
				'category'         => 'surveys',
			] );
		}
		if ( ! isset( $this->types['survey_task_completed'] ) ) {
			$this->register_type( 'survey_task_completed', [
				'label'            => 'Survey Task Completed',
				'description'      => 'A survey-related task you forwarded was completed.',
				'default_channels' => [ 'in_app' ],
				'source_plugin'    => 'zdz-satisfaction-surveys',
				'category'         => 'surveys',
			] );
		}
	}

	/**
	 * Get all registered alert types.
	 *
	 * @return array Map of type_key => config.
	 */
	public function get_types(): array {
		return $this->types;
	}

	// ═══════════════════════════════════════════════════════════════════
	//  Channel Configuration (3-Tier Resolution)
	// ═══════════════════════════════════════════════════════════════════

	/**
	 * Get the effective channel configuration for a specific alert type and user.
	 *
	 * Resolution order:
	 *   1. Per-user override (zdz_alert_prefs meta) — if set
	 *   2. Admin override (zdz_alert_channel_config option) — if set
	 *   3. Type default (from registration)
	 *
	 * Then filtered by role channel eligibility (ROLE_CHANNEL_DEFAULTS).
	 *
	 * @param string $type    Alert type key.
	 * @param int    $user_id Recipient user ID.
	 * @return string[] Enabled channels for this type+user.
	 */
	public function resolve_channels( string $type, int $user_id ): array {
		// Step 1: Start with type defaults
		$type_config = $this->types[ $type ] ?? null;
		$channels = $type_config ? $type_config['default_channels'] : [ 'in_app' ];

		// Step 2: Apply admin overrides (global setting)
		$admin_config = $this->get_admin_config();
		if ( isset( $admin_config[ $type ] ) && is_array( $admin_config[ $type ] ) ) {
			$channels = $admin_config[ $type ];
		}

		// Step 3: Apply per-user overrides (future — initially empty)
		$user_prefs = get_user_meta( $user_id, 'zdz_alert_prefs', true );
		if ( is_array( $user_prefs ) && isset( $user_prefs[ $type ] ) && is_array( $user_prefs[ $type ] ) ) {
			$channels = $user_prefs[ $type ];
		}

		// Step 4: Filter by role eligibility
		$user = get_userdata( $user_id );
		if ( $user ) {
			$role = 'zdz_tech'; // most restrictive default
			foreach ( $user->roles as $r ) {
				if ( isset( self::ROLE_CHANNEL_DEFAULTS[ $r ] ) || 'administrator' === $r ) {
					$role = $r;
					break;
				}
			}
			$role_channels = self::ROLE_CHANNEL_DEFAULTS[ $role ]
				?? self::ROLE_CHANNEL_DEFAULTS['zdz_admin'] ?? [];

			// WP administrator gets zdz_admin defaults
			if ( 'administrator' === $role ) {
				$role_channels = self::ROLE_CHANNEL_DEFAULTS['zdz_admin'];
			}

			// Remove channels the role doesn't allow
			$channels = array_filter( $channels, function( $ch ) use ( $role_channels ) {
				return ( $role_channels[ $ch ] ?? 'deny' ) === 'allow';
			} );
		}

		return array_values( $channels );
	}

	/**
	 * Get the admin-level channel config from wp_options.
	 *
	 * @return array Map of type_key => ['in_app', 'email'] enabled channels.
	 */
	public function get_admin_config(): array {
		if ( null !== self::$config_cache ) {
			return self::$config_cache;
		}
		self::$config_cache = get_option( 'zdz_alert_channel_config', [] );
		if ( ! is_array( self::$config_cache ) ) {
			self::$config_cache = [];
		}
		return self::$config_cache;
	}

	/**
	 * Update the admin-level channel config.
	 *
	 * @param array $config Map of type_key => channels array.
	 */
	public function update_admin_config( array $config ): void {
		// Validate: only keep known types and valid channels
		$clean = [];
		foreach ( $config as $type => $channels ) {
			if ( ! is_array( $channels ) ) continue;
			$clean[ sanitize_key( $type ) ] = array_values( array_intersect( $channels, self::CHANNELS ) );
		}
		update_option( 'zdz_alert_channel_config', $clean );
		self::$config_cache = $clean;
	}

	// ═══════════════════════════════════════════════════════════════════
	//  Core Send Method
	// ═══════════════════════════════════════════════════════════════════

	/**
	 * Send an alert to a user through the configured channels.
	 *
	 * This is the primary entry point for all plugins:
	 *   ZDZ_Alert_Router::send( 'survey_note_forwarded', $recipient_id, 'Title', 'Message', [...] );
	 *
	 * @param string $type         Alert type key (must be registered).
	 * @param int    $recipient_id WordPress user ID of the recipient.
	 * @param string $title        Short title for the notification.
	 * @param string $message      Full message body.
	 * @param array  $source       Optional context: sender_id, plugin, source_id, lead_id, url, etc.
	 * @return string[] Channels that were used for delivery.
	 */
	public static function send( string $type, int $recipient_id, string $title, string $message, array $source = [] ): array {
		$instance = self::get_instance();

		// Resolve which channels to use for this type + user combo
		$channels = $instance->resolve_channels( $type, $recipient_id );

		if ( empty( $channels ) ) {
			error_log( sprintf(
				'TS Alert Router: Alert [%s] for user %d — all channels disabled, skipping.',
				$type, $recipient_id
			) );
			return [];
		}

		$sender_id = $source['sender_id'] ?? get_current_user_id();
		$channels_used = [];

		// ── Dispatch to each enabled channel ──

		if ( in_array( 'in_app', $channels, true ) ) {
			$success = $instance->deliver_in_app( $recipient_id, $type, $title, $message, $source );
			if ( $success ) $channels_used[] = 'in_app';
		}

		if ( in_array( 'email', $channels, true ) ) {
			$success = $instance->deliver_email( $recipient_id, $type, $title, $message, $source );
			if ( $success ) $channels_used[] = 'email';
		}

		// ── Audit log ──
		if ( class_exists( 'ZDZ_Admin_Dashboard' ) && method_exists( 'ZDZ_Admin_Dashboard', 'log_action' ) ) {
			ZDZ_Admin_Dashboard::log_action(
				$sender_id,
				'alert_sent',
				sprintf( 'Alert [%s] → user %d via %s', $type, $recipient_id, implode( ', ', $channels_used ) ),
				$source['plugin'] ?? '',
				array_merge( $source, [
					'alert_type'    => $type,
					'channels_used' => $channels_used,
					'recipient_id'  => $recipient_id,
				] )
			);
		}

		return $channels_used;
	}

	// ═══════════════════════════════════════════════════════════════════
	//  Channel: In-App Notifications
	// ═══════════════════════════════════════════════════════════════════

	/**
	 * Deliver an in-app notification.
	 * Writes to wp_zdz_notifications table for SPA badge/inbox.
	 */
	private function deliver_in_app( int $user_id, string $type, string $title, string $message, array $source ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'zdz_notifications';

		$inserted = $wpdb->insert( $table, [
			'user_id'       => $user_id,
			'alert_type'    => $type,
			'title'         => $title,
			'message'       => $message,
			'source_plugin' => $source['plugin'] ?? '',
			'source_id'     => $source['source_id'] ?? null,
			'source_meta'   => ! empty( $source ) ? wp_json_encode( $source ) : null,
			'created_at'    => current_time( 'mysql' ),
		], [ '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ] );

		if ( ! $inserted ) {
			error_log( 'TS Alert Router: deliver_in_app DB error: ' . $wpdb->last_error );
			return false;
		}

		// Bust the Redis-cached unread count for this user
		delete_transient( 'zdz_unread_alerts_' . $user_id );

		return true;
	}

	/**
	 * Get notifications for a user (for SPA inbox).
	 *
	 * @param int  $user_id    WordPress user ID.
	 * @param bool $unread_only Only return unread notifications.
	 * @param int  $limit      Max results.
	 * @param int  $offset     Pagination offset.
	 * @return array Notifications with metadata.
	 */
	public static function get_notifications( int $user_id, bool $unread_only = false, int $limit = 50, int $offset = 0 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'zdz_notifications';

		$where = $wpdb->prepare( 'WHERE user_id = %d', $user_id );
		if ( $unread_only ) {
			$where .= ' AND is_read = 0';
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$limit, $offset
			),
			ARRAY_A
		) ?: [];
	}

	/**
	 * Get unread notification count for a user.
	 * Redis-cached via transient for fast badge polling.
	 */
	public static function get_unread_count( int $user_id ): int {
		$cache_key = 'zdz_unread_alerts_' . $user_id;
		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'zdz_notifications';
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND is_read = 0", $user_id )
		);

		set_transient( $cache_key, $count, 5 * MINUTE_IN_SECONDS );
		return $count;
	}

	/**
	 * Mark a notification as read.
	 */
	public static function mark_read( int $notification_id, int $user_id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'zdz_notifications';

		$updated = $wpdb->update(
			$table,
			[ 'is_read' => 1, 'read_at' => current_time( 'mysql' ) ],
			[ 'id' => $notification_id, 'user_id' => $user_id ],
			[ '%d', '%s' ],
			[ '%d', '%d' ]
		);

		if ( false !== $updated ) {
			delete_transient( 'zdz_unread_alerts_' . $user_id );
		}

		return false !== $updated;
	}

	/**
	 * Mark all notifications as read for a user.
	 */
	public static function mark_all_read( int $user_id ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'zdz_notifications';

		$count = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET is_read = 1, read_at = %s WHERE user_id = %d AND is_read = 0",
				current_time( 'mysql' ), $user_id
			)
		);

		delete_transient( 'zdz_unread_alerts_' . $user_id );
		return (int) $count;
	}

	// ═══════════════════════════════════════════════════════════════════
	//  Channel: Email
	// ═══════════════════════════════════════════════════════════════════

	/**
	 * Deliver an alert via email.
	 */
	private function deliver_email( int $user_id, string $type, string $title, string $message, array $source ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user || empty( $user->user_email ) ) {
			error_log( 'TS Alert Router: deliver_email — no email for user ' . $user_id );
			return false;
		}

		// Build email content
		$sender      = isset( $source['sender_id'] ) ? get_userdata( $source['sender_id'] ) : null;
		$sender_name = $sender ? $sender->display_name : 'Zorderz';
		$type_config = $this->types[ $type ] ?? [];
		$type_label  = $type_config['label'] ?? $type;

		$subject = sprintf( '[Zorderz] %s', $title );

		// Plain text email body (HTML can be added later)
		$body  = sprintf( "Hi %s,\n\n", $user->display_name );
		$body .= sprintf( "%s\n\n", $message );
		$body .= "---\n";
		$body .= sprintf( "From: %s\n", $sender_name );
		$body .= sprintf( "Alert type: %s\n", $type_label );
		if ( ! empty( $source['lead_name'] ) ) {
			$body .= sprintf( "Lead: %s\n", $source['lead_name'] );
		}
		$body .= "\nLog in to Zorderz to take action.\n";
		$body .= home_url( '/' ) . "\n";

		$headers = [ 'Content-Type: text/plain; charset=UTF-8' ];

		$sent = wp_mail( $user->user_email, $subject, $body, $headers );

		if ( ! $sent ) {
			error_log( sprintf( 'TS Alert Router: deliver_email FAILED for user %d (%s)', $user_id, $user->user_email ) );
		}

		return $sent;
	}

	// ═══════════════════════════════════════════════════════════════════
	//  REST Endpoints (for SPA consumption)
	// ═══════════════════════════════════════════════════════════════════

	/**
	 * Register REST API routes under ts/v1 namespace.
	 */
	public function register_routes(): void {
		$ns = 'zorderz/v1';

		// ── User-facing: Notification inbox ──

		register_rest_route( $ns, '/notifications', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'rest_get_notifications' ],
			'permission_callback' => function() { return is_user_logged_in(); },
			'args' => [
				'unread' => [ 'type' => 'boolean', 'default' => false ],
				'limit'  => [ 'type' => 'integer', 'default' => 50, 'sanitize_callback' => 'absint' ],
				'offset' => [ 'type' => 'integer', 'default' => 0,  'sanitize_callback' => 'absint' ],
			],
		] );

		register_rest_route( $ns, '/notifications/unread-count', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'rest_get_unread_count' ],
			'permission_callback' => function() { return is_user_logged_in(); },
		] );

		register_rest_route( $ns, '/notifications/(?P<id>\d+)/read', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'rest_mark_read' ],
			'permission_callback' => function() { return is_user_logged_in(); },
		] );

		register_rest_route( $ns, '/notifications/read-all', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'rest_mark_all_read' ],
			'permission_callback' => function() { return is_user_logged_in(); },
		] );

		// ── Admin-facing: Alert channel configuration ──

		register_rest_route( $ns, '/admin/alerts/types', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'rest_get_alert_types' ],
			'permission_callback' => function() { return current_user_can( 'manage_options' ); },
		] );

		register_rest_route( $ns, '/admin/alerts/config', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'rest_get_config' ],
			'permission_callback' => function() { return current_user_can( 'manage_options' ); },
		] );

		register_rest_route( $ns, '/admin/alerts/config', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'rest_update_config' ],
			'permission_callback' => function() { return current_user_can( 'manage_options' ); },
		] );
	}

	// ── REST Handlers ──

	public function rest_get_notifications( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id = get_current_user_id();
		$unread  = (bool) $request->get_param( 'unread' );
		$limit   = min( (int) $request->get_param( 'limit' ), 100 );
		$offset  = (int) $request->get_param( 'offset' );

		$notifications = self::get_notifications( $user_id, $unread, $limit, $offset );
		$unread_count  = self::get_unread_count( $user_id );

		return rest_ensure_response( [
			'success'      => true,
			'data'         => $notifications,
			'unread_count' => $unread_count,
		] );
	}

	public function rest_get_unread_count( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response( [
			'success' => true,
			'count'   => self::get_unread_count( get_current_user_id() ),
		] );
	}

	public function rest_mark_read( \WP_REST_Request $request ): \WP_REST_Response {
		$id      = (int) $request->get_param( 'id' );
		$user_id = get_current_user_id();
		$success = self::mark_read( $id, $user_id );

		return rest_ensure_response( [
			'success'      => $success,
			'unread_count' => self::get_unread_count( $user_id ),
		] );
	}

	public function rest_mark_all_read( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id = get_current_user_id();
		$count   = self::mark_all_read( $user_id );

		return rest_ensure_response( [
			'success'      => true,
			'marked_count' => $count,
			'unread_count' => 0,
		] );
	}

	public function rest_get_alert_types( \WP_REST_Request $request ): \WP_REST_Response {
		$types  = $this->get_types();
		$config = $this->get_admin_config();

		// Merge effective channels into each type
		$result = [];
		foreach ( $types as $key => $type ) {
			$result[ $key ] = $type;
			$result[ $key ]['effective_channels'] = $config[ $key ] ?? $type['default_channels'];
		}

		return rest_ensure_response( [
			'success'  => true,
			'types'    => $result,
			'channels' => self::CHANNELS,
		] );
	}

	public function rest_get_config( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response( [
			'success'           => true,
			'config'            => $this->get_admin_config(),
			'role_defaults'     => self::ROLE_CHANNEL_DEFAULTS,
			'available_channels' => self::CHANNELS,
		] );
	}

	public function rest_update_config( \WP_REST_Request $request ): \WP_REST_Response {
		$config = $request->get_json_params();
		if ( ! is_array( $config ) ) {
			return rest_ensure_response( [ 'success' => false, 'error' => 'Invalid config format.' ] );
		}

		$this->update_admin_config( $config );

		// Audit log
		if ( class_exists( 'ZDZ_Admin_Dashboard' ) && method_exists( 'ZDZ_Admin_Dashboard', 'log_action' ) ) {
			ZDZ_Admin_Dashboard::log_action(
				get_current_user_id(),
				'alert_config_updated',
				'Alert channel configuration updated.',
				'',
				[ 'new_config' => $config ]
			);
		}

		return rest_ensure_response( [ 'success' => true ] );
	}
}
