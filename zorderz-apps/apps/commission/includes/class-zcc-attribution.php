<?php
/**
 * ZCC_Attribution — whose sale, whose job: the one place pay asks "who is this money for?".
 *
 * Commission and piece-rate pay go only to the person a sale or a job is attributed to. An
 * invoice with nobody, or with people its records disagree on, is a REVIEW ITEM: nobody is
 * paid on it until a person settles it. It is never given to the owner, the asker, the
 * "closest" person or a default.
 *
 * SALE (commission), in order:
 *   1. The codes on the document ("(AB)", "(AB/CD)"), each resolved to a configured plan
 *      (ZDZ_Compensation::plan_by_code). Reserved place/source tokens are never people. Two or
 *      more resolved codes make a shared job, split by ZCC_Split.
 *   2. A rep override row (ZCC_Rep_Overrides) — a person's explicit assignment — when the
 *      document has no code that resolves, or to settle a disagreement (below).
 *   3. A recorded assignment from another app (the estimate's salesperson, a confirmed
 *      Territories decision), answered on the `zcc_attribution_people` filter. Used when there
 *      is no document code and no override; exactly one person is required.
 *   If the document names people and a recorded assignment names only others, the records
 *   disagree: a review item, unless an override row settles it.
 *
 * INSTALL (piece-rate pay):
 *   1. The job's confirmed installer(s), answered on `zcc_attribution_people` with role
 *      'install' (the Jobs app's confirmed installer, a Territories install decision). Two
 *      installers are paid only when each entry says which items are theirs (`items`);
 *      otherwise the job is a review item.
 *   2. With no recorded installer, the Core piece-rate mode (`per_job`) makes the job a review
 *      item. A business that sets the mode `company_wide` gives such a job to the piece worker
 *      only when exactly one person is configured as a piece worker. Two or more: a review
 *      item (never split, never paid twice).
 *   Team Roles, when in use (at least one mark exists), must mark the installer "Can" install
 *   each item; units of an item they are not marked for are withheld for review
 *   (ZCC_Installer_Pay). With no marks at all the check is skipped and the pay screen says so.
 *
 * An inferred person (bare initials in prose, a guess) is never payable here.
 *
 * Each `zcc_attribution_people` answer is a list of [ 'user_id' => int, 'source' => string,
 * 'items' => string[] (optional, install only) ].
 *
 * @package Zorderz\Commission
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZCC_Attribution {

	/**
	 * Tests install a callable( int $invoice_id ) => string override code here. Production
	 * leaves it null and reads ZCC_Rep_Overrides.
	 *
	 * @var callable|null
	 */
	public static $override_lookup = null;

	/**
	 * Who this invoice's money is for, in one role.
	 *
	 * @param array  $inv  A normalised invoice (ZCC_FreshBooks::normalize_invoice()).
	 * @param string $role 'sale' | 'install'.
	 * @return array{ role:string, status:string, people:int[], items:array, codes:string[],
	 *   unresolved_codes:string[], source:string, reason:string }
	 *   status: one | shared | none | ambiguous. Only one and shared pay anyone.
	 */
	public static function resolve( array $inv, string $role = 'sale' ): array {
		$role = 'install' === $role ? 'install' : 'sale';
		$out  = [
			'role'             => $role,
			'status'           => 'none',
			'people'           => [],
			'items'            => [],
			'codes'            => [],
			'unresolved_codes' => [],
			'source'           => '',
			'reason'           => '',
		];
		$external = self::external( $inv, $role );

		if ( 'install' === $role ) {
			return self::resolve_install( $out, $external );
		}

		// 1. The document's codes.
		$reserved = class_exists( 'ZDZ_Compensation' ) ? array_map( 'strtoupper', (array) ( ZDZ_Compensation::attribution()['reserved_tokens'] ?? [] ) ) : [];
		$codes    = class_exists( 'ZCC_Split' ) ? ZCC_Split::normalize_codes( (array) ( $inv['salesperson_codes'] ?? [] ) ) : [];
		$codes    = array_values( array_diff( $codes, $reserved ) );
		$doc      = [];
		$doc_codes = [];
		foreach ( $codes as $c ) {
			$pid = self::party_for_code( $c );
			if ( $pid > 0 ) {
				if ( ! in_array( $pid, $doc, true ) ) {
					$doc[] = $pid;
				}
				$doc_codes[] = $c;
			} else {
				$out['unresolved_codes'][] = $c;
			}
		}
		$out['codes'] = $doc_codes;

		// 2. A person's override row.
		$ov_code  = self::override_code( (int) ( $inv['invoice_id'] ?? 0 ) );
		$override = '' !== $ov_code ? self::party_for_code( $ov_code ) : 0;

		// 3. A recorded assignment from another app.
		$ext = array_values( array_unique( array_column( $external, 'user_id' ) ) );

		if ( $doc ) {
			if ( $ext && ! array_intersect( $doc, $ext ) ) {
				if ( $override > 0 ) {
					return self::one( $out, $override, 'override', 'override_settles' );
				}
				$out['status'] = 'ambiguous';
				$out['people'] = array_values( array_unique( array_merge( $doc, $ext ) ) );
				$out['reason'] = 'document_disagrees';
				return $out;
			}
			$out['status'] = count( $doc ) > 1 ? 'shared' : 'one';
			$out['people'] = $doc;
			$out['source'] = 'document_code';
			$out['reason'] = $out['unresolved_codes'] ? 'some_codes_unknown' : '';
			return $out;
		}
		if ( $override > 0 ) {
			return self::one( $out, $override, 'override', '' );
		}
		if ( $codes ) {
			$out['reason'] = 'unknown_code';
			return $out;
		}
		if ( 1 === count( $ext ) ) {
			return self::one( $out, (int) $ext[0], (string) ( $external[0]['source'] ?? 'recorded' ), '' );
		}
		if ( count( $ext ) > 1 ) {
			$out['status'] = 'ambiguous';
			$out['people'] = $ext;
			$out['reason'] = 'several_people';
			return $out;
		}
		$out['reason'] = 'no_attribution';
		return $out;
	}

	private static function resolve_install( array $out, array $external ): array {
		$people = [];
		$items  = [];
		$all_have_items = true;
		foreach ( $external as $e ) {
			$uid = (int) $e['user_id'];
			if ( ! in_array( $uid, $people, true ) ) {
				$people[] = $uid;
			}
			if ( empty( $e['items'] ) ) {
				$all_have_items = false;
			} else {
				$items[ $uid ] = array_values( array_unique( array_merge( $items[ $uid ] ?? [], (array) $e['items'] ) ) );
			}
		}
		if ( 1 === count( $people ) ) {
			$out          = self::one( $out, $people[0], (string) ( $external[0]['source'] ?? 'recorded' ), '' );
			$out['items'] = $items;
			return $out;
		}
		if ( count( $people ) > 1 ) {
			if ( $all_have_items ) {
				$out['status'] = 'shared';
				$out['people'] = $people;
				$out['items']  = $items;
				$out['source'] = (string) ( $external[0]['source'] ?? 'recorded' );
				return $out;
			}
			$out['status'] = 'ambiguous';
			$out['people'] = $people;
			$out['reason'] = 'several_installers';
			return $out;
		}
		$mode = class_exists( 'ZDZ_Compensation' ) ? (string) ( ZDZ_Compensation::piece_rate_eligibility()['mode'] ?? '' ) : '';
		if ( 'company_wide' === $mode ) {
			$workers = self::piece_workers();
			if ( 1 === count( $workers ) ) {
				return self::one( $out, $workers[0], 'only_piece_worker', '' );
			}
			if ( count( $workers ) > 1 ) {
				$out['status'] = 'ambiguous';
				$out['people'] = $workers;
				$out['reason'] = 'several_piece_workers';
				return $out;
			}
		}
		$out['reason'] = 'no_installer';
		return $out;
	}

	private static function one( array $out, int $pid, string $source, string $reason ): array {
		$out['status'] = 'one';
		$out['people'] = [ $pid ];
		$out['source'] = $source;
		$out['reason'] = $reason;
		return $out;
	}

	/** Does this resolution pay this person? Only `one` and `shared`, and only someone named. */
	public static function pays( array $res, int $party_id ): bool {
		return $party_id > 0 && in_array( $res['status'] ?? '', [ 'one', 'shared' ], true )
			&& in_array( $party_id, array_map( 'intval', (array) ( $res['people'] ?? [] ) ), true );
	}

	/**
	 * Split invoices into the ones attributed to this person (sale) and the review items.
	 * An invoice attributed by override or a recorded assignment is handed to the engine with
	 * no document codes, so it is that one person's solo sale.
	 *
	 * @return array{ matched:array, review:array }
	 */
	public static function partition( array $invoices, int $party_id ): array {
		$matched = [];
		$review  = [];
		foreach ( $invoices as $inv ) {
			if ( ! is_array( $inv ) ) {
				continue;
			}
			$res = self::resolve( $inv, 'sale' );
			if ( in_array( $res['status'], [ 'none', 'ambiguous' ], true ) ) {
				$review[] = self::review_row( $inv, $res );
				continue;
			}
			if ( ! self::pays( $res, $party_id ) ) {
				continue;
			}
			if ( 'document_code' !== $res['source'] ) {
				$inv['salesperson_codes'] = [];
			}
			$inv['attribution'] = $res;
			$matched[]          = $inv;
		}
		return [ 'matched' => $matched, 'review' => $review ];
	}

	/** A review item: ids, the number, the reason and the people involved — never a customer's details. */
	public static function review_row( array $inv, array $res ): array {
		return [
			'invoice_id'     => (int) ( $inv['invoice_id'] ?? 0 ),
			'invoice_number' => (string) ( $inv['invoice_number'] ?? '' ),
			'role'           => (string) $res['role'],
			'status'         => (string) $res['status'],
			'reason'         => (string) $res['reason'],
			'people'         => array_map( 'intval', (array) $res['people'] ),
			'codes'          => array_values( array_merge( (array) $res['codes'], (array) $res['unresolved_codes'] ) ),
		];
	}

	/** What each review reason means, for the screens. */
	public static function reason_text( string $reason ): string {
		$t = [
			'no_attribution'        => __( 'No salesperson code, override or recorded salesperson: nobody is paid until one is set.', 'zorderz' ),
			'unknown_code'          => __( 'The code on the invoice matches nobody with a plan: nobody is paid until it is fixed or overridden.', 'zorderz' ),
			'document_disagrees'    => __( 'The invoice code and the recorded salesperson are different people: nobody is paid until a person settles it with an override.', 'zorderz' ),
			'several_people'        => __( 'More than one person is recorded for this sale: nobody is paid until a person settles it.', 'zorderz' ),
			'no_installer'          => __( 'No installer is recorded for this job: its units are not paid until one is.', 'zorderz' ),
			'several_installers'    => __( 'Two installers are recorded without saying whose items are whose: the units are not paid until a person settles it.', 'zorderz' ),
			'several_piece_workers' => __( 'More than one piece worker could have done this job: its units are not paid until an installer is recorded.', 'zorderz' ),
			'not_marked'            => __( 'The installer is not marked able to install this item in Team Roles: those units are held for review.', 'zorderz' ),
		];
		return $t[ $reason ] ?? __( 'Needs a person to look.', 'zorderz' );
	}

	/** The party a short code resolves to through a configured plan, or 0. */
	private static function party_for_code( string $code ): int {
		if ( ! class_exists( 'ZDZ_Compensation' ) ) {
			return 0;
		}
		$plan = ZDZ_Compensation::plan_by_code( strtoupper( trim( $code ) ) );
		return is_array( $plan ) ? (int) ( $plan['party_id'] ?? 0 ) : 0;
	}

	private static function override_code( int $invoice_id ): string {
		if ( $invoice_id <= 0 ) {
			return '';
		}
		if ( is_callable( self::$override_lookup ) ) {
			return strtoupper( trim( (string) call_user_func( self::$override_lookup, $invoice_id ) ) );
		}
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! class_exists( 'ZCC_Rep_Overrides' ) ) {
			return '';
		}
		return ZCC_Rep_Overrides::get_code( $invoice_id );
	}

	/** Configured piece workers' party ids. */
	private static function piece_workers(): array {
		if ( ! class_exists( 'ZDZ_Compensation' ) ) {
			return [];
		}
		$out = [];
		foreach ( (array) ZDZ_Compensation::all_plans() as $p ) {
			if ( ! empty( $p['is_piece_worker'] ) && (int) ( $p['party_id'] ?? 0 ) > 0 ) {
				$out[] = (int) $p['party_id'];
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** The recorded people another app answers for this invoice, cleaned. */
	private static function external( array $inv, string $role ): array {
		$raw = apply_filters( 'zcc_attribution_people', [], $inv, $role );
		$out = [];
		foreach ( is_array( $raw ) ? $raw : [] as $e ) {
			if ( ! is_array( $e ) || (int) ( $e['user_id'] ?? 0 ) <= 0 ) {
				continue;
			}
			$out[] = [
				'user_id' => (int) $e['user_id'],
				'source'  => sanitize_key( (string) ( $e['source'] ?? 'recorded' ) ),
				'items'   => array_values( array_filter( array_map( 'sanitize_key', (array) ( $e['items'] ?? [] ) ), 'strlen' ) ),
			];
		}
		return $out;
	}

	/**
	 * The Territories answer on `zcc_attribution_people`: a decision a person or the estimate
	 * app confirmed (confirmed_user_id), for the estimate this invoice carries, the latest one,
	 * never a test and never a shadow guess. Stage 'stub' answers the sale, 'install' the job.
	 */
	public static function territories_people( $people, $inv, $role ) {
		$people = is_array( $people ) ? $people : [];
		if ( ! class_exists( 'ZTER_DB' ) || ! is_array( $inv ) ) {
			return $people;
		}
		$doc = trim( (string) ( $inv['estimate_number'] ?? '' ) );
		if ( '' === $doc ) {
			$doc = trim( (string) ( $inv['reference'] ?? '' ) );
		}
		if ( '' === $doc || ! preg_match( '/^[A-Za-z0-9-]{1,40}$/', $doc ) ) {
			return $people;
		}
		global $wpdb;
		if ( ! is_object( $wpdb ) ) {
			return $people;
		}
		$stage = 'install' === $role ? 'install' : 'stub';
		$row   = $wpdb->get_row( $wpdb->prepare(
			'SELECT confirmed_user_id, confirmed_people FROM ' . ZTER_DB::t( 'decisions' ) . ' WHERE stage = %s AND is_test = 0 AND confirmed_user_id > 0 AND ( estimate_number = %s OR applied_doc = %s ) ORDER BY id DESC LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- ZTER_DB::t( 'decisions' ) is $wpdb->prefix plus a fixed table name
			$stage, $doc, $doc
		), ARRAY_A );
		if ( is_array( $row ) ) {
			// The latest confirmed decision only. A pair names both people (with no items each),
			// which the resolver treats as a review item rather than paying one of them for all.
			$ids = array_filter( array_map( 'intval', explode( ',', (string) ( $row['confirmed_people'] ?? '' ) ) ) );
			$ids = $ids ? $ids : [ (int) $row['confirmed_user_id'] ];
			foreach ( array_unique( $ids ) as $id ) {
				$people[] = [ 'user_id' => (int) $id, 'source' => 'territories' ];
			}
		}
		return $people;
	}
}
