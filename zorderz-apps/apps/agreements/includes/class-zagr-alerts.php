<?php
/**
 * ZAGR_Alerts - four notices to staff through the platform's alert router (ZDZ_Alert_Router). [CORE mechanism; the words
 * are in ZAGR_Strings]
 *
 * The go sign to order is "agreement signed + cancellation period closed" (status_for's ok_to_order); these tell staff
 * when it moves:
 *
 *   signed        when the signed copy reached the customer (copy_received: the email taken with its PDF, or the PDF
 *                 opened on the page), so the cancel-by day is known. "An agreement was signed": the estimate number,
 *                 the cancel-by day, and the next step (send the deposit invoice for the deposit the customer signed).
 *   ok_to_order   when ok_to_order first becomes true: the window closed (a page view, a status read, or the hourly
 *                 sweep) with no change order waiting, or, when the window closed while one was waiting, the moment that
 *                 change order is signed or taken back. "OK to order": the estimate number; the period has ended.
 *   cancelled     when the agreement is cancelled (the customer's Cancel on the page, or staff recording an email or a
 *                 letter). "An agreement was cancelled": the estimate number and what to do, in the staff email's words.
 *   signed_on_paper  when staff record that the customer signed on paper (ZAGR_Paper): the estimate number, the paper
 *                 date, the cancel-by day and who recorded it (staff), never the customer's name; to the administrators
 *                 (not the one who recorded it); its link opens the agreement's row in Settings, Agreements, where an
 *                 administrator sees the photos. Once per paper signing (option ..._p<the record's step>), so one
 *                 recorded again after an undo is told. It says when the paper date is earlier than the day it was
 *                 recorded, and for one recorded after its cancellation period had ended, that ordering waits until an
 *                 administrator confirms it in Settings; "OK to order" is not due until then (due()), and the
 *                 confirmation sends it once, as a window closing does. A paper signing never sends "An agreement was
 *                 signed" (the salesperson recorded it themselves).
 *
 * WHO: the salesperson, which here is the staff member who signed for the business at Approve (contractor_user_id;
 * the agreement keeps the salesperson's name for the paper, not their account). The cancellations address already gets
 * the staff cancellation email (ZAGR_Mailer), so nothing is added for it. A recipient must be a current staff member
 * (ZAGR_People::is_staff(): never the shared device, a viewer, or an account whose role is gone); when the signer isn't
 * one, the administrators are told instead, and when nobody received an alert (the router delivered to no one), the
 * administrators once, so an alert is never lost quietly. The filter zagr_alert_recipients( $people, $event,
 * $estimate_id ) may add staff. At most 8 people. The router's own setting (zdz_alert_channel_config) decides the
 * channels, not the people. Default channels: the bell and push, never email unless an administrator turns it on.
 *
 * WHAT IT NEVER SAYS: no customer name, email, address or signature, in a title, a message, the link or a log line:
 * the estimate number only. A title shows on a lock screen (the router pushes the title only).
 *
 * ONCE per agreement and transition: option zagr_alerted_<event>_<agreement id>, and for OK to order again after a
 * change order settled (signed, or withdrawn) once the window had closed, _c<that change order's number>. So a page
 * view, a status read or a sweep never repeats one, and each return to OK is said once.
 *
 * THE CLAIM COMES AFTER THE WORK: the words, the people and their links are worked out first; then the claim is written
 * with add_option(), and a request whose add_option() fails (another one claimed it) sends nothing. Each trigger is one
 * conditional UPDATE that only one request wins; add_option() is not a lock, so the claim stops repeats, not a race the
 * trigger already prevents.
 *
 * A SEND THAT THROWS MAY HAVE BEEN DELIVERED: the router writes the bell, then the email, then the push, so a throw
 * usually comes after the bell row was written. So after a throw, and before any resend, the person's bell is read
 * (ZDZ_Alert_Router::get_notifications(); the row carries this alert's claim key): the row there = told, never rung
 * again; no row = pending, for the sweep; can't be read = maybe, never sent again. The administrators are asked (once)
 * when the router delivered to no channel for everyone, never because of a throw; or, if that never happened, when the
 * sweep gives up with nobody told (below).
 *
 * THE HOURLY SWEEP (retry(), through ZAGR_Store::sweep_windows()) takes up the claims left undone (pending people, or a
 * failure before the claim: a filter that threw) found by their own option names (zagr_alerted_..., done false, not
 * stopped; at most 50 a sweep; no shared list). It tries again for 24 hours from the first try, then gives up: the
 * log, and the claim (stopped: gave_up); when nobody was told on any channel by then, the administrators are asked once
 * first. A moment that is past (the agreement moved on) is stopped too (moved_on). The agreement's record is not
 * written: it is the customer's, printed in their signed copy.
 *
 * NEVER IN THE WAY: no router: nothing at all, silently, and nothing is claimed. Nothing here throws into the
 * customer's step or the record: everything is caught, and it runs after the state and the event are saved.
 *
 * THE LINK: zagr_alert_link( '', $estimate_id, $user_id, $event ) may answer a page on this site for that person (a
 * jobs app may answer the job, for someone who may open it). Only a same-site link is kept, never one into the billing
 * system; with none, the router opens the app's home.
 *
 * @package Zorderz\Agreements
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZAGR_Alerts {

	const SIGNED     = 'zagr_agreement_signed';
	const OK         = 'zagr_ok_to_order';
	const CANCELLED  = 'zagr_agreement_cancelled';
	const PAPER      = 'zagr_signed_on_paper';
	const SENT_OPT    = 'zagr_alerted_';      // + event + '_' + agreement id [+ '_c' + change order number]: the claim
	const RETRY_FOR   = 86400;                 // 24 hours from the first try
	const SWEEP_MAX   = 50;                    // undone claims the hourly sweep takes up at most
	const BELL_LOOK   = 200;                   // a person's newest bell rows read to find this alert's (has_bell())
	const MAX_PEOPLE  = 8;
	const ADMIN_ROLES = array( 'administrator', 'zdz_owner', 'zdz_admin' );

	/** event => alert type */
	const TYPES = array( 'signed' => self::SIGNED, 'ok_to_order' => self::OK, 'cancelled' => self::CANCELLED, 'signed_on_paper' => self::PAPER );

	public static function boot(): void {
		add_action( 'zdz_register_alert_types', array( __CLASS__, 'register_types' ) );
	}

	/** The four types: category sales; the bell and push; never email by default. */
	public static function register_types( $router ): void {
		if ( ! is_object( $router ) || ! method_exists( $router, 'register_type' ) ) {
			return;
		}
		$router->register_type( self::SIGNED, array(
			'label'            => 'An agreement was signed',
			'description'      => 'A customer signed the agreement you signed for the business and has the signed copy: the cancel-by day, and the deposit invoice to send.',
			'default_channels' => array( 'in_app', 'push' ),
			'source_plugin'    => 'agreements',
			'category'         => 'sales',
		) );
		$router->register_type( self::OK, array(
			'label'            => 'OK to order',
			'description'      => 'The cancellation period of an agreement you signed for the business has ended, so work on its order can go ahead.',
			'default_channels' => array( 'in_app', 'push' ),
			'source_plugin'    => 'agreements',
			'category'         => 'sales',
		) );
		$router->register_type( self::CANCELLED, array(
			'label'            => 'An agreement was cancelled',
			'description'      => 'A customer cancelled an agreement you signed for the business: refund in the time the law allows, and order nothing.',
			'default_channels' => array( 'in_app', 'push' ),
			'source_plugin'    => 'agreements',
			'category'         => 'sales',
		) );
		// for the administrators
		$router->register_type( self::PAPER, array(
			'label'            => 'Signed on paper',
			'description'      => 'Staff recorded that a customer signed the paper agreement: the photo of the signature page is there to look at.',
			'default_channels' => array( 'in_app', 'push' ),
			'source_plugin'    => 'agreements',
			'category'         => 'sales',
		) );
	}

	public static function router(): bool {
		return class_exists( 'ZDZ_Alert_Router' ) && method_exists( 'ZDZ_Alert_Router', 'send' );
	}

	/* ------------------------------------------------------------------ the three moments */

	/** The signed copy reached the customer (ZAGR_Flow::mark_received). */
	public static function signed( int $agreement_id ): void {
		self::fire( 'signed', $agreement_id );
	}

	/** ok_to_order may have become true (the window closed, or a change order was signed or taken back after it had). */
	public static function ok_to_order( int $agreement_id ): void {
		self::fire( 'ok_to_order', $agreement_id );
	}

	/** The agreement was cancelled (ZAGR_Flow::do_cancel). */
	public static function cancelled( int $agreement_id ): void {
		self::fire( 'cancelled', $agreement_id );
	}

	/** staff recorded a paper signing (ZAGR_Paper::record). */
	public static function signed_on_paper( int $agreement_id ): void {
		self::fire( 'signed_on_paper', $agreement_id );
	}

	/* ------------------------------------------------------------------ is it that moment? (pure) */

	/** Does the row stand where this event's alert speaks of it? */
	public static function due( string $event, array $row ): bool {
		$state = (string) ( $row['state'] ?? '' );
		if ( 'signed' === $event ) {
			return in_array( $state, array( 'signed', 'change_pending', 'window_closed' ), true )
				&& ! empty( $row['signed_at'] ) && ! empty( $row['copy_received_at'] ) && '' !== self::cancel_by( $row )
				&& ! ZAGR_Paper::is_paper( $row );   // never for a paper signing (its own alert, below)
		}
		if ( 'signed_on_paper' === $event ) {   // signed on paper, not undone
			return in_array( $state, array( 'signed', 'change_pending', 'window_closed' ), true ) && ZAGR_Paper::is_paper( $row ) && '' !== self::cancel_by( $row );
		}
		if ( 'ok_to_order' === $event ) {   // status_for's ok_to_order
			return 'window_closed' === $state && ! empty( $row['window_closed_at'] ) && ! empty( $row['signed_at'] ) && ! ZAGR_Paper::awaiting_confirm( $row );
		}
		if ( 'cancelled' === $event ) {
			return 'cancelled' === $state;
		}
		return false;
	}

	private static function cancel_by( array $row ): string {
		$by = substr( (string) ( $row['cancel_by'] ?? '' ), 0, 10 );
		return ZAGR_Calendar::valid( $by ) ? $by : '';
	}

	/** The change order that settled last (signed, or withdrawn: superseded), or null. */
	public static function settled_change( int $agreement_id ): ?array {
		$last = null;
		foreach ( ZAGR_Store::changes( $agreement_id ) as $c ) {   // oldest first (seq)
			if ( in_array( (string) $c['state'], array( 'signed', 'superseded' ), true ) ) {
				$last = $c;
			}
		}
		return $last;
	}

	/** OK to order again: the change order settled once the window had closed (null: this is the first time). */
	private static function again( array $row ): ?array {
		if ( empty( $row['window_closed_at'] ) || empty( $row['id'] ) ) {
			return null;
		}
		$c = self::settled_change( (int) $row['id'] );
		return $c && (string) $c['updated_at'] >= (string) $row['window_closed_at'] ? $c : null;
	}

	/** The claim's option: one per agreement and event, and one per return to OK after a change order. */
	public static function key( string $event, array $row ): string {
		$k = self::SENT_OPT . $event . '_' . (int) $row['id'];
		if ( 'signed_on_paper' === $event ) {   // one per paper signing (one recorded again after an undo is told)
			$p = ZAGR_Paper::current( $row );
			return $k . '_p' . (int) ( $p['seq'] ?? 0 );
		}
		$c = 'ok_to_order' === $event ? self::again( $row ) : null;
		return null === $c ? $k : $k . '_c' . (int) $c['seq'];
	}

	/* ------------------------------------------------------------------ the words (pure but for the number) */

	/** The estimate number as frozen in the agreement; without the key, as the record's first step wrote it; else ''. */
	public static function number( array $row ): string {
		$n   = '';
		$doc = ZAGR_Key::usable() ? ZAGR_Store::document( $row ) : null;
		if ( is_array( $doc ) ) {
			$n = (string) ( $doc['document']['number'] ?? '' );
		}
		if ( '' === $n && ! empty( $row['id'] ) ) {
			foreach ( ZAGR_Events::of_type( (int) $row['id'], 'created' ) as $e ) {
				$d = json_decode( (string) $e['detail'], true );
				$n = is_array( $d ) ? (string) ( $d['estimate_number'] ?? '' ) : '';
				break;
			}
		}
		$n = ltrim( trim( $n ), '#' );
		return preg_match( '/^[A-Za-z0-9-]{1,20}$/', $n ) ? $n : '';
	}

	/** The deposit the customer signed for, in cents (the last signed change order's, if any; else the agreement's). */
	public static function deposit_cents( array $row ): int {
		$c = ZAGR_Store::last_signed_change( (int) $row['id'] );
		$s = $c ? ( json_decode( (string) $c['schedule_json'], true ) ?: array() ) : ZAGR_Store::schedule( $row );
		return max( 0, (int) ( $s['deposit_cents'] ?? 0 ) );
	}

	/** @return array{title:string,message:string} */
	public static function words( string $event, array $row ): array {
		$num  = self::number( $row );
		$est  = ZAGR_Strings::t( '' !== $num ? 'alert_estimate' : 'alert_estimate_none', array( 'number' => $num ) );
		$test = ! empty( $row['is_test'] ) ? ' ' . ZAGR_Strings::t( 'alert_test' ) : '';
		if ( 'signed' === $event ) {
			$dep  = self::deposit_cents( $row );
			$next = $dep > 0 ? ZAGR_Strings::t( 'alert_signed_next', array( 'deposit' => ZAGR_Document::money( $dep ) ) ) : ZAGR_Strings::t( 'alert_signed_no_deposit' );
			return array(
				'title'   => ZAGR_Strings::t( 'alert_signed_title' ),
				'message' => $est . ' ' . ZAGR_Strings::t( 'alert_signed_body', array( 'cancel_by' => ZAGR_Calendar::long_date( self::cancel_by( $row ) ) ) ) . ' ' . $next . $test,
			);
		}
		if ( 'signed_on_paper' === $event ) {   // the date, the cancel-by day, who recorded it and where
			$p   = ZAGR_Paper::current( $row );
			$who = ZAGR_People::name( (int) ( $p['by'] ?? 0 ) );
			$by  = self::cancel_by( $row );
			// a cancel-by day already gone is said in the past tense, never "can cancel until" it
			$gone = '' !== $by && ! ZAGR_Calendar::window_open( $by, ZAGR_Clock::now() );
			return array(
				'title'   => ZAGR_Strings::t( 'alert_paper_title' ),
				'message' => $est . ' ' . ZAGR_Strings::t( $gone ? 'alert_paper_body_ended' : 'alert_paper_body', array(
					'date'      => ZAGR_Calendar::long_date( ZAGR_Paper::signed_on( $row ) ),
					'who'       => '' !== $who ? $who : ZAGR_Strings::t( 'alert_paper_staff' ),
					'where'     => ZAGR_Strings::t( 'admin' === ( $p['via'] ?? '' ) ? 'alert_paper_in_admin' : 'alert_paper_in_app' ),
					'cancel_by' => ZAGR_Calendar::long_date( self::cancel_by( $row ) ),
				) ) . ( '' !== ZAGR_Paper::late_words( (array) $p ) ? ' ' . ZAGR_Paper::late_words( (array) $p ) : '' )
					. ( ZAGR_Paper::awaiting_confirm( $row ) ? ' ' . ZAGR_Paper::WAITS : '' ) . $test,
			);
		}
		if ( 'ok_to_order' === $event ) {
			$c    = self::again( $row );   // OK again after a change order says why
			$body = null === $c ? 'alert_ok_body' : ( 'signed' === (string) $c['state'] ? 'alert_ok_again_signed' : 'alert_ok_again_withdrawn' );
			// OK to order because an administrator just confirmed a late paper signing (nothing settled since)
			$conf = ZAGR_Paper::is_paper( $row ) ? ZAGR_Paper::confirmation( $row ) : null;
			if ( null !== $conf && ( null === $c || substr( (string) $conf['at'], 0, 19 ) >= (string) $c['updated_at'] ) ) {
				$body = 'alert_ok_confirmed';
			}
			return array( 'title' => ZAGR_Strings::t( 'alert_ok_title' ), 'message' => $est . ' ' . ZAGR_Strings::t( $body ) . $test );
		}
		$doc = ZAGR_Key::usable() ? ZAGR_Store::document( $row ) : null;
		return array( 'title' => ZAGR_Strings::t( 'alert_cancelled_title' ), 'message' => $est . ' ' . ZAGR_Strings::t( 'alert_cancelled_body', array( 'refund' => ZAGR_Strings::refund_words( null === $doc ? array() : ZAGR_Document::stack( $doc ) ) ) ) . $test );
	}

	/* ------------------------------------------------------------------ who */

	/**
	 * A person who can be told: a current staff member (ZAGR_People::is_staff(): the staff or admin tier,
	 * which is never the shared device, a viewer, or an account that is gone or has lost its platform role).
	 */
	private static function usable( int $uid ): bool {
		return $uid > 0 && ZAGR_People::is_staff( $uid );
	}

	/** The administrators who can be told. @return int[] */
	public static function admins(): array {
		$out = array();
		if ( function_exists( 'get_users' ) ) {
			foreach ( (array) get_users( array( 'role__in' => self::ADMIN_ROLES, 'fields' => 'ID', 'number' => 50 ) ) as $u ) {
				$u = (int) ( is_object( $u ) ? ( $u->ID ?? 0 ) : $u );
				if ( ! in_array( $u, $out, true ) && self::usable( $u ) ) {
					$out[] = $u;
				}
			}
		}
		return array_slice( $out, 0, self::MAX_PEOPLE );
	}

	/** The salesperson who signed for the business; else the administrators; and any staff the filter adds. @return int[] */
	public static function recipients( array $row, string $event ): array {
		$signer = (int) ( $row['contractor_user_id'] ?? 0 );
		$people = self::usable( $signer ) ? array( $signer ) : self::admins();
		if ( 'signed_on_paper' === $event ) {   // the administrators (never the one who recorded it: $skip, below)
			$people = self::admins();
		}
		$people = (array) apply_filters( 'zagr_alert_recipients', $people, $event, (int) ( $row['estimate_id'] ?? 0 ) );
		$skip   = self::recorder( $row, $event );
		$out    = array();
		foreach ( $people as $u ) {
			$u = is_scalar( $u ) ? (int) $u : 0;
			if ( ! in_array( $u, $out, true ) && self::usable( $u ) && $u !== $skip ) {
				$out[] = $u;
			}
		}
		return array_slice( $out, 0, self::MAX_PEOPLE );
	}

	/**
	 * Who recorded a paper signing (never told of it: they already know, not even by "nobody received it"); 0 for
	 * any other alert.
	 */
	private static function recorder( array $row, string $event ): int {
		return 'signed_on_paper' === $event ? (int) ( ZAGR_Paper::current( $row )['by'] ?? 0 ) : 0;
	}

	/** A same-site page for this person ('' for none): never off-site, never the billing system. */
	public static function link( array $row, int $uid, string $event ): string {
		// a paper signing opens its row in Settings, Agreements (the photos are there, for administrators)
		$url = 'signed_on_paper' === $event ? self::paper_link( $row )
			: apply_filters( 'zagr_alert_link', '', (int) ( $row['estimate_id'] ?? 0 ), $uid, $event );
		$url = is_string( $url ) ? trim( $url ) : '';
		if ( '' === $url || strlen( $url ) > 500 || false !== stripos( $url, 'freshbooks' ) || preg_match( '/[\x00-\x20\x7F\\\\]/', $url ) ) {
			return '';
		}
		$home = rtrim( (string) home_url( '/' ), '/' ) . '/';
		$same = ( '/' === $url[0] && ( ! isset( $url[1] ) || '/' !== $url[1] ) ) || 0 === strpos( $url, $home );
		return $same ? $url : '';
	}

	/** The agreement's row in Settings, Agreements. */
	public static function paper_link( array $row ): string {
		return function_exists( 'admin_url' ) ? (string) admin_url( 'options-general.php?page=' . ZAGR_Admin::SLUG . '#zagr-a' . (int) ( $row['id'] ?? 0 ) ) : '';
	}

	/* ------------------------------------------------------------------ once, and again on the hour */

	public static function sent( string $key ): bool {
		$r = get_option( $key, false );
		return is_array( $r ) && ! empty( $r['done'] );
	}

	/**
	 * Did the router write this alert's bell row for this person? The router writes the bell,
	 * then the email, then the push, so a send that throws has usually written the bell already. true / false, or null
	 * when it can't be told (no get_notifications(), or the read failed). Matched by the alert's own claim key, sent with
	 * it in the source (the row's source_meta): the key names the event and the agreement, and tells this alert from
	 * another of the same type for the same agreement (the first OK to order, before a change order settled). The
	 * person's newest BELL_LOOK rows are read.
	 */
	public static function has_bell( int $uid, string $key ): ?bool {
		if ( $uid <= 0 || ! class_exists( 'ZDZ_Alert_Router' ) || ! method_exists( 'ZDZ_Alert_Router', 'get_notifications' ) ) {
			return null;
		}
		global $wpdb;
		try {
			$rows = ZDZ_Alert_Router::get_notifications( $uid, false, self::BELL_LOOK, 0 );   // $wpdb clears last_error first
			if ( ! is_array( $rows ) || ( is_object( $wpdb ) && '' !== (string) $wpdb->last_error ) ) {
				return null;   // real $wpdb answers a failed read with an empty result and last_error
			}
		} catch ( \Throwable $e ) {
			return null;
		}
		foreach ( $rows as $n ) {
			$m = json_decode( (string) ( ( (array) $n )['source_meta'] ?? '' ), true );
			if ( is_array( $m ) && $key === (string) ( $m['alert_key'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The claims left undone (done false, not stopped), found by their own option names - no shared
	 * list, so two requests writing at once can't lose one. At most SWEEP_MAX a sweep, oldest first.
	 *
	 * @return string[] option names
	 */
	public static function pending_keys(): array {
		global $wpdb;
		if ( ! is_object( $wpdb ) || empty( $wpdb->options ) || ! method_exists( $wpdb, 'get_col' ) ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the options table name is WordPress's own.
		$sql = $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value LIKE %s AND option_value NOT LIKE %s ORDER BY option_id LIMIT %d",
			$wpdb->esc_like( self::SENT_OPT ) . '%',
			'%' . $wpdb->esc_like( 's:4:"done";b:0;' ) . '%',   // as WordPress stores the claim (serialized)
			'%' . $wpdb->esc_like( 's:7:"stopped";' ) . '%',
			self::SWEEP_MAX );
		return array_map( 'strval', (array) $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is the $wpdb->prepare() result just above
	}

	/** A claim the sweep won't take up again: 'gave_up' (24 hours of failed sends) or 'moved_on' (that moment is past). */
	private static function stop( string $key, string $why ): void {
		$r = get_option( $key, false );
		if ( is_array( $r ) && empty( $r['done'] ) ) {
			$r['stopped']    = $why;
			$r['stopped_at'] = ZAGR_Clock::utc();
			update_option( $key, $r, false );
		}
	}

	/** [ event, agreement id ] of a claim: written in it; for one without them, read from its name. */
	private static function of_claim( string $key, array $rec ): array {
		$event = (string) ( $rec['event'] ?? '' );
		$id    = (int) ( $rec['id'] ?? 0 );
		if ( ( '' === $event || $id <= 0 ) && preg_match( '/^' . preg_quote( self::SENT_OPT, '/' ) . '(signed_on_paper|signed|ok_to_order|cancelled)_(\d+)(?:_c\d+|_p\d+)?$/', $key, $m ) ) {
			$event = $m[1];
			$id    = (int) $m[2];
		}
		return array( isset( self::TYPES[ $event ] ) ? $event : '', $id );
	}

	/**
	 * The hourly sweep (ZAGR_Store::sweep_windows): every claim left undone, again, for 24 hours from its first try; then
	 * it gives up, in the log and on the claim (stopped: gave_up). Not in the agreement's record: that is the customer's,
	 * printed in their signed copy, and a staff alert is not part of it.
	 */
	public static function retry(): void {
		foreach ( self::pending_keys() as $key ) {
			$rec = get_option( $key, false );
			if ( ! is_array( $rec ) || ! empty( $rec['done'] ) || ! empty( $rec['stopped'] ) ) {
				continue;
			}
			list( $event, $id ) = self::of_claim( $key, $rec );
			if ( '' === $event || $id <= 0 ) {
				self::stop( $key, 'moved_on' );
				continue;
			}
			$first = ZAGR_Clock::ts( (string) ( $rec['at'] ?? '' ) );
			if ( ! $first || ZAGR_Clock::now() - $first > self::RETRY_FOR ) {
				self::last_call( $key, $rec, $event, $id );   // nobody told at all: the administrators, once
				self::stop( $key, 'gave_up' );
				$row = ZAGR_Store::by_id( $id );
				$num = $row ? self::number( $row ) : '';
				error_log( sprintf( 'Zorderz agreements alert: gave up on the %s alert for estimate %s after 24 hours of failed sends.', $event, '' !== $num ? '#' . $num : '(no number)' ) ); // phpcs:ignore
				continue;
			}
			self::fire( $event, $id, $key );
		}
	}

	/**
	 * A claim that gives up with nobody told on any channel (no one told, no one who may have it)
	 * asks the administrators once first, the way "nobody received it" does (ask_admins()), unless they were asked
	 * already or the moment is past; the claim records it (admins_asked, and who was told).
	 */
	private static function last_call( string $key, array $rec, string $event, int $id ): void {
		if ( (array) ( $rec['told'] ?? array() ) || (array) ( $rec['maybe'] ?? array() ) || ! empty( $rec['admins_asked'] ) || ! self::router() ) {
			return;
		}
		try {
			$row = ZAGR_Store::by_id( $id );
			if ( ! $row || ! self::due( $event, $row ) ) {
				return;   // the moment is past (the hourly try stops a claim whose key has moved on)
			}
			$people = array_values( array_unique( array_merge( self::recipients( $row, $event ), array_map( 'intval', (array) ( $rec['pending'] ?? array() ) ) ) ) );
			list( $told, $maybe, $pending, $err, $more ) = self::ask_admins( $event, $id, $key, $row, self::words( $event, $row ), $people );
			if ( ! $more ) {
				return;
			}
			$rec['to']           = (int) ( $rec['to'] ?? 0 ) + count( $more );
			$rec['delivered']    = count( $told );
			$rec['told']         = $told;
			$rec['maybe']        = $maybe;
			$rec['pending']      = array_values( array_unique( array_merge( array_map( 'intval', (array) ( $rec['pending'] ?? array() ) ), $pending ) ) );
			$rec['admins_asked'] = true;
			if ( '' !== $err ) {
				$rec['error'] = $err;
			}
			update_option( $key, $rec, false );
			$num = self::number( $row );
			error_log( sprintf( 'Zorderz agreements alert: %s, estimate %s - nobody was told in 24 hours; the administrators were asked instead (%d told).', $event, '' !== $num ? '#' . $num : '(no number)', count( $told ) ) ); // phpcs:ignore
		} catch ( \Throwable $e ) {
			error_log( 'Zorderz agreements alert: the ' . $event . ' alert\'s last call to the administrators failed (' . get_class( $e ) . ').' ); // phpcs:ignore
		}
	}

	/* ------------------------------------------------------------------ send */

	/**
	 * The administrators, once, who aren't among $people: [ told, maybe, pending, the last error, those asked ].
	 * Used when the router delivered to no channel for everyone (fire()), and at a give-up with nobody told (last_call()).
	 */
	private static function ask_admins( string $event, int $id, string $key, array $row, array $w, array $people ): array {
		$more = array_values( array_diff( self::admins(), $people, array( self::recorder( $row, $event ) ) ) );
		if ( ! $more ) {
			return array( array(), array(), array(), '', array() );
		}
		list( $told, $none, $threw, $err ) = self::deliver( $event, $id, $key, $row, $w, $more );
		list( $t2, $maybe, $pending ) = self::after_throw( $threw, $key );
		return array( array_merge( $told, $t2 ), $maybe, $pending, $err, $more );
	}

	/**
	 * Send to each person, each with their own link and the alert's claim key (for has_bell()).
	 * [ told (a channel was used), none (no channel: their settings), threw, the class of the last error ('' for none) ]
	 */
	private static function deliver( string $event, int $id, string $key, array $row, array $w, array $people ): array {
		$told  = array();
		$none  = array();
		$threw = array();
		$err   = '';
		foreach ( $people as $uid ) {
			try {
				$used = ZDZ_Alert_Router::send( self::TYPES[ $event ], $uid, $w['title'], $w['message'], array(
					'plugin'    => 'agreements',
					'source_id' => $id,
					'url'       => self::link( $row, $uid, $event ),
					'sender_id' => 0,
					'alert_key' => $key,
				) );
				if ( is_array( $used ) && $used ) {
					$told[] = $uid;
				} else {
					$none[] = $uid;
				}
			} catch ( \Throwable $e ) {
				$threw[] = $uid;
				$err     = get_class( $e );
			}
		}
		return array( $told, $none, $threw, $err );
	}

	/**
	 * Those whose send threw, sorted by their bell: the row there = told; can't be told = maybe
	 * (possibly delivered, so never sent again); no row = pending (the sweep tries again).
	 */
	private static function after_throw( array $threw, string $key ): array {
		$told  = array();
		$maybe = array();
		$wait  = array();
		foreach ( $threw as $uid ) {
			$b = self::has_bell( $uid, $key );
			if ( true === $b ) {
				$told[] = $uid;
			} elseif ( null === $b ) {
				$maybe[] = $uid;
			} else {
				$wait[] = $uid;
			}
		}
		return array( $told, $maybe, $wait );
	}

	private static function claim_record( string $event, int $id ): array {
		return array( 'at' => ZAGR_Clock::utc(), 'event' => $event, 'id' => $id, 'to' => 0, 'delivered' => 0, 'told' => array(), 'maybe' => array(), 'pending' => array(), 'done' => false, 'tries' => 1 );
	}

	/** $retry: the claim key the hourly sweep is trying again ('' for the moment itself). */
	private static function fire( string $event, int $id, string $retry = '' ): void {
		$key     = '';
		$claimed = false;
		try {
			if ( $id <= 0 || ! isset( self::TYPES[ $event ] ) || ! self::router() ) {
				return;   // no router: nothing, silently, and nothing claimed
			}
			$row = ZAGR_Store::by_id( $id );
			if ( ! $row || ! self::due( $event, $row ) ) {
				if ( '' !== $retry ) {
					self::stop( $retry, 'moved_on' );   // the agreement has moved on: that moment is past
				}
				return;
			}
			$key = self::key( $event, $row );
			if ( '' !== $retry && $key !== $retry ) {
				self::stop( $retry, 'moved_on' );
				return;
			}
			$rec = get_option( $key, false );
			if ( is_array( $rec ) && ( ! empty( $rec['done'] ) || ! empty( $rec['stopped'] ) || '' === $retry ) ) {
				return;   // sent already, given up, or held by another request (only the sweep takes up an undone claim)
			}
			// the work first: a filter that throws stops it here, before the claim
			$w      = self::words( $event, $row );
			$people = self::recipients( $row, $event );
			$num    = self::number( $row );
			if ( is_array( $rec ) ) {
				$rec['tries'] = (int) ( $rec['tries'] ?? 1 ) + 1;   // the sweep, again, on its own claim
				update_option( $key, $rec, false );
			} elseif ( ! add_option( $key, self::claim_record( $event, $id ), '', 'no' ) ) {
				return;   // another request claimed it first
			} else {
				$rec = self::claim_record( $event, $id );
			}
			$claimed = true;
			$told    = array_map( 'intval', (array) ( $rec['told'] ?? array() ) );
			$maybe   = array_map( 'intval', (array) ( $rec['maybe'] ?? array() ) );
			$people  = array_values( array_unique( array_merge( $people, array_map( 'intval', (array) ( $rec['pending'] ?? array() ) ) ) ) );
			$send    = array();
			foreach ( array_diff( $people, $told, $maybe ) as $uid ) {
				if ( '' !== $retry && true === self::has_bell( $uid, $key ) ) {
					$told[] = $uid;   // an earlier try wrote their bell before it failed: never rung again
				} else {
					$send[] = $uid;
				}
			}
			list( $t1, $none, $threw, $err ) = self::deliver( $event, $id, $key, $row, $w, $send );
			list( $t2, $m2, $pending ) = self::after_throw( $threw, $key );
			$told  = array_merge( $told, $t1, $t2 );
			$maybe = array_merge( $maybe, $m2 );
			$asked = ! empty( $rec['admins_asked'] );
			if ( ! $told && ! $maybe && ! $pending && ! $asked ) {
				// the router delivered to no channel for everyone (never because of a throw): the administrators, once
				list( $t3, $m3, $p3, $err3, $more ) = self::ask_admins( $event, $id, $key, $row, $w, $people );
				if ( $more ) {
					$asked   = true;
					$told    = array_merge( $told, $t3 );
					$maybe   = array_merge( $maybe, $m3 );
					$pending = array_merge( $pending, $p3 );
					$err     = '' !== $err3 ? $err3 : $err;
					$people  = array_merge( $people, $more );
				}
			}
			$told  = array_values( array_unique( $told ) );
			$maybe = array_values( array_diff( array_unique( $maybe ), $told ) );
			$out   = array(
				'at'        => (string) ( $rec['at'] ?? ZAGR_Clock::utc() ),
				'event'     => $event,
				'id'        => $id,
				'to'        => count( array_unique( $people ) ),
				'delivered' => count( $told ),
				'told'      => $told,
				'maybe'     => $maybe,
				'pending'   => array_values( array_unique( $pending ) ),
				'done'      => ! $pending,
				'tries'     => (int) ( $rec['tries'] ?? 1 ),
			);
			if ( '' !== $err || isset( $rec['error'] ) ) {
				$out['error'] = '' !== $err ? $err : (string) $rec['error'];
			}
			if ( $asked ) {
				$out['admins_asked'] = true;
			}
			update_option( $key, $out, false );
			$label = '' !== $num ? '#' . $num : '(no number)';
			// counts and the estimate number only: never a name, an email, an address or a signature
			if ( $pending ) {   // a send threw and left no bell row: kept undone, for the hourly sweep
				error_log( $told || $maybe   // phpcs:ignore
					? sprintf( 'Zorderz agreements alert: %s, estimate %s - %d told, %d delivered, the rest failed (%s); the hourly sweep tries again for them.', $event, $label, $out['to'], count( $told ), $err )
					: sprintf( 'Zorderz agreements alert: %s, estimate %s - no send went through (%s); the hourly sweep tries again.', $event, $label, $err ) );
			} elseif ( '' !== $err && $maybe ) {
				error_log( sprintf( 'Zorderz agreements alert: %s, estimate %s - %d told, %d delivered, %d may have it (a send failed (%s) and the bell could not be checked), so it is not sent again.', $event, $label, $out['to'], count( $told ), count( $maybe ), $err ) ); // phpcs:ignore
			} elseif ( '' !== $err ) {
				error_log( sprintf( 'Zorderz agreements alert: %s, estimate %s - %d told, %d delivered; a send failed (%s) after the bell row was written, so it is not sent again.', $event, $label, $out['to'], count( $told ), $err ) ); // phpcs:ignore
			} else {
				error_log( sprintf( 'Zorderz agreements alert: %s, estimate %s - %d told, %d delivered.', $event, $label, $out['to'], count( $told ) ) ); // phpcs:ignore
			}
		} catch ( \Throwable $e ) {
			if ( '' !== $key ) {
				$r = get_option( $key, false );
				if ( $claimed && is_array( $r ) ) {
					$r['done']  = false;
					$r['error'] = get_class( $e );
					update_option( $key, $r, false );
				} elseif ( ! is_array( $r ) ) {
					// before the claim (a filter threw): an undone claim, so the hourly sweep finds it and tries again
					add_option( $key, array( 'error' => get_class( $e ) ) + self::claim_record( $event, $id ), '', 'no' );
				}
			}
			error_log( 'Zorderz agreements alert: the ' . $event . ' alert failed (' . get_class( $e ) . '); the hourly sweep tries again.' ); // phpcs:ignore
		}
	}
}
