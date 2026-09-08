<?php
/**
 * ZIB_Ingest — the P1 ingestion engine (pulls in-scope mail into the Ballast).
 *
 * FLOW (per 'ok' account whose index_mode != 'none', on the sync cron):
 *   1. Initialize forward-sync cursors at "now" (delta) once.
 *   2. Backfill the 12-month window in bounded page batches (Inbox + Sent).
 *   3. Forward-sync via delta each tick; honor upstream removals.
 * For every message: dedupe (seen) → classify from participants →
 * keep_for_mode? → if not, record a CONTENT-FREE 'excluded' seen row and stop;
 * if yes, TWO-STAGE fetch the body, encrypt it (ZIB_Crypto), store.
 *
 * DARK BY DEFAULT: cron_all() no-ops unless BOTH the feature flag and the
 * separate ingestion kill-switch (ZIB_Settings::ingest_enabled) are on — so
 * deploying P1 reads nothing until the owner deliberately turns ingestion on.
 *
 * normalize_graph_message() is PURE (no WP/Graph) so the parse + participant
 * extraction is unit-testable.
 *
 * @since 0.2.0 (P1 — Ballast + ingest)
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIB_Ingest {

	const MAX_BACKFILL_PAGES_PER_TICK = 4;   // ~100 messages/account/tick
	const MAX_DELTA_PAGES             = 10;
	const PRIME_PAGES                 = 80;   // v0.9.4: max metadata pages per folder per priming burst
	const PRIME_BUDGET                = 120;  // v0.9.4: wall-clock seconds for one prime_all pass
	const PRIME_DELAY                 = 75;   // v0.9.4: seconds between bursts (< the ~9-min skiptoken TTL)
	const FOLDERS                     = array( 'inbox', 'sent' );
	const ALLFOLDER_OPTION            = 'zib_allfolder_sync'; // per-folder (all-folder) sync switch; default OFF.

	// ── tables ──────────────────────────────────────────────────────
	private static function t_acct(): string { global $wpdb; return $wpdb->prefix . 'zib_accounts'; }
	private static function t_msg(): string { global $wpdb; return $wpdb->prefix . 'zib_messages'; }
	private static function t_party(): string { global $wpdb; return $wpdb->prefix . 'zib_participants'; }
	private static function t_seen(): string { global $wpdb; return $wpdb->prefix . 'zib_seen'; }

	/** Is the per-folder (all-folder) sync path enabled? Default OFF — the inbox/sent loop runs. */
	public static function allfolder_enabled(): bool {
		return '1' === (string) get_option( self::ALLFOLDER_OPTION, '' );
	}

	// ── cron entry ──────────────────────────────────────────────────

	/** Sync every eligible account. Feature + ingest gated, self-locking, fail-soft. */
	public static function cron_all(): void {
		if ( ! ZIB_Settings::feature_enabled() ) {
			return;
		}
		$do_ingest = ZIB_Settings::ingest_enabled();
		$do_enrich = ZIB_Settings::enrich_enabled() && class_exists( 'ZIB_Enrich' );
		if ( ! $do_ingest && ! $do_enrich ) {
			return;
		}
		if ( get_transient( 'zib_ingest_running' ) ) {
			return;
		}
		set_transient( 'zib_ingest_running', 1, 4 * MINUTE_IN_SECONDS );
		try {
			if ( $do_ingest ) {
				foreach ( self::eligible_accounts() as $acct ) {
					try {
						self::sync_account( $acct );
					} catch ( \Throwable $e ) {
						self::log( 'account ' . (int) $acct->id . ' sync error: ' . $e->getMessage() );
					}
				}
			}
			// P6a: re-enrich already-indexed mail in bounded batches (runs even with
			// forward ingest paused, so the owner can enrich the existing index alone).
			if ( $do_enrich ) {
				try {
					ZIB_Enrich::backfill_batch();
				} catch ( \Throwable $e ) {
					self::log( 'enrich backfill error: ' . $e->getMessage() );
				}
			}
		} finally {
			delete_transient( 'zib_ingest_running' );
		}
	}

	// ── priming: drain the initial enumeration to a stable anchor, cheaply ──

	/**
	 * v0.9.4: when delta_init returns a nextLink ENUMERATION rather than an empty "now" anchor,
	 * walk it to the deltaLink in bounded, METADATA-ONLY, time-boxed bursts, self-rescheduling a
	 * fast follow-up (< the skiptoken TTL) until anchored. No body fetches — history is the
	 * backfill's job — so a huge enumeration converges across bursts without hitting the ~3-min
	 * WP Engine limit. Runs on the 'zib_prime_sync' single-event hook. Feature/ingest gated, locked.
	 */
	public static function prime_all(): void {
		if ( ! ZIB_Settings::feature_enabled() || ! ZIB_Settings::ingest_enabled() ) {
			return;
		}
		if ( get_transient( 'zib_priming_running' ) ) {
			return;
		}
		set_transient( 'zib_priming_running', 1, 3 * MINUTE_IN_SECONDS );
		$deadline = microtime( true ) + self::PRIME_BUDGET;
		$more     = false;
		try {
			foreach ( self::eligible_accounts() as $acct ) {
				foreach ( self::FOLDERS as $folder ) {
					if ( microtime( true ) >= $deadline ) { $more = true; break 2; }
					if ( 'priming' === self::prime_folder( $acct, $folder, $deadline ) ) { $more = true; }
				}
			}
		} catch ( \Throwable $e ) {
			self::log( 'prime error: ' . $e->getMessage() );
			$more = true;
		} finally {
			delete_transient( 'zib_priming_running' );
		}
		if ( $more && ! wp_next_scheduled( 'zib_prime_sync' ) ) {
			wp_schedule_single_event( time() + self::PRIME_DELAY, 'zib_prime_sync' );
		}
	}

	/** Drain one folder's enumeration toward the deltaLink, metadata-only, until PRIME_PAGES or the
	 *  shared wall-clock deadline. Returns 'anchored' | 'priming' | 'idle' | 'error'. */
	private static function prime_folder( $acct, string $folder, float $deadline ): string {
		$acct_id = (int) $acct->id;
		$cursor  = ( 'inbox' === $folder ) ? (string) $acct->delta_inbox_cursor : (string) $acct->delta_sent_cursor;
		if ( '' === $cursor ) {
			$c = ZIB_Graph::delta_init( $acct_id, $folder );
			if ( is_wp_error( $c ) ) { self::log( 'prime acct ' . $acct_id . ' ' . $folder . ': init ' . $c->get_error_code() ); return 'error'; }
			self::save_cursor( $acct_id, $folder, $c ); $cursor = $c;
		}
		if ( false !== stripos( $cursor, 'deltatoken=' ) ) {
			return 'idle'; // already anchored — normal run_delta owns it
		}
		$pages = 0;
		for ( $i = 0; $i < self::PRIME_PAGES && microtime( true ) < $deadline; $i++ ) {
			$res = ZIB_Graph::delta_page( $acct_id, $cursor );
			if ( is_wp_error( $res ) ) {
				$code = $res->get_error_code();
				if ( 'zib_delta_invalid' === $code || 'zib_graph_410' === $code ) {
					self::save_cursor( $acct_id, $folder, '' ); // restart the walk next burst
					self::log( 'prime acct ' . $acct_id . ' ' . $folder . ': cursor expired mid-prime — restart' );
				} else {
					self::log( 'prime acct ' . $acct_id . ' ' . $folder . ': ' . $code );
				}
				return 'error';
			}
			$pages++;
			if ( '' !== $res['next'] ) {
				$cursor = $res['next'];
				self::save_cursor( $acct_id, $folder, $cursor ); // persist each page — the next burst resumes HERE
				continue;
			}
			if ( '' !== $res['delta'] ) {
				self::save_cursor( $acct_id, $folder, $res['delta'] );
			}
			self::log( 'primed acct ' . $acct_id . ' ' . $folder . ': anchored (drained ' . $pages . ' page(s) this burst)' );
			return 'anchored';
		}
		self::log( 'prime acct ' . $acct_id . ' ' . $folder . ': +' . $pages . ' page(s), still priming — resume in ' . self::PRIME_DELAY . 's' );
		return 'priming';
	}

	/** @return array<int,object> accounts that are ok and actually indexing. */
	private static function eligible_accounts(): array {
		global $wpdb;
		return (array) $wpdb->get_results(
			"SELECT * FROM " . self::t_acct() . " WHERE status = 'ok' AND index_mode <> 'none'" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
	}

	// ── per-account sync ────────────────────────────────────────────

	public static function sync_account( $acct ): void {
		$acct_id = (int) $acct->id;

		// 1) Initialize delta cursors at "now" once (best-effort; retried later).
		if ( '' === (string) $acct->delta_inbox_cursor ) {
			$c = ZIB_Graph::delta_init( $acct_id, 'inbox' );
			if ( ! is_wp_error( $c ) ) { self::save_cursor( $acct_id, 'inbox', $c ); $acct->delta_inbox_cursor = $c; }
			else { self::log( 'delta_init acct ' . $acct_id . ' inbox: ' . $c->get_error_code() ); }
		}
		if ( '' === (string) $acct->delta_sent_cursor ) {
			$c = ZIB_Graph::delta_init( $acct_id, 'sent' );
			if ( ! is_wp_error( $c ) ) { self::save_cursor( $acct_id, 'sent', $c ); $acct->delta_sent_cursor = $c; }
			else { self::log( 'delta_init acct ' . $acct_id . ' sent: ' . $c->get_error_code() ); }
		}

		// 2) Backfill the history window in a bounded batch.
		if ( in_array( (string) $acct->backfill_status, array( 'pending', 'running' ), true ) ) {
			self::run_backfill_batch( $acct );
		}

		// 3) Forward delta each folder.
		foreach ( self::FOLDERS as $folder ) {
			self::run_delta( $acct, $folder );
		}
	}

	private static function window_iso( $acct ): string {
		$ws = (string) $acct->window_start;
		if ( '' === $ws ) {
			$ws = gmdate( 'Y-m-d H:i:s', strtotime( '-1 year' ) );
		}
		return str_replace( ' ', 'T', $ws ) . 'Z';
	}

	private static function run_backfill_batch( $acct ): void {
		global $wpdb;
		$acct_id = (int) $acct->id;
		$state   = json_decode( (string) $acct->backfill_cursor, true );
		if ( ! is_array( $state ) || empty( $state['folder'] ) ) {
			$state = array( 'folder' => 'inbox', 'next' => '' );
		}
		$wpdb->update( self::t_acct(), array( 'backfill_status' => 'running' ), array( 'id' => $acct_id ), array( '%s' ), array( '%d' ) );

		$since = self::window_iso( $acct );
		for ( $page = 0; $page < self::MAX_BACKFILL_PAGES_PER_TICK; $page++ ) {
			$res = ZIB_Graph::backfill_page( $acct_id, (string) $state['folder'], $since, (string) $state['next'] );
			if ( is_wp_error( $res ) ) {
				self::log( 'backfill ' . $state['folder'] . ' acct ' . $acct_id . ': ' . $res->get_error_code() );
				self::save_backfill( $acct_id, $state ); // resume next tick
				return;
			}
			foreach ( $res['messages'] as $m ) {
				self::ingest_one( $acct, $m, (string) $state['folder'] );
			}
			if ( '' !== $res['next'] ) {
				$state['next'] = $res['next'];
				continue;
			}
			// Folder exhausted → advance.
			if ( 'inbox' === $state['folder'] ) {
				$state = array( 'folder' => 'sent', 'next' => '' );
				continue;
			}
			// Both folders done.
			$wpdb->update( self::t_acct(), array( 'backfill_status' => 'done', 'backfill_cursor' => '', 'last_synced_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $acct_id ), array( '%s', '%s', '%s' ), array( '%d' ) );
			self::log( 'backfill complete acct ' . $acct_id );
			return;
		}
		self::save_backfill( $acct_id, $state );
	}

	private static function run_delta( $acct, string $folder ): void {
		global $wpdb;
		$acct_id = (int) $acct->id;
		$cursor  = ( 'inbox' === $folder ) ? (string) $acct->delta_inbox_cursor : (string) $acct->delta_sent_cursor;
		if ( '' === $cursor ) {
			return; // not initialized yet (delta_init runs in sync_account)
		}
		// v0.9.4: a skiptoken cursor means we are still walking the initial "latest" enumeration
		// (priming), not doing incremental delta. Body-fetching every one of those pages blew the
		// WP Engine ~3-min tick (v0.9.3 chase-timeout). Hand it to the lightweight, metadata-only
		// prime pass (bounded, self-rescheduled in fast bursts) and do NOT ingest bodies here.
		if ( false === stripos( $cursor, 'deltatoken=' ) ) {
			if ( ! wp_next_scheduled( 'zib_prime_sync' ) ) {
				wp_schedule_single_event( time() + self::PRIME_DELAY, 'zib_prime_sync' );
			}
			return;
		}
		$pulled = 0; $removed_n = 0;
		for ( $i = 0; $i < self::MAX_DELTA_PAGES; $i++ ) {
			$res = ZIB_Graph::delta_page( $acct_id, $cursor );
			if ( is_wp_error( $res ) ) {
				$code = $res->get_error_code();
				// A 410 / invalid delta token NEVER clears on retry — Graph requires a full
				// re-sync. Silently keeping the dead cursor stalled forward ingest for hours in
				// the field (v0.9.1 incident). Reset the cursor so delta re-inits next tick, and
				// re-arm the bounded, dedupe-guarded backfill so mail that arrived during the
				// stall is recaptured (wp_zib_seen absorbs the overlap — no dupes, no loss).
				if ( 'zib_delta_invalid' === $code || 'zib_graph_410' === $code ) {
					self::save_cursor( $acct_id, $folder, '' );
					// v0.9.3: re-arm the history backfill ONCE to recapture the stall gap — only if it
					// had COMPLETED. Never restart an in-progress backfill (that caused re-arm churn in
					// v0.9.2 when a cursor expired repeatedly during the initial sync).
					$rearmed = false;
					if ( 'done' === (string) $acct->backfill_status ) {
						$wpdb->update( self::t_acct(), array( 'backfill_status' => 'pending', 'backfill_cursor' => '' ), array( 'id' => $acct_id ), array( '%s', '%s' ), array( '%d' ) );
						$acct->backfill_status = 'pending';
						$rearmed = true;
					}
					self::log( 'delta acct ' . $acct_id . ' ' . $folder . ': cursor expired (' . $code . ') — reset' . ( $rearmed ? ' + backfill re-armed' : '' ) . '; resync next tick' );
				} else {
					self::log( 'delta acct ' . $acct_id . ' ' . $folder . ': ' . $code . ' — cursor kept, retry next tick' );
				}
				return;
			}
			foreach ( $res['removed'] as $rid ) {
				self::delete_by_ms_id( $acct_id, $rid );
				$removed_n++;
			}
			foreach ( $res['items'] as $m ) {
				self::ingest_one( $acct, $m, $folder );
				$pulled++;
			}
			if ( '' !== $res['next'] ) {
				$cursor = $res['next'];
				self::save_cursor( $acct_id, $folder, $cursor ); // v0.9.3: persist progress — a budget stop / mid-tick timeout resumes HERE, not from the start
				continue;
			}
			if ( '' !== $res['delta'] ) {
				self::save_cursor( $acct_id, $folder, $res['delta'] );
			}
			$wpdb->update( self::t_acct(), array( 'last_synced_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $acct_id ), array( '%s' ), array( '%d' ) );
			if ( $pulled > 0 || $removed_n > 0 ) {
				self::log( 'delta acct ' . $acct_id . ' ' . $folder . ': pulled ' . $pulled . ' new, ' . $removed_n . ' removed' );
			}
			return;
		}
		self::log( 'delta acct ' . $acct_id . ' ' . $folder . ': page budget reached — resuming next tick (pulled ' . $pulled . ' so far)' );
	}

	// ── one message ─────────────────────────────────────────────────

	public static function ingest_one( $acct, array $m, string $folder ): void {
		$acct_id = (int) $acct->id;
		$norm    = self::normalize_graph_message( $m, $folder );
		if ( '' === $norm['ms_message_id'] ) {
			return;
		}
		if ( self::seen_exists( $acct_id, $norm['ms_message_id'] ) ) {
			return; // dedupe
		}

		$class = ZIB_Classifier::classify_message( $norm['participant_addrs'] );

		if ( ! ZIB_Classifier::keep_for_mode( $class, (string) $acct->index_mode ) ) {
			self::record_seen( $acct_id, $norm['ms_message_id'], $class, 'excluded', $norm['received_at'] );
			return; // out of scope — body NEVER fetched
		}

		$body = ZIB_Graph::fetch_body( $acct_id, $norm['ms_message_id'] );
		if ( is_wp_error( $body ) ) {
			return; // transient — no seen row, so we retry next tick
		}

		self::store_message( $acct, $norm, $class, $body, $folder );
		self::record_seen( $acct_id, $norm['ms_message_id'], $class, 'indexed', $norm['received_at'] );
	}

	/**
	 * PURE parse of a Graph message into a normalized record. No WP/Graph calls.
	 *
	 * @return array
	 */
	public static function normalize_graph_message( array $m, string $folder ): array {
		$parties     = array();
		$addrs       = array();
		$from        = self::addr_of( $m['from'] ?? null );
		if ( '' !== $from['addr'] ) {
			$parties[] = array( 'role' => 'from', 'addr' => $from['addr'], 'name' => $from['name'] );
			$addrs[]   = $from['addr'];
		}
		foreach ( array( 'toRecipients' => 'to', 'ccRecipients' => 'cc' ) as $key => $role ) {
			foreach ( (array) ( $m[ $key ] ?? array() ) as $r ) {
				$a = self::addr_of( $r );
				if ( '' !== $a['addr'] ) {
					$parties[] = array( 'role' => $role, 'addr' => $a['addr'], 'name' => $a['name'] );
					$addrs[]   = $a['addr'];
				}
			}
		}

		return array(
			'ms_message_id'          => (string) ( $m['id'] ?? '' ),
			'ms_internet_message_id' => (string) ( $m['internetMessageId'] ?? '' ),
			'ms_conversation_id'     => (string) ( $m['conversationId'] ?? '' ),
			'folder'                 => $folder,
			'direction'              => ( 'sent' === $folder ) ? 'out' : 'in',
			'from_addr'              => $from['addr'],
			'from_name'              => $from['name'],
			'received_at'            => self::iso_to_mysql( (string) ( $m['receivedDateTime'] ?? '' ) ),
			'sent_at'                => self::iso_to_mysql( (string) ( $m['sentDateTime'] ?? '' ) ),
			'subject'                => self::clip( (string) ( $m['subject'] ?? '' ), 512 ),
			'snippet'                => self::clip( (string) ( $m['bodyPreview'] ?? '' ), 512 ),
			'has_attachments'        => ! empty( $m['hasAttachments'] ) ? 1 : 0,
			'importance'             => self::clip( (string) ( $m['importance'] ?? 'normal' ), 16 ),
			'parties'                => $parties,
			'participant_addrs'      => $addrs,
			'parties_text'           => self::clip( trim( implode( ' ', array_map( function ( $p ) {
				return $p['addr'] . ' ' . $p['name'];
			}, $parties ) ) ), 2000 ),
		);
	}

	private static function addr_of( $node ): array {
		$e = is_array( $node ) && isset( $node['emailAddress'] ) ? $node['emailAddress'] : array();
		return array(
			'addr' => strtolower( trim( (string) ( $e['address'] ?? '' ) ) ),
			'name' => trim( (string) ( $e['name'] ?? '' ) ),
		);
	}

	private static function iso_to_mysql( string $iso ): ?string {
		if ( '' === $iso ) { return null; }
		$ts = strtotime( $iso );
		return $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : null;
	}

	private static function clip( string $s, int $len ): string {
		return ( strlen( $s ) > $len ) ? substr( $s, 0, $len ) : $s;
	}

	// ── storage ─────────────────────────────────────────────────────

	private static function store_message( $acct, array $norm, string $class, array $body, string $folder ): void {
		global $wpdb;
		$owner = (int) $acct->owner_user_id;

		$ok = $wpdb->insert( self::t_msg(), array(
			'owner_user_id'          => $owner,
			'account_id'             => (int) $acct->id,
			'ms_message_id'          => $norm['ms_message_id'],
			'ms_internet_message_id' => $norm['ms_internet_message_id'],
			'ms_conversation_id'     => $norm['ms_conversation_id'],
			'folder'                 => $folder,
			'direction'              => $norm['direction'],
			'class'                  => $class,
			'from_addr'              => $norm['from_addr'],
			'from_name'              => $norm['from_name'],
			'received_at'            => $norm['received_at'],
			'sent_at'                => $norm['sent_at'],
			'subject'                => $norm['subject'],
			'snippet'                => $norm['snippet'],
			'parties_text'           => $norm['parties_text'],
			'body_enc'               => ZIB_Crypto::encrypt( (string) $body['content'] ),
			'body_format'            => (string) $body['format'],
			'has_attachments'        => (int) $norm['has_attachments'],
			'importance'             => $norm['importance'],
			'indexed_at'             => gmdate( 'Y-m-d H:i:s' ),
			'updated_at'             => gmdate( 'Y-m-d H:i:s' ),
		) );
		if ( false === $ok ) {
			return; // UNIQUE collision or DB error — dedupe/seen guards the rest
		}
		$message_id       = (int) $wpdb->insert_id;
		$internal_domains = ZIB_Settings::internal_domains();

		foreach ( $norm['parties'] as $pt ) {
			$is_internal = ZIB_Classifier::is_internal( $pt['addr'], $internal_domains, array( 'ZIB_Classifier', 'wp_is_registered_internal' ) );
			$matched     = null;
			if ( $is_internal ) {
				if ( class_exists( 'ZDZ_Mailbox_Identity' ) ) {
					$matched = ZDZ_Mailbox_Identity::user_for_address( $pt['addr'] ) ?: null;
				} else {
					$u = get_user_by( 'email', $pt['addr'] );
					$matched = $u ? (int) $u->ID : null;
				}
			}
			$wpdb->insert( self::t_party(), array(
				'message_id'      => $message_id,
				'owner_user_id'   => $owner,
				'role'            => $pt['role'],
				'addr'            => $pt['addr'],
				'name'            => self::clip( $pt['name'], 255 ),
				'is_internal'     => $is_internal ? 1 : 0,
				'matched_user_id' => $matched,
			) );
		}

		// P6a: derive the index card / extracts / tags from the body we already hold
		// (gated on the dark enrich switch; fail-soft inside enrich_message()).
		if ( ZIB_Settings::enrich_enabled() && class_exists( 'ZIB_Enrich' ) ) {
			ZIB_Enrich::enrich_message( $message_id, array(
				'owner_user_id'   => $owner,
				'account_id'      => (int) $acct->id,
				'subject'         => $norm['subject'],
				'from_addr'       => $norm['from_addr'],
				'from_name'       => $norm['from_name'],
				'direction'       => $norm['direction'],
				'class'           => $class,
				'has_attachments' => (int) $norm['has_attachments'],
				'received_at'     => $norm['received_at'],
			), (string) $body['content'], (string) $body['format'] );
		}
	}

	private static function delete_by_ms_id( int $acct_id, string $ms_id ): void {
		global $wpdb;
		$msg_id = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . self::t_msg() . ' WHERE account_id = %d AND ms_message_id = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$acct_id, $ms_id
		) );
		if ( $msg_id > 0 ) {
			$wpdb->delete( self::t_party(), array( 'message_id' => $msg_id ), array( '%d' ) );
			$wpdb->delete( $wpdb->prefix . 'zib_extracts', array( 'message_id' => $msg_id ), array( '%d' ) );
			$wpdb->delete( $wpdb->prefix . 'zib_message_tags', array( 'message_id' => $msg_id ), array( '%d' ) );
			$wpdb->delete( self::t_msg(), array( 'id' => $msg_id ), array( '%d' ) );
		}
		// Keep the seen row so a re-surfaced id is not re-fetched.
	}

	// ── ledger + cursors ────────────────────────────────────────────

	private static function seen_exists( int $acct_id, string $ms_id ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM ' . self::t_seen() . ' WHERE account_id = %d AND ms_message_id = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$acct_id, $ms_id
		) );
	}

	private static function record_seen( int $acct_id, string $ms_id, string $class, string $decision, ?string $received_at ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare(
			'INSERT INTO ' . self::t_seen() . ' (account_id, ms_message_id, class, decision, received_at, seen_at)
			 VALUES (%d, %s, %s, %s, %s, %s)
			 ON DUPLICATE KEY UPDATE class = VALUES(class), decision = VALUES(decision)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$acct_id, $ms_id, $class, $decision, $received_at, gmdate( 'Y-m-d H:i:s' )
		) );
	}

	private static function save_cursor( int $acct_id, string $folder, string $link ): void {
		global $wpdb;
		$col = ( 'inbox' === $folder ) ? 'delta_inbox_cursor' : 'delta_sent_cursor';
		$wpdb->update( self::t_acct(), array( $col => $link ), array( 'id' => $acct_id ), array( '%s' ), array( '%d' ) );
	}

	private static function save_backfill( int $acct_id, array $state ): void {
		global $wpdb;
		$wpdb->update( self::t_acct(), array( 'backfill_cursor' => wp_json_encode( $state ) ), array( 'id' => $acct_id ), array( '%s' ), array( '%d' ) );
	}

	/**
	 * Purge the whole Ballast for one account (disconnect, or a mode change that
	 * demands a clean re-index). Messages + participants + seen.
	 */
	public static function purge_account( int $acct_id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE p FROM ' . self::t_party() . ' p INNER JOIN ' . self::t_msg() . ' m ON m.id = p.message_id WHERE m.account_id = %d', $acct_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( 'DELETE mt FROM ' . $wpdb->prefix . 'zib_message_tags mt INNER JOIN ' . self::t_msg() . ' m ON m.id = mt.message_id WHERE m.account_id = %d', $acct_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->delete( $wpdb->prefix . 'zib_extracts', array( 'account_id' => $acct_id ), array( '%d' ) );
		$wpdb->delete( self::t_msg(), array( 'account_id' => $acct_id ), array( '%d' ) );
		$wpdb->delete( self::t_seen(), array( 'account_id' => $acct_id ), array( '%d' ) );
	}

	private static function log( string $msg ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'ZIB Ingest: ' . $msg );
		}
	}
}
