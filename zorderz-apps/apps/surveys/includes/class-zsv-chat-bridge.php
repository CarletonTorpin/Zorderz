<?php
/**
 * Zorderz Surveys — chat/orchestrator bridge.
 *
 * Two verbs, published to the orchestrator registry:
 *   - survey.lookup  (read-only, tier-aware, kiosk-BOUNDED): "what's <customer>'s last
 *     survey?" — returns a DERIVED outcome (reviewed/sent/pending), never an invented
 *     rating. A shared device gets name + high-level status + date only.
 *   - survey.exclude (write, draft->confirm, kiosk HARD REFUSE): record a request not to
 *     survey a customer. Follows the platform draft contract: preview marker first, and
 *     only the confirmed turn performs the write.
 *
 * Identity/permission is re-derived server-side; a payload can never widen a caller's
 * tier. Marker tokens are constants (ZSV_MARKER_*), never literals in prose.
 *
 * @package Zorderz\Surveys
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSV_Chat_Bridge {

	const SOURCE = 'surveys';

	public static function init(): void {
		// Nothing to hook directly; the engine dispatches via the capability registry.
	}

	/**
	 * Resolve engine-authoritative context. The engine injects user_id/is_kiosk; kiosk
	 * is re-derived from ZDZ_Hierarchy so a payload uid can never impersonate.
	 *
	 * @param array $payload
	 * @return array{user_id:int,is_kiosk:bool}
	 */
	private static function ctx( array $payload ): array {
		$uid      = (int) ( $payload['user_id'] ?? get_current_user_id() );
		$is_kiosk = ! empty( $payload['is_kiosk'] );
		if ( class_exists( 'ZDZ_Hierarchy' ) && $uid > 0 && ZDZ_Hierarchy::is_kiosk( $uid ) ) {
			$is_kiosk = true;
		}
		return array( 'user_id' => $uid, 'is_kiosk' => (bool) $is_kiosk );
	}

	/** Can this user see other people's data (dollar figures, salesperson, contact)? */
	private static function can_see_others( int $uid ): bool {
		if ( $uid < 1 ) {
			return false;
		}
		if ( class_exists( 'ZDZ_Data_Permissions' ) && method_exists( 'ZDZ_Data_Permissions', 'can' ) ) {
			return (bool) ZDZ_Data_Permissions::can( $uid, 'view_others_data' );
		}
		return user_can( $uid, 'manage_options' );
	}

	/* ─────────────────────────────────────────────────────────────────
	 * survey.lookup — read-only, kiosk-bounded.
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * @param array $payload { query|email, user_id, is_kiosk }
	 * @return array Structured result; never fabricates on empty.
	 */
	public static function lookup_for_chat( array $payload ): array {
		$ctx   = self::ctx( $payload );
		$query = trim( (string) ( $payload['query'] ?? $payload['email'] ?? '' ) );
		if ( '' === $query ) {
			return array( 'ok' => false, 'reason' => 'no_query' );
		}

		global $wpdb;
		$t   = ZSV_DB::leads_table();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT first_name, last_name, email, city, salesperson_name, operator_status, email_sent_at, review_left, review_date, created_at
				 FROM {$t}
				 WHERE email = %s OR CONCAT(first_name,' ',last_name) LIKE %s
				 ORDER BY created_at DESC LIMIT 1",
				strtolower( $query ),
				'%' . $wpdb->esc_like( $query ) . '%'
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return array( 'ok' => true, 'found' => false );
		}

		$outcome = self::derive_outcome( $row );
		$name    = trim( ( $row['first_name'] ?? '' ) . ' ' . ( $row['last_name'] ?? '' ) );

		// Kiosk / unprivileged: name + high-level status + date only. No PII, no rep.
		if ( $ctx['is_kiosk'] || ! self::can_see_others( $ctx['user_id'] ) ) {
			return array(
				'ok'      => true,
				'found'   => true,
				'name'    => $name,
				'outcome' => $outcome,
				'date'    => $row['created_at'] ?? '',
			);
		}

		return array(
			'ok'          => true,
			'found'       => true,
			'name'        => $name,
			'email'       => $row['email'] ?? '',
			'city'        => $row['city'] ?? '',
			'salesperson' => $row['salesperson_name'] ?? '',
			'outcome'     => $outcome,
			'operator_status' => $row['operator_status'] ?? '',
			'date'        => $row['created_at'] ?? '',
		);
	}

	/** Derive an honest outcome; never invents a numeric score. */
	private static function derive_outcome( array $row ): string {
		if ( ! empty( $row['review_left'] ) ) {
			return 'reviewed';
		}
		if ( ! empty( $row['email_sent_at'] ) ) {
			return 'invited';
		}
		if ( 'satisfied' === ( $row['operator_status'] ?? '' ) ) {
			return 'confirmed_satisfied';
		}
		return 'pending';
	}

	/* ─────────────────────────────────────────────────────────────────
	 * survey.exclude — write, draft->confirm, kiosk HARD REFUSE.
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * @param array $payload { email, name, reason, confirmed, user_id, is_kiosk }
	 * @return array
	 */
	public static function exclude_for_chat( array $payload ): array {
		$ctx = self::ctx( $payload );

		// Shared device: HARD REFUSE a side-effecting verb (safety floor).
		if ( $ctx['is_kiosk'] ) {
			return array( 'ok' => false, 'refused' => true, 'reason' => 'kiosk_no_side_effects' );
		}
		if ( ! self::can_see_others( $ctx['user_id'] ) && ! user_can( $ctx['user_id'], 'manage_options' ) ) {
			return array( 'ok' => false, 'refused' => true, 'reason' => 'insufficient_capability' );
		}

		$reason = trim( (string) ( $payload['reason'] ?? '' ) );
		$email  = trim( (string) ( $payload['email'] ?? '' ) );
		$name   = trim( (string) ( $payload['name'] ?? '' ) );

		// Draft turn: never write; return a preview the UI/engine renders as a confirm
		// card. The write happens only on the confirmed turn.
		if ( empty( $payload['confirmed'] ) ) {
			return array(
				'ok'          => true,
				'needs_confirm' => true,
				'marker'      => ZSV_MARKER_EXCLUDE_DRAFT,
				'preview'     => sprintf(
					/* translators: 1: customer, 2: reason */
					__( 'Exclude %1$s from future surveys? Reason: %2$s', 'zorderz' ),
					$name !== '' ? $name : $email,
					$reason !== '' ? $reason : __( '(none given)', 'zorderz' )
				),
			);
		}

		if ( '' === $reason ) {
			return array( 'ok' => false, 'reason' => 'reason_required' );
		}

		$manager = new ZSV_Survey_Manager();
		$res     = $manager->exclude_customer_with_reason(
			array(
				'email'     => $email,
				'name'      => $name,
				'reason'    => $reason,
				'permanent' => ! empty( $payload['permanent'] ),
				'actor'     => self::actor_name( $ctx['user_id'] ),
			)
		);
		return array( 'ok' => ! empty( $res['ok'] ), 'wrote' => $res['wrote'] ?? array(), 'marker' => ZSV_MARKER_EXCLUDE );
	}

	private static function actor_name( int $uid ): string {
		$u = $uid > 0 ? get_userdata( $uid ) : null;
		return $u ? $u->display_name : '';
	}

	/* ─────────────────────────────────────────────────────────────────
	 * Registry descriptor.
	 * ────────────────────────────────────────────────────────────────── */

	public static function get_capability_descriptor(): array {
		return array(
			'survey.lookup'  => array(
				'verb'        => 'survey.lookup',
				'source'      => self::SOURCE,
				'callable'    => array( __CLASS__, 'lookup_for_chat' ),
				'marker'      => ZSV_MARKER_LOOKUP,
				'min_tier'    => 'kiosk',   // read is allowed, but bounded server-side.
				'kiosk'       => true,
				'side_effect' => false,
				'confirm'     => false,
				'summary'     => 'Look up a customer\'s most recent satisfaction follow-up (derived outcome, never an invented score).',
			),
			'survey.exclude' => array(
				'verb'        => 'survey.exclude',
				'source'      => self::SOURCE,
				'callable'    => array( __CLASS__, 'exclude_for_chat' ),
				'marker'      => ZSV_MARKER_EXCLUDE,
				'draft'       => ZSV_MARKER_EXCLUDE_DRAFT,
				'min_tier'    => 'staff',
				'kiosk'       => false,      // HARD REFUSE on a shared device.
				'side_effect' => true,
				'confirm'     => true,
				'summary'     => 'Record a request not to survey a customer (draft -> confirm; writes a CRM note).',
			),
		);
	}
}
