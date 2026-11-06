<?php
/**
 * ZPREP_Assignments — per-job assignment grants.
 *
 * A manager (owner/admin level) can assign one Prep job to one person. The grant does two
 * things:
 *
 *   - it gives the assignee Prep access through the platform's additive per-person seam
 *     (`zdz_extra_allowed_apps`), so a person with no Prep access of their own can open
 *     Prep — and then sees ONLY the jobs assigned to them;
 *   - a job assigned to someone hides it from other non-managers' queues.
 *
 * Grants are rows, never deleted: revoking stamps revoked_at/by, so the history of who was
 * given which job stays. The shared device is never an assignee. When Team Roles is present
 * and an ability key is configured (`zprep_cut_ability`, empty by default), only a person
 * Team Roles says can (or is learning to) do that work may be assigned.
 *
 * The decisions are pure static methods (decide_create, card_visible, revoke_allowed) so
 * each rule is a unit test; the rest is storage.
 *
 * @package Zorderz\Prep
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZPREP_Assignments {

	const TABLE = 'zprep_job_assignments';

	/** A job assigned to someone is hidden from other non-managers. */
	const VIS_HIDDEN = 'hidden';

	/** While true, this class adds nothing to the per-person app seam (to read base access). */
	private static $suspended = false;

	/** Per-request memo of live-grant lookups. */
	private static $memo = array();

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	public static function migrate(): void {
		global $wpdb;
		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			assigned_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			assignee_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			job_key VARCHAR(80) NOT NULL DEFAULT '',
			job_label VARCHAR(191) NOT NULL DEFAULT '',
			reason VARCHAR(191) NOT NULL DEFAULT '',
			visibility VARCHAR(16) NOT NULL DEFAULT 'hidden',
			expires_at DATETIME NULL DEFAULT NULL,
			revoked_at DATETIME NULL DEFAULT NULL,
			revoked_by BIGINT UNSIGNED NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY idx_assignee (assignee_id, revoked_at),
			KEY idx_job (job_key, revoked_at)
		) {$charset};" );
	}

	public static function register_hooks(): void {
		add_filter( 'zdz_extra_allowed_apps', array( __CLASS__, 'filter_extra_allowed_apps' ), 10, 2 );
	}

	/** Add Prep to the apps of a person holding a live grant (additive; Safe Mode and the deny list still win). */
	public static function filter_extra_allowed_apps( $apps, $user_id ) {
		$apps = is_array( $apps ) ? $apps : array();
		if ( ! self::$suspended && self::has_live_grant( (int) $user_id ) ) {
			$apps[] = ZPREP_APP_ID;
		}
		return $apps;
	}

	/** Run $fn with this class's app-seam contribution switched off. */
	public static function without_grants( callable $fn ) {
		$was              = self::$suspended;
		self::$suspended  = true;
		try {
			return $fn();
		} finally {
			self::$suspended = $was;
		}
	}

	/* ================================================================
	 * DECISIONS (pure)
	 * ================================================================ */

	/**
	 * May $actor assign $job_key to $assignee?
	 *
	 * @return string 'forbidden' | 'no_job' | 'no_user' | 'shared_device' | 'not_able' | 'unchanged' | 'reassign' | 'assign'
	 */
	public static function decide_create( bool $actor_is_manager, string $job_key, bool $assignee_exists, bool $assignee_is_shared, bool $assignee_able, int $current_assignee, int $assignee ): string {
		if ( ! $actor_is_manager ) {
			return 'forbidden';
		}
		if ( '' === trim( $job_key ) ) {
			return 'no_job';
		}
		if ( $assignee <= 0 || ! $assignee_exists ) {
			return 'no_user';
		}
		if ( $assignee_is_shared ) {
			return 'shared_device';
		}
		if ( ! $assignee_able ) {
			return 'not_able';
		}
		if ( $current_assignee === $assignee ) {
			return 'unchanged';
		}
		return $current_assignee > 0 ? 'reassign' : 'assign';
	}

	/**
	 * Does a queue card show for $me? Managers see every card; an assignee-only person sees
	 * only cards assigned to them; everyone else sees cards that are unassigned or theirs.
	 */
	public static function card_visible( string $mode, int $me, int $card_assignee ): bool {
		if ( 'manager' === $mode ) {
			return true;
		}
		if ( 'assignee' === $mode ) {
			return $me > 0 && $card_assignee === $me;
		}
		if ( 'team' === $mode ) {
			return 0 === $card_assignee || $card_assignee === $me;
		}
		return false;
	}

	/** May $actor revoke a grant? The manager who made it, or any manager; never twice. */
	public static function revoke_allowed( int $actor, int $assigned_by, bool $actor_is_manager, bool $already_revoked ): bool {
		if ( $actor <= 0 || $already_revoked ) {
			return false;
		}
		return $actor_is_manager || $actor === $assigned_by;
	}

	/** The job keys a queue card answers to (an estimate number and/or a CRM lead). */
	public static function card_keys( array $job ): array {
		$keys = array();
		if ( ! empty( $job['job_key'] ) ) {
			$keys[] = (string) $job['job_key'];
		}
		$en = trim( (string) ( $job['estimate_number'] ?? '' ) );
		if ( '' !== $en ) {
			$keys[] = 'est:' . $en;
		}
		$lid = (int) ( $job['lead_id'] ?? 0 );
		if ( $lid > 0 ) {
			$keys[] = 'lead:' . $lid;
		}
		return array_values( array_unique( $keys ) );
	}

	/** The one job key a (lead, source job) pair is worked under. */
	public static function job_key_for( int $lead_id, string $source_job ): string {
		$sj = trim( $source_job );
		if ( '' !== $sj ) {
			return preg_match( '/^(remake|est|lead):/', $sj ) ? $sj : 'est:' . $sj;
		}
		return $lead_id > 0 ? 'lead:' . $lead_id : '';
	}

	/* ================================================================
	 * QUEUE
	 * ================================================================ */

	/** Keep the cards $me may see; each kept card gains assignee_id / assignee_name. */
	public static function filter_cards( array $jobs, int $me, string $mode ): array {
		$out = array();
		foreach ( $jobs as $job ) {
			if ( ! is_array( $job ) ) {
				continue;
			}
			$a = 0;
			foreach ( self::card_keys( $job ) as $k ) {
				$a = self::assignee_of( $k );
				if ( $a > 0 ) {
					break;
				}
			}
			if ( ! self::card_visible( $mode, $me, $a ) ) {
				continue;
			}
			$job['assignee_id']   = $a;
			$job['assignee_name'] = $a > 0 ? self::display_name( $a ) : '';
			$out[]                = $job;
		}
		return $out;
	}

	/* ================================================================
	 * READS
	 * ================================================================ */

	public static function has_live_grant( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		$mk = 'live:' . $user_id;
		if ( array_key_exists( $mk, self::$memo ) ) {
			return self::$memo[ $mk ];
		}
		global $wpdb;
		$hit = $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM `' . self::table_name() . '` WHERE assignee_id = %d AND revoked_at IS NULL AND ( expires_at IS NULL OR expires_at > %s ) LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a class constant; values are placeholders.
			$user_id,
			current_time( 'mysql' )
		) );
		return self::$memo[ $mk ] = (bool) $hit;
	}

	public static function viewer_assigned_to_job( int $viewer_id, string $job_key ): bool {
		return $viewer_id > 0 && '' !== $job_key && self::assignee_of( $job_key ) === $viewer_id;
	}

	/** @return string[] the live job keys assigned to $user_id. */
	public static function job_keys_for( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}
		global $wpdb;
		$keys = $wpdb->get_col( $wpdb->prepare(
			'SELECT job_key FROM `' . self::table_name() . '` WHERE assignee_id = %d AND revoked_at IS NULL AND ( expires_at IS NULL OR expires_at > %s )', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a class constant; values are placeholders.
			$user_id,
			current_time( 'mysql' )
		) );
		return array_values( array_unique( array_filter( (array) $keys ) ) );
	}

	public static function active_for_job( string $job_key ): ?array {
		if ( '' === $job_key ) {
			return null;
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM `' . self::table_name() . '` WHERE job_key = %s AND revoked_at IS NULL AND ( expires_at IS NULL OR expires_at > %s ) ORDER BY id DESC LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a class constant; values are placeholders.
			$job_key,
			current_time( 'mysql' )
		), ARRAY_A );
		return $row ?: null;
	}

	public static function assignee_of( string $job_key ): int {
		$mk = 'job:' . $job_key;
		if ( ! array_key_exists( $mk, self::$memo ) ) {
			$row               = self::active_for_job( $job_key );
			self::$memo[ $mk ] = $row ? (int) $row['assignee_id'] : 0;
		}
		return self::$memo[ $mk ];
	}

	/* ================================================================
	 * WRITES
	 * ================================================================ */

	/**
	 * Assign a job. Reassigning revokes the earlier grant first.
	 *
	 * @return array|WP_Error the live grant row.
	 */
	public static function create( int $actor, int $assignee, string $job_key, string $job_label = '', string $reason = '' ) {
		$job_key = trim( $job_key );
		$current = self::active_for_job( $job_key );
		$verdict = self::decide_create(
			ZPREP_Dashboard::is_manager( $actor ),
			$job_key,
			$assignee > 0 && (bool) get_userdata( $assignee ),
			self::is_shared_device( $assignee ),
			self::is_able( $assignee ),
			$current ? (int) $current['assignee_id'] : 0,
			$assignee
		);
		$errors = array(
			'forbidden'     => __( 'Only a manager can assign jobs.', 'zorderz' ),
			'no_job'        => __( 'No job to assign.', 'zorderz' ),
			'no_user'       => __( 'Pick a person to assign to.', 'zorderz' ),
			'shared_device' => __( 'The shared device cannot be assigned a job.', 'zorderz' ),
			'not_able'      => __( 'That person is not marked as able to do this work.', 'zorderz' ),
		);
		if ( isset( $errors[ $verdict ] ) ) {
			return new WP_Error( 'zprep_assign_' . $verdict, $errors[ $verdict ] );
		}
		if ( 'unchanged' === $verdict ) {
			return $current;
		}
		if ( 'reassign' === $verdict ) {
			self::revoke( $actor, (int) $current['id'] );
		}
		global $wpdb;
		$ok = $wpdb->insert( self::table_name(), array(
			'created_at'  => current_time( 'mysql' ),
			'assigned_by' => $actor,
			'assignee_id' => $assignee,
			'job_key'     => substr( $job_key, 0, 80 ),
			'job_label'   => substr( sanitize_text_field( $job_label ), 0, 191 ),
			'reason'      => substr( sanitize_text_field( $reason ), 0, 191 ),
			'visibility'  => self::VIS_HIDDEN,
		), array( '%s', '%d', '%d', '%s', '%s', '%s', '%s' ) );
		if ( ! $ok ) {
			return new WP_Error( 'zprep_assign_db', __( 'Could not save the assignment.', 'zorderz' ) );
		}
		self::$memo = array();
		$id         = (int) $wpdb->insert_id;
		ZPREP_Settings::disposition( 'job_assigned', array( 'assignment_id' => $id, 'job_key' => $job_key, 'assignee' => $assignee, 'reassigned' => 'reassign' === $verdict ) );
		return self::get( $id );
	}

	/** Revoke the live grant on a job. */
	public static function revoke_job( int $actor, string $job_key ): bool {
		$row = self::active_for_job( $job_key );
		return $row ? self::revoke( $actor, (int) $row['id'] ) : false;
	}

	public static function revoke( int $actor, int $assignment_id ): bool {
		global $wpdb;
		$row = self::get( $assignment_id );
		if ( ! $row || ! self::revoke_allowed( $actor, (int) $row['assigned_by'], ZPREP_Dashboard::is_manager( $actor ), null !== $row['revoked_at'] ) ) {
			return false;
		}
		$wpdb->update(
			self::table_name(),
			array( 'revoked_at' => current_time( 'mysql' ), 'revoked_by' => $actor ),
			array( 'id' => $assignment_id ),
			array( '%s', '%d' ),
			array( '%d' )
		);
		self::$memo = array();
		ZPREP_Settings::disposition( 'job_unassigned', array( 'assignment_id' => $assignment_id, 'job_key' => (string) $row['job_key'] ) );
		return true;
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM `' . self::table_name() . '` WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a class constant; the id is a placeholder.
		return $row ?: null;
	}

	/* ================================================================
	 * PEOPLE
	 * ================================================================ */

	/** The shared device is never a person who is given work. */
	public static function is_shared_device( int $uid ): bool {
		if ( $uid <= 0 ) {
			return false;
		}
		if ( class_exists( 'ZDZ_User_Roles' ) && method_exists( 'ZDZ_User_Roles', 'is_shared_device' ) ) {
			return (bool) ZDZ_User_Roles::is_shared_device( $uid );
		}
		$u = get_userdata( $uid );
		return $u && in_array( 'zdz_general', (array) $u->roles, true );
	}

	/**
	 * May this person be given Prep work? With Team Roles present and an ability key set
	 * (`zprep_cut_ability`), only someone marked Can or Learning for it; otherwise anyone
	 * who is not the shared device.
	 */
	public static function is_able( int $uid ): bool {
		$key = trim( (string) apply_filters( 'zprep_cut_ability', (string) ZPREP_Settings::get( 'cut_ability', '' ) ) );
		if ( '' === $key || ! function_exists( 'zdz_person_can' ) ) {
			return true;
		}
		$a = zdz_person_can( $uid, $key );
		if ( ! is_array( $a ) ) {
			return false;
		}
		return ! empty( $a['can'] ) || 'learning' === (string) ( $a['level'] ?? '' );
	}

	public static function display_name( int $uid ): string {
		$u = $uid > 0 ? get_userdata( $uid ) : null;
		/* translators: %d: user id. */
		return $u ? (string) $u->display_name : sprintf( __( 'user #%d', 'zorderz' ), $uid );
	}

	public static function flush_memo(): void {
		self::$memo = array();
	}
}
