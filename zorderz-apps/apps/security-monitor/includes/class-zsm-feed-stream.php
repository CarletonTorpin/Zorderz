<?php
/**
 * ZSM_Feed_Stream — read a very large JSON object or array one top-level record at a time,
 * from a file, without ever holding the whole document in memory.
 *
 * The vulnerability feed is one JSON object keyed by advisory id and runs to well over
 * 100 MB. Decoding it whole inside a web or cron request, on a PHP worker with a few hundred
 * megabytes and a request wall of about a minute, is how a cron job becomes an outage.
 *
 * How: a byte scanner that tracks only "inside a string?" and the nesting depth, jumping over
 * uninteresting bytes with strcspn() (so the PHP loop runs once per quote or bracket, not once
 * per byte). Each value at depth 1 -> 2 is handed to the callback as raw JSON text; the caller
 * decides whether it is worth json_decode()-ing.
 *
 * Resumable: scan() stops at a record boundary when the deadline passes (or the extra stop
 * test says so) and returns the byte offset to resume from. A saved offset is always at depth
 * 1, outside any string, so that offset is the only state a resume needs.
 *
 * Pure PHP, no WordPress.
 *
 * @package Zorderz\Security_Monitor
 */

defined( 'ABSPATH' ) || exit;

final class ZSM_Feed_Stream {

	const CHUNK = 1048576; // 1 MB reads

	/**
	 * @param resource      $fh               readable, seekable file handle
	 * @param int           $offset           0 for a fresh scan, else an offset returned earlier
	 * @param callable      $on_record        function( string $json_text ): void
	 * @param float         $deadline         microtime(true) after which to stop at the next record boundary
	 * @param int           $max_record_bytes a single record larger than this is treated as a damaged feed
	 * @param callable|null $should_stop      optional extra stop test (memory), checked at record boundaries
	 * @return array{offset:int,done:bool,records:int,error:string}
	 */
	public static function scan( $fh, int $offset, callable $on_record, float $deadline, int $max_record_bytes = 8388608, ?callable $should_stop = null ): array {
		$out = array( 'offset' => $offset, 'done' => false, 'records' => 0, 'error' => '' );
		if ( ! is_resource( $fh ) || 0 !== fseek( $fh, $offset ) ) {
			$out['error'] = 'cannot seek feed file';
			return $out;
		}

		$depth     = ( $offset > 0 ) ? 1 : 0; // a saved offset is always inside the root, between records
		$in_string = false;
		$buf       = '';
		$base      = $offset;  // file offset of $buf[0]
		$i         = 0;        // cursor in $buf
		$rec       = -1;       // index in $buf where the current depth-2 value began, -1 = none
		$eof       = false;

		while ( true ) {
			$len = strlen( $buf );
			if ( $i >= $len ) {
				if ( $eof ) {
					break;
				}
				// Compact: keep the record in progress, or (outside a record) nothing before $i.
				$keep = ( $rec >= 0 ) ? $rec : $i;
				if ( $keep > 0 ) {
					$buf   = (string) substr( $buf, $keep );
					$base += $keep;
					$i    -= $keep;
					if ( $rec >= 0 ) {
						$rec = 0;
					}
				}
				if ( $rec >= 0 && strlen( $buf ) > $max_record_bytes ) {
					$out['error'] = 'record larger than ' . $max_record_bytes . ' bytes at offset ' . $base;
					return $out;
				}
				$more = fread( $fh, self::CHUNK ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				if ( false === $more || '' === $more ) {
					$eof = true;
					continue;
				}
				$buf .= $more;
				continue;
			}

			if ( $in_string ) {
				$i += strcspn( $buf, "\"\\", $i );
				if ( $i >= $len ) {
					continue;
				}
				if ( '\\' === $buf[ $i ] ) {
					if ( $i + 1 >= $len ) {
						if ( $eof ) {
							break;
						}
						// The escaped byte is in the next read: read on without consuming the backslash.
						$more = fread( $fh, self::CHUNK ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
						if ( false === $more || '' === $more ) {
							$eof = true;
							continue;
						}
						$buf .= $more;
						continue;
					}
					$i += 2;
					continue;
				}
				$in_string = false; // closing quote
				$i++;
				continue;
			}

			$i += strcspn( $buf, "\"{}[]", $i );
			if ( $i >= $len ) {
				continue;
			}
			$c = $buf[ $i ];

			if ( '"' === $c ) {
				$in_string = true;
				$i++;
				continue;
			}
			if ( '{' === $c || '[' === $c ) {
				$depth++;
				if ( 2 === $depth ) {
					$rec = $i;
				}
				$i++;
				continue;
			}
			// '}' or ']'
			$depth--;
			$i++;
			if ( $depth < 0 ) {
				$out['error'] = 'unbalanced JSON at offset ' . ( $base + $i );
				return $out;
			}
			if ( 1 === $depth && $rec >= 0 ) {
				$on_record( (string) substr( $buf, $rec, $i - $rec ) );
				$rec = -1;
				$out['records']++;
				$out['offset'] = $base + $i; // a safe resume point: depth 1, outside any string
				if ( microtime( true ) >= $deadline || ( null !== $should_stop && $should_stop() ) ) {
					return $out;
				}
				continue;
			}
			if ( 0 === $depth ) {
				$out['offset'] = $base + $i;
				$out['done']   = true;
				return $out;
			}
		}

		// Ran out of file before the root closed.
		$out['error'] = ( $rec >= 0 || $depth > 0 ) ? 'feed file ended mid-document (truncated download?)' : 'feed file is empty or not JSON';
		return $out;
	}

	/**
	 * Cheap pre-filter: the lower-cased "slug" values and whether a "core" software entry is
	 * present, read from the raw text without decoding it. Most of the feed is about software
	 * this site does not have, so most records never reach json_decode().
	 *
	 * @return array{slugs:array<string,bool>,core:bool}
	 */
	public static function peek( string $json ): array {
		$slugs = array();
		if ( preg_match_all( '/"slug"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/', $json, $m ) ) {
			foreach ( $m[1] as $s ) {
				$slugs[ strtolower( stripcslashes( $s ) ) ] = true;
			}
		}
		$core = (bool) preg_match( '/"type"\s*:\s*"core"/i', $json );
		return array( 'slugs' => $slugs, 'core' => $core );
	}
}
