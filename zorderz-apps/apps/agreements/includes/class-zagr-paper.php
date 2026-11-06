<?php
/**
 * ZAGR_Paper - "Signed on paper", with a photo of the signature page. [CORE]
 *
 * ONE SERVER PATH (record()), whichever way in: the capability verb agreements.record_paper, the seam the estimate app
 * calls (ZAGR_API::record_paper), and the Settings screen's form (handle_admin()). It records a paper signing on the
 * estimate's EXISTING agreement:
 *   allowed      only while the agreement is not signed: draft or ready ("signing" is a customer signing online at this
 *                very moment: refused for that minute, and a "signing" left over from a request that died is healed
 *                first). The paper form carries the business's own signature, so a draft nobody signed in the app is
 *                fine. Refused, in plain words: signed online, signed on paper, cancelled, no agreement; a sale not
 *                negotiated in the agreement's language (in the online path's own words); a mode that admits no
 *                agreement for this customer; a job whose state can't be read where the legal packs need it.
 *   language     only a yes makes an agreement (as contractor_sign). Unanswered, the question comes with the request
 *                ($in['language_ok']): a "No" is kept (the agreement and its record) and refused; a "Yes" is kept once
 *                everything else checks out.
 *   the date     the paper signing day, a local Y-m-d: not a day still to come, at most 30 days back, and never before
 *                the estimate's own date (frozen in the agreement).
 *   the answers  what the agreement's legal packs ask: "Is any buyer 65 or older?" where a pack asks it (yes or no,
 *                required, never assumed; when the buyer answered 65 or older on the agreement page, a "No" is refused:
 *                BUYER_OLDER), and a staff question still unanswered on the agreement (a disaster repair, for one): yes
 *                or no, recorded on the agreement with its own step. The packs then give the business days to cancel.
 *   the photos   one to twelve ZDZ_User_Media ids. Each must exist, be a photo (a photo row with an image attachment), be
 *                private (a shared one is refused, its sharing never changed here), have been taken on or after the paper
 *                date (captured_at, else when it was stored: a signature can't be photographed before it exists), not be
 *                the signature page of another agreement whose paper signing stands, and be the recorder's: through the
 *                app, their own (an administrator's too); in Settings, exactly what the form offers (recent_photos(): the
 *                administrator's own and the signer's for the business) or one uploaded there and then (its own EXIF
 *                date checked against the paper date before it is stored). Each is recorded with the SHA-256 of its
 *                whole file (the original upload when WordPress made a "-scaled" copy) and which file that was, and of
 *                the file served full size too, so a photo swapped, edited or rotated later shows on the Settings screen.
 *   who          an administrator (manage_options, or the admin tier); or the salesperson: the staff member who signed
 *                for the business (contractor_user_id), or one the estimate app names for the estimate on the filter
 *                zagr_estimate_salespeople. Never the
 *                shared device, never Demo Mode (ZAGR_People::is_kiosk).
 *
 * THE RESULT: the state becomes "signed" (so every reader, an order gate among them, keeps its words); signed_at is the
 * paper date (noon local time, or now if that is later today); the copy counts as received at signing (the customer
 * keeps the paper: copy_via 'paper', which is how a paper signing is told apart, status_for's signed_via); cancel_by is
 * the packs' business days after the paper date; the window then closes, and OK to order follows, exactly as for an
 * online signing (ZAGR_Store::refresh_window, the sweep, ZAGR_Alerts). A draft takes the salesperson as the one who
 * signed for the business, so "OK to order" reaches them as usual.
 *
 * THE RECORD: one chained step, "signed_on_paper": the date, the age answer (sealed, as every age answer in the record),
 * the cancel-by day, who recorded it (staff user id) and where (app | admin), the state it was in, and each photo's id
 * and SHA-256. Nothing else about the customer. The chain verifies as before (ZAGR_Events::verify).
 *
 * THE UNDO (undo()): an administrator, with a typed reason (kept sealed: it may name someone), only before OK to order
 * and never once a change order was made after it, waiting or signed (CHANGE_MADE): its own chained step
 * ("paper_undone"), and the agreement back to the state it was in. It can be recorded again.
 *
 * A BACKDATED PAPER DATE:
 *   the flag     every paper date earlier than the local day it was recorded (FLAG 'backdated', late_words()): "Recorded
 *                2 days after the paper date." in the administrators' alert and on the Settings row; one whose window had
 *                also already ended keeps its words ("...; its cancellation period had already ended.").
 *   the hold     one recorded after its window had ended (late_days() > 0) is never OK to order by itself: the window
 *                closes as for any paper signing (state window_closed, so an order gate reads its general "not OK to
 *                order yet"), but ok_to_order stays false (status_for, paper_for, ZAGR_Alerts::due) and status_for's
 *                waiting_for says 'admin_confirm' (awaiting_confirm()), until an administrator confirms it in Settings,
 *                as a separate step even when an administrator recorded it (confirm_late(): manage_options as the undo,
 *                never the shared device or Demo Mode, a tick that they looked at the photo and the paper date, an
 *                optional note kept sealed). Its own chained step ("paper_late_confirmed": which paper step, who), then
 *                "OK to order" as any window closing sends it, once. Undone before that, it is undone like any other.
 *                The customer's page shows nothing of it: their cancel-by day is what it is.
 *
 * THE CLAIM: as an online signature, the row is held in "signing" while the step is written; copy_via carries the state
 * it held ("pc:draft", "pc:ready"). A failure lets it go at once (release()); a request that dies is put back exactly by
 * the heal (heal_claim(): a draft stays a draft, the signer for the business as it was).
 *
 * ALERTS: "Signed on paper" to the administrators (ZAGR_Alerts, the bell and push), never the one who recorded it (not
 * even when nobody else received it); a backdated one says so (late_words()), and one recorded after its window had
 * ended says that ordering waits for an administrator's confirmation in Settings (WAITS; its link opens the row). Never
 * "An agreement was signed" for a paper signing (the salesperson recorded it themselves). "OK to order" later, to the
 * salesperson, as usual.
 *
 * PRIVACY: a signature page shows a customer's name and signature. Its photo stays where ZDZ_User_Media keeps it
 *; only its id and hash are recorded; it is shown only to administrators,
 * through ZDZ_User_Media's gated links; never a share link, never sent anywhere, never in an alert or a log line.
 *
 * @package Zorderz\Agreements
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZAGR_Paper {

	const EVENT       = 'signed_on_paper';
	const UNDONE      = 'paper_undone';
	const CONFIRMED   = 'paper_late_confirmed';   // an administrator confirmed a late paper signing
	// what the administrators' alert, the recorder and the Settings row are told of a late one
	const WAITS       = 'Ordering waits until an administrator confirms it in Settings, Agreements.';
	// a confirmation that meets a cancellation, and a paper step that can't be read
	const CANCELLED_NONE = 'The customer cancelled this agreement, so there is nothing to confirm.';
	const UNREADABLE     = 'This paper signing\'s step can\'t be read from the agreement\'s record just now, so it can\'t be confirmed, and ordering waits. Try again in a minute; if it stays, ask the developer to look at the record.';
	const VIA         = 'paper';            // copy_via: the copy counted as received at signing (the paper, kept by the customer)
	const BACK_DAYS   = 30;                 // the paper date at most this many days back
	const MAX_PHOTOS  = 12;
	const RECENT_DAYS = 14;                 // Settings' "recent photos": the administrator's own and the signer's for the business
	const UNSIGNED    = array( 'draft', 'ready' );
	const UPLOAD_KEY  = 'zagr_paper_one';  // the one-file slot media_handle_upload() reads (Settings' upload)
	const MAX_BYTES   = 26214400;           // 25 MB a file
	const CLAIM       = 'pc:';              // copy_via while a paper claim holds the row ("pc:draft", "pc:ready")
	// the buyer's own answer on the page: the words the refusal, the app's sheet and Settings' form all use
	const BUYER_OLDER = 'The customer answered 65 or older on the agreement page. Record Yes, and give them the notice for buyers 65 or older.';
	/** The refusal when the sale was not negotiated in the agreement's language. */
	public static function not_language_words(): string {
		/* translators: %s: the language the agreement template is written in, e.g. English */
		return sprintf( __( 'The sale was not negotiated in %s, the language this agreement is written in, so there is no agreement. The customer should call the office.', 'zorderz' ), self::language_name() );
	}

	/** The question, when nobody has answered it yet. */
	public static function ask_language_words(): string {
		/* translators: %s: the language the agreement template is written in */
		return sprintf( __( 'Ask whether the sale was negotiated in %s first. Only a yes makes an agreement.', 'zorderz' ), self::language_name() );
	}

	/** The name of the language the template in use is written in ("English"). */
	public static function language_name(): string {
		try {
			return ZAGR_Template::language_name( (string) ZAGR_Template::load( ZAGR_Settings::template_version() )['language'] );
		} catch ( \Throwable $e ) {
			return __( 'the agreement\'s language', 'zorderz' );
		}
	}
	const CHANGE_MADE = 'A change order was made after this paper signing, so it can\'t be undone here.';

	private static function out( bool $ok, string $code, string $message, array $more = array() ): array {
		return array( 'ok' => $ok, 'code' => $code, 'message' => $message ) + $more;
	}

	/* ---------------------------------------------------------------- what a row is */

	/** A row as readers see it (status_for, paper_for): a paper claim holding a draft ("pc:draft") reads as that draft. */
	public static function seen( array $row ): array {
		if ( 'signing' === (string) ( $row['state'] ?? '' ) && self::CLAIM . 'draft' === (string) ( $row['copy_via'] ?? '' ) ) {
			$row['state'] = 'draft';
		}
		return $row;
	}

	/** Signed on paper (and not undone): the copy counted as received at signing, by paper. */
	public static function is_paper( array $row ): bool {
		return ! empty( $row['signed_at'] ) && self::VIA === (string) ( $row['copy_via'] ?? '' );
	}

	/** status_for's signed_via: 'online', 'paper', or '' when the customer hasn't signed. */
	public static function signed_via( array $row ): string {
		if ( empty( $row['signed_at'] ) ) {
			return '';
		}
		return self::is_paper( $row ) ? 'paper' : 'online';
	}

	/** The local day a row was signed ('' when it wasn't). */
	public static function signed_on( array $row ): string {
		return empty( $row['signed_at'] ) ? '' : ZAGR_Clock::local_day( ZAGR_Clock::ts( (string) $row['signed_at'] ) );
	}

	/** The newest event of $type, decoded: { seq, at_utc, actor, d } or null. */
	private static function last_event( int $agreement_id, string $type ): ?array {
		$all = ZAGR_Events::of_type( $agreement_id, $type );
		$e   = $all ? end( $all ) : null;
		if ( ! $e ) {
			return null;
		}
		$d = json_decode( (string) $e['detail'], true );
		return array( 'seq' => (int) $e['seq'], 'at_utc' => (string) $e['at_utc'], 'actor' => (string) $e['actor'], 'd' => is_array( $d ) ? $d : array() );
	}

	/**
	 * The paper signing that stands: its chained step's facts { seq, date, cancel_by, by, via, from, photos, at, older },
	 * or null when the row is not signed on paper. older is the answer opened with the key ('' without it).
	 */
	public static function current( array $row ): ?array {
		if ( ! self::is_paper( $row ) || empty( $row['id'] ) ) {
			return null;
		}
		$e = self::last_event( (int) $row['id'], self::EVENT );
		if ( ! $e ) {
			return null;
		}
		$d     = $e['d'];
		$older = ZAGR_Key::open( (string) ( $d['age_sealed'] ?? '' ), 'age|' . (int) $row['id'] );
		$ph    = array();
		foreach ( (array) ( $d['photos'] ?? array() ) as $p ) {
			if ( is_array( $p ) && (int) ( $p['id'] ?? 0 ) > 0 ) {
				$ph[] = array( 'id' => (int) $p['id'], 'sha256' => (string) ( $p['sha256'] ?? '' ), 'file' => (string) ( $p['file'] ?? '' ) )
					+ ( isset( $p['served'] ) ? array( 'served' => (string) $p['served'] ) : array() );
			}
		}
		return array(
			'seq'       => $e['seq'],
			'date'      => (string) ( $d['date'] ?? '' ),
			'cancel_by' => (string) ( $d['cancel_by'] ?? '' ),
			'by'        => (int) ( $d['by'] ?? 0 ),
			'via'       => 'admin' === ( $d['via'] ?? '' ) ? 'admin' : 'app',
			'from'      => in_array( $d['from'] ?? '', self::UNSIGNED, true ) ? (string) $d['from'] : 'ready',
			'contractor_set' => (int) ( $d['contractor_set'] ?? 0 ),
			'photos'    => $ph,
			'at'        => $e['at_utc'],
			'older'     => in_array( $older, array( 'yes', 'no' ), true ) ? $older : '',
		);
	}

	/** The last undo, for an unsigned row that was once recorded on paper: { at, by, reason } or null. */
	public static function last_undo( array $row ): ?array {
		if ( empty( $row['id'] ) || self::is_paper( $row ) ) {
			return null;
		}
		$e = self::last_event( (int) $row['id'], self::UNDONE );
		if ( ! $e || 'staff' !== ( $e['d']['why'] ?? '' ) ) {
			return null;
		}
		$why = ZAGR_Key::open( (string) ( $e['d']['reason_sealed'] ?? '' ), 'paper-undo|' . (int) $row['id'] . '|' . (int) ( $e['d']['of'] ?? 0 ) );
		return array( 'at' => $e['at_utc'], 'by' => (int) ( $e['d']['by'] ?? 0 ), 'reason' => null === $why ? '' : (string) $why );
	}

	/* ---------------------------------------------------------------- who */

	/**
	 * The staff the estimate app names for this estimate on zagr_estimate_salespeople (the person who made it, worked out
	 * from its own rows on the server). User ids; at most 8; a filter that throws names nobody.
	 *
	 * @return int[]
	 */
	public static function salespeople( array $row ): array {
		try {
			$ids = apply_filters( 'zagr_estimate_salespeople', array(), (int) ( $row['estimate_id'] ?? 0 ) );
		} catch ( \Throwable $e ) {
			return array();
		}
		$out = array();
		foreach ( is_array( $ids ) ? $ids : array() as $u ) {
			$u = is_scalar( $u ) ? (int) $u : 0;
			if ( $u > 0 && ! in_array( $u, $out, true ) ) {
				$out[] = $u;
			}
		}
		return array_slice( $out, 0, 8 );
	}

	/** The salesperson on this agreement: the one who signed for the business, or one the estimate app names. */
	public static function is_salesperson( int $uid, array $row ): bool {
		return $uid > 0 && ( (int) ( $row['contractor_user_id'] ?? 0 ) === $uid || in_array( $uid, self::salespeople( $row ), true ) );
	}

	/** Salesperson + admins, never the shared device or Demo Mode. */
	public static function may_record( int $uid, array $row ): bool {
		if ( $uid <= 0 || ZAGR_People::is_kiosk( $uid ) ) {
			return false;
		}
		if ( ZAGR_People::is_admin_tier( $uid ) ) {
			return true;
		}
		return ZAGR_People::is_staff( $uid ) && self::is_salesperson( $uid, $row );
	}

	private static function mode_allows( array $row ): bool {
		$m = ZAGR_Settings::mode();
		return 'on' === $m || ( 'test' === $m && ! empty( $row['is_test'] ) );
	}

	private static function short( string $ymd ): string {
		return ZAGR_Calendar::short_date( $ymd );
	}

	/**
	 * Why this person can't record a paper signing on this agreement now (who, its state, its English answer, the mode,
	 * the key), or null when they can. [ code, message ].
	 */
	public static function blocker( array $row, int $uid ): ?array {
		if ( ZAGR_People::is_kiosk( $uid ) ) {
			return array( 'kiosk', 'A paper signing can\'t be recorded on the shared device. Sign in as yourself.' );
		}
		if ( $uid <= 0 ) {
			return array( 'signed_out', 'Sign in first.' );
		}
		if ( ! self::may_record( $uid, $row ) ) {
			return array( 'not_yours', 'Only the estimate\'s salesperson or an administrator can record that it was signed on paper.' );
		}
		$state = (string) ( $row['state'] ?? '' );
		if ( 'cancelled' === $state ) {
			return array( 'cancelled', 'The customer cancelled this agreement, so nothing was recorded.' );
		}
		if ( self::is_paper( $row ) ) {
			return array( 'signed_paper', 'This agreement is already recorded as signed on paper, on ' . self::short( self::signed_on( $row ) ) . '. Nothing new was recorded.' );
		}
		if ( ! empty( $row['signed_at'] ) ) {
			return array( 'signed_online', 'The customer already signed this agreement online, on ' . self::short( self::signed_on( $row ) ) . ', so a paper signing can\'t be recorded.' );
		}
		if ( 'signing' === $state ) {
			return array( 'busy', 0 === strpos( (string) ( $row['copy_via'] ?? '' ), self::CLAIM )
				? 'Someone is recording a paper signing for this agreement right now. Try again in a minute.'
				: 'The customer is signing this agreement online right now. Look again in a minute.' );
		}
		if ( ! in_array( $state, self::UNSIGNED, true ) ) {
			return array( 'state', 'This agreement can\'t take a paper signing in the state it is in.' );
		}
		if ( '0' === (string) ( $row['language_ok'] ?? '' ) ) {
			return array( 'not_language', self::not_language_words() );
		}
		if ( ! self::mode_allows( $row ) ) {
			return array( 'mode_off', 'Agreements are off for this estimate, so a paper signing can\'t be recorded here.' );
		}
		if ( ! ZAGR_Key::usable() ) {
			return array( 'not_ready', 'A paper signing can\'t be recorded until the agreements key is entered (Settings, Agreements).' );
		}
		$doc = ZAGR_Store::document( $row );
		if ( null === $doc ) {
			return array( 'not_ready', 'The agreement\'s own words don\'t open just now, so a paper signing can\'t be recorded. Try again in a minute.' );
		}
		$stack = ZAGR_Document::stack( $doc );
		if ( ! $stack || ( '' === (string) ( $doc['region'] ?? '' ) && ZAGR_Pack::needs_region( $stack ) ) ) {
			return array( 'not_ready', 'The state of the job\'s address couldn\'t be read, so it isn\'t known which state\'s rules apply. Correct the address on the estimate and approve it again.' );
		}
		return null;
	}

	/** Do this agreement's legal packs ask whether any buyer is 65 or older? (yes when its words can't be read) */
	public static function asks_age( array $row ): bool {
		$doc = ZAGR_Store::document( $row );
		return null === $doc || ZAGR_Document::asks_age( $doc );
	}

	/** The staff questions this agreement's packs ask that it hasn't answered yet: id => question. */
	public static function open_questions( array $row ): array {
		$doc = ZAGR_Store::document( $row );
		if ( null === $doc ) {
			return array();
		}
		$out = array();
		foreach ( ZAGR_Pack::questions( ZAGR_Document::stack( $doc ) ) as $id => $q ) {
			if ( 'staff' === ( $q['asked_of'] ?? '' ) && ! in_array( (string) ( $doc['answers'][ $id ] ?? '' ), array( 'yes', 'no' ), true ) ) {
				$out[ $id ] = $q;
			}
		}
		return $out;
	}

	/**
	 * The first paper date that may be recorded today (local Y-m-d): BACK_DAYS before $today, and, for an agreement
	 * ($row), never before the estimate's own date.
	 */
	public static function first_day( string $today, array $row = array() ): string {
		$first = ( new DateTimeImmutable( $today, ZAGR_Calendar::tz() ) )->modify( '-' . self::BACK_DAYS . ' day' )->format( 'Y-m-d' );
		$est   = $row ? self::estimate_date( $row ) : '';
		return ( '' !== $est && $est > $first ) ? $est : $first;
	}

	/** The estimate's own date, as frozen in the agreement ('' when it can't be read). */
	public static function estimate_date( array $row ): string {
		if ( empty( $row['document_sealed'] ) ) {
			return '';
		}
		$d = ZAGR_Store::document( $row );
		$y = (string) ( $d['document']['date'] ?? '' );
		return ZAGR_Calendar::valid( $y ) ? $y : '';
	}

	/** Did the buyer answer "65 or older" on the agreement page (any age_answered step that says yes)? */
	public static function buyer_older( array $row ): bool {
		$id = (int) ( $row['id'] ?? 0 );
		if ( $id <= 0 ) {
			return false;
		}
		foreach ( ZAGR_Events::of_type( $id, 'age_answered' ) as $e ) {
			$d = json_decode( (string) $e['detail'], true );
			if ( 'yes' === ZAGR_Key::open( (string) ( $d['age_sealed'] ?? '' ), 'age|' . $id ) ) {
				return true;
			}
		}
		return false;
	}

	/** The agreement's English answer: 'yes', 'no', or '' when nobody has asked yet. */
	public static function language_of( array $row ): string {
		$e = (string) ( $row['language_ok'] ?? '' );
		return '1' === $e ? 'yes' : ( '0' === $e ? 'no' : '' );
	}

	/**
	 * Why this date or age answer won't do (null when both will). [ code, message ]. With the agreement ($row): never a
	 * day before the estimate's own date, and never "No" when the buyer answered 65 or older on the agreement page.
	 */
	public static function answers( string $date, string $older, array $row = array() ): ?array {
		$today = ZAGR_Clock::today();
		if ( ! ZAGR_Calendar::valid( $date ) ) {
			return array( 'date', 'Choose the day the customer signed the paper form.' );
		}
		if ( $date > $today ) {
			return array( 'date', 'That day hasn\'t come yet. Choose the day the customer signed the paper form.' );
		}
		$first = self::first_day( $today );
		if ( $date < $first ) {
			return array( 'date', 'A paper signing can be recorded up to ' . self::BACK_DAYS . ' days after it was signed, and that day is longer ago. Choose a day from ' . self::short( $first ) . ' on.' );
		}
		$est = $row ? self::estimate_date( $row ) : '';
		if ( '' !== $est && $date < $est ) {
			return array( 'date', 'That day is before the estimate\'s own date (' . self::short( $est ) . '), so the paper agreement can\'t have been signed then. Choose the day the customer signed the paper form.' );
		}
		if ( $row && ! self::asks_age( $row ) ) {
			return null;   // the agreement's legal packs don't ask it
		}
		if ( ! in_array( $older, array( 'yes', 'no' ), true ) ) {
			return array( 'older', 'Answer whether any buyer is 65 or older: it sets how many business days the customer has to cancel.' );
		}
		if ( 'no' === $older && $row && self::buyer_older( $row ) ) {
			return array( 'older', self::BUYER_OLDER );
		}
		return null;
	}

	/* ---------------------------------------------------------------- the photos */

	/** The photo store is there (the theme's ZDZ_User_Media) and can be read the way this needs. */
	public static function store_ready(): bool {
		return class_exists( 'ZDZ_User_Media' ) && method_exists( 'ZDZ_User_Media', 'get_by_id' ) && function_exists( 'get_attached_file' );
	}

	/**
	 * The file behind a media row: [ path ('' when it can't be read), which ]. which: 'original' (the upload as it
	 * arrived, when WordPress made a "-scaled" copy of a large photo: wp_get_original_image_path()) or 'attached' (the
	 * attachment's own file). '' asks which: the original when there is one, else the attached file.
	 */
	private static function file_of( array $m, string $which = '' ): array {
		$att = (int) ( $m['wp_attachment_id'] ?? 0 );
		if ( $att <= 0 ) {
			return array( '', $which );
		}
		$attached = (string) get_attached_file( $att );
		$original = function_exists( 'wp_get_original_image_path' ) ? (string) wp_get_original_image_path( $att ) : '';
		if ( '' === $which ) {
			$which = ( '' !== $original && $original !== $attached ) ? 'original' : 'attached';
		}
		$p = 'original' === $which ? $original : $attached;
		return array( ( '' !== $p && is_file( $p ) && is_readable( $p ) ) ? $p : '', $which );
	}

	/** A photo: a photo row whose attachment is an image. */
	private static function is_photo( array $m ): bool {
		if ( 'photo' !== (string) ( $m['media_type'] ?? '' ) ) {
			return false;
		}
		$att = (int) ( $m['wp_attachment_id'] ?? 0 );
		if ( $att <= 0 ) {
			return false;
		}
		if ( function_exists( 'wp_attachment_is_image' ) ) {
			return (bool) wp_attachment_is_image( $att );
		}
		$p = self::file_of( $m )[0];
		$t = '' !== $p && function_exists( 'wp_check_filetype' ) ? (string) ( wp_check_filetype( $p )['type'] ?? '' ) : '';
		return 0 === strpos( $t, 'image/' );
	}

	/** The local day a photo was taken: its captured_at (the camera's), else when it was stored (the site's time). */
	public static function taken_on( array $m ): string {
		$cap = substr( (string) ( $m['captured_at'] ?? '' ), 0, 10 );
		return ZAGR_Calendar::valid( $cap ) ? $cap : substr( (string) ( $m['created_at'] ?? '' ), 0, 10 );
	}

	/** The paper steps' photos, read once while a scope is open (Settings' list: scope()); null when none is. */
	private static $scope_map = null;
	/** Agreement id => its standing paper signing's { number, photos } (or null), while a scope is open. */
	private static $scope_stands = array();

	/**
	 * Open (true) or close (false) a read scope: inside it, the paper steps are read once for every used_by() (
	 * Settings renders its whole list from one read). Closed, each check reads them afresh.
	 */
	public static function scope( bool $open ): void {
		self::$scope_map    = $open ? self::paper_map() : null;
		self::$scope_stands = array();
	}

	/** Media id => [ agreement id => true ] for every paper step that names it: one query. */
	public static function paper_map(): array {
		if ( null !== self::$scope_map ) {
			return self::$scope_map;
		}
		global $wpdb;
		$map = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT agreement_id, detail FROM ' . ZAGR_Install::t( 'events' ) . ' WHERE type = %s', self::EVENT ), ARRAY_A ) as $e ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name (ZAGR_Install::tables()); every value is a placeholder
			$d = json_decode( (string) $e['detail'], true );
			foreach ( is_array( $d ) ? (array) ( $d['photos'] ?? array() ) : array() as $p ) {
				$mid = is_array( $p ) ? (int) ( $p['id'] ?? 0 ) : 0;
				if ( $mid > 0 ) {
					$map[ $mid ][ (int) $e['agreement_id'] ] = true;
				}
			}
		}
		return $map;
	}

	/** An agreement's standing paper signing: { number, photos (ids) }, or null (none, or undone). */
	private static function standing( int $aid ): ?array {
		if ( null !== self::$scope_map && array_key_exists( $aid, self::$scope_stands ) ) {
			return self::$scope_stands[ $aid ];
		}
		$r = ZAGR_Store::by_id( $aid );
		$p = $r ? self::current( $r ) : null;
		$s = $p ? array( 'number' => self::number_of( $r ), 'photos' => array_column( $p['photos'], 'id' ) ) : null;
		if ( null !== self::$scope_map ) {
			self::$scope_stands[ $aid ] = $s;
		}
		return $s;
	}

	/**
	 * The estimate number of another agreement whose paper signing (standing, not undone) has this photo as its
	 * signature page ('#' alone when its number can't be read), or '' when none has. $map: paper_map(), read once by the
	 * caller for many photos.
	 */
	public static function used_by( int $media_id, int $except, ?array $map = null ): string {
		$map = $map ?? self::paper_map();
		foreach ( array_keys( $map[ $media_id ] ?? array() ) as $aid ) {
			if ( $aid === $except ) {
				continue;
			}
			$s = self::standing( (int) $aid );
			if ( $s && in_array( $media_id, $s['photos'], true ) ) {
				return '#' . $s['number'];
			}
		}
		return '';
	}

	/**
	 * The photos, checked: [ list of { id, sha256, file }, null ] or [ array(), [ code, message ] ]. Each must exist, be
	 * a photo, be private, and have been taken on or after the paper date ($date: a signature can't be photographed
	 * before it exists), and not be the signature page of another agreement. $via 'app': the recorder's own (an
	 * administrator too); 'admin': exactly what Settings' form offers ($row's recent photos for this administrator,
	 * recent_photos()) or one uploaded in this request ($uploaded).
	 */
	public static function check_photos( array $ids, int $uid, string $via, array $uploaded = array(), array $row = array(), string $date = '' ): array {
		$clean = array();
		foreach ( $ids as $i ) {
			$i = is_scalar( $i ) && preg_match( '/^[1-9][0-9]{0,18}$/', (string) $i ) ? (int) $i : 0;
			if ( $i > 0 && ! in_array( $i, $clean, true ) ) {
				$clean[] = $i;
			}
		}
		if ( ! $clean ) {
			return array( array(), array( 'photos', 'Add at least one photo of the signature page.' ) );
		}
		if ( count( $clean ) > self::MAX_PHOTOS ) {
			return array( array(), array( 'photos', 'Add at most ' . self::MAX_PHOTOS . ' photos.' ) );
		}
		if ( ! self::store_ready() ) {
			return array( array(), array( 'photos', 'Photos can\'t be checked on this site just now, so nothing was recorded.' ) );
		}
		$uploaded = array_map( 'intval', $uploaded );
		$map      = self::paper_map();
		$offered  = 'admin' === $via ? array_map( function ( $m ) { return (int) $m['id']; }, self::recent_photos( $row, $uid, $map ) ) : array();
		$out      = array();
		foreach ( $clean as $n => $id ) {
			$k = 'Photo ' . ( $n + 1 );
			$m = ZDZ_User_Media::get_by_id( $id );
			if ( ! is_array( $m ) ) {
				return array( array(), array( 'photos', $k . ' can\'t be found. Take it again, or choose another.' ) );
			}
			if ( ! self::is_photo( $m ) ) {
				return array( array(), array( 'photos', $k . ' isn\'t a photo.' ) );
			}
			if ( 'private' !== (string) ( $m['privacy'] ?? '' ) ) {   // never changed here: a shared photo is refused
				return array( array(), array( 'photos', ( count( $clean ) > 1 ? $k . ': ' : '' ) . 'This photo is shared; only private photos can be the signature page.' ) );
			}
			$other = self::used_by( $id, (int) ( $row['id'] ?? 0 ), $map );
			if ( '' !== $other ) {
				return array( array(), array( 'photos', $k . ' is already the signature page of another agreement (estimate ' . $other . '), so it can\'t be used here.' ) );
			}
			$owner = (int) ( $m['user_id'] ?? 0 );
			if ( 'admin' === $via ) {
				if ( ! in_array( $id, $uploaded, true ) && ! in_array( $id, $offered, true ) ) {
					return array( array(), array( 'photos', $k . ' isn\'t one of the photos offered here (yours, and those of whoever signed for the business, from the last ' . self::RECENT_DAYS . ' days, or one uploaded now), so it can\'t be used.' ) );
				}
			} elseif ( $owner !== $uid ) {   // the app: the person's own photo, an administrator's too
				return array( array(), array( 'photos', $k . ' isn\'t one of your photos, so it can\'t be used here.' ) );
			}
			$taken = self::taken_on( $m );
			if ( '' !== $date && ZAGR_Calendar::valid( $taken ) && $taken < $date ) {
				return array( array(), array( 'photos', $k . ' was taken on ' . self::short( $taken ) . ', before the paper date (' . self::short( $date ) . '). A signature can\'t be photographed before it exists: choose the day the customer signed, or a photo taken since.' ) );
			}
			list( $path, $which ) = self::file_of( $m );   // the original upload ("-scaled": the arrival), else the attached file
			$sha  = '' === $path ? false : hash_file( 'sha256', $path );   // the whole file, as uploaded
			$shown = self::file_of( $m, 'attached' )[0];   // and the file served full size (the "-scaled" copy)
			$served = 'original' === $which ? ( '' === $shown ? false : hash_file( 'sha256', $shown ) ) : $sha;
			if ( ! is_string( $sha ) || 64 !== strlen( $sha ) || ! is_string( $served ) || 64 !== strlen( $served ) ) {
				return array( array(), array( 'photos', $k . '\'s file can\'t be read just now. Try again in a minute.' ) );
			}
			$out[] = array( 'id' => $id, 'sha256' => $sha, 'file' => $which, 'served' => $served );
		}
		return array( $out, null );
	}

	/**
	 * Is a recorded photo the one recorded? 'same' | 'changed' | 'missing'. Both files must match, the original
	 * upload (sha256) and the file served full size (served: the "-scaled" copy, when WordPress made one).
	 */
	public static function photo_state( array $p ): string {
		if ( ! self::store_ready() ) {
			return 'missing';
		}
		$m    = ZDZ_User_Media::get_by_id( (int) ( $p['id'] ?? 0 ) );
		$path = is_array( $m ) ? self::file_of( $m, in_array( $p['file'] ?? '', array( 'original', 'attached' ), true ) ? (string) $p['file'] : '' )[0] : '';
		if ( '' === $path ) {
			return 'missing';
		}
		$shown = 'original' === ( $p['file'] ?? '' ) && isset( $p['served'] ) ? self::file_of( $m, 'attached' )[0] : $path;
		if ( '' === $shown ) {
			return 'missing';
		}
		$sha = hash_file( 'sha256', $path );
		$srv = $shown === $path ? $sha : hash_file( 'sha256', $shown );
		return is_string( $sha ) && hash_equals( (string) ( $p['sha256'] ?? '' ), $sha )
			&& is_string( $srv ) && hash_equals( (string) ( $p['served'] ?? $p['sha256'] ?? '' ), $srv ) ? 'same' : 'changed';
	}

	/* ---------------------------------------------------------------- record */

	/**
	 * When the paper signing counts as signed (UTC 'Y-m-d H:i:s'): noon local time on the paper date, or now when that is
	 * later today, so its local day is always the paper date and it is never in the future.
	 */
	public static function signed_at_for( string $date ): string {
		$noon = ( new DateTimeImmutable( $date . ' 12:00:00', ZAGR_Calendar::tz() ) )->getTimestamp();
		return ZAGR_Clock::utc( min( $noon, ZAGR_Clock::now() ) );
	}

	/**
	 * Which paper signings the administrators' alert and the Settings row flag: 'backdated', every paper date earlier
	 * than the local day it was recorded. The hold on "OK to order" for
	 * one recorded after its window had ended (late_days() > 0) is awaiting_confirm().
	 */
	const FLAG = 'backdated';

	/** The local day a paper signing was recorded ('' when it can't be read). $p: current(). */
	private static function recorded_on( array $p ): string {
		return '' !== (string) ( $p['at'] ?? '' ) ? ZAGR_Clock::local_day( ZAGR_Clock::ts( (string) $p['at'] ) ) : '';
	}

	/** How many days after the paper date a paper signing was recorded (0: on the day, or it can't be read). */
	public static function backdated_days( array $p ): int {
		$date = (string) ( $p['date'] ?? '' );
		$on   = self::recorded_on( $p );
		if ( ! ZAGR_Calendar::valid( $date ) || ! ZAGR_Calendar::valid( $on ) || $on <= $date ) {
			return 0;
		}
		$tz = ZAGR_Calendar::tz();
		return (int) ( new DateTimeImmutable( $date, $tz ) )->diff( new DateTimeImmutable( $on, $tz ) )->days;
	}

	/**
	 * For a paper signing recorded after its cancellation period had already ended (recorded on a local day after its
	 * cancel-by day): how many days after the paper date it was recorded; 0 otherwise. $p: current().
	 */
	public static function late_days( array $p ): int {
		$by = (string) ( $p['cancel_by'] ?? '' );
		$on = self::recorded_on( $p );
		if ( ! ZAGR_Calendar::valid( $by ) || ! ZAGR_Calendar::valid( $on ) || $on <= $by ) {
			return 0;
		}
		return self::backdated_days( $p );
	}

	/** The plain words for the flag (the administrators' alert and Settings' row), or ''. $mode: FLAG (tests: either). */
	public static function late_words( array $p, string $mode = self::FLAG ): string {
		$n = 'backdated' === $mode ? self::backdated_days( $p ) : self::late_days( $p );
		if ( $n <= 0 ) {
			return '';
		}
		$ended = self::late_days( $p ) > 0;
		return 'Recorded ' . $n . ' day' . ( 1 === $n ? '' : 's' ) . ' after the paper date' . ( $ended ? '; its cancellation period had already ended.' : '.' );
	}

	/**
	 * The administrator's confirmation of this paper signing: { seq, at, by, note } (the
	 * note opened with the key, '' without one), or null when there is none. $p: current() (read when not given).
	 */
	public static function confirmation( array $row, ?array $p = null ): ?array {
		$id = (int) ( $row['id'] ?? 0 );
		$p  = $p ?? self::current( $row );
		if ( ! $p || $id <= 0 ) {
			return null;
		}
		foreach ( ZAGR_Events::of_type( $id, self::CONFIRMED ) as $e ) {
			$d = json_decode( (string) $e['detail'], true );
			if ( is_array( $d ) && (int) ( $d['of'] ?? 0 ) === (int) $p['seq'] && (int) $e['seq'] > (int) $p['seq'] ) {
				$note = '' !== (string) ( $d['note_sealed'] ?? '' ) ? ZAGR_Key::open( (string) $d['note_sealed'], 'paper-confirm|' . $id . '|' . (int) $p['seq'] ) : '';
				return array( 'seq' => (int) $e['seq'], 'at' => (string) $e['at_utc'], 'by' => (int) ( $d['by'] ?? 0 ), 'note' => null === $note ? '' : (string) $note );
			}
		}
		return null;
	}

	/** A paper step whose facts can be read: its paper date, its cancel-by day, when it was recorded. $p: current(). */
	public static function step_ok( array $p ): bool {
		return ZAGR_Calendar::valid( (string) ( $p['date'] ?? '' ) ) && ZAGR_Calendar::valid( (string) ( $p['cancel_by'] ?? '' ) ) && ZAGR_Calendar::valid( self::recorded_on( $p ) );
	}

	/**
	 * A paper signing recorded after its cancellation period had ended (late_days() > 0),
	 * standing (not undone, not cancelled) and not yet confirmed by an administrator. Ordering waits for that: its
	 * ok_to_order stays false, whatever the window says. It fails closed: a row signed on paper whose
	 * paper step can't be read (a failed or empty read of the record, a step that doesn't decode, a date that isn't one,
	 * anything thrown) counts as waiting too, and never as a failed read (an order gate may let a failed read through).
	 */
	public static function awaiting_confirm( array $row ): bool {
		if ( ! self::is_paper( $row ) || 'cancelled' === (string) ( $row['state'] ?? '' ) ) {
			return false;
		}
		try {
			$p = self::current( $row );
			if ( null === $p || ! self::step_ok( $p ) ) {
				return true;   // can't be read: it waits
			}
			return self::late_days( $p ) > 0 && null === self::confirmation( $row, $p );
		} catch ( \Throwable $e ) {
			return true;
		}
	}

	/**
	 * The day a photo file says it was taken (its EXIF DateTimeOriginal, else IFD0 DateTime: the rule ZDZ_User_Media::save
	 * fills captured_at with; else WordPress's wp_read_image_metadata), Y-m-d, or '' when it doesn't say. An
	 * upload is checked against the paper date before it is stored.
	 */
	public static function file_taken_on( string $path ): string {
		if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			return '';
		}
		$dto = '';
		if ( function_exists( 'exif_read_data' ) ) {
			$e   = @exif_read_data( $path, null, true );   // phpcs:ignore -- a file without EXIF says nothing
			$dto = is_array( $e ) ? (string) ( $e['EXIF']['DateTimeOriginal'] ?? ( $e['IFD0']['DateTime'] ?? '' ) ) : '';
		}
		if ( preg_match( '/^(\d{4}):(\d{2}):(\d{2})/', $dto, $m ) && ZAGR_Calendar::valid( "{$m[1]}-{$m[2]}-{$m[3]}" ) ) {
			return "{$m[1]}-{$m[2]}-{$m[3]}";
		}
		if ( function_exists( 'wp_read_image_metadata' ) ) {
			$md = wp_read_image_metadata( $path );
			$t  = is_array( $md ) ? (int) ( $md['created_timestamp'] ?? 0 ) : 0;
			if ( $t > 0 ) {
				return gmdate( 'Y-m-d', $t );   // WordPress reads the camera's local time as if UTC
			}
		}
		return '';
	}

	/**
	 * Record a paper signing on an estimate's agreement (the one server path). $in: date (local Y-m-d), older (yes|no, where asked),
	 * photos (ZDZ_User_Media ids). $via: 'app' (the estimate in the app, the verb) | 'admin' (Settings). $uploaded: ids
	 * uploaded in this request by this administrator. Never throws:
	 * { ok, code, message, state, signed_via, signed_on, cancel_by, ok_to_order, waiting_for ('admin_confirm' | '') }.
	 */
	public static function record( int $estimate_id, int $uid, array $in, string $via = 'app', array $uploaded = array() ): array {
		self::$held = null;
		try {
			return self::record_inner( $estimate_id, $uid, $in, 'admin' === $via ? 'admin' : 'app', $uploaded );
		} catch ( \Throwable $e ) {
			error_log( 'Zorderz agreements: a paper signing could not be recorded (' . get_class( $e ) . ').' ); // phpcs:ignore -- never a value
			if ( null !== self::$held ) {   // never a claim left behind: the row back exactly as it was
				try {
					self::release( self::$held, 'error' );
				} catch ( \Throwable $e2 ) {
					error_log( 'Zorderz agreements: a paper claim could not be let go (' . get_class( $e2 ) . '); the hourly heal will.' ); // phpcs:ignore
				}
			}
			return self::out( false, 'error', 'The paper signing could not be recorded just now. Try again in a minute.' );
		} finally {
			self::$held = null;
		}
	}

	/** The claim this request holds while it records: { id, from, seq (its step, 0 until written) }, or null. */
	private static $held = null;

	/**
	 * Let a claim go: the row back to the state it was in (copy_via cleared; contractor_user_id never touched by the
	 * claim), and, when its step was written, paper_undone ($why) after it. Only the claim this request made.
	 */
	private static function release( array $h, string $why ): void {
		global $wpdb;
		$n = (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . ZAGR_Install::t( 'agreements' ) . " SET state = %s, copy_via = '', updated_at = %s WHERE id = %d AND state = 'signing' AND copy_via = %s", $h['from'], ZAGR_Clock::utc(), $h['id'], self::CLAIM . $h['from'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name (ZAGR_Install::tables()); every value is a placeholder
		if ( 1 === $n && $h['seq'] > 0 ) {
			ZAGR_Events::append( $h['id'], self::UNDONE, array( 'of' => $h['seq'], 'why' => $why ), 'system' );
		}
	}

	/** The language answer staff gave with the paper signing, kept on the agreement and in its record (as Approve does). */
	private static function save_language( array $row, bool $yes, int $uid ): void {
		ZAGR_Store::update( (int) $row['id'], array( 'language_ok' => $yes ? 1 : 0 ) );
		ZAGR_Events::append( (int) $row['id'], 'language', array( 'answer' => $yes ? 'yes' : 'no' ), 'staff:' . $uid );
	}

	private static function record_inner( int $estimate_id, int $uid, array $in, string $via, array $uploaded ): array {
		if ( ! ZAGR_Install::ready() ) {
			return self::out( false, 'not_ready', 'Agreements can\'t be recorded on this site just now (Settings, Agreements says why).' );
		}
		$row = $estimate_id > 0 ? ZAGR_Store::by_estimate( $estimate_id ) : null;
		if ( ! $row ) {
			return self::out( false, 'none', 'This estimate has no agreement yet, so there is nothing to record a paper signing on. Approve it in the app first: approving makes the agreement.' );
		}
		$row = ZAGR_Store::refresh_window( $row );   // a "signing" left by a request that died is healed first
		$b   = self::blocker( $row, $uid );
		if ( null !== $b ) {
			return self::out( false, $b[0], $b[1] );
		}
		// an agreement needs the language question answered yes (as contractor_sign); unanswered, it comes with this
		// request: a "No" is kept and refused in the online path's words, a "Yes" is kept once everything else checks out
		$ask = '1' !== (string) $row['language_ok'];
		$eng = is_scalar( $in['language_ok'] ?? '' ) ? strtolower( trim( (string) ( $in['language_ok'] ?? '' ) ) ) : '';
		if ( $ask && 'no' === $eng ) {
			self::save_language( $row, false, $uid );
			return self::out( false, 'not_language', self::not_language_words() );
		}
		if ( $ask && 'yes' !== $eng ) {
			return self::out( false, 'language', self::ask_language_words() );
		}
		$date  = trim( is_scalar( $in['date'] ?? '' ) ? (string) ( $in['date'] ?? '' ) : '' );
		$older = is_scalar( $in['older'] ?? '' ) ? (string) ( $in['older'] ?? '' ) : '';
		$a     = self::answers( $date, $older, $row );
		if ( null !== $a ) {
			return self::out( false, $a[0], $a[1] );
		}
		$older = self::asks_age( $row ) ? $older : 'na';
		// a staff question the agreement's packs ask and nobody answered yet comes with this request: yes or no
		$doc  = (array) ZAGR_Store::document( $row );
		$staff = array();
		foreach ( self::open_questions( $row ) as $qid => $q ) {
			$v = is_scalar( $in[ $qid ] ?? '' ) ? strtolower( trim( (string) ( $in[ $qid ] ?? '' ) ) ) : '';
			if ( ! in_array( $v, array( 'yes', 'no' ), true ) ) {
				return self::out( false, 'answers', 'Answer this first: ' . (string) ( $q['label'] ?? $qid ) );
			}
			$staff[ $qid ]          = $v;
			$doc['answers'][ $qid ] = $v;
		}
		list( $photos, $no ) = self::check_photos( is_array( $in['photos'] ?? null ) ? $in['photos'] : array(), $uid, $via, $uploaded, $row, $date );
		if ( null !== $no ) {
			return self::out( false, $no[0], $no[1] );
		}
		$id        = (int) $row['id'];
		$from      = (string) $row['state'];
		$days      = ZAGR_Document::window_days( $doc, $older );
		$cancel_by = ZAGR_Document::deadline( $doc, $date, $older );
		$sealed    = ZAGR_Key::seal( $older, 'age|' . $id );   // sealed as every age answer in the record
		$sdoc      = $staff ? ZAGR_Key::seal( (string) wp_json_encode( $doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), 'doc|' . (int) $row['estimate_id'] ) : '';
		if ( '' === $cancel_by || null === $sealed || null === $sdoc ) {
			return self::out( false, 'not_ready', 'A paper signing can\'t be recorded until the agreements key is entered (Settings, Agreements).' );
		}
		// a draft takes the salesperson as the one who signed for the business (the paper carries that signature), so "OK
		// to order" reaches them as usual
		$set = 0;
		if ( 0 === (int) $row['contractor_user_id'] ) {
			if ( self::is_salesperson( $uid, $row ) || ! ZAGR_People::is_admin_tier( $uid ) ) {
				$set = $uid;
			} else {
				foreach ( self::salespeople( $row ) as $s ) {
					if ( ZAGR_People::is_staff( $s ) && ! ZAGR_People::is_kiosk( $s ) ) {
						$set = $s;
						break;
					}
				}
			}
		}
		global $wpdb;
		$t = ZAGR_Install::t( 'agreements' );
		// claim it, as an online signature does: only one request signs, and only from the state that was read. copy_via
		// carries the state it held ("pc:draft"), so a claim whose request dies is put back exactly (heal_claim())
		$n = (int) $wpdb->query( $wpdb->prepare( "UPDATE $t SET state = 'signing', copy_via = %s, updated_at = %s WHERE id = %d AND state = %s AND signed_at IS NULL", self::CLAIM . $from, ZAGR_Clock::utc(), $id, $from ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix and a fixed name (ZAGR_Install::tables()); every value is a placeholder
		if ( 1 !== $n ) {
			return self::out( false, 'changed', 'The agreement changed while this was being recorded, so nothing was recorded. Look again.' );
		}
		self::$held = array( 'id' => $id, 'from' => $from, 'seq' => 0 );
		foreach ( $staff as $qid => $v ) {
			ZAGR_Events::append( $id, 'staff_answer', array( 'question' => $qid, 'answer' => $v, 'with' => 'paper' ), 'staff:' . $uid );
		}
		$e = ZAGR_Events::append( $id, self::EVENT, array(
			'date' => $date, 'age_sealed' => $sealed, 'days' => $days, 'cancel_by' => $cancel_by, 'by' => $uid, 'via' => $via, 'from' => $from,
			'contractor_set' => $set, 'doc_hash' => (string) $row['doc_hash'], 'photos' => $photos,
		), 'staff:' . $uid );
		if ( null === $e ) {
			self::release( self::$held, 'not_saved' );
			return self::out( false, 'not_ready', 'The paper signing couldn\'t be written to the agreement\'s record (is the agreements key entered?), so nothing was recorded.' );
		}
		self::$held['seq'] = (int) $e['seq'];
		$fields = array(
			'state' => 'signed', 'signed_at' => self::signed_at_for( $date ), 'copy_received_at' => ZAGR_Clock::utc(), 'copy_via' => self::VIA,
			'cancel_by' => $cancel_by, 'age_sealed' => $sealed,
		);
		if ( $set > 0 ) {
			$fields['contractor_user_id'] = $set;
		}
		if ( '' !== $sdoc ) {
			$fields['document_sealed'] = $sdoc;   // the staff answers, recorded on the agreement (doc_hash is unchanged)
		}
		if ( 1 !== ZAGR_Store::update( $id, $fields, array( 'signing' ) ) ) {
			self::release( self::$held, 'not_saved' );
			return self::out( false, 'error', 'The paper signing could not be saved, so nothing was recorded. Try again in a minute.' );
		}
		self::$held = null;
		if ( $ask ) {   // the language Yes is kept only now, with the paper signing recorded (a refusal leaves nothing)
			self::save_language( $row, true, $uid );
		}
		// the administrators' bell with the photo to look at; then the window (closed at once for a paper date long past,
		// which sends "OK to order" as any closing does). Neither ever stands in the way: ZAGR_Alerts catches everything.
		ZAGR_Alerts::signed_on_paper( $id );
		$row  = ZAGR_Store::refresh_window( ZAGR_Store::by_id( $id ) ?? $row );
		$wait = self::awaiting_confirm( $row );   // its window had ended when it was recorded
		$ok   = 'window_closed' === (string) $row['state'] && ! empty( $row['window_closed_at'] ) && ! $wait;
		$msg  = 'Recorded as signed on paper on ' . self::short( $date ) . '. ' . ( $wait
			? 'Its cancellation period ended at midnight ' . self::short( $cancel_by ) . ', before this was recorded. ' . self::WAITS
			: ( $ok
				? 'Its cancellation period ended at midnight ' . self::short( $cancel_by ) . ', so it is OK to order now.'
				: 'The customer can cancel until midnight ' . self::short( $cancel_by ) . '; ordering opens after that.' ) );
		return self::out( true, 'ok', $msg, array( 'state' => (string) $row['state'], 'signed_via' => 'paper', 'signed_on' => $date, 'cancel_by' => $cancel_by, 'ok_to_order' => $ok, 'waiting_for' => $wait ? 'admin_confirm' : '' ) );
	}

	/** The newest paper step that was neither finished nor undone (a claim whose request died after writing it), or null. */
	private static function unfinished( int $id ): ?array {
		$e = self::last_event( $id, self::EVENT );
		if ( ! $e ) {
			return null;
		}
		foreach ( ZAGR_Events::of_type( $id, self::UNDONE ) as $u ) {
			$d = json_decode( (string) $u['detail'], true );
			if ( (int) ( $d['of'] ?? 0 ) === $e['seq'] ) {
				return null;
			}
		}
		return $e;
	}

	/**
	 * A claim held in "signing" by a paper signing whose request died (ZAGR_Store::heal, after 5 minutes): back exactly
	 * to the state it was in - a draft stays a draft, never "ready", and the signer for the business as it was. Which
	 * state: the claim's own copy_via ("pc:draft"); else a row nobody signed for the business (only a paper claim holds a
	 * draft: an online signature claims a ready agreement); else its unfinished paper step's. Its step, when written, is
	 * undone in the record (paper_undone, interrupted). True when it was one (and was put back).
	 */
	public static function heal_claim( array $row ): bool {
		$id = (int) ( $row['id'] ?? 0 );
		if ( $id <= 0 || 'signing' !== (string) ( $row['state'] ?? '' ) || ! empty( $row['signed_at'] ) ) {
			return false;
		}
		$via = (string) ( $row['copy_via'] ?? '' );
		if ( 0 === strpos( $via, self::CLAIM ) ) {
			$from = substr( $via, strlen( self::CLAIM ) );
		} elseif ( 0 === (int) ( $row['contractor_user_id'] ?? 0 ) ) {
			$from = 'draft';
		} else {
			$u    = self::unfinished( $id );
			$from = $u ? (string) ( $u['d']['from'] ?? '' ) : '';
		}
		if ( ! in_array( $from, self::UNSIGNED, true ) ) {
			return false;
		}
		global $wpdb;
		$n = (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . ZAGR_Install::t( 'agreements' ) . " SET state = %s, copy_via = '', updated_at = %s WHERE id = %d AND state = 'signing' AND signed_at IS NULL", $from, ZAGR_Clock::utc(), $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name (ZAGR_Install::tables()); every value is a placeholder
		if ( 1 === $n ) {
			$u = self::unfinished( $id );
			if ( $u ) {
				ZAGR_Events::append( $id, self::UNDONE, array( 'of' => $u['seq'], 'why' => 'interrupted' ), 'system' );
			}
		}
		return true;
	}

	/* ---------------------------------------------------------------- undo */

	/** "Undo the paper signing": an administrator, a typed reason, only before OK to order. { ok, message }. */
	public static function undo( int $agreement_id, int $uid, string $reason ): array {
		try {
			return self::undo_inner( $agreement_id, $uid, $reason );
		} catch ( \Throwable $e ) {
			error_log( 'Zorderz agreements: a paper signing could not be undone (' . get_class( $e ) . ').' ); // phpcs:ignore
			return self::out( false, 'error', 'The paper signing could not be undone just now. Try again in a minute.' );
		}
	}

	private static function undo_inner( int $agreement_id, int $uid, string $reason ): array {
		if ( ! ZAGR_People::is_admin( $uid ) ) {
			return self::out( false, 'not_admin', 'Only an administrator can undo a paper signing.' );
		}
		$reason = ZAGR_Settings::clean( $reason, 300 );
		if ( ( function_exists( 'mb_strlen' ) ? mb_strlen( $reason ) : strlen( $reason ) ) < 4 ) {
			return self::out( false, 'reason', 'Say why it is undone, in a few words.' );
		}
		if ( ! ZAGR_Key::usable() ) {
			return self::out( false, 'not_ready', 'Enter the agreements key first.' );
		}
		$row = ZAGR_Store::by_id( $agreement_id );
		if ( ! $row ) {
			return self::out( false, 'none', 'No such agreement.' );
		}
		$row = ZAGR_Store::refresh_window( $row );   // a window whose day has passed closes first: then it is OK to order
		if ( ! self::is_paper( $row ) ) {
			return self::out( false, 'not_paper', 'This agreement isn\'t recorded as signed on paper.' );
		}
		$state = (string) $row['state'];
		if ( 'cancelled' === $state ) {
			return self::out( false, 'cancelled', 'The customer cancelled this agreement, so its paper signing stays as it is.' );
		}
		// a late one waiting for an administrator's confirmation was never OK to order, so
		// it is undone like any other (its window closed when it was recorded: that is put back too)
		$late = self::awaiting_confirm( $row );
		if ( ! $late && ( 'window_closed' === $state || ! empty( $row['window_closed_at'] ) ) ) {
			return self::out( false, 'ok_to_order', 'Its cancellation period has ended and it is OK to order, so the paper signing can\'t be undone.' );
		}
		if ( 'signed' !== $state && ! ( $late && 'window_closed' === $state ) ) {   // a change order waiting (change_pending)
			return self::out( false, 'change_pending', self::CHANGE_MADE );
		}
		if ( ZAGR_Store::changes( (int) $row['id'] ) ) {   // a change order made after it, signed since
			return self::out( false, 'change_order', self::CHANGE_MADE );
		}
		$p = self::current( $row );
		if ( ! $p ) {
			return self::out( false, 'record', 'The paper signing\'s step isn\'t in the agreement\'s record, so it can\'t be undone here.' );
		}
		$id     = (int) $row['id'];
		$sealed = ZAGR_Key::seal( $reason, 'paper-undo|' . $id . '|' . $p['seq'] );   // a reason may name someone: kept sealed
		if ( null === $sealed ) {
			return self::out( false, 'not_ready', 'Enter the agreements key first.' );
		}
		$back_c = ( $p['contractor_set'] > 0 && (int) $row['contractor_user_id'] === $p['contractor_set'] ) ? 0 : (int) $row['contractor_user_id'];
		global $wpdb;
		$t = ZAGR_Install::t( 'agreements' );
		// only from the state that was read: before its window closed; or a late one whose window closed
		// when it was recorded, only while no confirmation has been written since its paper step; and only
		// while the record's head is the one read (event_count), so an undo and a new recording, or a change order made and
		// taken back, in between are never undone by this request
		$still = ( $late
			? $wpdb->prepare( 'NOT EXISTS (SELECT 1 FROM ' . ZAGR_Install::t( 'events' ) . ' WHERE agreement_id = %d AND type = %s AND seq > %d)', $id, self::CONFIRMED, (int) $p['seq'] ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name (ZAGR_Install::tables()); every value is a placeholder
			: 'window_closed_at IS NULL' ) . $wpdb->prepare( ' AND event_count = %d', (int) ( $row['event_count'] ?? -1 ) );
		$n = (int) $wpdb->query( $wpdb->prepare( "UPDATE $t SET state = %s, signed_at = NULL, copy_received_at = NULL, copy_via = '', cancel_by = NULL, age_sealed = NULL, contractor_user_id = %d, window_closed_at = NULL, updated_at = %s WHERE id = %d AND state = %s AND copy_via = %s AND ", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix and a fixed name (ZAGR_Install::tables()); every value is a placeholder
			$p['from'], $back_c, ZAGR_Clock::utc(), $id, $state, self::VIA ) . $still );
		if ( 1 !== $n ) {
			return self::out( false, 'changed', 'The agreement changed while this was being undone. Look again.' );
		}
		if ( ! ZAGR_Events::append( $id, self::UNDONE, array( 'of' => $p['seq'], 'why' => 'staff', 'by' => $uid, 'reason_sealed' => $sealed, 'back_to' => $p['from'] ), 'staff:' . $uid ) ) {
			// never an undo the record doesn't hold: put the paper signing back as it was (a late one's closed window too)
			$args   = array( $state, (string) $row['signed_at'], (string) $row['copy_received_at'], self::VIA, (string) $row['cancel_by'], (string) $row['age_sealed'], (int) $row['contractor_user_id'], ZAGR_Clock::utc() );
			$closed = 'NULL';
			if ( ! empty( $row['window_closed_at'] ) ) {
				$closed = '%s';
				$args[] = (string) $row['window_closed_at'];
			}
			$args[] = $id;
			$args[] = $p['from'];
			$wpdb->query( $wpdb->prepare( "UPDATE $t SET state = %s, signed_at = %s, copy_received_at = %s, copy_via = %s, cancel_by = %s, age_sealed = %s, contractor_user_id = %d, updated_at = %s, window_closed_at = $closed WHERE id = %d AND state = %s AND signed_at IS NULL", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix and a fixed name; $closed is the literal NULL or a %s placeholder; every value is a placeholder
			return self::out( false, 'not_ready', 'The undo couldn\'t be written to the agreement\'s record, so nothing changed.' );
		}
		return self::out( true, 'ok', 'The paper signing is undone. The agreement is unsigned again (' . ( 'draft' === $p['from'] ? 'a draft' : 'ready for the customer to sign' ) . '), and a paper signing can be recorded again.' );
	}

	/* ---------------------------------------------------------------- confirm a late one */

	/**
	 * "Confirm this late paper signing" (Settings): an administrator (as the undo: manage_options, never the kiosk or Demo
	 * Mode), who ticked that they looked at the photo of the signature page and the paper date ($looked), with an optional
	 * note (kept sealed: it may name someone). Only while the row is a paper signing awaiting confirmation
	 * (awaiting_confirm()). Its own chained step (paper_late_confirmed: which paper step, who; when is the step's own
	 * time), then "OK to order" as any window closing sends it (once). { ok, code, message }. Never throws.
	 */
	public static function confirm_late( int $agreement_id, int $uid, bool $looked, string $note = '' ): array {
		try {
			return self::confirm_inner( $agreement_id, $uid, $looked, $note );
		} catch ( \Throwable $e ) {
			error_log( 'Zorderz agreements: a late paper signing could not be confirmed (' . get_class( $e ) . ').' ); // phpcs:ignore
			return self::out( false, 'error', 'The late paper signing could not be confirmed just now. Try again in a minute.' );
		}
	}

	private static function confirm_inner( int $agreement_id, int $uid, bool $looked, string $note ): array {
		if ( ! ZAGR_People::is_admin( $uid ) ) {
			return self::out( false, 'not_admin', 'Only an administrator can confirm a late paper signing.' );
		}
		if ( ! $looked ) {
			return self::out( false, 'looked', 'Tick the box to say you looked at the photo of the signature page and the paper date.' );
		}
		$note = ZAGR_Settings::clean( $note, 300 );
		if ( ! ZAGR_Key::usable() ) {
			return self::out( false, 'not_ready', 'Enter the agreements key first.' );
		}
		$row = ZAGR_Store::by_id( $agreement_id );
		if ( ! $row ) {
			return self::out( false, 'none', 'No such agreement.' );
		}
		$row = ZAGR_Store::refresh_window( $row );
		$p   = self::current( $row );
		if ( ! self::is_paper( $row ) ) {
			return self::out( false, 'not_paper', 'This agreement isn\'t recorded as signed on paper, so there is nothing to confirm.' );
		}
		if ( 'cancelled' === (string) $row['state'] ) {
			return self::out( false, 'cancelled', self::CANCELLED_NONE );
		}
		if ( ! $p || ! self::step_ok( $p ) ) {   // it waits (fails closed), but nobody confirms what can't be read
			return self::out( false, 'record', self::UNREADABLE );
		}
		if ( ! self::awaiting_confirm( $row ) ) {
			if ( null !== self::confirmation( $row, $p ) ) {
				return self::out( false, 'confirmed', 'This late paper signing is already confirmed.' );
			}
			return self::out( false, 'not_late', 'This paper signing was recorded before its cancellation period ended, so it needs no confirmation.' );
		}
		$id     = (int) $row['id'];
		$sealed = '' === $note ? '' : ZAGR_Key::seal( $note, 'paper-confirm|' . $id . '|' . $p['seq'] );   // a note may name someone
		if ( null === $sealed ) {
			return self::out( false, 'not_ready', 'Enter the agreements key first.' );
		}
		$d = array( 'of' => (int) $p['seq'], 'by' => $uid, 'looked' => true ) + ( '' !== $sealed ? array( 'note_sealed' => $sealed ) : array() );
		if ( ! ZAGR_Events::append( $id, self::CONFIRMED, $d, 'staff:' . $uid ) ) {
			return self::out( false, 'not_ready', 'The confirmation couldn\'t be written to the agreement\'s record, so nothing changed.' );
		}
		$row = ZAGR_Store::by_id( $id ) ?? $row;
		if ( 'cancelled' === (string) $row['state'] ) {   // a cancellation recorded at that very moment wins
			return self::out( false, 'cancelled', self::CANCELLED_NONE );
		}
		$now = self::current( $row );
		if ( ! $now || (int) $now['seq'] !== (int) $p['seq'] ) {   // undone at that very moment: the record says both
			return self::out( false, 'changed', 'The agreement changed while this was being confirmed. Look again.' );
		}
		ZAGR_Alerts::ok_to_order( $id );   // "OK to order", as any window closing sends it: once (ZAGR_Alerts says when)
		$row = ZAGR_Store::refresh_window( $row );
		$ok  = 'window_closed' === (string) $row['state'] && ! empty( $row['window_closed_at'] );
		return self::out( true, 'ok', 'The late paper signing is confirmed. ' . ( $ok
			? 'It is OK to order now.'
			: ( 'change_pending' === (string) $row['state'] ? 'A change order is waiting for the customer\'s signature, so ordering waits for that.' : 'Ordering opens when its cancellation period has ended.' ) ) );
	}

	/* ---------------------------------------------------------------- Settings: recent photos, uploads */

	/**
	 * Photos taken in the last 14 days by this administrator and by the one who signed for the business
	 * (contractor_user_id), if any (gated links only): what Settings' form offers, and so what check_photos() accepts
	 * there. Never a shared photo, nor one that is already another agreement's signature page (the form offers
	 * exactly what the server takes). Never the salesperson filter (zagr_estimate_salespeople), whose
	 * answer can need a call outside this site: Settings asks nothing outside it. $map: paper_map(), when the caller has it.
	 */
	public static function recent_photos( array $row, int $admin, ?array $map = null ): array {
		if ( ! self::store_ready() || ! method_exists( 'ZDZ_User_Media', 'get_user_media' ) ) {
			return array();
		}
		$people = array_values( array_unique( array_filter( array( $admin, (int) ( $row['contractor_user_id'] ?? 0 ) ) ) ) );
		$map    = $map ?? self::paper_map();
		// created_at is the site's own local time, so the photos are kept by their day
		$first  = ( new DateTimeImmutable( ZAGR_Clock::today(), ZAGR_Calendar::tz() ) )->modify( '-' . self::RECENT_DAYS . ' day' )->format( 'Y-m-d' );
		$out    = array();
		foreach ( array_slice( $people, 0, 6 ) as $u ) {
			if ( $u !== $admin && ! ZAGR_People::is_staff( $u ) ) {
				continue;
			}
			foreach ( (array) ZDZ_User_Media::get_user_media( $u, array( 'media_type' => 'photo', 'limit' => 40 ) ) as $m ) {
				if ( ! is_array( $m ) || substr( (string) ( $m['created_at'] ?? '' ), 0, 10 ) < $first ) {
					continue;
				}
				if ( 'private' !== (string) ( $m['privacy'] ?? '' ) || '' !== self::used_by( (int) $m['id'], (int) ( $row['id'] ?? 0 ), $map ) ) {
					continue;
				}
				$out[ (int) $m['id'] ] = $m;
			}
		}
		uasort( $out, function ( $a, $b ) { return strcmp( (string) $b['created_at'], (string) $a['created_at'] ); } );
		return array_slice( array_values( $out ), 0, 24 );
	}

	/** The files of Settings' upload ($files: one input, multiple), one array each; empty slots left out. */
	private static function slots( array $files ): array {
		$out = array();
		if ( empty( $files['name'] ) ) {
			return $out;
		}
		foreach ( array_keys( (array) $files['name'] ) as $i ) {
			$one = array();
			foreach ( array( 'name', 'type', 'tmp_name', 'error', 'size' ) as $k ) {
				$one[ $k ] = is_array( $files[ $k ] ?? null ) ? ( $files[ $k ][ $i ] ?? '' ) : ( $files[ $k ] ?? '' );
			}
			if ( '' === (string) $one['name'] && UPLOAD_ERR_NO_FILE === (int) $one['error'] ) {
				continue;   // an empty slot
			}
			$out[] = $one;
		}
		return $out;
	}

	/** Why one of these files won't do (null when every one will): whole, an image, 25 MB at most. Nothing is stored. */
	private static function check_files( array $slots ): ?string {
		foreach ( $slots as $one ) {
			if ( UPLOAD_ERR_OK !== (int) $one['error'] || (int) $one['size'] <= 0 ) {
				return 'A photo didn\'t arrive whole. Choose it again.';
			}
			if ( (int) $one['size'] > self::MAX_BYTES ) {
				return 'That photo is too large (25 MB at most).';
			}
			$ft = function_exists( 'wp_check_filetype' ) ? wp_check_filetype( (string) $one['name'] ) : array( 'type' => '' );
			if ( empty( $ft['type'] ) || 0 !== strpos( (string) $ft['type'], 'image/' ) ) {
				return 'That file isn\'t an image. Only photos of the signature page can be added here.';
			}
		}
		return null;
	}

	/** An uploaded photo's stored name: random and not guessable, never the file's own name (it may name the customer). */
	public static function random_name( string $name ): string {
		$ext = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
		return 'signature-page-' . bin2hex( random_bytes( 12 ) ) . '.' . ( preg_match( '/^[a-z0-9]{1,5}$/', $ext ) ? $ext : 'jpg' );
	}

	/**
	 * Settings' upload from the computer: each file in $files (one input, multiple), checked first (check_files: a
	 * refusal stores nothing), then stored through media_handle_upload() under a random name and ZDZ_User_Media::save() as
	 * the administrator's own private photo (source_app agreements), as the camera app stores a photo.
	 * [ ids, null ] or [ ids so far, message ].
	 */
	public static function uploads( int $uid, array $files, array $row ): array {
		$ids   = array();
		$slots = self::slots( $files );
		if ( ! $slots ) {
			return array( $ids, null );
		}
		if ( ! self::store_ready() || ! method_exists( 'ZDZ_User_Media', 'save' ) ) {
			return array( $ids, 'Photos can\'t be stored on this site just now, so nothing was recorded.' );
		}
		$bad = self::check_files( $slots );
		if ( null !== $bad ) {
			return array( $ids, $bad );
		}
		if ( ! function_exists( 'media_handle_upload' ) && defined( 'ABSPATH' ) && is_readable( ABSPATH . 'wp-admin/includes/media.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$num = self::number_of( $row );
		foreach ( $slots as $one ) {
			$one['name'] = self::random_name( (string) $one['name'] );
			$_FILES[ self::UPLOAD_KEY ] = $one;
			$att = function_exists( 'media_handle_upload' ) ? media_handle_upload( self::UPLOAD_KEY, 0, array(), array( 'test_form' => false ) ) : 0;
			unset( $_FILES[ self::UPLOAD_KEY ] );
			if ( ! is_int( $att ) || $att <= 0 ) {
				return array( $ids, 'A photo could not be stored. Try again.' );
			}
			$url   = (string) wp_get_attachment_url( $att );
			$thumb = function_exists( 'wp_get_attachment_image_url' ) ? (string) wp_get_attachment_image_url( $att, 'medium' ) : '';
			$m     = ZDZ_User_Media::save( array(
				'user_id' => $uid, 'file_url' => $url, 'thumbnail_url' => '' !== $thumb ? $thumb : $url, 'filename' => $one['name'],
				'media_type' => 'photo', 'source_app' => 'agreements', 'source_ref' => 'paper:' . (int) $row['id'] . ':' . $att,
				'title' => 'Signature page' . ( '' !== $num ? ', estimate #' . $num : '' ), 'privacy' => 'private', 'wp_attachment_id' => $att,
			) );
			if ( ! is_array( $m ) || (int) ( $m['id'] ?? 0 ) <= 0 ) {
				return array( $ids, 'A photo could not be stored. Try again.' );
			}
			$ids[] = (int) $m['id'];
		}
		return array( $ids, null );
	}

	/** The estimate number as frozen in the agreement ('' without the key). */
	public static function number_of( array $row ): string {
		return class_exists( 'ZAGR_Alerts' ) ? ZAGR_Alerts::number( $row ) : '';
	}

	/**
	 * Settings' form (an administrator). Everything is checked before anything is uploaded, so a refusal leaves no photo
	 * behind: who, the state, the language answer, the day and the answers, the files (whole, images, 25 MB at most),
	 * how many photos in all, and each picked photo (check_photos); then the uploads, then record(). { ok, message }.
	 */
	public static function from_admin( int $agreement_id, int $uid, array $post, array $files ): array {
		try {
			$row = ZAGR_Store::by_id( $agreement_id );
			if ( ! $row ) {
				return self::out( false, 'none', 'No such agreement.' );
			}
			$row   = ZAGR_Store::refresh_window( $row );
			$date  = trim( is_scalar( $post['paper_date'] ?? '' ) ? (string) ( $post['paper_date'] ?? '' ) : '' );
			$older = is_scalar( $post['older'] ?? '' ) ? (string) ( $post['older'] ?? '' ) : '';
			$eng   = is_scalar( $post['language_ok'] ?? '' ) ? (string) ( $post['language_ok'] ?? '' ) : '';
			$pre   = self::blocker( $row, $uid );
			if ( null !== $pre ) {
				return self::out( false, $pre[0], $pre[1] );
			}
			if ( '1' !== (string) $row['language_ok'] && 'yes' !== $eng ) {   // a "No" is kept and refused by record(), nothing uploaded
				return self::record( (int) $row['estimate_id'], $uid, array( 'language_ok' => $eng, 'date' => $date, 'older' => $older ), 'admin' );
			}
			$pre = self::answers( $date, $older, $row );
			if ( null !== $pre ) {
				return self::out( false, $pre[0], $pre[1] );
			}
			$staff = array();
			foreach ( self::open_questions( $row ) as $qid => $q ) {
				$v = is_scalar( $post[ 'q_' . $qid ] ?? '' ) ? (string) ( $post[ 'q_' . $qid ] ?? '' ) : '';
				if ( ! in_array( $v, array( 'yes', 'no' ), true ) ) {
					return self::out( false, 'answers', 'Answer this first: ' . (string) ( $q['label'] ?? $qid ) );
				}
				$staff[ $qid ] = $v;
			}
			$picked = is_array( $post['photos'] ?? null ) ? array_values( $post['photos'] ) : array();
			$slots  = self::slots( is_array( $files['paper_photo'] ?? null ) ? $files['paper_photo'] : array() );
			$bad    = self::check_files( $slots );
			if ( null !== $bad ) {
				return self::out( false, 'photos', $bad );
			}
			$ids = array_unique( array_filter( array_map( function ( $i ) { return is_scalar( $i ) && preg_match( '/^[1-9][0-9]{0,18}$/', (string) $i ) ? (int) $i : 0; }, $picked ) ) );
			$all = count( $ids ) + count( $slots );
			if ( 0 === $all ) {
				return self::out( false, 'photos', 'Add at least one photo of the signature page.' );
			}
			if ( $all > self::MAX_PHOTOS ) {
				return self::out( false, 'photos', 'Add at most ' . self::MAX_PHOTOS . ' photos.' );
			}
			foreach ( $slots as $one ) {   // an upload's own date (EXIF), before anything is stored
				$taken = self::file_taken_on( (string) $one['tmp_name'] );
				if ( '' !== $taken && $taken < $date ) {
					return self::out( false, 'photos', 'An uploaded photo was taken on ' . self::short( $taken ) . ', before the paper date (' . self::short( $date ) . '). A signature can\'t be photographed before it exists: choose the day the customer signed, or a photo taken since.' );
				}
			}
			if ( $picked ) {
				$no = self::check_photos( $picked, $uid, 'admin', array(), $row, $date )[1];
				if ( null !== $no ) {
					return self::out( false, $no[0], $no[1] );
				}
			}
			list( $up, $err ) = self::uploads( $uid, is_array( $files['paper_photo'] ?? null ) ? $files['paper_photo'] : array(), $row );
			if ( null !== $err ) {
				return self::out( false, 'photos', $err );
			}
			return self::record( (int) $row['estimate_id'], $uid, array( 'date' => $date, 'older' => $older, 'language_ok' => $eng, 'photos' => array_merge( $up, $picked ) ) + $staff, 'admin', $up );
		} catch ( \Throwable $e ) {
			error_log( 'Zorderz agreements: a paper signing could not be recorded from Settings (' . get_class( $e ) . ').' ); // phpcs:ignore
			return self::out( false, 'error', 'The paper signing could not be recorded just now. Try again in a minute.' );
		}
	}
}
