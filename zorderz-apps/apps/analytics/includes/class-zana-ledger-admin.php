<?php
/**
 * ZANA_Ledger_Admin — the ledger's panel on Zorderz → Settings, its "Sync now" button, and its REST routes.
 *
 * The panel shows what the copy holds (counts, the year loaded back to, the last good sync, whether a part is failing,
 * whether the stored labels are being re-derived, the shadow check's tally) and never a customer's name, a document
 * number or an error's text. "Sync now" runs one pass for an administrator (never on a staging copy).
 *
 * REST (under ZDZ_REST_NS):
 *   GET  /analytics/ledger/status          administrators: ZANA_Ledger::coverage()
 *   POST /analytics/ledger/sync            administrators: one pass now
 *   GET  /analytics/invoice-doc            signed in: one invoice or estimate as the customer got it (ZANA_Invoice_Doc::open)
 *   POST /analytics/invoice-link           signed in: the customer's link for one invoice (ZANA_Invoice_Doc::link)
 * Every route's permission check runs on the real user; the document routes also gate inside ZANA_Invoice_Doc.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Ledger_Admin {

	const ACTION = 'zana_ledger_sync_now';

	public static function init(): void {
		add_action( 'zdz_core_settings_after', array( __CLASS__, 'render_panel' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_sync' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/* ─────────────────────────── the panel ─────────────────────────── */

	protected static function when( $utc ): string {
		$t = strtotime( (string) $utc . ' UTC' );
		if ( ! $t ) {
			return __( 'never', 'zorderz' );
		}
		return function_exists( 'wp_date' ) ? (string) wp_date( 'M j, g:i a', $t ) : gmdate( 'M j, H:i', $t ) . ' UTC';
	}

	public static function render_panel(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$cov   = ZANA_Ledger::coverage();
		$label = array(
			'invoices'  => __( 'Invoices', 'zorderz' ),
			'estimates' => __( 'Estimates', 'zorderz' ),
			'payments'  => __( 'Payments', 'zorderz' ),
			'leads'     => __( 'CRM leads', 'zorderz' ),
		);
		echo '<h2 id="zana-ledger" style="margin-top:34px">' . esc_html__( 'Stored-data ledger', 'zorderz' ) . '</h2>';
		echo '<p style="max-width:900px">' . esc_html__( 'A copy of the billing system\'s invoices, estimates and payments and the CRM\'s leads, kept in step every hour. Home figures and quick answers read this copy instead of the providers. It only ever reads from them.', 'zorderz' ) . '</p>';
		if ( ! empty( $cov['staging'] ) ) {
			echo '<p><strong>' . esc_html__( 'This is a staging copy: the ledger does not sync here.', 'zorderz' ) . '</strong></p>';
		}
		echo '<table class="widefat striped" style="max-width:980px"><thead><tr><th>' . esc_html__( 'Copy', 'zorderz' ) . '</th><th>' . esc_html__( 'Rows', 'zorderz' ) . '</th><th>' . esc_html__( 'History', 'zorderz' ) . '</th><th>' . esc_html__( 'Last good sync', 'zorderz' ) . '</th><th>' . esc_html__( 'State', 'zorderz' ) . '</th></tr></thead><tbody>';
		foreach ( $label as $k => $l ) {
			$c       = (array) ( $cov[ $k ] ?? array() );
			$history = ! empty( $c['complete'] )
				? ( isset( $c['loaded_from'] ) && null !== $c['loaded_from'] ? sprintf( /* translators: %s: a year */ __( 'complete, from %s', 'zorderz' ), (string) $c['loaded_from'] ) : __( 'complete', 'zorderz' ) )
				: ( isset( $c['loading'] ) && null !== $c['loading'] ? sprintf( /* translators: %s: a year */ __( 'loading (%s)', 'zorderz' ), (string) $c['loading'] ) : __( 'not started', 'zorderz' ) );
			$state   = ! empty( $c['last_error'] ) ? __( 'the last pass failed', 'zorderz' ) : ( ! empty( $c['relabelling'] ) ? __( 're-deriving labels', 'zorderz' ) : __( 'ok', 'zorderz' ) );
			echo '<tr><td>' . esc_html( $l ) . '</td><td>' . esc_html( number_format_i18n( (int) ( $c['rows'] ?? 0 ) ) ) . '</td><td>' . esc_html( $history ) . '</td><td>' . esc_html( self::when( $c['last_ok'] ?? '' ) ) . '</td><td>' . esc_html( $state ) . '</td></tr>';
		}
		$n = (array) ( $cov['notes'] ?? array() );
		echo '<tr><td>' . esc_html__( 'CRM lead notes', 'zorderz' ) . '</td><td>' . esc_html( number_format_i18n( (int) ( $n['notes'] ?? 0 ) ) ) . '</td><td>'
			. esc_html( ! empty( $n['complete'] ) ? __( 'complete', 'zorderz' ) : sprintf( /* translators: %d: leads read */ __( '%d leads read', 'zorderz' ), (int) ( $n['leads_read'] ?? 0 ) ) ) . '</td><td>'
			. esc_html( self::when( $n['last_ok'] ?? '' ) ) . '</td><td>' . esc_html( ! empty( $n['last_error'] ) ? __( 'the last pass failed', 'zorderz' ) : ( empty( $n['ready'] ) ? __( 'not set up', 'zorderz' ) : __( 'ok', 'zorderz' ) ) ) . '</td></tr>';
		echo '</tbody></table>';
		$tally = get_option( ZANA_Ledger_Shadow::TALLY_OPTION, array() );
		if ( is_array( $tally ) && $tally ) {
			$sum = array();
			foreach ( $tally as $day ) {
				foreach ( (array) $day as $k => $v ) {
					$sum[ $k ] = ( $sum[ $k ] ?? 0 ) + (int) $v;
				}
			}
			arsort( $sum );
			$bits = array();
			foreach ( $sum as $k => $v ) {
				$bits[] = $k . ': ' . (int) $v;
			}
			echo '<p class="description">' . esc_html( __( 'Shadow check over the last 30 days', 'zorderz' ) . ' — ' . implode( ', ', $bits ) ) . '</p>';
		}
		if ( empty( $cov['staging'] ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( self::ACTION );
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
			echo '<p><button class="button" type="submit">' . esc_html__( 'Sync now', 'zorderz' ) . '</button> <span class="description">' . esc_html__( 'One pass of about half a minute; a history still loading carries on by itself every two minutes.', 'zorderz' ) . '</span></p></form>';
		}
	}

	public static function handle_sync(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'zorderz' ), 403 );
		}
		check_admin_referer( self::ACTION );
		ZANA_Ledger::cron();
		wp_safe_redirect( add_query_arg( array( 'page' => 'zdz-core-settings' ), admin_url( 'admin.php' ) ) . '#zana-ledger' );
		exit;
	}

	/* ─────────────────────────── REST ─────────────────────────── */

	public static function routes(): void {
		$admin = function () { return current_user_can( 'manage_options' ); };
		$in    = function () { return get_current_user_id() > 0; };
		register_rest_route( ZDZ_REST_NS, '/analytics/ledger/status', array(
			'methods'             => 'GET',
			'callback'            => function () { return rest_ensure_response( ZANA_Ledger::coverage() ); },
			'permission_callback' => $admin,
		) );
		register_rest_route( ZDZ_REST_NS, '/analytics/ledger/sync', array(
			'methods'             => 'POST',
			'callback'            => function () { return rest_ensure_response( ZANA_Ledger::cron() ); },
			'permission_callback' => $admin,
		) );
		register_rest_route( ZDZ_REST_NS, '/analytics/invoice-doc', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_doc' ),
			'permission_callback' => $in,
			'args'                => array(
				'kind'   => array( 'required' => true, 'sanitize_callback' => 'sanitize_key' ),
				'number' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );
		register_rest_route( ZDZ_REST_NS, '/analytics/invoice-link', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_link' ),
			'permission_callback' => $in,
			'args'                => array(
				'number' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );
	}

	public static function rest_doc( WP_REST_Request $r ) {
		$out = ZANA_Invoice_Doc::open( (int) get_current_user_id(), (string) $r->get_param( 'kind' ), (string) $r->get_param( 'number' ) );
		return new WP_REST_Response( $out, ! empty( $out['ok'] ) ? 200 : ( 'rate' === ( $out['code'] ?? '' ) ? 429 : 200 ) );
	}

	public static function rest_link( WP_REST_Request $r ) {
		return rest_ensure_response( ZANA_Invoice_Doc::link( (int) get_current_user_id(), (string) $r->get_param( 'number' ) ) );
	}
}
