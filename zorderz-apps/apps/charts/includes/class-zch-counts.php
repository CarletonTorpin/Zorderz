<?php
/**
 * Measured counts: the real number of pieces on a job, sent to the hub.
 *
 * Invoices often bill a product as one priced line, so the invoice alone cannot say how many
 * pieces were installed. The true count is on the job's measurement note in the CRM, which
 * the Prep app already reads.
 *
 * What it does, on a schedule (hourly, a small batch each time), when the business has named
 * the product the counts are for (Charts → Settings → "Measured counts"):
 *   1. reads, from the Estimates app's own table, only three NUMBERS per estimate: its billing
 *      estimate id and number, and its CRM lead id; the invoice it became comes from the
 *      billing system's own link (ZCH_Feed);
 *   2. asks Prep for that lead's piece count: the same count Prep shows on the job card, from
 *      the same measurement note, with Prep's own rule that the note must name THIS estimate;
 *   3. sends the hub {invoice number: count}, the whole set each time.
 *
 * Nothing else leaves the website: no names, addresses, note text or sizes. The hub accepts
 * this from the scheduled job only, and the scheduled job can read nothing back (hub access
 * floor "cron": health + put_counts). If Prep, the Estimates table, the product setting or the
 * hub is missing, it does nothing.
 *
 * The same scheduled job hands the hub each invoice's lead-source label (ZCH_Counts::sources).
 *
 * @package Zorderz\Charts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZCH_Counts {
	const HOOK         = 'zch_measured_counts';
	const STATE        = 'zch_counts_state';
	const BUDGET_S     = 25;          // inside a host's ~60 s request wall.
	const MAX_LEADS    = 40;          // per run.
	const RECHECK_DAYS = 30;
	const RETRY_DAYS   = 1;           // a lead that could not be read is tried again tomorrow.
	const RESEND_S     = 86400;       // the whole set again at least daily.

	const SOURCES_STATE    = 'zch_sources_state';
	const SOURCES_PER_PART = 1000;     // about 30 KB sealed: well under the hub's 64 KB.
	const LEADS_PART_BYTES = 25000;    // lead names vary in length, so their parts go by size.

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( self::HOOK, array( __CLASS__, 'run_sources' ) );
		add_action( 'admin_post_zch_counts_now', array( __CLASS__, 'run_now' ) );
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::HOOK );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	public static function state() {
		$s = get_option( self::STATE );
		$s = is_array( $s ) ? $s : array();
		return array_merge(
			array(
				'counts'      => array(),
				'checked'     => array(),
				'sent_hash'   => '',
				'sent_at'     => 0,
				'sent_result' => '',
				'last_run'    => 0,
				'last_note'   => '',
				'candidates'  => 0,
				'refused'     => 0,
			),
			$s
		);
	}

	/** Billing document numbers, compared without leading zeros ("0001234" = "1234"). */
	public static function norm_no( $v ) {
		$v = trim( (string) $v );
		if ( ! preg_match( '/^[A-Za-z0-9-]{1,20}$/', $v ) ) {
			return '';
		}
		$t = ltrim( $v, '0' );
		return '' === $t ? '0' : $t;
	}

	/** The product the counts are for (a hub product name), or '' when the business has not set one. */
	public static function product() {
		return trim( (string) ZCH_Store::settings()['counts_product'] );
	}

	/** The word an estimate's items carry for that product ('' = the product name itself). */
	public static function match_word() {
		$w = trim( (string) ZCH_Store::settings()['counts_match'] );
		return '' !== $w ? $w : self::product();
	}

	/**
	 * The estimates worth asking about: made in the Estimates app, linked to a CRM lead and
	 * invoiced, for the counted product. Only numbers are selected.
	 * Returns [invoice_no => [lead_id => estimate_no]], or null when there is nothing to read.
	 */
	public static function candidates() {
		global $wpdb;
		$word = self::match_word();
		if ( '' === $word ) {
			return null;
		}
		$table = $wpdb->prefix . 'zest_estimates';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return null;
		}
		$like = '%' . $wpdb->esc_like( $word ) . '%';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is $wpdb->prefix plus a fixed table name
				"SELECT billing_doc_id, billing_doc_num, crm_lead_id FROM {$table}
				  WHERE crm_lead_id <> '' AND crm_lead_id <> '0' AND billing_doc_num <> '' AND doc_type = 'estimate'
				    AND (items_json LIKE %s OR input_text LIKE %s)
				  ORDER BY id DESC LIMIT 5000", // phpcs:ignore WordPress.DB.PreparedSQL -- fixed table name.
				$like,
				$like
			),
			ARRAY_A
		);
		// The invoice an estimate became comes from the billing system's own link on the invoice
		// (ZCH_Feed), keyed by the estimate's billing id.
		$linked = class_exists( 'ZCH_Feed' ) ? ZCH_Feed::invoices_for_estimates( array_column( (array) $rows, 'billing_doc_id' ) ) : array();
		$out    = array();
		foreach ( (array) $rows as $r ) {
			$inv  = self::norm_no( $linked[ (string) $r['billing_doc_id'] ] ?? '' );
			$lead = (int) $r['crm_lead_id'];
			$est  = trim( (string) $r['billing_doc_num'] );
			if ( '' === $inv || $lead <= 0 || ! preg_match( '/^\d{1,12}$/', $est ) ) {
				continue;
			}
			$out[ $inv ][ $lead ] = $est;
		}
		return $out;
	}

	/**
	 * Prep's piece count for one lead, or an error when it cannot be confirmed. The block is the
	 * one Prep itself cuts from (pick_measurement_block()), counted with Prep's own test when Prep
	 * offers one, else the count Prep shows on the job card. Prep's rule: the note must carry
	 * THIS estimate number.
	 */
	public static function count_for_lead( $crm, $lead_id, $est ) {
		$lead = $crm->get_lead_by_id( (int) $lead_id );
		if ( ! is_array( $lead ) ) {
			return array( 'error' => 'unread' );
		}
		$meta  = isset( $lead['meta'] ) && is_array( $lead['meta'] ) ? $lead['meta'] : array();
		$notes = isset( $lead['notes'] ) && is_array( $lead['notes'] ) ? $lead['notes'] : array();
		$n     = null;
		$named = (string) ( $meta['estimate_number'] ?? '' );
		$cls   = get_class( $crm );
		if ( method_exists( $crm, 'pick_measurement_block' ) && method_exists( $cls, 'measurements_from_block' ) ) {
			$block = (string) $crm->pick_measurement_block( $notes );
			if ( '' !== $block ) {
				if ( method_exists( $cls, 'note_estimate_number' ) ) {
					$in_block = (string) $cls::note_estimate_number( $block );
					if ( '' !== $in_block ) {
						$named = $in_block;
					}
				}
				$n = 0;
				foreach ( (array) $cls::measurements_from_block( $block ) as $m ) {
					$n += (int) ( $m['qty'] ?? 0 );
				}
			}
		}
		if ( null === $n ) {
			$n = (int) ( $meta['cut_count'] ?? ( $meta['screen_count'] ?? 0 ) );
		}
		if ( (string) $est !== $named ) {
			return array( 'error' => 'other_estimate' );
		}
		if ( $n < 1 || $n > 999 ) {
			return array( 'error' => 'no_count' );
		}
		return array( 'count' => $n );
	}

	public static function run_now() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'zorderz' ) );
		}
		check_admin_referer( 'zch_counts_now' );
		self::run();
		self::run_sources( null, time(), true );
		wp_safe_redirect( admin_url( 'admin.php?page=' . ZCH_SLUG . '-settings&counts=1' ) );
		exit;
	}

	/** One scheduled pass. $crm and $client can be injected (tests). */
	public static function run( $crm = null, $client = null, $now = null ) {
		$now             = $now ? $now : time();
		$started         = microtime( true );
		$s               = self::state();
		$s['last_run']   = $now;
		if ( '' === self::product() ) {
			$s['last_note'] = __( 'Measured counts are off (no product is set in Charts → Settings).', 'zorderz' );
			return self::save( $s );
		}
		if ( null === $crm ) {
			if ( ! class_exists( 'ZPREP_Crm' ) ) {
				$s['last_note'] = __( 'The Prep app is not active.', 'zorderz' );
				return self::save( $s );
			}
			$crm = new ZPREP_Crm();
			if ( method_exists( $crm, 'is_ready' ) && ! $crm->is_ready() ) {
				$s['last_note'] = __( 'The CRM is not connected for Prep.', 'zorderz' );
				return self::save( $s );
			}
		}
		$cands = self::candidates();
		if ( null === $cands ) {
			$s['last_note'] = __( 'The Estimates table was not found.', 'zorderz' );
			return self::save( $s );
		}
		$s['candidates'] = count( $cands );

		// Forget invoices that are no longer candidates.
		$s['counts']  = array_intersect_key( $s['counts'], $cands );
		$s['checked'] = array_intersect_key( $s['checked'], $cands );

		// New ones first, then the oldest checks.
		$due = array();
		foreach ( $cands as $inv => $leads ) {
			$at = (int) ( $s['checked'][ $inv ] ?? 0 );
			if ( $at <= $now - self::RECHECK_DAYS * 86400 ) {
				$due[ $inv ] = $at;
			}
		}
		asort( $due );
		$done    = 0;
		$refused = 0;
		foreach ( array_keys( $due ) as $inv ) {
			if ( $done >= self::MAX_LEADS || microtime( true ) - $started > self::BUDGET_S ) {
				break;
			}
			$best   = null;
			$unread = false;
			foreach ( $cands[ $inv ] as $lead => $est ) {
				$done++;
				try {
					$r = self::count_for_lead( $crm, $lead, $est );
				} catch ( \Throwable $e ) {
					$r = array( 'error' => 'unread' );
				}
				if ( isset( $r['count'] ) ) {
					$best = max( (int) $best, $r['count'] );
				} elseif ( 'unread' === $r['error'] ) {
					$unread = true;
				} else {
					$refused++;
				}
			}
			if ( null !== $best ) {
				$s['counts'][ $inv ] = $best;
			} elseif ( ! $unread ) {
				unset( $s['counts'][ $inv ] );
			}
			$s['checked'][ $inv ] = ( null === $best && $unread )
				? $now - ( self::RECHECK_DAYS - self::RETRY_DAYS ) * 86400
				: $now;
		}
		$s['refused']   = $refused;
		/* translators: %d: how many CRM leads were read */
		$s['last_note'] = sprintf( __( 'Read %d lead(s) this run.', 'zorderz' ), $done );

		// Send the whole set when it changed, and at least daily.
		ksort( $s['counts'] );
		$hash = md5( zch_json( $s['counts'] ) );
		if ( $s['counts'] && ( $hash !== $s['sent_hash'] || $now - (int) $s['sent_at'] > self::RESEND_S ) ) {
			$client = $client ? $client : ZCH_Hub_Client::from_config();
			if ( $client ) {
				$reply = $client->call(
					'put_counts',
					array(
						'product'  => self::product(),
						'counts'   => array_map( 'intval', $s['counts'] ),
						'complete' => true,
						'access'   => array( 'caller' => 'cron', 'ops' => array( 'health', 'put_counts' ), 'scope' => null, 'kiosk' => false ),
					),
					'wp_cron',
					function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' )
				);
				if ( ! empty( $reply['ok'] ) ) {
					$s['sent_hash'] = $hash;
					$s['sent_at']   = $now;
					$d              = $reply['data'] ?? array();
					/* translators: 1: counts sent, 2: counts that matched an invoice on the hub */
					$s['sent_result'] = sprintf( __( '%1$d sent, %2$d matched an invoice on the hub', 'zorderz' ), (int) ( $d['stored'] ?? 0 ), (int) ( $d['matched'] ?? 0 ) );
				} else {
					$s['sent_result'] = __( 'not sent:', 'zorderz' ) . ' ' . (string) ( $reply['error']['code'] ?? 'hub unavailable' );
				}
			}
		}
		return self::save( $s );
	}

	/** A short lead-source label, or "Other" for anything that is not one (never a number or an address). */
	public static function clean_label( $label ) {
		$label = trim( preg_replace( '/\s+/', ' ', (string) $label ) );
		if ( '' === $label ) {
			return '';
		}
		if ( ! preg_match( '/^[A-Za-z][A-Za-z0-9 &\'.\/-]{0,39}$/', $label ) || preg_match( '/\d{3,}|@/', $label ) ) {
			return 'Other';
		}
		return $label;
	}

	/**
	 * The billing exports the hub imports carry no lead source, while the site's billing ledger
	 * (the analytics app's) reads one for every invoice. This hands the hub {invoice number: lead
	 * source label}. Labels only; the hub cleans them again and turns anything that is not a
	 * short label into "Other".
	 */
	public static function sources() {
		global $wpdb;
		$table = class_exists( 'ZCH_Ledger' ) ? ZCH_Ledger::table( 'invoice' ) : null;
		if ( ! $table ) {
			return null;
		}
		$rows = $wpdb->get_results(
			"SELECT invoice_number, lead_source FROM {$table} WHERE lead_source <> '' AND invoice_number <> '' AND status <> 0 LIMIT 50000", // phpcs:ignore WordPress.DB.PreparedSQL -- fixed table name, no input.
			ARRAY_A
		);
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$inv   = self::norm_no( $r['invoice_number'] );
			$label = self::clean_label( $r['lead_source'] );
			if ( '' === $inv || '' === $label ) {
				continue;
			}
			$out[ $inv ] = $label;
		}
		ksort( $out );
		return $out;
	}

	/**
	 * The CRM's own list of lead-source names, as the analytics app caches it (a day). A CRM
	 * export may name each lead's source by number only; the hub turns each name into its channel
	 * group and keeps only the group. Labels only, no customer data.
	 */
	public static function source_names() {
		$map = get_transient( 'zana_crm_sources' );
		if ( ( ! is_array( $map ) || ! $map ) && class_exists( 'ZDZ_Core_Nutshell' ) ) {
			try {
				$crm = new ZDZ_Core_Nutshell();
				$map = method_exists( $crm, 'get_source_map' ) ? $crm->get_source_map() : array();
			} catch ( \Throwable $e ) {
				$map = array();
			}
		}
		$map = function_exists( 'apply_filters' ) ? apply_filters( 'zch_crm_source_names', $map ) : $map;
		$out = array();
		foreach ( (array) $map as $id => $name ) {
			$id   = trim( (string) $id );
			$name = trim( preg_replace( '/\s+/', ' ', (string) $name ) );
			if ( ! preg_match( '/^\d{1,10}$/', $id ) || '' === $name || preg_match( '/\d{3,}|@/', $name ) ) {
				continue;
			}
			$out[ $id ] = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 80 ) : substr( $name, 0, 80 );
			if ( count( $out ) >= 2000 ) {
				break;
			}
		}
		ksort( $out );
		return $out;
	}

	public static function run_sources( $client = null, $now = null, $force = false ) {
		$now = $now ? $now : time();
		$st  = get_option( self::SOURCES_STATE );
		$st  = is_array( $st ) ? $st : array( 'hash' => '', 'sent_at' => 0, 'result' => '', 'count' => 0 );
		$src = self::sources();
		if ( null === $src ) {
			$st['result'] = __( 'no billing ledger on this site', 'zorderz' );
			update_option( self::SOURCES_STATE, $st, false );
			return $st;
		}
		$st['count'] = count( $src );
		$names       = self::source_names();
		$st['names'] = count( $names );
		// Each linked job's CRM lead source (ZCH_Feed::lead_names).
		$leads            = class_exists( 'ZCH_Feed' ) ? ZCH_Feed::lead_names() : array();
		$st['lead_names'] = count( $leads );
		$hash             = md5( zch_json( $src ) . '|' . zch_json( $names ) . '|' . zch_json( $leads ) );
		if ( ! $src || ( ! $force && $hash === $st['hash'] && $now - (int) $st['sent_at'] < self::RESEND_S ) ) {
			update_option( self::SOURCES_STATE, $st, false );
			return $st;
		}
		$client = $client ? $client : ZCH_Hub_Client::from_config();
		if ( $client ) {
			// The hub takes at most 64 KB per request: the set goes in parts of one batch, and the
			// hub replaces its copy only when every part has arrived. The jobs' CRM lead sources go
			// in parts of their own, after the invoice sources.
			$chunks  = array_chunk( $src, self::SOURCES_PER_PART, true );
			$lchunks = $leads ? self::chunk_bytes( $leads, self::LEADS_PART_BYTES ) : array();
			$nparts  = count( $chunks ) + count( $lchunks );
			$batch   = bin2hex( random_bytes( 8 ) );
			$reply   = array( 'ok' => false );
			for ( $i = 0; $i < $nparts; $i++ ) {
				$is_src = $i < count( $chunks );
				$body   = array(
					'sources' => $is_src ? $chunks[ $i ] : new stdClass(),
					'batch'   => $batch,
					'part'    => $i + 1,
					'parts'   => $nparts,
				);
				if ( 0 === $i && $names ) {
					$body['source_names'] = $names;
				}
				// Every part names its lead sources (an empty map too), so the hub replaces its set.
				if ( class_exists( 'ZCH_Feed' ) ) {
					$body['lead_names'] = $is_src ? new stdClass() : $lchunks[ $i - count( $chunks ) ];
				}
				$reply = $client->call(
					'put_sources',
					$body + array( 'access' => array( 'caller' => 'cron', 'ops' => array( 'health', 'put_sources' ), 'scope' => null, 'kiosk' => false ) ),
					'wp_cron',
					function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' )
				);
				if ( empty( $reply['ok'] ) ) {
					break;
				}
			}
			if ( ! empty( $reply['ok'] ) && empty( $reply['data']['waiting'] ) ) {
				$st['hash']    = $hash;
				$st['sent_at'] = $now;
				/* translators: 1: labels sent, 2: parts, 3: matched on the hub, 4: CRM source names, 5: jobs with a lead source */
				$st['result'] = sprintf( __( '%1$d sent in %2$d part(s), %3$d matched an invoice on the hub; %4$d CRM source names; %5$d jobs with their lead\'s source', 'zorderz' ), (int) ( $reply['data']['stored'] ?? 0 ), $nparts, (int) ( $reply['data']['matched'] ?? 0 ), count( $names ), count( $leads ) );
			} else {
				$st['result'] = __( 'not sent:', 'zorderz' ) . ' ' . (string) ( $reply['error']['code'] ?? 'hub unavailable' );
			}
		}
		update_option( self::SOURCES_STATE, $st, false );
		return $st;
	}

	/** Split a map into maps of at most $bytes of JSON each (keys kept). */
	public static function chunk_bytes( array $map, $bytes ) {
		$out  = array();
		$cur  = array();
		$size = 2;
		foreach ( $map as $k => $v ) {
			$len = strlen( zch_json( array( (string) $k => $v ) ) ) - 1;
			if ( $cur && $size + $len > $bytes ) {
				$out[] = $cur;
				$cur   = array();
				$size  = 2;
			}
			$cur[ $k ] = $v;
			$size     += $len;
		}
		if ( $cur ) {
			$out[] = $cur;
		}
		return $out;
	}

	private static function save( $s ) {
		update_option( self::STATE, $s, false );
		return $s;
	}

	/** The settings page's status block. */
	public static function status_html() {
		$s    = self::state();
		$when = function ( $t ) {
			return $t ? esc_html( wp_date( 'M j, g:i a', (int) $t ) ) : esc_html__( 'never', 'zorderz' );
		};
		$prod = self::product();
		$h    = '<p><strong>' . esc_html__( 'Measured counts', 'zorderz' ) . '</strong>' . ( '' !== $prod ? ' (' . esc_html( $prod ) . ')' : '' ) . '. '
			. esc_html__( 'Jobs found (invoiced, with a CRM lead):', 'zorderz' ) . ' <strong>' . (int) $s['candidates'] . '</strong>. '
			. esc_html__( 'With a measured count:', 'zorderz' ) . ' <strong>' . count( $s['counts'] ) . '</strong>. '
			. esc_html__( 'Checked so far:', 'zorderz' ) . ' ' . count( $s['checked'] ) . '.</p>'
			. '<p>' . esc_html__( 'Last run:', 'zorderz' ) . ' ' . $when( $s['last_run'] ) . '. ' . esc_html( $s['last_note'] )
			/* translators: %d: leads without a count */
			. ( $s['refused'] ? ' ' . esc_html( sprintf( __( '%d lead(s) had no count or a note for a different estimate.', 'zorderz' ), (int) $s['refused'] ) ) : '' ) . '</p>'
			. '<p>' . esc_html__( 'Last sent to the hub:', 'zorderz' ) . ' ' . $when( $s['sent_at'] ) . ( $s['sent_result'] ? ' (' . esc_html( $s['sent_result'] ) . ')' : '' ) . '.</p>';
		$src  = get_option( self::SOURCES_STATE );
		$src  = is_array( $src ) ? $src : array();
		$h   .= '<p><strong>' . esc_html__( 'Lead sources', 'zorderz' ) . '</strong> ' . esc_html__( '(from the billing ledger):', 'zorderz' ) . ' '
			. (int) ( $src['count'] ?? 0 ) . ' ' . esc_html__( 'invoices', 'zorderz' )
			/* translators: %d: jobs with their CRM lead's source */
			. ( isset( $src['lead_names'] ) ? ', ' . esc_html( sprintf( __( 'plus %d jobs with their lead\'s source', 'zorderz' ), (int) $src['lead_names'] ) ) : '' )
			. '; ' . esc_html__( 'last sent', 'zorderz' ) . ' ' . $when( $src['sent_at'] ?? 0 )
			. ( ! empty( $src['result'] ) ? ' (' . esc_html( $src['result'] ) . ')' : '' ) . '.</p>';
		return $h;
	}
}
