<?php
/**
 * ZEST_TSA_Bridge — the chat/orchestrator verbs for the Estimates app.
 *
 * The chat engine emits a marker (ZEST_MARKER_*, published via zdz_chat_markers) and the
 * orchestrator calls the matching verb here. This class does ALL resolution and side
 * effects server-side — the model only names intent. SAFETY FLOOR, enforced here and not
 * in the prompt:
 *   - the shared kiosk is hard-refused;
 *   - admin tier is derived SERVER-SIDE from the requesting user id (never a tier string
 *     the model relayed), subject to ZDZ_Data_Permissions;
 *   - a write is ownership-checked (created_by first, submitted-by line as legacy fallback);
 *   - every verb is draft → preview → confirm → re-verify — the model is never trusted for
 *     the side effect.
 *
 * The customer-facing format spec handed to the chat model is RENDERED from
 * ZDZ_Doc_Conventions, so the bot describes the tenant's house style without any literal
 * in the prompt.
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZEST_TSA_Bridge {

	public static function init(): void {
		// Verbs are invoked by the orchestrator via the capability registry (see app.php).
	}

	/* ---- security helpers (server-side truth) ---- */

	private static function is_kiosk( int $uid ): bool {
		return class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_kiosk( $uid );
	}

	/** Admin tier derived from the user id + data-permission overrides — never the model. */
	private static function is_admin_tier( int $uid ): bool {
		if ( user_can( $uid, 'manage_options' ) ) {
			return ! ( class_exists( 'ZDZ_Data_Permissions' )
				&& method_exists( 'ZDZ_Data_Permissions', 'can_view_others_data' )
				&& ! ZDZ_Data_Permissions::can_view_others_data( $uid ) );
		}
		$u = get_userdata( $uid );
		$roles = $u ? (array) $u->roles : array();
		foreach ( array( 'zdz_owner', 'zdz_admin' ) as $r ) {
			if ( in_array( $r, $roles, true ) ) {
				return true;
			}
		}
		return false;
	}

	/** True if $uid owns the local estimate row (created_by first; provenance line fallback). */
	private static function owns_row( array $row, int $uid ): bool {
		if ( (int) ( $row['created_by'] ?? 0 ) === $uid ) {
			return true;
		}
		// Legacy fallback: match the provenance ("Submitted by:") initials to the party code.
		if ( class_exists( 'ZDZ_Doc_Conventions' ) && class_exists( 'ZDZ_Party' ) ) {
			$code = self::user_code( $uid );
			if ( '' !== $code ) {
				$notes = (string) ( $row['notes'] ?? '' );
				foreach ( preg_split( '/\r\n|\r|\n/', $notes ) as $line ) {
					if ( ZDZ_Doc_Conventions::is_provenance_line( (string) $line )
						&& stripos( (string) $line, $code ) !== false ) {
						return true;
					}
				}
			}
		}
		return false;
	}

	/** The requesting user's party short code (key `initials`, case-insensitive). */
	private static function user_code( int $uid ): string {
		$code = strtoupper( trim( (string) get_user_meta( $uid, 'zdz_user_initials', true ) ) );
		if ( '' === $code ) {
			$code = strtoupper( trim( (string) get_user_meta( $uid, 'ts_user_initials', true ) ) ); // legacy alias
		}
		return $code;
	}

	private static function refuse( string $why ): array {
		return array( 'ok' => false, 'error' => $why );
	}

	/* ---- verbs ---- */

	/**
	 * Draft an estimate from chat. Returns a PREVIEW payload (never sends) — the caller
	 * confirms, then send_from_chat runs. Kiosk-refused.
	 *
	 * @param array $payload { customer, line_items, reference, requesting_user_id }
	 */
	public static function create_from_chat( array $payload ): array {
		$uid = (int) ( $payload['requesting_user_id'] ?? get_current_user_id() );
		if ( self::is_kiosk( $uid ) ) {
			return self::refuse( 'This action is not available on the shared device.' );
		}
		$estimate = array(
			'customer'   => (array) ( $payload['customer'] ?? array() ),
			'line_items' => (array) ( $payload['line_items'] ?? array() ),
			'reference'  => (string) ( $payload['reference'] ?? '' ),
		);
		$ctx = self::output_ctx( $uid );
		// Apply house style to the PREVIEW so the user confirms exactly what will be sent.
		if ( class_exists( 'ZDZ_Doc_Conventions' ) ) {
			$estimate = ZDZ_Doc_Conventions::apply_on_output( $estimate, $ctx );
		}
		return array( 'ok' => true, 'preview' => $estimate, 'confirm_verb' => 'estimate.send' );
	}

	/**
	 * Send a drafted/confirmed estimate to the billing provider. Kiosk-refused, ownership
	 * checked, $0-stub blocked, re-verified server-side. Resolves a missing recipient from
	 * the billing client rather than trusting an omitted email.
	 */
	public static function send_from_chat( array $payload ): array {
		$uid = (int) ( $payload['requesting_user_id'] ?? get_current_user_id() );
		if ( self::is_kiosk( $uid ) ) {
			return self::refuse( 'This action is not available on the shared device.' );
		}
		$estimate = (array) ( $payload['estimate'] ?? array() );
		$lines    = (array) ( $estimate['line_items'] ?? array() );
		$total    = 0.0;
		foreach ( $lines as $li ) {
			$total += (float) ( $li['unit_price'] ?? 0 ) * (int) ( $li['quantity'] ?? 1 );
		}
		if ( $total <= 0 ) {
			return self::refuse( 'This estimate has a $0.00 total — it is a stub awaiting pricing. Add pricing before sending.' );
		}
		$email = ZEST_FreshBooks::clean_email( $estimate['customer']['email'] ?? '' );
		if ( '' === $email ) {
			return self::refuse( 'This estimate needs a valid customer email to send.' );
		}
		$res = ZEST_FreshBooks::create_estimate( $estimate, self::output_ctx( $uid ) );
		if ( empty( $res['ok'] ) ) {
			return self::refuse( $res['error'] ?: 'Send failed.' );
		}
		return array( 'ok' => true, 'number' => $res['number'], 'id' => $res['id'] );
	}

	/**
	 * Read-only lookup of a customer's estimates for chat. Kiosk callers get a redacted
	 * view (server-side, by tier — never by the model): name + work items only.
	 */
	public static function lookup_for_chat( array $payload ): array {
		$uid = (int) ( $payload['requesting_user_id'] ?? get_current_user_id() );
		$customer = trim( (string) ( $payload['customer'] ?? '' ) );
		if ( '' === $customer ) {
			return self::refuse( 'Name a customer to look up.' );
		}
		$client = ZEST_FreshBooks::client();
		if ( ! $client ) {
			return array( 'ok' => true, 'documents' => array(), 'note' => 'Billing is not configured.' );
		}
		// Delegated to the dashboard's shared resolver so chat + UI agree.
		if ( class_exists( 'ZEST_Dashboard' ) && method_exists( 'ZEST_Dashboard', 'lookup_documents' ) ) {
			$docs = ZEST_Dashboard::lookup_documents( $customer, $uid, self::is_kiosk( $uid ) );
			return array( 'ok' => true, 'documents' => $docs );
		}
		return array( 'ok' => true, 'documents' => array() );
	}

	/**
	 * Create a $0 estimate stub from a CRM lead, assigned to a rep. Admin-only (derived
	 * server-side), kiosk-refused. The assignee's code drives the provenance/location
	 * lines via Doc Conventions on output.
	 */
	public static function stub_from_lead( array $payload ): array {
		$uid = (int) ( $payload['requesting_user_id'] ?? get_current_user_id() );
		if ( self::is_kiosk( $uid ) ) {
			return self::refuse( 'This action is not available on the shared device.' );
		}
		if ( ! self::is_admin_tier( $uid ) ) {
			return self::refuse( 'Only an administrator can create a stub from a lead.' );
		}
		if ( class_exists( 'ZEST_Dashboard' ) && method_exists( 'ZEST_Dashboard', 'create_stub_from_lead' ) ) {
			return ZEST_Dashboard::create_stub_from_lead(
				(string) ( $payload['lead'] ?? '' ),
				(string) ( $payload['assignee'] ?? '' ),
				$uid
			);
		}
		return self::refuse( 'Stub creation is unavailable.' );
	}

	/**
	 * Attach an email to an existing estimate so it becomes sendable. Kiosk-refused,
	 * ownership-checked, previewed → confirmed.
	 */
	public static function attach_email_from_chat( array $payload ): array {
		$uid = (int) ( $payload['requesting_user_id'] ?? get_current_user_id() );
		if ( self::is_kiosk( $uid ) ) {
			return self::refuse( 'This action is not available on the shared device.' );
		}
		$email = ZEST_FreshBooks::clean_email( $payload['email'] ?? '' );
		if ( '' === $email ) {
			return self::refuse( 'Provide a valid email to attach.' );
		}
		if ( class_exists( 'ZEST_Dashboard' ) && method_exists( 'ZEST_Dashboard', 'attach_email' ) ) {
			return ZEST_Dashboard::attach_email( (string) ( $payload['estimate_number'] ?? '' ), $email, $uid );
		}
		return self::refuse( 'Attach-email is unavailable.' );
	}

	/* ---- format spec for the chat model, rendered from Doc Conventions ---- */

	/**
	 * A neutral description of the tenant's document conventions for the chat model to
	 * follow when previewing an estimate. Rendered from ZDZ_Doc_Conventions — no literal.
	 */
	public static function format_spec(): array {
		if ( ! class_exists( 'ZDZ_Doc_Conventions' ) ) {
			return array();
		}
		$spec = array();
		$leading = (array) ZDZ_Doc_Conventions::get( 'estimate.leading', array() );
		if ( $leading ) {
			$spec['leading_lines'] = wp_list_pluck( $leading, 'description' );
		}
		$trailing = (array) ZDZ_Doc_Conventions::get( 'estimate.trailing', array() );
		if ( $trailing ) {
			$spec['closing_line'] = $trailing[0]['text'] ?? '';
		}
		$inc = (float) ZDZ_Doc_Conventions::get( 'rounding.price.increment', 0 );
		if ( $inc > 0 ) {
			$spec['price_rounding'] = sprintf( 'Round prices up to the nearest %s.', rtrim( rtrim( number_format( $inc, 2 ), '0' ), '.' ) );
		}
		if ( ZDZ_Doc_Conventions::get( 'estimate.provenance_line.enabled', false ) ) {
			$spec['provenance_line'] = ZDZ_Doc_Conventions::get( 'estimate.provenance_line.template', '' );
		}
		return $spec;
	}

	/** The output context (initials + parenthetical) for the acting/assigned user. */
	private static function output_ctx( int $uid ): array {
		$code  = self::user_code( $uid );
		$paren = (string) get_user_meta( $uid, 'zdz_user_parenthetical', true );
		if ( '' === $paren ) {
			$paren = (string) get_user_meta( $uid, 'ts_user_parenthetical', true ); // legacy alias
		}
		return array( 'initials' => $code, 'parenthetical' => $paren, 'user_id' => $uid );
	}
}
