<?php
/**
 * ZKV_Viewer — a Vault document opens as a readable page, inside the app.
 *
 *   /vault/{slug}            the viewer page below (after the same gates as the file)
 *   /vault/{slug}?raw=1      the file itself (what the viewer and Download read)
 *   /vault/{slug}?raw=1&dl=1 the file as a download
 *
 * assets/js/vault-viewer.js opens vault links in the theme's in-app document layer
 * (Bridge.openDoc) when the theme has one, so the page runs with the app's own session;
 * a Home Screen web app's links otherwise open in the phone's browser, which is signed
 * out. A signed-out visit remembers the document (ZDZ_Login_Next) so signing in comes
 * back to it.
 *
 * PDFs are drawn page by page with the bundled pdf.js (a phone or tablet shows only the
 * first page of a PDF placed in a frame). Images and video show directly; anything else
 * shows the text the Vault extracted when it indexed the file, with a Download button.
 * The page sends a Content-Security-Policy with a fresh nonce on every inline script
 * (csp()); pdf.js is loaded from this site, never a CDN.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class ZKV_Viewer {

	/** Characters of extracted text shown for a file type with no visual preview. */
	const TEXT_CAP = 200000;

	/**
	 * Find a document's file on THIS server, or ''.
	 *
	 * 1. what the indexer finds (a stored path, a stored uploads URL, the attachment);
	 * 2. a stored absolute path from another server location (a host move renames the
	 *    folder above wp-content): keep the part after "/wp-content/uploads/" and look
	 *    for it in this server's uploads folder.
	 * The /vault/{slug} handler still checks the result is inside the vault or uploads
	 * folder (realpath), so a stored value can never reach outside them.
	 *
	 * @param array $doc file_url, attachment_id
	 * @return string
	 */
	public static function locate( array $doc ) {
		$stored = (string) ( $doc['file_url'] ?? '' );
		if ( '' !== $stored && file_exists( $stored ) ) {
			return $stored;
		}
		// The file was given a random name since the row was read (the rename is recorded first).
		if ( '' !== $stored && class_exists( 'ZKV_File_Names' ) ) {
			$renamed = ZKV_File_Names::renamed( $stored );
			if ( '' !== $renamed ) {
				return $renamed;
			}
		}
		if ( class_exists( 'ZKV_Indexer' ) && method_exists( 'ZKV_Indexer', 'resolve_file_path_public' ) ) {
			$p = (string) ZKV_Indexer::resolve_file_path_public( $doc );
			if ( '' !== $p && file_exists( $p ) ) {
				return $p;
			}
		}
		if ( '' !== $stored && preg_match( '#/wp-content/uploads/(.+)$#', str_replace( '\\', '/', $stored ), $m )
			&& false === strpos( $m[1], '..' ) ) {
			$up = wp_upload_dir();
			$p  = rtrim( (string) $up['basedir'], '/' ) . '/' . $m[1];
			if ( file_exists( $p ) ) {
				return $p;
			}
		}
		return '';
	}

	/** Does this request want the file itself rather than the viewer page? */
	public static function wants_file() {
		return isset( $_GET['raw'] ) || isset( $_GET['download'] );
	}

	/** Should the file be sent as a download? */
	public static function wants_download() {
		return isset( $_GET['dl'] ) || isset( $_GET['download'] );
	}

	/**
	 * A signed-out visitor: remember this vault path, then go to the login page.
	 * ZDZ_Login_Next brings the visitor back after signing in; without it the
	 * argument is simply ignored.
	 *
	 * @param string $path e.g. "/vault/example-warranty"
	 */
	public static function remember_and_login( $path ) {
		$path = (string) $path;
		if ( ! preg_match( '#^/vault/(?:[a-z0-9-]+|excerpt/[0-9]+)/?$#', $path ) ) {
			$path = '';
		}
		if ( '' !== $path && class_exists( 'ZDZ_Login_Next' ) ) {
			$path = ZDZ_Login_Next::remember( $path );
		}
		$login = function_exists( 'zkv_login_url' ) ? zkv_login_url() : wp_login_url();
		if ( '' !== $path ) {
			$login = add_query_arg( class_exists( 'ZDZ_Login_Next' ) ? ZDZ_Login_Next::KEY : 'zdz_next', rawurlencode( $path ), $login );
		}
		nocache_headers();
		wp_redirect( $login );
		exit;
	}

	/** The path of the current request (no query), e.g. "/vault/x". */
	public static function request_path() {
		$p = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- unslashed and cut to its path by wp_parse_url(); used for route matching only.
		return is_string( $p ) ? untrailingslashit( $p ) : '';
	}

	/**
	 * What kind of preview a file gets.
	 *
	 * @param string $mime
	 * @return string pdf|image|video|audio|text
	 */
	public static function kind( $mime ) {
		$mime = strtolower( (string) $mime );
		if ( 'application/pdf' === $mime ) { return 'pdf'; }
		if ( 0 === strpos( $mime, 'image/' ) && 'image/heic' !== $mime && 'image/heif' !== $mime && 'image/tiff' !== $mime ) { return 'image'; }
		if ( 0 === strpos( $mime, 'video/' ) ) { return 'video'; }
		if ( 0 === strpos( $mime, 'audio/' ) ) { return 'audio'; }
		return 'text';
	}

	/**
	 * Extracted text for a file with no visual preview: the file itself for a small
	 * plain-text file, else the indexer's chunks in order.
	 *
	 * @param array  $doc  id, mime_type
	 * @param string $real resolved file path (already checked to be inside the vault)
	 * @return string
	 */
	public static function text_for( array $doc, $real ) {
		$mime = strtolower( (string) ( $doc['mime_type'] ?? '' ) );
		// A Private document keeps no extracted text; a plain-text one is read from its encrypted file.
		if ( ! empty( $doc['is_private'] ) ) {
			if ( ! in_array( $mime, array( 'text/plain', 'text/csv', 'text/markdown' ), true ) || ! class_exists( 'ZKV_Private' ) || ! $real ) { return ''; }
			$t = ZKV_Private::decrypt_to_string( $real, self::TEXT_CAP );
			return null === $t ? '' : $t;
		}
		if ( in_array( $mime, array( 'text/plain', 'text/csv', 'text/markdown' ), true )
			&& $real && is_readable( $real ) && filesize( $real ) <= self::TEXT_CAP ) {
			$t = (string) file_get_contents( $real );
			if ( '' !== trim( $t ) ) { return $t; }
		}
		global $wpdb;
		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT chunk_text FROM {$wpdb->prefix}zkv_chunks WHERE document_id = %d ORDER BY chunk_index ASC",
			(int) $doc['id']
		) );
		$out = '';
		foreach ( (array) $rows as $chunk ) {
			$out .= trim( (string) $chunk ) . "\n\n";
			if ( strlen( $out ) >= self::TEXT_CAP ) {
				$out = substr( $out, 0, self::TEXT_CAP ) . "\n…";
				break;
			}
		}
		return trim( $out );
	}

	/**
	 * One step of the business's brand ramp (Business Profile `brand.ramp.<step>`) for this
	 * page, which stands alone and does not load the theme's stylesheet. A value that is not
	 * a plain hex colour is never printed; the neutral fallback is used instead.
	 */
	private static function brand( $step, $fallback ) {
		$hex = class_exists( 'ZDZ_Business_Profile' ) ? trim( (string) ZDZ_Business_Profile::get( 'brand.ramp.' . $step, '' ) ) : '';
		return preg_match( '/^#([A-Fa-f0-9]{3}){1,2}$/', $hex ) ? $hex : $fallback;
	}

	/** Headers for a private page/file that may only be framed by this site. */
	public static function private_headers() {
		header( 'Cache-Control: private, no-store, max-age=0' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: same-origin' );
	}

	/**
	 * The viewer page's Content-Security-Policy. Everything not named is refused
	 * (default-src 'none'):
	 *   script-src  the page's own inline scripts (this request's nonce) and this site
	 *               (the bundled pdf.js module and its worker module);
	 *   worker-src  this site, and blob: should pdf.js wrap its worker;
	 *   connect-src the PDF itself (?raw=1, same origin, with the session cookie);
	 *   img-src / media-src  the file itself (?raw=1) for images, video and audio; data:
	 *               and blob: for what pdf.js draws;
	 *   style-src   the page's inline <style> (and pdf.js's own style changes);
	 *   font-src    data: fonts pdf.js may build from a PDF's embedded fonts;
	 *   frame-src   'self': the fallback that shows the file in a frame;
	 *   frame-ancestors 'self': only this site (the app's document layer) may frame it.
	 * There is no 'unsafe-eval': pdf.js runs with isEvalSupported:false.
	 *
	 * @param string $nonce base64, fresh for each request
	 * @return string
	 */
	public static function csp( $nonce ) {
		return "default-src 'none'; script-src 'nonce-" . $nonce . "' 'self'; worker-src 'self' blob:"
			. "; connect-src 'self'; img-src 'self' data: blob:; media-src 'self' blob:"
			. "; style-src 'self' 'unsafe-inline'; font-src 'self' data:; frame-src 'self'; frame-ancestors 'self'"
			. "; base-uri 'none'; form-action 'self'; object-src 'none'";
	}

	/** The bundled pdf.js (library and worker), shipped with the apps bundle; never a CDN. */
	public static function pdfjs_urls() {
		$v    = rawurlencode( defined( 'ZKV_VERSION' ) ? ZKV_VERSION : '' );
		$base = plugins_url( 'estimate/assets/js/vendor/pdfjs/', dirname( __DIR__ ) ); // apps/estimate owns the copy
		return array(
			'lib'    => $base . 'pdf.min.js?v=' . $v,
			'worker' => $base . 'pdf.worker.min.js?v=' . $v,
		);
	}

	/**
	 * Print the viewer page and exit. Called only after every gate in the
	 * /vault/{slug} handler has passed for this user.
	 *
	 * @param array  $doc  id, slug, title, mime_type, original_name
	 * @param string $real resolved file path
	 */
	public static function render( array $doc, $real ) {
		$slug  = (string) ( $doc['slug'] ?? '' );
		$title = trim( (string) ( $doc['title'] ?? '' ) );
		if ( '' === $title ) {
			$title = (string) ( $doc['original_name'] ?? 'Document' );
		}
		$base     = home_url( '/vault/' . $slug );
		$raw_url  = add_query_arg( 'raw', '1', $base );
		$dl_url   = add_query_arg( array( 'raw' => '1', 'dl' => '1' ), $base );
		$missing  = ( '' === (string) $real );
		$kind     = $missing ? 'text' : self::kind( $doc['mime_type'] ?? '' );
		$text     = ( 'text' === $kind ) ? self::text_for( $doc, $real ) : '';
		$home     = home_url( '/' );

		$nonce    = base64_encode( random_bytes( 16 ) ); // one per request

		status_header( 200 );
		self::private_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		if ( ! headers_sent() ) {
			header( 'Content-Security-Policy: ' . self::csp( $nonce ) );
		}

		$cfg = array(
			'kind'   => $kind,
			'raw'    => $raw_url,
			'home'   => $home,
		);
		if ( 'pdf' === $kind ) {
			// Pdf.js is ES modules only: the library is import()ed and the worker is a module too.
			$cfg = array_merge( $cfg, self::pdfjs_urls() );
		}
		$n = ' nonce="' . esc_attr( $nonce ) . '"';
		?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $title ); ?></title>
<script<?php echo $n; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- nonce attribute built with esc_attr() above. ?>>(function(){try{var t=localStorage.getItem("zdz_theme");if(t){document.documentElement.setAttribute("data-theme",t);}}catch(e){}try{if(window.self!==window.top){document.documentElement.className+=" is-framed";}}catch(e){document.documentElement.className+=" is-framed";}})();</script>
<style>
	:root{--bg:#eef0f3;--surface:#fff;--text:#111827;--muted:#4b5563;--border:#d1d5db;--brand:<?php echo esc_html( self::brand( '600', '#1e3a5f' ) ); ?>;--page-shadow:0 1px 4px rgba(0,0,0,.18)}
	[data-theme="dark"]{--bg:#0b1220;--surface:#111827;--text:#e5e7eb;--muted:#9ca3af;--border:#374151;--brand:<?php echo esc_html( self::brand( '300', '#7ab3ff' ) ); ?>;--page-shadow:0 1px 6px rgba(0,0,0,.6)}
	@media (prefers-color-scheme: dark){
		:root:not([data-theme="light"]):not([data-theme="sunlight"]){--bg:#0b1220;--surface:#111827;--text:#e5e7eb;--muted:#9ca3af;--border:#374151;--brand:<?php echo esc_html( self::brand( '300', '#7ab3ff' ) ); ?>;--page-shadow:0 1px 6px rgba(0,0,0,.6)}
	}
	[data-theme="sunlight"]{--bg:#fff;--surface:#fff;--text:#000;--muted:#000;--border:#000;--brand:#0033cc;--page-shadow:0 0 0 2px #000}
	*{box-sizing:border-box}
	html,body{margin:0;background:var(--bg);color:var(--text);font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
	.bar{position:sticky;top:0;z-index:5;display:flex;align-items:center;gap:8px;min-height:56px;
		padding:env(safe-area-inset-top,0) 12px 0;background:var(--surface);border-bottom:1px solid var(--border)}
	.is-framed .bar{display:none}
	.bar a,.bar button{font:600 15px/1 inherit;color:var(--brand);background:none;border:0;padding:12px 10px;border-radius:8px;cursor:pointer;text-decoration:none;white-space:nowrap}
	.bar h1{flex:1;min-width:0;margin:0;font-size:16px;font-weight:650;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
	main{padding:12px 12px calc(24px + env(safe-area-inset-bottom,0));max-width:1000px;margin:0 auto}
	.status{color:var(--muted);font-size:14px;text-align:center;padding:24px 8px}
	.page{position:relative;margin:0 auto 12px;background:#fff;box-shadow:var(--page-shadow);width:100%}
	.page canvas{display:block;width:100%;height:auto}
	.media{display:block;max-width:100%;margin:0 auto;background:var(--surface)}
	.text{background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:16px 18px;
		white-space:pre-wrap;word-wrap:break-word;line-height:1.55;font-size:15px}
	.note{color:var(--muted);font-size:13px;margin:0 0 10px}
	.fallback{width:100%;height:80vh;border:0;background:#fff}
	.dl{display:inline-block;margin-top:12px;color:var(--brand);font-weight:600}
</style>
</head>
<body>
<header class="bar">
	<button type="button" id="zkv-back" aria-label="Back">&#8249; Back</button>
	<h1><?php echo esc_html( $title ); ?></h1>
	<?php if ( ! $missing ) : ?><a href="<?php echo esc_url( $dl_url ); ?>" download>Download</a><?php endif; ?>
</header>
<main id="zkv-doc">
<?php if ( 'image' === $kind ) : ?>
	<img class="media" src="<?php echo esc_url( $raw_url ); ?>" alt="<?php echo esc_attr( $title ); ?>">
<?php elseif ( 'video' === $kind ) : ?>
	<video class="media" src="<?php echo esc_url( $raw_url ); ?>" controls playsinline></video>
<?php elseif ( 'audio' === $kind ) : ?>
	<audio class="media" src="<?php echo esc_url( $raw_url ); ?>" controls></audio>
<?php elseif ( $missing ) : ?>
	<p class="note"><strong>The original file is missing on the server.</strong> Ask whoever looks after the Vault to upload it again.<?php echo '' !== $text ? ' Below is the text the Vault read from it when it was added.' : ''; ?></p>
	<?php if ( '' !== $text ) : ?><div class="text"><?php echo esc_html( $text ); ?></div><?php endif; ?>
<?php elseif ( 'text' === $kind ) : ?>
	<?php if ( '' !== $text ) : ?>
		<p class="note">Text of this file as the Vault read it. Use Download for the original.</p>
		<div class="text"><?php echo esc_html( $text ); ?></div>
	<?php else : ?>
		<div class="status">There is no preview for this kind of file.<br><a class="dl" href="<?php echo esc_url( $dl_url ); ?>" download>Download the file</a></div>
	<?php endif; ?>
<?php else : ?>
	<div class="status" id="zkv-status">Loading…</div>
	<div id="zkv-pages"></div>
<?php endif; ?>
</main>
<script<?php echo $n; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- nonce attribute built with esc_attr() above. ?>>
(function () {
	'use strict';
	var CFG = <?php echo wp_json_encode( $cfg, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP ); ?>;

	var back = document.getElementById('zkv-back');
	if (back) {
		back.addEventListener('click', function () {
			var ref = document.referrer || '';
			var sameSite = ref.indexOf(location.origin + '/') === 0;
			if (sameSite && ref.indexOf('/login') === -1 && history.length > 1) { history.back(); }
			else { location.href = CFG.home; }
		});
	}
	if (CFG.kind !== 'pdf') { return; }

	var statusEl = document.getElementById('zkv-status');
	var pagesEl  = document.getElementById('zkv-pages');

	function fallback(msg) {
		statusEl.textContent = msg || 'Showing the file directly.';
		var f = document.createElement('iframe');
		f.className = 'fallback';
		f.src = CFG.raw;
		f.title = document.title;
		pagesEl.innerHTML = '';
		pagesEl.appendChild(f);
	}

	var noViewer = 'The page viewer could not load, so the file is shown directly (Download has the whole file).';
	var load;
	try { load = import(CFG.lib); } catch (e) { load = Promise.reject(e); }
	var drawFail = 'This PDF could not be drawn page by page, so it is shown directly.';
	load.then(function (pdfjsLib) {
		if (!pdfjsLib || !pdfjsLib.getDocument) { fallback(noViewer); return; }
		// Anything pdf.js throws here (an engine without a feature it needs) shows the file,
		// never a page stuck on "Loading…".
		try {
			pdfjsLib.GlobalWorkerOptions.workerSrc = CFG.worker;
			show(pdfjsLib);
		} catch (e) { fallback(drawFail); }
	}, function () { fallback(noViewer); });

	function show(pdfjsLib) {
	// IsEvalSupported:false — defence in depth against script from a crafted font
	// (CVE-2024-4367, fixed upstream); the page's CSP has no 'unsafe-eval' either.
	pdfjsLib.getDocument({ url: CFG.raw, withCredentials: true, isEvalSupported: false }).promise.then(function (pdf) {
		var n = pdf.numPages;
		statusEl.textContent = n === 1 ? '1 page' : n + ' pages';
		return pdf.getPage(1).then(function (p1) {
			var v1 = p1.getViewport({ scale: 1 });
			var slots = [];
			for (var i = 1; i <= n; i++) {
				var d = document.createElement('div');
				d.className = 'page';
				d.setAttribute('data-n', String(i));
				d.style.aspectRatio = v1.width + ' / ' + v1.height;
				pagesEl.appendChild(d);
				slots.push(d);
			}
			var dpr = Math.min(window.devicePixelRatio || 1, 2);
			function draw(slot) {
				if (slot.getAttribute('data-state')) { return; }
				slot.setAttribute('data-state', 'drawing');
				pdf.getPage(+slot.getAttribute('data-n')).then(function (page) {
					var base = page.getViewport({ scale: 1 });
					slot.style.aspectRatio = base.width + ' / ' + base.height;
					var cssW = slot.clientWidth || 800;
					var scale = (cssW / base.width) * dpr;
					var max = 12e6; // pixels per page canvas: keeps an iPad inside its memory
					if (base.width * base.height * scale * scale > max) {
						scale = Math.sqrt(max / (base.width * base.height));
					}
					var vp = page.getViewport({ scale: scale });
					var c = document.createElement('canvas');
					c.width = Math.floor(vp.width);
					c.height = Math.floor(vp.height);
					return page.render({ canvasContext: c.getContext('2d'), viewport: vp }).promise.then(function () {
						if (slot.getAttribute('data-state') !== 'drawing') { c.width = 0; c.height = 0; return; }
						slot.innerHTML = '';
						slot.appendChild(c);
						slot.setAttribute('data-state', 'drawn');
					});
				}).catch(function () { slot.removeAttribute('data-state'); });
			}
			function release(slot) {
				var c = slot.querySelector('canvas');
				if (c) { c.width = 0; c.height = 0; }
				slot.innerHTML = '';
				slot.removeAttribute('data-state');
			}
			if ('IntersectionObserver' in window) {
				// Pages near the screen are drawn; pages far away give their memory back.
				var io = new IntersectionObserver(function (entries) {
					entries.forEach(function (e) { if (e.isIntersecting) { draw(e.target); } else { release(e.target); } });
				}, { rootMargin: '1500px 0px' });
				slots.forEach(function (s) { io.observe(s); });
			} else {
				slots.slice(0, 30).forEach(draw);
			}
		});
	}).catch(function () {
		fallback(drawFail);
	});
	}
})();
</script>
</body>
</html>
<?php
		exit;
	}

	/**
	 * Front end, signed-in visitors: the script that opens vault links inside the
	 * app. In the HEAD (not the footer) because the mobile messenger page prints
	 * wp_head() but is not guaranteed to print wp_footer().
	 */
	public static function enqueue() {
		if ( ! is_user_logged_in() ) {
			return;
		}
		wp_enqueue_script(
			'zkv-vault-links',
			ZKV_PLUGIN_URL . 'assets/js/vault-viewer.js',
			array(),
			ZKV_VERSION,
			false
		);
	}
}

add_action( 'wp_enqueue_scripts', array( 'ZKV_Viewer', 'enqueue' ) );
