<?php
/**
 * ZSM_Codescan — an automatic check of YOUR OWN code (the plugins, must-use plugins and
 * themes the monitor counts as first party; see ZSM_Inventory::is_first_party), which no
 * public vulnerability feed covers.
 *
 * It runs on the site itself, over the code that is actually deployed, with PHP's own
 * tokenizer (token_get_all): no shell, no outside call, no external tooling. The daily job
 * (and "Check our code now") reads the files in time-boxed slices sized for the host's
 * request wall.
 *
 * WHAT IT LOOKS FOR (each rule is narrow on purpose: a noisy rule gets ignored)
 *   critical  include-input      include/require of a path built from request input
 *   high      sql-input          a $wpdb query with request input outside $wpdb->prepare()'s values
 *   high      echo-input         request input printed without an HTML escaper / cast
 *   high      exec               eval(), backticks, or a shell command whose arguments are not escaped
 *   high      unserialize-input  unserialize()/maybe_unserialize() of request input
 *   high      extract-input      extract() of request input
 *   high      file-input         a file write/delete/move whose path comes from request input
 *   high      secret-in-code     a string shaped like a live provider key (the value is never stored)
 *   medium    handler-no-nonce   an admin_post_/wp_ajax_ handler that writes and checks no nonce
 *   medium    tls-off            certificate checks off for a request to another server
 *   medium    redirect-input     wp_redirect() to request input (wp_safe_redirect is fine)
 *   low       exec               a shell command whose arguments are escaped (still worth knowing)
 *   low       tls-off            certificate checks off for a request back to this site
 *   low       token-compare      a secret token compared with == / === (not timing-safe)
 *   low       rest-no-permission a REST route with no permission_callback
 *   info      rest-public        REST routes anyone may call (one row per component)
 *
 * "Request input" = $_GET, $_POST, $_REQUEST, $_COOKIE, $_FILES, followed directly (no taint
 * tracking through variables: that is where such tools get noisy; import a deeper report for a
 * full review). Whether a wrapper makes it safe depends on WHERE it goes: an HTML escaper does
 * nothing for a file path or a query, so every sink has its own list. A use only tested with
 * isset()/empty()/a comparison is not counted. tests/, vendor/, node_modules/ and fixtures/
 * folders are skipped.
 *
 * A finding's identity is rule + file + a HASH of the statement's whitespace-free text (not the
 * line, and never the text itself, so a key inside a statement is never stored). Editing other
 * parts of a file does not close and reopen it, and an acknowledgement sticks.
 *
 * @package Zorderz\Security_Monitor
 */

defined( 'ABSPATH' ) || exit;

class ZSM_Codescan {

	const STATE_OPT   = 'zsm_codescan_state';   // in-progress walk: files, cursor, hits, slim function index
	const LAST_OPT    = 'zsm_codescan_last';    // last finished run: when, files, findings, seconds, skipped
	const CURRENT_OPT = 'zsm_codescan_current'; // the big file being read right now (a death mark)
	const DEAD_OPT    = 'zsm_codescan_dead';    // files a request died in: relpath => size|mtime (skipped until they change)
	const MAX_FILE    = 1572864;                 // 1.5 MB: anything bigger is generated or vendored
	const FILE_SECONDS = 8.0;                    // one file may take this long, then it is given up

	const SUPERGLOBALS = array( '$_GET', '$_POST', '$_REQUEST', '$_COOKIE', '$_FILES' );

	/** Results that cannot carry the input's text: a boolean, a number or a fixed-shape hash. Safe in every sink. */
	const BOOLISH = array(
		'isset', 'empty', 'count', 'strlen', 'mb_strlen', 'in_array', 'array_key_exists', 'absint', 'intval', 'floatval', 'boolval',
		'md5', 'sha1', 'hash', 'hash_hmac', 'wp_hash', 'crc32', 'preg_match', 'wp_verify_nonce', 'check_admin_referer', 'check_ajax_referer',
		'gettype', 'number_format', 'number_format_i18n', 'is_email', 'checked', 'selected', 'disabled', 'strcmp', 'hash_equals',
	);
	/** Per sink, what else neutralises request input (an HTML escaper does nothing for a query or a path, so each sink has its own list). */
	const SINK_SAFE = array(
		'html'     => array( 'esc_html', 'esc_attr', 'esc_url', 'esc_js', 'esc_textarea', 'esc_html__', 'esc_attr__', 'esc_html_e', 'esc_attr_e',
			'wp_kses', 'wp_kses_post', 'wp_kses_data', 'json_encode', 'wp_json_encode', 'htmlspecialchars', 'htmlentities', 'urlencode',
			'rawurlencode', 'base64_encode', 'sanitize_key', 'sanitize_hex_color', 'sanitize_html_class', 'wp_send_json', 'wp_send_json_success',
			'wp_send_json_error', 'sanitize_title' ),
		'sql'      => array( 'sanitize_key', 'sanitize_title' ), // + prepare() values and esc_sql() inside quotes, handled in code
		'path'     => array( 'sanitize_file_name', 'sanitize_key', 'sanitize_title', 'basename' ),
		'redirect' => array( 'wp_validate_redirect' ),
		'shell'    => array( 'escapeshellarg' ),
		'raw'      => array(), // unserialize, extract: nothing makes request input safe there
	);

	const DB_QUERY_METHODS = array( 'query', 'get_var', 'get_row', 'get_col', 'get_results' );
	const EXEC_FUNCS       = array( 'shell_exec', 'exec', 'system', 'passthru', 'proc_open', 'popen', 'pcntl_exec' );
	/** A request guard: a nonce check, or a timing-safe token compare (the loopback workers' signed tokens). */
	const NONCE_FUNCS      = array( 'check_admin_referer', 'check_ajax_referer', 'wp_verify_nonce', 'verify_nonce', 'hash_equals' );
	/** A handler that calls only these changes nothing (a hard deny, a fresh nonce, a cron nudge). */
	const HARMLESS_CALLS   = array( 'status_header', 'wp_send_json_error', 'wp_send_json_success', 'wp_send_json', 'wp_die', 'spawn_cron', 'wp_create_nonce', 'nocache_headers', 'is_user_logged_in', 'current_user_can', 'get_current_user_id', 'array', 'isset', 'empty', 'esc_html__', '__', 'wp_safe_redirect', 'wp_redirect', 'admin_url', 'home_url', 'site_url', 'add_query_arg' );
	const SITE_URL_FUNCS   = array( 'admin_url', 'site_url', 'home_url', 'rest_url', 'includes_url', 'content_url', 'plugins_url', 'get_rest_url', 'network_site_url' );
	const REMOTE_FUNCS     = array( 'wp_remote_get', 'wp_remote_post', 'wp_remote_request', 'wp_remote_head', 'wp_safe_remote_get', 'wp_safe_remote_post', 'wp_safe_remote_request', 'download_url' );

	/** Provider key shapes. Only the rule name, file and a hash prefix are ever kept — never the value. */
	const SECRET_SHAPES = array(
		'Stripe live secret key'  => '/\b[rs]k_live_([0-9A-Za-z]{16,})/',
		'AWS access key'          => '/\bAKIA([0-9A-Z]{16})\b/',
		'Google API key'          => '/\bAIza([0-9A-Za-z_\-]{35})\b/',
		'GitHub token'            => '/\bgh[pousr]_([0-9A-Za-z]{36,})\b/',
		'Slack token'             => '/\bxox[abposr]-([0-9A-Za-z\-]{10,})/',
		'private key'             => '/-----BEGIN (?:RSA |EC |OPENSSH |DSA )?PRIVATE KEY-----()/',
		'OpenAI-style key'        => '/\bsk-(?:proj-)?([0-9A-Za-z_\-]{32,})\b/',
	);

	/** Per file: the matching bracket of every opening bracket (built once, O(n)). */
	private static $P = array();

	// ───────────────────────────────────────────────────────────────────────────
	// PURE PART (no WordPress): source text in, hits out. Tested on its own.
	// ───────────────────────────────────────────────────────────────────────────

