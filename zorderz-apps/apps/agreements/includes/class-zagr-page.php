<?php
/**
 * ZAGR_Page - the customer's page, drawn from a view model (ZAGR_Public builds it; the suites build their own). Phone
 * first, light and dark, high contrast for sunlight. No inline script: the page runs only assets/js/agreement.js and
 * reads only assets/css/agreement.css and one brand-colour block the CSP allows by its hash. Every form posts back to
 * the page itself and works without script; the script only enables buttons as boxes are ticked, shows the typed
 * signature, and counts down. [CORE]
 *
 * The look: the business's logo from the Business Profile (its light-ink artwork in dark mode, picked by <picture>), a
 * five-colour stripe and numbered steps in the business's brand colours, and cards. A template with the card layout
 * opens with its welcome page, and the buyer's question (where the legal packs ask it) sits in its "You" card as two
 * one-tap buttons (the template's age-slot); a classic template keeps its own order with the question in a card of its
 * own. Until the question is answered, a section that depends on it shows a short note in its place. Signing is three
 * steps: open the PDF; agree, one box and one press that emails the code; then Sign: the code, one typed name, and a
 * tick beside each signature (the template's sig-slot). After signing, the status page keeps the five steps, "You
 * sign" marked done only once the signed copy reached the customer.
 *
 * An agreement signed on paper ($vm['paper']) says so plainly: "Signed on paper on <date>", the paper copy is the
 * agreement; the cancel-by day and its countdown; Cancel still works during the window, and any written notice counts;
 * no online signing, no online signed copy. The same look.
 *
 * @package Zorderz\Agreements
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZAGR_Page {

	/** where a step's one-time message shows: by the age question, in the Sign card, or at the top. */
	const MSG_AREA = array(
		'm_age_needed' => 'age', 'm_age_first' => 'age',
		'm_pdf_first' => 'sign', 'm_consent_needed' => 'sign', 'm_consent_first' => 'sign', 'm_code_sent' => 'sign', 'm_code_ok' => 'sign',
		'm_code_wait' => 'sign', 'm_code_limit' => 'sign', 'm_code_paused' => 'sign', 'm_code_locked' => 'sign', 'm_code_mail' => 'sign',
		'm_code_wrong' => 'sign', 'm_code_expired' => 'sign', 'm_code_tries' => 'sign', 'm_code_first' => 'sign', 'm_code_type' => 'sign',
		'm_names_needed' => 'sign', 'm_name_needed' => 'sign', 'm_changed' => 'sign',
	);

	/** The sections drawn in the Sign step, beside their signatures (never in the reading cards). */
	const SIGN_SECTIONS = array( 'sign-agreement', 'sign-notice' );

	private static function e( $s ): string {
		return htmlspecialchars( (string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}

	private static function t( string $k, array $v = array() ): string {
		return self::e( ZAGR_Strings::t( $k, $v ) );
	}

	public static function asset( string $rel ): string {
		return ZAGR_URL . 'assets/' . $rel . '?ver=' . ZAGR_VERSION;
	}

	/** The frame every page shares. */
	public static function frame( string $body, bool $test = false, string $title = '' ): string {
		return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<meta name="robots" content="noindex, nofollow, noarchive"><meta name="referrer" content="no-referrer">'
			. '<meta name="color-scheme" content="light dark"><meta name="format-detection" content="telephone=yes">'
			. '<title>' . self::e( '' !== $title ? $title : ZAGR_Strings::t( 'title' ) ) . '</title>'
			. '<link rel="stylesheet" href="' . self::e( self::asset( 'css/agreement.css' ) ) . '">'
			. '<style>' . self::brand_css() . '</style>'
			. '<script src="' . self::e( self::asset( 'js/agreement.js' ) ) . '" defer></script>'
			. '</head><body class="zagr">'
			. ( $test ? '<div class="testbar" role="note">' . self::t( 'test_banner' ) . '</div>' : '' )
			. '<main id="main" class="wrap">' . $body . '</main></body></html>';
	}

	/**
	 * The business's logo from the Business Profile: the wide artwork for a light page and, where there is one, the
	 * light-ink artwork for dark mode. Without a logo, the business's name as text.
	 */
	private static function logo(): string {
		if ( ! class_exists( 'ZDZ_Business_Profile' ) ) {
			return '<p class="logo logo-text">' . self::t( 'logo_alt' ) . '</p>';
		}
		$light = ZDZ_Business_Profile::logo( 'wide', 'light' );
		$dark  = ZDZ_Business_Profile::logo( 'wide', 'dark' );
		if ( '' === (string) $light['url'] ) {
			return '<p class="logo logo-text">' . self::t( 'logo_alt' ) . '</p>';
		}
		$w   = 'square' === $light['shape'] ? 62 : 124;
		$src = '' !== (string) $dark['url'] && $dark['url'] !== $light['url']
			? '<source media="(prefers-color-scheme: dark)" srcset="' . self::e( (string) $dark['url'] ) . '">' : '';
		return '<picture class="logo">' . $src . '<img src="' . self::e( (string) $light['url'] ) . '" alt="' . self::t( 'logo_alt' ) . '" width="' . $w . '" height="62"></picture>';
	}

	/** The hosts the logo is served from, for the page's Content-Security-Policy. */
	public static function logo_hosts(): array {
		$out = array();
		if ( class_exists( 'ZDZ_Business_Profile' ) ) {
			foreach ( array( 'light', 'dark' ) as $bg ) {
				$u = (string) ZDZ_Business_Profile::logo( 'wide', $bg )['url'];
				$p = '' !== $u ? wp_parse_url( $u ) : array();
				if ( ! empty( $p['host'] ) && in_array( $p['scheme'] ?? '', array( 'https', 'http' ), true ) ) {
					$out[] = $p['scheme'] . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . (int) $p['port'] : '' );
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * The page's brand colours, from the Business Profile's ramp, as one small style block. The page's
	 * Content-Security-Policy allows exactly this block by its SHA-256 (ZAGR_Public::csp()), nothing else inline.
	 */
	public static function brand_css(): string {
		$ramp = array();
		if ( class_exists( 'ZDZ_Business_Profile' ) ) {
			$ramp = (array) ZDZ_Business_Profile::get( 'brand.ramp', array() );
		}
		$c   = static function ( string $k, string $fallback ) use ( $ramp ) {
			$v = (string) ( $ramp[ $k ] ?? '' );
			return preg_match( '/^#[0-9A-Fa-f]{6}$/', $v ) ? strtolower( $v ) : $fallback;
		};
		$css = ':root{--paper-b1:' . $c( '700', '#162d4a' ) . ';--paper-b2:' . $c( '600', '#1e3a5f' ) . ';--paper-b3:' . $c( '500', '#2c5f8a' )
			. ';--paper-b4:' . $c( '400', '#4796f7' ) . ';--paper-b5:' . $c( '300', '#7ab3ff' )
			. ';--paper-c1:' . $c( '800', '#0f1f35' ) . ';--paper-c2:' . $c( '700', '#162d4a' ) . ';--paper-c3:' . $c( '600', '#1e3a5f' )
			. ';--paper-c4:' . $c( '500', '#2c5f8a' ) . ';--paper-c5:' . $c( '500', '#2c5f8a' )
			. ';--paper-brand:' . $c( '600', '#1e3a5f' ) . ';--paper-soft:' . $c( '50', '#ebf5ff' ) . ';--paper-hello:' . $c( '500', '#2c5f8a' ) . '}'
			. '@media (prefers-color-scheme: dark){:root{--paper-brand:' . $c( '200', '#adcfff' ) . ';--paper-soft:' . $c( '900', '#091526' ) . ';--paper-hello:' . $c( '300', '#7ab3ff' ) . '}}';
		return $css;
	}

	/** A band of five colours under the logo. */
	private static function stripe(): string {
		return '<div class="stripe" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i></div>';
	}

	/** The band at the top of a card: the logo, and on the agreement's own pages its title. */
	private static function brand( bool $titled = true ): string {
		return '<header class="band">' . self::logo()
			. ( $titled ? '<p class="band-r"><strong>' . self::t( 'band_title' ) . '</strong><span>' . self::t( 'band_sub' ) . '</span></p>' : '' )
			. '</header>' . self::stripe();
	}

	/** A plain one-card page (404, too many, not ready, no key, not English). Same bytes for the same words. */
	public static function plain( string $title_key, string $body_key, array $vars = array(), bool $test = false ): string {
		return self::frame( '<section class="card plain">' . self::brand( false ) . '<h1>' . self::t( $title_key, $vars ) . '</h1><p>' . self::t( $body_key, $vars ) . '</p></section>', $test );
	}

	/** A step's one-time message; on the signing page only in its own area. */
	private static function message( string $m, array $vars, string $area = '' ): string {
		if ( '' === $m || ! ZAGR_Strings::has( $m ) || 0 !== strpos( $m, 'm_' ) ) {
			return '';
		}
		if ( '' !== $area && $area !== ( self::MSG_AREA[ $m ] ?? 'top' ) ) {
			return '';
		}
		$good = in_array( $m, array( 'm_code_sent', 'm_code_ok', 'm_signed', 'm_change_signed', 'm_cancelled', 'm_manage_ok', 'm_done', 'm_manage_ok_paper', 'm_done_paper' ), true );
		return '<div class="msg ' . ( $good ? 'ok' : 'warn' ) . '" role="' . ( $good ? 'status' : 'alert' ) . '" id="msg">' . self::t( $m, $vars ) . '</div>';
	}

	/** 1.0's four-step strip (a 1.0 template's head card). */
	private static function steps( string $now ): string {
		$order = array( 'review' => 'step_review', 'sign' => 'step_sign', 'window' => 'step_window', 'order' => 'step_order' );
		$keys  = array_keys( $order );
		$at    = array_search( $now, $keys, true );
		$h     = '<ol class="strip" aria-label="' . self::t( 'steps_label' ) . '">';
		foreach ( $keys as $i => $k ) {
			$cls = $i < $at ? 'done' : ( $i === $at ? 'now' : '' );
			$h  .= '<li class="' . $cls . '"' . ( $i === $at ? ' aria-current="step"' : '' ) . '>' . self::t( $order[ $k ] ) . '</li>';
		}
		return $h . '</ol>';
	}

	/**
	 * "What happens next", the five steps. On the status page "You sign" is marked done once
	 * the signed copy reached the customer, and "Signed" before that (never more than the record says).
	 */
	private static function next_steps( string $first = '' ): string {
		$h = '<div class="steps next"><h2 class="next-h">' . self::t( 'next_heading' ) . '</h2><ol>';
		for ( $i = 1; $i <= 5; $i++ ) {
			$done = 1 === $i && '' !== $first;
			$h   .= '<li' . ( $done ? ' class="done"' : '' ) . '><strong>' . self::t( 'next_' . $i ) . '</strong><br>' . self::t( 'next_' . $i . '_d' )
				. ( $done ? ' <span class="pill">' . self::e( $first ) . '</span>' : '' ) . '</li>';
		}
		return $h . '</ol></div>';
	}

	private static function hidden( array $vm, string $do, array $more = array() ): string {
		$h = '<input type="hidden" name="tok" value="' . self::e( $vm['token'] ) . '"><input type="hidden" name="do" value="' . self::e( $do ) . '">';
		foreach ( $more as $k => $v ) {
			$h .= '<input type="hidden" name="' . self::e( $k ) . '" value="' . self::e( $v ) . '">';
		}
		return $h;
	}

	private static function form( array $vm, string $anchor, string $id = '' ): string {
		return '<form method="post" action="' . self::e( $vm['action'] . $anchor ) . '" class="step-form"' . ( '' !== $id ? ' id="' . self::e( $id ) . '"' : '' ) . '>';
	}

	/* ================================================================ before signing */

	/**
	 * $vm: test, message, vars (phone…), token, action (the page URL), sections (agreement name => html; before the age
	 * question is answered only the sections that don't depend on it), order and age_sections (the template's), layout
	 * (paper | classic), age, age_days, consent_html, doc_sha, sign_date, sign_date_long,
	 * email_masked, pdf_url, pdf_done, consent_done, code_sent, code_ok, kept_name, kind (agreement | change),
	 * change_html (for a change order).
	 */
	public static function sign( array $vm ): string {
		$s     = $vm['sections'];
		$is_c  = 'change' === ( $vm['kind'] ?? 'agreement' );
		$age   = in_array( $vm['age'] ?? '', array( 'yes', 'no', 'na' ), true ) ? (string) $vm['age'] : '';   // never a default answer
		$paper = 'paper' === ( $vm['layout'] ?? 'classic' );
		$b     = '<section class="card head" id="top">' . self::brand();
		$b    .= self::message( (string) $vm['message'], $vm['vars'], $is_c ? '' : 'top' );
		if ( $is_c ) {
			$b .= '<h1>' . self::t( 'change_heading' ) . '</h1><div class="status info" role="status"><p><strong>' . self::t( 'change_ready' ) . '</strong></p><p class="small">' . self::t( 'change_window_note' ) . '</p></div></section>';
			$b .= '<section class="card doc" id="change">' . str_replace( '<p class="sig-slot"></p>', '', (string) $vm['change_html'] ) . '</section>';
			return self::frame( $b . self::sign_card( $vm ), ! empty( $vm['test'] ) );
		}
		$order = (array) ( $vm['order'] ?? ZAGR_Template::AGREEMENT );
		$ages  = (array) ( $vm['age_sections'] ?? ZAGR_Template::AGE_SECTIONS );
		$head  = (string) ( $s['header'] ?? '' );
		if ( $paper ) {
			// the welcome page: the age question in the "You" card (the template's age-slot), when the packs ask it
			$block = 'na' === $age ? '' : self::age_block( $vm );
			$head  = false !== strpos( $head, '<p class="age-slot"></p>' ) ? str_replace( '<p class="age-slot"></p>', $block, $head ) : $head . $block;
			$b    .= '<div class="doc welcome">' . $head . '</div></section>';
		} else {
			// the header first: on screen, as in the PDF, it is the agreement's first page
			$b .= '<div class="doc">' . $head . '</div><div class="status info" role="status"><p><strong>' . self::t( 'review_title' ) . '</strong></p><p class="small">' . self::t( 'review_sub' ) . '</p></div>' . self::steps( 'review' ) . '</section>';
			$b .= 'na' === $age ? '' : '<section class="card age-card" aria-labelledby="age-h">' . self::age_block( $vm ) . '</section>';
		}
		// the agreement's other sections in the template's order, as cards; one opening with an h3 continues the card
		// before it (a heading of its own continues a card); one waiting for the answer shows a note
		$cards = array();
		foreach ( $order as $name ) {
			if ( 'header' === $name || in_array( $name, self::SIGN_SECTIONS, true ) ) {
				continue;
			}
			if ( isset( $s[ $name ] ) ) {
				$html = (string) $s[ $name ];
			} elseif ( '' === $age && in_array( $name, $ages, true ) && ZAGR_Strings::has( 'wait_' . $name ) ) {
				$h    = $paper && 'right-to-cancel' === $name ? 'h3' : 'h2';   // in the card layout it continues the card before it
				$html = '<' . $h . '>' . self::t( 'wait_' . $name . '_h' ) . '</' . $h . '><p class="wait">' . self::t( 'wait_' . $name ) . '</p>';
			} else {
				continue;
			}
			if ( $cards && 0 === strpos( ltrim( $html ), '<h3' ) ) {
				$cards[ count( $cards ) - 1 ][1] .= $html;
			} else {
				$cards[] = array( $name, $html );
			}
		}
		foreach ( $cards as $c ) {
			$b .= '<section class="card doc" id="s-' . self::e( $c[0] ) . '">' . $c[1] . '</section>';
		}
		return self::frame( $b . ( '' === $age ? self::sign_card_waiting( $vm ) : self::sign_card( $vm ) ), ! empty( $vm['test'] ) );
	}

	/**
	 * The buyer's question ("Is any buyer 65 or older?", in the legal pack's words), two one-tap buttons, with why it is asked. Unanswered,
	 * nothing that depends on it is drawn; answered, what the buyer said, and the same two buttons to change it until
	 * they sign (a change redraws the agreement, so the PDF and consent are asked for again).
	 */
	private static function age_block( array $vm ): string {
		$age = in_array( $vm['age'] ?? '', array( 'yes', 'no' ), true ) ? (string) $vm['age'] : '';
		$q   = (array) ( $vm['age_q'] ?? array() );
		$w   = static function ( string $pack_key, string $fallback ) use ( $q ) {
			$v = trim( (string) ( $q[ $pack_key ] ?? '' ) );
			return '' !== $v ? self::e( $v ) : self::t( $fallback );
		};
		$h   = '<div class="age" id="age"><p class="age-q" id="age-h"><strong>' . $w( 'label', 'age_heading' ) . '</strong></p>'
			. '<p class="hint">' . $w( 'why', 'age_why' ) . '</p>'
			. self::message( (string) ( $vm['message'] ?? '' ), (array) ( $vm['vars'] ?? array() ), 'age' )
			. self::form( $vm, '#age' ) . self::hidden( $vm, 'age' ) . '<div class="choices" role="group" aria-labelledby="age-h">';
		foreach ( array( 'no' => 'age_no', 'yes' => 'age_yes' ) as $v => $k ) {
			$on = $age === $v;
			$h .= '<button type="submit" name="age" value="' . $v . '" id="age' . ucfirst( $v ) . '" class="choice' . ( $on ? ' chosen' : '' ) . '" aria-pressed="' . ( $on ? 'true' : 'false' ) . '">' . $w( $v, $k ) . '</button>';
		}
		$h .= '</div></form>';
		if ( '' !== $age ) {
			$h .= '<p class="ok-line">' . self::t( 'age_answered', array( 'answer' => ZAGR_Document::age_answer( $age ), 'days' => (int) ( $vm['age_days'] ?? 0 ) ) ) . '</p>'
				. '<p class="hint">' . self::t( 'age_change_note' ) . '</p>';
		}
		return $h . '</div>';
	}

	/** The sign card before the age question is answered: every step waits for it. */
	private static function sign_card_waiting( array $vm ): string {
		$h = '<section class="card sign" id="sign" aria-labelledby="sign-h"><h2 id="sign-h">' . self::t( 'sign_heading' ) . '</h2>'
			. self::message( (string) $vm['message'], $vm['vars'], 'sign' ) . '<ol class="sign-steps">';
		foreach ( array( 's1_heading', 's2_heading', 's3_heading' ) as $i => $k ) {
			$h .= '<li id="step' . ( $i + 1 ) . '" class="' . ( 0 === $i ? 'now' : 'todo' ) . '"><h3>' . self::t( $k ) . '</h3><p class="small locked">' . self::t( 0 === $i ? 'locked_age' : 'locked_note' ) . '</p></li>';
		}
		return $h . '</ol><p class="small help">' . self::t( 'help_line', $vm['vars'] ) . '</p></section>';
	}

	private static function state( bool $done, bool $now ): string {
		return $done ? 'done' : ( $now ? 'now' : 'todo' );
	}

	/** the "I sign" box beside a signature, and the typed name shown there as it is typed. */
	private static function tick( string $name, string $words, string $date_long, string $dis = '' ): string {
		return '<div class="sigtick"><label class="check"><input type="checkbox" name="' . $name . '" value="1" id="' . $name . '" required' . $dis . '><span>' . self::e( $words ) . '</span></label>'
			. '<p class="sigshow" aria-hidden="true"><span class="signame" data-mirror="sigName"></span><span class="sigdate">' . self::t( 'date_line', array( 'date' => $date_long ) ) . '</span></p></div>';
	}

	/** A signature's section with its box in the template's sig-slot (or after the section, for a 1.0 template). */
	private static function place( string $html, string $tick ): string {
		return false !== strpos( $html, '<p class="sig-slot"></p>' ) ? str_replace( '<p class="sig-slot"></p>', $tick, $html ) : $html . $tick;
	}

	/** The name field, with the name kept after an error put back. */
	private static function name_field( array $vm, string $label_key, string $dis = '' ): string {
		return '<label class="field" for="sigName">' . self::t( $label_key ) . '</label>'
			. '<input type="text" id="sigName" name="sig" autocomplete="name" maxlength="120" required placeholder="' . self::t( 'full_name' ) . '" value="' . self::e( (string) ( $vm['kept_name'] ?? '' ) ) . '"' . $dis . '>';
	}

	private static function pdf_step( array $vm, bool $done, string $button_key, string $next ): string {
		return '<p><a class="btn ghost" id="pdfBtn" href="' . self::e( $vm['pdf_url'] ) . '" target="_blank" rel="noopener">' . self::t( $button_key ) . '</a></p>'
			. ( $done ? '<p class="ok-line">' . self::t( 'pdf_done' ) . '</p>' : '<p class="small">' . self::t( 'pdf_help' ) . '</p><p class="ok-line" id="pdfDoneJs" hidden>' . self::t( 'pdf_done' ) . '</p><p class="small"><a id="pdfContinue" href="' . self::e( $vm['action'] . $next ) . '">' . self::t( 'pdf_continue' ) . '</a></p>' );
	}

	/**
	 * three steps. 1. Open the agreement (the PDF: E-SIGN, 15 USC 7001(c)(1)(C)(ii)). 2. Agree to sign
	 * electronically: one box, and one press that emails the code. 3. Sign: the code, one typed name, and a box beside
	 * each signature, in one form. The server checks every step again.
	 */
	private static function sign_card( array $vm ): string {
		if ( 'change' === ( $vm['kind'] ?? 'agreement' ) ) {
			return self::change_card( $vm );
		}
		$p1  = ! empty( $vm['pdf_done'] );
		$p2  = $p1 && ! empty( $vm['consent_done'] );
		$ok  = $p2 && ! empty( $vm['code_ok'] );
		// a step's message shows in the step it is about (the page lands there after the press); one that names the
		// code button names the one on the page
		$msg = self::message( (string) $vm['message'], array( 'button' => ZAGR_Strings::t( ! empty( $vm['code_sent'] ) ? 'code_resend' : 'code_send' ) ) + (array) $vm['vars'], 'sign' );
		$m2  = in_array( (string) $vm['message'], array( 'm_pdf_first', 'm_consent_needed', 'm_consent_first' ), true ) || ! $p2 ? $msg : '';
		$m3  = '' === $m2 ? $msg : '';
		$h   = '<section class="card sign" id="sign" aria-labelledby="sign-h"><h2 id="sign-h">' . self::t( 'sign_heading' ) . '</h2><ol class="sign-steps">';

		// 1. the PDF preview
		$h .= '<li id="step1" class="' . self::state( $p1, ! $p1 ) . '"><h3>' . self::t( 's1_heading' ) . '</h3>' . self::pdf_step( $vm, $p1, 'pdf_button', '#step2' ) . '</li>';

		// 2. consent: one box; the press emails the code
		$h .= '<li id="step2" class="' . self::state( $p2, $p1 && ! $p2 ) . '"><h3>' . self::t( 's2_heading' ) . '</h3>' . $m2
			. '<div class="consent doc">' . $vm['consent_html'] . '</div>';
		if ( $p2 ) {
			$h .= '<p class="ok-line">' . self::t( 'consent_done' ) . '</p>';
		} else {
			$dis = $p1 ? '' : ' disabled';
			$h  .= self::form( $vm, '#step3' ) . self::hidden( $vm, 'consent' )
				. '<label class="check"><input type="checkbox" name="consent" value="1" id="consentBox"' . $dis . '><span>' . self::t( 'consent_label' ) . '</span></label>'
				. '<button type="submit" class="btn" id="consentBtn" data-needs="consentBox"' . $dis . '>' . self::t( 'consent_send' ) . '</button>'
				. '<p class="small">' . self::e( ZAGR_Strings::t( 'consent_where', array( 'email' => $vm['email_masked'] ) ) ) . '</p>'
				. ( $p1 ? '' : '<p class="small locked">' . self::t( 'locked_note' ) . '</p>' ) . '</form>';
		}
		$h .= '</li>';

		// 3. sign: the code, one typed name, a box beside each signature. The words beside each signature always show
		// (the statute's notice among them); the fields open once consent is recorded (the server checks it anyway)
		$dis = $p2 ? '' : ' disabled';
		$h  .= '<li id="step3" class="' . self::state( false, $p2 ) . '"><h3>' . self::t( 's3_heading' ) . '</h3>' . $m3;
		if ( $p2 && ! $ok ) {
			$h .= '<p>' . self::e( ZAGR_Strings::t( ! empty( $vm['code_sent'] ) ? 'code_sent_to' : 'code_where', array( 'email' => $vm['email_masked'] ) ) ) . '</p>'
				. self::form( $vm, '#step3' ) . self::hidden( $vm, 'send_code', array( 'purpose' => 'sign' ) )
				. '<button type="submit" class="btn ghost slim" id="resendBtn">' . self::t( ! empty( $vm['code_sent'] ) ? 'code_resend' : 'code_send' ) . '</button></form>';
		}
		$h .= self::form( $vm, '#top', 'signForm' ) . self::hidden( $vm, 'sign', array( 'doc_sha' => $vm['doc_sha'], 'sign_date' => $vm['sign_date'] ) );
		$needs = 'sigName tick1 tick2';
		if ( $ok ) {
			$h .= '<p class="ok-line">' . self::t( 'code_done' ) . '</p>';
		} else {
			$h     .= '<label class="field" for="codeIn">' . self::t( 'code_in_label' ) . '</label>'
				. '<input type="text" id="codeIn" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required' . $dis . '>';
			$needs = 'codeIn ' . $needs;
		}
		$h .= self::name_field( $vm, 'sign_name_label', $dis ) . '<p class="small">' . self::t( 'sign_name_help' ) . '</p>';
		$ticks = array(
			'sign-agreement' => self::tick( 'tick1', ZAGR_Strings::t( 'tick_agreement' ), (string) $vm['sign_date_long'], $dis ),
			'sign-notice'    => self::tick( 'tick2', ZAGR_Strings::t( 'tick_notice' ), (string) $vm['sign_date_long'], $dis ),
		);
		foreach ( (array) ( $vm['order'] ?? ZAGR_Template::AGREEMENT ) as $name ) {
			if ( isset( $ticks[ $name ] ) && isset( $vm['sections'][ $name ] ) ) {
				$h .= '<div class="sigplace doc' . ( 'sign-notice' === $name ? ' notice-place' : '' ) . '" id="place-' . self::e( $name ) . '">' . self::place( (string) $vm['sections'][ $name ], $ticks[ $name ] ) . '</div>';
			}
		}
		return $h . '<button type="submit" class="btn" id="signBtn" data-needs="' . $needs . '"' . $dis . '>' . self::t( 'sign_button' ) . '</button>'
			. ( $p2 ? '' : '<p class="small locked">' . self::t( 'locked_note' ) . '</p>' ) . '</form></li></ol>'
			. '<p class="small help">' . self::t( 'help_line', $vm['vars'] ) . '</p></section>';
	}

	/**
	 * A change order after signing: the code comes first, and its words, PDF, consent and signature open
	 * only once the code is accepted ($vm['locked'] until then: nothing of the change order was drawn). One
	 * consent box, and the one typed name with its box.
	 */
	private static function change_card( array $vm ): string {
		$p1 = empty( $vm['locked'] ) && ! empty( $vm['code_ok'] );
		$p2 = $p1 && ! empty( $vm['pdf_done'] );
		$p3 = $p2 && ! empty( $vm['consent_done'] );
		$h  = '<section class="card sign" id="sign" aria-labelledby="sign-h"><h2 id="sign-h">' . self::t( 'change_sign_heading' ) . '</h2><ol class="sign-steps">';

		// 1. the code
		$h .= '<li id="step1" class="' . self::state( $p1, ! $p1 ) . '"><h3>' . self::t( 'c1_heading' ) . '</h3>';
		if ( $p1 ) {
			$h .= '<p class="ok-line">' . self::t( 'code_done' ) . '</p>';
		} else {
			$h .= '<p>' . self::e( ZAGR_Strings::t( ! empty( $vm['code_sent'] ) ? 'code_sent_to' : 'change_code_help', array( 'email' => $vm['email_masked'] ) ) ) . '</p>'
				. self::form( $vm, '#step1' ) . self::hidden( $vm, 'send_code', array( 'purpose' => 'change' ) )
				. '<button type="submit" class="btn ghost" id="changeSend">' . self::t( ! empty( $vm['code_sent'] ) ? 'code_resend' : 'code_send' ) . '</button></form>';
			if ( ! empty( $vm['code_sent'] ) ) {
				$h .= self::form( $vm, '#step2' ) . self::hidden( $vm, 'verify_code', array( 'purpose' => 'change' ) )
					. '<label class="field" for="codeIn">' . self::t( 'code_label' ) . '</label>'
					. '<div class="row"><input type="text" id="codeIn" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required>'
					. '<button type="submit" class="btn" id="codeBtn">' . self::t( 'code_check' ) . '</button></div></form>';
			}
		}
		$h .= '</li>';

		// 2. the PDF preview
		$h .= '<li id="step2" class="' . self::state( $p2, $p1 && ! $p2 ) . '"><h3>' . self::t( 'c2_heading' ) . '</h3>';
		$h .= $p1 ? self::pdf_step( $vm, $p2, 'change_pdf_button', '#step3' ) : '<p class="small locked">' . self::t( 'locked_note' ) . '</p>';
		$h .= '</li>';

		// 3. consent: one box
		$h .= '<li id="step3" class="' . self::state( $p3, $p2 && ! $p3 ) . '"><h3>' . self::t( 'c3_heading' ) . '</h3>';
		if ( ! $p1 ) {
			$h .= '<p class="small locked">' . self::t( 'locked_note' ) . '</p>';
		} else {
			$h .= '<div class="consent doc">' . $vm['consent_html'] . '</div>';
			if ( $p3 ) {
				$h .= '<p class="ok-line">' . self::t( 'consent_done' ) . '</p>';
			} else {
				$dis = $p2 ? '' : ' disabled';
				$h  .= self::form( $vm, '#step4' ) . self::hidden( $vm, 'consent' )
					. '<label class="check"><input type="checkbox" name="consent" value="1" id="consentBox"' . $dis . '><span>' . self::t( 'consent_label_change' ) . '</span></label>'
					. '<button type="submit" class="btn" id="consentBtn" data-needs="consentBox"' . $dis . '>' . self::t( 'continue' ) . '</button>'
					. ( $p2 ? '' : '<p class="small locked">' . self::t( 'locked_note' ) . '</p>' ) . '</form>';
			}
		}
		$h .= '</li>';

		// 4. sign: one typed name and its box
		$h .= '<li id="step4" class="' . self::state( false, $p3 ) . '"><h3>' . self::t( 'c4_heading' ) . '</h3>';
		if ( ! $p1 ) {
			$h .= '<p class="small locked">' . self::t( 'locked_note' ) . '</p>';
		} else {
			$dis = $p3 ? '' : ' disabled';
			$h  .= self::form( $vm, '#top' ) . self::hidden( $vm, 'sign_change', array( 'doc_sha' => $vm['doc_sha'], 'sign_date' => $vm['sign_date'] ) )
				. '<label class="field" for="sigName">' . self::t( 'change_sig_label' ) . '</label>'
				. '<input type="text" id="sigName" name="sig" autocomplete="name" maxlength="120" placeholder="' . self::t( 'full_name' ) . '" value="' . self::e( (string) ( $vm['kept_name'] ?? '' ) ) . '"' . $dis . '>'
				. '<div class="sigtick"><label class="check"><input type="checkbox" name="tick1" value="1" id="tick1"' . $dis . '><span>' . self::t( 'change_sig_tick' ) . '</span></label>'
				. '<p class="sigshow" aria-hidden="true"><span class="signame" data-mirror="sigName"></span><span class="sigdate">' . self::t( 'date_line', array( 'date' => $vm['sign_date_long'] ) ) . '</span></p></div>'
				. '<button type="submit" class="btn" id="signBtn" data-needs="sigName tick1"' . $dis . '>' . self::t( 'sign_button' ) . '</button>'
				. ( $p3 ? '' : '<p class="small locked">' . self::t( 'locked_note' ) . '</p>' ) . '</form>';
		}
		return $h . '</li></ol><p class="small help">' . self::t( 'help_line', $vm['vars'] ) . '</p></section>';
	}

	/* ================================================================ after signing */

	/**
	 * $vm: test, message, vars (phone, email, address), token, action, state, signed_long, copy_via (email | opened |
	 * '' not received), copy_long, window_started, cancel_by_long, end_ts, window_open, window_closed, cancelled_long,
	 * payments (rows), payments_signed, manage (verified, code_sent, may_open, done, copy_url, change_copy_url, no_key),
	 * change (null | preparing | change => sign vm, locked until the code).
	 */
	public static function status( array $vm ): string {
		$v      = $vm['vars'];
		$cancel = 'cancelled' === $vm['state'];
		$b      = '<section class="card head" id="top">' . self::brand() . self::message( (string) $vm['message'], $v )
			. ( $cancel ? '' : '<p class="hello">' . self::t( 'hello' ) . '</p>' )
			. '<h1>' . self::t( $cancel ? 'status_h1_cancelled' : 'status_h1' ) . '</h1>';
		if ( $cancel ) {
			$b .= '<div class="status bad" role="status"><p><strong>' . self::t( 'cancelled_title', array( 'date' => $vm['cancelled_long'] ) ) . '</strong></p><p class="small">' . self::t( 'cancelled_sub' ) . '</p></div>';
		} else {
			// "emailed" only when it was; "opened" when the customer opened it here; otherwise, plainly, that it wasn't
			$via  = (string) ( $vm['copy_via'] ?? 'email' );
			if ( ! empty( $vm['paper'] ) ) {   // signed on paper; the customer keeps the paper copy
				$b .= '<div class="status ok" role="status"><p><strong>' . self::t( 'paper_signed_title', array( 'date' => $vm['signed_long'] ) ) . '</strong></p><p class="small">' . self::t( 'paper_signed_sub' ) . '</p></div>';
			} else {
				$copy = 'email' === $via ? self::t( 'copy_emailed', $v ) : ( 'opened' === $via ? self::t( 'copy_opened', array( 'date' => $vm['copy_long'] ) ) : self::t( 'copy_not_received' ) );
				$b   .= '<div class="status ' . ( '' === $via ? 'warn' : 'ok' ) . '" role="status"><p><strong>' . self::t( 'signed_title', array( 'date' => $vm['signed_long'] ) ) . '</strong></p><p class="small">' . $copy . '</p></div>';
			}
			if ( 'change' === ( $vm['change']['kind'] ?? '' ) ) {
				$b .= '<div class="status warn" role="status"><p><strong>' . self::t( 'change_ready' ) . '</strong></p><p class="small">' . self::t( 'change_window_note' ) . '</p></div>';
			} elseif ( 'preparing' === ( $vm['change']['kind'] ?? '' ) ) {
				$b .= '<div class="status warn" role="status"><p>' . self::t( 'change_preparing', $v ) . '</p></div>';
			}
			// the five steps; "You sign" done once the signed copy was received
			$b .= self::next_steps( ZAGR_Strings::t( '' === $via ? 'next_signed' : 'next_done' ) );
		}
		$b .= '</section>';

		// the right to cancel, counting down: stated plainly
		if ( ! $cancel ) {
			$b .= '<section class="card" id="cancel" aria-labelledby="cancel-h"><h2 id="cancel-h">' . self::t( 'cancel_heading' ) . '</h2>';
			if ( $vm['window_open'] && empty( $vm['window_started'] ) && array_key_exists( 'window_started', $vm ) ) {
				$b .= '<p>' . self::t( 'cancel_not_started', array( 'ordinal' => (string) ( $vm['ordinal'] ?? '' ) ) ) . '</p>'
					. '<p class="small">' . self::t( empty( $vm['manage']['no_key'] ) ? 'ways_to_cancel' : 'ways_to_cancel_nb', $v ) . '</p>';
			} elseif ( $vm['window_open'] ) {
				$ways = empty( $vm['paper'] ) ? 'ways_to_cancel' : 'paper_ways_to_cancel';   // any written notice counts
				$b .= '<p>' . self::t( 'cancel_until' ) . '</p><p class="deadline">' . self::t( 'deadline', array( 'date' => $vm['cancel_by_long'] ) ) . '</p>'
					. '<p class="countdown" id="countdown" data-end="' . (int) $vm['end_ts'] . '">' . self::e( self::left( (int) $vm['end_ts'], (int) $vm['now'] ) ) . '</p>'
					. '<p>' . self::t( 'order_after' ) . '</p>'
					. '<p class="small">' . self::t( empty( $vm['manage']['no_key'] ) ? $ways : $ways . '_nb', $v ) . '</p>';
			} else {
				$b .= '<p><strong>' . self::t( 'window_closed', array( 'date' => $vm['cancel_by_long'] ) ) . '</strong></p>'
					. ( false === ( $vm['order_note'] ?? true ) ? '' : '<p>' . self::t( 'window_closed_sub' ) . '</p>' );   // not while a late paper signing waits
			}
			$b .= '</section>';
		}

		// the payments, as signed or as offered
		$b .= '<section class="card" id="payments" aria-labelledby="pay-h"><h2 id="pay-h">' . self::t( 'payments_heading' ) . '</h2><p class="small">'
			. self::t( $vm['payments_signed'] ? 'payments_signed' : 'payments_offered' ) . '</p><table class="sched"><tbody>';
		foreach ( $vm['payments'] as $p ) {
			$b .= '<tr><td><strong>' . self::e( $p[0] ) . '</strong><span class="due">' . self::e( $p[1] ) . '</span></td><td class="amt">' . self::e( ZAGR_Document::money( (int) $p[2] ) ) . '</td></tr>';
		}
		$b .= '</tbody><tfoot><tr><td>' . self::t( 'pay_total' ) . '</td><td class="amt">' . self::e( ZAGR_Document::money( (int) $vm['total_cents'] ) ) . '</td></tr></tfoot></table>'
			. '<p class="promise">' . self::t( 'promise' ) . '</p></section>';

		// a change order to sign: its words only after the code
		if ( 'change' === ( $vm['change']['kind'] ?? '' ) ) {
			$c  = $vm['change'];
			$b .= ( empty( $c['locked'] ) && ! empty( $c['change_html'] ) ? '<section class="card doc" id="change">' . str_replace( '<p class="sig-slot"></p>', '', (string) $c['change_html'] ) . '</section>' : '<span id="change"></span>' ) . self::sign_card( $c );
		}

		// the signed copy and Cancel, behind a fresh code
		$b .= self::manage( $vm );
		$b .= '<p class="small help">' . self::t( 'help_line', $v ) . '</p>';
		return self::frame( $b, ! empty( $vm['test'] ) );
	}

	private static function manage( array $vm ): string {
		$m      = $vm['manage'];
		$cancel = $vm['window_open'] && 'cancelled' !== $vm['state'];
		$paper  = ! empty( $vm['paper'] );   // nothing to open here; Cancel only, while the window is open
		if ( $paper && ! $cancel ) {
			return '';
		}
		$h      = '<section class="card" id="manage" aria-labelledby="manage-h"><h2 id="manage-h">' . self::t( $paper ? 'paper_manage_heading' : 'manage_heading' ) . '</h2>';
		if ( ! empty( $m['no_key'] ) ) {
			return $h . '<p>' . self::t( $paper ? 'paper_ways_to_cancel_nb' : 'manage_no_key', $vm['vars'] ) . '</p></section>';
		}
		if ( ! empty( $m['may_open'] ) ) {
			$h .= '<p><a class="btn ghost" id="copyBtn" href="' . self::e( $m['copy_url'] ) . '" target="_blank" rel="noopener">' . self::t( 'copy_here' ) . '</a></p>';
			if ( ! empty( $m['change_copy_url'] ) ) {
				$h .= '<p><a class="btn ghost" href="' . self::e( $m['change_copy_url'] ) . '" target="_blank" rel="noopener">' . self::t( 'change_copy' ) . '</a></p>';
			}
		}
		// "Done": on a shared device, the next person needs a code again
		$done = empty( $m['done'] ) ? '' : self::form( $vm, '#top' ) . self::hidden( $vm, 'done' ) . '<button type="submit" class="btn ghost" id="doneBtn">' . self::t( 'done_button' ) . '</button><span class="small"> ' . self::t( 'done_help' ) . '</span></form>';
		if ( ! empty( $m['verified'] ) ) {
			if ( $cancel ) {
				$h .= self::form( $vm, '#top' ) . self::hidden( $vm, 'cancel' )
					. '<label class="check"><input type="checkbox" name="cancel_tick" value="1" id="cancelTick"><span>' . self::t( 'cancel_tick' ) . '</span></label>'
					. '<button type="submit" class="btn danger" id="cancelBtn" data-needs="cancelTick">' . self::t( 'cancel_button' ) . '</button>'
					. '<p class="small">' . self::t( 'cancel_help' ) . '</p></form>';
			}
			return $h . $done . '</section>';
		}
		$h .= '<p>' . self::t( $paper ? 'paper_manage_help' : ( ! empty( $m['may_open'] ) ? ( $cancel ? 'manage_help_cancel' : 'manage_help_more' ) : ( $cancel ? 'manage_help' : 'manage_help_closed' ) ) ) . '</p>'
			. self::form( $vm, '#manage' ) . self::hidden( $vm, 'send_code', array( 'purpose' => 'manage' ) )
			. '<button type="submit" class="btn ghost" id="manageSend">' . self::t( ! empty( $m['code_sent'] ) ? 'code_resend' : 'code_send' ) . '</button></form>';
		if ( ! empty( $m['code_sent'] ) ) {
			$h .= self::form( $vm, '#manage' ) . self::hidden( $vm, 'verify_code', array( 'purpose' => 'manage' ) )
				. '<label class="field" for="manageCode">' . self::t( 'code_label' ) . '</label>'
				. '<div class="row"><input type="text" id="manageCode" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required>'
				. '<button type="submit" class="btn" id="manageCheck">' . self::t( 'code_check' ) . '</button></div></form>';
		}
		return $h . $done . '</section>';
	}

	/** "3 days and 5 hours left." */
	public static function left( int $end, int $now ): string {
		$s = max( 0, $end - $now );
		$d = intdiv( $s, 86400 );
		$h = intdiv( $s % 86400, 3600 );
		return ZAGR_Strings::t( 'left_days', array(
			'days'  => $d . ( 1 === $d ? ' day' : ' days' ),
			'hours' => $h . ( 1 === $h ? ' hour' : ' hours' ),
		) );
	}
}
