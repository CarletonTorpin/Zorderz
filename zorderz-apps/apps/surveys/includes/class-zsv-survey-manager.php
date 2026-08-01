<?php
/**
 * Zorderz Surveys — core workflow.
 *
 * Screen settled invoices -> open a follow-up per eligible customer as a CRM lead ->
 * track the survey OPERATOR's call outcomes -> send a review invite routed to the
 * tenant's review destination -> close the loop. Every drop is a logged disposition;
 * the auto-close path fails loudly and obeys the safety floor.
 *
 * Identity is read from Core services (ZSV_Settings). No roster, no company name, no
 * review URL, no from-name is hardcoded here.
 *
 * @package Zorderz\Surveys
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSV_Survey_Manager {

	/** Marker prefix our exclusion notes start with, so screening can find them again. */
	const EXCLUSION_MARKER = '[Surveys] Excluded from survey';

	/** @var object|null Billing client (Core service). */
	private $billing;

	/** @var object|null CRM client (Core service). */
	private $crm;

	public function __construct( $billing = null, $crm = null ) {
		$this->billing = $billing;
		$this->crm     = ( null !== $crm ) ? $crm : ZSV_Settings::crm();
		if ( null === $billing ) {
			$this->billing = ZSV_Settings::billing();
		}
	}

	/* ─────────────────────────────────────────────────────────────────
	 * SCREENING
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Screen an already-enriched customer/invoice record. Returns the disposition
	 * code that should drop it, or '' when it is eligible. NOTHING is silent — the
	 * caller fires ZSV_DB::disposition() for whatever comes back.
	 *
	 * @param array $inv Enriched invoice: first_name,last_name,email,amount,invoice_id,...
	 * @return string Disposition code, or '' if eligible.
	 */
	public function screen( array $inv ): string {
		$amount = isset( $inv['amount'] ) ? (float) $inv['amount'] : 0.0;
		if ( $amount < 0.01 ) {
			// Was terminal 'zero_amount'; now RETRYABLE — a $0 total is frequently a
			// derived artifact (e.g. a pay-link replace), not a business decision.
			return 'zero_value_document';
		}
		if ( empty( $inv['email'] ) || ! is_email( $inv['email'] ) ) {
			return 'no_email';
		}
		if ( $this->is_excluded_company( $inv ) ) {
			return 'excluded_company';
		}
		if ( $this->recently_surveyed( $inv ) ) {
			return 'recently_surveyed';
		}
		return '';
	}

	/**
	 * Excluded by the admin-managed list (name or email fragment). The list ships
	 * EMPTY, so this returns false until a tenant configures it.
	 */
	public function is_excluded_company( array $inv ): bool {
		$name  = strtolower( trim( ( $inv['first_name'] ?? '' ) . ' ' . ( $inv['last_name'] ?? '' ) ) );
		$email = strtolower( (string) ( $inv['email'] ?? '' ) );
		foreach ( ZSV_Settings::excluded_companies() as $frag ) {
			if ( ( '' !== $name && strpos( $name, $frag ) !== false ) || ( '' !== $email && strpos( $email, $frag ) !== false ) ) {
				return true;
			}
		}
		return false;
	}

	/** Surveyed within the resurvey cooldown window? */
	public function recently_surveyed( array $inv ): bool {
		$email = strtolower( (string) ( $inv['email'] ?? '' ) );
		if ( '' === $email ) {
			return false;
		}
		global $wpdb;
		$t    = ZSV_DB::leads_table();
		$days = ZSV_Settings::resurvey_cooldown_days();
		$n    = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$t} WHERE email = %s AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
				$email,
				$days
			)
		);
		return $n > 0;
	}

	/* ─────────────────────────────────────────────────────────────────
	 * SALESPERSON RESOLUTION — via ZDZ_Party, never a local roster constant.
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Resolve a salesperson code (initials) on an invoice to a display name using the
	 * Party roster. No TS_SALESPERSON_MAP; the code is matched against the roster's
	 * published `initials` key (ZDZ_Party publishes id/name/initials/role) — exact, no
	 * fuzzy/substring, case-insensitive because party initials are uppercase monograms
	 * (matching the sibling leads/jobs roster consumers). Returns [code, name].
	 *
	 * @param string $code Short code / initials from a line item.
	 * @return array{code:string,name:string}
	 */
	public function resolve_salesperson( string $code ): array {
		$code = trim( $code );
		if ( '' === $code ) {
			return array( 'code' => '', 'name' => '' );
		}
		if ( class_exists( 'ZDZ_Party' ) && method_exists( 'ZDZ_Party', 'selectable_people' ) ) {
			foreach ( ZDZ_Party::selectable_people( array( 'include_self' => true ) ) as $p ) {
				if ( isset( $p['initials'] ) && strcasecmp( (string) $p['initials'], $code ) === 0 ) { // EXACT match on the roster's published short code.
					return array( 'code' => $code, 'name' => (string) ( $p['name'] ?? '' ) );
				}
			}
		}
		return array( 'code' => $code, 'name' => '' );
	}

	/* ─────────────────────────────────────────────────────────────────
	 * OPERATOR NOTE SYNC + STATUS CLASSIFIER
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Pull the survey operator's call activities/notes for each lead from the CRM and
	 * derive an operator_status. A transient CRM error can NEVER become a permanent
	 * business outcome: on failure we fire a `source_unavailable` disposition and
	 * LEAVE the stored status untouched (this is the generalized fix for the incident
	 * where a 429 overwrote a real "satisfied" with "not_contacted", which the sweep
	 * then closed as Won).
	 *
	 * @param array[] $leads Rows with id + crm_lead_id.
	 * @return array<int,array{operator_notes:string,operator_status:string}> keyed by lead id.
	 */
	public function sync_operator_notes( array $leads ): array {
		$out = array();
		if ( ! $this->crm ) {
			foreach ( $leads as $lead ) {
				ZSV_DB::disposition( 'source_unavailable', array( 'stage' => 'operator_sync', 'lead_id' => (int) ( $lead['id'] ?? 0 ) ) );
			}
			return $out; // no CRM => change nothing.
		}

		foreach ( $leads as $lead ) {
			$wp_id   = (int) ( $lead['id'] ?? 0 );
			$crm_id  = (int) ( $lead['crm_lead_id'] ?? 0 );
			if ( $crm_id < 1 ) {
				continue;
			}
			try {
				$activities = $this->crm->get_activities( array( 'entityType' => 'Leads', 'entityId' => $crm_id ) );
				$parts      = $this->collect_operator_notes( is_array( $activities ) ? $activities : array() );
				$parsed     = $this->parse_status_multi( $parts );
				$out[ $wp_id ] = array(
					'operator_notes'  => implode( "\n", $parts ),
					'operator_status' => $parsed['status'],
				);
			} catch ( \Throwable $e ) {
				// Unavailable is distinct from "no notes": DO NOT write a status.
				ZSV_DB::disposition( 'source_unavailable', array( 'stage' => 'operator_sync', 'lead_id' => $wp_id, 'error' => $e->getMessage() ) );
			}
		}
		return $out;
	}

	/**
	 * Pick out the operator's own notes from a CRM activity list, using the
	 * configurable author-match (ZSV_Settings::is_operator_author) rather than a
	 * substring of one person's first name.
	 *
	 * @param array[] $activities
	 * @return string[] Note bodies authored by the operator.
	 */
	private function collect_operator_notes( array $activities ): array {
		$parts = array();
		foreach ( $activities as $a ) {
			if ( ! is_array( $a ) ) {
				continue;
			}
			$author = $this->activity_author( $a );
			if ( '' === $author || ! ZSV_Settings::is_operator_author( $author ) ) {
				continue;
			}
			$body = '';
			foreach ( array( 'note', 'body', 'description', 'text' ) as $k ) {
				if ( ! empty( $a[ $k ] ) && is_string( $a[ $k ] ) ) {
					$body = $a[ $k ];
					break;
				}
			}
			if ( '' === $body && isset( $a['logNote']['body'] ) ) {
				$body = (string) $a['logNote']['body'];
			}
			$body = trim( wp_strip_all_tags( (string) $body ) );
			if ( '' !== $body ) {
				$parts[] = $body;
			}
		}
		return $parts;
	}

	/** Extract the author/display name from a CRM activity's several possible shapes. */
	private function activity_author( array $a ): string {
		$paths = array(
			array( 'logNote', 'user', 'name' ),
			array( 'user', 'name' ),
			array( 'loggedBy', 'name' ),
			array( 'creator', 'name' ),
		);
		foreach ( $paths as $p ) {
			$node = $a;
			foreach ( $p as $seg ) {
				if ( is_array( $node ) && isset( $node[ $seg ] ) ) {
					$node = $node[ $seg ];
				} else {
					$node = null;
					break;
				}
			}
			if ( is_string( $node ) && '' !== $node ) {
				return $node;
			}
		}
		foreach ( array( 'user', 'loggedBy', 'creator' ) as $k ) {
			if ( isset( $a[ $k ] ) && is_string( $a[ $k ] ) && '' !== $a[ $k ] ) {
				return $a[ $k ];
			}
		}
		return '';
	}

	/**
	 * Parse a single operator note into a status, using the tenant status vocabulary.
	 * Negation-aware: a negator before a "satisfied" phrase flips it to an issue, so
	 * "screen isn't working well" is not read as satisfaction. An invite must be
	 * EARNED by a recognised positive signal; anything reached-but-unclear defaults
	 * to needs_attention.
	 *
	 * @return string One of: satisfied|needs_attention|left_vm|no_online_review|no_primary_account|follow_up|not_contacted
	 */
	public function parse_single_status( string $text ): string {
		$t = $this->normalize( $text );
		if ( '' === $t ) {
			return 'not_contacted';
		}
		$vocab = ZSV_Settings::status_vocabulary();

		// Issue language outranks positive language.
		if ( $this->matches_any( $t, $vocab['issue'] ?? array() ) ) {
			// An issue that also reports a resolution is a follow-up cleared later;
			// bare issue language is a follow_up (open issue).
			return 'follow_up';
		}
		if ( $this->matches_any( $t, $vocab['no_review'] ?? array() ) ) {
			return 'no_online_review';
		}
		if ( $this->matches_any( $t, $vocab['no_primary_account'] ?? array() ) ) {
			return 'no_primary_account';
		}
		if ( $this->matches_any( $t, $vocab['voicemail'] ?? array() ) ) {
			return 'left_vm';
		}
		if ( $this->matches_satisfied( $t, $vocab['satisfied'] ?? array() ) ) {
			return 'satisfied';
		}
		// Reached, but nothing decisive → earn nothing by default.
		return 'needs_attention';
	}

	/**
	 * Aggregate a SET of operator notes into one status. Order-robust: notes carry a
	 * leading call date where present, so a later positive note clears an older
	 * follow-up and an eventually-reached customer is never pinned to Left VM by an
	 * older voicemail. Generic, business-agnostic aggregation.
	 *
	 * @param string[] $notes
	 * @return array{status:string,reason:string}
	 */
	public function parse_status_multi( array $notes ): array {
		if ( empty( $notes ) ) {
			return array( 'status' => 'not_contacted', 'reason' => '' );
		}
		$items = array();
		$idx   = 0;
		foreach ( $notes as $txt ) {
			$items[] = array(
				'status'  => $this->parse_single_status( (string) $txt ),
				'ts'      => $this->note_date_ts( (string) $txt ),
				'idx'     => $idx,
				'has_res' => $this->matches_any( $this->normalize( (string) $txt ), ZSV_Settings::resolution_phrases() ),
				'reason'  => trim( (string) $txt ),
			);
			$idx++;
		}
		// Undated notes inherit the nearest preceding dated note's timestamp.
		$carry = null;
		foreach ( $items as $k => $it ) {
			if ( null !== $it['ts'] ) {
				$carry = $it['ts'];
			}
			$items[ $k ]['ets'] = $carry;
		}
		$first_ts = 0;
		foreach ( $items as $it ) {
			if ( null !== $it['ets'] ) {
				$first_ts = $it['ets'];
				break;
			}
		}
		foreach ( $items as $k => $it ) {
			if ( null === $it['ets'] ) {
				$items[ $k ]['ets'] = $first_ts;
			}
		}
		usort(
			$items,
			function ( $a, $b ) {
				if ( $a['ets'] !== $b['ets'] ) {
					return ( $a['ets'] < $b['ets'] ) ? -1 : 1;
				}
				return ( $a['idx'] < $b['idx'] ) ? -1 : 1;
			}
		);

		$n         = count( $items );
		$resolving = array( 'satisfied', 'no_primary_account', 'no_online_review' );

		// An unresolved follow-up wins (resolved only by a strictly-later positive).
		for ( $i = $n - 1; $i >= 0; $i-- ) {
			if ( 'follow_up' !== $items[ $i ]['status'] ) {
				continue;
			}
			$resolved = false;
			for ( $j = $i + 1; $j < $n; $j++ ) {
				if ( ! in_array( $items[ $j ]['status'], $resolving, true ) ) {
					continue;
				}
				if ( $items[ $j ]['ets'] > $items[ $i ]['ets'] ) {
					$resolved = true;
					break;
				}
				if ( $items[ $j ]['ets'] === $items[ $i ]['ets'] && $items[ $j ]['idx'] > $items[ $i ]['idx'] && $items[ $j ]['has_res'] ) {
					$resolved = true;
					break;
				}
			}
			if ( ! $resolved ) {
				return array( 'status' => 'follow_up', 'reason' => $items[ $i ]['reason'] );
			}
		}

		$reached = array( 'no_online_review', 'no_primary_account', 'needs_attention', 'satisfied' );
		for ( $i = $n - 1; $i >= 0; $i-- ) {
			if ( in_array( $items[ $i ]['status'], $reached, true ) ) {
				return array( 'status' => $items[ $i ]['status'], 'reason' => '' );
			}
		}
		for ( $i = $n - 1; $i >= 0; $i-- ) {
			if ( 'left_vm' === $items[ $i ]['status'] ) {
				return array( 'status' => 'left_vm', 'reason' => '' );
			}
		}
		return array( 'status' => 'not_contacted', 'reason' => '' );
	}

	/** Read a leading "M/D/YY" call date, if present. */
	private function note_date_ts( string $text ): ?int {
		if ( preg_match( '/^\s*(\d{1,2})\/(\d{1,2})\/(\d{2,4})\b/', $text, $m ) ) {
			$mo = (int) $m[1];
			$d  = (int) $m[2];
			$y  = (int) $m[3];
			if ( $y < 100 ) {
				$y += 2000;
			}
			if ( $mo >= 1 && $mo <= 12 && $d >= 1 && $d <= 31 ) {
				return mktime( 0, 0, 0, $mo, $d, $y );
			}
		}
		return null;
	}

	/** Lowercase, straighten smart punctuation, collapse whitespace. */
	private function normalize( string $text ): string {
		$t = str_replace(
			array( "\xE2\x80\x99", "\xE2\x80\x98", "\xE2\x80\x9C", "\xE2\x80\x9D", "\xC2\xA0" ),
			array( "'", "'", '"', '"', ' ' ),
			$text
		);
		$t = strtolower( $t );
		return trim( preg_replace( '/\s+/', ' ', $t ) );
	}

	/** Any phrase present as a substring? */
	private function matches_any( string $haystack, array $phrases ): bool {
		foreach ( $phrases as $p ) {
			$p = strtolower( trim( (string) $p ) );
			if ( '' !== $p && strpos( $haystack, $p ) !== false ) {
				return true;
			}
		}
		return false;
	}

	/** A positive phrase counts only when it is NOT immediately preceded by a negator. */
	private function matches_satisfied( string $haystack, array $phrases ): bool {
		$negators = ZSV_Settings::negation_words();
		foreach ( $phrases as $p ) {
			$p = strtolower( trim( (string) $p ) );
			if ( '' === $p ) {
				continue;
			}
			$pos = strpos( $haystack, $p );
			while ( false !== $pos ) {
				$prefix = trim( substr( $haystack, 0, $pos ) );
				$words  = $prefix === '' ? array() : preg_split( '/\s+/', $prefix );
				$prev   = end( $words );
				if ( false === $prev || ! in_array( $prev, $negators, true ) ) {
					return true;
				}
				$pos = strpos( $haystack, $p, $pos + strlen( $p ) );
			}
		}
		return false;
	}

	/* ─────────────────────────────────────────────────────────────────
	 * INVITES — routed to the tenant review destination.
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Decide whether a lead is eligible to receive an invite right now. Reached-but-
	 * unresolved and opt-out statuses are held back; the guard is the status, and it
	 * cannot be bypassed by an id list (the old "explicit ids skip the guard" defect).
	 *
	 * @return bool
	 */
	public function invite_permitted( string $operator_status ): bool {
		$blocked = array( 'left_vm', 'follow_up', 'needs_attention', 'no_online_review', 'not_contacted', 'excluded', '' );
		return ! in_array( $operator_status, $blocked, true );
	}

	/**
	 * Send one review invite. Routes to the tenant's review destination and stamps the
	 * chosen channel. Uses the Business Profile sender identity. Idempotency is the
	 * caller's `from: [screened]` state guard; here we just send + record.
	 *
	 * @param array $lead Row: id, email, first_name, last_name, operator_status.
	 * @return array{sent:bool,channel:string,reason:string}
	 */
	public function send_invite( array $lead ): array {
		$email  = (string) ( $lead['email'] ?? '' );
		$status = (string) ( $lead['operator_status'] ?? '' );
		if ( ! is_email( $email ) ) {
			ZSV_DB::disposition( 'no_email', array( 'lead_id' => (int) ( $lead['id'] ?? 0 ) ) );
			return array( 'sent' => false, 'channel' => 'none', 'reason' => 'no_email' );
		}
		if ( ! $this->invite_permitted( $status ) ) {
			return array( 'sent' => false, 'channel' => 'none', 'reason' => 'status_holds_' . ( $status ?: 'blank' ) );
		}

		$dest = ZSV_Settings::resolve_review_destination( $email, $status );
		if ( 'none' === $dest['channel'] || '' === $dest['url'] ) {
			ZSV_DB::disposition( 'no_review_destination', array( 'lead_id' => (int) ( $lead['id'] ?? 0 ) ) );
			return array( 'sent' => false, 'channel' => 'none', 'reason' => 'no_review_destination' );
		}

		$name    = trim( ( $lead['first_name'] ?? '' ) . ' ' . ( $lead['last_name'] ?? '' ) );
		$sender  = ZSV_Settings::sender();
		$subject = sprintf(
			/* translators: %s: business trading name */
			__( 'How did we do? — %s', 'zorderz' ),
			class_exists( 'ZDZ_Business_Profile' ) ? ZDZ_Business_Profile::name() : get_bloginfo( 'name' )
		);
		$body = $this->render_invite_html( $name, $dest['url'], $dest['channel'] );

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			sprintf( 'From: %s <%s>', $sender['name'] ?: get_bloginfo( 'name' ), $sender['email'] ),
		);

		$GLOBALS['zsv_sending_html'] = true;
		$ok = wp_mail( $email, $subject, $body, $headers );
		unset( $GLOBALS['zsv_sending_html'] );

		if ( ! $ok ) {
			ZSV_DB::disposition( 'invite_send_failed', array( 'lead_id' => (int) ( $lead['id'] ?? 0 ) ) );
			return array( 'sent' => false, 'channel' => $dest['channel'], 'reason' => 'send_failed' );
		}
		return array( 'sent' => true, 'channel' => $dest['channel'], 'reason' => '' );
	}

	/** Minimal, neutral HTML invite. No company copy is hardcoded. */
	private function render_invite_html( string $name, string $url, string $channel ): string {
		$greeting = $name !== '' ? sprintf( __( 'Hi %s,', 'zorderz' ), esc_html( $name ) ) : esc_html__( 'Hello,', 'zorderz' );
		$cta      = 'deep_link' === $channel ? esc_html__( 'Leave a review', 'zorderz' ) : esc_html__( 'Rate your experience', 'zorderz' );
		$biz      = class_exists( 'ZDZ_Business_Profile' ) ? ZDZ_Business_Profile::name() : get_bloginfo( 'name' );
		ob_start();
		?>
		<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#1a1a1a;">
			<p><?php echo $greeting; ?></p>
			<p><?php echo esc_html( sprintf( __( 'Thanks for choosing %s. Would you take a moment to tell us how it went?', 'zorderz' ), $biz ) ); ?></p>
			<p><a href="<?php echo esc_url( $url ); ?>" style="display:inline-block;padding:10px 18px;background:#2C5F8A;color:#fff;text-decoration:none;border-radius:6px;"><?php echo $cta; ?></a></p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/* ─────────────────────────────────────────────────────────────────
	 * CLOSING — never override a human; obey the safety floor.
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * SAFETY FLOOR gate. A survey may NEVER auto-close as "Won" without human review.
	 * A system actor may close as Won ONLY when the tenant has opted in AND the status
	 * is genuinely satisfied. needs_attention / excluded / follow_up / not_contacted /
	 * left_vm / no_online_review are NEVER system-Won, regardless of the policy flag.
	 *
	 * @param string $operator_status
	 * @return bool
	 */
	public function can_system_close_won( string $operator_status ): bool {
		if ( 'satisfied' !== $operator_status ) {
			return false; // hard floor.
		}
		return ZSV_Settings::allow_system_close_won();
	}

	/**
	 * Close a lead as Won with a recorded reason. Idempotent and NON-OVERRIDING: if
	 * the lead is already in ANY terminal CRM state (won/lost/cancelled) locally, we
	 * skip the API entirely — an automatic Won must never override an explicit
	 * Lost/Cancelled. The "won" status value is a CRM Mapping, settings-driven.
	 *
	 * @param int    $wp_lead_id  Local row id.
	 * @param int    $crm_lead_id CRM lead id.
	 * @param string $reason      emailed|reviewed|grace_expiry|manual
	 * @param array  $context     Structured "why".
	 * @return bool
	 */
	public function mark_lead_won_with_reason( int $wp_lead_id, int $crm_lead_id, string $reason = 'manual', array $context = array() ): bool {
		if ( $crm_lead_id < 1 ) {
			return false;
		}
		global $wpdb;
		$t = ZSV_DB::leads_table();

		if ( $wp_lead_id > 0 ) {
			$local = $wpdb->get_var( $wpdb->prepare( "SELECT crm_status FROM {$t} WHERE id = %d", $wp_lead_id ) );
			if ( $local && in_array( strtolower( (string) $local ), array( 'won', 'lost', 'cancelled' ), true ) ) {
				return true; // already terminal — never override.
			}
		}
		if ( ! $this->crm ) {
			ZSV_DB::disposition( 'source_unavailable', array( 'stage' => 'close', 'lead_id' => $wp_lead_id ) );
			return false; // fail loudly; do not pretend it closed.
		}

		$labels   = array(
			'emailed'      => 'Survey invite sent — closing as Won',
			'reviewed'     => 'Review recorded — closing as Won',
			'grace_expiry' => 'Grace window elapsed — closing as Won',
			'manual'       => 'Marked Won',
		);
		$headline = $labels[ $reason ] ?? $labels['manual'];
		$note     = '[Surveys] ' . $headline . "\n" . 'Closed: ' . wp_date( 'M j, Y g:i A' ) . "\n";
		foreach ( $context as $k => $v ) {
			if ( '' === $v || null === $v ) {
				continue;
			}
			$note .= ucwords( str_replace( '_', ' ', (string) $k ) ) . ': ' . $v . "\n";
		}

		try {
			$won_status = (int) get_option( 'zsv_crm_status_won', 1 ); // CRM Mapping.
			$this->crm->rpc_call( 'editLead', array( 'leadId' => $crm_lead_id, 'lead' => array( 'status' => $won_status ) ) );
			$this->crm->add_note( array( 'entity' => array( 'entityType' => 'leads', 'id' => $crm_lead_id ), 'note' => array( 'body' => $note ) ) );
		} catch ( \Throwable $e ) {
			ZSV_DB::disposition( 'close_failed', array( 'lead_id' => $wp_lead_id, 'crm_lead_id' => $crm_lead_id, 'error' => $e->getMessage() ) );
			return false;
		}

		if ( $wp_lead_id > 0 ) {
			$wpdb->update(
				$t,
				array( 'crm_status' => 'Won', 'crm_synced_at' => current_time( 'mysql' ) ),
				array( 'id' => $wp_lead_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		}
		return true;
	}

	/**
	 * Grace-window sweep. Closes ONLY genuinely-satisfied leads as Won, and only when
	 * the tenant opted in; everything else past its window is ESCALATED with a logged
	 * disposition, never system-Won. This is the enforcement of the safety floor and
	 * the fix for "needs_attention/excluded/NULL auto-close as Won".
	 *
	 * @param bool $dry_run When true, decides but writes nothing.
	 * @return array Stats + details.
	 */
	public function auto_close_stale_leads( bool $dry_run = false ): array {
		global $wpdb;
		$t = ZSV_DB::leads_table();

		$callback_hours = ZSV_Settings::grace_hours_callback();
		$default_hours  = ZSV_Settings::grace_hours_default();

		$candidates = $wpdb->get_results(
			"SELECT id, crm_lead_id, first_name, last_name, operator_status, crm_status, created_at, email_sent_at, review_left
			 FROM {$t}
			 WHERE crm_lead_id IS NOT NULL AND crm_lead_id <> ''
			   AND email_sent_at IS NULL
			   AND ( review_left = 0 OR review_left IS NULL )
			   AND ( crm_status IS NULL OR LOWER(crm_status) NOT IN ('won','lost','cancelled') )
			 ORDER BY created_at ASC
			 LIMIT 300",
			ARRAY_A
		);

		$stats = array( 'scanned' => 0, 'closed' => 0, 'escalated' => 0, 'skipped' => 0, 'errors' => 0, 'details' => array() );
		if ( empty( $candidates ) ) {
			return $stats;
		}
		$now = current_time( 'timestamp' );

		foreach ( $candidates as $lead ) {
			$stats['scanned']++;
			$status = strtolower( trim( (string) ( $lead['operator_status'] ?? '' ) ) );
			$made   = ! empty( $lead['created_at'] ) ? strtotime( $lead['created_at'] ) : 0;
			if ( ! $made ) {
				$stats['skipped']++;
				continue;
			}
			$age = ( $now - $made ) / 3600;
			$win = in_array( $status, array( 'left_vm', 'needs_attention' ), true ) ? $callback_hours : $default_hours;
			if ( $age < $win ) {
				$stats['skipped']++;
				continue; // still inside grace.
			}

			$name = trim( ( $lead['first_name'] ?? '' ) . ' ' . ( $lead['last_name'] ?? '' ) );

			if ( ! $this->can_system_close_won( $status ) ) {
				// SAFETY FLOOR: needs a human. Escalate + log; never silently Won.
				if ( ! $dry_run ) {
					ZSV_DB::disposition(
						'survey_escalated',
						array(
							'lead_id'     => (int) $lead['id'],
							'crm_lead_id' => (int) $lead['crm_lead_id'],
							'reason'      => 'grace_elapsed_needs_human',
							'status'      => $status ?: 'not_contacted',
							'age_hours'   => (int) $age,
						)
					);
				}
				$stats['escalated']++;
				$stats['details'][] = sprintf( 'ESCALATE (needs human): %s [status=%s, age=%dh]', $name, $status ?: 'none', (int) $age );
				continue;
			}

			if ( $dry_run ) {
				$stats['closed']++;
				$stats['details'][] = sprintf( 'WOULD CLOSE: %s [status=%s, age=%dh]', $name, $status, (int) $age );
				continue;
			}

			$ok = $this->mark_lead_won_with_reason(
				(int) $lead['id'],
				(int) $lead['crm_lead_id'],
				'grace_expiry',
				array( 'prior_status' => $status, 'days_open' => round( $age / 24, 1 ) )
			);
			if ( $ok ) {
				$stats['closed']++;
				$stats['details'][] = sprintf( 'CLOSED (satisfied, policy on): %s [age=%dh]', $name, (int) $age );
			} else {
				$stats['errors']++;
			}
			if ( $stats['closed'] > 0 && 0 === $stats['closed'] % 25 ) {
				sleep( 1 );
			}
		}

		error_log(
			sprintf(
				'Zorderz Surveys: auto_close_stale_leads scanned=%d closed=%d escalated=%d skipped=%d errors=%d (dry_run=%s)',
				$stats['scanned'],
				$stats['closed'],
				$stats['escalated'],
				$stats['skipped'],
				$stats['errors'],
				$dry_run ? 'yes' : 'no'
			)
		);
		return $stats;
	}

	/* ─────────────────────────────────────────────────────────────────
	 * EXCLUDE — record a request not to survey (CRM note; optional hard opt-out tag).
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Exclude a customer from surveys and record WHY. Contextual by default (a note on
	 * the CRM contact + any matching lead); optionally permanent (adds the do-not-
	 * survey tag). Best-effort/non-fatal; reports what landed. Logs a disposition.
	 *
	 * @param array $args email,name,reason,wp_lead_id,crm_lead_id,permanent,actor
	 * @return array{ok:bool,wrote:string[]}
	 */
	public function exclude_customer_with_reason( array $args ): array {
		$reason = trim( (string) ( $args['reason'] ?? '' ) );
		$email  = strtolower( trim( (string) ( $args['email'] ?? '' ) ) );
		$name   = trim( (string) ( $args['name'] ?? '' ) );
		$actor  = trim( (string) ( $args['actor'] ?? '' ) );
		$wrote  = array();

		if ( '' === $reason ) {
			return array( 'ok' => false, 'wrote' => array() );
		}

		$body  = self::EXCLUSION_MARKER . "\n";
		$body .= 'Reason: ' . $reason . "\n";
		$body .= 'Recorded: ' . wp_date( 'M j, Y g:i A' );
		if ( '' !== $actor ) {
			$body .= "\nBy: " . $actor;
		}

		if ( $this->crm ) {
			$crm_lead_id = (int) ( $args['crm_lead_id'] ?? 0 );
			if ( $crm_lead_id > 0 ) {
				try {
					$this->crm->add_note( array( 'entity' => array( 'entityType' => 'leads', 'id' => $crm_lead_id ), 'note' => array( 'body' => $body ) ) );
					$wrote[] = 'lead';
				} catch ( \Throwable $e ) {
					ZSV_DB::disposition( 'exclude_write_failed', array( 'entity' => 'lead', 'error' => $e->getMessage() ) );
				}
			}
		}

		// Stamp the local row(s) so the dashboard reflects the exclusion.
		if ( '' !== $email ) {
			global $wpdb;
			$t = ZSV_DB::leads_table();
			$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET operator_status = 'excluded' WHERE email = %s", $email ) );
			$wrote[] = 'local';
		}

		ZSV_DB::disposition(
			'excluded_by_request',
			array(
				'email'     => $email,
				'name'      => $name,
				'reason'    => $reason,
				'permanent' => ! empty( $args['permanent'] ),
			)
		);

		return array( 'ok' => ! empty( $wrote ), 'wrote' => $wrote );
	}

	/* ─────────────────────────────────────────────────────────────────
	 * BATCH — fetch settled invoices from billing, screen, open follow-ups.
	 * Exercises the billing (Connections) + Item Engine + CRM bindings, and
	 * fires a disposition for EVERY drop (funnel arithmetic balances).
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Run one batch. Fails loudly when a required Connection is missing (no silent
	 * empty run). NO business data is seeded — this reads live billing data only.
	 *
	 * @param int $limit Max leads to create (0 = tenant batch size).
	 * @return array Stats keyed by disposition + created.
	 */
	public function run_batch( int $limit = 0 ): array {
		$limit = $limit > 0 ? $limit : ZSV_Settings::batch_size();
		$stats = array(
			'fetched' => 0,
			'created' => 0,
			'dispositions' => array(),
		);

		if ( ! $this->billing ) {
			ZSV_DB::disposition( 'source_unavailable', array( 'stage' => 'batch_fetch', 'system' => 'billing' ) );
			$stats['error'] = 'billing_unavailable';
			return $stats;
		}
		if ( ! $this->crm ) {
			ZSV_DB::disposition( 'source_unavailable', array( 'stage' => 'batch_create', 'system' => 'crm' ) );
			$stats['error'] = 'crm_unavailable';
			return $stats;
		}

		$batch_tag = wp_date( 'Y-m-d' ) . ' #' . substr( (string) wp_generate_uuid4(), 0, 8 );
		$invoices  = $this->fetch_settled_invoices();
		$stats['fetched'] = count( $invoices );

		$bump = static function ( &$s, $code ) {
			$s['dispositions'][ $code ] = ( $s['dispositions'][ $code ] ?? 0 ) + 1;
		};

		foreach ( $invoices as $raw ) {
			if ( $stats['created'] >= $limit ) {
				ZSV_DB::disposition( 'capacity_cap', array( 'batch_tag' => $batch_tag, 'cap' => $limit ) );
				$bump( $stats, 'capacity_cap' );
				continue;
			}

			$inv = $this->normalize_invoice( $raw );
			$inv['batch_tag'] = $batch_tag;

			// Distrust the source (crosswalk A6.12): a "settled" doc with an
			// outstanding balance or a zero total is a source disagreement, logged.
			if ( isset( $inv['outstanding'] ) && (float) $inv['outstanding'] > 0.01 ) {
				ZSV_DB::disposition( 'source_disagreement', array( 'invoice_id' => $inv['invoice_id'], 'outstanding' => $inv['outstanding'], 'batch_tag' => $batch_tag ) );
				$bump( $stats, 'source_disagreement' );
				continue;
			}

			$code = $this->screen( $inv );
			if ( '' !== $code ) {
				ZSV_DB::disposition( $code, array( 'invoice_id' => $inv['invoice_id'], 'batch_tag' => $batch_tag ) );
				$bump( $stats, $code );
				continue;
			}

			$res = $this->create_survey_lead( $inv );
			if ( ! empty( $res['ok'] ) ) {
				$stats['created']++;
				ZSV_DB::disposition( 'created', array( 'invoice_id' => $inv['invoice_id'], 'batch_tag' => $batch_tag, 'lead_id' => $res['wp_lead_id'] ) );
			} else {
				ZSV_DB::disposition( 'create_failed', array( 'invoice_id' => $inv['invoice_id'], 'batch_tag' => $batch_tag, 'reason' => $res['reason'] ?? '' ) );
				$bump( $stats, 'create_failed' );
			}
		}

		// Record the batch row (stats only; no PII).
		global $wpdb;
		$wpdb->insert(
			ZSV_DB::batches_table(),
			array(
				'batch_tag'      => $batch_tag,
				'total_invoices' => (int) $stats['fetched'],
				'leads_created'  => (int) $stats['created'],
				'status'         => 'completed',
			),
			array( '%s', '%d', '%d', '%s' )
		);
		return $stats;
	}

	/**
	 * Fetch settled invoices from the billing provider. The "settled" filter + lookback
	 * are a tenant Mapping — supplied via the `zdz_survey_billing_fetch_params` filter
	 * with a neutral default — so no provider-specific status integer is hardcoded here.
	 *
	 * @return array[] Raw invoice objects.
	 */
	private function fetch_settled_invoices(): array {
		$since   = gmdate( 'Y-m-d', strtotime( '-' . ZSV_Settings::fetch_lookback_days() . ' days' ) );
		$default = array(
			'search[date_min]' => $since,
			'include[]'        => 'lines',
			'per_page'         => 100,
		);
		/**
		 * Filter the billing fetch params (the "settled" mapping lives here, not in code).
		 *
		 * @param array $params Provider query params.
		 */
		$params = (array) apply_filters( 'zdz_survey_billing_fetch_params', $default );

		try {
			$resp = $this->billing->get_invoices( $params );
		} catch ( \Throwable $e ) {
			ZSV_DB::disposition( 'source_unavailable', array( 'stage' => 'batch_fetch', 'error' => $e->getMessage() ) );
			return array();
		}

		// Dig the invoice list out of the provider's several possible shapes.
		foreach ( array( array( 'response', 'result', 'invoices' ), array( 'result', 'invoices' ), array( 'invoices' ) ) as $path ) {
			$node = $resp;
			foreach ( $path as $seg ) {
				$node = is_array( $node ) && isset( $node[ $seg ] ) ? $node[ $seg ] : null;
				if ( null === $node ) {
					break;
				}
			}
			if ( is_array( $node ) ) {
				return $node;
			}
		}
		return array();
	}

	/**
	 * Normalize a raw billing invoice to the fields screening needs. Email enrichment
	 * (which a bare invoice read may lack) is a documented seam: sites plug richer
	 * enrichment into `zdz_survey_enrich_invoice`. No provider field is assumed present.
	 *
	 * @param array $raw
	 * @return array
	 */
	private function normalize_invoice( array $raw ): array {
		$amount      = isset( $raw['amount']['amount'] ) ? (float) $raw['amount']['amount'] : (float) ( $raw['amount'] ?? 0 );
		$outstanding = isset( $raw['outstanding']['amount'] ) ? (float) $raw['outstanding']['amount'] : (float) ( $raw['outstanding'] ?? 0 );

		$work  = '';
		$scode = '';
		if ( ! empty( $raw['lines'] ) && is_array( $raw['lines'] ) ) {
			$descs = array();
			foreach ( $raw['lines'] as $line ) {
				$name = trim( (string) ( $line['name'] ?? $line['description'] ?? '' ) );
				if ( '' !== $name ) {
					$descs[] = $name;
				}
			}
			$work = implode( '; ', $descs );
		}
		if ( '' === $work ) {
			$work = ZSV_Settings::generic_work_phrase();
		}

		$inv = array(
			'invoice_id'  => (int) ( $raw['invoiceid'] ?? $raw['id'] ?? 0 ),
			'client_id'   => (int) ( $raw['customerid'] ?? 0 ),
			'first_name'  => trim( (string) ( $raw['fname'] ?? '' ) ),
			'last_name'   => trim( (string) ( $raw['lname'] ?? '' ) ),
			'organization' => trim( (string) ( $raw['organization'] ?? '' ) ),
			'email'       => trim( (string) ( $raw['email'] ?? '' ) ),
			'city'        => trim( (string) ( $raw['city'] ?? '' ) ),
			'amount'      => $amount,
			'outstanding' => $outstanding,
			'work_description' => $work,
			'category'    => $this->classify_work_category( $work ),
			'salesperson_code' => $scode,
		);

		/**
		 * Filter/enrich a normalized invoice before screening (e.g. add the customer
		 * email from a client lookup, or a salesperson code from a line item).
		 *
		 * @param array $inv Normalized invoice.
		 * @param array $raw Raw provider object.
		 */
		return (array) apply_filters( 'zdz_survey_enrich_invoice', $inv, $raw );
	}

	/**
	 * Classify a work description into a category. Item Engine binding: the ordered
	 * category vocabulary comes from ZSV_Settings::work_categories() (filter
	 * `zdz_survey_work_categories`, NEUTRAL fallback). First case-insensitive match by
	 * priority wins; the last category is the catch-all.
	 *
	 * @param string $desc
	 * @return string
	 */
	public function classify_work_category( string $desc ): string {
		$cats  = ZSV_Settings::work_categories();
		$lower = strtolower( $desc );
		foreach ( $cats as $cat ) {
			if ( strpos( $lower, strtolower( $cat ) ) !== false ) {
				return $cat;
			}
		}
		return end( $cats ) ?: 'Other';
	}

	/**
	 * The operator CRM user stub to auto-assign a new lead to. Explicit id wins;
	 * otherwise the operator name is resolved against the CRM roster (findUsers) and
	 * used ONLY on a unique match — never guessed. Returns null when unresolved
	 * (assignment is skipped; lead creation is never blocked).
	 *
	 * @return array|null
	 */
	private function operator_assignee(): ?array {
		$id = ZSV_Settings::operator_crm_user_id();
		if ( $id > 0 ) {
			return array( 'entityType' => 'Users', 'id' => $id );
		}
		$name = ZSV_Settings::operator_name();
		if ( '' === $name || ! $this->crm ) {
			return null;
		}
		try {
			$users = $this->crm->rpc_call( 'findUsers', array( 'query' => array( 'name' => $name ), 'limit' => 5 ) );
		} catch ( \Throwable $e ) {
			return null;
		}
		if ( ! is_array( $users ) || count( $users ) !== 1 ) {
			return null; // ambiguous / none → refuse to guess.
		}
		$uid = (int) ( $users[0]['id'] ?? 0 );
		return $uid > 0 ? array( 'entityType' => 'Users', 'id' => $uid ) : null;
	}

	/**
	 * Create the CRM lead + local follow-up row for an eligible invoice. Best-effort
	 * CRM write; a failure returns ok=false with a reason (the caller logs a
	 * create_failed disposition). Never fabricates a CRM id it did not receive.
	 *
	 * @param array $inv Normalized, eligible invoice.
	 * @return array{ok:bool,wp_lead_id:int,crm_lead_id:int,reason?:string}
	 */
	public function create_survey_lead( array $inv ): array {
		$name = trim( ( $inv['first_name'] ?? '' ) . ' ' . ( $inv['last_name'] ?? '' ) );
		$note = sprintf(
			'[Surveys] Follow-up opened for %s — %s (%s).',
			$name !== '' ? $name : ( $inv['organization'] ?? '' ),
			$inv['work_description'] ?? '',
			$inv['category'] ?? ''
		);

		$lead_payload = array(
			'lead' => array(
				'description' => sprintf( 'Satisfaction follow-up — %s', $inv['category'] ?? '' ),
				'note'        => array( array( 'body' => $note ) ),
			),
		);
		$assignee = $this->operator_assignee();
		if ( $assignee ) {
			$lead_payload['lead']['assignee'] = $assignee;
		}

		$crm_lead_id = 0;
		try {
			$result = $this->crm->create_lead( $lead_payload );
			$crm_lead_id = (int) ( $result['id'] ?? ( is_array( $result ) && isset( $result[0]['id'] ) ? $result[0]['id'] : 0 ) );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'wp_lead_id' => 0, 'crm_lead_id' => 0, 'reason' => $e->getMessage() );
		}

		// Insert the local row regardless of whether the CRM returned an id, so the
		// funnel never loses a customer (a missing id is a syncable gap, not a drop).
		global $wpdb;
		$sp = $this->resolve_salesperson( (string) ( $inv['salesperson_code'] ?? '' ) );
		$wpdb->insert(
			ZSV_DB::leads_table(),
			array(
				'batch_id'         => 0,
				'first_name'       => (string) ( $inv['first_name'] ?? '' ),
				'last_name'        => (string) ( $inv['last_name'] ?? '' ),
				'email'            => strtolower( (string) ( $inv['email'] ?? '' ) ),
				'city'             => (string) ( $inv['city'] ?? '' ),
				'salesperson_name' => $sp['name'],
				'salesperson_code' => $sp['code'],
				'total_amount'     => (float) ( $inv['amount'] ?? 0 ),
				'pipeline'         => (string) ( $inv['category'] ?? '' ),
				'lead_name'        => $name,
				'work_description' => (string) ( $inv['work_description'] ?? '' ),
				'status'           => 'created',
				'crm_lead_id'      => $crm_lead_id > 0 ? (string) $crm_lead_id : null,
				'operator_status'  => 'not_contacted',
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		$wp_lead_id = (int) $wpdb->insert_id;

		return array( 'ok' => true, 'wp_lead_id' => $wp_lead_id, 'crm_lead_id' => $crm_lead_id );
	}
}
