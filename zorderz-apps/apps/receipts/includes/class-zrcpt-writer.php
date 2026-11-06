<?php
/**
 * ZRCPT_Writer -- the receipt's words from the platform's one Ai router, and the page they go on.
 *
 * WHO WRITES WHAT
 *   The model     writes the words only, as JSON (schema()): the job site's street line, a headline, the unit count in
 *                 a few words and up to six short sections (what was done, materials and specifications the facts
 *                 state, notes for the customer). Its instructions are this app's own (system_prompt()), the same on
 *                 every site: use only the facts given, never a price, an amount, a payment state, a link, a phone or
 *                 an e-mail address.
 *   This class    builds the page around those words (page()): the business's letterhead (zrcpt_render_letterhead(),
 *                 from the Business Profile), the street and the work date, the words (escaped: the model's text is
 *                 never markup), the invoice links of a combined receipt, the photo gallery (the photos the generator
 *                 checked, in its order; the model never sees or places them) and the business's closing line
 *                 (document convention closing_line). ZRCPT_Finalize then adds the invoice panel and the full-size
 *                 photos, and sanitizes the whole page, as for every receipt.
 *
 * THE ROAD. Every model call goes through ZDZ_Core_LLM::ask( $role, … ) as the caller 'receipts' (its row in
 * ZDZ_Core_LLM::CALLERS lets it send a picture or a PDF on read_document). The router codes every customer detail with
 * ZDZ_Private before anything leaves the site (gate codes are cut), so this class hands it the PLAIN facts; the answer
 * comes back coded and is decoded here (ZDZ_Private::for( 'llm' )), because the receipt is the customer's own document
 * and carries their street. Which model writes, and what it may cost, is the business's (the router's model slots and
 * budgets); nothing here names a model or a provider. An uploaded invoice goes as a picture (JPEG, PNG, GIF, WebP) or a
 * PDF on read_document; a looked-up invoice goes as text on `write`.
 *
 * The caller checks every photo against the job's house BEFORE asking (ZRCPT_Houses::gate()); nothing here sends a photo.
 *
 * @package Zorderz\Receipts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZRCPT_Writer {

	const CALLER       = 'receipts';   // this app's row in ZDZ_Core_LLM::CALLERS
	const WAIT_S       = 170;          // one call waits at most this long (a long PDF can take a while)
	const MAX_FACTS    = 24000;        // characters of facts sent
	const MAX_SECTIONS = 6;
	const MAX_ITEMS    = 10;
	const MAX_LINE     = 600;          // characters of one sentence kept
	const MAX_ADDRESS  = 160;

	/** What the router takes as a picture. */
	const ROUTER_IMAGES = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );

	/** Is the platform's Ai router here (with its customer-detail coder)? */
	public static function router_ready(): bool {
		return class_exists( 'ZDZ_Core_LLM' ) && method_exists( 'ZDZ_Core_LLM', 'ask' ) && class_exists( 'ZDZ_Private' );
	}

	/** Can a receipt be written: the router is here and the business has chosen a model for it. */
	public static function ready(): bool {
		if ( ! self::router_ready() ) {
			return false;
		}
		if ( method_exists( 'ZDZ_Core_LLM', 'models' ) ) {
			$m = (array) ZDZ_Core_LLM::models();
			return '' !== (string) ( $m['primary'] ?? '' );
		}
		return true;
	}

	/** "ready", or what it needs, in plain words. Never a key. */
	public static function ready_label(): string {
		if ( ! self::router_ready() ) {
			return __( 'not available: this site has no Ai router', 'zorderz' );
		}
		return self::ready() ? __( 'ready', 'zorderz' ) : __( 'needs an Ai model: an admin sets one on the Ai usage page', 'zorderz' );
	}

	/* ================================================================== the request */

	/**
	 * The invoice as a router part. Text (a looked-up invoice) -> a text part; a picture the router takes -> an image
	 * part; a PDF -> a pdf part. A HEIC picture that was not turned into a JPEG is refused in words. Null when there is
	 * no invoice.
	 *
	 * @param string|null $b64  the file, base64
	 * @param string      $mime its type as read on the site
	 * @return array|WP_Error|null
	 */
	public static function invoice_part( $b64, string $mime ) {
		if ( null === $b64 || '' === (string) $b64 ) {
			return null;
		}
		$mime = strtolower( trim( $mime ) );
		$data = preg_replace( '/\s+/', '', (string) $b64 );
		if ( 'text/plain' === $mime ) {
			$text = base64_decode( (string) $data, true );
			if ( false === $text || '' === trim( $text ) ) {
				return null;
			}
			return array( 'type' => 'text', 'text' => "The job document:\n" . self::cut_text( $text, self::MAX_FACTS ) );
		}
		if ( 'application/pdf' === $mime ) {
			return array( 'type' => 'pdf', 'data' => $data );
		}
		if ( 'image/jpg' === $mime ) {
			$mime = 'image/jpeg';
		}
		if ( in_array( $mime, self::ROUTER_IMAGES, true ) ) {
			return array( 'type' => 'image', 'media_type' => $mime, 'data' => $data );
		}
		if ( in_array( $mime, array( 'image/heic', 'image/heif' ), true ) ) {
			return new WP_Error( 'zrcpt_invoice_format', __( 'That invoice photo is in a format the Ai cannot read on this site. Share it as a JPEG or a PDF, or find the job by lookup instead.', 'zorderz' ) );
		}
		return new WP_Error( 'zrcpt_invoice_format', __( 'That invoice file cannot be read. Upload a PDF, JPEG, PNG or WebP, or find the job by lookup instead.', 'zorderz' ) );
	}

	/**
	 * The router request for one receipt: ['role' => …, 'request' => …]. $facts are the generator's plain words about the
	 * job (customer block, lines, notes, materials); the instructions are this app's own text (system_company).
	 */
	public static function request( string $facts, $invoice_part, int $photo_count ): array {
		$facts   = self::cut_text( $facts, self::MAX_FACTS );
		$photos  = $photo_count > 0
			/* translators: %d: photos */
			? sprintf( _n( 'The page shows %d photo of the finished work.', 'The page shows %d photos of the finished work.', $photo_count, 'zorderz' ), $photo_count )
			: __( 'The page shows no photos.', 'zorderz' );
		$content = array( array( 'type' => 'text', 'text' => $facts . "\n\n" . $photos . "\nReturn the JSON object now." ) );
		$role    = 'write';
		if ( is_array( $invoice_part ) ) {
			if ( 'text' === ( $invoice_part['type'] ?? '' ) ) {
				$content[] = $invoice_part;
			} else {
				$content[] = $invoice_part;
				$role      = 'read_document';
			}
		}
		return array(
			'role'    => $role,
			'request' => array(
				'system'         => self::system_prompt(),
				// The instructions are the business's own text: the one-word name rules leave them alone. The facts are
				// never marked: they carry the customer's details.
				'system_company' => true,
				'messages'       => array( array( 'role' => 'user', 'content' => $content ) ),
				'json_schema'    => self::schema(),
			),
		);
	}

	/** This app's instructions to the model (the same on every site). */
	public static function system_prompt(): string {
		$marker = defined( 'ZRCPT_COUNT_MARKER' ) ? ZRCPT_COUNT_MARKER : 'ZDZ_RCPT_COUNT';
		return implode( "\n", array(
			'You write the words of a completed-work receipt: a short document a business gives its customer to show what was done at their property. The page around your words (letterhead, photos, invoice panel) is made elsewhere.',
			'',
			'Use only the facts in the message: the job document and its lines, the notes, the materials block and any line marked as authoritative. Never add a product, material, specification, count, date or claim the facts do not state. When the facts are thin, write less.',
			'',
			'Answer with one JSON object in the given shape:',
			'- address_short: the street line of the job site (number and street), as the facts give it. Empty only when the facts give no street.',
			'- headline: one sentence saying what was completed. When the facts state a count (a line "' . $marker . ': N", or a UNIT COUNT line), use exactly that number with its unit word.',
			'- unit_summary: that count and its unit word in a few words, or an empty string when the facts state no count.',
			'- sections: at most ' . self::MAX_SECTIONS . ' sections, each a short heading and at most ' . self::MAX_ITEMS . ' short items: what was done, the materials and specifications the facts state, and notes useful to the customer.',
			'',
			'Never write a price, an amount, a payment state, an invoice or document number, a link, a phone number, an e-mail address or a staff member\'s name: the page adds the invoice panel itself.',
			'Customer details may appear as codes like ⟦c:12⟧ or ⟦e:abcd1⟧. Copy a code exactly where that detail belongs (for example in address_short); never invent, change or explain one.',
			'Plain text only: no HTML, no Markdown. Write plain, friendly sentences in the language of the facts.',
		) );
	}

	/** The answer's shape (field names only: nothing in it is a customer detail). */
	public static function schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'address_short' => array( 'type' => 'string' ),
				'headline'      => array( 'type' => 'string' ),
				'unit_summary'  => array( 'type' => 'string' ),
				'sections'      => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'heading' => array( 'type' => 'string' ),
							'items'   => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						),
						'required'             => array( 'heading', 'items' ),
						'additionalProperties' => false,
					),
				),
			),
			'required'             => array( 'address_short', 'headline', 'unit_summary', 'sections' ),
			'additionalProperties' => false,
		);
	}

	/* ================================================================== the answer */

	/**
	 * The model's words, decoded and bounded: { address_short, headline, unit_summary, sections: [ {heading, items} ] }.
	 * $decode turns the router's codes back into the customer's details. A street the model left empty is taken from
	 * $fallback_address (the looked-up job's first address line); with neither the answer is refused.
	 *
	 * @param mixed    $json
	 * @param callable $decode string => string
	 * @return array|WP_Error
	 */
	public static function words( $json, callable $decode, string $fallback_address = '' ) {
		if ( ! is_array( $json ) ) {
			return new WP_Error( 'zrcpt_writer_shape', __( 'The receipt writer answered in the wrong shape. Try again.', 'zorderz' ) );
		}
		$line = static function ( $s, int $max ) use ( $decode ): string {
			if ( ! is_string( $s ) ) {
				return '';
			}
			$s = (string) call_user_func( $decode, $s );
			$s = trim( (string) preg_replace( '/\s+/u', ' ', self::plain( $s ) ) );
			return self::cut_text( $s, $max );
		};
		$address = $line( $json['address_short'] ?? '', self::MAX_ADDRESS );
		if ( '' === $address ) {
			$address = self::cut_text( trim( (string) strtok( $fallback_address, "\n," ) ), self::MAX_ADDRESS );
		}
		if ( '' === $address ) {
			return new WP_Error( 'zrcpt_writer_address', __( 'The receipt writer found no street for this job. Check the job\'s address, then try again.', 'zorderz' ) );
		}
		$out = array(
			'address_short' => $address,
			'headline'      => $line( $json['headline'] ?? '', self::MAX_LINE ),
			'unit_summary'  => $line( $json['unit_summary'] ?? '', 80 ),
			'sections'      => array(),
		);
		foreach ( array_slice( is_array( $json['sections'] ?? null ) ? $json['sections'] : array(), 0, self::MAX_SECTIONS ) as $sec ) {
			if ( ! is_array( $sec ) ) {
				continue;
			}
			$items = array();
			foreach ( array_slice( is_array( $sec['items'] ?? null ) ? $sec['items'] : array(), 0, self::MAX_ITEMS ) as $it ) {
				$t = $line( $it, self::MAX_LINE );
				if ( '' !== $t ) {
					$items[] = $t;
				}
			}
			$heading = $line( $sec['heading'] ?? '', 120 );
			if ( $items || '' !== $heading ) {
				$out['sections'][] = array( 'heading' => $heading, 'items' => $items );
			}
		}
		if ( '' === $out['headline'] && ! $out['sections'] ) {
			return new WP_Error( 'zrcpt_writer_empty', __( 'The receipt writer gave no words for this job. Try again.', 'zorderz' ) );
		}
		return $out;
	}

	/* ================================================================== the page */

	/**
	 * The receipt page around the words. $ctx: heading (the receipt mode's document heading), install_date (as the
	 * generator formatted it), photo_urls (checked, in order), invoice_links (number => customer-facing link; drawn only
	 * for a receipt covering several invoices: the invoice panel carries a single one), closing_line.
	 * Every word is escaped; every link and photo address is checked as a web address.
	 */
	public static function page( array $w, array $ctx ): string {
		$heading = trim( (string) ( $ctx['heading'] ?? '' ) );
		$heading = '' !== $heading ? $heading : __( 'Completed Work', 'zorderz' );
		$address = (string) ( $w['address_short'] ?? '' );
		$date    = trim( (string) ( $ctx['install_date'] ?? '' ) );

		$h   = array();
		$h[] = '<!DOCTYPE html>';
		$h[] = '<html lang="' . self::attr( (string) ( $ctx['lang'] ?? 'en' ) ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
		$h[] = '<title>' . self::html( $heading . ( '' !== $address ? ' — ' . $address : '' ) ) . '</title>';
		$h[] = '<style>body{margin:0;background:#f4f5f7;color:#1d2327;font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}'
			. '.zrcpt-page{max-width:760px;margin:0 auto;background:#fff}.zrcpt-body{padding:20px 28px 28px}'
			. '.gallery{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:8px}'
			. '.gallery-item img{width:100%;height:150px;object-fit:cover;border-radius:6px;display:block;cursor:zoom-in}'
			. '.gallery-note{color:#50575e;font-size:13px;margin-top:6px}'
			. '@media print{body{background:#fff}.zrcpt-print{display:none}}</style>';
		$h[] = '</head><body><main class="zrcpt-page">';
		$h[] = function_exists( 'zrcpt_render_letterhead' )
			? (string) zrcpt_render_letterhead( array( 'heading' => $heading, 'subheading' => $address ) )
			: '<header class="zrcpt-letterhead" style="padding:24px 28px;"><h1 style="margin:0;font-size:22px;">' . self::html( $heading ) . '</h1></header>';
		$h[] = '<div class="zrcpt-body">';
		$facts = array();
		if ( '' !== $address ) {
			$facts[] = '<p class="zrcpt-address" style="margin:0;font-weight:600;">' . self::html( $address ) . '</p>';
		}
		if ( '' !== $date ) {
			/* translators: %s: the date the work was done */
			$facts[] = '<p class="zrcpt-date" style="margin:0;color:#50575e;">' . self::html( sprintf( __( 'Work completed: %s', 'zorderz' ), $date ) ) . '</p>';
		}
		if ( $facts ) {
			$h[] = '<section class="zrcpt-facts" style="margin:0 0 16px;">' . implode( '', $facts ) . '</section>';
		}
		if ( '' !== (string) ( $w['headline'] ?? '' ) ) {
			$h[] = '<p class="zrcpt-headline" style="font-size:18px;margin:0 0 16px;">' . self::html( (string) $w['headline'] ) . '</p>';
		}
		foreach ( (array) ( $w['sections'] ?? array() ) as $sec ) {
			$part = '<section class="zrcpt-section" style="margin:0 0 16px;">';
			if ( '' !== (string) ( $sec['heading'] ?? '' ) ) {
				$part .= '<h2 style="font-size:17px;margin:0 0 6px;">' . self::html( (string) $sec['heading'] ) . '</h2>';
			}
			if ( ! empty( $sec['items'] ) ) {
				$part .= '<ul style="margin:0;padding-left:20px;">';
				foreach ( (array) $sec['items'] as $it ) {
					$part .= '<li>' . self::html( (string) $it ) . '</li>';
				}
				$part .= '</ul>';
			}
			$h[] = $part . '</section>';
		}

		$links = array();
		foreach ( (array) ( $ctx['invoice_links'] ?? array() ) as $num => $url ) {
			$url = trim( (string) $url );
			if ( self::web_address( $url ) ) {
				/* translators: %s: invoice number */
				$links[] = '<li><a href="' . self::attr( $url ) . '" target="_blank" rel="noopener nofollow">' . self::html( sprintf( __( 'View invoice #%s', 'zorderz' ), (string) $num ) ) . '</a></li>';
			}
		}
		if ( count( $links ) > 1 ) {
			$h[] = '<section class="zrcpt-invoices" style="margin:0 0 16px;"><h2 style="font-size:17px;margin:0 0 6px;">' . self::html( __( 'Invoices', 'zorderz' ) ) . '</h2><ul style="margin:0;padding-left:20px;">' . implode( '', $links ) . '</ul></section>';
		}

		$items = '';
		$n     = 0;
		foreach ( (array) ( $ctx['photo_urls'] ?? array() ) as $url ) {
			$url = trim( (string) $url );
			if ( ! self::web_address( $url ) ) {
				continue;
			}
			$n++;
			/* translators: %d: photo number */
			$items .= "\n" . '            <div class="gallery-item"><img src="' . self::attr( $url ) . '" alt="' . self::attr( sprintf( __( 'Photo %d', 'zorderz' ), $n ) ) . '" loading="eager"></div>';
		}
		if ( $n > 0 ) {
			$h[] = '<section class="zrcpt-photos" style="margin:0 0 16px;"><h2 style="font-size:17px;margin:0 0 8px;">' . self::html( __( 'Photos of the work', 'zorderz' ) ) . '</h2>';
			$h[] = '<div class="gallery">' . $items . "\n" . '        </div>';
			// The note's opening tag is exactly this: photo remove/reorder finds the gallery by it.
			$h[] = '<div class="gallery-note">' . self::html( __( 'Tap a photo to see it larger.', 'zorderz' ) ) . '</div></section>';
		}
		$closing = trim( (string) ( $ctx['closing_line'] ?? '' ) );
		if ( '' !== $closing ) {
			$h[] = '<p class="zrcpt-closing" style="margin:16px 0 0;font-weight:600;">' . self::html( $closing ) . '</p>';
		}
		$h[] = '<p class="zrcpt-print" style="margin:20px 0 0;"><a href="#" data-zrcpt-print="1">' . self::html( __( 'Print this page', 'zorderz' ) ) . '</a></p>';
		$h[] = '</div></main></body></html>';
		return implode( "\n", $h );
	}

	/**
	 * The receipt record the generator stores: address_short, vanity_slug, install_date, vent_summary (the unit
	 * summary, under the key earlier receipts used), html_base64.
	 */
	public static function receipt( array $w, array $ctx ): array {
		$slug = function_exists( 'sanitize_title' ) ? sanitize_title( (string) $w['address_short'] ) : strtolower( trim( (string) preg_replace( '/[^A-Za-z0-9]+/', '-', (string) $w['address_short'] ), '-' ) );
		return array(
			'address_short' => (string) $w['address_short'],
			'vanity_slug'   => '' !== $slug ? $slug : 'receipt',
			'install_date'  => (string) ( $ctx['install_date'] ?? '' ),
			'vent_summary'  => (string) ( $w['unit_summary'] ?? '' ),
			'html_base64'   => base64_encode( self::page( $w, $ctx ) ),
		);
	}

	/* ================================================================== the call */

	/**
	 * Ask the router for the words and build the receipt record. $ctx: as page(), plus fallback_address. Never sends a
	 * photo. Returns the record or a WP_Error said in plain words.
	 *
	 * @return array|WP_Error
	 */
	public static function write( string $facts, $invoice_part, array $ctx ) {
		if ( ! self::router_ready() ) {
			return new WP_Error( 'zrcpt_writer_setup', __( 'Receipts cannot be written on this site: it has no Ai router.', 'zorderz' ) );
		}
		if ( is_wp_error( $invoice_part ) ) {
			return $invoice_part;
		}
		$uid = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( class_exists( 'ZDZ_Privacy_View' ) && method_exists( 'ZDZ_Privacy_View', 'is_on' ) && ZDZ_Privacy_View::is_on( $uid ) ) {
			// The receipt carries the customer's real details; an account in Privacy View may not see them.
			return new WP_Error( 'zrcpt_writer_privacy', __( 'Receipts cannot be written from this account.', 'zorderz' ) );
		}
		$req = self::request( $facts, $invoice_part, count( (array) ( $ctx['photo_urls'] ?? array() ) ) );
		try {
			$r = ZDZ_Core_LLM::ask( $req['role'], $req['request'], array(
				'caller'   => self::CALLER,
				'user_id'  => $uid,
				'deadline' => time() + self::WAIT_S,
			) );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'zrcpt_writer_failed', __( 'The Ai isn\'t reachable just now. Try again in a moment.', 'zorderz' ) );
		}
		if ( is_wp_error( $r ) ) {
			return new WP_Error( 'zrcpt_writer_failed', (string) $r->get_error_message() );
		}
		$coder = ZDZ_Private::for( 'llm' );
		$words = self::words( is_array( $r ) ? ( $r['json'] ?? null ) : null, static function ( $s ) use ( $coder ) {
			return (string) $coder->decode( (string) $s, true ); // the customer's own receipt
		}, (string) ( $ctx['fallback_address'] ?? '' ) );
		if ( is_wp_error( $words ) ) {
			return $words;
		}
		return self::receipt( $words, $ctx );
	}

	/* ================================================================== helpers */

	private static function cut_text( string $s, int $max ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_strlen( $s ) > $max ? rtrim( mb_substr( $s, 0, $max - 1 ) ) . '…' : $s;
		}
		return strlen( $s ) > $max ? rtrim( substr( $s, 0, $max - 3 ) ) . '...' : $s;
	}

	/** An https or http address (a page or a photo), nothing else. */
	private static function web_address( string $url ): bool {
		return '' !== $url && (bool) preg_match( '#^https?://[^\s"<>]+$#i', $url );
	}

	/** A model's sentence as plain text (it is told to write plain text; a tag is dropped, never shown as markup). */
	private static function plain( string $s ): string {
		return function_exists( 'wp_strip_all_tags' ) ? (string) wp_strip_all_tags( $s ) : trim( strip_tags( $s ) );
	}

	private static function html( string $s ): string {
		return htmlspecialchars( $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}

	private static function attr( string $s ): string {
		return htmlspecialchars( $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
