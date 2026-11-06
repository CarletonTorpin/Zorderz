<?php
/**
 * ZSM_Findings — the findings store. Upserts the current findings, keeps the person's
 * acknowledge state, and RESOLVES by itself anything that has gone away (a component updated
 * or removed changes the finding's key, so the old row resolves).
 *
 * @package Zorderz\Security_Monitor
 */

defined( 'ABSPATH' ) || exit;

class ZSM_Findings {

	const STATUSES = array( 'open', 'acknowledged', 'resolved' );

	/**
	 * Upsert the current findings and resolve earlier open ones that were not seen — but ONLY
	 * within $auto_resolve_kinds, so a feed scan never resolves code findings it did not look at
	 * (and the other way round).
	 */
	public static function reconcile( array $current, array $auto_resolve_kinds = array( 'vuln', 'outdated', 'drift', 'supply' ) ): void {
		global $wpdb;
		$table = ZSM_DB::findings_table();
		$now   = gmdate( 'Y-m-d H:i:s' );

		$seen = array();
		foreach ( $current as $f ) {
			$key          = (string) $f['finding_key'];
			$seen[ $key ] = true;

			$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, status FROM {$table} WHERE finding_key = %s", $key ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is a fixed identifier
			$data     = array(
				'kind'              => (string) ( $f['kind'] ?? '' ),
				'plane'             => (string) ( $f['plane'] ?? '' ),
				'comp_type'         => (string) ( $f['comp_type'] ?? '' ),
				'slug'              => (string) ( $f['slug'] ?? '' ),
				'name'              => (string) ( $f['name'] ?? '' ),
				'installed_version' => (string) ( $f['installed_version'] ?? '' ),
				'fixed_version'     => (string) ( $f['fixed_version'] ?? '' ),
				'cve'               => (string) ( $f['cve'] ?? '' ),
				'title'             => (string) ( $f['title'] ?? '' ),
				'severity'          => (string) ( $f['severity'] ?? 'info' ),
				'cvss_score'        => isset( $f['cvss_score'] ) && null !== $f['cvss_score'] ? (float) $f['cvss_score'] : null,
				'context_note'      => mb_substr( (string) ( $f['context_note'] ?? '' ), 0, 255 ),
				'ref_url'           => (string) ( $f['ref_url'] ?? '' ),
				'last_seen'         => $now,
			);

			if ( $existing ) {
				// A resolved finding that comes back reopens; an acknowledgement is never overridden.
				if ( 'resolved' === $existing['status'] ) {
					$data['status'] = 'open';
				}
				$wpdb->update( $table, $data, array( 'id' => (int) $existing['id'] ) );
			} else {
				$data['finding_key'] = $key;
				$data['status']      = 'open';
				$data['first_seen']  = $now;
				$wpdb->insert( $table, $data );
			}
		}

		$kinds = array_values( array_filter( array_map( 'strval', $auto_resolve_kinds ) ) );
		if ( empty( $kinds ) ) {
			return;
		}
		$placeholders = implode( ',', array_fill( 0, count( $kinds ), '%s' ) );
		$open         = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, finding_key FROM {$table} WHERE status <> 'resolved' AND kind IN ({$placeholders})", $kinds ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders -- table + built placeholders
			ARRAY_A
		);
		if ( is_array( $open ) ) {
			foreach ( $open as $row ) {
				if ( empty( $seen[ $row['finding_key'] ] ) ) {
					$wpdb->update( $table, array( 'status' => 'resolved', 'last_seen' => $now ), array( 'id' => (int) $row['id'] ) );
				}
			}
		}
	}

	public static function set_status( int $id, string $status ): bool {
		if ( $id <= 0 || ! in_array( $status, self::STATUSES, true ) ) {
			return false;
		}
		global $wpdb;
		return (bool) $wpdb->update( ZSM_DB::findings_table(), array( 'status' => $status ), array( 'id' => $id ) );
	}

	/** @return array<int,array> worst first, then by component */
	public static function list( string $status = 'active' ): array {
		global $wpdb;
		$table = ZSM_DB::findings_table();
		if ( 'active' === $status ) {
			$rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE status <> 'resolved' ORDER BY status ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed identifier
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s", $status ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed identifier
		}
		$rows = is_array( $rows ) ? $rows : array();
		usort( $rows, array( __CLASS__, 'compare' ) );
		return $rows;
	}

	/** Sort order: worse severity first, then by component slug. */
	public static function compare( array $a, array $b ): int {
		$d = ZSM_Matcher::rank( (string) $b['severity'] ) - ZSM_Matcher::rank( (string) $a['severity'] );
		return 0 !== $d ? $d : strcmp( (string) $a['slug'], (string) $b['slug'] );
	}

	/** Counts by severity of the findings that are not resolved, and the total. */
	public static function counts(): array {
		global $wpdb;
		$table = ZSM_DB::findings_table();
		$rows  = $wpdb->get_results( "SELECT severity, COUNT(*) c FROM {$table} WHERE status <> 'resolved' GROUP BY severity", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed identifier
		$out   = array( 'critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'info' => 0, 'total' => 0 );
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				$sev = (string) $r['severity'];
				if ( isset( $out[ $sev ] ) ) {
					$out[ $sev ] = (int) $r['c'];
				}
				$out['total'] += (int) $r['c'];
			}
		}
		return $out;
	}
}
