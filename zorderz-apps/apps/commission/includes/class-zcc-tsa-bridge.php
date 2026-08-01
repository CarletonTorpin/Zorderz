<?php
/**
 * ZCC_TSA_Bridge — cross-app capability provider for analytics + the chat
 * orchestrator.
 *
 * The theme orchestrator (ZDZ_Orchestrator) calls this to render an inline
 * commission card or a unit tally. Because the shipped theme still references
 * the historical class name, a `class_alias( 'ZCC_TSA_Bridge', 'TSCC_TSA_Bridge' )`
 * at the end of this file keeps `TSCC_TSA_Bridge::commission_calc_for_tsa()`
 * resolving — a documented deprecated alias, per Playbook §4.
 *
 * SAFETY: the shared kiosk HARD-REFUSES any figure; the tier gate
 * (ZDZ_Data_Permissions) is checked BEFORE any computation; the amount is
 * OMITTED from the return when the caller's tier does not permit it. Read-only —
 * no pay path is ever mutated here.
 *
 * @package Zorderz\Commission
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZCC_TSA_Bridge {

	public static function is_available(): bool {
		return class_exists( 'ZCC_Calc_Engine' )
			&& class_exists( 'ZCC_FreshBooks' )
			&& class_exists( 'ZDZ_Compensation' )
			&& ZCC_FreshBooks::is_connected();
	}

	/* ==================================================================
	 * COMMISSION FIGURE
	 * ================================================================== */

	public static function commission_calc_for_tsa( array $payload ): array {
		$result = [ 'success' => false, 'subject' => '', 'period' => '', 'denied' => false, 'message' => '', 'error' => '', 'source' => 'zcc_bridge' ];

		if ( ! self::is_available() ) {
			$result['error'] = 'Commission calculator is not active or FreshBooks is not connected.';
			return $result;
		}

		$requesting_uid = self::resolve_requesting_uid( $payload );

		// KIOSK = HARD REFUSE.
		if ( self::resolve_is_kiosk( $payload, $requesting_uid ) ) {
			$result['success'] = true;
			$result['denied']  = true;
			$result['message'] = "Commission figures aren't available on the shared device. Sign in on your own device.";
			return $result;
		}

		// Resolve subject.
		$subject_raw = trim( (string) ( $payload['subject'] ?? '' ) );
		$subject     = self::resolve_subject( $subject_raw, $requesting_uid );
		if ( empty( $subject['party_id'] ) ) {
			$result['success'] = true;
			$result['message'] = $subject_raw === '' ? 'Whose commission should I calculate?' : "I couldn't match \"{$subject_raw}\" to a rep with a compensation plan.";
			return $result;
		}
		$subject_uid       = (int) $subject['party_id'];
		$result['subject'] = (string) $subject['display_name'];
		$is_self           = ( $subject_uid === $requesting_uid );

		// TIER GATE — before any computation.
		$gate = self::authorize( $requesting_uid, $is_self );
		if ( ! $gate['allowed'] ) {
			$result['success'] = true;
			$result['denied']  = true;
			$result['message'] = $gate['message'];
			return $result;
		}

		$window           = self::resolve_period( $payload );
		$result['period'] = $window['label'];

		try {
			$invoices = ZCC_FreshBooks::get_invoices( $window['date_start'], $window['date_end'], $payload['status'] ?? null );
			$calc     = ZCC_Calc_Engine::calculate( $invoices, $subject_uid, str_replace( '-', '', substr( $window['date_start'], 0, 7 ) ) );
		} catch ( \Throwable $e ) {
			$result['error'] = 'Could not complete the commission calculation: ' . $e->getMessage();
			return $result;
		}

		$summary = $calc['summary'] ?? [];
		$amount  = (float) ( $summary['total_commission'] ?? 0 );

		if ( class_exists( 'ZCC_Audit' ) ) {
			$result['audit_id'] = ZCC_Audit::log_calculation( $calc, $requesting_uid, $subject_uid, $window['date_start'], $window['date_end'] );
		}

		$result['success'] = true;
		$result['amount']  = round( $amount, 2 );
		$result['basis']   = sprintf( '%s on %s net, %d invoice(s)', $summary['commission_rate'] ?? '', self::money( $summary['net_commissionable'] ?? 0 ), (int) ( $summary['invoice_count'] ?? 0 ) );
		$result['breakdown'] = self::build_breakdown( $calc );
		$result['message'] = sprintf( '%s earned %s in %s.', $result['subject'], self::money( $amount ), $window['label'] );
		return $result;
	}

	/* ==================================================================
	 * UNIT TALLY  (item-keyed counts for analytics)
	 * ================================================================== */

	public static function unit_counts_for_tsa( array $payload ): array {
		$result = [ 'success' => false, 'period' => '', 'counts' => [], 'counts_v2' => [], 'units_total' => 0, 'job_count' => 0, 'unclassified_lines' => 0, 'unclassified_amount' => 0.0, 'denied' => false, 'message' => '', 'error' => '', 'source' => 'zcc_bridge' ];

		if ( ! class_exists( 'ZCC_FreshBooks' ) || ! ZCC_FreshBooks::is_connected() || ! class_exists( 'ZCC_Installer_Pay' ) ) {
			$result['error'] = 'Commission calculator is not active or FreshBooks is not connected.';
			return $result;
		}
		$requesting_uid = self::resolve_requesting_uid( $payload );
		if ( self::resolve_is_kiosk( $payload, $requesting_uid ) ) {
			$result['success'] = true;
			$result['denied']  = true;
			$result['message'] = "Sales tallies aren't available on the shared device.";
			return $result;
		}
		$window           = self::resolve_period( $payload );
		$result['period'] = $window['label'];

		try {
			$counted = ZCC_Installer_Pay::count_units( 'ALL', $window['date_start'], $window['date_end'], null );
		} catch ( \Throwable $e ) {
			$result['error'] = 'Could not complete the unit count: ' . $e->getMessage();
			return $result;
		}
		if ( ! empty( $counted['errors'] ) ) {
			$result['error'] = 'Unit count could not be completed: ' . implode( '; ', (array) $counted['errors'] );
			return $result;
		}

		$result['counts']              = array_map( 'intval', $counted['counts'] ?? [] );
		$result['counts_v2']           = $counted['counts_v2'] ?? [];
		$result['units_total']         = (int) ( $counted['units_total'] ?? array_sum( $result['counts'] ) );
		$result['job_count']           = (int) ( $counted['invoice_count'] ?? 0 );
		$result['unclassified_lines']  = (int) ( $counted['unclassified_lines'] ?? 0 );
		$result['unclassified_amount'] = round( (float) ( $counted['unclassified_amount'] ?? 0 ), 2 );
		$result['success']             = true;
		$tail = $result['unclassified_lines'] > 0 ? sprintf( ' (%d line(s) need manual review — not estimated)', $result['unclassified_lines'] ) : '';
		$result['message'] = sprintf( '%d unit(s) across %d job(s) in %s%s.', $result['units_total'], $result['job_count'], $window['label'], $tail );
		return $result;
	}

	/* ==================================================================
	 * HELPERS
	 * ================================================================== */

	private static function resolve_requesting_uid( array $payload ): int {
		$session = (int) get_current_user_id();
		return $session > 0 ? $session : (int) ( $payload['requesting_user_id'] ?? 0 );
	}

	private static function resolve_is_kiosk( array $payload, int $uid ): bool {
		if ( ! empty( $payload['is_kiosk'] ) ) {
			return true;
		}
		if ( class_exists( 'ZDZ_Hierarchy' ) && method_exists( 'ZDZ_Hierarchy', 'is_kiosk' ) ) {
			return (bool) ZDZ_Hierarchy::is_kiosk( $uid );
		}
		return false;
	}

	/** Map a name / code / "me" to a party id + display name via the roster + Compensation. */
	private static function resolve_subject( string $raw, int $requesting_uid ): array {
		$raw = trim( $raw );
		if ( $raw === '' || in_array( strtolower( $raw ), [ 'me', 'self', 'my' ], true ) ) {
			$u = get_userdata( $requesting_uid );
			return [ 'party_id' => $requesting_uid, 'display_name' => $u ? $u->display_name : '' ];
		}
		// A short code?
		if ( preg_match( '/^[A-Za-z]{2,4}$/', $raw ) && class_exists( 'ZDZ_Compensation' ) ) {
			$plan = ZDZ_Compensation::plan_by_code( strtoupper( $raw ) );
			if ( $plan ) {
				return [ 'party_id' => (int) $plan['party_id'], 'display_name' => (string) $plan['display_name'] ];
			}
		}
		// A display name via the party roster.
		if ( class_exists( 'ZDZ_Party' ) && method_exists( 'ZDZ_Party', 'selectable_people' ) ) {
			$needle = strtolower( $raw );
			foreach ( (array) ZDZ_Party::selectable_people() as $p ) {
				if ( strpos( strtolower( (string) ( $p['name'] ?? '' ) ), $needle ) !== false ) {
					return [ 'party_id' => (int) ( $p['id'] ?? 0 ), 'display_name' => (string) ( $p['name'] ?? '' ) ];
				}
			}
		}
		return [ 'party_id' => 0, 'display_name' => '' ];
	}

	/** Tier gate via ZDZ_Data_Permissions. Own vs others; plus run permission. */
	private static function authorize( int $uid, bool $is_self ): array {
		if ( ! class_exists( 'ZDZ_Data_Permissions' ) ) {
			return [ 'allowed' => user_can( $uid, 'manage_options' ), 'message' => 'Not permitted.' ];
		}
		if ( ! ZDZ_Data_Permissions::can( $uid, 'run_commission_calculation' ) ) {
			return [ 'allowed' => false, 'message' => "You don't have permission to run commission calculations." ];
		}
		$perm = $is_self ? 'view_own_commission' : 'view_others_commissions';
		if ( ! ZDZ_Data_Permissions::can( $uid, $perm ) ) {
			return [ 'allowed' => false, 'message' => $is_self ? "You don't have access to your commission details." : "You don't have access to another rep's commission details." ];
		}
		return [ 'allowed' => true, 'message' => '' ];
	}

	/** Resolve a period phrase / explicit dates → a concrete window. */
	private static function resolve_period( array $payload ): array {
		$tz_now = current_time( 'Y-m-d' );
		if ( ! empty( $payload['date_start'] ) && ! empty( $payload['date_end'] ) ) {
			return [ 'date_start' => (string) $payload['date_start'], 'date_end' => (string) $payload['date_end'], 'label' => $payload['date_start'] . ' – ' . $payload['date_end'] ];
		}
		$p = strtolower( trim( (string) ( $payload['period'] ?? 'this_month' ) ) );
		if ( preg_match( '/^\d{4}-\d{2}$/', $p ) ) {
			$start = $p . '-01';
			$end   = gmdate( 'Y-m-t', strtotime( $start ) );
			return [ 'date_start' => $start, 'date_end' => $end, 'label' => $p ];
		}
		switch ( $p ) {
			case 'last_month':
				$start = gmdate( 'Y-m-01', strtotime( 'first day of last month', strtotime( $tz_now ) ) );
				$end   = gmdate( 'Y-m-t', strtotime( $start ) );
				return [ 'date_start' => $start, 'date_end' => $end, 'label' => gmdate( 'Y-m', strtotime( $start ) ) ];
			case 'mtd':
			case 'this_month':
			default:
				$start = gmdate( 'Y-m-01', strtotime( $tz_now ) );
				return [ 'date_start' => $start, 'date_end' => $tz_now, 'label' => gmdate( 'Y-m', strtotime( $start ) ) ];
		}
	}

	private static function build_breakdown( array $calc ): array {
		$rows = [];
		foreach ( (array) ( $calc['invoices'] ?? [] ) as $inv ) {
			$rows[] = [
				'invoice_number'    => $inv['invoice_number'] ?? '',
				'customer_name'     => $inv['customer_name'] ?? '',
				'date'              => $inv['date_completed'] ?? '',
				'commission_amount' => (float) ( $inv['commission_amount'] ?? 0 ),
				'fb_url'            => $inv['fb_url'] ?? '',
			];
		}
		return [ 'invoices' => $rows, 'summary' => $calc['summary'] ?? [] ];
	}

	private static function money( $n ): string {
		$sign = class_exists( 'ZDZ_Business_Profile' ) ? (string) ZDZ_Business_Profile::get( 'locale.currency_sign', '$' ) : '$';
		return $sign . number_format( (float) $n, 2 );
	}

	/* ── Format specs the orchestrator/bot reads to learn the verbs ── */

	public static function get_format_spec(): array {
		return [
			'marker'      => ( defined( 'ZCC_CALC_MARKER' ) ? ZCC_CALC_MARKER : '[ZCC_CALC]' ) . '{json}[/ZCC_CALC]',
			'deprecated'  => [ '[TSCC_CALC]' ],
			'payload'     => [ 'subject' => 'string — name, code, or "me"', 'period' => 'string — "YYYY-MM" | "this_month" | "last_month" | "mtd"' ],
			'side_effect' => false,
			'kiosk'       => false,
			'notes'       => 'Read-only. Hard-refuses on the shared device. Own vs others is tier-gated; the amount is omitted when the tier does not permit it.',
		];
	}

	public static function get_capability_descriptor(): array {
		return [ 'id' => 'commission.calc', 'label' => __( 'Commission calculation', 'zorderz' ), 'read_only' => true, 'kiosk_forbidden' => true, 'format' => self::get_format_spec() ];
	}

	public static function get_units_format_spec(): array {
		return [
			'marker'      => '[ZCC_UNITS]{json}[/ZCC_UNITS]',
			'deprecated'  => [ '[TSCC_UNITS]' ],
			'payload'     => [ 'period' => 'string — "YYYY-MM" | "this_month" | "last_month" | "mtd"' ],
			'side_effect' => false,
			'kiosk'       => false,
			'notes'       => 'Read-only company-wide unit tally, item-keyed (item_keyed_v2).',
		];
	}

	public static function get_units_capability_descriptor(): array {
		return [ 'id' => 'commission.units', 'label' => __( 'Sales unit tally', 'zorderz' ), 'read_only' => true, 'kiosk_forbidden' => true, 'format' => self::get_units_format_spec() ];
	}
}

/*
 * Deprecated alias: the shipped theme orchestrator still references the historical
 * class name. class_alias keeps TSCC_TSA_Bridge::commission_calc_for_tsa() (and
 * class_exists / method_exists on it) resolving to this class. Remove once every
 * caller has migrated to ZCC_TSA_Bridge.
 */
if ( ! class_exists( 'TSCC_TSA_Bridge', false ) ) {
	class_alias( 'ZCC_TSA_Bridge', 'TSCC_TSA_Bridge' );
}
