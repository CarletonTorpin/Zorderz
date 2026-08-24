<?php
/**
 * Zdz_Inference — the platform-wide, CRON-ONLY, SIDE-EFFECT-FREE advisory service.
 *
 * It answers one question — "what does the paperwork suggest for a schedule?" — as a
 * human-confirmed SUGGESTION, never an auto-booking. It COMPOSES existing Core
 * services (Connections in, Answer-Authority's confidence tier, the Party roster)
 * rather than duplicating them, and it is consumed read-only by Jobs/Projects, Prep,
 * and the scheduler's date pre-fill.
 *
 * THE INVARIANTS THIS FILE MAKES STRUCTURAL
 *
 *   INV-11 — ZERO WRITES TO SOURCE-OF-TRUTH DATA. The service reads, infers, and
 *     records its inferences in its OWN store/tier; it never mutates a lead / estimate
 *     / job / customer record and never calls a provider write (no FreshBooks
 *     create/update, no Nutshell editLead/newLead/addNote). EVERY persistence in this
 *     class funnels through the single private method store_inference(), and that
 *     method writes ONLY non-autoloaded `zdz_infer_*` options. A grep of this file
 *     finds no update/insert against any non-inference table and no provider mutation.
 *
 *   CRON-ONLY NETWORK. cron_tick() is the ONLY method that reads a source or reaches
 *     the model. Every interactive read (infer / for_estimate / for_invoice / for_lead)
 *     is `get_option` only — NO network on an interactive path, ever. A cache miss
 *     returns an empty envelope (authority = UNKNOWN), never a fetch.
 *
 *   TIER_INFERRED AT MINT. Every minted envelope is stamped
 *     ZDZ_Answer_Authority::TIER_INFERRED by mint_envelope(); a consumer cannot promote
 *     a guess to a fact. Any surface that STATES an inferred date is expected to route
 *     it through ZDZ_Answer_Authority::gate(), where `scheduled`/`booked` are governed
 *     outcome verbs (INV-12) — this producer never states anything itself.
 *
 *   NO PRIVATE MODEL CLIENT (INV-4). When a parser wants the model it goes through the
 *     Wave-A gateway (ZDZ_Core_Poe) resolved by ZDZ_Model_Registry — via
 *     Zdz_Inference::gateway(). This file issues no direct HTTP call and names no
 *     provider host: source reads go through the already-present Core FreshBooks/Nutshell
 *     adapters (Connections), the model goes through the shared gateway. Network work
 *     runs under the Wave-A request-guard / breaker (Zdz_Sweep → Zdz_Request_Guard →
 *     Zdz_Service_Breaker) when present, and degrades to a bounded, breaker-aware loop
 *     when absent.
 *
 * CORE vs IDENTITY (the layer question: would another business's copy differ?)
 *   [CORE]      the pipeline (Connections-in → parser → TIER_INFERRED → non-autoloaded
 *               cache → read-only, side-effect-free consumers), resolve_assignee's
 *               resolution mechanism, and the generic keyword anchors
 *               (`sched|scheduled|install|appt`).
 *   [IDENTITY]  the parser that turns a document (or model output) into a structured
 *               inference is registered via `zdz_inference_parser` and ships EMPTY —
 *               Core infers NOTHING until a business configures a parser. Its grammar /
 *               "Location-line" convention → document-conventions; the initials map
 *               (`zdz_infer_initials_map`) → parties; the product-line filter → catalog;
 *               the provider choice → connections; the estimate→lead field mapping
 *               (`zdz_infer_lead_map`) → mappings; the timezone default → profile (Core
 *               default UTC). Core ships NO initials, NO business token, NO Location
 *               convention, NO parser.
 *
 * The bundled estimate/invoice source descriptors are the reference registration
 * (mirroring ZDZ_Token_Service's bundled FreshBooks provider): they compose the
 * already-present Core adapter, carry no business-specific query literal, read nothing
 * until credentials exist, and are fully replaceable through `zdz_inference_sources`.
 * Because the parser registry ships empty, they mint nothing on a fresh install.
 *
 * Derives from S4-07 / S1-11 and PLAN-03a AC4. No Core DB table (the store is
 * non-autoloaded wp_options). Nothing is seeded on activation.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Zdz_Inference', false ) ) :

class Zdz_Inference {

	/** The cron hook this service self-registers (the ONLY network entry point). */
	const CRON_HOOK = 'zdz_inference_cron';

	/** Non-autoloaded index of every inference key written ({scope:id} => option_name). */
	const INDEX_OPTION = 'zdz_infer_keys';

	// ─────────────────────────────────────────────────────────────────
	// FILTERABLE BOUNDS (constants expressed as filters — a tenant tunes them,
	// Core ships conservative defaults). All bound the CRON path only.
	// ─────────────────────────────────────────────────────────────────

	/** Max documents pulled from a source per tick. */
	public static function max_crm_reads(): int {
		return max( 1, (int) apply_filters( 'zdz_inference_max_crm_reads', 100 ) );
	}

	/** Max per-customer follow-up fetches per tick (a by-customer fallback bound). */
	public static function max_cust_fetch(): int {
		return max( 0, (int) apply_filters( 'zdz_inference_max_cust_fetch', 60 ) );
	}

	/** Max diagnostic lines a tick may log (keeps a noisy account from flooding). */
	public static function diag_budget(): int {
		return max( 0, (int) apply_filters( 'zdz_inference_diag_budget', 5 ) );
	}

	// ─────────────────────────────────────────────────────────────────
	// PUBLIC CONTRACT (every consumer class_exists-guards this class)
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Generic cache resolver — GET_OPTION ONLY, never a network call. A miss returns an
	 * empty envelope (authority = UNKNOWN); it is not a fetch.
	 *
	 * @param array $args ['scope'=>string,'id'=>string|int]
	 * @return array The inference envelope (always shaped; see mint_envelope()).
	 */
	public static function infer( array $args ): array {
		$scope = isset( $args['scope'] ) ? sanitize_key( (string) $args['scope'] ) : '';
		$id    = isset( $args['id'] ) ? (string) $args['id'] : '';
		if ( '' === $scope || '' === $id ) {
			return self::empty_envelope( $scope, $id );
		}

		$stored = get_option( self::store_key( $scope, $id ), null );
		if ( is_array( $stored ) && ! empty( $stored ) ) {
			// Defensive re-stamp: anything drawn from the inference store IS inferred,
			// so a consumer can never read back an un-tagged (launderable) hint.
			if ( empty( $stored['authority'] ) ) {
				$stored['authority'] = self::tier_inferred();
			}
			return $stored;
		}
		return self::empty_envelope( $scope, $id );
	}

	/**
	 * The scheduled-date hint for an estimate. GET_OPTION ONLY.
	 *
	 * @param string   $num    The estimate number/id (the local key; never a CRM search).
	 * @param int|null $viewer Reserved: the reader whose surface will STATE the hint (that
	 *                         surface routes it through ZDZ_Answer_Authority::gate()).
	 * @return array
	 */
	public static function for_estimate( string $num, ?int $viewer = null ): array {
		return self::infer( array( 'scope' => 'estimate', 'id' => $num ) );
	}

	/** The scheduled-date hint for an invoice. GET_OPTION ONLY. */
	public static function for_invoice( string $num, ?int $viewer = null ): array {
		return self::infer( array( 'scope' => 'invoice', 'id' => $num ) );
	}

	/** The scheduled-date hint for a CRM lead. GET_OPTION ONLY. */
	public static function for_lead( int $lead_id, ?int $viewer = null ): array {
		return self::infer( array( 'scope' => 'lead', 'id' => (string) $lead_id ) );
	}

	/**
	 * Resolve staff initials to a user id. NEVER a hardcoded roster; never a guess.
	 *
	 *   1. an explicit Identity map (`zdz_infer_initials_map`, 'CT' => user_id) → parties;
	 *   2. else a SINGLE unambiguous match in ZDZ_Party::selectable_people();
	 *   3. else 0. Ambiguity (2+ people share the initials) → 0 + a logged disposition.
	 *
	 * The initials passed in are parsed from a document at runtime (PII the caller never
	 * seeds); no initials appear in this code.
	 *
	 * @param string $initials
	 * @return int user id, or 0 when unmapped / ambiguous / unknown.
	 */
	public static function resolve_assignee( string $initials ): int {
		$key = strtoupper( trim( preg_replace( '/[^A-Za-z]/', '', $initials ) ) );
		if ( '' === $key ) {
			return 0;
		}

		// 1. Explicit Identity map (parties). Core ships none.
		$map = apply_filters( 'zdz_infer_initials_map', array() );
		if ( is_array( $map ) ) {
			foreach ( $map as $k => $uid ) {
				if ( strtoupper( trim( (string) $k ) ) === $key ) {
					return max( 0, (int) $uid );
				}
			}
		}

		// 2. A single unambiguous match in the authoritative roster.
		if ( class_exists( 'ZDZ_Party' ) && method_exists( 'ZDZ_Party', 'selectable_people' ) ) {
			$matches = array();
			foreach ( ZDZ_Party::selectable_people() as $person ) {
				$pi = strtoupper( trim( (string) ( $person['initials'] ?? '' ) ) );
				if ( '' !== $pi && $pi === $key ) {
					$matches[] = (int) ( $person['id'] ?? 0 );
				}
			}
			$matches = array_values( array_unique( array_filter( $matches ) ) );
			if ( 1 === count( $matches ) ) {
				return (int) $matches[0];
			}
			if ( count( $matches ) > 1 ) {
				// 3. Ambiguous — never a guess. Nothing silent.
				self::log_disposition( 'assignee_ambiguous', array(
					'candidates' => count( $matches ),
				) );
				return 0;
			}
		}

		return 0;
	}

	/**
	 * THE ONLY NETWORK METHOD. Walks each registered source's bounded batch, runs the
	 * registered (Identity) parser, cross-checks a mapped CRM lead by a LOCAL id (no CRM
	 * search), stamps TIER_INFERRED, and writes the hint to the non-autoloaded store.
	 * Network work runs under the Wave-A guard/breaker when present; it refuses to run
	 * off a cron context (the network is cron-only).
	 *
	 * With the parser registry empty (a fresh Core install) this mints nothing.
	 */
	public static function cron_tick(): void {
		if ( ! self::is_cron_context() ) {
			error_log( '[Zdz_Inference] cron_tick invoked off a cron context — refusing (network is cron-only).' );
			return;
		}

		$sources = self::sources();
		if ( empty( $sources ) ) {
			return;
		}
		$parsers = self::parsers();

		foreach ( $sources as $src ) {
			if ( empty( $src['key'] ) || empty( $src['parser'] ) ) {
				continue;
			}
			// A source whose parser is not registered mints nothing — skip its read
			// entirely (no point spending a network call to feed an absent parser).
			if ( ! isset( $parsers[ (string) $src['parser'] ] ) || ! is_callable( $parsers[ (string) $src['parser'] ] ) ) {
				continue;
			}
			self::walk_source( $src, $parsers[ (string) $src['parser'] ] );
		}
	}

	/**
	 * A ready-to-use shared gateway bound to the inference model slot, for a parser that
	 * wants model help turning unstructured text into a structured hint. The parser MUST
	 * use this (or ZDZ_Model_Registry::gateway() directly) — never a private client
	 * (INV-4). Returns null if the registry/gateway is unavailable (degrade).
	 *
	 * @return ZDZ_Core_Poe|null
	 */
	public static function gateway() {
		if ( ! class_exists( 'ZDZ_Model_Registry' ) ) {
			return null;
		}
		$slot = (string) apply_filters( 'zdz_inference_model_slot', 'planner' );
		return ZDZ_Model_Registry::gateway( $slot );
	}

	// ─────────────────────────────────────────────────────────────────
	// SOURCE + PARSER REGISTRIES (Identity-configured; Core parser ships EMPTY)
	// ─────────────────────────────────────────────────────────────────

	/**
	 * The registered inference sources. Each descriptor:
	 *   [ 'key'    => string  (also the cache scope, e.g. 'estimate'),
	 *     'provider'=> string (a Connections id, e.g. 'freshbooks' — the breaker key),
	 *     'reader' => callable(int $limit): array   (pulls docs through Connections),
	 *     'id_of'  => callable(array $doc): string  (the local document id),
	 *     'parser' => string  (a `zdz_inference_parser` slug) ]
	 *
	 * Ships the reference estimate/invoice shape (composing the already-present Core
	 * FreshBooks adapter); a tenant/connections pack extends or replaces via the filter.
	 *
	 * @return array<int,array>
	 */
	public static function sources(): array {
		$defaults = self::default_sources();
		$sources  = apply_filters( 'zdz_inference_sources', $defaults );
		if ( ! is_array( $sources ) ) {
			return array();
		}
		$out = array();
		foreach ( $sources as $src ) {
			if ( is_array( $src ) && ! empty( $src['key'] ) && ! empty( $src['reader'] ) && is_callable( $src['reader'] ) ) {
				$src['key']    = sanitize_key( (string) $src['key'] );
				$src['parser'] = isset( $src['parser'] ) ? (string) $src['parser'] : '';
				$out[]         = $src;
			}
		}
		return $out;
	}

	/**
	 * The registered parsers: slug => callable(array $doc, array $identity): array.
	 * A parser returns a partial hint ['date','time','assignee','confidence','basis'] or
	 * an empty array. SHIPS EMPTY — Core registers no parser; the install-date extractor
	 * is the first `zdz_inference_parser` a business supplies (its grammar/initials/
	 * product-filter are Identity Pack data).
	 *
	 * @return array<string,callable>
	 */
	public static function parsers(): array {
		$parsers = apply_filters( 'zdz_inference_parser', array() );
		return is_array( $parsers ) ? $parsers : array();
	}

	/**
	 * The generic keyword anchors a parser may lean on. [CORE] and vendor-free — the
	 * business-specific grammar (the "Location" line convention, product-line filter,
	 * initials stop-list) is layered on by the Identity parser, never here.
	 *
	 * @return string[]
	 */
	public static function keyword_anchors(): array {
		$anchors = apply_filters( 'zdz_inference_keyword_anchors', array( 'sched', 'scheduled', 'install', 'appt' ) );
		return is_array( $anchors ) ? array_values( array_filter( array_map( 'strval', $anchors ) ) ) : array();
	}

	/**
	 * The identity bundle handed to a parser: everything grammar-shaped a business
	 * configures, each an EMPTY-by-default filter (Core supplies only the neutral tz
	 * default and the generic anchors). No business value is baked here.
	 *
	 * @param array $src The source descriptor.
	 * @return array
	 */
	public static function identity_bundle( array $src ): array {
		return array(
			'anchors'      => self::keyword_anchors(),
			'grammar'      => apply_filters( 'zdz_inference_grammar', array(), $src ),          // document-conventions
			'initials_map' => apply_filters( 'zdz_infer_initials_map', array() ),               // parties
			'product_filter' => apply_filters( 'zdz_inference_product_filter', array(), $src ), // catalog
			'tz_default'   => (string) apply_filters( 'zdz_inference_tz_default', 'UTC' ),       // profile (Core default UTC)
		);
	}

	// ─────────────────────────────────────────────────────────────────
	// CRON INTERNALS (network; guard/breaker-wrapped; the only writers of the store)
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Read a source's bounded batch and process each doc. Prefers the Wave-A shared
	 * sweep (lock + wall-clock budget + reserve-next-call + breaker + starved-pass log);
	 * degrades to a bounded, breaker-aware loop when the sweep is absent.
	 *
	 * @param array    $src    The source descriptor.
	 * @param callable $parser The resolved parser callable.
	 */
	private static function walk_source( array $src, callable $parser ): void {
		$service = 'inference:' . ( isset( $src['provider'] ) ? (string) $src['provider'] : (string) $src['key'] );
		$limit   = self::max_crm_reads();

		if ( class_exists( 'Zdz_Sweep' ) ) {
			Zdz_Sweep::run(
				'zdz_inference_' . $src['key'],
				static function ( $batch ) use ( $src ) {
					return self::read_docs( $src, (int) $batch );
				},
				static function ( $doc ) use ( $src, $parser ) {
					self::process_doc( $src, $doc, $parser );
				},
				array( 'service' => $service, 'batch_limit' => $limit )
			);
			return;
		}

		// ── Degrade: a bounded, breaker-aware loop (no sweep helper present). ──
		$docs = self::read_docs( $src, $limit );
		foreach ( $docs as $doc ) {
			if ( class_exists( 'Zdz_Service_Breaker' ) && Zdz_Service_Breaker::is_open( $service ) ) {
				break;
			}
			self::process_doc( $src, $doc, $parser );
		}
	}

	/**
	 * Pull docs from a source's reader (a Connections read — never a private client).
	 * Bounded and defensive: a reader that throws or returns non-array yields nothing.
	 *
	 * @return array<int,array>
	 */
	private static function read_docs( array $src, int $limit ): array {
		if ( empty( $src['reader'] ) || ! is_callable( $src['reader'] ) ) {
			return array();
		}
		try {
			$docs = call_user_func( $src['reader'], max( 1, $limit ) );
		} catch ( \Throwable $e ) {
			error_log( '[Zdz_Inference] source "' . (string) $src['key'] . '" reader failed: ' . $e->getMessage() );
			return array();
		}
		if ( ! is_array( $docs ) ) {
			return array();
		}
		return array_slice( array_values( array_filter( $docs, 'is_array' ) ), 0, max( 1, $limit ) );
	}

	/**
	 * Run the parser over one doc; on a dated hint, mint (TIER_INFERRED), cross-check a
	 * mapped CRM lead by LOCAL id, and store. Pure read + own-store write; no source-of-
	 * truth mutation anywhere on this path.
	 */
	private static function process_doc( array $src, array $doc, callable $parser ): void {
		$id = self::doc_id( $src, $doc );
		if ( '' === $id ) {
			return;
		}

		try {
			$hint = call_user_func( $parser, $doc, self::identity_bundle( $src ) );
		} catch ( \Throwable $e ) {
			error_log( '[Zdz_Inference] parser "' . (string) $src['parser'] . '" failed on ' . (string) $src['key'] . ' #' . $id . ': ' . $e->getMessage() );
			return;
		}
		if ( ! is_array( $hint ) || empty( $hint['date'] ) ) {
			return; // no date read — nothing to record (a blank is read, not inferred).
		}

		$env = self::mint_envelope( $hint, (string) $src['key'], $id, $src );
		$env = self::attach_crm_crosscheck( $env, $id );
		self::store_inference( (string) $src['key'], $id, $env );
	}

	/**
	 * Optional CRM cross-check: read (never write) a lead the LOCAL mapping points at.
	 * The mapping (`zdz_infer_lead_map`, fb_estimate_num => ns_lead_id) is Identity →
	 * mappings; Core ships none. No CRM search is ever performed.
	 */
	private static function attach_crm_crosscheck( array $env, string $id ): array {
		$map = apply_filters( 'zdz_infer_lead_map', array() );
		if ( ! is_array( $map ) || ! isset( $map[ $id ] ) ) {
			return $env;
		}
		$lead_id = (int) $map[ $id ];
		if ( $lead_id < 1 || ! class_exists( 'ZDZ_Core_Nutshell' ) ) {
			return $env;
		}
		$ns = new ZDZ_Core_Nutshell();
		if ( ! method_exists( $ns, 'is_configured' ) || ! $ns->is_configured() ) {
			return $env;
		}
		$lead = $ns->get_lead( $lead_id, 'REV_NEWEST' ); // READ ONLY — no editLead/newLead.
		if ( is_array( $lead ) ) {
			$status = $lead['status'] ?? null;
			if ( is_array( $status ) ) {
				$status = $status['id'] ?? ( $status['name'] ?? null );
			}
			$env['crm_lead_id'] = $lead_id;
			$env['crm_status']  = ( null === $status ) ? '' : (string) $status;
		}
		return $env;
	}

	/**
	 * Mint a full envelope from a parser's partial hint. TIER_INFERRED is ENFORCED here
	 * and cannot be overridden by a caller — a guess never launders into a fact.
	 */
	private static function mint_envelope( array $hint, string $scope, string $id, array $src = array() ): array {
		$date     = isset( $hint['date'] ) ? (string) $hint['date'] : '';
		$time     = isset( $hint['time'] ) ? (string) $hint['time'] : '';
		$tz       = ( isset( $hint['tz'] ) && '' !== (string) $hint['tz'] )
			? (string) $hint['tz']
			: (string) apply_filters( 'zdz_inference_tz_default', 'UTC' );
		$initials = isset( $hint['assignee'] ) ? (string) $hint['assignee'] : '';

		return array(
			'date'             => $date,
			'time'             => $time,
			'start_utc'        => self::compute_start_utc( $date, $time, $tz ),
			'tz'               => $tz,
			'assignee'         => $initials,
			'assignee_user_id' => ( '' !== $initials ) ? self::resolve_assignee( $initials ) : 0,
			'source'           => $scope,
			'confidence'       => isset( $hint['confidence'] ) ? (string) $hint['confidence'] : '',
			'basis'            => isset( $hint['basis'] ) ? (string) $hint['basis'] : '',
			'authority'        => self::tier_inferred(), // ENFORCED — never a caller value.
			'cached_at'        => time(),
		);
	}

	/** The shape returned on a miss: everything empty, authority UNKNOWN, cached_at 0. */
	private static function empty_envelope( string $scope, string $id ): array {
		return array(
			'date'             => '',
			'time'             => '',
			'start_utc'        => null,
			'tz'               => (string) apply_filters( 'zdz_inference_tz_default', 'UTC' ),
			'assignee'         => '',
			'assignee_user_id' => 0,
			'source'           => $scope,
			'confidence'       => '',
			'basis'            => '',
			'authority'        => self::tier_unknown(),
			'cached_at'        => 0,
		);
	}

	/**
	 * THE ONE WRITE METHOD (the INV-11 chokepoint). Writes ONLY non-autoloaded
	 * `zdz_infer_*` options: the per-hint envelope and a bounded key index. There is no
	 * other persistence in this class, and this method touches no source-of-truth store.
	 */
	private static function store_inference( string $scope, string $id, array $env ): void {
		$key = self::store_key( $scope, $id );
		update_option( $key, $env, false ); // non-autoloaded (no per-request bloat)

		$index = get_option( self::INDEX_OPTION, array() );
		if ( ! is_array( $index ) ) {
			$index = array();
		}
		$ref = $scope . ':' . $id;
		if ( ! isset( $index[ $ref ] ) ) {
			$index[ $ref ] = $key;
			$cap = max( 1, (int) apply_filters( 'zdz_inference_index_cap', 5000 ) );
			if ( count( $index ) > $cap ) {
				$index = array_slice( $index, -$cap, null, true );
			}
			update_option( self::INDEX_OPTION, $index, false );
		}
	}

	// ─────────────────────────────────────────────────────────────────
	// HELPERS
	// ─────────────────────────────────────────────────────────────────

	/** Canonical, collision-resistant, non-guessable-shape store key for a hint. */
	private static function store_key( string $scope, string $id ): string {
		return 'zdz_infer_' . md5( $scope . ':' . $id );
	}

	/** Extract the local document id via the source's id_of, else common id fields. */
	private static function doc_id( array $src, array $doc ): string {
		if ( isset( $src['id_of'] ) && is_callable( $src['id_of'] ) ) {
			try {
				return (string) call_user_func( $src['id_of'], $doc );
			} catch ( \Throwable $e ) {
				return '';
			}
		}
		foreach ( array( 'estimateid', 'invoiceid', 'id', 'number', 'num' ) as $f ) {
			if ( isset( $doc[ $f ] ) && '' !== (string) $doc[ $f ] ) {
				return (string) $doc[ $f ];
			}
		}
		return '';
	}

	/** Resolve date+time (in tz) to a UTC unix timestamp, or null when unparseable. */
	private static function compute_start_utc( string $date, string $time, string $tz ): ?int {
		if ( '' === $date ) {
			return null;
		}
		try {
			$zone = new DateTimeZone( '' !== $tz ? $tz : 'UTC' );
			$spec = $date . ( ( '' !== $time ) ? ( ' ' . $time ) : ' 00:00' );
			$dt   = new DateTime( $spec, $zone );
			return $dt->getTimestamp();
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/** TIER_INFERRED, degrading to the literal tier string if the gate class is absent. */
	private static function tier_inferred(): string {
		return class_exists( 'ZDZ_Answer_Authority' ) ? ZDZ_Answer_Authority::TIER_INFERRED : 'inferred';
	}

	/** TIER_UNKNOWN, degrading to the literal tier string if the gate class is absent. */
	private static function tier_unknown(): string {
		return class_exists( 'ZDZ_Answer_Authority' ) ? ZDZ_Answer_Authority::TIER_UNKNOWN : 'unknown';
	}

	/** Nothing silent: every drop/ambiguity is a logged disposition. */
	private static function log_disposition( string $code, array $detail = array() ): void {
		if ( function_exists( 'do_action' ) ) {
			do_action( 'zdz_disposition', 'inference', array_merge( array( 'code' => $code ), $detail ) );
		}
		error_log( '[Zdz_Inference] ' . $code . ' ' . wp_json_encode( $detail ) );
	}

	/**
	 * Is this a cron (or explicitly-permitted maintenance) context? The network path
	 * runs ONLY here; an interactive request can never trigger a source read.
	 */
	private static function is_cron_context(): bool {
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return true;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}
		return (bool) apply_filters( 'zdz_inference_force_tick', false );
	}

	/**
	 * The reference estimate/invoice sources. Each composes the already-present Core
	 * FreshBooks adapter (a Connection), carries no business-specific query literal, and
	 * reads nothing until credentials exist. Both point at the (empty-by-default)
	 * `install_date` parser slug, so a fresh install mints nothing.
	 *
	 * @return array<int,array>
	 */
	private static function default_sources(): array {
		return array(
			array(
				'key'      => 'estimate',
				'provider' => 'freshbooks',
				'parser'   => 'install_date',
				'reader'   => array( __CLASS__, 'read_estimates' ),
				'id_of'    => static function ( $doc ) {
					return (string) ( $doc['estimateid'] ?? ( $doc['id'] ?? ( $doc['estimate_number'] ?? '' ) ) );
				},
			),
			array(
				'key'      => 'invoice',
				'provider' => 'freshbooks',
				'parser'   => 'install_date',
				'reader'   => array( __CLASS__, 'read_invoices' ),
				'id_of'    => static function ( $doc ) {
					return (string) ( $doc['invoiceid'] ?? ( $doc['id'] ?? ( $doc['invoice_number'] ?? '' ) ) );
				},
			),
		);
	}

	/**
	 * Bundled estimate reader — through the Core FreshBooks adapter (Connections), never
	 * a private client. Inert without credentials. Carries no status/product literal;
	 * a tenant narrows the query via `zdz_inference_source_query`.
	 *
	 * @return array<int,array>
	 */
	public static function read_estimates( int $limit ): array {
		if ( ! class_exists( 'ZDZ_Core_FreshBooks' ) ) {
			return array();
		}
		$fb = new ZDZ_Core_FreshBooks();
		if ( ! method_exists( $fb, 'is_configured' ) || ! $fb->is_configured() ) {
			return array();
		}
		$params = apply_filters(
			'zdz_inference_source_query',
			array( 'per_page' => min( 100, max( 1, $limit ) ), 'include[]' => 'lines' ),
			'estimate'
		);
		$resp = $fb->get_estimates( is_array( $params ) ? $params : array() );
		return self::extract_list( $resp, array( 'estimates', 'estimate' ) );
	}

	/**
	 * Bundled invoice reader — through the Core FreshBooks adapter (Connections). Inert
	 * without credentials; no business literal.
	 *
	 * @return array<int,array>
	 */
	public static function read_invoices( int $limit ): array {
		if ( ! class_exists( 'ZDZ_Core_FreshBooks' ) ) {
			return array();
		}
		$fb = new ZDZ_Core_FreshBooks();
		if ( ! method_exists( $fb, 'is_configured' ) || ! $fb->is_configured() ) {
			return array();
		}
		$params = apply_filters(
			'zdz_inference_source_query',
			array( 'per_page' => min( 100, max( 1, $limit ) ), 'include[]' => 'lines' ),
			'invoice'
		);
		$resp = $fb->get_invoices( is_array( $params ) ? $params : array() );
		return self::extract_list( $resp, array( 'invoices', 'invoice' ) );
	}

	/** Pull the list of document objects out of a provider response, defensively. */
	private static function extract_list( $resp, array $keys ): array {
		if ( ! is_array( $resp ) ) {
			return array();
		}
		$result = $resp['response']['result'] ?? $resp;
		if ( is_array( $result ) ) {
			foreach ( $keys as $k ) {
				if ( isset( $result[ $k ] ) && is_array( $result[ $k ] ) ) {
					return array_values( array_filter( $result[ $k ], 'is_array' ) );
				}
			}
		}
		return array();
	}

	// ─────────────────────────────────────────────────────────────────
	// SELF-BOOT (cron only — no schema, no seed, no request-path work)
	// ─────────────────────────────────────────────────────────────────

	public static function boot(): void {
		// Schedule the maintenance tick (recurrence filterable; WP built-in default).
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ) );
		// Register the cron handler — the ONLY network entry point.
		add_action( self::CRON_HOOK, array( __CLASS__, 'cron_tick' ) );
	}

	/** Ensure the advisory tick is scheduled (idempotent). Never runs on a request path. */
	public static function ensure_scheduled(): void {
		if ( ! function_exists( 'wp_next_scheduled' ) ) {
			return;
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			$recurrence = (string) apply_filters( 'zdz_inference_cron_recurrence', 'hourly' );
			wp_schedule_event( time() + 300, $recurrence, self::CRON_HOOK );
		}
	}
}

Zdz_Inference::boot();

endif;
