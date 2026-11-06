<?php
/**
 * ZANA_Measurements -- "what sizes have we measured?", answered from the measurement notes the ledger keeps.
 *
 * The CRM's lead notes are copied by the ledger (zana_ledger_lead_notes). A measurement question ("what sizes of <kind>
 * have we measured", "the average <kind> size this year") is recognised by rule (detect()), never goes to a model, and
 * is answered from those notes (answer()): every size written per kind, how many units and how many jobs had it, the
 * typical (median) size, and how many units had no size written. NOTHING IS EVER FILLED IN: a line with no written size
 * is counted as "no size written", never as a standard or a price-list size.
 *
 * WHICH NOTE ON A LEAD. The measurement block the estimate app writes onto the lead, recognised by the markers Prep
 * publishes for it (ZPREP_CONTRACT_MEAS_MARKER and ZPREP_CONTRACT_MEAS_MARKER2, both present; more on the
 * `zana_measure_block_markers` filter): the newest such block that carries a size. Only when no block on the lead carries
 * one are the lead's other notes read (a salesperson's own lines). Machine notes (Prep's cut-plan note, anything on
 * `zana_measure_machine_markers`) are never read for sizes: a cut plan fills its standard size where none was written.
 *
 * WHAT A LINE IS. Its kind is the catalog item it names (ZDZ_Item_Engine::classify(); a line naming no catalog item is
 * not counted, so on an empty catalog nothing is). Labour, fees, taxes, discounts, links and address lines are skipped,
 * plus the business's own words (`zana_measure_skip_words`, e.g. a product line sold ready-made). A size is "A x B"
 * ("14 x 24", "14\" x 24\"", "2' x 3'", "14 1/2 x 24") or a diameter ("8\" dia", "Ø 18"); three numbers in a row are
 * counted as not readable unless the spacing says which is the count. Sizes are read as written; inch and foot marks are
 * turned into one unit (inches), and a size outside `zana_measure_bounds` (Core 0.5 to 1000) is not a size.
 *
 * WHO. Staff and the admin tier (ZANA_Ledger_Access, 'leads'); never the shared device or the demo. The answer names
 * no customer, street or person: sizes and counts only.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Measurements {

	const KIND       = 'measurements';
	const TABLE_ROWS = 10;
	const MAX_LEADS  = 5000;

	/** Injected in tests: rows like load() reads ({ lead_id, created_at, body }), the classifier and the label maker. */
	public static $rows     = null;
	public static $classify = null;
	public static $label    = null;
	public static $kinds    = null;

	public static function init(): void {
		add_filter( 'zana_quick_routes', array( __CLASS__, 'quick_route' ) );
	}

	/* ═══════════════════════════ parsing (pure) ═══════════════════════════ */

	/** The bounds a side must fall in (Core 0.5 to 1000; a business narrows them for its trade). */
	public static function bounds(): array {
		$b = function_exists( 'apply_filters' ) ? apply_filters( 'zana_measure_bounds', array( 0.5, 1000.0 ) ) : array( 0.5, 1000.0 );
		$lo = is_array( $b ) && isset( $b[0] ) && is_numeric( $b[0] ) ? max( 0.01, (float) $b[0] ) : 0.5;
		$hi = is_array( $b ) && isset( $b[1] ) && is_numeric( $b[1] ) ? (float) $b[1] : 1000.0;
		return $hi > $lo ? array( $lo, $hi ) : array( 0.5, 1000.0 );
	}

	/** One number as written ("14", "14.5", "14 1/2", "14-1/2", "14½", "1/2"); a foot mark multiplies by 12. Null: no number. */
	public static function to_units( $num, $unit = '' ) {
		$s = trim( str_replace( array( '½', '¼', '¾', '⅛', '⅜', '⅝', '⅞' ), array( ' 1/2', ' 1/4', ' 3/4', ' 1/8', ' 3/8', ' 5/8', ' 7/8' ), (string) $num ) );
		$s = trim( (string) preg_replace( '/\s*-\s*(?=\d+\/\d)/', ' ', $s ) );
		if ( preg_match( '/^(\d+(?:\.\d+)?)(?:\s+(\d+)\/(\d+))?$/', $s, $m ) ) {
			$v = (float) $m[1];
			if ( isset( $m[2] ) && '' !== $m[2] && (int) $m[3] > 0 ) {
				$v += (int) $m[2] / (int) $m[3];
			}
		} elseif ( preg_match( '/^(\d+)\/(\d+)$/', $s, $m ) && (int) $m[2] > 0 ) {
			$v = (int) $m[1] / (int) $m[2];
		} else {
			return null;
		}
		$u = strtolower( trim( (string) preg_replace( '/\s*(?:w|h|wide|high|tall)$/i', '', trim( (string) $unit ) ) ) );
		if ( in_array( $u, array( "'", '’', 'ft', 'ft.', 'feet', 'foot' ), true ) ) {
			$v *= 12;
		}
		return round( $v, 3 );
	}

	private static function num_re(): string {
		return '(?:\d+(?:\.\d+)?(?:\s*-\s*\d+\/\d{1,2}|\s+\d+\/\d{1,2})?|\d+\/\d{1,2})(?:\s*[½¼¾⅛⅜⅝⅞])?';
	}

	private static function unit_re(): string {
		return '(?:"|”|“|\'\'|in\b\.?|inch(?:es)?\b|\'|’|ft\b\.?|feet\b)?(?:\s*(?i:w|h|wide|high|tall)\b)?';
	}

	/**
	 * The size written on one line: { kind: 'rect', a, b } (a <= b) | { kind: 'round', d } | { kind: 'ambiguous' } |
	 * null. A count written beside the size ("14x24 x2", "2 x 14x24") comes back as qty.
	 */
	public static function size_in( $line ) {
		list( $lo, $hi ) = self::bounds();
		$text = (string) $line;
		$num  = self::num_re();
		$unit = self::unit_re();
		$sep  = '\s*(?:x|X|×|\*|by)\s*';
		$re   = '/(?<![\d.\/])(' . $num . ')(\s*)(' . $unit . ')(' . $sep . ')(' . $num . ')(\s*)(' . $unit . ')(?:(' . $sep . ')(' . $num . ')(?![\d\/]))?/u';
		if ( preg_match( $re, $text, $m ) ) {
			$n1  = $m[1];
			$u1  = (string) $m[3];
			$n2  = $m[5];
			$u2  = (string) $m[7];
			$qty = null;
			if ( isset( $m[9] ) && '' !== trim( (string) $m[9] ) ) {
				// Three numbers: the spacing says which is the count ("14x24 x2", "2 x 14x24"); "14 x 24 x 2" cannot be told.
				$s1 = (bool) preg_match( '/\s/', $m[2] . $m[3] . $m[4] );
				$s2 = (bool) preg_match( '/\s/', $m[6] . $m[7] . $m[8] );
				$n3 = trim( (string) $m[9] );
				// "14 x 24 × 2": a size written with "x" and its count with "×" (how a measurement list writes it).
				$count_mark = false !== strpos( (string) $m[8], '×' ) && false === strpos( (string) $m[4], '×' );
				if ( $count_mark && preg_match( '/^\d{1,3}$/', $n3 ) && (int) $n3 >= 1 && (int) $n3 <= 200 ) {
					$qty = (int) $n3;
				} elseif ( ! $s1 && $s2 && preg_match( '/^\d{1,2}$/', $n3 ) && (int) $n3 >= 1 && (int) $n3 <= 30 ) {
					$qty = (int) $n3;
				} elseif ( $s1 && ! $s2 && preg_match( '/^\d{1,2}$/', trim( $n1 ) ) && (int) $n1 >= 1 && (int) $n1 <= 30 ) {
					$qty = (int) $n1;
					$n1  = $n2;
					$u1  = $u2;
					$n2  = $n3;
					$u2  = '';
				} else {
					return array( 'kind' => 'ambiguous' );
				}
			}
			if ( '' === trim( $u1 ) ) {
				$u1 = $u2;   // a mark on one side belongs to both ("14 x 24 in")
			}
			if ( '' === trim( $u2 ) ) {
				$u2 = $u1;
			}
			$a = self::to_units( $n1, $u1 );
			$b = self::to_units( $n2, $u2 );
			if ( null !== $a && null !== $b && $a >= $lo && $b >= $lo && $a <= $hi && $b <= $hi ) {
				$out = array( 'kind' => 'rect', 'a' => min( $a, $b ), 'b' => max( $a, $b ) );
				if ( null !== $qty ) {
					$out['qty'] = $qty;
				}
				return $out;
			}
		}
		foreach ( array(
			'/(?<![\d.\/])(' . $num . ')\s*(' . $unit . ')\s*(?:dia\b\.?|diam\b\.?|diameter\b|Ø)/iu',
			'/(?:Ø|\bdia\b\.?:?|\bdiam\b\.?:?|\bdiameter\b:?)\s*(' . $num . ')\s*(' . $unit . ')/iu',
		) as $dre ) {
			if ( preg_match( $dre, $text, $m ) ) {
				$d = self::to_units( $m[1], $m[2] ?? '' );
				if ( null !== $d && $d >= $lo && $d <= $hi ) {
					return array( 'kind' => 'round', 'd' => $d );
				}
			}
		}
		return null;
	}

	/** Is this line something other than a measured item (labour, a fee, a link, the address, the business's own words)? */
	public static function skipped( $line ): bool {
		$l = strtolower( (string) $line );
		if ( preg_match( '/\binstallation\b|\binstall\b|\blabou?r\b|\bservice call\b|\btrip charge\b|\btax\b|\bfees?\b|\bdiscount\b|\bdeposit\b|\bsurcharge\b|\bcredit\b|\breceipt\b|\blink\b|https?:\/\//', $l )
			|| preg_match( '/^\s*\*?\s*(?:location|property location|address|customer|phone|email)\b/', $l ) ) {
			return true;
		}
		$words = function_exists( 'apply_filters' ) ? apply_filters( 'zana_measure_skip_words', array() ) : array();
		foreach ( is_array( $words ) ? $words : array() as $w ) {
			$w = strtolower( trim( (string) $w ) );
			if ( '' !== $w && preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( $w, '/' ) . '(?![\p{L}\p{N}])/u', $l ) ) {
				return true;
			}
		}
		return false;
	}

	/** How many of the thing a line describes: "× N" in a block; a person's line also reads "(N)", "qty N", "N @", "N pcs". */
	public static function qty_in( $line, bool $structured = true ): int {
		$t = (string) preg_replace( '/\s*[@=]\s*[^\d\s]?\s*[\d,]+(?:\.\d+)?.*$/u', '', (string) $line ); // "@ 75.00 = 150.00"
		if ( preg_match_all( '/×\s*(\d{1,3})\b/u', $t, $m ) ) {
			return max( 0, min( 200, (int) end( $m[1] ) ) );
		}
		if ( $structured ) {
			return 1;
		}
		$t2 = (string) preg_replace( '/(?<![\d.\/])' . self::num_re() . '\s*' . self::unit_re() . '\s*(?:x|X|×|\*|by)\s*' . self::num_re() . '\s*' . self::unit_re() . '/u', ' SIZE ', (string) $line );
		if ( preg_match( '/\((\d{1,3})\)/', $t2, $m )
			|| preg_match( '/\bqty\.?\s*:?\s*(\d{1,3})\b/i', $t2, $m )
			|| preg_match( '/\bquantity\s*:?\s*(\d{1,3})\b/i', $t2, $m )
			|| preg_match( '/(?<![\d.\/])(\d{1,3})\s*(?:@|pcs?\b|pieces?\b|ea\b)/i', $t2, $m ) ) {
			return max( 1, min( 200, (int) $m[1] ) );
		}
		return 1;
	}

	/** The catalog item a line names ('' when none), and its label. */
	public static function kind_of( string $line ): string {
		if ( is_callable( self::$classify ) ) {
			return (string) call_user_func( self::$classify, $line );
		}
		if ( class_exists( 'ZDZ_Item_Engine' ) && method_exists( 'ZDZ_Item_Engine', 'classify' ) ) {
			$id = ZDZ_Item_Engine::classify( $line );
			return is_string( $id ) ? $id : '';
		}
		return '';
	}

	public static function label_of( string $kind ): string {
		if ( is_callable( self::$label ) ) {
			return (string) call_user_func( self::$label, $kind );
		}
		if ( class_exists( 'ZDZ_Item_Engine' ) && method_exists( 'ZDZ_Item_Engine', 'get' ) ) {
			$it = ZDZ_Item_Engine::get( $kind );
			if ( is_array( $it ) && '' !== trim( (string) ( $it['display_name'] ?? '' ) ) ) {
				return trim( (string) $it['display_name'] );
			}
		}
		return $kind;
	}

	/**
	 * The measured lines of one piece of text: { items: [ {kind, shape, a, b, d, qty} ], unsized: {kind => qty},
	 * ambiguous: n }. $structured: a measurement block (its count is the "× N" a line ends with).
	 */
	public static function parse_text( $text, bool $structured = true ): array {
		$out = array( 'items' => array(), 'unsized' => array(), 'ambiguous' => 0 );
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $raw ) {
			$line = trim( (string) $raw );
			if ( '' === $line || strlen( $line ) > 400 || preg_match( '/^[=─—\-]{2,}/u', $line ) || preg_match( '/^\s*>/', $line ) ) {
				continue;   // a header rule, a quoted reply
			}
			if ( self::skipped( $line ) ) {
				continue;
			}
			$kind = self::kind_of( $line );
			if ( '' === $kind ) {
				continue;
			}
			$qty = self::qty_in( $line, $structured );
			if ( $qty <= 0 ) {
				continue;
			}
			$size = self::size_in( $line );
			if ( null === $size ) {
				$out['unsized'][ $kind ] = ( $out['unsized'][ $kind ] ?? 0 ) + $qty;
				continue;
			}
			if ( 'ambiguous' === $size['kind'] ) {
				$out['ambiguous']++;
				continue;
			}
			if ( isset( $size['qty'] ) && ! preg_match( '/×\s*\d/u', $line ) ) {
				$qty = (int) $size['qty'];
			}
			$out['items'][] = array( 'kind' => $kind, 'shape' => $size['kind'], 'a' => $size['a'] ?? null, 'b' => $size['b'] ?? null, 'd' => $size['d'] ?? null, 'qty' => $qty );
		}
		return $out;
	}

	/* ═══════════════════════════ which notes on a lead (pure) ═══════════════════════════ */

	/** The markers a measurement block carries (all of one set must be present). */
	public static function block_markers(): array {
		$sets = array();
		if ( defined( 'ZPREP_CONTRACT_MEAS_MARKER' ) && defined( 'ZPREP_CONTRACT_MEAS_MARKER2' ) ) {
			$sets[] = array( (string) ZPREP_CONTRACT_MEAS_MARKER, (string) ZPREP_CONTRACT_MEAS_MARKER2 );
		}
		$more = function_exists( 'apply_filters' ) ? apply_filters( 'zana_measure_block_markers', array() ) : array();
		foreach ( is_array( $more ) ? $more : array() as $set ) {
			$set = array_values( array_filter( array_map( 'strval', (array) $set ), 'strlen' ) );
			if ( $set ) {
				$sets[] = $set;
			}
		}
		return $sets;
	}

	public static function is_block( string $note, ?array $sets = null ): bool {
		foreach ( null === $sets ? self::block_markers() : $sets as $set ) {
			$all = true;
			foreach ( $set as $mk ) {
				if ( false === stripos( $note, $mk ) ) {
					$all = false;
					break;
				}
			}
			if ( $all ) {
				return true;
			}
		}
		return false;
	}

	/** A note an app wrote (a cut plan and its like): never read for sizes. */
	public static function is_machine( string $note ): bool {
		$marks = array();
		foreach ( array( 'ZPREP_CONTRACT_CUT_COMPLETE', 'ZPREP_CONTRACT_SIGNATURE' ) as $c ) {
			if ( defined( $c ) ) {
				$marks[] = (string) constant( $c );
			}
		}
		$more = function_exists( 'apply_filters' ) ? apply_filters( 'zana_measure_machine_markers', array() ) : array();
		foreach ( array_merge( $marks, is_array( $more ) ? $more : array() ) as $mk ) {
			$mk = (string) $mk;
			if ( '' !== $mk && false !== stripos( $note, $mk ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * One lead's measurements from its notes (newest first): the newest block that carries a size; only when none does,
	 * the lead's other notes. { items, unsized, ambiguous, from: 'block'|'notes'|'' }.
	 */
	public static function lead_items( array $notes ): array {
		$sets  = self::block_markers();
		$empty = array( 'items' => array(), 'unsized' => array(), 'ambiguous' => 0, 'from' => '' );
		$first_block = null;
		foreach ( $notes as $n ) {
			$n = (string) $n;
			if ( self::is_machine( $n ) || ! self::is_block( $n, $sets ) ) {
				continue;
			}
			$p = self::parse_text( $n, true );
			if ( $p['items'] ) {
				return $p + array( 'from' => 'block' );
			}
			if ( null === $first_block ) {
				$first_block = $p;   // unsized: its units still count as "no size written"
			}
		}
		$own = array( 'items' => array(), 'unsized' => array(), 'ambiguous' => 0 );
		foreach ( $notes as $n ) {
			$n = (string) $n;
			if ( self::is_machine( $n ) || self::is_block( $n, $sets ) ) {
				continue;
			}
			$p = self::parse_text( $n, false );
			$own['items']      = array_merge( $own['items'], $p['items'] );
			$own['ambiguous'] += $p['ambiguous'];
		}
		if ( $own['items'] ) {
			return $own + array( 'from' => 'notes' );
		}
		return null !== $first_block ? $first_block + array( 'from' => 'block' ) : $empty;
	}

	/* ═══════════════════════════ the question (pure) ═══════════════════════════ */

	/** The catalog's kinds a question may name: kind => [ words ]. */
	public static function kinds(): array {
		if ( is_array( self::$kinds ) ) {
			return self::$kinds;
		}
		$out = array();
		if ( class_exists( 'ZDZ_Item_Engine' ) && method_exists( 'ZDZ_Item_Engine', 'all' ) ) {
			foreach ( (array) ZDZ_Item_Engine::all() as $id => $it ) {
				if ( ! is_array( $it ) || '' === (string) $id ) {
					continue;
				}
				$words = array_merge( array( (string) ( $it['display_name'] ?? '' ) ), array_map( 'strval', (array) ( $it['aliases'] ?? array() ) ) );
				$out[ (string) $id ] = array_values( array_filter( array_map( 'trim', $words ), 'strlen' ) );
			}
		}
		return $out;
	}

	/**
	 * A measurement question, or null: { kinds: [ids], years: [yyyy] }. It must ask for sizes, dimensions or
	 * measurements, and name a catalog kind or ask about all of them / the typical one. A question about one document
	 * ("#1234") is not this route's.
	 */
	public static function detect( $message, ?int $now = null ): ?array {
		$m = strtolower( trim( (string) $message ) );
		if ( '' === $m || strlen( $m ) > 600 || preg_match( '/#\s*\d{2,}/', $m ) ) {
			return null;
		}
		if ( ! preg_match( '/\b(?:sizes?|dimensions?|measurements?|measured)\b/', $m ) ) {
			return null;
		}
		$named = array();
		foreach ( self::kinds() as $id => $words ) {
			foreach ( $words as $w ) {
				$w = strtolower( $w );
				if ( '' !== $w && preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( $w, '/' ) . '(?:s|es)?(?![\p{L}\p{N}])/u', $m ) ) {
					$named[] = (string) $id;
					break;
				}
			}
		}
		if ( ! $named && ! preg_match( '/\b(?:all|every|average|typical|most common|usual)\b/', $m ) ) {
			return null;
		}
		$now   = $now ?? time();
		$year  = (int) gmdate( 'Y', $now );
		$years = array();
		if ( preg_match_all( '/\b(19|20)\d{2}\b/', $m, $ym ) ) {
			foreach ( $ym[0] as $y ) {
				$years[] = (int) $y;
			}
		}
		if ( preg_match( '/\bthis year\b/', $m ) ) {
			$years[] = $year;
		}
		if ( preg_match( '/\blast year\b/', $m ) ) {
			$years[] = $year - 1;
		}
		$years = array_values( array_unique( array_filter( $years, static function ( $y ) use ( $year ) {
			return $y >= 1990 && $y <= $year;
		} ) ) );
		sort( $years );
		return array( 'kinds' => array_values( array_unique( $named ) ), 'years' => $years );
	}

	/* ═══════════════════════════ counting and words (pure) ═══════════════════════════ */

	/**
	 * Every lead's measurements added up per kind: { kind => { sizes: { key => {label, units, jobs} }, units, unsized,
	 * jobs, rect: [ [a, b] … ] } } plus the counts of leads and not-readable lines.
	 *
	 * @param array $leads lead id => lead_items() answer
	 */
	public static function aggregate( array $leads, array $kinds = array() ): array {
		$out = array( 'kinds' => array(), 'leads' => 0, 'ambiguous' => 0 );
		foreach ( $leads as $lid => $li ) {
			$seen = array();
			$out['ambiguous'] += (int) ( $li['ambiguous'] ?? 0 );
			foreach ( (array) ( $li['items'] ?? array() ) as $it ) {
				$k = (string) $it['kind'];
				if ( $kinds && ! in_array( $k, $kinds, true ) ) {
					continue;
				}
				$row = &$out['kinds'][ $k ];
				if ( null === $row ) {
					$row = array( 'sizes' => array(), 'units' => 0, 'unsized' => 0, 'jobs' => 0, 'rect' => array() );
				}
				$key = 'rect' === $it['shape'] ? self::n( $it['a'] ) . ' × ' . self::n( $it['b'] ) : 'Ø ' . self::n( $it['d'] );
				if ( ! isset( $row['sizes'][ $key ] ) ) {
					$row['sizes'][ $key ] = array( 'units' => 0, 'jobs' => 0, 'leads' => array() );
				}
				$row['sizes'][ $key ]['units'] += (int) $it['qty'];
				$row['sizes'][ $key ]['leads'][ (string) $lid ] = true;
				$row['units'] += (int) $it['qty'];
				if ( 'rect' === $it['shape'] ) {
					for ( $i = 0; $i < min( 200, (int) $it['qty'] ); $i++ ) {
						$row['rect'][] = array( (float) $it['a'], (float) $it['b'] );
					}
				}
				$seen[ $k ] = true;
				unset( $row );
			}
			foreach ( (array) ( $li['unsized'] ?? array() ) as $k => $q ) {
				if ( $kinds && ! in_array( (string) $k, $kinds, true ) ) {
					continue;
				}
				if ( ! isset( $out['kinds'][ $k ] ) ) {
					$out['kinds'][ $k ] = array( 'sizes' => array(), 'units' => 0, 'unsized' => 0, 'jobs' => 0, 'rect' => array() );
				}
				$out['kinds'][ $k ]['unsized'] += (int) $q;
				$seen[ $k ] = true;
			}
			foreach ( array_keys( $seen ) as $k ) {
				$out['kinds'][ $k ]['jobs']++;
			}
			if ( $seen ) {
				$out['leads']++;
			}
		}
		foreach ( $out['kinds'] as $k => $row ) {
			foreach ( $row['sizes'] as $key => $s ) {
				$out['kinds'][ $k ]['sizes'][ $key ]['jobs'] = count( $s['leads'] );
				unset( $out['kinds'][ $k ]['sizes'][ $key ]['leads'] );
			}
			uasort( $out['kinds'][ $k ]['sizes'], static function ( $x, $y ) {
				return array( $y['units'], $y['jobs'] ) <=> array( $x['units'], $x['jobs'] );
			} );
		}
		return $out;
	}

	/** The middle size of a kind's rectangles, each side on its own: [a, b] or null. */
	public static function median_sides( array $rect ): ?array {
		$n = count( $rect );
		if ( 0 === $n ) {
			return null;
		}
		$med = static function ( int $i ) use ( $rect, $n ): float {
			$v = array_map( static function ( $r ) use ( $i ) {
				return (float) $r[ $i ];
			}, $rect );
			sort( $v );
			return 0 === $n % 2 ? ( $v[ $n / 2 - 1 ] + $v[ $n / 2 ] ) / 2 : $v[ ( $n - 1 ) / 2 ];
		};
		return array( $med( 0 ), $med( 1 ) );
	}

	/** The answer's words (markdown): one table per kind, the units with no size written, and how it was read. */
	public static function render( array $agg, array $f, string $coverage = '' ): string {
		$years = (array) ( $f['years'] ?? array() );
		$when  = $years ? ' (' . implode( ', ', $years ) . ')' : '';
		$out   = array( __( 'Sizes written in the measurement notes', 'zorderz' ) . $when . ':' );
		if ( ! $agg['kinds'] ) {
			$out[] = '';
			$out[] = __( 'No measured item with a written size is in the notes I have.', 'zorderz' );
		}
		foreach ( $agg['kinds'] as $k => $row ) {
			$out[] = '';
			/* translators: 1: item, 2: units, 3: jobs */
			$out[] = '**' . sprintf( __( '%1$s: %2$d with a size, on %3$d job(s)', 'zorderz' ), self::label_of( (string) $k ), (int) $row['units'], (int) $row['jobs'] ) . '**';
			$med = self::median_sides( $row['rect'] );
			if ( null !== $med ) {
				/* translators: %s: the typical size */
				$out[] = sprintf( __( 'Typical (middle) size: %s', 'zorderz' ), self::n( $med[0] ) . ' × ' . self::n( $med[1] ) );
			}
			if ( $row['sizes'] ) {
				$out[] = '';
				$out[] = '| ' . __( 'Size', 'zorderz' ) . ' | ' . __( 'Units', 'zorderz' ) . ' | ' . __( 'Jobs', 'zorderz' ) . ' |';
				$out[] = '|---|---|---|';
				foreach ( array_slice( $row['sizes'], 0, self::TABLE_ROWS, true ) as $key => $s ) {
					$out[] = '| ' . $key . ' | ' . (int) $s['units'] . ' | ' . (int) $s['jobs'] . ' |';
				}
				if ( count( $row['sizes'] ) > self::TABLE_ROWS ) {
					/* translators: %d: sizes not shown */
					$out[] = sprintf( __( '…and %d more sizes.', 'zorderz' ), count( $row['sizes'] ) - self::TABLE_ROWS );
				}
			}
			if ( (int) $row['unsized'] > 0 ) {
				/* translators: %d: units with no size written */
				$out[] = sprintf( __( '%d more with no size written (not counted above, never guessed).', 'zorderz' ), (int) $row['unsized'] );
			}
		}
		if ( (int) $agg['ambiguous'] > 0 ) {
			$out[] = '';
			/* translators: %d: lines */
			$out[] = sprintf( __( '%d line(s) had three numbers in a row and were not read as a size.', 'zorderz' ), (int) $agg['ambiguous'] );
		}
		$out[] = '';
		$out[] = __( 'Sizes are as written, short side first. On each job I read its newest measurement block; a salesperson\'s own notes only when no block on the job has a size.', 'zorderz' ) . ( '' !== $coverage ? ' ' . $coverage : '' );
		return implode( "\n", $out );
	}

	private static function n( $v ): string {
		$v = (float) $v;
		return rtrim( rtrim( number_format( $v, 2, '.', '' ), '0' ), '.' );
	}

	/* ═══════════════════════════ reading and answering ═══════════════════════════ */

	/** The notes copy, newest first, grouped by lead: lead id => [ body … ]. Only notes that can hold a size are read. */
	public static function load( array $years = array() ): array {
		$rows = is_array( self::$rows ) ? self::$rows : null;
		if ( null === $rows ) {
			global $wpdb;
			if ( ! isset( $wpdb ) || ! class_exists( 'ZANA_Ledger_Store' ) ) {
				return array();
			}
			$t     = ZANA_Ledger_Store::table( 'notes' );
			$where = "body REGEXP '[0-9][^0-9]{0,16}(x|X|by|BY|[*]|×)[^0-9]{0,12}[0-9]|dia|Ø'";
			$args  = array();
			if ( $years ) {
				$where .= ' AND YEAR(created_at) IN (' . implode( ',', array_fill( 0, count( $years ), '%d' ) ) . ')';
				$args   = array_map( 'intval', $years );
			}
			$sql  = "SELECT lead_id, created_at, body FROM {$t} WHERE {$where} ORDER BY created_at DESC LIMIT " . ( self::MAX_LEADS * 4 );
			$rows = (array) $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL -- table name and fixed SQL; the years are bound
		}
		$out = array();
		foreach ( $rows as $r ) {
			$lid = (string) ( $r['lead_id'] ?? '' );
			if ( '' === $lid ) {
				continue;
			}
			if ( $years && ! in_array( (int) substr( (string) ( $r['created_at'] ?? '' ), 0, 4 ), array_map( 'intval', $years ), true ) ) {
				continue;
			}
			if ( ! isset( $out[ $lid ] ) && count( $out ) >= self::MAX_LEADS ) {
				continue;
			}
			$out[ $lid ][] = (string) ( $r['body'] ?? '' );
		}
		return $out;
	}

	/** The answer for a person, or a refusal in words. */
	public static function answer( int $uid, array $f ): string {
		if ( class_exists( 'ZANA_Ledger_Access' ) ) {
			$why = ZANA_Ledger_Access::why_not( ZANA_Ledger_Access::context( $uid ), 'leads' );
			if ( '' !== $why ) {
				return ZANA_Ledger_Access::refusal_text( $why );
			}
		} elseif ( $uid <= 0 ) {
			return __( 'Sign in first.', 'zorderz' );
		}
		$leads = array();
		foreach ( self::load( (array) ( $f['years'] ?? array() ) ) as $lid => $notes ) {
			$leads[ $lid ] = self::lead_items( $notes );
		}
		$cov = '';
		$c   = array();
		if ( ! is_array( self::$rows ) && class_exists( 'ZANA_Ledger' ) && method_exists( 'ZANA_Ledger', 'coverage' ) ) {
			try {
				$c = (array) ZANA_Ledger::coverage();
			} catch ( \Throwable $e ) {
				$c = array();
			}
		}
		if ( $c ) {
			if ( ! empty( $c['notes']['last_ok'] ) ) {
				/* translators: %s: when the notes were last copied */
				$cov = sprintf( __( 'Notes copied up to %s (UTC).', 'zorderz' ), (string) $c['notes']['last_ok'] );
			}
			if ( isset( $c['notes']['complete'] ) && ! $c['notes']['complete'] ) {
				$cov .= ( '' !== $cov ? ' ' : '' ) . __( 'The notes are still being copied, so this is not every job yet.', 'zorderz' );
			}
		}
		return self::render( self::aggregate( $leads, (array) ( $f['kinds'] ?? array() ) ), $f, $cov );
	}

	/** The chat's rule answer for a measurement question, or null (another route or the model answers). */
	public static function route( int $uid, string $message ): ?string {
		$f = self::detect( $message );
		return null === $f ? null : self::answer( $uid, $f );
	}

	/** The ask bar's fast lane (zana_quick_routes). */
	public static function quick_route( $routes ) {
		$routes   = is_array( $routes ) ? $routes : array();
		$routes[] = static function ( $question, $uid ) {
			$a = self::route( (int) $uid, (string) $question );
			return null === $a ? null : array( self::KIND, $a );
		};
		return $routes;
	}
}
