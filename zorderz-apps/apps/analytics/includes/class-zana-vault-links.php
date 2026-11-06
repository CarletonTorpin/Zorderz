<?php
/**
 * ZANA_Vault_Links — every knowledge-library link in a chat answer is real, full and clickable.
 *
 * The chat's instructions tell the model that a document is cited as [VAULT-<id>]; a model also writes a bare
 * "<site>/vault/<slug>" or a raw link by hand. Without this, a citation stays a bracketed token, an address with no
 * "https://" is plain text, and nothing checks that the address names a real document the reader may open. Applied to
 * every answer before it is saved:
 *   - [VAULT-<id>]                               -> [Document title](https://…/vault/<slug>)
 *   - a markdown or HTML link to /vault/<slug>   -> the same, with the canonical address
 *   - a bare "<this site>/vault/<slug>" (with or without https://, on this site's host or the business's app domain)
 *                                                -> a link
 *   - an address that names no document the READER can see -> the link is removed and the text says so (a document the
 *     reader may not see reads exactly like one that does not exist: nothing is disclosed)
 *   - after a direct-message or channel-post draft card ([ZANA_DM_DRAFT:@login] / [ZANA_POST_DRAFT:#channel]): a line
 *     AFTER the card (never inside the message) when the recipient cannot open a document it links to.
 * A slug the model built from a title also resolves when the library added "-<id>" to keep it unique. Nothing else is
 * guessed. A Private document is never linked, and code blocks are left exactly as written.
 *
 * Reads the knowledge app's own access rule (ZKV_ACL::sql_where_view) for the reader; without the knowledge app the
 * answer is returned exactly as it came.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class ZANA_Vault_Links {

	/**
	 * @var callable|null tests: function( string $kind, string $key, int $uid )
	 *   'id' / 'slug' -> ?array (one document); 'slug_like' -> array of documents whose slug starts "<key>-".
	 *   Only documents that user may see. (Never called with $uid <= 0.)
	 */
	public static $resolver = null;

	/** @var callable|null tests: function( int $uid, array $doc ) : string  ('' = can open, else the reason) */
	public static $access = null;

	/** @var callable|null tests: function( string $who ) : ?array  ('@login' or '#channel' -> array( 'uid' => int, 'name' => string, 'kind' => 'dm'|'post' )) */
	public static $recipient = null;

	/** @var string|null tests: the site's base URL (default home_url()) */
	public static $base = null;

	/** Is there a Vault to check against? (the knowledge app, or a test resolver.) */
	public static function available() {
		return null !== self::$resolver || ( class_exists( 'ZKV_ACL' ) && method_exists( 'ZKV_ACL', 'sql_where_view' ) );
	}

	/**
	 * Normalise every vault link in $text for a viewer.
	 *
	 * @param string $text   model answer (markdown, possibly with HTML)
	 * @param int    $uid    the person reading the answer
	 * @return array{text:string,linked:int,removed:int,warned:int}
	 */
	public static function apply( $text, $uid ) {
		$text = (string) $text;
		$out  = array( 'text' => $text, 'linked' => 0, 'removed' => 0, 'warned' => 0 );
		// No reader -> nothing is looked up (never "unscoped"), and the
		// answer is returned exactly as it came.
		if ( '' === $text || (int) $uid <= 0 || ! self::available() || ! preg_match( '/vault/i', $text ) ) {
			return $out;
		}
		// Code fences are left exactly as written.
		$parts = preg_split( '/(```[\s\S]*?```)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $parts ) ) {
			return $out;
		}
		$work = $out;
		foreach ( $parts as $i => $part ) {
			if ( 0 === strpos( $part, '```' ) ) { continue; }
			$seg = self::normalize_segment( $part, (int) $uid, $work );
			if ( null === $seg ) {
				return $out; // a regex failed (e.g. invalid UTF-8): keep the answer as it was, never blank it
			}
			$parts[ $i ] = $seg;
		}
		$text2 = self::warn_recipients( implode( '', $parts ), (int) $uid, $work );
		if ( ! is_string( $text2 ) ) {
			return $out;
		}
		$work['text'] = $text2;
		return $work;
	}

	/** preg_replace_callback that never turns a failure into an empty answer. */
	private static function rx( $re, $cb, $subject ) {
		if ( null === $subject ) { return null; }
		$r = preg_replace_callback( $re, $cb, $subject );
		return is_string( $r ) ? $r : null;
	}

	/** Tests hand in the hosts (array of host names). */
	public static $hosts = null;

	/**
	 * The host names a vault link may use: this site's, and the business's app domain (Business Profile
	 * `web.app_domain`), each with or without www. A dot is matched with or without a backslash before it, so an address
	 * written inside a pattern ("example\.com") is still recognised.
	 */
	public static function hosts(): array {
		$list = is_array( self::$hosts ) ? self::$hosts : array(
			(string) wp_parse_url( self::base(), PHP_URL_HOST ),
			class_exists( 'ZDZ_Business_Profile' ) ? (string) ZDZ_Business_Profile::get( 'web.app_domain', '' ) : '',
		);
		$out = array();
		foreach ( $list as $h ) {
			$h = strtolower( trim( (string) $h ) );
			$h = (string) preg_replace( '#^[a-z][a-z0-9+.-]*://#', '', $h );
			$h = (string) preg_replace( '#[/:].*$#', '', $h );
			$h = (string) preg_replace( '/^www\./', '', $h );
			if ( '' !== $h && preg_match( '/^[a-z0-9.-]{1,253}$/', $h ) && ! in_array( $h, $out, true ) ) {
				$out[] = $h;
			}
		}
		return $out;
	}

	private static function host_re() {
		$alts = array();
		foreach ( self::hosts() as $h ) {
			$alts[] = implode( '\\\\?\\.', array_map( function ( $p ) { return preg_quote( $p, '#' ); }, explode( '.', $h ) ) );
		}
		if ( ! $alts ) {
			$alts[] = '(?!)';
		}
		return '(?:https?://)?(?:www\\\\?\\.)?(?:' . implode( '|', $alts ) . ')';
	}

	private static function base() {
		return null !== self::$base ? rtrim( self::$base, '/' ) : rtrim( home_url( '/' ), '/' );
	}

	/** Canonical URL of a document. */
	public static function url_for( array $doc ) {
		return self::base() . '/vault/' . $doc['slug'];
	}

	/**
	 * The vault document slug in a URL, or '' (also '' for /vault/shared, /vault/excerpt/…,
	 * ?raw= file links and other hosts).
	 */
	public static function slug_in( $url ) {
		$url = trim( (string) $url );
		if ( ! preg_match( '#^' . self::host_re() . '/vault/([a-z0-9][a-z0-9-]*)/?(?:[?\#].*)?$#i', $url, $m )
			&& ! preg_match( '#^/vault/([a-z0-9][a-z0-9-]*)/?(?:[?\#].*)?$#i', $url, $m ) ) {
			return '';
		}
		$slug = strtolower( $m[1] );
		if ( in_array( $slug, array( 'shared', 'excerpt' ), true ) || preg_match( '/[?&]raw=/', $url ) ) {
			return '';
		}
		return $slug;
	}

	/** A document the viewer may see, by slug (exact, else the one "<slug>-<id>" the Vault made unique). */
	public static function doc_by_slug( $slug, $uid ) {
		$slug = strtolower( (string) $slug );
		if ( '' === $slug ) { return null; }
		$doc = self::resolve( 'slug', $slug, $uid );
		if ( $doc ) { return $doc; }
		// The Vault appends "-<id>" when two titles make the same slug (the knowledge app's slug rule),
		// so a slug the model built from a title may name exactly one "<slug>-<id>".
		$hits = array();
		foreach ( (array) self::resolve( 'slug_like', $slug, $uid ) as $r ) {
			if ( is_array( $r ) && preg_match( '/^' . preg_quote( $slug, '/' ) . '-\d+$/', (string) ( $r['slug'] ?? '' ) ) ) {
				$hits[] = $r;
			}
		}
		return 1 === count( $hits ) ? $hits[0] : null;
	}

	public static function doc_by_id( $id, $uid ) {
		$id = (int) $id;
		return $id > 0 ? ( self::resolve( 'id', (string) $id, $uid ) ?: null ) : null;
	}

	private static function resolve( $kind, $key, $uid ) {
		if ( (int) $uid <= 0 ) {
			return 'slug_like' === $kind ? array() : null; // no reader: never an unscoped lookup
		}
		if ( null !== self::$resolver ) {
			return call_user_func( self::$resolver, $kind, $key, (int) $uid );
		}
		global $wpdb;
		$t   = $wpdb->prefix . 'zkv_documents';
		$acl = ZKV_ACL::sql_where_view( (int) $uid, 'd' ) . self::not_private_sql();
		$cols = 'd.id, d.slug, d.title, d.visibility';
		if ( 'id' === $kind ) {
			return $wpdb->get_row( $wpdb->prepare( "SELECT {$cols} FROM {$t} d WHERE d.id = %d{$acl}", (int) $key ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table and columns; $acl is ZKV_ACL's fragment of constants and int-cast ids
		}
		if ( 'slug_like' === $kind ) {
			return (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT {$cols} FROM {$t} d WHERE d.slug LIKE %s{$acl} LIMIT 5", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table and columns; $acl is ZKV_ACL's fragment of constants and int-cast ids
				$wpdb->esc_like( $key ) . '-%'
			), ARRAY_A );
		}
		return $wpdb->get_row( $wpdb->prepare( "SELECT {$cols} FROM {$t} d WHERE d.slug = %s{$acl}", $key ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table and columns; $acl is ZKV_ACL's fragment of constants and int-cast ids
	}

	/**
	 * A Private document is never linked in a chat answer: its title in "[title](link)" would be stored with the answer
	 * and sent back to the model as history, and its slug is built from the title too. So it resolves like a document
	 * that is not there.
	 */
	public static function not_private_sql() {
		return ' AND d.is_private = 0';
	}

	private static function title_of( array $doc ) {
		$t = trim( (string) ( $doc['title'] ?? '' ) );
		return '' !== $t ? $t : __( 'Library document', 'zorderz' );
	}

	/** Markdown link text: no brackets or newlines. */
	private static function md_label( $s ) {
		$s = trim( preg_replace( '/\s+/', ' ', str_replace( array( '[', ']' ), array( '(', ')' ), (string) $s ) ) );
		return '' !== $s ? $s : __( 'Library document', 'zorderz' );
	}

	private static function link( array $doc, $label ) {
		return '[' . self::md_label( $label ) . '](' . self::url_for( $doc ) . ')';
	}

	/** Is this link text just an address / a [VAULT-n] token (so the title should replace it)? */
	private static function label_is_address( $label ) {
		$l = trim( strip_tags( (string) $label ) );
		if ( '' === $l || preg_match( '#^(?:https?://|www\.|/vault/|VAULT-\d+$)#i', $l ) ) {
			return true;
		}
		foreach ( self::hosts() as $h ) {
			if ( 0 === stripos( $l, $h ) ) {
				return true;
			}
		}
		return false;
	}

	private static function normalize_segment( $text, $uid, array &$out ) {
		$keep  = array();
		$stash = function ( $s ) use ( &$keep ) {
			$keep[] = $s;
			return "\x01" . ( count( $keep ) - 1 ) . "\x02";
		};
		$host = self::host_re();

		// 1. HTML links.
		$text = self::rx( '#<a\s[^>]*?href\s*=\s*["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', function ( $m ) use ( $uid, &$out, $stash ) {
			$slug = self::slug_in( html_entity_decode( $m[1], ENT_QUOTES ) );
			if ( '' === $slug ) { return $stash( $m[0] ); }
			$doc   = self::doc_by_slug( $slug, $uid );
			$label = trim( strip_tags( $m[2] ) );
			if ( ! $doc ) {
				$out['removed']++;
				return $stash( ( self::label_is_address( $label ) ? '' : $label . ' ' ) . __( '(not found in the library)', 'zorderz' ) );
			}
			$out['linked']++;
			return $stash( self::link( $doc, self::label_is_address( $label ) ? self::title_of( $doc ) : $label ) );
		}, $text );

		// 2. Markdown links [label](url).
		$text = self::rx( '#\[([^\]\n]{1,300})\]\(\s*<?([^)\s>]+)>?\s*\)#', function ( $m ) use ( $uid, &$out, $stash ) {
			$slug = self::slug_in( $m[2] );
			if ( '' === $slug ) { return $stash( $m[0] ); }
			$doc = self::doc_by_slug( $slug, $uid );
			if ( ! $doc ) {
				$out['removed']++;
				return $stash( ( self::label_is_address( $m[1] ) ? '' : $m[1] . ' ' ) . __( '(not found in the library)', 'zorderz' ) );
			}
			$out['linked']++;
			return $stash( self::link( $doc, self::label_is_address( $m[1] ) ? self::title_of( $doc ) : $m[1] ) );
		}, $text );

		// 3. [VAULT-<id>] tokens.
		$text = self::rx( '#\[VAULT-(\d{1,9})\](?!\()#i', function ( $m ) use ( $uid, &$out, $stash ) {
			$doc = self::doc_by_id( $m[1], $uid );
			if ( ! $doc ) {
				$out['removed']++;
				return $stash( __( '(that library document was not found)', 'zorderz' ) );
			}
			$out['linked']++;
			return $stash( self::link( $doc, self::title_of( $doc ) ) );
		}, $text );

		// 4. Bare addresses, with or without https://.
		$text = self::rx( '#(?<![\w/@.\-])(' . $host . '/vault/([a-z0-9][a-z0-9-]*))/?(?![a-z0-9/\-])(\?[A-Za-z0-9_]+=[^\s)\]]*|\#[A-Za-z0-9_\-]+)?#i', function ( $m ) use ( $uid, &$out, $stash ) {
			$slug = strtolower( $m[2] );
			if ( in_array( $slug, array( 'shared', 'excerpt' ), true ) || ! empty( $m[3] ) ) {
				return $stash( $m[0] ); // a page, or a ?raw= / #… address: left exactly as written
			}
			$doc = self::doc_by_slug( $slug, $uid );
			if ( ! $doc ) {
				$out['removed']++;
				return $stash( __( '(no library document at that address)', 'zorderz' ) );
			}
			$out['linked']++;
			return $stash( self::link( $doc, self::title_of( $doc ) ) );
		}, $text );

		// A line that now reads "Title: [Title](url)" says the title twice — keep the link's.
		$text = self::rx( "/\x01(\d+)\x02/", function ( $m ) use ( &$keep ) {
			return $keep[ (int) $m[1] ];
		}, $text );
		if ( null === $text ) {
			return null;
		}
		// Only on valid UTF-8 (this pattern needs /u; on anything else it would fail).
		if ( ! preg_match( '//u', $text ) ) {
			return $text;
		}
		$text = self::rx( '/(^|\n)([ \t]*(?:[-*\x{2022}]\s*)?)([^\n\[]{2,200}?)\s*[:\x{2014}\x{2013}-]\s*(\[([^\]\n]+)\]\(https?:[^)\s]+\/vault\/[a-z0-9-]+\))/u', function ( $m ) {
			$before = rtrim( $m[3] );
			if ( strcasecmp( $before, trim( $m[5] ) ) === 0 ) {
				return $m[1] . $m[2] . $m[4];
			}
			return $m[0];
		}, $text );
		return $text;
	}

	/**
	 * After each DM / channel-post draft card, say so when the recipient cannot open
	 * a document the draft links to. The note is outside the card, so it is never sent.
	 */
	private static function warn_recipients( $text, $uid, array &$out ) {
		// Only links to THIS site, and only documents the READER may see —
		// the note can never name a document the reader could not open themselves.
		return self::rx( '#(\[ZANA_(DM|POST)_DRAFT:([@\#][A-Za-z0-9_.@\-]+)\])([\s\S]*?)(\[/ZANA_\2_DRAFT\])#i', function ( $m ) use ( &$out, $uid ) {
			if ( ! preg_match_all( '#\]\((https?://[^)\s]+/vault/([a-z0-9][a-z0-9-]*))\)#i', $m[4], $links, PREG_SET_ORDER ) ) {
				return $m[0];
			}
			$who = self::recipient( $m[3] );
			if ( ! $who ) {
				return $m[0];
			}
			$notes = array();
			$seen  = array();
			foreach ( $links as $l ) {
				$slug = self::slug_in( $l[1] );
				if ( '' === $slug || isset( $seen[ $slug ] ) ) { continue; }
				$seen[ $slug ] = true;
				$doc = self::doc_by_slug( $slug, (int) $uid );
				if ( ! $doc ) { continue; }
				$why = self::cannot_open( $who, $doc );
				if ( '' !== $why ) {
					$notes[] = '"' . self::title_of( $doc ) . '" ' . $why;
				}
			}
			if ( ! $notes ) {
				return $m[0];
			}
			$out['warned'] += count( $notes );
			$name = $who['name'];
			/* translators: 1: who (a person, or "some people in #channel"), 2: the documents and why */
			$line = "\n\n\xE2\x9A\xA0\xEF\xB8\x8F " . sprintf( __( 'Before you send: %1$s won\'t be able to open %2$s.', 'zorderz' ),
				'post' === $who['kind'] ? sprintf( __( 'some people in %s', 'zorderz' ), $name ) : $name, implode( '; ', $notes ) );
			return $m[0] . $line;
		}, $text );
	}

	/** '@login' or '#channel' -> recipient record, or null. */
	private static function recipient( $who ) {
		if ( null !== self::$recipient ) {
			return call_user_func( self::$recipient, $who );
		}
		if ( '#' === $who[0] ) {
			return array( 'uid' => 0, 'name' => $who, 'kind' => 'post' );
		}
		$login = ltrim( $who, '@' );
		$u = get_user_by( 'login', $login );
		if ( ! $u && is_email( $login ) ) { $u = get_user_by( 'email', $login ); }
		if ( ! $u ) { $u = get_user_by( 'slug', sanitize_title( $login ) ); }
		if ( ! $u ) { return null; }
		$name = trim( $u->first_name . ' ' . $u->last_name );
		return array( 'uid' => (int) $u->ID, 'name' => '' !== $name ? $name : $u->display_name, 'kind' => 'dm' );
	}

	/** '' when the recipient can open the document, else why not (wording follows "won't be able to open X"). */
	private static function cannot_open( array $who, array $doc ) {
		if ( null !== self::$access ) {
			return (string) call_user_func( self::$access, $who, $doc );
		}
		$vis = (string) ( $doc['visibility'] ?? '' );
		if ( 'post' === $who['kind'] ) {
			if ( 'admin_only' === $vis ) { return __( '(it is admin-only in the library)', 'zorderz' ); }
			if ( 'transcript_private' === $vis ) { return __( '(it is a private transcript)', 'zorderz' ); }
			return '';
		}
		$uid = (int) $who['uid'];
		$is_transcript = ( 'transcript_private' === $vis );
		if ( ! $is_transcript && function_exists( 'zkv_user_has_access' ) && ! zkv_user_has_access( $uid ) ) {
			return __( '(they do not have the Knowledge app)', 'zorderz' );
		}
		if ( ! self::doc_by_id( (int) $doc['id'], $uid ) ) {
			return $is_transcript ? __( '(it is a private transcript they are not part of)', 'zorderz' ) : __( '(it is admin-only in the library)', 'zorderz' );
		}
		return '';
	}
}
