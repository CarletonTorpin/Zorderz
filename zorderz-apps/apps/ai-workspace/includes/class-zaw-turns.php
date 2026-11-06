<?php
/**
 * ZAW_Turns -- one turn, from the page to the hub's assistant and back.
 *
 *   start()    the person (granted, never the shared device, never in Privacy View) types a message:
 *                1. one turn at a time per person;
 *                2. a hold in the Ai ledger for the turn's most (the per-answer cap, or what is left of the business's
 *                   monthly budget; no budget set or under 5 cents left: not sent);
 *                3. the message coded with ZDZ_Private::for( 'assistant' ) (gate codes cut first); no coder: not sent;
 *                4. a turn row and a SINGLE-USE fetch link (a 64-hex token, kept as its hash, 15 minutes);
 *                5. the hub is given a small ticket { turn_id, fetch } (assistant_turn). Nothing else crosses there.
 *   fetch()    GET ZDZ_REST_NS/ai-workspace/turn-fetch/<id>?k=…, the hub's one pull: the token is spent before a byte
 *              goes (an UPDATE that must change one row); the body is sealed to the hub with the Charts keys and holds
 *              the coded message, the conversation's id on the hub, the turn's site tools (ZAW_Tools::for_user), a fresh
 *              tool token (its hash kept), the turn's cap and headroom (= the hold), the models with their prices and
 *              the region (the router's own configuration), the workspace the answers must come from, and the provider
 *              key, read from the credentials layer at this moment: never stored by this app, never logged.
 *              At that moment the hold becomes the turn's ONE ledger row at the hold's amount, and the hold is released
 *              (ZAW_Money: a hold counts 15 minutes, a row the month).
 *   advance()  asks the hub (assistant_status) for the next page of coded progress and, when the turn is over, its
 *              answer and usage; the row is then settled to the real figures. A poller writes its page only if the turn
 *              is still as it read it (`rev`). The page polls while open; the sweep (every 5 minutes) finishes turns
 *              whose page was closed, and marks a turn the hub lost (20 minutes without an end): its row stays at its
 *              hold, estimated.
 *   cancel()   asks the hub to stop, then reads the hub's own word (advance): a turn is never closed on a figure the
 *              hub did not give.
 *   view()     what the page shows: decoded for the writer only, and only codes this conversation produced.
 *
 * Who decides "pulled": fetch() alone, in one UPDATE that spends the link and moves the turn on. A refusal (start) and a
 * withdrawal (finish, lose) are UPDATEs on the same unspent link, so exactly one of them happens.
 *
 * @package Zorderz\AiWorkspace
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZAW_Turns {

	const MAX_TEXT = 8000;
	const FETCH_S  = 900;      // the link works for 15 minutes
	const LOST_S   = 1200;     // no end from the hub after 20 minutes: lost, booked at its hold
	const KEEP_DAYS = 7;       // the coded words; the numbers stay
	const SECONDS  = 600;      // a turn's time on the hub
	const LIVE     = array( 'sending', 'queued', 'pulled', 'running', 'cancelling' );
	const FINAL    = array( 'done', 'error', 'cancelled', 'failed', 'expired', 'lost', 'refused' );

	private static function err( $code, $msg, $status = 400 ) {
		return new WP_Error( $code, $msg, array( 'status' => $status ) );
	}

	/* ------------------------------------------------------------------ what a turn needs */

	/** The provider key, read from the credentials layer each time (never kept here). */
	public static function provider_key() {
		return ( class_exists( 'ZDZ_Core_Settings' ) && method_exists( 'ZDZ_Core_Settings', 'get_credential' ) )
			? (string) ZDZ_Core_Settings::get_credential( 'anthropic_api_key' ) : '';
	}

	/** The workspace the provider's answers must come from ('' when none is configured: then nothing is sent). */
	public static function workspace() {
		return ( class_exists( 'ZDZ_LLM_Anthropic' ) && method_exists( 'ZDZ_LLM_Anthropic', 'workspace' ) ) ? (string) ZDZ_LLM_Anthropic::workspace() : '';
	}

	/**
	 * The router's own configuration for a turn: the primary and fallback slots' models with their prices, the region
	 * and its price factor. -> array, or null when the primary model or its prices are missing (then nothing is sent).
	 */
	public static function plan() {
		if ( ! class_exists( 'ZDZ_Core_LLM' ) || ! method_exists( 'ZDZ_Core_LLM', 'models' ) ) {
			return null;
		}
		$m       = ZDZ_Core_LLM::models();
		$primary = (string) ( $m['primary'] ?? '' );
		$p       = '' !== $primary ? ZDZ_Core_LLM::prices_for( $primary ) : null;
		if ( '' === $primary || ! is_array( $p ) ) {
			return null;
		}
		$prices   = array( $primary => array_values( array_map( 'floatval', $p ) ) );
		$fallback = (string) ( $m['fallback'] ?? '' );
		$fp       = ( '' !== $fallback && $fallback !== $primary ) ? ZDZ_Core_LLM::prices_for( $fallback ) : null;
		if ( is_array( $fp ) ) {
			$prices[ $fallback ] = array_values( array_map( 'floatval', $fp ) );
		} else {
			$fallback = '';
		}
		$conn = ZDZ_Core_LLM::connection();
		return array(
			'models'     => array( 'primary' => $primary, 'fallback' => $fallback ),
			'prices'     => $prices,
			'geo'        => (string) ( $conn['inference_geo'] ?? '' ),
			'geo_factor' => (float) ( $conn['geo_price_factor'] ?? 1.0 ),
			'search_usd' => defined( 'ZDZ_Core_LLM::SEARCH_USD' ) ? (float) ZDZ_Core_LLM::SEARCH_USD : 0.01,
		);
	}

	/** What is missing for any turn, said plainly ('' when nothing is). Platform first, then the Charts link. */
	public static function missing() {
		$need = array(
			'ZDZ_Private'      => __( 'the platform\'s customer-detail coder', 'zorderz' ),
			'ZDZ_Sealed'       => __( 'the platform\'s gate-code lock', 'zorderz' ),
			'ZDZ_Capabilities' => __( 'the platform\'s capability registry', 'zorderz' ),
			'ZDZ_LLM_Ledger'   => __( 'the platform\'s Ai ledger', 'zorderz' ),
			'ZDZ_Core_LLM'     => __( 'the platform\'s Ai router', 'zorderz' ),
			'ZCH_Hub_Client'   => __( 'the Charts app (the sealed link to the hub)', 'zorderz' ),
			'ZCH_Envelope'     => __( 'the Charts app (the sealed envelope)', 'zorderz' ),
		);
		foreach ( $need as $class => $what ) {
			if ( ! class_exists( $class ) ) {
				return $what;
			}
		}
		if ( ! method_exists( 'ZDZ_LLM_Ledger', 'reserve' ) ) {
			return __( 'the platform\'s Ai spend holds', 'zorderz' );
		}
		if ( ZAW_Hub::staging() ) {
			return __( 'the live site (this is a staging copy)', 'zorderz' );
		}
		if ( ! class_exists( 'ZCH_Config' ) || 'connected' !== ZCH_Config::state() ) {
			return __( 'a Charts hub connected to this live site (Charts settings)', 'zorderz' );
		}
		return '';
	}

	public static function ready() {
		return '' === self::missing();
	}

	/**
	 * The checks a message passes before anything is booked or written. -> the cleaned text, or a WP_Error.
	 */
	public static function preflight( $uid, $text ) {
		$uid = (int) $uid;
		if ( ! self::ready() ) {
			/* translators: %s: what is missing */
			return self::err( 'not_ready', sprintf( __( 'The Ai Workspace needs %s first.', 'zorderz' ), self::missing() ), 503 );
		}
		if ( ! ZAW_Grant::may_use( $uid ) ) {
			return self::err( 'forbidden', __( 'The Ai Workspace is not on for this account.', 'zorderz' ), 403 );
		}
		$text = trim( str_replace( "\r\n", "\n", (string) $text ) );
		if ( '' === $text ) {
			return self::err( 'empty', __( 'Type a message first.', 'zorderz' ) );
		}
		if ( ( function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text ) ) > self::MAX_TEXT ) {
			return self::err( 'too_long', __( 'That message is too long for one turn (8,000 characters).', 'zorderz' ) );
		}
		if ( '' === self::provider_key() ) {
			return self::err( 'no_key', __( 'There is no Anthropic key in the Zorderz credentials, so nothing can be sent.', 'zorderz' ), 503 );
		}
		if ( '' === self::workspace() ) {
			return self::err( 'no_workspace', __( 'There is no Anthropic workspace ID in the Zorderz credentials, so nothing can be sent.', 'zorderz' ), 503 );
		}
		if ( null === self::plan() ) {
			return self::err( 'no_model', __( 'The Ai router has no primary model with prices set, so nothing can be sent.', 'zorderz' ), 503 );
		}
		$coder = ZDZ_Private::for( 'assistant' );
		if ( ! is_object( $coder ) || ! method_exists( $coder, 'encode' ) || ! method_exists( $coder, 'from_reader' ) ) {
			return self::err( 'no_coder', __( 'Customer details could not be coded, so nothing was sent.', 'zorderz' ), 503 );
		}
		return $text;
	}

	/** May this person's per-turn usage be kept (dollars and tokens on the turn's row)? Their consent decides. */
	public static function keeps_usage( $uid ) {
		return ! class_exists( 'ZDZ_Consent' ) || ZDZ_Consent::may_record( 'assistant_usage', (int) $uid );
	}

	/* ------------------------------------------------------------------ start */

	public static function start( $uid, $text, $conv_key = '' ) {
		global $wpdb;
		$uid  = (int) $uid;
		$text = self::preflight( $uid, $text );
		if ( is_wp_error( $text ) ) {
			return $text;
		}
		$live = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . ZAW_DB::turns() . " WHERE user_id = %d AND state IN ('sending','queued','pulled','running','cancelling') AND created > %s", $uid, gmdate( 'Y-m-d H:i:s', time() - self::LOST_S ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is ours
		if ( (int) $live > 0 ) {
			return self::err( 'busy', __( 'One turn at a time: wait for the answer, or stop it.', 'zorderz' ), 409 );
		}
		$conv = '' !== (string) $conv_key ? ZAW_DB::conv( (string) $conv_key ) : null;
		if ( '' !== (string) $conv_key && ( ! $conv || (int) $conv['user_id'] !== $uid ) ) {
			return self::err( 'no_conv', __( 'That conversation is not yours, or no longer here.', 'zorderz' ), 404 );
		}
		$hold = ZAW_Money::hold( ZAW_Settings::get( 'turn_usd' ) );
		if ( null === $hold ) {
			return self::err( 'money_unread', __( 'The Ai ledger could not be read, so nothing was sent (spend that can\'t be seen can\'t be capped).', 'zorderz' ), 503 );
		}
		if ( 'no_budget' === $hold['why'] ) {
			return self::err( 'no_budget', __( 'No monthly Ai budget is set (Ai usage settings), so nothing was sent.', 'zorderz' ), 503 );
		}
		if ( (float) $hold['usd'] <= 0 ) {
			return self::err( 'money_cap', __( 'The business\'s monthly Ai budget is reached, so nothing was sent.', 'zorderz' ), 402 );
		}
		if ( ! $conv ) {
			$conv = ZAW_DB::new_conv( $uid );
			if ( ! $conv ) {
				ZAW_Money::release( $hold['id'] );
				return self::err( 'db', __( 'The conversation could not be saved.', 'zorderz' ), 500 );
			}
		}
		$coder = ZDZ_Private::for( 'assistant' );
		$coder->new_request();
		$coded  = $coder->encode( $text );
		$caught = substr_count( $coded, ' code sealed]' ) - substr_count( ZDZ_Private::defuse( $text ), ' code sealed]' );
		$codes  = count( ZDZ_Private::codes( $coded ) ) + count( ZDZ_Private::sealed_codes( $coded ) );
		ZAW_DB::add_codes( $conv['conv_key'], $coded );
		$tid   = bin2hex( random_bytes( 16 ) );
		$token = bin2hex( random_bytes( 32 ) );
		$hash  = hash( 'sha256', $token );
		$now   = ZAW_DB::now();
		$ok    = $wpdb->insert( ZAW_DB::turns(), array(
			'turn_id' => $tid, 'user_id' => $uid, 'conv_key' => $conv['conv_key'], 'state' => 'sending', 'question' => $coded,
			'answer' => '', 'events' => '[]', 'next_offset' => 0, 'fetch_hash' => $hash,
			'fetch_exp' => gmdate( 'Y-m-d H:i:s', time() + self::FETCH_S ), 'tool_hash' => '', 'site_tools' => '[]',
			'hold_id' => (int) $hold['id'], 'hold_milli' => (int) round( (float) $hold['usd'] * 1000 ),
			'coded_count' => min( 65535, $codes ), 'caught_count' => max( 0, min( 65535, $caught ) ),
			'kept_usage' => self::keeps_usage( $uid ) ? 1 : 0,
			'created' => $now, 'updated' => $now,
		) );
		if ( ! $ok ) {
			ZAW_Money::release( $hold['id'] );
			return self::err( 'db', __( 'The turn could not be saved.', 'zorderz' ), 500 );
		}
		$turn  = ZAW_DB::turn( $tid );
		$fetch = add_query_arg( array( 'k' => $token ), rest_url( ZDZ_REST_NS . '/ai-workspace/turn-fetch/' . $tid ) );
		$r     = ZAW_Hub::call( 'assistant_turn', array( 'turn_id' => $tid, 'fetch' => $fetch ) );
		$token = null;
		$fetch = null;
		if ( empty( $r['ok'] ) ) {
			// No answer is not "not sent": the hub may have written the ticket and the runner pulled the body (and the
			// key) before its answer was lost. Refuse only a link still unspent, in one UPDATE that also withdraws it, so
			// a later pull gets 410. If the hub already pulled, the turn is running: it is followed like any other.
			$code    = substr( preg_replace( '/[^a-z0-9_]/', '', (string) ( $r['error']['code'] ?? 'hub' ) ), 0, 64 );
			$refused = 1 === (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . ZAW_DB::turns() . " SET state = 'refused', error = %s, fetch_hash = '', finished = %s, updated = %s, rev = rev + 1 WHERE id = %d AND state = 'sending' AND fetch_hash = %s", $code, ZAW_DB::now(), ZAW_DB::now(), (int) $turn['id'], $hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is ours
			if ( $refused ) {
				ZAW_Money::release( $hold['id'] );              // nothing was sent: nothing is booked
				return self::err( 'hub', ZAW_Hub::say( $r ), 502 );
			}
			$out           = self::view( ZAW_DB::turn( $tid ), $uid );
			$out['runner'] = '';
			return $out;
		}
		// the hub may already have pulled it (state 'pulled'): only a turn still 'sending' becomes 'queued'
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . ZAW_DB::turns() . " SET state = 'queued', updated = %s, rev = rev + 1 WHERE id = %d AND state = 'sending'", ZAW_DB::now(), (int) $turn['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is ours
		$out           = self::view( ZAW_DB::turn( $tid ), $uid );
		$out['runner'] = preg_replace( '/[^a-z_]/', '', (string) ( $r['data']['runner'] ?? '' ) );
		return $out;
	}

	/* ------------------------------------------------------------------ the hub's pull */

	public static function fetch( $turn_id, $k ) {
		global $wpdb;
		$nope = new WP_Error( 'not_found', 'Not found.', array( 'status' => 404 ) );
		$t    = ZAW_DB::turn( (string) $turn_id );
		$k    = (string) $k;
		if ( $t && '' === (string) $t['fetch_hash'] && preg_match( '/^[a-f0-9]{64}$/', $k ) ) {
			return new WP_Error( 'gone', 'Gone.', array( 'status' => 410 ) );   // the link was used (or withdrawn)
		}
		if ( ! $t || ! in_array( $t['state'], array( 'sending', 'queued' ), true ) || '' === (string) $t['fetch_hash']
			|| ! preg_match( '/^[a-f0-9]{64}$/', $k ) || ! hash_equals( (string) $t['fetch_hash'], hash( 'sha256', $k ) )
			|| strtotime( (string) $t['fetch_exp'] . ' UTC' ) < time() ) {
			return $nope;
		}
		// one pull: the token is spent, and the turn is 'pulled', in one UPDATE before anything is read out (the query
		// string may sit in a server log); a refusal or a withdrawal of the same link can't also happen
		if ( 1 !== (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . ZAW_DB::turns() . " SET fetch_hash = '', state = 'pulled', updated = %s, rev = rev + 1 WHERE id = %d AND fetch_hash = %s AND state IN ('sending','queued')", ZAW_DB::now(), (int) $t['id'], (string) $t['fetch_hash'] ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is ours
			return $nope;
		}
		$env  = ZAW_Hub::envelope();
		$uid  = (int) $t['user_id'];
		$conv = ZAW_DB::conv( (string) $t['conv_key'] );
		$plan = self::plan();
		$key  = self::provider_key();
		$ws   = self::workspace();
		if ( ! $env || ! $conv || ! ZAW_Grant::may_use( $uid ) || null === $plan || '' === $key || '' === $ws ) {
			return self::unsent( $t, 'fetch_refused', $nope );
		}
		$site = ZAW_Tools::for_user( $uid );
		$tool = bin2hex( random_bytes( 32 ) );
		ZAW_DB::set_turn( $t['id'], array( 'tool_hash' => hash( 'sha256', $tool ),
			'site_tools' => wp_json_encode( array_values( array_map( function ( $d ) { return $d['name']; }, $site ) ) ) ) );
		$hold = max( 0.0, (int) $t['hold_milli'] / 1000.0 );
		$body = array(
			'turn_id'      => (string) $t['turn_id'],
			'conv'         => (string) $conv['engine_conv'],
			'text'         => (string) $t['question'],
			'site_tools'   => $site,
			'tool_token'   => $tool,
			'caps'         => array( 'usd' => min( (float) ZAW_Settings::get( 'turn_usd' ), $hold ), 'seconds' => self::SECONDS ),
			'headroom_usd' => $hold,
			'effort'       => (string) ZAW_Settings::get( 'effort' ),
			'web'          => (bool) ZAW_Settings::get( 'web' ),
			'models'       => $plan['models'],
			'prices'       => $plan['prices'],
			'geo'          => $plan['geo'],
			'geo_factor'   => $plan['geo_factor'],
			'search_usd'   => $plan['search_usd'],
			'workspace'    => $ws,
			'key'          => $key,
		);
		$key = null;
		try {
			$sealed = $env->seal( ZCH_HUB_PARTY, 'response', $body, (string) $t['turn_id'] );
		} catch ( \Throwable $e ) {
			$sealed = null;
		}
		$body = null;
		$tool = null;
		if ( ! $sealed ) {
			return self::unsent( $t, 'seal', $nope );
		}
		self::book_at_pull( $t, $hold, (string) $plan['models']['primary'] );
		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}
		return $sealed;
	}

	/** The pull was refused after its link was spent: nothing left the site, so nothing is booked. */
	private static function unsent( array $t, $why, $out ) {
		ZAW_DB::set_turn( $t['id'], array( 'state' => 'failed', 'error' => $why, 'finished' => ZAW_DB::now() ) );
		ZAW_Money::release( $t['hold_id'] );
		return $out;
	}

	/**
	 * The key leaves now: the hold becomes the turn's ledger row at the hold's amount (in_progress), then the hold is
	 * released (a row first, then the hold, so the turn is never counted nowhere). If the row can't be written the hold
	 * stays and the turn's end books it.
	 */
	private static function book_at_pull( array $t, $hold_usd, $model ) {
		global $wpdb;
		if ( 1 !== (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . ZAW_DB::turns() . ' SET ledger_id = -1 WHERE id = %d AND ledger_id = 0', (int) $t['id'] ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is ours
			return;
		}
		$lid = ZAW_Money::book( $t, array( 'usd' => $hold_usd ), 'in_progress', $model, false, false );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . ZAW_DB::turns() . ' SET ledger_id = %d WHERE id = %d', max( 0, (int) $lid ), (int) $t['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is ours
		if ( $lid > 0 ) {
			ZAW_Money::release( (int) $t['hold_id'] );
		}
	}

	/** A site tool ran: the turn is running (the hub pulled it and works on it). */
	public static function touch_running( array $turn ) {
		if ( 'pulled' === $turn['state'] ) {
			global $wpdb;
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . ZAW_DB::turns() . " SET state = 'running', updated = %s, rev = rev + 1 WHERE id = %d AND state = 'pulled'", ZAW_DB::now(), (int) $turn['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is ours
		}
	}

	/* ------------------------------------------------------------------ following it */

	/** Ask the hub once and keep what it says. -> the turn row as it now stands. */
	public static function advance( array $t ) {
		if ( in_array( $t['state'], self::FINAL, true ) && ! ( 'lost' === $t['state'] && (int) $t['est'] ) ) {
			return $t;
		}
		$r = ZAW_Hub::call( 'assistant_status', array( 'turn_id' => (string) $t['turn_id'], 'offset' => (int) $t['next_offset'] ) );
		if ( empty( $r['ok'] ) ) {
			$code = (string) ( $r['error']['code'] ?? '' );
			if ( 'turn_not_found' === $code && 'lost' !== $t['state'] && strtotime( (string) $t['created'] . ' UTC' ) < time() - 120 ) {
				return self::lose( $t, 'the hub no longer has this turn' );
			}
			if ( 'lost' !== $t['state'] && strtotime( (string) $t['created'] . ' UTC' ) < time() - self::LOST_S ) {
				return self::lose( $t, 'no answer from the hub for 20 minutes' );
			}
			return $t;
		}
		$d      = is_array( $r['data'] ?? null ) ? $r['data'] : array();
		$st     = is_array( $d['turn'] ?? null ) ? $d['turn'] : array();
		$events = json_decode( (string) $t['events'], true );
		$events = is_array( $events ) ? $events : array();
		$answer = (string) $t['answer'];
		$got    = is_array( $d['events'] ?? null ) ? $d['events'] : array();
		foreach ( $got as $e ) {
			if ( ! is_array( $e ) || ! isset( $e['k'], $e['t'] ) || ! in_array( $e['k'], array( 'progress', 'note', 'answer' ), true ) ) {
				continue;
			}
			$txt = (string) $e['t'];                         // coded by the hub's model; opened only through view()
			if ( 'answer' === $e['k'] ) {
				$answer .= $txt;
			} elseif ( count( $events ) < 200 ) {
				$events[] = array( 'k' => $e['k'], 't' => function_exists( 'mb_substr' ) ? mb_substr( $txt, 0, 2000 ) : substr( $txt, 0, 2000 ) );
			}
		}
		$f = array( 'events' => wp_json_encode( $events ), 'answer' => $answer );
		$f['next_offset'] = ( isset( $d['next'] ) && is_numeric( $d['next'] ) ) ? (int) $d['next'] : (int) $t['next_offset'] + count( $got );
		$state = (string) ( $st['state'] ?? '' );
		if ( in_array( $state, array( 'pulling', 'running' ), true ) && in_array( $t['state'], array( 'sending', 'queued', 'pulled' ), true ) ) {
			$f['state'] = 'running';
		}
		if ( ! empty( $st['cancelling'] ) && ! in_array( $t['state'], self::FINAL, true ) ) {
			$f['state'] = 'cancelling';
		}
		if ( preg_match( '/^[a-f0-9]{16}$/', (string) ( $st['conv'] ?? '' ) ) ) {
			$conv = ZAW_DB::conv( (string) $t['conv_key'] );
			if ( $conv && $conv['engine_conv'] !== $st['conv'] ) {
				ZAW_DB::set_conv( $conv['conv_key'], array( 'engine_conv' => (string) $st['conv'] ) );
			}
		}
		if ( ! ZAW_DB::cas_turn( $t['id'], (int) $t['rev'], $f ) ) {
			return ZAW_DB::turn( (string) $t['turn_id'] );   // another poller moved it on: theirs stands
		}
		$t = ZAW_DB::turn( (string) $t['turn_id'] );
		if ( in_array( $state, array( 'done', 'error', 'cancelled', 'failed', 'expired' ), true ) && null === ( $d['next'] ?? null ) ) {
			return self::finish( $t, $st );
		}
		if ( strtotime( (string) $t['created'] . ' UTC' ) < time() - self::LOST_S ) {
			return self::lose( $t, 'no end from the hub for 20 minutes' );
		}
		return $t;
	}

	/** A model id the hub reported, kept only when it is one this turn could have used. */
	private static function reported_model( $m ) {
		$m    = (string) $m;
		$plan = self::plan();
		$ok   = $plan ? array_filter( array_values( $plan['models'] ), 'strlen' ) : array();
		return in_array( $m, $ok, true ) ? $m : '';
	}

	/**
	 * The hub says it is over. The money first (each step safe to repeat: the page and the sweep may both get here), then
	 * the turn's last state, written only if nobody moved it since (else the next poll finishes it).
	 */
	private static function finish( array $t, array $st ) {
		$u     = is_array( $st['usage'] ?? null ) ? $st['usage'] : array();
		$usd   = max( 0.0, (float) ( $u['usd'] ?? 0 ) );
		$state = (string) $st['state'];
		$est   = ! empty( $st['est'] );
		$model = self::reported_model( $st['model'] ?? '' );
		list( $t ) = self::settle( $t, $u, $usd, $state, '' !== $model ? $model : 'other', ! empty( $st['fallback'] ), $est );
		if ( null === $t ) {
			return null;
		}
		$f = array(
			'state'    => $state,
			'stop'     => substr( preg_replace( '/[^a-z0-9_]/', '', (string) ( $st['stop'] ?? '' ) ), 0, 24 ),
			'error'    => substr( preg_replace( '/[^a-z0-9_ ;:().,-]/i', '', (string) ( $st['error'] ?? '' ) ), 0, 64 ),
			'model'    => substr( $model, 0, 32 ),
			'fallback' => ! empty( $st['fallback'] ) ? 1 : 0,
			'est'      => $est ? 1 : 0,
			'finished' => ZAW_DB::now(),
		);
		if ( (int) $t['kept_usage'] ) {            // the person's own usage only under their consent
			$f += array(
				'usd_milli'   => (int) ceil( $usd * 1000 - 1e-9 ),
				'in_tokens'   => (int) ( $u['in'] ?? 0 ), 'out_tokens' => (int) ( $u['out'] ?? 0 ), 'cache_read' => (int) ( $u['cache_read'] ?? 0 ),
				'cache_write' => (int) ( $u['cache_write_5m'] ?? 0 ) + (int) ( $u['cache_write_1h'] ?? 0 ), 'searches' => (int) ( $u['searches'] ?? 0 ),
				'calls'       => (int) ( $u['calls'] ?? 0 ), 'tool_calls' => (int) ( $u['tool_calls'] ?? 0 ),
			);
		}
		ZAW_DB::cas_turn( $t['id'], (int) $t['rev'], $f );
		return ZAW_DB::turn( (string) $t['turn_id'] );
	}

	/**
	 * The turn's money at its end, from the hub's figures. -> [ the turn row as it now stands (null if gone), pulled? ].
	 *   never pulled   its link is withdrawn (an UPDATE on the unspent link: if the hub pulled it a moment ago, it is
	 *                  pulled after all), the hold released, no row: nothing left the site
	 *   pulled         the row written at the pull is settled to these figures; if the pull could not write it, the
	 *                  row is written now (claimed, so only once) and the hold released
	 */
	private static function settle( array $t, array $u, $usd, $outcome, $model, $fallback, $est ) {
		global $wpdb;
		if ( '' !== (string) $t['fetch_hash'] ) {
			$gone = 1 === (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . ZAW_DB::turns() . " SET fetch_hash = '' WHERE id = %d AND fetch_hash = %s", (int) $t['id'], (string) $t['fetch_hash'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is ours
			if ( $gone ) {
				ZAW_Money::release( (int) $t['hold_id'] );
				return array( ZAW_DB::turn( (string) $t['turn_id'] ), false );
			}
			$t = ZAW_DB::turn( (string) $t['turn_id'] );    // pulled just now
			if ( ! $t ) {
				return array( null, true );
			}
		}
		$lid = (int) $t['ledger_id'];
		if ( $lid > 0 ) {
			ZAW_Money::settle( $lid, $t, array_merge( $u, array( 'usd' => $usd ) ), $outcome, $model, $fallback, $est );
			ZAW_Money::release( (int) $t['hold_id'] );       // released at the pull; harmless again
			return array( $t, true );
		}
		if ( 0 === $lid && 1 === (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . ZAW_DB::turns() . ' SET ledger_id = -1 WHERE id = %d AND ledger_id = 0', (int) $t['id'] ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is ours
			$new = ZAW_Money::book( $t, array_merge( $u, array( 'usd' => $usd ) ), $outcome, $model, $fallback, $est );
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . ZAW_DB::turns() . ' SET ledger_id = %d WHERE id = %d', max( 0, (int) $new ), (int) $t['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is ours
			if ( $new > 0 ) {
				ZAW_Money::release( (int) $t['hold_id'] );    // its cost is a row now
			}
			return array( ZAW_DB::turn( (string) $t['turn_id'] ), true );
		}
		return array( $t, true );                             // another poller is writing it (-1)
	}

	/**
	 * The hub lost it. Pulled: its row stays at its hold (the most it could have cost), marked estimated, and is settled
	 * if the hub reports later (the sweep asks for a day). Never pulled: its link is withdrawn and nothing is booked.
	 */
	private static function lose( array $t, $why ) {
		list( $t, $pulled ) = self::settle( $t, array(), (int) $t['hold_milli'] / 1000.0, 'lost', 'other', false, true );
		if ( null === $t ) {
			return null;
		}
		$f = array( 'state' => 'lost', 'error' => substr( $why, 0, 64 ), 'est' => $pulled ? 1 : 0, 'finished' => ZAW_DB::now() );
		if ( (int) $t['kept_usage'] ) {
			$f['usd_milli'] = $pulled ? (int) $t['hold_milli'] : 0;
		}
		ZAW_DB::cas_turn( $t['id'], (int) $t['rev'], $f );
		return ZAW_DB::turn( (string) $t['turn_id'] );
	}

	public static function cancel( $turn_id, $uid ) {
		global $wpdb;
		$t = ZAW_DB::turn( (string) $turn_id );
		if ( ! $t || (int) $t['user_id'] !== (int) $uid ) {
			return self::err( 'not_found', __( 'No such turn.', 'zorderz' ), 404 );
		}
		if ( in_array( $t['state'], self::FINAL, true ) ) {
			return self::view( $t, $uid );
		}
		$r = ZAW_Hub::call( 'assistant_cancel', array( 'turn_id' => (string) $t['turn_id'] ) );
		if ( empty( $r['ok'] ) ) {
			return self::err( 'hub', ZAW_Hub::say( $r ), 502 );
		}
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . ZAW_DB::turns() . " SET state = 'cancelling', updated = %s, rev = rev + 1 WHERE id = %d AND state IN ('sending','queued','pulled','running')", ZAW_DB::now(), (int) $t['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is ours
		// whatever the hub answered, the turn's end and its cost are the hub's own word, read now
		$t = self::advance( ZAW_DB::turn( (string) $t['turn_id'] ) );
		return self::view( $t, $uid );
	}

	/* ------------------------------------------------------------------ what the page shows */

	/** One turn for its writer: words decoded, only codes this conversation produced. Never anyone else's. */
	public static function view( $t, $uid ) {
		if ( ! is_array( $t ) || (int) $t['user_id'] !== (int) $uid ) {
			return array();
		}
		$coder = ZDZ_Private::for( 'assistant' );
		$conv  = ZAW_DB::conv( (string) $t['conv_key'] );
		list( $c, $e ) = $conv ? ZAW_DB::codes( $conv ) : array( array(), array() );
		$open   = function ( $s ) use ( $coder, $c, $e ) {
			return $coder->decode( $coder->from_reader( (string) $s, $c, null, $e ), true );
		};
		$events = json_decode( (string) $t['events'], true );
		return array(
			'turn_id'  => (string) $t['turn_id'],
			'conv'     => (string) $t['conv_key'],
			'state'    => (string) $t['state'],
			'final'    => in_array( $t['state'], self::FINAL, true ),
			'question' => $open( $t['question'] ),
			'answer'   => '' !== (string) $t['answer'] ? $open( $t['answer'] ) : '',
			'progress' => array_map( function ( $x ) use ( $open ) {
				return array( 'k' => (string) ( $x['k'] ?? '' ), 't' => $open( $x['t'] ?? '' ) );
			}, is_array( $events ) ? $events : array() ),
			'cost'     => (int) $t['kept_usage'] ? round( (int) $t['usd_milli'] / 1000.0, 4 ) : null,
			'est'      => (bool) (int) $t['est'],
			'model'    => (string) $t['model'],
			'fallback' => (bool) (int) $t['fallback'],
			'stop'     => (string) $t['stop'],
			'error'    => (string) $t['error'],
			'created'  => (string) $t['created'],
			'purged'   => '' === (string) $t['question'] && in_array( $t['state'], self::FINAL, true ),
		);
	}

	/** A conversation's turns, oldest first, for its writer. */
	public static function thread( $conv_key, $uid ) {
		global $wpdb;
		$conv = ZAW_DB::conv( (string) $conv_key );
		if ( ! $conv || (int) $conv['user_id'] !== (int) $uid ) {
			return array();
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . ZAW_DB::turns() . ' WHERE conv_key = %s ORDER BY id ASC LIMIT 200', $conv['conv_key'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is ours
		return array_values( array_filter( array_map( function ( $t ) use ( $uid ) {
			return self::view( $t, $uid );
		}, is_array( $rows ) ? $rows : array() ) ) );
	}

	/** This person's conversations, newest first (key, when, how many turns). */
	public static function conversations( $uid ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT c.conv_key, c.updated, COUNT(t.id) AS turns FROM ' . ZAW_DB::convs() . ' c LEFT JOIN ' . ZAW_DB::turns() . ' t ON t.conv_key = c.conv_key WHERE c.user_id = %d GROUP BY c.conv_key, c.updated ORDER BY c.updated DESC LIMIT 20', (int) $uid ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names are ours
		return is_array( $rows ) ? $rows : array();
	}

	/* ------------------------------------------------------------------ the schedules */

	/** Every 5 minutes: finish turns whose page was closed; keep the engine's month fresh. */
	public static function sweep() {
		global $wpdb;
		if ( ! self::ready() ) {
			return;
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . ZAW_DB::turns() . " WHERE state IN ('sending','queued','pulled','running','cancelling') OR (state = 'lost' AND est = 1 AND finished > %s) ORDER BY id ASC LIMIT 50", gmdate( 'Y-m-d H:i:s', time() - 86400 ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is ours
		foreach ( is_array( $rows ) ? $rows : array() as $t ) {
			for ( $i = 0; $i < 20; $i++ ) {                     // a turn's pages, then its end
				if ( ! is_array( $t ) ) {
					break;
				}
				$before = (int) $t['next_offset'] . '|' . $t['state'];
				$t      = self::advance( $t );
				if ( ! is_array( $t ) || in_array( $t['state'], self::FINAL, true ) || (int) $t['next_offset'] . '|' . $t['state'] === $before ) {
					break;
				}
			}
		}
		$fresh = ZAW_Money::freshness();
		if ( ! $fresh['fresh'] || $fresh['age_s'] > 1800 ) {
			ZAW_Hub::call( 'assistant_status', array() );        // the engine's month, through ZAW_Money::note()
		}
	}

	/** Daily: the coded words of turns older than 7 days are blanked; the numbers stay. */
	public static function prune() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . ZAW_DB::turns() . " SET question = '', answer = '', events = '[]' WHERE created < %s AND question <> ''", gmdate( 'Y-m-d H:i:s', time() - self::KEEP_DAYS * 86400 ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is ours
	}
}
