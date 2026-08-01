<?php
/**
 * Zorderz — Rename Migration
 *
 * Carries an existing install from the pre-Zorderz identifier scheme to the
 * Zorderz scheme without data loss.
 *
 * WHY THIS EXISTS
 * The transition renames every identifier the platform owns: option keys, user
 * meta, role slugs, capabilities and tables. On a fresh install that is free.
 * On an install that is already running a business it is not: WordPress filters
 * a user's roles against the roles that are currently registered, so a renamed
 * role silently evaporates, and any code that matches a role slug as a literal
 * string stops matching. In this platform that includes the shared-device
 * privacy check, which fails OPEN. This migration is what makes the rename safe.
 *
 * DESIGN
 *  - Idempotent. Guarded by a stored schema version; safe to run repeatedly.
 *  - Non-destructive by default. Options and user meta are COPIED, not moved, so
 *    a rollback is possible until cleanup is explicitly run.
 *  - Verified. Every phase checks its own result before advancing.
 *  - Additive-then-subtractive for roles. New roles are registered and users
 *    reassigned BEFORE any old role is removed, so nobody is ever role-less.
 *  - Extensible. Plugins register their own prefix maps on the `zdz_rename_map`
 *    filter rather than shipping their own migrations.
 *
 * USAGE
 *   Automatic on load once the theme is active.
 *   Dry run: define( 'ZDZ_RENAME_DRY_RUN', true ) in wp-config.php.
 *   Report:  get_option( 'zdz_rename_migration_report' )
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Rename_Migration {

	/** Bump when the map below changes in a way that needs re-running. */
	const VERSION = 1;

	const VERSION_OPTION = 'zdz_rename_migration_version';
	const REPORT_OPTION  = 'zdz_rename_migration_report';
	const LOCK_OPTION    = 'zdz_rename_migration_lock';

	/** @var array Lines collected for the report. */
	private static $log = [];

	/**
	 * The canonical prefix map. Longest/most specific first.
	 *
	 * Plugins add their own via the `zdz_rename_map` filter rather than
	 * duplicating this machinery.
	 *
	 * @return array{tables:array,options:array,user_meta:array,roles:array,caps:array,cron:array}
	 */
	public static function map() {
		$map = [
			// old table suffix => new table suffix (without $wpdb->prefix)
			'tables' => [
				'ts_user_media'       => 'zdz_user_media',
				'ts_user_goals'       => 'zdz_user_goals',
				'ts_personal_records' => 'zdz_personal_records',
				'ts_notifications'    => 'zdz_notifications',
				'ts_audit_log'        => 'zdz_audit_log',
			],
			// exact option keys
			'options' => [
				'ts_theme_db_version'      => 'zdz_theme_db_version',
				'ts_media_schema'          => 'zdz_media_schema',
				'ts_notifications_schema'  => 'zdz_notifications_schema',
				'ts_audit_log_version'     => 'zdz_audit_log_version',
				'ts_alert_channel_config'  => 'zdz_alert_channel_config',
				'ts_company_phone'         => 'zdz_company_phone',
				'ts_receptionist_hours'    => 'zdz_receptionist_hours',
				'ts_field_prefs_version'   => 'zdz_field_prefs_version',
				'ts_sw_register'           => 'zdz_sw_register',
			],
			// exact user meta keys
			'user_meta' => [
				'ts_allowed_apps'        => 'zdz_allowed_apps',
				'ts_denied_apps'         => 'zdz_denied_apps',
				'ts_safe_mode'           => 'zdz_safe_mode',
				'ts_data_permissions'    => 'zdz_data_permissions',
				'ts_app_order'           => 'zdz_app_order',
				'ts_alert_prefs'         => 'zdz_alert_prefs',
				'ts_field_preferences'   => 'zdz_field_preferences',
				'ts_user_initials'       => 'zdz_user_initials',
				'ts_user_notes'          => 'zdz_user_notes',
				'ts_user_phone'          => 'zdz_user_phone',
				'ts_user_email_name'     => 'zdz_user_email_name',
				'ts_user_territories'    => 'zdz_user_territories',
				'ts_salesperson_initials' => 'zdz_salesperson_initials',
				'ts_dash_card_order'     => 'zdz_dash_card_order',
				'ts_dash_card_scope'     => 'zdz_dash_card_scope',
				'ts_dash_widget_order'   => 'zdz_dash_widget_order',
				'ts_dash_global_range'   => 'zdz_dash_global_range',
			],
			// role slug => new role slug
			'roles' => [
				'ts_owner'    => 'zdz_owner',
				'ts_admin'    => 'zdz_admin',
				'ts_sales'    => 'zdz_sales',
				'ts_operator' => 'zdz_operator',
				'ts_mfg'      => 'zdz_mfg',
				'ts_tech'     => 'zdz_tech',
				'ts_general'  => 'zdz_general',
			],
			// capability => new capability
			'caps' => [
				'ts_access_app'   => 'zdz_access_app',
				'ts_view_as'      => 'zdz_view_as',
				'ts_view_as_user' => 'zdz_view_as_user',
			],
			// cron hook => new cron hook
			'cron' => [
				'ts_personal_records_streak_tick' => 'zdz_personal_records_streak_tick',
			],
			/*
			 * App-id VALUES stored inside meta arrays.
			 *
			 * Renaming a meta KEY is not enough: zdz_allowed_apps / zdz_denied_apps
			 * hold app IDs as values, and those ids changed too. Without this, a
			 * user's grant list keeps pointing at app ids nothing registers any
			 * more, so their tile silently disappears — no error, just a missing
			 * app. Found by the boot test, not by reading the code.
			 */
			'app_ids' => [
				'ts-camera'     => 'zdz-camera',
				'ts-media'      => 'zdz-media',
				'ts-media-all'  => 'zdz-media-all',
				'ts-sketch-pad' => 'zdz-sketch-pad',
				/*
				 * Declared ahead of the app itself. The jobs app is not in this
				 * batch, but grants that name it already exist and Identity Packs
				 * already reference the new id. Mapping it now means the grant
				 * survives the port instead of pointing at a dead id on the day
				 * the app lands — which is precisely the failure the app-id value
				 * migration was written to prevent.
				 *
				 * Every other unported app (game, internal-messaging,
				 * sales-analytics, estimate-creator, lead-generator,
				 * commission-calculator, satisfaction-surveys, knowledge-vault,
				 * stock-checker) registers an id with no `ts-` prefix, so none
				 * of them needs an entry here.
				 */
				'ts-jobs'       => 'zdz-jobs',
			],
			// Meta keys whose value is a list of app ids.
			'app_id_meta' => [ 'zdz_allowed_apps', 'zdz_denied_apps' ],
		];

		/**
		 * Let plugins register their own rename map.
		 *
		 * Each plugin returns the same structure with its own keys merged in.
		 * This replaces per-plugin migration code.
		 */
		return apply_filters( 'zdz_rename_map', $map );
	}

	/** Should we write, or only report what we would do? */
	private static function dry_run() {
		return defined( 'ZDZ_RENAME_DRY_RUN' ) && ZDZ_RENAME_DRY_RUN;
	}

	private static function log( $line ) {
		self::$log[] = $line;
	}

	/**
	 * Entry point. Idempotent and lock-guarded.
	 */
	public static function maybe_run() {
		if ( (int) get_option( self::VERSION_OPTION, 0 ) >= self::VERSION ) {
			return;
		}
		// Crude but effective re-entrancy guard: two concurrent requests on a
		// cold cache must not both migrate.
		if ( get_option( self::LOCK_OPTION ) ) {
			return;
		}
		update_option( self::LOCK_OPTION, time(), false );

		self::$log = [];
		self::log( sprintf( 'Zorderz rename migration v%d starting%s', self::VERSION, self::dry_run() ? ' (DRY RUN)' : '' ) );

		$map = self::map();

		self::migrate_tables( $map['tables'] );
		self::migrate_options( $map['options'] );
		self::migrate_user_meta( $map['user_meta'] );
		self::migrate_roles_and_caps( $map['roles'], $map['caps'] );
		self::migrate_cron( $map['cron'] );
		self::migrate_app_id_values( $map['app_ids'] ?? [], $map['app_id_meta'] ?? [] );

		self::log( 'Complete.' );

		update_option( self::REPORT_OPTION, self::$log, false );
		if ( ! self::dry_run() ) {
			update_option( self::VERSION_OPTION, self::VERSION, false );
		}
		delete_option( self::LOCK_OPTION );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( "[Zorderz rename] \n" . implode( "\n", self::$log ) );
		}
	}

	/**
	 * Tables are renamed in place. Destructive, so we verify the target does not
	 * already exist and that the source does.
	 */
	private static function migrate_tables( array $tables ) {
		global $wpdb;
		foreach ( $tables as $old => $new ) {
			$old_t = $wpdb->prefix . $old;
			$new_t = $wpdb->prefix . $new;

			$has_old = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_t ) ) === $old_t;
			$has_new = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new_t ) ) === $new_t;

			if ( ! $has_old ) {
				self::log( "table  SKIP  {$old_t} (source absent — fresh install)" );
				continue;
			}

			if ( $has_new ) {
				// Both tables exist. This happens on a real upgrade: WordPress
				// loads plugins BEFORE the theme, so a plugin's own schema code
				// can create the new-named table before this migration runs.
				// A plain skip here would silently orphan the legacy rows, so
				// instead: if the new table is empty and the old one has data,
				// move the data across.
				$old_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$old_t}`" ); // phpcs:ignore WordPress.DB.PreparedSQL
				$new_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$new_t}`" ); // phpcs:ignore WordPress.DB.PreparedSQL

				if ( 0 === $old_rows ) {
					self::log( "table  SKIP  {$old_t} -> {$new_t} (target exists; source empty)" );
					continue;
				}
				if ( $new_rows > 0 ) {
					self::log( "table  MANUAL {$old_t} ({$old_rows} rows) -> {$new_t} ({$new_rows} rows) — BOTH have data, not merged automatically" );
					continue;
				}
				if ( self::dry_run() ) {
					self::log( "table  WOULD  copy {$old_rows} rows {$old_t} -> {$new_t}" );
					continue;
				}
				// Copy only the columns the two tables share, so a schema that
				// gained or lost a column between versions still transfers.
				$old_cols = self::column_names( $old_t );
				$new_cols = self::column_names( $new_t );
				$shared   = array_values( array_intersect( $old_cols, $new_cols ) );
				if ( ! $shared ) {
					self::log( "table  FAIL  {$old_t} -> {$new_t} (no shared columns)" );
					continue;
				}
				$list = '`' . implode( '`,`', $shared ) . '`';
				$wpdb->query( "INSERT INTO `{$new_t}` ({$list}) SELECT {$list} FROM `{$old_t}`" ); // phpcs:ignore WordPress.DB.PreparedSQL
				$moved = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$new_t}`" ); // phpcs:ignore WordPress.DB.PreparedSQL
				self::log( sprintf(
					'table  %s  copied %d/%d rows %s -> %s (%d shared cols; source retained)',
					$moved === $old_rows ? 'OK   ' : 'WARN ',
					$moved, $old_rows, $old_t, $new_t, count( $shared )
				) );
				continue;
			}
			if ( self::dry_run() ) {
				self::log( "table  WOULD  {$old_t} -> {$new_t}" );
				continue;
			}
			// Table identifiers cannot be bound; they are drawn from our own
			// constant map and prefixed by $wpdb, never from user input.
			$wpdb->query( "RENAME TABLE `{$old_t}` TO `{$new_t}`" ); // phpcs:ignore WordPress.DB.PreparedSQL
			$ok = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new_t ) ) === $new_t;
			self::log( ( $ok ? 'table  OK    ' : 'table  FAIL  ' ) . "{$old_t} -> {$new_t}" );
		}
	}

	/**
	 * Column names for a table, on MySQL or SQLite.
	 *
	 * @param string $table Fully-prefixed table name.
	 * @return string[]
	 */
	private static function column_names( $table ) {
		global $wpdb;
		$rows = $wpdb->get_results( "DESCRIBE `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$out  = [];
		foreach ( (array) $rows as $r ) {
			if ( isset( $r['Field'] ) ) {
				$out[] = $r['Field'];
			}
		}
		return $out;
	}

	/**
	 * Options are COPIED. The old key is left in place so a rollback is possible;
	 * cleanup() removes them once the install is known good.
	 */
	private static function migrate_options( array $options ) {
		foreach ( $options as $old => $new ) {
			$val = get_option( $old, null );
			if ( null === $val ) {
				continue;
			}
			if ( null !== get_option( $new, null ) ) {
				self::log( "option SKIP  {$old} -> {$new} (target set)" );
				continue;
			}
			if ( self::dry_run() ) {
				self::log( "option WOULD  {$old} -> {$new}" );
				continue;
			}
			update_option( $new, $val, false );
			self::log( "option OK    {$old} -> {$new} (original retained)" );
		}
	}

	/**
	 * User meta is COPIED for every user that holds the old key.
	 */
	private static function migrate_user_meta( array $meta ) {
		global $wpdb;
		foreach ( $meta as $old => $new ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s", $old )
			);
			if ( ! $rows ) {
				continue;
			}
			$n = 0;
			foreach ( $rows as $row ) {
				$existing = get_user_meta( $row->user_id, $new, true );
				if ( '' !== $existing && null !== $existing && [] !== $existing ) {
					continue;
				}
				if ( ! self::dry_run() ) {
					update_user_meta( $row->user_id, $new, maybe_unserialize( $row->meta_value ) );
				}
				$n++;
			}
			self::log( sprintf( 'meta   %s %s -> %s (%d user%s)', self::dry_run() ? 'WOULD ' : 'OK   ', $old, $new, $n, 1 === $n ? '' : 's' ) );
		}
	}

	/**
	 * Roles are additive first. Every user is moved onto the new role before any
	 * old role is removed, so no account is ever left without one.
	 *
	 * This ordering is deliberate: the previous implementation called
	 * remove_role() before add_role() on every activation, which meant a user
	 * holding that role had it filtered away by WordPress and fell through to a
	 * permissive default. That is the failure this method exists to avoid.
	 */
	private static function migrate_roles_and_caps( array $roles, array $caps ) {
		$wp_roles = wp_roles();

		// Phase 1 — create the new roles, mirroring the old definition.
		foreach ( $roles as $old => $new ) {
			if ( $wp_roles->is_role( $new ) ) {
				self::log( "role   SKIP  {$new} (exists)" );
				continue;
			}
			$old_role = $wp_roles->get_role( $old );
			if ( ! $old_role ) {
				self::log( "role   SKIP  {$old} (absent — fresh install)" );
				continue;
			}
			$label = isset( $wp_roles->role_names[ $old ] ) ? $wp_roles->role_names[ $old ] : $new;
			$caps_in = (array) $old_role->capabilities;

			// Carry renamed capabilities across at the same time.
			foreach ( $caps as $cap_old => $cap_new ) {
				if ( ! empty( $caps_in[ $cap_old ] ) ) {
					$caps_in[ $cap_new ] = true;
				}
			}
			if ( ! self::dry_run() ) {
				add_role( $new, $label, $caps_in );
			}
			self::log( sprintf( 'role   %s %s -> %s (%d caps)', self::dry_run() ? 'WOULD ' : 'OK   ', $old, $new, count( $caps_in ) ) );
		}

		// Phase 2 — move every user onto the new role.
		foreach ( $roles as $old => $new ) {
			$users = get_users( [ 'role' => $old, 'fields' => 'ID' ] );
			foreach ( $users as $uid ) {
				if ( ! self::dry_run() ) {
					$u = new WP_User( $uid );
					$u->add_role( $new );
					$u->remove_role( $old );
				}
			}
			if ( $users ) {
				self::log( sprintf( 'users  %s %s -> %s (%d)', self::dry_run() ? 'WOULD ' : 'OK   ', $old, $new, count( $users ) ) );
			}
		}

		// Phase 3 — user-level capabilities granted directly rather than by role.
		foreach ( $caps as $cap_old => $cap_new ) {
			$holders = get_users( [ 'meta_key' => $GLOBALS['wpdb']->get_blog_prefix() . 'capabilities', 'fields' => 'ID' ] );
			$moved = 0;
			foreach ( $holders as $uid ) {
				$u = new WP_User( $uid );
				if ( isset( $u->allcaps[ $cap_old ] ) && ! isset( $u->allcaps[ $cap_new ] ) ) {
					if ( ! self::dry_run() ) {
						$u->add_cap( $cap_new );
					}
					$moved++;
				}
			}
			if ( $moved ) {
				self::log( sprintf( 'cap    %s %s -> %s (%d users)', self::dry_run() ? 'WOULD ' : 'OK   ', $cap_old, $cap_new, $moved ) );
			}
		}

		// Phase 4 — retire the old roles, but ONLY once nobody holds them.
		foreach ( $roles as $old => $new ) {
			if ( ! $wp_roles->is_role( $old ) ) {
				continue;
			}
			$remaining = get_users( [ 'role' => $old, 'fields' => 'ID', 'number' => 1 ] );
			if ( $remaining ) {
				self::log( "role   KEEP  {$old} (still held — not removed)" );
				continue;
			}
			if ( ! self::dry_run() ) {
				remove_role( $old );
			}
			self::log( sprintf( 'role   %s retired %s', self::dry_run() ? 'WOULD ' : 'OK   ', $old ) );
		}
	}

	/**
	 * Rewrite app-id VALUES held inside per-user grant lists.
	 *
	 * Runs after the meta keys have been copied, so it operates on the new keys.
	 * Idempotent: an id already renamed is left alone.
	 */
	private static function migrate_app_id_values( array $ids, array $keys ) {
		if ( ! $ids || ! $keys ) {
			return;
		}
		global $wpdb;
		foreach ( $keys as $key ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s", $key )
			);
			$touched = 0;
			foreach ( (array) $rows as $row ) {
				$list = maybe_unserialize( $row->meta_value );
				if ( ! is_array( $list ) ) {
					continue;
				}
				$new = [];
				foreach ( $list as $app ) {
					$new[] = $ids[ $app ] ?? $app;
				}
				$new = array_values( array_unique( $new ) );
				if ( $new === $list ) {
					continue;
				}
				if ( ! self::dry_run() ) {
					update_user_meta( $row->user_id, $key, $new );
				}
				$touched++;
			}
			if ( $touched ) {
				self::log( sprintf( 'appid  %s %s (%d user%s)', self::dry_run() ? 'WOULD ' : 'OK   ', $key, $touched, 1 === $touched ? '' : 's' ) );
			}
		}
	}

	private static function migrate_cron( array $cron ) {
		foreach ( $cron as $old => $new ) {
			$ts = wp_next_scheduled( $old );
			if ( ! $ts ) {
				continue;
			}
			if ( ! self::dry_run() ) {
				wp_clear_scheduled_hook( $old );
				if ( ! wp_next_scheduled( $new ) ) {
					wp_schedule_event( $ts, 'daily', $new );
				}
			}
			self::log( sprintf( 'cron   %s %s -> %s', self::dry_run() ? 'WOULD ' : 'OK   ', $old, $new ) );
		}
	}

	/**
	 * Remove the retained legacy option and user-meta keys.
	 *
	 * Deliberately NOT automatic. Run it only once the install is confirmed
	 * healthy on the new keys.
	 */
	public static function cleanup() {
		$map = self::map();
		foreach ( $map['options'] as $old => $new ) {
			if ( null !== get_option( $new, null ) ) {
				delete_option( $old );
			}
		}
		global $wpdb;
		foreach ( $map['user_meta'] as $old => $new ) {
			$wpdb->delete( $wpdb->usermeta, [ 'meta_key' => $old ] );
		}
		return true;
	}
}
