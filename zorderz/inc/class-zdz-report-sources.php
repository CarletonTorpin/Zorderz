<?php
/**
 * ZDZ_Report_Sources — the report/event-source REGISTRY, the money-entitlement gate, the
 * visibility-respecting Flow-outbox READER, and the attribution resolver for the dot-plot.
 *
 * Model / architecture
 * --------------------
 *   sources()  A declarative registry. Each source declares a plottable Flow event
 *              (which outbox event_type(s), which row entities it may group by, its
 *              visibility ceiling, whether it is a money source). Registered via the
 *              `zdz_report_sources` filter — mirroring zdz_register_apps / zdz_kpi_metrics.
 *              CORE SHIPS EMPTY: no estimate.* / invoice.* / sale stage vocabulary is named
 *              here (that is Identity → flows/mappings). Adding a stage is one array entry;
 *              the validator, the reader and the visibility gate are untouched.
 *
 *   validate() THE SECURITY BOUNDARY. Delegates structural allow-listing to ZDZ_Report_Spec,
 *              then enforces the money entitlement (an unentitled viewer is refused BEFORE any
 *              gathering), then stamps the spec. A spec that has not passed here cannot be read.
 *
 *   read()     Reads wp_zdz_flow_outbox (the Wave-B Zdz_Flow writer's event outbox) by
 *              event_type / visibility / window. NEVER a network or provider API call — the
 *              mirror is provider-truth via the Connections importer that emits Flow transitions;
 *              "if the sync is behind, the plot is out of date; it never becomes slow, and it
 *              cannot take a request down." Visibility is enforced twice: in the SQL WHERE and
 *              again per-row in PHP, so a customer-audience reader can never select an internal
 *              event even if the query is later mutated.
 *
 *   Attribution reuses ZDZ_Compensation::attribution() so the grid and the commission ledger
 *              agree by construction; an unresolvable dot lands on the explicit "Unattributed"
 *              row (never hidden — hiding it would make the grid lie about volume), and a missing
 *              override resolver is a LOGGED disposition, never a silent fall-through to inference.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ZDZ_Report_Sources' ) ) {

	class ZDZ_Report_Sources {

		/** Filter apps/Identity use to declare plottable event sources. Core default: []. */
		const SOURCES_FILTER = 'zdz_report_sources';

		/** The money-visibility permission (fail-closed, reused from the KPI redaction floor). */
		const MONEY_PERMISSION = 'view_company_revenue';

		/** Hard row cap on the outbox scan — a report can never take the request down. */
		const DEFAULT_ROW_CAP = 5000;

		/** Visibility tiers ordered widest-audience LAST (customer is seen by the most viewers). */
		const VIS_INTERNAL = 'internal';
		const VIS_STAFF    = 'staff';
		const VIS_CUSTOMER = 'customer';

		/** Per-process salt so a client cannot forge the validation stamp. */
		private static $run_salt = '';

		/** Cache of the normalized source registry for one request. */
		private static $sources_cache = null;

		/* =====================================================================
		 * REGISTRY
		 * ===================================================================== */

		/**
		 * The normalized source registry, merged from the `zdz_report_sources` filter.
		 * Ships EMPTY. Malformed descriptors are dropped (a source with no key or no entities
		 * cannot be plotted).
		 *
		 * @return array<string,array> keyed by source key.
		 */
		public static function sources(): array {
			if ( is_array( self::$sources_cache ) ) {
				return self::$sources_cache;
			}
			$raw = function_exists( 'apply_filters' ) ? apply_filters( self::SOURCES_FILTER, array() ) : array();
			$out = array();
			foreach ( (array) $raw as $descriptor ) {
				$n = self::normalize_source( is_array( $descriptor ) ? $descriptor : array() );
				if ( null !== $n ) {
					$out[ $n['key'] ] = $n;
				}
			}
			self::$sources_cache = $out;
			return $out;
		}

		/** One normalized source descriptor, or null if unregistered. */
		public static function source( string $key ): ?array {
			$key = ZDZ_Report_Spec::slug( $key );
			$all = self::sources();
			return $all[ $key ] ?? null;
		}

		/** True when the named source is money-class (its dots can carry revenue). */
		public static function is_money_source( string $key ): bool {
			$s = self::source( $key );
			return $s ? ! empty( $s['money_class'] ) : false;
		}

		/** Drop the request-level registry cache (after a filter changes, or between tests). */
		public static function flush(): void {
			self::$sources_cache = null;
		}

		/**
		 * Normalize one raw descriptor into the canonical shape (or null when unusable).
		 * `stage_key` is accepted as a single-event alias for `event_types`.
		 */
		private static function normalize_source( array $d ): ?array {
			$key = ZDZ_Report_Spec::slug( $d['key'] ?? '' );
			if ( '' === $key ) {
				return null;
			}
			$entities = array();
			foreach ( (array) ( $d['entities'] ?? array() ) as $e ) {
				$e = ZDZ_Report_Spec::slug( $e );
				if ( '' !== $e ) {
					$entities[] = $e;
				}
			}
			if ( empty( $entities ) ) {
				return null; // nothing to group the dots by.
			}

			$event_types = array();
			foreach ( (array) ( $d['event_types'] ?? array() ) as $t ) {
				$t = is_string( $t ) ? trim( $t ) : '';
				if ( '' !== $t ) {
					$event_types[] = $t;
				}
			}
			if ( empty( $event_types ) && ! empty( $d['stage_key'] ) ) {
				$event_types[] = (string) $d['stage_key'];
			}

			$filterable = array();
			foreach ( (array) ( $d['filterable'] ?? array() ) as $f ) {
				$f = ZDZ_Report_Spec::slug( $f );
				if ( '' !== $f ) {
					$filterable[] = $f;
				}
			}

			$entity_paths = array();
			foreach ( (array) ( $d['entity_paths'] ?? array() ) as $ent => $path ) {
				$entity_paths[ ZDZ_Report_Spec::slug( $ent ) ] = is_string( $path ) ? $path : '';
			}

			return array(
				'key'              => $key,
				'label'            => isset( $d['label'] ) ? (string) $d['label'] : $key,
				'entities'         => array_values( array_unique( $entities ) ),
				'family'           => isset( $d['family'] ) ? ZDZ_Report_Spec::slug( $d['family'] ) : '',
				'stage'            => isset( $d['stage'] ) ? ZDZ_Report_Spec::slug( $d['stage'] ) : '',
				'event_types'      => array_values( array_unique( $event_types ) ),
				'visibility'       => self::clamp_visibility( $d['visibility'] ?? self::VIS_INTERNAL ),
				'money_class'      => ! empty( $d['money_class'] ),
				'filterable'       => array_values( array_unique( $filterable ) ),
				'date_path'        => isset( $d['date_path'] ) ? (string) $d['date_path'] : '',
				'amount_path'      => isset( $d['amount_path'] ) ? (string) $d['amount_path'] : '',
				'entity_paths'     => $entity_paths,
				'attribution_path' => isset( $d['attribution_path'] ) ? (string) $d['attribution_path'] : '',
				'callback'         => is_callable( $d['callback'] ?? null ) ? $d['callback'] : null,
			);
		}

		/* =====================================================================
		 * VALIDATE — the security boundary (structural allow-list + money gate + stamp)
		 * ===================================================================== */

		/**
		 * Validate an untrusted spec. Returns a sanitized, STAMPED spec ready for read(), or a
		 * WP_Error naming the first violation. A money source / money axis is refused here for an
		 * unentitled viewer BEFORE anything is gathered.
		 *
		 * @param array $spec Untrusted spec. `__viewer` (int) may be set by the server; the model
		 *                    never sets it — if absent the current user is used.
		 * @return array|WP_Error
		 */
		public static function validate( array $spec ) {
			$key    = isset( $spec['source'] ) ? (string) $spec['source'] : (string) ( $spec['key'] ?? '' );
			$source = self::source( $key );
			if ( ! $source ) {
				return self::error( 'zdz_report_unknown_source', 'Unknown or unregistered report source.', array( 'status' => 400 ) );
			}

			$clean = ZDZ_Report_Spec::validate( $spec, $source );
			if ( ZDZ_Report_Spec::is_error( $clean ) ) {
				return $clean;
			}

			// ── money entitlement — decided BEFORE any gather, fail-closed ──
			$viewer = isset( $spec['__viewer'] ) ? (int) $spec['__viewer']
				: ( function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0 );

			if ( 'amount' === $clean['axis'] || self::is_money_source( $key ) ) {
				$can = class_exists( 'ZDZ_Data_Permissions' )
					? (bool) ZDZ_Data_Permissions::can( $viewer, self::MONEY_PERMISSION )
					: false; // no permission layer → withhold money.
				if ( ! $can ) {
					return self::error(
						'zdz_report_forbidden_money',
						'This report includes financial figures you are not permitted to see.',
						array( 'status' => 403 )
					);
				}
			}

			$clean['__viewer'] = $viewer;

			// Carry the tz-aware epoch window (this layer knows the tenant timezone; the pure
			// validator does not).
			list( $from_ts, $to_ts )   = self::window_epoch( $clean['window']['from'], $clean['window']['to'] );
			$clean['window_from_ts']   = $from_ts;
			$clean['window_to_ts']     = $to_ts;

			$clean[ ZDZ_Report_Spec::STAMP ] = self::stamp( $clean );
			return $clean;
		}

		/* =====================================================================
		 * READ — visibility-respecting outbox reader (NO network)
		 * ===================================================================== */

		/**
		 * Read a validated spec into a dot-plot grid over the Flow event outbox.
		 *
		 * ALWAYS re-validates (so there is no path that queries an un-validated spec). Returns a
		 * grid structure, or ['ok'=>false,'error'=>...] on a refusal — in which case NOTHING was
		 * gathered.
		 *
		 * @param array $spec Untrusted or pre-validated spec.
		 * @return array
		 */
		public static function read( array $spec ): array {
			$clean = self::validate( $spec );
			if ( ZDZ_Report_Spec::is_error( $clean ) ) {
				return array(
					'ok'      => false,
					'error'   => self::error_code( $clean ),
					'message' => self::error_message( $clean ),
				);
			}

			$source = self::source( $clean['source'] );
			$viewer = (int) $clean['__viewer'];

			// A custom reader may replace the outbox scan (e.g. a non-Flow source); it still only
			// runs on an already-validated spec.
			if ( $source && is_callable( $source['callback'] ) ) {
				$rows = (array) call_user_func( $source['callback'], $clean, $source );
			} else {
				// Late static binding on the DB seam so the reader is test-injectable; the query
				// builder's assert still runs first, so validate() can never be skipped.
				$rows = static::query_rows( static::build_query_args( $clean, $source ) );
			}

			return self::assemble_grid( $rows, $clean, $source, $viewer );
		}

		/**
		 * Build the parametrized query arguments for the outbox scan. The first line asserts the
		 * spec was validated: a future refactor that hands an un-stamped spec to the reader trips
		 * this under `zend.assertions=1`, so the validate() boundary cannot be skipped silently.
		 */
		protected static function build_query_args( array $clean, ?array $source ): array {
			assert(
				ZDZ_Report_Spec::has_stamp( $clean ) && self::stamp_matches( $clean ),
				'ZDZ_Report_Sources: refusing to query an un-validated spec (validate() was skipped).'
			);

			$audience = self::audience_for_viewer( (int) ( $clean['__viewer'] ?? 0 ) );
			$allowed  = self::allowed_visibilities( $audience, (string) ( $source['visibility'] ?? self::VIS_INTERNAL ) );

			$types = $source ? (array) $source['event_types'] : array();

			return array(
				'tenant'        => self::tenant_id(),
				'event_types'   => $types,
				'visibilities'  => $allowed,
				'ulid_lo'       => self::ulid_floor( (int) $clean['window_from_ts'] ),
				'ulid_hi'       => self::ulid_ceil( (int) $clean['window_to_ts'] ),
				'limit'         => self::row_cap(),
			);
		}

		/**
		 * Run the outbox query. PURE DB — no network. Isolated so a test can override it, and so
		 * the visibility WHERE clause is the first (of two) enforcement points.
		 *
		 * @return array<int,array> raw rows: id, event_type, visibility, envelope.
		 */
		protected static function query_rows( array $args ): array {
			global $wpdb;
			if ( ! isset( $wpdb ) || empty( $args['event_types'] ) || empty( $args['visibilities'] ) ) {
				return array();
			}
			$table = self::outbox_table();

			$type_ph = implode( ',', array_fill( 0, count( $args['event_types'] ), '%s' ) );
			$vis_ph  = implode( ',', array_fill( 0, count( $args['visibilities'] ), '%s' ) );

			$sql = "SELECT id, event_type, visibility, envelope FROM {$table} "
				. "WHERE tenant_id = %d AND event_type IN ({$type_ph}) AND visibility IN ({$vis_ph})";
			$params = array_merge( array( (int) $args['tenant'] ), array_values( $args['event_types'] ), array_values( $args['visibilities'] ) );

			if ( '' !== $args['ulid_lo'] && '' !== $args['ulid_hi'] ) {
				$sql     .= ' AND id >= %s AND id <= %s';
				$params[] = $args['ulid_lo'];
				$params[] = $args['ulid_hi'];
			}
			$sql     .= ' ORDER BY id DESC LIMIT %d';
			$params[] = (int) $args['limit'];

			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
			return is_array( $rows ) ? $rows : array();
		}

		/**
		 * Turn raw outbox rows into a grid, enforcing visibility a SECOND time in PHP (belt and
		 * suspenders: a row the SQL should have excluded is dropped here and logged), placing each
		 * dot on the correct tenant-local day, and resolving the row entity via attribution.
		 */
		private static function assemble_grid( array $rows, array $clean, ?array $source, int $viewer ): array {
			$audience = self::audience_for_viewer( $viewer );
			$allowed  = self::allowed_visibilities( $audience, (string) ( $source['visibility'] ?? self::VIS_INTERNAL ) );

			$entity  = (string) $clean['entity'];
			$axis    = (string) $clean['axis'];
			$from_ts = (int) $clean['window_from_ts'];
			$to_ts   = (int) $clean['window_to_ts'];
			$filters = (array) $clean['filters'];

			// One-time honesty disposition when the attribution precedence expects overrides but no
			// resolver is wired — never a silent fall-through to inference (S8-04).
			self::maybe_warn_unwired_overrides( $entity );

			$cells         = array();
			$row_labels    = array();
			$days_seen     = array();
			$total         = 0;
			$dropped_vis   = 0;
			$considered    = 0;

			foreach ( $rows as $r ) {
				$vis = isset( $r['visibility'] ) ? (string) $r['visibility'] : self::VIS_INTERNAL;
				if ( ! in_array( $vis, $allowed, true ) ) {
					// SECOND enforcement point. In normal operation the SQL already excluded this;
					// reaching here means the query was mutated — drop it and record the drop.
					$dropped_vis++;
					self::disposition( 'visibility_drop', array( 'visibility' => $vis, 'audience' => $audience ) );
					continue;
				}

				$env = json_decode( (string) ( $r['envelope'] ?? '' ), true );
				if ( ! is_array( $env ) ) {
					continue;
				}
				if ( ! self::passes_filters( $env, $filters ) ) {
					continue;
				}

				$ts = self::occurrence_ts( $env, $source );
				if ( $ts < $from_ts || $ts > $to_ts ) {
					continue; // exact window (the ULID range is only a coarse SQL prefilter).
				}
				$considered++;

				$day = self::local_day( $ts );
				list( $row_id, $row_label ) = self::resolve_row_entity( $env, $entity, $source );

				$val = ( 'amount' === $axis ) ? self::amount_cents( $env, $source ) : 1;

				if ( ! isset( $cells[ $row_id ] ) ) {
					$cells[ $row_id ] = array();
				}
				$cells[ $row_id ][ $day ] = ( $cells[ $row_id ][ $day ] ?? 0 ) + $val;
				$row_labels[ $row_id ]    = $row_label;
				$days_seen[ $day ]        = true;
				$total                   += $val;
			}

			ksort( $days_seen );
			$rows_out = array();
			foreach ( $row_labels as $rid => $label ) {
				$rows_out[] = array( 'id' => $rid, 'label' => $label );
			}

			return array(
				'ok'                 => true,
				'source'             => $clean['source'],
				'entity'             => $entity,
				'axis'               => $axis,
				'bucket'             => 'day',
				'window'             => array( 'from' => $clean['window']['from'], 'to' => $clean['window']['to'] ),
				'days'               => array_keys( $days_seen ),
				'rows'               => $rows_out,
				'cells'              => $cells,
				'total'              => $total,
				'events_plotted'     => $considered,
				'visibility_allowed' => $allowed,
				'dropped_visibility' => $dropped_vis,
			);
		}

		/* =====================================================================
		 * VISIBILITY
		 * ===================================================================== */

		/**
		 * The reader's audience tier from viewer traits. FAIL-CLOSED to the narrowest tier
		 * (customer) on any uncertainty — a mis-tier must never widen what a reader can see.
		 */
		public static function audience_for_viewer( int $viewer ): string {
			if ( $viewer <= 0 ) {
				return self::VIS_CUSTOMER;
			}
			// A shared device is never trusted with staff/internal events.
			if ( class_exists( 'ZDZ_Hierarchy' ) && method_exists( 'ZDZ_Hierarchy', 'is_kiosk' ) && ZDZ_Hierarchy::is_kiosk( $viewer ) ) {
				return self::VIS_CUSTOMER;
			}
			if ( function_exists( 'user_can' ) && user_can( $viewer, 'manage_options' ) ) {
				return self::VIS_INTERNAL;
			}
			// Any authenticated, non-kiosk staff member sees staff+customer events, never internal.
			return self::VIS_STAFF;
		}

		/**
		 * The visibility values a reader may select: bounded by BOTH the viewer's audience and the
		 * source's declared visibility ceiling. A customer audience → {customer} only, so an
		 * internal event is structurally unreachable.
		 */
		public static function allowed_visibilities( string $audience, string $source_ceiling ): array {
			$by_audience = self::visibilities_at_or_below( $audience );
			$by_ceiling  = self::visibilities_at_or_below( self::clamp_visibility( $source_ceiling ) );
			return array_values( array_intersect( $by_audience, $by_ceiling ) );
		}

		/**
		 * The set of event visibilities a given audience/ceiling tier may see. Ordered by breadth:
		 *   internal → {internal, staff, customer}   (an internal viewer sees everything)
		 *   staff    → {staff, customer}
		 *   customer → {customer}                     (a customer sees only customer-visible events)
		 */
		private static function visibilities_at_or_below( string $tier ): array {
			switch ( $tier ) {
				case self::VIS_INTERNAL:
					return array( self::VIS_INTERNAL, self::VIS_STAFF, self::VIS_CUSTOMER );
				case self::VIS_STAFF:
					return array( self::VIS_STAFF, self::VIS_CUSTOMER );
				default:
					return array( self::VIS_CUSTOMER );
			}
		}

		private static function clamp_visibility( $v ): string {
			$v = is_string( $v ) ? strtolower( trim( $v ) ) : '';
			return in_array( $v, array( self::VIS_INTERNAL, self::VIS_STAFF, self::VIS_CUSTOMER ), true ) ? $v : self::VIS_INTERNAL;
		}

		/* =====================================================================
		 * ATTRIBUTION — reuse ZDZ_Compensation so the grid == the ledger
		 * ===================================================================== */

		/**
		 * Resolve which grid row a dot lands on. A party/rep entity routes through the shared
		 * attribution contract; any other entity reads a source-declared path. A missing value is
		 * an EXPLICIT "Unknown"/"Unattributed" row — never hidden (hiding it makes the grid lie).
		 *
		 * @return array{0:string,1:string} [row_id, row_label]
		 */
		private static function resolve_row_entity( array $env, string $entity, ?array $source ): array {
			if ( ! in_array( $entity, ZDZ_Report_Spec::PARTY_ENTITIES, true ) ) {
				$path = $source['entity_paths'][ $entity ] ?? '';
				$val  = ( '' !== $path ) ? self::dig( $env, $path ) : null;
				if ( null === $val || '' === $val || ! is_scalar( $val ) ) {
					return array( '__unknown', self::t( 'Unknown' ) );
				}
				return array( 'e:' . (string) $val, (string) $val );
			}
			return self::resolve_attribution( $env, $source );
		}

		/**
		 * The attribution-precedence resolution, reusing ZDZ_Compensation::attribution(). Only a
		 * real document code or an explicit override row names a rep; anything unresolvable lands
		 * on the explicit Unattributed row (the visualization face of pay_zero_and_flag).
		 */
		private static function resolve_attribution( array $env, ?array $source ): array {
			$unattributed = array( '__unattributed', self::t( 'Unattributed' ) );
			if ( ! class_exists( 'ZDZ_Compensation' ) ) {
				return $unattributed;
			}
			$contract   = (array) ZDZ_Compensation::attribution();
			$precedence = (array) ( $contract['precedence'] ?? array() );

			$token = '';
			$path  = $source['attribution_path'] ?? '';
			if ( '' !== $path ) {
				$dug   = self::dig( $env, $path );
				$token = is_scalar( $dug ) ? strtoupper( trim( (string) $dug ) ) : '';
			}

			foreach ( $precedence as $step ) {
				if ( 'document_code' === $step ) {
					$fmt      = (string) ( $contract['code_format'] ?? '' );
					$reserved = array_map( 'strval', (array) ( $contract['reserved_tokens'] ?? array() ) );
					if ( '' === $token || in_array( $token, $reserved, true ) ) {
						continue;
					}
					if ( '' !== $fmt && ! preg_match( $fmt, $token ) ) {
						continue;
					}
					if ( method_exists( 'ZDZ_Compensation', 'plan_by_code' ) ) {
						$plan = ZDZ_Compensation::plan_by_code( $token );
						if ( is_array( $plan ) ) {
							$pid   = (int) ( $plan['party_id'] ?? $plan['user_id'] ?? 0 );
							$label = (string) ( $plan['name'] ?? $plan['label'] ?? $token );
							return array( 'p:' . ( $pid > 0 ? $pid : $token ), $label );
						}
					}
				} elseif ( 'override_row' === $step ) {
					// No override lookup ships in Core (S8-04). A tenant/commission app supplies one
					// via this filter; when it does, it wins over inference. When it does not, the
					// one-time warning disposition has already fired in assemble_grid().
					$ov = function_exists( 'apply_filters' )
						? apply_filters( 'zdz_report_attribution_override', null, $env, $source )
						: null;
					if ( is_array( $ov ) && isset( $ov['id'] ) && '' !== (string) $ov['id'] ) {
						return array( 'p:' . (string) $ov['id'], (string) ( $ov['label'] ?? $ov['id'] ) );
					}
				} elseif ( 'inference' === $step ) {
					// inferred_is_payable === false: the grid does NOT invent a rep from a guess.
					break;
				}
			}
			return $unattributed;
		}

		/** Fire a single disposition per read when overrides are structurally unwired. */
		private static function maybe_warn_unwired_overrides( string $entity ): void {
			if ( ! in_array( $entity, ZDZ_Report_Spec::PARTY_ENTITIES, true ) || ! class_exists( 'ZDZ_Compensation' ) ) {
				return;
			}
			$precedence = (array) ( ZDZ_Compensation::attribution()['precedence'] ?? array() );
			if ( ! in_array( 'override_row', $precedence, true ) ) {
				return;
			}
			$wired = function_exists( 'has_filter' ) ? has_filter( 'zdz_report_attribution_override' ) : false;
			if ( ! $wired ) {
				self::disposition( 'override_lookup_unavailable', array(
					'note' => 'attribution precedence lists override_row but no zdz_report_attribution_override resolver is wired; unresolved dots fall to Unattributed.',
				) );
			}
		}

		/* =====================================================================
		 * ENVELOPE / TIME / AMOUNT helpers
		 * ===================================================================== */

		/** Occurrence epoch: a source-declared date_path if present, else the envelope 'time'. */
		private static function occurrence_ts( array $env, ?array $source ): int {
			$path = $source['date_path'] ?? '';
			$val  = ( '' !== $path ) ? self::dig( $env, $path ) : null;
			if ( null === $val ) {
				$val = $env['time'] ?? ( $env['recorded_at'] ?? null );
			}
			if ( is_int( $val ) || ( is_string( $val ) && ctype_digit( $val ) ) ) {
				return (int) $val;
			}
			if ( is_string( $val ) && '' !== $val ) {
				$t = strtotime( $val );
				return $t ? (int) $t : 0;
			}
			return 0;
		}

		/** Money-axis value: collected cents at the source's amount_path (D-04: collected, not face). */
		private static function amount_cents( array $env, ?array $source ): int {
			$path = $source['amount_path'] ?? '';
			if ( '' === $path ) {
				return 0;
			}
			$v = self::dig( $env, $path );
			return is_numeric( $v ) ? (int) round( (float) $v ) : 0;
		}

		/** The tenant-local Y-m-d for a UTC epoch — fixes the "one column left" UTC date-shift. */
		private static function local_day( int $ts ): string {
			try {
				$dt = new DateTimeImmutable( '@' . $ts );
				return $dt->setTimezone( self::tenant_tz() )->format( 'Y-m-d' );
			} catch ( \Throwable $e ) {
				return gmdate( 'Y-m-d', $ts );
			}
		}

		private static function tenant_tz(): DateTimeZone {
			$name = '';
			if ( class_exists( 'ZDZ_Business_Profile' ) && method_exists( 'ZDZ_Business_Profile', 'get' ) ) {
				$name = (string) ZDZ_Business_Profile::get( 'locale.timezone' );
			}
			if ( '' !== $name ) {
				try {
					return new DateTimeZone( $name );
				} catch ( \Throwable $e ) { /* fall through */ }
			}
			if ( function_exists( 'wp_timezone' ) ) {
				$tz = wp_timezone();
				if ( $tz instanceof DateTimeZone ) {
					return $tz;
				}
			}
			return new DateTimeZone( 'UTC' );
		}

		/** Read a dotted path out of a decoded envelope; null when absent. */
		private static function dig( array $arr, string $path ) {
			$node = $arr;
			foreach ( explode( '.', $path ) as $seg ) {
				if ( is_array( $node ) && array_key_exists( $seg, $node ) ) {
					$node = $node[ $seg ];
				} else {
					return null;
				}
			}
			return $node;
		}

		/** Every declared filter must match the envelope (exact, string-compared). */
		private static function passes_filters( array $env, array $filters ): bool {
			foreach ( $filters as $fk => $fv ) {
				$have = self::dig( $env, 'data.' . $fk );
				if ( null === $have ) {
					$have = self::dig( $env, $fk );
				}
				if ( ! is_scalar( $have ) || (string) $have !== (string) $fv ) {
					return false;
				}
			}
			return true;
		}

		/* =====================================================================
		 * ULID time bounds (coarse SQL prefilter over the time-ordered id PK)
		 * ===================================================================== */

		/** Crockford base32, ASCII-ascending so lexicographic id compare == chronological. */
		const CROCKFORD = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

		private static function ulid_time_prefix( int $ms ): string {
			if ( $ms < 0 ) {
				$ms = 0;
			}
			$out = '';
			for ( $i = 0; $i < 10; $i++ ) {
				$out = self::CROCKFORD[ $ms % 32 ] . $out;
				$ms  = intdiv( $ms, 32 );
			}
			return $out;
		}

		/** The smallest ULID whose timestamp is >= the given second (start-of-second). */
		private static function ulid_floor( int $ts ): string {
			if ( $ts <= 0 ) {
				return '';
			}
			return self::ulid_time_prefix( $ts * 1000 ) . str_repeat( '0', 16 );
		}

		/** The largest ULID whose timestamp is <= the END of the given second. */
		private static function ulid_ceil( int $ts ): string {
			if ( $ts <= 0 ) {
				return '';
			}
			return self::ulid_time_prefix( ( $ts + 1 ) * 1000 - 1 ) . str_repeat( 'Z', 16 );
		}

		/* =====================================================================
		 * Window → epoch (tenant tz), stamp, misc infra
		 * ===================================================================== */

		/** Convert a validated {from,to} Y-m-d pair to a [start, end] epoch pair in tenant tz. */
		private static function window_epoch( string $from, string $to ): array {
			$tz = self::tenant_tz();
			try {
				$lo = new DateTimeImmutable( $from . ' 00:00:00', $tz );
				$hi = new DateTimeImmutable( $to . ' 23:59:59', $tz );
				return array( $lo->getTimestamp(), $hi->getTimestamp() );
			} catch ( \Throwable $e ) {
				return array( (int) strtotime( $from . ' 00:00:00 UTC' ), (int) strtotime( $to . ' 23:59:59 UTC' ) );
			}
		}

		private static function stamp( array $clean ): string {
			if ( '' === self::$run_salt ) {
				self::$run_salt = bin2hex( random_bytes( 8 ) );
			}
			return hash( 'sha256', self::canonical( $clean ) . '|' . self::$run_salt );
		}

		private static function stamp_matches( array $clean ): bool {
			if ( ! ZDZ_Report_Spec::has_stamp( $clean ) || '' === self::$run_salt ) {
				return false;
			}
			$given = (string) $clean[ ZDZ_Report_Spec::STAMP ];
			$want  = hash( 'sha256', self::canonical( $clean ) . '|' . self::$run_salt );
			return hash_equals( $want, $given );
		}

		/** Canonical serialization of a spec MINUS the stamp itself (for stamp integrity). */
		private static function canonical( array $clean ): string {
			unset( $clean[ ZDZ_Report_Spec::STAMP ] );
			ksort( $clean );
			return function_exists( 'wp_json_encode' ) ? (string) wp_json_encode( $clean ) : (string) json_encode( $clean );
		}

		private static function outbox_table(): string {
			if ( class_exists( 'Zdz_Flow_DB' ) && method_exists( 'Zdz_Flow_DB', 'outbox' ) ) {
				return Zdz_Flow_DB::outbox();
			}
			global $wpdb;
			$prefix = isset( $wpdb ) ? $wpdb->prefix : 'wp_';
			return $prefix . 'zdz_flow_outbox';
		}

		private static function tenant_id(): int {
			if ( class_exists( 'Zdz_Flow_DB' ) && method_exists( 'Zdz_Flow_DB', 'tenant_id' ) ) {
				return (int) Zdz_Flow_DB::tenant_id();
			}
			return defined( 'ZDZ_TENANT' ) ? (int) ZDZ_TENANT : 1;
		}

		private static function row_cap(): int {
			$c = function_exists( 'apply_filters' ) ? (int) apply_filters( 'zdz_report_row_cap', self::DEFAULT_ROW_CAP ) : self::DEFAULT_ROW_CAP;
			return $c > 0 ? $c : self::DEFAULT_ROW_CAP;
		}

		private static function disposition( string $code, array $detail = array() ): void {
			if ( function_exists( 'do_action' ) ) {
				do_action( 'zdz_disposition', 'report_sources', array_merge( array( 'code' => $code ), $detail ) );
			}
		}

		private static function t( string $s ): string {
			return function_exists( '__' ) ? __( $s, 'zorderz' ) : $s;
		}

		private static function error( string $code, string $message, array $data = array() ) {
			if ( class_exists( 'WP_Error' ) ) {
				return new WP_Error( $code, $message, $data );
			}
			return (object) array( 'zdz_error' => true, 'code' => $code, 'message' => $message, 'data' => $data );
		}

		private static function error_code( $err ): string {
			if ( function_exists( 'is_wp_error' ) && is_wp_error( $err ) ) {
				return (string) $err->get_error_code();
			}
			return is_object( $err ) && isset( $err->code ) ? (string) $err->code : 'zdz_report_error';
		}

		private static function error_message( $err ): string {
			if ( function_exists( 'is_wp_error' ) && is_wp_error( $err ) ) {
				return (string) $err->get_error_message();
			}
			return is_object( $err ) && isset( $err->message ) ? (string) $err->message : 'Report refused.';
		}

		public static function init(): void {
			/* Stateless registry/reader — nothing to hook. Kept for the self-boot convention. */
		}
	}

	ZDZ_Report_Sources::init();
}
