<?php
/**
 * Zorderz Prep — CRM adapter.
 *
 * ALL CRM transport goes through the shared ZDZ_Core_Nutshell client (one credential +
 * transport authority); this class adds only the prep-domain logic on top:
 *   - find the lead(s) for a job, and pick/assemble its measurement block,
 *   - list leads sitting in the configurable "ready to cut" pipeline stage,
 *   - write the cut-completion note and advance/promote the pipeline stage,
 * all recognising the neutral, VERSIONED data-contract blocks defined in app.php (with
 * legacy prior-build headers accepted as deprecated aliases). Nothing hardcodes a block
 * string inline, and the product-line gate is the configurable QUEUE (tag/subtype), not a
 * baked-in product test.
 *
 * @package Zorderz\Prep
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZPREP_Crm {

	/** @var object|null Shared CRM client (ZDZ_Core_Nutshell) or null when unconfigured. */
	private $core;

	private array $last_trace          = array();
	private string $last_advance_status = '';

	public function __construct() {
		$this->core = ZPREP_Settings::crm();
	}

	public function is_ready(): bool {
		return null !== $this->core;
	}

	public function get_last_trace(): array {
		return $this->last_trace;
	}

	public function get_last_advance_status(): string {
		return $this->last_advance_status;
	}

	/** Delegate one RPC to the shared client. Returns result array/scalar or null. */
	private function rpc( string $method, array $params = array() ) {
		if ( ! $this->core ) {
			return null;
		}
		return $this->core->rpc_call( $method, $params );
	}

	/* ================================================================
	 * DATA-CONTRACT recognition (versioned; legacy aliases accepted)
	 * ================================================================ */

	/** True when a note body carries the inbound MEASUREMENTS contract block. */
	public function note_has_measurements( string $note ): bool {
		if ( '' === $note ) {
			return false;
		}
		if ( stripos( $note, ZPREP_CONTRACT_MEAS_MARKER ) !== false && stripos( $note, ZPREP_CONTRACT_MEAS_MARKER2 ) !== false ) {
			return true;
		}
		$dep = zprep_contract_deprecated_aliases();
		foreach ( (array) ( $dep['meas'] ?? array() ) as $alias ) {
			if ( '' !== $alias && stripos( $note, $alias ) !== false && stripos( $note, ZPREP_CONTRACT_MEAS_MARKER2 ) !== false ) {
				return true;
			}
		}
		return false;
	}

	/** True when a note body is one of OUR machine-written cut-completion logs. */
	public function is_machine_cut_note( string $note ): bool {
		if ( stripos( $note, ZPREP_CONTRACT_CUT_COMPLETE ) !== false ) {
			return true;
		}
		if ( preg_match( '/\b' . preg_quote( ZPREP_CONTRACT_SIGNATURE, '/' ) . '\s+v\d/i', $note ) ) {
			return true;
		}
		$dep = zprep_contract_deprecated_aliases();
		foreach ( (array) ( $dep['cut'] ?? array() ) as $alias ) {
			if ( '' !== $alias && stripos( $note, $alias ) !== false ) {
				return true;
			}
		}
		return false;
	}

	/** Choose the base measurement block from a lead's notes (contract, then dims heuristic). */
	public function pick_measurement_block( array $notes ): string {
		foreach ( $notes as $n ) {
			if ( $this->note_has_measurements( (string) $n ) ) {
				return (string) $n;
			}
		}
		foreach ( $notes as $n ) {
			if ( preg_match( '/\[\s*\d+(\.\d+)?\s*"?\s*[xX×]\s*\d+/u', (string) $n ) ) {
				return (string) $n;
			}
		}
		return (string) ( $notes[0] ?? '' );
	}

	/**
	 * The FULL parser input: the base measurement block plus every other note on the
	 * lead as a labelled adjustment, so field adjustments change the cut quantities. Our
	 * own machine cut-notes are skipped. Dollar amounts are stripped (prep never needs
	 * price, and the platform norm is "the model never sees a dollar").
	 */
	public function build_parse_input( array $notes, string $primary_block = '' ): string {
		if ( '' === $primary_block ) {
			$primary_block = $this->pick_measurement_block( $notes );
		}

		$adjustments = array();
		foreach ( $notes as $n ) {
			$n = (string) $n;
			if ( '' === trim( $n ) || $n === $primary_block || $this->is_machine_cut_note( $n ) ) {
				continue;
			}
			$adjustments[] = trim( $n );
		}

		if ( empty( $adjustments ) ) {
			return $this->strip_dollar_amounts( $primary_block );
		}

		$out  = "=== BASE MEASUREMENTS (primary block — source of truth for sizes) ===\n";
		$out .= trim( $primary_block ) . "\n\n";
		$out .= "=== ADJUSTMENT NOTES (apply these to the base — they may add, remove, recolour, or re-size items) ===\n";
		foreach ( $adjustments as $i => $adj ) {
			$out .= '--- Adjustment note ' . ( $i + 1 ) . " ---\n" . $adj . "\n\n";
		}
		$out .= 'When an adjustment changes a quantity or colour, the FINAL quantities you output MUST reflect the adjustment, not the base alone. Record what you changed in that item\'s notes field.';
		return $this->strip_dollar_amounts( rtrim( $out ) );
	}

	/** Remove dollar AMOUNTS (keep bare numbers so dimensions/quantities survive). */
	private function strip_dollar_amounts( string $text ): string {
		if ( strpos( $text, '$' ) === false ) {
			return $text;
		}
		$text = preg_replace( '/\s*[@=]?\s*\$\s*\d[\d,]*(?:\.\d+)?\s*(?:\/\s*(?:ft|sq\s?ft|sf|lf|ea|each|unit|roll))?/i', ' ', $text );
		$text = preg_replace( '/[ \t]{2,}/', ' ', (string) $text );
		return (string) $text;
	}

	/* ================================================================
	 * LOOKUP
	 * ================================================================ */

	/**
	 * All matching leads for a query, hydrated with display metadata. Kept only when the
	 * lead is IN QUEUE (its text qualifies for the configured queue) or carries notes.
	 */
	public function find_all_leads_for_query( string $query ): array {
		$this->last_trace = array();
		if ( ! $this->is_ready() ) {
			$this->last_trace[] = 'CRM not configured.';
			return array();
		}

		$leads_by_id = array();

		$result = $this->rpc( 'searchLeads', array( 'string' => $query, 'limit' => 10 ) );
		if ( is_array( $result ) ) {
			foreach ( $result as $stub ) {
				$lid = (int) ( $stub['id'] ?? 0 );
				if ( ! $lid || isset( $leads_by_id[ $lid ] ) ) {
					continue;
				}
				$full = $this->fetch_lead_with_meta( $lid, $stub );
				if ( $full ) {
					$leads_by_id[ $lid ] = $full;
				}
			}
		}

		$contacts = $this->rpc( 'searchContacts', array( 'string' => $query, 'limit' => 10 ) );
		foreach ( $this->extract_contact_ids( $contacts ) as $cid ) {
			$contact = $this->rpc( 'getContact', array( 'contactId' => (int) $cid ) );
			if ( ! is_array( $contact ) ) {
				continue;
			}
			foreach ( (array) ( $contact['leads'] ?? array() ) as $ls ) {
				$lid = (int) ( $ls['id'] ?? 0 );
				if ( ! $lid || isset( $leads_by_id[ $lid ] ) ) {
					continue;
				}
				$full = $this->fetch_lead_with_meta( $lid, $ls );
				if ( $full ) {
					$leads_by_id[ $lid ] = $full;
				}
			}
		}

		$out = array();
		foreach ( $leads_by_id as $lead ) {
			$desc     = (string) ( $lead['description'] ?? '' );
			$in_queue = ZPREP_Settings::job_in_queue( $desc, $desc );
			$has_notes = ! empty( $lead['notes'] );
			if ( $in_queue || $has_notes ) {
				$out[] = $lead;
			}
		}
		$this->last_trace[] = sprintf( '%d lead(s) after queue/notes filter', count( $out ) );
		return $out;
	}

	public function find_lead_for_customer( array $customer ): ?array {
		$this->last_trace = array();
		if ( ! $this->is_ready() ) {
			return null;
		}
		$query = trim( (string) ( $customer['query'] ?? $customer['estimate_number'] ?? $customer['name'] ?? '' ) );
		if ( '' === $query ) {
			return null;
		}
		$leads = $this->find_all_leads_for_query( $query );
		return $leads[0] ?? null;
	}

	public function get_lead_by_id( int $lead_id ): ?array {
		$this->last_trace = array();
		if ( ! $this->is_ready() || $lead_id <= 0 ) {
			return null;
		}
		return $this->fetch_lead_with_meta( $lead_id );
	}

	private function fetch_lead_with_meta( int $lid, array $stub = array() ): ?array {
		$is_full = ( isset( $stub['milestone'] ) || isset( $stub['notes'] ) || isset( $stub['rev'] ) ) && empty( $stub['stub'] );
		$lead    = $is_full ? $stub : $this->rpc( 'getLead', array( 'leadId' => $lid ) );
		if ( ! is_array( $lead ) ) {
			return null;
		}

		$desc  = (string) ( $lead['description'] ?? $stub['description'] ?? '' );
		$notes = $this->extract_note_bodies( $lead );
		$meta  = $this->extract_lead_display_meta( $desc, $notes, $lead );

		$milestone = ( isset( $lead['milestone'] ) && is_array( $lead['milestone'] ) ) ? $lead['milestone'] : array();

		return array(
			'id'             => $lid,
			'rev'            => (string) ( $lead['rev'] ?? '' ),
			'description'    => $desc,
			'notes'          => $notes,
			'milestone_id'   => (int) ( $milestone['id'] ?? 0 ),
			'milestone_name' => (string) ( $milestone['name'] ?? '' ),
			'stageset'       => $lead['stageset'] ?? array(),
			'created_at'     => (string) ( $lead['createdTime'] ?? '' ),
			'meta'           => $meta,
		);
	}

	private function extract_lead_display_meta( string $desc, array $notes, array $lead ): array {
		$meta = array( 'customer' => '', 'city' => '', 'date' => '', 'cut_count' => 0, 'estimate_number' => '' );

		if ( preg_match( '/for\s+(.+?)\s+in\s+(.+)$/i', $desc, $m ) ) {
			$meta['customer'] = trim( $m[1] );
			$meta['city']     = trim( $m[2] );
		} elseif ( preg_match( '/for\s+(.+)$/i', $desc, $m ) ) {
			$meta['customer'] = trim( $m[1] );
		}

		$created = $lead['createdTime'] ?? '';
		if ( $created ) {
			try {
				$dt           = new \DateTime( $created );
				$meta['date'] = $dt->format( 'M j, Y' );
			} catch ( \Throwable $e ) {
				// non-fatal.
			}
		}

		$block = '';
		foreach ( $notes as $n ) {
			if ( $this->note_has_measurements( (string) $n ) ) {
				$block = (string) $n;
				break;
			}
		}
		if ( $block ) {
			if ( ! $meta['customer'] && preg_match( '/Customer:\s*(.+)/i', $block, $m ) ) {
				$meta['customer'] = trim( $m[1] );
			}
			if ( preg_match( '/Estimate\s*#?:?\s*(\d+)/i', $block, $m ) ) {
				$meta['estimate_number'] = trim( $m[1] );
			}
			if ( ! $meta['city'] && preg_match( '/Address:\s*.+?,\s*([^,]+),/i', $block, $m ) ) {
				$meta['city'] = trim( $m[1] );
			}
			$meta['cut_count'] = $this->count_prep_pieces_in_block( $block );
		}
		return $meta;
	}

	/**
	 * Count the pieces the shop must actually CUT from a measurement block — for the card
	 * badge. A line counts when it (a) is not a non-cut document line (installation / tax
	 * / fee / receipt / link / location) AND (b) positively reads as a cut piece: it
	 * carries a bracketed [W" x H"] dimension, OR the Item Engine classifies it to a
	 * cuttable item. Empty catalog => the dimension test alone. Nothing hardcodes a piece
	 * list.
	 */
	private function count_prep_pieces_in_block( string $block ): int {
		if ( '' === $block ) {
			return 0;
		}
		$total = 0;
		foreach ( preg_split( '/\r\n|\r|\n/', $block ) as $line ) {
			if ( ! preg_match( '/×\s*(\d+)/u', $line, $qm ) ) {
				continue;
			}
			$qty = (int) $qm[1];
			if ( $qty <= 0 ) {
				continue;
			}
			$l = strtolower( $line );
			// Non-cut document lines (generic kinds — never a product taxonomy).
			if ( preg_match( '/\binstallation\b|\binstall\b|\bappt\.?\s*summary\b/', $l ) ) {
				continue;
			}
			if ( preg_match( '/\btax\b|\bfee\b|\bfees\b|\bdiscount\b|\bdeposit\b|\bsurcharge\b/', $l ) ) {
				continue;
			}
			if ( preg_match( '#\breceipt\b|\blink\b|https?://#', $l ) ) {
				continue;
			}
			if ( preg_match( '/\b(location|property location)\b/', $l ) ) {
				continue;
			}
			// Positive cut-piece signal.
			$has_dims = (bool) preg_match( '/\[\s*\d+(?:\.\d+)?\s*"?\s*[xX×]\s*\d+/u', $line );
			if ( $has_dims ) {
				$total += $qty;
				continue;
			}
			$item = ZPREP_Settings::item_match( $line );
			if ( is_array( $item ) && ZPREP_Settings::is_cuttable_piece( (string) ( $item['id'] ?? '' ), 'rectangle' ) ) {
				$total += $qty;
			}
		}
		return $total;
	}

	/* ================================================================
	 * "READY TO CUT" QUEUE (configurable pipeline stage)
	 * ================================================================ */

	/**
	 * Leads currently in the configured cut stage. When no stage is configured, the
	 * auto-queue is disabled (returns empty) — the lookup box still works. Resolves the
	 * milestone id(s) for the stage NAME and asks the CRM for open leads in that stage,
	 * falling back to an in-PHP name match.
	 *
	 * @return array<int,array>
	 */
	public function list_leads_by_stage( int $limit = 100 ): array {
		$this->last_trace = array();
		$stage            = ZPREP_Settings::cut_stage_name();
		if ( ! $this->is_ready() || '' === $stage ) {
			if ( '' === $stage ) {
				$this->last_trace[] = 'Cut stage not configured — auto-queue disabled.';
			}
			return array();
		}
		$target        = strtolower( trim( $stage ) );
		$limit         = max( 1, min( 100, $limit ) );
		$milestone_ids = $this->cut_stage_milestone_ids();

		$result = null;
		if ( ! empty( $milestone_ids ) ) {
			$query = array( 'status' => 0 );
			if ( count( $milestone_ids ) === 1 ) {
				$query['milestoneId'] = $milestone_ids[0];
			} else {
				$query['milestoneIds'] = array_values( $milestone_ids );
			}
			$result = $this->rpc( 'findLeads', array( 'query' => $query, 'orderBy' => 'id', 'orderDirection' => 'DESC', 'limit' => $limit, 'stubResponses' => false ) );
		}
		if ( ! is_array( $result ) ) {
			$result = $this->rpc( 'findLeads', array( 'query' => array( 'status' => 0 ), 'orderBy' => 'id', 'orderDirection' => 'DESC', 'limit' => $limit, 'stubResponses' => false ) );
		}
		if ( ! is_array( $result ) ) {
			$this->last_trace[] = 'findLeads returned no array.';
			return array();
		}

		$jobs        = array();
		$filtered_ok = ! empty( $milestone_ids );
		foreach ( $result as $lead ) {
			if ( ! is_array( $lead ) ) {
				continue;
			}
			$lid = (int) ( $lead['id'] ?? 0 );
			if ( ! $lid ) {
				continue;
			}
			$stage_name = $this->stage_name_for_lead( $lead );
			if ( ! $filtered_ok ) {
				if ( '' === $stage_name || strtolower( trim( $stage_name ) ) !== $target ) {
					continue;
				}
			}
			if ( '' === $stage_name ) {
				$stage_name = $stage;
			}

			$full = $this->fetch_lead_with_meta( $lid, $lead );
			if ( ! $full ) {
				continue;
			}
			// Already cut? (our machine note present) -> not ready.
			foreach ( $full['notes'] ?? array() as $n ) {
				if ( $this->is_machine_cut_note( (string) $n ) ) {
					continue 2;
				}
			}

			$m           = $full['meta'] ?? array();
			$created_ts  = ! empty( $full['created_at'] ) ? (int) strtotime( (string) $full['created_at'] ) : 0;
			$jobs[]      = array(
				'lead_id'         => $lid,
				'customer'        => (string) ( $m['customer'] ?? '' ),
				'city'            => (string) ( $m['city'] ?? '' ),
				'date'            => (string) ( $m['date'] ?? '' ),
				'cut_count'    => (int) ( $m['cut_count'] ?? 0 ),
				'estimate_number' => (string) ( $m['estimate_number'] ?? '' ),
				'stage_name'      => $stage_name,
				'description'     => (string) ( $full['description'] ?? '' ),
				'_created_ts'     => $created_ts,
				'_lead_id'        => $lid,
			);
		}

		usort(
			$jobs,
			function ( $a, $b ) {
				if ( $a['_created_ts'] !== $b['_created_ts'] ) {
					return $b['_created_ts'] <=> $a['_created_ts'];
				}
				return $b['_lead_id'] <=> $a['_lead_id'];
			}
		);

		$max_return = (int) apply_filters( 'zprep_queue_max_jobs', 25 );
		if ( $max_return > 0 && count( $jobs ) > $max_return ) {
			$jobs = array_slice( $jobs, 0, $max_return );
		}
		foreach ( $jobs as &$j ) {
			unset( $j['_created_ts'], $j['_lead_id'] );
		}
		unset( $j );

		$this->last_trace[] = sprintf( '%d lead(s) ready in "%s".', count( $jobs ), $stage );
		return $jobs;
	}

	private function stage_name_for_lead( array $lead ): string {
		if ( ! empty( $lead['milestone_name'] ) ) {
			return (string) $lead['milestone_name'];
		}
		if ( isset( $lead['milestone'] ) && is_array( $lead['milestone'] ) ) {
			$n = (string) ( $lead['milestone']['name'] ?? '' );
			if ( '' !== $n ) {
				return $n;
			}
		}
		return (string) ( $lead['milestoneName'] ?? '' );
	}

	private function cut_stage_milestone_ids(): array {
		$target = strtolower( trim( ZPREP_Settings::cut_stage_name() ) );
		if ( '' === $target ) {
			return array();
		}
		$cache_key = 'zprep_cut_milestone_ids_' . md5( $target );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$ids        = array();
		$milestones = $this->rpc( 'findMilestones', array( 'limit' => 250 ) );
		if ( is_array( $milestones ) ) {
			foreach ( $milestones as $ms ) {
				if ( ! is_array( $ms ) ) {
					continue;
				}
				$name = strtolower( trim( (string) ( $ms['name'] ?? '' ) ) );
				$mid  = (int) ( $ms['id'] ?? 0 );
				if ( $mid && $name === $target ) {
					$ids[] = $mid;
				}
			}
		}
		$ids = array_values( array_unique( $ids ) );
		set_transient( $cache_key, $ids, $ids ? 12 * HOUR_IN_SECONDS : 10 * MINUTE_IN_SECONDS );
		return $ids;
	}

	private function pipeline_milestones( int $stageset_id ): array {
		$cache_key = 'zprep_pipeline_' . $stageset_id;
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$out        = array();
		$milestones = $this->rpc( 'findMilestones', array( 'orderBy' => 'position', 'orderDirection' => 'ASC', 'limit' => 250 ) );
		if ( is_array( $milestones ) ) {
			foreach ( $milestones as $ms ) {
				if ( ! is_array( $ms ) ) {
					continue;
				}
				$mid = (int) ( $ms['id'] ?? 0 );
				if ( ! $mid ) {
					continue;
				}
				$ss = (int) ( $ms['stagesetId'] ?? 0 );
				if ( $stageset_id && $ss && $ss !== $stageset_id ) {
					continue;
				}
				$out[] = array( 'id' => $mid, 'name' => (string) ( $ms['name'] ?? '' ), 'position' => (int) ( $ms['position'] ?? 0 ) );
			}
		}
		usort( $out, fn( $a, $b ) => $a['position'] <=> $b['position'] );
		set_transient( $cache_key, $out, 12 * HOUR_IN_SECONDS );
		return $out;
	}

	/* ================================================================
	 * WRITE-BACK — note + stage advance + activity
	 * ================================================================ */

	public function add_completion_note( int $lead_id, string $body ): bool {
		$result = $this->rpc( 'newNote', array( 'entity' => array( 'entityType' => 'Leads', 'id' => $lead_id ), 'note' => $body ) );
		return ! empty( $result );
	}

	/** Advance a lead exactly one pipeline stage (idempotent past the cut stage). */
	public function advance_lead_stage( int $lead_id ): ?string {
		$this->last_advance_status = 'error';
		if ( ! $this->is_ready() ) {
			return null;
		}
		$lead = $this->rpc( 'getLead', array( 'leadId' => $lead_id ) );
		if ( ! is_array( $lead ) ) {
			return null;
		}
		$rev = (string) ( $lead['rev'] ?? '' );
		if ( '' === $rev ) {
			return null;
		}
		$milestone   = ( isset( $lead['milestone'] ) && is_array( $lead['milestone'] ) ) ? $lead['milestone'] : array();
		$cur_id      = (int) ( $milestone['id'] ?? 0 );
		$cur_name    = (string) ( $milestone['name'] ?? '' );
		$stageset_id = (int) ( $milestone['stagesetId'] ?? ( $lead['stageset']['id'] ?? 0 ) );
		if ( ! $cur_id ) {
			$this->last_advance_status = 'no_pipeline';
			return null;
		}
		$pipeline = $this->pipeline_milestones( $stageset_id );
		if ( empty( $pipeline ) ) {
			$this->last_advance_status = 'no_pipeline';
			return null;
		}
		$cut_target = strtolower( trim( ZPREP_Settings::cut_stage_name() ) );
		$cur_idx    = -1;
		$cut_idx    = -1;
		foreach ( $pipeline as $i => $ms ) {
			if ( (int) $ms['id'] === $cur_id ) {
				$cur_idx = $i;
			}
			if ( '' !== $cut_target && strtolower( trim( (string) $ms['name'] ) ) === $cut_target ) {
				$cut_idx = $i;
			}
		}
		if ( $cur_idx < 0 ) {
			return null;
		}
		if ( $cut_idx >= 0 && $cur_idx > $cut_idx ) {
			$this->last_advance_status = 'already_advanced';
			return '' !== $cur_name ? $cur_name : (string) $pipeline[ $cur_idx ]['name'];
		}
		if ( ! isset( $pipeline[ $cur_idx + 1 ] ) ) {
			$this->last_advance_status = 'at_last_stage';
			return null;
		}
		$next = $pipeline[ $cur_idx + 1 ];
		$this->rpc( 'editLead', array( 'leadId' => $lead_id, 'rev' => $rev, 'lead' => array( 'milestoneId' => (int) $next['id'] ) ) );
		$this->last_advance_status = 'advanced';
		return (string) ( $next['name'] ?? 'Next stage' );
	}

	/** Promote a lead directly to the cut stage (promote-only; never demotes). */
	public function promote_lead_to_cut( int $lead_id, string $reason ): array {
		$res = array( 'moved' => false, 'skipped' => '', 'from' => '', 'to' => '', 'error' => '' );
		if ( ! $this->is_ready() ) {
			$res['error'] = 'CRM not configured.';
			return $res;
		}
		$stage = ZPREP_Settings::cut_stage_name();
		if ( '' === $stage ) {
			$res['error'] = 'Cut stage not configured.';
			return $res;
		}
		$lead = $this->rpc( 'getLead', array( 'leadId' => $lead_id ) );
		if ( ! is_array( $lead ) ) {
			$res['error'] = 'getLead returned no lead.';
			return $res;
		}
		$rev         = (string) ( $lead['rev'] ?? '' );
		$milestone   = ( isset( $lead['milestone'] ) && is_array( $lead['milestone'] ) ) ? $lead['milestone'] : array();
		$cur_id      = (int) ( $milestone['id'] ?? 0 );
		$cur_name    = (string) ( $milestone['name'] ?? '' );
		$stageset_id = (int) ( $milestone['stagesetId'] ?? ( $lead['stageset']['id'] ?? 0 ) );
		$res['from'] = $cur_name;
		if ( '' === $rev ) {
			$res['error'] = 'Lead has no rev.';
			return $res;
		}
		$pipeline = $this->pipeline_milestones( $stageset_id );
		if ( empty( $pipeline ) ) {
			$res['error'] = 'Pipeline milestones unavailable.';
			return $res;
		}
		$cut_target = strtolower( trim( $stage ) );
		$cur_idx    = -1;
		$cut_idx    = -1;
		$cut_ms     = null;
		foreach ( $pipeline as $i => $ms ) {
			if ( $cur_id && (int) $ms['id'] === $cur_id ) {
				$cur_idx = $i;
			}
			if ( strtolower( trim( (string) $ms['name'] ) ) === $cut_target ) {
				$cut_idx = $i;
				$cut_ms  = $ms;
			}
		}
		if ( $cut_idx < 0 || null === $cut_ms ) {
			$res['error'] = 'Cut stage "' . $stage . '" not found in this lead\'s pipeline.';
			return $res;
		}
		if ( $cur_idx >= 0 && $cur_idx >= $cut_idx ) {
			$res['skipped'] = 'at-or-past-cut';
			$res['to']      = $cur_name;
			return $res;
		}
		$this->rpc( 'editLead', array( 'leadId' => $lead_id, 'rev' => $rev, 'lead' => array( 'milestoneId' => (int) $cut_ms['id'] ) ) );
		$res['moved'] = true;
		$res['to']    = (string) $cut_ms['name'];
		$this->rpc( 'newNote', array( 'entity' => array( 'entityType' => 'Leads', 'id' => $lead_id ), 'note' => $reason ) );
		return $res;
	}

	public function log_cut_activity( int $lead_id ): bool {
		$type_id = $this->resolve_activity_type_id();
		if ( ! $type_id ) {
			return false;
		}
		$result = $this->rpc(
			'newActivity',
			array(
				'activity' => array(
					'activityTypeId' => $type_id,
					'name'           => __( 'Pieces cut — Zorderz Prep', 'zorderz' ),
					'logNote'        => array( 'note' => __( 'Cut sheets generated and cut.', 'zorderz' ) ),
					'status'         => 1,
					'startTime'      => gmdate( 'Y-m-d\TH:i:s\Z' ),
					'endTime'        => gmdate( 'Y-m-d\TH:i:s\Z' ),
					'leads'          => array( array( 'entityType' => 'Leads', 'id' => $lead_id ) ),
				),
			)
		);
		return ! empty( $result );
	}

	private function resolve_activity_type_id(): int {
		$override = (int) get_option( 'zprep_crm_activity_type_id', 0 );
		if ( $override > 0 ) {
			return $override;
		}
		$cached = get_transient( 'zprep_crm_activity_type_id' );
		if ( false !== $cached ) {
			return (int) $cached;
		}
		$id    = 0;
		$types = $this->rpc( 'findActivityTypes', array() );
		if ( is_array( $types ) ) {
			foreach ( $types as $t ) {
				if ( isset( $t['id'] ) && (int) $t['id'] > 0 ) {
					$id = (int) $t['id'];
					break;
				}
			}
		}
		set_transient( 'zprep_crm_activity_type_id', $id, $id > 0 ? 12 * HOUR_IN_SECONDS : 10 * MINUTE_IN_SECONDS );
		return $id;
	}

	/** Full completion sync: validate the target, post the note (idempotent), advance, log. */
	public function sync_completion( int $lead_id, string $note_body ): array {
		$result = array( 'note_ok' => false, 'advance_ok' => false, 'activity_ok' => false, 'new_stage' => null, 'advance_status' => '', 'errors' => array() );

		$lead = $this->get_lead_by_id( $lead_id );
		if ( ! is_array( $lead ) ) {
			$result['errors'][] = __( 'Lead not found — nothing was written to the CRM.', 'zorderz' );
			return $result;
		}
		$stage        = $this->stage_name_for_lead( $lead );
		$cut_stage    = ZPREP_Settings::cut_stage_name();
		$in_cut_stage = ( '' !== $cut_stage && strcasecmp( trim( $stage ), trim( $cut_stage ) ) === 0 );
		$already_cut  = false;
		foreach ( $lead['notes'] ?? array() as $n ) {
			if ( $this->is_machine_cut_note( (string) $n ) ) {
				$already_cut = true;
				break;
			}
		}
		// When a cut stage is configured, only sync a genuine cut job. When none is
		// configured, accept the sync (manual lookup flow) but still guard re-posting.
		if ( '' !== $cut_stage && ! $in_cut_stage && ! $already_cut ) {
			$result['errors'][] = sprintf(
				/* translators: 1: cut stage, 2: current stage. */
				__( 'This lead is not in the "%1$s" stage, so it was not synced. (Current stage: %2$s.)', 'zorderz' ),
				$cut_stage,
				'' !== $stage ? $stage : __( 'unknown', 'zorderz' )
			);
			return $result;
		}

		if ( $already_cut ) {
			$result['note_ok']      = true;
			$result['note_skipped'] = true;
		} else {
			$result['note_ok'] = $this->add_completion_note( $lead_id, $note_body );
			if ( ! $result['note_ok'] ) {
				$result['errors'][] = __( 'Failed to post completion note.', 'zorderz' );
			}
		}

		$new_stage                = $this->advance_lead_stage( $lead_id );
		$result['advance_status'] = $this->last_advance_status;
		if ( $new_stage ) {
			$result['advance_ok'] = true;
			$result['new_stage']  = $new_stage;
		} elseif ( 'at_last_stage' === $this->last_advance_status ) {
			$result['errors'][] = __( 'Lead is already at the final pipeline stage — nothing to advance.', 'zorderz' );
		} else {
			$result['errors'][] = __( 'Could not advance the pipeline stage automatically — please move this lead forward in the CRM.', 'zorderz' );
		}

		$result['activity_ok'] = $this->log_cut_activity( $lead_id );

		ZPREP_Settings::disposition( 'cut_synced', array( 'lead_id' => $lead_id, 'advance' => $result['advance_status'] ) );
		return $result;
	}

	/* ================================================================
	 * HELPERS
	 * ================================================================ */

	private function extract_note_bodies( array $lead ): array {
		$out   = array();
		$notes = $lead['notes'] ?? array();
		if ( is_array( $notes ) ) {
			foreach ( $notes as $n ) {
				if ( ! is_array( $n ) ) {
					continue;
				}
				$body = (string) ( $n['note'] ?? $n['body'] ?? '' );
				if ( '' !== $body ) {
					$out[] = $body;
				}
			}
		}
		if ( empty( $out ) && ! empty( $lead['id'] ) ) {
			$response = $this->rpc( 'findNotes', array( 'query' => array( 'entityType' => 'Leads', 'entityId' => (int) $lead['id'] ) ) );
			if ( is_array( $response ) ) {
				foreach ( $response as $note ) {
					$body = (string) ( $note['note'] ?? $note['body'] ?? '' );
					if ( '' !== $body ) {
						$out[] = $body;
					}
				}
			}
		}
		return $out;
	}

	private function extract_contact_ids( $result ): array {
		$ids = array();
		if ( empty( $result ) || ! is_array( $result ) ) {
			return $ids;
		}
		if ( isset( $result[0] ) ) {
			foreach ( $result as $stub ) {
				if ( is_array( $stub ) && isset( $stub['id'] ) ) {
					$type = $stub['entityType'] ?? '';
					if ( '' === $type || 'Contacts' === $type || stripos( $type, 'contact' ) !== false ) {
						$ids[] = (int) $stub['id'];
					}
				}
			}
		} elseif ( isset( $result['contacts'] ) && is_array( $result['contacts'] ) ) {
			foreach ( $result['contacts'] as $c ) {
				if ( is_array( $c ) && isset( $c['id'] ) ) {
					$ids[] = (int) $c['id'];
				} elseif ( is_int( $c ) ) {
					$ids[] = $c;
				}
			}
		} elseif ( isset( $result['id'] ) ) {
			$ids[] = (int) $result['id'];
		}
		return array_values( array_unique( $ids ) );
	}
}
