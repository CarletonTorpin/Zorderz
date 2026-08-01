<?php
/**
 * Zorderz Surveys — settings + identity resolution.
 *
 * Everything that would differ between businesses lives here as configuration, read
 * from a Core service or a tenant option/filter, never hardcoded:
 *
 *   - the SURVEY OPERATOR (a Party user + a configurable author-match pattern),
 *   - the company EXCLUSION list (admin-managed, EMPTY by default),
 *   - the REVIEW DESTINATION + routing rule (from Business Profile),
 *   - the lifecycle RULES (grace windows, resurvey/cooldown) + the SAFETY FLOOR,
 *   - the WORK-CATEGORY vocabulary (Item Engine filter, NEUTRAL fallback),
 *   - the status-CLASSIFIER vocabulary (generic, filterable),
 *   - the SENDER identity (Business Profile).
 *
 * @package Zorderz\Surveys
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSV_Settings {

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
	 * Sender identity — from Business Profile, never a hardcoded from-name.
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
	 * The SURVEY OPERATOR — the generalized replacement for the one baked-in
	 * operator whose first name named two DB columns and an author-substring test.
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
	 * operator's first name (lowercased) so a tenant that only sets the operator
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
	 * name; if that is blank too, no author qualifies (the tenant must configure an
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
	 * Company EXCLUSION list — admin-managed, EMPTY by default. Replaces the
	 * TS_EXCLUDED_COMPANIES constant that shipped real client names.
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * @return string[] Lowercased name/email fragments to exclude from surveys.
	 *   Ships EMPTY; a tenant adds their own via Settings or the filter.
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
		 * Filter the survey exclusion list. NEUTRAL (empty) default — no business names
		 * are shipped.
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
	 * REVIEW DESTINATION + routing. Replaces the bespoke review chain
	 * (a hardcoded review deep-link + a second star-rating page + an
	 * address-domain rule where the mailbox provider decided the flow).
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
	 * The review-routing rule. Was "the email domain is the SOLE factor" (one
	 * mailbox provider -> deep-link, everything else -> star page). Now a
	 * configurable rule:
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
	 * 'auto' rule. NEUTRAL (empty) default — a tenant opts a provider in explicitly,
	 * so no address family decides anything until configured.
	 *
	 * @return string[] Lowercased email domains a tenant opts in (e.g. a large
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
	 * custom page (e.g. a "no primary account" outcome), mirroring the old no_google
	 * override — but expressed as a status the tenant classifier owns, not a domain.
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

		// A tenant-declared "no primary review account" outcome always uses the custom
		// page (was the no_google override).
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
	 * WORK-CATEGORY vocabulary — Item Engine binding with a NEUTRAL fallback.
	 * Replaces the TS_TAG_PRIORITY constant (a product taxonomy) and the
	 * description-refiner fallback string ('your recent screen service').
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Priority-ordered work categories used to tag a lead and pick a pipeline.
	 *
	 * Item Engine binding: the real taxonomy arrives via `zdz_survey_work_categories`
	 * (the shared Item Engine is not built yet, so this is the documented seam). The
	 * NEUTRAL fallback carries only generic service words — NO product name.
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
	 * Neutral, tenant-overridable description used when a line item has no readable
	 * work text. Replaces the hardcoded 'your recent screen service'.
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
	 * STATUS-CLASSIFIER vocabulary — generic, filterable. Replaces the ~110
	 * hardcoded phrase patterns tuned to one operator's note style.
	 *
	 * The closed outcome set is platform-owned; only the PHRASES that map onto it
	 * are tenant vocabulary. All lists are neutral by default.
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * @return array<string,string[]> outcome => phrase list. Lowercase phrases.
	 *   Outcomes: issue (-> needs_attention/follow_up), voicemail (-> left_vm),
	 *   no_review (-> no_online_review), no_primary_account (-> custom page),
	 *   satisfied (-> satisfied). Everything else defaults to needs_attention:
	 *   an invite must be EARNED by a recognised positive signal.
	 */
	public static function status_vocabulary(): array {
		$default = array(
			'issue'     => array( 'not working', "isn't working", 'still broken', 'problem', 'issue', 'not fixed', 'unhappy', 'complaint', 'call back', 'callback', 'follow up', 'waiting for', 'needs', 'redo', 'come back' ),
			'voicemail' => array( 'left vm', 'left voicemail', 'voicemail', 'no answer', 'did not answer', "didn't answer", 'not home', 'no response', 'called, no' ),
			'no_review' => array( 'no online review', 'no review', "won't review", 'declined review', 'do not send', "don't send", 'no thanks' ),
			'no_primary_account' => array( 'no google', 'no google account', 'not on google', 'send the other', 'use the other link' ),
			'satisfied' => array( 'satisfied', 'happy', 'working fine', 'works great', 'all good', 'great', 'good', 'fine', 'no problems', 'no issues', 'send review', 'send link', 'send google' ),
		);
		$vocab = apply_filters( 'zsv_status_vocabulary', $default );
		return is_array( $vocab ) && ! empty( $vocab ) ? $vocab : $default;
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

	/** @return string[] Negators that flip a following positive phrase (issue, not satisfaction). */
	public static function negation_words(): array {
		return array( 'not', "isn't", 'isnt', "wasn't", 'wasnt', 'no', 'never', "doesn't", 'doesnt', "won't", 'wont', "can't", 'cant', 'without' );
	}
}