	/** Significant tokens with line numbers, and the bracket map. */
	private static function tokenize( string $code ): array {
		$raw  = @token_get_all( $code ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a parse error in one file must not stop the walk
		$t    = array();
		$line = 1;
		foreach ( $raw as $tok ) {
			if ( is_array( $tok ) ) {
				if ( in_array( $tok[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) { continue; }
				$line = $tok[2];
				$t[]  = $tok;
			} else {
				$t[] = array( 0, $tok, $line );
			}
		}
		$P     = array();
		$stack = array();
		foreach ( $t as $k => $s ) {
			if ( ( 0 === $s[0] && ( '(' === $s[1] || '[' === $s[1] || '{' === $s[1] ) ) || T_CURLY_OPEN === $s[0] || T_DOLLAR_OPEN_CURLY_BRACES === $s[0] ) {
				$stack[] = $k;
			} elseif ( 0 === $s[0] && ( ')' === $s[1] || ']' === $s[1] || '}' === $s[1] ) ) {
				if ( $stack ) { $P[ array_pop( $stack ) ] = $k; }
			}
		}
		$n = count( $t );
		if ( $stack ) {
			// Unbalanced: an unclosed bracket ends just before the next ';' (or the file's end),
			// so no statement or argument list runs on to the end of the file.
			$next_semi = array();
			$ns        = $n - 1;
			for ( $k = $n - 1; $k >= 0; $k-- ) {
				if ( ( 0 === $t[ $k ][0] && ';' === $t[ $k ][1] ) || in_array( $t[ $k ][0], array( T_ECHO, T_PRINT, T_CLOSE_TAG, T_OPEN_TAG_WITH_ECHO, T_FUNCTION ), true ) ) { $ns = $k; } // or the next echo / tag / function
				$next_semi[ $k ] = $ns;
			}
			foreach ( $stack as $open ) { $P[ $open ] = max( $open, $next_semi[ $open ] - 1 ); }
		}
		self::$P = $P;
		return $t;
	}

	/** Lower-case function name of a T_STRING / fully-qualified name token, else ''. */
	private static function fname( array $tok ): string {
		if ( T_STRING === $tok[0] || ( defined( 'T_NAME_FULLY_QUALIFIED' ) && T_NAME_FULLY_QUALIFIED === $tok[0] ) ) {
			return strtolower( ltrim( $tok[1], '\\' ) );
		}
		return '';
	}

	/**
	 * Scan one PHP source.
	 *
	 * @param string $code    file contents
	 * @param string $relpath path as reported, e.g. "plugins/example-plugin/includes/x.php"
	 * @return array<int,array{rule:string,severity:string,file:string,line:int,message:string,sig:string,component:string}>
	 *         plus meta rows '_handler' / '_rest_public' resolved later per component.
	 */
	public static function scan_source( string $code, string $relpath ): array {
		if ( '' === $code || false === strpos( $code, '<?' ) ) {
			return array();
		}
		$t    = self::tokenize( $code );
		$comp = self::component_of( $relpath );
		$hits = array();
		$n    = count( $t );

		$add = function ( string $rule, string $sev, int $i_from, int $i_to, string $msg ) use ( &$hits, &$t, $relpath, $comp ) {
			$hits[] = array(
				'rule'      => $rule,
				'severity'  => $sev,
				'file'      => $relpath,
				'line'      => (int) $t[ max( 0, $i_from ) ][2],
				'message'   => $msg,
				'sig'       => self::norm( $t, $i_from, $i_to ),
				'component' => $comp,
			);
		};

		for ( $i = 0; $i < $n; $i++ ) {
			$tok = $t[ $i ];
			$id  = $tok[0];
			$tx  = $tok[1];

			// ── include / require of request input (critical) ───────────────
			if ( in_array( $id, array( T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE ), true ) ) {
				$end = self::stmt_end( $t, $i );
				if ( self::tainted_in( $t, $i + 1, $end, 'path' ) ) {
					$add( 'include-input', 'critical', $i, $end, __( 'A file is included from a path built from request input.', 'zorderz' ) );
				}
				continue;
			}

			// ── eval (high) ──────────────────────────────────────────────────
			if ( T_EVAL === $id ) {
				$add( 'exec', 'high', $i, self::stmt_end( $t, $i ), __( 'eval() runs a string as PHP code.', 'zorderz' ) ); // sentinel:allow the scanner names eval() in a finding; nothing is evaluated
				continue;
			}
			// Backtick shell operator.
			if ( 0 === $id && '`' === $tx ) {
				$close = $i + 1;
				while ( $close < $n && ! ( 0 === $t[ $close ][0] && '`' === $t[ $close ][1] ) ) { $close++; }
				$add( 'exec', 'high', $i, min( $close, $n - 1 ), __( 'Backticks run a shell command.', 'zorderz' ) );
				$i = $close;
				continue;
			}

			// ── echo / print / <?= of request input (high) ───────────────────
			if ( in_array( $id, array( T_ECHO, T_PRINT, T_OPEN_TAG_WITH_ECHO ), true ) ) {
				$end = self::stmt_end( $t, $i );
				if ( self::tainted_in( $t, $i + 1, $end, 'html' ) ) {
					$add( 'echo-input', 'high', $i, $end, __( 'Request input is printed without escaping (cross-site scripting).', 'zorderz' ) );
				}
				continue;
			}

			// ── CURLOPT_SSL_VERIFYPEER/HOST off, in curl_setopt( …, X, false ) or [ X => false ] ──
			if ( in_array( strtolower( ltrim( (string) $tx, '\\' ) ), array( 'curlopt_ssl_verifypeer', 'curlopt_ssl_verifyhost' ), true )
				&& ( T_DOUBLE_ARROW === ( $t[ $i + 1 ][0] ?? 0 ) || ( 0 === ( $t[ $i + 1 ][0] ?? -1 ) && ',' === $t[ $i + 1 ][1] ) )
				&& in_array( strtolower( ltrim( (string) ( $t[ $i + 2 ][1] ?? '' ), '\\' ) ), array( 'false', '0' ), true ) ) {
				$add( 'tls-off', 'medium', $i, $i + 2, __( 'TLS certificate checks are switched off for an outgoing request, so a forged server would be believed.', 'zorderz' ) );
				continue;
			}

			$fn = self::fname( $tok );
			if ( '' !== $fn ) {
				$prev = $t[ $i - 1 ] ?? array( 0, '', 0 );
				$next = $t[ $i + 1 ] ?? array( 0, '', 0 );
				$is_call = 0 === $next[0] && '(' === $next[1];

				// $wpdb->query( … ) etc.
				$arrow = T_OBJECT_OPERATOR === $prev[0] || ( defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) && T_NULLSAFE_OBJECT_OPERATOR === $prev[0] );
				$db    = ( T_VARIABLE === ( $t[ $i - 2 ][0] ?? 0 ) && '$wpdb' === ( $t[ $i - 2 ][1] ?? '' ) )
					|| ( T_STRING === ( $t[ $i - 2 ][0] ?? 0 ) && 'wpdb' === $t[ $i - 2 ][1] && T_OBJECT_OPERATOR === ( $t[ $i - 3 ][0] ?? 0 ) ); // $this->wpdb->query()
				if ( $is_call && in_array( $fn, self::DB_QUERY_METHODS, true ) && $arrow && $db ) {
					$close = self::close_of( $i + 1, $n );
					if ( self::tainted_in( $t, $i + 2, $close, 'sql' ) ) {
						$add( 'sql-input', 'high', $i - 2, $close, __( 'A database query is built from request input outside $wpdb->prepare()\'s values (SQL injection).', 'zorderz' ) );
					}
					continue;
				}

				$is_fn_call = $is_call
					&& ! ( in_array( $prev[0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST ), true )
						|| ( defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) && T_NULLSAFE_OBJECT_OPERATOR === $prev[0] ) );
				if ( ! $is_fn_call ) {
					continue;
				}
				$close = self::close_of( $i + 1, $n );
				$args  = self::split_args( $t, $i + 2, $close );

				if ( in_array( $fn, self::EXEC_FUNCS, true ) || 'create_function' === $fn ) {
					// High unless every argument is literal or escaped. A single variable built
					// with escapeshellarg() in the lines just above counts as escaped; request
					// input in the call itself never does.
					$tainted = self::tainted_in( $t, $i + 2, $close, 'shell' );
					$literal = isset( $args[0] ) && $args[0][0] === $args[0][1] && T_CONSTANT_ENCAPSED_STRING === $t[ $args[0][0] ][0];
					$escaped = self::calls_in( $t, $i + 2, $close, array( 'escapeshellarg' ) ) && self::all_vars_escaped( $t, $args[0] ?? array( $i + 2, $close ) );
					$built   = isset( $args[0] ) && $args[0][0] === $args[0][1] && T_VARIABLE === $t[ $args[0][0] ][0]
						&& self::assigned_with( $t, $i, $t[ $args[0][0] ][1], array( 'escapeshellarg' ) );
					if ( 'create_function' === $fn || $tainted || ! ( $literal || $escaped || $built ) ) {
						$add( 'exec', 'high', $i, $close, sprintf( /* translators: %s: function name */ __( '%s() runs a shell command or builds code at run time, and its arguments are not escaped.', 'zorderz' ), $fn ) );
					} else {
						$add( 'exec', 'low', $i, $close, sprintf( /* translators: %s: function name */ __( '%s() runs a shell command (arguments escaped). Confirm the host allows it and it is still needed.', 'zorderz' ), $fn ) );
					}
				} elseif ( 'assert' === $fn && isset( $args[0] ) && $args[0][0] === $args[0][1] && in_array( $t[ $args[0][0] ][0], array( T_CONSTANT_ENCAPSED_STRING, T_VARIABLE ), true ) ) {
					$add( 'exec', 'high', $i, $close, __( 'assert() with a string argument runs it as PHP code.', 'zorderz' ) );
				} elseif ( in_array( $fn, array( 'unserialize', 'maybe_unserialize' ), true ) && isset( $args[0] ) && self::tainted_in( $t, $args[0][0], $args[0][1], 'raw' )
					&& ! ( isset( $args[1] ) && preg_match( '/[\'"]allowed_classes[\'"]=>false/i', self::text( $t, $args[1] ) ) ) ) {
					$add( 'unserialize-input', 'high', $i, $close, sprintf( /* translators: %s: function name */ __( '%s() of request input can build arbitrary objects (object injection).', 'zorderz' ), $fn ) );
				} elseif ( 'extract' === $fn && isset( $args[0] ) && self::tainted_in( $t, $args[0][0], $args[0][1], 'raw' ) ) {
					$add( 'extract-input', 'high', $i, $close, __( 'extract() of request input overwrites local variables.', 'zorderz' ) );
				} elseif ( in_array( $fn, array( 'file_put_contents', 'unlink', 'fopen', 'rmdir', 'mkdir', 'touch', 'readfile', 'file_get_contents' ), true ) && isset( $args[0] ) && self::tainted_in( $t, $args[0][0], $args[0][1], 'path' ) ) {
					$add( 'file-input', 'high', $i, $close, sprintf( /* translators: %s: function name */ __( '%s() on a path that comes from request input (path traversal).', 'zorderz' ), $fn ) );
				} elseif ( in_array( $fn, array( 'move_uploaded_file', 'copy', 'rename' ), true ) && isset( $args[1] ) && self::tainted_in( $t, $args[1][0], $args[1][1], 'path' ) ) {
					$add( 'file-input', 'high', $i, $close, sprintf( /* translators: %s: function name */ __( '%s() to a destination that comes from request input (path traversal).', 'zorderz' ), $fn ) );
				} elseif ( 'wp_redirect' === $fn && isset( $args[0] ) && self::tainted_in( $t, $args[0][0], $args[0][1], 'redirect' ) ) {
					$add( 'redirect-input', 'medium', $i, $close, __( 'wp_redirect() to an address from request input (open redirect); use wp_safe_redirect().', 'zorderz' ) );
				} elseif ( in_array( $fn, array( 'printf', 'vprintf' ), true ) && self::tainted_in( $t, $i + 2, $close, 'html' ) ) {
					$add( 'echo-input', 'high', $i, $close, __( 'Request input is printed without escaping (cross-site scripting).', 'zorderz' ) );
				} elseif ( 'register_rest_route' === $fn ) {
					self::rest_route( $t, $i, $close, $args, $add );
				} elseif ( 'add_action' === $fn && isset( $args[0], $args[1] ) ) {
					$hook = self::literal( $t, $args[0] );
					if ( '' !== $hook && preg_match( '/^(admin_post_|wp_ajax_)/', $hook ) ) {
						$cb    = $args[1];
						$first = $cb[0];
						if ( T_STATIC === $t[ $first ][0] ) { $first++; } // static function () {}
						$inline = T_FUNCTION === $t[ $first ][0] || ( defined( 'T_FN' ) && T_FN === $t[ $first ][0] );
						$body   = $inline ? self::fn_info( $t, $first, $cb[1], self::class_at( $t, $i ) ) : null;
						$hits[] = array(
							'rule' => '_handler', 'severity' => 'meta', 'file' => $relpath, 'line' => (int) $t[ $i ][2],
							'message' => $hook, 'sig' => 'handler|' . $hook, 'component' => $comp,
							'ref'    => $inline ? '' : self::callback_ref( $t, $cb ),
							'inline' => $body,
						);
					}
				}
				continue;
			}

			// 'sslverify' => false
			if ( T_CONSTANT_ENCAPSED_STRING === $id && in_array( strtolower( trim( $tx, '\'"' ) ), array( 'sslverify', 'verify_peer' ), true )
				&& T_DOUBLE_ARROW === ( $t[ $i + 1 ][0] ?? 0 ) && in_array( strtolower( ltrim( (string) ( $t[ $i + 2 ][1] ?? '' ), '\\' ) ), array( 'false', '0' ), true ) ) {
				if ( self::loopback_request( $t, $i ) ) {
					$add( 'tls-off', 'low', $i, $i + 2, __( 'TLS certificate checks are off for a request back to this same site (a loopback). Usually harmless; nothing leaves the server.', 'zorderz' ) );
				} else {
					$add( 'tls-off', 'medium', $i, $i + 2, __( 'TLS certificate checks are switched off for an outgoing request, so a forged server would be believed.', 'zorderz' ) );
				}
				continue;
			}

			// Secret-shaped string literals (the value is never kept: only a hash prefix).
			if ( in_array( $id, array( T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE ), true ) && strlen( $tx ) >= 20 ) {
				foreach ( self::SECRET_SHAPES as $what => $re ) {
					if ( preg_match( $re, $tx, $m ) && ! preg_match( '/(.)\1{7,}/', (string) $m[1] ) ) { // "sk_live_XXXXXXXX…" is a placeholder
						$hits[] = array(
							'rule' => 'secret-in-code', 'severity' => 'high', 'file' => $relpath, 'line' => (int) $tok[2],
							'message' => sprintf( /* translators: %s: kind of key */ __( 'A string shaped like a %s is written into the code. Keys belong in the platform\'s credential store or in wp-config.php, never in code.', 'zorderz' ), $what ),
							'sig' => 'secret|' . $what . '|' . substr( hash( 'sha256', $m[0] ), 0, 16 ), 'component' => $comp,
						);
						break;
					}
				}
			}
		}
		return $hits;
	}

	/**
	 * Functions and methods defined in a source, by lower-case name ("func",
	 * "class::method"), with what handler resolution needs and nothing more.
	 *
	 * @return array<string,array>
	 */
	public static function index_functions( string $code, string $relpath ): array {
		$t     = self::tokenize( $code );
		$out   = array();
		$class = '';
		$class_end = -1;
		$n     = count( $t );
		for ( $i = 0; $i < $n; $i++ ) {
			$tok = $t[ $i ];
			if ( $i > $class_end ) { $class = ''; }
			if ( in_array( $tok[0], array( T_CLASS, T_TRAIT ), true ) && T_STRING === ( $t[ $i + 1 ][0] ?? 0 ) && T_DOUBLE_COLON !== ( $t[ $i - 1 ][0] ?? 0 ) ) {
				$k = $i + 1;
				while ( $k < $n && ! ( 0 === $t[ $k ][0] && '{' === $t[ $k ][1] ) ) { $k++; }
				$class     = $t[ $i + 1 ][1];
				$class_end = self::close_of( $k, $n );
				continue;
			}
			if ( T_FUNCTION !== $tok[0] ) { continue; }
			$j = $i + 1;
			if ( 0 === ( $t[ $j ][0] ?? -1 ) && '&' === ( $t[ $j ][1] ?? '' ) ) { $j++; }
			if ( T_STRING !== ( $t[ $j ][0] ?? 0 ) ) { continue; } // a closure
			$name = $t[ $j ][1];
			$info = self::fn_info( $t, $i, $n - 1, $class );
			if ( null === $info ) { continue; } // abstract / interface
			$key = strtolower( '' !== $class ? $class . '::' . $name : $name );
			$info['file'] = $relpath;
			$out[ $key ]  = $info;
			$i = $info['_end']; // nested named functions are rare; skip the body
		}
		foreach ( $out as $k => $v ) { unset( $out[ $k ]['_end'] ); }
		return $out;
	}

	/**
	 * What a function / closure body does, for handler resolution: does it check a nonce,
	 * is it harmless, does it compare a token, which functions/methods does it call.
	 * $at is the T_FUNCTION (or T_FN) token. Null when it has no body.
	 */
	private static function fn_info( array $t, int $at, int $limit, string $class ): ?array {
		$n = count( $t );
		$k = $at;
		while ( $k < $n && $k <= $limit && ! ( 0 === $t[ $k ][0] && '(' === $t[ $k ][1] ) ) { $k++; } // the parameter list…
		$k = self::close_of( $k, $n ) + 1;                                                          // …may hold "=>" in defaults
		while ( $k < $n && $k <= $limit && ! ( 0 === $t[ $k ][0] && in_array( $t[ $k ][1], array( '{', ';' ), true ) ) && T_DOUBLE_ARROW !== $t[ $k ][0] ) {
			if ( isset( self::$P[ $k ] ) ) { $k = self::$P[ $k ]; } // "use ( … )"
			$k++;
		}
		if ( $k > $limit ) { return null; }
		if ( $k >= $n || ';' === $t[ $k ][1] ) { return null; }
		$end = T_DOUBLE_ARROW === $t[ $k ][0] ? min( $limit, self::stmt_end( $t, $k ) ) : self::close_of( $k, $n );
		$calls = array();
		for ( $m = $k; $m < $end; $m++ ) {
			$isop = in_array( $t[ $m ][0], array( T_DOUBLE_COLON, T_OBJECT_OPERATOR ), true ) || ( defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) && T_NULLSAFE_OBJECT_OPERATOR === $t[ $m ][0] );
			if ( $isop && T_STRING === ( $t[ $m + 1 ][0] ?? 0 ) && 0 === ( $t[ $m + 2 ][0] ?? -1 ) && '(' === ( $t[ $m + 2 ][1] ?? '' ) ) {
				$who  = strtolower( (string) ( $t[ $m - 1 ][1] ?? '' ) );
				$meth = $t[ $m + 1 ][1];
				if ( in_array( $who, array( 'self', 'static', '$this' ), true ) && '' !== $class ) {
					$calls[] = $class . '::' . $meth;
				} elseif ( 'parent' === $who ) {
					$calls[] = '*::' . $meth; // any class's method of that name
				} elseif ( T_STRING === ( $t[ $m - 1 ][0] ?? 0 ) && T_DOUBLE_COLON === $t[ $m ][0] ) {
					$calls[] = ltrim( $t[ $m - 1 ][1], '\\' ) . '::' . $meth;
				} else {
					$calls[] = '->' . $meth; // $wpdb->query, $obj->save: not resolvable, never harmless
				}
			} elseif ( '' !== self::fname( $t[ $m ] ) && 0 === ( $t[ $m + 1 ][0] ?? -1 ) && '(' === ( $t[ $m + 1 ][1] ?? '' )
				&& ! in_array( $t[ $m - 1 ][0] ?? 0, array( T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_FUNCTION, T_NEW ), true ) ) {
				$calls[] = self::fname( $t[ $m ] );
			}
		}
		$calls = array_values( array_unique( array_map( 'strtolower', $calls ) ) );
		// A secret token compared with ==/=== (not timing-safe) still guards the handler.
		$token_cmp = 0;
		for ( $m = $k; $m < $end && ! $token_cmp; $m++ ) {
			if ( in_array( $t[ $m ][0], array( T_IS_IDENTICAL, T_IS_NOT_IDENTICAL, T_IS_EQUAL, T_IS_NOT_EQUAL ), true ) ) {
				foreach ( array( $t[ $m - 1 ] ?? null, $t[ $m + 1 ] ?? null ) as $side ) {
					if ( $side && T_VARIABLE === $side[0] && preg_match( '/token|secret|signature|hmac/i', $side[1] ) ) { $token_cmp = (int) $t[ $m ][2]; }
				}
			}
		}
		$nonce    = self::guard_in( $t, $k, $end );
		$harmless = ! array_diff( $calls, self::HARMLESS_CALLS );
		return array(
			'nonce'     => $nonce,
			'harmless'  => $harmless,
			'token_cmp' => $token_cmp,
			// Only an unguarded body needs its calls, and only those that could resolve to our
			// own code: not "$obj->x()" and not a PHP or WordPress core function.
			'calls'     => ( $nonce || $harmless ) ? array() : array_values( array_filter( $calls, array( __CLASS__, 'resolvable' ) ) ),
			'line'      => (int) $t[ $at ][2],
			'_end'      => $end,
		);
	}

