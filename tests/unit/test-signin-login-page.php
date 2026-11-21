<?php
/**
 * The login screen as it is drawn (page-login.php): a code is claimed with the email it is for, the code box takes six
 * digits or eight, and "Email not arriving?" says what to do.
 *
 *   - On load one input shows: the email. The code box, its email box and the help are closed.
 *   - Asking for a code remembers the email for this tab only (15 minutes at most); the claim sends the code AND
 *     the email; with no email nothing is sent. Signing in, or going back to the email form, forgets it.
 *   - Six digits read "123 456", eight read "1234 5678"; a pasted message gives up its code, eight digits before six.
 *   - "Email not arriving?": the backup address, or a one-time code from a person. No password or text message is
 *     offered.
 *   - The "You're logged in" page says to enter the email with its code.
 *
 * Standalone (its own PHP process): the template is drawn over stand-ins for the theme functions it calls, and the
 * page that ends the request is drawn in a further process: php tests/unit/test-signin-login-page.php draw <which>.
 * Run: php tests/unit/test-signin-login-page.php
 *
 * Neutral data only.
 */

error_reporting( E_ALL & ~E_DEPRECATED );
require __DIR__ . '/../bootstrap-unit.php';
require __DIR__ . '/signin-test-doubles.php';

if ( 'draw' === ( $argv[1] ?? '' ) ) {
	ini_set( 'display_errors', '1' );   // a fault while drawing is part of what the parent reads
	if ( ! defined( 'ZDZ_REST_NS' ) ) {
		define( 'ZDZ_REST_NS', 'zorderz/v1' );
	}
	// The theme functions the template calls, where no stand-in is loaded yet. Nobody is signed in.
	if ( ! function_exists( 'get_theme_mod' ) ) {
		function get_theme_mod( $k, $d = '' ) { return $d; }
	}
	if ( ! function_exists( 'wp_get_attachment_image_url' ) ) {
		function wp_get_attachment_image_url( ...$a ) { return ''; }
	}
	if ( ! function_exists( 'esc_url_raw' ) ) {
		function esc_url_raw( $s ) { return (string) $s; }
	}
	if ( ! function_exists( 'esc_url' ) ) {
		function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
	}
	if ( ! function_exists( 'esc_js' ) ) {
		function esc_js( $s ) { return addslashes( (string) $s ); }
	}
	if ( ! function_exists( 'rest_url' ) ) {
		function rest_url( $p = '' ) { return 'https://app.example.com/wp-json/' . ltrim( (string) $p, '/' ); }
	}
	if ( ! function_exists( 'shortcode_exists' ) ) {
		function shortcode_exists( $s ) { return true; }
	}
	if ( ! function_exists( 'language_attributes' ) ) {
		function language_attributes() { echo 'lang="en-US"'; }
	}
	if ( ! function_exists( 'bloginfo' ) ) {
		function bloginfo( $k = '' ) { echo 'charset' === $k ? 'UTF-8' : 'Example Co'; }
	}
	if ( ! function_exists( 'wp_safe_redirect' ) ) {
		function wp_safe_redirect( $u ) { return true; }
	}
	if ( ! function_exists( 'do_shortcode' ) ) {
		// A login plugin's form, as far as the page's script cares: a form with the standard "log" field.
		function do_shortcode( $s ) { return '<form class="magic-login-form" method="post" action="#"><input type="email" name="log"><button type="submit">Send</button></form>'; }
	}
	if ( ! function_exists( 'get_header' ) ) {
		function get_header() { echo '<!DOCTYPE html><html lang="en-US"><head><meta charset="UTF-8"><title>Log in</title></head><body>'; }
	}
	if ( ! function_exists( 'get_footer' ) ) {
		function get_footer() { echo '</body></html>'; }
	}
	if ( ! function_exists( 'wp_get_theme' ) ) {
		function wp_get_theme() {
			return new class() {
				public function get( $k ) { return '0.0.0'; }
			};
		}
	}

	$_GET = 'bridge' === ( $argv[2] ?? '' ) ? array( 'magic-login-bridge' => '1', 'bridge_code' => '123456' ) : array();
	require dirname( __DIR__, 2 ) . '/zorderz/page-login.php';
	exit( 0 );
}

