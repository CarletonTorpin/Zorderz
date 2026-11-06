<?php
/**
 * ZANA_Plan_Routes — "plan 3 door-hanger routes for Saturday near 99999" opens the Route Planner.
 *
 * The chat plans, ranks, draws and saves nothing here. The Route Planner does all of that as the signed-in person, and
 * saving is a press there (a chat never writes a route). This is a rule route like ZANA_Hours_Route: a narrow
 * detector and an answer written by code -- one line and an "Open in Route Planner" button that carries only settings
 * (area, day, count) in the Route Planner's own launch contract: Bridge.loadApp( 'route-planner', { mode: 'plan',
 * area, date, count, go: true } ). The button is the server's: any button markup in a model's text is removed
 * (strip_chip_markup) before the server's own goes on.
 *
 * Who: never the shared device; someone without the Route Planner is told so in one line, with nothing to press. The
 * Route Planner re-checks the person, the day and the area itself.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Plan_Routes {

	/** The Route Planner's app id (ZRP_APP_ID). */
	const APP = 'route-planner';

	const DEFAULT_COUNT = 3;
	const MAX_COUNT     = 5;

	/** The Route Planner refuses a day outside today .. +60; the button never carries one. */
	const MAX_DAYS_AHEAD = 60;

	/** Injected in tests: may this person use the Route Planner (bool), and "today" (Y-m-d). */
	public static $can   = null;
	public static $today = null;

	/** The words that may stand between "plan" and "routes" ("plan me 3 door-hanger routes"). */
	const BETWEEN = '/^(?:me|us|out|up|a|an|some|few|couple|of|the|my|our|new|more|good|door|doors|hanger|hangers|doorhanger|doorhangers|doorhanging|hanging|walking|walk|canvassing|canvass|flyer|flyers|\d{1,2}|one|two|three|four|five|six|seven|eight|nine|ten)$/';

	const NUMBERS = array( 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10, 'couple' => 2, 'few' => 3 );

	const WEEKDAYS = array( 'monday' => 1, 'mon' => 1, 'tuesday' => 2, 'tue' => 2, 'tues' => 2, 'wednesday' => 3, 'wed' => 3, 'thursday' => 4, 'thu' => 4, 'thur' => 4, 'thurs' => 4, 'friday' => 5, 'fri' => 5, 'saturday' => 6, 'sat' => 6, 'sunday' => 7, 'sun' => 7 );

	const MONTHS = array( 'jan' => 1, 'january' => 1, 'feb' => 2, 'february' => 2, 'mar' => 3, 'march' => 3, 'apr' => 4, 'april' => 4, 'may' => 5, 'jun' => 6, 'june' => 6, 'jul' => 7, 'july' => 7, 'aug' => 8, 'august' => 8, 'sep' => 9, 'sept' => 9, 'september' => 9, 'oct' => 10, 'october' => 10, 'nov' => 11, 'november' => 11, 'dec' => 12, 'december' => 12 );

	/** Street suffixes that make "1234 Example Dr" an address. */
	const STREET = '(?:st|street|ave|avenue|rd|road|dr|drive|ln|lane|ct|court|way|blvd|boulevard|pl|place|cir|circle|ter|terrace|trl|trail|pkwy|parkway|hwy|highway|loop|row|sq|square)';

	/**
	 * Words that may open the request before "plan" ("can you plan ...", "please plan ..."). The request must START with
	 * one of these and "plan": "email Alex that we plan 3 routes", "remind me to plan routes", "don't plan routes", "did
	 * you plan the routes" are not requests to plan.
	 */
	const LEAD_IN = '/^(?:(?:hey|hi|ok|okay|so|chat|please|pls)\s*,?\s+)*(?:(?:can|could|would|will)\s+you\s+(?:please\s+)?|(?:can|could)\s+we\s+|let\'?s\s+|i\s+(?:want|need|would\s+like|\'d\s+like)\s+(?:you\s+)?to\s+|help\s+me\s+|go\s+ahead\s+and\s+)?(?:please\s+)?plan\b/';

	/** Words that end a place name ("near example heights for saturday", "in midtown in the morning"). */
	const PLACE_STOP = '/^(?:for|on|this|next|today|tonight|tomorrow|at|by|around|near|in|from|starting|with|and|please|thanks|early|late|morning|afternoon|evening|noon|weekend|monday|mon|tuesday|tue|tues|wednesday|wed|thursday|thu|thur|thurs|friday|fri|saturday|sat|sunday|sun|jan|january|feb|february|mar|march|apr|april|may|jun|june|jul|july|aug|august|sep|sept|september|oct|october|nov|november|dec|december|the)$/';

	/** Normalise: lower case, curly quotes, door-hanger -> door hanger; keeps digits, / and - inside dates. */
	private static function norm( $message ) {
		$m = strtolower( str_replace( array( "\u{2019}", "\u{2018}", '`' ), "'", (string) $message ) );
		$m = (string) preg_replace( '/(?<=[a-z])-(?=[a-z])/', ' ', $m );       // door-hanger, drive-by
		$m = (string) preg_replace( '/[?!;:"()\[\]{}]+/', ' ', $m );
		$m = (string) preg_replace( '/,(?!\d)/', ' , ', $m );                   // a comma ends an area, unless it is "1,000"
		$m = (string) preg_replace( '/\.(?=\s|$)/', ' ', $m );                  // a full stop, not "st." inside an address
		return trim( (string) preg_replace( '/\s+/', ' ', $m ) );
	}

	/** The day phrases read_day() knows (without the "for" / "on" before them). */
	private static function day_re() {
		$mon = implode( '|', array_keys( self::MONTHS ) );
		$wd  = implode( '|', array_keys( self::WEEKDAYS ) );
		return '(?:today|tonight|tomorrow|(?:this\s+|next\s+)?(?:' . $wd . ')|\d{4}-\d{2}-\d{2}|\d{1,2}\/\d{1,2}(?:\/\d{2,4})?|(?:' . $mon . ')\.?\s+\d{1,2}(?:st|nd|rd|th)?|(?:the\s+)?\d{1,2}(?:st|nd|rd|th)(?:\s+of\s+(?:' . $mon . '))?|\d{1,2}\s+(?:' . $mon . '))';
	}

	/**
	 * Is this a request to plan several door-hanger routes? Returns array( count, date|null, area, notes[] ) or null.
	 * Pure (no WordPress beyond the clock): the words only. Read by rule, like the hours route: the request starts with
	 * "plan" (after a polite lead-in), and every word after "routes" must be a day, a time of day, a place, a ZIP, an
	 * address or a courtesy. Anything else (a person, named streets, "next week", "last saturday", a second request)
	 * means the words are not this request's, and the chat answers them as usual.
	 */
	public static function detect( $message ) {
		$raw = (string) $message;
		if ( '' === trim( $raw ) || strlen( $raw ) > 240 ) {
			return null;
		}
		$m = self::norm( $raw );
		if ( ! preg_match( self::LEAD_IN, $m, $lead ) ) {
			return null;
		}
		$m = trim( substr( $m, strlen( $lead[0] ) - 4 ) ); // from "plan"
		if ( ! preg_match( '/^plan\b((?:\s+\S+){0,6}?)\s+(routes?)\b/', $m, $mm ) ) {
			return null;
		}
		$between = trim( $mm[1] );
		$plural  = 'routes' === $mm[2];
		$words   = '' === $between ? array() : explode( ' ', $between );
		foreach ( $words as $w ) {
			if ( ! preg_match( self::BETWEEN, $w ) ) {
				return null; // "plan for routes", "plan the example routes": not this
			}
		}
		$count = null;
		foreach ( $words as $w ) {
			if ( ctype_digit( $w ) ) {
				$count = (int) $w;
			} elseif ( isset( self::NUMBERS[ $w ] ) && null === $count ) {
				$count = self::NUMBERS[ $w ];
			}
		}
		if ( ! $plural && 1 !== $count ) {
			return null; // "plan a route ..." is the single-route [ZDH_PLAN] path; only an explicit "1 route" is this one
		}
		$rest = self::read_rest( trim( substr( $m, strlen( $mm[0] ) ) ) );
		if ( null === $rest ) {
			return null;
		}
		$notes = array();
		if ( null === $count || $count < 1 ) {
			$count = self::DEFAULT_COUNT;
		} elseif ( $count > self::MAX_COUNT ) {
			$notes[] = 'max';
			$count   = self::MAX_COUNT;
		}
		$day = '' !== $rest['day'] ? self::read_day( $rest['day'], $notes ) : null;
		if ( '' !== $rest['day'] && null === $day && ! in_array( 'day_out_of_range', $notes, true ) ) {
			return null; // a day said that cannot be read ("the 31st of feb"): not this route, never a silent drop
		}
		return array( 'count' => $count, 'date' => $day, 'area' => $rest['area'], 'notes' => $notes );
	}

	/**
	 * The words after "routes", read by rule: array( day => the day phrase or '', area => the place or '' ), or null when a
	 * word is none of these. One day and one place at most; a time of day is read and left to the Route Planner's own window.
	 */
	public static function read_rest( $r ) {
		$day = ''; $area = ''; $guard = 0;
		$dre = self::day_re();
		while ( '' !== ( $r = trim( (string) $r ) ) && $guard++ < 20 ) {
			if ( preg_match( '/^(?:,|and|please|pls|thanks|thank you|for (?:me|us)|asap|today\'s|routes?)(?=\s|$)/', $r, $x ) ) {
				$r = substr( $r, strlen( $x[0] ) );
				continue;
			}
			// a day: "for saturday", "on oct 31", "this friday", "11/14", "the 7th"
			if ( preg_match( '/^(?:(?:for|on)\s+)?(' . $dre . ')(?=\s|,|$)/', $r, $x ) ) {
				if ( '' !== $day && $day !== $x[1] ) {
					return null; // two days: not this route
				}
				$day = $x[1];
				$r   = substr( $r, strlen( $x[0] ) );
				continue;
			}
			// a time of day: read, and left to the Route Planner (its walking window)
			if ( preg_match( '/^(?:in\s+the\s+(?:morning|afternoon|evening)|this\s+(?:morning|afternoon|evening)|(?:early|late)(?:\s+(?:morning|afternoon))?|(?:at|around|by|after|before)\s+(?:noon|\d{1,2}(?::\d{2})?\s*(?:am|pm)?)|morning|afternoon|evening)(?=\s|,|$)/', $r, $x ) ) {
				$r = substr( $r, strlen( $x[0] ) );
				continue;
			}
			// "near me", "around here", "in my territory": no place named (the planner asks, or uses where you are)
			if ( preg_match( '/^(?:near|around|by)\s+(?:me|here|us)(?=\s|,|$)|^in\s+(?:my|our)\s+(?:territory|area|zone|zip(?:\s+code)?)(?=\s|,|$)/', $r, $x ) ) {
				$r = substr( $r, strlen( $x[0] ) );
				continue;
			}
			$pre = '(?:(?:near|in|around|by|over\s+in|out\s+in|in\s+and\s+around)\s+)';
			// an address with a house number: "around 1234 Example Dr, Springfield 99999"
			if ( preg_match( '/^' . $pre . '(\d{1,6}\s+(?:[nsew]\s+)?[a-z][a-z\'.]*(?:\s+[a-z][a-z\'.]*){0,3}?\s+' . self::STREET . ')\.?(?=\s|,|$)/', $r, $x ) ) {
				if ( '' !== $area ) {
					return null;
				}
				$addr = $x[1];
				$r    = substr( $r, strlen( $x[0] ) );
				// ", Springfield" after it: the city, up to three words, ending at a day, a time or a stop word
				if ( preg_match( '/^\s*,\s*([a-z]+(?:\s+[a-z]+){0,2})/', $r, $c ) ) {
					$city = array();
					foreach ( explode( ' ', $c[1] ) as $w ) {
						if ( preg_match( self::PLACE_STOP, $w ) ) {
							break;
						}
						$city[] = $w;
					}
					if ( $city ) {
						$addr .= ', ' . implode( ' ', $city );
						$r     = substr( $r, (int) strpos( $r, $city[0] ) + strlen( implode( ' ', $city ) ) );
					}
				}
				if ( preg_match( '/^\s*,?\s*(\d{5})(?=\s|,|$)/', $r, $z ) ) {
					$addr .= ' ' . $z[1];
					$r     = substr( $r, strlen( $z[0] ) );
				}
				$area = self::clean_area( $addr );
				continue;
			}
			// a ZIP: "near 99999", "in 99901", "99999"
			if ( preg_match( '/^(?:' . $pre . '|for\s+)?(\d{5})(?=\s|,|$)/', $r, $x ) ) {
				if ( '' !== $area && $area !== $x[1] ) {
					return null;
				}
				$area = $x[1];
				$r    = substr( $r, strlen( $x[0] ) );
				continue;
			}
			// a place: up to four words after near / in / around ("near example heights", "in north example valley")
			if ( preg_match( '/^' . $pre . '(?:the\s+)?([a-z][a-z\'.]*(?:\s+[a-z][a-z\'.]*){0,3})/', $r, $x ) ) {
				$place = array();
				foreach ( explode( ' ', $x[1] ) as $w ) {
					if ( preg_match( self::PLACE_STOP, $w ) ) {
						break;
					}
					$place[] = $w;
				}
				if ( ! $place || '' !== $area ) {
					return null; // "in an hour", "in one go", a second place: not this
				}
				if ( preg_match( '/^(?:a|an|one|two|three|my|our|this|that|go|order|total|all|time|hours?|minutes?|town|it|them|there|here|me|us)$/', $place[0] ) ) {
					return null;
				}
				$area = self::clean_area( ucwords( implode( ' ', $place ) ) );
				$used = (int) strpos( $x[0], $place[0] ) + strlen( implode( ' ', $place ) );
				$r    = substr( $r, $used );
				continue;
			}
			return null; // a word this route does not read ("for Alex", "on Example Dr", "next week", "100 flyers each")
		}
		return '' === trim( (string) $r ) ? array( 'day' => $day, 'area' => $area ) : null;
	}

	/** Today in the site's zone (Y-m-d). */
	public static function today() {
		if ( is_string( self::$today ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', self::$today ) ) {
			return self::$today;
		}
		$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		return ( new DateTimeImmutable( 'now', $tz ) )->format( 'Y-m-d' );
	}

	/** The day said, as Y-m-d, or null. A day the planner would refuse (past, or over 60 days out) is dropped with a note. */
	public static function read_day( $m, array &$notes ) {
		$today = new DateTimeImmutable( self::today() . ' 12:00:00', new DateTimeZone( 'UTC' ) );
		$d     = null;
		if ( preg_match( '/\b(\d{4})-(\d{2})-(\d{2})\b/', $m, $x ) && checkdate( (int) $x[2], (int) $x[3], (int) $x[1] ) ) {
			$d = $today->setDate( (int) $x[1], (int) $x[2], (int) $x[3] );
		} elseif ( preg_match( '/\b(\d{1,2})\/(\d{1,2})(?:\/(\d{2,4}))?\b/', $m, $x ) ) {
			$y = isset( $x[3] ) && '' !== $x[3] ? ( strlen( $x[3] ) === 2 ? 2000 + (int) $x[3] : (int) $x[3] ) : null;
			$d = self::month_day( $today, (int) $x[1], (int) $x[2], $y );
		} elseif ( preg_match( '/\b(' . implode( '|', array_keys( self::MONTHS ) ) . ')\.?\s+(\d{1,2})(?:st|nd|rd|th)?\b/', $m, $x ) ) {
			$d = self::month_day( $today, self::MONTHS[ $x[1] ], (int) $x[2], null );
		} elseif ( preg_match( '/\b(\d{1,2})(?:st|nd|rd|th)?\s+(?:of\s+)?(' . implode( '|', array_keys( self::MONTHS ) ) . ')\b/', $m, $x ) ) {
			$d = self::month_day( $today, self::MONTHS[ $x[2] ], (int) $x[1], null );
		} elseif ( preg_match( '/^(?:the\s+)?(\d{1,2})(?:st|nd|rd|th)$/', trim( $m ), $x ) ) {
			// "the 7th": that day of this month, or of next month when it has passed
			$dd = (int) $x[1];
			$y  = (int) $today->format( 'Y' );
			$mo = (int) $today->format( 'n' );
			if ( $dd < (int) $today->format( 'j' ) ) {
				$mo++;
				if ( $mo > 12 ) {
					$mo = 1;
					$y++;
				}
			}
			$d = checkdate( $mo, $dd, $y ) ? $today->setDate( $y, $mo, $dd ) : null;
		} elseif ( preg_match( '/\btoday\b|\btonight\b/', $m ) ) {
			$d = $today;
		} elseif ( preg_match( '/\btomorrow\b/', $m ) ) {
			$d = $today->modify( '+1 day' );
		} elseif ( preg_match( '/\b(this|next)?\s*(' . implode( '|', array_keys( self::WEEKDAYS ) ) . ')\b/', $m, $x ) ) {
			// the coming one, today included (the Route Planner's own rule for `when`); "next" on the same weekday is a week on
			$want = self::WEEKDAYS[ $x[2] ];
			$add  = ( $want - (int) $today->format( 'N' ) + 7 ) % 7;
			if ( 0 === $add && 'next' === $x[1] ) {
				$add = 7;
			}
			$d = $today->modify( '+' . $add . ' days' );
		}
		if ( null === $d ) {
			return null;
		}
		$diff = (int) $today->diff( $d )->format( '%r%a' );
		if ( $diff < 0 || $diff > self::MAX_DAYS_AHEAD ) {
			$notes[] = 'day_out_of_range';
			return null;
		}
		return $d->format( 'Y-m-d' );
	}

	/** A month and day with no year: this year, or next year when it has passed. */
	private static function month_day( DateTimeImmutable $today, $mo, $da, $y ) {
		$said_year = null !== $y;
		$y         = $y ?: (int) $today->format( 'Y' );
		if ( ! checkdate( (int) $mo, (int) $da, (int) $y ) ) {
			return null;
		}
		$d = $today->setDate( (int) $y, (int) $mo, (int) $da );
		if ( ! $said_year && $d < $today ) {
			$d = checkdate( (int) $mo, (int) $da, (int) $y + 1 ) ? $today->setDate( (int) $y + 1, (int) $mo, (int) $da ) : $d;
		}
		return $d;
	}

	/** At most 80 characters of letters, digits, spaces and , . ' # - (the Route Planner's own limit). */
	public static function clean_area( $s ) {
		$s = trim( (string) preg_replace( '/\s+/', ' ', (string) preg_replace( '/[^\p{L}\p{N} ,.\'#-]/u', '', (string) $s ) ), " ,.-" );
		if ( preg_match( '/^\d+ /', $s ) ) {
			$s = ucwords( $s );
		}
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, 80 ) : substr( $s, 0, 80 );
	}

	/** Does this person hold the Route Planner (its own check, else the theme's app check)? Never the shared device. */
	public static function can( int $uid ): bool {
		if ( null !== self::$can ) {
			return (bool) self::$can;
		}
		if ( $uid <= 0 ) {
			return false;
		}
		if ( function_exists( 'zrp_user_can_use' ) ) {
			return (bool) zrp_user_can_use( $uid );
		}
		return class_exists( 'ZDZ_Plugin_API' ) && method_exists( 'ZDZ_Plugin_API', 'user_can_access_app' ) && (bool) ZDZ_Plugin_API::user_can_access_app( $uid, self::APP );
	}

	/** Is the Route Planner on this site at all? */
	public static function installed(): bool {
		return null !== self::$can || defined( 'ZRP_APP_ID' ) || function_exists( 'zrp_user_can_use' );
	}

	/** The route: the answer for this turn, or null when it is not a request to plan several routes. */
	public static function route( int $uid, string $message, bool $shared_device = false ): ?string {
		if ( $shared_device || $uid <= 0 || '' === trim( $message ) || ! self::installed() ) {
			return null;
		}
		$p = self::detect( $message );
		return null === $p ? null : self::answer( $p, $uid );
	}

	/** The launch options, exactly the Route Planner's own launch contract (zdz_app_launch: mode, area, date, count, go). */
	public static function launch_options( array $p ) {
		$o = array( 'mode' => 'plan' );
		if ( '' !== (string) ( $p['area'] ?? '' ) ) {
			$o['area'] = self::clean_area( (string) $p['area'] );
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $p['date'] ?? '' ) ) ) {
			$o['date'] = (string) $p['date'];
		}
		$o['count'] = max( 1, min( self::MAX_COUNT, (int) ( $p['count'] ?? self::DEFAULT_COUNT ) ) );
		if ( isset( $o['area'] ) ) {
			$o['go'] = true; // run the plan at once -- only with an area said here (without one the planner would run on the
			                 // area it remembers from last time, while this line says "Say where there")
		}
		return $o;
	}

	/** "Sat, Oct 31" */
	public static function day_words( $ymd ) {
		$d = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $ymd );
		return $d ? $d->format( 'D, M j' ) : '';
	}

	/** The answer, whole: one line, and the button (or one line and nothing to press). */
	public static function answer( array $p, int $uid ): string {
		if ( ! self::can( $uid ) ) {
			return __( 'The Route Planner isn\'t on your account, so I can\'t plan routes for you. An admin can add it in the team settings.', 'zorderz' );
		}
		$o    = self::launch_options( $p );
		$n    = (int) $o['count'];
		/* translators: %d: how many routes */
		$line = sprintf( _n( 'Open the Route Planner to plan %d route', 'Open the Route Planner to plan %d routes', $n, 'zorderz' ), $n )
			. ( isset( $o['area'] ) ? ' ' . sprintf( __( 'near %s', 'zorderz' ), self::md_escape( $o['area'] ) ) : '' )
			. ( isset( $o['date'] ) ? ' ' . sprintf( __( 'for %s', 'zorderz' ), self::day_words( $o['date'] ) ) : '' ) . '.';
		$after = array();
		if ( in_array( 'max', (array) ( $p['notes'] ?? array() ), true ) ) {
			$after[] = sprintf( __( 'It plans at most %d at a time.', 'zorderz' ), self::MAX_COUNT );
		}
		if ( in_array( 'day_out_of_range', (array) ( $p['notes'] ?? array() ), true ) ) {
			$after[] = isset( $o['area'] ) ? sprintf( __( 'Pick a day within the next %d days there.', 'zorderz' ), self::MAX_DAYS_AHEAD ) : sprintf( __( 'Pick a day within the next %d days there, and say where.', 'zorderz' ), self::MAX_DAYS_AHEAD );
		} elseif ( ! isset( $o['date'] ) && ! isset( $o['area'] ) ) {
			$after[] = __( 'Say where and which day there.', 'zorderz' );
		} elseif ( ! isset( $o['area'] ) ) {
			$after[] = __( 'Say where there.', 'zorderz' );
		} elseif ( ! isset( $o['date'] ) ) {
			$after[] = __( 'Pick the day there.', 'zorderz' );
		}
		if ( $after ) {
			$line .= ' ' . implode( ' ', $after );
		}
		$b64 = rtrim( strtr( base64_encode( (string) json_encode( $o, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ), '+/', '-_' ), '=' );
		$btn = '<div class="zana-app-chips"><button type="button" class="zana-app-open zana-rp-open" data-rp="' . htmlspecialchars( $b64, ENT_QUOTES, 'UTF-8' ) . '">'
			. "\u{1F5FA}\u{FE0F} " . esc_html__( 'Open in Route Planner', 'zorderz' ) . '</button></div>';
		return $line . "\n\n" . $btn;
	}

	/** The server's button, exactly as answer() writes it. */
	const CHIP_RE = '/<div class="zana-app-chips"><button type="button" class="zana-app-open zana-rp-open" data-rp="[A-Za-z0-9_-]{2,600}">[^<]{1,60}<\/button><\/div>/';

	/** Any Route Planner button markup in a text (a model's, or one fed to it) -- removed before the server's goes on. */
	public static function strip_chip_markup( $text ) {
		$text = (string) preg_replace( '/<div[^>]*zana-app-chips[^>]*>.*?<\/div>/si', '', (string) $text );
		return (string) preg_replace( '/<button[^>]*(?:zana-rp-open|zana-app-open|data-rp)[^>]*>.*?<\/button>/si', '', $text );
	}

	/** Markdown-safe area words (the area is the person's own words, cleaned; never markup). */
	private static function md_escape( $s ) {
		return (string) preg_replace( '/([\\\\`*_\[\]<>#|~])/', '\\\\$1', (string) $s );
	}
}
