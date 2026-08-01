<?php
/**
 * ZEST_FreshBooks — billing helpers over the theme's shared ZDZ_Core_FreshBooks client.
 *
 * The module does NOT hold FreshBooks credentials, refresh tokens or an OAuth app — the
 * theme's Connections layer (ZDZ_Core_FreshBooks + ZDZ_Token_Service) owns all of that,
 * single-flight-refreshed, one encrypted store. This class adds only estimate-shaped
 * helpers: resolving a provider status integer to a platform SIGNAL (never treating the
 * integer as state), cleaning an email to a valid address or '', and building an estimate
 * payload. No account slug, deep-link literal or status integer is hardcoded — the status
 * map is tenant/provider config read through a filter (crosswalk C14).
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZEST_FreshBooks {

	/**
	 * The provider estimate-status map: integer => { label, signal }. This is a
	 * PROVIDER/tenant mapping, not Core logic, so it is read through a filter and
	 * defaults to empty (a fresh install draws no meaning from a raw integer and logs
	 * the unmapped value instead of guessing). Consumers branch on the SIGNAL, and
	 * queries filter on platform state — never on the raw integer (which permanently
	 * stranded replied-to estimates in the old "fb_status < 4" filter).
	 *
	 * @return array<int,array{label:string,signal:string}>
	 */
	public static function status_map(): array {
		/**
		 * @param array $map integer => { label, signal }
		 */
		return (array) apply_filters( 'zdz_billing_estimate_status', array() );
	}

	/** Resolve an estimate row/array to a platform signal, or '' (logged as unmapped). */
	public static function status_signal( $estimate ): string {
		$int = self::status_int( $estimate );
		$map = self::status_map();
		if ( isset( $map[ $int ]['signal'] ) && '' !== $map[ $int ]['signal'] ) {
			return (string) $map[ $int ]['signal'];
		}
		if ( $int > 0 ) {
			error_log( sprintf( 'Zorderz Estimates: unmapped billing estimate status integer %d — ignored (add it to zdz_billing_estimate_status).', $int ) );
		}
		return '';
	}

	/** Extract the provider status integer from a provider estimate array. */
	public static function status_int( $estimate ): int {
		if ( is_numeric( $estimate ) ) {
			return (int) $estimate;
		}
		if ( is_array( $estimate ) ) {
			foreach ( array( 'status', 'estimate_status', 'v3_status' ) as $k ) {
				if ( isset( $estimate[ $k ] ) && is_numeric( $estimate[ $k ] ) ) {
					return (int) $estimate[ $k ];
				}
			}
		}
		return 0;
	}

	/** The shared billing client, or null when Connections is not configured. */
	public static function client() {
		if ( ! class_exists( 'ZDZ_Core_FreshBooks' ) ) {
			return null;
		}
		$c = new ZDZ_Core_FreshBooks();
		return $c->is_configured() ? $c : null;
	}

	public static function is_configured(): bool {
		return null !== self::client();
	}

	/**
	 * Normalise an email to a valid address or ''. Collapses the placeholder the parser
	 * sometimes emits for a missing email ("unknown") so it can never reach the provider
	 * and 422. Deterministic backstop — the send path also refuses on a blank email.
	 */
	public static function clean_email( $email ): string {
		$email = strtolower( trim( (string) $email ) );
		if ( '' === $email || in_array( $email, array( 'unknown', 'n/a', 'none', 'na', 'no email', 'noemail' ), true ) ) {
			return '';
		}
		return is_email( $email ) ? $email : '';
	}

	/**
	 * Create a billing estimate through the shared client. Applies document conventions
	 * to the line items and reference ON OUTPUT via ZDZ_Doc_Conventions before sending.
	 *
	 * @param array $estimate { customer:array, line_items:array[], notes:string, reference:string }
	 * @param array $ctx      { initials, parenthetical, user_id }
	 * @return array{ ok:bool, id:string, number:string, error:string }
	 */
	public static function create_estimate( array $estimate, array $ctx = array() ): array {
		$out    = array( 'ok' => false, 'id' => '', 'number' => '', 'error' => '' );
		$client = self::client();
		if ( ! $client ) {
			$out['error'] = 'Billing is not configured. Connect the billing provider in Zorderz settings.';
			return $out;
		}

		// House style on output only.
		if ( class_exists( 'ZDZ_Doc_Conventions' ) ) {
			$estimate = ZDZ_Doc_Conventions::apply_on_output( $estimate, $ctx );
		}

		try {
			$resp = $client->create_estimate( $estimate );
		} catch ( \Throwable $e ) {
			$out['error'] = 'Billing create failed: ' . $e->getMessage();
			return $out;
		}
		$est = $resp['response']['result']['estimate'] ?? ( is_array( $resp ) ? $resp : null );
		if ( ! is_array( $est ) || empty( $est['id'] ) && empty( $est['estimateid'] ) ) {
			$out['error'] = 'Billing create returned no estimate.';
			return $out;
		}
		$out['ok']     = true;
		$out['id']     = (string) ( $est['id'] ?? $est['estimateid'] ?? '' );
		$out['number'] = (string) ( $est['estimate_number'] ?? $est['estimateid'] ?? '' );
		return $out;
	}
}
