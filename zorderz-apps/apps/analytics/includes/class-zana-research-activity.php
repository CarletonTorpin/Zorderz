<?php
/**
 * ZANA_Research_Activity — what a deep research run is doing, in words, as it does it.
 *
 * A long run that only says "working" looks the same whether it is working, waiting for the server or dead. So every
 * action a run takes is one event: what it is doing, what it asked, how long it took and what came back. The progress
 * card shows the events as they happen; when the run ends, a trail of them is kept with the answer.
 *
 * WHO SEES IT
 *   1. Only the person who started the run: view() gives nothing to anyone else, whatever the caller checked.
 *   2. Nothing in it is something that person could not already see: a step runs as them, so every data permission
 *      they have applies, and web steps run only when they may use web research.
 *   3. It is summarised, never raw: a failure is said in plain words (no provider reply), an answer from the
 *      business's own data only as a short excerpt, and only while the run is live. The trail kept with the answer
 *      has no excerpts: it keeps the questions, the searches and the notes the run wrote, which carry the same
 *      figures its answer states.
 *
 * Kept on the run's own row (ZANA_Research_Store), at most MAX_EVENTS events; the totals are kept apart from the list,
 * so the trail's counts stay true when the list has dropped old events. A run that has ended takes no more events.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Research_Activity {

	const MAX_EVENTS  = 80;
	const LABEL_MAX   = 160;
	const DETAIL_MAX  = 400;
	const EXCERPT_MAX = 280;
	const MARKS_MAX   = 12;

	/** An event in progress with no word for this long is shown as possibly ended by the server. */
	const SILENT_AFTER = 150;

	/** Every kind an event may be (anything else is kept as 'info'). */
	const KINDS = array( 'start', 'plan', 'decide', 'web', 'data', 'note', 'finish', 'condense', 'vet', 'write', 'pause', 'retry', 'error', 'limit', 'done', 'stop', 'info' );

	const STATUSES = array( 'run', 'ok', 'fail', 'skip', 'wait', 'info', 'lost' );

	/* ================================================================== writing */

	/**
	 * Record an event. Returns its index (for done()), or -1 when the run has ended or is gone.
	 *
	 * @param string $status 'run' (in progress), 'ok', 'fail', 'skip', 'wait', 'info'
	 * @param array  $meta   small extras: sources (int), sites (string[]), excerpt (string), verdict, items (string[])
	 */
	public static function add( int $run_id, string $kind, string $label, string $detail = '', string $status = 'ok', array $meta = array() ): int {
		$run = ZANA_Research_Store::get( $run_id );
		if ( ! ZANA_Research_Store::is_live( $run ) ) {
			return -1;
		}
		list( $events, $counts, $idx ) = self::with_event( (array) $run['activity'], (array) $run['counts'], $kind, $label, $detail, $status, $meta, ZANA_Research_Store::now() );
		ZANA_Research_Store::set( $run_id, array( 'activity' => $events, 'counts' => $counts ) );
		return $idx;
	}

	/** Close an event in progress: its outcome, how long it took, and (optionally) new detail, extras and label. */
	public static function done( int $run_id, int $idx, string $status = 'ok', ?string $detail = null, array $meta = array(), ?string $label = null ): void {
		$run = ZANA_Research_Store::get( $run_id );
		if ( ! ZANA_Research_Store::is_live( $run ) || $idx < 0 || ! isset( $run['activity'][ $idx ] ) ) {
			return;
		}
		$events = (array) $run['activity'];
		$e      = $events[ $idx ];
		$e['st']  = self::status( $status );
		$e['dur'] = max( 0, ZANA_Research_Store::now() - (int) ( $e['at'] ?? 0 ) );
		if ( null !== $detail ) {
			$e['d'] = self::clip( $detail, self::DETAIL_MAX );
		}
		if ( null !== $label ) {
			$e['l'] = self::clip( $label, self::LABEL_MAX );
		}
		$e['m']         = array_merge( isset( $e['m'] ) ? (array) $e['m'] : array(), self::meta( $meta ) );
		$events[ $idx ] = $e;
		ZANA_Research_Store::set( $run_id, array( 'activity' => $events, 'counts' => self::tally( (array) $run['counts'], $e ) ) );
	}

	/**
	 * Close the event in progress, if there is one. One opened before $since (an earlier request) belongs to a worker
	 * that is gone: that is 'lost', whatever the caller passed.
	 */
	public static function close_running( int $run_id, string $status, ?string $detail = null, int $since = 0 ): void {
		$run = ZANA_Research_Store::get( $run_id );
		if ( ! ZANA_Research_Store::is_live( $run ) ) {
			return;
		}
		$idx = self::last_running( (array) $run['activity'] );
		if ( $idx < 0 ) {
			return;
		}
		if ( $since > 0 && (int) $run['activity'][ $idx ]['at'] < $since ) {
			$status = 'lost';
			$detail = null;
		}
		if ( 'lost' === $status ) {
			$c         = (array) $run['counts'];
			$c['lost'] = (int) ( $c['lost'] ?? 0 ) + 1;
			ZANA_Research_Store::set( $run_id, array( 'counts' => $c ) );
		}
		self::done( $run_id, $idx, $status, $detail );
	}

	/** A short note on the event in progress: where a long step is ("+36s the records answered"). The newest are kept. */
	public static function mark_running( int $run_id, string $text ): void {
		$text = self::clip( $text, 120 );
		$run  = '' === $text ? null : ZANA_Research_Store::get( $run_id );
		if ( ! ZANA_Research_Store::is_live( $run ) ) {
			return;
		}
		$events = (array) $run['activity'];
		$idx    = self::last_running( $events );
		if ( $idx < 0 ) {
			return;
		}
		$m                   = isset( $events[ $idx ]['m'] ) ? (array) $events[ $idx ]['m'] : array();
		$items               = isset( $m['items'] ) ? (array) $m['items'] : array();
		$items[]             = $text;
		$m['items']          = array_values( array_slice( $items, -self::MARKS_MAX ) );
		$events[ $idx ]['m'] = $m;
		ZANA_Research_Store::set( $run_id, array( 'activity' => $events ) );
	}

	/** Index of the last event still in progress, or -1. */
	public static function last_running( array $events ): int {
		for ( $i = count( $events ) - 1; $i >= 0; $i-- ) {
			if ( 'run' === ( $events[ $i ]['st'] ?? '' ) ) {
				return $i;
			}
		}
		return -1;
	}

	/**
	 * The list and totals with one more event (no storage: the rule itself). A new event while an earlier one is still
	 * in progress means that earlier step never reported back: its request was ended. It is marked 'lost', so nothing
	 * is left spinning for ever.
	 *
	 * @return array { 0: events, 1: counts, 2: the new event's index }
	 */
	public static function with_event( array $events, array $counts, string $kind, string $label, string $detail, string $status, array $meta, int $now ): array {
		$events = array_values( $events );
		foreach ( $events as $i => $e ) {
			if ( 'run' === ( $e['st'] ?? '' ) ) {
				$events[ $i ]['st']  = 'lost';
				$events[ $i ]['dur'] = max( 0, $now - (int) ( $e['at'] ?? $now ) );
				$counts['lost']      = (int) ( $counts['lost'] ?? 0 ) + 1;
			}
		}
		$ev       = array(
			'at'  => $now,
			'k'   => in_array( $kind, self::KINDS, true ) ? $kind : 'info',
			'st'  => self::status( $status ),
			'l'   => self::clip( $label, self::LABEL_MAX ),
			'd'   => self::clip( $detail, self::DETAIL_MAX ),
			'dur' => null,
			'm'   => self::meta( $meta ),
		);
		$events[] = $ev;
		$counts   = self::tally( $counts, $ev );
		if ( count( $events ) > self::MAX_EVENTS ) {
			// the first (the start) and the most recent are kept
			$counts['dropped'] = (int) ( $counts['dropped'] ?? 0 ) + ( count( $events ) - self::MAX_EVENTS );
			$events            = array_merge( array( $events[0] ), array_slice( $events, -( self::MAX_EVENTS - 1 ) ) );
		}
		return array( $events, $counts, count( $events ) - 1 );
	}

	/* ================================================================== reading */

	/**
	 * What the progress card shows while the run is live. Nothing unless $viewer_id started the run.
	 *
	 * @return array { events: [...], now: { label, since, quiet }|null }
	 */
	public static function view( array $run, int $viewer_id ): array {
		if ( (int) ( $run['user_id'] ?? 0 ) <= 0 || (int) $run['user_id'] !== $viewer_id ) {
			return array( 'events' => array(), 'now' => null );
		}
		$events  = array_values( (array) ( $run['activity'] ?? array() ) );
		$started = ZANA_Research_Store::ts( $run['started_at'] ?? '' );
		$now     = ZANA_Research_Store::now();
		$out     = array();
		$open    = 0;
		foreach ( $events as $e ) {
			$out[] = self::public_event( $e, $started, $now, true );
			if ( 'run' === ( $e['st'] ?? '' ) ) {
				$open++;
			}
		}
		// what is happening right now, in words, including what the log cannot record because nothing is running to record it
		$current = null;
		$last    = $events ? $events[ count( $events ) - 1 ] : null;
		if ( $last && 'run' === ( $last['st'] ?? '' ) ) {
			$since   = max( 0, $now - (int) $last['at'] );
			$quiet   = $since >= self::SILENT_AFTER;
			$current = array(
				/* translators: %s: minutes and seconds, like 2:45 */
				'label' => $quiet ? sprintf( __( 'No word from this step for %s. The server may have ended it; it starts again on its own.', 'zorderz' ), self::mmss( $since ) ) : (string) $last['l'],
				'since' => $since,
				'quiet' => $quiet,
			);
		} elseif ( 'awaiting' === ( $run['phase'] ?? '' ) ) {
			$current = array( 'label' => __( 'Waiting for you to start it', 'zorderz' ), 'since' => 0, 'quiet' => false );
		} elseif ( ZANA_Research_Store::is_live( $run ) ) {
			$heard   = max( ZANA_Research_Store::ts( $run['updated_at'] ?? '' ), $last ? (int) $last['at'] + (int) ( $last['dur'] ?? 0 ) : 0 );
			$idle    = max( 0, $now - $heard );
			$current = array(
				'label' => $idle >= 20 ? __( 'Waiting for the server to start the next step', 'zorderz' ) : __( 'Starting the next step', 'zorderz' ),
				'since' => $idle,
				'quiet' => $idle >= 90,
			);
		}
		return array( 'events' => $out, 'now' => $current );
	}

	/** The trail kept with the answer: every event without its excerpt, and the totals. [] when nothing was logged. */
	public static function trail( array $run ): array {
		$events = array_values( (array) ( $run['activity'] ?? array() ) );
		if ( ! $events ) {
			return array();
		}
		$started = ZANA_Research_Store::ts( $run['started_at'] ?? '' );
		$now     = ZANA_Research_Store::now();
		$out     = array();
		foreach ( $events as $e ) {
			$out[] = self::public_event( $e, $started, $now, false );
		}
		return array(
			'v'       => 1,
			'total_s' => max( 0, $now - $started ),
			'counts'  => array_merge( array( 'web' => 0, 'data' => 0, 'note' => 0, 'vet' => 0, 'pause' => 0, 'lost' => 0, 'dropped' => 0 ), array_map( 'intval', (array) ( $run['counts'] ?? array() ) ) ),
			'events'  => $out,
		);
	}

	/* ================================================================== small parts */

	/**
	 * Count an event once it has an outcome: a finished search, data answer or note; a fact check only when it has a
	 * verdict (listing the facts is not a check); a pause.
	 */
	public static function tally( array $c, array $e ): array {
		$k  = (string) ( $e['k'] ?? '' );
		$st = (string) ( $e['st'] ?? '' );
		if ( 'pause' === $k ) {
			$c['pause'] = (int) ( $c['pause'] ?? 0 ) + 1;
		} elseif ( 'ok' === $st && in_array( $k, array( 'web', 'data', 'note' ), true ) ) {
			$c[ $k ] = (int) ( $c[ $k ] ?? 0 ) + 1;
		} elseif ( 'ok' === $st && 'vet' === $k && ! empty( $e['m']['verdict'] ) ) {
			$c['vet'] = (int) ( $c['vet'] ?? 0 ) + 1;
		}
		return $c;
	}

	/** An event as the page gets it. $live = false leaves the excerpt out (the kept trail). */
	public static function public_event( array $e, int $started, int $now, bool $live ): array {
		$m   = isset( $e['m'] ) ? (array) $e['m'] : array();
		$out = array(
			't'   => max( 0, (int) ( $e['at'] ?? 0 ) - $started ),
			'k'   => (string) ( $e['k'] ?? 'info' ),
			'st'  => (string) ( $e['st'] ?? 'ok' ),
			'l'   => (string) ( $e['l'] ?? '' ),
			'd'   => (string) ( $e['d'] ?? '' ),
			'dur' => isset( $e['dur'] ) ? (int) $e['dur'] : ( 'run' === ( $e['st'] ?? '' ) ? max( 0, $now - (int) ( $e['at'] ?? $now ) ) : null ),
		);
		if ( isset( $m['sources'] ) ) {
			$out['sources'] = (int) $m['sources'];
		}
		if ( ! empty( $m['sites'] ) ) {
			$out['sites'] = array_values( array_slice( (array) $m['sites'], 0, 8 ) );
		}
		if ( ! empty( $m['verdict'] ) ) {
			$out['verdict'] = (string) $m['verdict'];
		}
		if ( ! empty( $m['items'] ) ) {
			$out['items'] = array_values( array_slice( (array) $m['items'], 0, 14 ) );
		}
		if ( $live && ! empty( $m['excerpt'] ) ) {
			$out['x'] = (string) $m['excerpt'];
		}
		return $out;
	}

	private static function meta( array $meta ): array {
		$out = array();
		foreach ( $meta as $k => $v ) {
			switch ( $k ) {
				case 'sources':
					$out['sources'] = (int) $v;
					break;
				case 'sites':
					$out['sites'] = array_values( array_unique( self::clip_list( (array) $v, 80 ) ) );
					break;
				case 'excerpt':
					$out['excerpt'] = self::clip( $v, self::EXCERPT_MAX );
					break;
				case 'verdict':
					$out['verdict'] = (string) preg_replace( '/[^a-z_]/', '', strtolower( is_scalar( $v ) ? (string) $v : '' ) );
					break;
				case 'items':
					$out['items'] = self::clip_list( (array) $v, 220 );
					break;
			}
		}
		return $out;
	}

	private static function clip_list( array $list, int $max ): array {
		$out = array();
		foreach ( $list as $s ) {
			$s = self::clip( $s, $max );
			if ( '' !== $s ) {
				$out[] = $s;
			}
		}
		return $out;
	}

	private static function status( string $s ): string {
		return in_array( $s, self::STATUSES, true ) ? $s : 'info';
	}

	/** One line of plain text, cut to length. Nothing is stripped ("< $500" is a figure, not a tag): the page writes every field as text. */
	public static function clip( $s, int $max ): string {
		$s = trim( (string) preg_replace( '/\s+/u', ' ', is_scalar( $s ) ? (string) $s : '' ) );
		if ( mb_strlen( $s ) > $max ) {
			$s = mb_substr( $s, 0, $max - 1 ) . '…';
		}
		return $s;
	}

	public static function mmss( int $s ): string {
		$s = max( 0, $s );
		return floor( $s / 60 ) . ':' . str_pad( (string) ( $s % 60 ), 2, '0', STR_PAD_LEFT );
	}

	/** The site of each address, once each: "sites: a.example, b.example". */
	public static function sites( array $urls ): array {
		$out = array();
		foreach ( $urls as $u ) {
			$u = is_array( $u ) ? (string) ( $u['url'] ?? '' ) : (string) $u;
			$h = '' === $u ? '' : ZANA_Web_Vetting::registrable_domain( $u );
			if ( '' !== $h ) {
				$out[ $h ] = $h;
			}
		}
		return array_values( $out );
	}
}
