<?php
/**
 * ZCC_Invoice_View — an invoice opens inside Commission, never on the billing system's site.
 *
 * An invoice number in Commission is a button. Tapping it reads that one invoice live from the
 * billing system (nothing about it is stored on the site) and draws it under its row: Billed To,
 * the dates and number, the lines, the totals. No link to the billing system reaches a page, a
 * REST answer or an export; the billing system's internal id never leaves the server.
 *
 * WHO SEES WHAT — no wider than Commission already shows them:
 *
 *   where         who may open it                                        money  name  address
 *   ------------  -----------------------------------------------------  -----  ----  -------
 *   commission    a pay admin; anyone else only an invoice their own     yes    yes   yes
 *                 commission ledger holds (their sale)
 *   review        pay admins only                                        yes    yes   yes
 *   installer     a pay admin; a piece worker only a job attributed to   no     yes   no
 *                 them (checked once the invoice is read)
 *
 * The shared device, Privacy View and an account previewing as another role see nothing. Notes
 * and terms (which often hold an address or a gate code) come only with the address. A failure
 * logs a reason code, never a number, a name or an amount.
 *
 * COPY CUSTOMER LINK: for whoever sees the customer's name AND address, the billing system's
 * share link (which the customer opens without signing in) is returned to be copied into the
 * person's own message — never opened by the app. A draft has no link.
 *
 * @package Zorderz\Commission
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZCC_Invoice_View {

	/** Tests: callable( string $number, int $invoice_id ) => raw invoice | null, or throws. */
	public static $read = null;

	private static function deny( string $message = '' ): array {
		return [ 'ok' => false, 'denied' => true, 'message' => '' !== $message ? $message : __( 'That invoice isn’t one you can open here.', 'zorderz' ) ];
	}

	private static function blocked( int $uid ): bool {
		if ( $uid <= 0 || ZCC_Visibility::emulating() ) {
			return true;
		}
		if ( class_exists( 'ZDZ_Privacy_View' ) && ZDZ_Privacy_View::is_on( $uid ) ) {
			return true;
		}
		if ( class_exists( 'ZDZ_Capabilities' ) && method_exists( 'ZDZ_Capabilities', 'tier_of' ) && 'kiosk' === ZDZ_Capabilities::tier_of( $uid ) ) {
			return true;
		}
		return class_exists( 'ZDZ_User_Roles' ) && method_exists( 'ZDZ_User_Roles', 'is_shared_device' ) && ZDZ_User_Roles::is_shared_device( $uid );
	}

	private static function has_commission_access( int $uid ): bool {
		if ( class_exists( 'ZDZ_Data_Permissions' ) ) {
			return ZDZ_Data_Permissions::can( $uid, 'run_commission_calculation' )
				&& ( ZDZ_Data_Permissions::can( $uid, 'view_own_commission' ) || ZDZ_Data_Permissions::can( $uid, 'view_others_commissions' ) );
		}
		return function_exists( 'user_can' ) && user_can( $uid, 'manage_options' );
	}

	/**
	 * Who may open this invoice from here, and what they see.
	 *
	 * @return array{ allowed:bool, money?:bool, name?:bool, address?:bool, ledger_uid?:int, needs_job?:bool, message?:string }
	 */
	public static function audience( int $uid, string $number, string $where, int $target = 0 ): array {
		$no = [ 'allowed' => false ];
		if ( self::blocked( $uid ) ) {
			return $no;
		}
		$pay_admin = ZCC_Visibility::is_pay_admin( $uid );
		$full      = [ 'allowed' => true, 'money' => true, 'name' => true, 'address' => true, 'ledger_uid' => 0, 'needs_job' => false ];
		switch ( $where ) {
			case 'commission':
				if ( ! self::has_commission_access( $uid ) ) {
					return $no;
				}
				if ( $pay_admin ) {
					$whose = $target > 0 ? $target : $uid;
					return [ 'ledger_uid' => ZCC_Ledger::get_entry_by_number( $whose, $number ) ? $whose : 0 ] + $full;
				}
				if ( ( $target > 0 && $target !== $uid ) || ! ZCC_Ledger::get_entry_by_number( $uid, $number ) ) {
					return $no;
				}
				return [ 'ledger_uid' => $uid ] + $full;
			case 'review':
				return $pay_admin ? $full : $no;
			case 'installer':
				if ( $pay_admin ) {
					return $full;
				}
				$plan = class_exists( 'ZDZ_Compensation' ) ? ZDZ_Compensation::get_plan( $uid ) : [];
				if ( ! empty( $plan['is_piece_worker'] ) ) {
					return [ 'allowed' => true, 'money' => false, 'name' => true, 'address' => false, 'ledger_uid' => 0, 'needs_job' => true ];
				}
				return $no;
		}
		return $no;
	}

	private static function read( string $number, int $invoice_id ): ?array {
		if ( is_callable( self::$read ) ) {
			return call_user_func( self::$read, $number, $invoice_id );
		}
		return $invoice_id > 0 ? ZCC_FreshBooks::find_invoice_by_id( $invoice_id ) : ZCC_FreshBooks::find_invoice_by_number( $number );
	}

	/** What the page gets for one invoice. */
	public static function answer( int $uid, string $number, string $where, int $target = 0 ): array {
		$number = trim( $number );
		if ( ! preg_match( '/^[A-Za-z0-9-]{1,20}$/D', $number ) ) {
			return self::deny( __( 'That isn’t an invoice number.', 'zorderz' ) );
		}
		$who = self::audience( $uid, $number, $where, $target );
		if ( empty( $who['allowed'] ) ) {
			return self::deny();
		}
		$le = $who['ledger_uid'] > 0 ? ZCC_Ledger::get_entry_by_number( (int) $who['ledger_uid'], $number ) : null;
		try {
			$raw = self::read( $number, (int) ( $le['invoice_id'] ?? 0 ) );
		} catch ( \Throwable $e ) {
			error_log( 'ZCC invoice view: unreadable' );
			return [ 'ok' => false, 'why' => 'unreadable', 'message' => __( 'The invoice couldn’t be loaded right now.', 'zorderz' ) ];
		}
		if ( ! $raw ) {
			return [ 'ok' => false, 'why' => 'not_found', 'message' => __( 'The billing system has no invoice with that number.', 'zorderz' ) ];
		}
		if ( ! empty( $who['needs_job'] ) ) {
			$res = ZCC_Attribution::resolve( ZCC_FreshBooks::normalize_invoice( $raw ), 'install' );
			if ( ! ZCC_Attribution::pays( $res, $uid ) ) {
				return self::deny();
			}
		}
		return [
			'ok'        => true,
			'doc'       => self::shape( $raw, (bool) $who['money'], (bool) $who['name'], (bool) $who['address'] ),
			'number'    => $number,
			'can_share' => ! empty( $who['name'] ) && ! empty( $who['address'] ) && ! self::is_draft( $raw ),
		];
	}

	/** The customer link to copy, for whoever sees this customer's name and address. */
	public static function link_answer( int $uid, string $number, string $where, int $target = 0 ): array {
		$a = self::answer( $uid, $number, $where, $target );
		if ( empty( $a['ok'] ) ) {
			return $a;
		}
		if ( empty( $a['can_share'] ) ) {
			return self::deny( __( 'Only people who can see this customer can copy their link, and a draft has none yet.', 'zorderz' ) );
		}
		$who = self::audience( $uid, $number, $where, $target );
		$le  = ( $who['ledger_uid'] ?? 0 ) > 0 ? ZCC_Ledger::get_entry_by_number( (int) $who['ledger_uid'], $number ) : null;
		try {
			$raw  = self::read( $number, (int) ( $le['invoice_id'] ?? 0 ) );
			$link = $raw ? ZCC_FreshBooks::share_link( (int) ( $raw['invoiceid'] ?? $raw['id'] ?? 0 ) ) : '';
		} catch ( \Throwable $e ) {
			$link = '';
		}
		if ( '' === $link ) {
			error_log( 'ZCC invoice link: no_link' );
			return [ 'ok' => false, 'why' => 'no_link', 'message' => __( 'The billing system didn’t give a customer link for this invoice just now. Try again in a minute.', 'zorderz' ) ];
		}
		return [ 'ok' => true, 'link' => $link, 'number' => $number ];
	}

	/** Is a raw invoice a draft? Read from its first status field that says something. */
	public static function is_draft( array $raw ): bool {
		foreach ( [ $raw['v3_status'] ?? '', $raw['display_status'] ?? '', $raw['status'] ?? '' ] as $s ) {
			$s = strtolower( trim( is_scalar( $s ) ? (string) $s : '' ) );
			if ( '' !== $s && ! ctype_digit( $s ) ) {
				return 'draft' === $s;
			}
			if ( '1' === $s ) {
				return true;
			}
		}
		return false;
	}

	private static function t( $s, int $max ): string {
		$s = is_scalar( $s ) ? trim( (string) $s ) : '';
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max ) : substr( $s, 0, $max );
	}

	private static function money( $v ): ?float {
		$v = is_array( $v ) ? ( $v['amount'] ?? null ) : $v;
		return is_numeric( $v ) ? round( (float) $v, 2 ) : null;
	}

	/** The invoice as the page draws it, cut to what this person may see. */
	public static function shape( array $inv, bool $money, bool $name = true, bool $address = true ): array {
		$org    = self::t( $inv['organization'] ?? '', 200 );
		$person = self::t( self::t( $inv['fname'] ?? '', 100 ) . ' ' . self::t( $inv['lname'] ?? '', 100 ), 200 );
		$who    = '' !== $org ? $org : $person;
		if ( ! $name ) {
			$billed = [ __( 'Customer details hidden', 'zorderz' ) ];
		} elseif ( ! $address ) {
			$billed = array_values( array_filter( [ $who ], 'strlen' ) );
		} else {
			$city   = self::t( $inv['city'] ?? '', 120 );
			$region = self::t( $inv['province'] ?? '', 60 );
			$place  = trim( $city . ( '' !== $city && '' !== $region ? ', ' : ' ' ) . $region );
			$code   = self::t( $inv['code'] ?? '', 20 );
			$billed = array_values( array_filter( [ $who, self::t( $inv['street'] ?? '', 200 ), self::t( $inv['street2'] ?? '', 200 ), trim( $place . ( '' !== $code ? '  ' . $code : '' ) ) ], 'strlen' ) );
		}
		$lines = [];
		$sub   = 0.0;
		foreach ( array_slice( (array) ( $inv['lines'] ?? [] ), 0, 300 ) as $ln ) {
			if ( ! is_array( $ln ) ) {
				continue;
			}
			$amt     = self::money( $ln['amount'] ?? null );
			$sub    += (float) $amt;
			$lines[] = [
				'name'        => self::t( $ln['name'] ?? '', 1000 ),
				'description' => self::t( $ln['description'] ?? '', 5000 ),
				'rate'        => $money ? self::money( $ln['unit_cost'] ?? null ) : null,
				'qty'         => is_numeric( $ln['qty'] ?? null ) ? 0 + $ln['qty'] : null,
				'amount'      => $money ? $amt : null,
			];
		}
		$total    = self::money( $inv['amount'] ?? null );
		$discount = self::money( $inv['discount_total'] ?? null );
		$tax      = null !== $total ? round( $total - $sub + (float) $discount, 2 ) : null;
		$due      = self::money( $inv['outstanding'] ?? null );
		$paid     = self::money( $inv['paid'] ?? null );
		if ( null === $paid && null !== $total && null !== $due ) {
			$paid = round( $total - $due, 2 );
		}
		return [
			'number'    => self::t( $inv['invoice_number'] ?? '', 40 ),
			'issued'    => substr( self::t( $inv['create_date'] ?? '', 40 ), 0, 10 ),
			'due'       => substr( self::t( $inv['due_date'] ?? '', 40 ), 0, 10 ),
			'reference' => self::t( $inv['po_number'] ?? '', 200 ),
			'status'    => self::t( $inv['v3_status'] ?? ( $inv['display_status'] ?? '' ), 40 ),
			'currency'  => self::t( is_array( $inv['amount'] ?? null ) ? ( $inv['amount']['code'] ?? '' ) : ( $inv['currency_code'] ?? '' ), 8 ),
			'billed_to' => $billed,
			'lines'     => $lines,
			'totals'    => $money ? [ 'subtotal' => round( $sub, 2 ), 'discount' => $discount ?: null, 'tax' => ( null !== $tax && abs( $tax ) < 0.005 ) ? 0.0 : $tax, 'total' => $total, 'paid' => $paid, 'due' => $due ] : null,
			'notes'     => $address && $name ? self::t( $inv['notes'] ?? '', 5000 ) : '',
			'terms'     => $address && $name ? self::t( $inv['terms'] ?? '', 5000 ) : '',
			'money'     => $money,
		];
	}
}
