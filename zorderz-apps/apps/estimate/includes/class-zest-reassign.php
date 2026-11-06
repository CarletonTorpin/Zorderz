<?php
/**
 * ZEST_Reassign - hand existing $0 stubs to the salesperson Territories would give them now, previewed, with an undo.
 *
 * New stubs ask Territories as they are made (ZEST_Territories::route). This is the rest: the stubs that already exist
 * and still name only the default pick. The admin tier only, never the shared device.
 *
 *   1 start   {scope: test|real, days}   this app's own open stubs made in the window, newest first, at most 200
 *                                        -> a batch (state 'scanning')
 *   2 read    {batch}                     8 at a time: each estimate as the billing system has it NOW (by its id): the
 *                                        same customer this app recorded, open, its location line naming only a
 *                                        hand-out code -> eligible, else skipped with why
 *   3 propose {batch}                     territories.preview_route (a dry run of the LIVE rules) -> each eligible stub:
 *                                        keep, or FROM -> TO with the rule and why. State 'preview'. Nothing written.
 *   4 apply   {batch, picks[]}            4 at a time, only rows the person ticked and Territories proposed: under the
 *                                        billing view's per-document lock, refused if the estimate changed since the
 *                                        preview or Territories' rules did; the location line's code -> the new
 *                                        person's, the provenance line ("Submitted by: FROM") -> theirs, an "Edited by"
 *                                        line for whoever re-assigned it; the history first (ZEST_Billing_View::log),
 *                                        then the billing system, read back; this app's row kept in step
 *   5 finish  {batch}                     one notice per person per batch (ZDZ_Alert_Router), and zest_reassign_applied
 *                                        so Territories takes each person's turn. A batch whose page was closed is
 *                                        finished by a scheduled safety net.
 *   6 undo    {batch}                     4 at a time: each estimate back to exactly what it was, unless it changed
 *                                        since the batch (then refused, and its number shown); zest_reassign_undone
 *   batches                               the last 20 batches, for their results and Undo
 *
 * HAND-OUT CODES (from_codes()): the code a stub carries when nobody has been chosen for it yet: the code of the
 * operator who typed it (this app writes the operator's own code when Territories made no live choice), and any code
 * the business lists (option zest_reassign_from_codes; rules.yml `estimates.reassign.from_codes`). A stub whose
 * provenance line names a salesperson (not only an operator) is theirs and is never handed out.
 *
 * The billing system is written only where this app already writes codes (the location line and the provenance line).
 * The CRM lead's owner is never changed.
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZEST_Reassign {

	const ALERT     = 'estimates_assigned';
	const OPT_CODES = 'zest_reassign_from_codes';
	const CRON      = 'zest_rsg_finish_cron';
	const MAX       = 200;
	const READ_N    = 8;
	const WRITE_N   = 4;
	const FRESH_S   = 7200;
	const QUIET_S   = 600;
	const VERB      = 'territories.preview_route';

	public static function init(): void {
		add_action( 'wp_ajax_zest_rsg_start', array( __CLASS__, 'ajax_start' ) );
		add_action( 'wp_ajax_zest_rsg_read', array( __CLASS__, 'ajax_read' ) );
		add_action( 'wp_ajax_zest_rsg_propose', array( __CLASS__, 'ajax_propose' ) );
		add_action( 'wp_ajax_zest_rsg_apply', array( __CLASS__, 'ajax_apply' ) );
		add_action( 'wp_ajax_zest_rsg_finish', array( __CLASS__, 'ajax_finish' ) );
		add_action( 'wp_ajax_zest_rsg_batches', array( __CLASS__, 'ajax_batches' ) );
		add_action( 'wp_ajax_zest_rsg_undo', array( __CLASS__, 'ajax_undo' ) );
		add_action( self::CRON, array( __CLASS__, 'cron_finish' ), 10, 1 );
		add_action( 'zdz_register_alert_types', array( __CLASS__, 'alert_type' ) );
	}

	public static function alert_type( $router ): void {
		if ( is_object( $router ) && method_exists( $router, 'register_type' ) ) {
			$router->register_type( self::ALERT, array(
				'label'            => __( 'Estimates assigned to you', 'zorderz' ),
				'description'      => __( 'An admin re-assigned existing estimate stubs to you (one notice per batch, with their numbers).', 'zorderz' ),
				'default_channels' => array( 'in_app', 'push' ),
				'source_plugin'    => 'estimate-creator',
				'category'         => 'sales',
			) );
		}
	}

	/* ================================================================ pure parts */

	/** The codes a stub may be handed out from (see the header). */
	public static function from_codes( array $row ): array {
		$out = array();
		$opt = function_exists( 'get_option' ) ? get_option( self::OPT_CODES, array() ) : array();
		foreach ( is_array( $opt ) ? $opt : array() as $c ) {
			$c = strtoupper( trim( (string) $c ) );
			if ( preg_match( '/^[A-Z]{2,3}$/', $c ) ) {
				$out[] = $c;
			}
		}
		$by = (int) ( $row['created_by'] ?? 0 );
		if ( $by > 0 && class_exists( 'ZEST_Salesperson' ) && ZEST_Salesperson::is_operator_only( $by ) ) {
			$c = ZEST_Salesperson::initials_of( $by );
			if ( '' !== $c ) {
				$out[] = $c;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** The index of the single location line of these billing-shape lines, or -1. */
	public static function location_index( array $lines ): int {
		$at = array();
		foreach ( array_values( $lines ) as $k => $l ) {
			if ( is_array( $l ) && preg_match( '/^\s*(?:property\s+)?location\b/i', (string) ( $l['name'] ?? '' ) ) ) {
				$at[] = $k;
			}
		}
		return 1 === count( $at ) ? $at[0] : -1;
	}

	/**
	 * One estimate as the billing system has it now -> the item, eligible (with what the preview and the write need) or
	 * skipped with why. Fails closed: anything not positively the same open estimate of the same customer, naming only a
	 * hand-out code, is skipped. $is_test: callable( name ) => bool. $from: the hand-out codes.
	 */
	public static function snapshot( array $it, ?array $doc, array $from, ?callable $is_test = null, ?callable $holder = null ): array {
		$skip = static function ( string $why ) use ( &$it ) {
			$it['state'] = 'skip';
			$it['why']   = $why;
			return $it;
		};
		if ( ! $doc ) {
			return $skip( __( 'the billing system could not be read just now', 'zorderz' ) );
		}
		$did = (string) ( $doc['id'] ?? ( $doc['estimateid'] ?? '' ) );
		if ( $did !== (string) $it['bid'] ) {
			return $skip( __( 'the billing system answered with a different estimate', 'zorderz' ) );
		}
		$num = ltrim( (string) ( $doc['estimate_number'] ?? '' ), '#' );
		if ( '' !== $num ) {
			$it['num'] = $num;
		}
		$cust = (string) ( $doc['customerid'] ?? '' );
		if ( '' === (string) $it['cust'] || '' === $cust || $cust !== (string) $it['cust'] ) {
			return $skip( '' === (string) $it['cust'] ? __( 'this app has no customer on record for it, so it can\'t be checked', 'zorderz' ) : __( 'the customer in the billing system is not the one this app recorded', 'zorderz' ) );
		}
		if ( $is_test ) {
			$doc_test = (bool) $is_test( trim( (string) ( $doc['fname'] ?? '' ) . ' ' . (string) ( $doc['lname'] ?? '' ) ) ) || (bool) $is_test( (string) ( $doc['organization'] ?? '' ) );
			if ( $doc_test !== ! empty( $it['test'] ) ) {
				return $skip( $doc_test ? __( 'the billing system names a test customer, this app a real one', 'zorderz' ) : __( 'the billing system\'s customer is not a test customer', 'zorderz' ) );
			}
		}
		if ( 0 !== (int) ( $doc['vis_state'] ?? 0 ) ) {
			return $skip( __( 'archived or deleted in the billing system', 'zorderz' ) );
		}
		$signal = class_exists( 'ZEST_FreshBooks' ) ? ZEST_FreshBooks::status_signal( $doc ) : '';
		if ( in_array( $signal, array( 'customer_accepted', 'invoiced' ), true ) ) {
			return $skip( __( 'accepted or invoiced', 'zorderz' ) );
		}
		$lines = array_values( (array) ( $doc['lines'] ?? array() ) );
		$at    = self::location_index( $lines );
		if ( $at < 0 ) {
			return $skip( __( 'no single location line names a salesperson', 'zorderz' ) );
		}
		$codes = class_exists( 'ZEST_Salesperson' ) ? ZEST_Salesperson::location_codes( array( $lines[ $at ] ) ) : array();
		$codes = array_values( array_map( 'strtoupper', $codes ) );
		if ( 1 !== count( $codes ) || ! in_array( $codes[0], $from, true ) ) {
			/* translators: %s: codes */
			return $skip( $codes ? sprintf( __( 'already has %s', 'zorderz' ), implode( ' & ', $codes ) ) : __( 'no line names a salesperson', 'zorderz' ) );
		}
		$notes = (string) ( $doc['notes'] ?? '' );
		// a provenance line naming a salesperson (not only an operator): they made it, so it is theirs
		$sub = class_exists( 'ZEST_Salesperson' ) ? ZEST_Salesperson::provenance_codes( $notes ) : array();
		foreach ( $sub as $sc ) {
			$sc = strtoupper( (string) $sc );
			if ( $sc === $codes[0] || in_array( $sc, $from, true ) ) {
				continue;
			}
			$sid = $holder ? (int) $holder( $sc ) : ( class_exists( 'ZEST_Territories' ) ? ZEST_Territories::user_with_initials( $sc ) : 0 );
			if ( $sid > 0 && class_exists( 'ZEST_Salesperson' ) && ! ZEST_Salesperson::is_operator_only( $sid ) ) {
				/* translators: %s: a code */
				return $skip( sprintf( __( 'submitted by %s, a salesperson: theirs', 'zorderz' ), $sc ) );
			}
		}
		$work = array();
		foreach ( $lines as $k => $l ) {
			if ( $k === $at || ! is_array( $l ) ) {
				continue;   // the street says nothing about the work
			}
			$work[] = array( 'description' => mb_substr( (string) ( $l['name'] ?? '' ), 0, 300 ), 'sub_description' => mb_substr( (string) ( $l['description'] ?? '' ), 0, 500 ) );
		}
		$it['state'] = 'eligible';
		$it['from']  = $codes[0];
		$it['at']    = $at;
		$it['base']  = ZEST_Line_Edits::base_hash( ZEST_Line_Edits::lines_from_doc( $doc ), $notes );
		$it['zip']   = '' !== trim( (string) ( $doc['code'] ?? '' ) ) ? trim( (string) $doc['code'] ) : (string) ( $it['zip'] ?? '' );
		$it['ref']   = mb_substr( (string) ( $doc['po_number'] ?? '' ), 0, 120 );
		$it['work']  = array_slice( $work, 0, 60 );
		unset( $it['why'] );
		return $it;
	}

	/**
	 * Territories' answer row for one eligible item -> the item proposed (FROM -> TO) or kept, with why. $holder:
	 * callable( code ) => the one account holding it, or 0. The person Territories names must be exactly that account.
	 */
	public static function propose_one( array $it, ?array $r, callable $holder ): array {
		unset( $it['work'] );
		if ( ! is_array( $r ) ) {
			$it['state'] = 'keep';
			$it['why']   = __( 'Territories gave no answer for it.', 'zorderz' );
			return $it;
		}
		$to_id  = (int) ( $r['user_id'] ?? 0 );
		$ini    = strtoupper( trim( (string) ( $r['initials'] ?? '' ) ) );
		$change = ! empty( $r['change'] ) && 'live' === ( $r['mode'] ?? '' ) && $to_id > 0 && $ini !== $it['from'] && preg_match( '/^[A-Z]{2,3}$/', $ini );
		$why    = (string) ( $r['why'] ?? '' );
		if ( $change && (int) $holder( $ini ) !== $to_id ) {
			$change = false;
			/* translators: 1: the code Territories picks, 2: the code kept */
			$why = sprintf( __( 'Territories picks %1$s, but that code is not exactly one person here, so it keeps %2$s.', 'zorderz' ), $ini, $it['from'] );
		}
		$it['state'] = $change ? 'proposed' : 'keep';
		$it['to']    = $change ? $ini : $it['from'];
		$it['to_id'] = $change ? $to_id : 0;
		$it['kinds'] = array_values( array_filter( array_map( static function ( $x ) { return is_array( $x ) ? (string) ( $x['label'] ?? '' ) : ''; }, (array) ( $r['kinds'] ?? array() ) ), 'strlen' ) );
		$it['rule']  = array( 'id' => (string) ( $r['rule_id'] ?? '' ), 'no' => (int) ( $r['rule_no'] ?? 0 ), 'sentence' => (string) ( $r['sentence'] ?? '' ) );
		$it['why']   = $why;
		return $it;
	}

	/**
	 * The new copy for one estimate: the location line's code FROM -> TO, the provenance line naming TO, an "Edited by"
	 * line for the person re-assigning. [ lines (billing shape), notes ] or the reason it can't be written.
	 *
	 * @return array|string
	 */
	public static function rewrite( array $doc, string $from, string $to, string $to_paren, string $editor, string $date ) {
		$lines = array_values( (array) ( $doc['lines'] ?? array() ) );
		if ( self::location_index( $lines ) < 0 || ! class_exists( 'ZEST_Billing_Doc' ) ) {
			return __( 'no single location line to change', 'zorderz' );
		}
		$new = ZEST_Billing_Doc::set_location_codes( $lines, array( $to ) );
		if ( null === $new ) {
			return __( 'the location line could not be rewritten', 'zorderz' );
		}
		$notes = self::with_provenance( (string) ( $doc['notes'] ?? '' ), $from, $to, $to_paren );
		$notes = ZEST_Line_Edits::edited_by( $notes, $editor, $date );
		return array( $new, $notes );
	}

	/** The provenance line naming FROM -> the same line naming TO; other lines as they are. */
	public static function with_provenance( string $notes, string $from, string $to, string $paren = '' ): string {
		if ( ! class_exists( 'ZDZ_Doc_Conventions' ) ) {
			return $notes;
		}
		$out = array();
		foreach ( (array) preg_split( '/\r\n|\r|\n/', $notes ) as $line ) {
			$line = (string) $line;
			if ( ZDZ_Doc_Conventions::is_provenance_line( $line ) && class_exists( 'ZEST_Salesperson' )
				&& in_array( strtoupper( $from ), array_map( 'strtoupper', ZEST_Salesperson::provenance_codes( $line ) ), true ) ) {
				$new = ZDZ_Doc_Conventions::provenance_line( $to, $paren );
				if ( '' !== $new ) {
					$line = $new;
				}
			}
			$out[] = $line;
		}
		return implode( "\n", $out );
	}

	/** One notice per person: [ to => { title, message, count, test } ]. No customer: numbers and codes only. */
	public static function notices( array $items, string $by_code ): array {
		$by = array();
		foreach ( $items as $it ) {
			if ( 'applied' === ( $it['state'] ?? '' ) && (int) ( $it['to_id'] ?? 0 ) > 0 ) {
				$by[ (int) $it['to_id'] ][] = $it;
			}
		}
		$out = array();
		foreach ( $by as $to => $list ) {
			$one   = 1 === count( $list );
			$test  = (bool) array_filter( $list, static function ( $x ) { return ! empty( $x['test'] ); } );
			$nums  = array_map( static function ( $x ) { return '#' . ltrim( (string) $x['num'], '#' ); }, $list );
			$shown = implode( ', ', array_slice( $nums, 0, 30 ) ) . ( count( $nums ) > 30 ? ' ' . sprintf( __( 'and %d more', 'zorderz' ), count( $nums ) - 30 ) : '' );
			$froms = implode( ' / ', array_values( array_unique( array_map( static function ( $x ) { return (string) $x['from']; }, $list ) ) ) );
			$title = ( $test ? __( 'TEST:', 'zorderz' ) . ' ' : '' ) . ( $one ? __( 'An estimate is now yours', 'zorderz' ) : sprintf( __( '%d estimates are now yours', 'zorderz' ), count( $list ) ) );
			$msg   = $one
				/* translators: 1: number, 2: " by AB" or "", 3: the code it had */
				? sprintf( __( 'Estimate %1$s was re-assigned to you%2$s (it had %3$s). Open it in Estimates.', 'zorderz' ), $shown, '' !== $by_code ? ' ' . sprintf( __( 'by %s', 'zorderz' ), $by_code ) : '', $froms )
				/* translators: 1: numbers, 2: " by AB" or "", 3: the codes they had */
				: sprintf( __( 'Estimates %1$s were re-assigned to you%2$s (they had %3$s). Open them in Estimates.', 'zorderz' ), $shown, '' !== $by_code ? ' ' . sprintf( __( 'by %s', 'zorderz' ), $by_code ) : '', $froms );
			$out[ $to ] = array( 'title' => $title, 'message' => $msg, 'count' => count( $list ), 'initials' => (string) $list[0]['to'], 'test' => $test );
		}
		return $out;
	}

	/* ================================================================ the batch record */

	private static function load( int $id ): ?array {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ZEST_DB::reassign_table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name (ZEST_DB::reassign_table())
		if ( ! $r ) {
			return null;
		}
		foreach ( array( 'params', 'items', 'notices' ) as $k ) {
			$v       = json_decode( (string) ( $r[ $k ] ?? '' ), true );
			$r[ $k ] = is_array( $v ) ? $v : array();
		}
		$r['id']         = (int) $r['id'];
		$r['created_by'] = (int) $r['created_by'];
		$r['generation'] = (int) $r['generation'];
		return $r;
	}

	private static function save( array $b, array $fields ): bool {
		global $wpdb;
		$row = array();
		foreach ( $fields as $k => $v ) {
			$row[ $k ] = is_array( $v ) ? wp_json_encode( $v ) : $v;
		}
		return false !== $wpdb->update( ZEST_DB::reassign_table(), $row, array( 'id' => (int) $b['id'] ) );
	}

	/** Run $work holding this batch's lock, so two windows never write one batch at once. */
	private static function locked( int $id, callable $work, int $wait = 5 ) {
		global $wpdb;
		$lock = 'zest_rsg_' . $id;
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, $wait ) ) ) {
			wp_send_json_error( array( 'message' => __( 'This batch is busy in another window. Try again in a moment.', 'zorderz' ) ) );
		}
		try {
			return $work();
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/* ================================================================ who may */

	/** The admin tier, signed in as a person (never the shared device), with the billing system connected. */
	private static function gate(): int {
		$uid = ZEST_Access::guard();
		ZEST_Access::no_kiosk( $uid );
		if ( ! ZEST_Access::is_admin_tier( $uid ) ) {
			wp_send_json_error( array( 'message' => __( 'Only the admin tier can re-assign estimates.', 'zorderz' ) ), 403 );
		}
		if ( ! class_exists( 'ZEST_Billing_Doc' ) || ! ZEST_Billing_Doc::available() ) {
			wp_send_json_error( array( 'message' => __( 'The billing system is not connected.', 'zorderz' ) ) );
		}
		return $uid;
	}

	private static function batch_for( int $uid, bool $own = true ): array {
		$b = self::load( isset( $_POST['batch'] ) ? absint( wp_unslash( $_POST['batch'] ) ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification -- gate() checked it
		if ( ! $b ) {
			wp_send_json_error( array( 'message' => __( 'That batch is not there any more. Start a new preview.', 'zorderz' ) ) );
		}
		if ( $own && $b['created_by'] !== $uid ) {
			wp_send_json_error( array( 'message' => __( 'That preview was made by someone else. Make your own.', 'zorderz' ) ), 403 );
		}
		return $b;
	}

	private static function is_test( string $name ): bool {
		return class_exists( 'ZEST_Terms_Note' ) && ZEST_Terms_Note::is_test( $name );
	}

	private static function holder( string $code ): int {
		return class_exists( 'ZEST_Territories' ) ? ZEST_Territories::user_with_initials( $code ) : 0;
	}

	/** A team member who may be given work: Territories' own rule when it is here, else a staff or admin rung. */
	private static function can_be_given_work( int $uid ): bool {
		if ( function_exists( 'zter_assignable' ) ) {
			return (bool) zter_assignable( $uid );
		}
		if ( $uid <= 0 || ( class_exists( 'ZEST_Salesperson' ) && ZEST_Salesperson::is_kiosk( $uid ) ) ) {
			return false;
		}
		return class_exists( 'ZDZ_Capabilities' ) && in_array( ZDZ_Capabilities::tier_of( $uid ), array( 'staff', 'admin' ), true );
	}

	/* ================================================================ 1. start */

	public static function ajax_start(): void {
		$uid   = self::gate();
		$scope = ( isset( $_POST['scope'] ) && 'real' === $_POST['scope'] ) ? 'real' : 'test'; // phpcs:ignore WordPress.Security.NonceVerification
		$days  = max( 7, min( 365, isset( $_POST['days'] ) ? absint( $_POST['days'] ) : 90 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$rows  = (array) $wpdb->get_results( $wpdb->prepare(
			'SELECT id, billing_doc_id, billing_doc_num, customer_name, billing_cust_id, customer_zip, created_by FROM ' . ZEST_DB::estimates_table() // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name (ZEST_DB::estimates_table())
			. " WHERE created_at >= %s AND billing_doc_id <> '' AND doc_type = 'estimate' AND status IN ('created','stub','open','updated') AND accepted_at IS NULL ORDER BY id DESC LIMIT 1000",
			$since
		), ARRAY_A );
		$seen  = array();
		$items = array();
		foreach ( $rows as $r ) {
			$bid = (string) $r['billing_doc_id'];
			if ( isset( $seen[ $bid ] ) || ! ctype_digit( $bid ) ) {
				continue;   // the newest row for each billing estimate
			}
			$seen[ $bid ] = true;
			$test = self::is_test( (string) $r['customer_name'] );
			if ( ( 'test' === $scope ) !== $test ) {
				continue;
			}
			$from = self::from_codes( $r );
			if ( ! $from ) {
				continue;   // nothing marks this stub as waiting for someone
			}
			$items[] = array( 'bid' => $bid, 'row_id' => (int) $r['id'], 'num' => (string) $r['billing_doc_num'], 'cust' => (string) $r['billing_cust_id'], 'zip' => (string) $r['customer_zip'], 'test' => $test, 'from_codes' => $from, 'state' => 'unread' );
			if ( count( $items ) >= self::MAX ) {
				break;
			}
		}
		if ( ! $items ) {
			/* translators: %d: days */
			wp_send_json_success( array( 'batch' => 0, 'total' => 0, 'message' => sprintf( 'test' === $scope ? __( 'No open test stubs waiting for a salesperson were made in the last %d days.', 'zorderz' ) : __( 'No open stubs waiting for a salesperson were made in the last %d days.', 'zorderz' ), $days ) ) );
		}
		$ok = $wpdb->insert( ZEST_DB::reassign_table(), array(
			'created_at' => current_time( 'mysql', true ),
			'created_by' => $uid,
			'state'      => 'scanning',
			'params'     => wp_json_encode( array( 'scope' => $scope, 'days' => $days ) ),
			'items'      => wp_json_encode( $items ),
			'notices'    => '[]',
		) );
		if ( ! $ok ) {
			wp_send_json_error( array( 'message' => __( 'The batch could not be recorded, so nothing was looked at. Tell an admin.', 'zorderz' ) ) );
		}
		wp_send_json_success( array( 'batch' => (int) $wpdb->insert_id, 'total' => count( $items ), 'capped' => count( $items ) >= self::MAX ) );
	}

	/* ================================================================ 2. read */

	public static function ajax_read(): void {
		$uid = self::gate();
		$b   = self::batch_for( $uid );
		if ( 'scanning' !== $b['state'] ) {
			wp_send_json_error( array( 'message' => __( 'This batch has already been read. Start a new preview.', 'zorderz' ) ) );
		}
		wp_send_json_success( self::locked( $b['id'], static function () use ( $b ) {
			$b = self::load( $b['id'] );
			$n = 0;
			foreach ( $b['items'] as $k => $it ) {
				if ( 'unread' !== $it['state'] ) {
					continue;
				}
				if ( $n++ >= self::READ_N ) {
					break;
				}
				$doc               = ZEST_Billing_Doc::read( 'estimate', (string) $it['bid'] );
				$b['items'][ $k ]  = self::snapshot( $it, is_array( $doc ) ? $doc : null, (array) ( $it['from_codes'] ?? array() ), array( __CLASS__, 'is_test' ), array( __CLASS__, 'holder' ) );
			}
			$left = count( array_filter( $b['items'], static function ( $x ) { return 'unread' === $x['state']; } ) );
			self::save( $b, array( 'items' => $b['items'] ) );
			return array( 'read' => count( $b['items'] ) - $left, 'of' => count( $b['items'] ), 'done' => 0 === $left );
		} ) );
	}

	/* ================================================================ 3. propose */

	/** territories.preview_route through the registry (the signed-in admin asks). [ 'error' => … ] when it can't answer. */
	private static function ask_territories( array $stubs ): array {
		if ( ! class_exists( 'ZDZ_Capabilities' ) || ! method_exists( 'ZDZ_Capabilities', 'get' ) ) {
			return array( 'error' => __( 'Territories could not be asked: the platform\'s verb registry is missing.', 'zorderz' ) );
		}
		try {
			if ( ! ZDZ_Capabilities::get( self::VERB ) ) {
				return array( 'error' => __( 'Territories is not active on this site, so stubs can\'t be re-assigned.', 'zorderz' ) );
			}
			$a = ZDZ_Capabilities::invoke( self::VERB, array( 'stubs' => $stubs ) );
		} catch ( \Throwable $e ) {
			return array( 'error' => __( 'Territories could not answer just now.', 'zorderz' ) );
		}
		if ( ! is_array( $a ) || empty( $a['success'] ) || ! empty( $a['denied'] ) ) {
			return array( 'error' => __( 'Territories did not answer:', 'zorderz' ) . ' ' . ( is_array( $a ) ? (string) ( $a['error'] ?? ( $a['message'] ?? __( 'no reason given', 'zorderz' ) ) ) : __( 'no answer', 'zorderz' ) ) );
		}
		return $a;
	}

	public static function ajax_propose(): void {
		$uid = self::gate();
		$b   = self::batch_for( $uid );
		if ( 'scanning' !== $b['state'] ) {
			wp_send_json_success( self::view( $b ) );   // already proposed: show it again
		}
		wp_send_json_success( self::locked( $b['id'], static function () use ( $b ) {
			$b = self::load( $b['id'] );
			if ( array_filter( $b['items'], static function ( $x ) { return 'unread' === $x['state']; } ) ) {
				wp_send_json_error( array( 'message' => __( 'Some estimates are not read yet. Keep the preview open until it finishes.', 'zorderz' ) ) );
			}
			$stubs = array();
			foreach ( $b['items'] as $k => $it ) {
				if ( 'eligible' === $it['state'] ) {
					$stubs[] = array( 'ref' => (string) $k, 'zip' => (string) $it['zip'], 'reference' => (string) $it['ref'], 'lines' => (array) $it['work'], 'default_initials' => (string) $it['from'], 'test' => ! empty( $it['test'] ) );
				}
			}
			$a = self::ask_territories( $stubs );
			if ( ! empty( $a['error'] ) ) {
				wp_send_json_error( array( 'message' => $a['error'] ) );
			}
			$by = array();
			foreach ( (array) ( $a['rows'] ?? array() ) as $r ) {
				if ( is_array( $r ) ) {
					$by[ (string) ( $r['ref'] ?? '' ) ] = $r;
				}
			}
			foreach ( $b['items'] as $k => $it ) {
				if ( 'eligible' === $it['state'] ) {
					$b['items'][ $k ] = self::propose_one( $it, $by[ (string) $k ] ?? null, array( __CLASS__, 'holder' ) );
				}
			}
			$b['params']['live_rules'] = (int) ( $a['live_rules'] ?? 0 );
			self::save( $b, array( 'items' => $b['items'], 'state' => 'preview', 'generation' => (int) ( $a['generation'] ?? 0 ), 'params' => $b['params'] ) );
			return self::view( self::load( $b['id'] ) );
		} ) );
	}

	/** What the screen shows for a batch. No customer names: the number, the postal code and the work. */
	private static function view( array $b ): array {
		$rows  = array();
		$skip  = array();
		$names = array();
		foreach ( $b['items'] as $k => $it ) {
			if ( in_array( $it['state'], array( 'skip', 'unread' ), true ) ) {
				$skip[] = array( 'num' => (string) $it['num'], 'why' => (string) ( $it['why'] ?? __( 'not read', 'zorderz' ) ) );
				continue;
			}
			$to = (int) ( $it['to_id'] ?? 0 );
			if ( $to > 0 && ! isset( $names[ $to ] ) ) {
				$u            = get_userdata( $to );
				$names[ $to ] = $u ? (string) $u->display_name : '';
			}
			$rows[] = array(
				'key' => (string) $k, 'num' => (string) $it['num'], 'zip' => (string) ( $it['zip'] ?? '' ), 'kinds' => (array) ( $it['kinds'] ?? array() ),
				'from' => (string) ( $it['from'] ?? '' ), 'to' => (string) ( $it['to'] ?? '' ), 'to_name' => $to > 0 ? $names[ $to ] : '',
				'state' => (string) $it['state'], 'rule' => $it['rule'] ?? null, 'why' => (string) ( $it['why'] ?? '' ), 'test' => ! empty( $it['test'] ),
				'note' => (string) ( $it['note'] ?? '' ),
			);
		}
		$live = (int) ( $b['params']['live_rules'] ?? 0 );
		return array(
			'batch' => $b['id'], 'state' => $b['state'], 'scope' => (string) ( $b['params']['scope'] ?? 'test' ), 'live_rules' => $live,
			'summary' => ( 0 === $live && 'preview' === $b['state'] ) ? __( 'No rule is live in Territories yet: every rule is in shadow, so every stub keeps its code. Turn rules live in Territories, then preview again.', 'zorderz' ) : '',
			'rows' => $rows, 'skipped' => $skip, 'created' => (string) $b['created_at'],
		);
	}

	/* ================================================================ 4. apply */

	public static function ajax_apply(): void {
		$uid   = self::gate();
		$b     = self::batch_for( $uid );
		$picks = isset( $_POST['picks'] ) ? json_decode( wp_unslash( (string) $_POST['picks'] ), true ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing -- JSON of keys, each checked against the batch; nonce verified in self::gate() (ZEST_Access::guard()) above
		if ( ! is_array( $picks ) || ! $picks ) {
			wp_send_json_error( array( 'message' => __( 'Nothing is ticked.', 'zorderz' ) ) );
		}
		if ( count( $picks ) > self::WRITE_N ) {
			/* translators: %d: how many */
			wp_send_json_error( array( 'message' => sprintf( __( 'Send at most %d at a time.', 'zorderz' ), self::WRITE_N ) ) );
		}
		if ( ! in_array( $b['state'], array( 'preview', 'applying' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'This batch can no longer be applied. Start a new preview.', 'zorderz' ) ) );
		}
		wp_send_json_success( self::locked( $b['id'], static function () use ( $b, $picks, $uid ) {
			$b = self::load( $b['id'] );
			if ( ! in_array( $b['state'], array( 'preview', 'applying' ), true ) ) {
				wp_send_json_error( array( 'message' => __( 'This batch was finished, so nothing more is written to it. Start a new preview.', 'zorderz' ) ) );
			}
			$res = array();
			$go  = array();
			foreach ( array_values( array_unique( array_map( 'strval', $picks ) ) ) as $k ) {
				if ( ! isset( $b['items'][ $k ] ) || 'proposed' !== $b['items'][ $k ]['state'] ) {
					$res[] = array( 'key' => $k, 'ok' => false, 'why' => __( 'not proposed in this preview', 'zorderz' ) );
				} else {
					$go[] = $k;
				}
			}
			if ( ! $go ) {
				return array( 'results' => $res );
			}
			if ( 'preview' === $b['state'] && time() - strtotime( $b['created_at'] . ' UTC' ) > self::FRESH_S ) {
				wp_send_json_error( array( 'message' => __( 'This preview is more than two hours old. Preview again.', 'zorderz' ) ) );
			}
			// every request: is the preview still what Territories would say?
			$a = self::ask_territories( array() );
			if ( ! empty( $a['error'] ) ) {
				wp_send_json_error( array( 'message' => $a['error'] ) );
			}
			if ( (int) ( $a['generation'] ?? -1 ) !== (int) $b['generation'] ) {
				wp_send_json_error( array( 'message' => __( 'Territories\' rules or areas changed since this preview, so nothing more was written. Preview again.', 'zorderz' ) ) );
			}
			if ( 'preview' === $b['state'] ) {
				$b['state'] = 'applying';
				self::save( $b, array( 'state' => 'applying' ) );
				wp_schedule_single_event( time() + 900, self::CRON, array( $b['id'] ) );   // notices go even if the page is closed
			}
			foreach ( $go as $k ) {
				$res[] = array( 'key' => $k ) + self::write_one( $b, $k, $uid );
			}
			return array( 'results' => $res );
		} ) );
	}

	/** Write one re-assignment under the billing view's per-document lock. The record is saved before and after. */
	private static function write_one( array &$b, string $k, int $uid ): array {
		global $wpdb;
		$it   = $b['items'][ $k ];
		$lock = 'zest_bv_estimate_' . $it['bid'];
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) ) ) {
			return array( 'ok' => false, 'num' => $it['num'], 'why' => __( 'someone is saving it right now; tick it in a new preview', 'zorderz' ) );
		}
		try {
			$out = self::write_locked( $b, $k, $uid );
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
		$b['params']['last_write'] = time();
		self::save( $b, array( 'items' => $b['items'], 'params' => $b['params'] ) );
		return $out;
	}

	private static function write_locked( array &$b, string $k, int $uid ): array {
		$it   = $b['items'][ $k ];
		$fail = static function ( string $why, string $state = 'failed' ) use ( &$b, $k ) {
			$b['items'][ $k ]['state'] = $state;
			$b['items'][ $k ]['note']  = $why;
			return array( 'ok' => false, 'num' => $b['items'][ $k ]['num'], 'why' => $why );
		};
		$doc = ZEST_Billing_Doc::read( 'estimate', (string) $it['bid'] );
		if ( ! is_array( $doc ) ) {
			return $fail( __( 'the billing system could not be read just now; nothing was written', 'zorderz' ) );
		}
		$notes = (string) ( $doc['notes'] ?? '' );
		if ( ! hash_equals( (string) $it['base'], ZEST_Line_Edits::base_hash( ZEST_Line_Edits::lines_from_doc( $doc ), $notes ) ) ) {
			return $fail( __( 'changed in the billing system since the preview; not touched', 'zorderz' ), 'changed' );
		}
		$check = self::snapshot( $it, $doc, array( (string) $it['from'] ), array( __CLASS__, 'is_test' ), array( __CLASS__, 'holder' ) );
		if ( 'eligible' !== $check['state'] || (int) $check['at'] !== (int) $it['at'] ) {
			/* translators: %s: why */
			return $fail( sprintf( __( 'no longer eligible (%s); not touched', 'zorderz' ), (string) ( $check['why'] ?? __( 'its location line moved', 'zorderz' ) ) ), 'changed' );
		}
		$to_id = (int) $it['to_id'];
		$to    = (string) $it['to'];
		if ( self::holder( $to ) !== $to_id ) {
			/* translators: %s: a code */
			return $fail( sprintf( __( 'the code %s is no longer exactly one person; not touched', 'zorderz' ), $to ) );
		}
		if ( ! self::can_be_given_work( $to_id ) ) {
			/* translators: %s: a code */
			return $fail( sprintf( __( '%s is no longer on the team; not touched', 'zorderz' ), $to ) );
		}
		$me    = class_exists( 'ZEST_Salesperson' ) ? ZEST_Salesperson::initials_of( $uid ) : '';
		$paren = trim( (string) get_user_meta( $to_id, 'zdz_user_parenthetical', true ) );
		$new   = self::rewrite( $doc, (string) $it['from'], $to, $paren, $me, function_exists( 'wp_date' ) ? (string) wp_date( 'n/j/Y' ) : gmdate( 'n/j/Y' ) );
		if ( is_string( $new ) ) {
			return $fail( $new );
		}
		list( $lines, $new_notes ) = $new;
		$at     = (int) $it['at'];
		$before = (string) ( $doc['lines'][ $at ]['description'] ?? '' );
		$after  = (string) ( $lines[ $at ]['description'] ?? '' );
		$log    = array( array( 'line_no' => $at + 1, 'type' => 'text', 'field' => 'description', 'before' => $before, 'after' => $after, 'before_amount' => null, 'after_amount' => null ) );
		if ( trim( $new_notes ) !== trim( $notes ) ) {
			$log[] = array( 'line_no' => 0, 'type' => 'text', 'field' => 'notes', 'before' => '', 'after' => '', 'before_amount' => null, 'after_amount' => null );
		}
		/* translators: 1: from, 2: to, 3: batch */
		$reason = sprintf( __( 'Re-assigned %1$s to %2$s, batch %3$d.', 'zorderz' ), $it['from'], $to, (int) $b['id'] );
		$hb     = substr( hash( 'sha256', 'rsg|' . $b['id'] . '|' . $it['bid'] . '|apply' ), 0, 32 );
		if ( ! ZEST_Billing_View::log( $hb, 'estimate', (string) $it['bid'], (string) $it['num'], $uid, $me, $log, $reason ) ) {
			return $fail( __( 'the change history could not be recorded, so nothing was written', 'zorderz' ) );
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, salesperson FROM ' . ZEST_DB::estimates_table() . ' WHERE billing_doc_id = %s ORDER BY id DESC LIMIT 1', (string) $it['bid'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name (ZEST_DB::estimates_table())
		// the undo record goes down BEFORE the write
		$b['items'][ $k ]['state']  = 'writing';
		$b['items'][ $k ]['before'] = array( 'index' => $at, 'text' => $before, 'notes' => $notes, 'salesperson' => $row ? (string) $row['salesperson'] : null );
		$b['items'][ $k ]['after']  = array( 'hash' => ZEST_Line_Edits::base_hash( ZEST_Line_Edits::lines_from_doc( array( 'lines' => $lines ) ), $new_notes ) );
		self::save( $b, array( 'items' => $b['items'] ) );
		$put = ZEST_Billing_Doc::put( 'estimate', (string) $it['bid'], array( 'lines' => ZEST_Billing_Doc::writable_lines( $lines ), 'notes' => $new_notes ), (string) ( $doc['create_date'] ?? '' ) );
		if ( empty( $put['ok'] ) ) {
			ZEST_Billing_View::mark( $hb, 'failed', false );
			unset( $b['items'][ $k ]['before'], $b['items'][ $k ]['after'] );
			return $fail( __( 'the billing system did not accept the change; nothing was written', 'zorderz' ) );
		}
		$saved = ZEST_Billing_Doc::read( 'estimate', (string) $it['bid'] );
		$saved = is_array( $saved ) ? $saved : array( 'lines' => $lines, 'notes' => $new_notes );
		ZEST_Billing_View::mark( $hb, 'saved', true );
		if ( $row ) {
			$data = array( 'salesperson' => $to, 'notes' => $new_notes, 'updated_at' => current_time( 'mysql', true ) );
			$data = array_intersect_key( (array) apply_filters( 'zest_row_data', $data, $uid ), $data );
			$wpdb->update( ZEST_DB::estimates_table(), $data, array( 'id' => (int) $row['id'] ) );
		}
		// "after" is what the billing system holds, not what was sent: Undo compares against it
		$b['items'][ $k ]['state'] = 'applied';
		$b['items'][ $k ]['after'] = array( 'hash' => ZEST_Line_Edits::base_hash( ZEST_Line_Edits::lines_from_doc( $saved ), (string) ( $saved['notes'] ?? $new_notes ) ) );
		$b['items'][ $k ]['note']  = '';
		return array( 'ok' => true, 'num' => $it['num'], 'to' => $to );
	}

	/* ================================================================ 5. finish */

	public static function ajax_finish(): void {
		$uid = self::gate();
		$b   = self::batch_for( $uid, false );
		if ( $b['created_by'] !== $uid && ! ( 'applying' === $b['state'] && self::quiet( $b ) ) ) {
			wp_send_json_error( array( 'message' => __( 'This batch is still being written by the person who made it.', 'zorderz' ) ) );
		}
		wp_send_json_success( self::locked( $b['id'], static function () use ( $b ) {
			return self::finish( self::load( $b['id'] ) );
		} ) );
	}

	private static function quiet( array $b ): bool {
		return time() - (int) ( $b['params']['last_write'] ?? 0 ) > self::QUIET_S;
	}

	/** The safety net: a batch whose page was closed mid-way still sends its notices. */
	public static function cron_finish( $id ): void {
		global $wpdb;
		$lock  = 'zest_rsg_' . (int) $id;
		$later = static function () use ( $id ) {
			wp_schedule_single_event( time() + self::QUIET_S, self::CRON, array( (int) $id ) );
		};
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
			$later();
			return;
		}
		try {
			$b = self::load( (int) $id );
			if ( $b && 'applying' === $b['state'] ) {
				if ( self::quiet( $b ) ) {
					self::finish( $b );
				} else {
					$later();
				}
			}
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/** A write a request did not live to record ("writing"): did it land? Then applied; if it is as before, failed. */
	private static function settle( array $it ): array {
		if ( 'writing' !== ( $it['state'] ?? '' ) ) {
			return $it;
		}
		$doc = ZEST_Billing_Doc::read( 'estimate', (string) $it['bid'] );
		if ( ! is_array( $doc ) ) {
			return $it;
		}
		$h = ZEST_Line_Edits::base_hash( ZEST_Line_Edits::lines_from_doc( $doc ), (string) ( $doc['notes'] ?? '' ) );
		if ( hash_equals( (string) ( $it['after']['hash'] ?? '' ), $h ) ) {
			$it['state'] = 'applied';
			$it['note']  = __( 'the request was interrupted, but the billing system has the change', 'zorderz' );
		} elseif ( hash_equals( (string) $it['base'], $h ) ) {
			$it['state'] = 'failed';
			$it['note']  = __( 'the request was interrupted before the billing system took the change; nothing was written', 'zorderz' );
			unset( $it['before'], $it['after'] );
		} else {
			$it['state'] = 'changed';
			$it['note']  = __( 'the request was interrupted and the estimate changed since; open it to check it', 'zorderz' );
		}
		return $it;
	}

	/** Close the batch: one notice per person, then Territories takes each person's turn. Runs once. */
	private static function finish( array $b ): array {
		if ( 'applying' !== $b['state'] ) {
			return self::result( $b );
		}
		foreach ( $b['items'] as $k => $it ) {
			$b['items'][ $k ] = self::settle( $it );
		}
		$turns = array();
		foreach ( $b['items'] as $it ) {
			if ( 'applied' === $it['state'] ) {
				$turns[] = array( 'doc' => (string) $it['num'], 'rule_id' => (string) ( $it['rule']['id'] ?? '' ), 'user_id' => (int) $it['to_id'], 'initials' => (string) $it['to'], 'test' => ! empty( $it['test'] ) );
			}
		}
		$by      = (int) $b['created_by'];
		$sent    = array();
		$me      = class_exists( 'ZEST_Salesperson' ) ? ZEST_Salesperson::initials_of( $by ) : '';
		foreach ( self::notices( $b['items'], $me ) as $to => $n ) {
			$used = array();
			if ( class_exists( 'ZDZ_Alert_Router' ) && method_exists( 'ZDZ_Alert_Router', 'send' ) ) {
				try {
					$used = (array) ZDZ_Alert_Router::send( self::ALERT, (int) $to, $n['title'], $n['message'], array( 'plugin' => 'estimate-creator', 'source_id' => (string) $b['id'], 'sender_id' => $by, 'url' => home_url( '/' ) ) );
				} catch ( \Throwable $e ) {
					$used = array();
				}
			}
			$sent[] = array( 'to' => (int) $to, 'initials' => $n['initials'], 'count' => $n['count'], 'channels' => $used );
		}
		$state = $turns ? 'applied' : 'closed';
		self::save( $b, array( 'items' => $b['items'], 'state' => $state, 'notices' => $sent, 'applied_at' => current_time( 'mysql', true ), 'applied_count' => count( $turns ) ) );
		wp_clear_scheduled_hook( self::CRON, array( $b['id'] ) );
		if ( $turns ) {
			try {
				do_action( 'zest_reassign_applied', (int) $b['id'], $turns, $by );
			} catch ( \Throwable $e ) {
				error_log( 'Zorderz Estimates: a re-assign listener failed - ' . $e->getMessage() );
			}
		}
		return self::result( self::load( $b['id'] ) );
	}

	private static function result( array $b ): array {
		$count = array();
		$list  = array();
		foreach ( $b['items'] as $k => $it ) {
			if ( in_array( $it['state'], array( 'applied', 'writing', 'undone', 'undo_failed', 'changed', 'failed', 'refused' ), true ) ) {
				$count[ $it['state'] ] = ( $count[ $it['state'] ] ?? 0 ) + 1;
				$list[] = array( 'key' => (string) $k, 'num' => (string) $it['num'], 'from' => (string) ( $it['from'] ?? '' ), 'to' => (string) ( $it['to'] ?? '' ), 'state' => (string) $it['state'], 'note' => (string) ( $it['note'] ?? '' ) );
			}
		}
		$u = get_userdata( (int) $b['created_by'] );
		return array(
			'batch' => $b['id'], 'state' => $b['state'], 'counts' => $count, 'rows' => $list, 'notices' => $b['notices'], 'created' => (string) $b['created_at'],
			'by' => $u ? (string) $u->display_name : '', 'stuck' => 'applying' === $b['state'] && self::quiet( $b ),
		);
	}

	/* ================================================================ batches */

	public static function ajax_batches(): void {
		self::gate();
		global $wpdb;
		$ids = (array) $wpdb->get_col( 'SELECT id FROM ' . ZEST_DB::reassign_table() . " WHERE state IN ('applied','applying','partly_undone','undone') ORDER BY id DESC LIMIT 20" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name (ZEST_DB::reassign_table())
		$out = array();
		foreach ( $ids as $id ) {
			$b = self::load( (int) $id );
			if ( $b ) {
				$out[] = self::result( $b );
			}
		}
		wp_send_json_success( array( 'batches' => $out ) );
	}

	/* ================================================================ 6. undo */

	/**
	 * Put a batch's estimates back, 4 at a time (the page calls again until done): each only if the billing system still
	 * has exactly what the batch wrote and it is still an open stub of the same customer. Any admin may undo a batch.
	 */
	public static function ajax_undo(): void {
		$uid = self::gate();
		$b   = self::batch_for( $uid, false );
		if ( ! in_array( $b['state'], array( 'applied', 'partly_undone' ), true ) ) {
			wp_send_json_error( array( 'message' => 'applying' === $b['state'] ? __( 'This batch is still being written. Wait for it to finish.', 'zorderz' ) : __( 'There is nothing in this batch to undo.', 'zorderz' ) ) );
		}
		$run = isset( $_POST['run'] ) ? substr( preg_replace( '/[^A-Za-z0-9]/', '', (string) wp_unslash( $_POST['run'] ) ), 0, 24 ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing -- letters and digits only; nonce verified in self::gate() (ZEST_Access::guard()) above
		$run = '' !== $run ? $run : 'once';
		wp_send_json_success( self::locked( $b['id'], static function () use ( $b, $uid, $run ) {
			$b    = self::load( $b['id'] );
			$todo = static function ( $x ) use ( $run ) {
				return 'applied' === $x['state'] || ( 'undo_failed' === $x['state'] && ( $x['undo_run'] ?? '' ) !== $run );
			};
			$n   = 0;
			$res = array();
			foreach ( $b['items'] as $k => $it ) {
				if ( ! $todo( $it ) ) {
					continue;
				}
				if ( $n++ >= self::WRITE_N ) {
					break;
				}
				$b['items'][ $k ]['undo_run'] = $run;
				$r = self::undo_one( $b, (string) $k, $uid );
				if ( empty( $r['ok'] ) && 'refused' !== $b['items'][ $k ]['state'] ) {
					$b['items'][ $k ]['state'] = 'undo_failed';
					$b['items'][ $k ]['note']  = (string) ( $r['why'] ?? __( 'not undone', 'zorderz' ) );
				}
				$res[] = array( 'key' => (string) $k ) + $r;
			}
			$left  = count( array_filter( $b['items'], $todo ) );
			$state = $b['state'];
			if ( 0 === $left ) {
				$stay  = count( array_filter( $b['items'], static function ( $x ) { return in_array( $x['state'], array( 'refused', 'undo_failed' ), true ); } ) );
				$state = $stay ? 'partly_undone' : 'undone';
			}
			self::save( $b, array( 'items' => $b['items'], 'state' => $state, 'undone_at' => current_time( 'mysql', true ), 'undone_by' => $uid ) );
			if ( 0 === $left ) {
				$docs = array();
				foreach ( $b['items'] as $it ) {
					if ( 'undone' === $it['state'] ) {
						$docs[] = (string) $it['num'];
					}
				}
				try {
					do_action( 'zest_reassign_undone', (int) $b['id'], $docs, $uid );
				} catch ( \Throwable $e ) {
					error_log( 'Zorderz Estimates: a re-assign undo listener failed - ' . $e->getMessage() );
				}
			}
			return array( 'results' => $res, 'done' => 0 === $left ) + self::result( self::load( $b['id'] ) );
		} ) );
	}

	private static function undo_one( array &$b, string $k, int $uid ): array {
		global $wpdb;
		$it   = $b['items'][ $k ];
		$lock = 'zest_bv_estimate_' . $it['bid'];
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) ) ) {
			return array( 'ok' => false, 'num' => $it['num'], 'why' => __( 'someone is saving it right now; try Undo again in a moment', 'zorderz' ) );
		}
		try {
			$doc = ZEST_Billing_Doc::read( 'estimate', (string) $it['bid'] );
			if ( ! is_array( $doc ) ) {
				return array( 'ok' => false, 'num' => $it['num'], 'why' => __( 'the billing system could not be read just now; try Undo again', 'zorderz' ) );
			}
			$notes = (string) ( $doc['notes'] ?? '' );
			if ( ! hash_equals( (string) ( $it['after']['hash'] ?? '' ), ZEST_Line_Edits::base_hash( ZEST_Line_Edits::lines_from_doc( $doc ), $notes ) ) ) {
				$b['items'][ $k ]['state'] = 'refused';
				$b['items'][ $k ]['note']  = __( 'changed since the batch, so it was left as it is now', 'zorderz' );
				return array( 'ok' => false, 'num' => $it['num'], 'why' => $b['items'][ $k ]['note'] );
			}
			// sold, archived or a new customer since: never handed back
			$check = self::snapshot( $it, $doc, array( (string) $it['to'] ), array( __CLASS__, 'is_test' ), array( __CLASS__, 'holder' ) );
			if ( 'eligible' !== $check['state'] ) {
				$b['items'][ $k ]['state'] = 'refused';
				/* translators: %s: why */
				$b['items'][ $k ]['note']  = sprintf( __( 'now %s, so it was left as it is', 'zorderz' ), (string) ( $check['why'] ?? __( 'not open', 'zorderz' ) ) );
				return array( 'ok' => false, 'num' => $it['num'], 'why' => $b['items'][ $k ]['note'] );
			}
			$i     = (int) ( $it['before']['index'] ?? -1 );
			$lines = array_values( (array) ( $doc['lines'] ?? array() ) );
			if ( ! isset( $lines[ $i ] ) ) {
				return array( 'ok' => false, 'num' => $it['num'], 'why' => __( 'its location line is not where it was', 'zorderz' ) );
			}
			$lines[ $i ]['description'] = (string) $it['before']['text'];
			$me = class_exists( 'ZEST_Salesperson' ) ? ZEST_Salesperson::initials_of( $uid ) : '';
			$hb = substr( hash( 'sha256', 'rsg|' . $b['id'] . '|' . $it['bid'] . '|undo' ), 0, 32 );
			/* translators: 1: batch, 2: to, 3: from */
			$reason = sprintf( __( 'Undo of re-assign batch %1$d (%2$s back to %3$s).', 'zorderz' ), (int) $b['id'], $it['to'], $it['from'] );
			$log    = array( array( 'line_no' => $i + 1, 'type' => 'text', 'field' => 'description', 'before' => (string) ( $doc['lines'][ $i ]['description'] ?? '' ), 'after' => (string) $it['before']['text'], 'before_amount' => null, 'after_amount' => null ) );
			if ( ! ZEST_Billing_View::log( $hb, 'estimate', (string) $it['bid'], (string) $it['num'], $uid, $me, $log, $reason ) ) {
				return array( 'ok' => false, 'num' => $it['num'], 'why' => __( 'the change history could not be recorded, so nothing was written', 'zorderz' ) );
			}
			$put = ZEST_Billing_Doc::put( 'estimate', (string) $it['bid'], array( 'lines' => ZEST_Billing_Doc::writable_lines( $lines ), 'notes' => (string) $it['before']['notes'] ), (string) ( $doc['create_date'] ?? '' ) );
			if ( empty( $put['ok'] ) ) {
				ZEST_Billing_View::mark( $hb, 'failed', false );
				return array( 'ok' => false, 'num' => $it['num'], 'why' => __( 'the billing system did not accept the change; try Undo again', 'zorderz' ) );
			}
			ZEST_Billing_View::mark( $hb, 'saved', true );
			if ( null !== ( $it['before']['salesperson'] ?? null ) ) {
				$row = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . ZEST_DB::estimates_table() . ' WHERE billing_doc_id = %s ORDER BY id DESC LIMIT 1', (string) $it['bid'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name (ZEST_DB::estimates_table())
				if ( $row ) {
					$data = array( 'salesperson' => (string) $it['before']['salesperson'], 'notes' => (string) $it['before']['notes'] );
					$data = array_intersect_key( (array) apply_filters( 'zest_row_data', $data, $uid ), $data );
					$wpdb->update( ZEST_DB::estimates_table(), $data, array( 'id' => (int) $row ) );
				}
			}
			$b['items'][ $k ]['state'] = 'undone';
			$b['items'][ $k ]['note']  = '';
			return array( 'ok' => true, 'num' => $it['num'] );
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}
}
