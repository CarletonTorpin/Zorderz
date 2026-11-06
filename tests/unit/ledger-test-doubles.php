<?php
/**
 * Test doubles and stubs for the analytics app's stored-data ledger: a billing reader, a CRM reader and a store, all in
 * memory. Every name carries the ledger tests' own prefix (the whole suite runs in one process).
 *
 * @package Zorderz\Tests
 */

if ( ! defined( 'ZANA_APP_ID' ) ) {
	define( 'ZANA_APP_ID', 'sales-analytics' );
}
if ( ! defined( 'ZANA_DIR' ) ) {
	define( 'ZANA_DIR', dirname( __DIR__, 2 ) . '/zorderz-apps/apps/analytics/' );
}
if ( ! defined( 'ZDZ_REST_NS' ) ) {
	define( 'ZDZ_REST_NS', 'zorderz/v1' );
}
foreach ( array( 'MINUTE_IN_SECONDS' => 60, 'HOUR_IN_SECONDS' => 3600, 'DAY_IN_SECONDS' => 86400 ) as $zdz_test_ldg_c => $zdz_test_ldg_v ) {
	if ( ! defined( $zdz_test_ldg_c ) ) {
		define( $zdz_test_ldg_c, $zdz_test_ldg_v );
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $k, $v, $autoload = null ) { $GLOBALS['zdz_test_options'][ $k ] = $v; return true; }
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $k ) { return $GLOBALS['zdz_test_transients'][ $k ] ?? false; }
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['zdz_test_transients'][ $k ] = $v; return true; }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() { return (int) ( $GLOBALS['zdz_test_current_user'] ?? 0 ); }
}

require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-doc-conventions.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-contacts.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-core-freshbooks.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-core-nutshell.php';
foreach ( array( 'ledger-rules', 'ledger-enrich', 'ledger-store', 'ledger', 'ledger-access', 'ledger-periods', 'ledger-figures',
	'lead-sources', 'ledger-answers', 'ledger-reports', 'billing-gaps', 'home-figures', 'quick-answers', 'ledger-shadow', 'invoice-state',
	'invoice-doc' ) as $zdz_test_ldg_part ) {
	require_once ZANA_DIR . 'includes/class-zana-' . $zdz_test_ldg_part . '.php';
}

/** A billing reader over documents in memory: the default list holds active documents only, two per page. */
final class ZDZ_Test_Ldg_FB {
	public $docs     = array( 'invoices' => array(), 'estimates' => array(), 'payments' => array() );
	public $calls    = array();
	public $per_page = 2;
	public $fail     = false;

	public function list_page( string $resource, array $search, int $page = 1, int $timeout = 30 ): array {
		$this->calls[] = array( 'list_page', $resource, $search, $page );
		if ( $this->fail ) {
			throw new RuntimeException( 'Billing read failed (HTTP 503)' );
		}
		$items = array_values( array_filter( $this->docs[ $resource ] ?? array(), function ( $d ) use ( $search, $resource ) {
			$vis = (int) ( $d['vis_state'] ?? 0 );
			if ( isset( $search['vis_state'] ) ? $vis !== (int) $search['vis_state'] : 0 !== $vis ) {
				return false;
			}
			$date = 'payments' === $resource ? (string) $d['date'] : (string) $d['create_date'];
			if ( ! empty( $search['date_min'] ) && $date < $search['date_min'] ) {
				return false;
			}
			if ( ! empty( $search['date_max'] ) && $date > $search['date_max'] ) {
				return false;
			}
			if ( ! empty( $search['updated_min'] ) && substr( (string) ( $d['updated'] ?? '' ), 0, 10 ) < $search['updated_min'] ) {
				return false;
			}
			return true;
		} ) );
		return array(
			'items' => array_slice( $items, ( $page - 1 ) * $this->per_page, $this->per_page ),
			'pages' => max( 1, (int) ceil( count( $items ) / $this->per_page ) ),
			'total' => count( $items ),
		);
	}
}

/** A CRM reader over leads in memory (findLeads pages of 100, getLead). */
final class ZDZ_Test_Ldg_NS {
	public $leads           = array();
	public $calls           = array();
	public $request_timeout = null;

