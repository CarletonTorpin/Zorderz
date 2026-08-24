<?php
/**
 * ZDP_Rest — the dot-plot REST surface under zorderz/v1 (ZDZ_REST_NS).
 *
 * Three routes, all gated on ZDZ_Plugin_API::user_can_access_app( ..., 'dotplot' ):
 *   GET  /dotplot/sources   the sources this viewer may plot (money sources hidden if unentitled)
 *   POST /dotplot/plot      validate an untrusted spec through ZDZ_Report_Sources and return the grid
 *   POST /dotplot/dictate   turn an utterance into a PROPOSED spec (still validated by /plot)
 *
 * The spec on /plot is HOSTILE input: it is passed verbatim to ZDZ_Report_Sources::read(), which
 * re-validates it (unknown source / un-allow-listed entity / filter / window, or an unentitled
 * money source => refusal, nothing gathered). This class never queries anything itself.
 *
 * @package Zorderz\DotPlot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ZDP_Rest' ) ) {

	class ZDP_Rest {

		public static function init(): void {
			/* Routes are registered on rest_api_init (wired in app.php). */
		}

		private static function ns(): string {
			return defined( 'ZDZ_REST_NS' ) ? ZDZ_REST_NS : 'zorderz/v1';
		}

		public static function register_routes(): void {
			$ns = self::ns();

			register_rest_route(
				$ns,
				'/dotplot/sources',
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'route_sources' ),
					'permission_callback' => array( __CLASS__, 'gate' ),
				)
			);

			register_rest_route(
				$ns,
				'/dotplot/plot',
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'route_plot' ),
					'permission_callback' => array( __CLASS__, 'gate' ),
				)
			);

			register_rest_route(
				$ns,
				'/dotplot/dictate',
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'route_dictate' ),
					'permission_callback' => array( __CLASS__, 'gate' ),
				)
			);
		}

		/** App-access gate: a logged-in user who may access the dot-plot app. */
		public static function gate(): bool {
			$uid = get_current_user_id();
			if ( ! $uid ) {
				return false;
			}
			if ( class_exists( 'ZDZ_Plugin_API' ) && method_exists( 'ZDZ_Plugin_API', 'user_can_access_app' ) ) {
				return (bool) ZDZ_Plugin_API::user_can_access_app( $uid, ZDP_APP_ID );
			}
			return current_user_can( 'manage_options' );
		}

		/**
		 * The sources this viewer may pick. A money source is omitted entirely when the viewer
		 * lacks view_company_revenue (the same drop-the-whole-metric discipline the KPI filter
		 * uses), so an unentitled reader never even sees a money option to try.
		 */
		public static function route_sources( $request ) {
			if ( ! class_exists( 'ZDZ_Report_Sources' ) ) {
				return new WP_REST_Response( array( 'ok' => false, 'error' => 'reporting_unavailable', 'sources' => array() ), 200 );
			}
			$uid          = get_current_user_id();
			$can_revenue  = class_exists( 'ZDZ_Data_Permissions' )
				? (bool) ZDZ_Data_Permissions::can( $uid, 'view_company_revenue' )
				: false;

			$out = array();
			foreach ( ZDZ_Report_Sources::sources() as $key => $s ) {
				if ( ! empty( $s['money_class'] ) && ! $can_revenue ) {
					continue; // never advertise a money source to a viewer who cannot see money.
				}
				$out[] = array(
					'key'         => $key,
					'label'       => (string) $s['label'],
					'entities'    => array_values( $s['entities'] ),
					'money_class' => ! empty( $s['money_class'] ),
					'has_amount'  => '' !== (string) $s['amount_path'],
					'filterable'  => array_values( $s['filterable'] ),
				);
			}

			return new WP_REST_Response( array( 'ok' => true, 'sources' => $out, 'can_revenue' => $can_revenue ), 200 );
		}

		/**
		 * Validate an untrusted spec and return the grid. The server sets __viewer from the
		 * authenticated user — the client cannot choose whose entitlement is checked.
		 */
		public static function route_plot( $request ) {
			if ( ! class_exists( 'ZDZ_Report_Sources' ) ) {
				return new WP_REST_Response( array( 'ok' => false, 'error' => 'reporting_unavailable' ), 200 );
			}

			$body = $request->get_json_params();
			if ( ! is_array( $body ) ) {
				$body = (array) $request->get_params();
			}

			$spec = array(
				'source'  => isset( $body['source'] ) ? (string) $body['source'] : '',
				'entity'  => isset( $body['entity'] ) ? (string) $body['entity'] : '',
				'axis'    => isset( $body['axis'] ) ? (string) $body['axis'] : 'count',
				'bucket'  => isset( $body['bucket'] ) ? (string) $body['bucket'] : 'day',
				'window'  => is_array( $body['window'] ?? null ) ? $body['window'] : array(),
				'filters' => is_array( $body['filters'] ?? null ) ? $body['filters'] : array(),
				// Server-authoritative — the model / client never sets whose money entitlement counts.
				'__viewer' => get_current_user_id(),
			);

			$grid   = ZDZ_Report_Sources::read( $spec );
			$status = ! empty( $grid['ok'] ) ? 200 : ( ( ( $grid['error'] ?? '' ) === 'zdz_report_forbidden_money' ) ? 403 : 400 );

			return new WP_REST_Response( $grid, $status );
		}

		/**
		 * Turn a natural-language utterance into a PROPOSED spec. The proposal is NOT trusted — the
		 * client submits it to /plot, which re-validates it. Degrades cleanly when the AI gateway
		 * is unavailable.
		 */
		public static function route_dictate( $request ) {
			$body      = $request->get_json_params();
			$utterance = is_array( $body ) && isset( $body['utterance'] ) ? (string) $body['utterance'] : '';
			$utterance = trim( wp_strip_all_tags( $utterance ) );
			if ( '' === $utterance ) {
				return new WP_REST_Response( array( 'ok' => false, 'error' => 'empty_utterance' ), 400 );
			}
			if ( ! class_exists( 'ZDP_Ai' ) ) {
				return new WP_REST_Response( array( 'ok' => false, 'error' => 'ai_unavailable' ), 200 );
			}
			// A dictation miss is not an HTTP error — the UI degrades to manual field selection.
			$result = ZDP_Ai::plan_spec( $utterance, get_current_user_id() );
			return new WP_REST_Response( $result, 200 );
		}
	}
}
