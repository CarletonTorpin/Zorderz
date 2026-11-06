<?php
/**
 * ZTER_Kinds — what an estimate's lines are: a Team Roles ITEM (a catalog item) and whether it is
 * NEW work or a REPAIR.
 *
 * Who can do it is then a Team Roles CELL: Sell for a new stub, Install (new) or Repair (repair) for
 * a won job (ZTER_Rules). Each line goes through:
 *
 *   1. The Jobs app's kinds of project for the line (ZJOB_Project_Types::types_for_line, from the
 *      catalog items it names), when the Jobs app is here.
 *   2. This app's WORDS TABLE, edited on the page, first matching row wins. A row names a kind of
 *      project, some words, or both, optionally only for repairs or only for new work, and the item
 *      it means. Core ships it empty: it is for what the catalog alone can't tell apart.
 *   3. Else the catalog: the item whose names and aliases the line mentions (ZDZ_Item_Engine::match),
 *      when Team Roles tracks it.
 *   4. The work: a repair when the line says so (repair, fix, broken, service … plus the business's
 *      own words, `zter_repair_words`); new when it says new or install.
 *   A repair that names no item ("Repair") is a GENERIC repair: the item is unknown and the rules
 *   can send it to whoever holds the ZIP.
 *
 * Nothing about the customer is kept: the notes say which TABLE ROW (or the catalog) decided each line.
 *
 * @package Zorderz\Territories
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZTER_Kinds {

	const OPTION    = 'zter_words';
	const MAX_ROWS  = 60;
	const MAX_WORDS = 16;
	const MAX_LINES = 60;
	const REPAIR    = array( 'any', 'yes', 'no' );

	/** Words that say a line is new work. */
	const NEW_WORDS = '/\bnew\b|\binstall(s|ed|ing|ation)?\b/i';

	/** Core's words that make a line a repair; a business adds its own (zter_repair_words). */
	const REPAIR_WORDS = '/\brepair(s|ed|ing)?\b|\bfix(ed|ing)?\b|\btorn\b|\bbroken\b|\bbent\b|\bpatch(ed|ing)?\b|\bservice\b/i';

	/** Core's words that start a line that never says what the work is (notes, the location line). */
	const ADMIN_LINES = array( 'location', 'property location', 'note', 'notes', 'memo', 'appointment summary' );

	private static $items_memo = null;
	private static $extra_repair = null;

	/** Forget the per-request copies (after Team Roles changes in the same request; tests). */
	public static function flush() {
		self::$items_memo   = null;
		self::$extra_repair = null;
	}

	/** The kinds of project a words-table row may name (the Jobs app's list, without its repair kind). */
	public static function types() {
		if ( ! class_exists( 'ZJOB_Project_Types' ) || ! method_exists( 'ZJOB_Project_Types', 'types' ) ) {
			return array();
		}
		$t = (array) ZJOB_Project_Types::types();
		$r = method_exists( 'ZJOB_Project_Types', 'repair_type' ) ? ZJOB_Project_Types::repair_type() : '';
		unset( $t[ $r ] );
		return $t;
	}

	/** The business's own repair words (rules.yml), as one regex, or ''. */
	private static function extra_repair() {
		if ( null === self::$extra_repair ) {
			$w = get_option( 'zter_repair_words', array() );
			$w = apply_filters( 'zter_repair_words', is_array( $w ) ? $w : array() );
			$q = array();
			foreach ( is_array( $w ) ? $w : array() as $x ) {
				$x = strtolower( trim( is_scalar( $x ) ? (string) $x : '' ) );
				if ( '' !== $x && strlen( $x ) <= 40 ) {
					$q[] = preg_quote( $x, '/' );
				}
			}
			self::$extra_repair = $q ? '/(?<![a-z0-9])(' . implode( '|', $q ) . ')s?(?![a-z0-9])/i' : '';
		}
		return self::$extra_repair;
	}

	/** Does this text say the work is a repair? */
	public static function says_repair( $text ) {
		$text = (string) $text;
		if ( preg_match( self::REPAIR_WORDS, $text ) ) {
			return true;
		}
		$x = self::extra_repair();
		return '' !== $x && (bool) preg_match( $x, $text );
	}

	/**
	 * Team Roles' record of where each older kind of work went: kind key => [item ids]. Empty when
	 * Team Roles is not active.
	 */
	public static function legacy_map() {
		$list = apply_filters( 'zdz_abilities', null, array( 'retired' => true ) );
		$out  = array();
		foreach ( is_array( $list ) ? $list : array() as $a ) {
			if ( is_array( $a ) && ! empty( $a['merged'] ) && is_array( $a['merged'] ) ) {
				$out[ (string) $a['key'] ] = array_values( array_unique( array_map( function ( $t ) { return (string) ( $t['item'] ?? '' ); }, $a['merged'] ) ) );
			}
		}
		return $out;
	}

	/** An item id for a stored key: itself when it is an item; the one item a kind of work moved to; '' otherwise. */
	public static function item_for_key( $key, $map = null ) {
		$items = self::items();
		if ( isset( $items[ $key ] ) ) {
			return (string) $key;
		}
		$map = null === $map ? self::legacy_map() : $map;
		$to  = $map[ $key ] ?? array();
		return 1 === count( $to ) && isset( $items[ $to[0] ] ) ? (string) $to[0] : '';
	}

	/** Team Roles' items in use (and retired, flagged): key => [label, roles[], addon, type, retired]. Empty without Team Roles. */
	public static function items() {
		if ( null !== self::$items_memo ) {
			return self::$items_memo;
		}
		$list = apply_filters( 'zdz_abilities', null, array( 'retired' => true ) );
		$out  = array();
		foreach ( is_array( $list ) ? $list : array() as $a ) {
			if ( is_array( $a ) && 'item' === ( $a['group'] ?? '' ) && ! empty( $a['key'] ) ) {
				$out[ (string) $a['key'] ] = array(
					'label'   => (string) ( $a['label'] ?? $a['key'] ),
					'roles'   => array_values( array_map( function ( $r ) { return (string) ( is_array( $r ) ? ( $r['role'] ?? '' ) : $r ); }, (array) ( $a['roles'] ?? array() ) ) ),
					'addon'   => ! empty( $a['addon'] ),
					'type'    => (string) ( $a['type'] ?? '' ),
					'retired' => ! empty( $a['retired'] ),
				);
			}
		}
		self::$items_memo = $out;
		return $out;
	}

	/**
	 * The Team Roles role a job's line asks for at a stage: Sell for a new stub; Install for new work
	 * and Repair for a repair on a won job (an item without Install is asked about Repair, and the
	 * other way). '' when the item has neither.
	 */
	public static function role_for( $item, $work, $stage ) {
		$items = self::items();
		$roles = isset( $items[ $item ] ) ? $items[ $item ]['roles'] : array();
		if ( 'install' !== $stage ) {
			return in_array( 'sell', $roles, true ) ? 'sell' : '';
		}
		$want = 'repair' === $work ? array( 'repair', 'install' ) : array( 'install', 'repair' );
		foreach ( $want as $r ) {
			if ( in_array( $r, $roles, true ) ) {
				return $r;
			}
		}
		return '';
	}

	/** The words table, cleaned. Empty until the business adds rows. */
	public static function rows() {
		$o = get_option( self::OPTION, null );
		if ( ! is_array( $o ) || ! isset( $o['rows'] ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) $o['rows'] as $r ) {
			$c = self::clean_row( $r );
			if ( ! is_wp_error( $c ) ) {
				$out[] = $c;
			}
		}
		return $out;
	}

	/** One row, cleaned; WP_Error when it can't be used. */
	public static function clean_row( $r ) {
		if ( ! is_array( $r ) ) {
			return new WP_Error( 'zter_row', __( 'A row of the words table is not readable.', 'zorderz' ) );
		}
		$type  = strtolower( trim( is_scalar( $r['type'] ?? '' ) ? (string) ( $r['type'] ?? '' ) : '' ) );
		$type  = isset( self::types()[ $type ] ) ? $type : '';
		$words = is_array( $r['words'] ?? null ) ? $r['words'] : ( is_scalar( $r['words'] ?? '' ) ? preg_split( '/\s*,\s*/', (string) ( $r['words'] ?? '' ) ) : array() );
		$clean = array();
		foreach ( $words as $w ) {
			if ( ! is_scalar( $w ) ) {
				continue;
			}
			$w = strtolower( trim( (string) preg_replace( '/\s+/', ' ', (string) $w ) ) );
			$w = (string) preg_replace( '/[^a-z0-9 \-\']/', '', $w );
			if ( strlen( $w ) >= 2 && strlen( $w ) <= 40 ) {
				$clean[ $w ] = true;
			}
		}
		$clean  = array_slice( array_keys( $clean ), 0, self::MAX_WORDS );
		$repair = in_array( (string) ( $r['repair'] ?? 'any' ), self::REPAIR, true ) ? (string) $r['repair'] : 'any';
		$item   = strtolower( trim( is_scalar( $r['item'] ?? '' ) ? (string) ( $r['item'] ?? '' ) : '' ) );
		if ( '' === $item && isset( $r['ability'] ) && is_scalar( $r['ability'] ) ) {
			$item = self::item_for_key( strtolower( trim( (string) $r['ability'] ) ) );
		}
		if ( ! preg_match( '/^[a-z0-9_\-]{1,80}$/', $item ) ) {
			return new WP_Error( 'zter_row', __( 'Each row needs the item it means.', 'zorderz' ) );
		}
		if ( '' === $type && ! $clean ) {
			return new WP_Error( 'zter_row', __( 'Each row needs a kind of project, some words, or both.', 'zorderz' ) );
		}
		$id = preg_match( '/^w\d{1,4}$/', is_scalar( $r['id'] ?? '' ) ? (string) ( $r['id'] ?? '' ) : '' ) ? (string) $r['id'] : '';
		return array( 'id' => $id, 'type' => $type, 'words' => $clean, 'repair' => $repair, 'item' => $item );
	}

	/** Save the whole table (the page sends it in order). Items must be ones Team Roles has in use, when it is active. */
	public static function save( array $rows, $by ) {
		$o     = get_option( self::OPTION, array() );
		$next  = (int) ( is_array( $o ) ? ( $o['next'] ?? 1 ) : 1 );
		$out   = array();
		$items = self::items();
		foreach ( array_slice( $rows, 0, self::MAX_ROWS ) as $i => $r ) {
			$c = self::clean_row( $r );
			if ( is_wp_error( $c ) ) {
				/* translators: 1: row number, 2: problem */
				return new WP_Error( 'zter_row', sprintf( __( 'Row %1$d: %2$s', 'zorderz' ), $i + 1, $c->get_error_message() ) );
			}
			if ( $items && ( ! isset( $items[ $c['item'] ] ) || ! empty( $items[ $c['item'] ]['retired'] ) ) ) {
				/* translators: 1: row number, 2: item id */
				return new WP_Error( 'zter_row', sprintf( __( 'Row %1$d: Team Roles has no item "%2$s" in use.', 'zorderz' ), $i + 1, $c['item'] ) );
			}
			if ( '' === $c['id'] ) {
				$c['id'] = 'w' . ( $next++ );
			}
			$out[] = $c;
		}
		$before = self::rows();
		update_option( self::OPTION, array( 'rows' => $out, 'next' => $next ), false );
		ZTER_DB::log( $by, 0, 'words', array( 'from' => $before, 'to' => $out ) );
		ZTER_DB::bump();
		return $out;
	}

	/**
	 * Team Roles' abilities in use and retired, key => [label, type, group, retired]. Null when Team
	 * Roles is not active (the filter returns the null passed in).
	 */
	public static function abilities() {
		$list = apply_filters( 'zdz_abilities', null, array( 'retired' => true ) );
		if ( ! is_array( $list ) ) {
			return null;
		}
		$out = array();
		foreach ( $list as $a ) {
			if ( is_array( $a ) && ! empty( $a['key'] ) ) {
				$out[ (string) $a['key'] ] = array(
					'label'   => (string) ( $a['label'] ?? $a['key'] ),
					'type'    => (string) ( $a['type'] ?? '' ),
					'group'   => (string) ( $a['group'] ?? '' ),
					'retired' => ! empty( $a['retired'] ),
				);
			}
		}
		return $out;
	}

	/** A stored kind ("<item>:repair") as words: "<Item> (repair)". */
	public static function stored_label( $stored, $abs = null ) {
		$p = explode( ':', (string) $stored, 2 );
		$w = $p[1] ?? '';
		return self::label( $p[0], $abs ) . ( 'repair' === $w ? ' (repair)' : ( 'both' === $w ? ' (new and repair)' : '' ) );
	}

	public static function label( $key, $abs = null ) {
		$abs = null === $abs ? self::abilities() : $abs;
		$key = (string) $key;
		if ( false !== strpos( $key, '__' ) && ! ( is_array( $abs ) && isset( $abs[ $key ] ) ) ) {
			$at = strrpos( $key, '__' );
			return self::label( substr( $key, 0, $at ), $abs ) . ': ' . substr( $key, $at + 2 );
		}
		return is_array( $abs ) && isset( $abs[ $key ] ) ? $abs[ $key ]['label'] : str_replace( array( '_', '-' ), ' ', $key );
	}

	/** The items in use, for the app's pickers: [{key, label, roles[], addon, type}], Team Roles' order. */
	public static function item_list() {
		$out = array();
		foreach ( self::items() as $k => $it ) {
			if ( empty( $it['retired'] ) ) {
				$out[] = array( 'key' => (string) $k, 'label' => $it['label'], 'roles' => $it['roles'], 'addon' => $it['addon'], 'type' => $it['type'] );
			}
		}
		return $out;
	}

	/** [name, desc] from either shape: {name, desc} or an estimate's {description, sub_description, notes}; or a string. */
	public static function line_text( $line ) {
		if ( is_string( $line ) ) {
			return array( substr( $line, 0, 500 ), '' );
		}
		if ( ! is_array( $line ) ) {
			return array( '', '' );
		}
		$name = (string) ( $line['name'] ?? ( $line['description'] ?? '' ) );
		$desc = (string) ( $line['desc'] ?? ( $line['sub_description'] ?? '' ) );
		if ( '' !== (string) ( $line['notes'] ?? '' ) ) {
			$desc = trim( $desc . ' ' . (string) $line['notes'] );
		}
		return array( substr( $name, 0, 500 ), substr( $desc, 0, 500 ) );
	}

	/** Lines that never say what the work is: the location line, notes, the appointment summary, and the business's own. */
	public static function is_admin_line( $name ) {
		$words = apply_filters( 'zter_admin_line_words', self::ADMIN_LINES );
		$name  = strtolower( ltrim( (string) $name ) );
		foreach ( is_array( $words ) ? $words : self::ADMIN_LINES as $w ) {
			$w = strtolower( trim( (string) $w ) );
			if ( '' !== $w && preg_match( '/^' . preg_quote( $w, '/' ) . '\b/', $name ) ) {
				return true;
			}
		}
		return false;
	}

	/** The kinds of project for a line (the Jobs app's classifier), or none without it. */
	public static function types_for( $name, $desc ) {
		if ( class_exists( 'ZJOB_Project_Types' ) && method_exists( 'ZJOB_Project_Types', 'types_for_line' ) ) {
			try {
				return array_values( array_map( 'strval', (array) ZJOB_Project_Types::types_for_line( (string) $name, (string) $desc ) ) );
			} catch ( \Throwable $e ) {
				return array();
			}
		}
		return array();
	}

	/** The catalog item a line names (its name first, then its description), when Team Roles tracks it; ''. */
	public static function catalog_item( $name, $desc ) {
		if ( ! class_exists( 'ZDZ_Item_Engine' ) || ! method_exists( 'ZDZ_Item_Engine', 'match' ) ) {
			return '';
		}
		$items = self::items();
		foreach ( array( $name, $desc ) as $text ) {
			if ( '' === trim( (string) $text ) ) {
				continue;
			}
			try {
				$hit = ZDZ_Item_Engine::match( (string) $text );
			} catch ( \Throwable $e ) {
				$hit = null;
			}
			$id = is_array( $hit ) ? (string) ( $hit['id'] ?? '' ) : '';
			if ( '' !== $id && isset( $items[ $id ] ) ) {
				return $id;
			}
		}
		return '';
	}

	/** Does any word or phrase in $words appear in $text as a word (a trailing "s" allowed)? */
	public static function has_word( $text, array $words ) {
		foreach ( $words as $w ) {
			if ( '' !== $w && preg_match( '/(?<![a-z0-9])' . preg_quote( $w, '/' ) . 's?(?![a-z0-9])/i', $text ) ) {
				return $w;
			}
		}
		return '';
	}

	/**
	 * What the work on an estimate is.
	 *
	 * @param array      $lines lines in either shape (at most 60 are read)
	 * @param array|null $rows  the words table (default: the stored one)
	 * @return array {
	 *   kinds: item ids, first seen first;
	 *   work:  item id => 'new' | 'repair' | 'both' (what the lines naming it ask for);
	 *   state: 'known' (every work line named an item) | 'unknown' (some did not) | 'none' (no work lines);
	 *   generic: a repair line named no item;
	 *   notes: per work line: {n, types[], repair, item, ability (the same id), work, by}.
	 * }
	 */
	public static function classify( array $lines, $rows = null ) {
		$rows    = null === $rows ? self::rows() : $rows;
		$items   = self::items();
		$repair_type = class_exists( 'ZJOB_Project_Types' ) && method_exists( 'ZJOB_Project_Types', 'repair_type' ) ? ZJOB_Project_Types::repair_type() : '';
		$kinds   = array();
		$work    = array();
		$notes   = array();
		$unknown = false;
		$generic = false;
		$n       = 0;
		foreach ( array_slice( array_values( $lines ), 0, self::MAX_LINES ) as $line ) {
			$n++;
			list( $name, $desc ) = self::line_text( $line );
			if ( '' === trim( $name . $desc ) || self::is_admin_line( $name ) ) {
				continue;
			}
			$types   = self::types_for( $name, $desc );
			$text    = strtolower( $name . ' ' . $desc );
			$is_new  = (bool) preg_match( self::NEW_WORDS, $name . ' ' . $desc );
			$repair  = self::says_repair( $name )
				|| ( ! $is_new && ( ( '' !== $repair_type && in_array( $repair_type, $types, true ) ) || self::says_repair( $desc ) ) );
			$product = array_values( array_diff( $types, array( $repair_type ) ) );
			$item    = '';
			$by      = '';
			foreach ( $rows as $r ) {
				if ( '' !== $r['type'] && ! in_array( $r['type'], $product, true ) ) {
					continue;
				}
				if ( $r['words'] && '' === self::has_word( $text, $r['words'] ) ) {
					continue;
				}
				if ( ( 'yes' === $r['repair'] && ! $repair ) || ( 'no' === $r['repair'] && $repair ) ) {
					continue;
				}
				$item = $r['item'];
				$by   = $r['id'];
				break;
			}
			if ( '' === $item ) {
				$item = self::catalog_item( $name, $desc );
				$by   = '' !== $item ? 'catalog' : '';
			}
			if ( '' === $item && ! $product && ! $repair ) {
				continue; // labour, a trip charge, parts: says nothing about who can do the work
			}
			if ( '' !== $item && $items && ( ! isset( $items[ $item ] ) || ! empty( $items[ $item ]['retired'] ) ) ) {
				$by   = 'gone:' . $item;
				$item = '';
			}
			$w = $repair ? 'repair' : 'new';
			if ( '' === $item ) {
				$unknown = true;
				if ( $repair && ! $product && '' === $by ) {
					$generic = true;
					$by      = 'repair';
				}
			} else {
				if ( ! in_array( $item, $kinds, true ) ) {
					$kinds[] = $item;
				}
				$work[ $item ] = isset( $work[ $item ] ) && $work[ $item ] !== $w ? 'both' : $w;
			}
			$notes[] = array( 'n' => $n, 'types' => $types, 'repair' => $repair, 'item' => $item, 'ability' => $item, 'work' => $w, 'by' => $by );
		}
		$state = ! $notes ? 'none' : ( $unknown ? 'unknown' : 'known' );
		return array( 'kinds' => $kinds, 'work' => $work, 'state' => $state, 'generic' => $generic, 'notes' => $notes );
	}

	/**
	 * The work lines' text, lower case, for rules that look for words. The location line, notes and
	 * the like are left out: a street name is not a product. Not stored anywhere.
	 */
	public static function all_text( array $lines ) {
		$t = '';
		foreach ( array_slice( array_values( $lines ), 0, self::MAX_LINES ) as $line ) {
			list( $name, $desc ) = self::line_text( $line );
			if ( self::is_admin_line( $name ) ) {
				continue;
			}
			$t .= ' ' . $name . ' ' . $desc;
		}
		return strtolower( $t );
	}
}
