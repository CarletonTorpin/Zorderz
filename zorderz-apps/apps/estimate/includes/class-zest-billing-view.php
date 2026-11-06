<?php
/**
 * ZEST_Billing_View - "My documents": the billing system's estimates and invoices a person may see, opened and changed
 * the way the billing system shows them, including ones this app did not make.
 *
 * WHO (owns()), deny by default:
 *   - never signed out, never the shared device;
 *   - the admin tier: any document;
 *   - anyone else, only their own, and only when they may make estimates (user meta zest_can_create_estimate not "0"):
 *       an estimate this app has a row for: that row's rule (ZEST_Salesperson::owned_state: its creator, a person its
 *       codes name, an administrator's grant; never after an administrator removed their access);
 *       an invoice made from such an estimate (its estimate id): the same row's rule;
 *       a document this app has no row for: the salesperson codes on its location line name exactly them (a code two
 *       accounts share names nobody).
 *   The same rule answers the messaging app's document chips for invoices (ZEST_Doc_Reader): a salesperson sees their
 *   own invoices, nobody else's.
 *
 * Endpoints (nonce, signed in, never the shared device):
 *   zest_bv_list                       this person's documents: this app's own rows (the admin tier: everyone's), and,
 *                                      when the chat app's stored ledger is here, the documents it holds under their
 *                                      codes (the admin tier: the newest of all). Every row is re-checked on opening.
 *   zest_bv_load {kind, number|id}     the document as the billing system has it now, cut of codes; prices only for
 *                                      someone who sees prices; the person's rights on it; the option map; its history
 *   zest_bv_save {kind, id, base, changes, reason, batch}
 *                                      under a per-document lock: read again, refused if it moved since `base`, every
 *                                      change checked (ZEST_Line_Edits with the option map), a priced estimate never
 *                                      taken to nothing, an invoice never below what has been paid; the history written
 *                                      FIRST (no record, no write), then the billing system, read back and compared;
 *                                      this app's copy kept in step; an "Edited by" line in the notes. A retried save
 *                                      (same batch) gets the first answer, never a second write.
 *
 * Nothing here names a customer in a log line.
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZEST_Billing_View {

	const MAX_REASON = 300;

	public static function init(): void {
		add_action( 'wp_ajax_zest_bv_list', array( __CLASS__, 'ajax_list' ) );
		add_action( 'wp_ajax_zest_bv_load', array( __CLASS__, 'ajax_load' ) );
		add_action( 'wp_ajax_zest_bv_save', array( __CLASS__, 'ajax_save' ) );
	}

	/* ================================================================ who */

	/** This app's row for the document: an estimate by its id; an invoice by the estimate it was made from. */
	public static function local_row( array $doc, string $kind ): ?array {
		if ( ! class_exists( 'ZEST_DB' ) ) {
			return null;
		}
		$id = 'estimate' === $kind ? (string) ( $doc['id'] ?? ( $doc['estimateid'] ?? '' ) ) : (string) ( $doc['estimateid'] ?? '' );
		$id = (string) preg_replace( '/\D/', '', $id );
		if ( '' === $id || '0' === $id ) {
			return null;
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ZEST_DB::estimates_table() . ' WHERE billing_doc_id = %s ORDER BY id DESC LIMIT 1', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name (ZEST_DB::estimates_table())
		return is_array( $row ) ? $row : null;
	}

	/** The people the document's location line names (exactly one account per code), by user id. */
	public static function named_people( array $doc ): array {
		if ( ! class_exists( 'ZEST_Salesperson' ) ) {
			return array();
		}
		$out = array();
		foreach ( ZEST_Salesperson::location_codes( (array) ( $doc['lines'] ?? array() ) ) as $code ) {
			$uid = ZEST_Salesperson::person_for_code( (string) $code );
			if ( $uid > 0 ) {
				$out[] = $uid;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** May this person make estimates at all? (Unset means yes; "0" means no.) */
	private static function may_make_estimates( int $uid ): bool {
		$can = get_user_meta( $uid, 'zest_can_create_estimate', true );
		return '' === $can || '1' === (string) $can || true === $can || 1 === $can;
	}

	/** Is this billing document this person's? See the header. */
	public static function owns( array $doc, string $kind, int $uid ): bool {
		if ( $uid <= 0 || ! in_array( $kind, array( 'estimate', 'invoice' ), true ) ) {
			return false;
		}
		if ( class_exists( 'ZEST_Access' ) && ZEST_Access::is_kiosk( $uid ) ) {
			return false;
		}
		if ( class_exists( 'ZEST_Salesperson' ) && ZEST_Salesperson::is_admin_tier( $uid ) ) {
			return true;
		}
		if ( ! self::may_make_estimates( $uid ) ) {
			return false;
		}
		$row = self::local_row( $doc, $kind );
		if ( $row ) {
			return class_exists( 'ZEST_Salesperson' ) && ZEST_Salesperson::owned_by( $row, $uid );
		}
		return in_array( $uid, self::named_people( $doc ), true );
	}

	/* ================================================================ the list */

	public static function ajax_list(): void {
		$uid = ZEST_Access::guard();
		ZEST_Access::no_kiosk( $uid );
		wp_send_json_success( array( 'rows' => self::list_for( $uid ) ) );
	}

	/** { kind, number, id, customer, date, status, from } rows: this app's own first, then the ledger's. */
	public static function list_for( int $uid, int $limit = 60 ): array {
		global $wpdb;
		$admin = ZEST_Access::is_admin_tier( $uid );
		$t     = ZEST_DB::estimates_table();
		if ( $admin ) {
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, billing_doc_id, billing_doc_num, customer_name, created_at, status FROM {$t} WHERE billing_doc_id <> '' ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is $wpdb->prefix plus a fixed name (ZEST_DB::estimates_table())
		} else {
			list( $mine, $args ) = ZEST_Salesperson::mine_sql( $uid );
			$args[] = $limit;
			$rows   = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, billing_doc_id, billing_doc_num, customer_name, created_at, status FROM {$t} WHERE billing_doc_id <> '' AND {$mine} ORDER BY id DESC LIMIT %d", $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is $wpdb->prefix plus a fixed name (ZEST_DB::estimates_table()); $mine is ZEST_Salesperson::mine_sql()'s fragment of placeholders and int-cast ids
		}
		$out  = array();
		$seen = array();
		foreach ( $rows as $r ) {
			$seen[ 'estimate|' . (string) $r['billing_doc_id'] ] = true;
			$out[] = array( 'kind' => 'estimate', 'number' => (string) $r['billing_doc_num'], 'id' => (string) $r['billing_doc_id'], 'customer' => (string) $r['customer_name'], 'date' => substr( (string) $r['created_at'], 0, 10 ), 'status' => (string) $r['status'], 'from' => 'app' );
		}
		foreach ( self::ledger_rows( $uid, $admin, $limit ) as $r ) {
			if ( ! isset( $seen[ $r['kind'] . '|' . $r['id'] ] ) ) {
				$out[] = $r;
			}
		}
		return array_slice( $out, 0, $limit * 2 );
	}

	/** The chat app's stored copy of the billing system, read with its own rules (guarded: nothing without it). */
	private static function ledger_rows( int $uid, bool $admin, int $limit ): array {
		if ( ! class_exists( 'ZANA_Ledger' ) || ! class_exists( 'ZANA_Ledger_Access' ) || ! method_exists( 'ZANA_Ledger', 'table' ) ) {
			return array();
		}
		try {
			$ctx   = ZANA_Ledger_Access::context( $uid );
			$all   = $admin && ZANA_Ledger_Access::may( $ctx, 'doc_all' );
			$codes = $all ? array() : ZANA_Ledger_Access::codes_for( $uid );
			if ( ! $all && ( ! $codes || ! ZANA_Ledger_Access::may( $ctx, 'doc_own' ) ) ) {
				return array();
			}
			global $wpdb;
			$out = array();
			foreach ( array( 'estimate' => array( 'estimates', 'estimate_id', 'estimate_number', 'est_date', 'status_label' ), 'invoice' => array( 'invoices', 'invoice_id', 'invoice_number', 'inv_date', 'pay_status' ) ) as $kind => $c ) {
				$table = ZANA_Ledger::table( $c[0] );
				if ( '' === $table ) {
					continue;
				}
				$sql = "SELECT {$c[1]} AS id, {$c[2]} AS num, customer_name, {$c[3]} AS d, {$c[4]} AS st, rep_code FROM {$table} WHERE vis_state <> 1";
				if ( ! $all ) {
					$ph   = implode( ',', array_fill( 0, count( $codes ), '%s' ) );
					$sql .= $wpdb->prepare( " AND rep_code IN ({$ph})", $codes ); // phpcs:ignore WordPress.DB.PreparedSQL -- placeholders only
				}
				$sql .= $wpdb->prepare( " ORDER BY {$c[3]} DESC LIMIT %d", $limit ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the column name comes from the fixed list in the loop above
				foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL -- built above from placeholders
					$out[] = array( 'kind' => $kind, 'number' => (string) $r['num'], 'id' => (string) $r['id'], 'customer' => (string) $r['customer_name'], 'date' => (string) $r['d'], 'status' => (string) $r['st'], 'from' => 'ledger' );
				}
			}
			return $out;
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	/* ================================================================ open */

	private static function kind(): string {
		return isset( $_POST['kind'] ) && 'invoice' === $_POST['kind'] ? 'invoice' : 'estimate'; // phpcs:ignore WordPress.Security.NonceVerification -- guard() checked it
	}

	private static function gate( bool $write ): int {
		$uid = ZEST_Access::guard();
		ZEST_Access::no_kiosk( $uid );
		if ( ! class_exists( 'ZEST_Billing_Doc' ) || ! ZEST_Billing_Doc::available() ) {
			wp_send_json_error( array( 'message' => __( 'The billing system is not connected.', 'zorderz' ) ) );
		}
		if ( $write && class_exists( 'ZDZ_Privacy_View' ) && ZDZ_Privacy_View::is_on( $uid ) ) {
			wp_send_json_error( array( 'message' => __( 'Changes are not made in this view.', 'zorderz' ) ), 403 );
		}
		return $uid;
	}

	public static function ajax_load(): void {
		$uid    = self::gate( false );
		$kind   = self::kind();
		$id     = isset( $_POST['id'] ) ? preg_replace( '/\D/', '', (string) wp_unslash( $_POST['id'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing -- digits only; nonce verified in self::gate() (ZEST_Access::guard()) above
		$number = isset( $_POST['number'] ) ? preg_replace( '/\D/', '', (string) wp_unslash( $_POST['number'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing -- digits only; nonce verified in self::gate() (ZEST_Access::guard()) above
		if ( '' !== $id ) {
			$doc = ZEST_Billing_Doc::read( $kind, $id );
		} elseif ( '' !== $number ) {
			$doc = ZEST_Billing_Doc::find_by_number( $kind, $number );
		} else {
			wp_send_json_error( array( 'message' => __( 'Enter the document number.', 'zorderz' ) ) );
		}
		if ( false === $doc ) {
			wp_send_json_error( array( 'message' => __( 'The billing system could not be read just now. Try again in a minute.', 'zorderz' ) ) );
		}
		if ( ! is_array( $doc ) || 1 === (int) ( $doc['vis_state'] ?? 0 ) ) {
			wp_send_json_error( array( 'message' => 'invoice' === $kind ? __( 'No such invoice. Check the number, and whether it is an estimate.', 'zorderz' ) : __( 'No such estimate. Check the number, and whether it is an invoice.', 'zorderz' ) ) );
		}
		if ( ! self::owns( $doc, $kind, $uid ) ) {
			wp_send_json_error( array( 'code' => 'not_yours', 'message' => __( 'You can only open your own estimates and invoices.', 'zorderz' ) ), 403 );
		}
		$have = isset( $_POST['have_map'] ) ? sanitize_text_field( wp_unslash( $_POST['have_map'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in self::gate() (ZEST_Access::guard()) above
		wp_send_json_success( self::payload( $doc, $kind, $uid, $have ) );
	}

	private static function cut( string $s ): string {
		return class_exists( 'ZDZ_Sealed' ) ? (string) ZDZ_Sealed::cut( $s ) : $s;
	}

	private static function amount( $v ): float {
		if ( is_array( $v ) ) {
			$v = $v['amount'] ?? 0;
		}
		return is_numeric( $v ) ? round( (float) $v, 2 ) : 0.0;
	}

	private static function sees_prices( int $uid ): bool {
		if ( class_exists( 'ZDZ_Privacy_View' ) && ZDZ_Privacy_View::is_on( $uid ) ) {
			return false;
		}
		return ! empty( ZEST_Access::field_permissions( $uid )['view_pricing'] );
	}

	private static function rights( array $doc, string $kind, int $uid ): array {
		$perms = ZEST_Access::field_permissions( $uid );
		$r     = ZEST_Line_Edits::rights( $doc, $kind, $perms, ZEST_Access::is_admin_tier( $uid ), (bool) get_option( 'zest_blind_pricing', false ) || ! self::sees_prices( $uid ) );
		$r['options'] = $r['text'];
		return $r;
	}

	/** The document as the view shows it (codes cut; amounts only for someone who sees prices). */
	public static function payload( array $doc, string $kind, int $uid, string $have_map = '' ): array {
		$lines = ZEST_Line_Edits::lines_from_doc( $doc );
		$money = self::sees_prices( $uid );
		$show  = array();
		foreach ( $lines as $l ) {
			$show[] = array( 'name' => self::cut( $l['name'] ), 'description' => self::cut( $l['description'] ), 'qty' => $l['qty'], 'rate' => $money ? $l['rate'] : null );
		}
		$id    = (string) ( $doc['id'] ?? ( $doc[ $kind . 'id' ] ?? '' ) );
		$sub   = ZEST_Line_Edits::total( $lines );
		$total = self::amount( $doc['amount'] ?? 0 );
		$row   = 'estimate' === $kind ? self::local_row( $doc, $kind ) : null;
		$out   = array(
			'kind'      => $kind,
			'id'        => $id,
			'number'    => (string) ( $doc[ $kind . '_number' ] ?? '' ),
			'row_id'    => $row ? (int) $row['id'] : 0,
			'archived'  => 0 !== (int) ( $doc['vis_state'] ?? 0 ),
			'customer'  => array(
				'name'   => trim( (string) ( $doc['fname'] ?? '' ) . ' ' . (string) ( $doc['lname'] ?? '' ) ),
				'org'    => (string) ( $doc['organization'] ?? '' ),
				'street' => self::cut( trim( (string) ( $doc['street'] ?? '' ) . ' ' . (string) ( $doc['street2'] ?? '' ) ) ),
				'city'   => (string) ( $doc['city'] ?? '' ),
				'state'  => (string) ( $doc['province'] ?? '' ),
				'zip'    => (string) ( $doc['code'] ?? '' ),
			),
			'date'      => (string) ( $doc['create_date'] ?? '' ),
			'reference' => self::cut( (string) ( $doc['po_number'] ?? '' ) ),
			'lines'     => $show,
			'notes'     => self::cut( (string) ( $doc['notes'] ?? '' ) ),
			'terms'     => self::cut( (string) ( $doc['terms'] ?? '' ) ),
			'totals'    => $money ? array(
				'subtotal'    => $sub,
				'tax'         => ( $total - $sub > 0.004 ) ? round( $total - $sub, 2 ) : 0.0,
				'total'       => $total > 0 ? $total : $sub,
				'paid'        => self::amount( $doc['paid'] ?? 0 ),
				'outstanding' => self::amount( $doc['outstanding'] ?? 0 ),
			) : null,
			'base'      => ZEST_Line_Edits::base_hash( $lines, (string) ( $doc['notes'] ?? '' ) ),
			'rights'    => self::rights( $doc, $kind, $uid ),
			'history'   => self::history( $kind, $id ),
		);
		if ( class_exists( 'ZEST_Line_Options' ) ) {
			$out += ZEST_Line_Options::for_browser( $have_map );
		}
		return $out;
	}

	/* ================================================================ save */

	public static function ajax_save(): void {
		$uid     = self::gate( true );
		$kind    = self::kind();
		$id      = isset( $_POST['id'] ) ? preg_replace( '/\D/', '', (string) wp_unslash( $_POST['id'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing -- digits only; nonce verified in self::gate() (ZEST_Access::guard()) above
		$base    = isset( $_POST['base'] ) ? preg_replace( '/[^a-f0-9]/', '', (string) wp_unslash( $_POST['base'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing -- hex only; nonce verified in self::gate() (ZEST_Access::guard()) above
		$batch   = isset( $_POST['batch'] ) ? preg_replace( '/[^a-f0-9]/', '', (string) wp_unslash( $_POST['batch'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing -- hex only; nonce verified in self::gate() (ZEST_Access::guard()) above
		$reason  = ZEST_Line_Edits::clean_text( isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '', self::MAX_REASON ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in self::gate() (ZEST_Access::guard()) above
		$changes = isset( $_POST['changes'] ) ? json_decode( wp_unslash( $_POST['changes'] ), true ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing -- JSON, each value checked by ZEST_Line_Edits::apply(); nonce verified in self::gate() (ZEST_Access::guard()) above
		if ( '' === $id || '' === $base || strlen( $batch ) < 16 || ! is_array( $changes ) ) {
			wp_send_json_error( array( 'message' => __( 'The save was not understood. Open the document again and try again.', 'zorderz' ) ) );
		}
		// a double tap or a retry gets the first answer, never a second write, and only for the same save
		$sig      = hash( 'sha256', $kind . '|' . $id . '|' . $base . '|' . wp_json_encode( $changes ) . '|' . $reason );
		$done_key = 'zest_bv_done_' . md5( $uid . '|' . $batch );
		$done     = get_transient( $done_key );
		if ( is_array( $done ) ) {
			if ( ( $done['_sig'] ?? '' ) !== $sig ) {
				wp_send_json_error( array( 'message' => __( 'That save was already made with different changes. Open the document again and make the change again.', 'zorderz' ) ) );
			}
			unset( $done['_sig'] );
			$done['already'] = true;
			wp_send_json_success( $done );
		}
		global $wpdb;
		$lock = 'zest_bv_' . $kind . '_' . $id;
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Someone is saving this document right now. Try again in a moment.', 'zorderz' ) ) );
		}
		try {
			$out = self::save_locked( $kind, $id, $base, $batch, $reason, $changes, $uid );
			if ( is_array( $out ) && empty( $out['moved'] ) ) {
				set_transient( $done_key, $out + array( '_sig' => $sig ), 6 * HOUR_IN_SECONDS );
			}
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
		if ( is_string( $out ) ) {
			wp_send_json_error( array( 'message' => $out ) );
		}
		if ( ! empty( $out['moved'] ) ) {
			wp_send_json_error( $out );
		}
		wp_send_json_success( $out );
	}

	/**
	 * The save, under the per-document lock: the success payload, a refusal, or { moved, message, doc } when the document
	 * changed since the person opened it.
	 *
	 * @return array|string
	 */
	public static function save_locked( string $kind, string $id, string $base, string $batch, string $reason, array $changes, int $uid ) {
		$doc = ZEST_Billing_Doc::read( $kind, $id );
		if ( ! is_array( $doc ) ) {
			return __( 'The billing system could not be read just now, so nothing was saved. Try again in a minute.', 'zorderz' );
		}
		if ( 0 !== (int) ( $doc['vis_state'] ?? 0 ) ) {
			return __( 'That document is archived or deleted in the billing system, so it cannot be changed here.', 'zorderz' );
		}
		if ( ! self::owns( $doc, $kind, $uid ) ) {
			return __( 'You can only change your own estimates and invoices.', 'zorderz' );
		}
		$lines = ZEST_Line_Edits::lines_from_doc( $doc );
		$notes = (string) ( $doc['notes'] ?? '' );
		if ( ! hash_equals( ZEST_Line_Edits::base_hash( $lines, $notes ), $base ) ) {
			return array(
				'moved'   => true,
				'message' => __( 'This document changed in the billing system since you opened it, so nothing was saved. Here it is as it is now: make your change again.', 'zorderz' ),
				'doc'     => self::payload( $doc, $kind, $uid ),
			);
		}
		$map = class_exists( 'ZEST_Line_Options' ) ? ZEST_Line_Options::get() : null;
		$res = ZEST_Line_Edits::apply( $lines, $changes, self::rights( $doc, $kind, $uid ), $map );
		if ( empty( $res['ok'] ) ) {
			return (string) $res['error'];
		}
		$old_total = ZEST_Line_Edits::total( $lines );
		$new_total = ZEST_Line_Edits::total( $res['lines'] );
		$no        = self::money_refusal( $kind, $doc, $old_total, $new_total );
		if ( '' !== $no ) {
			return $no;
		}
		$initials  = class_exists( 'ZEST_Salesperson' ) ? ZEST_Salesperson::initials_of( $uid ) : '';
		$new_notes = ZEST_Line_Edits::edited_by( $notes, $initials, function_exists( 'wp_date' ) ? (string) wp_date( 'n/j/Y' ) : gmdate( 'n/j/Y' ) );
		if ( 'estimate' === $kind && class_exists( 'ZEST_Warranty_Note' ) ) {
			$new_notes = ZEST_Warranty_Note::add( $new_notes, ZEST_Line_Edits::to_wire_lines( $res['lines'] ) );
		}
		$number = (string) ( $doc[ $kind . '_number' ] ?? $id );
		// the history first: no record, no write
		if ( ! self::log( $batch, $kind, $id, $number, $uid, $initials, $res['log'], $reason ) ) {
			return __( 'The change history could not be recorded, so nothing was saved. Tell an admin.', 'zorderz' );
		}
		$put = ZEST_Billing_Doc::put( $kind, $id, array( 'lines' => ZEST_Line_Edits::to_wire_lines( $res['lines'] ), 'notes' => $new_notes ), (string) ( $doc['create_date'] ?? '' ) );
		if ( empty( $put['ok'] ) ) {
			self::mark( $batch, 'failed', false );
			return (string) $put['error'];
		}
		$saved    = ZEST_Billing_Doc::read( $kind, $id );
		$mismatch = is_array( $saved ) ? ZEST_Line_Edits::verify( $res['lines'], $saved ) : 'read';
		self::mark( $batch, 'saved', '' === $mismatch );
		if ( 'estimate' === $kind ) {
			self::sync_local_row( $doc, $res['lines'], $new_notes, $uid );
		}
		error_log( 'Zorderz Estimates: billing view saved ' . $kind . ' id ' . $id . ': ' . count( $res['log'] ) . ' change(s)' . ( '' === $mismatch ? ', read back OK.' : ', read back differs (' . $mismatch . ').' ) );
		return array(
			'saved'       => true,
			'summary'     => ZEST_Line_Edits::summary( $res['log'] ),
			'verified'    => '' === $mismatch,
			'verify_note' => '' === $mismatch ? '' : __( 'The billing system saved it, but its copy reads differently. Close this and open it again to check it.', 'zorderz' ),
			'doc'         => self::payload( is_array( $saved ) ? $saved : $doc, $kind, $uid ),
		);
	}

	/**
	 * A priced estimate is never taken to nothing here; an invoice with a payment never drops below what was paid (judged
	 * on the billing system's own total, moved by what the lines moved). '' when the save may go ahead.
	 */
	public static function money_refusal( string $kind, array $doc, float $old_total, float $new_total ): string {
		if ( 'estimate' === $kind && $new_total <= 0.0 && $old_total > 0.0 ) {
			return __( 'This estimate has a price, and the save would take it to nothing. Nothing was saved.', 'zorderz' );
		}
		$paid = self::amount( $doc['paid'] ?? 0 );
		if ( 'invoice' === $kind && $paid > 0.0 && abs( $new_total - $old_total ) > 0.004 ) {
			$doc_total = self::amount( $doc['amount'] ?? 0 );
			$doc_total = $doc_total > 0.0 ? $doc_total : $old_total;
			$est_new   = round( $doc_total + ( $new_total - $old_total ), 2 );
			if ( $est_new + 0.005 < $paid ) {
				/* translators: 1: paid, 2: the new total */
				return sprintf( __( 'This invoice has %1$s paid; its total would drop to about %2$s. Nothing was saved.', 'zorderz' ), ZEST_Line_Edits::money( $paid ), ZEST_Line_Edits::money( $est_new ) );
			}
		}
		return '';
	}

	/** This app's copy of an estimate it made follows the save (so an older screen can never put back what changed). */
	private static function sync_local_row( array $doc, array $lines, string $notes, int $uid ): void {
		$row = self::local_row( $doc, 'estimate' );
		if ( ! $row ) {
			return;
		}
		$old   = json_decode( (string) ( $row['items_json'] ?? '' ), true );
		$old   = is_array( $old ) ? array_values( $old ) : array();
		$items = array();
		foreach ( $lines as $i => $l ) {
			$item                    = ( count( $old ) === count( $lines ) && is_array( $old[ $i ] ?? null ) ) ? $old[ $i ] : array();
			$item['description']     = (string) $l['name'];
			$item['sub_description'] = (string) $l['description'];
			$item['quantity']        = (float) $l['qty'];
			$item['unit_price']      = (float) $l['rate'];
			$items[]                 = $item;
		}
		global $wpdb;
		$data = array( 'items_json' => wp_json_encode( $items ), 'item_count' => count( $items ), 'notes' => $notes, 'updated_at' => current_time( 'mysql', true ), 'seal_hold' => (string) ( $row['seal_hold'] ?? '' ) );
		$data = array_intersect_key( (array) apply_filters( 'zest_row_data', $data, $uid ), $data );
		$wpdb->update( ZEST_DB::estimates_table(), $data, array( 'id' => (int) $row['id'] ) );
		if ( function_exists( 'zest_usage' ) ) {
			zest_usage( 'estimate_edited', (int) $row['id'] );
		}
	}

	/* ================================================================ history */

	/** One row per change, state 'pending' until the billing system answers. */
	public static function log( string $batch, string $kind, string $id, string $number, int $uid, string $initials, array $log, string $reason ): bool {
		if ( ! class_exists( 'ZEST_DB' ) ) {
			return false;
		}
		global $wpdb;
		$now = current_time( 'mysql', true );
		foreach ( $log as $r ) {
			$ok = $wpdb->insert( ZEST_DB::line_changes_table(), array(
				'batch'         => substr( $batch, 0, 64 ),
				'created_at'    => $now,
				'user_id'       => $uid,
				'initials'      => substr( $initials, 0, 8 ),
				'doc_kind'      => $kind,
				'billing_id'    => $id,
				'doc_number'    => substr( $number, 0, 40 ),
				'line_no'       => (int) ( $r['line_no'] ?? 0 ),
				'change_type'   => substr( (string) ( $r['type'] ?? '' ), 0, 10 ),
				'field'         => substr( (string) ( $r['field'] ?? '' ), 0, 20 ),
				'slot'          => substr( (string) ( $r['slot'] ?? '' ), 0, 40 ),
				'option_id'     => substr( (string) ( $r['option'] ?? '' ), 0, 40 ),
				'before_text'   => self::cut( (string) ( $r['before'] ?? '' ) ),
				'after_text'    => self::cut( (string) ( $r['after'] ?? '' ) ),
				'before_amount' => $r['before_amount'] ?? null,
				'after_amount'  => $r['after_amount'] ?? null,
				'reason'        => self::cut( $reason ),
				'verified'      => 0,
				'state'         => 'pending',
			) );
			if ( ! $ok ) {
				self::mark( $batch, 'failed', false );
				return false;
			}
		}
		return true;
	}

	public static function mark( string $batch, string $state, bool $verified ): void {
		global $wpdb;
		$wpdb->update( ZEST_DB::line_changes_table(), array( 'state' => $state, 'verified' => $verified ? 1 : 0 ), array( 'batch' => $batch ) );
	}

	/** The last 50 saved changes to a document, newest first. */
	public static function history( string $kind, string $id ): array {
		if ( '' === $id || ! class_exists( 'ZEST_DB' ) ) {
			return array();
		}
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT batch, created_at, user_id, initials, line_no, change_type, field, before_text, after_text, reason, verified FROM ' . ZEST_DB::line_changes_table() // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name (ZEST_DB::line_changes_table())
			. " WHERE doc_kind = %s AND billing_id = %s AND state = 'saved' ORDER BY id DESC LIMIT 50",
			$kind, $id
		), ARRAY_A );
		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			$u     = get_userdata( (int) $r['user_id'] );
			$line  = ZEST_Line_Edits::summary( array( array( 'line_no' => (int) $r['line_no'], 'type' => (string) $r['change_type'], 'field' => (string) $r['field'], 'before' => (string) $r['before_text'], 'after' => (string) $r['after_text'] ) ) );
			$out[] = array(
				'when'     => (string) $r['created_at'],
				'who'      => trim( ( $u ? (string) $u->display_name : '' ) . ( '' !== (string) $r['initials'] ? ' (' . $r['initials'] . ')' : '' ) ),
				'what'     => (string) ( $line[0] ?? '' ),
				'reason'   => (string) $r['reason'],
				'verified' => 1 === (int) $r['verified'],
			);
		}
		return $out;
	}
}
