<?php
/**
 * Context data recorded with every drop (free public services, best effort):
 *   - weather at the drop time (Open-Meteo, keyless): temperature, humidity, heat index, daily high
 *   - air quality (Open-Meteo, keyless): US AQI
 *   - elevation (Open-Meteo, keyless)
 *   - neighborhood profile, when the business turns it on (US Census ACS 5-year, block group; needs the free Census
 *     API key): median household income, median home value, median year built, owner-occupied share
 *   - the hazard zone at the spot, from the hazard layers the business names (ZDH_Geo::fire_zone)
 * Times are read in the business's timezone (zdh_timezone()).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDH_Enrich {

	/** The business's timezone name, for the weather service's hours. */
	public static function tz() {
		return function_exists( 'zdh_timezone' ) ? zdh_timezone()->getName() : 'UTC';
	}

	private static function get_json( $url, $timeout = 10 ) {
		$resp = wp_remote_get( $url, array( 'timeout' => $timeout, 'headers' => array( 'User-Agent' => ZDH_Geo::UA ) ) );
		if ( is_wp_error( $resp ) || 200 !== wp_remote_retrieve_response_code( $resp ) ) {
			return null;
		}
		$json = json_decode( wp_remote_retrieve_body( $resp ), true );
		return is_array( $json ) ? $json : null;
	}

	/** NOAA heat index (°F). Only meaningful above ~80°F; below that returns the temperature. */
	public static function heat_index( $t, $rh ) {
		if ( $t === null || $rh === null ) {
			return null;
		}
		if ( $t < 80 ) {
			return round( $t, 1 );
		}
		$hi = -42.379 + 2.04901523 * $t + 10.14333127 * $rh - 0.22475541 * $t * $rh - 0.00683783 * $t * $t
			- 0.05481717 * $rh * $rh + 0.00122874 * $t * $t * $rh + 0.00085282 * $t * $rh * $rh - 0.00000199 * $t * $t * $rh * $rh;
		if ( $rh < 13 && $t >= 80 && $t <= 112 ) {
			$hi -= ( ( 13 - $rh ) / 4 ) * sqrt( ( 17 - abs( $t - 95 ) ) / 17 );
		} elseif ( $rh > 85 && $t >= 80 && $t <= 87 ) {
			$hi += ( ( $rh - 85 ) / 10 ) * ( ( 87 - $t ) / 5 );
		}
		return round( $hi, 1 );
	}

	public static function weather( $lat, $lng, $date, $time ) {
		if ( ! $date ) {
			return null;
		}
		$hour = 12;
		if ( preg_match( '/^(\d{1,2}):(\d{2})/', (string) $time, $m ) ) {
			$hour = min( 23, max( 0, (int) $m[1] ) );
		}
		$age_days = ( time() - strtotime( $date . ' 12:00:00' ) ) / 86400;
		$common   = '&hourly=temperature_2m,relative_humidity_2m,apparent_temperature,wind_speed_10m,wind_gusts_10m,wind_direction_10m&daily=temperature_2m_max,temperature_2m_min,wind_speed_10m_max&temperature_unit=fahrenheit&wind_speed_unit=mph&timezone=' . rawurlencode( self::tz() );
		$urls     = array();
		if ( $age_days > 6 ) {
			$urls[] = 'https://archive-api.open-meteo.com/v1/archive?latitude=' . $lat . '&longitude=' . $lng . '&start_date=' . $date . '&end_date=' . $date . $common;
		}
		$urls[] = 'https://api.open-meteo.com/v1/forecast?latitude=' . $lat . '&longitude=' . $lng . '&start_date=' . $date . '&end_date=' . $date . $common;
		$out = null;
		foreach ( $urls as $url ) {
			$j = self::get_json( $url );
			if ( ! $j || empty( $j['hourly']['time'] ) ) {
				continue;
			}
			$idx = null;
			foreach ( $j['hourly']['time'] as $i => $t ) {
				if ( 0 === strpos( $t, $date ) && (int) substr( $t, 11, 2 ) === $hour ) {
					$idx = $i;
					break;
				}
			}
			if ( null === $idx ) {
				continue;
			}
			$temp = $j['hourly']['temperature_2m'][ $idx ] ?? null;
			$rh   = $j['hourly']['relative_humidity_2m'][ $idx ] ?? null;
			if ( null === $temp ) {
				continue;
			}
			$out = array(
				'temp_f'        => round( (float) $temp, 1 ),
				'humidity_pct'  => null === $rh ? null : (int) round( $rh ),
				'apparent_f'    => isset( $j['hourly']['apparent_temperature'][ $idx ] ) ? round( (float) $j['hourly']['apparent_temperature'][ $idx ], 1 ) : null,
				'heat_index_f'  => self::heat_index( (float) $temp, null === $rh ? null : (float) $rh ),
				'daily_high_f'  => isset( $j['daily']['temperature_2m_max'][0] ) ? round( (float) $j['daily']['temperature_2m_max'][0], 1 ) : null,
				'daily_low_f'   => isset( $j['daily']['temperature_2m_min'][0] ) ? round( (float) $j['daily']['temperature_2m_min'][0], 1 ) : null,
				'wind_mph'      => isset( $j['hourly']['wind_speed_10m'][ $idx ] ) ? round( (float) $j['hourly']['wind_speed_10m'][ $idx ], 1 ) : null,
				'wind_gusts_mph'=> isset( $j['hourly']['wind_gusts_10m'][ $idx ] ) ? round( (float) $j['hourly']['wind_gusts_10m'][ $idx ], 1 ) : null,
				'wind_direction'=> isset( $j['hourly']['wind_direction_10m'][ $idx ] ) ? self::compass( (float) $j['hourly']['wind_direction_10m'][ $idx ] ) : null,
				'daily_wind_max_mph' => isset( $j['daily']['wind_speed_10m_max'][0] ) ? round( (float) $j['daily']['wind_speed_10m_max'][0], 1 ) : null,
				'hour_used'     => $hour,
				'source'        => 'open-meteo',
			);
			break;
		}
		// Air quality (US AQI) for the same hour
		$aq = self::get_json( 'https://air-quality-api.open-meteo.com/v1/air-quality?latitude=' . $lat . '&longitude=' . $lng . '&hourly=us_aqi&start_date=' . $date . '&end_date=' . $date . '&timezone=' . rawurlencode( self::tz() ) );
		if ( $aq && ! empty( $aq['hourly']['time'] ) ) {
			foreach ( $aq['hourly']['time'] as $i => $t ) {
				if ( (int) substr( $t, 11, 2 ) === $hour && isset( $aq['hourly']['us_aqi'][ $i ] ) && null !== $aq['hourly']['us_aqi'][ $i ] ) {
					if ( null === $out ) {
						$out = array( 'source' => 'open-meteo', 'hour_used' => $hour );
					}
					$out['aqi_us'] = (int) round( $aq['hourly']['us_aqi'][ $i ] );
					break;
				}
			}
		}
		return $out;
	}

	/** 0–360° -> 16-point compass label (the breeze direction, for the log). */
	public static function compass( $deg ) {
		$dirs = array( 'N', 'NNE', 'NE', 'ENE', 'E', 'ESE', 'SE', 'SSE', 'S', 'SSW', 'SW', 'WSW', 'W', 'WNW', 'NW', 'NNW' );
		return $dirs[ (int) round( fmod( $deg, 360 ) / 22.5 ) % 16 ];
	}

	public static function elevation_ft( $lat, $lng ) {
		$j = self::get_json( 'https://api.open-meteo.com/v1/elevation?latitude=' . $lat . '&longitude=' . $lng, 8 );
		if ( $j && isset( $j['elevation'][0] ) ) {
			return (int) round( (float) $j['elevation'][0] * 3.28084 );
		}
		return null;
	}

	/**
	 * The neighborhood figures for a point: the local mirror's, else the Census website (block group from the keyless
	 * geocoder, then ACS 5-year stats; the ACS call needs a free key: Zorderz → Doorhangers). Null when the business
	 * has not turned the figures on.
	 */
	public static function census( $lat, $lng ) {
		$mine = self::census_from_place( ZDH_Local::place( $lat, $lng ) );
		if ( $mine ) {
			return $mine;
		}
		return self::census_on() ? self::census_from_website( $lat, $lng ) : null;
	}

	/** Has the business turned the US Census figures on? (A US-only public service: off unless chosen.) */
	public static function census_on() {
		$s = function_exists( 'zdh_get_settings' ) ? zdh_get_settings() : array();
		return ! empty( $s['census_on'] );
	}

	/**
	 * The walk log's neighborhood figures from the local mirror's answer (ZDH_Local::place): the ACS figures for the
	 * ZIP the drop is in, in the same fields the Census website gives for a block group, with acs_level 'ZIP' said so.
	 * Null when the mirror had no figures for the place.
	 */
	public static function census_from_place( $p ) {
		if ( ! is_array( $p ) || empty( $p['zip'] ) || empty( $p['zip_facts'] ) || ! is_array( $p['zip_facts'] ) ) {
			return null;
		}
		$f   = $p['zip_facts'];
		$int = function ( $k ) use ( $f ) {
			return isset( $f[ $k ] ) && is_numeric( $f[ $k ] ) && $f[ $k ] >= 0 ? (int) round( $f[ $k ] ) : null;
		};
		$out = array(
			'zip'                     => (string) $p['zip'],
			'acs_year'                => is_numeric( $p['acs_year'] ?? null ) ? (int) $p['acs_year'] : null,
			'acs_level'               => 'ZIP',
			'median_household_income' => $int( 'income' ),
			'median_home_value'       => $int( 'home_value' ),
			'median_year_built'       => $int( 'year_built' ),
			'owner_occupied_pct'      => isset( $f['owner_pct'] ) && is_numeric( $f['owner_pct'] ) ? round( (float) $f['owner_pct'], 1 ) : null,
			'population'              => $int( 'population' ),
			'source'                  => 'local mirror (ACS 5-year, ZIP ' . (string) $p['zip'] . ')',
		);
		if ( null === $out['median_household_income'] && null === $out['median_home_value'] && null === $out['median_year_built'] && null === $out['owner_occupied_pct'] ) {
			return null;
		}
		return $out;
	}

	/** The Census website: block group from the keyless geocoder, then ACS 5-year (needs a key). */
	public static function census_from_website( $lat, $lng ) {
		$g = self::get_json( 'https://geocoding.geo.census.gov/geocoder/geographies/coordinates?x=' . $lng . '&y=' . $lat . '&benchmark=Public_AR_Current&vintage=Current_Current&format=json', 20 );
		if ( ! $g || empty( $g['result']['geographies'] ) ) {
			return null;
		}
		$geo = $g['result']['geographies'];
		$bg  = null;
		// The geocoder stopped returning a separate "Block Groups" layer; the block layer carries BLKGRP.
		foreach ( array( 'Census Blocks', 'Block Group', 'Census Tracts' ) as $needle ) {
			foreach ( $geo as $name => $items ) {
				if ( false !== stripos( $name, $needle ) && ! empty( $items[0] ) && ! empty( $items[0]['TRACT'] ) ) {
					$bg = $items[0];
					break 2;
				}
			}
		}
		if ( ! $bg ) {
			return null;
		}
		$out = array(
			'state'       => $bg['STATE'] ?? '',
			'county'      => $bg['COUNTY'] ?? '',
			'tract'       => $bg['TRACT'] ?? '',
			'block_group' => $bg['BLKGRP'] ?? '',
			'geoid'       => substr( (string) ( $bg['GEOID'] ?? '' ), 0, 12 ),
		);
		$s = zdh_get_settings();
		if ( ! $s['census_api_key'] ) {
			$out['acs_note'] = 'ACS statistics need a free Census API key (Zorderz → Doorhangers).';
			return $out;
		}
		$key = '&key=' . rawurlencode( $s['census_api_key'] );
		$for = $out['block_group'] !== '' ? 'block%20group:' . rawurlencode( $out['block_group'] ) : 'tract:' . rawurlencode( $out['tract'] );
		$in  = 'state:' . rawurlencode( $out['state'] ) . '%20county:' . rawurlencode( $out['county'] ) . ( $out['block_group'] !== '' ? '%20tract:' . rawurlencode( $out['tract'] ) : '' );
		foreach ( array( 2024, 2023, 2022 ) as $year ) {
			$url = 'https://api.census.gov/data/' . $year . '/acs/acs5?get=B19013_001E,B25077_001E,B25035_001E,B25003_001E,B25003_002E,B01003_001E&for=' . $for . '&in=' . $in . $key;
			$a   = self::get_json( $url, 15 );
			if ( ! $a || count( $a ) < 2 || ! is_array( $a[0] ) ) {
				continue;
			}
			$row = array_combine( $a[0], $a[1] );
			$v   = function ( $k ) use ( $row ) {
				if ( ! isset( $row[ $k ] ) || $row[ $k ] === null || $row[ $k ] === '' || (float) $row[ $k ] < 0 ) {
					return null;
				}
				return (float) $row[ $k ];
			};
			$occupied = $v( 'B25003_001E' );
			$owner    = $v( 'B25003_002E' );
			$out['acs_year']                = $year;
			$out['acs_level']               = $out['block_group'] !== '' ? 'block group' : 'tract';
			$out['median_household_income'] = $v( 'B19013_001E' ) === null ? null : (int) $v( 'B19013_001E' );
			$out['median_home_value']       = $v( 'B25077_001E' ) === null ? null : (int) $v( 'B25077_001E' );
			$out['median_year_built']       = $v( 'B25035_001E' ) === null ? null : (int) $v( 'B25035_001E' );
			$out['owner_occupied_pct']      = ( $occupied && $owner !== null ) ? round( 100 * $owner / $occupied, 1 ) : null;
			$out['population']              = $v( 'B01003_001E' ) === null ? null : (int) $v( 'B01003_001E' );
			break;
		}
		if ( empty( $out['acs_year'] ) ) {
			$out['acs_note'] = 'ACS request failed (check the Census API key).';
		}
		return $out;
	}

	/** Build the whole enrichment blob for a drop row (array from ZDH_DB::get_drop). */
	public static function build( $drop ) {
		$lat = $drop['lat'];
		$lng = $drop['lng'];
		$out = array( 'generated_at' => current_time( 'mysql' ) );
		if ( ! empty( $drop['analysis']['screen_readings'] ) ) {
			$sr = $drop['analysis']['screen_readings'];
			$out['screenshot'] = array( 'clock' => $sr['clock'] ?? null, 'temperature_f' => $sr['temperature_f'] ?? null, 'aqi' => $sr['aqi'] ?? null );
		}
		if ( $lat === null || $lng === null ) {
			return $out;
		}
		$out['weather']          = self::weather( $lat, $lng, $drop['drop_date'], $drop['drop_time'] );
		$out['elevation_ft']     = self::elevation_ft( $lat, $lng );
		// one question to the local mirror gives the ZIP's figures, past fires and the coast
		$place = ZDH_Local::place( $lat, $lng );
		$mine  = self::census_from_place( $place );
		$out['census']           = $mine ? $mine : ( self::census_on() ? self::census_from_website( $lat, $lng ) : null );
		if ( is_array( $place ) ) {
			if ( isset( $place['fires'] ) && is_numeric( $place['fires'] ) ) {
				$out['fire_history'] = array(
					'fires'          => (int) $place['fires'],
					'last_fire_year' => is_numeric( $place['last_fire_year'] ?? null ) ? (int) $place['last_fire_year'] : null,
					'source'         => 'local mirror (fire history)',
				);
			}
			if ( isset( $place['miles_to_coast'] ) && is_numeric( $place['miles_to_coast'] ) ) {
				$out['miles_to_coast'] = round( (float) $place['miles_to_coast'], 1 );
			}
		}
		$fz = ZDH_Geo::fire_zone( $lat, $lng );   // null: no hazard layer is configured
		if ( is_array( $fz ) ) {
			$out['fire_hazard_zone']   = $fz['zone'];
			$out['fire_hazard_source'] = $fz['source'];
		}
		return $out;
	}
}
