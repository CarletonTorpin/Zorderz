<?php
/**
 * ZEST_Sale_Form - the printed sale form, filled from an estimate, so the salesperson need not copy it out by hand.
 *
 * The server decides every word (facts()) and checks the print request (check_print()); where each word goes on the
 * business's own blank form is the business's: option zest_sale_form ({ blank_url, sha256, fields: { pdf field name =>
 * fact key } }, document-conventions.yml `estimate.sale_form`), and the browser puts the words on the blank. A business
 * with no blank still gets the facts (a summary sheet), never a guessed contract.
 *
 * WHAT IS SAID, from the estimate (the billing system's copy when there is one), and nothing else: the salesperson's
 * name, the estimate number, the buyer's name, phone, email and the job's address in its parts (ZEST_Contract_Address);
 * one item per priced line (its name, its description with its $0 parts, qty, price); in cents, the lines, the tax
 * (worked out as the billing system does), the discount and the adjustment that makes them add up to the contract price;
 * the contract price; the Downpayment (the salesperson's choice on the sheet, and only ever the form's own figure: the
 * business's deposit rule, never over the deposit limit, ZEST_Payment_Plan::deposit_for) and the Final payment (the
 * rest); the signing day.
 * NEVER SAID: anything a person decides or signs. The pre-fill never guesses: a blank is better than a wrong word on a
 * contract. A name in letters the form's font can't print is left for the pen, never printed as "?".
 *
 * WHO (zest_sale_form): whoever may open the estimate (ZEST_Access); never the shared device; never in a view that
 * hides prices. Estimates only. Refused while the estimate surely asks a deposit over the limit, as Approve and Send
 * are: the printed schedule and the estimate must not disagree. Read-only: nothing is written anywhere, and nothing is
 * stored. The log line carries the estimate id and the item count, never a customer's words.
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZEST_Sale_Form {

	const OPT = 'zest_sale_form';

	/** Registers the sale form's AJAX route. */
	public static function init(): void {
		add_action( 'wp_ajax_zest_sale_form', array( __CLASS__, 'ajax_sale_form' ) );
	}

	/** The index of the app's own location line among the document's lines, or null. */
	public static function location_index( array $doc ): ?int {
		foreach ( array_values( (array) ( $doc['lines'] ?? array() ) ) as $i => $l ) {
			if ( is_array( $l ) && preg_match( '/^\s*(?:property\s+)?location\b/i', (string) ( $l['name'] ?? '' ) ) ) {
				return $i;
			}
		}
		return null;
	}

	/** A real calendar date, Y-m-d. */
	public static function valid_date( string $d ): bool {
		return (bool) preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/** Today in the site's time zone, Y-m-d. */
	public static function today(): string {
		if ( class_exists( 'ZAGR_Clock' ) && method_exists( 'ZAGR_Clock', 'today' ) ) {
			return (string) ZAGR_Clock::today();
		}
		return function_exists( 'wp_date' ) ? (string) wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
	}

	/**
	 * Check a print request against what the sheet showed ($dp: downpayment()). $in: signing (Y-m-d), down (the dollars
	 * typed, '' for the pen; required), seen (the sheet's hash), older ('yes' | 'no', required where $asks_age).
	 * { ok, code, message, extra { signing, down (cents|null), older } }. Pure.
	 */
	public static function check_print( array $dp, array $in, string $today, bool $asks_age ): array {
		$no = static function ( string $code, string $message ): array {
			return array( 'ok' => false, 'code' => $code, 'message' => $message, 'extra' => array() );
		};
		$signing = trim( (string) ( $in['signing'] ?? '' ) );
		if ( '' === $signing ) {
			$signing = $today;
		}
		if ( ! self::valid_date( $signing ) ) {
			return $no( 'signing', __( 'Pick the day the customer will sign.', 'zorderz' ) );
		}
		$days = (int) round( ( strtotime( $signing . ' 12:00:00 UTC' ) - strtotime( $today . ' 12:00:00 UTC' ) ) / 86400 );
		if ( $days < -3 || $days > 14 ) {
			return $no( 'signing', __( 'Pick the signing day within the next two weeks (or the last three days).', 'zorderz' ) );
		}
		if ( ! array_key_exists( 'down', $in ) || is_array( $in['down'] ) ) {
			return $no( 'down', __( 'Confirm the downpayment first: open the sale form again.', 'zorderz' ) );
		}
		if ( ! is_string( $in['seen'] ?? null ) || ! hash_equals( (string) $dp['seen'], (string) $in['seen'] ) ) {
			return $no( 'changed', __( 'The estimate changed since this sheet opened, so nothing was filled. Check the downpayment again, then print.', 'zorderz' ) );
		}
		$down = null;
		if ( empty( $dp['locked'] ) ) {
			$raw = trim( (string) $in['down'] );
			if ( '' !== $raw ) {
				if ( ! preg_match( '/^\$?\s*(\d{1,3}(?:,\d{3})+|\d{1,7})(?:\.(\d{1,2}))?$/', $raw, $dm ) ) {
					return $no( 'down', __( 'Write the downpayment in dollars, like 500 or 500.00, or leave it empty to write it by hand.', 'zorderz' ) );
				}
				$cents = (int) str_replace( ',', '', $dm[1] ) * 100 + (int) str_pad( (string) ( $dm[2] ?? '' ), 2, '0' );
				if ( (int) $dp['cap'] <= 0 || $cents !== (int) $dp['cap'] ) {
					return (int) $dp['cap'] > 0
						/* translators: %s: the form's downpayment */
						? $no( 'down', sprintf( __( 'The downpayment on this form can only be $%s (the business\'s deposit, within the deposit limit), or left for you to write.', 'zorderz' ), self::money( (int) $dp['cap'] ) ) )
						: $no( 'down', __( 'No downpayment is set for this business, so leave it for you to write.', 'zorderz' ) );
				}
				$down = $cents;
			}
		}
		$older = (string) ( $in['older'] ?? '' );
		if ( $asks_age && 'yes' !== $older && 'no' !== $older ) {
			return $no( 'older', __( 'Answer whether any buyer is 65 or older: it decides the cancellation period printed on the form.', 'zorderz' ) );
		}
		return array( 'ok' => true, 'code' => 'ok', 'message' => '', 'extra' => array( 'signing' => $signing, 'down' => $down, 'older' => $asks_age ? $older : '' ) );
	}

	/** The business's form: { blank_url, sha256, fields: { field => fact key } }, or null. */
	public static function form(): ?array {
		$f = function_exists( 'get_option' ) ? get_option( self::OPT, null ) : null;
		$f = function_exists( 'apply_filters' ) ? apply_filters( self::OPT, $f ) : $f;
		if ( ! is_array( $f ) || ! is_array( $f['fields'] ?? null ) || ! preg_match( '#^https://#', (string) ( $f['blank_url'] ?? '' ) ) || ! preg_match( '/^[0-9a-f]{64}$/', (string) ( $f['sha256'] ?? '' ) ) ) {
			return null;
		}
		return $f;
	}

	/** The words for the business's form, by field name: each field gets its fact (items by "items.<n>.<key>"). */
	public static function fill( array $facts, array $form ): array {
		$out = array();
		foreach ( (array) $form['fields'] as $field => $key ) {
			$key = (string) $key;
			if ( preg_match( '/^items\.(\d+)\.(what|notes|qty|price)$/', $key, $m ) ) {
				$v = (string) ( $facts['items'][ (int) $m[1] ][ $m[2] ] ?? '' );
			} else {
				$v = isset( $facts[ $key ] ) && is_scalar( $facts[ $key ] ) ? (string) $facts[ $key ] : '';
			}
			if ( '' !== $v && self::printable( $v ) ) {
				$out[ (string) $field ] = $v;
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ the facts, from the estimate */

	private static function cents( $v ): int {
		if ( is_array( $v ) ) {
			$v = $v['amount'] ?? 0;
		}
		return is_numeric( $v ) ? (int) round( (float) $v * 100 ) : 0;
	}

	/** Cents as money words: "$1,000" or "$12.50". */
	public static function money( int $cents ): string {
		return number_format( $cents / 100, 2, '.', ',' );
	}

	/** A quantity as the billing system prints it: "1", "2.5". */
	public static function qty( float $q ): string {
		$s = rtrim( rtrim( number_format( $q, 2, '.', '' ), '0' ), '.' );
		return '' === $s ? '0' : $s;
	}

	private static function oneline( string $s ): string {
		return trim( preg_replace( '/\s+/u', ' ', $s ) );
	}

	/**
	 * The figures and words a sale form needs from a billing document, plus the facts the person typed ($extra):
	 * items, prices, tax, total, down payment and the job's address.
	 */
	public static function facts( array $doc, array $extra ): array {
		$items    = array();
		$pos      = 0;
		$neg      = 0;
		$credits  = 0;
		$charges  = 0;
		$sub      = 0;
		$taxl     = 0;
		$rates    = array();
		$parts    = array();
		$loc      = self::location_index( $doc );
		foreach ( array_values( (array) ( $doc['lines'] ?? array() ) ) as $li => $l ) {
			if ( ! is_array( $l ) ) {
				continue;
			}
			$q    = is_numeric( $l['qty'] ?? null ) ? (float) $l['qty'] : 1.0;
			$rate = self::cents( $l['unit_cost'] ?? 0 );
			$amt  = self::line_cents( $l, $q, $rate );
			$sub += $amt;
			if ( $amt > 0 ) {
				if ( ZEST_Deposit_Rule::deposit_line( $l ) ) {
					$charges += $amt;
					continue;
				}
				$rates[] = array( $amt, self::tax_rates( $l ) );
				if ( self::tax_only( $l ) ) {
					$taxl += $amt;
					continue;
				}
				$pos    += $amt;
				$items[] = array(
					'what'     => self::oneline( (string) ( $l['name'] ?? '' ) ),
					'notes'    => self::oneline( (string) ( $l['description'] ?? '' ) ),
					'qty'      => self::qty( $q ),
					'price'    => self::money( $amt ),
					'includes' => array(),
					'li'       => $li,
				);
			} elseif ( 0 === $amt ) {

				// a $0 part of the priced work is listed in the card of the priced line above it, with its quantity
				if ( self::part_line( $l, $q ) && $loc !== $li ) {
					$nm      = self::oneline( (string) ( $l['name'] ?? '' ) );
					$parts[] = array(
						'li'   => $li,
						'text' => $nm . ( abs( $q - 1.0 ) > 0.0001 ? ' (' . self::qty( $q ) . ')' : '' ),
					);
				}
			} elseif ( $amt < 0 ) {
				if ( ZEST_Deposit_Rule::credit_line( $l ) ) {
					$credits += -$amt;
					continue;
				}
				$neg += -$amt;
			}
		}

		// where each $0 part goes: the priced line above it, or the first when none is above
		$unprintable_part = false;
		$part_notes       = array();
		foreach ( $parts as $pt ) {
			if ( ! $items ) {
				break;
			}
			if ( ! self::printable( $pt['text'] ) ) {
				$unprintable_part = true;
				continue;
			}
			$to = 0;
			foreach ( $items as $k => $it ) {
				if ( $it['li'] < $pt['li'] ) {
					$to = $k;
				}
			}
			$items[ $to ]['includes'][] = $pt['text'];
		}
		foreach ( $items as $k => $it ) {
			if ( $it['includes'] ) {
				$list = 'Includes: ' . rtrim( implode( ', ', $it['includes'] ), '.' ) . '.';
				$sep  = '' === $it['notes'] ? '' : ( preg_match( '/[.!?:;]$/', $it['notes'] ) ? ' ' : '. ' );
				$items[ $k ]['notes'] = $it['notes'] . $sep . $list;
			}
			unset( $items[ $k ]['includes'], $items[ $k ]['li'] );
		}
		if ( $unprintable_part ) {
			$part_notes[] = 'A part\'s name uses letters the form\'s font can\'t print, so it was left off its item: write it by hand.';
		}

		$discount = $neg + ZEST_Deposit_Rule::doc_discount_cents( $doc );
		$contract = self::cents( $doc['amount'] ?? 0 );
		$notes    = $part_notes;

		$dp   = self::downpayment( $doc );
		$down = null;
		$paid = $dp['locked'];
		if ( $paid ) {
			$notes[] = 'The estimate has a line for money already paid or a deposit charge, so the contract price and the payments were left for you to write.';
		} elseif ( $contract > 0 ) {
			$down = $extra['down'] ?? null;
			if ( null !== $down && ( ! is_int( $down ) || $down !== $dp['cap'] ) ) {
				$down = null;
			}
			if ( null === $down ) {
				$notes[] = 'The downpayment and final payment were left for you to write.';
			}
		}

		$tax    = ( ! $paid && $contract > 0 ) ? $taxl + self::tax_cents( $doc, $rates, $sub ) : 0;
		$adjust = ( ! $paid && $contract > 0 ) ? $contract - ( $pos + $tax - $discount ) : 0;

		$fname  = self::oneline( (string) ( $doc['fname'] ?? '' ) );
		$lname  = self::oneline( (string) ( $doc['lname'] ?? '' ) );
		$org    = self::oneline( (string) ( $doc['organization'] ?? '' ) );
		$name   = trim( $fname . ' ' . $lname );
		$signing = (string) ( $extra['signing'] ?? '' );
		return array(
			'number'         => self::oneline( (string) ( $doc['estimate_number'] ?? '' ) ),
			'salesperson'    => self::oneline( (string) ( $extra['salesperson'] ?? '' ) ),
			'prepared'       => '' !== $name ? $name : $org,
			'phone'          => self::oneline( (string) ( $extra['phone'] ?? '' ) ),
			'email'          => self::oneline( (string) ( $extra['email'] ?? '' ) ),

			'street'         => self::oneline( ZEST_Contract_Address::street( (string) ( $doc['street'] ?? '' ), (string) ( $doc['street2'] ?? '' ), array_merge( array( (string) ( $extra['phone'] ?? '' ) ), array_map( 'strval', (array) ( $extra['phones'] ?? array() ) ) ) ) ),
			'city'           => self::oneline( ZEST_Contract_Address::city( (string) ( $doc['city'] ?? '' ), (string) ( $doc['code'] ?? '' ) ) ),
			'zip'            => self::oneline( (string) ( $doc['code'] ?? '' ) ),
			'state'          => self::oneline( (string) ( $doc['province'] ?? '' ) ),
			'country'        => self::oneline( (string) ( $doc['country'] ?? '' ) ),
			'items'          => $items,
			'contract'       => ( ! $paid && $contract > 0 ) ? self::money( $contract ) : '',
			'down'           => null !== $down ? self::money( $down ) : '',
			'final'          => null !== $down ? self::money( $contract - $down ) : '',
			'signing'        => self::valid_date( $signing ) ? $signing : '',
			'notes'          => $notes,

			'tax_cents'      => max( 0, $tax ),
			'discount_cents' => $discount > 0 ? $discount : 0,
			'adjust_cents'   => $adjust,
			'lines_cents'    => $pos,
		);
	}

	/**
	 * One line's amount in cents: the billing system's own amount when it agrees with quantity times rate, else
	 * quantity times rate.
	 */
	public static function line_cents( array $l, float $q, int $rate ): int {
		$exact = $q * $rate;
		$own   = $l['amount'] ?? null;
		$own   = is_array( $own ) ? ( $own['amount'] ?? null ) : $own;
		if ( is_numeric( $own ) ) {
			$c = (int) round( (float) $own * 100 );
			if ( abs( $c - $exact ) < 1.0 ) {
				return $c;
			}
		}
		return (int) round( $exact );
	}

	private static function tax_rates( array $l ): array {
		$out = array();
		foreach ( array( 1, 2 ) as $k ) {
			$tn = trim( (string) ( $l[ 'taxName' . $k ] ?? '' ) );
			$tr = $l[ 'taxAmount' . $k ] ?? null;
			if ( '' !== $tn && is_numeric( $tr ) && (float) $tr > 0 ) {
				$out[] = array( $tn, (int) round( (float) $tr * 100 ) );
			}
		}
		return $out;
	}

	private static function tax_cents( array $doc, array $rates, int $sub ): int {
		$dv = is_numeric( $doc['discount_value'] ?? null ) ? (int) round( (float) $doc['discount_value'] * 100 ) : 0;
		$dt = ZEST_Deposit_Rule::doc_discount_cents( $doc );
		$discount = $dt > 0 ? $dt : ( ( $dv > 0 && $sub > 0 ) ? intdiv( $sub * $dv + 5000, 10000 ) : 0 );
		$acc = array();
		foreach ( $rates as $r ) {
			foreach ( $r[1] as $tx ) {
				$key = strtolower( $tx[0] ) . '|' . $tx[1];
				if ( $dv > 0 ) {
					$acc[ $key ] = ( $acc[ $key ] ?? 0 ) + $r[0] * $tx[1] * ( 10000 - $dv );
				} else {
					$share       = ( $discount > 0 && $sub > 0 ) ? ( $sub - $discount ) / $sub : 1.0;
					$acc[ $key ] = ( $acc[ $key ] ?? 0 ) + (int) round( $r[0] * $tx[1] * 10000 * $share );
				}
			}
		}
		$tax = 0;
		foreach ( $acc as $n ) {
			$tax += intdiv( (int) $n + 50000000, 100000000 );
		}
		return $tax;
	}

	/** Is this line goods or work the form lists (not a location, note, deposit, tax or code line)? */
	public static function part_line( array $l, float $q ): bool {
		$name = self::oneline( (string) ( $l['name'] ?? '' ) );
		$meta = class_exists( 'ZDZ_Doc_Conventions' ) && ZDZ_Doc_Conventions::is_metadata_line( $name, 0 );
		if ( '' === $name || $q < 0.005 || $meta || preg_match( self::NOT_PART, $name ) || ZEST_Deposit_Rule::tax_line( $l ) ) {
			return false;
		}
		return ! self::asks_up_front( $name . ' ' . self::oneline( (string) ( $l['description'] ?? '' ) ) );
	}

	const NOT_PART = '/(?i:^\s*(?:property\s+)?location\b|\binclude[sd]?\b|\bincl\b|\b(?:thank|balance|due|net\s*\d+|paid|pay\w*|fee|fees|price|prices|pricing|valid|discount|rebate|credit|optional|option|warranty|guarantee|deposit|note|notes|terms?|total|subtotal|tax|estimate|quote)\b|\$\s*\d|\d\s*%|\b(?:gate|lock\s*box|lockbox|alarm|keypad|garage|door|access|entry)\s*(?:code|combo|pin)\b|\bcode\b)|\(\s*[A-Z]{2,4}(?:\s*[\/&,]\s*[A-Z]{2,4})*\s*\)|[\[\]]/';

	/** Is this a tax line with nothing else in it (no work or goods named beside the tax)? */
	public static function tax_only( array $l ): bool {
		return ZEST_Deposit_Rule::tax_line( $l ) && ! preg_match( '/\b(?:install\w*|labou?r|permits?|delivery|freight|shipping|trip|haul\w*|removal|disposal|fees?|parts?|' . ZEST_Deposit_Limit::alt( array(), 'product' ) . ')\b/i', (string) ( $l['name'] ?? '' ) );
	}

	const STRONG = '/\bdeposits?\b|\bdown\s*-?\s*payments?\b|\bdownpayments?\b|\bmoney\s+down\b|(?:\$\s*[\d,]+(?:\.\d{2})?|\b\d[\d,]*(?:\.\d+)?\s*%?)\s+down\b|^\s*(?:amount\s+)?down\b\s*(?:[:\x{2013}\x{2014}-]|\$|\d)|\bretainers?\b(?!\s+(?:bars?|strips?|clips?|channels?|rails?))|\bpre-?pay(?:s|ing|ments?)?\b|\bpre-?paid\b(?!\s+freight)|\b\d{1,3}\s*%?\s*[\/-]\s*\d{1,3}\s*%?\s*split\b|\bsplit\s+(?:payments?|\d)|^\s*\d{1,3}\s*%?\s*\/\s*\d{1,3}\s*%?\s*[.!]?\s*$|\b(?:booking|reservation|mobili[sz]ation)\s+fees?\b|^\s*mobili[sz]ation\b|\b(?:initial|first|progress|partial)\s+payments?\b|\bpayment\s+(?:schedule|plan)\b|\binstal{1,2}ments?\b|\b(?:remaining|other|second|final|last)\s+(?:\d{1,3}\s*%|half)|\bfinal\s+payment\s+of\s+\d/iu';

	const NOT_ASK = '/(?:\$\s*[\d,]+(?:\.\d{2})?|\b\d+(?:\.\d+)?\s*(?:%|percent\b))\s*(?:[\w-]+\s+){0,2}?(?:fees?|surcharges?|charges?|interest|off|savings?|rebates?|discounts?|value|back|increase|tax|gift\s+cards?|guarantee|quieter|weatherproof|each|per\s+(?:hour|month|year|foot|ft|sq|square|panel|window|door|unit|item)|an?\s+hour|monthly|APR)\b|\b(?:fees?|surcharges?|charges?|interest|savings?|rebates?|discounts?|save|saves|value|refund(?:ed|s)?|within|up)\b[\s:]+(?:(?:of|by|an?|the|extra|additional|over|up\s+to|about)\s+)*(?:\$\s*[\d,]+(?:\.\d{2})?|\d+(?:\.\d+)?\s*(?:%|percent\b))/i';

	const WHEN = '/\bsign(?:s|ed|ing|ature)?\b|\bexecut\w*|\border(?:ed|ing)?\b|\bapprov\w*|\baccept\w*|\bbefore(?:hand)?\b|\bprior\s+to\b|\bstart\w*|\bbegin\w*|\bmeasur\w*|\bmaterials?\b|\bproduction\b|\bmanufactur\w*|\bfabricat\w*|\bbook(?:s|ed|ing)?\b|\breserv\w*|\bsecur\w*|\bhold(?:s|ing)?\b|\bproceed\w*|\bmove\s+forward\b|\bget\s+(?:going|started|on)\b|\bgo\s+ahead\b|\bnow\b|\btoday\b|\bimmediately\b|\bthis\s+(?:week|month)\b|\brequired\b|\bneeded\b|\bnecessary\b|\bin\s+advance\b|\bup\s*-?\s*front\b|\bscheduling\b|\bdeliver\w*|\btime\s+of\s+(?:contract|agreement|purchase|sale)\b|\b(?:with|upon|on|at)\s+(?:the\s+|a\s+)?(?:signed\s+)?(?:contract|agreement)\b/i';

	const MONEY = '/\$\s*\d|\d\s*%|\bpercent\b|\bhalf\b|\b1\s*\/\s*2\b|\bin\s+full\b|\bdollars?\b|\b\d{1,3}(?:,\d{3})+(?:\.\d{2})?\b|(?<![\d.,])\d+\.\d{2}\b|(?<![\d-])\d{2,}\s+(?:at|upon|on|when|once|before|with|to|due|down|now|today)\b/i';

	const PAY = '/\bpa(?:y|ys|id|yable|yment|yments)\b|\bdue\b|\bbalance\b|\bowed?\b|\bremaining\b/i';

	/** Does this wording ask for money before the work (a deposit, a down payment, a split, a payment on order)? */
	public static function asks_up_front( string $u ): bool {
		if ( preg_match( self::STRONG, $u ) ) {
			return true;
		}
		$s = (string) preg_replace( self::NOT_ASK, ' ', $u );
		return preg_match( self::WHEN, $s ) && ( preg_match( self::MONEY, $s ) || preg_match( self::PAY, $s ) );
	}

	private static function clip( string $s ): string {
		$s = self::oneline( $s );
		return function_exists( 'mb_strlen' ) && mb_strlen( $s ) > 200 ? rtrim( mb_substr( $s, 0, 199 ) ) . "\u{2026}" : $s;
	}

	/**
	 * Where the document speaks of payment: { figures, words, all } with the place each was found (Notes, Terms, Line
	 * N).
	 */
	public static function payment_words( array $doc ): array {
		$src = array( array( 'Notes', (string) ( $doc['notes'] ?? '' ) ), array( 'Terms', (string) ( $doc['terms'] ?? '' ) ) );
		foreach ( array_values( (array) ( $doc['lines'] ?? array() ) ) as $i => $l ) {
			if ( is_array( $l ) ) {
				$src[] = array( 'Line ' . ( $i + 1 ), trim( (string) ( $l['name'] ?? '' ) . "\n" . (string) ( $l['description'] ?? '' ) ) );
			}
		}
		$figures = array();
		$words   = array();
		$all     = array();
		foreach ( $src as $one ) {
			list( $where, $text ) = $one;
			if ( '' === trim( $text ) ) {
				continue;
			}
			if ( 'Notes' === $where && class_exists( 'ZEST_Terms_Note' ) ) {
				list( $text, $own ) = ZEST_Terms_Note::sale_form_part( $text, $doc );
				if ( $own ) {
					$figures[] = $own;
				}
			}
			if ( preg_match_all( ZEST_Deposit_Limit::own_re() . 'i', $text, $m ) ) {
				foreach ( $m[1] as $k => $f ) {
					$figures[] = array( (int) round( (float) str_replace( ',', '', $f ) * 100 ), $where, self::oneline( $m[0][ $k ] ) );
				}
				$text = (string) preg_replace( ZEST_Deposit_Limit::own_re() . 'i', "\n", $text );
			}
			$hits = array();
			foreach ( array( 1, 100000000000 ) as $price ) {
				foreach ( ZEST_Deposit_Rule::find( $text, $price, 'Terms' === $where ) as $f ) {
					$hits[] = (string) $f['clause'];
				}
			}
			$lines = array_values( array_filter( array_map( 'trim', preg_split( '/\R/u', $text ) ), 'strlen' ) );
			foreach ( $lines as $n => $ln ) {
				$alone = false;
				foreach ( preg_split( '/(?<=[.!?;])\s+/u', $ln ) as $sentence ) {
					if ( self::asks_up_front( $sentence ) ) {
						$hits[] = $sentence;
						$alone  = true;
					}
				}
				if ( ! $alone && isset( $lines[ $n + 1 ] ) && ! preg_match( '/[.!?;]$/u', $ln ) && ! self::asks_up_front( $lines[ $n + 1 ] )
					&& self::asks_up_front( $ln . ' ' . $lines[ $n + 1 ] ) ) {
					$hits[] = $ln . ' ' . $lines[ $n + 1 ];
				}
			}
			$kept = array();
			usort( $hits, static function ( $a, $b ) { return strlen( $b ) <=> strlen( $a ); } );
			foreach ( $hits as $h ) {
				$h = self::oneline( $h );
				foreach ( $kept as $k ) {
					if ( '' === $h || false !== stripos( $k, $h ) ) {
						continue 2;
					}
				}
				$kept[] = $h;
			}
			foreach ( $kept as $h ) {
				$words[] = array( 'where' => $where, 'text' => self::clip( $h ) );
				$all[]   = $where . ': ' . $h;
			}
		}

		$pct = is_numeric( $doc['deposit_percentage'] ?? null ) ? (float) $doc['deposit_percentage'] : 0.0;
		$usd = self::cents( $doc['deposit_amount'] ?? 0 );
		if ( isset( $doc['deposit'] ) && is_array( $doc['deposit'] ) ) {
			$pct = max( $pct, is_numeric( $doc['deposit']['percentage'] ?? null ) ? (float) $doc['deposit']['percentage'] : 0.0 );
			$usd = max( $usd, self::cents( $doc['deposit']['amount'] ?? 0 ) );
		}
		if ( $pct > 0 || $usd > 0 ) {
			$words[] = array( 'where' => "The billing system's deposit request", 'text' => $usd > 0 ? '$' . self::money( $usd ) : self::qty( $pct ) . '%' );
		}
		return array( 'figures' => $figures, 'words' => $words, 'all' => $all );
	}

	/**
	 * The down payment the form prints: { contract, cap (the business's deposit by its rule, never over the limit),
	 * locked (a deposit line already there), words, seen }.
	 */
	public static function downpayment( array $doc ): array {
		$locked = false;
		foreach ( (array) ( $doc['lines'] ?? array() ) as $l ) {
			if ( ! is_array( $l ) ) {
				continue;
			}
			$q   = is_numeric( $l['qty'] ?? null ) ? (float) $l['qty'] : 1.0;
			$amt = (int) round( $q * self::cents( $l['unit_cost'] ?? 0 ) );
			if ( ( $amt > 0 && ZEST_Deposit_Rule::deposit_line( $l ) ) || ( $amt < 0 && ZEST_Deposit_Rule::credit_line( $l ) ) ) {
				$locked = true;
			}
		}
		$contract = self::cents( $doc['amount'] ?? 0 );
		$own      = ZEST_Payment_Plan::deposit_for( ZEST_Deposit_Rule::base( $doc ) );   // the business's deposit rule, never over the limit
		$cap      = ( $contract > 0 && null !== $own ) ? min( $contract, $own ) : 0;
		$pw       = self::payment_words( $doc );
		$words    = $pw['words'];
		foreach ( array_reverse( $pw['figures'] ) as $f ) {
			array_unshift( $words, array( 'where' => $f[1], 'text' => $f[2] ) );
		}

		$seen  = substr( hash( 'sha256', (string) json_encode( array( (string) ( $doc['id'] ?? $doc['estimateid'] ?? '' ), $contract, $cap, $locked, $words, $pw['all'] ) ) ), 0, 16 );
		$words = array_slice( $words, 0, 10 );
		return array( 'contract' => $contract, 'cap' => $cap, 'locked' => $locked, 'words' => $words, 'seen' => $seen );
	}

	/** Can the form's font print every character of $s? */
	public static function printable( string $s ): bool {
		return 1 === preg_match( '/^[\x{20}-\x{7E}\x{A0}-\x{FF}\s\x{152}\x{153}\x{160}\x{161}\x{178}\x{17D}\x{17E}\x{192}\x{2C6}\x{2DC}'
			. '\x{2013}\x{2014}\x{2018}-\x{201A}\x{201C}-\x{201E}\x{2020}-\x{2022}\x{2026}\x{2030}\x{2039}\x{203A}\x{20AC}\x{2122}\x{2032}\x{2033}\x{2212}\x{D7}]*$/u', $s );
	}

	/* ------------------------------------------------------------------ the request */

	/** zest_sale_form {id, step: check} | {id, signing, down, seen, older}. */
	public static function ajax_sale_form(): void {
		list( $uid, $row ) = ZEST_Access::load_owned( 'id', true );
		if ( class_exists( 'ZDZ_Privacy_View' ) && ZDZ_Privacy_View::is_on( $uid ) ) {
			wp_send_json_error( array( 'message' => __( 'The sale form shows prices, so it can\'t be printed in this view.', 'zorderz' ) ), 403 );
		}
		$perms = class_exists( 'ZEST_Dashboard' ) ? ZEST_Dashboard::get_resolved_permissions( $uid ) : array( 'view_pricing' => true );
		if ( empty( $perms['view_pricing'] ) ) {
			wp_send_json_error( array( 'message' => __( 'The sale form shows prices, which your account does not see.', 'zorderz' ) ), 403 );
		}
		if ( class_exists( 'ZDZ_Sealed' ) && ! ZDZ_Sealed::ready() ) {
			wp_send_json_error( array( 'message' => __( 'Gate and lockbox codes can\'t be checked just now, so the sale form was not filled in. Try again later, or tell an admin.', 'zorderz' ) ) );
		}
		list( $doc ) = ZEST_Deposit_Check::doc_for( $row );
		$contract    = isset( $doc['amount'] ) ? self::cents( $doc['amount'] ) : ZEST_Payment_Plan::build( $doc )['total_cents'];
		if ( $contract <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'This estimate has no price yet, so there is nothing to put on the sale form.', 'zorderz' ) ) );
		}
		$doc['amount'] = $doc['amount'] ?? number_format( $contract / 100, 2, '.', '' );
		$no = ZEST_Deposit_Check::refusal( $row, $doc, 'filled in', $uid );
		if ( $no ) {
			wp_send_json_error( array( 'code' => $no['code'], 'message' => str_replace( 'so it was not filled in', 'so the sale form was not filled in', $no['message'] ) ) );
		}
		$work = $doc;
		foreach ( array( 'notes', 'terms' ) as $k ) {
			$work[ $k ] = class_exists( 'ZDZ_Sealed' ) ? ZDZ_Sealed::cut( (string) ( $work[ $k ] ?? '' ) ) : (string) ( $work[ $k ] ?? '' );
		}
		foreach ( (array) ( $work['lines'] ?? array() ) as $k => $l ) {
			foreach ( array( 'name', 'description' ) as $f ) {
				if ( is_array( $l ) && isset( $l[ $f ] ) && class_exists( 'ZDZ_Sealed' ) ) {
					$work['lines'][ $k ][ $f ] = ZDZ_Sealed::cut( (string) $l[ $f ] );
				}
			}
		}
		ZEST_Deposit_Check::use_limit( $row );
		$dp  = self::downpayment( $work );
		$cut = static function ( string $t ): string {
			return class_exists( 'ZDZ_Sealed' ) ? ZDZ_Sealed::cut( $t ) : $t;
		};
		$asks_age = false;
		if ( class_exists( 'ZAGR_Pack' ) && method_exists( 'ZAGR_Pack', 'asks' ) ) {
			try {
				$asks_age = (bool) ZAGR_Pack::asks( ZAGR_Pack::stack_for( ZEST_Deposit_Check::region( $row ) ), 'senior' );
			} catch ( \Throwable $e ) {
				$asks_age = false;
			}
		}
		if ( 'check' === ( isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in ZEST_Access::load_owned() above
			wp_send_json_success( array(
				'cap'      => self::money( $dp['cap'] ),
				'fill'     => ( $dp['locked'] || $dp['cap'] <= 0 ) ? '' : self::money( $dp['cap'] ),
				'contract' => self::money( $dp['contract'] ),
				'locked'   => $dp['locked'],
				'words'    => array_map( static function ( $w ) use ( $cut ) {
					return array( 'where' => (string) $w['where'], 'text' => $cut( (string) $w['text'] ) );
				}, $dp['words'] ),
				'seen'     => $dp['seen'],
				'asks_age' => $asks_age,
				'today'    => self::today(),
			) );
		}
		$in = array(
			'signing' => isset( $_POST['signing'] ) ? sanitize_text_field( wp_unslash( $_POST['signing'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in ZEST_Access::load_owned() above
			'seen'    => isset( $_POST['seen'] ) ? sanitize_text_field( wp_unslash( $_POST['seen'] ) ) : null, // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in ZEST_Access::load_owned() above
			'older'   => isset( $_POST['older'] ) ? sanitize_key( wp_unslash( $_POST['older'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in ZEST_Access::load_owned() above
		);
		if ( isset( $_POST['down'] ) && ! is_array( $_POST['down'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in ZEST_Access::load_owned() above
			$in['down'] = sanitize_text_field( wp_unslash( $_POST['down'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in ZEST_Access::load_owned() above
		}
		$ok = self::check_print( $dp, $in, self::today(), $asks_age );
		if ( empty( $ok['ok'] ) ) {
			wp_send_json_error( array( 'code' => $ok['code'], 'message' => $ok['message'] ) );
		}
		$seller = class_exists( 'ZEST_Terms_Note' ) ? ZEST_Terms_Note::seller( $row ) : array( 'name' => '' );
		$facts  = self::facts( $work, array(
			'email'       => (string) ( $row['customer_email'] ?? '' ),
			'phone'       => (string) ( $row['customer_phone'] ?? '' ),
			'phones'      => array( (string) ( $row['customer_phone'] ?? '' ) ),
			'salesperson' => (string) $seller['name'],
			'signing'     => $ok['extra']['signing'],
			'down'        => $ok['extra']['down'],
		) );
		foreach ( array( 'salesperson', 'prepared', 'phone', 'email', 'street', 'city', 'zip', 'state', 'country', 'number' ) as $k ) {
			$facts[ $k ] = $cut( (string) ( $facts[ $k ] ?? '' ) );
		}
		$form = self::form();
		error_log( 'Zorderz Estimates: sale form filled for estimate id ' . (int) $row['id'] . ' (' . count( (array) $facts['items'] ) . ' item(s)).' );
		wp_send_json_success( array(
			'facts'  => $facts,
			'older'  => $ok['extra']['older'],
			'form'   => $form ? array( 'blank_url' => esc_url_raw( (string) $form['blank_url'] ), 'sha256' => (string) $form['sha256'], 'fields' => self::fill( $facts, $form ) ) : null,
		) );
	}
}
