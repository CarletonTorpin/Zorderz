<?php
/**
 * The registry read verb `charts.records`: the business's own invoice lines and job lists, for the chat.
 *
 * `charts.run` hands the chat totals and never a line. A question about what is written ON the invoices ("which jobs
 * had a part replaced") needs the lines themselves, and the hub already holds every invoice's line text and can
 * search it in milliseconds. This verb asks the hub for RECORDS, not totals:
 *
 *   op "search_lines"  the invoice lines whose text holds the words of `text`, filtered by salesperson, product, work
 *                      type, tags, towns, regions and a date window. With `whole_words` the hub matches whole words,
 *                      side by side, the last with a plain ending allowed; the answer's `method` says which search
 *                      ran ("whole_words", or "keyword" when the hub searched loosely: any hub that does not know the
 *                      switch does). Only a caller that asks gets whole words: the Charts page's own search does not.
 *   op "list_jobs"     the jobs (one row per invoice) a filter covers: number, date, town, products, amount.
 *
 * WHO MAY. Two doors, both shut until someone opens them:
 *   1. the business's switch (Charts → Settings → Assistant, "invoice lines and job lists"). The assistant's models
 *      may run in the cloud and a line is typed by a person, so Core ships the switch off.
 *   2. exactly the people the Charts PAGE lets search lines (ZCH_Access::block( person, 'ui' ) carries search_lines).
 *      Both ops need it: someone with invoice cards but no line items gets neither. The block goes to the hub as the
 *      page's own, cut down to what the chat needs: these two ops and health, named accounts off, invoice cards off.
 *      A person kept to their own jobs keeps that scope, so they find only their own lines and jobs.
 * Never the shared device, never signed out. Money only for people with money. Charts' per-minute budget applies, a
 * hub that did not answer a moment ago is not asked again for five minutes, and every hub call leaves an audit line.
 *
 * WHAT COMES BACK. Known keys only (a stray field in a hub row never reaches the caller) and no customer names. Line
 * text is typed by people and can hold a customer's contact details, so e-mail addresses, phone numbers and street
 * addresses are replaced by [email] / [phone] / [address] before the text leaves this app (redact()).
 *
 * With no hub connected Charts is in local mode and the verb says so. A failed read is an error in words, never an
 * empty success. Nothing is written (`side_effect` false); nothing is stored but the audit line.
 *
 * @package Zorderz\Charts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZCH_Records_Verb {
	const VERB      = 'charts.records';
	const OPS       = array( 'search_lines', 'list_jobs' );
	const MAX_LINES = 50;             // the hub's own search limit.
	const MAX_JOBS  = 100;
	const MAX_TEXT  = 400;            // characters of one line's text.
	const BACKOFF_S = 300;

	/** The filters a records ask may carry (the hub checks each against its pack again). */
	const FILTER_KEYS = array(
		'salespeople', 'products', 'work_types', 'tags', 'areas', 'regions', 'zips',
		'exclude_products', 'exclude_work_types', 'exclude_tags', 'exclude_salespeople', 'customer_type', 'payment',
	);

	/**
	 * The words a street's name ends with, after a house number ("120 Main St"). A region whose streets end otherwise
	 * adds its own in its Identity Pack (knowledge.yml addresses.street_end_words, read by ZDZ_Sealed::street_end_words())
	 * or with the zch_records_street_words filter; words that come BEFORE the name ("1450 Via Example") are the
	 * platform's region list (ZDZ_Sealed::street_first_words()).
	 */
	const STREET_WORDS = array(
		'St', 'Street', 'Ave', 'Avenue', 'Rd', 'Road', 'Dr', 'Drive', 'Ln', 'Lane', 'Ct', 'Court', 'Blvd', 'Boulevard',
		'Way', 'Pl', 'Place', 'Cir', 'Circle', 'Ter', 'Terrace', 'Pkwy', 'Parkway', 'Hwy', 'Highway', 'Trl', 'Trail',
		'Loop', 'Row',
	);

	/**
	 * Tests hand in the hub: an object with call( $op, $body, $user, $today ) and last_ms, or false for "no hub
	 * connected". null = the configured hub.
	 */
	public static $client = null;

	public static function get_capability_descriptor(): array {
		return array(
			'verb'          => self::VERB,
			'provider'      => 'charts',
			'app'           => ZCH_APP_ID,
			'tier'          => 'viewer',       // anyone signed in; the switch and Charts' own per-person access decide the rest.
			'needs_app'     => false,
			'callback'      => array( __CLASS__, 'from_capability' ),
			'kiosk'         => false,
			'kiosk_bounded' => false,
			'side_effect'   => false,
			'summary'       => 'Searches invoice line text (op search_lines) or lists the jobs behind a filter (op list_jobs) from the Charts hub, for people the Charts page lets see line items and only while the business shares records with the assistant; no customer names, money only for people who may see it. The chat asks through its own records requests. Never the shared device.',
		);
	}

	/** Has the business let the assistant read invoice lines and job lists (Charts → Settings → Assistant)? Off in Core. */
	public static function shared(): bool {
		$s = ZCH_Store::settings();
		return ! empty( $s['bot_records'] );
	}

	/**
	 * A list of words from a setting or a text box: lower-case words or short phrases (letters, digits, spaces,
	 * hyphens and apostrophes, 2 to 40 characters), at most 80 of them. PURE.
	 */
	public static function clean_words( $v ): array {
		$v   = is_array( $v ) ? $v : preg_split( '/[\r\n,]+/', (string) $v );
		$out = array();
		foreach ( (array) $v as $w ) {
			$w = is_scalar( $w ) ? mb_strtolower( trim( (string) preg_replace( '/\s+/u', ' ', (string) $w ) ) ) : '';
			if ( preg_match( '/^[\p{L}\p{N}][\p{L}\p{N} \'\-]{1,39}$/u', $w ) ) {
				$out[ $w ] = $w;
			}
		}
		return array_slice( array_values( $out ), 0, 80 );
	}

	/**
	 * The words that are on most of this business's invoice lines (Charts → Settings → Assistant). A search for one of
	 * them alone finds thousands of lines and answers nothing, so the chat's records tool leaves it out. Core: none.
	 */
	public static function common_words(): array {
		$s = ZCH_Store::settings();
		return self::clean_words( $s['records_common'] ?? array() );
	}

	private static function no( string $why, string $msg, bool $denied = false ): array {
		$out = array( 'success' => false, 'verb' => self::VERB, 'message' => $msg );
		if ( $denied ) {
			$out['denied'] = true;
			$out['reason'] = $why;
		} else {
			$out['error'] = $why;
		}
		return $out;
	}

	/** A value from the hub as text: a string or a number, anything else is nothing. */
	private static function str( $v, int $max ): string {
		return is_scalar( $v ) ? mb_substr( (string) $v, 0, $max ) : '';
	}

	/** A list from the hub as short strings: at most $n of them, each at most $max characters. */
	private static function strs( $v, int $n, int $max ): array {
		$out = array();
		foreach ( is_array( $v ) ? $v : array() as $x ) {
			if ( is_scalar( $x ) && '' !== (string) $x ) {
				$out[] = mb_substr( (string) $x, 0, $max );
			}
		}
		return array_slice( $out, 0, $n );
	}

	/** The filters, as plain lists of short strings (or the two switches), known keys only. */
	public static function clean_filters( $f ): array {
		$f   = is_array( $f ) ? $f : array();
		$out = array();
		foreach ( self::FILTER_KEYS as $k ) {
			if ( ! array_key_exists( $k, $f ) ) {
				continue;
			}
			$v = $f[ $k ];
			if ( 'customer_type' === $k || 'payment' === $k ) {
				if ( is_string( $v ) && preg_match( '/^[a-z]{2,20}$/D', $v ) ) {
					$out[ $k ] = $v;
				}
				continue;
			}
			$v    = is_array( $v ) ? $v : array( $v );
			$list = array();
			foreach ( $v as $x ) {
				if ( ! is_scalar( $x ) ) {
					continue;
				}
				$x = trim( sanitize_text_field( (string) $x ) );
				if ( '' !== $x && mb_strlen( $x ) <= 60 ) {
					$list[] = $x;
				}
			}
			if ( $list ) {
				$out[ $k ] = array_slice( array_values( array_unique( $list ) ), 0, 20 );
			}
		}
		return $out;
	}

	/** The street-ending words as pattern alternatives: Core's and the region's (letters only, so a word never changes the pattern). */
	public static function street_words(): array {
		$w = self::STREET_WORDS;
		if ( class_exists( 'ZDZ_Sealed' ) && method_exists( 'ZDZ_Sealed', 'street_end_words' ) ) {
			$w = array_merge( $w, ZDZ_Sealed::street_end_words() );
		}
		$w = function_exists( 'apply_filters' ) ? apply_filters( 'zch_records_street_words', $w ) : $w;
		$out = array();
		foreach ( is_array( $w ) ? $w : self::STREET_WORDS as $x ) {
			if ( is_string( $x ) && preg_match( '/^\p{L}{2,20}$/u', $x ) ) {
				$out[ $x ] = $x;
			}
		}
		return array_values( $out );
	}

	/**
	 * Line text is typed by people and can hold a customer's contact details. Before it leaves for a model, e-mail
	 * addresses, phone numbers and street addresses are replaced by [email] / [phone] / [address]. Sizes and money stay
	 * ("12x30", "$222.00"). Pattern-based: a name typed into a line is not found here (the hub sends no customer-name
	 * field, and the Ai router codes every customer detail it knows before a model reads anything). A text that cannot
	 * be read as UTF-8, or a pattern that fails, gives no text at all: nothing rather than something unchecked. The
	 * zch_records_line_text filter gets the text ALREADY redacted, so a region can take more out and never put it back.
	 */
	public static function redact( string $t ): string {
		if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $t, 'UTF-8' ) ) {
			$t = (string) mb_convert_encoding( $t, 'UTF-8', 'UTF-8' );
		}
		$steps = array(
			array( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', '[email]' ),
			// ten digits in the common groupings, with or without a leading 1; never a price or a size
			array( '/(?<![\d$.])(?:\+?1[\s.\-]?)?(?:\(\d{3}\)|\d{3})[\s.\-]?\d{3}[\s.\-]\d{4}(?!\d)/u', '[phone]' ),
			// a number written with its country code: a plus sign, then eight or more digits in groups
			array( '/(?<![\w$])\+\d{1,3}(?:[ \t.\-]?\(?\d{2,4}\)?){2,5}(?!\w)/u', '[phone]' ),
		);
		$street = self::street_words();
		if ( $street ) {
			$steps[] = array( '/\b\d{1,6}\s+(?:[NSEW]\.?\s+)?(?:\p{L}[\p{L}\']*\.?\s+){1,3}(?:' . implode( '|', $street ) . ')\b\.?/iu', '[address]' );
		}
		$first = array();
		if ( class_exists( 'ZDZ_Sealed' ) && method_exists( 'ZDZ_Sealed', 'street_first_words' ) ) {
			foreach ( ZDZ_Sealed::street_first_words() as $w ) {
				$first[] = str_replace( ' ', '[ \t]+', preg_quote( $w, '/' ) );
			}
		}
		if ( $first ) {
			// the street word first ("1450 Via Example"): only after a house number of three digits or more, so a one- or
			// two-digit count before such a word stays a count
			$steps[] = array( '/\b\d{3,6}[ \t]+(?:(?i:El|La|Los|Las|Del|De)[ \t]+)?(?i:' . implode( '|', $first ) . ')\b(?:[ \t]+(?:\p{Lu}[\p{L}\'.\-]*|de|del|la|las|los)){0,5}/u', '[address]' );
		}
		foreach ( $steps as $s ) {
			$r = preg_replace( $s[0], $s[1], $t );
			if ( ! is_string( $r ) ) {
				return '';
			}
			$t = $r;
		}
		if ( function_exists( 'apply_filters' ) ) {
			$f = apply_filters( 'zch_records_line_text', $t );
			$t = is_string( $f ) ? $f : $t;
		}
		return $t;
	}

	/** A date, YYYY-MM-DD, or null. */
	private static function day( $v ) {
		$v = is_scalar( $v ) ? (string) $v : '';
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $v ) ? $v : null;
	}

	/**
	 * The page's access block, cut down for the chat: these two ops and health, never names, never invoice cards.
	 * Null when the Charts page does not let this person search lines: both ops need that right, so a person with
	 * invoice cards but no line items gets neither. For someone with line items the hub's own-jobs rule rides on the
	 * block's scope, so turning invoice cards off here changes nothing about whose lines and jobs they find.
	 */
	public static function block_for( int $uid, string $op ) {
		$b = ZCH_Access::block( $uid, 'ui' );
		if ( ! in_array( 'search_lines', $b['ops'], true ) || ! in_array( $op, $b['ops'], true ) ) {
			return null;
		}
		$b['ops']      = array_values( array_intersect( $b['ops'], array_merge( array( 'health' ), self::OPS ) ) );
		$b['accounts'] = false;
		$b['invoices'] = 'none';
		return $b;
	}

	/**
	 * The registry callback. $args: op, text, filters, date_from, date_to, limit, whole_words, plus what
	 * ZDZ_Capabilities::invoke() stamps (user_id, requesting_user_id, tier, is_kiosk: the signed-in person, never the
	 * caller's payload).
	 */
	public static function from_capability( array $args ): array {
		$uid = (int) ( $args['user_id'] ?? 0 );
		if ( ! empty( $args['is_kiosk'] ) || ZCH_Access::is_kiosk( $uid ) ) {
			return self::no( 'kiosk', __( 'Charts is not available on the shared device.', 'zorderz' ), true );
		}
		if ( $uid <= 0 ) {
			return self::no( 'signed_out', __( 'Sign in first.', 'zorderz' ), true );
		}
		$op = is_scalar( $args['op'] ?? null ) ? (string) $args['op'] : '';
		if ( ! in_array( $op, self::OPS, true ) ) {
			return self::no( 'bad_request', __( 'charts.records takes op "search_lines" or "list_jobs".', 'zorderz' ) );
		}
		if ( ! self::shared() ) {
			return self::no( 'bot_records', __( 'Charts records are not shared with the assistant (Charts > Settings > Assistant).', 'zorderz' ), true );
		}
		if ( ! ZCH_Access::allowed( $uid ) ) {                 // the page's own door, its two-factor hook included.
			return self::no( 'charts_access', __( 'Charts is not turned on for this account.', 'zorderz' ), true );
		}
		$b = self::block_for( $uid, $op );
		if ( null === $b ) {
			return self::no( 'line_items', __( 'Charts does not show line items to this account.', 'zorderz' ), true );
		}
		$filters = self::clean_filters( $args['filters'] ?? array() );
		$from    = self::day( $args['date_from'] ?? null );
		$to      = self::day( $args['date_to'] ?? null );
		if ( 'search_lines' === $op ) {
			$text = is_scalar( $args['text'] ?? null ) ? trim( sanitize_text_field( (string) $args['text'] ) ) : '';
			$text = mb_substr( $text, 0, 200 );
			if ( '' === $text ) {
				return self::no( 'bad_request', __( 'A line search needs words to look for.', 'zorderz' ) );
			}
			$limit = (int) ( $args['limit'] ?? 0 );
			$body  = array(
				'filters' => $filters ? $filters : new stdClass(),
				'text'    => $text,
				'limit'   => max( 1, min( self::MAX_LINES, $limit > 0 ? $limit : 30 ) ),
			);
			if ( ! empty( $args['whole_words'] ) ) {
				$body['whole_words'] = true;
			}
			if ( $from ) {
				$body['date_from'] = $from;
			}
			if ( $to ) {
				$body['date_to'] = $to;
			}
		} else {
			$spec = array( 'filters' => $filters ? $filters : new stdClass() );
			if ( $from ) {
				$spec['date_from'] = $from;
			}
			if ( $to ) {
				$spec['date_to'] = $to;
			}
			$limit = (int) ( $args['limit'] ?? 0 );
			$body  = array( 'spec' => $spec, 'limit' => max( 1, min( self::MAX_JOBS, $limit > 0 ? $limit : 50 ) ) );
		}
		$client = null !== self::$client ? ( self::$client ? self::$client : null ) : ZCH_Hub_Client::from_config();
		if ( ! $client ) {
			return self::no( 'hub_unavailable', ZCH_Config::local_message() );
		}
		$last = (int) get_option( 'zch_last_fail', 0 );
		if ( $last && time() - $last < self::BACKOFF_S ) {
			return self::no( 'hub_unavailable', __( 'The Charts hub did not answer a moment ago; try again in a few minutes.', 'zorderz' ) );
		}
		if ( ! ZCH_Store::rate_ok( $uid ) ) {
			return self::no( 'rate_limited', __( 'Too many Charts requests in a minute. Try again shortly.', 'zorderz' ) );
		}
		$body['access'] = $b;
		$reply          = $client->call( $op, $body, 'wp_' . $uid, wp_date( 'Y-m-d' ) );
		$reply          = is_array( $reply ) ? $reply : array();
		$d              = is_array( $reply['data'] ?? null ) ? $reply['data'] : array();
		$key            = 'search_lines' === $op ? 'lines' : 'jobs';
		$n              = isset( $d[ $key ] ) && is_array( $d[ $key ] ) ? count( $d[ $key ] ) : null;
		$code           = is_scalar( $reply['error']['code'] ?? null ) ? (string) $reply['error']['code'] : null;
		ZCH_Store::audit( $op, ! empty( $reply['ok'] ), $code, $n, $client->last_ms ?? null, null, 'chat' );
		if ( empty( $reply['ok'] ) ) {
			$code = null !== $code && '' !== $code ? $code : 'hub_error';
			if ( 'hub_unavailable' === $code ) {
				update_option( 'zch_last_fail', time(), false );
				return self::no( 'hub_unavailable', __( 'The Charts hub did not answer.', 'zorderz' ) );
			}
			if ( 'forbidden' === $code ) {
				return self::no( 'line_items', __( 'Charts does not show line items to this account.', 'zorderz' ), true );
			}
			/* translators: %s: an error code */
			return self::no( $code, sprintf( __( 'Charts did not answer (%s).', 'zorderz' ), preg_replace( '/[^a-z0-9_]/', '', strtolower( $code ) ) ) );
		}
		delete_option( 'zch_last_fail' );
		$money = ! empty( $b['money'] );
		$as_of = self::str( $d['meta']['data_as_of'] ?? '', 40 );
		if ( 'search_lines' === $op ) {
			if ( null === $n ) {
				return self::no( 'hub_error', __( 'Charts answered without a list of lines.', 'zorderz' ) );
			}
			$lines = array();
			foreach ( $d['lines'] as $l ) {
				if ( ! is_array( $l ) ) {
					continue;
				}
				$lines[] = array(
					// redacted on a longer piece, then cut: a detail that sits across the cut is not left half-shown
					'text'       => mb_substr( self::redact( self::str( $l['text'] ?? '', self::MAX_TEXT * 2 ) ), 0, self::MAX_TEXT ),
					'date'       => self::day( $l['date'] ?? null ),
					'town'       => self::str( $l['town'] ?? '', 60 ),
					'invoice_no' => self::str( $l['invoice_no'] ?? '', 20 ),
					'family'     => self::str( $l['family'] ?? '', 40 ),
					'tags'       => self::strs( $l['tags'] ?? array(), 8, 60 ),
					'dollars'    => $money && isset( $l['dollars'] ) && is_numeric( $l['dollars'] ) ? round( (float) $l['dollars'], 2 ) : null,
				);
			}
			$total = isset( $d['total_matches'] ) && is_numeric( $d['total_matches'] ) ? max( count( $lines ), (int) $d['total_matches'] ) : count( $lines );
			return array(
				'success'       => true,
				'verb'          => self::VERB,
				'op'            => $op,
				'text'          => $body['text'],
				'filters'       => $filters,
				'date_from'     => $from,
				'date_to'       => $to,
				'money'         => $money,
				'lines'         => $lines,
				'total_matches' => $total,
				'method'        => 'whole_words' === ( $d['method'] ?? '' ) ? 'whole_words' : 'keyword',
				'data_as_of'    => $as_of,
				/* translators: %d: how many invoice lines matched */
				'message'       => sprintf( _n( '%d invoice line matched.', '%d invoice lines matched.', $total, 'zorderz' ), $total ),
			);
		}
		if ( null === $n ) {
			return self::no( 'hub_error', __( 'Charts answered without a list of jobs.', 'zorderz' ) );
		}
		$by_person = ! empty( $b['by_person'] ) || null !== $b['scope'];
		$jobs      = array();
		foreach ( $d['jobs'] as $j ) {
			if ( ! is_array( $j ) ) {
				continue;
			}
			$jobs[] = array(
				'invoice_no'  => self::str( $j['invoice_no'] ?? '', 20 ),
				'date'        => self::day( $j['date'] ?? null ),
				'town'        => self::str( $j['town'] ?? '', 60 ),
				'products'    => self::strs( $j['products'] ?? array(), 6, 60 ),
				'amount'      => $money && isset( $j['amount'] ) && is_numeric( $j['amount'] ) ? round( (float) $j['amount'], 2 ) : null,
				'salesperson' => $by_person && isset( $j['salesperson'] ) && is_string( $j['salesperson'] ) && '' !== $j['salesperson'] ? mb_substr( $j['salesperson'], 0, 8 ) : null,
			);
		}
		$total = isset( $d['total'] ) && is_numeric( $d['total'] ) ? max( count( $jobs ), (int) $d['total'] ) : count( $jobs );
		return array(
			'success'    => true,
			'verb'       => self::VERB,
			'op'         => $op,
			'filters'    => $filters,
			'date_from'  => $from,
			'date_to'    => $to,
			'money'      => $money,
			'jobs'       => $jobs,
			'total'      => $total,
			'data_as_of' => $as_of,
			/* translators: %d: how many jobs the filters cover */
			'message'    => sprintf( _n( '%d job found.', '%d jobs found.', $total, 'zorderz' ), $total ),
		);
	}
}

if ( function_exists( 'add_filter' ) ) {
	add_filter(
		'zdz_register_capabilities',
		function ( $caps ) {
			$caps = is_array( $caps ) ? $caps : array();
			$d    = ZCH_Records_Verb::get_capability_descriptor();
			$caps[ $d['verb'] ] = $d;
			return $caps;
		},
		20
	);
}
