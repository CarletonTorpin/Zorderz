<?php
/**
 * Zorderz Jobs — the CRM seam (a Flow/mirror provider abstraction).
 *
 * The rest of the app does NOT hard-couple to a concrete CRM. It calls
 * ZJOB_CRM::provider()->method(...); the default adapter mirrors into the CRM the
 * theme is configured for (ZDZ_Core_Nutshell today), and a future Core Flow
 * adapter drops in via the `zdz_job_crm_provider` filter with no rewrite.
 *
 * INVARIANTS PRESERVED
 *   - All CRM wire calls route through the theme's shared client (one credential
 *     authority; no private key copies here).
 *   - The app record is authoritative; every provider method is best-effort and
 *     never blocks the app-side transition. A missing CRM degrades to a neutral
 *     "unavailable" result, never a fatal.
 *
 * This class touches neither billing nor the DB; it is a routing layer only.
 *
 * @package Zorderz\Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The contract a CRM/Flow mirror provider must satisfy. A job row (assoc) goes in;
 * a neutral best-effort result comes back. Nothing provider-specific appears in the
 * signatures, so swapping the provider changes nothing for callers.
 */
interface ZJOB_CRM_Provider {

	/** Is the backing CRM available and configured for writes right now? */
	public function available(): bool;

	/**
	 * Mirror a new job into the CRM (a specialist child lead today).
	 *
	 * @return array{ok:bool,child_lead_id:int,steps:array<string,bool>,error:string}
	 */
	public function create_child_lead( array $job ): array;

	/**
	 * Record the worker's completion on the CRM (a note + finish-photo permalinks).
	 *
	 * @return array{ok:bool,error:string,steps:array<string,bool>}
	 */
	public function post_completion_note( array $job, array $photo_links, bool $verified, int $worker_id ): array;

	/**
	 * Note the worker's ETA signal ('on_my_way' | 'running_late') on the customer record.
	 *
	 * @return array{ok:bool,error:string}
	 */
	public function post_eta_note( array $job, string $eta, int $worker_id ): array;

	/**
	 * Retire the mirrored record on close-out (a child lead closed as an internal,
	 * not-a-sale outcome — never Won). Best-effort; never blocks the app close.
	 *
	 * @return array{ok:bool,error:string,steps:array<string,bool>}
	 */
	public function close_child_lead( array $job, int $actor_id, bool $auto = false ): array;

	/**
	 * Resolve a CRM contact's address/phone/business for auto-filling a job card.
	 *
	 * @return array{address:string,phone:string,business:string}
	 */
	public function resolve_contact_info( int $contact_id ): array;
}

/**
 * The default adapter: a thin delegator to the concrete CRM adapter (ZJOB_Nutshell).
 * It moves NO logic out of the adapter, so the proven wire behaviour is unchanged.
 * If the adapter is absent, each method returns the neutral "unavailable" shape a
 * CRM-unconfigured call already returns, so callers need no extra guarding.
 */
class ZJOB_Nutshell_Provider implements ZJOB_CRM_Provider {

	public function available(): bool {
		return class_exists( 'ZJOB_Nutshell' ) && ZJOB_Nutshell::available();
	}

	public function create_child_lead( array $job ): array {
		if ( ! class_exists( 'ZJOB_Nutshell' ) ) {
			return [ 'ok' => false, 'child_lead_id' => 0, 'steps' => [], 'error' => 'crm_unavailable' ];
		}
		return ZJOB_Nutshell::create_child_lead( $job );
	}

	public function post_completion_note( array $job, array $photo_links, bool $verified, int $worker_id ): array {
		if ( ! class_exists( 'ZJOB_Nutshell' ) ) {
			return [ 'ok' => false, 'error' => 'crm_unavailable', 'steps' => [] ];
		}
		return ZJOB_Nutshell::post_completion_note( $job, $photo_links, $verified, $worker_id );
	}

	public function post_eta_note( array $job, string $eta, int $worker_id ): array {
		if ( ! class_exists( 'ZJOB_Nutshell' ) ) {
			return [ 'ok' => false, 'error' => 'crm_unavailable' ];
		}
		return ZJOB_Nutshell::post_eta_note( $job, $eta, $worker_id );
	}

	public function close_child_lead( array $job, int $actor_id, bool $auto = false ): array {
		if ( ! class_exists( 'ZJOB_Nutshell' ) ) {
			return [ 'ok' => false, 'error' => 'crm_unavailable', 'steps' => [] ];
		}
		return ZJOB_Nutshell::close_child_lead( $job, $actor_id, $auto );
	}

	public function resolve_contact_info( int $contact_id ): array {
		if ( ! class_exists( 'ZJOB_Nutshell' ) ) {
			return [ 'address' => '', 'phone' => '', 'business' => '' ];
		}
		return ZJOB_Nutshell::resolve_contact_info( $contact_id );
	}
}

/**
 * The resolver. The app calls ZJOB_CRM::provider()->method(...) instead of naming a
 * concrete CRM class, so the mirror backend is swappable in one place.
 */
class ZJOB_CRM {

	/** Cached active provider for this request. */
	private static $provider = null;

	/**
	 * The active CRM provider. Defaults to the built-in adapter; a site (or a future
	 * Core Flow build) can substitute any ZJOB_CRM_Provider via `zdz_job_crm_provider`.
	 * A filter that returns a non-provider is ignored (fail closed), so the mirror can
	 * never be silently disabled by a bad filter.
	 */
	public static function provider(): ZJOB_CRM_Provider {
		if ( self::$provider instanceof ZJOB_CRM_Provider ) {
			return self::$provider;
		}
		$default = new ZJOB_Nutshell_Provider();
		/**
		 * Swap the job/pipeline CRM mirror provider.
		 *
		 * @param ZJOB_CRM_Provider $default The built-in adapter.
		 */
		$chosen = apply_filters( 'zdz_job_crm_provider', $default );
		if ( ! ( $chosen instanceof ZJOB_CRM_Provider ) ) {
			$chosen = $default; // fail closed — never disable the mirror via a bad filter
		}
		self::$provider = $chosen;
		return self::$provider;
	}

	/** Convenience: is a CRM mirror available right now? */
	public static function available(): bool {
		return self::provider()->available();
	}
}
