<?php
/**
 * ZEST_Approve - Approve and Send, with every check in front of them.
 *
 *   zest_approve {id, language_ok?, disaster_repair?}
 *     1. the estimate is the person's (ZEST_Access), never the shared device;
 *     2. refused while it surely asks a deposit over the limit (ZEST_Deposit_Check) or still offers two options of one
 *        line (ZEST_Price_Book_Lines: the total counts both until one is chosen);
 *     3. where the payment-notes switch wants a schedule (ZEST_Terms_Note::wanted) and the estimate is in a schedule
 *        group, the schedule block goes into the notes (in the billing system and this app's copy); a schedule typed by
 *        hand under the same heading refuses instead of a second one; an estimate that left scope loses the block;
 *     4. with the agreements app: the language question is asked once per estimate (an administrator may answer again),
 *        ensure() makes or refreshes the agreement, and the person who approves signs for the business, when they see
 *        prices (someone who doesn't approves as before and signs nothing, so Send waits for someone who does).
 *   zest_send {id}
 *     only an approved estimate, and only the copy that was approved: Approve keeps a fingerprint of the estimate as it
 *     was approved (approval_fp: its lines, notes, terms, customer, total and reference), and any change since, here or
 *     in the billing system, means approving again;
 *     the deposit check again; an in-scope estimate whose schedule is missing or out of date is refused; with the
 *     agreements app, an agreement the business has not signed for this copy is refused; then the billing system
 *     e-mails it (its copy checked for gate codes first) and this app's row says sent.
 *
 * Both record the action in the Usage view (ZDZ_Usage, through zest_usage()). Nothing here is a model's.
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZEST_Approve {

	public static function init(): void {
		add_action( 'wp_ajax_zest_approve', array( __CLASS__, 'ajax_approve' ) );
		add_action( 'wp_ajax_zest_send', array( __CLASS__, 'ajax_send' ) );
	}

	private static function sees_prices( int $uid ): bool {
		if ( class_exists( 'ZDZ_Privacy_View' ) && ZDZ_Privacy_View::is_on( $uid ) ) {
			return false;
		}
		$p = class_exists( 'ZEST_Dashboard' ) ? ZEST_Dashboard::get_resolved_permissions( $uid ) : array( 'view_pricing' => true );
		return ! empty( $p['view_pricing'] );
	}

	/**
	 * What an approval is bound to: the estimate exactly as it is, every line's words, quantity and price, the notes,
	 * the terms, the customer and the address printed on it, the total and the reference.
	 */
	public static function approval_fp( array $doc ): string {
		$lines = ZEST_Line_Edits::lines_from_doc( $doc );
		$amt   = is_array( $doc['amount'] ?? null ) ? ( $doc['amount']['amount'] ?? 0 ) : ( $doc['amount'] ?? 0 );
		$parts = array(
			ZEST_Line_Edits::base_hash( $lines, (string) ( $doc['notes'] ?? '' ) ),
			trim( (string) ( $doc['terms'] ?? '' ) ),
			(string) ( $doc['customerid'] ?? '' ),
			number_format( is_numeric( $amt ) ? (float) $amt : 0.0, 2, '.', '' ),
			trim( (string) ( $doc['po_number'] ?? '' ) ),
			implode( '/', array_map( static function ( $k ) use ( $doc ) {
				return trim( is_scalar( $doc[ $k ] ?? null ) ? (string) $doc[ $k ] : '' );
			}, array( 'fname', 'lname', 'organization', 'street', 'street2', 'city', 'province', 'code', 'country', 'currency_code' ) ) ),
		);
		return hash( 'sha256', 'zest-approve-v1|' . implode( '|', $parts ) );
	}

	/** Is this copy the one that was approved? { code, message } when not, else null. */
	public static function approval_refusal( array $row, array $doc ): ?array {
		$fp = (string) ( $row['approved_fp'] ?? '' );
		if ( '' === $fp ) {
			return array( 'code' => 'needs_approval', 'message' => __( 'Approve this estimate before sending it: open it, check it and press Approve.', 'zorderz' ) );
		}
		if ( ! hash_equals( $fp, self::approval_fp( $doc ) ) ) {
			return array( 'code' => 'needs_approval', 'message' => __( 'This estimate changed after it was approved, so it was not sent. Check it again and approve it.', 'zorderz' ) );
		}
		return null;
	}

	/** The local row follows a write: these columns (codes cut by the zest_row_data filter). */
	private static function update_row( array $row, array $data ): void {
		global $wpdb;
		$data['seal_hold'] = (string) ( $row['seal_hold'] ?? '' );   // a new code is sealed under the row's own hold
		$data = array_intersect_key( (array) apply_filters( 'zest_row_data', $data, (int) get_current_user_id() ), $data );
		$wpdb->update( ZEST_DB::estimates_table(), $data, array( 'id' => (int) $row['id'] ) );
	}

	/** Write new notes (and terms) where the estimate lives: the billing system (when it has it) and this app's row. */
	private static function write_notes( array $row, array $doc, string $from, string $notes, ?string $terms ): array {
		if ( 'billing' === $from ) {
			$f = array( 'notes' => $notes );
			if ( null !== $terms ) {
				$f['terms'] = $terms;
			}
			$res = ZEST_Billing_Doc::put( 'estimate', (string) $row['billing_doc_id'], $f, (string) ( $doc['create_date'] ?? '' ) );
			if ( empty( $res['ok'] ) ) {
				return $res;
			}
		}
		$data = array( 'notes' => $notes, 'updated_at' => current_time( 'mysql', true ) );
		if ( null !== $terms ) {
			$data['terms'] = $terms;
		}
		self::update_row( $row, $data );
		return array( 'ok' => true, 'error' => '' );
	}

	/**
	 * Approve one estimate. $in: language_ok ('yes' | 'no' | ''), disaster_repair ('yes' | 'no' | ''). Returns { ok,
	 * code, message, ask? }; ask = 'language' when the agreement needs the language answer first (nothing written).
	 */
	public static function approve( array $row, int $uid, array $in = array() ): array {
		$id = (int) $row['id'];
		list( $doc, $from ) = ZEST_Deposit_Check::doc_for( $row );
		$no = ZEST_Deposit_Check::refusal( $row, $doc, 'approved', $uid );
		if ( $no ) {
			return array( 'ok' => false ) + $no;
		}
		if ( class_exists( 'ZEST_Price_Book_Lines' ) && ZEST_Price_Book_Lines::has_options( (array) ( $doc['lines'] ?? array() ) ) ) {
			return array( 'ok' => false, 'code' => 'options', 'message' => __( 'This estimate still offers two options of one line, and its total counts both. Remove the one the customer did not choose, then approve.', 'zorderz' ) );
		}
		$sees  = self::sees_prices( $uid );
		$notes = (string) ( $doc['notes'] ?? '' );
		$plan  = ZEST_Payment_Plan::build( $doc );
		$plan['tax_included'] = ZEST_Terms_Note::tax_included( $doc );
		$want  = ZEST_Terms_Note::wanted( $doc + array( 'customer_name' => (string) ( $row['customer_name'] ?? '' ) ) );
		$seam  = ZEST_Terms_Note::seam() && $plan['in_scope'] && $plan['ok'] && $want;

		// the language answer, when an agreement may follow
		$lang = isset( $in['language_ok'] ) ? (string) $in['language_ok'] : '';
		if ( in_array( $lang, array( 'yes', 'no' ), true ) && ! ZEST_Terms_Note::set_language( $id, 'yes' === $lang, $uid ) ) {
			return array( 'ok' => false, 'code' => 'language_kept', 'message' => __( 'The answer kept for this estimate is No. An administrator can answer again.', 'zorderz' ) );
		}
		$ans = ZEST_Terms_Note::language( $id );
		if ( $seam && $sees && null === $ans ) {
			return array( 'ok' => false, 'code' => 'ask', 'ask' => 'language', 'message' => __( 'Was the sale made in the agreement\'s language? Answer once for this estimate.', 'zorderz' ) );
		}
		// the staff questions the job's legal packs ask (a disaster repair, ...): answered once per estimate, never assumed
		$answers = array();
		if ( $seam ) {
			foreach ( ZEST_Terms_Note::staff_questions( ZEST_Deposit_Check::region( $row ) ) as $qid => $key ) {
				$given = (string) ( $in[ $key ] ?? '' );
				if ( in_array( $given, array( 'yes', 'no' ), true ) ) {
					ZEST_Terms_Note::set_staff_answer( $id, $qid, 'yes' === $given );
				}
				$answers[ $key ] = ZEST_Terms_Note::staff_answer( $id, $qid );
				if ( null === $answers[ $key ] && $sees ) {
					return array( 'ok' => false, 'code' => 'ask', 'ask' => $key, 'message' => __( 'The agreement for this job asks a question first. Answer it once for this estimate.', 'zorderz' ) );
				}
			}
		}

		// the schedule in the notes
		if ( $want && $plan['in_scope'] ) {
			if ( ! $plan['ok'] ) {
				return array( 'ok' => false, 'code' => 'plan', 'message' => __( 'The payment schedule can\'t be worked out:', 'zorderz' ) . ' ' . implode( '; ', $sees ? $plan['why'] : $plan['why_plain'] ) . '.' );
			}
			if ( ZEST_Terms_Note::lookalike( $notes ) ) {
				return array( 'ok' => false, 'code' => 'lookalike', 'message' => __( 'The notes already hold a payment schedule typed by hand. Take it out, then approve: the app writes the schedule itself.', 'zorderz' ) );
			}
			$url   = ZEST_Terms_Note::link_for( $id );
			$block = ZEST_Terms_Note::render( $plan, '' !== $url ? ZEST_Terms_Note::link_line( $url ) : ZEST_Terms_Note::phone_line() );
			$new   = ZEST_Terms_Note::put( $notes, $block );
			if ( $new !== $notes ) {
				$w = self::write_notes( $row, $doc, $from, $new, ZEST_Terms_Note::TERMS );
				if ( empty( $w['ok'] ) ) {
					return array( 'ok' => false, 'code' => 'write', 'message' => (string) $w['error'] );
				}
				$doc['notes'] = $new;
			}
		} elseif ( ZEST_Terms_Note::has_block( $notes ) ) {
			$w = self::write_notes( $row, $doc, $from, ZEST_Terms_Note::take_out( $notes ), null );
			if ( empty( $w['ok'] ) ) {
				return array( 'ok' => false, 'code' => 'write', 'message' => (string) $w['error'] );
			}
		}

		// the agreement
		$signed = false;
		$said   = '';
		if ( $seam ) {
			$req = ZEST_Terms_Note::request( $row, $doc, $plan, $ans, $answers );
			if ( is_array( $req ) ) {
				$r = ZEST_Terms_Note::ensure( $req );
				if ( $r['ok'] && $sees ) {
					$s      = ZEST_Terms_Note::contractor_sign( $id, $uid, (string) $req['doc_hash'] );
					$signed = ! empty( $s['ok'] );
					$said   = $signed ? '' : (string) $s['message'];
				} elseif ( ! $r['ok'] && ! in_array( $r['code'], array( 'mode_off', 'absent' ), true ) ) {
					$said = (string) $r['message'];
				}
				$key = ZEST_Terms_Note::signed_key( $doc, $plan, (string) ( $row['billing_doc_num'] ?? '' ), (string) ( $doc['create_date'] ?? substr( (string) ( $row['created_at'] ?? '' ), 0, 10 ) ) );
				ZEST_Terms_Note::settle( $id, $key, $signed, $uid, $req, ZEST_Terms_Note::seller( $row )['uid'] );
			}
		}
		// bound to the copy as it is now, the billing system's own words read back after any write above
		if ( 'billing' === $from && class_exists( 'ZEST_Billing_Doc' ) ) {
			$now = ZEST_Billing_Doc::read( 'estimate', (string) $row['billing_doc_id'] );
			$doc = is_array( $now ) ? $now : $doc;
		} elseif ( 'local' === $from ) {
			$doc = ZEST_Deposit_Check::doc_for( ZEST_Access::row( $id ) ?: $row, false )[0];
		}
		self::update_row( $row, array( 'approved_at' => current_time( 'mysql', true ), 'approved_by' => $uid, 'approved_fp' => self::approval_fp( $doc ) ) );
		if ( function_exists( 'zest_usage' ) ) {
			zest_usage( 'estimate_approved', $id );
		}
		$msg = __( 'Approved.', 'zorderz' );
		if ( $signed ) {
			$msg .= ' ' . __( 'The agreement is signed for the business and ready for the customer.', 'zorderz' );
		} elseif ( '' !== $said ) {
			$msg .= ' ' . $said;
		}
		return array( 'ok' => true, 'code' => 'ok', 'message' => $msg, 'signed' => $signed );
	}

	/** Why this estimate may not be sent now, or null. */
	public static function send_refusal( array $row, array $doc, int $uid ): ?array {
		$no = self::approval_refusal( $row, $doc );
		if ( $no ) {
			return $no;
		}
		$no = ZEST_Deposit_Check::refusal( $row, $doc, 'sent', $uid );
		if ( $no ) {
			return $no;
		}
		$id   = (int) $row['id'];
		$plan = ZEST_Payment_Plan::build( $doc );
		$want = ZEST_Terms_Note::wanted( $doc + array( 'customer_name' => (string) ( $row['customer_name'] ?? '' ) ) );
		if ( $want && $plan['in_scope'] && null === ZEST_Terms_Note::valid_block( $doc, $id ) ) {
			return array( 'code' => 'schedule', 'message' => __( 'The payment schedule in the notes is missing or out of date. Approve the estimate again, then send it.', 'zorderz' ) );
		}
		if ( $want && ! $plan['in_scope'] && ZEST_Terms_Note::has_block( (string) ( $doc['notes'] ?? '' ) ) ) {
			return array( 'code' => 'schedule', 'message' => __( 'The notes hold a payment schedule this estimate no longer has. Approve it again, then send it.', 'zorderz' ) );
		}
		if ( $want && $plan['in_scope'] && ZEST_Terms_Note::seam() ) {
			$st  = ZEST_Terms_Note::status_for( $id );
			$key = ZEST_Terms_Note::signed_key( $doc, $plan, (string) ( $row['billing_doc_num'] ?? '' ), (string) ( $doc['create_date'] ?? substr( (string) ( $row['created_at'] ?? '' ), 0, 10 ) ) );
			$ok  = 'signed' === ZEST_Terms_Note::settled( $id, $key ) || ( null !== $st && in_array( $st['state'], array( 'signed', 'window_closed', 'change_pending' ), true ) );
			if ( null !== $st && 'none' !== $st['state'] && ! $ok ) {
				return array( 'code' => 'unsigned', 'message' => __( 'The business has not signed the agreement for this copy of the estimate. Someone who sees prices approves it first, then send it.', 'zorderz' ) );
			}
		}
		return null;
	}

	public static function send( array $row, int $uid ): array {
		list( $doc, $from ) = ZEST_Deposit_Check::doc_for( $row );
		$no = self::send_refusal( $row, $doc, $uid );
		if ( $no ) {
			return array( 'ok' => false ) + $no;
		}
		if ( 'billing' !== $from ) {
			return array( 'ok' => false, 'code' => 'no_billing', 'message' => __( 'This estimate is not in a billing system that can e-mail it. Print it or send it from there.', 'zorderz' ) );
		}
		$before = '' !== (string) ( $row['sent_at'] ?? '' ) || 'sent' === (string) ( $row['status'] ?? '' );
		$r      = ZEST_Billing_Doc::email( (string) $row['billing_doc_id'], (string) ( $row['customer_email'] ?? '' ) );
		if ( empty( $r['ok'] ) ) {
			return array( 'ok' => false, 'code' => 'send', 'message' => (string) $r['error'] );
		}
		self::update_row( $row, array( 'status' => 'sent', 'sent_at' => current_time( 'mysql', true ) ) );
		if ( function_exists( 'zest_usage' ) ) {
			zest_usage( $before ? 'estimate_resent' : 'estimate_sent', (int) $row['id'] );
		}
		return array( 'ok' => true, 'code' => 'ok', 'message' => __( 'Sent.', 'zorderz' ) );
	}

	public static function ajax_approve(): void {
		list( $uid, $row ) = ZEST_Access::load_owned( 'id', true );
		$in = array(
			'language_ok'     => isset( $_POST['language_ok'] ) ? sanitize_key( wp_unslash( $_POST['language_ok'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in ZEST_Access::load_owned() above
			'disaster_repair' => isset( $_POST['disaster_repair'] ) ? sanitize_key( wp_unslash( $_POST['disaster_repair'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in ZEST_Access::load_owned() above
		);
		foreach ( isset( $_POST['answers'] ) && is_array( $_POST['answers'] ) ? wp_unslash( $_POST['answers'] ) : array() as $k => $v ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing -- each key and value checked here; nonce verified in ZEST_Access::load_owned() above
			if ( preg_match( '/^[a-z][a-z0-9_]{0,30}$/', (string) $k ) && in_array( $v, array( 'yes', 'no' ), true ) && ! isset( $in[ $k ] ) ) {
				$in[ $k ] = $v;
			}
		}
		$r = self::approve( $row, $uid, $in );
		if ( empty( $r['ok'] ) ) {
			wp_send_json_error( $r );
		}
		wp_send_json_success( $r );
	}

	public static function ajax_send(): void {
		list( $uid, $row ) = ZEST_Access::load_owned( 'id', true );
		$r = self::send( $row, $uid );
		if ( empty( $r['ok'] ) ) {
			wp_send_json_error( $r );
		}
		wp_send_json_success( $r );
	}
}
