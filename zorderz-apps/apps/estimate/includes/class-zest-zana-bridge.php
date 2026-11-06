<?php
/**
 * ZEST_ZANA_Bridge - the chat's verbs for the Estimates app, on the platform's verb registry.
 *
 * The chat app (Analytics) runs an action block ([ZDZ_ACTION]{"verb":…} or this app's own marker, published on
 * `zdz_chat_markers` with its verb) through ZDZ_Capabilities: a read is answered at once, a write is held for the
 * person's Confirm press and then runs here with `confirmed` set and the signed-in person stamped over the arguments.
 * Every answer is the registry's envelope: { success, message, … } (with `ok` kept for older callers).
 *
 *   estimate.lookup        read   a customer's estimates (and, with the chat's stored ledger, its invoices), only the
 *                                 ones this person may open (the admin tier: all); others are counted, never shown. The
 *                                 shared device gets names, numbers and statuses only.
 *   estimate.create        write  on Confirm, creates the estimate exactly as the app's own create does
 *                                 (ZEST_Dashboard::create_core); without Confirm, a preview and nothing written
 *   estimate.modify        write  on Confirm, the instruction is previewed and applied in one step only when the preview
 *                                 needs no review; otherwise nothing is written and the person is sent to the app
 *   estimate.send          write  e-mails an estimate that is the person's, only the copy that was approved
 *                                 (ZEST_Approve::send: approval, deposit limit, agreement)
 *   estimate.stub          write  a $0 stub for a CRM lead, the admin tier only; `preview` resolves and writes nothing
 *   estimate.attach_email  write  an email on the person's own estimate so it can be sent
 *
 * SAFETY FLOOR, here and not in the prompt: the shared device never writes; who is asking is the SESSION (the payload's
 * id is used only when there is no session, as for a scheduled run); the admin tier is worked out on the server; every
 * write is ownership-checked; the model is never trusted for a side effect.
 *
 * The ask bar: a quick route (`zana_quick_routes`) answers "estimate 1042" and "my open estimates" from this app's own
 * rows, for estimates the person may open, with no model.
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZEST_ZANA_Bridge {

	/** stub_from_lead() takes `preview` (resolve and check, write nothing). */
	const STUB_PREVIEW = true;

	public static function init(): void {
		add_filter( 'zana_quick_routes', array( __CLASS__, 'quick_routes' ) );
	}

	/** The verb rows this app puts on the registry (flat rows: tier, side_effect, summary, callback). */
	public static function verbs(): array {
		$rows = array(
			'estimate.lookup'       => array( 'lookup_for_chat', 'viewer', false, 'A customer\'s estimates and invoices that are yours. Args: {"customer":"name"}.' ),
			'estimate.create'       => array( 'create_from_chat', 'staff', true, 'Create an estimate. Args: {"customer":{"name","email","phone","street","city","state","zip"},"line_items":[{"description","sub_description","quantity","unit_price"}],"reference"}.' ),
			'estimate.modify'       => array( 'update_from_chat', 'staff', true, 'Change one of your estimates. Args: {"estimate_number":"1042","instruction":"what to change"}.' ),
			'estimate.send'         => array( 'send_from_chat', 'staff', true, 'E-mail one of your approved estimates to its customer. Args: {"estimate_number":"1042"}.' ),
			'estimate.stub'         => array( 'stub_from_lead', 'admin', true, 'A $0 estimate stub for a CRM lead, assigned to a person. Args: {"lead":"name or number","assignee":"name or code"}.' ),
			'estimate.attach_email' => array( 'attach_email_from_chat', 'staff', true, 'Put an email on one of your estimates. Args: {"estimate_number":"1042","email":"…"}.' ),
		);
		$out = array();
		foreach ( $rows as $verb => $r ) {
			$out[ $verb ] = array(
				'verb'          => $verb,
				'provider'      => 'estimate-creator',
				'app'           => defined( 'ZEST_APP_ID' ) ? ZEST_APP_ID : 'estimate-creator',
				'tier'          => $r[1],
				'kiosk'         => false,
				'kiosk_bounded' => 'estimate.lookup' === $verb,
				'side_effect'   => $r[2],
				'outside'       => $r[2],
				'needs_app'     => 'estimate.lookup' !== $verb,
				'summary'       => $r[3],
				'callback'      => array( __CLASS__, $r[0] ),
			);
		}
		return $out;
	}

	/* ---- who is asking (server-side truth) ---- */

	/** The signed-in person; the payload's id only when there is no session (a scheduled run). */
	public static function acting_uid( array $payload ): int {
		$s = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		return $s > 0 ? $s : (int) ( $payload['requesting_user_id'] ?? ( $payload['user_id'] ?? 0 ) );
	}

	/**
	 * May the shared device look up customers' documents (names, numbers, statuses; never amounts)? Off unless the
	 * business turns it on (option zest_shared_device_lookup, filter of the same name): a device anyone can pick up
	 * should not list other people's customers by default.
	 */
	public static function shared_device_lookup_allowed(): bool {
		$on = function_exists( 'get_option' ) ? (bool) get_option( 'zest_shared_device_lookup', false ) : false;
		return (bool) ( function_exists( 'apply_filters' ) ? apply_filters( 'zest_shared_device_lookup', $on ) : $on );
	}

	private static function is_kiosk( int $uid ): bool {
		if ( class_exists( 'ZEST_Salesperson' ) && ZEST_Salesperson::is_kiosk( $uid ) ) {
			return true;
		}
		return class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_kiosk( $uid );
	}

	private static function is_admin_tier( int $uid ): bool {
		return class_exists( 'ZEST_Salesperson' ) && ZEST_Salesperson::is_admin_tier( $uid );
	}

	/** The registry's envelope from this app's { ok, error, … }: success, message, and `ok` kept. */
	public static function envelope( array $r, string $done = '' ): array {
		$ok = ! empty( $r['ok'] ) || ! empty( $r['success'] );
		$r['ok']      = $ok;
		$r['success'] = $ok;
		if ( ! isset( $r['message'] ) || '' === (string) $r['message'] ) {
			$r['message'] = $ok ? $done : (string) ( $r['error'] ?? __( 'That did not go through, and nothing was changed.', 'zorderz' ) );
		}
		return $r;
	}

	private static function refuse( string $why ): array {
		return array( 'ok' => false, 'success' => false, 'error' => $why, 'message' => $why );
	}

	private static function no_kiosk(): array {
		return self::refuse( __( 'This is not available on the shared device. Sign in as yourself.', 'zorderz' ) );
	}

	/* ---- verbs ---- */

	/**
	 * Create an estimate from chat. On Confirm (`confirmed`) it is created exactly as the app's own create does; without
	 * it, the preview the person would confirm (house style applied) and nothing written.
	 */
	public static function create_from_chat( array $payload ): array {
		$uid = self::acting_uid( $payload );
		if ( $uid <= 0 ) {
			return self::refuse( __( 'You need to be signed in to do that.', 'zorderz' ) );
		}
		if ( self::is_kiosk( $uid ) ) {
			return self::no_kiosk();
		}
		$c        = is_array( $payload['customer'] ?? null ) ? $payload['customer'] : array();
		$estimate = array(
			'customer'       => $c,
			'customer_name'  => (string) ( $c['name'] ?? trim( (string) ( $c['first_name'] ?? '' ) . ' ' . (string) ( $c['last_name'] ?? '' ) ) ),
			'customer_email' => (string) ( $c['email'] ?? '' ),
			'line_items'     => array_values( array_filter( (array) ( $payload['line_items'] ?? ( $payload['items'] ?? array() ) ), 'is_array' ) ),
			'reference'      => (string) ( $payload['reference'] ?? '' ),
			'notes'          => (string) ( $payload['notes'] ?? '' ),
		);
		if ( ! $estimate['line_items'] ) {
			return self::refuse( __( 'Name at least one line for the estimate.', 'zorderz' ) );
		}
		if ( class_exists( 'ZEST_Door_Handle' ) ) {
			$estimate['line_items'] = ZEST_Door_Handle::apply( $estimate['line_items'] );
		}
		if ( empty( $payload['confirmed'] ) ) {
			$preview = class_exists( 'ZDZ_Doc_Conventions' ) ? ZDZ_Doc_Conventions::apply_on_output( $estimate, self::output_ctx( $uid ) ) : $estimate;
			return array( 'ok' => true, 'success' => true, 'preview' => $preview, 'confirm_verb' => 'estimate.create',
				'message' => __( 'Here is the estimate as it would be made. Nothing has been made yet.', 'zorderz' ) );
		}
		if ( ! class_exists( 'ZEST_Dashboard' ) || ! method_exists( 'ZEST_Dashboard', 'create_core' ) ) {
			return self::refuse( __( 'Estimates can\'t be made from the chat just now.', 'zorderz' ) );
		}
		$res = ZEST_Dashboard::create_core( $estimate, $uid );
		if ( empty( $res['ok'] ) ) {
			return self::refuse( (string) $res['error'] );
		}
		/* translators: %s: estimate number */
		return self::envelope( $res, sprintf( __( 'Estimate #%s is made. Open it in Estimates to check it, approve it and send it.', 'zorderz' ), (string) $res['number'] ) );
	}

	/** This app's row for an estimate number the person names, if they may open it; [ row, refusal ]. */
	private static function own_row( string $number, int $uid ): array {
		$number = substr( (string) preg_replace( '/[^0-9A-Za-z\-]/', '', $number ), 0, 40 );
		if ( '' === $number ) {
			return array( null, __( 'Name the estimate number.', 'zorderz' ) );
		}
		$row = class_exists( 'ZEST_Access' ) ? ZEST_Access::row_by_number( $number ) : null;
		if ( ! $row || ! ZEST_Access::may_open( $row, $uid ) ) {
			// never says whether someone else's estimate exists
			/* translators: %s: estimate number */
			return array( null, sprintf( __( 'You can only work on your own estimates, and #%s isn\'t one of yours here.', 'zorderz' ), $number ) );
		}
		return array( $row, '' );
	}

	/** E-mail one of the person's estimates: only the copy that was approved, with every check Send makes in the app. */
	public static function send_from_chat( array $payload ): array {
		$uid = self::acting_uid( $payload );
		if ( $uid <= 0 ) {
			return self::refuse( __( 'You need to be signed in to do that.', 'zorderz' ) );
		}
		if ( self::is_kiosk( $uid ) ) {
			return self::no_kiosk();
		}
		list( $row, $no ) = self::own_row( (string) ( $payload['estimate_number'] ?? ( $payload['number'] ?? '' ) ), $uid );
		if ( ! $row ) {
			return self::refuse( $no );
		}
		if ( ! class_exists( 'ZEST_Approve' ) ) {
			return self::refuse( __( 'Estimates can\'t be sent from the chat just now.', 'zorderz' ) );
		}
		$r = ZEST_Approve::send( $row, $uid );
		if ( empty( $r['ok'] ) ) {
			return self::refuse( (string) ( $r['message'] ?? __( 'It was not sent.', 'zorderz' ) ) );
		}
		/* translators: %s: estimate number */
		return self::envelope( array( 'ok' => true, 'number' => (string) $row['billing_doc_num'] ), sprintf( __( 'Estimate #%s was sent to the customer.', 'zorderz' ), (string) $row['billing_doc_num'] ) );
	}

	/**
	 * A customer's documents for the chat. Who sees what is decided by the SESSION: the admin tier everything, the shared
	 * device everything in the bounded shape (names, numbers, statuses: no amounts, no addresses), anyone else only their
	 * own, with a count of the rest.
	 */
	public static function lookup_for_chat( array $payload ): array {
		$uid      = self::acting_uid( $payload );
		$customer = trim( (string) ( $payload['customer'] ?? ( $payload['name'] ?? '' ) ) );
		if ( '' === $customer ) {
			return self::envelope( array( 'ok' => true, 'needs_clarify' => true, 'documents' => array() ), __( 'Which customer should I look up?', 'zorderz' ) );
		}
		if ( $uid <= 0 ) {
			return self::refuse( __( 'You need to be signed in to see documents.', 'zorderz' ) );
		}
		$kiosk = self::is_kiosk( $uid ) || ! empty( $payload['is_kiosk'] );
		if ( $kiosk && ! self::shared_device_lookup_allowed() ) {
			return self::refuse( __( 'Customer documents are not looked up on the shared device.', 'zorderz' ) );
		}
		$all   = $kiosk || self::is_admin_tier( $uid );
		$money = ! $kiosk && ! empty( ZEST_Access::field_permissions( $uid )['view_pricing'] )
			&& ! ( class_exists( 'ZDZ_Privacy_View' ) && ZDZ_Privacy_View::is_on( $uid ) );
		list( $docs, $withheld ) = self::documents_for( $customer, $uid, $all, $money );
		$out = array( 'ok' => true, 'documents' => $docs, 'withheld' => $withheld, 'kiosk' => $kiosk );
		if ( ! $docs && $withheld > 0 ) {
			$out['not_yours'] = true;
			return self::envelope( $out, __( 'That customer\'s documents belong to someone else. Ask an administrator if you need them.', 'zorderz' ) );
		}
		if ( ! $docs ) {
			/* translators: %s: what was asked */
			return self::envelope( $out, sprintf( __( 'I found no estimates or invoices for "%s".', 'zorderz' ), $customer ) );
		}
		$lines = array();
		foreach ( $docs as $d ) {
			$lines[] = '- ' . ( 'invoice' === $d['kind'] ? __( 'Invoice', 'zorderz' ) : __( 'Estimate', 'zorderz' ) ) . ' #' . $d['number'] . ' - ' . $d['customer']
				. ( '' !== $d['status'] ? ' - ' . $d['status'] : '' ) . ( '' !== $d['date'] ? ' - ' . $d['date'] : '' ) . ( null !== $d['total'] ? ' - ' . ZEST_Line_Edits::money( $d['total'] ) : '' );
		}
		if ( $withheld > 0 ) {
			/* translators: %d: how many */
			$lines[] = '_' . sprintf( _n( '%d other document belongs to someone else.', '%d other documents belong to someone else.', $withheld, 'zorderz' ), $withheld ) . '_';
		}
		return self::envelope( $out, implode( "\n", $lines ) );
	}

	/** [ documents, withheld ]: this app's rows by customer name, then the chat's stored ledger (guarded). */
	public static function documents_for( string $customer, int $uid, bool $all, bool $money ): array {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( $customer ) . '%';
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . ZEST_DB::estimates_table() . " WHERE customer_name LIKE %s AND billing_doc_num <> '' ORDER BY id DESC LIMIT 40", $like ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name (ZEST_DB::estimates_table())
		$docs     = array();
		$withheld = 0;
		$seen     = array();
		foreach ( $rows as $r ) {
			if ( isset( $seen[ (string) $r['billing_doc_num'] ] ) ) {
				continue;
			}
			$seen[ (string) $r['billing_doc_num'] ] = true;
			if ( ! $all && ! ZEST_Access::may_open( $r, $uid ) ) {
				$withheld++;
				continue;
			}
			$items = json_decode( (string) ( $r['items_json'] ?? '' ), true );
			$total = 0.0;
			foreach ( is_array( $items ) ? $items : array() as $li ) {
				$total += (float) ( $li['unit_price'] ?? 0 ) * (float) ( $li['quantity'] ?? 1 );
			}
			$docs[] = array( 'kind' => 'estimate', 'number' => (string) $r['billing_doc_num'], 'customer' => (string) $r['customer_name'], 'status' => (string) $r['status'], 'date' => substr( (string) $r['created_at'], 0, 10 ), 'total' => $money ? round( $total, 2 ) : null );
		}
		// invoices (and estimates made outside this app) from the chat's stored ledger, by its own rules
		if ( class_exists( 'ZANA_Ledger' ) && class_exists( 'ZANA_Ledger_Access' ) && method_exists( 'ZANA_Ledger', 'table' ) && ! ( $all && self::is_kiosk( $uid ) ) ) {
			try {
				$ctx   = ZANA_Ledger_Access::context( $uid );
				$codes = ZANA_Ledger_Access::codes_for( $uid );
				$see   = $all && ZANA_Ledger_Access::may( $ctx, 'doc_all' );
				$own   = ! $see && $codes && ZANA_Ledger_Access::may( $ctx, 'doc_own' );
				foreach ( array( 'invoice' => array( 'invoices', 'invoice_number', 'inv_date', 'pay_status' ), 'estimate' => array( 'estimates', 'estimate_number', 'est_date', 'status_label' ) ) as $kind => $c ) {
					$t = ZANA_Ledger::table( $c[0] );
					if ( '' === $t ) {
						continue;
					}
					foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT {$c[1]} AS num, customer_name, {$c[2]} AS d, {$c[3]} AS st, amount, rep_code FROM {$t} WHERE customer_name LIKE %s AND vis_state <> 1 ORDER BY {$c[2]} DESC LIMIT 40", $like ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL -- table and columns are this list's own
						if ( 'estimate' === $kind && isset( $seen[ (string) $r['num'] ] ) ) {
							continue;
						}
						if ( ! $see && ! ( $own && ZANA_Ledger_Access::rep_matches( (string) $r['rep_code'], $codes ) ) ) {
							$withheld++;
							continue;
						}
						$docs[] = array( 'kind' => $kind, 'number' => (string) $r['num'], 'customer' => (string) $r['customer_name'], 'status' => (string) $r['st'], 'date' => (string) $r['d'], 'total' => $money ? round( (float) $r['amount'], 2 ) : null );
					}
				}
			} catch ( \Throwable $e ) {
				error_log( 'Zorderz Estimates: the stored ledger could not be read for a lookup (' . get_class( $e ) . ').' );
			}
		}
		return array( array_slice( $docs, 0, 25 ), $withheld );
	}

	/**
	 * A $0 stub for a CRM lead, assigned to a person. The admin tier only (worked out here), never the shared device.
	 * `preview`: resolve and check, write nothing. `assignee_id` pins the person a preview resolved.
	 */
	public static function stub_from_lead( array $payload ): array {
		$uid = self::acting_uid( $payload );
		if ( self::is_kiosk( $uid ) ) {
			return self::no_kiosk();
		}
		if ( ! self::is_admin_tier( $uid ) ) {
			return self::refuse( __( 'Only an administrator can make a stub from a lead.', 'zorderz' ) );
		}
		if ( ! class_exists( 'ZEST_Dashboard' ) || ! method_exists( 'ZEST_Dashboard', 'create_stub_from_lead' ) ) {
			return self::refuse( __( 'Stubs can\'t be made just now.', 'zorderz' ) );
		}
		$assignee = (string) ( $payload['assignee'] ?? '' );
		$pinned   = (int) ( $payload['assignee_id'] ?? 0 );
		if ( $pinned > 0 ) {
			$assignee = (string) $pinned;
		}
		if ( ! empty( $payload['preview'] ) ) {
			$who = null;
			foreach ( ZEST_Dashboard::assignable_people() as $p ) {
				if ( ( ctype_digit( $assignee ) && (int) $p['id'] === (int) $assignee ) || strtoupper( $assignee ) === $p['initials'] || 0 === strcasecmp( $assignee, (string) $p['name'] ) ) {
					$who = $p;
					break;
				}
			}
			if ( null === $who && '' !== $assignee ) {
				return self::refuse( __( 'Could not work out who to assign it to. Name one person.', 'zorderz' ) );
			}
			/* translators: %s: a person, or "nobody yet" */
			return self::envelope( array( 'ok' => true, 'preview' => true, 'assignee_id' => $who ? (int) $who['id'] : 0 ), sprintf( __( 'A $0 stub would be made for this lead, assigned to %s. Nothing has been made yet.', 'zorderz' ), $who ? $who['name'] . ' (' . $who['initials'] . ')' : __( 'nobody yet', 'zorderz' ) ) );
		}
		$res = ZEST_Dashboard::create_stub_from_lead( (string) ( $payload['lead'] ?? '' ), $assignee, $uid );
		/* translators: 1: estimate number, 2: a person */
		return self::envelope( $res, ! empty( $res['ok'] ) ? sprintf( __( 'Stub #%1$s is made, assigned to %2$s.', 'zorderz' ), (string) ( $res['number'] ?? '' ), (string) ( $res['assignee'] ?? '' ) ) : '' );
	}

	/**
	 * Change one of the person's estimates. Two-phase with a confirm_hash (an app screen that showed the preview); on the
	 * chat's Confirm alone, the change is previewed and applied in one step only when the preview needs no review.
	 *
	 * @param array $payload { estimate_number|estimate_id, instruction, image_urls, confirm_hash, doc, confirmed }
	 */
	public static function update_from_chat( array $payload ): array {
		$uid = self::acting_uid( $payload );
		if ( $uid <= 0 ) {
			return self::refuse( __( 'You need to be signed in to do that.', 'zorderz' ) );
		}
		if ( self::is_kiosk( $uid ) ) {
			return self::no_kiosk();
		}
		if ( ! class_exists( 'ZEST_Dashboard' ) ) {
			return self::refuse( __( 'Estimates can\'t be changed from the chat just now.', 'zorderz' ) );
		}
		$args = array(
			'estimate_number' => (string) ( $payload['estimate_number'] ?? '' ),
			'estimate_id'     => (int) ( $payload['estimate_id'] ?? 0 ),
			'instruction'     => (string) ( $payload['instruction'] ?? '' ),
			'image_urls'      => array_values( array_filter( array_map( 'strval', (array) ( $payload['image_urls'] ?? array() ) ) ) ),
			'confirm_hash'    => (string) ( $payload['confirm_hash'] ?? '' ),
			'doc'             => is_array( $payload['doc'] ?? null ) ? $payload['doc'] : null,
		);
		if ( '' === $args['confirm_hash'] ) {
			$pre = ZEST_Dashboard::compute_update_preview( $args, $uid );
			if ( empty( $pre['ok'] ) ) {
				return self::refuse( '' !== (string) ( $pre['error'] ?? '' ) ? (string) $pre['error'] : __( 'Could not preview the change.', 'zorderz' ) );
			}
			if ( empty( $payload['confirmed'] ) || ! empty( $pre['needs_review'] ) || ! empty( $pre['warnings'] ) ) {
				return array(
					'ok'           => empty( $payload['confirmed'] ),
					'success'      => empty( $payload['confirmed'] ),
					'preview'      => $pre['preview'],
					'confirm_verb' => 'estimate.modify',
					'confirm_hash' => $pre['confirm_hash'],
					'warnings'     => (array) ( $pre['warnings'] ?? array() ),
					'needs_review' => ! empty( $pre['needs_review'] ),
					'message'      => empty( $payload['confirmed'] ) ? __( 'Here is the change. Nothing has been changed yet.', 'zorderz' ) : __( 'That change needs a look first, so nothing was changed. Open the estimate in Estimates to make it.', 'zorderz' ),
				);
			}
			$args['confirm_hash'] = (string) $pre['confirm_hash'];
			$args['doc']          = is_array( $pre['preview'] ?? null ) ? $pre['preview'] : null;
		}
		$res = ZEST_Dashboard::commit_update( $args, $uid );
		if ( empty( $res['ok'] ) ) {
			return self::refuse( '' !== (string) ( $res['error'] ?? '' ) ? (string) $res['error'] : __( 'The change did not go through.', 'zorderz' ) );
		}
		/* translators: %s: estimate number */
		return self::envelope( $res, sprintf( __( 'Estimate #%s is changed. Approve it again before it is sent.', 'zorderz' ), (string) ( $res['number'] ?? '' ) ) );
	}

	/** An email on one of the person's estimates, so it can be sent. */
	public static function attach_email_from_chat( array $payload ): array {
		$uid = self::acting_uid( $payload );
		if ( $uid <= 0 ) {
			return self::refuse( __( 'You need to be signed in to do that.', 'zorderz' ) );
		}
		if ( self::is_kiosk( $uid ) ) {
			return self::no_kiosk();
		}
		$email = ZEST_FreshBooks::clean_email( $payload['email'] ?? '' );
		if ( '' === $email ) {
			return self::refuse( __( 'Give a valid email to put on the estimate.', 'zorderz' ) );
		}
		if ( ! class_exists( 'ZEST_Dashboard' ) || ! method_exists( 'ZEST_Dashboard', 'attach_email' ) ) {
			return self::refuse( __( 'That can\'t be done just now.', 'zorderz' ) );
		}
		$res = ZEST_Dashboard::attach_email( (string) ( $payload['estimate_number'] ?? '' ), $email, $uid );
		/* translators: %s: estimate number */
		return self::envelope( $res, ! empty( $res['ok'] ) ? sprintf( __( 'The email is on estimate #%s. It can be sent once it is approved.', 'zorderz' ), (string) ( $res['number'] ?? '' ) ) : '' );
	}

	/* ---- the ask bar ---- */

	public static function quick_routes( $routes ) {
		$routes   = is_array( $routes ) ? $routes : array();
		$routes[] = array( __CLASS__, 'quick' );
		return $routes;
	}

	/**
	 * "estimate 1042" / "#1042 estimate" -> that estimate's line, when the person may open it; "my open estimates" -> the
	 * person's open estimates. Anything else, or anything not theirs: null (the ask bar offers the chat). No model.
	 *
	 * @return array|null [ kind, markdown ]
	 */
	public static function quick( $q, $uid ): ?array {
		$q   = strtolower( trim( (string) $q ) );
		$uid = (int) $uid;
		if ( '' === $q || $uid <= 0 || self::is_kiosk( $uid ) || ! class_exists( 'ZEST_Access' ) ) {
			return null;
		}
		if ( preg_match( '/^(?:show\s+(?:me\s+)?)?(?:estimate|est\.?)\s*#?\s*(\d{2,12})\??$/', $q, $m ) || preg_match( '/^#?(\d{2,12})\s+(?:estimate|est)\??$/', $q, $m ) ) {
			$row = ZEST_Access::row_by_number( $m[1] );
			if ( ! $row || ! ZEST_Access::may_open( $row, $uid ) ) {
				return null;
			}
			/* translators: 1: number, 2: customer, 3: status, 4: lines, 5: date */
			return array( 'estimate', sprintf( __( '**Estimate #%1$s** - %2$s - %3$s - %4$d line(s) - made %5$s', 'zorderz' ), (string) $row['billing_doc_num'], (string) $row['customer_name'], (string) $row['status'], (int) $row['item_count'], substr( (string) $row['created_at'], 0, 10 ) ) );
		}
		if ( preg_match( '/^(?:show\s+)?(?:me\s+)?my\s+open\s+estimates\??$/', $q ) ) {
			global $wpdb;
			list( $mine, $args ) = ZEST_Salesperson::mine_sql( $uid );
			$args[] = 10;
			$rows   = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . ZEST_DB::estimates_table() . " WHERE status IN ('created','stub','open','updated') AND {$mine} ORDER BY id DESC LIMIT %d", $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL -- mine_sql() is placeholders only
			if ( ! $rows ) {
				return array( 'estimates', __( 'You have no open estimates.', 'zorderz' ) );
			}
			$out = array();
			foreach ( $rows as $r ) {
				$out[] = '- #' . (string) $r['billing_doc_num'] . ' - ' . (string) $r['customer_name'] . ' - ' . (string) $r['status'];
			}
			return array( 'estimates', implode( "\n", $out ) );
		}
		return null;
	}

	/* ---- format spec for the chat model, rendered from Doc Conventions ---- */

	/**
	 * A neutral description of the business's document conventions for the chat model to follow when previewing an
	 * estimate. Rendered from ZDZ_Doc_Conventions: no literal.
	 */
	public static function format_spec(): array {
		if ( ! class_exists( 'ZDZ_Doc_Conventions' ) ) {
			return array();
		}
		$spec    = array();
		$leading = (array) ZDZ_Doc_Conventions::get( 'estimate.leading', array() );
		if ( $leading ) {
			$spec['leading_lines'] = wp_list_pluck( $leading, 'description' );
		}
		$trailing = (array) ZDZ_Doc_Conventions::get( 'estimate.trailing', array() );
		if ( $trailing ) {
			$spec['closing_line'] = $trailing[0]['text'] ?? '';
		}
		$inc = (float) ZDZ_Doc_Conventions::get( 'rounding.price.increment', 0 );
		if ( $inc > 0 ) {
			$spec['price_rounding'] = sprintf( 'Round prices up to the nearest %s.', rtrim( rtrim( number_format( $inc, 2 ), '0' ), '.' ) );
		}
		if ( ZDZ_Doc_Conventions::get( 'estimate.provenance_line.enabled', false ) ) {
			$spec['provenance_line'] = ZDZ_Doc_Conventions::get( 'estimate.provenance_line.template', '' );
		}
		return $spec;
	}

	/** The output context (code + parenthetical) for the acting person. */
	private static function output_ctx( int $uid ): array {
		$code = class_exists( 'ZEST_Salesperson' ) ? ZEST_Salesperson::initials_of( $uid ) : strtoupper( trim( (string) get_user_meta( $uid, 'zdz_user_initials', true ) ) );
		return array( 'initials' => $code, 'parenthetical' => (string) get_user_meta( $uid, 'zdz_user_parenthetical', true ), 'user_id' => $uid );
	}
}
