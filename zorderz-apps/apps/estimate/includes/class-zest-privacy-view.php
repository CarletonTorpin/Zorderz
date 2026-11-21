<?php
/**
 * ZEST_Privacy_View - Estimates for an account in Privacy View (the platform's ZDZ_Privacy_View), to look only.
 *
 * An administrator may put an account in Privacy View (an assistant's account, a demonstration, an outside
 * contractor). Estimates stays closed to such an account until an administrator opens it (Zorderz -> Settings,
 * "Estimates in Privacy View": option zest_privacy_view_mode, off by default). Opened, the account may look and
 * nothing else. The platform masks every reply on the way out; this class is the app's own part, where the data is:
 *
 *   four reads   zest_list_open (the open list, with each estimate's follow-up), zest_history (past estimates),
 *                zest_fu_history (one estimate's follow-ups) and zest_pv_estimate (one estimate to look at: a read of
 *                its own, from THIS SITE'S copy, never the billing system, and by this app's row id only, never by
 *                number, so a number cannot be guessed at). The app tells the platform about exactly these
 *                (zdz_privacy_view_allowed_ajax, zdz_privacy_view_ajax_keys, zdz_privacy_view_allowed_apps) and
 *                guard() refuses every other request of this app for the account, whatever any list says. No REST
 *                route of this app is open to it.
 *   pseudonyms   each reply is built again from a list of fields (a field added to a handler later is not sent until
 *                it is added here): the customer, the number, every team member and the street as the platform's
 *                pseudonyms. No e-mail, phone, town, postal code, CRM id, photo, reference or dictated text is sent
 *                at all, and no price (the account sees none anywhere in Estimates).
 *   no words     NOTHING A PERSON TYPED OR DICTATED IS SENT. A follow-up's notes read "[not shown in Privacy View]".
 *                Of an estimate's notes only this app's own fixed follow-up lines are given, with how many other lines
 *                were left out. A line is its quantity; its name and description are not sent (only whether it has
 *                any), because words can hold a name, initials or a number in a shape no rule knows.
 *   look only    every may-do flag is false, the follow-up token is empty, nothing is written on view (the follow-up
 *                table is not made for this account), and the screen draws no control that writes.
 *
 * Without the platform's class on() is false and nothing here runs.
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZEST_Privacy_View {

	/** The only requests of this app an account in Privacy View may make, each with the only fields it may carry. */
	const READS = array(
		'zest_list_open'   => array(),
		'zest_history'     => array(),
		'zest_fu_history'  => array( 'id' ),
		'zest_pv_estimate' => array( 'id' ),
	);

	/** off | look: whether Estimates is open (to look only) to an account in Privacy View. */
	const MODE_OPTION = 'zest_privacy_view_mode';

	/** In place of anything a person typed. */
	const HIDDEN = '[not shown in Privacy View]';

	/** In place of a name that has no pseudonym. */
	const NAME = '[name]';

	/** What this app writes in an estimate row's status. Anything else is not sent. */
	const STATUSES = array( 'created', 'stub', 'open', 'updated', 'sent', 'accepted', 'imported', 'converted' );

	/** A follow-up's states and what was done to its billing line: fixed words only. */
	const FU_STATES  = array( 'open', 'tried', 'done', 'superseded', 'withdrawn' );
	const DOC_STATES = array( 'written', 'skipped', 'failed', 'removed', 'restored', 'kept', 'clean' );
	const CRM_STATES = array( 'queued', 'posting', 'posted', 'none', 'failed' );

	private static $booted = false;

	/**
	 * Hooked when the app's files load, so the platform finds the app's part whenever it asks (its gate runs before
	 * any app boots).
	 */
	public static function boot(): void {
		if ( self::$booted || ! function_exists( 'add_filter' ) ) {
			return;
		}
		self::$booted = true;
		add_filter( 'zdz_privacy_view_allowed_ajax', array( __CLASS__, 'allowed_ajax' ) );
		add_filter( 'zdz_privacy_view_ajax_keys', array( __CLASS__, 'ajax_keys' ) );
		add_filter( 'zdz_privacy_view_allowed_apps', array( __CLASS__, 'allowed_apps' ) );
		add_filter( 'zdz_privacy_view_allowed_rest', array( __CLASS__, 'allowed_rest' ), PHP_INT_MAX );
		// admin-ajax.php runs admin_init before it runs the request's own handler
		add_action( 'admin_init', array( __CLASS__, 'guard' ), 0 );
		add_action( 'wp_ajax_zest_pv_estimate', array( __CLASS__, 'ajax_estimate' ) );
	}

	/* ================================================================== the switch and the lists */

	/** Is the signed-in account (or this one) in Privacy View? */
	public static function on( $user_id = null ): bool {
		return class_exists( 'ZDZ_Privacy_View' ) && method_exists( 'ZDZ_Privacy_View', 'is_on' ) && (bool) ZDZ_Privacy_View::is_on( $user_id );
	}

	/** Has an administrator opened Estimates, to look only, to accounts in Privacy View? Anything unreadable is no. */
	public static function open(): bool {
		return function_exists( 'get_option' ) && 'look' === get_option( self::MODE_OPTION, 'off' );
	}

	/**
	 * May this account in Privacy View look at Estimates now? An administrator opened it, and the platform lists the
	 * app for the account (so the four reads open together, or not at all).
	 */
	public static function may_look( int $uid ): bool {
		if ( ! self::open() || $uid <= 0 ) {
			return false;
		}
		if ( class_exists( 'ZDZ_Plugin_API' ) && method_exists( 'ZDZ_Plugin_API', 'user_can_access_app' ) ) {
			return (bool) ZDZ_Plugin_API::user_can_access_app( $uid, defined( 'ZEST_APP_ID' ) ? ZEST_APP_ID : 'estimate-creator' );
		}
		return true;   // no platform to ask
	}

	/** May an account in Privacy View make this request? Exact names, and only once Estimates is opened to it. */
	public static function may( string $action ): bool {
		return self::open() && isset( self::READS[ $action ] );
	}

	/** Is this one of this app's requests (every one of them starts "zest")? */
	public static function ours( string $action ): bool {
		return 0 === strpos( strtolower( $action ), 'zest' );
	}

	/** zdz_privacy_view_allowed_ajax: the four reads, when Estimates is opened to the account. */
	public static function allowed_ajax( $actions ) {
		$actions = is_array( $actions ) ? $actions : array();
		return self::open() ? array_values( array_unique( array_merge( $actions, array_keys( self::READS ) ) ) ) : $actions;
	}

	/** zdz_privacy_view_ajax_keys: the only fields each read may carry (besides the action and the nonce). */
	public static function ajax_keys( $rows ) {
		$rows = is_array( $rows ) ? $rows : array();
		if ( self::open() ) {
			foreach ( self::READS as $action => $keys ) {
				$rows[ $action ] = $keys;
			}
		}
		return $rows;
	}

	/** zdz_privacy_view_allowed_apps: Estimates on the account's app list, when opened to it. */
	public static function allowed_apps( $apps ) {
		$apps = is_array( $apps ) ? $apps : array();
		$id   = defined( 'ZEST_APP_ID' ) ? ZEST_APP_ID : 'estimate-creator';
		if ( self::open() && ! in_array( $id, $apps, true ) ) {
			$apps[] = $id;
		}
		return $apps;
	}

	/** zdz_privacy_view_allowed_rest: no REST route of this app is open to the account, whatever was added. */
	public static function allowed_rest( $routes ) {
		$routes = is_array( $routes ) ? $routes : array();
		$ns     = '/' . trim( defined( 'ZDZ_REST_NS' ) ? (string) ZDZ_REST_NS : 'zorderz/v1', '/' );
		return array_values( array_filter( $routes, static function ( $r ) use ( $ns ) {
			$r = '/' . trim( (string) $r, '/' );
			return 0 !== strpos( $r, $ns . '/estimate' ) && 0 !== strpos( $r, $ns . '/invoice' );
		} ) );
	}

	/** The refusal the platform itself gives. */
	public static function refusal(): array {
		return array( 'code' => 'zdz_privacy_view', 'message' => __( 'Not available in Privacy View.', 'zorderz' ) );
	}

	/**
	 * The second lock, inside the app: for an account in Privacy View, any request of this app that is not one of the
	 * four reads (or any at all while Estimates is closed to it) ends here, with the platform's own refusal. The
	 * platform's gate has already refused it; this holds if that list is ever widened.
	 */
	public static function guard(): void {
		if ( ! self::on() ) {
			return;
		}
		$ajax = ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() );
		if ( ! $ajax ) {
			return;   // a screen: the platform refuses every wp-admin screen of this app for the account
		}
		$action = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? (string) preg_replace( '/[^a-z0-9_\-]/i', '', wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read only to refuse the request, never to act on it; reduced to letters, digits, "_" and "-" here
		if ( self::ours( $action ) && ! self::may( $action ) ) {
			wp_send_json_error( self::refusal(), 403 );
		}
	}

	/** A write handler's own check: end the request for an account in Privacy View. */
	public static function no_writes(): void {
		if ( self::on() ) {
			wp_send_json_error( self::refusal(), 403 );
		}
	}

	/* ================================================================== pseudonyms */

	public static function customer( string $name ): string {
		$name = trim( $name );
		if ( '' === $name ) {
			return '';
		}
		return method_exists( 'ZDZ_Privacy_View', 'customer' ) ? (string) ZDZ_Privacy_View::customer( $name ) : self::NAME;
	}

	/** An estimate (or invoice) number as its pseudonym; '' when there is none. */
	public static function number( string $num, string $kind = 'estimate' ): string {
		$n = ltrim( trim( $num ), '#' );
		if ( '' === $n ) {
			return '';
		}
		return method_exists( 'ZDZ_Privacy_View', 'doc' ) ? (string) ZDZ_Privacy_View::doc( $kind, $n ) : '[number]';
	}

	public static function street( string $s ): string {
		$s = trim( $s );
		if ( '' === $s ) {
			return '';
		}
		return method_exists( 'ZDZ_Privacy_View', 'street' ) ? (string) ZDZ_Privacy_View::street( $s ) : '[street]';
	}

	/** A team member by user id; '' for nobody. */
	public static function staff( int $uid ): string {
		if ( $uid <= 0 ) {
			return '';
		}
		return method_exists( 'ZDZ_Privacy_View', 'staff' ) ? (string) ZDZ_Privacy_View::staff( $uid ) : self::NAME;
	}

	/**
	 * The people an estimate names, each as a pseudonym, joined with " & ". A code that names nobody on the team, or
	 * two people, is "[name]": never the code itself.
	 */
	public static function people( array $row ): string {
		$sp  = class_exists( 'ZEST_Salesperson' ) ? ZEST_Salesperson::for_row( $row ) : array( 'salesperson_uids' => array(), 'salesperson_initials' => array() );
		$out = array();
		foreach ( (array) ( $sp['salesperson_uids'] ?? array() ) as $uid ) {
			$c = self::staff( (int) $uid );
			if ( '' !== $c ) {
				$out[ $c ] = true;
			}
		}
		$unnamed = count( (array) ( $sp['salesperson_initials'] ?? array() ) ) - count( (array) ( $sp['salesperson_uids'] ?? array() ) );
		if ( $unnamed > 0 ) {
			$out[ self::NAME ] = true;
		}
		return implode( ' & ', array_keys( $out ) );
	}

	/** Is this value already one of the platform's pseudonyms (or "[name]")? */
	public static function coded( string $s ): bool {
		$s = trim( $s );
		if ( self::NAME === $s ) {
			return true;
		}
		return method_exists( 'ZDZ_Privacy_View', 'is_pseudonym' ) && (bool) ZDZ_Privacy_View::is_pseudonym( $s );
	}

	/** '' stays ''; anything else a person typed is not shown. */
	public static function hidden( $s ): string {
		if ( is_array( $s ) || is_object( $s ) ) {
			return $s ? self::HIDDEN : '';
		}
		return '' === trim( (string) $s ) ? '' : self::HIDDEN;
	}

	/** A row's status: one of the words this app writes there, or nothing. */
	public static function status( $s ): string {
		$s = is_scalar( $s ) ? (string) $s : '';
		return in_array( $s, self::STATUSES, true ) ? $s : '';
	}

	/** A stored date and time ("2026-01-31 18:00:00"), or nothing. */
	public static function when( $s ): string {
		$s = is_scalar( $s ) ? trim( (string) $s ) : '';
		return preg_match( '/\A\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?)?\z/', $s ) ? $s : '';
	}

	/** One of a closed list of words, or nothing. */
	private static function one_of( $s, array $list ): string {
		$s = is_scalar( $s ) ? trim( (string) $s ) : '';
		return in_array( $s, $list, true ) ? $s : '';
	}

	/** A value as text; anything that is not text or a number is nothing. */
	private static function text( $v ): string {
		return is_scalar( $v ) ? (string) $v : '';
	}

	/* ================================================================== follow-ups */

	/** A team member's label inside a follow-up: already a pseudonym (the request class makes it one), else "[name]". */
	private static function who( $v ): string {
		$v = is_scalar( $v ) ? trim( (string) $v ) : 'x';   // anything that is not text is not a pseudonym
		return '' === $v ? '' : ( self::coded( $v ) ? $v : self::NAME );
	}

	/** How it was done: one of the app's fixed channel labels, or nothing. */
	private static function channel( $s ): string {
		$s = is_scalar( $s ) ? trim( (string) $s ) : '';
		return ( '' !== $s && class_exists( 'ZEST_Followup' ) && in_array( $s, array_values( ZEST_Followup::channels() ), true ) ) ? $s : '';
	}

	/** Why the billing line was left alone, from its code: one of the app's own sentences, or nothing. */
	private static function why( $code ): string {
		$code = is_scalar( $code ) ? trim( (string) $code ) : '';
		if ( ! class_exists( 'ZEST_Followup' ) || ! preg_match( '/\A[a-z_]{1,24}(?::\d{1,4})?\z/', $code ) ) {
			return '';
		}
		return ZEST_Followup::why_words( $code );
	}

	/**
	 * One follow-up as the lists and the history show it: a fixed list of fields, nothing the account may act on. The
	 * sentence is written again here, from the state, the answer and the outcome code (closed lists) around the three
	 * people as pseudonyms: nothing of what the handler sent as words is passed on.
	 */
	public static function followup( $v ): ?array {
		if ( ! is_array( $v ) || ! class_exists( 'ZEST_Followup' ) ) {
			return null;
		}
		$to      = self::who( $v['to'] ?? '' );
		$by      = self::who( $v['by'] ?? '' );
		$who     = self::who( $v['who'] ?? '' );
		$state   = self::one_of( $v['state'] ?? '', self::FU_STATES );
		$answer  = self::one_of( $v['answer'] ?? '', ZEST_Followup::ANSWERS );
		$outcome = self::one_of( $v['outcome_code'] ?? '', array_keys( ZEST_Followup::OUTCOMES ) );
		$tries   = $v['tries'] ?? 0;
		$tries   = max( 0, is_scalar( $tries ) ? (int) $tries : 0 );
		$id      = $v['id'] ?? 0;
		$words   = ZEST_Followup::status_words( array( 'state' => $state, 'tries' => $tries, 'answer' => $answer, 'outcome' => $outcome ), $to, $by, $who );
		$again   = '';
		if ( ! empty( $v['reopened'] ) ) {
			$again = self::who( $v['reopened_by'] ?? '' );
			/* translators: %s: who reopened it */
			$words .= ( '' !== $words ? '; ' : '' ) . ( '' !== $again ? sprintf( __( 'reopened by %s', 'zorderz' ), $again ) : __( 'reopened', 'zorderz' ) );
		}
		return array(
			'id'           => is_scalar( $id ) ? (int) $id : 0,
			'token'        => '',
			'state'        => $state,
			'to'           => $to,
			'by'           => $by,
			'asked'        => self::when( $v['asked'] ?? '' ),
			'answer'       => $answer,
			'outcome'      => '' !== $outcome ? ZEST_Followup::outcome_label( $outcome ) : '',
			'outcome_code' => $outcome,
			'tries'        => $tries,
			'who'          => $who,
			'when'         => self::when( $v['when'] ?? '' ),
			'declined'     => ! empty( $v['declined'] ),
			'mine'         => false,
			'by_me'        => false,
			'reopened'     => ! empty( $v['reopened'] ),
			'reopened_by'  => $again,
			'words'        => $words,
			'doc_state'    => self::one_of( $v['doc_state'] ?? '', self::DOC_STATES ),
			'doc_why'      => self::why( $v['doc_why_code'] ?? '' ),
			'test'         => ! empty( $v['test'] ),
		);
	}

	/** One of this app's own fixed billing lines, without the person's code; '' for anything else. */
	public static function doc_line( $line ): string {
		$line = is_scalar( $line ) ? trim( (string) $line ) : '';
		if ( '' === $line || ! class_exists( 'ZEST_Followup' ) || ! ZEST_Followup::line_ok( $line ) ) {
			return '';
		}
		return ZEST_Followup::line_without_code( $line );
	}

	/** One thing taken back, as the history shows it: the sentence written again around pseudonyms. */
	private static function undone( array $u ): array {
		$kind = self::one_of( $u['kind'] ?? '', array( 'ask', 'try', 'answer' ) );
		return array(
			'kind'    => $kind,
			'when'    => self::when( $u['when'] ?? '' ),
			'by'      => self::who( $u['by'] ?? '' ),
			'words'   => 'ask' === $kind ? __( 'a request to follow up', 'zorderz' ) : ( 'try' === $kind ? __( 'a try with no answer', 'zorderz' ) : ( 'answer' === $kind ? __( 'an answer', 'zorderz' ) : '' ) ),
			'was'     => self::when( $u['was'] ?? '' ),
			'channel' => self::channel( $u['channel'] ?? '' ),
			'note'    => self::hidden( $u['note'] ?? '' ),
		);
	}

	/** The history sheet's reply (zest_fu_history). */
	public static function fu_history( array $reply ): array {
		$rows = array();
		foreach ( (array) ( $reply['rows'] ?? array() ) as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$v = self::followup( $r );
			if ( null === $v ) {
				continue;
			}
			$v['ask_note'] = self::hidden( $r['ask_note'] ?? '' );
			$v['note']     = self::hidden( $r['note'] ?? '' );
			$v['channel']  = self::channel( $r['channel'] ?? '' );
			$v['tried']    = array();
			foreach ( (array) ( $r['tried'] ?? array() ) as $t ) {
				if ( is_array( $t ) ) {
					$v['tried'][] = array( 'when' => self::when( $t['when'] ?? '' ), 'who' => self::who( $t['who'] ?? '' ), 'channel' => self::channel( $t['channel'] ?? '' ), 'note' => self::hidden( $t['note'] ?? '' ) );
				}
			}
			$v['doc_line'] = self::doc_line( $r['doc_line'] ?? '' );
			$v['crm']      = self::one_of( $r['crm'] ?? '', self::CRM_STATES );
			$v['undone']   = array();
			foreach ( (array) ( $r['undone'] ?? array() ) as $u ) {
				if ( is_array( $u ) ) {
					$v['undone'][] = self::undone( $u );
				}
			}
			$rows[] = $v;
		}
		return array( 'number' => self::number( self::text( $reply['number'] ?? '' ) ), 'rows' => $rows, 'privacy_view' => true );
	}

	/* ================================================================== the lists */

	private static function is_test( array $row ): bool {
		return class_exists( 'ZEST_Terms_Note' ) && ZEST_Terms_Note::is_test( self::text( $row['customer_name'] ?? '' ), self::text( $row['customer_org'] ?? '' ) );
	}

	/** What the lists' controls read: nothing may be sent, answered or taken back. */
	public static function meta(): array {
		return array( 'can_send' => false, 'read_only' => true, 'outcomes' => array(), 'channels' => array(), 'max_note' => 0 );
	}

	/**
	 * One estimate on a list, from this site's whole row and its follow-up as the request class views it.
	 *
	 * @param array      $row      the estimate's row
	 * @param array|null $followup its newest follow-up (ZEST_Followup_Ajax::view())
	 * @param bool       $history  does it have any follow-up row at all
	 */
	public static function list_row( array $row, ?array $followup, bool $history ): array {
		$fu = self::followup( $followup );
		return array(
			'id'               => (int) ( $row['id'] ?? 0 ),
			'customer'         => self::customer( self::text( $row['customer_name'] ?? '' ) ),
			'number'           => self::number( self::text( $row['billing_doc_num'] ?? '' ) ),
			'status'           => self::status( $row['status'] ?? '' ),
			'created'          => self::when( $row['created_at'] ?? '' ),
			'items'            => (int) ( $row['item_count'] ?? 0 ),
			'followup'         => $fu,
			'followup_history' => $history,
			'can_follow'       => false,
			'declined'         => (bool) ( $fu['declined'] ?? false ),
			'test'             => self::is_test( $row ),
		);
	}

	/** A list's whole reply (zest_list_open, zest_history): the rows as list_row() made them, and the look-only meta. */
	public static function list_reply( array $rows ): array {
		return array( 'rows' => array_values( $rows ), 'followups' => self::meta(), 'privacy_view' => true );
	}

	/* ================================================================== one estimate */

	/**
	 * One estimate from this site's row, to look at: who and what as pseudonyms, each line's quantity, and of its
	 * notes only this app's own fixed follow-up lines. A line's name and description are NOT sent (`words` says
	 * whether it has any): they are dictated or typed. No price is sent.
	 *
	 * @param array      $row      the whole row
	 * @param array|null $followup the estimate's newest follow-up, as ZEST_Followup_Ajax::view() gives it
	 */
	public static function estimate( array $row, ?array $followup ): array {
		$lines = array();
		$items = json_decode( self::text( $row['items_json'] ?? '' ), true );
		foreach ( is_array( $items ) ? $items : array() as $it ) {
			if ( ! is_array( $it ) ) {
				continue;
			}
			$q     = $it['quantity'] ?? ( $it['qty'] ?? 1 );
			$words = false;
			foreach ( array( 'name', 'description', 'sub_description' ) as $k ) {
				$w     = $it[ $k ] ?? '';
				$words = $words || ( is_scalar( $w ) ? '' !== trim( (string) $w ) : ! empty( $w ) );
			}
			$lines[] = array( 'words' => $words, 'qty' => is_numeric( $q ) ? (float) $q : 1.0 );
		}
		$ours  = array();
		$other = 0;
		foreach ( (array) preg_split( '/\r\n|\r|\n/', self::text( $row['notes'] ?? '' ) ) as $l ) {
			if ( '' === trim( (string) $l ) ) {
				continue;
			}
			$f = self::doc_line( $l );
			if ( '' !== $f ) {
				$ours[] = $f;
			} else {
				$other++;
			}
		}
		$rejected = json_decode( self::text( $row['rejected_json'] ?? '' ), true );
		$state    = strtoupper( trim( self::text( $row['customer_state'] ?? '' ) ) );
		return array(
			'id'             => (int) ( $row['id'] ?? 0 ),
			'number'         => self::number( self::text( $row['billing_doc_num'] ?? '' ) ),
			'kind'           => 'invoice' === self::text( $row['doc_type'] ?? '' ) ? 'invoice' : 'estimate',
			'customer'       => self::customer( self::text( $row['customer_name'] ?? '' ) ),
			'test'           => self::is_test( $row ),
			'salesperson'    => self::people( $row ),
			'street'         => self::street( self::text( $row['customer_street'] ?? '' ) ),
			'state'          => preg_match( '/\A[A-Z]{2}\z/', $state ) ? $state : '',
			'has_email'      => '' !== trim( self::text( $row['customer_email'] ?? '' ) ),
			'has_phone'      => '' !== trim( self::text( $row['customer_phone'] ?? '' ) ),
			'status'         => self::status( $row['status'] ?? '' ),
			'in_billing'     => '' !== trim( self::text( $row['billing_doc_id'] ?? '' ) ),
			'in_crm'         => '' !== trim( self::text( $row['crm_lead_id'] ?? '' ) ),
			'created'        => self::when( $row['created_at'] ?? '' ),
			'sent_at'        => self::when( $row['sent_at'] ?? '' ),
			'approved_at'    => self::when( $row['approved_at'] ?? '' ),
			'approved_by'    => self::staff( (int) ( $row['approved_by'] ?? 0 ) ),
			'accepted_at'    => self::when( $row['accepted_at'] ?? '' ),
			'lines'          => $lines,
			'left_out'       => is_array( $rejected ) ? count( $rejected ) : (int) ( $row['rejected_count'] ?? 0 ),
			'followup_lines' => $ours,
			'notes_hidden'   => $other,
			'followup'       => self::followup( $followup ),
		);
	}

	/**
	 * zest_pv_estimate {id}: one estimate to look at. It answers ONLY an account in Privacy View that Estimates is
	 * opened to (anyone else has the editor), reads ONLY this site's row (no billing call, nothing written) and finds
	 * the estimate ONLY by this app's row id, which the two lists hand out for the estimates the account may see:
	 * there is no look-up by number or name, so nothing can be guessed at.
	 */
	public static function ajax_estimate(): void {
		$uid = ZEST_Access::guard();
		if ( class_exists( 'ZEST_Followup_Ajax' ) && ! ZEST_Followup_Ajax::has_app( $uid ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'zorderz' ) ), 403 );
		}
		if ( ZEST_Access::is_kiosk( $uid ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'zorderz' ) ), 403 );
		}
		if ( ! self::on() || ! self::may_look( $uid ) ) {
			wp_send_json_error( array( 'message' => __( 'This view is only for an account in Privacy View.', 'zorderz' ) ), 403 );
		}
		$raw = isset( $_POST['id'] ) ? wp_unslash( $_POST['id'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in ZEST_Access::guard() above; digits only, checked on the next line
		if ( ! is_string( $raw ) || ! preg_match( '/\A[1-9]\d{0,17}\z/', $raw ) ) {
			wp_send_json_error( array( 'message' => __( 'Which estimate? Reload the list and try again.', 'zorderz' ) ) );
		}
		$r = self::look( (int) $raw, $uid );
		if ( null === $r ) {
			// One answer for "not there" and "not yours": which ids exist is not the account's to learn.
			wp_send_json_error( array( 'message' => __( 'Estimate not found.', 'zorderz' ) ), 404 );
		}
		wp_send_json_success( $r );
	}

	/** The reply for one estimate, or null when it is not there or not this account's to see. */
	public static function look( int $id, int $uid ): ?array {
		$row = ZEST_Access::row( $id );
		if ( ! $row || ! ZEST_Access::may_open( $row, $uid ) ) {
			return null;
		}
		$fu = null;
		if ( class_exists( 'ZEST_Followup_Ajax' ) && class_exists( 'ZEST_Followup' ) ) {
			$latest = (array) ZEST_Followup::quiet( static function () use ( $id ) {
				return ZEST_Followup::latest_map( array( $id ) );
			}, array() );
			$fu = isset( $latest[ $id ] ) ? ZEST_Followup_Ajax::view( $latest[ $id ], $uid ) : null;
		}
		return array( 'estimate' => self::estimate( $row, $fu ), 'privacy_view' => true );
	}

	/* ================================================================== the screen */

	/**
	 * The widget for this account: the open list and past estimates. No New estimate panel, no documents, no tools:
	 * none of it is in the page. The widget's own script runs in its look-only mode (zestWidget.privacyView); the
	 * editor's script is not loaded.
	 */
	public static function render_widget( int $user_id ): string {
		wp_enqueue_style( 'zest-widget', ZEST_URL . 'assets/css/widget.css', array(), zest_asset_ver( 'assets/css/widget.css' ) );
		wp_enqueue_script( 'zest-widget', ZEST_URL . 'assets/js/widget.js', array(), zest_asset_ver( 'assets/js/widget.js' ), true );
		$deps = array( 'zest-widget' );
		if ( wp_script_is( 'zdz-kit-js', 'registered' ) || wp_script_is( 'zdz-kit-js', 'enqueued' ) ) {
			$deps[] = 'zdz-kit-js';
		}
		wp_enqueue_style( 'zest-followup', ZEST_URL . 'assets/css/followup.css', array(), zest_asset_ver( 'assets/css/followup.css' ) );
		wp_enqueue_script( 'zest-followup', ZEST_URL . 'assets/js/followup.js', $deps, zest_asset_ver( 'assets/js/followup.js' ), true );
		wp_enqueue_style( 'zest-privacy-view', ZEST_URL . 'assets/css/privacy-view.css', array(), zest_asset_ver( 'assets/css/privacy-view.css' ) );
		wp_enqueue_script( 'zest-privacy-view', ZEST_URL . 'assets/js/privacy-view.js', $deps, zest_asset_ver( 'assets/js/privacy-view.js' ), true );
		wp_localize_script( 'zest-widget', 'zestWidget', array(
			'ajaxurl'     => admin_url( 'admin-ajax.php' ),
			'restBase'    => '',
			'restNonce'   => '',
			'nonce'       => wp_create_nonce( ZEST_NONCE ),
			'version'     => ZEST_VERSION,
			'privacyView' => true,
			'isAdminTier' => false,
			'isOperator'  => false,
			'permissions' => array(),
		) );
		ob_start();
		?>
		<div class="zest-w zest-w--pv" id="zest-widget" data-admin="0" data-zest-pv="1">
			<p class="zest-w-pv-note" role="note"><?php esc_html_e( 'Privacy View: you can look, not change. Customers and team members are shown as codes; e-mails, phones, prices and anything people typed or dictated are not shown.', 'zorderz' ); ?></p>
			<div class="zest-w-tabs" role="tablist">
				<button class="zest-w-tab is-active" data-tab="open" role="tab"><?php esc_html_e( 'Open', 'zorderz' ); ?></button>
				<button class="zest-w-tab" data-tab="history" role="tab"><?php esc_html_e( 'History', 'zorderz' ); ?></button>
			</div>
			<div class="zest-w-panel is-active" data-panel="open">
				<div class="zest-w-list" id="zest-open-list" aria-live="polite">
					<div class="zest-empty zest-loading"><?php esc_html_e( 'Loading', 'zorderz' ); ?>&hellip;</div>
				</div>
			</div>
			<div class="zest-w-panel" data-panel="history">
				<div class="zest-w-list" id="zest-history-list" aria-live="polite">
					<div class="zest-empty zest-loading"><?php esc_html_e( 'Loading', 'zorderz' ); ?>&hellip;</div>
				</div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
