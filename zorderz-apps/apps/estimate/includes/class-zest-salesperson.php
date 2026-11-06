<?php
/**
 * ZEST_Salesperson - who an estimate's salesperson is, and whose estimate it is.
 *
 * 1. codes_for_row(): the salesperson codes on this app's own row, upper case, in order: the `salesperson` column (what
 *    the app wrote: the creator's code, or the person Territories routed a stub to), the location line's codes and the
 *    provenance line's (ZDZ_Doc_Conventions: "Submitted by: XX" by default). Never the operator who typed the stub in:
 *    an operator's own code never comes from the provenance line, and a creator who is only an operator is never named.
 * 2. person_for_code(): a code names a person only when exactly one account holds it (user meta zdz_user_initials),
 *    and that account is not the shared device and not only an operator. Two people with one code is a question for an
 *    administrator, not a guess.
 * 3. for_row(): the people a row names ({ salesperson_uids, salesperson_initials }); what the acceptance notice and the
 *    agreements app's "who may record a paper signing" (filter zagr_estimate_salespeople) read.
 * 4. owned_state(): is this estimate this person's? The admin tier; else, unless an administrator removed their access
 *    to it (ZEST_Access_Requests::is_revoked): its creator, a person its codes name (so a stub Territories routed to
 *    someone is theirs), or an administrator's grant (ZEST_Access_Requests::has_grant). Never the shared device.
 * 5. contact_for(): the capability verb estimate.contact_for ("estimate:<id>"): the first name, email, phone and
 *    number for the estimate's owner by the rule above; anyone else is refused and told nothing about the estimate.
 *
 * Nothing here logs a name, email, phone or code.
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZEST_Salesperson {

	const VERB = 'estimate.contact_for';
	const META = 'zdz_user_initials';

	/** @var array<string,int[]>|null  CODE => user ids holding it, once per request. */
	private static $code_map = null;

	public static function flush(): void {
		self::$code_map = null;
	}

	public static function boot(): void {
		add_filter( 'zagr_estimate_salespeople', array( __CLASS__, 'agreement_salespeople' ), 10, 2 );
		add_filter( 'zdz_register_capabilities', array( __CLASS__, 'register_verb' ) );
	}

	/* ------------------------------------------------------------------ people */

	private static function roles( int $uid ): array {
		$u = $uid > 0 ? get_userdata( $uid ) : null;
		return $u ? (array) $u->roles : array();
	}

	public static function is_kiosk( int $uid ): bool {
		if ( $uid <= 0 ) {
			return false;
		}
		if ( class_exists( 'ZDZ_User_Roles' ) && method_exists( 'ZDZ_User_Roles', 'is_shared_device' ) && ZDZ_User_Roles::is_shared_device( $uid ) ) {
			return true;
		}
		if ( class_exists( 'ZDZ_Hierarchy' ) && method_exists( 'ZDZ_Hierarchy', 'is_kiosk' ) && ZDZ_Hierarchy::is_kiosk( $uid ) ) {
			return true;
		}
		return in_array( 'zdz_general', self::roles( $uid ), true );
	}

	/** Only an operator: holds the operator seat and nothing that sells or administers. */
	public static function is_operator_only( int $uid ): bool {
		$r = self::roles( $uid );
		return in_array( 'zdz_operator', $r, true ) && ! array_intersect( $r, array( 'zdz_sales', 'administrator', 'zdz_owner', 'zdz_admin' ) );
	}

	public static function initials_of( int $uid ): string {
		if ( $uid <= 0 ) {
			return '';
		}
		$c = strtoupper( trim( (string) get_user_meta( $uid, self::META, true ) ) );
		return preg_match( '/^[A-Z]{2,4}$/', $c ) ? $c : '';
	}

	/** CODE => user ids holding it. One query a request. */
	private static function code_map(): array {
		if ( null !== self::$code_map ) {
			return self::$code_map;
		}
		$map = array();
		if ( function_exists( 'get_users' ) ) {
			foreach ( (array) get_users( array( 'meta_key' => self::META, 'meta_compare' => 'EXISTS', 'fields' => 'ID', 'number' => 500 ) ) as $id ) {
				$id = (int) ( is_object( $id ) ? ( $id->ID ?? 0 ) : $id );
				$c  = self::initials_of( $id );
				if ( $id > 0 && '' !== $c ) {
					$map[ $c ][] = $id;
				}
			}
		}
		return self::$code_map = $map;
	}

	/** The one person a code names, or 0 (none, several, the shared device, an operator only). */
	public static function person_for_code( string $code ): int {
		$ids = self::code_map()[ strtoupper( trim( $code ) ) ] ?? array();
		if ( 1 !== count( $ids ) ) {
			return 0;
		}
		$uid = (int) $ids[0];
		return ( self::is_kiosk( $uid ) || self::is_operator_only( $uid ) ) ? 0 : $uid;
	}

	/* ------------------------------------------------------------------ the row */

	/** The row's stored lines and notes in the billing system's shape ({ name, description, unit_cost.amount }). */
	public static function doc_from_row( array $row ): array {
		$items = json_decode( (string) ( $row['items_json'] ?? '' ), true );
		$lines = array();
		$str   = static function ( $v ) { return is_scalar( $v ) ? (string) $v : ''; };
		foreach ( is_array( $items ) ? $items : array() as $it ) {
			if ( ! is_array( $it ) ) {
				continue;
			}
			if ( array_key_exists( 'sub_description', $it ) || ! isset( $it['name'] ) ) {
				$name = $str( $it['description'] ?? '' );
				$desc = $str( $it['sub_description'] ?? '' );
			} else {
				$name = $str( $it['name'] );
				$desc = $str( $it['description'] ?? '' );
			}
			$amt = $it['unit_cost']['amount'] ?? ( $it['unit_price'] ?? ( $it['rate'] ?? 0 ) );
			$qty = $it['qty'] ?? ( $it['quantity'] ?? 1 );
			$lines[] = array( 'name' => $name, 'description' => $desc, 'qty' => is_numeric( $qty ) ? (float) $qty : 1.0, 'unit_cost' => array( 'amount' => is_numeric( $amt ) ? (float) $amt : 0.0 ) );
		}
		return array( 'lines' => $lines, 'notes' => $str( $row['notes'] ?? '' ), 'terms' => $str( $row['terms'] ?? '' ) );
	}

	/** Codes in the location line (the first line whose words parse as one) of these billing-shape lines. */
	public static function location_codes( array $lines ): array {
		if ( ! class_exists( 'ZDZ_Doc_Conventions' ) ) {
			return array();
		}
		foreach ( $lines as $l ) {
			$name = (string) ( $l['name'] ?? '' );
			$desc = (string) ( $l['description'] ?? '' );
			if ( ! preg_match( '/^\s*(?:property\s+)?location\b/i', $name ) ) {
				continue;
			}
			$text = '' !== trim( $desc ) ? $desc : (string) preg_replace( '/^\s*(?:property\s+)?location\s*[-:|]?\s*/i', '', $name );
			return (array) ( ZDZ_Doc_Conventions::parse_location_line( $text )['party_codes'] ?? array() );
		}
		return array();
	}

	/** Codes in the provenance line of these notes. */
	public static function provenance_codes( string $notes ): array {
		if ( ! class_exists( 'ZDZ_Doc_Conventions' ) ) {
			return array();
		}
		foreach ( (array) preg_split( '/\r\n|\r|\n/', $notes ) as $line ) {
			if ( ZDZ_Doc_Conventions::is_provenance_line( (string) $line ) ) {
				$tail = (string) preg_replace( '/^[^:]*:\s*/', '', (string) $line );
				$tail = (string) preg_replace( '/\(.*$/', '', $tail );   // a parenthetical after the code is not a code
				return ZDZ_Doc_Conventions::parse_initials( $tail );
			}
		}
		return array();
	}

	/** The salesperson codes on the row (see the header). */
	public static function codes_for_row( array $row ): array {
		$by      = (int) ( $row['created_by'] ?? 0 );
		$typist  = ( $by > 0 && in_array( 'zdz_operator', self::roles( $by ), true ) ) ? self::initials_of( $by ) : '';
		$op_only = $by > 0 && self::is_operator_only( $by );
		$drop    = static function ( array $codes ) use ( $typist ) {
			return '' === $typist ? $codes : array_values( array_filter( $codes, static function ( $c ) use ( $typist ) { return strtoupper( (string) $c ) !== $typist; } ) );
		};
		$col = array();
		$sp  = trim( (string) ( $row['salesperson'] ?? '' ), " \t()" );
		if ( '' !== $sp ) {
			$col = class_exists( 'ZDZ_Doc_Conventions' ) ? ZDZ_Doc_Conventions::parse_initials( $sp ) : array_filter( (array) preg_split( '/\s*[&\/,]\s*/', strtoupper( $sp ) ) );
		}
		$doc = self::doc_from_row( $row );
		$loc = self::location_codes( $doc['lines'] );
		$sub = $drop( self::provenance_codes( $doc['notes'] ) );
		if ( $op_only ) {
			$col = $drop( $col );
			$loc = $drop( $loc );
		}
		return array_values( array_unique( array_map( 'strtoupper', array_merge( $col, $loc, $sub ) ) ) );
	}

	/** The people the row names: { salesperson_uids, salesperson_initials }. */
	public static function for_row( array $row ): array {
		$codes = self::codes_for_row( $row );
		$uids  = array();
		foreach ( $codes as $c ) {
			$uid = self::person_for_code( $c );
			if ( $uid > 0 && ! in_array( $uid, $uids, true ) ) {
				$uids[] = $uid;
			}
		}
		return array( 'salesperson_uids' => $uids, 'salesperson_initials' => $codes );
	}

	/* ------------------------------------------------------------------ ownership */

	public static function is_admin_tier( int $uid ): bool {
		if ( $uid <= 0 ) {
			return false;
		}
		$is = user_can( $uid, 'manage_options' ) || (bool) array_intersect( array( 'administrator', 'zdz_owner', 'zdz_admin' ), self::roles( $uid ) );
		if ( $is && class_exists( 'ZDZ_Data_Permissions' ) && method_exists( 'ZDZ_Data_Permissions', 'can_view_others_data' )
			&& ! ZDZ_Data_Permissions::can_view_others_data( $uid ) ) {
			return false;
		}
		return $is;
	}

	/** 'yes' | 'no': is this estimate this person's (see the header)? */
	public static function owned_state( array $row, int $uid ): string {
		if ( $uid <= 0 || self::is_kiosk( $uid ) ) {
			return 'no';
		}
		if ( self::is_admin_tier( $uid ) ) {
			return 'yes';
		}
		$rid = (int) ( $row['id'] ?? 0 );
		if ( $rid > 0 && class_exists( 'ZEST_Access_Requests' ) && ZEST_Access_Requests::is_revoked( $uid, $rid ) ) {
			return 'no';
		}
		if ( (int) ( $row['created_by'] ?? 0 ) === $uid ) {
			return 'yes';
		}
		if ( in_array( $uid, self::for_row( $row )['salesperson_uids'], true ) ) {
			return 'yes';
		}
		if ( $rid > 0 && class_exists( 'ZEST_Access_Requests' ) && ZEST_Access_Requests::has_grant( $uid, $rid ) ) {
			return 'yes';
		}
		return 'no';
	}

	public static function owned_by( array $row, int $uid ): bool {
		return 'yes' === self::owned_state( $row, $uid );
	}

	/**
	 * The SQL a "my estimates" list adds for someone below the admin tier: their own rows, rows whose `salesperson`
	 * column names their code (only when exactly they hold it), and rows granted to them. [ sql, args ].
	 */
	public static function mine_sql( int $uid ): array {
		$sql  = 'created_by = %d';
		$args = array( $uid );
		$code = self::initials_of( $uid );
		if ( '' !== $code && self::person_for_code( $code ) === $uid ) {
			$sql   .= " OR CONCAT(' ', REPLACE(REPLACE(REPLACE(UPPER(salesperson), '&', ' '), '/', ' '), ',', ' '), ' ') LIKE %s";
			$args[] = '% ' . $code . ' %';
		}
		$granted = class_exists( 'ZEST_Access_Requests' ) ? ZEST_Access_Requests::granted_rows( $uid ) : array();
		if ( $granted ) {
			$sql .= ' OR id IN (' . implode( ',', array_map( 'intval', $granted ) ) . ')';
		}
		$revoked = class_exists( 'ZEST_Access_Requests' ) ? ZEST_Access_Requests::revoked_rows( $uid ) : array();
		$sql     = '(' . $sql . ')';
		if ( $revoked ) {
			$sql .= ' AND id NOT IN (' . implode( ',', array_map( 'intval', $revoked ) ) . ')';
		}
		return array( $sql, $args );
	}

	/* ------------------------------------------------------------------ the agreements app */

	/** zagr_estimate_salespeople: the people this estimate names (who may record its paper signing). */
	public static function agreement_salespeople( $ids, $estimate_id ) {
		$ids = is_array( $ids ) ? $ids : array();
		$row = class_exists( 'ZEST_Access' ) ? ZEST_Access::row( (int) $estimate_id ) : null;
		if ( ! $row ) {
			return $ids;
		}
		$out = self::for_row( $row )['salesperson_uids'];
		$by  = (int) ( $row['created_by'] ?? 0 );
		if ( $by > 0 && ! self::is_kiosk( $by ) && ! self::is_operator_only( $by ) ) {
			$out[] = $by;
		}
		return array_values( array_unique( array_merge( array_map( 'intval', $ids ), $out ) ) );
	}

	/* ------------------------------------------------------------------ the verb */

	public static function first_name( string $name ): string {
		$name = trim( (string) preg_replace( '/\s+/', ' ', $name ) );
		return '' === $name ? '' : (string) explode( ' ', $name )[0];
	}

	public static function register_verb( $caps ) {
		$caps = is_array( $caps ) ? $caps : array();
		$caps[ self::VERB ] = array(
			'app'         => defined( 'ZEST_APP_ID' ) ? ZEST_APP_ID : 'estimate-creator',
			'callable'    => array( __CLASS__, 'contact_for' ),
			'min_tier'    => 'staff',
			'side_effect' => false,
			'needs_app'   => false,
			'kiosk'       => false,
			'description' => 'An estimate customer\'s first name, email and phone, for the estimate\'s own salesperson.',
		);
		return $caps;
	}

	/** estimate.contact_for. $args come from the registry: user_id and is_kiosk are its, never the caller's. */
	public static function contact_for( array $args ): array {
		$uid = (int) ( $args['user_id'] ?? 0 );
		if ( ! empty( $args['is_kiosk'] ) || self::is_kiosk( $uid ) ) {
			return array( 'success' => true, 'denied' => true, 'reason' => 'kiosk', 'verb' => self::VERB );
		}
		if ( $uid <= 0 ) {
			return array( 'success' => true, 'denied' => true, 'reason' => 'signed_out', 'verb' => self::VERB );
		}
		$ref = is_scalar( $args['ref'] ?? '' ) ? trim( (string) $args['ref'] ) : '';
		if ( ! preg_match( '/^estimate:([1-9][0-9]{0,18})$/', $ref, $m ) ) {
			return array( 'success' => false, 'error' => 'bad_ref', 'verb' => self::VERB );
		}
		$row = class_exists( 'ZEST_Access' ) ? ZEST_Access::row( (int) $m[1] ) : null;
		if ( ! $row ) {
			return array( 'success' => true, 'denied' => true, 'reason' => 'not_yours', 'verb' => self::VERB );
		}
		if ( ! self::owned_by( $row, $uid ) ) {
			return array( 'success' => true, 'denied' => true, 'reason' => 'not_yours', 'verb' => self::VERB );
		}
		return array(
			'success'    => true,
			'verb'       => self::VERB,
			'first_name' => self::first_name( (string) ( $row['customer_name'] ?? '' ) ),
			'email'      => (string) ( $row['customer_email'] ?? '' ),
			'phone'      => (string) ( $row['customer_phone'] ?? '' ),
			'number'     => (string) ( $row['billing_doc_num'] ?? '' ),
		);
	}
}
