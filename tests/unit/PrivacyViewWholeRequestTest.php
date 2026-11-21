<?php
/**
 * ZDZ_Privacy_View: the gate reads the WHOLE request from an account in Privacy View, not only its name.
 *
 * WordPress runs every plugin's init and admin_init handlers on admin-ajax.php before the action's own handler, so a
 * settings handler that looks for its own fields would run beside an allowed read, with the account's rights. So:
 *
 *   - an allowed action carries only its own fields (a row per action; "action" and "nonce" are every row's), by POST
 *     with nothing in the address (or, for the messaging app's three reads, by GET with no body), to admin-ajax.php
 *     itself; its action is written once and is the name WordPress runs; it uploads nothing;
 *   - the address as written is name=value pairs of plain characters and the same names PHP read;
 *   - no body is left that PHP did not turn, whole, into those fields;
 *   - a REST call is one only where WordPress routes one; wp-login.php (to sign out) and the pages the account could
 *     already open are held the same way; no sign-in route or page is the account's;
 *   - Core's rows are Core's: an app declares its own reads on the filters, and no filter widens a row, opens a write
 *     by REST, or hands the account a sign-in route;
 *   - a refusal says one word from a fixed list, never anything the request wrote.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/privacy-view-gate-doubles.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/messaging/includes/class-zim-privacy-view.php';   // the messaging app narrows the direct-message actions

final class PrivacyViewWholeRequestTest extends TestCase {

	const R = '/zorderz/v1/notifications/unread-count';

	/**
	 * Field names that handlers on plugins_loaded, init, admin_init, template_redirect and the sign-in hooks read in
	 * this repository (a pattern survey of the theme and the apps), with WordPress's own and the sign-in plugin's.
	 * No row may hold one: a request carrying it could set such a handler off. ("action" and "nonce" are every
	 * request's; a handler that reads them needs another field as well.)
	 */
	const TRIGGERS = array( 'page', 'code', 'state', 'error', 'error_description', 'option_page', 'confirm', 'decision', 'provider', 'pack', 'item', 'item_id',
		'new_id', 'new_name', 'scheme', 'scheme_id', 'subtype', 'zdz_bp', 'zdz_bp_action', 'zdz_brand', 'zdz_bug_action', 'zdz_bug_id', 'zdz_new_status',
		'zdz_cred_managed', 'zdz_docs', 'zdz_embed', 'zdz_ie_action', 'zdz_media', 'zdz_view_as', 'zdz_view_as_user', 'zest_doc', 'zest_inv', 'zib_oauth',
		'zim_page', 'zjob_form_key', 'zjob_form_map', 'zjob_forms_action', 'zjob_photo', 'zjob_settings_save', 'zjob_solo_operator',
		'zjob_workers_may_self_schedule', 'zrcpt_from_cutter', 'zsch_conncal_save', 'zsch_oauth', 'zsch_page', 'zsch_settings_save', 'client_id',
		'client_secret', 'tenant_id', 'default_tz', 'sync_enabled', 'cc_conflict_policy', 'cc_enabled', 'cc_google_client_id', 'cc_google_secret',
		'cc_ms_client_id', 'cc_ms_secret', 'cc_work_domains', 'magic-login', 'magic_login_token', 'token', 'user_id', 'log', 'pwd', 'key', 'login',
		'redirect_to', 'plugin', 'slug', 'tab', 'mode', '_method', '_wpnonce', '_wp_http_referer', 'submit', 'rest_route', 'cb', 't', 's', 'doing_wp_cron',
		'customize_changeset_uuid' );

	protected function setUp(): void {
		ZDZ_Test_Kpv_Request::reset();
		ZDZ_Test_Kpv_Request::people();
		$GLOBALS['zdz_test_options'][ ZDZ_Privacy_View::DM_OPTION ] = ZDZ_Test_Kpv_Request::ADMIN;   // an administrator was chosen for it
	}

	protected function tearDown(): void {
		ZDZ_Test_Kpv_Request::reset();
	}

	private static function hit( $kind, array $get = array(), array $post = array(), array $o = array(), array $files = array() ) {
		return ZDZ_Test_Kpv_Request::hit( $kind, $get, $post, $o, $files );
	}

	private static function own( $action, array $extra = array() ) {
		return ZDZ_Test_Kpv_Request::own( $action, $extra );
	}

	/* ── the stand-ins answer as WordPress does ─────────────────────────────── */

	public function test_the_request_kinds_are_told_apart(): void {
		self::hit( 'ajax', array(), self::own( 'zana_list_sessions' ) );
		$this->assertSame( 'ajax', ZDZ_Privacy_View::request_kind(), 'wp_doing_ajax() must answer its own filter, and is_admin() the current screen, as WordPress defines them' );
		self::hit( 'rest' );
		$this->assertSame( 'rest', ZDZ_Privacy_View::request_kind() );
		self::hit( 'login' );
		$this->assertSame( 'login', ZDZ_Privacy_View::request_kind() );
		self::hit( 'admin' );
		$this->assertSame( 'admin', ZDZ_Privacy_View::request_kind() );
		self::hit( 'front' );
		$this->assertSame( 'front', ZDZ_Privacy_View::request_kind() );
	}

	/* ── a handler's fields beside an allowed read ──────────────────────────── */

	public function test_a_read_carrying_another_handlers_fields_is_refused(): void {
		$this->assertNotSame( '', self::hit( 'ajax', array(), array( 'action' => 'zana_list_sessions', 'nonce' => 'n', 'option_page' => 'example_settings', 'zsch_settings_save' => '1' ) ),
			'a chat read with a settings save\'s fields in its body' );
		$this->assertNotSame( '', self::hit( 'ajax', array( 'page' => 'zdz-core-settings', 'code' => 'example' ), array( 'action' => 'zana_list_sessions', 'nonce' => 'n' ) ),
			'a chat read with a sign-in return\'s ?page=...&code= beside it' );
		$this->assertNotSame( '', self::hit( 'ajax', array( 'zdz_view_as' => 'zdz_sales' ), array( 'action' => 'zdz_jobs_projects_list', 'nonce' => 'n' ) ),
			'a Projects read with an init handler\'s key beside it' );
	}

	/* ── the lists ───────────────────────────────────────────────────────────── */

	public function test_every_action_has_a_row_and_the_rows_are_exactly_these(): void {
		$all  = ZDZ_Test_Kpv_Request::all();
		$rows = ZDZ_Privacy_View::AJAX_KEYS;
		$this->assertSame( array(), array_values( array_diff( $all, array_keys( $rows ) ) ), 'every action the account may run has a row' );
		$this->assertSame( array(), array_values( array_diff( array_keys( $rows ), $all ) ), 'and no row is for an action it may not run' );
		foreach ( $all as $action ) {
			$this->assertTrue( ZDZ_Privacy_View::ajax_allowed( $action ), $action . ' is open (an administrator chosen, the messaging app narrowing)' );
		}
		// The rows, written out a second time: a field added to a row, or taken from one, has to be meant twice.
		$want = array(
			'zana_send_message'          => array( 'message', 'session_id' ),
			'zana_load_session'          => array( 'session_id' ),
			'zana_list_sessions'         => array(),
			'zana_list_memories'         => array(),
			'zana_cancel_pending_action' => array( 'id', 'session_id' ),
			'zana_refresh_nonce'         => array(),
			'zdz_jobs_projects_list'     => array( 'view' ),
			'zdz_jobs_project_detail'    => array( 'project_id' ),
			'zdz_fresh_nonce'            => array(),
			'zana_confirm_zim_dm'        => array( 'id', 'session_id' ),
			'zim_sidebar'                => array(),
			'zim_fetch_before'           => array( 'conversation_id', 'before' ),
			'zim_poll'                   => array( 'conversation_id', 'since' ),
			'zim_mark_read'              => array( 'conversation_id', 'message_id' ),
			'zim_post'                   => array( 'conversation_id', 'body', 'attachment_ids', 'share_refs' ),
		);
		ksort( $want );
		ksort( $rows );
		$this->assertSame( $want, $rows );
		$this->assertSame( array( 'action', 'nonce' ), ZDZ_Privacy_View::AJAX_BASE_KEYS );
		$this->assertSame( array( 'zim_sidebar', 'zim_fetch_before', 'zim_poll' ), ZDZ_Privacy_View::AJAX_GET, 'the only GETs are the messaging app\'s three reads' );
		$this->assertSame( array( 'rest_route', '_wpnonce' ), ZDZ_Privacy_View::REST_KEYS );
		$this->assertSame( array( 'action', '_wpnonce', 'redirect_to', 'loggedout', 'reauth', 'interim-login', 'wp_lang' ), ZDZ_Privacy_View::WPLOGIN_KEYS, 'WordPress\'s own keys only' );
		$this->assertSame( array( '' => array( 'cb' ), 'zdz_media' => array( 'zdz_media', 't', 's', 'v' ) ), ZDZ_Privacy_View::FRONT_KEYS );
		$this->assertSame( array( 'profile.php' => array(), 'admin.php?page=zdz-llm-usage' => array( 'page' ) ), ZDZ_Privacy_View::ALLOWED_SCREENS );
		$this->assertSame( array( 'sales-analytics', 'projects' ), ZDZ_Privacy_View::ALLOWED_APPS );
	}

	public function test_actions_nothing_answers_for_the_account_are_not_listed(): void {
		foreach ( array( 'zana_run_loopback', 'zana_confirm_pending_action', 'zana_pv_dm_run', 'zana_push_subscribe', 'zana_push_unsubscribe',
			'zana_push_test', 'zdz_step_run', 'zana_example_no_such_action', 'example_unknown_action' ) as $action ) {
			$this->assertFalse( ZDZ_Privacy_View::ajax_allowed( $action ), $action );
			$this->assertSame( 'ajax:' . $action, self::hit( 'ajax', array(), array( 'action' => $action, 'nonce' => 'n' ) ) );
		}
	}

	public function test_no_row_holds_a_field_name_an_early_handler_reads(): void {
		$in = array();
		foreach ( ZDZ_Privacy_View::AJAX_KEYS as $action => $row ) {
			foreach ( array_intersect( $row, self::TRIGGERS ) as $t ) {
				$in[] = $action . ':' . $t;
			}
		}
		$this->assertSame( array(), $in );
	}

	/** Every field a shipped script sends with a listed action is in that action's row (so the account is never refused its own page's request). */
	public function test_the_rows_hold_what_the_shipped_scripts_send(): void {
		$root    = dirname( __DIR__, 2 );
		$scripts = array(
			'zorderz-apps/apps/analytics/assets/js/chat.js',
			'zorderz-apps/apps/jobs/assets/js/projects.js',
			'zorderz-apps/apps/messaging/assets/js/widget.js',
		);
		$seen = array();
		$miss = array();
		foreach ( $scripts as $rel ) {
			$js = (string) file_get_contents( $root . '/' . $rel );
			foreach ( array_keys( ZDZ_Privacy_View::AJAX_KEYS ) as $action ) {
				// ajax( 'action', { a: ..., b: ... } ) or post( 'action', { ... } ): the names in the object written there
				if ( ! preg_match_all( '/\b(?:ajax|post)\(\s*[\'"]' . preg_quote( $action, '/' ) . '[\'"]\s*,\s*\{([^{}]*)\}/', $js, $calls ) ) {
					continue;
				}
				foreach ( $calls[1] as $object ) {
					preg_match_all( '/(?:^|[,{\s])([A-Za-z_][A-Za-z0-9_]*)\s*:/', $object, $names );
					$seen[ $action ] = array_values( array_unique( array_merge( $seen[ $action ] ?? array(), $names[1] ) ) );
					foreach ( array_diff( $names[1], ZDZ_Privacy_View::ajax_keys( $action ) ) as $extra ) {
						$miss[] = $rel . ': ' . $action . ' sends "' . $extra . '", which is not in its row';
					}
				}
			}
		}
		$this->assertSame( array(), $miss, 'add the field to the action\'s row in ZDZ_Privacy_View::AJAX_KEYS (and to the copy of the rows in this file), or the account is refused that request' );
		// the scan found the calls it is there to read (it cannot pass by finding nothing)
		$this->assertSame( array( 'message', 'session_id' ), $seen['zana_send_message'] ?? null );
		$this->assertSame( array( 'session_id' ), $seen['zana_load_session'] ?? null );
		$this->assertSame( array( 'id', 'session_id' ), $seen['zana_confirm_zim_dm'] ?? null );
		$this->assertSame( array( 'view' ), $seen['zdz_jobs_projects_list'] ?? null );
		$this->assertSame( array( 'project_id' ), $seen['zdz_jobs_project_detail'] ?? null );
		$this->assertSame( array( 'conversation_id', 'since' ), $seen['zim_poll'] ?? null );
		$this->assertSame( array( 'conversation_id', 'body', 'attachment_ids', 'share_refs' ), $seen['zim_post'] ?? null );
		// the keep-alive is sent with "action" alone
		$app = (string) file_get_contents( $root . '/zorderz/assets/js/app.js' );
		$this->assertSame( 1, preg_match( '/function zdzFreshNonce\(\) \{\s*var body = new URLSearchParams\(\);\s*body\.append\(\'action\', \'zdz_fresh_nonce\'\);\s*return fetch\(/', $app ) );
	}

	/* ── what each script really sends still goes through ───────────────────── */

	public function test_each_action_goes_through_as_its_script_sends_it(): void {
		$bad = array();
		foreach ( ZDZ_Test_Kpv_Request::all() as $action ) {
			if ( '' !== self::hit( 'ajax', array(), self::own( $action ) ) ) {
				$bad[] = $action . ' (a form)';
			}
			if ( '' !== self::hit( 'ajax', array(), array( 'action' => $action ) ) ) {
				$bad[] = $action . ' (alone)';
			}
			if ( '' !== self::hit( 'ajax', array(), self::own( $action ), array( 'type' => 'multipart/form-data; boundary=----example' ) ) ) {
				$bad[] = $action . ' (a form with parts)';
			}
			if ( in_array( $action, ZDZ_Privacy_View::AJAX_GET, true ) !== ( '' === self::hit( 'ajax', self::own( $action ) ) ) ) {
				$bad[] = $action . ' (by GET)';
			}
		}
		$this->assertSame( array(), $bad, 'as a form, alone, as a form with parts; by GET for the messaging app\'s three reads and for no other' );
		$this->assertSame( '', self::hit( 'ajax', array( 'action' => 'zim_sidebar', 'nonce' => 'a1b2c3d4e5' ) ) );
		$this->assertSame( '', self::hit( 'ajax', array( 'action' => 'zim_poll', 'nonce' => 'a1b2c3d4e5', 'conversation_id' => '4', 'since' => '120' ) ) );
		$this->assertSame( '', self::hit( 'ajax', array( 'action' => 'zim_fetch_before', 'nonce' => 'a1b2c3d4e5', 'conversation_id' => '4', 'before' => '0' ) ) );
		$this->assertSame( '', self::hit( 'ajax', array(), array( 'action' => 'zana_refresh_nonce' ) ), 'the chat\'s own keep-alive ("action" alone)' );
		$this->assertSame( '', self::hit( 'ajax', array(), array( 'action' => 'zdz_jobs_projects_list', 'nonce' => 'n', 'view' => 'all' ) ), 'the Projects list as its script sends it' );
		$this->assertSame( '', self::hit( 'ajax', array(), array( 'action' => 'zana_send_message', 'nonce' => 'n', 'session_id' => '9', 'message' => "A question?\nline two & more = 100% [ok] {\"a\":1}" ) ),
			'the chat\'s send with any text in the message (it is a value, in the body)' );
		$this->assertSame( '', self::hit( 'ajax', array(), array( 'action' => 'zim_post', 'nonce' => 'n', 'conversation_id' => '4', 'body' => 'Hello', 'attachment_ids' => array( '3' ), 'share_refs' => array( 'p:1' ) ),
			array( 'type' => 'multipart/form-data; boundary=example' ) ), 'a direct-message post with its two lists' );
		$this->assertSame( '', self::hit( 'ajax', array(), array( 'action' => 'zim_post', 'nonce' => 'n', 'conversation_id' => '4', 'body' => 'Hello' ) ), '... and without them' );
		$this->assertSame( '', self::hit( 'ajax', array(), array( 'action' => 'zdz_jobs_project_detail', 'nonce' => 'n', 'project_id' => '77' ),
			array( 'length' => 0, 'input' => 'action=zdz_jobs_project_detail&nonce=n&project_id=77' ) ), 'a form whose length the server did not pass on' );
	}

	/* ── one field more, and it is refused ───────────────────────────────────── */

	public function test_one_field_more_is_refused(): void {
		$through = array();
		foreach ( ZDZ_Test_Kpv_Request::all() as $action ) {
			foreach ( self::TRIGGERS as $t ) {
				$want = '_method' === $t ? 'override' : 'ajax-field:' . $action . ':' . $t;   // a method override is refused before anything else is read
				if ( $want !== self::hit( 'ajax', array(), self::own( $action, array( $t => '1' ) ) ) ) {
					$through[] = $action . '+' . $t . ' (body)';
				}
				if ( '' === self::hit( 'ajax', array( $t => '1' ), self::own( $action ) ) ) {
					$through[] = $action . '+' . $t . ' (address)';
				}
				if ( in_array( $action, ZDZ_Privacy_View::AJAX_GET, true ) && $want !== self::hit( 'ajax', self::own( $action, array( $t => '1' ) ) ) ) {
					$through[] = $action . '+' . $t . ' (by GET)';
				}
			}
		}
		$this->assertSame( array(), array_slice( $through, 0, 6 ), 'every action with every handler field: in the body, in the address, and in a GET' );
		$this->assertNotSame( '', self::hit( 'ajax', array(), self::own( 'zdz_jobs_projects_list', array( 'zjob_form_map' => array( 'a' => array( 'b' => '1' ) ) ) ) ), 'a field that is a list is still that field' );
		$this->assertNotSame( '', self::hit( 'ajax', array(), self::own( 'zana_load_session', array( 'example_unknown' => '' ) ) ), 'an empty field nobody listed' );
		$this->assertNotSame( '', self::hit( 'ajax', array( '0' => 'x' ), array(), array( 'request' => array( 'action' => 'zim_sidebar' ) ) ), 'a field whose name is a number' );
		$this->assertNotSame( '', self::hit( 'ajax', array(), self::own( 'zana_load_session', array( 'message' => 'x' ) ) ), 'a field of ANOTHER allowed action is not this one\'s' );
		$this->assertNotSame( '', self::hit( 'ajax', array(), self::own( 'zana_load_session', array( 'Session_ID' => '1' ) ) ), 'names are exact: no other case' );
		$this->assertNotSame( '', self::hit( 'ajax', array(), self::own( 'zana_load_session', array( 'session_id ' => '1' ) ) ), '... no space' );
		$this->assertNotSame( '', self::hit( 'ajax', array( 'session_id' => '3' ), self::own( 'zana_send_message' ) ), 'a body\'s field cannot be written in the address instead' );
		$this->assertNotSame( '', self::hit( 'ajax', self::own( 'zana_send_message' ) ), '... nor the whole request' );
		$this->assertNotSame( '', self::hit( 'ajax', array( 'action' => 'zim_poll', 'nonce' => 'n' ), array( 'conversation_id' => '4' ), array( 'method' => 'GET', 'request' => array( 'action' => 'zim_poll' ) ) ),
			'nor a GET carry fields in a body' );
	}

	/* ── the action is written once, and is the one WordPress runs ──────────── */

	public function test_the_action_is_written_once_and_is_the_one_wordpress_runs(): void {
		$this->assertNotSame( '', self::hit( 'ajax', array(), array( 'nonce' => 'n' ), array( 'request' => array( 'action' => 'zana_list_sessions', 'nonce' => 'n' ) ) ),
			'an action that is in neither the address nor the body (a cookie named "action")' );
		$this->assertNotSame( '', self::hit( 'ajax', array( 'action' => 'zana_list_sessions' ), array( 'action' => 'zsch_settings_save', 'nonce' => 'n' ), array( 'request' => array( 'action' => 'zana_list_sessions', 'nonce' => 'n' ) ) ),
			'one name in the address and another in the body (whichever WordPress reads)' );
		$this->assertNotSame( '', self::hit( 'ajax', array( 'action' => 'zsch_settings_save' ), array( 'action' => 'zana_list_sessions', 'nonce' => 'n' ) ), '... the other way round' );
		$this->assertNotSame( '', self::hit( 'ajax', array( 'action' => 'zana_list_sessions' ), array( 'action' => 'zana_list_sessions', 'nonce' => 'n' ) ), '... and the same name in both' );
		$this->assertNotSame( '', self::hit( 'ajax', array( 'action' => 'zim_sidebar', 'nonce' => 'n' ), array(), array( 'uri' => '/wp-admin/admin-ajax.php?action=zim_sidebar&action=zim_sidebar&nonce=n' ) ), '... or twice in the address' );
		$this->assertNotSame( '', self::hit( 'ajax', array(), array( 'action' => array( 'zana_list_sessions' ), 'nonce' => 'n' ) ), 'an action that is a list' );
		$this->assertNotSame( '', self::hit( 'ajax', array( 'action' => array( 'zim_sidebar' ) ), array(), array( 'request' => array( 'action' => 'zim_sidebar' ) ) ), '... in the address' );
		foreach ( array( 'ZANA_LIST_SESSIONS', 'zana_list_sessions ', ' zana_list_sessions', "zana_list_sessions\0", 'zana_list_sessions;', 'zana_list_sessions%00', 'zana.list.sessions' ) as $odd ) {
			$this->assertNotSame( '', self::hit( 'ajax', array(), array( 'action' => $odd, 'nonce' => 'n' ) ), 'not the name itself: ' . json_encode( $odd ) );
		}
	}

	/* ── the address says only what PHP read from it ─────────────────────────── */

	public function test_the_address_is_held_to_what_php_read_from_it(): void {
		$a = '/wp-admin/admin-ajax.php?action=zim_sidebar&nonce=n';
		$g = array( 'action' => 'zim_sidebar', 'nonce' => 'n' );
		$this->assertSame( '', self::hit( 'ajax', $g, array(), array( 'uri' => $a ) ), 'the messaging list, address and fields agreeing' );
		foreach ( array(
			'&=code=x'       => 'a pair with no name (PHP drops it; a handler that searches the address for "code=" would not)',
			'&nonce=n'       => 'a name twice',
			'&&'             => 'an empty pair',
			'&'              => 'a trailing "&"',
			'&code'          => 'a name with no "="',
			'%26code%3Dx'    => 'characters written as %xx',
			'+code'          => 'a "+"',
			'&nonce[]=n'     => 'a name with brackets',
			'/.well-known/'  => 'a slash in a value',
			';code=x'        => 'a ";"',
			'&x=<y>'         => 'a "<"',
			"&x=\x00"        => 'a control character',
			'&x= y'          => 'a space',
			'#code=x'        => 'a "#"',
			'&x=y?z'         => 'a second "?"',
			'&='             => 'a bare "="',
			'=code=x'        => 'an "=" inside a value',
		) as $tail => $what ) {
			$this->assertNotSame( '', self::hit( 'ajax', $g, array(), array( 'uri' => $a . $tail ) ), $what );
		}
		$this->assertNotSame( '', self::hit( 'ajax', array( 'action' => 'zim_sidebar', 'nonce' => 'n=code=x' ), array(), array( 'uri' => '/wp-admin/admin-ajax.php?action=zim_sidebar&nonce=n=code=x' ) ),
			'an "=" inside a value, PHP and the address agreeing about it' );
		$this->assertNotSame( '', self::hit( 'ajax', array(), array( 'action' => 'zana_list_sessions', 'nonce' => 'n' ), array( 'uri' => '/wp-admin/admin-ajax.php?code=x' ) ), 'something in the address that PHP did not read as a field' );
		$this->assertNotSame( '', self::hit( 'ajax', $g, array(), array( 'uri' => '/wp-admin/admin-ajax.php', 'bare' => true ) ), 'fields PHP read that the address does not show' );
		$this->assertNotSame( '', self::hit( 'front', array( 'cb' => '1' ), array(), array( 'uri' => '/?cb=1&=/.well-known/' ) ), 'the app shell\'s address is held the same way' );
		$this->assertNotSame( '', self::hit( 'front', array( 'cb' => 'a b' ), array(), array( 'uri' => '/?cb=a%20b' ) ) );
		$this->assertNotSame( '', self::hit( 'admin', array(), array(), array( 'uri' => '/wp-admin/profile.php?&=code=x' ) ), 'and its two wp-admin screens\'' );
		$this->assertNotSame( '', self::hit( 'admin', array( 'page' => 'zdz-llm-usage' ), array(), array( 'uri' => '/wp-admin/admin.php?page=zdz-llm-usage&=x', 'page' => 'admin.php' ) ) );
		$this->assertSame( '', self::hit( 'admin', array( 'page' => 'zdz-llm-usage' ), array(), array( 'uri' => '/wp-admin/admin.php?page=zdz-llm-usage', 'page' => 'admin.php' ) ) );
		$this->assertSame( '', self::hit( 'front', array( 'zdz_media' => '77', 't' => 'a1b2c3', 's' => 'thumb2', 'v' => '1731300000' ) ), 'a photo\'s address is plain already' );
		$this->assertSame( '', self::hit( 'front', array( 'cb' => '2.1.0-x_1' ) ), '... and a cache-buster' );
		$this->assertSame( '', self::hit( 'front', array( 'zim_page' => '1', 'zdz_embed' => 'dock', 'zim_compact' => '1', 'c' => '12' ) ), '... and the docked direct-message thread\'s' );
	}

	/* ── the address of admin-ajax.php, the method, files ───────────────────── */

	public function test_admin_ajax_itself_by_its_own_method_with_no_file(): void {
		foreach ( array( '/wp-admin/admin-ajax.php/x', '/wp-admin/admin-ajax.php/', '/wp-admin//admin-ajax.php', '/wp-admin/./admin-ajax.php', '/wp-admin/admin-ajax.php%2Fx',
			'/wp-admin/admin-post.php', '/x/admin-ajax.php', '//wp-admin/admin-ajax.php', '/x/../wp-admin/admin-ajax.php', '/x%2f/wp-admin/admin-ajax.php', '/x\\/wp-admin/admin-ajax.php' ) as $u ) {
			$this->assertNotSame( '', self::hit( 'ajax', array(), self::own( 'zana_list_sessions' ), array( 'uri' => $u ) ), 'an AJAX request written to ' . $u );
		}
		$g = array( 'action' => 'zim_sidebar', 'nonce' => 'n' );
		foreach ( array( 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS', 'PROPFIND', 'TRACE' ) as $m ) {
			$this->assertNotSame( '', self::hit( 'ajax', $g, array(), array( 'method' => $m ) ), 'by ' . $m );
		}
		$this->assertSame( 'ajax-file:zana_send_message:example_files', self::hit( 'ajax', array(), self::own( 'zana_send_message' ), array(), array( 'example_files' => array( 'name' => array( 'x.png' ) ) ) ),
			'the account uploads nothing: a file on the chat\'s send' );
		$this->assertNotSame( '', self::hit( 'ajax', array(), self::own( 'zim_post' ), array(), array( 'file' => array( 'name' => 'x.pdf' ) ) ), '... on a direct-message post' );
		foreach ( array( 'front', 'rest', 'admin', 'login' ) as $kind ) {
			$this->assertNotSame( '', self::hit( $kind, array(), array(), array( 'method' => 'GET', 'type' => null ), array( 'x' => array( 'name' => 'x' ) ) ), 'a file on a ' . $kind . ' request' );
		}
	}

	/* ── no body is left that PHP did not turn into those fields ─────────────── */

	public function test_no_body_is_left_that_php_did_not_read_into_fields(): void {
		$s = array( 'action' => 'zana_list_sessions', 'nonce' => 'n' );
		$j = '{"zsch_settings_save":"1"}';
		$f = 'action=zana_list_sessions&nonce=n';
		$g = array( 'action' => 'zim_sidebar', 'nonce' => 'n' );
		foreach ( array( 'application/json', 'application/json; charset=utf-8', 'text/plain', 'application/xml', 'text/xml', 'application/octet-stream', 'APPLICATION/JSON' ) as $ct ) {
			$this->assertNotSame( '', self::hit( 'ajax', array(), $s, array( 'type' => $ct, 'input' => $j ) ), 'a body PHP does not read into fields (' . $ct . ')' );
		}
		$this->assertNotSame( '', self::hit( 'ajax', array(), $s, array( 'type' => null, 'input' => $j, 'length' => 0 ) ), 'a body with no type at all: seen in the body' );
		$this->assertNotSame( '', self::hit( 'ajax', array(), $s, array( 'type' => null, 'input' => '', 'length' => 40 ) ), '... in its length' );
		$this->assertNotSame( '', self::hit( 'ajax', array(), $s, array( 'type' => null, 'input' => '', 'length' => 0, 'te' => 'chunked' ) ), '... or in how it was sent' );
		$this->assertNotSame( '', self::hit( 'ajax', array(), $s, array( 'type' => 'application/json', 'input' => '', 'length' => 0 ) ), 'a POST whose type is not a form, even with nothing in it' );
		$this->assertNotSame( '', self::hit( 'ajax', array(), $s, array( 'type' => 'application/json', 'http_type' => true, 'input' => '', 'length' => 0 ) ), 'the type under PHP\'s other name for it' );
		// the type is read as PHP reads it: the whole word, not its beginning and not a word inside it
		$this->assertSame( '', self::hit( 'ajax', array(), $s, array( 'input' => $f ) ) );
		foreach ( array( 'application/x-www-form-urlencodedx', 'multipart/form-data-x', 'application/json; x=multipart/form-data' ) as $ct ) {
			$this->assertNotSame( '', self::hit( 'ajax', array(), $s, array( 'type' => $ct, 'input' => '', 'length' => 0 ) ), 'a type that begins like a form\'s, or has one inside it: ' . $ct );
			$this->assertNotSame( '', self::hit( 'ajax', array(), $s, array( 'type' => $ct, 'input' => $j ) ), '... with a body PHP parses nothing of' );
			$this->assertNotSame( '', self::hit( 'ajax', array(), $s, array( 'type' => $ct, 'input' => $f ) ), '... even with a body that reads like the fields themselves' );
		}
		$this->assertNotSame( '', self::hit( 'ajax', $g, array(), array( 'method' => 'GET', 'type' => 'application/x-www-form-urlencoded', 'input' => $j ) ), 'a GET with a form\'s type and a body (PHP parses bodies only on POST)' );
		$this->assertNotSame( '', self::hit( 'front', array( 'cb' => '1' ), array(), array( 'type' => 'application/x-www-form-urlencoded', 'input' => $j ) ), '... on the app shell' );
		$this->assertNotSame( '', self::hit( 'rest', array(), array(), array( 'type' => 'application/x-www-form-urlencoded', 'input' => $j ) ), '... on a REST read' );
		$this->assertNotSame( '', self::hit( 'admin', array(), array(), array( 'type' => 'multipart/form-data; boundary=z', 'input' => $j ) ), '... on a wp-admin screen' );
		$this->assertNotSame( '', self::hit( 'ajax', array(), $s, array( 'input' => $f . '&[' . $j . ']' ) ), 'a pair whose name begins with "[" (PHP drops it)' );
		$this->assertNotSame( '', self::hit( 'ajax', array(), $s, array( 'input' => $f . '&%5Bx%5D=1' ) ) );
		$this->assertNotSame( '', self::hit( 'ajax', array(), $s, array( 'type' => 'multipart/form-data', 'input' => $j ) ), 'a form with parts that PHP gave up on (no boundary): something of it is left' );
		$this->assertNotSame( '', self::hit( 'ajax', array(), $s, array( 'type' => 'multipart/form-data; boundary=x', 'input' => "--x\r\n" . $j ) ) );
		$this->assertNotSame( '', self::hit( 'ajax', $g, array(), array( 'method' => 'POST', 'type' => 'application/x-www-form-urlencoded', 'input' => 'x=' . str_repeat( 'y', 4000 ) . '&' . $j ) ),
			'a form over PHP\'s size limit ($_POST comes empty, the body is still there)' );
		foreach ( array(
			'=' . $j          => 'a pair with no name',
			$j                => 'no "=" at all',
			$f . '&'          => 'a trailing "&"',
			$f . '&x=1'       => 'a pair PHP did not put in $_POST',
			'action=zana_list_sessions' => 'fewer pairs than $_POST has',
			$f . '&a.b=1'     => 'a name PHP rewrites (a dot)',
			$f . '&a+b=1'     => 'a name PHP rewrites (a space)',
		) as $raw => $what ) {
			$this->assertNotSame( '', self::hit( 'ajax', array(), $s, array( 'input' => $raw ) ), 'an ordinary form whose text is not its fields: ' . $what );
		}
		$this->assertNotSame( '', self::hit( 'ajax', array(), $s, array( 'input' => 'action=zsch_settings_save&action=zana_list_sessions&nonce=n' ) ), 'a plain name twice in a form (PHP keeps the last; a handler reading the text might take the first)' );
		$this->assertNotSame( '', self::hit( 'ajax', array(), $s, array( 'input' => 'action=zana_list_sessions&nonce=a&nonce=n' ) ) );
		$this->assertSame( '', self::hit( 'ajax', array(), array( 'action' => 'zim_post', 'nonce' => 'n', 'conversation_id' => '4', 'body' => 'x', 'attachment_ids' => array( '3', '5' ) ),
			array( 'input' => 'action=zim_post&nonce=n&conversation_id=4&body=x&attachment_ids%5B%5D=3&attachment_ids%5B%5D=5' ) ), '... a list\'s name comes once per item, as ever' );
		$big = 'action=zana_list_sessions&nonce=' . str_repeat( 'n', 300000 );
		$this->assertNotSame( '', self::hit( 'ajax', array(), array( 'action' => 'zana_list_sessions', 'nonce' => str_repeat( 'n', 300000 ) ), array( 'input' => $big ) ), 'an ordinary form far longer than any its scripts send' );
		$this->assertNotSame( '', self::hit( 'ajax', array(), $s, array( 'input' => $f, 'length' => 300000 ) ), '... by its stated length' );
		$this->assertNotSame( '', self::hit( 'ajax', array(), array( 'action' => 'zana_list_sessions', 'nonce' => str_repeat( 'n', 300000 ) ), array( 'input' => $big, 'length' => 0 ) ), '... or by what is there' );
		$this->assertNotSame( '', self::hit( 'ajax', $g, array(), array( 'method' => 'GET', 'type' => null, 'input' => '', 'length' => 9 ) ), 'a GET that carries anything: by its length' );
		$this->assertNotSame( '', self::hit( 'ajax', $g, array(), array( 'method' => 'GET', 'type' => null, 'input' => 'x', 'length' => 0 ) ), '... or by what is there' );
		$this->assertSame( '', self::hit( 'ajax', $g, array(), array( 'method' => 'GET', 'type' => 'application/json', 'input' => '' ) ), 'a GET that only NAMES a type and sends nothing is still a GET' );
		$this->assertSame( '', self::hit( 'rest', array(), array(), array( 'type' => 'application/json', 'input' => '' ) ) );
		foreach ( array( 'Application/X-WWW-Form-URLEncoded', ' MULTIPART/FORM-DATA; boundary=x', 'application/x-www-form-urlencoded;charset=UTF-8' ) as $ct ) {
			$this->assertSame( '', self::hit( 'ajax', array(), self::own( 'zana_load_session' ), array( 'type' => $ct ) + ( false !== stripos( $ct, 'multipart' ) ? array( 'input' => '' ) : array() ) ),
				'a form\'s type in capitals, or with its charset, is still a form: ' . $ct );
		}
	}

	/* ── an app's own declaration ────────────────────────────────────────────── */

	public function test_an_app_declares_its_own_reads_and_cannot_widen_cores(): void {
		$this->assertNotSame( '', self::hit( 'ajax', array(), array( 'action' => 'example_read', 'nonce' => 'n' ) ), 'not declared: closed' );
		$GLOBALS['zdz_test_filters']['zdz_privacy_view_allowed_ajax'] = function ( $list ) {
			return array_merge( (array) $list, array( 'example_read' ) );
		};
		$this->assertSame( '', self::hit( 'ajax', array(), array( 'action' => 'example_read', 'nonce' => 'n' ) ), 'an action an app adds may carry "action" and "nonce" ...' );
		$this->assertSame( 'ajax-field:example_read:example_id', self::hit( 'ajax', array(), array( 'action' => 'example_read', 'nonce' => 'n', 'example_id' => '4' ) ), '... and nothing more until it names its fields' );
		$GLOBALS['zdz_test_filters']['zdz_privacy_view_ajax_keys'] = function ( $rows ) {
			return array(
				'example_read'       => array( 'example_id', 7, array( 'x' ), 'bad name', 'action' ),   // only plain names are taken
				'zana_list_sessions' => array( 'page', 'code' ),                                          // a row of Core's: not the app's to change
				'example_unlisted'   => array( 'example_id' ),                                            // a row for an action nobody allowed
			) + (array) $rows;
		};
		$this->assertSame( '', self::hit( 'ajax', array(), array( 'action' => 'example_read', 'nonce' => 'n', 'example_id' => '4' ) ), '... then those too' );
		$this->assertSame( array( 'action', 'nonce', 'example_id' ), ZDZ_Privacy_View::ajax_keys( 'example_read' ) );
		$this->assertNotSame( '', self::hit( 'ajax', array( 'action' => 'example_read', 'nonce' => 'n', 'example_id' => '4' ) ), '... by POST only' );
		$this->assertNotSame( '', self::hit( 'ajax', array(), array( 'action' => 'example_read', 'nonce' => 'n' ), array(), array( 'example_files' => array( 'name' => array( 'x.png' ) ) ) ), '... and with no upload' );
		$this->assertNotSame( '', self::hit( 'ajax', array(), array( 'action' => 'zana_list_sessions', 'nonce' => 'n', 'page' => 'zdz-core-settings', 'code' => 'x' ) ), 'that list cannot widen an action Core lists itself' );
		$this->assertSame( array( 'action', 'nonce' ), ZDZ_Privacy_View::ajax_keys( 'zana_list_sessions' ) );
		$this->assertNotSame( '', self::hit( 'ajax', array(), array( 'action' => 'example_unlisted', 'nonce' => 'n', 'example_id' => '4' ) ), 'nor open an action nobody allowed' );
		unset( $GLOBALS['zdz_test_filters']['zdz_privacy_view_allowed_ajax'], $GLOBALS['zdz_test_filters']['zdz_privacy_view_ajax_keys'] );
		$this->assertNotSame( '', self::hit( 'ajax', array(), array( 'action' => 'example_read', 'nonce' => 'n' ) ), 'and without the app, it is closed again' );
	}

	public function test_a_filter_cannot_reopen_the_direct_message_actions_or_take_cores_rows(): void {
		$GLOBALS['zdz_test_options'][ ZDZ_Privacy_View::DM_OPTION ] = 0;   // nobody chosen
		$GLOBALS['zdz_test_filters']['zdz_privacy_view_allowed_ajax'] = function ( $list ) {
			return array_merge( (array) $list, array( 'zim_post', 'ZIM_SIDEBAR', 'Example_Read', 'example read', 'example.read', '', 42, array( 'x' ) ) );
		};
		$this->assertFalse( ZDZ_Privacy_View::ajax_allowed( 'zim_post' ), 'the direct-message actions answer to the chosen administrator only' );
		$this->assertFalse( ZDZ_Privacy_View::ajax_allowed( 'example_read' ), 'a name that is not lower-case letters, digits, _ and - is not taken' );
		$this->assertFalse( ZDZ_Privacy_View::ajax_allowed( 'example read' ), '... asked for by exactly that name either (a space)' );
		$this->assertFalse( ZDZ_Privacy_View::ajax_allowed( 'example.read' ), '... or a dot' );
		$this->assertSame( array(), ZDZ_Privacy_View::declared_now()['ajax'] );
		// a filter may take one of Core's away (narrowing), never add to its row
		$GLOBALS['zdz_test_filters']['zdz_privacy_view_allowed_ajax'] = function ( $list ) {
			return array_values( array_diff( (array) $list, array( 'zdz_jobs_projects_list' ) ) );
		};
		$this->assertFalse( ZDZ_Privacy_View::ajax_allowed( 'zdz_jobs_projects_list' ) );
		$this->assertTrue( ZDZ_Privacy_View::ajax_allowed( 'zdz_jobs_project_detail' ) );
		$GLOBALS['zdz_test_filters']['zdz_privacy_view_allowed_ajax'] = function () {
			return 'everything';   // nonsense changes nothing
		};
		$this->assertFalse( ZDZ_Privacy_View::ajax_allowed( 'example_read' ) );
	}

	/** An app made safe for the account opens look-only reads the way the Estimates app does: its id, its reads, their fields. */
	public function test_an_app_opens_look_only_reads_by_declaring_them(): void {
		$reads  = array( 'example_list_open', 'example_list_past', 'example_one', 'example_history' );
		$others = array( 'example_create', 'example_update', 'example_delete', 'example_send', 'example_search', 'example_one_edit', 'example_list_open_all', 'example_', 'example' );
		// without the app's own declaration: not listed, each read refused
		$this->assertFalse( ZDZ_Privacy_View::app_allowed( 'example-app' ) );
		foreach ( $reads as $a ) {
			$this->assertFalse( ZDZ_Privacy_View::ajax_allowed( $a ), $a );
		}
		$this->assertSame( 'ajax:example_list_open', self::hit( 'ajax', array(), array( 'action' => 'example_list_open', 'nonce' => 'n' ) ) );
		$this->assertTrue( ZDZ_Privacy_View::app_allowed( 'sales-analytics' ) );
		$this->assertTrue( ZDZ_Privacy_View::app_allowed( 'projects' ) );
		foreach ( array( '', 'internal-messaging', 'projects ', 'Projects', 'estimate-creator' ) as $id ) {
			$this->assertFalse( ZDZ_Privacy_View::app_allowed( $id ), 'no other app, and no near-miss of a name: ' . json_encode( $id ) );
		}
		$this->assertFalse( ZDZ_Privacy_View::app_allowed( array( 'projects' ) ) );
		// the app declares itself
		$GLOBALS['zdz_test_filters']['zdz_privacy_view_allowed_apps'] = function ( $apps ) {
			$apps[] = 'example-app';
			return $apps;
		};
		$GLOBALS['zdz_test_filters']['zdz_privacy_view_allowed_ajax'] = function ( $list ) use ( $reads ) {
			return array_merge( (array) $list, $reads );
		};
		$GLOBALS['zdz_test_filters']['zdz_privacy_view_ajax_keys'] = function ( $rows ) {
			$rows['example_one']     = array( 'example_id' );
			$rows['example_history'] = array( 'ref' );
			return $rows;
		};
		$this->assertTrue( ZDZ_Privacy_View::app_allowed( 'example-app' ), 'the app is listed' );
		$this->assertNotContains( 'example-app', ZDZ_Privacy_View::ALLOWED_APPS, 'and it is not in Core\'s own list' );
		foreach ( $reads as $a ) {
			$this->assertTrue( ZDZ_Privacy_View::ajax_allowed( $a ), $a );
			$this->assertSame( '', self::hit( 'ajax', array(), self::own( $a ) ), 'the gate lets ' . $a . ' through with its own fields' );
		}
		foreach ( $others as $a ) {
			$this->assertFalse( ZDZ_Privacy_View::ajax_allowed( $a ), 'nothing else of the app: ' . $a );
			$this->assertSame( 'ajax:' . preg_replace( '/[^a-z0-9_\-]/', '', $a ), self::hit( 'ajax', array(), array( 'action' => $a, 'nonce' => 'n' ) ) );
		}
		$this->assertSame( ZDZ_Privacy_View::ajax_allowed( 'EXAMPLE_ONE' ), ZDZ_Privacy_View::ajax_allowed( 'example_one' ), 'names compare in lower case' );
		$this->assertFalse( ZDZ_Privacy_View::ajax_allowed( 'example_one ' ) );
		$this->assertFalse( ZDZ_Privacy_View::ajax_allowed( 'example_one_x' ) );
		$this->assertSame( 'ajax-field:example_one:option_page', self::hit( 'ajax', array(), self::own( 'example_one', array( 'option_page' => 'x' ) ) ), 'one field more is refused for a declared read too' );
		$this->assertSame( 'ajax-field:example_list_open:example_id', self::hit( 'ajax', array(), self::own( 'example_list_open', array( 'example_id' => '5' ) ) ), 'a read that declared no field carries none' );
		// a team member (Privacy View off) is never this code's business
		$GLOBALS['zdz_test_current_user'] = ZDZ_Test_Kpv_Request::MEMBER;
		ZDZ_Test_Kpv_Request::build( 'ajax', array(), array( 'action' => 'example_send', 'nonce' => 'n' ) );
		ZDZ_Privacy_View::gate();
		ZDZ_Privacy_View::guard_admin();
		$this->assertFalse( ZDZ_Privacy_View::is_on() );
	}

	public function test_the_app_shell_asks_app_allowed(): void {
		$api = (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-plugin-api.php' );
		$this->assertSame( 2, substr_count( $api, 'ZDZ_Privacy_View::app_allowed( ' ), 'both places: the list of apps, and may-this-user-open-it' );
		$this->assertSame( 0, preg_match( '/in_array\([^;]*ZDZ_Privacy_View::ALLOWED_APPS/', $api ) );
	}

	/* ── a REST call is one only where WordPress routes one ──────────────────── */

	public function test_a_rest_call_is_one_only_where_wordpress_routes_one(): void {
		$r = self::R;
		$this->assertSame( '', self::hit( 'rest' ), 'the bell\'s count: bare' );
		$this->assertSame( '', self::hit( 'rest', array( '_wpnonce' => 'a1b2c3' ) ), '... with a nonce' );
		$this->assertSame( '', self::hit( 'rest', array(), array(), array( 'uri' => '/wp-json' . $r . '/' ) ), '... with a closing slash' );
		$this->assertSame( '', self::hit( 'rest', array(), array(), array( 'method' => 'HEAD' ) ), '... by HEAD' );
		$this->assertSame( '', self::hit( 'rest', array( 'rest_route' => $r ), array(), array( 'uri' => '/?rest_route=' . $r ) ), '... by ?rest_route= at the home address' );
		$this->assertSame( '', self::hit( 'rest', array( 'rest_route' => $r ), array(), array( 'uri' => '/index.php?rest_route=' . $r ) ) );
		$this->assertSame( '', self::hit( 'rest', array(), array(), array( 'uri' => '/index.php/wp-json' . $r ) ), '... or through /index.php/' );
		$this->assertSame( '', self::hit( 'rest', array(), array(), array( 'uri' => '/wp-json/zorderz/v1/consent' ) ), 'its own usage-records consent' );
		foreach ( array( 'users.php', 'options.php', 'post-new.php', 'plugins.php', 'plugin-editor.php', 'theme-editor.php', 'update-core.php', 'export.php', 'site-health.php', 'index.php', 'options-permalink.php', 'admin.php' ) as $scr ) {
			$this->assertNotSame( '', self::hit( 'admin', array( 'rest_route' => $r ), array(), array( 'uri' => '/wp-admin/' . $scr . '?rest_route=' . $r, 'page' => $scr ) ),
				'wp-admin/' . $scr . ' with ?rest_route=<the bell\'s count> is a wp-admin screen, and refused' );
		}
		$this->assertNotSame( '', self::hit( 'admin', array(), array(), array( 'uri' => '/wp-admin/options.php/wp-json' . $r, 'page' => 'options.php' ) ), '... also written as a path after the screen' );
		$this->assertNotSame( '', self::hit( 'admin', array(), array(), array( 'uri' => '/wp-admin/users.php', 'page' => 'users.php' ) ), '... and the screen itself, as ever' );
		$this->assertNotSame( '', self::hit( 'login', array( 'rest_route' => $r ), array(), array( 'uri' => '/wp-login.php?rest_route=' . $r ) ), 'wp-login.php with it is still wp-login.php' );
		foreach ( array( 'wp-cron.php', 'wp-links-opml.php', 'wp-comments-post.php', 'wp-trackback.php', 'wp-signup.php', 'wp-activate.php', 'wp-mail.php', 'xmlrpc.php' ) as $scr ) {
			$this->assertNotSame( '', self::hit( 'front', array( 'rest_route' => $r ), array(), array( 'uri' => '/' . $scr . '?rest_route=' . $r, 'page' => $scr ) ), '/' . $scr . ' with ?rest_route=' );
			$this->assertNotSame( '', self::hit( 'front', array(), array(), array( 'uri' => '/' . $scr, 'page' => $scr ) ), '/' . $scr . ' without' );
		}
		foreach ( array( '/jobs/wp-json' . $r, '/x/wp-json' . $r, '/wp-jsonx' . $r, '/wp-json/../wp-json' . $r, '//wp-json' . $r, '/index.php/x/wp-json' . $r, '/jobs/?rest_route=' . $r ) as $u ) {
			$g = array();
			parse_str( (string) parse_url( 'http://x' . ( 0 === strpos( $u, '//' ) ? '/x' : '' ) . $u, PHP_URL_QUERY ), $g );
			$this->assertNotSame( '', self::hit( 'rest', $g, array(), array( 'uri' => $u ) ), '"/wp-json/" or ?rest_route= somewhere else in an address: ' . $u );
		}
		foreach ( array( 'zdz_view_as', 'page', 'zdz_docs', 'magic-login', '_method', 'cb', '_locale', '_jsonp', '_envelope', '_' ) as $t ) {
			$this->assertNotSame( '', self::hit( 'rest', array( $t => '1' ) ), 'the bell\'s count with ?' . $t . '= beside it' );
			$this->assertNotSame( '', self::hit( 'rest', array( $t => '1' ), array(), array( 'method' => 'HEAD' ) ), '... by HEAD' );
		}
		$this->assertNotSame( '', self::hit( 'rest', array( 'rest_route' => array( $r ) ), array(), array( 'uri' => '/?rest_route[]=' . $r ) ), 'a route that is a list' );
		$this->assertNotSame( '', self::hit( 'rest', array( '_wpnonce' => 'a/b' ), array(), array( 'uri' => '/wp-json' . $r . '?_wpnonce=a%2Fb' ) ), 'a value written as %xx' );
		$this->assertNotSame( '', self::hit( 'rest', array(), array(), array( 'uri' => '/wp-json/zorderz/v1/example' ) ), 'a route not listed' );
		$this->assertNotSame( '', self::hit( 'rest', array(), array( 'x' => '1' ) ), 'a POST' );
	}

	public function test_a_site_in_a_folder_has_its_own_addresses(): void {
		$r                        = self::R;
		$GLOBALS['zdz_test_home'] = 'https://app.example.com/shop';
		$this->assertSame( '', self::hit( 'rest', array(), array(), array( 'uri' => '/shop/wp-json' . $r ) ) );
		$this->assertSame( '', self::hit( 'rest', array( 'rest_route' => $r ), array(), array( 'uri' => '/shop/?rest_route=' . $r ) ) );
		$this->assertSame( '', self::hit( 'rest', array(), array(), array( 'uri' => '/shop/index.php/wp-json' . $r ) ) );
		$this->assertSame( '', self::hit( 'front', array( 'cb' => '1' ), array(), array( 'uri' => '/shop/?cb=1' ) ), 'its own home address' );
		$this->assertNotSame( '', self::hit( 'rest', array(), array(), array( 'uri' => '/wp-json' . $r ) ), 'not the ones above it' );
		$this->assertNotSame( '', self::hit( 'rest', array( 'rest_route' => $r ), array(), array( 'uri' => '/?rest_route=' . $r ) ) );
		$this->assertNotSame( '', self::hit( 'rest', array(), array(), array( 'uri' => '/shopx/wp-json' . $r ) ), '... or beside it' );
		$this->assertNotSame( '', self::hit( 'rest', array(), array(), array( 'uri' => '/shop/x/wp-json' . $r ) ) );
	}

	public function test_a_filter_adds_a_rest_read_and_never_a_write_or_a_sign_in_route(): void {
		$GLOBALS['zdz_test_filters']['zdz_privacy_view_allowed_rest'] = function ( $routes ) {
			return array_merge( (array) $routes, array( '/zorderz/v1/example-read', '/zorderz/v1/magic-link-status', '/zorderz/v1/magic-link-code-claim' ) );
		};
		$this->assertTrue( ZDZ_Privacy_View::rest_allowed( '/zorderz/v1/example-read', 'GET' ) );
		$this->assertSame( '', self::hit( 'rest', array(), array(), array( 'uri' => '/wp-json/zorderz/v1/example-read' ) ) );
		foreach ( array( 'POST', 'PUT', 'PATCH', 'DELETE' ) as $m ) {
			$this->assertFalse( ZDZ_Privacy_View::rest_allowed( '/zorderz/v1/example-read', $m ), 'reads only, whatever a filter lists: ' . $m );
		}
		$this->assertFalse( ZDZ_Privacy_View::rest_allowed( '/zorderz/v1/magic-link-status', 'GET' ), 'the account gets no sign-in route, whatever a filter lists' );
		$this->assertFalse( ZDZ_Privacy_View::rest_allowed( '/zorderz/v1/magic-link-code-claim', 'GET' ) );
		$this->assertNotSame( '', self::hit( 'rest', array(), array(), array( 'uri' => '/wp-json/zorderz/v1/magic-link-status' ) ) );
	}

	/* ── sign-in addresses are never the account's ───────────────────────────── */

	public function test_no_sign_in_route_page_or_action_is_the_accounts(): void {
		$this->assertSame( array( '/zorderz/v1/notifications/unread-count', '/zorderz/v1/consent' ), ZDZ_Privacy_View::allowed_rest(), 'the REST list is the same two reads' );
		$this->assertSame( array(), array_filter( array_keys( ZDZ_Privacy_View::ALLOWED_SCREENS ), function ( $k ) { return false !== strpos( $k, 'signin' ) || false !== strpos( $k, 'login' ); } ) );
		$this->assertSame( array(), array_filter( ZDZ_Test_Kpv_Request::all(), function ( $k ) { return false !== strpos( $k, 'signin' ) || false !== strpos( $k, 'magic' ) || false !== strpos( $k, 'login' ); } ) );
		$this->assertSame( '', self::hit( 'rest', array(), array(), array( 'uri' => '/wp-json/zorderz/v1/notifications/unread-count' ) ), 'control: the gate is answering for the account' );
		foreach ( ZDZ_Privacy_View::SIGNIN_ROUTES as $route ) {
			$this->assertNotSame( '', self::hit( 'rest', array(), array( 'code' => '123456', 'email' => 'alex@example.com' ),
				array( 'uri' => '/wp-json/zorderz/v1/' . $route, 'type' => 'application/json', 'input' => '{"code":"123456","email":"alex@example.com"}' ) ), 'POST ' . $route );
			$this->assertNotSame( '', self::hit( 'rest', array( 'request_id' => 'abc' ), array(), array( 'uri' => '/wp-json/zorderz/v1/' . $route ) ), 'GET ' . $route );
		}
		$this->assertNotSame( '', self::hit( 'admin', array( 'page' => 'zdz-signin-routes' ), array(), array( 'uri' => '/wp-admin/admin.php', 'page' => 'admin.php' ) ), 'a sign-in settings page' );
		$this->assertNotSame( '', self::hit( 'admin', array( 'page' => 'zdz-signin-routes' ), array( 'do' => 'issue', 'uid' => '7', '_wpnonce' => 'n' ), array( 'uri' => '/wp-admin/admin.php', 'page' => 'admin.php' ) ), 'a post to it' );
		$this->assertNotSame( '', self::hit( 'admin', array(), array( 'backup_address' => 'alex@example.net', 'user_id' => '9' ), array( 'uri' => '/wp-admin/profile.php', 'page' => 'profile.php' ) ), 'a post to its own profile' );
		$this->assertNotSame( '', self::hit( 'front', array(), array(), array( 'uri' => '/login/' ) ), 'the sign-in page' );
		$this->assertNotSame( '', self::hit( 'front', array(), array(), array( 'uri' => '/register/' ) ), 'the register page' );
	}

	public function test_the_sign_in_routes_are_the_ones_the_login_bridge_registers(): void {
		$bridge = (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-magic-link-bridge.php' );
		preg_match_all( "#register_rest_route\(\s*(?:'zorderz/v1'|ZDZ_REST_NS),\s*'/([a-z0-9/_-]+)'#", $bridge, $m );
		$registered = $m[1];
		$listed     = ZDZ_Privacy_View::SIGNIN_ROUTES;
		sort( $registered );
		sort( $listed );
		$this->assertNotEmpty( $registered );
		$this->assertSame( $registered, $listed, 'a sign-in route added to the bridge must be meant here too (or a browser holding a stale cookie cannot use it)' );
		$this->assertSame( array( '/zorderz/v1/magic-link-init', '/zorderz/v1/magic-link-status', '/zorderz/v1/magic-link-claim', '/zorderz/v1/magic-link-code-claim', '/zorderz/v1/magic-link-send-code' ),
			ZDZ_Privacy_View::signin_rest() );
		// the must-use file's own fall-back names the same five
		$mu = (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz/mu-plugin/0-zdz-privacy-view-gate.php' );
		$this->assertSame( 1, preg_match( '/magic-link-\(\?:([a-z|\-]+)\)/', $mu, $alt ) );
		$fallback = array_map( function ( $s ) { return 'magic-link-' . $s; }, explode( '|', $alt[1] ) );
		sort( $fallback );
		$this->assertSame( $listed, $fallback );
		$this->assertSame( array( 'login', 'register' ), ZDZ_Privacy_View::SIGNIN_PAGES );
		$this->assertSame( array( 'magic-login-bridge', 'request_id', 'bridge_token', 'bridge_code', 'zdz_next', 'redirect_to' ), ZDZ_Privacy_View::SIGNIN_PAGE_KEYS );
	}

	/* ── wp-login.php, held like the rest ────────────────────────────────────── */

	public function test_wp_login_is_for_signing_out_only(): void {
		$this->assertSame( '', self::hit( 'login', array( 'action' => 'logout', '_wpnonce' => 'a1b2c3' ) ), 'signing out' );
		$this->assertSame( '', self::hit( 'login' ), 'the sign-in page' );
		$this->assertSame( '', self::hit( 'login', array( 'loggedout' => 'true', 'wp_lang' => 'en_US' ) ), 'the signed-out page' );
		$this->assertSame( '', self::hit( 'login', array( 'redirect_to' => 'https://app.example.com/?a=1', 'reauth' => '1' ), array(), array( 'uri' => '/wp-login.php?redirect_to=https%3A%2F%2Fapp.example.com%2F%3Fa%3D1&reauth=1' ) ),
			'with its redirect address (written as %xx, as WordPress writes it)' );
		$this->assertNotSame( '', self::hit( 'login', array(), array(), array( 'method' => 'POST', 'type' => 'application/x-www-form-urlencoded', 'input' => '', 'length' => 0 ) ), 'by POST, even with nothing in it' );
		$this->assertNotSame( '', self::hit( 'login', array(), array(), array( 'method' => 'POST', 'type' => null, 'input' => '', 'length' => 0 ) ) );
		$this->assertNotSame( '', self::hit( 'login', array( 'redirect_to' => '/.well-known/x' ), array(), array( 'uri' => '/wp-login.php?redirect_to=/.well-known/x' ) ), 'a redirect address with a raw "/" in it' );
		$this->assertNotSame( '', self::hit( 'login', array( 'redirect_to' => 'a:b' ), array(), array( 'uri' => '/wp-login.php?redirect_to=a:b' ) ), '... or a raw ":"' );
		$this->assertNotSame( '', self::hit( 'login', array(), array( 'log' => 'alex', 'pwd' => 'x', 'wp-submit' => 'Log In', 'redirect_to' => '/', 'testcookie' => '1' ) ), 'the sign-in form is not the account\'s to send (it is signed in already)' );
		$this->assertNotSame( '', self::hit( 'login', array(), array( 'log' => 'alex@example.com' ) ) );
		foreach ( array( 'zdz_view_as', 'page', 'magic_login_token', 'magic-login', 'user_id', 'key', 'log', 'pwd', 'user_login', 'customize-login', 'token' ) as $t ) {
			$this->assertSame( 'login-field:' . $t, self::hit( 'login', array( $t => '1' ) ), 'wp-login.php with ?' . $t . '=' );
		}
		$this->assertNotSame( '', self::hit( 'login', array(), array(), array( 'method' => 'POST', 'type' => 'application/json', 'input' => '{"a":1}' ) ), 'with a body of any kind, by any method' );
		$this->assertNotSame( '', self::hit( 'login', array(), array(), array( 'method' => 'PUT', 'type' => 'text/xml', 'input' => '<a/>' ) ) );
		$this->assertNotSame( '', self::hit( 'login', array(), array(), array( 'method' => 'GET', 'type' => null, 'input' => 'x=1' ) ) );
		$this->assertNotSame( '', self::hit( 'login', array(), array(), array( 'uri' => '/wp-login.php?=code=x&=page=example&=/.well-known/' ) ), 'things in its address that PHP did not read' );
		$this->assertNotSame( '', self::hit( 'login', array( 'redirect_to' => '/x?code=1' ), array(), array( 'uri' => '/wp-login.php?redirect_to=/x?code=1' ) ), 'a redirect address not written as %xx' );
		$this->assertNotSame( '', self::hit( 'login', array( 'action' => 'logout' ), array(), array( 'request' => array( 'action' => 'lostpassword' ) ) ), 'its action is the one in the address: not another' );
		$this->assertNotSame( '', self::hit( 'login', array(), array(), array( 'request' => array( 'action' => 'logout' ) ) ), '... not a cookie\'s' );
		$this->assertNotSame( '', self::hit( 'login', array( 'action' => 'lostpassword' ) ), '... not a password reset' );
		$this->assertNotSame( '', self::hit( 'login', array( 'action' => 'rp' ) ) );
		$this->assertNotSame( '', self::hit( 'login', array( 'action' => array( 'logout' ) ), array(), array( 'uri' => '/wp-login.php?action[]=logout', 'request' => array( 'action' => array( 'logout' ) ) ) ), '... not a list' );
	}

	public function test_a_hosts_own_login_marker_is_declared_not_built_in(): void {
		$with = array( 'action' => 'logout', '_wpnonce' => 'a1b2c3', 'example-host-login' => 'true' );
		$this->assertSame( 'login-field:example-host-login', self::hit( 'login', $with ), 'Core knows no host\'s marker' );
		$GLOBALS['zdz_test_filters']['zdz_privacy_view_login_keys'] = function ( $keys ) {
			return array_merge( (array) $keys, array( 'example-host-login', 'log', 'pwd', 'key', 'token', 'bad key', 9 ) );
		};
		$this->assertSame( '', self::hit( 'login', $with ), 'a site whose host adds one to every wp-login.php address declares it' );
		$this->assertSame( array( 'action', '_wpnonce', 'redirect_to', 'loggedout', 'reauth', 'interim-login', 'wp_lang', 'example-host-login' ), ZDZ_Privacy_View::login_keys(), 'the sign-in form\'s fields are never taken' );
		foreach ( array( 'log', 'pwd', 'key', 'token' ) as $t ) {
			$this->assertSame( 'login-field:' . $t, self::hit( 'login', array( $t => '1' ) ), $t . ' stays refused, whatever the filter returned' );
		}
		$GLOBALS['zdz_test_filters']['zdz_privacy_view_login_keys'] = function () {
			return array();   // Core's own stay, whatever came back
		};
		$this->assertSame( '', self::hit( 'login', array( 'action' => 'logout', '_wpnonce' => 'a1b2c3' ) ) );
	}

	/* ── the pages it could already open ─────────────────────────────────────── */

	public function test_the_pages_it_could_already_open(): void {
		$this->assertSame( '', self::hit( 'front' ), 'the app shell' );
		$this->assertSame( '', self::hit( 'front', array( 'cb' => '1' ) ), '... with its cache-buster' );
		$this->assertSame( '', self::hit( 'front', array( 'zdz_media' => '77', 't' => 'a' ) ), 'a photo' );
		$this->assertSame( '', self::hit( 'admin' ), 'its profile page' );
		$this->assertNotSame( '', self::hit( 'front', array( 'zdz_docs' => '1' ) ), 'the shell with another key' );
		$this->assertNotSame( '', self::hit( 'front', array( '_fresh' => 'abc' ) ) );
		$this->assertNotSame( '', self::hit( 'front', array(), array( 'x' => '1' ) ), '... by POST' );
		$this->assertNotSame( '', self::hit( 'front', array( '_method' => array( 'x' ) ), array(), array( 'uri' => '/?_method[]=x' ) ), '... with a method that is a list' );
		$this->assertNotSame( '', self::hit( 'front', array(), array(), array( 'method' => 'GET', 'type' => 'application/json', 'input' => '{}' ) ), 'the shell with a body' );
		$this->assertNotSame( '', self::hit( 'admin', array(), array(), array( 'type' => 'text/plain', 'input' => 'x' ) ), 'the profile page with a body' );
	}

	/* ── what a refusal says ─────────────────────────────────────────────────── */

	public function test_a_refusal_says_one_word_from_a_fixed_list(): void {
		$s    = array( 'action' => 'zana_list_sessions', 'nonce' => 'n' );
		$g    = array( 'action' => 'zim_sidebar', 'nonce' => 'n' );
		$a    = '/wp-admin/admin-ajax.php?action=zim_sidebar&nonce=n';
		$said = array();
		foreach ( array(
			array( 'ajax', array(), self::own( 'zana_list_sessions', array( 'x"><script>alert(1)</script>' => '1' ) ), array(), array() ),
			array( 'ajax', array(), self::own( 'zana_send_message' ), array(), array( "x\nfile<b>" => array() ) ),
			array( 'ajax', array(), $s, array( 'input' => 'action=zana_list_sessions&nonce=n&<x>' ), array() ),
			array( 'ajax', array(), array( 'action' => 'example_<b>' ), array(), array() ),
			array( 'ajax', array(), self::own( 'zana_list_sessions' ), array( 'uri' => '/wp-admin/admin-ajax.php/<x>' ), array() ),
			array( 'ajax', $g, array(), array( 'method' => 'PUT' ), array() ),
			array( 'ajax', $g, array(), array( 'uri' => $a . '&=<x>' ), array() ),
			array( 'ajax', array(), array( 'nonce' => 'n' ), array( 'request' => array( 'action' => 'zana_list_sessions' ) ), array() ),
			array( 'rest', array( 'x<i>' => '1' ), array(), array( 'uri' => '/wp-json' . self::R . '?x=1', 'bare' => true ), array() ),
			array( 'rest', array( 'page' => 'x' ), array(), array(), array() ),
			array( 'rest', array(), array(), array( 'type' => 'application/json', 'input' => '{"x":"<b>"}' ), array() ),
			array( 'rest', array(), array(), array( 'uri' => '/wp-json/zorderz/v1/<x>' ), array() ),
			array( 'login', array( 'log' => 'x' ), array(), array(), array() ),
		) as $c ) {
			$said[] = ZDZ_Privacy_View::why_word( self::hit( $c[0], $c[1], $c[2], $c[3], $c[4] ) );
		}
		$this->assertSame( array( 'field', 'file', 'body', 'not listed', 'address', 'method', 'address', 'action', 'address', 'field', 'body', 'not listed', 'field' ), $said );
		$this->assertSame( 'not listed', ZDZ_Privacy_View::why_word( 'ajax:x' ) );
		$this->assertSame( 'field', ZDZ_Privacy_View::why_word( 'rest-field:x' ) );
		$this->assertSame( 'action', ZDZ_Privacy_View::why_word( 'ajax-action:two' ) );
		$this->assertSame( 'address', ZDZ_Privacy_View::why_word( 'query:front' ) );
		$this->assertSame( 'body', ZDZ_Privacy_View::why_word( 'body:rest' ) );
		$this->assertSame( 'header', ZDZ_Privacy_View::why_word( 'header:HTTP_X_ORIGINAL_URL' ) );
		$this->assertSame( 'not listed', ZDZ_Privacy_View::why_word( "field:\n<x>" ), 'never the request\'s own text' );
		$this->assertSame( 'not listed', ZDZ_Privacy_View::why_word( array() ) );
	}

	/* ── where the gate is hooked, and who it is for ─────────────────────────── */

	public function test_the_gate_is_hooked_first_of_all_and_only_judges_the_account(): void {
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-privacy-view.php' );
		$this->assertSame( 1, substr_count( $src, "add_action( 'after_setup_theme', array( __CLASS__, 'gate' ), PHP_INT_MIN );" ), 'after_setup_theme, first of all: before any init or admin_init handler' );
		$this->assertSame( 1, preg_match_all( "/add_(?:action|filter)\(\s*'[a-z_]+',\s*array\( __CLASS__, 'gate' \)/", $src ), 'hooked once' );
		$this->assertSame( 1, substr_count( $src, "add_action( 'admin_init', array( __CLASS__, 'guard_admin' ), 0 );" ), 'the second net on admin_init, first' );
		$this->assertSame( 1, substr_count( $src, "add_action( 'plugins_loaded', array( __CLASS__, 'early_confirm' ), PHP_INT_MIN );" ), 'the early gate\'s second look: plugins_loaded, first of all' );
		// anyone else's request is not looked at: the gate returns at once
		$GLOBALS['zdz_test_current_user'] = ZDZ_Test_Kpv_Request::MEMBER;
		ZDZ_Test_Kpv_Request::build( 'ajax', array( 'page' => 'zdz-core-settings', 'code' => 'x' ), array( 'action' => 'zsch_settings_save', 'option_page' => 'x' ), array( 'type' => 'application/json', 'input' => '{"a":1}' ) );
		$_COOKIE = array( 'example_cache' => '1' );
		ZDZ_Privacy_View::gate();
		ZDZ_Privacy_View::guard_admin();
		$this->assertSame( array( 'example_cache' => '1' ), $_COOKIE, '... and nobody else loses a cookie' );
	}

	public function test_view_as_returns_for_the_account_before_it_reads_its_key_or_cookie(): void {
		$va = (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-view-as.php' );
		$at = strpos( $va, 'public static function handle_query_param()' );
		$pv = strpos( $va, 'ZDZ_Privacy_View::is_on()', (int) $at );
		$ck = strpos( $va, "\$_COOKIE['zdz_view_as']", (int) $at );
		$gk = strpos( $va, "\$_GET['zdz_view_as']", (int) $at );
		$this->assertTrue( false !== $at && false !== $pv && false !== $ck && false !== $gk );
		$this->assertLessThan( $ck, $pv );
		$this->assertLessThan( $gk, $pv );
		$this->assertStringContainsString( 'return;', substr( $va, $pv, 200 ) );
		$this->assertSame( 0, substr_count( substr( $va, 0, (int) $at ), '$_COOKIE[' ), 'nothing before it reads a cookie' );
	}
}
