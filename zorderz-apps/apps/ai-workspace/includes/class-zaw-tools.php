<?php
/**
 * ZAW_Tools -- the site tools a turn may use, and the endpoint where the hub's runner asks this site's apps.
 *
 * A turn's tool list is decided HERE, per person (identity is decided on the site; the hub's runner offers exactly the
 * tools it is given). Each tool is one of the capability registry's READ verbs, offered only when
 * ZDZ_Capabilities::can() says this person may use it and only while the verb is registered; a verb with a side effect
 * is never a tool. Core offers two, written for the verbs' own inputs (Team Roles' who-can; Projects' upcoming work,
 * when the Jobs app registers it); the filter zaw_site_tools adds or narrows the list.
 *
 * POST ZDZ_REST_NS/ai-workspace/tool (no WordPress session: the hub has none) takes a sealed envelope from the hub with
 * { turn_id, token, tool, args }. It answers only when: the envelope opens with the Charts keys; the turn is live
 * (pulled or running, under 20 minutes old); the token's hash is the turn's; the tool is one the turn was given; the
 * person still holds the workspace and is neither the shared device nor in Privacy View. Then:
 *   1. any code in the arguments that THIS conversation produced is decoded; any other is made harmless;
 *   2. the verb runs through ZDZ_Capabilities::invoke() AS THE TURN'S PERSON (its own per-asker shape);
 *   3. every word of the answer is coded with ZDZ_Private::for( 'assistant' ), and ZDZ_Sealed::cut() runs again on it
 *      (a second lock on gate codes): values and keys, numbers a coder would change, and a short value its key makes
 *      a code; the keys that name the asker are left out; the codes it made join the conversation's own; it is cut at
 *      40,000 characters;
 *   4. it goes back sealed. Anything wrong is a bare 404, never a reason.
 *
 * @package Zorderz\AiWorkspace
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZAW_Tools {

	const MAX_CHARS = 40000;
	const LIVE_S    = 1200;
	/** The keys that name the asker (ZDZ_Capabilities::IDENTITY_KEYS): never sent on to the hub in an answer. */
	const ASKER_KEYS = array( 'user_id', 'requesting_user_id', 'tier', 'is_kiosk' );

	/** Site tools: the API name, the verb, what it is for, and its input (written from the verbs' own inputs). */
	public static function site_defs() {
		$kinds = 'as the Jobs app names them';
		if ( class_exists( 'ZJOB_Project_Types' ) && method_exists( 'ZJOB_Project_Types', 'types' ) ) {
			$k = array_keys( (array) ZJOB_Project_Types::types() );
			if ( $k ) {
				$kinds = 'from: ' . implode( ', ', $k );
			}
		}
		$defs = array(
			'app_roles_who_can'      => array(
				'verb'         => 'roles.who_can',
				'progress'     => 'asking Team Roles',
				'description'  => 'Team Roles: who on the team can do an ability (sell, install or repair a catalog item, a kind of work, a site condition such as working at height, or a task), or whether one person can. Answers as the asker may see it. If it asks "which one", ask again with one of the abilities it named, in ability.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'query'     => array( 'type' => 'string', 'description' => 'The ability in plain words, e.g. "install <item>", "work at height", "sell <item>".' ),
						'ability'   => array( 'type' => 'string', 'description' => 'Only an exact ability key or label that Team Roles itself gave you (e.g. from a "which one" answer); when given, query is not used. Never plain words here.' ),
						'person'    => array( 'type' => 'string', 'description' => 'Optional: one team member\'s name or initials, to ask whether that person can.' ),
						'person_id' => array( 'type' => 'integer', 'description' => 'Optional: one team member\'s id, as Team Roles gave it, instead of person.' ),
					),
				),
			),
			'app_jobs_upcoming_work' => array(
				'verb'         => 'jobs.upcoming_work',
				'progress'     => 'asking Projects',
				'description'  => 'Projects: sold work with an install day in a window of at most 30 days (or none yet): kinds of work, the day, the city, the installer\'s initials and what was sold. Never a customer\'s name, phone, e-mail, notes or money.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'from'        => array( 'type' => 'string', 'description' => 'First day, YYYY-MM-DD.' ),
						'to'          => array( 'type' => 'string', 'description' => 'Last day, YYYY-MM-DD, at most 30 days after from.' ),
						'kinds'       => array( 'type' => 'string', 'description' => 'Optional: kinds of work, comma-separated, ' . $kinds . ' (any other word is refused).' ),
						'unscheduled' => array( 'type' => 'boolean', 'description' => 'Optional: sold work with no install day yet.' ),
					),
					'required'   => array( 'from', 'to' ),
				),
			),
		);
		$f = apply_filters( 'zaw_site_tools', $defs );
		return is_array( $f ) ? $f : $defs;
	}

	/** This person's site tools, in the provider's tool shape (plus a progress line). Reads only, each one allowed. */
	public static function for_user( $uid ) {
		$uid  = (int) $uid;
		$site = array();
		if ( ! class_exists( 'ZDZ_Capabilities' ) ) {
			return $site;
		}
		foreach ( self::site_defs() as $name => $d ) {
			if ( ! is_array( $d ) || ! preg_match( '/^app_[a-z0-9_]{1,60}$/', (string) $name ) || ! is_array( $d['input_schema'] ?? null ) ) {
				continue;
			}
			$c = ZDZ_Capabilities::get( (string) ( $d['verb'] ?? '' ) );
			if ( ! $c || ! empty( $c['side_effect'] ) ) {
				continue;                                     // unknown, or a write: never a tool
			}
			if ( ! ZDZ_Capabilities::can( (string) $d['verb'], $uid ) ) {
				continue;
			}
			$site[] = array(
				'name'         => (string) $name,
				'description'  => substr( (string) ( $d['description'] ?? '' ), 0, 2000 ),
				'progress'     => substr( (string) ( $d['progress'] ?? '' ), 0, 80 ),
				'input_schema' => $d['input_schema'],
			);
			if ( count( $site ) >= 20 ) {
				break;
			}
		}
		return $site;
	}

	/* ------------------------------------------------------------------ the endpoint */

	private static function nope() {
		return new WP_Error( 'not_found', 'Not found.', array( 'status' => 404 ) );
	}

	public static function rest_tool( $req ) {
		$outer = json_decode( (string) $req->get_body(), true );
		return self::answer( ZAW_Hub::envelope(), $outer );
	}

	/** The endpoint's work, given the site's envelope and the request's JSON body. -> a sealed reply, or a bare 404. */
	public static function answer( $env, $outer ) {
		if ( ! $env || ! is_array( $outer ) ) {
			return self::nope();
		}
		try {
			$inner = $env->open( $outer, ZCH_HUB_PARTY, 'request' );
		} catch ( \Throwable $e ) {
			return self::nope();
		}
		$b    = is_array( $inner['body'] ?? null ) ? $inner['body'] : array();
		$turn = ZAW_DB::turn( (string) ( $b['turn_id'] ?? '' ) );
		$tok  = (string) ( $b['token'] ?? '' );
		if ( ! $turn || ! in_array( $turn['state'], array( 'pulled', 'running' ), true ) || '' === (string) $turn['tool_hash']
			|| ! preg_match( '/^[a-f0-9]{64}$/', $tok ) || ! hash_equals( (string) $turn['tool_hash'], hash( 'sha256', $tok ) )
			|| strtotime( (string) $turn['created'] . ' UTC' ) < time() - self::LIVE_S ) {
			return self::nope();
		}
		$given = json_decode( (string) $turn['site_tools'], true );
		$name  = (string) ( $b['tool'] ?? '' );
		$defs  = self::site_defs();
		$uid   = (int) $turn['user_id'];
		if ( ! is_array( $given ) || ! in_array( $name, $given, true ) || ! isset( $defs[ $name ]['verb'] ) ) {
			$reply = array( 'ok' => false, 'text' => 'That tool is not available in this conversation.' );
		} elseif ( ! ZAW_Grant::may_use( $uid ) ) {
			$reply = array( 'ok' => false, 'text' => 'The Ai Workspace is no longer on for this person, so the app was not asked.' );
		} else {
			ZAW_Turns::touch_running( $turn );
			$reply = self::run( $turn, (string) $defs[ $name ]['verb'], is_array( $b['args'] ?? null ) ? $b['args'] : array() );
		}
		try {
			return $env->seal( ZCH_HUB_PARTY, 'response', $reply, (string) $inner['rid'] );
		} catch ( \Throwable $e ) {
			return self::nope();
		}
	}

	/** Run one verb as the turn's person; the answer coded. -> { ok, text }. */
	public static function run( array $turn, $verb, array $args ) {
		$coder = ZDZ_Private::for( 'assistant' );
		$conv  = ZAW_DB::conv( (string) $turn['conv_key'] );
		list( $allowed_c, $allowed_e ) = $conv ? ZAW_DB::codes( $conv ) : array( array(), array() );
		$args = self::walk( $args, function ( $s ) use ( $coder, $allowed_c, $allowed_e ) {
			// a code this conversation produced opens; any other is made harmless (from_reader), then the words open
			return $coder->decode( $coder->from_reader( $s, $allowed_c, null, $allowed_e ), true );
		}, 0 );
		$prev = get_current_user_id();
		wp_set_current_user( (int) $turn['user_id'] );
		try {
			$out = ZDZ_Capabilities::invoke( $verb, $args );
		} catch ( \Throwable $e ) {
			$out = array( 'success' => false, 'error' => 'That could not be answered just now.' );
		} finally {
			wp_set_current_user( $prev );
		}
		$ok = is_array( $out ) && ! empty( $out['success'] ) && empty( $out['denied'] );
		$coder->new_request();
		$text = self::coded_text( is_array( $out ) ? $out : array( 'answer' => (string) $out ), $coder );
		ZAW_DB::add_codes( (string) $turn['conv_key'], $text );
		return array( 'ok' => $ok, 'text' => $text );
	}

	/** A verb's answer as the coded JSON text the hub receives, cut at MAX_CHARS. */
	public static function coded_text( array $out, $coder ) {
		$coded = self::code_answer( $out, function ( $s ) use ( $coder ) {
			return self::cut_plain( $coder->encode( $s ) );   // coded, then the gate-code lock again
		} );
		$text = wp_json_encode( $coded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $text ) ) {
			$text = '{"error":"the answer could not be written"}';
		}
		$len = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
		if ( $len > self::MAX_CHARS ) {
			$text = ( function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::MAX_CHARS ) : substr( $text, 0, self::MAX_CHARS ) ) . "\n[cut at " . self::MAX_CHARS . ' characters; ask for a narrower part]';
		}
		return $text;
	}

	/** ZDZ_Sealed::cut() on the words between codes (a code itself is never read as digits). */
	public static function cut_plain( $s ) {
		if ( ! class_exists( 'ZDZ_Sealed' ) ) {
			return (string) $s;
		}
		$parts = preg_split( '/(\x{27E6}[ce]:[A-Za-z0-9_\-]*\x{27E7})/u', (string) $s, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $parts ) ) {
			return ZDZ_Sealed::cut( (string) $s );
		}
		foreach ( $parts as $i => $p ) {
			if ( 0 === $i % 2 && '' !== $p ) {
				$parts[ $i ] = ZDZ_Sealed::cut( $p );
			}
		}
		return implode( '', $parts );
	}

	/**
	 * A verb's answer, coded for the hub: every string value AND every string key through $code; a number as text when
	 * coding would change it (a phone kept as a number); and a short value its key makes a code ("gate" => 1234,
	 * "pin" => "0420") sealed by that context, as ZDZ_Sealed::cut() reads "gate: 1234". At most 8 levels and 2,000
	 * values. The asker's own keys are left out.
	 */
	public static function code_answer( $v, callable $code, $depth = 0, &$count = 0 ) {
		if ( ! is_array( $v ) ) {
			return self::code_scalar( $v, $code, '', $count );
		}
		if ( $depth > 8 ) {
			return array();
		}
		$out = array();
		foreach ( $v as $k => $x ) {
			if ( is_string( $k ) && in_array( strtolower( $k ), self::ASKER_KEYS, true ) ) {
				continue;
			}
			$ks          = (string) $k;                       // PHP makes a numeric string key an int: coded all the same
			$kc          = $code( $ks );
			$key         = $kc !== $ks ? $kc : $k;
			$out[ $key ] = is_array( $x ) ? self::code_answer( $x, $code, $depth + 1, $count ) : self::code_scalar( $x, $code, is_string( $k ) ? $k : '', $count );
		}
		return $out;
	}

	private static function code_scalar( $x, callable $code, $key, &$count ) {
		if ( ++$count > 2000 ) {
			return '';
		}
		if ( ! is_string( $x ) && ! is_int( $x ) && ! is_float( $x ) ) {
			return $x;                                        // true, false, null
		}
		$plain = is_string( $x ) ? $x : (string) $x;
		if ( '' !== $key && strlen( $plain ) <= 40 && class_exists( 'ZDZ_Sealed' ) && ZDZ_Sealed::cut( $plain ) === $plain ) {
			$probe = str_replace( '_', ' ', $key ) . ': ' . $plain;
			$cut   = ZDZ_Sealed::cut( $probe );
			if ( $cut !== $probe ) {
				return preg_match( '/\[[a-z ]*code sealed\]/', $cut, $m ) ? $m[0] : '[gate code sealed]';
			}
		}
		$coded = $code( $plain );
		return ( is_string( $x ) || $coded !== $plain ) ? $coded : $x;
	}

	/** Apply $fn to every string in a nested array (keys left alone; at most 8 levels, 2,000 strings). */
	public static function walk( $v, callable $fn, $depth, &$count = 0 ) {
		if ( is_string( $v ) ) {
			return ++$count > 2000 ? '' : $fn( $v );
		}
		if ( ! is_array( $v ) || $depth > 8 ) {
			return is_array( $v ) ? array() : $v;
		}
		$out = array();
		foreach ( $v as $k => $x ) {
			$out[ $k ] = self::walk( $x, $fn, $depth + 1, $count );
		}
		return $out;
	}
}
