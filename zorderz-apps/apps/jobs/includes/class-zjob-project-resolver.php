<?php
/**
 * Zjob_Project_Resolver — the money-gated read resolver for a Project.
 *
 * gather() assembles the Project's panels from local sources, each tagged CLASS_WORK or CLASS_MONEY,
 * under one keystone rule:
 *
 *   RULE 1 — GATE BEFORE GATHERING. A CLASS_MONEY source, for a viewer without see_money, is set
 *   STATE_WITHHELD and `continue`d BEFORE its gather callback runs — so the figure is NEVER FETCHED.
 *   This is "never fetched" > "fetched then redacted": a hidden field is a request; an unfetched
 *   figure is the boundary. Do NOT "optimise" this into gather-then-filter.
 *
 * FOUR panel states, NEVER conflated:
 *   STATE_OK          the panel loaded and has content.
 *   STATE_EMPTY       the panel loaded and there is nothing yet — a FACT ("nothing there yet").
 *   STATE_WITHHELD    a money-class panel the viewer may not see — gated upstream, never fetched.
 *   STATE_UNAVAILABLE the panel could not load — a FAILURE. `empty` and `unavailable` are different
 *                     answers and must not be collapsed into one.
 *
 * EXTENSION SEAM — the `zdz_project_sources` filter. A third-party source that declares CLASS_MONEY
 * opts into the gate FOR FREE: "the author of a data source cannot forget to check." The two-class
 * split is the whole mechanism — the resolver body never grows a per-source money check.
 *
 * The billing wiring reads Zorderz's own ZEST_Billing (the committed three-state linkage source):
 * the FACT a billing document exists is CLASS_WORK (the linkage state — no figure); the AMOUNT is
 * CLASS_MONEY (the invoice total, fetched only inside the gate). Any photo/document URL a source
 * surfaces MUST go through ZDZ_User_Media::secure_url() (the token proxy) — never a raw uploads URL
 * (geo-PII). The built-in sources surface counts only, so no raw URL is emitted here.
 *
 * EXISTENCE ORACLE closed: a missing project and a REL_NONE viewer return the SAME single literal
 * refusal, so two responses cannot enumerate ids.
 *
 * Ships EMPTY: names no company/person/product/place/provider; seeds nothing.
 *
 * @package Zorderz\Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-zjob-project.php';
require_once __DIR__ . '/class-zjob-project-visibility.php';

if ( ! class_exists( 'Zjob_Project_Resolver' ) ) {

	class Zjob_Project_Resolver {

		/** Source data classes. Declaring CLASS_MONEY opts a source into the gate for free. */
		const CLASS_WORK  = 'work';
		const CLASS_MONEY = 'money';

		/** The four — and only four — panel states. Constants so a value is never typed twice. */
		const STATE_OK          = 'ok';          // loaded, has content.
		const STATE_EMPTY       = 'empty';       // loaded, nothing yet (a fact).
		const STATE_WITHHELD    = 'withheld';    // money-class, gated upstream (never fetched).
		const STATE_UNAVAILABLE = 'unavailable'; // could not load (a failure).

		/** The valid states a gathered source may return (withheld is only ever set by the gate). */
		const GATHER_STATES = array( self::STATE_OK, self::STATE_EMPTY, self::STATE_WITHHELD, self::STATE_UNAVAILABLE );

		/* ===================================================================
		 * GATHER — the money-gated panel assembly.
		 * =================================================================== */

		/**
		 * Resolve a Project's panels for a viewer.
		 *
		 * @param int    $viewer     WP user id.
		 * @param string $project_id the project work_item id.
		 * @return array Either the single literal refusal { ok:false, reason } (missing project OR a
		 *               REL_NONE viewer), or { ok:true, project_id, human_code, status, relationship,
		 *               see_money, panels:{ id => { id, class, state, data } } }.
		 */
		public static function gather( int $viewer, string $project_id ): array {
			$project = class_exists( 'Zjob_Project' ) ? Zjob_Project::get( $project_id ) : null;
			$rel     = ( is_array( $project ) )
				? Zjob_Project_Visibility::relationship( $viewer, $project )
				: Zjob_Project_Visibility::REL_NONE;

			// Existence oracle closed: missing project AND no-relationship return the SAME refusal.
			if ( ! is_array( $project ) || Zjob_Project_Visibility::REL_NONE === $rel ) {
				return array( 'ok' => false, 'reason' => Zjob_Project::REFUSAL );
			}

			// The ONE money decision, made once, upstream of every source (RULE 1 depends on it).
			$see_money = Zjob_Project_Visibility::decide_money( $viewer, $project );

			$panels = array();
			foreach ( self::sources( $viewer, $project ) as $src ) {
				$sid = $src['id'];
				$cls = $src['class'];

				// RULE 1 — GATE BEFORE GATHERING. A money-class source for a viewer without see_money
				// is withheld and skipped HERE, so its gather callback never runs and the figure is
				// never fetched. This `continue` is the security keystone of the whole subsystem.
				if ( self::CLASS_MONEY === $cls && ! $see_money ) {
					$panels[ $sid ] = array(
						'id'    => $sid,
						'class' => $cls,
						'state' => self::STATE_WITHHELD,
						'data'  => null,
					);
					continue;
				}

				try {
					$res = call_user_func( $src['gather'], $viewer, $project, $see_money );
				} catch ( \Throwable $e ) {
					// "could not load" is a FAILURE — distinct from "nothing yet".
					$panels[ $sid ] = array(
						'id'    => $sid,
						'class' => $cls,
						'state' => self::STATE_UNAVAILABLE,
						'data'  => null,
					);
					continue;
				}
				$panels[ $sid ] = self::normalize_panel( $sid, $cls, $res );
			}

			return array(
				'ok'           => true,
				'project_id'   => (string) $project['id'],
				'human_code'   => (string) ( $project['human_code'] ?? '' ),
				'status'       => (string) ( $project['state'] ?? '' ),
				'relationship' => $rel,
				'see_money'    => $see_money,
				'customer'     => isset( $project['customer'] ) ? $project['customer'] : null,
				'panels'       => $panels,
			);
		}

		/**
		 * Route any photo/document URL through the token proxy. A source that surfaces media MUST call
		 * this — never a raw uploads URL (geo-PII: a customer address/name/measurement must not reach a
		 * public URL). Returns '' when the media store is unavailable (fail loud, never a raw fallback).
		 *
		 * @param array  $media_row a ZDZ_User_Media row.
		 * @param string $size
		 * @return string
		 */
		public static function secure_media_url( array $media_row, string $size = 'full' ): string {
			if ( ! class_exists( 'ZDZ_User_Media' ) || ! method_exists( 'ZDZ_User_Media', 'secure_url' ) ) {
				return '';
			}
			return (string) ZDZ_User_Media::secure_url( $media_row, $size );
		}

		/**
		 * THE PER-COMPONENT GATE (B5). Given the project's child job ROWS, return the ids of the jobs
		 * this viewer could open in Jobs — nothing else may contribute assets to the Record. Runs
		 * ZJOB_Jobs::actor_can_manage() per row BEFORE any asset read. This door and
		 * Zjob_Project_Visibility::relationship() answer DIFFERENT questions (open THIS job vs. see the
		 * project at all) over the SAME authority; they must never be allowed to diverge — a leak here
		 * is exactly the disclosure the subsystem exists to prevent.
		 *
		 * @param array $jobs   job rows (each an assoc array with at least id).
		 * @param int   $viewer WP user id.
		 * @return int[] openable job ids.
		 */
		public static function openable_job_ids( array $jobs, int $viewer ): array {
			if ( $viewer <= 0 || ! class_exists( 'ZJOB_Jobs' ) || ! method_exists( 'ZJOB_Jobs', 'actor_can_manage' ) ) {
				return array();
			}
			$ids = array();
			foreach ( $jobs as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$jid = (int) ( $row['id'] ?? 0 );
				if ( $jid > 0 && ZJOB_Jobs::actor_can_manage( $viewer, $row ) ) {
					$ids[] = $jid;
				}
			}
			return $ids;
		}

		/* ===================================================================
		 * SOURCES — built-in + the extension filter.
		 * =================================================================== */

		/**
		 * The registered sources for this project, each { id, class, gather:callable }. A source
		 * declaring CLASS_MONEY is gated for free by the gather loop; nothing else opts it in.
		 *
		 * @param int   $viewer
		 * @param array $project
		 * @return array<int,array{id:string,class:string,gather:callable}>
		 */
		private static function sources( int $viewer, array $project ): array {
			$builtin = array(
				array( 'id' => 'estimate', 'class' => self::CLASS_WORK, 'gather' => array( __CLASS__, 'src_estimate' ) ),
				array( 'id' => 'billing', 'class' => self::CLASS_WORK, 'gather' => array( __CLASS__, 'src_billing' ) ),
				array( 'id' => 'billing_amount', 'class' => self::CLASS_MONEY, 'gather' => array( __CLASS__, 'src_billing_amount' ) ),
				array( 'id' => 'schedule', 'class' => self::CLASS_WORK, 'gather' => array( __CLASS__, 'src_schedule' ) ),
				array( 'id' => 'activity', 'class' => self::CLASS_WORK, 'gather' => array( __CLASS__, 'src_activity' ) ),
				array( 'id' => 'record', 'class' => self::CLASS_WORK, 'gather' => array( __CLASS__, 'src_record' ) ),
			);

			/**
			 * Register additional Project panels. A source that declares 'class' => CLASS_MONEY is
			 * gated by decide_money() for free — the resolver body never learns its name.
			 *
			 * @param array $builtin
			 * @param int   $viewer
			 * @param array $project
			 */
			$declared = apply_filters( 'zdz_project_sources', $builtin, $viewer, $project );

			$out = array();
			foreach ( (array) $declared as $s ) {
				if ( ! is_array( $s ) ) {
					continue;
				}
				$sid = sanitize_key( (string) ( $s['id'] ?? '' ) );
				$cb  = $s['gather'] ?? null;
				if ( '' === $sid || isset( $out[ $sid ] ) || ! is_callable( $cb ) ) {
					continue;
				}
				// A source is money-class ONLY when it declares so; anything else is work-class.
				$cls           = ( self::CLASS_MONEY === ( $s['class'] ?? '' ) ) ? self::CLASS_MONEY : self::CLASS_WORK;
				$out[ $sid ]   = array( 'id' => $sid, 'class' => $cls, 'gather' => $cb );
			}
			return array_values( $out );
		}

		/** Coerce a source result to a well-formed panel with one of the four states. */
		private static function normalize_panel( string $sid, string $cls, $res ): array {
			$state = self::STATE_UNAVAILABLE;
			$data  = null;
			if ( is_array( $res ) ) {
				$s = (string) ( $res['state'] ?? '' );
				if ( in_array( $s, self::GATHER_STATES, true ) ) {
					$state = $s;
				}
				$data = array_key_exists( 'data', $res ) ? $res['data'] : null;
			}
			// A withheld panel never carries data (defence in depth against a leak through a source).
			if ( self::STATE_WITHHELD === $state ) {
				$data = null;
			}
			return array( 'id' => $sid, 'class' => $cls, 'state' => $state, 'data' => $data );
		}

		/* ===================================================================
		 * BUILT-IN SOURCES
		 * =================================================================== */

		/** WORK: the FACT that estimate document(s) exist on this project (no figure). */
		public static function src_estimate( int $viewer, array $project, bool $see_money ): array {
			unset( $viewer, $see_money );
			$ids = self::estimate_ids( $project );
			if ( empty( $ids ) ) {
				return array( 'state' => self::STATE_EMPTY, 'data' => null );
			}
			return array( 'state' => self::STATE_OK, 'data' => array( 'estimate_ids' => array_values( $ids ) ) );
		}

		/**
		 * WORK: the billing LINKAGE state from ZEST_Billing — invoiced / not_invoiced / unchecked.
		 * This is the FACT a document does or does not exist; it carries NO money figure.
		 */
		public static function src_billing( int $viewer, array $project, bool $see_money ): array {
			unset( $viewer, $see_money );
			$ids = self::estimate_ids( $project );
			if ( empty( $ids ) ) {
				return array( 'state' => self::STATE_EMPTY, 'data' => null );
			}
			if ( ! class_exists( 'ZEST_Billing' ) ) {
				return array( 'state' => self::STATE_UNAVAILABLE, 'data' => null );
			}
			$states = array();
			foreach ( $ids as $eid ) {
				$states[ (int) $eid ] = ZEST_Billing::resolve( (int) $eid )['state'];
			}
			return array( 'state' => self::STATE_OK, 'data' => array( 'billing' => $states ) );
		}

		/**
		 * MONEY: the invoice AMOUNT (total / paid / due). Reached ONLY when the gate is open — the
		 * loop withholds this source before it ever runs for a viewer without see_money, so the amount
		 * query never executes for them.
		 */
		public static function src_billing_amount( int $viewer, array $project, bool $see_money ): array {
			unset( $viewer, $see_money );
			$ids = self::estimate_ids( $project );
			if ( empty( $ids ) ) {
				return array( 'state' => self::STATE_EMPTY, 'data' => null );
			}
			if ( ! class_exists( 'ZEST_Billing' ) ) {
				return array( 'state' => self::STATE_UNAVAILABLE, 'data' => null );
			}
			$total = 0;
			$paid  = 0;
			$any   = false;
			foreach ( $ids as $eid ) {
				$b = ZEST_Billing::resolve( (int) $eid );
				if ( ZEST_Billing::STATE_INVOICED === $b['state'] && (int) $b['invoice_id'] > 0 ) {
					$amt = self::invoice_amount_cents( (int) $b['invoice_id'] );
					if ( null !== $amt ) {
						$total += $amt['total_cents'];
						$paid  += $amt['paid_cents'];
						$any    = true;
					}
				}
			}
			if ( ! $any ) {
				// Nothing billed yet — a FACT, not a failure (and distinct from withheld).
				return array( 'state' => self::STATE_EMPTY, 'data' => null );
			}
			return array(
				'state' => self::STATE_OK,
				'data'  => array(
					'total_cents'    => $total,
					'paid_cents'     => $paid,
					'due_cents'      => max( 0, $total - $paid ),
					'currency_scale' => 100,
				),
			);
		}

		/**
		 * WORK: a light schedule read — the earliest linked, non-zero, live-status appointment start
		 * across the project's child jobs. (The full three-state install-date resolver is C1; this is
		 * a fact-of-scheduling panel, and it carries no money.)
		 */
		public static function src_schedule( int $viewer, array $project, bool $see_money ): array {
			unset( $viewer, $see_money );
			$earliest = null;
			foreach ( Zjob_Project::jobs_for( (string) $project['id'] ) as $j ) {
				$appt = (int) ( $j['scheduled_appt_id'] ?? 0 );
				$st   = trim( (string) ( $j['scheduled_start_utc'] ?? '' ) );
				$dead = 'cancelled' === strtolower( (string) ( $j['status'] ?? '' ) );
				if ( $appt > 0 && '' !== $st && '0000-00-00 00:00:00' !== $st && ! $dead ) {
					if ( null === $earliest || $st < $earliest ) {
						$earliest = $st;
					}
				}
			}
			if ( null === $earliest ) {
				return array( 'state' => self::STATE_EMPTY, 'data' => array( 'scheduled' => false ) );
			}
			return array( 'state' => self::STATE_OK, 'data' => array( 'scheduled' => true, 'start_utc' => $earliest ) );
		}

		/** WORK: activity counts only — never note bodies, never raw asset URLs (that is B5). */
		public static function src_activity( int $viewer, array $project, bool $see_money ): array {
			unset( $viewer, $see_money );
			$counts = ( isset( $project['counts'] ) && is_array( $project['counts'] ) )
				? $project['counts']
				: Zjob_Project::counts_for( (string) $project['id'] );
			$total = 0;
			foreach ( $counts as $c ) {
				$total += (int) $c;
			}
			if ( 0 === $total ) {
				return array( 'state' => self::STATE_EMPTY, 'data' => array( 'jobs' => 0 ) );
			}
			return array( 'state' => self::STATE_OK, 'data' => array( 'jobs' => $total, 'by_status' => $counts ) );
		}

		/**
		 * WORK: the RECORD — photos + notes under the per-component gate (B5). Counts span EVERY
		 * component (a count discloses nothing); the visible photo strip and the notes come ONLY from
		 * jobs this viewer could open in Jobs. INV-12: the panel states when it holds FEWER pictures
		 * than the count — a short strip under a big number is a silent lie. Every media URL is a
		 * secure token minted by ZJOB_Photos::for_jobs (never a raw uploads URL — geo-PII); notes are
		 * top-level with NO include-internal flag anywhere. The MODEL never sees this panel — the model
		 * path (Zjob_Project::summarize_project) is counts-only by an allow-list.
		 */
		public static function src_record( int $viewer, array $project, bool $see_money ): array {
			unset( $see_money );
			$pid = (string) ( $project['id'] ?? '' );
			if ( '' === $pid || ! class_exists( 'Zjob_Project' ) ) {
				return array( 'state' => self::STATE_UNAVAILABLE, 'data' => null );
			}
			$jobs = Zjob_Project::jobs_for( $pid );
			if ( empty( $jobs ) ) {
				return array( 'state' => self::STATE_EMPTY, 'data' => array( 'photo_count' => 0, 'note_count' => 0 ) );
			}

			// Counts span EVERY component regardless of who may open it (a count discloses nothing).
			$all_ids = array();
			foreach ( $jobs as $j ) {
				$id = (int) ( $j['id'] ?? 0 );
				if ( $id > 0 ) {
					$all_ids[] = $id;
				}
			}
			$photo_count = ( class_exists( 'ZJOB_Photos' ) && method_exists( 'ZJOB_Photos', 'counts_for' ) )
				? (int) array_sum( ZJOB_Photos::counts_for( $jobs ) ) : 0;
			$note_count  = ( class_exists( 'ZJOB_Notes' ) && method_exists( 'ZJOB_Notes', 'counts_for' ) )
				? (int) array_sum( ZJOB_Notes::counts_for( $all_ids ) ) : 0;

			// THE PER-COMPONENT GATE: assets come ONLY from jobs this viewer could open in Jobs.
			$openable_ids  = self::openable_job_ids( $jobs, $viewer );
			$openable_set  = array_fill_keys( $openable_ids, true );
			$openable_jobs = array();
			foreach ( $jobs as $j ) {
				if ( isset( $openable_set[ (int) ( $j['id'] ?? 0 ) ] ) ) {
					$openable_jobs[] = $j;
				}
			}

			// Photos — confirmed-only, ONLY openable jobs; for_jobs already mints secure URLs.
			$photos = array();
			if ( ! empty( $openable_jobs ) && class_exists( 'ZJOB_Photos' ) && method_exists( 'ZJOB_Photos', 'for_jobs' ) ) {
				foreach ( ZJOB_Photos::for_jobs( $openable_jobs, array( 'min_confidence' => 'confirmed' ) ) as $jid => $list ) {
					foreach ( (array) $list as $m ) {
						$url = (string) ( $m['url'] ?? '' );
						if ( '' === $url ) {
							continue; // never a raw fallback
						}
						$photos[] = array(
							'job_id'      => (int) $jid,
							'media_id'    => (int) ( $m['media_id'] ?? 0 ),
							'url'         => $url,
							'thumb_url'   => (string) ( $m['thumb_url'] ?? $url ),
							'captured_at' => (string) ( $m['captured_at'] ?? '' ),
							// geo coords deliberately dropped — the panel shows the image, not a map pin.
						);
					}
				}
			}

			// Notes — top-level only, ONLY openable ids (there is NO include-internal flag anywhere).
			$notes = array();
			if ( ! empty( $openable_ids ) && class_exists( 'ZJOB_Notes' ) && method_exists( 'ZJOB_Notes', 'top_level_for_jobs' ) ) {
				foreach ( ZJOB_Notes::top_level_for_jobs( $openable_ids ) as $n ) {
					if ( ! is_array( $n ) ) {
						continue;
					}
					$notes[] = array(
						'id'         => (int) ( $n['id'] ?? 0 ),
						'job_id'     => (int) ( $n['job_id'] ?? 0 ),
						'author_id'  => (int) ( $n['author_id'] ?? 0 ),
						'visibility' => (string) ( $n['visibility'] ?? '' ),
						'body'       => (string) ( $n['body'] ?? '' ),
						'created_at' => (string) ( $n['created_at'] ?? '' ),
					);
				}
			}

			$shown_photos = count( $photos );
			return array(
				'state' => self::STATE_OK,
				'data'  => array(
					'photo_count'    => $photo_count, // spans all components
					'note_count'     => $note_count,  // spans all components
					'photos'         => $photos,      // only openable, secure URLs
					'notes'          => $notes,       // only openable, top-level
					'shown_photos'   => $shown_photos,
					'strip_is_short' => ( $shown_photos < $photo_count ), // INV-12: honest short strip
					'openable_jobs'  => count( $openable_ids ),
					'total_jobs'     => count( $all_ids ),
				),
			);
		}

		/* ===================================================================
		 * INTERNAL
		 * =================================================================== */

		/** The estimate ids referenced by a project (from its estimate/document refs). */
		private static function estimate_ids( array $project ): array {
			if ( empty( $project['id'] ) || ! class_exists( 'Zdz_Flow_Refs' ) ) {
				return array();
			}
			$ids = array();
			foreach ( Zdz_Flow_Refs::for( (string) $project['id'], 'estimate', 'document' ) as $r ) {
				$eid = (int) ( $r['external_id'] ?? 0 );
				if ( $eid > 0 ) {
					$ids[] = $eid;
				}
			}
			return array_values( array_unique( $ids ) );
		}

		/**
		 * The money figure for one native invoice, in integer cents. This is a MONEY read and is only
		 * ever reached from inside the gate (src_billing_amount). Returns null when the invoice store
		 * is unavailable or the row is missing.
		 *
		 * @param int $invoice_id
		 * @return array{total_cents:int,paid_cents:int}|null
		 */
		private static function invoice_amount_cents( int $invoice_id ): ?array {
			global $wpdb;
			if ( $invoice_id <= 0 || ! isset( $wpdb ) || ! class_exists( 'ZEST_DB' ) ) {
				return null;
			}
			$table = ZEST_DB::invoices_table();
			$row   = $wpdb->get_row(
				$wpdb->prepare( "SELECT total_amount, amount_paid FROM {$table} WHERE id = %d", $invoice_id ),
				ARRAY_A
			);
			if ( ! is_array( $row ) ) {
				return null;
			}
			return array(
				'total_cents' => (int) round( ( (float) $row['total_amount'] ) * 100 ),
				'paid_cents'  => (int) round( ( (float) $row['amount_paid'] ) * 100 ),
			);
		}
	}
}
