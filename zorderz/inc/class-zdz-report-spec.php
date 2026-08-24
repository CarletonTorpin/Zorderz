<?php
/**
 * ZDZ_Report_Spec — the report/chart spec VALIDATOR. This class is a SECURITY BOUNDARY.
 *
 * A report spec (source, row-entity, window, filters, axis) is produced by a dictation model
 * or arrives from a client. NOTHING in it is trusted. Every field is checked against the
 * registered source's allow-lists before any query is built; an un-allow-listed source, entity
 * (grouping), filter field, axis or bucket is REFUSED (a WP_Error), never sanitized-into-a-query.
 * A too-wide, future-only, or malformed window is refused. The refusal diagnostic NEVER contains
 * a credential value.
 *
 * PURE by design: no DB, no network, no tenant timezone. It validates STRUCTURE and returns a
 * sanitized spec or a WP_Error, so it is unit-testable without WordPress. The money-entitlement
 * gate and the timezone-aware window→epoch conversion live one layer up, in ZDZ_Report_Sources,
 * which calls this first and refuses to gather anything the model was not allowed to ask for.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ZDZ_Report_Spec' ) ) {

	final class ZDZ_Report_Spec {

		/** Marker key stamped onto a spec once (and only once) it has passed validation. */
		const STAMP = '__zdz_report_validated';

		/** Row-entity dimensions that resolve to a PERSON via the attribution contract. */
		const PARTY_ENTITIES = array( 'rep', 'party', 'salesperson', 'actor', 'owner' );

		/** The only supported axes and buckets this release. */
		const AXES    = array( 'count', 'amount' );
		const BUCKETS = array( 'day' );

		/** Default hard ceiling on a window span (days). Tenant-tunable via zdz_report_max_span_days. */
		const DEFAULT_MAX_SPAN_DAYS = 400;

		/**
		 * Validate a raw spec against a normalized source descriptor.
		 *
		 * @param array $spec   The untrusted spec.
		 * @param array $source A normalized descriptor from ZDZ_Report_Sources::source().
		 * @return array|WP_Error  A sanitized spec (NO stamp — the caller stamps after entitlement),
		 *                         or a WP_Error naming the first violation.
		 */
		public static function validate( array $spec, array $source ) {
			$key = (string) ( $source['key'] ?? '' );
			if ( '' === $key || empty( $source['entities'] ) || ! is_array( $source['entities'] ) ) {
				return self::err( 'zdz_report_bad_source', 'The report source is not a usable registered source.' );
			}

			// ── entity (the GROUP / row dimension) — must be allow-listed by the source ──
			$entity = isset( $spec['entity'] ) ? self::slug( $spec['entity'] ) : '';
			if ( '' === $entity ) {
				return self::err( 'zdz_report_missing_entity', 'The report is missing a row entity.' );
			}
			if ( ! in_array( $entity, array_map( array( __CLASS__, 'slug' ), $source['entities'] ), true ) ) {
				// The exact "un-allow-listed group" refusal. The requested entity is NOT queried.
				return self::err(
					'zdz_report_unlisted_entity',
					'That grouping is not available for this report source.',
					array( 'entity' => $entity )
				);
			}

			// ── axis ── 'amount' is the money axis (entitlement enforced upstream) ──
			$axis = isset( $spec['axis'] ) ? self::slug( $spec['axis'] ) : 'count';
			if ( ! in_array( $axis, self::AXES, true ) ) {
				return self::err( 'zdz_report_bad_axis', 'Unknown report axis.', array( 'axis' => $axis ) );
			}
			// A money axis is only meaningful when the source declared where the amount lives.
			if ( 'amount' === $axis && '' === (string) ( $source['amount_path'] ?? '' ) ) {
				return self::err( 'zdz_report_no_amount', 'This source has no money axis.' );
			}

			// ── bucket ──
			$bucket = isset( $spec['bucket'] ) ? self::slug( $spec['bucket'] ) : 'day';
			if ( ! in_array( $bucket, self::BUCKETS, true ) ) {
				return self::err( 'zdz_report_bad_bucket', 'Unknown report bucket.', array( 'bucket' => $bucket ) );
			}

			// ── window ── bounded, well-formed, not future-only ──
			$window = self::validate_window( is_array( $spec['window'] ?? null ) ? $spec['window'] : array() );
			if ( self::is_error( $window ) ) {
				return $window;
			}

			// ── filters ── every filter FIELD must be on the source's allow-list ──
			$filters   = array();
			$raw_filts = is_array( $spec['filters'] ?? null ) ? $spec['filters'] : array();
			$allowed_f = array_map( array( __CLASS__, 'slug' ), (array) ( $source['filterable'] ?? array() ) );
			foreach ( $raw_filts as $fk => $fv ) {
				$fk = self::slug( $fk );
				if ( '' === $fk ) {
					continue;
				}
				if ( ! in_array( $fk, $allowed_f, true ) ) {
					// The exact "un-allow-listed field" refusal. Nothing is queried.
					return self::err(
						'zdz_report_unlisted_filter',
						'That filter field is not allowed for this report source.',
						array( 'field' => $fk )
					);
				}
				// Values are coerced to a scalar string; a filter value is never eval'd or interpolated raw.
				$filters[ $fk ] = is_scalar( $fv ) ? (string) $fv : '';
			}

			// Sanitized spec — ONLY allow-listed fields survive. No stamp yet.
			return array(
				'source'  => $key,
				'entity'  => $entity,
				'axis'    => $axis,
				'bucket'  => $bucket,
				'window'  => $window,
				'filters' => $filters,
			);
		}

		/**
		 * Validate the {from,to} window: strict Y-m-d, from<=to, span<=ceiling, not future-only.
		 *
		 * @return array|WP_Error {from:'Y-m-d', to:'Y-m-d', span_days:int}
		 */
		public static function validate_window( array $window ) {
			$from = self::ymd( $window['from'] ?? '' );
			$to   = self::ymd( $window['to'] ?? '' );
			if ( '' === $from || '' === $to ) {
				return self::err( 'zdz_report_bad_window', 'The report window must have a valid from and to date (YYYY-MM-DD).' );
			}
			if ( strcmp( $from, $to ) > 0 ) {
				return self::err( 'zdz_report_window_order', 'The report window ends before it begins.' );
			}

			// Span ceiling (whole days, timezone-independent since both are UTC-anchored dates here).
			$day       = defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;
			$span_days = (int) round( ( strtotime( $to . ' 00:00:00 UTC' ) - strtotime( $from . ' 00:00:00 UTC' ) ) / $day ) + 1;
			$max       = self::max_span_days();
			if ( $span_days > $max ) {
				return self::err(
					'zdz_report_window_too_wide',
					'The report window is wider than allowed.',
					array( 'span_days' => $span_days, 'max_span_days' => $max )
				);
			}

			// Not future-only: the window must begin on or before today (UTC). A window entirely in
			// the future can only describe events that have not happened — refuse it rather than
			// return an empty grid that looks like "no sales".
			$today = gmdate( 'Y-m-d' );
			if ( strcmp( $from, $today ) > 0 ) {
				return self::err( 'zdz_report_window_future', 'The report window is entirely in the future.' );
			}

			return array( 'from' => $from, 'to' => $to, 'span_days' => $span_days );
		}

		/** True once a spec carries the validation stamp with the expected shape. */
		public static function has_stamp( array $spec ): bool {
			return isset( $spec[ self::STAMP ] ) && is_string( $spec[ self::STAMP ] ) && '' !== $spec[ self::STAMP ];
		}

		/* ── helpers ───────────────────────────────────────────────────────── */

		/** A strict Y-m-d or '' — rejects '2026-13-40', '2026-1-1', and any non-date string. */
		public static function ymd( $v ): string {
			$v = is_string( $v ) ? trim( $v ) : '';
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ) {
				return '';
			}
			$parts = explode( '-', $v );
			if ( ! checkdate( (int) $parts[1], (int) $parts[2], (int) $parts[0] ) ) {
				return '';
			}
			return $v;
		}

		/** Lower-case [a-z0-9_.-] slug — the shape every allow-list key is compared in. */
		public static function slug( $v ): string {
			$v = is_string( $v ) ? strtolower( trim( $v ) ) : ( is_scalar( $v ) ? strtolower( (string) $v ) : '' );
			$v = preg_replace( '/[^a-z0-9_.\-]/', '', $v );
			return (string) $v;
		}

		private static function max_span_days(): int {
			$m = function_exists( 'apply_filters' ) ? apply_filters( 'zdz_report_max_span_days', self::DEFAULT_MAX_SPAN_DAYS ) : self::DEFAULT_MAX_SPAN_DAYS;
			$m = (int) $m;
			return $m > 0 ? $m : self::DEFAULT_MAX_SPAN_DAYS;
		}

		/** Build a WP_Error (or a compatible stand-in when WP is absent) — never leaks a value. */
		private static function err( string $code, string $message, array $data = array() ) {
			$data['status'] = $data['status'] ?? 400;
			if ( class_exists( 'WP_Error' ) ) {
				return new WP_Error( $code, $message, $data );
			}
			// Minimal stand-in for a no-WordPress context; is_error() below recognizes it.
			return (object) array( 'zdz_error' => true, 'code' => $code, 'message' => $message, 'data' => $data );
		}

		/** True for a WP_Error or the stand-in above. */
		public static function is_error( $v ): bool {
			if ( function_exists( 'is_wp_error' ) && is_wp_error( $v ) ) {
				return true;
			}
			return is_object( $v ) && isset( $v->zdz_error ) && $v->zdz_error;
		}
	}
}
