<?php
/**
 * ZAGR_Verbs - agreements.status_for, agreements.schedule_for and agreements.record_paper on the platform's verb
 * registry (zdz_register_capabilities, read by ZDZ_Capabilities). [CORE]
 *
 * status_for and schedule_for are reads (side_effect false), identity-stamped: ZDZ_Capabilities::invoke() overwrites
 * user_id, is_kiosk and tier with the signed-in person, and the callbacks check again, so the shared device and Demo
 * Mode get nothing whatever the caller passed. status_for (signed? cancellation period? OK to order?) is for the viewer
 * tier and up; schedule_for (amounts) for staff and up: someone who does the work may see "signed / OK to order" but
 * not the price. Neither ever returns a name, an email, an IP or a typed signature. needs_app is false: an order gate
 * in another app reads status_for for people who never open the agreements app itself.
 *
 * A status_for read changes nothing a caller asks for, but it is not free of effects: like a page view, it closes a
 * cancellation period whose day has passed (ZAGR_Store::refresh_window), and closing it sends the staff's OK-to-order
 * alert (ZAGR_Alerts, once per transition).
 *
 * agreements.record_paper records a paper signing (ZAGR_Paper::record): a write inside the platform (side_effect
 * true, outside false), staff tier and up, never the shared device; the callback checks again (the salesperson on the
 * estimate or an administrator, the photos the person's own). ZDZ_Capabilities::invoke() runs only reads (a write
 * waits for a person's Confirm), so the estimate's button calls the same path through ZAGR_API::record_paper.
 *
 * @package Zorderz\Agreements
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZAGR_Verbs {

	const STATUS   = 'agreements.status_for';
	const SCHEDULE = 'agreements.schedule_for';
	const PAPER    = 'agreements.record_paper';

	public static function boot(): void {
		add_filter( 'zdz_register_capabilities', array( __CLASS__, 'register' ) );
	}

	public static function register( $caps ) {
		$caps = is_array( $caps ) ? $caps : array();
		$caps[ self::STATUS ] = array(
			'callable'   => array( __CLASS__, 'status' ),
			'descriptor' => array(
				'verb' => self::STATUS, 'source' => 'agreements', 'app' => 'agreements', 'min_tier' => 'viewer',
				'kiosk' => false, 'kiosk_bounded' => false, 'side_effect' => false, 'confirm' => false, 'needs_app' => false,
				'summary' => 'Where a customer\'s agreement for one estimate stands: the state, when it was signed, the cancel-by date, whether a change order is waiting, and whether it is OK to order. Never a name, email, IP address, or signature.',
			),
		);
		$caps[ self::SCHEDULE ] = array(
			'callable'   => array( __CLASS__, 'schedule' ),
			'descriptor' => array(
				'verb' => self::SCHEDULE, 'source' => 'agreements', 'app' => 'agreements', 'min_tier' => 'staff',
				'kiosk' => false, 'kiosk_bounded' => false, 'side_effect' => false, 'confirm' => false, 'needs_app' => false,
				'summary' => 'The payment schedule for one estimate as the customer signed it (or as offered): the downpayment, the final payment, and the total, in cents.',
			),
		);
		$caps[ self::PAPER ] = array(
			'callable'   => array( __CLASS__, 'record_paper' ),
			'descriptor' => array(
				'verb' => self::PAPER, 'source' => 'agreements', 'app' => 'agreements', 'min_tier' => 'staff',
				'kiosk' => false, 'kiosk_bounded' => false, 'side_effect' => true, 'outside' => false, 'confirm' => true, 'needs_app' => false,
				'summary' => 'Record that the customer signed an estimate\'s agreement on paper: the day, the answers the agreement\'s legal rules ask (whether any buyer is 65 or older, where asked), whether the sale was negotiated in the agreement\'s language (when nobody has asked yet), and the photos of the signature page (the person\'s own, private, taken that day or since). The cancellation period runs from that day. The estimate\'s salesperson or an administrator only.',
			),
		);
		return $caps;
	}

	/** agreements.record_paper: as the person the registry stamped, with the same checks as every way in. */
	public static function record_paper( array $args ): array {
		$why = self::refuse( $args, 'staff' );
		if ( '' !== $why ) {
			return array( 'success' => true, 'denied' => true, 'reason' => $why, 'verb' => self::PAPER );
		}
		$id = self::estimate( $args );
		if ( $id <= 0 ) {
			return array( 'success' => false, 'error' => 'An estimate_id is required.', 'verb' => self::PAPER );
		}
		$photos = $args['photos'] ?? array();
		$photos = is_array( $photos ) ? $photos : ( is_string( $photos ) ? explode( ',', $photos ) : array() );
		$r = ZAGR_Paper::record( $id, (int) $args['user_id'], array( 'date' => $args['date'] ?? '', 'older' => $args['older'] ?? '', 'disaster' => $args['disaster'] ?? '', 'language_ok' => $args['language_ok'] ?? ( $args['english'] ?? '' ), 'photos' => $photos ), 'app' );
		return array( 'success' => true, 'verb' => self::PAPER ) + $r;
	}

	/** '' when this caller may read, else why not (kiosk | signed_out | tier). */
	private static function refuse( array $args, string $need ): string {
		$uid = (int) ( $args['user_id'] ?? 0 );
		if ( ! empty( $args['is_kiosk'] ) || ZAGR_People::is_kiosk( $uid ) ) {
			return 'kiosk';
		}
		if ( $uid <= 0 ) {
			return 'signed_out';
		}
		if ( 'staff' === $need && ! ZAGR_People::is_staff( $uid ) ) {
			return 'tier';
		}
		// checked here too, so a caller that runs the callback without ZDZ_Capabilities::invoke() is held
		if ( 'viewer' === $need && ! ZAGR_People::is_viewer( $uid ) ) {
			return 'tier';
		}
		return '';
	}

	private static function estimate( array $args ): int {
		$e = $args['estimate_id'] ?? 0;
		return ( is_int( $e ) || ( is_string( $e ) && ctype_digit( $e ) ) ) ? (int) $e : 0;
	}

	public static function status( array $args ): array {
		$why = self::refuse( $args, 'viewer' );
		if ( '' !== $why ) {
			return array( 'success' => true, 'denied' => true, 'reason' => $why, 'verb' => self::STATUS );
		}
		$id = self::estimate( $args );
		if ( $id <= 0 ) {
			return array( 'success' => false, 'error' => 'An estimate_id is required.', 'verb' => self::STATUS );
		}
		$s = ZAGR_API::status_for( $id );
		unset( $s['url'] ); // the link is a key to the customer's page: it goes onto the estimate, not into chat
		return array( 'success' => true, 'verb' => self::STATUS ) + $s;
	}

	public static function schedule( array $args ): array {
		$why = self::refuse( $args, 'staff' );
		if ( '' !== $why ) {
			return array( 'success' => true, 'denied' => true, 'reason' => $why, 'verb' => self::SCHEDULE );
		}
		$id = self::estimate( $args );
		if ( $id <= 0 ) {
			return array( 'success' => false, 'error' => 'An estimate_id is required.', 'verb' => self::SCHEDULE );
		}
		return array( 'success' => true, 'verb' => self::SCHEDULE ) + ZAGR_API::schedule_for( $id );
	}
}
