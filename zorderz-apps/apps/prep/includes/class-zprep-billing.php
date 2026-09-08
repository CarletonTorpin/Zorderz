<?php
/**
 * Zorderz Prep — billing adapter (optional secondary source + approval ground truth).
 *
 * ALL billing transport goes through the shared ZDZ_Core_Freshbooks client (one credential
 * + transport authority). This class adds only the prep-domain logic: look a job up by
 * number / name / phone, list APPROVED estimates for the queue, and promote their CRM
 * leads to the cut stage. The product-line gate is the configurable QUEUE (tag/subtype) —
 * NOT a hardcoded product reference test.
 *
 * @package Zorderz\Prep
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZPREP_Billing {

	/** @var object|null Shared billing client or null when unconfigured. */
	private $core;
	private string $last_error = '';

	public function __construct() {
		$this->core = ZPREP_Settings::billing();
	}

	public function is_ready(): bool {
		return null !== $this->core;
	}

	public function get_last_error(): string {
		return $this->last_error;
	}

	/**
	 * GET against FreshBooks preserving literal brackets in search params (some gateways
	 * drop percent-encoded include[]). Delegates the actual HTTP + token refresh to the
	 * shared client's api_request().
	 */
	private function get( string $path, array $params = array() ): ?array {
		if ( ! $this->core ) {
			return null;
		}
		$acct = $this->account_id();
		if ( '' === $acct ) {
			return null;
		}
		$endpoint = "/accounting/account/{$acct}{$path}";
		if ( ! empty( $params ) ) {
			$parts = array();
			foreach ( $params as $k => $v ) {
				$parts[] = $k . '=' . rawurlencode( (string) $v );
			}
			$endpoint .= '?' . implode( '&', $parts );
		}
		$resp = $this->core->api_request( 'GET', $endpoint );
		$this->last_error = is_array( $resp ) ? '' : 'FreshBooks unreachable.';
		return is_array( $resp ) ? $resp : null;
	}

	private function account_id(): string {
		if ( class_exists( 'ZDZ_Core_Settings' ) ) {
			return (string) ZDZ_Core_Settings::get_fb_account_id();
		}
		return '';
	}

	/* ================================================================
	 * LOOKUP (fallback when the CRM has no lead)
	 * ================================================================ */

	public function search( string $query ): array {
		if ( ! $this->is_ready() ) {
			return array( 'status' => 'not_configured', 'message' => __( 'Billing is not configured.', 'zorderz' ), 'matches' => array() );
		}
		$query = trim( $query );
		if ( '' === $query ) {
			return array( 'status' => 'error', 'message' => __( 'Please enter something to search.', 'zorderz' ), 'matches' => array() );
		}

		$matches     = array();
		$digits_only = preg_replace( '/\D/', '', $query );
		$has_letters = (bool) preg_match( '/[a-zA-Z]/', $query );

		if ( '' !== $digits_only && strlen( $digits_only ) >= 3 && ! $has_letters && strlen( $digits_only ) < 10 ) {
			$matches = $this->search_by_estimate_number( $digits_only );
		} elseif ( $has_letters ) {
			$matches = $this->search_by_name( $query );
		}

		// QUEUE gate (was the hardcoded EM filter): keep only jobs that qualify.
		$in_queue = array_values(
			array_filter(
				$matches,
				function ( $m ) {
					return ZPREP_Settings::job_in_queue( (string) ( $m['reference'] ?? '' ), (string) ( $m['customer_name'] ?? '' ) );
				}
			)
		);

		if ( empty( $in_queue ) ) {
			if ( '' !== $this->last_error ) {
				return array( 'status' => 'error', 'message' => 'Billing error: ' . $this->last_error, 'matches' => array() );
			}
			return array( 'status' => 'not_found', 'message' => __( 'No matching billing records found for the queue.', 'zorderz' ), 'matches' => array() );
		}
		return array( 'status' => 'ok', 'message' => '', 'matches' => $in_queue );
	}

	private function search_by_estimate_number( string $number ): array {
		$resp = $this->get( '/estimates/estimates', array( 'search[estimate_number]' => $number, 'include[]' => 'lines', 'per_page' => 5 ) );
		$out  = array();
		foreach ( $resp['response']['result']['estimates'] ?? array() as $est ) {
			$out[] = $this->normalize_estimate( $est );
		}
		return $out;
	}

	private function search_by_name( string $query ): array {
		$parts = preg_split( '/\s+/', trim( $query ), 2 );
		$last  = $parts[1] ?? $parts[0] ?? '';
		$resp  = $this->get( '/users/clients', array( 'search[lname_like]' => $last, 'per_page' => 15 ) );
		$out   = array();
		foreach ( $resp['response']['result']['clients'] ?? array() as $c ) {
			$cid = $c['id'] ?? null;
			if ( ! $cid ) {
				continue;
			}
			$er = $this->get( '/estimates/estimates', array( 'search[customerid]' => $cid, 'include[]' => 'lines', 'per_page' => 10 ) );
			foreach ( $er['response']['result']['estimates'] ?? array() as $est ) {
				$out[] = $this->normalize_estimate( $est, $c );
			}
		}
		return $out;
	}

	public function get_client( $client_id ): ?array {
		if ( ! $this->is_ready() || ! $client_id ) {
			return null;
		}
		$resp   = $this->get( "/users/clients/{$client_id}" );
		$client = $resp['response']['result']['client'] ?? null;
		if ( ! $client ) {
			return null;
		}
		return array(
			'id'    => $client['id'] ?? '',
			'name'  => trim( ( $client['fname'] ?? '' ) . ' ' . ( $client['lname'] ?? '' ) ),
			'email' => $client['email'] ?? '',
			'phone' => $client['home_phone'] ?? $client['mob_phone'] ?? $client['bus_phone'] ?? '',
		);
	}

	/* ================================================================
	 * APPROVAL GROUND TRUTH (drives the queue when the CRM lags)
	 * ================================================================ */

	/**
	 * Approved estimates in the queue, straight from billing. statusid 5 = accepted,
	 * 6 = invoiced. Bucketed 'ready' (approved; no sent invoice yet) or 'billed'.
	 *
	 * @return array[]
	 */
	public function list_approved_queue_estimates( int $days = 60 ): array {
		if ( ! $this->is_ready() ) {
			return array();
		}
		$cache_key = 'zprep_billing_approved_' . max( 7, $days );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$date_min = gmdate( 'Y-m-d', time() - ( max( 7, $days ) * DAY_IN_SECONDS ) );
		$out      = array();
		$resp     = $this->get( '/estimates/estimates', array( 'search[date_min]' => $date_min, 'include[0]' => 'invoices', 'per_page' => 100 ) );
		$ests     = $resp['response']['result']['estimates'] ?? array();

		foreach ( $ests as $est ) {
			$ui = strtolower( (string) ( $est['ui_status'] ?? '' ) );
			if ( 'accepted' !== $ui && 'invoiced' !== $ui ) {
				continue;
			}
			if ( ! ZPREP_Settings::job_in_queue( (string) ( $est['po_number'] ?? '' ), '' ) ) {
				continue;
			}
			$bucket     = ( 'invoiced' === $ui ) ? 'billed' : 'ready';
			$inv_status = '';
			foreach ( (array) ( $est['invoices'] ?? array() ) as $li ) {
				$vs         = strtolower( (string) ( $li['v3_status'] ?? $li['ui_status'] ?? '' ) );
				$inv_status = '' !== $vs ? $vs : $inv_status;
				if ( in_array( $vs, array( 'paid', 'partial', 'autopaid', 'deposit-paid', 'deposit-partial' ), true ) ) {
					$bucket = 'billed';
					break;
				}
				if ( '' !== $vs && 'draft' !== $vs && 'created' !== $vs ) {
					$bucket = 'billed';
				}
			}
			$out[] = array(
				'estimate_number' => (string) ( $est['estimate_number'] ?? '' ),
				'estimate_id'     => (string) ( $est['id'] ?? '' ),
				'customer_name'   => $this->extract_customer_name( $est ),
				'customer_id'     => (string) ( $est['customerid'] ?? '' ),
				'ui_status'       => $ui,
				'invoice_status'  => $inv_status,
				'bucket'          => $bucket,
				'reference'       => (string) ( $est['po_number'] ?? '' ),
				'created_at'      => (string) ( $est['create_date'] ?? $est['created_at'] ?? '' ),
			);
		}

		set_transient( $cache_key, $out, MINUTE_IN_SECONDS );
		return $out;
	}

	/**
	 * The billing state of a job by estimate number: paid | invoiced | open | unknown |
	 * fresh_unavailable (the last means "couldn't reach billing" — keep the job, trust CRM).
	 */
	public function estimate_billing_state( string $est_number ): string {
		$est_number = trim( $est_number );
		if ( '' === $est_number ) {
			return 'unknown';
		}
		if ( ! $this->is_ready() ) {
			return 'fresh_unavailable';
		}
		$resp = $this->get( '/estimates/estimates', array( 'search[estimate_number]' => $est_number, 'per_page' => 5 ) );
		if ( '' !== $this->last_error || ! is_array( $resp ) ) {
			return 'fresh_unavailable';
		}
		$ests = $resp['response']['result']['estimates'] ?? array();
		$est  = null;
		foreach ( $ests as $e ) {
			if ( (string) ( $e['estimate_number'] ?? '' ) === $est_number ) {
				$est = $e;
				break;
			}
		}
		if ( null === $est && ! empty( $ests ) ) {
			$est = $ests[0];
		}
		if ( null === $est ) {
			return 'unknown';
		}
		$ui = strtolower( (string) ( $est['ui_status'] ?? $est['status'] ?? '' ) );
		return ( 'invoiced' === $ui ) ? 'invoiced' : 'open';
	}

	/* ================================================================
	 * NORMALIZATION
	 * ================================================================ */

	private function normalize_estimate( array $est, ?array $client_hint = null ): array {
		return array(
			'type'          => 'estimate',
			'id'            => $est['id'] ?? '',
			'number'        => $est['estimate_number'] ?? '',
			'reference'     => $est['po_number'] ?? '',
			'status'        => $est['ui_status'] ?? $est['status'] ?? '',
			'created_at'    => $est['created_at'] ?? '',
			'customer_id'   => $est['customerid'] ?? ( $client_hint['id'] ?? '' ),
			'customer_name' => $this->extract_customer_name( $est, $client_hint ),
			'line_count'    => count( $est['lines'] ?? array() ),
		);
	}

	private function extract_customer_name( array $record, ?array $hint = null ): string {
		if ( $hint ) {
			return trim( ( $hint['fname'] ?? '' ) . ' ' . ( $hint['lname'] ?? '' ) );
		}
		$combo = trim( ( $record['fname'] ?? '' ) . ' ' . ( $record['lname'] ?? '' ) );
		return $combo ?: (string) ( $record['organization'] ?? '' );
	}

	/* ================================================================
	 * CRON — promote approved-estimate CRM leads to the cut stage.
	 * ================================================================ */
	public static function run_approved_sync( string $origin = 'cron' ): array {
		$out = array( 'checked' => 0, 'moved' => array(), 'skipped' => 0, 'errors' => array() );
		if ( 'yes' !== get_option( 'zprep_billing_ground_truth', 'no' ) ) {
			return $out; // Off by default: the CRM stage is the trusted signal.
		}
		if ( '' === ZPREP_Settings::cut_stage_name() ) {
			return $out; // No stage configured -> nothing to promote to.
		}
		$fb  = new self();
		$crm = new ZPREP_Crm();
		if ( ! $fb->is_ready() || ! $crm->is_ready() ) {
			return $out;
		}
		$map = get_option( 'zprep_billing_promotions', array() );
		if ( ! is_array( $map ) ) {
			$map = array();
		}
		foreach ( $fb->list_approved_queue_estimates( 60 ) as $e ) {
			if ( 'ready' !== $e['bucket'] || '' === $e['estimate_number'] ) {
				continue;
			}
			$en = $e['estimate_number'];
			++$out['checked'];
			$rec = $map[ $en ] ?? null;
			if ( is_array( $rec ) ) {
				$state = (string) ( $rec['state'] ?? '' );
				if ( 'moved' === $state || 'already' === $state ) {
					++$out['skipped'];
					continue;
				}
				if ( time() - (int) ( $rec['t'] ?? 0 ) < 6 * HOUR_IN_SECONDS ) {
					++$out['skipped'];
					continue;
				}
			}
			$cust = $e['customer_id'] ? $fb->get_client( $e['customer_id'] ) : null;
			$lead = $crm->find_lead_for_customer(
				array(
					'name'            => $e['customer_name'],
					'query'           => $en,
					'estimate_number' => $en,
					'email'           => (string) ( $cust['email'] ?? '' ),
					'phone'           => (string) ( $cust['phone'] ?? '' ),
				)
			);
			if ( ! is_array( $lead ) || empty( $lead['id'] ) ) {
				$map[ $en ] = array( 'state' => 'no_lead', 't' => time() );
				ZPREP_Settings::disposition( 'billing_no_lead', array( 'estimate' => $en, 'origin' => $origin ) );
				continue;
			}
			$reason = sprintf(
				'[Zorderz Prep] Auto-promoted to "%s": billing estimate #%s is %s. Trigger: billing-sync/%s.',
				ZPREP_Settings::cut_stage_name(),
				$en,
				$e['ui_status'],
				$origin
			);
			$res = $crm->promote_lead_to_cut( (int) $lead['id'], $reason );
			if ( ! empty( $res['moved'] ) ) {
				$map[ $en ]     = array( 'state' => 'moved', 'lead' => (int) $lead['id'], 't' => time() );
				$out['moved'][] = $en;
			} elseif ( 'at-or-past-cut' === ( $res['skipped'] ?? '' ) ) {
				$map[ $en ] = array( 'state' => 'already', 'lead' => (int) $lead['id'], 't' => time() );
				++$out['skipped'];
			} else {
				$map[ $en ]      = array( 'state' => 'error', 't' => time() );
				$out['errors'][] = $en;
			}
		}
		update_option( 'zprep_billing_promotions', $map, false );
		return $out;
	}

	/* ================================================================
	 * INVOICE ARM — recent queue invoices (for the de-dup pass)
	 * ================================================================ */

	/** Flatten a FreshBooks doc (notes + line name/description) to plain searchable text. */
	public static function estimate_text( array $doc ): string {
		$parts = array( (string) ( $doc['notes'] ?? '' ) );
		foreach ( (array) ( $doc['lines'] ?? array() ) as $ln ) {
			$parts[] = trim( (string) ( $ln['name'] ?? '' ) . ' ' . (string) ( $ln['description'] ?? '' ) );
		}
		return trim( implode( "\n", array_filter( $parts ) ) );
	}

	/** Does the doc text carry a receipt link — i.e. the job is done (installed + receipted)? */
	public static function text_has_receipt_link( string $text ): bool {
		if ( '' === $text ) {
			return false;
		}
		return ( false !== stripos( $text, '/receipt/' ) ) || ( false !== stripos( $text, 'receipt link' ) );
	}

	/**
	 * Recent QUEUE invoices, read directly from FreshBooks (not via the estimate query),
	 * so a job whose estimate never read accepted/invoiced — but that HAS an invoice —
	 * still surfaces on the Approved-to-Cut queue. Each carries the receipt-link (installed)
	 * + scheduled-date signals so the de-dup pass can fold or surface it. Queue membership
	 * uses the generalized reference tag (ZPREP_Settings::job_in_queue), never a hardcoded
	 * code. The mesh bar is left null here — the source's mesh summary is trade-specific
	 * (a fixed dark/light material taxonomy) and is not part of this generalized de-dup port;
	 * a card with no mesh simply shows no bar, exactly as the source degrades a detail-less card.
	 *
	 * @return array[] { invoice_number, customer_name, customer_id, amount, estimateid,
	 *                   reference, created_at, installed, scheduled, mesh }
	 */
	public function list_recent_queue_invoices( int $days = 90 ): array {
		if ( ! $this->is_ready() ) {
			return array();
		}
		$days      = (int) apply_filters( 'zprep_atc_invoice_lookback_days', max( 14, $days ) );
		$cache_key = 'zprep_fb_qinv_' . max( 7, $days );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$date_min = gmdate( 'Y-m-d', time() - ( $days * DAY_IN_SECONDS ) );
		$out = array(); $page = 1; $pages = 1;
		do {
			$resp = $this->get( '/invoices/invoices', array(
				'search[date_min]' => $date_min,
				'include[]'        => 'lines',
				'per_page'         => 100,
				'page'             => $page,
			) );
			if ( '' !== $this->last_error || ! is_array( $resp ) ) {
				break;
			}
			$invs  = $resp['response']['result']['invoices'] ?? array();
			$pages = max( 1, (int) ( $resp['response']['result']['pages'] ?? 1 ) );
			foreach ( $invs as $inv ) {
				$cust = trim( (string) ( $inv['fname'] ?? '' ) . ' ' . (string) ( $inv['lname'] ?? '' ) );
				if ( '' === $cust ) {
					$cust = (string) ( $inv['organization'] ?? '' );
				}
				if ( ! ZPREP_Settings::job_in_queue( (string) ( $inv['po_number'] ?? '' ), $cust ) ) {
					continue;
				}
				$text = self::estimate_text( $inv );
				$out[] = array(
					'invoice_number' => (string) ( $inv['invoice_number'] ?? $inv['invoiceid'] ?? '' ),
					'customer_name'  => $cust,
					'customer_id'    => (string) ( $inv['customerid'] ?? '' ),
					'amount'         => (float) ( $inv['amount']['amount'] ?? 0 ),
					'estimateid'     => (string) ( $inv['estimateid'] ?? '' ),
					'reference'      => (string) ( $inv['po_number'] ?? '' ),
					'created_at'     => (string) ( $inv['create_date'] ?? $inv['created_at'] ?? $inv['date'] ?? '' ),
					'installed'      => self::text_has_receipt_link( $text ),
					'scheduled'      => class_exists( 'ZPREP_Install_Fallback' ) ? ZPREP_Install_Fallback::from_text( $text ) : null,
					'mesh'           => null,
				);
			}
			$page++;
		} while ( $page <= min( $pages, 3 ) ); // sanity cap: 300 invoices/window

		set_transient( $cache_key, $out, MINUTE_IN_SECONDS );
		return $out;
	}
}
