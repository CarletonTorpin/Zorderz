<?php
/**
 * ZANA_Ledger_Access — who may read what from the stored-data ledger. Deny by default.
 *
 * The ledger holds customer data (names, amounts, towns, notes), so every read starts here. A context is built once
 * per request from the platform's own rules (never re-derived): the capability ladder (ZDZ_Capabilities::tier_of), the
 * data permissions (ZDZ_Data_Permissions), the shared-device rule, Privacy View and Demo Mode. Then:
 *
 *   company   every figure, money included: the company revenue right AND the right to see others' data. Lists that
 *             name customers need this too.
 *   mine      a person's own counts by their salesperson codes, never money: staff or admin tier.
 *   leads     CRM lead counts (no money): staff or admin tier.
 *   list      a list that names customers (accepted estimates never invoiced, money owed by customer): company, and
 *             never a Privacy View account (its slice is aggregates: totals and counts that name no one).
 *   list_own  a list of a salesperson's own customers (their codes): the right to see their own figures, never Privacy View.
 *   doc       one invoice or estimate opened in the chat: company and not Privacy View, or (own) a salesperson whose
 *             code the document carries, re-checked on the document itself (ZANA_Invoice_Doc).
 *
 * Refused always: signed out, the shared device, Demo Mode, an account with no rung on the ladder. The ledger holds no
 * cost data, so no cost rule is needed here; one that ever does must be the admin tier only.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Ledger_Access {

	/** The user meta keys a salesperson code is kept under (the jobs app's list, when it is installed, wins). */
	const CODE_META_KEYS = array( 'zdz_party_code', 'zl_salesperson_code', 'zdz_salesperson_initials', 'zdz_user_initials' );

	/** Injected in tests: callable( uid ) => context, and code => name. */
	public static $context = null;
	public static $reps = null;

	/** @var array this request's contexts and code map */
	private static $memo = array();
	private static $rep_memo = null;

	public static function flush(): void {
		self::$memo     = array();
		self::$rep_memo = null;
	}

	/** The asker's context: identity, rung, flags and rights. */
	public static function context( int $uid ): array {
		if ( is_callable( self::$context ) ) {
			return self::normalize( (array) call_user_func( self::$context, $uid ) + array( 'user_id' => $uid ) );
		}
		if ( isset( self::$memo[ $uid ] ) ) {
			return self::$memo[ $uid ];
		}
		$ctx = array( 'user_id' => $uid );
		if ( $uid > 0 ) {
			$ctx['tier']         = class_exists( 'ZDZ_Capabilities' ) ? ZDZ_Capabilities::tier_of( $uid ) : '';
			$ctx['is_kiosk']     = 'kiosk' === $ctx['tier'] || ( class_exists( 'ZDZ_User_Roles' ) && ZDZ_User_Roles::is_shared_device( $uid ) );
			$ctx['privacy_view'] = class_exists( 'ZDZ_Privacy_View' ) && ZDZ_Privacy_View::is_on( $uid );
			$ctx['demo']         = class_exists( 'ZDZ_Kiosk_Demo' ) && method_exists( 'ZDZ_Kiosk_Demo', 'is_active' ) && ZDZ_Kiosk_Demo::is_active();
			$perm                = class_exists( 'ZDZ_Data_Permissions' ) ? ZDZ_Data_Permissions::resolve( $uid ) : array();
			$ctx['revenue']      = 'allow' === ( $perm['view_company_revenue'] ?? 'deny' );
			$ctx['others']       = 'allow' === ( $perm['view_others_data'] ?? 'deny' );
			$ctx['own']          = 'allow' === ( $perm['view_own_commission'] ?? 'deny' );
			$ctx['codes']        = self::codes_for( $uid );
		}
		self::$memo[ $uid ] = self::normalize( $ctx );
		return self::$memo[ $uid ];
	}

	/** Every key present, typed; a kiosk floor no right can lift. */
	public static function normalize( array $c ): array {
		$c = array_merge( array(
			'user_id'      => 0,
			'tier'         => '',
			'is_kiosk'     => false,
			'privacy_view' => false,
			'demo'         => false,
			'revenue'      => false,
			'others'       => false,
			'own'          => false,
			'codes'        => array(),
		), $c );
		$c['user_id'] = (int) $c['user_id'];
		$c['tier']    = in_array( (string) $c['tier'], array( 'kiosk', 'viewer', 'staff', 'admin' ), true ) ? (string) $c['tier'] : '';
		if ( 'kiosk' === $c['tier'] ) {
			$c['is_kiosk'] = true;
		}
		if ( $c['is_kiosk'] ) {
			$c['revenue'] = false;
			$c['others']  = false;
			$c['own']     = false;
			$c['codes']   = array();
		}
		foreach ( array( 'is_kiosk', 'privacy_view', 'demo', 'revenue', 'others', 'own' ) as $k ) {
			$c[ $k ] = (bool) $c[ $k ];
		}
		$c['codes'] = self::clean_codes( (array) $c['codes'] );
		return $c;
	}

	/**
	 * May this context read $what? '' when yes, else the reason: signed_out | kiosk | demo | tier | rights | privacy_view.
	 * $what: company | mine | leads | list | doc_all | doc_own.
	 */
	public static function why_not( array $c, string $what ): string {
		$c = self::normalize( $c );
		if ( $c['user_id'] <= 0 ) {
			return 'signed_out';
		}
		if ( $c['is_kiosk'] ) {
			return 'kiosk';
		}
		if ( $c['demo'] ) {
			return 'demo';
		}
		if ( ! in_array( $c['tier'], array( 'staff', 'admin' ), true ) ) {
			return 'tier';
		}
		$company = $c['revenue'] && $c['others'];
		switch ( $what ) {
			case 'company':
				return $company ? '' : 'rights';
			case 'mine':
			case 'leads':
				return '';
			case 'list':
			case 'doc_all':
				if ( $c['privacy_view'] ) {
					return 'privacy_view';
				}
				return $company ? '' : 'rights';
			case 'doc_own':
				if ( $c['privacy_view'] ) {
					return 'privacy_view';
				}
				return $c['codes'] ? '' : 'rights';
			case 'list_own':
				// a salesperson's own customers, by their codes: the right to see their own figures
				if ( $c['privacy_view'] ) {
					return 'privacy_view';
				}
				return ( $c['own'] && $c['codes'] ) ? '' : 'rights';
		}
		return 'rights';
	}

	public static function may( array $c, string $what ): bool {
		return '' === self::why_not( $c, $what );
	}

	/** The words for a refusal (never says what exists). */
	public static function refusal_text( string $why ): string {
		$m = array(
			'signed_out'   => __( 'Sign in first.', 'zorderz' ),
			'kiosk'        => __( 'That is not available on the shared device.', 'zorderz' ),
			'demo'         => __( 'Not available in Demo Mode.', 'zorderz' ),
			'privacy_view' => __( 'This account sees totals and counts only.', 'zorderz' ),
		);
		return $m[ $why ] ?? __( 'That is not available on your account.', 'zorderz' );
	}

	/* ─────────────────────────── salesperson codes ─────────────────────────── */

	/** 2-4 capital letters each, no duplicates. */
	public static function clean_codes( array $codes ): array {
		$out = array();
		foreach ( $codes as $c ) {
			foreach ( preg_split( '/[\/,&+\s]+/', strtoupper( trim( (string) $c ) ) ) as $one ) {
				if ( preg_match( '/^[A-Z]{2,4}$/', $one ) ) {
					$out[ $one ] = true;
				}
			}
		}
		return array_keys( $out );
	}

	/** A person's salesperson codes, from their own user record (never from the request). */
	public static function codes_for( int $uid ): array {
		if ( $uid <= 0 ) {
			return array();
		}
		if ( class_exists( 'ZJOB_Scope' ) && method_exists( 'ZJOB_Scope', 'sp_codes_for' ) ) {
			return self::clean_codes( (array) ZJOB_Scope::sp_codes_for( $uid ) );
		}
		$raw = array();
		if ( function_exists( 'get_user_meta' ) ) {
			foreach ( self::CODE_META_KEYS as $k ) {
				$raw[] = (string) get_user_meta( $uid, $k, true );
			}
		}
		return self::clean_codes( $raw );
	}

	/** Every salesperson code people hold => the person's display name (the code alone when two people share it). */
	public static function rep_map(): array {
		if ( is_array( self::$reps ) ) {
			return self::$reps;
		}
		if ( null !== self::$rep_memo ) {
			return self::$rep_memo;
		}
		$map = array();
		if ( function_exists( 'get_users' ) ) {
			$keys = class_exists( 'ZJOB_Scope' ) && defined( 'ZJOB_Scope::SP_CODE_META_KEYS' ) ? (array) ZJOB_Scope::SP_CODE_META_KEYS : self::CODE_META_KEYS;
			$q    = array( 'relation' => 'OR' );
			foreach ( $keys as $k ) {
				$q[] = array( 'key' => (string) $k, 'compare' => 'EXISTS' );
			}
			foreach ( (array) get_users( array( 'meta_query' => $q, 'fields' => array( 'ID', 'display_name' ), 'number' => 500 ) ) as $u ) {
				foreach ( self::codes_for( (int) $u->ID ) as $code ) {
					$map[ $code ] = isset( $map[ $code ] ) && $map[ $code ] !== (string) $u->display_name ? $code : (string) $u->display_name;
				}
			}
		}
		self::$rep_memo = $map;
		return $map;
	}

	/** "Alex (AB)" — a code's label for an answer. */
	public static function rep_label( string $code ): string {
		$code = strtoupper( trim( $code ) );
		if ( '' === $code ) {
			return __( '(no salesperson)', 'zorderz' );
		}
		$map  = self::rep_map();
		$name = $map[ $code ] ?? '';
		return ( '' !== $name && $name !== $code ) ? $name . ' (' . $code . ')' : $code;
	}

	/** Does a stored rep value ("AB" or "AB/CD") carry any of these codes? */
	public static function rep_matches( string $stored, array $codes ): bool {
		if ( ! $codes ) {
			return false;
		}
		return (bool) array_intersect( self::clean_codes( array( $stored ) ), array_map( 'strtoupper', $codes ) );
	}
}
