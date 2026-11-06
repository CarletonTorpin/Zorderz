<?php
/**
 * The registry verb `charts.survey`: the Charts hub transcribes a recorded survey drive and reads
 * it with its local model, for the route planner.
 *
 * A spoken correction ("scratch that", "delete that") can remove a word or a whole stretch, so
 * the hub reads the transcript in full context with its local model rather than by rule. The
 * hub does the work; this verb is the one door to it.
 *
 *   action   status   {survey: {mode, ffmpeg, whisper, whisper_model, model}, hub_version}: can the hub read drives right now
 *            submit   kind transcribe: audio {url, bytes, mime}; url MUST be this site's own route-planner
 *                       audio-fetch address (a short-lived, token-signed link); the hub checks the same and
 *                       fetches nothing else
 *                     kind read: of (a finished transcribe job), streets [[i, street, zip], ...], vocab [label, ...]
 *                     -> {job, status}
 *            result   job, offset, forget -> {status, ...a page of sentences, or notes / removed / dropped, next}
 *   Gate     signed in, the staff tier or above, never the shared device. Not Charts' per-person access:
 *            a salesperson without Charts still gets their drives read, and the sealed block is
 *            `survey_only`; the hub answers survey ops and health to it and nothing of the store's
 *            (no figures, no names, no jobs). Jobs are filed under the asking person (`wp_<id>`);
 *            another person's job is "not found".
 *   Output   the hub's words, as they are: they are the driver's own sentences (the route planner
 *            scrubs them before it keeps anything). Nothing here is stored but the audit line. A
 *            failed call is an error, never an empty success.
 *
 * Not a write on this site (`side_effect` false): it starts a computation on the hub and reads it
 * back; the route planner decides what to keep.
 *
 * @package Zorderz\Charts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZCH_Survey_Verb {
	const VERB      = 'charts.survey';
	const CALLER    = 'app:route-planner';
	const MAX_BYTES = 419430400; // 400 MB, the reference hub's own default.

	public static function get_capability_descriptor(): array {
		return array(
			'verb'          => self::VERB,
			'provider'      => 'charts',
			'app'           => ZCH_APP_ID,
			'tier'          => 'sales',
			'needs_app'     => false,          // the planner's people need it without opening Charts.
			'callback'      => array( __CLASS__, 'from_capability' ),
			'kiosk'         => false,
			'kiosk_bounded' => false,
			'side_effect'   => false,
			'chat'          => false,
			'summary'       => 'For the route planner only: the Charts hub transcribes a recorded survey drive and reads it into notes (status, submit, result). Never the shared device; no sales figures.',
		);
	}

	/** The access block for the hub: survey ops only, nothing of the store's. */
	public static function block(): array {
		return array(
			'caller'      => self::CALLER,
			'survey_only' => true,
			'ops'         => array( 'health', 'survey_submit', 'survey_result' ),
			'scope'       => array(),
			'money'       => false,
			'by_person'   => false,
			'line_items'  => 'none',
			'kiosk'       => false,
			'accounts'    => false,
			'invoices'    => 'none',
			'me'          => null,
			'compare'     => false,
		);
	}

	/** The only path a recording may be fetched from (filter `zch_survey_fetch_path`, a full regex). */
	public static function fetch_path(): string {
		$ns  = defined( 'ZDZ_REST_NS' ) ? ZDZ_REST_NS : 'zorderz/v1';
		$def = '#^/wp-json/' . preg_quote( $ns, '#' ) . '/route-planner/audio-fetch/[0-9]{1,12}\z#';
		$f   = function_exists( 'apply_filters' ) ? apply_filters( 'zch_survey_fetch_path', $def ) : $def;
		return ( is_string( $f ) && '' !== $f && false !== @preg_match( $f, '' ) ) ? $f : $def; // phpcs:ignore WordPress.PHP.NoSilencedErrors -- an invalid filter pattern falls back.
	}

	/** True when $url is this site's own route-planner fetch address. */
	public static function own_url( $url ): bool {
		if ( ! is_string( $url ) || '' === $url || strlen( $url ) > 600 ) {
			return false;
		}
		$u = wp_parse_url( $url );
		$h = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $u ) || ! is_array( $h ) || empty( $u['host'] ) || empty( $h['host'] ) ) {
			return false;
		}
		if ( strtolower( $u['host'] ) !== strtolower( $h['host'] ) || isset( $u['user'] ) || isset( $u['pass'] ) ) {
			return false;
		}
		if ( ( $u['port'] ?? null ) !== ( $h['port'] ?? null ) ) {
			return false;
		}
		if ( 'https' !== ( $u['scheme'] ?? '' ) && ! ( 'http' === ( $u['scheme'] ?? '' ) && 'http' === ( $h['scheme'] ?? '' ) ) ) {
			return false;
		}
		if ( isset( $u['fragment'] ) || ! preg_match( '/\Ak=[a-f0-9]{64}\z/', (string) ( $u['query'] ?? '' ) ) ) {   // the token, nothing else.
			return false;
		}
		$path = rawurldecode( (string) ( $u['path'] ?? '' ) );
		return false === strpos( $path, '..' ) && 1 === preg_match( self::fetch_path(), $path );
	}

	private static function no( string $why, string $msg, bool $denied = false ): array {
		$out = array( 'success' => false, 'verb' => self::VERB, 'message' => $msg );
		if ( $denied ) {
			$out['denied'] = true;
			$out['reason'] = $why;
		} else {
			$out['error'] = $why;
		}
		return $out;
	}

	private static function strings( $list, int $max, int $len ): array {
		$out = array();
		foreach ( array_slice( is_array( $list ) ? $list : array(), 0, $max ) as $v ) {
			if ( is_scalar( $v ) && '' !== trim( (string) $v ) ) {
				$out[] = mb_substr( sanitize_text_field( (string) $v ), 0, $len );
			}
		}
		return $out;
	}

	/** The op and body for one action, or a refusal array. */
	public static function request( array $args ) {
		$action = (string) ( $args['action'] ?? '' );
		if ( ! in_array( $action, array( 'status', 'submit', 'result' ), true ) ) {
			return self::no( 'bad_request', __( 'Status, submit or result.', 'zorderz' ) );
		}
		$body = array( 'access' => self::block() );
		if ( 'status' === $action ) {
			return array( 'health', $body );
		}
		if ( 'submit' === $action ) {
			$kind = (string) ( $args['kind'] ?? '' );
			if ( 'transcribe' === $kind ) {
				$a     = is_array( $args['audio'] ?? null ) ? $args['audio'] : array();
				$bytes = (int) ( $a['bytes'] ?? 0 );
				if ( ! self::own_url( $a['url'] ?? '' ) ) {
					return self::no( 'bad_url', __( 'A recording is read only from this site\'s own address.', 'zorderz' ) );
				}
				if ( $bytes <= 0 || $bytes > self::MAX_BYTES ) {
					return self::no( 'too_big', __( 'That recording is too large to read.', 'zorderz' ) );
				}
				$body += array(
					'kind'  => 'transcribe',
					'audio' => array(
						'url'   => (string) $a['url'],
						'bytes' => $bytes,
						'mime'  => substr( preg_replace( '#[^a-z0-9/.+;=-]#i', '', (string) ( $a['mime'] ?? '' ) ), 0, 60 ),
					),
					'vocab' => self::strings( $args['vocab'] ?? array(), 40, 40 ),
				);
				return array( 'survey_submit', $body );
			}
			if ( 'read' === $kind ) {
				$streets = array();
				foreach ( array_slice( is_array( $args['streets'] ?? null ) ? $args['streets'] : array(), 0, 4000 ) as $r ) {
					if ( is_array( $r ) && isset( $r[0] ) && is_numeric( $r[0] ) ) {
						$streets[] = array( (int) $r[0], mb_substr( sanitize_text_field( (string) ( $r[1] ?? '' ) ), 0, 80 ), substr( preg_replace( '/\D/', '', (string) ( $r[2] ?? '' ) ), 0, 5 ) );
					}
				}
				$of = strtolower( (string) ( $args['of'] ?? '' ) );
				if ( ! preg_match( '/^[a-f0-9]{24}$/', $of ) ) {
					return self::no( 'bad_request', __( 'Which transcription?', 'zorderz' ) );
				}
				$body += array( 'kind' => 'read', 'of' => $of, 'streets' => $streets, 'vocab' => self::strings( $args['vocab'] ?? array(), 60, 40 ) );
				return array( 'survey_submit', $body );
			}
			return self::no( 'bad_request', __( 'Transcribe or read.', 'zorderz' ) );
		}
		$job = strtolower( (string) ( $args['job'] ?? '' ) );
		if ( ! preg_match( '/^[a-f0-9]{24}$/', $job ) ) {
			return self::no( 'bad_request', __( 'Which job?', 'zorderz' ) );
		}
		$body += array( 'job' => $job, 'offset' => max( 0, (int) ( $args['offset'] ?? 0 ) ), 'forget' => ! empty( $args['forget'] ) );
		return array( 'survey_result', $body );
	}

	public static function from_capability( array $args ): array {
		$uid = (int) ( $args['user_id'] ?? 0 );
		if ( ! empty( $args['is_kiosk'] ) || ZCH_Access::is_kiosk( $uid ) ) {
			return self::no( 'kiosk', __( 'Not available on the shared device.', 'zorderz' ), true );
		}
		if ( $uid <= 0 ) {
			return self::no( 'signed_out', __( 'Sign in first.', 'zorderz' ), true );
		}
		$req = self::request( $args );
		if ( isset( $req['success'] ) ) {
			return $req;
		}
		list( $op, $body ) = $req;
		$action            = (string) $args['action'];
		$client            = ZCH_Hub_Client::from_config();
		if ( ! $client ) {
			return self::no( 'hub_unavailable', ZCH_Config::local_message() );
		}
		if ( strlen( (string) wp_json_encode( $body ) ) > 60000 ) {
			return self::no( 'too_big', __( 'Too much to send to the Charts hub at once.', 'zorderz' ) );
		}
		$reply = $client->call( $op, $body, 'wp_' . $uid, wp_date( 'Y-m-d' ) );
		ZCH_Store::audit( $op, ! empty( $reply['ok'] ), $reply['error']['code'] ?? null, null, $client->last_ms, null, self::CALLER );
		if ( empty( $reply['ok'] ) ) {
			$code = (string) ( $reply['error']['code'] ?? 'hub_error' );
			if ( 'forbidden' === $code ) {
				return self::no( 'hub_too_old', __( 'The Charts hub does not read survey drives.', 'zorderz' ) );
			}
			$msg = (string) ( $reply['error']['message'] ?? __( 'Charts did not answer.', 'zorderz' ) );
			return self::no( $code, 'hub_unavailable' === $code ? __( 'The Charts hub did not answer.', 'zorderz' ) : $msg );
		}
		$d = is_array( $reply['data'] ?? null ) ? $reply['data'] : array();
		if ( 'status' === $action ) {
			if ( ! isset( $d['survey'] ) || ! is_array( $d['survey'] ) ) {
				return self::no( 'hub_too_old', __( 'The Charts hub does not read survey drives.', 'zorderz' ) );
			}
			return array( 'success' => true, 'verb' => self::VERB, 'survey' => $d['survey'], 'hub_version' => (string) ( $d['hub_version'] ?? '' ) );
		}
		if ( 'submit' === $action && ! preg_match( '/^[a-f0-9]{24}$/', (string) ( $d['job'] ?? '' ) ) ) {
			return self::no( 'hub_error', __( 'Charts answered without a job.', 'zorderz' ) );
		}
		if ( 'result' === $action && ! in_array( (string) ( $d['status'] ?? '' ), array( 'queued', 'running', 'done', 'failed' ), true ) ) {
			return self::no( 'hub_error', __( 'Charts answered without a status.', 'zorderz' ) );
		}
		return array( 'success' => true, 'verb' => self::VERB ) + $d;
	}
}

if ( function_exists( 'add_filter' ) ) {
	add_filter(
		'zdz_register_capabilities',
		function ( $caps ) {
			$caps = is_array( $caps ) ? $caps : array();
			$d    = ZCH_Survey_Verb::get_capability_descriptor();
			$caps[ $d['verb'] ] = $d;
			return $caps;
		},
		20
	);
}
