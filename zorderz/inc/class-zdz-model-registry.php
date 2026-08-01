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
 * populated only by a tenant filter or an upgrade migration. Ships neutral.
 *
 * Crosswalk: 05 §C (P-37, P-41), §D (T-06 auditor pairing); Playbook §2.5, §6.
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
	 * @return array<string,array{tier:string,purpose:string}>
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
		);
		$extra = apply_filters( 'zdz_model_slots', array() );
		if ( is_array( $extra ) ) {
			$slots = array_merge( $slots, $extra );
		}
		return $slots;
	}

	/** The platform base model — read from Core settings, never hardcoded here. */
	public static function base_model(): string {
		if ( class_exists( 'ZDZ_Core_Settings' ) ) {
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
	 * Resolve the model for a task slot. Order:
	 *   1. per-slot option  (zdz_model_slot_<slot>)
	 *   2. the `zdz_model_for` filter (a tenant/plugin override)
	 *   3. the platform base model
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

		if ( '' === $model ) {
			$model = self::base_model();
		}

		$model = self::remap( $model );

		// If the resolved model is known-unavailable, fall back for this slot's tier.
		if ( '' !== $model && ! self::is_available( $model ) ) {
			$slots = self::slots();
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
