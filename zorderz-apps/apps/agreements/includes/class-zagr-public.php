<?php
/**
 * ZAGR_Public - the route /agreement/<six words>/: a rewrite rule, template_redirect at priority 1 (before the theme's
 * login gate), the words looked up by their SHA-256, a per-IP rate limit, and on every answer: noindex, no-store (a
 * host's page cache must never keep a copy), no referrer, nosniff, no framing, and a CSP that runs only this app's own
 * script file and reads only its own stylesheet and brand-colour block.
 *
 * handle() is the whole answer as data ({ status, headers, body, cookie }), so the suites can compare answers byte for
 * byte: a wrong link, a replaced (revoked) link and a malformed one get the very same 404, and never a cookie.
 *
 * A page view makes no session, sets no cookie and writes no event; the first real step does (the PDF opened, or a form
 * posted with the page's token), at most 6 new sessions an hour per address on one link. A step's outcome is kept in
 * its session and shown once, never read from the address. Anything unexpected answers with a plain "please call us"
 * page.
 *
 * What the link alone shows: before signing, what the estimate shows plus the agreement's words; after signing, where
 * things stand (no name, no address; a waiting change order's words only after a code). Signing, the signed copy and
 * Cancel need the emailed code. Consent emails the code in the same post (the page then goes to the Sign step), and the
 * Sign form carries the code; a failed Sign goes back to the Sign step, where the page shows why (ZAGR_Flow).
 *
 * @package Zorderz\Agreements
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZAGR_Public {

	const QV       = 'zagr_words';
	const COOKIE   = 'zagr_s';
	const GET_MAX  = 30; // page and PDF loads a minute per IP
	const POST_MAX = 20; // form posts a minute per IP
	const SESSIONS_PER_HOUR = 6; // new sessions an hour per address on one link

	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'add_rewrite' ) );
		add_action( 'init', array( __CLASS__, 'maybe_flush' ), 99 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'serve' ), 1 );
	}

	public static function add_rewrite(): void {
		add_rewrite_rule( '^agreement/([^/]+)/?$', 'index.php?' . self::QV . '=$matches[1]', 'top' );
	}

	public static function query_vars( $vars ) {
		$vars[] = self::QV;
		return $vars;
	}

	/** Flush the rules once per version, so a printed link works right after an update. */
	public static function maybe_flush(): void {
		if ( get_option( 'zagr_rewrite_version', '' ) !== ZAGR_VERSION ) {
			self::add_rewrite();
			flush_rewrite_rules( false );
			update_option( 'zagr_rewrite_version', ZAGR_VERSION );
		}
	}

	public static function serve(): void {
		$w = (string) get_query_var( self::QV );
		if ( '' === $w ) {
			return;
		}
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		// belt and braces beside the no-store headers: page caches that honor it never keep an agreement page (
		// a first view no longer sets a cookie, so a cache can't rely on one; the cache exclusion is a post-deploy check)
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		$post   = 'POST' === $method ? wp_unslash( $_POST ) : array(); // phpcs:ignore -- checked per field, with the session's token
		$r      = self::handle( $w, $method, wp_unslash( $_GET ), (array) $post, $_COOKIE ); // phpcs:ignore
		self::emit( $r );
		exit;
	}

	public static function emit( array $r ): void {
		status_header( (int) $r['status'] );
		nocache_headers();
		foreach ( $r['headers'] as $k => $v ) {
			header( $k . ': ' . $v, true );
		}
		if ( ! empty( $r['cookie'] ) ) {
			$c = $r['cookie'];
			setcookie( self::COOKIE, $c['value'], array( 'expires' => 0, 'path' => $c['path'], 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
		}
		echo $r['body']; // phpcs:ignore -- built escaped by ZAGR_Page, or PDF bytes
	}

	/* ---------------------------------------------------------------- headers */

	/**
	 * Only this app's script folder runs; only its stylesheet folder and its one brand-colour block (by SHA-256) style the
	 * page; images come from this site and the host of the business's logo; forms post back to the agreement path.
	 */
	public static function csp(): string {
		$js   = ZAGR_URL . 'assets/js/';
		$css  = ZAGR_URL . 'assets/css/';
		$hash = "'sha256-" . base64_encode( hash( 'sha256', ZAGR_Page::brand_css(), true ) ) . "'";
		$img  = trim( "'self' data: " . implode( ' ', ZAGR_Page::logo_hosts() ) );
		$csp  = "default-src 'none'; script-src " . $js . '; style-src ' . $css . ' ' . $hash . '; img-src ' . $img . "; font-src 'none'; connect-src 'none'; "
			. "object-src 'none'; base-uri 'none'; form-action " . home_url( '/agreement/' ) . "; frame-ancestors 'none'";
		return (string) apply_filters( 'zagr_csp', $csp );
	}

	public static function headers( string $type = 'text/html; charset=utf-8' ): array {
		$h = array(
			'Content-Type'           => $type,
			'X-Robots-Tag'           => 'noindex, nofollow, noarchive',
			'Referrer-Policy'        => 'no-referrer',
			'Cache-Control'          => 'private, no-store, max-age=0',
			'X-Content-Type-Options' => 'nosniff',
			'X-Frame-Options'        => 'DENY',
			'Permissions-Policy'     => 'camera=(), microphone=(), geolocation=(), payment=()',
		);
		if ( 0 === strpos( $type, 'text/html' ) ) {
			$h['Content-Security-Policy'] = self::csp();
		}
		return $h;
	}

	private static function resp( int $status, string $body, array $extra = array(), ?array $cookie = null ): array {
		return array( 'status' => $status, 'headers' => array_merge( self::headers(), $extra ), 'body' => self::email_off( $body ), 'cookie' => $cookie );
	}

	/**
	 * Some CDNs rewrite every email address in an HTML page into a placeholder and add a decoder script, which this
	 * page's Content-Security-Policy refuses to run, so the addresses for cancellations would read as a placeholder.
	 * They skip whatever sits between <!--email_off--> and <!--/email_off-->, so every HTML page here carries one pair,
	 * right inside <body> and right before </body>. Harmless where no CDN does this; switch it off with the filter
	 * zagr_email_off_markers. It is added at the response, after everything that is hashed or sealed: the templates, the
	 * words shown and signed, the PDFs and the emails are unchanged, and the CSP is not loosened. A body with no <body>
	 * is wrapped whole; an empty one (a redirect) and one already marked are left alone.
	 */
	public static function email_off( string $html ): string {
		if ( '' === $html || false !== strpos( $html, '<!--email_off-->' ) || ! apply_filters( 'zagr_email_off_markers', true ) ) {
			return $html;
		}
		$open  = stripos( $html, '<body' );
		$gt    = false === $open ? false : strpos( $html, '>', $open );
		$close = strripos( $html, '</body>' );
		if ( false === $gt || false === $close || $close < $gt ) {
			return '<!--email_off-->' . $html . '<!--/email_off-->';
		}
		return substr( $html, 0, $gt + 1 ) . '<!--email_off-->' . substr( $html, $gt + 1, $close - $gt - 1 ) . '<!--/email_off-->' . substr( $html, $close );
	}

	/** The one 404: a wrong, replaced or malformed link. Never a cookie, never a hint. */
	public static function not_found(): array {
		return self::resp( 404, ZAGR_Page::plain( 'not_found_title', 'not_found_body' ) );
	}

	private static function too_many(): array {
		return self::resp( 429, ZAGR_Page::plain( 'too_many', 'help_line', array( 'phone' => ZAGR_Settings::facts()['help_phone'] ) ), array( 'Retry-After' => '60' ) );
	}

	private static function redirect( string $url ): array {
		return array( 'status' => 303, 'headers' => array_merge( self::headers(), array( 'Location' => $url ) ), 'body' => '', 'cookie' => null );
	}

	private static function pdf( array $file ): array {
		$h = self::headers( 'application/pdf' );
		$h['Content-Disposition'] = 'inline; filename="' . preg_replace( '/[^A-Za-z0-9._-]/', '', $file[1] ) . '"';
		$h['Content-Length']      = (string) strlen( $file[0] );
		return array( 'status' => 200, 'headers' => $h, 'body' => $file[0], 'cookie' => null );
	}

	/* ---------------------------------------------------------------- the rate limit */

	/**
	 * Best effort, per IP, per minute (fails open without an IP). The counter is named by ip_tag(), an
	 * HMAC of the IP under the site's salt, never the IP or an unkeyed hash of it.
	 */
	public static function rate_ok( string $kind ): bool {
		$max = (int) apply_filters( 'zagr_rate_limit', 'POST' === $kind ? self::POST_MAX : self::GET_MAX, $kind );
		$tag = ZAGR_People::ip_tag();
		if ( $max <= 0 || '' === $tag ) {
			return true;
		}
		$k = 'zagr_rl_' . strtolower( $kind ) . '_' . $tag;
		$n = (int) get_transient( $k );
		if ( $n >= $max ) {
			return false;
		}
		set_transient( $k, $n + 1, MINUTE_IN_SECONDS );
		return true;
	}

	/* ---------------------------------------------------------------- the answer */

	/** The form token of a session (a POST must carry it). */
	private static function token( string $sid ): string {
		return hash_hmac( 'sha256', 'zagr-form|' . $sid, (string) wp_salt( 'nonce' ) );
	}

	/**
	 * The form token of a page shown without a session. It is tied to the
	 * link; its POST makes the session. A session may also post it for a first step taken from a page drawn before the
	 * session existed (the PDF opened in another tab), but never to sign or cancel.
	 */
	private static function pre_token( array $row ): string {
		return hash_hmac( 'sha256', 'zagr-pre|' . (string) $row['link_hash'], (string) wp_salt( 'nonce' ) );
	}

	/** A page view's stand-in for a session: nothing done yet. */
	private static function no_session(): array {
		return array(
			'id' => 0, 'viewed_at' => null, 'consent_shown_for' => '', 'pdf_for' => '', 'pdf_sha256' => '', 'pdf_opened_at' => null,
			'consent_for' => '', 'consent_at' => null, 'verified_purpose' => '', 'verified_for' => '', 'verified_at' => null,
			'copy_until' => null, 'flash' => '',
		);
	}

	/** A new session for a real step: [ sid, row, cookie ], or null when this address made too many on this link. */
	private static function start_session( array $row, string $path ): ?array {
		$tag = ZAGR_People::ip_tag();
		$max = (int) apply_filters( 'zagr_sessions_per_hour', self::SESSIONS_PER_HOUR );
		if ( '' !== $tag && $max > 0 && ZAGR_Store::sessions_made( (int) $row['id'], $tag ) >= $max ) {
			return null;
		}
		list( $sid, $sess ) = ZAGR_Store::session_create( (int) $row['id'] );
		return array( $sid, $sess, array( 'value' => $sid, 'path' => $path ) );
	}

	private static function too_many_sessions( array $vars, bool $test ): array {
		return self::resp( 429, ZAGR_Page::plain( 'too_many', 'too_many_sessions', $vars, $test ), array( 'Retry-After' => '3600' ) );
	}

	/** Each value as a string; anything that isn't a single value (an array) becomes ''. */
	private static function scalars( array $in ): array {
		return array_map( static function ( $v ) { return is_scalar( $v ) ? (string) $v : ''; }, $in );
	}

	/**
	 * could the customer sign this agreement now? What the page below offers: ready (the business
	 * signed; a ready row is never signed yet), a mode that admits it, English answered yes, the frozen document opening,
	 * and nothing on the readiness list undone (which in test and on includes a usable key). status_for() reports it as
	 * `signable` (an order gate holds ordering for an unsigned agreement only while the customer could sign it).
	 */
	public static function signable( array $row ): bool {
		return in_array( (string) ( $row['state'] ?? '' ), array( 'ready', 'signing' ), true )
			&& self::mode_allows( $row ) && '1' === (string) ( $row['language_ok'] ?? '' )
			&& null !== ( $doc = ZAGR_Store::document( $row ) ) && ! ZAGR_Settings::missing_for( ZAGR_Settings::mode(), $doc );
	}

	/**
	 * why an unsigned agreement (draft, ready, signing) can't be signed now, '' when it
	 * can (signable() true) or when it isn't unsigned. status_for() reports it as `unsignable_why`. Two are decisions
	 * and come first: 'language_no' (the sales talk wasn't in English) and 'mode' (off, or test mode and a customer who
	 * isn't a test one). The rest are waits: 'draft' (the business hasn't signed it, or the language isn't answered),
	 * 'key' (the agreements key can't be used), 'readiness' (a Settings readiness item is undone), 'document' (the
	 * frozen document doesn't open). An order gate releases only on a decision.
	 */
	public static function unsignable_why( array $row ): string {
		$state = (string) ( $row['state'] ?? '' );
		if ( ! in_array( $state, array( 'draft', 'ready', 'signing' ), true ) ) {
			return '';
		}
		$lang = (string) ( $row['language_ok'] ?? '' );
		if ( '0' === $lang ) {
			return 'language_no';
		}
		if ( ! self::mode_allows( $row ) ) {
			return 'mode';
		}
		if ( 'draft' === $state || '1' !== $lang ) {
			return 'draft';
		}
		if ( ! ZAGR_Key::usable() ) {
			return 'key';
		}
		$doc = ZAGR_Store::document( $row );
		if ( null === $doc ) {
			return 'document';
		}
		return ZAGR_Settings::missing_for( ZAGR_Settings::mode(), $doc ) ? 'readiness' : '';
	}

	/** Is this record shown to the link? Signed records always (status and Cancel); unsigned ones as the mode allows. */
	private static function mode_allows( array $row ): bool {
		$m = ZAGR_Settings::mode();
		return 'on' === $m || ( 'test' === $m && ! empty( $row['is_test'] ) );
	}

	public static function handle( string $words, string $method, array $get, array $post, array $cookie ): array {
		try {
			return self::answer( $words, $method, $get, $post, $cookie );
		} catch ( \Throwable $e ) {
			// a template version gone from the server, or anything else unexpected: a plain page, never
			// WordPress's "critical error"
			error_log( 'Zorderz agreements: the agreement page failed (' . get_class( $e ) . ').' ); // phpcs:ignore -- never a value
			return self::resp( 503, ZAGR_Page::plain( 'unavailable_title', 'unavailable_body', array( 'phone' => ZAGR_Settings::facts()['help_phone'] ) ), array( 'Retry-After' => '3600' ) );
		}
	}

	private static function answer( string $words, string $method, array $get, array $post, array $cookie ): array {
		if ( ! ZAGR_Install::ready() ) {
			// tables a pre-release test build left: never read, never written
			return self::resp( 503, ZAGR_Page::plain( 'unavailable_title', 'unavailable_body', array( 'phone' => ZAGR_Settings::facts()['help_phone'] ) ), array( 'Retry-After' => '3600' ) );
		}
		// every field is a string; an array-valued one (age[]=…) is empty, so nothing casts an array
		$get    = self::scalars( $get );
		$post   = self::scalars( $post );
		$cookie = self::scalars( $cookie );
		$method = 'POST' === strtoupper( $method ) ? 'POST' : 'GET';
		if ( ! self::rate_ok( $method ) ) {
			return self::too_many();
		}
		$n = ZAGR_Words::normalize( $words );
		if ( ! ZAGR_Words::well_formed( $n ) ) {
			return self::not_found();
		}
		$row = ZAGR_Store::by_link_hash( ZAGR_Words::hash( $n ) );
		if ( ! $row ) {
			return self::not_found();
		}
		$facts = ZAGR_Settings::facts();
		$vars  = array( 'phone' => $facts['help_phone'], 'company' => ZAGR_Strings::company(), 'language' => ZAGR_Paper::language_name() );
		$test  = ! empty( $row['is_test'] );
		$url   = ZAGR_Words::url( $n );
		$row   = ZAGR_Store::refresh_window( $row );
		$signed = ! empty( $row['signed_at'] );

		if ( ! $signed ) {
			if ( ! self::mode_allows( $row ) || in_array( $row['state'], array( 'draft' ), true ) ) {
				return self::resp( 200, ZAGR_Page::plain( 'not_ready_title', 'not_ready_body', $vars, $test ) );
			}
			if ( '1' !== (string) $row['language_ok'] ) {
				return self::resp( 200, ZAGR_Page::plain( 'not_language_title', 'not_language_body', $vars, $test ) );
			}
			// a readiness item undone since the business signed (the template's approval taken back, a fact
			// cleared) stops signing on the page too
			$doc = ZAGR_Key::usable() ? ZAGR_Store::document( $row ) : null;
			if ( null === $doc || ZAGR_Settings::missing_for( ZAGR_Settings::mode(), $doc ) ) {
				return self::resp( 200, ZAGR_Page::plain( 'no_key_title', 'no_key_body', $vars, $test ) );
			}
		}

		// the session: only for a real step (the PDF opened, or a form posted), never for a page view
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$sid  = (string) ( $cookie[ self::COOKIE ] ?? '' );
		$sess = '' !== $sid ? ZAGR_Store::session_get( (int) $row['id'], $sid ) : null;
		$setc = null;
		$pdf  = 'GET' === $method ? (string) ( $get['pdf'] ?? '' ) : '';

		if ( 'POST' === $method ) {
			$tok = (string) ( $post['tok'] ?? '' );
			$do  = (string) ( $post['do'] ?? '' );
			$pre = hash_equals( self::pre_token( $row ), $tok );
			if ( ! $sess ) {
				if ( ! $pre ) {
					return self::redirect( $url . '#top' ); // a stale or foreign form, and no session to tell: the page again
				}
				$new = self::start_session( $row, $path );
				if ( null === $new ) {
					return self::too_many_sessions( $vars, $test );
				}
				list( $sid, $sess, $setc ) = $new;
			} elseif ( ! hash_equals( self::token( $sid ), $tok ) && ! ( $pre && in_array( $do, array( 'age', 'consent', 'send_code' ), true ) ) ) {
				ZAGR_Store::session_update( (int) $sess['id'], array( 'flash' => 'm_session', 'last_seen_at' => ZAGR_Clock::utc() ) );
				return self::redirect( $url . '#top' );
			}
			ZAGR_Store::session_update( (int) $sess['id'], array( 'last_seen_at' => ZAGR_Clock::utc() ) );
			$r           = self::post( $row, $sess, $post, $url );
			$r['cookie'] = $setc;
			return $r;
		}

		if ( '' !== $pdf ) {
			if ( 'preview' === $pdf && ! $sess ) {
				$new = self::start_session( $row, $path ); // opening the PDF is a real step
				if ( null === $new ) {
					return self::too_many_sessions( $vars, $test );
				}
				list( $sid, $sess, $setc ) = $new;
			}
			if ( ! $sess ) {
				// the signed copy needs a code: the page says how
				return self::redirect( $url . ( 'signed' === $pdf && ZAGR_Paper::is_paper( $row ) ? '#top' : '#manage' ) );
			}
			ZAGR_Store::session_update( (int) $sess['id'], array( 'last_seen_at' => ZAGR_Clock::utc() ) );
			$file = 'preview' === $pdf ? ZAGR_Flow::open_preview( $row, $sess )
				: ( in_array( $pdf, array( 'signed', 'change' ), true ) ? ZAGR_Flow::signed_pdf( $row, $sess, 'signed' === $pdf ? 'agreement' : 'change' ) : null );
			if ( null === $file ) {
				$tg   = ZAGR_Flow::target( $row, $sess );
				$why  = 'preview' !== $pdf || ZAGR_Flow::code_first( $row ) ? 'm_code_first' : ( $tg && 'agreement' === $tg['kind'] && '' === $tg['for'] ? 'm_age_first' : 'm_not_now' );
				if ( 'signed' === $pdf && ZAGR_Paper::is_paper( $row ) ) {
					$why = 'm_paper_no_copy';   // signed on paper, so no copy here
				}
				ZAGR_Store::session_update( (int) $sess['id'], array( 'flash' => $why ) );
				$r           = self::redirect( $url . ( 'preview' !== $pdf ? '#manage' : ( ZAGR_Flow::code_first( $row ) ? '#change' : ( 'm_age_first' === $why ? '#age' : '#top' ) ) ) );
				$r['cookie'] = $setc;
				return $r;
			}
			$r           = self::pdf( $file );
			$r['cookie'] = $setc;
			return $r;
		}

		// a page view
		$flash = '';
		if ( $sess ) {
			ZAGR_Store::session_update( (int) $sess['id'], array( 'last_seen_at' => ZAGR_Clock::utc() ) );
			$sess  = ZAGR_Store::session_by_id( (int) $sess['id'] ) ?? $sess;
			$flash = (string) ( $sess['flash'] ?? '' );
			if ( '' !== $flash ) {
				ZAGR_Store::session_update( (int) $sess['id'], array( 'flash' => '' ) ); // shown once
			}
			if ( ZAGR_Clock::ts( (string) $sess['viewed_at'] ) < ZAGR_Clock::now() - 1800 ) { // "viewed", once per session per half hour
				ZAGR_Events::append( (int) $row['id'], 'viewed', array(), 'customer', (int) $sess['id'] );
				ZAGR_Store::session_update( (int) $sess['id'], array( 'viewed_at' => ZAGR_Clock::utc() ) );
			}
		} else {
			$sess = self::no_session();
		}
		$flash = ( ZAGR_Strings::has( $flash ) && 0 === strpos( $flash, 'm_' ) ) ? $flash : '';
		$vm    = array( 'test' => $test, 'message' => $flash, 'token' => $sess['id'] ? self::token( $sid ) : self::pre_token( $row ), 'action' => $url );
		$vm['vars'] = $vars;
		$body = $signed ? self::status_page( $row, $sess, $vm, $facts ) : self::sign_page( $row, $sess, $vm );
		if ( null === $body ) {
			return self::resp( 200, ZAGR_Page::plain( 'no_key_title', 'no_key_body', $vars, $test ) );
		}
		return self::resp( 200, $body );
	}

	/** A step: its outcome is kept in the session as a one-time message. */
	private static function post( array $row, array $sess, array $post, string $url ): array {
		$do      = (string) ( $post['do'] ?? '' );
		$purpose = (string) ( $post['purpose'] ?? '' );
		$purpose = in_array( $purpose, ZAGR_Codes::PURPOSES, true ) ? $purpose : '';
		$manage  = 'manage' === $purpose;
		$change  = 'change' === $purpose;
		switch ( $do ) {
			case 'age':
				$m = ZAGR_Flow::answer_age( $row, $sess, $post );
				$a = '#age';
				break;
			case 'consent':
				$m = ZAGR_Flow::consent( $row, $sess, $post );
				if ( ZAGR_Flow::code_first( $row ) ) {
					$a = '' === $m ? '#step4' : '#step3';   // a change order: its consent is step 3
				} else {
					// consent recorded (the code sent, or why not): on to the Sign step; refused: back to step 2
					$a = in_array( $m, array( 'm_not_now', 'm_age_first', 'm_pdf_first', 'm_consent_needed', 'm_busy' ), true ) ? '#step2' : '#step3';
				}
				break;
			case 'send_code':
				$m = '' === $purpose ? 'm_not_now' : ZAGR_Flow::send_code( $row, $sess, $purpose );
				$a = $manage ? '#manage' : ( $change ? '#step1' : '#step3' );
				break;
			case 'verify_code':
				$m = '' === $purpose ? 'm_not_now' : ZAGR_Flow::verify_code( $row, $sess, $purpose, (string) ( $post['code'] ?? '' ) );
				$a = $manage ? '#manage' : ( 'm_code_ok' === $m ? ( $change ? '#step2' : '#step4' ) : ( $change ? '#step1' : '#step3' ) );
				break;
			case 'sign':
				$m = ZAGR_Flow::sign( $row, $sess, $post );
				$a = in_array( $m, array( 'm_signed', 'm_signed_nomail', 'm_signed_unrecorded', 'm_sign_failed', 'm_not_now' ), true ) ? '#top' : '#step3';
				break;
			case 'sign_change':
				$m = ZAGR_Flow::sign_change( $row, $sess, $post );
				$a = in_array( $m, array( 'm_change_signed', 'm_change_signed_nomail', 'm_sign_failed', 'm_not_now' ), true ) ? '#top' : '#step4';
				break;
			case 'cancel':
				$m = ZAGR_Flow::cancel( $row, $sess, $post );
				$a = '#top';
				break;
			case 'done':
				$m = ZAGR_Flow::done( $row, $sess );
				$a = '#top';
				break;
			default:
				$m = 'm_not_now';
				$a = '#top';
		}
		ZAGR_Store::session_update( (int) $sess['id'], array( 'flash' => $m ) );
		return self::redirect( $url . $a );
	}

	/* ---------------------------------------------------------------- the two pages */

	private static function code_sent( array $sess, string $purpose ): bool {
		global $wpdb;
		if ( empty( $sess['id'] ) ) {
			return false;
		}
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . ZAGR_Install::t( 'codes' ) . ' WHERE session_id = %d AND purpose = %s AND used_at IS NULL AND expires_at > %s LIMIT 1', (int) $sess['id'], $purpose, ZAGR_Clock::utc() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name (ZAGR_Install::tables()); every value is a placeholder
	}

	private static function sign_vm( array $row, array $sess, array $vm, array $t ): ?array {
		$email = ZAGR_People::mask_email( (string) ZAGR_Store::email( $row ) );
		if ( 'change' === $t['kind'] && ! ZAGR_Flow::verified( $sess, 'change', $t['for'] ) ) {
			// after signing, the change order's words (a name, an address) are drawn only for a session with the code
			return $vm + array( 'kind' => 'change', 'locked' => true, 'email_masked' => $email, 'code_sent' => self::code_sent( $sess, 'change' ), 'code_ok' => false );
		}
		$today = ZAGR_Clock::today();
		if ( 'agreement' === $t['kind'] && '' === $t['age'] ) {
			// before the answer, the sections that don't depend on it, and the question with the rule; no notice
			$doc = ZAGR_Store::document( $row );
			if ( ! $doc ) {
				return null;
			}
			$b = ZAGR_Document::before_age( $doc, array( 'sign_date' => $today, 'url' => $vm['action'] ) );
			return $vm + array( 'kind' => 'agreement', 'age' => '', 'age_q' => self::age_question( $doc ), 'sections' => $b['sections'], 'locked' => false,
				'order' => $b['order'], 'age_sections' => $b['age_sections'], 'layout' => $b['layout'] );
		}
		$x     = ZAGR_Flow::render( $row, $t, $today );
		if ( ! $x ) {
			return null;
		}
		$consent = ZAGR_Document::consent( $x['doc'], array( 'sign_date' => $today, 'age' => $t['age'], 'url' => $vm['action'] ) );
		if ( ! empty( $sess['id'] ) && (string) $sess['consent_shown_for'] !== $t['for'] ) {
			ZAGR_Events::append( (int) $row['id'], 'consent_shown', array( 'for' => $t['for'], 'consent_sha256' => $consent['hash'] ), 'customer', (int) $sess['id'] );
			ZAGR_Store::session_update( (int) $sess['id'], array( 'consent_shown_for' => $t['for'] ) );
		}
		return $vm + array(
			'kind'           => $t['kind'],
			'age'            => $t['age'],
			'age_days'       => 'agreement' === $t['kind'] ? ZAGR_Document::window_days( $x['doc'], $t['age'] ) : 0,
			'age_q'          => 'agreement' === $t['kind'] ? self::age_question( $x['doc'] ) : array(),
			'locked'         => false,
			'sections'       => $x['render']['sections'],
			'change_html'    => 'change' === $t['kind'] ? $x['render']['sections']['change-order'] : '',
			'consent_html'   => $consent['html'],
			'doc_sha'        => $x['render']['hash'],
			'sign_date'      => $today,
			'sign_date_long' => ZAGR_Calendar::long_date( $today ),
			'email_masked'   => $email,
			'pdf_url'        => $vm['action'] . '?pdf=preview',
			'pdf_done'       => $t['for'] === (string) $sess['pdf_for'] && ! empty( $sess['pdf_opened_at'] ),
			'consent_done'   => $t['for'] === (string) $sess['consent_for'],
			'code_sent'      => self::code_sent( $sess, $t['purpose'] ),
			'code_ok'        => ZAGR_Flow::verified( $sess, $t['purpose'], $t['for'] ),
			// the template's order and look, and the name typed before an error (read once, then gone)
			'order'          => array_keys( $x['render']['sections'] ),
			'age_sections'   => (array) ( $x['render']['age_sections'] ?? array() ),
			'layout'         => (string) ( $x['render']['layout'] ?? 'classic' ),
			'kept_name'      => ZAGR_Flow::kept_name( $sess ),
		);
	}

	/** The legal pack's wording of the age question (label, why, yes, no), or none: the page then uses its own. */
	private static function age_question( array $doc ): array {
		$q = ZAGR_Pack::questions( ZAGR_Document::stack( $doc ) )['senior'] ?? array();
		return array(
			'label' => (string) ( $q['label'] ?? '' ),
			'why'   => (string) ( $q['why'] ?? '' ),
			'yes'   => (string) ( $q['yes'] ?? '' ),
			'no'    => (string) ( $q['no'] ?? '' ),
		);
	}

	private static function sign_page( array $row, array $sess, array $vm ): ?string {
		$t = ZAGR_Flow::target( $row, $sess );
		if ( ! $t ) {
			return ZAGR_Page::plain( 'not_ready_title', 'not_ready_body', $vm['vars'], $vm['test'] );
		}
		$s = self::sign_vm( $row, $sess, $vm, $t );
		return $s ? ZAGR_Page::sign( $s ) : null;
	}

	/** "third", "fifth"…: the signed agreement's last day to cancel, as the notice words it ('' when unreadable). */
	private static function ordinal( array $row ): string {
		$doc = ZAGR_Store::document( $row );
		$age = ZAGR_Flow::signed_age( $row );
		$w   = null === $doc || '' === $age ? null : ZAGR_Document::window( $doc, $age );
		return null === $w ? __( 'last', 'zorderz' ) : (string) $w['ordinal'];
	}

	private static function status_page( array $row, array $sess, array $vm, array $facts ): ?string {
		$id     = (int) $row['id'];
		$now    = ZAGR_Clock::now();
		$by     = substr( (string) $row['cancel_by'], 0, 10 );
		$frozen = ZAGR_Key::usable() ? ZAGR_Flow::facts( $row ) : array( 'cancel_email' => $facts['cancel_email'], 'address_lines' => $facts['address_lines'], 'help_phone' => $facts['help_phone'] );
		$vm['vars'] = array(
			'company' => ZAGR_Key::usable() ? ZAGR_Flow::company( $row ) : $vm['vars']['company'],
			'phone'   => $frozen['help_phone'] ?: $facts['help_phone'],
			'email'   => $frozen['cancel_email'] ?: $facts['cancel_email'],
			'address' => $frozen['address_lines'] ? implode( ', ', $frozen['address_lines'] ) : 'the address in your agreement',
			'refund'  => ZAGR_Strings::refund_words( ZAGR_Key::usable() ? ZAGR_Document::stack( (array) ZAGR_Store::document( $row ) ) : array() ),
		);
		list( , , $sched ) = ZAGR_Store::signed_current( $row );
		$sched  = $sched ?: ZAGR_Store::schedule( $row );
		$rows   = array();
		foreach ( ZAGR_Document::schedule_rows( $sched + array( 'deposit_cents' => 0, 'balance_cents' => 0 ) ) as $r ) {
			$rows[] = $r;
		}
		$no_key = ! ZAGR_Key::usable();
		$change = null;
		if ( 'change_pending' === $row['state'] && ! $no_key ) {
			$t = ZAGR_Flow::target( $row );
			if ( $t ) {
				$c = self::sign_vm( $row, $sess, $vm, $t );
				$change = $c ? $c + array( 'kind' => 'change' ) : null;
			} else {
				$change = array( 'kind' => 'preparing' );
			}
		}
		$last_change = ZAGR_Store::last_signed_change( $id );
		$paper       = ZAGR_Paper::is_paper( $row );   // signed on paper (its own words, no copy online)
		if ( $paper ) {
			$vm['message'] = array( 'm_manage_ok' => 'm_manage_ok_paper', 'm_done' => 'm_done_paper' )[ (string) $vm['message'] ] ?? (string) $vm['message'];
		}
		$started     = '' !== $by; // the window counts only from the day the copy was received
		$verified    = ZAGR_Flow::verified( $sess, 'manage', 'manage:' . $id );
		$may_open    = ! $no_key && ZAGR_Flow::may_open_copy( $row, $sess );
		$vm += array(
			'paper'           => $paper,
			'state'           => (string) $row['state'],
			'signed_long'     => ZAGR_Calendar::long_date( ZAGR_Clock::local_day( ZAGR_Clock::ts( (string) $row['signed_at'] ) ) ),
			'copy_via'        => (string) $row['copy_via'],
			'copy_long'       => empty( $row['copy_received_at'] ) ? '' : ZAGR_Calendar::long_date( ZAGR_Clock::local_day( ZAGR_Clock::ts( (string) $row['copy_received_at'] ) ) ),
			'window_started'  => $started,
			'ordinal'         => self::ordinal( $row ),
			'cancel_by_long'  => $started ? ZAGR_Calendar::long_date( $by ) : '',
			'end_ts'          => $started ? ZAGR_Calendar::end_ts( $by ) : 0,
			'now'             => $now,
			'window_open'     => 'cancelled' !== $row['state'] && ( ! $started || ZAGR_Calendar::window_open( $by, $now ) ),
			'window_closed'   => ! empty( $row['window_closed_at'] ) || ( $started && ! ZAGR_Calendar::window_open( $by, $now ) ),
			// "We can now place your order." only when it is so (never while a late paper signing waits for an
			// administrator); nothing else changes on the page, and nothing about why
			'order_note'      => ! ZAGR_Paper::awaiting_confirm( $row ),
			'cancelled_long'  => empty( $row['cancelled_at'] ) ? '' : ZAGR_Calendar::long_date( ZAGR_Clock::local_day( ZAGR_Clock::ts( (string) $row['cancelled_at'] ) ) ),
			'payments'        => $rows,
			'total_cents'     => (int) ( $sched['total_cents'] ?? 0 ),
			'payments_signed' => true,
			'change'          => $change,
			'manage'          => array(
				'no_key'          => $no_key,
				'verified'        => $verified,
				'code_sent'       => self::code_sent( $sess, 'manage' ),
				'may_open'        => $may_open,
				'done'            => ! empty( $sess['id'] ) && ( $verified || $may_open ),
				'copy_url'        => $vm['action'] . '?pdf=signed',
				'change_copy_url' => $last_change ? $vm['action'] . '?pdf=change' : '',
			),
		);
		return ZAGR_Page::status( $vm );
	}
}
