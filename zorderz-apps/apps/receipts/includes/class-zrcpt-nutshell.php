<?php
/**
 * ZRCPT Nutshell — embedded CRM client for the Receipts app.
 *
 * Delegates to ZPREP_Nutshell when present (same rationale as
 * ZRCPT_FreshBooks — reuse production-tested credentials + API client).
 *
 * Adds one Receipts-specific behavior: find_install_notes_for_lead().
 * the Prep module pulls the pre-work full-measurements note;
 * Receipts needs post-work install content (Trap 3): activities of
 * type "On-Site Installation" + notes matching install keywords.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class ZRCPT_Nutshell {

	/** @var ZPREP_Nutshell|null */
	private $delegate = null;

	private array $last_trace = [];

	public function __construct() {
		if ( class_exists( 'ZPREP_Nutshell' ) ) {
			$this->delegate = new ZPREP_Nutshell();
		}
	}

	public function is_ready(): bool {
		if ( $this->delegate ) return $this->delegate->is_ready();
		return (bool) ( get_option( 'tsec_ns_api_key' ) || get_option( 'ts_surveys_ns_api_key' ) );
	}

	public function get_active_prefix(): string {
		if ( $this->delegate ) return $this->delegate->get_active_prefix();
		foreach ( [ 'tsec_', 'ts_surveys_', 'tsa_', 'tsl_', 'ts_core_' ] as $p ) {
			if ( get_option( $p . 'ns_api_key' ) ) return $p;
		}
		return '';
	}

	public function get_last_trace(): array {
		if ( $this->delegate ) return $this->delegate->get_last_trace();
		return $this->last_trace;
	}

	public function find_lead_for_customer( array $customer ): ?array {
		if ( $this->delegate ) return $this->delegate->find_lead_for_customer( $customer );
		$this->last_trace[] = 'CRM lookup unavailable — configure the CRM connection in Zorderz settings.';
		return null;
	}

	/**
	 * Find install-related notes + activities for a Nutshell lead.
	 *
	 * Three places to look, per Trap 3:
	 *   1. Activity feed — findActivities({leadId: N}), filter type="On-Site Installation"
	 *   2. Notes on the lead with install-keyword matches
	 *   3. Activity-attached notes (getActivity)
	 *
	 * Returns a combined, de-duplicated array of { source, content, timestamp }.
	 * Empty array if lead has no install content — that's not an error; the
	 * receipt can still be generated from invoice + photos alone.
	 */
	/**
	 * @param int|array $lead Either the Nutshell lead id (legacy callers) OR the
	 *                        full lead array returned by find_lead_for_customer()
	 *                        (preferred — it already carries notes[], so we avoid
	 *                        a redundant API round-trip and the missing-method
	 *                        fatal below).
	 */
	public function find_install_notes_for_lead( $lead ): array {
		$out = [];

		// Resolve the notes list WITHOUT assuming any particular delegate method
		// exists. Preference order:
		//   1. If the caller passed the lead array, use its notes[] directly —
		//      find_lead_for_customer() already populated it (each entry is a
		//      note body string). This is the normal path and needs no API call.
		//   2. Else, only if the delegate actually exposes get_notes_for_lead(),
		//      call it. (Older/newer the Prep module builds may not — guarding with
		//      method_exists() is what prevents the "Call to undefined method
		//      ZPREP_Nutshell::get_notes_for_lead()" fatal we hit in production.)
		//   3. Otherwise degrade gracefully to empty — per the contract below,
		//      "zero install content" is not an error; the receipt still builds.
		$notes   = [];
		$lead_id = 0;

		if ( is_array( $lead ) ) {
			$lead_id = (int) ( $lead['id'] ?? 0 );
			if ( isset( $lead['notes'] ) && is_array( $lead['notes'] ) ) {
				$notes = $lead['notes'];
			}
		} else {
			$lead_id = (int) $lead;
		}

		if ( empty( $notes ) ) {
			if ( $this->delegate && method_exists( $this->delegate, 'get_notes_for_lead' ) && $lead_id > 0 ) {
				try {
					$fetched = $this->delegate->get_notes_for_lead( $lead_id );
					if ( is_array( $fetched ) ) {
						$notes = $fetched;
					}
				} catch ( \Throwable $e ) {
					$this->last_trace[] = 'get_notes_for_lead failed: ' . $e->getMessage();
				}
			} elseif ( ! $this->delegate ) {
				$this->last_trace[] = 'CRM client unavailable — configure the CRM connection in Zorderz settings.';
				return $out;
			} else {
				// Delegate present but no notes accessor and none supplied on the
				// lead — nothing to scan. Not an error (see contract).
				$this->last_trace[] = 'No notes available for lead (no inline notes; delegate has no get_notes_for_lead).';
			}
		}

		$install_keywords = [ 'install', 'installed', 'install date', 'install photos', 'photos', 'completed', 'on-site' ];
		foreach ( (array) $notes as $note ) {
			$body = '';
			if ( is_array( $note ) ) {
				$body = (string) ( $note['note'] ?? $note['body'] ?? $note['content'] ?? '' );
			} elseif ( is_string( $note ) ) {
				$body = $note;
			}
			if ( $body === '' ) continue;

			$body_lower = strtolower( $body );
			foreach ( $install_keywords as $kw ) {
				if ( strpos( $body_lower, $kw ) !== false ) {
					$out[] = [
						'source'    => 'note',
						'content'   => $body,
						'timestamp' => is_array( $note ) ? ( $note['created_time'] ?? $note['createdTime'] ?? '' ) : '',
					];
					break;
				}
			}
		}

		// (2) and (3) — activity feed + activity-attached notes — would require
		// extending ZPREP_Nutshell with findActivities + getActivity wrappers.
		// For v2.9.0 we rely on the note-keyword path; activity-based discovery
		// is a documented follow-up. The spec is explicit that "zero install
		// content found" is not a blocker — receipt still generates.

		// Sort most-recent first; empty timestamps fall to the end.
		usort( $out, function ( $a, $b ) {
			return strcmp( $b['timestamp'] ?? '', $a['timestamp'] ?? '' );
		} );

		return $out;
	}

	public function test_connection(): array {
		if ( $this->delegate ) return $this->delegate->test_connection();
		return [
			'ok'      => false,
			'message' => '',
			'error'   => 'CRM connection not configured — configure it in Zorderz settings.',
			'log'     => [],
		];
	}
}
