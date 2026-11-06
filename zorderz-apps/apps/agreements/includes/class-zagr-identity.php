<?php
/**
 * ZAGR_Identity — Agreements' part of an Identity Pack: which legal pack the business's agreements follow, the
 * business facts an agreement prints, and its lead-time groups. Each is checked as the app's own Settings check it,
 * shown in the pack's preview, written on apply and put back exactly on revert (ZDZ_Identity_Pack::option_consumer()).
 *
 *   profile.yml
 *     legal:
 *       agreement_pack: us-federal          # a legal pack this site has, or "none" (no agreements)
 *     contact:
 *       function_mailboxes:
 *         cancellations: cancel@example.com # where a customer's cancellation is e-mailed
 *     insurance:
 *       general_liability: { insurer: Example Mutual, phone: "555-0100" }
 *   document-conventions.yml
 *     agreements:
 *       substantial_commencement: When we begin work at your home.
 *   rules.yml
 *     agreements:
 *       subcontractors_used: false          # true / false; unanswered is never assumed
 *     testing:
 *       fixture_customer_name: Test Customer
 *   flows.yml
 *     lead_times:                           # per group of work: days after the cancellation period, and work days
 *       standard: { label: Standard, min: 3, max: 10, install: 1 }
 *       made_to_order: { label: Made to order, min: 20, max: 40, install: 1, per_unit: true }
 *
 * Not from a pack: the license number an agreement prints (which registration that is depends on the state's law
 * and is typed on purpose in Agreements' settings), and the help phone (an agreement uses the profile's phone when
 * none is typed there).
 *
 * Registered on the Identity Pack's consumer registry (`zdz_identity_pack_consumers`).
 *
 * @package Zorderz\Agreements
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZAGR_Identity {

	public static function register( $consumers ) {
		$consumers = is_array( $consumers ) ? $consumers : array();
		if ( ! class_exists( 'ZDZ_Identity_Pack' ) || ! method_exists( 'ZDZ_Identity_Pack', 'option_consumer' ) ) {
			return $consumers;
		}
		$label = __( 'Agreements', 'zorderz' );
		$consumers['agreements-profile'] = ZDZ_Identity_Pack::option_consumer( $label, 'profile', array( 'legal.agreement_pack', 'contact.function_mailboxes.cancellations', 'insurance.general_liability' ), array( __CLASS__, 'plan_profile' ) );
		$consumers['agreements-wording'] = ZDZ_Identity_Pack::option_consumer( $label, 'document-conventions', array( 'agreements.substantial_commencement' ), array( __CLASS__, 'plan_wording' ) );
		$consumers['agreements-rules']   = ZDZ_Identity_Pack::option_consumer( $label, 'rules', array( 'agreements.subcontractors_used', 'testing.fixture_customer_name' ), array( __CLASS__, 'plan_rules' ) );
		$consumers['agreements-lead']    = ZDZ_Identity_Pack::option_consumer( $label, 'flows', array( 'lead_times' ), array( __CLASS__, 'plan_lead' ) );
		return $consumers;
	}

	/** One fact, written into zagr_facts beside the others. */
	private static function fact( array &$plan, string $path, string $key, $value ): void {
		ZDZ_Identity_Pack::plan_key( $plan, $path, ZAGR_Settings::OPT_FACTS, $key, $value );
	}

	/** profile.yml: the legal pack, the cancellations mailbox, the liability insurer. */
	public static function plan_profile( array $data ): array {
		$plan = ZDZ_Identity_Pack::plan();
		if ( array_key_exists( 'legal.agreement_pack', $data ) ) {
			$path = 'legal.agreement_pack';
			$v    = strtolower( trim( is_scalar( $data[ $path ] ) ? (string) $data[ $path ] : '' ) );
			$ids  = ZAGR_Pack::ids();
			if ( 'none' !== $v && ! in_array( $v, $ids, true ) ) {
				$plan['refused'][] = array( 'path' => $path, 'why' => sprintf( 'Not a legal pack on this site (packs: %s, or none).', implode( ', ', $ids ) ) );
			} else {
				$now   = class_exists( 'ZDZ_Business_Profile' ) ? get_option( ZDZ_Business_Profile::OPTION, array() ) : array();
				$legal = is_array( $now ) && is_array( $now['legal'] ?? null ) ? $now['legal'] : array();
				ZDZ_Identity_Pack::plan_key( $plan, $path, ZDZ_Business_Profile::OPTION, 'legal', array( 'agreement_pack' => $v ) + $legal );
			}
		}
		if ( array_key_exists( 'contact.function_mailboxes.cancellations', $data ) ) {
			$path = 'contact.function_mailboxes.cancellations';
			$v    = trim( is_scalar( $data[ $path ] ) ? (string) $data[ $path ] : '' );
			if ( strlen( $v ) > 120 || false === filter_var( $v, FILTER_VALIDATE_EMAIL ) ) {
				$plan['refused'][] = array( 'path' => $path, 'why' => 'An e-mail address (name@example.com), at most 120 characters.' );
			} else {
				self::fact( $plan, $path, 'cancel_email', $v );
			}
		}
		if ( array_key_exists( 'insurance.general_liability', $data ) ) {
			$path = 'insurance.general_liability';
			$raw  = $data[ $path ];
			ZDZ_Identity_Pack::refuse_extra( $plan, $path, $raw, array( 'insurer', 'phone' ) );
			$ins  = is_array( $raw ) && is_scalar( $raw['insurer'] ?? null ) ? ZAGR_Settings::clean( $raw['insurer'], 120 ) : '';
			$tel  = is_array( $raw ) && is_scalar( $raw['phone'] ?? null ) ? ZAGR_Settings::clean( $raw['phone'], 30 ) : '';
			if ( '' === $ins || ( '' !== $tel && ! preg_match( '/^[0-9+().\-\s]{3,30}$/', $tel ) ) ) {
				$plan['refused'][] = array( 'path' => $path, 'why' => 'The insurer\'s name, and its phone (digits, spaces, + ( ) . -) when given.' );
			} else {
				self::fact( $plan, "$path.insurer", 'cgl_insurer', $ins );
				if ( '' !== $tel ) {
					self::fact( $plan, "$path.phone", 'cgl_phone', $tel );
				}
			}
		}
		return $plan;
	}

	/** document-conventions.yml: the words for when work counts as begun. */
	public static function plan_wording( array $data ): array {
		$plan = ZDZ_Identity_Pack::plan();
		$path = 'agreements.substantial_commencement';
		$v    = is_scalar( $data[ $path ] ?? null ) ? ZAGR_Settings::clean( $data[ $path ], 300 ) : '';
		if ( '' === $v ) {
			$plan['refused'][] = array( 'path' => $path, 'why' => 'One sentence, at most 300 characters.' );
		} else {
			self::fact( $plan, $path, 'substantial_commencement', $v );
		}
		return $plan;
	}

	/** rules.yml: whether subcontractors are used, and the test customer's name. */
	public static function plan_rules( array $data ): array {
		$plan = ZDZ_Identity_Pack::plan();
		if ( array_key_exists( 'agreements.subcontractors_used', $data ) ) {
			$path = 'agreements.subcontractors_used';
			$raw  = $data[ $path ];
			$s    = is_bool( $raw ) ? ( $raw ? 'yes' : 'no' ) : strtolower( trim( is_scalar( $raw ) ? (string) $raw : '' ) );
			$map  = array( 'yes' => 'yes', 'true' => 'yes', 'no' => 'no', 'false' => 'no' );
			if ( ! isset( $map[ $s ] ) ) {
				$plan['refused'][] = array( 'path' => $path, 'why' => 'true or false: never assumed.' );
			} else {
				self::fact( $plan, $path, 'subcontractors', $map[ $s ] );
			}
		}
		if ( array_key_exists( 'testing.fixture_customer_name', $data ) ) {
			$path = 'testing.fixture_customer_name';
			$v    = is_scalar( $data[ $path ] ) ? ZAGR_Settings::clean( $data[ $path ], 60 ) : '';
			if ( '' === $v ) {
				$plan['refused'][] = array( 'path' => $path, 'why' => 'A name, at most 60 characters.' );
			} else {
				ZDZ_Identity_Pack::plan_option( $plan, $path, ZAGR_Settings::OPT_TEST, $v );
			}
		}
		return $plan;
	}

	/**
	 * flows.yml lead_times: the groups of work, each checked by ZAGR_Settings::clean_group(); every day count set and
	 * each range running forward. A group that fails refuses the whole list: half a list is never applied.
	 */
	public static function plan_lead( array $data ): array {
		$plan  = ZDZ_Identity_Pack::plan();
		$path  = 'lead_times';
		$raw   = $data[ $path ];
		$out   = array();
		$wrong = false;
		foreach ( is_array( $raw ) ? $raw : array() as $id => $g ) {
			$g = is_array( $g ) ? $g : array();
			$c = ZAGR_Settings::clean_group( array( 'id' => is_int( $id ) ? ( $g['id'] ?? '' ) : $id ) + $g );
			if ( null === $c || '' === $c['min'] || '' === $c['max'] || '' === $c['install'] || $c['max'] < $c['min'] || isset( $out[ $c['id'] ] ) ) {
				$plan['refused'][] = array( 'path' => "$path.$id", 'why' => 'An id (a-z, 0-9, - and _), and min, max and install as whole days from 0 to 365, max not before min.' );
				$wrong             = true;
				continue;
			}
			$out[ $c['id'] ] = $c;
		}
		if ( ! is_array( $raw ) || count( $out ) > 20 ) {
			$plan['refused'][] = array( 'path' => $path, 'why' => 'A map of at most 20 groups of work.' );
			$wrong             = true;
		}
		if ( ! $wrong && $out ) {
			ZDZ_Identity_Pack::plan_option( $plan, $path, ZAGR_Settings::OPT_LEAD, array_values( $out ) );
		}
		return $plan;
	}
}
