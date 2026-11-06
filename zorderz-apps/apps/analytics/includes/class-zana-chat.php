<?php
/**
 * ZANA_Chat — one chat turn, wired through the Core services.
 *
 * The flow, in order, is the whole point:
 *
 *   1. the shared device is refused before anything is stored (the Ai router serves no shared device);
 *   2. the person's message is saved as typed;
 *   3. a Privacy View code in it is answered or read before anything else (ZANA_Pseudonym_Route): never searched for;
 *   4. a widget block shared into the chat ("Ask chat about this") is checked again on the server with the kernel's
 *      rules (ZDZ_Ask_Context::rescrub) -- the page's preview is a convenience, this is the check that counts;
 *   5. classify the turn (cheap, deterministic) → a category + signals for rule selection;
 *   6. a narrow question one app answers is answered by that app, by rule, and the model is never asked (ZANA_Routes:
 *      a website page, the person's hours, a route plan, who can do a kind of work, the person's own mail);
 *   7. assemble the instructions (ZANA_Prompt_Builder — one author, plus the turn's extras from ZANA_Routes: "About
 *      you", the business's facts, Charts, email drafting, direct messages) and the turn's DATA from whatever
 *      connectors are bound (`zdz_analytics_data_context`, ships EMPTY), fenced with the platform's data fence;
 *   8. ask the model through the platform's Ai router (ZANA_LLM_Client → ZDZ_Core_LLM::ask): customer details are coded
 *      before anything leaves, and the answer comes back decoded for the person who asked. When the model asks Charts
 *      for figures first, the charts run and it is asked once more, with their rows (ZANA_Charts_Tool);
 *   9. math written as LaTeX becomes plain text (ZANA_Plain_Math);
 *  10. an action the model asked for becomes an app's answer, a refusal, or a Confirm card (ZANA_Actions) -- never a
 *      write; library links become real, a message draft is held for Send, access codes are cut (ZANA_Routes);
 *  11. the answer passes the SINGLE outbound gate (ZDZ_Answer_Authority::gate, channel 'chat') so it states facts only;
 *  12. the answer is saved, a "remember that I…" request is handled, and the turn's timings are kept.
 *
 * Fail loudly, never silently: a model or configuration error returns an honest sentence -- never a made-up answer,
 * never a bare zero.
 *
 * This method is the ONE turn implementation. It runs unchanged whether the REST route calls it, the admin-ajax road
 * calls it, or ZANA_Background runs it from a loopback for a slow turn. The async wrapper only changes WHERE it runs.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Chat {

	/** How many prior turns to include as history. */
	const HISTORY_TURNS = 12;

	/** Seconds the answer must arrive in (the background runner and the browser wait longer than this). */
	const BUDGET_S = 150;

	/**
	 * Run one turn. Returns a structured result the REST layer serialises.
	 *
	 * @param int    $user_id
	 * @param int    $session_id 0 to start a new session.
	 * @param string $message
	 * @return array {ok, session_id, answer, tier, verdict, error?}
	 */
	public static function send( int $user_id, int $session_id, string $message ): array {
		$started = microtime( true );
		$message = trim( wp_strip_all_tags( $message ) );
		if ( '' === $message ) {
			return array( 'ok' => false, 'error' => __( 'Empty message.', 'zorderz' ) );
		}
		// An access code the person typed is cut before it is kept or read by a model (ZANA_Sealed).
		$message = class_exists( 'ZANA_Sealed' ) ? (string) ZANA_Sealed::cut( $message ) : $message;

		if ( ZANA_LLM_Client::is_shared_device( $user_id ) ) {
			// The Ai router serves no shared device, and the shared device keeps no transcript: say so, store nothing.
			return self::fail( 0, __( 'The assistant is not available on the shared device.', 'zorderz' ) );
		}
		$pv = class_exists( 'ZANA_Pseudonym_Route' ) && ZANA_Pseudonym_Route::is_on( $user_id );

		$session_id = self::ensure_session( $user_id, $session_id, $message );
		$history    = self::history( $session_id, $user_id );   // before this turn's message is saved
		self::store_message( $session_id, $user_id, 'user', $message, 'confirmed', 'ok' );

		// ── A Privacy View code: read it as what it stands for (this chat's own answers only), or answer it ──
		$for_model = $message;
		if ( class_exists( 'ZANA_Pseudonym_Route' ) && ZANA_Pseudonym_Route::find( $message ) ) {
			$sw = $pv ? ZANA_Pseudonym_Route::swap( $message, $user_id, $history, $session_id ) : null;
			if ( null !== $sw ) {
				$for_model = $sw;
			} else {
				$reply = ZANA_Pseudonym_Route::answer( $message, $user_id );
				if ( null !== $reply ) {
					self::store_message( $session_id, $user_id, 'assistant', $reply, 'confirmed', 'ok' );
					self::touch_session( $session_id );
					self::timing( $session_id, $started, 'privacy view code', 'ok', 'route' );
					return array( 'ok' => true, 'session_id' => $session_id, 'answer' => $reply, 'tier' => 'confirmed', 'verdict' => 'ok', 'route' => 'privacy_view_code' );
				}
			}
		}
		if ( class_exists( 'ZANA_Pseudonym_Route' ) ) {
			$history = ZANA_Pseudonym_Route::swap_history( $history, $user_id, $session_id );
		}

		// ── A widget block shared into the chat, checked again here (and in earlier messages) ──
		$for_model = self::rescrub_blocks( $for_model );
		foreach ( $history as $i => $h ) {
			if ( 'user' === $h['role'] ) {
				$history[ $i ]['content'] = self::rescrub_blocks( (string) $h['content'] );
			}
		}

		$context                 = self::classify( $for_model );
		$context['is_kiosk']     = false;
		$context['privacy_view'] = $pv;

		// ── A narrow question one app answers is answered by that app; the model is never asked (ZANA_Routes) ──
		$route = class_exists( 'ZANA_Routes' )
			? ZANA_Routes::before_model( $user_id, $for_model, $history, array(
				'privacy_view'    => $pv,
				'customer_lookup' => 'customer_lookup' === $context['category'],
			) )
			: array( 'answer' => null, 'kind' => '', 'plan' => array() );
		if ( null !== $route['answer'] ) {
			$answer = class_exists( 'ZANA_Sealed' ) ? (string) ZANA_Sealed::cut( (string) $route['answer'] ) : (string) $route['answer'];
			self::store_message( $session_id, $user_id, 'assistant', $answer, 'confirmed', 'ok' );
			self::touch_session( $session_id );
			self::after_turn( $user_id, $message, $session_id );
			self::timing( $session_id, $started, (string) $route['kind'], empty( $route['failed'] ) ? 'ok' : 'failed', 'route' );
			return array( 'ok' => true, 'session_id' => $session_id, 'answer' => $answer, 'tier' => 'confirmed', 'verdict' => 'ok', 'route' => (string) $route['kind'] );
		}
		// An app's answer shown on an earlier turn reaches the model only as its title and a note.
		if ( class_exists( 'ZANA_App_Reads' ) ) {
			$history = ZANA_App_Reads::for_models( $history );
		}
		$web    = ! empty( $context['signals']['web'] );
		$vocab  = ( ! $web && class_exists( 'ZANA_Charts_Tool' ) && ZANA_Charts_Tool::available( $user_id ) ) ? ZANA_Charts_Tool::vocab( $user_id ) : array();
		$flags  = array( 'charts_vocab' => $vocab, 'privacy_view' => $pv, 'web' => $web, 'email_task' => ! empty( $route['plan']['email_task'] ) );

		// ── Assemble the instructions (one author) and the turn's data (ships empty) ──
		$system = ZANA_Prompt_Builder::build( $user_id, $context );
		if ( class_exists( 'ZANA_Routes' ) ) {
			$extra = ZANA_Routes::extras( $user_id, $for_model, $history, (array) $route['plan'], $flags );
			if ( $extra ) {
				$system .= "\n\n" . implode( "\n\n", $extra );
			}
		}
		$data   = apply_filters(
			'zdz_analytics_data_context',
			array(
				'text'                    => '',
				'verified_figures'        => array(),
				'allowed_fallback_claims' => array(),
				'sor_outcomes'            => array(),
			),
			$for_model,
			$user_id,
			$context
		);
		$data   = is_array( $data ) ? $data : array();
		$fenced = ! empty( $data['text'] ) ? ZANA_Markers::fence( (string) $data['text'] ) : '';

		$messages = array( array( 'role' => 'system', 'content' => $system ) );
		if ( '' !== $fenced ) {
			$messages[] = array( 'role' => 'system', 'content' => $fenced );
		}
		foreach ( $history as $h ) {
			$messages[] = $h;
		}
		$messages[] = array( 'role' => 'user', 'content' => $for_model );

		// ── The model, through the platform's Ai router ──
		$client = new ZANA_LLM_Client( $user_id );
		$model0 = microtime( true );
		$r      = $client->chat( $messages, array(
			'budget_s' => self::BUDGET_S,
			'web'      => $web,
		) );
		if ( empty( $r['ok'] ) ) {
			self::timing( $session_id, $started, (string) $context['category'], 'failed', 'model', array( 'model_ms' => (int) round( ( microtime( true ) - $model0 ) * 1000 ) ) );
			return self::fail( $session_id, (string) ( $r['error'] ?? __( 'I couldn\'t reach the assistant just now. Please try again.', 'zorderz' ) ) );
		}
		$raw  = (string) $r['text'];
		$how  = 'model';
		$cost = array( 'usd' => (float) ( $client->last_numbers['usd'] ?? 0 ), 'model' => (string) ( $client->last_numbers['model'] ?? '' ) );

		// ── Charts as the calculator: the model asked for charts first; they run, and it answers from their rows ──
		if ( $vocab && ZANA_Charts_Tool::only_requests( $raw ) ) {
			ZANA_Charts_Tool::run_specs( ZANA_Charts_Tool::requests_in( $raw ), $user_id, $vocab, 'plan' );
			$messages[] = array( 'role' => 'assistant', 'content' => $raw );
			$messages[] = array( 'role' => 'system', 'content' => ZANA_Markers::fence( ZANA_Charts_Tool::block() ) );
			$messages[] = array( 'role' => 'user', 'content' => __( 'Now answer my question from the CHARTS RESULTS above.', 'zorderz' ) );
			$r2 = $client->chat( $messages, array( 'budget_s' => max( 30, self::BUDGET_S - (int) ( microtime( true ) - $model0 ) ) ) );
			if ( ! empty( $r2['ok'] ) ) {
				$raw = (string) $r2['text'];
				$cost['usd'] += (float) ( $client->last_numbers['usd'] ?? 0 );
			}
			// a failed second call leaves the chart blocks, which are drawn as tables below
			$how = 'model+charts';
			foreach ( ZANA_Charts_Tool::verified_figures() as $vf ) {
				$data['verified_figures'][] = $vf;
			}
		}
		$model_ms = (int) round( ( microtime( true ) - $model0 ) * 1000 );

		// ── Plain-text math, then the actions the model asked for, then no protocol token reaches a person ──
		$raw = ZANA_Plain_Math::apply( $raw );
		$raw = ZANA_Actions::process( $raw, array(
			'user_id'      => $user_id,
			'session_id'   => $session_id,
			'is_kiosk'     => false,
			'privacy_view' => $pv,
			'sources'      => array( $fenced, $message, $for_model ),
		) );
		$raw = ZANA_Markers::strip( $raw );
		if ( class_exists( 'ZANA_Routes' ) ) {
			$raw = ZANA_Routes::after_model( $raw, $user_id, $session_id, $flags );
		}

		// ── The single outbound gate ──
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
		if ( class_exists( 'ZANA_Routes' ) ) {
			$answer = ZANA_Routes::finish( $answer, $flags );   // the server's chart buttons and the web note, after the gate
		}
		$verdict = (string) ( $gated['verdict'] ?? 'ok' );
		// The turn is at best DERIVED unless a system of record backed the figures.
		$tier = empty( $data['verified_figures'] ) ? 'unknown' : 'derived';
		if ( class_exists( 'ZDZ_Answer_Authority' ) ) {
			$tier = empty( $data['verified_figures'] ) ? ZDZ_Answer_Authority::TIER_UNKNOWN : ZDZ_Answer_Authority::TIER_DERIVED;
		}

		self::store_message( $session_id, $user_id, 'assistant', $answer, $tier, $verdict );
		self::touch_session( $session_id );
		self::after_turn( $user_id, $message, $session_id );
		self::timing( $session_id, $started, (string) $context['category'], 'ok', $how, $cost + array( 'model_ms' => $model_ms, 'cut_off' => 'length' === $client->last_finish_reason() ) );

		return array(
			'ok'         => true,
			'session_id' => (int) $session_id,
			'answer'     => $answer,
			'tier'       => $tier,
			'verdict'    => $verdict,
			'cut_off'    => 'length' === $client->last_finish_reason(),
		);
	}

	/**
	 * Every block in a text that opens with the platform's data fence is cleaned again with the kernel's rules
	 * (ZDZ_Ask_Context::rescrub): a page that skipped its preview, an old cached page or a pasted block cannot hand raw
	 * details on. Without the kernel's checker the text stays as it is (the Ai router still codes customer details).
	 */
	public static function rescrub_blocks( string $text ): string {
		$start = ZANA_Markers::data_start();
		$end   = ZANA_Markers::data_end();
		if ( '' === $start || false === strpos( $text, $start ) || ! class_exists( 'ZDZ_Ask_Context' ) || ! method_exists( 'ZDZ_Ask_Context', 'rescrub' ) ) {
			return $text;
		}
		$re  = '/^[^\n]*' . preg_quote( $start, '/' ) . '[^\n]*\n.*?(?:^' . preg_quote( $end, '/' ) . '[ \t]*$|\z)/msu';
		$out = preg_replace_callback( $re, function ( $m ) {
			try {
				$r = ZDZ_Ask_Context::rescrub( (string) $m[0] );
				return is_array( $r ) && isset( $r['text'] ) ? (string) $r['text'] : $m[0];
			} catch ( \Throwable $e ) {
				return '';   // a block that cannot be checked is not passed on
			}
		}, $text );
		return is_string( $out ) ? $out : $text;
	}

	/* ─────────────────────────── Classification ──────────────────────────── */

	/**
	 * Cheap, deterministic classification for rule selection. No model, no network. The category names are generic;
	 * the signals drive which rules fire.
	 */
	public static function classify( string $message ): array {
		$m       = strtolower( $message );
		$signals = array();

		if ( preg_match( '/\b(how many|count|number of|total (?:units|items|jobs))\b/', $m ) ) {
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
		if ( preg_match( '/\b(search the web|on the web|on the internet|look (?:it )?up online|google (?:it|for)|latest news|their website)\b/', $m ) ) {
			$signals['web'] = true;
		}

		$category = 'general';
		if ( preg_match( '/\b(customer|client|account|property)\b/', $m ) ) {
			$category                       = 'customer_lookup';
			$signals['customer_in_context'] = true;
		} elseif ( preg_match( '/\blead(s)?\b/', $m ) ) {
			$category = 'lead_lookup';
		} elseif ( ! empty( $signals['asks_count'] ) || ! empty( $signals['asks_figure'] ) ) {
			$category = 'metrics';
		}

		return array( 'category' => $category, 'signals' => $signals );
	}

	/* ─────────────────────────── Persistence ─────────────────────────────── */

	/** Is this chat this person's own? */
	public static function owns_session( int $user_id, int $session_id ): bool {
		global $wpdb;
		if ( $user_id <= 0 || $session_id <= 0 ) {
			return false;
		}
		$table = ZANA_DB::sessions_table();
		return $user_id === (int) $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$table} WHERE id = %d", $session_id ) );   // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private static function ensure_session( int $user_id, int $session_id, string $first_message ): int {
		global $wpdb;
		if ( self::owns_session( $user_id, $session_id ) ) {
			return $session_id;
		}
		$now = current_time( 'mysql' );
		$wpdb->insert(
			ZANA_DB::sessions_table(),
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

	/**
	 * A Confirm card after its press: the card's token in the saved answer becomes what happened, so reloading the chat
	 * never brings back a card that looks armed.
	 */
	public static function settle_card( int $session_id, int $user_id, string $action_id, string $note ): void {
		global $wpdb;
		$token = ZANA_Markers::confirm( $action_id );
		if ( $session_id <= 0 || $user_id <= 0 || strlen( $token ) < 20 ) {
			return;
		}
		$table = ZANA_DB::messages_table();
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET body = REPLACE(body, %s, %s) WHERE session_id = %d AND user_id = %d AND role = 'assistant' AND body LIKE %s",   // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$token,
			'(' . $note . ')',
			$session_id,
			$user_id,
			'%' . $wpdb->esc_like( $token ) . '%'
		) );
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
				"SELECT role, body FROM {$table} WHERE session_id = %d AND user_id = %d ORDER BY id DESC LIMIT %d",   // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$session_id,
				$user_id,
				self::HISTORY_TURNS * 2
			),
			ARRAY_A
		);
		$rows = array_reverse( (array) $rows );
		$out  = array();
		foreach ( $rows as $r ) {
			$role  = ( 'assistant' === $r['role'] ) ? 'assistant' : 'user';
			$out[] = array( 'role' => $role, 'content' => (string) $r['body'] );
		}
		return $out;
	}

	public static function sessions( int $user_id, int $limit = 30 ): array {
		global $wpdb;
		$table = ZANA_DB::sessions_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, title, updated_at FROM {$table} WHERE user_id = %d ORDER BY updated_at DESC LIMIT %d",   // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
				"SELECT id, role, body, tier, verdict, created_at FROM {$table} WHERE session_id = %d AND user_id = %d ORDER BY id ASC",   // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$session_id,
				$user_id
			),
			ARRAY_A
		);
		// The page needs each message's id (a docked direct message is remembered per answer) and its time with the
		// site's offset (a Confirm card older than its hold is drawn as expired, never offered).
		return array_map(
			static fn( $r ) => array(
				'id'         => (int) ( $r['id'] ?? 0 ),
				'role'       => (string) $r['role'],
				'body'       => (string) $r['body'],
				'tier'       => (string) $r['tier'],
				'verdict'    => (string) $r['verdict'],
				'created_at' => function_exists( 'mysql2date' ) ? (string) mysql2date( 'c', (string) ( $r['created_at'] ?? '' ), false ) : '',
			),
			(array) $rows
		);
	}

	/* ─────────────────────────── Helpers ─────────────────────────────────── */

	/** After a turn: a "remember / forget that I…" request is handled ("About you"). Never throws. */
	private static function after_turn( int $user_id, string $message, int $session_id ): void {
		if ( ! class_exists( 'ZANA_User_Memory' ) || '' === ZANA_User_Memory::explicit_intent( $message ) ) {
			return;
		}
		try {
			ZANA_User_Memory::handle_turn( $user_id, $message, $session_id );
		} catch ( \Throwable $e ) {
			error_log( 'ZANA_Chat: the "About you" request could not be handled (' . get_class( $e ) . ').' );
		}
	}

	/** The turn's timings row (labels and times only). Never throws. */
	private static function timing( int $session_id, float $started, string $kind, string $status, string $how, array $more = array() ): void {
		if ( class_exists( 'ZANA_Turn_Timings' ) ) {
			ZANA_Turn_Timings::record( array( 'session_id' => $session_id, 'started' => $started, 'kind' => $kind, 'status' => $status, 'how' => $how ) + $more );
		}
	}

	private static function title_from( string $message ): string {
		$t = trim( (string) preg_replace( '/\s+/', ' ', $message ) );
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
