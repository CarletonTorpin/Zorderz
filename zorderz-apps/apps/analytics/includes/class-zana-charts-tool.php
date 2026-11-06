<?php
/**
 * ZANA_Charts_Tool — the chat uses the Charts app as its calculator.
 *
 * The chat never writes English for Charts and never does sums the Charts hub can do. When Charts is on for the person
 * (ZCH_Chat_Bridge::is_available), the chat's instructions get Charts' closed lists for THIS person
 * (ZCH_Chat_Bridge::vocab_for_chat: the measures they may see, the splits, the business's own products, work types,
 * areas, tags and sources, the period grammar) and the rule: to get figures, answer with up to four
 * `[ZANA_CHARTS_RUN]{spec}[/ZANA_CHARTS_RUN]` blocks and nothing else. Then:
 *
 *   1. each spec is checked here against those lists (a wrong value is a problem the model is told, never a guess);
 *   2. each is run through the registry verb `charts.run` as the signed-in person (ZDZ_Capabilities::invoke →
 *      ZCH_Chat_Bridge: Charts' own access — money, by-person, scope — and the hub's floors apply again);
 *   3. the rows come back into the SAME turn as a fenced CHARTS RESULTS block, and the model is asked once more to answer
 *      from those rows only (block()): it may compare and infer across the charts, and every figure it quotes is a row;
 *   4. each chart that ran gets an "Open in Charts" button under the answer (chips_html()), carrying Charts' own public
 *      spec — never a model's: any chip markup a model wrote is removed first (strip_chip_markup()).
 * A chart block left in the final answer is run and drawn as a small table (handle_markers()).
 *
 * Also through charts.run: who covers which ZIP codes ({territories: {who}}, Territories' own answers as the person
 * asking) and a canvassing recipe ({prospect: …}). A draft for someone else that names a zone only the admin tier may see
 * is taken out (guard_admin_only_zones()).
 *
 * Never the shared device, never with a web search (the results are business data). Four charts a turn.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Charts_Tool {

	const MAX_PER_TURN = 4;
	const MARKER       = 'ZANA_CHARTS_RUN';
	const VERB         = 'charts.run';
	const MAX_LIST     = 120;

	const PERIOD_RE = '/^(?:all|ytd|qtd|mtd|this_year|last_\d{1,3}[dwmy]|(?:\d{4}(?:-(?:q[1-4]|h[12]|w\d{2}|winter|spring|summer|fall|\d{2}(?:-\d{2})?))?)|(?:(?:\d{4}(?:-(?:q[1-4]|h[12]|w\d{2}|winter|spring|summer|fall|\d{2}(?:-\d{2})?))?)|start)?\.\.(?:(?:\d{4}(?:-(?:q[1-4]|h[12]|w\d{2}|winter|spring|summer|fall|\d{2}(?:-\d{2})?))?)|now|today)?)$/i';

	/** Spec keys a chart may carry (anything else is dropped). */
	const KEYS = array( 'measure', 'periods', 'compare', 'to_date', 'months', 'grain', 'date_part', 'split', 'facet', 'filters', 'agg', 'rank', 'top_n', 'share', 'chart_type', 'orientation', 'labels', 'weather' );

	/** The list filters every Charts vocabulary may carry; a vocabulary's own `list_filters` add to them. */
	const LISTS = array( 'products', 'work_types', 'areas', 'regions', 'salespeople', 'lead_sources' );

	/** Tests hand in: the vocabulary (array), the verb's answer (callable $spec, $uid), and the admin-only zone names. */
	public static $vocab_for   = null;
	public static $invoke      = null;
	public static $admin_zones = null;

	/** @var array this turn's results, in order */
	private static $results = array();
	private static $admin_zones_memo = null;

	public static function reset(): void {
		self::$results          = array();
		self::$admin_zones_memo = null;
	}

	public static function results(): array {
		return self::$results;
	}

	/* ------------------------------------------------------------------ availability */

	/** May this turn use Charts? Not the shared device, and the person must have Charts. */
	public static function available( int $uid, bool $shared_device = false ): bool {
		if ( $shared_device || $uid <= 0 ) {
			return false;
		}
		if ( null !== self::$vocab_for ) {
			return ! empty( self::$vocab_for['measures'] );
		}
		if ( ! class_exists( 'ZCH_Chat_Bridge' ) || ! method_exists( 'ZCH_Chat_Bridge', 'is_available' ) ) {
			return false;
		}
		try {
			return (bool) ZCH_Chat_Bridge::is_available( $uid );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/** The closed lists for this person (an empty array when Charts is off for them or the hub is away). */
	public static function vocab( int $uid ): array {
		if ( null !== self::$vocab_for ) {
			return (array) self::$vocab_for;
		}
		if ( ! class_exists( 'ZCH_Chat_Bridge' ) || ! method_exists( 'ZCH_Chat_Bridge', 'vocab_for_chat' ) ) {
			return array();
		}
		try {
			$v = ZCH_Chat_Bridge::vocab_for_chat( $uid );
			return is_array( $v ) && ! empty( $v['measures'] ) ? $v : array();
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	/* ------------------------------------------------------------------ the instructions */

	private static function listed( array $vocab, string $key, int $max = self::MAX_LIST ): string {
		$v = array_values( array_filter( array_map( 'strval', (array) ( $vocab[ $key ] ?? array() ) ), 'strlen' ) );
		if ( ! $v ) {
			return '(none)';
		}
		return implode( ', ', array_slice( $v, 0, $max ) ) . ( count( $v ) > $max ? ' … and ' . ( count( $v ) - $max ) . ' more' : '' );
	}

	/** The instructions block: the marker, the keys, the lists, the rules. '' when Charts is unavailable. */
	public static function prompt_block( array $vocab ): string {
		if ( empty( $vocab['measures'] ) ) {
			return '';
		}
		$splits = array();
		foreach ( (array) ( $vocab['splits'] ?? array() ) as $s ) {
			if ( is_array( $s ) && ! empty( $s['key'] ) ) {
				$splits[] = $s['key'] . ( ! empty( $s['label'] ) && $s['label'] !== $s['key'] ? ' (' . $s['label'] . ')' : '' );
			}
		}
		$tags = array();
		foreach ( (array) ( $vocab['tag_groups'] ?? array() ) as $g ) {
			if ( is_array( $g ) && ! empty( $g['tags'] ) ) {
				$tags[] = ( $g['name'] ?? 'tags' ) . ': ' . implode( ', ', array_slice( array_map( 'strval', (array) $g['tags'] ), 0, 40 ) );
			}
		}
		$regions = array();
		foreach ( (array) ( $vocab['regions'] ?? array() ) as $r ) {
			if ( is_array( $r ) && ! empty( $r['name'] ) ) {
				$regions[] = (string) $r['name'] . ' (' . implode( ', ', array_slice( array_map( 'strval', (array) ( $r['towns'] ?? array() ) ), 0, 30 ) ) . ')';
			}
		}
		$years   = (array) ( $vocab['years'] ?? array() );
		$span    = 2 === count( $years ) ? $years[0] . '–' . $years[1] : 'every year on file';
		$money   = ! empty( $vocab['money'] );
		$compare = empty( $vocab['by_person'] ) && ! empty( $vocab['compare'] ) && ! empty( $vocab['salespeople'] );
		$extra   = array_values( array_diff( array_map( 'strval', (array) ( $vocab['list_filters'] ?? array() ) ), self::LISTS, array( 'tags' ) ) );
		$out     = "CHARTS — the business's sales-chart app; every number it returns is computed on the Charts hub from every invoice (data {$span}"
			. ( ! empty( $vocab['data_as_of'] ) ? ', as of ' . $vocab['data_as_of'] : '' ) . ").\n"
			. 'Whenever a question is about totals, trends, comparisons, rankings, shares, averages or breakdowns of sales'
			. ( $money ? ' (revenue, jobs, customers, units)' : ' (jobs, customers, units)' ) . ' over periods, places, products, work types, people or sources, '
			. 'do not work the figures out yourself: answer ONLY with up to ' . self::MAX_PER_TURN . " chart requests, one per line, and nothing else:\n"
			. '[' . self::MARKER . ']{spec}[/' . self::MARKER . "]\n"
			. "The charts run, and you are then given their rows to write the answer from. One question per spec; split a multi-part ask into several specs.\n"
			. "A spec is JSON with ONLY these keys (anything else is refused):\n"
			. '- measure: ' . implode( ' | ', (array) $vocab['measures'] ) . ( $money ? '' : '   (no money figures for this person)' ) . "\n"
			. "- periods: a list of period tokens — \"all\", \"ytd\", \"qtd\", \"mtd\", \"this_year\", \"last_<n>d|w|m|y\", \"YYYY\", \"YYYY-MM\", \"YYYY-q1\"…\"YYYY-q4\", \"YYYY-h1\"/\"YYYY-h2\", \"YYYY-winter|spring|summer|fall\", \"A..B\" (\"2021..now\"). Several tokens = those periods side by side.\n"
			. "- grain: none | year | quarter | month | week | day\n"
			. "- date_part: month_of_year | quarter_of_year | week_of_year | day_of_week | season\n"
			. "- months: [1..12] keep only these months\n"
			. "- compare: prior_year | prior_period | growth\n"
			. '- split: ' . ( $splits ? implode( ' | ', $splits ) : '(none)' ) . "\n"
			. '- filters: { products:[…], work_types:[…], areas:[…], ' . ( $regions ? 'regions:[…], ' : '' ) . ( ! empty( $vocab['salespeople'] ) ? 'salespeople:[…], ' : '' )
			. 'tags:[…], lead_sources:[…], ' . ( $extra ? implode( ':[…], ', $extra ) . ':[…], ' : '' ) . 'payment:"paid"|"unpaid", customer_type:"business"|"residential", repeat_customers_only:true, '
			. ( $money ? 'job_min:<dollars>, job_max:<dollars>, ' : '' ) . "exclude_<list>:[…] } — values spelled EXACTLY as in the lists below\n"
			. "- agg: avg_per_period | per_job | jobs_per_customer | job_range ; rank: {\"dir\":\"top\"|\"bottom\",\"n\":1-15} ; top_n: 3-15 ; share: true\n"
			. "- chart_type: auto | stacked_bar | grouped_bar | percent_bar | line | area | pie | donut | table | map\n"
			. "RULES: (1) Only what these keys allow. (2) A concept with no key or list value is NOT invented: leave it out and say it was not available. "
			. "(3) \"jobs\" are invoices. (4) To compare with a window from an earlier answer, ask for BOTH windows, so every figure quoted is a row of this turn. "
			. "(5) A single customer, an email, a document or a look-up is not a chart: answer those as usual.\n"
			. ( $compare ? '(6) THIS PERSON sees their own jobs' . ( $money ? ' with their own dollar figures' : '' ) . '. A chart split by or filtered to another salesperson is a COMPARISON: job counts only, never another person\'s dollars.' . "\n" : '' )
			. 'LISTS — products: ' . self::listed( $vocab, 'products' ) . "\n"
			. 'work_types: ' . self::listed( $vocab, 'work_types' ) . "\n"
			. 'areas: ' . self::listed( $vocab, 'areas' ) . "\n"
			. ( $regions ? 'regions: ' . implode( '; ', $regions ) . "\n" : '' )
			. ( $tags ? 'tags — ' . implode( '; ', $tags ) . "\n" : '' )
			. 'lead_sources: ' . self::listed( $vocab, 'lead_sources', 40 ) . "\n"
			. ( ! empty( $vocab['salespeople'] ) ? 'salespeople (initials): ' . self::listed( $vocab, 'salespeople' ) . "\n" : '' )
			. 'EXAMPLE: "jobs by month this year vs last" → [' . self::MARKER . ']{"measure":"jobs","periods":["this_year"],"grain":"month","compare":"prior_year"}[/' . self::MARKER . ']';
		if ( ! empty( $vocab['territories'] ) ) {
			$out .= "\nTERRITORIES — who covers which ZIP codes, and where each kind of work is offered: a spec {\"territories\": {\"who\": \"me\"}} "
				. '(or "all", "zones", "zone:<id>", "zip:NNNNN", "person:<initials or first name>"). It is not a sales figure. Never invent a territory, a ZIP code or a zone.';
		}
		if ( ! empty( $vocab['prospect']['ingredients'] ) ) {
			$out .= "\nWHERE TO CANVASS — a spec {\"prospect\": {recipe}} ranks neighborhoods (product, ingredients {name: 0..1}, exclude, how_many, near). "
				. 'Answer from its rows only; never invent a neighborhood, a street or a reason, and never name a customer.';
		}
		return $out;
	}

	/* ------------------------------------------------------------------ checking a spec */

	private static function first_of( array $vocab, string $key ): array {
		return array_values( array_map( 'strval', (array) ( $vocab[ $key ] ?? array() ) ) );
	}

	private static function region_names( array $vocab ): array {
		$out = array();
		foreach ( (array) ( $vocab['regions'] ?? array() ) as $r ) {
			if ( is_array( $r ) && ! empty( $r['name'] ) ) {
				$out[] = (string) $r['name'];
			}
		}
		foreach ( array_keys( (array) ( $vocab['region_words'] ?? array() ) ) as $w ) {
			if ( is_string( $w ) && '' !== $w ) {
				$out[] = $w;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** What a territories spec may say. */
	const TERRITORY_WHO_RE = '/^(me|all|zones|zone:[a-z0-9_]{1,24}|zip:\d{5}|person:[a-z][a-z.\-]{1,19})$/D';

	/**
	 * Check a spec against the closed lists: array( spec-or-null, problems[] ). Unknown keys are dropped (a model adds
	 * commentary keys); a wrong VALUE is a problem the model is told about. A list a person may not filter by (another
	 * salesperson without by-person or compare, dollars without money) is refused, not quietly widened.
	 */
	public static function validate( $spec, array $vocab ): array {
		if ( is_string( $spec ) ) {
			$spec = json_decode( $spec, true );
		}
		if ( ! is_array( $spec ) || ! $spec ) {
			return array( null, array( 'the spec is not an object' ) );
		}
		if ( array_key_exists( 'territories', $spec ) ) {
			if ( empty( $vocab['territories'] ) ) {
				return array( null, array( 'territories are not available to this person' ) );
			}
			$who = is_array( $spec['territories'] ) ? ( $spec['territories']['who'] ?? null ) : $spec['territories'];
			$who = is_string( $who ) ? strtolower( trim( $who ) ) : '';
			return preg_match( self::TERRITORY_WHO_RE, $who )
				? array( array( 'territories' => array( 'who' => $who ) ), array() )
				: array( null, array( 'territories.who must be me, all, zones, zone:<id>, zip:NNNNN or person:<initials or first name>' ) );
		}
		if ( array_key_exists( 'prospect', $spec ) ) {
			return ( is_array( $spec['prospect'] ) && ! empty( $vocab['prospect']['ingredients'] ) )
				? array( array( 'prospect' => $spec['prospect'] ), array() )
				: array( null, array( 'canvassing is not available to this person' ) );
		}
		$problems = array();
		$out      = array_intersect_key( $spec, array_flip( self::KEYS ) );
		foreach ( array( 'measure', 'grain', 'date_part', 'compare', 'agg', 'chart_type', 'split', 'facet', 'orientation' ) as $sk ) {
			if ( isset( $out[ $sk ] ) && null !== $out[ $sk ] && ! is_scalar( $out[ $sk ] ) ) {
				$problems[] = $sk . ' must be a single value';
				unset( $out[ $sk ] );
			}
		}
		$measures = self::first_of( $vocab, 'measures' );
		if ( empty( $out['measure'] ) ) {
			$out['measure'] = in_array( 'jobs', $measures, true ) ? 'jobs' : ( $measures[0] ?? 'jobs' );
		} elseif ( ! in_array( (string) $out['measure'], $measures, true ) ) {
			$problems[] = 'measure "' . $out['measure'] . '" is not one of ' . implode( ', ', $measures );
		}
		$grains = self::first_of( $vocab, 'grains' ) ?: array( 'none', 'year', 'quarter', 'month', 'week', 'day' );
		if ( isset( $out['grain'] ) && null !== $out['grain'] && ! in_array( (string) $out['grain'], $grains, true ) ) {
			$problems[] = 'grain "' . $out['grain'] . '" is not one of ' . implode( ', ', $grains );
		}
		foreach ( array( 'date_part' => 'date_parts', 'compare' => 'compares', 'agg' => 'aggs', 'chart_type' => 'chart_types' ) as $k => $lk ) {
			$allowed = self::first_of( $vocab, $lk );
			if ( isset( $out[ $k ] ) && null !== $out[ $k ] && '' !== $out[ $k ] && $allowed && ! in_array( (string) $out[ $k ], $allowed, true ) ) {
				$problems[] = $k . ' "' . $out[ $k ] . '" is not one of ' . implode( ', ', $allowed );
			}
		}
		$split_keys = array();
		foreach ( (array) ( $vocab['splits'] ?? array() ) as $s ) {
			$split_keys[] = is_array( $s ) ? (string) ( $s['key'] ?? '' ) : (string) $s;
		}
		foreach ( array( 'split', 'facet' ) as $k ) {   // an empty list means no split for this person, never "anything goes"
			if ( ! empty( $out[ $k ] ) && 'none' !== $out[ $k ] && ! in_array( (string) $out[ $k ], $split_keys, true ) ) {
				$problems[] = $k . ' "' . $out[ $k ] . '" is not one Charts has (' . implode( ', ', array_filter( $split_keys ) ) . ')';
			}
		}
		if ( isset( $out['periods'] ) ) {
			$out['periods'] = is_string( $out['periods'] ) ? array( $out['periods'] ) : $out['periods'];
			if ( ! is_array( $out['periods'] ) ) {
				$problems[] = 'periods must be a list of period tokens';
			} else {
				$out['periods'] = array_values( array_map( 'strval', $out['periods'] ) );
				if ( count( $out['periods'] ) > 12 ) {
					$problems[] = 'at most 12 periods';
				}
				foreach ( $out['periods'] as $t ) {
					if ( ! preg_match( self::PERIOD_RE, strtolower( $t ) ) ) {
						$problems[] = 'period "' . $t . '" is not a period token';
					}
				}
			}
		}
		if ( isset( $out['months'] ) ) {
			$m = array();
			foreach ( is_array( $out['months'] ) ? $out['months'] : array( 'x' ) as $x ) {
				if ( ! is_int( $x ) && ! ( is_string( $x ) && ctype_digit( $x ) ) || (int) $x < 1 || (int) $x > 12 ) {
					$problems[] = 'months must be whole numbers 1–12';
					break;
				}
				$m[] = (int) $x;
			}
			$out['months'] = array_values( array_unique( $m ) );
		}
		if ( isset( $out['filters'] ) ) {
			list( $f, $fp ) = self::check_filters( $out['filters'], $vocab );
			$problems       = array_merge( $problems, $fp );
			if ( $f ) {
				$out['filters'] = $f;
			} else {
				unset( $out['filters'] );
			}
		}
		if ( isset( $out['rank'] ) ) {
			$r = is_array( $out['rank'] ) ? $out['rank'] : array();
			if ( ! in_array( $r['dir'] ?? '', array( 'top', 'bottom' ), true ) ) {
				$problems[] = 'rank.dir must be top or bottom';
			} else {
				$out['rank'] = array( 'dir' => $r['dir'], 'n' => max( 1, min( 15, (int) ( $r['n'] ?? 5 ) ) ) );
			}
		}
		if ( isset( $out['top_n'] ) ) {
			$out['top_n'] = max( 3, min( 15, (int) $out['top_n'] ) );
		}
		foreach ( array( 'share', 'to_date' ) as $b ) {
			if ( isset( $out[ $b ] ) ) {
				$out[ $b ] = (bool) $out[ $b ];
			}
		}
		if ( isset( $out['weather'] ) ) {
			$w    = is_array( $out['weather'] ) ? $out['weather'] : array();
			$var  = (string) ( $w['var'] ?? 'tmax' );
			$view = (string) ( $w['view'] ?? 'overlay' );
			if ( ! in_array( $var, array( 'tmax', 'tmin', 'tavg', 'prcp', 'rain_days', 'hot_days' ), true ) || ! in_array( $view, array( 'overlay', 'scatter', 'bands', 'only' ), true ) ) {
				$problems[] = 'weather.var must be tmax, tmin, tavg, prcp, rain_days or hot_days, and weather.view overlay, scatter or bands';
			} else {
				$out['weather'] = array( 'var' => $var, 'view' => $view ) + array_filter( array( 'color_by' => ! empty( $w['color_by'] ), 'labels' => ! empty( $w['labels'] ), 'by_group' => ! empty( $w['by_group'] ) ) );
			}
		}
		return $problems ? array( null, $problems ) : array( $out, array() );
	}

	/** The filters: known lists with values from the lists, the few plain filters, nothing about one customer. */
	private static function check_filters( $filters, array $vocab ): array {
		if ( ! is_array( $filters ) ) {
			return array( array(), array( 'filters must be an object' ) );
		}
		$lists = array_values( array_unique( array_merge( self::LISTS, array_map( 'strval', (array) ( $vocab['list_filters'] ?? array() ) ) ) ) );
		$lists = array_values( array_diff( $lists, array( 'accounts', 'customers' ) ) );
		$tags  = array();
		foreach ( (array) ( $vocab['tag_groups'] ?? array() ) as $g ) {
			foreach ( (array) ( $g['tags'] ?? array() ) as $t ) {
				$tags[] = (string) $t;
			}
		}
		$f = array();
		$p = array();
		foreach ( $filters as $k => $v ) {
			$k    = (string) $k;
			$base = 0 === strpos( $k, 'exclude_' ) ? substr( $k, 8 ) : $k;
			if ( in_array( strtolower( $base ), array( 'accounts', 'customers' ), true ) ) {
				continue;   // never from the chat
			}
			if ( 'salespeople' === $base && empty( $vocab['by_person'] ) && empty( $vocab['compare'] ) ) {
				$p[] = $k . ': by-person figures are not available to this person';
				continue;
			}
			if ( 'customer_type' === $k || 'payment' === $k ) {
				$ok = 'customer_type' === $k ? array( 'any', 'business', 'residential' ) : array( 'any', 'paid', 'unpaid' );
				if ( in_array( $v, $ok, true ) ) {
					$f[ $k ] = $v;
				} else {
					$p[] = $k . ' must be one of ' . implode( ', ', array_slice( $ok, 1 ) );
				}
				continue;
			}
			if ( 'repeat_customers_only' === $k ) {
				$f[ $k ] = (bool) $v;
				continue;
			}
			if ( 'job_min' === $k || 'job_max' === $k ) {
				if ( empty( $vocab['money'] ) ) {
					$p[] = $k . ': job sizes in dollars are not available to this person';
				} elseif ( ! is_numeric( $v ) || (float) $v < 0 ) {
					$p[] = $k . ' must be a number of dollars, 0 or more';
				} else {
					$f[ $k ] = round( (float) $v, 2 );
				}
				continue;
			}
			if ( 'tags' !== $base && 'zips' !== $base && ! in_array( $base, $lists, true ) ) {
				$p[] = 'filter "' . $k . '" is not one Charts has';
				continue;
			}
			$v = is_string( $v ) ? array( $v ) : $v;
			if ( ! is_array( $v ) ) {
				$p[] = 'filter ' . $k . ' must be a list';
				continue;
			}
			$v    = array_values( array_map( 'strval', $v ) );
			$have = 'tags' === $base ? $tags : ( 'regions' === $base ? self::region_names( $vocab ) : self::first_of( $vocab, $base ) );
			if ( 'zips' === $base ) {
				$v    = array_values( array_filter( $v, function ( $z ) { return (bool) preg_match( '/^\d{5}$/', $z ); } ) );
				$have = array();
			}
			if ( 'regions' === $base && $have ) {
				$lower = array();
				foreach ( $have as $name ) {
					$lower[ strtolower( $name ) ] = $lower[ strtolower( $name ) ] ?? $name;
				}
				$v = array_map( function ( $x ) use ( $lower ) { return $lower[ strtolower( $x ) ] ?? $x; }, $v );
			}
			if ( $have ) {
				$bad = array_values( array_diff( $v, $have ) );
				if ( $bad ) {
					$p[] = $k . ': not in Charts\' list: ' . implode( ', ', array_slice( $bad, 0, 5 ) );
					continue;
				}
			}
			$f[ $k ] = $v;
		}
		if ( isset( $f['job_min'], $f['job_max'] ) && $f['job_min'] >= $f['job_max'] ) {
			$p[] = 'job_min must be below job_max';
			unset( $f['job_min'], $f['job_max'] );
		}
		return array( $f, $p );
	}

	/* ------------------------------------------------------------------ running */

	/** Run specs (a list, or one), within the turn's four. Each result is kept for block() and chips_html(). */
	public static function run_specs( $specs, int $uid, array $vocab, string $origin = 'plan' ): array {
		$out = array();
		if ( ! is_array( $specs ) ) {
			return $out;
		}
		if ( isset( $specs['measure'] ) || isset( $specs['periods'] ) || isset( $specs['prospect'] ) || isset( $specs['territories'] ) ) {
			$specs = array( $specs );
		}
		foreach ( $specs as $raw ) {
			if ( count( self::$results ) >= self::MAX_PER_TURN ) {
				$r = array( 'ok' => false, 'refused' => 'at most ' . self::MAX_PER_TURN . ' charts a turn', 'spec' => $raw, 'origin' => $origin );
				self::$results[] = $r;
				$out[]           = $r;
				break;
			}
			list( $spec, $problems ) = self::validate( $raw, $vocab );
			if ( null === $spec ) {
				$r = array( 'ok' => false, 'refused' => implode( '; ', $problems ), 'spec' => $raw, 'origin' => $origin );
			} else {
				$res = self::invoke( $spec, $uid );
				if ( empty( $res['success'] ) ) {
					$why = ! empty( $res['problems'] ) ? implode( '; ', (array) $res['problems'] ) : (string) ( $res['message'] ?? ( $res['error'] ?? 'Charts did not answer' ) );
					$r   = array( 'ok' => false, 'refused' => $why, 'spec' => $spec, 'origin' => $origin );
					if ( 'territories' === ( $res['kind'] ?? '' ) && '' !== trim( (string) ( $res['message'] ?? '' ) ) ) {
						$r['said'] = trim( (string) $res['message'] );   // Territories' own words, said as written
					}
				} else {
					$r = array( 'ok' => true, 'spec' => $spec, 'origin' => $origin, 'result' => class_exists( 'ZANA_Sealed' ) ? (array) ZANA_Sealed::deep( $res ) : $res );
				}
			}
			self::$results[] = $r;
			$out[]           = $r;
		}
		return $out;
	}

	/** charts.run through the registry, as the signed-in person only. */
	private static function invoke( array $spec, int $uid ): array {
		if ( is_callable( self::$invoke ) ) {
			return (array) call_user_func( self::$invoke, $spec, $uid );
		}
		try {
			if ( class_exists( 'ZDZ_Capabilities' ) && function_exists( 'get_current_user_id' ) && (int) get_current_user_id() === $uid ) {
				return (array) ZDZ_Capabilities::invoke( self::VERB, array( 'spec' => $spec ) );
			}
		} catch ( \Throwable $e ) {
			return array( 'success' => false, 'error' => 'hub_error', 'message' => 'Charts did not answer.' );
		}
		return array( 'success' => false, 'error' => 'not_session_user', 'message' => 'Charts could not be asked for this person.' );
	}

	/** The chart requests in a model's answer, in order: [ raw payload, … ]. */
	public static function requests_in( string $text ): array {
		if ( ! preg_match_all( '/\[' . self::MARKER . '\](.*?)\[\/' . self::MARKER . '\]/s', $text, $m ) ) {
			return array();
		}
		$out = array();
		foreach ( $m[1] as $j ) {
			$out[] = json_decode( trim( $j ), true );
		}
		return $out;
	}

	/** Is this answer nothing but chart requests (the model asked for the figures first)? */
	public static function only_requests( string $text ): bool {
		$rest = trim( (string) preg_replace( '/\[' . self::MARKER . '\].*?\[\/' . self::MARKER . '\]/s', '', $text ) );
		return '' === $rest && array() !== self::requests_in( $text );
	}

	/* ------------------------------------------------------------------ the results block */

	private static function one_line( string $s, int $max = 200 ): string {
		$s = trim( (string) preg_replace( '/\s+/', ' ', $s ) );
		return mb_substr( $s, 0, $max );
	}

	private static function num( $v, string $unit ): string {
		if ( null === $v || '' === $v ) {
			return '—';
		}
		if ( ! is_numeric( $v ) ) {
			return (string) $v;
		}
		$v = (float) $v;
		if ( '$' === $unit ) {
			return '$' . number_format( $v, 2 );
		}
		return floor( $v ) == $v ? number_format( $v ) : number_format( $v, 2 );   // phpcs:ignore Universal.Operators.StrictComparisons
	}

	/** One CHART n section of the results block. */
	public static function describe_result( array $r, int $n ): string {
		if ( empty( $r['ok'] ) && '' !== (string) ( $r['said'] ?? '' ) ) {
			return "--- CHART {$n}: TERRITORIES — NOT ANSWERED ---\nsay exactly this, and nothing about anyone's ZIP codes: " . self::one_line( (string) $r['said'], 300 ) . "\n";
		}
		if ( empty( $r['ok'] ) ) {
			$spec = is_array( $r['spec'] ?? null ) ? (string) json_encode( $r['spec'] ) : (string) ( $r['spec'] ?? '' );
			return "--- CHART {$n}: NOT RUN ---\nspec: " . mb_substr( $spec, 0, 300 ) . "\nwhy: " . (string) ( $r['refused'] ?? 'refused' ) . "\n";
		}
		$res = (array) $r['result'];
		if ( 'territories' === ( $res['kind'] ?? '' ) ) {
			$lines = array( "--- CHART {$n}: TERRITORIES — " . self::one_line( (string) ( $res['title'] ?? '' ), 90 ) . ' ---' );
			foreach ( (array) ( $res['people'] ?? array() ) as $p ) {
				if ( is_array( $p ) ) {
					$zips    = array_values( array_filter( array_map( 'strval', (array) ( $p['zips'] ?? array() ) ), function ( $z ) { return (bool) preg_match( '/^\d{5}$/', $z ); } ) );
					$lines[] = trim( self::one_line( (string) ( $p['initials'] ?? '' ), 4 ) . ' ' . self::one_line( (string) ( $p['name'] ?? '' ), 60 ) )
						. ( ! empty( $p['admin_only'] ) ? ' (admin tier only)' : '' ) . ': '
						. ( $zips ? implode( ', ', array_slice( $zips, 0, 30 ) ) . ( count( $zips ) > 30 ? ' and ' . ( count( $zips ) - 30 ) . ' more' : '' ) : 'no ZIP codes' );
				}
			}
			foreach ( array_merge( array( (string) ( $res['message'] ?? '' ) ), array_map( 'strval', array_slice( (array) ( $res['notes'] ?? array() ), 0, 4 ) ) ) as $say ) {
				if ( '' !== trim( $say ) ) {
					$lines[] = 'say: ' . self::one_line( $say, 300 );
				}
			}
			return implode( "\n", $lines ) . "\n";
		}
		$unit  = (string) ( $res['unit'] ?? '' );
		$cols  = array_values( array_map( 'strval', (array) ( $res['columns'] ?? array() ) ) );
		$lines = array( "--- CHART {$n}: " . self::one_line( (string) ( $res['title'] ?? 'Chart' ), 160 ) . ' ---' );
		$lines[] = 'measure: ' . (string) ( $res['measure'] ?? '' ) . ( '$' === $unit ? ' (dollars)' : '' );
		if ( ! empty( $res['read_as'] ) ) {
			$lines[] = 'read as: ' . implode( ' · ', array_map( 'strval', (array) $res['read_as'] ) );
		}
		if ( ! empty( $res['empty'] ) ) {
			$lines[] = 'rows: NONE (nothing matched)';
		} else {
			$lines[] = 'columns: ' . implode( ' | ', $cols );
			$vi      = array_search( 'value', $cols, true );
			$count   = in_array( (string) ( $res['measure'] ?? '' ), array( 'jobs', 'customers', 'units' ), true ) ? ' ' . $res['measure'] : '';
			foreach ( (array) ( $res['rows'] ?? array() ) as $row ) {
				$row   = array_values( (array) $row );
				$parts = array();
				foreach ( $row as $i => $cell ) {
					$parts[] = $i === $vi ? self::num( $cell, $unit ) . ( '$' === $unit ? '' : $count ) : self::one_line( (string) $cell, 60 );
				}
				$lines[] = implode( ' | ', $parts );
			}
			if ( ! empty( $res['truncated'] ) ) {
				$lines[] = '… ' . (int) ( $res['rows_total'] ?? 0 ) . ' rows in all; only the first ' . count( (array) $res['rows'] ) . ' are shown';
			}
		}
		foreach ( array( 'notes', 'caption' ) as $k ) {
			$v = is_array( $res[ $k ] ?? null ) ? implode( ' ', array_map( 'strval', $res[ $k ] ) ) : (string) ( $res[ $k ] ?? '' );
			if ( '' !== trim( $v ) ) {
				$lines[] = $k . ': ' . self::one_line( $v, 400 );
			}
		}
		return implode( "\n", $lines ) . "\n";
	}

	/** The CHARTS RESULTS block with its rules, for the answer. '' with no results. */
	public static function block( ?array $results = null ): string {
		$results = null === $results ? self::$results : $results;
		if ( ! $results ) {
			return '';
		}
		$out = "CHARTS RESULTS (computed exactly by the Charts hub). These rows are this turn's figures: quote them exactly as written, "
			. 'add, compare and rank across the charts in your words, and say which chart a figure came from ("Chart 1"). Never state a figure these rows cannot give. '
			. 'A chart marked NOT RUN was refused for the reason given: say so in one line; do not guess its numbers. Do not ask for more charts in this answer. '
			. "A table has at most 3 columns and 12 rows; each chart that ran has an Open in Charts button under your answer.\n";
		$n = 0;
		foreach ( $results as $r ) {
			$out .= self::describe_result( $r, ++$n );
		}
		return $out;
	}

	/** Every figure the charts that ran this turn returned (rows and totals), for the outbound gate's figure check. */
	public static function verified_figures(): array {
		$out = array();
		foreach ( self::$results as $r ) {
			if ( empty( $r['ok'] ) ) {
				continue;
			}
			$res  = (array) $r['result'];
			$vals = array_values( (array) ( $res['totals'] ?? array() ) );
			foreach ( (array) ( $res['rows'] ?? array() ) as $row ) {
				foreach ( (array) $row as $cell ) {
					$vals[] = $cell;
				}
			}
			foreach ( $vals as $v ) {
				if ( is_numeric( $v ) ) {
					$out[] = (string) round( (float) $v, 2 );
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/* ------------------------------------------------------------------ chips and the marker */

	private static function b64( $v ): string {
		return rtrim( strtr( base64_encode( (string) json_encode( $v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ), '+/', '-_' ), '=' );
	}

	/** The server's buttons: "Open in Charts" with Charts' own public spec (data-zch, base64url JSON); one map button. */
	public static function chips_html( ?array $results = null ): string {
		$results = null === $results ? self::$results : $results;
		$chips   = array();
		$n       = 0;
		$map     = false;
		foreach ( $results as $r ) {
			$n++;
			if ( empty( $r['ok'] ) || empty( $r['result']['spec_public'] ) ) {
				continue;
			}
			$res   = (array) $r['result'];
			$title = (string) ( '' !== (string) ( $res['title_short'] ?? '' ) ? $res['title_short'] : ( $res['title'] ?? '' ) );
			if ( 'territories' === ( $res['kind'] ?? '' ) ) {
				if ( $map ) {
					continue;
				}
				$map     = true;
				$chips[] = '<button type="button" class="zana-charts-open" data-zch="' . esc_attr( self::b64( $res['spec_public'] ) ) . '">' . "\u{1F5FA}\u{FE0F} " . esc_html__( 'Open the map', 'zorderz' ) . '</button>';
				continue;
			}
			$mini    = self::mini_data( $res );
			$chips[] = '<button type="button" class="zana-charts-open" data-zch="' . esc_attr( self::b64( $res['spec_public'] ) ) . '"'
				. ( $mini ? ' data-mini="' . esc_attr( self::b64( $mini ) ) . '"' : '' ) . ' title="' . esc_attr( $title ) . '">'
				/* translators: 1: chart number, 2: chart title */
				. "\u{1F4CA} " . esc_html( sprintf( __( 'Chart %1$d: %2$s', 'zorderz' ), $n, mb_substr( $title, 0, 110 ) ) ) . '</button>';
		}
		return $chips ? "\n\n<div class=\"zana-charts-chips\">" . implode( ' ', $chips ) . '</div>' : '';
	}

	/**
	 * The figures a small chart above the buttons draws: { u: '$'|'', p: [periods or groups], s: [ { g, v: [] } ] }
	 * (one bar per group when one period is split into groups). null when the rows are not a period series.
	 */
	public static function mini_data( array $res ): ?array {
		$cols = array_values( array_map( 'strval', (array) ( $res['columns'] ?? array() ) ) );
		$pi   = array_search( 'period', $cols, true );
		$gi   = array_search( 'group', $cols, true );
		$vi   = array_search( 'value', $cols, true );
		if ( false === $pi || false === $vi ) {
			return null;
		}
		$periods = array();
		$groups  = array();
		$cells   = array();
		foreach ( (array) ( $res['rows'] ?? array() ) as $row ) {
			$row = array_values( (array) $row );
			$p   = (string) ( $row[ $pi ] ?? '' );
			$g   = false !== $gi ? (string) ( $row[ $gi ] ?? 'All' ) : 'All';
			$v   = $row[ $vi ] ?? null;
			if ( '' === $p || ! is_numeric( $v ) ) {
				continue;
			}
			$periods[ $p ]          = true;
			$groups[ $g ]           = true;
			$cells[ $g ][ $p ]      = round( (float) $v, 2 );
		}
		$periods = array_keys( $periods );
		$groups  = array_map( 'strval', array_keys( $groups ) );
		$u       = '$' === ( $res['unit'] ?? '' ) ? '$' : '';
		if ( 1 === count( $periods ) && count( $groups ) >= 2 && count( $groups ) <= 15 ) {
			return array( 'u' => $u, 'p' => array_map( function ( $g ) { return mb_substr( $g, 0, 40 ); }, $groups ), 'h' => 1,
				's' => array( array( 'g' => $periods[0], 'v' => array_map( function ( $g ) use ( $cells, $periods ) { return $cells[ $g ][ $periods[0] ] ?? 0; }, $groups ) ) ) );
		}
		if ( count( $periods ) < 2 || count( $periods ) > 60 || count( $groups ) > 6 ) {
			return null;
		}
		$series = array();
		foreach ( $groups as $g ) {
			$series[] = array( 'g' => mb_substr( $g, 0, 40 ), 'v' => array_map( function ( $p ) use ( $cells, $g ) { return $cells[ $g ][ $p ] ?? 0; }, $periods ) );
		}
		return array( 'u' => $u, 'p' => $periods, 's' => $series );
	}

	/** Any chart button markup a model wrote is removed before the server's own buttons go on. */
	public static function strip_chip_markup( string $text ): string {
		$text = (string) preg_replace( '/<div[^>]*(?:zana|zdz)-charts-chips[^>]*>.*?<\/div>/si', '', $text );
		return (string) preg_replace( '/<button[^>]*(?:zana-charts-open|data-zch|data-mini)[^>]*>.*?<\/button>/si', '', $text );
	}

	/**
	 * A chart request left in an answer: each is run (within the turn's four) and replaced by a small table; the buttons
	 * come with the others. Refused when Charts is not allowed this turn.
	 */
	public static function handle_markers( string $response, int $uid, array $vocab, bool $allowed ): string {
		if ( false === stripos( $response, '[' . self::MARKER . ']' ) ) {
			return $response;
		}
		$re  = '/\[' . self::MARKER . '\](.*?)\[\/' . self::MARKER . '\]\s*/si';
		$out = preg_replace_callback( $re, function ( $m ) use ( $uid, $vocab, $allowed ) {
			if ( ! $allowed ) {
				return "\u{26A0}\u{FE0F} " . __( 'Charts is not available here.', 'zorderz' ) . "\n\n";
			}
			$payload = json_decode( trim( $m[1] ), true );
			if ( ! is_array( $payload ) || isset( $payload[0] ) || ! array_intersect_key( $payload, array_flip( array_merge( self::KEYS, array( 'territories', 'prospect' ) ) ) ) ) {
				return "\u{26A0}\u{FE0F} " . __( 'Charts could not read that chart request.', 'zorderz' ) . "\n\n";
			}
			$runs = self::run_specs( array( $payload ), $uid, $vocab, 'marker' );
			$r    = $runs ? end( $runs ) : null;
			if ( ! $r || empty( $r['ok'] ) ) {
				return "\u{26A0}\u{FE0F} " . __( 'Charts did not run that chart:', 'zorderz' ) . ' ' . self::md( (string) ( $r['refused'] ?? $r['said'] ?? 'refused' ) ) . "\n\n";
			}
			return self::render_table( $r, count( self::$results ) ) . "\n\n";
		}, $response );
		return (string) preg_replace( '/\[\/?' . self::MARKER . '\].*$/si', '', is_string( $out ) ? $out : $response );
	}

	private static function md( string $s ): string {
		return (string) preg_replace( '/([\\\\`*_\[\]<>#|~])/', '\\\\$1', self::one_line( $s, 300 ) );
	}

	/** A compact markdown table of one result. */
	public static function render_table( array $r, int $n ): string {
		$res  = (array) $r['result'];
		$unit = (string) ( $res['unit'] ?? '' );
		$cols = array_values( array_map( 'strval', (array) ( $res['columns'] ?? array() ) ) );
		$vi   = array_search( 'value', $cols, true );
		/* translators: 1: chart number, 2: chart title */
		$out = '**' . self::md( sprintf( __( 'Chart %1$d: %2$s', 'zorderz' ), $n, (string) ( $res['title'] ?? '' ) ) ) . "**\n\n";
		if ( 'territories' === ( $res['kind'] ?? '' ) ) {
			return $out . self::md( (string) ( $res['message'] ?? '' ) );
		}
		if ( ! empty( $res['empty'] ) ) {
			return $out . '_' . __( 'Nothing matched these filters.', 'zorderz' ) . '_';
		}
		$multi = count( (array) ( $res['groups'] ?? array() ) ) > 1;
		$out  .= $multi ? '| ' . __( 'Period', 'zorderz' ) . ' | ' . __( 'Group', 'zorderz' ) . ' | ' . __( 'Value', 'zorderz' ) . " |\n|---|---|---|\n" : '| ' . __( 'Period', 'zorderz' ) . ' | ' . __( 'Value', 'zorderz' ) . " |\n|---|---|\n";
		$rows  = (array) ( $res['rows'] ?? array() );
		foreach ( array_slice( $rows, 0, 40 ) as $row ) {
			$row  = array_values( (array) $row );
			$v    = false !== $vi && isset( $row[ $vi ] ) ? self::num( $row[ $vi ], $unit ) : '';
			$out .= $multi ? '| ' . self::md( (string) ( $row[0] ?? '' ) ) . ' | ' . self::md( (string) ( $row[1] ?? '' ) ) . ' | ' . $v . " |\n" : '| ' . self::md( (string) ( $row[0] ?? '' ) ) . ' | ' . $v . " |\n";
		}
		if ( count( $rows ) > 40 ) {
			$out .= '| … | ' . ( $multi ? '… | ' : '' ) . ( count( $rows ) - 40 ) . ' ' . __( 'more rows', 'zorderz' ) . " |\n";
		}
		return rtrim( $out );
	}

	/* ------------------------------------------------------------------ the admin-only zones */

	/**
	 * The names and short names of the zones only the admin tier may see, read from Territories (territories.zones,
	 * through ZANA_App_Reads: the signed-in person only; Territories sends them to the admin tier alone, so this is empty
	 * for everyone else) and from this turn's territory answers. Never shown, never sent to a model.
	 */
	public static function admin_zone_names(): array {
		if ( is_callable( self::$admin_zones ) ) {
			return array_values( array_map( 'strval', (array) call_user_func( self::$admin_zones ) ) );
		}
		if ( null !== self::$admin_zones_memo ) {
			return self::$admin_zones_memo;
		}
		$out = array();
		$uid = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $uid > 0 && class_exists( 'ZANA_App_Reads' ) && '' === ZANA_App_Reads::why_not( 'territories.zones', $uid ) ) {
			$a = ZANA_App_Reads::ask( 'territories.zones', array(), $uid );
			if ( ! empty( $a['success'] ) && empty( $a['denied'] ) && 'admin' === ( $a['shaped_for'] ?? '' ) ) {
				foreach ( (array) ( $a['zones'] ?? array() ) as $z ) {
					if ( is_array( $z ) && 'admin' === ( $z['visible'] ?? '' ) ) {
						foreach ( array( 'label', 'short' ) as $k ) {
							$w = trim( (string) ( $z[ $k ] ?? '' ) );
							if ( '' !== $w ) {
								$out[] = $w;
							}
						}
					}
				}
			}
		}
		foreach ( self::$results as $r ) {
			if ( ! empty( $r['ok'] ) && 'territories' === ( $r['result']['kind'] ?? '' ) ) {
				foreach ( array_merge( (array) ( $r['result']['people'] ?? array() ), (array) ( $r['result']['everywhere'] ?? array() ) ) as $z ) {
					if ( is_array( $z ) && ! empty( $z['admin_only'] ) ) {
						foreach ( array( 'name', 'initials' ) as $k ) {
							$w = trim( (string) ( $z[ $k ] ?? '' ) );
							if ( strlen( $w ) >= 2 ) {
								$out[] = $w;
							}
						}
					}
				}
			}
		}
		return self::$admin_zones_memo = array_values( array_unique( $out ) );
	}

	/**
	 * A draft for someone else (an email, a direct message or channel post) that names an admin-only zone is taken out,
	 * and the person told why. Nothing changes when there is no draft or no admin-only zone.
	 */
	public static function guard_admin_only_zones( string $response ): string {
		$draft = '(ZDZ_EMAIL_DRAFT|ZANA_DM_DRAFT|ZANA_POST_DRAFT)';
		if ( ! preg_match( '/\[' . $draft . '\b/i', $response ) ) {
			return $response;
		}
		$words = array_values( array_filter( self::admin_zone_names(), function ( $w ) { return strlen( $w ) >= 2; } ) );
		if ( ! $words ) {
			return $response;
		}
		$name_re = '/(?<![A-Za-z0-9])(?:' . implode( '|', array_map( function ( $w ) { return preg_quote( $w, '/' ); }, $words ) ) . ')(?![A-Za-z0-9])/i';
		$out     = preg_replace_callback( '/\[' . $draft . '(?::[^\]\n]*)?\][\s\S]*?\[\/\1\]/i', function ( $m ) use ( $name_re ) {
			return preg_match( $name_re, $m[0] )
				? "\u{26A0}\u{FE0F} " . __( 'I left that draft out: it named an area only the admin tier may see. Ask again without it, or write that part yourself.', 'zorderz' )
				: $m[0];
		}, $response );
		return is_string( $out ) ? $out : $response;
	}
}
