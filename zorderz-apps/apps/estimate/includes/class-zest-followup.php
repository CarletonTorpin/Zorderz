<?php
/**
 * ZEST_Followup - an estimate re-sent to its salesperson, and what they found out: the record and the words.
 *
 * An estimate that is still undecided can be sent back to the person it names, who says how it went: reached the
 * customer, tried with no answer, or had already handled it; what the customer said (a fixed list); and their own
 * words. Nobody has to have asked: a salesperson records a follow-up on any estimate of their own. This class is the
 * record and the words. It holds no request handling (ZEST_Followup_Ajax does) and calls neither the billing system
 * nor the CRM.
 *
 *   zest_followups   one row per ask, or per answer nobody asked for. Rows are never deleted.
 *     state  open        asked, no answer yet
 *            tried       tried, no answer (still theirs to do; `tries` counts, `try_notes` keeps each try's words)
 *            done        reached the customer, or it was already handled (answer, outcome, the person's own words)
 *            superseded  a newer ask replaced it
 *            withdrawn   taken back as recorded or sent by mistake: an answer nobody asked for, or an ask
 *   "The follow-up" of an estimate is always its NEWEST row that is neither superseded nor withdrawn (latest()); it is
 *   waiting on someone when that row is open or tried (waiting()). Every write that reads and then writes runs under
 *   one named lock per estimate (the request class's locked()) and carries the token of the row the screen showed
 *   (token()), so a screen that was not drawn again can never act on what it did not show.
 *
 * TWO KINDS OF WORDS, KEPT APART
 *   - ask_note / answer_note / try_notes: what a person typed. It stays in this app, goes to the estimate's CRM lead
 *     (the business's own record) and to whoever asked. It is NEVER written to the billing system.
 *   - doc_line: the one line written at the bottom of the billing estimate's notes, which the customer can read. It is
 *     assembled only from fixed phrases (words()), a date and the person's code: see line() and line_ok(), which the
 *     writer asks again right before it writes. Nothing typed can reach it. A cleaner that tried to take names and
 *     numbers out of typed text would be a guess; a closed list cannot leak.
 *
 * THE PHRASES are document-conventions data (estimate.followup: lead, reopened, and one phrase per outcome code), with
 * the plain English in WORDS as the default. A phrase is one line of plain words: no digit, "@" or "#", so a
 * configured phrase can never carry a number or an address either. A line written with the defaults is still
 * recognised after a business words its own.
 *
 * THE SWITCH for the billing line is option zest_followup_line_mode: off / test / on, default test = only estimates of
 * a test customer get the line (ZEST_Terms_Note::is_test). The record, the notices and the CRM note do not depend on it.
 *
 * TAKEN BACK. undo() takes back the LAST thing recorded on the follow-up (an answer, a try or an ask) and nothing
 * older: an asked row goes back to waiting (open, or tried when earlier tries remain); a row nobody asked for, and an
 * ask, become `withdrawn`. What was taken back is kept on the row (undo_log, its newest entries; `undone` counts them
 * all). An ask taken back brings back the ask it had replaced. The billing line is the request class's to take off
 * (without_lines()): doc_on holds this row's own lines that may be on the estimate (doc_line is only the last one
 * tried), and doc_prev the line the billing system showed before this row's first, so taking this row's off can put
 * that one back, or leave it when it is the very same line.
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZEST_Followup {

	const ANSWERS = array( 'reached', 'tried', 'handled' );

	/** How they got in touch. The platform's contact log takes exactly these (ZDZ_Contacts::CHANNELS). */
	const CHANNELS = array( 'call', 'voicemail', 'text', 'email', 'in_person' );

	/** What the customer said: code => does it move the estimate to "Declined estimates". The order is the order offered. */
	const OUTCOMES = array(
		'deciding'       => false,
		'changes'        => false,
		'callback'       => false,
		'going_ahead'    => false,
		'other_provider' => true,
		'not_needed'     => true,
		'other'          => false,
	);

	/** The only words that may go on the customer's estimate: the line's first word, the reopened line, one per outcome. */
	const WORDS = array(
		'lead'           => 'Follow-up',
		'reopened'       => 'Estimate reopened.',
		'deciding'       => 'Customer is still deciding.',
		'changes'        => 'Customer asked for changes to the estimate.',
		'callback'       => 'Customer will call back.',
		'going_ahead'    => 'Customer plans to go ahead.',
		'other_provider' => 'Customer chose another provider.',
		'not_needed'     => 'Customer no longer needs the work.',
		'other'          => 'Spoke with customer.',
	);

	const MODE_OPTION = 'zest_followup_line_mode';
	const MAX_NOTE    = 1000;
	const MAX_PHRASE  = 120;
	const MAX_LEAD    = 30;

	/** The states that are no longer the estimate's follow-up: replaced by a newer ask, or taken back. */
	const GONE = "'superseded','withdrawn'";

	/** How many take-backs a row keeps in undo_log (the newest). `undone` counts every one. */
	const UNDO_KEEP = 12;

	/** The columns a list needs: never the typed words (ask_note, answer_note, try_notes) or the note queue. */
	const LIST_COLS = 'id, estimate_id, is_test, state, sent_by, sent_to, sent_at, tries, answer, outcome, channel, declined, answered_by, answered_at, reopened_by, reopened_at, doc_state, doc_why, undone';

	private static $words = null;

	/* ================================================================ the switch */

	/** off | test | on: whether an answer writes its line onto the billing estimate. Anything unreadable is test. */
	public static function mode(): string {
		$m = function_exists( 'get_option' ) ? strtolower( trim( (string) get_option( self::MODE_OPTION, 'test' ) ) ) : 'test';
		return in_array( $m, array( 'off', 'test', 'on' ), true ) ? $m : 'test';
	}

	/** Does the switch want the line on this estimate (a test customer's, or anyone's)? */
	public static function line_wanted( bool $is_test ): bool {
		$m = self::mode();
		return 'on' === $m || ( 'test' === $m && $is_test );
	}

	/* ================================================================ the words (pure) */

	/** code => the label the team reads. */
	public static function channels(): array {
		return array(
			'call'      => __( 'Call', 'zorderz' ),
			'voicemail' => __( 'Left a voicemail', 'zorderz' ),
			'text'      => __( 'Text', 'zorderz' ),
			'email'     => __( 'Email', 'zorderz' ),
			'in_person' => __( 'In person', 'zorderz' ),
		);
	}

	public static function channel_label( string $code ): string {
		return self::channels()[ $code ] ?? '';
	}

	/** code => the label the team reads and picks. */
	public static function outcome_labels(): array {
		return array(
			'deciding'       => __( 'Still deciding', 'zorderz' ),
			'changes'        => __( 'Wants changes to the estimate', 'zorderz' ),
			'callback'       => __( 'Will call us back', 'zorderz' ),
			'going_ahead'    => __( 'Wants to go ahead', 'zorderz' ),
			'other_provider' => __( 'Found another provider', 'zorderz' ),
			'not_needed'     => __( 'No longer needs the work', 'zorderz' ),
			'other'          => __( 'Something else (say in the note)', 'zorderz' ),
		);
	}

	public static function outcome_label( string $code ): string {
		return self::outcome_labels()[ $code ] ?? '';
	}

	public static function is_declined( string $code ): bool {
		return ! empty( self::OUTCOMES[ $code ] );
	}

	/** One line of plain words: letters, spaces and sentence punctuation; never a digit, "@" or "#". */
	public static function valid_phrase( $s ): bool {
		return is_string( $s ) && '' !== trim( $s ) && trim( $s ) === $s && strlen( $s ) <= self::MAX_PHRASE
			&& ! preg_match( '/[\x00-\x1F\x7F0-9@#<>]/', $s );
	}

	/** The line's first word or words: letters, spaces and hyphens only (the line's own punctuation follows it). */
	public static function valid_lead( $s ): bool {
		return is_string( $s ) && strlen( $s ) <= self::MAX_LEAD && 1 === preg_match( '/\A\p{L}[\p{L} \-]*\p{L}\z/u', $s );
	}

	/** The wording in force: document conventions (estimate.followup) over the defaults, each value checked. */
	public static function words(): array {
		if ( null !== self::$words ) {
			return self::$words;
		}
		$w = self::WORDS;
		if ( class_exists( 'ZDZ_Doc_Conventions' ) && method_exists( 'ZDZ_Doc_Conventions', 'get' ) ) {
			$c = ZDZ_Doc_Conventions::get( 'estimate.followup', array() );
			foreach ( is_array( $c ) ? $c : array() as $k => $v ) {
				if ( ! is_string( $k ) || ! isset( $w[ $k ] ) ) {
					continue;
				}
				if ( 'lead' === $k ? self::valid_lead( $v ) : self::valid_phrase( $v ) ) {
					$w[ $k ] = $v;
				}
			}
		}
		return self::$words = $w;
	}

	public static function reset(): void {
		self::$words = null;
	}

	/** Every sentence that may follow "<lead> <date> (XX): " on a billing estimate, in this wording. */
	public static function phrases( ?array $w = null ): array {
		$w = null === $w ? self::words() : $w;
		$p = array();
		foreach ( $w as $k => $v ) {
			if ( 'lead' !== $k ) {
				$p[] = $v;
			}
		}
		return $p;
	}

	/**
	 * The billing line for an answer: "Follow-up 6/1/2026 (AB): Customer chose another provider."
	 * $what: an outcome code, or 'reopened'. Anything else, or a date that is not one, gives '' (no line): this never
	 * falls back to free text. A code that is not one to five capitals or digits is left off, never replaced by a name.
	 *
	 * @param string $date the site's day with slashes, in the business's order (n/j/Y or j/n/Y)
	 */
	public static function line( string $what, string $initials, string $date ): string {
		$w = self::words();
		if ( 'lead' === $what || ! isset( $w[ $what ] ) ) {
			return '';
		}
		if ( ! preg_match( '#\A\d{1,2}/\d{1,2}/\d{4}\z#', $date ) ) {
			return '';
		}
		$ini  = strtoupper( trim( $initials ) );
		$who  = preg_match( '/\A[A-Z0-9]{1,5}\z/', $ini ) ? ' (' . $ini . ')' : '';
		$line = $w['lead'] . ' ' . $date . $who . ': ' . $w[ $what ];
		return self::line_ok( $line ) ? $line : '';
	}

	/**
	 * Is this exactly one of the app's own lines: the lead, a date, an optional code and one of the fixed phrases (in
	 * the wording in force, or in the defaults)? The writer asks this right before it writes, so a line built anywhere
	 * else, or carrying anything a person typed, is refused.
	 */
	public static function line_ok( string $line ): bool {
		foreach ( array( self::words(), self::WORDS ) as $w ) {
			// \A ... \z, never ^ ... $: "$" also matches before a final newline, and a line is one line.
			if ( preg_match( '#\A' . preg_quote( $w['lead'], '#' ) . ' \d{1,2}/\d{1,2}/\d{4}(?: \([A-Z0-9]{1,5}\))?: (.+)\z#u', $line, $m )
				&& in_array( $m[1], self::phrases( $w ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/** One of the app's own lines without the person's code (what an account that sees no team codes is shown); else ''. */
	public static function line_without_code( string $line ): string {
		$line = trim( $line );
		if ( '' === $line || ! self::line_ok( $line ) ) {
			return '';
		}
		return (string) preg_replace( '#\A(.+? \d{1,2}/\d{1,2}/\d{4}) \([A-Z0-9]{1,5}\):#u', '$1:', $line );
	}

	/**
	 * The estimate's notes with $line as their one follow-up line, at the bottom. An earlier line of the app's own
	 * (same shape, one of the fixed phrases) is taken out, so the customer reads the latest word, not a list. A line a
	 * person wrote that merely starts with the lead word is not the app's and is left alone.
	 *
	 * @return array{notes:string,before:string,changed:bool}
	 */
	public static function with_line( string $notes, string $line ): array {
		$keep   = array();
		$before = '';
		foreach ( (array) preg_split( '/\r\n|\r|\n/', $notes ) as $l ) {
			if ( self::line_ok( trim( (string) $l ) ) ) {
				$before = trim( (string) $l ); // the last one found is the one reported
				continue;
			}
			$keep[] = $l;
		}
		$body = rtrim( implode( "\n", $keep ) );
		$new  = '' === $line ? $body : ( '' === $body ? $line : $body . "\n" . $line );
		// Unchanged only when the same line was already the last one: a line found higher up moves to the bottom.
		$same = rtrim( $notes ) === $new;
		return array( 'notes' => $same ? $notes : $new, 'before' => $before, 'changed' => ! $same );
	}

	/**
	 * The estimate's notes with exactly these lines taken out (a follow-up taken back). Only the lines given go, never
	 * another follow-up line, never a person's words, and only those that are the app's own fixed lines. When one was
	 * there and $restore is the line the billing system showed before (also one of the app's), that one goes back at
	 * the bottom. None found: nothing changes, and nothing is put back (the notes are as a person left them). Every
	 * other line, and the notes' own line endings, stay as they were.
	 *
	 * @param string[] $lines
	 * @return array{notes:string,found:bool,restored:string}
	 */
	public static function without_lines( string $notes, array $lines, string $restore = '' ): array {
		$same = array( 'notes' => $notes, 'found' => false, 'restored' => '' );
		$gone = array();
		foreach ( $lines as $l ) {
			if ( is_string( $l ) && self::line_ok( $l ) ) {
				$gone[ $l ] = true;
			}
		}
		if ( ! $gone ) {
			return $same;
		}
		$nl    = false !== strpos( $notes, "\r\n" ) ? "\r\n" : "\n";
		$keep  = array();
		$found = false;
		foreach ( (array) preg_split( '/\r\n|\r|\n/', $notes ) as $l ) {
			if ( isset( $gone[ trim( (string) $l ) ] ) ) {
				$found = true;
				continue;
			}
			$keep[] = $l;
		}
		if ( ! $found ) {
			return $same;
		}
		$body = rtrim( implode( $nl, $keep ) );
		if ( '' !== $restore && ! isset( $gone[ $restore ] ) && self::line_ok( $restore ) ) {
			$last = trim( (string) ( array_slice( (array) preg_split( '/\r\n|\r|\n/', $body ), -1 )[0] ?? '' ) );
			if ( $last !== $restore ) {
				$body = '' === $body ? $restore : $body . $nl . $restore;
			}
			return array( 'notes' => $body, 'found' => true, 'restored' => $restore );
		}
		return array( 'notes' => $body, 'found' => true, 'restored' => '' );
	}

	/** What a person typed, kept for the app as typed: no control characters, at most MAX_NOTE. Shown escaped, always. */
	public static function clean_note( $text ): string {
		if ( ! is_scalar( $text ) ) {
			return '';
		}
		$t = (string) $text;
		if ( function_exists( 'wp_check_invalid_utf8' ) ) {
			$t = (string) wp_check_invalid_utf8( $t, true );
		}
		// Nothing is cut for looking like markup: "quoted <100, wants >5% off" is what the customer said. Every place
		// that shows these words escapes them.
		$t = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', (string) $t );
		$t = preg_replace( "/\r\n|\r/", "\n", (string) $t );
		$t = preg_replace( "/\n{3,}/", "\n\n", (string) $t );
		$t = trim( (string) $t );
		return function_exists( 'mb_substr' ) ? mb_substr( $t, 0, self::MAX_NOTE ) : substr( $t, 0, self::MAX_NOTE );
	}

	/** "reached" / "handled" need an outcome; "tried" never has one. Returns the outcome to store, or null = refuse. */
	public static function outcome_for( string $answer, string $outcome ): ?string {
		if ( 'tried' === $answer ) {
			return '';
		}
		if ( ! in_array( $answer, array( 'reached', 'handled' ), true ) ) {
			return null;
		}
		return isset( self::OUTCOMES[ $outcome ] ) ? $outcome : null;
	}

	/** The channel to store for an answer, or null = refuse. A voicemail is a try, never a reach (the contact log's rule). */
	public static function channel_for( string $answer, string $channel ): ?string {
		if ( ! in_array( $channel, self::CHANNELS, true ) ) {
			return null;
		}
		if ( 'voicemail' === $channel && 'tried' !== $answer ) {
			return null;
		}
		if ( 'in_person' === $channel && 'tried' === $answer ) {
			return null; // met them in person = reached them
		}
		return $channel;
	}

	/** One sentence saying what a row says, for the list and the notice: no customer, no typed words. */
	public static function status_words( array $r, string $to, string $by, string $answered_by ): string {
		$state = (string) ( $r['state'] ?? '' );
		if ( 'open' === $state ) {
			$whom = '' !== $to ? $to : __( 'the salesperson', 'zorderz' );
			return '' !== $by
				/* translators: 1: who asked, 2: who was asked */
				? sprintf( __( '%1$s asked %2$s to follow up', 'zorderz' ), $by, $whom )
				/* translators: %s: who was asked */
				: sprintf( __( 'Asked %s to follow up', 'zorderz' ), $whom );
		}
		$who = '' !== $answered_by ? $answered_by : __( 'Someone', 'zorderz' );
		if ( 'tried' === $state ) {
			$n = max( 1, (int) ( $r['tries'] ?? 1 ) );
			return $n > 1
				/* translators: 1: who tried, 2: how many times */
				? sprintf( __( '%1$s tried %2$d times, no answer yet', 'zorderz' ), $who, $n )
				/* translators: %s: who tried */
				: sprintf( __( '%s tried, no answer yet', 'zorderz' ), $who );
		}
		if ( 'done' === $state ) {
			$what = self::outcome_label( (string) ( $r['outcome'] ?? '' ) );
			$said = 'handled' === ( $r['answer'] ?? '' )
				/* translators: %s: who answered */
				? sprintf( __( '%s had already handled it', 'zorderz' ), $who )
				/* translators: %s: who answered */
				: sprintf( __( '%s reached the customer', 'zorderz' ), $who );
			return '' !== $what ? $said . ': ' . $what : $said;
		}
		return '';
	}

	/**
	 * Why the billing line was left alone or not written, as a sentence. The row keeps a short code (doc_why), never
	 * free text, so nothing but these sentences can ever be shown for it.
	 */
	public static function why_words( string $code ): string {
		$line = 0;
		if ( preg_match( '/\Adiffers:(\d{1,4})\z/', $code, $m ) ) {
			$code = 'differs';
			$line = (int) $m[1];
		}
		$w = array(
			'no_line'         => __( 'no fixed line for that answer; nothing was written to the billing system', 'zorderz' ),
			'bad_line'        => __( 'the stored line is not one of the fixed lines, so the billing system was not touched', 'zorderz' ),
			'not_connected'   => __( 'the billing system is not connected', 'zorderz' ),
			'local'           => __( 'this estimate is not in a billing system', 'zorderz' ),
			'mode_off'        => __( 'the follow-up line on billing estimates is switched off', 'zorderz' ),
			'mode_test'       => __( 'the follow-up line on billing estimates is switched on for test customers only', 'zorderz' ),
			'busy'            => __( 'someone is saving this estimate right now', 'zorderz' ),
			'taken_back'      => __( 'the follow-up was taken back meanwhile', 'zorderz' ),
			'unread'          => __( 'the billing system could not be read just now', 'zorderz' ),
			'other_doc'       => __( 'the billing system answered with a different estimate', 'zorderz' ),
			'customer_unread' => __( 'the customer in the billing system could not be read just now', 'zorderz' ),
			'customer_old'    => __( 'this app has no billing customer on record for this older estimate, and the name in the billing system is not the name here', 'zorderz' ),
			'customer'        => __( 'the customer in the billing system could not be confirmed as the one this app recorded', 'zorderz' ),
			'archived'        => __( 'the estimate is archived or deleted in the billing system', 'zorderz' ),
			'accepted'        => __( 'the estimate was accepted or invoiced', 'zorderz' ),
			'not_open'        => __( 'this app\'s copy says the estimate is no longer open', 'zorderz' ),
			'no_lines'        => __( 'the estimate has no lines yet, and the billing system takes notes only together with lines', 'zorderz' ),
			'history'         => __( 'the change history could not be recorded, so nothing was written', 'zorderz' ),
			'refused'         => __( 'the billing system did not accept the change', 'zorderz' ),
			'not_last'        => __( 'the billing system took the change, but its notes do not end with the line; open the estimate to check it', 'zorderz' ),
			'still_there'     => __( 'the billing system took the change, but its notes still do not read as they should; open the estimate to check it', 'zorderz' ),
			'undo_unfinished' => __( 'its line was taken off during a take-back that did not finish', 'zorderz' ),
			'error'           => __( 'something went wrong while writing to the billing system', 'zorderz' ),
		);
		if ( 'differs' === $code ) {
			if ( $line <= 0 ) {
				return __( 'the billing system saved it, but its copy has a different number of lines', 'zorderz' );
			}
			/* translators: %d: a line number */
			return sprintf( __( 'the billing system saved it, but its copy reads differently at line %d', 'zorderz' ), $line );
		}
		return $w[ $code ] ?? '';
	}

	/* ================================================================ the record */

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'zest_followups';
	}

	public static function schema( string $prefix, string $charset ): string {
		return "CREATE TABLE {$prefix}zest_followups (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			estimate_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
			billing_doc_id  VARCHAR(50)  NOT NULL DEFAULT '',
			billing_doc_num VARCHAR(50)  NOT NULL DEFAULT '',
			is_test         TINYINT(1)   NOT NULL DEFAULT 0,
			state           VARCHAR(12)  NOT NULL DEFAULT 'open',
			sent_by         BIGINT UNSIGNED NOT NULL DEFAULT 0,
			sent_to         BIGINT UNSIGNED NOT NULL DEFAULT 0,
			sent_at         DATETIME     NOT NULL,
			ask_note        TEXT,
			tries           INT          NOT NULL DEFAULT 0,
			answer          VARCHAR(12)  NOT NULL DEFAULT '',
			outcome         VARCHAR(20)  NOT NULL DEFAULT '',
			channel         VARCHAR(12)  NOT NULL DEFAULT '',
			answer_note     TEXT,
			try_notes       TEXT,
			declined        TINYINT(1)   NOT NULL DEFAULT 0,
			answered_by     BIGINT UNSIGNED NOT NULL DEFAULT 0,
			answered_at     DATETIME     NULL DEFAULT NULL,
			reopened_by     BIGINT UNSIGNED NOT NULL DEFAULT 0,
			reopened_at     DATETIME     NULL DEFAULT NULL,
			doc_line        VARCHAR(255) NOT NULL DEFAULT '',
			doc_state       VARCHAR(12)  NOT NULL DEFAULT '',
			doc_why         VARCHAR(40)  NOT NULL DEFAULT '',
			doc_prev        VARCHAR(255) NOT NULL DEFAULT '',
			doc_on          TEXT,
			undo_log        TEXT,
			undone          INT          NOT NULL DEFAULT 0,
			crm_state       VARCHAR(12)  NOT NULL DEFAULT '',
			crm_queue       LONGTEXT,
			crm_tries       INT          NOT NULL DEFAULT 0,
			contact_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY idx_est (estimate_id, id),
			KEY idx_to (sent_to, state)
		) {$charset};";
	}

	public static function table_exists(): bool {
		global $wpdb;
		$t = self::table();
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t;
	}

	/** Make the table when it is missing (files copied over an install whose upgrade pass has not run yet). */
	public static function ensure_table(): void {
		global $wpdb;
		if ( ! self::table_exists() && defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( self::schema( $wpdb->prefix, $wpdb->get_charset_collate() ) );
		}
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		$r = $id > 0 ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name (self::table())
		return is_array( $r ) ? $r : null;
	}

	/**
	 * Run a read that must never break a page or fill the log when the table is not there yet (the lists run for
	 * everyone, on every load): database errors are silenced for its length, and anything thrown gives $default.
	 */
	public static function quiet( callable $read, $default ) {
		global $wpdb;
		$was = ( is_object( $wpdb ) && method_exists( $wpdb, 'suppress_errors' ) ) ? $wpdb->suppress_errors( true ) : null;
		try {
			$out = $read();
			return null === $out ? $default : $out;
		} catch ( \Throwable $e ) {
			return $default;
		} finally {
			if ( null !== $was ) {
				$wpdb->suppress_errors( $was );
			}
		}
	}

	/** This row's own fixed lines that may be on the billing estimate (doc_on: one per line, newest last). */
	public static function lines_on( array $r ): array {
		$out = array();
		foreach ( (array) preg_split( '/\r\n|\r|\n/', (string) ( $r['doc_on'] ?? '' ) ) as $l ) {
			if ( '' !== trim( (string) $l ) ) {
				$out[] = trim( (string) $l );
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** The estimate's follow-up: its newest row that is neither superseded nor withdrawn (the whole row); or null. */
	public static function latest( int $estimate_id ): ?array {
		global $wpdb;
		if ( $estimate_id <= 0 ) {
			return null;
		}
		$r = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE estimate_id = %d AND state NOT IN ( ' . self::GONE . ' ) ORDER BY id DESC LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name (self::table()); self::GONE is a constant list of two quoted words
			$estimate_id
		), ARRAY_A );
		return is_array( $r ) ? $r : null;
	}

	/** Is this row waiting on someone (asked, or tried with no answer)? */
	public static function is_waiting( ?array $r ): bool {
		return null !== $r && in_array( (string) ( $r['state'] ?? '' ), array( 'open', 'tried' ), true );
	}

	/** The estimate's follow-up when it is waiting on someone; else null. Always the newest row, never an older one. */
	public static function waiting( int $estimate_id ): ?array {
		$r = self::latest( $estimate_id );
		return self::is_waiting( $r ) ? $r : null;
	}

	/** Whole numbers above zero, each once: the only values an IN list below is ever built from. */
	private static function ids( array $ids ): array {
		$out = array();
		foreach ( $ids as $i ) {
			if ( is_scalar( $i ) && ctype_digit( (string) $i ) && (int) $i > 0 ) {
				$out[ (int) $i ] = true;
			}
		}
		return array_keys( $out );
	}

	/**
	 * estimate id => its follow-up (LIST_COLS only), for a list. One query per 400 estimates. Only each estimate's
	 * newest row that still stands is read.
	 *
	 * @param int[] $estimate_ids
	 * @return array<int,array>
	 */
	public static function latest_map( array $estimate_ids ): array {
		global $wpdb;
		$out = array();
		$t   = self::table();
		foreach ( array_chunk( self::ids( $estimate_ids ), 400 ) as $chunk ) {
			$in   = implode( ',', array_map( 'intval', $chunk ) );
			$rows = $wpdb->get_results(
				'SELECT ' . self::LIST_COLS . " FROM {$t} WHERE id IN ( SELECT MAX(id) FROM {$t} WHERE estimate_id IN ( {$in} ) AND state NOT IN ( " . self::GONE . ' ) GROUP BY estimate_id )', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is $wpdb->prefix plus a fixed name; $in is int-cast ids (self::ids()); the rest are class constants
				ARRAY_A
			);
			foreach ( is_array( $rows ) ? $rows : array() as $r ) {
				$out[ (int) $r['estimate_id'] ] = $r;
			}
		}
		return $out;
	}

	/**
	 * Of these estimates, the ones with any follow-up row at all (also replaced and withdrawn ones): there is a
	 * history to show.
	 *
	 * @param int[] $estimate_ids
	 * @return int[]
	 */
	public static function have_rows( array $estimate_ids ): array {
		global $wpdb;
		$out = array();
		$t   = self::table();
		foreach ( array_chunk( self::ids( $estimate_ids ), 400 ) as $chunk ) {
			$in   = implode( ',', array_map( 'intval', $chunk ) );
			$rows = $wpdb->get_col( "SELECT DISTINCT estimate_id FROM {$t} WHERE estimate_id IN ( {$in} )" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is $wpdb->prefix plus a fixed name; $in is int-cast ids (self::ids())
			foreach ( is_array( $rows ) ? $rows : array() as $id ) {
				$out[] = (int) $id;
			}
		}
		return $out;
	}

	/**
	 * The estimates whose follow-up was sent to this person (waiting on them, or answered): so the estimate is on their
	 * list even when this app's row does not otherwise make it theirs, and stays there with its answer once they have
	 * given one. An ask that a newer one replaced, or one taken back, does not count.
	 *
	 * @return int[]
	 */
	public static function sent_to( int $uid ): array {
		global $wpdb;
		if ( $uid <= 0 ) {
			return array();
		}
		$t    = self::table();
		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT a.estimate_id FROM {$t} a WHERE a.sent_to = %d AND a.state NOT IN ( " . self::GONE . ' )' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is $wpdb->prefix plus a fixed name; self::GONE is a constant list of two quoted words
			. " AND NOT EXISTS ( SELECT 1 FROM {$t} b WHERE b.estimate_id = a.estimate_id AND b.id > a.id AND b.state NOT IN ( " . self::GONE . ' ) ) ORDER BY a.id DESC LIMIT 500', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- as the line above
			$uid
		) );
		return self::ids( is_array( $rows ) ? $rows : array() );
	}

	/**
	 * The estimates that have a follow-up standing now (asked, tried, answered or declined), newest first: what an
	 * open list shows beside the estimates that were never sent to the customer.
	 *
	 * @return int[]
	 */
	public static function with_followup( int $limit = 500 ): array {
		global $wpdb;
		$t    = self::table();
		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT estimate_id FROM {$t} WHERE state NOT IN ( " . self::GONE . ' ) GROUP BY estimate_id ORDER BY MAX(id) DESC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is $wpdb->prefix plus a fixed name; self::GONE is a constant list of two quoted words
			max( 1, min( 2000, $limit ) )
		) );
		return self::ids( is_array( $rows ) ? $rows : array() );
	}

	/** Every row for an estimate, newest first (the history sheet). */
	public static function history( int $estimate_id, int $limit = 30 ): array {
		global $wpdb;
		if ( $estimate_id <= 0 ) {
			return array();
		}
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE estimate_id = %d ORDER BY id DESC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name (self::table())
			$estimate_id, max( 1, min( 100, $limit ) )
		), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Record an ask. Any ask still waiting on this estimate is superseded first, so one estimate has one open ask.
	 * The caller holds the estimate's lock (two asks at once would otherwise both stay open).
	 *
	 * @param array $a {estimate_id, billing_doc_id, billing_doc_num, is_test, sent_by, sent_to, ask_note}
	 * @return int the new row's id, 0 when it could not be recorded
	 */
	public static function ask( array $a ): int {
		global $wpdb;
		$est = (int) ( $a['estimate_id'] ?? 0 );
		$to  = (int) ( $a['sent_to'] ?? 0 );
		if ( $est <= 0 || $to <= 0 ) {
			return 0;
		}
		$wpdb->query( $wpdb->prepare(
			'UPDATE ' . self::table() . " SET state = 'superseded' WHERE estimate_id = %d AND state IN ( 'open', 'tried' )", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name (self::table())
			$est
		) );
		$ok = $wpdb->insert( self::table(), array(
			'estimate_id'     => $est,
			'billing_doc_id'  => substr( (string) ( $a['billing_doc_id'] ?? '' ), 0, 50 ),
			'billing_doc_num' => substr( (string) ( $a['billing_doc_num'] ?? '' ), 0, 50 ),
			'is_test'         => empty( $a['is_test'] ) ? 0 : 1,
			'state'           => 'open',
			'sent_by'         => (int) ( $a['sent_by'] ?? 0 ),
			'sent_to'         => $to,
			'sent_at'         => current_time( 'mysql', true ),
			'ask_note'        => self::clean_note( $a['ask_note'] ?? '' ),
		) );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * What a screen saw of an estimate's follow-up, sent back with every write: the newest row's id, state, tries,
	 * declined mark and whether it was reopened. "0" = no follow-up at all. A write whose token is not the token of
	 * the follow-up as it is NOW is refused, whatever the row's state.
	 * Once anything has been taken back from the row, the count of take-backs rides at the end (":2"). A take-back can
	 * return a row to a state it was in before (open, done, open, done), and two different answers must never share a
	 * token: a screen still showing the first could act on the second.
	 */
	public static function token( ?array $r ): string {
		if ( ! $r ) {
			return '0';
		}
		$undone = (int) ( $r['undone'] ?? 0 );
		return (int) $r['id'] . ':' . (string) $r['state'] . ':' . (int) $r['tries'] . ':' . ( empty( $r['declined'] ) ? 0 : 1 ) . ':' . ( empty( $r['reopened_at'] ) ? 0 : 1 )
			. ( $undone > 0 ? ':' . $undone : '' );
	}

	/** Each try's own words, oldest first: [{at (UTC), by, channel, note}]. */
	public static function tries_of( array $r ): array {
		$t = json_decode( (string) ( $r['try_notes'] ?? '' ), true );
		return is_array( $t ) ? array_values( array_filter( $t, 'is_array' ) ) : array();
	}

	/**
	 * Record an answer: on the follow-up that is waiting ($row), or as a row of its own ($row null: nobody asked, or
	 * the last follow-up is finished). The caller holds the estimate's lock and has checked the token; the update is
	 * still ONE statement that names the state it read, so it can never land on a row that moved.
	 * A try's words go to try_notes (one entry per try, never overwritten); the final answer's go to answer_note.
	 *
	 * @param array $a {estimate_id, billing_doc_id, billing_doc_num, is_test, answer, outcome, channel, note, by}
	 * @return int the row's id; 0 = not recorded; -1 = the follow-up changed since it was read
	 */
	public static function answer( ?array $row, array $a ): int {
		global $wpdb;
		$answer  = (string) ( $a['answer'] ?? '' );
		$outcome = self::outcome_for( $answer, (string) ( $a['outcome'] ?? '' ) );
		$channel = self::channel_for( $answer, (string) ( $a['channel'] ?? '' ) );
		$by      = (int) ( $a['by'] ?? 0 );
		if ( null === $outcome || null === $channel || $by <= 0 ) {
			return 0;
		}
		if ( $row && ! self::is_waiting( $row ) ) {
			return -1; // only a waiting row is answered in place
		}
		$now   = current_time( 'mysql', true );
		$done  = 'tried' !== $answer;
		$note  = self::clean_note( $a['note'] ?? '' );
		$tries = $row ? self::tries_of( $row ) : array();
		if ( ! $done ) {
			$tries[] = array( 'at' => $now, 'by' => $by, 'channel' => $channel, 'note' => $note );
		}
		$set = array(
			'state'       => $done ? 'done' : 'tried',
			'answer'      => $answer,
			'outcome'     => $outcome,
			'channel'     => $channel,
			'answer_note' => $done ? $note : '',
			'try_notes'   => $tries ? wp_json_encode( $tries ) : '',
			'declined'    => ( $done && self::is_declined( $outcome ) ) ? 1 : 0,
			'answered_by' => $by,
			'answered_at' => $now,
			'tries'       => count( $tries ),
		);
		if ( $row ) {
			$n = $wpdb->query( $wpdb->prepare(
				'UPDATE ' . self::table() . ' SET state = %s, answer = %s, outcome = %s, channel = %s, answer_note = %s, try_notes = %s, declined = %d, answered_by = %d, answered_at = %s, tries = %d' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name (self::table())
				. ' WHERE id = %d AND state = %s AND tries = %d',
				$set['state'], $set['answer'], $set['outcome'], $set['channel'], $set['answer_note'], $set['try_notes'], $set['declined'], $set['answered_by'], $set['answered_at'], $set['tries'],
				(int) $row['id'], (string) $row['state'], (int) $row['tries']
			) );
			return $n ? (int) $row['id'] : -1;
		}
		$est = (int) ( $a['estimate_id'] ?? 0 );
		if ( $est <= 0 ) {
			return 0;
		}
		$ok = $wpdb->insert( self::table(), $set + array(
			'estimate_id'     => $est,
			'billing_doc_id'  => substr( (string) ( $a['billing_doc_id'] ?? '' ), 0, 50 ),
			'billing_doc_num' => substr( (string) ( $a['billing_doc_num'] ?? '' ), 0, 50 ),
			'is_test'         => empty( $a['is_test'] ) ? 0 : 1,
			'sent_by'         => 0,      // nobody asked: the person said so themselves
			'sent_to'         => $by,
			'sent_at'         => $now,
		) );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/* ================================================================ taken back */

	/** What undo() would take back from this row: 'answer' (done), 'try' (tried), 'ask' (open); '' = nothing. */
	public static function undo_kind( ?array $r ): string {
		$map = array( 'done' => 'answer', 'tried' => 'try', 'open' => 'ask' );
		return $r ? ( $map[ (string) ( $r['state'] ?? '' ) ] ?? '' ) : '';
	}

	/** What was taken back from this row, oldest first: [{at (UTC), by, kind, ...what it said}]. */
	public static function undone_of( array $r ): array {
		$u = json_decode( (string) ( $r['undo_log'] ?? '' ), true );
		return is_array( $u ) ? array_values( array_filter( $u, 'is_array' ) ) : array();
	}

	/**
	 * Take back the LAST thing recorded on this follow-up, as recorded (or sent) by mistake:
	 *   done   the answer. An asked row waits again: `tried` when earlier tries remain (the last of them is its
	 *          answer again), else `open`. A row nobody asked for becomes `withdrawn`, unless earlier tries remain.
	 *   tried  the last try. With none left, an asked row is `open` again and an unasked one `withdrawn`.
	 *   open   the ask itself: `withdrawn`; the waiting ask it had replaced (if any) waits again.
	 * What was taken back goes to undo_log (who, when, and what it said); the row's billing fields are cleared,
	 * because the caller has taken the line off (or left the billing system alone) BEFORE calling this. The caller
	 * holds the estimate's lock and has checked the token; the update is still ONE statement naming the state it read.
	 *
	 * @return array|null {kind, state, entry, back}; null = the row moved since it was read, or there is nothing to
	 *                    take back. back = the earlier ask an ask's take-back brought back (its row), else null.
	 */
	public static function undo( array $row, int $by ): ?array {
		global $wpdb;
		$kind = self::undo_kind( $row );
		if ( '' === $kind || $by <= 0 ) {
			return null;
		}
		$now   = current_time( 'mysql', true );
		$asked = (int) $row['sent_by'] > 0;
		$tries = self::tries_of( $row );
		$entry = array( 'at' => $now, 'by' => $by, 'kind' => $kind );
		if ( 'answer' === $kind ) {
			$entry += array(
				'answer'   => (string) $row['answer'],
				'outcome'  => (string) $row['outcome'],
				'channel'  => (string) $row['channel'],
				'note'     => (string) ( $row['answer_note'] ?? '' ),
				'who'      => (int) $row['answered_by'],
				'when'     => (string) ( $row['answered_at'] ?? '' ),
				'declined' => empty( $row['declined'] ) ? 0 : 1,
				'reopened' => empty( $row['reopened_at'] ) ? 0 : 1,
				'doc_line' => 'written' === ( $row['doc_state'] ?? '' ) ? (string) ( $row['doc_line'] ?? '' ) : '',
			);
		} elseif ( 'try' === $kind ) {
			$last   = $tries ? array_pop( $tries ) : array();
			$entry += array(
				'answer'  => 'tried',
				'channel' => (string) ( $last['channel'] ?? $row['channel'] ),
				'note'    => (string) ( $last['note'] ?? '' ),
				'who'     => (int) ( $last['by'] ?? $row['answered_by'] ),
				'when'    => (string) ( $last['at'] ?? ( $row['answered_at'] ?? '' ) ),
			);
		} else {
			$entry += array( 'to' => (int) $row['sent_to'], 'who' => (int) $row['sent_by'], 'when' => (string) $row['sent_at'] );
		}
		// An ask taken back brings back the ask it replaced: ask() supersedes the waiting row and inserts the new one
		// next, so a superseded row directly before this one was replaced by this very ask. Asks already taken back in
		// between are stepped over: only they can sit there, because an answer goes onto the waiting row.
		$back = null;
		if ( 'ask' === $kind ) {
			$prev = $wpdb->get_row( $wpdb->prepare(
				'SELECT * FROM ' . self::table() . " WHERE estimate_id = %d AND id < %d AND state <> 'withdrawn' ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name (self::table())
				(int) $row['estimate_id'], (int) $row['id']
			), ARRAY_A );
			if ( is_array( $prev ) && 'superseded' === $prev['state'] ) {
				$back                  = $prev;
				$entry['brought_back'] = (int) $prev['id'];
			}
		}
		// What the row says once this is taken back.
		if ( 'ask' !== $kind && $tries ) {
			$last = $tries[ count( $tries ) - 1 ];
			$to   = array( 'state' => 'tried', 'answer' => 'tried', 'channel' => (string) ( $last['channel'] ?? '' ), 'answered_by' => (int) ( $last['by'] ?? 0 ), 'answered_at' => (string) ( $last['at'] ?? '' ) );
		} else {
			$to = array( 'state' => ( 'ask' !== $kind && $asked ) ? 'open' : 'withdrawn', 'answer' => '', 'channel' => '', 'answered_by' => 0, 'answered_at' => '' );
		}
		$log   = self::undone_of( $row );
		$log[] = $entry;
		$log   = array_slice( $log, -self::UNDO_KEEP ); // an entry can hold a long note; the column is not endless
		$sql   = 'UPDATE ' . self::table() . " SET state = %s, answer = %s, outcome = '', channel = %s, answer_note = '', try_notes = %s, declined = 0, answered_by = %d,"
			. ' answered_at = ' . ( '' === $to['answered_at'] ? 'NULL' : '%s' ) . ", tries = %d, reopened_by = 0, reopened_at = NULL, doc_line = '', doc_state = '', doc_why = '', doc_prev = '', doc_on = '',"
			. ' undo_log = %s, undone = undone + 1'
			. ' WHERE id = %d AND state = %s AND tries = %d AND declined = %d AND undone = %d';
		$n     = $wpdb->query( $wpdb->prepare(
			$sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built above from fixed text, the table name ($wpdb->prefix plus a fixed name) and placeholders only
			array_merge(
				array( $to['state'], $to['answer'], $to['channel'], $tries ? wp_json_encode( $tries ) : '', $to['answered_by'] ),
				'' === $to['answered_at'] ? array() : array( $to['answered_at'] ),
				array( count( $tries ), wp_json_encode( $log ), (int) $row['id'], (string) $row['state'], (int) $row['tries'], empty( $row['declined'] ) ? 0 : 1, (int) ( $row['undone'] ?? 0 ) )
			)
		) );
		if ( ! $n ) {
			return null;
		}
		if ( $back ) {
			$ok = $wpdb->query( $wpdb->prepare(
				'UPDATE ' . self::table() . " SET state = %s WHERE id = %d AND state = 'superseded'", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name (self::table())
				(int) $back['tries'] > 0 ? 'tried' : 'open', (int) $back['id']
			) );
			$back = $ok ? self::get( (int) $back['id'] ) : null;
		}
		return array( 'kind' => $kind, 'state' => $to['state'], 'entry' => $entry, 'back' => $back );
	}

	/**
	 * Take ONE note that has not gone to the CRM yet off the row's queue: the last one that starts with $start (a
	 * note's first two lines: its heading, then the estimate, who and when). For a follow-up taken back before its
	 * note was posted, so words typed on the wrong estimate never reach that customer's lead. False = no such note is
	 * waiting (it has gone, or is going right now). The caller holds the lock.
	 */
	public static function drop_note( int $id, string $start ): bool {
		global $wpdb;
		if ( '' === $start ) {
			return false;
		}
		for ( $i = 0; $i < 5; $i++ ) {
			$r = self::get( $id );
			if ( ! $r ) {
				return false;
			}
			$raw = (string) ( $r['crm_queue'] ?? '' );
			$q   = self::queue_of( $r );
			$at  = -1;
			foreach ( $q as $k => $text ) {
				if ( 0 === strpos( $text, $start ) ) {
					$at = $k;
				}
			}
			if ( $at < 0 ) {
				return false;
			}
			array_splice( $q, $at, 1 );
			$n = $wpdb->query( $wpdb->prepare(
				'UPDATE ' . self::table() . ' SET crm_queue = %s, crm_state = %s WHERE id = %d AND crm_queue = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name (self::table())
				$q ? wp_json_encode( $q ) : '', $q ? (string) $r['crm_state'] : '', $id, $raw
			) );
			if ( $n ) {
				return true;
			}
		}
		return false;
	}

	/** A declined estimate opened again: the row stays, its declined mark comes off. False = it was not declined any more. */
	public static function reopen( array $row, int $by ): bool {
		global $wpdb;
		$n = $wpdb->query( $wpdb->prepare(
			'UPDATE ' . self::table() . " SET declined = 0, reopened_by = %d, reopened_at = %s WHERE id = %d AND state = 'done' AND declined = 1", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name (self::table())
			$by, current_time( 'mysql', true ), (int) $row['id']
		) );
		return (bool) $n;
	}

	/** Keep what happened to the billing line, the CRM note or the contact log on the row. */
	public static function mark( int $id, array $fields ): void {
		global $wpdb;
		$ok = array_intersect_key( $fields, array_flip( array( 'doc_line', 'doc_state', 'doc_why', 'doc_prev', 'doc_on', 'crm_state', 'crm_tries', 'contact_id' ) ) );
		if ( $id > 0 && $ok ) {
			if ( isset( $ok['doc_why'] ) ) {
				$ok['doc_why'] = preg_match( '/\A[a-z_]{1,24}(?::\d{1,4})?\z/', (string) $ok['doc_why'] ) ? (string) $ok['doc_why'] : '';
			}
			$wpdb->update( self::table(), $ok, array( 'id' => $id ) );
		}
	}

	/* ================================================================ the CRM notes still to post */

	/** The notes waiting to go to the lead, oldest first. */
	public static function queue_of( array $r ): array {
		$q = json_decode( (string) ( $r['crm_queue'] ?? '' ), true );
		return is_array( $q ) ? array_values( array_filter( $q, 'is_string' ) ) : array();
	}

	/**
	 * Add one note (already worded) to the row's queue. Each answer adds its own, so a try followed a minute later
	 * by a reach leaves two notes to post, never one overwriting the other. The caller holds the estimate's lock.
	 */
	public static function queue_note( int $id, string $text ): bool {
		global $wpdb;
		$r = self::get( $id );
		if ( ! $r || '' === trim( $text ) ) {
			return false;
		}
		$q   = self::queue_of( $r );
		$q[] = $text;
		return false !== $wpdb->update( self::table(), array( 'crm_queue' => wp_json_encode( $q ), 'crm_state' => 'queued', 'crm_tries' => 0 ), array( 'id' => $id ) );
	}

	/**
	 * Take every waiting note off the row (so no second run can post them too) and return them. The swap is one
	 * statement that names the queue it read: if another answer added a note in between, nothing is taken and the
	 * caller tries again.
	 *
	 * @return string[]|null the notes; null = the queue moved, try again
	 */
	public static function take_notes( int $id ): ?array {
		global $wpdb;
		$r = self::get( $id );
		if ( ! $r ) {
			return array();
		}
		$raw = (string) ( $r['crm_queue'] ?? '' );
		$q   = self::queue_of( $r );
		if ( ! $q ) {
			return array();
		}
		$n = $wpdb->query( $wpdb->prepare(
			'UPDATE ' . self::table() . " SET crm_queue = '', crm_state = 'posting' WHERE id = %d AND crm_queue = %s", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name (self::table())
			$id, $raw
		) );
		return $n ? $q : null;
	}

	/** Put notes that did not go back at the FRONT of the queue (in front of any added meanwhile), and say how it stands. */
	public static function put_back( int $id, array $texts, string $state, int $tries ): void {
		global $wpdb;
		for ( $i = 0; $i < 5; $i++ ) {
			$r = self::get( $id );
			if ( ! $r ) {
				return;
			}
			$raw = (string) ( $r['crm_queue'] ?? '' );
			$q   = array_merge( array_values( $texts ), self::queue_of( $r ) );
			$n   = $wpdb->query( $wpdb->prepare(
				'UPDATE ' . self::table() . ' SET crm_queue = %s, crm_state = %s, crm_tries = %d WHERE id = %d AND ( crm_queue = %s OR ( crm_queue IS NULL AND %s = \'\' ) )', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name (self::table())
				$q ? wp_json_encode( $q ) : '', $state, $tries, $id, $raw, $raw
			) );
			if ( $n ) {
				return;
			}
		}
	}

	/** After a run that posted everything it took: "posted", unless another answer queued a note meanwhile. */
	public static function settle_notes( int $id, string $state ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare(
			'UPDATE ' . self::table() . " SET crm_state = %s, crm_tries = 0 WHERE id = %d AND crm_state = 'posting'", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name (self::table())
			$state, $id
		) );
	}
}
