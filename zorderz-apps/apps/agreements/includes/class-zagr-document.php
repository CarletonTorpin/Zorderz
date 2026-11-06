<?php
/**
 * ZAGR_Document - the request checked, the frozen document, and the words drawn from the template. [CORE]
 *
 * check(): every key of ensure()'s request, the schedule's arithmetic (deposit + balance = total = the contract
 * amount; a price above zero; the deposit within the lowest limit any applicable legal pack sets, a backstop: the
 * estimate works it out), and the doc_hash recomputed here:
 *
 *   doc_hash = lowercase hex SHA-256 of wp_json_encode( [ document, schedule, salesperson ],
 *              JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), each object's keys in this order:
 *     document    { number, date, lines: [ { name, description, qty, amount_cents } ], contract_cents, tax_cents, discount_cents }
 *     schedule    { deposit_cents, balance_cents, total_cents, plan_version }
 *     salesperson { name, registration }
 *   (values as given, keys put in that order; extra keys are not part of the hash.)
 *
 * The frozen document (sealed in the agreements row): the request's three parts, the lead-time groups it names (not
 * part of doc_hash), the buyer's name and job address, the job's state and the legal packs that apply there, the staff
 * answers the packs ask for, and a snapshot of the business facts, lead times and template version taken when the
 * draft was made, so a later change in Settings never changes words a customer is reading or has signed.
 *
 * The buyer's own answer to a pack's question (whether any buyer is 65 or older, where a pack asks it) is not in the
 * frozen document: it comes with each drawing ($ctx['age']: 'yes', 'no', or 'na' where nothing is asked) and picks the
 * notice. Before it is answered only the sections that don't depend on it are drawn (before_age()).
 *
 * agreement(): the agreement's sections, rendered for the day the buyer signs, and the SHA-256 of exactly those words
 * (the hash the Sign form sends back and the server checks).
 *
 * @package Zorderz\Agreements
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZAGR_Document {

	const NOT_SET = 'Not set (test mode)';

	/* ---------------------------------------------------------------- the legal packs of one agreement */

	/** The packs frozen into the agreement, root first. */
	public static function stack( array $doc ): array {
		return array_values( array_map( 'strval', (array) ( $doc['packs'] ?? array() ) ) );
	}

	/** Does the agreement ask the buyer whether any buyer is 65 or older? */
	public static function asks_age( array $doc ): bool {
		$q = ZAGR_Pack::questions( self::stack( $doc ) );
		return isset( $q['senior'] ) && 'buyer' === ( $q['senior']['asked_of'] ?? '' );
	}

	/** The buyer's answer as the flow keeps it: 'yes' | 'no', 'na' where the packs don't ask, '' not answered yet. */
	public static function norm_age( array $doc, string $age ): string {
		if ( ! self::asks_age( $doc ) ) {
			return 'na';
		}
		return in_array( $age, array( 'yes', 'no' ), true ) ? $age : '';
	}

	/** Every answer the packs read: the buyer's, and the staff answers frozen into the document. */
	public static function answers( array $doc, string $age ): array {
		$out = array();
		foreach ( (array) ( $doc['answers'] ?? array() ) as $k => $v ) {
			$out[ (string) $k ] = (string) $v;
		}
		$a = self::norm_age( $doc, $age );
		if ( 'na' !== $a ) {
			$out['senior'] = $a;
		}
		return $out;
	}

	/** The window the agreement prints for these answers ({ days, word, ordinal, title, … }), or null. */
	public static function window( array $doc, string $age ): ?array {
		if ( '' === self::norm_age( $doc, $age ) ) {
			return null;
		}
		return ZAGR_Pack::window( self::stack( $doc ), self::answers( $doc, $age ) );
	}

	/** Business days to cancel for these answers; 0 when they can't be worked out yet. */
	public static function window_days( array $doc, string $age ): int {
		$w = self::window( $doc, $age );
		return null === $w ? 0 : (int) $w['days'];
	}

	/** The last day to cancel when the period starts on $from_ymd; '' when it can't be worked out. */
	public static function deadline( array $doc, string $from_ymd, string $age ): string {
		if ( null === self::window( $doc, $age ) ) {
			return '';
		}
		return ZAGR_Pack::deadline( self::stack( $doc ), $from_ymd, self::answers( $doc, $age ) );
	}

	/* ---------------------------------------------------------------- the request */

	public static function canonical( array $document, array $schedule, array $salesperson ): array {
		$lines = array();
		foreach ( (array) ( $document['lines'] ?? array() ) as $l ) {
			$l       = (array) $l;
			$lines[] = array(
				'name'         => $l['name'] ?? null,
				'description'  => $l['description'] ?? null,
				'qty'          => $l['qty'] ?? null,
				'amount_cents' => $l['amount_cents'] ?? null,
			);
		}
		return array(
			array(
				'number'         => $document['number'] ?? null,
				'date'           => $document['date'] ?? null,
				'lines'          => $lines,
				'contract_cents' => $document['contract_cents'] ?? null,
				'tax_cents'      => $document['tax_cents'] ?? null,
				'discount_cents' => $document['discount_cents'] ?? null,
			),
			array(
				'deposit_cents' => $schedule['deposit_cents'] ?? null,
				'balance_cents' => $schedule['balance_cents'] ?? null,
				'total_cents'   => $schedule['total_cents'] ?? null,
				'plan_version'  => $schedule['plan_version'] ?? null,
			),
			array(
				'name'         => $salesperson['name'] ?? null,
				'registration' => $salesperson['registration'] ?? null,
			),
		);
	}

	public static function doc_hash( array $document, array $schedule, array $salesperson ): string {
		return hash( 'sha256', (string) wp_json_encode( self::canonical( $document, $schedule, $salesperson ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/** The request's yes/no/unknown: true, false or null (a missing key is null). */
	private static function tri( array $req, string $key ) {
		$v = $req[ $key ] ?? null;
		return ( true === $v || false === $v ) ? $v : null;
	}

	/**
	 * Check ensure()'s request. { ok, code, message, req } with code bad_request or hash_mismatch on failure; req is
	 * the request with its parts in the canonical shape, plus language_ok, region, packs and answers.
	 *
	 * language_ok: was the sale negotiated in the language the agreement is written in (true, false, or null when
	 * nobody asked yet). The key "english" is read the same way when language_ok is absent.
	 */
	public static function check( $req ): array {
		$bad = function ( string $why ) {
			return array( 'ok' => false, 'code' => 'bad_request', 'message' => $why, 'req' => null );
		};
		if ( ! is_array( $req ) ) {
			return $bad( 'The request is not a list of values.' );
		}
		foreach ( array( 'estimate_id', 'client_id', 'is_test', 'buyer', 'document', 'schedule', 'salesperson', 'doc_hash' ) as $k ) {
			if ( ! array_key_exists( $k, $req ) ) {
				return $bad( 'The request has no ' . $k . '.' );
			}
		}
		if ( ! array_key_exists( 'language_ok', $req ) && array_key_exists( 'english', $req ) ) {
			$req['language_ok'] = $req['english'];
		}
		if ( ! array_key_exists( 'language_ok', $req ) ) {
			return $bad( 'The request has no language_ok.' );
		}
		if ( ! is_int( $req['estimate_id'] ) || $req['estimate_id'] <= 0 || ! is_int( $req['client_id'] ) || $req['client_id'] <= 0 ) {
			return $bad( 'The estimate and client ids must be whole numbers above 0.' );
		}
		if ( ! is_bool( $req['is_test'] ) || ! ( is_bool( $req['language_ok'] ) || null === $req['language_ok'] ) ) {
			return $bad( 'is_test must be true or false, and language_ok true, false, or null.' );
		}
		$b = $req['buyer'];
		if ( ! is_array( $b ) || ! is_string( $b['name'] ?? null ) || '' === trim( $b['name'] ) || strlen( $b['name'] ) > 200
			|| ! is_string( $b['email'] ?? null ) || ! is_email( $b['email'] ) || ! is_array( $b['address_lines'] ?? null ) || count( $b['address_lines'] ) > 6 ) {
			return $bad( 'The buyer needs a name, an email address, and address lines.' );
		}
		foreach ( $b['address_lines'] as $l ) {
			if ( ! is_string( $l ) || strlen( $l ) > 200 ) {
				return $bad( 'An address line is not text.' );
			}
		}
		if ( isset( $b['region'] ) && ! is_string( $b['region'] ) ) {
			return $bad( 'The buyer\'s region must be a two-letter state code.' );
		}
		$d = $req['document'];
		if ( ! is_array( $d ) || ! ( is_string( $d['number'] ?? null ) || is_int( $d['number'] ?? null ) ) || '' === (string) $d['number']
			|| ! is_string( $d['date'] ?? null ) || ! ZAGR_Calendar::valid( $d['date'] ) || ! is_array( $d['lines'] ?? null )
			|| ! $d['lines'] || count( $d['lines'] ) > 200 ) {
			return $bad( 'The document needs a number, a date (Y-m-d), and at least one line.' );
		}
		foreach ( $d['lines'] as $l ) {
			if ( ! is_array( $l ) || ! is_string( $l['name'] ?? null ) || ! is_string( $l['description'] ?? '' )
				|| ! is_numeric( $l['qty'] ?? null ) || ! is_int( $l['amount_cents'] ?? null ) ) {
				return $bad( 'Each line needs a name, a description, a quantity, and an amount in cents.' );
			}
		}
		foreach ( array( 'contract_cents', 'tax_cents', 'discount_cents' ) as $k ) {
			if ( ! is_int( $d[ $k ] ?? null ) || $d[ $k ] < 0 ) {
				return $bad( 'The document\'s ' . $k . ' must be a whole number of cents.' );
			}
		}
		$s = $req['schedule'];
		if ( ! is_array( $s ) || ! is_string( $s['plan_version'] ?? null ) ) {
			return $bad( 'The schedule needs a plan version.' );
		}
		foreach ( array( 'deposit_cents', 'balance_cents', 'total_cents' ) as $k ) {
			if ( ! is_int( $s[ $k ] ?? null ) || $s[ $k ] < 0 ) {
				return $bad( 'The schedule\'s ' . $k . ' must be a whole number of cents.' );
			}
		}
		if ( $s['total_cents'] <= 0 || $d['contract_cents'] <= 0 ) {
			return $bad( 'The price must be above zero.' );
		}
		if ( $s['deposit_cents'] + $s['balance_cents'] !== $s['total_cents'] || $s['total_cents'] !== $d['contract_cents'] ) {
			return $bad( 'The payments don\'t add up to the contract amount.' );
		}
		$region = ZAGR_Pack::region_of( $b );
		$stack  = ZAGR_Pack::stack_for( $region );
		$cap    = ZAGR_Pack::deposit_cap( $stack, $d['contract_cents'], $d['tax_cents'] );
		if ( null !== $cap && $s['deposit_cents'] > $cap ) {
			return $bad( 'The deposit is more than the legal pack for the job\'s state allows (' . self::money( $cap ) . ' for this contract).' );
		}
		if ( ZAGR_Pack::whole_dollars( $stack ) && 0 !== $s['deposit_cents'] % 100 ) {
			return $bad( 'The deposit must be in whole dollars.' );
		}
		$p = $req['salesperson'];
		if ( ! is_array( $p ) || ! is_string( $p['name'] ?? null ) || ! is_string( $p['registration'] ?? null ) ) {
			return $bad( 'The salesperson needs a name and a registration number (which may be empty).' );
		}
		if ( ! is_string( $req['doc_hash'] ) || ! preg_match( '/^[0-9a-f]{64}$/', $req['doc_hash'] ) ) {
			return $bad( 'doc_hash must be 64 lowercase hex characters.' );
		}
		if ( ! hash_equals( self::doc_hash( $d, $s, $p ), $req['doc_hash'] ) ) {
			return array( 'ok' => false, 'code' => 'hash_mismatch', 'message' => 'The document hash doesn\'t match the document, schedule, and salesperson sent.', 'req' => null );
		}
		// the lead-time groups on this estimate: optional, not part of doc_hash: [ { family, units } ]
		$groups = ZAGR_Settings::lead();
		$fam    = array();
		if ( array_key_exists( 'families', $req ) && null !== $req['families'] ) {
			if ( ! is_array( $req['families'] ) || count( $req['families'] ) > 20 ) {
				return $bad( 'families must be a list of { family, units }.' );
			}
			foreach ( $req['families'] as $f ) {
				if ( ! is_array( $f ) || ! is_string( $f['family'] ?? null ) || ! isset( $groups[ $f['family'] ] )
					|| ! is_int( $f['units'] ?? null ) || $f['units'] < 0 ) {
					return $bad( 'Each family must be one of the lead-time groups in Settings, with its units as a whole number.' );
				}
				$fam[ $f['family'] ] = ( $fam[ $f['family'] ] ?? 0 ) + $f['units'];
			}
			foreach ( $fam as $id => $u ) {
				if ( ! empty( $groups[ $id ]['per_unit'] ) && $u > 999 ) {
					return $bad( 'A group counted per unit must have 999 units or fewer.' );
				}
			}
			// units only count toward the dates for a per-unit group, so another group's large count is kept at 999
			$fam = array_map( static function ( $u ) { return min( 999, $u ); }, $fam );
		}
		// the staff answers the packs ask for (yes, no, or not answered): never assumed
		$answers = array();
		foreach ( ZAGR_Pack::questions( $stack ) as $qid => $q ) {
			if ( 'staff' !== ( $q['asked_of'] ?? '' ) ) {
				continue;
			}
			$v               = self::tri( $req, 'disaster' === $qid ? 'disaster_repair' : $qid );
			$answers[ $qid ] = true === $v ? 'yes' : ( false === $v ? 'no' : '' );
		}
		list( $cd, $cs, $cp ) = self::canonical( $d, $s, $p );
		$out                  = $req;
		$out['document']      = $cd;
		$out['schedule']      = $cs;
		$out['salesperson']   = $cp;
		$out['buyer']         = array(
			'name'          => trim( $b['name'] ),
			'email'         => trim( $b['email'] ),
			'address_lines' => array_values( array_map( 'trim', $b['address_lines'] ) ),
		);
		$out['region']   = $region;
		$out['packs']    = $stack;
		$out['answers']  = $answers;
		$out['families'] = array();
		foreach ( $groups as $k => $g ) {
			if ( isset( $fam[ $k ] ) ) {
				$out['families'][] = array( 'family' => $k, 'units' => $fam[ $k ] );
			}
		}
		return array( 'ok' => true, 'code' => 'ok', 'message' => '', 'req' => $out );
	}

	/* ---------------------------------------------------------------- the frozen document */

	/** What is sealed into the row: the request's parts, the buyer (no email: that is sealed on its own), the facts. */
	public static function freeze( array $req, bool $test_mode ): array {
		$f = ZAGR_Settings::facts();
		return array(
			'v'           => 1,
			'document'    => $req['document'],
			'schedule'    => $req['schedule'],
			'salesperson' => $req['salesperson'],
			'families'    => array_values( (array) ( $req['families'] ?? array() ) ),
			'buyer'       => array( 'name' => $req['buyer']['name'], 'address_lines' => $req['buyer']['address_lines'] ),
			'region'      => (string) ( $req['region'] ?? '' ),
			'packs'       => array_values( (array) ( $req['packs'] ?? array() ) ),
			'answers'     => (array) ( $req['answers'] ?? array() ),
			'facts'       => array(
				'company_name'             => $f['legal_name'],
				'address_lines'            => $f['address_lines'],
				'license'                  => $f['license'],
				'cancel_email'             => $f['cancel_email'],
				'help_phone'               => $f['help_phone'],
				'substantial_commencement' => $f['substantial_commencement'],
				'cgl_insurer'              => $f['cgl_insurer'],
				'cgl_phone'                => $f['cgl_phone'],
				'lead'                     => ZAGR_Settings::lead(),
				'subcontractors'           => $f['subcontractors'],
				'template_version'         => ZAGR_Settings::template_version(),
				'test'                     => $test_mode,
			),
		);
	}

	/* ---------------------------------------------------------------- drawing it */

	/** An amount in the business's currency sign ("$1,250", "$99.50"). */
	public static function money( int $cents ): string {
		$sign = '$';
		if ( class_exists( 'ZDZ_Business_Profile' ) ) {
			$sign = (string) ZDZ_Business_Profile::get( 'locale.currency_sign', '$' );
		}
		$neg = $cents < 0;
		$c   = abs( $cents );
		$s   = $sign . number_format( $c / 100, 0 === $c % 100 ? 0 : 2 );
		return $neg ? '-' . $s : $s;
	}

	private static function qty( $q ): string {
		$f = (float) $q;
		return floor( $f ) === $f ? (string) (int) $f : rtrim( rtrim( number_format( $f, 3, '.', '' ), '0' ), '.' );
	}

	private static function e( $s ): string {
		return ZAGR_Template::esc( (string) $s );
	}

	private static function lines_html( array $lines, int $contract_cents ): string {
		$h = '<table class="lines"><tr><th>Item</th><th>Qty</th><th>Amount</th></tr>';
		foreach ( $lines as $l ) {
			$name = self::e( $l['name'] );
			$desc = trim( (string) ( $l['description'] ?? '' ) );
			$h   .= '<tr><td><strong>' . $name . '</strong>' . ( '' !== $desc ? '<br>' . nl2br( self::e( $desc ), false ) : '' ) . '</td><td>'
				. self::e( self::qty( $l['qty'] ) ) . '</td><td>' . self::e( self::money( (int) $l['amount_cents'] ) ) . '</td></tr>';
		}
		return $h . '<tr><td><strong>Contract price</strong></td><td></td><td><strong>' . self::e( self::money( $contract_cents ) ) . '</strong></td></tr></table>';
	}

	public static function schedule_rows( array $schedule ): array {
		return array(
			array( ZAGR_Strings::t( 'pay_deposit' ), ZAGR_Strings::t( 'pay_deposit_due' ), (int) $schedule['deposit_cents'] ),
			array( ZAGR_Strings::t( 'pay_final' ), ZAGR_Strings::t( 'pay_final_due' ), (int) $schedule['balance_cents'] ),
		);
	}

	private static function schedule_html( array $schedule ): string {
		$h = '<table class="schedule"><tr><th>Payment</th><th>Due</th><th>Amount</th></tr>';
		foreach ( self::schedule_rows( $schedule ) as $r ) {
			$h .= '<tr><td><strong>' . self::e( $r[0] ) . '</strong></td><td>' . self::e( $r[1] ) . '</td><td>' . self::e( self::money( $r[2] ) ) . '</td></tr>';
		}
		return $h . '<tr><td><strong>' . self::e( ZAGR_Strings::t( 'pay_total' ) ) . '</strong></td><td></td><td><strong>' . self::e( self::money( (int) $schedule['total_cents'] ) ) . '</strong></td></tr></table>';
	}

	private static function lines_br( array $lines ): string {
		return implode( '<br>', array_map( array( __CLASS__, 'e' ), $lines ) );
	}

	const TO_BE_CONFIRMED = 'To be confirmed';

	/**
	 * [ Approximate Start Date, Approximate Completion Date ] for a document and a cancel-by day. The start is a range
	 * of calendar days after the cancellation period ends, from the slowest group present (the largest "from" to the
	 * largest "to"); completion is the range's end plus the days the work takes (each group's days; a per-unit group's
	 * days times its units; a mixed job adds them). No groups sent: "To be confirmed"; a group's lead time blanked in
	 * Settings: "Not set (test mode)". Calendar days, not business days: "approximate" dates may fall on a holiday.
	 */
	public static function dates( array $doc, string $cancel_by ): array {
		$fam = (array) ( $doc['families'] ?? array() );
		if ( ! $fam || '' === $cancel_by ) {
			return array( self::TO_BE_CONFIRMED, self::TO_BE_CONFIRMED );
		}
		$lead = is_array( $doc['facts']['lead'] ?? null ) ? $doc['facts']['lead'] : ZAGR_Settings::lead();
		$lo   = 0;
		$hi   = 0;
		$inst = 0;
		foreach ( $fam as $f ) {
			$l = $lead[ $f['family'] ] ?? null;
			if ( ! is_array( $l ) || '' === ( $l['min'] ?? '' ) || '' === ( $l['max'] ?? '' ) || '' === ( $l['install'] ?? '' ) ) {
				return array( self::NOT_SET, self::NOT_SET );
			}
			$lo    = max( $lo, (int) $l['min'] );
			$hi    = max( $hi, (int) $l['max'] );
			$inst += ! empty( $l['per_unit'] ) ? (int) $l['install'] * max( 1, (int) $f['units'] ) : (int) $l['install'];
		}
		$from  = ZAGR_Calendar::add_days( $cancel_by, $lo );
		$to    = ZAGR_Calendar::add_days( $cancel_by, max( $lo, $hi ) );
		$done  = ZAGR_Calendar::add_days( $to, $inst );
		$start = $from === $to ? 'On or about ' . ZAGR_Calendar::long_date( $from ) : 'Between ' . ZAGR_Calendar::long_date( $from ) . ' and ' . ZAGR_Calendar::long_date( $to );
		return array( $start, 'On or about ' . ZAGR_Calendar::long_date( $done ) );
	}

	/**
	 * What a change order changes: the scope (each item added, removed or changed), the amount added to or subtracted
	 * from the contract price, the effect on the payments, and the effect on the completion date. Built from the
	 * allowed tags only, every value escaped.
	 */
	public static function change_summary_html( array $old, array $new, string $cancel_by = '' ): string {
		$key  = function ( $l ) { return strtolower( trim( (string) $l['name'] ) ) . '|' . strtolower( trim( (string) ( $l['description'] ?? '' ) ) ); };
		$was  = array();
		foreach ( $old['document']['lines'] as $l ) {
			$was[ $key( $l ) ][] = $l;
		}
		$rows = array();
		foreach ( $new['document']['lines'] as $l ) {
			$k = $key( $l );
			if ( ! empty( $was[ $k ] ) ) {
				$o = array_shift( $was[ $k ] );
				if ( self::qty( $o['qty'] ) !== self::qty( $l['qty'] ) || (int) $o['amount_cents'] !== (int) $l['amount_cents'] ) {
					$rows[] = array( 'Changed', $l['name'], self::qty( $o['qty'] ) . ' to ' . self::qty( $l['qty'] ), self::money( (int) $o['amount_cents'] ) . ' to ' . self::money( (int) $l['amount_cents'] ) );
				}
				continue;
			}
			$rows[] = array( 'Added', $l['name'], self::qty( $l['qty'] ), self::money( (int) $l['amount_cents'] ) );
		}
		foreach ( $was as $left ) {
			foreach ( $left as $o ) {
				$rows[] = array( 'Removed', $o['name'], self::qty( $o['qty'] ), self::money( -1 * (int) $o['amount_cents'] ) );
			}
		}
		$h = '<table class="changes"><tr><th>Change</th><th>Item</th><th>Qty</th><th>Amount</th></tr>';
		if ( ! $rows ) {
			$h .= '<tr><td>No change to the items</td><td></td><td></td><td></td></tr>';
		}
		foreach ( $rows as $r ) {
			$h .= '<tr><td>' . self::e( $r[0] ) . '</td><td>' . self::e( $r[1] ) . '</td><td>' . self::e( $r[2] ) . '</td><td>' . self::e( $r[3] ) . '</td></tr>';
		}
		$diff  = (int) $new['schedule']['total_cents'] - (int) $old['schedule']['total_cents'];
		$delta = 0 === $diff ? 'No change to the contract price' : ( $diff > 0 ? 'Adds ' . self::money( $diff ) . ' to the contract price' : 'Subtracts ' . self::money( -$diff ) . ' from the contract price' );
		$h    .= '</table><p><strong>' . self::e( $delta ) . '</strong></p><table class="changes-totals"><tr><th>What</th><th>Before</th><th>Now</th></tr>';
		foreach ( array( array( 'Contract price', 'total_cents' ), array( ZAGR_Strings::t( 'pay_deposit' ), 'deposit_cents' ), array( ZAGR_Strings::t( 'pay_final' ), 'balance_cents' ) ) as $r ) {
			$h .= '<tr><td><strong>' . self::e( $r[0] ) . '</strong></td><td>' . self::e( self::money( (int) $old['schedule'][ $r[1] ] ) ) . '</td><td>' . self::e( self::money( (int) $new['schedule'][ $r[1] ] ) ) . '</td></tr>';
		}
		$was_done = self::dates( $old, $cancel_by )[1];
		$now_done = self::dates( $new, $cancel_by )[1];
		$h       .= '<tr><td><strong>Approximate completion date</strong></td><td>' . self::e( $was_done ) . '</td><td>' . self::e( $now_done === $was_done ? $now_done . ' (no change)' : $now_done ) . '</td></tr>';
		return $h . '</table>';
	}

	/** What the buyer answered, as the agreement and the record say it. */
	public static function age_answer( string $age ): string {
		$map = array( 'no' => 'no buyer is 65 or older', 'yes' => 'a buyer is 65 or older' );
		return $map[ $age ] ?? '';
	}

	/** "[to come]" for an insurer fact still to come. */
	const TO_COME = '[to come]';

	/**
	 * Every placeholder's value. $ctx: sign_date (Y-m-d, local: the day the buyer signs, today before signing), age
	 * ('yes' | 'no' | 'na' | '' not answered yet), signed (bool: the filled-in copy after signing; before it, the signed
	 * date reads "Not signed yet" and the deadline reads "the third business day after you receive your signed copy
	 * (if you sign today, …)"), cancel_by (the real one, once known), contractor_uid, contractor_signed_at (UTC or ''),
	 * url, old (the document a change order replaces, or null).
	 */
	public static function values( array $doc, array $ctx ): array {
		$f       = $doc['facts'];
		$set     = function ( $v ) { return ( '' === $v || null === $v || array() === $v ) ? self::NOT_SET : $v; };
		$sign    = (string) $ctx['sign_date'];
		$signed  = ! empty( $ctx['signed'] );
		$age     = (string) ( $ctx['age'] ?? '' );
		$win     = self::window( $doc, $age );
		$days    = null === $win ? 0 : (int) $win['days'];
		$known   = isset( $ctx['cancel_by'] ) && '' !== (string) $ctx['cancel_by'];
		$cancel  = $known ? (string) $ctx['cancel_by'] : ( $days > 0 ? self::deadline( $doc, $sign, $age ) : '' );
		list( $start, $done ) = self::dates( $doc, $cancel );
		$c_uid   = (int) ( $ctx['contractor_uid'] ?? 0 );
		$c_at    = (string) ( $ctx['contractor_signed_at'] ?? '' );
		$company = '' !== trim( (string) $f['company_name'] ) ? (string) $f['company_name'] : self::NOT_SET;
		// the Yes / No box a pack may ask for; the PDF draws the boxes (ZAGR_PDF)
		$boxes   = array( 'yes' => "\u{2612} Yes \u{2610} No", 'no' => "\u{2610} Yes \u{2612} No", '' => "\u{2610} Yes \u{2610} No" );
		if ( '' === $cancel ) {
			$cancel_words = isset( $ctx['old'] ) ? 'the last day of your cancellation period' : '';
		} elseif ( $signed || $known ) {
			$cancel_words = ZAGR_Calendar::long_date( $cancel );
		} else {
			$cancel_words = 'the ' . (string) ( $win['ordinal'] ?? '' ) . ' business day after you receive your signed copy (if you sign today, ' . ZAGR_Calendar::long_date( $cancel ) . ')';
		}
		$refund = ZAGR_Pack::refund( self::stack( $doc ) );
		$addr   = $f['address_lines'] ? self::lines_br( $f['address_lines'] ) : self::e( self::NOT_SET );
		$vals   = array(
			'company_name'             => $company,
			'company_address_html'     => $addr,
			'company_address_line'     => $f['address_lines'] ? implode( ', ', array_map( 'strval', $f['address_lines'] ) ) : self::NOT_SET,
			'license_number'           => $set( $f['license'] ),
			'cancel_email'             => $set( $f['cancel_email'] ),
			'help_phone'               => $set( $f['help_phone'] ),
			'salesperson_name'         => '' !== trim( (string) $doc['salesperson']['name'] ) ? $doc['salesperson']['name'] : $company,
			'salesperson_registration' => '' !== trim( (string) ( $doc['salesperson']['registration'] ?? '' ) ) ? $doc['salesperson']['registration'] : 'Not applicable',
			'buyer_name'               => $doc['buyer']['name'],
			'job_address_html'         => self::lines_br( $doc['buyer']['address_lines'] ),
			'contract_price'           => self::money( (int) $doc['document']['contract_cents'] ),
			'finance_charge'           => 'None',
			'project_lines_html'       => self::lines_html( $doc['document']['lines'], (int) $doc['document']['contract_cents'] ),
			'downpayment'              => self::money( (int) $doc['schedule']['deposit_cents'] ),
			'schedule_table_html'      => self::schedule_html( $doc['schedule'] ),
			'substantial_commencement' => $set( $f['substantial_commencement'] ),
			'approx_start'             => $start,
			'approx_completion'        => $done,
			'subcontractors'           => $boxes[ (string) ( $f['subcontractors'] ?? '' ) ] ?? $boxes[''],
			'window_days'              => $days > 0 ? (string) $days : '',
			'window_title'             => (string) ( $win['title'] ?? '' ),
			'window_days_word'         => (string) ( $win['word'] ?? '' ),
			'window_day_ordinal'       => (string) ( $win['ordinal'] ?? '' ),
			'age_answer'               => self::age_answer( $age ),
			'cgl_insurer'              => '' !== trim( (string) ( $f['cgl_insurer'] ?? '' ) ) ? (string) $f['cgl_insurer'] : self::TO_COME,
			'cgl_phone'                => '' !== trim( (string) ( $f['cgl_phone'] ?? '' ) ) ? (string) $f['cgl_phone'] : self::TO_COME,
			'refund_days'              => null === $refund ? '' : (int) $refund['days'] . ' ' . (string) ( $refund['unit'] ?? 'days' ),
			'pack_label'               => ZAGR_Pack::label( self::stack( $doc ) ),
			'cancel_by'                => $cancel_words,
			'signed_date'              => $signed ? ZAGR_Calendar::long_date( $sign ) : 'Not signed yet',
			'contractor_signer'        => $c_uid > 0 ? ( ZAGR_People::name( $c_uid ) ?: $company ) : 'Not signed yet',
			'contractor_signed_date'   => '' !== $c_at ? ZAGR_Calendar::long_date( ZAGR_Clock::local_day( ZAGR_Clock::ts( $c_at ) ) ) : 'Not signed yet',
			'agreement_url'            => (string) ( $ctx['url'] ?? '' ),
			'template_version'         => (string) $f['template_version'],
			'change_summary_html'      => isset( $ctx['old'] ) && is_array( $ctx['old'] ) ? self::change_summary_html( $ctx['old'], $doc, $cancel ) : '',
		);
		// the legal packs' notices, filled from the same values (a notice never holds another block)
		$sources = ZAGR_Pack::notice_sources( self::stack( $doc ) );
		foreach ( ZAGR_Pack::SLOTS as $slot => $ph ) {
			$vals[ $ph ] = isset( $sources[ $slot ] ) ? ZAGR_Template::render( $sources[ $slot ], $vals ) : '';
		}
		return $vals;
	}

	/** @throws ZAGR_Template_Error */
	private static function template_for( array $doc ): array {
		return ZAGR_Template::load( (string) $doc['facts']['template_version'] );
	}

	/**
	 * The agreement for a sign day and an answer: { sections: name => html, html, hash, values, template_files }.
	 * The agreement is never drawn before every answer its packs need.
	 * @throws ZAGR_Template_Error
	 */
	public static function agreement( array $doc, array $ctx ): array {
		if ( null === self::window( $doc, (string) ( $ctx['age'] ?? '' ) ) ) {
			throw new InvalidArgumentException( 'The agreement is drawn only once every answer its legal packs need is given.' );
		}
		$tpl  = self::template_for( $doc );
		$vals = self::values( $doc, $ctx );
		$out  = array();
		$all  = '';
		foreach ( ZAGR_Template::order( $tpl ) as $s ) {
			$out[ $s ] = ZAGR_Template::render( $tpl['sections'][ $s ], $vals );
			$all      .= '<section data-s="' . $s . '">' . "\n" . $out[ $s ] . "\n</section>\n";
		}
		return array( 'sections' => $out, 'html' => $all, 'hash' => hash( 'sha256', $all ), 'values' => $vals, 'template_files' => $tpl['files_hash'],
			'layout' => ZAGR_Template::layout( $tpl ), 'age_sections' => ZAGR_Template::age_sections( $tpl ) );
	}

	/**
	 * Before the buyer answers, only the sections whose words don't depend on it: { sections: name => html }. The page
	 * shows the question and the rule in place of the rest.
	 * @throws ZAGR_Template_Error
	 */
	public static function before_age( array $doc, array $ctx ): array {
		$tpl  = self::template_for( $doc );
		$vals = self::values( $doc, array( 'age' => '' ) + $ctx );
		// the two dates count from the cancel-by day, so before the answer they read as a short note
		$vals['approx_start'] = $vals['approx_completion'] = ZAGR_Strings::t( 'dates_after_age' );
		$ages = ZAGR_Template::age_sections( $tpl );
		$out  = array();
		foreach ( ZAGR_Template::order( $tpl ) as $s ) {
			if ( ! in_array( $s, $ages, true ) ) {
				$out[ $s ] = ZAGR_Template::render( $tpl['sections'][ $s ], $vals );
			}
		}
		return array( 'sections' => $out, 'order' => ZAGR_Template::order( $tpl ), 'age_sections' => $ages, 'layout' => ZAGR_Template::layout( $tpl ) );
	}

	/** A change order's own words (the change-order fragment). @throws ZAGR_Template_Error */
	public static function change( array $doc, array $ctx ): array {
		$tpl  = self::template_for( $doc );
		$vals = self::values( $doc, $ctx );
		$html = ZAGR_Template::render( $tpl['sections']['change-order'], $vals );
		$all  = '<section data-s="change-order">' . "\n" . $html . "\n</section>\n";
		return array( 'sections' => array( 'change-order' => $html ), 'html' => $all, 'hash' => hash( 'sha256', $all ), 'values' => $vals, 'layout' => ZAGR_Template::layout( $tpl ) );
	}

	/** The electronic-signature consent's words and their hash. @throws ZAGR_Template_Error */
	public static function consent( array $doc, array $ctx ): array {
		$tpl  = self::template_for( $doc );
		$html = ZAGR_Template::render( $tpl['sections']['consent'], self::values( $doc, $ctx ) );
		return array( 'html' => $html, 'hash' => hash( 'sha256', $html ), 'version' => $tpl['version'] );
	}
}
