<?php
/**
 * Zorderz Prep — persistent "save-for-future-use" offcut inventory.
 *
 * Ships EMPTY: install() creates the table only, never seeds a row. Every save-for-future
 * offcut produced by a cut plan auto-logs a row here; a later plan can consume them
 * (reserve -> commit/release) with the "Use available leftovers?" toggle.
 *
 * TABLE RENAME (real migration, recorded in schema_migrations): the legacy
 * `wp_tsemc_leftovers` table is renamed to `wp_zdz_prep_leftovers` by the platform
 * ZDZ_Rename_Migration (declared in app.php's zdz_rename_map); this class then dbDelta's
 * the neutral table so a fresh install and an upgraded install converge.
 *
 * @package Zorderz\Prep
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZPREP_Leftovers {

	/** Neutral table basename (without $wpdb->prefix). */
	const TABLE = 'zdz_prep_leftovers';

	/** Schema version. Bump to trigger maybe_upgrade(). */
	const DB_VERSION = '2.3.0';

	const RESERVATION_SECONDS = 4 * HOUR_IN_SECONDS;
	const CRON_HOOK           = 'zprep_leftovers_expire_reservations';

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/* ================================================================
	 * MIGRATION — idempotent: dbDelta + explicit column adds.
	 * ================================================================ */
	public static function migrate(): void {
		global $wpdb;
		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			source_job VARCHAR(64) NOT NULL DEFAULT '',
			material VARCHAR(16) NOT NULL DEFAULT 'black',
			roll_width_in TINYINT UNSIGNED NOT NULL DEFAULT 36,
			width_in DECIMAL(6,2) NOT NULL DEFAULT 0,
			length_in DECIMAL(6,2) NOT NULL DEFAULT 0,
			bin_location VARCHAR(32) NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'available',
			used_in_job VARCHAR(64) NULL,
			reserved_until DATETIME NULL,
			reserved_by BIGINT UNSIGNED NULL,
			notes TEXT NULL,
			PRIMARY KEY  (id),
			KEY idx_status_material (status, material),
			KEY idx_reserved_until (reserved_until)
		) {$charset};";

		dbDelta( $sql );

		// Belt-and-suspenders for pre-existing installs.
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
		if ( $cols && ! in_array( 'reserved_until', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN reserved_until DATETIME NULL" );
		}
		if ( $cols && ! in_array( 'reserved_by', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN reserved_by BIGINT UNSIGNED NULL" );
		}

		update_option( 'zprep_db_version', self::DB_VERSION, false );
	}

	/** Self-heal: run migrate() when the stored version is behind (folder-overwrite upgrade). */
	public static function maybe_upgrade(): void {
		if ( get_option( 'zprep_db_version' ) === self::DB_VERSION ) {
			return;
		}
		self::migrate();
	}

	/* ================================================================
	 * AUTO-LOG — after a cut plan, persist every save-for-future offcut.
	 * ================================================================ */
	public static function auto_log_from_plan( array $plan, string $source_job, int $user_id ): int {
		global $wpdb;
		$table         = self::table_name();
		$rows_inserted = 0;

		foreach ( $plan['pages'] ?? array() as $page ) {
			if ( empty( $page['leftover_saveable'] ) ) {
				continue;
			}

			$material      = (string) ( $page['color'] ?? 'black' );
			$roll_width_in = (int) ( $page['roll_width_in'] ?? 36 );
			$width_in      = (float) ( $page['leftover_in'] ?? 0 );
			$length_in     = (float) ( $page['sheet_length_in'] ?? $page['sheet_length'] ?? 0 );

			if ( $width_in <= 0 || $length_in <= 0 ) {
				continue;
			}

			// Dedupe: identical row for same source_job -> skip (replay protection).
			$existing = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table}
					 WHERE source_job = %s AND material = %s
					   AND ABS(width_in - %f) < 0.01 AND ABS(length_in - %f) < 0.01
					   AND status IN ('available','reserved','used') LIMIT 1",
					$source_job,
					$material,
					$width_in,
					$length_in
				)
			);
			if ( $existing ) {
				continue;
			}

			$ok = $wpdb->insert(
				$table,
				array(
					'created_at'    => current_time( 'mysql', true ),
					'created_by'    => $user_id,
					'source_job'    => $source_job,
					'material'      => $material,
					'roll_width_in' => $roll_width_in,
					'width_in'      => $width_in,
					'length_in'     => $length_in,
					'status'        => 'available',
				),
				array( '%s', '%d', '%s', '%s', '%d', '%f', '%f', '%s' )
			);
			if ( $ok ) {
				++$rows_inserted;
			}
		}

		return $rows_inserted;
	}

	/* ================================================================
	 * FIND CANDIDATES — smallest-suitable first, both orientations.
	 * ================================================================ */
	public static function find_candidates( string $material, float $piece_short, float $piece_long ): array {
		global $wpdb;
		$table = self::table_name();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE material = %s AND status = 'available'
				   AND ( (width_in >= %f AND length_in >= %f) OR (width_in >= %f AND length_in >= %f) )
				 ORDER BY (width_in * length_in) ASC, id ASC",
				$material,
				$piece_short,
				$piece_long,
				$piece_long,
				$piece_short
			),
			ARRAY_A
		);
		return $rows ?: array();
	}

	/* ================================================================
	 * RESERVE / COMMIT / RELEASE — atomic conditional updates.
	 * ================================================================ */
	public static function reserve( int $leftover_id, int $user_id ): bool {
		global $wpdb;
		$table = self::table_name();
		$until = gmdate( 'Y-m-d H:i:s', time() + self::RESERVATION_SECONDS );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status='reserved', reserved_until=%s, reserved_by=%d
				 WHERE id=%d AND status='available'",
				$until,
				$user_id,
				$leftover_id
			)
		);
		return 1 === $wpdb->rows_affected;
	}

	public static function commit_reservation( int $leftover_id, string $used_in_job ): bool {
		global $wpdb;
		$table = self::table_name();
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status='used', used_in_job=%s, reserved_until=NULL, reserved_by=NULL
				 WHERE id=%d AND status='reserved'",
				$used_in_job,
				$leftover_id
			)
		);
		return 1 === $wpdb->rows_affected;
	}

	public static function release_reservation( int $leftover_id ): bool {
		global $wpdb;
		$table = self::table_name();
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status='available', reserved_until=NULL, reserved_by=NULL
				 WHERE id=%d AND status='reserved'",
				$leftover_id
			)
		);
		return 1 === $wpdb->rows_affected;
	}

	/* ================================================================
	 * CRON — expire reservations past their window.
	 * ================================================================ */
	public static function schedule_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, 'hourly', self::CRON_HOOK );
		}
	}

	public static function unschedule_cron(): void {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}

	public static function cron_expire(): int {
		global $wpdb;
		$table = self::table_name();
		$now   = current_time( 'mysql', true );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status='available', reserved_until=NULL, reserved_by=NULL
				 WHERE status='reserved' AND reserved_until IS NOT NULL AND reserved_until < %s",
				$now
			)
		);
		return (int) $wpdb->rows_affected;
	}

	/* ================================================================
	 * LIST / EDIT (admin).
	 * ================================================================ */
	public static function list_rows( array $filters = array() ): array {
		global $wpdb;
		$table = self::table_name();

		$where = array( '1=1' );
		$args  = array();

		if ( ! empty( $filters['material'] ) ) {
			$where[] = 'material = %s';
			$args[]  = (string) $filters['material'];
		}
		if ( ! empty( $filters['status'] ) && in_array( $filters['status'], array( 'available', 'reserved', 'used', 'discarded' ), true ) ) {
			$where[] = 'status = %s';
			$args[]  = $filters['status'];
		}
		if ( ! empty( $filters['min_width'] ) ) {
			$where[] = 'width_in >= %f';
			$args[]  = (float) $filters['min_width'];
		}
		if ( ! empty( $filters['min_length'] ) ) {
			$where[] = 'length_in >= %f';
			$args[]  = (float) $filters['min_length'];
		}

		$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY created_at DESC LIMIT 500';
		if ( $args ) {
			$sql = $wpdb->prepare( $sql, ...$args );
		}
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return $rows ?: array();
	}

	public static function update_bin( int $id, string $bin ): bool {
		global $wpdb;
		$bin = substr( sanitize_text_field( $bin ), 0, 32 );
		$ok  = $wpdb->update( self::table_name(), array( 'bin_location' => $bin ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
		return false !== $ok;
	}

	public static function bulk_discard( array $ids ): int {
		global $wpdb;
		$table = self::table_name();
		$ids   = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids ) {
			return 0;
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status='discarded' WHERE id IN ({$placeholders}) AND status='available'",
				...$ids
			)
		);
		return (int) $wpdb->rows_affected;
	}

	public static function stream_csv( array $filters = array() ): void {
		$rows = self::list_rows( $filters );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=zprep-leftovers-' . gmdate( 'Ymd-His' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'id', 'created_at', 'source_job', 'material', 'roll_width_in', 'width_in', 'length_in', 'bin_location', 'status', 'used_in_job', 'notes' ) );
		foreach ( $rows as $r ) {
			fputcsv(
				$out,
				array(
					$r['id'],
					$r['created_at'],
					$r['source_job'],
					$r['material'],
					$r['roll_width_in'],
					$r['width_in'],
					$r['length_in'],
					$r['bin_location'] ?? '',
					$r['status'],
					$r['used_in_job'] ?? '',
					$r['notes'] ?? '',
				)
			);
		}
		fclose( $out );
		exit;
	}
}
