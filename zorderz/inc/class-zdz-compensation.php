<?php
/**
 * ZDZ_Compensation — the Compensation Core service.
 *
 * The single authority for HOW people are paid: commission structures and tiers,
 * shared-job split policies, piece rates, product-scoped bonus/minimum rules,
 * non-commissionable ledger kinds, card-fee handling, the pay calendar, the
 * payability gate and the attribution-precedence contract. Every consumer (the
 * Commission app, its installer-pay payroll, its cross-app analytics bridge)
 * reads its typed config from here instead of hardcoding a rate, a tier ladder
 * or a $/unit table.
 *
 * ── The layer split (Playbook §1) ─────────────────────────────────────
 *   MECHANISM is [CORE] and lives in code: the STRUCTURE enum, the SPLIT
 *   policy enum, the ledger-kind flag set, the marginal-tier math shape, the
 *   safety floor (never pay commission on refunded/non-sale money; never trust
 *   a provider's own "paid" filter). These are the same for every business.
 *
 *   VALUES are [IDENTITY] and SHIP EMPTY: the commission rate, the tier
 *   ceilings, the piece $/unit table, the product-minimum floors, the card-fee
 *   rate, and every per-party plan. This is the most commercially sensitive
 *   data in the whole distribution — it is NEVER seeded, sampled or shipped
 *   (Playbook §5). An unconfigured install computes $0 and says so, loudly; it
 *   never invents a 20% default.
 *
 * ── Data discipline ───────────────────────────────────────────────────
 *   - Activation creates NOTHING. There is no schema and no seed here; the
 *     store is a handful of wp_options (empty) plus per-party user meta (empty).
 *   - A party with no plan resolves to `configured => false`. Callers surface
 *     that as a disposition/flag and pay nothing — they never fall back to a
 *     baked-in rate (Playbook §7: nothing silent, fail loudly).
 *   - The per-party short code is read from ZDZ_Party under the key `initials`
 *     (NOT `code`) and matched case-insensitively (Playbook §13).
 *
 * Self-boots via ::init() at file end, like the other Core services.
 *
 * @package Zorderz
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Compensation {

	/* ==================================================================
	 * CORE MECHANISM ENUMS  — [CORE]: the shape is the same everywhere.
	 * ================================================================== */

	/**
	 * Commission structures. The enum and its math are Core; only the SELECTED
	 * value and its parameters are tenant. `formula` replaces the old "custom"
	 * that silently aliased flat — a custom structure now means something.
	 */
	const STRUCTURES = [ 'flat_rate', 'tiered', 'per_job_bonus', 'salary_only', 'piece_rate', 'formula' ];

	/**
	 * Shared-job split policies.
	 *   full      — no division (legacy); on a shared job it is pooled equally.
	 *   equal     — 1/N of the pool.
	 *   weight    — this rep's weight ÷ Σ weights of reps present.
	 *   own_share — a literal percent of the pool.
	 */
	const SPLIT_POLICIES = [ 'full', 'equal', 'weight', 'own_share' ];

	/** Pay-period cadences a plan may declare (labels only; the calendar drives finalisation). */
	const PAY_PERIODS = [ 'weekly', 'biweekly', 'semimonthly', 'monthly' ];

	/** The three independent flags a ledger kind carries (see ledger_kinds()). */
	const LEDGER_FLAGS = [ 'counts_toward_revenue', 'counts_toward_commission', 'counts_toward_units' ];

	/* ==================================================================
	 * OPTION KEYS  — every one ships ABSENT (empty). No activation seed.
	 * ================================================================== */

	const OPT_SETTINGS     = 'zdz_compensation_settings';      // global mechanism config (merged over safe Core defaults)
	const OPT_PIECE_RATES  = 'zdz_compensation_piece_rates';   // item_id => { rate, unit }        [IDENTITY, EMPTY]
	const OPT_MINIMUMS     = 'zdz_compensation_minimums';      // product-scoped min-commission    [IDENTITY, EMPTY]
	const OPT_LEDGER_KINDS = 'zdz_compensation_ledger_kinds';  // tenant extensions to the kind set

	/** Per-party plan is stored in user meta under these keys (all ship empty). */
	const META_STRUCTURE   = 'zdz_comp_structure';
	const META_RATE        = 'zdz_comp_rate_percent';
	const META_BASE_PAY    = 'zdz_comp_base_pay';
	const META_BONUS       = 'zdz_comp_bonus_per_job';
	const META_TIERS       = 'zdz_comp_tiers';
	const META_PAY_PERIOD  = 'zdz_comp_pay_period';
	const META_EXCLUDE_CC  = 'zdz_comp_exclude_card_fees';
	const META_SPLIT_POL   = 'zdz_comp_split_policy';
	const META_SPLIT_WT    = 'zdz_comp_split_weight';
	const META_SPLIT_OWN   = 'zdz_comp_split_own_share';
	const META_MIN_PARTY   = 'zdz_comp_minimum_party';         // opt-in flag: this party is covered by product minimums
	const META_IS_PIECE    = 'zdz_comp_is_piece_worker';       // shop/bench worker paid piece-rate

	/** Request-level cache of the code → plan map. */
	private static $code_map = null;

	/* ==================================================================
	 * BOOT
	 * ================================================================== */

	public static function init(): void {
		// Publish the mechanism enums + resolved config to any consumer that
		// prefers a filter to a class dependency (mirrors the Item Engine style).
		add_filter( 'zdz_compensation_structures', function ( $pre ) {
			return $pre ?: self::STRUCTURES;
		} );
		add_filter( 'zdz_compensation_split_policies', function ( $pre ) {
			return $pre ?: self::SPLIT_POLICIES;
		} );

		// Surface comp fields on the WP user profile for an admin who configures
		// per-party plans there. The Commission app also has its own settings UI.
		add_action( 'show_user_profile', [ __CLASS__, 'render_admin_fields' ] );
		add_action( 'edit_user_profile', [ __CLASS__, 'render_admin_fields' ] );
		add_action( 'personal_options_update', [ __CLASS__, 'save_admin_fields' ] );
		add_action( 'edit_user_profile_update', [ __CLASS__, 'save_admin_fields' ] );
	}

	/* ==================================================================
	 * GLOBAL MECHANISM CONFIG  — safe Core defaults, tenant-overridable.
	 *
	 * These are NOT sensitive business data: they carry no company, person or
	 * product name and no money — only the safe mechanism defaults the Playbook
	 * (§12) endorses shipping. A tenant narrows/extends them via the settings
	 * option or the mirrored filters; nobody may weaken the safety floor.
	 * ================================================================== */

	private static function settings(): array {
		$stored = get_option( self::OPT_SETTINGS, [] );
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}
		return $stored;
	}

	/**
	 * Payability gate. SAFETY FLOOR: commission is earned only on money that
	 * actually changed hands, and the provider's own status filter is never
	 * trusted (it has been observed to leak drafts). The two historical
	 * implementations disagreed on tolerance (0.005 vs 0.01) and status set
	 * (paid+partial vs paid-only); the crosswalk resolves it to 0.005 +
	 * [paid, partial], declared ONCE here.
	 */
	public static function payability(): array {
		$s = self::settings()['payability'] ?? [];
		$out = [
			'statuses'              => is_array( $s['statuses'] ?? null ) && $s['statuses'] ? array_map( 'strval', $s['statuses'] ) : [ 'paid', 'partial' ],
			'money_tolerance'       => isset( $s['money_tolerance'] ) ? (float) $s['money_tolerance'] : 0.005,
			// The floor: NEVER trust the provider's filter, regardless of config.
			'trust_provider_filter' => false,
			'status_source'         => [ 'v3_status', 'status' ], // string status is authoritative; never hand-map an integer code
		];
		return (array) apply_filters( 'zdz_compensation_payability', $out );
	}

	/**
	 * The pay calendar. `period_type: calendar_month` is Core; the old cron used
	 * a 30-day interval standing in for "monthly" — a defect, not a setting.
	 */
	public static function pay_calendar(): array {
		$s   = self::settings()['pay_calendar'] ?? [];
		$out = [
			'period_type'     => (string) ( $s['period_type'] ?? 'calendar_month' ),
			'finalize_on'     => (string) ( $s['finalize_on'] ?? '1st 00:00' ),
			'finalize_target' => (string) ( $s['finalize_target'] ?? 'prior_period' ),
			'timezone_source' => 'wp',   // WP-configured timezone, never server-local
			'frozen_fields'   => [
				'gross_billed', 'total_cogs', 'net_commissionable', 'net_attributed',
				'commission_amount', 'invoice_number', 'customer_name', 'date_completed', 'detail_json',
			],
		];
		return (array) apply_filters( 'zdz_compensation_pay_calendar', $out );
	}

	/** Shared-job split mechanics (each an explicit declared choice; all Core-safe). */
	public static function split_settings(): array {
		$s   = self::settings()['splits'] ?? [];
		$out = [
			'pool_rate_policy'       => in_array( $s['pool_rate_policy'] ?? '', [ 'max', 'min', 'average', 'first' ], true ) ? $s['pool_rate_policy'] : 'max',
			'on_full_policy_conflict'=> 'degrade_to_equal',
			'on_over_allocation'     => 'scale_down_with_warning',
			'on_under_allocation'    => 'leave_unallocated_to_house',
			'cent_allocation'        => 'largest_remainder',
		];
		return (array) apply_filters( 'zdz_compensation_split_settings', $out );
	}

	/**
	 * Attribution-precedence contract. SAFETY FLOOR: an inferred rep is NEVER
	 * payable (only a real document code or an explicit override row pays), and
	 * an unresolvable share on a shared job pays zero-and-flags, never full rate.
	 */
	public static function attribution(): array {
		$s   = self::settings()['attribution'] ?? [];
		$out = [
			'precedence'                  => [ 'document_code', 'override_row', 'inference' ],
			'inferred_is_payable'         => false,
			'code_format'                 => '/^[A-Z]{2,4}$/',
			'override_requires_plan'      => true,
			'unresolvable_share_on_shared'=> 'pay_zero_and_flag',
			// Tokens that look like codes but are places/sources, never people.
			// Ships EMPTY (the territory/source registers fill it); an empty list
			// means "resolve every token against configured plans".
			'reserved_tokens'             => is_array( $s['reserved_tokens'] ?? null ) ? array_map( 'strval', $s['reserved_tokens'] ) : [],
		];
		return (array) apply_filters( 'zdz_compensation_attribution', $out );
	}

	/**
	 * The card-processing fee rate used for RECONCILIATION only (the actual fee
	 * is read off the invoice line). Ships NULL — the historical "4%" lived in a
	 * comment with no constant; a tenant declares its own expected rate.
	 *
	 * @return float|null Fraction (e.g. 0.04), or null when not configured.
	 */
	public static function card_fee_rate(): ?float {
		$s = self::settings()['fees']['card_processing_expected_rate'] ?? null;
		$v = ( $s === null || $s === '' ) ? null : (float) $s;
		return apply_filters( 'zdz_compensation_card_fee_rate', $v );
	}

	/* ==================================================================
	 * PIECE RATES  — [IDENTITY], SHIP EMPTY. Keyed by ITEM ID (not an enum
	 * key) so counts and rates join on the same catalog identity — the
	 * parity contract (crosswalk CN-09 / CP-20).
	 * ================================================================== */

	/**
	 * The full piece-rate table: item_id => { rate:float, unit:string }.
	 * EMPTY out of the box. The tenant configures it; the Item Engine supplies
	 * the item ids so counts and rates cannot drift apart.
	 *
	 * @return array<string,array{rate:float,unit:string}>
	 */
	public static function piece_rates(): array {
		$raw = get_option( self::OPT_PIECE_RATES, [] );
		if ( ! is_array( $raw ) ) {
			$raw = [];
		}
		$out = [];
		foreach ( $raw as $item_id => $row ) {
			$item_id = sanitize_key( (string) $item_id );
			if ( $item_id === '' || ! is_array( $row ) ) {
				continue;
			}
			$out[ $item_id ] = [
				'rate' => max( 0.0, round( (float) ( $row['rate'] ?? 0 ), 2 ) ),
				'unit' => (string) ( $row['unit'] ?? 'per_item' ),
			];
		}
		return (array) apply_filters( 'zdz_compensation_piece_rates', $out );
	}

	/** One piece rate by item id, or null when the tenant has not configured it. */
	public static function piece_rate( string $item_id ): ?array {
		$item_id = sanitize_key( $item_id );
		$all     = self::piece_rates();
		return $all[ $item_id ] ?? null;
	}

	/** Eligibility rules for piece-rate pay (shop-only, exclusions, caps). All admin-set; safe empties. */
	public static function piece_rate_eligibility(): array {
		$s   = self::settings()['piece_rate_eligibility'] ?? [];
		$out = [
			'mode'                 => (string) ( $s['mode'] ?? 'company_wide' ),
			'require_shop'         => (bool) ( $s['require_shop'] ?? true ),
			'exclude_subtypes'     => is_array( $s['exclude_subtypes'] ?? null ) ? array_values( array_map( 'sanitize_key', $s['exclude_subtypes'] ) ) : [],
			'hardware_max_line'    => isset( $s['hardware_max_line'] ) ? (float) $s['hardware_max_line'] : null,
			'require_work_verb'    => (bool) ( $s['require_work_verb'] ?? true ),
		];
		return (array) apply_filters( 'zdz_compensation_piece_rate_eligibility', $out );
	}

	/**
	 * Save the piece-rate table. Only accepts item ids the Item Engine knows (or
	 * any id when the engine is absent/empty). Never seeds — writes only what an
	 * admin submits.
	 */
	public static function save_piece_rates( array $rows ): bool {
		$clean = [];
		foreach ( $rows as $item_id => $row ) {
			$item_id = sanitize_key( (string) $item_id );
			if ( $item_id === '' ) {
				continue;
			}
			$rate = isset( $row['rate'] ) && is_numeric( $row['rate'] ) ? max( 0.0, round( (float) $row['rate'], 2 ) ) : 0.0;
			$unit = sanitize_key( (string) ( $row['unit'] ?? 'per_item' ) ) ?: 'per_item';
			$clean[ $item_id ] = [ 'rate' => $rate, 'unit' => $unit ];
		}
		return update_option( self::OPT_PIECE_RATES, $clean, false );
	}

	/* ==================================================================
	 * PRODUCT-SCOPED MINIMUM-COMMISSION RULES  — [IDENTITY], SHIP EMPTY.
	 *
	 * Generalises a product-line minimum (e.g. a $500 job of a given subtype
	 * guarantees the product-percentage rep at least $100): a rule attached to a
	 * product SUBTYPE, applied only to configured parties and percentage
	 * structures. Names no product; ships with no rules.
	 * ================================================================== */

	/**
	 * @return array<int,array{id:string,item_subtype:string,min_job_gross:float,
	 *   min_commission:float,applies_to_structures:string[],applies_to_parties:int[]}>
	 */
	public static function product_minimums(): array {
		$raw = get_option( self::OPT_MINIMUMS, [] );
		if ( ! is_array( $raw ) ) {
			$raw = [];
		}
		$out = [];
		foreach ( $raw as $rule ) {
			if ( ! is_array( $rule ) || empty( $rule['item_subtype'] ) ) {
				continue;
			}
			$out[] = [
				'id'                    => sanitize_key( (string) ( $rule['id'] ?? $rule['item_subtype'] ) ),
				'item_subtype'          => sanitize_key( (string) $rule['item_subtype'] ),
				'min_job_gross'         => max( 0.0, (float) ( $rule['min_job_gross'] ?? 0 ) ),
				'min_commission'        => max( 0.0, (float) ( $rule['min_commission'] ?? 0 ) ),
				'applies_to_structures' => is_array( $rule['applies_to_structures'] ?? null ) ? array_values( array_map( 'strval', $rule['applies_to_structures'] ) ) : [ 'flat_rate', 'tiered' ],
				'applies_to_parties'    => is_array( $rule['applies_to_parties'] ?? null ) ? array_values( array_map( 'intval', $rule['applies_to_parties'] ) ) : [],
			];
		}
		return (array) apply_filters( 'zdz_compensation_product_minimums', $out );
	}

	public static function save_product_minimums( array $rules ): bool {
		$clean = [];
		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) || empty( $rule['item_subtype'] ) ) {
				continue;
			}
			$clean[] = [
				'id'                    => sanitize_key( (string) ( $rule['id'] ?? $rule['item_subtype'] ) ),
				'item_subtype'          => sanitize_key( (string) $rule['item_subtype'] ),
				'min_job_gross'         => max( 0.0, (float) ( $rule['min_job_gross'] ?? 0 ) ),
				'min_commission'        => max( 0.0, (float) ( $rule['min_commission'] ?? 0 ) ),
				'applies_to_structures' => array_values( array_map( 'strval', (array) ( $rule['applies_to_structures'] ?? [ 'flat_rate', 'tiered' ] ) ) ),
				'applies_to_parties'    => array_values( array_map( 'intval', (array) ( $rule['applies_to_parties'] ?? [] ) ) ),
			];
		}
		return update_option( self::OPT_MINIMUMS, $clean, false );
	}

	/* ==================================================================
	 * LEDGER KINDS  — money rows that are not things you sell.
	 *
	 * The kind SET and its flags are a Core safety mechanism (a refund or a
	 * processing fee must never earn commission). The default set uses only
	 * generic business English — no company/person/product name — so it is safe
	 * to ship, and a tenant extends it via the option/filter.
	 * ================================================================== */

	public static function ledger_kinds(): array {
		$core = [
			'discount' => [
				'label'                    => __( 'Discount / credit', 'zorderz' ),
				'sign'                     => 'negative',
				'counts_toward_revenue'    => true,
				'counts_toward_commission' => true,   // a discount reduces the commissionable base
				'counts_toward_units'      => false,
				'aliases'                  => [ 'discount', 'credit', 'price adjustment', 'reduction', 'coupon' ],
			],
			'card_fee' => [
				'label'                    => __( 'Card processing fee', 'zorderz' ),
				'sign'                     => 'positive',
				'counts_toward_revenue'    => false,
				'counts_toward_commission' => false,  // SAFETY: a collected fee is not a sale
				'counts_toward_units'      => false,
				'aliases'                  => [ 'credit card', 'cc fee', 'card processing', 'processing fee', 'convenience fee' ],
			],
			'refund' => [
				'label'                    => __( 'Refund / overpayment', 'zorderz' ),
				'sign'                     => 'negative',
				'counts_toward_revenue'    => false,
				'counts_toward_commission' => false,  // SAFETY: money returned is never commissionable
				'counts_toward_units'      => false,
				'aliases'                  => [ 'refund', 'overpayment', 'reimbursement' ],
			],
			'gratuity' => [
				'label'                    => __( 'Gratuity / tip', 'zorderz' ),
				'sign'                     => 'positive',
				'counts_toward_revenue'    => false,
				'counts_toward_commission' => false,
				'counts_toward_units'      => false,
				'aliases'                  => [ 'tip', 'tips', 'gratuity' ],
			],
			'pass_through' => [
				'label'                    => __( 'Pass-through / non-sale payment', 'zorderz' ),
				'sign'                     => 'positive',
				'counts_toward_revenue'    => false,
				'counts_toward_commission' => false,
				'counts_toward_units'      => false,
				// generic terms only — a tenant adds its own via the option
				'aliases'                  => [ 'consulting', 'setup payment', 'quarterly payment', 'reimbursable' ],
			],
		];

		$tenant = get_option( self::OPT_LEDGER_KINDS, [] );
		if ( is_array( $tenant ) ) {
			foreach ( $tenant as $key => $def ) {
				$key = sanitize_key( (string) $key );
				if ( $key !== '' && is_array( $def ) ) {
					$core[ $key ] = array_merge( $core[ $key ] ?? [], $def );
				}
			}
		}
		return (array) apply_filters( 'zdz_compensation_ledger_kinds', $core );
	}

	/**
	 * Classify a line description as a ledger kind, or null when it is ordinary
	 * product/service revenue. Longest-alias-wins, so "credit card processing"
	 * beats a bare "card". Pure string mechanism — no LLM, no product taxonomy.
	 *
	 * @return array|null { kind:string, def:array } or null
	 */
	public static function classify_ledger_kind( string $description ): ?array {
		$lower = strtolower( trim( $description ) );
		if ( $lower === '' ) {
			return null;
		}
		$best     = null;
		$best_len = 0;
		foreach ( self::ledger_kinds() as $kind => $def ) {
			foreach ( (array) ( $def['aliases'] ?? [] ) as $alias ) {
				$alias = strtolower( trim( (string) $alias ) );
				if ( $alias !== '' && strpos( $lower, $alias ) !== false && strlen( $alias ) > $best_len ) {
					$best     = [ 'kind' => $kind, 'def' => $def ];
					$best_len = strlen( $alias );
				}
			}
		}
		return $best;
	}

	/* ==================================================================
	 * PER-PARTY PLANS  — [IDENTITY], SHIP EMPTY.
	 *
	 * Reads a party's plan from user meta. An unconfigured party returns
	 * `configured => false` and NO baked-in rate — the caller must treat that
	 * as "no plan" (flag + pay nothing), never as a silent default.
	 * ================================================================== */

	/**
	 * @param int $party_id A ZDZ_Party / WP user id.
	 * @return array The plan. `configured` is false until an admin sets one.
	 */
	public static function get_plan( int $party_id ): array {
		$structure = (string) get_user_meta( $party_id, self::META_STRUCTURE, true );
		$configured = ( $structure !== '' );

		$rate_raw = get_user_meta( $party_id, self::META_RATE, true );
		$tiers_raw = get_user_meta( $party_id, self::META_TIERS, true );
		$exclude_cc = get_user_meta( $party_id, self::META_EXCLUDE_CC, true );

		$split_policy = get_user_meta( $party_id, self::META_SPLIT_POL, true );
		if ( ! in_array( $split_policy, self::SPLIT_POLICIES, true ) ) {
			$split_policy = 'full';
		}
		$split_wt_raw  = get_user_meta( $party_id, self::META_SPLIT_WT, true );
		$split_own_raw = get_user_meta( $party_id, self::META_SPLIT_OWN, true );

		return [
			'party_id'          => $party_id,
			'display_name'      => self::party_display_name( $party_id ),
			'configured'        => $configured,
			// No default rate/structure: an empty plan is empty on purpose.
			'structure'         => $configured ? $structure : '',
			'rate_percent'      => ( $rate_raw === '' || $rate_raw === false ) ? null : (float) $rate_raw,
			'base_pay'          => (float) ( get_user_meta( $party_id, self::META_BASE_PAY, true ) ?: 0 ),
			'bonus_per_job'     => (float) ( get_user_meta( $party_id, self::META_BONUS, true ) ?: 0 ),
			'tiers'             => is_array( $tiers_raw ) ? $tiers_raw : [],
			'pay_period'        => (string) ( get_user_meta( $party_id, self::META_PAY_PERIOD, true ) ?: '' ),
			// The card-fee exclusion default is FALSE (the safe, explicit choice):
			// an unset flag no longer silently hides fees the way the source did.
			'exclude_card_fees' => ( $exclude_cc === '' || $exclude_cc === false ) ? false : (bool) $exclude_cc,
			'code'              => self::party_code( $party_id ),
			'split_policy'      => $split_policy,
			'split_weight'      => ( $split_wt_raw === '' || $split_wt_raw === false ) ? 1.0 : max( 0.0, (float) $split_wt_raw ),
			'split_own_share'   => ( $split_own_raw === '' || $split_own_raw === false ) ? 0.0 : max( 0.0, min( 100.0, (float) $split_own_raw ) ),
			'minimum_party'     => get_user_meta( $party_id, self::META_MIN_PARTY, true ) === '1',
			'is_piece_worker'   => get_user_meta( $party_id, self::META_IS_PIECE, true ) === '1',
		];
	}

	/** Save a party's plan. Admin action; validates the enums; never seeds. */
	public static function save_plan( int $party_id, array $data ): bool {
		if ( $party_id <= 0 ) {
			return false;
		}
		if ( array_key_exists( 'structure', $data ) ) {
			$structure = in_array( $data['structure'], self::STRUCTURES, true ) ? $data['structure'] : '';
			update_user_meta( $party_id, self::META_STRUCTURE, $structure );
		}
		if ( array_key_exists( 'rate_percent', $data ) ) {
			$r = $data['rate_percent'];
			update_user_meta( $party_id, self::META_RATE, ( $r === '' || $r === null ) ? '' : max( 0.0, min( 100.0, (float) $r ) ) );
		}
		if ( array_key_exists( 'base_pay', $data ) ) {
			update_user_meta( $party_id, self::META_BASE_PAY, max( 0.0, (float) $data['base_pay'] ) );
		}
		if ( array_key_exists( 'bonus_per_job', $data ) ) {
			update_user_meta( $party_id, self::META_BONUS, max( 0.0, (float) $data['bonus_per_job'] ) );
		}
		if ( array_key_exists( 'pay_period', $data ) ) {
			$pp = in_array( $data['pay_period'], self::PAY_PERIODS, true ) ? $data['pay_period'] : '';
			update_user_meta( $party_id, self::META_PAY_PERIOD, $pp );
		}
		if ( array_key_exists( 'exclude_card_fees', $data ) ) {
			update_user_meta( $party_id, self::META_EXCLUDE_CC, ! empty( $data['exclude_card_fees'] ) ? '1' : '0' );
		}
		if ( array_key_exists( 'split_policy', $data ) ) {
			$sp = in_array( $data['split_policy'], self::SPLIT_POLICIES, true ) ? $data['split_policy'] : 'full';
			update_user_meta( $party_id, self::META_SPLIT_POL, $sp );
		}
		if ( array_key_exists( 'split_weight', $data ) ) {
			update_user_meta( $party_id, self::META_SPLIT_WT, max( 0.0, (float) $data['split_weight'] ) );
		}
		if ( array_key_exists( 'split_own_share', $data ) ) {
			update_user_meta( $party_id, self::META_SPLIT_OWN, max( 0.0, min( 100.0, (float) $data['split_own_share'] ) ) );
		}
		if ( array_key_exists( 'tiers', $data ) && is_array( $data['tiers'] ) ) {
			$clean = [];
			foreach ( $data['tiers'] as $tier ) {
				$ceiling = ( isset( $tier['ceiling'] ) && $tier['ceiling'] !== '' && $tier['ceiling'] !== null ) ? (float) $tier['ceiling'] : null;
				$clean[] = [ 'ceiling' => $ceiling, 'rate' => max( 0.0, min( 100.0, (float) ( $tier['rate'] ?? 0 ) ) ) ];
			}
			update_user_meta( $party_id, self::META_TIERS, $clean );
		}
		if ( array_key_exists( 'minimum_party', $data ) ) {
			update_user_meta( $party_id, self::META_MIN_PARTY, ! empty( $data['minimum_party'] ) ? '1' : '0' );
		}
		if ( array_key_exists( 'is_piece_worker', $data ) ) {
			update_user_meta( $party_id, self::META_IS_PIECE, ! empty( $data['is_piece_worker'] ) ? '1' : '0' );
		}
		self::$code_map = null;
		return true;
	}

	/**
	 * All configured plans (parties whose structure meta is set). Empty on a
	 * fresh install. Used by the split engine and the admin roster.
	 */
	public static function all_plans(): array {
		$plans = [];
		$users = get_users( [
			'meta_key'     => self::META_STRUCTURE,
			'meta_compare' => 'EXISTS',
			'fields'       => [ 'ID' ],
			'number'       => -1,
		] );
		foreach ( $users as $u ) {
			$plan = self::get_plan( (int) $u->ID );
			if ( $plan['configured'] ) {
				$plans[] = $plan;
			}
		}
		return (array) apply_filters( 'zdz_compensation_all_plans', $plans );
	}

	/**
	 * Resolve a salesperson short code to a configured plan, or null.
	 *
	 * The code comes from the party roster: ZDZ_Party publishes it under the key
	 * `initials` (NOT `code`), matched CASE-INSENSITIVELY (Playbook §13). A
	 * request-level cache avoids re-scanning parties for every invoice.
	 */
	public static function plan_by_code( string $code ): ?array {
		$code = strtoupper( trim( $code ) );
		if ( $code === '' ) {
			return null;
		}
		if ( self::$code_map === null ) {
			self::$code_map = [];
			foreach ( self::all_plans() as $plan ) {
				$pc = strtoupper( trim( (string) ( $plan['code'] ?? '' ) ) );
				if ( $pc !== '' && ! isset( self::$code_map[ $pc ] ) ) {
					self::$code_map[ $pc ] = $plan;
				}
			}
		}
		return self::$code_map[ $code ] ?? null;
	}

	/** Drop the request-level code→plan cache (after a save, or between tests). */
	public static function flush_code_cache(): void {
		self::$code_map = null;
	}

	/* ==================================================================
	 * PARTY-ROSTER GLUE  — read identity from ZDZ_Party, never a local roster.
	 * ================================================================== */

	/**
	 * A party's short code. Reads ZDZ_Party's published roster and takes the
	 * `initials` key (§13). Falls back to the WP display name's initials-free
	 * meta only if the roster has no entry. Never hardcodes a code.
	 */
	private static function party_code( int $party_id ): string {
		if ( class_exists( 'ZDZ_Party' ) && method_exists( 'ZDZ_Party', 'selectable_people' ) ) {
			foreach ( (array) ZDZ_Party::selectable_people() as $person ) {
				if ( (int) ( $person['id'] ?? $person['user_id'] ?? 0 ) === $party_id ) {
					// The roster publishes the short code under 'initials', not 'code'.
					return strtoupper( trim( (string) ( $person['initials'] ?? '' ) ) );
				}
			}
		}
		// Optional per-party override meta (still not a hardcoded roster).
		$meta = get_user_meta( $party_id, 'zdz_party_code', true );
		return strtoupper( trim( (string) $meta ) );
	}

	private static function party_display_name( int $party_id ): string {
		$u = get_userdata( $party_id );
		return $u ? $u->display_name : (string) $party_id;
	}

	/* ==================================================================
	 * LABELS
	 * ================================================================== */

	public static function structure_label( string $structure ): string {
		$labels = [
			'flat_rate'     => __( 'Flat rate', 'zorderz' ),
			'tiered'        => __( 'Tiered', 'zorderz' ),
			'per_job_bonus' => __( 'Per-job bonus', 'zorderz' ),
			'salary_only'   => __( 'Salary only', 'zorderz' ),
			'piece_rate'    => __( 'Piece rate', 'zorderz' ),
			'formula'       => __( 'Formula', 'zorderz' ),
		];
		return $labels[ $structure ] ?? ucfirst( str_replace( '_', ' ', $structure ) );
	}

	public static function split_policy_label( string $policy ): string {
		$labels = [
			'full'      => __( 'Full rate (no split)', 'zorderz' ),
			'equal'     => __( 'Equal share (1/N)', 'zorderz' ),
			'weight'    => __( 'Weighted share', 'zorderz' ),
			'own_share' => __( 'Own fixed share %', 'zorderz' ),
		];
		return $labels[ $policy ] ?? ucfirst( str_replace( '_', ' ', $policy ) );
	}

	/* ==================================================================
	 * WP USER-PROFILE ADMIN FIELDS  (an admin may set a plan here too)
	 * ================================================================== */

	public static function render_admin_fields( $user ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$p = self::get_plan( (int) $user->ID );
		?>
		<h2><?php esc_html_e( 'Compensation plan', 'zorderz' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Compensation ships empty. Nothing here is set until you set it — an unconfigured plan earns nothing and is flagged for review, never paid at a default rate.', 'zorderz' ); ?></p>
		<table class="form-table">
			<tr>
				<th><label for="zdz_comp_structure"><?php esc_html_e( 'Structure', 'zorderz' ); ?></label></th>
				<td>
					<select name="zdz_comp_structure" id="zdz_comp_structure">
						<option value=""><?php esc_html_e( '— not configured —', 'zorderz' ); ?></option>
						<?php foreach ( self::STRUCTURES as $val ) : ?>
							<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $p['structure'], $val ); ?>><?php echo esc_html( self::structure_label( $val ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="zdz_comp_rate"><?php esc_html_e( 'Commission rate (%)', 'zorderz' ); ?></label></th>
				<td><input type="number" name="zdz_comp_rate" id="zdz_comp_rate" value="<?php echo esc_attr( $p['rate_percent'] === null ? '' : $p['rate_percent'] ); ?>" step="0.5" min="0" max="100" class="small-text"></td>
			</tr>
			<tr>
				<th><label for="zdz_comp_base_pay"><?php esc_html_e( 'Base pay per period', 'zorderz' ); ?></label></th>
				<td><input type="number" name="zdz_comp_base_pay" id="zdz_comp_base_pay" value="<?php echo esc_attr( $p['base_pay'] ); ?>" step="1" min="0" class="small-text"></td>
			</tr>
			<tr>
				<th><label for="zdz_comp_bonus"><?php esc_html_e( 'Bonus per job', 'zorderz' ); ?></label></th>
				<td><input type="number" name="zdz_comp_bonus" id="zdz_comp_bonus" value="<?php echo esc_attr( $p['bonus_per_job'] ); ?>" step="1" min="0" class="small-text"></td>
			</tr>
			<tr>
				<th><label for="zdz_comp_split_policy"><?php esc_html_e( 'Shared-job split', 'zorderz' ); ?></label></th>
				<td>
					<select name="zdz_comp_split_policy" id="zdz_comp_split_policy">
						<?php foreach ( self::SPLIT_POLICIES as $val ) : ?>
							<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $p['split_policy'], $val ); ?>><?php echo esc_html( self::split_policy_label( $val ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="zdz_comp_split_weight"><?php esc_html_e( 'Split weight', 'zorderz' ); ?></label></th>
				<td><input type="number" name="zdz_comp_split_weight" id="zdz_comp_split_weight" value="<?php echo esc_attr( $p['split_weight'] ); ?>" step="1" min="0" class="small-text"></td>
			</tr>
			<tr>
				<th><label for="zdz_comp_split_own"><?php esc_html_e( 'Own share (%)', 'zorderz' ); ?></label></th>
				<td><input type="number" name="zdz_comp_split_own" id="zdz_comp_split_own" value="<?php echo esc_attr( $p['split_own_share'] ); ?>" step="1" min="0" max="100" class="small-text"></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Card fees', 'zorderz' ); ?></th>
				<td><label><input type="checkbox" name="zdz_comp_exclude_cc" value="1" <?php checked( $p['exclude_card_fees'] ); ?>> <?php esc_html_e( 'Exclude card-processing fees from this rep\'s commissionable base', 'zorderz' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Product minimums', 'zorderz' ); ?></th>
				<td><label><input type="checkbox" name="zdz_comp_min_party" value="1" <?php checked( $p['minimum_party'] ); ?>> <?php esc_html_e( 'This rep is covered by product-scoped minimum-commission rules', 'zorderz' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Piece worker', 'zorderz' ); ?></th>
				<td><label><input type="checkbox" name="zdz_comp_is_piece" value="1" <?php checked( $p['is_piece_worker'] ); ?>> <?php esc_html_e( 'Shop/bench worker paid by piece rate (sees a "My pay" panel; not commissions)', 'zorderz' ); ?></label></td>
			</tr>
		</table>
		<?php
	}

	public static function save_admin_fields( int $user_id ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::save_plan( $user_id, [
			'structure'         => isset( $_POST['zdz_comp_structure'] ) ? sanitize_text_field( wp_unslash( $_POST['zdz_comp_structure'] ) ) : '',
			'rate_percent'      => isset( $_POST['zdz_comp_rate'] ) ? sanitize_text_field( wp_unslash( $_POST['zdz_comp_rate'] ) ) : '',
			'base_pay'          => isset( $_POST['zdz_comp_base_pay'] ) ? sanitize_text_field( wp_unslash( $_POST['zdz_comp_base_pay'] ) ) : 0,
			'bonus_per_job'     => isset( $_POST['zdz_comp_bonus'] ) ? sanitize_text_field( wp_unslash( $_POST['zdz_comp_bonus'] ) ) : 0,
			'split_policy'      => isset( $_POST['zdz_comp_split_policy'] ) ? sanitize_text_field( wp_unslash( $_POST['zdz_comp_split_policy'] ) ) : 'full',
			'split_weight'      => isset( $_POST['zdz_comp_split_weight'] ) ? sanitize_text_field( wp_unslash( $_POST['zdz_comp_split_weight'] ) ) : 1,
			'split_own_share'   => isset( $_POST['zdz_comp_split_own'] ) ? sanitize_text_field( wp_unslash( $_POST['zdz_comp_split_own'] ) ) : 0,
			'exclude_card_fees' => ! empty( $_POST['zdz_comp_exclude_cc'] ),
			'minimum_party'     => ! empty( $_POST['zdz_comp_min_party'] ),
			'is_piece_worker'   => ! empty( $_POST['zdz_comp_is_piece'] ),
		] );
	}
}

ZDZ_Compensation::init();
