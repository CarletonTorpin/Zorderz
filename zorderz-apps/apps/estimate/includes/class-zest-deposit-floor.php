<?php
/**
 * ZEST_Deposit_Floor - the plain deposit reader: an amount next to an advance word.
 *
 * ZEST_Deposit_Rule reads far more shapes, and some of its readings are narrower than this one on purpose (a lawful
 * wording that merely looks like an ask). It never finds less than this reader except where its floor_lawful() names
 * the lawful shape: so a narrowing there can never hide an ask this reader sees. Read-only: find() alone, with the same
 * finding shape as ZEST_Deposit_Rule::find(), against the same limit (ZEST_Deposit_Limit) and word lists.
 *
 *   SURE      an amount, then the advance ("50% deposit", "half at order", "$2,500 down"); the advance, then the
 *             amount ("Deposit required: 50%", "Deposit USD 2,500"); a split with payment words ("Terms: 50/50")
 *   POSSIBLE  a clause with an advance word, a payment word and an amount over the limit that no shape binds
 *   NEVER     a promotion, a denied advance, a product attribute's or a fee's percent, a size or an address, a record
 *             of a payment
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ZEST_Deposit_Floor {


	const TALK = 'a about advance after agreement all also amount an and any approval approve approved are as ask asked asks at balance be before begin book booked booking by can card cash cent check collect collected complete completed completion confirm contract credit deposit deposits do down downpayment due each estimate final first for from front full get getting going half hold in initial install installation installed installing invoice is it its job lock material materials may minimum must need needed needs non nonrefundable now of on once or order ordered ordering orders our paid pay payable paying payment payments pct per percent place placed placement placing please plus pre prepayment price prior project refundable remainder remaining request requested require required requires reserve rest retainer schedule scheduled scheduling second secure shall sign signature signed signing so spot standard start started starting such terms thank thanks that the them then this time to today total up upfront upon us via we when will with work you your zelle';

	const CORE = '/\b(?:due|paid|pay|payable|paying|payments?|deposits?|balance|remaining|remainder|rest|terms|down|upfront|advance|signing|completion|retainer|prepayment|downpayment)\b/';

	private static $re   = null;
	private static $talk = null;
	private static $sig  = '';

	/** The business's product words, as payment talk a clause may hold when the app replaces it whole. */
	private static function talk_extra(): array {
		$out = array();
		foreach ( ZEST_Deposit_Limit::words( 'product' ) as $w ) {
			foreach ( preg_split( '/[\s-]+/', $w ) as $p ) {
				$out[] = $p;
				$out[] = $p . 's';
			}
		}
		return $out;
	}

	/** The patterns are built once per word list (a changed list builds them again). */
	private static function words_sig(): string {
		return md5( implode( ',', ZEST_Deposit_Limit::words( 'product' ) ) . '|' . implode( ',', ZEST_Deposit_Limit::words( 'rate' ) ) );
	}

	/** The most a deposit may be on this pre-tax price, in cents (see ZEST_Deposit_Limit). */
	public static function cap_cents( int $pretax_cents ): int {
		return ZEST_Deposit_Limit::cap_cents( $pretax_cents );
	}

	/** Cents as money words: "$1,000" or "$12.50". */
	public static function money( int $cents ): string {
		return ZEST_Deposit_Limit::money( $cents );
	}

	/** The deposit sentence at the limit for this price, in the business's own words. */
	public static function lawful_sentence( int $pretax_cents ): string {
		return ZEST_Deposit_Limit::lawful_sentence( $pretax_cents );
	}

	/** The short form of the deposit sentence at the limit for this price. */
	public static function short_sentence( int $pretax_cents ): string {
		return ZEST_Deposit_Limit::short_sentence( $pretax_cents );
	}

	private static function over( float $pct, int $cents, int $pretax ): array {
		return ZEST_Deposit_Limit::over( $pct, $cents, $pretax );
	}

	private static function re(): array {
		$sig = self::words_sig();
		if ( null !== self::$re && $sig === self::$sig ) {
			return self::$re;
		}
		self::$sig  = $sig;
		self::$talk = null;
		$goods  = ZEST_Deposit_Limit::alt( array(), 'product' );   // what the business sells, by name
		$rate   = ZEST_Deposit_Limit::alt( array(), 'rate' );      // its products' attributes measured in percent
		$pct  = '\d{1,3}(?:\.\d+)?%';
		$usd  = '\$\s?(?:\d{1,3}(?:,\d{3})+|\d+)(?:\.\d{1,2})?(?![\d,])';
		$bare = '(?:\d{1,3}(?:,\d{3})+|\d{3,6})(?:\.\d{1,2})?(?![\d\/%]|[.,]\d|-\d)(?!\s*(?:series|mm\b|cm\b|in\b|inch|ft\b|"|x\b|model|sq\b|lbs?\b))';
		$dep  = '(?:deposit|down\s*-?\s*payment|downpayment|down\b|retainer)';
		$adj  = '(?:\s+(?:non-?\s*refundable|refundable|initial|minimum|mandatory|required|standard|customary|materials?|product|order))*';
		$after_dep = '(?:\s*$|\s*[.,;:!)]|\s+(?:required|due|is|to|at|upon|on|when|before|needed|please|payment|now|of|for)\b)';

		$when = 'to\s+(?:start|begin|order|book|reserve|secure|schedule|hold|get\s+started|confirm|lock\s+in|place\s+(?:the\s+|your\s+)?order)\b'
			. '|(?:at|on|upon|with|before|prior\s+to|by)\s+(?:the\s+|your\s+|time\s+of\s+(?:the\s+)?)?(?:signing|signature|sign(?:ing)?\s*-?\s*off|order(?:ing|s)?\b|order\s+placement|placing\s+(?:the\s+|your\s+)?order|acceptance|approval|booking|scheduling|contract(?:\s+signing)?|start\b|sale\b|purchase\b|agreement\b)'
			. '|when\s+(?:you\s+|we\s+)?(?:sign|signed|order|ordered|accept|accepted|approve|approved|book|booked|schedule|scheduled|place\s+(?:the\s+|your\s+)?order)\b'
			. '|when\s+(?:the\s+|your\s+)?order\s+is\s+placed|once\s+(?:you\s+)?(?:sign|signed|approve|approved|accept|accepted|order|ordered)\b'
			. '|(?:before|prior\s+to)\s+(?:we\s+)?(?:order(?:ing)?|start(?:ing)?|begin(?:ning)?|schedul(?:e|ing)|install(?:ation|ing)?|work\s+(?:starts|begins))\b';
		$adv  = '(?:deposit|down\s*-?\s*payment|downpayment|down\b|retainer|pre-?\s*payment|advance(?:\s+payment)?\b|up\s*-?\s*front|upfront|in\s+advance|now\b|today\b|immediately|at\s+once|' . $when . ')';
		$strong = '(?:deposits?|down\s*-?\s*payments?|downpayments?|retainers?|pre-?\s*pay(?:ment)?|advance\s+payment|advance\s*:|dep\s*[.:]|initial\s+(?:payment|installment)|first\s+(?:payment|installment)|1st\s+payment'
			. '|(?:at|upon|on|with)\s+(?:the\s+time\s+of\s+)?(?:signing|order(?:ing)?|acceptance|approval|booking|contract(?:\s+signing)?)'
			. '|payment\s+(?:due\s+)?(?:at|upon|on|with)\s+(?:signing|order(?:ing)?|acceptance|approval|booking)'
			. '|due\s+(?:at|upon|on|with|before)\s+(?:signing|order(?:ing)?|acceptance|approval|booking|contract(?:\s+signing)?|start)'
			. '|due\s+(?:now|today|immediately)'
			. '|(?:required|needed|requested|due|payable)\s+(?:to\s+(?:start|order|book|begin|get\s+started)|up\s*-?\s*front|in\s+advance)'
			. '|to\s+(?:start|get\s+started|begin|order|book)\s*[:=]'
			. '|up\s*-?\s*front(?:\s+(?:payment|deposit|cost|amount))?|upfront(?:\s+(?:payment|deposit|cost|amount))?|in\s+advance)';
		$fill = '(?:\s*(?:[(:=\/-]|\)|(?:0?[1-9]|1[0-2])[\/-](?:0?[1-9]|[12]\d|3[01])(?:[\/-]\d{2,4})?(?![\d\/%-])|(?:of|is|are|amount|required|requested|needed|due|payable|will\s+be|must\s+be|to\s+be|shall\s+be|approximately|approx|about|only|just|equal\s+to|equals|in\s+the\s+amount\s+of|the\s+sum\s+of|a|an|totaling|totalling|for|we\s+require|we\s+need|we\s+ask\s+for|please|pay|paid|collect|collected|non-?\s*refundable|refundable|at\s+signing|at\s+order|upon\s+signing|upon\s+order(?:ing)?|on\s+order|to\s+(?:start|order|book|begin|get\s+started|schedule)|(?:before|prior\s+to)\s+(?:we\s+)?(?:order(?:ing)?|start(?:ing)?))\b\.?)\s*){0,6}';
		self::$re = array(
			'when'    => $when,

			'p1'      => '/(?<![\w$.,\/])(' . $pct . '|' . $usd . '|\bhalf\b|' . $bare . '(?=' . $adj . '\s*' . $dep . $after_dep . '))'
				. '(?:\s*\(?\s*(?:of\s+)?(?:the\s+|your\s+)?(?:total|price|job|contract(?:\s+(?:price|amount|total))?|amount|estimate|invoice|project|order|cost)\s*\)?)?'
				. $adj . '(?:\s+(?:payment|installment))?(?:\s*(?:is|are|will\s+be|must\s+be|to\s+be|shall\s+be))?(?:\s*(?:required|needed|requested|due|payable|paid|collected|expected))?'
				. '\s*[-:,]?\s*' . $adv . '/',

			'p2'      => '/\b' . $strong . '(?![a-z])' . $fill . '\s*(' . $pct . '|' . $usd . '|\bhalf\b|' . $bare . ')/',

			'denied'  => '/\b(?:no|without|zero|waived?|not)\s+(?:an?\s+)?$/',

			'restate' => '/' . $pct . '\s*(?:=|is)\s*\$?\s?((?:\d{1,3}(?:,\d{3})+|\d+)(?:\.\d{1,2})?)/',

			'split'   => '/(?<![\d.\/$])(\d{1,3})\s*%?\s*\/\s*(\d{1,3})\s*%?(?:\s*\/\s*(\d{1,3})\s*%?)?(?:\s*\/\s*(\d{1,3})\s*%?)?(?![\d\/])/',
			'halves'  => '/\bhalf\s+(?:and|&|\/)\s+half\b/',
			'dash'    => '/(?<![\d.\/$-])(\d{1,3})\s*%?\s*-\s*(\d{1,3})\s*%?(?![\d\/-])/',
			'payword' => '/\b(?:pay|pays|paid|payment|payments|payable|deposits?|balance|terms|installments?|retainer|down\s*-?\s*payment|downpayment|billing|billed)\b|\bdue\b(?!\s+to\b)/',
			'advany'  => '/\b(?:deposits?|down\s*-?\s*payments?|downpayments?|retainers?|pre-?\s*pay\w*|up\s*-?\s*front|upfront|in\s+advance|advance\s+payment|initial\s+payment|first\s+payment|at\s+(?:the\s+time\s+of\s+)?signing|upon\s+signing|at\s+order|on\s+order|upon\s+order\w*|when\s+ordered|before\s+we\s+(?:order|start)|to\s+get\s+started|to\s+start)\b/',

			'disc'    => '/\b(?:save|saves|saving|savings|get|gets|take|takes|extra|additional|rebate|discount(?:ed|s)?|off|up\s+to|promo|promotion|sale|special|coupon|bonus|earn|reduce[sd]?|reduction|lower(?:s|ed)?|cuts?|block(?:s|ed|ing)?|raise[sd]?)\s*(?::|\bof\b|-)?\s*(?:an?\s+)?(?:extra\s+|additional\s+)?$/',

			'discby'  => '/\b(?:(?:reduce[sd]?|lower(?:s|ed)?|cuts?|drop(?:s|ped)?|raise[sd]?|increase[sd]?|discount(?:s|ed)?|mark(?:s|ed)?\s+down)(?:\s+[\w-]+){0,3}|save[sd]?(?:\s+on(?:\s+[\w-]+){0,3})?|off|up\s+to)\s+by\s*(?::|\bof\b|-)?\s*(?:an?\s+)?(?:extra\s+|additional\s+)?$/',
			'notpct'  => '/^\s*(?:off|discount|fee|surcharge|processing|card|credit|tax|interest|apr|finance|open\b|block\b|uv|visibility|privacy|' . $rate . '|more|less|of\s+(?:the\s+)?(?:light|heat|sun|uv|glare|windows?|gates?|doors?|time|' . $goods . '))/',
			'notusd'  => '/\b(?:total|balance|remaining|remainder|final|price|subtotal|amount\s+due|estimate|invoice|value|cost|fee|tax|discount|off|save|saves|savings|retail|msrp|list)\s*(?:of|is|:|=|-)?\s*$/',

			'record'  => '/\b(?:received|rec\'?d|credited|applied\s+to|on\s+file|thanks?(?:\s+you)?\s+for\s+(?:your\s+|the\s+)?(?:\$[\d,.]+\s+)?(?:deposit|payment)|(?:was|were|has\s+been|have\s+been|already)\s+paid|paid\s+in\s+full|check\s*(?:#|no\.?|number)\s*\d|cheque|ck\s*#\s*\d|refunded)\b'

				. '|(?<!\bbe\s)\b(?:paid|collected)\b(?!\s+(?:at|upon|on\s+(?:signing|order|acceptance|approval)|before|when|prior|in\s+advance|up\s*-?\s*front|to\s+(?:start|order|book|begin)|by\s+(?:the\s+)?(?:signing|order)))'
				. '|^\s*paid\s+(?:\$|\d)/',
		);
		return self::$re;
	}

	private static function norm( string $s ): string {
		$s = str_replace(
			array( "\xC2\xA0", "\xE2\x80\xAF", "\xE2\x80\x89", "\xEF\xBC\x85", "\xE2\x80\x93", "\xE2\x80\x94", "\xE2\x88\x92", "\xE2\x80\x98", "\xE2\x80\x99", "\xE2\x80\x9C", "\xE2\x80\x9D" ),
			array( ' ', ' ', ' ', '%', '-', '-', '-', "'", "'", '"', '"' ),
			$s
		);
		$s = strtolower( (string) preg_replace( '/\s+/', ' ', $s ) );

		$s = (string) preg_replace( '/\b((?:on|by|dated?|install(?:ed|ation|s|ing)?|ready|scheduled|until|till|before|after|from|starting|starts?|thru|through|week\s+of|deliver(?:y|ed)?|pick\s*-?\s*up|measure(?:d|ment)?|appointment|begins?)\s*:?\s*)(0?[1-9]|1[0-2])\/(0?[1-9]|[12]\d|3[01])(?![\d\/])'
			. '(?!\s*(?:%|of\b|down\b|up\s*-?\s*front|upfront|in\s+advance|(?:is\s+)?(?:due|payable|required|needed)\b(?!\s+to\b)|' . self::re()['when'] . '))/', '$1$2-$3', $s );
		$s = str_replace( array( "\xC2\xBD", "\xC2\xBC", "\xC2\xBE", "\xE2\x85\x93" ), array( ' 1/2 ', ' 1/4 ', ' 3/4 ', ' 1/3 ' ), $s );
		$s = (string) preg_replace( '/\b(?:usd|us\$)\s*\$?\s*(?=\d)/', '$', $s );
		$s = (string) preg_replace( '/\b(\d[\d,]*(?:\.\d{1,2})?)\s*(?:dollars|bucks)\b/', '\$$1', $s );
		$s = (string) preg_replace_callback( '/\$\s?(\d{1,3}(?:\.\d{1,2})?)\s?k\b/', static function ( $m ) { return '$' . (string) (int) round( (float) $m[1] * 1000 ); }, $s );
		$s = (string) preg_replace( '/\s*\b(?:per\s*cent|percent|pct)\b\.?/', '%', $s );
		$s = (string) preg_replace( '/\bfifty[- ]fifty\b/', '50/50', $s );
		foreach ( array( 'one hundred' => 100, 'seventy[- ]five' => 75, 'sixty[- ]six' => 66, 'forty[- ]five' => 45, 'thirty[- ]three' => 33,
			'twenty[- ]five' => 25, 'ninety' => 90, 'eighty' => 80, 'seventy' => 70, 'sixty' => 60, 'fifty' => 50, 'forty' => 40,
			'thirty' => 30, 'twenty' => 20, 'fifteen' => 15, 'ten' => 10, 'five' => 5 ) as $w => $n ) {
			$s = (string) preg_replace( '/\b' . $w . '\s*%/', $n . '%', $s );
		}
		$size = '(?![\d\/])(?!\s*(?:"|\'\'|\'|in\b(?!\s+advance)|inch|mm\b|cm\b|ft\b|x\b))';
		$s = (string) preg_replace( '/\b(?:one[- ]half|a\s+half|1\/2)' . $size . '/', 'half', $s );
		$s = (string) preg_replace( '/\b(?:two[- ]thirds|2\/3)' . $size . '/', '67%', $s );
		$s = (string) preg_replace( '/\b(?:one[- ]third|a\s+third|1\/3)' . $size . '/', '33%', $s );
		$s = (string) preg_replace( '/\b(?:three[- ]quarters|3\/4)' . $size . '/', '75%', $s );
		$s = (string) preg_replace( '/\b(?:one[- ]quarter|a\s+quarter|1\/4)' . $size . '/', '25%', $s );
		$s = (string) preg_replace( '/(\d)\s+%/', '$1%', $s );
		return trim( (string) preg_replace( '/\s+/', ' ', $s ) );
	}

	private static function cents_of( string $figure ): int {
		$v = preg_replace( '/[^\d.]/', '', $figure );
		return is_numeric( $v ) ? (int) round( (float) $v * 100 ) : 0;
	}

	private static function amount( string $a ): array {
		$a = trim( $a );
		if ( 'half' === $a ) {
			return array( 50.0, 0 );
		}
		if ( '%' === substr( $a, -1 ) ) {
			return array( (float) $a, 0 );
		}
		return array( 0.0, self::cents_of( $a ) );
	}

	private static function promo( string $n, int $at, int $len ): bool {
		$R      = self::re();
		$before = substr( $n, max( 0, $at - 30 ), min( 30, $at ) );
		if ( preg_match( $R['disc'], $before ) ) {
			return true;
		}
		$wide = substr( $n, max( 0, $at - 60 ), min( 60, $at ) );
		return preg_match( $R['discby'], $wide ) && ! preg_match( '/^\s*(?:deposits?|down\b|down\s*-?\s*payments?|downpayments?|retainers?)/', substr( $n, $at + $len, 30 ) );
	}

	private static function asks( string $n, bool $terms ): array {
		$R   = self::re();
		$out = array();
		if ( preg_match_all( $R['p1'], $n, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[1] as $hit ) {
				if ( self::promo( $n, $hit[1], strlen( $hit[0] ) ) ) {
					continue;
				}
				$out[] = self::amount( $hit[0] );
			}
		}
		if ( preg_match_all( $R['p2'], $n, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[1] as $k => $a ) {
				$at = $m[0][ $k ][1];
				if ( preg_match( $R['denied'], substr( $n, max( 0, $at - 20 ), min( 20, $at ) ) ) ) {
					continue;
				}
				if ( '%' === substr( $a[0], -1 ) && preg_match( $R['notpct'], substr( $n, $a[1] + strlen( $a[0] ), 40 ) ) ) {
					continue;
				}
				if ( '$' !== $a[0][0] && '%' !== substr( $a[0], -1 ) && 'half' !== $a[0]
					&& preg_match( '/(?:0?[1-9]|1[0-2])[\/-](?:0?[1-9]|[12]\d|3[01])/', substr( $n, $at, $a[1] - $at ) ) ) {
					continue;
				}
				$out[] = self::amount( $a[0] );
			}
		}

		if ( $terms || preg_match( $R['payword'], $n ) || preg_match( '/^\d{1,3}\s*%?(?:\s*\/\s*\d{1,3}\s*%?){1,3}[.!]?$/', $n ) ) {
			if ( preg_match_all( $R['split'], $n, $m, PREG_SET_ORDER ) ) {
				foreach ( $m as $s ) {
					$parts = array();
					for ( $k = 1; $k <= 4; $k++ ) {
						if ( isset( $s[ $k ] ) && '' !== $s[ $k ] ) {
							$parts[] = (int) $s[ $k ];
						}
					}
					if ( count( $parts ) >= 2 && 100 === array_sum( $parts ) ) {
						$out[] = array( (float) $parts[0], 0 );
					}
				}
			}
			if ( preg_match( $R['halves'], $n ) ) {
				$out[] = array( 50.0, 0 );
			}
			if ( ( $terms || preg_match( $R['payword'], $n ) ) && preg_match_all( $R['dash'], $n, $m, PREG_SET_ORDER ) ) {
				foreach ( $m as $d ) {
					if ( (int) $d[1] > 0 && (int) $d[2] > 0 && 100 === (int) $d[1] + (int) $d[2] ) {
						$out[] = array( (float) $d[1], 0 );
					}
				}
			}
		}
		if ( $out && preg_match_all( $R['restate'], $n, $m ) ) {
			foreach ( $m[1] as $a ) {
				$out[] = array( 0.0, self::cents_of( $a ) );
			}
		}
		return $out;
	}

	private static function maybes( string $n ): array {
		$R = self::re();
		if ( ! preg_match( $R['advany'], $n ) || ! preg_match( $R['payword'], $n )
			|| preg_match( '/\b(?:no|without|zero)\s+(?:an?\s+)?(?:deposit|down\s*-?\s*payment|retainer)s?\b|\b(?:deposit|down\s*-?\s*payment|retainer)s?\s+(?:waived|not\s+required)\b/', $n ) ) {
			return array();
		}
		$out = array();
		if ( preg_match_all( '/(?<![\w.])(\d{1,3}(?:\.\d+)?)%/', $n, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[0] as $k => $hit ) {
				$after  = substr( $n, $hit[1] + strlen( $hit[0] ), 40 );
				$before = substr( $n, max( 0, $hit[1] - 30 ), min( 30, $hit[1] ) );
				if ( ! preg_match( $R['notpct'], $after ) && ! self::promo( $n, $hit[1], strlen( $hit[0] ) ) ) {
					$out[] = array( (float) $m[1][ $k ][0], 0 );
				}
			}
		}
		if ( preg_match( '/\bhalf\b(?!\s*(?:-?\s*off|-?\s*price|the\s+(?:windows?|doors?|price|' . ZEST_Deposit_Limit::alt( array(), 'product' ) . ')))/', $n ) ) {
			$out[] = array( 50.0, 0 );
		}
		if ( preg_match_all( '/\$\s?((?:\d{1,3}(?:,\d{3})+|\d+)(?:\.\d{1,2})?)/', $n, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[0] as $k => $hit ) {
				if ( ! preg_match( $R['notusd'], substr( $n, max( 0, $hit[1] - 30 ), min( 30, $hit[1] ) ) ) ) {
					$out[] = array( 0.0, self::cents_of( $m[1][ $k ][0] ) );
				}
			}
		}
		return $out;
	}

	private static function worst( array $amounts, int $pretax ): array {
		$best = array( false, '' );
		$top  = -1.0;
		foreach ( $amounts as $a ) {
			list( $is, $why ) = self::over( $a[0], $a[1], $pretax );
			if ( ! $is ) {
				continue;
			}
			$score = $a[0] > 0 ? ( $pretax > 0 ? $pretax * $a[0] / 100 : $a[0] * 1e7 ) : (float) $a[1];
			if ( $score > $top ) {
				$top  = $score;
				$best = array( true, $why );
			}
		}
		return $best;
	}

	private static function only_talk( string $n ): bool {
		if ( null === self::$talk ) {
			self::$talk = array_flip( array_merge( explode( ' ', self::TALK ), self::talk_extra() ) );
		}

		$rest = (string) preg_replace( '/\bwhen\s+(?:you\s+are\s+|you\'re\s+|you\s+re\s+)?ready\b|(?:\b(?:on|at|by)\s+)?(?:the\s+)?\b(?:date|day)\s+of\s+(?:the\s+)?(?:install(?:ation)?|completion)\b/', ' ', $n );

		$rest = (string) preg_replace_callback( '/\b\d{1,3}(?:\s*[\/-]\s*\d{1,4})+\b/', static function ( $m ) use ( $rest ) {
			$parts = array_map( 'intval', preg_split( '/\s*[\/-]\s*/', $m[0][0] ) );
			if ( count( $parts ) < 2 || 100 !== array_sum( $parts ) || in_array( 0, $parts, true ) ) {
				return $m[0][0];
			}
			$before = substr( $rest, 0, $m[0][1] );
			$after  = substr( $rest, $m[0][1] + strlen( $m[0][0] ) );
			$alone  = preg_match( '/^[\s,;:.-]*$/', $before ) && preg_match( '/^\s*[.!]?\s*$/', $after );
			$said   = preg_match( '/\b(?:terms|payments?|pay|paid|split|deposits?|due|balance|schedule|plan)\s*[:=-]?\s*$|%\s*$/', $before )
				|| preg_match( '/^\s*[-,;:.)]?\s*\(?\s*(?:payments?|split|terms|plan|schedule|half|deposits?|down|due|at|upon)\b/', $after );
			return ( $alone || $said ) ? ' ' : $m[0][0];
		}, $rest, -1, $count, PREG_OFFSET_CAPTURE );
		$rest = (string) preg_replace( '/\$\s?(?:\d{1,3}(?:,\d{3})+|\d+)(?:\.\d{1,2})?|\d{1,3}(?:\.\d+)?\s*%(?:\s*\/\s*\d{1,3}\s*%?)*|=\s*\$?\s?(?:\d{1,3}(?:,\d{3})+|\d+)(?:\.\d{1,2})?/', ' ', $rest );
		if ( preg_match( '/\d/', $rest ) ) {
			return false;
		}
		foreach ( preg_split( '/[^a-z]+/', $rest, -1, PREG_SPLIT_NO_EMPTY ) as $w ) {
			if ( strlen( $w ) > 1 && ! isset( self::$talk[ $w ] ) ) {
				return false;
			}
		}
		return true;
	}

	private static function clauses( string $text ): array {
		$out = array();
		$n   = strlen( $text );
		$s   = 0;
		for ( $i = 0; $i <= $n; $i++ ) {
			$end  = ( $i === $n );
			$c    = $end ? '' : $text[ $i ];
			$hard = $end || "\n" === $c || "\r" === $c || "\0" === $c;
			if ( ! $end && ( "\n" === $c || "\r" === $c ) && preg_match( '/:[ \t]*\r?\z/', substr( $text, $s, $i - $s ) ) && preg_match( '/\S/', substr( $text, $s, $i - $s ) ) ) {
				continue;
			}
			$cut = $hard || ( strpos( '.;!?', $c ) !== false && ( $i + 1 >= $n || ctype_space( $text[ $i + 1 ] ) )
				&& ! ( '.' === $c && preg_match( '/\b(?:approx|appx|est|no|nos|qty|vs|etc|inc|ltd|mr|mrs|ms|dr|st|ave|apt|ste|e\.g|i\.e)$/i', substr( $text, max( $s, $i - 6 ), $i - max( $s, $i - 6 ) ) ) ) );
			if ( ! $cut ) {
				continue;
			}
			$stop = $hard ? $i : $i + 1;
			while ( $s < $stop && ( ctype_space( $text[ $s ] ) || "\0" === $text[ $s ] ) ) {
				$s++;
			}
			while ( $stop > $s && ( "\r" === $text[ $stop - 1 ] || "\n" === $text[ $stop - 1 ] ) ) {
				$stop--;
			}
			if ( $stop > $s ) {
				$out[] = array( $s, $stop - $s );
			}
			$s = $i + 1;
		}
		return $out;
	}

	private static function cut( string $clause, int $pretax, bool $terms ): ?array {
		$end = '';
		if ( preg_match( '/[.!?;]+$/', $clause, $m ) ) {
			$end    = $m[0];
			$clause = substr( $clause, 0, -strlen( $end ) );
		}
		$parts = array();
		$pos   = 0;
		$prev  = '';
		if ( preg_match_all( '/,\s+|;\s+|\s+(?:-|\xE2\x80\x93|\xE2\x80\x94)\s+|\s+(?=\()|(?<=\))\s+/', $clause, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[0] as $d ) {
				$parts[] = array( substr( $clause, $pos, $d[1] - $pos ), $prev );
				$prev    = $d[0];
				$pos     = $d[1] + strlen( $d[0] );
			}
		}
		$parts[] = array( substr( $clause, $pos ), $prev );
		if ( count( $parts ) < 2 ) {
			return null;
		}
		$kind = array();
		$any  = false;
		foreach ( $parts as $i => $p ) {
			if ( substr_count( $p[0], '(' ) !== substr_count( $p[0], ')' ) || substr_count( $p[0], '"' ) % 2
				|| substr_count( $p[0], "\xE2\x80\x9C" ) !== substr_count( $p[0], "\xE2\x80\x9D" ) ) {
				return null;
			}
			$n    = self::norm( $p[0] );
			$ask  = self::worst( self::asks( $n, $terms ), $pretax )[0];
			$talk = self::only_talk( $n );
			if ( $ask && ! $talk ) {
				return null;
			}
			$kind[ $i ] = $ask ? 'cut' : ( $talk ? 'pay' : 'keep' );
			$any        = $any || $ask;
		}
		if ( ! $any ) {
			return null;
		}
		do {
			$moved = false;
			foreach ( $kind as $i => $k ) {
				if ( 'pay' === $k && ( 'cut' === ( $kind[ $i - 1 ] ?? '' ) || 'cut' === ( $kind[ $i + 1 ] ?? '' ) ) ) {
					$kind[ $i ] = 'cut';
					$moved      = true;
				}
			}
		} while ( $moved );
		$kept  = '';
		$taken = array();
		$first = true;
		foreach ( $parts as $i => $p ) {
			if ( 'cut' === $kind[ $i ] ) {
				$taken[] = trim( $p[0] );
				continue;
			}
			$kept .= ( $first ? '' : $p[1] ) . $p[0];
			$first = false;
		}
		$kept = trim( $kept );
		return '' === $kept ? null : array( $kept . $end, implode( ', ', $taken ) );
	}

	/**
	 * The over-the-limit deposit asks in $text, read the plain way (no rewriting): [ { clause, why, sure } ]. Empty
	 * when no limit is in force.
	 */
	public static function find( string $text, int $pretax_cents = 0, bool $terms = false ): array {
		if ( '' === trim( $text ) || ! ZEST_Deposit_Limit::active() ) {
			return array();
		}
		$R      = self::re();
		$out    = array();
		$masked = $text;
		if ( preg_match_all( ZEST_Deposit_Limit::own_re(), $text, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[0] as $k => $hit ) {
				list( $is, $why ) = self::over( 0.0, self::cents_of( $m[1][ $k ][0] ), $pretax_cents );
				if ( $is ) {
					$out[] = array( 'start' => $hit[1], 'length' => strlen( $hit[0] ), 'clause' => $hit[0], 'why' => $why,
						'sure' => true, 'whole' => true, 'own' => true, 'cut' => null, 'taken' => $hit[0] );
				}
				$masked = substr_replace( $masked, str_repeat( "\0", strlen( $hit[0] ) ), $hit[1], strlen( $hit[0] ) );
			}
		}
		foreach ( self::clauses( $masked ) as $c ) {
			$clause = substr( $text, $c[0], $c[1] );
			$n      = self::norm( $clause );
			if ( '' === $n || preg_match( $R['record'], $n ) ) {
				continue;
			}
			$sure = self::asks( $n, $terms );
			list( $is, $why ) = self::worst( $sure, $pretax_cents );
			if ( $is ) {
				$whole = self::only_talk( $n );
				$cut   = $whole ? null : self::cut( $clause, $pretax_cents, $terms );
				$out[] = array( 'start' => $c[0], 'length' => $c[1], 'clause' => trim( $clause ), 'why' => $why, 'sure' => true,
					'whole' => $whole, 'own' => false, 'cut' => $cut ? $cut[0] : null, 'taken' => $whole ? trim( $clause ) : ( $cut ? $cut[1] : '' ) );
				continue;
			}
			if ( ! $sure ) {
				list( $is, $why ) = self::worst( self::maybes( $n ), $pretax_cents );
				if ( $is ) {
					$out[] = array( 'start' => $c[0], 'length' => $c[1], 'clause' => trim( $clause ), 'why' => $why, 'sure' => false,
						'whole' => false, 'own' => false, 'cut' => null, 'taken' => '' );
				}
			}
		}
		usort( $out, static function ( $a, $b ) { return $a['start'] <=> $b['start']; } );
		return $out;
	}
}
