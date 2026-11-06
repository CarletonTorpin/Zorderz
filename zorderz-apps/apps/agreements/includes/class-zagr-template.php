<?php
/**
 * ZAGR_Template - the agreement's words, loaded, checked and rendered. [CORE mechanism; the words are the business's]
 *
 * A template is a folder: <version>/manifest.json names the version, the language its words are written in, the
 * sections, and optionally the page order ("order"), the sections that wait for the buyer's answers ("age_sections"),
 * the layout ("paper" or "classic"), the statutory blocks the template's author declares it carries ("statutory", for
 * a legal pack's checklist), and whether it is the bundled starter ("starter": a skeleton, never approvable). Each
 * section is one HTML fragment, <section>.html. A fragment may use only h2 h3 p strong ul li table tr th td br div ol,
 * no attribute but class (from a fixed list of classes with a meaning), and only the fixed {{placeholder}} tokens.
 * Anything else is refused when the template loads (ZAGR_Template_Error), so a template that would render wrong never
 * reaches a customer.
 *
 * Where templates live: the business's own folder first (wp-content/zorderz-agreements/templates/<version>/, filter
 * zagr_templates_dirs), then the bundled ones. A bundled version name is never shadowed.
 *
 * Each load fingerprints the exact files (the manifest and every section, in a fixed order): files_hash. An approval
 * names that fingerprint, so a template edited after approval is no longer approved.
 *
 * render() escapes every value; the *_html values are built by the app itself from escaped parts and the same tags.
 * The legal pack's notices arrive through the pack_*_html placeholders. An unknown placeholder is an error, never a
 * blank.
 *
 * @package Zorderz\Agreements
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZAGR_Template_Error extends RuntimeException {}

class ZAGR_Template {

	const SECTIONS = array( 'header', 'schedule', 'right-to-cancel', 'project', 'notices', 'sign-agreement', 'sign-notice',
		'notice-of-cancellation', 'change-order', 'consent' );

	/** The agreement document, in page order (the consent and the change order are documents of their own). */
	const AGREEMENT = array( 'header', 'schedule', 'right-to-cancel', 'project', 'notices', 'sign-agreement', 'sign-notice',
		'notice-of-cancellation' );

	const PLACEHOLDERS = array( 'company_name', 'company_address_html', 'company_address_line', 'license_number',
		'cancel_email', 'help_phone', 'salesperson_name', 'salesperson_registration', 'buyer_name', 'job_address_html',
		'contract_price', 'finance_charge', 'project_lines_html', 'downpayment', 'schedule_table_html',
		'substantial_commencement', 'approx_start', 'approx_completion', 'subcontractors', 'window_days', 'cancel_by',
		'signed_date', 'contractor_signer', 'contractor_signed_date', 'agreement_url', 'template_version',
		'change_summary_html', 'window_title', 'window_days_word', 'window_day_ordinal', 'age_answer', 'cgl_insurer',
		'cgl_phone', 'refund_days', 'pack_label',
		// the legal pack's notices (ZAGR_Pack::SLOTS)
		'pack_statement_html', 'pack_cancellation_form_html', 'pack_first_page_html', 'pack_deposit_notice_html' );

	/** The sections whose words depend on the buyer's answers, unless a manifest names its own. */
	const AGE_SECTIONS = array( 'right-to-cancel', 'project', 'sign-agreement', 'sign-notice', 'notice-of-cancellation' );

	const TAGS = array( 'h2', 'h3', 'p', 'strong', 'ul', 'li', 'table', 'tr', 'th', 'td', 'br', 'div', 'ol' );

	/** Sections a template may add to the ten (in its manifest's "sections" and "order"). */
	const OPTIONAL = array( 'good-to-know' );

	/**
	 * The words that depend on the buyer's answers. A section using one is never drawn before the answers: it must be
	 * one of the template's age_sections.
	 */
	const AGE_TOKENS = array( 'window_title', 'window_days_word', 'window_day_ordinal', 'window_days', 'age_answer', 'cancel_by',
		'pack_statement_html', 'pack_cancellation_form_html' );

	const LAYOUTS = array( 'classic', 'paper' );

	/**
	 * Classes with a meaning. Every one prints at 10 points or more.
	 *   stat-bold   12-point bold (a statutory statement)
	 *   stat-12     12-point, not bold (a statutory notice set at that size)
	 *   todo        text still to come: marked on the page and in the PDF; a template holding one is never ready
	 *   noc-copy    the start of a Notice of Cancellation copy (a new PDF page)
	 *   hello       the warm first line of page one
	 *   card, cards a card; a row of cards (side by side where there is room)
	 *   steps       "What happens next": its list is numbered in circles
	 *   money       an amount set large in its card
	 *   small       quieter words (10 points in the PDF, never smaller)
	 *   notice      the box around a statutory right-to-cancel notice
	 *   first-foot  the foot of page one (where a Notice of Cancellation may be sent, the help phone)
	 *   age-slot    where the page asks the buyer's question (nothing in the PDF)
	 *   sig-slot    where a signature goes: on the page the "I sign" box, in the PDF the typed name and the date
	 *   first-page  a line the law puts on the first page (the date the buyer signed): the PDF keeps it on page one
	 */
	const CLASSES = array( 'stat-bold', 'stat-12', 'todo', 'noc-copy', 'hello', 'card', 'cards', 'steps', 'money', 'small',
		'notice', 'first-foot', 'age-slot', 'sig-slot', 'first-page' );

	/** @var array version => loaded template, this request */
	private static $cache = array();

	/** The folders templates are read from: the business's own, then the bundled ones. */
	public static function dirs(): array {
		$own  = ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '' ) . '/zorderz-agreements/templates';
		$dirs = array( $own, rtrim( ZAGR_DIR, '/' ) . '/templates' );
		if ( function_exists( 'apply_filters' ) ) {
			$f    = apply_filters( 'zagr_templates_dirs', $dirs );
			$dirs = is_array( $f ) && $f ? array_values( array_map( 'strval', $f ) ) : $dirs;
		}
		return $dirs;
	}

	/** version => its folder. A bundled version is never shadowed by the same name elsewhere. */
	private static function index(): array {
		$bundled = rtrim( ZAGR_DIR, '/' ) . '/templates';
		$out     = array();
		foreach ( (array) glob( $bundled . '/*/manifest.json' ) as $m ) {
			$out[ basename( dirname( (string) $m ) ) ] = dirname( (string) $m );
		}
		foreach ( self::dirs() as $d ) {
			foreach ( (array) glob( rtrim( $d, '/' ) . '/*/manifest.json' ) as $m ) {
				$v = basename( dirname( (string) $m ) );
				if ( ! isset( $out[ $v ] ) ) {
					$out[ $v ] = dirname( (string) $m );
				}
			}
		}
		foreach ( array_keys( $out ) as $v ) {
			if ( ! preg_match( '/^[A-Za-z0-9._-]{1,40}$/', (string) $v ) ) {
				unset( $out[ $v ] );
			}
		}
		ksort( $out );
		return $out;
	}

	/** Versions present on disk (folders holding a manifest.json). */
	public static function versions(): array {
		return array_keys( self::index() );
	}

	/**
	 * Load and check one version: { version, language, sections: name => html, files_hash, uses, has_todo, order,
	 * age_sections, layout, statutory, starter }.
	 * @throws ZAGR_Template_Error
	 */
	public static function load( string $version ): array {
		if ( isset( self::$cache[ $version ] ) ) {
			return self::$cache[ $version ];
		}
		if ( ! preg_match( '/^[A-Za-z0-9._-]{1,40}$/', $version ) || false !== strpos( $version, '..' ) ) {
			throw new ZAGR_Template_Error( 'The template version name is not valid.' );
		}
		$idx = self::index();
		if ( ! isset( $idx[ $version ] ) ) {
			throw new ZAGR_Template_Error( 'Template ' . $version . ' has no readable manifest.json.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an internal error: callers escape the message where an administrator sees it (ZAGR_Admin); the customer's page never shows it
		}
		$dir  = $idx[ $version ] . '/';
		$mraw = is_readable( $dir . 'manifest.json' ) ? (string) file_get_contents( $dir . 'manifest.json' ) : '';
		$man  = json_decode( $mraw, true );
		if ( ! is_array( $man ) ) {
			throw new ZAGR_Template_Error( 'Template ' . $version . ' has no readable manifest.json.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an internal error: callers escape the message where an administrator sees it (ZAGR_Admin); the customer's page never shows it
		}
		if ( (string) ( $man['version'] ?? '' ) !== $version ) {
			throw new ZAGR_Template_Error( 'Template ' . $version . ': the manifest names another version.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an internal error: callers escape the message where an administrator sees it (ZAGR_Admin); the customer's page never shows it
		}
		$lang = (string) ( $man['language'] ?? '' );
		if ( ! preg_match( '/^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/', $lang ) ) {
			throw new ZAGR_Template_Error( 'Template ' . $version . ': the manifest must name the language its words are in (for example "en").' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an internal error: callers escape the message where an administrator sees it (ZAGR_Admin); the customer's page never shows it
		}
		$listed = array_values( array_map( 'strval', (array) ( $man['sections'] ?? array() ) ) );
		$extra  = array_values( array_diff( $listed, self::SECTIONS ) );
		if ( count( $listed ) !== count( array_unique( $listed ) ) || array_diff( self::SECTIONS, $listed ) || array_diff( $extra, self::OPTIONAL ) ) {
			throw new ZAGR_Template_Error( 'Template ' . $version . ' must list exactly these sections: ' . implode( ', ', self::SECTIONS ) . ' (and may add ' . implode( ', ', self::OPTIONAL ) . ').' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an internal error: callers escape the message where an administrator sees it (ZAGR_Admin); the customer's page never shows it
		}
		$extra    = array_values( array_intersect( self::OPTIONAL, $extra ) );   // hashed and loaded in a fixed order
		$sections = array();
		$ctx      = hash_init( 'sha256' );
		hash_update( $ctx, "manifest\n" . $mraw . "\n" );
		foreach ( array_merge( self::SECTIONS, $extra ) as $s ) {
			$f = $dir . $s . '.html';
			if ( ! is_readable( $f ) ) {
				throw new ZAGR_Template_Error( 'Template ' . $version . ' is missing ' . $s . '.html.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an internal error: callers escape the message where an administrator sees it (ZAGR_Admin); the customer's page never shows it
			}
			$html = (string) file_get_contents( $f );
			hash_update( $ctx, $s . "\n" . $html . "\n" );
			$html = self::strip_comments( $html );
			self::validate( $html, $s );
			$sections[ $s ] = trim( $html );
		}
		$uses = array();
		foreach ( $sections as $html ) {
			$uses = array_merge( $uses, self::tokens( $html ) );
		}
		$order = self::manifest_order( $man, $extra, $version );
		$ages  = self::manifest_ages( $man, $order, $sections, $version );
		$lay   = (string) ( $man['layout'] ?? 'classic' );
		if ( ! in_array( $lay, self::LAYOUTS, true ) ) {
			throw new ZAGR_Template_Error( 'Template ' . $version . ': the layout must be ' . implode( ' or ', self::LAYOUTS ) . '.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an internal error: callers escape the message where an administrator sees it (ZAGR_Admin); the customer's page never shows it
		}
		$stat = array();
		foreach ( (array) ( $man['statutory'] ?? array() ) as $k ) {
			if ( is_string( $k ) && preg_match( '/^[a-z_]{2,40}$/', $k ) ) {
				$stat[] = $k;
			}
		}
		return self::$cache[ $version ] = array(
			'version'      => $version,
			'language'     => $lang,
			'sections'     => $sections,
			'files_hash'   => hash_final( $ctx ),
			'uses'         => array_values( array_unique( $uses ) ),
			'has_todo'     => (bool) preg_match( '/class="[^"]*\btodo\b/', implode( '', $sections ) ),
			'order'        => $order,
			'age_sections' => $ages,
			'layout'       => $lay,
			'statutory'    => array_values( array_unique( $stat ) ),
			'starter'      => ! empty( $man['starter'] ),
		);
	}

	/**
	 * The agreement's sections in page order: the manifest's "order", which must hold the agreement's eight sections
	 * and the optional ones the template has, each once (never the consent or the change order, which are documents of
	 * their own); without it, AGREEMENT.
	 * @throws ZAGR_Template_Error
	 */
	private static function manifest_order( array $man, array $extra, string $version ): array {
		if ( ! array_key_exists( 'order', $man ) ) {
			return array_merge( self::AGREEMENT, $extra );
		}
		$o    = is_array( $man['order'] ) ? array_values( array_map( 'strval', $man['order'] ) ) : array();
		$want = array_merge( self::AGREEMENT, $extra );
		$a    = $o;
		$b    = $want;
		sort( $a );
		sort( $b );
		if ( $a !== $b ) {
			throw new ZAGR_Template_Error( 'Template ' . $version . ': the order must name each of these once: ' . implode( ', ', $want ) . '.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an internal error: callers escape the message where an administrator sees it (ZAGR_Admin); the customer's page never shows it
		}
		return $o;
	}

	/**
	 * The sections that wait for the buyer's answers: the manifest's "age_sections", or AGE_SECTIONS. Every section of
	 * the agreement that uses a word depending on the answers (AGE_TOKENS) must be one of them, so a notice is never
	 * drawn before the answers, whatever a manifest says.
	 * @throws ZAGR_Template_Error
	 */
	private static function manifest_ages( array $man, array $order, array $sections, string $version ): array {
		$ages = array_key_exists( 'age_sections', $man )
			? ( is_array( $man['age_sections'] ) ? array_values( array_map( 'strval', $man['age_sections'] ) ) : array( '' ) )
			: self::AGE_SECTIONS;
		if ( array_diff( $ages, $order ) ) {
			throw new ZAGR_Template_Error( 'Template ' . $version . ': age_sections may name only the agreement\'s own sections.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an internal error: callers escape the message where an administrator sees it (ZAGR_Admin); the customer's page never shows it
		}
		foreach ( $order as $s ) {
			if ( array_intersect( self::tokens( $sections[ $s ] ), self::AGE_TOKENS ) && ! in_array( $s, $ages, true ) ) {
				throw new ZAGR_Template_Error( 'Template ' . $version . ': the section ' . $s . ' depends on the buyer\'s answers, so it must be one of age_sections.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an internal error: callers escape the message where an administrator sees it (ZAGR_Admin); the customer's page never shows it
			}
		}
		return $ages;
	}

	/** A loaded template's agreement sections in page order. */
	public static function order( array $tpl ): array {
		return (array) ( $tpl['order'] ?? self::AGREEMENT );
	}

	/** A loaded template's sections that wait for the buyer's answers. */
	public static function age_sections( array $tpl ): array {
		return (array) ( $tpl['age_sections'] ?? self::AGE_SECTIONS );
	}

	/** 'paper' (cards, numbered steps, a stripe) or 'classic' (plain headings and paragraphs). */
	public static function layout( array $tpl ): string {
		return 'paper' === ( $tpl['layout'] ?? '' ) ? 'paper' : 'classic';
	}

	/** The language's name for the words people read ("English" for "en"), or the code itself. */
	public static function language_name( string $code ): string {
		$names = array( 'en' => 'English', 'es' => 'Spanish', 'zh' => 'Chinese', 'tl' => 'Tagalog', 'vi' => 'Vietnamese',
			'ko' => 'Korean', 'fr' => 'French', 'de' => 'German', 'pt' => 'Portuguese', 'it' => 'Italian' );
		$base  = strtolower( (string) strtok( $code, '-' ) );
		return $names[ $base ] ?? $code;
	}

	public static function forget(): void {
		self::$cache = array();
	}

	public static function strip_comments( string $html ): string {
		return (string) preg_replace( '/<!--.*?-->/s', '', $html );
	}

	/**
	 * A fragment holds only the allowed tags, attributes and placeholders.
	 * @throws ZAGR_Template_Error
	 */
	public static function validate( string $html, string $name ): void {
		if ( preg_match( '/<\s*(script|style|iframe|object|embed|img|a|form|input|link|meta|svg|math)\b/i', $html ) ) {
			throw new ZAGR_Template_Error( $name . ': a tag that is not allowed in a template.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an internal error: callers escape the message where an administrator sees it (ZAGR_Admin); the customer's page never shows it
		}
		// every tag, opening or closing
		if ( preg_match_all( '/<\s*(\/?)\s*([A-Za-z][A-Za-z0-9]*)([^>]*)>/', $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $t ) {
				$tag = strtolower( $t[2] );
				if ( ! in_array( $tag, self::TAGS, true ) ) {
					throw new ZAGR_Template_Error( $name . ': <' . $tag . '> is not allowed in a template.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an internal error: callers escape the message where an administrator sees it (ZAGR_Admin); the customer's page never shows it
				}
				$attrs = trim( rtrim( trim( $t[3] ), '/' ) );
				if ( '' === $attrs ) {
					continue;
				}
				if ( '/' === $t[1] ) {
					throw new ZAGR_Template_Error( $name . ': a closing tag with attributes.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an internal error: callers escape the message where an administrator sees it (ZAGR_Admin); the customer's page never shows it
				}
				if ( ! preg_match( '/^class\s*=\s*"([a-z0-9 _-]*)"$/', $attrs, $cm ) ) {
					throw new ZAGR_Template_Error( $name . ': only a class attribute (lowercase letters, digits, - and _) is allowed.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an internal error: callers escape the message where an administrator sees it (ZAGR_Admin); the customer's page never shows it
				}
				foreach ( preg_split( '/\s+/', trim( $cm[1] ) ) as $cls ) {
					if ( '' !== $cls && ! in_array( $cls, self::CLASSES, true ) ) {
						throw new ZAGR_Template_Error( $name . ': the class "' . $cls . '" has no meaning here (use ' . implode( ', ', self::CLASSES ) . ').' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an internal error: callers escape the message where an administrator sees it (ZAGR_Admin); the customer's page never shows it
					}
				}
			}
		}
		if ( preg_match( '/<(?!\s*\/?\s*[A-Za-z])/', $html ) ) {
			throw new ZAGR_Template_Error( $name . ': a stray < in the text (write &lt;).' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an internal error: callers escape the message where an administrator sees it (ZAGR_Admin); the customer's page never shows it
		}
		if ( preg_match( '/\bon[a-z]+\s*=|javascript:/i', $html ) ) {
			throw new ZAGR_Template_Error( $name . ': script in a template.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an internal error: callers escape the message where an administrator sees it (ZAGR_Admin); the customer's page never shows it
		}
		foreach ( self::tokens( $html ) as $tok ) {
			if ( ! in_array( $tok, self::PLACEHOLDERS, true ) ) {
				throw new ZAGR_Template_Error( $name . ': unknown placeholder {{' . $tok . '}}.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an internal error: callers escape the message where an administrator sees it (ZAGR_Admin); the customer's page never shows it
			}
		}
		$rest = (string) preg_replace( '/\{\{[a-z_]+\}\}/', '', $html );
		if ( false !== strpos( $rest, '{{' ) || false !== strpos( $rest, '}}' ) ) {
			throw new ZAGR_Template_Error( $name . ': a placeholder is not written as {{name}}.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an internal error: callers escape the message where an administrator sees it (ZAGR_Admin); the customer's page never shows it
		}
	}

	/** The placeholder names a fragment uses. */
	public static function tokens( string $html ): array {
		preg_match_all( '/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', $html, $m );
		return array_values( array_unique( $m[1] ) );
	}

	/**
	 * Fill a fragment. Plain values are escaped; *_html values are inserted as built (the app made them from escaped
	 * parts and the allowed tags). A placeholder without a value is an error.
	 * @throws ZAGR_Template_Error
	 */
	public static function render( string $html, array $values ): string {
		return (string) preg_replace_callback( '/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', function ( $m ) use ( $values ) {
			$k = $m[1];
			if ( ! in_array( $k, self::PLACEHOLDERS, true ) ) {
				throw new ZAGR_Template_Error( 'Unknown placeholder {{' . $k . '}}.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an internal error: callers escape the message where an administrator sees it (ZAGR_Admin); the customer's page never shows it
			}
			if ( ! array_key_exists( $k, $values ) ) {
				throw new ZAGR_Template_Error( 'No value for {{' . $k . '}}.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an internal error: callers escape the message where an administrator sees it (ZAGR_Admin); the customer's page never shows it
			}
			$v = (string) $values[ $k ];
			return '_html' === substr( $k, -5 ) ? $v : self::esc( $v );
		}, $html );
	}

	public static function esc( string $s ): string {
		return htmlspecialchars( $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
