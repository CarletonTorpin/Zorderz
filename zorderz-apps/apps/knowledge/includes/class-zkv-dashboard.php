<?php
/**
 * ZKV_Dashboard — AJAX handlers + admin page.
 *
 * Follows the platform dashboard-app pattern: constructor registers hooks,
 * class self-instantiates at bottom of file.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class ZKV_Dashboard {

	public function __construct() {
		// AJAX handlers (all require login — wp_ajax_ prefix, NOT wp_ajax_nopriv_)
		add_action( 'wp_ajax_zkv_preanalyze',       array( $this, 'ajax_preanalyze' ) );
		add_action( 'wp_ajax_zkv_upload_document',  array( $this, 'ajax_upload' ) );
		add_action( 'wp_ajax_zkv_search',           array( $this, 'ajax_search' ) );
		add_action( 'wp_ajax_zkv_list_documents',   array( $this, 'ajax_list' ) );
		add_action( 'wp_ajax_zkv_get_document',     array( $this, 'ajax_get' ) );
		add_action( 'wp_ajax_zkv_delete_document',  array( $this, 'ajax_delete' ) );
		add_action( 'wp_ajax_zkv_get_categories',   array( $this, 'ajax_categories' ) );
		add_action( 'wp_ajax_zkv_download',         array( $this, 'ajax_download' ) );
		add_action( 'wp_ajax_zkv_paste_text',       array( $this, 'ajax_paste_text' ) );
		add_action( 'wp_ajax_zkv_reindex',          array( $this, 'ajax_reindex' ) );
		add_action( 'wp_ajax_zkv_reindex_all',      array( $this, 'ajax_reindex_all' ) );
		add_action( 'wp_ajax_zkv_save_settings',    array( $this, 'ajax_save_settings' ) );
		add_action( 'wp_ajax_zkv_get_settings',     array( $this, 'ajax_get_settings' ) );
		add_action( 'wp_ajax_zkv_update_context',   array( $this, 'ajax_update_context' ) );
		add_action( 'wp_ajax_zkv_diagnostic',       array( $this, 'ajax_diagnostic' ) );
		add_action( 'wp_ajax_zkv_toggle_pricing',   array( $this, 'ajax_toggle_pricing' ) );
		add_action( 'wp_ajax_zkv_list_pricing',     array( $this, 'ajax_list_pricing' ) );
		add_action( 'wp_ajax_zkv_clear_pricing',    array( $this, 'ajax_clear_pricing' ) );
		add_action( 'wp_ajax_zkv_upload_from_chat', array( $this, 'ajax_upload_from_chat' ) );

		// v1.3.3: Browser-side PDF chunk extraction — when server can't run pdftotext
		add_action( 'wp_ajax_zkv_check_chunks',      array( $this, 'ajax_check_chunks' ) );
		add_action( 'wp_ajax_zkv_browser_chunks',    array( $this, 'ajax_browser_chunks' ) );

		// v1.5.0: Private transcripts — admin queue (suggested + latent) and
		// party-initiated sharing. Every handler re-derives authority
		// server-side (admin capability / ZKV_ACL::is_party) — the UI hiding
		// a button is never the enforcement (INV-1).
		add_action( 'wp_ajax_zkv_transcript_queue',   array( $this, 'ajax_transcript_queue' ) );
		add_action( 'wp_ajax_zkv_transcript_confirm', array( $this, 'ajax_transcript_confirm' ) );
		add_action( 'wp_ajax_zkv_transcript_detected',        array( $this, 'ajax_transcript_detected' ) );
		add_action( 'wp_ajax_zkv_transcript_confirm_parties', array( $this, 'ajax_transcript_confirm_parties' ) );
		add_action( 'wp_ajax_nopriv_zkv_transcript_detected',        array( $this, 'deny_nopriv' ) );
		add_action( 'wp_ajax_nopriv_zkv_transcript_confirm_parties', array( $this, 'deny_nopriv' ) );
		add_action( 'wp_ajax_zkv_transcript_reject',  array( $this, 'ajax_transcript_reject' ) );
		add_action( 'wp_ajax_zkv_transcript_bind',    array( $this, 'ajax_transcript_bind' ) );
		add_action( 'wp_ajax_zkv_transcript_unbind',  array( $this, 'ajax_transcript_unbind' ) );
		add_action( 'wp_ajax_zkv_transcript_context', array( $this, 'ajax_transcript_context' ) );
		add_action( 'wp_ajax_zkv_vault_users',        array( $this, 'ajax_vault_users' ) );
		add_action( 'wp_ajax_zkv_transcript_lines',   array( $this, 'ajax_transcript_lines' ) );
		add_action( 'wp_ajax_zkv_share_create',       array( $this, 'ajax_share_create' ) );
		add_action( 'wp_ajax_zkv_share_revoke',       array( $this, 'ajax_share_revoke' ) );

		// CRITICAL: Explicitly deny non-logged-in access.
		add_action( 'wp_ajax_nopriv_zkv_preanalyze',       array( $this, 'deny_nopriv' ) );
		add_action( 'wp_ajax_nopriv_zkv_download',         array( $this, 'deny_nopriv' ) );
		add_action( 'wp_ajax_nopriv_zkv_paste_text',       array( $this, 'deny_nopriv' ) );
		add_action( 'wp_ajax_nopriv_zkv_upload_document',  array( $this, 'deny_nopriv' ) );
		add_action( 'wp_ajax_nopriv_zkv_search',           array( $this, 'deny_nopriv' ) );
		add_action( 'wp_ajax_nopriv_zkv_list_documents',   array( $this, 'deny_nopriv' ) );
		add_action( 'wp_ajax_nopriv_zkv_get_document',     array( $this, 'deny_nopriv' ) );
		add_action( 'wp_ajax_nopriv_zkv_delete_document',  array( $this, 'deny_nopriv' ) );
		add_action( 'wp_ajax_nopriv_zkv_get_categories',   array( $this, 'deny_nopriv' ) );
		add_action( 'wp_ajax_nopriv_zkv_get_document',    array( $this, 'deny_nopriv' ) );
		add_action( 'wp_ajax_nopriv_zkv_delete_document', array( $this, 'deny_nopriv' ) );
		add_action( 'wp_ajax_nopriv_zkv_get_categories',  array( $this, 'deny_nopriv' ) );
		add_action( 'wp_ajax_nopriv_zkv_toggle_pricing',    array( $this, 'deny_nopriv' ) );
		add_action( 'wp_ajax_nopriv_zkv_list_pricing',      array( $this, 'deny_nopriv' ) );
		add_action( 'wp_ajax_nopriv_zkv_clear_pricing',     array( $this, 'deny_nopriv' ) );
		add_action( 'wp_ajax_nopriv_zkv_upload_from_chat',  array( $this, 'deny_nopriv' ) );
		add_action( 'wp_ajax_nopriv_zkv_transcript_queue',   array( $this, 'deny_nopriv' ) );
		add_action( 'wp_ajax_nopriv_zkv_transcript_confirm', array( $this, 'deny_nopriv' ) );
		add_action( 'wp_ajax_nopriv_zkv_transcript_reject',  array( $this, 'deny_nopriv' ) );
		add_action( 'wp_ajax_nopriv_zkv_transcript_bind',    array( $this, 'deny_nopriv' ) );
		add_action( 'wp_ajax_nopriv_zkv_transcript_unbind',  array( $this, 'deny_nopriv' ) );
		add_action( 'wp_ajax_nopriv_zkv_transcript_context', array( $this, 'deny_nopriv' ) );
		add_action( 'wp_ajax_nopriv_zkv_vault_users',        array( $this, 'deny_nopriv' ) );
		add_action( 'wp_ajax_nopriv_zkv_transcript_lines',   array( $this, 'deny_nopriv' ) );
		add_action( 'wp_ajax_nopriv_zkv_share_create',       array( $this, 'deny_nopriv' ) );
		add_action( 'wp_ajax_nopriv_zkv_share_revoke',       array( $this, 'deny_nopriv' ) );

		// Hide vault files from media library.
		add_filter( 'ajax_query_attachments_args', array( $this, 'filter_media_library' ) );
	}

	/**
	 * Explicit denial for all non-logged-in AJAX requests.
	 * Belt-and-suspenders: wp_ajax_ (no nopriv) already gates this,
	 * but registering the nopriv hook with a hard deny means even if
	 * WordPress routing changes, unauthenticated users get a 403.
	 */
	public function deny_nopriv() {
		status_header( 403 );
		wp_send_json_error( 'Authentication required.', 403 );
	}

	// ──────────────────────────────────────────────────────────────
	//  Helpers
	// ──────────────────────────────────────────────────────────────

	private function check_access() {
		if ( ! is_user_logged_in() ) { return false; }
		// v1.3.8 (theme pass item #2): real app-access to 'knowledge-vault', not blanket zdz_access_app.
		if ( is_callable( array( 'ZDZ_Plugin_API', 'user_can_access_app' ) ) ) {
			return ZDZ_Plugin_API::user_can_access_app( get_current_user_id(), 'knowledge-vault' );
		}
		return current_user_can( 'manage_options' ) || current_user_can( 'zdz_access_app' );
	}

	private function is_admin_user( $user_id = null ) {
		$user_id = $user_id ?: get_current_user_id();
		if ( class_exists( 'ZDZ_User_Roles' ) ) {
			$user  = get_userdata( $user_id );
			$roles = (array) ( $user ? $user->roles : array() );
			$role  = ! empty( $roles ) ? $roles[0] : '';
			return ZDZ_User_Roles::is_admin_role( $role );
		}
		return user_can( $user_id, 'manage_options' );
	}

	private function visibility_sql( $alias = 'd' ) {
		// v1.5.0: delegate to the single authoritative predicate (view mode:
		// party OR active whole-doc share admits a transcript; non-transcript
		// behavior is byte-identical to pre-1.5.0 — admins see all incl.
		// admin_only, everyone else sees all_employees).
		if ( class_exists( 'ZKV_ACL' ) ) {
			return ZKV_ACL::sql_where_view( get_current_user_id(), $alias );
		}
		// Fallback: legacy behavior + explicit transcript exclusion for admins
		// (fail toward hiding).
		if ( $this->is_admin_user() ) { return " AND {$alias}.visibility <> 'transcript_private'"; }
		return " AND {$alias}.visibility = 'all_employees'";
	}

	private function allowed_mimes() {
		return array(
			'pdf'      => 'application/pdf',
			'txt'      => 'text/plain',
			'md'       => 'text/markdown',
			'csv'      => 'text/csv',
			'doc'      => 'application/msword',
			'docx'     => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'xls'      => 'application/vnd.ms-excel',
			'xlsx'     => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'jpg|jpeg' => 'image/jpeg',
			'png'      => 'image/png',
			'webp'     => 'image/webp',
			'heic'     => 'image/heic',
			// Transcript & caption formats.
			'srt'      => 'application/x-subrip',
			'vtt'      => 'text/vtt',
			'itt'      => 'application/xml',
			'sbv'      => 'text/plain',
			'ass'      => 'text/plain',
			'ssa'      => 'text/plain',
			'sub'      => 'text/plain',
			'lrc'      => 'text/plain',
			'xml'      => 'application/xml',
			'json'     => 'application/json',
			'rtf'      => 'application/rtf',
		);
	}

	// ──────────────────────────────────────────────────────────────
	//  AJAX: Pre-Analyze (fires when user selects a file)
	//
	//  Uploads file to vault, does quick text extraction, calls a
	//  fast AI model to suggest title/category/description, then
	//  returns suggestions to pre-fill the form. The file stays in
	//  the vault dir; the upload handler reuses it via transient.
	// ──────────────────────────────────────────────────────────────

	public function ajax_preanalyze() {
		ob_start();

		// Catch ALL errors including fatal-level ones in PHP 8+.
		try {

		check_ajax_referer( ZKV_NONCE, 'nonce' );
		if ( ! $this->check_access() ) { ob_end_clean(); wp_send_json_error( 'Access denied.' ); }

		if ( empty( $_FILES['file'] ) ) {
			ob_end_clean();
			wp_send_json_error( 'No file provided.' );
		}

		$file = $_FILES['file'];
		if ( ! empty( $file['size'] ) && (int) $file['size'] > ZKV_MAX_UPLOAD_BYTES ) {
			ob_end_clean();
			wp_send_json_error( 'File exceeds 50 MB limit.' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Ensure vault dir exists.
		if ( function_exists( 'zkv_create_secure_vault_dir' ) ) {
			zkv_create_secure_vault_dir();
		}

		// Upload to protected vault directory.
		$vault_filter = function( $uploads ) {
			$uploads['path'] = ZKV_VAULT_DIR;
			$uploads['url']  = ZKV_VAULT_URL;
			$uploads['subdir'] = '';
			return $uploads;
		};
		add_filter( 'upload_dir', $vault_filter );
		$result = wp_handle_upload( $file, array( 'test_form' => false, 'mimes' => $this->allowed_mimes() ) );
		remove_filter( 'upload_dir', $vault_filter );

		if ( ! empty( $result['error'] ) ) {
			ob_end_clean();
			wp_send_json_error( $result['error'] );
		}

		// Compute content hash for duplicate detection.
		$file_hash = hash_file( 'sha256', $result['file'] );

		// Check for duplicate — same content hash = exact same file.
		global $wpdb;
		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, title, original_name FROM {$wpdb->prefix}zkv_documents WHERE file_hash = %s LIMIT 1",
			$file_hash
		), ARRAY_A );

		$duplicate_info = null;
		if ( $existing ) {
			$duplicate_info = array(
				'id'    => (int) $existing['id'],
				'title' => $existing['title'],
				'name'  => $existing['original_name'],
			);
		}

		// Store file info in a transient so ajax_upload can reuse it.
		$temp_key = 'zkv_temp_' . get_current_user_id() . '_' . md5( $file['name'] );
		set_transient( $temp_key, array(
			'file'      => $result['file'],
			'url'       => $result['url'],
			'type'      => $result['type'],
			'name'      => $file['name'],
			'size'      => filesize( $result['file'] ),
			'file_hash' => $file_hash,
		), 30 * MINUTE_IN_SECONDS );

		// Quick text extraction (first 4K chars for speed).
		$text = '';
		if ( class_exists( 'ZKV_Indexer' ) ) {
			$text = ZKV_Indexer::quick_extract( $result['file'], $result['type'], 4000 );
			error_log( 'ZKV preanalyze: Extracted ' . strlen( $text ) . ' chars from ' . $result['type'] . ' file.' );
		}

		// For image-only PDFs, try vision (send first page to AI).
		$vision_b64 = null;
		if ( empty( trim( $text ) ) && $result['type'] === 'application/pdf'
		     && class_exists( 'ZKV_PDF_Reader' )
		     && ZKV_PDF_Reader::is_image_only( $result['file'] ) ) {
			$page_images = ZKV_PDF_Reader::extract_page_images( $result['file'], 1, 800 );
			if ( ! empty( $page_images ) ) {
				$vision_b64 = $page_images[0];
				error_log( 'ZKV preanalyze: Image-only PDF detected, sending first page to vision AI.' );
			}
		}

		// Call fast AI for suggestions.
		$suggestions = $this->get_ai_suggestions( $file['name'], $result['type'], $text, $vision_b64 );

		ob_end_clean();
		wp_send_json_success( array(
			'temp_key'    => $temp_key,
			'suggestions' => $suggestions,
			'duplicate'   => $duplicate_info,
		) );

		} catch ( \Throwable $e ) {
			ob_end_clean();
			wp_send_json_error( 'ZKV preanalyze crash: ' . $e->getMessage() . ' in ' . basename( $e->getFile() ) . ':' . $e->getLine() );
		}
	}

	// ──────────────────────────────────────────────────────────────
	//  AJAX: Diagnostic — tests the AI pipeline so you can see
	//  exactly where it fails. Hit from browser console:
	//  fetch(zkv.ajax_url,{method:'POST',body:new URLSearchParams({action:'zkv_diagnostic',nonce:zkv.nonce})}).then(r=>r.json()).then(console.log)
	// ──────────────────────────────────────────────────────────────

	public function ajax_diagnostic() {
		check_ajax_referer( ZKV_NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Admin only.' ); }

		$report = array();

		// 1. Check exec() availability.
		$report['exec_available'] = function_exists( 'exec' ) && ! in_array( 'exec', array_map( 'trim', explode( ',', ini_get( 'disable_functions' ) ) ), true );

		// 2. Check pdftotext.
		if ( $report['exec_available'] ) {
			$out = array(); $ret = -1;
			@exec( 'which pdftotext 2>/dev/null', $out, $ret );
			$report['pdftotext_path'] = $ret === 0 ? implode( '', $out ) : 'NOT FOUND';
		} else {
			$report['pdftotext_path'] = 'exec() disabled — cannot check';
		}

		// 3. Check ZDZ_Core_Poe.
		$report['poe_class_exists'] = class_exists( 'ZDZ_Core_Poe' );

		// 4. Check API key (properly decrypted).
		$has_key = false;
		$key = self::get_poe_api_key();
		$has_key = ! empty( $key );
		$report['poe_api_key'] = $has_key ? 'SET (' . strlen( $key ) . ' chars, starts with ' . substr( $key, 0, 6 ) . '...)' : 'EMPTY';
		$report['poe_key_source'] = ! empty( get_option( 'zkv_poe_api_key', '' ) ) ? "this app's key" : ( class_exists( 'ZDZ_Core_Settings' ) ? 'platform shared key (ZDZ_Core_Settings)' : 'none' );

		// 5. Check AI model.
		$report['ai_model'] = self::get_ai_model();

		// 6. Live Poe test (direct call with stream:false — bypasses broken ZDZ_Core_Poe).
		if ( $has_key ) {
			try {
				$start = microtime( true );
				$test_response = self::poe_query( $key, 'Reply with exactly: {"status":"ok"}' );
				$elapsed = round( ( microtime( true ) - $start ) * 1000 );
				$report['poe_test_response'] = substr( $test_response, 0, 200 );
				$report['poe_test_ms'] = $elapsed;
			} catch ( \Throwable $e ) {
				$report['poe_test_error'] = $e->getMessage();
			}
		} else {
			$report['poe_test_response'] = 'SKIPPED — no API key';
		}

		// 7. ZKV classes loaded.
		$report['classes'] = array(
			'ZKV_Indexer'    => class_exists( 'ZKV_Indexer' ),
			'ZKV_Categories' => class_exists( 'ZKV_Categories' ),
			'ZKV_Dashboard'  => class_exists( 'ZKV_Dashboard' ),
			'ZKV_PDF_Reader' => class_exists( 'ZKV_PDF_Reader' ),
		);

		// 8. Vision pipeline diagnostics.
		$vision = array();
		$vision['imagick_available'] = class_exists( 'Imagick' );
		$vision['gd_available']      = function_exists( 'imagecreatetruecolor' );

		if ( $vision['imagick_available'] ) {
			try {
				$im = new \Imagick();
				$vision['imagick_formats'] = implode( ', ', array_slice( $im->queryFormats( 'PDF*' ), 0, 5 ) );
				if ( empty( $vision['imagick_formats'] ) ) {
					$vision['imagick_pdf_support'] = 'NO — PDF format not supported (Ghostscript missing)';
				} else {
					$vision['imagick_pdf_support'] = 'YES';
				}
				$im->destroy();
			} catch ( \Throwable $e ) {
				$vision['imagick_error'] = $e->getMessage();
			}
		}

		// 9. Try to find and test a real PDF in the vault.
		global $wpdb;
		$test_doc = $wpdb->get_row(
			"SELECT * FROM {$wpdb->prefix}zkv_documents WHERE mime_type = 'application/pdf' ORDER BY id DESC LIMIT 1",
			ARRAY_A
		);

		if ( $test_doc ) {
			// Resolve URL to filesystem path if needed.
			$pdf_path = $test_doc['file_url'];
			if ( ! empty( $pdf_path ) && ! file_exists( $pdf_path ) && strpos( $pdf_path, 'http' ) === 0 ) {
				$upload_dir = wp_upload_dir();
				$pdf_path = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $pdf_path );
			}
			// Also try via attachment_id.
			if ( ! file_exists( $pdf_path ) && ! empty( $test_doc['attachment_id'] ) ) {
				$att_path = get_attached_file( (int) $test_doc['attachment_id'] );
				if ( $att_path && file_exists( $att_path ) ) { $pdf_path = $att_path; }
			}
			$vision['test_pdf_id']    = $test_doc['id'];
			$vision['test_pdf_title'] = $test_doc['title'];
			$vision['test_pdf_path']  = $pdf_path;
			$vision['test_pdf_exists'] = file_exists( $pdf_path );

			if ( file_exists( $pdf_path ) && class_exists( 'ZKV_PDF_Reader' ) ) {
				$vision['is_image_only'] = ZKV_PDF_Reader::is_image_only( $pdf_path );

				// Try image extraction.
				try {
					$start = microtime( true );
					$images = ZKV_PDF_Reader::extract_page_images( $pdf_path, 1, 600 );
					$elapsed = round( ( microtime( true ) - $start ) * 1000 );
					$vision['extract_time_ms'] = $elapsed;
					$vision['images_extracted'] = count( $images );
					if ( ! empty( $images ) ) {
						$vision['first_image_size'] = strlen( $images[0] ) . ' bytes base64';
						// Decode to check dimensions.
						$raw = base64_decode( $images[0] );
						$gd = @imagecreatefromstring( $raw );
						if ( $gd ) {
							$vision['first_image_dims'] = imagesx( $gd ) . 'x' . imagesy( $gd );
							imagedestroy( $gd );
						}

						// Try sending to AI vision.
						if ( $has_key ) {
							$vision_content = array(
								array( 'type' => 'image_url', 'image_url' => array( 'url' => 'data:image/jpeg;base64,' . $images[0] ) ),
								array( 'type' => 'text', 'text' => 'Read the first line of text visible in this document image. Reply with ONLY that text, nothing else.' ),
							);
							$start2 = microtime( true );
							$vision_response = self::poe_query( $key, $vision_content );
							$vision['ai_vision_ms'] = round( ( microtime( true ) - $start2 ) * 1000 );
							$vision['ai_vision_response'] = substr( $vision_response, 0, 300 );
						}
					} else {
						$vision['extraction_failed'] = 'No images returned — check Imagick/GD';
					}
				} catch ( \Throwable $e ) {
					$vision['extract_error'] = $e->getMessage() . ' in ' . basename( $e->getFile() ) . ':' . $e->getLine();
				}
			}
		} else {
			$vision['test_pdf'] = 'No PDFs found in vault';
		}

		$report['vision'] = $vision;

		wp_send_json_success( $report );
	}

	/**
	 * AJAX: Toggle pricing authority flag on a document.
	 * Admin-only. Invalidates ZKV_Bridge cache on change.
	 *
	 * @since 1.2.8
	 */
	public function ajax_toggle_pricing() {
		check_ajax_referer( ZKV_NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Admin access required.' );
		}

		$doc_id = (int) ( $_POST['document_id'] ?? 0 );
		if ( $doc_id < 1 ) {
			wp_send_json_error( 'Invalid document ID.' );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'zkv_documents';

		// Get current value.
		$current = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT is_pricing_authority FROM {$table} WHERE id = %d",
			$doc_id
		) );

		// Toggle.
		$new_value = $current ? 0 : 1;

		// v1.5.2 (KV3): a document may be ENABLED as a pricing authority ONLY when it
		// lives in a designated pricing folder. Turning it OFF is always allowed, so a
		// mis-marked doc can always be cleared. Default stays "not pricing".
		if ( 1 === $new_value && ! self::doc_in_pricing_folder( $doc_id ) ) {
			wp_send_json_error( 'This document isn’t in a pricing folder, so it can’t be a pricing source. Move it into the “Pricing Documents” folder first, then mark it.' );
		}

		$wpdb->update(
			$table,
			array( 'is_pricing_authority' => $new_value, 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $doc_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		// Invalidate pricing cache.
		if ( class_exists( 'ZKV_Bridge' ) ) {
			ZKV_Bridge::invalidate_cache();
		}

		wp_send_json_success( array(
			'document_id'          => $doc_id,
			'is_pricing_authority' => $new_value,
			'message'              => $new_value ? 'Document marked as pricing authority.' : 'Pricing authority removed.',
		) );
	}

	// ══════════════════════════════════════════════════════════════
	//  v1.5.2 (KV3) — pricing-folder gating + review/clear
	// ══════════════════════════════════════════════════════════════

	/**
	 * The category slugs that count as a "pricing folder." A document may become a
	 * pricing authority ONLY if it lives in one of these AND is explicitly enabled.
	 * Filterable so more folders (e.g. supplier price sheets) can be added without a
	 * code change.
	 *
	 * @return string[] sanitized category slugs.
	 */
	public static function pricing_category_slugs() {
		$slugs = apply_filters( 'zkv_pricing_category_slugs', array( 'pricing-documents' ) );
		return array_values( array_filter( array_map( 'sanitize_title', (array) $slugs ) ) );
	}

	/** True iff the document's category is one of the designated pricing folders. */
	public static function doc_in_pricing_folder( $doc_id ) {
		global $wpdb;
		$slug = $wpdb->get_var( $wpdb->prepare(
			"SELECT c.slug FROM {$wpdb->prefix}zkv_documents d
			 LEFT JOIN {$wpdb->prefix}zkv_categories c ON c.id = d.category_id
			 WHERE d.id = %d",
			(int) $doc_id
		) );
		return $slug && in_array( $slug, self::pricing_category_slugs(), true );
	}

	/**
	 * AJAX (admin): list every document currently flagged as a pricing authority, so
	 * an admin can review and clear anything mis-marked. Flags whether each still
	 * lives in a pricing folder (a "stray" is one that does not).
	 */
	public function ajax_list_pricing() {
		check_ajax_referer( ZKV_NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Admin access required.' );
		}
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT d.id, d.title, d.status, c.slug AS category_slug, c.label AS category_label
			 FROM {$wpdb->prefix}zkv_documents d
			 LEFT JOIN {$wpdb->prefix}zkv_categories c ON c.id = d.category_id
			 WHERE d.is_pricing_authority = 1
			 ORDER BY d.updated_at DESC",
			ARRAY_A
		);
		$pricing_slugs = self::pricing_category_slugs();
		$out = array();
		foreach ( (array) $rows as $r ) {
			$in_folder = ! empty( $r['category_slug'] ) && in_array( $r['category_slug'], $pricing_slugs, true );
			$out[] = array(
				'id'                => (int) $r['id'],
				'title'             => (string) $r['title'],
				'category'          => $r['category_label'] ?: '(none)',
				'in_pricing_folder' => (bool) $in_folder,
			);
		}
		wp_send_json_success( array( 'pricing' => $out, 'pricing_folders' => $pricing_slugs ) );
	}

	/**
	 * AJAX (admin): clear the pricing-authority flag. With only_stray=1, clears ONLY
	 * documents that are NOT in a pricing folder (the mis-classified ones); otherwise
	 * clears every pricing-authority document. Turning the flag off is always safe.
	 */
	public function ajax_clear_pricing() {
		check_ajax_referer( ZKV_NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Admin access required.' );
		}
		global $wpdb;
		$now        = current_time( 'mysql' );
		$only_stray = 1 === (int) ( $_POST['only_stray'] ?? 0 );

		if ( $only_stray ) {
			$slugs = self::pricing_category_slugs();
			$ph    = implode( ',', array_fill( 0, max( 1, count( $slugs ) ), '%s' ) );
			$args  = empty( $slugs ) ? array( '' ) : $slugs;
			$ids   = $wpdb->get_col( $wpdb->prepare(
				"SELECT d.id FROM {$wpdb->prefix}zkv_documents d
				 LEFT JOIN {$wpdb->prefix}zkv_categories c ON c.id = d.category_id
				 WHERE d.is_pricing_authority = 1
				   AND ( c.slug IS NULL OR c.slug NOT IN ($ph) )",
				$args
			) );
			if ( empty( $ids ) ) {
				wp_send_json_success( array( 'cleared' => 0 ) );
			}
			$in_ids  = implode( ',', array_map( 'intval', $ids ) );
			$cleared = $wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->prefix}zkv_documents SET is_pricing_authority = 0, updated_at = %s
				 WHERE id IN ($in_ids)",
				$now
			) );
		} else {
			$cleared = $wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->prefix}zkv_documents SET is_pricing_authority = 0, updated_at = %s
				 WHERE is_pricing_authority = 1",
				$now
			) );
		}

		if ( class_exists( 'ZKV_Bridge' ) ) {
			ZKV_Bridge::invalidate_cache();
		}
		wp_send_json_success( array( 'cleared' => (int) $cleared ) );
	}

	/**
	 * AJAX: Upload a document from Brain Bot chat interface.
	 * Accepts base64-encoded file data + metadata.
	 * Creates document record and schedules AI indexing.
	 *
	 * Expected POST params:
	 *   - nonce: ZKV_NONCE
	 *   - file_data: base64-encoded file content
	 *   - filename: original filename (e.g., "price-sheet.pdf")
	 *   - mime_type: MIME type (e.g., "application/pdf")
	 *   - title: (optional) suggested title
	 *   - context: (optional) user context string
	 *   - category_slug: (optional) category slug
	 *   - is_pricing: (optional) "1" to mark as pricing authority
	 *
	 * @since 1.2.8
	 */
	public function ajax_upload_from_chat() {
		check_ajax_referer( ZKV_NONCE, 'nonce' );

		if ( ! $this->check_access() ) {
			wp_send_json_error( 'Access denied.' );
		}

		$file_data = $_POST['file_data'] ?? '';
		$filename  = sanitize_file_name( $_POST['filename'] ?? '' );
		$mime_type = sanitize_text_field( $_POST['mime_type'] ?? '' );

		if ( empty( $file_data ) || empty( $filename ) ) {
			wp_send_json_error( 'Missing file_data or filename.' );
		}

		// Decode base64.
		$decoded = base64_decode( $file_data, true );
		if ( false === $decoded ) {
			wp_send_json_error( 'Invalid base64 file data.' );
		}

		// Check file size.
		$file_size = strlen( $decoded );
		if ( $file_size > ZKV_MAX_UPLOAD_BYTES ) {
			wp_send_json_error( 'File exceeds ' . size_format( ZKV_MAX_UPLOAD_BYTES ) . ' limit.' );
		}

		// Validate MIME type against allowed list.
		$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		$allowed = $this->allowed_mimes();
		$mime_ok = false;
		foreach ( $allowed as $exts => $allowed_mime ) {
			$ext_list = explode( '|', $exts );
			if ( in_array( $ext, $ext_list, true ) ) {
				$mime_ok = true;
				if ( empty( $mime_type ) ) {
					$mime_type = $allowed_mime;
				}
				break;
			}
		}
		if ( ! $mime_ok ) {
			wp_send_json_error( 'File type not allowed: .' . $ext );
		}

		// Ensure vault directory exists.
		if ( function_exists( 'zkv_create_secure_vault_dir' ) ) {
			zkv_create_secure_vault_dir();
		}

		// Write file to vault, under an unguessable per-file random subdirectory so the raw
		// file URL cannot be guessed on servers that ignore the .htaccess deny (e.g. nginx).
		$vault_sub = ZKV_VAULT_DIR . '/' . bin2hex( random_bytes( 16 ) );
		wp_mkdir_p( $vault_sub );
		$safe_name = wp_unique_filename( $vault_sub, $filename );
		$file_path = $vault_sub . '/' . $safe_name;
		$written   = file_put_contents( $file_path, $decoded );

		if ( false === $written ) {
			wp_send_json_error( 'Failed to write file to vault.' );
		}

		// Compute hash for duplicate detection.
		$file_hash = hash( 'sha256', $decoded );

		global $wpdb;

		// Check for duplicates.
		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, title FROM {$wpdb->prefix}zkv_documents WHERE file_hash = %s LIMIT 1",
			$file_hash
		), ARRAY_A );

		if ( $existing ) {
			// Clean up the file we just wrote.
			@unlink( $file_path );
			wp_send_json_error( 'Duplicate file — already exists as "' . $existing['title'] . '" (ID: ' . $existing['id'] . ').' );
		}

		// Determine category.
		$category_id = null;
		$cat_slug = sanitize_title( $_POST['category_slug'] ?? '' );
		if ( ! empty( $cat_slug ) && class_exists( 'ZKV_Categories' ) ) {
			$cat = ZKV_Categories::get_by_slug( $cat_slug );
			if ( $cat ) {
				$category_id = (int) $cat['id'];
			}
		}

		// Create document record.
		$title   = sanitize_text_field( $_POST['title'] ?? '' );
		$context = sanitize_textarea_field( $_POST['context'] ?? '' );
		// v1.5.2 (KV3): uploads NEVER auto-flag pricing. A document enters the vault as
		// a normal doc; becoming a pricing authority is a separate, explicit,
		// folder-gated admin action (ajax_toggle_pricing). Ignoring any is_pricing hint
		// here stops documents from being "arbitrarily" treated as pricing at ingest.
		$is_pricing = 0;

		if ( empty( $title ) ) {
			$title = pathinfo( $filename, PATHINFO_FILENAME );
			$title = str_replace( array( '-', '_' ), ' ', $title );
			$title = ucwords( $title );
		}

		$user_id = get_current_user_id();

		$wpdb->insert( $wpdb->prefix . 'zkv_documents', array(
			'attachment_id'        => 0,
			'uploaded_by'          => $user_id,
			'slug'                 => '',
			'title'                => $title,
			'original_name'        => $filename,
			'mime_type'            => $mime_type,
			'file_size'            => $file_size,
			'file_url'             => $file_path,
			'file_hash'            => $file_hash,
			'source_type'          => 'chat_upload',
			'description'          => '',
			'user_context'         => $context,
			'category_id'          => $category_id,
			'status'               => 'pending',
			'visibility'           => 'all_employees',
			'is_pricing_authority' => $is_pricing,
			'version'              => 1,
			'created_at'           => current_time( 'mysql' ),
			'updated_at'           => current_time( 'mysql' ),
		), array( '%d','%d','%s','%s','%s','%s','%d','%s','%s','%s','%s','%s','%d','%s','%s','%d','%d','%s','%s' ) );

		$doc_id = $wpdb->insert_id;

		if ( ! $doc_id ) {
			@unlink( $file_path );
			wp_send_json_error( 'Failed to create document record.' );
		}

		// Schedule background indexing.
		wp_schedule_single_event( time(), 'zkv_process_pending_doc', array( $doc_id ) );

		wp_send_json_success( array(
			'document_id' => $doc_id,
			'title'       => $title,
			'status'      => 'pending',
			'message'     => 'Document uploaded from chat. AI indexing scheduled.',
		) );
	}

	/**
	 * Call a fast AI model to get title/category/description suggestions.
	 */
	private function get_ai_suggestions( $filename, $mime, $text, $vision_b64 = null ) {
		$defaults = array(
			'title'       => $this->filename_to_title( $filename ),
			'category'    => '',
			'description' => '',
		);

		// Get the Poe API key (properly decrypted).
		$api_key = self::get_poe_api_key();
		if ( empty( $api_key ) ) {
			error_log( 'ZKV preanalyze: No Poe API key configured — AI suggestions skipped.' );
			return $defaults;
		}

		// Build category list for the AI.
		$cats = class_exists( 'ZKV_Categories' ) ? ZKV_Categories::get_all() : array();
		$cat_list = '';
		foreach ( $cats as $c ) {
			$cat_list .= '- ' . $c['slug'] . ' (' . $c['label'] . '): ' . ( $c['description'] ?? '' ) . "\n";
		}

		error_log( 'ZKV preanalyze: Extracted ' . strlen( $text ) . ' chars of text. Categories: ' . count( $cats ) . '. Calling Poe AI...' );

		$prompt = "You are a document classifier. Analyze this file and respond with ONLY a JSON object:
{
  \"title\": \"Short title, max 5 words, professional\",
  \"category_slug\": \"best-matching-slug-from-list-below\",
  \"description\": \"One sentence describing what this document is about\"
}

CATEGORIES:
{$cat_list}

TITLE RULES: Max 5 words. Keep names/numbers if useful. No dates, no file extensions, no ALL CAPS.
Examples: \"Ladder Safety Policy\" not \"2026-04-26-ladder-plan.pdf\"
\"Heat Illness Prevention\" not \"2026 04 26 Heat illness plan\"

File: {$filename} ({$mime})
Content preview:
---
{$text}
---

Respond with ONLY the JSON object.";

		// Use vision if we have a page image (image-only PDF).
		if ( ! empty( $vision_b64 ) ) {
			$content_parts = array(
				array( 'type' => 'image_url', 'image_url' => array( 'url' => 'data:image/jpeg;base64,' . $vision_b64 ) ),
				array( 'type' => 'text', 'text' => $prompt ),
			);
			$response = self::poe_query( $api_key, $content_parts, self::get_ai_model() );
		} else {
			$response = self::poe_query( $api_key, $prompt, self::get_ai_model() );
		}

		if ( strpos( $response, 'Error:' ) === 0 ) {
			error_log( 'ZKV preanalyze: Poe AI returned error: ' . substr( $response, 0, 200 ) );
			return $defaults;
		}

		error_log( 'ZKV preanalyze: Poe AI responded (' . strlen( $response ) . ' chars). Parsing JSON...' );

		$parsed = self::parse_llm_json( $response );
		if ( ! $parsed || ! is_array( $parsed ) ) {
			error_log( 'ZKV preanalyze: JSON parse failed. Raw response: ' . substr( $response, 0, 300 ) );
			return $defaults;
		}

		error_log( 'ZKV preanalyze: Success — title="' . ( $parsed['title'] ?? '?' ) . '", category="' . ( $parsed['category_slug'] ?? '?' ) . '"' );

		return array(
			'title'       => sanitize_text_field( $parsed['title'] ?? $defaults['title'] ),
			'category'    => sanitize_text_field( $parsed['category_slug'] ?? '' ),
			'description' => sanitize_text_field( $parsed['description'] ?? '' ),
		);
	}

	/**
	 * Get the Poe API key.
	 *
	 * GENERALIZED (was: scavenge three other plugins' option families + reach
	 * into a company-era admin class). Now:
	 *   1. This app's own key (zkv_poe_api_key option — encrypted or plaintext).
	 *   2. The platform's shared credential store (ZDZ_Core_Settings), which is
	 *      the one place a Zorderz install keeps the Poe key.
	 * No cross-plugin class-scavenging; a missing key returns '' and callers
	 * degrade honestly (AI suggestions skipped).
	 */
	public static function get_poe_api_key() {
		// 1) This app's own dedicated key (highest priority).
		$own_key = get_option( 'zkv_poe_api_key', '' );
		if ( ! empty( $own_key ) ) {
			$decrypted = self::decrypt_key( $own_key );
			if ( ! empty( $decrypted ) ) { return $decrypted; }
			// May be stored plaintext.
			if ( strlen( $own_key ) > 30 ) { return $own_key; }
		}

		// 2) The platform's shared credential store.
		if ( class_exists( 'ZDZ_Core_Settings' ) ) {
			$key = ZDZ_Core_Settings::get_poe_api_key();
			if ( ! empty( $key ) ) { return $key; }
		}

		return '';
	}

	public static function encrypt_key( $plaintext ) {
		$salt = wp_salt( 'auth' );
		$iv   = substr( hash( 'sha256', $salt ), 0, 16 );
		return openssl_encrypt( $plaintext, 'AES-256-CBC', $salt, 0, $iv );
	}

	public static function decrypt_key( $encrypted ) {
		if ( empty( $encrypted ) ) { return ''; }
		$salt   = wp_salt( 'auth' );
		$iv     = substr( hash( 'sha256', $salt ), 0, 16 );
		$result = openssl_decrypt( $encrypted, 'AES-256-CBC', $salt, 0, $iv );
		return ( ! empty( $result ) && strlen( $result ) > 10 ) ? $result : '';
	}

	/**
	 * Query the model.
	 *
	 * Prefers the theme's shared Poe client (ZDZ_Core_Poe) for plain-text
	 * prompts so model selection, key handling and cross-vendor fallback live in
	 * ONE Core place. Falls back to the direct transport for multimodal (vision)
	 * content arrays and an SSE-format response the shared client does not handle,
	 * and whenever the Core client is unavailable or returns an error. api.poe.com
	 * is a third-party API host.
	 */
	public static function poe_query( $api_key, $prompt, $model = 'Gemini-3.1-Pro', $temperature = 0.0 ) {
		// A STORED zkv_ai_model carrying a retired/older handle still calls a live
		// model, without needing settings re-saved.
		$aliases = array(
			'Gemini-2.5-Flash'  => 'Gemini-3.6-Flash',
			'Gemini-3-Flash'    => 'Gemini-3.6-Flash',
			'Gemini-3.5-Flash'  => 'Gemini-3.6-Flash',
			'Gemini-3-Pro'      => 'Gemini-3.1-Pro',
			'Claude-Opus-4.7'   => 'Claude-Opus-4.8',
			'Claude-Opus-4.6'   => 'Claude-Opus-4.8',
			'Claude-Sonnet-4.5' => 'Claude-Sonnet-4.6',
			'GPT-5.2'           => 'GPT-5.5',
		);
		if ( isset( $aliases[ $model ] ) ) {
			$model = $aliases[ $model ];
		}

		// Preferred path: the platform's shared Poe client (plain-text prompts).
		if ( is_string( $prompt ) && class_exists( 'ZDZ_Core_Poe' ) ) {
			$client = new ZDZ_Core_Poe( $api_key, $model );
			$out    = $client->query( array( array( 'role' => 'user', 'content' => $prompt ) ), $temperature );
			if ( is_string( $out ) && '' !== $out && stripos( $out, 'Error:' ) !== 0 ) {
				return $out;
			}
			// else fall through to the direct transport below.
		}

		// Support both string prompts and multimodal content arrays.
		$content = $prompt;
		// If $prompt is already an array (multimodal), use as-is.

		$body = array(
			'model'       => $model,
			'stream'      => false,
			'temperature' => $temperature,
			'messages'    => array(
				array( 'role' => 'user', 'content' => $content ),
			),
		);

		$response = wp_remote_post( 'https://api.poe.com/v1/chat/completions', array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
			'timeout' => 120, // Vision requests can take longer.
		) );

		if ( is_wp_error( $response ) ) {
			return 'Error: ' . $response->get_error_message();
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );

		if ( 200 !== (int) $code ) {
			$err = json_decode( $raw, true );
			$msg = $err['error']['message'] ?? substr( $raw, 0, 200 );
			return "Error: Poe API {$code}: {$msg}";
		}

		$data = json_decode( $raw, true );
		if ( isset( $data['choices'][0]['message']['content'] ) ) {
			return $data['choices'][0]['message']['content'];
		}

		// SSE fallback — Poe sometimes returns streaming format.
		if ( strpos( $raw, 'data: ' ) !== false ) {
			$text = '';
			foreach ( explode( "\n", $raw ) as $line ) {
				if ( strpos( $line, 'data: ' ) === 0 ) {
					$chunk = json_decode( substr( $line, 6 ), true );
					if ( isset( $chunk['choices'][0]['delta']['content'] ) ) {
						$text .= $chunk['choices'][0]['delta']['content'];
					}
				}
			}
			if ( ! empty( $text ) ) { return $text; }
		}

		return 'Error: No response content. Raw: ' . substr( $raw, 0, 200 );
	}

	/**
	 * Extract JSON from an AI response (may be wrapped in markdown fences).
	 */
	public static function parse_llm_json( $response ) {
		if ( preg_match( '/```json\s*(.*?)\s*```/s', $response, $m ) ) {
			return json_decode( $m[1], true );
		}
		if ( preg_match( '/\{.*\}/s', $response, $m ) ) {
			return json_decode( $m[0], true );
		}
		return null;
	}

	private function filename_to_title( $filename ) {
		$name = pathinfo( $filename, PATHINFO_FILENAME );
		$name = preg_replace( '/^\d{4}[-_\s]*\d{2}[-_\s]*\d{2}[-_\s]*/', '', $name ); // Strip leading dates
		$name = str_replace( array( '-', '_', '.' ), ' ', $name );
		$name = preg_replace( '/\s+/', ' ', trim( $name ) );
		return ucwords( strtolower( $name ) );
	}

	// ──────────────────────────────────────────────────────────────
	//  AJAX: Upload (finalizes — file already in vault from preanalyze)
	//
	//  Returns IMMEDIATELY. Deep AI indexing runs afterward.
	//  Document appears in the list right away with "Processing" badge.
	// ──────────────────────────────────────────────────────────────

	public function ajax_upload() {
		ob_start();
		check_ajax_referer( ZKV_NONCE, 'nonce' );
		if ( ! $this->check_access() ) { ob_end_clean(); wp_send_json_error( 'Access denied.' ); }

		// v2.17.0 (5C): Theme-level upload gate.
		if ( class_exists( 'ZDZ_Data_Permissions' ) && ! ZDZ_Data_Permissions::can( get_current_user_id(), 'upload_to_knowledge_vault' ) ) {
			ob_end_clean();
			wp_send_json_error( 'Upload permission denied.' );
		}

		// Try to reuse file from preanalyze step.
		$temp_key = sanitize_text_field( $_POST['temp_key'] ?? '' );
		$temp_data = null;
		if ( ! empty( $temp_key ) ) {
			$temp_data = get_transient( $temp_key );
			if ( $temp_data ) {
				delete_transient( $temp_key );
			}
		}

		// If no preanalyzed file, handle fresh upload.
		if ( ! $temp_data ) {
			if ( empty( $_FILES['file'] ) ) {
				ob_end_clean();
				wp_send_json_error( 'No file uploaded.' );
			}
			$file = $_FILES['file'];
			if ( ! empty( $file['size'] ) && (int) $file['size'] > ZKV_MAX_UPLOAD_BYTES ) {
				ob_end_clean();
				wp_send_json_error( 'File exceeds 25 MB limit.' );
			}
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			if ( function_exists( 'zkv_create_secure_vault_dir' ) ) { zkv_create_secure_vault_dir(); }
			$vf = function( $u ) { $u['path'] = ZKV_VAULT_DIR; $u['url'] = ZKV_VAULT_URL; $u['subdir'] = ''; return $u; };
			add_filter( 'upload_dir', $vf );
			$result = wp_handle_upload( $file, array( 'test_form' => false, 'mimes' => $this->allowed_mimes() ) );
			remove_filter( 'upload_dir', $vf );
			if ( ! empty( $result['error'] ) ) { ob_end_clean(); wp_send_json_error( $result['error'] ); }
			$temp_data = array(
				'file'      => $result['file'],
				'url'       => $result['url'],
				'type'      => $result['type'],
				'name'      => $file['name'],
				'size'      => filesize( $result['file'] ),
				'file_hash' => hash_file( 'sha256', $result['file'] ),
			);
		}

		// Create WP attachment (private).
		$att_id = wp_insert_attachment( array(
			'guid'           => $temp_data['url'] ?? '',
			'post_mime_type' => $temp_data['type'],
			'post_title'     => sanitize_file_name( pathinfo( $temp_data['file'], PATHINFO_FILENAME ) ),
			'post_content'   => '',
			'post_status'    => 'private',
			'post_author'    => get_current_user_id(),
		), $temp_data['file'] );

		if ( is_wp_error( $att_id ) || ! $att_id ) {
			ob_end_clean();
			wp_send_json_error( 'Failed to register file.' );
		}

		wp_generate_attachment_metadata( $att_id, $temp_data['file'] );
		update_post_meta( $att_id, '_zkv_vault_document', '1' );

		// Use user-provided (AI-suggested) metadata.
		$title = sanitize_text_field( $_POST['title'] ?? '' );
		if ( empty( $title ) ) {
			$title = $this->filename_to_title( $temp_data['name'] );
		}

		$category_id = null;
		$cat_slug = sanitize_text_field( $_POST['category'] ?? '' );
		if ( ! empty( $cat_slug ) && class_exists( 'ZKV_Categories' ) ) {
			$cat = ZKV_Categories::get_by_slug( $cat_slug );
			if ( $cat ) { $category_id = (int) $cat['id']; }
		}

		// v1.5.0: 'transcript_private' is the uploader's OPT-IN assertion — the
		// only signal that auto-privatizes a document (D4). It takes effect AT
		// INSERT, before any index/chunk content exists, so there is no instant
		// at which the text is both indexed-visible and unscoped.
		$visibility = in_array( $_POST['visibility'] ?? '', array( 'all_employees', 'admin_only', 'transcript_private' ), true )
		              ? $_POST['visibility'] : 'all_employees';
		$transcript_status = ( 'transcript_private' === $visibility ) ? 'detected' : '';

		// Generate slug from AI-suggested title.
		$slug = '';
		if ( function_exists( 'zkv_generate_slug' ) ) {
			$slug = zkv_generate_slug( $title, 0 ); // ID 0, will update after insert
		}

		// Insert document record — IMMEDIATELY visible with "pending" status.
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'zkv_documents', array(
			'attachment_id' => (int) $att_id,
			'uploaded_by'   => get_current_user_id(),
			'slug'          => $slug,
			'title'         => $title,
			'original_name' => sanitize_file_name( $temp_data['name'] ),
			'mime_type'     => $temp_data['type'],
			'file_size'     => (int) $temp_data['size'],
			'file_url'      => $temp_data['file'],
			'file_hash'     => $temp_data['file_hash'] ?? '',
			'source_type'   => $temp_data['source_type'] ?? 'upload',
			'description'   => sanitize_textarea_field( $_POST['description'] ?? '' ),
			'user_context'  => sanitize_textarea_field( $_POST['user_context'] ?? '' ),
			'category_id'   => $category_id,
			'status'        => 'pending',
			'visibility'    => $visibility,
			'transcript_status' => $transcript_status,
			'version'       => 1,
			'created_at'    => current_time( 'mysql' ),
			'updated_at'    => current_time( 'mysql' ),
		) );

		$doc_id = (int) $wpdb->insert_id;

		// Fix slug uniqueness now that we have the real ID.
		if ( function_exists( 'zkv_generate_slug' ) && $doc_id > 0 ) {
			$final_slug = zkv_generate_slug( $title, $doc_id );
			$wpdb->update( $wpdb->prefix . 'zkv_documents',
				array( 'slug' => $final_slug ),
				array( 'id' => $doc_id ),
				array( '%s' ), array( '%d' )
			);
		}

		// Schedule deep AI indexing for the next page load.
		// This runs in the background so the upload response is instant.
		wp_schedule_single_event( time(), 'zkv_process_pending_doc', array( $doc_id ) );

		// Return SUCCESS immediately — document is now in the list.
		ob_end_clean();
		wp_send_json_success( array(
			'document_id' => $doc_id,
			'title'       => $title,
			'status'      => 'pending',
		) );
	}

	// ──────────────────────────────────────────────────────────────
	//  AJAX: Search
	// ──────────────────────────────────────────────────────────────

	public function ajax_search() {
		check_ajax_referer( ZKV_NONCE, 'nonce' );
		if ( ! $this->check_access() ) { wp_send_json_error( 'Access denied.' ); }

		global $wpdb;
		$query = trim( sanitize_text_field( $_POST['query'] ?? '' ) );
		if ( empty( $query ) ) { wp_send_json_success( array() ); }

		$vis = $this->visibility_sql( 'd' );

		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT d.id, d.slug, d.title, d.original_name, d.mime_type, d.file_size,
			        d.file_url, d.status, d.visibility, d.uploaded_by, d.created_at,
			        i.synopsis, i.document_type, i.tags,
			        MATCH(i.search_text) AGAINST(%s IN NATURAL LANGUAGE MODE) as relevance
			 FROM {$wpdb->prefix}zkv_index i
			 JOIN {$wpdb->prefix}zkv_documents d ON d.id = i.document_id
			 WHERE i.is_current = 1
			   AND d.status = 'indexed'
			   AND MATCH(i.search_text) AGAINST(%s IN NATURAL LANGUAGE MODE)
			   {$vis}
			 ORDER BY relevance DESC
			 LIMIT 30",
			$query, $query
		), ARRAY_A );

		if ( ! is_array( $results ) ) { $results = array(); }

		foreach ( $results as &$r ) {
			$u = get_userdata( (int) $r['uploaded_by'] );
			$r['uploader_name'] = $u ? $u->display_name : 'Unknown';
			$r = $this->sanitize_for_frontend( $r ); // Strip private paths
		}

		wp_send_json_success( $results );
	}

	// ──────────────────────────────────────────────────────────────
	//  AJAX: List Documents
	// ──────────────────────────────────────────────────────────────

	public function ajax_list() {
		check_ajax_referer( ZKV_NONCE, 'nonce' );
		if ( ! $this->check_access() ) { wp_send_json_error( 'Access denied.' ); }

		global $wpdb;
		$page     = max( 1, (int) ( $_POST['page'] ?? 1 ) );
		$per_page = min( 50, max( 1, (int) ( $_POST['per_page'] ?? 20 ) ) );
		$offset   = ( $page - 1 ) * $per_page;
		$vis      = $this->visibility_sql( 'd' );

		$d = $wpdb->prefix . 'zkv_documents';
		$i = $wpdb->prefix . 'zkv_index';

		// Category filter.
		$cat_clause = '';
		$cat_slug   = sanitize_text_field( $_POST['category'] ?? '' );
		if ( ! empty( $cat_slug ) && class_exists( 'ZKV_Categories' ) ) {
			$cat = ZKV_Categories::get_by_slug( $cat_slug );
			if ( $cat ) {
				$cat_clause = $wpdb->prepare( ' AND d.category_id = %d', (int) $cat['id'] );
			}
		}

		$where = "WHERE d.status IN ('indexed','pending','processing') {$vis} {$cat_clause}";

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$d} d {$where}" );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT d.id, d.slug, d.title, d.original_name, d.mime_type, d.file_size,
			        d.file_url, d.status, d.uploaded_by, d.created_at, d.category_id,
			        i.synopsis, i.document_type, i.tags
			 FROM {$d} d
			 LEFT JOIN {$i} i ON i.document_id = d.id AND i.is_current = 1
			 {$where}
			 ORDER BY d.created_at DESC
			 LIMIT %d OFFSET %d",
			$per_page, $offset
		), ARRAY_A );

		if ( ! is_array( $rows ) ) { $rows = array(); }

		foreach ( $rows as &$r ) {
			$u = get_userdata( (int) $r['uploaded_by'] );
			$r['uploader_name'] = $u ? $u->display_name : 'Unknown';
			$r = $this->sanitize_for_frontend( $r ); // Strip private paths
		}

		wp_send_json_success( array(
			'documents' => $rows,
			'total'     => $total,
			'page'      => $page,
			'per_page'  => $per_page,
			'pages'     => (int) ceil( $total / max( 1, $per_page ) ),
		) );
	}

	// ──────────────────────────────────────────────────────────────
	//  AJAX: Get Document
	// ──────────────────────────────────────────────────────────────

	public function ajax_get() {
		check_ajax_referer( ZKV_NONCE, 'nonce' );
		if ( ! $this->check_access() ) { wp_send_json_error( 'Access denied.' ); }

		global $wpdb;
		$id  = (int) ( $_POST['document_id'] ?? 0 );
		$vis = $this->visibility_sql( 'd' );

		$doc = $wpdb->get_row( $wpdb->prepare(
			"SELECT d.*, i.synopsis, i.key_entities, i.key_facts, i.document_type, i.tags, i.summary_json
			 FROM {$wpdb->prefix}zkv_documents d
			 LEFT JOIN {$wpdb->prefix}zkv_index i ON i.document_id = d.id AND i.is_current = 1
			 WHERE d.id = %d {$vis}",
			$id
		), ARRAY_A );

		if ( ! $doc ) { wp_send_json_error( 'Not found.' ); }

		$u = get_userdata( (int) $doc['uploaded_by'] );
		$doc['uploader_name'] = $u ? $u->display_name : 'Unknown';
		$uploaded_by_id    = (int) $doc['uploaded_by'];                    // v1.5.2 (KV2) capture before sanitize
		$transcript_status = (string) ( $doc['transcript_status'] ?? '' ); // v1.5.2 (KV2)
		$doc = $this->sanitize_for_frontend( $doc ); // Strip private paths

		// v1.5.0: transcript extras for the detail view. The ACL in the query
		// above already guaranteed the caller is a party or an active
		// whole-doc sharee — this block only decides which UI they get.
		if ( class_exists( 'ZKV_ACL' ) && ZKV_ACL::is_transcript_visibility( $doc['visibility'] ?? '' ) ) {
			$uid = get_current_user_id();
			$doc['is_transcript']   = true;
			$doc['viewer_is_party'] = ZKV_ACL::is_party( $uid, $id );
			$doc['viewer_is_sharee'] = ! $doc['viewer_is_party'];
			$doc['viewer_is_uploader'] = ( $uploaded_by_id === $uid ); // v1.5.2 (KV2)
			$doc['transcript_status']  = $transcript_status;           // v1.5.2 (KV2)

			if ( $doc['viewer_is_party'] ) {
				$parties = array();
				$my_label = '';
				foreach ( ZKV_ACL::parties( $id ) as $p ) {
					$pu = get_userdata( (int) $p['user_id'] );
					$parties[] = array(
						'user_id'       => (int) $p['user_id'],
						'name'          => $pu ? $pu->display_name : ( '#' . $p['user_id'] ),
						'speaker_label' => $p['speaker_label'],
						'match_method'  => $p['match_method'],
					);
					if ( (int) $p['user_id'] === $uid ) { $my_label = $p['speaker_label']; }
				}
				$doc['parties']  = $parties;
				$doc['my_label'] = $my_label;

				$shares = array();
				foreach ( ZKV_ACL::live_shares( $id ) as $s ) {
					$su = get_userdata( (int) $s['shared_with'] );
					$by = get_userdata( (int) $s['shared_by'] );
					$shares[] = array(
						'id'         => (int) $s['id'],
						'with_name'  => $su ? $su->display_name : ( '#' . $s['shared_with'] ),
						'by_name'    => $by ? $by->display_name : ( '#' . $s['shared_by'] ),
						'scope'      => $s['scope'],
						'expires_at' => $s['expires_at'],
					);
				}
				$doc['shares'] = $shares;
			} else {
				// Whole-doc sharee: banner data only — no party list, no
				// share controls, and never any Brain Bot involvement.
				$grantor = null;
				global $wpdb;
				$now = esc_sql( current_time( 'mysql' ) );
				$grantor_id = $wpdb->get_var( $wpdb->prepare(
					"SELECT shared_by FROM {$wpdb->prefix}zkv_doc_shares
					 WHERE document_id = %d AND shared_with = %d AND scope = 'whole'
					   AND revoked_at IS NULL AND ( expires_at IS NULL OR expires_at > '{$now}' )
					 ORDER BY id DESC LIMIT 1",
					$id, $uid
				) );
				$expires = $wpdb->get_var( $wpdb->prepare(
					"SELECT expires_at FROM {$wpdb->prefix}zkv_doc_shares
					 WHERE document_id = %d AND shared_with = %d AND scope = 'whole'
					   AND revoked_at IS NULL AND ( expires_at IS NULL OR expires_at > '{$now}' )
					 ORDER BY id DESC LIMIT 1",
					$id, $uid
				) );
				$gu = $grantor_id ? get_userdata( (int) $grantor_id ) : null;
				$doc['shared_by_name']    = $gu ? $gu->display_name : 'a party';
				$doc['share_expires_at']  = $expires;
			}
		}

		wp_send_json_success( $doc );
	}

	// ──────────────────────────────────────────────────────────────
	//  AJAX: Delete Document
	// ──────────────────────────────────────────────────────────────

	public function ajax_delete() {
		check_ajax_referer( ZKV_NONCE, 'nonce' );
		if ( ! $this->check_access() ) { wp_send_json_error( 'Access denied.' ); }

		global $wpdb;
		$id      = (int) ( $_POST['document_id'] ?? 0 );
		$user_id = get_current_user_id();

		$doc = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}zkv_documents WHERE id = %d", $id
		), ARRAY_A );

		if ( ! $doc ) { wp_send_json_error( 'Not found.' ); }

		// v1.5.0: for a private transcript, a caller who is neither the
		// uploader nor an admin gets 'Not found.' — not 'Permission denied.'
		// (which would confirm the id exists). Uploader keeps delete (they
		// created the record; deleting exposes no content) and admins keep
		// delete (the latent queue's cleanup action) — neither gains a READ.
		$is_transcript = class_exists( 'ZKV_ACL' ) && ZKV_ACL::is_transcript_visibility( $doc['visibility'] );
		if ( $is_transcript && ! $this->is_admin_user() && (int) $doc['uploaded_by'] !== $user_id ) {
			wp_send_json_error( 'Not found.' );
		}

		// Only owner or admin can delete.
		if ( ! $this->is_admin_user() && (int) $doc['uploaded_by'] !== $user_id ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$wpdb->delete( $wpdb->prefix . 'zkv_index', array( 'document_id' => $id ), array( '%d' ) );
		if ( ! empty( $doc['attachment_id'] ) ) {
			wp_delete_attachment( (int) $doc['attachment_id'], true );
		}
		$wpdb->delete( $wpdb->prefix . 'zkv_documents', array( 'id' => $id ), array( '%d' ) );

		// v1.5.0: transcript ancillary rows go with the document. Chunks too
		// (pre-existing gap — deleting a doc left its chunk text sitting in
		// wp_zkv_chunks forever; tidied here for every doc type).
		$wpdb->delete( $wpdb->prefix . 'zkv_chunks', array( 'document_id' => $id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'zkv_doc_parties', array( 'document_id' => $id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'zkv_doc_shares', array( 'document_id' => $id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'zkv_transcript_lines', array( 'document_id' => $id ), array( '%d' ) );
		if ( $is_transcript && class_exists( 'ZKV_ACL' ) ) {
			ZKV_ACL::log( 'transcript_deleted', $id, $user_id );
			ZKV_ACL::reset_cache();
		}

		// v1.2.6: Invalidate the TSA bridge inventory cache so Brain Bot
		// sees the updated document list on the next query.
		if ( class_exists( 'ZKV_TSA_Bridge' ) && method_exists( 'ZKV_TSA_Bridge', 'invalidate_cache' ) ) {
			ZKV_TSA_Bridge::invalidate_cache();
		}

		wp_send_json_success( array( 'deleted' => true ) );
	}

	// ──────────────────────────────────────────────────────────────
	//  AJAX: Categories
	// ──────────────────────────────────────────────────────────────

	public function ajax_categories() {
		check_ajax_referer( ZKV_NONCE, 'nonce' );
		if ( ! $this->check_access() ) { wp_send_json_error( 'Access denied.' ); }
		wp_send_json_success( ZKV_Categories::get_all() );
	}

	// ──────────────────────────────────────────────────────────────
	//  Media Library Filter
	// ──────────────────────────────────────────────────────────────

	public function filter_media_library( $query ) {
		if ( ! isset( $query['meta_query'] ) || ! is_array( $query['meta_query'] ) ) {
			$query['meta_query'] = array();
		}
		$query['meta_query'][] = array(
			'key'     => '_zkv_vault_document',
			'compare' => 'NOT EXISTS',
		);
		return $query;
	}

	// ──────────────────────────────────────────────────────────────
	//  AJAX: Update Context — edit user_context on an existing doc
	// ──────────────────────────────────────────────────────────────

	public function ajax_update_context() {
		check_ajax_referer( ZKV_NONCE, 'nonce' );
		if ( ! $this->check_access() ) { wp_send_json_error( 'Access denied.' ); }

		$doc_id  = (int) ( $_POST['document_id'] ?? 0 );
		$context = sanitize_textarea_field( $_POST['user_context'] ?? '' );

		if ( $doc_id < 1 ) { wp_send_json_error( 'Invalid document ID.' ); }

		global $wpdb;
		$doc = $wpdb->get_row( $wpdb->prepare(
			"SELECT uploaded_by FROM {$wpdb->prefix}zkv_documents WHERE id = %d",
			$doc_id
		), ARRAY_A );

		if ( ! $doc ) { wp_send_json_error( 'Document not found.' ); }

		if ( (int) $doc['uploaded_by'] !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Only the uploader or admins can edit context.' );
		}

		$wpdb->update(
			$wpdb->prefix . 'zkv_documents',
			array( 'user_context' => $context, 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $doc_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		// Update search_text in index to include new context.
		$idx = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, search_text FROM {$wpdb->prefix}zkv_index WHERE document_id = %d AND is_current = 1",
			$doc_id
		), ARRAY_A );
		if ( $idx ) {
			$search = $idx['search_text'] . ' ' . $context;
			$wpdb->update(
				$wpdb->prefix . 'zkv_index',
				array( 'search_text' => $search ),
				array( 'id' => (int) $idx['id'] ),
				array( '%s' ), array( '%d' )
			);
		}

		wp_send_json_success( array( 'saved' => true ) );
	}

	// ──────────────────────────────────────────────────────────────
	//  AJAX: Paste Text — user pastes text (transcripts, notes, etc.)
	//  Saves as .txt file in vault, runs AI pre-analysis, creates
	//  document record, and schedules deep indexing.
	// ──────────────────────────────────────────────────────────────

	// ──────────────────────────────────────────────────────────────
	//  AJAX: Re-index — re-run deep AI analysis on an existing document.
	//  Admin only. Useful after improving text extraction.
	// ──────────────────────────────────────────────────────────────

	public function ajax_reindex() {
		check_ajax_referer( ZKV_NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Admin only.' ); }

		$doc_id = (int) ( $_POST['document_id'] ?? 0 );
		if ( $doc_id < 1 ) { wp_send_json_error( 'Invalid document ID.' ); }

		if ( ! class_exists( 'ZKV_Indexer' ) ) { wp_send_json_error( 'Indexer not available.' ); }

		// Process synchronously — don't rely on WP Cron for immediate re-index.
		$result = ZKV_Indexer::reindex( $doc_id );

		if ( $result['success'] ) {
			// v1.5.0: a private transcript's synopsis never rides back in an
			// admin response — the admin may re-index (operational action)
			// without gaining a read (they are not a party).
			$synopsis = $result['synopsis'] ?? '';
			if ( class_exists( 'ZKV_ACL' ) && ZKV_ACL::is_transcript( $doc_id )
			     && ! ZKV_ACL::can_view_whole( get_current_user_id(), $doc_id ) ) {
				$synopsis = '';
			}
			wp_send_json_success( array( 'message' => 'Re-indexed successfully.', 'synopsis' => $synopsis ) );
		} else {
			wp_send_json_error( 'Re-index failed: ' . ( $result['error'] ?? 'Unknown error' ) );
		}
	}

	public function ajax_reindex_all() {
		check_ajax_referer( ZKV_NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Admin only.' ); }

		if ( ! class_exists( 'ZKV_Indexer' ) ) { wp_send_json_error( 'Indexer not available.' ); }

		$results = ZKV_Indexer::reindex_all();
		$success = 0; $fail = 0;
		foreach ( $results as $r ) {
			if ( ! empty( $r['success'] ) ) { $success++; } else { $fail++; }
		}

		wp_send_json_success( array( 'message' => "Re-indexed {$success} documents. {$fail} failed." ) );
	}

	// ──────────────────────────────────────────────────────────────
	//  AJAX: Settings — save/get ZKV configuration (admin only)
	// ──────────────────────────────────────────────────────────────

	public function ajax_save_settings() {
		check_ajax_referer( ZKV_NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Admin only.' ); }

		$api_key = sanitize_text_field( $_POST['api_key'] ?? '' );
		$model   = sanitize_text_field( $_POST['ai_model'] ?? 'Gemini-3.1-Pro' );

		if ( ! empty( $api_key ) ) {
			// Encrypt and store.
			$encrypted = self::encrypt_key( $api_key );
			update_option( 'zkv_poe_api_key', $encrypted ?: $api_key );
		} else {
			delete_option( 'zkv_poe_api_key' );
		}

		update_option( 'zkv_ai_model', $model );

		// Quick test the key.
		$test_key = self::get_poe_api_key();
		$test = '';
		if ( ! empty( $test_key ) ) {
			$test = self::poe_query( $test_key, 'Reply with exactly: OK', $model );
		}

		wp_send_json_success( array(
			'saved'       => true,
			'key_set'     => ! empty( $test_key ),
			'test_result' => substr( $test, 0, 100 ),
		) );
	}

	public function ajax_get_settings() {
		check_ajax_referer( ZKV_NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Admin only.' ); }

		$key = self::get_poe_api_key();
		$masked = '';
		if ( ! empty( $key ) ) {
			$masked = substr( $key, 0, 8 ) . '...' . substr( $key, -4 );
		}

		wp_send_json_success( array(
			'api_key_masked' => $masked,
			'api_key_set'    => ! empty( $key ),
			'ai_model'       => get_option( 'zkv_ai_model', 'Gemini-3.1-Pro' ),
			'has_own_key'    => ! empty( get_option( 'zkv_poe_api_key', '' ) ),
			'key_source'     => ! empty( get_option( 'zkv_poe_api_key', '' ) ) ? "this app's key" : ( class_exists( 'ZDZ_Core_Settings' ) ? 'platform shared key' : 'none' ),
		) );
	}

	/**
	 * Get the configured AI model name.
	 */
	public static function get_ai_model() {
		return get_option( 'zkv_ai_model', 'Gemini-3.1-Pro' );
	}

	// ──────────────────────────────────────────────────────────────
	//  AJAX: Paste Text
	// ──────────────────────────────────────────────────────────────

	public function ajax_paste_text() {
		ob_start();
		try {

		check_ajax_referer( ZKV_NONCE, 'nonce' );
		if ( ! $this->check_access() ) { ob_end_clean(); wp_send_json_error( 'Access denied.' ); }

		$pasted_text = wp_unslash( $_POST['text'] ?? '' );
		if ( strlen( trim( $pasted_text ) ) < 10 ) {
			ob_end_clean();
			wp_send_json_error( 'Please paste at least a few sentences of text.' );
		}

		$title       = sanitize_text_field( $_POST['title'] ?? '' );
		$category    = sanitize_text_field( $_POST['category'] ?? '' );
		$description = sanitize_textarea_field( $_POST['description'] ?? '' );
		// v1.5.0: paste flow supports the same opt-in (transcripts are pasted
		// at least as often as they are uploaded).
		$visibility  = in_array( $_POST['visibility'] ?? '', array( 'all_employees', 'admin_only', 'transcript_private' ), true )
		               ? $_POST['visibility'] : 'all_employees';
		$transcript_status = ( 'transcript_private' === $visibility ) ? 'detected' : '';

		// Ensure vault dir exists.
		if ( function_exists( 'zkv_create_secure_vault_dir' ) ) {
			zkv_create_secure_vault_dir();
		}

		// Save text as .txt file in vault.
		$safe_title = sanitize_file_name( $title ?: 'pasted-text' );
		$filename   = date( 'Y-m-d' ) . '-' . $safe_title . '.txt';
		// Unguessable per-file random subdirectory (see the upload path) so the URL can't be guessed.
		$vault_sub  = ZKV_VAULT_DIR . '/' . bin2hex( random_bytes( 16 ) );
		wp_mkdir_p( $vault_sub );
		$file_path  = $vault_sub . '/' . wp_unique_filename( $vault_sub, $filename );

		file_put_contents( $file_path, $pasted_text );

		// Compute content hash.
		$file_hash = hash( 'sha256', $pasted_text );

		// Check for duplicate.
		global $wpdb;
		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, title, original_name FROM {$wpdb->prefix}zkv_documents WHERE file_hash = %s LIMIT 1",
			$file_hash
		), ARRAY_A );
		if ( $existing ) {
			@unlink( $file_path );
			ob_end_clean();
			wp_send_json_error( 'This exact text already exists in the vault as "' . $existing['title'] . '".' );
		}

		// AI suggestions if no title provided.
		if ( empty( $title ) ) {
			$suggestions = $this->get_ai_suggestions( $filename, 'text/plain', substr( $pasted_text, 0, 4000 ) );
			$title = $suggestions['title'] ?: $safe_title;
			if ( empty( $category ) ) { $category = $suggestions['category']; }
			if ( empty( $description ) ) { $description = $suggestions['description']; }
		}

		// Resolve category.
		$category_id = null;
		if ( ! empty( $category ) && class_exists( 'ZKV_Categories' ) ) {
			$cat_row = $wpdb->get_row( $wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}zkv_categories WHERE slug = %s",
				$category
			), ARRAY_A );
			if ( $cat_row ) { $category_id = (int) $cat_row['id']; }
		}

		// Generate slug.
		$slug = '';
		if ( function_exists( 'zkv_generate_slug' ) ) {
			$slug = zkv_generate_slug( $title, 0 );
		}

		// Insert document record.
		$wpdb->insert( $wpdb->prefix . 'zkv_documents', array(
			'attachment_id' => 0,
			'uploaded_by'   => get_current_user_id(),
			'slug'          => $slug,
			'title'         => $title,
			'original_name' => $filename,
			'mime_type'     => 'text/plain',
			'file_size'     => strlen( $pasted_text ),
			'file_url'      => $file_path,
			'file_hash'     => $file_hash,
			'source_type'   => 'paste',
			'description'   => $description,
			'user_context'  => sanitize_textarea_field( $_POST['user_context'] ?? '' ),
			'category_id'   => $category_id,
			'status'        => 'pending',
			'visibility'    => $visibility,
			'transcript_status' => $transcript_status,
			'version'       => 1,
			'created_at'    => current_time( 'mysql' ),
			'updated_at'    => current_time( 'mysql' ),
		) );

		$doc_id = (int) $wpdb->insert_id;

		// Fix slug uniqueness.
		if ( function_exists( 'zkv_generate_slug' ) && $doc_id > 0 ) {
			$final_slug = zkv_generate_slug( $title, $doc_id );
			$wpdb->update( $wpdb->prefix . 'zkv_documents',
				array( 'slug' => $final_slug ),
				array( 'id' => $doc_id ),
				array( '%s' ), array( '%d' )
			);
		}

		// Schedule deep AI indexing.
		if ( $doc_id > 0 ) {
			wp_schedule_single_event( time(), 'zkv_process_pending_doc', array( $doc_id ) );
			spawn_cron();
		}

		ob_end_clean();
		wp_send_json_success( array( 'document_id' => $doc_id, 'title' => $title ) );

		} catch ( \Throwable $e ) {
			ob_end_clean();
			wp_send_json_error( 'Paste text error: ' . $e->getMessage() );
		}
	}

	// ──────────────────────────────────────────────────────────────
	//  SECURE DOWNLOAD PROXY
	//
	//  This is the ONLY way to access vault files via HTTP.
	//  Checks: logged in → nonce valid → has zdz_access_app → file exists.
	//  .htaccess blocks all direct access to the vault directory.
	// ──────────────────────────────────────────────────────────────

	public function ajax_download() {
		// Layer 1: Must be logged in (wp_ajax_ already ensures this, but belt-and-suspenders).
		if ( ! is_user_logged_in() ) {
			status_header( 403 );
			die( 'Authentication required.' );
		}

		// Layer 2: Verify nonce.
		if ( ! check_ajax_referer( ZKV_NONCE, 'nonce', false ) ) {
			status_header( 403 );
			die( 'Invalid security token.' );
		}

		// Layer 3: Must have app access.
		if ( ! $this->check_access() ) {
			status_header( 403 );
			die( 'Access denied.' );
		}

		$doc_id = (int) ( $_GET['id'] ?? 0 );
		if ( $doc_id <= 0 ) {
			status_header( 400 );
			die( 'Invalid document ID.' );
		}

		// Layer 4: Document must exist and be visible to this user.
		// v1.5.0: visibility_sql() now carries the transcript ACL (view mode),
		// so a transcript id resolves ONLY for a party or an active whole-doc
		// sharee — everyone else (admins included) gets the same 404 as a
		// nonexistent id. Silent scoping, no existence signal.
		global $wpdb;
		$vis = $this->visibility_sql( 'd' );
		$doc = $wpdb->get_row( $wpdb->prepare(
			"SELECT d.file_url, d.original_name, d.mime_type
			 FROM {$wpdb->prefix}zkv_documents d
			 WHERE d.id = %d {$vis}",
			$doc_id
		), ARRAY_A );

		if ( ! $doc ) {
			status_header( 404 );
			die( 'Document not found.' );
		}

		$file_path = $doc['file_url']; // This is a filesystem path, not a URL.
		if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
			status_header( 404 );
			die( 'File not found on server.' );
		}

		// Layer 5: Prevent path traversal — file must be inside vault dir OR WP uploads dir.
		$real_path = realpath( $file_path );
		$vault_dir = defined( 'ZKV_VAULT_DIR' ) ? realpath( ZKV_VAULT_DIR ) : false;
		$uploads = wp_upload_dir();
		$uploads_dir = realpath( $uploads['basedir'] );
		$in_vault   = ( $real_path && $vault_dir && strpos( $real_path, $vault_dir ) === 0 );
		$in_uploads = ( $real_path && $uploads_dir && strpos( $real_path, $uploads_dir ) === 0 );
		if ( ! $real_path || ( ! $in_vault && ! $in_uploads ) ) {
			status_header( 403 );
			die( 'Access denied.' );
		}

		// Serve the file securely.
		$mime = $doc['mime_type'] ?: 'application/octet-stream';
		$name = $doc['original_name'] ?: basename( $file_path );

		// Prevent caching by proxies/CDN.
		header( 'Cache-Control: private, no-cache, no-store, must-revalidate' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );
		header( 'X-Robots-Tag: noindex, nofollow' );

		// Content headers (Content-Type + nosniff + safe Content-Disposition, shared helper).
		zkv_serve_file_headers( $mime, $name );
		header( 'Content-Length: ' . filesize( $real_path ) );

		// Stream the file and exit.
		readfile( $real_path );
		exit;
	}

	// ──────────────────────────────────────────────────────────────
	//  SECURITY HELPER: Rewrite file paths to proxy URLs
	//
	//  Converts private filesystem paths stored in file_url to
	//  authenticated download proxy URLs before sending to frontend.
	//  This ensures NO private server paths ever reach the browser.
	// ──────────────────────────────────────────────────────────────

	private function secure_file_url( $doc_id ) {
		return admin_url( 'admin-ajax.php' ) . '?action=zkv_download&id=' . (int) $doc_id . '&nonce=' . wp_create_nonce( ZKV_NONCE );
	}

	/**
	 * Rewrite file_url in a document row before sending to frontend.
	 * Strips the private filesystem path, replaces with proxy URL.
	 */
	private function sanitize_for_frontend( $row ) {
		if ( ! is_array( $row ) ) return $row;
		$id   = $row['document_id'] ?? $row['id'] ?? 0;
		$slug = $row['slug'] ?? '';
		if ( $id > 0 ) {
			$row['file_url'] = zkv_secure_url( (int) $id, $slug );
		} else {
			$row['file_url'] = '';
		}
		return $row;
	}

	// ──────────────────────────────────────────────────────────────
	//  v1.3.3: Browser-side PDF chunk extraction
	//  When WP Engine (or any host) blocks exec(), the browser extracts
	//  text from PDFs via PDF.js and sends it here for chunk storage.
	// ──────────────────────────────────────────────────────────────

	/**
	 * Return chunk count for a document — widget uses this to decide
	 * whether browser extraction is needed.
	 */
	public function ajax_check_chunks() {
		check_ajax_referer( ZKV_NONCE, 'nonce' );
		if ( ! $this->check_access() ) { wp_send_json_error( 'Access denied.' ); }
		$doc_id = (int) ( $_POST['document_id'] ?? 0 );
		if ( $doc_id < 1 ) { wp_send_json_error( 'Invalid ID.' ); }

		// v1.5.0: scoped — an unscoped chunk_count/mime probe would confirm a
		// hidden document's existence by id. Invisible ids read as 0 chunks /
		// no mime, identical to a nonexistent id.
		global $wpdb;
		$vis  = $this->visibility_sql( 'd' );
		$mime = $wpdb->get_var( $wpdb->prepare(
			"SELECT d.mime_type FROM {$wpdb->prefix}zkv_documents d WHERE d.id = %d {$vis}",
			$doc_id
		) );
		$count = 0;
		if ( null !== $mime ) {
			$count = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}zkv_chunks WHERE document_id = %d",
				$doc_id
			) );
		}
		wp_send_json_success( array(
			'chunk_count' => $count,
			'mime_type'   => $mime,
			'needs_browser_extract' => ( $count === 0 && $mime === 'application/pdf' ),
		) );
	}

	/**
	 * Receive browser-extracted PDF text and create content chunks.
	 * Called by the widget JS after PDF.js extracts text client-side.
	 */
	public function ajax_browser_chunks() {
		check_ajax_referer( ZKV_NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Admin only.' ); }

		$doc_id = (int) ( $_POST['document_id'] ?? 0 );
		$text   = wp_unslash( $_POST['extracted_text'] ?? '' );

		if ( $doc_id < 1 ) { wp_send_json_error( 'Invalid document ID.' ); }
		if ( strlen( $text ) < 100 ) { wp_send_json_error( 'Extracted text too short (' . strlen( $text ) . ' chars).' ); }

		// Verify document exists
		global $wpdb;
		$doc = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}zkv_documents WHERE id = %d",
			$doc_id
		), ARRAY_A );
		if ( ! $doc ) { wp_send_json_error( 'Document not found.' ); }

		if ( ! class_exists( 'ZKV_Indexer' ) ) { wp_send_json_error( 'Indexer not loaded.' ); }

		// Store chunks from the browser-extracted text
		$count = ZKV_Indexer::store_content_chunks( $doc_id, $text, $doc );

		error_log( 'ZKV v1.3.3: Browser extraction for doc ' . $doc_id . ' — '
			. strlen( $text ) . ' chars received, ' . $count . ' chunks created.' );

		if ( $count > 0 ) {
			wp_send_json_success( array(
				'message'     => $count . ' chunks created from browser-extracted text.',
				'chunk_count' => $count,
				'text_length' => strlen( $text ),
			) );
		} else {
			wp_send_json_error( 'Chunk creation failed — text may be empty or corrupt.' );
		}
	}

	// ══════════════════════════════════════════════════════════════
	//  v1.5.0 — PRIVATE TRANSCRIPTS: admin queue
	//
	//  The queue is the ONLY admin surface for transcripts, and it is
	//  deliberately body-free: an admin sees filenames, uploaders, dates,
	//  speaker labels and (on request, logged) ±1 line of context per
	//  label — never the transcript body, synopsis, or chunks. Binding a
	//  speaker grants the BOUND person; it never grants the admin a read.
	// ══════════════════════════════════════════════════════════════

	/** Gate: transcripts queue is manage_options (same as KV settings). */
	private function require_transcript_admin() {
		check_ajax_referer( ZKV_NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Admin only.' );
		}
		if ( ! class_exists( 'ZKV_ACL' ) || ! class_exists( 'ZKV_Transcript' ) ) {
			wp_send_json_error( 'Transcript module not loaded.' );
		}
	}

	/**
	 * Queue listing: suggestions awaiting confirmation + latent transcripts
	 * awaiting a party bind (+ stuck 'detected' ones, so nothing hides).
	 * METADATA ONLY. For privatized docs the AI title is withheld (it was
	 * derived from the content) — the filename identifies the item.
	 */
	public function ajax_transcript_queue() {
		$this->require_transcript_admin();
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT d.id, d.title, d.original_name, d.source_type, d.visibility,
			        d.transcript_status, d.uploaded_by, d.created_at
			 FROM {$wpdb->prefix}zkv_documents d
			 WHERE d.transcript_status IN ('suggested','latent','detected')
			 ORDER BY d.created_at DESC LIMIT 100",
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $r ) {
			$is_private = ZKV_ACL::is_transcript_visibility( $r['visibility'] );
			$u = get_userdata( (int) $r['uploaded_by'] );
			$item = array(
				'id'            => (int) $r['id'],
				'status'        => $r['transcript_status'],
				'original_name' => $r['original_name'],
				// Title only while the doc is still a NORMAL visible document
				// (suggested) — a privatized doc's AI title derives from its
				// content and stays out of the admin's view.
				'title'         => $is_private ? '' : $r['title'],
				'uploader'      => $u ? $u->display_name : 'Unknown',
				'source_type'   => $r['source_type'],
				'created_at'    => $r['created_at'],
				'is_private'    => $is_private,
			);
			if ( $is_private ) {
				$item['parties'] = count( ZKV_ACL::party_user_ids( (int) $r['id'] ) );
				$unmatched = ZKV_Transcript::unmatched_labels( (int) $r['id'] );
				$item['unmatched_labels'] = array_keys( $unmatched );
			}
			$out[] = $item;
		}
		wp_send_json_success( array( 'items' => $out ) );
	}

	/** Confirm a SUGGESTION → privatize + auto-resolve parties. */
	public function ajax_transcript_confirm() {
		$this->require_transcript_admin();
		$doc_id = (int) ( $_POST['document_id'] ?? 0 );
		if ( $doc_id < 1 ) { wp_send_json_error( 'Invalid document ID.' ); }

		global $wpdb;
		$status = $wpdb->get_var( $wpdb->prepare(
			"SELECT transcript_status FROM {$wpdb->prefix}zkv_documents WHERE id = %d", $doc_id
		) );
		if ( 'suggested' !== (string) $status ) {
			wp_send_json_error( 'Only suggested documents can be confirmed.' );
		}

		$res = ZKV_Transcript::confirm_transcript( $doc_id, get_current_user_id() );
		if ( empty( $res['ok'] ) ) {
			wp_send_json_error( $res['error'] ?? 'Confirm failed.' );
		}
		wp_send_json_success( array(
			'status'    => $res['status'],
			'parties'   => $res['parties'],
			'unmatched' => $res['unmatched'],
			'message'   => 'active' === $res['status']
				? 'Now private — live for its ' . $res['parties'] . ' matched ' . ( 1 === (int) $res['parties'] ? 'party' : 'parties' ) . '.'
				: 'Now private — no speakers matched; it is latent until you bind the right people.',
		) );
	}

	/** Not a transcript: dismiss a suggestion / revert a latent doc to normal. */
	public function ajax_transcript_reject() {
		$this->require_transcript_admin();
		$doc_id = (int) ( $_POST['document_id'] ?? 0 );
		if ( $doc_id < 1 ) { wp_send_json_error( 'Invalid document ID.' ); }

		global $wpdb;
		$status = $wpdb->get_var( $wpdb->prepare(
			"SELECT transcript_status FROM {$wpdb->prefix}zkv_documents WHERE id = %d", $doc_id
		) );
		// An ACTIVE transcript is someone's private conversation — reverting
		// it to a public doc is not a queue action (remove the parties first
		// if that is truly intended; every removal is audit-logged).
		if ( ! in_array( (string) $status, array( 'suggested', 'latent', 'detected' ), true ) ) {
			wp_send_json_error( 'Only suggested or latent items can be marked not-a-transcript.' );
		}
		ZKV_Transcript::mark_not_transcript( $doc_id, get_current_user_id() );
		wp_send_json_success( array( 'message' => 'Marked as not a transcript.' ) );
	}

	/** Late-join bind: speaker label → confirmed WP user (match_method=manual). */
	public function ajax_transcript_bind() {
		$this->require_transcript_admin();
		$doc_id  = (int) ( $_POST['document_id'] ?? 0 );
		$user_id = (int) ( $_POST['user_id'] ?? 0 );
		$label   = sanitize_text_field( $_POST['label'] ?? '' );
		if ( $doc_id < 1 || $user_id < 1 ) { wp_send_json_error( 'Invalid parameters.' ); }

		$res = ZKV_Transcript::bind_party( $doc_id, $label, $user_id, get_current_user_id() );
		if ( empty( $res['ok'] ) ) {
			wp_send_json_error( $res['error'] ?? 'Bind failed.' );
		}
		wp_send_json_success( array(
			'parties' => count( ZKV_ACL::party_user_ids( $doc_id ) ),
			'message' => 'Bound. The transcript is now live for that person.',
		) );
	}

	/** Remove a mis-resolved party. 0 parties left → back to latent. */
	public function ajax_transcript_unbind() {
		$this->require_transcript_admin();
		$doc_id  = (int) ( $_POST['document_id'] ?? 0 );
		$user_id = (int) ( $_POST['user_id'] ?? 0 );
		if ( $doc_id < 1 || $user_id < 1 ) { wp_send_json_error( 'Invalid parameters.' ); }
		ZKV_Transcript::remove_party( $doc_id, $user_id, get_current_user_id() );
		wp_send_json_success( array( 'parties' => count( ZKV_ACL::party_user_ids( $doc_id ) ) ) );
	}

	/** ±1-line context for one unmatched label (narrow, logged disclosure). */
	public function ajax_transcript_context() {
		$this->require_transcript_admin();
		$doc_id = (int) ( $_POST['document_id'] ?? 0 );
		$label  = sanitize_text_field( $_POST['label'] ?? '' );
		if ( $doc_id < 1 || '' === $label ) { wp_send_json_error( 'Invalid parameters.' ); }
		wp_send_json_success( array(
			'snippets' => ZKV_Transcript::context_snippets( $doc_id, $label, get_current_user_id() ),
		) );
	}

	/**
	 * Staff roster for the bind dropdown + share picker. Vault users only;
	 * kiosk (zdz_general) excluded — it can never be a party or a recipient.
	 */
	public function ajax_vault_users() {
		check_ajax_referer( ZKV_NONCE, 'nonce' );
		if ( ! $this->check_access() ) { wp_send_json_error( 'Access denied.' ); }
		// v1.5.2 (KV1): every active user WITH AN EMAIL is a selectable participant /
		// share target — the picker must NOT depend on the vault app grant (that
		// dependency is what made a real user like Ron un-selectable). Only the shared
		// kiosk account (zdz_general) is excluded; it can never be a party or a
		// recipient of private content.
		$out = array();
		foreach ( get_users( array( 'fields' => 'all' ) ) as $user ) {
			if ( in_array( 'zdz_general', (array) $user->roles, true ) ) { continue; }
			if ( empty( $user->user_email ) ) { continue; }
			$out[] = array( 'id' => (int) $user->ID, 'name' => $user->display_name );
		}
		usort( $out, function ( $a, $b ) { return strcasecmp( $a['name'], $b['name'] ); } );
		wp_send_json_success( array( 'users' => $out ) );
	}

	// ══════════════════════════════════════════════════════════════
	//  v1.5.2 (KV2) — uploader confirms who may see a private transcript
	// ══════════════════════════════════════════════════════════════

	/** True if the caller uploaded this document, or is an admin. */
	private function is_uploader_or_admin( $doc_id ) {
		if ( current_user_can( 'manage_options' ) ) { return true; }
		global $wpdb;
		$up = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT uploaded_by FROM {$wpdb->prefix}zkv_documents WHERE id = %d", (int) $doc_id ) );
		return $up > 0 && $up === get_current_user_id();
	}

	/**
	 * AJAX: the parties a pending transcript's speakers resolve to, for the uploader's
	 * confirmation prompt. Uploader (or admin) only; the transcript body is never
	 * exposed here — only resolved names + unmatched speaker labels.
	 */
	public function ajax_transcript_detected() {
		check_ajax_referer( ZKV_NONCE, 'nonce' );
		if ( ! $this->check_access() ) { wp_send_json_error( 'Access denied.' ); }
		if ( ! class_exists( 'ZKV_Transcript' ) ) { wp_send_json_error( 'Not found.' ); }
		$doc_id = (int) ( $_POST['document_id'] ?? 0 );
		if ( $doc_id < 1 || ! $this->is_uploader_or_admin( $doc_id ) ) { wp_send_json_error( 'Not found.' ); }
		wp_send_json_success( ZKV_Transcript::detected_parties( $doc_id ) );
	}

	/**
	 * AJAX: the uploader confirms which detected parties may see the transcript. Grants
	 * exactly the confirmed subset (validated against the detected speakers) and
	 * activates it. Uploader (or admin) only. Idempotent guard on 'pending_confirm'.
	 */
	public function ajax_transcript_confirm_parties() {
		check_ajax_referer( ZKV_NONCE, 'nonce' );
		if ( ! $this->check_access() ) { wp_send_json_error( 'Access denied.' ); }
		if ( ! class_exists( 'ZKV_Transcript' ) ) { wp_send_json_error( 'Not found.' ); }
		global $wpdb;
		$doc_id = (int) ( $_POST['document_id'] ?? 0 );
		if ( $doc_id < 1 || ! $this->is_uploader_or_admin( $doc_id ) ) { wp_send_json_error( 'Not found.' ); }

		$status = (string) $wpdb->get_var( $wpdb->prepare(
			"SELECT transcript_status FROM {$wpdb->prefix}zkv_documents WHERE id = %d", $doc_id ) );
		if ( 'pending_confirm' !== $status ) { wp_send_json_error( 'This transcript has already been confirmed.' ); }

		$ids = json_decode( wp_unslash( $_POST['user_ids'] ?? '[]' ), true );
		if ( ! is_array( $ids ) ) { $ids = array(); }
		$res = ZKV_Transcript::confirm_parties( $doc_id, $ids, get_current_user_id() );
		if ( empty( $res['ok'] ) ) { wp_send_json_error( $res['error'] ?? 'Confirm failed.' ); }
		$granted = (int) ( $res['granted'] ?? 0 );
		wp_send_json_success( array(
			'granted' => $granted,
			'parties' => (int) ( $res['parties'] ?? 0 ),
			'message' => $granted > 0
				? 'Confirmed — ' . $granted . ' ' . ( 1 === $granted ? 'person' : 'people' ) . ' can now see this transcript.'
				: 'Confirmed — this transcript stays private to you for now.',
		) );
	}

	// ══════════════════════════════════════════════════════════════
	//  v1.5.0 — PRIVATE TRANSCRIPTS: party-initiated sharing
	// ══════════════════════════════════════════════════════════════

	/**
	 * Numbered line rendition — PARTY ONLY (the excerpt author view).
	 * A whole-doc sharee reads the file through the serve gate; they never
	 * get the line tooling, because they cannot share onward.
	 */
	public function ajax_transcript_lines() {
		check_ajax_referer( ZKV_NONCE, 'nonce' );
		if ( ! $this->check_access() ) { wp_send_json_error( 'Access denied.' ); }
		if ( ! class_exists( 'ZKV_ACL' ) || ! class_exists( 'ZKV_Transcript' ) ) {
			wp_send_json_error( 'Not found.' );
		}
		$doc_id = (int) ( $_POST['document_id'] ?? 0 );
		$uid    = get_current_user_id();
		// Silent scoping: non-parties (admins included) learn nothing.
		if ( $doc_id < 1 || ! ZKV_ACL::is_party( $uid, $doc_id ) ) {
			wp_send_json_error( 'Not found.' );
		}
		wp_send_json_success( array( 'lines' => ZKV_Transcript::lines( $doc_id ) ) );
	}

	/**
	 * Create a share. PARTY ONLY, server-verified — this check is what makes
	 * re-share structurally impossible (a recipient is not a party) and keeps
	 * non-party admins from lending what isn't theirs.
	 */
	public function ajax_share_create() {
		check_ajax_referer( ZKV_NONCE, 'nonce' );
		if ( ! $this->check_access() ) { wp_send_json_error( 'Access denied.' ); }
		if ( ! class_exists( 'ZKV_ACL' ) || ! class_exists( 'ZKV_Transcript' ) ) {
			wp_send_json_error( 'Not found.' );
		}
		global $wpdb;

		$doc_id  = (int) ( $_POST['document_id'] ?? 0 );
		$with_id = (int) ( $_POST['with_user_id'] ?? 0 );
		$scope   = ( 'excerpt' === ( $_POST['scope'] ?? '' ) ) ? 'excerpt' : 'whole';
		$mode    = ( 'redact' === ( $_POST['mode'] ?? '' ) ) ? 'redact' : 'select';
		$days    = max( 0, min( 365, (int) ( $_POST['expires_days'] ?? 0 ) ) );
		$uid     = get_current_user_id();

		// The load-bearing check: only a PARTY may share. (Silent for non-parties.)
		if ( $doc_id < 1 || ! ZKV_ACL::is_party( $uid, $doc_id ) ) {
			wp_send_json_error( 'Not found.' );
		}
		$recipient = get_userdata( $with_id );
		if ( ! $recipient ) { wp_send_json_error( 'Choose a person to share with.' ); }
		if ( $with_id === $uid ) { wp_send_json_error( 'That is you.' ); }
		if ( in_array( 'zdz_general', (array) $recipient->roles, true ) ) {
			wp_send_json_error( 'The shared kiosk account cannot receive private content.' );
		}
		if ( ZKV_ACL::is_party( $with_id, $doc_id ) ) {
			wp_send_json_error( 'That person is already a party — they have full access.' );
		}
		// v1.5.2 (KV1): a recipient no longer needs the coarse knowledge-vault app
		// grant. Sharing now gives SCOPED access — they can open only this transcript
		// (or its excerpt) via the serve gate and the "/vault/shared" page, never the
		// general vault. (This refusal is exactly why a transcript could be "shared"
		// with someone like Ron who then couldn't open it.) The shared kiosk account
		// is still refused above; parties/self are handled above too.

		$expires_at = $days > 0
			? date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + $days * DAY_IN_SECONDS )
			: null;

		$excerpt_text = null;
		$span_map     = null;
		if ( 'excerpt' === $scope ) {
			$ranges_raw = json_decode( wp_unslash( $_POST['ranges'] ?? '[]' ), true );
			if ( ! is_array( $ranges_raw ) || empty( $ranges_raw ) ) {
				wp_send_json_error( 'Pick at least one line range.' );
			}
			$built = ZKV_Transcript::materialize_excerpt( $doc_id, $mode, $ranges_raw );
			if ( is_wp_error( $built ) ) {
				wp_send_json_error( $built->get_error_message() );
			}
			$excerpt_text = $built['text'];
			$span_map     = $built['span_map'];
		}

		$wpdb->insert( $wpdb->prefix . 'zkv_doc_shares', array(
			'document_id'  => $doc_id,
			'shared_by'    => $uid,
			'shared_with'  => $with_id,
			'scope'        => $scope,
			'excerpt_mode' => 'excerpt' === $scope ? $mode : '',
			'excerpt_text' => $excerpt_text,
			'span_map'     => $span_map,
			'expires_at'   => $expires_at,
			'created_at'   => current_time( 'mysql' ),
		) );
		$share_id = (int) $wpdb->insert_id;
		if ( $share_id < 1 ) { wp_send_json_error( 'Share failed.' ); }

		ZKV_ACL::log( 'transcript_shared', $doc_id, $uid,
			'share=' . $share_id . ' with=' . $with_id . ' scope=' . $scope
			. ( $expires_at ? ' expires=' . $expires_at : ' no-expiry' ) );
		if ( 'excerpt' === $scope ) {
			ZKV_ACL::log( 'excerpt_created', $doc_id, $uid,
				'share=' . $share_id . ' mode=' . $mode . ' map=' . substr( (string) $span_map, 0, 300 ) );
		}
		ZKV_ACL::reset_cache();

		// v1.5.2 (KV1): hand the sharer a direct link for a whole-doc share, so a
		// recipient without the vault app can be sent straight to it (they can also
		// find it at /vault/shared). The link resolves to the ACL-gated serve path.
		$whole_url = '';
		if ( 'whole' === $scope ) {
			$slug = $wpdb->get_var( $wpdb->prepare(
				"SELECT slug FROM {$wpdb->prefix}zkv_documents WHERE id = %d", $doc_id ) );
			if ( $slug ) { $whole_url = home_url( '/vault/' . $slug ); }
		}

		wp_send_json_success( array(
			'share_id'    => $share_id,
			'scope'       => $scope,
			'excerpt_url' => 'excerpt' === $scope ? home_url( '/vault/excerpt/' . $share_id ) : '',
			'whole_url'   => $whole_url,
			'shared_page' => home_url( '/vault/shared' ),
			'message'     => 'whole' === $scope
				? 'Shared — ' . $recipient->display_name . ' can now open this transcript (view only, never in their chat). They can find it under “Shared with me.”'
				: 'Excerpt shared with ' . $recipient->display_name . ' — only the selected lines exist in their copy.',
		) );
	}

	/** Revoke a share — any party of the document may revoke any of its shares. */
	public function ajax_share_revoke() {
		check_ajax_referer( ZKV_NONCE, 'nonce' );
		if ( ! $this->check_access() ) { wp_send_json_error( 'Access denied.' ); }
		if ( ! class_exists( 'ZKV_ACL' ) ) { wp_send_json_error( 'Not found.' ); }
		global $wpdb;

		$share_id = (int) ( $_POST['share_id'] ?? 0 );
		$uid      = get_current_user_id();
		$share    = $share_id > 0 ? $wpdb->get_row( $wpdb->prepare(
			"SELECT id, document_id FROM {$wpdb->prefix}zkv_doc_shares WHERE id = %d",
			$share_id
		), ARRAY_A ) : null;

		// Silent unless the caller is a party of the underlying transcript.
		if ( ! $share || ! ZKV_ACL::is_party( $uid, (int) $share['document_id'] ) ) {
			wp_send_json_error( 'Not found.' );
		}

		$wpdb->update( $wpdb->prefix . 'zkv_doc_shares',
			array( 'revoked_at' => current_time( 'mysql' ) ),
			array( 'id' => $share_id ), array( '%s' ), array( '%d' )
		);
		ZKV_ACL::log( 'transcript_share_revoked', (int) $share['document_id'], $uid, 'share=' . $share_id );
		ZKV_ACL::reset_cache();
		wp_send_json_success( array( 'revoked' => true ) );
	}
}

new ZKV_Dashboard();
