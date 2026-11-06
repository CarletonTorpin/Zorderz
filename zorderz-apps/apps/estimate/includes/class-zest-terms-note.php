<?php
/**
 * ZEST_Terms_Note - the payment schedule in the estimate's notes, and this app's side of the seam to the
 * customer-agreements app (ZAGR_API).
 *
 * A billing system carries an estimate's notes onto the invoice it becomes, so the schedule lives in the notes, as one
 * block the app writes and recognises:
 *
 *   PAYMENT SCHEDULE
 *
 *   Deposit - $500, due when you sign your agreement.
 *   We place your order after your cancellation period ends.
 *
 *   Final payment - $4,500, due when the work is done.
 *
 *   Total: $5,000
 *
 *   Review and sign your agreement: https://<this site>/agreement/<words>/
 *
 * Every line's words are document-conventions data (estimate.schedule: heading, deposit_line, window_line, final_line,
 * total_line, tax_included_suffix, promise_line, link_lead, phone_lead), with the neutral English above as Core's
 * default; a promise line is the business's own and is empty by default. The last line is the agreement's link when the
 * agreements app has one, else "Questions about your payments? Call <phone>." (the business's phone). The amounts are
 * ZEST_Payment_Plan's. Nothing here is a model's.
 *
 *   the switch   option zest_payment_notes_mode: off / test / on, default test = test customers only (the name starts
 *                with the test-customer mark: option zest_test_fixture, else the agreements app's zagr_test_fixture).
 *   our block    only the WHOLE block, every line in order (the amounts and the last line may vary), is the app's own
 *                (spans). A heading outside it (with whatever follows) is the customer's words: never edited or removed,
 *                and Approve and Send refuse while it is there rather than add a second schedule (lookalike).
 *   reading      a block exactly as this estimate should read is taken out before the deposit reader reads the notes
 *                (unread), Fix writes no second deposit sentence beside it (says_deposit), and the sale form offers its
 *                deposit as the estimate's own figure (sale_form_part).
 *   the seam     ZAGR_API (SEAM '1'), only when the class exists, always inside try/catch: anything that goes wrong is
 *                "no agreement". request() builds ensure()'s request from the estimate (the app's own location line is
 *                left out of the document; the buyer is the job's address as a contract prints it), and the language
 *                answer ("was the sale made in the agreement's language?") is kept per estimate.
 *
 * No customer words or figures are logged. Every word is cut of gate codes (ZDZ_Sealed) before it leaves.
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ZEST_Terms_Note {

	const MODE_OPTION   = 'zest_payment_notes_mode';
	const TEST_OPTION   = 'zest_test_fixture';
	const LANG_OPTION   = 'zest_agr_language_';   // + estimate id: { answer: yes|no, by, at }
	const SIGNED_OPTION = 'zest_agr_signed_';     // + estimate id: { key, signed, by, at, salesperson, seller }
	const STAFF_OPTION  = 'zest_agr_answer_';     // + estimate id: { <question id>: yes|no } the staff questions the packs ask
	const TERMS         = 'Payments as scheduled in the notes';
	const SEAM          = '1';

	const DEFAULTS = array(
		'heading'             => 'PAYMENT SCHEDULE',
		'deposit_line'        => 'Deposit - {amount}, due when you sign your agreement.',
		'window_line'         => 'We place your order after your cancellation period ends.',
		'final_line'          => 'Final payment - {amount}, due when the work is done.',
		'total_line'          => 'Total: {amount}',
		'tax_included_suffix' => ' (tax included)',
		'promise_line'        => '',
		'link_lead'           => 'Review and sign your agreement: ',
		'phone_lead'          => 'Questions about your payments? Call ',
	);

	/** An amount as ZEST_Payment_Plan::money() writes it. */
	const MONEY = '\$(?:0|[1-9][0-9]{0,2}(?:,[0-9]{3})*)(?:\.[0-9]{2})?';
	const EOL   = '[ \t\r]*\n';

	private static $words = null;

	/* ================================================================ settings */

	public static function mode(): string {
		$m = function_exists( 'get_option' ) ? strtolower( trim( (string) get_option( self::MODE_OPTION, 'test' ) ) ) : 'test';
		return in_array( $m, array( 'off', 'test', 'on' ), true ) ? $m : 'test';
	}

	/** The wording in force (document conventions over the defaults). */
	public static function words(): array {
		if ( null !== self::$words ) {
			return self::$words;
		}
		$w = self::DEFAULTS;
		if ( class_exists( 'ZDZ_Doc_Conventions' ) && method_exists( 'ZDZ_Doc_Conventions', 'get' ) ) {
			$c = ZDZ_Doc_Conventions::get( 'estimate.schedule', array() );
			foreach ( is_array( $c ) ? $c : array() as $k => $v ) {
				if ( isset( $w[ $k ] ) && is_string( $v ) && strlen( $v ) <= 300 && false === strpos( $v, "\n" ) ) {
					$w[ $k ] = $v;
				}
			}
		}
		foreach ( array( 'deposit_line', 'final_line', 'total_line' ) as $k ) {
			if ( false === strpos( $w[ $k ], '{amount}' ) ) {
				$w[ $k ] = self::DEFAULTS[ $k ];
			}
		}
		if ( '' === trim( $w['heading'] ) ) {
			$w['heading'] = self::DEFAULTS['heading'];
		}
		return self::$words = $w;
	}

	public static function reset(): void {
		self::$words = null;
	}

	/** The business's phone for the last line (Business Profile), digits and phone punctuation only, or ''. */
	public static function phone(): string {
		$p = '';
		if ( class_exists( 'ZDZ_Business_Profile' ) && method_exists( 'ZDZ_Business_Profile', 'get' ) ) {
			$p = trim( (string) ZDZ_Business_Profile::get( 'contact.phone', '' ) );
		}
		$p = (string) apply_filters( 'zest_help_phone', $p );
		return ( '' !== $p && strlen( $p ) <= 30 && preg_match( '/^\+?[0-9][0-9 ().\-]*[0-9]\z/', $p ) ) ? $p : '';
	}

	/** The mark a test customer's name starts with. */
	public static function test_mark(): string {
		$m = function_exists( 'get_option' ) ? trim( (string) get_option( self::TEST_OPTION, '' ) ) : '';
		if ( '' === $m && function_exists( 'get_option' ) ) {
			$m = trim( (string) get_option( 'zagr_test_fixture', '' ) );
		}
		return '' !== $m ? $m : 'Test Customer';
	}

	/** Is this a test customer's estimate (by the name or the organization on it)? */
	public static function is_test( string $name, string $org = '' ): bool {
		$mark = strtolower( self::test_mark() );
		foreach ( array( $name, $org ) as $n ) {
			if ( '' !== trim( $n ) && 0 === strpos( strtolower( trim( $n ) ), $mark ) ) {
				return true;
			}
		}
		return false;
	}

	public static function is_test_doc( array $doc ): bool {
		$name = trim( (string) ( $doc['fname'] ?? '' ) . ' ' . (string) ( $doc['lname'] ?? '' ) );
		if ( '' === $name ) {
			$name = (string) ( $doc['customer_name'] ?? '' );
		}
		return self::is_test( $name, (string) ( $doc['organization'] ?? ( $doc['customer_org'] ?? '' ) ) );
	}

	/** Does the switch want a schedule on this estimate? */
	public static function wanted( array $doc ): bool {
		$m = self::mode();
		return 'on' === $m || ( 'test' === $m && self::is_test_doc( $doc ) );
	}

	/* ================================================================ the block */

	public static function phone_line(): string {
		$p = self::phone();
		return '' !== $p ? self::words()['phone_lead'] . $p . '.' : '';
	}

	/** An agreement link the notes may carry: https, this site, /agreement/<3 to 12 words>/. */
	public static function valid_link( string $url ): bool {
		if ( ! preg_match( '#^https://([a-z0-9.\-]+)/agreement/[a-z0-9]+(?:-[a-z0-9]+){2,11}/\z#', $url, $m ) ) {
			return false;
		}
		$own = function_exists( 'home_url' ) ? strtolower( (string) parse_url( (string) home_url(), PHP_URL_HOST ) ) : '';
		return '' !== $own && $own === $m[1];
	}

	public static function link_line( string $url ): string {
		return self::words()['link_lead'] . $url;
	}

	private static function fill( string $t, int $cents ): string {
		return str_replace( '{amount}', ZEST_Payment_Plan::money( $cents ), $t );
	}

	/** The block, exactly. */
	public static function render( array $plan, string $last ): string {
		$w     = self::words();
		$lines = array( $w['heading'], '', self::fill( $w['deposit_line'], (int) $plan['deposit_cents'] ) );
		if ( '' !== trim( $w['window_line'] ) ) {
			$lines[] = $w['window_line'];
		}
		array_push( $lines, '', self::fill( $w['final_line'], (int) $plan['balance_cents'] ), '',
			self::fill( $w['total_line'], (int) $plan['total_cents'] ) . ( ! empty( $plan['tax_included'] ) ? $w['tax_included_suffix'] : '' ) );
		if ( '' !== trim( $w['promise_line'] ) ) {
			array_push( $lines, '', $w['promise_line'] );
		}
		if ( '' !== $last ) {
			array_push( $lines, '', $last );
		}
		return implode( "\n", $lines );
	}

	/** The whole block in its own shape, every line in order (the amounts and the last line may vary). */
	private static function shape(): string {
		$w   = self::words();
		$e   = self::EOL;
		$b   = '[ \t\r]*\n';
		$amt = static function ( string $t ): string {
			$p = explode( '{amount}', $t, 2 );
			return preg_quote( $p[0], '/' ) . self::MONEY . preg_quote( $p[1] ?? '', '/' );
		};
		$re  = '/(?<![^\n])' . preg_quote( $w['heading'], '/' ) . $e . $b . $amt( $w['deposit_line'] ) . $e;
		if ( '' !== trim( $w['window_line'] ) ) {
			$re .= preg_quote( $w['window_line'], '/' ) . $e;
		}
		$re .= $b . $amt( $w['final_line'] ) . $e . $b . $amt( $w['total_line'] ) . '(?:' . preg_quote( $w['tax_included_suffix'], '/' ) . ')?';
		if ( '' !== trim( $w['promise_line'] ) ) {
			$re .= $e . $b . preg_quote( $w['promise_line'], '/' );
		}
		$re .= '(?:' . $e . $b . '(?:' . preg_quote( $w['link_lead'], '/' ) . 'https:\/\/[a-z0-9.\-]+\/agreement\/[a-z0-9]+(?:-[a-z0-9]+){2,11}\/'
			. '|' . preg_quote( $w['phone_lead'], '/' ) . '\+?[0-9][0-9 ().\-]{0,28}[0-9]\.))?[ \t\r]*(?![^\n])/';
		return $re;
	}

	/** Where the app's own blocks are in some notes: [ [ start, length ] ]. */
	public static function spans( string $notes ): array {
		if ( false === strpos( $notes, self::words()['heading'] ) || ! preg_match_all( self::shape(), $notes, $m, PREG_OFFSET_CAPTURE ) ) {
			return array();
		}
		$out = array();
		foreach ( $m[0] as $hit ) {
			$out[] = array( (int) $hit[1], strlen( $hit[0] ) );
		}
		return $out;
	}

	/** The same block, a CR or a line's trailing spaces aside. */
	public static function same( string $a, string $b ): bool {
		$n = static function ( string $s ): string {
			return (string) preg_replace( '/[ \t]+$/m', '', str_replace( "\r", '', $s ) );
		};
		return $n( $a ) === $n( $b );
	}

	public static function has_block( string $notes ): bool {
		return (bool) self::spans( $notes );
	}

	public static function block_text( string $notes ): string {
		$s = self::spans( $notes );
		return $s ? substr( $notes, $s[0][0], $s[0][1] ) : '';
	}

	/** A heading outside the app's own blocks: a schedule the customer's words hold. */
	public static function lookalike( string $notes ): bool {
		$rest = $notes;
		$sp   = self::spans( $notes );
		for ( $k = count( $sp ) - 1; $k >= 0; $k-- ) {
			$rest = substr( $rest, 0, $sp[ $k ][0] ) . "\n" . substr( $rest, $sp[ $k ][0] + $sp[ $k ][1] );
		}
		$h = preg_quote( self::words()['heading'], '/' );
		return (bool) preg_match( '/^[ \t]*' . $h . '/mi', $rest );
	}

	private static function cut_span( string $notes, array $sp ): string {
		$a = $sp[0];
		$b = $sp[0] + $sp[1];
		while ( $a > 0 && ctype_space( $notes[ $a - 1 ] ) ) {
			$a--;
		}
		if ( 0 === $a ) {
			$len = strlen( $notes );
			while ( $b < $len && ctype_space( $notes[ $b ] ) ) {
				$b++;
			}
		}
		return substr( $notes, 0, $a ) . substr( $notes, $b );
	}

	/** $notes with $block in place of the first block (any second taken out), or after the notes; a look-alike: as they are. */
	public static function put( string $notes, string $block ): string {
		if ( self::lookalike( $notes ) ) {
			return $notes;
		}
		$sp = self::spans( $notes );
		if ( ! $sp ) {
			$t = rtrim( $notes );
			return '' === $t ? $block : $t . "\n\n" . $block;
		}
		for ( $k = count( $sp ) - 1; $k >= 1; $k-- ) {
			$notes = self::cut_span( $notes, $sp[ $k ] );
		}
		return substr( $notes, 0, $sp[0][0] ) . $block . substr( $notes, $sp[0][0] + $sp[0][1] );
	}

	/** $notes without any block of the app's own (a look-alike stays: it is the customer's). */
	public static function take_out( string $notes ): string {
		$sp = self::spans( $notes );
		for ( $k = count( $sp ) - 1; $k >= 0; $k-- ) {
			$notes = self::cut_span( $notes, $sp[ $k ] );
		}
		return $notes;
	}

	/* ================================================================ reading */

	/** Is the estimate's notes' block exactly as it should read? [ block text, plan ], else null. */
	public static function valid_block( array $doc, int $estimate_id = 0 ): ?array {
		$text = self::block_text( (string) ( $doc['notes'] ?? '' ) );
		if ( '' === $text ) {
			return null;
		}
		$plan = ZEST_Payment_Plan::build( $doc );
		if ( ! $plan['in_scope'] || ! $plan['ok'] ) {
			return null;
		}
		$plan['tax_included'] = self::tax_included( $doc );
		$lasts = array( self::phone_line() );
		$url   = $estimate_id > 0 ? self::link_for( $estimate_id ) : '';
		if ( '' !== $url ) {
			$lasts[] = self::link_line( $url );
		}
		foreach ( $lasts as $last ) {
			if ( self::same( self::render( $plan, $last ), $text ) ) {
				return array( $text, $plan );
			}
		}
		return null;
	}

	/** Does the estimate say its price includes tax? (a line named as document conventions estimate.schedule.tax_included_names). */
	public static function tax_included( array $doc ): bool {
		$names = class_exists( 'ZDZ_Doc_Conventions' ) ? (array) ZDZ_Doc_Conventions::get( 'estimate.schedule.tax_included_names', array() ) : array();
		foreach ( (array) ( $doc['lines'] ?? array() ) as $l ) {
			$p = ZEST_Payment_Plan::line( $l );
			foreach ( $names as $n ) {
				if ( null !== $p && is_string( $n ) && '' !== trim( $n ) && 0 === strcasecmp( trim( $p[0] ), trim( $n ) ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/** For the deposit reader: the document with a block exactly as it should read taken out of its notes (wanted only). */
	public static function unread( array $doc, int $estimate_id = 0 ): array {
		if ( false === strpos( (string) ( $doc['notes'] ?? '' ), self::words()['heading'] ) || ! self::wanted( $doc ) ) {
			return $doc;
		}
		$v = self::valid_block( $doc, $estimate_id );
		if ( null === $v ) {
			return $doc;
		}
		$at           = strpos( (string) $doc['notes'], $v[0] );
		$doc['notes'] = substr( (string) $doc['notes'], 0, $at ) . "\n" . substr( (string) $doc['notes'], $at + strlen( $v[0] ) );
		return $doc;
	}

	/** For the deposit reader's Fix: the notes already say the deposit (the app's own block, exactly right, wanted here). */
	public static function says_deposit( array $doc ): bool {
		return false !== strpos( (string) ( $doc['notes'] ?? '' ), self::words()['heading'] ) && self::wanted( $doc ) && null !== self::valid_block( $doc );
	}

	/** For the sale form: [ the notes without a valid block, its deposit as [ cents, 'Notes', the deposit line ] ] or [ notes, null ]. */
	public static function sale_form_part( string $text, array $doc ): array {
		$doc['notes'] = $text;
		$v = ( false === strpos( $text, self::words()['heading'] ) || ! self::wanted( $doc ) ) ? null : self::valid_block( $doc );
		if ( null === $v ) {
			return array( $text, null );
		}
		$at   = strpos( $text, $v[0] );
		$line = self::fill( self::words()['deposit_line'], (int) $v[1]['deposit_cents'] );
		return array( substr( $text, 0, $at ) . "\n" . substr( $text, $at + strlen( $v[0] ) ), array( (int) $v[1]['deposit_cents'], 'Notes', $line ) );
	}

	/* ================================================================ the seam */

	public static function seam(): bool {
		return class_exists( 'ZAGR_API' ) && defined( 'ZAGR_API::SEAM' ) && self::SEAM === (string) constant( 'ZAGR_API::SEAM' ) && is_callable( array( 'ZAGR_API', 'ensure' ) );
	}

	private static function answer( $r ): array {
		if ( ! is_array( $r ) ) {
			return array( 'ok' => false, 'code' => 'error', 'message' => '', 'url' => '', 'state' => '' );
		}
		return array(
			'ok'      => true === ( $r['ok'] ?? null ),
			'code'    => (string) ( $r['code'] ?? '' ),
			'message' => is_scalar( $r['message'] ?? null ) ? (string) $r['message'] : '',
			'url'     => is_string( $r['url'] ?? null ) ? $r['url'] : '',
			'state'   => is_string( $r['state'] ?? null ) ? $r['state'] : '',
		);
	}

	/** ZAGR_API::ensure() (Approve only), or "no agreement" ('absent' without the app, 'error' when it fails). */
	public static function ensure( array $req ): array {
		if ( ! self::seam() ) {
			return array( 'ok' => false, 'code' => 'absent', 'message' => '', 'url' => '', 'state' => '' );
		}
		try {
			return self::answer( ZAGR_API::ensure( $req ) );
		} catch ( \Throwable $e ) {
			error_log( 'Zorderz Estimates: the agreement for estimate id ' . (int) ( $req['estimate_id'] ?? 0 ) . ' could not be made ready (' . get_class( $e ) . ').' );
			return array( 'ok' => false, 'code' => 'error', 'message' => '', 'url' => '', 'state' => '' );
		}
	}

	public static function contractor_sign( int $estimate_id, int $user_id, string $doc_hash ): array {
		if ( ! self::seam() || ! is_callable( array( 'ZAGR_API', 'contractor_sign' ) ) ) {
			return array( 'ok' => false, 'code' => 'absent', 'message' => '', 'url' => '', 'state' => '' );
		}
		try {
			return self::answer( ZAGR_API::contractor_sign( $estimate_id, $user_id, $doc_hash ) );
		} catch ( \Throwable $e ) {
			error_log( 'Zorderz Estimates: the business\'s signature on estimate id ' . $estimate_id . ' could not be recorded (' . get_class( $e ) . ').' );
			return array( 'ok' => false, 'code' => 'error', 'message' => '', 'url' => '', 'state' => '' );
		}
	}

	/** { ok, state, signed_via, ok_to_order } ('none' = no agreement), or null when it can't be read. */
	public static function status_for( int $estimate_id ): ?array {
		if ( $estimate_id <= 0 || ! self::seam() || ! is_callable( array( 'ZAGR_API', 'status_for' ) ) ) {
			return null;
		}
		try {
			$s = ZAGR_API::status_for( $estimate_id );
		} catch ( \Throwable $e ) {
			return null;
		}
		if ( ! is_array( $s ) || ! is_string( $s['state'] ?? null ) || ! empty( $s['error'] ) ) {
			return null;
		}
		return array(
			'ok'          => true === ( $s['ok'] ?? null ),
			'state'       => $s['state'],
			'signed_via'  => is_string( $s['signed_via'] ?? null ) ? $s['signed_via'] : '',
			'ok_to_order' => ! empty( $s['ok_to_order'] ),
		);
	}

	/** The schedule as signed (or as offered before signing), or null: ZAGR_API::schedule_for, read only. */
	public static function schedule_for( int $estimate_id ): ?array {
		if ( $estimate_id <= 0 || ! self::seam() || ! is_callable( array( 'ZAGR_API', 'schedule_for' ) ) ) {
			return null;
		}
		try {
			$s = ZAGR_API::schedule_for( $estimate_id );
		} catch ( \Throwable $e ) {
			return null;
		}
		return is_array( $s ) && true === ( $s['ok'] ?? null ) ? $s : null;
	}

	/**
	 * The staff questions the job's legal packs ask (other than the language one), as the agreements app names them:
	 * [ question id => request key ] (the disaster-repair question is sent as disaster_repair). [] without the app.
	 */
	public static function staff_questions( string $region ): array {
		if ( ! class_exists( 'ZAGR_Pack' ) || ! method_exists( 'ZAGR_Pack', 'questions' ) ) {
			return array();
		}
		try {
			$qs = (array) ZAGR_Pack::questions( ZAGR_Pack::stack_for( strtoupper( trim( $region ) ) ) );
		} catch ( \Throwable $e ) {
			return array();
		}
		$out = array();
		foreach ( $qs as $qid => $q ) {
			if ( is_array( $q ) && 'staff' === ( $q['asked_of'] ?? '' ) && preg_match( '/^[a-z][a-z0-9_]{0,30}$/', (string) $qid ) ) {
				$out[ (string) $qid ] = 'disaster' === $qid ? 'disaster_repair' : (string) $qid;
			}
		}
		return $out;
	}

	/** A staff answer kept for one estimate: true, false, or null (not answered). */
	public static function staff_answer( int $id, string $qid ): ?bool {
		$v = $id > 0 ? get_option( self::STAFF_OPTION . $id, array() ) : array();
		$a = is_array( $v ) ? (string) ( $v[ $qid ] ?? '' ) : '';
		return 'yes' === $a ? true : ( 'no' === $a ? false : null );
	}

	public static function set_staff_answer( int $id, string $qid, bool $yes ): void {
		if ( $id <= 0 ) {
			return;
		}
		$v         = get_option( self::STAFF_OPTION . $id, array() );
		$v         = is_array( $v ) ? $v : array();
		$v[ $qid ] = $yes ? 'yes' : 'no';
		update_option( self::STAFF_OPTION . $id, $v, false );
	}

	/** The agreement's link for the notes, or '' (read only). */
	public static function link_for( int $estimate_id ): string {
		if ( $estimate_id <= 0 || ! self::seam() || ! is_callable( array( 'ZAGR_API', 'link_for' ) ) ) {
			return '';
		}
		try {
			$u = ZAGR_API::link_for( $estimate_id );
		} catch ( \Throwable $e ) {
			return '';
		}
		return is_string( $u ) && self::valid_link( $u ) ? $u : '';
	}

	private static function cut( string $s ): string {
		return class_exists( 'ZDZ_Sealed' ) ? (string) ZDZ_Sealed::cut( $s ) : $s;
	}

	/** The salesperson the estimate names (one person): { uid, name, registration }. */
	public static function seller( array $row ): array {
		$codes = class_exists( 'ZEST_Salesperson' ) ? ZEST_Salesperson::codes_for_row( $row ) : array();
		$uid   = ( $codes && class_exists( 'ZEST_Salesperson' ) ) ? ZEST_Salesperson::person_for_code( (string) $codes[0] ) : 0;
		$u     = $uid > 0 ? get_userdata( $uid ) : null;
		$reg   = $uid > 0 ? trim( (string) apply_filters( 'zest_salesperson_registration', '', $uid ) ) : '';
		return array( 'uid' => $uid, 'name' => $u ? (string) ( $u->display_name ?? '' ) : '', 'registration' => $reg );
	}

	/** The buyer: { name, email, address_lines[], region? } from this app's row (the job's address as a contract prints it). */
	public static function buyer( array $row ): array {
		$street = class_exists( 'ZEST_Contract_Address' )
			? ZEST_Contract_Address::street( (string) ( $row['customer_street'] ?? '' ), '', array( (string) ( $row['customer_phone'] ?? '' ) ) )
			: (string) ( $row['customer_street'] ?? '' );
		$city   = class_exists( 'ZEST_Contract_Address' )
			? ZEST_Contract_Address::city( (string) ( $row['customer_city'] ?? '' ), (string) ( $row['customer_zip'] ?? '' ) )
			: (string) ( $row['customer_city'] ?? '' );
		$state  = strtoupper( trim( (string) ( $row['customer_state'] ?? '' ) ) );
		$place  = trim( $city . ( '' !== $state ? ', ' . $state : '' ) . ' ' . trim( (string) ( $row['customer_zip'] ?? '' ) ) );
		$out    = array(
			'name'          => self::cut( trim( (string) ( $row['customer_name'] ?? '' ) ) ),
			'email'         => class_exists( 'ZEST_FreshBooks' ) ? ZEST_FreshBooks::clean_email( $row['customer_email'] ?? '' ) : (string) ( $row['customer_email'] ?? '' ),
			'address_lines' => array_values( array_filter( array( self::cut( $street ), self::cut( trim( $place, ' ,' ) ) ), 'strlen' ) ),
		);
		if ( preg_match( '/^[A-Z]{2}$/', $state ) ) {
			$out['region'] = $state;
		}
		return $out;
	}

	/** Is this billing-shape line one the app writes for itself (the location line), not part of the job? */
	private static function app_line( array $p ): bool {
		return 0 === (int) $p[3] && (bool) preg_match( '/^\s*(?:property\s+)?location\b/i', (string) $p[0] );
	}

	/** The request's document and schedule: what the customer signs. */
	public static function parts( array $doc, array $plan, string $number, string $date ): array {
		$lines = array();
		foreach ( (array) ( $doc['lines'] ?? array() ) as $l ) {
			$p = ZEST_Payment_Plan::line( $l );
			if ( null === $p || self::app_line( $p ) ) {
				continue;
			}
			$lines[] = array( 'name' => self::cut( $p[0] ), 'description' => self::cut( $p[1] ), 'qty' => ZEST_Payment_Plan::qty( $p[2] ), 'amount_cents' => (int) $p[3] );
		}
		$document = array(
			'number'         => $number,
			'date'           => preg_match( '/^\d{4}-\d{2}-\d{2}/', $date, $m ) ? $m[0] : '',
			'lines'          => $lines,
			'contract_cents' => (int) $plan['total_cents'],
			'tax_cents'      => (int) $plan['tax_cents'],
			'discount_cents' => (int) $plan['discount_cents'],
		);
		$schedule = array(
			'deposit_cents' => (int) $plan['deposit_cents'],
			'balance_cents' => (int) $plan['balance_cents'],
			'total_cents'   => (int) $plan['total_cents'],
			'plan_version'  => (string) $plan['version'],
		);
		return array( $document, $schedule );
	}

	/** The key of what the business signs, without the salesperson (so a renamed seller never stops a Send). */
	public static function signed_key( array $doc, array $plan, string $number, string $date ): string {
		return hash( 'sha256', (string) json_encode( self::parts( $doc, $plan, $number, $date ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * ensure()'s request for one estimate (row + the billing-shape document + its plan), doc_hash from the agreements
	 * app's own canonical form. Once the customer has signed, the salesperson is the one recorded when the business
	 * signed, while the same person sells it. null without the agreements app's document class.
	 */
	public static function request( array $row, array $doc, array $plan, ?bool $language_ok, array $answers = array() ): ?array {
		if ( ! class_exists( 'ZAGR_Document' ) || ! method_exists( 'ZAGR_Document', 'doc_hash' ) ) {
			return null;
		}
		$id     = (int) ( $row['id'] ?? 0 );
		$date   = (string) ( $doc['create_date'] ?? substr( (string) ( $row['created_at'] ?? '' ), 0, 10 ) );
		$number = (string) ( $row['billing_doc_num'] ?? '' );
		list( $document, $schedule ) = self::parts( $doc, $plan, $number, $date );
		$seller = self::seller( $row );
		$sp     = array( 'name' => $seller['name'], 'registration' => $seller['registration'] );
		$rec    = function_exists( 'get_option' ) ? get_option( self::SIGNED_OPTION . $id, null ) : null;
		if ( is_array( $rec ) && is_array( $rec['salesperson'] ?? null ) && (int) ( $rec['seller'] ?? -1 ) === $seller['uid'] ) {
			$st = self::status_for( $id );
			if ( null !== $st && in_array( $st['state'], array( 'signed', 'window_closed', 'change_pending' ), true ) ) {
				$sp = array( 'name' => (string) ( $rec['salesperson']['name'] ?? '' ), 'registration' => (string) ( $rec['salesperson']['registration'] ?? '' ) );
			}
		}
		$client = (string) ( $row['billing_cust_id'] ?? '' );
		$req    = array(
			'estimate_id' => $id,
			'client_id'   => ctype_digit( $client ) && (int) $client > 0 ? (int) $client : $id,   // a customer known only here is keyed by the estimate
			'is_test'     => self::is_test( (string) ( $row['customer_name'] ?? '' ), (string) ( $row['customer_org'] ?? '' ) ),
			'language_ok' => $language_ok,
			'buyer'       => self::buyer( $row ),
			'document'    => $document,
			'schedule'    => $schedule,
			'salesperson' => $sp,
			'families'    => ZEST_Payment_Plan::family_units( (array) ( $doc['lines'] ?? array() ) ), // phpcs:ignore PHPCompatibility.Extensions.RemovedExtensions.famRemoved -- ZEST_Payment_Plan::family_units() is a method of this app, not the removed fam extension
			'doc_hash'    => ZAGR_Document::doc_hash( $document, $schedule, $sp ),
		);
		foreach ( $answers as $k => $v ) {
			if ( preg_match( '/^[a-z][a-z0-9_]{0,30}$/', (string) $k ) && ( true === $v || false === $v || null === $v ) && ! isset( $req[ $k ] ) ) {
				$req[ $k ] = $v;   // a staff answer the packs ask for (disaster_repair: true, false or null, never assumed)
			}
		}
		return $req;
	}

	/** The answer to "was the sale made in the agreement's language?" for one estimate: true, false, or null. */
	public static function language( int $id ): ?bool {
		if ( $id <= 0 || ! function_exists( 'get_option' ) ) {
			return null;
		}
		$v = get_option( self::LANG_OPTION . $id, null );
		$a = is_array( $v ) ? (string) ( $v['answer'] ?? '' ) : '';
		return 'yes' === $a ? true : ( 'no' === $a ? false : null );
	}

	/** Keep the answer. A kept No changes only by an administrator. */
	public static function set_language( int $id, bool $yes, int $uid ): bool {
		if ( $id <= 0 ) {
			return false;
		}
		if ( false === self::language( $id ) && ! ( class_exists( 'ZEST_Salesperson' ) && ZEST_Salesperson::is_admin_tier( $uid ) ) ) {
			return false;
		}
		update_option( self::LANG_OPTION . $id, array( 'answer' => $yes ? 'yes' : 'no', 'by' => $uid, 'at' => gmdate( 'c' ) ), false );
		return true;
	}

	/** What Approve settled for this copy: 'signed', 'none' (no agreement wanted), or '' (nothing yet). */
	public static function settled( int $id, string $key ): string {
		$v = function_exists( 'get_option' ) ? get_option( self::SIGNED_OPTION . $id, null ) : null;
		if ( ! is_array( $v ) || ! is_string( $v['key'] ?? null ) || ! hash_equals( $v['key'], $key ) ) {
			return '';
		}
		return ! empty( $v['signed'] ) ? 'signed' : 'none';
	}

	public static function settle( int $id, string $key, bool $signed, int $uid, ?array $req = null, int $seller = 0 ): void {
		$was = get_option( self::SIGNED_OPTION . $id, null );
		$rec = array( 'key' => $key, 'signed' => $signed, 'by' => $uid, 'at' => gmdate( 'c' ) );
		if ( $signed && is_array( $req ) ) {
			$rec['salesperson'] = (array) ( $req['salesperson'] ?? array() );
			$rec['seller']      = $seller;
		} elseif ( is_array( $was ) && isset( $was['salesperson'] ) ) {
			$rec['salesperson'] = $was['salesperson'];
			$rec['seller']      = (int) ( $was['seller'] ?? 0 );
		}
		update_option( self::SIGNED_OPTION . $id, $rec, false );
	}
}
