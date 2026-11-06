<?php
/**
 * ZEST_Pairs - a new stub that names TWO salespeople, "(AB & CD)".
 *
 * Territories may answer a pair to an asker that says it can write two (`pairs: true` on territories.route_for): a job
 * that needs a specialist for part of the work and another salesperson for the rest. The pair is written in one bracket
 * on the location line, the shape every reader of that line already takes as two people (ZEST_Salesperson, the line
 * edits, the jobs app's codes):
 *
 *     Location | 1 Example Way, Springfield - (AB & CD)
 *
 * WHO IS TOLD. Territories tells each person ITS rules named, once the save reports the codes it wrote
 * (zest_operator_routed). A pair's second person can instead be "keep this app's pick" (the rest of the work fell to
 * Territories' last rule): Territories answers that person's code with no user and tells nobody, because the pick is
 * this app's. This class tells that person, so each person on a pair hears of it once:
 *
 *     Territories' answer            location line   told by
 *     AB (rule) + QP (rule)          (AB & QP)       Territories, both
 *     AB (rule) + CD (this app)      (AB & CD)       Territories to AB; this app to CD
 *
 * Only for a NEW operator stub, a LIVE decision, and only when what was written is the pair that was answered (an edit
 * in review that changed the codes sends nothing, as Territories does). Never the operator who made it, never the
 * shared device, never someone off the team. The notice names the estimate by number, its postal code and the other
 * person's code: never the customer.
 *
 * A pair is used only when BOTH people check out: each named person must be exactly one account with that code and the
 * account Territories named; an "own pick" person must carry this stub's own default code. Otherwise the whole answer is
 * set aside and the default stands, so the notice and the billing system never disagree.
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZEST_Pairs {

	/** The alert type for the person this app picked on a pair. */
	const ALERT = 'estimate_stub_shared';

	public static function init(): void {
		add_action( 'zdz_register_alert_types', array( __CLASS__, 'alert_type' ) );
	}

	public static function alert_type( $router ): void {
		if ( is_object( $router ) && method_exists( $router, 'register_type' ) ) {
			$router->register_type( self::ALERT, array(
				'label'            => __( 'Estimate stubs you share', 'zorderz' ),
				'description'      => __( 'A new estimate stub names you with another salesperson.', 'zorderz' ),
				'default_channels' => array( 'in_app' ),
				'source_plugin'    => 'estimate-creator',
				'category'         => 'sales',
			) );
		}
	}

	/* ------------------------------------------------------------------ the writing */

	/**
	 * "AB & CD", "(ab&cd)", "AB/CD", "AB, CD", "AB and CD" -> [ 'AB', 'CD' ]; "QP" -> [ 'QP' ]. [] when any part is not
	 * 2-3 letters, when a code repeats, or when there are more than two.
	 *
	 * @return string[]
	 */
	public static function split( string $ini ): array {
		$s = strtoupper( trim( $ini, " \t()" ) );
		if ( '' === $s ) {
			return array();
		}
		$out = array();
		foreach ( (array) preg_split( '/\s*(?:&|\/|,|\bAND\b)\s*/', $s ) as $p ) {
			$p = trim( (string) $p );
			if ( ! preg_match( '/^[A-Z]{2,3}$/', $p ) || in_array( $p, $out, true ) ) {
				return array();
			}
			$out[] = $p;
		}
		return count( $out ) <= 2 ? $out : array();
	}

	/** [ 'AB', 'CD' ] -> "AB & CD" (the one way this app writes a pair). */
	public static function join( array $codes ): string {
		return implode( ' & ', array_values( $codes ) );
	}

	/** The same writing? "(ab & cd)" = "AB&CD" = "AB & CD"; order counts (the first is the rule's person). */
	public static function same( string $a, string $b ): bool {
		$x = self::split( $a );
		return $x && $x === self::split( $b );
	}

	/* ------------------------------------------------------------------ checking a live pair */

	/** The one account holding this code, or 0 (ZEST_Territories' rule). */
	public static function holder( string $ini ): int {
		return class_exists( 'ZEST_Territories' ) ? ZEST_Territories::user_with_initials( $ini ) : 0;
	}

	/**
	 * A LIVE two-person answer from territories.route_for, checked person by person.
	 *
	 * @param array  $a       Territories' answer: initials "AB & CD", people[ {user_id, initials} ]
	 * @param string $default this stub's own pick (the code this app would write without Territories)
	 * @return array{ok:bool, initials:string, people:array<int,array{user_id:int,initials:string,own_pick:bool}>, why:string}
	 */
	public static function resolve( array $a, string $default ): array {
		$bad   = static function ( string $why ) {
			return array( 'ok' => false, 'initials' => '', 'people' => array(), 'why' => $why );
		};
		$codes = self::split( (string) ( $a['initials'] ?? '' ) );
		if ( 2 !== count( $codes ) ) {
			return $bad( 'live answer is not two people' );
		}
		$default = strtoupper( trim( $default ) );
		$given   = array_values( is_array( $a['people'] ?? null ) ? $a['people'] : array() );
		$people  = array();
		foreach ( $codes as $n => $ini ) {
			$p    = is_array( $given[ $n ] ?? null ) ? $given[ $n ] : array();
			$tuid = (int) ( $p['user_id'] ?? 0 );
			$pini = strtoupper( trim( (string) ( $p['initials'] ?? $ini ) ) );
			if ( $pini !== $ini ) {
				return $bad( 'person ' . ( $n + 1 ) . ' of the pair does not match its code' );
			}
			$uid = self::holder( $ini );
			if ( $tuid > 0 ) {
				if ( $uid <= 0 || $uid !== $tuid ) {
					return $bad( 'a code of the pair is not exactly one person' );
				}
				$people[] = array( 'user_id' => $uid, 'initials' => $ini, 'own_pick' => false );
			} elseif ( '' !== $default && $ini === $default ) {
				$people[] = array( 'user_id' => $uid, 'initials' => $ini, 'own_pick' => true );
			} else {
				return $bad( 'a code of the pair names nobody Territories found and is not this app\'s pick' );
			}
		}
		if ( ! empty( $people[0]['own_pick'] ) || $people[0]['user_id'] <= 0 ) {
			return $bad( 'the first of a pair must be the person a rule named' );
		}
		return array( 'ok' => true, 'initials' => self::join( $codes ), 'people' => $people, 'why' => 'live pair' );
	}

	/* ------------------------------------------------------------------ after the save */

	/**
	 * Tell the person of a live pair whom Territories does not tell (this app's own pick). Called right after the stub is
	 * saved, for a new operator stub only. $route: ZEST_Territories::route()'s answer (worked out on the server);
	 * $written: the codes the location line was written with; $from: the operator who made it. Once per decision.
	 *
	 * @return array<int,array{to:int,channels:array}> who was told
	 */
	public static function notify_own_picks( array $route, string $written, string $number, string $zip, int $from ): array {
		$people = array_values( (array) ( $route['people'] ?? array() ) );
		if ( 2 !== count( $people ) || 'live' !== (string) ( $route['mode'] ?? '' ) || (int) ( $route['decision_id'] ?? 0 ) <= 0 ) {
			return array();
		}
		if ( ! self::same( $written, (string) ( $route['initials'] ?? '' ) ) ) {
			return array();   // the codes were changed before the save: nobody is told about a pair that was not written
		}
		$once = 'zest_pair_told_' . (int) $route['decision_id'];
		if ( false !== get_transient( $once ) ) {
			return array();
		}
		set_transient( $once, 1, DAY_IN_SECONDS );
		$number = substr( (string) preg_replace( '/[^A-Za-z0-9#\-]/', '', $number ), 0, 40 );
		$zip    = preg_match( '/^[A-Za-z0-9 \-]{3,10}$/', trim( $zip ) ) ? trim( $zip ) : '';
		$told   = array();
		foreach ( $people as $n => $p ) {
			$to = (int) ( $p['user_id'] ?? 0 );
			if ( empty( $p['own_pick'] ) || $to <= 0 || $to === $from || ! get_userdata( $to ) || ! self::on_team( $to ) ) {
				continue;
			}
			$other = (string) ( $people[ 1 - $n ]['initials'] ?? '' );
			$title = __( 'A new estimate for you', 'zorderz' );
			/* translators: 1: " (#123)" or "", 2: " in 12345" or "", 3: the other person's code, 4: the pair */
			$msg   = sprintf( __( 'A new estimate stub%1$s%2$s names you with %3$s (%4$s).', 'zorderz' ),
				'' !== $number ? ' (#' . ltrim( $number, '#' ) . ')' : '', '' !== $zip ? ' ' . sprintf( __( 'in %s', 'zorderz' ), $zip ) : '', $other, self::join( self::split( (string) $route['initials'] ) ) );
			$used  = array();
			if ( class_exists( 'ZDZ_Alert_Router' ) && method_exists( 'ZDZ_Alert_Router', 'send' ) ) {
				try {
					$used = (array) ZDZ_Alert_Router::send( self::ALERT, $to, $title, $msg, array(
						'plugin'    => 'estimate-creator',
						'source_id' => (string) (int) $route['decision_id'],
						'sender_id' => $from,
						'url'       => function_exists( 'home_url' ) ? home_url( '/' ) : '',
					) );
				} catch ( \Throwable $e ) {
					$used = array();
				}
			}
			$told[] = array( 'to' => $to, 'channels' => $used );
		}
		return $told;
	}

	/** A team member (the staff or admin rung), never the shared device or an account with no rung. */
	private static function on_team( int $uid ): bool {
		if ( class_exists( 'ZEST_Salesperson' ) && ZEST_Salesperson::is_kiosk( $uid ) ) {
			return false;
		}
		if ( class_exists( 'ZDZ_Capabilities' ) && method_exists( 'ZDZ_Capabilities', 'tier_of' ) ) {
			return in_array( ZDZ_Capabilities::tier_of( $uid ), array( 'staff', 'admin' ), true );
		}
		$u = get_userdata( $uid );
		return $u && (bool) array_intersect( (array) $u->roles, array( 'administrator', 'zdz_owner', 'zdz_admin', 'zdz_sales', 'zdz_operator' ) );
	}
}
