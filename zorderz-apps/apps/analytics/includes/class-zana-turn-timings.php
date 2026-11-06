<?php
/**
 * ZANA_Turn_Timings — every chat turn's timings, kept, so the slow kinds of question can be found and made faster.
 *
 * One row per turn in wp_zana_turn_timings: when it was asked, what KIND of turn it was (a route's name — hours, page,
 * mail_search, roles… — or the model's category), how it ended (ok, refused, failed), how it was answered (route,
 * model, model+charts), the total and model milliseconds, the model's name and what the call cost. Labels and timings
 * only: never the question or the answer, never who asked. Rows older than RETAIN_DAYS are removed once a day.
 *
 * Zorderz → Settings shows the last seven days by kind (count, median and slowest-tenth time, failures, asked again
 * within five minutes) to an administrator; GET {ns}/analytics/timings returns the same, and ?format=csv the rows.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Turn_Timings {

	const RETAIN_DAYS = 90;
	const REASK_S     = 300;
	const CRON        = 'zana_turn_timings_prune';

	/** Statuses whose answer reached the person (the medians are made of them). */
	const ANSWERED = array( 'ok' );
	/** Turns that ended with no answer: a real failure. */
	const FAILED = array( 'failed' );

	/** Tests set "now" (float seconds). */
	public static $now = null;

	public static function now(): float {
		return null !== self::$now ? (float) self::$now : microtime( true );
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'zana_turn_timings';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta( "CREATE TABLE {$t} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			asked_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			kind VARCHAR(40) NOT NULL DEFAULT '',
			status VARCHAR(16) NOT NULL DEFAULT 'ok',
			how VARCHAR(16) NOT NULL DEFAULT '',
			total_ms INT UNSIGNED NULL,
			model_ms INT UNSIGNED NULL,
			model VARCHAR(80) NOT NULL DEFAULT '',
			usd DECIMAL(10,4) NOT NULL DEFAULT 0,
			cut_off TINYINT(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY asked_at (asked_at),
			KEY kind (kind)
		) " . $wpdb->get_charset_collate() . ';' );
	}

	public static function schedule(): void {
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	/** A kind as stored: lowercase letters, digits, spaces, _ and -, at most 40 characters. */
	public static function clean_kind( $k ): string {
		$k = strtolower( trim( (string) $k ) );
		$k = (string) preg_replace( '/[^a-z0-9_ \-]+/', '', $k );
		return substr( trim( (string) preg_replace( '/\s+/', ' ', $k ) ), 0, 40 );
	}

	/**
	 * Keep one turn's row. $t: session_id, started (float seconds), kind, status, how, model_ms, model, usd, cut_off.
	 * Never throws; a site whose table is missing simply keeps nothing.
	 */
	public static function record( array $t ): void {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return;
		}
		$started = (float) ( $t['started'] ?? self::now() );
		$row     = self::row( $t, $started, self::now() );
		try {
			$wpdb->insert( self::table(), $row, array( '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%f', '%d' ) );
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/** The row for a turn (pure). */
	public static function row( array $t, float $started, float $ended ): array {
		$status = in_array( (string) ( $t['status'] ?? 'ok' ), array( 'ok', 'refused', 'failed' ), true ) ? (string) $t['status'] : 'ok';
		return array(
			'session_id' => max( 0, (int) ( $t['session_id'] ?? 0 ) ),
			'asked_at'   => gmdate( 'Y-m-d H:i:s', (int) floor( $started ) ),
			'kind'       => self::clean_kind( $t['kind'] ?? '' ),
			'status'     => $status,
			'how'        => self::clean_kind( $t['how'] ?? '' ),
			'total_ms'   => max( 0, (int) round( ( $ended - $started ) * 1000 ) ),
			'model_ms'   => isset( $t['model_ms'] ) ? max( 0, (int) $t['model_ms'] ) : 0,
			'model'      => mb_substr( (string) preg_replace( '/[^A-Za-z0-9._:\-\/ ]+/', '', (string) ( $t['model'] ?? '' ) ), 0, 80 ),
			'usd'        => round( max( 0.0, (float) ( $t['usd'] ?? 0 ) ), 4 ),
			'cut_off'    => empty( $t['cut_off'] ) ? 0 : 1,
		);
	}

	/** Remove rows older than RETAIN_DAYS. */
	public static function prune(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE asked_at < %s', gmdate( 'Y-m-d H:i:s', time() - self::RETAIN_DAYS * DAY_IN_SECONDS ) ) );   // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** The rows of the last $days days. */
	public static function rows( int $days = 7 ): array {
		global $wpdb;
		$r = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE asked_at >= %s ORDER BY asked_at ASC, id ASC LIMIT 20000', gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS ) ), ARRAY_A );   // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $r ) ? $r : array();
	}

	/** The q-quantile (0..1) of the non-null values, or null. Pure. */
	public static function quantile( array $vals, float $q ): ?float {
		$vals = array_values( array_filter( $vals, function ( $v ) { return null !== $v; } ) );
		if ( ! $vals ) {
			return null;
		}
		sort( $vals );
		$pos = ( count( $vals ) - 1 ) * max( 0.0, min( 1.0, $q ) );
		$lo  = (int) floor( $pos );
		$hi  = (int) ceil( $pos );
		return (float) $vals[ $lo ] + ( (float) $vals[ $hi ] - (float) $vals[ $lo ] ) * ( $pos - $lo );
	}

	/** The rows of a turn asked again (same chat, within REASK_S of the one before): their ids. Pure. */
	public static function reasked( array $rows ): array {
		$by = array();
		foreach ( $rows as $r ) {
			$by[ (int) ( $r['session_id'] ?? 0 ) ][] = $r;
		}
		$out = array();
		foreach ( $by as $sid => $rs ) {
			if ( $sid <= 0 ) {
				continue;
			}
			usort( $rs, function ( $a, $b ) { return strcmp( (string) $a['asked_at'], (string) $b['asked_at'] ) ?: ( (int) $a['id'] <=> (int) $b['id'] ); } );
			for ( $i = 1, $n = count( $rs ); $i < $n; $i++ ) {
				$gap = strtotime( (string) $rs[ $i ]['asked_at'] . ' UTC' ) - strtotime( (string) $rs[ $i - 1 ]['asked_at'] . ' UTC' );
				if ( $gap >= 0 && $gap <= self::REASK_S && (string) $rs[ $i ]['kind'] === (string) $rs[ $i - 1 ]['kind'] ) {
					$out[ (int) $rs[ $i - 1 ]['id'] ] = true;
				}
			}
		}
		return $out;
	}

	/** By kind: n, answered, failed, median and p90 seconds, model seconds, cost, asked again. Slowest total first. Pure over $rows. */
	public static function summary( array $rows ): array {
		$re  = self::reasked( $rows );
		$by  = array();
		foreach ( $rows as $r ) {
			$by[ '' !== (string) $r['kind'] ? (string) $r['kind'] : 'other' ][] = $r;
		}
		$out = array();
		foreach ( $by as $kind => $rs ) {
			$ans   = array_values( array_filter( $rs, function ( $r ) { return in_array( (string) $r['status'], self::ANSWERED, true ); } ) );
			$tot   = array_map( function ( $r ) { return null === $r['total_ms'] ? null : (float) $r['total_ms'] / 1000; }, $ans );
			$mod   = array_map( function ( $r ) { return empty( $r['model_ms'] ) ? null : (float) $r['model_ms'] / 1000; }, $ans );
			$out[ $kind ] = array(
				'kind'      => $kind,
				'n'         => count( $rs ),
				'answered'  => count( $ans ),
				'failed'    => count( array_filter( $rs, function ( $r ) { return in_array( (string) $r['status'], self::FAILED, true ); } ) ),
				'median_s'  => self::quantile( $tot, 0.5 ),
				'p90_s'     => self::quantile( $tot, 0.9 ),
				'model_s'   => self::quantile( $mod, 0.5 ),
				'usd'       => round( array_sum( array_map( function ( $r ) { return (float) $r['usd']; }, $rs ) ), 4 ),
				'reasked'   => count( array_filter( $rs, function ( $r ) use ( $re ) { return isset( $re[ (int) $r['id'] ] ); } ) ),
				'waiting_s' => array_sum( array_map( 'floatval', array_filter( $tot, function ( $v ) { return null !== $v; } ) ) ),
			);
		}
		uasort( $out, function ( $a, $b ) { return $b['waiting_s'] <=> $a['waiting_s']; } );
		return array_values( $out );
	}

	const CSV_COLUMNS = array( 'turn', 'asked_at_hour_utc', 'kind', 'status', 'how', 'total_s', 'model_s', 'model', 'usd', 'cut_off', 'asked_again_5min' );

	/** The rows as CSV text (the hour, not the minute; no session number). Pure over $rows. */
	public static function csv( array $rows ): string {
		$re  = self::reasked( $rows );
		$out = implode( ',', self::CSV_COLUMNS ) . "\n";
		$n   = 0;
		foreach ( $rows as $r ) {
			$cells = array(
				++$n,
				substr( (string) $r['asked_at'], 0, 13 ) . ':00',
				(string) $r['kind'],
				(string) $r['status'],
				(string) $r['how'],
				null === $r['total_ms'] ? '' : number_format( (float) $r['total_ms'] / 1000, 1, '.', '' ),
				empty( $r['model_ms'] ) ? '' : number_format( (float) $r['model_ms'] / 1000, 1, '.', '' ),
				(string) $r['model'],
				number_format( (float) $r['usd'], 4, '.', '' ),
				(int) $r['cut_off'],
				isset( $re[ (int) $r['id'] ] ) ? 1 : 0,
			);
			$out .= implode( ',', array_map( function ( $c ) {
				$c = (string) $c;
				if ( preg_match( '/^[=+\-@]/', $c ) ) {
					$c = "'" . $c;   // never a formula in a spreadsheet
				}
				return preg_match( '/[",\n]/', $c ) ? '"' . str_replace( '"', '""', $c ) . '"' : $c;
			}, $cells ) ) . "\n";
		}
		return $out;
	}

	/** The last seven days by kind, on Zorderz → Settings (after the form), for an administrator. */
	public static function render_panel(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$rows = self::summary( self::rows( 7 ) );
		echo '<h2>' . esc_html__( 'Chat: answer times (last 7 days)', 'zorderz' ) . '</h2>';
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No chat turns recorded yet.', 'zorderz' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr>';
		foreach ( array( __( 'Kind', 'zorderz' ), __( 'Turns', 'zorderz' ), __( 'Median', 'zorderz' ), __( 'Slowest tenth', 'zorderz' ), __( 'Model', 'zorderz' ), __( 'Failed', 'zorderz' ), __( 'Asked again', 'zorderz' ), __( 'Ai cost', 'zorderz' ) ) as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		$s = function ( $v ) { return null === $v ? '—' : number_format( (float) $v, 1 ) . ' s'; };
		foreach ( $rows as $r ) {
			echo '<tr><td>' . esc_html( $r['kind'] ) . '</td><td>' . (int) $r['n'] . '</td><td>' . esc_html( $s( $r['median_s'] ) ) . '</td><td>' . esc_html( $s( $r['p90_s'] ) )
				. '</td><td>' . esc_html( $s( $r['model_s'] ) ) . '</td><td>' . (int) $r['failed'] . '</td><td>' . (int) $r['reasked'] . '</td><td>$' . esc_html( number_format( (float) $r['usd'], 2 ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