$pass = 0;
$fail = 0;
function ok( bool $c, string $m ): void {
	global $pass, $fail;
	if ( $c ) {
		$pass++;
		echo "  ok   $m\n";
	} else {
		$fail++;
		echo "  FAIL $m\n";
	}
}
/** One drawing of the template, in its own process. */
function draw( string $which ): string {
	$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' draw ' . escapeshellarg( $which );
	return (string) shell_exec( $cmd . ' 2>&1' ); // sentinel:allow every argument is escapeshellarg()'d; runs this test script only
}
/** The opening tag of the element with this id ('' when there is none). */
function tag( string $html, string $id ): string {
	return preg_match( '~<[a-z]+\b[^>]*\bid="' . preg_quote( $id, '~' ) . '"[^>]*>~s', $html, $m ) ? $m[0] : '';
}
function hidden( string $html, string $id ): bool {
	return (bool) preg_match( '~display:\s*none~', tag( $html, $id ) );
}
/** The text inside the element with this id, up to its closing tag of the same kind (flat elements only). */
function inside( string $html, string $id ): string {
	return preg_match( '~<([a-z]+)\b[^>]*\bid="' . preg_quote( $id, '~' ) . '"[^>]*>(.*?)</\1>~s', $html, $m ) ? $m[2] : '';
}

$page = draw( 'login' );
$js   = preg_match_all( '~<script>(.*?)</script>~s', $page, $s ) ? implode( "\n", $s[1] ) : '';
ok( 0 === strpos( ltrim( $page ), '<!DOCTYPE html>' ) && false !== strpos( $page, '</html>' ) && ! preg_match( '/Fatal error|Warning:|Notice:|Deprecated:/', $page ), 'the login screen is drawn whole, with no fault' . ( 0 === strpos( ltrim( $page ), '<!DOCTYPE html>' ) ? '' : ': ' . substr( trim( strip_tags( $page ) ), 0, 200 ) ) );

echo "\nOn load: one input, and the way in when email is down is one press away\n";
ok( false !== strpos( $page, 'id="zdz-otp-email"' ) && ! hidden( $page, 'zdz-otp-email' ) && ! hidden( $page, 'zdz-otp-section' ), 'the email box is showing' );
ok( hidden( $page, 'zdz-have-code-block' ), 'the code box is closed' );
ok( hidden( $page, 'zdz-code-email' ) && hidden( $page, 'zdz-code-for' ), '... with its email box and its "Code for" line' );
ok( hidden( $page, 'zdz-login-form-wrap' ), "the login plugin's own form is put away" );
ok( '' !== tag( $page, 'zdz-no-mail-link' ) && ! hidden( $page, 'zdz-no-mail-link' ) && false !== strpos( inside( $page, 'zdz-no-mail-link' ), 'Email not arriving?' ), '"Email not arriving?" is on the screen' );
ok( hidden( $page, 'zdz-no-mail-help' ), 'its help is closed' );
ok( 1 === substr_count( $page, 'id="zdz-code-input"' ) && 1 === substr_count( $page, 'id="zdz-code-email"' ) && 1 === substr_count( $page, 'id="zdz-no-mail-help"' ), 'each of them is there once' );

echo "\nThe code box\n";
$box = tag( $page, 'zdz-code-input' );
ok( false !== strpos( $box, 'maxlength="9"' ) && false !== strpos( $box, 'pattern="[0-9 ]*"' ), 'it takes eight digits and the space between their two groups' );
ok( false !== strpos( $box, 'placeholder="Code"' ) && false === strpos( $page, '000 000' ), 'it does not suggest a six-digit shape' );
ok( false !== strpos( $box, 'autocomplete="one-time-code"' ) && false !== strpos( $box, 'inputmode="numeric"' ), 'a phone still offers the code from a message' );
ok( false !== strpos( inside( $page, 'zdz-have-code-label' ), 'Enter your code' ) && false === strpos( $page, 'Enter your 6-digit code' ), 'its label does not say six' );
ok( false !== strpos( tag( $page, 'zdz-code-email' ), 'type="email"' ) && false !== strpos( tag( $page, 'zdz-code-email' ), 'autocomplete="email"' ), 'the email beside it is an email box' );

