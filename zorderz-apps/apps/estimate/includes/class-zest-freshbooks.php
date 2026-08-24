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

	/**
	 * Update an existing billing estimate through the shared client (Plan 02 E1). Applies
	 * document conventions ON OUTPUT, then maps the model shape → the provider WIRE shape
	 * at THIS boundary — the one place the wire shape is authoritative:
	 *   unit_price → unit_cost['amount'], quantity → qty, description → name,
	 *   sub_description → the provider's secondary description line.
	 * Prefers the client's own update helper; falls back to a generic PUT (logged) when the
	 * shared client lacks it. Returns { ok, id, number, error }.
	 *
	 * @param string $billing_doc_id provider estimate id.
	 * @param array  $estimate       { customer, line_items(model shape), notes, reference }
	 * @param array  $ctx            { initials, parenthetical, user_id }
	 */
	public static function update_estimate( string $billing_doc_id, array $estimate, array $ctx = array() ): array {
		$out    = array( 'ok' => false, 'id' => (string) $billing_doc_id, 'number' => '', 'error' => '' );
		$client = self::client();
		if ( ! $client ) {
			$out['error'] = 'Billing is not configured. Connect the billing provider in Zorderz settings.';
			return $out;
		}
		if ( '' === trim( (string) $billing_doc_id ) ) {
			$out['error'] = 'No billing document id to update.';
			return $out;
		}

		// House style on output only.
		if ( class_exists( 'ZDZ_Doc_Conventions' ) ) {
			$estimate = ZDZ_Doc_Conventions::apply_on_output( $estimate, $ctx );
		}

		// Model → WIRE mapping (the provider boundary owns the wire shape).
		$wire = self::to_wire_estimate( $estimate );

		try {
			if ( method_exists( $client, 'update_estimate' ) ) {
				$resp = $client->update_estimate( $billing_doc_id, $wire );
			} else {
				// Fallback: a generic PUT via the shared client's api_request (logged).
				error_log( 'Zorderz Estimates: shared FreshBooks client lacks update_estimate(); using generic PUT.' );
				$account = class_exists( 'ZDZ_Core_Settings' ) && method_exists( 'ZDZ_Core_Settings', 'get_fb_account_id' )
					? (string) ZDZ_Core_Settings::get_fb_account_id() : '';
				if ( '' === $account || ! method_exists( $client, 'api_request' ) ) {
					$out['error'] = 'Billing update is unavailable on this connection.';
					return $out;
				}
				$endpoint = '/accounting/account/' . rawurlencode( $account ) . '/estimates/estimates/' . rawurlencode( $billing_doc_id );
				$resp     = $client->api_request( 'PUT', $endpoint, array( 'estimate' => $wire ) );
			}
		} catch ( \Throwable $e ) {
			$out['error'] = 'Billing update failed: ' . $e->getMessage();
			return $out;
		}

		$est = $resp['response']['result']['estimate'] ?? ( is_array( $resp ) ? $resp : null );
		if ( ! is_array( $est ) ) {
			$out['error'] = 'Billing update returned no estimate.';
			return $out;
		}
		$out['ok']     = true;
		$out['id']     = (string) ( $est['id'] ?? $est['estimateid'] ?? $billing_doc_id );
		$out['number'] = (string) ( $est['estimate_number'] ?? $est['estimateid'] ?? '' );
		return $out;
	}

	/**
	 * Map a model-shape estimate to the provider WIRE shape. Only the fields the provider
	 * needs are emitted; the caller's subtractive-write discipline is preserved upstream.
	 */
	private static function to_wire_estimate( array $estimate ): array {
		$wire  = array();
		$lines = array();
		foreach ( (array) ( $estimate['line_items'] ?? array() ) as $li ) {
			if ( ! is_array( $li ) ) {
				continue;
			}
			$lines[] = array(
				'name'        => (string) ( $li['description'] ?? '' ),
				'description' => (string) ( $li['sub_description'] ?? '' ),
				'qty'         => (string) ( isset( $li['quantity'] ) ? ( 0 + $li['quantity'] ) : 1 ),
				'unit_cost'   => array( 'amount' => number_format( (float) ( $li['unit_price'] ?? 0 ), 2, '.', '' ) ),
			);
		}
		$wire['lines'] = $lines;
		if ( isset( $estimate['customer'] ) && is_array( $estimate['customer'] ) ) {
			$wire['customer'] = $estimate['customer'];
		}
		// Notes may be the customer-facing field after apply_on_output (customer_notes).
		$notes = (string) ( $estimate['customer_notes'] ?? ( $estimate['notes'] ?? '' ) );
		if ( '' !== $notes ) {
			$wire['notes'] = $notes;
		}
		return $wire;
	}
}
