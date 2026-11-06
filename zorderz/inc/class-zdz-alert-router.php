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
 *   3. Per-user preferences (zdz_alert_prefs user meta) — the bell's "Notices you get"
 *
 * Delivery channels:
 *   - in_app   : Writes to wp_zdz_notifications (the bell's badge and list)
 *   - email    : Sends via wp_mail() with branded template
 *   - push     : Hands {title, body, url, tag} to the messaging app's quiet-hours seam,
 *                apply_filters( 'zim_queue_notice', null, $uid, $payload ). It is a filter,
 *                never a class of that app. With nobody answering it the channel is
 *                "not used" and in_app still works. The push carries the TITLE ONLY plus
 *                $source['push_body'] (default "Tap to open."), because it shows on a lock
 *                screen: a type's title must never name a customer.
 *   - disabled : Logged but not delivered
 *
 * Never the shared device: send() refuses a recipient that is_kiosk_user() says is the
 * shared device, whatever other roles it holds, and the REST routes answer it an empty
 * list and 0.
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
	const CHANNELS = [ 'in_app', 'email', 'push' ];

	/**
	 * Option an org pack writes: per role, extra channels for one category of alert, added to
	 * what ROLE_CHANNEL_DEFAULTS allows. Shape: [ role => [ category => [ channel, ... ] ] ].
	 * Example: [ 'zdz_tech' => [ 'jobs' => [ 'in_app', 'push' ] ] ] lets field staff get the bell
	 * and push for job notices without opening every other kind to them.
	 * Core ships none: a role gets exactly what its row below allows.
	 */
	const OPT_ROLE_CATEGORY_GRANTS = 'zdz_alert_role_category_grants';

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
			'push'   => 'allow',
			'email'  => 'allow',
		],
		'zdz_admin' => [
			'in_app' => 'allow',
			'push'   => 'allow',
			'email'  => 'allow',
		],
		'zdz_sales' => [
			'in_app' => 'allow',
			'push'   => 'allow',
			'email'  => 'deny',
		],
		'zdz_operator' => [
			'in_app' => 'allow',
			'push'   => 'allow',
			'email'  => 'deny',
		],
		'zdz_mfg' => [
			'in_app' => 'deny',
			'email'  => 'deny',
			'push'   => 'deny',
		],
		'zdz_tech' => [
			'in_app' => 'deny',
			'email'  => 'deny',
			'push'   => 'deny',
		],
	];

	/**
	 * Per role, the extra channels each alert category opens (see OPT_ROLE_CATEGORY_GRANTS).
	 * Read from the option, then the `zdz_alert_role_category_grants` filter, then cleaned:
	 * only the platform's own role slugs, category keys, and known channels survive, and the
	 * shared device's role never gets a grant.
	 *
	 * @return array<string,array<string,string[]>>
	 */
	public static function role_category_grants(): array {
		$raw = function_exists( 'get_option' ) ? get_option( self::OPT_ROLE_CATEGORY_GRANTS, [] ) : [];
		$raw = is_array( $raw ) ? $raw : [];
		if ( function_exists( 'apply_filters' ) ) {
			$f   = apply_filters( 'zdz_alert_role_category_grants', $raw );
			$raw = is_array( $f ) ? $f : $raw;
		}
		return self::clean_role_category_grants( $raw );
	}

	/**
	 * Category grants as stored or as a pack writes them, cleaned by the one rule every reader uses:
	 * only the platform's own role slugs with a channel row (never the shared device's role), category
	 * keys, and known channels. Pure.
	 *
	 * @param mixed $raw [ role => [ category => [ channel, ... ] ] ]
	 * @return array<string,array<string,string[]>>
	 */
	public static function clean_role_category_grants( $raw ): array {
		$out = [];
		foreach ( is_array( $raw ) ? $raw : [] as $role => $cats ) {
			$role = (string) $role;
			if ( ! isset( self::ROLE_CHANNEL_DEFAULTS[ $role ] ) || ! is_array( $cats ) ) {
				continue;
			}
			foreach ( $cats as $cat => $chs ) {
				$cat = sanitize_key( (string) $cat );
				$chs = array_values( array_intersect( array_map( 'strval', (array) $chs ), self::CHANNELS ) );
				if ( '' !== $cat && $chs ) {
					$out[ $role ][ $cat ] = $chs;
				}
			}
		}
		return $out;
	}

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

		// Built-in types (Satisfaction Surveys)
		if ( ! isset( $this->types['survey_note_forwarded'] ) ) {
			$this->register_type( 'survey_note_forwarded', [
				'label'            => 'Survey Note Forwarded',
				'description'      => 'A CRM note was forwarded from the Surveys widget.',
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

	/**
	 * The categories the registered alert types use, sorted; [] before the types are collected (they are, on init).
	 * A category grant naming none of these opens nothing, so a pack's preview names it (a likely typo).
	 *
	 * @return string[]
	 */
	public static function categories_in_use(): array {
		$out = [];
		foreach ( null !== self::$instance ? self::$instance->types : [] as $t ) {
			$c = sanitize_key( (string) ( $t['category'] ?? '' ) );
			if ( '' !== $c ) {
				$out[ $c ] = true;
			}
		}
		$out = array_keys( $out );
		sort( $out );
		return $out;
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
	 * @param string $type            Alert type key.
	 * @param int    $user_id         Recipient user ID.
	 * @param bool   $with_user_prefs False: as if the person had made no choices of their own.
	 * @return string[] Enabled channels for this type+user.
	 */
	public function resolve_channels( string $type, int $user_id, bool $with_user_prefs = true ): array {
		// Step 1: Start with type defaults
		$type_config = $this->types[ $type ] ?? null;
		$channels = $type_config ? $type_config['default_channels'] : [ 'in_app' ];

		// Step 2: Apply admin overrides (global setting)
		$admin_config = $this->get_admin_config();
		if ( isset( $admin_config[ $type ] ) && is_array( $admin_config[ $type ] ) ) {
			$channels = $admin_config[ $type ];
		}

		// Step 3: Apply the person's own choices (the bell's "Notices you get")
		$user_prefs = $with_user_prefs ? get_user_meta( $user_id, 'zdz_alert_prefs', true ) : [];
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

			// A category grant from the org's settings. Only for someone who really holds that
			// role: an account with no platform role falls back to zdz_tech's row above and must
			// not pick up that role's grants with it.
			$category = (string) ( $type_config['category'] ?? '' );
			$grants   = in_array( $role, (array) $user->roles, true ) ? ( self::role_category_grants()[ $role ][ $category ] ?? [] ) : [];
			foreach ( (array) $grants as $granted ) {
				$role_channels[ $granted ] = 'allow';
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

		// Never the shared device, whatever other roles the account holds.
		if ( self::is_kiosk_user( $recipient_id ) ) {
			error_log( sprintf( 'Zorderz Alert Router: Alert [%s] refused for user %d - the shared device never gets alerts.', $type, $recipient_id ) );
			return [];
		}

		// Resolve which channels to use for this type + user combo
		$channels = $instance->resolve_channels( $type, $recipient_id );

		if ( empty( $channels ) ) {
			error_log( sprintf(
				'Zorderz Alert Router: Alert [%s] for user %d — all channels disabled, skipping.',
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

		if ( in_array( 'push', $channels, true ) ) {
			$success = $instance->deliver_push( $recipient_id, $type, $title, $source );
			if ( $success ) $channels_used[] = 'push';
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

	/** Is this account the shared device? Never a person. */
	public static function is_kiosk_user( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		if ( class_exists( 'ZDZ_Hierarchy' ) && method_exists( 'ZDZ_Hierarchy', 'is_kiosk' ) && ZDZ_Hierarchy::is_kiosk( $user_id ) ) {
			return true;
		}
		if ( class_exists( 'ZDZ_User_Roles' ) && method_exists( 'ZDZ_User_Roles', 'is_shared_device' ) && ZDZ_User_Roles::is_shared_device( $user_id ) ) {
			return true;
		}
		$u = get_userdata( $user_id );
		return $u && in_array( 'zdz_general', (array) $u->roles, true );
	}

	/**
	 * A link on this site, or ''. The bell and the push open only same-site links: a path
	 * ("/x", never "//x"), or an absolute URL whose scheme, host and port are this site's
	 * (an explicit default port counts as none). No credentials, backslashes, spaces or
	 * control characters; at most 500 characters.
	 */
	public static function same_site_url( $url ): string {
		$url = is_string( $url ) ? trim( $url ) : '';
		if ( '' === $url || strlen( $url ) > 500 || false !== strpos( $url, '\\' ) || preg_match( '/[\x00-\x20\x7F]/', $url ) ) {
			return '';
		}
		$home = wp_parse_url( home_url( '/' ) );
		if ( '/' === $url[0] ) {
			return ( isset( $url[1] ) && '/' === $url[1] ) ? '' : $url;
		}
		$u = wp_parse_url( $url );
		if ( ! is_array( $u ) || ! is_array( $home ) || isset( $u['user'] ) || isset( $u['pass'] ) ) {
			return '';
		}
		$scheme = strtolower( (string) ( $home['scheme'] ?? 'https' ) );
		$dport  = 'http' === $scheme ? 80 : 443;
		$same = strtolower( (string) ( $u['scheme'] ?? '' ) ) === $scheme
			&& strtolower( (string) ( $u['host'] ?? '' ) ) === strtolower( (string) ( $home['host'] ?? '' ) )
			&& (int) ( $u['port'] ?? $dport ) === (int) ( $home['port'] ?? $dport );
		return $same ? $url : '';
	}

	// ═══════════════════════════════════════════════════════════════════
	//  Channel: Push (through the messaging app's quiet-hours seam)
	// ═══════════════════════════════════════════════════════════════════

	/**
	 * The push payload for one alert: the title and a short body only, never the message,
	 * which may name a customer. Pure, so it can be checked without sending anything.
	 *
	 * @return array{title:string,body:string,url:string,tag:string}
	 */
	public static function push_payload( string $type, string $title, array $source ): array {
		$cut  = function ( $s, $n ) { return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $n ) : substr( $s, 0, $n ); };
		$scal = function ( $v ) { return is_scalar( $v ) ? (string) $v : ''; };   // never "Array"
		$body = trim( $scal( $source['push_body'] ?? '' ) );
		$url  = self::same_site_url( $source['url'] ?? '' );
		$sid  = preg_replace( '/[^0-9A-Za-z_-]/', '', $scal( $source['source_id'] ?? '' ) );
		return [
			'title' => $cut( trim( $title ), 120 ),
			'body'  => '' !== $body ? $cut( $body, 120 ) : __( 'Tap to open.', 'zorderz' ),
			'url'   => '' !== $url ? $url : home_url( '/' ),
			'tag'   => substr( 'zdz-alert-' . sanitize_key( $type ) . ( '' !== $sid ? '-' . $sid : '' ), 0, 64 ),
		];
	}

	/**
	 * Hand a push to the messaging app, held to the person's quiet hours. Nothing answered
	 * the filter = "not used" (false), and in_app still works.
	 */
	private function deliver_push( int $user_id, string $type, string $title, array $source ): bool {
		$r = apply_filters( 'zim_queue_notice', null, $user_id, self::push_payload( $type, $title, $source ) );
		if ( null === $r ) {
			return false;   // no seam: the messaging app is absent or does not queue notices
		}
		if ( is_wp_error( $r ) ) {
			error_log( sprintf( 'Zorderz Alert Router: push [%s] for user %d not queued (%s).', $type, $user_id, $r->get_error_code() ) );
			return false;
		}
		return (int) $r > 0;
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
			error_log( 'Zorderz Alert Router: deliver_in_app DB error: ' . $wpdb->last_error );
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
			error_log( 'Zorderz Alert Router: deliver_email — no email for user ' . $user_id );
			return false;
		}

		// Build email content
		$sender      = isset( $source['sender_id'] ) ? get_userdata( $source['sender_id'] ) : null;
		$brand       = class_exists( 'ZDZ_Business_Profile' ) ? ZDZ_Business_Profile::name() : get_bloginfo( 'name' );
		$brand       = '' !== trim( (string) $brand ) ? (string) $brand : 'Zorderz';
		$sender_name = $sender ? $sender->display_name : $brand;
		$type_config = $this->types[ $type ] ?? [];
		$type_label  = $type_config['label'] ?? $type;

		$subject = sprintf( '[%s] %s', $brand, $title );

		// Plain text email body (HTML can be added later)
		$body  = sprintf( "Hi %s,\n\n", $user->display_name );
		$body .= sprintf( "%s\n\n", $message );
		$body .= "---\n";
		$body .= sprintf( "From: %s\n", $sender_name );
		$body .= sprintf( "Alert type: %s\n", $type_label );
		if ( ! empty( $source['lead_name'] ) ) {
			$body .= sprintf( "Lead: %s\n", $source['lead_name'] );
		}
		$body .= sprintf( "\nLog in to %s to take action.\n", $brand );
		$body .= home_url( '/' ) . "\n";

		$headers = [ 'Content-Type: text/plain; charset=UTF-8' ];

		$sent = wp_mail( $user->user_email, $subject, $body, $headers );

		if ( ! $sent ) {
			error_log( sprintf( 'Zorderz Alert Router: deliver_email FAILED for user %d (%s)', $user_id, $user->user_email ) );
		}

		return $sent;
	}

	// ═══════════════════════════════════════════════════════════════════
	//  REST Endpoints (for SPA consumption)
	// ═══════════════════════════════════════════════════════════════════

	/**
	 * Register REST API routes under the platform namespace.
	 */
	public function register_routes(): void {
		$ns = ZDZ_REST_NS;

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

		// The bell's "Notices you get": one on/off per alert type this person can receive.
		register_rest_route( $ns, '/notifications/prefs', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'rest_get_prefs' ],
				'permission_callback' => function() { return is_user_logged_in(); },
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_set_prefs' ],
				'permission_callback' => function() { return is_user_logged_in() && ! self::is_kiosk_user( get_current_user_id() ); },
				// Both required: a POST without "on" must never turn a type off.
				'args'                => [
					'type' => [ 'required' => true, 'type' => 'string' ],
					'on'   => [ 'required' => true, 'type' => 'boolean' ],
				],
			],
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
		if ( self::is_kiosk_user( $user_id ) ) {
			return rest_ensure_response( [ 'success' => true, 'data' => [], 'unread_count' => 0 ] );
		}
		$unread  = (bool) $request->get_param( 'unread' );
		$limit   = min( (int) $request->get_param( 'limit' ), 100 );
		$offset  = (int) $request->get_param( 'offset' );

		$notifications = self::get_notifications( $user_id, $unread, $limit, $offset );
		// The bell opens only same-site links: it gets the checked one, and the type's label.
		$types = $this->get_types();
		foreach ( $notifications as $i => $n ) {
			$meta = json_decode( (string) ( $n['source_meta'] ?? '' ), true );
			$notifications[ $i ]['link']       = self::same_site_url( is_array( $meta ) ? ( $meta['url'] ?? '' ) : '' );
			$notifications[ $i ]['type_label'] = (string) ( $types[ $n['alert_type'] ?? '' ]['label'] ?? '' );
		}
		$unread_count  = self::get_unread_count( $user_id );

		return rest_ensure_response( [
			'success'      => true,
			'data'         => $notifications,
			'unread_count' => $unread_count,
		] );
	}

	public function rest_get_unread_count( \WP_REST_Request $request ): \WP_REST_Response {
		$uid = get_current_user_id();
		return rest_ensure_response( [
			'success' => true,
			'count'   => self::is_kiosk_user( $uid ) ? 0 : self::get_unread_count( $uid ),
		] );
	}

	/** The alert types this person can receive at all (before their own choices), each with on/off. */
	public function prefs_for( int $user_id ): array {
		$prefs = get_user_meta( $user_id, 'zdz_alert_prefs', true );
		$prefs = is_array( $prefs ) ? $prefs : [];
		$out   = [];
		foreach ( $this->get_types() as $key => $t ) {
			if ( ! $this->resolve_channels( $key, $user_id, false ) ) {
				continue;   // a type this person can never get is not offered
			}
			$out[] = [
				'type'     => $key,
				'label'    => (string) ( $t['label'] ?? $key ),
				'category' => (string) ( $t['category'] ?? '' ),
				'on'       => ! ( isset( $prefs[ $key ] ) && is_array( $prefs[ $key ] ) && [] === $prefs[ $key ] ),
			];
		}
		return $out;
	}

	public function rest_get_prefs( \WP_REST_Request $request ): \WP_REST_Response {
		$uid = get_current_user_id();
		return rest_ensure_response( [ 'success' => true, 'types' => self::is_kiosk_user( $uid ) ? [] : $this->prefs_for( $uid ) ] );
	}

	/**
	 * The stored choices after turning one type off (no channel) or back on (the defaults).
	 * Only known types and known channels survive, so a hand-edited meta value never widens
	 * anything. Pure.
	 */
	public static function apply_pref( array $prefs, string $type, bool $on, array $known_types ): array {
		if ( $on ) {
			unset( $prefs[ $type ] );   // back to the defaults (the admin's and the type's)
		} else {
			$prefs[ $type ] = [];       // no channel for this type
		}
		$prefs = array_intersect_key( $prefs, array_flip( $known_types ) );
		foreach ( $prefs as $k => $chs ) {
			$prefs[ $k ] = array_values( array_intersect( (array) $chs, self::CHANNELS ) );
		}
		return $prefs;
	}

	/**
	 * POST { type, on } - turn one alert type off or back on. Only types this person can
	 * receive; never the shared device (the route's permission refuses it).
	 */
	public function rest_set_prefs( \WP_REST_Request $request ): \WP_REST_Response {
		$uid  = get_current_user_id();
		$tp   = $request->get_param( 'type' );
		$type = is_scalar( $tp ) ? sanitize_key( (string) $tp ) : '';
		$onp  = $request->get_param( 'on' );
		if ( null === $onp || ! is_scalar( $onp ) ) {
			return new \WP_REST_Response( [ 'success' => false, 'error' => 'missing_on' ], 400 );
		}
		$on      = rest_sanitize_boolean( $onp );
		$offered = wp_list_pluck( $this->prefs_for( $uid ), 'type' );
		if ( '' === $type || ! in_array( $type, $offered, true ) ) {
			return new \WP_REST_Response( [ 'success' => false, 'error' => 'unknown_type' ], 400 );
		}
		$prefs = get_user_meta( $uid, 'zdz_alert_prefs', true );
		$prefs = self::apply_pref( is_array( $prefs ) ? $prefs : [], $type, $on, array_keys( $this->get_types() ) );
		update_user_meta( $uid, 'zdz_alert_prefs', $prefs );
		return rest_ensure_response( [ 'success' => true, 'types' => $this->prefs_for( $uid ) ] );
	}

	public function rest_mark_read( \WP_REST_Request $request ): \WP_REST_Response {
		$id      = (int) $request->get_param( 'id' );
		$user_id = get_current_user_id();
		if ( self::is_kiosk_user( $user_id ) ) {
			return rest_ensure_response( [ 'success' => false, 'unread_count' => 0 ] );
		}
		$success = self::mark_read( $id, $user_id );

		return rest_ensure_response( [
			'success'      => $success,
			'unread_count' => self::get_unread_count( $user_id ),
		] );
	}

	public function rest_mark_all_read( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id = get_current_user_id();
		if ( self::is_kiosk_user( $user_id ) ) {
			return rest_ensure_response( [ 'success' => true, 'marked_count' => 0, 'unread_count' => 0 ] );
		}
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
			'role_category_grants' => self::role_category_grants(),
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
