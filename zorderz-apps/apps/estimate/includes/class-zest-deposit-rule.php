<?php
/**
 * ZEST_Deposit_Rule - no estimate asks for a deposit over the limit that applies to it.
 *
 * Pure: no WordPress, no network, no model. The same words and price always give the same answer. Money is cents; the
 * price is the PRE-TAX amount (the smaller base, so the safer one). The limit itself is ZEST_Deposit_Limit's: the
 * legal packs of the customer-agreements app (where a state limits a home-improvement down payment) and the business's
 * own, stricter one. With no limit in force, find() finds nothing.
 *
 * NOTHING HERE WRITES. Approve and Send refuse a sure ask (ZEST_Deposit_Check); Fix shows what fix_doc() would change
 * and writes only when a person confirms that preview. Every change to a customer's words is seen first.
 *
 * READING (find). Words are read clause by clause: a clause ends at a line break or at . ; ! ? before a space, except
 *   after a short abbreviation ("approx.") or at the end of a line that ends with ":" ("Deposit:" / "50%"). Figures are
 *   read plainly first: curly quotes, "percent", "pct", "fifty percent", "one-half", "1/2", "USD 2,500", "$2.5k",
 *   "Twenty-five hundred dollars", "$2,000 ($2,000.00)" and "Half (50%)" as one amount.
 *   SURE (refuses Approve and Send) when an amount is bound to a step before the work by one of these shapes:
 *     amount, then the advance:   "50% deposit", "half at order", "$2,500 down", "50% is required to start",
 *                                 "2,500 deposit", "half the total is due at signing", "a 50% non-refundable deposit"
 *     the advance, then amount:   "Deposit required: 50%", "a retainer of $1,200", "Initial payment of 50%",
 *                                 "At signing: 50%", "Dep: 50%", "Prepay 50%", "Deposit USD 2,500"
 *     a deposit's label and step: "Deposit when you sign: $1,500", "Down payment with signed estimate: $2,000"
 *     a split with payment words: "Terms: 50/50", "Payment: 50% / 50%", "Payment terms 50-50" (in Terms, any split)
 *     a down label:               "Down: 50%", "Amount down - $1,234.50", "Down pmt: $500"
 *     a clear step before work:   "50% when the contract is signed", "upon execution of this agreement", "with signed
 *                                 estimate", "before we place the order", "before production begins", "to move
 *                                 forward", "$500 holds your install date", "at final measure", "in full" or "full
 *                                 payment" as 100%, a booking or reservation fee, the goods shipping or arriving at the
 *                                 business ("Half when your order ships"), a percent alone under a bare label for a
 *                                 step before the work ("Signing: 50%", "Order - 50%")
 *   POSSIBLE (a warning that blocks nothing and changes nothing): a step only probably before the work ("upon
 *     commencement", "on mobilization", "at the go-ahead", a percent "for materials"), a bare label with a dollar figure
 *     ("Signing: $1,234.50"), a holding fee, earnest money, a non-refundable or partial payment, what a later payment
 *     implies ("Remaining 50% due upon completion"), a part of the price when the goods arrive at the home, and any
 *     clause with an advance word, a payment word and an amount over the limit that no shape binds. A clause a pattern
 *     fails on (the backtrack limit) is listed as possible, never emptied, and only the failure's code is logged.
 *   NEVER an ask: a promotion ("Save 20% when you order"), a denied or waived one ("No deposit required, 100% due at
 *     install"), a product attribute's or a fee's percent ("4% processing fee"), a size or an address ("1200 Deposit
 *     Way"), a record of a payment ("Deposit of $1,000 received 10/1", "Thanks for the $2,500 deposit"), a payment at
 *     or after completion ("100% due upon completion", "due 30 days after completion"), a threshold ("orders over
 *     $1,500"), a tax's rate, a refund promise, the business paying ("We pay 100% on shipping").
 *   The app's own sentence (ZEST_Deposit_Limit::lawful_sentence) is read as one unit and measured against today's price.
 *   Over the limit: see ZEST_Deposit_Limit::over(). Price unknown (0): only what is over at any price.
 *
 * THE FLOOR. find() also reads every text with ZEST_Deposit_Floor, the plain reader (an amount next to an advance
 *   word), and never finds less than it, except where floor_lawful() names a lawful shape (a product's split, a part
 *   number, a refund promise, a product's positions, a payment after the work) and the floor, reading the clause again
 *   with only that shape's figures blanked, finds nothing. So a narrowing reading here can only take back what the
 *   fuller reading itself added.
 *
 * FIXING (fix, for the preview and then the write). Only sure asks, and only where the app understands the clause:
 *   - a clause that is nothing but payment talk (a figure that is not a percent or a dollar amount is a fact, so
 *     "Order #1001 - 50% deposit" is not) is replaced WHOLE, by the app's sentence the first time (when the price is
 *     known and the document has no sentence yet), by nothing after that; a balance clause right after it goes too;
 *   - a clause that also says something else loses only its payment parts, cut at commas, dashes, semicolons and
 *     brackets ("Motor: Model 4, 50% deposit, white housing" -> "Motor: Model 4, white housing"), never inside a
 *     bracket or a quote; the sentence then goes at the end;
 *   - anything else is left for a person ("change by hand"). Words are never cut from inside a phrase, and spacing is
 *     touched only where something was taken out. Fixing twice changes nothing; words with no sure ask come back byte
 *     for byte.
 *
 * DOCUMENTS (billing-system shape: notes, terms, lines { name, description, qty, unit_cost { amount } }, deposit
 *   fields, payment_schedule). doc_findings() reads notes, terms, each line, a priced deposit charge (a line whose NAME
 *   is the deposit), the billing system's own deposit request and an invoice's payment schedule. fix_doc() changes
 *   notes, terms and the words of $0 lines of ESTIMATES only: a priced line is the customer's bill, the billing
 *   system's deposit request is its own, and an invoice's notes can be a record of what was paid. The price leaves out
 *   priced deposit charges and credits for money already paid ("Less deposit received"); a line that reads as a
 *   discount is a discount. An invoice with its own priced work is its own job; only a deposit invoice is measured
 *   against the estimate it was made from.
 *
 * WORDS. What a business sells changes a few readings (the goods that ship or arrive, the product words beside which
 *   "50/50" is a split of the product, the attributes measured in percent): ZEST_Deposit_Limit::words(). Core reads
 *   generic words only.
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
require_once __DIR__ . '/class-zest-deposit-limit.php';
require_once __DIR__ . '/class-zest-deposit-floor.php';

final class ZEST_Deposit_Rule {

	const UNREAD = "this part couldn't be read; check it by hand";

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
		$usd  = '\$\s?(?:\d{1,3}(?:,\d{3})+|\d+)(?:\.\d{1,2})?(?!\d|,\d)';
		$bare = '(?:\d{1,3}(?:,\d{3})+|\d{3,6})(?:\.\d{1,2})?(?![\d\/%]|[.,]\d|-\d)(?!\s*(?:series|mm\b|cm\b|in\b|inch|ft\b|"|x\b|model|sq\b|lbs?\b))';
		$dep  = '(?:deposit|down\s*-?\s*payment|downpayment|down\b|retainer)';
		$adj  = '(?:\s+(?:non-?\s*refundable|refundable|initial|minimum|mandatory|required|standard|customary|materials?|product|order))*';
		$after_dep = '(?:\s*$|\s*[.,;:!)]|\s+(?:required|due|is|to|at|upon|on|when|before|needed|please|payment|now|of|for)\b)';

		$done = '(?!\s+(?:of|by)\s+(?:the\s+)?(?:completed|finished|final|work|job|install\w*|inspection|walk-?\s*through))';
		$when = 'to\s+(?:start|begin|order|book|reserve|secure|schedule|hold|get\s+started|confirm|lock\s+in|place\s+(?:the\s+|your\s+)?order)\b'
			. '|(?:at|on|upon|with|before|prior\s+to|by)\s+(?:the\s+|your\s+|time\s+of\s+(?:the\s+)?)?(?:signing|signature|sign(?:ing)?\s*-?\s*off|order(?:ing|s)?\b|order\s+placement|placing\s+(?:the\s+|your\s+)?order|acceptance' . $done . '|approval' . $done . '|booking|scheduling|contract(?:\s+signing)?|start\b|sale\b|purchase\b|agreement\b)'
			. '|when\s+(?:you\s+|we\s+)?(?:sign|signed|order|ordered|accept|accepted|approve|approved|book|booked|schedule|scheduled|place\s+(?:the\s+|your\s+)?order)\b'
			. '|when\s+(?:the\s+|your\s+)?order\s+is\s+placed|once\s+(?:you\s+)?(?:sign|signed|approve|approved|accept|accepted|order|ordered)\b'
			. '|(?:before|prior\s+to)\s+(?:we\s+)?(?:order(?:ing)?|start(?:ing)?|begin(?:ning)?|schedul(?:e|ing)|install(?:ation|ing)?|work\s+(?:starts|begins))\b';
		$when_old = $when;
		$adv_old  = 'deposit|down\s*-?\s*payment|downpayment|down\b|retainer|pre-?\s*payment|advance(?:\s+payment)?\b|up\s*-?\s*front|upfront|in\s+advance|now\b|today\b|immediately|at\s+once|' . $when_old;
		$home     = '(?:at|to)\s+(?:your|the|their|his|her)\s+(?:home|house|residence|property)\b';
		$nothome  = '(?!\s+' . $home . ')';

		$when .= '|(?:when|once|after|as\s+soon\s+as)\s+(?:the\s+|your\s+|this\s+|our\s+)?(?:estimate|contract|agreement|proposal|quote|order)\s+(?:is|are|has\s+been|have\s+been|gets?)\s+(?:signed|approved|accepted|ordered|placed|executed|booked)\b' . $done . '(?!\s+in\s+(?:service|operation|use)\b)'
			. '|(?:when|once|after|as\s+soon\s+as)\s+(?:the\s+|your\s+|this\s+|our\s+)?(?:materials?|products?|' . $goods . ')\s+(?:is|are|has\s+been|have\s+been|gets?)\s+(?:ordered|placed)\b'
			. '|when\s+(?:the\s+)?(?:contract|estimate|agreement|proposal)\s+(?:is\s+)?signed\b'

			. '|(?:after|following)\s+(?:the\s+|your\s+)?(?:(?:estimate|contract|order)\s+)?(?:approval|signing|signature|acceptance|contract\s+signing)(?:\s+of\s+(?:the\s+|this\s+|your\s+)?(?:estimate|contract|agreement|proposal|quote|order))?\b(?!\s+(?:of|by|off|the)\b)'
			. '|after\s+(?:you|we)\s+sign\b(?!\s+(?:off|the)\b)'
			. '|(?:at|on|upon|with)\s+(?:the\s+|a\s+|your\s+)?signed\s+(?:contract|estimate|agreement|proposal|quote|order)\b'
			. '|(?:on|upon)\s+(?:receipt|return)\s+of\s+(?:the\s+|a\s+|your\s+)?signed\s+(?:contract|estimate|agreement|proposal|quote)\b'
			. '|when\s+you\s+return\s+(?:the\s+|a\s+|your\s+)?signed\s+(?:contract|estimate|agreement|proposal|quote)\b'
			. '|(?:at|on|upon|with)\s+(?:the\s+|your\s+)?(?:estimate|customer|contract|proposal|quote|order)\s+(?:approval|acceptance|signing|signature)\b' . $done
			. '|(?:at|on|upon|with)\s+(?:the\s+)?(?:(?:contract|agreement)\s+)?execution\b'
			. '|(?:at|on|upon)\s+(?:the\s+)?(?:time\s+of\s+(?:the\s+)?)?(?:final\s+)?measure(?:ment|ments|ing)?\b'
			. '|(?:before|prior\s+to|ahead\s+of)\s+(?:we\s+|you\s+)?(?:place\s+(?:the\s+|your\s+|an\s+)?order|get\s+started|start(?:ing)?|begin(?:ning)?)\b'
			. '|(?:before|prior\s+to|ahead\s+of)\s+(?:the\s+|your\s+|any\s+)?(?:install(?:ation|ing)?(?:\s+date)?|production|manufactur(?:e|ing)|fabrication|work)\b'
			. '|(?:before|prior\s+to)\s+(?:the\s+|your\s+|any\s+)?(?:materials?|products?|' . $goods . ')\s+(?:is|are)\s+(?:ordered|placed|made|built)\b'
			. '|beforehand\b'
			. '|to\s+(?:move\s+forward|proceed|go\s+ahead|get\s+going|get\s+(?:you\s+)?(?:on|onto)\s+(?:the\s+|our\s+)?(?:schedule|calendar))\b'
			. '|when\s+(?:you\'re\s+|you\s+are\s+|you\s+re\s+)?ready\s+to\s+(?:order|sign|start|proceed|book|move\s+forward|go\s+ahead)\b'
			. '|(?:secures?|holds?|reserves?|locks?\s+in|guarantees?)\s+(?:your\s+|the\s+|an?\s+)?(?:order|install(?:ation)?(?:\s+date)?|date|spot|slot|appointment|place\s+in\s+line)\b'

			. '|(?:when|once|after|as\s+soon\s+as)\s+(?:all\s+(?:of\s+)?)?(?:the\s+|your\s+|our\s+)?(?:[a-z]+\s+)?(?:products?|orders?|materials?|parts?|items?|goods|' . $goods . ')\s+(?:ships?|shipped|arrives?|arrived|(?:is|are|has\s+been|have\s+been|gets?|get)\s+(?:shipped|received|delivered\s+to\s+(?:our|the)\s+(?:shop|warehouse|office|showroom))|comes?\s+in)\b' . $nothome
			. '|(?:when|once|as\s+soon\s+as)\s+(?:it\s+is\s+|they\s+are\s+)?shipped\b'
			. '|(?:on|upon|at)\s+(?:the\s+)?(?:time\s+of\s+)?(?:shipment|shipping)\b'
			. '|(?:on|upon)\s+(?:the\s+)?(?:arrival|receipt)\s+of\s+(?:the\s+|your\s+)?(?:[a-z]+\s+)?(?:materials?|products?|goods|parts?|items?|' . $goods . ')\b' . $nothome;
		$adv  = '(?:deposit|down\s*-?\s*payment|downpayment|down\b(?!\s*[:=])|retainer|pre-?\s*payment|advance(?:\s+payment)?\b|up\s*-?\s*front|upfront|in\s+advance|now\b|today\b|immediately|at\s+once|'
			. 'dep(?=\s*[,.;:)]|\s*$|\s+(?:bal|balance|due|required|at|upon|to|now)\b)|(?:booking|reservation)\s+fees?\b|' . $when . ')';

		$money = '(?:\d{1,3}(?:,\d{3})+(?:\.\d{2})?|\d+\.\d{2})(?![\d%\/])';

		$full = '(?<=\bpay\s|\bpaid\s|\bpayment\s|\bpayable\s|\bdue\s)(?:in\s+full|(?:the\s+)?(?:full|entire|total)\s+(?:amount|price|balance|cost))\b'
			. '|(?:the\s+)?(?:full|entire|total)\s+(?:amount|price|balance|cost)\b(?=\s+(?:is\s+|are\s+|will\s+be\s+)?(?:due|payable|required)\b)'
			. '|\bfull\s+payment\b';
		$moneyat = $money . '(?=\s+(?:due\s+)?(?:at|upon|on|with)\s+(?:the\s+)?(?:time\s+of\s+(?:the\s+)?)?(?:signing|signature|order(?:ing)?|acceptance|approval|booking|contract)\b)';
		$strong = '(?:deposits?|down\s*-?\s*payments?|downpayments?|retainers?|pre-?\s*pay(?:ment)?|advance\s+payment|advance\s*:|dep\s*[.:]|initial\s+(?:payment|installment)|first\s+(?:payment|installment)|1st\s+(?:payment|installment)'
			. '|down\s+(?:pmt|pymt)|(?:booking|reservation)\s+fees?'
			. '|(?:at|upon|on|with)\s+(?:the\s+time\s+of\s+)?(?:signing|order(?:ing)?|acceptance|approval|booking|contract(?:\s+signing)?)'
			. '|payment\s+(?:due\s+)?(?:at|upon|on|with)\s+(?:signing|order(?:ing)?|acceptance|approval|booking)'
			. '|due\s+(?:at|upon|on|with|before)\s+(?:signing|order(?:ing)?|acceptance|approval|booking|contract(?:\s+signing)?|start)'
			. '|due\s+(?:now|today|immediately)'
			. '|due\s+(?:before|prior\s+to)\s+(?:the\s+)?(?:production|manufactur(?:e|ing)|fabrication|install(?:ation)?|work\s+(?:starts|begins))'
			. '|(?:required|needed|requested|due|payable)\s+(?:to\s+(?:start|order|book|begin|get\s+started)|up\s*-?\s*front|in\s+advance)'
			. '|to\s+(?:start|get\s+started|begin|order|book)\s*[:=]'
			. '|up\s*-?\s*front(?:\s+(?:payment|deposit|cost|amount))?|upfront(?:\s+(?:payment|deposit|cost|amount))?|in\s+advance)';
		$fill = '(?:\s*(?:[(:=\/-]|\)|(?:0?[1-9]|1[0-2])[\/-](?:0?[1-9]|[12]\d|3[01])(?:[\/-]\d{2,4})?(?![\d\/%-])|(?:of|is|are|amount|required|requested|needed|due|payable|will\s+be|must\s+be|to\s+be|shall\s+be|approximately|approx|about|only|just|equal\s+to|equals|in\s+the\s+amount\s+of|the\s+sum\s+of|a|an|totaling|totalling|for|we\s+require|we\s+need|we\s+ask\s+for|please|pay|paid|collect|collected|non-?\s*refundable|refundable|at\s+signing|at\s+order|upon\s+signing|upon\s+order(?:ing)?|on\s+order|to\s+(?:start|order|book|begin|get\s+started|schedule)|(?:before|prior\s+to)\s+(?:we\s+)?(?:order(?:ing)?|start(?:ing)?))\b\.?)\s*){0,6}';
		self::$re = array(
			'when'    => $when,
			'when_old' => $when_old,

			'p1'      => '/(?<![\w$.,\/])(' . $pct . '|' . $usd . '|\bhalf\b|' . $full . '|' . $moneyat . '|' . $bare . '(?=' . $adj . '\s*' . $dep . $after_dep . '))'
				. '(?:\s*\(?\s*(?:of\s+)?(?:the\s+|your\s+)?(?:total|price|job|contract(?:\s+(?:price|amount|total))?|amount|estimate|invoice|project|order|cost)\s*\)?)?'
				. '(?:(?<=%)\))?' . $adj . '(?:\s+(?:payment|installment))?(?:\s*,(?=\s*(?:is|are|will|must|shall|required|due|payable)\b))?(?:\s*(?:is|are|will\s+be|must\s+be|to\s+be|shall\s+be))?(?:\s*(?:required|needed|requested|due|payable|paid|collected|expected))?'
				. '\s*[-:,]?\s*(?<adv>' . $adv . ')/',

			'advold'  => '/^(?:' . $adv_old . ')/',

			'p2'      => '/\b' . $strong . '(?![a-z])' . $fill . '\s*(' . $pct . '|' . $usd . '|\bhalf\b|' . $bare . ')/',

			'p2step'  => '/\b(?:deposits?|down\s*-?\s*payments?|downpayments?|down\s+(?:pmt|pymt)|retainers?|pre-?\s*payments?|advance\s+payment|(?:initial|first|1st)\s+(?:payment|installment))(?![a-z])'
				. '\s*[,\/-]?\s*\(?\s*(?:amount\s+)?(?:(?:(?:is|will\s+be|to\s+be|must\s+be|shall\s+be)\s+(?:paid|collected)|(?:(?:is|will\s+be)\s+)?(?:due|payable|required|needed|requested))\s+)?'
				. '(?!(?:when|once)\s+(?:you|we)\s+(?:signed|ordered|accepted|approved|booked|scheduled)\b)(?:' . $when . '|when\s+(?:signing|ordering|booking)\b)'

				. '(?:\s+(?:(?:of|the|your|this|our|an?|all|signed|custom|new)\s+){0,3}(?:contract|estimate|agreement|proposal|quote|order|paperwork|spot|slot|(?:install(?:ation)?\s+)?date|appointment|products?|materials?|' . $goods . '|job|work|project)'
				. '|(?<!start)(?<!begin)\s+(?:(?:the|your)\s+)?install(?:ation)?\b)?(?:\s+(?:begins|starts|is\s+placed|today))?'
				. '(?:\s*(?:,\s*|\(\s*)(?:non-?\s*refundable|refundable|required)\b\s*\)?)?'

				. '\s*\)?\s*[:=\/-]' . $fill . '\s*(' . $pct . '|' . $usd . '|\bhalf\b|' . $money . '(?!\s*(?:series|mm\b|cm\b|in\b|inch|ft\b|"|x\b|model|sq\b|lbs?\b)))/',

			'p2cap'   => '/\b(?:whichever\s+is\s+(?:less|lower|smaller|lesser)|not\s+to\s+exceed|(?:no|not|never)\s+more\s+than|does\s+not\s+exceed|not\s+exceeding|max(?:imum)?|up\s+to|capped\s+(?:at|to)|a\s+cap\s+of|at\s+most|(?:the\s+)?lesser\s+of|or\s+less|if\s+less|limit(?:ed)?\s+(?:to|of))\b/',

			'p2rec'   => '/^(?:(?!\b(?:balance|remain\w*|rest|remainder|final|second|2nd|then|and|plus)\b|\$\s?\d|\d%)[^.;])*?\b(?:waived?|not\s+(?:required|needed|due|charged)|no\s+charge|(?<!upon\s)(?<!on\s)receipts?|(?<!is\s)(?<!are\s)(?<!been\s)(?<!get\s)(?<!gets\s)received|rec\'?d|paid|collected|credit(?:ed|s)?|refund(?:ed|s)?|returned|applied|thanks?|off|save[sd]?|savings?|discount(?:ed)?)\b/',

			'downlab' => '/(?:(?<![a-z-])(?:amount\s+)?down(?:\s+(?:pmt|pymt|payment))?\s*[:=]\s*'
				. '|(?:^|[,;:]\s*|\s-\s+|\b(?:terms|payments?|schedule)\s*:\s*)(?:(?:[-*]|\xE2\x80\xA2)\s*)?(?:amount\s+)?down(?:\s+(?:pmt|pymt|payment))?(?:\s*(?:\/|-(?!\s*(?:the|to|from)\b))\s*|\s+(?=\S+\s*(?:[.!,;]|$))))'
				. '(' . $pct . '|' . $usd . '|\bhalf\b|' . $money . ')/',

			'splitsaid' => '/\d{1,3}\s*%?\s*[\/-]\s*\d{1,3}\s*%?\s+split\s*(?:payments?\b|[.,;!]|$)/',

			'soft'    => '/(?<![\w$.,\/])(?<amt>' . $pct . '|' . $usd . '|\bhalf\b|' . $full . ')(?:\s+(?:payment|is|are|due|payable))*\s*[-:,]?\s*'
				. '(?:(?:at|on|upon|with)\s+(?:the\s+)?(?:commencement|mobili[sz]ation|go\s*-?\s*ahead)\b'

				. '|(?:when|once)\s+(?:the\s+|your\s+|our\s+)?(?:[a-z]+\s+)?(?:products?|orders?|materials?|parts?|items?|' . $goods . ')\s+(?:is|are)\s+ready\b(?!\s+(?:to\s+(?:use|go|operate)|for\s+use))'

				. '|(?:on|upon)\s+(?:the\s+)?receipt\s+of\s+(?:the\s+|your\s+)?order\b'
				. ')/',

			'home'    => '/(?<![\w$.,\/])(?<amt>' . $pct . '|' . $usd . '|\bhalf\b|' . $full . ')(?:\s+(?:payment|is|are|due|payable|required))*\s*[-:,]?\s*'
				. '(?:(?:when|once|after|as\s+soon\s+as)\s+(?:all\s+(?:of\s+)?)?(?:the\s+|your\s+|our\s+)?(?:[a-z]+\s+)?(?:products?|orders?|materials?|parts?|items?|goods|' . $goods . ')\s+(?:arrives?|arrived|(?:is|are|has\s+been|have\s+been|gets?|get)\s+(?:delivered|received))\s+'
				. '|(?:on|upon|at)\s+(?:the\s+)?(?:delivery|arrival|receipt)\s+(?:of\s+(?:the\s+|your\s+)?(?:[a-z]+\s+)?(?:materials?|products?|goods|parts?|items?|' . $goods . ')\s+)?)' . $home . '/',
			'softpct' => '/(?<![\w$.,\/])(?<amt>' . $pct . '|\bhalf\b)(?:\s+(?:payment|is|are|due|payable|required|needed))*\s*[-:,]?\s*'
				. '(?:this\s+week\b|for\s+(?:the\s+)?materials?\b|for\s+(?:all\s+)?(?:custom\s+|special\s+)?orders?\b)/',
			'lab1'    => '/(?:^|(?<!\w)-\s*|[*:\/]\s*|\xE2\x80\xA2\s*)(?<lab>signing|signature|order(?:ing)?|approval|acceptance|booking|contract|mobili[sz]ation|commencement|materials?)\s*(?:[:=\/]|-)\s*(?<amt>' . $pct . '|' . $usd . '|\bhalf\b|' . $money . ')/',
			'lab2'    => '/(?<![\w$.,\/])(?<amt>' . $pct . '|' . $usd . '|\bhalf\b)\s*(?:-|:)\s*(?<lab>signing|signature|order(?:ing)?|approval|acceptance|booking|contract|mobili[sz]ation|materials?)\s*(?=$|[.,;\/])/',
			'fee'     => '/\b(?:holding\s+fees?|good[\s-]*faith\s+(?:payments?|money|fees?)|earnest\s+(?:money|payments?)|(?:non-?\s*refundable|partial)\s+payment)\s*(?:of\s+|[:=-]\s*|is\s+)?(?<amt>' . $pct . '|' . $usd . '|\bhalf\b)'
				. '|(?<![\w$.,\/])(?<amt2>' . $pct . '|' . $usd . ')\s+(?:non-?\s*refundable\s+)?(?:holding\s+fee|good[\s-]*faith\s+(?:payment|money)|earnest\s+money)\b'
				. '|(?:^|[-*:;,]\s*)dp\s*(?:[:=-]\s*|\s+)(?<amt3>' . $usd . ')/',

			'alone'   => '/^\s*(?:$|[.,;)]|[\/-]\s*(?:[a-z][a-z ]*?\s*[:=-]|\d{1,3}(?:\.\d+)?%\s*(?:$|[.,;]|(?:(?:is\s+)?(?:due|payable)\s+)?(?:at|upon|on|after|when|once|by|before|with|to)\b)|(?:the\s+)?(?:balance|remainder|rest)\b|$)'
				. '|(?:and|&)\s+(?:install(?:ation)?|completion|delivery|balance|final|signing|order|approval|booking|contract)\s*[:=-]\s*\d'
				. '|\(\s*(?:non-?\s*refundable|refundable|deposit|down(?:\s+payment)?)\s*\)'
				. '|(?:(?:is\s+)?(?:due|payable|required|needed)|payment|deposit|down)?\s*(?:$|[.,;)]|(?:' . $when . '))'
				. '|of\s+(?:the\s+)?(?:total|price|contract|job|amount|estimate|order|cost|project|invoice)\b(?:\s+(?:is\s+)?(?:due|payable|required))?(?:\s*(?:$|[.,;\/)])|\s+(?:(?:is\s+)?(?:due|payable)\s+)?(?:' . $when . ')))/',

			'later'   => '/^\s*(?:,\s*)?(?:(?:is|are|will\s+be)\s+)?(?:due|payable|paid)?\s*(?:(?:\d+|an?|one|two|three|four|five|six|seven|eight|nine|ten|fourteen|fifteen|twenty|thirty|forty-five|sixty|ninety)\s+(?:days?|weeks?|months?)\s+)?(?:(?:at|upon|on|after|following|by|when|once)\s+(?:the\s+)?(?:time\s+of\s+|day\s+of\s+|date\s+of\s+)?(?:completion|complete|completed|install(?:ation)?|installed|finish(?:ed)?|final\s+(?:inspection|walk-?\s*through)|job\s+completion|delivery)\b|(?:when|once)\s+(?:it\s+is\s+|they\s+are\s+|the\s+(?:job|work|install(?:ation)?)\s+is\s+)?(?:installed|complete|completed|done|finished)\b|on\s+install\s+day\b)/',

			'priceword' => '/\b(?:total|price|subtotal|estimate|estimated(?:\s+total)?|quote|quoted|cost|amount\s+due)\s*(?:of|is|:|=|-)?\s*$'
				. '|\bprice\s+(?:for|of|per)\s+(?:[\w#.-]+\s+){0,4}?[\w#.-]+\s*[:=-]\s*$|\bprice\s+(?:drops?|falls?|is\s+reduced|goes\s+down)\s+to\s*$/',

			'netof'   => '/\b(?:after|net\s+of|less)\s+(?:the\s+|your\s+|any\s+|all\s+)?(?:discounts?|savings|rebates?)\s*[:,=-]?\s*$/',

			'paidlater' => '/^\s*\(?\s*[,;:-]?\s*(?:(?:all\s+of\s+it|all|it|which|this|the\s+(?:full|whole|entire)\s+(?:amount|price|sum))\s+(?:is\s+|will\s+be\s+|to\s+be\s+)?)?(?:payable|due|paid|you\s+pay(?:\s+it)?)\s+(?:in\s+full\s+)?(?:at|upon|on|after|when|once|until)\s+(?:the\s+)?(?:time\s+of\s+)?(?:completion|install(?:ation)?|final\s+inspection|job\s+completion)\b/',

			'pricebound' => '/\b(?:deposits?|down(?:\s*-?\s*payment)?|half|balance|remainder|the\s+rest)\b|\b(?:remaining|final|last|second|2nd|other)\s+(?:payment|installment|invoice|half|\d|\$)'
				. '|\b(?:next|first|1st|initial)\s+(?:payment|installment|invoice)\b|\b(?:and|plus|then|with)\s+(?:another\s+|the\s+other\s+)?\$\s?\d'
				. '|\b(?:all|it)\s+(?:is\s+)?(?:due|payable)\b|\b(?:due|payable|required)\s+(?:' . $when . ')/',

			'notask'  => '/\b(?:discount(?:ed)?|off|save[sd]?|saves|savings|retail|msrp|list\s+price|(?:orders?|purchases?|projects?|jobs?|spend(?:ing)?|totals?)\s+(?:of\s+)?(?:over|above|under|more\s+than|less\s+than)|(?:save[sd]?|saves|savings?)\s+(?:over|up\s+to|more\s+than|at\s+least))\s*(?:of|is|:|=|-)?\s*$/',

			'feeword' => '/\b(?:fees?|charges?|surcharges?)\s*(?:of|is|:|=|-)?\s*$/',

			'negated' => '/\b(?:no|not|never|don\'t|dont|doesn\'t|without|won\'t)\s+(?:(?:ever|usually|always|need|needs|to|ask|asks|for|require|requires|any|an?|the|you|we|us)\s+){0,3}?(?:require[sd]?|ask(?:s|ed)?(?:\s+for)?|need(?:s|ed)?(?:\s+to\s+pay)?|charge[sd]?|collect(?:s|ed)?|expect(?:s|ed)?|take[sn]?|pay|payment|demand(?:s|ed)?)\s+(?:an?\s+|the\s+|any\s+)?$/',

			'wepay'   => '/\bwe\s+(?:pay|cover)\s+(?:for\s+)?$/',

			'deniedp1' => '/\b(?:no|without|zero|waive[sd]?|free)\s+(?:the\s+|an?\s+|our\s+|your\s+|of\s+)?$/',

			'product' => '/\b(?:doors?|panels?|fixed|left|right|rails?|windows?|configuration|config|blocks?|model|part|double|single|bars?|white|bronze|black|brown|beige|gr[ae]y|charcoal|ivory|tan|sand|almond|' . $goods . ')\b/',

			'implied' => '/\b(?:(?:remaining|final|last|second|2nd|other)\s+(?:(?:payment|installment)\s*(?:of\s+|[:=-]\s*)?)?|balance\s*(?:of\s+|[:=-]\s*|due\s*[:=-]?\s*))(\d{1,3}(?:\.\d+)?)%/',

			'denied'  => '/\b(?:no|without|zero|waived?|not)\s+(?:an?\s+)?$/',

			'restate' => '/' . $pct . '\s*(?:=|is)\s*\$?\s?((?:\d{1,3}(?:,\d{3})+|\d+)(?:\.\d{1,2})?)/',

			'split'   => '/(?<![\d.\/$#])(\d{1,3})\s*%?\s*\/\s*(\d{1,3})\s*%?(?:\s*\/\s*(\d{1,3})\s*%?)?(?:\s*\/\s*(\d{1,3})\s*%?)?(?![\d\/])/',
			'halves'  => '/\bhalf\s+(?:and|&|\/)\s+half\b/',
			'dash'    => '/(?<![\d.\/$#-])(\d{1,3})\s*%?\s*-\s*(\d{1,3})\s*%?(?![\d\/-])/',
			'payword' => '/\b(?:pay|pays|paid|payment|payments|payable|deposits?|balance|terms|installments?|retainer(?!\s+(?:bars?|strips?|clips?|channels?|rails?|' . $goods . '))|down\s*-?\s*payment|downpayment|billing|billed)\b|\bdue\b(?!\s+to\b)/',
			'advany'  => '/\b(?:deposits?|down\s*-?\s*payments?|downpayments?|retainers?|pre-?\s*pay\w*|up\s*-?\s*front|upfront|in\s+advance|advance\s+payment|initial\s+payment|first\s+payment|at\s+(?:the\s+time\s+of\s+)?signing|upon\s+signing|at\s+order|on\s+order|upon\s+order\w*|when\s+ordered|before\s+we\s+(?:order|start)|to\s+get\s+started|to\s+start)\b/',

			'disc'    => '/\b(?:save|saves|saving|savings|get|gets|take|takes|extra|additional|rebate|discount(?:ed|s)?|off|up\s+to|promo|promotion|sale|special|coupon|bonus|earn|reduce[sd]?|reduction|lower(?:s|ed)?|cuts?|block(?:s|ed|ing)?|raise[sd]?)\s*(?::|\bof\b|-)?\s*(?:an?\s+)?(?:extra\s+|additional\s+)?$/',

			'discby'  => '/\b(?:(?:reduce[sd]?|lower(?:s|ed)?|cuts?|drop(?:s|ped)?|raise[sd]?|increase[sd]?|discount(?:s|ed)?|mark(?:s|ed)?\s+down)(?:\s+[\w-]+){0,3}|save[sd]?(?:\s+on(?:\s+[\w-]+){0,3})?|off|up\s+to)\s+by\s*(?::|\bof\b|-)?\s*(?:an?\s+)?(?:extra\s+|additional\s+)?$/',
			'notpct'  => '/^\s*(?:off|discount|fee|surcharge|processing|card|credit|tax|interest|apr|finance|open\b|block\b|uv|visibility|privacy|' . $rate . '|more|less|of\s+(?:the\s+)?(?:light|heat|sun|uv|glare|windows?|gates?|doors?|time|' . $goods . '))/',
			'notusd'  => '/\b(?:total|balance|remaining|remainder|final|price|subtotal|amount\s+due|estimate|estimated|invoice|value|cost|fee|tax|discount|off|save|saves|savings|retail|msrp|list|quote|quoted|over|under|above|shipping|freight)\s*(?:of|is|:|=|-)?\s*$/',

			'record'  => '/\b(?:received|rec\'?d|credited|(?:was|were|has\s+been|have\s+been|been|already|and)\s+applied\s+to|applied\s+to\s+(?:invoice|inv\.?|bill|statement)\s*(?:#|no\.?|number)?\s*\d+|on\s+file|thanks?(?:\s+you)?\s+for\s+(?:your\s+|the\s+)?(?:\$[\d,.]+\s+)?(?:deposit|payment)|(?:was|were|has\s+been|have\s+been|already)\s+paid|check\s*(?:#|no\.?|number)\s*\d|cheque|ck\s*#\s*\d|refunded)\b'

				. '|(?<!\bbe\s)\b(?:paid|collected)\b(?!\s+(?:at|upon|on\s+(?:signing|order|acceptance|approval)|before|when|prior|in\s+advance|up\s*-?\s*front|to\s+(?:start|order|book|begin)|by\s+(?:the\s+)?(?:signing|order)))'
				. '|^\s*paid\s+(?:\$|\d)'
				. '|\(\s*applied\s+to\b(?!\s+(?:your|the)\s+(?:order|total|balance|purchase|final|job|project|contract)\b)'
				. '|\$[\d,.]*\d\s+(?:deposit\s+|payment\s+)?applied\s+to\b(?![^.;]*\b(?:due|payable|required)\s+(?:' . $when . '))/',
		);
		return self::$re;
	}

	private static function norm( string $s ): string {
		$s = str_replace(
			array( "\xC2\xA0", "\xE2\x80\xAF", "\xE2\x80\x89", "\xEF\xBC\x85", "\xE2\x80\x93", "\xE2\x80\x94", "\xE2\x88\x92", "\xE2\x80\x98", "\xE2\x80\x99", "\xE2\x80\x9C", "\xE2\x80\x9D",

				"\xE2\x80\x90", "\xE2\x80\x91", "\xE2\x80\x92", "\xE2\x80\x95", "\xC2\xB7", "\xE2\x96\xAA", "\xE2\x96\xAB", "\xE2\x97\xA6", "\xE2\x80\xA3", "\xE2\x81\x83" ),
			array( ' ', ' ', ' ', '%', '-', '-', '-', "'", "'", '"', '"', '-', '-', '-', '-', "\xE2\x80\xA2", "\xE2\x80\xA2", "\xE2\x80\xA2", "\xE2\x80\xA2", "\xE2\x80\xA2", "\xE2\x80\xA2" ),
			$s
		);
		$s = strtolower( self::pr( '/\s+/', ' ', $s ) );

		$s = self::pr( '/\b((?:on|by|dated?|install(?:ed|ation|s|ing)?|ready|scheduled|until|till|before|after|from|starting|starts?|thru|through|week\s+of|deliver(?:y|ed)?|pick\s*-?\s*up|measure(?:d|ment)?|appointment|begins?)\s*:?\s*)(0?[1-9]|1[0-2])\/(0?[1-9]|[12]\d|3[01])(?![\d\/])'
			. '(?!\s*(?:%|of\b|down\b|up\s*-?\s*front|upfront|in\s+advance|(?:is\s+)?(?:due|payable|required|needed)\b(?!\s+to\b)|' . self::re()['when_old'] . '))/', '$1$2-$3', $s );
		$s = str_replace( array( "\xC2\xBD", "\xC2\xBC", "\xC2\xBE", "\xE2\x85\x93" ), array( ' 1/2 ', ' 1/4 ', ' 3/4 ', ' 1/3 ' ), $s );
		$s = self::pr( '/\b(?:usd|us\$)\s*\$?\s*(?=\d)/', '$', $s );

		$s = self::number_words( $s );
		$s = self::pr( '/\b(\d[\d,]*(?:\.\d{1,2})?)\s*(?:dollars|bucks)\b/', '\$$1', $s );
		$s = self::prc( '/\$\s?(\d{1,3}(?:\.\d{1,2})?)\s?k\b/', static function ( $m ) { return '$' . (string) (int) round( (float) $m[1] * 1000 ); }, $s );

		$s = self::prc( '/\$\s?((?:\d{1,3}(?:,\d{3})+|\d+)(?:\.\d{1,2})?)\s*\(\s*\$\s?((?:\d{1,3}(?:,\d{3})+|\d+)(?:\.\d{1,2})?)\s*\)/', static function ( $m ) {
			return abs( (float) str_replace( ',', '', $m[1] ) - (float) str_replace( ',', '', $m[2] ) ) < 0.005 ? '$' . $m[1] : $m[0];
		}, $s );
		$s = self::pr( '/\bin-full\b/', 'in full', $s );
		$s = self::pr( '/\s*\b(?:per\s*cent|percent|pct)\b\.?/', '%', $s );
		$s = self::pr( '/\bfifty[- ]fifty\b/', '50/50', $s );

		$s = self::prc( '/\b(twenty|thirty|forty|fifty|sixty|seventy|eighty|ninety)[- ](one|two|three|four|five|six|seven|eight|nine)\s*%/', static function ( $m ) {
			$tens  = array( 'twenty' => 20, 'thirty' => 30, 'forty' => 40, 'fifty' => 50, 'sixty' => 60, 'seventy' => 70, 'eighty' => 80, 'ninety' => 90 );
			$units = array( 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9 );
			return ( $tens[ $m[1] ] + $units[ $m[2] ] ) . '%';
		}, $s );
		foreach ( array( 'one hundred' => 100, 'seventy[- ]five' => 75, 'sixty[- ]six' => 66, 'forty[- ]five' => 45, 'thirty[- ]three' => 33,
			'twenty[- ]five' => 25, 'ninety' => 90, 'eighty' => 80, 'seventy' => 70, 'sixty' => 60, 'fifty' => 50, 'forty' => 40,
			'thirty' => 30, 'twenty' => 20, 'fifteen' => 15, 'ten' => 10, 'five' => 5 ) as $w => $n ) {
			$s = self::pr( '/\b' . $w . '\s*%/', $n . '%', $s );
		}
		$size = '(?![\d\/])(?!\s*(?:"|\'\'|\'|in\b(?!\s+advance)|inch|mm\b|cm\b|ft\b|x\b))';
		$s = self::pr( '/\b(?:one[- ]half|a\s+half|1\/2)' . $size . '/', 'half', $s );
		$s = self::pr( '/\b(?:two[- ]thirds|2\/3)' . $size . '/', '67%', $s );
		$s = self::pr( '/\b(?:one[- ]third|a\s+third|1\/3)' . $size . '/', '33%', $s );
		$s = self::pr( '/\b(?:three[- ]quarters|3\/4)' . $size . '/', '75%', $s );
		$s = self::pr( '/\b(?:one[- ]quarter|a\s+quarter|1\/4)' . $size . '/', '25%', $s );
		$s = self::pr( '/(\d)\s+%/', '$1%', $s );
		$s = self::pr( '/(?<![\d.])(\d{1,3}(?:\.\d+)?%)\s*\(\s*\1\s*\)/', '$1', $s );
		$s = self::pr( '/\bhalf\s*\(\s*50%\s*\)/', 'half', $s );
		$s = self::pr( '/\b[a-z]+(?:-[a-z]+)?\s*\(\s*(\d{1,3}(?:\.\d+)?%)\s*\)\s*%/', '$1', $s );
		return trim( self::pr( '/\s+/', ' ', $s ) );
	}

	private static function number_words( string $s ): string {
		if ( ! self::pma( '/\b(?:dollars|bucks)\b/', $s, $dm, PREG_OFFSET_CAPTURE ) ) {
			return $s;
		}
		$w = '(?:a|one|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve|thirteen|fourteen|fifteen|sixteen|seventeen|eighteen|nineteen|twenty|thirty|forty|fifty|sixty|seventy|eighty|ninety|hundred|thousand|half)';
		foreach ( array_reverse( $dm[0] ) as $d ) {
			$from = max( 0, $d[1] - 120 );
			$win  = substr( $s, $from, $d[1] + strlen( $d[0] ) - $from );
			if ( self::pm( '/\b(' . $w . '(?:(?:\s+and\s+|[\s-]+)' . $w . '){0,9})\s+(?:dollars|bucks)$/', $win, $x, PREG_OFFSET_CAPTURE ) ) {
				$v = self::words_value( $x[1][0] );
				if ( $v > 0 ) {
					$s = substr_replace( $s, '$' . $v, $from + $x[0][1], strlen( $x[0][0] ) );
				}
			}
		}
		return $s;
	}

	private static function words_value( string $words ): int {
		static $v = array( 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9,
			'ten' => 10, 'eleven' => 11, 'twelve' => 12, 'thirteen' => 13, 'fourteen' => 14, 'fifteen' => 15, 'sixteen' => 16, 'seventeen' => 17,
			'eighteen' => 18, 'nineteen' => 19, 'twenty' => 20, 'thirty' => 30, 'forty' => 40, 'fifty' => 50, 'sixty' => 60, 'seventy' => 70,
			'eighty' => 80, 'ninety' => 90 );
		$t = array_values( array_filter( (array) preg_split( '/[\s-]+/', $words ), static function ( $x ) { return 'and' !== $x && '' !== $x; } ) );
		if ( $t && 'a' === $t[0] ) {
			array_shift( $t );
		}
		$total = 0.0;
		$now   = 0.0;
		foreach ( $t as $i => $x ) {
			if ( isset( $v[ $x ] ) ) {

				$now = ( $v[ $x ] >= 10 && $now >= 10 && $now < 100 ) ? $now * 100 + $v[ $x ] : $now + $v[ $x ];
			} elseif ( 'half' === $x ) {
				$now += 0.5;
			} elseif ( 'a' === $x && 'half' === ( $t[ $i + 1 ] ?? '' ) ) {
				continue;
			} elseif ( 'hundred' === $x ) {
				$now = max( 1, $now ) * 100;
			} elseif ( 'thousand' === $x ) {
				$total += max( 1, $now ) * 1000;
				$now    = 0.0;
			} else {
				return 0;
			}
		}
		return (int) round( $total + $now );
	}

	private static $failed = '';
	private static function rx_failed(): void {
		if ( '' !== self::$failed ) {
			return;
		}
		$e     = preg_last_error();
		$codes = array( PREG_INTERNAL_ERROR => 'internal', PREG_BACKTRACK_LIMIT_ERROR => 'backtrack-limit', PREG_RECURSION_LIMIT_ERROR => 'recursion-limit',
			PREG_BAD_UTF8_ERROR => 'bad-utf8', PREG_BAD_UTF8_OFFSET_ERROR => 'bad-utf8-offset' );
		if ( defined( 'PREG_JIT_STACKLIMIT_ERROR' ) ) {
			$codes[ PREG_JIT_STACKLIMIT_ERROR ] = 'jit-stack';
		}
		self::$failed = $codes[ $e ] ?? ( 'pcre-' . $e );
	}
	private static function pm( string $re, string $s, &$m = null, int $flags = 0, int $offset = 0 ): int {
		$r = preg_match( $re, $s, $m, $flags, $offset );
		if ( false === $r ) {
			self::rx_failed();
			$m = array();
			return 0;
		}
		return $r;
	}
	private static function pma( string $re, string $s, &$m = null, int $flags = PREG_PATTERN_ORDER, int $offset = 0 ): int {
		$r = preg_match_all( $re, $s, $m, $flags, $offset );
		if ( false === $r ) {
			self::rx_failed();
			$m = array();
			return 0;
		}
		return $r;
	}
	private static function pr( $re, $to, string $s ): string {
		$r = preg_replace( $re, $to, $s );
		if ( null === $r ) {
			self::rx_failed();
			return $s;
		}
		return $r;
	}
	private static function prc( string $re, callable $fn, string $s, int $limit = -1, &$count = null, int $flags = 0 ): string {
		$r = preg_replace_callback( $re, $fn, $s, $limit, $count, $flags );
		if ( null === $r ) {
			self::rx_failed();
			return $s;
		}
		return $r;
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
		if ( self::pm( '/^(?:in\s+full|full\s+payment|(?:the\s+)?(?:full|entire|total)\s+(?:amount|price|balance|cost))$/', $a ) ) {
			return array( 100.0, 0 );
		}
		if ( '%' === substr( $a, -1 ) ) {
			return array( (float) $a, 0 );
		}
		return array( 0.0, self::cents_of( $a ) );
	}

	private static function promo( string $n, int $at, int $len ): bool {
		$R      = self::re();
		$before = substr( $n, max( 0, $at - 30 ), min( 30, $at ) );
		if ( self::pm( $R['netof'], $before ) || self::pm( '/\b(?:we|i)\s+(?:take|get|collect)\s*$/', $before ) ) {
			return false;
		}
		if ( self::pm( $R['disc'], $before ) ) {

			return ! ( self::pm( '/\b(?:sale|special)\s*[:-]?\s*$/', $before )
				&& self::pm( '/^\s*(?:down\b(?!\s+(?:to|from|the)\b)|deposits?\b|down\s*-?\s*payments?|downpayments?|retainers?\b)/', substr( $n, $at + $len, 30 ) ) );
		}
		$wide = substr( $n, max( 0, $at - 60 ), min( 60, $at ) );
		return self::pm( $R['discby'], $wide ) && ! self::pm( '/^\s*(?:deposits?|down\b|down\s*-?\s*payments?|downpayments?|retainers?)/', substr( $n, $at + $len, 30 ) );
	}

	private static function asks( string $n, bool $terms, bool $priced = false, ?array &$demoted = null ): array {
		$R       = self::re();
		$out     = array();
		$demoted = array();
		$pay     = (bool) self::pm( $R['payword'], $n );
		if ( self::pma( $R['p1'], $n, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[1] as $k => $hit ) {
				$span   = $m[0][ $k ][0];
				$before = substr( $n, max( 0, $hit[1] - 40 ), min( 40, $hit[1] ) );
				$usd    = '$' === $hit[0][0];
				$pct    = '%' === substr( $hit[0], -1 );
				$whole  = ! $usd && ! $pct && 'half' !== $hit[0] && ! ctype_digit( $hit[0][0] );
				if ( self::promo( $n, $hit[1], strlen( $hit[0] ) )

					|| self::pm( $R['deniedp1'], $before ) || self::pm( '/^\s*(?:is\s+|are\s+|will\s+be\s+)?waived\b/', substr( $n, $m[0][ $k ][1] + strlen( $span ), 30 ) )
					|| ( $usd && self::pm( $R['notask'], substr( $before, -30 ) ) && ! self::pm( $R['netof'], substr( $before, -30 ) ) )
					|| ( self::pm( '/(?<!non-)(?<!non)\brefundable\b/', $span ) && ! self::pm( '/\b(?:deposit|down|retainer|payment)\b/', $span ) )

					|| ( self::pm( $R['negated'], $before ) && ! self::pm( '/\b(?:if|unless|until)\b/', $before ) ) || self::pm( $R['wepay'], $before )
					|| ( $whole && self::pm( '/^[^$]*?(?:\bis\b|:|=|\bof\b)\s*(?:\$\s?\d|none\b|nothing\b|zero\b|n\/a\b)/', substr( $n, $hit[1] + strlen( $hit[0] ) ) ) )

					|| ( ! $usd && ! $pct && ctype_digit( $hit[0][0] ) && ! $pay && self::pm( $R['product'], substr( $before, -30 ) ) ) ) {
					continue;
				}
				$old = (bool) self::pm( $R['advold'], $m['adv'][ $k ][0] );
				if ( ( ! $usd && ! $pct && ctype_digit( $hit[0][0] ) && ! $pay ) || ( $usd && self::pm( $R['feeword'], substr( $before, -30 ) ) ) ) {

					$demoted[] = self::amount( $hit[0] );
					continue;
				}
				if ( $usd && ! self::pm( '/\b(?:due|payable|required|initial|first)\b/', $span )
					&& ( self::pm( '/^\$[\d,.\s]+(?:total|price|cost)\b/', $span ) || self::pm( $R['priceword'], $before ) ) ) {
					$rest = substr( $n, $m[0][ $k ][1] + strlen( $span ) );
					if ( self::pm( $R['paidlater'], $rest ) && ! self::pm( '/\$\s?\d/', $rest ) ) {
						continue;
					}
					if ( ! self::pm( $R['pricebound'], $rest ) ) {

						$demoted[] = array_merge( self::amount( $hit[0] ), array( $old ? 'old' : '' ) );
						continue;
					}
				}
				$out[] = self::amount( $hit[0] );
			}
		}
		if ( self::pma( $R['p2'], $n, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[1] as $k => $a ) {
				$at   = $m[0][ $k ][1];
				$tail = substr( $n, $a[1] + strlen( $a[0] ), 60 );
				$lead = $m[0][ $k ][0];
				if ( self::pm( $R['denied'], substr( $n, max( 0, $at - 20 ), min( 20, $at ) ) ) ) {
					continue;
				}
				if ( '%' === substr( $a[0], -1 ) && self::pm( $R['notpct'], $tail ) ) {
					continue;
				}
				if ( '$' !== $a[0][0] && '%' !== substr( $a[0], -1 ) && 'half' !== $a[0]
					&& self::pm( '/(?:0?[1-9]|1[0-2])[\/-](?:0?[1-9]|[12]\d|3[01])/', substr( $n, $at, $a[1] - $at ) ) ) {
					continue;
				}

				if ( ( ! self::pm( '/^(?:deposits?|down|downpayments?|retainers?|pre-?\s*pay|advance|dep\b|up\s*-?\s*front|upfront|in\s+advance)/', $lead ) && self::pm( $R['later'], $tail ) )
					|| self::pm( '/^\s*\(?\s*(?:is\s+)?waived\b/', $tail ) ) {
					continue;
				}
				$pre = substr( $n, max( 0, $at - 20 ), min( 20, $at ) );
				if ( '$' === $a[0][0] && ! self::pm( '/\b(?:due|payable|required|deposit|down|retainer)\b/', $lead )
					&& self::pm( '/\b(?:total|price|cost|quote|estimate|subtotal)(?:\s+(?:locked|guaranteed|held|fixed))?\s*$/', $pre ) ) {
					$rest = substr( $n, $a[1] + strlen( $a[0] ) );
					if ( self::pm( $R['paidlater'], $rest ) ) {
						continue;
					}
					if ( ! self::pm( $R['pricebound'], $rest ) ) {

						$demoted[] = array_merge( self::amount( $a[0] ), array( 'old' ) );
						continue;
					}
				}
				$out[] = self::amount( $a[0] );
			}
		}

		if ( ! self::pm( '/\b(?:check|ck)\s*(?:#|no\.?|number)\s*\d/', $n ) && ! self::pm( $R['p2cap'], $n ) && self::pma( $R['p2step'], $n, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[1] as $k => $a ) {
				$at   = $m[0][ $k ][1];
				$tail = substr( $n, $a[1] + strlen( $a[0] ), 60 );
				$pre  = substr( $n, max( 0, $at - 40 ), min( 40, $at ) );
				if ( self::pm( $R['denied'], substr( $pre, -20 ) ) || ( self::pm( $R['negated'], $pre ) && ! self::pm( '/\b(?:if|unless|until)\b/', $pre ) )
					|| ( '%' === substr( $a[0], -1 ) && self::pm( $R['notpct'], $tail ) )
					|| self::pm( $R['later'], $tail )
					|| self::pm( '/\b(?:less|minus|credit(?:ed)?\s+(?:for|of)|applied|refund(?:ed|s)?(?:\s+of)?|return(?:ed)?(?:\s+of)?|deduct(?:ed|ion)?(?:\s+of)?)\s*[:-]?\s*(?:the\s+|your\s+|a\s+|any\s+)?$/', $pre )
					|| self::pm( '/(?:-|\(\s*)$/', substr( $n, $at, $a[1] - $at ) )
					|| self::pm( $R['p2rec'], substr( $n, $a[1] + strlen( $a[0] ) ) ) ) {
					continue;
				}
				$out[] = array_merge( self::amount( $a[0] ), array( 'step' ) );
			}
		}

		if ( self::pma( $R['downlab'], $n, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[1] as $a ) {
				if ( '%' === substr( $a[0], -1 ) && self::pm( $R['notpct'], substr( $n, $a[1] + strlen( $a[0] ), 40 ) ) ) {
					continue;
				}
				if ( '%' === substr( $a[0], -1 ) && self::pm( '/^\s*(?:light|filter|opaci|translucen|sheer|dim|view)/', substr( $n, $a[1] + strlen( $a[0] ), 20 ) ) ) {
					continue;
				}
				if ( self::pm( '/\b(?:up\s*[:=-]?\s*\d{1,3}(?:\.\d+)?%|position|tilt|raised|lowered)/', substr( $n, 0, $a[1] ) ) ) {
					continue;
				}
				$out[] = self::amount( $a[0] );
			}
		}

		if ( self::pma( $R['home'], $n, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m['amt'] as $a ) {
				$v = self::amount( $a[0] );
				if ( $v[0] >= 100.0 && ! self::pm( $R['negated'], substr( $n, max( 0, $a[1] - 40 ), min( 40, $a[1] ) ) ) ) {
					$out[] = $v;
				}
			}
		}

		foreach ( array( 'lab1', 'lab2' ) as $k ) {
			if ( ! self::pma( $R[ $k ], $n, $m, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			foreach ( $m['amt'] as $j => $a ) {
				$lab   = $m['lab'][ $j ];
				$after = substr( $n, $a[1] + strlen( $a[0] ), 60 );
				if ( ( '%' !== substr( $a[0], -1 ) && 'half' !== $a[0] ) || ! self::pm( '/^(?:signing|signature|order(?:ing)?|approval|acceptance|booking|contract)$/', $lab[0] )
					|| self::promo( $n, 'lab1' === $k ? $lab[1] : $a[1], 'lab1' === $k ? strlen( $lab[0] ) : strlen( $a[0] ) )
					|| ( 'lab1' === $k && ! self::pm( $R['alone'], $after ) ) ) {
					continue;
				}
				$out[] = self::amount( $a[0] );
			}
		}

		$product = (bool) self::pm( $R['product'], $n );
		if ( $pay || ( ! $priced && ( ( $terms && ! $product ) || self::pm( '/^\d{1,3}\s*%?(?:\s*\/\s*\d{1,3}\s*%?){1,3}[.!]?$/', $n ) ) ) ) {
			foreach ( self::splits( $n, $pay || ( $terms && ! $product ) ) as $first ) {
				$out[] = array( $first, 0 );
			}
		}
		if ( $out && self::pma( $R['restate'], $n, $m ) ) {
			foreach ( $m[1] as $a ) {
				$out[] = array( 0.0, self::cents_of( $a ) );
			}
		}
		return $out;
	}

	private static function splits( string $n, bool $dash ): array {
		$R   = self::re();
		$out = array();
		if ( self::pma( $R['split'], $n, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $s ) {
				$parts = array();
				for ( $k = 1; $k <= 4; $k++ ) {
					if ( isset( $s[ $k ] ) && '' !== $s[ $k ] ) {
						$parts[] = (int) $s[ $k ];
					}
				}
				if ( count( $parts ) >= 2 && 100 === array_sum( $parts ) ) {
					$out[] = (float) $parts[0];
				}
			}
		}
		if ( self::pm( $R['halves'], $n ) ) {
			$out[] = 50.0;
		}
		if ( $dash && self::pma( $R['dash'], $n, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $d ) {
				if ( (int) $d[1] > 0 && (int) $d[2] > 0 && 100 === (int) $d[1] + (int) $d[2] ) {
					$out[] = (float) $d[1];
				}
			}
		}
		return $out;
	}

	private static function maybes( string $n ): array {
		$R = self::re();
		if ( ! self::pm( $R['advany'], $n ) || ! self::pm( $R['payword'], $n )
			|| self::pm( '/\b(?:no|without|zero)\s+(?:an?\s+)?(?:deposit|down\s*-?\s*payment|retainer)s?\b|\b(?:deposit|down\s*-?\s*payment|retainer)s?\s+(?:waived|not\s+required)\b/', $n ) ) {
			return array();
		}
		$out = array();
		if ( self::pma( '/(?<![\w.])(\d{1,3}(?:\.\d+)?)%/', $n, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[0] as $k => $hit ) {
				$after  = substr( $n, $hit[1] + strlen( $hit[0] ), 40 );
				$before = substr( $n, max( 0, $hit[1] - 30 ), min( 30, $hit[1] ) );
				if ( ! self::pm( $R['notpct'], $after ) && ! self::promo( $n, $hit[1], strlen( $hit[0] ) )
					) {
					$out[] = array( (float) $m[1][ $k ][0], 0 );
				}
			}
		}
		if ( self::pm( '/\bhalf\b(?!\s*(?:-?\s*off|-?\s*price|the\s+(?:windows?|doors?|price|' . ZEST_Deposit_Limit::alt( array(), 'product' ) . ')))/', $n ) ) {
			$out[] = array( 50.0, 0 );
		}
		if ( self::pma( '/\$\s?((?:\d{1,3}(?:,\d{3})+|\d+)(?:\.\d{1,2})?)/', $n, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[0] as $k => $hit ) {
				if ( ! self::pm( $R['notusd'], substr( $n, max( 0, $hit[1] - 30 ), min( 30, $hit[1] ) ) ) ) {
					$out[] = array( 0.0, self::cents_of( $m[1][ $k ][0] ) );
				}
			}
		}
		return $out;
	}

	private static function soft( string $n, bool $priced = false ): array {
		$R   = self::re();
		$out = array();
		$pay = (bool) self::pm( $R['payword'], $n );
		foreach ( array( 'soft', 'softpct', 'lab1', 'lab2', 'fee', 'home' ) as $k ) {
			if ( ! self::pma( $R[ $k ], $n, $m, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			foreach ( $m[0] as $j => $whole ) {
				$a = null;
				foreach ( array( 'amt', 'amt2', 'amt3' ) as $g ) {
					if ( isset( $m[ $g ][ $j ] ) && '' !== $m[ $g ][ $j ][0] && $m[ $g ][ $j ][1] >= 0 ) {
						$a = $m[ $g ][ $j ];
						break;
					}
				}
				if ( null === $a ) {
					continue;
				}
				$lab   = isset( $m['lab'][ $j ] ) ? $m['lab'][ $j ] : null;
				$after = substr( $n, $a[1] + strlen( $a[0] ), 60 );
				if ( 'home' === $k && '$' === $a[0][0] ) {
					continue;
				}
				if ( self::pm( $R['denied'], substr( $n, max( 0, $whole[1] - 20 ), min( 20, $whole[1] ) ) )
					|| ( self::pm( $R['negated'], substr( $n, max( 0, $a[1] - 40 ), min( 40, $a[1] ) ) ) && ! self::pm( '/\b(?:if|unless|until)\b/', substr( $n, max( 0, $a[1] - 40 ), min( 40, $a[1] ) ) ) )
					|| self::promo( $n, ( $lab && 'lab1' === $k ) ? $lab[1] : $a[1], ( $lab && 'lab1' === $k ) ? strlen( $lab[0] ) : strlen( $a[0] ) )
					|| ( '%' === substr( $a[0], -1 ) && self::pm( $R['notpct'], $after ) ) || self::pm( $R['later'], $after ) ) {
					continue;
				}
				if ( $lab ) {
					if ( '%' === substr( $a[0], -1 ) || 'half' === $a[0] ) {

						if ( ! $pay && 'lab1' === $k && ! self::pm( $R['alone'], $after ) ) {
							continue;
						}
					} elseif ( self::pm( '/^(?:order|ordering|contract|materials?)$/', $lab[0] ) && ! $pay ) {
						continue;
					}
				}
				$out[] = self::amount( $a[0] );
			}
		}
		if ( self::pma( $R['implied'], $n, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[1] as $a ) {
				$rest = (float) $a[0];
				$tail = substr( $n, $a[1] + strlen( $a[0] ) + 1, 40 );

				if ( $rest > 0 && ( $pay || self::pm( '/^\s*(?:is\s+)?(?:due\s+)?(?:when|once|at|upon|on|after|before)\b/', $tail ) ) && ! self::pm( $R['notpct'], $tail ) ) {
					$out[] = array( 100.0 - $rest, 0 );
				}
			}
		}
		if ( self::pm( '/\b(?:two|2)\s+equal\s+(?:payments|installments)\b/', $n ) && self::pm( '/\b(?:order\w*|sign\w*|approv\w*|accept\w*|book\w*|start\w*)\b/', $n ) ) {
			$out[] = array( 50.0, 0 );
		}

		if ( ! $priced && ! self::pm( $R['product'], $n ) && self::pm( $R['splitsaid'], $n ) ) {
			foreach ( self::splits( $n, true ) as $first ) {
				$out[] = array( $first, 0 );
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

		$rest = self::pr( '/\bwhen\s+(?:you\s+are\s+|you\'re\s+|you\s+re\s+)?ready\b|(?:\b(?:on|at|by)\s+)?(?:the\s+)?\b(?:date|day)\s+of\s+(?:the\s+)?(?:install(?:ation)?|completion)\b/', ' ', $n );

		$rest = self::pr( '/\(?\s*\b(?:to\s+be\s+|will\s+be\s+)?applied\s+to\s+(?:your|the)\s+(?:order|total|balance|invoice|final\s+invoice|purchase)\s*\)?/', ' ', $rest );

		$rest = self::prc( '/\b\d{1,3}(?:\s*[\/-]\s*\d{1,4})+\b/', static function ( $m ) use ( $rest ) {
			$parts = array_map( 'intval', preg_split( '/\s*[\/-]\s*/', $m[0][0] ) );
			if ( count( $parts ) < 2 || 100 !== array_sum( $parts ) || in_array( 0, $parts, true ) ) {
				return $m[0][0];
			}
			$before = substr( $rest, 0, $m[0][1] );
			$after  = substr( $rest, $m[0][1] + strlen( $m[0][0] ) );
			$alone  = self::pm( '/^[\s,;:.-]*$/', $before ) && self::pm( '/^\s*[.!]?\s*$/', $after );
			$said   = self::pm( '/\b(?:terms|payments?|pay|paid|split|deposits?|due|balance|schedule|plan)\s*[:=-]?\s*$|%\s*$/', $before )
				|| self::pm( '/^\s*[-,;:.)]?\s*\(?\s*(?:payments?|split|terms|plan|schedule|half|deposits?|down|due|at|upon)\b/', $after );
			return ( $alone || $said ) ? ' ' : $m[0][0];
		}, $rest, -1, $count, PREG_OFFSET_CAPTURE );
		$rest = self::pr( '/\$\s?(?:\d{1,3}(?:,\d{3})+|\d+)(?:\.\d{1,2})?|\d{1,3}(?:\.\d+)?\s*%(?:\s*\/\s*\d{1,3}\s*%?)*|=\s*\$?\s?(?:\d{1,3}(?:,\d{3})+|\d+)(?:\.\d{1,2})?/', ' ', $rest );
		if ( self::pm( '/\d/', $rest ) ) {
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
			if ( ! $end && ( "\n" === $c || "\r" === $c ) && self::pm( '/:[ \t]*\r?\z/', substr( $text, $s, $i - $s ) ) && self::pm( '/\S/', substr( $text, $s, $i - $s ) ) ) {
				continue;
			}
			if ( ! $end && ( "\n" === $c || "\r" === $c ) && self::runs_on( $text, $s, $i ) ) {
				continue;
			}
			$cut = $hard || ( strpos( '.;!?', $c ) !== false && ( $i + 1 >= $n || ctype_space( $text[ $i + 1 ] ) )
				&& ! ( '.' === $c && self::pm( '/\b(?:approx|appx|est|no|nos|qty|vs|etc|inc|ltd|mr|mrs|ms|dr|st|ave|apt|ste|e\.g|i\.e)$/i', substr( $text, max( $s, $i - 6 ), $i - max( $s, $i - 6 ) ) ) ) );
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

	private static function runs_on( string $text, int $s, int $i ): bool {
		$from = strrpos( substr( $text, 0, $i ), "\n" );
		$from = max( $s, false === $from ? 0 : $from + 1 );
		$line = trim( substr( $text, $from, $i - $from ), " \t\r\0" );
		if ( '' === $line ) {
			return false;
		}
		$j = $i + ( "\r" === $text[ $i ] && isset( $text[ $i + 1 ] ) && "\n" === $text[ $i + 1 ] ? 2 : 1 );
		if ( $j >= strlen( $text ) ) {
			return false;
		}
		$k = strcspn( $text, "\r\n", $j );
		$next = trim( substr( $text, $j, $k ), " \t\0" );
		if ( '' === $next ) {
			return false;
		}
		if ( ! self::pm( '/\d/', $line )
			&& self::pm( '/^(?:\$\s?(?:\d{1,3}(?:,\d{3})+|\d+)(?:\.\d{1,2})?|\d{1,3}(?:\.\d+)?\s*%)(?:\s*\([^()]{1,30}\)|\s+of\s+(?:the\s+)?(?:total|price|contract|job|estimate)(?:\s+price)?)?[.!]?$/i', $next ) ) {
			return true;
		}
		return (bool) self::pm( '/\b(?:due|payable)$/i', $line ) && (bool) self::pm( '/^(?:at|upon|on|when|before|with|once|prior\s+to|by)\b/i', $next );
	}

	private static function cut( string $clause, int $pretax, bool $terms ): ?array {
		$end = '';
		if ( self::pm( '/[.!?;]+$/', $clause, $m ) ) {
			$end    = $m[0];
			$clause = substr( $clause, 0, -strlen( $end ) );
		}
		$parts = array();
		$pos   = 0;
		$prev  = '';
		if ( self::pma( '/,\s+|;\s+|\s+(?:-|\xE2\x80\x93|\xE2\x80\x94)\s+|\s+(?=\()|(?<=\))\s+/', $clause, $m, PREG_OFFSET_CAPTURE ) ) {
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
		if ( self::pm( '/^(?:[a-z]+\s+)?(?:sale|special|promo(?:tion)?|deal|event|offer)$|^(?:plus|\+)\s+(?:applicable\s+|sales\s+)?tax(?:es)?$/i', $kept ) ) {
			return null;
		}
		return '' === $kept ? null : array( $kept . $end, implode( ', ', $taken ) );
	}

	/**
	 * Every over-the-limit deposit ask in $text: [ { clause, why, sure, ... } ]. $pretax_cents: the price the limit is
	 * measured against; $terms: the text is a document's terms; $priced: the figures were already priced. Empty when no
	 * limit is in force.
	 */
	public static function find( string $text, int $pretax_cents = 0, bool $terms = false, bool $priced = false ): array {
		if ( '' === trim( $text ) || ! ZEST_Deposit_Limit::active() ) {
			return array();
		}
		$R      = self::re();
		$out    = array();
		$masked = $text;
		if ( self::pma( ZEST_Deposit_Limit::own_re(), $text, $m, PREG_OFFSET_CAPTURE ) ) {
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
			$clause       = substr( $text, $c[0], $c[1] );
			self::$failed = '';
			$got          = null;
			do {
				$n = self::norm( $clause );

				$rec = self::pr( '/\b(?:when|once|after|as\s+soon\s+as|until)\s+(?:[\w\']+\s+){0,5}?(?:is\s+|are\s+|has\s+been\s+|have\s+been\s+|gets?\s+)?received\b/', ' ', $n );
				if ( '' === $n || self::pm( $R['record'], $rec ) ) {
					break;
				}
				$demoted = array();
				$sure    = self::asks( $n, $terms, $priced, $demoted );
				foreach ( $demoted as $i => $d ) {

					if ( 'old' === ( $d[2] ?? '' ) && ! self::pm( '/[^:\s][ \t]*\r?\n/', $clause ) ) {
						$sure[] = array( $d[0], $d[1] );
						unset( $demoted[ $i ] );
					}
				}
				list( $is, $why ) = self::worst( $sure, $pretax_cents );
				if ( $is ) {
					$whole = self::only_talk( $n );
					$cut   = $whole ? null : self::cut( $clause, $pretax_cents, $terms );
					$got   = array( 'start' => $c[0], 'length' => $c[1], 'clause' => trim( $clause ), 'why' => $why, 'sure' => true,
						'whole' => $whole, 'own' => false, 'cut' => $cut ? $cut[0] : null, 'taken' => $whole ? trim( $clause ) : ( $cut ? $cut[1] : '' ) );
					break;
				}

				$sure = array_filter( $sure, static function ( $x ) { return 'step' !== ( $x[2] ?? '' ); } );
				if ( ! $sure ) {
					list( $is, $why ) = self::worst( array_merge( self::maybes( $n ), self::soft( $n, $priced ), $demoted ), $pretax_cents );
					if ( $is ) {
						$got = array( 'start' => $c[0], 'length' => $c[1], 'clause' => trim( $clause ), 'why' => $why, 'sure' => false,
							'whole' => false, 'own' => false, 'cut' => null, 'taken' => '' );
					}
				}
			} while ( false );

			if ( '' !== self::$failed && ( null === $got || ! $got['sure'] ) ) {
				error_log( 'Zorderz Estimates deposit rule: a clause of ' . strlen( $clause ) . ' bytes could not be read (' . self::$failed . '); it is listed as possible' );
				$got = array( 'start' => $c[0], 'length' => $c[1], 'clause' => trim( $clause ), 'why' => self::UNREAD, 'sure' => false,
					'whole' => false, 'own' => false, 'cut' => null, 'taken' => '' );
			}
			if ( null !== $got ) {
				$out[] = $got;
			}
		}
		$out = self::floor( $text, $pretax_cents, $terms, $priced, $out );
		usort( $out, static function ( $a, $b ) { return $a['start'] <=> $b['start']; } );
		return $out;
	}

	private static function floor( string $text, int $pretax, bool $terms, bool $priced, array $out ): array {
		foreach ( ZEST_Deposit_Floor::find( $text, $pretax, $terms ) as $l ) {
			$a    = (int) $l['start'];
			$b    = $a + (int) $l['length'];
			$over = array();
			foreach ( $out as $i => $o ) {
				if ( $o['start'] < $b && $a < $o['start'] + $o['length'] ) {
					$over[ $i ] = $o;
				}
			}
			if ( array_filter( $over, static function ( $o ) { return $o['sure']; } ) || ( ! $l['sure'] && $over ) ) {
				continue;
			}
			$clause = substr( $text, $a, $b - $a );
			if ( self::floor_lawful( $clause, $priced, $pretax, $terms ) ) {
				continue;
			}
			if ( ! $l['sure'] ) {
				$out[] = array( 'start' => $a, 'length' => $b - $a, 'clause' => trim( $clause ), 'why' => $l['why'], 'sure' => false,
					'whole' => false, 'own' => false, 'cut' => null, 'taken' => '' );
				continue;
			}
			foreach ( array_keys( $over ) as $i ) {
				unset( $out[ $i ] );
			}
			$n     = self::norm( $clause );
			$whole = self::only_talk( $n );
			$cut   = $whole ? null : self::cut( $clause, $pretax, $terms );
			$out[] = array( 'start' => $a, 'length' => $b - $a, 'clause' => trim( $clause ), 'why' => $l['why'], 'sure' => true,
				'whole' => $whole, 'own' => false, 'cut' => $cut ? $cut[0] : null, 'taken' => $whole ? trim( $clause ) : ( $cut ? $cut[1] : '' ) );
		}
		return array_values( $out );
	}

	private static function floor_lawful( string $clause, bool $priced, int $pretax = 0, bool $terms = false ): bool {
		$R     = self::re();
		$n     = self::norm( $clause );
		$pay   = (bool) self::pm( $R['payword'], $n );
		$blank = $n;
		$shape = false;

		if ( ! $pay && ( $priced || self::pm( $R['product'], $n ) ) ) {
			$blank = self::prc( '/(?<![\d.,#])\d{1,3}(?:\s*%?\s*[\/-]\s*\d{1,3}){1,3}\s*%?(?!\d|[.,]\d)/', static function ( $m ) use ( &$shape ) {
				$parts = array_map( 'intval', preg_split( '/\s*%?\s*[\/-]\s*/', rtrim( $m[0], " %\t" ) ) );
				for ( $k = 2, $sum = $parts[0] + $parts[1]; $k <= count( $parts ); $sum += $parts[ $k ] ?? 0, $k++ ) {
					if ( 100 === $sum ) {
						$shape = true;
						return ' ';
					}
				}
				return $m[0];
			}, $blank );
		}

		$blank = self::prc( '/#\s*\d{1,3}\s*[\/-]\s*\d+/', static function () use ( &$shape ) { $shape = true; return ' '; }, $blank );

		if ( ! self::pm( '/\b(?:deposits?|down|retainer|payment)\b/', $n ) ) {
			$blank = self::prc( '/(?:\d{1,3}(?:\.\d+)?%\s*)?(?<!non-)(?<!non)\brefundable\b/', static function () use ( &$shape ) { $shape = true; return ' '; }, $blank );
		}

		if ( self::pm( '/\b(?:position|tilt)\b|\bup\s*[:=-]?\s*\d{1,3}(?:\.\d+)?%/', $n ) && ! self::pm( '/\b(?:deposits?|down\s*-?\s*payment|retainer)\b/', $n ) ) {
			$shape = true;
			$blank = self::pr( '/\b(?:up|down|tilt|open|closed?|position)\s*[:=-]?\s*\d{1,3}(?:\.\d+)?%/', ' ', $blank );
		}

		$after = '\b(?:(?:\d+|an?|one|two|three|four|five|six|ten|fourteen|fifteen|thirty|sixty|ninety)\s+(?:days?|weeks?|months?)\s+after\s+(?:the\s+)?(?:completion|install(?:ation)?|the\s+job)|(?:acceptance|approval)\s+of\s+the\s+(?:completed|finished)\s+(?:work|job|install(?:ation)?))\b';
		if ( self::pm( '/' . $after . '/', $n ) ) {
			$shape = true;
			$blank = self::pr( '/(?:\$\s?[\d,]*\d(?:\.\d{1,2})?|\b\d{1,3}(?:\.\d+)?%)(?=[^.;,]*?' . $after . ')/', ' ', $blank );
		}

		return $shape && array() === ZEST_Deposit_Floor::find( $blank, $pretax, $terms );
	}

	private static function fixable_finding( array $f ): bool {
		return ! empty( $f['sure'] ) && ( ! empty( $f['whole'] ) || null !== $f['cut'] );
	}

	private static function splice_out( string $text, int $at, int $len ): string {
		$a = $at;
		$b = $at + $len;
		$n = strlen( $text );
		if ( $b < $n && ( ' ' === $text[ $b ] || "\t" === $text[ $b ] ) ) {
			while ( $b < $n && ( ' ' === $text[ $b ] || "\t" === $text[ $b ] ) ) {
				$b++;
			}
		} else {
			while ( $a > 0 && ( ' ' === $text[ $a - 1 ] || "\t" === $text[ $a - 1 ] ) ) {
				$a--;
			}
		}
		$line_start = ( 0 === $a || "\n" === $text[ $a - 1 ] );
		$line_end   = ( $b >= $n || "\n" === $text[ $b ] || "\r" === $text[ $b ] );
		if ( $line_start && $line_end ) {
			if ( $b < $n ) {
				$b += ( "\r" === $text[ $b ] && $b + 1 < $n && "\n" === $text[ $b + 1 ] ) ? 2 : 1;
			} elseif ( $a > 0 ) {
				$a -= ( $a >= 2 && "\r" === $text[ $a - 2 ] ) ? 2 : 1;
			}
		}
		return substr( $text, 0, $a ) . substr( $text, $b );
	}

	/**
	 * The text with every fixable over-the-limit ask taken out and, when $may_write and nothing lawful is left, the
	 * lawful sentence written: [ text, removed[], wrote ].
	 */
	public static function fix( string $text, int $pretax_cents = 0, bool $terms = false, bool $may_write = true ): array {
		$found = array_values( array_filter( self::find( $text, $pretax_cents, $terms ), static function ( $f ) { return self::fixable_finding( $f ); } ) );
		if ( ! $found ) {
			return array( $text, array(), '' );
		}
		$has     = (bool) preg_match( ZEST_Deposit_Limit::own_re(), $text );
		$write   = $pretax_cents > 0 && $may_write && ! $has;
		$mark    = "\x01";
		$edits   = array();
		$taken   = array();
		$wrote   = '';
		$append  = false;
		$placed  = null;
		foreach ( $found as $f ) {
			$taken[] = $f['taken'];
			if ( $f['own'] ) {
				$long    = false !== strpos( $f['clause'], 'Balance due upon completion.' );
				$new     = $long ? self::lawful_sentence( $pretax_cents ) : self::short_sentence( $pretax_cents );
				$edits[] = array( $f['start'], $f['length'], $new );
				$wrote   = $new;
				continue;
			}
			if ( $f['whole'] ) {
				$put = '';
				if ( $write ) {
					$put    = $mark;
					$write  = false;
					$placed = $f;
				}
				$edits[] = array( $f['start'], $f['length'], $put );
			} else {
				$edits[] = array( $f['start'], $f['length'], (string) $f['cut'] );
				if ( $write ) {
					$append = true;
					$write  = false;
				}
			}
		}

		if ( $placed ) {
			$masked = (string) preg_replace_callback( ZEST_Deposit_Limit::own_re(), static function ( $x ) { return str_repeat( "\0", strlen( $x[0] ) ); }, $text );
			$after  = $placed['start'] + $placed['length'];
			foreach ( self::clauses( $masked ) as $c ) {
				if ( $c[0] < $after ) {
					continue;
				}
				if ( preg_match( '/\n[ \t\r]*\n/', substr( $text, $after, $c[0] - $after ) ) ) {
					break;
				}
				$n     = self::norm( substr( $text, $c[0], $c[1] ) );
				$found_here = false;
				foreach ( $found as $f ) {
					$found_here = $found_here || $f['start'] === $c[0];
				}
				$balance = preg_match( '/^(?:and\s+|then\s+|with\s+(?:the\s+)?)?(?:the\s+)?(?:balance|remaining\s+balance|remainder|rest\s+of\s+(?:the\s+)?(?:balance|payment|amount|total))\b/', $n )
					|| preg_match( '/^(?:(?:the\s+)?balance\s+)?(?:is\s+)?(?:due|payable)\s+(?:up)?on\s+(?:completion|install(?:ation)?|finish)\b/', $n );
				if ( $found_here || ! $balance || ! self::only_talk( $n ) || preg_match( '/[\d$%]/', $n ) ) {
					break;
				}
				$edits[] = array( $c[0], $c[1], '' );
				$taken[] = trim( substr( $text, $c[0], $c[1] ) );
				$after   = $c[0] + $c[1];
			}
		}
		usort( $edits, static function ( $a, $b ) { return $b[0] <=> $a[0]; } );
		foreach ( $edits as $e ) {
			$text = '' === $e[2] ? self::splice_out( $text, $e[0], $e[1] ) : substr( $text, 0, $e[0] ) . $e[2] . substr( $text, $e[0] + $e[1] );
		}
		$text = trim( $text );
		if ( null !== $placed || $append ) {
			$rest     = str_replace( $mark, '', $text );
			$sentence = preg_match( '/\bbalance\s+(?:is\s+)?due\s+(?:up)?on\s+completion\b/i', $rest ) ? self::short_sentence( $pretax_cents ) : self::lawful_sentence( $pretax_cents );
			$text     = null !== $placed ? str_replace( $mark, $sentence, $text ) : $text . ( '' !== $text ? "\n\n" : '' ) . $sentence;
			$wrote    = $sentence;
		}
		return array( $text, array_values( array_filter( $taken, 'strlen' ) ), $wrote );
	}

	private static function amt( $v ): int {
		if ( is_array( $v ) ) {
			$v = $v['amount'] ?? 0;
		}
		return is_numeric( $v ) ? (int) round( (float) $v * 100 ) : 0;
	}

	/** The document's whole-document discount, in cents (never negative). */
	public static function doc_discount_cents( array $doc ): int {
		return abs( self::amt( $doc['discount_total'] ?? 0 ) );
	}

	/** A billing document's [ pre-tax cents, total cents ], tax typed as a line kept out of the pre-tax figure. */
	public static function doc_totals( array $doc ): array {
		$pre = 0;
		foreach ( (array) ( $doc['lines'] ?? array() ) as $l ) {
			if ( ! is_array( $l ) ) {
				continue;
			}
			$qty   = is_numeric( $l['qty'] ?? null ) ? (float) $l['qty'] : 1.0;
			$cents = (int) round( $qty * self::amt( $l['unit_cost'] ?? 0 ) );
			if ( $cents > 0 && self::tax_line( $l ) ) {
				continue;
			}
			$pre += $cents;
		}
		$pre  -= self::doc_discount_cents( $doc );
		$total = self::amt( $doc['amount'] ?? 0 );
		if ( $pre <= 0 || ( $total > 0 && $pre > $total ) ) {
			$pre = $total;
		}
		return array( max( 0, $pre ), max( 0, $total ) );
	}

	private static function is_invoice( array $doc ): bool {
		return isset( $doc['invoiceid'] ) || isset( $doc['invoice_number'] );
	}

	/**
	 * The price a deposit on this document is measured against, in cents: the work before tax, less discounts (an
	 * invoice made from an estimate may use the job's own pre-tax price).
	 */
	public static function base( array $doc, int $job_pretax = 0 ): int {
		list( $pre, $total )  = self::doc_totals( $doc );
		list( $job, $credit ) = self::job_cents( $doc );
		$job                  = max( 0, $job - self::doc_discount_cents( $doc ) );
		$job_ok = $job > 0 && ( $total <= 0 || $job <= $total + $credit );
		if ( ! self::is_invoice( $doc ) ) {
			return $job_ok ? $job : $pre;
		}

		$deposit = self::is_deposit_invoice( $doc );
		if ( $job_ok && ! $deposit ) {
			return $job;
		}
		$est    = (string) ( $doc['estimateid'] ?? '' );
		$linked = ctype_digit( $est ) && '' !== ltrim( $est, '0' );
		if ( $linked && $job_pretax > 0 ) {
			return $job_pretax;
		}
		if ( $deposit ) {

			return ( $job_ok && $job > self::deposit_cents( $doc ) ) ? $job : 0;
		}
		return $linked ? 0 : $pre;
	}

	private static function is_deposit_line( array $l ): bool {
		$name = self::norm( (string) ( $l['name'] ?? '' ) );
		$all  = self::norm( (string) ( $l['name'] ?? '' ) . ' ' . (string) ( $l['description'] ?? '' ) );
		return (bool) preg_match( '/^(?:\d{1,3}(?:\.\d+)?%\s+|half\s+)?(?:deposits?|down\s*-?\s*payments?|downpayments?|retainers?)(?:\s*$|\s*[-:#(]|\s+(?:for|on|of|toward|towards|due|invoice|payment|amount|request)\b)/', $name )
			&& ! preg_match( '/\b(?:no|without|waived?|free|not\s+required|none|refund(?:ed)?)\b/', $all )
			&& ! preg_match( self::re()['record'], $all );
	}

	/** Is this billing line a credit or discount (money off the price)? */
	public static function credit_line( array $l ): bool {
		return self::is_credit_line( $l );
	}

	/** Is this billing line a deposit charge or a payment already taken? */
	public static function deposit_line( array $l ): bool {
		return self::is_deposit_line( $l );
	}

	/** Is this billing line tax typed as a line (not a tax credit, rebate or exemption)? */
	public static function tax_line( array $l ): bool {
		return (bool) preg_match( '/\btax(?:es)?\b(?![\s-]*(?:credits?|rebates?|exempt\w*|free|deductible|incentives?|id\b|prep\w*|returns?|services?))/i', (string) ( $l['name'] ?? '' ) );
	}

	private static function is_credit_line( array $l ): bool {
		$all = self::norm( (string) ( $l['name'] ?? '' ) . ' ' . (string) ( $l['description'] ?? '' ) );
		$name = self::norm( (string) ( $l['name'] ?? '' ) );
		if ( preg_match( '/%|\b(?:discount\w*|off|save[sd]?|saving|savings|promo\w*|rebate\w*|coupon\w*|special\w*|sale|deal|offer|referral|reward\w*|bonus|courtesy|loyalty'
			. '|incentive\w*|match\w*|adjust\w*|early|in\s+full|up\s*-?\s*front|upfront|pric(?:e|es|ed|ing)|delay\w*|goodwill|concession\w*|waive[sd]?|comp\b|complimentary'
			. '|credit|free|financing|allowance)\b/', $all ) ) {
			return false;
		}
		$money = '(?:deposits?|payments?|retainers?|down\s*-?\s*payments?|downpayments?|prepayments?)';

		$less  = '/\bless\b[\s:]*(?:(?:the|your|a|an|first|1st|second|2nd|third|3rd|previous|prior|initial|advance|down|cash|check|card|customer|hoa|partial|progress|deposit)\s+){0,3}?' . $money . '\b\s*(?:[-:(,]\s*)?(?:(?:on\s+)?(?:0?[1-9]|1[0-2])[\/-]\d{1,2}(?:[\/-]\d{2,4})?|(?:check\s*)?(?:#|no\.?|number)\s*\d+)?\s*\)?\s*[.!]?$/';
		return preg_match( $less, $name )
			|| preg_match( '/\b' . $money . '\s*(?:-|:)?\s*(?:received|rec\'?d|paid|already\s+paid|was\s+paid|collected|applied)\b'
			. '|\bthanks?(?:\s+you)?\s+for\s+(?:your\s+|the\s+)?' . $money . '\b|\bcheck\s*(?:#|no\.?|number)\s*\d/', $all );
	}

	private static function is_deposit_invoice( array $doc ): bool {
		return self::is_invoice( $doc ) && self::deposit_cents( $doc ) > 0;
	}

	private static function deposit_cents( array $doc ): int {
		$sum = 0;
		foreach ( (array) ( $doc['lines'] ?? array() ) as $l ) {
			if ( ! is_array( $l ) ) {
				continue;
			}
			$qty   = is_numeric( $l['qty'] ?? null ) ? (float) $l['qty'] : 1.0;
			$cents = (int) round( $qty * self::amt( $l['unit_cost'] ?? 0 ) );
			if ( $cents > 0 && self::is_deposit_line( $l ) ) {
				$sum += $cents;
			}
		}
		return $sum;
	}

	/**
	 * Does this invoice need its estimate read to judge its deposit wording (made from an estimate, not a deposit
	 * invoice)?
	 */
	public static function needs_estimate( array $doc ): bool {
		if ( ! self::is_invoice( $doc ) ) {
			return false;
		}
		$est = (string) ( $doc['estimateid'] ?? '' );
		if ( ! ctype_digit( $est ) || '' === ltrim( $est, '0' ) ) {
			return false;
		}
		if ( self::is_deposit_invoice( $doc ) ) {
			return true;
		}
		list( , $total )      = self::doc_totals( $doc );
		list( $job, $credit ) = self::job_cents( $doc );
		$job                  = max( 0, $job - self::doc_discount_cents( $doc ) );
		return ! ( $job > 0 && ( $total <= 0 || $job <= $total + $credit ) );
	}

	private static function job_cents( array $doc ): array {
		$pre     = 0;
		$credits = 0;
		foreach ( (array) ( $doc['lines'] ?? array() ) as $l ) {
			if ( ! is_array( $l ) ) {
				continue;
			}
			$qty   = is_numeric( $l['qty'] ?? null ) ? (float) $l['qty'] : 1.0;
			$cents = (int) round( $qty * self::amt( $l['unit_cost'] ?? 0 ) );
			if ( $cents > 0 && ( self::is_deposit_line( $l ) || self::tax_line( $l ) ) ) {
				continue;
			}
			if ( $cents < 0 && self::is_credit_line( $l ) ) {
				$credits -= $cents;
				continue;
			}
			$pre += $cents;
		}
		return array( $pre, $credits );
	}

	/**
	 * Every over-the-limit deposit ask in a billing document's notes, terms and lines: [ { where, words, why, sure,
	 * fixable } ].
	 */
	public static function doc_findings( array $doc, int $job_pretax = 0 ): array {
		$doc  = class_exists( 'ZEST_Terms_Note' ) ? ZEST_Terms_Note::unread( $doc ) : $doc;
		$base = self::base( $doc, $job_pretax );
		$est  = ! self::is_invoice( $doc );
		$out  = array();
		foreach ( array( 'notes' => 'Notes', 'terms' => 'Terms' ) as $k => $label ) {
			foreach ( self::find( (string) ( $doc[ $k ] ?? '' ), $base, 'terms' === $k ) as $f ) {
				$out[] = array( 'where' => $label, 'words' => $f['clause'], 'why' => $f['why'], 'sure' => $f['sure'], 'fixable' => $est && self::fixable_finding( $f ) );
			}
		}
		foreach ( array_values( (array) ( $doc['lines'] ?? array() ) ) as $i => $l ) {
			if ( ! is_array( $l ) ) {
				continue;
			}
			$qty   = is_numeric( $l['qty'] ?? null ) ? (float) $l['qty'] : 1.0;
			$line  = (int) round( $qty * self::amt( $l['unit_cost'] ?? 0 ) );
			$where = 'Line ' . ( $i + 1 );
			if ( $line > 0 && self::is_deposit_line( $l ) ) {
				$cap = ZEST_Deposit_Limit::limit_cents( $base );
				if ( null !== $cap && $line > $cap ) {

					$out[] = array( 'where' => $where, 'words' => trim( (string) ( $l['name'] ?? '' ) ) . ' ' . self::money( $line ),
						'why' => 'a deposit charge of ' . self::money( $line ) . ' is over the ' . self::money( $cap ) . ' limit', 'sure' => true, 'fixable' => false );
					continue;
				}
			}
			$mine = array();
			foreach ( array( 'name', 'description' ) as $k ) {
				foreach ( self::find( (string) ( $l[ $k ] ?? '' ), $base, false, $line > 0 ) as $f ) {
					$mine[] = array( 'where' => $where, 'words' => $f['clause'], 'why' => $f['why'], 'sure' => $f['sure'], 'fixable' => $est && 0 === $line && self::fixable_finding( $f ) );
				}
			}
			if ( ! array_filter( $mine, static function ( $f ) { return $f['sure']; } ) ) {

				$both = trim( trim( (string) ( $l['name'] ?? '' ) ) . ' / ' . trim( (string) ( $l['description'] ?? '' ) ), ' /' );
				$together = self::find( $both, $base, false, $line > 0 );
				$sure_t   = array_filter( $together, static function ( $x ) { return $x['sure']; } );
				if ( $sure_t ) {
					$mine = array_values( array_filter( $mine, static function ( $x ) { return $x['sure']; } ) );
					foreach ( $sure_t as $f ) {
						$mine[] = array( 'where' => $where, 'words' => $f['clause'], 'why' => $f['why'], 'sure' => true, 'fixable' => false );
					}
				} elseif ( ! $mine ) {

					foreach ( $together as $f ) {
						$mine[] = array( 'where' => $where, 'words' => $f['clause'], 'why' => $f['why'], 'sure' => false, 'fixable' => false );
					}
				}
			}
			$out = array_merge( $out, $mine );
		}

		$pct = is_numeric( $doc['deposit_percentage'] ?? null ) ? (float) $doc['deposit_percentage'] : 0.0;
		$usd = self::amt( $doc['deposit_amount'] ?? 0 );
		if ( isset( $doc['deposit'] ) && is_array( $doc['deposit'] ) ) {
			$pct = max( $pct, is_numeric( $doc['deposit']['percentage'] ?? null ) ? (float) $doc['deposit']['percentage'] : 0.0 );
			$usd = max( $usd, self::amt( $doc['deposit']['amount'] ?? 0 ) );
		}
		list( $is, $why ) = self::worst( array( array( $pct, 0 ), array( 0.0, $usd ) ), $base );
		if ( $is ) {
			$out[] = array( 'where' => "The billing system's deposit request", 'words' => $pct > 0 ? ( rtrim( rtrim( number_format( $pct, 2, '.', '' ), '0' ), '.' ) . '%' ) : self::money( $usd ),
				'why' => $why, 'sure' => true, 'fixable' => false );
		}
		$ps = self::is_invoice( $doc ) ? self::schedule_finding( $doc, $base ) : null;
		if ( $ps ) {
			$out[] = $ps;
		}
		return $out;
	}

	private static function schedule_finding( array $doc, int $base ): ?array {
		$ps = $doc['payment_schedule'] ?? null;
		if ( ! is_array( $ps ) ) {
			return null;
		}
		$items = array_values( array_filter( $ps, 'is_array' ) );

		usort( $items, static function ( $a, $b ) {
			return array( (int) ( $a['payment_number'] ?? 0 ), (string) ( $a['due_date'] ?? '' ) ) <=> array( (int) ( $b['payment_number'] ?? 0 ), (string) ( $b['due_date'] ?? '' ) );
		} );
		$nest    = isset( $doc['deposit'] ) && is_array( $doc['deposit'] ) ? $doc['deposit'] : array();
		$deposit = self::amt( $doc['deposit_amount'] ?? 0 ) > 0 || ( is_numeric( $doc['deposit_percentage'] ?? null ) && (float) $doc['deposit_percentage'] > 0 )
			|| self::amt( $nest['amount'] ?? 0 ) > 0 || ( is_numeric( $nest['percentage'] ?? null ) && (float) $nest['percentage'] > 0 );
		foreach ( $items as $i => $p ) {
			$amt = self::amt( $p['amount'] ?? 0 );
			$has = array_key_exists( 'payable', $p );
			$due = $has ? self::amt( $p['payable'] ) : $amt;
			if ( 'paid' === strtolower( (string) ( $p['status'] ?? '' ) ) || ( $has && $due <= 0 ) ) {
				continue;
			}
			if ( $due <= 0 ) {
				return null;
			}
			list( $is, $why ) = self::over( 0.0, $due, $base );
			if ( ! $is ) {
				return null;
			}
			$shown = $amt > 0 ? $amt : $due;
			$date  = (string) ( $p['due_date'] ?? '' );
			$words = 'Payment ' . ( $i + 1 ) . ' of ' . count( $items ) . ': ' . self::money( $shown )
				. ( preg_match( '/^(\d{4})-(\d\d)-(\d\d)/', $date, $m ) ? ' due ' . $m[2] . '/' . $m[3] . '/' . $m[1] : '' )
				. ( $due === $shown ? '' : ' (' . self::money( $due ) . ( $due < $shown ? ' still due' : ( $deposit ? ' with the deposit' : ' due by then' ) ) . ')' );
			return array( 'where' => "The billing system's payment schedule", 'words' => $words, 'why' => $why . ', if it is due before the work is done',
				'sure' => false, 'fixable' => false, 'quote' => false );
		}
		return null;
	}

	/** The findings that stop a send: the sure ones only. */
	public static function blocking( array $found ): array {
		return array_values( array_filter( $found, static function ( $f ) { return ! empty( $f['sure'] ); } ) );
	}

	private static function fix_parts( ?string $notes, ?string $terms, array $lines, int $pretax, bool $said = false ): array {
		$all = (string) $notes . "\n" . (string) $terms;
		foreach ( $lines as $l ) {
			$all .= "\n" . $l['name'] . "\n" . $l['description'];
		}
		$may     = $pretax > 0 && ! preg_match( ZEST_Deposit_Limit::own_re(), $all );
		$may     = $may && ! $said;
		$removed = array();
		$wrote   = '';
		foreach ( array( 'notes', 'terms' ) as $k ) {
			if ( null === $$k ) {
				continue;
			}
			list( $t, $r, $w ) = self::fix( $$k, $pretax, 'terms' === $k, $may );
			if ( $r ) {
				$$k      = $t;
				$removed = array_merge( $removed, $r );
				if ( '' !== $w ) {
					$wrote = $w;
					$may   = false;
				}
			}
		}
		$renamed = null;
		$first   = null;
		foreach ( $lines as $i => $l ) {
			if ( empty( $l['zero'] ) ) {
				continue;
			}
			list( $tn, $rn, $wn ) = self::fix( (string) $l['name'], $pretax, false, false );
			list( $td, $rd, $wd ) = self::fix( (string) $l['description'], $pretax, false, false );
			if ( ! $rn && ! $rd ) {
				continue;
			}
			if ( $rn && '' === $tn ) {
				$tn      = 'Payment terms';
				$renamed = $renamed ?? $i;
				$nd      = self::norm( $td );
				if ( '' !== $nd && self::only_talk( $nd ) && preg_match( self::CORE . 'i', $nd ) && ! preg_match( '/[\d$%]/', $nd ) ) {
					$rd[] = trim( $td );
					$td   = '';
				}
			}
			$lines[ $i ]['name']        = $tn;
			$lines[ $i ]['description'] = $td;
			$removed = array_merge( $removed, $rn, $rd );
			$wrote   = $wd ?: ( $wn ?: $wrote );
			$first   = $first ?? $i;
		}
		if ( $may && null !== $first ) {
			$target = null !== $renamed ? $renamed : ( null !== $notes ? -1 : $first );
			$text   = -1 === $target ? (string) $notes : (string) $lines[ $target ]['description'];
			$s      = preg_match( '/\bbalance\s+(?:is\s+)?due\s+(?:up)?on\s+completion\b/i', $text ) ? self::short_sentence( $pretax ) : self::lawful_sentence( $pretax );
			if ( -1 === $target ) {
				$notes = trim( $text . ( '' !== trim( $text ) ? "\n\n" : '' ) . $s );
			} elseif ( null !== $renamed ) {
				$lines[ $target ]['description'] = trim( $s . ( '' !== trim( $text ) ? "\n" . $text : '' ) );
			} else {
				$lines[ $target ]['description'] = trim( $text . ( '' !== trim( $text ) ? "\n" : '' ) . $s );
			}
			$wrote = $s;
		}
		return array( $notes, $terms, $lines, $removed, $wrote );
	}

	/**
	 * The document's words with every fixable over-the-limit ask taken out and, where none is left, the lawful
	 * sentence written: [ notes, terms, lines, removed[], wrote ]. An invoice is never rewritten.
	 */
	public static function fix_doc( array $doc, array $lines, int $job_pretax = 0 ): array {
		$notes = (string) ( $doc['notes'] ?? '' );
		$terms = (string) ( $doc['terms'] ?? '' );
		if ( self::is_invoice( $doc ) ) {
			return array( $notes, $terms, $lines, array(), '' );
		}
		$parts = array();
		$fb    = array();
		foreach ( $lines as $i => $l ) {
			$cents       = (int) round( (float) ( $l['qty'] ?? 1 ) * (float) ( $l['rate'] ?? 0 ) * 100 );
			$parts[ $i ] = array( 'name' => (string) ( $l['name'] ?? '' ), 'description' => (string) ( $l['description'] ?? '' ), 'zero' => 0 === $cents );
			$fb[]        = array( 'name' => $parts[ $i ]['name'], 'description' => $parts[ $i ]['description'], 'qty' => $l['qty'] ?? 1, 'unit_cost' => array( 'amount' => $l['rate'] ?? 0 ) );
		}

		$pre = $lines ? self::base( array( 'lines' => $fb, 'amount' => $doc['amount'] ?? 0, 'discount_total' => $doc['discount_total'] ?? 0 ) ) : self::base( $doc );
		list( $notes, $terms, $parts, $removed, $wrote ) = self::fix_parts( $notes, $terms, $parts, max( 0, $pre ), class_exists( 'ZEST_Terms_Note' ) && ZEST_Terms_Note::says_deposit( $doc ) );
		foreach ( $parts as $i => $p ) {
			if ( $p['zero'] ) {
				$lines[ $i ]['name']        = $p['name'];
				$lines[ $i ]['description'] = $p['description'];
			}
		}
		return array( (string) $notes, (string) $terms, $lines, $removed, $wrote );
	}

	/**
	 * What a person is told when a document was not sent or saved because it asks for too much up front (the words
	 * quoted, at most three).
	 */
	public static function refusal_text( array $found, string $kind, string $number, string $doing = 'sent' ): string {
		$found = self::blocking( $found );
		$what  = ( 'invoice' === $kind ? 'Invoice' : 'Estimate' ) . ( '' !== $number ? ' #' . $number : '' );
		$list  = array();
		foreach ( array_slice( $found, 0, 3 ) as $f ) {
			$w      = (string) $f['words'];
			$list[] = $f['where'] . ': "' . ( strlen( $w ) > 80 ? self::clip( $w, 77 ) . '...' : $w ) . '" (' . $f['why'] . ')';
		}
		$fixable = (bool) array_filter( $found, static function ( $f ) { return ! empty( $f['fixable'] ); } );
		return $what . ' asks for a deposit over the limit (' . ZEST_Deposit_Limit::describe() . '), so it was not ' . $doing . '. '
			. implode( '; ', $list ) . ( count( $found ) > 3 ? '; and ' . ( count( $found ) - 3 ) . ' more' : '' ) . '. '
			. ( $fixable ? 'Open it and press "Fix the deposit wording", then check it' . ( 'estimate' === $kind ? ' and approve it again.' : '.' ) : 'Change it by hand: the app does not change a priced line, the billing system\'s own deposit request, or words it cannot separate from the rest.' );
	}

	private static function clip( string $s, int $bytes ): string {
		if ( function_exists( 'mb_strcut' ) ) {
			return (string) mb_strcut( $s, 0, $bytes, 'UTF-8' );
		}
		return preg_match( '/^.{0,' . $bytes . '}/su', $s, $m ) ? $m[0] : substr( $s, 0, $bytes );
	}

	/** The same refusal without quoting the document (for Privacy View and notices). */
	public static function refusal_plain( string $kind, string $number, string $doing = 'sent' ): string {
		return ( 'invoice' === $kind ? 'Invoice' : 'Estimate' ) . ( '' !== $number ? ' #' . $number : '' )
			. ' asks for a deposit over the limit (' . ZEST_Deposit_Limit::describe() . '), so it was not ' . $doing
			. '. Open it in Estimates and press "Fix the deposit wording", or ask the salesperson or an admin to.';
	}
}
