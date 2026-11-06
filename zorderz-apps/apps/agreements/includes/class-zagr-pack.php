<?php
/**
 * ZAGR_Pack - the legal rules an agreement follows, as selectable packs of public law. [CORE]
 *
 * A pack is a folder under packs/<id>/ (or the business's own folder, see dirs()):
 *
 *   pack.json        id, label, country, region (a state code, '' for a national pack), layers_on (the pack it adds
 *                    to), requires_job_region, the sources it was checked against and when
 *   rules.json       business_days, windows, questions, count_from, refund, deposit_cap, language, notices,
 *                    replaces_notices, applies, exemptions, flags
 *   checklist.json   the items that hold an agreement back from "ready to sign" while they are missing
 *   notices/*.html   the statutory wording, in the same restricted HTML as an agreement template
 *
 * Every rule, notice and checklist item carries its citation, the date the wording it encodes took effect
 * (effective_from) and the date someone last checked it against the law (last_checked). A pack missing any of them
 * does not load: an uncited rule is never applied.
 *
 * Which rules apply to one agreement (stack_for()):
 *   - the business picks its pack in the Business Profile, legal.agreement_pack (default us-federal); '' or "none"
 *     means no pack, and no agreement is made;
 *   - the JOB address's state decides the state layer: a pack whose region is that state and which layers on the
 *     chosen national pack is added on top of it. The office's state never decides it;
 *   - a later layer may replace a notice of an earlier one (replaces_notices), and its stricter rules win: the longest
 *     window, the lowest down payment limit, the shortest refund time. A deadline is worked out under each pack's own
 *     business-day definition and the latest one is the deadline (federal law lets a state law that protects the buyer
 *     more apply alongside it).
 *
 * Packs describe law; they hold no business data. They are not legal advice, and the settings screen says so.
 *
 * @package Zorderz\Agreements
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZAGR_Pack_Error extends RuntimeException {}

class ZAGR_Pack {

	/** The national pack a business in the United States starts on. */
	const DEFAULT_PACK = 'us-federal';

	/** The notice slots a pack may fill, and the template placeholder each one fills. */
	const SLOTS = array(
		'statement'         => 'pack_statement_html',
		'cancellation_form' => 'pack_cancellation_form_html',
		'first_page'        => 'pack_first_page_html',
		'deposit'           => 'pack_deposit_notice_html',
	);

	/** Rule groups of rules.json that must carry a citation (null allowed where the pack has no such rule). */
	const CITED = array( 'business_days', 'count_from', 'refund', 'deposit_cap', 'language', 'applies', 'exemptions' );

	/** Every two-letter code a job's state may be read as. */
	const US_REGIONS = array( 'AL', 'AK', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'DC', 'FL', 'GA', 'HI', 'ID', 'IL', 'IN', 'IA', 'KS',
		'KY', 'LA', 'ME', 'MD', 'MA', 'MI', 'MN', 'MS', 'MO', 'MT', 'NE', 'NV', 'NH', 'NJ', 'NM', 'NY', 'NC', 'ND', 'OH', 'OK', 'OR',
		'PA', 'RI', 'SC', 'SD', 'TN', 'TX', 'UT', 'VT', 'VA', 'WA', 'WV', 'WI', 'WY', 'PR', 'GU', 'VI', 'AS', 'MP' );

	/** @var array id => loaded pack, this request */
	private static $cache = array();

	/* ---------------------------------------------------------------- where packs live */

	/** The bundled packs first, then the business's own folder. A bundled id is never shadowed. */
	public static function dirs(): array {
		$own  = ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '' ) . '/zorderz-agreements/packs';
		$dirs = array( rtrim( ZAGR_DIR, '/' ) . '/packs', $own );
		if ( function_exists( 'apply_filters' ) ) {
			$f    = apply_filters( 'zagr_pack_dirs', $dirs );
			$dirs = is_array( $f ) && $f ? array_values( array_map( 'strval', $f ) ) : $dirs;
		}
		return $dirs;
	}

	/** id => folder, for every pack on disk. */
	public static function index(): array {
		$out = array();
		foreach ( self::dirs() as $d ) {
			foreach ( (array) glob( rtrim( $d, '/' ) . '/*/pack.json' ) as $m ) {
				$id = basename( dirname( (string) $m ) );
				if ( preg_match( '/^[a-z0-9][a-z0-9-]{1,39}$/', $id ) && ! isset( $out[ $id ] ) ) {
					$out[ $id ] = dirname( (string) $m );
				}
			}
		}
		ksort( $out );
		return $out;
	}

	public static function ids(): array {
		return array_keys( self::index() );
	}

	/* ---------------------------------------------------------------- loading */

	/** @throws ZAGR_Pack_Error */
	private static function json( string $file ): array {
		$raw = is_readable( $file ) ? (string) file_get_contents( $file ) : '';
		$j   = json_decode( $raw, true );
		if ( ! is_array( $j ) ) {
			throw new ZAGR_Pack_Error( basename( dirname( $file ) ) . ': ' . basename( $file ) . ' is missing or is not JSON.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an internal error: callers escape the message where an administrator sees it (ZAGR_Admin); the customer's page never shows it
		}
		return $j;
	}

	/** @throws ZAGR_Pack_Error when $row lacks a citation, effective_from or last_checked. */
	private static function cited( $row, string $what, string $id ): void {
		if ( ! is_array( $row ) ) {
			throw new ZAGR_Pack_Error( $id . ': ' . $what . ' is not a rule.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an internal error: callers escape the message where an administrator sees it (ZAGR_Admin); the customer's page never shows it
		}
		foreach ( array( 'citation', 'effective_from', 'last_checked' ) as $k ) {
			$v = (string) ( $row[ $k ] ?? '' );
			if ( '' === trim( $v ) || ( 'citation' !== $k && ! ZAGR_Business_Days::valid( $v ) ) ) {
				throw new ZAGR_Pack_Error( $id . ': ' . $what . ' has no ' . $k . '.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an internal error: callers escape the message where an administrator sees it (ZAGR_Admin); the customer's page never shows it
			}
		}
	}

	/**
	 * One pack, checked: { id, meta, rules, checklist, notices: slot => html, dir }.
	 * @throws ZAGR_Pack_Error
	 */
	public static function load( string $id ): array {
		if ( isset( self::$cache[ $id ] ) ) {
			return self::$cache[ $id ];
		}
		$idx = self::index();
		if ( ! isset( $idx[ $id ] ) ) {
			throw new ZAGR_Pack_Error( 'There is no legal pack named ' . $id . '.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an internal error: callers escape the message where an administrator sees it (ZAGR_Admin); the customer's page never shows it
		}
		$dir   = $idx[ $id ];
		$meta  = self::json( $dir . '/pack.json' );
		$rules = self::json( $dir . '/rules.json' );
		$check = self::json( $dir . '/checklist.json' );
		if ( (string) ( $meta['id'] ?? '' ) !== $id ) {
			throw new ZAGR_Pack_Error( $id . ': pack.json names another pack.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an internal error: callers escape the message where an administrator sees it (ZAGR_Admin); the customer's page never shows it
		}
		foreach ( self::CITED as $k ) {
			if ( array_key_exists( $k, $rules ) && null !== $rules[ $k ] ) {
				self::cited( $rules[ $k ], $k, $id );
			}
		}
		if ( ! isset( $rules['business_days'] ) ) {
			throw new ZAGR_Pack_Error( $id . ': rules.json has no business_days.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an internal error: callers escape the message where an administrator sees it (ZAGR_Admin); the customer's page never shows it
		}
		$windows = (array) ( $rules['windows'] ?? array() );
		if ( ! $windows ) {
			throw new ZAGR_Pack_Error( $id . ': rules.json has no windows.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an internal error: callers escape the message where an administrator sees it (ZAGR_Admin); the customer's page never shows it
		}
		foreach ( $windows as $w ) {
			self::cited( $w, 'a window', $id );
			$d = (int) ( $w['days'] ?? 0 );
			if ( $d < 1 || $d > ZAGR_Business_Days::MAX_DAYS || '' === (string) ( $w['word'] ?? '' ) || '' === (string) ( $w['ordinal'] ?? '' ) ) {
				throw new ZAGR_Pack_Error( $id . ': a window needs days (1 to ' . ZAGR_Business_Days::MAX_DAYS . '), a word and an ordinal.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an internal error: callers escape the message where an administrator sees it (ZAGR_Admin); the customer's page never shows it
			}
		}
		foreach ( (array) ( $rules['questions'] ?? array() ) as $q ) {
			self::cited( $q, 'a question', $id );
			if ( ! preg_match( '/^[a-z_]{2,30}$/', (string) ( $q['id'] ?? '' ) ) || ! in_array( $q['asked_of'] ?? '', array( 'buyer', 'staff' ), true ) ) {
				throw new ZAGR_Pack_Error( $id . ': a question needs an id and who it is asked of (buyer or staff).' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an internal error: callers escape the message where an administrator sees it (ZAGR_Admin); the customer's page never shows it
			}
		}
		foreach ( (array) ( $rules['flags'] ?? array() ) as $f ) {
			self::cited( $f, 'a flag', $id );
		}
		$rep = $rules['replaces_notices'] ?? null;
		if ( is_array( $rep ) && isset( $rep['slots'] ) ) {
			self::cited( $rep, 'replaces_notices', $id );
		}
		$notices = array();
		foreach ( (array) ( $rules['notices'] ?? array() ) as $slot => $n ) {
			if ( ! isset( self::SLOTS[ $slot ] ) ) {
				throw new ZAGR_Pack_Error( $id . ': ' . $slot . ' is not a notice slot.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an internal error: callers escape the message where an administrator sees it (ZAGR_Admin); the customer's page never shows it
			}
			self::cited( $n, 'the ' . $slot . ' notice', $id );
			$file = $dir . '/notices/' . basename( (string) ( $n['file'] ?? '' ) );
			if ( ! is_readable( $file ) ) {
				throw new ZAGR_Pack_Error( $id . ': the ' . $slot . ' notice file is missing.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an internal error: callers escape the message where an administrator sees it (ZAGR_Admin); the customer's page never shows it
			}
			$html = trim( ZAGR_Template::strip_comments( (string) file_get_contents( $file ) ) );
			try {
				ZAGR_Template::validate( $html, $id . '/' . $slot );
			} catch ( ZAGR_Template_Error $e ) {
				throw new ZAGR_Pack_Error( $e->getMessage() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an internal error: callers escape the message where an administrator sees it (ZAGR_Admin); the customer's page never shows it
			}
			foreach ( ZAGR_Template::tokens( $html ) as $tok ) {
				if ( '_html' === substr( $tok, -5 ) ) {
					throw new ZAGR_Pack_Error( $id . ': a notice may not hold another block (' . $tok . ').' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an internal error: callers escape the message where an administrator sees it (ZAGR_Admin); the customer's page never shows it
				}
			}
			$notices[ $slot ] = $html;
		}
		$items = array();
		foreach ( (array) ( $check['items'] ?? array() ) as $it ) {
			self::cited( $it, 'a checklist item', $id );
			if ( ! preg_match( '/^[a-z0-9_]{2,40}$/', (string) ( $it['id'] ?? '' ) ) || '' === (string) ( $it['label'] ?? '' ) ) {
				throw new ZAGR_Pack_Error( $id . ': a checklist item needs an id and a label.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an internal error: callers escape the message where an administrator sees it (ZAGR_Admin); the customer's page never shows it
			}
			$items[] = $it + array( 'pack' => $id );
		}
		return self::$cache[ $id ] = array(
			'id'        => $id,
			'meta'      => $meta,
			'rules'     => $rules,
			'checklist' => $items,
			'notices'   => $notices,
			'dir'       => $dir,
		);
	}

	public static function forget(): void {
		self::$cache = array();
	}

	/* ---------------------------------------------------------------- which packs apply */

	/** The business's chosen pack: the profile's legal.agreement_pack, '' for none. */
	public static function selected(): string {
		$v = self::DEFAULT_PACK;
		if ( class_exists( 'ZDZ_Business_Profile' ) ) {
			$v = (string) ZDZ_Business_Profile::get( 'legal.agreement_pack', self::DEFAULT_PACK );
		}
		if ( function_exists( 'apply_filters' ) ) {
			$v = (string) apply_filters( 'zagr_agreement_pack', $v );
		}
		$v = strtolower( trim( $v ) );
		return ( '' === $v || 'none' === $v ) ? '' : $v;
	}

	/** The pack a pack is built on, and that one's, down to a national pack: root first. */
	public static function chain( string $id ): array {
		$out = array();
		while ( '' !== $id && count( $out ) < 5 && ! in_array( $id, $out, true ) ) {
			array_unshift( $out, $id );
			try {
				$id = (string) ( self::load( $id )['meta']['layers_on'] ?? '' );
			} catch ( ZAGR_Pack_Error $e ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * A job's state, from an explicit region or from its address lines ("Springfield, IL 62701"): a two-letter code,
	 * or '' when it can't be read. Never guessed from the office's address.
	 */
	public static function region_of( array $buyer ): string {
		$r = strtoupper( trim( (string) ( $buyer['region'] ?? '' ) ) );
		if ( in_array( $r, self::US_REGIONS, true ) ) {
			return $r;
		}
		$lines = array_reverse( array_map( 'strval', (array) ( $buyer['address_lines'] ?? array() ) ) );
		foreach ( $lines as $l ) {
			if ( preg_match_all( '/(?:^|[\s,])([A-Z]{2})\.?\s+\d{5}(?:-\d{4})?\b/', $l, $m ) ) {
				$code = end( $m[1] );
				if ( in_array( $code, self::US_REGIONS, true ) ) {
					return $code;
				}
			}
		}
		return '';
	}

	/**
	 * The packs that apply to a job in $region, root first. The business's chosen pack's chain, plus the state pack
	 * for the job's state that layers on that chain's root. A job whose state can't be read keeps the chosen pack's
	 * own chain (the checklist then holds it back: requires_job_region).
	 */
	public static function stack_for( string $region, ?string $selected = null ): array {
		$sel = null === $selected ? self::selected() : $selected;
		if ( '' === $sel ) {
			return array();
		}
		$chain = self::chain( $sel );
		if ( ! $chain ) {
			return array();
		}
		$root = $chain[0];
		if ( '' === $region ) {
			return $chain;
		}
		$out = array( $root );
		foreach ( self::ids() as $id ) {
			try {
				$p = self::load( $id );
			} catch ( ZAGR_Pack_Error $e ) {
				continue;
			}
			if ( strtoupper( (string) ( $p['meta']['region'] ?? '' ) ) === $region && (string) ( $p['meta']['layers_on'] ?? '' ) === $root ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/** Load every pack of a stack; a pack that won't load stops the whole stack (null). */
	public static function packs( array $stack ): ?array {
		$out = array();
		foreach ( $stack as $id ) {
			try {
				$out[] = self::load( (string) $id );
			} catch ( ZAGR_Pack_Error $e ) {
				return null;
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------- the rules, layered */

	/** The questions the stack asks, by id (a later layer's wording wins). */
	public static function questions( array $stack ): array {
		$out = array();
		foreach ( (array) self::packs( $stack ) as $p ) {
			foreach ( (array) ( $p['rules']['questions'] ?? array() ) as $q ) {
				$out[ (string) $q['id'] ] = $q + array( 'pack' => $p['id'] );
			}
		}
		return $out;
	}

	/** Does the stack ask $question ('senior', 'disaster', …)? */
	public static function asks( array $stack, string $question ): bool {
		return isset( self::questions( $stack )[ $question ] );
	}

	/** One pack's window for these answers: the longest of its windows whose conditions all hold. */
	public static function pack_window( array $pack, array $answers ): ?array {
		$best = null;
		foreach ( (array) $pack['rules']['windows'] as $w ) {
			$ok = true;
			foreach ( (array) ( $w['when'] ?? array() ) as $q => $want ) {
				if ( (string) ( $answers[ $q ] ?? '' ) !== (string) $want ) {
					$ok = false;
					break;
				}
			}
			if ( $ok && ( null === $best || (int) $w['days'] > (int) $best['days'] ) ) {
				$best = $w;
			}
		}
		return $best;
	}

	/**
	 * Every answer the stack needs is given? A question "required" with no yes/no answer means no window can be worked
	 * out yet: never assume one.
	 */
	public static function answered( array $stack, array $answers ): bool {
		foreach ( self::questions( $stack ) as $id => $q ) {
			if ( ! empty( $q['required'] ) && ! in_array( (string) ( $answers[ $id ] ?? '' ), array( 'yes', 'no' ), true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The window the agreement prints: { days, word, ordinal, title, pack, citation } from the pack giving the longest
	 * period for these answers, or null (no pack, or a required answer is missing).
	 */
	public static function window( array $stack, array $answers ): ?array {
		$packs = self::packs( $stack );
		if ( ! $packs || ! self::answered( $stack, $answers ) ) {
			return null;
		}
		$best = null;
		foreach ( $packs as $p ) {
			$w = self::pack_window( $p, $answers );
			if ( null !== $w && ( null === $best || (int) $w['days'] >= (int) $best['days'] ) ) {
				$best = $w + array( 'pack' => $p['id'] );
			}
		}
		return $best;
	}

	/**
	 * The last day to cancel when the period starts on $from_ymd: each pack's own window counted in its own business
	 * days, and the latest of them. '' when it can't be worked out.
	 */
	public static function deadline( array $stack, string $from_ymd, array $answers ): string {
		$packs = self::packs( $stack );
		if ( ! $packs || ! self::answered( $stack, $answers ) || ! ZAGR_Business_Days::valid( $from_ymd ) ) {
			return '';
		}
		$late = '';
		foreach ( $packs as $p ) {
			$w = self::pack_window( $p, $answers );
			if ( null === $w ) {
				continue;
			}
			$d = ZAGR_Business_Days::add( (array) $p['rules']['business_days'], $from_ymd, (int) $w['days'] );
			if ( '' === $d ) {
				return '';
			}
			if ( $d > $late ) {
				$late = $d;
			}
		}
		return $late;
	}

	/** The lowest down payment limit any pack sets for this contract, in cents, or null when none sets one. */
	public static function deposit_cap( array $stack, int $contract_cents, int $tax_cents ): ?int {
		$cap = null;
		foreach ( (array) self::packs( $stack ) as $p ) {
			$c = $p['rules']['deposit_cap'] ?? null;
			if ( ! is_array( $c ) ) {
				continue;
			}
			$base = 'contract_before_tax' === ( $c['base'] ?? '' ) ? max( 0, $contract_cents - $tax_cents ) : $contract_cents;
			$lim  = intdiv( $base * (int) ( $c['percent'] ?? 100 ), 100 );
			if ( isset( $c['fixed_cents'] ) ) {
				$lim = min( $lim, (int) $c['fixed_cents'] );
			}
			$cap = null === $cap ? $lim : min( $cap, $lim );
		}
		return $cap;
	}

	/** Must the down payment be whole dollars under any pack of the stack? */
	public static function whole_dollars( array $stack ): bool {
		foreach ( (array) self::packs( $stack ) as $p ) {
			if ( ! empty( $p['rules']['deposit_cap']['whole_dollars'] ) ) {
				return true;
			}
		}
		return false;
	}

	/** The refund time the buyer is promised: the shortest any pack sets. { days, unit, citation } or null. */
	public static function refund( array $stack ): ?array {
		$best = null;
		foreach ( (array) self::packs( $stack ) as $p ) {
			$r = $p['rules']['refund'] ?? null;
			if ( is_array( $r ) && ( null === $best || (int) $r['days'] < (int) $best['days'] || ( (int) $r['days'] === (int) $best['days'] && 'days' === ( $r['unit'] ?? '' ) ) ) ) {
				$best = $r;
			}
		}
		return $best;
	}

	/** Slot => the notice's raw HTML for the stack: a later layer's notice in a slot it replaces stands alone. */
	public static function notice_sources( array $stack ): array {
		$out = array();
		foreach ( (array) self::packs( $stack ) as $p ) {
			$rep   = $p['rules']['replaces_notices'] ?? null;
			$slots = is_array( $rep ) ? (array) ( $rep['slots'] ?? $rep ) : array();
			foreach ( $p['notices'] as $slot => $html ) {
				if ( in_array( $slot, $slots, true ) || ! isset( $out[ $slot ] ) ) {
					$out[ $slot ] = array( $html );
				} else {
					$out[ $slot ][] = $html;
				}
			}
		}
		return array_map( static function ( $parts ) {
			return implode( "\n", $parts );
		}, $out );
	}

	/** The things to tell staff or the lawyer, from every pack of the stack. */
	public static function flags( array $stack ): array {
		$out = array();
		foreach ( (array) self::packs( $stack ) as $p ) {
			foreach ( (array) ( $p['rules']['flags'] ?? array() ) as $f ) {
				$out[] = $f + array( 'pack' => $p['id'] );
			}
		}
		return $out;
	}

	/** The language rule to quote when a sale was not negotiated in the agreement's language. */
	public static function language_rule( array $stack ): ?array {
		$last = null;
		foreach ( (array) self::packs( $stack ) as $p ) {
			if ( is_array( $p['rules']['language'] ?? null ) ) {
				$last = $p['rules']['language'];
			}
		}
		return $last;
	}

	/** Does any pack of the stack need the job's state? */
	public static function needs_region( array $stack ): bool {
		foreach ( (array) self::packs( $stack ) as $p ) {
			if ( ! empty( $p['meta']['requires_job_region'] ) ) {
				return true;
			}
		}
		return false;
	}

	/** The stack's labels, root first ("United States: federal standard + California: …"). */
	public static function label( array $stack ): string {
		$names = array();
		foreach ( (array) self::packs( $stack ) as $p ) {
			$names[] = (string) ( $p['meta']['label'] ?? $p['id'] );
		}
		return implode( ' + ', $names );
	}
}
