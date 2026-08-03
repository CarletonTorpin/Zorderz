<?php
/*
Plugin Name: Zorderz Sketch Pad
Description: Canvas-based drawing app for field sketches, diagrams, and visual notes. Save, manage, and share sketches across Zorderz apps.
Version: 1.0.6
Author: Zorderz
Requires at least: 6.0
Requires PHP: 7.4

== Changelog ==
1.0.6
  - Thumb-friendly Save: the full-screen "Save" button moved from the top-right
    corner (a long reach on a phone) down into the bottom action bar, right-
    aligned next to the draw tools (undo/redo/clear) — where the thumb naturally
    rests for one-handed use. The header keeps only the small Close (X) for
    "leave without saving." View-mode "Viewing" badge / "Add to this sketch"
    flow unchanged; the badge→Save swap now happens in the bottom bar too.
1.0.5
  - Jump-to-widget (consistency with every other app): tapping the Sketch icon
    now scrolls to the Sketch widget on the dashboard instead of taking over the
    full screen. The in-widget canvas is a LIVE drawing surface — you can start
    sketching right there. The Expand (⤢) button still opens the full-screen
    canvas for detailed work, exactly as before. (No theme change required; this
    simply stops claiming the `zdz_app_launch` intent so the theme Bridge runs
    its standard scroll-the-widget-into-view behavior.)
  - Widget + fullscreen canvases stay in sync (strokes drawn in the widget
    appear when you expand, and vice-versa) — this was already supported by the
    drawing engine and is now used by the in-widget canvas.
1.0.4
  - One-tap finish: "Done" in the full-screen overlay now SAVES the sketch and
    returns you to the dashboard, then auto-scrolls the Sketch widget into view
    with "My Sketches" open. Removes the separate "Save Sketch" tap. A small
    Close (X) on the left exits without saving.
  - Bigger, clearer primary action: the header Save button is larger and
    labeled, with a Close (X) for "leave without saving".
  - Loaded sketches open READ-ONLY (view mode): an accidental touch no longer
    draws. An explicit "Add to this sketch" confirmation enters edit mode.
  - Dark mode: "My Sketches" thumbnails now render correctly on dark surfaces
    (presentation-only; saved files are unchanged).
  - Launch-to-canvas and back-button/history parity unchanged.
1.0.3
  - Tap-to-draw: tapping the Sketch icon opens the full-screen drawing canvas
    directly via the theme's `zdz_app_launch` intent (requires theme Bridge
    v3.2). No dashboard scroll.
  - History/back parity: the fullscreen overlay pushes a history entry, "Done"
    routes through history.back(), and hardware/swipe back closes it.
1.0.2
  - System theme dark mode fix, sticky save button.
*/
if ( ! defined( 'ABSPATH' ) ) exit;
define( 'ZSP_VERSION', '1.0.6' );
define( 'ZSP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZSP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ZSP_NONCE', 'zsp_nonce' );
require_once ZSP_PLUGIN_DIR . 'includes/class-zsp-bridge.php';

