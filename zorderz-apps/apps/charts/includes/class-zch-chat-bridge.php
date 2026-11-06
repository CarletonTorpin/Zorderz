<?php
/**
 * The registry verb `charts.run`: Charts for the chat assistant and other callers.
 *
 * The chat writes a version-2 Charts spec itself, from the closed lists this bridge publishes
 * (`vocab_for_chat`), and hands it here. The hub runs it as written (op `run_v2`): no reader,
 * no model, no second look; the rows come back in milliseconds and every number is the hub's
 * own. The bridge applies the same access block the Charts page uses, as the `bot` caller
 * (never account names, never line items, never invoice cards), and the hub applies the same
 * floors again. The chat reads the rows back as data and reasons over them; a chip opens the
 * same spec in Charts.
 *
 * Also through this verb: a canvassing recipe ({prospect: …}) and a territory question
 * ({territories: {who}}).
 *
 * Nothing here writes anything (`side_effect` false); nothing is stored but the audit line
 * every hub call gets.
 *
 * @package Zorderz\Charts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZCH_Chat_Bridge {
	const VERB      = 'charts.run';
	const MAX_ROWS  = 240;            // 60 months x 4 groups: what a chat turn can read; the hub's own cap is higher.
	const VOCAB_TTL = 3600;

	public static function get_capability_descriptor(): array {
		return array(
			'verb'          => self::VERB,
			'provider'      => 'charts',
			'app'           => ZCH_APP_ID,
			'tier'          => 'viewer',       // anyone signed in; Charts' own per-person access decides the rest.
			'needs_app'     => false,
			'callback'      => array( __CLASS__, 'run_for_chat' ),
			'kiosk'         => false,
			'kiosk_bounded' => false,
			'side_effect'   => false,
			'summary'       => 'Runs a sales chart from a spec and returns its rows (the numbers Charts would draw); money only for people who may see it.',
		);
	}

	/** True when a hub is connected and this person may run charts at all. */
	public static function is_available( $uid = null ): bool {
		$uid = null === $uid ? get_current_user_id() : (int) $uid;
		if ( ! ZCH_Hub_Client::from_config() ) {
			return false;
		}
		$b = ZCH_Access::block( $uid, 'bot' );
		return in_array( 'run_v2', $b['ops'], true );
	}

	private static function no( string $why, string $msg, bool $denied = false, array $extra = array() ): array {
		$out = array( 'success' => false, 'message' => $msg, 'verb' => self::VERB );
		if ( $denied ) {
			$out['denied'] = true;
			$out['reason'] = $why;
		} else {
			$out['error'] = $why;
		}
		return $out + $extra;
	}

	/**
	 * The registry callback. $args: spec (the v2 spec, an array), plus what ZDZ_Capabilities::invoke()
	 * adds (user_id, requesting_user_id, tier, is_kiosk: the signed-in person, never the payload).
	 */
	public static function run_for_chat( array $args ): array {
		$uid = (int) ( $args['requesting_user_id'] ?? $args['user_id'] ?? get_current_user_id() );
		if ( ! empty( $args['is_kiosk'] ) || ZCH_Access::is_kiosk( $uid ) ) {
			return self::no( 'kiosk', __( 'Charts is not available on the shared device.', 'zorderz' ), true );
		}
		$spec = $args['spec'] ?? null;
		if ( is_string( $spec ) ) {
			$spec = json_decode( $spec, true );
		}
		if ( ! is_array( $spec ) || ! $spec ) {
			return self::no( 'bad_spec', __( 'No chart spec.', 'zorderz' ) );
		}
		$spec = self::clean_v2( $spec );
		if ( isset( $spec['prospect'] ) ) {                       // a canvassing recipe.
			return self::run_prospect( $spec['prospect'], $uid );
		}
		if ( array_key_exists( 'territories', $spec ) ) {         // who covers which ZIPs (Territories' verbs).
			$who = ZCH_Territories::clean_who( $spec['territories']['who'] ?? '' );
			if ( '' === $who ) {
				return self::no( 'bad_spec', 'A territories spec is {territories: {who: "me" | "all" | "zip:NNNNN" | "person:<initials or first name>" | "zones" | "zone:<id>"}}.' );
			}
			// Territories decides who sees what (its verbs, as the signed-in person); not Charts'
			// figures switch. The same per-minute budget and audit line as the page's route.
			if ( ! ZCH_Store::rate_ok( $uid ) ) {
				return self::no( 'rate_limited', __( 'Too many in a minute. Try again shortly.', 'zorderz' ) );
			}
			$out = ZCH_Territories::for_chat( $who, self::VERB );
			ZCH_Store::audit( 'territories', ! empty( $out['success'] ), null, count( (array) ( $out['people'] ?? array() ) ), null, null );
			return $out;
		}
		// The business's switch (Charts -> Settings -> Assistant) decides whether the assistant gets figures at all.
		if ( ! ZCH_Store::settings()['bot_totals'] ) {
			return self::no( 'bot_totals', __( 'Charts totals are not shared with the assistant (Charts > Settings > Assistant).', 'zorderz' ), true );
		}
		$b = ZCH_Access::block( $uid, 'bot' );
		if ( ! in_array( 'run_v2', $b['ops'], true ) ) {
			return self::no( 'charts_access', __( 'Charts is not turned on for this account.', 'zorderz' ), true );
		}
		// A money ask from a person without money is refused here, by the verb itself, not left
		// to the hub's floors or to the chat's own list check (other callers can use this verb).
		if ( empty( $b['money'] ) && self::asks_money( $spec ) ) {
			return self::no( 'money', __( 'Dollar figures are not available to this account.', 'zorderz' ), true );
		}
		// By-person asks need by_person, or compare (the hub then answers in counts).
		if ( empty( $b['by_person'] ) && empty( $b['compare'] ) && self::asks_person( $spec ) ) {
			return self::no( 'by_person', __( 'By-salesperson figures are not available to this account.', 'zorderz' ), true );
		}
		$client = ZCH_Hub_Client::from_config();
		if ( ! $client ) {
			ZCH_Store::audit( 'run_v2', false, 'not_configured', null, null, null );
			return self::no( 'hub_unavailable', ZCH_Config::local_message() );
		}
		$body  = array( 'spec' => $spec, 'access' => $b );
		$reply = $client->call( 'run_v2', $body, 'wp_' . $uid, wp_date( 'Y-m-d' ) );
		$rows  = isset( $reply['data']['result']['rows'] ) ? count( $reply['data']['result']['rows'] ) : null;
		ZCH_Store::audit( 'run_v2', ! empty( $reply['ok'] ), $reply['error']['code'] ?? null, $rows, $client->last_ms, null );
		if ( empty( $reply['ok'] ) ) {
			$code = (string) ( $reply['error']['code'] ?? 'hub_error' );
			if ( 'hub_unavailable' === $code ) {
				update_option( 'zch_last_fail', time(), false );
			}
			if ( 'bad_request' === $code ) {
				// A hub that does not run v2 specs: say so, never guess a chart.
				return self::no( 'hub_too_old', __( 'Charts did not answer: the hub does not run specs yet.', 'zorderz' ) );
			}
			/* translators: %s: an error code */
			return self::no( $code, sprintf( __( 'Charts did not answer (%s).', 'zorderz' ), $code ) );
		}
		$d      = $reply['data'];
		$status = (string) ( $d['status'] ?? 'invalid' );
		if ( 'invalid' === $status ) {
			return self::no( 'invalid_spec', __( 'Charts could not run that spec.', 'zorderz' ), false, array( 'problems' => array_values( array_map( 'strval', (array) ( $d['problems'] ?? array() ) ) ) ) );
		}
		if ( 'clarify' === $status || 'unsupported' === $status ) {
			return self::no( 'clarify', (string) ( $d['clarify_question'] ?? __( 'Charts needs a clearer spec.', 'zorderz' ) ) );
		}
		return self::shape( $d, $b, $spec );
	}

	/** Drop the columns a person without money must not get. */
	private static function drop_columns( array $cols, array $rows, string $name ): array {
		$drop = array();
		foreach ( $cols as $i => $c ) {
			if ( $name === $c ) {
				$drop[] = $i;
			}
		}
		if ( ! $drop ) {
			return array( $cols, $rows );
		}
		$cols = array_values( array_diff_key( $cols, array_flip( $drop ) ) );
		$rows = array_map(
			function ( $r ) use ( $drop ) {
				return array_values( array_diff_key( (array) $r, array_flip( $drop ) ) );
			},
			$rows
		);
		return array( $cols, $rows );
	}

	/** What the chat gets: the rows (capped), the titles, how it was read, the notes, and the public spec for a chip. */
	public static function shape( array $d, array $b, array $spec_in ): array {
		$res   = (array) ( $d['result'] ?? array() );
		$pub   = (array) ( $d['spec'] ?? array() );
		$cols  = array_values( array_map( 'strval', (array) ( $res['columns'] ?? array() ) ) );
		$rows  = (array) ( $res['rows'] ?? array() );
		$money = ! empty( $b['money'] );
		if ( ! $money ) {                       // belt and braces: the hub already measures jobs for them.
			list( $cols, $rows ) = self::drop_columns( $cols, $rows, 'revenue' );
			unset( $res['totals']['revenue'] );
		}
		$total   = count( $rows );
		$rows    = array_slice( $rows, 0, self::MAX_ROWS );
		$wx      = isset( $res['weather'] ) && is_array( $res['weather'] ) ? $res['weather'] : null;
		$weather = null;
		if ( $wx && 'ok' === ( $wx['status'] ?? '' ) ) {
			$weather = array(
				'label'  => (string) ( $wx['label'] ?? '' ),
				'unit'   => (string) ( $wx['unit'] ?? '' ),
				'view'   => (string) ( $wx['view'] ?? '' ),
				'values' => (array) ( $wx['values'] ?? array() ),
				'corr'   => isset( $wx['corr'] ) ? (array) $wx['corr'] : null,
			);
			// Each group's own values and r, as the hub sent them (groups are the chart's own
			// labels, places or regions, never a person or a customer).
			if ( 'ok' === ( $wx['by_group'] ?? '' ) && is_array( $wx['groups'] ?? null ) ) {
				$weather['by_group'] = 'ok';
				$weather['groups']   = array();
				foreach ( $wx['groups'] as $g => $e ) {
					if ( ! is_array( $e ) || 'ok' !== ( $e['status'] ?? '' ) ) {
						continue;
					}
					$weather['groups'][ (string) $g ] = array(
						'values' => (array) ( $e['values'] ?? array() ),
						'corr'   => isset( $e['corr'] ) ? (array) $e['corr'] : null,
						'zips'   => (int) ( $e['zips'] ?? 0 ),
					);
				}
			} elseif ( isset( $wx['by_group'] ) ) {
				$weather['by_group'] = (string) $wx['by_group'];
			}
		}
		$spec_public = array_diff_key( $pub, array( 'assumptions' => 1, 'clarify_question' => 1, 'clarify_options' => 1 ) );
		$measure     = (string) ( $pub['measure'] ?? '' );
		if ( ! $money && ( in_array( $measure, array( 'revenue', 'avg_job' ), true ) || in_array( (string) ( $pub['agg'] ?? '' ), array( 'per_job', 'job_range' ), true ) ) ) {
			// The hub answered in dollars for a person without money: hand back no figures at all.
			list( $cols, $rows ) = self::drop_columns( $cols, $rows, 'value' );
			unset( $res['totals']['value'], $res['read_as'], $res['notes'], $d['caption'] );
			$measure = 'jobs';
		}
		return array(
			'success'     => true,
			'verb'        => self::VERB,
			'title'       => (string) ( $res['title_full'] ?? $pub['title'] ?? '' ),
			'title_short' => (string) ( $pub['title'] ?? '' ),
			'measure'     => $measure,
			'unit'        => in_array( $measure, array( 'revenue', 'avg_job' ), true ) ? '$' : $measure,
			'columns'     => $cols,
			'rows'        => $rows,
			'rows_total'  => $total,
			'truncated'   => $total > self::MAX_ROWS,
			'periods'     => array_values( array_map( 'strval', (array) ( $res['periods'] ?? array() ) ) ),
			'groups'      => array_values( array_map( 'strval', (array) ( $res['groups'] ?? array() ) ) ),
			'totals'      => (array) ( $res['totals'] ?? array() ),
			'empty'       => ! empty( $res['empty'] ),
			'read_as'     => array_values( array_map( 'strval', (array) ( $res['read_as'] ?? array() ) ) ),
			'notes'       => array_values( array_map( 'strval', (array) ( $res['notes'] ?? array() ) ) ),
			'caption'     => (string) ( $d['caption'] ?? '' ),
			'weather'     => $weather,
			'data_as_of'  => (string) ( $d['meta']['data_as_of'] ?? '' ),
			'compute_ms'  => (int) ( $d['meta']['compute_ms'] ?? 0 ),
			'money'       => $money,
			'spec_public' => $spec_public,       // what the Charts page opens; it carries the v2 copy.
			'spec_v2'     => $spec_in,
		);
	}

	/** A recipe through charts.run: the same doors (the Assistant switch, run_v2 for this person), counts and places back. */
	private static function run_prospect( array $recipe, int $uid ): array {
		if ( ! ZCH_Store::settings()['bot_totals'] ) {
			return self::no( 'bot_totals', __( 'Charts totals are not shared with the assistant (Charts > Settings > Assistant).', 'zorderz' ), true );
		}
		$reply = ZCH_Prospect::run( $recipe, $uid, 'bot' );
		if ( empty( $reply['ok'] ) ) {
			$code = (string) ( $reply['error']['code'] ?? 'hub_error' );
			if ( 'forbidden' === $code ) {
				return self::no( 'charts_access', __( 'Charts is not turned on for this account.', 'zorderz' ), true );
			}
			/* translators: %s: an error code */
			return self::no( $code, sprintf( __( 'Charts did not answer (%s).', 'zorderz' ), $code ) );
		}
		$d      = (array) $reply['data'];
		$status = (string) ( $d['status'] ?? '' );
		if ( 'prospect' === $status ) {
			return ZCH_Prospect::for_chat( $d, $recipe );
		}
		if ( 'clarify' === $status || 'unsupported' === $status ) {
			return self::no( 'clarify', (string) ( $d['clarify_question'] ?? __( 'Charts needs a clearer recipe.', 'zorderz' ) ) );
		}
		// A hub that does not rank neighborhoods reads {prospect} as an empty chart spec: say so, never guess.
		return self::no( 'hub_too_old', __( 'Charts did not answer: the hub does not rank neighborhoods yet.', 'zorderz' ) );
	}

	/** A v2 spec is settings: only its known keys, only plain data, bounded. Never an account or one customer. */
	public static function clean_v2( $spec ): array {
		if ( is_array( $spec ) && isset( $spec['prospect'] ) && is_array( $spec['prospect'] ) ) {
			return array( 'prospect' => ZCH_Prospect::clean_recipe( $spec['prospect'] ) );
		}
		if ( is_array( $spec ) && array_key_exists( 'territories', $spec ) ) {
			$t = is_array( $spec['territories'] ) ? $spec['territories'] : array( 'who' => $spec['territories'] );
			return array( 'territories' => array( 'who' => is_scalar( $t['who'] ?? null ) ? mb_substr( (string) $t['who'], 0, 40 ) : '' ) );
		}
		$keys = array(
			'intent', 'measure', 'periods', 'compare', 'to_date', 'to_date_cut', 'overlay', 'months', 'grain',
			'date_part', 'split', 'facet', 'filters', 'series', 'merge', 'agg', 'rank', 'top_n', 'share',
			'chart_type', 'orientation', 'labels', 'weather', 'zipfacts',
		);
		$out  = array();
		foreach ( (array) $spec as $k => $v ) {
			if ( ! in_array( $k, $keys, true ) && ! preg_match( '/^[a-z_]{1,30}_mode$/', (string) $k ) ) {
				continue;
			}
			if ( is_string( $v ) ) {
				$v = mb_substr( sanitize_text_field( $v ), 0, 80 );
			} elseif ( is_array( $v ) ) {
				$v = json_decode( wp_json_encode( $v ), true );          // plain data only.
				if ( strlen( (string) wp_json_encode( $v ) ) > 4000 ) {
					continue;
				}
			} elseif ( ! is_bool( $v ) && ! is_int( $v ) && ! is_float( $v ) && null !== $v ) {
				continue;
			}
			$out[ $k ] = $v;
		}
		// No caller of this verb writes series/merge (per-series filters could carry an account).
		unset( $out['series'], $out['merge'] );
		if ( isset( $out['filters'] ) && ! is_array( $out['filters'] ) ) {
			unset( $out['filters'] );
		}
		if ( isset( $out['filters'] ) ) {
			$out['filters'] = self::strip_names( $out['filters'] );           // never names, never one customer, from chat.
		}
		return $out;
	}

	/** Remove accounts/customers keys anywhere in a filters tree, whatever their case. */
	public static function strip_names( array $f ): array {
		$out = array();
		foreach ( $f as $k => $v ) {
			if ( is_string( $k ) && in_array( strtolower( $k ), array( 'accounts', 'customers', 'account', 'customer' ), true ) ) {
				continue;
			}
			$out[ $k ] = is_array( $v ) ? self::strip_names( $v ) : $v;
		}
		return $out;
	}

	/** Does the spec ask for a dollar figure? */
	public static function asks_money( array $spec ): bool {
		$m = strtolower( (string) ( $spec['measure'] ?? '' ) );
		$a = strtolower( (string) ( $spec['agg'] ?? '' ) );
		return in_array( $m, array( 'revenue', 'avg_job' ), true ) || in_array( $a, array( 'per_job', 'job_range' ), true );
	}

	/** Does the spec ask for a by-salesperson view or filter? */
	public static function asks_person( array $spec ): bool {
		foreach ( array( 'split', 'facet' ) as $k ) {
			if ( 'salesperson' === strtolower( (string) ( $spec[ $k ] ?? '' ) ) ) {
				return true;
			}
		}
		$f = isset( $spec['filters'] ) && is_array( $spec['filters'] ) ? $spec['filters'] : array();
		return isset( $f['salespeople'] ) || isset( $f['exclude_salespeople'] );
	}

	/**
	 * The closed lists the chat may write a spec from, for THIS person: measures they may see,
	 * splits they may use, the pack's own products / work types / towns / tags / lead sources,
	 * places, and the spec grammar. No account names, no customers, no line items. Cached an hour
	 * per access shape.
	 */
	public static function vocab_for_chat( $uid = null ): array {
		$uid = null === $uid ? get_current_user_id() : (int) $uid;
		$b   = ZCH_Access::block( $uid, 'bot' );
		if ( ! in_array( 'vocab', $b['ops'], true ) ) {
			return array();
		}
		$key = 'zch_chat_vocab_' . md5( wp_json_encode( array( $b['money'], $b['by_person'], $b['scope'], ! empty( $b['compare'] ), ZCH_VERSION, ZCH_Territories::available( $uid ) ) ) );
		$hit = get_transient( $key );
		if ( is_array( $hit ) && isset( $hit['measures'] ) ) {
			return $hit;
		}
		$client = ZCH_Hub_Client::from_config();
		if ( ! $client ) {
			return array();
		}
		$reply = $client->call( 'vocab', array( 'access' => $b ), 'wp_' . $uid, wp_date( 'Y-m-d' ) );
		ZCH_Store::audit( 'vocab', ! empty( $reply['ok'] ), $reply['error']['code'] ?? null, null, $client->last_ms, null );
		if ( empty( $reply['ok'] ) || ! is_array( $reply['data'] ) ) {
			return array();
		}
		$out = self::vocab_shape( $reply['data'], $b, $uid );
		set_transient( $key, $out, self::VOCAB_TTL );
		return $out;
	}

	/** The vocab the chat gets, from the hub's answer and this person's access block. */
	public static function vocab_shape( array $v, array $b, $uid = 0 ): array {
		$s2       = is_array( $v['spec2'] ?? null ) ? $v['spec2'] : array();
		$measures = array_values( array_map( 'strval', (array) ( $s2['measures'] ?? array( 'revenue', 'jobs', 'customers', 'units', 'avg_job' ) ) ) );
		if ( empty( $b['money'] ) ) {
			$measures = array_values( array_diff( $measures, array( 'revenue', 'avg_job' ) ) );
		}
		$splits = array_values( array_map( 'strval', (array) ( $s2['splits'] ?? $v['splits'] ?? array() ) ) );
		$splits = array_values( array_diff( $splits, array( 'account', 'none' ) ) );
		$people = ! empty( $b['by_person'] ) || ! empty( $b['compare'] );        // compare = by salesperson, as counts.
		if ( ! $people ) {
			$splits = array_values( array_diff( $splits, array( 'salesperson' ) ) );
		}
		$labels = (array) ( $v['split_labels'] ?? array() );
		return array(
			'hub_runs_specs'      => isset( $v['spec2'] ),
			'measures'            => $measures,
			'splits'              => array_map(
				function ( $s ) use ( $labels ) {
					return array( 'key' => $s, 'label' => (string) ( $labels[ $s ] ?? $s ) );
				},
				$splits
			),
			'grains'              => (array) ( $s2['grains'] ?? array( 'none', 'year', 'quarter', 'month', 'week', 'day' ) ),
			'date_parts'          => (array) ( $s2['date_parts'] ?? array() ),
			'compares'            => (array) ( $s2['compares'] ?? array() ),
			'aggs'                => (array) ( $s2['aggs'] ?? array() ),
			'chart_types'         => (array) ( $s2['chart_types'] ?? array() ),
			'period_forms'        => (array) ( $s2['period_forms'] ?? array() ),
			'list_filters'        => array_values( array_diff( (array) ( $s2['list_filters'] ?? array() ), array( 'accounts', 'customers' ) ) ),
			'excludes'            => (array) ( $s2['excludes'] ?? array() ),
			'products'            => (array) ( $v['products'] ?? array() ),
			'work_types'          => (array) ( $v['work_types'] ?? array() ),
			'areas'               => (array) ( $v['areas'] ?? array() ),
			'tag_groups'          => array_map(
				function ( $g ) {
					return array( 'name' => (string) ( $g['name'] ?? '' ), 'tags' => (array) ( $g['tags'] ?? array() ) );
				},
				(array) ( $v['tag_groups'] ?? array() )
			),
			'lead_sources'        => (array) ( $v['lead_sources'] ?? array() ),
			'salespeople'         => $people ? (array) ( $v['salespeople'] ?? array() ) : array(),
			'regions'             => self::regions_of( $v ),
			'region_words'        => self::region_words_of( $v ),
			'place_filters'       => self::place_filters_of( $v ),
			'payments'            => (array) ( $v['payments'] ?? array() ),
			'years'               => (array) ( $v['years'] ?? array() ),
			'data_as_of'          => (string) ( $v['data_as_of'] ?? '' ),
			'money'               => ! empty( $b['money'] ),
			'by_person'           => ! empty( $b['by_person'] ),
			'compare'             => ! empty( $b['compare'] ),
			'scoped_to'           => is_array( $b['scope'] ) ? array_values( $b['scope'] ) : null,
			'prospect'            => self::prospect_vocab_of( $v ),
			'territories'         => self::territories_vocab( $uid ),
		);
	}

	/**
	 * What a territories spec may say, for THIS person ([] when Territories is not there or they
	 * may not ask it). The answers come from Territories' own verbs, shaped by who asks; the chat
	 * writes the spec, never the territories.
	 */
	public static function territories_vocab( $uid = null ): array {
		if ( ! ZCH_Territories::available( $uid ) ) {
			return array();
		}
		return array(
			'spec'    => array( 'territories' => array( 'who' => 'me | all | zip:NNNNN | person:<initials or first name> | zones | zone:<id>' ) ),
			'whos'    => array(
				'me'            => 'the asker\'s own ZIP codes',
				'all'           => 'every territory the asker may see',
				'zip:NNNNN'     => 'who covers one ZIP, and the zones it is in',
				'person:<word>' => 'one person\'s, by initials or first name',
				'zones'         => 'the zones: where each kind of work is offered, and named areas',
				'zone:<id>'     => 'one zone',
			),
			'phrases' => array( 'what are my territories', 'my zip codes', 'who covers <a ZIP>', 'show all territories', 'show <a first name>\'s territory' ),
			'note'    => 'A salesperson sees only their own territory unless an admin lets salespeople see each other (set in Territories). Never a figure: for sales in a territory, use a chart.',
		);
	}

	/** The hub's canvassing vocabulary, strings and numbers only (empty when the hub has none). */
	private static function prospect_vocab_of( array $v ): array {
		$p = is_array( $v['prospect'] ?? null ) ? $v['prospect'] : array();
		if ( ! $p ) {
			return array();
		}
		$ing = array();
		foreach ( (array) ( $p['ingredients'] ?? array() ) as $k => $e ) {
			if ( ! is_string( $k ) || ! ZCH_Prospect::is_ingredient( $k ) || ! is_array( $e ) ) {
				continue;
			}
			$ing[ $k ] = array(
				'label'    => (string) ( $e['label'] ?? $k ),
				'unit'     => (string) ( $e['unit'] ?? '' ),
				'better'   => 'less' === ( $e['better'] ?? '' ) ? 'less' : 'more',
				'zip_wide' => ! empty( $e['zip_wide'] ),
			);
		}
		$defaults = array();
		foreach ( (array) ( $p['defaults'] ?? array() ) as $prod => $w ) {
			if ( ! is_string( $prod ) || ! is_array( $w ) ) {
				continue;
			}
			foreach ( $w as $k => $x ) {
				if ( ZCH_Prospect::is_ingredient( $k ) && is_numeric( $x ) ) {
					$defaults[ $prod ][ $k ] = (float) $x;
				}
			}
		}
		$dw = array();
		foreach ( (array) ( $p['default_weights'] ?? array() ) as $k => $x ) {
			if ( ZCH_Prospect::is_ingredient( $k ) && is_numeric( $x ) ) {
				$dw[ $k ] = (float) $x;
			}
		}
		return array(
			'products'        => array_map( 'strval', array_filter( (array) ( $p['products'] ?? array() ), 'is_scalar' ) ),
			'ingredients'     => $ing,
			'defaults'        => $defaults,
			'default_weights' => $dw,
			'how_many_max'    => (int) ( $p['how_many_max'] ?? 20 ),
		);
	}

	/** The hub's regions as [{name, towns[]}]: strings only, nothing else rides along. */
	private static function regions_of( array $v ): array {
		$out = array();
		foreach ( (array) ( $v['regions'] ?? array() ) as $r ) {
			if ( ! is_array( $r ) || ! isset( $r['name'] ) || '' === (string) $r['name'] ) {
				continue;
			}
			$towns = array_values( array_filter( array_map( 'strval', array_filter( (array) ( $r['towns'] ?? array() ), 'is_scalar' ) ), 'strlen' ) );
			$out[] = array( 'name' => (string) $r['name'], 'towns' => $towns );
		}
		return $out;
	}

	/** The everyday words for places ("the north side") => the regions and towns each means. */
	private static function region_words_of( array $v ): array {
		$out = array();
		foreach ( (array) ( $v['region_words'] ?? array() ) as $word => $meant ) {
			if ( ! is_string( $word ) || '' === $word ) {
				continue;
			}
			$out[ $word ] = array_values( array_filter( array_map( 'strval', array_filter( (array) $meant, 'is_scalar' ) ), 'strlen' ) );
		}
		return $out;
	}

	/** The region pack's place filters (a key, a label and its values), strings only. */
	private static function place_filters_of( array $v ): array {
		$out = array();
		foreach ( (array) ( $v['place_filters'] ?? array() ) as $f ) {
			if ( ! is_array( $f ) || ! isset( $f['key'] ) || ! preg_match( '/^[a-z][a-z0-9_]{0,39}$/', (string) $f['key'] ) ) {
				continue;
			}
			$out[] = array(
				'key'    => (string) $f['key'],
				'label'  => (string) ( $f['label'] ?? $f['key'] ),
				'values' => array_values( array_map( 'strval', array_filter( (array) ( $f['values'] ?? array() ), 'is_scalar' ) ) ),
			);
		}
		return $out;
	}
}

if ( function_exists( 'add_filter' ) ) {
	add_filter(
		'zdz_register_capabilities',
		function ( $caps ) {
			$caps = is_array( $caps ) ? $caps : array();
			$d    = ZCH_Chat_Bridge::get_capability_descriptor();
			$caps[ $d['verb'] ] = $d;
			return $caps;
		},
		20
	);
}
