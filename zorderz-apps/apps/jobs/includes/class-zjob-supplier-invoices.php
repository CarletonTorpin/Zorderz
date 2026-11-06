<?php
/**
 * ZJOB_Supplier_Invoices — a supplier's invoices, matched to the job by the PO printed on them, and only by that.
 *
 *   - a job PO (prefix + separator + the job's invoice number) -> the job whose orders went out on it, else the job
 *     whose invoice is that number;
 *   - a shop-stock PO (prefix + STOCK + the order's number) -> that approved shop-stock order.
 * Anything else — no PO, a PO not in this business's shape, a PO no job carries, a PO two jobs carry — is FLAGGED with
 * the reason and waits for a person, who picks the job and says why. Nothing here guesses from names, amounts or dates.
 *
 * A person records invoices on the materials desk (ZJOB_Project_Orders::desk_ok): number, date, PO, amount.
 *
 * @package Zorderz\Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZJOB_Supplier_Invoices {

	const OPT_READY  = 'zjob_supplier_invoices_ready';
	const ST_MATCHED = 'matched';
	const ST_FLAGGED = 'flagged';

	public static function init(): void {
		add_action( 'wp_ajax_zjob_supinv', array( __CLASS__, 'ajax' ) );
		// No _nopriv variant.
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'zjob_supplier_invoices';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta( "CREATE TABLE {$t} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			supplier VARCHAR(64) NOT NULL DEFAULT '',
			number VARCHAR(48) NOT NULL DEFAULT '',
			inv_date DATE NULL DEFAULT NULL,
			po_raw VARCHAR(64) NOT NULL DEFAULT '',
			amount_cents BIGINT NOT NULL DEFAULT 0,
			state VARCHAR(16) NOT NULL DEFAULT 'flagged',
			why VARCHAR(191) NOT NULL DEFAULT '',
			project_id VARCHAR(40) NOT NULL DEFAULT '',
			stock_order_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			matched_how VARCHAR(8) NOT NULL DEFAULT '',
			matched_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			matched_note VARCHAR(255) NOT NULL DEFAULT '',
			matched_at DATETIME NULL DEFAULT NULL,
			created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY state (state),
			KEY project_id (project_id)
		) " . $wpdb->get_charset_collate() . ';' );
		update_option( self::OPT_READY, 1 );
	}

	public static function ready(): bool {
		return function_exists( 'get_option' ) && 1 === (int) get_option( self::OPT_READY, 0 );
	}

	/**
	 * A PO as compared: capitals and digits only, and a number without its leading zeros ("po-0010452" and "PO10452"
	 * are one PO). Only the comparison is loosened; what was printed is kept as printed. PURE.
	 */
	public static function norm( string $po ): string {
		$n = strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', $po ) );
		return preg_match( '/^([A-Z]+)0*(\d+)$/', $n, $m ) ? $m[1] . $m[2] : $n;
	}

	/**
	 * PURE: what shape a printed PO has under these settings.
	 *
	 * @return array{kind:string,digits:string,norm:string,why:string} kind none | foreign | stock | job
	 */
	public static function classify( string $po_raw, array $settings ): array {
		$n = self::norm( $po_raw );
		if ( '' === $n ) {
			return array( 'kind' => 'none', 'digits' => '', 'norm' => '', 'why' => __( 'No PO on the invoice.', 'zorderz' ) );
		}
		$prefix  = self::norm( (string) ( $settings['prefix'] ?? '' ) );
		$foreign = array( 'kind' => 'foreign', 'digits' => '', 'norm' => $n, 'why' => __( 'Not this business\'s PO shape.', 'zorderz' ) );
		if ( '' === $prefix || 0 !== strpos( $n, $prefix ) || ! preg_match( '/(\d+)$/', $n, $dm ) ) {
			return $foreign;
		}
		$digits = $dm[1];
		$stock  = self::norm( (string) $settings['prefix'] . (string) ( $settings['stock_prefix'] ?? 'STOCK' ) );
		if ( 0 === strpos( $n, $stock ) && $n === $stock . $digits ) {
			return array( 'kind' => 'stock', 'digits' => $digits, 'norm' => $n, 'why' => '' );
		}
		if ( $n !== $prefix . $digits ) {
			return $foreign;
		}
		return array( 'kind' => 'job', 'digits' => $digits, 'norm' => $n, 'why' => '' );
	}

	/**
	 * PURE: the match for a classified PO, given the jobs that carry it and whether a shop-stock order does. Exactly one
	 * job, or one stock order; otherwise flagged, with the reason.
	 *
	 * @param string[] $carriers project ids carrying this PO
	 * @return array{project_id:string,stock_order_id:int,why:string}
	 */
	public static function decide( array $c, array $carriers, int $stock_order_id = 0 ): array {
		$none = static function ( string $why ): array {
			return array( 'project_id' => '', 'stock_order_id' => 0, 'why' => $why );
		};
		if ( in_array( $c['kind'], array( 'none', 'foreign' ), true ) ) {
			return $none( (string) $c['why'] );
		}
		if ( 'stock' === $c['kind'] ) {
			return $stock_order_id > 0
				? array( 'project_id' => '', 'stock_order_id' => $stock_order_id, 'why' => '' )
				: $none( __( 'A shop-stock PO no approved stock order carries.', 'zorderz' ) );
		}
		$carriers = array_values( array_unique( array_filter( array_map( 'strval', $carriers ), 'strlen' ) ) );
		if ( 1 === count( $carriers ) ) {
			return array( 'project_id' => $carriers[0], 'stock_order_id' => 0, 'why' => '' );
		}
		return $none( $carriers ? __( 'That PO is on more than one job.', 'zorderz' ) : __( 'No job carries that PO.', 'zorderz' ) );
	}

	/** What a printed PO points at (reads the orders). */
	public static function resolve( string $po_raw ): array {
		global $wpdb;
		$c = self::classify( $po_raw, ZJOB_Project_Orders::po_settings() );
		if ( 'stock' === $c['kind'] ) {
			$o  = ZJOB_Project_Orders::get( (int) $c['digits'] );
			$ok = $o && ZJOB_Project_Orders::is_stock( $o ) && self::norm( (string) $o['po'] ) === $c['norm'];
			return self::decide( $c, array(), $ok ? (int) $o['id'] : 0 );
		}
		$carriers = array();
		if ( 'job' === $c['kind'] && ZJOB_Project_Orders::ready() ) {
			$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, project_id, po FROM ' . ZJOB_Project_Orders::table() . ' WHERE po LIKE %s AND status <> %s AND project_id <> %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name; values are placeholders.
				'%' . $wpdb->esc_like( $c['digits'] ), ZJOB_Project_Orders::ST_DISCARDED, ZJOB_Project_Orders::STOCK_PROJECT ), ARRAY_A );
			foreach ( $rows as $r ) {
				if ( self::norm( (string) $r['po'] ) !== $c['norm'] ) {
					continue;
				}
				// A warranty order carries its ORIGINAL job's PO: the invoice belongs to that job.
				$o          = ZJOB_Project_Orders::get( (int) $r['id'] );
				$orig       = $o ? ZJOB_Project_Orders::orig_project( $o ) : null;
				$carriers[] = $orig ? (string) $orig['id'] : (string) $r['project_id'];
			}
			// No order on record (placed at the counter and never entered): the job whose invoice is that number.
			if ( ! $carriers && class_exists( 'ZJOB_Project_Refs' ) ) {
				$pid = ZJOB_Project_Refs::project_for( 'invoice', ltrim( $c['digits'], '0' ) );
				$pid = '' !== $pid ? $pid : ZJOB_Project_Refs::project_for( 'invoice', $c['digits'] );
				if ( '' !== $pid && self::norm( ZJOB_Project_Orders::po_for( (array) Zjob_Project::get( $pid ) ) ) === $c['norm'] ) {
					$carriers[] = $pid;
				}
			}
		}
		return self::decide( $c, $carriers );
	}

	/** Record one invoice and match it. @return int the row id */
	public static function add( string $supplier, string $number, string $date, string $po_raw, int $amount_cents, int $uid ): int {
		global $wpdb;
		$r     = self::resolve( $po_raw );
		$state = ( '' !== $r['project_id'] || $r['stock_order_id'] > 0 ) ? self::ST_MATCHED : self::ST_FLAGGED;
		$ok    = $wpdb->insert( self::table(), array(
			'supplier'       => substr( sanitize_key( $supplier ), 0, 64 ),
			'number'         => ZJOB_Project_Orders::clean_text( $number, 48 ),
			'inv_date'       => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : null,
			'po_raw'         => ZJOB_Project_Orders::clean_text( $po_raw, 64 ),
			'amount_cents'   => max( 0, $amount_cents ),
			'state'          => $state,
			'why'            => substr( $r['why'], 0, 191 ),
			'project_id'     => $r['project_id'],
			'stock_order_id' => $r['stock_order_id'],
			'matched_how'    => self::ST_MATCHED === $state ? 'po' : '',
			'matched_at'     => self::ST_MATCHED === $state ? current_time( 'mysql', true ) : null,
			'created_by'     => $uid,
			'created_at'     => current_time( 'mysql', true ),
		) );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/** A person matches a flagged invoice to a job, with a reason. */
	public static function match_by_hand( int $id, string $project_id, string $note, int $uid ): bool {
		global $wpdb;
		$note = ZJOB_Project_Orders::clean_text( $note, 255 );
		if ( $id <= 0 || '' === $note || ! class_exists( 'Zjob_Project' ) || ! Zjob_Project::get( $project_id ) ) {
			return false;
		}
		return 1 === (int) $wpdb->update( self::table(),
			array( 'state' => self::ST_MATCHED, 'project_id' => $project_id, 'matched_how' => 'hand', 'matched_by' => $uid, 'matched_note' => $note, 'matched_at' => current_time( 'mysql', true ) ),
			array( 'id' => $id, 'state' => self::ST_FLAGGED ) );
	}

	/** The desk's list: flagged first. Amounts only for someone who may see supplier costs. */
	public static function desk_view( int $uid ): array {
		global $wpdb;
		if ( ! self::ready() ) {
			return array();
		}
		// A supplier's bill is a cost: only for whoever the price book lets see costs (the admin rung).
		$money = class_exists( 'ZJOB_Project_Orders' ) && ZJOB_Project_Orders::sees_costs( $uid );
		$rows  = (array) $wpdb->get_results( 'SELECT * FROM ' . self::table() . " ORDER BY ( state = 'flagged' ) DESC, id DESC LIMIT 100", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name; no input in the query.
		return array_map( static function ( $r ) use ( $money ) {
			return array(
				'id'         => (int) $r['id'],
				'supplier'   => (string) $r['supplier'],
				'number'     => (string) $r['number'],
				'date'       => (string) $r['inv_date'],
				'po'         => (string) $r['po_raw'],
				'state'      => (string) $r['state'],
				'why'        => (string) $r['why'],
				'project_id' => (string) $r['project_id'],
				'how'        => (string) $r['matched_how'],
				'amount'     => $money ? round( (int) $r['amount_cents'] / 100, 2 ) : null,
			);
		}, $rows );
	}

	/** add | match | list, for the materials desk only. */
	public static function ajax(): void {
		check_ajax_referer( ZJOB_NONCE, 'nonce' );
		$uid = (int) get_current_user_id();
		if ( ! class_exists( 'ZJOB_Project_Orders' ) || ! ZJOB_Project_Orders::desk_ok( $uid ) || ( class_exists( 'ZDZ_Privacy_View' ) && ZDZ_Privacy_View::is_on( $uid ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Only the materials desk can record supplier invoices.', 'zorderz' ) ), 403 );
		}
		if ( ! self::ready() ) {
			wp_send_json_error( array( 'message' => __( 'Supplier invoices are not set up yet. Reload in a moment.', 'zorderz' ) ), 503 );
		}
		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : 'list';
		$p  = static function ( string $k ): string {
			return isset( $_POST[ $k ] ) && ! is_array( $_POST[ $k ] ) ? sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- checked above.
		};
		if ( 'add' === $op ) {
			if ( ! self::add( $p( 'supplier' ), $p( 'number' ), $p( 'date' ), $p( 'po' ), (int) round( 100 * (float) $p( 'amount' ) ), $uid ) ) {
				wp_send_json_error( array( 'message' => __( 'The invoice could not be saved.', 'zorderz' ) ), 500 );
			}
		} elseif ( 'match' === $op ) {
			$pid = $p( 'project_id' );
			if ( class_exists( 'Zdz_Ulid' ) && ! Zdz_Ulid::is_valid( $pid ) && class_exists( 'ZJOB_Materials_Desk' ) ) {
				$pid = ZJOB_Materials_Desk::project_for_code( $pid ); // a typed project code
			}
			if ( '' === $pid || ! self::match_by_hand( (int) $p( 'id' ), $pid, $p( 'note' ), $uid ) ) {
				wp_send_json_error( array( 'message' => __( 'Pick the job and say why.', 'zorderz' ) ), 400 );
			}
		}
		wp_send_json_success( array( 'invoices' => self::desk_view( $uid ) ) );
	}
}
