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
		$raw = isset( $_POST['estimate'] ) ? json_decode( wp_unslash( $_POST['estimate'] ), true ) : null;
		if ( ! is_array( $raw ) ) {
			wp_send_json_error( array( 'message' => 'Missing estimate.' ) );
		}
		$email = ZEST_FreshBooks::clean_email( $raw['customer_email'] ?? ( $raw['customer']['email'] ?? '' ) );
		$is_operator = user_can( $uid, 'zest_create_zero_estimates' ) && ! self::is_admin_tier( $uid );

		// A priced (non-operator) estimate requires a valid email to reach the customer.
		$total = 0.0;
		foreach ( (array) ( $raw['line_items'] ?? array() ) as $li ) {
			$total += (float) ( $li['unit_price'] ?? 0 ) * (int) ( $li['quantity'] ?? 1 );
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
	}

	/* ---- widget support ---- */

	/** Field-level permissions for the current user (widget config). Neutral defaults. */
	public static function get_resolved_permissions( int $user_id ): array {
		$perms = array( 'view_pricing' => true, 'edit_pricing' => true );
		return (array) apply_filters( 'zest_field_permissions', $perms, $user_id );
	}
}
