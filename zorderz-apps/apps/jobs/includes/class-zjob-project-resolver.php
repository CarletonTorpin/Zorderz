<?php
/**
 * Zjob_Project_Resolver — the money-gated read resolver for a Project.
 *
 * gather() assembles the Project's panels from local sources, each tagged CLASS_WORK or CLASS_MONEY,
 * under one keystone rule:
 *
 *   RULE 1 — GATE BEFORE GATHERING. A CLASS_MONEY source, for a viewer without see_money, is set
 *   STATE_WITHHELD and `continue`d BEFORE its gather callback runs — so the figure is NEVER FETCHED.
 *   This is "never fetched" > "fetched then redacted": a hidden field is a request; an unfetched
 *   figure is the boundary. Do NOT "optimise" this into gather-then-filter.
 *
 * FOUR panel states, NEVER conflated:
 *   STATE_OK          the panel loaded and has content.
 *   STATE_EMPTY       the panel loaded and there is nothing yet — a FACT ("nothing there yet").
 *   STATE_WITHHELD    a money-class panel the viewer may not see — gated upstream, never fetched.
 *   STATE_UNAVAILABLE the panel could not load — a FAILURE. `empty` and `unavailable` are different
 *                     answers and must not be collapsed into one.
 *
 * EXTENSION SEAM — the `zdz_project_sources` filter. A third-party source that declares CLASS_MONEY
 * opts into the gate FOR FREE: "the author of a data source cannot forget to check." The two-class
 * split is the whole mechanism — the resolver body never grows a per-source money check.
 *
 * The billing wiring reads Zorderz's own ZEST_Billing (the committed three-state linkage source):
 * the FACT a billing document exists is CLASS_WORK (the linkage state — no figure); the AMOUNT is
 * CLASS_MONEY (the invoice total, fetched only inside the gate). Any photo/document URL a source
 * surfaces MUST go through ZDZ_User_Media::secure_url() (the token proxy) — never a raw uploads URL
 * (geo-PII). The built-in sources surface counts only, so no raw URL is emitted here.
 *
 * WHAT THE ESTIMATE SAYS IN WORDS is CLASS_WORK too (src_said): the estimate's notes, and what the salesperson
 * wrote or said to make it, whole. Whoever orders or installs the work reads them to the end, so up to SAID_MAX
 * characters of each are carried, counted in characters, and a note that reaches the bound ends with a plain
 * sentence saying where the rest is. STILL NO MONEY: every amount is taken out of every string first
 * (without_amounts()), because people without money access read this panel; and every access code is cut before
 * anything is clipped.
 *
 * EXISTENCE ORACLE closed: a missing project and a REL_NONE viewer return the SAME single literal
 * refusal, so two responses cannot enumerate ids.
 *
 * Ships EMPTY: names no company/person/product/place/provider; seeds nothing.
 *
 * @package Zorderz\Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-zjob-project.php';
require_once __DIR__ . '/class-zjob-project-visibility.php';

if ( ! class_exists( 'Zjob_Project_Resolver' ) ) {

	class Zjob_Project_Resolver {

		/** Source data classes. Declaring CLASS_MONEY opts a source into the gate for free. */
		const CLASS_WORK  = 'work';
		const CLASS_MONEY = 'money';

		/** The four — and only four — panel states. Constants so a value is never typed twice. */
		const STATE_OK          = 'ok';          // loaded, has content.
		const STATE_EMPTY       = 'empty';       // loaded, nothing yet (a fact).
		const STATE_WITHHELD    = 'withheld';    // money-class, gated upstream (never fetched).
		const STATE_UNAVAILABLE = 'unavailable'; // could not load (a failure).

		/** The valid states a gathered source may return (withheld is only ever set by the gate). */
		const GATHER_STATES = array( self::STATE_OK, self::STATE_EMPTY, self::STATE_WITHHELD, self::STATE_UNAVAILABLE );

		/**
		 * The most of an estimate's notes, or of what the salesperson wrote or said, that one opened Project carries, in
		 * characters. It is only there so one pasted document cannot make a reply unbounded; a note that reaches it says
		 * so in words (said_more()).
		 */
		const SAID_MAX = 60000;

		/** How many of a Project's estimates the words are read from (the first recorded first); the rest are counted. */
		const SAID_ESTIMATES = 4;

		/**
		 * A text many times SAID_MAX is not read to its end. Where the reading stopped can be the middle of an amount or
		 * of an access code, which is then not seen as one: so this much of the end of what was read is left off, in
		 * bytes.
		 */
		const SAID_EDGE = 400;

		/* ===================================================================
		 * GATHER — the money-gated panel assembly.
		 * =================================================================== */

		/**
		 * Resolve a Project's panels for a viewer.
		 *
		 * @param int    $viewer     WP user id.
		 * @param string $project_id the project work_item id.
		 * @return array Either the single literal refusal { ok:false, reason } (missing project OR a
		 *               REL_NONE viewer), or { ok:true, project_id, human_code, status, relationship,
		 *               see_money, panels:{ id => { id, class, state, data } } }.
		 */
		public static function gather( int $viewer, string $project_id ): array {
			$project = class_exists( 'Zjob_Project' ) ? Zjob_Project::get( $project_id ) : null;
			$rel     = ( is_array( $project ) )
				? Zjob_Project_Visibility::relationship( $viewer, $project )
				: Zjob_Project_Visibility::REL_NONE;

			// Existence oracle closed: missing project AND no-relationship return the SAME refusal.
			if ( ! is_array( $project ) || Zjob_Project_Visibility::REL_NONE === $rel ) {
				return array( 'ok' => false, 'reason' => Zjob_Project::REFUSAL );
			}

			// The ONE money decision, made once, upstream of every source (RULE 1 depends on it).
			$see_money = Zjob_Project_Visibility::decide_money( $viewer, $project );

			$panels = array();
			foreach ( self::sources( $viewer, $project ) as $src ) {
				$sid = $src['id'];
				$cls = $src['class'];

				// RULE 1 — GATE BEFORE GATHERING. A money-class source for a viewer without see_money
				// is withheld and skipped HERE, so its gather callback never runs and the figure is
				// never fetched. This `continue` is the security keystone of the whole subsystem.
				if ( self::CLASS_MONEY === $cls && ! $see_money ) {
					$panels[ $sid ] = array(
						'id'    => $sid,
						'class' => $cls,
						'state' => self::STATE_WITHHELD,
						'data'  => null,
					);
					continue;
				}

				try {
					$res = call_user_func( $src['gather'], $viewer, $project, $see_money );
				} catch ( \Throwable $e ) {
					// "could not load" is a FAILURE — distinct from "nothing yet".
					$panels[ $sid ] = array(
						'id'    => $sid,
						'class' => $cls,
						'state' => self::STATE_UNAVAILABLE,
						'data'  => null,
					);
					continue;
				}
				$panels[ $sid ] = self::normalize_panel( $sid, $cls, $res );
			}

			return array(
				'ok'           => true,
				'project_id'   => (string) $project['id'],
				'human_code'   => (string) ( $project['human_code'] ?? '' ),
				'status'       => (string) ( $project['state'] ?? '' ),
				'relationship' => $rel,
				'see_money'    => $see_money,
				'customer'     => isset( $project['customer'] ) ? $project['customer'] : null,
				'panels'       => $panels,
			);
		}

		/**
		 * Route any photo/document URL through the token proxy. A source that surfaces media MUST call
		 * this — never a raw uploads URL (geo-PII: a customer address/name/measurement must not reach a
		 * public URL). Returns '' when the media store is unavailable (fail loud, never a raw fallback).
		 *
		 * @param array  $media_row a ZDZ_User_Media row.
		 * @param string $size
		 * @return string
		 */
		public static function secure_media_url( array $media_row, string $size = 'full' ): string {
			if ( ! class_exists( 'ZDZ_User_Media' ) || ! method_exists( 'ZDZ_User_Media', 'secure_url' ) ) {
				return '';
			}
			return (string) ZDZ_User_Media::secure_url( $media_row, $size );
		}

		/**
		 * THE PER-COMPONENT GATE (B5). Given the project's child job ROWS, return the ids of the jobs
		 * this viewer could open in Jobs — nothing else may contribute assets to the Record. Runs
		 * ZJOB_Jobs::actor_can_manage() per row BEFORE any asset read. This door and
		 * Zjob_Project_Visibility::relationship() answer DIFFERENT questions (open THIS job vs. see the
		 * project at all) over the SAME authority; they must never be allowed to diverge — a leak here
		 * is exactly the disclosure the subsystem exists to prevent.
		 *
		 * @param array $jobs   job rows (each an assoc array with at least id).
		 * @param int   $viewer WP user id.
		 * @return int[] openable job ids.
		 */
		public static function openable_job_ids( array $jobs, int $viewer ): array {
			if ( $viewer <= 0 || ! class_exists( 'ZJOB_Jobs' ) || ! method_exists( 'ZJOB_Jobs', 'actor_can_manage' ) ) {
				return array();
			}
			$ids = array();
			foreach ( $jobs as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$jid = (int) ( $row['id'] ?? 0 );
				if ( $jid > 0 && ZJOB_Jobs::actor_can_manage( $viewer, $row ) ) {
					$ids[] = $jid;
				}
			}
			return $ids;
		}

		/* ===================================================================
		 * SOURCES — built-in + the extension filter.
		 * =================================================================== */

		/**
		 * The registered sources for this project, each { id, class, gather:callable }. A source
		 * declaring CLASS_MONEY is gated for free by the gather loop; nothing else opts it in.
		 *
		 * @param int   $viewer
		 * @param array $project
		 * @return array<int,array{id:string,class:string,gather:callable}>
		 */
		private static function sources( int $viewer, array $project ): array {
			$builtin = array(
				array( 'id' => 'estimate', 'class' => self::CLASS_WORK, 'gather' => array( __CLASS__, 'src_estimate' ) ),
				array( 'id' => 'said', 'class' => self::CLASS_WORK, 'gather' => array( __CLASS__, 'src_said' ) ),
				array( 'id' => 'billing', 'class' => self::CLASS_WORK, 'gather' => array( __CLASS__, 'src_billing' ) ),
				array( 'id' => 'billing_amount', 'class' => self::CLASS_MONEY, 'gather' => array( __CLASS__, 'src_billing_amount' ) ),
				array( 'id' => 'schedule', 'class' => self::CLASS_WORK, 'gather' => array( __CLASS__, 'src_schedule' ) ),
				array( 'id' => 'activity', 'class' => self::CLASS_WORK, 'gather' => array( __CLASS__, 'src_activity' ) ),
				array( 'id' => 'record', 'class' => self::CLASS_WORK, 'gather' => array( __CLASS__, 'src_record' ) ),
			);

			/**
			 * Register additional Project panels. A source that declares 'class' => CLASS_MONEY is
			 * gated by decide_money() for free — the resolver body never learns its name.
			 *
			 * @param array $builtin
			 * @param int   $viewer
			 * @param array $project
			 */
			$declared = apply_filters( 'zdz_project_sources', $builtin, $viewer, $project );

			$out = array();
			foreach ( (array) $declared as $s ) {
				if ( ! is_array( $s ) ) {
					continue;
				}
				$sid = sanitize_key( (string) ( $s['id'] ?? '' ) );
				$cb  = $s['gather'] ?? null;
				if ( '' === $sid || isset( $out[ $sid ] ) || ! is_callable( $cb ) ) {
					continue;
				}
				// A source is money-class ONLY when it declares so; anything else is work-class.
				$cls           = ( self::CLASS_MONEY === ( $s['class'] ?? '' ) ) ? self::CLASS_MONEY : self::CLASS_WORK;
				$out[ $sid ]   = array( 'id' => $sid, 'class' => $cls, 'gather' => $cb );
			}
			return array_values( $out );
		}

		/** Coerce a source result to a well-formed panel with one of the four states. */
		private static function normalize_panel( string $sid, string $cls, $res ): array {
			$state = self::STATE_UNAVAILABLE;
			$data  = null;
			if ( is_array( $res ) ) {
				$s = (string) ( $res['state'] ?? '' );
				if ( in_array( $s, self::GATHER_STATES, true ) ) {
					$state = $s;
				}
				$data = array_key_exists( 'data', $res ) ? $res['data'] : null;
			}
			// A withheld panel never carries data (defence in depth against a leak through a source).
			if ( self::STATE_WITHHELD === $state ) {
				$data = null;
			}
			return array( 'id' => $sid, 'class' => $cls, 'state' => $state, 'data' => $data );
		}

		/* ===================================================================
		 * BUILT-IN SOURCES
		 * =================================================================== */

		/** WORK: the FACT that estimate document(s) exist on this project (no figure). */
		public static function src_estimate( int $viewer, array $project, bool $see_money ): array {
			unset( $viewer, $see_money );
			$ids = self::estimate_ids( $project );
			if ( empty( $ids ) ) {
				return array( 'state' => self::STATE_EMPTY, 'data' => null );
			}
			return array( 'state' => self::STATE_OK, 'data' => array( 'estimate_ids' => array_values( $ids ) ) );
		}

		/**
		 * WORK: what the estimate says in words. For each estimate the Project holds (the first recorded first, at most
		 * SAID_ESTIMATES): its document number, its notes, and what the salesperson wrote or said to make it. Whole, up
		 * to SAID_MAX characters each. No figure: every amount is taken out of every string.
		 *
		 * data: { estimates: [ { estimate_num, notes, said } ], more: int (estimates past the first SAID_ESTIMATES) }
		 */
		public static function src_said( int $viewer, array $project, bool $see_money ): array {
			unset( $viewer, $see_money );
			$ids = self::estimate_ids( $project );
			if ( empty( $ids ) ) {
				return array( 'state' => self::STATE_EMPTY, 'data' => null );
			}
			$rows = self::estimate_word_rows( array_slice( $ids, 0, self::SAID_ESTIMATES ) );
			if ( null === $rows ) {
				// The estimate app's record could not be read: a failure, never "nothing was written".
				return array( 'state' => self::STATE_UNAVAILABLE, 'data' => null );
			}
			$data = self::said_from_rows( $rows, max( 0, count( $ids ) - self::SAID_ESTIMATES ) );
			if ( empty( $data['estimates'] ) ) {
				return array( 'state' => self::STATE_EMPTY, 'data' => null );
			}
			return array( 'state' => self::STATE_OK, 'data' => $data );
		}

		/**
		 * The words of estimate rows as an opened Project carries them. PURE apart from the code reader and the
		 * business's currency. Each text: access codes cut first (so the bound can never split one), then amounts out,
		 * then held to SAID_MAX characters. An estimate with no words is left out.
		 *
		 * @param array $rows each { doc_number, billing_doc_num, notes, input_text }, in the order to show. The number
		 *                    shown is the estimate's own, else the one the billing system gave it.
		 * @param int   $more estimates of the Project that were not read
		 * @return array{estimates:array<int,array{estimate_num:string,notes:string,said:string}>,more:int}
		 */
		public static function said_from_rows( array $rows, int $more = 0 ): array {
			$out = array();
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$notes = self::said_text( $row['notes'] ?? '' );
				$said  = self::said_text( $row['input_text'] ?? '' );
				if ( '' === $notes && '' === $said ) {
					continue;
				}
				$num   = trim( (string) ( $row['doc_number'] ?? '' ) );
				$num   = '' !== $num ? $num : trim( (string) ( $row['billing_doc_num'] ?? '' ) );
				$out[] = array(
					'estimate_num' => (string) preg_replace( '/[^A-Za-z0-9\-]/', '', $num ),
					'notes'        => $notes,
					'said'         => $said,
				);
			}
			return array( 'estimates' => $out, 'more' => max( 0, $more ) );
		}

		/**
		 * One text of an estimate as the panel carries it: no access code, no amount, at most SAID_MAX characters.
		 * In that order, so the bound can never cut a code or an amount in two and leave half of it showing.
		 */
		private static function said_text( $text ): string {
			$text = is_string( $text ) ? trim( $text ) : '';
			if ( '' === $text ) {
				return '';
			}
			// Bytes that are not text are dropped first: nothing below can read a string that is not UTF-8.
			$text = self::text_only( $text );
			// A pasted document many times the bound is not read to its end: only its start can be shown. Twice the
			// bound is read, since taking codes and amounts out makes it shorter.
			list( $text, $long ) = self::first_chars( $text, 2 * self::SAID_MAX );
			if ( class_exists( 'ZJOB_Sealed' ) ) {
				$text = (string) ZJOB_Sealed::cut( $text );
			}
			$text = self::without_amounts( $text );
			if ( $long ) {
				$text = self::without_edge( $text, self::SAID_EDGE );
			}
			list( $head, $cut ) = self::first_chars( $text, self::SAID_MAX );
			return ( $cut || $long ) ? self::cut_short( $head ) : $text;
		}

		/** $s less its last $bytes bytes, ending on a whole character. PURE. */
		public static function without_edge( string $s, int $bytes ): string {
			$len = strlen( $s );
			$at  = $len - max( 0, $bytes );
			while ( $at > 0 && $at < $len && 0x80 === ( ord( $s[ $at ] ) & 0xC0 ) ) {
				$at--; // inside a character: back to where it starts
			}
			return $at > 0 ? substr( $s, 0, $at ) : '';
		}

		/** A text that was cut, as it is shown: an ellipsis, then where the rest is. */
		private static function cut_short( string $head ): string {
			return rtrim( $head ) . "\u{2026}" . self::said_more();
		}

		/**
		 * The estimate app's own rows for these ids, in the order given: { doc_number, billing_doc_num, notes,
		 * input_text }. null when the estimate app is not there to ask (no guess is made from anything else).
		 *
		 * @param int[] $ids
		 * @return array<int,array<string,string>>|null
		 */
		private static function estimate_word_rows( array $ids ): ?array {
			global $wpdb;
			if ( ! isset( $wpdb ) || ! class_exists( 'ZEST_DB' ) || ! is_callable( array( 'ZEST_DB', 'estimates_table' ) ) ) {
				return null;
			}
			$out = array();
			foreach ( $ids as $id ) {
				$row = $wpdb->get_row( $wpdb->prepare( 'SELECT doc_number, billing_doc_num, notes, input_text FROM ' . ZEST_DB::estimates_table() . ' WHERE id = %d', (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name; the id is a placeholder.
				if ( is_array( $row ) ) {
					$out[] = $row;
				}
			}
			return $out;
		}

		/**
		 * $s with every byte that is not part of a UTF-8 character dropped: a stray byte from an older encoding, or half
		 * a character. Text that is already UTF-8 comes back as it is. Needs no extension, so it is the same on every
		 * server. PURE.
		 */
		public static function text_only( string $s ): string {
			if ( '' === $s || 1 === preg_match( '//u', $s ) ) {
				return $s;
			}
			// One UTF-8 character, byte by byte: no overlong form, no surrogate, nothing past U+10FFFF.
			$char = '[\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2}';
			// A run of characters is kept; a byte that starts none is dropped.
			$out = preg_replace( '/((?:' . $char . ')++)|[\x80-\xFF]/', '$1', $s );
			return is_string( $out ) ? $out : '';
		}

		/** What a note that reached SAID_MAX ends with: where the rest is, never a bare ellipsis. */
		public static function said_more(): string {
			return "\n\n[" . __( 'This note is longer than Projects shows. The rest is on the estimate.', 'zorderz' ) . ']';
		}

		/**
		 * $s held to $max characters. Counted in characters and only in characters: a note under the bound in
		 * characters but over it in bytes (curly quotes, inch marks, dashes) is never cut, and a cut never ends in the
		 * middle of a character. A note that is cut ends with said_more(). PURE.
		 *
		 * @param string    $s
		 * @param int       $max       characters
		 * @param bool|null $multibyte false counts without the multibyte functions; null uses them when they exist
		 */
		public static function clip( string $s, int $max, ?bool $multibyte = null ): string {
			list( $head, $cut ) = self::first_chars( $s, $max, $multibyte );
			return $cut ? self::cut_short( $head ) : $s;
		}

		/**
		 * The first $max characters of $s, and whether anything was left off: [ string, bool ]. Characters, never bytes.
		 * PURE.
		 *
		 * @param string    $s
		 * @param int       $max       characters
		 * @param bool|null $multibyte false counts without the multibyte functions; null uses them when they exist
		 * @return array{0:string,1:bool}
		 */
		public static function first_chars( string $s, int $max, ?bool $multibyte = null ): array {
			$max = max( 0, $max );
			if ( null === $multibyte ? function_exists( 'mb_strlen' ) : $multibyte ) {
				return mb_strlen( $s, 'UTF-8' ) > $max ? array( mb_substr( $s, 0, $max, 'UTF-8' ), true ) : array( $s, false );
			}
			// Without the multibyte functions: still cut on a character.
			if ( strlen( $s ) <= $max ) {
				return array( $s, false ); // bytes are never fewer than characters
			}
			// One step of the pattern takes at most 60,000 characters, so a longer run is taken in steps.
			$head = '';
			$at   = 0;
			for ( $left = $max; $left > 0; $left -= 60000 ) {
				if ( 1 !== preg_match( '/\G.{0,' . min( $left, 60000 ) . '}/su', $s, $m, 0, $at ) ) {
					return array( substr( $s, 0, $max ), true ); // the text was not valid UTF-8 to begin with: a plain cut
				}
				$head .= $m[0];
				$at   += strlen( $m[0] );
			}
			return $at >= strlen( $s ) ? array( $s, false ) : array( $head, true );
		}

		/**
		 * The marks that make a number money, as two patterns: one for a mark before the number, one for a mark after.
		 * Core knows the common signs and codes; the business's own currency (Business Profile, locale.currency and
		 * locale.currency_sign) is always among them. PURE when $currency is given.
		 *
		 * @param array|null $currency { code, sign }; null reads the Business Profile
		 * @return array{0:string,1:string}
		 */
		public static function currency_marks( ?array $currency = null ): array {
			if ( null === $currency ) {
				$currency = array( 'code' => '', 'sign' => '' );
				if ( class_exists( 'ZDZ_Business_Profile' ) && method_exists( 'ZDZ_Business_Profile', 'get' ) ) {
					$currency['code'] = (string) ZDZ_Business_Profile::get( 'locale.currency', '' );
					$currency['sign'] = (string) ZDZ_Business_Profile::get( 'locale.currency_sign', '' );
				}
			}
			// A sign, with or without the letters some countries put before it (written in capitals, joined to it).
			$signs  = '(?:(?-i:(?<![\p{L}\p{N}])[A-Z]{1,3})?[\$\x{20AC}\x{00A3}\x{00A5}\x{20B9}])';
			$codes  = 'USD|EUR|GBP|CAD|AUD|NZD|CHF|JPY|CNY|INR|MXN|BRL|ZAR|SEK|NOK|DKK|PLN';
			$before = array( $signs, '\b(?:' . $codes . ')\b\.?' );
			$after  = array( '[\x{20AC}\x{00A3}\x{20B9}]', '\b(?:' . $codes . '|dollars?|bucks|euros?)\b' );
			$code   = strtoupper( (string) preg_replace( '/[^A-Za-z]/', '', (string) ( $currency['code'] ?? '' ) ) );
			if ( 3 === strlen( $code ) && false === strpos( '|' . $codes . '|', '|' . $code . '|' ) ) {
				// The business's own code, as written (capitals), so an ordinary word that spells it is left alone.
				$before[] = '(?-i:\b' . $code . '\b)\.?';
				$after[]  = '(?-i:\b' . $code . '\b)';
			}
			$sign = trim( (string) ( $currency['sign'] ?? '' ) );
			$len  = function_exists( 'mb_strlen' ) ? mb_strlen( $sign, 'UTF-8' ) : strlen( $sign );
			if ( '' !== $sign && '$' !== $sign && $len <= 4 && ! preg_match( '/[\d\s]/', $sign ) ) {
				$q = preg_quote( $sign, '/' );
				if ( preg_match( '/^[\p{L}.]+$/u', $sign ) ) {
					// A sign made of letters ("kr"): only as a whole word, as written, beside its number.
					$before[] = '(?-i:(?<![\p{L}\p{N}])' . $q . ')(?=\s?[-+]?\s?\d)';
					$after[]  = '(?-i:' . $q . ')(?![\p{L}\p{N}])';
				} else {
					$before[] = $q;
					$after[]  = $q;
				}
			}
			return array( '(?:' . implode( '|', $before ) . ')', '(?:' . implode( '|', $after ) . ')' );
		}

		/**
		 * Remove every amount of money from a string: a number with a currency mark before or after it, a rate
		 * ("@ 37 each", "111/ea"), a number written as money after a money word ("Total 2,222", "Cost: 333"), and the
		 * money word left with nothing after it ("Tax:"). Measurements and counts are not money and stay ("48 in",
		 * "12x24", "Total 3 pieces", "Total 120 ft of gutter guard"); so does a document number ("Quote 1001"). Line
		 * breaks are kept. A bare number after a money word stays only when a measure or a counting word follows it:
		 * Core knows the measures and the plainest counting words, and a business adds its own
		 * (`zjob_amount_count_words`), since what it counts is its own. A text the amounts cannot be taken out of comes
		 * back empty. PURE apart from that setting and the currency.
		 *
		 * @param string     $s
		 * @param array|null $currency { code, sign }; null reads the Business Profile
		 */
		public static function without_amounts( string $s, ?array $currency = null ): string {
			if ( '' === $s ) {
				return '';
			}
			// A NUMBER (digits with optional thousands separators - comma, period or space - and decimals).
			$num = '\d{1,3}(?:[,. ]\d{3})+(?:[.,]\d{1,2})?|\d+(?:[.,]\d{1,2})?';
			// MONEY: a currency mark before it, or a currency mark or word after it.
			list( $cur_before, $cur_after ) = self::currency_marks( $currency );
			$money = '(?:' . $cur_before . '\s?[-+]?\s?(?:' . $num . ')(?:\s?k\b)?|(?:' . $num . ')\s?' . $cur_after . ')';
			// WORDS that make the number after them money, with a currency mark or without.
			$labels = '(?:price[ds]?|priced at|cost[s]?|total|sub-?total|grand total|amount|deposit|balance|tax|labou?r|fee[s]?|charge[s]?|rate|quote[d]?|paid|due|invoice[d]?|discount|msrp|retail)';

			// 1. a money word and everything up to and including its number ("Deposit 50% = 444", "Price 333.00").
			// After a money word, a bare number counts as money only when it looks like money: 3+ digits, thousands
			// separators or cents ("Total 2,222", "Labor 222.00"), never "Total 3 pieces".
			$big = '\d{1,3}(?:[,. ]\d{3})+(?:[.,]\d{1,2})?|\d{3,}(?:[.,]\d{1,2})?|\d+[.,]\d{2}';
			// A bare number is money only after the money words themselves (not "Quote 1001", "Invoice 1002": document
			// numbers), and never when a measure or a counting word follows ("Total 120 ft of gutter guard").
			$bare_labels = '(?:price[ds]?|priced at|cost[s]?|total|sub-?total|grand total|amount|deposit|balance|tax|labou?r|fee[s]?|charge[s]?|paid|msrp|retail|discount)';
			$unit        = '(?![^\S\n]*(?:"|\'|\x{201D}|\x{2019}|in\b|inch|inches|ft\b|feet|foot|yd\b|yards?\b|x\b|\x{00D7}|mm\b|cm\b|m\b|sq|linear|lf\b|%|' . self::count_words() . '))';
			$steps = array(
				array( '/\b' . $labels . '\b[^\S\n]*(?:[:=]|of|is|was|at)?[^\S\n]*(?:\d{1,3}\s?%[^\S\n]*(?:=|of)?[^\S\n]*)?' . $money . '/iu', '' ),
				array( '/\b' . $bare_labels . '\b[^\S\n]*(?:[:=]|of|is|was|at)?[^\S\n]*(?:\d{1,3}\s?%[^\S\n]*(?:=|of)?[^\S\n]*)?(?:' . $big . ')\b' . $unit . '/iu', '' ),
				// 2. "@ $37 each", "@ 333", "111/ea", "37 per hour": a unit price.
				array( '/[^\S\n]*@[^\S\n]*(?:' . $money . '|(?:' . $num . ')\b(?![^\S\n]*(?:"|\'|\x{201D}|\x{2019}|in\b|inch|ft\b|feet|x\b|\x{00D7}|mm\b|cm\b)))[^\S\n]*(?:each|ea\b\.?|per[^\S\n]+\w+)?/iu', '' ),
				array( '/(?:' . $money . '|(?:' . $num . '))[^\S\n]*\/[^\S\n]*(?:ea|each|hr|hour|unit|ft|sq\s?ft)\b/iu', '' ),
				// 3. any money left.
				array( '/' . $money . '/iu', '' ),
				// 4. a money word left with nothing after it ("Tax:"); "Price: for the pair" stays readable.
				array( '/\b' . $labels . '\b[^\S\n]*[:=][^\S\n]*(?=$|[\n,;.])/imu', '' ),
				array( '/([?!.])[ \t]*\.(?=\s|$)/u', '$1' ), // a full stop left behind an amount that ended a sentence
				array( '/[ \t]{2,}/u', ' ' ),
				array( '/[ \t]+([,.;:])/u', '$1' ),
				array( '/[,;:]+(?=[.!?](?:\s|$))/u', '' ), // a comma left before the full stop once the last amount is gone
				array( '/^[ \t]*[.,;:=-]+[ \t]*/mu', '' ),
				array( '/[ \t]+[-:=,;]+[ \t]*$/mu', '' ), // a dash left hanging once its amount is gone
				array( '/[ \t]+$/mu', '' ),
			);
			foreach ( $steps as $step ) {
				$s = preg_replace( $step[0], $step[1], $s );
				if ( null === $s ) {
					return ''; // it could not be read: nothing, rather than a text with its amounts still in it
				}
			}
			return trim( $s );
		}

		/**
		 * The counting words after which a bare number is a count, not money, as one pattern. Core: the plainest ones.
		 * A business adds what it counts (option `zjob_amount_count_words`, a list of words; filter of the same name).
		 * Letters only; each also matches with a final "s". A number with a currency mark is money whatever follows it.
		 */
		private static function count_words(): string {
			$words = array( 'pc', 'piece', 'unit', 'item', 'set', 'pair' );
			$own   = function_exists( 'get_option' ) ? get_option( 'zjob_amount_count_words', array() ) : array();
			$words = array_merge( $words, is_array( $own ) ? array_values( $own ) : array() );
			if ( function_exists( 'apply_filters' ) ) {
				$more  = apply_filters( 'zjob_amount_count_words', $words );
				$words = is_array( $more ) ? $more : $words;
			}
			$out = array();
			foreach ( $words as $w ) {
				$w = strtolower( (string) preg_replace( '/[^a-z]/i', '', is_scalar( $w ) ? (string) $w : '' ) );
				if ( strlen( $w ) >= 2 && count( $out ) < 60 ) {
					$out[ $w ] = preg_quote( $w, '/' ) . 's?\b';
				}
			}
			return $out ? implode( '|', $out ) : '(?!)';
		}

		/**
		 * WORK: the billing LINKAGE state from ZEST_Billing — invoiced / not_invoiced / unchecked.
		 * This is the FACT a document does or does not exist; it carries NO money figure.
		 */
		public static function src_billing( int $viewer, array $project, bool $see_money ): array {
			unset( $viewer, $see_money );
			$ids = self::estimate_ids( $project );
			if ( empty( $ids ) ) {
				return array( 'state' => self::STATE_EMPTY, 'data' => null );
			}
			if ( ! class_exists( 'ZEST_Billing' ) ) {
				return array( 'state' => self::STATE_UNAVAILABLE, 'data' => null );
			}
			$states = array();
			foreach ( $ids as $eid ) {
				$states[ (int) $eid ] = ZEST_Billing::resolve( (int) $eid )['state'];
			}
			return array( 'state' => self::STATE_OK, 'data' => array( 'billing' => $states ) );
		}

		/**
		 * MONEY: the invoice AMOUNT (total / paid / due). Reached ONLY when the gate is open — the
		 * loop withholds this source before it ever runs for a viewer without see_money, so the amount
		 * query never executes for them.
		 */
		public static function src_billing_amount( int $viewer, array $project, bool $see_money ): array {
			unset( $viewer, $see_money );
			$ids = self::estimate_ids( $project );
			if ( empty( $ids ) ) {
				return array( 'state' => self::STATE_EMPTY, 'data' => null );
			}
			if ( ! class_exists( 'ZEST_Billing' ) ) {
				return array( 'state' => self::STATE_UNAVAILABLE, 'data' => null );
			}
			$total = 0;
			$paid  = 0;
			$any   = false;
			foreach ( $ids as $eid ) {
				$b = ZEST_Billing::resolve( (int) $eid );
				if ( ZEST_Billing::STATE_INVOICED === $b['state'] && (int) $b['invoice_id'] > 0 ) {
					$amt = self::invoice_amount_cents( (int) $b['invoice_id'] );
					if ( null !== $amt ) {
						$total += $amt['total_cents'];
						$paid  += $amt['paid_cents'];
						$any    = true;
					}
				}
			}
			if ( ! $any ) {
				// Nothing billed yet — a FACT, not a failure (and distinct from withheld).
				return array( 'state' => self::STATE_EMPTY, 'data' => null );
			}
			return array(
				'state' => self::STATE_OK,
				'data'  => array(
					'total_cents'    => $total,
					'paid_cents'     => $paid,
					'due_cents'      => max( 0, $total - $paid ),
					'currency_scale' => 100,
				),
			);
		}

		/**
		 * WORK: a light schedule read — the earliest linked, non-zero, live-status appointment start
		 * across the project's child jobs. (The full three-state install-date resolver is C1; this is
		 * a fact-of-scheduling panel, and it carries no money.)
		 */
		public static function src_schedule( int $viewer, array $project, bool $see_money ): array {
			unset( $viewer, $see_money );
			$earliest = null;
			foreach ( Zjob_Project::jobs_for( (string) $project['id'] ) as $j ) {
				$appt = (int) ( $j['scheduled_appt_id'] ?? 0 );
				$st   = trim( (string) ( $j['scheduled_start_utc'] ?? '' ) );
				$dead = 'cancelled' === strtolower( (string) ( $j['status'] ?? '' ) );
				if ( $appt > 0 && '' !== $st && '0000-00-00 00:00:00' !== $st && ! $dead ) {
					if ( null === $earliest || $st < $earliest ) {
						$earliest = $st;
					}
				}
			}
			if ( null === $earliest ) {
				return array( 'state' => self::STATE_EMPTY, 'data' => array( 'scheduled' => false ) );
			}
			return array( 'state' => self::STATE_OK, 'data' => array( 'scheduled' => true, 'start_utc' => $earliest ) );
		}

		/** WORK: activity counts only — never note bodies, never raw asset URLs (that is B5). */
		public static function src_activity( int $viewer, array $project, bool $see_money ): array {
			unset( $viewer, $see_money );
			$counts = ( isset( $project['counts'] ) && is_array( $project['counts'] ) )
				? $project['counts']
				: Zjob_Project::counts_for( (string) $project['id'] );
			$total = 0;
			foreach ( $counts as $c ) {
				$total += (int) $c;
			}
			if ( 0 === $total ) {
				return array( 'state' => self::STATE_EMPTY, 'data' => array( 'jobs' => 0 ) );
			}
			return array( 'state' => self::STATE_OK, 'data' => array( 'jobs' => $total, 'by_status' => $counts ) );
		}

		/**
		 * WORK: the RECORD — photos + notes under the per-component gate. Counts span EVERY
		 * component (a count discloses nothing); the visible photo strip and the notes come ONLY from
		 * jobs this viewer could open in Jobs. Honest output: the panel states when it holds FEWER pictures
		 * than the count — a short strip under a big number is a silent lie. Every media URL is a
		 * secure token minted by ZJOB_Photos::for_jobs (never a raw uploads URL — geo-PII); notes are
		 * top-level with NO include-internal flag anywhere. The MODEL never sees this panel — the model
		 * path (Zjob_Project::summarize_project) is counts-only by an allow-list.
		 */
		public static function src_record( int $viewer, array $project, bool $see_money ): array {
			unset( $see_money );
			$pid = (string) ( $project['id'] ?? '' );
			if ( '' === $pid || ! class_exists( 'Zjob_Project' ) ) {
				return array( 'state' => self::STATE_UNAVAILABLE, 'data' => null );
			}
			$jobs = Zjob_Project::jobs_for( $pid );
			if ( empty( $jobs ) ) {
				return array( 'state' => self::STATE_EMPTY, 'data' => array( 'photo_count' => 0, 'note_count' => 0 ) );
			}

			// Counts span EVERY component regardless of who may open it (a count discloses nothing).
			$all_ids = array();
			foreach ( $jobs as $j ) {
				$id = (int) ( $j['id'] ?? 0 );
				if ( $id > 0 ) {
					$all_ids[] = $id;
				}
			}
			$photo_count = ( class_exists( 'ZJOB_Photos' ) && method_exists( 'ZJOB_Photos', 'counts_for' ) )
				? (int) array_sum( ZJOB_Photos::counts_for( $jobs ) ) : 0;
			$note_count  = ( class_exists( 'ZJOB_Notes' ) && method_exists( 'ZJOB_Notes', 'counts_for' ) )
				? (int) array_sum( ZJOB_Notes::counts_for( $all_ids ) ) : 0;

			// THE PER-COMPONENT GATE: assets come ONLY from jobs this viewer could open in Jobs.
			$openable_ids  = self::openable_job_ids( $jobs, $viewer );
			$openable_set  = array_fill_keys( $openable_ids, true );
			$openable_jobs = array();
			foreach ( $jobs as $j ) {
				if ( isset( $openable_set[ (int) ( $j['id'] ?? 0 ) ] ) ) {
					$openable_jobs[] = $j;
				}
			}

			// Photos — confirmed-only, ONLY openable jobs; for_jobs already mints secure URLs.
			$photos = array();
			if ( ! empty( $openable_jobs ) && class_exists( 'ZJOB_Photos' ) && method_exists( 'ZJOB_Photos', 'for_jobs' ) ) {
				foreach ( ZJOB_Photos::for_jobs( $openable_jobs, array( 'min_confidence' => 'confirmed' ) ) as $jid => $list ) {
					foreach ( (array) $list as $m ) {
						$url = (string) ( $m['url'] ?? '' );
						if ( '' === $url ) {
							continue; // never a raw fallback
						}
						$photos[] = array(
							'job_id'      => (int) $jid,
							'media_id'    => (int) ( $m['media_id'] ?? 0 ),
							'url'         => $url,
							'thumb_url'   => (string) ( $m['thumb_url'] ?? $url ),
							'captured_at' => (string) ( $m['captured_at'] ?? '' ),
							// geo coords deliberately dropped — the panel shows the image, not a map pin.
						);
					}
				}
			}

			// Notes — top-level only, ONLY openable ids (there is NO include-internal flag anywhere).
			$notes = array();
			if ( ! empty( $openable_ids ) && class_exists( 'ZJOB_Notes' ) && method_exists( 'ZJOB_Notes', 'top_level_for_jobs' ) ) {
				foreach ( ZJOB_Notes::top_level_for_jobs( $openable_ids ) as $n ) {
					if ( ! is_array( $n ) ) {
						continue;
					}
					$notes[] = array(
						'id'         => (int) ( $n['id'] ?? 0 ),
						'job_id'     => (int) ( $n['job_id'] ?? 0 ),
						'author_id'  => (int) ( $n['author_id'] ?? 0 ),
						'visibility' => (string) ( $n['visibility'] ?? '' ),
						'body'       => (string) ( $n['body'] ?? '' ),
						'created_at' => (string) ( $n['created_at'] ?? '' ),
					);
				}
			}

			$shown_photos = count( $photos );
			return array(
				'state' => self::STATE_OK,
				'data'  => array(
					'photo_count'    => $photo_count, // spans all components
					'note_count'     => $note_count,  // spans all components
					'photos'         => $photos,      // only openable, secure URLs
					'notes'          => $notes,       // only openable, top-level
					'shown_photos'   => $shown_photos,
					'strip_is_short' => ( $shown_photos < $photo_count ), // honest output: a short strip says so
					'openable_jobs'  => count( $openable_ids ),
					'total_jobs'     => count( $all_ids ),
				),
			);
		}

		/* ===================================================================
		 * INTERNAL
		 * =================================================================== */

		/** The estimate ids referenced by a project (from its estimate/document refs). */
		private static function estimate_ids( array $project ): array {
			if ( empty( $project['id'] ) || ! class_exists( 'Zdz_Flow_Refs' ) ) {
				return array();
			}
			$ids = array();
			foreach ( Zdz_Flow_Refs::for( (string) $project['id'], 'estimate', 'document' ) as $r ) {
				$eid = (int) ( $r['external_id'] ?? 0 );
				if ( $eid > 0 ) {
					$ids[] = $eid;
				}
			}
			return array_values( array_unique( $ids ) );
		}

		/**
		 * The money figure for one native invoice, in integer cents. This is a MONEY read and is only
		 * ever reached from inside the gate (src_billing_amount). Returns null when the invoice store
		 * is unavailable or the row is missing.
		 *
		 * @param int $invoice_id
		 * @return array{total_cents:int,paid_cents:int}|null
		 */
		private static function invoice_amount_cents( int $invoice_id ): ?array {
			global $wpdb;
			if ( $invoice_id <= 0 || ! isset( $wpdb ) || ! class_exists( 'ZEST_DB' ) ) {
				return null;
			}
			$table = ZEST_DB::invoices_table();
			$row   = $wpdb->get_row(
				$wpdb->prepare( "SELECT total_amount, amount_paid FROM {$table} WHERE id = %d", $invoice_id ),
				ARRAY_A
			);
			if ( ! is_array( $row ) ) {
				return null;
			}
			return array(
				'total_cents' => (int) round( ( (float) $row['total_amount'] ) * 100 ),
				'paid_cents'  => (int) round( ( (float) $row['amount_paid'] ) * 100 ),
			);
		}
	}
}
