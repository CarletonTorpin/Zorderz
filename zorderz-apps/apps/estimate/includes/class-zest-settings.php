<?php
/**
 * ZEST_Settings - the estimate app's rows on Zorderz -> Settings (administrators).
 *
 *   Estimates Ai road        zest_ai_route: the platform's Ai router (the default, when it is set up) or the chat
 *                            gateway, as ZEST_Router_Client reads it.
 *   Payment schedule notes   zest_payment_notes_mode: off / test (test customers only, the default) / on, as
 *                            ZEST_Terms_Note reads it.
 *   Customer lookup index    how far the copy of the billing system's client list is (ZEST_Client_Index), read only.
 *   Line choices             where the press-and-hold option map in use came from (ZEST_Line_Options), read only.
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZEST_Settings {

	public static function init(): void {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'zdz_core_settings_rows', array( __CLASS__, 'rows' ) );
	}

	public static function register(): void {
		register_setting( 'zdz_core_settings_group', 'zest_ai_route', array(
			'type'              => 'string',
			'default'           => 'router',
			'sanitize_callback' => static function ( $v ) { return 'gateway' === $v ? 'gateway' : 'router'; },
		) );
		register_setting( 'zdz_core_settings_group', 'zest_payment_notes_mode', array(
			'type'              => 'string',
			'default'           => 'test',
			'sanitize_callback' => static function ( $v ) { return in_array( $v, array( 'off', 'test', 'on' ), true ) ? $v : 'test'; },
		) );
	}

	public static function rows(): void {
		$route = (string) get_option( 'zest_ai_route', 'router' );
		$mode  = class_exists( 'ZEST_Terms_Note' ) ? ZEST_Terms_Note::mode() : 'test';
		$st    = class_exists( 'ZEST_Client_Index' ) ? ZEST_Client_Index::state() : array();
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Estimates Ai road', 'zorderz' ); ?></th>
			<td>
				<select name="zest_ai_route">
					<option value="router" <?php selected( 'gateway' !== $route ); ?>><?php esc_html_e( 'The platform\'s Ai router (when it is set up)', 'zorderz' ); ?></option>
					<option value="gateway" <?php selected( 'gateway' === $route ); ?>><?php esc_html_e( 'The chat gateway', 'zorderz' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'Where Estimates sends its Ai reads. The router codes customer details before they leave the site and keeps to the Ai budget.', 'zorderz' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Payment schedule in estimate notes', 'zorderz' ); ?></th>
			<td>
				<select name="zest_payment_notes_mode">
					<option value="off" <?php selected( 'off' === $mode ); ?>><?php esc_html_e( 'Off', 'zorderz' ); ?></option>
					<option value="test" <?php selected( 'test' === $mode ); ?>><?php esc_html_e( 'Test customers only', 'zorderz' ); ?></option>
					<option value="on" <?php selected( 'on' === $mode ); ?>><?php esc_html_e( 'On', 'zorderz' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'Approve writes the deposit and final payment into the notes of estimates in a payment-schedule group.', 'zorderz' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Customer lookup index', 'zorderz' ); ?></th>
			<td><p class="description"><?php
				if ( ! empty( $st['complete'] ) ) {
					/* translators: %d: clients */
					echo esc_html( sprintf( __( 'Ready: %d clients from the billing system.', 'zorderz' ), (int) ( $st['total'] ?? 0 ) ) );
				} elseif ( (int) ( $st['pages'] ?? 0 ) > 0 ) {
					/* translators: 1: pages read, 2: pages */
					echo esc_html( sprintf( __( 'Building: page %1$d of %2$d. Lookups ask the billing system until it is ready.', 'zorderz' ), max( 0, (int) $st['page'] - 1 ), (int) $st['pages'] ) );
				} else {
					esc_html_e( 'Not built yet. It builds in the background once the billing system is connected.', 'zorderz' );
				}
				if ( '' !== (string) ( $st['last_error'] ?? '' ) ) {
					echo ' ' . esc_html( (string) $st['last_error'] );
				}
			?></p></td>
		</tr>
		<?php $lo = class_exists( 'ZEST_Line_Options' ) ? ZEST_Line_Options::status() : array( 'from' => 'none' ); ?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Line choices', 'zorderz' ); ?></th>
			<td><p class="description"><?php
				$from = array(
					'hub'  => __( 'From the charts hub', 'zorderz' ),
					'pack' => __( 'From the Identity Pack', 'zorderz' ),
					'none' => __( 'None: the editors offer no press-and-hold choices until a pack or the charts hub supplies them.', 'zorderz' ),
				);
				echo esc_html( $from[ $lo['from'] ] ?? $from['none'] );
				if ( '' !== (string) ( $lo['built'] ?? '' ) ) {
					/* translators: %s: a date */
					echo ' ' . esc_html( sprintf( __( '(built %s)', 'zorderz' ), (string) $lo['built'] ) );
				}
				if ( '' !== (string) ( $lo['last_error'] ?? '' ) ) {
					echo ' ' . esc_html( sprintf( __( 'Last fetch: %s.', 'zorderz' ), (string) $lo['last_error'] ) );
				}
			?></p></td>
		</tr>
		<?php
	}
}
