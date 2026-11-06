<?php
/**
 * Database layer: tables, saving drops, lookups, exports.
 * Everything here uses $wpdb->prepare() — no raw user input reaches SQL.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDH_DB {

	public static function t_drops() {
		global $wpdb;
		return $wpdb->prefix . 'zdh_drops';
	}
	public static function t_addr() {
		global $wpdb;
		return $wpdb->prefix . 'zdh_addresses';
	}
	public static function t_streets() {
		global $wpdb;
		return $wpdb->prefix . 'zdh_streets';
	}

	/* ---------------------------------------------------------------- tables */

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$drops   = self::t_drops();
		$addr    = self::t_addr();
		$streets = self::t_streets();

		dbDelta( "CREATE TABLE $drops (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			drop_date date NULL,
			drop_time varchar(5) NOT NULL DEFAULT '',
			delivered_by varchar(100) NOT NULL DEFAULT '',
			delivered_by_uid bigint(20) unsigned NOT NULL DEFAULT 0,
			source_app varchar(40) NOT NULL DEFAULT 'doorhangers',
			hanger_type varchar(100) NOT NULL DEFAULT '',
			service varchar(150) NOT NULL DEFAULT '',
			quantity int NULL,
			city varchar(100) NOT NULL DEFAULT '',
			state varchar(10) NOT NULL DEFAULT '',
			zip varchar(10) NOT NULL DEFAULT '',
			county varchar(100) NOT NULL DEFAULT '',
			streets_summary varchar(255) NOT NULL DEFAULT '',
			description text NULL,
			notes text NULL,
			lat decimal(10,7) NULL,
			lng decimal(10,7) NULL,
			address_count int NOT NULL DEFAULT 0,
			marker_color varchar(9) NOT NULL DEFAULT '#e74c3c',
			responses int NULL,
			converted int NULL,
			attachment_ids text NULL,
			geometry_json longtext NULL,
			analysis_json longtext NULL,
			enrichment_json longtext NULL,
			status varchar(12) NOT NULL DEFAULT 'done',
			hanger_side varchar(10) NOT NULL DEFAULT '',
			revisit_days int NULL,
			method varchar(12) NOT NULL DEFAULT 'screenshot',
			name varchar(150) NOT NULL DEFAULT '',
			started_at datetime NULL,
			finished_at datetime NULL,
			track_json longtext NULL,
			walks_json text NULL,
			assigned_uid bigint(20) unsigned NOT NULL DEFAULT 0,
			due_by date NULL,
			hours varchar(40) NOT NULL DEFAULT '',
			plan_source varchar(40) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY drop_date (drop_date),
			KEY assigned_uid (assigned_uid),
			KEY zip (zip),
			KEY status (status)
		) $charset;" );

		dbDelta( "CREATE TABLE $addr (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			drop_id bigint(20) unsigned NOT NULL,
			house_number varchar(12) NOT NULL DEFAULT '',
			unit varchar(20) NOT NULL DEFAULT '',
			street varchar(120) NOT NULL DEFAULT '',
			city varchar(100) NOT NULL DEFAULT '',
			state varchar(10) NOT NULL DEFAULT '',
			zip varchar(10) NOT NULL DEFAULT '',
			full_address varchar(255) NOT NULL DEFAULT '',
			match_key varchar(191) NOT NULL DEFAULT '',
			street_key varchar(120) NOT NULL DEFAULT '',
			lat decimal(10,7) NULL,
			lng decimal(10,7) NULL,
			source varchar(20) NOT NULL DEFAULT 'region',
			confidence varchar(20) NOT NULL DEFAULT 'verified',
			road_seg_id int NULL,
			apn varchar(20) NOT NULL DEFAULT '',
			delivered tinyint(1) NOT NULL DEFAULT 1,
			delivered_at datetime NULL,
			walk tinyint unsigned NOT NULL DEFAULT 1,
			walk_order int NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY drop_id (drop_id),
			KEY match_key (match_key),
			KEY street_zip (street_key, zip)
		) $charset;" );

		dbDelta( "CREATE TABLE $streets (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			drop_id bigint(20) unsigned NOT NULL,
			street varchar(120) NOT NULL DEFAULT '',
			street_key varchar(120) NOT NULL DEFAULT '',
			city varchar(100) NOT NULL DEFAULT '',
			zip varchar(10) NOT NULL DEFAULT '',
			coverage varchar(10) NOT NULL DEFAULT 'full',
			low_number int NULL,
			high_number int NULL,
			address_count int NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY drop_id (drop_id),
			KEY street_zip (street_key, zip)
		) $charset;" );
		if ( class_exists( 'ZDH_NoSolicit' ) ) { ZDH_NoSolicit::create_table(); }
	}

	/* ----------------------------------------------------- normalization */

	/** Street suffix / directional canonical short forms (USPS style). */
	public static function suffix_map() {
		return array(
			'avenue' => 'ave', 'av' => 'ave', 'boulevard' => 'blvd', 'circle' => 'cir', 'court' => 'ct', 'cove' => 'cv',
			'drive' => 'dr', 'expressway' => 'expy', 'freeway' => 'fwy', 'glen' => 'gln', 'heights' => 'hts', 'highway' => 'hwy',
			'lane' => 'ln', 'loop' => 'loop', 'parkway' => 'pkwy', 'place' => 'pl', 'plaza' => 'plz', 'point' => 'pt',
			'road' => 'rd', 'ridge' => 'rdg', 'row' => 'row', 'run' => 'run', 'square' => 'sq', 'street' => 'st',
			'terrace' => 'ter', 'trail' => 'trl', 'way' => 'way', 'walk' => 'walk', 'view' => 'vw', 'vista' => 'vis',
			'crossing' => 'xing', 'alley' => 'aly', 'path' => 'path', 'pass' => 'pass', 'mount' => 'mt', 'lake' => 'lk',
			'ave' => 'ave', 'blvd' => 'blvd', 'cir' => 'cir', 'ct' => 'ct', 'cv' => 'cv', 'dr' => 'dr', 'expy' => 'expy',
			'fwy' => 'fwy', 'gln' => 'gln', 'hts' => 'hts', 'hwy' => 'hwy', 'ln' => 'ln', 'pkwy' => 'pkwy', 'pky' => 'pkwy',
			'pl' => 'pl', 'plz' => 'plz', 'pt' => 'pt', 'rd' => 'rd', 'rdg' => 'rdg', 'sq' => 'sq', 'st' => 'st',
			'ter' => 'ter', 'terr' => 'ter', 'trl' => 'trl', 'vw' => 'vw', 'vis' => 'vis', 'xing' => 'xing', 'aly' => 'aly', 'mt' => 'mt', 'lk' => 'lk',
		);
	}

	public static function dir_map() {
		return array( 'north' => 'n', 'south' => 's', 'east' => 'e', 'west' => 'w', 'n' => 'n', 's' => 's', 'e' => 'e', 'w' => 'w',
			'northeast' => 'ne', 'northwest' => 'nw', 'southeast' => 'se', 'southwest' => 'sw', 'ne' => 'ne', 'nw' => 'nw', 'se' => 'se', 'sw' => 'sw' );
	}

	/**
	 * Normalize a street name for matching: "Oak Hollow Drive" / "OAK HOLLOW DR" / "oak  hollow dr." -> "oak hollow dr".
	 * Directionals at the start/end and the final suffix word are canonicalized.
	 */
	public static function street_key( $street ) {
		$s = strtolower( trim( (string) $street ) );
		$s = str_replace( array( '.', ',', '#' ), ' ', $s );
		$s = preg_replace( '/[^a-z0-9\' \-]/', ' ', $s );
		$s = preg_replace( '/\s+/', ' ', trim( $s ) );
		if ( $s === '' ) {
			return '';
		}
		$parts  = explode( ' ', $s );
		$sfx    = self::suffix_map();
		$dirs   = self::dir_map();
		$n      = count( $parts );
		// leading directional (only when more words follow)
		if ( $n > 1 && isset( $dirs[ $parts[0] ] ) ) {
			$parts[0] = $dirs[ $parts[0] ];
		}
		// trailing directional (e.g. "Main St N")
		if ( $n > 2 && isset( $dirs[ $parts[ $n - 1 ] ] ) ) {
			$parts[ $n - 1 ] = $dirs[ $parts[ $n - 1 ] ];
			$sfx_idx         = $n - 2;
		} else {
			$sfx_idx = $n - 1;
		}
		if ( $sfx_idx > 0 && isset( $sfx[ $parts[ $sfx_idx ] ] ) ) {
			$parts[ $sfx_idx ] = $sfx[ $parts[ $sfx_idx ] ];
		}
		// "Mount Example Ave" and "Mt Example Ave" (address layers use both) are one street, "Saint John Pl" = "St John Pl"
		$first = ( $n > 1 && isset( $dirs[ $s === '' ? '' : explode( ' ', $s )[0] ] ) ) ? 1 : 0;
		if ( $n - $first > 2 ) {
			if ( 'mount' === $parts[ $first ] ) { $parts[ $first ] = 'mt'; }
			if ( 'saint' === $parts[ $first ] ) { $parts[ $first ] = 'st'; }
		}
		return implode( ' ', $parts );
	}

	public static function match_key( $number, $street, $unit = '' ) {
		$num  = strtolower( preg_replace( '/[^0-9a-z\/\-]/i', '', (string) $number ) );
		$key  = $num . ' ' . self::street_key( $street );
		$unit = strtolower( preg_replace( '/[^0-9a-z]/i', '', (string) $unit ) );
		if ( $unit !== '' ) {
			$key .= ' #' . $unit;
		}
		return $key;
	}

	/**
	 * Town and community names stripped off the end of an address typed without commas: the business's own list
	 * (option `zdh_places`, territories.yml `canvassing.places`; filter `zdh_known_places`) and the default town. Core
	 * names no place.
	 */
	public static function known_places() {
		$list = function_exists( 'get_option' ) ? get_option( 'zdh_places', array() ) : array();
		$list = is_array( $list ) ? $list : array();
		if ( function_exists( 'zdh_area_defaults' ) ) {
			$list[] = zdh_area_defaults()['city'];
		}
		if ( function_exists( 'apply_filters' ) ) {
			$f    = apply_filters( 'zdh_known_places', $list );
			$list = is_array( $f ) ? $f : $list;
		}
		return array_values( array_unique( array_filter( array_map( function ( $c ) { return strtolower( trim( (string) $c ) ); }, $list ) ) ) );
	}

	/**
	 * The words for the business's state or region stripped off the end of a typed address: its code and its name, as
	 * the default area says (and any added through `zdh_state_words`). Empty when the area names none.
	 */
	public static function state_words() {
		$w = array();
		if ( function_exists( 'zdh_area_defaults' ) ) {
			$w[] = zdh_area_defaults()['state'];
		}
		if ( function_exists( 'apply_filters' ) ) {
			$f = apply_filters( 'zdh_state_words', $w );
			$w = is_array( $f ) ? $f : $w;
		}
		return array_values( array_unique( array_filter( array_map( function ( $x ) { return strtolower( trim( (string) $x, " .\t" ) ); }, $w ) ) ) );
	}

	/**
	 * Parse a free-form address line typed into the lookup box (or a CRM's street line).
	 * Returns [number, street, unit, zip] (any may be empty).
	 * copes with addresses pasted straight from a CRM or a text message — line breaks, no commas, a state and
	 * a country on the end ("4100 Example Court Exampleton ST 00000 US"), so that what follows the street is not read
	 * as part of the street name (which would never match).
	 */
	public static function parse_address_line( $line, $known_cities = array(), $state_words = array() ) {
		$line = (string) $line;
		$line = preg_replace( '/[\r\n\t]+/', ', ', $line ); // pasted on several lines
		$line = preg_replace( '/\s+/', ' ', trim( $line ) );
		$line = trim( $line, " ,;" );
		// country on the end
		$line = preg_replace( '/[\s,]+(?:u\.?s\.?a?\.?|united states(?: of america)?)\s*$/i', '', $line );
		$zip  = '';
		if ( preg_match( '/[\s,]*\b(\d{5})(?:-\d{4})?\s*$/', $line, $m ) ) {
			$zip  = $m[1];
			$line = trim( substr( $line, 0, -strlen( $m[0] ) ) );
		}
		// state on the end (with or without a comma): the business's own state words
		$states = $state_words ? $state_words : self::state_words();
		if ( $states ) {
			$alt  = implode( '|', array_map( function ( $w ) { return preg_quote( $w, '/' ); }, $states ) );
			$line = trim( preg_replace( '/[\s,]+(?:' . $alt . ')\.?\s*$/i', '', $line ), " ," );
		}
		if ( $zip === '' && preg_match( '/[\s,]*\b(\d{5})(?:-\d{4})?\s*$/', $line, $m ) && preg_match( '/^\d+\s+\S/', $line ) && substr_count( $line, ' ' ) > 2 ) {
			$zip  = $m[1]; // "…, 00000 ST"
			$line = trim( substr( $line, 0, -strlen( $m[0] ) ), " ," );
		}
		// keep only the part before the first comma (the street part)
		$parts  = array_map( 'trim', explode( ',', $line ) );
		$street = $parts[0];
		$commas = count( $parts ) > 1;
		$unit   = '';
		// the unit can sit in its own comma part ("123 Main St, Apt 4, Exampleton")
		if ( $commas && isset( $parts[1] ) && preg_match( '/^(?:#|apt\.?|apartment|unit|ste\.?|suite|spc|space)\s*#?\s*([0-9a-z\-]+)$/i', $parts[1], $m ) ) {
			$unit = $m[1];
		}
		// strip trailing city words if the user did not use commas
		if ( ! $commas ) {
			$cities = array_merge( array_map( 'strtolower', array_map( 'trim', (array) $known_cities ) ), self::known_places() );
			$cities = array_values( array_unique( array_filter( $cities ) ) );
			usort( $cities, function ( $a, $b ) { return strlen( $b ) - strlen( $a ); } ); // "north exampleton" before "exampleton"
			foreach ( $cities as $c ) {
				$re = '/\s+' . preg_quote( $c, '/' ) . '\s*$/i';
				if ( preg_match( $re, $street ) ) {
					$rest = preg_replace( $re, '', $street );
					if ( preg_match( '/^\d+[a-z]?\s+\S/i', $rest ) ) { // never eat the whole street ("123 Vista")
						$street = $rest;
						break;
					}
				}
			}
			// still a city we do not know? cut after the street-type word ("Example Court | Somewhere")
			$street = self::cut_after_suffix( $street );
		}
		if ( preg_match( '/\s(?:#|apt\.?|apartment|unit|ste\.?|suite|spc|space)\s*#?\s*([0-9a-z\-]+)\s*$/i', $street, $m ) ) {
			$unit   = $m[1];
			$street = trim( substr( $street, 0, -strlen( $m[0] ) ) );
		} elseif ( preg_match( '/\s#\s*([0-9a-z\-]+)\s*$/i', $street, $m ) ) {
			$unit   = $m[1];
			$street = trim( substr( $street, 0, -strlen( $m[0] ) ) );
		}
		$number = '';
		if ( preg_match( '/^(\d+[a-z]?(?:[\-\/]\d+)?)\s+(.+)$/i', $street, $m ) ) {
			$number = $m[1];
			$street = $m[2];
		}
		return array( $number, trim( $street ), $unit, $zip );
	}

	/**
	 * "4100 Example Court Example Hills" → "4100 Example Court": drop the words after the first street-type word that
	 * follows the street name (a directional right after it is kept: "Main St N"). Lines without such a word are left alone.
	 */
	private static function cut_after_suffix( $street ) {
		$w = preg_split( '/\s+/', trim( $street ) );
		if ( count( $w ) < 4 || ! preg_match( '/^\d/', $w[0] ) ) {
			return $street;
		}
		$sfx  = self::suffix_map();
		$dirs = self::dir_map();
		for ( $i = 2; $i < count( $w ) - 1; $i++ ) { // $w[1] is the first word of the name, never the suffix
			$t = strtolower( trim( $w[ $i ], '.' ) );
			if ( isset( $sfx[ $t ] ) && ! in_array( $t, array( 'vista', 'vis', 'view', 'vw', 'point', 'pt', 'lake', 'lk', 'mount', 'mt', 'glen', 'gln', 'heights', 'hts', 'ridge', 'rdg', 'run', 'row', 'pass', 'walk' ), true ) ) {
				while ( isset( $w[ $i + 1 ] ) && isset( $sfx[ strtolower( trim( $w[ $i + 1 ], '.' ) ) ] ) ) {
					$i++; // "Example Place Dr" — the last street-type word of a run is the suffix
				}
				if ( isset( $w[ $i + 1 ] ) && preg_match( '/^\d/', $w[ $i + 1 ] ) ) {
					return $street; // "Old Highway 80"
				}
				$keep = $i + 1;
				if ( isset( $w[ $keep ] ) && preg_match( '/^#\S+$/', $w[ $keep ] ) ) {
					return implode( ' ', array_slice( $w, 0, $keep + 1 ) );
				}
				if ( isset( $w[ $keep + 1 ] ) && preg_match( '/^(?:apt\.?|apartment|unit|ste\.?|suite|spc|space)$/i', $w[ $keep ] ) ) {
					return implode( ' ', array_slice( $w, 0, $keep + 2 ) );
				}
				if ( isset( $w[ $keep ] ) && isset( $dirs[ strtolower( $w[ $keep ] ) ] ) ) {
					$keep++;
				}
				return implode( ' ', array_slice( $w, 0, $keep ) );
			}
		}
		return $street;
	}

	/* ------------------------------------------------------------ writes */

	/**
	 * Save a drop with its addresses and street coverage. $data is already sanitized by the REST layer.
	 * Returns the new drop id.
	 */
	public static function insert_drop( $data, $addresses, $streets ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		// a code in the notes or description never reaches the row; once the route has its id the
		// code is sealed under it (ZDZ_Sealed, ['street', 'drop:<id>']) and the row keeps "[gate code sealed]"
		$plain = array();
		if ( class_exists( 'ZDH_Sealed' ) ) {
			foreach ( array( 'notes', 'description' ) as $k ) {
				if ( isset( $data[ $k ] ) && is_string( $data[ $k ] ) && ZDH_Sealed::has_code( $data[ $k ] ) ) {
					$plain[ $k ]  = $data[ $k ];
					$data[ $k ]   = ZDH_Sealed::cut( $data[ $k ] );
				}
			}
		}
		$row = array(
			'created_at'      => $now,
			'created_by'      => get_current_user_id(),
			'drop_date'       => $data['drop_date'] ?: null,
			'drop_time'       => $data['drop_time'],
			'delivered_by'    => $data['delivered_by'],
			'delivered_by_uid' => isset( $data['delivered_by_uid'] ) ? (int) $data['delivered_by_uid'] : 0,
			'source_app'      => isset( $data['source_app'] ) ? substr( (string) $data['source_app'], 0, 40 ) : 'doorhangers',
			'hanger_type'     => $data['hanger_type'],
			'service'         => $data['service'],
			'quantity'        => $data['quantity'],
			'city'            => $data['city'],
			'state'           => $data['state'],
			'zip'             => $data['zip'],
			'county'          => $data['county'],
			'streets_summary' => $data['streets_summary'],
			'description'     => $data['description'],
			'notes'           => $data['notes'],
			'lat'             => $data['lat'],
			'lng'             => $data['lng'],
			'address_count'   => count( $addresses ),
			'marker_color'    => $data['marker_color'],
			'attachment_ids'  => wp_json_encode( $data['attachment_ids'] ),
			'geometry_json'   => wp_json_encode( $data['geometry'] ),
			'analysis_json'   => wp_json_encode( $data['analysis'] ),
			'enrichment_json' => null,
			'status'          => $data['status'],
			'hanger_side'     => $data['hanger_side'],
			'revisit_days'    => $data['revisit_days'],
			'method'          => $data['method'],
			'name'            => $data['name'],
		);
		// a quick walk arrives already finished: keep its timing and GPS track
		if ( ! empty( $data['started_at'] ) ) { $row['started_at'] = $data['started_at']; }
		if ( ! empty( $data['finished_at'] ) ) { $row['finished_at'] = $data['finished_at']; }
		if ( ! empty( $data['track'] ) ) { $row['track_json'] = wp_json_encode( $data['track'] ); }
		if ( ! empty( $data['walks'] ) ) { $row['walks_json'] = wp_json_encode( $data['walks'] ); }
		$wpdb->insert( self::t_drops(), $row );
		$drop_id = (int) $wpdb->insert_id;
		if ( ! $drop_id ) {
			return 0;
		}
		foreach ( $plain as $v ) {   // the cut words are already in the row; the codes go to the vault now
			ZDH_Sealed::seal( $v, $drop_id );
		}
		// rotate marker colours so neighbouring drops are distinguishable on the map
		$palette = array( '#e74c3c', '#8e44ad', '#2980b9', '#16a085', '#d35400', '#27ae60', '#f39c12', '#c0392b', '#2c3e50', '#e84393' );
		$wpdb->update( self::t_drops(), array( 'marker_color' => $palette[ $drop_id % count( $palette ) ] ), array( 'id' => $drop_id ) );

		self::insert_addresses( $drop_id, $addresses, 'planned' === $data['status'] ? 0 : 1 );
		self::insert_streets( $drop_id, $streets );
		// tie the screenshots to the drop in the media library
		foreach ( $data['attachment_ids'] as $aid ) {
			update_post_meta( (int) $aid, '_zdh_drop_id', $drop_id );
			wp_update_post( array( 'ID' => (int) $aid, 'post_title' => sprintf( 'Door hanger %s #%d — %s %s', 'planned' === $data['status'] ? 'plan' : 'drop', $drop_id, $data['drop_date'], $data['streets_summary'] ) ) );
		}
		return $drop_id;
	}

	public static function insert_addresses( $drop_id, $addresses, $delivered = 1 ) {
		global $wpdb;
		// the safety floor: a house with a No Soliciting sign is never stored on a route
		if ( class_exists( 'ZDH_NoSolicit' ) ) {
			list( $addresses ) = ZDH_NoSolicit::without( (array) $addresses, ZDH_NoSolicit::keys() );
		}
		foreach ( $addresses as $a ) {
			$wpdb->insert( self::t_addr(), array(
				'drop_id'      => $drop_id,
				'house_number' => $a['number'],
				'unit'         => $a['unit'],
				'street'       => $a['street'],
				'city'         => $a['city'],
				'state'        => $a['state'],
				'zip'          => $a['zip'],
				'full_address' => $a['full'],
				'match_key'    => self::match_key( $a['number'], $a['street'], $a['unit'] ),
				'street_key'   => self::street_key( $a['street'] ),
				'lat'          => $a['lat'],
				'lng'          => $a['lng'],
				'source'       => $a['source'],
				'confidence'   => $a['confidence'],
				'road_seg_id'  => $a['seg'],
				'apn'          => $a['apn'],
				'delivered'    => $delivered,
				'delivered_at' => $delivered ? current_time( 'mysql' ) : null,
				'walk'         => isset( $a['walk'] ) ? max( 1, min( 250, (int) $a['walk'] ) ) : 1,
				'walk_order'   => isset( $a['walk_order'] ) ? max( 0, (int) $a['walk_order'] ) : 0,
			) );
		}
	}

	public static function insert_streets( $drop_id, $streets ) {
		global $wpdb;
		foreach ( $streets as $st ) {
			$wpdb->insert( self::t_streets(), array(
				'drop_id'       => $drop_id,
				'street'        => $st['street'],
				'street_key'    => self::street_key( $st['street'] ),
				'city'          => $st['city'],
				'zip'           => $st['zip'],
				'coverage'      => $st['coverage'],
				'low_number'    => $st['low'],
				'high_number'   => $st['high'],
				'address_count' => $st['count'],
			) );
		}
	}

	/** Replace the houses/streets/geometry of a planned cluster (contextual editing). */
	public static function replace_houses( $drop_id, $addresses, $streets, $geometry, $summary, $walks = null ) {
		global $wpdb;
		$wpdb->delete( self::t_addr(), array( 'drop_id' => (int) $drop_id ) );
		$wpdb->delete( self::t_streets(), array( 'drop_id' => (int) $drop_id ) );
		self::insert_addresses( (int) $drop_id, $addresses, 0 );
		self::insert_streets( (int) $drop_id, $streets );
		$wpdb->update( self::t_drops(), array( 'address_count' => count( $addresses ), 'geometry_json' => wp_json_encode( $geometry ), 'streets_summary' => $summary, 'walks_json' => $walks ? wp_json_encode( $walks ) : null ), array( 'id' => (int) $drop_id ) );
	}

	/* ------------------------------------------------------------- walks */

	/** The walks of a cluster: the stored list, or one walk covering everything for clusters planned before 1.6. */
	public static function walks_of( $d ) {
		$w = ( isset( $d['walks'] ) && is_array( $d['walks'] ) && ! empty( $d['walks']['list'] ) ) ? $d['walks'] : null;
		if ( ! $w ) {
			$w = array( 'size' => 0, 'style' => '', 'per_hour' => 0, 'list' => array( array( 'n' => 1, 'count' => (int) $d['address_count'], 'est_min' => 0, 'streets' => (string) $d['streets_summary'], 'status' => 'done' === $d['status'] ? 'done' : ( 'in_progress' === $d['status'] ? 'in_progress' : 'planned' ) ) ) );
		}
		return $w;
	}
	/** Re-assign every house to a walk (walk number + order) and store the walk list. $assign: address id => [walk, order]. */
	public static function set_walks( $drop_id, $walks, $assign ) {
		global $wpdb;
		$drop_id = (int) $drop_id;
		foreach ( $assign as $aid => $wo ) {
			$wpdb->update( self::t_addr(), array( 'walk' => max( 1, min( 250, (int) $wo[0] ) ), 'walk_order' => max( 0, (int) $wo[1] ) ), array( 'id' => (int) $aid, 'drop_id' => $drop_id ) );
		}
		$wpdb->update( self::t_drops(), array( 'walks_json' => $walks ? wp_json_encode( $walks ) : null ), array( 'id' => $drop_id ) );
		return true;
	}
	private static function save_walks( $drop_id, $walks ) {
		global $wpdb;
		$wpdb->update( self::t_drops(), array( 'walks_json' => wp_json_encode( $walks ) ), array( 'id' => (int) $drop_id ) );
	}
	/** Mark one walk started (the cluster becomes in progress). */
	public static function start_walk( $id, $n ) {
		$d = self::get_drop( $id );
		if ( ! $d ) { return false; }
		$w = self::walks_of( $d );
		foreach ( $w['list'] as &$walk ) {
			if ( (int) $walk['n'] === (int) $n && 'done' !== $walk['status'] ) { $walk['status'] = 'in_progress'; if ( empty( $walk['started_at'] ) ) { $walk['started_at'] = current_time( 'mysql' ); } }
		}
		unset( $walk );
		self::save_walks( $id, $w );
		return true;
	}
	/** Finish one walk: optionally mark its remaining houses delivered; returns true when every walk is now done. */
	public static function finish_walk( $id, $n, $mark_remaining ) {
		global $wpdb;
		$id = (int) $id; $n = (int) $n;
		if ( $mark_remaining ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t_addr() . ' SET delivered = 1, delivered_at = %s WHERE drop_id = %d AND walk = %d AND delivered = 0', current_time( 'mysql' ), $id, $n ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from self::t_addr() ($wpdb->prefix and a fixed name); values are placeholders
			if ( class_exists( 'ZDH_NoSolicit' ) ) { ZDH_NoSolicit::undeliver_in_drop( $id ); } // a No Soliciting house is never "the rest"
		}
		$d = self::get_drop( $id );
		if ( ! $d ) { return false; }
		$w = self::walks_of( $d );
		$all_done = true;
		foreach ( $w['list'] as &$walk ) {
			if ( (int) $walk['n'] === $n ) {
				$walk['status'] = 'done'; $walk['finished_at'] = current_time( 'mysql' );
				if ( empty( $walk['started_at'] ) ) { $walk['started_at'] = current_time( 'mysql' ); }
				$walk['delivered'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t_addr() . ' WHERE drop_id = %d AND walk = %d AND delivered = 1', $id, $n ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from self::t_addr() ($wpdb->prefix and a fixed name); values are placeholders
			}
			if ( 'done' !== $walk['status'] ) { $all_done = false; }
		}
		unset( $walk );
		self::save_walks( $id, $w );
		return $all_done;
	}
	private static function walks_all( $id, $status ) {
		$d = self::get_drop( $id );
		if ( ! $d ) { return; }
		$w = self::walks_of( $d );
		foreach ( $w['list'] as &$walk ) {
			$walk['status'] = $status;
			if ( 'planned' === $status ) { unset( $walk['started_at'], $walk['finished_at'], $walk['delivered'] ); }
			elseif ( 'done' === $status && empty( $walk['finished_at'] ) ) { $walk['finished_at'] = current_time( 'mysql' ); }
		}
		unset( $walk );
		if ( ! empty( $d['walks']['list'] ) ) { self::save_walks( $id, $w ); } // clusters without a stored list stay without one
	}

	/* ---------------------------------------------------- delivery session */

	public static function start_delivery( $id, $started_at = null ) {
		global $wpdb;
		$d = self::get_drop( $id );
		if ( ! $d ) { return false; }
		$when = $started_at ? $started_at : current_time( 'mysql' );
		$fields = array( 'status' => 'in_progress' );
		if ( empty( $d['started_at'] ) ) { $fields['started_at'] = $when; }
		if ( empty( $d['drop_date'] ) || 'planned' === $d['status'] ) { $fields['drop_date'] = substr( $when, 0, 10 ); $fields['drop_time'] = substr( $when, 11, 5 ); }
		$wpdb->update( self::t_drops(), $fields, array( 'id' => (int) $id ) );
		return true;
	}

	/** Back to planned: forget the walk (start time, track, ticks) but keep the route and its houses. */
	public static function reset_delivery( $id ) {
		global $wpdb;
		$id = (int) $id;
		$wpdb->update( self::t_drops(), array( 'status' => 'planned', 'started_at' => null, 'finished_at' => null, 'track_json' => null ), array( 'id' => $id ) );
		$wpdb->update( self::t_addr(), array( 'delivered' => 0, 'delivered_at' => null ), array( 'drop_id' => $id ) );
		self::walks_all( $id, 'planned' );
		return true;
	}

	/** Mark houses delivered / undelivered and append GPS track points. */
	public static function record_progress( $id, $delivered_ids, $undelivered_ids, $track ) {
		global $wpdb;
		$id = (int) $id;
		$now = current_time( 'mysql' );
		if ( $delivered_ids ) {
			$in = implode( ',', array_map( 'intval', $delivered_ids ) );
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t_addr() . " SET delivered = 1, delivered_at = COALESCE(delivered_at, %s) WHERE drop_id = %d AND id IN ($in)", $now, $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from self::t_addr() ($wpdb->prefix and a fixed name); $in is a list of ints (array_map intval); the rest are placeholders
			if ( class_exists( 'ZDH_NoSolicit' ) ) { ZDH_NoSolicit::undeliver_in_drop( $id ); } // an auto-tick that arrives after the sign was pressed
		}
		if ( $undelivered_ids ) {
			$in = implode( ',', array_map( 'intval', $undelivered_ids ) );
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t_addr() . " SET delivered = 0, delivered_at = NULL WHERE drop_id = %d AND id IN ($in)", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from self::t_addr() ($wpdb->prefix and a fixed name); $in is a list of ints (array_map intval); the rest are placeholders
		}
		if ( $track ) {
			$row = $wpdb->get_var( $wpdb->prepare( 'SELECT track_json FROM ' . self::t_drops() . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from self::t_drops() ($wpdb->prefix and a fixed name); values are placeholders
			$cur = $row ? json_decode( $row, true ) : array();
			if ( ! is_array( $cur ) ) { $cur = array(); }
			// the phone may send a stretch twice (a background beacon it could not confirm, then the next sync): keep each moment once
			$seen = array();
			foreach ( $cur as $pt ) { if ( isset( $pt[2] ) ) { $seen[ (int) $pt[2] ] = true; } }
			$added = 0;
			foreach ( $track as $pt ) {
				$t = isset( $pt[2] ) ? (int) $pt[2] : 0;
				if ( $t && isset( $seen[ $t ] ) ) { continue; }
				$seen[ $t ] = true; $cur[] = $pt; $added++;
			}
			if ( $added ) {
				usort( $cur, function ( $a, $b ) { return ( isset( $a[2] ) ? (int) $a[2] : 0 ) <=> ( isset( $b[2] ) ? (int) $b[2] : 0 ); } );
				if ( count( $cur ) > 20000 ) { $cur = array_slice( $cur, -20000 ); }
				$wpdb->update( self::t_drops(), array( 'track_json' => wp_json_encode( $cur ) ), array( 'id' => $id ) );
			}
		}
		return array(
			'delivered' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t_addr() . ' WHERE drop_id = %d AND delivered = 1', $id ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from self::t_addr() ($wpdb->prefix and a fixed name); values are placeholders
			'total'     => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t_addr() . ' WHERE drop_id = %d', $id ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from self::t_addr() ($wpdb->prefix and a fixed name); values are placeholders
		);
	}

	public static function finish_delivery( $id, $mark_remaining, $quantity, $delivered_by, $notes, $finished_at = null ) {
		global $wpdb;
		$id = (int) $id;
		$when = $finished_at ? $finished_at : current_time( 'mysql' );
		if ( $mark_remaining ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t_addr() . ' SET delivered = 1, delivered_at = %s WHERE drop_id = %d AND delivered = 0', $when, $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from self::t_addr() ($wpdb->prefix and a fixed name); values are placeholders
			if ( class_exists( 'ZDH_NoSolicit' ) ) { ZDH_NoSolicit::undeliver_in_drop( $id ); }
		}
		$delivered = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t_addr() . ' WHERE drop_id = %d AND delivered = 1', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from self::t_addr() ($wpdb->prefix and a fixed name); values are placeholders
		$fields = array( 'status' => 'done', 'finished_at' => $when, 'quantity' => null === $quantity ? $delivered : $quantity );
		if ( $delivered_by !== null && $delivered_by !== '' ) { $fields['delivered_by'] = $delivered_by; }
		if ( $notes !== null ) { $fields['notes'] = class_exists( 'ZDH_Sealed' ) ? ZDH_Sealed::seal( (string) $notes, $id ) : $notes; }
		$d = self::get_drop( $id );
		if ( $d && empty( $d['started_at'] ) ) { $fields['started_at'] = current_time( 'mysql' ); }
		if ( $d && empty( $d['drop_date'] ) ) { $fields['drop_date'] = current_time( 'Y-m-d' ); }
		$wpdb->update( self::t_drops(), $fields, array( 'id' => $id ) );
		// street ranges reflect what was actually delivered
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT street, street_key, city, zip, house_number, delivered FROM ' . self::t_addr() . ' WHERE drop_id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from self::t_addr() ($wpdb->prefix and a fixed name); values are placeholders
		$wpdb->delete( self::t_streets(), array( 'drop_id' => $id ) );
		$by = array();
		foreach ( $rows as $r ) {
			$k = $r['street_key'];
			if ( ! isset( $by[ $k ] ) ) { $by[ $k ] = array( 'street' => $r['street'], 'city' => $r['city'], 'zip' => $r['zip'], 'nums' => array(), 'total' => 0, 'count' => 0 ); }
			$by[ $k ]['total']++;
			if ( $r['delivered'] ) { $by[ $k ]['count']++; $n = (int) $r['house_number']; if ( $n > 0 ) { $by[ $k ]['nums'][] = $n; } }
		}
		$streets = array();
		foreach ( $by as $st ) {
			if ( ! $st['count'] ) { continue; }
			$streets[] = array( 'street' => $st['street'], 'city' => $st['city'], 'zip' => $st['zip'], 'coverage' => $st['count'] === $st['total'] ? 'full' : 'partial', 'low' => $st['nums'] ? min( $st['nums'] ) : null, 'high' => $st['nums'] ? max( $st['nums'] ) : null, 'count' => $st['count'] );
		}
		self::insert_streets( $id, $streets );
		self::walks_all( $id, 'done' );
		return $delivered;
	}

	public static function update_drop_fields( $id, $fields ) {
		global $wpdb;
		if ( empty( $fields ) ) {
			return true;
		}
		if ( class_exists( 'ZDH_Sealed' ) ) {
			$fields = ZDH_Sealed::fields( (array) $fields, (int) $id );   // codes in notes / description sealed under the route
		}
		return false !== $wpdb->update( self::t_drops(), $fields, array( 'id' => (int) $id ) );
	}

	public static function set_enrichment( $id, $enrichment ) {
		global $wpdb;
		$wpdb->update( self::t_drops(), array( 'enrichment_json' => wp_json_encode( $enrichment ) ), array( 'id' => (int) $id ) );
	}

	public static function delete_drop( $id ) {
		global $wpdb;
		$id = (int) $id;
		$wpdb->delete( self::t_addr(), array( 'drop_id' => $id ) );
		$wpdb->delete( self::t_streets(), array( 'drop_id' => $id ) );
		$wpdb->delete( self::t_drops(), array( 'id' => $id ) );
		// keep the screenshots in the media library, just unlink them
		$att = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'posts_per_page' => 50, 'fields' => 'ids', 'meta_key' => '_zdh_drop_id', 'meta_value' => $id ) );
		foreach ( $att as $aid ) {
			delete_post_meta( $aid, '_zdh_drop_id' );
		}
		return true;
	}

	/* ------------------------------------------------------------- reads */

	public static function stats() {
		global $wpdb;
		$d = self::t_drops();
		$a = self::t_addr();
		return array(
			'drops'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $d WHERE status = 'done'" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $d is self::t_drops() ($wpdb->prefix and a fixed name); the query takes no values
			'planned'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $d WHERE status = 'planned'" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $d is self::t_drops() ($wpdb->prefix and a fixed name); the query takes no values
			'addresses' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $a a INNER JOIN $d d ON d.id = a.drop_id WHERE d.status = 'done' AND a.delivered = 1" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $a and $d are self::t_addr() and self::t_drops() (each $wpdb->prefix and a fixed name); the query takes no values
			'in_progress' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $d WHERE status = 'in_progress'" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $d is self::t_drops() ($wpdb->prefix and a fixed name); the query takes no values
			'streets'   => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::t_streets() ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from self::t_streets() ($wpdb->prefix and a fixed name); the query takes no values
			'hangers'   => (int) $wpdb->get_var( "SELECT COALESCE(SUM(quantity),0) FROM $d WHERE status = 'done'" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $d is self::t_drops() ($wpdb->prefix and a fixed name); the query takes no values
			'cities'    => (int) $wpdb->get_var( "SELECT COUNT(DISTINCT city) FROM $d WHERE city <> '' AND status = 'done'" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $d is self::t_drops() ($wpdb->prefix and a fixed name); the query takes no values
			'zips'      => (int) $wpdb->get_var( "SELECT COUNT(DISTINCT zip) FROM $d WHERE zip <> '' AND status = 'done'" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $d is self::t_drops() ($wpdb->prefix and a fixed name); the query takes no values
		);
	}

	/** Turn a planned route into a completed drop. */
	public static function mark_done( $id, $date, $time, $quantity, $delivered_by ) {
		global $wpdb;
		$fields = array( 'status' => 'done', 'drop_date' => $date );
		if ( $time !== null ) { $fields['drop_time'] = $time; }
		if ( $quantity !== null ) { $fields['quantity'] = $quantity; }
		if ( $delivered_by !== null && $delivered_by !== '' ) { $fields['delivered_by'] = $delivered_by; }
		$ok = false !== $wpdb->update( self::t_drops(), $fields, array( 'id' => (int) $id ) );
		self::walks_all( (int) $id, 'done' );
		return $ok;
	}

	/** iCalendar event for the revisit date of one drop. */
	public static function ics_for_drop( $d ) {
		$due  = $d['revisit_due'] ?: date( 'Y-m-d', strtotime( '+90 days' ) );
		$dt   = str_replace( '-', '', $due );
		$uid  = 'zdh-drop-' . (int) $d['id'] . '@' . wp_parse_url( home_url(), PHP_URL_HOST );
		$sum  = 'Re-hang door hangers: ' . $d['streets_summary'] . ( $d['city'] ? ', ' . $d['city'] : '' ) . ( $d['zip'] ? ' ' . $d['zip'] : '' );
		$desc = 'Last covered ' . $d['drop_date'] . ( $d['delivered_by'] ? ' by ' . $d['delivered_by'] : '' ) . ' (' . (int) $d['address_count'] . ' addresses, ' . $d['hanger_type'] . ').';
		$esc  = function ( $t ) { return str_replace( array( '\\', ';', ',', "\r\n", "\r", "\n" ), array( '\\\\', '\\;', '\\,', '\\n', '\\n', '\\n' ), (string) $t ); };
		$lines = array( 'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Door Hanger Tracker//EN', 'BEGIN:VEVENT', 'UID:' . $uid, 'DTSTAMP:' . gmdate( 'Ymd\THis\Z' ), 'DTSTART;VALUE=DATE:' . $dt, 'DTEND;VALUE=DATE:' . date( 'Ymd', strtotime( $due . ' +1 day' ) ), 'SUMMARY:' . $esc( $sum ), 'DESCRIPTION:' . $esc( $desc ), 'END:VEVENT', 'END:VCALENDAR' );
		return implode( "\r\n", $lines ) . "\r\n";
	}

	private static function decode_drop( $r, $with_geometry = true ) {
		$r['id']            = (int) $r['id'];
		$r['quantity']      = null === $r['quantity'] ? null : (int) $r['quantity'];
		$r['responses']     = null === $r['responses'] ? null : (int) $r['responses'];
		$r['converted']     = null === $r['converted'] ? null : (int) $r['converted'];
		$r['address_count'] = (int) $r['address_count'];
		$r['lat']           = null === $r['lat'] ? null : (float) $r['lat'];
		$r['lng']           = null === $r['lng'] ? null : (float) $r['lng'];
		$r['attachment_ids'] = $r['attachment_ids'] ? array_map( 'intval', (array) json_decode( $r['attachment_ids'], true ) ) : array();
		$r['revisit_days']  = null === $r['revisit_days'] ? null : (int) $r['revisit_days'];
		$r['track']         = ( $with_geometry && ! empty( $r['track_json'] ) ) ? json_decode( $r['track_json'], true ) : null;
		unset( $r['track_json'] );
		if ( ! empty( $r['started_at'] ) && ! empty( $r['finished_at'] ) ) {
			$r['duration_min'] = max( 0, (int) round( ( strtotime( $r['finished_at'] ) - strtotime( $r['started_at'] ) ) / 60 ) );
		} else {
			$r['duration_min'] = null;
		}
		$r['revisit_due']   = ( $r['drop_date'] && $r['revisit_days'] && 'done' === $r['status'] ) ? date( 'Y-m-d', strtotime( $r['drop_date'] . ' +' . (int) $r['revisit_days'] . ' days' ) ) : null;
		$r['enrichment']    = $r['enrichment_json'] ? json_decode( $r['enrichment_json'], true ) : null;
		$r['geometry']      = ( $with_geometry && $r['geometry_json'] ) ? json_decode( $r['geometry_json'], true ) : null;
		$r['walks']         = ! empty( $r['walks_json'] ) ? json_decode( $r['walks_json'], true ) : null;
		unset( $r['walks_json'] );
		$r['analysis']      = ( $with_geometry && $r['analysis_json'] ) ? json_decode( $r['analysis_json'], true ) : null;
		unset( $r['enrichment_json'], $r['geometry_json'], $r['analysis_json'] );
		$r['screenshots']   = array();
		foreach ( $r['attachment_ids'] as $aid ) {
			$url = wp_get_attachment_url( $aid );
			if ( $url ) {
				$r['screenshots'][] = array( 'id' => $aid, 'url' => $url, 'thumb' => wp_get_attachment_image_url( $aid, 'medium' ) ?: $url );
			}
		}
		return $r;
	}

	/** The id of a finished Quick Start walk that began at exactly this time (Y-m-d H:i:s), or 0. */
	public static function done_quick_started( $started_at ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t_drops() . " WHERE method = 'quick' AND status = 'done' AND started_at = %s ORDER BY id ASC LIMIT 1", $started_at ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from self::t_drops() ($wpdb->prefix and a fixed name); values are placeholders
	}

	/** Ids of unfinished Quick Start records that began at exactly this time (Y-m-d H:i:s). */
	public static function live_quick_ids_started( $started_at ) {
		global $wpdb;
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::t_drops() . " WHERE method = 'quick' AND status = 'in_progress' AND started_at = %s", $started_at ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from self::t_drops() ($wpdb->prefix and a fixed name); values are placeholders
	}

	public static function list_drops() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . self::t_drops() . ' ORDER BY drop_date DESC, id DESC', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from self::t_drops() ($wpdb->prefix and a fixed name); the query takes no values
		$cnt  = $wpdb->get_results( 'SELECT drop_id, walk, SUM(delivered) AS delivered, COUNT(*) AS total FROM ' . self::t_addr() . ' GROUP BY drop_id, walk', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from self::t_addr() ($wpdb->prefix and a fixed name); the query takes no values
		$byd  = array(); $byw = array();
		foreach ( $cnt as $c ) {
			$did = (int) $c['drop_id'];
			if ( ! isset( $byd[ $did ] ) ) { $byd[ $did ] = array( 0, 0 ); }
			$byd[ $did ][0] += (int) $c['delivered']; $byd[ $did ][1] += (int) $c['total'];
			$byw[ $did ][ (int) $c['walk'] ] = array( (int) $c['delivered'], (int) $c['total'] );
		}
		$out  = array();
		foreach ( $rows as $r ) {
			$d = self::decode_drop( $r, true );
			if ( 'quick' === $d['method'] && 'in_progress' === $d['status'] && is_array( $d['track'] ) && $d['track'] ) {
				// a live Quick Start walk: what the server has of it, so any device can count or discard it
				$t = $d['track']; $m = 0; $prev = null;
				foreach ( $t as $pt ) { if ( $prev && ( ! isset( $pt[4] ) || 'd' !== $pt[4] ) ) { $m += ZDH_Geo::distance_m( $prev[0], $prev[1], $pt[0], $pt[1] ); } $prev = $pt; }
				$d['track_points'] = count( $t ); $d['track_first'] = (int) $t[0][2]; $d['track_last'] = (int) $t[ count( $t ) - 1 ][2]; $d['track_m'] = (int) round( $m );
			}
			// how far the walk went (every drop with a track) — for the card, the stats and the shared summary.
			// Same rule as the phone: driving points and jumps (> 150 m or > 5 min between fixes) are not walking.
			$d['walked_m'] = null;
			if ( is_array( $d['track'] ) && count( $d['track'] ) > 1 ) {
				$wm = 0; $prev = null;
				foreach ( $d['track'] as $pt ) {
					if ( ! is_array( $pt ) || count( $pt ) < 3 ) { continue; }
					if ( $prev && ( ! isset( $pt[4] ) || 'd' !== $pt[4] ) ) {
						$dd = ZDH_Geo::distance_m( $prev[0], $prev[1], $pt[0], $pt[1] );
						if ( $dd <= 150 && abs( $pt[2] - $prev[2] ) <= 300000 ) { $wm += $dd; }
					}
					$prev = $pt;
				}
				$d['walked_m'] = (int) round( $wm );
			}
			unset( $d['track'] ); // keep the list light
			if ( class_exists( 'ZDH_Sealed' ) ) { $d['sealed'] = ZDH_Sealed::for_drop( $d, get_current_user_id() ); } // Show code
			$d['delivered_count'] = isset( $byd[ $d['id'] ] ) ? $byd[ $d['id'] ][0] : $d['address_count'];
			$d['walk_counts'] = isset( $byw[ $d['id'] ] ) ? $byw[ $d['id'] ] : new stdClass();
			$out[] = $d;
		}
		return $out;
	}

	public static function get_drop( $id ) {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t_drops() . ' WHERE id = %d', (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from self::t_drops() ($wpdb->prefix and a fixed name); values are placeholders
		if ( ! $r ) {
			return null;
		}
		$d              = self::decode_drop( $r, true );
		if ( class_exists( 'ZDH_Sealed' ) ) { $d['sealed'] = ZDH_Sealed::for_drop( $d, get_current_user_id() ); } // Show code
		$d['addresses'] = $wpdb->get_results( $wpdb->prepare( 'SELECT id, house_number, unit, street, street_key, city, state, zip, full_address, lat, lng, source, confidence, road_seg_id, apn, delivered, delivered_at, walk, walk_order FROM ' . self::t_addr() . ' WHERE drop_id = %d ORDER BY street, CAST(house_number AS UNSIGNED)', (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from self::t_addr() ($wpdb->prefix and a fixed name); values are placeholders
		$wc = array();
		foreach ( $d['addresses'] as &$a ) { $a['id'] = (int) $a['id']; $a['delivered'] = (int) $a['delivered']; $a['lat'] = null === $a['lat'] ? null : (float) $a['lat']; $a['lng'] = null === $a['lng'] ? null : (float) $a['lng']; $a['walk'] = max( 1, (int) $a['walk'] ); $a['walk_order'] = (int) $a['walk_order']; if ( ! isset( $wc[ $a['walk'] ] ) ) { $wc[ $a['walk'] ] = array( 0, 0 ); } $wc[ $a['walk'] ][1]++; if ( $a['delivered'] ) { $wc[ $a['walk'] ][0]++; } }
		unset( $a );
		if ( class_exists( 'ZDH_NoSolicit' ) ) { $d['addresses'] = ZDH_NoSolicit::tag( $d['addresses'] ); } // the map draws them red, nothing ticks them
		$d['walk_counts'] = $wc ? $wc : new stdClass();
		$d['delivered_count'] = count( array_filter( $d['addresses'], function ( $a ) { return $a['delivered']; } ) );
		$d['streets']   = $wpdb->get_results( $wpdb->prepare( 'SELECT street, city, zip, coverage, low_number, high_number, address_count FROM ' . self::t_streets() . ' WHERE drop_id = %d ORDER BY street', (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from self::t_streets() ($wpdb->prefix and a fixed name); values are placeholders
		return $d;
	}

	public static function known_cities() {
		global $wpdb;
		$c = $wpdb->get_col( 'SELECT DISTINCT city FROM ' . self::t_addr() . " WHERE city <> '' UNION SELECT DISTINCT city FROM " . self::t_drops() . " WHERE city <> ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names from self::t_addr() and self::t_drops() (each $wpdb->prefix and a fixed name); the query takes no values
		return array_filter( array_map( 'trim', (array) $c ) );
	}

	/**
	 * Look up one address line. Returns a result with status:
	 *   'delivered' (exact address on file), 'likely' (street covered), 'none'.
	 */
	public static function lookup( $line, $near = false ) {
		list( $number, $street, $unit, $zip ) = self::parse_address_line( $line, self::known_cities() );
		if ( ! $near || $number === '' ) {
			return self::lookup_parsed( $line, $number, $street, $unit, $zip, $near );
		}
		$r = self::lookup_parsed( $line, $number, $street, $unit, $zip, false );
		// a misspelled street ("1667 Examplebry st" for 4400 Example St). Only when the typed street is
		// unknown here (status none) and only for a single typed address. Never a guess:
		//  · the house number must match exactly, the ZIP too when one was typed;
		//  · the names must be close (street_similarity) and one street must be clearly closest — two possible
		//    streets give suggestions, not a correction;
		//  · if the address as typed really exists on the map, it is NOT corrected (a real street that
		//    happens to look like one the team walked).
		if ( 'none' === $r['status'] ) {
			$typed_real = class_exists( 'ZDH_Geo' ) ? ZDH_Geo::geocode_one( $number, $street, $zip ) : null;
			if ( ! $typed_real ) {
				$fix = self::fuzzy_on_file( $number, $street, $zip );
				if ( ( ! $fix || ! empty( $fix['ambiguous'] ) ) && $zip !== '' && class_exists( 'ZDH_Geo' ) ) {
					$region = ZDH_Geo::fuzzy_region_street( $number, $street, $zip );
					if ( $region ) {
						$fix = $region;
					}
				}
				if ( $fix && ! empty( $fix['street'] ) ) {
					$r2 = self::lookup_parsed( $line, $number, $fix['street'], $unit, $zip, true );
					$r2['corrected'] = array( 'typed' => $street, 'street' => $fix['street'], 'how' => $fix['how'], 'source' => $fix['source'] );
					return $r2;
				}
			}
		}
		$r = self::lookup_parsed( $line, $number, $street, $unit, $zip, true );
		if ( isset( $fix ) && $fix && ! empty( $fix['ambiguous'] ) ) {
			$r['suggestions'] = $fix['ambiguous'];
		}
		return $r;
	}

	/* ------------------------------------------------- spelling tolerance */

	/** "oak hollow st" → [ 'oakhollow', 'st' ]: the name without the street type, spaces, hyphens or apostrophes. */
	public static function street_core( $street_key ) {
		$w   = array_values( array_filter( explode( ' ', (string) $street_key ), 'strlen' ) );
		$sfx = array_unique( array_values( self::suffix_map() ) );
		$dir = array_unique( array_values( self::dir_map() ) );
		$type = '';
		$dirs = '';
		if ( count( $w ) > 1 && in_array( end( $w ), $dir, true ) ) {
			$dirs .= array_pop( $w ); // trailing directional
		}
		if ( count( $w ) > 1 && in_array( end( $w ), $sfx, true ) ) {
			$type = array_pop( $w );
		}
		if ( count( $w ) > 1 && in_array( $w[0], $dir, true ) ) {
			$dirs .= array_shift( $w ); // leading directional
		}
		$core = implode( '', $w );
		$core = preg_replace( '/^mount/', 'mt', $core );
		$core = preg_replace( '/^saint/', 'st', $core );
		return array( preg_replace( '/[^a-z0-9]/', '', $core ), $type, $dirs );
	}

	/** Optimal-string-alignment distance (Levenshtein + one swap of neighbours = 1). */
	public static function osa( $a, $b ) {
		$la = strlen( $a ); $lb = strlen( $b );
		if ( abs( $la - $lb ) > 4 ) {
			return 9;
		}
		$d = array();
		for ( $i = 0; $i <= $la; $i++ ) { $d[ $i ][0] = $i; }
		for ( $j = 0; $j <= $lb; $j++ ) { $d[0][ $j ] = $j; }
		for ( $i = 1; $i <= $la; $i++ ) {
			for ( $j = 1; $j <= $lb; $j++ ) {
				$c = $a[ $i - 1 ] === $b[ $j - 1 ] ? 0 : 1;
				$d[ $i ][ $j ] = min( $d[ $i - 1 ][ $j ] + 1, $d[ $i ][ $j - 1 ] + 1, $d[ $i - 1 ][ $j - 1 ] + $c );
				if ( $i > 1 && $j > 1 && $a[ $i - 1 ] === $b[ $j - 2 ] && $a[ $i - 2 ] === $b[ $j - 1 ] ) {
					$d[ $i ][ $j ] = min( $d[ $i ][ $j ], $d[ $i - 2 ][ $j - 2 ] + 1 );
				}
			}
		}
		return $d[ $la ][ $lb ];
	}

	/**
	 * How close is a typed street to a street on file? Lower is closer; null = not the same street.
	 * 0 same name, different type (Ct/Ave) · 1 one slip (typo, swapped letters, missing letter) · 2 shortened
	 * ("Examplebry" → "Examplebury") · 3 two slips on a long name · 4 sounds the same.
	 * Names under 5 letters and numbered streets ("5th") are never corrected — too many real streets sit one letter apart.
	 */
	public static function street_similarity( $typed_key, $file_key ) {
		list( $a, $ta, $da ) = self::street_core( $typed_key );
		list( $b, $tb, $db ) = self::street_core( $file_key );
		if ( $a === '' || $b === '' ) {
			return null;
		}
		if ( $da !== '' && $db !== '' && $da !== $db ) {
			return null; // Camino del Rio N and Camino del Rio S are different streets
		}
		if ( $ta !== '' && $tb !== '' && $ta !== $tb ) {
			return null; // Example Ave and Example Ct are different streets — never swap the type
		}
		if ( $a === $b ) {
			return 0; // "Oak Point" = "Oakpoint", "Mount Example" = "Mt Example", a missing "St"
		}
		if ( strlen( $a ) < 5 || strlen( $b ) < 5 || preg_match( '/^\d/', $a ) || preg_match( '/^\d/', $b ) ) {
			return null;
		}
		$type_pen = 0;
		$d = self::osa( $a, $b );
		$long = min( strlen( $a ), strlen( $b ) );
		if ( 1 === $d ) {
			return 1 + $type_pen;
		}
		// shortened: the typed name is the start of the real one and covers most of it (never "Hill" for "Examplebury")
		if ( strlen( $a ) >= 6 && 0 === strpos( $b, $a ) && strlen( $a ) / strlen( $b ) >= 0.7 ) {
			return 2 + $type_pen;
		}
		if ( 2 === $d && $long >= 8 ) {
			return 3 + $type_pen;
		}
		// sounds the same ("Brookfeild" / "Brookfield", "Tuskan" / "Tuscan"): same sound-code (or its start), first letter equal
		if ( $long >= 6 && $a[0] === $b[0] && $d <= 4 ) {
			$ma = metaphone( $a ); $mb = metaphone( $b );
			if ( $ma !== '' && strlen( $ma ) >= 4 && ( $ma === $mb || 0 === strpos( $mb, $ma ) ) ) {
				return 4 + $type_pen;
			}
		}
		return null;
	}

	/**
	 * The one street among $candidates (street keys) that the typed street most likely means, or
	 * [ 'ambiguous' => [...] ] when two are equally close, or null.
	 */
	public static function fuzzy_pick( $typed_key, $candidates ) {
		$scored = array();
		foreach ( array_unique( (array) $candidates ) as $k ) {
			if ( $k === $typed_key ) {
				continue;
			}
			$sc = self::street_similarity( $typed_key, $k );
			if ( null !== $sc ) {
				$scored[ $k ] = $sc;
			}
		}
		if ( ! $scored ) {
			return null;
		}
		asort( $scored );
		$keys = array();
		foreach ( array_keys( $scored ) as $k ) { // "Mt X Ave" / "Mount X Ave" style twins: keep the first, they are one street
			$dupe = false;
			foreach ( $keys as $kk ) {
				if ( 0 === self::street_similarity( $k, $kk ) ) { $dupe = true; break; }
			}
			if ( ! $dupe ) { $keys[] = $k; }
		}
		if ( count( $keys ) > 1 && $scored[ $keys[1] ] - $scored[ $keys[0] ] < 1 ) {
			return array( 'ambiguous' => array_slice( $keys, 0, 3 ) );
		}
		return array( 'key' => $keys[0], 'score' => $scored[ $keys[0] ] );
	}

	private static function how_text( $score ) {
		$s = floor( $score );
		return array( 0 => 'same name written differently', 1 => 'one letter off', 2 => 'shortened name', 3 => 'two letters off', 4 => 'sounds the same' )[ $s ] ?? 'close spelling';
	}

	/** Streets on file where this exact house number got a hanger (same ZIP when typed) — the safest place to look. */
	public static function fuzzy_on_file( $number, $street, $zip ) {
		global $wpdb;
		$typed = self::street_key( $street );
		if ( $typed === '' || $number === '' ) {
			return null;
		}
		$sql  = 'SELECT DISTINCT a.street_key, a.street FROM ' . self::t_addr() . ' a INNER JOIN ' . self::t_drops() . " d ON d.id = a.drop_id WHERE a.house_number = %s AND d.status IN ('done','planned','in_progress')";
		$args = array( $number );
		if ( $zip !== '' ) {
			$sql   .= ' AND a.zip = %s';
			$args[] = $zip;
		}
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is built above from the table helpers and fixed clauses with %s placeholders; every value is bound here
		$name = array();
		foreach ( $rows as $r ) {
			$name[ $r['street_key'] ] = $r['street'];
		}
		$pick = self::fuzzy_pick( $typed, array_keys( $name ) );
		if ( ! $pick ) {
			return null;
		}
		if ( isset( $pick['ambiguous'] ) ) {
			return array( 'ambiguous' => array_map( function ( $k ) use ( $number, $name ) { return $number . ' ' . $name[ $k ]; }, $pick['ambiguous'] ) );
		}
		return array( 'street' => $name[ $pick['key'] ], 'how' => self::how_text( $pick['score'] ), 'source' => 'routes' );
	}

	/** Nutshell: the delivered rows (same number, same ZIP) whose street is the one a misspelled Nutshell address means. */
	public static function fuzzy_rows_for( $typed_street_key, $rows ) {
		$keys = array();
		foreach ( $rows as $r ) {
			$keys[] = (string) $r['street_key'];
		}
		$pick = self::fuzzy_pick( $typed_street_key, $keys );
		if ( ! $pick || isset( $pick['ambiguous'] ) ) {
			return array();
		}
		return array_values( array_filter( $rows, function ( $r ) use ( $pick ) { return (string) $r['street_key'] === $pick['key']; } ) );
	}

	public static function lookup_parsed( $line, $number, $street, $unit, $zip, $near ) {
		global $wpdb;
		$street_key = self::street_key( $street );
		$result     = array(
			'query'   => $line,
			'parsed'  => array( 'number' => $number, 'street' => $street, 'unit' => $unit, 'zip' => $zip ),
			'status'  => 'none',
			'matches' => array(),
			'streets' => array(),
		);
		if ( $street_key === '' ) {
			$result['status'] = 'invalid';
			return $result;
		}

		$drops_t = self::t_drops();
		$addr_t  = self::t_addr();
		$st_t    = self::t_streets();

		if ( $number !== '' ) {
			$mk_exact = self::match_key( $number, $street, '' );
			if ( class_exists( 'ZDH_NoSolicit' ) ) { $result['no_solicit'] = ZDH_NoSolicit::for_key( $unit !== '' ? self::match_key( $number, $street, $unit ) : $mk_exact ); }
			$sql      = "SELECT a.id, a.full_address, a.unit, a.confidence, a.source, a.lat, a.lng, a.zip, d.id AS drop_id, d.drop_date, d.drop_time, d.delivered_by, d.hanger_type, d.quantity, d.service
				FROM $addr_t a INNER JOIN $drops_t d ON d.id = a.drop_id
				WHERE d.status = 'done' AND a.delivered = 1 AND (a.match_key = %s OR a.match_key LIKE %s)";
			$args     = array( $mk_exact, $wpdb->esc_like( $mk_exact ) . ' #%' );
			if ( $zip !== '' ) {
				$sql   .= ' AND a.zip = %s';
				$args[] = $zip;
			}
			$sql .= ' ORDER BY d.drop_date DESC';
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is built above from the table helpers and fixed clauses with %s placeholders; every value is bound here
			if ( $unit !== '' ) {
				// prefer the exact unit, but keep building-level matches visible
				$u = strtolower( preg_replace( '/[^0-9a-z]/i', '', $unit ) );
				usort( $rows, function ( $a, $b ) use ( $u ) {
					$au = strtolower( preg_replace( '/[^0-9a-z]/i', '', $a['unit'] ) ) === $u ? 0 : 1;
					$bu = strtolower( preg_replace( '/[^0-9a-z]/i', '', $b['unit'] ) ) === $u ? 0 : 1;
					return $au - $bu;
				} );
			}
			if ( $rows ) {
				$result['status']  = 'delivered';
				$result['matches'] = $rows;
			}
		}

		// street-level coverage (works even when the exact house number is not on file)
		$sql  = "SELECT s.street, s.city, s.zip, s.coverage, s.low_number, s.high_number, s.address_count, d.id AS drop_id, d.drop_date, d.delivered_by, d.hanger_type, d.quantity
			FROM $st_t s INNER JOIN $drops_t d ON d.id = s.drop_id WHERE d.status = 'done' AND s.street_key = %s";
		$args = array( $street_key );
		if ( $zip !== '' ) {
			$sql   .= ' AND s.zip = %s';
			$args[] = $zip;
		}
		$sql .= ' ORDER BY d.drop_date DESC';
		$streets = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is built above from the table helpers and fixed clauses with %s placeholders; every value is bound here
		$num_i   = (int) preg_replace( '/[^0-9]/', '', $number );
		foreach ( $streets as &$s ) {
			$in_range = null;
			if ( $num_i > 0 && $s['low_number'] !== null && $s['high_number'] !== null ) {
				$in_range = ( $num_i >= (int) $s['low_number'] && $num_i <= (int) $s['high_number'] );
			}
			$s['number_in_range'] = $in_range;
		}
		unset( $s );
		$result['streets'] = $streets;
		if ( 'none' === $result['status'] && $streets ) {
			// 'likely': the house number sits inside a recorded covered range (or the street was covered end to end and no range is known)
			// 'street_partial': the street is on file but this number falls outside every recorded range
			$likely = false;
			foreach ( $streets as $s ) {
				if ( true === $s['number_in_range'] || ( null === $s['number_in_range'] && 'full' === $s['coverage'] ) ) {
					$likely = true;
				}
			}
			$result['status'] = $likely ? 'likely' : 'street_partial';
		}

		// not delivered (yet)? say so if the house sits on a planned or in-progress cluster — handy on the phone with a customer
		$result['upcoming'] = array();
		$sql  = "SELECT a.full_address, a.unit, a.delivered, d.id AS drop_id, d.name, d.status, d.drop_date, d.delivered_by
			FROM $addr_t a INNER JOIN $drops_t d ON d.id = a.drop_id
			WHERE d.status IN ('planned','in_progress') AND a.street_key = %s";
		$args = array( $street_key );
		if ( $number !== '' ) {
			$sql   .= ' AND a.house_number = %s';
			$args[] = $number;
		}
		if ( $zip !== '' ) {
			$sql   .= ' AND a.zip = %s';
			$args[] = $zip;
		}
		$sql .= ' ORDER BY d.drop_date ASC LIMIT 5';
		$up   = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is built above from the table helpers and fixed clauses with %s placeholders; every value is bound here
		if ( $up ) {
			$result['upcoming'] = $up;
			if ( 'none' === $result['status'] ) {
				$result['status'] = 'planned';
			}
		}

		// not delivered for sure? find the house on the map and say how close a delivered route came
		if ( $near && 'delivered' !== $result['status'] && $number !== '' && class_exists( 'ZDH_Geo' ) ) {
			$g = ZDH_Geo::geocode_one( $number, $street, $zip );
			if ( $g ) {
				// the platform geocoder's coordinates stay on the server: the card gets the label and the distances
				$result['geo']    = empty( $g['private'] ) ? array( 'lat' => $g['lat'], 'lng' => $g['lng'], 'label' => $g['label'] ) : array( 'lat' => null, 'lng' => null, 'label' => $g['label'] );
				$result['nearby'] = self::nearby_delivered( $g['lat'], $g['lng'] );
			} else {
				$result['geo'] = null; // not an address point on the map (typo, new build, or outside the address source)
			}
		}
		return $result;
	}

	/**
	 * How close did the delivered routes come to a point?
	 * Looks at delivered houses of completed batches within ~1.2 km and at their walked GPS tracks.
	 * Returns nearest houses (≤ 3, with batch), how many delivered houses lie within 150 m, and the closest walked track.
	 */
	public static function nearby_delivered( $lat, $lng ) {
		global $wpdb;
		$lat     = (float) $lat;
		$lng     = (float) $lng;
		$dlat    = 0.011;
		$dlng    = 0.011 / max( 0.2, cos( deg2rad( $lat ) ) );
		$drops_t = self::t_drops();
		$addr_t  = self::t_addr();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $addr_t and $drops_t are self::t_addr() and self::t_drops() (each $wpdb->prefix and a fixed name); the box bounds are %f placeholders
		$rows    = $wpdb->get_results( $wpdb->prepare(
			"SELECT a.full_address, a.lat, a.lng, d.id AS drop_id, d.name, d.drop_date, d.drop_time, d.delivered_by, d.method
			FROM $addr_t a INNER JOIN $drops_t d ON d.id = a.drop_id
			WHERE d.status = 'done' AND a.delivered = 1 AND a.lat BETWEEN %f AND %f AND a.lng BETWEEN %f AND %f",
			$lat - $dlat, $lat + $dlat, $lng - $dlng, $lng + $dlng
		), ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$near  = array();
		$close = 0;
		$drops = array();
		foreach ( (array) $rows as $r ) {
			$m = ZDH_Geo::distance_m( $lat, $lng, (float) $r['lat'], (float) $r['lng'] );
			if ( $m <= 150 ) {
				$close++;
			}
			$r['dist_m']            = (int) round( $m );
			$near[]                 = $r;
			$drops[ $r['drop_id'] ] = true;
		}
		usort( $near, function ( $a, $b ) { return $a['dist_m'] - $b['dist_m']; } );
		$out = array( 'houses' => array_slice( $near, 0, 3 ), 'within_150' => $close, 'track' => null );
		foreach ( $out['houses'] as &$h ) {
			unset( $h['lat'], $h['lng'] );
		}
		unset( $h );
		// the walked GPS track of those batches (Quick walks and walked clusters): how close did the walker pass?
		if ( $drops ) {
			$ids    = array_map( 'intval', array_keys( $drops ) );
			$tracks = $wpdb->get_results( 'SELECT id, name, drop_date, track_json FROM ' . $drops_t . ' WHERE id IN (' . implode( ',', $ids ) . ") AND track_json IS NOT NULL AND track_json <> ''", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $drops_t is self::t_drops() ($wpdb->prefix and a fixed name); $ids is a list of ints (array_map intval)
			foreach ( (array) $tracks as $t ) {
				$pts = json_decode( (string) $t['track_json'], true );
				if ( ! is_array( $pts ) ) {
					continue;
				}
				$best = null;
				foreach ( $pts as $pt ) {
					if ( ! is_array( $pt ) || count( $pt ) < 2 || ( isset( $pt[4] ) && 'd' === $pt[4] ) ) {
						continue; // driving points don't count
					}
					if ( abs( $pt[0] - $lat ) > 0.004 || abs( $pt[1] - $lng ) > 0.005 ) {
						continue;
					}
					$m = ZDH_Geo::distance_m( $lat, $lng, (float) $pt[0], (float) $pt[1] );
					if ( null === $best || $m < $best ) {
						$best = $m;
					}
				}
				if ( null !== $best && ( null === $out['track'] || $best < $out['track']['dist_m'] ) ) {
					$out['track'] = array( 'drop_id' => (int) $t['id'], 'name' => $t['name'], 'drop_date' => $t['drop_date'], 'dist_m' => (int) round( $best ) );
				}
			}
		}
		return $out;
	}

	/* ----------------------------------------------------------- exports */

	public static function export_rows( $type ) {
		global $wpdb;
		$drops_t = self::t_drops();
		$addr_t  = self::t_addr();
		if ( 'drops' === $type ) {
			$rows = $wpdb->get_results( "SELECT * FROM $drops_t ORDER BY drop_date, id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $drops_t is self::t_drops() ($wpdb->prefix and a fixed name); the query takes no values
			$out  = array();
			foreach ( $rows as $r ) {
				$e     = $r['enrichment_json'] ? json_decode( $r['enrichment_json'], true ) : array();
				$out[] = array_merge( array(
					'drop_id'         => $r['id'],
					'name'            => $r['name'],
					'status'          => $r['status'],
					'started_at'      => $r['started_at'],
					'finished_at'     => $r['finished_at'],
					'duration_min'    => ( $r['started_at'] && $r['finished_at'] ) ? max( 0, (int) round( ( strtotime( $r['finished_at'] ) - strtotime( $r['started_at'] ) ) / 60 ) ) : '',
					'drop_date'       => $r['drop_date'],
					'day_of_week'     => $r['drop_date'] ? date( 'l', strtotime( $r['drop_date'] ) ) : '',
					'drop_time'       => $r['drop_time'],
					'delivered_by'    => $r['delivered_by'],
					'hanger_type'     => $r['hanger_type'],
					'hanger_side'     => $r['hanger_side'],
					'revisit_days'    => $r['revisit_days'],
					'revisit_due'     => ( $r['drop_date'] && $r['revisit_days'] ) ? date( 'Y-m-d', strtotime( $r['drop_date'] . ' +' . (int) $r['revisit_days'] . ' days' ) ) : '',
					'method'          => $r['method'],
					'service'         => $r['service'],
					'quantity'        => $r['quantity'],
					'address_count'   => $r['address_count'],
					'streets'         => $r['streets_summary'],
					'city'            => $r['city'],
					'state'           => $r['state'],
					'zip'             => $r['zip'],
					'county'          => $r['county'],
					'lat'             => $r['lat'],
					'lng'             => $r['lng'],
					'responses'       => $r['responses'],
					'converted'       => $r['converted'],
					'notes'           => $r['notes'],
					'screenshots'     => self::attachment_urls( $r['attachment_ids'] ),
					'logged_by'       => self::user_name( $r['created_by'] ),
					'created_at'      => $r['created_at'],
				), self::flatten_enrichment( $e ) );
			}
			return $out;
		}
		// addresses joined to their drop
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $addr_t and $drops_t are self::t_addr() and self::t_drops() (each $wpdb->prefix and a fixed name); the query takes no values
		$rows = $wpdb->get_results( "SELECT a.*, d.name AS cluster_name, d.status, d.drop_date, d.drop_time, d.delivered_by, d.hanger_type, d.hanger_side, d.service, d.quantity AS drop_quantity, d.enrichment_json, d.attachment_ids
			FROM $addr_t a INNER JOIN $drops_t d ON d.id = a.drop_id ORDER BY d.drop_date, a.drop_id, a.street, CAST(a.house_number AS UNSIGNED)", ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out = array();
		$cache = array();
		foreach ( $rows as $r ) {
			if ( ! isset( $cache[ $r['drop_id'] ] ) ) {
				$e = $r['enrichment_json'] ? json_decode( $r['enrichment_json'], true ) : array();
				$cache[ $r['drop_id'] ] = array( self::flatten_enrichment( $e ), self::attachment_urls( $r['attachment_ids'] ) );
			}
			list( $flat, $shots ) = $cache[ $r['drop_id'] ];
			$out[] = array_merge( array(
				'address_id'    => $r['id'],
				'house_number'  => $r['house_number'],
				'unit'          => $r['unit'],
				'street'        => $r['street'],
				'city'          => $r['city'],
				'state'         => $r['state'],
				'zip'           => $r['zip'],
				'full_address'  => $r['full_address'],
				'lat'           => $r['lat'],
				'lng'           => $r['lng'],
				'source'        => $r['source'],
				'confidence'    => $r['confidence'],
				'apn'           => $r['apn'],
				'road_seg_id'   => $r['road_seg_id'],
				'delivered'     => $r['delivered'],
				'delivered_at'  => $r['delivered_at'],
				'walk'          => $r['walk'],
				'walk_order'    => $r['walk_order'],
				'drop_id'       => $r['drop_id'],
				'cluster_name'  => $r['cluster_name'],
				'status'        => $r['status'],
				'drop_date'     => $r['drop_date'],
				'day_of_week'   => $r['drop_date'] ? date( 'l', strtotime( $r['drop_date'] ) ) : '',
				'drop_time'     => $r['drop_time'],
				'delivered_by'  => $r['delivered_by'],
				'hanger_type'   => $r['hanger_type'],
				'hanger_side'   => $r['hanger_side'],
				'service'       => $r['service'],
				'drop_quantity' => $r['drop_quantity'],
				'screenshots'   => $shots,
			), $flat );
		}
		return $out;
	}

	private static function user_name( $user_id ) {
		$u = $user_id ? get_userdata( (int) $user_id ) : null;
		return $u ? $u->display_name : '';
	}

	/** Neutralize spreadsheet formula injection ("=HYPERLINK(...)" etc.) in exported cells. */
	public static function csv_safe( $v ) {
		if ( is_null( $v ) ) {
			return '';
		}
		$v = (string) $v;
		if ( $v !== '' && strpbrk( $v[0], "=+-@\t\r" ) !== false && ! is_numeric( $v ) ) {
			$v = "'" . $v;
		}
		return $v;
	}

	private static function attachment_urls( $json ) {
		$ids  = $json ? (array) json_decode( $json, true ) : array();
		$urls = array();
		foreach ( $ids as $id ) {
			$u = wp_get_attachment_url( (int) $id );
			if ( $u ) {
				$urls[] = $u;
			}
		}
		return implode( ' ', $urls );
	}

	/** Pull the useful numbers out of the enrichment blob into flat CSV columns. */
	public static function flatten_enrichment( $e ) {
		$e = is_array( $e ) ? $e : array();
		$w = isset( $e['weather'] ) && is_array( $e['weather'] ) ? $e['weather'] : array();
		$c = isset( $e['census'] ) && is_array( $e['census'] ) ? $e['census'] : array();
		$s = isset( $e['screenshot'] ) && is_array( $e['screenshot'] ) ? $e['screenshot'] : array();
		$f = isset( $e['fire_history'] ) && is_array( $e['fire_history'] ) ? $e['fire_history'] : array();
		return array(
			'temp_f'                => $w['temp_f'] ?? '',
			'heat_index_f'          => $w['heat_index_f'] ?? '',
			'humidity_pct'          => $w['humidity_pct'] ?? '',
			'daily_high_f'          => $w['daily_high_f'] ?? '',
			'aqi_us'                => $w['aqi_us'] ?? '',
			'wind_mph'              => $w['wind_mph'] ?? '',
			'wind_gusts_mph'        => $w['wind_gusts_mph'] ?? '',
			'wind_direction'        => $w['wind_direction'] ?? '',
			'screenshot_temp_f'     => $s['temperature_f'] ?? '',
			'screenshot_aqi'        => $s['aqi'] ?? '',
			'fire_hazard_zone'      => $e['fire_hazard_zone'] ?? '',
			'elevation_ft'          => $e['elevation_ft'] ?? '',
			'census_tract'          => $c['tract'] ?? '',
			'median_household_income' => $c['median_household_income'] ?? '',
			'median_home_value'     => $c['median_home_value'] ?? '',
			'median_year_built'     => $c['median_year_built'] ?? '',
			'owner_occupied_pct'    => $c['owner_occupied_pct'] ?? '',
			// added at the end so older column positions hold
			'census_level'          => $c['acs_level'] ?? '',
			'past_fires'            => $f['fires'] ?? '',
			'last_fire_year'        => $f['last_fire_year'] ?? '',
			'miles_to_coast'        => $e['miles_to_coast'] ?? '',
		);
	}

	public static function full_backup() {
		global $wpdb;
		return array(
			'exported_at' => current_time( 'mysql' ),
			'version'     => ZDH_VERSION,
			'drops'       => $wpdb->get_results( 'SELECT * FROM ' . self::t_drops() . ' ORDER BY id', ARRAY_A ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from self::t_drops() ($wpdb->prefix and a fixed name); the query takes no values
			'addresses'   => $wpdb->get_results( 'SELECT * FROM ' . self::t_addr() . ' ORDER BY id', ARRAY_A ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from self::t_addr() ($wpdb->prefix and a fixed name); the query takes no values
			'streets'     => $wpdb->get_results( 'SELECT * FROM ' . self::t_streets() . ' ORDER BY id', ARRAY_A ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from self::t_streets() ($wpdb->prefix and a fixed name); the query takes no values
		);
	}
}
