<?php
/**
 * ZAGR_API - the one seam between an estimate and its agreement. [CORE]
 *
 * The estimate app calls it only behind class_exists( 'ZAGR_API' ), inside try/catch. Nothing here throws: a failure
 * comes back as { ok: false, code, message } with a message that names what is wrong, never a value.
 *
 *   ensure( $req )            create or refresh the agreement for one estimate; idempotent per (estimate_id, doc_hash).
 *                             The request (ZAGR_Document::check()): estimate_id, client_id, is_test, language_ok (was
 *                             the sale negotiated in the agreement's language: true | false | null; "english" is read
 *                             the same way), buyer { name, email, address_lines, region? }, document, schedule,
 *                             salesperson { name, registration }, doc_hash, and optionally families
 *                             [ { family, units } ] (lead-time group ids, not part of doc_hash) and the staff answers
 *                             the job's legal packs ask (disaster_repair: true | false | null). When no agreement is
 *                             wanted (mode off, or test mode and is_test not true) that is said first, before the
 *                             request is checked at all.
 *                             codes: ok | not_language | language_kept (a signed agreement keeps its Yes) | mode_off |
 *                             not_ready | bad_request | hash_mismatch | cancelled | error
 *   contractor_sign( … )      the business signs (the staff member who approves): draft -> ready, or a change order's
 *                             draft -> ready. codes: ok | hash_mismatch | not_staff | not_language | mode_off |
 *                             not_ready | cancelled | bad_request | error
 *   status_for( $id )         { ok, state, signed_at, copy_received_at, cancel_by, ok_to_order, change_pending, url,
 *                             signable, error, unsignable_why, made_at, signed_via, waiting_for }
 *                             copy_received_at and cancel_by stay '' until the signed copy reached the customer (by
 *                             email, or opened on the page); ok_to_order is true only after the cancellation period
 *                             closed. signable: the customer could sign it now (what the page offers). error: the read
 *                             failed (tables this build can't use, anything thrown, or $wpdb's last_error on an empty
 *                             read), which "no agreement" never is. unsignable_why: why an unsigned one can't be signed
 *                             now: language_no | mode (decisions: an order gate may release on these) | draft | key |
 *                             readiness | document (waits), or ''. made_at: when the agreement was made (UTC, ISO 8601).
 *                             signed_via: 'online' | 'paper' ('' until the customer signs). A paper signing keeps state
 *                             "signed" (then window_closed, OK to order): signed_at is the paper date, copy_received_at
 *                             the moment it was recorded (the customer keeps the paper copy), cancel_by the packs'
 *                             business days after the paper date. waiting_for: 'admin_confirm' while a paper signing
 *                             recorded after its cancellation period had ended waits for an administrator to confirm
 *                             it in Settings (also while its paper step can't be read: never error true for it), else
 *                             ''; meanwhile its window has closed as usual (state window_closed) but ok_to_order is
 *                             false. A read may close a window that has run out, and so send the OK-to-order alert.
 *   record_paper( $id, $in )  the signed-in person records that the customer signed this estimate's agreement on paper
 *                             (ZAGR_Paper::record, via 'app'; $in: date, older, photos, disaster, and language_ok when
 *                             the agreement's language question is unanswered): { ok, code, message, state, signed_via,
 *                             signed_on, cancel_by, ok_to_order, waiting_for }. The person is always
 *                             get_current_user_id(), never one the caller names. codes: ok | none | not_yours | kiosk |
 *                             signed_out | signed_online | signed_paper | cancelled | busy | not_language | mode_off |
 *                             not_ready | date | older | photos | language | changed | error
 *   paper_for( $id )          for the signed-in person: { ok, can_record (they could record a paper signing now),
 *                             state, signed_via, signed_on (local Y-m-d), cancel_by, ok_to_order, today and first (the
 *                             paper dates it takes today: local Y-m-d, 30 days back at most and never before the
 *                             estimate's own date), language_ok ('yes' | 'no' | '': not asked yet; then record_paper()
 *                             takes $in['language_ok']), buyer_older (the buyer answered 65 or older online), asks_age
 *                             (the job's legal packs ask it), waiting_for ('admin_confirm' | '', as status_for's) }
 *   schedule_for( $id )       { ok, as (signed | offered), deposit_cents, balance_cents, total_cents, plan_version, payments, pending }
 *   link_for( $id )           the six-word link for the estimate, or '' (an unsigned agreement only where the mode allows
 *                             agreements; a signed one keeps its link in every mode)
 *
 * The estimate app also answers the filter zagr_estimate_salespeople ( array $user_ids, int $estimate_id ): the
 * salespeople it names on an estimate, who may record its paper signing.
 *
 * States: draft -> ready -> signed -> window_closed; signed / window_closed -> change_pending -> back (same cancel_by);
 * signed / change_pending -> cancelled. "signing" is held for the moment a signature is being saved.
 *
 * @package Zorderz\Agreements
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ZAGR_API {

	const SEAM = '1';

	private static function out( bool $ok, string $code, string $message = '', string $url = '', string $state = '' ): array {
		return array( 'ok' => $ok, 'code' => $code, 'message' => $message, 'url' => $url, 'state' => $state );
	}

	private static function mode_allows( bool $is_test ): bool {
		$m = ZAGR_Settings::mode();
		return 'on' === $m || ( 'test' === $m && $is_test );
	}

	/* ================================================================ ensure */

	public static function ensure( array $req ): array {
		try {
			return self::ensure_inner( $req );
		} catch ( \Throwable $e ) {
			error_log( 'Zorderz agreements: ensure() failed (' . get_class( $e ) . ').' ); // phpcs:ignore -- never the request's values
			return self::out( false, 'error', 'The agreement could not be made just now.' );
		}
	}

	/** Tables of another shape are never read or written. */
	private static function unavailable(): array {
		return self::out( false, 'not_ready', 'The agreements app found tables of another shape that it can\'t use, so it takes no agreements. Settings, Agreements says more.' );
	}

	private static function ensure_inner( array $req ): array {
		// when no agreement is wanted, say so before looking at anything else in the request, so a request this app
		// would refuse can never hold up the estimate's Approve or Send
		if ( ! self::mode_allows( true === ( $req['is_test'] ?? null ) ) ) {
			return self::out( false, 'mode_off', 'test' === ZAGR_Settings::mode() ? 'Test mode: only test estimates get agreements.' : 'Agreements are off.' );
		}
		if ( ! ZAGR_Install::ready() ) {
			return self::unavailable();
		}
		$chk = ZAGR_Document::check( $req );
		if ( ! $chk['ok'] ) {
			return self::out( false, $chk['code'], $chk['message'] );
		}
		$r = $chk['req'];
		$row = ZAGR_Store::by_estimate( $r['estimate_id'] );
		if ( $row && (int) $row['client_id'] !== $r['client_id'] ) {
			return self::out( false, 'bad_request', 'This estimate\'s agreement belongs to another billing client.' );
		}
		if ( false === $r['language_ok'] && $row && ! empty( $row['signed_at'] ) ) {
			// a signed agreement (online or on paper) keeps its language answer: nothing changes here
			return self::out( false, 'language_kept', 'The customer already signed this agreement, so its language answer (Yes) stays as it is. Call the office if that answer was wrong.' );
		}
		if ( false === $r['language_ok'] ) {
			if ( $row && '0' !== (string) $row['language_ok'] && ZAGR_Key::usable() ) {
				ZAGR_Store::update( (int) $row['id'], array( 'language_ok' => 0 ) );
				ZAGR_Events::append( (int) $row['id'], 'language', array( 'answer' => 'no' ), self::actor() );
			}
			return self::out( false, 'not_language', ZAGR_Paper::not_language_words() );
		}
		// the readiness list for this agreement: the app's own items, and the legal packs of the job's state
		$missing = ZAGR_Settings::missing_for( ZAGR_Settings::mode(), ZAGR_Document::freeze( $r, (bool) $r['is_test'] ) );
		if ( $missing ) {
			return self::out( false, 'not_ready', 'Agreements aren\'t ready for this estimate. Still needed: ' . ZAGR_Settings::oxford( $missing ) . '.' );
		}
		if ( $row && 'cancelled' === $row['state'] ) {
			return self::out( false, 'cancelled', 'The customer cancelled this agreement. Nothing reopens it.', self::url_of( $row ), 'cancelled' );
		}
		return $row ? self::refresh( $row, $r ) : self::create( $r );
	}

	private static function actor(): string {
		$u = (int) get_current_user_id();
		return $u > 0 ? 'staff:' . $u : 'system';
	}

	/**
	 * The buyer's address as sealed for this estimate, when it differs from the one kept: the change is
	 * recorded as fingerprints of the old and new address, never the addresses. Null when nothing changes.
	 */
	private static function email_change( array $row, string $email ): ?string {
		$old = ZAGR_Store::email( $row );
		if ( null !== $old && strtolower( trim( $old ) ) === strtolower( trim( $email ) ) ) {
			return null;
		}
		$sealed = ZAGR_Key::seal( $email, 'email|' . (int) $row['estimate_id'] );
		if ( null === $sealed ) {
			return null;
		}
		ZAGR_Events::append( (int) $row['id'], 'email_changed', array( 'from' => null === $old ? '' : ZAGR_People::email_fp( $old ), 'to' => ZAGR_People::email_fp( $email ) ), self::actor() );
		return $sealed;
	}

	private static function url_of( array $row ): string {
		$w = ZAGR_Store::words( $row );
		return null === $w ? '' : ZAGR_Words::url( $w );
	}

	private static function create( array $r ): array {
		$words = '';
		for ( $i = 0; $i < 5; $i++ ) {
			$w = ZAGR_Words::generate();
			if ( '' === $w ) {
				return self::out( false, 'error', 'The link could not be made (the word list is too short).' );
			}
			if ( ! ZAGR_Store::by_link_hash( ZAGR_Words::hash( $w ) ) ) {
				$words = $w;
				break;
			}
		}
		if ( '' === $words ) {
			return self::out( false, 'error', 'The link could not be made.' );
		}
		$est  = (int) $r['estimate_id'];
		$doc  = ZAGR_Document::freeze( $r, (bool) $r['is_test'] );
		$sdoc = ZAGR_Key::seal( (string) wp_json_encode( $doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), 'doc|' . $est );
		$slnk = ZAGR_Key::seal( $words, 'link|' . $est );
		$smail = ZAGR_Key::seal( $r['buyer']['email'], 'email|' . $est );
		if ( null === $sdoc || null === $slnk || null === $smail ) {
			return self::out( false, 'not_ready', 'Agreements aren\'t ready. Still needed: the agreements key.' );
		}
		$id = ZAGR_Store::insert( array(
			'estimate_id'      => $est,
			'client_id'        => (int) $r['client_id'],
			'is_test'          => $r['is_test'] ? 1 : 0,
			'link_hash'        => ZAGR_Words::hash( $words ),
			'link_sealed'      => $slnk,
			'state'            => 'draft',
			'language_ok'      => true === $r['language_ok'] ? 1 : null,
			'doc_hash'         => $r['doc_hash'],
			'document_sealed'  => $sdoc,
			'schedule_json'    => (string) wp_json_encode( $r['schedule'] ),
			'template_version' => (string) $doc['facts']['template_version'],
			'plan_version'     => (string) $r['schedule']['plan_version'],
			'email_sealed'     => $smail,
		) );
		if ( ! $id ) {
			return self::out( false, 'error', 'The agreement could not be saved.' );
		}
		ZAGR_Events::append( $id, 'created', array(
			'doc_hash' => $r['doc_hash'], 'estimate_number' => (string) $r['document']['number'], 'template_version' => $doc['facts']['template_version'],
			'plan_version' => $r['schedule']['plan_version'], 'is_test' => (bool) $r['is_test'], 'mode' => ZAGR_Settings::mode(),
		), self::actor() );
		if ( true === $r['language_ok'] ) {
			ZAGR_Events::append( $id, 'language', array( 'answer' => 'yes' ), self::actor() );
		}
		return self::out( true, 'ok', '', ZAGR_Words::url( $words ), 'draft' );
	}

	private static function refresh( array $row, array $r ): array {
		$id  = (int) $row['id'];
		$est = (int) $row['estimate_id'];
		if ( true === $r['language_ok'] && '1' !== (string) $row['language_ok'] ) {
			ZAGR_Store::update( $id, array( 'language_ok' => 1 ) );
			ZAGR_Events::append( $id, 'language', array( 'answer' => 'yes' ), self::actor() );
		}
		$url = self::url_of( $row );
		$row = ZAGR_Store::refresh_window( ZAGR_Store::by_id( $id ) ?? $row );

		if ( in_array( $row['state'], array( 'draft', 'ready' ), true ) ) {
			if ( hash_equals( (string) $row['doc_hash'], $r['doc_hash'] ) ) {
				// Same words. A draft takes today's buyer details and Settings (nothing is signed yet); a ready one keeps
				// what the business signed. Either way the code goes to the billing client's address as it is now.
				$fields = array();
				$smail  = self::email_change( $row, $r['buyer']['email'] );
				if ( null !== $smail ) {
					$fields['email_sealed'] = $smail;
				}
				if ( 'draft' === $row['state'] ) {
					$doc  = ZAGR_Document::freeze( $r, (bool) $row['is_test'] );
					$sdoc = ZAGR_Key::seal( (string) wp_json_encode( $doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), 'doc|' . $est );
					if ( null !== $sdoc && ZAGR_Store::document( $row ) !== $doc ) {
						$fields['document_sealed']  = $sdoc;
						$fields['template_version'] = (string) $doc['facts']['template_version'];
					}
				}
				if ( $fields ) {
					ZAGR_Store::update( $id, $fields, array( $row['state'] ) );
				}
				return self::out( true, 'ok', '', $url, $row['state'] );
			}
			// A new doc_hash before the customer signs replaces the draft; the link stays the same.
			$doc   = ZAGR_Document::freeze( $r, (bool) $row['is_test'] );
			$sdoc  = ZAGR_Key::seal( (string) wp_json_encode( $doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), 'doc|' . $est );
			if ( null === $sdoc ) {
				return self::out( false, 'not_ready', 'Agreements aren\'t ready. Still needed: the agreements key.' );
			}
			$smail = self::email_change( $row, $r['buyer']['email'] ) ?? (string) $row['email_sealed'];
			$n = ZAGR_Store::update( $id, array(
				'doc_hash'             => $r['doc_hash'],
				'document_sealed'      => $sdoc,
				'email_sealed'         => $smail,
				'schedule_json'        => (string) wp_json_encode( $r['schedule'] ),
				'template_version'     => (string) $doc['facts']['template_version'],
				'plan_version'         => (string) $r['schedule']['plan_version'],
				'state'                => 'draft',
				'contractor_user_id'   => 0,
				'contractor_signed_at' => null,
			), array( 'draft', 'ready' ) );
			if ( 1 !== $n ) {
				return self::out( false, 'error', 'The customer is signing right now. Try again in a minute.' );
			}
			ZAGR_Events::append( $id, 'superseded', array( 'what' => 'draft', 'old_doc_hash' => $row['doc_hash'], 'new_doc_hash' => $r['doc_hash'] ), self::actor() );
			ZAGR_Store::sessions_reset( $id );
			return self::out( true, 'ok', '', $url, 'draft' );
		}

		if ( 'signing' === $row['state'] ) {
			return self::out( false, 'error', 'The customer is signing right now. Try again in a minute.' );
		}

		if ( in_array( $row['state'], ZAGR_Store::SIGNED_STATES, true ) ) {
			list( $signed_hash, $signed_doc ) = ZAGR_Store::signed_current( $row );
			$pending                          = ZAGR_Store::pending_change( $id );
			if ( $pending && 'signing' === $pending['state'] ) {
				return self::out( false, 'error', 'The customer is signing a change right now. Try again in a minute.' );
			}
			if ( hash_equals( $signed_hash, $r['doc_hash'] ) ) {
				if ( $pending ) { // the change was taken back: the signed agreement stands as it was
					ZAGR_Store::update_change( (int) $pending['id'], array( 'state' => 'superseded' ) );
					ZAGR_Events::append( $id, 'superseded', array( 'what' => 'change', 'change' => (int) $pending['seq'], 'back_to' => $signed_hash ), self::actor() );
					ZAGR_Store::update( $id, array( 'state' => empty( $row['window_closed_at'] ) ? 'signed' : 'window_closed' ), array( 'change_pending' ) );
					ZAGR_Store::sessions_reset( $id );
				}
				$row = ZAGR_Store::by_id( $id ) ?? $row;
				return self::out( true, 'ok', '', $url, $row['state'] );
			}
			if ( $pending && hash_equals( (string) $pending['doc_hash'], $r['doc_hash'] ) ) {
				return self::out( true, 'ok', '', $url, 'change_pending' );
			}
			// a new doc_hash after the customer signed is a change order, without a new window.
			$changes = ZAGR_Store::changes( $id );
			$seq     = $changes ? (int) end( $changes )['seq'] + 1 : 1;
			$doc     = ZAGR_Document::freeze( $r, (bool) $row['is_test'] );
			$sdoc    = ZAGR_Key::seal( (string) wp_json_encode( $doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), 'co-doc|' . $est . '|' . $seq );
			if ( null === $sdoc ) {
				return self::out( false, 'not_ready', 'Agreements aren\'t ready. Still needed: the agreements key.' );
			}
			if ( $pending ) {
				ZAGR_Store::update_change( (int) $pending['id'], array( 'state' => 'superseded' ) );
				ZAGR_Events::append( $id, 'superseded', array( 'what' => 'change', 'change' => (int) $pending['seq'], 'by' => $seq ), self::actor() );
			}
			$cid = ZAGR_Store::insert_change( array(
				'agreement_id'     => $id,
				'seq'              => $seq,
				'state'            => 'draft',
				'doc_hash'         => $r['doc_hash'],
				'document_sealed'  => $sdoc,
				'schedule_json'    => (string) wp_json_encode( $r['schedule'] ),
				'template_version' => (string) $doc['facts']['template_version'],
				'plan_version'     => (string) $r['schedule']['plan_version'],
			) );
			if ( ! $cid ) {
				// another request numbered a change order at the same moment (a double click): the same change is fine
				$again = ZAGR_Store::pending_change( $id );
				if ( $again && hash_equals( (string) $again['doc_hash'], $r['doc_hash'] ) ) {
					return self::out( true, 'ok', '', $url, 'change_pending' );
				}
				return self::out( false, 'error', 'The change order could not be saved. Try again in a minute.' );
			}
			$smail = self::email_change( $row, $r['buyer']['email'] );
			ZAGR_Store::update( $id, array_filter( array( 'state' => 'change_pending', 'email_sealed' => $smail ) ), ZAGR_Store::SIGNED_STATES );
			ZAGR_Events::append( $id, 'change_created', array( 'change' => $seq, 'doc_hash' => $r['doc_hash'], 'replaces' => $signed_hash ), self::actor() );
			ZAGR_Store::sessions_reset( $id );
			return self::out( true, 'ok', '', $url, 'change_pending' );
		}
		return self::out( false, 'error', 'The agreement is in a state that can\'t change.' );
	}

	/* ================================================================ contractor_sign */

	public static function contractor_sign( int $estimate_id, int $user_id, string $doc_hash ): array {
		try {
			return self::contractor_inner( $estimate_id, $user_id, $doc_hash );
		} catch ( \Throwable $e ) {
			error_log( 'Zorderz agreements: contractor_sign() failed (' . get_class( $e ) . ').' ); // phpcs:ignore
			return self::out( false, 'error', 'The business\'s signature could not be saved just now.' );
		}
	}

	private static function contractor_inner( int $estimate_id, int $user_id, string $doc_hash ): array {
		if ( 'off' === ZAGR_Settings::mode() ) {   // the same: off holds nothing up
			return self::out( false, 'mode_off', 'Agreements are off.' );
		}
		if ( ! ZAGR_Install::ready() ) {
			return self::unavailable();
		}
		$row = ZAGR_Store::by_estimate( $estimate_id );
		if ( ! $row ) {
			return self::out( false, 'bad_request', 'There is no agreement for this estimate. Make it ready first.' );
		}
		$url = self::url_of( $row );
		if ( ! ZAGR_People::is_staff( $user_id ) ) {
			return self::out( false, 'not_staff', 'Only staff can sign for the business.', $url, $row['state'] );
		}
		if ( ! self::mode_allows( (bool) $row['is_test'] ) ) {
			return self::out( false, 'mode_off', 'Agreements are off for this estimate.', $url, $row['state'] );
		}
		if ( '1' !== (string) $row['language_ok'] ) {
			return self::out( false, 'not_language', ZAGR_Paper::ask_language_words(), $url, $row['state'] );
		}
		if ( ! ZAGR_Key::usable() ) {
			return self::out( false, 'not_ready', 'Agreements aren\'t ready. Still needed: the agreements key.', $url, $row['state'] );
		}
		// the readiness list holds here too (an approval taken back, a fact cleared since ensure())
		$doc     = ZAGR_Store::document( $row );
		$missing = null === $doc ? array( 'the agreement\'s own words (they don\'t open)' ) : ZAGR_Settings::missing_for( ZAGR_Settings::mode(), $doc );
		if ( $missing ) {
			return self::out( false, 'not_ready', 'Agreements aren\'t ready for this estimate. Still needed: ' . ZAGR_Settings::oxford( $missing ) . '.', $url, $row['state'] );
		}
		if ( 'cancelled' === $row['state'] ) {
			return self::out( false, 'cancelled', 'The customer cancelled this agreement.', $url, 'cancelled' );
		}
		$id    = (int) $row['id'];
		$row   = ZAGR_Store::refresh_window( $row );
		$actor = 'staff:' . $user_id;
		if ( in_array( $row['state'], array( 'draft', 'ready' ), true ) ) {
			if ( ! hash_equals( (string) $row['doc_hash'], $doc_hash ) ) {
				return self::out( false, 'hash_mismatch', 'The estimate changed since this agreement was made. Make it ready again first.', $url, $row['state'] );
			}
			if ( 'ready' === $row['state'] ) {
				return self::out( true, 'ok', '', $url, 'ready' );
			}
			// only this draft, only this version: a draft replaced since the read above is not signed (one conditional UPDATE)
			global $wpdb;
			$n = (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . ZAGR_Install::t( 'agreements' ) . " SET state = 'ready', contractor_user_id = %d, contractor_signed_at = %s, updated_at = %s WHERE id = %d AND state = 'draft' AND doc_hash = %s", $user_id, ZAGR_Clock::utc(), ZAGR_Clock::utc(), $id, $doc_hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name (ZAGR_Install::tables()); every value is a placeholder
			if ( 1 !== $n ) {
				return self::out( false, 'hash_mismatch', 'The estimate changed while it was being signed. Make it ready again first.', $url, 'draft' );
			}
			ZAGR_Events::append( $id, 'contractor_signed', array( 'doc_hash' => $doc_hash, 'user_id' => $user_id ), $actor );
			return self::out( true, 'ok', '', $url, 'ready' );
		}
		if ( 'change_pending' === $row['state'] ) {
			$c = ZAGR_Store::pending_change( $id );
			if ( ! $c || ! hash_equals( (string) $c['doc_hash'], $doc_hash ) ) {
				return self::out( false, 'hash_mismatch', 'This isn\'t the change waiting to be signed. Make it ready again first.', $url, 'change_pending' );
			}
			if ( 'draft' === $c['state'] ) {
				if ( 1 !== ZAGR_Store::update_change( (int) $c['id'], array( 'state' => 'ready', 'contractor_user_id' => $user_id, 'contractor_signed_at' => ZAGR_Clock::utc() ), 'draft' ) ) {
					return self::out( false, 'error', 'The change order changed while it was being signed. Try again.', $url, 'change_pending' );
				}
				ZAGR_Events::append( $id, 'change_contractor_signed', array( 'change' => (int) $c['seq'], 'doc_hash' => $doc_hash, 'user_id' => $user_id ), $actor );
			}
			return self::out( true, 'ok', '', $url, 'change_pending' );
		}
		if ( in_array( $row['state'], array( 'signed', 'window_closed' ), true ) ) {
			list( $signed_hash ) = ZAGR_Store::signed_current( $row );
			return hash_equals( $signed_hash, $doc_hash )
				? self::out( true, 'ok', '', $url, $row['state'] )
				: self::out( false, 'hash_mismatch', 'The estimate changed after the customer signed. Make it ready again for a change order.', $url, $row['state'] );
		}
		return self::out( false, 'error', 'The customer is signing right now. Try again in a minute.', $url, $row['state'] );
	}

	/* ================================================================ reads */

	private static function iso( $utc ): string {
		$t = ZAGR_Clock::ts( (string) $utc );
		return $t ? gmdate( 'Y-m-d\TH:i:s\Z', $t ) : '';
	}

	/** Where the agreement stands. Never a name, email, IP or typed signature. */
	public static function status_for( int $estimate_id ): array {
		$none = array( 'ok' => false, 'state' => 'none', 'signed_at' => '', 'copy_received_at' => '', 'cancel_by' => '', 'ok_to_order' => false, 'change_pending' => false, 'url' => '', 'signable' => false, 'error' => false, 'unsignable_why' => '', 'made_at' => '', 'signed_via' => '', 'waiting_for' => '' );
		$failed          = $none;   // the same answer as no agreement, and error says it is a failure
		$failed['error'] = true;
		try {
			if ( ! ZAGR_Install::ready() ) {
				return $failed;
			}
			global $wpdb;
			$row = ZAGR_Store::by_estimate( $estimate_id );   // $wpdb clears last_error before each query
			if ( ! $row ) {
				// real $wpdb doesn't throw: a read that failed is an empty answer with last_error set
				return '' !== (string) $wpdb->last_error ? $failed : $none;
			}
			$row   = ZAGR_Paper::seen( ZAGR_Store::refresh_window( $row ) );   // a paper claim on a draft reads as the draft
			$state = 'signing' === $row['state'] ? 'ready' : (string) $row['state'];
			$cp    = 'change_pending' === $state;
			$wait  = ZAGR_Paper::awaiting_confirm( $row );   // a late paper signing an administrator must confirm
			return array(
				'ok'               => true,
				'state'            => $state,
				'signed_at'        => self::iso( $row['signed_at'] ),
				'copy_received_at' => self::iso( $row['copy_received_at'] ),
				'cancel_by'        => (string) ( $row['cancel_by'] ? substr( (string) $row['cancel_by'], 0, 10 ) : '' ),
				'ok_to_order'      => 'window_closed' === $state && ! empty( $row['window_closed_at'] ) && ! empty( $row['signed_at'] ) && ! $wait,
				'change_pending'   => $cp,
				'url'              => self::link_for( $estimate_id ),
				'signable'         => ZAGR_Public::signable( $row ),   // ready, and the customer could sign now
				'error'            => false,
				'unsignable_why'   => ZAGR_Public::unsignable_why( $row ),   // why not, '' when it can be
				'made_at'          => self::iso( $row['created_at'] ?? '' ),   // when it was made, UTC
				'signed_via'       => ZAGR_Paper::signed_via( $row ),   // online | paper | '' (additive)
				'waiting_for'      => $wait ? 'admin_confirm' : '',     // additive, last
			);
		} catch ( \Throwable $e ) {
			return $failed;
		}
	}

	/* ================================================================ signed on paper */

	/**
	 * The signed-in person records a paper signing on this estimate's agreement (ZAGR_Paper::record, from the app). $in:
	 * date (local Y-m-d), older (yes | no, where asked), disaster (yes | no, where asked and not yet answered), photos
	 * (ZDZ_User_Media ids, the person's own). Never throws.
	 */
	public static function record_paper( int $estimate_id, array $in ): array {
		try {
			return ZAGR_Paper::record( $estimate_id, (int) get_current_user_id(), array(
				'date' => $in['date'] ?? '', 'older' => $in['older'] ?? '', 'disaster' => $in['disaster'] ?? '', 'language_ok' => $in['language_ok'] ?? ( $in['english'] ?? '' ), 'photos' => is_array( $in['photos'] ?? null ) ? $in['photos'] : array(),
			), 'app' );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'code' => 'error', 'message' => 'The paper signing could not be recorded just now. Try again in a minute.' );
		}
	}

	/** Where a paper signing stands for the signed-in person (the estimate's button and line in the app). Never throws. */
	public static function paper_for( int $estimate_id ): array {
		// the same keys as the answer below, empty
		$out = array( 'ok' => false, 'can_record' => false, 'state' => 'none', 'signed_via' => '', 'signed_on' => '', 'cancel_by' => '', 'ok_to_order' => false, 'today' => '', 'first' => '', 'language_ok' => '', 'buyer_older' => false, 'asks_age' => false, 'waiting_for' => '' );
		try {
			$row = ZAGR_Install::ready() && $estimate_id > 0 ? ZAGR_Store::by_estimate( $estimate_id ) : null;
			if ( ! $row ) {
				return $out;
			}
			$row  = ZAGR_Store::refresh_window( $row );
			$b    = ZAGR_Paper::blocker( $row, (int) get_current_user_id() );
			$row  = ZAGR_Paper::seen( $row );   // a paper claim on a draft reads as the draft
			$wait = ZAGR_Paper::awaiting_confirm( $row );   // a late paper signing an administrator must confirm
			return array(
				'ok'          => true,
				'can_record'  => null === $b || 'busy' === $b[0],   // a customer signing online this minute: the button stays, Save says so
				'state'       => 'signing' === $row['state'] ? 'ready' : (string) $row['state'],
				'signed_via'  => ZAGR_Paper::signed_via( $row ),
				'signed_on'   => ZAGR_Paper::signed_on( $row ),
				'cancel_by'   => (string) ( $row['cancel_by'] ? substr( (string) $row['cancel_by'], 0, 10 ) : '' ),
				'ok_to_order' => 'window_closed' === $row['state'] && ! empty( $row['window_closed_at'] ) && ! empty( $row['signed_at'] ) && ! $wait,
				'today'       => ZAGR_Clock::today(),                              // the day picker's range is the one record() takes
				'first'       => ZAGR_Paper::first_day( ZAGR_Clock::today(), $row ),   // never before the estimate's own date
				'language_ok' => ZAGR_Paper::language_of( $row ),                  // '': the sheet asks, and record_paper() keeps the answer
				'buyer_older' => ZAGR_Paper::buyer_older( $row ),                 // the buyer answered 65 or older on the agreement page
				'asks_age'    => ZAGR_Paper::asks_age( $row ),                    // the job's legal packs ask whether any buyer is 65 or older
				'waiting_for' => $wait ? 'admin_confirm' : '',                     // an administrator confirms a late one first
			);
		} catch ( \Throwable $e ) {
			return $out;
		}
	}

	private static function payments( array $s ): array {
		$out = array();
		foreach ( ZAGR_Document::schedule_rows( $s ) as $r ) {
			$out[] = array( 'label' => $r[0], 'due' => $r[1], 'amount_cents' => $r[2] );
		}
		return $out;
	}

	private static function sched( array $s ): array {
		return array(
			'deposit_cents' => (int) ( $s['deposit_cents'] ?? 0 ),
			'balance_cents' => (int) ( $s['balance_cents'] ?? 0 ),
			'total_cents'   => (int) ( $s['total_cents'] ?? 0 ),
			'plan_version'  => (string) ( $s['plan_version'] ?? '' ),
			'payments'      => self::payments( $s + array( 'deposit_cents' => 0, 'balance_cents' => 0 ) ),
		);
	}

	/** The schedule as signed, or as offered when the customer hasn't signed yet; a pending change's beside it. */
	public static function schedule_for( int $estimate_id ): array {
		try {
			$row = ZAGR_Install::ready() ? ZAGR_Store::by_estimate( $estimate_id ) : null;
			if ( ! $row ) {
				return array( 'ok' => false, 'as' => '', 'deposit_cents' => 0, 'balance_cents' => 0, 'total_cents' => 0, 'plan_version' => '', 'payments' => array(), 'pending' => null );
			}
			$signed = ! empty( $row['signed_at'] );
			if ( $signed ) {
				$c = ZAGR_Store::last_signed_change( (int) $row['id'] );
				$s = $c ? ( json_decode( (string) $c['schedule_json'], true ) ?: array() ) : ZAGR_Store::schedule( $row );
			} else {
				$s = ZAGR_Store::schedule( $row );
			}
			$p = ZAGR_Store::pending_change( (int) $row['id'] );
			return array( 'ok' => true, 'as' => $signed ? 'signed' : 'offered' ) + self::sched( $s )
				+ array( 'pending' => $p ? self::sched( json_decode( (string) $p['schedule_json'], true ) ?: array() ) : null );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'as' => '', 'deposit_cents' => 0, 'balance_cents' => 0, 'total_cents' => 0, 'plan_version' => '', 'payments' => array(), 'pending' => null );
		}
	}

	/** The link for the notes, or ''. Never throws. */
	public static function link_for( int $estimate_id ): string {
		try {
			// an unsigned agreement has a link only where the mode allows one; a signed customer's link stays (their
			// status and Cancel live there, in every mode)
			$row = ZAGR_Install::ready() ? ZAGR_Store::by_estimate( $estimate_id ) : null;
			if ( ! $row || ( ! self::mode_allows( (bool) $row['is_test'] ) && empty( $row['signed_at'] ) ) ) {
				return '';
			}
			return self::url_of( $row );
		} catch ( \Throwable $e ) {
			return '';
		}
	}
}
