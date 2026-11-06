<?php
/**
 * ZAGR_Events - the record: append-only, one row per event, a keyed hash chain per agreement. [CORE]
 *
 * Each event keeps its UTC time (to the microsecond), its sequence number (1, 2, 3 … per agreement), who acted
 * (customer, staff:<user id>, or system), the IP address and user agent SEALED under the agreements key, a JSON detail
 * (typed names inside it are sealed too; a customer's step names its session, "sess"), the previous event's hash, and
 * its own hash:
 *
 *   hash = HMAC-SHA256( "agreements-chain|" + agreement_id \n seq \n type \n at_utc \n actor \n ip_sealed \n ua_sealed
 *          \n detail \n prev_hash ), under the agreements key's "agreements-chain" subkey
 *
 * The chain is keyed, so someone who can only write the database (an injection elsewhere, a leaked login, a restored
 * backup) can't edit or remove events and recompute it; verify() therefore needs the key, and says "can't be checked"
 * without it, never "intact". The head is anchored twice outside the events table: the agreement row (head_hash,
 * event_count) and an option of its own (zagr_anchor_<id>), both only ever moving forward; and the head at signing is
 * printed in the emailed copy, the head at a cancellation in the email to staff. The anchor { n, head, mac } carries an
 * HMAC of the agreement, n and head under the same chain subkey, so someone with the database alone can't set it back
 * after cutting off the record's tail; once an agreement has events, a missing anchor fails the check too. (Someone
 * who also kept an older anchor, a backup say, could put that back: the copies outside the site, the signed copy and the
 * staff cancellation email, are the check against that.)
 *
 * Only append() writes this table; nothing in the app updates or deletes a row.
 *
 * Events: created, superseded, language (whether the sale was negotiated in the agreement's language),
 * contractor_signed, viewed, consent_shown, pdf_opened, consent_given, code_sent, code_ok, code_failed, signed,
 * notice_acknowledged, pdf_sealed, copy_received, sign_failed, signed_copy_opened, cancelled, window_closed,
 * change_created, change_contractor_signed, change_signed, link_rotated, email_failed, email_changed, codes_locked,
 * codes_limited, codes_ceiling, codes_reset, session_done, age_answered (which notice the buyer's answer gives, never
 * the answer in the clear: that is sealed), copy_reissued, signed_on_paper (staff recorded a paper signing: the date,
 * the age answer sealed, the cancel-by day, who and where, the photos' ids and SHA-256s), paper_undone (an
 * administrator undid it, the reason sealed; or the request that recorded it died, or its save failed),
 * paper_late_confirmed (an administrator confirmed a paper signing recorded after its cancellation period had ended)
 * and staff_answer (a staff answer a legal pack asks for, given with a paper signing).
 * When the estimate is sent to the customer is the estimate app's to know; this app is never told.
 *
 * @package Zorderz\Agreements
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZAGR_Events {

	const GENESIS    = '0000000000000000000000000000000000000000000000000000000000000000';
	const ANCHOR_OPT = 'zagr_anchor_';

	const TYPES = array(
		'created', 'superseded', 'language', 'contractor_signed', 'viewed', 'consent_shown', 'pdf_opened', 'consent_given',
		'code_sent', 'code_ok', 'code_failed', 'signed', 'notice_acknowledged', 'pdf_sealed', 'copy_received',
		'sign_failed', 'signed_copy_opened', 'cancelled', 'window_closed', 'change_created', 'change_contractor_signed',
		'change_signed', 'link_rotated', 'email_failed', 'email_changed', 'codes_locked', 'codes_limited', 'codes_ceiling', 'codes_reset',
		'session_done', 'age_answered', 'copy_reissued',
		'signed_on_paper', 'paper_undone',   // a paper signing recorded by staff, and its undo (ZAGR_Paper)
		'paper_late_confirmed',              // an administrator confirmed a late paper signing
		'staff_answer',                      // a staff answer a legal pack asks, given with a paper signing
	);

	/** The canonical line an event's hash is taken over. */
	public static function line( array $e ): string {
		return implode( "\n", array(
			(string) (int) $e['agreement_id'], (string) (int) $e['seq'], (string) $e['type'], (string) $e['at_utc'],
			(string) $e['actor'], (string) $e['ip_sealed'], (string) $e['ua_sealed'], (string) $e['detail'], (string) $e['prev_hash'],
		) );
	}

	/** The event's keyed hash; null without a usable key. */
	public static function hash_of( array $e ): ?string {
		return ZAGR_Key::chain_mac( self::line( $e ) );
	}

	/** The anchor's MAC; null without a usable key. */
	public static function anchor_mac( int $agreement_id, int $n, string $head ): ?string {
		return ZAGR_Key::chain_mac( 'anchor|' . $agreement_id . '|' . $n . '|' . $head );
	}

	/**
	 * Append one event. $detail is plain data (never a name, email, IP or typed signature in the clear: seal those
	 * first). The request's IP and user agent are sealed here. With no usable key nothing is written (null): the record
	 * never holds them in the clear, and its chain can't be extended without the key. $session: the customer session
	 * that took the step (0 for staff and the system).
	 */
	public static function append( int $agreement_id, string $type, array $detail = array(), string $actor = 'system', int $session = 0 ): ?array {
		if ( ! in_array( $type, self::TYPES, true ) ) {
			throw new InvalidArgumentException( 'Unknown event type.' );
		}
		if ( ! ZAGR_Key::usable() ) {
			return null;
		}
		global $wpdb;
		$ip = ZAGR_People::request_ip();
		$ua = ZAGR_People::request_ua();
		$ip_sealed = '' === $ip ? '' : ZAGR_Key::seal( $ip, 'event-ip|' . $agreement_id );
		$ua_sealed = '' === $ua ? '' : ZAGR_Key::seal( $ua, 'event-ua|' . $agreement_id );
		if ( null === $ip_sealed || null === $ua_sealed ) {
			return null; // fail closed: no key, no record of who was there in the clear
		}
		if ( $session > 0 ) {
			$detail['sess'] = $session;
		}
		$json = (string) wp_json_encode( (object) $detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$t    = ZAGR_Install::t( 'events' );
		for ( $try = 0; $try < 3; $try++ ) {
			$tail = $wpdb->get_row( $wpdb->prepare( "SELECT seq, hash FROM $t WHERE agreement_id = %d ORDER BY seq DESC LIMIT 1", $agreement_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix and a fixed name (ZAGR_Install::tables()); every value is a placeholder
			$e = array(
				'agreement_id' => $agreement_id,
				'seq'          => $tail ? (int) $tail['seq'] + 1 : 1,
				'type'         => $type,
				'at_utc'       => ZAGR_Clock::utc_micro(),
				'actor'        => substr( $actor, 0, 40 ),
				'ip_sealed'    => $ip_sealed,
				'ua_sealed'    => $ua_sealed,
				'detail'       => $json,
				'prev_hash'    => $tail ? (string) $tail['hash'] : self::GENESIS,
			);
			$h = self::hash_of( $e );
			if ( null === $h ) {
				return null;
			}
			$e['hash'] = $h;
			$sup = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;
			$ok  = $wpdb->insert( $t, $e );
			if ( null !== $sup ) {
				$wpdb->suppress_errors( $sup );
			}
			if ( $ok ) {
				// the head only moves forward: two appends finishing out of order never pull it back
				$wpdb->query( $wpdb->prepare( 'UPDATE ' . ZAGR_Install::t( 'agreements' ) . ' SET head_hash = %s, event_count = %d, updated_at = %s WHERE id = %d AND event_count < %d', $e['hash'], $e['seq'], ZAGR_Clock::utc(), $agreement_id, $e['seq'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name (ZAGR_Install::tables()); every value is a placeholder
				$a = get_option( self::ANCHOR_OPT . $agreement_id, array() );
				if ( ! is_array( $a ) || (int) ( $a['n'] ?? 0 ) < $e['seq'] ) {
					update_option( self::ANCHOR_OPT . $agreement_id, array( 'n' => $e['seq'], 'head' => $e['hash'], 'mac' => (string) self::anchor_mac( $agreement_id, $e['seq'], $e['hash'] ) ), false );
				}
				return $e;
			}
			// Another request took this sequence number (the unique key): read the tail again.
		}
		return null;
	}

	/** Every event of an agreement, in order. */
	public static function all( int $agreement_id ): array {
		global $wpdb;
		$t = ZAGR_Install::t( 'events' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE agreement_id = %d ORDER BY seq ASC", $agreement_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix and a fixed name (ZAGR_Install::tables()); every value is a placeholder
	}

	/** The head as it stands: [ count, hash ]. */
	public static function head( int $agreement_id ): array {
		$rows = self::all( $agreement_id );
		$last = $rows ? end( $rows ) : null;
		return array( count( $rows ), $last ? (string) $last['hash'] : self::GENESIS );
	}

	/**
	 * Check the chain: { ok, count, bad_seq, reason, unchecked }. Needs the key. An edited, removed, reordered, inserted
	 * or re-hashed event, or a head that doesn't match the agreement row or the anchor, fails and names the first event
	 * that doesn't hold.
	 */
	public static function verify( int $agreement_id ): array {
		global $wpdb;
		$rows = self::all( $agreement_id );
		if ( ! ZAGR_Key::usable() ) {
			return array( 'ok' => false, 'count' => count( $rows ), 'bad_seq' => 0, 'reason' => 'The record can be checked only with the agreements key.', 'unchecked' => true );
		}
		$prev = self::GENESIS;
		$n    = 0;
		$at   = array();
		foreach ( $rows as $r ) {
			$n++;
			if ( (int) $r['seq'] !== $n ) {
				return array( 'ok' => false, 'count' => count( $rows ), 'bad_seq' => $n, 'reason' => 'An event is missing or out of order.', 'unchecked' => false );
			}
			if ( ! hash_equals( $prev, (string) $r['prev_hash'] ) ) {
				return array( 'ok' => false, 'count' => count( $rows ), 'bad_seq' => $n, 'reason' => 'An event does not follow the one before it.', 'unchecked' => false );
			}
			$h = self::hash_of( $r );
			if ( null === $h || ! hash_equals( $h, (string) $r['hash'] ) ) {
				return array( 'ok' => false, 'count' => count( $rows ), 'bad_seq' => $n, 'reason' => 'An event was changed after it was written.', 'unchecked' => false );
			}
			$prev     = (string) $r['hash'];
			$at[ $n ] = $prev;
		}
		$a = $wpdb->get_row( $wpdb->prepare( 'SELECT head_hash, event_count FROM ' . ZAGR_Install::t( 'agreements' ) . ' WHERE id = %d', $agreement_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name (ZAGR_Install::tables()); every value is a placeholder
		if ( $a && ( (int) $a['event_count'] !== $n || ( $n > 0 && ! hash_equals( (string) $a['head_hash'], $prev ) ) ) ) {
			return array( 'ok' => false, 'count' => $n, 'bad_seq' => $n + 1, 'reason' => 'The last events are missing.', 'unchecked' => false );
		}
		$anchor = get_option( self::ANCHOR_OPT . $agreement_id, array() );
		if ( $n > 0 && ( ! is_array( $anchor ) || empty( $anchor['n'] ) ) ) {
			return array( 'ok' => false, 'count' => $n, 'bad_seq' => $n, 'reason' => 'The record\'s anchor is missing.', 'unchecked' => false );
		}
		if ( is_array( $anchor ) && ! empty( $anchor['n'] ) ) {
			$an  = (int) $anchor['n'];
			$mac = self::anchor_mac( $agreement_id, $an, (string) ( $anchor['head'] ?? '' ) );
			if ( null === $mac || ! hash_equals( $mac, (string) ( $anchor['mac'] ?? '' ) ) ) {
				return array( 'ok' => false, 'count' => $n, 'bad_seq' => min( $an, $n + 1 ), 'reason' => 'The record\'s anchor was changed.', 'unchecked' => false );
			}
			if ( $an > $n || ! hash_equals( (string) ( $anchor['head'] ?? '' ), (string) ( $at[ $an ] ?? '' ) ) ) {
				return array( 'ok' => false, 'count' => $n, 'bad_seq' => min( $an, $n + 1 ), 'reason' => 'The record no longer reaches its anchored step.', 'unchecked' => false );
			}
		}
		return array( 'ok' => true, 'count' => $n, 'bad_seq' => 0, 'reason' => '', 'unchecked' => false );
	}

	/** Events of one type (newest last). */
	public static function of_type( int $agreement_id, string $type ): array {
		return array_values( array_filter( self::all( $agreement_id ), function ( $r ) use ( $type ) {
			return $r['type'] === $type;
		} ) );
	}

	/** Has the agreement an event of this type? (one indexed count) */
	public static function has( int $agreement_id, string $type ): bool {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . ZAGR_Install::t( 'events' ) . ' WHERE agreement_id = %d AND type = %s', $agreement_id, $type ) ) > 0; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name (ZAGR_Install::tables()); every value is a placeholder
	}
}
