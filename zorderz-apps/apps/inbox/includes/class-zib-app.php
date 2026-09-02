<?php
/**
 * ZIB_App — the inline-widget app object for the theme's app registry.
 *
 * In its own file because it `implements \Zorderz\Widget_App_Interface`,
 * which fatals if this file is loaded before the theme defines that interface.
 * ZIB_Widget::register_app() requires it lazily, only once the interface
 * exists (i.e. when the theme enumerates apps).
 *
 * @since 0.1.0 (P0 — Connect)
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! interface_exists( '\Zorderz\Widget_App_Interface' ) ) {
	return; // Defensive: never declare the class without its interface.
}

class ZIB_App implements \Zorderz\Widget_App_Interface {

	private $cfg;

	public function __construct( array $cfg ) {
		$this->cfg                = $cfg;
		$this->cfg['bridge_type'] = 'inline_widget';
	}

	public function get_config(): array {
		return $this->cfg;
	}

	public function render_mobile_view( int $user_id ): void {
		echo '<div class="zib-mobile" style="padding:16px;">' . ZIB_Widget::render_card_shell() . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public function render_dashboard_widget( int $user_id ): ?string {
		if ( ! ZIB_Widget::should_render() ) {
			return null;
		}
		return ZIB_Widget::render_card_shell();
	}
}
