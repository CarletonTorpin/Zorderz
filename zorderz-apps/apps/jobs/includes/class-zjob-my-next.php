<?php
/**
 * ZJOB_My_Next — "My next": the steps that are this person's to do now, across every sold Project.
 *
 * A step is never stored. Each time it is asked for it is worked out from what the Projects already hold:
 *
 *   step       open when (a sold Project: one with at least one job that is not cancelled)          whose
 *   ---------  ------------------------------------------------------------------------------------  ------------------------------
 *   order      not installed, and an order on it is not placed yet — or none exists and the job       the confirmed orderer, else the
 *              kinds are ones the business says need materials ordered (step rules)                   order owners
 *   cut        not installed, the visit not passed, and the shop's prep ledger (Prep) knows the job   the confirmed preparer, else
 *              and it is not complete — or the job kinds are ones the business says are prepared      the cut owners
 *   schedule   not installed, no install visit on the calendar                                       the seller (confirmed, else who
 *                                                                                                    made it, else a code holder);
 *                                                                                                    the office when there is none
 *   install    not installed, a visit on the calendar (or one passed, not installed)                  who is on the visit, and the
 *                                                                                                    confirmed installers
 *   invoice    installed and nothing invoiced (only when a billing connector answers                  the office
 *   payment    zjob_project_money for the Project; with no answer there are no money steps)
 *   walk       a door-hanger route assigned to this person (the zdh_routes_list filter), not finished the person it is assigned to
 *
 * WHO OWNS A STEP is a setting (option zjob_step_owners {cut, order, office}, filter of the same name): cut defaults to
 * the holders of the production role (zdz_mfg), order to the people named to check and order materials, office to the
 * order owners. A confirmed person (Projects' People) takes the step while they still can.
 *
 * WHICH KINDS OF WORK need an order or shop prep before anything exists to say so is the business's (option
 * zjob_step_rules {order: {kinds: []}, cut: {kinds: []}}, filter of the same name; Identity Pack flows.yml steps.*).
 * Core names none: without them a step opens only on a fact (an order not placed, a prep ledger not complete).
 *
 * "Done lately" is what this person did in the last PAST_DAYS days that closed a step: an order marked ordered, a job
 * they finished, a prep they marked cut. Nothing is claimed for anyone that the records do not say.
 *
 * Every Project shown passes the Projects visibility rule for this person; money steps only for those who may see
 * money. Never the shared device.
 *
 * @package Zorderz\Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZJOB_My_Next {

	const OWNERS_OPTION = 'zjob_step_owners';
	const RULES_OPTION  = 'zjob_step_rules';
	const MAX_PROJECTS  = 400;
	const VISIT_DAYS    = 120;   // a visit older than this is history, not a step
	const PAST_DAYS     = 14;
	const FINISHED_DAYS = 180;   // an installed Project keeps its money steps this long
	const LIVE          = array( 'open', 'in_progress', 'pending_close' );

	/** The order steps are listed in. */
	const RANK = array( 'install' => 0, 'cut' => 1, 'order' => 2, 'schedule' => 3, 'walk' => 4, 'invoice' => 5, 'payment' => 6 );

	/** Injected in tests: "now" as a unix time. */
	public static $now = null;

	public static function init(): void {
		add_action( 'wp_ajax_zjob_my_next', array( __CLASS__, 'ajax' ) );
		// No _nopriv variant.
	}

	private static function now(): int {
		return null !== self::$now ? (int) self::$now : time();
	}

	/* ── Who owns which step, and which kinds of work open one ─────────────────────────────────────────── */

	/** @return array{cut:int[],order:int[],office:int[]} */
	public static function owners(): array {
		$o   = function_exists( 'get_option' ) ? get_option( self::OWNERS_OPTION, array() ) : array();
		$o   = is_array( $o ) ? $o : array();
		$ids = static function ( $v ): array {
			return array_values( array_unique( array_filter( array_map( 'intval', (array) $v ), static function ( $i ) {
				return $i > 0;
			} ) ) );
		};
		$order = isset( $o['order'] ) ? $ids( $o['order'] )
			: ( class_exists( 'ZJOB_Project_Orders' ) ? ZJOB_Project_Orders::operators() : array() );
		$cut = isset( $o['cut'] ) ? $ids( $o['cut'] ) : self::production_people();
		if ( ! $cut && ! isset( $o['cut'] ) ) {
			$cut = $order; // nobody holds the production role: the order owners rather than nobody
		}
		$office = isset( $o['office'] ) ? $ids( $o['office'] ) : $order;
		$out    = function_exists( 'apply_filters' )
			? apply_filters( self::OWNERS_OPTION, array( 'cut' => $cut, 'order' => $order, 'office' => $office ) )
			: array( 'cut' => $cut, 'order' => $order, 'office' => $office );
		$out = is_array( $out ) ? $out : array();
		$out = array(
			'cut'    => $ids( $out['cut'] ?? array() ),
			'order'  => $ids( $out['order'] ?? array() ),
			'office' => $ids( $out['office'] ?? array() ),
		);
		// The shared device never owns a step.
		foreach ( $out as $k => $list ) {
			$out[ $k ] = array_values( array_filter( $list, array( __CLASS__, 'is_person' ) ) );
		}
		return $out;
	}

	/** A person (not the shared device). */
	public static function is_person( int $uid ): bool {
		if ( $uid <= 0 ) {
			return false;
		}
		if ( class_exists( 'ZDZ_Hierarchy' ) && method_exists( 'ZDZ_Hierarchy', 'is_kiosk' ) && ZDZ_Hierarchy::is_kiosk( $uid ) ) {
			return false;
		}
		return ! ( class_exists( 'ZDZ_User_Roles' ) && method_exists( 'ZDZ_User_Roles', 'is_shared_device' ) && ZDZ_User_Roles::is_shared_device( $uid ) );
	}

	/** The holders of the production role (the default for shop prep). */
	private static function production_people(): array {
		if ( ! function_exists( 'get_users' ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) get_users( array( 'role__in' => array( 'zdz_mfg' ), 'fields' => 'ID', 'number' => 20 ) ) as $u ) {
			$out[] = (int) ( is_object( $u ) ? $u->ID : $u );
		}
		return $out;
	}

	/**
	 * The kinds of work (job kinds, zdz_job_components keys) that open an order or a prep step before anything exists to
	 * say so. Core: none. PURE apart from the option and filter.
	 *
	 * @return array{order:string[],cut:string[]}
	 */
	public static function step_rules(): array {
		$raw = function_exists( 'get_option' ) ? get_option( self::RULES_OPTION, array() ) : array();
		$raw = is_array( $raw ) ? $raw : array();
		if ( function_exists( 'apply_filters' ) ) {
			$f   = apply_filters( self::RULES_OPTION, $raw );
			$raw = is_array( $f ) ? $f : $raw;
		}
		$kinds = static function ( $v ): array {
			$out = array();
			foreach ( (array) $v as $k ) {
				$k = strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', (string) $k ) );
				if ( '' !== $k ) {
					$out[] = $k;
				}
			}
			return array_values( array_unique( $out ) );
		};
		return array(
			'order' => $kinds( $raw['order']['kinds'] ?? array() ),
			'cut'   => $kinds( $raw['cut']['kinds'] ?? array() ),
		);
	}

	/* ── The rule (pure: no database, no WordPress) ─────────────────────────────────────────────────────── */

	/**
	 * The open steps on one Project, and whose each is.
	 *
	 * @param array $p {
	 *   id, kinds string[], installed bool, seller_ids int[], seller_codes string[], orders {n, ordered},
	 *   cut ('complete'|'partial'|'uncut'|'unknown'), visit {start_utc, people int[]}|null, visit_past bool,
	 *   money {invoiced bool, due float|null}|null (null: no billing answer, so no money steps),
	 *   confirmed {seller?, installer?, installer2?, orderer?, preparer?: int}
	 * }
	 * @param array $owners owners() shape
	 * @param array $rules  step_rules() shape
	 * @return array<int,array{step:string,owners:int[],why:string}>
	 */
	public static function steps( array $p, array $owners, array $rules = array( 'order' => array(), 'cut' => array() ) ): array {
		$out   = array();
		$kinds = array_map( 'strval', (array) ( $p['kinds'] ?? array() ) );
		$inst  = ! empty( $p['installed'] );
		$visit = is_array( $p['visit'] ?? null ) ? $p['visit'] : null;
		$conf  = array_map( 'intval', array_filter( (array) ( $p['confirmed'] ?? array() ) ) );

		if ( ! $inst ) {
			$n   = (int) ( $p['orders']['n'] ?? 0 );
			$ord = (int) ( $p['orders']['ordered'] ?? 0 );
			$by_kind = (bool) array_intersect( $kinds, (array) ( $rules['order'] ?? array() ) );
			if ( ( $n > 0 && $ord < $n ) || ( 0 === $n && $by_kind ) ) {
				$out[] = array(
					'step'   => 'order',
					'owners' => ! empty( $conf['orderer'] ) ? array( $conf['orderer'] ) : (array) $owners['order'],
					'why'    => 0 === $n ? __( 'No material order yet.', 'zorderz' )
						/* translators: 1: orders not placed, 2: all orders. */
						: sprintf( __( '%1$d of %2$d orders not placed yet.', 'zorderz' ), $n - $ord, $n ),
				);
			}
		}

		if ( ! $inst && empty( $p['visit_past'] ) ) {
			$cut     = (string) ( $p['cut'] ?? 'unknown' );
			$by_kind = (bool) array_intersect( $kinds, (array) ( $rules['cut'] ?? array() ) );
			if ( in_array( $cut, array( 'uncut', 'partial' ), true ) || ( 'unknown' === $cut && $by_kind ) ) {
				$out[] = array(
					'step'   => 'cut',
					'owners' => ! empty( $conf['preparer'] ) ? array( $conf['preparer'] ) : (array) $owners['cut'],
					'why'    => 'partial' === $cut ? __( 'Part done in the shop.', 'zorderz' ) : __( 'Not prepared in the shop yet.', 'zorderz' ),
				);
			}
		}

		if ( ! $inst && ! $visit ) {
			$sellers = array_values( array_filter( array_map( 'intval', (array) ( $p['seller_ids'] ?? array() ) ) ) );
			$codes   = array_values( array_map( 'strval', (array) ( $p['seller_codes'] ?? array() ) ) );
			if ( ! empty( $conf['seller'] ) ) {
				$sellers = array( $conf['seller'] ); // the confirmed salesperson, not whoever holds the code
				$codes   = array();
			}
			$has_seller = $sellers || $codes;
			$out[]      = array(
				'step'   => 'schedule',
				'owners' => $has_seller ? $sellers : (array) $owners['office'],
				'codes'  => $has_seller ? $codes : array(),
				'why'    => __( 'No install date on the calendar.', 'zorderz' ),
			);
		}

		if ( ! $inst && $visit ) {
			$people = array_map( 'intval', (array) ( $visit['people'] ?? array() ) );
			foreach ( array( 'installer', 'installer2' ) as $r ) {
				if ( ! empty( $conf[ $r ] ) ) {
					$people[] = $conf[ $r ];
				}
			}
			$out[] = array(
				'step'   => 'install',
				'owners' => array_values( array_unique( array_filter( $people ) ) ),
				'when'   => (string) ( $visit['start_utc'] ?? '' ),
				'why'    => ! empty( $p['visit_past'] ) ? __( 'The visit has passed; not finished.', 'zorderz' ) : __( 'Install visit booked.', 'zorderz' ),
			);
		}

		if ( $inst && is_array( $p['money'] ?? null ) ) {
			if ( empty( $p['money']['invoiced'] ) ) {
				$out[] = array( 'step' => 'invoice', 'owners' => (array) $owners['office'], 'why' => __( 'Finished; no invoice sent yet.', 'zorderz' ), 'money' => true );
			} elseif ( null !== ( $p['money']['due'] ?? null ) && (float) $p['money']['due'] > 0.004 ) {
				$out[] = array( 'step' => 'payment', 'owners' => (array) $owners['office'], 'why' => __( 'Finished; payment still due.', 'zorderz' ), 'money' => true );
			}
		}
		return $out;
	}

	/** Is this step this person's? A schedule step also matches the person's own salesperson codes. PURE. */
	public static function is_mine( array $step, int $uid, array $my_codes ): bool {
		if ( $uid <= 0 ) {
			return false;
		}
		if ( in_array( $uid, array_map( 'intval', (array) ( $step['owners'] ?? array() ) ), true ) ) {
			return true;
		}
		if ( 'schedule' === ( $step['step'] ?? '' ) && $my_codes ) {
			return (bool) array_intersect( array_map( 'strtoupper', (array) ( $step['codes'] ?? array() ) ), array_map( 'strtoupper', $my_codes ) );
		}
		return false;
	}

	/** The words of each step. */
	public static function labels(): array {
		return array(
			'order'    => __( 'Order materials', 'zorderz' ),
			'cut'      => __( 'Prepare in the shop', 'zorderz' ),
			'schedule' => __( 'Schedule the install', 'zorderz' ),
			'install'  => __( 'Install', 'zorderz' ),
			'invoice'  => __( 'Send the invoice', 'zorderz' ),
			'payment'  => __( 'Follow up the payment', 'zorderz' ),
			'walk'     => __( 'Walk the door-hanger route', 'zorderz' ),
		);
	}

	/** One item as a person reads it, and where Start goes. PURE. */
	public static function present_item( string $step, array $p, array $s ): array {
		$labels = self::labels();
		$start  = array( 'app' => 'projects', 'options' => array( 'project_id' => (string) $p['id'] ), 'label' => __( 'Open the project', 'zorderz' ) );
		if ( 'cut' === $step ) {
			$opts = array();
			if ( ! empty( $p['cut_lead'] ) ) {
				$opts['lead_id'] = (int) $p['cut_lead'];
			}
			if ( ! empty( $p['estimate_number'] ) ) {
				$opts['estimate_number'] = (string) $p['estimate_number'];
			}
			if ( $opts ) {
				$start = array( 'app' => defined( 'ZPREP_APP_ID' ) ? ZPREP_APP_ID : 'prep', 'options' => $opts, 'label' => __( 'Start in Prep', 'zorderz' ) );
			}
		}
		$item = array(
			'key'     => $step . ':' . (string) $p['id'],
			'step'    => $step,
			'label'   => $labels[ $step ] ?? $step,
			'why'     => (string) ( $s['why'] ?? '' ),
			'when'    => (string) ( $s['when'] ?? '' ),
			'project' => array(
				'id'       => (string) $p['id'],
				'code'     => (string) ( $p['code'] ?? '' ),
				'customer' => (string) ( $p['customer'] ?? '' ),
				'city'     => (string) ( $p['city'] ?? '' ),
			),
			'cut_by'  => (string) ( $p['cut_by_name'] ?? '' ),
			'start'   => $start,
		);
		if ( ! empty( $s['money'] ) ) {
			$item['money'] = true;
		}
		return $item;
	}

	/**
	 * The order a person reads: a visit by its date, then shop prep, orders, scheduling, walks, money; within a kind the
	 * newest Project first (by its id, which sorts by time). PURE.
	 */
	public static function sort_present( array $items ): array {
		usort( $items, static function ( $a, $b ) {
			$ra = self::RANK[ $a['step'] ?? '' ] ?? 9;
			$rb = self::RANK[ $b['step'] ?? '' ] ?? 9;
			if ( $ra !== $rb ) {
				return $ra <=> $rb;
			}
			if ( in_array( $a['step'], array( 'install', 'walk' ), true ) && (string) $a['when'] !== (string) $b['when'] ) {
				// No day sorts after every day.
				if ( '' === (string) $a['when'] ) {
					return 1;
				}
				if ( '' === (string) $b['when'] ) {
					return -1;
				}
				return strcmp( (string) $a['when'], (string) $b['when'] );
			}
			return strcmp( (string) ( $b['project']['id'] ?? '' ), (string) ( $a['project']['id'] ?? '' ) );
		} );
		return $items;
	}

	/**
	 * The install visit among a Project's candidate times: the next one coming, else the most recent one that has
	 * passed (within VISIT_DAYS), marked past. A past visit never hides an upcoming one. PURE.
	 *
	 * @param array  $cands each { start_utc 'Y-m-d H:i:s', people int[], appt int }
	 * @param string $now   UTC 'Y-m-d H:i:s'
	 * @param string $floor UTC 'Y-m-d H:i:s' (older candidates are ignored)
	 */
	public static function pick_visit( array $cands, string $now, string $floor ): ?array {
		$list = array_values( array_filter( $cands, static function ( $c ) use ( $floor ) {
			$s = (string) ( $c['start_utc'] ?? '' );
			return '' !== $s && 0 !== strpos( $s, '0000-00-00' ) && $s >= $floor;
		} ) );
		if ( ! $list ) {
			return null;
		}
		$future = array_values( array_filter( $list, static function ( $c ) use ( $now ) {
			return (string) $c['start_utc'] >= $now;
		} ) );
		if ( $future ) {
			usort( $future, static function ( $a, $b ) {
				return strcmp( (string) $a['start_utc'], (string) $b['start_utc'] );
			} );
			$v = $future[0] + array( 'past' => false );
		} else {
			usort( $list, static function ( $a, $b ) {
				return strcmp( (string) $b['start_utc'], (string) $a['start_utc'] );
			} );
			$v = $list[0] + array( 'past' => true );
		}
		// Everyone on any candidate at the chosen time shares the visit.
		$people = array();
		foreach ( $list as $c ) {
			if ( (string) $c['start_utc'] === (string) $v['start_utc'] ) {
				$people = array_merge( $people, array_map( 'intval', (array) ( $c['people'] ?? array() ) ) );
			}
		}
		$v['people'] = array_values( array_unique( array_filter( $people ) ) );
		return $v;
	}

	/**
	 * Is a Project's work finished? Its state is completed, or every job that is not cancelled is done (at least one).
	 * PURE.
	 */
	public static function is_installed( string $state, array $jobs ): bool {
		if ( 'completed' === $state ) {
			return true;
		}
		$done = 0;
		foreach ( $jobs as $j ) {
			$st = (string) ( $j['status'] ?? '' );
			if ( in_array( $st, self::LIVE, true ) ) {
				return false;
			}
			if ( 'done' === $st ) {
				$done++;
			}
		}
		return $done > 0;
	}

	/** Is it sold (at least one job that is not cancelled)? A quote alone has no steps. PURE. */
	public static function is_sold( array $jobs ): bool {
		foreach ( $jobs as $j ) {
			if ( 'cancelled' !== (string) ( $j['status'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * How several prep records of one Project combine: several leads are several jobs, so the LEAST prepared decides;
	 * one lead and the estimate number are the same job filed twice, so the MORE prepared decides. PURE.
	 *
	 * @param string[] $by_leads the states read by each lead
	 * @param string   $by_est   the state read by the estimate number ('unknown' when none)
	 */
	public static function combine_cut( array $by_leads, string $by_est ): string {
		$order = array( 'unknown' => 0, 'uncut' => 1, 'partial' => 2, 'complete' => 3 );
		$known = array_values( array_filter( array_map( 'strval', $by_leads ), static function ( $s ) use ( $order ) {
			return isset( $order[ $s ] ) && 'unknown' !== $s;
		} ) );
		if ( count( $known ) > 1 ) {
			usort( $known, static function ( $a, $b ) use ( $order ) {
				return $order[ $a ] <=> $order[ $b ];
			} );
			return $known[0];
		}
		if ( 1 === count( $known ) ) {
			return ( $order[ $by_est ] ?? 0 ) > $order[ $known[0] ] ? $by_est : $known[0];
		}
		return isset( $order[ $by_est ] ) ? $by_est : 'unknown';
	}

	/**
	 * A town for a card: after the street, the last part of the address that is a place. A region code, a postcode, a
	 * "region postcode" pair or a country word is not a town. No street ever. '' when none. PURE.
	 */
	public static function city_of( string $address ): string {
		$parts = array_values( array_filter( array_map( 'trim', explode( ',', $address ) ), 'strlen' ) );
		$city  = '';
		foreach ( array_slice( $parts, 1 ) as $part ) {
			$c = trim( (string) preg_replace( '/^[\s\-\x{2013}\x{2014}]+|[\s\-\x{2013}\x{2014}]+$/u', '', $part ) );
			$c = trim( (string) preg_replace( '/\s*\([^)]*\)\s*$/', '', $c ) ); // a trailing tag in brackets
			if ( preg_match( '/^(.*\D)\s+[A-Z]{2,3}\s+[A-Z0-9][A-Z0-9 \-]{2,9}$/', $c, $m ) && preg_match( '/\d/', substr( $c, strlen( $m[1] ) ) ) ) {
				$c = trim( $m[1] ); // "Springfield AB 12345"
			}
			$is_code     = (bool) preg_match( '/^[A-Z]{2,3}(?:\s+[A-Z0-9][A-Z0-9 \-]{2,9})?$/', $c ) && ( strlen( $c ) <= 3 || preg_match( '/\d/', $c ) );
			$is_postcode = (bool) preg_match( '/^[A-Z0-9][A-Z0-9 \-]{2,9}$/i', $c ) && (bool) preg_match( '/\d/', $c );
			$is_country  = (bool) preg_match( '/^(?:usa|u\.s\.a\.|us|uk|united states(?: of america)?|united kingdom|canada|australia)$/i', $c );
			if ( '' === $c || $is_code || $is_postcode || $is_country ) {
				continue;
			}
			$city = $c;
		}
		return $city;
	}

	/* ── Reading the Projects ───────────────────────────────────────────────────────────────────────────── */

	/**
	 * Everything My next shows this person. $lean (for the count verbs and the hub's Mine): no walks, no money steps,
	 * no history.
	 *
	 * @return array{present:array,past:array,as_of:string}
	 */
	public static function for_viewer( int $uid, bool $lean = false ): array {
		$empty = array( 'present' => array(), 'past' => array(), 'as_of' => gmdate( 'c', self::now() ) );
		if ( ! self::is_person( $uid ) ) {
			return $empty;
		}
		$owners   = self::owners();
		$rules    = self::step_rules();
		$my_codes = class_exists( 'ZJOB_Scope' ) ? array_map( 'strtoupper', ZJOB_Scope::sp_codes_for( $uid ) ) : array();
		$prep_ok  = self::prep_ok( $uid );
		if ( ! $prep_ok ) {
			// Someone who cannot open Prep could not do the step, nor see the ledger that says it is done.
			$owners['cut'] = array_values( array_diff( $owners['cut'], array( $uid ) ) );
		}

		$data    = self::read( self::now() );
		$present = array();
		$cut_ctx = array();
		$held    = self::held_codes();
		foreach ( $data['projects'] as $pid => $row ) {
			$jobs = $data['jobs'][ $pid ] ?? array();
			if ( ! self::is_sold( $jobs ) ) {
				continue;
			}
			$installed = self::is_installed( (string) $row['state'], $jobs );
			$p         = self::project_facts( $pid, $row, $jobs, $data, $held );
			$p['installed'] = $installed;
			// A confirmed person takes the step only while they still can.
			foreach ( $data['people'][ $pid ] ?? array() as $role => $cu ) {
				if ( 'preparer' === $role && ! $prep_ok && $cu === $uid ) {
					continue;
				}
				if ( self::is_person( (int) $cu ) ) {
					$p['confirmed'][ $role ] = (int) $cu;
				}
			}
			// Which steps could be this person's before anything costly is read.
			$maybe = array();
			foreach ( self::steps( $p + array( 'cut' => 'unknown' ), $owners, $rules ) as $s ) {
				if ( self::is_mine( $s, $uid, $my_codes ) ) {
					$maybe[ $s['step'] ] = $s;
				}
			}
			$office_money = ! $lean && $installed && in_array( $uid, $owners['office'], true );
			$could_cut    = $prep_ok && ! $installed && ( in_array( $uid, $owners['cut'], true ) || (int) ( $p['confirmed']['preparer'] ?? 0 ) === $uid );
			if ( ! $maybe && ! $office_money && ! $could_cut ) {
				continue;
			}
			$project = array(
				'id'           => $pid,
				'created_by'   => (int) $p['creator'],
				'participants' => $p['participants'],
				'confirmed'    => (array) ( $data['people'][ $pid ] ?? array() ),
				'sp_code'      => '',
			);
			// Listing a step only reads the Project: the read gate (the people confirmed on it may read it).
			if ( ! class_exists( 'Zjob_Project_Visibility' ) || ! Zjob_Project_Visibility::may_view_project( $uid, $project ) ) {
				continue;
			}
			if ( $could_cut ) {
				$cut_ctx[ $pid ] = $p;
				continue; // decided once Prep's ledger is read for all of them at once
			}
			if ( $office_money && Zjob_Project_Visibility::decide_money( $uid, $project ) ) {
				$p['money'] = self::money_for( $pid );
			}
			foreach ( self::steps( $p, $owners, $rules ) as $s ) {
				if ( ( ! $lean || empty( $s['money'] ) ) && self::is_mine( $s, $uid, $my_codes ) ) {
					$present[] = self::present_item( $s['step'], $p, $s );
				}
			}
		}

		if ( $cut_ctx ) {
			$cut = self::cut_states( $cut_ctx, $uid );
			foreach ( $cut_ctx as $pid => $p ) {
				$st                   = $cut[ $pid ] ?? array( 'state' => 'unknown' );
				$p['cut']             = (string) $st['state'];
				$p['cut_lead']        = (int) ( $st['lead'] ?? 0 );
				$p['estimate_number'] = (string) ( $st['est'] ?? '' );
				$p['cut_by_name']     = ! empty( $st['cut_by'] ) ? self::first_name( (int) $st['cut_by'] ) : '';
				foreach ( self::steps( $p, $owners, $rules ) as $s ) {
					if ( empty( $s['money'] ) && self::is_mine( $s, $uid, $my_codes ) ) {
						$present[] = self::present_item( $s['step'], $p, $s );
					}
				}
			}
		}

		if ( $lean ) {
			return array( 'present' => $present, 'past' => array(), 'as_of' => gmdate( 'c', self::now() ) );
		}
		$present = self::with_holds( $present, $data );
		foreach ( self::walk_items( $uid ) as $w ) {
			$present[] = $w;
		}
		return array( 'present' => self::sort_present( $present ), 'past' => self::past_for( $uid ), 'as_of' => gmdate( 'c', self::now() ) );
	}

	/** The Projects on which this person owns an open step (no money steps): Projects' "Mine" reads this. */
	public static function step_project_ids( int $uid ): array {
		$ids = array();
		foreach ( (array) ( self::for_viewer( $uid, true )['present'] ?? array() ) as $it ) {
			$pid = (string) ( $it['project']['id'] ?? '' );
			if ( '' !== $pid && empty( $it['money'] ) ) {
				$ids[ $pid ] = true;
			}
		}
		return array_keys( $ids );
	}

	/** May this person open Prep? (No Prep app: nobody does shop prep through it.) */
	private static function prep_ok( int $uid ): bool {
		if ( ! class_exists( 'ZPREP_Dashboard' ) || ! method_exists( 'ZPREP_Dashboard', 'user_can_access' ) ) {
			return false;
		}
		return (bool) ZPREP_Dashboard::user_can_access( $uid );
	}

	/** The facts steps() reads, from one Project's rows. */
	private static function project_facts( string $pid, array $row, array $jobs, array $data, array $held ): array {
		$first   = $jobs[0] ?? array();
		$kinds   = array();
		$creator = (int) ( $data['creators'][ $pid ] ?? 0 );
		$people  = $creator > 0 ? array( $creator ) : array();
		$codes   = array();
		$cands   = array();
		foreach ( $jobs as $j ) {
			$k = (string) ( $j['component'] ?? '' );
			if ( '' !== $k ) {
				$kinds[ $k ] = true;
			}
			foreach ( array( 'created_by', 'assigned_user_id' ) as $c ) {
				if ( (int) ( $j[ $c ] ?? 0 ) > 0 ) {
					$people[] = (int) $j[ $c ];
				}
			}
			$code = strtoupper( trim( (string) ( $j['sp_code'] ?? '' ) ) );
			if ( '' !== $code && isset( $held[ $code ] ) ) {
				$codes[ $code ] = true;
			}
			if ( in_array( (string) ( $j['status'] ?? '' ), self::LIVE, true ) && ! empty( $j['scheduled_start_utc'] ) ) {
				$aid = (int) ( $j['scheduled_appt_id'] ?? 0 );
				if ( $aid > 0 && isset( $data['appts'][ $aid ] ) && empty( $data['appts'][ $aid ]['live'] ) ) {
					continue; // its calendar appointment was deleted: no visit
				}
				$who = array_filter( array( (int) ( $j['assigned_user_id'] ?? 0 ), (int) ( $data['appts'][ $aid ]['owner'] ?? 0 ) ) );
				$cands[] = array( 'start_utc' => (string) $j['scheduled_start_utc'], 'people' => array_values( $who ), 'appt' => $aid );
			}
		}
		$link = (int) ( $data['links'][ $pid ] ?? 0 );
		if ( $link > 0 && ! empty( $data['appts'][ $link ]['live'] ) ) {
			$cands[] = array( 'start_utc' => (string) $data['appts'][ $link ]['start_utc'], 'people' => array_filter( array( (int) $data['appts'][ $link ]['owner'] ) ), 'appt' => $link );
		}
		$now   = gmdate( 'Y-m-d H:i:s', self::now() );
		$floor = gmdate( 'Y-m-d H:i:s', self::now() - self::VISIT_DAYS * 86400 );
		$visit = self::pick_visit( $cands, $now, $floor );
		return array(
			'id'           => $pid,
			'code'         => (string) ( $row['human_code'] ?? '' ),
			'customer'     => (string) ( $first['customer_name'] ?? '' ),
			'city'         => self::city_of( (string) ( $first['customer_address'] ?? '' ) ),
			'kinds'        => array_keys( $kinds ),
			'creator'      => $creator,
			'participants' => array_values( array_unique( $people ) ),
			'seller_ids'   => $creator > 0 ? array( $creator ) : array(),
			'seller_codes' => array_keys( $codes ),
			'orders'       => array( 'n' => (int) ( $data['orders'][ $pid ]['n'] ?? 0 ), 'ordered' => (int) ( $data['orders'][ $pid ]['ordered'] ?? 0 ) ),
			'cut'          => 'unknown',
			'visit'        => $visit,
			'visit_past'   => $visit && ! empty( $visit['past'] ),
			'money'        => null,
			'confirmed'    => array(),
			'leads'        => array_values( array_unique( array_filter( array_merge(
				array_map( static function ( $j ) { return (int) ( $j['parent_lead_id'] ?? 0 ); }, $jobs ),
				array_map( static function ( $j ) { return (int) ( $j['crm_child_lead_id'] ?? 0 ); }, $jobs )
			) ) ) ),
			'estimate_ids' => array_values( array_unique( array_filter( array_map( static function ( $j ) {
				return (int) ( $j['estimate_id'] ?? 0 );
			}, $jobs ) ) ) ),
		);
	}

	/**
	 * One read of everything the steps need, for the Projects that could carry a step: live ones, and finished ones
	 * within FINISHED_DAYS (their money steps). Each part is one query.
	 *
	 * @return array{projects:array,jobs:array,creators:array,people:array,orders:array,links:array,appts:array}
	 */
	public static function read( int $now ): array {
		global $wpdb;
		$out = array( 'projects' => array(), 'jobs' => array(), 'creators' => array(), 'people' => array(), 'orders' => array(), 'links' => array(), 'appts' => array() );
		if ( ! isset( $wpdb ) || ! class_exists( 'Zdz_Flow_DB' ) || ! class_exists( 'ZJOB_DB' ) ) {
			return $out;
		}
		$wi   = Zdz_Flow_DB::work_items();
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT id, state, state_since, human_code, created_at FROM {$wi} WHERE work_type = %s AND tenant_id = %d" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix plus a fixed name; values are placeholders.
			. " AND ( state IN ('open','active','waiting') OR ( state = 'completed' AND state_since >= %s ) ) ORDER BY created_at DESC LIMIT %d",
			'project',
			Zdz_Flow_DB::tenant_id(),
			gmdate( 'Y-m-d H:i:s', $now - self::FINISHED_DAYS * 86400 ),
			self::MAX_PROJECTS
		), ARRAY_A );
		foreach ( $rows as $r ) {
			$out['projects'][ (string) $r['id'] ] = $r;
		}
		$pids = array_keys( $out['projects'] );
		if ( ! $pids ) {
			return $out;
		}
		$in = implode( ',', array_fill( 0, count( $pids ), '%s' ) );

		// What each Project points at: its jobs (directly, or through its estimate) and its appointment.
		$refs     = Zdz_Flow_DB::refs();
		$job_of   = array();
		$est_of   = array();
		$ref_rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT work_item_id, `system`, entity, external_id FROM {$refs} WHERE work_item_id IN ({$in}) AND `system` IN ('job','estimate','appointment')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix plus a fixed name; every IN-list item is a placeholder.
			$pids
		), ARRAY_A );
		foreach ( $ref_rows as $r ) {
			$pid = (string) $r['work_item_id'];
			$ext = (int) $r['external_id'];
			if ( $ext <= 0 ) {
				continue;
			}
			if ( 'job' === $r['system'] && 'handoff' === $r['entity'] ) {
				$job_of[ $ext ] = $pid;
			} elseif ( 'estimate' === $r['system'] && 'document' === $r['entity'] ) {
				$est_of[ $ext ][] = $pid;
			} elseif ( 'appointment' === $r['system'] ) {
				$out['links'][ $pid ] = $ext;
			}
		}
		if ( $job_of || $est_of ) {
			$parts = array();
			if ( $job_of ) {
				$parts[] = 'id IN (' . implode( ',', array_map( 'intval', array_keys( $job_of ) ) ) . ')';
			}
			if ( $est_of ) {
				$parts[] = 'estimate_id IN (' . implode( ',', array_map( 'intval', array_keys( $est_of ) ) ) . ')';
			}
			// Only integers are inlined.
			$jobs = (array) $wpdb->get_results( 'SELECT * FROM ' . ZJOB_DB::table() . ' WHERE ' . implode( ' OR ', $parts ) . ' ORDER BY id ASC', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			foreach ( $jobs as $j ) {
				$to = array();
				if ( isset( $job_of[ (int) $j['id'] ] ) ) {
					$to[] = $job_of[ (int) $j['id'] ];
				}
				foreach ( $est_of[ (int) ( $j['estimate_id'] ?? 0 ) ] ?? array() as $pid ) {
					$to[] = $pid;
				}
				foreach ( array_unique( $to ) as $pid ) {
					$out['jobs'][ $pid ][] = $j;
				}
			}
		}

		// Who made each Project (the first transition's person).
		$tr = Zdz_Flow_DB::transitions();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT work_item_id, actor_id FROM {$tr} WHERE work_item_id IN ({$in}) AND sequence = 1 AND actor_kind = 'user'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix plus a fixed name; every IN-list item is a placeholder.
			$pids
		), ARRAY_A ) as $r ) {
			$out['creators'][ (string) $r['work_item_id'] ] = (int) $r['actor_id'];
		}

		// Who is confirmed on each step (the newest live row of a role wins).
		if ( class_exists( 'ZJOB_Project_People' ) && ZJOB_Project_People::ready() ) {
			foreach ( (array) $wpdb->get_results( $wpdb->prepare(
				'SELECT project_id, role, user_id FROM ' . ZJOB_Project_People::table() . " WHERE ended_at IS NULL AND project_id IN ({$in}) ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name; every IN-list item is a placeholder.
				$pids
			), ARRAY_A ) as $r ) {
				$out['people'][ (string) $r['project_id'] ][ (string) $r['role'] ] = (int) $r['user_id'];
			}
		}

		// Material orders: how many, and how many placed (discarded ones are not orders).
		if ( class_exists( 'ZJOB_Project_Orders' ) && ZJOB_Project_Orders::ready() ) {
			foreach ( (array) $wpdb->get_results( $wpdb->prepare(
				'SELECT project_id, status, COUNT(*) AS c FROM ' . ZJOB_Project_Orders::table() . " WHERE project_id IN ({$in}) AND status <> %s GROUP BY project_id, status", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name; every IN-list item and the status are placeholders.
				array_merge( $pids, array( ZJOB_Project_Orders::ST_DISCARDED ) )
			), ARRAY_A ) as $r ) {
				$pid = (string) $r['project_id'];
				$out['orders'][ $pid ]['n']       = (int) ( $out['orders'][ $pid ]['n'] ?? 0 ) + (int) $r['c'];
				$out['orders'][ $pid ]['ordered'] = (int) ( $out['orders'][ $pid ]['ordered'] ?? 0 ) + ( ZJOB_Project_Orders::ST_ORDERED === $r['status'] ? (int) $r['c'] : 0 );
			}
		}

		// The calendar's own word on each appointment: still there, when, and whose.
		$aids = array_values( $out['links'] );
		foreach ( $out['jobs'] as $list ) {
			foreach ( $list as $j ) {
				if ( (int) ( $j['scheduled_appt_id'] ?? 0 ) > 0 ) {
					$aids[] = (int) $j['scheduled_appt_id'];
				}
			}
		}
		$aids = array_values( array_unique( array_filter( array_map( 'intval', $aids ) ) ) );
		$at   = $wpdb->prefix . 'zsch_appointments';
		if ( $aids && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $at ) ) === $at ) {
			// Only integers are inlined.
			foreach ( (array) $wpdb->get_results( "SELECT id, start_utc, owner_user_id, deleted_at FROM {$at} WHERE id IN (" . implode( ',', $aids ) . ')', ARRAY_A ) as $a ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name; the IN list is ints only (intval above).
				$out['appts'][ (int) $a['id'] ] = array(
					'start_utc' => (string) $a['start_utc'],
					'owner'     => (int) $a['owner_user_id'],
					'live'      => empty( $a['deleted_at'] ),
				);
			}
			foreach ( $aids as $aid ) {
				if ( ! isset( $out['appts'][ $aid ] ) ) {
					$out['appts'][ $aid ] = array( 'start_utc' => '', 'owner' => 0, 'live' => false );
				}
			}
		}
		return $out;
	}

	/** Salesperson codes somebody holds now (a code nobody holds is no seller to send a step to). */
	private static function held_codes(): array {
		static $memo = null;
		if ( null !== $memo ) {
			return $memo;
		}
		$memo = array();
		if ( ! function_exists( 'get_users' ) || ! class_exists( 'ZJOB_Scope' ) ) {
			return $memo;
		}
		foreach ( (array) get_users( array( 'fields' => 'ID', 'number' => 500 ) ) as $u ) {
			foreach ( ZJOB_Scope::sp_codes_for( (int) ( is_object( $u ) ? $u->ID : $u ) ) as $c ) {
				$memo[ strtoupper( (string) $c ) ] = true;
			}
		}
		return $memo;
	}

	/** A person's first name (the display name's first word). */
	private static function first_name( int $uid ): string {
		$u = function_exists( 'get_userdata' ) ? get_userdata( $uid ) : null;
		return $u ? (string) strtok( (string) $u->display_name, ' ' ) : '';
	}

	/**
	 * Prep's ledger for each Project, read through Prep's own gated readers: by its jobs' CRM leads and by its estimate
	 * numbers. The shop's step is the bench work: a run measured and cut on site does not keep the shop's step open.
	 *
	 * @return array<string,array{state:string,lead:int,est:string,cut_by:int}>
	 */
	private static function cut_states( array $ctx, int $uid ): array {
		$out = array();
		if ( ! class_exists( 'ZPREP_Cut_State' ) ) {
			foreach ( $ctx as $pid => $p ) {
				$out[ $pid ] = array( 'state' => 'unknown', 'lead' => (int) ( $p['leads'][0] ?? 0 ), 'est' => '', 'cut_by' => 0 );
			}
			return $out;
		}
		$all_leads = array();
		$nums      = array();
		$num_of    = array();
		foreach ( $ctx as $pid => $p ) {
			$all_leads = array_merge( $all_leads, (array) $p['leads'] );
			$n         = self::estimate_number( (array) $p['estimate_ids'] );
			$num_of[ $pid ] = $n;
			if ( '' !== $n ) {
				$nums[] = $n;
			}
		}
		$by_lead = $all_leads ? (array) ZPREP_Cut_State::for_leads( array_values( array_unique( $all_leads ) ), $uid ) : array();
		$by_est  = ( $nums && method_exists( 'ZPREP_Cut_State', 'for_estimate_numbers' ) ) ? (array) ZPREP_Cut_State::for_estimate_numbers( array_values( array_unique( $nums ) ), $uid ) : array();
		$shop    = static function ( $r ): string {
			$b = is_array( $r ) ? ( $r['bench'] ?? null ) : null;
			if ( is_array( $b ) && (int) ( $b['total'] ?? 0 ) > 0 ) {
				return (string) ( $b['state'] ?? 'unknown' );
			}
			return (string) ( is_array( $r ) ? ( $r['state'] ?? 'unknown' ) : 'unknown' );
		};
		foreach ( $ctx as $pid => $p ) {
			$by = 0;
			$at = '';
			$st = array();
			foreach ( (array) $p['leads'] as $l ) {
				if ( isset( $by_lead[ $l ] ) ) {
					$st[] = $shop( $by_lead[ $l ] );
					if ( ! empty( $by_lead[ $l ]['cut_by'] ) && strcmp( (string) ( $by_lead[ $l ]['cut_at'] ?? '' ), $at ) >= 0 ) {
						$by = (int) $by_lead[ $l ]['cut_by'];
						$at = (string) ( $by_lead[ $l ]['cut_at'] ?? '' );
					}
				}
			}
			$e  = $num_of[ $pid ];
			$es = ( '' !== $e && isset( $by_est[ $e ] ) ) ? $shop( $by_est[ $e ] ) : 'unknown';
			if ( '' !== $e && ! empty( $by_est[ $e ]['cut_by'] ) && strcmp( (string) ( $by_est[ $e ]['cut_at'] ?? '' ), $at ) >= 0 ) {
				$by = (int) $by_est[ $e ]['cut_by'];
			}
			$out[ $pid ] = array( 'state' => self::combine_cut( $st, $es ), 'lead' => (int) ( $p['leads'][0] ?? 0 ), 'est' => $e, 'cut_by' => $by );
		}
		return $out;
	}

	/** The estimate's document number, read from the estimate app's own table when it is installed (no guess). */
	private static function estimate_number( array $estimate_ids ): string {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $estimate_ids ) ) );
		if ( ! $ids || ! isset( $wpdb ) || ! class_exists( 'ZEST_DB' ) || ! is_callable( array( 'ZEST_DB', 'estimates_table' ) ) ) {
			return '';
		}
		$t = ZEST_DB::estimates_table();
		// Only integers are inlined.
		$n = (string) $wpdb->get_var( "SELECT doc_number FROM {$t} WHERE id IN (" . implode( ',', $ids ) . ") AND doc_number <> '' ORDER BY id ASC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name; the IN list is ints only (intval above).
		return (string) preg_replace( '/[^A-Za-z0-9\-]/', '', $n );
	}

	/**
	 * Invoiced and due for the office's money steps, from a billing connector that answers zjob_project_money
	 * ({invoiced: bool, due: float|null}). No answer: null, so no money step is claimed.
	 */
	private static function money_for( string $pid ): ?array {
		$m = function_exists( 'apply_filters' ) ? apply_filters( 'zjob_project_money', null, $pid ) : null;
		if ( ! is_array( $m ) || ! array_key_exists( 'invoiced', $m ) ) {
			return null;
		}
		return array( 'invoiced' => ! empty( $m['invoiced'] ), 'due' => isset( $m['due'] ) && is_numeric( $m['due'] ) ? round( (float) $m['due'], 2 ) : null );
	}

	/**
	 * While a Project's order is held by its agreement (ZJOB_Order_Gate), its "Order materials" step says so in short
	 * words, flagged held. One gate read per Project with an order step; a failure costs only that label.
	 */
	private static function with_holds( array $present, array $data ): array {
		if ( ! class_exists( 'ZJOB_Order_Gate' ) || ! class_exists( 'Zjob_Project' ) ) {
			return $present;
		}
		foreach ( $present as $k => $it ) {
			if ( 'order' !== ( $it['step'] ?? '' ) ) {
				continue;
			}
			$pid = (string) ( $it['project']['id'] ?? '' );
			try {
				$p = Zjob_Project::get( $pid );
				$g = $p ? ZJOB_Order_Gate::for_project( $p, $data['jobs'][ $pid ] ?? array() ) : null;
			} catch ( \Throwable $e ) {
				$g = null;
			}
			if ( is_array( $g ) && empty( $g['ok_to_order'] ) && '' !== (string) ( $g['short'] ?? '' ) ) {
				$present[ $k ]['label'] = (string) $g['short'];
				$present[ $k ]['held']  = true;
			}
		}
		return $present;
	}

	/**
	 * The door-hanger routes assigned to this person (zdh_routes_list, scope 'mine'), as walk steps. The doorhangers app
	 * answers only for a holder of its app and never for the shared device; a finished route is simply absent.
	 */
	public static function walk_items( int $uid ): array {
		if ( ! function_exists( 'has_filter' ) || ! has_filter( 'zdh_routes_list' ) ) {
			return array();
		}
		$routes = apply_filters( 'zdh_routes_list', null, $uid, 'mine' );
		if ( ! is_array( $routes ) ) {
			return array();
		}
		$out = array();
		$dot = " \xC2\xB7 ";
		foreach ( array_slice( $routes, 0, 20 ) as $r ) {
			if ( ! is_array( $r ) || (int) ( $r['assigned_uid'] ?? 0 ) !== $uid ) {
				continue;
			}
			$houses = (int) ( $r['houses'] ?? 0 );
			$mins   = (int) ( $r['est_min'] ?? 0 );
			/* translators: %d: houses on the route. */
			$why = sprintf( __( '%d houses', 'zorderz' ), $houses )
				. ( $mins > 0 ? $dot . ( $mins >= 60 ? round( $mins / 60, 1 ) . ' h' : $mins . ' min' ) : '' )
				. ( 'in_progress' === (string) ( $r['status'] ?? '' ) ? $dot . __( 'in progress', 'zorderz' ) : '' );
			$out[] = array(
				'key'     => 'walk:' . (int) $r['id'],
				'step'    => 'walk',
				'label'   => self::labels()['walk'],
				'why'     => $why,
				'when'    => ! empty( $r['due_by'] ) ? (string) $r['due_by'] . ' 23:59:00' : '',
				/* translators: %d: route number. */
				'project' => array( 'id' => '', 'code' => '', 'customer' => sprintf( __( 'Route #%d', 'zorderz' ), (int) $r['id'] ), 'city' => trim( (string) ( $r['city'] ?? '' ) ) ),
				'cut_by'  => '',
				'start'   => array( 'app' => 'doorhangers', 'options' => array( 'drop_id' => (int) $r['id'] ), 'label' => __( 'Open the route', 'zorderz' ) ),
			);
		}
		return $out;
	}

	/**
	 * What this person finished lately: orders they marked ordered and jobs they finished. Each with its Project (one
	 * this person can still see) and when.
	 */
	public static function past_for( int $uid ): array {
		global $wpdb;
		$out = array();
		if ( ! isset( $wpdb ) || ! class_exists( 'Zjob_Project' ) || ! class_exists( 'Zjob_Project_Visibility' ) ) {
			return $out;
		}
		$since = gmdate( 'Y-m-d H:i:s', self::now() - self::PAST_DAYS * 86400 );
		$seen  = array();
		$proj  = static function ( string $pid ) use ( $uid, &$seen ) {
			if ( '' === $pid ) {
				return null;
			}
			if ( array_key_exists( $pid, $seen ) ) {
				return $seen[ $pid ];
			}
			$p = Zjob_Project::get( $pid );
			if ( ! $p || ! Zjob_Project_Visibility::may_view_project( $uid, $p ) ) {
				return $seen[ $pid ] = null;
			}
			$jobs = Zjob_Project::jobs_for( $pid );
			return $seen[ $pid ] = array(
				'id'       => $pid,
				'code'     => (string) ( $p['human_code'] ?? '' ),
				'customer' => (string) ( $jobs[0]['customer_name'] ?? '' ),
				'city'     => self::city_of( (string) ( $jobs[0]['customer_address'] ?? '' ) ),
			);
		};
		if ( class_exists( 'ZJOB_Project_Orders' ) && ZJOB_Project_Orders::ready() ) {
			foreach ( (array) $wpdb->get_results( $wpdb->prepare(
				'SELECT id, project_id, ordered_at FROM ' . ZJOB_Project_Orders::table() . ' WHERE status = %s AND ordered_by = %d AND ordered_at >= %s ORDER BY ordered_at DESC LIMIT 50', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name; values are placeholders.
				ZJOB_Project_Orders::ST_ORDERED,
				$uid,
				$since
			), ARRAY_A ) as $r ) {
				$p = $proj( (string) $r['project_id'] );
				if ( $p ) {
					$out[] = array( 'key' => 'order:' . (int) $r['id'], 'step' => 'order', 'label' => __( 'Ordered materials', 'zorderz' ), 'at' => (string) $r['ordered_at'], 'project' => $p );
				}
			}
		}
		if ( class_exists( 'ZJOB_DB' ) && class_exists( 'Zdz_Flow_Refs' ) ) {
			foreach ( (array) $wpdb->get_results( $wpdb->prepare(
				'SELECT id, estimate_id, worker_done_at FROM ' . ZJOB_DB::table() . ' WHERE assigned_user_id = %d AND worker_done_at >= %s ORDER BY worker_done_at DESC LIMIT 50', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name; values are placeholders.
				$uid,
				$since
			), ARRAY_A ) as $r ) {
				$pid = (string) ( Zdz_Flow_Refs::get( 'job', 'handoff', (string) (int) $r['id'] ) ?? '' );
				if ( '' === $pid && (int) $r['estimate_id'] > 0 ) {
					$pid = (string) ( Zdz_Flow_Refs::get( 'estimate', 'document', (string) (int) $r['estimate_id'] ) ?? '' );
				}
				$p = $proj( $pid );
				if ( $p ) {
					$out[] = array( 'key' => 'job:' . (int) $r['id'], 'step' => 'install', 'label' => __( 'Finished a job', 'zorderz' ), 'at' => (string) $r['worker_done_at'], 'project' => $p );
				}
			}
		}
		usort( $out, static function ( $a, $b ) {
			return strcmp( (string) $b['at'], (string) $a['at'] );
		} );
		return array_slice( $out, 0, 40 );
	}

	/* ── The endpoint ───────────────────────────────────────────────────────────────────────────────────── */

	public static function ajax(): void {
		check_ajax_referer( ZJOB_NONCE, 'nonce' );
		$uid = (int) get_current_user_id(); // never a client-supplied id
		if ( $uid <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Not signed in.', 'zorderz' ) ), 401 );
		}
		if ( ! self::is_person( $uid ) ) {
			wp_send_json_error( array( 'message' => __( 'Not available on a shared device.', 'zorderz' ) ), 403 );
		}
		if ( class_exists( 'ZDZ_Plugin_API' ) && method_exists( 'ZDZ_Plugin_API', 'user_can_access_app' )
			&& ! ZDZ_Plugin_API::user_can_access_app( $uid, ZJOB_APP_ID )
			&& ! ( class_exists( 'ZJOB_Projects_App' ) && ZDZ_Plugin_API::user_can_access_app( $uid, ZJOB_Projects_App::APP_ID ) ) ) {
			wp_send_json_error( array( 'message' => __( 'This app is not available on your account.', 'zorderz' ) ), 403 );
		}
		$data = null;
		try {
			$data = self::for_viewer( $uid );
		} catch ( \Throwable $e ) {
			error_log( 'Zorderz Jobs my next: ' . $e->getMessage() );
		}
		if ( null === $data ) {
			wp_send_json_error( array( 'message' => __( 'Your next steps could not be worked out just now.', 'zorderz' ) ), 500 );
		}
		wp_send_json_success( $data );
	}
}