	/** Could this call name one of our own functions or methods? */
	public static function resolvable( string $c ): bool {
		static $cache = array();
		if ( 0 === strpos( $c, '->' ) ) { return false; }
		if ( false !== strpos( $c, '::' ) ) { return true; }
		if ( isset( $cache[ $c ] ) ) { return $cache[ $c ]; }
		$ok = true;
		if ( function_exists( $c ) ) {
			try {
				$rf   = new ReflectionFunction( $c );
				$file = (string) $rf->getFileName();
				$core = defined( 'ABSPATH' ) && defined( 'WPINC' ) && ( 0 === strpos( $file, ABSPATH . WPINC . '/' ) || 0 === strpos( $file, ABSPATH . 'wp-admin/' ) );
				$ok   = ! $rf->isInternal() && ! $core;
			} catch ( \Throwable $e ) { $ok = true; }
		}
		return $cache[ $c ] = $ok;
	}

	/** A nonce check (or hash_equals) whose result is used: not a bare statement. */
	private static function guard_in( array $t, int $a, int $b ): bool {
		for ( $k = $a; $k <= $b && $k < count( $t ); $k++ ) {
			$f = self::fname( $t[ $k ] );
			if ( '' === $f || ! in_array( $f, self::NONCE_FUNCS, true ) || ! ( 0 === ( $t[ $k + 1 ][0] ?? -1 ) && '(' === ( $t[ $k + 1 ][1] ?? '' ) ) ) { continue; }
			if ( in_array( $f, array( 'check_admin_referer', 'check_ajax_referer' ), true ) ) { return true; } // these die on failure by themselves
			$p     = $t[ $k - 1 ] ?? array( 0, ';', 0 );
			$after = $t[ self::close_of( $k + 1, count( $t ) ) + 1 ] ?? array( 0, ';', 0 );
			if ( 0 === $p[0] && in_array( $p[1], array( ';', '{', '}' ), true )
				&& ! in_array( $after[0], array( T_LOGICAL_OR, T_BOOLEAN_OR, T_LOGICAL_AND, T_BOOLEAN_AND ), true ) ) {
				continue; // result thrown away (but "wp_verify_nonce( … ) or die()" uses it)
			}
			return true;
		}
		return false;
	}