add_action( 'after_setup_theme', function () {
	if ( interface_exists( '\\Zorderz\\Widget_App_Interface' ) ) {
		class ZSP_App implements \Zorderz\Widget_App_Interface {
			public function get_config(): array {
				return [ 'id'=>'zdz-sketch-pad', 'nm'=>'Sketch', 'name'=>'Sketch', 'icon'=>'pencil',
					'desc'=>'Draw sketches, diagrams, and visual notes',
					'description'=>'Draw sketches, diagrams, and visual notes',
					'cat'=>'Tools', 'cc'=>'#F59E0B',
					'bridge_type'=>'inline_widget', 'order'=>52 ];
			}
			public function render_mobile_view( int $user_id ): void { echo '<p>Use the dashboard widget.</p>'; }
			public function render_dashboard_widget( int $user_id ): ?string {
				ob_start();
				wp_enqueue_style( 'zsp-widget-css', ZSP_PLUGIN_URL.'assets/css/widget.css', [], ZSP_VERSION );
				wp_enqueue_script( 'zsp-widget-js', ZSP_PLUGIN_URL.'assets/js/widget.js', [], ZSP_VERSION, true );
				wp_localize_script( 'zsp-widget-js', 'zspWidget', [
					'ajaxurl'=>admin_url('admin-ajax.php'), 'nonce'=>wp_create_nonce(ZSP_NONCE), 'version'=>ZSP_VERSION ] );
				?>
				<div class="zsp-w" id="zsp-widget">
					<div class="zsp-w-tabs">
						<button class="zsp-w-tab zsp-w-tab-active" data-tab="draw">New Sketch</button>
						<button class="zsp-w-tab" data-tab="gallery">My Sketches</button>
					</div>
					<div class="zsp-w-panel" id="zsp-w-tab-draw">
						<div class="zsp-w-canvas-wrap" id="zsp-w-canvas-wrap">
							<span class="zsp-w-placeholder" id="zsp-w-placeholder">Draw here &mdash; or tap &#10138; to go full screen</span>
							<canvas class="zsp-w-canvas" id="zsp-w-canvas"></canvas>
							<span class="zsp-w-canvas-hint" id="zsp-w-canvas-hint" aria-hidden="true">
								<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
								Tap to draw
							</span>
						</div>
						<div class="zsp-w-toolbar">
							<button type="button" class="zsp-w-tool" id="zsp-w-undo" title="Undo" disabled><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7v6h6"/><path d="M21 17a9 9 0 0 0-9-9 9 9 0 0 0-6 2.3L3 13"/></svg></button>
							<button type="button" class="zsp-w-tool" id="zsp-w-redo" title="Redo" disabled><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 7v6h-6"/><path d="M3 17a9 9 0 0 1 9-9 9 9 0 0 1 6 2.3L21 13"/></svg></button>
							<div class="zsp-w-sep"></div>
							<button type="button" class="zsp-w-tool" id="zsp-w-clear" title="Clear" disabled><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg></button>
							<div class="zsp-w-sep"></div>
							<button type="button" class="zsp-w-tool zsp-w-tool-cta" id="zsp-w-expand" title="Open full-screen canvas"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg></button>
							<span class="zsp-w-status" id="zsp-w-status"></span>
						</div>
					</div>
					<div class="zsp-w-panel" id="zsp-w-tab-gallery" style="display:none;">
						<div id="zsp-w-gallery" class="zsp-w-gallery"><div class="zsp-w-loading">Loading sketches…</div></div>
					</div>
				</div>
				<?php
				return ob_get_clean();
			}
		}
		add_filter( 'zdz_register_apps', function ( $apps ) { $apps[] = new ZSP_App(); return $apps; } );
	}
}, 12 );

add_action( 'init', function () {
	add_action( 'wp_ajax_tssp_save_sketch', 'zsp_ajax_save_sketch' );
	add_action( 'wp_ajax_nopriv_tssp_save_sketch', 'zsp_deny_nopriv' );
	add_action( 'wp_ajax_tssp_list_sketches', 'zsp_ajax_list_sketches' );
	add_action( 'wp_ajax_nopriv_tssp_list_sketches', 'zsp_deny_nopriv' );
	add_action( 'wp_ajax_tssp_delete_sketch', 'zsp_ajax_delete_sketch' );
	add_action( 'wp_ajax_nopriv_tssp_delete_sketch', 'zsp_deny_nopriv' );
	add_action( 'wp_ajax_tssp_load_sketch', 'zsp_ajax_load_sketch' );
	add_action( 'wp_ajax_nopriv_tssp_load_sketch', 'zsp_deny_nopriv' );
	add_action( 'wp_ajax_tssp_debug_status', 'zsp_ajax_debug_status' );
} );

function zsp_deny_nopriv() { wp_send_json_error( 'Authentication required.', 403 ); }

