<?php
/**
 * ZEST_Paper_Signing - "Signed on paper", the estimate's side.
 *
 * When the customer signs the paper form at the table, the salesperson records it here, with a photo of the signature
 * page (taken with the camera app there and then, or one already taken). The customer-agreements app decides everything
 * (who may, the agreement's state, the date, the age answer, the photos); this class only carries the person's request
 * there and the answers back, through the seam (ZAGR_API SEAM '1': paper_for() and record_paper()). The agreements app
 * reads the signed-in person itself: nothing the browser sends names anyone.
 *
 *   zest_paper_view {id}     the button ("can": this person may record on an unsigned agreement now) and, once
 *                            recorded, the line "Signed on paper Mon, Jan 5 - the customer can cancel until midnight Thu,
 *                            Jan 8." (or OK to order, cancelled, or waiting for an administrator's confirmation).
 *   zest_paper_record {id, date, older, disaster, language_ok, photos[]}
 *                            records it; the agreements app's words come back as they are.
 *   zest_paper_photos {ids[] | today}
 *                            this person's own photos only, by id (what the camera app reported saving) or today's (the
 *                            site's day): gated thumbnails, never a file's own address.
 *
 * Each action: the nonce, never the shared device, the estimate is this person's (ZEST_Access); the agreements app then
 * decides whether this person may record on this agreement (it asks zagr_estimate_salespeople, answered by
 * ZEST_Salesperson from this app's own row). Who the salesperson is never comes from the browser.
 *
 * Privacy: a signature page shows a customer's name and signature. Its photo stays private in ZDZ_User_Media (its owner
 * and the admin tier), is shown here only to its owner, never shared, never logged (an id at most).
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ZEST_Paper_Signing {

	const SEAM       = '1';
	const MAX_PHOTOS = 12;
	const BACK_DAYS  = 30;

	public static function init(): void {
		add_action( 'wp_ajax_zest_paper_view', array( __CLASS__, 'ajax_view' ) );
		add_action( 'wp_ajax_zest_paper_record', array( __CLASS__, 'ajax_record' ) );
		add_action( 'wp_ajax_zest_paper_photos', array( __CLASS__, 'ajax_photos' ) );
	}

	/** The agreements app is in, speaks this seam, and has the paper signing. */
	public static function seam(): bool {
		return class_exists( 'ZAGR_API' ) && defined( 'ZAGR_API::SEAM' ) && self::SEAM === (string) constant( 'ZAGR_API::SEAM' )
			&& is_callable( array( 'ZAGR_API', 'paper_for' ) ) && is_callable( array( 'ZAGR_API', 'record_paper' ) );
	}

	/* ---------------------------------------------------------------- the view */

	/** "Mon, Jan 5" for a Y-m-d, or ''. */
	public static function short_date( string $ymd ): string {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $ymd ) ) {
			return '';
		}
		$t = strtotime( $ymd . ' 12:00:00 UTC' );
		return false === $t ? '' : gmdate( 'D, M j', $t );
	}

	/** The line the estimate shows once a paper signing is recorded. */
	public static function line( array $p ): string {
		$on = self::short_date( (string) ( $p['signed_on'] ?? '' ) );
		if ( '' === $on ) {
			return '';
		}
		/* translators: %s: a date like "Mon, Jan 5" */
		$head = sprintf( __( 'Signed on paper %s', 'zorderz' ), $on );
		if ( 'cancelled' === ( $p['state'] ?? '' ) ) {
			return $head . " \u{2014} " . __( 'the customer cancelled it.', 'zorderz' );
		}
		if ( ! empty( $p['ok_to_order'] ) ) {
			return $head . " \u{2014} " . __( 'the cancellation period has ended: OK to order.', 'zorderz' );
		}
		if ( 'admin_confirm' === ( $p['waiting_for'] ?? '' ) ) {
			return $head . " \u{2014} " . __( 'recorded after the cancellation period had ended: ordering waits until an administrator confirms it.', 'zorderz' );
		}
		$by = self::short_date( (string) ( $p['cancel_by'] ?? '' ) );
		/* translators: %s: a date like "Thu, Jan 8" */
		return $head . ( '' !== $by ? " \u{2014} " . sprintf( __( 'the customer can cancel until midnight %s.', 'zorderz' ), $by ) : '.' );
	}

	/** What the estimate shows, for the signed-in person, or null (no agreements app, no agreement, a failed read). */
	public static function view( int $id ): ?array {
		if ( $id <= 0 || ! self::seam() ) {
			return null;
		}
		try {
			$p = ZAGR_API::paper_for( $id );
		} catch ( \Throwable $e ) {
			return null;
		}
		if ( ! is_array( $p ) || true !== ( $p['ok'] ?? null ) ) {
			return null;
		}
		$paper = 'paper' === ( $p['signed_via'] ?? '' );
		$ymd   = static function ( $d ) { return is_string( $d ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ); };
		$today = $ymd( $p['today'] ?? null ) ? $p['today'] : ( class_exists( 'ZEST_Sale_Form' ) ? ZEST_Sale_Form::today() : gmdate( 'Y-m-d' ) );
		$first = $ymd( $p['first'] ?? null ) && $p['first'] <= $today ? $p['first'] : gmdate( 'Y-m-d', (int) strtotime( $today . ' 12:00:00 UTC' ) - self::BACK_DAYS * 86400 );
		return array(
			'can'         => true === ( $p['can_record'] ?? null ),
			'paper'       => $paper,
			'line'        => $paper ? self::line( $p ) : '',
			'state'       => is_string( $p['state'] ?? null ) ? $p['state'] : '',
			'signed_on'   => $paper ? (string) ( $p['signed_on'] ?? '' ) : '',
			'cancel_by'   => $paper ? (string) ( $p['cancel_by'] ?? '' ) : '',
			'ok_to_order' => $paper && true === ( $p['ok_to_order'] ?? null ),
			'language_ok' => in_array( $p['language_ok'] ?? null, array( 'yes', 'no', '' ), true ) ? $p['language_ok'] : 'yes',
			'buyer_older' => true === ( $p['buyer_older'] ?? null ),
			'asks_age'    => true === ( $p['asks_age'] ?? null ),
			'today'       => $today,
			'first'       => $first,
		);
	}

	/* ---------------------------------------------------------------- record */

	/** Record the paper signing (the agreements app decides; its words come back as they are). { ok, code, message }. */
	public static function record( int $id, array $in ): array {
		if ( $id <= 0 || ! self::seam() ) {
			return array( 'ok' => false, 'code' => 'absent', 'message' => __( 'Signed on paper needs the agreements app on this site.', 'zorderz' ) );
		}
		$ids = array();
		foreach ( (array) ( $in['photos'] ?? array() ) as $p ) {
			$p = is_scalar( $p ) ? trim( (string) $p ) : '';
			if ( preg_match( '/^[1-9][0-9]{0,18}$/', $p ) && ! in_array( (int) $p, $ids, true ) ) {
				$ids[] = (int) $p;
			}
		}
		$lang = in_array( $in['language_ok'] ?? '', array( 'yes', 'no' ), true ) ? (string) $in['language_ok'] : '';
		try {
			$r = ZAGR_API::record_paper( $id, array(
				'date'        => (string) ( $in['date'] ?? '' ),
				'older'       => in_array( $in['older'] ?? '', array( 'yes', 'no' ), true ) ? (string) $in['older'] : '',
				'disaster'    => in_array( $in['disaster'] ?? '', array( 'yes', 'no' ), true ) ? (string) $in['disaster'] : '',
				'language_ok' => $lang,
				'photos'      => array_slice( $ids, 0, self::MAX_PHOTOS + 1 ),
			) );
		} catch ( \Throwable $e ) {
			error_log( 'Zorderz Estimates: a paper signing on estimate id ' . $id . ' could not be recorded (' . get_class( $e ) . ').' );
			$r = null;
		}
		if ( ! is_array( $r ) ) {
			return array( 'ok' => false, 'code' => 'error', 'message' => __( 'The paper signing could not be recorded just now. Try again in a minute.', 'zorderz' ) );
		}
		// a language answer the agreements app kept is this app's answer too (Approve then doesn't ask it again)
		$kept = ( 'yes' === $lang && true === ( $r['ok'] ?? null ) ) || ( 'no' === $lang && 'not_language' === ( $r['code'] ?? '' ) );
		if ( $kept && class_exists( 'ZEST_Terms_Note' ) && null === ZEST_Terms_Note::language( $id ) ) {
			ZEST_Terms_Note::set_language( $id, 'yes' === $lang, (int) get_current_user_id() );
		}
		return array(
			'ok'      => true === ( $r['ok'] ?? null ),
			'code'    => is_string( $r['code'] ?? null ) ? $r['code'] : 'error',
			'message' => is_string( $r['message'] ?? null ) && '' !== $r['message'] ? $r['message'] : __( 'The paper signing could not be recorded just now. Try again in a minute.', 'zorderz' ),
		);
	}

	/* ---------------------------------------------------------------- the photos */

	private static function store(): bool {
		return class_exists( 'ZDZ_User_Media' ) && method_exists( 'ZDZ_User_Media', 'get_by_id' ) && method_exists( 'ZDZ_User_Media', 'get_user_media' );
	}

	/** One photo for the sheet: its id, its gated thumbnails, when it was taken. Never the file's own address. */
	private static function shape( array $m ): array {
		$c = (string) ( $m['created_at'] ?? '' );
		$t = '' !== $c ? strtotime( $c ) : false;
		return array(
			'id'     => (int) $m['id'],
			'thumb'  => (string) ( $m['thumbnail_url'] ?? '' ),
			'thumb2' => (string) ( $m['thumbnail_2x_url'] ?? ( $m['thumbnail_url'] ?? '' ) ),
			'when'   => $t ? gmdate( 'g:i a', $t ) : '',
		);
	}

	private static function own_photo( $m, int $uid ): bool {
		return is_array( $m ) && $uid > 0 && (int) ( $m['user_id'] ?? 0 ) === $uid && 'photo' === (string) ( $m['media_type'] ?? '' );
	}

	/** $uid's own photos: those of $ids, or with $today those taken today (the site's day), newest first, at most 30. */
	public static function photos( int $uid, array $ids = array(), bool $today = false ): array {
		if ( $uid <= 0 || ! self::store() ) {
			return array();
		}
		$out = array();
		if ( $today ) {
			$day = function_exists( 'current_time' ) ? substr( (string) current_time( 'mysql' ), 0, 10 ) : gmdate( 'Y-m-d' );
			foreach ( (array) ZDZ_User_Media::get_user_media( $uid, array( 'media_type' => 'photo', 'limit' => 60 ) ) as $m ) {
				if ( self::own_photo( $m, $uid ) && substr( (string) ( $m['created_at'] ?? '' ), 0, 10 ) === $day ) {
					$out[] = self::shape( $m );
				}
			}
			return array_slice( $out, 0, 30 );
		}
		foreach ( array_slice( array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) ), 0, self::MAX_PHOTOS ) as $pid ) {
			$m = ZDZ_User_Media::get_by_id( $pid );
			if ( self::own_photo( $m, $uid ) ) {
				$out[] = self::shape( $m );
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------- ajax */

	public static function ajax_view(): void {
		list( $uid, $row ) = ZEST_Access::load_owned( 'id', false );
		$sched = class_exists( 'ZEST_Terms_Note' ) ? ZEST_Terms_Note::schedule_for( (int) $row['id'] ) : null;
		$sees  = ! ( class_exists( 'ZDZ_Privacy_View' ) && ZDZ_Privacy_View::is_on( $uid ) );
		wp_send_json_success( array( 'paper' => self::view( (int) $row['id'] ), 'schedule' => $sees ? $sched : null ) );
	}

	public static function ajax_record(): void {
		list( $uid, $row ) = ZEST_Access::load_owned( 'id', true );
		$photos = isset( $_POST['photos'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['photos'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in ZEST_Access::load_owned() above
		$r      = self::record( (int) $row['id'], array(
			'date'        => isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in ZEST_Access::load_owned() above
			'older'       => isset( $_POST['older'] ) ? sanitize_key( wp_unslash( $_POST['older'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in ZEST_Access::load_owned() above
			'disaster'    => isset( $_POST['disaster'] ) ? sanitize_key( wp_unslash( $_POST['disaster'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in ZEST_Access::load_owned() above
			'language_ok' => isset( $_POST['language_ok'] ) ? sanitize_key( wp_unslash( $_POST['language_ok'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in ZEST_Access::load_owned() above
			'photos'      => $photos,
		) );
		if ( empty( $r['ok'] ) ) {
			wp_send_json_error( array( 'code' => $r['code'], 'message' => $r['message'] ) );
		}
		if ( function_exists( 'zest_usage' ) ) {
			zest_usage( 'estimate_signed_on_paper', (int) $row['id'] );
		}
		wp_send_json_success( array( 'message' => $r['message'], 'paper' => self::view( (int) $row['id'] ) ) );
	}

	public static function ajax_photos(): void {
		list( $uid ) = ZEST_Access::load_owned( 'id', true );
		$today = ! empty( $_POST['today'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in ZEST_Access::load_owned() above
		$ids   = isset( $_POST['ids'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['ids'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in ZEST_Access::load_owned() above
		wp_send_json_success( array( 'photos' => self::photos( $uid, $ids, $today ) ) );
	}
}
