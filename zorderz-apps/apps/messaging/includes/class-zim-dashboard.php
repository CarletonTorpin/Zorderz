<?php
/**
 * ZIM_Dashboard
 *
 * AJAX router. Every request this plugin receives from the browser lands
 * here or in an admin-post handler.
 *
 * CONTRACT FOR EVERY HANDLER:
 *   1. verify nonce (ZIM_NONCE)
 *   2. verify user is logged in and has zdz_access_app
 *   3. verify customer-facing mode is NOT active (hard-block from AJAX too)
 *   4. verify conversation membership when applicable
 *   5. return JSON only (wp_send_json_success / wp_send_json_error)
 *
 * POLLING ENDPOINT SHAPE (required pattern, see MEP prompt):
 *   - `zim_poll` takes ( conversation_id, since )
 *   - Returns up to 50 messages with id > since
 *   - Frontend keeps ≤ 2 in-flight requests per tab (one per open
 *     conversation + one sidebar tick). Backoff on error.
 *
 * NO-CACHE CONTRACT (acceptance #14):
 *   We never write message bodies into transients or wp_cache_*. Message
 *   data flows Messages table → fetch_since() → JSON → client. The only
 *   caches this plugin writes are:
 *     - ZIM_Widget's static `should_render()` (per-request)
 *     - ZIM_Preview_Cards' `zim_fb_preview_*` transients (metadata only)
 *     - ZIM_Search's static FT-availability probe (per-request)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIM_Dashboard {

	public function __construct() {
		$handlers = array(
			'zim_poll'                => 'ajax_poll',
			'zim_fetch_before'        => 'ajax_fetch_before',
			'zim_sidebar'             => 'ajax_sidebar',
			'zim_post'                => 'ajax_post',
			'zim_edit'                => 'ajax_edit',
			'zim_delete'              => 'ajax_delete',
			'zim_bulk_delete'         => 'ajax_bulk_delete',
			'zim_search'              => 'ajax_search',
			'zim_upload'              => 'ajax_upload',
			'zim_push_subscribe'      => 'ajax_push_subscribe',
			'zim_push_unsubscribe'    => 'ajax_push_unsubscribe',
			'zim_channel_create'      => 'ajax_channel_create',
			'zim_member_add'          => 'ajax_member_add',
			'zim_preview_ref'         => 'ajax_preview_ref',
			'zim_preview_vault'       => 'ajax_preview_vault', // v1.0.20: Knowledge Vault doc previews
			'zim_set_quiet_hours'     => 'ajax_set_quiet_hours',
			'zim_dm_open'             => 'ajax_dm_open',
			'zim_autocomplete_users'  => 'ajax_autocomplete_users',
			'zim_user_search'         => 'ajax_user_search',
			'zim_mark_read'           => 'ajax_mark_read',
		);
		foreach ( $handlers as $action => $method ) {
			add_action( 'wp_ajax_' . $action, array( $this, $method ) );
		}
	}

	// ─────────────────────────────────────────────────────────────
	// Gates
	// ─────────────────────────────────────────────────────────────

	/**
	 * Shared front-door check. Exits with JSON error on failure.
	 *
	 * IMPORTANT: check_ajax_referer's default behavior on nonce fail is to
	 * call wp_die('-1', 403) — a plain-text response, not JSON. That makes
	 * client-side diagnostics impossible ("403 with no body"). We pass
	 * false as the third arg to suppress the auto-die and handle it
	 * ourselves, returning a distinguishable JSON error so callers (and
	 * especially cross-plugin callers like the analytics app's team-embed) can tell
	 * "bad nonce" apart from "missing capability" apart from
	 * "customer-facing mode."
	 */
	private function gate() {
		$nonce_ok = check_ajax_referer( ZIM_NONCE, 'nonce', false );
		if ( ! $nonce_ok ) {
			wp_send_json_error( array(
				'message' => 'Invalid or expired nonce.',
				'code'    => 'zim_bad_nonce',
			), 403 );
		}
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array(
				'message' => 'Not logged in.',
				'code'    => 'zim_not_logged_in',
			), 403 );
		}
		// Dual capability check — matches TSA v1.9.27+'s pattern:
		// WordPress admin (manage_options) OR Zorderz custom cap (zdz_access_app).
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'zdz_access_app' ) ) {
			wp_send_json_error( array(
				'message' => 'User lacks manage_options or zdz_access_app capability.',
				'code'    => 'zim_no_capability',
			), 403 );
		}
		if ( ! ZIM_Widget::should_render() ) {
			wp_send_json_error( array(
				'message' => 'Not available.',
				'code'    => 'zim_unavailable',
			), 404 );
		}
	}

	private function gate_admin() {
		$this->gate();
		// v1.0.24 — read-only roles (kiosk) are never admins; refuse explicitly
		// so channel-create / member-add can't be driven by the shared account.
		$this->deny_if_read_only();
		if ( ! current_user_can( 'manage_options' ) ) {
			$user  = wp_get_current_user();
			$roles = (array) ( $user->roles ?? array() );
			if ( ! array_intersect( array( 'zdz_owner', 'zdz_admin' ), $roles ) ) {
				wp_send_json_error( array( 'message' => 'Admin required.' ), 403 );
			}
		}
	}

	/**
	 * v1.0.24 — Front door for *write* endpoints. Runs the normal gate(), then
	 * refuses read-only roles (the shared kiosk `zdz_general`). Every mutation
	 * handler (post, edit, delete, bulk-delete, upload, dm-open) calls this
	 * instead of gate(). The refusal is deterministic and server-side; the
	 * client also hides the composer, but that is courtesy UX, not the
	 * guarantee. See zim_user_can_write().
	 */
	private function gate_write() {
		$this->gate();
		$this->deny_if_read_only();
	}

	/**
	 * Emit a 403 JSON error and exit if the current user has read-only
	 * messaging access. Shared by gate_write() and gate_admin().
	 */
	private function deny_if_read_only() {
		if ( function_exists( 'zim_user_can_write' ) && ! zim_user_can_write() ) {
			wp_send_json_error( array(
				'message' => 'This account has read-only messaging access.',
				'code'    => 'zim_read_only',
			), 403 );
		}
	}

	/**
	 * Verifies membership. Returns the validated conversation_id, or exits
	 * with 403. Keeps the param order consistent with ZIM_Membership:
	 * is_member( $user_id, $conversation_id ).
	 */
	private function require_member( $conversation_id ) {
		$conversation_id = (int) $conversation_id;
		if ( $conversation_id <= 0 ) {
			wp_send_json_error( array( 'message' => 'Bad conversation.' ), 400 );
		}
		if ( ! ZIM_Membership::is_member( get_current_user_id(), $conversation_id ) ) {
			wp_send_json_error( array( 'message' => 'Not a member of this conversation.' ), 403 );
		}
		return $conversation_id;
	}

	// ─────────────────────────────────────────────────────────────
	// Poll — the hot path
	// ─────────────────────────────────────────────────────────────

	public function ajax_poll() {
		$this->gate();

		$conversation_id = $this->require_member(
			isset( $_GET['conversation_id'] ) ? absint( $_GET['conversation_id'] ) : 0
		);
		$since = isset( $_GET['since'] ) ? absint( $_GET['since'] ) : 0;

		$messages = ZIM_Messages::fetch_since( $conversation_id, $since, 50 );
		$latest   = ! empty( $messages ) ? (int) end( $messages )['id'] : 0;

		wp_send_json_success( array(
			'messages'    => $messages,
			'latest_id'   => $latest,
			'server_time' => gmdate( 'c' ),
		) );
	}

	/**
	 * Load older history on scroll-up.
	 */
	public function ajax_fetch_before() {
		$this->gate();

		$conversation_id = $this->require_member(
			isset( $_GET['conversation_id'] ) ? absint( $_GET['conversation_id'] ) : 0
		);
		$before = isset( $_GET['before'] ) ? absint( $_GET['before'] ) : 0;

		$messages = ZIM_Messages::fetch_before( $conversation_id, $before, 50 );
		wp_send_json_success( array(
			'messages'  => $messages,
			'oldest_id' => $messages ? (int) $messages[0]['id'] : 0,
			'has_more'  => count( $messages ) >= 50,
		) );
	}

	/**
	 * Sidebar poll — channels + DMs with unread counts.
	 */
	public function ajax_sidebar() {
		$this->gate();
		$user_id = get_current_user_id();
		wp_send_json_success( array(
			'channels'    => ZIM_Channels::list_for_user( $user_id ),
			'dms'         => ZIM_DMs::list_for_user( $user_id ),
			'server_time' => gmdate( 'c' ),
		) );
	}

	// ─────────────────────────────────────────────────────────────
	// Message mutations
	// ─────────────────────────────────────────────────────────────

	public function ajax_post() {
		$this->gate_write();

		$conversation_id = $this->require_member(
			isset( $_POST['conversation_id'] ) ? absint( $_POST['conversation_id'] ) : 0
		);
		$body           = (string) ( $_POST['body'] ?? '' );
		$attachment_ids = isset( $_POST['attachment_ids'] )
			? array_map( 'absint', (array) $_POST['attachment_ids'] )
			: array();

		$result = ZIM_Messages::post(
			$conversation_id,
			get_current_user_id(),
			$body,
			$attachment_ids
		);
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array(
				'message' => $result->get_error_message(),
				'code'    => $result->get_error_code(),
			), 400 );
		}

		$fresh = ZIM_Messages::fetch_since( $conversation_id, (int) $result['message_id'] - 1, 1 );
		wp_send_json_success( array(
			'message_id' => (int) $result['message_id'],
			'messages'   => $fresh,
		) );
	}

	public function ajax_edit() {
		$this->gate_write();
		$message_id = isset( $_POST['message_id'] ) ? absint( $_POST['message_id'] ) : 0;
		$body       = (string) ( $_POST['body'] ?? '' );

		$result = ZIM_Messages::edit( $message_id, get_current_user_id(), $body );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array(
				'message' => $result->get_error_message(),
				'code'    => $result->get_error_code(),
			), 400 );
		}
		wp_send_json_success( array( 'message_id' => (int) $result['message_id'] ) );
	}

	public function ajax_delete() {
		$this->gate_write();
		$message_id = isset( $_POST['message_id'] ) ? absint( $_POST['message_id'] ) : 0;

		$result = ZIM_Messages::soft_delete( $message_id, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array(
				'message' => $result->get_error_message(),
				'code'    => $result->get_error_code(),
			), 400 );
		}
		wp_send_json_success( array( 'message_id' => (int) $message_id, 'deleted' => true ) );
	}

	/**
	 * v1.0.21: Bulk-delete multiple messages in one request.
	 * Accepts a comma-separated list of message IDs.
	 */
	public function ajax_bulk_delete() {
		$this->gate_write();
		$raw_ids = isset( $_POST['message_ids'] ) ? sanitize_text_field( $_POST['message_ids'] ) : '';
		if ( empty( $raw_ids ) ) {
			wp_send_json_error( array( 'message' => 'No message IDs provided.' ), 400 );
		}

		$ids = array_filter( array_map( 'absint', explode( ',', $raw_ids ) ) );
		if ( empty( $ids ) || count( $ids ) > 50 ) {
			wp_send_json_error( array( 'message' => 'Invalid message count (1-50).' ), 400 );
		}

		$user_id = get_current_user_id();
		$deleted = array();
		$errors  = array();

		foreach ( $ids as $mid ) {
			$result = ZIM_Messages::soft_delete( $mid, $user_id );
			if ( is_wp_error( $result ) ) {
				$errors[] = $mid;
			} else {
				$deleted[] = $mid;
			}
		}

		wp_send_json_success( array(
			'deleted' => $deleted,
			'errors'  => $errors,
			'count'   => count( $deleted ),
		) );
	}

	public function ajax_mark_read() {
		$this->gate();
		$conversation_id = $this->require_member(
			isset( $_POST['conversation_id'] ) ? absint( $_POST['conversation_id'] ) : 0
		);
		$message_id = isset( $_POST['message_id'] ) ? absint( $_POST['message_id'] ) : 0;
		ZIM_Channels::mark_read( $conversation_id, get_current_user_id(), $message_id );
		wp_send_json_success();
	}

	// ─────────────────────────────────────────────────────────────
	// Search
	// ─────────────────────────────────────────────────────────────

	public function ajax_search() {
		$this->gate();
		$conversation_id = $this->require_member(
			isset( $_GET['conversation_id'] ) ? absint( $_GET['conversation_id'] ) : 0
		);
		$query = (string) ( $_GET['q'] ?? '' );

		$hits = ZIM_Search::search( $conversation_id, $query, get_current_user_id(), 50 );
		wp_send_json_success( array( 'hits' => $hits, 'count' => count( $hits ) ) );
	}

	// ─────────────────────────────────────────────────────────────
	// Attachments
	// ─────────────────────────────────────────────────────────────

	public function ajax_upload() {
		$this->gate_write();
		$result = ZIM_Attachments::handle_upload( 'attachment', get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array(
				'message' => $result->get_error_message(),
				'code'    => $result->get_error_code(),
			), 400 );
		}
		wp_send_json_success( $result );
	}

	// ─────────────────────────────────────────────────────────────
	// Push subscriptions
	// ─────────────────────────────────────────────────────────────

	public function ajax_push_subscribe() {
		$this->gate();
		$endpoint = isset( $_POST['endpoint'] ) ? esc_url_raw( wp_unslash( $_POST['endpoint'] ) ) : '';
		$p256dh   = isset( $_POST['p256dh'] )   ? sanitize_text_field( wp_unslash( $_POST['p256dh'] ) ) : '';
		$auth     = isset( $_POST['auth'] )     ? sanitize_text_field( wp_unslash( $_POST['auth'] ) )   : '';
		$ua       = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';

		$id = ZIM_Push::subscribe( get_current_user_id(), $endpoint, $p256dh, $auth, $ua );
		if ( is_wp_error( $id ) ) {
			wp_send_json_error( array( 'message' => $id->get_error_message() ), 400 );
		}
		wp_send_json_success( array( 'subscription_id' => (int) $id ) );
	}

	public function ajax_push_unsubscribe() {
		$this->gate();
		$endpoint = isset( $_POST['endpoint'] ) ? esc_url_raw( wp_unslash( $_POST['endpoint'] ) ) : '';
		if ( '' !== $endpoint ) {
			ZIM_Push::unsubscribe_by_endpoint( get_current_user_id(), $endpoint );
		}
		wp_send_json_success();
	}

	// ─────────────────────────────────────────────────────────────
	// Channel + membership (admin)
	// ─────────────────────────────────────────────────────────────

	public function ajax_channel_create() {
		$this->gate_admin();

		$slug        = sanitize_title( wp_unslash( $_POST['slug'] ?? '' ) );
		$name        = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$description = sanitize_text_field( wp_unslash( $_POST['description'] ?? '' ) );
		$is_private  = ! empty( $_POST['is_private'] );
		$invite_ids  = isset( $_POST['invite_user_ids'] )
			? array_map( 'absint', (array) $_POST['invite_user_ids'] )
			: array();

		if ( '' === $slug ) {
			wp_send_json_error( array( 'message' => 'Slug required.' ), 400 );
		}

		$id = ZIM_Channels::create( array(
			'slug'        => $slug,
			'name'        => '' !== $name ? $name : ( '#' . $slug ),
			'description' => $description,
			'is_private'  => $is_private ? 1 : 0,
			'created_by'  => get_current_user_id(),
		) );
		if ( $id <= 0 ) {
			wp_send_json_error( array( 'message' => 'Channel could not be created (duplicate slug?).' ), 400 );
		}

		foreach ( $invite_ids as $uid ) {
			if ( $uid > 0 && zim_user_has_access( $uid ) ) {
				ZIM_Channels::add_member( $id, $uid, 'member' );
			}
		}

		wp_send_json_success( array( 'conversation_id' => (int) $id ) );
	}

	public function ajax_member_add() {
		$this->gate_admin();

		$conv_id = isset( $_POST['conversation_id'] ) ? absint( $_POST['conversation_id'] ) : 0;
		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		$role    = ( ( $_POST['role'] ?? 'member' ) === 'admin' ) ? 'admin' : 'member';

		if ( $conv_id <= 0 || $user_id <= 0 ) {
			wp_send_json_error( array( 'message' => 'Missing conversation or user.' ), 400 );
		}
		if ( ! zim_user_has_access( $user_id ) ) {
			wp_send_json_error( array( 'message' => 'User lacks access.' ), 400 );
		}
		// v1.0.27 (hardening): members are added to CHANNELS only. A DM is a fixed
		// 1:1 pair (created with exactly its two members); refusing a DM conversation
		// here stops an admin from injecting a third party into someone's private DM.
		$conv = ZIM_Channels::get( $conv_id );
		if ( ! $conv || 'channel' !== $conv->kind ) {
			wp_send_json_error( array( 'message' => 'Members can only be added to channels.' ), 400 );
		}
		ZIM_Channels::add_member( $conv_id, $user_id, $role );
		wp_send_json_success();
	}

	// ─────────────────────────────────────────────────────────────
	// Preview cards
	// ─────────────────────────────────────────────────────────────

	public function ajax_preview_ref() {
		$this->gate();

		$raw    = isset( $_GET['number'] ) ? (string) $_GET['number'] : '';
		$number = (int) preg_replace( '/\D+/', '', $raw );
		if ( $number <= 0 ) {
			wp_send_json_error( array( 'message' => 'Bad number.' ), 400 );
		}

		$card = ZIM_Preview_Cards::get_card( $number );
		if ( is_wp_error( $card ) ) {
			// Graceful neutral chip so the UI doesn't surface a scary error.
			wp_send_json_success( array(
				'id'            => $number,
				'number'        => (string) $number,
				'kind'          => 'document',
				'customer_name' => '',
				'total'         => null,
				'currency'      => '',
				'status'        => '',
				'url'           => 'https://my.freshbooks.com/#/search?q=' . rawurlencode( (string) $number ),
				'source'        => 'fallback',
				'unavailable'   => true,
			) );
		}
		wp_send_json_success( $card );
	}

	/**
	 * v1.0.20: Knowledge Vault document preview.
	 *
	 * Accepts either `id` (numeric vault doc ID) or `slug` (pretty URL slug).
	 * Delegates to ZIM_Vault_Cards which calls TSKV's REST endpoint via
	 * internal dispatch — same pattern as FreshBooks preview cards.
	 */
	public function ajax_preview_vault() {
		$this->gate();

		$doc_id = 0;

		// Accept numeric ID directly.
		if ( isset( $_GET['id'] ) ) {
			$doc_id = absint( $_GET['id'] );
		}
		// Or resolve a slug to an ID.
		elseif ( isset( $_GET['slug'] ) ) {
			$slug = sanitize_title( wp_unslash( $_GET['slug'] ) );
			if ( class_exists( 'ZIM_Vault_Cards' ) ) {
				$doc_id = ZIM_Vault_Cards::resolve_slug( $slug );
			}
		}

		if ( $doc_id <= 0 ) {
			wp_send_json_success( array(
				'id'            => 0,
				'title'         => 'Document not found',
				'synopsis'      => '',
				'document_type' => '',
				'url'           => '',
				'source'        => 'fallback',
				'unavailable'   => true,
			) );
		}

		if ( ! class_exists( 'ZIM_Vault_Cards' ) ) {
			wp_send_json_success( array(
				'id'            => $doc_id,
				'title'         => 'Vault unavailable',
				'synopsis'      => '',
				'document_type' => '',
				'url'           => '',
				'source'        => 'fallback',
				'unavailable'   => true,
			) );
		}

		$card = ZIM_Vault_Cards::get_card( $doc_id );
		if ( is_wp_error( $card ) ) {
			wp_send_json_success( array(
				'id'            => $doc_id,
				'title'         => 'Document not found',
				'synopsis'      => '',
				'document_type' => '',
				'url'           => '',
				'source'        => 'fallback',
				'unavailable'   => true,
			) );
		}
		wp_send_json_success( $card );
	}

	// ─────────────────────────────────────────────────────────────
	// Settings
	// ─────────────────────────────────────────────────────────────

	public function ajax_set_quiet_hours() {
		$this->gate();
		$start = isset( $_POST['start'] ) ? sanitize_text_field( wp_unslash( $_POST['start'] ) ) : '';
		$end   = isset( $_POST['end'] )   ? sanitize_text_field( wp_unslash( $_POST['end'] ) )   : '';
		$res = ZIM_Notifications::set_quiet_hours( get_current_user_id(), $start, $end );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ), 400 );
		}
		wp_send_json_success();
	}

	// ─────────────────────────────────────────────────────────────
	// DM open (deterministic — acceptance #12)
	// ─────────────────────────────────────────────────────────────

	public function ajax_dm_open() {
		$this->gate_write();
		$other = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		$conv_id = ZIM_DMs::get_or_create_conversation( get_current_user_id(), $other );
		if ( $conv_id <= 0 ) {
			wp_send_json_error( array( 'message' => 'Cannot open DM.' ), 400 );
		}
		wp_send_json_success( array( 'conversation_id' => (int) $conv_id ) );
	}

	// ─────────────────────────────────────────────────────────────
	// User autocomplete (@ picker, DM picker)
	// ─────────────────────────────────────────────────────────────

	public function ajax_autocomplete_users() {
		$this->gate();
		$conv_id = $this->require_member(
			isset( $_GET['conversation_id'] ) ? absint( $_GET['conversation_id'] ) : 0
		);
		$q = (string) ( $_GET['q'] ?? '' );
		wp_send_json_success( array(
			'candidates' => ZIM_Mentions::autocomplete_candidates( $conv_id, $q, 10 ),
		) );
	}

	/**
	 * Global user search for the New DM picker.
	 * Excludes the caller. Results limited to zdz_access_app holders.
	 */
	public function ajax_user_search() {
		$this->gate();
		global $wpdb;

		$q    = trim( (string) ( $_GET['q'] ?? '' ) );
		$like = '%' . $wpdb->esc_like( $q ) . '%';

		// v1.0.23: Removed `ID <> %d` exclusion so the current user appears
		// in their own search results. This lets users initiate a self-DM
		// ("Notes to Self") from the TSIM New DM picker — not only via
		// Brain Bot. The ZIM_DMs::get_or_create_conversation() backend
		// now allows $user_a === $user_b.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT ID, user_login, display_name
			   FROM {$wpdb->users}
			  WHERE ( user_login LIKE %s OR display_name LIKE %s )
			  ORDER BY display_name ASC
			  LIMIT 40",
			$like, $like
		), ARRAY_A );

		$out = array();
		foreach ( $rows as $r ) {
			$uid = (int) $r['ID'];
			if ( ! zim_user_has_access( $uid ) ) {
				continue;
			}
			$out[] = array(
				'user_id' => $uid,
				'login'   => (string) $r['user_login'],
				'name'    => (string) $r['display_name'],
			);
			if ( count( $out ) >= 20 ) {
				break;
			}
		}
		wp_send_json_success( array( 'users' => $out ) );
	}
}