echo "\nA claim is the code and the email\n";
ok( false !== strpos( $js, 'body: JSON.stringify({ code: raw, email: claimEmail })' ), 'the claim sends both' );
ok( false === strpos( $js, 'body: JSON.stringify({ code: raw })' ), '... never the code alone' );
$claim = (string) substr( $js, (int) strpos( $js, "submitBtn.addEventListener('click'" ) );
$guard = strpos( $claim, 'if (!zdzLooksLikeEmail(claimEmail)) {' );
$fetch = strpos( $claim, "fetch(apiBase + 'magic-link-code-claim'" );
ok( false !== $guard && false !== $fetch && $guard < $fetch && false !== strpos( substr( $claim, $guard, $fetch - $guard ), 'return;' ), 'with no email nothing is sent' );
ok( false !== strpos( substr( $claim, (int) $guard, 400 ), 'errorEl.textContent = zdzLoginText.needEmail;' ) && false !== strpos( $js, '"needEmail":"Enter your email with the code."' ), '... and the screen says to enter it' );
ok( false !== strpos( $claim, 'if (raw.length !== 6 && raw.length !== 8) return;' ), 'six digits or eight, nothing else' );
ok( false !== strpos( $js, "fetch(apiBase + 'magic-link-send-code'" ) && false !== strpos( $js, 'body: JSON.stringify({ email: email })' ), 'a code is asked for with the email typed' );
ok( false !== strpos( $js, '"https:\/\/app.example.com\/wp-json\/zorderz\/v1\/"' ), "both go to the platform's own REST address" );
ok( false !== strpos( $js, "var msg = (data && data.message) ? data.message : 'Invalid or expired code. Try again.';" ), "a refusal shows the site's own sentence" );

echo "\nThe email is remembered for this tab, and not for long\n";
ok( 2 === substr_count( $js, "window.sessionStorage.setItem('zdz_login_email'" ) + substr_count( $js, "window.sessionStorage.getItem('zdz_login_email'" ) && false === strpos( $js, 'localStorage.setItem' ), 'in the tab\'s own storage, which a shared device does not keep' );
ok( false !== strpos( $js, 'Date.now() - kept.t < 15 * 60 * 1000' ) && false !== strpos( $js, "typeof kept.e === 'string'" ), 'for 15 minutes at most; a value in any other shape is ignored' );
ok( false !== strpos( $js, 'zdzRememberEmail(email);' ) && false !== strpos( $js, 'zdzRememberEmail(claimEmail);' ), 'kept when a code is asked for and when one is claimed' );
$done = (string) substr( $claim, (int) strpos( $claim, 'if (data && data.success) {' ), 200 );
ok( false !== strpos( $done, 'zdzForgetEmail();' ), 'once signed in, it is not left for the next person at this device' );
$back = (string) substr( $js, (int) strpos( $js, "backBtn.addEventListener('click'" ), 1400 );
ok( false !== strpos( $back, 'zdzForgetEmail();' ) && false !== strpos( $back, "emailBox.removeAttribute('data-open')" ), '"Use email login instead" forgets it too' );
ok( false !== strpos( $js, "codeForChange.addEventListener('click'" ) && false !== strpos( $js, 'zdzRefreshCodeEmail(true);' ), '"change" opens the email box' );

