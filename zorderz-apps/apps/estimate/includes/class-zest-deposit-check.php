<?php
/**
 * ZEST_Deposit_Check - the deposit limit in the app: shown, enforced, fixable, and listed.
 *
 *   nothing silent   no write changes a customer's words on its own: every change is previewed and confirmed.
 *   the open view    zest_dep_check {id}: what the estimate asks over the limit (sure asks, and possible ones as a
 *                    warning), the limit in words, and whether this person may fix it (the estimate is theirs).
 *   Approve, Send    refused while the estimate surely asks over the limit (refusal()); the chat's send too. A possible
 *                    ask never holds anything up.
 *   Fix              zest_dep_fix {id, step: preview}: what ZEST_Deposit_Rule::fix_doc would change in the notes, the
 *                    terms and the $0 lines (where, before, after), bound to the copy it read by a token; {step: apply,
 *                    token}: the same copy read again, the token checked, then written (billing system and this app's
 *                    row), read back and checked. Gate codes are cut first. A priced line, the billing system's own
 *                    deposit request and any invoice are changed by hand.
 *   the list         zest_dep_scan (admin tier, never the shared device): this app's open estimates, newest first, a
 *                    page at a time, each read here from its stored copy.
 *
 * The limit is the job's: ZEST_Deposit_Limit::for_region() for the state on the estimate (unknown: the strictest that
 * could apply). With no limit in force nothing is found and nothing is refused.
 *
 * No customer words are logged. No model reads any of it.
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZEST_Deposit_Check {

	const PAGE = 50;

	public static function init(): void {
		add_action( 'wp_ajax_zest_dep_check', array( __CLASS__, 'ajax_check' ) );
		add_action( 'wp_ajax_zest_dep_fix', array( __CLASS__, 'ajax_fix' ) );
		add_action( 'wp_ajax_zest_dep_scan', array( __CLASS__, 'ajax_scan' ) );
	}

	/* ================================================================ the document */

	/** The job's state (two letters) from the row, or ''. */
	public static function region( array $row ): string {
		$s = strtoupper( trim( (string) ( $row['customer_state'] ?? '' ) ) );
		if ( preg_match( '/^[A-Z]{2}$/', $s ) ) {
			return $s;
		}
		if ( class_exists( 'ZAGR_Pack' ) && method_exists( 'ZAGR_Pack', 'region_of' ) && class_exists( 'ZEST_Terms_Note' ) ) {
			try {
				return (string) ZAGR_Pack::region_of( ZEST_Terms_Note::buyer( $row ) );
			} catch ( \Throwable $e ) {
				return '';
			}
		}
		return '';
	}

	/** Read against this row's limit from here on. */
	public static function use_limit( array $row ): void {
		ZEST_Deposit_Limit::set( ZEST_Deposit_Limit::for_region( self::region( $row ) ) );
	}

	/**
	 * The estimate in billing shape: the billing system's copy when it has one and it can be read ($from 'billing'),
	 * else this app's stored copy ($from 'local', no saved total: the plan predicts it).
	 *
	 * @return array [ doc, from ]
	 */
	public static function doc_for( array $row, bool $read_billing = true ): array {
		$bid = (string) ( $row['billing_doc_id'] ?? '' );
		if ( $read_billing && '' !== $bid && class_exists( 'ZEST_Billing_Doc' ) && ZEST_Billing_Doc::available() ) {
			$doc = ZEST_Billing_Doc::read( 'estimate', $bid );
			if ( is_array( $doc ) ) {
				return array( $doc, 'billing' );
			}
		}
		$doc = ZEST_Salesperson::doc_from_row( $row );
		$doc['estimate_number'] = (string) ( $row['billing_doc_num'] ?? '' );
		$doc['customer_name']   = (string) ( $row['customer_name'] ?? '' );
		return array( $doc, 'local' );
	}

	/** The findings for this estimate's document (the app's own schedule block, exactly right, is not read). */
	public static function findings( array $row, array $doc ): array {
		self::use_limit( $row );
		$doc = class_exists( 'ZEST_Terms_Note' ) ? ZEST_Terms_Note::unread( $doc, (int) ( $row['id'] ?? 0 ) ) : $doc;
		return ZEST_Deposit_Rule::doc_findings( $doc );
	}

	private static function cut( string $s ): string {
		return class_exists( 'ZDZ_Sealed' ) ? (string) ZDZ_Sealed::cut( $s ) : $s;
	}

	/** Is the person in a view that hides customer words and prices (Privacy View)? */
	private static function plain_view( int $uid ): bool {
		return class_exists( 'ZDZ_Privacy_View' ) && method_exists( 'ZDZ_Privacy_View', 'is_on' ) && ZDZ_Privacy_View::is_on( $uid );
	}

	/**
	 * Approve / Send / the sale form refuse an estimate that surely asks over the limit: { code, message } or null. In a
	 * view that hides customer words, the message carries no words and no figure.
	 */
	public static function refusal( array $row, array $doc, string $doing, int $uid = 0 ): ?array {
		$found = ZEST_Deposit_Rule::blocking( self::findings( $row, $doc ) );
		if ( ! $found ) {
			return null;
		}
		$num = (string) ( $row['billing_doc_num'] ?? '' );
		$msg = self::plain_view( $uid ) ? ZEST_Deposit_Rule::refusal_plain( 'estimate', $num, $doing ) : ZEST_Deposit_Rule::refusal_text( $found, 'estimate', $num, $doing );
		return array( 'code' => 'deposit_over', 'message' => self::cut( $msg ) );
	}

	/** The open view's payload: findings (cut), the limit in words, and whether this person may fix it. */
	public static function payload( array $row, array $doc, int $uid ): array {
		$found   = self::findings( $row, $doc );
		$plain   = self::plain_view( $uid );
		$fixable = (bool) array_filter( $found, static function ( $f ) { return ! empty( $f['fixable'] ); } );
		return array(
			'limit'    => ZEST_Deposit_Limit::active() ? ZEST_Deposit_Limit::describe() : '',
			'findings' => $plain ? array() : array_map( static function ( $f ) {
				return array( 'where' => (string) $f['where'], 'words' => self::cut( (string) $f['words'] ), 'why' => (string) $f['why'], 'sure' => ! empty( $f['sure'] ), 'fixable' => ! empty( $f['fixable'] ) );
			}, $found ),
			'sure'     => count( ZEST_Deposit_Rule::blocking( $found ) ),
			'possible' => count( $found ) - count( ZEST_Deposit_Rule::blocking( $found ) ),
			'may_fix'  => $fixable && ! $plain,
		);
	}

	/* ================================================================ ajax */

	public static function ajax_check(): void {
		list( $uid, $row ) = ZEST_Access::load_owned( 'id', false );
		list( $doc )       = self::doc_for( $row );
		wp_send_json_success( self::payload( $row, $doc, $uid ) );
	}

	/** The fix of this copy: [ notes, terms, lines, removed, wrote ] and a token binding it to the copy read. */
	private static function fix_of( array $row, array $doc ): array {
		self::use_limit( $row );
		$lines = array();
		foreach ( (array) ( $doc['lines'] ?? array() ) as $l ) {
			$p = ZEST_Payment_Plan::line( $l );
			if ( null !== $p ) {
				$lines[] = array( 'name' => $p[0], 'description' => $p[1], 'qty' => $p[2], 'rate' => $p[2] > 0 ? round( $p[3] / 100 / $p[2], 2 ) : 0 );
			}
		}
		$fix   = ZEST_Deposit_Rule::fix_doc( $doc, $lines );
		$token = hash( 'sha256', (string) wp_json_encode( array( (int) $row['id'], (string) ( $doc['notes'] ?? '' ), (string) ( $doc['terms'] ?? '' ), $lines ) ) );
		return array( $fix, $token, $lines );
	}

	public static function ajax_fix(): void {
		list( $uid, $row ) = ZEST_Access::load_owned( 'id', true );
		if ( self::plain_view( $uid ) ) {
			wp_send_json_error( array( 'message' => __( 'The deposit wording can\'t be fixed in this view. Ask the salesperson or an admin.', 'zorderz' ) ), 403 );
		}
		list( $doc, $from ) = self::doc_for( $row );
		list( $fix, $token, $lines ) = self::fix_of( $row, $doc );
		list( $notes, $terms, $new_lines, $removed, $wrote ) = $fix;
		$step = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : 'preview'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in ZEST_Access::load_owned() above
		if ( ! $removed ) {
			wp_send_json_error( array( 'message' => __( 'There is nothing the app can fix here: change it by hand.', 'zorderz' ) ) );
		}
		if ( 'apply' !== $step ) {
			wp_send_json_success( array(
				'token'   => $token,
				'removed' => array_map( array( __CLASS__, 'cut' ), array_map( 'strval', $removed ) ),
				'wrote'   => self::cut( (string) $wrote ),
				'notes'   => self::cut( (string) $notes ),
				'terms'   => self::cut( (string) $terms ),
			) );
		}
		$sent = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in ZEST_Access::load_owned() above
		if ( ! hash_equals( $token, $sent ) ) {
			wp_send_json_error( array( 'code' => 'changed', 'message' => __( 'The estimate changed since this preview, so nothing was written. Look at the fix again.', 'zorderz' ) ) );
		}
		// the $0 lines' new words go back on the billing system's own lines, every other field kept
		if ( 'billing' === $from ) {
			$wire = (array) ( $doc['lines'] ?? array() );
			foreach ( $new_lines as $i => $l ) {
				if ( isset( $wire[ $i ] ) && is_array( $wire[ $i ] ) ) {
					$wire[ $i ]['name']        = (string) $l['name'];
					$wire[ $i ]['description'] = (string) $l['description'];
				}
			}
			$res = ZEST_Billing_Doc::put( 'estimate', (string) $row['billing_doc_id'], array( 'notes' => (string) $notes, 'terms' => (string) $terms, 'lines' => ZEST_Billing_Doc::writable_lines( $wire ) ), (string) ( $doc['create_date'] ?? '' ) );
			if ( empty( $res['ok'] ) ) {
				wp_send_json_error( array( 'message' => $res['error'] ) );
			}
			$doc = is_array( $res['doc'] ) && ! empty( $res['doc']['lines'] ) ? $res['doc'] : ( ZEST_Billing_Doc::read( 'estimate', (string) $row['billing_doc_id'] ) ?: $doc );
		}
		self::store_local( $row, (string) $notes, (string) $terms, $new_lines );
		$row  = ZEST_Access::row( (int) $row['id'] ) ?: $row;
		$left = ZEST_Deposit_Rule::blocking( self::findings( $row, 'billing' === $from ? $doc : self::doc_for( $row, false )[0] ) );
		wp_send_json_success( array(
			'fixed'   => true,
			'message' => $left
				? __( 'The wording is fixed. Still over the limit:', 'zorderz' ) . ' ' . implode( '; ', array_map( static function ( $f ) { return $f['where'] . ' (' . $f['why'] . ')'; }, $left ) ) . '. ' . __( 'That has to be changed by hand.', 'zorderz' )
				: __( 'The deposit wording is fixed. Check it, then approve it again.', 'zorderz' ),
		) );
	}

	/** This app's copy follows a fix: notes, terms, and the $0 lines' words. */
	private static function store_local( array $row, string $notes, string $terms, array $lines ): void {
		global $wpdb;
		$items = json_decode( (string) ( $row['items_json'] ?? '' ), true );
		$items = is_array( $items ) ? array_values( $items ) : array();
		foreach ( $lines as $i => $l ) {
			if ( ! isset( $items[ $i ] ) || ! is_array( $items[ $i ] ) ) {
				continue;
			}
			if ( array_key_exists( 'sub_description', $items[ $i ] ) || ! isset( $items[ $i ]['name'] ) ) {
				$items[ $i ]['description']     = (string) $l['name'];
				$items[ $i ]['sub_description'] = (string) $l['description'];
			} else {
				$items[ $i ]['name']        = (string) $l['name'];
				$items[ $i ]['description'] = (string) $l['description'];
			}
		}
		$data = array( 'notes' => $notes, 'terms' => $terms, 'items_json' => wp_json_encode( $items ), 'updated_at' => current_time( 'mysql', true ), 'seal_hold' => (string) ( $row['seal_hold'] ?? '' ) );
		$data = array_intersect_key( (array) apply_filters( 'zest_row_data', $data, (int) get_current_user_id() ), $data );
		$wpdb->update( ZEST_DB::estimates_table(), $data, array( 'id' => (int) $row['id'] ) );
	}

	/** The list: this app's open estimates (admin tier), one page, each read from its stored copy. */
	public static function ajax_scan(): void {
		$uid = ZEST_Access::guard();
		ZEST_Access::no_kiosk( $uid );
		if ( ! ZEST_Access::is_admin_tier( $uid ) ) {
			wp_send_json_error( array( 'message' => __( 'Only the admin tier can list the estimates that ask too much up front.', 'zorderz' ) ), 403 );
		}
		$page = isset( $_POST['page'] ) ? max( 0, (int) $_POST['page'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in ZEST_Access::guard() above
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . ZEST_DB::estimates_table() . " WHERE doc_type = 'estimate' AND status NOT IN ('declined','void','accepted','converted') ORDER BY id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name (ZEST_DB::estimates_table())
			self::PAGE, $page * self::PAGE
		), ARRAY_A );
		$out = array();
		foreach ( $rows as $row ) {
			list( $doc ) = self::doc_for( $row, false );
			$found       = self::findings( $row, $doc );
			if ( ! $found ) {
				continue;
			}
			$sure  = ZEST_Deposit_Rule::blocking( $found );
			$out[] = array(
				'id'       => (int) $row['id'],
				'number'   => (string) $row['billing_doc_num'],
				'customer' => (string) $row['customer_name'],
				'sure'     => count( $sure ),
				'possible' => count( $found ) - count( $sure ),
				'first'    => self::cut( (string) ( $found[0]['where'] . ': ' . $found[0]['words'] ) ),
				'fixable'  => (bool) array_filter( $found, static function ( $f ) { return ! empty( $f['fixable'] ); } ),
			);
		}
		wp_send_json_success( array( 'rows' => $out, 'page' => $page, 'more' => count( $rows ) === self::PAGE ) );
	}
}
