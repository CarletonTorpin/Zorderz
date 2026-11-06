<?php
/**
 * ZANA_Home_Figures — the read verb `ledger.home_figures`: the home screen's revenue, estimate and CRM lead figures for
 * one month, from the stored-data ledger (local SQL; never a live billing or CRM call inside a person's request).
 *
 * The definitions (the same sums the chat answers with, so a tile and an answer agree to the cent):
 *   - Revenue = INVOICED in the period: invoices dated in it at their full amount, paid or not; drafts and deleted left
 *     out, archived included (ZANA_Ledger_Figures::sales). Also how many invoices, and of those invoices how much has
 *     been collected and how much is still owed. `received`: every payment dated in the month (or from 1 January to the
 *     month's end), on any invoice, from the ledger's payments copy; credits applied as payments are counted and also
 *     given apart.
 *   - Estimates dated in the month, drafts and deleted left out; of those, accepted or turned into an invoice; drafts.
 *   - CRM leads created in the month; won (closed won in the month, whenever created); win rate (won / (won + lost)
 *     among those closed in the month); open now.
 *   - Contacted: of the month's new leads, how many have a note a person wrote more than `intake_minutes` after the lead
 *     (a note made with the lead is the intake), or an app's record of a person's contact (the Leads app's "Contacted by
 *     phone on …", and what apps add on `zana_crm_contact_note_patterns`), whenever written. Other notes the platform's
 *     apps write through the shared CRM login are not a person's follow-up: the contact log's own notes
 *     (ZDZ_Contacts::note_opening(); the contact is counted once, by the app that recorded it) and every opening an app
 *     declares on the `zana_crm_app_note_patterns` filter. A call logged in the CRM as an activity is not in the notes
 *     copy, so such a lead reads as not contacted.
 *
 * Who asks, and what they get (ZANA_Ledger_Access):
 *   company rights (revenue and others' data)   `company`: every figure
 *   staff or admin tier without them            `mine`: counts of their own invoices and estimates by their salesperson
 *                                               codes; NO dollars; no lead figures (the CRM's leads carry no code). No
 *                                               code -> `mine` with `no_rep_code: true`, never the company's
 *   a viewer, the shared device, Demo Mode, signed out   refused
 *   anyone                                      counts and totals only: never a customer name, a document number, a line
 *                                               or a note
 *
 * Freshness: `sources.{invoices,estimates,leads,notes,payments}` = { synced_at (unix), age_s, complete, stale }. A stale
 * source still answers, marked stale. One source's failed read -> `errors[source]` and its figures absent; every source
 * failed -> success false. Never zeros for a failure.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Home_Figures {

	const VERB = 'ledger.home_figures';

	/** A billing or CRM source older than this is stale. */
	const STALE_SECONDS = 7200;

	/** The notes copy re-reads an open lead about once a day: older than this is stale. */
	const NOTES_STALE_SECONDS = 93600;

	/** Injected in tests: "now" (unix) and the coverage array. */
	public static $now = null;
	public static $coverage = null;

	public static function init(): void {
		add_filter( 'zdz_register_capabilities', array( __CLASS__, 'register' ), 20 );
		add_filter( 'zdz_capability_annotations', array( __CLASS__, 'annotate' ) );
	}

	public static function register( $caps ) {
		$caps = is_array( $caps ) ? $caps : array();
		$caps[ self::VERB ] = array(
			'callable'   => array( __CLASS__, 'from_capability' ),
			'descriptor' => array(
				'verb'          => self::VERB,
				'source'        => 'sales-analytics',
				'app'           => ZANA_APP_ID,
				'min_tier'      => 'staff',
				'kiosk'         => false,
				'kiosk_bounded' => false,
				'side_effect'   => false,
				'outside'       => false,
				'needs_app'     => false, // the home screen shows figures to people who never open the chat
				'summary'       => 'Home-screen figures from the stored-data ledger for one month (site time): revenue, estimates, new leads, leads won; the company\'s for someone with company rights, a salesperson\'s own counts otherwise; never the shared device.',
			),
		);
		return $caps;
	}

	public static function annotate( $a ) {
		$a = is_array( $a ) ? $a : array();
		$a[ self::VERB ] = array( 'app' => ZANA_APP_ID, 'outside' => false, 'needs_app' => false, 'side_effect' => false );
		return $a;
	}

	protected static function now(): int {
		return null !== self::$now ? (int) self::$now : time();
	}

	/**
	 * $args: month (optional 'Y-m', default this month in the site's time zone). Identity (user_id, tier, is_kiosk) is
	 * stamped by the registry and is the only identity trusted.
	 */
	public static function from_capability( array $args ): array {
		$uid = (int) ( $args['user_id'] ?? 0 );
		$ctx = ZANA_Ledger_Access::context( $uid );
		if ( ! empty( $args['is_kiosk'] ) || 'kiosk' === (string) ( $args['tier'] ?? '' ) ) {
			$ctx['is_kiosk'] = true;
		}
		$why = ZANA_Ledger_Access::why_not( $ctx, 'mine' );
		if ( '' !== $why ) {
			return array( 'success' => false, 'denied' => true, 'reason' => $why, 'message' => ZANA_Ledger_Access::refusal_text( $why ), 'verb' => self::VERB );
		}
		$win = ZANA_Ledger_Periods::month_window( is_string( $args['month'] ?? null ) ? (string) $args['month'] : '' );
		if ( isset( $win['error'] ) ) {
			return array( 'success' => false, 'error' => $win['error'], 'verb' => self::VERB );
		}
		$company = ZANA_Ledger_Access::may( $ctx, 'company' );
		$cov     = self::coverage();
		$out     = array(
			'success'    => true,
			'verb'       => self::VERB,
			'shaped_for' => $company ? 'company' : 'mine',
			'month'      => $win['month'],
			'window'     => array( 'from' => $win['from'], 'to' => $win['to'], 'tz' => $win['tz'], 'year_from' => $win['year_from'] ),
			'read_at'    => self::now(),
			'sources'    => self::sources( $cov, $win ),
			'source'     => 'sales-analytics',
		);
		if ( ! $company ) {
			unset( $out['sources']['payments'], $out['sources']['leads'], $out['sources']['notes'] );
		}
		$errors = array();
		$tried  = 0;
		if ( $company ) {
			$figs = array();
			foreach ( array( 'invoices' => 'company_revenue', 'estimates' => 'estimate_figures', 'leads' => 'lead_figures', 'notes' => 'contacted' ) as $src => $fn ) {
				$tried++;
				try {
					$figs = array_merge( $figs, 'estimate_figures' === $fn ? self::estimate_figures( $win, array() ) : call_user_func( array( __CLASS__, $fn ), $win ) );
				} catch ( \Throwable $e ) {
					$errors[ $src ] = 'not_ready' === $e->getMessage() ? __( 'The lead notes copy is not set up yet.', 'zorderz' ) : sprintf( /* translators: %s: which copy */ __( 'The %s could not be read just now.', 'zorderz' ), self::source_word( $src ) );
				}
			}
			if ( isset( $figs['revenue_mtd'] ) ) {
				try {
					foreach ( self::received( $win, $cov ) as $k => $v ) {
						$figs[ $k ]['received'] = $v;
					}
				} catch ( \Throwable $e ) {
					$errors['payments'] = 'not_ready' === $e->getMessage() ? __( 'The payments copy is still loading.', 'zorderz' ) : __( 'The payments copy could not be read just now.', 'zorderz' );
				}
			}
			$out['company'] = $figs;
		} else {
			$mine = array();
			if ( ! $ctx['codes'] ) {
				$mine['no_rep_code'] = true;
			} else {
				foreach ( array( 'invoices' => 'invoice_counts', 'estimates' => 'estimate_figures' ) as $src => $fn ) {
					$tried++;
					try {
						$mine = array_merge( $mine, call_user_func( array( __CLASS__, $fn ), $win, $ctx['codes'] ) );
					} catch ( \Throwable $e ) {
						$errors[ $src ] = sprintf( /* translators: %s: which copy */ __( 'The %s could not be read just now.', 'zorderz' ), self::source_word( $src ) );
					}
				}
			}
			$mine['not_shown'] = array( 'revenue', 'leads' );
			$out['mine']       = $mine;
		}
		if ( $tried > 0 && count( $errors ) === $tried ) {
			return array( 'success' => false, 'error' => __( 'The sales ledger could not be read just now.', 'zorderz' ), 'errors' => $errors, 'verb' => self::VERB );
		}
		if ( $errors ) {
			$out['errors'] = $errors;
		}
		$out['definitions'] = self::definitions( $company );
		return $out;
	}

	/* ═══════════════════════════ the figures ═══════════════════════════ */

	protected static function company_revenue( array $win ): array {
		$f = ZANA_Ledger_Figures::filters();
		$m = ZANA_Ledger_Figures::sales( $f, array( $win['from'], $win['to'], '' ) );
		$y = ZANA_Ledger_Figures::sales( $f, array( $win['year_from'], $win['to'], '' ) );
		return array(
			'revenue_mtd' => array( 'value' => $m['value'], 'invoices' => (int) $m['jobs'], 'collected' => $m['paid'], 'owed' => $m['owed'] ),
			'revenue_ytd' => array( 'value' => $y['value'], 'invoices' => (int) $y['jobs'], 'collected' => $y['paid'], 'owed' => $y['owed'] ),
		);
	}

	/** Payments dated in the month and from 1 January; 'not_ready' while the payments copy does not reach the year. */
	protected static function received( array $win, array $cov ): array {
		$p    = (array) ( $cov['payments'] ?? array() );
		$year = (int) substr( $win['month'], 0, 4 );
		$from = (int) ( $p['loaded_from'] ?? 0 );
		if ( empty( $p['last_ok'] ) || ( empty( $p['complete'] ) && ( 0 === $from || $from > $year ) ) ) {
			throw new RuntimeException( 'not_ready' );
		}
		return array(
			'revenue_mtd' => ZANA_Ledger_Figures::received( $win['from'], $win['to'] ),
			'revenue_ytd' => ZANA_Ledger_Figures::received( $win['year_from'], $win['to'] ),
		);
	}

	/** A salesperson's own invoices, as counts only. */
	protected static function invoice_counts( array $win, array $codes ): array {
		$f = ZANA_Ledger_Figures::filters( array( 'reps' => $codes ) );
		$m = ZANA_Ledger_Figures::sales( $f, array( $win['from'], $win['to'], '' ) );
		$y = ZANA_Ledger_Figures::sales( $f, array( $win['year_from'], $win['to'], '' ) );
		return array( 'invoices_mtd' => (int) $m['jobs'], 'invoices_ytd' => (int) $y['jobs'] );
	}

	/** Estimates dated in the month: counted, accepted or invoiced, drafts. */
	protected static function estimate_figures( array $win, array $codes ): array {
		$e = ZANA_Ledger_Figures::estimates( ZANA_Ledger_Figures::filters( array( 'reps' => $codes ) ), array( $win['from'], $win['to'], '' ) );
		return array(
			'estimates'          => (int) $e['n'],
			'estimates_accepted' => (int) $e['either'],
			'estimates_drafts'   => (int) $e['drafts'],
		);
	}

	protected static function lead_figures( array $win ): array {
		return ZANA_Ledger_Figures::leads( $win['from_utc'], $win['to_utc'] );
	}

	/* ─────────────────────────── contacted ─────────────────────────── */

	/** Injected in tests: callable( from_utc, to_utc ) => { leads: [{lead_id, created_at, read_at, via}], notes: [...] }. */
	public static $notes = null;

	/**
	 * Notes an app writes that ARE a person's contact with the lead, read before anything else: the Leads app's
	 * "Contacted by phone on …" / "Contacted via email on …". Apps add theirs on `zana_crm_contact_note_patterns`.
	 */
	const CONTACT_NOTE = '/^Contacted (?:by phone|via email) on /iu';

	/** The openings of notes that record a person's contact (kind => regex). */
	public static function contact_note_patterns(): array {
		$p = array( 'leads' => self::CONTACT_NOTE );
		$f = function_exists( 'apply_filters' ) ? apply_filters( 'zana_crm_contact_note_patterns', $p ) : $p;
		$o = array();
		foreach ( is_array( $f ) ? $f : array() as $k => $rx ) {
			if ( is_string( $rx ) && '' !== $rx && false !== @preg_match( $rx, '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a bad pattern is skipped
				$o[ sanitize_key( (string) $k ) ] = $rx;
			}
		}
		return $o;
	}

	/** The fixed openings of notes the platform's apps write (kind => regex): the contact log's, and what apps declare. */
	public static function app_note_patterns(): array {
		$p = array();
		if ( class_exists( 'ZDZ_Contacts' ) && method_exists( 'ZDZ_Contacts', 'note_opening' ) ) {
			$o = trim( (string) ZDZ_Contacts::note_opening() );
			if ( '' !== $o ) {
				$p['contacts'] = '/^' . preg_quote( $o, '/' ) . '/iu';
			}
		}
		$f = function_exists( 'apply_filters' ) ? apply_filters( 'zana_crm_app_note_patterns', array() ) : array();
		foreach ( is_array( $f ) ? $f : array() as $k => $rx ) {
			if ( is_string( $rx ) && '' !== $rx && false !== @preg_match( $rx, '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a bad pattern is skipped
				$p[ sanitize_key( (string) $k ) ] = $rx;
			}
		}
		return $p;
	}

	/**
	 * What a note is: 'contact' (an app's record of a person's contact, whenever written), 'person' (a person's
	 * follow-up), 'intake' (written with the lead, or undated), 'no_author', or 'app:<kind>'. Pure but for the patterns.
	 */
	public static function classify( string $lead_created_utc, array $note, array $patterns, int $intake_minutes, ?array $contact = null ): string {
		$body = trim( str_replace( array( "\r\n", "\r" ), "\n", (string) ( $note['body'] ?? '' ) ) );
		foreach ( null === $contact ? self::contact_note_patterns() : $contact as $re ) {
			if ( preg_match( $re, $body ) ) {
				return 'contact';
			}
		}
		foreach ( $patterns as $kind => $re ) {
			if ( preg_match( $re, $body ) ) {
				return 'app:' . $kind;
			}
		}
		if ( '' === trim( (string) ( $note['author'] ?? '' ) ) ) {
			return 'no_author';
		}
		$lt = '' !== trim( $lead_created_utc ) ? strtotime( $lead_created_utc . ' UTC' ) : false;
		$nt = '' !== trim( (string) ( $note['created_at'] ?? '' ) ) ? strtotime( (string) $note['created_at'] . ' UTC' ) : false;
		if ( ! $lt || ! $nt || $nt <= $lt + $intake_minutes * 60 ) {
			return 'intake';
		}
		return 'person';
	}

	/** Of the month's new leads, how many have a person's follow-up or contact note (and how many the notes copy has not read). */
	protected static function contacted( array $win ): array {
		$cov = self::coverage();
		if ( empty( $cov['notes']['ready'] ) ) {
			throw new RuntimeException( 'not_ready' );
		}
		$data     = is_callable( self::$notes ) ? (array) call_user_func( self::$notes, $win['from_utc'], $win['to_utc'] ) : self::notes_rows( $win['from_utc'], $win['to_utc'] );
		$by       = array();
		foreach ( (array) ( $data['notes'] ?? array() ) as $x ) {
			$by[ (int) $x['lead_id'] ][] = $x;
		}
		$patterns = self::app_note_patterns();
		$contact  = self::contact_note_patterns();
		$intake   = (int) ZANA_Ledger_Rules::get()['intake_minutes'];
		$contacted = 0;
		$unread    = 0;
		$oldest    = null;
		$leads     = (array) ( $data['leads'] ?? array() );
		foreach ( $leads as $lead ) {
			$id = (int) $lead['lead_id'];
			if ( null === ( $lead['read_at'] ?? null ) || 'miss' === (string) ( $lead['via'] ?? '' ) ) {
				$unread++;
			} else {
				$t      = strtotime( (string) $lead['read_at'] . ' UTC' );
				$oldest = null === $oldest ? $t : min( $oldest, $t );
			}
			foreach ( $by[ $id ] ?? array() as $note ) {
				if ( in_array( self::classify( (string) $lead['created_at'], $note, $patterns, $intake, $contact ), array( 'person', 'contact' ), true ) ) {
					$contacted++;
					break;
				}
			}
		}
		return array(
			'leads_contacted' => array(
				'contacted' => $contacted,
				'of'        => count( $leads ),
				'unread'    => $unread,
				'as_of'     => $oldest,
				'stale'     => $unread > 0 || ( null !== $oldest && $oldest < self::now() - self::NOTES_STALE_SECONDS ),
				'basis'     => 'notes',
			),
		);
	}

	protected static function notes_rows( string $from_utc, string $to_utc ): array {
		global $wpdb;
		$l     = ZANA_Ledger_Store::table( 'leads' );
		$nt    = ZANA_Ledger_Store::table( 'notes' );
		$r     = ZANA_Ledger_Store::table( 'reads' );
		$leads = $wpdb->get_results( $wpdb->prepare( "SELECT l.lead_id, l.created_at, r.read_at, r.via FROM {$l} l LEFT JOIN {$r} r ON r.lead_id = l.lead_id WHERE l.created_at >= %s AND l.created_at < %s", $from_utc, $to_utc ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL -- fixed names.
		ZANA_Ledger_Figures::db_ok();
		$notes = $wpdb->get_results( $wpdb->prepare( "SELECT n.lead_id, n.created_at, n.author, n.body FROM {$nt} n JOIN {$l} l ON l.lead_id = n.lead_id WHERE l.created_at >= %s AND l.created_at < %s ORDER BY n.lead_id, n.created_at", $from_utc, $to_utc ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL -- fixed names.
		ZANA_Ledger_Figures::db_ok();
		return array( 'leads' => is_array( $leads ) ? $leads : array(), 'notes' => is_array( $notes ) ? $notes : array() );
	}

	/* ═══════════════════════════ freshness, words ═══════════════════════════ */

	/** Each source's last good sync (unix), age, and whether it is stale (old or incomplete). */
	public static function sources( array $cov, array $win = array() ): array {
		$out = array();
		foreach ( array( 'invoices', 'estimates', 'leads', 'notes', 'payments' ) as $k ) {
			$c    = (array) ( $cov[ $k ] ?? array() );
			$last = (string) ( 'payments' === $k && ! empty( $c['caught_up_at'] ) ? $c['caught_up_at'] : ( $c['last_ok'] ?? '' ) );
			$t    = '' !== $last ? ( is_numeric( $last ) ? (int) $last : (int) strtotime( $last . ' UTC' ) ) : 0;
			$age  = $t > 0 ? max( 0, self::now() - $t ) : null;
			$lim  = 'notes' === $k ? self::NOTES_STALE_SECONDS : self::STALE_SECONDS;
			$done = ! empty( $c['complete'] );
			if ( 'payments' === $k && ! $done && $win ) {
				$from = (int) ( $c['loaded_from'] ?? 0 );
				$done = $from > 0 && $from <= (int) substr( (string) $win['month'], 0, 4 );
			}
			$out[ $k ] = array(
				'synced_at' => $t > 0 ? $t : null,
				'age_s'     => $age,
				'complete'  => $done,
				'stale'     => null === $age || $age > $lim || ! $done,
			);
		}
		return $out;
	}

	/** One sentence per figure, for the tile's "what this counts" line. */
	public static function definitions( bool $company ): array {
		if ( ! $company ) {
			return array(
				'invoices_mtd'       => __( 'Your invoices dated this month (site time), by your salesperson code; drafts and deleted left out, archived included. A count, not dollars.', 'zorderz' ),
				'invoices_ytd'       => __( 'The same from 1 January to the end of this month.', 'zorderz' ),
				'estimates'          => __( 'Your estimates dated this month, by your salesperson code; drafts and deleted left out.', 'zorderz' ),
				'estimates_accepted' => __( 'Of those, how many were accepted or turned into an invoice.', 'zorderz' ),
				'estimates_drafts'   => __( 'Your draft estimates dated this month (not counted above).', 'zorderz' ),
				'no_rep_code'        => __( 'Your account has no salesperson code, so there are no figures of your own to show.', 'zorderz' ),
				'not_shown'          => __( 'Revenue in dollars and lead figures are shown with company rights only; the CRM\'s leads carry no salesperson code.', 'zorderz' ),
			);
		}
		return array(
			'revenue_mtd'        => __( 'Invoiced this month (site time): invoices dated in the month at their full amount, paid or not; drafts and deleted left out, archived included. Also how many invoices, and of those invoices how much has been collected and how much is still owed. Received: every payment dated this month, on any invoice; credits applied as payments are counted and also given apart.', 'zorderz' ),
			'revenue_ytd'        => __( 'The same from 1 January to the end of this month.', 'zorderz' ),
			'estimates'          => __( 'Estimates dated this month; drafts and deleted left out.', 'zorderz' ),
			'estimates_accepted' => __( 'Of those, how many were accepted or turned into an invoice.', 'zorderz' ),
			'estimates_drafts'   => __( 'Draft estimates dated this month (not counted above).', 'zorderz' ),
			'leads_new'          => __( 'CRM leads created this month (site time), every kind.', 'zorderz' ),
			'leads_won'          => __( 'CRM leads won this month, by the day they were closed won, whenever they were created.', 'zorderz' ),
			'win_rate'           => __( 'Won ÷ (won + lost) among the leads closed this month; empty when none closed.', 'zorderz' ),
			'leads_open'         => __( 'CRM leads open right now (open or pending).', 'zorderz' ),
			'leads_contacted'    => __( 'Of this month\'s new leads, how many have a note a person wrote after the lead was made, or a contact an app recorded (the apps\' other notes and the intake left out; a call logged as an activity is not counted).', 'zorderz' ),
		);
	}

	protected static function source_word( string $src ): string {
		$w = array(
			'invoices'  => __( 'invoices copy', 'zorderz' ),
			'estimates' => __( 'estimates copy', 'zorderz' ),
			'leads'     => __( 'leads copy', 'zorderz' ),
			'notes'     => __( 'lead notes copy', 'zorderz' ),
			'payments'  => __( 'payments copy', 'zorderz' ),
		);
		return $w[ $src ] ?? __( 'ledger', 'zorderz' );
	}

	protected static function coverage(): array {
		if ( is_array( self::$coverage ) ) {
			return self::$coverage;
		}
		try {
			return ZANA_Ledger::coverage();
		} catch ( \Throwable $e ) {
			return array();
		}
	}
}
