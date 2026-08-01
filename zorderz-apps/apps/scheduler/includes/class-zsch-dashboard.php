<?php
/**
 * ZSCH_Dashboard — front-end boot.
 *
 * Lightweight: the widget loads its data over REST (zorderz/v1/scheduler), so there is no
 * admin-ajax data router here. This class exists to (a) be the single
 * constructor the bootstrap news up on plugins_loaded, and (b) hold any
 * front-end-only wiring we add later (e.g. a service worker, deep links).
 *
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSCH_Dashboard {

	public function __construct() {
		// Reserved for future front-end hooks. The inline widget enqueues its
		// own assets in ZSCH_Widget::render_dashboard_widget().
	}
}
