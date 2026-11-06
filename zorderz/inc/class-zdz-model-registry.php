<?php
/**
 * ZDZ_Model_Registry — one place that owns which model runs which task.
 *
 * The analytics/chat engine hardcoded ~12 model names across nine clients: the
 * analyst model, a planner model, a self-check auditor (deliberately a different
 * family), a memory extractor, a transcription bot, a vision pass, a dedup pass —
 * each typed in as a literal, with three override branches routing around a retired
 * bot and NO allow-list validation, so an install still holding a decommissioned
 * model name failed silently every turn once that model was gone.
 *
 * This service centralises all of it:
 *
 *   - PER-TASK SLOTS. Each kind of call resolves its model through a named slot
 *     (chat, planner, auditor, memory, transcription, vision, dedup, kiosk) rather
 *     than a literal. A slot resolves from an option, then a filter, then the
 *     platform's base model — so there is exactly one place to change a model.
 *   - A CAPABILITY TIER MAP + CROSS-VENDOR FALLBACK. A slot can declare the tier it
 *     needs (frontier / fast / audio / vision); if the configured model is
 *     unavailable the registry falls back within, then across, vendors — never to a
 *     wrong answer, always to a working model or an explicit failure.
 *   - AN IDEMPOTENT RETIRED-VALUE REMAP. A model id that has been decommissioned is
 *     remapped to its successor on read, so a stale stored value cannot break a
 *     turn silently. The remap is idempotent: applying it twice is applying it once.
 *
 * Poe stays the v1 gateway (ZDZ_Core_Poe), but every knob it needs — API key,
 * model/bot name per task — comes from config, not a literal. NO vendor, product or
 * model name is hardcoded in this file: the base default is read from
 * ZDZ_Core_Settings, and the capability, fallback and retired maps ship EMPTY,
 * populated only by a business's filter or an upgrade migration. Ships neutral.
 *
 * @since   1.1.0
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Model_Registry {

	/** Option prefix for a per-slot model override (e.g. zdz_model_slot_chat). */
	const OPT_PREFIX = 'zdz_model_slot_';

	/**
	 * The task slots the platform knows about, each with the capability tier it
	 * wants and a one-line purpose. A slot is CONFIG: which model fills it is not
	 * decided here. Ships with no model literals.
	 *
	 * A slot marked 'inherit_base' => false never falls back to the platform base model:
	 * the Ai router's slots name models on its native provider, and the base model is a
	 * gateway handle that provider would not recognise. Such a slot left empty stays ''.
	 *
	 * @return array<string,array{tier:string,purpose:string,inherit_base?:bool}>
	 */
	public static function slots(): array {
		$slots = array(
			'chat'          => array( 'tier' => 'frontier', 'purpose' => 'the analyst that answers a chat turn' ),
			'planner'       => array( 'tier' => 'fast',     'purpose' => 'decides what data to fetch for a turn' ),
			'auditor'       => array( 'tier' => 'frontier', 'purpose' => 'a cross-family self-check of the analyst\'s answer' ),
			'memory'        => array( 'tier' => 'fast',     'purpose' => 'extracts durable memories from a turn' ),
			'transcription' => array( 'tier' => 'audio',    'purpose' => 'transcribes an audio note' ),
			'vision'        => array( 'tier' => 'vision',   'purpose' => 'reads an image or handwritten note' ),
			'dedup'         => array( 'tier' => 'fast',     'purpose' => 'proposes duplicate-record merges' ),
			'kiosk'         => array( 'tier' => 'fast',     'purpose' => 'the terse shared-device answerer' ),
			// The Ai router (ZDZ_Core_LLM): every kind of call, a call with little time left, after the primary fails.
			'llm_primary'   => array( 'tier' => 'frontier', 'purpose' => 'the Ai router\'s model for every kind of call', 'inherit_base' => false ),
			'llm_quick'     => array( 'tier' => 'fast',     'purpose' => 'the Ai router\'s model when a call has little time left', 'inherit_base' => false ),
			'llm_fallback'  => array( 'tier' => 'frontier', 'purpose' => 'the Ai router\'s model after the primary fails', 'inherit_base' => false ),
			// The gateway: a model with web search, asked for findings first when the chosen model has none.
			'web'           => array( 'tier' => 'fast',     'purpose' => 'searches the web for findings when the model asked has no web search', 'inherit_base' => false ),
		);
		$extra = apply_filters( 'zdz_model_slots', array() );
		if ( is_array( $extra ) ) {
			$slots = array_merge( $slots, $extra );
		}
		return $slots;
	}

	/** The platform base model — read from Core settings, never hardcoded here. */
	public static function base_model(): string {
		if ( class_exists( 'ZDZ_Core_Settings' ) && method_exists( 'ZDZ_Core_Settings', 'get_ai_model' ) ) {
			$m = (string) ZDZ_Core_Settings::get_ai_model();
			if ( '' !== $m ) {
				return self::remap( $m );
			}
		}
		// Last-resort neutral default is supplied by the gateway, not this registry,
		// so no vendor/model literal lives in this file.
		return self::remap( (string) apply_filters( 'zdz_model_base_default', '' ) );
	}

	/**
	 * The model handles offered in the Settings AI-model chooser. Ships EMPTY — Core
	 * names no model; a tenant/connections pack supplies the roster via the
	 * `zdz_model_choices` filter, either as a flat list of handles
	 * (array( 'Handle-A', 'Handle-B' )) or as handle => label pairs. When this is
	 * empty the Settings UI renders a free-text field so an operator on any gateway
	 * can type the handle their connections pack resolves. No literal here.
	 *
	 * @return array
	 */
	public static function choices(): array {
		$choices = apply_filters( 'zdz_model_choices', array() );
		return is_array( $choices ) ? $choices : array();
	}

	/**
	 * Resolve the model for a task slot. Order:
	 *   1. per-slot option  (zdz_model_slot_<slot>)
	 *   2. the `zdz_model_for` filter (a tenant/plugin override)
	 *   3. the platform base model (not for a slot marked 'inherit_base' => false)
	 * then the retired-value remap (idempotent) and an availability check with
	 * cross-vendor fallback.
	 *
	 * @param string $slot
	 * @return string the model/bot name to send to the gateway ('' if none configured)
	 */
	public static function model_for( string $slot ): string {
		$slot  = sanitize_key( $slot );
		$model = (string) get_option( self::OPT_PREFIX . $slot, '' );

		$model = (string) apply_filters( 'zdz_model_for', $model, $slot );

		$slots = self::slots();
		if ( '' === $model && false !== ( $slots[ $slot ]['inherit_base'] ?? true ) ) {
			$model = self::base_model();
		}

		$model = self::remap( $model );

		// If the resolved model is known-unavailable, fall back for this slot's tier.
		if ( '' !== $model && ! self::is_available( $model ) ) {
			$tier  = $slots[ $slot ]['tier'] ?? 'frontier';
			$fb    = self::fallback_for( $model, $tier );
			if ( '' !== $fb ) {
				error_log( sprintf( '[ZDZ_Model_Registry] slot "%s": model unavailable, falling back.', $slot ) );
				$model = self::remap( $fb );
			}
		}

		return $model;
	}

	/** Bot name is the model name for the Poe gateway; kept as a named accessor. */
	public static function bot_for( string $slot ): string {
		return self::model_for( $slot );
	}

	/**
	 * The capability tier map: model id => tier. Ships EMPTY — the platform makes no
	 * claim about a specific vendor's line-up; a tenant/upgrade populates it.
	 *
	 * @return array<string,string>
	 */
	public static function capabilities(): array {
		$map = apply_filters( 'zdz_model_capabilities', array() );
		return is_array( $map ) ? $map : array();
	}

	/**
	 * A model is available unless a capability/health map says otherwise. Ships
	 * permissive (unknown ⇒ available) so a fresh install never blocks a turn; a
	 * tenant that tracks availability tightens it via `zdz_model_available`.
	 */
	public static function is_available( string $model ): bool {
		if ( '' === $model ) {
			return false;
		}
		return (bool) apply_filters( 'zdz_model_available', true, $model );
	}

	/**
	 * Cross-vendor fallback for an unavailable model. Ships EMPTY (no vendor pairs
	 * baked in); a tenant/upgrade supplies `zdz_model_fallback` as model => successor,
	 * or a tier-keyed default via `zdz_model_fallback_by_tier`.
	 *
	 * @return string successor model, or '' if none configured
	 */
	public static function fallback_for( string $model, string $tier = '' ): string {
		$direct = apply_filters( 'zdz_model_fallback', array(), $model, $tier );
		if ( is_array( $direct ) && isset( $direct[ $model ] ) ) {
			return (string) $direct[ $model ];
		}
		$by_tier = apply_filters( 'zdz_model_fallback_by_tier', array(), $tier );
		if ( is_array( $by_tier ) && '' !== $tier && isset( $by_tier[ $tier ] ) ) {
			return (string) $by_tier[ $tier ];
		}
		return '';
	}

	/**
	 * Idempotent remap of a retired/decommissioned model id to its successor. Ships
	 * EMPTY — no vendor model names appear here; an upgrade migration or a tenant
	 * supplies `zdz_model_retired_map` (old => new). Idempotent because the result
	 * is looked up again once and a value that maps to itself (or is absent) is
	 * returned unchanged.
	 */
	public static function remap( string $model ): string {
		$map = apply_filters( 'zdz_model_retired_map', array() );
		if ( is_array( $map ) && isset( $map[ $model ] ) && (string) $map[ $model ] !== $model ) {
			$next = (string) $map[ $model ];
			// One more hop covers a two-step retirement chain; then stop (idempotent).
			if ( isset( $map[ $next ] ) && (string) $map[ $next ] !== $next ) {
				$next = (string) $map[ $next ];
			}
			return $next;
		}
		return $model;
	}

	/* ── The model policy every road shares: when to try another model, a model that is gone, web search ── */

	/**
	 * HTTP statuses after which another model may answer where this one did not: the network or the provider timed out
	 * (0, 408), the model is missing (404) or busy (429, 529), the provider failed (5xx), or the body was refused (400,
	 * 413, 422: the other model gets a body built for it). Never 401 or 403: every model is reached with the same key,
	 * so a key problem is not a model problem and no other model would do better.
	 */
	const FAILOVER_STATUSES = array( 0, 400, 404, 408, 413, 422, 429, 500, 502, 503, 504, 529 );

	/** How long a model that answered 404 is remembered as gone (seconds), so it is not asked (and paid for) on every call. */
	const GONE_TTL = 3600;

	/** Would another model plausibly answer after this status? PURE. */
	public static function fails_over( int $status ): bool {
		if ( 401 === $status || 403 === $status ) {
			return false;
		}
		return in_array( $status, self::FAILOVER_STATUSES, true ) || ( $status >= 500 && $status <= 599 );
	}

	private static function gone_key( string $model, string $provider ): string {
		return 'zdz_model_gone_' . md5( strtolower( $provider . '|' . trim( $model ) ) );
	}

	/** Remember that a provider answered 404 for this model: it is skipped as a fallback for GONE_TTL. */
	public static function mark_gone( string $model, string $provider = '' ): void {
		if ( '' !== trim( $model ) && function_exists( 'set_transient' ) ) {
			set_transient( self::gone_key( $model, $provider ), 1, self::GONE_TTL );
		}
	}

	/** Did this provider answer 404 for this model within the last GONE_TTL? */
	public static function is_gone( string $model, string $provider = '' ): bool {
		return '' !== trim( $model ) && function_exists( 'get_transient' ) && false !== get_transient( self::gone_key( $model, $provider ) );
	}

	/**
	 * Can this model search the web? The `zdz_model_web_capable` filter answers with a map, model id => bool (a
	 * connections pack knows which of its models search). A model the map does not name is taken as able, as before;
	 * only one marked false is never sent a web search.
	 */
	public static function web_capable( string $model ): bool {
		$map = function_exists( 'apply_filters' ) ? apply_filters( 'zdz_model_web_capable', array() ) : array();
		if ( is_array( $map ) && array_key_exists( $model, $map ) ) {
			return (bool) $map[ $model ];
		}
		return true;
	}

	/**
	 * The model to ask for web findings on behalf of $for, which has no web search: the `web` slot's model, when one is
	 * set, can search and is not $for itself and not gone. '' when there is none.
	 */
	public static function web_model( string $for = '', string $provider = '' ): string {
		$m = self::model_for( 'web' );
		if ( '' === $m || $m === $for || ! self::web_capable( $m ) || self::is_gone( $m, $provider ) ) {
			return '';
		}
		return $m;
	}

	/**
	 * A ready-to-use Poe gateway bound to a slot's resolved model. The gateway owns
	 * the endpoint and reads the API key from Core settings; this registry supplies
	 * only the model. Returns null if the gateway class is unavailable.
	 *
	 * @return ZDZ_Core_Poe|null
	 */
	public static function gateway( string $slot = 'chat' ): ?ZDZ_Core_Poe {
		if ( ! class_exists( 'ZDZ_Core_Poe' ) ) {
			return null;
		}
		return new ZDZ_Core_Poe( '', self::model_for( $slot ) );
	}

	public static function init(): void {
		/* Stateless resolver — nothing to hook. init() kept for the self-boot convention. */
	}
}

ZDZ_Model_Registry::init();
