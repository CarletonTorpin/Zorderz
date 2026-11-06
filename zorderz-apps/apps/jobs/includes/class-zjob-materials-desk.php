<?php
/**
 * ZJOB_Materials_Desk — the materials desk in the Projects hub, for the people who check and order materials.
 *
 *   shop stock      orders for the shop's own stock (no customer job): asked for with a reason, approved or declined
 *                   by an approver, then checked and ordered like any order, on their own PO
 *   supplier bills  supplier invoices matched to a job by the PO printed on them; anything else waits, with the reason,
 *                   for a person who names the job and says how they know (ZJOB_Supplier_Invoices)
 *   settings        our ship-to and bill-to as printed on supplier forms (the desk); who checks and orders materials and
 *                   how PO numbers are written (administrators)
 *
 * Who: ZJOB_Project_Orders::desk_ok() (the named operators, the approvers, administrators). Never the shared device,
 * never a Privacy View account. Supplier amounts only for whoever the price book lets see costs (the admin rung).
 *
 * @package Zorderz\Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZJOB_Materials_Desk {

	public static function init(): void {
		add_action( 'wp_ajax_zjob_materials_desk', array( __CLASS__, 'ajax' ) );
		// No _nopriv variant.
	}

	/** People who could be named to check and order materials: staff tier and up, never the shared device. */
	public static function candidates(): array {
		if ( ! function_exists( 'get_users' ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) get_users( array( 'number' => 300, 'fields' => array( 'ID', 'display_name' ) ) ) as $u ) {
			$id = (int) $u->ID;
			if ( ! ZJOB_My_Next::is_person( $id ) ) {
				continue;
			}
			$tier = class_exists( 'ZDZ_Capabilities' ) && method_exists( 'ZDZ_Capabilities', 'tier_of' ) ? (string) ZDZ_Capabilities::tier_of( $id ) : '';
			if ( in_array( $tier, array( 'staff', 'admin' ), true ) ) {
				$out[] = array( 'id' => $id, 'name' => (string) $u->display_name );
			}
		}
		return $out;
	}

	/** The one Project a typed code names ('' when none or more than one does). */
	public static function project_for_code( string $code ): string {
		global $wpdb;
		$code = strtoupper( trim( (string) preg_replace( '/[^A-Za-z0-9\-]/', '', $code ) ) );
		if ( '' === $code || ! isset( $wpdb ) || ! class_exists( 'Zdz_Flow_DB' ) ) {
			return '';
		}
		$ids = (array) $wpdb->get_col( $wpdb->prepare(
			'SELECT id FROM ' . Zdz_Flow_DB::work_items() . ' WHERE work_type = %s AND tenant_id = %d AND human_code = %s LIMIT 2', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name; values are placeholders.
			'project',
			Zdz_Flow_DB::tenant_id(),
			$code
		) );
		return 1 === count( $ids ) ? (string) $ids[0] : '';
	}

	/** Everything the desk shows this person. */
	public static function view( int $uid ): array {
		$admin = ZJOB_Project_Orders::site_admin( $uid );
		$po    = ZJOB_Project_Orders::po_settings();
		$out   = array(
			'stock'    => ZJOB_Project_Orders::ready() ? ZJOB_Project_Orders::view( ZJOB_Project_Orders::stock_project(), array(), $uid ) : null,
			'approver' => ZJOB_Project_Orders::may_approve( $uid ),
			'invoices' => class_exists( 'ZJOB_Supplier_Invoices' ) && ZJOB_Supplier_Invoices::ready() ? ZJOB_Supplier_Invoices::desk_view( $uid ) : null,
			'company'  => class_exists( 'ZJOB_Order_Paper' ) ? ZJOB_Order_Paper::company() : null,
			'admin'    => $admin,
		);
		if ( $admin ) {
			$out['operators'] = array(
				'ids'        => ZJOB_Project_Orders::operators(),
				'self_ok'    => ZJOB_Project_Orders::self_ok(),
				'approvers'  => ZJOB_Project_Orders::approvers(),
				'candidates' => self::candidates(),
			);
			$out['po'] = array(
				'prefix'       => $po['prefix'],
				'sep'          => $po['sep'],
				'stock_prefix' => $po['stock_prefix'],
				'drop_zeros'   => 'drop' === $po['zeros'],
				'example'      => ZJOB_Project_Orders::po_from( '01234', $po ),
			);
		}
		return $out;
	}

	public static function ajax(): void {
		check_ajax_referer( ZJOB_NONCE, 'nonce' );
		$uid = (int) get_current_user_id();
		if ( $uid <= 0 || ! ZJOB_My_Next::is_person( $uid ) || ( class_exists( 'ZDZ_Privacy_View' ) && ZDZ_Privacy_View::is_on( $uid ) )
			|| ! ZJOB_Project_Orders::desk_ok( $uid ) ) {
			wp_send_json_error( array( 'message' => __( 'The materials desk is not open to your account.', 'zorderz' ) ), 403 );
		}
		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : 'view';
		if ( in_array( $op, array( 'operators', 'po' ), true ) && ! ZJOB_Project_Orders::site_admin( $uid ) ) {
			wp_send_json_error( array( 'message' => __( 'Only an administrator can change this.', 'zorderz' ) ), 403 );
		}
		if ( 'operators' === $op ) {
			$allow = array_column( self::candidates(), 'id' );
			$pick  = static function ( string $k ) use ( $allow ): array {
				$raw = isset( $_POST[ $k ] ) ? json_decode( wp_unslash( (string) $_POST[ $k ] ), true ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked above; the decoded ids are cast to int and kept only when they are in the candidate list.
				return array_values( array_intersect( array_map( 'intval', is_array( $raw ) ? $raw : array() ), $allow ) );
			};
			$ids = $pick( 'ids' );
			update_option( ZJOB_Project_Orders::OPT_OPERATORS, array( 'ids' => $ids, 'self_ok' => array_values( array_intersect( $pick( 'self_ok' ), $ids ) ), 'by' => $uid, 'at' => current_time( 'mysql', true ) ), false );
			$o              = get_option( ZJOB_Project_Orders::OPT_PO, array() );
			$o              = is_array( $o ) ? $o : array();
			$o['approvers'] = $pick( 'approvers' );
			update_option( ZJOB_Project_Orders::OPT_PO, $o, false );
		} elseif ( 'po' === $op ) {
			$o                 = get_option( ZJOB_Project_Orders::OPT_PO, array() );
			$o                 = is_array( $o ) ? $o : array();
			$o['prefix']       = sanitize_text_field( wp_unslash( (string) ( $_POST['prefix'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification -- checked above.
			$o['sep']          = sanitize_text_field( wp_unslash( (string) ( $_POST['sep'] ?? '-' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification -- checked above.
			$o['stock_prefix'] = sanitize_text_field( wp_unslash( (string) ( $_POST['stock_prefix'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification -- checked above.
			$o['drop_zeros']   = ! empty( $_POST['drop_zeros'] ); // phpcs:ignore WordPress.Security.NonceVerification -- checked above.
			$o['by']           = $uid;
			update_option( ZJOB_Project_Orders::OPT_PO, $o, false );
		}
		ZJOB_Project_Orders::flush_memo();
		wp_send_json_success( self::view( $uid ) );
	}
}
