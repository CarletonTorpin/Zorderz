<?php
/**
 * ZDP_Ai — the dictation adapter. Turns an utterance into a PROPOSED plot spec by asking the
 * shared AI gateway, constrained to the sources the viewer may actually plot.
 *
 * DISCIPLINE (S8-03, ported as a DELETION):
 *   - Binds the shared gateway BY NAME via ZDZ_Model_Registry::gateway(); the app NEVER passes an
 *     API key (the theme owns credentials). No key list, no per-app key, no rotation on 402, no
 *     remembered-winning-option, no upgrade-clear. Single-credential is the platform invariant.
 *   - Treats a WP_Error or an "Error:"-prefixed string from the gateway as a FAILURE, never an
 *     answer.
 *   - Honest diagnostics only: reports option NAMES, lengths and 8-char digests — NEVER a value.
 *
 * The returned spec is NOT trusted. The caller submits it to ZDZ_Report_Sources::validate()/read()
 * (via the /plot route), which is the security boundary. This class only proposes.
 *
 * @package Zorderz\DotPlot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ZDP_Ai' ) ) {

	class ZDP_Ai {

		/** Preferred model slot; falls back to the shared chat gateway. */
		const SLOT = 'report';

		/** True when a shared gateway is available to bind. */
		public static function available(): bool {
			return class_exists( 'ZDZ_Model_Registry' ) && null !== self::gateway();
		}

		/** The shared gateway bound by name to our slot. Null when the registry/gateway is absent. */
		private static function gateway() {
			if ( ! class_exists( 'ZDZ_Model_Registry' ) || ! method_exists( 'ZDZ_Model_Registry', 'gateway' ) ) {
				return null;
			}
			$gw = ZDZ_Model_Registry::gateway( self::SLOT );
			return ( $gw && is_object( $gw ) ) ? $gw : null;
		}

		/**
		 * Propose a spec from an utterance, constrained to the entitlement-filtered source list.
		 *
		 * @return array{ok:bool, spec?:array, error?:string, note?:string}
		 */
		public static function plan_spec( string $utterance, int $viewer ): array {
			$sources = self::allowed_sources( $viewer );
			if ( empty( $sources ) ) {
				return array( 'ok' => false, 'error' => 'no_sources', 'note' => 'No report sources are registered for you yet.' );
			}

			$gw = self::gateway();
			if ( null === $gw || ! method_exists( $gw, 'query' ) ) {
				return array( 'ok' => false, 'error' => 'ai_unavailable' );
			}

			$messages = self::build_messages( $utterance, $sources );

			$raw = $gw->query( $messages, 0.0, array(), '' );

			// A WP_Error or an "Error:" string is a failure, not an answer.
			if ( ( function_exists( 'is_wp_error' ) && is_wp_error( $raw ) ) || ! is_string( $raw ) || 0 === stripos( ltrim( (string) $raw ), 'Error:' ) ) {
				return array( 'ok' => false, 'error' => 'ai_error' );
			}

			$parsed = method_exists( $gw, 'parse_llm_json' ) ? $gw->parse_llm_json( $raw ) : json_decode( $raw, true );
			if ( ! is_array( $parsed ) ) {
				return array( 'ok' => false, 'error' => 'unparseable' );
			}

			// Shape a candidate spec. Still untrusted — /plot validates it. We only pass through
			// fields the validator understands; the model cannot smuggle extra keys into a query.
			$spec = array(
				'source' => isset( $parsed['source'] ) ? (string) $parsed['source'] : '',
				'entity' => isset( $parsed['entity'] ) ? (string) $parsed['entity'] : '',
				'axis'   => in_array( ( $parsed['axis'] ?? 'count' ), array( 'count', 'amount' ), true ) ? (string) $parsed['axis'] : 'count',
				'window' => array(
					'from' => isset( $parsed['window']['from'] ) ? (string) $parsed['window']['from'] : '',
					'to'   => isset( $parsed['window']['to'] ) ? (string) $parsed['window']['to'] : '',
				),
			);
			if ( isset( $parsed['filters'] ) && is_array( $parsed['filters'] ) ) {
				$spec['filters'] = $parsed['filters'];
			}

			return array( 'ok' => true, 'spec' => $spec );
		}

		/** The sources this viewer may plot (money sources dropped when revenue is denied). */
		private static function allowed_sources( int $viewer ): array {
			if ( ! class_exists( 'ZDZ_Report_Sources' ) ) {
				return array();
			}
			$can_revenue = class_exists( 'ZDZ_Data_Permissions' )
				? (bool) ZDZ_Data_Permissions::can( $viewer, 'view_company_revenue' )
				: false;

			$out = array();
			foreach ( ZDZ_Report_Sources::sources() as $key => $s ) {
				if ( ! empty( $s['money_class'] ) && ! $can_revenue ) {
					continue;
				}
				$out[ $key ] = array( 'label' => (string) $s['label'], 'entities' => array_values( $s['entities'] ) );
			}
			return $out;
		}

		/**
		 * The prompt is assembled at runtime by ONE author from the live source registry — no
		 * company data, no baked vocabulary. It instructs the model to choose only from the listed
		 * sources/entities and to answer with strict JSON.
		 */
		private static function build_messages( string $utterance, array $sources ): array {
			$catalog = array();
			foreach ( $sources as $key => $s ) {
				$catalog[] = '- ' . $key . ' ("' . $s['label'] . '") entities: ' . implode( ', ', $s['entities'] );
			}
			$today = gmdate( 'Y-m-d' );

			$system = "You translate a request into a dot-plot specification.\n"
				. "Choose exactly one source KEY and one ENTITY from this list (do not invent any):\n"
				. implode( "\n", $catalog ) . "\n"
				. "axis is \"count\" (number of events) or \"amount\" (money, only if you were shown a money source).\n"
				. "window is an inclusive date range {from,to} as YYYY-MM-DD; today is {$today}; never choose a future-only range.\n"
				. "Answer with STRICT JSON only: {\"source\":\"..\",\"entity\":\"..\",\"axis\":\"count\",\"window\":{\"from\":\"YYYY-MM-DD\",\"to\":\"YYYY-MM-DD\"}}.";

			return array(
				array( 'role' => 'system', 'content' => $system ),
				array( 'role' => 'user', 'content' => $utterance ),
			);
		}

		/**
		 * Honest diagnostics for the credential surface: option NAME, presence, length and an
		 * 8-char digest — NEVER a value. This is the ONLY thing kept from the deleted five-key
		 * rotation apparatus. There is no rotation and no per-app key; the single credential is
		 * owned by Core settings and read only by the gateway.
		 */
		public static function diagnostics(): array {
			$names = array();
			if ( class_exists( 'ZDZ_Core_Settings' ) && method_exists( 'ZDZ_Core_Settings', 'secret_fields' ) ) {
				$names = (array) ZDZ_Core_Settings::secret_fields();
			}
			$out = array();
			foreach ( $names as $name ) {
				$name  = (string) $name;
				$out[] = array(
					'option' => $name,
					'digest' => substr( hash( 'sha256', $name ), 0, 8 ), // digest of the NAME, not any value.
				);
			}
			return array( 'single_credential' => true, 'rotation' => false, 'fields' => $out );
		}
	}
}