function zsp_ajax_save_sketch() {
	global $wpdb;
	$debug = [];

	// Step 1: Auth
	check_ajax_referer( ZSP_NONCE, 'nonce' );
	if ( ! is_user_logged_in() ) wp_send_json_error( 'Not logged in.' );
	$debug[] = 'auth:ok user=' . get_current_user_id();

	// Step 2: Check ZDZ_User_Media class
	if ( ! class_exists( 'ZDZ_User_Media' ) ) {
		wp_send_json_error( 'ZDZ_User_Media class not found. Deploy theme v2.17.1 first. Debug: ' . implode(' | ', $debug) );
	}
	$debug[] = 'class:ok';

	// Step 3: Check table
	$table = $wpdb->prefix . 'zdz_user_media';
	$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table;
	$debug[] = 'table_check:' . ( $table_exists ? 'exists' : 'MISSING' );

	if ( ! $table_exists ) {
		// Try to create it on the fly
		$debug[] = 'creating_table...';
		$charset = $wpdb->get_charset_collate();
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS `{$table}` (
				`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				`user_id` bigint(20) unsigned NOT NULL,
				`file_url` varchar(512) NOT NULL DEFAULT '',
				`thumbnail_url` varchar(512) NOT NULL DEFAULT '',
				`filename` varchar(255) NOT NULL DEFAULT '',
				`media_type` varchar(32) NOT NULL DEFAULT 'photo',
				`source_app` varchar(64) NOT NULL DEFAULT '',
				`source_ref` varchar(128) NOT NULL DEFAULT '',
				`title` varchar(255) NOT NULL DEFAULT 'Untitled',
				`description` text,
				`privacy` varchar(16) NOT NULL DEFAULT 'private',
				`wp_attachment_id` bigint(20) unsigned DEFAULT 0,
				`meta_json` longtext,
				`created_at` datetime NOT NULL,
				`updated_at` datetime NOT NULL,
				PRIMARY KEY (`id`),
				KEY `user_id` (`user_id`),
				KEY `media_type` (`media_type`)
			) {$charset};"
		);
		if ( $wpdb->last_error ) {
			$debug[] = 'create_err:' . $wpdb->last_error;
			wp_send_json_error( 'Table creation failed. Debug: ' . implode(' | ', $debug) );
		}
		$debug[] = 'table_created:ok';
	} else {
		// Table exists — patch missing columns
		$cols = $wpdb->get_col( "DESCRIBE `{$table}`", 0 );
		if ( ! in_array( 'meta_json', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `meta_json` longtext AFTER `wp_attachment_id`" );
			$debug[] = 'patched:meta_json';
		}
		if ( ! in_array( 'description', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `description` text AFTER `title`" );
			$debug[] = 'patched:description';
		}
	}

	// Step 4: Check file upload
	if ( empty( $_FILES['image'] ) ) {
		wp_send_json_error( 'No image file received. Debug: ' . implode(' | ', $debug) );
	}
	$debug[] = 'file:' . $_FILES['image']['name'] . ' size=' . $_FILES['image']['size'] . ' err=' . $_FILES['image']['error'];

	// Step 5: WordPress upload
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$title = sanitize_text_field( $_POST['title'] ?? '' );
	$canvas_data = wp_unslash( $_POST['canvas_data'] ?? '' );
	$safe = $title ? sanitize_file_name( $title ) : 'sketch';
	$_FILES['image']['name'] = "zsp-{$safe}-" . date('Ymd-His') . '.jpg';

	$att_id = media_handle_upload( 'image', 0, [], [ 'test_form' => false, 'mimes' => [ 'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png' ] ] );
	if ( is_wp_error( $att_id ) ) {
		$debug[] = 'upload_err:' . $att_id->get_error_message();
		wp_send_json_error( 'Upload failed: ' . $att_id->get_error_message() . ' | Debug: ' . implode(' | ', $debug) );
	}
	$debug[] = 'attachment:' . $att_id;

	$url = wp_get_attachment_url( $att_id );
	$thumb = wp_get_attachment_image_url( $att_id, 'medium' );
	$debug[] = 'url:' . ( $url ? 'ok' : 'EMPTY' );

	// Step 6: Save to zdz_user_media
	$save_args = [
		'user_id'          => get_current_user_id(),
		'file_url'         => $url,
		'thumbnail_url'    => $thumb ?: $url,
		'filename'         => $_FILES['image']['name'],
		'media_type'       => 'sketch',
		'source_app'       => 'zdz-sketch-pad',
		'source_ref'       => 'sketch:' . $att_id,
		'title'            => $title ?: 'Untitled Sketch',
		'canvas_data'      => $canvas_data ?: null,
		'wp_attachment_id' => $att_id,
	];
	$debug[] = 'calling_save...';

	$media = ZDZ_User_Media::save( $save_args );

	if ( ! $media ) {
		$debug[] = 'save_returned:null';
		$debug[] = 'last_db_error:' . $wpdb->last_error;
		error_log( 'TSSP save failed. Debug: ' . implode(' | ', $debug) );
		wp_send_json_error( 'Metadata save failed. Debug: ' . implode(' | ', $debug) );
	}

	$debug[] = 'saved:id=' . $media['id'];
	error_log( 'TSSP save OK. Debug: ' . implode(' | ', $debug) );
	wp_send_json_success( [ 'media_id' => $media['id'], 'file_url' => $url, 'thumbnail_url' => $thumb ?: $url, 'title' => $media['title'] ] );
}

/** Debug endpoint — check table status */
function zsp_ajax_debug_status() {
	global $wpdb;
	check_ajax_referer( ZSP_NONCE, 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Admin only.' );

	$table = $wpdb->prefix . 'zdz_user_media';
	$exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table;
	$count = $exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ) : -1;
	$class = class_exists( 'ZDZ_User_Media' );
	$cols = $exists ? $wpdb->get_results( "DESCRIBE `{$table}`", ARRAY_A ) : [];

	wp_send_json_success( [
		'table'       => $table,
		'exists'      => $exists,
		'row_count'   => $count,
		'class_found' => $class,
		'columns'     => array_column( $cols, 'Field' ),
		'wp_version'  => get_bloginfo( 'version' ),
		'php_version' => phpversion(),
		'db_version'  => $wpdb->db_version(),
	] );
}

function zsp_ajax_list_sketches() {
	check_ajax_referer( ZSP_NONCE, 'nonce' );
	if ( ! is_user_logged_in() ) wp_send_json_error( 'Authentication required.' );
	if ( ! class_exists( 'ZDZ_User_Media' ) ) { wp_send_json_success( [ 'sketches' => [] ] ); return; }
	$items = ZDZ_User_Media::get_user_media( get_current_user_id(), [
		'media_type' => 'sketch',
		'source_app' => 'zdz-sketch-pad',
		'limit'      => 20,
		'offset'     => (int)( $_POST['offset'] ?? 0 ),
	] );
	$out = array_map( function( $s ) {
		$meta = ! empty( $s['meta_json'] ) ? json_decode( $s['meta_json'], true ) : [];
		return [
			'id'            => (int) $s['id'],
			'title'         => $s['title'],
			'thumbnail_url' => $s['thumbnail_url'] ?: $s['file_url'],
			'file_url'      => $s['file_url'],
			'privacy'       => $s['privacy'],
			'created_at'    => $s['created_at'],
			'has_strokes'   => ! empty( $meta['canvas_data'] ),
		];
	}, $items );
	wp_send_json_success( [ 'sketches' => $out ] );
}

/**
 * Load a single sketch's full data including canvas strokes.
 */
function zsp_ajax_load_sketch() {
	check_ajax_referer( ZSP_NONCE, 'nonce' );
	if ( ! is_user_logged_in() ) wp_send_json_error( 'Authentication required.' );
	$id = (int)( $_POST['media_id'] ?? 0 );
	if ( ! $id || ! class_exists( 'ZDZ_User_Media' ) ) wp_send_json_error( 'Invalid.' );
	$row = ZDZ_User_Media::get_by_id( $id );
	if ( ! $row || (int) $row['user_id'] !== get_current_user_id() ) {
		wp_send_json_error( 'Not found or access denied.' );
	}
	$meta = ! empty( $row['meta_json'] ) ? json_decode( $row['meta_json'], true ) : [];
	wp_send_json_success( [
		'id'            => (int) $row['id'],
		'title'         => $row['title'],
		'file_url'      => $row['file_url'],
		'thumbnail_url' => $row['thumbnail_url'] ?: $row['file_url'],
		'canvas_data'   => $meta['canvas_data'] ?? null,
		'created_at'    => $row['created_at'],
	] );
}

function zsp_ajax_delete_sketch() {
	check_ajax_referer( ZSP_NONCE, 'nonce' );
	if ( ! is_user_logged_in() ) wp_send_json_error( 'Authentication required.' );
	$id = (int)( $_POST['media_id'] ?? 0 );
	if ( ! $id || ! class_exists( 'ZDZ_User_Media' ) ) wp_send_json_error( 'Invalid.' );
	$deleted = ZDZ_User_Media::delete( $id, get_current_user_id() );
	if ( ! $deleted ) wp_send_json_error( 'Delete failed — not found or access denied.' );
	wp_send_json_success();
}
