<?php
/**
 * ZJOB_Order_Gate — "Order materials" waits for the go sign: the customer's agreement signed and its cancellation
 * period over (the agreements app's ok_to_order).
 *
 * An order can still be started, filled in and confirmed by both sides while the customer may cancel; only PLACING it
 * waits: "Mark ordered", and the supplier's form, which is how an order leaves the app.
 *
 * HOW IT READS THE AGREEMENTS APP (never its classes): the platform's verb registry, as the signed-in person:
 *     ZDZ_Capabilities::invoke( 'agreements.status_for', array( 'estimate_id' => <the estimate app's id> ) )
 * going by success, denied and ok_to_order === true. Read-only.
 *
 * WHICH ESTIMATES: the estimate ids the Project knows from local reads (its estimate reference and its jobs'
 * estimate_id), the newest 12.
 *
 * THE RULE, per estimate:
 *   no agreements app (the verb unknown), a denied read, or no agreement for the estimate  -> nothing to go by
 *   a failed read (an error answer, a throw)       -> allowed, and logged once an hour a Project
 *   an unsigned agreement a DECISION releases (unsignable_why language_no or mode)          -> nothing to go by
 *   an agreement with ok_to_order === true         -> OK to order, whatever its state is called
 *   any other agreement                            -> the server refuses the order, in plain words by state
 * A Project with several estimates waits while any of its agreements does (a cancellation said first) — except that
 * once the customer has signed one estimate's agreement, the unsigned option estimates beside it (made before the
 * newest signature) do not hold; one made after is a second sale and holds. Shop stock has no agreement. A warranty or
 * service order skips the gate only when it names its original job, another Project.
 *
 * THE ALERT'S LINK: the agreements app asks `zagr_alert_link` for a page to send with its staff alerts; this answers
 * the Project's link for a person who may open it, and nothing otherwise.
 *
 * @package Zorderz\Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZJOB_Order_Gate {

	const VERB      = 'agreements.status_for';
	const MAX       = 12;
	const FAIL_NOTE = 'zjob_gate_fail_';

	/** The reasons the agreements app gives (unsignable_why) that are decisions, and release the gate. */
	const RELEASE = array( 'language_no', 'mode' );

	/** @var array per-request memo: reads and a Project's estimate ids */
	private static $memo = array();

	public static function init(): void {
		add_filter( 'zagr_alert_link', array( __CLASS__, 'alert_link' ), 10, 4 );
	}

	/** Tests, and after anything that may change an agreement in the same request. */
	public static function forget(): void {
		self::$memo = array();
	}

	/* ------------------------------------------------------------------ the words */

	public static function ok_chip(): string {
		return __( 'OK to order', 'zorderz' );
	}

	/** "Wednesday, November 4" for a Y-m-d, or '' when it is not a date. */
	public static function day( string $ymd ): string {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return '';
		}
		return gmdate( 'l, F j', gmmktime( 12, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1] ) );
	}

	/** The long refusals, by key. */
	public static function texts(): array {
		return array(
			'unsigned' => __( 'Wait to order: the customer hasn\'t signed the agreement yet.', 'zorderz' ),
			/* translators: %s: a day such as "Wednesday, November 4" */
			'window'   => __( 'Wait to order: the customer can cancel until midnight %s.', 'zorderz' ),
			'copy'     => __( 'Wait to order: the cancellation period starts when the customer receives their signed copy.', 'zorderz' ),
			'change'   => __( 'Wait to order: a change order is waiting for the customer\'s signature.', 'zorderz' ),
			'cancel'   => __( 'Don\'t order: the customer cancelled this agreement.', 'zorderz' ),
			'other'    => __( 'Wait to order: the agreement is not OK to order yet.', 'zorderz' ),
			'cant'     => __( 'Wait to order: the agreement can\'t be signed right now.', 'zorderz' ),
			'warranty' => __( 'Wait to order: a warranty or service order must name its original job. Until it does, it waits like the rest.', 'zorderz' ),
		);
	}

	/** Which refusal an agreement gets: '' when it says OK to order. PURE. */
	public static function reason( array $a ): string {
		if ( true === ( $a['ok_to_order'] ?? null ) ) {
			return '';
		}
		$state = (string) ( $a['state'] ?? '' );
		if ( 'cancelled' === $state ) {
			return 'cancel';
		}
		if ( 'change_pending' === $state || ! empty( $a['change_pending'] ) ) {
			return 'change';
		}
		if ( in_array( $state, array( 'draft', 'ready', 'signing' ), true ) ) {
			// Nothing in the way but the customer: "hasn't signed"; a readiness item, the key, the document (or a
			// reason this build doesn't know): "can't be signed right now".
			return in_array( (string) ( $a['why'] ?? '' ), array( '', 'draft' ), true ) ? 'unsigned' : 'cant';
		}
		if ( 'signed' === $state ) {
			return '' === self::day( (string) ( $a['cancel_by'] ?? '' ) ) ? 'copy' : 'window';
		}
		return 'other';
	}

	/** '' when this agreement says OK to order; else why not, in the words staff read. */
	public static function words( array $a ): string {
		$r = self::reason( $a );
		if ( '' === $r ) {
			return '';
		}
		$t = self::texts();
		return 'window' === $r ? sprintf( $t['window'], self::day( (string) $a['cancel_by'] ) ) : $t[ $r ];
	}

	/** The short form for a list of next steps: '' when OK to order. */
	public static function short_words( array $a ): string {
		$r = self::reason( $a );
		switch ( $r ) {
			case '':
				return '';
			case 'cancel':
				return __( 'Don\'t order: cancelled', 'zorderz' );
			case 'change':
				return __( 'Wait to order: change order to sign', 'zorderz' );
			case 'unsigned':
				return __( 'Wait to order: not signed yet', 'zorderz' );
			case 'copy':
				return __( 'Wait to order: signed copy not received', 'zorderz' );
			case 'cant':
				return __( 'Wait to order: can\'t be signed now', 'zorderz' );
			case 'window':
				preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', (string) $a['cancel_by'], $m );
				/* translators: %s: a short day such as "Wed, Nov 4" */
				return sprintf( __( 'Wait to order: can cancel until %s', 'zorderz' ), gmdate( 'D, M j', gmmktime( 12, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1] ) ) );
			default:
				return __( 'Wait to order', 'zorderz' );
		}
	}

	/** A warranty or service order that names no original job, while the Project is held. A cancellation's words win. */
	public static function warranty_words( string $refusal ): string {
		$t = self::texts();
		return $t['cancel'] === $refusal ? $t['cancel'] : $t['warranty'];
	}

	/* ------------------------------------------------------------------ one estimate's agreement */

	/**
	 * PURE: the agreements app's status_for answer → what the gate goes by, or null (nothing to go by), with whether
	 * the read failed (allowed, and noted) or was denied (nothing learned).
	 *
	 * @return array{0:?array,1:bool,2:bool} [agreement, failed, denied]
	 */
	public static function decode( $s ): array {
		$s      = is_array( $s ) ? $s : array();
		$failed = false;
		$denied = false;
		$out    = null;
		if ( empty( $s['success'] ) ) {
			// The registry could not answer (a throw) — a failed read; an unknown verb comes back denied.
			$failed = empty( $s['denied'] );
		} elseif ( ! empty( $s['denied'] ) ) {
			$denied = true;
		} elseif ( true === ( $s['error'] ?? null ) ) {
			$failed = true; // the agreements app says its own read failed
		} elseif ( ! empty( $s['ok'] ) && 'none' !== (string) ( $s['state'] ?? 'none' ) ) {
			$state = (string) $s['state'];
			$why   = array_key_exists( 'unsignable_why', $s ) ? (string) $s['unsignable_why'] : null;
			$open  = true === ( $s['signable'] ?? null );
			// An unsigned agreement holds ordering unless a DECISION stops it (language_no, mode); without
			// unsignable_why, only while the customer could sign it.
			if ( ! in_array( $state, array( 'draft', 'ready', 'signing' ), true ) || $open || ( null !== $why && ! in_array( $why, self::RELEASE, true ) ) ) {
				$out = array(
					'state'          => $state,
					'ok_to_order'    => true === ( $s['ok_to_order'] ?? null ),
					'cancel_by'      => (string) ( $s['cancel_by'] ?? '' ),
					'change_pending' => ! empty( $s['change_pending'] ),
					'why'            => $open ? '' : (string) $why,
					'signed_at'      => (string) ( $s['signed_at'] ?? '' ),
					'made_at'        => (string) ( $s['made_at'] ?? '' ),
					'signed_via'     => (string) ( $s['signed_via'] ?? '' ),
				);
			}
		}
		return array( $out, $failed, $denied );
	}

	/** Where this estimate's agreement stands, read as the signed-in person; null when there is nothing to go by. */
	public static function read( int $estimate_id ): ?array {
		if ( $estimate_id <= 0 || ! class_exists( 'ZDZ_Capabilities' ) || ! method_exists( 'ZDZ_Capabilities', 'invoke' ) ) {
			return null;
		}
		$k = 'read:' . $estimate_id;
		if ( array_key_exists( $k, self::$memo ) ) {
			return self::$memo[ $k ];
		}
		try {
			$s = ZDZ_Capabilities::invoke( self::VERB, array( 'estimate_id' => $estimate_id ) );
		} catch ( \Throwable $e ) {
			$s = array(); // a throw: a failed read
		}
		list( $out, $failed, $denied )               = self::decode( $s );
		self::$memo[ 'failed:' . $estimate_id ]      = $failed;
		self::$memo[ 'denied:' . $estimate_id ]      = $denied;
		return self::$memo[ $k ] = $out;
	}

	/* ------------------------------------------------------------------ a Project */

	/** The estimate ids a Project knows (its estimate references, its jobs'), newest first, at most MAX. */
	public static function estimate_ids( array $project, array $jobs = array() ): array {
		$pid = (string) ( $project['id'] ?? '' );
		if ( '' === $pid || ! empty( $project['is_stock'] ) ) {
			return array();
		}
		$ids = array();
		if ( class_exists( 'ZJOB_Project_Refs' ) ) {
			foreach ( ZJOB_Project_Refs::all( $pid, 'estimate' ) as $eid ) {
				$ids[] = (int) $eid;
			}
		}
		foreach ( $jobs as $j ) {
			$ids[] = (int) ( $j['estimate_id'] ?? 0 );
		}
		$ids = array_values( array_unique( array_filter( $ids, static function ( $v ) {
			return $v > 0;
		} ) ) );
		rsort( $ids );
		return array_slice( $ids, 0, self::MAX );
	}

	/** Has the customer signed this agreement (in its window, copy pending, a change order, closed, or OK)? */
	private static function is_signed( array $r ): bool {
		return true === $r['ok_to_order'] || ! empty( $r['change_pending'] ) || in_array( $r['state'], array( 'signed', 'change_pending', 'window_closed' ), true );
	}

	/**
	 * PURE: the rule over a Project's reads (non-empty): OK only when every agreement says so, leaving out unsigned
	 * option estimates made before the newest signed one was signed.
	 *
	 * @return array{ok_to_order:bool,state:string,message:string,cancel_by:string,short:string}
	 */
	public static function decide( array $reads ): array {
		$signed = (bool) array_filter( $reads, array( __CLASS__, 'is_signed' ) );
		$newest = '';
		foreach ( $reads as $r ) {
			if ( self::is_signed( $r ) && (string) ( $r['signed_at'] ?? '' ) > $newest ) {
				$newest = (string) $r['signed_at'];
			}
		}
		$wait = null;
		foreach ( $reads as $r ) {
			$option = '' === $newest || (string) ( $r['made_at'] ?? '' ) < $newest;
			if ( '' === self::words( $r ) || ( $signed && in_array( $r['state'], array( 'draft', 'ready', 'signing' ), true ) && $option ) ) {
				continue;
			}
			if ( null === $wait || ( 'cancelled' === $r['state'] && 'cancelled' !== $wait['state'] ) ) {
				$wait = $r; // a cancellation is said first
			}
		}
		if ( null === $wait ) {
			return array( 'ok_to_order' => true, 'state' => 'window_closed', 'message' => '', 'cancel_by' => (string) ( $reads[0]['cancel_by'] ?? '' ), 'short' => '' );
		}
		return array( 'ok_to_order' => false, 'state' => (string) $wait['state'], 'message' => self::words( $wait ), 'cancel_by' => (string) $wait['cancel_by'], 'short' => self::short_words( $wait ) );
	}

	/** A failed read is allowed and logged, once an hour a Project, naming the Project only. */
	private static function note_failure( string $pid, int $n ): void {
		$k = self::FAIL_NOTE . md5( $pid );
		if ( function_exists( 'get_transient' ) && false !== get_transient( $k ) ) {
			return;
		}
		if ( function_exists( 'set_transient' ) ) {
			set_transient( $k, 1, 3600 );
		}
		error_log( sprintf( 'Zorderz Jobs order gate: the agreement for project %s could not be read (%d estimate%s); its order is not held (allowed, and noted here once an hour).', $pid, $n, 1 === $n ? '' : 's' ) );
	}

	/** null: no agreement to go by. Otherwise decide()'s answer, with the reads. */
	public static function for_project( array $project, array $jobs = array() ): ?array {
		$reads  = array();
		$failed = 0;
		foreach ( self::estimate_ids( $project, $jobs ) as $id ) {
			$r = self::read( $id );
			if ( null !== $r ) {
				$reads[] = $r;
			} elseif ( ! empty( self::$memo[ 'failed:' . $id ] ) ) {
				$failed++;
			}
		}
		if ( $failed ) {
			self::note_failure( (string) ( $project['id'] ?? '' ), $failed );
		}
		if ( ! $reads ) {
			return null;
		}
		$out          = self::decide( $reads );
		$out['reads'] = $reads;
		return $out;
	}

	/** '' when an order for this Project may be placed now; else the refusal. */
	public static function refusal( array $project, array $jobs = array() ): string {
		$g = self::for_project( $project, $jobs );
		return null === $g || ! empty( $g['ok_to_order'] ) ? '' : (string) $g['message'];
	}

	/** For the opened Project: the chip beside the estimate, the orders panel. Never a name or an amount. */
	public static function view( array $project, array $jobs = array() ): ?array {
		$g = self::for_project( $project, $jobs );
		if ( null === $g ) {
			return null;
		}
		$signed    = false;
		$cancelled = false;
		$via       = '';
		foreach ( (array) $g['reads'] as $r ) {
			$cancelled = $cancelled || 'cancelled' === $r['state'];
			if ( self::is_signed( $r ) ) {
				$signed = true;
				$via    = (string) $r['signed_via'];
			}
		}
		return array(
			'ok_to_order' => (bool) $g['ok_to_order'],
			'state'       => (string) $g['state'],
			'message'     => (string) $g['message'],
			'short'       => (string) $g['short'],
			'chip'        => $g['ok_to_order'] ? self::ok_chip() : '',
			'warranty'    => $g['ok_to_order'] ? '' : self::warranty_words( (string) $g['message'] ),
			'signed'      => $signed || $cancelled,
			'signed_via'  => $via,
			'cancelled'   => $cancelled,
		);
	}

	/* ------------------------------------------------------------------ the agreements app's alert link */

	/** zagr_alert_link( $url, $estimate_id, $user_id, $event ): the Project's link for a person who may open it. */
	public static function alert_link( $url, $estimate_id = 0, $user_id = 0, $event = '' ) {
		try {
			$eid = (int) $estimate_id;
			$uid = (int) $user_id;
			if ( '' !== (string) $url || $eid <= 0 || $uid <= 0 || ! class_exists( 'ZJOB_Project_Refs' ) || ! class_exists( 'Zjob_Project' ) || ! class_exists( 'ZJOB_Accept_Notice' ) ) {
				return $url;
			}
			$pid = ZJOB_Project_Refs::project_for( 'estimate', (string) $eid );
			if ( '' === $pid ) {
				return $url;
			}
			$project = Zjob_Project::get( $pid );
			if ( ! $project || ! ZJOB_Accept_Notice::can_open( $uid, $project ) ) {
				return $url;
			}
			return ZJOB_Accept_Notice::link( $pid, 'orders' );
		} catch ( \Throwable $e ) {
			return $url;
		}
	}
}
