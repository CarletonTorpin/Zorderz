<?php
namespace Zorderz;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface App_Interface {

	/**
	 * Returns the app configuration for the frontend Springboard.
	 *
	 * Required keys:
	 *   'id'          => string
	 *   'nm'          => string
	 *   'icon'        => string
	 *   'cat'         => string
	 *   'cc'          => string
	 *   'desc'        => string
	 *   'roles'       => array
	 *   'bridge_type' => string ('iframe' | 'ajax_html' | 'redirect')
	 *   'admin_url'   => string
	 *
	 * @return array
	 */
	public function get_config(): array;

	/**
	 * Renders the mobile-optimized HTML for the frontend SPA.
	 *
	 * @param int $user_id The current WordPress user ID
	 * @return void
	 */
	public function render_mobile_view( int $user_id ): void;
}

/**
 * Extended interface for apps that provide an inline dashboard widget.
 *
 * Plugins implementing this interface will have their widget rendered
 * directly on the main SPA dashboard (no bottom sheet required).
 *
 * Backwards-compatible: existing plugins that only implement App_Interface
 * continue to work as app tiles with bridge_type iframe/ajax_html/redirect.
 *
 * @since 2.0.0
 */
interface Widget_App_Interface extends App_Interface {

	/**
	 * Renders the HTML skeleton for the inline dashboard widget.
	 *
	 * Return lightweight HTML (buttons, containers). Heavy data should
	 * be loaded asynchronously via AJAX after the page renders.
	 *
	 * @param int $user_id The current WordPress user ID.
	 * @return string|null Widget HTML, or null if nothing to display.
	 */
	public function render_dashboard_widget( int $user_id ): ?string;
}