	/**
	 * Turn the meta rows (handlers, REST routes) of one component into findings, using
	 * the component's function index. Pure.
	 */
	public static function resolve_component( array $hits, array $index ): array {
		$out    = array();
		$public = array();
		$seen   = array();
		self::$memo      = array();
		self::$by_method = array();
		foreach ( array_keys( $index ) as $key ) {
			$p = strpos( $key, '::' );
			if ( false !== $p ) { self::$by_method[ substr( $key, $p ) ][] = $key; }
		}
		foreach ( $hits as $h ) {
			if ( '_rest_public' === $h['rule'] ) {
				$public[ $h['component'] ][] = $h;
				continue;
			}
			if ( '_handler' !== $h['rule'] ) {
				$out[] = $h;
				continue;
			}
			if ( ! empty( $h['inline'] ) ) {
				$info = $h['inline'];
			} else {
				$ref = strtolower( (string) ( $h['ref'] ?? '' ) );
				if ( '' === $ref || ! isset( $index[ $ref ] ) ) {
					continue; // could not resolve: say nothing rather than guess
				}
				$info = $index[ $ref ];
				$h['line'] = (int) $info['line'];
				$h['file'] = (string) $info['file'];
			}
			if ( ! empty( $info['harmless'] ) || self::info_reaches_nonce( $info, $index, 3 ) ) {
				continue;
			}
			if ( ! empty( $info['token_cmp'] ) ) {
				$tc = 'token-compare|' . ( '' !== (string) ( $h['ref'] ?? '' ) ? $h['ref'] : $h['message'] );
				if ( isset( $seen[ $tc ] ) ) { continue; } // one row per function, not per hook
				$seen[ $tc ] = true;
				$out[] = array(
					'rule'      => 'token-compare',
					'severity'  => 'low',
					'file'      => $h['file'],
					'line'      => (int) $info['token_cmp'],
					'message'   => __( 'A secret token is compared with == / === , which leaks timing. Use hash_equals().', 'zorderz' ),
					'sig'       => $tc,
					'component' => $h['component'],
				);
				continue;
			}
			if ( self::read_only_hook( $h['message'] ) ) {
				continue;
			}
			$out[] = array(
				'rule'      => 'handler-no-nonce',
				'severity'  => 'medium',
				'file'      => $h['file'],
				'line'      => $h['line'],
				'message'   => sprintf( /* translators: %s: hook name */ __( 'The "%s" handler checks no nonce, so another site could make a signed-in user trigger it (CSRF). Add check_admin_referer() / check_ajax_referer().', 'zorderz' ), $h['message'] ),
				'sig'       => 'handler|' . $h['message'],
				'component' => $h['component'],
			);
		}
		foreach ( $public as $comp => $rows ) {
			$routes = array_values( array_unique( array_map( function ( $r ) { return $r['message']; }, $rows ) ) );
			sort( $routes );
			$out[] = array(
				'rule'      => 'rest-public',
				'severity'  => 'info',
				'file'      => $rows[0]['file'],
				'line'      => $rows[0]['line'],
				'message'   => sprintf( /* translators: 1: number of routes, 2: list of routes */ __( '%1$d REST route(s) anyone may call: %2$s. Check each returns nothing private.', 'zorderz' ), count( $routes ), implode( ', ', array_slice( $routes, 0, 12 ) ) . ( count( $routes ) > 12 ? ', …' : '' ) ),
				'sig'       => 'rest-public|' . sha1( implode( ',', $routes ) ),
				'component' => $comp,
			);
		}
		return $out;
	}

