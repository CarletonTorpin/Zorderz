<?php
/**
 * The browser's only door to Charts. Every route: Charts access + the two-factor hook, the
 * WordPress REST nonce (cookie auth), an audit row. Hub calls are made here in PHP; the
 * browser gets back only the spec, the result, the caption and the meta it needs. No key,
 * token or hub address ever reaches the browser.
 *
 * Routes live under ZDZ_REST_NS . '/charts'.
 *
 * @package Zorderz\Charts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZCH_Rest {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function allowed() {
		return is_user_logged_in() && ZCH_Access::allowed( get_current_user_id() );
	}

	/** First lock: refuse before calling the hub when the op is not granted. */
	private static function forbidden() {
		return new WP_REST_Response(
			array( 'ok' => false, 'error' => array( 'code' => 'forbidden', 'message' => __( "Your account doesn't have access to that part of Charts.", 'zorderz' ) ) ),
			403
		);
	}

	private static function bad_request( $message ) {
		return new WP_REST_Response( array( 'ok' => false, 'error' => array( 'code' => 'bad_request', 'message' => $message ) ), 400 );
	}

	private static function may( $op ) {
		$b = ZCH_Access::block( get_current_user_id(), 'ui' );
		return in_array( $op, $b['ops'], true );
	}

	public static function routes() {
		$p  = array( __CLASS__, 'allowed' );
		$ns = ZDZ_REST_NS;
		$r  = function ( $path, $methods, $cb ) use ( $p, $ns ) {
			register_rest_route(
				$ns,
				ZCH_REST_BASE . $path,
				array(
					'methods'             => $methods,
					'callback'            => array( __CLASS__, $cb ),
					'permission_callback' => $p,
				)
			);
		};
		$r( '/health', 'GET', 'health' );
		$r( '/vocab', 'GET', 'vocab' );
		$r( '/ask', 'POST', 'ask' );
		$r( '/run', 'POST', 'run' );
		$r( '/search', 'POST', 'search' );
		$r( '/status', 'GET', 'status' );
		$r( '/saved', 'GET', 'saved_list' );
		$r( '/saved', 'POST', 'saved_save' );
		$r( '/saved/(?P<id>\d+)', 'DELETE', 'saved_delete' );
		$r( '/saved/(?P<id>\d+)/pin', 'POST', 'saved_pin' );
		$r( '/saved/(?P<id>\d+)/run', 'GET', 'saved_run' );
		$r( '/saved/(?P<id>\d+)/share', 'POST', 'saved_share' );
		$r( '/people', 'GET', 'people' );
		$r( '/flag', 'POST', 'flag' );
		$r( '/drill', 'POST', 'drill' );
		$r( '/jobs', 'POST', 'jobs' );
		$r( '/invoice', 'POST', 'invoice' );           // an invoice card.
		$r( '/invoice-link', 'POST', 'invoice_link' ); // the customer's link, to copy (never opened).
		$r( '/pivot', 'POST', 'pivot' );               // a question from a card.
		$r( '/prospect', 'POST', 'prospect' );         // where to canvass (a recipe).
		$r( '/territories', 'POST', 'territories' );   // who covers which ZIPs (Territories' verbs, as the asker).
	}

	/* ── The hub call ───────────────────────────────────────────────────── */

	private static function hub( $op, array $body, $question = null ) {
		$client = ZCH_Hub_Client::from_config();
		if ( ! $client ) {
			ZCH_Store::audit( $op, false, 'not_configured', null, null, $question );
			return ZCH_Hub_Client::unavailable();
		}
		$body['access'] = ZCH_Access::block( get_current_user_id(), 'ui' );
		$reply          = $client->call( $op, $body, 'wp_' . get_current_user_id(), wp_date( 'Y-m-d' ) );
		$rows           = isset( $reply['data']['result']['rows'] ) ? count( $reply['data']['result']['rows'] ) :
			( isset( $reply['data']['lines'] ) ? count( $reply['data']['lines'] ) : null );
		ZCH_Store::audit( $op, ! empty( $reply['ok'] ), $reply['error']['code'] ?? null, $rows, $client->last_ms, $question );
		if ( 'hub_unavailable' === ( $reply['error']['code'] ?? '' ) ) {
			update_option( 'zch_last_fail', time(), false );
		} elseif ( ! empty( $reply['ok'] ) ) {
			delete_option( 'zch_last_fail' );
		}
		return $reply;
	}

	/** Only what the page needs. */
	public static function chart_payload( $data ) {
		$meta = $data['meta'] ?? array();
		return array(
			'spec'      => $data['spec'] ?? null,
			'floor_key' => isset( $data['floor_key'] ) && is_string( $data['floor_key'] ) ? $data['floor_key'] : null,
			'status'    => $data['status'] ?? 'chart',
			'result'    => $data['result'] ?? null,
			'caption'   => (string) ( $data['caption'] ?? '' ),
			'meta'      => array(
				'model'             => $meta['model'] ?? null,
				'backup_model_used' => ! empty( $meta['backup_model_used'] ),
				'cached'            => ! empty( $meta['cached'] ),
				'data_as_of'        => $meta['data_as_of'] ?? null,
				'caption_model'     => $meta['caption_model'] ?? null,
			),
		);
	}

	private static function out( $reply, $fn = null ) {
		if ( empty( $reply['ok'] ) ) {
			$code = $reply['error']['code'] ?? 'internal';
			$err  = array( 'code' => $code, 'message' => $reply['error']['message'] ?? __( 'Charts hub unavailable.', 'zorderz' ) );
			if ( ! empty( $reply['error']['local'] ) ) {
				$err['local'] = true;              // no hub here: the page shows local mode, not an outage.
			}
			return new WP_REST_Response( array( 'ok' => false, 'error' => $err ), 'hub_unavailable' === $code ? 503 : 200 );
		}
		$data = $fn ? call_user_func( $fn, $reply['data'] ) : $reply['data'];
		return new WP_REST_Response( array( 'ok' => true, 'data' => $data ), 200 );
	}

	private static function rate_limited() {
		return new WP_REST_Response(
			array( 'ok' => false, 'error' => array( 'code' => 'rate_limited', 'message' => __( 'Too many in a minute. Try again shortly.', 'zorderz' ) ) ),
			429
		);
	}

	/* ── Routes ─────────────────────────────────────────────────────────── */

	public static function health() {
		return self::out( self::hub( 'health', array() ) );
	}

	public static function vocab() {
		return self::out( self::hub( 'vocab', array() ) );
	}

	public static function ask( WP_REST_Request $req ) {
		if ( ! self::may( 'ask' ) ) {
			return self::forbidden();
		}
		$q = trim( sanitize_textarea_field( (string) $req->get_param( 'question' ) ) );
		if ( '' === $q || mb_strlen( $q ) > 500 ) {
			return self::bad_request( __( 'Type a question (up to 500 characters).', 'zorderz' ) );
		}
		if ( ! ZCH_Store::rate_ok( get_current_user_id() ) ) {
			return new WP_REST_Response(
				array( 'ok' => false, 'error' => array( 'code' => 'rate_limited', 'message' => __( 'Too many questions in a minute. Try again shortly.', 'zorderz' ) ) ),
				429
			);
		}
		$prev = $req->get_param( 'previous_spec' );
		$body = array(
			'question'      => $q,
			// A territory map on the card is not a chart to follow up on.
			'previous_spec' => is_array( $prev ) && ! isset( $prev['territories'] ) ? ZCH_Store::clean_spec( $prev ) : null,
		);
		// A canvassing question comes back as status "prospect" (or a question back carrying the
		// recipe); both go through the same shaping as the /prospect route, and the asker's own
		// canvassing log rides along so "not where we've been lately" works in words too.
		$hung = ZCH_Prospect::hung_for_question( get_current_user_id(), $q );
		if ( null !== $hung ) {
			$body['hung'] = $hung;
		}
		return self::out(
			self::hub( 'ask', $body, $q ),
			function ( $d ) {
				// A territory question: the hub only reads which one it is; the territories come
				// from Territories' own verbs, here, as the person asking.
				if ( 'territories' === ( $d['status'] ?? '' ) ) {
					$t = isset( $d['spec']['territories'] ) && is_array( $d['spec']['territories'] ) ? $d['spec']['territories'] : array();
					return ZCH_Territories::for_page( (string) ( $t['who'] ?? '' ), (string) ( $d['spec']['title'] ?? '' ), (array) ( $d['result']['read_as'] ?? array() ) );
				}
				$is_p = 'prospect' === ( $d['status'] ?? '' ) || ( isset( $d['spec']['prospect'] ) && is_array( $d['spec']['prospect'] ) );
				return $is_p ? ZCH_Prospect::for_page( $d ) : self::chart_payload( $d );
			}
		);
	}

	/**
	 * The territory map's own door: the Territories button, a tapped ZIP, the layer on a ZIP map.
	 * Nothing goes to the hub: Territories' verbs answer, as the signed-in person, shaped by who
	 * that is. Works in local mode too.
	 */
	public static function territories( WP_REST_Request $req ) {
		$who = ZCH_Territories::clean_who( $req->get_param( 'who' ) );
		if ( '' === $who ) {
			return self::bad_request( __( 'Ask for your territory, all territories or one ZIP.', 'zorderz' ) );
		}
		if ( ! ZCH_Store::rate_ok( get_current_user_id() ) ) {
			return self::rate_limited();
		}
		$d = ZCH_Territories::for_page( $who, (string) $req->get_param( 'title' ) );
		ZCH_Store::audit( 'territories', ! empty( $d['territories']['ok'] ), null, count( $d['territories']['people'] ), null, null );
		return new WP_REST_Response( array( 'ok' => true, 'data' => $d ), 200 );
	}

	/** Rank neighborhoods for a recipe (the page's sliders, a saved recipe). */
	public static function prospect( WP_REST_Request $req ) {
		if ( ! self::may( 'run_v2' ) ) {
			return self::forbidden();
		}
		$uid = get_current_user_id();
		if ( ! ZCH_Store::rate_ok( $uid ) ) {
			return self::rate_limited();
		}
		$reply = ZCH_Prospect::run( (array) $req->get_param( 'recipe' ), $uid, 'ui' );
		if ( empty( $reply['ok'] ) ) {
			return self::out( $reply );
		}
		delete_option( 'zch_last_fail' );
		return new WP_REST_Response( array( 'ok' => true, 'data' => ZCH_Prospect::for_page( (array) $reply['data'] ) ), 200 );
	}

	/**
	 * "Not right" on an answer. The question, what Charts understood and the person's note go to
	 * the hub's misses log (the hub masks names and strips emails, phones and addresses again).
	 * Nothing is kept on the website.
	 */
	public static function flag( WP_REST_Request $req ) {
		if ( ! self::may( 'flag' ) ) {
			return self::forbidden();
		}
		$q    = mb_substr( trim( sanitize_textarea_field( (string) $req->get_param( 'question' ) ) ), 0, 500 );
		$note = mb_substr( trim( sanitize_textarea_field( (string) $req->get_param( 'note' ) ) ), 0, 500 );
		if ( '' === $q && '' === $note ) {
			return self::bad_request( __( 'Nothing to send.', 'zorderz' ) );
		}
		if ( ! ZCH_Store::rate_ok( get_current_user_id() ) ) {
			return self::rate_limited();
		}
		$spec = $req->get_param( 'spec' );
		$body = array(
			'question' => $q,
			'note'     => $note,
			'spec'     => is_array( $spec ) ? ZCH_Store::clean_spec( $spec ) : null,
		);
		return self::out(
			self::hub( 'flag', $body ),
			function () {
				return array( 'noted' => true );
			}
		);
	}

	/* ── Drill-down: a tapped bar opens one level down; a tapped piece lists its jobs ── */

	private static function group_param( WP_REST_Request $req ) {
		$g = trim( sanitize_text_field( (string) $req->get_param( 'group' ) ) );
		return mb_substr( $g, 0, 80 );
	}

	/**
	 * A tapped bar opens THAT period only. The browser sends the bar's period key exactly as the
	 * hub wrote it; it is checked here against the spec's own time grain (strict format, ASCII
	 * digits) and anything else is refused before the hub is called. The hub checks it again.
	 * Returns null (no period: the whole range), the period, or false (malformed).
	 */
	public static function period_param( $req, array $spec ) {
		$p = $req->get_param( 'period' );
		if ( null === $p ) {
			return null;
		}
		if ( ! is_string( $p ) ) {
			return false;
		}
		$grain   = array_key_exists( 'time_grain', $spec ) ? $spec['time_grain'] : 'year';   // the hub's default.
		$formats = array(
			'year'    => '/^[0-9]{4}$/D',
			'quarter' => '/^[0-9]{4}Q[1-4]$/D',
			'month'   => '/^[0-9]{4}-(0[1-9]|1[0-2])$/D',
		);
		if ( ! is_string( $grain ) || ! isset( $formats[ $grain ] ) || ! preg_match( $formats[ $grain ], $p ) ) {
			return false;
		}
		return $p;
	}

	private static function bad_period() {
		return self::bad_request( __( "That bar couldn't be opened.", 'zorderz' ) );
	}

	public static function drill( WP_REST_Request $req ) {
		if ( ! self::may( 'drill' ) ) {
			return self::forbidden();
		}
		$g = self::group_param( $req );
		if ( '' === $g ) {
			return self::bad_request( __( 'Nothing to open.', 'zorderz' ) );
		}
		if ( ! ZCH_Store::rate_ok( get_current_user_id() ) ) {
			return self::rate_limited();
		}
		$spec   = ZCH_Store::clean_spec( (array) $req->get_param( 'spec' ) );
		$period = self::period_param( $req, $spec );
		if ( false === $period ) {
			return self::bad_period();
		}
		$body = array( 'spec' => $spec, 'group' => $g );
		if ( null !== $period ) {
			$body['period'] = $period;
		}
		return self::out( self::hub( 'drill', $body ) );
	}

	public static function jobs( WP_REST_Request $req ) {
		if ( ! self::may( 'list_jobs' ) ) {
			return self::forbidden();
		}
		if ( ! ZCH_Store::rate_ok( get_current_user_id() ) ) {
			return self::rate_limited();
		}
		$spec   = ZCH_Store::clean_spec( (array) $req->get_param( 'spec' ) );
		$period = self::period_param( $req, $spec );
		if ( false === $period ) {
			return self::bad_period();
		}
		$limit = (int) $req->get_param( 'limit' );
		$body  = array( 'spec' => $spec, 'limit' => min( 200, max( 1, $limit ? $limit : 100 ) ) );
		$g     = self::group_param( $req );
		if ( '' !== $g ) {
			$body['group'] = $g;
		}
		if ( null !== $period ) {
			$body['period'] = $period;
		}
		// Like line-item search: shown, never stored. No link out; a number opens its card here.
		return self::out( self::hub( 'list_jobs', $body ), array( __CLASS__, 'with_links' ) );
	}

	/**
	 * Job rows carry no link out to the billing system. An invoice opens inside Charts (the card,
	 * for whoever may open it); for anyone else the number is plain text.
	 */
	public static function with_links( $d ) {
		if ( isset( $d['jobs'] ) && is_array( $d['jobs'] ) ) {
			foreach ( $d['jobs'] as &$j ) {
				unset( $j['invoice_url'] );
			}
			unset( $j );
		}
		return $d;
	}

	/* ── Invoice cards ──────────────────────────────────────────────────── */
	// A tapped invoice number expands to the full invoice; a tapped part of it (the customer, a
	// line item, the town, the rep, the source, the date) offers questions about that part. Who
	// may open one: the owner and admins (every job) and the salesperson of that job (their own).
	// Checked here first (the ledger's rep code), then on the hub (its own data), then here again
	// on the hub's answer before any name is added.

	public static function card_params( $req ) {
		$no   = $req->get_param( 'invoice_no' );
		$kind = $req->get_param( 'kind' ) ?? 'invoice';
		if ( is_int( $no ) ) {
			$no = (string) $no;
		}
		if ( ! is_string( $no ) || ! is_string( $kind ) ) {
			return null;
		}
		if ( '' === $kind ) {
			$kind = 'invoice';
		}
		if ( ! preg_match( '/^[A-Za-z0-9-]{1,20}$/D', $no ) || ! in_array( $kind, array( 'invoice', 'estimate' ), true ) ) {
			return null;
		}
		return array( $no, $kind );
	}

	private static function bad_card() {
		return self::bad_request( __( "That invoice couldn't be opened.", 'zorderz' ) );
	}

	/**
	 * The same answer the hub gives for a job that is not yours AND for one that does not exist
	 * (200, ok:false, forbidden), so the status cannot tell them apart.
	 */
	private static function not_yours() {
		return new WP_REST_Response(
			array( 'ok' => false, 'error' => array( 'code' => 'forbidden', 'message' => __( "Your account doesn't have access to that part of Charts.", 'zorderz' ) ) ),
			200
		);
	}

	/** The first lock for "own": the ledger names someone else -> refused, hub not called. */
	public static function ledger_says_not_mine( $kind, $no ) {
		$b = ZCH_Access::block( get_current_user_id(), 'ui' );
		if ( 'own' !== ( $b['invoices'] ?? 'none' ) ) {
			return false;
		}
		$rep = ZCH_Ledger::rep( $kind, $no );
		return '' !== $rep && (string) $b['me'] !== $rep;
	}

	/** May the person see the customer's name on this card (the hub's answer in hand)? */
	public static function may_see_name( $card, $kind, $no ) {
		$b   = ZCH_Access::block( get_current_user_id(), 'ui' );
		$inv = $b['invoices'] ?? 'none';
		if ( 'all' === $inv ) {
			return true;
		}
		if ( 'own' !== $inv || empty( $b['me'] ) ) {
			return false;
		}
		if ( (string) ( $card['salesperson'] ?? '' ) !== (string) $b['me'] ) {
			return false;
		}
		$rep = ZCH_Ledger::rep( $kind, $no );
		return '' === $rep || (string) $b['me'] === $rep;      // fail closed when the two disagree.
	}

	public static function invoice( WP_REST_Request $req ) {
		if ( ! self::may( 'invoice' ) ) {
			return self::forbidden();
		}
		$p = self::card_params( $req );
		if ( ! $p ) {
			return self::bad_card();
		}
		list( $no, $kind ) = $p;
		if ( ! ZCH_Store::rate_ok( get_current_user_id() ) ) {
			return self::rate_limited();
		}
		if ( self::ledger_says_not_mine( $kind, $no ) ) {
			return self::not_yours();
		}
		$reply = self::hub( 'invoice', array( 'invoice_no' => $no, 'kind' => $kind ) );
		return self::out(
			$reply,
			function ( $d ) use ( $no, $kind ) {
				$card             = $d['card'] ?? array();
				$b                = ZCH_Access::block( get_current_user_id(), 'ui' );
				$see              = self::may_see_name( $card, $kind, $no );
				$card['identity'] = $see ? ZCH_Ledger::identity( $kind, $no, ! empty( $b['money'] ) ) : null;
				unset( $card['invoice_url'] );   // never a link out to the billing system.
				// The invoice as the customer got it, read live, only for someone who may see the
				// customer on this card (the same rule as the name).
				$card['doc'] = null;
				if ( 'invoice' === $kind && $see && class_exists( 'ZCH_Invoice_Doc' ) ) {
					$card['doc'] = ZCH_Invoice_Doc::for_card( (string) ( $card['invoice_no'] ?? $no ), ! empty( $b['money'] ) );
				}
				// "Copy customer link" for whoever sees this customer's name and address (the same
				// people the invoice opens for here), never the shared device. A draft has no
				// button; the sheet says it has no link yet.
				$card['can_share'] = $see && ! ZCH_Access::is_kiosk( get_current_user_id() ) && is_array( $card['doc'] )
					&& ! empty( $card['doc']['ok'] ) && empty( $card['doc']['draft'] );
				return array( 'card' => $card, 'meta' => $d['meta'] ?? array() );
			}
		);
	}

	/** Plain sentences for each reason a customer link could not be copied. */
	public static function link_words() {
		$retry = __( "The billing system didn't give a customer link for this invoice just now. Try again in a minute.", 'zorderz' );
		return array(
			'draft'         => __( 'This invoice is still a draft in the billing system, so it has no customer link yet.', 'zorderz' ),
			'not_found'     => __( 'The billing system has no invoice with that number.', 'zorderz' ),
			'no_billing'    => __( "The billing system isn't connected on this site.", 'zorderz' ),
			'billing_error' => $retry,
			'no_link'       => $retry,
			'bad_number'    => __( "That invoice couldn't be opened.", 'zorderz' ),
			'ambiguous'     => __( 'The billing system has more than one invoice with this number, so no link was copied. Copy it from the invoice itself.', 'zorderz' ),
		);
	}

	/**
	 * The customer's link for one invoice, for the page to COPY. The same locks as the card, in
	 * the same order (the op, the number, the per-minute allowance, the ledger, the hub), then the
	 * same rule as the customer's name. The link is read live and is in the answer only; nothing
	 * keeps or logs it.
	 */
	public static function invoice_link( WP_REST_Request $req ) {
		if ( ! self::may( 'invoice' ) ) {
			return self::forbidden();
		}
		$p = self::card_params( $req );
		if ( ! $p || 'invoice' !== $p[1] ) {
			return self::bad_card();
		}
		list( $no, $kind ) = $p;
		if ( ! ZCH_Store::rate_ok( get_current_user_id() ) ) {
			return self::rate_limited();
		}
		if ( self::ledger_says_not_mine( $kind, $no ) ) {
			return self::not_yours();
		}
		$reply = self::hub( 'invoice', array( 'invoice_no' => $no, 'kind' => $kind ) );
		if ( empty( $reply['ok'] ) ) {
			return self::out( $reply );
		}
		$card = $reply['data']['card'] ?? array();
		// The hub's card must be the invoice the locks checked (leading zeros aside), and the link
		// is read for the number the locks checked.
		$same = ltrim( (string) ( $card['invoice_no'] ?? '' ), '0' ) === ltrim( (string) $no, '0' );
		if ( ! $same || ! self::may_see_name( $card, $kind, $no ) || ZCH_Access::is_kiosk( get_current_user_id() ) || ! class_exists( 'ZCH_Invoice_Doc' ) ) {
			return self::not_yours();
		}
		$r = ZCH_Invoice_Doc::link_for( (string) $no );
		if ( ! empty( $r['ok'] ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'data' => array( 'link' => $r['link'] ) ), 200 );
		}
		$why   = (string) ( $r['why'] ?? 'no_link' );
		$words = self::link_words();
		return new WP_REST_Response( array( 'ok' => false, 'error' => array( 'code' => $why, 'message' => $words[ $why ] ?? $words['no_link'] ) ), 200 );
	}

	public static function pivot( WP_REST_Request $req ) {
		if ( ! self::may( 'pivot' ) ) {
			return self::forbidden();
		}
		$p    = self::card_params( $req );
		$tid  = $req->get_param( 'template' );
		$tid  = is_string( $tid ) ? $tid : '';
		$line = $req->get_param( 'line_id' );
		if ( ! $p || ! preg_match( '/^[a-z][0-9]{2}$/D', $tid )
			|| ( null !== $line && ( ! is_string( $line ) || ! preg_match( '/^[A-Za-z0-9:_-]{1,40}$/D', $line ) ) ) ) {
			return self::bad_card();
		}
		list( $no, $kind ) = $p;
		if ( ! ZCH_Store::rate_ok( get_current_user_id() ) ) {
			return self::rate_limited();
		}
		if ( self::ledger_says_not_mine( $kind, $no ) ) {
			return self::not_yours();
		}
		// The browser names a template; the hub rebuilds the question from the card itself.
		$body = array( 'invoice_no' => $no, 'kind' => $kind, 'template' => $tid );
		if ( null !== $line ) {
			$body['line_id'] = $line;
		}
		return self::out( self::hub( 'pivot', $body ), array( __CLASS__, 'with_links' ) );
	}

	public static function run( WP_REST_Request $req ) {
		$spec = ZCH_Store::clean_spec( (array) $req->get_param( 'spec' ) );
		$body = array( 'spec' => $spec, 'caption' => (bool) $req->get_param( 'caption' ) );
		// A chip edit asks the hub to write the title again from what is now counted; a saved
		// chart keeps its owner's title (it never sends this).
		if ( $req->get_param( 'retitle' ) ) {
			$body['retitle'] = true;
		}
		return self::out( self::hub( 'run_spec', $body ), array( __CLASS__, 'chart_payload' ) );
	}

	public static function search( WP_REST_Request $req ) {
		if ( ! self::may( 'search_lines' ) ) {
			return self::forbidden();
		}
		$text    = trim( sanitize_text_field( (string) $req->get_param( 'text' ) ) );
		$filters = $req->get_param( 'filters' );
		$limit   = (int) $req->get_param( 'limit' );
		$body    = array(
			'text'    => mb_substr( $text, 0, 200 ),
			'limit'   => min( 50, max( 1, $limit ? $limit : 20 ) ),
			'filters' => is_array( $filters ) ? $filters : new stdClass(),
		);
		foreach ( array( 'date_from', 'date_to' ) as $k ) {
			$v = (string) $req->get_param( $k );
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ) {
				$body[ $k ] = $v;
			}
		}
		// Search results are shown, never stored. No link out; a line opens its invoice inside
		// Charts for whoever may open it.
		return self::out(
			self::hub( 'search_lines', $body ),
			function ( $d ) {
				if ( isset( $d['lines'] ) && is_array( $d['lines'] ) ) {
					foreach ( $d['lines'] as &$l ) {
						unset( $l['invoice_url'] );
					}
					unset( $l );
				}
				return $d;
			}
		);
	}

	public static function status() {
		if ( ! self::may( 'data_status' ) ) {
			return self::forbidden();
		}
		return self::out( self::hub( 'data_status', array() ) );
	}

	/* ── Saved charts ───────────────────────────────────────────────────── */
	// A saved chart belongs to the person who made it and to the site admin, who can see, share
	// and remove any. Sharing sends the SPEC, never the numbers: everyone who opens a shared chart
	// sees it through their own access, so a salesperson opening the owner's company chart sees
	// only their own jobs.

	public static function saved_list() {
		return new WP_REST_Response( array( 'ok' => true, 'data' => ZCH_Store::saved_visible_to( get_current_user_id() ) ), 200 );
	}

	public static function saved_save( WP_REST_Request $req ) {
		$spec = $req->get_param( 'spec' );
		if ( ! is_array( $spec ) ) {
			return self::bad_request( __( 'Nothing to save.', 'zorderz' ) );
		}
		$id = (int) $req->get_param( 'id' );
		if ( $id && ! ZCH_Store::can_manage( $id, get_current_user_id() ) ) {
			$id = 0;                       // someone else's chart: save a copy of your own.
		}
		$id = ZCH_Store::save_graph( get_current_user_id(), (string) $req->get_param( 'title' ), $spec, (string) $req->get_param( 'chart_type' ), $id );
		return new WP_REST_Response( array( 'ok' => true, 'data' => array( 'id' => $id ) ), 200 );
	}

	public static function saved_delete( WP_REST_Request $req ) {
		$ok = ZCH_Store::delete_graph( (int) $req['id'], get_current_user_id() );
		return new WP_REST_Response( array( 'ok' => $ok ), $ok ? 200 : 404 );
	}

	public static function saved_pin( WP_REST_Request $req ) {
		$ok = ZCH_Store::set_pinned( (int) $req['id'], get_current_user_id(), (bool) $req->get_param( 'pinned' ) );
		return new WP_REST_Response( array( 'ok' => $ok ), $ok ? 200 : 404 );
	}

	/** Share with people who can open Charts; the maker or the site admin only. */
	public static function saved_share( WP_REST_Request $req ) {
		$id = (int) $req['id'];
		if ( ! ZCH_Store::can_manage( $id, get_current_user_id() ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 404 );
		}
		$ids = array_filter( array_map( 'intval', (array) $req->get_param( 'user_ids' ) ) );
		$ids = array_values(
			array_filter(
				$ids,
				function ( $u ) {
					return ZCH_Access::allowed( $u );
				}
			)
		);
		ZCH_Store::set_shared( $id, $ids );
		ZCH_Store::audit( 'share', true, 'graph_' . $id, count( $ids ) );
		return new WP_REST_Response( array( 'ok' => true, 'data' => array( 'shared_with' => $ids ) ), 200 );
	}

	/** People a chart can be shared with: names only, and only those with Charts. */
	public static function people() {
		$out = array();
		foreach ( get_users( array( 'fields' => array( 'ID', 'display_name' ), 'number' => 200 ) ) as $u ) {
			if ( (int) $u->ID !== get_current_user_id() && ZCH_Access::allowed( (int) $u->ID ) ) {
				$out[] = array( 'id' => (int) $u->ID, 'name' => $u->display_name );
			}
		}
		return new WP_REST_Response( array( 'ok' => true, 'data' => $out ), 200 );
	}

	/** A saved chart is its spec: run again on open, so it shows the latest data. */
	public static function saved_run( WP_REST_Request $req ) {
		$uid = get_current_user_id();
		$g   = ZCH_Store::get_visible( (int) $req['id'], $uid );
		if ( ! $g ) {
			return new WP_REST_Response( array( 'ok' => false ), 404 );
		}
		$mine  = (int) $g['owner_user_id'] === $uid;
		$saved = array(
			'id'         => (int) $g['id'],
			'title'      => $g['title'],
			'chart_type' => $g['chart_type'],
			'mine'       => $mine,
			'can_manage' => ZCH_Store::can_manage( (int) $g['id'], $uid ),
		);
		if ( isset( $g['spec']['prospect'] ) && is_array( $g['spec']['prospect'] ) ) {     // a saved recipe.
			$reply = ZCH_Prospect::run( $g['spec']['prospect'], $uid, 'ui' );
			if ( empty( $reply['ok'] ) ) {
				return self::out( $reply );
			}
			$payload = ZCH_Prospect::for_page( (array) $reply['data'] );
			if ( empty( $payload['spec'] ) ) {           // a question back without the recipe: the saved one, so it reopens.
				$payload['spec'] = array( 'prospect' => ZCH_Prospect::clean_recipe( $g['spec']['prospect'] ), 'title' => (string) $g['title'] );
			}
			$payload['saved'] = $saved;
			return new WP_REST_Response( array( 'ok' => true, 'data' => $payload ), 200 );
		}
		$reply = self::hub( 'run_spec', array( 'spec' => $g['spec'] ) );
		if ( ! empty( $reply['ok'] ) ) {
			// A snapshot is the maker's view only, and never of a named account (it would put the
			// account's name in this site's database).
			if ( $mine && ! ZCH_Store::uses_accounts( $g['spec'] ) ) {
				ZCH_Store::put_snapshot( (int) $g['id'], $reply['data']['result'], $reply['data']['meta']['data_as_of'] ?? null );
			}
			$payload          = self::chart_payload( $reply['data'] );
			$payload['saved'] = $saved;
			return new WP_REST_Response( array( 'ok' => true, 'data' => $payload ), 200 );
		}
		$snap = $mine ? ZCH_Store::get_snapshot( (int) $g['id'] ) : null;
		if ( $snap ) {       // offline: show the last aggregates with their date.
			return new WP_REST_Response(
				array(
					'ok'   => true,
					'data' => array(
						'spec'    => $g['spec'],
						'status'  => 'snapshot',
						'result'  => $snap['result'],
						'caption' => '',
						'meta'    => array( 'data_as_of' => $snap['as_of'], 'snapshot' => true, 'local' => ! empty( $reply['error']['local'] ) ),
						'saved'   => $saved,
					),
				),
				200
			);
		}
		return self::out( $reply );
	}
}
