<?php
/**
 * ZAGR_Flow - what the customer does on the page, and what staff record from the settings screen. [CORE]
 *
 * Every step is checked on the server, against the session (the cookie's row) and the exact version being signed
 * ("agr:<doc_hash>:<answer tag>", or "co:<id>:<doc_hash>" for a change order):
 *
 *   0. the buyer's question         where the agreement's legal packs ask it ("Is any buyer 65 or older?"): required
 *                                   before anything else, never assumed; the answer picks the pack's window and its
 *                                   words. It is kept sealed in the session, and its tag (a MAC, not the answer) is part
 *                                   of the version, so changing it redraws the agreement and the PDF, consent and code
 *                                   are asked for again. Where no pack asks it, the answer is 'na' and this step is
 *                                   skipped.
 *   1. open the PDF preview         records pdf_opened (the PDF's SHA-256) for that version
 *   2. consent, which sends the code  refused until the PDF was opened; one box and one press record consent_given and
 *                                   email the code in the same request. If the code can't be sent (wait, limit,
 *                                   paused, locked, the email), the consent stays recorded, the page says why, and
 *                                   offers "Email me a new code".
 *   3. sign, with the code          the code is typed in the Sign form and checked first (ZAGR_Codes::check: 6 digits,
 *                                   10 minutes, 5 tries; code_ok or code_failed recorded); a wrong code signs nothing,
 *                                   and a session whose code was already accepted signs without one. Then one typed name
 *                                   (field sig), applied to both signatures, each with its own required tick (tick1 by
 *                                   the agreement's signature, tick2 by the acknowledgment of the right to cancel); the
 *                                   SHA-256 of the words on the page must equal the server's for today; then signed,
 *                                   notice_acknowledged, the signed PDF (stored, its SHA-256 recorded), the signed state
 *                                   saved, and only then the copy emailed. When the page comes back with an error, the
 *                                   name typed is put back in the field; meanwhile it is kept only sealed, for 10
 *                                   minutes, and read once (keep_name, kept_name).
 *   copy received                   only when wp_mail took the email with its PDF, or the customer opened the signed PDF
 *                                   on the page: the window counts from that day, never from an email that failed. When
 *                                   that day is later than the signing day, the filled-in copy is made again for it, so
 *                                   the PDF, the page, the email and the record give one deadline.
 *   after signing                   the signed PDF and Cancel need a fresh code (purpose "manage", 15 minutes); the just-
 *                                   signed session may open its copy for 5 minutes without one, and "Done" ends that.
 *   a change order                  after signing, the code comes first (the link alone shows no name or address), then
 *                                   the PDF, one consent box, and one typed name with its tick; no new window.
 *
 * Each step returns a message key (ZAGR_Strings, "m_…"), kept in the session as a one-time message. Every event a
 * customer's step writes names its session, and the signed copy prints only the signing session's steps.
 *
 * @package Zorderz\Agreements
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZAGR_Flow {

	const VERIFY_TTL = 1800; // a code for signing stays good for this long after it is accepted
	const MANAGE_TTL = 900;  // the signed copy and Cancel, after a fresh code
	const COPY_TTL   = 300;  // the just-signed session sees its copy without a new code

	/* ---------------------------------------------------------------- what is being signed now */

	/**
	 * { kind: agreement | change, for, purpose, co, age } or null when nothing is waiting for the customer's signature.
	 * For the agreement, $sess's answer to the age question is part of what is signed: until it is answered, "for"
	 * is '' and nothing can be done but answer it.
	 */
	public static function target( array $row, ?array $sess = null ): ?array {
		if ( 'ready' === $row['state'] ) {
			$age = ! self::asks_age( $row ) ? 'na' : ( null === $sess ? '' : self::age_of( $sess ) );
			$for = '' === $age ? '' : 'agr:' . $row['doc_hash'] . ':' . self::age_tag( (int) $row['id'], $age );
			return array( 'kind' => 'agreement', 'for' => $for, 'purpose' => 'sign', 'co' => null, 'age' => $age );
		}
		if ( 'change_pending' === $row['state'] ) {
			$c = ZAGR_Store::pending_change( (int) $row['id'] );
			if ( $c && 'ready' === $c['state'] ) {
				return array( 'kind' => 'change', 'for' => 'co:' . $c['id'] . ':' . $c['doc_hash'], 'purpose' => 'change', 'co' => $c, 'age' => '' );
			}
		}
		return null;
	}

	/* ---------------------------------------------------------------- 0. the age question */

	/** @var array agreement id => whether its legal packs ask the buyer's age, this request */
	private static $asks = array();

	/**
	 * Do this agreement's legal packs ask whether any buyer is 65 or older? When the frozen document can't be opened the
	 * answer is yes: the question is shown, never skipped on a guess.
	 */
	public static function asks_age( array $row ): bool {
		$id = (int) ( $row['id'] ?? 0 );
		if ( ! isset( self::$asks[ $id ] ) ) {
			$doc               = ZAGR_Store::document( $row );
			self::$asks[ $id ] = null === $doc ? true : ZAGR_Document::asks_age( $doc );
		}
		return self::$asks[ $id ];
	}

	/** The session's answer: 'yes', 'no', or '' (not answered, or no key to open it). */
	public static function age_of( array $sess ): string {
		if ( empty( $sess['id'] ) || '' === (string) ( $sess['age_sealed'] ?? '' ) ) {
			return '';
		}
		$a = ZAGR_Key::open( (string) $sess['age_sealed'], 'sess-age|' . (int) $sess['id'] );
		return in_array( $a, array( 'yes', 'no' ), true ) ? $a : '';
	}

	/** A tag for the answer that tells the two apart without saying which is which (a MAC under the key). */
	private static function age_tag( int $agreement_id, string $age ): string {
		return substr( (string) ZAGR_Key::mac( 'age-for|' . $agreement_id . '|' . $age ), 0, 12 );
	}

	/** The agreement's answer, as signed: 'yes' | 'no' | 'na' (not asked); '' before signing, or without the key. */
	public static function signed_age( array $row ): string {
		$a = ZAGR_Key::open( (string) ( $row['age_sealed'] ?? '' ), 'age|' . (int) $row['id'] );
		return in_array( $a, array( 'yes', 'no', 'na' ), true ) ? $a : '';
	}

	/** Business days to cancel the signed agreement, by its legal packs and the answers signed; 0 when unreadable. */
	public static function window_days( array $row ): int {
		$doc = ZAGR_Store::document( $row );
		$age = self::signed_age( $row );
		return null === $doc || '' === $age ? 0 : ZAGR_Document::window_days( $doc, $age );
	}

	/** The last day to cancel if the period starts on $from_ymd, by the answers signed; '' when unreadable. */
	public static function deadline_from( array $row, string $from_ymd ): string {
		$doc = ZAGR_Store::document( $row );
		$age = self::signed_age( $row );
		return null === $doc || '' === $age ? '' : ZAGR_Document::deadline( $doc, $from_ymd, $age );
	}

	/** "Is any buyer 65 or older?": 'yes' or 'no'. Changing it before signing redraws the agreement. */
	public static function answer_age( array $row, array $sess, array $post ): string {
		$t = self::target( $row, $sess );
		if ( ! $t || 'agreement' !== $t['kind'] || 'na' === $t['age'] ) {
			return 'm_not_now';
		}
		$age = (string) ( $post['age'] ?? '' );
		if ( ! in_array( $age, array( 'yes', 'no' ), true ) ) {
			return 'm_age_needed';
		}
		if ( $age === $t['age'] ) {
			return '';
		}
		$sid    = (int) $sess['id'];
		$sealed = ZAGR_Key::seal( $age, 'sess-age|' . $sid );
		$record = ZAGR_Key::seal( $age, 'age|' . (int) $row['id'] );
		if ( null === $sealed || null === $record ) {
			return 'm_busy';
		}
		ZAGR_Store::session_update( $sid, array( 'age_sealed' => $sealed ) );
		ZAGR_Events::append( (int) $row['id'], 'age_answered', array( 'for' => 'agr:' . $row['doc_hash'] . ':' . self::age_tag( (int) $row['id'], $age ), 'age_sealed' => $record ), 'customer', $sid );
		return '';
	}

	/** Does the code come first here? */
	public static function code_first( array $row ): bool {
		$t = self::target( $row );
		return null !== $t && 'change' === $t['kind'];
	}

	public static function url( array $row ): string {
		$w = ZAGR_Store::words( $row );
		return null === $w ? '' : ZAGR_Words::url( $w );
	}

	/**
	 * The words for a target on $day: { doc, render, old, number }. Null when the document doesn't open (no key).
	 * @throws ZAGR_Template_Error
	 */
	public static function render( array $row, array $t, string $day ): ?array {
		if ( 'agreement' === $t['kind'] ) {
			$doc = ZAGR_Store::document( $row );
			if ( ! $doc ) {
				return null;
			}
			$r = ZAGR_Document::agreement( $doc, array(
				'sign_date' => $day, 'age' => $t['age'], 'contractor_uid' => (int) $row['contractor_user_id'],
				'contractor_signed_at' => (string) $row['contractor_signed_at'], 'url' => self::url( $row ),
			) );
			return array( 'doc' => $doc, 'render' => $r, 'old' => null, 'number' => (string) $doc['document']['number'] );
		}
		$c   = $t['co'];
		$doc = ZAGR_Store::change_document( $row, $c );
		$old = ZAGR_Store::signed_current( $row )[1];
		if ( ! $doc || ! $old ) {
			return null;
		}
		$r = ZAGR_Document::change( $doc, array(
			'sign_date' => $day, 'cancel_by' => substr( (string) $row['cancel_by'], 0, 10 ), 'contractor_uid' => (int) $c['contractor_user_id'],
			'contractor_signed_at' => (string) $c['contractor_signed_at'], 'url' => self::url( $row ), 'old' => $old,
		) );
		return array( 'doc' => $doc, 'render' => $r, 'old' => $old, 'number' => (string) $doc['document']['number'] );
	}

	private static function title( string $number, string $kind ): string {
		return 'change' === $kind ? 'Change order, estimate #' . $number : 'Agreement, estimate #' . $number;
	}

	private static function footer( array $doc ): string {
		$c = trim( (string) ( $doc['facts']['company_name'] ?? '' ) );
		return ( '' !== $c ? $c . ' - ' : '' ) . 'Estimate #' . $doc['document']['number'] . ' - Template ' . $doc['facts']['template_version'];
	}

	private static function file_name( string $number, string $what ): string {
		return ucfirst( $what ) . '-' . preg_replace( '/[^A-Za-z0-9_-]/', '', $number ) . '.pdf';
	}

	/** The estimate number as frozen in the agreement (the estimate id without the key). */
	public static function number( array $row ): string {
		$doc = ZAGR_Store::document( $row );
		return (string) ( $doc['document']['number'] ?? $row['estimate_id'] );
	}

	/** The contractor's legal name as frozen in this agreement. */
	public static function company( array $row ): string {
		$c = trim( (string) ( self::facts( $row )['company_name'] ?? '' ) );
		return '' !== $c ? $c : ZAGR_Strings::company();
	}

	/* ---------------------------------------------------------------- 1. the PDF preview */

	/** [ bytes, file name ] of the preview, recorded as opened for this session; null when nothing is waiting. */
	public static function open_preview( array $row, array $sess ): ?array {
		$t = self::target( $row, $sess );
		if ( ! $t || ! ZAGR_Key::usable() || '' === $t['for'] ) {
			return null; // nothing to open until the age question is answered
		}
		if ( 'change' === $t['kind'] && ! self::verified( $sess, 'change', $t['for'] ) ) {
			return null; // after signing, the change order's words (a name, an address) need the code first
		}
		$x = self::render( $row, $t, ZAGR_Clock::today() );
		if ( ! $x ) {
			return null;
		}
		$opts = array( 'title' => self::title( $x['number'], $t['kind'] ), 'footer' => self::footer( $x['doc'] ), 'preview' => true, 'test' => ! empty( $row['is_test'] ), 'number' => $x['number'] );
		$pdf  = 'agreement' === $t['kind'] ? ZAGR_PDF_Render::agreement( $x['render'], $opts ) : ZAGR_PDF_Render::change( $x['render'], $opts );
		$sha  = hash( 'sha256', $pdf );
		ZAGR_Store::session_update( (int) $sess['id'], array( 'pdf_for' => $t['for'], 'pdf_sha256' => $sha, 'pdf_opened_at' => ZAGR_Clock::utc() ) );
		ZAGR_Events::append( (int) $row['id'], 'pdf_opened', array( 'for' => $t['for'], 'pdf_sha256' => $sha, 'render_hash' => $x['render']['hash'] ), 'customer', (int) $sess['id'] );
		return array( $pdf, self::file_name( $x['number'], 'change' === $t['kind'] ? 'change-order-preview' : 'agreement-preview' ) );
	}

	/* ---------------------------------------------------------------- 2. consent */

	public static function consent( array $row, array $sess, array $post ): string {
		$t = self::target( $row, $sess );
		if ( ! $t ) {
			return 'm_not_now';
		}
		if ( '' === $t['for'] ) {
			return 'm_age_first';
		}
		if ( $t['for'] !== (string) $sess['pdf_for'] || empty( $sess['pdf_opened_at'] ) ) {
			return 'm_pdf_first';
		}
		// one box, whose words hold both of 1.0's statements (consent and "I have read")
		if ( '1' !== (string) ( $post['consent'] ?? '' ) ) {
			return 'm_consent_needed';
		}
		// pressed again for the version this session already agreed to: consent is recorded once;
		// while the code it emailed can still be used, the answer is that it was sent, and nothing new is issued
		$again = hash_equals( $t['for'], (string) $sess['consent_for'] );
		if ( $again && 'agreement' === $t['kind'] && self::code_waiting( $row, $sess, $t['for'] ) ) {
			return 'm_code_sent';
		}
		if ( ! $again ) {
			$x = self::render( $row, $t, ZAGR_Clock::today() );
			if ( ! $x ) {
				return 'm_busy';
			}
			$c     = ZAGR_Document::consent( $x['doc'], array( 'sign_date' => ZAGR_Clock::today(), 'age' => $t['age'], 'url' => self::url( $row ) ) );
			$label = ZAGR_Strings::t( 'change' === $t['kind'] ? 'consent_label_change' : 'consent_label' );
			if ( ! ZAGR_Events::append( (int) $row['id'], 'consent_given', array( 'for' => $t['for'], 'consent_sha256' => $c['hash'], 'template_version' => $c['version'], 'read_ticked' => true, 'one_box' => true, 'label_sha256' => hash( 'sha256', $label ) ), 'customer', (int) $sess['id'] ) ) {
				return 'm_busy';
			}
			ZAGR_Store::session_update( (int) $sess['id'], array( 'consent_for' => $t['for'], 'consent_at' => ZAGR_Clock::utc() ) );
		}
		if ( 'agreement' !== $t['kind'] ) {
			return '';   // a change order's code came first
		}
		// the same press emails the code; if it can't go, consent stays recorded
		// and the message says why (the page offers "Email me a new code")
		$sess['consent_for'] = $t['for'];
		return self::send_code( $row, $sess, 'sign' );
	}

	/* ---------------------------------------------------------------- 3. the code */

	/** What a code of $purpose is bound to for this agreement, or '' when no such code makes sense now. */
	/**
	 * Has this session a signing code for $for that went out by email and can still be used (its
	 * newest code: unused, unexpired, tries left, and code_sent recorded for it)? Only reads; issues and spends nothing.
	 */
	private static function code_waiting( array $row, array $sess, string $for ): bool {
		global $wpdb;
		$sid = (int) $sess['id'];
		$c   = $wpdb->get_row( $wpdb->prepare( 'SELECT created_at, bound_for, used_at, expires_at, tries FROM ' . ZAGR_Install::t( 'codes' ) . ' WHERE session_id = %d AND agreement_id = %d AND purpose = %s ORDER BY id DESC LIMIT 1', $sid, (int) $row['id'], 'sign' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name (ZAGR_Install::tables()); every value is a placeholder
		if ( ! $c || null !== $c['used_at'] || (int) $c['tries'] >= ZAGR_Codes::TRIES || ZAGR_Clock::ts( (string) $c['expires_at'] ) < ZAGR_Clock::now()
			|| ! hash_equals( substr( $for, 0, 100 ), (string) $c['bound_for'] ) ) {
			return false;
		}
		foreach ( ZAGR_Events::of_type( (int) $row['id'], 'code_sent' ) as $e ) {
			$d = json_decode( (string) $e['detail'], true );
			if ( is_array( $d ) && $sid === (int) ( $d['sess'] ?? 0 ) && 'sign' === ( $d['purpose'] ?? '' ) && $for === ( $d['for'] ?? '' )
				&& ZAGR_Clock::ts( (string) $e['at_utc'] ) >= ZAGR_Clock::ts( (string) $c['created_at'] ) ) {
				return true;
			}
		}
		return false;
	}

	public static function bound( array $row, string $purpose, ?array $sess = null ): string {
		if ( 'manage' === $purpose ) {
			return empty( $row['signed_at'] ) ? '' : 'manage:' . (int) $row['id'];
		}
		$t = self::target( $row, $sess );
		return ( $t && $t['purpose'] === $purpose ) ? $t['for'] : '';
	}

	/** The message for codes held back: with the ways to cancel that need no code when the purpose is "manage". */
	/** Why a code was refused, as the page says it; after signing (manage), always with how to cancel without one. */
	private static function held( string $why, string $purpose ): string {
		$m = 'manage' === $purpose;
		return array(
			'wait'   => $m ? 'm_manage_wait' : 'm_code_wait',
			'limit'  => $m ? 'm_manage_limit' : 'm_code_limit',
			'locked' => $m ? 'm_manage_locked' : 'm_code_locked',
			'paused' => $m ? 'm_manage_paused' : 'm_code_paused',
			'mail'   => $m ? 'm_manage_mail' : 'm_code_mail',
		)[ $why ] ?? ( $m ? 'm_manage_busy' : 'm_busy' );
	}

	/** Why there is no code to ask for: the age question first, or nothing to do. */
	private static function no_target( array $row, array $sess, string $purpose ): string {
		$t = 'sign' === $purpose ? self::target( $row, $sess ) : null;
		return ( $t && 'agreement' === $t['kind'] && '' === $t['for'] ) ? 'm_age_first' : 'm_not_now';
	}

	public static function send_code( array $row, array $sess, string $purpose ): string {
		$for = self::bound( $row, $purpose, $sess );
		if ( '' === $for ) {
			return self::no_target( $row, $sess, $purpose );
		}
		// before signing, consent comes first; a change order's code comes first of all, and "manage" needs none
		if ( 'sign' === $purpose && $for !== (string) $sess['consent_for'] ) {
			return 'm_consent_first';
		}
		$to = ZAGR_Store::email( $row );
		if ( null === $to || ! is_email( $to ) ) {
			return self::held( 'busy', $purpose );
		}
		$sid = (int) $sess['id'];
		$c   = ZAGR_Codes::issue( (int) $row['id'], $sid, $purpose, $for );
		if ( ! $c['ok'] ) {
			return self::held( (string) $c['why'], $purpose );
		}
		$facts = self::facts( $row );
		if ( ! ZAGR_Mailer::code( $to, $c['plain'], $facts ) ) {
			ZAGR_Events::append( (int) $row['id'], 'email_failed', array( 'what' => 'code', 'purpose' => $purpose ), 'system', $sid );
			return self::held( 'mail', $purpose );
		}
		// which address the code went to, as a fingerprint
		ZAGR_Events::append( (int) $row['id'], 'code_sent', array( 'purpose' => $purpose, 'for' => $for, 'to' => ZAGR_People::email_fp( $to ) ), 'customer', $sid );
		return 'm_code_sent';
	}

	public static function verify_code( array $row, array $sess, string $purpose, string $typed ): string {
		$for = self::bound( $row, $purpose, $sess );
		if ( '' === $for ) {
			return self::no_target( $row, $sess, $purpose );
		}
		$sid = (int) $sess['id'];
		$r   = ZAGR_Codes::check( (int) $row['id'], $sid, $purpose, $for, $typed );
		if ( 'ok' === $r ) {
			ZAGR_Events::append( (int) $row['id'], 'code_ok', array( 'purpose' => $purpose, 'for' => $for ), 'customer', $sid );
			ZAGR_Store::session_update( $sid, array( 'verified_purpose' => $purpose, 'verified_for' => $for, 'verified_at' => ZAGR_Clock::utc() ) );
			return 'manage' === $purpose ? 'm_manage_ok' : 'm_code_ok';
		}
		if ( 'locked' === $r || 'paused' === $r ) {
			return self::held( $r, $purpose );
		}
		ZAGR_Events::append( (int) $row['id'], 'code_failed', array( 'purpose' => $purpose, 'why' => $r ), 'customer', $sid );
		return array( 'wrong' => 'm_code_wrong', 'expired' => 'm_code_expired', 'tries' => 'm_code_tries' )[ $r ] ?? 'm_code_first';
	}

	/** Is this session's code for $purpose accepted, for $for, and fresh? */
	public static function verified( array $sess, string $purpose, string $for ): bool {
		$ttl = 'manage' === $purpose ? self::MANAGE_TTL : self::VERIFY_TTL;
		return $purpose === (string) $sess['verified_purpose'] && '' !== $for && hash_equals( $for, (string) $sess['verified_for'] )
			&& ZAGR_Clock::ts( (string) $sess['verified_at'] ) >= ZAGR_Clock::now() - $ttl;
	}

	/* ---------------------------------------------------------------- 4. signing */

	public static function clean_name( $s ): string {
		$s = ZAGR_Settings::clean( (string) $s, 200 );
		return (string) preg_replace( '/\s+/u', ' ', $s );
	}

	/** A typed name: 2 to 120 letters, spaces and the marks names use. */
	public static function valid_name( string $s ): bool {
		return (bool) preg_match( "/^[\\p{L}\\p{M}][\\p{L}\\p{M} .,'\x{2019}-]{1,119}$/u", $s );
	}

	public static function facts( array $row ): array {
		$doc = ZAGR_Store::document( $row );
		$f   = $doc['facts'] ?? array();
		$set = ZAGR_Settings::facts();
		return array(
			'company_name'  => (string) ( $f['company_name'] ?? $set['legal_name'] ),
			'address_lines' => (array) ( $f['address_lines'] ?? $set['address_lines'] ),
			'license'       => (string) ( $f['license'] ?? $set['license'] ),
			'cancel_email'  => (string) ( ( $f['cancel_email'] ?? '' ) ?: $set['cancel_email'] ),
			'help_phone'    => (string) ( ( $f['help_phone'] ?? '' ) ?: $set['help_phone'] ),
			'packs'         => array_values( (array) ( $doc['packs'] ?? array() ) ),
		);
	}

	/**
	 * The steps the signed copy prints: the agreement's own (prepared, signed for the contractor, changes)
	 * and the signing session's. Steps of other visitors stay in the full record, not in the customer's copy.
	 */
	public static function steps_for( int $agreement_id, int $session ): array {
		return array_values( array_filter( ZAGR_Events::all( $agreement_id ), function ( $e ) use ( $session ) {
			$d = json_decode( (string) $e['detail'], true );
			$s = is_array( $d ) && isset( $d['sess'] ) ? (int) $d['sess'] : 0;
			return $s > 0 ? $s === $session : 'customer' !== $e['actor'];
		} ) );
	}

	/** What the change order's signature page says (built at the moment of signing). */
	private static function sig_facts( array $row, array $x, string $kind, string $n1, string $n2, string $today, string $cancel_by, int $contractor_uid, string $contractor_at, int $session ): array {
		$email = (string) ZAGR_Store::email( $row );
		return array(
			'kind'                 => $kind,
			'agreement_name'       => $n1,
			'signed_at_long'       => ZAGR_Clock::local_long( ZAGR_Clock::utc() ),
			'signed_date_long'     => ZAGR_Calendar::long_date( $today ),
			'notice_name'          => $n2,
			'notice_date_long'     => ZAGR_Calendar::long_date( $today ),
			'contractor_name'      => ZAGR_People::name( $contractor_uid ) ?: self::company( $row ),
			'company'              => self::company( $row ),
			'copy_hash'            => (string) ( $x['copy']['hash'] ?? '' ),
			'contractor_date_long' => '' !== $contractor_at ? ZAGR_Calendar::long_date( ZAGR_Clock::local_day( ZAGR_Clock::ts( $contractor_at ) ) ) : '',
			'render_hash'          => $x['render']['hash'],
			'template_version'     => (string) $x['doc']['facts']['template_version'],
			'plan_version'         => (string) $x['doc']['schedule']['plan_version'],
			'cancel_by_long'       => '' !== $cancel_by ? ZAGR_Calendar::long_date( $cancel_by ) : '',
			'email_masked'         => ZAGR_People::mask_email( $email ),
			'ip'                   => ZAGR_People::request_ip(),
			'device'               => ZAGR_People::request_ua(),
			'events'               => self::steps_for( (int) $row['id'], $session ),
			'head'                 => ZAGR_Events::head( (int) $row['id'] ),
			'estimate_number'      => $x['number'],
		);
	}

	/**
	 * The completely filled-in signed copy for a deadline, built from the record alone: the typed names,
	 * the answer to the age question, and the IP and device of the signing request (all sealed in its "signed" and
	 * "notice_acknowledged" events), the signing session's steps, and the record's fingerprint now. The one way a signed
	 * copy is made: at signing, and again when the copy is received on a later day.
	 * Returns [ pdf bytes, SHA-256, the copy's words' hash ]. @throws RuntimeException
	 */
	private static function build_copy( array $row, string $cancel_by ): array {
		$id  = (int) $row['id'];
		$doc = ZAGR_Store::document( $row );
		$sev = ZAGR_Events::of_type( $id, 'signed' );
		$ack = ZAGR_Events::of_type( $id, 'notice_acknowledged' );
		if ( ! $doc || ! $sev || ! $ack ) {
			throw new RuntimeException( 'record' );
		}
		$sev = end( $sev );
		$ack = end( $ack );
		$sd  = (array) json_decode( (string) $sev['detail'], true );
		$ad  = (array) json_decode( (string) $ack['detail'], true );
		$n1  = ZAGR_Key::open( (string) ( $sd['typed_name_sealed'] ?? '' ), 'sig1|' . $id );
		$n2  = ZAGR_Key::open( (string) ( $ad['typed_name_sealed'] ?? '' ), 'sig2|' . $id );
		$age = ZAGR_Key::open( (string) ( $sd['age_sealed'] ?? '' ), 'age|' . $id );
		if ( null === $n1 || null === $n2 || ! in_array( $age, array( 'yes', 'no', 'na' ), true ) ) {
			throw new RuntimeException( 'seal' );
		}
		$day    = (string) ( $ad['date'] ?? '' );
		$number = (string) $doc['document']['number'];
		$copy   = ZAGR_Document::agreement( $doc, array(
			'sign_date' => $day, 'signed' => true, 'cancel_by' => $cancel_by, 'age' => $age, 'contractor_uid' => (int) $row['contractor_user_id'],
			'contractor_signed_at' => (string) $row['contractor_signed_at'], 'url' => self::url( $row ),
		) );
		$ip    = '' === (string) $sev['ip_sealed'] ? '' : (string) ZAGR_Key::open( (string) $sev['ip_sealed'], 'event-ip|' . $id );
		$ua    = '' === (string) $sev['ua_sealed'] ? '' : (string) ZAGR_Key::open( (string) $sev['ua_sealed'], 'event-ua|' . $id );
		$days  = ZAGR_Document::window_days( $doc, $age );
		$facts = array(
			'kind'                 => 'agreement',
			'agreement_name'       => $n1,
			'signed_at_long'       => ZAGR_Clock::local_long( substr( (string) $sev['at_utc'], 0, 19 ) ),
			'signed_date_long'     => ZAGR_Calendar::long_date( $day ),
			'notice_name'          => $n2,
			'notice_date_long'     => ZAGR_Calendar::long_date( $day ),
			'contractor_name'      => ZAGR_People::name( (int) $row['contractor_user_id'] ) ?: self::company( $row ),
			'company'              => self::company( $row ),
			'copy_hash'            => $copy['hash'],
			'contractor_date_long' => '' !== (string) $row['contractor_signed_at'] ? ZAGR_Calendar::long_date( ZAGR_Clock::local_day( ZAGR_Clock::ts( (string) $row['contractor_signed_at'] ) ) ) : '',
			'render_hash'          => (string) ( $sd['render_hash'] ?? '' ),
			'template_version'     => (string) $doc['facts']['template_version'],
			'plan_version'         => (string) $doc['schedule']['plan_version'],
			'cancel_by_long'       => ZAGR_Calendar::long_date( $cancel_by ),
			'age_line'             => 'na' === $age ? '' : ZAGR_Strings::t( 'yes' === $age ? 'pdf_age_yes' : 'pdf_age_no', array( 'days' => $days ) ),
			'window_line'          => ZAGR_Strings::t( 'pdf_window_days', array( 'days' => $days ) ),
			'pack_label'           => ZAGR_Pack::label( ZAGR_Document::stack( $doc ) ),
			'one_name'             => ! empty( $sd['one_name'] ) && ! empty( $ad['one_name'] ),   // as the record says
			'email_masked'         => ZAGR_People::mask_email( (string) ZAGR_Store::email( $row ) ),
			'ip'                   => $ip,
			'device'               => $ua,
			'events'               => self::steps_for( $id, (int) ( $sd['sess'] ?? 0 ) ),
			'head'                 => ZAGR_Events::head( $id ),
			'estimate_number'      => $number,
		);
		$pdf = ZAGR_PDF_Render::agreement( $copy, array( 'title' => self::title( $number, 'agreement' ), 'footer' => self::footer( $doc ), 'test' => ! empty( $row['is_test'] ), 'sign' => $facts, 'number' => $number ) );
		return array( $pdf, hash( 'sha256', $pdf ), $copy['hash'] );
	}

	public static function sign( array $row, array $sess, array $post ): string {
		$t = self::target( $row, $sess );
		if ( ! $t || 'agreement' !== $t['kind'] ) {
			return 'm_not_now';
		}
		if ( '' === $t['for'] ) {
			return 'm_age_first';
		}
		if ( $t['for'] !== (string) $sess['pdf_for'] || empty( $sess['pdf_opened_at'] ) ) {
			return 'm_pdf_first';
		}
		if ( $t['for'] !== (string) $sess['consent_for'] ) {
			return 'm_consent_first';
		}
		// the code typed in the Sign form is checked first, a try spent as ever;
		// a wrong code signs nothing and is recorded as before. A session whose code was accepted needs none.
		if ( ! self::verified( $sess, 'sign', $t['for'] ) ) {
			$typed = (string) ( $post['code'] ?? '' );
			if ( '' === trim( $typed ) ) {
				self::keep_name( $sess, $post );
				return 'm_code_type';
			}
			$m = self::verify_code( $row, $sess, 'sign', $typed );
			if ( 'm_code_ok' !== $m ) {
				self::keep_name( $sess, $post );
				return $m;
			}
			$sess = ZAGR_Store::session_by_id( (int) $sess['id'] ) ?? $sess;
			if ( ! self::verified( $sess, 'sign', $t['for'] ) ) {
				return 'm_code_first';
			}
		}
		// one typed name for both signatures, and a tick beside each statement
		$n1 = self::clean_name( $post['sig'] ?? '' );
		$n2 = $n1;
		if ( ! self::valid_name( $n1 ) || '1' !== (string) ( $post['tick1'] ?? '' ) || '1' !== (string) ( $post['tick2'] ?? '' ) ) {
			self::keep_name( $sess, $post );
			return 'm_names_needed';
		}
		$today = ZAGR_Clock::today();
		$x     = self::render( $row, $t, $today );
		if ( ! $x ) {
			self::keep_name( $sess, $post );   // the name comes back after this error too
			return 'm_busy';
		}
		$sent = strtolower( (string) ( $post['doc_sha'] ?? '' ) );
		if ( ! preg_match( '/^[0-9a-f]{64}$/', $sent ) || ! hash_equals( $x['render']['hash'], $sent ) || (string) ( $post['sign_date'] ?? '' ) !== $today ) {
			self::keep_name( $sess, $post );
			return 'm_changed';
		}
		$id  = (int) $row['id'];
		$sid = (int) $sess['id'];
		// claim the agreement: only one request signs, and only the version that was read
		global $wpdb;
		$n = (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . ZAGR_Install::t( 'agreements' ) . " SET state = 'signing', updated_at = %s WHERE id = %d AND state = 'ready' AND doc_hash = %s", ZAGR_Clock::utc(), $id, (string) $row['doc_hash'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name (ZAGR_Install::tables()); every value is a placeholder
		if ( 1 !== $n ) {
			self::keep_name( $sess, $post );
			return 'm_changed';
		}
		try {
			$s1 = ZAGR_Key::seal( $n1, 'sig1|' . $id );
			$s2 = ZAGR_Key::seal( $n2, 'sig2|' . $id );
			$sa = ZAGR_Key::seal( $t['age'], 'age|' . $id );   // sealed with the signer's details
			if ( null === $s1 || null === $s2 || null === $sa ) {
				throw new RuntimeException( 'seal' );
			}
			$sched = $x['doc']['schedule'];
			if ( ! ZAGR_Events::append( $id, 'signed', array(
				'typed_name_sealed' => $s1, 'age_sealed' => $sa, 'render_hash' => $x['render']['hash'], 'doc_hash' => (string) $row['doc_hash'],
				'template_version' => (string) $x['doc']['facts']['template_version'], 'template_files_sha256' => $x['render']['template_files'],
				'plan_version' => (string) $sched['plan_version'], 'schedule' => $sched, 'consent_for' => $t['for'],
				// the statement ticked, and the one typed name applied to it and to the notice
				'ticked' => ZAGR_Strings::t( 'tick_agreement' ), 'one_name' => 'applied to the agreement and to the notice',
			), 'customer', $sid ) ) {
				throw new RuntimeException( 'event' );
			}
			if ( ! ZAGR_Events::append( $id, 'notice_acknowledged', array(
				'typed_name_sealed' => $s2, 'date' => $today, 'notice_sha256' => hash( 'sha256', $x['render']['sections']['sign-notice'] ),
				'ticked' => ZAGR_Strings::t( 'tick_notice' ), 'one_name' => 'applied to the agreement and to the notice',
			), 'customer', $sid ) ) {
				throw new RuntimeException( 'event' );
			}
			// the date the copy prints: the window if the copy is received today (by email now, or opened on the page);
			// received later, the copy is made again for that day
			$cancel_by = ZAGR_Document::deadline( $x['doc'], $today, $t['age'] );
			if ( '' === $cancel_by ) {
				throw new RuntimeException( 'window' );
			}
			list( $pdf, $sha, $copy_hash ) = self::build_copy( $row, $cancel_by );
			$file = ZAGR_Storage::put( $pdf, 'agr|' . $id );
			if ( '' === $file ) {
				throw new RuntimeException( 'store' );
			}
			$vault = ZAGR_Storage::to_vault( $pdf, 'Signed agreement, estimate #' . $x['number'], (int) $row['client_id'], $x['number'], (int) $row['contractor_user_id'], 'agreement-' . $x['number'] . '.pdf' );
			ZAGR_Events::append( $id, 'pdf_sealed', array( 'pdf_sha256' => $sha, 'copy_render_sha256' => $copy_hash, 'cancel_by' => $cancel_by, 'own_copy' => true, 'vault_doc_id' => $vault, 'second_copy' => ZAGR_Storage::second_copy( (int) $vault ) ), 'system' );
			// the signed state is saved before anything is emailed, so a request that dies after the email can never
			// put the agreement back to "ready" while the customer holds a signed copy
			$done = ZAGR_Store::update( $id, array(
				'state' => 'signed', 'signed_at' => ZAGR_Clock::utc(), 'render_hash' => $x['render']['hash'], 'age_sealed' => $sa, 'pdf_sha256' => $sha,
				'pdf_file' => $file, 'pdf_cancel_by' => $cancel_by, 'vault_doc_id' => $vault,
			), array( 'signing' ) );
			if ( 1 !== $done ) {
				throw new RuntimeException( 'state' );
			}
		} catch ( \Throwable $e ) {
			ZAGR_Events::append( $id, 'sign_failed', array( 'why' => $e instanceof RuntimeException ? $e->getMessage() : 'error' ), 'system', $sid );
			ZAGR_Store::update( $id, array( 'state' => 'ready' ), array( 'signing' ) );
			return 'm_sign_failed';
		}
		ZAGR_Store::session_update( $sid, array( 'copy_until' => ZAGR_Clock::utc( ZAGR_Clock::now() + self::COPY_TTL ), 'verified_purpose' => '', 'verified_for' => '', 'verified_at' => null ) );
		self::forget_name( $sid );   // signed; a name kept from an earlier error has no use now
		$row = ZAGR_Store::by_id( $id ) ?? $row;
		try {
			$mailed = ZAGR_Mailer::signed_copy( (string) ZAGR_Store::email( $row ), $pdf, self::file_name( $x['number'], 'agreement' ), self::facts( $row ), ZAGR_Clock::local_long( (string) $row['signed_at'] ), ZAGR_Calendar::long_date( $cancel_by ), self::url( $row ) );
		} catch ( \Throwable $e ) {
			$mailed = false;
		}
		if ( ! $mailed ) {
			// never "emailed" when it wasn't; the window waits until the customer opens the copy (an email that
			// would have gone without its PDF is not sent at all)
			ZAGR_Events::append( $id, 'email_failed', array( 'what' => 'signed_copy' ), 'system', $sid );
			return 'm_signed_nomail';
		}
		// the email went in this request, with the copy made for today, so it is received with that
		// copy's deadline, even when the mail server took it just after midnight; the page never says "on its way"
		// unless that was recorded
		return self::mark_received( $row, 'email', 0, $cancel_by ) ? 'm_signed' : 'm_signed_unrecorded';
	}

	/** The deadline if the signed copy is received today. '' when the answer can't be read (no key). */
	private static function deadline_today( array $row ): string {
		return self::deadline_from( $row, ZAGR_Clock::today() );
	}

	/**
	 * make the stored copy print $cancel_by, if it doesn't already. The new copy is stored (and filed to the
	 * private store), its SHA-256 recorded (copy_reissued), and it replaces the old one on the agreement. Returns the row as it
	 * now stands, or null when no copy for that date could be made.
	 */
	private static function copy_for( array $row, string $cancel_by, string $why ): ?array {
		// only while the copy can still be received; a cancelled or closed agreement keeps the copy it has
		if ( (string) $row['pdf_cancel_by'] === $cancel_by || ! in_array( (string) $row['state'], array( 'signed', 'change_pending' ), true ) ) {
			return $row;
		}
		$id = (int) $row['id'];
		try {
			list( $pdf, $sha ) = self::build_copy( $row, $cancel_by );
		} catch ( \Throwable $e ) {
			return null;
		}
		$file = ZAGR_Storage::put( $pdf, 'agr|' . $id );
		if ( '' === $file ) {
			return null;
		}
		global $wpdb;
		$n = (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . ZAGR_Install::t( 'agreements' ) . ' SET pdf_sha256 = %s, pdf_file = %s, pdf_cancel_by = %s, updated_at = %s WHERE id = %d AND pdf_sha256 = %s AND copy_received_at IS NULL', $sha, $file, $cancel_by, ZAGR_Clock::utc(), $id, (string) $row['pdf_sha256'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name (ZAGR_Install::tables()); every value is a placeholder
		if ( 1 !== $n ) {
			return ZAGR_Store::by_id( $id );   // another request made it (or the copy was received meanwhile)
		}
		$number = self::number( $row );
		$vault  = ZAGR_Storage::to_vault( $pdf, 'Signed agreement, estimate #' . $number . ' (copy received ' . ZAGR_Clock::today() . ')', (int) $row['client_id'], $number, (int) $row['contractor_user_id'], 'agreement-' . $number . '-' . ZAGR_Clock::today() . '.pdf' );
		if ( $vault ) {
			ZAGR_Store::update( $id, array( 'vault_doc_id' => $vault ) );
		}
		ZAGR_Events::append( $id, 'copy_reissued', array( 'why' => $why, 'old_pdf_sha256' => (string) $row['pdf_sha256'], 'pdf_sha256' => $sha, 'cancel_by' => $cancel_by, 'own_copy' => true, 'vault_doc_id' => $vault, 'second_copy' => ZAGR_Storage::second_copy( (int) $vault ) ), 'system' );
		return ZAGR_Store::by_id( $id );
	}

	/**
	 * "Copy received": only when wp_mail took the email with its PDF ($via email), or the customer opened
	 * the signed PDF on the page ($via opened). The window counts from that day, and the copy received prints it.
	 * Once only; false when it was already.
	 */
	private static function mark_received( array $row, string $via, int $session, string $cancel_by ): bool {
		global $wpdb;
		$id = (int) $row['id'];
		// $cancel_by: the deadline the copy just delivered prints
		if ( '' === $cancel_by || (string) $row['pdf_cancel_by'] !== $cancel_by ) {
			return false;   // never a deadline the customer's copy doesn't print
		}
		$now = ZAGR_Clock::utc();
		$n   = (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . ZAGR_Install::t( 'agreements' ) . " SET copy_received_at = %s, copy_via = %s, cancel_by = %s, updated_at = %s WHERE id = %d AND copy_received_at IS NULL AND signed_at IS NOT NULL AND state IN ('signed','change_pending') AND pdf_sha256 = %s", $now, $via, $cancel_by, $now, $id, (string) $row['pdf_sha256'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name (ZAGR_Install::tables()); every value is a placeholder
		if ( 1 !== $n ) {
			return false;
		}
		// the buyer may cancel until midnight of the last business day the legal packs give after receiving the copy
		ZAGR_Events::append( $id, 'copy_received', array( 'via' => $via, 'cancel_by' => $cancel_by, 'pdf_sha256' => (string) $row['pdf_sha256'] ), 'opened' === $via ? 'customer' : 'system', $session );
		ZAGR_Alerts::signed( $id );   // staff hear it now the cancel-by day is known (never in the way: all caught)
		return true;
	}

	/** the customer signs a change order (one signature, no new window). */
	public static function sign_change( array $row, array $sess, array $post ): string {
		$t = self::target( $row );
		if ( ! $t || 'change' !== $t['kind'] ) {
			return 'm_not_now';
		}
		if ( ! self::verified( $sess, 'change', $t['for'] ) ) {
			return 'm_code_first';
		}
		if ( $t['for'] !== (string) $sess['pdf_for'] || empty( $sess['pdf_opened_at'] ) ) {
			return 'm_pdf_first';
		}
		if ( $t['for'] !== (string) $sess['consent_for'] ) {
			return 'm_consent_first';
		}
		$n1 = self::clean_name( $post['sig'] ?? '' );   // the same field as the agreement's one typed name
		if ( ! self::valid_name( $n1 ) || '1' !== (string) ( $post['tick1'] ?? '' ) ) {
			self::keep_name( $sess, $post );
			return 'm_name_needed';
		}
		$today = ZAGR_Clock::today();
		$x     = self::render( $row, $t, $today );
		if ( ! $x ) {
			return 'm_busy';
		}
		$sent = strtolower( (string) ( $post['doc_sha'] ?? '' ) );
		if ( ! preg_match( '/^[0-9a-f]{64}$/', $sent ) || ! hash_equals( $x['render']['hash'], $sent ) || (string) ( $post['sign_date'] ?? '' ) !== $today ) {
			return 'm_changed';
		}
		$id  = (int) $row['id'];
		$sid = (int) $sess['id'];
		$c   = $t['co'];
		if ( 1 !== ZAGR_Store::update_change( (int) $c['id'], array( 'state' => 'signing' ), 'ready' ) ) {
			return 'm_changed';
		}
		try {
			$s1 = ZAGR_Key::seal( $n1, 'cosig|' . $id . '|' . (int) $c['seq'] );
			if ( null === $s1 ) {
				throw new RuntimeException( 'seal' );
			}
			if ( ! ZAGR_Events::append( $id, 'change_signed', array(
				'change' => (int) $c['seq'], 'typed_name_sealed' => $s1, 'render_hash' => $x['render']['hash'], 'doc_hash' => (string) $c['doc_hash'],
				'template_version' => (string) $x['doc']['facts']['template_version'], 'plan_version' => (string) $x['doc']['schedule']['plan_version'],
				'schedule' => $x['doc']['schedule'],
			), 'customer', $sid ) ) {
				throw new RuntimeException( 'event' );
			}
			$cancel_by = substr( (string) $row['cancel_by'], 0, 10 ); // the original window stands
			$x['copy'] = ZAGR_Document::change( $x['doc'], array(
				'sign_date' => $today, 'signed' => true, 'cancel_by' => $cancel_by, 'contractor_uid' => (int) $c['contractor_user_id'],
				'contractor_signed_at' => (string) $c['contractor_signed_at'], 'url' => self::url( $row ), 'old' => $x['old'],
			) );
			$facts = self::sig_facts( $row, $x, 'change', $n1, '', $today, $cancel_by, (int) $c['contractor_user_id'], (string) $c['contractor_signed_at'], $sid );
			$pdf   = ZAGR_PDF_Render::change( $x['copy'], array( 'title' => self::title( $x['number'], 'change' ), 'footer' => self::footer( $x['doc'] ), 'test' => ! empty( $row['is_test'] ), 'sign' => $facts ) );
			$sha   = hash( 'sha256', $pdf );
			$file  = ZAGR_Storage::put( $pdf, 'co|' . $id . '|' . (int) $c['seq'] );
			if ( '' === $file ) {
				throw new RuntimeException( 'store' );
			}
			$vault = ZAGR_Storage::to_vault( $pdf, 'Signed change order ' . (int) $c['seq'] . ', estimate #' . $x['number'], (int) $row['client_id'], $x['number'], (int) $c['contractor_user_id'], 'change-order-' . (int) $c['seq'] . '-' . $x['number'] . '.pdf' );
			ZAGR_Events::append( $id, 'pdf_sealed', array( 'change' => (int) $c['seq'], 'pdf_sha256' => $sha, 'own_copy' => true, 'vault_doc_id' => $vault, 'second_copy' => ZAGR_Storage::second_copy( (int) $vault ) ), 'system' );
			if ( 1 !== ZAGR_Store::update_change( (int) $c['id'], array( 'state' => 'signed', 'signed_at' => ZAGR_Clock::utc(), 'render_hash' => $x['render']['hash'], 'pdf_sha256' => $sha, 'pdf_file' => $file, 'vault_doc_id' => $vault ), 'signing' ) ) {
				throw new RuntimeException( 'state' );
			}
		} catch ( \Throwable $e ) {
			ZAGR_Events::append( $id, 'sign_failed', array( 'change' => (int) $c['seq'], 'why' => $e instanceof RuntimeException ? $e->getMessage() : 'error' ), 'system', $sid );
			ZAGR_Store::update_change( (int) $c['id'], array( 'state' => 'ready' ), 'signing' );
			return 'm_sign_failed';
		}
		$fresh = ZAGR_Store::by_id( $id ) ?? $row;
		ZAGR_Store::update( $id, array( 'state' => empty( $fresh['window_closed_at'] ) ? 'signed' : 'window_closed' ), array( 'change_pending' ) );
		ZAGR_Store::refresh_window( ZAGR_Store::by_id( $id ) ?? $fresh );
		ZAGR_Store::session_update( $sid, array( 'copy_until' => ZAGR_Clock::utc( ZAGR_Clock::now() + self::COPY_TTL ), 'verified_purpose' => '', 'verified_for' => '', 'verified_at' => null ) );
		// saved first, then emailed
		try {
			$mailed = ZAGR_Mailer::change_copy( (string) ZAGR_Store::email( $row ), $pdf, self::file_name( $x['number'], 'change-order-' . (int) $c['seq'] ), self::facts( $row ), $facts['signed_at_long'], self::url( $row ) );
		} catch ( \Throwable $e ) {
			$mailed = false;
		}
		if ( ! $mailed ) {
			ZAGR_Events::append( $id, 'email_failed', array( 'what' => 'change_copy', 'change' => (int) $c['seq'] ), 'system', $sid );
			return 'm_change_signed_nomail';
		}
		return 'm_change_signed';
	}

	/** how long a typed name waits, sealed, for the page to put it back after an error. */
	const NAME_TTL = 600;

	private static function name_key( array $sess ): string {
		return 'zagr_name_' . (int) $sess['id'];
	}

	/** Drop a session's kept name (signed, Done, or the session swept away). */
	public static function forget_name( int $session_id ): void {
		if ( $session_id > 0 ) {
			delete_transient( self::name_key( array( 'id' => $session_id ) ) );
		}
	}

	/**
	 * the page came back with an error (a wrong code, a box not ticked): keep the name the customer typed
	 * so the field shows it again. Kept only sealed under the key (never in the clear, never logged), for this session,
	 * for 10 minutes, and read once (kept_name()). Nothing is kept without the key.
	 */
	private static function keep_name( array $sess, array $post ): void {
		$n = self::clean_name( $post['sig'] ?? '' );
		if ( empty( $sess['id'] ) || '' === $n || ( function_exists( 'mb_strlen' ) ? mb_strlen( $n ) : strlen( $n ) ) > 120 ) {
			return;
		}
		$sealed = ZAGR_Key::seal( $n, 'sess-name|' . (int) $sess['id'] );
		if ( null !== $sealed ) {
			set_transient( self::name_key( $sess ), $sealed, self::NAME_TTL );
		}
	}

	/** the name kept for this session's page, once ('' when none). */
	public static function kept_name( array $sess ): string {
		if ( empty( $sess['id'] ) ) {
			return '';
		}
		$k = self::name_key( $sess );
		$s = get_transient( $k );
		if ( false === $s ) {
			return '';
		}
		delete_transient( $k );
		$n = ZAGR_Key::open( (string) $s, 'sess-name|' . (int) $sess['id'] );
		return null === $n ? '' : (string) $n;
	}

	/* ---------------------------------------------------------------- after signing */

	/** May this session open the signed copy now? (just signed, or a fresh manage code) */
	public static function may_open_copy( array $row, array $sess ): bool {
		if ( empty( $row['signed_at'] ) || empty( $sess['id'] ) || ZAGR_Paper::is_paper( $row ) ) {
			return false;   // signed on paper: no copy online (the customer's paper copy is the agreement)
		}
		if ( ZAGR_Clock::ts( (string) $sess['copy_until'] ) >= ZAGR_Clock::now() ) {
			return true;
		}
		return self::verified( $sess, 'manage', 'manage:' . (int) $row['id'] );
	}

	/**
	 * [ bytes, file name ] of the signed agreement ('agreement') or the last signed change order ('change'); or null.
	 * Opening the signed agreement is "copy received" when the email wasn't.
	 */
	public static function signed_pdf( array $row, array $sess, string $which ): ?array {
		if ( ! self::may_open_copy( $row, $sess ) || ( 'agreement' === $which && ZAGR_Paper::is_paper( $row ) ) ) {
			return null;   // never a PDF for a paper signing (may_open_copy() already says no for a paper row, so a change order signed online on one isn't opened here either)
		}
		$id = (int) $row['id'];
		if ( 'agreement' === $which && empty( $row['copy_received_at'] ) ) {
			// received today (not on the signing day): the copy opened is made for today's deadline first
			$fresh = self::copy_for( $row, self::deadline_today( $row ), 'opened' );
			if ( null === $fresh ) {
				return null;
			}
			$row = $fresh;
		}
		if ( 'change' === $which ) {
			$c = ZAGR_Store::last_signed_change( $id );
			if ( ! $c ) {
				return null;
			}
			$bytes = ZAGR_Storage::get( (string) $c['pdf_file'], 'co|' . $id . '|' . (int) $c['seq'] );
			$sha   = (string) $c['pdf_sha256'];
			$name  = 'Change-order-' . (int) $c['seq'] . '.pdf';
		} else {
			$bytes = ZAGR_Storage::get( (string) $row['pdf_file'], 'agr|' . $id );
			$sha   = (string) $row['pdf_sha256'];
			$name  = 'Agreement.pdf';
		}
		if ( null === $bytes || '' === $sha || ! hash_equals( $sha, hash( 'sha256', $bytes ) ) ) {
			return null; // never a copy that isn't the one recorded
		}
		ZAGR_Events::append( $id, 'signed_copy_opened', array( 'which' => $which ), 'customer', (int) $sess['id'] );
		if ( 'agreement' === $which && empty( $row['copy_received_at'] ) ) {
			self::mark_received( $row, 'opened', (int) $sess['id'], self::deadline_today( $row ) );
		}
		return array( $bytes, $name );
	}

	/** Has the window run out? Never before the copy was received (no cancel_by yet: the buyer may still cancel). */
	private static function window_over( array $row ): bool {
		$by = substr( (string) $row['cancel_by'], 0, 10 );
		return '' !== $by && ! ZAGR_Calendar::window_open( $by, ZAGR_Clock::now() );
	}

	/** cancel during the window, behind a fresh code. */
	public static function cancel( array $row, array $sess, array $post ): string {
		if ( 'window_closed' === $row['state'] ) {
			return 'm_window_over';
		}
		if ( ! in_array( $row['state'], array( 'signed', 'change_pending' ), true ) ) {
			return 'm_not_now';
		}
		if ( self::window_over( $row ) ) {
			return 'm_window_over';
		}
		if ( ! self::verified( $sess, 'manage', 'manage:' . (int) $row['id'] ) ) {
			return 'm_code_first';
		}
		if ( '1' !== (string) ( $post['cancel_tick'] ?? '' ) ) {
			return 'm_cancel_tick';
		}
		return self::do_cancel( $row, 'online', ZAGR_Clock::today(), 'customer', (int) $sess['id'] )['ok'] ? 'm_cancelled' : 'm_not_now';
	}

	/** { ok, mailed (both emails went) } */
	private static function do_cancel( array $row, string $how, string $sent_on, string $actor, int $session = 0 ): array {
		$id  = (int) $row['id'];
		$now = ZAGR_Clock::utc();
		if ( 1 !== ZAGR_Store::update( $id, array( 'state' => 'cancelled', 'cancelled_at' => $now, 'cancel_how' => $how ), array( 'signed', 'change_pending', 'window_closed' ) ) ) {
			return array( 'ok' => false, 'mailed' => false );
		}
		$p = ZAGR_Store::pending_change( $id );
		if ( $p ) {
			ZAGR_Store::update_change( (int) $p['id'], array( 'state' => 'superseded' ) );
		}
		ZAGR_Events::append( $id, 'cancelled', array( 'how' => $how, 'sent_on' => $sent_on, 'cancel_by' => substr( (string) $row['cancel_by'], 0, 10 ) ), $actor, $session );
		$number = self::number( $row );
		$facts  = self::facts( $row );
		$when   = ZAGR_Clock::local_long( $now );
		$mailed = true;
		// the record's head after the cancellation goes to the cancellations address: a copy outside the database
		if ( ! ZAGR_Mailer::cancel_staff( $facts, $number, (int) $row['client_id'], $when, $how, ! empty( $row['is_test'] ), ZAGR_Events::head( $id ) ) ) {
			ZAGR_Events::append( $id, 'email_failed', array( 'what' => 'cancel_staff' ), 'system' );
			$mailed = false;
		}
		$to = ZAGR_Store::email( $row );
		if ( null === $to || ! ZAGR_Mailer::cancel_buyer( $to, $facts, $number, $when ) ) {
			ZAGR_Events::append( $id, 'email_failed', array( 'what' => 'cancel_buyer' ), 'system' );
			$mailed = false;
		}
		ZAGR_Alerts::cancelled( $id );   // after the record and the emails; a failed alert changes nothing here
		return array( 'ok' => true, 'mailed' => $mailed );
	}

	/** "Done": the session gives up its copy and its codes; the next person at a shared device needs a code. */
	public static function done( array $row, array $sess ): string {
		ZAGR_Store::session_update( (int) $sess['id'], array( 'copy_until' => null, 'verified_purpose' => '', 'verified_for' => '', 'verified_at' => null ) );
		self::forget_name( (int) $sess['id'] );
		ZAGR_Events::append( (int) $row['id'], 'session_done', array(), 'customer', (int) $sess['id'] );
		return 'm_done';
	}

	/* ---------------------------------------------------------------- staff (the settings screen) */

	/** A cancellation that came by email or letter, recorded by an administrator. { ok, message } */
	public static function staff_cancel( int $agreement_id, string $how, string $sent_on, int $uid ): array {
		if ( ! ZAGR_People::is_admin( $uid ) ) {
			return array( 'ok' => false, 'message' => 'Only an administrator can record a cancellation.' );
		}
		if ( ! in_array( $how, array( 'email', 'mail' ), true ) || ! ZAGR_Calendar::valid( $sent_on ) ) {
			return array( 'ok' => false, 'message' => 'Choose email or letter, and the date it was sent.' );
		}
		$row = ZAGR_Store::by_id( $agreement_id );
		if ( ! $row || ! in_array( $row['state'], array( 'signed', 'change_pending', 'window_closed' ), true ) ) {
			return array( 'ok' => false, 'message' => 'Only a signed agreement can be cancelled.' );
		}
		if ( ! ZAGR_Key::usable() ) {
			return array( 'ok' => false, 'message' => 'Enter the agreements key first.' );
		}
		$by = substr( (string) $row['cancel_by'], 0, 10 );
		if ( '' !== $by && $sent_on > $by ) {
			return array( 'ok' => false, 'message' => 'That date is after the cancellation period ended (midnight on ' . ZAGR_Calendar::long_date( $by ) . ').' );
		}
		if ( $sent_on < ZAGR_Clock::local_day( ZAGR_Clock::ts( (string) $row['signed_at'] ) ) ) {
			return array( 'ok' => false, 'message' => 'That date is before the customer signed.' );
		}
		$r = self::do_cancel( $row, $how, $sent_on, 'staff:' . $uid );
		if ( ! $r['ok'] ) {
			return array( 'ok' => false, 'message' => 'The agreement changed. Look again.' );
		}
		// say so when an email didn't go
		return array( 'ok' => true, 'message' => $r['mailed']
			? 'The cancellation is recorded, and the cancellations address and the customer were emailed.'
			: 'The cancellation is recorded, but an email couldn\'t be sent (to the cancellations address, the customer, or both). Please tell the customer yourself, and refund within ' . ZAGR_Strings::refund_words( ZAGR_Document::stack( (array) ZAGR_Store::document( $row ) ) ) . '.' );
	}

	/**
	 * Email the signed copy again: for an agreement whose copy never reached the customer. When wp_mail
	 * takes it, the copy is received today and the window counts from today. { ok, message }
	 */
	public static function resend_copy( int $agreement_id, int $uid ): array {
		if ( ! ZAGR_People::is_admin( $uid ) ) {
			return array( 'ok' => false, 'message' => 'Only an administrator can send the signed copy again.' );
		}
		$row = ZAGR_Store::by_id( $agreement_id );
		if ( ! $row || empty( $row['signed_at'] ) || ! in_array( $row['state'], array( 'signed', 'change_pending' ), true ) ) {
			return array( 'ok' => false, 'message' => 'Only a signed agreement that isn\'t cancelled can be sent again.' );
		}
		if ( ! empty( $row['copy_received_at'] ) ) {
			return array( 'ok' => false, 'message' => 'The customer already received the signed copy.' );
		}
		$id        = (int) $row['id'];
		$cancel_by = self::deadline_today( $row );
		// the copy sent prints the deadline the email gives (counted from today, the day it is received)
		$row   = '' === $cancel_by ? null : self::copy_for( $row, $cancel_by, 'resent' );
		$bytes = null === $row || (string) $row['pdf_cancel_by'] !== $cancel_by ? null : ZAGR_Storage::get( (string) $row['pdf_file'], 'agr|' . $id );
		if ( null === $bytes || ! hash_equals( (string) $row['pdf_sha256'], hash( 'sha256', $bytes ) ) ) {
			return array( 'ok' => false, 'message' => 'The signed copy doesn\'t open (is the agreements key entered?).' );
		}
		$number    = self::number( $row );
		$mailed    = ZAGR_Mailer::signed_copy( (string) ZAGR_Store::email( $row ), $bytes, self::file_name( $number, 'agreement' ), self::facts( $row ), ZAGR_Clock::local_long( (string) $row['signed_at'] ), ZAGR_Calendar::long_date( $cancel_by ), self::url( $row ) );
		if ( ! $mailed ) {
			ZAGR_Events::append( $id, 'email_failed', array( 'what' => 'signed_copy', 'by' => 'staff:' . $uid ), 'system' );
			return array( 'ok' => false, 'message' => 'The email couldn\'t be sent. Please call the customer.' );
		}
		if ( ! self::mark_received( $row, 'email', 0, $cancel_by ) ) {
			return array( 'ok' => false, 'message' => 'The signed copy was emailed, but it couldn\'t be recorded as received. Please try again in a minute.' );
		}
		return array( 'ok' => true, 'message' => 'The signed copy was emailed. The customer\'s cancellation period now ends at midnight on ' . ZAGR_Calendar::long_date( $cancel_by ) . '.' );
	}

	/**
	 * A new link; the old one stops working at once. Not while the cancellation period
	 * is open or hasn't started: the customer's signed copy and email carry the link with its Cancel
	 * button. { ok, message }
	 */
	public static function rotate_link( int $agreement_id, int $uid ): array {
		if ( ! ZAGR_People::is_admin( $uid ) ) {
			return array( 'ok' => false, 'message' => 'Only an administrator can replace a link.' );
		}
		$row = ZAGR_Store::by_id( $agreement_id );
		if ( ! $row ) {
			return array( 'ok' => false, 'message' => 'No such agreement.' );
		}
		$row = ZAGR_Store::refresh_window( $row );
		if ( ! empty( $row['signed_at'] ) && in_array( $row['state'], array( 'signed', 'change_pending' ), true ) && ! self::window_over( $row ) ) {
			return array( 'ok' => false, 'message' => 'Not while the customer can still cancel: their signed copy and email carry this link, with its Cancel button. Make a new link after the cancellation period ends.' );
		}
		for ( $i = 0; $i < 5; $i++ ) {
			$w = ZAGR_Words::generate();
			if ( '' !== $w && ! ZAGR_Store::by_link_hash( ZAGR_Words::hash( $w ) ) ) {
				$s = ZAGR_Key::seal( $w, 'link|' . (int) $row['estimate_id'] );
				if ( null === $s ) {
					return array( 'ok' => false, 'message' => 'Enter the agreements key first.' );
				}
				ZAGR_Store::update( $agreement_id, array( 'link_hash' => ZAGR_Words::hash( $w ), 'link_sealed' => $s ) );
				ZAGR_Events::append( $agreement_id, 'link_rotated', array(), 'staff:' . $uid );
				return array( 'ok' => true, 'message' => 'The link is replaced. The old one no longer opens. Write the new one onto the estimate again (the estimate app does this on its next save).' );
			}
		}
		return array( 'ok' => false, 'message' => 'A new link could not be made.' );
	}
}
