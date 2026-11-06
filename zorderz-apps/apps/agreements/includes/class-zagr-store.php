<?php
/**
 * ZAGR_Store - reading and writing the agreements, change orders and sessions. [CORE]
 *
 * State changes that matter are conditional UPDATEs (WHERE state = …), so two requests can never both sign, both
 * cancel, or sign a draft that just changed. The window closes lazily (the first read after local midnight on
 * cancel_by) and from the hourly sweep, once, with a window_closed event. cancel_by is set only when the signed copy
 * was received (emailed, or opened on the page), so an agreement whose copy never arrived has no window counting yet
 * and is never OK to order.
 *
 * @package Zorderz\Agreements
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZAGR_Store {

	const SIGNED_STATES = array( 'signed', 'window_closed', 'change_pending' );

	/* ---------------------------------------------------------------- agreements */

	public static function count_all(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ZAGR_Install::t( 'agreements' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name (ZAGR_Install::tables()); the query takes no values
	}

	public static function by_id( int $id ): ?array {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ZAGR_Install::t( 'agreements' ) . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name (ZAGR_Install::tables()); every value is a placeholder
		return is_array( $r ) ? $r : null;
	}

	public static function by_estimate( int $estimate_id ): ?array {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ZAGR_Install::t( 'agreements' ) . ' WHERE estimate_id = %d', $estimate_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name (ZAGR_Install::tables()); every value is a placeholder
		return is_array( $r ) ? $r : null;
	}

	public static function by_link_hash( string $hash ): ?array {
		global $wpdb;
		if ( ! preg_match( '/^[0-9a-f]{64}$/', $hash ) ) {
			return null;
		}
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ZAGR_Install::t( 'agreements' ) . ' WHERE link_hash = %s', $hash ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name (ZAGR_Install::tables()); every value is a placeholder
		return ( is_array( $r ) && hash_equals( (string) $r['link_hash'], $hash ) ) ? $r : null;
	}

	public static function recent( int $limit = 50 ): array {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . ZAGR_Install::t( 'agreements' ) . ' ORDER BY id DESC LIMIT %d', max( 1, min( 500, $limit ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name (ZAGR_Install::tables()); every value is a placeholder
	}

	public static function insert( array $row ): int {
		global $wpdb;
		$now               = ZAGR_Clock::utc();
		$row['created_at'] = $now;
		$row['updated_at'] = $now;
		return $wpdb->insert( ZAGR_Install::t( 'agreements' ), $row ) ? (int) $wpdb->insert_id : 0;
	}

	/** Update one agreement; with $from_states, only while it is in one of them. Rows changed (0 or 1). */
	public static function update( int $id, array $fields, array $from_states = array() ): int {
		global $wpdb;
		$fields['updated_at'] = ZAGR_Clock::utc();
		$sets  = array();
		$args  = array();
		foreach ( $fields as $k => $v ) {
			if ( ! preg_match( '/^[a-z_0-9]+$/', $k ) ) {
				continue;
			}
			if ( null === $v ) {
				$sets[] = "$k = NULL";
				continue;
			}
			$sets[] = is_int( $v ) ? "$k = %d" : "$k = %s";
			$args[] = $v;
		}
		$sql    = 'UPDATE ' . ZAGR_Install::t( 'agreements' ) . ' SET ' . implode( ', ', $sets ) . ' WHERE id = %d';
		$args[] = $id;
		if ( $from_states ) {
			$sql .= ' AND state IN (' . implode( ',', array_fill( 0, count( $from_states ), '%s' ) ) . ')';
			$args = array_merge( $args, array_values( $from_states ) );
		}
		$n = (int) $wpdb->query( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql holds only checked column names, the table name ($wpdb->prefix and a fixed name) and placeholders
		// back to window_closed (a change order signed, or taken back, after the window had closed) is the
		// moment ok_to_order becomes true again: the OK-to-order alert, once per agreement (ZAGR_Alerts says when)
		if ( 1 === $n && 'window_closed' === ( $fields['state'] ?? null ) ) {
			ZAGR_Alerts::ok_to_order( $id );
		}
		return $n;
	}

	/** The frozen document, opened (null: no key, or it doesn't open). */
	public static function document( array $row ): ?array {
		$j = ZAGR_Key::open( (string) $row['document_sealed'], 'doc|' . (int) $row['estimate_id'] );
		$d = null === $j ? null : json_decode( $j, true );
		return is_array( $d ) ? $d : null;
	}

	public static function email( array $row ): ?string {
		return ZAGR_Key::open( (string) $row['email_sealed'], 'email|' . (int) $row['estimate_id'] );
	}

	public static function words( array $row ): ?string {
		return ZAGR_Key::open( (string) $row['link_sealed'], 'link|' . (int) $row['estimate_id'] );
	}

	public static function schedule( array $row ): array {
		$s = json_decode( (string) $row['schedule_json'], true );
		return is_array( $s ) ? $s : array();
	}

	/* ---------------------------------------------------------------- change orders */

	public static function changes( int $agreement_id ): array {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . ZAGR_Install::t( 'changes' ) . ' WHERE agreement_id = %d ORDER BY seq ASC', $agreement_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name (ZAGR_Install::tables()); every value is a placeholder
	}

	/** The change order waiting for signatures (draft or ready), or null. */
	public static function pending_change( int $agreement_id ): ?array {
		foreach ( array_reverse( self::changes( $agreement_id ) ) as $c ) {
			if ( in_array( $c['state'], array( 'draft', 'ready', 'signing' ), true ) ) {
				return $c;
			}
		}
		return null;
	}

	/** The last change order the customer signed, or null. */
	public static function last_signed_change( int $agreement_id ): ?array {
		foreach ( array_reverse( self::changes( $agreement_id ) ) as $c ) {
			if ( 'signed' === $c['state'] ) {
				return $c;
			}
		}
		return null;
	}

	public static function change_document( array $agreement, array $c ): ?array {
		$j = ZAGR_Key::open( (string) $c['document_sealed'], 'co-doc|' . (int) $agreement['estimate_id'] . '|' . (int) $c['seq'] );
		$d = null === $j ? null : json_decode( $j, true );
		return is_array( $d ) ? $d : null;
	}

	public static function insert_change( array $row ): int {
		global $wpdb;
		$now               = ZAGR_Clock::utc();
		$row['created_at'] = $now;
		$row['updated_at'] = $now;
		// two change orders can't share a number; a clash returns 0
		$sup = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;
		$ok  = $wpdb->insert( ZAGR_Install::t( 'changes' ), $row );
		if ( null !== $sup ) {
			$wpdb->suppress_errors( $sup );
		}
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function update_change( int $id, array $fields, string $from_state = '' ): int {
		global $wpdb;
		$fields['updated_at'] = ZAGR_Clock::utc();
		$sets = array();
		$args = array();
		foreach ( $fields as $k => $v ) {
			if ( ! preg_match( '/^[a-z_0-9]+$/', $k ) ) {
				continue;   // a column name only, as update() takes them
			}
			if ( null === $v ) {
				$sets[] = "$k = NULL";
				continue;
			}
			$sets[] = is_int( $v ) ? "$k = %d" : "$k = %s";
			$args[] = $v;
		}
		$sql    = 'UPDATE ' . ZAGR_Install::t( 'changes' ) . ' SET ' . implode( ', ', $sets ) . ' WHERE id = %d';
		$args[] = $id;
		if ( '' !== $from_state ) {
			$sql   .= ' AND state = %s';
			$args[] = $from_state;
		}
		return (int) $wpdb->query( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql holds only checked column names, the table name ($wpdb->prefix and a fixed name) and placeholders
	}

	/** The document and schedule as the customer last signed them: [ doc_hash, document, schedule ]. */
	public static function signed_current( array $row ): array {
		$c = self::last_signed_change( (int) $row['id'] );
		if ( $c ) {
			return array( (string) $c['doc_hash'], self::change_document( $row, $c ), json_decode( (string) $c['schedule_json'], true ) ?: array() );
		}
		return array( (string) $row['doc_hash'], self::document( $row ), self::schedule( $row ) );
	}

	/* ---------------------------------------------------------------- the window */

	/**
	 * A signature being saved holds the row in "signing" for a moment. A request that died there (a fatal error, a
	 * timeout) would hold it for good: after 5 minutes it is ready again, with sign_failed in the record.
	 */
	public static function heal( array $row ): array {
		global $wpdb;
		$old = ZAGR_Clock::utc( ZAGR_Clock::now() - 300 );
		$id  = (int) $row['id'];
		// a paper signing's claim goes back to the state it was in (a draft stays a draft)
		if ( 'signing' === $row['state'] && (string) $row['updated_at'] < $old && class_exists( 'ZAGR_Paper' ) && ZAGR_Paper::heal_claim( $row ) ) {
			$row = self::by_id( $id ) ?? $row;
		}
		if ( 'signing' === $row['state'] && (string) $row['updated_at'] < $old ) {
			$n = $wpdb->query( $wpdb->prepare( 'UPDATE ' . ZAGR_Install::t( 'agreements' ) . " SET state = 'ready', updated_at = %s WHERE id = %d AND state = 'signing' AND updated_at < %s", ZAGR_Clock::utc(), $id, $old ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name (ZAGR_Install::tables()); every value is a placeholder
			if ( 1 === (int) $n ) {
				ZAGR_Events::append( $id, 'sign_failed', array( 'why' => 'interrupted' ), 'system' );
			}
			$row = self::by_id( $id ) ?? $row;
		}
		if ( 'change_pending' === $row['state'] ) {
			$c = self::pending_change( $id );
			if ( $c && 'signing' === $c['state'] && (string) $c['updated_at'] < $old && 1 === self::update_change( (int) $c['id'], array( 'state' => 'ready' ), 'signing' ) ) {
				ZAGR_Events::append( $id, 'sign_failed', array( 'change' => (int) $c['seq'], 'why' => 'interrupted' ), 'system' );
			}
		}
		return $row;
	}

	/** Close the window if its day has passed (once, with an event). Returns the row as it now stands. */
	public static function refresh_window( array $row ): array {
		$row = self::heal( $row );
		if ( ! in_array( $row['state'], array( 'signed', 'change_pending' ), true ) || empty( $row['cancel_by'] ) || ! empty( $row['window_closed_at'] ) ) {
			return $row;
		}
		$cancel_by = substr( (string) $row['cancel_by'], 0, 10 );
		if ( ZAGR_Calendar::window_open( $cancel_by, ZAGR_Clock::now() ) ) {
			return $row;
		}
		global $wpdb;
		$t   = ZAGR_Install::t( 'agreements' );
		$now = ZAGR_Clock::utc();
		$n   = $wpdb->query( $wpdb->prepare( "UPDATE $t SET window_closed_at = %s, state = CASE WHEN state = 'signed' THEN 'window_closed' ELSE state END, updated_at = %s WHERE id = %d AND window_closed_at IS NULL AND state IN ('signed','change_pending')", $now, $now, (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix and a fixed name (ZAGR_Install::tables()); every value is a placeholder
		if ( 1 === (int) $n ) {
			ZAGR_Events::append( (int) $row['id'], 'window_closed', array( 'cancel_by' => $cancel_by ), 'system' );
			// "OK to order" to staff, from whichever read closed it (a page view, a status read, the sweep).
			// With a change order waiting, ok_to_order is still false: the alert waits for that change (update(), above).
			ZAGR_Alerts::ok_to_order( (int) $row['id'] );
		}
		return self::by_id( (int) $row['id'] ) ?? $row;
	}

	/**
	 * The hourly sweep: close every window whose day has passed; write a window_closed event that couldn't be written
	 * when the window closed; and remove codes and sessions older than two days.
	 */
	public static function sweep_windows(): void {
		global $wpdb;
		if ( ! ZAGR_Install::ready() ) {
			return;   // never a pre-release build's tables
		}
		$t    = ZAGR_Install::t( 'agreements' );
		$e    = ZAGR_Install::t( 'events' );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE state IN ('signed','change_pending') AND window_closed_at IS NULL AND cancel_by IS NOT NULL AND cancel_by < %s LIMIT 200", ZAGR_Clock::today() ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix and a fixed name (ZAGR_Install::tables()); every value is a placeholder
		foreach ( $rows as $r ) {
			self::refresh_window( $r );
		}
		if ( ZAGR_Key::usable() ) {
			$late = (array) $wpdb->get_results( "SELECT id, cancel_by, window_closed_at FROM $t a WHERE window_closed_at IS NOT NULL AND NOT EXISTS (SELECT 1 FROM $e WHERE agreement_id = a.id AND type = 'window_closed') LIMIT 200", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are $wpdb->prefix and fixed names (ZAGR_Install::tables()); the query takes no values
			foreach ( $late as $r ) {
				ZAGR_Events::append( (int) $r['id'], 'window_closed', array( 'cancel_by' => substr( (string) $r['cancel_by'], 0, 10 ), 'closed_at' => (string) $r['window_closed_at'], 'written_late' => true ), 'system' );
			}
		}
		// sessions go two days after their last use; codes thirty (a code used from an address marks that address as the
		// customer's for the agreement-wide cap)
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . ZAGR_Install::t( 'codes' ) . ' WHERE created_at < %s', ZAGR_Clock::utc( ZAGR_Clock::now() - ZAGR_Codes::PROVEN_DAYS * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name (ZAGR_Install::tables()); every value is a placeholder
		$old = ZAGR_Clock::utc( ZAGR_Clock::now() - 2 * DAY_IN_SECONDS );
		// a swept session's kept name goes with it
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id FROM ' . ZAGR_Install::t( 'sessions' ) . ' WHERE last_seen_at < %s LIMIT 1000', $old ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name (ZAGR_Install::tables()); every value is a placeholder
			ZAGR_Flow::forget_name( (int) $r['id'] );
		}
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . ZAGR_Install::t( 'sessions' ) . ' WHERE last_seen_at < %s', $old ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name (ZAGR_Install::tables()); every value is a placeholder
		// staff alerts left undone (every send threw, or a filter did) are tried again, for 24 hours
		ZAGR_Alerts::retry();
	}

	/* ---------------------------------------------------------------- sessions */

	public static function session_get( int $agreement_id, string $sid ): ?array {
		global $wpdb;
		if ( ! preg_match( '/^[A-Za-z0-9_-]{43}$/', $sid ) ) {
			return null;
		}
		$h = hash( 'sha256', 'zagr-session|' . $sid );
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ZAGR_Install::t( 'sessions' ) . ' WHERE sid_hash = %s AND agreement_id = %d', $h, $agreement_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name (ZAGR_Install::tables()); every value is a placeholder
		if ( ! is_array( $r ) || ! hash_equals( (string) $r['sid_hash'], $h ) ) {
			return null;
		}
		if ( ZAGR_Clock::ts( (string) $r['last_seen_at'] ) < ZAGR_Clock::now() - 12 * HOUR_IN_SECONDS ) {
			return null; // a session idle for 12 hours is over
		}
		return $r;
	}

	/**
	 * A new session: [ the cookie's value (shown to no one but the browser), the row ]. Made only for a
	 * real step (the PDF opened, or a form posted), never for a page view.
	 */
	public static function session_create( int $agreement_id ): array {
		global $wpdb;
		$sid = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		$now = ZAGR_Clock::utc();
		$wpdb->insert( ZAGR_Install::t( 'sessions' ), array(
			'agreement_id' => $agreement_id,
			'sid_hash'     => hash( 'sha256', 'zagr-session|' . $sid ),
			'created_at'   => $now,
			'last_seen_at' => $now,
			'ip_tag'       => ZAGR_People::ip_tag(),
		) );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ZAGR_Install::t( 'sessions' ) . ' WHERE id = %d', (int) $wpdb->insert_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name (ZAGR_Install::tables()); every value is a placeholder
		return array( $sid, is_array( $row ) ? $row : array() );
	}

	/** Sessions this address made on this agreement in the last hour. */
	public static function sessions_made( int $agreement_id, string $ip_tag ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . ZAGR_Install::t( 'sessions' ) . ' WHERE agreement_id = %d AND ip_tag = %s AND created_at > %s', $agreement_id, $ip_tag, ZAGR_Clock::utc( ZAGR_Clock::now() - HOUR_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name (ZAGR_Install::tables()); every value is a placeholder
	}

	public static function session_update( int $id, array $fields ): void {
		global $wpdb;
		$wpdb->update( ZAGR_Install::t( 'sessions' ), $fields, array( 'id' => $id ) );
	}

	public static function session_by_id( int $id ): ?array {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ZAGR_Install::t( 'sessions' ) . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name (ZAGR_Install::tables()); every value is a placeholder
		return is_array( $r ) ? $r : null;
	}

	/** The document changed: every session's progress on the old one ends. */
	public static function sessions_reset( int $agreement_id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . ZAGR_Install::t( 'sessions' ) . " SET pdf_for = '', consent_for = '', verified_purpose = '', verified_for = '', verified_at = NULL, consent_shown_for = '' WHERE agreement_id = %d", $agreement_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name (ZAGR_Install::tables()); every value is a placeholder
	}
}
