<?php
/**
 * ZEST_Sealed - gate, lockbox, alarm, door and keypad codes on estimates go into the platform's one vault
 * (ZDZ_Sealed) and never travel in plain text: not in this app's stored copy, not to the billing system (notes, terms,
 * lines, the e-mail and its PDF), not to a model, not in a chat answer.
 *
 *   On the way in   what a salesperson types or says for a parse is cut before it is read by a model (zest_parse_input)
 *                   and the model's lines are cut again on the way back. A code is sealed at once under a HOLD
 *                   (['estimate', 'h<hex>']) because the estimate has no id yet; the hold rides with the preview
 *                   (estimate.seal_hold), is checked on create as this person's own, and is kept on the row (seal_hold).
 *   Stored          every write of this app's row is cut (zest_row_data): notes, input text, lines, rejected lines and
 *                   the customer's fields keep the label only ("[gate code sealed]").
 *   To billing      every write (ZEST_Billing_Doc::put, and the create payload) is cut, a new code sealed under
 *                   ['estimate', 'b<billing id>']; before the billing system e-mails an estimate its copy is checked and
 *                   any code there is sealed and taken out (scrub_before_send); when it can't be, nothing is sent.
 *   Show code       for_row() lists the codes kept for an estimate (its id, its hold, its billing id) beside the label,
 *                   with ZDZ_Sealed::info(): never the code. Opening is ZDZ_Sealed's REST route, which logs every opening.
 *   Who may open    the admin tier (ZDZ_Sealed decides); anyone else only when this estimate is theirs AND an app that
 *                   knows the visits says today is a visit for it (filter zest_sealed_estimate_visit( false, $estimate_id,
 *                   $uid ), answered by a jobs or scheduling app). Deny by default.
 *   Verbs           every estimate verb's answer is cut before it reaches the chat.
 *
 * Without ZDZ_Sealed nothing here changes a word.
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZEST_Sealed {

	const HOLD_TTL = 86400;

	/** This request's hold, once made. */
	private static $hold = '';

	public static function boot(): void {
		add_filter( 'zest_row_data', array( __CLASS__, 'row_data' ), 10, 2 );
		add_filter( 'zest_billing_outgoing', array( __CLASS__, 'outgoing' ), 10, 3 );
		add_filter( 'zest_billing_outgoing_estimate', array( __CLASS__, 'outgoing_estimate' ), 10, 2 );
		add_filter( 'zest_parse_input', array( __CLASS__, 'cut_in' ) );
		add_filter( 'zest_parse_result', array( __CLASS__, 'result_in' ) );
		add_filter( 'zdz_sealed_may_open', array( __CLASS__, 'may_open' ), 10, 3 );
		add_filter( 'zdz_register_capabilities', array( __CLASS__, 'guard_verbs' ), 1000 );
	}

	public static function on(): bool {
		return class_exists( 'ZDZ_Sealed' ) && method_exists( 'ZDZ_Sealed', 'ready' ) && ZDZ_Sealed::ready();
	}

	/* ------------------------------------------------------------------ cutting */

	/** $text with every code cut (sealed under $scope when one is given). */
	public static function cut( $text, $scope = null ): string {
		$text = (string) $text;
		if ( '' === $text || ! self::on() || ! ZDZ_Sealed::might_have_code( $text ) ) {
			return $text;
		}
		return (string) ZDZ_Sealed::cut( $text, $scope );
	}

	/** Every string in $v cut (arrays walked; keys kept). */
	public static function deep( $v, $scope = null ) {
		if ( is_string( $v ) ) {
			return self::cut( $v, $scope );
		}
		if ( is_array( $v ) ) {
			foreach ( $v as $k => $x ) {
				$v[ $k ] = self::deep( $x, $scope );
			}
		}
		return $v;
	}

	public static function has_code( $text ): bool {
		return self::on() && ZDZ_Sealed::might_have_code( (string) $text ) && ZDZ_Sealed::has_code( (string) $text );
	}

	/**
	 * Does any string in $v hold a code? Each string is read on its own: never the JSON of a whole structure, where a
	 * closing quote right after a number reads as a size ("36\"") and would hide a code.
	 */
	public static function any_code( $v ): bool {
		if ( is_string( $v ) ) {
			return self::has_code( $v );
		}
		if ( is_array( $v ) ) {
			foreach ( $v as $x ) {
				if ( self::any_code( $x ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/** A stored JSON column's strings: any code in them? */
	private static function json_has_code( string $json ): bool {
		$v = json_decode( $json, true );
		return is_array( $v ) ? self::any_code( $v ) : self::has_code( $json );
	}

	/* ------------------------------------------------------------------ the hold */

	/** This request's hold (made on first use and remembered as this person's for a day). */
	public static function hold(): string {
		if ( '' === self::$hold ) {
			self::$hold = 'h' . bin2hex( random_bytes( 8 ) );
			if ( function_exists( 'set_transient' ) ) {
				set_transient( 'zest_hold_' . self::$hold, (int) get_current_user_id(), self::HOLD_TTL );
			}
		}
		return self::$hold;
	}

	/** This request's hold if one was made, else ''. */
	public static function current_hold(): string {
		return self::$hold;
	}

	/** Use a hold a preview carried, when it is well formed and this person's own; else ''. */
	public static function adopt( string $hold ): string {
		if ( ! preg_match( '/^h[0-9a-f]{16}$/', $hold ) || ! function_exists( 'get_transient' ) ) {
			return '';
		}
		$who = (int) get_transient( 'zest_hold_' . $hold );
		if ( $who <= 0 || $who !== (int) get_current_user_id() ) {
			return '';
		}
		self::$hold = $hold;
		return $hold;
	}

	public static function reset(): void {
		self::$hold = '';
	}

	/** zest_parse_input: the words for a parse, codes sealed under this request's hold. */
	public static function cut_in( $text ) {
		return self::has_code( $text ) ? self::cut( $text, array( 'estimate', self::hold() ) ) : $text;
	}

	/** zest_parse_result: the parse answer cut again; the hold goes with the preview when anything was sealed. */
	public static function result_in( $res ) {
		if ( ! is_array( $res ) || ! self::on() ) {
			return $res;
		}
		if ( self::any_code( $res ) ) {
			$res = self::deep( $res, array( 'estimate', self::hold() ) );
		}
		if ( '' !== self::$hold && isset( $res['estimate'] ) && is_array( $res['estimate'] ) ) {
			$res['estimate']['seal_hold'] = self::$hold;
		}
		return $res;
	}

	/* ------------------------------------------------------------------ stored and outgoing */

	/**
	 * zest_row_data: every word column of a row write is cut. A new code is sealed under the row's own hold (a write that
	 * carries seal_hold: the row's, or '' for a new row, which then gets this request's hold).
	 */
	public static function row_data( $data, $uid = 0 ) {
		if ( ! is_array( $data ) || ! self::on() ) {
			return $data;
		}
		$has  = array_key_exists( 'seal_hold', $data );
		$hold = $has && preg_match( '/^h[0-9a-f]{16}$/', (string) $data['seal_hold'] ) ? (string) $data['seal_hold'] : '';
		$words = array( 'notes', 'input_text', 'terms', 'customer_name', 'customer_street', 'customer_city', 'reference', 'customer_org' );
		$found = false;
		foreach ( array_merge( $words, array( 'items_json', 'rejected_json' ) ) as $k ) {
			$v = $data[ $k ] ?? null;
			if ( is_string( $v ) && ( in_array( $k, $words, true ) ? self::has_code( $v ) : self::json_has_code( $v ) ) ) {
				$found = true;
				break;
			}
		}
		if ( ! $found ) {
			if ( $has && '' === $hold && '' !== self::$hold ) {
				$data['seal_hold'] = self::$hold;
			}
			return $data;
		}
		$scope = array( 'estimate', '' !== $hold ? $hold : self::hold() );
		foreach ( $words as $k ) {
			if ( isset( $data[ $k ] ) && is_string( $data[ $k ] ) ) {
				$data[ $k ] = self::cut( $data[ $k ], $scope );
			}
		}
		foreach ( array( 'items_json', 'rejected_json' ) as $k ) {
			if ( isset( $data[ $k ] ) && is_string( $data[ $k ] ) && self::json_has_code( $data[ $k ] ) ) {
				$v = json_decode( $data[ $k ], true );
				if ( is_array( $v ) ) {
					$data[ $k ] = wp_json_encode( self::deep( $v, $scope ) );
				}
			}
		}
		if ( $has && '' === $hold ) {
			$data['seal_hold'] = $scope[1];
		}
		return $data;
	}

	/** zest_billing_outgoing: a write's words, cut; a new code sealed under the billing document. */
	public static function outgoing( $fields, $kind = 'estimate', $id = '' ) {
		if ( ! is_array( $fields ) || ! self::on() ) {
			return $fields;
		}
		$scope = array( 'estimate', ( 'invoice' === $kind ? 'i' : 'b' ) . preg_replace( '/\D/', '', (string) $id ) );
		foreach ( array( 'notes', 'terms', 'po_number' ) as $k ) {
			if ( isset( $fields[ $k ] ) && is_string( $fields[ $k ] ) ) {
				$fields[ $k ] = self::cut( $fields[ $k ], $scope );
			}
		}
		if ( isset( $fields['lines'] ) && is_array( $fields['lines'] ) ) {
			foreach ( $fields['lines'] as $i => $l ) {
				foreach ( array( 'name', 'description' ) as $f ) {
					if ( is_array( $l ) && isset( $l[ $f ] ) && is_string( $l[ $f ] ) ) {
						$fields['lines'][ $i ][ $f ] = self::cut( $l[ $f ], $scope );
					}
				}
			}
		}
		return $fields;
	}

	/** zest_billing_outgoing_estimate: a new estimate's payload before the billing system has it (sealed under the hold). */
	public static function outgoing_estimate( $estimate, $ctx = array() ) {
		if ( ! is_array( $estimate ) || ! self::on() ) {
			return $estimate;
		}
		$hold = is_string( $estimate['seal_hold'] ?? null ) ? self::adopt( (string) $estimate['seal_hold'] ) : '';
		unset( $estimate['seal_hold'] );
		return self::deep( $estimate, array( 'estimate', '' !== $hold ? $hold : self::hold() ) );
	}

	/**
	 * Before the billing system e-mails an estimate: its copy is checked, and any code there is sealed and taken out.
	 * true when the copy is clean (or was cleaned), false when it could not be checked or cleaned (nothing is sent).
	 */
	public static function scrub_before_send( string $kind, string $id ): bool {
		if ( ! self::on() ) {
			return true;
		}
		$doc = ZEST_Billing_Doc::read( $kind, $id );
		if ( ! is_array( $doc ) ) {
			return false;
		}
		$found = self::has_code( (string) ( $doc['notes'] ?? '' ) ) || self::has_code( (string) ( $doc['terms'] ?? '' ) );
		foreach ( (array) ( $doc['lines'] ?? array() ) as $l ) {
			$found = $found || ( is_array( $l ) && ( self::has_code( (string) ( $l['name'] ?? '' ) ) || self::has_code( (string) ( $l['description'] ?? '' ) ) ) );
		}
		if ( ! $found ) {
			return true;
		}
		$res = ZEST_Billing_Doc::put( $kind, $id, array(
			'notes' => (string) ( $doc['notes'] ?? '' ),
			'terms' => (string) ( $doc['terms'] ?? '' ),
			'lines' => ZEST_Billing_Doc::writable_lines( (array) ( $doc['lines'] ?? array() ) ),
		), (string) ( $doc['create_date'] ?? '' ) );   // the outgoing filter cuts and seals on the way
		return ! empty( $res['ok'] );
	}

	/* ------------------------------------------------------------------ who may open */

	/** The estimate row a sealed code's scope names, or null. */
	public static function row_for_scope( string $sid ): ?array {
		if ( ! class_exists( 'ZEST_DB' ) ) {
			return null;
		}
		global $wpdb;
		$t = ZEST_DB::estimates_table();
		if ( preg_match( '/^[1-9][0-9]{0,18}$/', $sid ) ) {
			return ZEST_Access::row( (int) $sid );
		}
		if ( preg_match( '/^h[0-9a-f]{16}$/', $sid ) ) {
			$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE seal_hold = %s ORDER BY id DESC LIMIT 1", $sid ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is $wpdb->prefix plus a fixed name (ZEST_DB::estimates_table())
			return is_array( $r ) ? $r : null;
		}
		if ( preg_match( '/^b([1-9][0-9]{0,18})$/', $sid, $m ) ) {
			$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE billing_doc_id = %s ORDER BY id DESC LIMIT 1", $m[1] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is $wpdb->prefix plus a fixed name (ZEST_DB::estimates_table())
			return is_array( $r ) ? $r : null;
		}
		return null;
	}

	/** zdz_sealed_may_open for estimate codes: the estimate is theirs AND today is a visit for it. Deny by default. */
	public static function may_open( $ok, $row, $uid ) {
		if ( true === $ok || ! is_array( $row ) || 'estimate' !== ( $row['scope_type'] ?? '' ) ) {
			return $ok;
		}
		$est = self::row_for_scope( (string) ( $row['scope_id'] ?? '' ) );
		if ( ! $est || ! ZEST_Access::may_open( $est, (int) $uid ) ) {
			return $ok;
		}
		$visit = apply_filters( 'zest_sealed_estimate_visit', false, (int) $est['id'], (int) $uid );
		return true === $visit ? 'estimate visit today' : $ok;
	}

	/** The codes kept for an estimate, as "Show code" lists them: ZDZ_Sealed::info() rows, never a code. */
	public static function for_row( array $row, int $uid ): array {
		if ( ! class_exists( 'ZDZ_Sealed' ) || ! method_exists( 'ZDZ_Sealed', 't_sealed' ) ) {
			return array();
		}
		$ids = array( (string) (int) $row['id'] );
		if ( '' !== (string) ( $row['seal_hold'] ?? '' ) ) {
			$ids[] = (string) $row['seal_hold'];
		}
		if ( '' !== (string) ( $row['billing_doc_id'] ?? '' ) ) {
			$ids[] = 'b' . preg_replace( '/\D/', '', (string) $row['billing_doc_id'] );
		}
		global $wpdb;
		$in   = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
		$list = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . ZDZ_Sealed::t_sealed() . " WHERE scope_type = 'estimate' AND scope_id IN ({$in}) ORDER BY id ASC LIMIT 50", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL -- placeholders only
		$out  = array();
		foreach ( $list as $sid ) {
			$i = ZDZ_Sealed::info( (int) $sid, $uid );
			if ( is_array( $i ) ) {
				$out[] = $i;
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ the chat */

	/** Every estimate verb answers with codes cut. */
	public static function guard_verbs( $caps ) {
		if ( ! is_array( $caps ) ) {
			return $caps;
		}
		foreach ( $caps as $verb => $row ) {
			if ( 0 !== strpos( (string) $verb, 'estimate.' ) || ! is_array( $row ) ) {
				continue;
			}
			foreach ( array( 'callable', 'callback' ) as $k ) {
				if ( isset( $row[ $k ] ) && is_callable( $row[ $k ] ) ) {
					$cb                  = $row[ $k ];
					$caps[ $verb ][ $k ] = static function ( $args = array() ) use ( $cb ) {
						return self::deep( call_user_func( $cb, $args ) );
					};
				}
			}
		}
		return $caps;
	}
}