	public function find_leads_page( array $query, int $page = 1, int $limit = 100, string $order_by = 'id', string $direction = 'ASC' ): array {
		$this->calls[] = array( 'find_leads_page', $query, $page, $direction );
		$rows = array_values( array_filter( $this->leads, function ( $l ) use ( $query ) {
			return ! isset( $query['status'] ) || (int) $l['status'] === (int) $query['status'];
		} ) );
		usort( $rows, function ( $a, $b ) use ( $direction ) { return 'DESC' === $direction ? $b['id'] <=> $a['id'] : $a['id'] <=> $b['id']; } );
		return array_slice( $rows, ( $page - 1 ) * $limit, $limit );
	}

	public function read_lead( int $id ) {
		$this->calls[] = array( 'read_lead', $id );
		foreach ( $this->leads as $l ) {
			if ( (int) $l['id'] === $id ) {
				return $l;
			}
		}
		return null;
	}
}

/** The ledger's tables in memory, with the store's methods. */
final class ZDZ_Test_Ldg_Store {
	public $rows        = array( 'invoices' => array(), 'estimates' => array(), 'payments' => array(), 'leads' => array() );
	public $notes       = array();
	public $reads       = array();
	public $locked      = false;
	public $notes_ready = true;

	public function lock(): bool {
		if ( $this->locked ) {
			return false;
		}
		$this->locked = true;
		return true;
	}

	public function unlock(): void {
		$this->locked = false;
	}

	public function upsert( string $kind, array $row ): bool {
		$this->rows[ $kind ][ (int) reset( $row ) ] = $row;
		return true;
	}

	public function count( string $kind ): int {
		return count( $this->rows[ $kind ] );
	}

	public function max_lead_id(): int {
		return $this->rows['leads'] ? (int) max( array_keys( $this->rows['leads'] ) ) : 0;
	}

	public $vanished = array();

	public function close_vanished_open_leads( string $before ): void {
		$this->vanished[] = $before;
		foreach ( $this->rows['leads'] as $id => $r ) {
			if ( 0 === (int) $r['status'] && $r['synced_at'] < $before ) {
				$this->rows['leads'][ $id ]['status'] = -1;
			}
		}
	}

	public function derived( string $kind, array $ids ): array {
		$out = array();
		foreach ( $ids as $id ) {
			if ( isset( $this->rows[ $kind ][ $id ] ) ) {
				$out[ $id ] = ZANA_Ledger::derived_of( $kind, $this->rows[ $kind ][ $id ] );
			}
		}
		return $out;
	}

	public function update_derived( string $kind, int $id, array $fields ): bool {
		$this->rows[ $kind ][ $id ] = array_merge( $this->rows[ $kind ][ $id ], $fields );
		return true;
	}

	public function sweep_payments( int $year, string $before ): int {
		$n = 0;
		foreach ( $this->rows['payments'] as $id => $r ) {
			if ( substr( (string) $r['pay_date'], 0, 4 ) === (string) $year && $r['synced_at'] < $before ) {
				unset( $this->rows['payments'][ $id ] );
				$n++;
			}
		}
		return $n;
	}

	public function invoice_number_for_id( int $id ): string {
		$r = $this->rows['invoices'][ $id ] ?? null;
		return ( $r && 1 !== (int) $r['vis_state'] ) ? (string) $r['invoice_number'] : '';
	}

	public function notes_ready(): bool {
		return $this->notes_ready;
	}

	public function store_notes( int $lead, array $rows, bool $may_delete, string $via, string $now ): int {
		if ( $may_delete ) {
			$this->notes[ $lead ] = array();
		}
		foreach ( $rows as $r ) {
			$this->notes[ $lead ][ $r['note_key'] ] = $r;
		}
		$this->reads[ $lead ] = array( 'via' => $via, 'read_at' => $now );
		return count( $this->notes[ $lead ] ?? array() );
	}

	public function mark_miss( int $lead, string $now ): void {
		$this->reads[ $lead ] = array( 'via' => 'miss', 'read_at' => $now );
	}

	public function leads_needing_notes( int $limit, bool $never_only, bool $get_mode, int $now, int $open, int $all ): array {
		$ids = array_diff( array_keys( $this->rows['leads'] ), array_keys( $this->reads ) );
		rsort( $ids );
		return array_slice( array_values( $ids ), 0, $limit );
	}

	public function notes_summary(): array {
		return array( 'leads_read' => count( $this->reads ), 'notes' => array_sum( array_map( 'count', $this->notes ) ), 'last_write' => '' );
	}
}

