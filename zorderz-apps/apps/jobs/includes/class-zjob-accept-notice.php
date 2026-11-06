<?php
/**
 * ZJOB_Accept_Notice — the notices the jobs app sends through the platform's alert router (ZDZ_Alert_Router):
 *
 *   zjob_estimate_accepted  "Estimate #NNNN was accepted" to the salesperson, with a link to the project's next step.
 *   zjob_job_assigned       "A job is now yours" on the bell, beside the direct message (in-app only: the message
 *                           already pushes, so never twice).
 *   zjob_step_assigned      "A project step is yours" when someone else confirmed you to install, order the
 *                           materials for, or prepare a project.
 *
 * HOW AN ACCEPTANCE ARRIVES. The estimate app fires
 *     do_action( 'zest_estimate_accepted', array $row, string $source, array $detail )
 * once per estimate row ($source 'billing' | 'manual'; $detail may say who = customer, billing_state = accepted |
 * invoiced | manual, salesperson_uids, salesperson_initials, and duplicate / backlog / quiet to stay silent). This
 * listener runs at priority 30, after the project listener has minted or found the project.
 *
 * WHO IS TOLD, in order: the project's confirmed seller; else the people the estimate app names; else nobody — then the
 * fallback people (option zjob_accept_fallback = { codes: [initials…], admins: bool }; Core: no codes, administrators)
 * are told "No salesperson found on estimate #NNNN" as a review item. Never the shared device; never the person who
 * recorded a manual accept themselves (they know). Someone who cannot open the project is told without the link.
 *
 * WHAT IT SAYS: never "your customer accepted" unless the estimate app says who = customer. A test record says
 * "(test)". The bell carries the customer's name; the push never does (its body is "Tap to open the project.").
 *
 * ONCE PER ESTIMATE, by this app's own record (a small non-autoloaded option per estimate, written under a named lock).
 * It never writes to the customer, never sends e-mail itself (the router's channels decide), and never throws.
 *
 * @package Zorderz\Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZJOB_Accept_Notice {

	const TYPE         = 'zjob_estimate_accepted';
	const TYPE_JOB     = 'zjob_job_assigned';
	const TYPE_STEP    = 'zjob_step_assigned';
	const OPT_FALLBACK = 'zjob_accept_fallback';
	const MAX_PEOPLE   = 8;

	/** @var bool one "no router" line a request */
	private static $said_no_router = false;

	public static function init(): void {
		add_action( 'zdz_register_alert_types', array( __CLASS__, 'register_types' ) );
		add_action( 'zest_estimate_accepted', array( __CLASS__, 'on_accepted' ), 30, 3 );
	}

	/** The alert types this app sends. */
	public static function register_types( $router ): void {
		if ( ! is_object( $router ) || ! method_exists( $router, 'register_type' ) ) {
			return;
		}
		$router->register_type( self::TYPE, array(
			'label'            => __( 'Estimate accepted', 'zorderz' ),
			'description'      => __( 'An estimate you sold was accepted (or no salesperson was found on one).', 'zorderz' ),
			'default_channels' => array( 'in_app', 'push' ),
			'source_plugin'    => 'jobs',
			'category'         => 'sales',
		) );
		$router->register_type( self::TYPE_JOB, array(
			'label'            => __( 'A job is now yours', 'zorderz' ),
			'description'      => __( 'A job was scheduled for you or handed to you. (Messages also sends you a message.)', 'zorderz' ),
			'default_channels' => array( 'in_app' ),
			'source_plugin'    => 'jobs',
			'category'         => 'jobs',
		) );
		$router->register_type( self::TYPE_STEP, array(
			'label'            => __( 'A project step is yours', 'zorderz' ),
			'description'      => __( 'You were confirmed to install, order the materials for, or prepare a project.', 'zorderz' ),
			'default_channels' => array( 'in_app', 'push' ),
			'source_plugin'    => 'jobs',
			'category'         => 'jobs',
		) );
	}

	/* ------------------------------------------------------------------ the words (pure) */

	/** "#1042", or '' when the row has no number. */
	public static function number( array $row ): string {
		$n = ltrim( trim( (string) ( $row['doc_number'] ?? ( $row['billing_doc_num'] ?? '' ) ) ), '#' );
		if ( '' === $n ) {
			$n = ltrim( trim( (string) ( $row['billing_doc_num'] ?? '' ) ), '#' );
		}
		return '' !== $n ? '#' . $n : '';
	}

	/** First name of a person, for "marked accepted by Sam". */
	public static function first_name_of( int $uid ): string {
		if ( $uid <= 0 ) {
			return '';
		}
		$f = function_exists( 'get_user_meta' ) ? trim( (string) get_user_meta( $uid, 'first_name', true ) ) : '';
		if ( '' !== $f ) {
			return $f;
		}
		$u = function_exists( 'get_userdata' ) ? get_userdata( $uid ) : null;
		$d = $u ? trim( (string) ( $u->display_name ?? '' ) ) : '';
		if ( '' === $d ) {
			return '';
		}
		$w = preg_split( '/\s+/', $d );
		return (string) $w[0];
	}

	/** Is this a made-up record? The whole word test, fake or dummy (a business adds its own marks by filter). */
	public static function looks_like_test( string $name ): bool {
		$hit = (bool) preg_match( '/\b(?:test|fake|dummy)\b/i', $name );
		return function_exists( 'apply_filters' ) ? (bool) apply_filters( 'zjob_looks_like_test', $hit, $name ) : $hit;
	}

	/**
	 * The words, from $detail. PURE but for the accepter's first name.
	 *
	 * @return array{title:string,message:string,history:string,kind:string}
	 */
	public static function words( array $row, string $source, array $detail, bool $nobody = false ): array {
		$num   = self::number( $row );
		/* translators: %s: an estimate number such as #1042 */
		$est   = '' !== $num ? sprintf( __( 'Estimate %s', 'zorderz' ), $num ) : __( 'An estimate', 'zorderz' );
		/* translators: %s: an estimate number such as #1042 */
		$est_l = '' !== $num ? sprintf( __( 'estimate %s', 'zorderz' ), $num ) : __( 'an estimate', 'zorderz' );
		$has   = array() !== $detail;
		$who   = (string) ( $detail['who'] ?? '' );
		$state = (string) ( $detail['billing_state'] ?? '' );
		if ( '' === $state && 'manual' === $source ) {
			$state = 'manual'; // a manual accept is still a manual accept without the detail
		}
		if ( 'customer' === $who ) {
			$kind  = 'customer';
			/* translators: %s: "estimate #1042" */
			$title = sprintf( __( 'Your customer accepted %s', 'zorderz' ), $est_l );
			/* translators: %s: "estimate #1042" */
			$hist  = sprintf( __( 'The customer accepted %s.', 'zorderz' ), $est_l );
		} elseif ( 'manual' === $state ) {
			$by    = self::first_name_of( (int) ( $row['accepted_by'] ?? 0 ) );
			$kind  = 'manual';
			$title = '' !== $by
				/* translators: 1: "Estimate #1042", 2: a first name */
				? sprintf( __( '%1$s was marked accepted by %2$s', 'zorderz' ), $est, $by )
				/* translators: %s: "Estimate #1042" */
				: sprintf( __( '%s was marked accepted by hand', 'zorderz' ), $est );
			$hist  = $title . '.';
		} elseif ( 'invoiced' === $state ) {
			$kind  = 'invoiced';
			/* translators: %s: "Estimate #1042" */
			$title = sprintf( __( '%s was turned into an invoice', 'zorderz' ), $est );
			$hist  = $title . '.';
		} elseif ( $has ) {
			$kind  = 'accepted';
			/* translators: %s: "Estimate #1042" */
			$title = sprintf( __( '%s was accepted in the billing system', 'zorderz' ), $est );
			$hist  = $title . '.';
		} else {
			$kind  = 'unknown';
			/* translators: %s: "Estimate #1042" */
			$title = sprintf( __( '%s was accepted', 'zorderz' ), $est );
			$hist  = $title . '.';
		}
		$name = trim( (string) preg_replace( '/\s+/', ' ', (string) ( $row['customer_name'] ?? '' ) ) );
		if ( $nobody ) {
			$message = ( '' !== $name ? $name . '. ' : '' ) . $title . '. ' . __( 'Open the project to choose its salesperson.', 'zorderz' );
			/* translators: %s: "estimate #1042" */
			$title   = sprintf( __( 'No salesperson found on %s', 'zorderz' ), $est_l );
		} else {
			$message = ( '' !== $name ? $name . '. ' : '' ) . __( 'Open the project to email or call them.', 'zorderz' );
		}
		if ( self::looks_like_test( $name ) ) {
			$title .= ' ' . __( '(test)', 'zorderz' );
		}
		return array( 'title' => $title, 'message' => $message, 'history' => $hist, 'kind' => $kind );
	}

	/* ------------------------------------------------------------------ who */

	private static function is_kiosk( int $uid ): bool {
		if ( class_exists( 'ZDZ_Hierarchy' ) && method_exists( 'ZDZ_Hierarchy', 'is_kiosk' ) && ZDZ_Hierarchy::is_kiosk( $uid ) ) {
			return true;
		}
		return class_exists( 'ZDZ_User_Roles' ) && method_exists( 'ZDZ_User_Roles', 'is_shared_device' ) && ZDZ_User_Roles::is_shared_device( $uid );
	}

	/**
	 * The fallback people for "no salesperson found": holders of the configured initials and (by default) the
	 * administrators — a review item for someone who can choose the salesperson. Core configures no initials.
	 *
	 * @return int[]
	 */
	public static function fallback_people(): array {
		$cfg    = function_exists( 'get_option' ) ? get_option( self::OPT_FALLBACK, array() ) : array();
		$cfg    = is_array( $cfg ) ? $cfg : array();
		$codes  = isset( $cfg['codes'] ) && is_array( $cfg['codes'] ) ? $cfg['codes'] : array();
		$admins = array_key_exists( 'admins', $cfg ) ? (bool) $cfg['admins'] : true;
		$codes  = array_values( array_filter( array_map( static function ( $c ) {
			return strtoupper( (string) preg_replace( '/[^A-Za-z]/', '', (string) $c ) );
		}, $codes ) ) );
		$out = array();
		if ( $codes && function_exists( 'get_users' ) && class_exists( 'ZJOB_Scope' ) ) {
			foreach ( (array) get_users( array( 'fields' => 'ID', 'number' => 500 ) ) as $id ) {
				$id = (int) ( is_object( $id ) ? ( $id->ID ?? 0 ) : $id );
				if ( $id > 0 && array_intersect( ZJOB_Scope::sp_codes_for( $id ), $codes ) ) {
					$out[] = $id;
				}
			}
		}
		if ( $admins && function_exists( 'get_users' ) ) {
			foreach ( (array) get_users( array( 'role__in' => array( 'administrator', 'zdz_owner', 'zdz_admin' ), 'fields' => 'ID', 'number' => 50 ) ) as $id ) {
				$out[] = (int) ( is_object( $id ) ? ( $id->ID ?? 0 ) : $id );
			}
		}
		$out = function_exists( 'apply_filters' ) ? (array) apply_filters( 'zjob_accept_fallback_people', array_values( array_unique( array_filter( array_map( 'intval', $out ) ) ) ) ) : $out;
		return array_slice( array_values( array_unique( array_filter( array_map( 'intval', $out ) ) ) ), 0, self::MAX_PEOPLE );
	}

	/**
	 * Who is told, and whether it is the "no salesperson" notice.
	 *
	 * @return array{people:int[], nobody:bool, from:string}
	 */
	public static function recipients( string $project_id, array $row, string $source, array $detail, bool $force_fallback = false ): array {
		// A person who can't be told (the shared device, a deleted account) is not a salesperson: try the next source.
		$usable = static function ( array $ids ): array {
			return array_values( array_unique( array_filter( array_map( 'intval', $ids ), static function ( $u ) {
				return $u > 0 && ! self::is_kiosk( $u ) && function_exists( 'get_userdata' ) && false !== get_userdata( $u );
			} ) ) );
		};
		$people = array();
		$from   = '';
		$nobody = false;
		if ( ! $force_fallback ) {
			if ( '' !== $project_id && class_exists( 'ZJOB_Project_People' ) ) {
				$c      = ZJOB_Project_People::confirmed( $project_id );
				$people = $usable( array( (int) ( $c['seller']['user_id'] ?? 0 ) ) );
				$from   = 'seller';
			}
			if ( ! $people && ! empty( $detail['salesperson_uids'] ) && is_array( $detail['salesperson_uids'] ) ) {
				$people = $usable( $detail['salesperson_uids'] );
				$from   = 'estimate';
			}
		}
		if ( ! $people ) {
			$people = $usable( self::fallback_people() );
			$nobody = true;
			$from   = 'fallback';
		}
		// The person who recorded a manual accept knows already.
		$manual = 'manual' === ( $detail['billing_state'] ?? ( 'manual' === $source ? 'manual' : '' ) );
		$by     = $manual ? (int) ( $row['accepted_by'] ?? 0 ) : 0;
		$people = array_values( array_filter( $people, static function ( $u ) use ( $by ) {
			return $u !== $by;
		} ) );
		return array( 'people' => array_slice( $people, 0, self::MAX_PEOPLE ), 'nobody' => $nobody, 'from' => $from );
	}

	/** Can this person open the project from the link? Its own visibility rule, and the Projects app. */
	public static function can_open( int $uid, ?array $project ): bool {
		if ( ! $project || ! class_exists( 'Zjob_Project_Visibility' ) ) {
			return false;
		}
		if ( ! Zjob_Project_Visibility::may_view_project( $uid, $project ) ) {
			return false;
		}
		if ( class_exists( 'ZDZ_Plugin_API' ) && method_exists( 'ZDZ_Plugin_API', 'user_can_access_app' ) && class_exists( 'ZJOB_Projects_App' ) ) {
			return (bool) ZDZ_Plugin_API::user_can_access_app( $uid, ZJOB_Projects_App::APP_ID );
		}
		return true;
	}

	/** The project's link: the site with ?zprj=<id> (and &at=next for the next step). */
	public static function link( string $project_id, string $at = 'next' ): string {
		$args = array( 'zprj' => $project_id );
		if ( '' !== $at ) {
			$args['at'] = $at;
		}
		return add_query_arg( $args, home_url( '/' ) );
	}

	/* ------------------------------------------------------------------ the salesperson code */

	/**
	 * When the estimate app names exactly one salesperson code, the project knows its seller's code from the owning
	 * app (a fact, never a guess): returned for the caller to keep. '' otherwise.
	 */
	public static function adopt_code( array $detail ): string {
		$codes = isset( $detail['salesperson_initials'] ) && is_array( $detail['salesperson_initials'] ) ? array_values( array_unique( $detail['salesperson_initials'] ) ) : array();
		if ( 1 !== count( $codes ) ) {
			return '';
		}
		$code = strtoupper( (string) $codes[0] );
		return preg_match( '/^[A-Z]{2,4}$/', $code ) ? $code : '';
	}

	/* ------------------------------------------------------------------ once */

	private static function opt_for( int $estimate_id ): string {
		return 'zjob_accn_' . $estimate_id;
	}

	public static function already_told( int $estimate_id ): bool {
		return function_exists( 'get_option' ) && false !== get_option( self::opt_for( $estimate_id ), false );
	}

	public static function mark_told( int $estimate_id ): void {
		if ( function_exists( 'add_option' ) ) {
			add_option( self::opt_for( $estimate_id ), gmdate( 'Y-m-d H:i:s' ), '', 'no' );
		}
	}

	/* ------------------------------------------------------------------ the listener */

	/**
	 * zest_estimate_accepted, priority 30, 3 arguments. Never throws.
	 *
	 * @param array  $row    the estimate row (id, doc_number, customer_name, accepted_by …)
	 * @param string $source billing | manual
	 * @param array  $detail see the class docblock
	 */
	public static function on_accepted( $row, $source = '', $detail = array() ): void {
		$lock = '';
		try {
			if ( ! is_array( $row ) ) {
				return;
			}
			$detail = is_array( $detail ) ? $detail : array();
			if ( ! empty( $detail['duplicate'] ) || ! empty( $detail['backlog'] ) || ! empty( $detail['quiet'] ) ) {
				return; // another row was first, or old news: never a notice
			}
			$estimate_id = (int) ( $row['id'] ?? 0 );
			if ( $estimate_id <= 0 ) {
				return;
			}
			if ( ! class_exists( 'ZDZ_Alert_Router' ) || ! method_exists( 'ZDZ_Alert_Router', 'send' ) ) {
				if ( ! self::$said_no_router ) {
					self::$said_no_router = true;
					error_log( 'Zorderz Jobs: no alert router, so no estimate-accepted notice was sent.' );
				}
				return;
			}
			$pid = class_exists( 'Zjob_Project' ) ? (string) Zjob_Project::ensure_for_estimate( $estimate_id ) : '';
			global $wpdb;
			$lock = substr( 'zjob_accn_' . md5( $wpdb->prefix . '|' . $estimate_id ), 0, 60 );
			if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
				$lock = '';
				return; // another request is telling these people right now
			}
			if ( self::already_told( $estimate_id ) ) {
				return;
			}
			$project = '' !== $pid ? Zjob_Project::get( $pid ) : null;
			$send_to = static function ( array $who ) use ( $row, $source, $detail, $pid, $project, $estimate_id ): array {
				$w        = self::words( $row, (string) $source, $detail, $who['nobody'] );
				$sent     = 0;
				$linkless = 0;
				foreach ( $who['people'] as $uid ) {
					$open = '' !== $pid && self::can_open( (int) $uid, $project );
					$msg  = $w['message'];
					if ( ! $open ) {
						$msg = (string) preg_replace( '/' . preg_quote( __( 'Open the project to', 'zorderz' ), '/' ) . '.*$/', '' !== $pid
							? __( 'Projects is not on your account yet, so ask an administrator to open it for you.', 'zorderz' )
							: __( 'Open the estimate in Estimates to see it.', 'zorderz' ), $msg );
					}
					$used = (array) ZDZ_Alert_Router::send( self::TYPE, (int) $uid, $w['title'], $msg, array(
						'plugin'     => 'jobs',
						'source_id'  => $estimate_id,
						'project_id' => $pid,
						'url'        => $open ? self::link( $pid ) : home_url( '/' ),
						'push_body'  => __( 'Tap to open the project.', 'zorderz' ),
						'sender_id'  => 0,
					) );
					if ( $used ) {
						$sent++;
					}
					if ( ! $open ) {
						$linkless++;
					}
				}
				return array( $sent, $linkless, $w['kind'] );
			};

			$who = self::recipients( $pid, $row, (string) $source, $detail );
			list( $sent, $linkless, $kind ) = $send_to( $who );
			// A salesperson whose channels delivered to nobody is not "told": the fallback people hear it instead, as a
			// review item, so the acceptance is never lost.
			if ( 0 === $sent && $who['people'] && ! $who['nobody'] ) {
				$who2                = self::recipients( $pid, $row, (string) $source, $detail, true );
				list( $sent, $l2 )   = $send_to( $who2 );
				$linkless           += $l2;
				$who['from']        .= '>fallback';
			}
			if ( $sent > 0 ) {
				self::mark_told( $estimate_id );
			}
			// Counts and words only: never a name.
			error_log( sprintf( 'Zorderz Jobs: estimate row %d accepted (%s) - %d told (%s), %d delivered, %d without the link.',
				$estimate_id, $kind, count( $who['people'] ), $who['from'], $sent, $linkless ) );
		} catch ( \Throwable $e ) {
			error_log( 'Zorderz Jobs: estimate-accepted notice failed (' . get_class( $e ) . ').' );
		} finally {
			if ( '' !== $lock ) {
				global $wpdb;
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
			}
		}
	}

	/* ------------------------------------------------------------------ bell rows for "yours" */

	/** "A job is now yours" on the bell, beside the direct message. */
	public static function job_assigned( array $job, int $actor ): void {
		try {
			$to = (int) ( $job['assigned_user_id'] ?? 0 );
			if ( $to <= 0 || $to === $actor || self::is_kiosk( $to ) || ! class_exists( 'ZDZ_Alert_Router' ) ) {
				return;
			}
			$name = trim( (string) ( $job['customer_name'] ?? '' ) );
			ZDZ_Alert_Router::send( self::TYPE_JOB, $to, __( 'A job is now yours', 'zorderz' ),
				( '' !== $name ? $name . '. ' : '' ) . __( 'Open Jobs for the address and details.', 'zorderz' ),
				array(
					'plugin'    => 'jobs',
					'source_id' => (int) ( $job['id'] ?? 0 ),
					'url'       => home_url( '/' ),
					'push_body' => __( 'Tap to open it.', 'zorderz' ),
					'sender_id' => $actor,
				) );
		} catch ( \Throwable $e ) {
			error_log( 'Zorderz Jobs: job-assigned bell row failed (' . get_class( $e ) . ').' );
		}
	}

	/** "A project step is yours" (installer, second installer, orderer, preparer confirmed by someone else). */
	public static function step_assigned( string $project_id, string $role, int $to, int $actor ): void {
		try {
			if ( '' === $project_id || $to <= 0 || $to === $actor || 'seller' === $role || self::is_kiosk( $to ) || ! class_exists( 'ZDZ_Alert_Router' ) ) {
				return;
			}
			$labels = array(
				'installer'  => __( 'install', 'zorderz' ),
				'installer2' => __( 'install the rest of the work on', 'zorderz' ),
				'orderer'    => __( 'order the materials for', 'zorderz' ),
				'preparer'   => __( 'prepare', 'zorderz' ),
			);
			if ( ! isset( $labels[ $role ] ) ) {
				return;
			}
			$p    = class_exists( 'Zjob_Project' ) ? Zjob_Project::get( $project_id ) : null;
			$name = is_array( $p ) ? trim( (string) ( $p['customer']['name'] ?? '' ) ) : '';
			$name = html_entity_decode( $name, ENT_QUOTES, 'UTF-8' );
			$code = is_array( $p ) ? trim( (string) ( $p['human_code'] ?? '' ) ) : '';
			ZDZ_Alert_Router::send( self::TYPE_STEP, $to,
				__( 'A project step is yours', 'zorderz' ) . ( '' !== $code ? ' (' . $code . ')' : '' ),
				/* translators: 1: "install" / "order the materials for" / …, 2: "Jordan's project" or "this project" */
				sprintf( __( 'You were confirmed to %1$s %2$s.', 'zorderz' ), $labels[ $role ],
					/* translators: %s: a customer name */
					'' !== $name ? sprintf( __( '%s\'s project', 'zorderz' ), $name ) : __( 'this project', 'zorderz' ) ),
				array(
					'plugin'     => 'jobs',
					'source_id'  => 0,
					'project_id' => $project_id,
					'url'        => self::link( $project_id ),
					'push_body'  => __( 'Tap to open the project.', 'zorderz' ),
					'sender_id'  => $actor,
				) );
		} catch ( \Throwable $e ) {
			error_log( 'Zorderz Jobs: step-assigned bell row failed (' . get_class( $e ) . ').' );
		}
	}
}
