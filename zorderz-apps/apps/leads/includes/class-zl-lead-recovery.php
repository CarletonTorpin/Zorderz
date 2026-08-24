<?php
/**
 * Zorderz Leads — Recover a failed lead (D-03 / S6-01)
 *
 * One-click, duplicate-safe, CRM-ONLY retry for a lead that generated locally but
 * never reached the CRM (its create threw, or the id write-back was lost). It does
 * NOT re-run the pipeline — no FreshBooks, no scoring, no AI. It rebuilds the CRM
 * push from the saved lead row and nothing else.
 *
 * Three safety properties, each structural rather than remembered:
 *
 *   1. DUPLICATE-SAFE. A retry never creates a second CRM lead. It short-circuits when
 *      the row already carries a `nutshell_lead_id` (idempotent on that stable key), and
 *      when a prior attempt created the lead but lost the local write-back it ADOPTS the
 *      existing CRM lead (adopt-not-twin) via a bounded duplicate guard.
 *
 *   2. READ-ONLY CONTACT RESOLVE. Resolution goes through {@see ZL_Crm_Port}, which has
 *      no contact-mutating verb at all — so a retry cannot blank a good phone/address
 *      over live CRM data. Creating a brand-new contact (when none exists) is allowed;
 *      overwriting an existing one is impossible by type.
 *
 *   3. CRM-ONLY. The manager is built from the CRM client alone (never the FreshBooks
 *      path), so a lapsed billing-provider token cannot block recovery.
 *
 * The core — {@see recover()}, {@see classify_error()}, {@see is_in_crm()},
 * {@see is_recoverable()}, {@see stable_key()} — is pure PHP (no WordPress, no network),
 * so its invariants are provable in a no-WP harness. The WordPress-facing glue (the
 * AJAX endpoint, the lock, the persistence, the CRM-client build) lives below it.
 *
 * @package Zorderz\Leads
 * @since   2.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZL_Lead_Recovery {

	/** Under-lock TTL for a single retry (seconds). Bounds a stuck retry, not the work. */
	const LOCK_TTL_SECONDS = 90;

	/** Bounded duplicate-guard scan: never inspect more than this many CRM leads. */
	const DUP_SCAN_LIMIT = 15;

	// ─────────────────────────────────────────────────────────────────────────
	// PURE MECHANISM (no WordPress, no network — harness-provable)
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Is this lead already in the CRM? True iff it carries a non-empty lead id.
	 * This is the stable idempotency key the retry short-circuits on.
	 *
	 * @param array $lead
	 * @return bool
	 */
	public static function is_in_crm( array $lead ): bool {
		return trim( (string) ( $lead['nutshell_lead_id'] ?? '' ) ) !== '';
	}

	/**
	 * Is this lead a candidate for recovery — i.e. it failed to reach the CRM and can
	 * be retried? A lead is recoverable when the batch is a real (non-test) batch that
	 * has stopped running, the lead has no CRM lead id, and it is not a placeholder.
	 * This predicate drives whether the UI offers the "Create Now" button.
	 *
	 * @param array $lead
	 * @param array $batch
	 * @return bool
	 */
	public static function is_recoverable( array $lead, array $batch ): bool {
		if ( self::is_in_crm( $lead ) ) {
			return false; // already there — nothing to recover
		}
		if ( (int) ( $batch['is_test'] ?? 0 ) === 1 ) {
			return false; // test batches never push to the CRM
		}
		// Only offer recovery once the batch has stopped generating (terminal state):
		// complete, no_matches, or failed. A still-running batch may yet create it.
		$status   = (string) ( $batch['status'] ?? '' );
		$terminal = array( 'complete', 'completed', 'finalized', 'failed', 'no_matches' );
		if ( $status !== '' && ! in_array( $status, $terminal, true ) ) {
			return false;
		}
		// A lead with neither a name nor an email/phone is not a real, pushable lead.
		$has_name    = trim( (string) ( $lead['first_name'] ?? '' ) . ( $lead['last_name'] ?? '' ) ) !== '';
		$has_contact = trim( (string) ( $lead['email'] ?? '' ) ) !== ''
			|| trim( (string) ( $lead['phone'] ?? '' ) ) !== '';
		return $has_name || $has_contact;
	}

	/**
	 * A deterministic, stable idempotency key for a lead. Same row → same key, so a
	 * retry (or a double-submit) targets exactly one unit of work. Prefers the DB row
	 * id (globally unique); falls back to batch + billing-client + lowered email.
	 *
	 * @param array $lead
	 * @return string
	 */
	public static function stable_key( array $lead ): string {
		$id = (int) ( $lead['id'] ?? 0 );
		if ( $id > 0 ) {
			return 'zl_lead:' . $id;
		}
		$parts = array(
			(string) ( $lead['batch_id'] ?? '' ),
			(string) ( $lead['freshbooks_client_id'] ?? '' ),
			strtolower( trim( (string) ( $lead['email'] ?? '' ) ) ),
		);
		return 'zl_lead:' . md5( implode( '|', $parts ) );
	}

	/**
	 * Split a raw error message into { retryable, label }. A retryable error is a
	 * transient upstream condition (timeout, 5xx, rate limit, connection) that a later
	 * retry may clear; a non-retryable error is our own configuration (auth / 401 /
	 * bad key) or a permanent validation reject, where hammering retry only wastes
	 * calls. The label is a short, human sentence for the operator.
	 *
	 * @param string $message
	 * @return array{retryable:bool,label:string}
	 */
	public static function classify_error( $message ): array {
		$m = strtolower( (string) $message );

		// Configuration / auth — NOT retryable. Recovery cannot fix a bad credential.
		$auth_markers = array( '401', 'authentication failed', 'unauthorized', 'invalid api key', 'api key', 'forbidden', '403' );
		foreach ( $auth_markers as $marker ) {
			if ( $marker !== '' && strpos( $m, $marker ) !== false ) {
				return array( 'retryable' => false, 'label' => 'CRM authentication problem — check the CRM credentials in Settings.' );
			}
		}

		// Transient transport / upstream — retryable.
		$transient_markers = array( 'timeout', 'timed out', 'temporarily', 'rate limit', '429', '500', '502', '503', '504', 'gateway', 'connection', 'could not resolve host', 'http error', 'network' );
		foreach ( $transient_markers as $marker ) {
			if ( $marker !== '' && strpos( $m, $marker ) !== false ) {
				return array( 'retryable' => true, 'label' => 'Temporary CRM problem — you can try again.' );
			}
		}

		// Unknown → treat as retryable but say so plainly (an unclassified error is more
		// often a blip than a permanent reject, and a retry is cheap and duplicate-safe).
		return array( 'retryable' => true, 'label' => 'Could not reach the CRM — you can try again.' );
	}

	/**
	 * The recovery itself. Never throws — always returns a persistable result array so
	 * the caller can record the outcome. Depends only on the {@see ZL_Crm_Port}, so the
	 * whole flow is exercised in the harness against a fake CRM.
	 *
	 * Result shape:
	 *   {
	 *     ok                  bool
	 *     action              'already' | 'adopted' | 'created' | 'created_with_contact' | 'error'
	 *     nutshell_lead_id    string   (set when ok)
	 *     nutshell_contact_id string   (set when a contact was resolved/created)
	 *     contact_created     bool
	 *     error               string   (set when !ok)
	 *     retryable           bool     (set when !ok)
	 *     label               string   (human summary)
	 *   }
	 *
	 * @param array        $lead
	 * @param array        $batch
	 * @param ZL_Crm_Port  $crm
	 * @return array
	 */
	public static function recover( array $lead, array $batch, ZL_Crm_Port $crm ): array {
		$result = array(
			'ok'                  => false,
			'action'              => 'error',
			'nutshell_lead_id'    => '',
			'nutshell_contact_id' => (string) ( $lead['nutshell_contact_id'] ?? '' ),
			'contact_created'     => false,
			'error'               => '',
			'retryable'           => false,
			'label'               => '',
		);

		// 1. Idempotency short-circuit — already in the CRM. No CRM calls, no twin.
		if ( self::is_in_crm( $lead ) ) {
			$result['ok']               = true;
			$result['action']           = 'already';
			$result['nutshell_lead_id'] = (string) $lead['nutshell_lead_id'];
			$result['label']            = 'This lead is already in the CRM.';
			return $result;
		}

		$batch_tag = (string) ( $batch['batch_tag'] ?? '' );
		$email     = trim( (string) ( $lead['email'] ?? '' ) );

		try {
			// 2. Resolve the contact READ-ONLY. Never mutate an existing contact.
			$contact_id = null;

			$existing_cid = (int) ( $lead['nutshell_contact_id'] ?? 0 );
			if ( $existing_cid > 0 ) {
				// Verify the pinned contact still exists (read-only). If it's gone,
				// fall through to re-resolve rather than trusting a stale id.
				$existing = $crm->get_contact( $existing_cid );
				if ( is_array( $existing ) ) {
					$contact_id = $existing_cid;
				}
			}

			if ( $contact_id === null && $email !== '' ) {
				$contact_id = $crm->find_contact_id_by_email( $email );
			}

			$contact_created = false;
			if ( $contact_id === null ) {
				// No existing contact — creating a NEW one is not a mutation of live data.
				$contact_id      = $crm->create_contact( $lead );
				$contact_created = ( $contact_id !== null );
			}

			if ( $contact_id === null ) {
				$result['error']     = 'Could not resolve or create the CRM contact.';
				$result['retryable'] = true;
				$result['label']     = 'Could not reach the CRM — you can try again.';
				return $result;
			}

			$result['nutshell_contact_id'] = (string) $contact_id;
			$result['contact_created']     = $contact_created;

			// 3. Adopt-not-twin: did a prior attempt already create this lead? (bounded scan)
			$adopted = $crm->find_existing_lead_for_contact( (int) $contact_id, $batch_tag, self::DUP_SCAN_LIMIT );
			if ( $adopted !== null ) {
				$result['ok']               = true;
				$result['action']           = 'adopted';
				$result['nutshell_lead_id'] = (string) $adopted;
				$result['label']            = 'Re-linked an existing CRM lead (no duplicate created).';
				return $result;
			}

			// 4. Create the lead.
			$lead_id = $crm->create_lead( $lead, (int) $contact_id, $batch_tag );
			if ( $lead_id === null ) {
				$result['error']     = 'The CRM did not return a lead id.';
				$result['retryable'] = true;
				$result['label']     = 'Could not reach the CRM — you can try again.';
				return $result;
			}

			$result['ok']               = true;
			$result['action']           = $contact_created ? 'created_with_contact' : 'created';
			$result['nutshell_lead_id'] = (string) $lead_id;
			$result['label']            = 'Created in the CRM.';
			return $result;

		} catch ( \Throwable $e ) {
			$class               = self::classify_error( $e->getMessage() );
			$result['action']    = 'error';
			$result['error']     = $e->getMessage();
			$result['retryable'] = $class['retryable'];
			$result['label']     = $class['label'];
			return $result;
		}
	}

	// ─────────────────────────────────────────────────────────────────────────
	// WORDPRESS GLUE (AJAX endpoint, lock, persistence, CRM-only client build)
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Register the recovery AJAX endpoint. Called once from zl_load_includes().
	 */
	public static function init(): void {
		add_action( 'wp_ajax_zl_retry_lead', array( __CLASS__, 'ajax_retry_lead' ) );
	}

	/**
	 * AJAX: one-click retry for a single failed lead. Nonce + capability + ownership
	 * gated; idempotency-short-circuited; guarded by a 90s transient lock with an
	 * under-lock re-read so a double-click cannot double-push.
	 */
	public static function ajax_retry_lead(): void {
		@set_time_limit( 120 );
		check_ajax_referer( 'zl_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'zdz_access_app' ) ) {
			wp_send_json_error( array( 'label' => 'Unauthorized' ) );
		}

		$lead_id = (int) ( $_POST['lead_id'] ?? 0 );
		if ( $lead_id <= 0 ) {
			wp_send_json_error( array( 'label' => 'Missing lead id.' ) );
		}

		// Ownership gate — a salesperson may only recover a lead assigned to them
		// (admins/operators may recover any; the shared device may recover none).
		if ( class_exists( 'ZL_Lead_Assignment' )
			&& method_exists( 'ZL_Lead_Assignment', 'current_user_can_act_on_lead' )
			&& ! ZL_Lead_Assignment::current_user_can_act_on_lead( $lead_id ) ) {
			wp_send_json_error( array( 'label' => 'You can only recover leads assigned to you.' ) );
		}

		// 90s transient lock (best-effort; the true duplicate-safety is the idempotency
		// key + adopt-not-twin guard below). If a retry is already in flight, say so.
		$lock_key = 'zl_retry_lock_' . $lead_id;
		if ( get_transient( $lock_key ) ) {
			wp_send_json_error( array( 'label' => 'A retry for this lead is already in progress.', 'in_progress' => true ) );
		}
		set_transient( $lock_key, 1, self::LOCK_TTL_SECONDS );

		global $wpdb;
		try {
			// Under-lock re-read: fetch the freshest lead + batch so the idempotency
			// check sees any id a concurrent request may have just written back.
			$lead = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}zl_leads WHERE id = %d",
				$lead_id
			), ARRAY_A );

			if ( ! is_array( $lead ) ) {
				delete_transient( $lock_key );
				wp_send_json_error( array( 'label' => 'Lead not found.' ) );
			}

			$batch = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}zl_batches WHERE id = %d",
				(int) $lead['batch_id']
			), ARRAY_A );
			if ( ! is_array( $batch ) ) {
				$batch = array();
			}

			// Idempotency short-circuit before we even build a client.
			if ( self::is_in_crm( $lead ) ) {
				delete_transient( $lock_key );
				wp_send_json_success( array(
					'ok'               => true,
					'action'           => 'already',
					'nutshell_lead_id' => (string) $lead['nutshell_lead_id'],
					'label'            => 'This lead is already in the CRM.',
					'lead_id'          => $lead_id,
				) );
			}

			// Build the CRM-ONLY manager (never the FreshBooks path).
			$port = self::build_crm_port();
			if ( ! $port instanceof ZL_Crm_Port ) {
				delete_transient( $lock_key );
				wp_send_json_error( array( 'label' => 'The CRM is not configured — add credentials in Settings.' ) );
			}

			$res = self::recover( $lead, $batch, $port );

			// Persist a successful outcome. Only WRITE-BACK ids; never touch counts.
			if ( ! empty( $res['ok'] ) && $res['nutshell_lead_id'] !== '' ) {
				$update = array(
					'nutshell_lead_id' => (string) $res['nutshell_lead_id'],
					'status'           => 'Lead Created',
				);
				$fmt = array( '%s', '%s' );
				if ( $res['nutshell_contact_id'] !== ''
					&& (string) ( $lead['nutshell_contact_id'] ?? '' ) === '' ) {
					$update['nutshell_contact_id'] = (string) $res['nutshell_contact_id'];
					$fmt[] = '%s';
				}
				$wpdb->update( $wpdb->prefix . 'zl_leads', $update, array( 'id' => $lead_id ), $fmt, array( '%d' ) );

				if ( class_exists( 'ZDZ_Admin_Dashboard' ) && method_exists( 'ZDZ_Admin_Dashboard', 'log_action' ) ) {
					ZDZ_Admin_Dashboard::log_action( 'lead_generator', "Recovered lead #{$lead_id} → CRM lead {$res['nutshell_lead_id']} ({$res['action']})" );
				}
			}

			// Structured breadcrumb so a recovery is never silent.
			if ( function_exists( 'zl_log_disposition' ) ) {
				zl_log_disposition( 'lead_recovery_' . ( ! empty( $res['ok'] ) ? $res['action'] : 'error' ), array(
					'batch_id'  => (int) ( $lead['batch_id'] ?? 0 ),
					'lead_id'   => $lead_id,
					'ok'        => (bool) $res['ok'],
					'retryable' => (bool) ( $res['retryable'] ?? false ),
				) );
			}

			delete_transient( $lock_key );

			$res['lead_id'] = $lead_id;
			if ( ! empty( $res['ok'] ) ) {
				wp_send_json_success( $res );
			}
			// A failed retry is a real response (with a retryable flag + human label),
			// not a thrown error — the button can decide whether to offer another try.
			wp_send_json_error( $res );

		} catch ( \Throwable $e ) {
			delete_transient( $lock_key );
			$class = self::classify_error( $e->getMessage() );
			wp_send_json_error( array(
				'ok'        => false,
				'action'    => 'error',
				'error'     => $e->getMessage(),
				'retryable' => $class['retryable'],
				'label'     => $class['label'],
				'lead_id'   => $lead_id,
			) );
		}
	}

	/**
	 * Build the CRM-ONLY port. Constructs the CRM client alone (self-resolving its
	 * credential from the shared Core settings) — deliberately NOT init_clients(), so a
	 * lapsed FreshBooks token cannot block recovery. Returns null when the CRM client
	 * class is unavailable.
	 *
	 * @return ZL_Crm_Port|null
	 */
	private static function build_crm_port(): ?ZL_Crm_Port {
		if ( ! class_exists( 'ZL_Nutshell' ) || ! class_exists( 'ZL_Nutshell_Crm_Port' ) ) {
			return null;
		}
		// No-arg construction resolves the shared CRM credential (ZDZ_Core_Settings) —
		// the same single source the rest of the app uses. No private client, no
		// hardcoded key.
		$ns = new ZL_Nutshell();
		return new ZL_Nutshell_Crm_Port( $ns );
	}
}