/** Reset every injection point the ledger classes have. */
function zdz_test_ldg_reset(): void {
	$GLOBALS['zdz_test_options']    = array();
	$GLOBALS['zdz_test_transients'] = array();
	$GLOBALS['zdz_test_ldg_ticks']  = 100000;
	ZANA_Ledger::$fb              = false;
	ZANA_Ledger::$ns              = false;
	ZANA_Ledger::$store           = new ZDZ_Test_Ldg_Store();
	ZANA_Ledger::$now             = gmmktime( 15, 0, 0, 6, 15, 2026 );
	ZANA_Ledger::$staging         = false;
	ZANA_Ledger::$catalog_version = '1';
	ZANA_Ledger::$clock           = function () { return $GLOBALS['zdz_test_ldg_ticks']-- > 0; };
	ZANA_Ledger::$context         = array( 'rep_codes' => array( 'AB', 'CD' ) );
	ZANA_Ledger_Rules::$override  = array();
	ZANA_Ledger_Rules::flush();
	ZANA_Ledger_Enrich::$category_fn = function () { return ''; };
	ZANA_Ledger_Enrich::$brands_fn   = function () { return array(); };
	ZANA_Ledger_Access::$context  = null;
	ZANA_Ledger_Access::$reps     = array( 'AB' => 'Alex Example', 'CD' => 'Casey Doe' );
	ZANA_Ledger_Access::flush();
	ZANA_Ledger_Periods::$now     = gmmktime( 15, 0, 0, 6, 15, 2026 );
	ZANA_Ledger_Periods::$tz      = new DateTimeZone( 'UTC' );
	ZANA_Ledger_Figures::$rows      = null;
	ZANA_Ledger_Figures::$lead_rows = null;
	ZANA_Ledger_Answers::$ready       = true;
	ZANA_Ledger_Answers::$cities      = array( 'Riverton', 'Midvale' );
	ZANA_Ledger_Answers::$zips        = array( '10001' );
	ZANA_Ledger_Answers::$categories  = array( 'gate' => 'Gates', 'fence' => 'Fencing' );
	ZANA_Ledger_Answers::$relabelling = false;
	ZANA_Lead_Sources::$ready     = true;
	ZANA_Home_Figures::$now       = gmmktime( 15, 0, 0, 6, 15, 2026 );
	ZANA_Home_Figures::$coverage  = null;
	ZANA_Home_Figures::$notes     = null;
	ZANA_Billing_Gaps::$now       = gmmktime( 15, 0, 0, 6, 15, 2026 );
	ZANA_Billing_Gaps::$estimates = null;
	ZANA_Billing_Gaps::$customer_docs = null;
	ZANA_Invoice_Doc::$fetcher    = null;
	ZANA_Invoice_Doc::$sharer     = null;
	ZANA_Invoice_Doc::$ledger     = null;
	ZANA_Invoice_Doc::$breadth    = function () { return null; };
	ZANA_Ledger_Shadow::reset();
	unset( $GLOBALS['zdz_test_filters']['zana_quick_routes'], $GLOBALS['zdz_test_filters']['zana_crm_app_note_patterns'], $GLOBALS['zdz_test_filters']['zana_crm_contact_note_patterns'], $GLOBALS['zdz_test_filters']['zdz_contact_note_opening'] );
}

/** A permission context (the ledger's own shape) for a test person. */
function zdz_test_ldg_ctx( string $who ): array {
	$base = array( 'user_id' => 7, 'tier' => 'staff', 'revenue' => false, 'others' => false, 'own' => false, 'codes' => array() );
	switch ( $who ) {
		case 'owner':
			return array_merge( $base, array( 'tier' => 'admin', 'revenue' => true, 'others' => true, 'own' => true ) );
		case 'sales':
			return array_merge( $base, array( 'own' => true, 'codes' => array( 'AB' ) ) );
		case 'sales_nocode':
			return array_merge( $base, array( 'own' => true ) );
		case 'tech':
			return array_merge( $base, array( 'tier' => 'viewer' ) );
		case 'kiosk':
			return array_merge( $base, array( 'tier' => 'kiosk', 'revenue' => true, 'others' => true ) );
		case 'pv_owner':
			return array_merge( $base, array( 'tier' => 'admin', 'revenue' => true, 'others' => true, 'privacy_view' => true ) );
		case 'demo':
			return array_merge( $base, array( 'tier' => 'admin', 'revenue' => true, 'others' => true, 'demo' => true ) );
	}
	return array( 'user_id' => 0 );
}

/** Make ZANA_Ledger_Access answer with one of the contexts above. */
function zdz_test_ldg_as( string $who ): void {
	ZANA_Ledger_Access::$context = function () use ( $who ) { return zdz_test_ldg_ctx( $who ); };
}
