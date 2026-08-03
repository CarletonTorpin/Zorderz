<?php
/**
 * ZANA_Chat — one synchronous chat turn, wired through the Core services.
 *
 * The flow, in order, is the whole point:
 *
 *   1. classify the turn (cheap, deterministic) → a category + signals for rule
 *      selection;
 *   2. assemble the system prompt at runtime (ZANA_Prompt_Builder — one author);
 *   3. gather the DATA CONTEXT for the turn from whatever connectors are bound
 *      (`zdz_analytics_data_context`, ships EMPTY) and fence it as inert data;
 *   4. resolve the model for the 'chat' slot (ZDZ_Model_Registry);
 *   5. call the model through the shared gateway (ZDZ_Core_Poe);
 *   6. pass the reply through the SINGLE outbound gate (ZDZ_Answer_Authority::gate,
 *      channel 'chat') so it states facts only — INV-12;
 *   7. persist the turn (never on a shared device — INV-10 / memory-privacy).
 *
 * Fail loudly, never silently: a model or configuration error returns an honest
 * "I couldn't reach the assistant" — never a fabricated answer, never a bare zero.
 *
 * This method is the ONE turn implementation. It runs unchanged whether the REST
 * route calls it synchronously or ZANA_Background calls it from a loopback for a slow
 * turn — same prompt, same gateway, same outbound gate, same persistence. The async
 * wrapper only changes WHERE it runs; do not fork the logic.
 *
 * DEFERRED (documented extension points, not shipped half-built): token streaming, the
 * self-check auditor second pass, the memory extractor, and the live billing/CRM/
 * analytics connectors. Each is a filter with a neutral fallback so the surface
 * degrades to "no data bound" rather than breaking. (The async job queue + polling,
 * once deferred, now ships — see ZANA_Background.)
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Chat {

	/** How many prior turns to include as history. */
	const HISTORY_TURNS = 12;

	/**
	 * Run one turn. Returns a structured result the REST layer serialises.
	 *
	 * @param int    $user_id
	 * @param int    $session_id 0 to start a new session.
	 * @param string $message
	 * @return array {ok, session_id, answer, tier, verdict, error?}
	 */
	public static function send( int $user_id, int $session_id, string $message ): array {
		$message = trim( wp_strip_all_tags( $message ) );
		if ( '' === $message ) {
			return array( 'ok' => false, 'error' => __( 'Empty message.', 'zorderz' ) );
		}

		$is_kiosk = class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_kiosk( $user_id );
		$context  = self::classify( $message );
		$context['is_kiosk'] = $is_kiosk;

		// Persist the user turn (unless shared device: never persist a transcript there).
		if ( ! $is_kiosk ) {
			$session_id = self::ensure_session( $user_id, $session_id, $message );
			self::store_message( $session_id, $user_id, 'user', $message, 'confirmed', 'ok' );
		}

		// ── Assemble the prompt (one author) ──
		$system = ZANA_Prompt_Builder::build( $user_id, $context );

		// ── Gather + fence the data context (ships empty) ──
		$data = apply_filters(
			'zdz_analytics_data_context',
			array(
				'text'                    => '',
				'verified_figures'        => array(),
				'allowed_fallback_claims' => array(),
				'sor_outcomes'            => array(),
			),
			$message,
			$user_id,
			$context
		);
		$data = is_array( $data ) ? $data : array();

		$messages = array( array( 'role' => 'system', 'content' => $system ) );
		if ( ! empty( $data['text'] ) ) {
			$messages[] = array( 'role' => 'system', 'content' => ZANA_Markers::fence( (string) $data['text'] ) );
		}
		if ( ! $is_kiosk ) {
			foreach ( self::history( $session_id, $user_id ) as $h ) {
				$messages[] = $h;
			}
		}
		$messages[] = array( 'role' => 'user', 'content' => $message );

		// ── Resolve model + call the gateway ──
		$slot    = $is_kiosk ? 'kiosk' : 'chat';
		$gateway = class_exists( 'ZDZ_Model_Registry' ) ? ZDZ_Model_Registry::gateway( $slot ) : null;
		if ( ! $gateway ) {
			return self::fail( $session_id, __( 'The assistant is not configured on this site yet.', 'zorderz' ) );
		}

		try {
			$raw = $gateway->query( $messages, 0.0 );
		} catch ( \Throwable $e ) {
			error_log( '[ZANA_Chat] model call failed: ' . $e->getMessage() );
			return self::fail( $session_id, __( 'I couldn\'t reach the assistant just now. Please try again.', 'zorderz' ) );
		}

		if ( ! is_string( $raw ) || '' === trim( $raw ) || 0 === stripos( trim( $raw ), 'error:' ) ) {
			return self::fail( $session_id, __( 'I couldn\'t reach the assistant just now. Please try again.', 'zorderz' ) );
		}

		// Strip any protocol markers the model echoed before the answer reaches a human.
		$raw = ZANA_Markers::strip( $raw );

		// ── The single outbound gate (INV-12) ──
		$gated = array( 'verdict' => 'ok', 'text' => $raw );
		if ( class_exists( 'ZDZ_Answer_Authority' ) ) {
			$gated = ZDZ_Answer_Authority::gate(
				array(
					'channel' => 'chat',
					'text'    => $raw,
					'context' => array(
						'verified_figures'        => (array) ( $data['verified_figures'] ?? array() ),
						'allowed_fallback_claims' => (array) ( $data['allowed_fallback_claims'] ?? array() ),
						'sor_outcomes'            => (array) ( $data['sor_outcomes'] ?? array() ),
						'side_effect'             => false,
					),
				)
			);
		}

		$answer  = (string) $gated['text'];
		$verdict = (string) ( $gated['verdict'] ?? 'ok' );
		// The turn is at best DERIVED unless a system of record backed the figures.
		$tier = empty( $data['verified_figures'] )
			? ZDZ_Answer_Authority::TIER_UNKNOWN
			: ZDZ_Answer_Authority::TIER_DERIVED;

		if ( ! $is_kiosk ) {
			self::store_message( $session_id, $user_id, 'assistant', $answer, $tier, $verdict );
			self::touch_session( $session_id );
		}

		return array(
			'ok'         => true,
			'session_id' => (int) $session_id,
			'answer'     => $answer,
			'tier'       => $tier,
			'verdict'    => $verdict,
		);
	}

	/* ─────────────────────────── Classification ──────────────────────────── */

	/**
	 * Cheap, deterministic classification for rule selection. No LLM, no network.
	 * The category names are generic; the signals drive which rules fire.
	 */
	public static function classify( string $message ): array {
		$m       = strtolower( $message );
		$signals = array();

		if ( preg_match( '/\b(how many|count|number of|total (?:units|screens|items|jobs))\b/', $m ) ) {
			$signals['asks_count'] = true;
		}
		if ( preg_match( '/\b(revenue|paid|amount|price|\$|dollar|margin|invoice total|balance)\b/', $m ) ) {
			$signals['asks_figure'] = true;
		}
		if ( preg_match( '/\b(vs|versus|compare|compared to|year over year|month over month|last (?:month|year))\b/', $m ) ) {
			$signals['comparison'] = true;
		}
		if ( preg_match( '/\b(email|send|draft|write) (?:an?|the)? ?(?:email|message|note)\b/', $m ) ) {
			$signals['email_intent'] = true;
		}
		if ( preg_match( '/\b(create|update|book|schedule|assign|exclude|mark as)\b/', $m ) ) {
			$signals['write_intent'] = true;
		}

		$category = 'general';
		if ( preg_match( '/\b(customer|client|account|property)\b/', $m ) ) {
			$category = 'customer_lookup';
			$signals['customer_in_context'] = true;
		} elseif ( preg_match( '/\blead(s)?\b/', $m ) ) {
			$category = 'lead_lookup';
		} elseif ( ! empty( $signals['asks_count'] ) || ! empty( $signals['asks_figure'] ) ) {
			$category = 'metrics';
		}

		return array( 'category' => $category, 'signals' => $signals );
	}

	/* ─────────────────────────── Persistence ─────────────────────────────── */

	private static function ensure_session( int $user_id, int $session_id, string $first_message ): int {
		global $wpdb;
		$table = ZANA_DB::sessions_table();
		if ( $session_id > 0 ) {
			$owner = (int) $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$table} WHERE id = %d", $session_id ) );
			if ( $owner === $user_id ) {
				return $session_id;
			}
		}
		$now = current_time( 'mysql' );
		$wpdb->insert(
			$table,
			array(
				'user_id'    => $user_id,
				'title'      => self::title_from( $first_message ),
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%d', '%s', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	private static function store_message( int $session_id, int $user_id, string $role, string $body, string $tier, string $verdict ): void {
		global $wpdb;
		$wpdb->insert(
			ZANA_DB::messages_table(),
			array(
				'session_id' => $session_id,
				'user_id'    => $user_id,
				'role'       => $role,
				'body'       => $body,
				'tier'       => $tier,
				'verdict'    => $verdict,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	private static function touch_session( int $session_id ): void {
		global $wpdb;
		$wpdb->update(
			ZANA_DB::sessions_table(),
			array( 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $session_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/** Prior turns for this session, oldest-first, owner-scoped. */
	public static function history( int $session_id, int $user_id ): array {
		global $wpdb;
		if ( $session_id <= 0 ) {
			return array();
		}
		$table = ZANA_DB::messages_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT role, body FROM {$table} WHERE session_id = %d AND user_id = %d ORDER BY id DESC LIMIT %d",
				$session_id,
				$user_id,
				self::HISTORY_TURNS * 2
			),
			ARRAY_A
		);
		$rows = array_reverse( (array) $rows );
		$out  = array();
		foreach ( $rows as $r ) {
			$role = ( 'assistant' === $r['role'] ) ? 'assistant' : 'user';
			$out[] = array( 'role' => $role, 'content' => (string) $r['body'] );
		}
		return $out;
	}

	public static function sessions( int $user_id, int $limit = 30 ): array {
		global $wpdb;
		$table = ZANA_DB::sessions_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, title, updated_at FROM {$table} WHERE user_id = %d ORDER BY updated_at DESC LIMIT %d",
				$user_id,
				$limit
			),
			ARRAY_A
		);
		return array_map(
			static fn( $r ) => array(
				'id'         => (int) $r['id'],
				'title'      => (string) $r['title'],
				'updated_at' => (string) $r['updated_at'],
			),
			(array) $rows
		);
	}

	public static function session_messages( int $session_id, int $user_id ): array {
		global $wpdb;
		$table = ZANA_DB::messages_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT role, body, tier, verdict, created_at FROM {$table} WHERE session_id = %d AND user_id = %d ORDER BY id ASC",
				$session_id,
				$user_id
			),
			ARRAY_A
		);
		return array_map(
			static fn( $r ) => array(
				'role'    => (string) $r['role'],
				'body'    => (string) $r['body'],
				'tier'    => (string) $r['tier'],
				'verdict' => (string) $r['verdict'],
			),
			(array) $rows
		);
	}

	/* ─────────────────────────── Helpers ─────────────────────────────────── */

	private static function title_from( string $message ): string {
		$t = trim( preg_replace( '/\s+/', ' ', $message ) );
		return mb_substr( $t, 0, 60 );
	}

	private static function fail( int $session_id, string $msg ): array {
		return array(
			'ok'         => false,
			'session_id' => (int) $session_id,
			'answer'     => $msg,
			'tier'       => 'unknown',
			'verdict'    => 'refuse',
			'error'      => $msg,
		);
	}
}
