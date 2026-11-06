<?php
/**
 * ZEST_Access_Requests - "Ask an admin" for an estimate that is someone else's.
 *
 * An estimate the app has as someone else's is refused (ZEST_Access::may_open). When that is wrong (the initials were
 * mistyped, or a teammate needs to add measurements to a colleague's job) the person taps "Ask an admin": every
 * administrator gets a direct message (the messaging app, when it is there; else an alert in the bell) with a link; one
 * tap approves or declines; the person is told the answer the same way. An approval lets THAT person work on THAT
 * estimate until an administrator removes it. Deny by default: nothing is granted until an administrator says so.
 *
 *   person (not theirs) -- zest_request_access --> create()  -- message --> each admin-tier person
 *                                                               link: /?zest_access=<id>
 *   admin opens the link --> maybe_render() (GET: the request + Approve / Decline; POST: nonce + admin tier) --> decide()
 *        approve  -> user meta zest_estimate_grants   (has_grant(), asked by ZEST_Salesperson::owned_state)
 *        revoke   -> user meta zest_estimate_revoked  (is_revoked(): no rule makes it theirs, not even their initials
 *                                                      on it or their own saves, until an administrator approves again)
 *
 * Server-authoritative: the approval is checked on every request, never carried by the browser. The shared device can
 * neither ask nor answer, and a grant never counts for it. Logs carry user ids and estimate ids only, never names.
 * Storage: option zest_access_requests (not autoloaded, the newest 300, approved ones always kept: their page is where
 * access is removed) and the two user meta keys. Grants are by this app's estimate id.
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZEST_Access_Requests {

	const OPT          = 'zest_access_requests';
	const META         = 'zest_estimate_grants';
	const REVOKED      = 'zest_estimate_revoked';
	const QUERY        = 'zest_access';
	const ALERT        = 'estimate_access_request';
	const MAX_KEPT     = 300;
	const MAX_PER_DAY  = 10;
	const PENDING_DAYS = 7;

	public static function init(): void {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 1 );
		add_action( 'wp_ajax_zest_request_access', array( __CLASS__, 'ajax_request' ) );
		add_action( 'zdz_register_alert_types', array( __CLASS__, 'register_alert_type' ) );
	}

	public static function register_alert_type( $router ): void {
		if ( is_object( $router ) && method_exists( $router, 'register_type' ) ) {
			$router->register_type( self::ALERT, array(
				'label'            => __( 'Estimate access requests', 'zorderz' ),
				'description'      => __( 'Someone asks to work on an estimate that is not theirs, and the answer.', 'zorderz' ),
				'default_channels' => array( 'in_app' ),
				'source_plugin'    => 'estimate-creator',
				'category'         => 'sales',
			) );
		}
	}

	/* ------------------------------------------------------------------ who */

	public static function is_admin_tier( int $uid ): bool {
		return class_exists( 'ZEST_Salesperson' ) && ZEST_Salesperson::is_admin_tier( $uid );
	}

	/** The shared device. No user counts as the most restrictive. */
	public static function is_kiosk( int $uid ): bool {
		if ( $uid <= 0 ) {
			return true;
		}
		return class_exists( 'ZEST_Salesperson' ) && ZEST_Salesperson::is_kiosk( $uid );
	}

	/** Everyone who is asked: the admin tier, never the shared device, never the person asking. */
	public static function recipients( int $asker, array $req = array() ): array {
		$ids = function_exists( 'get_users' )
			? array_map( static function ( $u ) {
				return is_object( $u ) ? (int) ( $u->ID ?? 0 ) : (int) $u;
			}, (array) get_users( array( 'role__in' => array( 'administrator', 'zdz_owner', 'zdz_admin' ), 'fields' => 'ID' ) ) )
			: array();
		/**
		 * Who is asked to approve an estimate access request. Whatever is returned is still limited to the admin tier.
		 *
		 * @param int[] $ids Admin-tier user ids.
		 * @param array $req The request (uid, row, num, initials, note).
		 */
		$ids = function_exists( 'apply_filters' ) ? (array) apply_filters( 'zest_access_request_recipients', $ids, $req ) : $ids;
		return array_values( array_filter( array_unique( array_map( 'intval', $ids ) ), static function ( $id ) use ( $asker ) {
			return $id > 0 && $id !== $asker && self::is_admin_tier( $id ) && ! self::is_kiosk( $id );
		} ) );
	}

	/* ------------------------------------------------------------------ grants */

	private static function key( int $row ): string {
		return 'row:' . $row;
	}

	/** Has an administrator approved $uid for this estimate? Never for the shared device. */
	public static function has_grant( int $uid, int $row ): bool {
		if ( $uid <= 0 || $row <= 0 || self::is_kiosk( $uid ) ) {
			return false;
		}
		$g = get_user_meta( $uid, self::META, true );
		return is_array( $g ) && isset( $g[ self::key( $row ) ] );
	}

	/** Did an administrator remove $uid's access to this estimate? Then no rule makes it theirs. */
	public static function is_revoked( int $uid, int $row ): bool {
		if ( $uid <= 0 || $row <= 0 ) {
			return false;
		}
		$r = get_user_meta( $uid, self::REVOKED, true );
		return is_array( $r ) && isset( $r[ self::key( $row ) ] );
	}

	/** The estimate ids granted to $uid (for a list query). */
	public static function granted_rows( int $uid ): array {
		if ( $uid <= 0 || self::is_kiosk( $uid ) ) {
			return array();
		}
		$g = get_user_meta( $uid, self::META, true );
		return self::ids_of( $g );
	}

	public static function revoked_rows( int $uid ): array {
		return $uid > 0 ? self::ids_of( get_user_meta( $uid, self::REVOKED, true ) ) : array();
	}

	private static function ids_of( $map ): array {
		$out = array();
		foreach ( is_array( $map ) ? array_keys( $map ) : array() as $k ) {
			if ( preg_match( '/^row:([1-9][0-9]{0,18})$/', (string) $k, $m ) ) {
				$out[] = (int) $m[1];
			}
		}
		return array_slice( $out, 0, 500 );
	}

	/* ------------------------------------------------------------------ requests */

	public static function all(): array {
		$r = get_option( self::OPT, array() );
		return is_array( $r ) ? $r : array();
	}

	private static function save( array $all ): void {
		if ( count( $all ) > self::MAX_KEPT ) {
			uasort( $all, static function ( $a, $b ) { return (int) ( $b['at'] ?? 0 ) <=> (int) ( $a['at'] ?? 0 ); } );
			$kept = 0;
			foreach ( $all as $rid => $r ) {
				if ( 'approved' === ( $r['status'] ?? '' ) ) {
					continue;
				}
				if ( ++$kept > self::MAX_KEPT ) {
					unset( $all[ $rid ] );
				}
			}
		}
		update_option( self::OPT, $all, false );
	}

	public static function get( string $id ): ?array {
		$all = self::all();
		return isset( $all[ $id ] ) && is_array( $all[ $id ] ) ? $all[ $id ] : null;
	}

	public static function url( string $id ): string {
		return add_query_arg( self::QUERY, rawurlencode( $id ), home_url( '/' ) );
	}

	/**
	 * Ask. $row: this app's estimate id (the caller found it); $num: its number as shown; $initials: the codes on it now
	 * (shown to the administrator). Never throws.
	 *
	 * @return array { ok:bool, pending?:bool, message:string, id?:string, asked?:int }
	 */
	public static function create( int $uid, int $row, string $num, array $initials, string $note ): array {
		if ( $uid <= 0 || self::is_kiosk( $uid ) ) {
			return array( 'ok' => false, 'message' => __( 'Asking for access is not available on the shared device. Sign in as yourself.', 'zorderz' ) );
		}
		if ( $row <= 0 ) {
			return array( 'ok' => false, 'message' => __( 'That estimate could not be identified. Ask an administrator directly.', 'zorderz' ) );
		}
		$num  = substr( preg_replace( '/[^A-Za-z0-9\-]/', '', $num ), 0, 40 );
		$note = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $note ) ) );
		if ( class_exists( 'ZDZ_Sealed' ) ) {
			$note = ZDZ_Sealed::cut( $note );   // a code typed in a note is never sent on
		}
		if ( ( function_exists( 'mb_strlen' ) ? mb_strlen( $note ) : strlen( $note ) ) > 300 ) {
			$note = ( function_exists( 'mb_substr' ) ? mb_substr( $note, 0, 297 ) : substr( $note, 0, 297 ) ) . '...';
		}
		$now   = time();
		$today = 0;
		foreach ( self::all() as $rid => $r ) {
			if ( ! is_array( $r ) || (int) ( $r['uid'] ?? 0 ) !== $uid ) {
				continue;
			}
			if ( $now - (int) ( $r['at'] ?? 0 ) < DAY_IN_SECONDS ) {
				$today++;
			}
			if ( (int) ( $r['row'] ?? 0 ) === $row && 'pending' === ( $r['status'] ?? '' ) && $now - (int) ( $r['at'] ?? 0 ) < self::PENDING_DAYS * DAY_IN_SECONDS ) {
				return array( 'ok' => true, 'pending' => true, 'id' => (string) $rid,
					/* translators: %s: estimate number */
					'message' => sprintf( __( 'You already asked about estimate #%s. An administrator has it; the reply comes to you there.', 'zorderz' ), $num ) );
			}
		}
		if ( $today >= self::MAX_PER_DAY ) {
			/* translators: %d: requests a day */
			return array( 'ok' => false, 'message' => sprintf( __( 'You have asked %d times today. Ask an administrator directly.', 'zorderz' ), self::MAX_PER_DAY ) );
		}

		$id   = bin2hex( random_bytes( 8 ) );
		$inis = array_values( array_slice( array_map( 'strval', $initials ), 0, 6 ) );
		$req  = array( 'uid' => $uid, 'row' => $row, 'num' => $num, 'initials' => $inis, 'note' => $note, 'at' => $now, 'status' => 'pending', 'asked' => array() );

		$asker = get_userdata( $uid );
		$name  = ( $asker && ! empty( $asker->display_name ) ) ? (string) $asker->display_name : __( 'Someone', 'zorderz' );
		$mine  = class_exists( 'ZEST_Salesperson' ) ? ZEST_Salesperson::initials_of( $uid ) : '';
		$body  = sprintf(
			/* translators: 1: name, 2: their code, 3: number, 4: the codes on it, 5: their note, 6: link */
			__( '%1$s%2$s is asking to work on estimate #%3$s in Estimates (add measurements, update it). The app has it as someone else\'s; %4$s.%5$s Approve or decline: %6$s', 'zorderz' ),
			$name,
			'' !== $mine ? ' (' . $mine . ')' : '',
			$num,
			$inis ? sprintf( __( 'it names %s', 'zorderz' ), implode( ', ', array_map( static function ( $i ) { return '(' . $i . ')'; }, $inis ) ) ) : __( 'it names nobody', 'zorderz' ),
			'' !== $note ? ' ' . sprintf( __( 'Their note: "%s"', 'zorderz' ), $note ) : '',
			self::url( $id )
		);
		foreach ( self::recipients( $uid, $req ) as $to ) {
			if ( self::tell( $uid, $to, __( 'Estimate access request', 'zorderz' ), $body, self::url( $id ) ) ) {
				$req['asked'][] = $to;
			}
		}
		if ( ! $req['asked'] ) {
			/* translators: %s: estimate number */
			return array( 'ok' => false, 'message' => sprintf( __( 'No administrator could be reached. Ask one directly about estimate #%s.', 'zorderz' ), $num ) );
		}
		$all        = self::all();   // re-read: the messages took time, and another request may have been saved
		$all[ $id ] = $req;
		self::save( $all );
		error_log( 'Zorderz Estimates access: request ' . $id . ' by user ' . $uid . ' for estimate id ' . $row . ' sent to ' . count( $req['asked'] ) . ' administrator(s)' );
		$n = count( $req['asked'] );
		return array( 'ok' => true, 'id' => $id, 'asked' => $n,
			'message' => 1 === $n ? __( 'Asked an administrator. The reply comes to you; then tap Check again.', 'zorderz' )
				/* translators: %d: administrators */
				: sprintf( __( 'Asked %d administrators. The reply comes to you; then tap Check again.', 'zorderz' ), $n ) );
	}

	/**
	 * An administrator answers. $decision: approve | decline | revoke. Never throws.
	 *
	 * @return array { ok:bool, message:string }
	 */
	public static function decide( string $id, int $admin, string $decision ): array {
		if ( ! self::is_admin_tier( $admin ) || self::is_kiosk( $admin ) ) {
			return array( 'ok' => false, 'message' => __( 'Only an administrator can answer this request.', 'zorderz' ) );
		}
		$req = self::get( $id );
		if ( ! $req ) {
			return array( 'ok' => false, 'message' => __( 'That request was not found.', 'zorderz' ) );
		}
		$status = (string) ( $req['status'] ?? '' );
		$uid    = (int) ( $req['uid'] ?? 0 );
		$num    = (string) ( $req['num'] ?? '' );
		$row    = (int) ( $req['row'] ?? 0 );
		if ( 'approve' === $decision || 'decline' === $decision ) {
			if ( 'pending' !== $status ) {
				return array( 'ok' => false, 'message' => __( 'This request was already answered.', 'zorderz' ) );
			}
		} elseif ( 'revoke' === $decision ) {
			if ( 'approved' !== $status ) {
				return array( 'ok' => false, 'message' => __( 'There is no access to remove.', 'zorderz' ) );
			}
		} else {
			return array( 'ok' => false, 'message' => __( 'Unknown answer.', 'zorderz' ) );
		}
		// two administrators at once: re-read, and act only if nobody answered in between
		$fresh = self::get( $id );
		if ( ! $fresh || (string) ( $fresh['status'] ?? '' ) !== $status ) {
			return array( 'ok' => false, 'message' => __( 'Someone answered this request just now. Reload the page.', 'zorderz' ) );
		}
		$grants  = get_user_meta( $uid, self::META, true );
		$grants  = is_array( $grants ) ? $grants : array();
		$revoked = get_user_meta( $uid, self::REVOKED, true );
		$revoked = is_array( $revoked ) ? $revoked : array();
		$key     = self::key( $row );
		if ( 'approve' === $decision ) {
			$grants[ $key ] = array( 'row' => $row, 'num' => $num, 'by' => $admin, 'at' => time(), 'req' => $id );
			update_user_meta( $uid, self::META, $grants );
			if ( isset( $revoked[ $key ] ) ) {   // approving again lifts an earlier removal
				unset( $revoked[ $key ] );
				update_user_meta( $uid, self::REVOKED, $revoked );
			}
			$req['status'] = 'approved';
			/* translators: %s: estimate number */
			$reply = sprintf( __( 'You are approved for estimate #%s in Estimates. You can add measurements and update it now (in Estimates, tap Check again).', 'zorderz' ), $num );
		} elseif ( 'decline' === $decision ) {
			$req['status'] = 'declined';
			/* translators: %s: estimate number */
			$reply = sprintf( __( 'Your request to work on estimate #%s in Estimates was declined.', 'zorderz' ), $num );
		} else {
			unset( $grants[ $key ] );
			update_user_meta( $uid, self::META, $grants );
			$revoked[ $key ] = array( 'row' => $row, 'num' => $num, 'by' => $admin, 'at' => time(), 'req' => $id );
			update_user_meta( $uid, self::REVOKED, $revoked );
			$req['status'] = 'revoked';
			/* translators: %s: estimate number */
			$reply = sprintf( __( 'Your access to estimate #%s in Estimates has been removed.', 'zorderz' ), $num );
		}
		$req['by']         = $admin;
		$req['decided_at'] = time();
		$all               = self::all();
		$all[ $id ]        = $req;
		self::save( $all );
		self::tell( $admin, $uid, __( 'Estimate access', 'zorderz' ), $reply, '' );
		error_log( 'Zorderz Estimates access: request ' . $id . ' (user ' . $uid . ', estimate id ' . $row . ') ' . $req['status'] . ' by user ' . $admin );
		$words = array(
			'approved' => __( 'Approved', 'zorderz' ),
			'declined' => __( 'Declined', 'zorderz' ),
			'revoked'  => __( 'Access removed - on this estimate nothing makes it theirs now (not their initials on it, not their own saves) unless you approve them again', 'zorderz' ),
		);
		return array( 'ok' => true, 'message' => $words[ $req['status'] ] . '. ' . __( 'They have been told.', 'zorderz' ) );
	}

	/**
	 * Tell someone: a direct message in the messaging app when it is there, else an alert in the bell. Best effort, never
	 * throws, never to oneself, never the shared device.
	 */
	private static function tell( int $from, int $to, string $title, string $body, string $url ): bool {
		try {
			if ( $from <= 0 || $to <= 0 || $from === $to || self::is_kiosk( $from ) || self::is_kiosk( $to ) ) {
				return false;
			}
			if ( class_exists( 'ZIM_DMs' ) && class_exists( 'ZIM_Messages' ) && method_exists( 'ZIM_DMs', 'get_or_create_conversation' ) ) {
				$conv = (int) ZIM_DMs::get_or_create_conversation( $from, $to );
				if ( $conv > 0 ) {
					$res = ZIM_Messages::post( $conv, $from, $body );
					if ( is_array( $res ) && ! empty( $res['message_id'] ) ) {
						return true;
					}
				}
			}
			if ( class_exists( 'ZDZ_Alert_Router' ) ) {
				$used = ZDZ_Alert_Router::send( self::ALERT, $to, $title, $body, array( 'sender_id' => $from, 'plugin' => 'estimate-creator', 'url' => $url ) );
				return ! empty( $used );
			}
		} catch ( \Throwable $e ) {
			return false;
		}
		return false;
	}

	/* ------------------------------------------------------------------ the ask, from the app */

	/** zest_request_access { id, note }: the person asks for an estimate the app has as someone else's. */
	public static function ajax_request(): void {
		$uid = ZEST_Access::guard();
		ZEST_Access::no_kiosk( $uid );
		$id  = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in ZEST_Access::guard() above
		$row = ZEST_Access::row( $id );
		if ( ! $row ) {
			wp_send_json_error( array( 'message' => __( 'That estimate was not found.', 'zorderz' ) ), 404 );
		}
		if ( ZEST_Access::may_open( $row, $uid ) ) {
			wp_send_json_success( array( 'ok' => true, 'message' => __( 'This estimate is already yours to work on.', 'zorderz' ) ) );
		}
		$codes = class_exists( 'ZEST_Salesperson' ) ? ZEST_Salesperson::codes_for_row( $row ) : array();
		$note  = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in ZEST_Access::guard() above
		$res   = self::create( $uid, (int) $row['id'], (string) $row['billing_doc_num'], $codes, $note );
		if ( empty( $res['ok'] ) ) {
			wp_send_json_error( array( 'message' => $res['message'] ) );
		}
		wp_send_json_success( $res );
	}

	/* ------------------------------------------------------------------ page */

	/** /?zest_access=<id>: the request, and the administrator's answer. */
	public static function maybe_render(): void {
		if ( ! isset( $_GET[ self::QUERY ] ) ) {
			return;
		}
		$id = preg_replace( '/[^a-f0-9]/', '', sanitize_key( wp_unslash( $_GET[ self::QUERY ] ) ) );
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Referrer-Policy: same-origin' );
			header( 'X-Frame-Options: SAMEORIGIN' );
		}
		if ( ! is_user_logged_in() ) {
			auth_redirect();
			exit;
		}
		$me      = get_current_user_id();
		$message = '';
		$kind    = 'info';
		if ( 'POST' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) ) {
			$decision = sanitize_key( (string) ( $_POST['decision'] ?? '' ) );
			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'zest_access_' . $id ) ) {
				$message = __( 'This page was open too long. Reload it and try again.', 'zorderz' );
				$kind    = 'error';
			} else {
				$r       = self::decide( $id, $me, $decision );
				$message = $r['message'];
				$kind    = $r['ok'] ? 'ok' : 'error';
			}
		}
		echo self::page_html( $id, $me, $message, $kind ); // phpcs:ignore WordPress.Security.EscapeOutput -- every value is escaped in page_html()
		exit;
	}

	/** The page. Public for the tests. */
	public static function page_html( string $id, int $me, string $message, string $kind ): string {
		$req   = '' !== $id ? self::get( $id ) : null;
		$admin = self::is_admin_tier( $me ) && ! self::is_kiosk( $me );
		$e     = static function ( $s ) { return esc_html( (string) $s ); };
		$body  = '';
		if ( ! $req ) {
			$body .= '<p>' . $e( __( 'That request was not found. It may be older than the ones kept.', 'zorderz' ) ) . '</p>';
		} elseif ( ! $admin && (int) ( $req['uid'] ?? 0 ) !== $me ) {
			$body .= '<p>' . $e( __( 'Only an administrator can answer this request.', 'zorderz' ) ) . '</p>';
		} else {
			$asker = get_userdata( (int) $req['uid'] );
			$name  = ( $asker && ! empty( $asker->display_name ) ) ? $asker->display_name : sprintf( __( 'User %d', 'zorderz' ), (int) $req['uid'] );
			$mine  = class_exists( 'ZEST_Salesperson' ) ? ZEST_Salesperson::initials_of( (int) $req['uid'] ) : '';
			$by    = ! empty( $req['by'] ) ? get_userdata( (int) $req['by'] ) : null;
			$when  = static function ( $t ) { return function_exists( 'wp_date' ) ? wp_date( 'M j, g:i A', (int) $t ) : gmdate( 'M j, H:i', (int) $t ); };
			$words = array(
				'pending'  => __( 'Waiting for an answer', 'zorderz' ),
				'approved' => __( 'Approved', 'zorderz' ),
				'declined' => __( 'Declined', 'zorderz' ),
				'revoked'  => __( 'Access removed', 'zorderz' ),
			);
			$st    = (string) ( $req['status'] ?? 'pending' );
			$body .= '<dl>';
			$body .= '<dt>' . $e( __( 'Who', 'zorderz' ) ) . '</dt><dd>' . $e( $name ) . ( '' !== $mine ? ' (' . $e( $mine ) . ')' : '' ) . '</dd>';
			$body .= '<dt>' . $e( __( 'Estimate', 'zorderz' ) ) . '</dt><dd>#' . $e( $req['num'] ?? '' ) . '</dd>';
			$body .= '<dt>' . $e( __( 'Initials on it when asked', 'zorderz' ) ) . '</dt><dd>' . ( ! empty( $req['initials'] ) ? $e( implode( ', ', (array) $req['initials'] ) ) : $e( __( 'none', 'zorderz' ) ) ) . '</dd>';
			if ( ! empty( $req['note'] ) ) {
				$body .= '<dt>' . $e( __( 'Their note', 'zorderz' ) ) . '</dt><dd>' . $e( $req['note'] ) . '</dd>';
			}
			$body .= '<dt>' . $e( __( 'Asked', 'zorderz' ) ) . '</dt><dd>' . $e( $when( $req['at'] ?? 0 ) ) . '</dd>';
			$body .= '<dt>' . $e( __( 'Status', 'zorderz' ) ) . '</dt><dd><strong>' . $e( $words[ $st ] ?? $st ) . '</strong>'
				. ( $by && 'pending' !== $st ? ' ' . $e( __( 'by', 'zorderz' ) ) . ' ' . $e( $by->display_name ?? '' ) . ( ! empty( $req['decided_at'] ) ? ', ' . $e( $when( $req['decided_at'] ) ) : '' ) : '' ) . '</dd>';
			$body .= '</dl>';
			if ( $admin ) {
				$form = static function ( $decision, $label, $cls ) use ( $id, $e ) {
					return '<form method="post"><input type="hidden" name="decision" value="' . $e( $decision ) . '">'
						. wp_nonce_field( 'zest_access_' . $id, '_wpnonce', false, false )
						. '<button type="submit" class="' . $e( $cls ) . '">' . $e( $label ) . '</button></form>';
				};
				if ( 'pending' === $st ) {
					$body .= '<p class="why">' . $e( sprintf( __( 'Approving lets %1$s open, add measurements to and update estimate #%2$s in Estimates until you remove it. It does not change the initials on the estimate.', 'zorderz' ), $name, $req['num'] ?? '' ) ) . '</p>';
					$body .= '<div class="row">' . $form( 'approve', __( 'Approve', 'zorderz' ), 'go' ) . $form( 'decline', __( 'Decline', 'zorderz' ), 'no' ) . '</div>';
				} elseif ( 'approved' === $st ) {
					$body .= '<p class="why">' . $e( sprintf( __( 'Only if the estimate is not theirs: removing access blocks %1$s from estimate #%2$s in Estimates even if their initials are on it by then, until you approve them again. If the estimate is theirs now, leave this as it is.', 'zorderz' ), $name, $req['num'] ?? '' ) ) . '</p>';
					$body .= '<div class="row">' . $form( 'revoke', __( 'Remove access', 'zorderz' ), 'no' ) . '</div>';
				}
			}
		}
		$note = '' !== $message ? '<p class="msg ' . $e( $kind ) . '" role="status">' . $e( $message ) . '</p>' : '';
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=utf-8' );
		}
		$css = function_exists( 'get_stylesheet_uri' ) ? '<link rel="stylesheet" href="' . esc_url( get_stylesheet_uri() ) . '">' : '';
		return '<!doctype html><html lang="en" data-theme="system"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<meta name="robots" content="noindex"><title>' . $e( __( 'Estimate access', 'zorderz' ) ) . '</title>'
			. $css . '<style>'
			. 'body.zest-acc{margin:0;background:var(--sys-bg,#F1F5F9);color:var(--sys-text,#0F172A);font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}'
			. '.zest-acc main{max-width:520px;margin:0 auto;padding:24px 16px}'
			. '.zest-acc .card{background:var(--sys-surface,#FFFFFF);border:1px solid var(--sys-border,#E2E8F0);border-radius:14px;padding:20px}'
			. '.zest-acc h1{font-size:20px;margin:0 0 12px}.zest-acc dl{margin:0}'
			. '.zest-acc dt{color:var(--sys-text-sec,#475569);font-size:13px;margin-top:10px}.zest-acc dt:first-child{margin-top:0}.zest-acc dd{margin:2px 0 0;overflow-wrap:anywhere}'
			. '.zest-acc .why{color:var(--sys-text-sec,#475569);font-size:14px}.zest-acc .row{display:flex;gap:10px;margin-top:16px}.zest-acc .row form{flex:1}'
			. '.zest-acc button{width:100%;min-height:48px;border-radius:10px;font-family:inherit;font-size:16px;font-weight:600;line-height:1;cursor:pointer;background:transparent}'
			. '.zest-acc button.go{background:var(--sys-tint-success-fg,#166534);color:var(--sys-surface,#FFFFFF);border:0}'
			. '.zest-acc button.no{border:2px solid var(--sys-tint-danger-fg,#991B1B);color:var(--sys-tint-danger-fg,#991B1B)}'
			. '.zest-acc .msg{padding:10px 12px;border-radius:10px}'
			. '.zest-acc .msg.ok{background:var(--sys-tint-success-bg,#DCFCE7);color:var(--sys-tint-success-fg,#166534)}'
			. '.zest-acc .msg.error{background:var(--sys-tint-danger-bg,#FEE2E2);color:var(--sys-tint-danger-fg,#991B1B)}'
			. '.zest-acc a{color:inherit}'
			. '</style></head><body class="zest-acc"><main><div class="card"><h1>' . $e( __( 'Estimate access request', 'zorderz' ) ) . '</h1>' . $note . $body
			. '<p><a href="' . esc_url( home_url( '/' ) ) . '">' . $e( __( 'Back to the app', 'zorderz' ) ) . '</a></p></div></main></body></html>';
	}
}
