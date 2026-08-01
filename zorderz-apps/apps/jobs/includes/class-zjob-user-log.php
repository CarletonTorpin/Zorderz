<?php
/**
 * Zorderz Jobs — a lightweight "who is actually using the app" activity log.
 *
 * A sibling to the platform's shared audit log (ZDZ_Admin_Dashboard). Where the
 * audit log is about ACCOUNTABILITY (what changed, by whom, for security review),
 * this log is about ENGAGEMENT — which people are actually using the app, how often,
 * and what they touch. Both are written from the same call sites.
 *
 * Ships inside Jobs but is a general facility: any code may call ZJOB_User_Log::log()
 * while Jobs is active. Self-contained: it owns its table (wp_zjob_user_log), its
 * logger, and its admin view (Tools -> Activity Log). Guarded with class_exists so a
 * future theme-level owner can take the name over without a fatal; call sites also
 * guard, so logging is always best-effort.
 *
 * @package Zorderz\Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ZJOB_User_Log' ) ) :

class ZJOB_User_Log {

	const TABLE_SLUG     = 'zjob_user_log';
	const VERSION_OPTION = 'zjob_user_log_db_version';
	const DB_VERSION     = '1.0.0';
	const CAP            = 'manage_options'; // admin-only view

	/** Fully-qualified table name. */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SLUG;
	}

	/* =======================================================================
	 * SCHEMA
	 * ======================================================================= */

	public static function install(): void {
		global $wpdb;
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			action VARCHAR(64) NOT NULL DEFAULT '',
			app_id VARCHAR(64) NOT NULL DEFAULT '',
			detail VARCHAR(255) NOT NULL DEFAULT '',
			meta LONGTEXT NULL,
			ip VARCHAR(48) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY user_created (user_id, created_at),
			KEY app_action (app_id, action),
			KEY created_at (created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		update_option( self::VERSION_OPTION, self::DB_VERSION );
	}

	/** Version-gated install (covers zip-replace upgrades that skip activation). */
	public static function maybe_install(): void {
		global $wpdb;
		$table   = self::table();
		$present = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
		if ( ! $present || get_option( self::VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/* =======================================================================
	 * LOGGER
	 * ======================================================================= */

	/**
	 * Record a user action. Best-effort: never throws, never blocks the request.
	 */
	public static function log( $user_id, $action, $detail = '', $app_id = '', $meta = array() ): void {
		global $wpdb;
		$action = substr( sanitize_key( (string) $action ), 0, 64 );
		if ( $action === '' ) {
			return;
		}
		$row = array(
			'user_id'    => (int) $user_id,
			'action'     => $action,
			'app_id'     => substr( sanitize_key( (string) $app_id ), 0, 64 ),
			'detail'     => substr( sanitize_text_field( (string) $detail ), 0, 255 ),
			'meta'       => ! empty( $meta ) ? wp_json_encode( $meta ) : null,
			'ip'         => self::client_ip(),
			'created_at' => current_time( 'mysql', true ),
		);

		$ok = $wpdb->insert( self::table(), $row );
		if ( false === $ok ) {
			// Self-heal a missing table (fresh file-overwrite), then retry once.
			self::maybe_install();
			$wpdb->insert( self::table(), $row );
		}
	}

	/**
	 * Throttled "active today" signal: log $action at most once per user per calendar
	 * day (UTC), so repeated polls don't flood the log. Returns true if it logged.
	 */
	public static function log_active_daily( $user_id, $action, $app_id = '', $detail = '' ): bool {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return false;
		}
		$key = 'zjob_ul_seen_' . $user_id . '_' . sanitize_key( (string) $action ) . '_' . gmdate( 'Ymd' );
		if ( get_transient( $key ) ) {
			return false;
		}
		set_transient( $key, 1, DAY_IN_SECONDS );
		self::log( $user_id, $action, $detail, $app_id );
		return true;
	}

	private static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		return substr( (string) preg_replace( '/[^0-9a-fA-F:.]/', '', $ip ), 0, 48 );
	}

	/* =======================================================================
	 * READS (admin view)
	 * ======================================================================= */

	/** Most recent activity rows. */
	public static function recent( int $limit = 100 ): array {
		global $wpdb;
		$limit = max( 1, min( 500, $limit ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY created_at DESC, id DESC LIMIT %d', $limit ),
			ARRAY_A
		);
		return $rows ?: array();
	}

	/** Per-user rollup over the last N days: action count + last-seen. */
	public static function active_users( int $days = 30 ): array {
		global $wpdb;
		$days  = max( 1, min( 365, $days ) );
		$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT user_id, COUNT(*) AS actions, MAX(created_at) AS last_seen
				 FROM ' . self::table() . '
				 WHERE created_at >= %s
				 GROUP BY user_id
				 ORDER BY actions DESC, last_seen DESC
				 LIMIT 200',
				$since
			),
			ARRAY_A
		);
		return $rows ?: array();
	}

	/* =======================================================================
	 * ADMIN SURFACE  (Tools -> Activity Log)
	 * ======================================================================= */

	public static function admin_menu(): void {
		add_submenu_page(
			'tools.php',
			'Activity Log',
			'Activity Log',
			self::CAP,
			'zjob-activity-log',
			array( __CLASS__, 'render_admin_page' )
		);
	}

	public static function render_admin_page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Not allowed.' );
		}
		$active = self::active_users( 30 );
		$recent = self::recent( 100 );

		echo '<div class="wrap">';
		echo '<h1>Activity Log</h1>';
		echo '<p>Who is actually using the app — engagement across apps (not security; the audit log covers accountability).</p>';

		echo '<h2>Active users (last 30 days)</h2>';
		echo '<table class="widefat striped"><thead><tr><th>User</th><th>Actions</th><th>Last active</th></tr></thead><tbody>';
		if ( empty( $active ) ) {
			echo '<tr><td colspan="3">No activity recorded yet.</td></tr>';
		} else {
			foreach ( $active as $r ) {
				$u    = get_userdata( (int) $r['user_id'] );
				$name = $u ? $u->display_name : ( '#' . (int) $r['user_id'] );
				echo '<tr><td>' . esc_html( $name ) . '</td><td>' . (int) $r['actions'] . '</td><td>' . esc_html( self::fmt( $r['last_seen'] ) ) . '</td></tr>';
			}
		}
		echo '</tbody></table>';

		echo '<h2 style="margin-top:2em;">Recent activity</h2>';
		echo '<table class="widefat striped"><thead><tr><th>When</th><th>User</th><th>App</th><th>Action</th><th>Detail</th></tr></thead><tbody>';
		if ( empty( $recent ) ) {
			echo '<tr><td colspan="5">No activity recorded yet.</td></tr>';
		} else {
			foreach ( $recent as $r ) {
				$u    = get_userdata( (int) $r['user_id'] );
				$name = $u ? $u->display_name : ( '#' . (int) $r['user_id'] );
				echo '<tr>'
					. '<td>' . esc_html( self::fmt( $r['created_at'] ) ) . '</td>'
					. '<td>' . esc_html( $name ) . '</td>'
					. '<td>' . esc_html( (string) $r['app_id'] ) . '</td>'
					. '<td><code>' . esc_html( (string) $r['action'] ) . '</code></td>'
					. '<td>' . esc_html( (string) $r['detail'] ) . '</td>'
					. '</tr>';
			}
		}
		echo '</tbody></table>';
		echo '</div>';
	}

	/** Render a stored UTC datetime in the site's local time zone. */
	private static function fmt( $mysql_utc ): string {
		if ( empty( $mysql_utc ) ) {
			return '';
		}
		$ts = strtotime( $mysql_utc . ' UTC' );
		if ( ! $ts ) {
			return (string) $mysql_utc;
		}
		return get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $ts ), 'M j, Y g:i a' );
	}
}

endif;
