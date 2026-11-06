<?php
/**
 * ZJOB_Project_Homes — one home per homeowner and property, and the billing history of a Project.
 *
 * HOMES. A Project is made per estimate, so a homeowner with three estimates has three Projects, each a third of the
 * picture. A HOME groups them, without moving anything: the Projects that share a client and a street are one home,
 * shown as one card with a tab per estimate. Grouping is worked out on read (nothing is rewritten) and is reversible:
 * an administrator turns it off (option zjob_group_homes = '0').
 *   the client   a fact, never a name: the billing system's client id (a billing connector answers zjob_project_client
 *                with it), else the CRM contact on the Project's jobs. A Project with neither stays on its own.
 *   the property the street of the Project's address, normalised (ZJOB_Repair_Log::addr_key: "12 Oak St." and
 *                "12 oak street" are one). A Project whose street is not known stays on its own.
 *
 * BILLING HISTORY. What happened to each of a Project's invoices: sent, viewed, reminded, paid, and the amounts. The
 * billing connector keeps a copy (never a network call while a person waits) and answers zjob_project_invoice_history
 * with the provider's own invoice records (each with its audit log and payments); this class keeps only what happened
 * and when (shape()), and the counts and dates the screen draws (summary()). No name, e-mail or note is kept. Shown only
 * to someone who may see this Project's money; it also tells My next whether a finished Project is invoiced and what
 * is still due (zjob_project_money).
 *
 * @package Zorderz\Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZJOB_Project_Homes {

	const OPT_ON     = 'zjob_group_homes';
	const MAX_EVENTS = 40;
	const MAX_PAYS   = 20;

	public static function init(): void {
		add_filter( 'zjob_project_money', array( __CLASS__, 'money_from_history' ), 20, 2 );
	}

	public static function on(): bool {
		return '0' !== (string) ( function_exists( 'get_option' ) ? get_option( self::OPT_ON, '1' ) : '1' );
	}

	/* ── Homes ─────────────────────────────────────────────────────────────────────────────────────────── */

	/** A Project's client key: the billing client (from a connector), else the CRM contact on its jobs; '' if neither. */
	public static function client_of( string $pid, array $jobs ): string {
		$billing = function_exists( 'apply_filters' ) ? (string) apply_filters( 'zjob_project_client', '', $pid ) : '';
		$billing = (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', $billing );
		if ( '' !== $billing ) {
			return 'bill:' . $billing;
		}
		foreach ( $jobs as $j ) {
			if ( (int) ( $j['crm_contact_id'] ?? 0 ) > 0 ) {
				return 'crm:' . (int) $j['crm_contact_id'];
			}
		}
		return '';
	}

	/** The home a Project belongs to: client and street, or '' (on its own). PURE apart from the street normaliser. */
	public static function home_key( string $client, string $address ): string {
		$street = class_exists( 'ZJOB_Repair_Log' ) ? ZJOB_Repair_Log::addr_key( $address ) : '';
		return ( '' === $client || '' === $street ) ? '' : md5( $client . '|' . $street );
	}

	/**
	 * Group list rows into homes: rows with the same non-empty `home` become one card (the newest first) with its other
	 * parts listed; every other row stays as it is. Order is kept by each home's newest part. PURE.
	 */
	public static function group( array $rows ): array {
		$out   = array();
		$index = array();
		foreach ( $rows as $r ) {
			$h = (string) ( $r['home'] ?? '' );
			if ( '' === $h ) {
				$out[] = $r;
				continue;
			}
			if ( ! isset( $index[ $h ] ) ) {
				$r['parts']  = array();
				$index[ $h ] = count( $out );
				$out[]       = $r;
				continue;
			}
			$out[ $index[ $h ] ]['parts'][] = array( 'id' => $r['id'], 'code' => $r['code'], 'status' => $r['status'], 'created' => $r['created'] ?? '' );
			$out[ $index[ $h ] ]['mine']    = ! empty( $out[ $index[ $h ] ]['mine'] ) || ! empty( $r['mine'] );
		}
		return $out;
	}

	/* ── Billing history (pure) ─────────────────────────────────────────────────────────────────────────── */

	/** A money value ({amount, code} or a string) as a float, or null. PURE. */
	public static function money( $v ): ?float {
		if ( is_array( $v ) ) {
			$v = $v['amount'] ?? null;
		}
		return is_numeric( $v ) ? round( (float) $v, 2 ) : null;
	}

	/**
	 * What one log entry says happened: sent, viewed, reminder, paid, created, or '' for anything else (a settings
	 * change is nothing). The structured fields are read first, then the free text; a person's name is never read.
	 * PURE.
	 */
	public static function kind_of( array $log ): string {
		$pick = static function ( array $keys ) use ( $log ): string {
			$w = array();
			foreach ( $keys as $k ) {
				if ( isset( $log[ $k ] ) && is_string( $log[ $k ] ) && '' !== trim( $log[ $k ] ) ) {
					$w[] = $log[ $k ];
				}
			}
			return $w ? self::kind_of_words( implode( ' ', $w ) ) : '';
		};
		$k = $pick( array( 'event', 'event_type', 'action', 'type', 'log_type', 'activity' ) );
		return '' !== $k ? $k : $pick( array( 'description', 'message', 'label', 'change' ) );
	}

	private static function kind_of_words( string $words ): string {
		$s = strtolower( str_replace( array( '_', '-', '.' ), ' ', $words ) );
		if ( '' === trim( $s ) || preg_match( '/\b(?:enabled?|disabled?|updated?|changed?|edited?|settings?|turned)\b/', $s ) ) {
			return '';
		}
		if ( preg_match( '/\bremind/', $s ) ) {
			return 'reminder';
		}
		if ( preg_match( '/\bview/', $s ) ) {
			return 'viewed';
		}
		if ( preg_match( '/\b(?:payment|paid)\b/', $s ) ) {
			return 'paid';
		}
		if ( preg_match( '/\b(?:sent|send|sending|resent|emailed|email|e mail|mailed)\b/', $s ) ) {
			return 'sent';
		}
		return preg_match( '/\bcreat/', $s ) ? 'created' : '';
	}

	/** When an entry happened ('Y-m-d H:i:s' or 'Y-m-d'), reading seconds or milliseconds since 1970 too; '' if not. PURE. */
	public static function when( array $log ): string {
		foreach ( array( 'created_at', 'occurred_at', 'logged_at', 'timestamp', 'date', 'time', 'created', 'updated' ) as $k ) {
			if ( ! isset( $log[ $k ] ) ) {
				continue;
			}
			$v = $log[ $k ];
			if ( is_string( $v ) && preg_match( '/^(\d{4}-\d{2}-\d{2})(?:[ T](\d{2}:\d{2}(?::\d{2})?))?/', $v, $m ) ) {
				return empty( $m[2] ) ? $m[1] : $m[1] . ' ' . ( 5 === strlen( $m[2] ) ? $m[2] . ':00' : $m[2] );
			}
			if ( is_numeric( $v ) ) {
				$n = (float) $v;
				$n = $n >= 1e12 ? $n / 1000 : $n;
				if ( $n >= 1e9 && $n < 1e10 ) {
					return gmdate( 'Y-m-d H:i:s', (int) $n );
				}
			}
		}
		return '';
	}

	/**
	 * A provider's invoice record (with its log) and payments (null when not read) -> what is kept: { number, status,
	 * amount, paid, due, date_paid, events [{k, at}], log (whether a log came at all: without one, "nothing on file" is
	 * not known), payments [{at, a}] }. Payments come from the payments list, never from the log. PURE.
	 */
	public static function shape( array $inv, ?array $payments ): array {
		$raw = $inv['audit_logs'] ?? ( $inv['auditlogs'] ?? ( $inv['audit_log'] ?? ( $inv['events'] ?? array() ) ) );
		$raw = is_array( $raw ) ? $raw : array();
		foreach ( array( 'audit_logs', 'logs', 'items', 'results' ) as $k ) {
			if ( isset( $raw[ $k ] ) && is_array( $raw[ $k ] ) ) {
				$raw = $raw[ $k ];
				break;
			}
		}
		$events = array();
		foreach ( array_filter( $raw, 'is_array' ) as $log ) {
			$k  = self::kind_of( $log );
			$at = self::when( $log );
			if ( '' !== $k && '' !== $at ) {
				$events[ $k . '|' . $at ] = array( 'k' => $k, 'at' => $at );
			}
		}
		$events = array_values( $events );
		usort( $events, static function ( $a, $b ) {
			return strcmp( $a['at'], $b['at'] );
		} );
		$events = array_slice( $events, -self::MAX_EVENTS );
		$pays   = array();
		foreach ( is_array( $payments ) ? $payments : array() as $p ) {
			if ( ! is_array( $p ) || 1 === (int) ( $p['vis_state'] ?? 0 ) ) {
				continue; // a deleted payment was never paid
			}
			$d = (string) ( $p['date'] ?? '' );
			$a = self::money( $p['amount'] ?? null );
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}/', $d ) && null !== $a ) {
				$pays[] = array( 'at' => substr( $d, 0, 10 ), 'a' => $a );
			}
		}
		usort( $pays, static function ( $a, $b ) {
			return strcmp( $a['at'], $b['at'] );
		} );
		$status = '';
		foreach ( array( 'v3_status', 'display_status', 'payment_status', 'status' ) as $k ) {
			if ( isset( $inv[ $k ] ) && is_string( $inv[ $k ] ) && '' !== trim( $inv[ $k ] ) && ! is_numeric( $inv[ $k ] ) ) {
				$status = substr( strtolower( trim( $inv[ $k ] ) ), 0, 30 );
				break;
			}
		}
		$paid_on = (string) ( $inv['date_paid'] ?? '' );
		return array(
			'number'    => substr( (string) preg_replace( '/[^A-Za-z0-9\-]/', '', (string) ( $inv['invoice_number'] ?? ( $inv['number'] ?? '' ) ) ), 0, 30 ),
			'status'    => $status,
			'draft'     => 'draft' === $status,
			'amount'    => self::money( $inv['amount'] ?? null ),
			'paid'      => self::money( $inv['paid'] ?? null ),
			'due'       => self::money( $inv['outstanding'] ?? ( $inv['due'] ?? null ) ),
			'date_paid' => preg_match( '/^\d{4}-\d{2}-\d{2}/', $paid_on ) ? substr( $paid_on, 0, 10 ) : '',
			'events'    => $events,
			'log'       => array_key_exists( 'audit_logs', $inv ) || array_key_exists( 'auditlogs', $inv ) || array_key_exists( 'audit_log', $inv ) || array_key_exists( 'events', $inv ),
			'payments'  => array_slice( $pays, -self::MAX_PAYS ),
		);
	}

	/** The counts and dates the screen draws from one kept history. PURE. */
	public static function summary( array $h ): array {
		$out = array( 'sent_count' => 0, 'first_sent' => '', 'last_sent' => '', 'viewed_count' => 0, 'first_viewed' => '', 'last_viewed' => '', 'reminders' => 0, 'last_reminder' => '', 'last_payment' => '' );
		foreach ( (array) ( $h['events'] ?? array() ) as $e ) {
			$k  = (string) ( $e['k'] ?? '' );
			$at = (string) ( $e['at'] ?? '' );
			if ( 'sent' === $k ) {
				$out['sent_count']++;
				$out['first_sent'] = $out['first_sent'] ? $out['first_sent'] : $at;
				$out['last_sent']  = $at;
			} elseif ( 'viewed' === $k ) {
				$out['viewed_count']++;
				$out['first_viewed'] = $out['first_viewed'] ? $out['first_viewed'] : $at;
				$out['last_viewed']  = $at;
			} elseif ( 'reminder' === $k ) {
				$out['reminders']++;
				$out['last_reminder'] = $at;
			}
		}
		$pays = (array) ( $h['payments'] ?? array() );
		$out['last_payment'] = $pays ? (string) ( end( $pays )['at'] ?? '' ) : (string) ( $h['date_paid'] ?? '' );
		return $out;
	}

	/** Invoiced, and what is still due, from kept histories (drafts are not invoices). PURE. */
	public static function money_state( array $histories ): array {
		$inv = array_values( array_filter( $histories, static function ( $h ) {
			return is_array( $h ) && empty( $h['draft'] );
		} ) );
		if ( ! $inv ) {
			return array( 'invoiced' => false, 'due' => null );
		}
		$due   = 0.0;
		$known = false;
		foreach ( $inv as $h ) {
			if ( null !== ( $h['due'] ?? null ) ) {
				$due  += (float) $h['due'];
				$known = true;
			}
		}
		return array( 'invoiced' => true, 'due' => $known ? round( $due, 2 ) : null );
	}

	/** A Project's kept histories from the billing connector (shaped here), or null when nobody answers. */
	public static function histories( string $pid ): ?array {
		$raw = function_exists( 'apply_filters' ) ? apply_filters( 'zjob_project_invoice_history', null, $pid ) : null;
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$out = array();
		foreach ( array_slice( $raw, 0, 20 ) as $r ) {
			if ( is_array( $r ) ) {
				$out[] = self::shape( (array) ( $r['invoice'] ?? $r ), isset( $r['payments'] ) && is_array( $r['payments'] ) ? $r['payments'] : null );
			}
		}
		return $out;
	}

	/** zjob_project_money: My next's money steps, from the kept billing history. */
	public static function money_from_history( $pre, $pid ) {
		if ( is_array( $pre ) ) {
			return $pre;
		}
		$h = self::histories( (string) $pid );
		return null === $h ? $pre : self::money_state( $h );
	}

	/**
	 * The home and billing block of an opened Project for this person: the home's other parts they may open (tabs),
	 * and the billing history only for someone who may see this Project's money.
	 */
	public static function view( array $project, array $jobs, int $uid ): array {
		$pid = (string) ( $project['id'] ?? '' );
		$out = array( 'parts' => array(), 'billing' => null );
		if ( self::on() && class_exists( 'Zjob_Project' ) && class_exists( 'Zjob_Project_Visibility' ) ) {
			$home = self::home_key( self::client_of( $pid, $jobs ), (string) ( $jobs[0]['customer_address'] ?? '' ) );
			if ( '' !== $home ) {
				foreach ( Zjob_Project::list_for( $uid, array( 'limit' => 200 ) ) as $wi ) {
					$other = (string) ( $wi['id'] ?? '' );
					if ( '' === $other || $other === $pid ) {
						continue;
					}
					$oj = Zjob_Project::jobs_for( $other );
					if ( self::home_key( self::client_of( $other, $oj ), (string) ( $oj[0]['customer_address'] ?? '' ) ) === $home ) {
						$out['parts'][] = array( 'id' => $other, 'code' => (string) ( $wi['human_code'] ?? '' ), 'status' => (string) ( $wi['state'] ?? '' ), 'created' => substr( (string) ( $wi['created_at'] ?? '' ), 0, 10 ) );
					}
					if ( count( $out['parts'] ) >= 12 ) {
						break;
					}
				}
			}
		}
		if ( class_exists( 'Zjob_Project_Visibility' ) && Zjob_Project_Visibility::decide_money( $uid, $project ) ) {
			$h = self::histories( $pid );
			if ( null !== $h ) {
				$out['billing'] = array_map( static function ( $x ) {
					return $x + array( 'summary' => self::summary( $x ) );
				}, $h );
			}
		}
		return $out;
	}
}
