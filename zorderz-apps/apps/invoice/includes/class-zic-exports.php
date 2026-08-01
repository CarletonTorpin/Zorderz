<?php
/**
 * CSV export of invoices for the invoicing module.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIC_Exports {

	public static function handle_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'nope' );
		}
		check_admin_referer( 'zic_export_csv' );
		$rows = ZIC_DB::all_invoices_for_export();
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="zic-invoices-' . gmdate( 'Ymd-His' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fputcsv(
			$out,
			array( 'id', 'token', 'client_name', 'client_email', 'description', 'amount_usd', 'refunded_usd', 'currency', 'freshbooks_invoice_id', 'status', 'created_at', 'paid_at', 'pay_url' )
		);
		foreach ( (array) $rows as $r ) {
			fputcsv(
				$out,
				array(
					$r['id'],
					$r['token'],
					$r['client_name'],
					$r['client_email'],
					$r['description'],
					number_format( $r['amount_cents'] / 100, 2, '.', '' ),
					number_format( ( (int) $r['refunded_cents'] ) / 100, 2, '.', '' ),
					$r['currency'],
					$r['freshbooks_invoice_id'],
					$r['status'],
					$r['created_at'],
					$r['paid_at'],
					home_url( '/pay/' . $r['token'] ),
				)
			);
		}
		fclose( $out );
		exit;
	}
}
