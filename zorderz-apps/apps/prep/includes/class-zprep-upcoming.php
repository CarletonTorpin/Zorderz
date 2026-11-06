<?php
/**
 * ZPREP_Upcoming — Upcoming production: the sold work coming up, the prep sheet each job needs, and by when.
 *
 * WHERE THE LIST COMES FROM. The jobs app's read verb `jobs.upcoming_work` (ZJOB_Upcoming), asked as the person: the
 * sold work whose install falls in the next VERB_DAYS days, and the sold work not yet booked. Kinds, the install day,
 * the town, the installer's initials and what was sold (never an amount, never a customer's name) come from it. A
 * verb that is missing, fails or refuses is said, never shown as "nothing coming up".
 *
 * WHAT IS THE BUSINESS'S (rules.yml prep.*, written into options by its Identity Pack or set by an administrator):
 *   sheets        zprep_prep_sheets: [{ key, label, kinds[], words[], lead_days, capacity, steps[] }] — which prep sheet
 *                 a kind of work gets (a sheet with `words` is offered only when a line names one of them), how many
 *                 working days before the install it must be ready, how many the shop can prepare on one day, and the
 *                 steps printed on it. Core: one sheet per kind of work, ready on the install day, no limit, no steps.
 *   working days  zprep_working_days: ISO weekdays (1 = Monday). Core: every day.
 *   who sees it   zprep_upcoming_audience: 'prep' (everyone who can open Prep, never a grant-only assignee) or 'admin'
 *                 (any other value reads as 'admin': the narrow side). zprep_upcoming_hidden_roles: role slugs never
 *                 shown it, whatever else allows it. Core: 'prep', no role hidden.
 *
 * WHAT IS CORE: asking the verb, the windows (prep-by days counted back over working days), the day buckets and the
 * over-capacity flag, and the rule that printing a prep sheet records nothing.
 *
 * @package Zorderz\Prep
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZPREP_Upcoming {

	const VERB         = 'jobs.upcoming_work';
	const VERB_DAYS    = 30;
	const SHEETS_OPT   = 'zprep_prep_sheets';
	const DAYS_OPT     = 'zprep_working_days';
	const AUDIENCE_OPT = 'zprep_upcoming_audience';
	const HIDDEN_OPT   = 'zprep_upcoming_hidden_roles';
	const MAX_SHEETS   = 30;

	public static function init(): void {
		add_action( 'wp_ajax_zprep_upcoming', array( __CLASS__, 'ajax' ) );
	}

	/* ── The business's rules ─────────────────────────────────────────────────────────────────────────── */

	/** A sheet as data, or null. PURE. */
	public static function clean_sheet( $s ): ?array {
		if ( ! is_array( $s ) ) {
			return null;
		}
		$key   = strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', (string) ( $s['key'] ?? '' ) ) );
		$label = trim( strip_tags( (string) ( $s['label'] ?? '' ) ) );
		if ( '' === $key || '' === $label ) {
			return null;
		}
		$list = static function ( $v, int $max, int $len, bool $slug ): array {
			$out = array();
			foreach ( array_slice( (array) $v, 0, $max ) as $x ) {
				$x = $slug ? strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', (string) $x ) ) : trim( strip_tags( (string) $x ) );
				if ( '' !== $x ) {
					$out[] = substr( $x, 0, $len );
				}
			}
			return array_values( array_unique( $out ) );
		};
		$cap = isset( $s['capacity'] ) && is_numeric( $s['capacity'] ) && (int) $s['capacity'] > 0 ? (int) $s['capacity'] : null;
		return array(
			'key'       => $key,
			'label'     => substr( $label, 0, 80 ),
			'kinds'     => $list( $s['kinds'] ?? array(), 40, 64, true ),
			'words'     => $list( $s['words'] ?? array(), 40, 40, false ),
			'lead_days' => max( 0, min( 30, (int) ( $s['lead_days'] ?? 0 ) ) ),
			'capacity'  => $cap,
			'steps'     => $list( $s['steps'] ?? array(), 30, 200, false ),
		);
	}

	/**
	 * The prep sheets: the business's, else one per kind of work seen ($kinds_seen), labelled by the jobs app's kind
	 * names. PURE apart from the option and the labels.
	 */
	public static function sheets( array $kinds_seen = array() ): array {
		$raw = function_exists( 'get_option' ) ? get_option( self::SHEETS_OPT, array() ) : array();
		$raw = apply_filters( 'zprep_prep_sheets', is_array( $raw ) ? $raw : array() );
		$out = array();
		foreach ( array_slice( (array) $raw, 0, self::MAX_SHEETS ) as $s ) {
			$c = self::clean_sheet( $s );
			if ( $c ) {
				$out[ $c['key'] ] = $c;
			}
		}
		if ( $out ) {
			return array_values( $out );
		}
		foreach ( array_unique( array_map( 'strval', $kinds_seen ) ) as $k ) {
			if ( '' === $k ) {
				continue;
			}
			$out[] = array(
				'key'       => $k,
				'label'     => function_exists( 'zjob_component_label' ) ? zjob_component_label( $k ) : ucwords( str_replace( array( '_', '-' ), ' ', $k ) ),
				'kinds'     => array( $k ),
				'words'     => array(),
				'lead_days' => 0,
				'capacity'  => null,
				'steps'     => array(),
			);
		}
		return $out;
	}

	/** ISO weekdays the shop works (1 = Monday). Core: every day. */
	public static function working_days(): array {
		$raw  = function_exists( 'get_option' ) ? get_option( self::DAYS_OPT, array() ) : array();
		$days = array_values( array_unique( array_filter( array_map( 'intval', (array) $raw ), static function ( $d ) {
			return $d >= 1 && $d <= 7;
		} ) ) );
		return $days ? $days : array( 1, 2, 3, 4, 5, 6, 7 );
	}

	/* ── The windows (pure) ───────────────────────────────────────────────────────────────────────────── */

	/**
	 * The day the prep must be ready: $lead_days working days before the install day (0 = the install day itself,
	 * moved back to a working day when it is not one). '' for an unbooked job. PURE.
	 */
	public static function prep_by( string $install_day, int $lead_days, array $working ): string {
		$d = DateTimeImmutable::createFromFormat( '!Y-m-d', $install_day, new DateTimeZone( 'UTC' ) );
		if ( ! $d ) {
			return '';
		}
		$working = $working ? $working : array( 1, 2, 3, 4, 5, 6, 7 );
		$guard   = 0;
		while ( ! in_array( (int) $d->format( 'N' ), $working, true ) && $guard++ < 14 ) {
			$d = $d->modify( '-1 day' );
		}
		$left = max( 0, $lead_days );
		while ( $left > 0 && $guard++ < 400 ) {
			$d = $d->modify( '-1 day' );
			if ( in_array( (int) $d->format( 'N' ), $working, true ) ) {
				$left--;
			}
		}
		return $d->format( 'Y-m-d' );
	}

	/** Does a line name one of the words (whole words, any case)? PURE. */
	private static function names_word( array $lines, array $words ): bool {
		foreach ( $lines as $ln ) {
			$t = ' ' . strtolower( (string) ( $ln['name'] ?? '' ) . ' ' . (string) ( $ln['description'] ?? '' ) ) . ' ';
			foreach ( $words as $w ) {
				if ( '' !== $w && preg_match( '/(?<![a-z0-9])' . preg_quote( strtolower( $w ), '/' ) . '(?![a-z0-9])/', $t ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * The sheets a job is offered: those of its kinds; where a kind has sheets with words, the ones whose words a line
	 * names, or all of that kind's sheets when no line names any (a person picks). PURE.
	 *
	 * @return string[] sheet keys, in the sheets' order
	 */
	public static function sheets_for( array $kinds, array $lines, array $sheets ): array {
		$want = array();
		foreach ( array_unique( array_map( 'strval', $kinds ) ) as $k ) {
			$mine = array_values( array_filter( $sheets, static function ( $s ) use ( $k ) {
				return in_array( $k, $s['kinds'], true );
			} ) );
			$hit = array();
			foreach ( $mine as $s ) {
				if ( ! $s['words'] || self::names_word( $lines, $s['words'] ) ) {
					$hit[] = $s['key'];
				}
			}
			if ( ! $hit ) {
				$hit = array_column( $mine, 'key' );
			}
			foreach ( $hit as $h ) {
				$want[ $h ] = true;
			}
		}
		return array_values( array_filter( array_column( $sheets, 'key' ), static function ( $key ) use ( $want ) {
			return isset( $want[ $key ] );
		} ) );
	}

	/**
	 * The list as the shop reads it: each job with its sheets and its prep-by day (the earliest of its sheets'), late
	 * when that day is before today; and per prep-by day, how many jobs each sheet has, flagged when over the sheet's
	 * capacity. Unbooked jobs come last, with no day. PURE.
	 *
	 * @return array{items:array,days:array}
	 */
	public static function plan( array $items, array $sheets, string $today, array $working ): array {
		$by_key = array();
		foreach ( $sheets as $s ) {
			$by_key[ $s['key'] ] = $s;
		}
		$out  = array();
		$days = array();
		foreach ( $items as $it ) {
			$keys = self::sheets_for( (array) ( $it['kinds'] ?? array() ), (array) ( $it['lines'] ?? array() ), $sheets );
			$day  = (string) ( $it['install_day'] ?? '' );
			$by   = '';
			foreach ( $keys as $k ) {
				$p = '' !== $day ? self::prep_by( $day, (int) $by_key[ $k ]['lead_days'], $working ) : '';
				if ( '' !== $p && ( '' === $by || $p < $by ) ) {
					$by = $p;
				}
				if ( '' !== $p ) {
					$days[ $p ][ $k ] = ( $days[ $p ][ $k ] ?? 0 ) + 1;
				}
			}
			$it['sheets']  = $keys;
			$it['prep_by'] = $by;
			$it['late']    = '' !== $by && $by < $today;
			$out[]         = $it;
		}
		usort( $out, static function ( $a, $b ) {
			if ( $a['prep_by'] !== $b['prep_by'] ) {
				if ( '' === $a['prep_by'] ) {
					return 1;
				}
				if ( '' === $b['prep_by'] ) {
					return -1;
				}
				return strcmp( $a['prep_by'], $b['prep_by'] );
			}
			return strcmp( (string) $a['install_day'], (string) $b['install_day'] ) ?: strcmp( (string) $a['key'], (string) $b['key'] );
		} );
		ksort( $days );
		$day_rows = array();
		foreach ( $days as $d => $counts ) {
			$row = array( 'day' => $d, 'sheets' => array() );
			foreach ( $counts as $k => $n ) {
				$cap                  = $by_key[ $k ]['capacity'];
				$row['sheets'][ $k ] = array( 'count' => $n, 'capacity' => $cap, 'over' => null !== $cap && $n > $cap );
			}
			$day_rows[] = $row;
		}
		return array( 'items' => $out, 'days' => $day_rows );
	}

	/* ── Who sees it, and the endpoint ────────────────────────────────────────────────────────────────── */

	public static function allowed( int $uid ): bool {
		if ( $uid <= 0 || ! class_exists( 'ZPREP_Dashboard' ) || ! ZPREP_Dashboard::user_can_access( $uid ) ) {
			return false;
		}
		if ( 'assignee' === ZPREP_Dashboard::queue_mode( $uid ) ) {
			return false; // a person who holds only assigned jobs sees only those
		}
		$ok = self::audience( get_option( self::AUDIENCE_OPT, 'prep' ) ) === 'prep'
			? true
			: ( class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_admin( $uid ) );
		// A filter may only narrow.
		$ok = $ok && (bool) apply_filters( 'zprep_upcoming_allowed', true, $uid );
		// After the filter: a hidden role is never shown it.
		if ( $ok && function_exists( 'get_userdata' ) ) {
			$u  = get_userdata( $uid );
			$ok = ! ( $u && array_intersect( self::hidden_roles( get_option( self::HIDDEN_OPT, array() ) ), (array) ( $u->roles ?? array() ) ) );
		}
		return $ok;
	}

	/** 'prep' or 'admin'; anything else (unset excepted) is 'admin'. PURE. */
	public static function audience( $v ): string {
		return ( null === $v || false === $v || '' === $v || 'prep' === $v ) ? 'prep' : 'admin';
	}

	/** Role slugs, cleaned. PURE apart from sanitize_key. */
	public static function hidden_roles( $v ): array {
		$out = array();
		foreach ( array_slice( is_array( $v ) ? array_values( $v ) : array(), 0, 20 ) as $r ) {
			$r = sanitize_key( (string) $r );
			if ( '' !== $r ) {
				$out[] = $r;
			}
		}
		return array_values( array_unique( $out ) );
	}

	public static function ajax(): void {
		check_ajax_referer( ZPREP_NONCE, 'nonce' );
		$uid = (int) get_current_user_id();
		if ( ! self::allowed( $uid ) ) {
			wp_send_json_error( array( 'message' => __( 'Upcoming production is not open to your account.', 'zorderz' ) ), 403 );
		}
		if ( ! class_exists( 'ZDZ_Capabilities' ) || ! ZDZ_Capabilities::get( self::VERB ) ) {
			wp_send_json_error( array( 'message' => __( 'Upcoming production needs the Jobs app, which is not on this site.', 'zorderz' ), 'missing' => true ), 409 );
		}
		$r = ZDZ_Capabilities::invoke( self::VERB, array( 'unscheduled' => true ) );
		if ( empty( $r['success'] ) || ! empty( $r['denied'] ) || ! isset( $r['items'] ) || ! is_array( $r['items'] ) ) {
			wp_send_json_error( array( 'message' => (string) ( $r['message'] ?? ( $r['error'] ?? __( 'Projects did not answer. Try again in a moment.', 'zorderz' ) ) ) ), 502 );
		}
		$kinds = array();
		foreach ( $r['items'] as $it ) {
			foreach ( (array) ( $it['kinds'] ?? array() ) as $k ) {
				$kinds[] = (string) $k;
			}
		}
		$sheets = self::sheets( $kinds );
		$plan   = self::plan( $r['items'], $sheets, (string) ( $r['window']['today'] ?? gmdate( 'Y-m-d' ) ), self::working_days() );
		wp_send_json_success( array(
			'window' => $r['window'] ?? array(),
			'sheets' => $sheets,
			'items'  => $plan['items'],
			'days'   => $plan['days'],
		) );
	}
}
