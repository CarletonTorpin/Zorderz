<?php
/**
 * ZCC_Audit — commission calculation audit trail.
 *
 * Every calculation run is stored permanently with its full breakdown. This
 * table is the MIGRATION GATE (crosswalk CP-25): replaying it must reproduce
 * per-invoice net_commissionable and total_commission identically before and
 * after every step of a taxonomy migration. Core mechanism; no taxonomy.
 *
 * @package Zorderz\Commission
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZCC_Audit {

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'zcc_audit_log';
	}

	/** Store a calculation result. Returns the audit-log entry id. */
	public static function log_calculation( array $result, int $actor_id, int $subject_party_id, string $date_start, string $date_end ): int {
		global $wpdb;
		$summary = $result['summary'] ?? [];
		$flags   = $result['flags'] ?? [];

		$wpdb->insert( self::table_name(), [
			'user_id'            => $actor_id,
			'target_user_id'     => $subject_party_id,
			'date_range_start'   => $date_start,
			'date_range_end'     => $date_end,
			'invoice_count'      => (int) ( $summary['invoice_count'] ?? 0 ),
			'gross_billed'       => (float) ( $summary['gross_billed'] ?? 0 ),
			'total_cogs'         => (float) ( $summary['total_cogs'] ?? 0 ),
			'total_cc_fees'      => (float) ( $summary['total_cc_fees'] ?? 0 ),
			'total_discounts'    => (float) ( $summary['total_discounts'] ?? 0 ),
			'net_commissionable' => (float) ( $summary['net_commissionable'] ?? 0 ),
			'commission_rate'    => sanitize_text_field( (string) ( $summary['commission_rate'] ?? '' ) ),
			'total_commission'   => (float) ( $summary['total_commission'] ?? 0 ),
			'detail_json'        => wp_json_encode( $result['invoices'] ?? [] ),
			'flags_json'         => ! empty( $flags ) ? wp_json_encode( $flags ) : null,
		] );
		return (int) $wpdb->insert_id;
	}

	/** Generic disposition logger — used to surface loud, non-silent drops. */
	public static function log( string $event, array $context = [] ): void {
		do_action( 'zdz_flow_disposition', 'commission', $event, $context );
		error_log( 'ZCC disposition [' . $event . ']: ' . wp_json_encode( $context ) );
	}

	public static function get_entries( array $args = [] ): array {
		global $wpdb;
		$table  = self::table_name();
		$where  = [ '1=1' ];
		$params = [];
		if ( ! empty( $args['target_user_id'] ) ) {
			$where[]  = 'target_user_id = %d';
			$params[] = (int) $args['target_user_id'];
		}
		if ( ! empty( $args['user_id'] ) ) {
			$where[]  = 'user_id = %d';
			$params[] = (int) $args['user_id'];
		}
		$limit     = min( 50, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );
		$offset    = max( 0, ( (int) ( $args['page'] ?? 1 ) - 1 ) * $limit );
		$where_sql = implode( ' AND ', $where );

		$query    = "SELECT id, user_id, target_user_id, date_range_start, date_range_end, invoice_count, gross_billed, total_cogs, total_cc_fees, total_discounts, net_commissionable, commission_rate, total_commission, computed_at FROM {$table} WHERE {$where_sql} ORDER BY computed_at DESC LIMIT %d OFFSET %d";
		$params[] = $limit;
		$params[] = $offset;
		$rows     = $wpdb->get_results( $wpdb->prepare( $query, $params ), ARRAY_A );

		$total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE {$where_sql}",
			array_slice( $params, 0, -2 ) ?: [ null ]
		) );

		return [
			'items'    => $rows ?: [],
			'total'    => $total,
			'page'     => (int) ( $args['page'] ?? 1 ),
			'per_page' => $limit,
			'pages'    => max( 1, (int) ceil( $total / $limit ) ),
		];
	}

	public static function get_entry( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . self::table_name() . " WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$row['detail'] = json_decode( $row['detail_json'] ?? '[]', true );
		$row['flags']  = json_decode( $row['flags_json'] ?? '[]', true );
		return $row;
	}
}
