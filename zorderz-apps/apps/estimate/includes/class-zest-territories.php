<?php
/**
 * ZEST_Territories — the estimate app asks Territories who gets a new operator stub.
 *
 * When an operator (zest_create_zero_estimates, not the admin tier) creates a new $0 stub, the stub's
 * salesperson line asks territories.route_for through the capability registry (a read; the registry stamps
 * who is asking). Territories never sees the customer: `key` is this app's own keyed hash of the customer
 * and the reference, so the same stub asked again gets the same decision and two customers' stubs never
 * share one. The location line is not sent: it says nothing about the work.
 *
 *   - In shadow (every rule until an admin turns one live), or with no answer, the stub keeps today's pick
 *     (the operator's own code) and Territories records what it would have chosen.
 *   - A LIVE answer naming one person writes their initials and makes them the stub's salesperson, but
 *     only when exactly one account holds those initials and it is the person Territories named.
 *   - A LIVE answer naming two people (pairs) writes both, "(AB & CD)", each checked the same way; any
 *     doubt keeps the default whole.
 *   - Once the stub is saved, Territories is told what was written:
 *       do_action( 'zest_operator_routed', $decision_id, $estimate_number, $initials_written )
 *     and notifies the person (only for a live decision whose initials are the ones written).
 *
 * Re-assigning existing stubs (admin tier) is ZEST_Reassign: previewed with territories.preview_route, written to the
 * billing system and this app's copy with an undo, and told to Territories (`zest_reassign_applied`).
 *
 * Everything here is guarded: without Territories (or its verbs) nothing changes and nothing is asked.
 *
 * @package Zorderz\Estimates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ZEST_Territories' ) ) {

	class ZEST_Territories {

		const VERB         = 'territories.route_for';
		const PREVIEW_VERB = 'territories.preview_route';
		const MAX_LINES    = 60;

		/** Is a verb registered? Building the registry runs every app's registration, so it is guarded too. */
		private static function has_verb( string $verb ): bool {
			if ( ! class_exists( 'ZDZ_Capabilities' ) || ! method_exists( 'ZDZ_Capabilities', 'get' ) ) {
				return false;
			}
			try {
				return (bool) ZDZ_Capabilities::get( $verb );
			} catch ( \Throwable $e ) {
				return false;
			}
		}

		/** The one user whose initials are these (case-insensitive), or 0 when none or several. */
		public static function user_with_initials( string $ini ): int {
			$ini = strtoupper( trim( $ini ) );
			if ( ! preg_match( '/^[A-Z]{2,3}$/', $ini ) ) {
				return 0;
			}
			$found = array();
			foreach ( (array) get_users( array( 'meta_key' => 'zdz_user_initials', 'meta_compare' => 'EXISTS', 'fields' => 'ID', 'number' => 500 ) ) as $id ) {
				$id = (int) ( is_object( $id ) ? ( $id->ID ?? 0 ) : $id );
				if ( $id > 0 && strtoupper( trim( (string) get_user_meta( $id, 'zdz_user_initials', true ) ) ) === $ini ) {
					$found[] = $id;
				}
			}
			return 1 === count( $found ) ? $found[0] : 0;
		}

		/** "(AB)", "AB & CD", "AB/CD" -> [ 'AB', 'CD' ]. */
		public static function split( string $initials ): array {
			$s = strtoupper( trim( $initials, " \t()" ) );
			return '' === $s ? array() : array_values( array_filter( preg_split( '/\s*[&\/,]\s*/', $s ) ) );
		}

		/** The lines Territories reads: no location line, no customer. */
		private static function lines( array $doc ): array {
			$out = array();
			foreach ( (array) ( $doc['line_items'] ?? ( $doc['items'] ?? array() ) ) as $li ) {
				if ( ! is_array( $li ) ) {
					continue;
				}
				$name = (string) ( $li['description'] ?? ( $li['name'] ?? '' ) );
				if ( preg_match( '/^\s*(property\s+)?location\b/i', $name ) || 'context' === ( $li['kind'] ?? '' ) ) {
					continue;
				}
				$out[] = array(
					'description'     => $name,
					'sub_description' => (string) ( $li['sub_description'] ?? '' ),
					'notes'           => (string) ( $li['notes'] ?? '' ),
				);
			}
			return array_slice( $out, 0, self::MAX_LINES );
		}

		/**
		 * Ask who gets this new stub. $default: the initials this app would write without Territories.
		 *
		 * @return array{initials:string, user_id:int, people:array, decision_id:int, mode:string, why:string}
		 *         `initials` differs from $default only when a LIVE rule named people that check out.
		 */
		public static function route( array $doc, string $default ): array {
			$out = array( 'initials' => $default, 'user_id' => 0, 'people' => array(), 'decision_id' => 0, 'mode' => '', 'why' => 'not active' );
			if ( ! self::has_verb( self::VERB ) ) {
				return $out;
			}
			$c      = is_array( $doc['customer'] ?? null ) ? $doc['customer'] : array();
			$name   = trim( (string) ( $c['name'] ?? trim( ( $c['first_name'] ?? '' ) . ' ' . ( $c['last_name'] ?? '' ) ) ) );
			$street = strtolower( trim( (string) ( $c['street'] ?? ( $c['address'] ?? '' ) ) ) );
			$ref    = (string) ( $doc['reference'] ?? '' );
			// A keyed hash (the site's salt), and none at all when nothing identifies the customer, so two nameless stubs are never merged.
			$key = ( '' === $name && '' === $street ) ? '' : wp_hash( 'zest-territories|' . strtolower( $name ) . '|' . $street . '|' . $ref );
			try {
				$a = ZDZ_Capabilities::invoke( self::VERB, array(
					'zip'              => (string) ( $c['zip'] ?? ( $doc['customer_zip'] ?? '' ) ),
					'reference'        => $ref,
					'lines'            => self::lines( $doc ),
					'default_initials' => $default,
					'test'             => false,
					'key'              => $key,
					'pairs'            => true,
				) );
			} catch ( \Throwable $e ) {
				$a = null;
			}
			if ( ! is_array( $a ) || empty( $a['success'] ) || ! empty( $a['denied'] ) ) {
				$out['why'] = 'no answer';
				return $out;
			}
			$out['decision_id'] = (int) ( $a['decision_id'] ?? 0 );
			$out['mode']        = (string) ( $a['mode'] ?? '' );
			if ( 'live' !== $out['mode'] ) {
				$out['why'] = 'shadow';
				return $out;
			}
			$want = self::split( (string) ( $a['initials'] ?? '' ) );
			if ( ! $want || count( $want ) > 2 ) {
				$out['why'] = 'live, nobody named';
				return $out;
			}
			// A pair is checked person by person (ZEST_Pairs): each named person exactly one account, an "own pick"
			// person carrying this stub's default code. Any doubt keeps the default whole.
			if ( 2 === count( $want ) && class_exists( 'ZEST_Pairs' ) ) {
				$pair = ZEST_Pairs::resolve( $a, $default );
				if ( empty( $pair['ok'] ) ) {
					$out['why'] = 'live pair set aside: ' . (string) $pair['why'];
					return $out;
				}
				$out['initials'] = (string) $pair['initials'];
				$out['user_id']  = (int) $pair['people'][0]['user_id'];
				$out['people']   = $pair['people'];
				$out['why']      = 'live';
				return $out;
			}
			// Each person written must be exactly one account and the person Territories named, or the notice and
			// the billing system would disagree.
			$named  = array_values( (array) ( $a['people'] ?? array() ) );
			$people = array();
			foreach ( $want as $n => $ini ) {
				$uid    = self::user_with_initials( $ini );
				$expect = 2 === count( $want ) ? (int) ( $named[ $n ]['user_id'] ?? 0 ) : (int) ( $a['user_id'] ?? 0 );
				if ( 2 === count( $want ) && $ini === strtoupper( $default ) && 0 === $expect ) {
					$people[] = array( 'user_id' => 0, 'initials' => $ini ); // "keep this app's pick" for the rest of the work
					continue;
				}
				if ( $uid <= 0 || $uid !== $expect ) {
					$out['why'] = 'live answer "' . $ini . '" is not exactly one person; kept the default';
					return $out;
				}
				$people[] = array( 'user_id' => $uid, 'initials' => $ini );
			}
			$out['initials'] = implode( ' & ', array_map( function ( $p ) { return $p['initials']; }, $people ) );
			$out['user_id']  = (int) $people[0]['user_id'];
			$out['people']   = $people;
			$out['why']      = 'live';
			return $out;
		}

		/** The output context (initials, owner) for a routed stub; $fallback when the route changed nothing. */
		public static function out_ctx( array $route, array $fallback ): array {
			if ( 'live' !== ( $route['mode'] ?? '' ) || (int) ( $route['user_id'] ?? 0 ) <= 0 ) {
				return $fallback;
			}
			return array( 'initials' => (string) $route['initials'], 'parenthetical' => '', 'user_id' => (int) $route['user_id'] );
		}

		/**
		 * After the stub is saved: this app's copy names the routed salesperson, Territories is told what was written, and
		 * the person of a live pair whom Territories does not tell (this app's own pick) is told (ZEST_Pairs).
		 */
		public static function after_save( array $route, string $number, string $written, string $zip = '', int $from = 0 ): void {
			$decision = (int) ( $route['decision_id'] ?? 0 );
			if ( $decision <= 0 ) {
				return;
			}
			if ( 'live' === ( $route['mode'] ?? '' ) && (int) ( $route['user_id'] ?? 0 ) > 0 && '' !== $number && class_exists( 'ZEST_DB' ) ) {
				global $wpdb;
				$wpdb->update( ZEST_DB::estimates_table(), array( 'salesperson' => substr( $written, 0, 32 ) ), array( 'billing_doc_num' => $number ) );
			}
			try {
				do_action( 'zest_operator_routed', $decision, $number, $written );
			} catch ( \Throwable $e ) {
				error_log( 'Zorderz Estimates: a territories listener failed - ' . $e->getMessage() );
			}
			if ( class_exists( 'ZEST_Pairs' ) && 2 === count( (array) ( $route['people'] ?? array() ) ) ) {
				ZEST_Pairs::notify_own_picks( $route, $written, $number, $zip, $from );
			}
		}
	}
}
