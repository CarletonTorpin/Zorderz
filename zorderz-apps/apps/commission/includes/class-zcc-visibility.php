<?php
/**
 * ZCC_Visibility — who may see what in Commission.
 *
 *   Costs (COGS, material cost, net after cost, margins) — the administrator tier only, never
 *   in Privacy View, never the shared device, never while an administrator is previewing the
 *   app as another role (View As). Deny by default: an unknown tier sees no cost. The rule is
 *   the price book's (ZDZ_Price_Book::may_see_costs) with View As added.
 *
 *   Pay admin — whoever pays commission: the commission permissions to run a figure AND to see
 *   other people's (ZDZ_Data_Permissions), else manage_options when the permissions service is
 *   absent. View As, Privacy View and the shared device demote to "not a pay admin".
 *
 * shape_calc() takes a calculation and removes every cost field for someone who may not see
 * costs, so a salesperson reads their commission and billed amounts without the cost behind it.
 *
 * @package Zorderz\Commission
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZCC_Visibility {

	/** Fields that carry a cost or a margin, at summary, invoice and line level. */
	const COST_FIELDS = [ 'total_cogs', 'net_commissionable', 'net_attributed', 'net_billed', 'cogs', 'cogs_source', 'net', 'material', 'cost_recheck', 'split' ];

	/** Is an administrator previewing the app as someone else? */
	public static function emulating(): bool {
		return class_exists( 'ZDZ_View_As' ) && method_exists( 'ZDZ_View_As', 'is_emulating' ) && ZDZ_View_As::is_emulating();
	}

	private static function shared_or_private( int $uid ): bool {
		if ( class_exists( 'ZDZ_Privacy_View' ) && ZDZ_Privacy_View::is_on( $uid ) ) {
			return true;
		}
		if ( class_exists( 'ZDZ_User_Roles' ) && method_exists( 'ZDZ_User_Roles', 'is_shared_device' ) && ZDZ_User_Roles::is_shared_device( $uid ) ) {
			return true;
		}
		if ( class_exists( 'ZDZ_Hierarchy' ) && method_exists( 'ZDZ_Hierarchy', 'is_kiosk' ) && ZDZ_Hierarchy::is_kiosk( $uid ) ) {
			return true;
		}
		return false;
	}

	/** May this person see costs and margins? */
	public static function may_see_costs( int $uid ): bool {
		if ( $uid <= 0 || self::emulating() || self::shared_or_private( $uid ) ) {
			return false;
		}
		if ( class_exists( 'ZDZ_Price_Book' ) && method_exists( 'ZDZ_Price_Book', 'may_see_costs' ) ) {
			return (bool) ZDZ_Price_Book::may_see_costs( $uid );
		}
		if ( class_exists( 'ZDZ_Capabilities' ) && method_exists( 'ZDZ_Capabilities', 'tier_of' ) ) {
			return 'admin' === ZDZ_Capabilities::tier_of( $uid );
		}
		return function_exists( 'user_can' ) && user_can( $uid, 'manage_options' );
	}

	/** Does this person pay commission (see everyone's figure, lock months, read review items)? */
	public static function is_pay_admin( int $uid ): bool {
		if ( $uid <= 0 || self::emulating() || self::shared_or_private( $uid ) ) {
			return false;
		}
		if ( class_exists( 'ZDZ_Data_Permissions' ) ) {
			return ZDZ_Data_Permissions::can( $uid, 'run_commission_calculation' )
				&& ZDZ_Data_Permissions::can( $uid, 'view_others_commissions' );
		}
		return function_exists( 'user_can' ) && user_can( $uid, 'manage_options' );
	}

	/** A calculation as this person may see it. */
	public static function shape_calc( array $calc, bool $costs ): array {
		if ( $costs ) {
			return $calc;
		}
		if ( isset( $calc['summary'] ) && is_array( $calc['summary'] ) ) {
			$calc['summary'] = self::strip( $calc['summary'] );
		}
		if ( isset( $calc['invoices'] ) && is_array( $calc['invoices'] ) ) {
			foreach ( $calc['invoices'] as $i => $inv ) {
				if ( ! is_array( $inv ) ) {
					continue;
				}
				$inv = self::strip( $inv );
				if ( isset( $inv['lines'] ) && is_array( $inv['lines'] ) ) {
					foreach ( $inv['lines'] as $j => $ln ) {
						$inv['lines'][ $j ] = is_array( $ln ) ? self::strip( $ln ) : $ln;
					}
				}
				$calc['invoices'][ $i ] = $inv;
			}
		}
		// Flags may quote a cost ("COGS ($x) exceeded …"): only their count stays.
		if ( isset( $calc['flags'] ) && is_array( $calc['flags'] ) ) {
			$calc['flags_count'] = count( $calc['flags'] );
			unset( $calc['flags'] );
		}
		return $calc;
	}

	private static function strip( array $row ): array {
		foreach ( self::COST_FIELDS as $f ) {
			unset( $row[ $f ] );
		}
		return $row;
	}
}
