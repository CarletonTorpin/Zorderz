<?php
/**
 * ZEST_Billing_Doc - one estimate or invoice in the billing system, read and written by its id.
 *
 * The connection (credentials, token refresh) is the theme's ZDZ_Core_FreshBooks; this class only shapes the calls the
 * estimate app makes on one document:
 *
 *   read( kind, id )               the document with its lines, as the billing system has it now, or null. The answer
 *                                  must be the document asked for (its id), else null: a provider that ignores a filter
 *                                  never hands back someone else's document.
 *   put( kind, id, fields )        write only these top-level fields (notes, terms, lines, ...). Lines are written as
 *                                  given, every field of each line kept; the document's date rides along so a save never
 *                                  moves it. Gate codes are cut from every word first (zest_billing_outgoing).
 *   email( id, to )                the billing system e-mails the estimate to the customer. Its copy is checked for gate
 *                                  codes first; when one cannot be taken out, nothing is sent.
 *   set_location_codes( lines, c ) pure: the lines with the location line's codes replaced, or null when there is no
 *                                  single location line to change.
 *
 * Never throws; every failure is null / ok false with a plain reason, and nothing is logged but ids and HTTP codes.
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZEST_Billing_Doc {

	/** @var callable|null tests: (method, path, body) => decoded answer */
	public static $transport = null;

	/** @var string|null tests: the billing account id (null reads it from the platform's settings) */
	public static $account = null;

	private static function account(): string {
		if ( null !== self::$account ) {
			return preg_replace( '/[^A-Za-z0-9]/', '', (string) self::$account );
		}
		if ( class_exists( 'ZDZ_Core_Settings' ) && method_exists( 'ZDZ_Core_Settings', 'get_fb_account_id' ) ) {
			return preg_replace( '/[^A-Za-z0-9]/', '', (string) ZDZ_Core_Settings::get_fb_account_id() );
		}
		return '';
	}

	private static function call( string $method, string $path, array $body = array() ) {
		if ( is_callable( self::$transport ) ) {
			return call_user_func( self::$transport, $method, $path, $body );
		}
		$client = class_exists( 'ZEST_FreshBooks' ) ? ZEST_FreshBooks::client() : null;
		if ( ! $client || ! method_exists( $client, 'api_request' ) ) {
			return null;
		}
		try {
			return $client->api_request( $method, $path, $body );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	private static function path( string $kind, string $id ): string {
		$acct = self::account();
		if ( '' === $acct || ! in_array( $kind, array( 'estimate', 'invoice' ), true ) || ! preg_match( '/^[1-9][0-9]{0,18}$/', $id ) ) {
			return '';
		}
		$plural = $kind . 's';
		return '/accounting/account/' . $acct . '/' . $plural . '/' . $plural . '/' . $id;
	}

	public static function available(): bool {
		return is_callable( self::$transport ) || ( class_exists( 'ZEST_FreshBooks' ) && ZEST_FreshBooks::is_configured() && '' !== self::account() );
	}

	/** The document with its lines, or null (not configured, not found, another document answered). */
	public static function read( string $kind, string $id ): ?array {
		$path = self::path( $kind, $id );
		if ( '' === $path ) {
			return null;
		}
		$resp = self::call( 'GET', $path . '?include[]=lines' );
		$doc  = is_array( $resp ) ? ( $resp['response']['result'][ $kind ] ?? null ) : null;
		if ( ! is_array( $doc ) ) {
			return null;
		}
		$got = (string) ( $doc['id'] ?? ( $doc[ $kind . 'id' ] ?? '' ) );
		return $got === $id ? $doc : null;
	}

	/**
	 * A document by its number (the billing system's own search): the one whose number is exactly this (leading zeros
	 * aside) and that is not deleted; null when there is none; false when the billing system can't be read.
	 *
	 * @return array|null|false
	 */
	public static function find_by_number( string $kind, string $number ) {
		$number = ltrim( (string) preg_replace( '/\D/', '', $number ), '0' );
		$acct   = self::account();
		if ( '' === $number || ! in_array( $kind, array( 'estimate', 'invoice' ), true ) ) {
			return null;
		}
		if ( '' === $acct || ! self::available() ) {
			return false;
		}
		$plural = $kind . 's';
		$resp   = self::call( 'GET', '/accounting/account/' . $acct . '/' . $plural . '/' . $plural . '?include[]=lines&search[' . $kind . '_number]=' . rawurlencode( $number ) );
		if ( ! is_array( $resp ) ) {
			return false;
		}
		foreach ( (array) ( $resp['response']['result'][ $plural ] ?? array() ) as $d ) {
			if ( is_array( $d ) && ltrim( (string) ( $d[ $kind . '_number' ] ?? '' ), '0' ) === $number && 1 !== (int) ( $d['vis_state'] ?? 0 ) ) {
				return $d;
			}
		}
		return null;
	}

	/**
	 * Write these fields. Returns { ok, doc, error }. The date the document already has rides along when $fields has
	 * none (pass create_date to keep it; read() gives it).
	 */
	public static function put( string $kind, string $id, array $fields, string $create_date = '' ): array {
		$path = self::path( $kind, $id );
		if ( '' === $path || ! $fields ) {
			return array( 'ok' => false, 'doc' => null, 'error' => __( 'The billing system is not connected, or the document is not known.', 'zorderz' ) );
		}
		$allowed = array_intersect_key( $fields, array_flip( array( 'notes', 'terms', 'lines', 'po_number', 'create_date', 'discount_value' ) ) );
		if ( '' !== $create_date && ! isset( $allowed['create_date'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $create_date ) ) {
			$allowed['create_date'] = $create_date;
		}
		/**
		 * Every word on its way to the billing system (ZEST_Sealed cuts gate codes here).
		 *
		 * @param array  $allowed The fields written.
		 * @param string $kind    estimate | invoice
		 * @param string $id      The document's id.
		 */
		$allowed = (array) apply_filters( 'zest_billing_outgoing', $allowed, $kind, $id );
		$resp    = self::call( 'PUT', $path, array( $kind => $allowed ) );
		$saved   = is_array( $resp ) ? ( $resp['response']['result'][ $kind ] ?? null ) : null;
		if ( ! is_array( $saved ) ) {
			error_log( 'Zorderz Estimates: billing write refused for ' . $kind . ' id ' . $id . '.' );
			return array( 'ok' => false, 'doc' => null, 'error' => __( 'The billing system did not take the change just now. Try again in a minute.', 'zorderz' ) );
		}
		do_action( 'zest_billing_saved', $kind, $id, $saved );
		return array( 'ok' => true, 'doc' => $saved, 'error' => '' );
	}

	/** The billing system e-mails the estimate. { ok, error }. */
	public static function email( string $id, string $to ): array {
		$path = self::path( 'estimate', $id );
		$to   = class_exists( 'ZEST_FreshBooks' ) ? ZEST_FreshBooks::clean_email( $to ) : $to;
		if ( '' === $path || '' === $to ) {
			return array( 'ok' => false, 'error' => __( 'This estimate needs a valid customer email to send.', 'zorderz' ) );
		}
		if ( class_exists( 'ZEST_Sealed' ) && ! ZEST_Sealed::scrub_before_send( 'estimate', $id ) ) {
			return array( 'ok' => false, 'error' => __( 'This estimate has an access code in the billing system that could not be taken out just now, so it was not sent. Try again in a minute.', 'zorderz' ) );
		}
		$resp = self::call( 'PUT', $path, array( 'estimate' => array(
			'action_email'              => true,
			'email_recipients'          => array( $to ),
			'estimate_customized_email' => array(
				'subject' => '::company name:: sent you an estimate (::estimate number::)',
				'body'    => '::company name:: sent you an estimate (::estimate number::)',
			),
		) ) );
		$est = is_array( $resp ) ? ( $resp['response']['result']['estimate'] ?? null ) : null;
		return is_array( $est ) ? array( 'ok' => true, 'error' => '' ) : array( 'ok' => false, 'error' => __( 'The billing system did not send it just now. Try again in a minute.', 'zorderz' ) );
	}

	/** Lines as read, reduced to the fields a write may carry (each line's words, quantity, price and taxes). */
	public static function writable_lines( array $lines ): array {
		$keep = array_flip( array( 'name', 'description', 'qty', 'unit_cost', 'taxName1', 'taxAmount1', 'taxName2', 'taxAmount2', 'type', 'expenseid' ) );
		$out  = array();
		foreach ( $lines as $l ) {
			if ( is_array( $l ) ) {
				$out[] = array_intersect_key( $l, $keep );
			}
		}
		return $out;
	}

	/**
	 * The lines with the location line's codes replaced by $codes (a billing-shape line whose name is "Location ...").
	 * null when there is no location line, or more than one, or the document conventions are not there.
	 */
	public static function set_location_codes( array $lines, array $codes ): ?array {
		if ( ! class_exists( 'ZDZ_Doc_Conventions' ) ) {
			return null;
		}
		$at = array();
		foreach ( $lines as $k => $l ) {
			if ( is_array( $l ) && preg_match( '/^\s*(?:property\s+)?location\b/i', (string) ( $l['name'] ?? '' ) ) ) {
				$at[] = $k;
			}
		}
		if ( 1 !== count( $at ) ) {
			return null;
		}
		$k    = $at[0];
		$desc = (string) ( $lines[ $k ]['description'] ?? '' );
		$p    = ZDZ_Doc_Conventions::parse_location_line( $desc );
		$new  = ZDZ_Doc_Conventions::build_location_line( (string) $p['locality'], array_values( $codes ), (string) $p['note'] );
		if ( '' === trim( $new ) ) {
			return null;
		}
		$lines[ $k ]['description'] = $new;
		return $lines;
	}
}
