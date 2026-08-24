<?php
/**
 * ZEST_Dashboard — AJAX controller + shared cores for the Estimates app.
 *
 * Endpoints are nonce-checked and scoped. Row ownership is decided by created_by FIRST
 * (the reliable indexed column), with the provenance ("Submitted by:") initials as a
 * legacy fallback — a rep with no code configured still sees their own work (the "Ron"
 * defect). The assignable roster comes from ZDZ_Party (short code under key `initials`,
 * matched case-insensitively), never a local roster constant. Every estimate leaving the
 * app passes through ZDZ_Doc_Conventions ON OUTPUT. Billing status is read as a SIGNAL via
 * the mappings map, never as a raw provider integer. Nothing is silent — a scope collapse
 * or unresolved mapping is logged.
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZEST_Dashboard {

	/** @var ZEST_Estimate_Engine|null */
	private static $engine = null;

	public static function boot( $engine ): void {
		if ( $engine instanceof ZEST_Estimate_Engine ) {
			self::$engine = $engine;
		}
		$map = array(
			'zest_parse'         => 'ajax_parse',
			'zest_create'        => 'ajax_create',
			'zest_update'        => 'ajax_update',
			'zest_list_open'     => 'ajax_list_open',
			'zest_history'       => 'ajax_history',
			'zest_assignables'   => 'ajax_assignables',
			'zest_lookup'        => 'ajax_lookup',
			'zest_lead_to_stub'  => 'ajax_lead_to_stub',
			'zest_attach_email'  => 'ajax_attach_email',
		);
		foreach ( $map as $action => $method ) {
			add_action( 'wp_ajax_' . $action, array( __CLASS__, $method ) );
		}
	}

	private static function engine(): ZEST_Estimate_Engine {
		if ( ! self::$engine ) {
			self::$engine = new ZEST_Estimate_Engine();
		}
		return self::$engine;
	}

	/* ---- guards ---- */

	private static function guard(): int {
		if ( ! check_ajax_referer( ZEST_NONCE, 'nonce', false ) || ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => 'Not allowed.' ), 403 );
		}
		return get_current_user_id();
	}

	/** Shared-device (kiosk) session test — a third-layer server-side write refusal. */
	private static function is_kiosk_session( int $uid ): bool {
		return class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_kiosk( $uid );
	}

	private static function is_admin_tier( int $uid ): bool {
		if ( user_can( $uid, 'manage_options' ) ) {
			if ( class_exists( 'ZDZ_Data_Permissions' ) && method_exists( 'ZDZ_Data_Permissions', 'can_view_others_data' )
				&& ! ZDZ_Data_Permissions::can_view_others_data( $uid ) ) {
				error_log( sprintf( 'Zorderz Estimates: admin uid %d collapsed to own-scope by data-permission override.', $uid ) );
				return false;
			}
			return true;
		}
		$u = get_userdata( $uid );
		$roles = $u ? (array) $u->roles : array();
		return (bool) array_intersect( array( 'zdz_owner', 'zdz_admin' ), $roles );
	}

	/** created_by first, provenance-initials fallback. */
	private static function owns_row( array $row, int $uid ): bool {
		if ( (int) ( $row['created_by'] ?? 0 ) === $uid ) {
			return true;
		}
		$code = self::user_code( $uid );
		if ( '' === $code || ! class_exists( 'ZDZ_Doc_Conventions' ) ) {
			return false;
		}
		foreach ( preg_split( '/\r\n|\r|\n/', (string) ( $row['notes'] ?? '' ) ) as $line ) {
			if ( ZDZ_Doc_Conventions::is_provenance_line( (string) $line ) && stripos( (string) $line, $code ) !== false ) {
				return true;
			}
		}
		return false;
	}

	private static function user_code( int $uid ): string {
		$code = strtoupper( trim( (string) get_user_meta( $uid, 'zdz_user_initials', true ) ) );
		if ( '' === $code ) {
			$code = strtoupper( trim( (string) get_user_meta( $uid, 'ts_user_initials', true ) ) );
		}
		return $code;
	}

	private static function output_ctx( int $uid ): array {
		$paren = (string) get_user_meta( $uid, 'zdz_user_parenthetical', true );
		if ( '' === $paren ) {
			$paren = (string) get_user_meta( $uid, 'ts_user_parenthetical', true );
		}
		return array( 'initials' => self::user_code( $uid ), 'parenthetical' => $paren, 'user_id' => $uid );
	}

	/* ---- endpoints ---- */

	/** Parse inline (small text). Photos go through ZEST_Background instead. */
	public static function ajax_parse(): void {
		$uid  = self::guard();
		$text = isset( $_POST['text'] ) ? wp_kses_post( wp_unslash( $_POST['text'] ) ) : '';
		$ctx  = array(
			'user_id'         => $uid,
			'is_operator_mode' => user_can( $uid, 'zest_create_zero_estimates' ) && ! self::is_admin_tier( $uid ),
			'is_new_estimate' => true,
		);
		$res = self::engine()->parse( $text, $ctx );
		if ( empty( $res['ok'] ) ) {
			wp_send_json_error( array( 'message' => $res['error'] ) );
		}
		wp_send_json_success( $res );
	}

	/** Create the billing estimate + CRM lead from a confirmed preview. */
	public static function ajax_create(): void {
		$uid = self::guard();
		// Third-layer defense (§61 INV-10, S2-08): the direct AJAX create path refuses on a
		// shared device too, matching the chat bridge's kiosk hard-refusal.
		if ( self::is_kiosk_session( $uid ) ) {
			wp_send_json_error( array( 'message' => 'This action is not available on the shared device.' ), 403 );
		}
		$raw = isset( $_POST['estimate'] ) ? json_decode( wp_unslash( $_POST['estimate'] ), true ) : null;
		if ( ! is_array( $raw ) ) {
			wp_send_json_error( array( 'message' => 'Missing estimate.' ) );
		}
		$email = ZEST_FreshBooks::clean_email( $raw['customer_email'] ?? ( $raw['customer']['email'] ?? '' ) );
		$is_operator = user_can( $uid, 'zest_create_zero_estimates' ) && ! self::is_admin_tier( $uid );

		// A priced (non-operator) estimate requires a valid email to reach the customer.
		$items = (array) ( $raw['line_items'] ?? array() );
		$total = 0.0;
		foreach ( $items as $li ) {
			$total += (float) ( $li['unit_price'] ?? 0 ) * (int) ( $li['quantity'] ?? 1 );
		}

		// E5 — the $0-stub allowance matrix (server-authoritative, INV-1). Privileged
		// full-$0 stub allowed; a mixed $0 (a $0 billable among priced) blocked for all.
		$zero = self::assess_zero_total( $items, $uid, array( 'is_operator' => $is_operator, 'path' => 'create' ) );
		if ( empty( $zero['allow'] ) ) {
			wp_send_json_error( array( 'message' => $zero['refuse_message'] ) );
		}

		if ( ZEST_FreshBooks::is_configured() ) {
			// Billing connected: a priced estimate needs an email to deliver, and we create through the provider.
			if ( $total > 0 && '' === $email ) {
				wp_send_json_error( array( 'message' => 'This estimate needs a valid email address to create and deliver the customer document.' ) );
			}
			$res = ZEST_FreshBooks::create_estimate( $raw, self::output_ctx( $uid ) );
			if ( empty( $res['ok'] ) ) {
				wp_send_json_error( array( 'message' => $res['error'] ) );
			}
		} else {
			// No billing provider connected: save a local estimate the app owns. It syncs to a
			// provider later, when one is connected. Estimates never require an external service.
			$res = array( 'ok' => true, 'id' => '', 'number' => self::next_local_number(), 'local' => true );
		}

		// Mirror locally for ownership + history (source of truth for "my estimates").
		self::store_row( $raw, $res, $uid );

		// CRM lead is failure-tolerant — a CRM outage never blocks the billing estimate.
		if ( ZEST_Nutshell::is_configured() ) {
			$lead = ZEST_Nutshell::create_lead( array(
				'contact'   => (array) ( $raw['customer'] ?? array() ),
				'reference' => (string) ( $raw['reference'] ?? '' ),
			) );
			if ( empty( $lead['ok'] ) ) {
				error_log( 'Zorderz Estimates: CRM lead not created for estimate ' . $res['number'] . ' — ' . $lead['error'] );
			}
		}

		wp_send_json_success( array( 'number' => $res['number'], 'id' => $res['id'], 'local' => ! empty( $res['local'] ) ) );
	}

	/**
	 * Update an existing estimate (Plan 02 E1). Two-phase: with NO confirm_hash it returns
	 * a preview + a content hash and writes nothing; WITH a confirm_hash + the echoed doc it
	 * re-verifies and writes once. Nonce + login guarded (guard()), kiosk-refused server-side.
	 */
	public static function ajax_update(): void {
		$uid = self::guard();
		if ( self::is_kiosk_session( $uid ) ) {
			wp_send_json_error( array( 'message' => 'This action is not available on the shared device.' ), 403 );
		}
		$args = array(
			'estimate_number' => isset( $_POST['estimate_number'] ) ? sanitize_text_field( wp_unslash( $_POST['estimate_number'] ) ) : '',
			'estimate_id'     => isset( $_POST['estimate_id'] ) ? (int) $_POST['estimate_id'] : 0,
			'instruction'     => isset( $_POST['instruction'] ) ? wp_kses_post( wp_unslash( $_POST['instruction'] ) ) : '',
			'image_urls'      => isset( $_POST['images'] ) ? array_map( 'esc_url_raw', (array) wp_unslash( $_POST['images'] ) ) : array(),
			'confirm_hash'    => isset( $_POST['confirm_hash'] ) ? sanitize_text_field( wp_unslash( $_POST['confirm_hash'] ) ) : '',
			'doc'             => isset( $_POST['doc'] ) ? json_decode( wp_unslash( $_POST['doc'] ), true ) : null,
		);
		$res = ( '' === $args['confirm_hash'] )
			? self::compute_update_preview( $args, $uid )
			: self::commit_update( $args, $uid );
		if ( empty( $res['ok'] ) ) {
			wp_send_json_error( array( 'message' => $res['error'] ?? 'Update failed.' ) );
		}
		wp_send_json_success( $res );
	}

	/** Open estimates: admin sees all (unless collapsed), a rep sees their own. */
	public static function ajax_list_open(): void {
		$uid = self::guard();
		wp_send_json_success( array( 'rows' => self::rows_for( $uid, "status IN ('created','stub','open')" ) ) );
	}

	public static function ajax_history(): void {
		$uid = self::guard();
		wp_send_json_success( array( 'rows' => self::rows_for( $uid, '1=1', 200 ) ) );
	}

	/** Assignable people from ZDZ_Party (active, emailable) — never a local roster. */
	public static function ajax_assignables(): void {
		$uid = self::guard();
		if ( ! self::is_admin_tier( $uid ) ) {
			wp_send_json_error( array( 'message' => 'Not allowed.' ), 403 );
		}
		wp_send_json_success( array( 'people' => self::assignable_people() ) );
	}

	public static function ajax_lookup(): void {
		$uid = self::guard();
		$customer = isset( $_POST['customer'] ) ? sanitize_text_field( wp_unslash( $_POST['customer'] ) ) : '';
		$is_kiosk = class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_kiosk( $uid );
		wp_send_json_success( array( 'documents' => self::lookup_documents( $customer, $uid, $is_kiosk ) ) );
	}

	public static function ajax_lead_to_stub(): void {
		$uid = self::guard();
		if ( ! self::is_admin_tier( $uid ) ) {
			wp_send_json_error( array( 'message' => 'Not allowed.' ), 403 );
		}
		$res = self::create_stub_from_lead(
			isset( $_POST['lead'] ) ? sanitize_text_field( wp_unslash( $_POST['lead'] ) ) : '',
			isset( $_POST['assignee'] ) ? sanitize_text_field( wp_unslash( $_POST['assignee'] ) ) : '',
			$uid
		);
		if ( empty( $res['ok'] ) ) {
			wp_send_json_error( array( 'message' => $res['error'] ) );
		}
		wp_send_json_success( $res );
	}

	public static function ajax_attach_email(): void {
		$uid = self::guard();
		$res = self::attach_email(
			isset( $_POST['estimate_number'] ) ? sanitize_text_field( wp_unslash( $_POST['estimate_number'] ) ) : '',
			ZEST_FreshBooks::clean_email( $_POST['email'] ?? '' ),
			$uid
		);
		if ( empty( $res['ok'] ) ) {
			wp_send_json_error( array( 'message' => $res['error'] ) );
		}
		wp_send_json_success( $res );
	}

	/* ---- shared cores (used by AJAX + the chat bridge) ---- */

	/** Active, emailable parties as assignees. Row shape: { id, name, initials }. */
	public static function assignable_people(): array {
		if ( ! class_exists( 'ZDZ_Party' ) || ! method_exists( 'ZDZ_Party', 'selectable_people' ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) ZDZ_Party::selectable_people() as $p ) {
			$out[] = array(
				'id'       => (int) ( $p['id'] ?? 0 ),
				'name'     => (string) ( $p['name'] ?? '' ),
				'initials' => strtoupper( (string) ( $p['initials'] ?? '' ) ),
			);
		}
		return $out;
	}

	/** Resolve a spoken assignee (id | code | name) to a party, unique-only. */
	private static function resolve_assignee( string $spoken ): ?array {
		$spoken = trim( $spoken );
		if ( '' === $spoken ) {
			return null;
		}
		$people = self::assignable_people();
		if ( ctype_digit( $spoken ) ) {
			foreach ( $people as $p ) {
				if ( (int) $p['id'] === (int) $spoken ) {
					return $p;
				}
			}
		}
		$up = strtoupper( $spoken );
		$by_code = array_values( array_filter( $people, fn( $p ) => $p['initials'] === $up ) );
		if ( 1 === count( $by_code ) ) {
			return $by_code[0];
		}
		$lc = strtolower( $spoken );
		$by_name = array_values( array_filter( $people, fn( $p ) => strtolower( $p['name'] ) === $lc || stripos( $p['name'], $spoken ) === 0 ) );
		return 1 === count( $by_name ) ? $by_name[0] : null;
	}

	/** Read-only customer document lookup, redacted for a kiosk caller (server-side). */
	public static function lookup_documents( string $customer, int $uid, bool $kiosk ): array {
		$client = ZEST_FreshBooks::client();
		if ( ! $client || '' === trim( $customer ) ) {
			return array();
		}
		$docs = array();
		try {
			$clients = $client->get_clients( array( 'search' => $customer ) );
			$list    = $clients['response']['result']['clients'] ?? ( is_array( $clients ) ? $clients : array() );
			foreach ( (array) $list as $c ) {
				$docs[] = array(
					'name'  => trim( (string) ( $c['fname'] ?? '' ) . ' ' . (string) ( $c['lname'] ?? '' ) ),
					'city'  => $kiosk ? '' : (string) ( $c['p_city'] ?? '' ),
					'email' => $kiosk ? '' : (string) ( $c['email'] ?? '' ),
				);
			}
		} catch ( \Throwable $e ) {
			error_log( 'Zorderz Estimates: lookup_documents failed: ' . $e->getMessage() );
		}
		return $docs;
	}

	/** Create a $0 stub for a CRM lead, assigned to a resolved party. */
	public static function create_stub_from_lead( string $lead, string $assignee, int $acting_uid ): array {
		$who = self::resolve_assignee( $assignee );
		if ( '' !== $assignee && null === $who && ! in_array( strtolower( $assignee ), array( 'unassigned', 'none', 'nobody' ), true ) ) {
			return array( 'ok' => false, 'error' => 'Could not uniquely resolve the assignee. Name one person.' );
		}
		$ctx = array( 'is_operator_mode' => true, 'is_new_estimate' => true, 'user_id' => $acting_uid );
		$estimate = array(
			'customer'   => array(),
			'line_items' => array(),      // a pure $0 stub — no priced lines
			'reference'  => '',
		);
		// The location/provenance lines derive from the ASSIGNEE's code, applied on output.
		$out_ctx = $who ? array( 'initials' => $who['initials'], 'parenthetical' => '', 'user_id' => (int) $who['id'] )
			: array( 'initials' => '', 'parenthetical' => '', 'user_id' => 0 );
		$res = ZEST_FreshBooks::create_estimate( $estimate, $out_ctx );
		if ( empty( $res['ok'] ) ) {
			return array( 'ok' => false, 'error' => $res['error'] );
		}
		return array( 'ok' => true, 'number' => $res['number'], 'id' => $res['id'], 'assignee' => $who['name'] ?? 'Unassigned' );
	}

	/** Attach an email to an owned estimate so it becomes sendable. */
	public static function attach_email( string $estimate_number, string $email, int $uid ): array {
		if ( '' === $email ) {
			return array( 'ok' => false, 'error' => 'Provide a valid email.' );
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . ZEST_DB::estimates_table() . ' WHERE billing_doc_num = %s',
			$estimate_number
		), ARRAY_A );
		if ( ! $row ) {
			return array( 'ok' => false, 'error' => 'Estimate not found.' );
		}
		if ( ! self::is_admin_tier( $uid ) && ! self::owns_row( $row, $uid ) ) {
			return array( 'ok' => false, 'error' => 'You can only edit your own estimates.' );
		}
		$wpdb->update( ZEST_DB::estimates_table(), array( 'customer_email' => $email ), array( 'id' => (int) $row['id'] ) );
		return array( 'ok' => true, 'number' => $estimate_number, 'email' => $email );
	}

	/* ---- update path (Plan 02 E1) — shared by AJAX + the chat bridge ---- */

	/**
	 * Phase 1: compute the update preview. Loads the owned row, reads the prior items,
	 * runs apply_modification (lock + conflict + vision), runs the guards, and returns a
	 * preview doc + a content hash. WRITES NOTHING.
	 *
	 * @return array{ ok:bool, preview:array, confirm_verb:string, confirm_hash:string, warnings:array, needs_review:bool, error:string }
	 */
	public static function compute_update_preview( array $args, int $uid ): array {
		$out = array( 'ok' => false, 'error' => '' );
		$row = self::load_owned_row( $args, $uid );
		if ( is_string( $row ) ) {
			$out['error'] = $row;
			return $out;
		}
		$prior = self::read_prior_items( $row );
		$ctx   = self::output_ctx( $uid );
		$ctx['zip'] = (string) ( $row['customer_zip'] ?? '' );
		$mod   = self::engine()->apply_modification( $prior, (string) $args['instruction'], $ctx, (array) $args['image_urls'] );
		if ( empty( $mod['ok'] ) ) {
			$out['error'] = $mod['error'] ?: 'Could not compute the update.';
			return $out;
		}
		$doc   = self::build_update_doc( $row, $mod, $uid );
		$guard = self::run_update_guards( $row, $doc, $uid );
		if ( ! empty( $guard['refuse'] ) ) {
			$out['error'] = $guard['message'];
			return $out;
		}
		$out['ok']           = true;
		$out['preview']      = $doc;
		$out['confirm_verb'] = 'estimate.modify';
		$out['confirm_hash'] = self::canonical_doc_hash( $doc );
		$out['warnings']     = (array) ( $mod['warnings'] ?? array() );
		$out['needs_review'] = ! empty( $mod['needs_review'] );
		return $out;
	}

	/**
	 * Phase 2: commit the update. The confirmed doc is echoed back with its hash; the write
	 * is refused unless the doc still hashes to the confirm_hash (content-hash approval — a
	 * marker without a matching hash is held, not executed). The row is re-loaded fresh, the
	 * guards re-run server-side, then the write executes once (provider when configured +
	 * a billing id exists, else local). Idempotent on retry (subtractive write).
	 *
	 * @return array{ ok:bool, number:string, id:string, updated:bool, error:string }
	 */
	public static function commit_update( array $args, int $uid ): array {
		$out = array( 'ok' => false, 'error' => '' );
		$doc = is_array( $args['doc'] ?? null ) ? $args['doc'] : null;
		if ( ! is_array( $doc ) ) {
			$out['error'] = 'Missing the confirmed document.';
			return $out;
		}
		// Content-hash approval: the doc to be written must match what was previewed.
		if ( ! hash_equals( (string) $args['confirm_hash'], self::canonical_doc_hash( $doc ) ) ) {
			$out['error'] = 'This confirmation no longer matches the previewed estimate. Review and confirm again.';
			return $out;
		}
		$row = self::load_owned_row( $args, $uid );
		if ( is_string( $row ) ) {
			$out['error'] = $row;
			return $out;
		}
		// Re-verify the guards server-side (defense in depth; the model is never trusted).
		$guard = self::run_update_guards( $row, $doc, $uid );
		if ( ! empty( $guard['refuse'] ) ) {
			$out['error'] = $guard['message'];
			return $out;
		}

		$number = (string) $row['billing_doc_num'];
		$id     = (string) $row['billing_doc_id'];
		if ( ZEST_FreshBooks::is_configured() && '' !== $id ) {
			$res = ZEST_FreshBooks::update_estimate( $id, $doc, self::output_ctx( $uid ) );
			if ( empty( $res['ok'] ) ) {
				$out['error'] = $res['error'] ?: 'Billing update failed.';
				return $out;
			}
			$number = $res['number'] ?: $number;
			$id     = $res['id'] ?: $id;
		}
		// Always mirror the change locally (subtractive) so the console reflects it.
		$local = self::store_update( (int) $row['id'], $doc, $uid );
		if ( empty( $local['ok'] ) ) {
			$out['error'] = $local['error'] ?: 'Local update failed.';
			return $out;
		}

		// E6 — carry the measurement rows into the CRM note (sanitized at the gate).
		$rows = array_values( array_filter( array_map( 'strval', (array) ( $doc['measurement_rows'] ?? array() ) ) ) );
		if ( ! empty( $rows ) ) {
			self::write_measurements_note( $row, $rows, $uid );
		}
		// E7 — geo-provenance finalize (idempotent, fail-open).
		$transcript = ! empty( $args['image_urls'] ) ? implode( "\n", $rows ) : '';
		self::finalize_media_geo( (int) $row['id'], (string) $row['billing_doc_num'], (array) $args['image_urls'], $transcript, self::customer_from_doc( $doc, $row ), $uid );

		return array( 'ok' => true, 'number' => $number, 'id' => $id, 'updated' => true );
	}

	/**
	 * Subtractive local write (workflow-web §5): ONLY the fields the confirmed doc names
	 * change — items_json, item_count, reference, notes (provenance re-merged), updated_at.
	 * billing_doc_id / created_by are never touched, no column is blanket-overwritten.
	 *
	 * @return array{ ok:bool, id:int, error:string }
	 */
	public static function store_update( int $row_id, array $doc, int $uid ): array {
		global $wpdb;
		if ( $row_id <= 0 ) {
			return array( 'ok' => false, 'id' => 0, 'error' => 'No row to update.' );
		}
		$fields = array();
		if ( array_key_exists( 'line_items', $doc ) ) {
			$items                = array_values( (array) $doc['line_items'] );
			$fields['items_json'] = wp_json_encode( $items );
			$fields['item_count'] = count( $items );
		}
		if ( array_key_exists( 'reference', $doc ) ) {
			$fields['reference'] = (string) $doc['reference'];
		}
		if ( array_key_exists( 'notes', $doc ) ) {
			$notes = (string) $doc['notes'];
			if ( class_exists( 'ZDZ_Doc_Conventions' ) ) {
				$ctx  = self::output_ctx( $uid );
				$prov = ZDZ_Doc_Conventions::provenance_line( $ctx['initials'], $ctx['parenthetical'] );
				if ( '' !== $prov ) {
					$notes = ZDZ_Doc_Conventions::merge_provenance( $notes, $prov );
				}
			}
			$fields['notes'] = $notes;
		}
		$fields['updated_at'] = current_time( 'mysql' );
		$wpdb->update( ZEST_DB::estimates_table(), $fields, array( 'id' => $row_id ) );
		return array( 'ok' => true, 'id' => $row_id, 'error' => '' );
	}

	/** Run the write-time guards on the resulting doc: E3 zero-regression + E5 $0 matrix. */
	private static function run_update_guards( array $row, array $doc, int $uid ): array {
		$items     = (array) ( $doc['line_items'] ?? array() );
		$new_total = self::compute_doc_total( $items );

		// E3 — zero-regression (identity-blind, fail-open) against the PRIOR total.
		if ( class_exists( 'Zdz_Doc_Guard' ) ) {
			$prior = self::prior_total_for_row( $row );
			$g     = Zdz_Doc_Guard::zero_regression_refusal( $prior, $new_total, array( 'row_id' => (int) $row['id'] ) );
			if ( ! empty( $g['refuse'] ) ) {
				return array( 'refuse' => true, 'message' => $g['message'] );
			}
		}
		// E5 — $0-stub allowance matrix on the resulting billable lines.
		$is_operator = user_can( $uid, 'zest_create_zero_estimates' ) && ! self::is_admin_tier( $uid );
		$zero        = self::assess_zero_total( $items, $uid, array( 'is_operator' => $is_operator, 'path' => 'update' ) );
		if ( empty( $zero['allow'] ) ) {
			return array( 'refuse' => true, 'message' => $zero['refuse_message'] );
		}
		return array( 'refuse' => false, 'message' => '' );
	}

	/* ---- $0-stub allowance matrix (Plan 02 E5) ---- */

	/**
	 * Classify a document's $0 posture and decide whether the acting user may save it.
	 * Server-authoritative (INV-1). Privileged full-$0 stub allowed; a mixed $0 (a $0
	 * billable among priced) blocked for EVERYONE; a catalog line that matched an item but
	 * resolved $0 is a forgotten price (D-11), treated as mixed except in operator mode
	 * where a full-$0 pre-estimate is the deliberate workflow.
	 *
	 * @return array{ allow:bool, kind:string, refuse_message:string }
	 */
	public static function assess_zero_total( array $items, int $uid, array $ctx = array() ): array {
		$priced           = 0;
		$zero             = 0;
		$matched_but_zero = false;
		foreach ( $items as $li ) {
			if ( ! is_array( $li ) ) {
				continue;
			}
			$kind = strtolower( trim( (string) ( $li['kind'] ?? '' ) ) );
			if ( '' !== $kind && 'item' !== $kind ) {
				continue; // context / discount / fee / note are not billable lines
			}
			$price = (float) ( $li['unit_price'] ?? 0 );
			if ( class_exists( 'ZDZ_Doc_Conventions' )
				&& ZDZ_Doc_Conventions::is_metadata_line( (string) ( $li['description'] ?? '' ), $price ) ) {
				continue; // a $0 metadata/context line by description is not billable
			}
			if ( $price > 0 ) {
				$priced++;
			} else {
				$zero++;
				if ( self::catalog_matched_but_zero( $li ) ) {
					$matched_but_zero = true;
				}
			}
		}

		// Classify.
		if ( 0 === $zero && $priced > 0 ) {
			return array( 'allow' => true, 'kind' => 'priced', 'refuse_message' => '' );
		}
		$is_operator     = ! empty( $ctx['is_operator'] );
		$forgotten_price = $matched_but_zero && ! $is_operator; // D-11 discrimination
		if ( $priced > 0 || $forgotten_price ) {
			$msg = $forgotten_price
				? 'A line matched a catalog item but resolved to $0 — that is a missing price, not a stub. Add its price before saving.'
				: 'This estimate mixes priced lines with $0 lines. Price every line, or make it a full $0 stub.';
			return array( 'allow' => false, 'kind' => 'mixed', 'refuse_message' => $msg );
		}
		// A full $0 (every billable line $0, or no billable lines) — a deliberate stub.
		$privileged = self::is_zero_privileged( $uid, $ctx );
		$allow      = $privileged && (bool) apply_filters( 'zdz_allow_zero_total', $privileged, $ctx );
		return array(
			'allow'          => $allow,
			'kind'           => 'stub',
			'refuse_message' => $allow ? '' : 'A $0 estimate stub can only be created by an owner, admin or operator.',
		);
	}

	/** manage_options / owner / admin / the zero-pricing cap / operator mode — never a bare slug match alone. */
	private static function is_zero_privileged( int $uid, array $ctx ): bool {
		if ( ! empty( $ctx['is_operator'] ) ) {
			return true;
		}
		if ( user_can( $uid, 'zest_create_zero_estimates' ) ) {
			return true;
		}
		return self::is_admin_tier( $uid );
	}

	/** D-11: a $0 billable line that matched a catalog item whose scheme resolved a real $0. */
	private static function catalog_matched_but_zero( array $li ): bool {
		if ( (float) ( $li['unit_price'] ?? 0 ) > 0 ) {
			return false;
		}
		$item_id = (string) ( $li['item_id'] ?? '' );
		if ( '' === $item_id ) {
			$item_id = (string) ZEST_Catalog::classify( (string) ( $li['description'] ?? '' ) );
		}
		if ( '' === $item_id ) {
			return false; // no catalog match → an intended $0, not a forgotten price
		}
		$r      = ZEST_Catalog::resolve_price( $item_id, array( 'qty' => 1 ) );
		$amount = $r['amount'] ?? null;
		return ( null !== $amount && (float) $amount <= 0 ); // matched + resolved a real $0
	}

	/* ---- E6 CRM note + E7 geo-provenance ---- */

	/** Write the measurement rows into a CRM note, sanitized on the outbound boundary (E6). */
	private static function write_measurements_note( array $row, array $rows, int $uid ): void {
		if ( empty( $rows ) || ! ZEST_Nutshell::is_configured() ) {
			return;
		}
		$body = sprintf( '%d measurement row(s) added to estimate %s:', count( $rows ), (string) $row['billing_doc_num'] )
			. "\n" . implode( "\n", array_map( 'strval', $rows ) );
		if ( class_exists( 'ZDZ_Answer_Authority' ) && method_exists( 'ZDZ_Answer_Authority', 'sanitize_outbound' ) ) {
			$body = ZDZ_Answer_Authority::sanitize_outbound( $body );
		}
		$lead = ZEST_Nutshell::create_lead( array(
			'contact'   => self::row_customer( $row ),
			'reference' => (string) $row['reference'],
			'note'      => $body,
		) );
		if ( empty( $lead['ok'] ) ) {
			error_log( 'Zorderz Estimates: measurements CRM note not written for estimate ' . (string) $row['billing_doc_num'] . ' — ' . ( $lead['error'] ?? '' ) );
		}
	}

	/**
	 * Persist estimate media as geotagged ZDZ_User_Media rows (Plan 02 E7). Per photo, one
	 * GPS-stamped row keyed by source_ref (idempotent). The measurement transcript becomes
	 * an estimate_transcript row. The customer address is forward-geocoded ONCE via the
	 * Plan-03b geocoder (method_exists-guarded — absent → rows save without GPS, fail-open).
	 * Media is read back through ZDZ_User_Media's token proxy, never a public uploads URL
	 * (geo-PII floor). No money motion; pricing/model/CRM untouched.
	 */
	public static function finalize_media_geo( int $eid, string $doc_number, array $image_urls, string $transcript, array $customer, int $uid ): void {
		if ( ! class_exists( 'ZDZ_User_Media' ) || $uid <= 0 ) {
			return;
		}
		$image_urls = array_values( array_filter( array_map( 'strval', $image_urls ) ) );
		$transcript = (string) $transcript;
		if ( empty( $image_urls ) && '' === trim( $transcript ) ) {
			return; // nothing to persist
		}

		// Forward-geocode ONCE through the shared geocoder (Plan 03b), feeding the STRUCTURED
		// address so ZDZ_Geocoder::address_cache_key() computes the byte-identical cache key
		// the jobs Project also seeds — one cache entry, surfaced by address. Absent geocoder
		// → rows save without GPS (fail-open). ZDZ_Media_Geocoder::resolve_address is a
		// secondary in case a build wires the forward seam onto the reverse class.
		$lat = null;
		$lng = null;
		$geo = null;
		try {
			if ( class_exists( 'ZDZ_Geocoder' ) && method_exists( 'ZDZ_Geocoder', 'resolve_address' ) ) {
				$geo = ZDZ_Geocoder::resolve_address( $customer ); // canonical field array → shared key
			} elseif ( class_exists( 'ZDZ_Media_Geocoder' ) && method_exists( 'ZDZ_Media_Geocoder', 'resolve_address' ) ) {
				$addr = self::geo_address( $customer );
				$geo  = '' !== $addr ? ZDZ_Media_Geocoder::resolve_address( $addr ) : null;
			}
		} catch ( \Throwable $e ) {
			error_log( 'Zorderz Estimates: forward geocode failed (fail-open): ' . $e->getMessage() );
		}
		if ( is_array( $geo ) ) {
			$lat = isset( $geo['lat'] ) ? (float) $geo['lat'] : null;
			$lng = isset( $geo['lng'] ) ? (float) $geo['lng'] : null;
		}

		$key_base = ( '' !== trim( $doc_number ) )
			? 'est-' . preg_replace( '/[^A-Za-z0-9_-]/', '', $doc_number )
			: 'est-h' . substr( md5( $transcript . implode( '|', $image_urls ) ), 0, 10 );

		foreach ( $image_urls as $url ) {
			$ref = $key_base . '-p' . substr( md5( $url ), 0, 10 );
			if ( ZDZ_User_Media::get_by_source_ref( $uid, $ref ) ) {
				continue; // idempotent per photo
			}
			ZDZ_User_Media::save( array(
				'user_id'    => $uid,
				'file_url'   => $url,
				'media_type' => 'photo',
				'source_app' => ZEST_APP_ID,
				'source_ref' => $ref,
				'privacy'    => 'private',
				'gps_lat'    => $lat,
				'gps_lng'    => $lng,
			) );
		}

		if ( '' !== trim( $transcript ) ) {
			$ref = $key_base . '-transcript';
			if ( ! ZDZ_User_Media::get_by_source_ref( $uid, $ref ) ) {
				$file_url = self::write_transcript_file( $doc_number, $transcript );
				if ( '' !== $file_url ) {
					ZDZ_User_Media::save( array(
						'user_id'    => $uid,
						'file_url'   => $file_url,
						'media_type' => 'estimate_transcript',
						'source_app' => ZEST_APP_ID,
						'source_ref' => $ref,
						'privacy'    => 'private',
						'gps_lat'    => $lat,
						'gps_lng'    => $lng,
					) );
				}
			}
		}
	}

	/**
	 * Compose "street, city, state zip" the way the jobs Project does, so both hash to the
	 * SAME geocode-cache key (Plan 01 contract). The jobs app owns the canonical normalizer;
	 * we compose the same string and route it through the shared filter so the two agree.
	 */
	public static function geo_address( array $customer ): string {
		// Prefer the single source of the cache-key contract (Plan 03b), so the estimate and
		// the jobs Project hash the same address to the same key by construction.
		if ( class_exists( 'ZDZ_Geocoder' ) && method_exists( 'ZDZ_Geocoder', 'address_cache_key' ) ) {
			return (string) ZDZ_Geocoder::address_cache_key( $customer );
		}
		// Fallback composition ("street, city, state zip") mirroring the contract.
		$street = trim( (string) ( $customer['street'] ?? '' ) );
		$city   = trim( (string) ( $customer['city'] ?? '' ) );
		$state  = trim( (string) ( $customer['state'] ?? '' ) );
		$zip    = trim( (string) ( $customer['zip'] ?? '' ) );
		$parts  = array();
		if ( '' !== $street ) {
			$parts[] = $street;
		}
		if ( '' !== $city ) {
			$parts[] = $city;
		}
		$sz = trim( $state . ' ' . $zip );
		if ( '' !== $sz ) {
			$parts[] = $sz;
		}
		$addr = implode( ', ', $parts );
		return (string) apply_filters( 'zdz_geo_address_normalize', $addr, $customer );
	}

	/** Write the transcript to a .txt in uploads; return its URL, or '' (fail-open). */
	private static function write_transcript_file( string $doc_number, string $transcript ): string {
		if ( ! function_exists( 'wp_upload_bits' ) ) {
			return '';
		}
		$slug = ( '' !== trim( $doc_number ) ) ? preg_replace( '/[^A-Za-z0-9_-]/', '', $doc_number ) : 'doc';
		// UNGUESSABLE filename (geo-PII): a geotagged transcript carries the customer address /
		// measurements, so its raw uploads URL must NOT be derivable from the public estimate
		// number. The media row is resolved by source_ref, never by filename, so the name is free
		// to be random — this closes the guessable-public-URL leak the tokened proxy alone can't.
		$rand = function_exists( 'wp_generate_password' )
			? wp_generate_password( 24, false, false )
			: substr( hash( 'sha256', $slug . '|' . $transcript . '|' . uniqid( '', true ) ), 0, 24 );
		$res  = wp_upload_bits( 'estimate-transcript-' . $slug . '-' . $rand . '.txt', null, $transcript );
		if ( ! empty( $res['error'] ) || empty( $res['url'] ) ) {
			error_log( 'Zorderz Estimates: transcript file write failed (fail-open): ' . ( $res['error'] ?? '' ) );
			return '';
		}
		return (string) $res['url'];
	}

	/* ---- update-path helpers ---- */

	/** Load the owned estimate row by id or number; return the row, or an error string. */
	private static function load_owned_row( array $args, int $uid ) {
		global $wpdb;
		$table = ZEST_DB::estimates_table();
		$row   = null;
		$id    = (int) ( $args['estimate_id'] ?? 0 );
		$num   = trim( (string) ( $args['estimate_number'] ?? '' ) );
		if ( $id > 0 ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		} elseif ( '' !== $num ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE billing_doc_num = %s", $num ), ARRAY_A );
		}
		if ( ! $row ) {
			return 'Estimate not found.';
		}
		if ( ! self::is_admin_tier( $uid ) && ! self::owns_row( $row, $uid ) ) {
			return 'You can only update your own estimates.';
		}
		return $row;
	}

	/** Prior line items — from the provider (WIRE shape) when a billing id exists, else local. */
	private static function read_prior_items( array $row ): array {
		$billing_id = (string) ( $row['billing_doc_id'] ?? '' );
		if ( '' !== $billing_id && ZEST_FreshBooks::is_configured() ) {
			$client = ZEST_FreshBooks::client();
			if ( $client ) {
				try {
					$resp  = $client->get_estimates( array( 'estimateid' => $billing_id ) );
					$est   = $resp['response']['result']['estimate'] ?? null;
					$lines = is_array( $est ) ? ( $est['lines'] ?? array() ) : array();
					if ( is_array( $lines ) && ! empty( $lines ) ) {
						return $lines; // WIRE shape — normalized inside apply_modification
					}
				} catch ( \Throwable $e ) {
					error_log( 'Zorderz Estimates: prior items provider read failed: ' . $e->getMessage() );
				}
			}
		}
		$items = json_decode( (string) ( $row['items_json'] ?? '' ), true );
		return is_array( $items ) ? $items : array();
	}

	/** The prior document total for the zero-regression guard; null → fail-open. */
	private static function prior_total_for_row( array $row ): ?float {
		$billing_id = (string) ( $row['billing_doc_id'] ?? '' );
		if ( '' !== $billing_id ) {
			// Provider-backed: the provider is the prior authority; a transport error → null.
			if ( ! ZEST_FreshBooks::is_configured() || ! class_exists( 'Zdz_Doc_Guard' ) ) {
				return null;
			}
			$client = ZEST_FreshBooks::client();
			if ( ! $client ) {
				return null;
			}
			try {
				$resp = $client->get_estimates( array( 'estimateid' => $billing_id ) );
				return Zdz_Doc_Guard::total_from_provider( is_array( $resp ) ? $resp : array() );
			} catch ( \Throwable $e ) {
				error_log( 'Zorderz Estimates: prior total provider read failed (fail-open): ' . $e->getMessage() );
				return null;
			}
		}
		$items = json_decode( (string) ( $row['items_json'] ?? '' ), true );
		return is_array( $items ) ? self::compute_doc_total( $items ) : null;
	}

	/** Server-authoritative total of a model-shape line-item set. */
	private static function compute_doc_total( array $items ): float {
		if ( class_exists( 'ZEST_Doc_Renderer' ) && method_exists( 'ZEST_Doc_Renderer', 'compute_totals' ) ) {
			$t = ZEST_Doc_Renderer::compute_totals( $items );
			return (float) ( $t['total'] ?? 0 );
		}
		$sum = 0.0;
		foreach ( $items as $li ) {
			if ( is_array( $li ) ) {
				$sum += (float) ( $li['unit_price'] ?? 0 ) * (float) ( $li['quantity'] ?? 1 );
			}
		}
		return $sum;
	}

	/** Build the preview/write doc from the modify result, prepending any lock disclosure. */
	private static function build_update_doc( array $row, array $mod, int $uid ): array {
		$doc = array(
			'customer'         => self::row_customer( $row ),
			'line_items'       => array_values( (array) ( $mod['line_items'] ?? array() ) ),
			'reference'        => isset( $mod['reference'] ) ? (string) $mod['reference'] : (string) ( $row['reference'] ?? '' ),
			'notes'            => (string) ( $row['notes'] ?? '' ),
			'measurement_rows' => array_values( (array) ( $mod['measurement_rows'] ?? array() ) ),
		);
		if ( '' !== (string) ( $mod['disclosure'] ?? '' ) && class_exists( 'Zdz_Doc_Preservation' ) ) {
			$doc = Zdz_Doc_Preservation::prepend_disclosure( $doc, (string) $mod['disclosure'], 'notes' );
		}
		return $doc;
	}

	/** A stable content hash of the written document (content-hash approval, workflow-web §5). */
	private static function canonical_doc_hash( array $doc ): string {
		$proj = array(
			'customer'  => self::sort_map( (array) ( $doc['customer'] ?? array() ) ),
			'reference' => (string) ( $doc['reference'] ?? '' ),
			'notes'     => (string) ( $doc['notes'] ?? '' ),
			'lines'     => array(),
			'measures'  => array_values( array_map( 'strval', (array) ( $doc['measurement_rows'] ?? array() ) ) ),
		);
		foreach ( (array) ( $doc['line_items'] ?? array() ) as $li ) {
			if ( ! is_array( $li ) ) {
				continue;
			}
			$qty = array_key_exists( 'quantity', $li ) ? $li['quantity'] : ( $li['qty'] ?? '' );
			$proj['lines'][] = array(
				'kind'  => strtolower( trim( (string) ( $li['kind'] ?? '' ) ) ),
				'desc'  => (string) ( $li['description'] ?? '' ),
				'sub'   => (string) ( $li['sub_description'] ?? '' ),
				'qty'   => is_numeric( $qty ) ? (string) ( 0 + $qty ) : trim( (string) $qty ),
				'cents' => (int) round( ( (float) ( $li['unit_price'] ?? 0 ) ) * 100 ),
			);
		}
		return hash( 'sha256', (string) wp_json_encode( $proj ) );
	}

	/** ksort a flat map for a stable projection. */
	private static function sort_map( array $a ): array {
		ksort( $a );
		return array_map( 'strval', $a );
	}

	/** Customer block from the row columns. */
	private static function row_customer( array $row ): array {
		return array(
			'name'   => (string) ( $row['customer_name'] ?? '' ),
			'org'    => (string) ( $row['customer_org'] ?? '' ),
			'email'  => (string) ( $row['customer_email'] ?? '' ),
			'phone'  => (string) ( $row['customer_phone'] ?? '' ),
			'street' => (string) ( $row['customer_street'] ?? '' ),
			'city'   => (string) ( $row['customer_city'] ?? '' ),
			'state'  => (string) ( $row['customer_state'] ?? '' ),
			'zip'    => (string) ( $row['customer_zip'] ?? '' ),
		);
	}

	/** The customer block from a doc, falling back to the row's columns. */
	private static function customer_from_doc( array $doc, array $row ): array {
		$c = (array) ( $doc['customer'] ?? array() );
		return ! empty( $c ) ? $c : self::row_customer( $row );
	}

	/** Reconcile billing status SIGNALS into local rows (cron). Never filters on a raw int. */
	public static function cron_sync_estimates(): void {
		$client = ZEST_FreshBooks::client();
		if ( ! $client ) {
			return;
		}
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT id, billing_doc_id FROM ' . ZEST_DB::estimates_table() . " WHERE billing_doc_id <> '' AND status <> 'accepted' LIMIT 100", ARRAY_A );
		foreach ( (array) $rows as $r ) {
			try {
				$est = $client->get_estimates( array( 'estimateid' => $r['billing_doc_id'] ) );
				$one = $est['response']['result']['estimate'] ?? null;
				if ( ! is_array( $one ) ) {
					continue;
				}
				$signal = ZEST_FreshBooks::status_signal( $one );
				if ( 'customer_accepted' === $signal ) {
					$wpdb->update( ZEST_DB::estimates_table(), array( 'status' => 'accepted', 'accepted_source' => 'billing' ), array( 'id' => (int) $r['id'] ) );
				}
			} catch ( \Throwable $e ) {
				error_log( 'Zorderz Estimates: sync failed for estimate id ' . $r['id'] . ': ' . $e->getMessage() );
			}
		}
	}

	/* ---- helpers ---- */

	private static function rows_for( int $uid, string $where, int $limit = 100 ): array {
		global $wpdb;
		$table = ZEST_DB::estimates_table();
		if ( self::is_admin_tier( $uid ) ) {
			$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC LIMIT %d", $limit );
		} else {
			// "mine" = created_by OR provenance-initials — always runs (the Ron fix).
			$code = self::user_code( $uid );
			$like = '%' . $wpdb->esc_like( 'Submitted by:' ) . '%' . $wpdb->esc_like( $code ) . '%';
			$sql  = $wpdb->prepare(
				"SELECT * FROM {$table} WHERE ({$where}) AND (created_by = %d" . ( '' !== $code ? ' OR notes LIKE %s' : '' ) . ") ORDER BY created_at DESC LIMIT %d",
				'' !== $code ? array( $uid, $like, $limit ) : array( $uid, $limit )
			);
		}
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return array_map( array( __CLASS__, 'public_row' ), (array) $rows );
	}

	private static function public_row( array $row ): array {
		return array(
			'id'       => (int) $row['id'],
			'customer' => (string) $row['customer_name'],
			'number'   => (string) $row['billing_doc_num'],
			'status'   => (string) $row['status'],
			'created'  => (string) $row['created_at'],
			'items'    => (int) $row['item_count'],
		);
	}

	/** Sequential local estimate number for the no-billing fallback (EST-0001, EST-0002, ...). */
	private static function next_local_number(): string {
		global $wpdb;
		$n = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id),0)+1 FROM ' . ZEST_DB::estimates_table() );
		return 'EST-' . str_pad( (string) $n, 4, '0', STR_PAD_LEFT );
	}

	private static function store_row( array $raw, array $res, int $uid ): void {
		global $wpdb;
		$items = (array) ( $raw['line_items'] ?? array() );
		$notes = (string) ( $raw['notes'] ?? '' );
		// Ensure the provenance line is present in the stored notes for legacy ownership.
		if ( class_exists( 'ZDZ_Doc_Conventions' ) ) {
			$ctx  = self::output_ctx( $uid );
			$prov = ZDZ_Doc_Conventions::provenance_line( $ctx['initials'], $ctx['parenthetical'] );
			if ( '' !== $prov ) {
				$notes = ZDZ_Doc_Conventions::merge_provenance( $notes, $prov );
			}
		}
		$wpdb->insert( ZEST_DB::estimates_table(), array(
			'customer_name'   => (string) ( $raw['customer_name'] ?? ( $raw['customer']['name'] ?? '' ) ),
			'customer_email'  => ZEST_FreshBooks::clean_email( $raw['customer_email'] ?? ( $raw['customer']['email'] ?? '' ) ),
			'salesperson'     => self::user_code( $uid ),
			'item_count'      => count( $items ),
			'items_json'      => wp_json_encode( $items ),
			'rejected_json'   => wp_json_encode( (array) ( $raw['rejected'] ?? array() ) ),
			'notes'           => $notes,
			'input_text'      => (string) ( $raw['input_text'] ?? '' ),
			'reference'       => (string) ( $raw['reference'] ?? '' ),
			'billing_doc_id'  => (string) $res['id'],
			'billing_doc_num' => (string) $res['number'],
			'status'          => 'created',
			'created_by'      => $uid,
		) );
		$eid = (int) $wpdb->insert_id;
		if ( $eid > 0 && function_exists( 'do_action' ) ) {
			/**
			 * An estimate was persisted (B4 seam). Downstream containers (Projects) mint UPSTREAM
			 * from this so a quote alone becomes a Project. Fail-safe: a subscriber must never break
			 * the save; the budgeted floor sweep is the converging backstop for any miss.
			 */
			do_action( 'zest_estimate_saved', $eid, array( 'source' => 'dashboard', 'created_by' => (int) $uid ) );

			// E7 — geo-provenance finalize on create (idempotent, fail-open). Only photo-based
			// estimates persist a transcript; a pure typed create has no photo to geotag.
			$image_urls = array();
			foreach ( array( 'image_urls', 'images' ) as $k ) {
				if ( ! empty( $raw[ $k ] ) && is_array( $raw[ $k ] ) ) {
					$image_urls = array_map( 'strval', $raw[ $k ] );
					break;
				}
			}
			if ( '' !== (string) ( $raw['image_url'] ?? '' ) ) {
				$image_urls[] = (string) $raw['image_url'];
			}
			$transcript = ! empty( $image_urls ) ? (string) ( $raw['input_text'] ?? '' ) : '';
			$customer   = ! empty( $raw['customer'] ) && is_array( $raw['customer'] ) ? $raw['customer'] : array(
				'street' => (string) ( $raw['customer_street'] ?? '' ),
				'city'   => (string) ( $raw['customer_city'] ?? '' ),
				'state'  => (string) ( $raw['customer_state'] ?? '' ),
				'zip'    => (string) ( $raw['customer_zip'] ?? '' ),
			);
			self::finalize_media_geo( $eid, (string) ( $res['number'] ?? '' ), $image_urls, $transcript, $customer, $uid );
		}
	}

	/* ---- widget support ---- */

	/** Field-level permissions for the current user (widget config). Neutral defaults. */
	public static function get_resolved_permissions( int $user_id ): array {
		$perms = array( 'view_pricing' => true, 'edit_pricing' => true );
		return (array) apply_filters( 'zest_field_permissions', $perms, $user_id );
	}
}
