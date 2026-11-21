<?php
/**
 * ZEST_Followup_Ajax - re-send an estimate to its salesperson, their answer, and taking one back: the requests.
 *
 * The record and the words are ZEST_Followup's. This class knows who may, and what to tell each system.
 *
 *   zest_fu_people     {id}                         who the estimate names (the people a re-send may go to)
 *   zest_fu_send       {id, to, note, expect}       ask the salesperson to follow up: a row, then a notice (the bell
 *                                                   and a push). Nothing is written to the billing system.
 *   zest_fu_answer     {id, answer, outcome,        reached | tried | handled, what the customer said (a fixed list)
 *                       channel, note, expect}      and the person's own words. Saved here FIRST; then, for reached and
 *                                                   handled, ONE fixed-phrase line at the bottom of the billing
 *                                                   estimate's notes; the true wording goes to the estimate's CRM lead
 *                                                   and the platform's contact log; whoever asked is told.
 *   zest_fu_reopen     {id, expect}                 a declined estimate back among the open ones
 *   zest_fu_history    {id}                         every ask and answer for it, with the typed words
 *   zest_fu_line_retry {fu}                         write a billing line that did not get through
 *   zest_fu_undo       {id, expect}                 take back the LAST thing recorded on the follow-up (an answer, a
 *                                                   try, or the ask itself) as recorded or sent by mistake. The billing
 *                                                   system FIRST: the answer's own line is taken off (the line it
 *                                                   replaced goes back); if that cannot be done now, nothing is taken
 *                                                   back. Then the record; a fixed-words correction for the CRM lead;
 *                                                   whoever asked (or was asked) is told.
 *   `id` is always this app's own estimate row. Nothing here looks an estimate up by number or by name.
 *
 * WHO
 *   re-send         the admin tier, or an operator who sees other people's data (ZDZ_Data_Permissions), for an
 *                   estimate they may open; a business chooses otherwise on filter zest_followup_can_send. Never the
 *                   shared device, never an account in Privacy View.
 *   answer, reopen,
 *   history, retry  whoever may re-send it, the person it was sent to (unless an administrator removed their access to
 *                   the estimate), or the estimate's owner by this app's own rule (ZEST_Salesperson). Reopen is here on
 *                   purpose: whoever marked an estimate declined can undo it.
 *   take back       an answer or a try: whoever may answer, or the person who recorded it. An ask: whoever may re-send,
 *                   or the person who sent it (never the person asked: they answer it).
 *   A re-send goes only to a person the estimate already names (its location line, its provenance line or its
 *   salesperson column), whatever their role, so the person told can always open it; the ask itself puts the estimate
 *   on their open list. Changing who that is stays with the editor and with Tools -> Re-assign stubs.
 *
 * ONE AT A TIME, AND NEVER FROM A STALE SCREEN
 *   send, answer, reopen, retry and take-back run under one named lock per estimate (locked()), and each write
 *   carries `expect`, the token of the follow-up the person's screen showed (ZEST_Followup::token()). A token that is
 *   not the follow-up's token now is refused with code "changed".
 *
 * WHAT REACHES THE BILLING SYSTEM (the customer can read an estimate's notes)
 *   Only ZEST_Followup::line(): "<lead> <date> (XX): <one of the fixed phrases>". write_line() asks
 *   ZEST_Followup::line_ok() again before it writes and refuses anything else. Written only onto an open estimate of
 *   the same customer this app recorded (fail closed), under the billing view's per-document lock, history first, read
 *   back; and only where the switch wants it (ZEST_Followup::mode(): test customers only until an administrator turns
 *   it on). An estimate with no billing customer on record is not skipped outright: the estimate's own client is read
 *   from the billing system and, only when its name is the name this app holds, the id is kept on the row and the line
 *   is written (confirm_customer()). Any doubt still writes nothing.
 *
 * The screen is always told what was and was not done: which notices went, whether the billing line was written, left
 * alone or failed, and why. No log line carries a customer or typed words; a notice's title never names a customer.
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZEST_Followup_Ajax {

	const ALERT_ASKED    = 'estimate_followup_asked';
	const ALERT_ANSWERED = 'estimate_followup_answered';
	const CRON           = 'zest_fu_crm_note';
	/** Tries per CRM note before it is left for the next answer's run. */
	const CRM_MAX        = 3;
	/** The states of this app's row in which an estimate is still undecided. */
	const OPEN_STATES    = array( 'created', 'stub', 'open', 'updated', 'sent' );

	/** @var callable|null tests: (type, to, title, message, options) => the channels a notice went out on */
	public static $notifier = null;

	public static function init(): void {
		add_action( 'wp_ajax_zest_fu_people', array( __CLASS__, 'ajax_people' ) );
		add_action( 'wp_ajax_zest_fu_send', array( __CLASS__, 'ajax_send' ) );
		add_action( 'wp_ajax_zest_fu_answer', array( __CLASS__, 'ajax_answer' ) );
		add_action( 'wp_ajax_zest_fu_reopen', array( __CLASS__, 'ajax_reopen' ) );
		add_action( 'wp_ajax_zest_fu_history', array( __CLASS__, 'ajax_history' ) );
		add_action( 'wp_ajax_zest_fu_line_retry', array( __CLASS__, 'ajax_line_retry' ) );
		add_action( 'wp_ajax_zest_fu_undo', array( __CLASS__, 'ajax_undo' ) );
		add_action( self::CRON, array( __CLASS__, 'cron_crm_note' ), 10, 2 );
		add_action( 'zdz_register_alert_types', array( __CLASS__, 'alert_types' ) );
	}

	public static function alert_types( $router ): void {
		if ( ! is_object( $router ) || ! method_exists( $router, 'register_type' ) ) {
			return;
		}
		$router->register_type( self::ALERT_ASKED, array(
			'label'            => __( 'Estimates to follow up', 'zorderz' ),
			'description'      => __( 'One of your open estimates was re-sent to you with a request to follow up with the customer.', 'zorderz' ),
			'default_channels' => array( 'in_app', 'push' ),
			'source_plugin'    => 'estimate-creator',
			'category'         => 'sales',
		) );
		$router->register_type( self::ALERT_ANSWERED, array(
			'label'            => __( 'Follow-ups answered', 'zorderz' ),
			'description'      => __( 'A salesperson answered a follow-up you asked for on an estimate.', 'zorderz' ),
			'default_channels' => array( 'in_app' ),
			'source_plugin'    => 'estimate-creator',
			'category'         => 'sales',
		) );
	}

	/* ================================================================ answers */

	/** A refusal: { ok: false, message, status?, code? }. */
	private static function no( string $message, int $status = 0, string $code = '' ): array {
		return array( 'ok' => false, 'message' => $message, 'status' => $status, 'code' => $code );
	}

	/** The refusal for a screen that no longer shows the follow-up as it is. */
	private static function changed( string $message = '' ): array {
		return self::no( '' !== $message ? $message : __( 'This follow-up changed since your screen was drawn. The list was refreshed: look again.', 'zorderz' ), 0, 'changed' );
	}

	private static function reply( array $r ): void {
		if ( empty( $r['ok'] ) ) {
			$data = array( 'message' => (string) ( $r['message'] ?? '' ) );
			if ( '' !== (string) ( $r['code'] ?? '' ) ) {
				$data['code'] = (string) $r['code'];
			}
			if ( (int) ( $r['status'] ?? 0 ) > 0 ) {
				wp_send_json_error( $data, (int) $r['status'] );
			}
			wp_send_json_error( $data );
		}
		unset( $r['ok'] );
		wp_send_json_success( $r );
	}

	/* ================================================================ who may */

	private static function pv(): bool {
		return class_exists( 'ZEST_Privacy_View' ) && ZEST_Privacy_View::on();
	}

	/** Does this person have the Estimates app at all (the platform's own rule)? */
	public static function has_app( int $uid ): bool {
		if ( $uid <= 0 ) {
			return false;
		}
		if ( class_exists( 'ZDZ_Plugin_API' ) && method_exists( 'ZDZ_Plugin_API', 'user_can_access_app' ) ) {
			return (bool) ZDZ_Plugin_API::user_can_access_app( $uid, defined( 'ZEST_APP_ID' ) ? ZEST_APP_ID : 'estimate-creator' );
		}
		return true;   // no platform to ask: the nonce the app's own screen handed out stands
	}

	/**
	 * Signed in as a person with Estimates, never the shared device; a write never from an account in Privacy View.
	 * Ends the request otherwise.
	 */
	private static function gate( bool $write ): int {
		$uid = ZEST_Access::guard();
		if ( ! self::has_app( $uid ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'zorderz' ) ), 403 );
		}
		if ( ZEST_Access::is_kiosk( $uid ) ) {
			wp_send_json_error( array( 'message' => __( 'Follow-ups are not available on the shared device. Sign in as yourself.', 'zorderz' ) ), 403 );
		}
		if ( self::pv() ) {
			// It looks only, and only once an administrator opened Estimates to it.
			if ( $write || ! ZEST_Privacy_View::open() ) {
				wp_send_json_error( ZEST_Privacy_View::refusal(), 403 );
			}
		} else {
			// Nothing is written on view for an account in Privacy View: not even the table being made. Its one request
			// here is the history, which reads (a missing table reads as empty).
			ZEST_Followup::ensure_table();
		}
		return $uid;
	}

	/** Does this person see other people's data (the platform's data permission)? Without the platform's class: yes. */
	private static function sees_others( int $uid ): bool {
		if ( class_exists( 'ZDZ_Data_Permissions' ) && method_exists( 'ZDZ_Data_Permissions', 'can' ) ) {
			return (bool) ZDZ_Data_Permissions::can( $uid, 'view_others_data' );
		}
		return true;
	}

	/**
	 * May this person re-send estimates to their salespeople at all? The admin tier, or an operator who sees other
	 * people's data; a business chooses otherwise on the filter. Never the shared device, never nobody.
	 */
	public static function can_send( int $uid ): bool {
		if ( $uid <= 0 || ZEST_Access::is_kiosk( $uid ) ) {
			return false;
		}
		$can = ZEST_Access::is_admin_tier( $uid );
		if ( ! $can ) {
			$u   = get_userdata( $uid );
			$can = $u && in_array( 'zdz_operator', (array) $u->roles, true ) && self::sees_others( $uid );
		}
		/**
		 * Who may re-send an estimate to its salesperson (the shared device never may, whatever this answers).
		 *
		 * @param bool $can Core's answer: the admin tier, or an operator who sees other people's data.
		 * @param int  $uid The person.
		 */
		return true === apply_filters( 'zest_followup_can_send', (bool) $can, $uid );
	}

	/** May this person re-send THIS estimate? They may re-send at all, and may open it. */
	public static function may_send( int $uid, array $row ): bool {
		return self::can_send( $uid ) && ( ZEST_Access::is_admin_tier( $uid ) || ZEST_Access::may_open( $row, $uid ) );
	}

	/** Did an administrator remove this person's access to this estimate? */
	private static function revoked( int $uid, int $estimate_id ): bool {
		return class_exists( 'ZEST_Access_Requests' ) && ZEST_Access_Requests::is_revoked( $uid, $estimate_id );
	}

	/** May this person answer for (or read the history of) this estimate? */
	public static function may_answer( int $uid, array $row, ?array $waiting ): bool {
		if ( $uid <= 0 || ZEST_Access::is_kiosk( $uid ) ) {
			return false;
		}
		if ( self::may_send( $uid, $row ) ) {
			return true;
		}
		// The person it was sent to, unless an administrator removed their access to this estimate since (the ownership
		// rule below honours that too; an ask must not be a way round it).
		if ( $waiting && (int) $waiting['sent_to'] === $uid && ! self::revoked( $uid, (int) $row['id'] ) ) {
			return true;
		}
		return ZEST_Access::may_open( $row, $uid );
	}

	/**
	 * May this person take back the last thing recorded on this follow-up? Never someone whose access to the estimate
	 * an administrator removed, whoever recorded it.
	 */
	public static function may_undo( int $uid, array $row, array $fu ): bool {
		if ( $uid <= 0 || ZEST_Access::is_kiosk( $uid ) || self::revoked( $uid, (int) $row['id'] ) ) {
			return false;
		}
		if ( 'ask' === ZEST_Followup::undo_kind( $fu ) ) {
			// An ask: whoever may re-send, or the person who sent it; never the person asked (they answer it).
			return (int) $fu['sent_to'] !== $uid && ( self::may_send( $uid, $row ) || (int) $fu['sent_by'] === $uid );
		}
		return self::may_answer( $uid, $row, ZEST_Followup::is_waiting( $fu ) ? $fu : null ) || (int) $fu['answered_by'] === $uid;
	}

	/**
	 * Run $work holding this estimate's follow-up lock, so an ask, an answer, a reopen, a retry and a take-back for one
	 * estimate never interleave (two asks both left open; an answer nobody asked for landing beside a new ask).
	 */
	private static function locked( int $estimate_id, callable $work ): array {
		global $wpdb;
		$lock = 'zest_fu_' . $estimate_id;
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) ) ) {
			return self::no( __( 'Someone else is recording a follow-up on this estimate right now. Try again in a moment.', 'zorderz' ) );
		}
		try {
			return (array) $work();
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/* ================================================================ the estimate */

	/** The estimate row id from the request: digits only, or the request ends. */
	private static function request_id(): int {
		$raw = isset( $_POST['id'] ) ? wp_unslash( $_POST['id'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in self::gate() (ZEST_Access::guard()); digits only, checked on the next line
		if ( ! is_string( $raw ) || ! preg_match( '/\A[1-9]\d{0,17}\z/', $raw ) ) {
			wp_send_json_error( array( 'message' => __( 'Which estimate? Reload the list and try again.', 'zorderz' ) ) );
		}
		return (int) $raw;
	}

	/** '' when this app's copy says the estimate is still undecided; else why not. */
	public static function not_open( array $row ): string {
		if ( 'estimate' !== (string) ( $row['doc_type'] ?? 'estimate' ) ) {
			return __( 'Only an estimate can be followed up from here.', 'zorderz' );
		}
		$status = (string) ( $row['status'] ?? '' );
		if ( ! empty( $row['accepted_at'] ) || in_array( $status, array( 'accepted', 'converted' ), true ) ) {
			return __( 'This estimate was accepted, so there is nothing left to follow up.', 'zorderz' );
		}
		if ( ! in_array( $status, self::OPEN_STATES, true ) ) {
			return __( 'This estimate is not an open one in this app.', 'zorderz' );
		}
		return '';
	}

	public static function is_test( array $row ): bool {
		return class_exists( 'ZEST_Terms_Note' ) && ZEST_Terms_Note::is_test( (string) ( $row['customer_name'] ?? '' ), (string) ( $row['customer_org'] ?? '' ) );
	}

	private static function number( array $row ): string {
		return ltrim( (string) ( $row['billing_doc_num'] ?? '' ), '#' );
	}

	private static function initials( int $uid ): string {
		return class_exists( 'ZEST_Salesperson' ) ? ZEST_Salesperson::initials_of( $uid ) : '';
	}

	/** A person as the team reads them: their code, else their display name, else ''. */
	public static function who( int $uid ): string {
		if ( $uid <= 0 ) {
			return '';
		}
		// For an account in Privacy View a team member is their pseudonym, here at the source, so every sentence built
		// around a person is built around pseudonyms. That account can make no request that writes, so a pseudonym never
		// reaches the billing system, the CRM or a notice.
		if ( self::pv() ) {
			return ZEST_Privacy_View::staff( $uid );
		}
		$ini = self::initials( $uid );
		if ( '' !== $ini ) {
			return $ini;
		}
		$u = get_userdata( $uid );
		return $u ? (string) $u->display_name : '';
	}

	/**
	 * The people the estimate names who can be given work: [{id, name, initials}], and every code on it. A code two
	 * people hold, or nobody holds, names no one (ZEST_Salesperson's rule): it is listed in `codes` only. Whoever the
	 * estimate names is its salesperson here, whatever their role. Someone whose access to this estimate an
	 * administrator removed is left out.
	 */
	public static function people( array $row ): array {
		$sp     = class_exists( 'ZEST_Salesperson' ) ? ZEST_Salesperson::for_row( $row ) : array( 'salesperson_uids' => array(), 'salesperson_initials' => array() );
		$people = array();
		foreach ( (array) $sp['salesperson_uids'] as $id ) {
			$id = (int) $id;
			$u  = $id > 0 ? get_userdata( $id ) : false;
			if ( ! $u || ! ZEST_Reassign::can_be_given_work( $id ) || self::revoked( $id, (int) $row['id'] ) ) {
				continue;
			}
			$people[] = array( 'id' => $id, 'name' => (string) $u->display_name, 'initials' => self::initials( $id ) );
		}
		return array( 'people' => $people, 'codes' => array_values( array_map( 'strval', (array) $sp['salesperson_initials'] ) ) );
	}

	/* ================================================================ what the page gets */

	/** One follow-up as the page reads it. No customer, and no typed words (the history sheet carries those). */
	public static function view( ?array $r, int $viewer ): ?array {
		if ( ! $r ) {
			return null;
		}
		$to       = self::who( (int) $r['sent_to'] );
		$by       = self::who( (int) $r['sent_by'] );
		$who      = self::who( (int) $r['answered_by'] );
		$wait     = ZEST_Followup::is_waiting( $r );
		$reopened = ! empty( $r['reopened_at'] );
		$again    = $reopened ? self::who( (int) ( $r['reopened_by'] ?? 0 ) ) : '';
		$words    = ZEST_Followup::status_words( $r, $to, $by, $who );
		if ( $reopened ) {
			/* translators: %s: who reopened it */
			$words .= '; ' . ( '' !== $again ? sprintf( __( 'reopened by %s', 'zorderz' ), $again ) : __( 'reopened', 'zorderz' ) );
		}
		$gone = in_array( (string) $r['state'], array( 'superseded', 'withdrawn' ), true );
		return array(
			'id'           => (int) $r['id'],
			'token'        => ZEST_Followup::token( $gone ? null : $r ),
			'state'        => (string) $r['state'],
			'to'           => $to,
			'by'           => $by,
			'asked'        => (string) $r['sent_at'],
			'answer'       => (string) $r['answer'],
			'outcome'      => ZEST_Followup::outcome_label( (string) $r['outcome'] ),
			'outcome_code' => (string) $r['outcome'],
			'tries'        => (int) $r['tries'],
			'who'          => $who,
			'when'         => (string) ( $r['answered_at'] ?? '' ),
			'declined'     => 'done' === $r['state'] && ! empty( $r['declined'] ),
			'mine'         => $wait && (int) $r['sent_to'] === $viewer,
			'by_me'        => (int) $r['sent_by'] > 0 && (int) $r['sent_by'] === $viewer,
			'reopened'     => $reopened,
			'reopened_by'  => $again,
			'words'        => $words,
			'doc_state'    => (string) ( $r['doc_state'] ?? '' ),
			'doc_why'      => ZEST_Followup::why_words( (string) ( $r['doc_why'] ?? '' ) ),
			'doc_why_code' => (string) ( $r['doc_why'] ?? '' ),
			'test'         => ! empty( $r['is_test'] ),
		);
	}

	/**
	 * For a list of this app's estimates: each one's newest follow-up, which have any history, and what this person
	 * may do. Never throws and never ends the request: a missing table means "no follow-ups", and the list still loads.
	 *
	 * @param int[] $estimate_ids
	 * @return array{map:array<int,array>,any:array<int,bool>,meta:array}
	 */
	public static function for_list( array $estimate_ids, int $uid ): array {
		$map = array();
		foreach ( (array) ZEST_Followup::quiet( static function () use ( $estimate_ids ) {
			return ZEST_Followup::latest_map( $estimate_ids );
		}, array() ) as $id => $r ) {
			$v = self::view( $r, $uid );
			if ( $v ) {
				$map[ (int) $id ] = $v;
			}
		}
		$outcomes = array();
		foreach ( ZEST_Followup::outcome_labels() as $code => $label ) {
			$outcomes[] = array( 'code' => $code, 'label' => $label, 'declined' => ZEST_Followup::is_declined( $code ) );
		}
		$channels = array();
		foreach ( ZEST_Followup::channels() as $code => $label ) {
			$channels[] = array( 'code' => $code, 'label' => $label );
		}
		// Which listed estimates have ANY follow-up row, so History is offered after the only one was taken back (what
		// was typed is kept there for whoever typed it).
		$any = (array) ZEST_Followup::quiet( static function () use ( $estimate_ids ) {
			return ZEST_Followup::have_rows( $estimate_ids );
		}, array() );
		return array(
			'map'  => $map,
			'any'  => array_fill_keys( array_map( 'intval', $any ), true ),
			'meta' => array( 'can_send' => self::can_send( $uid ), 'outcomes' => $outcomes, 'channels' => $channels, 'max_note' => ZEST_Followup::MAX_NOTE ),
		);
	}

	/* ================================================================ 1. who is on it */

	public static function ajax_people(): void {
		$uid = self::gate( true );
		$row = ZEST_Access::row( self::request_id() );
		self::reply( $row ? self::who_is_on( $row, $uid ) : self::no( __( 'This app has no record of that estimate, so it can\'t be followed up from here.', 'zorderz' ) ) );
	}

	public static function who_is_on( array $row, int $uid ): array {
		if ( ! self::may_send( $uid, $row ) ) {
			return self::no( __( 'Only an administrator or an operator can re-send an estimate to its salesperson.', 'zorderz' ), 403 );
		}
		$why = self::not_open( $row );
		if ( '' !== $why ) {
			return self::no( $why );
		}
		$p = self::people( $row );
		return array(
			'ok'       => true,
			'people'   => $p['people'],
			'codes'    => $p['codes'],
			'me'       => $uid,
			'number'   => self::number( $row ),
			'followup' => self::view( ZEST_Followup::latest( (int) $row['id'] ), $uid ),
		);
	}

	/* ================================================================ 2. re-send */

	public static function ajax_send(): void {
		$uid = self::gate( true );
		$row = ZEST_Access::row( self::request_id() );
		if ( ! $row ) {
			self::reply( self::no( __( 'This app has no record of that estimate, so it can\'t be followed up from here.', 'zorderz' ) ) );
		}
		self::reply( self::send( $row, $uid, array(
			'to'     => isset( $_POST['to'] ) ? absint( wp_unslash( $_POST['to'] ) ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in self::gate() (ZEST_Access::guard()) above
			'note'   => isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in self::gate() (ZEST_Access::guard()) above
			'expect' => isset( $_POST['expect'] ) ? sanitize_text_field( wp_unslash( $_POST['expect'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in self::gate() (ZEST_Access::guard()) above
		) ) );
	}

	/** What a person typed, kept as typed, with any gate code sealed under the estimate before it is stored. */
	private static function typed( $text, array $row ): string {
		$note = ZEST_Followup::clean_note( $text );
		return ( '' !== $note && class_exists( 'ZEST_Sealed' ) ) ? ZEST_Sealed::cut( $note, array( 'estimate', (string) (int) $row['id'] ) ) : $note;
	}

	/** Re-send: $in {to, note, expect}. */
	public static function send( array $row, int $uid, array $in ): array {
		if ( self::pv() ) {
			return self::no( __( 'Not available in Privacy View.', 'zorderz' ), 403, 'zdz_privacy_view' );
		}
		if ( ! self::may_send( $uid, $row ) ) {
			return self::no( __( 'Only an administrator or an operator can re-send an estimate to its salesperson.', 'zorderz' ), 403 );
		}
		$why = self::not_open( $row );
		if ( '' !== $why ) {
			return self::no( $why );
		}
		$est    = (int) $row['id'];
		$to     = (int) ( $in['to'] ?? 0 );
		$people = self::people( $row )['people'];
		$person = null;
		foreach ( $people as $p ) {
			if ( (int) $p['id'] === $to ) {
				$person = $p;
				break;
			}
		}
		if ( ! $person ) {
			return self::no( $people
				? __( 'Pick one of the people this estimate names.', 'zorderz' )
				: __( 'This estimate names no one person as its salesperson, so there is nobody to send it to. Set the salesperson on the estimate first.', 'zorderz' ) );
		}
		if ( $to === $uid ) {
			return self::no( __( 'You are the salesperson on this one. Record the follow-up yourself.', 'zorderz' ) );
		}
		$note   = self::typed( $in['note'] ?? '', $row );
		$expect = (string) ( $in['expect'] ?? '' );
		$test   = self::is_test( $row );
		$made   = self::locked( $est, static function () use ( $est, $row, $uid, $to, $note, $expect, $test ) {
			$latest = ZEST_Followup::latest( $est );
			// A second press of the same ask within a minute is the same ask: no second row, no second notice. Both
			// times are the site's own clock (the one sent_at was written with), and an ask "from the future" is not one.
			$age = (int) strtotime( current_time( 'mysql', true ) . ' UTC' ) - (int) strtotime( (string) ( $latest['sent_at'] ?? '' ) . ' UTC' );
			if ( $latest && 'open' === $latest['state'] && (int) $latest['sent_to'] === $to && (int) $latest['sent_by'] === $uid && $age >= 0 && $age < 60 ) {
				return array( 'ok' => true, 'again' => (int) $latest['id'] );
			}
			// The follow-up as this person's screen showed it: another person's ask, or an answer, since then ends it here.
			if ( ZEST_Followup::token( $latest ) !== $expect ) {
				return self::changed();
			}
			if ( $latest && 'done' === $latest['state'] && ! empty( $latest['declined'] ) ) {
				return self::no( __( 'This estimate is among the declined ones. Reopen it first, then re-send it.', 'zorderz' ) );
			}
			$id = ZEST_Followup::ask( array(
				'estimate_id'     => $est,
				'billing_doc_id'  => (string) ( $row['billing_doc_id'] ?? '' ),
				'billing_doc_num' => (string) ( $row['billing_doc_num'] ?? '' ),
				'is_test'         => $test,
				'sent_by'         => $uid,
				'sent_to'         => $to,
				'ask_note'        => $note,
			) );
			return $id > 0 ? array( 'ok' => true, 'id' => $id ) : self::no( __( 'The re-send could not be recorded, so nobody was told. Tell an admin.', 'zorderz' ) );
		} );
		if ( empty( $made['ok'] ) ) {
			return $made;
		}
		if ( ! empty( $made['again'] ) ) {
			return array( 'ok' => true, 'followup' => self::view( ZEST_Followup::get( (int) $made['again'] ), $uid ), 'told' => array(), 'again' => true, 'to' => $person );
		}
		$id  = (int) $made['id'];
		$num = self::number( $row );
		$by  = self::who( $uid );
		// The title goes to the push too, so it never names the customer; nor does the message: the number is enough to
		// find the estimate, and the person asked can open it.
		$title = ( $test ? __( 'TEST:', 'zorderz' ) . ' ' : '' ) . __( 'Follow up on an estimate', 'zorderz' );
		$msg   = '' !== $by
			/* translators: 1: who asks, 2: estimate number */
			? sprintf( __( '%1$s asks you to follow up on estimate #%2$s. Open it in Estimates and say how it went.', 'zorderz' ), $by, $num )
			/* translators: %s: estimate number */
			: sprintf( __( 'You are asked to follow up on estimate #%s. Open it in Estimates and say how it went.', 'zorderz' ), $num );
		if ( '' !== $note ) {
			/* translators: %s: what the person who asks typed */
			$msg .= "\n\n" . sprintf( __( 'Note: %s', 'zorderz' ), $note );
		}
		$used = self::tell( self::ALERT_ASKED, $to, $title, $msg, 'fu' . $id, $uid );
		/* translators: 1: estimate number, 2: the person's code or name */
		self::audit( $uid, 'estimate_followup_sent', sprintf( __( 'Estimate #%1$s re-sent to %2$s for a follow-up.', 'zorderz' ), $num, '' !== $person['initials'] ? $person['initials'] : $person['name'] ) );
		return array( 'ok' => true, 'followup' => self::view( ZEST_Followup::get( $id ), $uid ), 'told' => $used, 'to' => $person );
	}

	/** Send one notice through the platform's router. Returns the channels that took it ([] = nobody was told). Never throws. */
	private static function tell( string $type, int $to, string $title, string $msg, string $source_id, int $sender ): array {
		$opts = array(
			'plugin'    => 'estimate-creator',
			'source_id' => $source_id,
			'sender_id' => $sender,
			'url'       => home_url( '/' ),
			'push_body' => __( 'Tap to open Estimates.', 'zorderz' ),
		);
		if ( $to > 0 && is_callable( self::$notifier ) ) {
			return array_values( (array) call_user_func( self::$notifier, $type, $to, $title, $msg, $opts ) );
		}
		if ( $to <= 0 || ! class_exists( 'ZDZ_Alert_Router' ) || ! method_exists( 'ZDZ_Alert_Router', 'send' ) ) {
			return array();
		}
		try {
			return array_values( (array) ZDZ_Alert_Router::send( $type, $to, $title, $msg, $opts ) );
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	/** One line in the platform's activity log: a number and codes, never a customer or typed words. */
	private static function audit( int $uid, string $what, string $detail ): void {
		if ( class_exists( 'ZDZ_Admin_Dashboard' ) && method_exists( 'ZDZ_Admin_Dashboard', 'log_action' ) ) {
			try {
				ZDZ_Admin_Dashboard::log_action( $uid, $what, $detail, 'estimate-creator' );
			} catch ( \Throwable $e ) {
				return;
			}
		}
	}

	/* ================================================================ 3. the answer */

	public static function ajax_answer(): void {
		$uid = self::gate( true );
		$row = ZEST_Access::row( self::request_id() );
		if ( ! $row ) {
			self::reply( self::no( __( 'This app has no record of that estimate, so it can\'t be followed up from here.', 'zorderz' ) ) );
		}
		$r = self::answer( $row, $uid, array(
			'answer'  => isset( $_POST['answer'] ) ? sanitize_key( wp_unslash( $_POST['answer'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in self::gate() (ZEST_Access::guard()) above
			'outcome' => isset( $_POST['outcome'] ) ? sanitize_key( wp_unslash( $_POST['outcome'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in self::gate() (ZEST_Access::guard()) above
			'channel' => isset( $_POST['channel'] ) ? sanitize_key( wp_unslash( $_POST['channel'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in self::gate() (ZEST_Access::guard()) above
			'note'    => isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in self::gate() (ZEST_Access::guard()) above
			'expect'  => isset( $_POST['expect'] ) ? sanitize_text_field( wp_unslash( $_POST['expect'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in self::gate() (ZEST_Access::guard()) above
		) );
		if ( ! empty( $r['ok'] ) && ! empty( $r['id'] ) ) {
			// The CRM note goes from cron (the CRM never holds up the answer). The second argument only makes each answer's
			// event its own: WordPress drops a second event with the same arguments within ten minutes.
			self::schedule_note( (int) $r['id'], 5, '' );
			unset( $r['id'] );
		}
		self::reply( $r );
	}

	private static function schedule_note( int $id, int $in_seconds, string $mark ): void {
		if ( function_exists( 'wp_schedule_single_event' ) ) {
			wp_schedule_single_event( time() + $in_seconds, self::CRON, array( $id, uniqid( $mark, true ) ) );
			if ( $in_seconds < 60 && function_exists( 'spawn_cron' ) ) {
				spawn_cron();
			}
		}
	}

	/** The answer: $in {answer, outcome, channel, note, expect}. On success `id` is the follow-up row (for the note's cron). */
	public static function answer( array $row, int $uid, array $in ): array {
		if ( self::pv() ) {
			return self::no( __( 'Not available in Privacy View.', 'zorderz' ), 403, 'zdz_privacy_view' );
		}
		$est = (int) $row['id'];
		if ( ! self::may_answer( $uid, $row, ZEST_Followup::waiting( $est ) ) ) {
			return self::no( __( 'You can only answer for your own estimates.', 'zorderz' ), 403 );
		}
		$why = self::not_open( $row );
		if ( '' !== $why ) {
			return self::no( $why );
		}
		$answer = (string) ( $in['answer'] ?? '' );
		if ( ! in_array( $answer, ZEST_Followup::ANSWERS, true ) ) {
			return self::no( __( 'Say how it went: reached them, tried, or already handled.', 'zorderz' ) );
		}
		$outcome = ZEST_Followup::outcome_for( $answer, (string) ( $in['outcome'] ?? '' ) );
		if ( null === $outcome ) {
			return self::no( __( 'Pick what the customer said.', 'zorderz' ) );
		}
		$channel = ZEST_Followup::channel_for( $answer, (string) ( $in['channel'] ?? '' ) );
		if ( null === $channel ) {
			return self::no( 'tried' === $answer
				? __( 'Pick how you tried (a meeting in person is "reached them").', 'zorderz' )
				: __( 'Pick how you reached them (a voicemail is a try, not a reach).', 'zorderz' ) );
		}
		$note = self::typed( $in['note'] ?? '', $row );
		if ( 'other' === $outcome && '' === $note ) {
			return self::no( __( 'For "Something else", say what they said in the note.', 'zorderz' ) );
		}
		$expect = (string) ( $in['expect'] ?? '' );
		$test   = self::is_test( $row );
		$done   = 'tried' !== $answer;
		$num    = self::number( $row );

		// Under the estimate's lock: the follow-up as it is now, the token check, the row, its CRM note queued, and its
		// billing line, so a take-back can never land between the save and the line.
		$saved = self::locked( $est, static function () use ( $est, $row, $uid, $expect, $test, $answer, $outcome, $channel, $note, $num, $done ) {
			$latest = ZEST_Followup::latest( $est );
			// The follow-up as this person's screen showed it, whatever its state: a newer ask, an answer from another
			// device, a decline or a reopen since then ends it here.
			if ( ZEST_Followup::token( $latest ) !== $expect ) {
				return self::changed();
			}
			if ( $latest && 'done' === $latest['state'] && ! empty( $latest['declined'] ) ) {
				return self::no( __( 'This estimate is among the declined ones. Reopen it first.', 'zorderz' ) );
			}
			$waiting = ZEST_Followup::is_waiting( $latest ) ? $latest : null;
			// The person it was sent to may answer only while it is still theirs (checked again, under the lock).
			if ( ! self::may_answer( $uid, $row, $waiting ) ) {
				return self::no( __( 'You can only answer for your own estimates.', 'zorderz' ), 403 );
			}
			$id = ZEST_Followup::answer( $waiting, array(
				'estimate_id'     => $est,
				'billing_doc_id'  => (string) ( $row['billing_doc_id'] ?? '' ),
				'billing_doc_num' => (string) ( $row['billing_doc_num'] ?? '' ),
				'is_test'         => $test,
				'answer'          => $answer,
				'outcome'         => $outcome,
				'channel'         => $channel,
				'note'            => $note,
				'by'              => $uid,
			) );
			if ( -1 === $id ) {
				return self::changed();
			}
			if ( $id <= 0 ) {
				return self::no( __( 'The answer could not be recorded. Nothing was written anywhere. Try again.', 'zorderz' ) );
			}
			// The true wording, for the estimate's CRM lead: worded now (this answer's own words), posted from cron.
			ZEST_Followup::queue_note( $id, self::crm_text( ZEST_Followup::get( $id ), $num, $note ) );
			// The billing system: one fixed-phrase line, for a reach only. Never the typed words.
			$doc = array( 'state' => '', 'why' => '', 'approval' => '' );
			if ( $done ) {
				$line = ZEST_Followup::line( $outcome, self::initials( $uid ), self::today() );
				$doc  = self::write_line( (array) ZEST_Followup::get( $id ), $row, $line, $uid );
			}
			return array( 'ok' => true, 'id' => $id, 'doc' => $doc );
		} );
		if ( empty( $saved['ok'] ) ) {
			return $saved;
		}
		$id = (int) $saved['id'];
		$fu = (array) ZEST_Followup::get( $id );

		// The contact log behind "Contacted". This app posts its own CRM note, so the log owes none.
		if ( class_exists( 'ZDZ_Contacts' ) && method_exists( 'ZDZ_Contacts', 'log' ) ) {
			$cid = (int) ZDZ_Contacts::log( array(
				'app'      => 'estimate-creator',
				'channel'  => $channel,
				'lead_id'  => (int) preg_replace( '/\D/', '', (string) ( $row['crm_lead_id'] ?? '' ) ),
				'ref_kind' => 'estimate',
				'ref_id'   => $est,
				'outcome'  => $done ? 'reached' : 'attempt',
				'uid'      => $uid,
				'nutshell' => false,
			) );
			if ( $cid > 0 ) {
				ZEST_Followup::mark( $id, array( 'contact_id' => $cid ) );
			}
		}

		// Whoever asked is told (never the person who answered their own ask).
		$asker = (int) ( $fu['sent_by'] ?? 0 );
		$told  = null; // null = nobody asked, so there was nobody to tell
		if ( $asker > 0 && $asker !== $uid ) {
			$words = ZEST_Followup::status_words( $fu, self::who( (int) $fu['sent_to'] ), self::who( $asker ), self::who( $uid ) );
			$title = ( $test ? __( 'TEST:', 'zorderz' ) . ' ' : '' ) . ( $done ? __( 'A follow-up was answered', 'zorderz' ) : __( 'A follow-up was tried', 'zorderz' ) );
			/* translators: 1: estimate number, 2: what was recorded */
			$msg = sprintf( __( 'Estimate #%1$s: %2$s.', 'zorderz' ), $num, $words );
			if ( '' !== $note ) {
				/* translators: %s: what the person who answered typed */
				$msg .= "\n\n" . sprintf( __( 'They wrote: %s', 'zorderz' ), $note );
			}
			$told = self::tell( self::ALERT_ANSWERED, $asker, $title, $msg, 'fu' . $id . 'a' . (int) $fu['tries'] . ( $done ? 'd' : '' ), $uid );
		}
		/* translators: 1: estimate number, 2: reached, tried or handled, 3: the outcome's code or nothing */
		self::audit( $uid, 'estimate_followup_answered', sprintf( __( 'Estimate #%1$s: follow-up %2$s%3$s.', 'zorderz' ), $num, $answer, '' !== $outcome ? ' (' . $outcome . ')' : '' ) );
		return array(
			'ok'       => true,
			'id'       => $id,
			'followup' => self::view( ZEST_Followup::get( $id ), $uid ),
			'doc'      => $saved['doc'],
			'asker'    => null === $told ? null : array( 'who' => self::who( $asker ), 'told' => $told ),
		);
	}

	/**
	 * The site's day, as the billing line writes it: with slashes, as the line is read back, and the day first unless
	 * the business writes the month first (Business Profile → Locale → Date format).
	 */
	private static function today(): string {
		$f = ( class_exists( 'ZDZ_Business_Profile' ) ? ZDZ_Business_Profile::date_format() : 'n/j/Y' );
		$f = preg_match( '/\A[nm]/', $f ) ? 'n/j/Y' : 'j/n/Y';
		return function_exists( 'wp_date' ) ? (string) wp_date( $f ) : gmdate( $f );
	}

	/** A stored UTC time as the team reads it (the site's zone); '' when it is not a time. */
	private static function local_time( string $utc ): string {
		$t = '' !== $utc ? strtotime( $utc . ' UTC' ) : false;
		if ( ! $t ) {
			return '';
		}
		$f = ( class_exists( 'ZDZ_Business_Profile' ) ? ZDZ_Business_Profile::date_format() : 'n/j/Y' ) . ' g:i A';
		return function_exists( 'wp_date' ) ? (string) wp_date( $f, $t ) : gmdate( $f, $t );
	}

	/**
	 * A follow-up note's first two lines: the heading, then the estimate, who and when. Also how a take-back finds
	 * that answer's note in the queue (ZEST_Followup::drop_note), so the two are built in one place.
	 */
	public static function crm_head( string $num, int $by, string $at_utc ): string {
		$who = self::who( $by );
		return '=== ' . __( 'Estimates', 'zorderz' ) . ' - ' . __( 'FOLLOW-UP', 'zorderz' ) . " ===\n"
			/* translators: %s: estimate number */
			. sprintf( __( 'Estimate #%s', 'zorderz' ), $num ) . ' - ' . ( '' !== $who ? $who : sprintf( 'user %d', $by ) ) . ' - ' . self::local_time( $at_utc ) . "\n";
	}

	/**
	 * One answer, worded for the estimate's CRM lead (the business's own record): who, when, how, what the customer
	 * said and the person's own words. Built when the answer is saved, so each answer has its own note.
	 */
	public static function crm_text( ?array $fu, string $num, string $note ): string {
		if ( ! $fu ) {
			return '';
		}
		$how = ZEST_Followup::channel_label( (string) $fu['channel'] );
		if ( 'tried' === $fu['answer'] ) {
			$what = (int) $fu['tries'] > 1
				/* translators: %d: which try this was */
				? sprintf( __( 'Tried to reach the customer, no answer (try %d)', 'zorderz' ), (int) $fu['tries'] )
				: __( 'Tried to reach the customer, no answer', 'zorderz' );
		} else {
			$what = 'handled' === $fu['answer'] ? __( 'Had already followed up with the customer', 'zorderz' ) : __( 'Reached the customer', 'zorderz' );
		}
		$said = ZEST_Followup::outcome_label( (string) $fu['outcome'] );
		$out  = self::crm_head( $num, (int) $fu['answered_by'], (string) $fu['answered_at'] )
			. $what . ( '' !== $how ? ' (' . strtolower( $how ) . ')' : '' ) . '.';
		if ( '' !== $said ) {
			/* translators: %s: what the customer said (one of the fixed outcomes) */
			$out .= "\n" . sprintf( __( 'The customer: %s.', 'zorderz' ), $said );
		}
		if ( '' !== trim( $note ) ) {
			/* translators: %s: what the person typed */
			$out .= "\n" . sprintf( __( 'Note: %s', 'zorderz' ), trim( $note ) );
		}
		if ( (int) $fu['sent_by'] > 0 ) {
			/* translators: %s: who asked */
			$out .= "\n" . sprintf( __( 'Asked by %s.', 'zorderz' ), self::who( (int) $fu['sent_by'] ) );
		}
		if ( ! empty( $fu['declined'] ) ) {
			$out .= "\n" . __( 'Moved to Declined estimates in the Estimates app.', 'zorderz' );
		}
		return $out;
	}

	/* ================================================================ the billing line */

	/**
	 * Put $line at the bottom of the estimate's notes in the billing system (replacing an earlier follow-up line of the
	 * app's own). Returns {state: written | skipped | failed, why, why_code, approval}. "skipped" = on purpose, nothing
	 * to retry (the switch does not want it, the estimate is no longer open, has no lines, or cannot be confirmed as
	 * this customer's). "failed" = try again. approval "void" = the estimate had been approved exactly as it stood; the
	 * new line means it must be approved again before it is sent (ZEST_Approve binds an approval to the copy it was
	 * given). The follow-up row keeps the line and what happened, and the line the billing system showed before this
	 * row's first one (doc_prev).
	 *
	 * $undo given ({lines, restore}): the other way round, for a follow-up taken back. `lines`, this row's own fixed
	 * lines, are taken OFF (only those exact lines), and `restore`, the line the row's first one had replaced, goes
	 * back when it is one of the app's. $line is not used. Returns {state: removed | restored | clean | skipped |
	 * failed, ...}; "clean" = none of them was there. The row is NOT marked here: the caller decides what the record
	 * says once it knows how this went. The switch is not asked: a line the app put there comes off whatever it says now.
	 * Every other gate is the same either way: one of the app's own fixed lines or nothing, the billing view's lock, the
	 * same customer, an open estimate, history first, read back.
	 */
	public static function write_line( array $fu, array $row, string $line, int $uid, ?array $undo = null ): array {
		global $wpdb;
		$id      = (int) ( $fu['id'] ?? 0 );
		$take    = null !== $undo;
		$restore = $take ? (string) ( $undo['restore'] ?? '' ) : '';
		$off     = $take ? array_values( array_unique( array_map( 'strval', (array) ( $undo['lines'] ?? array() ) ) ) ) : array();
		$done    = static function ( string $state, string $why = '', string $approval = '' ) use ( $id, $line, $take ) {
			if ( ! $take ) {
				ZEST_Followup::mark( $id, array( 'doc_line' => $line, 'doc_state' => $state, 'doc_why' => $why ) );
			}
			return array( 'state' => $state, 'why' => ZEST_Followup::why_words( $why ), 'why_code' => $why, 'approval' => $approval );
		};
		// The last gate before the customer's document: one of the app's own fixed lines, or nothing is written.
		if ( $take ) {
			if ( ! $off || array_filter( $off, static function ( $l ) {
				return ! ZEST_Followup::line_ok( $l );
			} ) ) {
				return $done( 'failed', 'bad_line' );
			}
		} elseif ( '' === $line || ! ZEST_Followup::line_ok( $line ) ) {
			return $done( 'failed', 'no_line' );
		}
		$bid = (string) ( $row['billing_doc_id'] ?? '' );
		if ( '' === $bid ) {
			return $take ? $done( 'clean' ) : $done( 'skipped', 'local' );   // the estimate lives in this app only
		}
		if ( ! $take && ! ZEST_Followup::line_wanted( ! empty( $fu['is_test'] ) ) ) {
			return $done( 'skipped', 'off' === ZEST_Followup::mode() ? 'mode_off' : 'mode_test' );
		}
		if ( ! ctype_digit( $bid ) || ! class_exists( 'ZEST_Billing_Doc' ) || ! ZEST_Billing_Doc::available() ) {
			return $done( 'failed', 'not_connected' );
		}
		$lock = 'zest_bv_estimate_' . $bid; // the billing view's own lock: never alongside a person's edit
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) ) ) {
			return $done( 'failed', 'busy' );
		}
		try {
			// A line is put on only for a follow-up that still stands: one taken back in the moment between its save and
			// this write gets no line (and its row is left as the take-back left it).
			if ( ! $take ) {
				$cur = ZEST_Followup::get( $id );
				if ( ! $cur || 'done' !== (string) $cur['state'] ) {
					return array( 'state' => 'skipped', 'why' => ZEST_Followup::why_words( 'taken_back' ), 'why_code' => 'taken_back', 'approval' => '' );
				}
			}
			$doc = ZEST_Billing_Doc::read( 'estimate', $bid );   // null when it cannot be read, or another document answered
			if ( ! is_array( $doc ) ) {
				return $done( 'failed', 'unread' );
			}
			// Only onto the estimate of the customer this app recorded for it. In doubt, nothing is written.
			$cust = (string) ( $doc['customerid'] ?? '' );
			$mine = (string) ( $row['billing_cust_id'] ?? '' );
			$old  = '' === $mine; // this app holds no billing customer for it
			if ( $old && '' !== $cust ) {
				$mine = self::confirm_customer( $row, $cust );
				if ( null === $mine ) {
					// Not a "no": the client could not be read, so nothing is known. Try again can settle it.
					return $done( 'failed', 'customer_unread' );
				}
			}
			if ( '' === $cust || '' === $mine || $cust !== $mine ) {
				return $done( 'skipped', $old ? 'customer_old' : 'customer' );
			}
			if ( 0 !== (int) ( $doc['vis_state'] ?? 0 ) ) {
				return $done( 'skipped', 'archived' );
			}
			$signal = class_exists( 'ZEST_FreshBooks' ) ? ZEST_FreshBooks::status_signal( $doc ) : '';
			if ( in_array( $signal, array( 'customer_accepted', 'invoiced' ), true ) ) {
				return $done( 'skipped', 'accepted' );
			}
			$lines = ZEST_Line_Edits::lines_from_doc( $doc );
			if ( ! $lines ) {
				return $done( 'skipped', 'no_lines' );
			}
			$notes = (string) ( $doc['notes'] ?? '' );
			$own   = array();
			$first = false;
			$was   = '';
			if ( $take ) {
				$u = ZEST_Followup::without_lines( $notes, $off, $restore );
				if ( ! $u['found'] ) {
					return $done( 'clean' ); // none of the row's lines is on the estimate: nothing to take off
				}
				$w = array( 'notes' => $u['notes'], 'before' => implode( "\n", $off ), 'after' => $u['restored'] );
			} else {
				$w = ZEST_Followup::with_line( $notes, $line );
				// What a take-back will need, recorded BEFORE the write (a write the billing system applied but never
				// answered must not look, at the retry, like a line that "was already there"):
				//   doc_on    this row's own lines that may be on the estimate, newest last (at most two: its answer's
				//             and a reopen's). After a write that is read back, only the new one.
				//   doc_prev  the line the billing system showed before this row's FIRST look, so a take-back can put it
				//             back, or, when it is this very line (the same person, the same answer, the same day as the
				//             follow-up before), know the line is not this row's to take off. A reopen does not move it.
				$cur   = ZEST_Followup::get( $id );
				$own   = $cur ? ZEST_Followup::lines_on( $cur ) : array();
				$first = $cur && ! $own && '' === (string) ( $cur['doc_prev'] ?? '' ) && 'written' !== (string) $cur['doc_state'];
				$was   = $w['changed'] ? (string) $w['before'] : $line;
				if ( ! $w['changed'] ) {
					// The billing system already ends with exactly this line: nothing to write.
					ZEST_Followup::mark( $id, $first ? array( 'doc_on' => $line, 'doc_prev' => $line ) : array( 'doc_on' => $line ) );
					return $done( 'written' );
				}
				$w['after'] = $line;
			}
			// Was it approved exactly as it stood? Then this change means it must be approved again before a send.
			$approved = (string) ( $row['approved_fp'] ?? '' );
			$void     = '' !== $approved && class_exists( 'ZEST_Approve' ) && hash_equals( $approved, ZEST_Approve::approval_fp( $doc ) );
			$me       = self::initials( $uid );
			$num      = ltrim( (string) ( $doc['estimate_number'] ?? ( $row['billing_doc_num'] ?? '' ) ), '#' );
			$batch    = substr( hash( 'sha256', 'fu|' . $id . '|' . $bid . '|' . microtime( true ) ), 0, 32 );
			$log      = array( array( 'line_no' => 0, 'type' => 'text', 'field' => 'notes', 'slot' => '', 'option' => '', 'before' => $w['before'], 'after' => $w['after'], 'before_amount' => null, 'after_amount' => null ) );
			// History first: no record, no write (the billing view's rule).
			$reason = $take ? __( 'Follow-up taken back (recorded by mistake); its fixed line removed.', 'zorderz' ) : __( 'Follow-up recorded (the line holds fixed words only).', 'zorderz' );
			if ( ! ZEST_Billing_View::log( $batch, 'estimate', $bid, $num, $uid, $me, $log, $reason ) ) {
				return $done( 'failed', 'history' );
			}
			if ( ! $take ) {
				$mark = array( 'doc_on' => implode( "\n", array_slice( array_values( array_unique( array_merge( $own, array( $line ) ) ) ), -2 ) ) );
				if ( $first && '' !== $was ) {
					$mark['doc_prev'] = $was;
				}
				ZEST_Followup::mark( $id, $mark );
			}
			$put = ZEST_Billing_Doc::put( 'estimate', $bid, array( 'lines' => ZEST_Line_Edits::to_wire_lines( $lines ), 'notes' => $w['notes'] ), (string) ( $doc['create_date'] ?? '' ) );
			if ( empty( $put['ok'] ) ) {
				ZEST_Billing_View::mark( $batch, 'failed', false );
				return $done( 'failed', 'refused' );
			}
			// Read back: the lines as they were, and the notes ending with exactly the line (or, for a line taken off, no
			// longer holding it, and ending with the one put back), or it is not "written".
			$read     = ZEST_Billing_Doc::read( 'estimate', $bid );
			$saved    = is_array( $read ) ? $read : ( is_array( $put['doc'] ?? null ) ? $put['doc'] : array() );
			$mismatch = is_array( $read ) ? ZEST_Line_Edits::verify( $lines, $read ) : '';
			$kept     = array_key_exists( 'notes', $saved ) ? (string) $saved['notes'] : $w['notes'];
			$kept_l   = array_map( 'trim', (array) preg_split( '/\r\n|\r|\n/', rtrim( $kept ) ) );
			$last     = (string) ( array_slice( $kept_l, -1 )[0] ?? '' );
			$as_asked = $take ? ( ! array_intersect( $off, $kept_l ) && ( '' === $w['after'] || $last === $w['after'] ) ) : $last === $line;
			ZEST_Billing_View::mark( $batch, 'saved', is_array( $read ) && '' === $mismatch && $as_asked );
			// This app's own copy: ONLY its notes get the same change (never its lines: a notes-only write must not
			// rebuild them), so an older screen can't put the old notes back.
			$local = $take
				? ZEST_Followup::without_lines( (string) ( $row['notes'] ?? '' ), $off, $w['after'] )
				: ZEST_Followup::with_line( (string) ( $row['notes'] ?? '' ), $line );
			if ( $take ? $local['found'] : $local['changed'] ) {
				$data = array( 'notes' => $local['notes'], 'seal_hold' => (string) ( $row['seal_hold'] ?? '' ) );
				$data = array_intersect_key( (array) apply_filters( 'zest_row_data', $data, $uid ), $data );
				$wpdb->update( ZEST_DB::estimates_table(), $data, array( 'id' => (int) $row['id'] ) );
			}
			$differs = '' === $mismatch ? '' : 'differs:' . (int) $mismatch;
			if ( $take ) {
				if ( ! $as_asked ) {
					return $done( 'failed', 'still_there', $void ? 'void' : '' );
				}
				return $done( '' !== $w['after'] ? 'restored' : 'removed', $differs, $void ? 'void' : '' );
			}
			if ( ! $as_asked ) {
				return $done( 'failed', 'not_last', $void ? 'void' : '' );
			}
			// Read back as asked: the new line replaced whatever of this row's was there, so it alone is on the estimate.
			ZEST_Followup::mark( $id, array( 'doc_on' => $line ) );
			return $done( 'written', $differs, $void ? 'void' : '' );
		} catch ( \Throwable $e ) {
			error_log( 'Zorderz Estimates: follow-up line for estimate id ' . (int) ( $row['id'] ?? 0 ) . ' failed (' . get_class( $e ) . ').' );
			return $done( 'failed', 'error' );
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/** A name as compared: lower case, letters and digits only, single spaces. */
	public static function name_key( string $s ): string {
		$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s ) : strtolower( $s );
		return trim( (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $s ) );
	}

	/**
	 * An estimate this app holds no billing customer for. The estimate in the billing system names its client by id;
	 * that client is read, and ONLY when its name (first and last, or the organization) is exactly the name this app
	 * holds for the estimate is the id kept on the row and returned. '' = it is not, or there is no name to compare:
	 * nothing is written. null = the client could not be read just now: nothing is known either way.
	 */
	private static function confirm_customer( array $row, string $cust ): ?string {
		$mine = self::name_key( (string) ( $row['customer_name'] ?? '' ) );
		if ( '' === $mine || ! ctype_digit( $cust ) ) {
			return '';
		}
		$client = ZEST_Billing_Doc::client( $cust );
		if ( false === $client ) {
			return null;
		}
		if ( ! is_array( $client ) ) {
			return '';
		}
		$person = self::name_key( (string) ( $client['fname'] ?? '' ) . ' ' . (string) ( $client['lname'] ?? '' ) );
		$org    = self::name_key( (string) ( $client['organization'] ?? '' ) );
		if ( $mine !== $person && $mine !== $org ) {
			return '';
		}
		global $wpdb;
		$wpdb->update( ZEST_DB::estimates_table(), array( 'billing_cust_id' => $cust ), array( 'id' => (int) $row['id'] ) );
		return $cust;
	}

	/** Write a billing line that did not get through the first time. The stored line is checked again. */
	public static function ajax_line_retry(): void {
		$uid = self::gate( true );
		self::reply( self::line_retry( isset( $_POST['fu'] ) ? absint( wp_unslash( $_POST['fu'] ) ) : 0, $uid ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in self::gate() (ZEST_Access::guard()) above
	}

	public static function line_retry( int $fu_id, int $uid ): array {
		if ( self::pv() ) {
			return self::no( __( 'Not available in Privacy View.', 'zorderz' ), 403, 'zdz_privacy_view' );
		}
		$fu  = ZEST_Followup::get( $fu_id );
		$row = $fu ? ZEST_Access::row( (int) $fu['estimate_id'] ) : null;
		// Who may is settled before anything is said about the follow-up, so an id tells a stranger nothing.
		if ( ! $fu || ! $row || ! ( self::may_answer( $uid, $row, null ) || ( (int) $fu['answered_by'] === $uid && ! self::revoked( $uid, (int) $row['id'] ) ) ) ) {
			return self::no( __( 'You can only do that for your own estimates.', 'zorderz' ), 403 );
		}
		// Under the estimate's lock, on the row as it is NOW, so a retry and a take-back never cross.
		$r = self::locked( (int) $fu['estimate_id'], static function () use ( $fu, $row, $uid ) {
			$fu = ZEST_Followup::get( (int) $fu['id'] );
			if ( ! $fu || 'done' !== $fu['state'] || 'failed' !== $fu['doc_state'] ) {
				return self::no( __( 'There is nothing to retry for that follow-up.', 'zorderz' ) );
			}
			$newest = ZEST_Followup::latest( (int) $fu['estimate_id'] );
			if ( ! $newest || (int) $newest['id'] !== (int) $fu['id'] ) {
				return self::no( __( 'A newer follow-up replaced that one, so its line is not written.', 'zorderz' ) );
			}
			return array( 'ok' => true, 'doc' => self::write_line( $fu, $row, (string) $fu['doc_line'], $uid ) );
		} );
		if ( empty( $r['ok'] ) ) {
			return $r;
		}
		return array( 'ok' => true, 'followup' => self::view( ZEST_Followup::get( (int) $fu['id'] ), $uid ), 'doc' => $r['doc'] );
	}

	/* ================================================================ 4. reopen a declined estimate */

	public static function ajax_reopen(): void {
		$uid = self::gate( true );
		$row = ZEST_Access::row( self::request_id() );
		if ( ! $row ) {
			self::reply( self::no( __( 'This app has no record of that estimate, so it can\'t be followed up from here.', 'zorderz' ) ) );
		}
		self::reply( self::reopen( $row, $uid, isset( $_POST['expect'] ) ? sanitize_text_field( wp_unslash( $_POST['expect'] ) ) : '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in self::gate() (ZEST_Access::guard()) above
	}

	public static function reopen( array $row, int $uid, string $expect ): array {
		if ( self::pv() ) {
			return self::no( __( 'Not available in Privacy View.', 'zorderz' ), 403, 'zdz_privacy_view' );
		}
		$est = (int) $row['id'];
		if ( ! self::may_answer( $uid, $row, null ) ) {
			return self::no( __( 'You can only reopen your own estimates.', 'zorderz' ), 403 );
		}
		$r = self::locked( $est, static function () use ( $est, $uid, $expect, $row ) {
			$fu = ZEST_Followup::latest( $est );
			if ( ! $fu || ZEST_Followup::token( $fu ) !== $expect ) {
				return self::changed();
			}
			if ( 'done' !== $fu['state'] || empty( $fu['declined'] ) ) {
				return self::changed( __( 'That estimate is not among the declined ones any more. The list was refreshed.', 'zorderz' ) );
			}
			if ( ! ZEST_Followup::reopen( $fu, $uid ) ) {
				return self::changed( __( 'Someone else just reopened it. The list was refreshed.', 'zorderz' ) );
			}
			// state '' = the billing system was not touched (this app's copy says the estimate is no longer open). The line
			// is written inside the lock, like an answer's.
			$doc = array( 'state' => '', 'why' => '', 'why_code' => '', 'approval' => '' );
			if ( '' === self::not_open( $row ) ) {
				$line = ZEST_Followup::line( 'reopened', self::initials( $uid ), self::today() );
				$doc  = self::write_line( (array) ZEST_Followup::get( (int) $fu['id'] ), $row, $line, $uid );
			} else {
				$doc['why_code'] = 'not_open';
				$doc['why']      = ZEST_Followup::why_words( 'not_open' );
			}
			return array( 'ok' => true, 'id' => (int) $fu['id'], 'doc' => $doc );
		} );
		if ( empty( $r['ok'] ) ) {
			return $r;
		}
		/* translators: %s: estimate number */
		self::audit( $uid, 'estimate_followup_reopened', sprintf( __( 'Estimate #%s reopened (was declined).', 'zorderz' ), self::number( $row ) ) );
		return array( 'ok' => true, 'followup' => self::view( ZEST_Followup::get( (int) $r['id'] ), $uid ), 'doc' => $r['doc'] );
	}

	/* ================================================================ 5. history (with the typed words) */

	public static function ajax_history(): void {
		$uid = self::gate( false );
		self::reply( self::history( self::request_id(), $uid ) );
	}

	public static function history( int $estimate_id, int $uid ): array {
		$pv  = self::pv();
		$row = ZEST_Access::row( $estimate_id );
		// For an account in Privacy View "this app has no such estimate" and "it is not yours" are one answer, so which
		// ids exist is not something it can test.
		if ( ! $row ) {
			return $pv ? self::no( __( 'Estimate not found.', 'zorderz' ), 404 ) : self::no( __( 'This app has no record of that estimate, so it can\'t be followed up from here.', 'zorderz' ) );
		}
		// The table is never made on view for that account (gate()), so a site that has none yet reads as "nothing
		// recorded" rather than an error. Whose the estimate is, is asked of this site's row only.
		$waiting = ZEST_Followup::quiet( static function () use ( $estimate_id ) {
			return ZEST_Followup::waiting( $estimate_id );
		}, null );
		if ( ! self::may_answer( $uid, $row, is_array( $waiting ) ? $waiting : null ) ) {
			return $pv ? self::no( __( 'Estimate not found.', 'zorderz' ), 404 ) : self::no( __( 'You can only see this for your own estimates.', 'zorderz' ), 403 );
		}
		$out  = array();
		$hist = (array) ZEST_Followup::quiet( static function () use ( $estimate_id ) {
			return ZEST_Followup::history( $estimate_id );
		}, array() );
		$all  = self::may_send( $uid, $row );
		foreach ( $hist as $r ) {
			$v = (array) self::view( $r, $uid );
			// An ask taken back was sent on the wrong estimate: its note, like a taken-back answer's words, only for
			// whoever sent it or may re-send.
			$v['ask_note'] = ( 'withdrawn' === $r['state'] && (int) $r['sent_by'] !== $uid && ! $all ) ? '' : (string) ( $r['ask_note'] ?? '' );
			$v['note']     = (string) ( $r['answer_note'] ?? '' );
			$v['channel']  = ZEST_Followup::channel_label( (string) $r['channel'] );
			$v['tried']    = array();
			foreach ( ZEST_Followup::tries_of( $r ) as $t ) {
				$v['tried'][] = array(
					'when'    => (string) ( $t['at'] ?? '' ),
					'who'     => self::who( (int) ( $t['by'] ?? 0 ) ),
					'channel' => ZEST_Followup::channel_label( (string) ( $t['channel'] ?? '' ) ),
					'note'    => (string) ( $t['note'] ?? '' ),
				);
			}
			$v['doc_line'] = 'written' === ( $r['doc_state'] ?? '' ) ? (string) ( $r['doc_line'] ?? '' ) : '';
			$v['crm']      = (string) ( $r['crm_state'] ?? '' );
			$v['undone']   = self::undone_view( $r, $uid, $all );   // what was taken back from it, oldest first
			$out[]         = $v;
		}
		$reply = array( 'number' => self::number( $row ), 'rows' => $out );
		// For an account in Privacy View what people typed is not sent, and the number is a pseudonym.
		if ( $pv ) {
			$reply = ZEST_Privacy_View::fu_history( $reply );
		}
		return array( 'ok' => true ) + $reply;
	}

	/* ================================================================ 6. taken back */

	/** One line saying what an undo_log entry had said: no customer, no typed words. */
	public static function undone_words( array $e ): string {
		$who = self::who( (int) ( $e['who'] ?? 0 ) );
		if ( 'answer' === ( $e['kind'] ?? '' ) ) {
			return ZEST_Followup::status_words( array( 'state' => 'done', 'answer' => (string) ( $e['answer'] ?? '' ), 'outcome' => (string) ( $e['outcome'] ?? '' ) ), '', '', $who );
		}
		if ( 'try' === ( $e['kind'] ?? '' ) ) {
			/* translators: %s: who tried */
			return sprintf( __( '%s tried, no answer', 'zorderz' ), '' !== $who ? $who : __( 'Someone', 'zorderz' ) );
		}
		return ZEST_Followup::status_words( array( 'state' => 'open' ), self::who( (int) ( $e['to'] ?? 0 ) ), $who, '' );
	}

	/**
	 * What was taken back from a row, for the history sheet. The words typed with an answer that was taken back are
	 * shown only to the person who typed them, the person who took it back, or whoever may re-send: they were, by
	 * definition, recorded on the wrong estimate, and may be about another customer.
	 */
	private static function undone_view( array $r, int $viewer, bool $all ): array {
		$out = array();
		foreach ( ZEST_Followup::undone_of( $r ) as $e ) {
			$see   = $all || (int) ( $e['who'] ?? 0 ) === $viewer || (int) ( $e['by'] ?? 0 ) === $viewer;
			$out[] = array(
				'kind'    => (string) ( $e['kind'] ?? '' ),
				'when'    => (string) ( $e['at'] ?? '' ),
				'by'      => self::who( (int) ( $e['by'] ?? 0 ) ),
				'words'   => self::undone_words( $e ),
				'was'     => (string) ( $e['when'] ?? '' ),
				'channel' => ZEST_Followup::channel_label( (string) ( $e['channel'] ?? '' ) ),
				'note'    => $see ? (string) ( $e['note'] ?? '' ) : '',
			);
		}
		return $out;
	}

	public static function ajax_undo(): void {
		$uid = self::gate( true );
		$row = ZEST_Access::row( self::request_id() );
		if ( ! $row ) {
			self::reply( self::no( __( 'This app has no record of that estimate, so it can\'t be followed up from here.', 'zorderz' ) ) );
		}
		$r = self::undo( $row, $uid, isset( $_POST['expect'] ) ? sanitize_text_field( wp_unslash( $_POST['expect'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in self::gate() (ZEST_Access::guard()) above
		if ( ! empty( $r['ok'] ) && 'corrected' === ( $r['crm'] ?? '' ) && ! empty( $r['id'] ) ) {
			self::schedule_note( (int) $r['id'], 5, 'u' );
		}
		unset( $r['id'] );
		self::reply( $r );
	}

	/**
	 * Take back the last thing recorded on an estimate's follow-up.
	 *
	 * In this order, under one hold of the estimate's lock, so a half-done take-back is never left looking finished:
	 *   1. the follow-up is the one the screen showed (token), and this person may
	 *   2. the billing system (an answer whose line may be there): the line comes off, the one it replaced goes back.
	 *      If the billing system cannot be changed right now, NOTHING is taken back and the person is told to try again.
	 *   3. the record, on the row exactly as it was read (one guarded statement)
	 * and then: a fixed-words correction for the CRM lead (the first note there stays), and the people told.
	 */
	public static function undo( array $row, int $uid, string $expect ): array {
		if ( self::pv() ) {
			return self::no( __( 'Not available in Privacy View.', 'zorderz' ), 403, 'zdz_privacy_view' );
		}
		$est  = (int) $row['id'];
		$num  = self::number( $row );
		$test = self::is_test( $row );
		// ONE hold of the estimate's lock from the first look to the record (the billing system included), so no re-send,
		// answer, reopen, retry or second take-back can land in between.
		$out = self::locked( $est, static function () use ( $est, $row, $uid, $expect, $num ) {
			// 1. The follow-up as this person's screen showed it, and whether they may.
			$fu = ZEST_Followup::latest( $est );
			if ( ! $fu || ZEST_Followup::token( $fu ) !== $expect ) {
				return self::changed();
			}
			if ( ! self::may_undo( $uid, $row, $fu ) ) {
				return self::no( __( 'You can only take back a follow-up on your own estimates.', 'zorderz' ), 403 );
			}
			$kind = ZEST_Followup::undo_kind( $fu );

			// 2. The billing system first. This row's own lines: those that may be on the estimate (doc_on: a reopen's
			//    line that failed is not the line that is there). A line that was on the estimate before this row
			//    (doc_prev) is not this row's to take off: when it is the very same line it stays ("kept"); otherwise it
			//    goes back in place of this row's. state '' = nothing of this row's was ever written there, so the
			//    billing system is not touched.
			$doc = array( 'state' => '', 'why' => '', 'why_code' => '', 'approval' => '' );
			if ( 'answer' === $kind ) {
				$prev = (string) ( $fu['doc_prev'] ?? '' );
				$mine = ZEST_Followup::lines_on( $fu );
				$off  = array_values( array_diff( array_unique( $mine ), array( $prev ) ) );
				if ( $off ) {
					$doc = self::write_line( $fu, $row, '', $uid, array( 'lines' => $off, 'restore' => $prev ) );
					if ( 'failed' === $doc['state'] ) {
						/* translators: %s: why, or nothing */
						return self::no( sprintf( __( 'Nothing was taken back: the line could not be taken off the billing estimate just now%s. Try again in a moment.', 'zorderz' ), '' !== $doc['why'] ? ' (' . $doc['why'] . ')' : '' ) );
					}
				} elseif ( $mine ) {
					$doc['state'] = 'kept';
				}
			}

			// 3. The record: on the row exactly as it was read (the lock should make this certain; it is checked anyway).
			$now = ZEST_Followup::latest( $est );
			$did = ( $now && (int) $now['id'] === (int) $fu['id'] && ZEST_Followup::token( $now ) === $expect ) ? ZEST_Followup::undo( $now, $uid ) : null;
			if ( ! $did ) {
				if ( in_array( $doc['state'], array( 'removed', 'restored' ), true ) ) {
					// The line is off the estimate but the record was not changed here. If the answer still stands, say so
					// on its row, so "Try again" on the list puts the line back, and tell the person plainly.
					$cur = ZEST_Followup::get( (int) $fu['id'] );
					if ( $cur && 'done' === $cur['state'] ) {
						ZEST_Followup::mark( (int) $fu['id'], array( 'doc_state' => 'failed', 'doc_on' => '', 'doc_why' => 'undo_unfinished' ) );
					}
					return self::changed( __( 'This follow-up changed while its line was being taken off the billing estimate, so it was not taken back. The list was refreshed: if it still shows the answer, use Try again there to put its line back.', 'zorderz' ) );
				}
				return self::changed();
			}

			// 4. The estimate's CRM lead (still under the lock: the queue is the row's). A note that has not gone yet is
			//    dropped, so words typed on the wrong estimate never reach that customer's lead; one that has gone gets a
			//    correction in fixed words, and stays. An ask was never noted there.
			$entry = (array) $did['entry'];
			$noted = '';
			if ( 'ask' !== $kind ) {
				$head = self::crm_head( $num, (int) ( $entry['who'] ?? 0 ), (string) ( $entry['when'] ?? '' ) );
				if ( ZEST_Followup::drop_note( (int) $fu['id'], $head ) ) {
					$noted = 'dropped';
				} elseif ( 'none' === (string) $fu['crm_state'] ) {
					$noted = 'none'; // no CRM lead is this estimate's: nothing went, nothing to correct
				} else {
					$me   = self::who( $uid );
					$when = self::local_time( (string) ( $entry['when'] ?? '' ) );
					$text = '=== ' . __( 'Estimates', 'zorderz' ) . ' - ' . __( 'FOLLOW-UP TAKEN BACK', 'zorderz' ) . " ===\n"
						/* translators: %s: estimate number */
						. sprintf( __( 'Estimate #%s', 'zorderz' ), $num ) . ' - ' . ( '' !== $me ? $me : sprintf( 'user %d', $uid ) ) . ' - ' . self::local_time( current_time( 'mysql', true ) ) . "\n"
						. ( '' !== $when
							/* translators: 1: when the note was written, 2: what it had said, in fixed words */
							? sprintf( __( 'The follow-up note of %1$s (%2$s) was recorded by mistake and has been taken back. Disregard it.', 'zorderz' ), $when, self::undone_words( $entry ) )
							/* translators: %s: what it had said, in fixed words */
							: sprintf( __( 'The follow-up note (%s) was recorded by mistake and has been taken back. Disregard it.', 'zorderz' ), self::undone_words( $entry ) ) );
					$noted = ZEST_Followup::queue_note( (int) $fu['id'], $text ) ? 'corrected' : '';
				}
			}
			return array( 'ok' => true, 'fu' => $fu, 'kind' => $kind, 'doc' => $doc, 'did' => $did, 'noted' => $noted );
		} );
		if ( empty( $out['ok'] ) ) {
			return $out;
		}
		$fu    = (array) $out['fu'];
		$kind  = (string) $out['kind'];
		$did   = (array) $out['did'];
		$entry = (array) $did['entry'];
		$words = self::undone_words( $entry );
		$me    = self::who( $uid );

		// 5. The people it concerns (never the person doing it): whoever asked and whoever answered; for an ask, the
		//    person asked. The number is enough: a notice names no customer.
		/* translators: %s: estimate number */
		$head = sprintf( __( 'Estimate #%s:', 'zorderz' ), $num ) . ' ';
		$told = array();
		$back = is_array( $did['back'] ?? null ) ? $did['back'] : null; // the earlier ask this one had replaced, waiting again
		if ( 'ask' === $kind ) {
			$to = (int) $fu['sent_to'];
			if ( $to > 0 && $to !== $uid ) {
				$still = $back && (int) $back['sent_to'] === $to;
				$name  = '' !== $me ? $me : __( 'Someone', 'zorderz' );
				$body  = $still
					/* translators: %s: who took it back */
					? sprintf( __( '%s took back the newer request to follow up (sent by mistake). The earlier request still stands: it is yours to do.', 'zorderz' ), $name )
					/* translators: %s: who took it back */
					: sprintf( __( '%s took back the request to follow up (sent by mistake). There is nothing for you to do.', 'zorderz' ), $name );
				$used   = self::tell( self::ALERT_ASKED, $to, ( $test ? __( 'TEST:', 'zorderz' ) . ' ' : '' ) . __( 'A follow-up request was taken back', 'zorderz' ), $head . $body, 'fu' . (int) $fu['id'] . 'w', $uid );
				$told[] = array( 'who' => self::who( $to ), 'told' => $used );
			}
		} else {
			$again = '';
			if ( 'withdrawn' !== $did['state'] ) {
				$whom = self::who( (int) $fu['sent_to'] );
				/* translators: %s: who it waits on */
				$again = ' ' . sprintf( __( 'It is waiting on %s again.', 'zorderz' ), '' !== $whom ? $whom : __( 'the salesperson', 'zorderz' ) );
			}
			foreach ( array_unique( array_filter( array( (int) $fu['sent_by'], (int) ( $entry['who'] ?? 0 ) ) ) ) as $p ) {
				if ( $p === $uid ) {
					continue;
				}
				/* translators: 1: who took it back, 2: what was taken back, in fixed words */
				$body   = sprintf( __( '%1$s took back "%2$s" (recorded by mistake).', 'zorderz' ), '' !== $me ? $me : __( 'Someone', 'zorderz' ), $words ) . $again;
				$used   = self::tell( self::ALERT_ANSWERED, $p, ( $test ? __( 'TEST:', 'zorderz' ) . ' ' : '' ) . __( 'A follow-up was taken back', 'zorderz' ), $head . $body, 'fu' . (int) $fu['id'] . 'u' . (int) ( $fu['undone'] ?? 0 ), $uid );
				$told[] = array( 'who' => self::who( $p ), 'told' => $used );
			}
		}
		/* translators: 1: estimate number, 2: answer, try or ask */
		self::audit( $uid, 'estimate_followup_taken_back', sprintf( __( 'Estimate #%1$s: follow-up %2$s taken back (recorded by mistake).', 'zorderz' ), $num, $kind ) );
		$latest = ZEST_Followup::latest( $est );
		$view   = self::view( $latest, $uid );
		return array(
			'ok'       => true,
			'id'       => (int) $fu['id'],
			'followup' => $view,
			'kind'     => $kind,
			'words'    => $words,
			'doc'      => $out['doc'],
			'crm'      => (string) $out['noted'],   // dropped (it had not gone) | corrected (it had: a correction follows) | none | ''
			'back'     => ( $back && $latest && (int) $latest['id'] === (int) $back['id'] && $view ) ? (string) $view['words'] : '',
			'told'     => $told,
		);
	}

	/* ================================================================ the CRM notes (cron) */

	/**
	 * Post the follow-up's waiting notes to the estimate's CRM lead: only a lead that is positively this estimate's
	 * (ZEST_Nutshell::estimate_lead_id, the rule "Add measurements" uses), so a lead that names another estimate is
	 * never written. The notes are taken off the row first (one statement, so two runs never post the same note),
	 * posted in order, and any that did not go are put back and tried again in a quarter of an hour, three times at
	 * most. $mark only makes each answer's event its own.
	 */
	public static function cron_crm_note( $id, $mark = '' ): void {
		$id = (int) $id;
		$fu = $id > 0 ? ZEST_Followup::get( $id ) : null;
		if ( ! $fu ) {
			return;
		}
		$notes = null;
		for ( $i = 0; $i < 3 && null === $notes; $i++ ) {
			$notes = ZEST_Followup::take_notes( $id );
		}
		if ( ! $notes ) {
			return; // nothing waiting (another run took them), or the queue would not hold still: the next run takes it
		}
		$tries = (int) $fu['crm_tries'] + 1;
		$left  = $notes;
		try {
			$row  = ZEST_Access::row( (int) $fu['estimate_id'] );
			$lead = ( $row && class_exists( 'ZEST_Nutshell' ) ) ? ZEST_Nutshell::estimate_lead_id( $row ) : 0;
			if ( 0 === $lead ) {
				// No CRM, or no lead that is this estimate's: the words stay in the app (the row), and nothing is retried.
				ZEST_Followup::settle_notes( $id, 'none' );
				return;
			}
			if ( $lead > 0 ) {
				foreach ( $notes as $k => $text ) {
					if ( ! ZEST_Nutshell::add_lead_note( $lead, $text ) ) {
						break;
					}
					unset( $left[ $k ] );
				}
			}
		} catch ( \Throwable $e ) {
			error_log( 'Zorderz Estimates: follow-up ' . $id . ' CRM note failed (' . get_class( $e ) . ').' );
		}
		if ( ! $left ) {
			ZEST_Followup::settle_notes( $id, 'posted' );
			return;
		}
		ZEST_Followup::put_back( $id, array_values( $left ), $tries >= self::CRM_MAX ? 'failed' : 'queued', $tries );
		if ( $tries < self::CRM_MAX ) {
			self::schedule_note( $id, 900, 'r' );
		}
	}
}
