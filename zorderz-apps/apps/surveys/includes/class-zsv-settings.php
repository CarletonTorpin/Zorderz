<?php
/**
 * Zorderz Surveys — settings + identity resolution.
 *
 * Everything that would differ between businesses lives here as configuration, read
 * from a Core service or the business's option/filter:
 *
 *   - the SURVEY OPERATOR (a Party user + a configurable author-match pattern),
 *   - the company EXCLUSION list (admin-managed, EMPTY by default),
 *   - the REVIEW DESTINATION + routing rule (from Business Profile),
 *   - the lifecycle RULES (grace windows, resurvey/cooldown) + the SAFETY FLOOR,
 *   - the WORK-CATEGORY vocabulary (Item Engine filter, generic fallback),
 *   - the status-CLASSIFIER vocabulary (generic, filterable),
 *   - the SENDER identity (Business Profile).
 *
 * @package Zorderz\Surveys
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSV_Settings {

	/**
	 * A moment as the business writes it, for the notes Surveys leaves in the CRM: the site's own date and time formats
	 * (Settings → General) in the site's zone. "M j, Y" and "g:i A" when the site has none. Nothing reads it back.
	 */
	public static function stamp( ?int $ts = null ): string {
		$df = function_exists( 'get_option' ) ? trim( (string) get_option( 'date_format', '' ) ) : '';
		$tf = function_exists( 'get_option' ) ? trim( (string) get_option( 'time_format', '' ) ) : '';
		$f  = ( '' !== $df ? $df : 'M j, Y' ) . ' ' . ( '' !== $tf ? $tf : 'g:i A' );
		return function_exists( 'wp_date' ) ? (string) wp_date( $f, $ts ) : gmdate( $f, null === $ts ? time() : $ts );
	}

	/** A short day for a list ("Nov 12" in English; the format is translatable, so a language may put the day first). */
	public static function short_day( int $ts ): string {
		/* translators: a PHP date format for a short day: month and day ("M j" gives "Nov 12"; "j M" gives "12 Nov") */
		$f = function_exists( '_x' ) ? (string) _x( 'M j', 'short day format', 'zorderz' ) : 'M j';
		return function_exists( 'wp_date' ) ? (string) wp_date( $f, $ts ) : gmdate( $f, $ts );
	}

	/** A short day and the time of day, in the site's time format ("g:i A" when the site has none). */
	public static function short_stamp( int $ts ): string {
		$tf = function_exists( 'get_option' ) ? trim( (string) get_option( 'time_format', '' ) ) : '';
		$tf = '' !== $tf ? $tf : 'g:i A';
		return self::short_day( $ts ) . ', ' . ( function_exists( 'wp_date' ) ? (string) wp_date( $tf, $ts ) : gmdate( $tf, $ts ) );
	}

	/* ─────────────────────────────────────────────────────────────────
	 * Core-service accessors (Connections layer). Fail loudly (return null)
	 * rather than fabricate a client from copied credentials.
	 * ────────────────────────────────────────────────────────────────── */

	/** @return object|null Billing provider client, or null when unavailable/unconfigured. */
	public static function billing() {
		if ( ! class_exists( 'ZDZ_Core_Freshbooks' ) ) {
			return null;
		}
		$fb = new ZDZ_Core_Freshbooks();
		return $fb->is_configured() ? $fb : null;
	}

	/** @return object|null CRM client, or null when unavailable/unconfigured. */
	public static function crm() {
		if ( ! class_exists( 'ZDZ_Core_Nutshell' ) ) {
			return null;
		}
		$ns = new ZDZ_Core_Nutshell();
		return $ns->is_configured() ? $ns : null;
	}

	/** @return object|null AI client, or null when unavailable. */
	public static function ai() {
		if ( ! class_exists( 'ZDZ_Core_Poe' ) ) {
			return null;
		}
		$model = (string) get_option( 'zsv_ai_model', '' );
		return new ZDZ_Core_Poe( '', $model );
	}

	/** @return object|null Review-bridge client, or null when unavailable/unconfigured. */
	public static function review_bridge() {
		if ( ! class_exists( 'ZDZ_Core_ReviewBridge' ) ) {
			return null;
		}
		$b = new ZDZ_Core_ReviewBridge();
		return $b->is_configured() ? $b : null;
	}

	/* ─────────────────────────────────────────────────────────────────
	 * Sender identity — from the Business Profile.
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * @return array{email:string,name:string} The 'surveys' sending identity, with a
	 *   graceful fall back to the site's default sender / admin email.
	 */
	public static function sender(): array {
		if ( class_exists( 'ZDZ_Business_Profile' ) ) {
			$s = ZDZ_Business_Profile::sender( 'surveys' );
			if ( is_array( $s ) && ! empty( $s['email'] ) ) {
				return array( 'email' => (string) $s['email'], 'name' => (string) ( $s['name'] ?? '' ) );
			}
		}
		return array(
			'email' => (string) get_option( 'admin_email' ),
			'name'  => (string) get_bloginfo( 'name' ),
		);
	}

	/* ─────────────────────────────────────────────────────────────────
	 * The SURVEY OPERATOR: who makes the survey calls is a setting.
	 *
	 * An operator is: (a) a configurable Party USER (the person who works the
	 * survey calls and whose CRM-logged notes we read), and (b) a configurable
	 * author-MATCH pattern used to recognise that operator's notes in the CRM.
	 * ────────────────────────────────────────────────────────────────── */

	/** @return int The operator's WP/Party user id (0 = unset). */
	public static function operator_user_id(): int {
		return (int) get_option( 'zsv_operator_user_id', 0 );
	}

	/** @return string The operator's configured display name (may be blank). */
	public static function operator_name(): string {
		$name = trim( (string) get_option( 'zsv_operator_name', '' ) );
		if ( '' === $name ) {
			$uid = self::operator_user_id();
			if ( $uid > 0 ) {
				$u = get_userdata( $uid );
				if ( $u ) {
					$name = $u->display_name;
				}
			}
		}
		return $name;
	}

	/**
	 * The case-insensitive substring used to recognise the operator's authored
	 * activities/notes in the CRM. Configurable via option/filter; defaults to the
	 * operator's first name (lowercased) so a business that only sets the operator
	 * user gets sensible matching without a second field. Empty = match nothing
	 * (i.e. treat every reached note as the operator's — see is_operator_author()).
	 *
	 * @return string Lowercased match fragment, or '' when unconfigured.
	 */
	public static function operator_match_pattern(): string {
		$pat = strtolower( trim( (string) get_option( 'zsv_operator_match', '' ) ) );
		if ( '' === $pat ) {
			$name = self::operator_name();
			if ( '' !== $name ) {
				$parts = preg_split( '/\s+/', $name );
				$pat   = strtolower( (string) ( $parts[0] ?? '' ) );
			}
		}
		/**
		 * Filter the operator author-match fragment.
		 *
		 * @param string $pat Lowercased substring matched against a CRM note author.
		 */
		return (string) apply_filters( 'zsv_operator_match_pattern', $pat );
	}

	/**
	 * Does this author string belong to the configured survey operator?
	 *
	 * When a match pattern is configured, match on it (case-insensitive substring).
	 * When NONE is configured, fall back to matching the operator's full display
	 * name; if that is blank too, no author qualifies (the business must configure an
	 * operator before operator-note sync does anything) — we never guess.
	 *
	 * @param string $author Author/display name from a CRM activity or note.
	 * @return bool
	 */
	public static function is_operator_author( string $author ): bool {
		$author = strtolower( trim( $author ) );
		if ( '' === $author ) {
			return false;
		}
		$pat = self::operator_match_pattern();
		if ( '' !== $pat ) {
			return strpos( $author, $pat ) !== false;
		}
		$name = strtolower( self::operator_name() );
		return ( '' !== $name && strpos( $author, $name ) !== false );
	}

	/**
	 * The CRM user stub to auto-assign new survey leads to (the operator).
	 * Explicit numeric CRM user id wins; otherwise the operator name is resolved by
	 * the caller against the CRM roster. Never blocks lead creation.
	 *
	 * @return int CRM user id, or 0 when unset (name-resolution handled by the CRM adapter).
	 */
	public static function operator_crm_user_id(): int {
		return (int) get_option( 'zsv_operator_crm_user_id', 0 );
	}

	/* ─────────────────────────────────────────────────────────────────
	 * Company EXCLUSION list — admin-managed, EMPTY by default; no client
	 * names ship with the app.
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * @return string[] Lowercased name/email fragments to exclude from surveys.
	 *   Ships EMPTY; a business adds its own via Settings or the filter.
	 */
	public static function excluded_companies(): array {
		$raw = get_option( 'zsv_excluded_companies', array() );
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/[\r\n,]+/', $raw );
		}
		$out = array();
		foreach ( (array) $raw as $frag ) {
			$frag = strtolower( trim( (string) $frag ) );
			if ( '' !== $frag ) {
				$out[] = $frag;
			}
		}
		/**
		 * Filter the survey exclusion list. Empty by default.
		 *
		 * @param string[] $out Lowercased match fragments.
		 */
		return (array) apply_filters( 'zsv_excluded_companies', $out );
	}

	/** @return string The "do not survey" opt-out tag name (a CRM Mapping, settings-driven). */
	public static function do_not_survey_tag(): string {
		return trim( (string) get_option( 'zsv_do_not_survey_tag', 'Do Not Survey' ) );
	}

	/* ─────────────────────────────────────────────────────────────────
	 * REVIEW DESTINATION + routing: the review links come from the Business
	 * Profile, and settings decide which one a customer is sent.
	 * ────────────────────────────────────────────────────────────────── */

	/** @return string Primary (deep-link) review URL, from Business Profile. */
	public static function review_url_primary(): string {
		if ( class_exists( 'ZDZ_Business_Profile' ) ) {
			$u = (string) ZDZ_Business_Profile::get( 'web.review_google', '' );
			if ( '' !== $u ) {
				return $u;
			}
		}
		return (string) get_option( 'zsv_review_url_primary', '' );
	}

	/** @return string Custom (star-rating page) review URL, from Business Profile. */
	public static function review_url_custom(): string {
		if ( class_exists( 'ZDZ_Business_Profile' ) ) {
			$u = (string) ZDZ_Business_Profile::get( 'web.review_page', '' );
			if ( '' !== $u ) {
				return $u;
			}
		}
		return (string) get_option( 'zsv_review_url_custom', '' );
	}

	/**
	 * The review-routing rule:
	 *   'auto'        — deep-link for the configured domains, custom page otherwise;
	 *   'primary_only'— always the primary review URL;
	 *   'custom_only' — always the custom review URL;
	 *   'off'         — no review link at all.
	 *
	 * @return string
	 */
	public static function review_routing(): string {
		$mode  = (string) get_option( 'zsv_review_routing', 'auto' );
		$valid = array( 'auto', 'primary_only', 'custom_only', 'off' );
		return in_array( $mode, $valid, true ) ? $mode : 'auto';
	}

	/**
	 * Email domains that receive the PRIMARY (deep-link) review destination under the
	 * 'auto' rule. Empty by default — a business opts a provider in explicitly, so no
	 * address family decides anything until configured.
	 *
	 * @return string[] Lowercased email domains the business opts in (e.g. a large
	 *   mailbox provider's domain).
	 */
	public static function review_deeplink_domains(): array {
		$raw = get_option( 'zsv_review_deeplink_domains', array() );
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/[\r\n,\s]+/', $raw );
		}
		$out = array();
		foreach ( (array) $raw as $d ) {
			$d = strtolower( ltrim( trim( (string) $d ), '@' ) );
			if ( '' !== $d ) {
				$out[] = $d;
			}
		}
		return (array) apply_filters( 'zsv_review_deeplink_domains', $out );
	}

	/**
	 * Resolve the review destination for a recipient. `operator_status` may force the
	 * custom page (e.g. a "no primary account" outcome): a status the business's note
	 * vocabulary decides, never a mail domain.
	 *
	 * @param string $email           Recipient email.
	 * @param string $operator_status Parsed operator status for this lead.
	 * @return array{url:string,channel:string} channel ∈ deep_link|custom|none.
	 */
	public static function resolve_review_destination( string $email, string $operator_status = '' ): array {
		$mode = self::review_routing();
		if ( 'off' === $mode ) {
			return array( 'url' => '', 'channel' => 'none' );
		}

		$primary = self::review_url_primary();
		$custom  = self::review_url_custom();

		// A "no primary review account" outcome always uses the custom page.
		if ( 'no_primary_account' === $operator_status && '' !== $custom ) {
			return array( 'url' => $custom, 'channel' => 'custom' );
		}

		if ( 'primary_only' === $mode ) {
			return $primary !== '' ? array( 'url' => $primary, 'channel' => 'deep_link' ) : array( 'url' => $custom, 'channel' => 'custom' );
		}
		if ( 'custom_only' === $mode ) {
			return $custom !== '' ? array( 'url' => $custom, 'channel' => 'custom' ) : array( 'url' => $primary, 'channel' => 'deep_link' );
		}

		// 'auto': deep-link for configured domains, custom page otherwise.
		$domain = strtolower( substr( strrchr( strtolower( trim( $email ) ), '@' ) ?: '', 1 ) );
		if ( '' !== $primary && $domain !== '' && in_array( $domain, self::review_deeplink_domains(), true ) ) {
			return array( 'url' => $primary, 'channel' => 'deep_link' );
		}
		if ( '' !== $custom ) {
			return array( 'url' => $custom, 'channel' => 'custom' );
		}
		return $primary !== '' ? array( 'url' => $primary, 'channel' => 'deep_link' ) : array( 'url' => '', 'channel' => 'none' );
	}

	/* ─────────────────────────────────────────────────────────────────
	 * LIFECYCLE RULES + the SAFETY FLOOR.
	 * ────────────────────────────────────────────────────────────────── */

	/** @return int Grace window (hours) for reached-but-callback outcomes (left_vm/needs_attention). */
	public static function grace_hours_callback(): int {
		return (int) apply_filters( 'zsv_grace_hours_callback', (int) get_option( 'zsv_grace_hours_callback', 120 ) );
	}

	/** @return int Grace window (hours) for untouched leads. */
	public static function grace_hours_default(): int {
		return (int) apply_filters( 'zsv_grace_hours_default', (int) get_option( 'zsv_grace_hours_default', 96 ) );
	}

	/** @return int Resurvey cooldown (days): a customer surveyed within this window is skipped. */
	public static function resurvey_cooldown_days(): int {
		return (int) apply_filters( 'zsv_resurvey_cooldown_days', (int) get_option( 'zsv_resurvey_cooldown_days', 365 ) );
	}

	/** @return int Billing fetch lookback window (days). */
	public static function fetch_lookback_days(): int {
		return (int) apply_filters( 'zsv_fetch_lookback_days', (int) get_option( 'zsv_fetch_lookback_days', 90 ) );
	}

	/** @return int Batch size (clamped 1..50). */
	public static function batch_size(): int {
		$n = (int) get_option( 'zsv_batch_size', 10 );
		return max( 1, min( 50, $n ) );
	}

	/**
	 * SAFETY FLOOR — the optional, off-by-default policy that lets the auto-closer
	 * close genuinely-satisfied leads as "Won" without a human. It NEVER reaches a
	 * status that still needs review (see ZSV_Survey_Manager::can_system_close_won);
	 * this flag only widens from "escalate everything" to "auto-close satisfied".
	 *
	 * @return bool Default FALSE.
	 */
	public static function allow_system_close_won(): bool {
		return (bool) apply_filters( 'zsv_allow_system_close_won', (bool) get_option( 'zsv_allow_system_close_won', false ) );
	}

	/* ─────────────────────────────────────────────────────────────────
	 * WORK-CATEGORY vocabulary — Item Engine binding with a plain fallback
	 * ('your recent service'); the app carries no product taxonomy.
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Priority-ordered work categories used to tag a lead and pick a pipeline.
	 *
	 * Item Engine binding: the real taxonomy arrives via `zdz_survey_work_categories`
	 * (the shared Item Engine is not built yet, so this is the documented seam). The
	 * fallback carries only generic service words.
	 *
	 * @return string[] Ordered, most-specific first.
	 */
	public static function work_categories(): array {
		$default = array(
			__( 'Repair', 'zorderz' ),
			__( 'Installation', 'zorderz' ),
			__( 'Service', 'zorderz' ),
			__( 'Other', 'zorderz' ),
		);
		$cats = apply_filters( 'zdz_survey_work_categories', $default );
		if ( ! is_array( $cats ) || empty( $cats ) ) {
			return $default;
		}
		$out = array();
		foreach ( $cats as $c ) {
			$c = trim( (string) $c );
			if ( '' !== $c ) {
				$out[] = $c;
			}
		}
		return $out ?: $default;
	}

	/**
	 * Plain, business-overridable description used when a line item has no readable
	 * work text.
	 *
	 * @return string
	 */
	public static function generic_work_phrase(): string {
		$phrase = (string) get_option( 'zsv_generic_work_phrase', '' );
		if ( '' === $phrase ) {
			$phrase = __( 'your recent service', 'zorderz' );
		}
		return (string) apply_filters( 'zdz_survey_generic_work_phrase', $phrase );
	}

	/* ─────────────────────────────────────────────────────────────────
	 * STATUS-CLASSIFIER vocabulary: filterable word lists. The closed outcome set
	 * is the platform's; the PHRASES that map onto it are the business's own.
	 * Core ships plain English defaults; a business replaces any list from its Identity
	 * Pack or the filters.
	 * ────────────────────────────────────────────────────────────────── */

	/** Where an Identity Pack (voice.yml, surveys.status_vocabulary) keeps this business's phrase lists. */
	const VOCABULARY_OPTION = 'zsv_status_vocabulary';

	/** Where an Identity Pack (voice.yml, surveys.note_reading) keeps how this business's notes are read. */
	const READING_OPTION = 'zsv_note_reading';

	/** The most phrases one list may hold, and the longest phrase. */
	const WORDS_MAX       = 200;
	const WORD_LENGTH_MAX = 80;

	/**
	 * A phrase list as the note reader uses it: lower case, single spaces, no empties, no
	 * duplicates, at most WORDS_MAX phrases of at most WORD_LENGTH_MAX characters. Pure.
	 *
	 * @param mixed $list
	 * @return string[]
	 */
	public static function clean_words( $list ): array {
		$out = array();
		foreach ( is_array( $list ) ? $list : array() as $w ) {
			if ( ! is_scalar( $w ) ) {
				continue;
			}
			$w = str_replace( array( "\xE2\x80\x99", "\xE2\x80\x98" ), "'", (string) $w ); // a typed curly apostrophe reads as a straight one, as in a note
			$w = trim( (string) preg_replace( '/\s+/', ' ', strtolower( $w ) ) );
			if ( '' !== $w && strlen( $w ) <= self::WORD_LENGTH_MAX ) {
				$out[ $w ] = true;
			}
			if ( count( $out ) >= self::WORDS_MAX ) {
				break;
			}
		}
		return array_map( 'strval', array_keys( $out ) );
	}

	/** Core's lists with this business's stored lists over them, one key at a time (a key not stored keeps Core's). */
	private static function with_stored( array $default, string $option ): array {
		$stored = get_option( $option, array() );
		if ( is_array( $stored ) ) {
			foreach ( $default as $k => $list ) {
				if ( isset( $stored[ $k ] ) && is_array( $stored[ $k ] ) ) {
					$default[ $k ] = self::clean_words( $stored[ $k ] );
				}
			}
		}
		return $default;
	}

	/**
	 * @return array<string,string[]> outcome => phrase list. Lowercase phrases.
	 *   Outcomes: issue (-> follow_up), voicemail (-> left_vm),
	 *   no_review (-> no_online_review), no_primary_account (-> custom page),
	 *   satisfied (-> satisfied), voicemail_full (-> left_vm, after satisfied),
	 *   attention (-> needs_attention: a person reads the note), and
	 *   send_instruction (the operator's explicit "send the review request" wording,
	 *   read in context by ZSV_Note_Reader). Everything else defaults to needs_attention:
	 *   an invite must be EARNED by a recognised positive signal.
	 */
	public static function status_vocabulary(): array {
		$default = array(
			'issue'     => array( 'not working', "isn't working", 'still broken', 'problem', 'issue', 'not fixed', 'unhappy', 'complaint', 'waiting for', 'needs', 'redo' ),
			'voicemail' => array( 'left vm', 'left voicemail', 'voicemail', 'no answer', 'did not answer', "didn't answer", 'not home', 'no response', 'called, no' ),
			'no_review' => array( 'no online review', 'no review', "won't review", 'declined review', 'do not send', "don't send", 'no thanks' ),
			'no_primary_account' => array( 'no google', 'no google account', 'not on google' ),
			'satisfied' => array( 'satisfied', 'happy', 'working fine', 'works great', 'all good', 'great', 'good', 'fine', 'no problems', 'no issues', 'send review', 'send link' ),
			// A full voice mailbox, however it is written: the customer was not reached (unless
			// the same note shows they were — a reached outcome is checked first).
			'voicemail_full' => array( 'vm full', 'vm is full', 'vm box full', 'voicemail full', 'voicemail is full', 'voice mail full', 'voice mail is full', 'mailbox full', 'mailbox is full', 'mailbox was full', 'voicemail box full', 'voicemail box is full', 'box is full' ),
			// A call still owed, a follow-up, somebody else to reach: a person reads the note
			// before anything is sent, unless the note also carries an explicit send instruction
			// and every one of these sits in an aside that only says the customer may call later.
			'attention' => array( 'follow up', 'followup', 'follow-up', 'call back', 'callback', 'call-back', 'reach out', 'try again', 'not available', 'come back', 'reschedule', 'schedule callback', 'will call', 'need to call', 'needs to call', 'get back to', 'check back' ),
			// The operator's explicit instruction to send the review request.
			'send_instruction' => array( 'send review', 'send the review', 'send link', 'send the link', 'send her the link', 'send him the link', 'send them the link', 'ok to send', 'ready to send', 'go ahead and send' ),
		);
		$default = self::with_stored( $default, self::VOCABULARY_OPTION );
		$vocab   = apply_filters( 'zsv_status_vocabulary', $default );
		return is_array( $vocab ) && ! empty( $vocab ) ? $vocab : $default;
	}

	/**
	 * How a call note's sentences and clauses are read (ZSV_Note_Reader). Word lists only: the
	 * reading itself is the platform's. English by default; a business whose operator writes
	 * differently, or in another language, keeps its own lists in an Identity Pack
	 * (voice.yml, surveys.note_reading) or on the `zsv_note_reading` filter.
	 *
	 *   titles, web_endings  a stop typed with no space after it still ends a sentence
	 *                        ("again.Send"), except after a title or before a web-address ending;
	 *   conditions           a send instruction with one of these in its own clause is not a clean
	 *                        instruction ("send the link if that is ok");
	 *   send_verbs, waits,   "send … after / once / when <something>" waits on that something and
	 *   waits_not            holds the e-mail, unless the next word is one of waits_not
	 *                        ("send it when you get a chance");
	 *   soft_words           the attention phrases that CAN be a soft aside (the customer may call
	 *                        the business later), and then only in a clause shaped
	 *                          <subject> <auxiliary> <call_back> [tail]     "she will call back if anything comes up"
	 *                          <bare_aside> [tail]                          "will call again"
	 *                          <subject> <reply> [tail]                     "he'll get back to us if needed"
	 *                        after an optional lead_in ("and", "but");
	 *   open_work            word beginnings that say work is not finished or money is in question.
	 *                        When an aside is excused, none of them may appear anywhere in the note.
	 *
	 * @return array<string,string[]>
	 */
	public static function note_reading(): array {
		$tails = array( 'if anything comes up', 'if anything else comes up', 'if needed', 'if any questions', 'anytime', 'any time', 'in the future' );
		foreach ( array( 'she', 'he', 'they' ) as $who ) {
			foreach ( array( 'needs', 'need' ) as $verb ) {
				$tails[] = "if $who $verb anything";
				$tails[] = "if $who $verb anything else";
			}
		}
		$default = array(
			'titles'      => array( 'mr', 'mrs', 'ms', 'dr', 'st', 'jr', 'sr' ),
			'web_endings' => array( 'com', 'net', 'org', 'edu', 'gov', 'us', 'io', 'co', 'app', 'uk', 'ca', 'info', 'biz' ),
			'conditions'  => array( 'if', 'unless' ),
			'send_verbs'  => array( 'send' ),
			'waits'       => array( 'after', 'once', 'when' ),
			'waits_not'   => array( 'you' ),
			'lead_ins'    => array( 'and', 'but', 'so', 'then' ),
			'soft_words'  => array( 'will call', 'call back', 'callback', 'call-back', 'get back to' ),
			'subjects'    => array( 'she', 'he', 'they', 'customer', 'client' ),
			'auxiliaries' => array( "'ll", 'will', 'may', 'might', 'can' ),
			'call_backs'  => array( 'call back', 'callback', 'call-back', 'call us', 'call us back', 'call again' ),
			'bare_asides' => array( 'will call again', 'will call us', 'may call again', 'may call us' ),
			'replies'     => array( "'ll get back to us", 'will get back to us' ),
			'tails'       => $tails,
			'open_work'   => array( 'install', 'schedul', 'appoint', 'repair', 'fix', 'replac', 'warrant', 'service', 'refund', 'balance', 'credit', 'bill', 'invoice', 'pay', 'dispute', 'part', 'remaining', 'rest of', 'other', 'second', 'another', 'order', 'quote', 'price', 'pricing', 'approv' ),
		);
		$default = self::with_stored( $default, self::READING_OPTION );
		$reading = apply_filters( 'zsv_note_reading', $default );
		if ( ! is_array( $reading ) ) {
			return $default;
		}
		foreach ( $default as $k => $list ) {
			$default[ $k ] = isset( $reading[ $k ] ) && is_array( $reading[ $k ] ) ? self::clean_words( $reading[ $k ] ) : $list;
		}
		return $default;
	}

	/** @return string[] Phrases that indicate an issue was RESOLVED (clears a follow-up). */
	public static function resolution_phrases(): array {
		$default = array(
			'fixed it', 'we fixed', 'already fixed', 'all fixed', 'was fixed', 'is fixed',
			'has been fixed', 'taken care of', 'took care of', 'is resolved', 'was resolved',
			'all resolved', 'replaced it', 'we replaced', 'all good now', 'all set now', 'squared away', 'sorted out',
		);
		return (array) apply_filters( 'zsv_resolution_phrases', $default );
	}

	/**
	 * @return string[] Negators: one of these shortly before a positive phrase or a send
	 *   instruction turns it round ("isn't working well", "don't send the link").
	 */
	public static function negation_words(): array {
		$default = array(
			'not', 'no', 'never', 'without', 'cannot',
			"don't", 'dont', "doesn't", 'doesnt', "didn't", 'didnt', "isn't", 'isnt', "aren't", 'arent',
			"wasn't", 'wasnt', "won't", 'wont', "can't", 'cant',
			'skip', 'avoid', 'stopped', 'quit', 'refused', 'refuses', 'declined',
		);
		$words = apply_filters( 'zsv_negation_words', $default );
		return is_array( $words ) ? self::clean_words( $words ) : $default;
	}

	/* ─────────────────────────────────────────────────────────────────
	 * GOOGLE REVIEWS — how far back a review is matched, and to whom.
	 * ────────────────────────────────────────────────────────────────── */

	/** @return int Days: a Google review older than this is never matched to a customer (1..365, default 60). */
	public static function greviews_lookback_days(): int {
		$n = (int) apply_filters( 'zsv_greviews_lookback_days', (int) get_option( 'zsv_greviews_lookback_days', 60 ) );
		return max( 1, min( 365, $n ) );
	}

	/**
	 * @return int Days: only a customer whose survey follow-up was opened at most this long before
	 *   the review can be the reviewer (1..730, default 120).
	 */
	public static function greviews_candidate_days(): int {
		$n = (int) apply_filters( 'zsv_greviews_candidate_days', (int) get_option( 'zsv_greviews_candidate_days', 120 ) );
		return max( 1, min( 730, $n ) );
	}
}
