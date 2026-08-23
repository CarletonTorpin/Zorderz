<?php
/**
 * Zjob_Project_Signal — the cached ranking signal for Projects, on its OWN clock.
 *
 * A Project's rank is a DERIVED number (see Zjob_Project::compute_signal): a bag of capped
 * integer terms over the project's work, appointments, photos, acceptance, CRM link, etc.
 * Deriving it on every list render would be an N+1 across three surfaces; so it is computed
 * on a budgeted 15-minute sweep and CACHED here, then read by an indexed ORDER BY.
 *
 * WHY A FACET TABLE, NOT A COLUMN ON wp_zdz_work_items:
 *   The Flow work-items table is the generic substrate — it "names nothing" and must not grow
 *   a Projects-specific ranking column. The signal is a Projects concept, so it lives in a
 *   Projects-owned side table keyed 1:1 to the project's work_item id.
 *
 * THE CLOCK INVARIANT (B4): the signal has its own `signal_at`, NEVER the work item's
 * `updated_at`/`state_since`. Writing an ordering column off a general-purpose "touched"
 * timestamp is exactly how a cache silently reorders every surface each pass. `signal_at`
 * moves only when compute_signal() actually ran.
 *
 * @package Zorderz\Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Zjob_Project_Signal' ) ) {

	class Zjob_Project_Signal {

		/** Bump when the facet schema changes. */
		const DB_VERSION        = '1.0.0';
		const DB_VERSION_OPTION = 'zjob_project_signal_db_version';

		/** @return string the fully-prefixed facet table name. */
		public static function table(): string {
			global $wpdb;
			return $wpdb->prefix . 'zjob_project_signal';
		}

		/**
		 * Self-booting schema (idempotent). Hooked on after_setup_theme + init like the other
		 * Dossier substrates, and guarded by a version option so it runs its dbDelta once.
		 */
		public static function ensure_schema(): void {
			if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
				return;
			}
			global $wpdb;
			if ( ! isset( $wpdb ) ) {
				return;
			}
			$table   = self::table();
			$charset = method_exists( $wpdb, 'get_charset_collate' ) ? $wpdb->get_charset_collate() : '';

			// project_id is a ULID (26 chars). `signal` is indexed so the Queue's ORDER BY
			// signal DESC is a range scan, not a filesort over the whole table.
			$sql = "CREATE TABLE {$table} (
				project_id VARCHAR(26) NOT NULL,
				signal INT NOT NULL DEFAULT 0,
				signal_at DATETIME NULL DEFAULT NULL,
				reasons_json LONGTEXT NULL DEFAULT NULL,
				PRIMARY KEY  (project_id),
				KEY signal (signal),
				KEY signal_at (signal_at)
			) {$charset};";

			if ( ! function_exists( 'dbDelta' ) ) {
				require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			}
			if ( function_exists( 'dbDelta' ) ) {
				dbDelta( $sql );
				update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
			}
		}

		/**
		 * The cached signal facet for one project, or null if never computed.
		 *
		 * @param string $project_id
		 * @return array{signal:int,signal_at:?string,reasons:array}|null
		 */
		public static function get( string $project_id ): ?array {
			global $wpdb;
			$project_id = trim( $project_id );
			if ( '' === $project_id || ! isset( $wpdb ) ) {
				return null;
			}
			$row = $wpdb->get_row(
				$wpdb->prepare( 'SELECT signal, signal_at, reasons_json FROM ' . self::table() . ' WHERE project_id = %s', $project_id ),
				ARRAY_A
			);
			if ( ! is_array( $row ) ) {
				return null;
			}
			$reasons = array();
			if ( ! empty( $row['reasons_json'] ) ) {
				$decoded = json_decode( (string) $row['reasons_json'], true );
				if ( is_array( $decoded ) ) {
					$reasons = $decoded;
				}
			}
			return array(
				'signal'    => (int) $row['signal'],
				'signal_at' => isset( $row['signal_at'] ) ? (string) $row['signal_at'] : null,
				'reasons'   => $reasons,
			);
		}

		/**
		 * Persist a freshly-computed signal + its reasons, stamping the signal's OWN clock.
		 * An UPSERT: one row per project. Never touches the work item.
		 *
		 * @param string $project_id
		 * @param int    $signal   the capped total.
		 * @param array  $reasons  labeled term breakdown (for signal_reasons()).
		 * @return bool
		 */
		public static function put( string $project_id, int $signal, array $reasons = array() ): bool {
			global $wpdb;
			$project_id = trim( $project_id );
			if ( '' === $project_id || ! isset( $wpdb ) ) {
				return false;
			}
			$now  = current_time( 'mysql', true );
			$json = wp_json_encode( array_values( $reasons ) );
			// INSERT ... ON DUPLICATE KEY UPDATE keeps this one indexed write atomic.
			$ok = $wpdb->query(
				$wpdb->prepare(
					'INSERT INTO ' . self::table() . ' (project_id, signal, signal_at, reasons_json) VALUES (%s, %d, %s, %s) '
					. 'ON DUPLICATE KEY UPDATE signal = VALUES(signal), signal_at = VALUES(signal_at), reasons_json = VALUES(reasons_json)',
					$project_id,
					$signal,
					$now,
					is_string( $json ) ? $json : '[]'
				)
			);
			return false !== $ok;
		}

		/**
		 * Drop a project's facet (e.g. on a hard delete). Idempotent.
		 *
		 * @param string $project_id
		 * @return void
		 */
		public static function forget( string $project_id ): void {
			global $wpdb;
			$project_id = trim( $project_id );
			if ( '' === $project_id || ! isset( $wpdb ) ) {
				return;
			}
			$wpdb->delete( self::table(), array( 'project_id' => $project_id ), array( '%s' ) );
		}
	}

	if ( function_exists( 'add_action' ) ) {
		add_action( 'after_setup_theme', array( 'Zjob_Project_Signal', 'ensure_schema' ), 7 );
		add_action( 'init', array( 'Zjob_Project_Signal', 'ensure_schema' ), 7 );
	}
}