echo "\nSix digits or eight, typed or pasted\n";
ok( false !== strpos( $js, "text.replace(/\\D/g, '').substring(0, 8)" ) && false !== strpos( $js, 'var cut = raw.length > 6 ? 4 : 3;' ), 'typed: digits only, at most eight, in two groups (3+3 or 4+4)' );
ok( false !== strpos( $js, 'submitBtn.disabled = !(raw.length === 6 || raw.length === 8);' ), 'Log In is on at six digits and at eight, off at five, seven and nine' );
// The two patterns a pasted text is read with, taken from the page and applied here as the browser applies them.
$found = preg_match( '~text\.match\(/(.+?)/\) \|\| text\.match\(/(.+?)/\)~', $js, $re );
ok( 1 === $found, 'a pasted text is read with two patterns: eight digits first, then six' );
$read = function ( string $text ) use ( $re ) {
	foreach ( array( $re[1] ?? '(?!)', $re[2] ?? '(?!)' ) as $pattern ) {
		if ( preg_match( '~' . $pattern . '~', $text, $m ) ) {
			return $m[1] . $m[2];
		}
	}
	return substr( (string) preg_replace( '/\D/', '', $text ), 0, 8 );
};
ok( '123456' === $read( 'Your login code is 123 456. It expires in 5 minutes' ), 'pasted with words and another digit after it: the code is taken, not the stray digit' );
ok( '12345678' === $read( 'code: 1234 5678 (10 min)' ), 'pasted eight digits with words around them' );
ok( '12345678' === $read( 'Your sign-in code: 1234 5678. Ref 123 456' ), 'eight digits are looked for before six' );
ok( '12345678' === $read( '12345678' ) && '123456' === $read( '123456' ), 'pasted bare digits (as a phone fills a one-time code)' );
ok( '12345678' === $read( '1234-5678' ) && '123456' === $read( 'Example Co — Your login code: 123 456' ), 'with a dash between the groups, or after a subject line' );

echo "\n\"Email not arriving?\"\n";
$help = inside( $page, 'zdz-no-mail-help' );
ok( false !== strpos( $help, 'backup address' ) && false !== strpos( $help, 'one-time sign-in code' ), 'it says: the backup address, or a one-time code' );
ok( false !== strpos( $help, '8 digits' ), '... and that a given code has 8 digits' );
ok( ! preg_match( '/password|passkey|text message|SMS|authenticator/i', $help ), 'no password, passkey, text message or authenticator is offered' );
ok( $help === strip_tags( $help, '<strong><ol><li>' ), 'its words carry no markup but emphasis and a list' );
$open = (string) substr( $js, (int) strpos( $js, "noMailLink.addEventListener('click'" ), 1100 );
ok( false !== strpos( $open, "noMailHelp.style.display = 'block';" ) && false !== strpos( $open, "haveBlock.style.display = 'block';" ), 'pressing it opens the help and the code box' );
ok( false !== strpos( $open, "if (otpSection) otpSection.style.display = '';" ) && false !== strpos( $open, 'otpSend.disabled = false;' ) && false !== strpos( $open, 'otpSend.textContent = zdzLoginText.sendCode;' ), '... and brings the request box back, so a backup address can be typed' );
ok( false !== strpos( $js, '"sendCode":"Send me a code"' ) && false !== strpos( $js, '"checkEmail":"Check your email, then enter the code"' ), 'the words the script shows come from the page' );

echo "\nThe \"You're logged in\" page\n";
$bridge = draw( 'bridge' );
ok( 0 === strpos( ltrim( $bridge ), '<!DOCTYPE html>' ) && ! preg_match( '/Fatal error|Warning:|Notice:|Deprecated:/', $bridge ), 'it is drawn whole, with no fault' );
ok( false !== strpos( $bridge, '123 456' ) && false !== strpos( $bridge, "You're logged in!" ), 'it shows its six-digit code' );
ok( false !== strpos( $bridge, 'Tap <strong>"Already have a code?"</strong>, then enter your email and the code above' ), 'and says to enter the email with it, under the name the button has' );
ok( false === strpos( $bridge, 'Have a login code?' ), '... not under a name no button has' );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
