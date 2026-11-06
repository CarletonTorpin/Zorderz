<?php
/**
 * ZJOB_Project_People — the people on a project, by the step they take.
 *
 * FIVE STEPS. seller (who sold it), installer (who does the work), installer2 (the second
 * installer of a job split between two people), orderer (who orders its materials), preparer
 * (who prepares it in the shop). Team Roles names a task after one of these (`job_role`), so a
 * person's own Sell / Install marks can stand for the step.
 *
 * WHO MAY TAKE A STEP. Only someone the tier ladder lets work in the app (ZDZ_Capabilities: viewer
 * and up; a seller staff and up), never the shared device.
 *
 * NOTHING IS SET UNTIL SOMEONE CONFIRMS. A confirmation is a row in {prefix}zjob_project_people (who,
 * which step, who confirmed, when); a new one ends the old one (ended_at/by kept), and clearing ends it
 * without a new one. WHO MAY CONFIRM: the admin tier, or someone on the project (its originator, or a
 * job's creator or assignee). The person confirmed must be able to take the step.
 *
 * THE INSTALLER SUGGESTION. When Territories is here, ask_installer() asks territories.installer_for
 * (a read verb, as the signed-in person) for the job's lines and ZIP; the answer names a suggestion and
 * the others who could. When the salesperson confirms an installer, the decision is reported back:
 *
 *     do_action( 'zjob_installer_confirmed', $decision_id, $user_id, $job_number, $part );
 *
 * so Territories can record who got the work and take the live turn. Without Territories nothing is
 * suggested and nothing is reported.
 *
 * @package Zorderz\Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ZJOB_Project_People' ) ) {

	class ZJOB_Project_People {

		const ROLES = array( 'seller', 'installer', 'installer2', 'orderer', 'preparer' );

		const OPT_READY = 'zjob_people_ready';

		/** @var bool|null */
		private static $ready = null;

		public static function init(): void {
			add_action( 'wp_ajax_zjob_project_person', array( __CLASS__, 'ajax_set' ) );
			// No _nopriv variant.
		}

		public static function table(): string {
			global $wpdb;
			return $wpdb->prefix . 'zjob_project_people';
		}

		public static function install(): void {
			global $wpdb;
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			$t = self::table();
			dbDelta( "CREATE TABLE {$t} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				project_id VARCHAR(64) NOT NULL DEFAULT '',
				role VARCHAR(16) NOT NULL DEFAULT '',
				user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				decision_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				confirmed_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				confirmed_at DATETIME NULL DEFAULT NULL,
				ended_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				ended_at DATETIME NULL DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY project_role (project_id, role),
				KEY user_id (user_id)
			) " . $wpdb->get_charset_collate() . ';' );
			$ok = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t;
			update_option( self::OPT_READY, $ok ? 1 : 0, true );
			self::$ready = null;
		}

		public static function ready(): bool {
			if ( null === self::$ready ) {
				self::$ready = 1 === (int) get_option( self::OPT_READY, 0 );
			}
			return self::$ready;
		}

		/** The words people read for each step. */
		public static function labels(): array {
			return array(
				'seller'     => __( 'Salesperson', 'zorderz' ),
				'installer'  => __( 'Installer', 'zorderz' ),
				'installer2' => __( 'Second installer', 'zorderz' ),
				'orderer'    => __( 'Orders materials', 'zorderz' ),
				'preparer'   => __( 'Prepares in the shop', 'zorderz' ),
			);
		}

		/** May this person take this step? Viewer and up (a seller staff and up); never the shared device. */
		public static function eligible( int $uid, string $role ): bool {
			if ( $uid <= 0 || ! in_array( $role, self::ROLES, true ) ) {
				return false;
			}
			if ( class_exists( 'ZDZ_User_Roles' ) && method_exists( 'ZDZ_User_Roles', 'is_shared_device' ) && ZDZ_User_Roles::is_shared_device( $uid ) ) {
				return false;
			}
			if ( class_exists( 'ZDZ_Hierarchy' ) && method_exists( 'ZDZ_Hierarchy', 'is_kiosk' ) && ZDZ_Hierarchy::is_kiosk( $uid ) ) {
				return false;
			}
			if ( ! class_exists( 'ZDZ_Capabilities' ) || ! method_exists( 'ZDZ_Capabilities', 'tier_of' ) ) {
				return false; // no ladder to ask: nobody is placed
			}
			$tier = (string) ZDZ_Capabilities::tier_of( $uid );
			if ( 'seller' === $role ) {
				return in_array( $tier, array( 'staff', 'admin' ), true );
			}
			return in_array( $tier, array( 'viewer', 'staff', 'admin' ), true );
		}

		/** Who is confirmed on a project: role => { user_id, by, at, decision_id }. */
		public static function confirmed( string $project_id ): array {
			global $wpdb;
			if ( '' === $project_id || ! self::ready() ) {
				return array();
			}
			$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT role, user_id, decision_id, confirmed_by, confirmed_at FROM ' . self::table() . ' WHERE ended_at IS NULL AND project_id = %s ORDER BY id ASC', $project_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name; the id is a placeholder.
			$out  = array();
			foreach ( $rows as $r ) {
				$out[ (string) $r['role'] ] = array( 'user_id' => (int) $r['user_id'], 'by' => (int) $r['confirmed_by'], 'at' => (string) $r['confirmed_at'], 'decision_id' => (int) $r['decision_id'] );
			}
			return $out;
		}

		/** May $uid confirm people on this project? The admin tier, or someone on the project. Never the shared device. */
		public static function may_confirm( int $uid, string $project_id ): bool {
			if ( $uid <= 0 || '' === $project_id ) {
				return false;
			}
			if ( ( class_exists( 'ZDZ_User_Roles' ) && method_exists( 'ZDZ_User_Roles', 'is_shared_device' ) && ZDZ_User_Roles::is_shared_device( $uid ) )
				|| ( class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_kiosk( $uid ) ) ) {
				return false;
			}
			if ( class_exists( 'ZDZ_Capabilities' ) && 'admin' === ZDZ_Capabilities::tier_of( $uid ) ) {
				return true;
			}
			if ( class_exists( 'Zjob_Project' ) && method_exists( 'Zjob_Project', 'participant_ids' ) ) {
				return in_array( $uid, Zjob_Project::participant_ids( $project_id ), true );
			}
			return false;
		}

		/**
		 * Confirm (or, with $user_id 0, clear) the person for one step. For an installer step a Territories
		 * decision may be named; it is then reported back with the part (0, or 1 for the second installer).
		 *
		 * @return array|WP_Error the project's confirmed people
		 */
		public static function set( string $project_id, string $role, int $user_id, int $actor, int $decision_id = 0 ) {
			global $wpdb;
			if ( ! self::ready() ) {
				return new WP_Error( 'zjob_people', __( 'The people on a job cannot be saved yet. Reload in a moment.', 'zorderz' ) );
			}
			if ( ! in_array( $role, self::ROLES, true ) ) {
				return new WP_Error( 'zjob_people', __( 'That is not a step on a job.', 'zorderz' ) );
			}
			if ( ! self::may_confirm( $actor, $project_id ) ) {
				return new WP_Error( 'zjob_people', __( 'You can confirm people only on jobs you are part of.', 'zorderz' ) );
			}
			if ( $user_id > 0 && ! self::eligible( $user_id, $role ) ) {
				return new WP_Error( 'zjob_people', __( 'That person cannot take this step.', 'zorderz' ) );
			}
			$cur = self::confirmed( $project_id );
			if ( isset( $cur[ $role ] ) && (int) $cur[ $role ]['user_id'] === $user_id ) {
				return $cur;
			}
			$now = current_time( 'mysql', true );
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET ended_at = %s, ended_by = %d WHERE ended_at IS NULL AND project_id = %s AND role = %s', $now, $actor, $project_id, $role ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name; values are placeholders.
			if ( $user_id > 0 ) {
				$wpdb->insert( self::table(), array( 'project_id' => $project_id, 'role' => $role, 'user_id' => $user_id, 'decision_id' => max( 0, $decision_id ), 'confirmed_by' => $actor, 'confirmed_at' => $now ) );
				// "A project step is yours" when someone else confirmed them (never for the seller step).
				if ( class_exists( 'ZJOB_Accept_Notice' ) ) {
					ZJOB_Accept_Notice::step_assigned( $project_id, $role, $user_id, $actor );
				}
				if ( $decision_id > 0 && in_array( $role, array( 'installer', 'installer2' ), true ) ) {
					try {
						do_action( 'zjob_installer_confirmed', $decision_id, $user_id, self::job_number( $project_id ), 'installer2' === $role ? 1 : 0 );
					} catch ( \Throwable $e ) {
						error_log( 'Zorderz Jobs: an installer-confirmed listener failed - ' . $e->getMessage() );
					}
				}
			}
			return self::confirmed( $project_id );
		}

		/** The project's display code when it is a short number Territories can keep, else ''. Never a title. */
		private static function job_number( string $project_id ): string {
			$p    = class_exists( 'Zjob_Project' ) ? Zjob_Project::get_raw( $project_id ) : null;
			$code = is_array( $p ) ? (string) ( $p['human_code'] ?? '' ) : '';
			return preg_match( '/^[A-Z#-]{0,4}\d{1,10}$/i', $code ) ? $code : '';
		}

		/**
		 * Ask Territories who should do this won job (territories.installer_for, a read, as the signed-in
		 * person). $lines are the job's lines ({name, desc} or an estimate's {description, sub_description});
		 * never the customer. Null when Territories is not here or gives no answer.
		 */
		public static function ask_installer( string $project_id, array $lines, string $zip = '', int $seller = 0, string $reference = '' ): ?array {
			if ( '' === $project_id || ! class_exists( 'ZDZ_Capabilities' ) || ! method_exists( 'ZDZ_Capabilities', 'get' ) ) {
				return null;
			}
			try {
				if ( ! ZDZ_Capabilities::get( 'territories.installer_for' ) ) {
					return null;
				}
				$a = ZDZ_Capabilities::invoke( 'territories.installer_for', array(
					'zip'            => $zip,
					'reference'      => $reference,
					'lines'          => array_slice( array_values( $lines ), 0, 60 ),
					'seller_user_id' => $seller,
					'key'            => 'project-' . $project_id,
					'pairs'          => true,
				) );
			} catch ( \Throwable $e ) {
				return null;
			}
			return ( is_array( $a ) && ! empty( $a['success'] ) && empty( $a['denied'] ) ) ? $a : null;
		}

		/** POST zjob_project_person { nonce, project_id, role, user_id, decision_id } -> the project's confirmed people. */
		public static function ajax_set(): void {
			check_ajax_referer( ZJOB_NONCE, 'nonce' );
			$actor = get_current_user_id();
			if ( $actor <= 0 || ( class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_kiosk( $actor ) ) ) {
				wp_send_json_error( array( 'message' => __( 'This action is not available on the shared device.', 'zorderz' ) ), 403 );
			}
			$pid  = isset( $_POST['project_id'] ) ? preg_replace( '/[^A-Za-z0-9_\-]/', '', sanitize_text_field( wp_unslash( $_POST['project_id'] ) ) ) : '';
			$role = isset( $_POST['role'] ) ? sanitize_key( wp_unslash( $_POST['role'] ) ) : '';
			$uid  = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
			$dec  = isset( $_POST['decision_id'] ) ? (int) $_POST['decision_id'] : 0;
			$r    = self::set( (string) $pid, $role, $uid, $actor, $dec );
			if ( is_wp_error( $r ) ) {
				wp_send_json_error( array( 'message' => $r->get_error_message() ), 400 );
			}
			wp_send_json_success( array( 'people' => $r ) );
		}
	}
}