	/**
	 * Hooks whose name says they only read: a read word and no write word anywhere
	 * after the prefix ("example_update_status" writes).
	 */
	private static function read_only_hook( string $hook ): bool {
		$name = preg_replace( '/^(admin_post_nopriv_|admin_post_|wp_ajax_nopriv_|wp_ajax_)/', '', $hook );
		if ( preg_match( '/(^|_)(update|save|set|delete|del|remove|add|create|clear|toggle|upload|send|reset|import|confirm|reject|bind|unbind|revoke|approve|cancel|move|rename|edit|write|post|submit|store|insert|purge|run|trigger|sync|retire)(_|$)/', $name ) ) {
			return false;
		}
		return (bool) preg_match( '/(^|_)(get|fetch|load|list|poll|status|search|lookup|read|preview|ping|heartbeat|check|nonce|view|show|count)(_|$)/', $name );
	}

	/** Per resolve_component(): "::method" => [ keys ], and memoised answers. */
	private static $by_method = array();
	private static $memo      = array();

	private static function info_reaches_nonce( array $info, array $index, int $depth ): bool {
		if ( ! empty( $info['nonce'] ) ) { return true; }
		if ( $depth <= 0 ) { return false; }
		foreach ( (array) ( $info['calls'] ?? array() ) as $c ) {
			$keys = 0 === strpos( $c, '*::' ) ? ( self::$by_method[ substr( $c, 1 ) ] ?? array() ) : ( isset( $index[ $c ] ) ? array( $c ) : array() );
			foreach ( $keys as $key ) {
				$m = $key . '|' . ( $depth - 1 );
				if ( ! isset( self::$memo[ $m ] ) ) {
					self::$memo[ $m ] = false; // a cycle answers "no" while it is being worked out
					self::$memo[ $m ] = self::info_reaches_nonce( $index[ $key ], $index, $depth - 1 );
				}
				if ( self::$memo[ $m ] ) { return true; }
			}
		}
		return false;
	}

	/** register_rest_route( ns, route, args ): public / missing permission_callback. */
	private static function rest_route( array $t, int $i, int $close, array $args, callable $add ): void {
		$ns    = isset( $args[0] ) ? self::literal( $t, $args[0] ) : '';
		$route = isset( $args[1] ) ? self::literal( $t, $args[1] ) : '';
		$path  = '/' . trim( $ns, '/' ) . '/' . ltrim( $route, '/' );
		if ( ! isset( $args[2] ) ) { return; }
		$a0 = $args[2][0];
		$literal_args = T_ARRAY === $t[ $a0 ][0] || ( 0 === $t[ $a0 ][0] && '[' === $t[ $a0 ][1] );
		if ( ! $literal_args ) { return; }
		// One endpoint, or a list of endpoints: look at each array that has a 'callback'.
		$endpoints = array();
		$open = ( T_ARRAY === $t[ $a0 ][0] ) ? $a0 + 1 : $a0;
		$inner = self::split_args( $t, $open + 1, self::close_of( $open, count( $t ) ) );
		$nested = false;
		foreach ( $inner as $r ) {
			$s = $t[ $r[0] ];
			if ( T_ARRAY === $s[0] || ( 0 === $s[0] && '[' === $s[1] ) ) { $nested = true; $endpoints[] = $r; }
		}
		if ( ! $nested ) { $endpoints = array( $args[2] ); }
		$public = false;
		$missing = false;
		foreach ( $endpoints as $r ) {
			$has_cb = false; $has_perm = false;
			for ( $k = $r[0]; $k <= $r[1]; $k++ ) {
				if ( T_CONSTANT_ENCAPSED_STRING !== $t[ $k ][0] || T_DOUBLE_ARROW !== ( $t[ $k + 1 ][0] ?? 0 ) ) { continue; }
				$key = trim( $t[ $k ][1], '\'"' );
				if ( 'callback' === $key ) { $has_cb = true; }
				if ( 'permission_callback' === $key ) {
					$has_perm = true;
					$v = $t[ $k + 2 ] ?? array( 0, '', 0 );
					if ( T_CONSTANT_ENCAPSED_STRING === $v[0] && '__return_true' === trim( $v[1], '\'"' ) ) { $public = true; }
					// fn() => true / function () { return true; }
					$w = array_slice( $t, $k + 2, 12 );
					$txt = strtolower( implode( '', array_map( function ( $x ) { return $x[1]; }, $w ) ) );
					if ( preg_match( '/^(static)?(fn\(\)=>true|function\(\)\{returntrue;\})/', $txt ) ) { $public = true; }
				}
			}
			if ( $has_cb && ! $has_perm ) { $missing = true; }
		}
		if ( $public ) {
			$add( '_rest_public', 'meta', $i, $close, $path );
		}
		if ( $missing ) {
			$add( 'rest-no-permission', 'low', $i, $close, sprintf( /* translators: %s: REST route */ __( 'REST route %s has no permission_callback; WordPress then treats it as public.', 'zorderz' ), $path ) );
		}
	}

	// ── token helpers ──────────────────────────────────────────────────────────

	/** Indexes of request-input uses in [a,b] that are not neutralised for $sink. @return int[] */
	public static function tainted_in( array $t, int $a, int $b, string $sink = 'html' ): array {
		$out = array();
		$n   = count( $t );
		for ( $k = $a; $k <= $b && $k < $n; $k++ ) {
			if ( T_VARIABLE === $t[ $k ][0] && in_array( $t[ $k ][1], self::SUPERGLOBALS, true ) && ! self::safe_use( $t, $k, $a, $sink ) ) {
				if ( 'path' === $sink && '$_FILES' === $t[ $k ][1] && self::subscript_has( $t, $k, 'tmp_name' ) ) {
					continue; // PHP names the upload's temporary file itself
				}
				$out[] = $k;
			}
		}
		return $out;
	}

