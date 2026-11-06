<?php
/**
 * Charts in the Zorderz app shell.
 *
 * Required only from after_setup_theme, behind interface_exists(): it implements an interface
 * the theme defines, and themes load after plugins.
 *
 * One app, one tile. Charts is the dashboard WIDGET (inline_widget): an ask box and one chart.
 * Its expand button opens the full screen (saved charts, filters, sharing) over the dashboard,
 * from inside the same app. render_mobile_view() is that same full screen, for any surface that
 * asks an app for its page.
 *
 * Whether the tile shows is the platform's app grant, which Charts adds to for each person its own
 * access rules allow (ZCH_Access::extra_allowed(), on the ADD-only zdz_extra_allowed_apps: Safe Mode
 * and a person's denied apps still win). What is inside is decided by those rules too. The widget
 * answers null for anyone Charts does not allow, so a granted tile never shows an empty card.
 *
 * @package Zorderz\Charts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ZCH_App' ) ) {

	class ZCH_App implements \Zorderz\Widget_App_Interface {

		public static function init() {
			$widget = new self();
			add_filter(
				'zdz_register_apps',
				function ( $apps ) use ( $widget ) {
					$apps[ ZCH_APP_ID ] = $widget;
					return $apps;
				}
			);
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		}

		/** The dashboard is a front-end page: load Charts there for people Charts allows. */
		public static function enqueue() {
			if ( is_admin() || ! is_user_logged_in() || ! ZCH_Access::allowed( get_current_user_id() ) ) {
				return;
			}
			ZCH_Admin::enqueue( 'widget' );
		}

		public function get_config(): array {
			return array(
				'id'          => ZCH_APP_ID,
				'nm'          => __( 'Charts', 'zorderz' ),
				'icon'        => 'pie-chart',
				'cat'         => 'Sales',
				'cc'          => '#0F766E',
				'desc'        => __( 'Ask about sales and get a chart.', 'zorderz' ),
				'roles'       => array(),     // visibility: the platform's app grant, then Charts access.
				'bridge_type' => 'inline_widget',
				'admin_url'   => admin_url( 'admin.php?page=' . ZCH_SLUG ),
				'classes'     => array( 'ZCH_App', 'ZCH_Rest', 'ZCH_Access', 'ZCH_Hub_Client' ),
			);
		}

		/** The full screen: the Charts page with the WordPress chrome hidden. */
		public function render_mobile_view( int $user_id ): void {
			if ( ! ZCH_Access::allowed( $user_id ) ) {
				echo '<p>' . esc_html__( "Charts isn't available on this account.", 'zorderz' ) . '</p>';
				return;
			}
			echo '<iframe src="' . esc_url( admin_url( 'admin.php?page=' . ZCH_SLUG . '&zdz_mobile=1' ) )
				. '" style="width:100%;height:100%;border:none;" title="' . esc_attr__( 'Charts', 'zorderz' ) . '"></iframe>';
		}

		/**
		 * Skeleton only, at its final size, so nothing jumps when the chart arrives. NULL (not '')
		 * for people without access: an empty card reads as broken. No overflow/max-height here or
		 * on any child: the dashboard's .dash-widget-body{overflow:clip} is a cross-app contract.
		 */
		public function render_dashboard_widget( int $user_id ): ?string {
			if ( ! ZCH_Access::allowed( $user_id ) ) {
				return null;
			}
			return '<div class="zch zch-w" data-zch-widget="1">'
				. '<div class="zch-w-skel" aria-hidden="true"><i></i><i></i><i></i><i></i></div>'
				. '</div>';
		}
	}
}
