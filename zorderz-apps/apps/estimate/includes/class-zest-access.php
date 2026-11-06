<?php
/**
 * ZEST_Access - the checks every estimate endpoint makes, in one place.
 *
 *   guard()          nonce (zest_nonce) and a signed-in person, else 403; returns the user id
 *   no_kiosk()       the shared device and Demo Mode never write (403)
 *   row( $id )       this app's estimate row by id, or null
 *   may_open()       the admin tier, or the estimate is the person's by ZEST_Salesperson::owned_state() (creator, a
 *                    person its codes name, an administrator's grant; never after an administrator removed access)
 *   load_owned()     guard + row + may_open in one, for an AJAX handler; 403 / 404 with a plain message otherwise
 *   field_permissions()  what a person may see and change on an estimate's lines (filter zest_field_permissions)
 *
 * Every refusal is a plain sentence; none says whose the estimate is.
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZEST_Access {

	public static function guard(): int {
		if ( ! check_ajax_referer( defined( 'ZEST_NONCE' ) ? ZEST_NONCE : 'zest_nonce', 'nonce', false ) || ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'zorderz' ) ), 403 );
		}
		return (int) get_current_user_id();
	}

	public static function is_kiosk( int $uid ): bool {
		if ( class_exists( 'ZEST_Salesperson' ) && ZEST_Salesperson::is_kiosk( $uid ) ) {
			return true;
		}
		return class_exists( 'ZDZ_Kiosk_Demo' ) && method_exists( 'ZDZ_Kiosk_Demo', 'is_active' ) && ZDZ_Kiosk_Demo::is_active();
	}

	public static function no_kiosk( int $uid ): void {
		if ( self::is_kiosk( $uid ) ) {
			wp_send_json_error( array( 'message' => __( 'This action is not available on the shared device. Sign in as yourself.', 'zorderz' ) ), 403 );
		}
	}

	public static function is_admin_tier( int $uid ): bool {
		return class_exists( 'ZEST_Salesperson' ) && ZEST_Salesperson::is_admin_tier( $uid );
	}

	/** This app's row by id (the estimates table), or null. */
	public static function row( int $id ): ?array {
		if ( $id <= 0 || ! class_exists( 'ZEST_DB' ) ) {
			return null;
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ZEST_DB::estimates_table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name (ZEST_DB::estimates_table())
		return is_array( $row ) ? $row : null;
	}

	/** The newest row for a billing document number, or null. A number of digits matches with or without leading zeros. */
	public static function row_by_number( string $number ): ?array {
		$number = substr( preg_replace( '/[^A-Za-z0-9#\-]/', '', $number ), 0, 40 );
		if ( '' === $number || ! class_exists( 'ZEST_DB' ) ) {
			return null;
		}
		global $wpdb;
		$t = ZEST_DB::estimates_table();
		if ( ctype_digit( $number ) && '' !== ltrim( $number, '0' ) ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE TRIM(LEADING '0' FROM billing_doc_num) = %s ORDER BY id DESC LIMIT 1", ltrim( $number, '0' ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is $wpdb->prefix plus a fixed name (ZEST_DB::estimates_table())
		} else {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE billing_doc_num = %s ORDER BY id DESC LIMIT 1", $number ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is $wpdb->prefix plus a fixed name (ZEST_DB::estimates_table())
		}
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Field-level permissions for a person (the widget, line edits, the billing view, the sale form). Neutral defaults:
	 * the estimate's owner sees and edits its prices, quantities and wording; a business narrows them on the filter.
	 */
	public static function field_permissions( int $user_id ): array {
		$perms = array( 'view_pricing' => true, 'edit_pricing' => true, 'edit_quantity' => true, 'edit_wording' => true );
		return (array) apply_filters( 'zest_field_permissions', $perms, $user_id );
	}

	public static function may_open( array $row, int $uid ): bool {
		return class_exists( 'ZEST_Salesperson' ) && ZEST_Salesperson::owned_by( $row, $uid );
	}

	/** [ uid, row ] for an AJAX handler, or a JSON refusal (which ends the request). */
	public static function load_owned( string $param = 'id', bool $write = true ): array {
		$uid = self::guard();
		if ( $write ) {
			self::no_kiosk( $uid );
		}
		$id  = isset( $_POST[ $param ] ) ? absint( wp_unslash( $_POST[ $param ] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- guard() checked it
		$row = self::row( $id );
		if ( ! $row ) {
			wp_send_json_error( array( 'message' => __( 'That estimate was not found.', 'zorderz' ) ), 404 );
		}
		if ( ! self::may_open( $row, $uid ) ) {
			wp_send_json_error( array( 'code' => 'not_yours', 'message' => __( 'This estimate is not yours. You can ask an administrator for access to it.', 'zorderz' ) ), 403 );
		}
		return array( $uid, $row );
	}
}