	/**
	 * Is the request input at $k neutralised before it reaches a $sink sink? Walks
	 * outward over the brackets between $from and $k; an enclosing call that is safe for
	 * this sink, a cast, or a comparison / isset test right beside it makes it safe.
	 */
	private static function safe_use( array $t, int $k, int $from, string $sink ): bool {
		$safe = array_merge( self::BOOLISH, self::SINK_SAFE[ $sink ] ?? array() );
		$casts = array( T_INT_CAST, T_DOUBLE_CAST, T_BOOL_CAST );
		$p = $t[ $k - 1 ] ?? array( 0, '', 0 );
		if ( in_array( $p[0], array_merge( $casts, array( T_ISSET, T_EMPTY ) ), true ) ) { return true; }
		$cmp = array( T_IS_EQUAL, T_IS_IDENTICAL, T_IS_NOT_EQUAL, T_IS_NOT_IDENTICAL, T_IS_GREATER_OR_EQUAL, T_IS_SMALLER_OR_EQUAL, T_INSTANCEOF );
		if ( in_array( $p[0], $cmp, true ) || ( 0 === $p[0] && in_array( $p[1], array( '<', '>', '!' ), true ) ) ) { return true; }
		// Skip the [..] subscripts after it, then look at what follows.
		$q = $k + 1;
		while ( isset( $t[ $q ] ) && 0 === $t[ $q ][0] && '[' === $t[ $q ][1] ) {
			$q = ( self::$P[ $q ] ?? $q ) + 1;
		}
		$nx = $t[ $q ] ?? array( 0, '', 0 );
		if ( in_array( $nx[0], $cmp, true ) ) { return true; }
		if ( 0 === $nx[0] && in_array( $nx[1], array( '<', '>' ), true ) ) { return true; }
		if ( 0 === $nx[0] && '?' === $nx[1] && ! ( 0 === ( $t[ $q + 1 ][0] ?? -1 ) && ':' === ( $t[ $q + 1 ][1] ?? '' ) ) ) { return true; } // a ternary test; "?:" returns it
		// Enclosing calls, innermost first.
		for ( $j = $k - 1; $j >= $from; $j-- ) {
			$s = $t[ $j ];
			if ( 0 === $s[0] && in_array( $s[1], array( '(', '[' ), true ) && ( self::$P[ $j ] ?? -1 ) >= $k ) {
				if ( '(' !== $s[1] ) { continue; }
				$f  = $t[ $j - 1 ] ?? array( 0, '', 0 );
				$fn = self::fname( $f );
				if ( in_array( $f[0], array( T_ISSET, T_EMPTY ), true ) || in_array( $f[0], $casts, true ) ) { return true; }
				if ( '' !== $fn && ( in_array( $fn, $safe, true ) || 0 === strpos( $fn, 'is_' ) || 0 === strpos( $fn, 'ctype_' ) ) ) { return true; }
				if ( 'sql' === $sink && 'prepare' === $fn && self::arg_index( $t, $j, $k ) > 0 ) { return true; } // a prepare() VALUE, not its query string
				if ( 'sql' === $sink && 'esc_sql' === $fn && self::quoted_before( $t, $j - 1 ) ) { return true; }      // '…' . esc_sql( x ) . '…'
			} elseif ( in_array( $s[0], $casts, true ) && self::cast_covers( $t, $j, $k ) ) {
				return true;
			}
		}
		return false;
	}

	/** Does the variable at $k have a ['$key'] subscript? */
	private static function subscript_has( array $t, int $k, string $key ): bool {
		$q = $k + 1;
		while ( isset( $t[ $q ] ) && 0 === $t[ $q ][0] && '[' === $t[ $q ][1] ) {
			if ( T_CONSTANT_ENCAPSED_STRING === ( $t[ $q + 1 ][0] ?? 0 ) && $key === trim( $t[ $q + 1 ][1], '\'"' ) ) { return true; }
			$q = ( self::$P[ $q ] ?? $q ) + 1;
		}
		return false;
	}

	/** Which top-level argument of the call opened at $open holds token $k (0-based). */
	private static function arg_index( array $t, int $open, int $k ): int {
		$idx = 0;
		for ( $m = $open + 1; $m < $k; $m++ ) {
			if ( isset( self::$P[ $m ] ) ) {
				if ( self::$P[ $m ] >= $k ) { return $idx; } // $k sits inside a nested call in THIS argument
				$m = self::$P[ $m ];
				continue;
			}
			if ( 0 === $t[ $m ][0] && ',' === $t[ $m ][1] ) { $idx++; }
		}
		return $idx;
	}

	/** Is the esc_sql() call whose name token is $f preceded by a string ending in a quote? */
	private static function quoted_before( array $t, int $f ): bool {
		$d = $t[ $f - 1 ] ?? array( 0, '', 0 );
		$s = $t[ $f - 2 ] ?? array( 0, '', 0 );
		return 0 === $d[0] && '.' === $d[1] && T_CONSTANT_ENCAPSED_STRING === $s[0] && "'" === substr( substr( $s[1], 1, -1 ), -1 );
	}

	/** A cast at $c applies to $k when $k is its operand (the next value, with its brackets). */
	private static function cast_covers( array $t, int $c, int $k ): bool {
		$q = $c + 1;
		if ( $q === $k ) { return true; }
		if ( 0 === $t[ $q ][0] && '(' === $t[ $q ][1] && ( self::$P[ $q ] ?? -1 ) >= $k ) { return true; }
		return false;
	}

