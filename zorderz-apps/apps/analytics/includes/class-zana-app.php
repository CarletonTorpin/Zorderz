<?php
/**
 * ZANA_App — the dashboard registration for the Analytics/Chat surface.
 *
 * Registers under the STABLE app id 'sales-analytics' (kept: the theme grants and
 * labels it, and the KPI tiles + digest deep-link route to it). The surface itself
 * is the permanent bottom "Chat" tab, injected by assets/js/chat.js; this class also
 * renders the same surface into the app viewport so Bridge.loadApp('sales-analytics')
 * — used by the KPI tile prompts and the digest deep-link — lands on the chat too.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_App implements \Zorderz\App_Interface {

	public function get_config(): array {
		return array(
			'id'          => ZANA_APP_ID, // 'sales-analytics' — do not rename
			'nm'          => __( 'Chat', 'zorderz' ),
			'icon'        => 'message-circle',
			'cat'         => 'Sales',
			'cc'          => '#6366F1',
			'desc'        => __( 'Ask your data a question — conversational analytics.', 'zorderz' ),
			'roles'       => array( 'zdz_owner', 'zdz_admin', 'zdz_sales' ),
			'bridge_type' => 'ajax_html',
			// Reached through the permanent Chat tab, so it is not a separate tile.
			'springboard' => false,
			'admin_url'   => home_url( '/' ),
		);
	}

	public function render_mobile_view( int $user_id ): void {
		// The chat UI mounts client-side into this container (see assets/js/chat.js).
		echo '<div id="zana-chat-mount" data-zana-app="' . esc_attr( ZANA_APP_ID ) . '"></div>';
	}
}
