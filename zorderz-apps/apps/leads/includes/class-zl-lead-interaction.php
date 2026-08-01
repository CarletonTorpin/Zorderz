<?php
/**
 * ZL Lead Interaction — AJAX handlers for lead management within the SPA widget.
 *
 * Provides:
 *   - Nutshell notes fetching (timeline activities for a lead)
 *   - Lead status updates (contacted/skipped/callback) with Nutshell sync
 *   - Forward-to-team member via ZDZ_Alert_Router
 *   - Forward history and completion tracking
 *   - Stale batch cleanup cron
 *
 * Modeled after ts-satisfaction-surveys/includes/class-ts-dashboard.php
 * forward handlers and ts-satisfaction-surveys/assets/js/widget.js
 * interaction patterns.
 *
 * @since 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZL_Lead_Interaction {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Lead interaction AJAX endpoints
		add_action( 'wp_ajax_zl_get_lead_notes',        array( $this, 'ajax_get_lead_notes' ) );
		add_action( 'wp_ajax_zl_forward_note',          array( $this, 'ajax_forward_note' ) );
		add_action( 'wp_ajax_zl_get_forwards',          array( $this, 'ajax_get_forwards' ) );
		add_action( 'wp_ajax_zl_mark_forward_complete', array( $this, 'ajax_mark_forward_complete' ) );
		add_action( 'wp_ajax_zl_get_team_members',      array( $this, 'ajax_get_team_members' ) );

		// Stale batch cleanup cron
		add_action( 'zl_cleanup_stale_batches', array( $this, 'cleanup_stale_batches' ) );
		if ( ! wp_next_scheduled( 'zl_cleanup_stale_batches' ) ) {
			wp_schedule_event( time(), 'fifteen_minutes', 'zl_cleanup_stale_batches' );
		}

		// Register custom cron interval
		add_filter( 'cron_schedules', array( $this, 'add_cron_intervals' ) );
	}

	/**
	 * Add custom cron intervals.
	 */
	public function add_cron_intervals( $schedules ) {
		if ( ! isset( $schedules['fifteen_minutes'] ) ) {
			$schedules['fifteen_minutes'] = array(
				'interval' => 900,
				'display'  => 'Every 15 Minutes',
			);
		}
		return $schedules;
	}

	// ═══════════════════════════════════════════════════════════════
	// NUTSHELL NOTES
	// ═══════════════════════════════════════════════════════════════

	/**
	 * Fetch Nutshell CRM timeline notes for a lead.
	 *
	 * Reads the lead's nutshell_lead_id, queries Nutshell's getTimeline
	 * endpoint, and returns parsed notes with timestamps and authors.
	 *
	 * Cached in a 5-minute transient to avoid hammering the API on
	 * rapid expand/collapse.
	 */
	public function ajax_get_lead_notes() {
		check_ajax_referer( 'zl_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'zdz_access_app' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$lead_id = (int) ( $_POST['lead_id'] ?? 0 );
		if ( ! $lead_id ) {
			wp_send_json_error( 'Missing lead_id' );
		}

		global $wpdb;
		$lead = $wpdb->get_row( $wpdb->prepare(
			"SELECT nutshell_lead_id, nutshell_contact_id, contact_notes, salesperson_notes
			 FROM {$wpdb->prefix}zl_leads WHERE id = %d",
			$lead_id
		), ARRAY_A );

		if ( ! $lead ) {
			wp_send_json_error( 'Lead not found' );
		}

		$ns_lead_id = $lead['nutshell_lead_id'] ?? '';
		$notes      = array();

		// Try to fetch from Nutshell if we have a lead ID
		if ( ! empty( $ns_lead_id ) ) {
			$cache_key = 'zl_ns_notes_' . $ns_lead_id;
			$cached    = get_transient( $cache_key );

			if ( $cached !== false ) {
				$notes = $cached;
			} else {
				// Attempt Nutshell timeline fetch
				try {
					if ( class_exists( 'ZL_Nutshell' ) ) {
						$ns = new ZL_Nutshell();
						if ( method_exists( $ns, 'get_lead_timeline' ) ) {
							$timeline = $ns->get_lead_timeline( $ns_lead_id );
							if ( is_array( $timeline ) ) {
								foreach ( $timeline as $entry ) {
									$notes[] = array(
										'date'   => $entry['date'] ?? $entry['createdTime'] ?? '',
										'author' => $entry['author'] ?? $entry['user'] ?? '',
										'text'   => $entry['note'] ?? $entry['body'] ?? $entry['description'] ?? '',
										'type'   => $entry['type'] ?? 'note',
									);
								}
							}
						} elseif ( method_exists( $ns, 'get_lead' ) ) {
							// Fallback: get lead details which may include notes
							$lead_data = $ns->get_lead( $ns_lead_id );
							if ( ! empty( $lead_data['notes'] ) ) {
								foreach ( (array) $lead_data['notes'] as $n ) {
									$notes[] = array(
										'date'   => $n['createdTime'] ?? '',
										'author' => '',
										'text'   => is_string( $n ) ? $n : ( $n['body'] ?? '' ),
										'type'   => 'note',
									);
								}
							}
						}
					}
				} catch ( \Throwable $e ) {
					error_log( 'ZL get_lead_notes Nutshell error: ' . $e->getMessage() );
				}

				set_transient( $cache_key, $notes, 300 ); // 5 min cache
			}
		}

		// Also include local notes (from contact_notes and salesperson_notes fields)
		$local_notes = array();
		if ( ! empty( $lead['contact_notes'] ) ) {
			$local_notes[] = array(
				'date'   => '',
				'author' => 'App',
				'text'   => $lead['contact_notes'],
				'type'   => 'local',
			);
		}
		if ( ! empty( $lead['salesperson_notes'] ) ) {
			$local_notes[] = array(
				'date'   => '',
				'author' => 'AI Summary',
				'text'   => $lead['salesperson_notes'],
				'type'   => 'local',
			);
		}

		wp_send_json_success( array(
			'notes'       => $notes,
			'local_notes' => $local_notes,
			'ns_lead_id'  => $ns_lead_id,
		) );
	}

	// ═══════════════════════════════════════════════════════════════
	// FORWARD-TO-TEAM
	// ═══════════════════════════════════════════════════════════════

	/**
	 * Forward lead info to a team member.
	 *
	 * Creates a record in wp_zl_lead_forwards and dispatches a
	 * notification via ZDZ_Alert_Router (theme v2.19.0+).
	 */
	public function ajax_forward_note() {
		check_ajax_referer( 'zl_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'zdz_access_app' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$lead_id      = (int) ( $_POST['lead_id'] ?? 0 );
		$recipient_id = (int) ( $_POST['recipient_id'] ?? 0 );
		$note_text    = sanitize_textarea_field( $_POST['note_text'] ?? '' );
		$is_task      = (int) ( $_POST['is_task'] ?? 0 );

		if ( ! $lead_id || ! $recipient_id ) {
			wp_send_json_error( 'Missing lead_id or recipient_id' );
		}

		if ( empty( $note_text ) ) {
			wp_send_json_error( 'Note text is required' );
		}

		$sender_id = get_current_user_id();

		// Get lead info for the notification
		global $wpdb;
		$lead = $wpdb->get_row( $wpdb->prepare(
			"SELECT l.*, b.batch_tag
			 FROM {$wpdb->prefix}zl_leads l
			 LEFT JOIN {$wpdb->prefix}zl_batches b ON b.id = l.batch_id
			 WHERE l.id = %d",
			$lead_id
		), ARRAY_A );

		if ( ! $lead ) {
			wp_send_json_error( 'Lead not found' );
		}

		$batch_id = (int) ( $lead['batch_id'] ?? 0 );

		// Insert forward record
		$wpdb->insert(
			$wpdb->prefix . 'zl_lead_forwards',
			array(
				'lead_id'      => $lead_id,
				'batch_id'     => $batch_id,
				'sender_id'    => $sender_id,
				'recipient_id' => $recipient_id,
				'note_text'    => $note_text,
				'is_task'      => $is_task,
				'status'       => 'pending',
			),
			array( '%d', '%d', '%d', '%d', '%s', '%d', '%s' )
		);

		$forward_id = $wpdb->insert_id;

		// Dispatch notification via Alert Router if available
		$recipient_name = '';
		$recipient_user = get_userdata( $recipient_id );
		if ( $recipient_user ) {
			$recipient_name = $recipient_user->display_name;
		}

		// Deliver via the theme's ZDZ_Alert_Router::send(). If the router is unavailable the
		// forward is still recorded in the DB above and returned to the caller — never a
		// silent loss (the record persists; only the push/notify hop is skipped).
		if ( class_exists( 'ZDZ_Alert_Router' ) && method_exists( 'ZDZ_Alert_Router', 'send' ) ) {
			$lead_name = trim( ( $lead['first_name'] ?? '' ) . ' ' . ( $lead['last_name'] ?? '' ) );

			ZDZ_Alert_Router::send(
				'lead_forward',
				(int) $recipient_id,
				'Lead forwarded: ' . $lead_name,
				$note_text,
				array(
					'sender_id'    => $sender_id,
					'source'       => 'leads',
					'reference_id' => $lead_id,
					'is_task'      => (bool) $is_task,
					'meta'         => array(
						'lead_id'    => $lead_id,
						'batch_id'   => $batch_id,
						'forward_id' => $forward_id,
						'lead_name'  => $lead_name,
						'lead_city'  => $lead['city'] ?? '',
					),
				)
			);
		}

		// Audit log
		if ( class_exists( 'ZDZ_Admin_Dashboard' ) && method_exists( 'ZDZ_Admin_Dashboard', 'log_action' ) ) {
			$lead_name = trim( ( $lead['first_name'] ?? '' ) . ' ' . ( $lead['last_name'] ?? '' ) );
			ZDZ_Admin_Dashboard::log_action(
				'lead_forward',
				"Forwarded lead #{$lead_id} ({$lead_name}) to {$recipient_name}",
				'zorderz'
			);
		}

		wp_send_json_success( array(
			'forward_id'     => $forward_id,
			'recipient_name' => $recipient_name,
		) );
	}

	/**
	 * Get forward history for a lead.
	 */
	public function ajax_get_forwards() {
		check_ajax_referer( 'zl_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'zdz_access_app' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$lead_id = (int) ( $_POST['lead_id'] ?? 0 );
		if ( ! $lead_id ) {
			wp_send_json_error( 'Missing lead_id' );
		}

		global $wpdb;
		$forwards = $wpdb->get_results( $wpdb->prepare(
			"SELECT f.*,
			        s.display_name AS sender_name,
			        r.display_name AS recipient_name
			 FROM {$wpdb->prefix}zl_lead_forwards f
			 LEFT JOIN {$wpdb->users} s ON s.ID = f.sender_id
			 LEFT JOIN {$wpdb->users} r ON r.ID = f.recipient_id
			 WHERE f.lead_id = %d
			 ORDER BY f.created_at DESC",
			$lead_id
		), ARRAY_A );

		wp_send_json_success( $forwards ?: array() );
	}

	/**
	 * Mark a forwarded task as complete.
	 */
	public function ajax_mark_forward_complete() {
		check_ajax_referer( 'zl_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'zdz_access_app' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$forward_id = (int) ( $_POST['forward_id'] ?? 0 );
		if ( ! $forward_id ) {
			wp_send_json_error( 'Missing forward_id' );
		}

		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'zl_lead_forwards',
			array(
				'status'       => 'completed',
				'completed_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $forward_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		wp_send_json_success( array( 'completed' => true ) );
	}

	/**
	 * Get team members for the forward dropdown.
	 *
	 * Returns all users with TS roles, excluding the current user.
	 * Cached in a 10-minute transient.
	 */
	public function ajax_get_team_members() {
		check_ajax_referer( 'zl_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'zdz_access_app' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$cache_key = 'zl_team_members';
		$cached    = get_transient( $cache_key );
		if ( $cached !== false ) {
			// Filter out current user from cached result
			$current = get_current_user_id();
			$result  = array_filter( $cached, function( $m ) use ( $current ) {
				return (int) $m['id'] !== $current;
			} );
			wp_send_json_success( array_values( $result ) );
			return;
		}

		$ts_roles = array( 'zdz_owner', 'zdz_admin', 'zdz_sales', 'zdz_operator', 'zdz_mfg', 'zdz_tech', 'administrator' );
		$users    = get_users( array(
			'role__in' => $ts_roles,
			'orderby'  => 'display_name',
			'order'    => 'ASC',
		) );

		$members = array();
		foreach ( $users as $u ) {
			$members[] = array(
				'id'   => $u->ID,
				'name' => $u->display_name,
				'role' => ! empty( $u->roles ) ? $u->roles[0] : '',
			);
		}

		set_transient( $cache_key, $members, 600 ); // 10 min

		// Filter out current user
		$current = get_current_user_id();
		$result  = array_filter( $members, function( $m ) use ( $current ) {
			return (int) $m['id'] !== $current;
		} );

		wp_send_json_success( array_values( $result ) );
	}

	// ═══════════════════════════════════════════════════════════════
	// STALE BATCH CLEANUP
	// ═══════════════════════════════════════════════════════════════

	/**
	 * Cron job: detect and clean up stale batches.
	 *
	 * Batches stuck in 'generating' or 'running' for >30 minutes
	 * with no progress heartbeat are marked as 'failed'.
	 * Orphaned transients are also cleaned up.
	 */
	public function cleanup_stale_batches() {
		global $wpdb;

		$stale = $wpdb->get_results(
			"SELECT id FROM {$wpdb->prefix}zl_batches
			 WHERE status IN ('generating', 'running')
			 AND (
			     (updated_at IS NOT NULL AND updated_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE))
			     OR
			     (updated_at IS NULL AND created_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE))
			 )",
			ARRAY_A
		);

		if ( empty( $stale ) ) {
			return;
		}

		foreach ( $stale as $row ) {
			$bid = (int) $row['id'];

			// Check ZL_Progress for recent heartbeat
			$has_recent_heartbeat = false;
			if ( class_exists( 'ZL_Progress' ) ) {
				$progress = ZL_Progress::get( $bid );
				if ( $progress && isset( $progress['updated_at'] ) ) {
					$last_beat = strtotime( $progress['updated_at'] );
					if ( $last_beat && ( time() - $last_beat ) < 1800 ) {
						$has_recent_heartbeat = true;
					}
				}
			}

			if ( $has_recent_heartbeat ) {
				continue; // Batch is still active
			}

			// Mark as failed
			$wpdb->update(
				$wpdb->prefix . 'zl_batches',
				array(
					'status'        => 'failed',
					'error_message' => 'Timed out — no progress for 30+ minutes.',
					'updated_at'    => current_time( 'mysql', true ),
				),
				array( 'id' => $bid ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);

			// Clean up orphaned transients for this batch
			$transient_keys = array(
				"zl_batch_{$bid}_customers",
				"zl_batch_{$bid}_options",
				"zl_batch_{$bid}_candidates",
				"zl_batch_{$bid}_expanded_filter",
				"zl_batch_{$bid}_unique_names",
				"zl_batch_{$bid}_cmeta",
			);
			foreach ( $transient_keys as $tk ) {
				delete_transient( $tk );
			}
			// v2.0.0: Delete chunked customer transients (up to 50 chunks for very large batches)
			for ( $ci = 0; $ci < 50; $ci++ ) {
				$chunk_key = "zl_batch_{$bid}_cchunk_{$ci}";
				if ( get_transient( $chunk_key ) === false ) {
					break; // No more chunks
				}
				delete_transient( $chunk_key );
			}

			// Release user lock
			// Find the batch creator to clear their lock
			$batch_row = $wpdb->get_row( $wpdb->prepare(
				"SELECT batch_tag FROM {$wpdb->prefix}zl_batches WHERE id = %d", $bid
			), ARRAY_A );

			error_log( "ZL stale batch cleanup: Batch #{$bid} marked as failed (no heartbeat for 30+ min)." );
		}

		// Also clean orphaned user locks older than 30 min
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			 WHERE option_name LIKE '_transient_zl_batch_lock_%'
			 AND option_value < " . ( time() - 1800 )
		);
	}

	// ═══════════════════════════════════════════════════════════════
	// SALESPERSON RESOLUTION
	// ═══════════════════════════════════════════════════════════════

	/**
	 * Resolve a WordPress user ID to their salesperson code.
	 *
	 * Resolution order:
	 *   1. User meta 'zl_salesperson_code' (explicit override)
	 *   2. Match user_login against salespeople[].code (case-insensitive)
	 *   3. Match display_name against salespeople[].name (partial)
	 *   4. Future: Theme territory system (zdz_user_territory meta)
	 *
	 * @param int $user_id
	 * @return string Salesperson code or empty string.
	 */
	public static function resolve_salesperson_code( $user_id ) {
		// 1. Explicit meta override
		$override = get_user_meta( $user_id, 'zl_salesperson_code', true );
		if ( ! empty( $override ) ) {
			return $override;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return '';
		}

		$salespeople = function_exists( 'zl_salespeople' ) ? zl_salespeople() : array();

		// 2. Username code match
		foreach ( $salespeople as $sp ) {
			if ( strtolower( $sp['code'] ?? '' ) === strtolower( $user->user_login ) ) {
				return $sp['code'];
			}
		}

		// 3. Display name match
		foreach ( $salespeople as $sp ) {
			if ( ! empty( $sp['name'] ) && stripos( $user->display_name, $sp['name'] ) !== false ) {
				return $sp['code'];
			}
		}

		// 4. Check user initials meta (used by theme)
		$initials = get_user_meta( $user_id, 'zdz_salesperson_initials', true );
		if ( ! empty( $initials ) ) {
			foreach ( $salespeople as $sp ) {
				if ( strtoupper( $sp['code'] ?? '' ) === strtoupper( $initials ) ) {
					return $sp['code'];
				}
			}
		}

		// 5. Future: theme territory system
		// $territory = get_user_meta( $user_id, 'zdz_user_territory', true );

		return '';
	}

	/**
	 * Get the territory zip codes for a salesperson code.
	 *
	 * @param string $sp_code
	 * @return string[] Array of zip codes.
	 */
	public static function get_territory_zips( $sp_code ) {
		if ( empty( $sp_code ) ) {
			return array();
		}

		$zip_map = function_exists( 'zl_zip_territories' ) ? zl_zip_territories() : array();
		$zips    = array();

		// Also check the salesperson's territory codes (from the Party-bound roster).
		$salespeople = function_exists( 'zl_salespeople' ) ? zl_salespeople() : array();

		$territory_codes = array();
		foreach ( $salespeople as $sp ) {
			if ( strtoupper( $sp['code'] ?? '' ) === strtoupper( $sp_code ) ) {
				$territory_codes = array_map( 'trim', explode( ',', strtoupper( $sp['territories'] ?? '' ) ) );
				break;
			}
		}

		if ( empty( $territory_codes ) ) {
			$territory_codes = array( strtoupper( $sp_code ) );
		}

		foreach ( $zip_map as $zip => $terr ) {
			if ( in_array( strtoupper( $terr ), $territory_codes, true ) ) {
				$zips[] = (string) $zip;
			}
		}

		return $zips;
	}
}