	/** Does [a,b] call any of $names (as a function or a method)? */
	private static function calls_in( array $t, int $a, int $b, array $names ): bool {
		$n = count( $t );
		for ( $k = $a; $k <= $b && $k < $n; $k++ ) {
			if ( in_array( self::fname( $t[ $k ] ), $names, true ) && 0 === ( $t[ $k + 1 ][0] ?? -1 ) && '(' === ( $t[ $k + 1 ][1] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	/** Every variable in the range sits inside escapeshellarg( … ). */
	private static function all_vars_escaped( array $t, array $r ): bool {
		for ( $k = $r[0]; $k <= $r[1]; $k++ ) {
			if ( T_VARIABLE !== $t[ $k ][0] ) { continue; }
			$ok = false;
			for ( $j = $k - 1; $j >= $r[0]; $j-- ) {
				if ( 0 === $t[ $j ][0] && '(' === $t[ $j ][1] && ( self::$P[ $j ] ?? -1 ) >= $k && 'escapeshellarg' === self::fname( $t[ $j - 1 ] ?? array( 0, '', 0 ) ) ) { $ok = true; break; }
			}
			if ( ! $ok ) { return false; }
		}
		return true;
	}

	/** Was $var last assigned (within ~120 tokens before $at) from an expression calling one of $fns, with no request input? */
	private static function assigned_with( array $t, int $at, string $var, array $fns ): bool {
		for ( $j = $at - 1; $j >= max( 0, $at - 160 ); $j-- ) {
			if ( T_VARIABLE !== $t[ $j ][0] || $var !== $t[ $j ][1] ) { continue; }
			$op  = $t[ $j + 1 ] ?? array( 0, '', 0 );
			$end = self::stmt_end( $t, $j );
			if ( T_CONCAT_EQUAL === $op[0] ) { // "$cmd .= …": that part must be escaped too
				if ( self::tainted_in( $t, $j + 2, $end, 'shell' ) || ! self::all_vars_escaped( $t, array( $j + 2, $end ) ) ) { return false; }
				continue;
			}
			if ( 0 === $op[0] && '=' === $op[1] ) {
				return self::calls_in( $t, $j + 2, $end, $fns ) && ! self::tainted_in( $t, $j + 2, $end, 'shell' )
					&& self::all_vars_escaped( $t, array( $j + 2, $end ) );
			}
		}
		return false;
	}

	/**
	 * Is the 'sslverify' at $i part of a request back to this site? Decided from the URL
	 * argument of the enclosing wp_remote_*() call — directly, or through the variable it
	 * names, assigned just above. A site URL merely nearby is not enough.
	 */
	private static function loopback_request( array $t, int $i ): bool {
		for ( $j = $i - 1; $j >= max( 0, $i - 400 ); $j-- ) {
			if ( ! ( 0 === $t[ $j ][0] && '(' === $t[ $j ][1] && ( self::$P[ $j ] ?? -1 ) > $i ) ) { continue; }
			$fn = self::fname( $t[ $j - 1 ] ?? array( 0, '', 0 ) );
			if ( ! in_array( $fn, self::REMOTE_FUNCS, true ) ) { continue; }
			$args = self::split_args( $t, $j + 1, self::$P[ $j ] );
			if ( ! isset( $args[0] ) ) { return false; }
			$a = $args[0];
			if ( $a[0] === $a[1] && T_VARIABLE === $t[ $a[0] ][0] ) {
				$var = $t[ $a[0] ][1];
				for ( $m = $j - 2; $m >= max( 0, $j - 200 ); $m-- ) {
					if ( T_VARIABLE === $t[ $m ][0] && $var === $t[ $m ][1] && 0 === ( $t[ $m + 1 ][0] ?? -1 ) && '=' === ( $t[ $m + 1 ][1] ?? '' ) ) {
						return self::site_url_expr( $t, array( $m + 2, self::stmt_end( $t, $m ) - 1 ) );
					}
				}
				return false;
			}
			return self::site_url_expr( $t, $a );
		}
		return false;
	}

	/** An expression whose URL comes from this site: it calls a site-URL function and names no other host. */
	private static function site_url_expr( array $t, array $r ): bool {
		$first = self::fname( $t[ $r[0] ] ?? array( 0, '', 0 ) );
		if ( in_array( $first, self::SITE_URL_FUNCS, true ) ) {
			return true; // the address itself starts with this site's URL
		}
		if ( 'add_query_arg' === $first && 0 === ( $t[ $r[0] + 1 ][0] ?? -1 ) ) { // add_query_arg( …, site_url( … ) )
			$args = self::split_args( $t, $r[0] + 2, self::close_of( $r[0] + 1, count( $t ) ) );
			$last = end( $args );
			return $last && in_array( self::fname( $t[ $last[0] ] ), self::SITE_URL_FUNCS, true );
		}
		return false; // a constant, a property or a literal host
	}

	/** End of the statement that starts at $i (';' at depth 0, or a closing PHP tag). */
	private static function stmt_end( array $t, int $i ): int {
		$n = count( $t );
		for ( $k = $i + 1; $k < $n; $k++ ) {
			$s = $t[ $k ];
			if ( in_array( $s[0], array( T_CLOSE_TAG, T_ECHO, T_PRINT, T_OPEN_TAG_WITH_ECHO ), true ) && $k > $i + 1 ) { return $k - 1; } // a new statement began: the last one had no ';'
			if ( isset( self::$P[ $k ] ) ) { $k = self::$P[ $k ]; continue; }
			if ( 0 === $s[0] && in_array( $s[1], array( ')', ']', '}' ), true ) ) { return $k - 1; }
			if ( 0 === $s[0] && ';' === $s[1] ) { return $k; }
		}
		return $n - 1;
	}

	private static function close_of( int $open, int $n ): int {
		return self::$P[ $open ] ?? ( $n - 1 );
	}

	/** Argument token ranges [from, to] between the ( before $a and the ) at $close. */
	private static function split_args( array $t, int $a, int $close ): array {
		$args  = array();
		$start = $a;
		for ( $k = $a; $k < $close; $k++ ) {
			if ( isset( self::$P[ $k ] ) ) { $k = min( self::$P[ $k ], $close ); continue; }
			if ( 0 === $t[ $k ][0] && ',' === $t[ $k ][1] ) {
				if ( $k > $start ) { $args[] = array( $start, $k - 1 ); }
				$start = $k + 1;
			}
		}
		if ( $close > $start ) { $args[] = array( $start, $close - 1 ); }
		return $args;
	}

	private static function text( array $t, array $r ): string {
		$s = '';
		for ( $k = $r[0]; $k <= $r[1]; $k++ ) { $s .= $t[ $k ][1]; }
		return $s;
	}

	/** A single quoted string argument's value, else ''. */
	private static function literal( array $t, array $r ): string {
		if ( $r[0] === $r[1] && T_CONSTANT_ENCAPSED_STRING === $t[ $r[0] ][0] ) {
			return substr( $t[ $r[0] ][1], 1, -1 );
		}
		return '';
	}

	/** "func", "class::method" for a callback argument, or ''. */
	private static function callback_ref( array $t, array $r ): string {
		$s = self::text( $t, $r );
		if ( preg_match( '/^[\'"]\\\\?([A-Za-z_][A-Za-z0-9_]*)[\'"]$/', $s, $m ) ) { return $m[1]; }
		if ( preg_match( '/^[\'"]([A-Za-z_][A-Za-z0-9_]*)::([A-Za-z_][A-Za-z0-9_]*)[\'"]$/', $s, $m ) ) { return $m[1] . '::' . $m[2]; }
		if ( preg_match( '/^(?:array\(|\[)(.+?),[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"](?:\)|\])$/', $s, $m ) ) {
			$who = $m[1];
			if ( in_array( $who, array( '__CLASS__', 'self::class', 'static::class', '$this' ), true ) ) {
				$cls = self::class_at( $t, $r[0] );
				return '' !== $cls ? $cls . '::' . $m[2] : '';
			}
			if ( preg_match( '/^[\'"]?\\\\?([A-Za-z_][A-Za-z0-9_]*)[\'"]?(?:::class)?$/', $who, $w ) ) { return $w[1] . '::' . $m[2]; }
		}
		return '';
	}

	/** Name of the class whose body contains token $at, or ''. */
	private static function class_at( array $t, int $at ): string {
		for ( $k = $at; $k >= 0; $k-- ) {
			if ( in_array( $t[ $k ][0], array( T_CLASS, T_TRAIT ), true ) && T_STRING === ( $t[ $k + 1 ][0] ?? 0 ) ) {
				$o = $k + 1;
				while ( isset( $t[ $o ] ) && ! ( 0 === $t[ $o ][0] && '{' === $t[ $o ][1] ) ) { $o++; }
				if ( ( self::$P[ $o ] ?? -1 ) >= $at ) { return $t[ $k + 1 ][1]; }
			}
		}
		return '';
	}

	/** A hash of the statement's whitespace-free text: stable identity, never the text. */
	private static function norm( array $t, int $a, int $b ): string {
		$s = '';
		for ( $k = max( 0, $a ); $k <= $b && $k < count( $t ); $k++ ) { $s .= $t[ $k ][1]; }
		return sha1( (string) preg_replace( '/\s+/', '', $s ) );
	}

	/** "example-plugin" for plugins/example-plugin/…, "example-theme" for themes/example-theme/…, "example-mu" for mu-plugins/example-mu.php. */
	public static function component_of( string $relpath ): string {
		$p = str_replace( '\\', '/', $relpath );
		if ( preg_match( '#^(?:plugins|themes)/([^/]+)/#', $p, $m ) ) { return $m[1]; }
		if ( preg_match( '#^mu-plugins/([^/]+?)(?:\.php)?(?:/|$)#', $p, $m ) ) { return $m[1]; }
		return 'first-party';
	}

	/**
	 * Skip folders that are not running code: tests, third-party libraries, fixtures, and the
	 * installer copy of the apps bundle that the platform theme carries for its first install.
	 */
	public static function skip_path( string $relpath ): bool {
		$p = '/' . str_replace( '\\', '/', $relpath );
		return (bool) preg_match( '#/(tests?|vendor|node_modules|fixtures)/#i', $p )
			|| (bool) preg_match( '#^/themes/zorderz[^/]*/bundled/#i', $p );
	}

	// ───────────────────────────────────────────────────────────────────────────
	// WORDPRESS PART: list the files, walk them in slices, reconcile findings.
	// ───────────────────────────────────────────────────────────────────────────

	/** Every first-party PHP file, as [ relpath => abspath ], sorted. Unreadable folders are skipped. */
	public static function files(): array {
		$roots = array();
		$plug  = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : WP_CONTENT_DIR . '/plugins';
		$mu    = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
		foreach ( (array) glob( $plug . '/*', GLOB_ONLYDIR ) as $d ) {
			if ( ZSM_Inventory::is_first_party( basename( $d ) ) ) { $roots[ 'plugins/' . basename( $d ) ] = $d; }
		}
		foreach ( (array) glob( get_theme_root() . '/*', GLOB_ONLYDIR ) as $d ) {
			if ( ZSM_Inventory::is_first_party( basename( $d ) ) ) { $roots[ 'themes/' . basename( $d ) ] = $d; }
		}
		foreach ( (array) glob( $mu . '/*' ) as $d ) {
			if ( ZSM_Inventory::is_first_party( basename( $d, '.php' ) ) ) { $roots[ 'mu-plugins/' . basename( $d ) ] = $d; }
		}
		$out = array();
		foreach ( $roots as $rel => $abs ) {
			if ( is_file( $abs ) ) {
				if ( '.php' === substr( $abs, -4 ) ) { $out[ $rel ] = $abs; }
				continue;
			}
			try {
				$dir = new RecursiveDirectoryIterator( $abs, FilesystemIterator::SKIP_DOTS );
				$flt = new RecursiveCallbackFilterIterator( $dir, function ( $f ) {
					if ( $f->isDir() ) {
						return $f->isReadable() && ! $f->isLink() && ! preg_match( '/^(tests?|vendor|node_modules|fixtures)$/i', $f->getFilename() );
					}
					return 'php' === strtolower( $f->getExtension() );
				} );
				foreach ( new RecursiveIteratorIterator( $flt, RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD ) as $f ) {
					$r = $rel . '/' . ltrim( str_replace( '\\', '/', substr( $f->getPathname(), strlen( $abs ) ) ), '/' );
					if ( ! self::skip_path( $r ) ) { $out[ $r ] = $f->getPathname(); }
				}
			} catch ( \Throwable $e ) {
				ZSM::log( 'code check: could not list ' . $rel . ' (' . get_class( $e ) . ')' );
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Advance the walk by up to $budget seconds (at least one file per call). A finished
	 * walk reconciles the 'codescan' findings and returns status 'done'; otherwise
	 * 'scanning'. A file being read when a request died is skipped next time.
	 *
	 * @return array{status:string,progress:float,message:string,findings?:int}
	 */
	public static function step( float $budget = 20.0 ): array {
		$t0    = microtime( true );
		// A request that died inside a big file left its name here: remember it (with its
		// size and time) so every later walk skips it until the file changes.
		$dead = get_option( self::DEAD_OPT, array() );
		$dead = is_array( $dead ) ? $dead : array();
		$mark = (string) get_option( self::CURRENT_OPT, '' );
		$all  = self::files();
		if ( '' !== $mark ) {
			if ( isset( $all[ $mark ] ) ) { $dead[ $mark ] = self::stamp( $all[ $mark ] ); }
			update_option( self::DEAD_OPT, $dead, false );
			delete_option( self::CURRENT_OPT );
			ZSM::log( 'code check: a request died reading ' . $mark . '; it is skipped until it changes' );
		}
		$state = get_option( self::STATE_OPT, array() );
		if ( empty( $state['files'] ) || ! is_array( $state['files'] ) || ( time() - (int) ( $state['started'] ?? 0 ) ) > DAY_IN_SECONDS ) {
			$state = array( 'files' => array_keys( $all ), 'cursor' => 0, 'hits' => array(), 'index' => array(), 'started' => time(), 'skipped' => array() );
		}
		$files = $state['files'];
		$n     = count( $files );
		$read  = 0;
		while ( $state['cursor'] < $n && ( 0 === $read || ( microtime( true ) - $t0 ) < $budget ) ) {
			$rel = $files[ $state['cursor'] ];
			$state['cursor']++;
			$read++;
			$abs = $all[ $rel ] ?? '';
			if ( '' === $abs || ! is_readable( $abs ) ) { continue; }
			if ( isset( $dead[ $rel ] ) ) {
				if ( $dead[ $rel ] === self::stamp( $abs ) ) { $state['skipped'][] = $rel; continue; }
				unset( $dead[ $rel ] ); // changed since: try it again
				update_option( self::DEAD_OPT, $dead, false );
			}
			$size = (int) @filesize( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a file can vanish mid-walk
			if ( $size <= 0 ) { continue; }
			if ( $size > self::MAX_FILE ) { $state['skipped'][] = $rel; continue; }
			$big = $size > 65536;
			if ( $big ) { update_option( self::CURRENT_OPT, $rel, false ); } // a death mark
			do_action( 'zsm_codescan_reading', $rel ); // a seam for tests that simulate a request dying here
			$f0   = microtime( true );
			$code = (string) @file_get_contents( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			foreach ( self::scan_source( $code, $rel ) as $h ) { $state['hits'][] = $h; }
			$comp = self::component_of( $rel );
			foreach ( self::index_functions( $code, $rel ) as $k => $v ) { $state['index'][ $comp ][ $k ] = $v; }
			if ( $big ) { delete_option( self::CURRENT_OPT ); }
			if ( ( microtime( true ) - $f0 ) > self::FILE_SECONDS ) { ZSM::log( 'code check: ' . $rel . ' took ' . round( microtime( true ) - $f0, 1 ) . ' s' ); }
			unset( $code );
		}
		if ( $state['cursor'] < $n ) {
			update_option( self::STATE_OPT, $state, false );
			return array( 'status' => 'scanning', 'progress' => $n ? $state['cursor'] / $n : 1.0, 'message' => sprintf( /* translators: 1: files read, 2: files in all */ __( 'Reading your code: %1$d of %2$d files.', 'zorderz' ), $state['cursor'], $n ) );
		}

		// Done: resolve per component, reconcile, record.
		$by = array();
		foreach ( $state['hits'] as $h ) { $by[ $h['component'] ][] = $h; }
		$hits = array();
		foreach ( $by as $comp => $list ) {
			$hits = array_merge( $hits, self::resolve_component( $list, $state['index'][ $comp ] ?? array() ) );
		}
		$rows    = self::to_findings( $hits );
		$skipped = array_values( array_unique( (array) $state['skipped'] ) );
		// A skipped file was not read, so its open findings are not "fixed": keep them.
		global $wpdb;
		foreach ( $skipped as $rel ) {
			$keep = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . ZSM_DB::findings_table() . " WHERE kind = 'codescan' AND status <> 'resolved' AND title LIKE %s", '% — ' . $wpdb->esc_like( $rel ) . ':%' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed identifier
			foreach ( (array) $keep as $row ) { $rows[] = $row; }
		}
		ZSM_Findings::reconcile( $rows, array( 'codescan' ) );
		delete_option( self::STATE_OPT );
		$last = array( 'at' => time(), 'files' => $n, 'findings' => count( $rows ), 'seconds' => time() - (int) $state['started'], 'skipped' => $skipped );
		update_option( self::LAST_OPT, $last, false );
		return array( 'status' => 'done', 'progress' => 1.0, 'message' => sprintf( /* translators: 1: files, 2: findings, 3: optional skipped note */ __( 'Your code was read: %1$d files, %2$d finding(s)%3$s.', 'zorderz' ), $n, count( $rows ), $skipped ? ', ' . sprintf( /* translators: %d: number of files */ __( '%d file(s) skipped (too big or too slow)', 'zorderz' ), count( $skipped ) ) : '' ), 'findings' => count( $rows ) );
	}

	/** Run the whole walk (WP-CLI, tests). */
	public static function run_all( float $slice = 20.0 ): array {
		do { $r = self::step( $slice ); } while ( 'scanning' === $r['status'] );
		return $r;
	}

	public static function abort(): void {
		delete_option( self::STATE_OPT );
		delete_option( self::CURRENT_OPT );
	}

	/** Size and modification time: a changed file is tried again. */
	private static function stamp( string $abs ): string {
		return (int) @filesize( $abs ) . '|' . (int) @filemtime( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	public static function in_progress(): bool {
		$s = get_option( self::STATE_OPT, array() );
		return is_array( $s ) && ! empty( $s['files'] );
	}

	/** Once a day: the last finished walk is older than 20 hours (or there never was one). */
	public static function due(): bool {
		$l = self::last();
		return ZSM_Policy::codescan_due( (int) ( $l['at'] ?? 0 ), time() );
	}

	public static function last(): array {
		$l = get_option( self::LAST_OPT, array() );
		return is_array( $l ) ? $l : array();
	}

	/** Hits → finding rows (kind 'codescan', plane first_party). */
	public static function to_findings( array $hits ): array {
		$rows  = array();
		$count = array();
		foreach ( $hits as $h ) {
			if ( 'meta' === ( $h['severity'] ?? '' ) ) { continue; }
			$base = $h['rule'] . '|' . $h['file'] . '|' . $h['sig'];
			$count[ $base ] = ( $count[ $base ] ?? 0 ) + 1; // the same statement twice in a file stays two findings
			$rows[] = array(
				'finding_key'       => sha1( 'codescan|' . $base . '|' . $count[ $base ] ),
				'kind'              => 'codescan',
				'plane'             => 'first_party',
				'comp_type'         => 0 === strpos( $h['file'], 'themes/' ) ? 'theme' : 'plugin',
				'slug'              => $h['component'],
				'name'              => $h['component'],
				'installed_version' => '',
				'fixed_version'     => '',
				'cve'               => '',
				'title'             => $h['message'] . ' — ' . $h['file'] . ( $h['line'] ? ':' . $h['line'] : '' ) . ' [' . $h['rule'] . ']',
				'severity'          => $h['severity'],
				'cvss_score'        => null,
				'context_note'      => __( 'Found by reading your own code on this site (the Security Monitor\'s code check).', 'zorderz' ),
				'ref_url'           => '',
			);
		}
		return $rows;
	}
}
